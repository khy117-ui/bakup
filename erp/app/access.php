<?php
declare(strict_types=1);

// 웹에서 직접 열면 실행되지 않게 막습니다 (nginx 면 .htaccess 가 무시됩니다)
if (!defined('APP_DIR')) { http_response_code(403); exit('Forbidden'); }

/**
 * 화면 접근 권한 · 삭제 승인.
 *
 *   · 화면마다 [보기 권한, 입력 권한] 을 정합니다. 보기 권한이 없으면 메뉴에서도 숨깁니다.
 *     POST(저장·수정·삭제)는 입력 권한이 있어야 합니다.
 *   · null = 로그인한 사람 누구나, '@super' = 최고관리자만. 여기 없는 화면은 최고관리자만.
 *   · 삭제 · 취소는 'sys.delete.direct' 권한이 없으면 바로 실행하지 않고 '삭제 요청' 으로 남깁니다.
 *     승인자가 승인하면 그때 요청 당시 내용 그대로 실행합니다 (delete_requests 화면).
 */
const ROUTE_PERMS = [
    'dashboard'        => [null, null],
    'search'           => [null, null],
    'my_account'       => [null, null],
    'delete_requests'  => [null, null],

    'companies'        => ['master.company.read', 'master.company.write'],
    'company_form'     => ['master.company.read', 'master.company.write'],
    'company_contacts' => ['master.company.read', 'master.company.write'],
    'carriers'         => ['master.carrier.read', 'master.carrier.write'],
    'rate_table'       => ['master.rate.read', 'master.rate.write'],
    'company_terms'    => ['master.rate.read', 'master.discount.write'],

    'quotations'       => ['sales.quote.read', 'sales.quote.write'],
    'quotation_form'   => ['sales.quote.read', 'sales.quote.write'],
    'quotation_print'  => ['sales.quote.read', 'sales.quote.write'],
    'statements'       => ['sales.statement.read', 'sales.statement.write'],
    'statement_print'  => ['sales.statement.read', 'sales.statement.write'],
    'rate_calculator'  => ['sales.calc.use', 'sales.calc.use'],

    'shipments'        => ['sales.voucher.read', 'sales.voucher.write'],
    'shipment_form'    => ['sales.voucher.read', 'sales.voucher.write'],
    'awb_list'         => ['logi.awb.read', 'logi.awb.write'],
    'awb_label'        => ['logi.awb.read', 'logi.awb.write'],
    'tracking'         => ['logi.tracking.read', 'logi.tracking.write'],
    'documents'        => ['logi.document.read', 'logi.document.write'],
    'file_download'    => ['logi.document.read', 'logi.document.write'],

    'sales_stats'      => ['sales.stats.read', 'sales.stats.read'],
    'purchases'        => ['acct.purchase.read', 'acct.purchase.write'],
    'profit'           => ['acct.profit.read', 'acct.profit.read'],
    'billing'          => ['acct.invoice.read', 'acct.invoice.write'],
    'invoice_view'     => ['acct.invoice.read', 'acct.invoice.write'],
    'invoice_print'    => ['acct.invoice.read', 'acct.invoice.write'],
    'tax_invoices'     => ['acct.tax.read', 'acct.tax.issue'],

    'cash_dashboard'   => ['CASH_VIEW', 'CASH_WRITE'],
    'cash_in'          => ['CASH_WRITE', 'CASH_WRITE'],
    'cash_out'         => ['CASH_WRITE', 'CASH_WRITE'],
    'cash_list'        => ['CASH_VIEW', 'CASH_CANCEL'],
    'receivables'      => ['CASH_VIEW', 'CASH_VIEW'],
    'ledger'           => ['LEDGER_VIEW', 'LEDGER_VIEW'],
    'opening_balances' => ['OPENING_MANAGE', 'OPENING_MANAGE'],
    'bank_import'      => ['BANK_IMPORT', 'BANK_IMPORT'],
    'accounts'         => ['ACCOUNT_MANAGE', 'ACCOUNT_MANAGE'],
    'cash_stats'       => ['CASH_VIEW', 'CASH_VIEW'],

    'boards'           => ['web.board.read', 'web.board.write'],

    'business_entity'  => ['sys.entity.write', 'sys.entity.write'],
    'permissions'      => ['sys.admin.read', 'sys.admin.write'],
    'activity_log'     => ['sys.log.read', 'sys.log.read'],
    'settings'         => ['sys.settings.write', 'sys.settings.write'],
    'storage_settings' => ['sys.storage.write', 'sys.storage.write'],
    'backup'           => ['sys.backup', 'sys.backup'],
    'migration'        => ['@super', '@super'],
    'migration_import' => ['@super', '@super'],
];

