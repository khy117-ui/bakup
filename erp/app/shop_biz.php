<?php
declare(strict_types=1);

// 웹에서 직접 열면 실행되지 않게 막습니다 (nginx 면 .htaccess 가 무시됩니다)
if (!defined('APP_DIR')) { http_response_code(403); exit('Forbidden'); }

require_once APP_DIR . '/shop.php';
require_once APP_DIR . '/shop_ads.php';

/**
 * 쇼핑몰 상품 · 재고 · 순이익 · 자동 가져오기 · 알림.
 *
 *   재고   : 담당자가 [재고 입력] 한 수량(stock_base)에서, 입력한 뒤 들어온 주문 수량(취소 · 반품 제외)을 뺀 값.
 *            주문을 다시 가져와도 두 번 빠지지 않도록 저장된 값을 깎지 않고 그때그때 계산합니다.
 *   순이익 : 매출 − 원가×수량 − 판매 수수료(판매처별 %) − 배송비×주문 줄 − 광고비(판매처 광고비를 매출 비중으로 나눔).
 *            광고비는 [광고 · 키워드] 에 올린 보고서 중 기간이 겹치는 것을 날짜 비율로 가져옵니다 (추정치).
 *   자동   : 로그인한 ERP 화면이 부르는 자동 작업 신호(track_tick)에 얹어, 1시간에 한 번 최근 2일 주문을 가져오고
 *            하루 한 번 매출 급감 · ROAS 미달 · 품절 임박을 담당자 메일 · 카톡으로 알립니다.
 */

/** 취소 · 반품 줄 — 채널마다 상태 문구가 달라 글자로 봅니다 (카페24: C** 취소, R** 반품) */
function shop_cancel_sql(string $a = ''): string
{
    $p = $a !== '' ? $a . '.' : '';
    return "({$p}status LIKE '%CANCEL%' OR {$p}status LIKE '%RETURN%' OR {$p}status LIKE '%취소%' OR {$p}status LIKE '%반품%'
             OR ({$p}channel = 'cafe24' AND ({$p}status LIKE 'C%' OR {$p}status LIKE 'R%')))";
}

