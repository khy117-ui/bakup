<?php
/**
 * 홈페이지 요금표 — 공개 읽기 전용 JSON (로그인 없음)
 *   GET /erp/rates.php → {"ok":true,"sheets":{"DHL_수출_비서류":[["중량(kg)","1지역",…],[0.5,12000,…],…]},"tables":{…}}
 * ERP 단가표에서 '홈페이지 요금표 연결' 해 둔 칸만 나갑니다 (공개해도 되는 기본 요금만 — 거래처 할인율은 안 나감).
 */
declare(strict_types=1);

define('GP_NO_SESSION', true);
require __DIR__ . '/app/bootstrap.php';
require_once APP_DIR . '/webrates.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=120');
header('X-Robots-Tag: noindex');

try {
    $d = webrates_all(db());
    echo json_encode(['ok' => true] + $d, JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('요금표 JSON 실패: ' . $e->getMessage());
    http_response_code(503);
    echo json_encode(['ok' => false, 'sheets' => new stdClass()], JSON_UNESCAPED_UNICODE);
}
