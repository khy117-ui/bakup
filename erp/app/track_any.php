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

/** 홈페이지 공개 조회 · 자동 추적이 같이 쓰는 캐시 · 호출 기록 표 */
function track_ensure_tables(PDO $pdo): void
{
    static $done = false;
    if ($done) { return; }
    $pdo->exec("CREATE TABLE IF NOT EXISTS public_track_cache (
                  cache_key  VARCHAR(80) NOT NULL PRIMARY KEY,
                  payload    MEDIUMTEXT  NOT NULL,
                  delivered  TINYINT(1)  NOT NULL DEFAULT 0,
                  fetched_at DATETIME    NOT NULL
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='홈페이지 화물추적 캐시'");
    $pdo->exec("CREATE TABLE IF NOT EXISTS public_track_hits (
                  id     BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                  ip     VARCHAR(45) NOT NULL COMMENT '홈페이지 조회자 IP, 자동추적은 erp-auto / erp-tick',
                  src    VARCHAR(10) NULL COMMENT '운송사 실제 호출이면 epost/dhl/fedex',
                  hit_at DATETIME    NOT NULL,
                  KEY ix_ip (ip, hit_at),
                  KEY ix_at (hit_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='화물추적 조회 기록'");
    $done = true;
}

/**
 * 운송사 호출 하루 한도 (홈페이지 + 자동 추적 합계. ERP 화면에서 사람이 누르는 조회는 따로 — 50건쯤 남겨 둠)
 *   DHL 처음 한도 하루 250건 · 우체국 하루 1만 건 · FedEx 는 넉넉함
 */
const TRACK_DAILY_CAP = ['dhl' => 200, 'fedex' => 3000, 'epost' => 3000];

function track_cap_left(PDO $pdo, string $src): bool
{
    $st = $pdo->prepare('SELECT COUNT(*) FROM public_track_hits WHERE src = ? AND hit_at > NOW() - INTERVAL 1 DAY');
    $st->execute([$src]);
    return (int)$st->fetchColumn() < (TRACK_DAILY_CAP[$src] ?? 0);
}

/** 전표의 AWB 가 운송사 운송장번호로 보이면 그 운송사 코드(epost/dhl/fedex/ups), 아니면 '' */
function track_awb_src(array $s): string
{
    $awb = strtoupper((string)preg_replace('/[\s-]+/', '', (string)$s['awb_no']));
    if (!preg_match('/^[A-Z0-9]{8,40}$/', $awb)) { return ''; }
    $src = track_detect_src($awb, trim(($s['carrier_code'] ?? '') . ' ' . ($s['carrier_name'] ?? '')));
    // 자사 채번(HOUSE) 번호가 운송사 번호로 잘못 등록되지 않게 — 번호 모양만으로도 같은 운송사여야 함
    return $src !== '' && ($src === track_detect_src($awb) || ($s['awb_source'] ?? '') === 'CARRIER') ? $src : '';
}

/**
 * 전표 AWB 를 추적번호로 등록합니다 (그 전표에 추적번호가 하나도 없을 때).
 * 수정으로 AWB · 운송사가 바뀌었으면, 예전 AWB 로 자동 등록돼 있던 번호(이력 없는 것)를 새 것으로 바꿉니다.
 * 등록한 추적번호 id, 없으면 0
 */
function track_auto_register(PDO $pdo, int $shipmentId, string $oldAwb = ''): int
{
    $st = $pdo->prepare('SELECT s.id, s.awb_no, s.awb_source, s.carrier_id, s.ship_date, s.voucher_date,
                                ca.code AS carrier_code, ca.name AS carrier_name
                           FROM shipments s JOIN carriers ca ON ca.id = s.carrier_id
                          WHERE s.id = ? AND s.deleted_at IS NULL');
    $st->execute([$shipmentId]);
    $s = $st->fetch();
    if (!$s) { return 0; }
    $awb = strtoupper((string)preg_replace('/[\s-]+/', '', (string)$s['awb_no']));
    if ($oldAwb !== '') {
        $old = strtoupper((string)preg_replace('/[\s-]+/', '', $oldAwb));
        $pdo->prepare('DELETE t FROM tracking_numbers t
                        WHERE t.shipment_id = ? AND t.tracking_no = ? AND (t.tracking_no <> ? OR t.carrier_id <> ?)
                          AND NOT EXISTS (SELECT 1 FROM tracking_events e WHERE e.tracking_number_id = t.id)')
            ->execute([$shipmentId, $old, $awb, (int)$s['carrier_id']]);
    }
    $n = $pdo->prepare('SELECT COUNT(*) FROM tracking_numbers WHERE shipment_id = ?');
    $n->execute([$shipmentId]);
    if ((int)$n->fetchColumn() > 0 || track_awb_src($s) === '') { return 0; }
    $ins = $pdo->prepare('INSERT IGNORE INTO tracking_numbers (shipment_id, carrier_id, tracking_no, accepted_on)
                          VALUES (?,?,?,?)');
    $ins->execute([$shipmentId, (int)$s['carrier_id'], $awb, $s['ship_date'] ?: $s['voucher_date']]);
    return $ins->rowCount() > 0 ? (int)$pdo->lastInsertId() : 0;
}

/**
 * 자동 추적 — ERP 화면이 열려 있으면 브라우저가 가끔 불러 줍니다. 서버 전체에서 5분에 한 번만 일합니다.
 *   1) 최근 30일 전표 중 추적번호가 없고 AWB 가 운송사 번호인 것 → 추적번호로 등록
 *   2) 최근 45일 · 배송완료 전 · 4시간 넘게 안 본 추적번호를 운송사에서 최대 $limit 건 조회해 이력을 쌓음
 *      (DHL 은 5초에 1건 한도라 한 번에 1건만, 하루 한도는 TRACK_DAILY_CAP)
 */
function track_auto_tick(PDO $pdo, int $limit = 3): array
{
    track_ensure_tables($pdo);
    if (!(int)$pdo->query("SELECT GET_LOCK('gp_track_tick', 0)")->fetchColumn()) {
        return ['skipped' => 'busy'];
    }
    try {
        if ((int)$pdo->query("SELECT COUNT(*) FROM public_track_hits
                               WHERE ip = 'erp-tick' AND hit_at > NOW() - INTERVAL 5 MINUTE")->fetchColumn() > 0) {
            return ['skipped' => 'recent'];
        }
        $pdo->exec("INSERT INTO public_track_hits (ip, src, hit_at) VALUES ('erp-tick', NULL, NOW())");

        // 1) 새로 생긴 전표 · 이관 전표의 AWB 등록 (운송사 조회가 되는 것만)
        $reg = 0;
        $rows = $pdo->query("SELECT s.id, s.awb_no, s.awb_source, s.carrier_id, s.ship_date, s.voucher_date,
                                    ca.code AS carrier_code, ca.name AS carrier_name
                               FROM shipments s JOIN carriers ca ON ca.id = s.carrier_id
                              WHERE s.deleted_at IS NULL AND s.status <> 'CANCELLED'
                                AND s.voucher_date >= CURDATE() - INTERVAL 30 DAY
                                AND NOT EXISTS (SELECT 1 FROM tracking_numbers t WHERE t.shipment_id = s.id)
                              ORDER BY s.id DESC LIMIT 2000")->fetchAll();
        $ins = $pdo->prepare('INSERT IGNORE INTO tracking_numbers (shipment_id, carrier_id, tracking_no, accepted_on)
                              VALUES (?,?,?,?)');
        foreach ($rows as $s) {
            if (!in_array(track_awb_src($s), TRACK_API_SRC, true)) { continue; }
            $ins->execute([(int)$s['id'], (int)$s['carrier_id'],
                           strtoupper((string)preg_replace('/[\s-]+/', '', (string)$s['awb_no'])),
                           $s['ship_date'] ?: $s['voucher_date']]);
            $reg += $ins->rowCount();
        }

        // 2) 오래 안 본 것부터 조회
        $rows = $pdo->query("SELECT t.id, t.tracking_no, ca.code, ca.name
                               FROM tracking_numbers t
                               JOIN shipments s ON s.id = t.shipment_id
                               JOIN carriers ca ON ca.id = t.carrier_id
                              WHERE t.delivered_at IS NULL AND s.deleted_at IS NULL AND s.status <> 'CANCELLED'
                                AND s.voucher_date >= CURDATE() - INTERVAL 45 DAY
                                AND (t.last_checked_at IS NULL OR t.last_checked_at < NOW() - INTERVAL 4 HOUR)
                              ORDER BY t.last_checked_at IS NOT NULL, t.last_checked_at, t.id
                              LIMIT 500")->fetchAll();
        $hit = $pdo->prepare("INSERT INTO public_track_hits (ip, src, hit_at) VALUES ('erp-auto', ?, NOW())");
        $mark = $pdo->prepare('UPDATE tracking_numbers SET last_checked_at = NOW() WHERE id = ?');
        $done = 0; $new = 0; $usedSrc = [];
        foreach ($rows as $t) {
            if ($done >= $limit) { break; }
            $src = track_detect_src((string)$t['tracking_no'], $t['code'] . ' ' . $t['name']);
            if (!in_array($src, TRACK_API_SRC, true) || isset($usedSrc['dhl']) && $src === 'dhl') { continue; }
            if (!track_has_key($src) || !track_cap_left($pdo, $src)) { continue; }
            $hit->execute([$src]);
            $usedSrc[$src] = true;
            $done++;
            $r = track_fetch($src, (string)$t['tracking_no']);
            if ($r['ok'] && $r['events']) {
                [$n] = track_save_events($pdo, (int)$t['id'], $r['events'], null);
                $new += $n;
            } else {
                $mark->execute([(int)$t['id']]);   // 아직 운송사에 없거나 오류 — 4시간 뒤 다시
            }
        }
        return ['registered' => $reg, 'checked' => $done, 'new_events' => $new];
    } finally {
        $pdo->query("SELECT RELEASE_LOCK('gp_track_tick')");
    }
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
