<?php
/**
 * 요금표 엑셀 업로드 엔드포인트 (선택 사항 · PHP 호스팅에서 사용)
 *
 * admin/rates.html 의 "서버에 업로드" 폼이 이 파일로 rates.xlsx 를 보내면
 * 검증 후 assets/data/rates.xlsx 를 교체합니다. 이 파일은 /admin/ 폴더에 있으므로
 * .htaccess · web.config · nginx 설정의 IP 제한과 Basic 인증이 그대로 적용됩니다.
 *
 * 추가 방어 (서버 설정이 빠졌을 때를 대비한 이중 잠금):
 *   - 아래 ALLOWED_IPS 에 없는 IP는 403
 *   - Basic 인증 사용자(REMOTE_USER)가 없으면 403
 *   - POST + 토큰(ADMIN_TOKEN) 일치 + .xlsx 서명 검사 + 5MB 상한
 *   - 교체 전 이전 파일을 assets/data/backup/ 에 날짜별로 보관
 */
declare(strict_types=1);

const ALLOWED_IPS = ['127.0.0.1', '61.43.120.209', '117.110.98.252', '175.209.40.226', '211.206.111.242'];
const ALLOWED_PREFIX = ['221.151.149.'];
const ADMIN_TOKEN = '';                 // 배포 시 긴 무작위 문자열로 설정 (비워 두면 토큰 검사 생략)
const MAX_BYTES = 5 * 1024 * 1024;
const TARGET = __DIR__ . '/../assets/data/rates.xlsx';
const BACKUP_DIR = __DIR__ . '/../assets/data/backup';

header('Content-Type: application/json; charset=utf-8');
header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store');

function fail(int $code, string $msg): void {
    http_response_code($code);
    echo json_encode(['ok' => false, 'message' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

$ip = $_SERVER['REMOTE_ADDR'] ?? '';
$ipOk = in_array($ip, ALLOWED_IPS, true);
foreach (ALLOWED_PREFIX as $prefix) {
    if (strpos($ip, $prefix) === 0) { $ipOk = true; }
}
if (!$ipOk) { fail(403, '허용되지 않은 IP에서의 접근입니다.'); }
if (empty($_SERVER['REMOTE_USER']) && empty($_SERVER['PHP_AUTH_USER'])) { fail(403, '관리자 인증이 필요합니다.'); }
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { fail(405, 'POST 요청만 허용됩니다.'); }
if (ADMIN_TOKEN !== '' && !hash_equals(ADMIN_TOKEN, (string)($_POST['token'] ?? ''))) { fail(403, '업로드 토큰이 일치하지 않습니다.'); }

$file = $_FILES['rates'] ?? null;
if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) { fail(400, '업로드된 파일이 없습니다.'); }
if ($file['size'] > MAX_BYTES) { fail(413, '파일이 5MB를 초과합니다.'); }
if (strtolower(pathinfo($file['name'], PATHINFO_EXTENSION)) !== 'xlsx') { fail(400, '.xlsx 파일만 업로드할 수 있습니다.'); }

// .xlsx 는 ZIP 컨테이너: 파일 서명(PK\x03\x04)과 워크북 항목 존재 여부로 검증
$head = file_get_contents($file['tmp_name'], false, null, 0, 4);
if ($head !== "PK\x03\x04") { fail(400, '올바른 엑셀(.xlsx) 파일이 아닙니다.'); }
$zip = new ZipArchive();
if ($zip->open($file['tmp_name']) !== true || $zip->locateName('xl/workbook.xml') === false) { fail(400, '엑셀 워크북을 읽을 수 없습니다.'); }
$zip->close();

if (!is_dir(BACKUP_DIR) && !mkdir(BACKUP_DIR, 0755, true)) { fail(500, '백업 폴더를 만들 수 없습니다.'); }
if (is_file(TARGET)) {
    copy(TARGET, BACKUP_DIR . '/rates-' . date('Ymd-His') . '.xlsx');
}
if (!move_uploaded_file($file['tmp_name'], TARGET)) { fail(500, '파일을 저장하지 못했습니다. 폴더 쓰기 권한을 확인하세요.'); }
chmod(TARGET, 0644);

echo json_encode(['ok' => true, 'message' => '요금표가 교체되었습니다. 요금 페이지를 새로고침하면 반영됩니다.', 'updated' => date('c')], JSON_UNESCAPED_UNICODE);
