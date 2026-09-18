<?php
declare(strict_types=1);

// 웹에서 직접 열면 실행되지 않게 막습니다 (nginx 면 .htaccess 가 무시됩니다)
if (!defined('APP_DIR')) { http_response_code(403); exit('Forbidden'); }

/**
 * 우체국 EMS 행방조회 — 공공데이터포털 "과학기술정보통신부 우정사업본부_EMS행방조회 서비스" (무료, 자동승인, 하루 1만 건)
 *   http://openapi.epost.go.kr/trace/retrieveLongitudinalEMSService/retrieveLongitudinalEMSService/getLongitudinalEMSList
 *   ?serviceKey=인증키&rgist=등기번호(EG230930844KR 같은 13자리)
 *
 * 응답 XML 의 이력 항목 이름이 공개 문서에 자세히 없어서, 이름 패턴으로 찾습니다
 * (처리일시 · 처리현황 · 현재위치 · 상세설명). 원문도 같이 돌려주니, 처음 조회한 결과를 보고 맞춥니다.
 */

const EPOST_EMS_URL = 'http://openapi.epost.go.kr/trace/retrieveLongitudinalEMSService/retrieveLongitudinalEMSService/getLongitudinalEMSList';

/** EMS · 국제등기 번호 모양 (영문2 + 숫자9 + 영문2, 예 EG230930844KR) */
function epost_is_ems_no(string $no): bool
{
    return (bool)preg_match('/^[A-Z]{2}\d{9}[A-Z]{2}$/', strtoupper(trim($no)));
}

/** 환경설정에 넣은 인증키. 포털에서 '인코딩' 키를 복사해 넣었으면 한 번 풀어 둡니다 */
function epost_key(): string
{
    try {
        $st = db()->prepare("SELECT setting_val FROM app_settings WHERE setting_key = 'epost_api_key'");
        $st->execute();
        $k = trim((string)$st->fetchColumn());
    } catch (PDOException $e) {
        $k = '';
    }
    return strpos($k, '%') !== false ? rawurldecode($k) : $k;
}

function epost_http_get(string $url): ?string
{
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15,
                                CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_FOLLOWLOCATION => true]);
        $body = curl_exec($ch);
        curl_close($ch);
        return is_string($body) ? $body : null;
    }
    $ctx = stream_context_create(['http' => ['timeout' => 15]]);
    $body = @file_get_contents($url, false, $ctx);
    return is_string($body) ? $body : null;
}

/** "2023.09.30" + "10:20" 같은 조각을 DATETIME 으로 */
function epost_datetime(string $s): ?string
{
    // "2023.09.30 10:20" · "20230930 1020" · "20230930102033" 모두
    if (!preg_match('/(\d{4})\D?(\d{1,2})\D?(\d{1,2})(?:\D*(\d{1,2})\D?(\d{2})(?:\D?(\d{2}))?)?/', $s, $m)) {
        return null;
    }
    return sprintf('%04d-%02d-%02d %02d:%02d:%02d', $m[1], $m[2], $m[3],
                   (int)($m[4] ?? 0), (int)($m[5] ?? 0), (int)($m[6] ?? 0));
}

/**
 * 조회. 돌려주는 값:
 *   ['ok' => bool, 'error' => string, 'events' => [[at, status, location, description], ...], 'raw' => XML 원문]
 */
function epost_ems_trace(string $no): array
{
    $no  = strtoupper(trim($no));
    $key = epost_key();
    if ($key === '') {
        return ['ok' => false, 'error' => '우체국 Open API 인증키가 없습니다. 시스템 > 환경설정 > 연동 에 넣어 주세요.',
                'events' => [], 'raw' => ''];
    }
    $raw = epost_http_get(EPOST_EMS_URL . '?' . http_build_query(['serviceKey' => $key, 'rgist' => $no]));
    if ($raw === null || trim($raw) === '') {
        return ['ok' => false, 'error' => '우체국 서버에 연결하지 못했습니다. 잠시 뒤 다시 해 보세요.', 'events' => [], 'raw' => ''];
    }
    $prev = libxml_use_internal_errors(true);
    $xml = simplexml_load_string($raw);
    libxml_use_internal_errors($prev);
    if ($xml === false) {
        return ['ok' => false, 'error' => '우체국 응답을 읽지 못했습니다.', 'events' => [], 'raw' => $raw];
    }
    // 성공 여부 — cmmMsgHeader(successYN · errMsg) 또는 header(successYN · errorMessage)
    $flat = [];
    foreach ($xml->xpath('//*[not(*)]') ?: [] as $leaf) {
        $flat[strtolower($leaf->getName())] = trim((string)$leaf);
    }
    if (($flat['successyn'] ?? 'Y') === 'N') {
        $msg = $flat['errmsg'] ?? ($flat['errormessage'] ?? ($flat['returnauthmsg'] ?? '알 수 없는 오류'));
        if (stripos($msg, 'SERVICE_KEY') !== false || stripos($msg, 'KEY') !== false) {
            $msg .= ' — 인증키가 틀렸거나 아직 승인 전일 수 있습니다 (포털에서 활용신청 후 1~2시간)';
        }
        return ['ok' => false, 'error' => '우체국: ' . $msg, 'events' => [], 'raw' => $raw];
    }

    // 이력 한 줄 = 잎(leaf) 자식을 3개 이상 가진 요소 (머리말 cmmMsgHeader/header 는 빼고)
    $events = [];
    foreach ($xml->xpath('//*[count(*) >= 3 and not(*/*)]') ?: [] as $item) {
        $name = strtolower($item->getName());
        if (in_array($name, ['cmmmsgheader', 'header'], true)) { continue; }
        $f = ['date' => '', 'time' => '', 'status' => '', 'loc' => '', 'desc' => ''];
        foreach ($item->children() as $c) {
            $t = strtolower($c->getName());
            $v = trim((string)$c);
            if ($v === '') { continue; }
            if (preg_match('/(sttus|status|state|evnt|event)/', $t))            { $f['status'] = $f['status'] ?: $v; }
            elseif (preg_match('/(detail|dtl|desc|dc$|cn$|rm$|etc)/', $t))     { $f['desc'] = $f['desc'] ?: $v; }
            elseif (preg_match('/(tm$|time|hour|hm$)/', $t))                   { $f['time'] = $f['time'] ?: $v; }
            elseif (preg_match('/(de$|date|dt$|ymd|day)/', $t))                { $f['date'] = $f['date'] ?: $v; }
            elseif (preg_match('/(lc$|loc|po$|nm$|office|place|brff|cntry)/', $t)) { $f['loc'] = $f['loc'] ?: $v; }
        }
        $at = epost_datetime(trim($f['date'] . ' ' . $f['time']));
        if ($at === null || $f['status'] === '') { continue; }
        $events[] = ['at' => $at, 'status' => mb_substr($f['status'], 0, 100),
                     'location' => $f['loc'] !== '' ? mb_substr($f['loc'], 0, 100) : null,
                     'description' => $f['desc'] !== '' ? mb_substr($f['desc'], 0, 255) : null];
    }
    usort($events, fn($a, $b) => strcmp($a['at'], $b['at']));
    return ['ok' => true, 'error' => '', 'events' => $events, 'raw' => $raw];
}
