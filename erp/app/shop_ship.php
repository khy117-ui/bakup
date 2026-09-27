<?php
declare(strict_types=1);

// 웹에서 직접 열면 실행되지 않게 막습니다 (nginx 면 .htaccess 가 무시됩니다)
if (!defined('APP_DIR')) { http_response_code(403); exit('Forbidden'); }

require_once APP_DIR . '/shop_biz.php';
require_once APP_DIR . '/xlsx_read.php';

/**
 * 송장번호 일괄 등록 — 택배 프로그램에서 받은 엑셀(주문번호 · 송장번호 · 택배사)을 올리면
 * ERP 에 쌓인 쇼핑몰 주문과 맞춰 보고, 쇼핑몰 통합관리 서버(commerce-hub)의 POST /dispatch 로
 * 쿠팡 · 스마트스토어 · 카페24 에 한 번에 발송처리합니다.
 */

/** 택배사 공통 코드 => [화면 이름, 알아듣는 다른 이름들]. 쿠팡 · 스마트스토어가 같은 코드를 씁니다 */
const SHOP_COURIERS = [
    'CJGLS'  => ['CJ대한통운', ['cj', '대한통운', 'cj택배']],
    'HANJIN' => ['한진택배', ['한진']],
    'LOTTE'  => ['롯데택배', ['롯데', '롯데글로벌로지스', '현대택배']],
    'EPOST'  => ['우체국택배', ['우체국']],
    'LOGEN'  => ['로젠택배', ['로젠']],
    'KDEXP'  => ['경동택배', ['경동']],
    'DAESIN' => ['대신택배', ['대신']],
    'ILYANG' => ['일양로지스', ['일양']],
    'CHUNIL' => ['천일택배', ['천일']],
    'HDEXP'  => ['합동택배', ['합동']],
    'CVSNET' => ['GS편의점택배', ['gs', 'gs편의점', 'gs postbox']],
    'CUPOST' => ['CU편의점택배', ['cu', 'cu편의점']],
];

/** 발송 전 상태 (쿠팡 결제완료 · 상품준비중, 스마트스토어 결제완료, 카페24 상품준비중 · 배송준비중 · 배송대기 · 배송보류) */
const SHOP_WAITING_STATUS = ['ACCEPT', 'INSTRUCT', 'PAYED', 'N10', 'N20', 'N21', 'N22'];

function shop_courier_code(string $name): ?string
{
    $n = mb_strtolower((string)preg_replace('/\s+/u', '', $name));
    if ($n === '') { return null; }
    foreach (SHOP_COURIERS as $code => [$label, $aliases]) {
        if ($n === mb_strtolower($code) || $n === mb_strtolower((string)preg_replace('/\s+/u', '', $label))) { return $code; }
        foreach ($aliases as $a) {
            if ($n === (string)preg_replace('/\s+/u', '', $a)) { return $code; }
        }
    }
    return null;
}

function shop_courier_label(string $code): string
{
    return SHOP_COURIERS[$code][0] ?? $code;
}

