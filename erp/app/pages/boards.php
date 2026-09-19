<?php
// 웹에서 직접 열면 실행되지 않게 막습니다 (nginx 면 .htaccess 가 무시됩니다)
if (!defined('APP_DIR')) { http_response_code(403); exit('Forbidden'); }

require_once APP_DIR . '/layout.php';

/**
 * 홈페이지 게시판 (공지사항 · Q&A) — 홈페이지가 읽는 표(board_post)를 ERP 에서 바로 관리합니다.
 * 여기서 쓰고 고치면 홈페이지 고객센터에 곧바로 보입니다. 게시판은 하나뿐입니다 (예전 홈페이지 관리자 화면은 닫음).
 *
 *   글쓰기 · 수정 · 공지 고정 · 비밀글 · Q&A 답변 · 휴지통(삭제 대신 옮김, 되돌리기 가능)
 *   휴지통은 board 값을 'trash_notice' / 'trash_qna' 로 바꿔 두는 방식 — 홈페이지 API 는 notice · qna 만 읽으므로 바로 숨겨짐
 */

const BOARD_NAMES = ['notice' => '공지사항', 'qna' => 'Q&A'];
$err = '';
$pdo = db();
$b   = array_key_exists(query('b'), BOARD_NAMES) ? query('b') : (query('b') === 'trash' ? 'trash' : 'notice');
$pid = (int)query('id', '0');

/** 입력 글(일반 텍스트) → 저장 HTML (줄바꿈 유지, 태그는 글자로) */
function bd_to_html(string $text): string
{
    return nl2br(htmlspecialchars(trim($text), ENT_QUOTES, 'UTF-8'));
}