function shop_biz_ensure_schema(): void
{
    shop_ensure_schema();
    shop_ads_ensure_schema();
    if (!empty($_SESSION['schema_shop_biz_v1'])) { shop_biz_upgrade_reorder(); return; }
    $pdo = db();
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS shop_products (
                      id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                      line_key      CHAR(40)      NOT NULL COMMENT '상품명 sha1 (shop_orders.line_key 와 같음)',
                      product       VARCHAR(255)  NOT NULL,
                      unit_cost     DECIMAL(14,0) NULL     COMMENT '원가 (1개, 매입가 + 포장)',
                      ship_cost     DECIMAL(14,0) NULL     COMMENT '주문 줄 1개당 배송비 (우리가 내는 몫)',
                      stock_base    INT           NULL     COMMENT '마지막으로 입력한 재고',
                      stock_base_at DATETIME      NULL     COMMENT '재고를 입력한 시각 — 이 뒤 주문만 뺍니다',
                      hidden        TINYINT(1)    NOT NULL DEFAULT 0 COMMENT '1 = 목록 · 알림에서 숨김 (판매 종료)',
                      updated_at    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                      PRIMARY KEY (id),
                      UNIQUE KEY uq_sp_key (line_key)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                      COMMENT='쇼핑몰 상품 — 원가 · 배송비 · 재고'");
        $pdo->exec("CREATE TABLE IF NOT EXISTS shop_state (
                      k          VARCHAR(60)  NOT NULL,
                      v          VARCHAR(255) NULL,
                      updated_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                      PRIMARY KEY (k)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                      COMMENT='쇼핑몰 자동 작업 상태 (마지막 가져오기 · 보낸 알림)'");
        $ins = $pdo->prepare("INSERT IGNORE INTO app_settings
                                (setting_key, setting_val, group_ko, label_ko, help_ko, input_type, options_csv, sort_order)
                              VALUES (?, ?, '쇼핑몰', ?, ?, ?, ?, ?)");
        foreach ([
            ['shop_fee_coupang', '10.8', '쿠팡 판매 수수료 (%)', '카테고리 평균. 순이익 계산에 씁니다. 캠핑용품은 보통 10.8%', 'number', null, 1],
            ['shop_fee_naver', '5.5', '스마트스토어 수수료 (%)', '주문관리 수수료 + 매출연동 수수료 합계. 보통 5~6%', 'number', null, 2],
            ['shop_fee_cafe24', '3.3', '카페24 결제 수수료 (%)', 'PG 카드 수수료. 보통 3.3%', 'number', null, 3],
            ['shop_auto_fetch', '예', '주문 자동 가져오기', '예 = ERP 화면이 열려 있을 때 1시간마다 쇼핑몰 서버에서 최근 2일 주문을 가져옴', 'select', '예,아니오', 10],
            ['shop_alert_on', '예', '쇼핑몰 알림 보내기', '예 = 하루 한 번 매출 급감 · ROAS 미달 · 품절 임박을 담당자 메일 · 카톡으로 (받는 곳은 알림 설정)', 'select', '예,아니오', 11],
            ['shop_alert_drop_pct', '40', '매출 급감 기준 (%)', '어제 매출이 지난 7일 평균보다 이만큼 이상 줄면 알림', 'number', null, 12],
            ['shop_stock_warn_days', '7', '품절 경고 (일)', '최근 14일 판매 속도로 이 날짜 안에 재고가 떨어질 상품을 경고', 'number', null, 13],
        ] as $r) { $ins->execute($r); }
        $_SESSION['schema_shop_biz_v1'] = 1;
    } catch (PDOException $e) {
        error_log('쇼핑몰 상품 표 준비 실패: ' . $e->getMessage());
    }
    shop_biz_upgrade_reorder();
}

/** 발주 추천용 칸 · 설정 (v2) */
function shop_biz_upgrade_reorder(): void
{
    if (!empty($_SESSION['schema_shop_biz_v3'])) { return; }
    $pdo = db();
    try {
        $st = $pdo->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS
                            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'shop_products'");
        $cols = array_flip($st->fetchAll(PDO::FETCH_COLUMN));
        if (!isset($cols['supplier'])) {
            $pdo->exec("ALTER TABLE shop_products
                          ADD COLUMN supplier   VARCHAR(100) NULL COMMENT '매입처 (발주할 곳)' AFTER ship_cost,
                          ADD COLUMN lead_days  SMALLINT     NULL COMMENT '발주 후 입고까지 걸리는 날 — 비우면 환경설정 기본값' AFTER supplier,
                          ADD COLUMN order_unit INT          NULL COMMENT '발주 단위 (박스 입수 등) — 비우면 1' AFTER lead_days");
        }
        $ins = $pdo->prepare("INSERT IGNORE INTO app_settings
                                (setting_key, setting_val, group_ko, label_ko, help_ko, input_type, options_csv, sort_order)
                              VALUES (?, ?, '쇼핑몰', ?, ?, 'number', NULL, ?)");
        foreach ([
            ['shop_lead_days', '7', '발주 → 입고 기본 일수', '상품에 입고 기간을 따로 안 넣었을 때 씁니다', 20],
            ['shop_cover_days', '30', '한 번에 발주할 판매 일수', '입고된 뒤 이만큼 팔 수 있게 수량을 추천', 21],
            ['shop_safety_days', '3', '안전 재고 (일)', '판매가 갑자기 늘어도 버틸 여유분', 22],
        ] as $r) { $ins->execute($r); }
        $pdo->exec("INSERT IGNORE INTO app_settings
                      (setting_key, setting_val, group_ko, label_ko, help_ko, input_type, options_csv, sort_order)
                    VALUES ('shop_reply_templates', '안녕하세요, 고객님. 문의 주셔서 감사합니다.|확인 후 다시 안내드리겠습니다.|오늘 오후 3시 이전 주문은 당일 출고됩니다.|추가로 궁금하신 점은 언제든 문의 주세요. 감사합니다.',
                            '쇼핑몰', '문의 답변 자주 쓰는 문구', '고객 문의 화면의 빠른 문구 버튼. | 로 나눕니다 (500자까지)', 'text', NULL, 30)");
        $pdo->exec("CREATE TABLE IF NOT EXISTS shop_inquiries (
                      id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                      channel      VARCHAR(20)   NOT NULL COMMENT 'coupang / naver',
                      ext_id       VARCHAR(60)   NOT NULL COMMENT '판매채널 문의 번호',
                      product      VARCHAR(255)  NOT NULL DEFAULT '',
                      question     TEXT          NOT NULL,
                      asked_at     DATETIME      NULL,
                      answered     TINYINT(1)    NOT NULL DEFAULT 0,
                      answer       TEXT          NULL,
                      answered_by  BIGINT UNSIGNED NULL COMMENT 'ERP 에서 답변한 사람',
                      answered_at  DATETIME      NULL,
                      fetched_at   DATETIME      NULL,
                      PRIMARY KEY (id),
                      UNIQUE KEY uq_si (channel, ext_id),
                      KEY ix_si_open (answered, asked_at)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                      COMMENT='쇼핑몰 상품 문의 (commerce-hub 에서 가져옴)'");
        $_SESSION['schema_shop_biz_v3'] = 1;
    } catch (PDOException $e) {
        error_log('쇼핑몰 상품 표 준비 실패: ' . $e->getMessage());
    }
}

function shop_setting(string $key, string $def = ''): string
{
    static $c = null;
    if ($c === null) {
        $c = [];
        try {
            foreach (db()->query("SELECT setting_key, setting_val FROM app_settings WHERE setting_key LIKE 'shop\\_%' OR setting_key = 'ad_target_roas'")
                     ->fetchAll() as $r) { $c[$r['setting_key']] = trim((string)$r['setting_val']); }
        } catch (PDOException $e) {
            // 표가 없으면 기본값
        }
    }
    return ($c[$key] ?? '') !== '' ? $c[$key] : $def;
}

function shop_state_get(string $k): ?string
{
    $st = db()->prepare('SELECT v FROM shop_state WHERE k = ?');
    $st->execute([$k]);
    $v = $st->fetchColumn();
    return $v === false ? null : (string)$v;
}

function shop_state_set(string $k, string $v): void
{
    db()->prepare('INSERT INTO shop_state (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)')->execute([$k, $v]);
}

/** 주문에 나온 상품을 상품 표에 채웁니다 */
function shop_products_sync(): void
{
    db()->exec('INSERT IGNORE INTO shop_products (line_key, product)
                SELECT line_key, MIN(product) FROM shop_orders GROUP BY line_key');
}

/**
 * 상품별 재고 · 판매 속도.
 * @return array<int, array> 상품 줄 + sold_since · stock_now · daily · days_left
 */
function shop_stock_rows(bool $withHidden = false): array
{
    shop_products_sync();
    $cx = shop_cancel_sql('o');
    $st = db()->prepare("SELECT p.*,
                   (SELECT COALESCE(SUM(o.qty), 0) FROM shop_orders o
                     WHERE o.line_key = p.line_key AND p.stock_base_at IS NOT NULL AND o.ordered_at >= p.stock_base_at AND NOT $cx) AS sold_since,
                   (SELECT COALESCE(SUM(o.qty), 0) FROM shop_orders o
                     WHERE o.line_key = p.line_key AND o.ordered_at >= ? AND NOT $cx) AS sold14,
                   (SELECT COALESCE(SUM(o.qty), 0) FROM shop_orders o
                     WHERE o.line_key = p.line_key AND o.ordered_at >= ? AND NOT $cx) AS sold7,
                   (SELECT MAX(o.ordered_at) FROM shop_orders o WHERE o.line_key = p.line_key) AS last_order
              FROM shop_products p " . ($withHidden ? '' : 'WHERE p.hidden = 0') . '
             ORDER BY sold14 DESC, p.product');
    // 주문 시각은 한국 시간으로 저장되어 있어 DB 의 NOW() 대신 PHP 시각을 씁니다
    $st->execute([date('Y-m-d H:i:s', strtotime('-14 days')), date('Y-m-d H:i:s', strtotime('-7 days'))]);
    $rows = $st->fetchAll();
    foreach ($rows as &$r) {
        // 최근 7일이 더 빠르면 그 속도로 (잘 팔리기 시작한 상품을 늦게 알아채지 않게)
        $r['daily'] = round(max((int)$r['sold14'] / 14, (int)$r['sold7'] / 7), 2);
        $r['stock_now'] = $r['stock_base'] === null ? null : (int)$r['stock_base'] - (int)$r['sold_since'];
        $r['days_left'] = ($r['stock_now'] !== null && $r['daily'] > 0) ? (int)floor(max(0, $r['stock_now']) / $r['daily']) : null;
    }
    unset($r);
    return $rows;
}

/**
 * 발주 추천 — 재고를 입력한 상품만.
 *   발주 시점 = 남은 재고가 (입고 일수 + 안전 일수) 동안 팔 양보다 적어지는 날
 *   추천 수량 = 하루 판매 × (입고 일수 + 한 번에 발주할 일수 + 안전 일수) − 남은 재고, 발주 단위로 올림
 */
function shop_reorder_rows(?array $rows = null): array
{
    $defLead = max(0, (int)shop_setting('shop_lead_days', '7'));
    $cover   = max(1, (int)shop_setting('shop_cover_days', '30'));
    $safety  = max(0, (int)shop_setting('shop_safety_days', '3'));
    $out = [];
    foreach ($rows ?? shop_stock_rows() as $r) {
        if ($r['stock_now'] === null || $r['daily'] <= 0) { continue; }
        $lead = $r['lead_days'] !== null ? (int)$r['lead_days'] : $defLead;
        $unit = max(1, (int)($r['order_unit'] ?? 1));
        $now = max(0, (int)$r['stock_now']);
        $point = $r['daily'] * ($lead + $safety);                 // 이 아래로 내려가면 지금 발주
        $daysToOrder = (int)floor(($now - $point) / $r['daily']);  // 0 이하 = 지금
        $need = $r['daily'] * ($lead + $cover + $safety) - $now;
        $qty = $need > 0 ? (int)(ceil($need / $unit) * $unit) : 0;
        $r['lead'] = $lead;
        $r['unit'] = $unit;
        $r['order_in'] = max(0, $daysToOrder);
        $r['order_date'] = date('Y-m-d', strtotime('+' . max(0, $daysToOrder) . ' days'));
        $r['order_now'] = $daysToOrder <= 0;
        $r['qty'] = $qty;
        $r['amount'] = $r['unit_cost'] === null ? null : $qty * (float)$r['unit_cost'];
        $out[] = $r;
    }
    usort($out, fn($a, $b) => [$a['order_in'], (string)$a['supplier']] <=> [$b['order_in'], (string)$b['supplier']]);
    return $out;
}

/** 품절 · 품절 임박 상품 */
function shop_stock_alerts(?array $rows = null): array
{
    $warn = (int)shop_setting('shop_stock_warn_days', '7');
    return array_values(array_filter($rows ?? shop_stock_rows(), fn($r) => $r['stock_now'] !== null
        && ($r['stock_now'] <= 0 || ($r['days_left'] !== null && $r['days_left'] <= $warn))));
}

/**
 * 판매처별 광고비 (추정) — [광고 · 키워드] 에 올린 보고서 중 기간이 겹치는 가장 최근 것을 날짜 비율로.
 * @return array<string, float> channel => 광고비. 보고서가 없는 판매처는 빠집니다
 */
function shop_ad_spend(string $from, string $to): array
{
    $st = db()->prepare("SELECT b.id, b.channel, b.period_from, b.period_to, SUM(s.cost) AS cost
                           FROM ad_keyword_batches b JOIN ad_keyword_stats s ON s.batch_id = b.id
                          WHERE b.period_from IS NOT NULL AND b.period_to IS NOT NULL
                            AND b.period_from <= ? AND b.period_to >= ?
                          GROUP BY b.id ORDER BY b.id DESC");
    $st->execute([$to, $from]);
    $out = [];
    foreach ($st->fetchAll() as $b) {
        if (isset($out[$b['channel']])) { continue; }   // 판매처마다 가장 최근 보고서 하나
        $pd = (strtotime($b['period_to']) - strtotime($b['period_from'])) / 86400 + 1;
        $ov = (min(strtotime($to), strtotime($b['period_to'])) - max(strtotime($from), strtotime($b['period_from']))) / 86400 + 1;
        $out[$b['channel']] = $pd > 0 ? (float)$b['cost'] * max(0, $ov) / $pd : 0.0;
    }
    // 쿠팡 광고는 쿠팡 매출, 네이버 검색광고는 스마트스토어 매출에 붙입니다 (채널 코드가 같음)
    return $out;
}

/**
 * 상품별 순이익.
 * @return array{rows: array, total: array, ad: array, missing_cost: int}
 */
function shop_profit(string $from, string $to): array
{
    shop_products_sync();
    $cx = shop_cancel_sql('o');
    $st = db()->prepare("SELECT o.line_key, o.channel, MIN(o.product) AS product, SUM(o.qty) AS qty, SUM(o.amount) AS sales, COUNT(*) AS lines_cnt
                           FROM shop_orders o
                          WHERE o.ordered_at >= ? AND o.ordered_at < DATE_ADD(?, INTERVAL 1 DAY) AND NOT $cx
                          GROUP BY o.line_key, o.channel");
    $st->execute([$from, $to]);
    $lines = $st->fetchAll();
    $prod = [];
    foreach (db()->query('SELECT line_key, unit_cost, ship_cost FROM shop_products')->fetchAll() as $p) { $prod[$p['line_key']] = $p; }
    $fee = ['coupang' => (float)shop_setting('shop_fee_coupang', '10.8'), 'naver' => (float)shop_setting('shop_fee_naver', '5.5'),
            'cafe24' => (float)shop_setting('shop_fee_cafe24', '3.3')];
    $ad = shop_ad_spend($from, $to);
    $chSales = [];
    foreach ($lines as $l) { $chSales[$l['channel']] = ($chSales[$l['channel']] ?? 0) + (float)$l['sales']; }

    $rows = [];
    $missing = [];
    foreach ($lines as $l) {
        $k = $l['line_key'];
        $p = $prod[$k] ?? ['unit_cost' => null, 'ship_cost' => null];
        if ($p['unit_cost'] === null) { $missing[$k] = 1; }
        $r = $rows[$k] ?? ['line_key' => $k, 'product' => $l['product'], 'qty' => 0, 'sales' => 0.0, 'cost' => 0.0, 'fee' => 0.0,
                           'ship' => 0.0, 'ad' => 0.0, 'channels' => [], 'no_cost' => $p['unit_cost'] === null];
        $sales = (float)$l['sales'];
        $r['qty']   += (int)$l['qty'];
        $r['sales'] += $sales;
        $r['cost']  += (float)($p['unit_cost'] ?? 0) * (int)$l['qty'];
        $r['fee']   += $sales * ($fee[$l['channel']] ?? 0) / 100;
        $r['ship']  += (float)($p['ship_cost'] ?? 0) * (int)$l['lines_cnt'];
        $r['ad']    += isset($ad[$l['channel']]) && ($chSales[$l['channel']] ?? 0) > 0 ? $ad[$l['channel']] * $sales / $chSales[$l['channel']] : 0;
        $r['channels'][$l['channel']] = 1;
        $rows[$k] = $r;
    }
    $tot = ['qty' => 0, 'sales' => 0.0, 'cost' => 0.0, 'fee' => 0.0, 'ship' => 0.0, 'ad' => 0.0, 'profit' => 0.0, 'before_ad' => 0.0];
    foreach ($rows as &$r) {
        $r['before_ad'] = $r['sales'] - $r['cost'] - $r['fee'] - $r['ship'];
        $r['profit'] = $r['before_ad'] - $r['ad'];
        $r['margin'] = $r['sales'] > 0 ? round($r['profit'] / $r['sales'] * 100, 1) : 0.0;
        $r['roas'] = $r['ad'] > 0 ? (int)round($r['sales'] / $r['ad'] * 100) : null;
        foreach ($tot as $k => $_) { $tot[$k] += $r[$k]; }
    }
    unset($r);
    usort($rows, fn($a, $b) => $b['sales'] <=> $a['sales']);
    $tot['margin'] = $tot['sales'] > 0 ? round($tot['profit'] / $tot['sales'] * 100, 1) : 0.0;
    return ['rows' => $rows, 'total' => $tot, 'ad' => $ad, 'missing_cost' => count($missing)];
}

/** 하루(또는 기간) 매출 · 주문 수 (취소 · 반품 제외) */
function shop_sales_between(string $fromDt, string $toDt): array
{
    $st = db()->prepare('SELECT COALESCE(SUM(amount), 0) AS sales, COUNT(DISTINCT channel, order_id) AS orders
                           FROM shop_orders WHERE ordered_at >= ? AND ordered_at < ? AND NOT ' . shop_cancel_sql());
    $st->execute([$fromDt, $toDt]);
    $r = $st->fetch();
    return ['sales' => (float)$r['sales'], 'orders' => (int)$r['orders']];
}

/** 판매처별 가장 최근 키워드 보고서의 제외할 키워드 수 · ROAS */
function shop_ad_latest(): array
{
    $target = (float)shop_setting('ad_target_roas', '400');
    $out = [];
    foreach (db()->query('SELECT b.id, b.channel, b.uploaded_at FROM ad_keyword_batches b
                           JOIN (SELECT channel, MAX(id) AS id FROM ad_keyword_batches GROUP BY channel) l ON l.id = b.id')->fetchAll() as $b) {
        $st = db()->prepare('SELECT clicks, cost, revenue FROM ad_keyword_stats WHERE batch_id = ?');
        $st->execute([(int)$b['id']]);
        $ex = 0; $cost = 0.0; $rev = 0.0;
        foreach ($st->fetchAll() as $s) {
            if (shop_ads_action(['clicks' => (int)$s['clicks'], 'cost' => (float)$s['cost'], 'revenue' => (float)$s['revenue']], $target)[0] === '제외키워드 등록') { $ex++; }
            $cost += (float)$s['cost'];
            $rev += (float)$s['revenue'];
        }
        $out[$b['channel']] = ['batch_id' => (int)$b['id'], 'exclude' => $ex, 'roas' => $cost > 0 ? (int)round($rev / $cost * 100) : null,
                               'uploaded_at' => $b['uploaded_at']];
    }
    return $out;
}

/**
 * 자동 작업 — track_tick 이 부릅니다. 서버 전체에서 한 사람만 (GET_LOCK), 할 일이 없으면 바로 끝.
 * @return array 한 일 요약
 */
function shop_auto_tick(): array
{
    $pdo = db();
    $res = [];
    try {
        shop_biz_ensure_schema();
        if ((int)$pdo->query("SELECT GET_LOCK('gp_shop_tick', 0)")->fetchColumn() !== 1) { return ['shop' => 'busy']; }
        try {
            // ① 1시간마다 최근 2일 주문
            $cfg = shop_api_cfg();
            $last = (int)(shop_state_get('last_fetch') ?? 0);
            if (shop_setting('shop_auto_fetch', '예') === '예' && $cfg['shop_api_url'] !== '' && $cfg['shop_api_token'] !== ''
                && time() - $last >= 3600) {
                shop_state_set('last_fetch', (string)time());   // 실패해도 1시간 뒤 다시 (서버가 꺼져 있을 때 매번 기다리지 않게)
                try {
                    [$new, $chg] = shop_upsert(shop_api_fetch(2), 'API');
                    $qn = '';
                    try {
                        [$qNew] = shop_inquiries_sync(3);
                        $qn = " · 새 문의 {$qNew}";
                    } catch (RuntimeException $e) {
                        $qn = ' · 문의 실패';
                    }
                    shop_state_set('last_fetch_result', date('Y-m-d H:i') . " 새 {$new} · 바뀜 {$chg}{$qn}");
                    $res['shop_fetch'] = "$new/$chg";
                } catch (RuntimeException $e) {
                    shop_state_set('last_fetch_result', date('Y-m-d H:i') . ' 실패: ' . mb_substr($e->getMessage(), 0, 150));
                    $res['shop_fetch'] = 'fail';
                }
            }
            // ② 하루 한 번 (오전 9시 이후) 알림
            if (shop_setting('shop_alert_on', '예') === '예' && (int)date('G') >= 9 && shop_state_get('alert_day') !== date('Y-m-d')) {
                shop_state_set('alert_day', date('Y-m-d'));
                $res['shop_alert'] = shop_send_alerts();
            }
        } finally {
            $pdo->query("SELECT RELEASE_LOCK('gp_shop_tick')");
        }
    } catch (Throwable $e) {
        error_log('쇼핑몰 자동 작업 실패: ' . $e->getMessage());
        $res['shop'] = 'error';
    }
    return $res;
}

/** 알림에 넣을 줄들 — 화면(쇼핑몰 주문 · 매출)에서도 같은 내용을 보여 줍니다 */
function shop_alert_lines(): array
{
    $lines = [];
    // 매출 급감 — 어제 vs 그 전 7일 평균
    $y = date('Y-m-d', strtotime('-1 day'));
    $yd = shop_sales_between($y, date('Y-m-d'));
    $prev = shop_sales_between(date('Y-m-d', strtotime('-8 days')), $y);
    $avg = $prev['sales'] / 7;
    $drop = (float)shop_setting('shop_alert_drop_pct', '40');
    if ($avg > 0 && $yd['sales'] < $avg * (1 - $drop / 100)) {
        $lines[] = sprintf('매출 급감: 어제 %s원 (지난 7일 평균 %s원보다 %d%% 적음)', money($yd['sales']), money($avg),
                           (int)round((1 - $yd['sales'] / $avg) * 100));
    }
    // ROAS 미달 · 제외할 키워드 — 최근 보고서
    $target = (int)shop_setting('ad_target_roas', '400');
    foreach (shop_ad_latest() as $ch => $a) {
        $name = $ch === 'coupang' ? '쿠팡' : '네이버 검색광고';
        if ($a['roas'] !== null && $a['roas'] < $target) {
            $lines[] = "{$name} 광고 ROAS {$a['roas']}% (목표 {$target}%) — 제외할 키워드 {$a['exclude']}개";
        }
    }
    // 답변 안 한 고객 문의
    $open = shop_inquiries_open();
    if ($open['cnt'] > 0) {
        $lines[] = "답변 안 한 상품 문의 {$open['cnt']}건" . ($open['old'] ? " (하루 넘은 것 {$open['old']}건)" : '');
    }
    // 주문받고 하루가 지나도 송장이 없는 주문
    require_once APP_DIR . '/shop_ship.php';
    $late = array_filter(shop_ship_waiting(14), fn($r) => (string)$r['ordered_at'] < date('Y-m-d H:i:s', strtotime('-1 day')));
    if ($late) {
        $lines[] = '하루 넘게 송장이 없는 주문 ' . count($late) . '건 — 쇼핑몰관리 > 송장 등록';
    }
    // 오늘 발주해야 할 상품
    $ro = array_values(array_filter(shop_reorder_rows(), fn($r) => $r['order_now'] && $r['qty'] > 0 && !(int)$r['hidden']));
    if ($ro) {
        $lines[] = '발주 필요 ' . count($ro) . '개: ' . implode(', ', array_map(fn($r) => $r['product'] . ' ' . $r['qty'] . '개', array_slice($ro, 0, 5)))
                 . (count($ro) > 5 ? ' 외' : '');
    }
    // 품절 · 품절 임박
    foreach (array_slice(shop_stock_alerts(), 0, 10) as $r) {
        $lines[] = $r['stock_now'] <= 0
            ? "품절: {$r['product']} (재고 {$r['stock_now']})"
            : "품절 임박: {$r['product']} — 재고 {$r['stock_now']}개, 약 {$r['days_left']}일 뒤 소진";
    }
    return $lines;
}

function shop_send_alerts(): string
{
    $lines = shop_alert_lines();
    if (!$lines) { return 'none'; }
    require_once APP_DIR . '/notify.php';
    $c = notify_cfg();
    $text = "[GOODPOST 쇼핑몰] " . date('m/d') . " 확인할 일\n- " . implode("\n- ", $lines);
    $sent = [];
    if (($c['notify_staff_emails'] ?? '') !== '') {
        [$ok, $m] = notify_mail($c['notify_staff_emails'], '[GOODPOST 쇼핑몰] 확인할 일 ' . count($lines) . '건', $text);
        $sent[] = '메일 ' . ($ok ? 'OK' : '실패 ' . $m);
    }
    if (($c['notify_staff_phones'] ?? '') !== '') {
        [$ok, $m] = notify_kakao($c['notify_staff_phones'], mb_substr($text, 0, 1000));
        $sent[] = '카톡 ' . ($ok ? 'OK' : '실패 ' . $m);
    }
    $r = date('Y-m-d H:i') . ' ' . count($lines) . '건 · ' . ($sent ? implode(' · ', $sent) : '받는 곳 없음 (알림 설정)');
    shop_state_set('last_alert_result', $r);
    return $r;
}

/**
 * 상품 문의 가져오기 — commerce-hub GET /inquiries.
 * @return array{0:int,1:array} [새로 들어온 문의 수, 판매채널별 오류]
 */
function shop_inquiries_sync(int $days): array
{
    $r = shop_api_get('/inquiries', min($days, 30));
    $pdo = db();
    $st = $pdo->prepare('INSERT INTO shop_inquiries (channel, ext_id, product, question, asked_at, answered, answer, fetched_at)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                         ON DUPLICATE KEY UPDATE product = VALUES(product), question = VALUES(question),
                                                 answered = GREATEST(answered, VALUES(answered)),
                                                 answer = COALESCE(VALUES(answer), answer), fetched_at = VALUES(fetched_at)');
    $new = 0;
    $now = date('Y-m-d H:i:s');
    foreach ($r['items'] ?? [] as $q) {
        if (!is_array($q) || ($q['id'] ?? '') === '' || ($q['channel'] ?? '') === '') { continue; }
        $st->execute([mb_substr((string)$q['channel'], 0, 20), mb_substr((string)$q['id'], 0, 60), mb_substr((string)($q['product'] ?? ''), 0, 255),
                      (string)($q['question'] ?? ''), shop_datetime((string)($q['askedAt'] ?? '')), !empty($q['answered']) ? 1 : 0,
                      isset($q['answer']) && $q['answer'] !== '' ? (string)$q['answer'] : null, $now]);
        if ($st->rowCount() === 1) { $new++; }
    }
    shop_state_set('last_inquiry_fetch', date('Y-m-d H:i') . " 새 {$new}" . (!empty($r['errors']) ? ' · 실패: ' . implode(', ', array_keys((array)$r['errors'])) : ''));
    return [$new, (array)($r['errors'] ?? [])];
}

/** 판매채널에 답변 등록 — 성공하면 ERP 에도 답변 · 답변한 사람을 남깁니다 */
function shop_inquiry_reply(int $id, string $content): array
{
    $st = db()->prepare('SELECT * FROM shop_inquiries WHERE id = ?');
    $st->execute([$id]);
    $q = $st->fetch();
    if (!$q) { throw new RuntimeException('문의를 찾을 수 없습니다.'); }
    $content = trim($content);
    if ($content === '') { throw new RuntimeException('답변을 적으세요.'); }
    if (mb_strlen($content) > 2000) { throw new RuntimeException('답변은 2000자까지 됩니다.'); }
    shop_api_post('/inquiries/reply', ['channel' => $q['channel'], 'id' => $q['ext_id'], 'content' => $content]);
    db()->prepare('UPDATE shop_inquiries SET answered = 1, answer = ?, answered_by = ?, answered_at = ? WHERE id = ?')
        ->execute([$content, $_SESSION['admin_id'] ?? null, date('Y-m-d H:i:s'), $id]);
    return $q;
}

/** 답변 안 한 문의 수 · 그중 하루 넘은 것 */
function shop_inquiries_open(): array
{
    try {
        $st = db()->prepare('SELECT COUNT(*) AS cnt, COALESCE(SUM(asked_at < ?), 0) AS old FROM shop_inquiries WHERE answered = 0');
        $st->execute([date('Y-m-d H:i:s', strtotime('-1 day'))]);
        $r = $st->fetch();
        return ['cnt' => (int)$r['cnt'], 'old' => (int)$r['old']];
    } catch (PDOException $e) {
        return ['cnt' => 0, 'old' => 0];
    }
}
