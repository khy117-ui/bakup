<?php
declare(strict_types=1);

// 웹에서 직접 열면 실행되지 않게 막습니다 (nginx 면 .htaccess 가 무시됩니다)
if (!defined('APP_DIR')) { http_response_code(403); exit('Forbidden'); }

/**
 * 쇼핑몰 주문 — 쇼핑몰 통합관리 프로그램(commerce-hub)이 모은 쿠팡 · 스마트스토어 · 카페24 주문을 ERP 에 쌓습니다.
 *   commerce-hub serve 서버의 GET /orders?days=N · GET /report?days=N 를 부릅니다 (Authorization: Bearer 토큰).
 * 같은 판매처 · 주문번호 · 상품명 줄은 한 줄로 보고, 다시 들어오면 수량 · 금액 · 상태만 새 값으로 바꿉니다.
 */

const SHOP_CHANNELS = ['coupang' => '쿠팡', 'naver' => '스마트스토어', 'cafe24' => '카페24'];

function shop_channel_code(string $s): string
{
    $s = trim($s);
    if (isset(SHOP_CHANNELS[$s])) { return $s; }
    $k = array_search($s, SHOP_CHANNELS, true);
    return $k !== false ? $k : mb_substr($s, 0, 20);
}

function shop_channel_label(string $code): string
{
    return SHOP_CHANNELS[$code] ?? $code;
}

