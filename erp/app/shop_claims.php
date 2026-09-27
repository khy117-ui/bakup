<?php
declare(strict_types=1);

// 웹에서 직접 열면 실행되지 않게 막습니다 (nginx 면 .htaccess 가 무시됩니다)
if (!defined('APP_DIR')) { http_response_code(403); exit('Forbidden'); }

require_once APP_DIR . '/shop_biz.php';

/**
 * 반품 · 교환 — 쿠팡 · 스마트스토어의 반품 · 교환 요청을 쇼핑몰 서버(commerce-hub GET /claims)에서 가져와 한 화면에서 보고,
 * 반품은 ERP 에서 바로 승인합니다 (POST /claims/approve).
 *
 * 재고: 반품이 끝난 주문은 주문 상태가 반품으로 바뀌어 판매 수량에서 빠지므로 재고가 저절로 다시 늘어납니다.
 *   (스마트스토어 · 카페24 는 주문 가져오기로, 쿠팡은 여기서 반품 완료를 보고 주문 상태를 바꿉니다.)
 *   파손 등으로 다시 팔 수 없으면 승인할 때 "재고에 넣지 않음" 을 고르면 그만큼 재고에서 뺍니다.
 */

const SHOP_CLAIM_TYPES = ['RETURN' => ['반품', 'b-warn'], 'EXCHANGE' => ['교환', 'b-info'], 'CANCEL' => ['취소', 'b-err']];

const SHOP_CLAIM_STATUS = [
    // 쿠팡 반품
    'RETURNS_UNCHECKED' => '반품 접수', 'VENDOR_WAREHOUSE_CONFIRM' => '입고 확인', 'REQUEST_COUPANG_CHECK' => '쿠팡 확인 요청',
    'RETURNS_COMPLETED' => '반품 완료',
    // 쿠팡 교환
    'RECEIPT' => '교환 접수', 'PROGRESS' => '교환 진행', 'SUCCESS' => '교환 완료', 'REJECT' => '교환 거부', 'CANCEL' => '교환 철회',
    // 스마트스토어
    'RETURN_REQUEST' => '반품 요청', 'COLLECTING' => '수거 중', 'COLLECT_DONE' => '수거 완료', 'RETURN_DONE' => '반품 완료',
    'RETURN_REJECT' => '반품 거부', 'EXCHANGE_REQUEST' => '교환 요청', 'EXCHANGE_REDELIVERING' => '교환 재배송 중',
    'EXCHANGE_DONE' => '교환 완료', 'EXCHANGE_REJECT' => '교환 거부',
];

