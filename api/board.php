<?php
/**
 * 게시판 공개 API (공지사항 · Q&A)
 *   목록  GET  board.php?board=notice&page=1&size=15&q=검색어
 *   본문  GET  board.php?board=qna&id=12            (비밀글: pw 파라미터로 비밀번호 확인)
 *   등록  POST board.php  board=qna&title=&writer=&content=&secret=1&password=   (Q&A 만 공개 등록 허용)
 * 응답 형식은 README "게시판 API 형식" 참고. 관리(수정 · 삭제 · 답변)는 admin/posts.php 에서 처리합니다.
 */
declare(strict_types=1);
require __DIR__ . '/../includes/db.php';

$boards = ['notice' => '공지사항', 'qna' => 'Q&A'];
$board = $_REQUEST['board'] ?? 'notice';
if (!isset($boards[$board])) {
    board_json(['error' => '알 수 없는 게시판입니다.'], 400);
}

try {
    $pdo = board_db();
} catch (Throwable $e) {
    board_json(['error' => 'DB 연결에 실패했습니다.'], 503);
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

/* ---------- 등록 (Q&A) ---------- */
if ($method === 'POST') {
    if ($board !== 'qna') {
        board_json(['error' => '이 게시판은 관리자만 글을 쓸 수 있습니다.'], 403);
    }
    if (!empty($_POST['website'])) {                       // 스팸 봇용 숨김 필드
        board_json(['error' => '잘못된 요청입니다.'], 400);
    }
    $title = trim((string) ($_POST['title'] ?? ''));
    $writer = trim((string) ($_POST['writer'] ?? '')) ?: '익명';
    $content = trim((string) ($_POST['content'] ?? ''));
    $secret = !empty($_POST['secret']) ? 1 : 0;
    $password = (string) ($_POST['password'] ?? '');
    if ($title === '' || mb_strlen($title) > 200) {
        board_json(['error' => '제목을 1~200자로 입력해 주세요.'], 400);
    }
    if ($content === '' || mb_strlen($content) > 20000) {
        board_json(['error' => '내용을 입력해 주세요. (최대 20,000자)'], 400);
    }
    if ($secret && strlen($password) < 4) {
        board_json(['error' => '비밀글은 4자 이상의 비밀번호가 필요합니다.'], 400);
    }
    $now = board_now();
    $st = $pdo->prepare('INSERT INTO board_post (board, title, content, writer, views, pinned, is_secret, password, created_at, updated_at)
                         VALUES (:board, :title, :content, :writer, 0, 0, :is_secret, :password, :now, :now2)');
    $st->execute([
        ':board' => 'qna',
        ':title' => $title,
        ':content' => nl2br(htmlspecialchars($content, ENT_QUOTES, 'UTF-8')),   // 일반 텍스트로 저장 · 줄바꿈 유지
        ':writer' => mb_substr($writer, 0, 50),
        ':is_secret' => $secret,
        ':password' => $secret ? password_hash($password, PASSWORD_DEFAULT) : null,
        ':now' => $now,
        ':now2' => $now,
    ]);
    board_json(['ok' => true, 'id' => (int) $pdo->lastInsertId()], 201);
}

/* ---------- 본문 ---------- */
if (isset($_GET['id'])) {
    $st = $pdo->prepare('SELECT * FROM board_post WHERE id = :id AND board = :board');
    $st->execute([':id' => (int) $_GET['id'], ':board' => $board]);
    $row = $st->fetch();
    if (!$row) {
        board_json(['error' => '글을 찾을 수 없습니다.'], 404);
    }
    if ($row['is_secret']) {
        $pw = (string) ($_REQUEST['pw'] ?? '');
        $ok = $pw !== '' && $row['password'] && password_verify($pw, $row['password']);
        if (!$ok) {
            board_json(['error' => 'secret', 'message' => '비밀글입니다. 작성 시 입력한 비밀번호를 입력해 주세요.', 'item' => board_row_public($row)], 403);
        }
    }
    $pdo->prepare('UPDATE board_post SET views = views + 1 WHERE id = :id')->execute([':id' => $row['id']]);
    $item = board_row_public($row);
    $item['views'] = (int) $row['views'] + 1;
    $item['content'] = board_clean_html((string) $row['content']);
    $item['answer'] = $row['answer'] ? board_clean_html((string) $row['answer']) : null;
    $item['answered_at'] = $row['answered_at'] ? substr((string) $row['answered_at'], 0, 10) : null;
    board_json($item);
}

/* ---------- 목록 ---------- */
$page = max(1, (int) ($_GET['page'] ?? 1));
$size = min(50, max(1, (int) ($_GET['size'] ?? 15)));
$q = trim((string) ($_GET['q'] ?? ''));
$where = 'board = :board';
$args = [':board' => $board];
if ($q !== '') {
    $where .= ' AND (title LIKE :q1 OR content LIKE :q2)';
    $args[':q1'] = '%' . $q . '%';
    $args[':q2'] = '%' . $q . '%';
}
$st = $pdo->prepare("SELECT COUNT(*) FROM board_post WHERE $where AND pinned = 0");
$st->execute($args);
$total = (int) $st->fetchColumn();

$items = [];
if ($page === 1) {
    $st = $pdo->prepare("SELECT * FROM board_post WHERE $where AND pinned = 1 ORDER BY id DESC");
    $st->execute($args);
    foreach ($st as $r) {
        $items[] = board_row_public($r);
    }
}
$st = $pdo->prepare("SELECT * FROM board_post WHERE $where AND pinned = 0 ORDER BY id DESC LIMIT :lim OFFSET :off");
foreach ($args as $k => $v) {
    $st->bindValue($k, $v);
}
$st->bindValue(':lim', $size, PDO::PARAM_INT);
$st->bindValue(':off', ($page - 1) * $size, PDO::PARAM_INT);
$st->execute();
foreach ($st as $r) {
    $items[] = board_row_public($r);
}
board_json(['total' => $total, 'page' => $page, 'size' => $size, 'items' => $items]);