/**
 * 삭제 · 취소 동작. [화면][act] => 대상 id 를 어디서 읽는지 · 사유 칸 · 이름을 찾는 SQL
 *   id_from : 'post:필드' 또는 'get:필드'
 */
const DELETE_ACTIONS = [
    'boards'           => ['hide'   => ['label' => '게시글 숨기기', 'id_from' => 'post:post_id', 'reason' => 'reason',
                                        'name_sql' => 'SELECT title FROM posts WHERE id = ?']],
    'company_contacts' => ['remove' => ['label' => '업체 담당자 내리기', 'id_from' => 'post:id', 'reason' => '',
                                        'name_sql' => 'SELECT name FROM company_contacts WHERE id = ?']],
    'documents'        => ['remove' => ['label' => '문서 내리기', 'id_from' => 'post:id', 'reason' => '',
                                        'name_sql' => 'SELECT title FROM documents WHERE id = ?']],
    'shipment_form'    => ['cancel' => ['label' => '매출전표 취소', 'id_from' => 'get:id', 'reason' => 'cancel_reason',
                                        'name_sql' => 'SELECT awb_no FROM shipments WHERE id = ?']],
    'invoice_view'     => ['cancel' => ['label' => '청구서 취소', 'id_from' => 'get:id', 'reason' => 'reason',
                                        'name_sql' => 'SELECT invoice_no FROM invoices WHERE id = ?']],
    'tax_invoices'     => ['cancel' => ['label' => '세금계산서 취소', 'id_from' => 'post:id', 'reason' => 'reason',
                                        'name_sql' => 'SELECT doc_no FROM tax_invoices WHERE id = ?']],
    'cash_list'        => ['cancel' => ['label' => '입출금 취소(역분개)', 'id_from' => 'post:id', 'reason' => 'reason',
                                        'name_sql' => "SELECT CONCAT(doc_no, ' · ', FORMAT(amount, 0), '원')
                                                         FROM financial_transactions WHERE id = ?"]],
    'opening_balances' => ['cancel' => ['label' => '기초잔액 취소', 'id_from' => 'post:id', 'reason' => 'reason',
                                        'name_sql' => "SELECT CONCAT(COALESCE(counterparty_name, ''), ' · ',
                                                                     FORMAT(amount, 0), '원')
                                                         FROM opening_balances WHERE id = ?"]],
];

function is_super(): bool
{
    return ($_SESSION['role'] ?? '') === 'SUPER_ADMIN';
}

function perm_ok(?string $code): bool
{
    if ($code === null) { return true; }
    if ($code === '@super') { return is_super(); }
    return can($code);
}

function route_can_view(string $route): bool
{
    $p = ROUTE_PERMS[$route] ?? ['@super', '@super'];
    return perm_ok($p[0]);
}

function route_can_edit(string $route): bool
{
    $p = ROUTE_PERMS[$route] ?? ['@super', '@super'];
    return perm_ok($p[0]) && perm_ok($p[1]);
}

function delete_action(string $route, string $act): ?array
{
    return DELETE_ACTIONS[$route][$act] ?? null;
}

/** 권한이 없을 때 보여주는 화면 */
function deny_page(string $what): void
{
    http_response_code(403);
    if (!function_exists('layout_head')) { require_once APP_DIR . '/layout.php'; }
    layout_head('권한 없음', '');
    echo '<div class="msg err"><b>' . h($what) . '</b> 권한이 없습니다. 관리자에게 요청하세요.</div>';
    layout_foot();
    exit;
}

/**
 * 삭제 요청으로 바꿔 남깁니다 (승인 권한자가 아닌 사람이 삭제 · 취소를 눌렀을 때).
 * 요청 당시의 POST 내용과 주소를 그대로 저장해 두었다가, 승인하면 그대로 다시 실행합니다.
 */
function delete_request_create(string $route, string $act, array $spec): void
{
    [$src, $field] = explode(':', $spec['id_from'], 2);
    $targetId = (int)($src === 'get' ? ($_GET[$field] ?? 0) : ($_POST[$field] ?? 0));
    $reason = $spec['reason'] !== '' ? trim((string)($_POST[$spec['reason']] ?? '')) : '';

    $name = null;
    if ($targetId > 0) {
        try {
            $st = db()->prepare($spec['name_sql']);
            $st->execute([$targetId]);
            $name = $st->fetchColumn();
        } catch (PDOException $e) {
            $name = null;
        }
    }
    $label = $spec['label'] . ' — ' . ($name !== null && $name !== false && $name !== '' ? $name : '#' . $targetId);

    $payload = $_POST;
    unset($payload['_csrf']);
    $query = $_GET;

    $back = '?' . http_build_query($query);

    // 같은 대상에 이미 대기 중인 요청이 있으면 또 만들지 않습니다
    $dup = db()->prepare("SELECT id FROM delete_requests
                           WHERE status = 'PENDING' AND route = ? AND act = ? AND target_id = ?");
    $dup->execute([$route, $act, $targetId]);
    if ($dup->fetchColumn()) {
        flash('이미 삭제 요청이 올라가 있습니다. 관리자 승인을 기다리세요.');
        redirect($back);
    }

    db()->prepare(
        'INSERT INTO delete_requests
           (route, act, target_id, target_label, reason, payload_json, query_json,
            requested_by, requested_name)
         VALUES (?,?,?,?,?,?,?,?,?)')
        ->execute([$route, $act, $targetId, mb_substr($label, 0, 250), $reason !== '' ? mb_substr($reason, 0, 500) : null,
                   json_encode($payload, JSON_UNESCAPED_UNICODE), json_encode($query, JSON_UNESCAPED_UNICODE),
                   (int)($_SESSION['admin_id'] ?? 0), $_SESSION['admin_name'] ?? null]);
    $rid = (int)db()->lastInsertId();
    log_action('삭제요청', 'REQUEST', 'delete_requests', $rid, $label, null, null, $reason ?: null);
    flash('삭제 요청을 올렸습니다. 관리자가 승인하면 처리됩니다. (시스템 > 삭제 요청 · 승인)');
    redirect($back);
}

/** 승인 대기 건수 (메뉴 표시용) */
function delete_request_pending(): int
{
    try {
        return (int)db()->query("SELECT COUNT(*) FROM delete_requests WHERE status = 'PENDING'")->fetchColumn();
    } catch (PDOException $e) {
        return 0;
    }
}

/**
 * 뷰(미수금 · 원장 등) 를 올바른 collation 으로 다시 만듭니다.
 *
 * MySQL 은 뷰를 만들 때의 연결 collation 을 뷰 안의 글자 상수('CANCELLED' 등)에 박아 둡니다.
 * 처음 설치할 때 연결이 utf8mb4_0900_ai_ci 여서, 화면 쿼리의 상수(utf8mb4_unicode_ci)와 비교하면
 * 'Illegal mix of collations (…COERCIBLE)' 로 멈췄습니다 (입금등록 등).
 * 서버의 sql/install.sql 에 있는 CREATE OR REPLACE VIEW 문장만, 파일 순서대로 다시 실행합니다.
 */
function schema_fix_view_collation(): void
{
    if (!empty($_SESSION['views_collation_ok'])) { return; }
    $pdo = db();
    try {
        $bad = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.VIEWS
                                  WHERE TABLE_SCHEMA = DATABASE()
                                    AND COLLATION_CONNECTION <> 'utf8mb4_unicode_ci'")->fetchColumn();
        if ($bad > 0) {
            $file = dirname(APP_DIR) . '/sql/install.sql';
            $sql = is_file($file) ? (string)file_get_contents($file) : '';
            $n = 0;
            foreach (sql_split($sql) as $st) {
                if (preg_match('/^CREATE\s+OR\s+REPLACE\s+VIEW\s+v_\w+\s+AS\b/i', $st)) {
                    $pdo->exec($st);
                    $n++;
                }
            }
            error_log("뷰 collation 정리: {$bad}개가 어긋나 있어 {$n}개 문장을 다시 실행했습니다");
            $left = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.VIEWS
                                       WHERE TABLE_SCHEMA = DATABASE()
                                         AND COLLATION_CONNECTION <> 'utf8mb4_unicode_ci'")->fetchColumn();
            if ($left > 0) { return; }
        }
        $_SESSION['views_collation_ok'] = 1;
    } catch (PDOException $e) {
        error_log('뷰 collation 정리 실패: ' . $e->getMessage());
    }
}

/**
 * 계정 · 삭제승인용 표와 권한을 DB 에 붙입니다. 이미 붙어 있으면 아무것도 안 합니다.
 * 설치 SQL 을 다시 돌리지 않아도 되게, 정해진 문장만 여기서 실행합니다.
 */
function schema_upgrade_accounts(): void
{
    if (!empty($_SESSION['schema_accounts_v1'])) { return; }
    $pdo = db();
    $has = function (string $table, ?string $col = null) use ($pdo): bool {
        if ($col === null) {
            $st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES
                                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
            $st->execute([$table]);
        } else {
            $st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS
                                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
            $st->execute([$table, $col]);
        }
        return (int)$st->fetchColumn() > 0;
    };
    try {
        if (!$has('admins')) { return; }   // 아직 설치 전
        if (!$has('admins', 'must_change_pw')) {
            $pdo->exec("ALTER TABLE admins ADD COLUMN must_change_pw TINYINT(1) NOT NULL DEFAULT 0
                          COMMENT '1 = 다음 로그인 때 비밀번호를 바꿔야 함' AFTER password_hash");
        }
        if (!$has('admins', 'perm_custom')) {
            $pdo->exec("ALTER TABLE admins ADD COLUMN perm_custom TINYINT(1) NOT NULL DEFAULT 0
                          COMMENT '1 = 역할 대신 계정별 권한(admin_permissions)을 씀' AFTER role_code");
        }
        if (!$has('admin_permissions')) {
            $pdo->exec("CREATE TABLE admin_permissions (
                          admin_id      BIGINT UNSIGNED NOT NULL,
                          permission_id BIGINT UNSIGNED NOT NULL,
                          PRIMARY KEY (admin_id, permission_id),
                          CONSTRAINT fk_ap_admin FOREIGN KEY (admin_id) REFERENCES admins(id) ON DELETE CASCADE,
                          CONSTRAINT fk_ap_perm FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE
                        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                          COMMENT='계정별 권한 — admins.perm_custom = 1 인 계정만 씀'");
        }
        if (!$has('delete_requests')) {
            $pdo->exec("CREATE TABLE delete_requests (
                          id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                          route          VARCHAR(40)  NOT NULL COMMENT '화면',
                          act            VARCHAR(30)  NOT NULL COMMENT '동작 (remove / cancel / hide)',
                          target_id      BIGINT       NOT NULL DEFAULT 0,
                          target_label   VARCHAR(255) NULL,
                          reason         VARCHAR(500) NULL,
                          payload_json   MEDIUMTEXT   NOT NULL COMMENT '요청 당시 보낸 내용 — 승인하면 그대로 다시 실행',
                          query_json     TEXT         NOT NULL,
                          status         VARCHAR(12)  NOT NULL DEFAULT 'PENDING'
                                         COMMENT 'PENDING 대기 / DONE 처리됨 / FAILED 처리 실패 / REJECTED 반려 / WITHDRAWN 철회',
                          requested_by   BIGINT UNSIGNED NOT NULL,
                          requested_name VARCHAR(50)  NULL,
                          requested_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                          decided_by     BIGINT UNSIGNED NULL,
                          decided_name   VARCHAR(50)  NULL,
                          decided_at     DATETIME     NULL,
                          decision_note  VARCHAR(500) NULL,
                          PRIMARY KEY (id),
                          KEY ix_dr_status (status, requested_at),
                          KEY ix_dr_target (route, act, target_id)
                        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                          COMMENT='삭제 · 취소 승인 요청'");
        }
        $pdo->exec("INSERT INTO permissions (code, group_ko, name_ko) VALUES
                      ('sys.delete.direct',   '시스템',   '삭제·취소 바로 실행 (승인 없이)'),
                      ('sys.delete.approve',  '시스템',   '삭제·취소 요청 승인'),
                      ('sys.settings.write',  '시스템',   '환경설정 수정'),
                      ('sys.backup',          '시스템',   '백업 / 복구'),
                      ('logi.tracking.write', '물류관리', '배송추적 등록/완료처리')
                    ON DUPLICATE KEY UPDATE name_ko = VALUES(name_ko)");
        // 배송추적 입력은 원래 물류 · 관리자 역할이 하던 일이라 그대로 이어 줍니다
        $pdo->exec("INSERT IGNORE INTO role_permissions (role_code, permission_id)
                    SELECT r.role_code, p.id
                      FROM permissions p
                      JOIN (SELECT 'MANAGER' AS role_code UNION ALL SELECT 'LOGISTICS') r
                     WHERE p.code = 'logi.tracking.write'");
        $_SESSION['schema_accounts_v1'] = 1;
    } catch (PDOException $e) {
        error_log('계정·승인 표 준비 실패: ' . $e->getMessage());
    }
}