function shop_ship_ensure_schema(): void
{
    shop_biz_ensure_schema();
    if (!empty($_SESSION['schema_shop_ship_v1'])) { return; }
    try {
        db()->exec("INSERT IGNORE INTO app_settings
                      (setting_key, setting_val, group_ko, label_ko, help_ko, input_type, options_csv, sort_order)
                    VALUES ('shop_default_courier', 'CJ대한통운', '쇼핑몰', '기본 택배사',
                            '송장 엑셀에 택배사 칸이 없을 때 씁니다', 'select', '" . implode(',', array_column(SHOP_COURIERS, 0)) . "', 40)");
        $_SESSION['schema_shop_ship_v1'] = 1;
    } catch (PDOException $e) {
        error_log('쇼핑몰 송장 설정 준비 실패: ' . $e->getMessage());
    }
}

/** 올린 파일 · 붙여넣은 글 → [[판매처, 주문번호, 택배사, 송장번호], ...] */
function shop_ship_parse(string $bin, string $fileName, string $pasted): array
{
    if ($bin !== '') {
        if (str_starts_with($bin, "PK\x03\x04")) {
            $rows = xlsx_read_rows($bin);
        } elseif (preg_match('/\.xls$/i', $fileName) || str_starts_with($bin, "\xD0\xCF\x11\xE0")) {
            throw new RuntimeException('예전 엑셀(.xls) 파일은 못 읽습니다. 엑셀에서 "다른 이름으로 저장 → Excel 통합 문서(.xlsx)" 로 저장해 올리세요.');
        } else {
            $rows = shop_ship_text_rows($bin, true);
        }
    } else {
        $rows = shop_ship_text_rows($pasted, false);
    }
    return shop_ship_columns($rows);
}

/** CSV · 엑셀에서 복사한 글(탭) → 줄 × 칸 */
function shop_ship_text_rows(string $text, bool $csv): array
{
    $text = preg_replace('/^\xEF\xBB\xBF/', '', $text) ?? $text;
    if (!mb_check_encoding($text, 'UTF-8')) {
        $text = (string)mb_convert_encoding($text, 'UTF-8', 'CP949');   // 엑셀에서 저장한 CSV
    }
    $first = strtok($text, "\n") ?: '';
    $delim = substr_count($first, "\t") >= max(1, substr_count($first, ',')) ? "\t" : ',';
    $rows = [];
    $fh = fopen('php://temp', 'r+');
    fwrite($fh, $text);
    rewind($fh);
    while (($r = fgetcsv($fh, null, $delim, '"', '')) !== false) {
        if (count($rows) >= 20000) { break; }
        // 붙여넣기에서 칸이 하나뿐이면 공백으로 나눔 (주문번호 송장번호)
        if (!$csv && count($r) === 1) { $r = preg_split('/\s+/u', trim((string)$r[0])) ?: []; }
        $rows[] = array_map(fn($v) => trim((string)$v), $r);
    }
    fclose($fh);
    return $rows;
}

/** 머리글을 찾아 판매처 · 주문번호 · 택배사 · 송장번호 칸만 뽑음. 머리글이 없으면 [주문번호, 송장번호, 택배사] 순서로 봄 */
function shop_ship_columns(array $rows): array
{
    $names = [
        'channel' => ['판매처', '채널', '쇼핑몰', '판매채널', '마켓'],
        'order'   => ['주문번호', '상품주문번호', '주문id', 'orderid', 'order_id', '주문코드', '품목별주문번호', '고객주문번호'],
        'courier' => ['택배사', '배송사', '택배회사', '배송업체', '택배', '택배사명'],
        'track'   => ['송장번호', '운송장번호', '송장', '운송장', 'invoice', 'tracking', 'tracking_no', 'trackingno', '등기번호'],
    ];
    $idx = null;
    $start = 0;
    foreach (array_slice($rows, 0, 10, true) as $i => $r) {
        $found = [];
        foreach ($r as $c => $h) {
            $h = mb_strtolower((string)preg_replace('/[\s()]+/u', '', (string)$h));
            foreach ($names as $k => $list) {
                if (!isset($found[$k]) && in_array($h, $list, true)) { $found[$k] = $c; }
            }
        }
        if (isset($found['order'], $found['track'])) { $idx = $found; $start = $i + 1; break; }
    }
    if ($idx === null) {
        $idx = ['order' => 0, 'track' => 1, 'courier' => 2];
    }
    $out = [];
    foreach (array_slice($rows, $start) as $r) {
        $order = trim((string)($r[$idx['order']] ?? ''));
        $track = (string)preg_replace('/[^0-9A-Za-z]/', '', (string)($r[$idx['track']] ?? ''));   // 6401-2345-6789 → 숫자만
        if ($order === '' && $track === '') { continue; }
        $out[] = [
            isset($idx['channel']) ? shop_channel_code((string)($r[$idx['channel']] ?? '')) : '',
            $order, isset($idx['courier']) ? trim((string)($r[$idx['courier']] ?? '')) : '', $track,
        ];
    }
    if (!$out) { throw new RuntimeException('주문번호 · 송장번호가 있는 줄을 찾지 못했습니다. 첫 줄에 "주문번호", "송장번호" 머리글을 넣어 주세요.'); }
    return $out;
}

/**
 * ERP 주문과 맞춰 봅니다. 줄마다 state:
 *   ok 보낼 수 있음 / none 주문 없음 / courier 택배사 모름 / blank 송장번호 없음 / same 이미 같은 송장 / dup 파일 안에서 중복 / noline 주문을 다시 가져와야 함
 */
function shop_ship_match(array $rows, string $defaultCourier): array
{
    $pdo = db();
    $st = $pdo->prepare("SELECT id, channel, order_id, line_id, ship_id, product, qty, status, courier, tracking_no
                           FROM shop_orders
                          WHERE (order_id = ? OR line_id = ?) AND NOT " . shop_cancel_sql() . "
                          ORDER BY id");
    $seen = [];
    $out = [];
    foreach ($rows as $n => [$ch, $order, $courier, $track]) {
        $courier = $courier !== '' ? $courier : $defaultCourier;
        $code = shop_courier_code($courier);
        $st->execute([$order, $order]);
        $lines = array_values(array_filter($st->fetchAll(), fn($l) => $ch === '' || $l['channel'] === $ch));
        $e = ['n' => $n + 1, 'channel' => $lines[0]['channel'] ?? $ch, 'order_id' => $order, 'courier' => $code ? shop_courier_label($code) : $courier,
              'code' => $code, 'tracking_no' => $track, 'lines' => $lines, 'state' => 'ok', 'msg' => ''];
        $key = $order . '|' . $track;
        if ($track === '') {
            $e['state'] = 'blank'; $e['msg'] = '송장번호가 비었습니다';
        } elseif (!$lines) {
            $e['state'] = 'none'; $e['msg'] = 'ERP 에 없는 주문입니다 (주문 · 매출 에서 먼저 가져오세요)';
        } elseif ($code === null) {
            $e['state'] = 'courier'; $e['msg'] = "모르는 택배사: {$courier}";
        } elseif (isset($seen[$key])) {
            $e['state'] = 'dup'; $e['msg'] = "{$seen[$key]}번째 줄과 같습니다";
        } elseif (!array_filter($lines, fn($l) => $l['tracking_no'] !== $track)) {
            $e['state'] = 'same'; $e['msg'] = '이미 이 송장으로 등록했습니다';
        } elseif (array_filter($lines, fn($l) => ($l['line_id'] ?? '') === '')) {
            $e['state'] = 'noline'; $e['msg'] = '예전에 가져온 주문이라 상품주문 번호가 없습니다. 주문 · 매출 에서 다시 가져오세요';
        } elseif (array_filter($lines, fn($l) => ($l['tracking_no'] ?? '') !== '')) {
            $e['msg'] = '다른 송장이 이미 등록돼 있습니다. 판매처에서 거절될 수 있습니다';
        }
        $seen[$key] ??= $n + 1;
        $out[] = $e;
    }
    return $out;
}

/** 확인한 줄을 판매처에 보냅니다. [보낸 건, 성공, 실패, 결과 줄들] */
function shop_ship_send(array $entries): array
{
    $items = [];
    $lineIds = [];
    foreach ($entries as $e) {
        if ($e['state'] !== 'ok') { continue; }
        foreach ($e['lines'] as $l) {
            $k = $l['channel'] . '|' . $l['order_id'] . '|' . ($l['ship_id'] ?? '');
            $items[$k] ??= ['channel' => $l['channel'], 'orderId' => $l['order_id'], 'lineIds' => [], 'shipId' => $l['ship_id'],
                            'courier' => $e['code'], 'trackingNo' => $e['tracking_no']];
            if (!in_array($l['line_id'], $items[$k]['lineIds'], true)) { $items[$k]['lineIds'][] = $l['line_id']; }
            $lineIds[$k][] = (int)$l['id'];
        }
    }
    if (!$items) { throw new RuntimeException('보낼 수 있는 줄이 없습니다.'); }
    $results = [];
    foreach (array_chunk($items, 500, true) as $part) {
        $r = shop_api_request('POST', '/dispatch', [], ['items' => array_values($part)]);
        $got = [];
        foreach ($r['results'] ?? [] as $x) { $got[$x['channel'] . '|' . $x['orderId']][] = $x; }
        foreach ($part as $k => $it) {
            // 같은 주문의 묶음배송이 여러 개면 결과도 주문번호로 여러 개 — 순서대로 짝지음
            $x = array_shift($got[$it['channel'] . '|' . $it['orderId']]) ?? ['ok' => false, 'message' => '서버가 결과를 주지 않았습니다'];
            $results[$k] = ['channel' => $it['channel'], 'order_id' => $it['orderId'], 'courier' => shop_courier_label($it['courier']),
                            'tracking_no' => $it['trackingNo'], 'ok' => !empty($x['ok']), 'message' => mb_substr((string)($x['message'] ?? ''), 0, 300)];
        }
    }

    $pdo = db();
    $batch = substr(bin2hex(random_bytes(8)), 0, 16);
    $now = date('Y-m-d H:i:s');
    $uid = $_SESSION['admin_id'] ?? null;
    $log = $pdo->prepare('INSERT INTO shop_dispatch_log (batch, channel, order_id, courier, tracking_no, ok, message, sent_by, sent_at)
                          VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $upd = $pdo->prepare('UPDATE shop_orders SET courier = ?, tracking_no = ?, dispatched_at = ? WHERE id = ?');
    $ok = 0;
    foreach ($results as $k => $x) {
        $log->execute([$batch, $x['channel'], $x['order_id'], $x['courier'], $x['tracking_no'], $x['ok'] ? 1 : 0, $x['message'], $uid, $now]);
        if ($x['ok']) {
            $ok++;
            foreach ($lineIds[$k] as $id) { $upd->execute([$x['courier'], $x['tracking_no'], $now, $id]); }
        }
    }
    return [count($results), $ok, count($results) - $ok, array_values($results)];
}

/** 송장 기다리는 주문 (최근 N일, 취소 · 송장 등록 뺌) — 주문 하나에 한 줄 */
function shop_ship_waiting(int $days = 14): array
{
    $in = implode(',', array_fill(0, count(SHOP_WAITING_STATUS), '?'));
    $st = db()->prepare("SELECT channel, order_id, MIN(ordered_at) AS ordered_at,
                                GROUP_CONCAT(CONCAT(product, ' × ', qty) ORDER BY id SEPARATOR ' / ') AS products, SUM(qty) AS qty
                           FROM shop_orders
                          WHERE ordered_at >= ? AND tracking_no IS NULL AND status IN ($in) AND NOT " . shop_cancel_sql() . "
                          GROUP BY channel, order_id
                          ORDER BY MIN(ordered_at), channel, order_id");
    $st->execute(array_merge([date('Y-m-d 00:00:00', strtotime("-{$days} days"))], SHOP_WAITING_STATUS));
    return $st->fetchAll();
}
