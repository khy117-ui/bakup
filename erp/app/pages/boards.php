<?php
// 웹에서 직접 열면 실행되지 않게 막습니다 (nginx 면 .htaccess 가 무시됩니다)
if (!defined('APP_DIR')) { http_response_code(403); exit('Forbidden'); }

require APP_DIR . '/layout.php';

/**
 * 홈페이지 게시판 — 공지사항 · 견적문의 · 상담 · 자료실.
 *
 * 홈페이지 쪽 입력 화면은 이 시스템 밖(회사 홈페이지)에 있고, 여기는 **관리자쪽**입니다.
 * 문의가 들어오면 여기서 읽고 답변합니다. 답변은 같은 글의 자식 글로 붙습니다.
 */

$err = '';

// ---------------------------------------------------------------- 답변 등록
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('act') === 'answer') {
    csrf_check();
    $pid   = (int)post('post_id');
    $title = post('title');
    $body  = post('content');

    $st = db()->prepare('SELECT p.*, b.board_type FROM posts p
                           JOIN boards b ON b.id = p.board_id
                          WHERE p.id = ? AND p.deleted_at IS NULL');
    $st->execute([$pid]);
    $parent = $st->fetch();

    if (!$parent) {
        $err = '원글을 찾을 수 없습니다.';
    } elseif (trim($body) === '') {
        $err = '답변 내용을 입력하세요.';
    } else {
        $pdo = db();
        try {
            $pdo->beginTransaction();
            $pdo->prepare(
                'INSERT INTO posts (board_id, parent_id, title, content, writer_name,
                                    writer_ip, is_secret)
                 VALUES (?,?,?,?,?,?,?)')
                ->execute([
                    (int)$parent['board_id'], $pid,
                    $title !== '' ? $title : 'RE: ' . $parent['title'],
                    $body, $_SESSION['admin_name'] ?? '관리자',
                    $_SERVER['REMOTE_ADDR'] ?? '-', (int)$parent['is_secret'],
                ]);
            $pdo->prepare('UPDATE posts SET is_answered = 1, answered_by = ?, answered_at = NOW()
                            WHERE id = ?')
                ->execute([$_SESSION['admin_id'] ?? null, $pid]);
            log_action('게시판', 'CREATE', 'posts', $pid, (string)$parent['title'],
                       null, '답변 등록');
            $pdo->commit();
            flash('답변을 등록했습니다.');
            redirect('?p=boards&b=' . (int)$parent['board_id'] . '&post=' . $pid);
        } catch (Throwable $e) {
            $pdo->rollBack();
            error_log('게시판 답변 실패: ' . $e->getMessage());
            $err = '답변을 등록하지 못했습니다.';
        }
    }
}

// ---------------------------------------------------------------- 글 숨기기 (soft delete)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('act') === 'hide') {
    csrf_check();
    $pid = (int)post('post_id');
    $why = post('reason');
    if (mb_strlen($why) < 2) {
        $err = '숨기는 사유를 적어 주세요.';
    } else {
        $st = db()->prepare('SELECT board_id, title FROM posts WHERE id = ?');
        $st->execute([$pid]);
        if ($row = $st->fetch()) {
            db()->prepare('UPDATE posts SET deleted_at = NOW() WHERE id = ?')->execute([$pid]);
            log_action('게시판', 'DELETE', 'posts', $pid, (string)$row['title'],
                       null, 'deleted_at 설정', $why);
            flash('글을 숨겼습니다. 내용은 남아 있어 되돌릴 수 있습니다.');
            redirect('?p=boards&b=' . (int)$row['board_id']);
        }
        $err = '글을 찾을 수 없습니다.';
    }
}

// ---------------------------------------------------------------- 게시판 사용여부
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('act') === 'toggle_board') {
    csrf_check();
    $bid = (int)post('board_id');
    $st = db()->prepare('SELECT name, is_active FROM boards WHERE id = ?');
    $st->execute([$bid]);
    if ($b = $st->fetch()) {
        $to = (int)$b['is_active'] === 1 ? 0 : 1;
        db()->prepare('UPDATE boards SET is_active = ? WHERE id = ?')->execute([$to, $bid]);
        log_action('게시판', 'UPDATE', 'boards', $bid, (string)$b['name'],
                   $b['is_active'] ? '사용' : '중지', $to ? '사용' : '중지');
        flash($b['name'] . ' 을 ' . ($to ? '사용' : '중지') . ' 으로 바꿨습니다.');
        redirect('?p=boards&b=' . $bid);
    }
    $err = '게시판을 찾을 수 없습니다.';
}

