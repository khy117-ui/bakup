<?php
declare(strict_types=1);

// 웹에서 직접 열면 실행되지 않게 막습니다 (nginx 면 .htaccess 가 무시됩니다)
if (!defined('APP_DIR')) { http_response_code(403); exit('Forbidden'); }

/**
 * 운송사 조회 공통 — ERP 화물추적 화면과 홈페이지 화물추적(track.php)이 같이 씁니다.
 *   번호 · 운송사 이름으로 어디에 물어볼지 정하고(track_detect_src), 가져오고(track_fetch),
 *   ERP 에 등록된 추적번호면 이력을 쌓습니다(track_save_events).
 */

require_once APP_DIR . '/epost.php';
require_once APP_DIR . '/dhl.php';
require_once APP_DIR . '/fedex.php';

const TRACK_SRC_NAME = ['epost' => '우체국 EMS', 'dhl' => 'DHL', 'fedex' => 'FedEx', 'ups' => 'UPS'];
/** 조회 API 가 붙어 있는 곳 (UPS 는 사이트 링크만) */
const TRACK_API_SRC = ['epost', 'dhl', 'fedex'];

/**
 * 어디에 물어볼지 — 운송사 이름을 먼저 보고, 없으면 번호 모양으로.
 *   우체국 EMS : 영문2 + 숫자9 + 영문2 (EG230930844KR)
 *   DHL        : 이름에 DHL, 또는 숫자 10자리
 *   FedEx      : 이름에 FEDEX · FDX · 페덱스, 또는 숫자 12 · 15 · 20 · 22자리
 *   UPS        : 이름에 UPS, 또는 1Z + 16자리
 */
function track_detect_src(string $no, string $carrierText = ''): string
{
    $no = strtoupper(trim($no));
    if (preg_match('/^[A-Z]{2}\d{9}[A-Z]{2}$/', $no)) { return 'epost'; }
    if (stripos($carrierText, 'DHL') !== false)       { return 'dhl'; }
    if (preg_match('/FEDEX|FDX|페덱스/iu', $carrierText)) { return 'fedex'; }
    if (preg_match('/\bUPS\b/i', $carrierText))        { return 'ups'; }
    if (preg_match('/EMS|우체국|POST/iu', $carrierText)) { return 'epost'; }
    if (preg_match('/^\d{10}$/', $no))                 { return 'dhl'; }
    if (fedex_is_no($no))                              { return 'fedex'; }
    if (preg_match('/^1Z[0-9A-Z]{16}$/', $no))         { return 'ups'; }
    return '';
}

/** 운송사 사이트의 조회 화면 주소 */
function track_site_url(string $src, string $no): string
{
    $n = urlencode(trim($no));
    return [
        'epost' => 'https://service.epost.go.kr/trace.RetrieveEmsRigiTraceList.comm?POST_CODE=' . $n . '&displayHeader=N',
        'dhl'   => 'https://www.dhl.com/kr-ko/home/tracking/tracking-express.html?submit=1&tracking-id=' . $n,
        'fedex' => 'https://www.fedex.com/fedextrack/?trknbr=' . $n,
        'ups'   => 'https://www.ups.com/track?loc=ko_KR&tracknum=' . $n,
    ][$src] ?? '';
}

/** 조회. ['ok', 'error', 'events' (오래된 것부터), 'raw'] */
function track_fetch(string $src, string $no): array
{
    switch ($src) {
        case 'epost': return epost_ems_trace($no);
        case 'dhl':   return dhl_trace($no);
        case 'fedex': return fedex_trace($no);
    }
    return ['ok' => false, 'error' => '이 운송사는 자동 조회가 연결되어 있지 않습니다.', 'events' => [], 'raw' => ''];
}

/** 인증키가 들어 있는지 (없으면 조회하지 않고 사이트 링크만) */
function track_has_key(string $src): bool
{
    if ($src === 'epost') { return epost_key() !== ''; }
    if ($src === 'dhl')   { return dhl_key() !== ''; }
    if ($src === 'fedex') { [$a, $b] = fedex_creds(); return $a !== '' && $b !== ''; }
    return false;
}

/** 배달완료 이력인지 — 우체국 '배달완료', DHL statusCode delivered, FedEx eventType DL(→ delivered) */
function track_is_delivered(?array $e): bool
{
    if (!$e) { return false; }
    return ($e['code'] ?? '') === 'delivered'
        || (bool)preg_match('/배달완료|delivered/iu', $e['status'] . ' ' . ($e['description'] ?? ''));
}

/**
 * ERP 추적번호에 이력을 쌓습니다 (같은 일시 · 상태는 건너뜀). [새로 들어간 건수, 배달완료 여부]
 */
function track_save_events(PDO $pdo, int $tid, array $events, ?int $by): array
{
    $pdo->beginTransaction();
    try {
        $ins = $pdo->prepare('INSERT IGNORE INTO tracking_events
                                (tracking_number_id, event_at, location, status, description)
                              VALUES (?,?,?,?,?)');
        $new = 0;
        foreach ($events as $e) {
            $ins->execute([$tid, $e['at'], $e['location'], $e['status'], $e['description']]);
            $new += $ins->rowCount();
        }
        $last = $events ? end($events) : null;
        $done = track_is_delivered($last);
        $pdo->prepare('UPDATE tracking_numbers
                          SET current_status = COALESCE(?, current_status),
                              current_location = COALESCE(?, current_location),
                              delivered_at = CASE WHEN ? = 1 AND delivered_at IS NULL THEN ? ELSE delivered_at END,
                              last_checked_at = NOW(), last_checked_by = ?
                        WHERE id = ?')
            ->execute([$last['status'] ?? null, $last['location'] ?? null, $done ? 1 : 0,
                       $last['at'] ?? null, $by, $tid]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        throw $e;
    }
    return [$new, $done];
}
