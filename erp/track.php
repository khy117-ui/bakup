<?php
/**
 * 홈페이지 화물추적 — 공개 조회 입구 (로그인 없음, 읽기 전용 JSON)
 *
 *   GET /erp/track.php?no=871350927454&carrier=auto|DHL|FEDEX|EMS|UPS
 *
 *   1) ERP 에 등록된 추적번호 · 자사 AWB 이면 그 운송사로 조회합니다 (고객은 AWB 만 알아도 됨)
 *   2) 아니면 고른 운송사 · 번호 모양으로 조회합니다
 *   3) 우체국 EMS · DHL · FedEx 는 ERP 에 넣은 인증키로 운송사에 물어보고, UPS · 키 없음은 운송사 사이트 링크만
 *
 *   거래처명 · 금액 같은 내부 정보는 내보내지 않습니다 (출발/도착 국가 · 발송일 · 이력만).
 *   운송사 한도(DHL 하루 250건 등)를 지키려고 같은 번호는 20분 캐시, IP 당 10분 20건 · 하루 100건,
 *   운송사 실제 호출은 TRACK_DAILY_CAP(자동 추적과 합계)까지만 합니다.
 */
declare(strict_types=1);

define('GP_NO_SESSION', true);
require __DIR__ . '/app/bootstrap.php';
require_once APP_DIR . '/track_any.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');

function tp_out(array $data, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    tp_out(['ok' => false, 'error' => '잘못된 요청입니다.'], 405);
}

$q = strtoupper((string)preg_replace('/[\s\-]+/', '', (string)($_GET['no'] ?? '')));
$sel = strtoupper(trim((string)($_GET['carrier'] ?? 'AUTO')));
$selText = ['DHL' => 'DHL', 'FEDEX' => 'FEDEX', 'EMS' => 'EMS', 'UPS' => 'UPS'][$sel] ?? '';
if (!preg_match('/^[A-Z0-9]{6,40}$/', $q)) {
    tp_out(['ok' => false, 'error' => '운송장 번호를 확인해 주세요. (영문 · 숫자 6~40자)'], 400);
}

try {
    $pdo = db();
    track_ensure_tables($pdo);
} catch (PDOException $e) {
    error_log('track.php DB: ' . $e->getMessage());
    tp_out(['ok' => false, 'error' => '잠시 후 다시 조회해 주세요.'], 503);
}