/** 저장 HTML → 고칠 때 보여줄 글 */
function bd_to_text(?string $html): string
{
    $s = preg_replace('#<br\s*/?>\s*#i', "\n", (string)$html);
    $s = preg_replace('#</p>\s*#i', "\n\n", (string)$s);
    return trim(html_entity_decode(strip_tags((string)$s), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
}

// 표가 없으면 (홈페이지가 한 번도 안 열렸으면) 홈페이지와 같은 모양으로 만듭니다
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS board_post (
                  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                  board VARCHAR(20) NOT NULL, title VARCHAR(200) NOT NULL, content MEDIUMTEXT NOT NULL,
                  writer VARCHAR(50) NOT NULL DEFAULT '관리자', views INT UNSIGNED NOT NULL DEFAULT 0,
                  pinned TINYINT(1) NOT NULL DEFAULT 0, is_secret TINYINT(1) NOT NULL DEFAULT 0, password VARCHAR(255) NULL,
                  answer MEDIUMTEXT NULL, answered_at DATETIME NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL,
                  INDEX idx_board (board, pinned, id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} catch (PDOException $e) {
    error_log('board_post 준비 실패: ' . $e->getMessage());
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $act = post('act');
    try {
        if ($act === 'save') {
            $bb = array_key_exists(post('board'), BOARD_NAMES) ? post('board') : 'notice';
            $title = mb_substr(trim(post('title')), 0, 200);
            $body  = (string)($_POST['content'] ?? '');
            if ($title === '' || trim($body) === '') { throw new RuntimeException('제목과 내용을 입력하세요.'); }
            $writer = mb_substr(trim(post('writer')), 0, 50) ?: '관리자';
            $pinned = post('pinned') === '1' ? 1 : 0;
            $id = (int)post('id');
            if ($id > 0) {
                $pdo->prepare('UPDATE board_post SET title = ?, content = ?, writer = ?, pinned = ?, updated_at = NOW()
                                WHERE id = ? AND board = ?')
                    ->execute([$title, bd_to_html($body), $writer, $pinned, $id, $bb]);
                log_action('게시판', 'UPDATE', 'board_post', $id, $title);
                flash('글을 고쳤습니다. 홈페이지에 바로 반영됩니다.');
            } else {
                $pdo->prepare('INSERT INTO board_post (board, title, content, writer, views, pinned, is_secret, created_at, updated_at)
                               VALUES (?,?,?,?,0,?,0,NOW(),NOW())')
                    ->execute([$bb, $title, bd_to_html($body), $writer, $pinned]);
                $id = (int)$pdo->lastInsertId();
                log_action('게시판', 'CREATE', 'board_post', $id, $title);
                flash('글을 올렸습니다. 홈페이지 ' . BOARD_NAMES[$bb] . '에 바로 보입니다.');
            }
            redirect('?p=boards&b=' . $bb . '&id=' . $id);
        } elseif ($act === 'pin') {
            // 목록 · 글 화면에서 바로 고정 / 해제
            $id = (int)post('id');
            $st = $pdo->prepare("SELECT id, board, title, pinned FROM board_post WHERE id = ? AND board IN ('notice','qna')");
            $st->execute([$id]);
            $row = $st->fetch();
            if (!$row) { throw new RuntimeException('글을 찾을 수 없습니다.'); }
            $to = (int)$row['pinned'] ? 0 : 1;
            $pdo->prepare('UPDATE board_post SET pinned = ?, updated_at = NOW() WHERE id = ?')->execute([$to, $id]);
            log_action('게시판', 'UPDATE', 'board_post', $id, (string)$row['title'], null, $to ? '맨 위 고정' : '고정 해제');
            flash('"' . $row['title'] . '" ' . ($to ? '을 맨 위에 고정했습니다.' : '의 고정을 풀었습니다.') . ' 홈페이지에 바로 반영됩니다.');
            $back = post('back') === 'view' ? '&id=' . $id : '';
            redirect('?p=boards&b=' . $row['board'] . $back);
        } elseif ($act === 'answer') {
            $id = (int)post('id');
            $ans = trim((string)($_POST['answer'] ?? ''));
            $pdo->prepare("UPDATE board_post SET answer = ?, answered_at = " . ($ans !== '' ? 'NOW()' : 'NULL') . ", updated_at = NOW()
                            WHERE id = ? AND board = 'qna'")
                ->execute([$ans !== '' ? bd_to_html($ans) : null, $id]);
            log_action('게시판', 'UPDATE', 'board_post', $id, 'Q&A 답변', null, $ans !== '' ? '답변 등록' : '답변 지움');
            flash($ans !== '' ? '답변을 저장했습니다. 홈페이지에 "답변완료" 로 보입니다.' : '답변을 지웠습니다.');
            redirect('?p=boards&b=qna&id=' . $id);
        } elseif ($act === 'hide') {
            // 삭제 — 휴지통으로 옮김 (DELETE_ACTIONS 'boards/hide' — 권한 없으면 승인 요청)
            $id = (int)post('post_id');
            $why = trim(post('reason'));
            if (mb_strlen($why) < 2) { throw new RuntimeException('삭제 사유를 적어 주세요.'); }
            $st = $pdo->prepare("SELECT id, board, title FROM board_post WHERE id = ? AND board IN ('notice','qna')");
            $st->execute([$id]);
            $row = $st->fetch();
            if (!$row) { throw new RuntimeException('글을 찾을 수 없습니다.'); }
            $pdo->prepare("UPDATE board_post SET board = CONCAT('trash_', board), updated_at = NOW() WHERE id = ?")->execute([$id]);
            log_action('게시판', 'DELETE', 'board_post', $id, (string)$row['title'], null, '휴지통으로', $why);
            flash('휴지통으로 옮겼습니다. 홈페이지에서 바로 사라지고, 휴지통에서 되돌릴 수 있습니다.');
            redirect('?p=boards&b=' . $row['board']);
        } elseif ($act === 'restore') {
            $id = (int)post('id');
            $pdo->prepare("UPDATE board_post SET board = REPLACE(board, 'trash_', ''), updated_at = NOW()
                            WHERE id = ? AND board LIKE 'trash\\_%'")->execute([$id]);
            log_action('게시판', 'UPDATE', 'board_post', $id, null, null, '휴지통에서 되돌림');
            flash('글을 되돌렸습니다.');
            redirect('?p=boards&b=trash');
        }
    } catch (RuntimeException $e) {
        $err = $e->getMessage();
    } catch (PDOException $e) {
        error_log('게시판 저장 실패: ' . $e->getMessage());
        $err = '저장하지 못했습니다.';
    }
}

// ---------------------------------------------------------------- 조회
$cur = null;
if ($pid > 0) {
    $st = $pdo->prepare('SELECT * FROM board_post WHERE id = ?');
    $st->execute([$pid]);
    $cur = $st->fetch() ?: null;
}
$isNew = query('new') === '1';
$kw = trim(query('kw'));
$w = $b === 'trash' ? "board LIKE 'trash\\_%'" : 'board = ?';
$pa = $b === 'trash' ? [] : [$b];
if ($kw !== '') { $w .= ' AND (title LIKE ? OR content LIKE ? OR writer LIKE ?)'; array_push($pa, "%$kw%", "%$kw%", "%$kw%"); }
$st = $pdo->prepare("SELECT id, board, title, writer, views, pinned, is_secret, answer IS NOT NULL AS answered, created_at
                       FROM board_post WHERE $w ORDER BY pinned DESC, id DESC LIMIT 200");
$st->execute($pa);
$rows = $st->fetchAll();
$cnt = ['notice' => 0, 'qna' => 0, 'trash' => 0, 'wait' => 0];
foreach ($pdo->query("SELECT board, COUNT(*) c, SUM(board = 'qna' AND answer IS NULL) w FROM board_post GROUP BY board")->fetchAll() as $r) {
    $k = strpos((string)$r['board'], 'trash_') === 0 ? 'trash' : (string)$r['board'];
    if (isset($cnt[$k])) { $cnt[$k] += (int)$r['c']; }
    $cnt['wait'] += (int)$r['w'];
}
$canEdit = route_can_edit('boards');
$home = '../helpdesk/' . ($b === 'qna' ? 'qna' : 'notice') . '.html';

layout_head('홈페이지 게시판', 'boards');
?>
<div class="head">
  <h1>홈페이지 게시판</h1>
  <div class="crumb">홈페이지 &gt; 공지사항 · Q&amp;A — 여기서 쓰면 홈페이지 고객센터에 바로 보입니다</div>
  <div class="right">
    <a class="btn" href="<?= h($home) ?>" target="_blank" rel="noopener">홈페이지에서 보기</a>
    <?php if ($canEdit && $b !== 'trash'): ?><a class="btn pri" href="?p=boards&amp;b=<?= h($b) ?>&amp;new=1">새 글</a><?php endif; ?>
  </div>
</div>
<?php if ($err !== ''): ?><div class="msg err"><?= h($err) ?></div><?php endif; ?>

<div class="card"><div class="cb" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
  <a class="btn sm<?= $b === 'notice' ? ' pri' : '' ?>" href="?p=boards&amp;b=notice">공지사항 <?= $cnt['notice'] ?></a>
  <a class="btn sm<?= $b === 'qna' ? ' pri' : '' ?>" href="?p=boards&amp;b=qna">Q&amp;A <?= $cnt['qna'] ?>
    <?php if ($cnt['wait'] > 0): ?><span class="badge b-warn" style="margin-left:4px">답변 대기 <?= $cnt['wait'] ?></span><?php endif; ?></a>
  <a class="btn sm<?= $b === 'trash' ? ' pri' : '' ?>" href="?p=boards&amp;b=trash">휴지통 <?= $cnt['trash'] ?></a>
  <form method="get" style="margin-left:auto;display:flex;gap:6px">
    <input type="hidden" name="p" value="boards"><input type="hidden" name="b" value="<?= h($b) ?>">
    <input type="text" name="kw" value="<?= h($kw) ?>" placeholder="제목 · 내용 · 작성자" style="width:200px">
    <button class="btn sm">검색</button></form>
</div></div>

<?php if ($canEdit && ($isNew || ($cur && in_array($cur['board'], ['notice', 'qna'], true) && query('edit') === '1'))):
  $eb = $cur ? $cur['board'] : ($b === 'trash' ? 'notice' : $b); ?>
<div class="card">
  <div class="ch"><?= $cur ? '글 고치기' : '새 글' ?> · <?= h(BOARD_NAMES[$eb]) ?></div>
  <form method="post" class="cb">
    <?= csrf_field() ?>
    <input type="hidden" name="act" value="save"><input type="hidden" name="board" value="<?= h($eb) ?>">
    <input type="hidden" name="id" value="<?= (int)($cur['id'] ?? 0) ?>">
    <div class="f" style="align-items:flex-end">
      <div class="fw gr" style="min-width:300px"><label>제목 *</label>
        <input type="text" name="title" maxlength="200" required value="<?= h($cur['title'] ?? '') ?>"></div>
      <div class="fw w1"><label>작성자</label><input type="text" name="writer" maxlength="50" value="<?= h($cur['writer'] ?? '관리자') ?>"></div>
      <label style="display:flex;gap:6px;align-items:center;font-size:12.5px"><input type="checkbox" name="pinned" value="1" style="width:auto"<?= !empty($cur['pinned']) ? ' checked' : '' ?>> 맨 위 고정(공지)</label>
    </div>
    <div class="fw" style="margin-top:10px"><label>내용 *</label>
      <textarea name="content" rows="14" required><?= h(bd_to_text($cur['content'] ?? '')) ?></textarea></div>
    <div style="display:flex;gap:8px;margin-top:10px">
      <button class="btn pri">저장 — 홈페이지에 바로 반영</button>
      <a class="btn" href="?p=boards&amp;b=<?= h($eb) ?><?= $cur ? '&amp;id=' . (int)$cur['id'] : '' ?>">취소</a></div>
  </form>
</div>
<?php elseif ($cur): ?>
<div class="card">
  <div class="ch" style="flex-wrap:wrap">
    <?php if ($cur['pinned']): ?><span class="badge b-info">고정</span><?php endif; ?>
    <?php if ($cur['is_secret']): ?><span class="badge b-warn">비밀글</span><?php endif; ?>
    <b><?= h($cur['title']) ?></b>
    <span style="font-weight:400;color:var(--ink3)"><?= h($cur['writer']) ?> · <?= h(substr((string)$cur['created_at'], 0, 16)) ?> · 조회 <?= (int)$cur['views'] ?></span>
    <span style="margin-left:auto;display:flex;gap:6px">
    <?php if ($canEdit && in_array($cur['board'], ['notice', 'qna'], true)): ?>
      <form method="post"><?= csrf_field() ?><input type="hidden" name="act" value="pin"><input type="hidden" name="id" value="<?= (int)$cur['id'] ?>">
        <input type="hidden" name="back" value="view">
        <button class="btn sm<?= $cur['pinned'] ? '' : ' pri' ?>">📌 <?= $cur['pinned'] ? '고정 해제' : '맨 위 고정' ?></button></form>
      <a class="btn sm" href="?p=boards&amp;b=<?= h($cur['board']) ?>&amp;id=<?= (int)$cur['id'] ?>&amp;edit=1">고치기</a>
      <form method="post" onsubmit="var r=prompt('삭제(휴지통으로) 사유를 적어 주세요.');if(!r||r.trim().length<2)return false;this.reason.value=r.trim();return true;">
        <?= csrf_field() ?><input type="hidden" name="act" value="hide"><input type="hidden" name="post_id" value="<?= (int)$cur['id'] ?>">
        <input type="hidden" name="reason" value=""><button class="btn sm" style="color:#A32020">삭제</button></form>
    <?php elseif ($canEdit): ?>
      <form method="post"><?= csrf_field() ?><input type="hidden" name="act" value="restore"><input type="hidden" name="id" value="<?= (int)$cur['id'] ?>">
        <button class="btn sm">되돌리기</button></form>
    <?php endif; ?>
    </span>
  </div>
  <div class="cb" style="font-size:13.5px;line-height:1.8"><?= nl2br(h(bd_to_text($cur['content']))) ?></div>
  <?php if (in_array($cur['board'], ['qna', 'trash_qna'], true)): ?>
  <div class="cb" style="border-top:1px solid var(--line2);background:#F7FAFB">
    <div style="font-weight:700;margin-bottom:6px">답변 <?= $cur['answered_at'] ? '<span style="font-weight:400;color:var(--ink3)">' . h(substr((string)$cur['answered_at'], 0, 16)) . '</span>' : '<span class="badge b-warn">대기</span>' ?></div>
    <?php if ($canEdit && $cur['board'] === 'qna'): ?>
    <form method="post">
      <?= csrf_field() ?><input type="hidden" name="act" value="answer"><input type="hidden" name="id" value="<?= (int)$cur['id'] ?>">
      <textarea name="answer" rows="6" placeholder="답변을 적으면 홈페이지에 '답변완료' 로 보입니다. 비우고 저장하면 답변을 지웁니다."><?= h(bd_to_text($cur['answer'] ?? '')) ?></textarea>
      <button class="btn pri" style="margin-top:8px">답변 저장</button>
    </form>
    <?php else: ?>
      <div style="font-size:13.5px;line-height:1.8"><?= $cur['answer'] ? nl2br(h(bd_to_text($cur['answer']))) : '-' ?></div>
    <?php endif; ?>
  </div>
  <?php endif; ?>
</div>
<?php endif; ?>

<div class="card">
  <?php if (!$rows): ?>
    <div class="empty"><?= $b === 'trash' ? '휴지통이 비어 있습니다.' : '글이 없습니다.' ?></div>
  <?php else: ?>
  <table>
    <thead><tr><?php if ($canEdit && $b !== 'trash'): ?><th class="c" style="width:78px" title="누르면 맨 위 고정 / 해제">고정</th><?php endif; ?>
      <th style="width:70px">번호</th><th>제목</th><th style="width:120px">작성자</th>
      <th style="width:110px">날짜</th><th class="r" style="width:70px">조회</th>
      <?php if ($b !== 'notice'): ?><th class="c" style="width:90px">답변</th><?php endif; ?></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr style="<?= (int)$r['id'] === $pid ? 'background:#EEF6FA' : ($r['pinned'] ? 'background:#F4F9FD' : '') ?>">
        <?php if ($canEdit && $b !== 'trash'): ?>
        <td class="c"><form method="post" style="display:inline"><?= csrf_field() ?>
          <input type="hidden" name="act" value="pin"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
          <button class="btn sm" title="<?= $r['pinned'] ? '고정 해제' : '맨 위 고정' ?>"
                  style="<?= $r['pinned'] ? 'background:#0B4F6C;border-color:#0B4F6C;color:#fff' : 'color:var(--ink3)' ?>">📌<?= $r['pinned'] ? ' 고정' : '' ?></button></form></td>
        <?php endif; ?>
        <td class="tnum"><?= $r['pinned'] ? '<span class="badge b-info">공지</span>' : (int)$r['id'] ?></td>
        <td style="font-weight:600"><a href="?p=boards&amp;b=<?= h($b) ?>&amp;id=<?= (int)$r['id'] ?>"><?= h($r['title']) ?></a>
          <?= $r['is_secret'] ? ' <span class="badge b-warn">비밀</span>' : '' ?></td>
        <td><?= h($r['writer']) ?></td>
        <td class="tnum"><?= h(substr((string)$r['created_at'], 0, 10)) ?></td>
        <td class="r tnum"><?= (int)$r['views'] ?></td>
        <?php if ($b !== 'notice'): ?>
          <td class="c"><?= in_array($r['board'], ['qna', 'trash_qna'], true) ? ($r['answered'] ? '<span class="badge b-ok">완료</span>' : '<span class="badge b-warn">대기</span>') : '' ?></td>
        <?php endif; ?>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>
<?php layout_foot();
