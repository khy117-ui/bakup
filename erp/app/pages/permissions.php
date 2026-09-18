<?php
// 웹에서 직접 열면 실행되지 않게 막습니다 (nginx 면 .htaccess 가 무시됩니다)
if (!defined('APP_DIR')) { http_response_code(403); exit('Forbidden'); }

require APP_DIR . '/layout.php';

$err = '';
$tab = query('tab', 'admin') === 'role' ? 'role' : 'admin';

$ROLES = [
    'SUPER_ADMIN'   => '최고관리자',
    'MANAGER'       => '관리자',
    'SALES'         => '영업',
    'LOGISTICS'     => '물류',
    'ACCOUNTING'    => '회계',
    'BOARD_MANAGER' => '게시판',
    'VIEWER'        => '조회전용',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $act = post('act');
    $pdo = db();
    $me  = (int)($_SESSION['admin_id'] ?? 0);
    try {
        if ($act === 'admin_save') {
            $aid  = (int)post('id');
            $name = post('name');
            $role = post('role_code');
            if ($name === '' || !isset($ROLES[$role])) {
                $err = '이름과 역할을 확인하세요.';
            } elseif ($aid === $me && $role !== 'SUPER_ADMIN') {
                // 스스로 권한을 낮춰 아무도 관리자가 없는 상태가 되는 걸 막습니다
                $err = '자기 자신의 역할은 낮출 수 없습니다. 다른 최고관리자에게 요청하세요.';
            } else {
                $pdo->prepare(
                    'UPDATE admins SET name = ?, role_code = ?, team = ?, phone = ?, email = ?
                      WHERE id = ?')
                    ->execute([$name, $role, post('team') ?: null,
                               post('phone') ?: null, post('email') ?: null, $aid]);
                log_action('시스템', 'UPDATE', 'admins', $aid, $name, null, '역할 ' . $role);
                flash('관리자 정보를 수정했습니다.');
                redirect('?p=permissions');
            }

        } elseif ($act === 'admin_toggle') {
            $aid = (int)post('id');
            if ($aid === $me) {
                $err = '자기 자신은 중지할 수 없습니다.';
            } else {
                $n = (int)$pdo->query(
                    "SELECT COUNT(*) FROM admins
                      WHERE role_code = 'SUPER_ADMIN' AND is_active = 1 AND deleted_at IS NULL")
                    ->fetchColumn();
                $t = $pdo->prepare('SELECT role_code, is_active, name FROM admins WHERE id = ?');
                $t->execute([$aid]);
                $tgt = $t->fetch();
                if ($tgt && $tgt['role_code'] === 'SUPER_ADMIN' && $tgt['is_active'] && $n <= 1) {
                    $err = '마지막 최고관리자는 중지할 수 없습니다. 아무도 못 들어오게 됩니다.';
                } else {
                    $pdo->prepare('UPDATE admins SET is_active = 1 - is_active WHERE id = ?')
                        ->execute([$aid]);
                    log_action('시스템', 'UPDATE', 'admins', $aid, $tgt['name'] ?? null,
                               null, '사용 여부 변경');
                    flash('변경했습니다.');
                    redirect('?p=permissions');
                }
            }

        } elseif ($act === 'unlock') {
            $aid = (int)post('id');
            $pdo->prepare('UPDATE admins SET fail_count = 0, locked_at = NULL WHERE id = ?')
                ->execute([$aid]);
            log_action('시스템', 'UPDATE', 'admins', $aid, null, null, '계정 잠금 해제');
            flash('잠금을 풀었습니다.');
            redirect('?p=permissions');

        } elseif ($act === 'role_save') {
            $role = post('role_code');
            if (!isset($ROLES[$role])) {
                $err = '역할이 올바르지 않습니다.';
            } elseif ($role === 'SUPER_ADMIN') {
                $err = '최고관리자의 권한은 바꿀 수 없습니다. 항상 전부 허용입니다.';
            } else {
                $picked = $_POST['perm'] ?? [];
                $picked = is_array($picked) ? array_map('intval', $picked) : [];
                $pdo->beginTransaction();
                $pdo->prepare('DELETE FROM role_permissions WHERE role_code = ?')->execute([$role]);
                if ($picked) {
                    $ins = $pdo->prepare(
                        'INSERT INTO role_permissions (role_code, permission_id) VALUES (?,?)');
                    foreach ($picked as $pid) { $ins->execute([$role, $pid]); }
                }
                log_action('시스템', 'UPDATE', 'role_permissions', null, $role,
                           null, count($picked) . '개 허용');
                $pdo->commit();
                flash($ROLES[$role] . ' 역할의 권한을 저장했습니다.');
                redirect('?p=permissions&tab=role&role=' . urlencode($role));
            }
        }
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        error_log('권한 저장 실패: ' . $e->getMessage());
        $err = '저장하지 못했습니다. 역할 권한 수정에는 DELETE 권한이 필요합니다.';
    }
}

$admins = db()->query(
    'SELECT * FROM admins WHERE deleted_at IS NULL
      ORDER BY is_active DESC, role_code, login_id')->fetchAll();

$editId = (int)query('edit', '0');
$edit = null;
foreach ($admins as $a) { if ((int)$a['id'] === $editId) { $edit = $a; } }

$selRole = query('role', 'MANAGER');
if (!isset($ROLES[$selRole])) { $selRole = 'MANAGER'; }

$perms = db()->query('SELECT * FROM permissions ORDER BY group_ko, code')->fetchAll();
$byGroup = [];
foreach ($perms as $p) { $byGroup[$p['group_ko']][] = $p; }

$st = db()->prepare('SELECT permission_id FROM role_permissions WHERE role_code = ?');
$st->execute([$selRole]);
$has = array_flip(array_column($st->fetchAll(), 'permission_id'));

$roleCount = [];
foreach (array_keys($ROLES) as $r) {
    $st = db()->prepare('SELECT COUNT(*) FROM role_permissions WHERE role_code = ?');
    $st->execute([$r]);
    $roleCount[$r] = (int)$st->fetchColumn();
}

$today = date('Y-m-d H:i:s');
layout_head('관리자 / 권한', 'permissions');
?>
<div class="head">
  <h1>관리자 / 권한</h1>
  <div class="crumb">시스템 &gt; 관리자 / 권한</div>
</div>

<?php if ($err !== ''): ?><div class="msg err"><?= h($err) ?></div><?php endif; ?>

<div class="card">
  <div class="ch">
    <a class="btn sm<?= $tab==='admin'?' pri':'' ?>" href="?p=permissions&amp;tab=admin">관리자</a>
    <a class="btn sm<?= $tab==='role'?' pri':'' ?>" href="?p=permissions&amp;tab=role">역할별 권한</a>
  </div>

<?php if ($tab === 'admin'): ?>
  <?php if ($edit): ?>
  <div class="cb">
    <form method="post" class="f" style="align-items:flex-end">
      <?= csrf_field() ?>
      <input type="hidden" name="act" value="admin_save">
      <input type="hidden" name="id" value="<?= (int)$edit['id'] ?>">
      <div class="fw w1"><label>아이디</label>
        <input type="text" class="tnum" value="<?= h($edit['login_id']) ?>" disabled></div>
      <div class="fw w1"><label>이름 *</label>
        <input type="text" name="name" required value="<?= h($edit['name']) ?>"></div>
      <div class="fw w1"><label>역할</label>
        <select name="role_code">
          <?php foreach ($ROLES as $k=>$v): ?>
            <option value="<?= h($k) ?>"<?= $edit['role_code']===$k?' selected':'' ?>><?= h($v) ?></option>
          <?php endforeach; ?>
        </select></div>
      <div class="fw w1"><label>팀</label>
        <input type="text" name="team" value="<?= h($edit['team']) ?>"></div>
      <div class="fw w2"><label>전화</label>
        <input type="text" name="phone" value="<?= h($edit['phone']) ?>"></div>
      <div class="fw w2"><label>이메일</label>
        <input type="text" name="email" value="<?= h($edit['email']) ?>"></div>
      <button class="btn pri">수정</button>
      <a class="btn" href="?p=permissions">취소</a>
    </form>
  </div>
  <?php endif; ?>

  <table>
    <thead><tr>
      <th style="width:120px">아이디</th><th style="width:100px">이름</th>
      <th style="width:110px">역할</th><th style="width:90px">팀</th>
      <th style="width:150px">마지막 로그인</th><th style="width:120px">접속 IP</th>
      <th class="c" style="width:90px">상태</th><th class="c" style="width:160px"></th>
    </tr></thead>
    <tbody>
    <?php foreach ($admins as $a):
      $locked = $a['locked_at'] !== null; ?>
      <tr<?= $a['is_active'] ? '' : ' style="opacity:.55"' ?>>
        <td class="tnum" style="font-weight:600"><?= h($a['login_id']) ?>
          <?php if ((int)$a['id'] === (int)($_SESSION['admin_id'] ?? 0)): ?>
            <span class="badge b-info">나</span>
          <?php endif; ?></td>
        <td><?= h($a['name']) ?></td>
        <td><?= h($ROLES[$a['role_code']] ?? $a['role_code']) ?></td>
        <td><?= h($a['team'] ?: '-') ?></td>
        <td class="tnum" style="font-size:11.5px"><?= h($a['last_login_at'] ?: '없음') ?></td>
        <td class="tnum" style="font-size:11.5px"><?= h($a['last_login_ip'] ?: '-') ?></td>
        <td class="c"><?php
          if ($locked) {
              echo '<span class="badge b-err">잠김</span>';
          } elseif ($a['is_active']) {
              echo '<span class="badge b-ok">사용</span>';
          } else {
              echo '<span class="badge b-warn">중지</span>';
          }
          if ((int)$a['fail_count'] > 0) {
              echo '<div style="font-size:11px;color:var(--err-fg)">실패 '
                 . (int)$a['fail_count'] . '회</div>';
          }
        ?></td>
        <td class="c">
          <a class="btn sm" href="?p=permissions&amp;edit=<?= (int)$a['id'] ?>">수정</a>
          <?php if ($locked): ?>
          <form method="post" style="display:inline">
            <?= csrf_field() ?><input type="hidden" name="act" value="unlock">
            <input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
            <button class="btn sm">잠금해제</button>
          </form>
          <?php endif; ?>
          <form method="post" style="display:inline">
            <?= csrf_field() ?><input type="hidden" name="act" value="admin_toggle">
            <input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
            <button class="btn sm"><?= $a['is_active'] ? '중지' : '사용' ?></button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$admins): ?>
      <tr><td colspan="8" class="empty">관리자가 없습니다.</td></tr>
    <?php endif; ?>
    </tbody>
  </table>
  <div class="pager"><span>
    계정은 지우지 않습니다 — 작업로그가 이 계정을 참조합니다. 안 쓰면 <b>중지</b>로 내리세요.
    <b>비밀번호는 이 화면에서 볼 수도, 바꿀 수도 없습니다.</b> 본인이 직접 재설정해야 합니다.
  </span></div>

<?php else: ?>
  <div class="cb" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
    <span style="font-size:12px;color:var(--ink2)">역할</span>
    <?php foreach ($ROLES as $k=>$v): ?>
      <a class="btn sm<?= $selRole===$k?' pri':'' ?>"
         href="?p=permissions&amp;tab=role&amp;role=<?= urlencode($k) ?>">
        <?= h($v) ?> <span class="tnum" style="opacity:.7"><?= (int)$roleCount[$k] ?></span></a>
    <?php endforeach; ?>
  </div>

  <?php if ($selRole === 'SUPER_ADMIN'): ?>
    <div class="empty">최고관리자는 <b>항상 모든 권한</b>을 갖습니다. 여기서 바꿀 수 없습니다 —
      잘못 건드리면 아무도 시스템을 관리할 수 없게 됩니다.</div>
  <?php else: ?>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="act" value="role_save">
    <input type="hidden" name="role_code" value="<?= h($selRole) ?>">
    <?php foreach ($byGroup as $grp => $items): ?>
      <div class="cb" style="border-top:1px solid var(--line2)">
        <div class="sec" style="margin-bottom:8px"><?= h($grp) ?></div>
        <div style="display:flex;flex-wrap:wrap;gap:8px 18px">
          <?php foreach ($items as $p): ?>
            <label style="display:flex;gap:6px;align-items:center;font-size:12.5px;
                          min-width:230px;cursor:pointer">
              <input type="checkbox" name="perm[]" value="<?= (int)$p['id'] ?>"
                     <?= isset($has[(int)$p['id']]) ? 'checked' : '' ?>>
              <?= h($p['name_ko']) ?>
              <span class="tnum" style="color:var(--ink3);font-size:11px"><?= h($p['code']) ?></span>
            </label>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endforeach; ?>
    <div class="cb" style="border-top:1px solid var(--line);display:flex;gap:8px;align-items:center">
      <button class="btn pri"><?= h($ROLES[$selRole]) ?> 권한 저장</button>
      <span style="font-size:11.5px;color:var(--ink3)">
        체크를 모두 풀면 그 역할은 아무것도 못 합니다. 저장 전에 확인하세요.</span>
    </div>
  </form>
  <?php endif; ?>
<?php endif; ?>
</div>

<div class="card">
  <div class="ch">지금 로그인한 세션</div>
  <div class="cb f" style="gap:24px">
    <div><div style="font-size:11px;color:var(--ink2)">계정</div>
      <div style="font-weight:600"><?= h($ADMIN['name'] ?? '') ?>
        (<?= h($ADMIN['login_id'] ?? '') ?>)</div></div>
    <div><div style="font-size:11px;color:var(--ink2)">역할</div>
      <div><?= h($ROLES[$ADMIN['role_code'] ?? ''] ?? ($ADMIN['role_code'] ?? '')) ?></div></div>
    <div><div style="font-size:11px;color:var(--ink2)">접속 IP</div>
      <div class="tnum"><?= h($_SERVER['REMOTE_ADDR'] ?? '-') ?></div></div>
    <div><div style="font-size:11px;color:var(--ink2)">서버 시각</div>
      <div class="tnum"><?= h($today) ?></div></div>
  </div>
</div>
<?php layout_foot();
