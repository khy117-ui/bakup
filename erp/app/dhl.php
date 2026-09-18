<?php
declare(strict_types=1);

// 웹에서 직접 열면 실행되지 않게 막습니다 (nginx 면 .htaccess 가 무시됩니다)
if (!defined('APP_DIR')) { http_response_code(403); exit('Forbidden'); }

/**
 * DHL 배송조회 — DHL API Developer Portal 의 "Shipment Tracking - Unified" (무료)
 *   GET https://api-eu.dhl.com/track/shipments?trackingNumber=1234567890
 *   헤더 DHL-API-Key: (MyApps 의 Consumer Key)
 *   처음 한도: 하루 250건, 5초에 1건 — 화면에서 한 건씩 누르는 용도로 충분합니다.
 * 응답 JSON: shipments[].events[] = { timestamp, location.address.addressLocality/countryCode,
 *                                     statusCode, status, description, remark }
 */

const DHL_TRACK_URL = 'https://api-eu.dhl.com/track/shipments';

/** DHL Express AWB 모양 (숫자 10자리) */
function dhl_is_no(string $no): bool
{
    return (bool)preg_match('/^\d{10}$/', trim($no));
}

function dhl_key(): string
{
    try {
        $st = db()->prepare("SELECT setting_val FROM app_settings WHERE setting_key = 'dhl_api_key'");
        $st->execute();
        $k = (string)$st->fetchColumn();
    } catch (PDOException $e) {
        $k = '';
    }
    return (string)preg_replace('/\s+/u', '', $k);
}

function dhl_save_key(string $key): void
{
    $key = (string)preg_replace('/\s+/u', '', $key);
    db()->prepare("INSERT INTO app_settings
                     (setting_key, setting_val, group_ko, label_ko, help_ko, input_type, sort_order, updated_by)
                   VALUES ('dhl_api_key', ?, '연동', 'DHL API 키 (Consumer Key)',
                           'developer.dhl.com 의 Shipment Tracking - Unified 앱 Consumer Key. 화물추적에서 씁니다.', 'secret', 2, ?)
                   ON DUPLICATE KEY UPDATE setting_val = VALUES(setting_val), updated_by = VALUES(updated_by),
                                           input_type = 'secret'")
        ->execute([$key, $_SESSION['admin_id'] ?? null]);
}

/**
 * 조회. 돌려주는 값: ['ok' => bool, 'error' => string, 'events' => [[at, status, location, description]], 'raw' => 원문]
 */
function dhl_trace(string $no): array
{
    $no  = trim($no);
    $key = dhl_key();
    if ($key === '') {
        return ['ok' => false, 'error' => 'DHL API 키가 없습니다. 화물추적 맨 위 "배송조회 인증키" 에 넣어 주세요.',
                'events' => [], 'raw' => ''];
    }
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'error' => '서버에 curl 이 없어 DHL 에 연결할 수 없습니다.', 'events' => [], 'raw' => ''];
    }
    $ch = curl_init(DHL_TRACK_URL . '?' . http_build_query(['trackingNumber' => $no]));
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_CONNECTTIMEOUT => 8,
                            CURLOPT_HTTPHEADER => ['DHL-API-Key: ' . $key, 'Accept: application/json']]);
    $raw  = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if (!is_string($raw)) {
        return ['ok' => false, 'error' => 'DHL 서버에 연결하지 못했습니다. 잠시 뒤 다시 해 보세요.', 'events' => [], 'raw' => ''];
    }
    $j = json_decode($raw, true);
    if ($code !== 200) {
        $why = [401 => 'API 키가 틀렸거나 아직 승인 전입니다 (MyApps 에서 Shipment Tracking - Unified 승인 확인)',
                403 => 'API 키에 이 서비스 권한이 없습니다',
                404 => 'DHL 에서 이 번호를 찾지 못했습니다 (번호 확인, 또는 접수 직후라 아직 없음)',
                429 => '조회 한도를 넘었습니다 (5초에 1건, 하루 250건) — 잠시 뒤 다시'][$code]
             ?? ('DHL 오류 ' . $code . (is_array($j) && isset($j['detail']) ? ' — ' . $j['detail'] : ''));
        return ['ok' => false, 'error' => 'DHL: ' . $why, 'events' => [], 'raw' => $raw];
    }
    $events = [];
    foreach (($j['shipments'] ?? []) as $sh) {
        foreach (($sh['events'] ?? []) as $e) {
            $ts = (string)($e['timestamp'] ?? '');
            $t  = $ts !== '' ? strtotime($ts) : false;
            if ($t === false) { continue; }
            $addr = $e['location']['address'] ?? [];
            $loc = trim(($addr['addressLocality'] ?? '') . (isset($addr['countryCode']) ? ' ' . $addr['countryCode'] : ''));
            $desc = trim((string)($e['description'] ?? ''));
            $status = trim((string)($e['status'] ?? '')) ?: ($desc !== '' ? $desc : (string)($e['statusCode'] ?? ''));
            if ($status === '') { continue; }
            $events[] = ['at' => date('Y-m-d H:i:s', $t), 'status' => mb_substr($status, 0, 100),
                         'location' => $loc !== '' ? mb_substr($loc, 0, 100) : null,
                         'description' => $desc !== '' && $desc !== $status ? mb_substr($desc, 0, 255)
                                          : (isset($e['remark']) ? mb_substr((string)$e['remark'], 0, 255) : null),
                         'code' => (string)($e['statusCode'] ?? '')];
        }
    }
    usort($events, fn($a, $b) => strcmp($a['at'], $b['at']));
    return ['ok' => true, 'error' => '', 'events' => $events, 'raw' => $raw];
}