/** 표 · 설정 자리 · 권한 (세션당 한 번) */
function shop_ensure_schema(): void
{
    if (!empty($_SESSION['schema_shop_v1'])) { shop_upgrade_dispatch(); return; }
    $pdo = db();
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS shop_orders (
                      id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                      channel     VARCHAR(20)   NOT NULL COMMENT 'coupang / naver / cafe24',
                      order_id    VARCHAR(60)   NOT NULL,
                      line_key    CHAR(40)      NOT NULL COMMENT '상품명 sha1 — 한 주문의 여러 상품 줄 구분',
                      ordered_at  DATETIME      NULL,
                      product     VARCHAR(255)  NOT NULL DEFAULT '',
                      qty         INT           NOT NULL DEFAULT 0,
                      amount      DECIMAL(14,0) NOT NULL DEFAULT 0,
                      status      VARCHAR(40)   NULL,
                      source      VARCHAR(10)   NOT NULL DEFAULT 'API' COMMENT '어디서 들어왔는지 (API)',
                      created_at  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
                      updated_at  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                      PRIMARY KEY (id),
                      UNIQUE KEY uq_so_line (channel, order_id, line_key),
                      KEY ix_so_date (ordered_at)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                      COMMENT='쇼핑몰 주문 (commerce-hub 연동)'");
        $pdo->exec("INSERT IGNORE INTO app_settings
                      (setting_key, setting_val, group_ko, label_ko, help_ko, input_type, options_csv, sort_order)
                    VALUES ('shop_api_url', NULL, '연동', '쇼핑몰 통합관리 서버 주소',
                            'commerce-hub serve 를 띄운 주소. 예) https://shop.example.com:8787 — 쇼핑몰관리 > 주문 · 매출 에서 주문과 리포트를 여기서 불러옵니다.',
                            'text', NULL, 30),
                           ('shop_api_token', NULL, '연동', '쇼핑몰 통합관리 서버 토큰',
                            'commerce-hub .env 의 ERP_API_TOKEN 과 같은 값.', 'secret', NULL, 31)");
        $pdo->exec("INSERT INTO permissions (code, group_ko, name_ko) VALUES
                      ('shop.order.read',  '쇼핑몰관리', '쇼핑몰 주문 · 매출 조회'),
                      ('shop.order.write', '쇼핑몰관리', '쇼핑몰 주문 가져오기')
                    ON DUPLICATE KEY UPDATE name_ko = VALUES(name_ko)");
        $pdo->exec("INSERT IGNORE INTO role_permissions (role_code, permission_id)
                    SELECT r.role_code, p.id
                      FROM permissions p
                      JOIN (SELECT 'SUPER_ADMIN' AS role_code UNION ALL SELECT 'MANAGER') r
                     WHERE p.code IN ('shop.order.read', 'shop.order.write')");
        $_SESSION['schema_shop_v1'] = 1;
    } catch (PDOException $e) {
        error_log('쇼핑몰 주문 표 준비 실패: ' . $e->getMessage());
    }
    shop_upgrade_dispatch();
}

/** 송장 등록용: 판매채널 상품주문 번호 · 쿠팡 묶음배송번호 · 등록한 송장, 송장 등록 기록 */
function shop_upgrade_dispatch(): void
{
    if (!empty($_SESSION['schema_shop_v2'])) { return; }
    $pdo = db();
    try {
        $st = $pdo->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS
                            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'shop_orders'");
        $cols = array_flip($st->fetchAll(PDO::FETCH_COLUMN));
        if (!isset($cols['line_id'])) {
            $pdo->exec("ALTER TABLE shop_orders
                          ADD COLUMN line_id       VARCHAR(80) NULL COMMENT '상품주문 번호 (쿠팡 vendorItemId · 스마트스토어 productOrderId · 카페24 order_item_code)' AFTER line_key,
                          ADD COLUMN ship_id       VARCHAR(40) NULL COMMENT '쿠팡 묶음배송번호 (shipmentBoxId)' AFTER line_id,
                          ADD COLUMN courier       VARCHAR(30) NULL COMMENT 'ERP 에서 등록한 택배사' AFTER status,
                          ADD COLUMN tracking_no   VARCHAR(40) NULL COMMENT 'ERP 에서 등록한 송장번호' AFTER courier,
                          ADD COLUMN dispatched_at DATETIME    NULL COMMENT '송장 등록한 때' AFTER tracking_no,
                          ADD KEY ix_so_line_id (line_id)");
        }
        $pdo->exec("CREATE TABLE IF NOT EXISTS shop_dispatch_log (
                      id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                      batch       CHAR(16)      NOT NULL COMMENT '한 번에 올린 묶음',
                      channel     VARCHAR(20)   NOT NULL,
                      order_id    VARCHAR(60)   NOT NULL,
                      courier     VARCHAR(30)   NOT NULL,
                      tracking_no VARCHAR(40)   NOT NULL,
                      ok          TINYINT(1)    NOT NULL DEFAULT 0,
                      message     VARCHAR(300)  NULL,
                      sent_by     BIGINT UNSIGNED NULL,
                      sent_at     DATETIME      NOT NULL,
                      PRIMARY KEY (id),
                      KEY ix_sdl_order (channel, order_id),
                      KEY ix_sdl_sent (sent_at)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                      COMMENT='쇼핑몰 송장 등록 기록'");
        $_SESSION['schema_shop_v2'] = 1;
    } catch (PDOException $e) {
        error_log('쇼핑몰 송장 표 준비 실패: ' . $e->getMessage());
    }
}

/** 주문일시 문자열 → 'Y-m-d H:i:s' (한국 시간). 못 읽으면 null */
function shop_datetime(string $s): ?string
{
    $s = trim($s);
    if ($s === '') { return null; }
    try {
        $d = new DateTimeImmutable($s, new DateTimeZone('Asia/Seoul'));
        return $d->setTimezone(new DateTimeZone('Asia/Seoul'))->format('Y-m-d H:i:s');
    } catch (Exception $e) {
        return null;
    }
}

/**
 * 주문 줄 저장 (있으면 수량 · 금액 · 상태 갱신).
 * @return array{0: int, 1: int} [새로 들어온 줄, 바뀐 줄]
 */
function shop_upsert(array $orders, string $source): array
{
    shop_ensure_schema();
    $pdo = db();
    $st = $pdo->prepare("INSERT INTO shop_orders (channel, order_id, line_key, line_id, ship_id, ordered_at, product, qty, amount, status, source)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                         ON DUPLICATE KEY UPDATE qty = VALUES(qty), amount = VALUES(amount), status = VALUES(status),
                                                 line_id = COALESCE(VALUES(line_id), line_id), ship_id = COALESCE(VALUES(ship_id), ship_id),
                                                 ordered_at = COALESCE(VALUES(ordered_at), ordered_at)");
    $new = 0;
    $chg = 0;
    $pdo->beginTransaction();
    try {
        foreach ($orders as $o) {
            $product = mb_substr(trim((string)($o['product'] ?? '')), 0, 255);
            $st->execute([
                shop_channel_code((string)$o['channel']),
                mb_substr(trim((string)$o['orderId']), 0, 60),
                sha1($product),
                mb_substr(trim((string)($o['lineId'] ?? '')), 0, 80) ?: null,
                mb_substr(trim((string)($o['shipId'] ?? '')), 0, 40) ?: null,
                shop_datetime((string)($o['orderedAt'] ?? '')),
                $product,
                (int)num((string)($o['qty'] ?? '0')),
                round(num((string)($o['amount'] ?? '0'))),
                mb_substr(trim((string)($o['status'] ?? '')), 0, 40) ?: null,
                $source,
            ]);
            // MySQL: 새 줄 1, 바뀐 줄 2, 그대로 0
            $n = $st->rowCount();
            if ($n === 1) { $new++; } elseif ($n === 2) { $chg++; }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    return [$new, $chg];
}

function shop_api_cfg(): array
{
    $c = ['shop_api_url' => '', 'shop_api_token' => ''];
    try {
        $st = db()->query("SELECT setting_key, setting_val FROM app_settings WHERE setting_key IN ('shop_api_url', 'shop_api_token')");
        foreach ($st->fetchAll() as $r) { $c[$r['setting_key']] = trim((string)$r['setting_val']); }
    } catch (PDOException $e) {
        // 표가 없으면 빈 설정
    }
    return $c;
}

/** commerce-hub serve 에서 최근 N일 주문 줄 */
function shop_api_fetch(int $days): array
{
    return shop_api_get('/orders', $days);
}

/** commerce-hub serve 의 판매 · 마케팅 리포트 (total · channels · products · tips) */
function shop_api_report(int $days): array
{
    return shop_api_get('/report', $days);
}

function shop_api_get(string $path, int $days): array
{
    return shop_api_request('GET', $path, ['days' => max(1, min($days, 90))]);
}

/** commerce-hub 에 JSON 을 보냅니다 (문의 답변 등) */
function shop_api_post(string $path, array $body): array
{
    return shop_api_request('POST', $path, [], $body);
}

function shop_api_request(string $method, string $path, array $query, ?array $body = null): array
{
    $c = shop_api_cfg();
    if ($c['shop_api_url'] === '' || $c['shop_api_token'] === '') {
        throw new RuntimeException('환경설정 → 연동 에 쇼핑몰 통합관리 서버 주소와 토큰을 먼저 넣으세요.');
    }
    if (!preg_match('#^https?://#i', $c['shop_api_url'])) {
        throw new RuntimeException('서버 주소는 http:// 또는 https:// 로 시작해야 합니다.');
    }
    if (!function_exists('curl_init')) {
        throw new RuntimeException('이 서버에 PHP curl 이 없어 쇼핑몰 서버를 부를 수 없습니다.');
    }
    $url = rtrim($c['shop_api_url'], '/') . $path . ($query ? '?' . http_build_query($query) : '');
    $ch = curl_init($url);
    if ($body !== null) {
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE)]);
    }
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 90,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $c['shop_api_token'], 'Accept: application/json', 'Content-Type: application/json'],
    ]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $cerr = curl_error($ch);
    curl_close($ch);
    if ($body === false) { throw new RuntimeException('쇼핑몰 서버에 연결하지 못했습니다: ' . $cerr); }
    if ($code === 401) { throw new RuntimeException('토큰이 맞지 않습니다 (401). 환경설정의 토큰을 확인하세요.'); }
    $data = json_decode((string)$body, true);
    if ($code !== 200 || !is_array($data)) {
        $msg = is_array($data) && isset($data['error']) ? (string)$data['error'] : ('응답 코드 ' . $code);
        throw new RuntimeException('쇼핑몰 서버 응답 오류: ' . mb_substr($msg, 0, 200));
    }
    return $data;
}