function shop_claims_ensure_schema(): void
{
    shop_biz_ensure_schema();
    if (!empty($_SESSION['schema_shop_claims_v1'])) { return; }
    try {
        db()->exec("CREATE TABLE IF NOT EXISTS shop_claims (
                      id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                      channel      VARCHAR(20)   NOT NULL,
                      ext_id       VARCHAR(60)   NOT NULL COMMENT '쿠팡 접수번호 · 교환번호 / 스마트스토어 상품주문번호',
                      order_id     VARCHAR(60)   NOT NULL DEFAULT '',
                      type         VARCHAR(10)   NOT NULL COMMENT 'RETURN / EXCHANGE / CANCEL',
                      status       VARCHAR(40)   NOT NULL DEFAULT '',
                      product      VARCHAR(255)  NOT NULL DEFAULT '',
                      qty          INT           NOT NULL DEFAULT 1,
                      reason       VARCHAR(500)  NULL,
                      requested_at DATETIME      NULL,
                      can_approve  TINYINT(1)    NOT NULL DEFAULT 0,
                      done         TINYINT(1)    NOT NULL DEFAULT 0,
                      approved_by  BIGINT UNSIGNED NULL,
                      approved_at  DATETIME      NULL,
                      no_restock   TINYINT(1)    NOT NULL DEFAULT 0 COMMENT '1 = 다시 팔 수 없어 재고에서 뺌',
                      fetched_at   DATETIME      NULL,
                      PRIMARY KEY (id),
                      UNIQUE KEY uq_sc (channel, ext_id, type),
                      KEY ix_sc_open (done, requested_at)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                      COMMENT='쇼핑몰 반품 · 교환 (commerce-hub 에서 가져옴)'");
        $_SESSION['schema_shop_claims_v1'] = 1;
    } catch (PDOException $e) {
        error_log('쇼핑몰 반품 표 준비 실패: ' . $e->getMessage());
    }
}

function shop_claim_status_label(string $s): string
{
    return SHOP_CLAIM_STATUS[$s] ?? $s;
}

/** 반품 · 교환 가져오기. [새 건수, 판매처별 오류] */
function shop_claims_sync(int $days): array
{
    shop_claims_ensure_schema();
    $r = shop_api_get('/claims', min($days, 30));
    $pdo = db();
    $st = $pdo->prepare('INSERT INTO shop_claims (channel, ext_id, order_id, type, status, product, qty, reason, requested_at, can_approve, done, fetched_at)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                         ON DUPLICATE KEY UPDATE status = VALUES(status), product = VALUES(product), qty = VALUES(qty), reason = VALUES(reason),
                                                 can_approve = IF(approved_at IS NULL, VALUES(can_approve), 0),
                                                 done = GREATEST(done, VALUES(done)), fetched_at = VALUES(fetched_at)');
    $new = 0;
    $now = date('Y-m-d H:i:s');
    foreach ($r['items'] ?? [] as $c) {
        if (!is_array($c) || ($c['id'] ?? '') === '' || !isset(SHOP_CLAIM_TYPES[$c['type'] ?? ''])) { continue; }
        $st->execute([mb_substr((string)$c['channel'], 0, 20), mb_substr((string)$c['id'], 0, 60), mb_substr((string)($c['orderId'] ?? ''), 0, 60),
                      $c['type'], mb_substr((string)($c['status'] ?? ''), 0, 40), mb_substr((string)($c['product'] ?? ''), 0, 255),
                      max(1, (int)($c['qty'] ?? 1)), mb_substr((string)($c['reason'] ?? ''), 0, 500) ?: null,
                      shop_datetime((string)($c['requestedAt'] ?? '')), !empty($c['canApprove']) ? 1 : 0, !empty($c['done']) ? 1 : 0, $now]);
        if ($st->rowCount() === 1) { $new++; }
    }
    shop_claims_mark_orders();
    shop_state_set('last_claim_fetch', date('Y-m-d H:i') . " 새 {$new}" . (!empty($r['errors']) ? ' · 실패: ' . implode(', ', array_keys((array)$r['errors'])) : ''));
    return [$new, (array)($r['errors'] ?? [])];
}

/**
 * 쿠팡 반품 완료 → 그 주문 줄 상태를 RETURN_DONE 으로 (매출 · 판매 수량에서 빠져 재고가 다시 늘어남).
 * 스마트스토어 · 카페24 는 주문 가져오기 때 판매처 상태가 그대로 들어옵니다.
 */
function shop_claims_mark_orders(): void
{
    $pdo = db();
    $done = $pdo->query("SELECT channel, order_id, product FROM shop_claims
                          WHERE channel = 'coupang' AND type = 'RETURN' AND done = 1 AND order_id <> ''")->fetchAll();
    $lines = $pdo->prepare('SELECT id, product FROM shop_orders WHERE channel = ? AND order_id = ? AND NOT ' . shop_cancel_sql());
    $upd = $pdo->prepare("UPDATE shop_orders SET status = 'RETURN_DONE' WHERE id = ?");
    foreach ($done as $c) {
        $lines->execute([$c['channel'], $c['order_id']]);
        $ls = $lines->fetchAll();
        $names = array_map('trim', explode(',', (string)$c['product']));
        $hit = array_filter($ls, fn($l) => in_array($l['product'], $names, true));
        foreach ($hit ?: (count($ls) === 1 ? $ls : []) as $l) { $upd->execute([(int)$l['id']]); }
    }
}

/** 반품 승인. $restock = false 면 다시 팔 수 없는 물건이라 재고에서 수량만큼 뺍니다 */
function shop_claim_approve(int $id, bool $restock): array
{
    shop_claims_ensure_schema();
    $pdo = db();
    $st = $pdo->prepare('SELECT * FROM shop_claims WHERE id = ?');
    $st->execute([$id]);
    $c = $st->fetch();
    if (!$c) { throw new RuntimeException('반품 건을 찾을 수 없습니다.'); }
    if (!(int)$c['can_approve']) { throw new RuntimeException('이 건은 지금 ERP 에서 승인할 수 없습니다 (판매처 관리자에서 처리하세요).'); }
    shop_api_post('/claims/approve', ['channel' => $c['channel'], 'id' => $c['ext_id'], 'qty' => (int)$c['qty']]);
    $pdo->prepare('UPDATE shop_claims SET can_approve = 0, approved_by = ?, approved_at = ?, no_restock = ? WHERE id = ?')
        ->execute([$_SESSION['admin_id'] ?? null, date('Y-m-d H:i:s'), $restock ? 0 : 1, $id]);
    if (!$restock) {
        // 재고를 입력해 둔 상품만: 반품 완료로 다시 늘어날 수량을 미리 빼 둠
        foreach (array_map('trim', explode(',', (string)$c['product'])) as $name) {
            $pdo->prepare('UPDATE shop_products SET stock_base = stock_base - ? WHERE line_key = ? AND stock_base IS NOT NULL')
                ->execute([(int)$c['qty'], sha1($name)]);
        }
    }
    return $c;
}

/** 처리할 반품 · 교환 (끝나지 않은 것, 최근 30일) */
function shop_claims_open(): int
{
    try {
        $st = db()->prepare("SELECT COUNT(*) FROM shop_claims WHERE done = 0 AND type IN ('RETURN', 'EXCHANGE')
                               AND (requested_at IS NULL OR requested_at >= ?)
                               AND status NOT IN ('RETURN_REJECT', 'EXCHANGE_REJECT', 'REJECT', 'CANCEL')");
        $st->execute([date('Y-m-d H:i:s', strtotime('-30 days'))]);
        return (int)$st->fetchColumn();
    } catch (PDOException $e) {
        return 0;
    }
}
