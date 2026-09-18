<?php
// 웹에서 직접 열면 실행되지 않게 막습니다 (nginx 면 .htaccess 가 무시됩니다)
if (!defined('APP_DIR')) { http_response_code(403); exit('Forbidden'); }

require_once APP_DIR . '/layout.php';

/**
 * 삭제 요청 · 승인.
 *
 *   · 삭제 · 취소를 바로 실행할 권한(sys.delete.direct)이 없는 사람이 삭제를 누르면 여기 요청으로 남습니다.
 *   · 승인 권한(sys.delete.approve, 최고관리자는 항상)이 있으면 모든 요청을 보고 승인 · 반려합니다.
 *     없으면 자기 요청만 보고, 대기 중인 것은 철회할 수 있습니다.
 *   · 승인 = 요청 당시 그 화면에 보낸 내용을 승인자 권한으로 그대로 다시 실행합니다.
 *     그래서 화면마다 있던 확인(이미 수금된 청구서는 취소 불가 등)이 그대로 적용됩니다.
 */

$canApprove = can('sys.delete.approve');
$me  = (int)($_SESSION['admin_id'] ?? 0);
$err = '';

function dr_get(int $id): ?array
{
    $st = db()->prepare('SELECT * FROM delete_requests WHERE id = ?');
    $st->execute([$id]);
    $r = $st->fetch();
    return $r ?: null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $act = post('act');
    $rid = (int)post('id');
    $req = dr_get($rid);

    if (!$req) {
        $err = '요청을 찾을 수 없습니다.';
    } elseif ($req['status'] !== 'PENDING') {
        $err = '이미 처리된 요청입니다.';

    } elseif ($act === 'withdraw') {
        if ((int)$req['requested_by'] !== $me) {
            $err = '자기 요청만 철회할 수 있습니다.';
        } else {
            db()->prepare("UPDATE delete_requests SET status = 'WITHDRAWN', decided_by = ?, decided_name = ?,
                                  decided_at = NOW() WHERE id = ? AND status = 'PENDING'")
                ->execute([$me, $_SESSION['admin_name'] ?? null, $rid]);
            log_action('삭제요청', 'WITHDRAW', 'delete_requests', $rid, $req['target_label']);
            flash('요청을 철회했습니다.');
            redirect('?p=delete_requests');
        }

    } elseif ($act === 'reject') {
        $note = trim(post('note'));
        if (!$canApprove) {
            $err = '승인 권한이 없습니다.';
        } elseif (mb_strlen($note) < 2) {
            $err = '반려 사유를 적어 주세요. 요청한 사람이 봅니다.';
        } else {
            db()->prepare("UPDATE delete_requests SET status = 'REJECTED', decided_by = ?, decided_name = ?,
                                  decided_at = NOW(), decision_note = ? WHERE id = ? AND status = 'PENDING'")
                ->execute([$me, $_SESSION['admin_name'] ?? null, mb_substr($note, 0, 500), $rid]);
            log_action('삭제요청', 'REJECT', 'delete_requests', $rid, $req['target_label'], null, null, $note);
            flash('반려했습니다.');
            redirect('?p=delete_requests');
        }

    } elseif ($act === 'approve') {
        $route = (string)$req['route'];
        if (!$canApprove) {
            $err = '승인 권한이 없습니다.';
        } elseif (!isset($routes[$route]) || delete_action($route, (string)$req['act']) === null) {
            $err = '처리할 수 없는 요청입니다 (화면이 바뀌었습니다).';
        } elseif (!route_can_edit($route) || !can('sys.delete.direct')) {
            $err = '이 요청을 처리하려면 그 화면의 입력 권한과 "삭제·취소 바로 실행" 권한이 있어야 합니다.';
        } else {
            // 두 사람이 동시에 눌러도 한 번만 실행되게 — 먼저 상태를 바꾼 쪽만 진행합니다
            $upd = db()->prepare("UPDATE delete_requests SET status = 'DONE', decided_by = ?, decided_name = ?,
                                         decided_at = NOW() WHERE id = ? AND status = 'PENDING'");
            $upd->execute([$me, $_SESSION['admin_name'] ?? null, $rid]);
            if ($upd->rowCount() !== 1) {
                $err = '다른 사람이 먼저 처리했습니다.';
            } else {
                log_action('삭제요청', 'APPROVE', 'delete_requests', $rid, $req['target_label']);

                // 요청 당시 내용 그대로 그 화면을 다시 실행합니다.
                // 화면이 처리를 마치면 목록으로 돌려보내고(Location), 오류로 화면을 그리면 '처리 실패' 로 남깁니다.
                register_shutdown_function(function () use ($rid) {
                    $moved = false;
                    foreach (headers_list() as $hd) {
                        if (stripos($hd, 'Location:') === 0) { $moved = true; }
                    }
                    if ($moved && http_response_code() < 400) {
                        if (!headers_sent()) { header('Location: ?p=delete_requests', true, 302); }
                        return;
                    }
                    try {
                        db()->prepare("UPDATE delete_requests SET status = 'FAILED',
                                              decision_note = '승인했지만 그 화면에서 처리되지 않았습니다 (화면의 안내를 보세요)'
                                        WHERE id = ?")->execute([$rid]);
                    } catch (Throwable $e) {
                        error_log('삭제요청 상태 기록 실패: ' . $e->getMessage());
                    }
                });

                $payload = json_decode((string)$req['payload_json'], true) ?: [];
                $query   = json_decode((string)$req['query_json'], true) ?: [];
                $payload['_csrf'] = csrf_token();
                $_GET = $query;
                $_POST = $payload;
                $_REQUEST = $query + $payload;
                $_SERVER['REQUEST_METHOD'] = 'POST';
                define('DELETE_REPLAY', $rid);
                require APP_DIR . '/pages/' . $routes[$route];
                exit;
            }
        }
    }
}

// ---------------------------------------------------------------- 목록
$tab = query('tab', 'pending') === 'done' ? 'done' : 'pending';
$where = $tab === 'pending' ? "status = 'PENDING'" : "status <> 'PENDING'";
$params = [];
if (!$canApprove) {
    $where .= ' AND requested_by = ?';
    $params[] = $me;
}
$st = db()->prepare("SELECT * FROM delete_requests WHERE $where
                      ORDER BY " . ($tab === 'pending' ? 'requested_at' : 'decided_at DESC, id DESC') . " LIMIT 200");
$st->execute($params);
$rows = $st->fetchAll();

$ST = [
    'PENDING'   => ['대기', 'b-warn'],
    'DONE'      => ['처리됨', 'b-ok'],
    'FAILED'    => ['처리 실패', 'b-err'],
    'REJECTED'  => ['반려', 'b-err'],
    'WITHDRAWN' => ['철회', 'b-info'],
];

layout_head('삭제 요청 · 승인', 'delete_requests');
?>
<div class="head">
  <h1>삭제 요청 · 승인</h1>
  <div class="crumb"><?= $canApprove ? '모든 요청' : '내가 올린 요청' ?></div>
</div>

<?php if ($err !== ''): ?><div class="msg err"><?= h($err) ?></div><?php endif; ?>

<div class="card">
  <div class="ch">
    <a class="btn sm<?= $tab==='pending'?' pri':'' ?>" href="?p=delete_requests">대기</a>
    <a class="btn sm<?= $tab==='done'?' pri':'' ?>" href="?p=delete_requests&amp;tab=done">처리 내역</a>
  </div>
  <table>
    <thead><tr>
      <th style="width:130px">요청 시각</th><th style="width:90px">요청자</th>
      <th>대상</th><th>사유</th><th class="c" style="width:90px">상태</th>
      <th style="width:<?= $tab==='pending' ? '300' : '220' ?>px"><?= $tab==='pending' ? '' : '처리' ?></th>
    </tr></thead>
    <tbody>
    <?php foreach ($rows as $r):
      $s = $ST[$r['status']] ?? [$r['status'], 'b-info']; ?>
      <tr>
        <td class="tnum" style="font-size:12px"><?= h($r['requested_at']) ?></td>
        <td><?= h($r['requested_name'] ?: '#' . $r['requested_by']) ?></td>
        <td style="white-space:normal"><?= h($r['target_label']) ?></td>
        <td style="white-space:normal"><?= h($r['reason'] ?: '(사유 없음)') ?></td>
        <td class="c"><span class="badge <?= $s[1] ?>"><?= h($s[0]) ?></span></td>
        <td style="white-space:normal">
        <?php if ($r['status'] === 'PENDING'): ?>
          <?php if ($canApprove): ?>
            <form method="post" style="display:inline"
                  onsubmit="return confirm('승인하면 바로 삭제 · 취소됩니다. 진행할까요?')">
              <?= csrf_field() ?><input type="hidden" name="act" value="approve">
              <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
              <button class="btn sm pri">승인</button>
            </form>
            <form method="post" style="display:inline-flex;gap:4px;align-items:center">
              <?= csrf_field() ?><input type="hidden" name="act" value="reject">
              <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
              <input type="text" name="note" placeholder="반려 사유" style="width:120px;height:28px">
              <button class="btn sm">반려</button>
            </form>
          <?php endif; ?>
          <?php if ((int)$r['requested_by'] === $me): ?>
            <form method="post" style="display:inline">
              <?= csrf_field() ?><input type="hidden" name="act" value="withdraw">
              <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
              <button class="btn sm">철회</button>
            </form>
          <?php endif; ?>
        <?php else: ?>
          <span style="font-size:12px"><?= h($r['decided_name'] ?: '-') ?>
            <span class="tnum" style="color:var(--ink3)"><?= h($r['decided_at'] ?: '') ?></span></span>
          <?php if ($r['decision_note']): ?>
            <div style="font-size:12px;color:var(--ink2)"><?= h($r['decision_note']) ?></div>
          <?php endif; ?>
        <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?>
      <tr><td colspan="6" class="empty"><?= $tab==='pending' ? '대기 중인 요청이 없습니다.' : '처리한 요청이 없습니다.' ?></td></tr>
    <?php endif; ?>
    </tbody>
  </table>
  <div class="pager"><span>
    삭제 · 취소를 바로 할 권한이 없는 계정이 누르면 여기 요청으로 올라옵니다.
    승인하면 요청 당시 내용 그대로 처리되고, 그 화면의 확인 규칙(예: 수금된 청구서는 취소 불가)도 그대로 적용됩니다.
  </span></div>
</div>
<?php layout_foot();
