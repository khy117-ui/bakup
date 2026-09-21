<?php
// 웹에서 직접 열면 실행되지 않게 막습니다 (nginx 면 .htaccess 가 무시됩니다)
if (!defined('APP_DIR')) { http_response_code(403); exit('Forbidden'); }
/**
 * 문서 내려받기 — 로그인한 사람만.
 *
 * 파일은 웹에서 직접 못 여는 서버 폴더 또는 NAS 비공개 폴더에 있고, 저장 이름도 원본과 무관한 난수입니다.
 * 로그인 · 문서 보기 권한 · 사업자 확인을 거친 뒤 여기서만 내보냅니다.
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

// 저장 경로는 DB 에 있는 값(상대경로)만 씁니다. 사용자가 준 경로를 절대 쓰지 않습니다.
// 파일은 이 서버 사본이 있으면 거기서, 없으면 NAS(WebDAV)에서 ERP 가 대신 받아 흘려줍니다 — NAS 주소는 밖에 안 나감
require_once APP_DIR . '/filestore.php';
$rel = str_replace(DIRECTORY_SEPARATOR, '/', (string)$doc['stored_path']);
if (!fs_rel_ok($rel)) {
    http_response_code(400);
    exit('경로가 올바르지 않습니다.');
}

log_action('문서', 'DOWNLOAD', 'documents', $id, (string)$doc['original_name']);

if (!fs_send_private('docs', $rel, (string)$doc['original_name'])) {
    http_response_code(404);
    exit('파일이 없습니다. 서버 · NAS 어디에도 없거나 NAS 에 연결되지 않습니다. 잠시 뒤 다시 해 보세요.');
}
exit;
