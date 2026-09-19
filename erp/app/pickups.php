<?php
declare(strict_types=1);

// 웹에서 직접 열면 실행되지 않게 막습니다 (nginx 면 .htaccess 가 무시됩니다)
if (!defined('APP_DIR')) { http_response_code(403); exit('Forbidden'); }

require_once APP_DIR . '/notify.php';

/**
 * 홈페이지 온라인 접수(픽업 예약) — 공개 입구(pickup.php)와 ERP 화면(web_pickups)이 같이 씁니다.
 *   접수 → web_pickups 표에 저장 → 담당자에게 메일 · 카톡, 고객에게 '접수되었습니다' 메일 · 카톡
 *   ERP 에서 [접수 확인] 을 누르면 고객에게 확인 연락(메일 · 카톡)이 한 번 더 갑니다.
 */

const PICKUP_STATUS = ['NEW' => ['새 접수', 'b-warn'], 'CONFIRMED' => ['확인', 'b-info'],
                       'DONE' => ['픽업 완료', 'b-ok'], 'CANCELLED' => ['취소', 'b-err']];

function pickup_ensure_table(PDO $pdo): void
{
    static $done = false;
    if ($done) { return; }
    $pdo->exec("CREATE TABLE IF NOT EXISTS web_pickups (
                  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                  req_no        VARCHAR(20)  NOT NULL COMMENT '접수번호 P260919-001',
                  pickup_type   VARCHAR(10)  NOT NULL COMMENT '내부픽업 / 외부픽업',
                  company_name  VARCHAR(100) NOT NULL,
                  manager       VARCHAR(50)  NOT NULL,
                  phone         VARCHAR(30)  NOT NULL,
                  email         VARCHAR(120) NULL,
                  pickup_date   DATE         NULL,
                  pickup_time   VARCHAR(10)  NULL,
                  area          VARCHAR(20)  NULL,
                  address       VARCHAR(255) NOT NULL,
                  weight_kg     VARCHAR(20)  NULL,
                  ship_mode     VARCHAR(10)  NULL COMMENT 'AIR / SEA / COB',
                  pay_terms     VARCHAR(10)  NULL COMMENT 'Prepaid / Collect',
                  memo          TEXT         NULL,
                  status        VARCHAR(12)  NOT NULL DEFAULT 'NEW',
                  staff_memo    TEXT         NULL,
                  company_id    BIGINT UNSIGNED NULL COMMENT 'ERP 거래처로 연결했으면',
                  handled_by    BIGINT UNSIGNED NULL,
                  handled_at    DATETIME     NULL,
                  notify_log    TEXT         NULL COMMENT '메일 · 카톡 보낸 결과',
                  ip            VARCHAR(45)  NULL,
                  user_agent    VARCHAR(255) NULL,
                  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                  PRIMARY KEY (id),
                  UNIQUE KEY uq_pk_no (req_no),
                  KEY ix_pk_status (status, created_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='홈페이지 온라인 접수(픽업 예약)'");
    $done = true;
}

/** 접수번호 P + 날짜 + 그날 순번 */
function pickup_next_no(PDO $pdo): string
{
    $prefix = 'P' . date('ymd') . '-';
    $st = $pdo->prepare('SELECT COUNT(*) FROM web_pickups WHERE req_no LIKE ?');
    $st->execute([$prefix . '%']);
    return $prefix . sprintf('%03d', (int)$st->fetchColumn() + 1);
}

function pickup_when(array $p): string
{
    return trim(($p['pickup_date'] ?? '') . ' ' . ($p['pickup_time'] ?? '')) ?: '날짜 미정';
}

/** 담당자에게 가는 글 */
function pickup_staff_text(array $p): string
{
    return "[GOODPOST 온라인 접수] " . $p['req_no'] . "\n"
         . "구분: " . $p['pickup_type'] . " · " . ($p['ship_mode'] ?: '-') . " · " . ($p['pay_terms'] ?: '-') . "\n"
         . "회사: " . $p['company_name'] . "\n"
         . "담당: " . $p['manager'] . " " . $p['phone'] . ($p['email'] ? " · " . $p['email'] : '') . "\n"
         . "픽업: " . pickup_when($p) . " · " . ($p['area'] ?: '') . "\n"
         . "주소: " . $p['address'] . "\n"
         . "무게: " . ($p['weight_kg'] ?: '-') . " kg\n"
         . ($p['memo'] ? "비고: " . $p['memo'] . "\n" : '')
         . "→ ERP 물류관리 > 온라인 접수에서 확인하세요.";
}

/** 고객에게 가는 글 — 접수됨 / 확인됨 */
function pickup_customer_text(array $p, string $kind, string $staffMsg = ''): string
{
    $head = $kind === 'confirm'
        ? "[굿배송항공] 픽업 예약이 확인되었습니다."
        : "[굿배송항공] 픽업 예약 신청이 접수되었습니다.";
    return $head . "\n"
         . "접수번호: " . $p['req_no'] . "\n"
         . "회사명: " . $p['company_name'] . "\n"
         . "픽업일시: " . pickup_when($p) . "\n"
         . "주소: " . $p['address'] . "\n"
         . ($staffMsg !== '' ? "안내: " . $staffMsg . "\n" : '')
         . ($kind === 'confirm' ? '' : "담당자가 확인 후 다시 연락드립니다.\n")
         . "문의 02-6929-0666 · 카카오톡 상담";
}

/** 알림톡 템플릿 변수 — SOLAPI 에 템플릿을 등록할 때 이 이름을 그대로 쓰세요 */
function pickup_vars(array $p, string $staffMsg = ''): array
{
    return ['#{접수번호}' => $p['req_no'], '#{회사명}' => $p['company_name'], '#{담당자}' => $p['manager'],
            '#{연락처}' => $p['phone'], '#{픽업일시}' => pickup_when($p), '#{주소}' => $p['address'],
            '#{무게}' => ($p['weight_kg'] ?: '-'), '#{안내}' => ($staffMsg !== '' ? $staffMsg : '-')];
}

/** 알림 결과를 접수 건에 한 줄씩 남깁니다 */
function pickup_log(PDO $pdo, int $id, string $line): void
{
    $pdo->prepare("UPDATE web_pickups SET notify_log = CONCAT(COALESCE(notify_log, ''), ?) WHERE id = ?")
        ->execute([date('m-d H:i') . ' ' . $line . "\n", $id]);
}

/** 새 접수 알림 — 담당자(메일 · 카톡) + 고객(메일 · 카톡). 실패해도 접수는 그대로 */
function pickup_notify_new(PDO $pdo, array $p): void
{
    $c = notify_cfg();
    $id = (int)$p['id'];
    $sub = '[온라인 접수] ' . $p['req_no'] . ' ' . $p['company_name'] . ' · ' . pickup_when($p);
    if (($c['notify_staff_emails'] ?? '') !== '') {
        [$ok, $m] = notify_mail($c['notify_staff_emails'], $sub, pickup_staff_text($p), (string)($p['email'] ?? ''));
        pickup_log($pdo, $id, '담당자 메일 ' . ($ok ? 'OK' : '실패') . ' · ' . $m);
    }
    if (($c['notify_staff_phones'] ?? '') !== '') {
        [$ok, $m] = notify_kakao($c['notify_staff_phones'], pickup_staff_text($p), 'solapi_tpl_pickup_staff', pickup_vars($p));
        pickup_log($pdo, $id, '담당자 카톡 ' . ($ok ? 'OK' : '실패') . ' · ' . $m);
    }
    pickup_notify_customer($pdo, $p, 'received');
}

/** 고객 알림 — received(접수됨) / confirm(확인됨, 담당자 안내 포함) */
function pickup_notify_customer(PDO $pdo, array $p, string $kind, string $staffMsg = ''): array
{
    $c = notify_cfg();
    $id = (int)$p['id'];
    $out = [];
    $label = $kind === 'confirm' ? '확인' : '접수';
    $text = pickup_customer_text($p, $kind, $staffMsg);
    if (($p['email'] ?? '') !== '' && filter_var($p['email'], FILTER_VALIDATE_EMAIL)) {
        [$ok, $m] = notify_mail((string)$p['email'], '[굿배송항공] 픽업 예약 ' . $label . ' 안내 (' . $p['req_no'] . ')', $text,
                                (string)(notify_list($c['notify_staff_emails'] ?? '')[0] ?? ''));
        pickup_log($pdo, $id, '고객 ' . $label . ' 메일 ' . ($ok ? 'OK' : '실패') . ' · ' . $m);
        $out[] = ($ok ? '메일 보냄' : '메일 실패');
    }
    if (($c['notify_customer_kakao'] ?? '예') !== '아니오' && notify_phone((string)$p['phone']) !== '') {
        [$ok, $m] = notify_kakao((string)$p['phone'], $text,
                                 $kind === 'confirm' ? 'solapi_tpl_pickup_confirm' : 'solapi_tpl_pickup_received',
                                 pickup_vars($p, $staffMsg));
        pickup_log($pdo, $id, '고객 ' . $label . ' 카톡 ' . ($ok ? 'OK' : '실패') . ' · ' . $m);
        $out[] = ($ok ? '카톡 · 문자 보냄' : '카톡 · 문자 실패');
    }
    return $out;
}
