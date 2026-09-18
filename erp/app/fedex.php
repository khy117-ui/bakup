<?php
declare(strict_types=1);

// 웹에서 직접 열면 실행되지 않게 막습니다 (nginx 면 .htaccess 가 무시됩니다)
if (!defined('APP_DIR')) { http_response_code(403); exit('Forbidden'); }

/**
 * FedEx 배송조회 — FedEx Developer Portal 의 "Track API" (무료)
 *   1) POST https://apis.fedex.com/oauth/token  (grant_type=client_credentials, client_id=API Key, client_secret=Secret Key)
 *      → access_token (1시간). 세션에 들고 있다가 만료 전까지 다시 씁니다.
 *   2) POST https://apis.fedex.com/track/v1/trackingnumbers  (Bearer 토큰, JSON)
 * 응답 JSON: output.completeTrackResults[].trackResults[] = { scanEvents[] = { date, eventType, eventDescription,
 *            exceptionDescription, scanLocation{city, stateOrProvinceCode, countryCode} }, latestStatusDetail, error }
 * 샌드박스(apis-sandbox) 키는 가짜 데이터만 돌려주므로, 포털에서 Production 키를 받아야 실제 번호가 조회됩니다.
 */

const FEDEX_BASE = 'https://apis.fedex.com';

/** FedEx 번호 모양 — Express 12자리 · Ground 15자리 · SmartPost 20/22자리 */
function fedex_is_no(string $no): bool
{
    return (bool)preg_match('/^(\d{12}|\d{15}|\d{20}|\d{22})$/', trim($no));
}

function fedex_setting(string $key): string
{
    try {
        $st = db()->prepare('SELECT setting_val FROM app_settings WHERE setting_key = ?');
        $st->execute([$key]);
        $v = (string)$st->fetchColumn();
    } catch (PDOException $e) {
        $v = '';
    }
    return (string)preg_replace('/\s+/u', '', $v);
}

/** [API Key, Secret Key] */
function fedex_creds(): array
{
    return [fedex_setting('fedex_api_key'), fedex_setting('fedex_secret_key')];
}

function fedex_save_key(string $which, string $val): void
{
    $val = (string)preg_replace('/\s+/u', '', $val);
    [$key, $label, $sort] = $which === 'secret'
        ? ['fedex_secret_key', 'FedEx Secret Key', 4]
        : ['fedex_api_key', 'FedEx API Key (Client ID)', 3];
    db()->prepare("INSERT INTO app_settings
                     (setting_key, setting_val, group_ko, label_ko, help_ko, input_type, sort_order, updated_by)
                   VALUES (?, ?, '연동', ?, 'developer.fedex.com 프로젝트(Track API)의 Production 키. 화물추적에서 씁니다.',
                           'secret', ?, ?)
                   ON DUPLICATE KEY UPDATE setting_val = VALUES(setting_val), updated_by = VALUES(updated_by),
                                           input_type = 'secret'")
        ->execute([$key, $val, $label, $sort, $_SESSION['admin_id'] ?? null]);
    unset($_SESSION['fedex_token']);   // 키가 바뀌면 받아 둔 토큰은 버립니다
}

/** HTTP 호출. [코드, 본문] */
function fedex_http(string $url, array $headers, string $body): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_CONNECTTIMEOUT => 8,
                            CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_HTTPHEADER => $headers]);
    $raw  = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, is_string($raw) ? $raw : null];
}

/** FedEx 오류 응답에서 사람이 읽을 말 */
function fedex_err_msg(?string $raw): string
{
    $j = is_string($raw) ? json_decode($raw, true) : null;
    $e = $j['errors'][0] ?? null;
    return is_array($e) ? trim(($e['code'] ?? '') . ' ' . ($e['message'] ?? '')) : '';
}

/** 접근 토큰 (세션에 55분 보관). 실패하면 ['', 오류문, 원문] */
function fedex_token(bool $fresh = false): array
{
    $t = $_SESSION['fedex_token'] ?? null;
    if (!$fresh && is_array($t) && ($t['exp'] ?? 0) > time()) { return [$t['tok'], '', '']; }
    [$id, $secret] = fedex_creds();
    [$code, $raw] = fedex_http(FEDEX_BASE . '/oauth/token', ['Content-Type: application/x-www-form-urlencoded'],
                               http_build_query(['grant_type' => 'client_credentials',
                                                 'client_id' => $id, 'client_secret' => $secret]));
    if ($raw === null) { return ['', 'FedEx 서버에 연결하지 못했습니다. 잠시 뒤 다시 해 보세요.', '']; }
    $j = json_decode($raw, true);
    if ($code !== 200 || empty($j['access_token'])) {
        $m = fedex_err_msg($raw);
        return ['', 'FedEx 로그인 실패 (' . $code . ') — API Key · Secret Key 가 틀렸거나, Production 키가 아닙니다'
                    . ($m !== '' ? ' [' . $m . ']' : ''), $raw];
    }
    $_SESSION['fedex_token'] = ['tok' => (string)$j['access_token'],
                                'exp' => time() + max(60, (int)($j['expires_in'] ?? 3600) - 300)];
    return [(string)$j['access_token'], '', ''];
}

