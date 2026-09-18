<?php
/**
 * 게시판 관리 (공지사항 · Q&A) — 글 목록 · 작성 · 수정 · 삭제 · Q&A 답변
 * /admin/ 폴더의 IP 제한 · 관리자 인증(.htaccess / web.config / nginx) 아래에서만 열립니다.
 *   목록   posts.php?board=notice
 *   작성   posts.php?board=notice&act=new
 *   수정   posts.php?board=qna&act=edit&id=12
 *   저장/삭제는 POST + CSRF 토큰
 */
declare(strict_types=1);
require __DIR__ . '/../includes/db.php';

header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store');
session_start();
if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
}
$csrf = $_SESSION['csrf'];

$boards = ['notice' => '공지사항', 'qna' => 'Q&A'];
$board = $_REQUEST['board'] ?? 'notice';
if (!isset($boards[$board])) {
    $board = 'notice';
}
$act = $_REQUEST['act'] ?? 'list';
$id = (int) ($_REQUEST['id'] ?? 0);
$msg = $_GET['msg'] ?? '';
$err = '';

function h(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}
function go(string $board, string $msg = ''): void
{
    header('Location: posts.php?board=' . $board . ($msg ? '&msg=' . rawurlencode($msg) : ''));
    exit;
}

try {
    $pdo = board_db();
} catch (Throwable $e) {
    http_response_code(503);
    echo '<!doctype html><meta charset="utf-8"><p style="font-family:sans-serif;padding:40px">DB 연결에 실패했습니다: ' . h($e->getMessage()) . '</p>';
    exit;
}