// ---------------------------------------------------------------- 조회
$boards = db()->query(
    'SELECT b.*,
            (SELECT COUNT(*) FROM posts p
              WHERE p.board_id = b.id AND p.deleted_at IS NULL AND p.parent_id IS NULL) AS cnt,
            (SELECT COUNT(*) FROM posts p
              WHERE p.board_id = b.id AND p.deleted_at IS NULL AND p.parent_id IS NULL
                AND p.is_answered = 0) AS waiting
       FROM boards b ORDER BY b.sort_order, b.id')->fetchAll();

$bid = (int)query('b', '0');
if ($bid === 0 && $boards) { $bid = (int)$boards[0]['id']; }
$board = null;
foreach ($boards as $b) { if ((int)$b['id'] === $bid) { $board = $b; } }

$kw   = query('kw');
$only = query('only');                       // waiting = 미답변만
$page = max(1, (int)query('page', '1'));
$per  = 20;
$off  = ($page - 1) * $per;

$rows = []; $total = 0; $cur = null; $replies = [];
if ($board) {
    $where = ['p.board_id = ?', 'p.deleted_at IS NULL', 'p.parent_id IS NULL'];
    $params = [$bid];
    if ($kw !== '') {
        $where[] = '(p.title LIKE ? OR p.content LIKE ? OR p.writer_name LIKE ?)';
        $like = '%' . $kw . '%';
        array_push($params, $like, $like, $like);
    }
    if ($only === 'waiting') { $where[] = 'p.is_answered = 0'; }
    $w = implode(' AND ', $where);

    $st = db()->prepare("SELECT COUNT(*) FROM posts p WHERE $w");
    $st->execute($params);
    $total = (int)$st->fetchColumn();

    $st = db()->prepare("SELECT p.* FROM posts p WHERE $w
                          ORDER BY p.created_at DESC, p.id DESC LIMIT $per OFFSET $off");
    $st->execute($params);
    $rows = $st->fetchAll();

    $pid = (int)query('post', '0');
    if ($pid > 0) {
        $st = db()->prepare('SELECT * FROM posts WHERE id = ? AND board_id = ?
                              AND deleted_at IS NULL');
        $st->execute([$pid, $bid]);
        $cur = $st->fetch();
        if ($cur) {
            $st = db()->prepare('SELECT * FROM posts WHERE parent_id = ? AND deleted_at IS NULL
                                  ORDER BY id');
            $st->execute([$pid]);
            $replies = $st->fetchAll();

            $st = db()->prepare('SELECT * FROM post_files WHERE post_id = ? ORDER BY id');
            $st->execute([$pid]);
            $files = $st->fetchAll();
        }
    }
}

layout_head('게시판 관리', 'boards');
?>
<div class="head">
  <h1>게시판 관리</h1>
  <div class="crumb">시스템 &gt; 게시판 관리</div>
</div>

<?php if ($err !== ''): ?><div class="msg err"><?= h($err) ?></div><?php endif; ?>

<div class="card">
  <div class="ch">게시판</div>
  <table>
    <thead><tr>
      <th style="width:120px">코드</th><th>이름</th>
      <th class="c" style="width:90px">형태</th>
      <th class="c" style="width:80px">글</th><th class="c" style="width:90px">미답변</th>
      <th class="c" style="width:70px">사용</th><th class="c" style="width:140px"></th>
    </tr></thead>
    <tbody>
    <?php foreach ($boards as $b): ?>
      <tr<?= (int)$b['id']===$bid?' style="background:#F2F7FB"':'' ?>>
        <td class="tnum"><?= h($b['code']) ?></td>
        <td style="font-weight:600"><?= h($b['name']) ?>
          <?php if ($b['legacy_source']): ?>
            <span style="font-weight:400;font-size:11px;color:var(--ink3)">
              ← <?= h($b['legacy_source']) ?></span>
          <?php endif; ?></td>
        <td class="c"><?= h($b['board_type']) ?></td>
        <td class="c tnum"><?= money($b['cnt']) ?></td>
        <td class="c tnum" style="<?= (int)$b['waiting']>0?'color:var(--err-fg);font-weight:700':'' ?>">
          <?= money($b['waiting']) ?></td>
        <td class="c"><?= (int)$b['is_active']
             ? '<span class="badge b-ok">사용</span>'
             : '<span class="badge b-err">중지</span>' ?></td>
        <td class="c">
          <a class="btn sm" href="?p=boards&amp;b=<?= (int)$b['id'] ?>">글보기</a>
          <form method="post" style="display:inline"
                onsubmit="return confirm('사용여부를 바꿉니다.');">
            <?= csrf_field() ?>
            <input type="hidden" name="act" value="toggle_board">
            <input type="hidden" name="board_id" value="<?= (int)$b['id'] ?>">
            <button class="btn sm"><?= (int)$b['is_active'] ? '중지' : '사용' ?></button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$boards): ?>
      <tr><td colspan="7" class="empty">게시판이 없습니다. <code>12_seed.sql</code> 을 실행하세요.</td></tr>
    <?php endif; ?>
    </tbody>
  </table>
</div>

<?php if ($cur): ?>
<div class="card">
  <div class="ch"><?= h($cur['title']) ?>
    <?php if ((int)$cur['is_secret']): ?><span class="badge b-warn">비밀글</span><?php endif; ?>
    <?php if ((int)$cur['is_answered']): ?><span class="badge b-ok">답변완료</span>
    <?php else: ?><span class="badge b-err">미답변</span><?php endif; ?>
    <a class="btn sm" style="margin-left:auto" href="?p=boards&amp;b=<?= $bid ?>">목록</a>
  </div>
  <div class="cb f" style="gap:24px">
    <div><div style="font-size:11px;color:var(--ink2)">작성자</div>
      <div style="font-weight:600"><?= h($cur['writer_name']) ?></div></div>
    <div><div style="font-size:11px;color:var(--ink2)">연락처</div>
      <div class="tnum"><?= h($cur['writer_phone'] ?: '-') ?></div></div>
    <div><div style="font-size:11px;color:var(--ink2)">이메일</div>
      <div><?= h($cur['writer_email'] ?: '-') ?></div></div>
    <div><div style="font-size:11px;color:var(--ink2)">작성일</div>
      <div class="tnum"><?= h($cur['created_at']) ?></div></div>
    <div><div style="font-size:11px;color:var(--ink2)">조회</div>
      <div class="tnum"><?= money($cur['view_count']) ?></div></div>
    <div><div style="font-size:11px;color:var(--ink2)">작성 IP</div>
      <div class="tnum" style="font-size:11.5px"><?= h($cur['writer_ip'] ?: '-') ?></div></div>
  </div>
  <div class="cb" style="border-top:1px solid var(--line);white-space:pre-wrap;line-height:1.8;font-size:12.5px">
<?= h((string)$cur['content']) ?>
  </div>
  <?php if (!empty($files)): ?>
  <div class="cb" style="border-top:1px solid var(--line)">
    <div style="font-size:11px;color:var(--ink2);margin-bottom:6px">첨부파일</div>
    <?php foreach ($files as $f): ?>
      <div style="font-size:12px">
        <?= h($f['original_name']) ?>
        <span class="tnum" style="color:var(--ink3)">
          <?= money((int)(((int)$f['size_bytes']) / 1024)) ?> KB</span>
        <span style="color:var(--ink3)">· 다운로드 <?= money($f['download_count']) ?>회</span>
      </div>
    <?php endforeach; ?>
    <div style="font-size:11px;color:var(--ink3);margin-top:6px">
      홈페이지에서 올라온 파일입니다. 내려받기는 문서보관함 경로를 통해 붙입니다.
    </div>
  </div>
  <?php endif; ?>

  <?php foreach ($replies as $rp): ?>
    <div class="cb" style="border-top:1px solid var(--line);background:#F7FAFB">
      <div style="font-size:11px;color:var(--ink2);margin-bottom:6px">
        답변 · <b><?= h($rp['writer_name']) ?></b>
        <span class="tnum"><?= h($rp['created_at']) ?></span>
      </div>
      <div style="white-space:pre-wrap;line-height:1.8;font-size:12.5px"><?= h((string)$rp['content']) ?></div>
    </div>
  <?php endforeach; ?>

  <div class="cb" style="border-top:1px solid var(--line)">
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="act" value="answer">
      <input type="hidden" name="post_id" value="<?= (int)$cur['id'] ?>">
      <div class="f" style="align-items:flex-end">
        <div class="fw w3"><label for="rt">답변 제목</label>
          <input type="text" id="rt" name="title"
                 placeholder="비우면 RE: <?= h($cur['title']) ?>"></div>
      </div>
      <div class="fw" style="margin-top:8px"><label for="rc">답변 내용 *</label>
        <textarea id="rc" name="content" rows="5" required
                  placeholder="고객에게 보낼 답변을 적습니다."></textarea></div>
      <div style="margin-top:8px;display:flex;gap:8px;align-items:center">
        <button class="btn pri">답변 등록</button>
        <span style="font-size:11.5px;color:var(--ink3)">
          답변 등록만으로 메일이 나가지는 않습니다. 메일 발송은 따로 붙입니다.
        </span>
      </div>
    </form>
  </div>

  <div class="cb" style="border-top:1px solid var(--line)">
    <form method="post" class="f" style="align-items:flex-end;gap:8px"
          onsubmit="return confirm('글을 숨깁니다. 내용은 지워지지 않습니다.');">
      <?= csrf_field() ?>
      <input type="hidden" name="act" value="hide">
      <input type="hidden" name="post_id" value="<?= (int)$cur['id'] ?>">
      <div class="fw w3"><label>숨기는 사유 *</label>
        <input type="text" name="reason" required placeholder="예) 광고글"></div>
      <button class="btn" style="border-color:#C9A257;color:#6B4700">글 숨기기</button>
    </form>
  </div>
</div>
<?php endif; ?>

<?php if ($board): ?>
<div class="card">
  <div class="ch"><?= h($board['name']) ?>
    <form class="f" method="get" style="margin-left:auto;align-items:flex-end;gap:8px">
      <input type="hidden" name="p" value="boards">
      <input type="hidden" name="b" value="<?= $bid ?>">
      <input type="text" name="kw" value="<?= h($kw) ?>" placeholder="제목 · 내용 · 작성자"
             style="width:220px">
      <select name="only" style="width:120px">
        <option value="">전체</option>
        <option value="waiting"<?= $only==='waiting'?' selected':'' ?>>미답변만</option>
      </select>
      <button class="btn sm">검색</button>
    </form>
  </div>
  <?php if (!$rows): ?>
    <div class="empty">글이 없습니다.</div>
  <?php else: ?>
  <table>
    <thead><tr>
      <th class="c" style="width:60px">번호</th><th>제목</th>
      <th style="width:100px">작성자</th><th style="width:150px">작성일</th>
      <th class="c" style="width:70px">조회</th><th class="c" style="width:90px">답변</th>
      <th class="c" style="width:55px"></th>
    </tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td class="c tnum"><?= (int)$r['id'] ?></td>
        <td>
          <a href="?p=boards&amp;b=<?= $bid ?>&amp;post=<?= (int)$r['id'] ?>"
             style="font-weight:600"><?= h(mb_strimwidth((string)$r['title'], 0, 70, '…')) ?></a>
          <?php if ((int)$r['is_secret']): ?>
            <span class="badge b-warn">비밀</span>
          <?php endif; ?>
        </td>
        <td><?= h($r['writer_name']) ?></td>
        <td class="tnum" style="font-size:11.5px"><?= h($r['created_at']) ?></td>
        <td class="c tnum"><?= money($r['view_count']) ?></td>
        <td class="c"><?= (int)$r['is_answered']
             ? '<span class="badge b-ok">완료</span>'
             : '<span class="badge b-err">대기</span>' ?></td>
        <td class="c"><a class="btn sm"
              href="?p=boards&amp;b=<?= $bid ?>&amp;post=<?= (int)$r['id'] ?>">열기</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <div class="pager">
    <span>전체 <b class="tnum"><?= money($total) ?></b> 건</span>
    <div class="right">
      <?php $qs = 'p=boards&b=' . $bid . '&kw=' . urlencode($kw) . '&only=' . urlencode($only); ?>
      <?php if ($page>1): ?><a class="btn sm" href="?<?= h($qs) ?>&amp;page=<?= $page-1 ?>">이전</a><?php endif; ?>
      <?php if ($off+$per<$total): ?><a class="btn sm" href="?<?= h($qs) ?>&amp;page=<?= $page+1 ?>">다음</a><?php endif; ?>
    </div>
  </div>
  <?php endif; ?>
</div>
<?php endif; ?>

<div class="card">
  <div class="ch">홈페이지와 어떻게 이어지나</div>
  <div class="cb" style="font-size:12px;color:var(--ink2);line-height:1.9">
    · 손님이 글을 쓰는 화면은 <b>회사 홈페이지</b>에 있고, 이 화면은 <b>받아서 처리하는 쪽</b>입니다.
      같은 <code>posts</code> 테이블을 씁니다.<br>
    · 기존 <code>IS_QNA</code> / <code>IS_QNA_ANSWER</code> 처럼 원글·답변이 따로 있던 구조는
      <code>parent_id</code> 하나로 합쳤습니다. 답변은 원글의 자식 글입니다.<br>
    · <b>미답변 건수</b>가 게시판 목록에 빨갛게 나옵니다. 견적문의는 이 숫자가 곧 영업 대기 건수입니다.<br>
    · 글은 <b>지워지지 않습니다.</b> 숨기기는 <code>deleted_at</code> 만 찍고 사유를 작업로그에 남깁니다.
  </div>
</div>
<?php layout_foot();