// ---- 조회 한도 (IP)
$xff = trim(explode(',', (string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''))[0]);
$ip  = substr((string)($_SERVER['HTTP_X_REAL_IP'] ?? '') ?: ($xff ?: (string)($_SERVER['REMOTE_ADDR'] ?? '')), 0, 45);
$cnt = $pdo->prepare('SELECT SUM(hit_at > NOW() - INTERVAL 10 MINUTE), COUNT(*) FROM public_track_hits
                       WHERE ip = ? AND hit_at > NOW() - INTERVAL 1 DAY');
$cnt->execute([$ip]);
[$n10, $nDay] = array_map('intval', $cnt->fetch(PDO::FETCH_NUM) ?: [0, 0]);
if ($n10 >= 20 || $nDay >= 100) {
    tp_out(['ok' => false, 'error' => '조회가 너무 많습니다. 잠시 후 다시 시도해 주세요.'], 429);
}
$hit = $pdo->prepare('INSERT INTO public_track_hits (ip, src, hit_at) VALUES (?, ?, NOW())');
$hit->execute([$ip, null]);
if (random_int(1, 200) === 1) {   // 가끔 오래된 기록 정리
    $pdo->exec('DELETE FROM public_track_hits WHERE hit_at < NOW() - INTERVAL 7 DAY');
    $pdo->exec('DELETE FROM public_track_cache WHERE fetched_at < NOW() - INTERVAL 7 DAY');
}

// ---- 무엇을 조회할지: ERP 추적번호 → 자사 AWB → 입력 그대로
$targets = [];     // [tid|null, no, carrierText]
$route = null;
$shipSel = 'SELECT s.id, s.awb_no, s.ship_date, s.voucher_date, s.origin_country, s.dest_country, s.dest_city,
                   s.package_count, ca.code AS carrier, ca.name AS carrier_name
              FROM shipments s JOIN carriers ca ON ca.id = s.carrier_id
             WHERE s.deleted_at IS NULL';
$st = $pdo->prepare('SELECT t.id, t.tracking_no, t.shipment_id, ca.code, ca.name
                       FROM tracking_numbers t
                       JOIN carriers ca ON ca.id = t.carrier_id
                       JOIN shipments s ON s.id = t.shipment_id AND s.deleted_at IS NULL
                      WHERE t.tracking_no = ? LIMIT 3');
$st->execute([$q]);
$rows = $st->fetchAll();
$shipId = $rows ? (int)$rows[0]['shipment_id'] : 0;
if (!$rows) {
    $st = $pdo->prepare($shipSel . ' AND s.awb_no = ? ORDER BY s.id DESC LIMIT 1');
    $st->execute([$q]);
    $s = $st->fetch();
    if ($s) {
        $shipId = (int)$s['id'];
        $st = $pdo->prepare('SELECT t.id, t.tracking_no, t.shipment_id, ca.code, ca.name FROM tracking_numbers t
                               JOIN carriers ca ON ca.id = t.carrier_id WHERE t.shipment_id = ? ORDER BY t.id LIMIT 3');
        $st->execute([$shipId]);
        $rows = $st->fetchAll();
        if (!$rows) { $targets[] = [null, $q, $s['carrier'] . ' ' . $s['carrier_name']]; }
    }
}
foreach ($rows as $r) {
    $targets[] = [(int)$r['id'], (string)$r['tracking_no'], $r['code'] . ' ' . $r['name']];
}
if ($shipId > 0) {
    $st = $pdo->prepare($shipSel . ' AND s.id = ?');
    $st->execute([$shipId]);
    $s = $st->fetch();
    if ($s) {
        $route = ['from' => $s['origin_country'] ?: 'KR', 'to' => $s['dest_country'] ?: '',
                  'to_city' => (string)($s['dest_city'] ?? ''), 'ship_date' => $s['ship_date'] ?: $s['voucher_date'],
                  'pcs' => $s['package_count'] !== null ? (int)$s['package_count'] : null];
    }
}
if (!$targets) {
    $targets[] = [null, $q, $selText];
}

/** 이력으로 6단계 진행 위치 (1 픽업의뢰 · 2 입고 · 3 운송 · 4 통관 · 5 내륙운송 · 6 인도) */
function tp_stage(array $events, bool $delivered): int
{
    if ($delivered) { return 6; }
    if (!$events) { return 1; }
    $stage = 2;
    foreach ($events as $e) {
        $t = $e['status'] . ' ' . ($e['description'] ?? '');
        if (preg_match('/out for delivery|with delivery courier|on .*vehicle for delivery|배달준비|배달중|배송출발/iu', $t)) {
            $stage = max($stage, 5);
        } elseif (preg_match('/customs|clearance|통관|세관/iu', $t)) {
            $stage = max($stage, 4);
        } elseif (preg_match('/transit|depart|arriv|processed|forwarded|출발|도착|발송|교환국|항공기|운송/iu', $t)) {
            $stage = max($stage, 3);
        }
    }
    return $stage;
}

$results = [];
foreach ($targets as [$tid, $no, $ctext]) {
    $src = track_detect_src($no, $ctext);
    $item = ['carrier' => TRACK_SRC_NAME[$src] ?? (trim($ctext) ?: '운송사 미확인'), 'no' => $no,
             'site_url' => track_site_url($src, $no), 'events' => [], 'delivered' => false,
             'source' => '', 'message' => ''];
    $events = null;

    // ERP 자동 추적이 최근 20분 안에 봤거나 이미 배송완료면, 운송사에 다시 묻지 않고 ERP 이력을 씁니다
    $fresh = false;
    if ($tid) {
        $f = $pdo->prepare('SELECT delivered_at IS NOT NULL OR last_checked_at > NOW() - INTERVAL 20 MINUTE
                              FROM tracking_numbers WHERE id = ?');
        $f->execute([$tid]);
        $fresh = (bool)(int)$f->fetchColumn();
    }

    if (!$fresh && in_array($src, TRACK_API_SRC, true) && track_has_key($src)) {
        $ck = $src . ':' . $no;
        $c = $pdo->prepare('SELECT payload FROM public_track_cache WHERE cache_key = ?
                             AND fetched_at > NOW() - INTERVAL IF(delivered = 1, 720, 20) MINUTE');
        $c->execute([$ck]);
        $cached = $c->fetchColumn();
        if ($cached !== false) {
            $events = json_decode((string)$cached, true) ?: [];
            $item['source'] = 'cache';
        } else {
            if (!track_cap_left($pdo, $src)) {
                $item['message'] = '오늘 자동 조회 한도에 도달했습니다. 운송사 사이트에서 확인해 주세요.';
            } else {
                $hit->execute([$ip, $src]);
                $r = track_fetch($src, $no);
                if ($r['ok']) {
                    $events = $r['events'];
                    $item['source'] = 'live';
                    $done = track_is_delivered($events ? end($events) : null);
                    $pdo->prepare('REPLACE INTO public_track_cache (cache_key, payload, delivered, fetched_at) VALUES (?,?,?,NOW())')
                        ->execute([$ck, json_encode($events, JSON_UNESCAPED_UNICODE), $done ? 1 : 0]);
                    if ($tid) {
                        try { track_save_events($pdo, $tid, $events, null); }
                        catch (Throwable $e) { error_log('track.php 저장: ' . $e->getMessage()); }
                    }
                } else {
                    // 키 오류 같은 내부 사정은 감추고, 번호 없음만 알려 줍니다
                    error_log('track.php ' . $src . ' ' . $no . ': ' . $r['error']);
                    $item['message'] = preg_match('/찾지 못|NOTFOUND|not found/iu', $r['error'])
                        ? '운송사에 아직 조회되지 않는 번호입니다. 접수 직후라면 몇 시간 뒤 다시 확인해 주세요.'
                        : '지금은 운송사 조회가 원활하지 않습니다. 운송사 사이트에서 확인해 주세요.';
                }
            }
        }
    }
    // 운송사에서 못 받았으면 ERP 에 쌓인 이력으로
    if ($events === null && $tid) {
        $e = $pdo->prepare('SELECT event_at AS at, status, location, description FROM tracking_events
                             WHERE tracking_number_id = ? ORDER BY event_at');
        $e->execute([$tid]);
        $rowsE = $e->fetchAll();
        if ($rowsE) { $events = $rowsE; $item['source'] = 'erp'; }
    }
    if ($events === null && $item['message'] === '') {
        $item['message'] = $src === '' ? '운송사를 선택하고 다시 조회해 주세요.'
                         : ($fresh && in_array($src, TRACK_API_SRC, true)
                            ? '운송사에 아직 조회되지 않는 번호입니다. 접수 직후라면 몇 시간 뒤 다시 확인해 주세요.'
                            : '자동 조회가 연결되지 않은 운송사입니다. 운송사 사이트에서 확인해 주세요.');
    }
    $events = $events ?? [];
    $item['delivered'] = track_is_delivered($events ? end($events) : null);
    $item['stage'] = tp_stage($events, $item['delivered']);
    $item['events'] = array_reverse(array_map(fn($e) => ['at' => (string)$e['at'], 'status' => (string)$e['status'],
                                                         'location' => (string)($e['location'] ?? ''),
                                                         'description' => (string)($e['description'] ?? '')], $events));
    $results[] = $item;
}

tp_out(['ok' => true, 'no' => $q, 'route' => $route, 'results' => $results]);