/* ---------- 저장 · 삭제 ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($csrf, (string) ($_POST['csrf'] ?? ''))) {
        $err = '보안 토큰이 맞지 않습니다. 페이지를 새로고침한 뒤 다시 시도해 주세요.';
    } elseif ($act === 'delete' && $id) {
        $pdo->prepare('DELETE FROM board_post WHERE id = :id AND board = :board')->execute([':id' => $id, ':board' => $board]);
        go($board, '삭제했습니다.');
    } elseif ($act === 'save') {
        $title = trim((string) ($_POST['title'] ?? ''));
        $content = trim((string) ($_POST['content'] ?? ''));
        $writer = trim((string) ($_POST['writer'] ?? '')) ?: '관리자';
        $pinned = !empty($_POST['pinned']) ? 1 : 0;
        $secret = !empty($_POST['is_secret']) ? 1 : 0;
        $answer = trim((string) ($_POST['answer'] ?? ''));
        $date = trim((string) ($_POST['created_at'] ?? ''));
        if ($title === '') {
            $err = '제목을 입력해 주세요.';
        } elseif ($content === '') {
            $err = '내용을 입력해 주세요.';
        } else {
            $now = board_now();
            $created = preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? $date . ' 09:00:00' : $now;
            if ($id) {
                $cur = $pdo->prepare('SELECT answer FROM board_post WHERE id = :id AND board = :board');
                $cur->execute([':id' => $id, ':board' => $board]);
                $prev = $cur->fetch();
                $answeredAt = ($answer !== '' && ($prev === false || $prev['answer'] !== $answer)) ? $now : null;
                $sql = 'UPDATE board_post SET title = :title, content = :content, writer = :writer, pinned = :pinned, is_secret = :secret,
                        answer = :answer' . ($answer === '' ? ', answered_at = NULL' : ($answeredAt ? ', answered_at = :answered_at' : '')) . ',
                        created_at = :created_at, updated_at = :updated_at WHERE id = :id AND board = :board';
                $p = [':title' => $title, ':content' => $content, ':writer' => mb_substr($writer, 0, 50), ':pinned' => $pinned, ':secret' => $secret,
                      ':answer' => $answer === '' ? null : $answer, ':created_at' => $created, ':updated_at' => $now, ':id' => $id, ':board' => $board];
                if ($answer !== '' && $answeredAt) {
                    $p[':answered_at'] = $answeredAt;
                }
                $pdo->prepare($sql)->execute($p);
                go($board, '수정했습니다.');
            }
            $pdo->prepare('INSERT INTO board_post (board, title, content, writer, views, pinned, is_secret, answer, answered_at, created_at, updated_at)
                           VALUES (:board, :title, :content, :writer, 0, :pinned, :secret, :answer, :answered_at, :created_at, :updated_at)')
                ->execute([':board' => $board, ':title' => $title, ':content' => $content, ':writer' => mb_substr($writer, 0, 50), ':pinned' => $pinned,
                           ':secret' => $secret, ':answer' => $answer === '' ? null : $answer, ':answered_at' => $answer === '' ? null : $now,
                           ':created_at' => $created, ':updated_at' => $now]);
            go($board, '등록했습니다.');
        }
        $act = $id ? 'edit' : 'new';
    }
}

/* ---------- 화면 데이터 ---------- */
$row = null;
if ($act === 'edit' && $id) {
    $st = $pdo->prepare('SELECT * FROM board_post WHERE id = :id AND board = :board');
    $st->execute([':id' => $id, ':board' => $board]);
    $row = $st->fetch() ?: null;
    if (!$row) {
        go($board, '글을 찾을 수 없습니다.');
    }
}
if ($err && $_SERVER['REQUEST_METHOD'] === 'POST') {          // 입력값 유지
    $row = array_merge($row ?? [], [
        'id' => $id, 'title' => $_POST['title'] ?? '', 'content' => $_POST['content'] ?? '', 'writer' => $_POST['writer'] ?? '',
        'pinned' => !empty($_POST['pinned']) ? 1 : 0, 'is_secret' => !empty($_POST['is_secret']) ? 1 : 0,
        'answer' => $_POST['answer'] ?? '', 'created_at' => ($_POST['created_at'] ?? '') . ' 00:00:00',
    ]);
}
$page = max(1, (int) ($_GET['page'] ?? 1));
$size = 20;
$total = 0;
$list = [];
if ($act === 'list') {
    $st = $pdo->prepare('SELECT COUNT(*) FROM board_post WHERE board = :board');
    $st->execute([':board' => $board]);
    $total = (int) $st->fetchColumn();
    $st = $pdo->prepare('SELECT id, title, writer, views, pinned, is_secret, answer, created_at FROM board_post WHERE board = :board ORDER BY pinned DESC, id DESC LIMIT :lim OFFSET :off');
    $st->bindValue(':board', $board);
    $st->bindValue(':lim', $size, PDO::PARAM_INT);
    $st->bindValue(':off', ($page - 1) * $size, PDO::PARAM_INT);
    $st->execute();
    $list = $st->fetchAll();
}
$pages = max(1, (int) ceil($total / $size));
$driver = board_is_mysql($pdo) ? 'MySQL' : 'SQLite(임시 · MySQL 미연결)';
?>
<!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>게시판 관리 · <?= h($boards[$board]) ?> | 굿배송항공 GOODPOST</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Noto+Sans+KR:wght@400;500;700;900&family=Montserrat:wght@600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="../assets/css/style.css">
<style>
.admin-wrap { max-width: 1100px; margin: 0 auto; padding: 32px var(--gutter) 60px; }
.admin-top { display: flex; align-items: center; gap: 16px; flex-wrap: wrap; margin-bottom: 20px; }
.admin-top .tabs { display: flex; gap: 6px; }
.admin-top .tabs a { padding: 9px 16px; border-radius: 999px; border: 1px solid var(--line); font-weight: 700; font-size: 14px; color: var(--ink); }
.admin-top .tabs a.is-active { background: var(--navy); color: #fff; border-color: var(--navy); }
.admin-top .right { margin-left: auto; display: flex; gap: 8px; align-items: center; }
.flash { background: var(--tint); color: var(--navy); border-radius: 10px; padding: 12px 16px; margin-bottom: 16px; font-size: 14px; }
.flash--err { background: var(--warn-bg); color: var(--warn); }
.tbl { width: 100%; border-collapse: collapse; background: #fff; border: 1px solid var(--line); border-radius: 12px; overflow: hidden; }
.tbl th, .tbl td { padding: 12px 10px; border-bottom: 1px solid var(--line); font-size: 14px; text-align: left; vertical-align: middle; }
.tbl th { background: var(--tint); color: var(--navy); font-size: 13px; }
.tbl td.num, .tbl th.num { text-align: center; width: 64px; font-family: var(--font-en); color: var(--muted); }
.tbl td.act { white-space: nowrap; width: 150px; }
.tbl .tag { display: inline-block; padding: 2px 7px; border-radius: 999px; font-size: 11px; font-weight: 700; margin-right: 4px; }
.tag--pin { background: #E6F0FF; color: var(--blue); } .tag--secret { background: #F1F1F4; color: var(--muted); } .tag--ans { background: #E7F7EE; color: #1B7F4A; } .tag--wait { background: var(--warn-bg); color: var(--warn); }
.btn--xs { height: 32px; padding: 0 12px; font-size: 13px; }
.btn--danger { background: #fff; color: #C0392B; border-color: #C0392B; }
.form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 14px 18px; }
.form-grid .full { grid-column: 1 / -1; }
.field label { display: block; font-size: 13px; font-weight: 700; color: var(--navy); margin-bottom: 6px; }
.field input[type=text], .field input[type=date], .field textarea { width: 100%; padding: 10px 12px; border: 1px solid var(--line-2); border-radius: 10px; font: inherit; font-size: 14px; }
.field textarea { min-height: 260px; line-height: 1.6; }
.field .note { margin-top: 6px; }
.checks { display: flex; gap: 18px; align-items: center; font-size: 14px; padding-top: 28px; }
.pager-a { display: flex; gap: 4px; justify-content: center; margin-top: 18px; }
.pager-a a { width: 34px; height: 34px; border-radius: 8px; display: flex; align-items: center; justify-content: center; font-family: var(--font-en); font-size: 13px; color: var(--ink); border: 1px solid var(--line); }
.pager-a a.is-active { background: var(--navy); color: #fff; border-color: var(--navy); }
.tbl .tag { white-space: nowrap; }
.tbl td.title a { word-break: keep-all; overflow-wrap: anywhere; }
@media (max-width: 720px) {
  .form-grid { grid-template-columns: 1fr; } .checks { padding-top: 0; flex-wrap: wrap; gap: 10px; }
  .admin-top .right { margin-left: 0; width: 100%; justify-content: space-between; }
  /* 표를 카드형으로: 번호 · 조회 숨김, 제목 한 줄, 작성자 · 등록일 한 줄, 버튼 한 줄 */
  .tbl thead { display: none; }
  .tbl, .tbl tbody, .tbl tr { display: block; width: 100%; }
  .tbl tr { padding: 12px 14px; border-bottom: 1px solid var(--line); }
  .tbl tr:last-child { border-bottom: 0; }
  .tbl td { display: block; padding: 0; border: 0; width: auto; }
  .tbl td.num { display: none; }
  .tbl td.title { font-size: 15px; line-height: 1.5; margin-bottom: 6px; }
  .tbl td.title .tag { vertical-align: 1px; }
  .tbl td.writer, .tbl td.date { display: inline; font-size: 13px; color: var(--muted); }
  .tbl td.writer::after { content: " · "; }
  .tbl td.act { margin-top: 10px; display: flex; gap: 6px; }
  .tbl td.act form { display: inline; }
}
</style>
</head>
<body>
<header class="site-header"><div class="container"><a class="logo" href="../index.html"><img src="../assets/img/logo.png" alt="GOODPOST"></a><div class="header-tools" style="margin-left:auto"><a class="btn btn--outline btn--sm btn--square" href="rates.html">관리자 홈</a></div></div></header>
<main class="admin-wrap">
  <div class="admin-top">
    <div><span class="eyebrow">BOARD ADMIN</span><h1 class="h2" style="margin:0">게시판 관리</h1></div>
    <nav class="tabs" aria-label="게시판">
      <?php foreach ($boards as $k => $label): ?><a href="posts.php?board=<?= $k ?>" class="<?= $k === $board ? 'is-active' : '' ?>"><?= h($label) ?></a><?php endforeach; ?>
    </nav>
    <div class="right">
      <span class="note">저장소: <?= h($driver) ?></span>
      <?php if ($act === 'list'): ?><a class="btn btn--navy btn--sm btn--square" href="posts.php?board=<?= $board ?>&amp;act=new">새 글 작성</a><?php endif; ?>
    </div>
  </div>
  <?php if ($msg): ?><div class="flash"><?= h($msg) ?></div><?php endif; ?>
  <?php if ($err): ?><div class="flash flash--err"><?= h($err) ?></div><?php endif; ?>

<?php if ($act === 'list'): ?>
  <table class="tbl">
    <thead><tr><th class="num">번호</th><th>제목</th><th style="width:110px">작성자</th><th style="width:110px">등록일</th><th class="num">조회</th><th class="act">관리</th></tr></thead>
    <tbody>
    <?php if (!$list): ?><tr><td colspan="6" style="text-align:center;color:var(--muted);padding:32px">등록된 글이 없습니다.</td></tr><?php endif; ?>
    <?php foreach ($list as $r): ?>
      <tr>
        <td class="num"><?= (int) $r['id'] ?></td>
        <td class="title">
          <?php if ($r['pinned']): ?><span class="tag tag--pin">공지</span><?php endif; ?>
          <?php if ($r['is_secret']): ?><span class="tag tag--secret">비밀글</span><?php endif; ?>
          <?php if ($board === 'qna'): ?><span class="tag <?= $r['answer'] ? 'tag--ans">답변완료' : 'tag--wait">답변대기' ?></span><?php endif; ?>
          <a href="../helpdesk/<?= $board ?>.html?id=<?= (int) $r['id'] ?>" target="_blank" rel="noopener" style="color:var(--ink)"><?= h($r['title']) ?></a>
        </td>
        <td class="writer"><?= h($r['writer']) ?></td>
        <td class="date" style="font-family:var(--font-en);font-size:13px;color:var(--muted)"><?= h(substr((string) $r['created_at'], 0, 10)) ?></td>
        <td class="num"><?= (int) $r['views'] ?></td>
        <td class="act">
          <a class="btn btn--outline btn--xs btn--square" href="posts.php?board=<?= $board ?>&amp;act=edit&amp;id=<?= (int) $r['id'] ?>"><?= $board === 'qna' && !$r['answer'] ? '답변' : '수정' ?></a>
          <form method="post" action="posts.php?board=<?= $board ?>&amp;act=delete&amp;id=<?= (int) $r['id'] ?>" style="display:inline" onsubmit="return confirm('이 글을 삭제할까요? 되돌릴 수 없습니다.')">
            <input type="hidden" name="csrf" value="<?= h($csrf) ?>"><button class="btn btn--outline btn--danger btn--xs btn--square" type="submit">삭제</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php if ($pages > 1): ?><nav class="pager-a" aria-label="페이지"><?php for ($p = 1; $p <= $pages; $p++): ?><a href="posts.php?board=<?= $board ?>&amp;page=<?= $p ?>" class="<?= $p === $page ? 'is-active' : '' ?>"><?= $p ?></a><?php endfor; ?></nav><?php endif; ?>

<?php else: ?>
  <form method="post" action="posts.php?board=<?= $board ?>&amp;act=save<?= $row && !empty($row['id']) ? '&amp;id=' . (int) $row['id'] : '' ?>" class="card">
    <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
    <div class="form-grid">
      <div class="field full"><label for="title">제목</label><input id="title" type="text" name="title" maxlength="200" required value="<?= h($row['title'] ?? '') ?>"></div>
      <div class="field"><label for="writer">작성자</label><input id="writer" type="text" name="writer" maxlength="50" value="<?= h($row['writer'] ?? '관리자') ?>"></div>
      <div class="field"><label for="created_at">등록일</label><input id="created_at" type="date" name="created_at" value="<?= h(substr((string) ($row['created_at'] ?? date('Y-m-d')), 0, 10)) ?>"></div>
      <div class="checks full">
        <label><input type="checkbox" name="pinned" value="1" <?= !empty($row['pinned']) ? 'checked' : '' ?>> 목록 맨 위에 고정(공지)</label>
        <label><input type="checkbox" name="is_secret" value="1" <?= !empty($row['is_secret']) ? 'checked' : '' ?>> 비밀글(관리자만 열람)</label>
      </div>
      <div class="field full"><label for="content"><?= $board === 'qna' ? '문의 내용' : '내용' ?></label><textarea id="content" name="content" required><?= h($row['content'] ?? '') ?></textarea><p class="note">HTML 태그를 그대로 쓸 수 있습니다. 줄바꿈만 필요하면 &lt;p&gt; 또는 &lt;br&gt; 을 넣어 주세요.</p></div>
      <?php if ($board === 'qna'): ?>
      <div class="field full"><label for="answer">관리자 답변</label><textarea id="answer" name="answer" style="min-height:160px"><?= h($row['answer'] ?? '') ?></textarea><p class="note">답변을 입력하면 홈페이지 Q&amp;A 본문 아래에 "답변" 으로 표시되고 목록에 답변완료가 붙습니다.</p></div>
      <?php endif; ?>
    </div>
    <div class="form-actions" style="margin-top:20px">
      <a class="btn btn--outline btn--sm btn--square" href="posts.php?board=<?= $board ?>">취소</a>
      <button class="btn btn--navy btn--sm btn--square" type="submit"><?= $row && !empty($row['id']) ? '수정 저장' : '등록' ?></button>
    </div>
  </form>
<?php endif; ?>
</main>
</body>
</html>