/**
 * 조회. 돌려주는 값: ['ok' => bool, 'error' => string, 'events' => [[at, status, location, description, code]], 'raw' => 원문]
 */
function fedex_trace(string $no): array
{
    $no = trim($no);
    [$id, $secret] = fedex_creds();
    if ($id === '' || $secret === '') {
        return ['ok' => false, 'error' => 'FedEx API Key 와 Secret Key 가 둘 다 있어야 합니다. 화물추적 맨 위 "배송조회 인증키" 에 넣어 주세요.',
                'events' => [], 'raw' => ''];
    }
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'error' => '서버에 curl 이 없어 FedEx 에 연결할 수 없습니다.', 'events' => [], 'raw' => ''];
    }
    $body = json_encode(['includeDetailedScans' => true,
                         'trackingInfo' => [['trackingNumberInfo' => ['trackingNumber' => $no]]]]);
    $code = 0; $raw = null;
    foreach ([false, true] as $fresh) {           // 토큰이 만료됐으면(401) 새로 받아 한 번 더
        [$tok, $terr, $traw] = fedex_token($fresh);
        if ($tok === '') { return ['ok' => false, 'error' => $terr, 'events' => [], 'raw' => $traw]; }
        [$code, $raw] = fedex_http(FEDEX_BASE . '/track/v1/trackingnumbers',
                                   ['Content-Type: application/json', 'Authorization: Bearer ' . $tok, 'X-locale: en_US'],
                                   (string)$body);
        if ($code !== 401) { break; }
        unset($_SESSION['fedex_token']);
    }
    if ($raw === null) {
        return ['ok' => false, 'error' => 'FedEx 서버에 연결하지 못했습니다. 잠시 뒤 다시 해 보세요.', 'events' => [], 'raw' => ''];
    }
    if ($code !== 200) {
        $why = [401 => '인증이 안 됩니다 (키 확인)', 403 => '이 키에 Track API 권한이 없습니다 (포털 프로젝트에서 Track API 선택 확인)',
                429 => '조회 한도를 넘었습니다 — 잠시 뒤 다시'][$code] ?? ('오류 ' . $code);
        $m = fedex_err_msg($raw);
        return ['ok' => false, 'error' => 'FedEx: ' . $why . ($m !== '' ? ' [' . $m . ']' : ''), 'events' => [], 'raw' => $raw];
    }
    $j = json_decode($raw, true);
    $events = [];
    $notFound = '';
    foreach (($j['output']['completeTrackResults'] ?? []) as $ct) {
        foreach (($ct['trackResults'] ?? []) as $tr) {
            if (!empty($tr['error'])) {
                $notFound = trim((string)($tr['error']['message'] ?? $tr['error']['code'] ?? ''));
                continue;
            }
            foreach (($tr['scanEvents'] ?? []) as $e) {
                $t = isset($e['date']) ? strtotime((string)$e['date']) : false;
                if ($t === false) { continue; }
                $sl  = $e['scanLocation'] ?? [];
                $loc = trim(implode(' ', array_filter([$sl['city'] ?? '', $sl['stateOrProvinceCode'] ?? '',
                                                       $sl['countryCode'] ?? ''], fn($x) => $x !== '')));
                $status = trim((string)($e['eventDescription'] ?? $e['derivedStatus'] ?? ''));
                if ($status === '') { continue; }
                $desc = trim((string)($e['exceptionDescription'] ?? ''));
                $type = (string)($e['eventType'] ?? '');
                $events[] = ['at' => date('Y-m-d H:i:s', $t), 'status' => mb_substr($status, 0, 100),
                             'location' => $loc !== '' ? mb_substr($loc, 0, 100) : null,
                             'description' => $desc !== '' ? mb_substr($desc, 0, 255) : null,
                             'code' => $type === 'DL' ? 'delivered' : $type];
            }
        }
    }
    if (!$events && $notFound !== '') {
        return ['ok' => false, 'error' => 'FedEx: 이 번호를 찾지 못했습니다 (' . $notFound . ')', 'events' => [], 'raw' => $raw];
    }
    usort($events, fn($a, $b) => strcmp($a['at'], $b['at']));
    return ['ok' => true, 'error' => '', 'events' => $events, 'raw' => $raw];
}
