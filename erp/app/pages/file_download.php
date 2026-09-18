<?php
// 웹에서 직접 열면 실행되지 않게 막습니다 (nginx 면 .htaccess 가 무시됩니다)
if (!defined('APP_DIR')) { http_response_code(403); exit('Forbidden'); }
/**
 * 문서 내려받기 — 로그인한 사람만.
 *
 * 파일은 웹 루트 밖에 있고, 저장 이름도 원본과 무관한 난수입니다.
 * 여기서만 내보내므로 URL 을 알아도 로그인 없이는 받을 수 없습니다.
 */
$eid = entity_id();
$id  = (int)query('id', '0');

$st = db()->prepare(
    'SELECT d.*, t.name AS type_name FROM documents d
       JOIN document_types t ON t.id = d.document_type_id
      WHERE d.id = ? AND d.business_entity_id = ? AND d.deleted_at IS NULL');
$st->execute([$id, $eid]);
$doc = $st->fetch();
if (!$doc) {
    http_response_code(404);
    exit('문서를 찾을 수 없습니다.');
}

// 저장 경로는 DB 에 있는 값만 씁니다. 사용자가 준 경로를 절대 쓰지 않습니다.
// 혹시 값이 오염됐더라도 보관 폴더 밖으로 못 나가게 다시 확인합니다
$root = storage_root();
$rel  = str_replace(DIRECTORY_SEPARATOR, '/', (string)$doc['stored_path']);
if ($rel === '' || strpos($rel, '..') !== false || $rel[0] === '/') {
    http_response_code(400);
    exit('경로가 올바르지 않습니다.');
}
$full     = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
$realRoot = realpath($root);
$realFull = realpath($full);
if ($realRoot === false || $realFull === false || strpos($realFull, $realRoot) !== 0) {
    http_response_code(404);
    exit('파일이 없습니다. 서버에서 옮겨졌거나 삭제됐을 수 있습니다.');
}

log_action('문서', 'DOWNLOAD', 'documents', $id, (string)$doc['original_name']);

// 헤더에 넣을 수 없는 문자를 걸러냅니다 (응답 헤더 주입 방지)
$safe = preg_replace('/[\r\n"]/', '', (string)$doc['original_name']);
if ($safe === '' || $safe === null) {
    $safe = 'document';
}

header('Content-Type: application/octet-stream');
header('Content-Length: ' . (string)filesize($realFull));
header('Content-Disposition: attachment; filename="' . rawurlencode($safe) . '"; '
     . "filename*=UTF-8''" . rawurlencode($safe));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
readfile($realFull);
exit;
