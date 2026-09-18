<?php
// 웹에서 직접 열면 실행되지 않게 막습니다 (nginx 면 .htaccess 가 무시됩니다)
if (!defined('APP_DIR')) { http_response_code(403); exit('Forbidden'); }

/**
 * 자동 화물추적 신호 — 로그인한 ERP 화면이 뒤에서 가끔 부릅니다 (layout 의 스크립트).
 * 서버 전체에서 5분에 한 번만 실제로 일하고 (app/track_any.php track_auto_tick), 나머지는 바로 끝납니다.
 * 서버에 예약 작업(cron)을 둘 수 없어서 이렇게 합니다.
 */
require_once APP_DIR . '/track_any.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
session_write_close();   // 운송사 응답을 기다리는 동안 같은 사람의 다른 화면이 막히지 않게

try {
    $res = track_auto_tick(db(), 3);
} catch (Throwable $e) {
    error_log('자동 추적 실패: ' . $e->getMessage());
    $res = ['error' => 1];
}
echo json_encode($res, JSON_UNESCAPED_UNICODE);
exit;
