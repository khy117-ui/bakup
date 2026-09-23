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
    'track_tick'       => [null, null],   // 자동 화물추적 신호 — 로그인한 누구의 화면이든 뒤에서 부름
    'live_feed'        => [null, null],   // 실시간 알림 신호 — 안에서 화면별 볼 권한을 다시 확인
    'delete_requests'  => [null, null],

    'companies'        => ['master.company.read', 'master.company.write'],
    'company_form'     => ['master.company.read', 'master.company.write'],
    'company_contacts' => ['master.company.read', 'master.company.write'],
    'carriers'         => ['master.carrier.read', 'master.carrier.write'],
    // 도착지는 전표를 쓰는 사람이 늘리고 고칩니다
    'destinations'     => ['sales.voucher.read', 'sales.voucher.write'],
    'rate_table'       => ['master.rate.read', 'master.rate.write'],
    'company_terms'    => ['master.rate.read', 'master.discount.write'],

    'quotations'       => ['sales.quote.read', 'sales.quote.write'],
    'quotation_form'   => ['sales.quote.read', 'sales.quote.write'],
    'quotation_print'  => ['sales.quote.read', 'sales.quote.write'],
    'statements'       => ['sales.statement.read', 'sales.statement.write'],
    'statement_print'  => ['sales.statement.read', 'sales.statement.write'],
    'rate_calculator'  => ['sales.calc.use', 'sales.calc.use'],
    'rate_quote'       => ['sales.voucher.read', 'sales.voucher.read'],  // 매출전표의 단가 자동계산 (JSON)

    'shipments'        => ['sales.voucher.read', 'sales.voucher.write'],
    'shipment_form'    => ['sales.voucher.read', 'sales.voucher.write'],
    'web_pickups'      => ['sales.voucher.read', 'sales.voucher.write'],   // 홈페이지 온라인 접수
    'awb_list'         => ['logi.awb.read', 'logi.awb.write'],
    'awb_label'        => ['logi.awb.read', 'logi.awb.write'],
    'hawb_list'        => ['logi.awb.read', 'logi.awb.write'],   // 항공 하우스 비엘 (HAWB)
    'hawb_form'        => ['logi.awb.read', 'logi.awb.write'],
    'hawb_print'       => ['logi.awb.read', 'logi.awb.read'],
    'hawb_batches'     => ['logi.awb.read', 'logi.awb.write'],  // 수입 서류 (항공편 적하목록)
    'hawb_export'      => ['logi.awb.read', 'logi.awb.read'],
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
    'error_log'        => ['@super', '@super'],   // 오류 기록 — 최고관리자만
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
    // 홈페이지 게시판(board_post) 글 삭제 = 휴지통으로
    'boards'           => ['hide'      => ['label' => '홈페이지 게시글 삭제', 'id_from' => 'post:post_id', 'reason' => 'reason',
                                           'name_sql' => 'SELECT title FROM board_post WHERE id = ?'],
                           'hide_many' => ['label' => '홈페이지 게시글 여러 개 삭제', 'id_from' => 'post:first_id', 'reason' => 'reason',
                                           'name_sql' => "SELECT CONCAT(title, ' 외') FROM board_post WHERE id = ?"]],
    'companies'        => ['delete' => ['label' => '거래처 삭제', 'id_from' => 'post:id', 'reason' => 'reason',
                                        'name_sql' => "SELECT CONCAT(name_ko, ' (', company_code, ')') FROM companies WHERE id = ?"]],
    'company_contacts' => ['remove' => ['label' => '업체 담당자 내리기', 'id_from' => 'post:id', 'reason' => '',
                                        'name_sql' => 'SELECT name FROM company_contacts WHERE id = ?']],
    'documents'        => ['remove' => ['label' => '문서 내리기', 'id_from' => 'post:id', 'reason' => '',
                                        'name_sql' => 'SELECT title FROM documents WHERE id = ?']],
    // 매출전표 삭제 — 목록의 휴지통. 청구 · 입금 · 지급된 매입이 없을 때만 (shipments.php 에서 다시 확인)
    'shipments'        => ['delete' => ['label' => '매출전표 삭제', 'id_from' => 'post:id', 'reason' => 'reason',
                                        'name_sql' => 'SELECT awb_no FROM shipments WHERE id = ?']],
    'hawb_list'        => ['delete' => ['label' => 'HAWB 삭제', 'id_from' => 'post:id', 'reason' => 'reason',
                                        'name_sql' => 'SELECT house_no FROM hawbs WHERE id = ?']],
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

/** 도착지 마스터 — 매출전표 도착지를 목록에서 고르게 (shipments.dest_city 표기와 이름으로 맞춤) */
function schema_upgrade_dest(): void
{
    if (!empty($_SESSION['schema_dest_v1'])) { return; }
    try {
        db()->exec("CREATE TABLE IF NOT EXISTS destinations (
                      id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                      name         VARCHAR(50)  NOT NULL COMMENT '전표에 적히는 표기 (옛 ARRIVAL_N) — shipments.dest_city',
                      name_ko      VARCHAR(50)  NULL,
                      country_code CHAR(2)      NULL     COMMENT 'ISO 2자리 — 운송사 Zone 과 맞춤',
                      memo         VARCHAR(200) NULL,
                      is_active    TINYINT(1)   NOT NULL DEFAULT 1,
                      created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                      updated_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                      PRIMARY KEY (id),
                      UNIQUE KEY uq_dest_name (name),
                      KEY ix_dest_country (country_code)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                      COMMENT='도착지 목록'");
        $_SESSION['schema_dest_v1'] = 1;
    } catch (PDOException $e) {
        error_log('도착지 표 준비 실패: ' . $e->getMessage());
    }
}

/**
 * 파일 저장소 (app/filestore.php) — 서류 저장 위치 칸 · 공개 이미지 표 · 환경설정 '파일 저장소' 항목
 * 파일 자체는 DB 에 넣지 않습니다. DB 에는 상대경로 · 메타데이터만.
 */
function schema_upgrade_filestore(): void
{
    if (!empty($_SESSION['schema_fs_v2'])) { return; }
    $pdo = db();
    try {
        $has = $pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS
                             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'documents' AND COLUMN_NAME = 'storage'")
                   ->fetchColumn();
        if (!(int)$has) {
            $pdo->exec("ALTER TABLE documents
                          ADD COLUMN storage VARCHAR(10) NOT NULL DEFAULT 'LOCAL'
                              COMMENT 'LOCAL 이 서버에만 / NAS 파일 서버에 있음(서버 사본은 설정에 따라)' AFTER stored_path,
                          ADD KEY ix_doc_storage (storage)");
        }
        $pdo->exec("CREATE TABLE IF NOT EXISTS public_files (
                      id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                      rel_path        VARCHAR(300) NOT NULL COMMENT '공개 구역 기준 상대경로 — 주소 = 공개 기준 주소 + / + rel_path',
                      original_name   VARCHAR(255) NOT NULL,
                      mime_type       VARCHAR(100) NULL,
                      size_bytes      BIGINT UNSIGNED NULL,
                      width           INT NULL,
                      height          INT NULL,
                      checksum_sha256 CHAR(64) NULL,
                      purpose         VARCHAR(50) NULL COMMENT '홈페이지 · 메일 · 로고 등 메모',
                      uploaded_by     BIGINT UNSIGNED NULL,
                      created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                      deleted_at      DATETIME NULL,
                      PRIMARY KEY (id),
                      UNIQUE KEY uq_pf_path (rel_path)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='공개 이미지 (NAS 웹 폴더)'");
        $rows = [
            ['fs_mode', 'LOCAL', '저장 방식', 'LOCAL = 이 서버에만 · NAS = 집 시놀로지 NAS(WebDAV)에 저장. NAS 가 잠시 안 붙으면 서버에 두었다가 자동으로 다시 보냅니다.', 'select', 'LOCAL,NAS', 1],
            ['fs_webdav_url', 'https://frugen.synology.me:5006', 'NAS WebDAV 주소', 'https:// 로 시작. 시놀로지 WebDAV Server 의 HTTPS 주소 · 포트 (예 https://frugen.synology.me:5006)', 'text', null, 2],
            ['fs_webdav_user', null, 'NAS 계정', 'ERP 전용으로 만든 NAS 계정 (erp · web/images 폴더에만 권한)', 'text', null, 3],
            ['fs_webdav_pass', null, 'NAS 비밀번호', 'ERP 전용 계정의 비밀번호', 'secret', null, 4],
            ['fs_dir_docs', '/erp/documents', '비공개 · 업무 서류 폴더', 'WebDAV 기준 경로 (공유폴더/하위폴더). 웹에 공개하지 않는 공유폴더여야 합니다.', 'text', null, 5],
            ['fs_dir_uploads', '/erp/uploads', '비공개 · 기타 첨부 폴더', '직인 · 거래처 서류 등. 역시 웹에 공개하지 않는 폴더.', 'text', null, 6],
            ['fs_dir_public', '/web/images/erp', '공개 이미지 폴더', 'NAS 웹서버가 내주는 폴더 안 (WebDAV 기준 경로)', 'text', null, 7],
            ['fs_public_base_url', 'https://frugen.synology.me:8443/images/erp', '공개 이미지 기준 주소', '위 공개 폴더가 인터넷에서 보이는 주소. 바꾸면 모든 공개 이미지 주소가 같이 바뀝니다.', 'text', null, 8],
            ['fs_keep_local', '예', '서버에도 사본 유지', '예 = ERP 서버 · NAS 두 곳에 보관 (한쪽이 망가져도 남음) · 아니오 = NAS 에만', 'select', '예,아니오', 9],
            ['fs_dir_backup', '/backup/db', 'DB 백업 폴더', 'NAS 의 비공개 폴더 (WebDAV 기준 경로). 하루 한 번 DB 전체를 여기에 저장', 'text', null, 10],
            ['fs_backup_keep', '30', 'DB 백업 보관 개수', '이보다 오래된 백업은 NAS 에서 지웁니다 (하루 1개씩 쌓임)', 'number', null, 11],
        ];
        $ins = $pdo->prepare("INSERT IGNORE INTO app_settings
                                (setting_key, setting_val, group_ko, label_ko, help_ko, input_type, options_csv, sort_order)
                              VALUES (?, ?, '파일 저장소', ?, ?, ?, ?, ?)");
        foreach ($rows as $r) { $ins->execute($r); }
        $_SESSION['schema_fs_v2'] = 1;
    } catch (PDOException $e) {
        error_log('파일 저장소 표 준비 실패: ' . $e->getMessage());
    }
}

/**
 * 알림 (메일 · 카톡) 설정 자리 — 홈페이지 온라인 접수 알림 등 (app/notify.php)
 * 비밀번호 · 키는 사용자가 환경설정에서 직접 넣습니다.
 */
function schema_upgrade_notify(): void
{
    if (!empty($_SESSION['schema_notify_v1'])) { return; }
    $rows = [
        ['notify_staff_emails', 'info@good-post.co.kr', '담당자 받는 메일', '온라인 접수가 들어오면 알림 받을 메일 (여러 개는 쉼표로)', 'text', null, 1],
        ['notify_staff_phones', null, '담당자 휴대폰', '온라인 접수 카톡(알림톡) · 문자 받을 번호 (여러 개는 쉼표로)', 'text', null, 2],
        ['notify_customer_kakao', '예', '고객에게도 카톡 · 문자', '예 = 접수 · 확인 때 고객 휴대폰으로도 보냄 (이메일은 적은 경우 항상 보냄)', 'select', '예,아니오', 3],
        ['smtp_host', 'smtp.gmail.com', '메일 서버(SMTP) 주소', '예) smtp.gmail.com · smtp.naver.com · smtp.daum.net · 회사 메일 서버', 'text', null, 10],
        ['smtp_port', '465', '메일 서버 포트', '465 = SSL (권장) · 587 = STARTTLS', 'text', null, 11],
        ['smtp_user', null, '메일 계정', '보내는 메일 계정 (보통 메일 주소 전체)', 'text', null, 12],
        ['smtp_pass', null, '메일 비밀번호 (앱 비밀번호)', '구글 · 네이버는 2단계 인증 후 발급하는 앱 비밀번호', 'secret', null, 13],
        ['smtp_from', null, '보내는 메일 주소', '비우면 메일 계정으로 보냄', 'text', null, 14],
        ['smtp_from_name', '굿배송항공 GOODPOST', '보내는 사람 이름', '받는 사람 메일함에 보이는 이름', 'text', null, 15],
        ['solapi_api_key', null, 'SOLAPI API Key', 'solapi.com 콘솔 → API Key 관리 (카톡 알림톡 · 문자 발송)', 'text', null, 20],
        ['solapi_api_secret', null, 'SOLAPI API Secret', '같은 화면의 API Secret', 'secret', null, 21],
        ['solapi_sender', '02-6929-0666', '보내는 번호 (발신번호)', 'SOLAPI 에 등록 · 인증한 회사 번호', 'text', null, 22],
        ['solapi_pfid', null, '카카오 채널 pfId', 'SOLAPI 에 연결한 카카오톡 채널 ID (KA01PF…). 비우면 문자로만', 'text', null, 23],
        ['solapi_tpl_pickup_staff', null, '알림톡 템플릿 — 담당자 새 접수', '승인된 템플릿 ID (KA01TP…). 비우면 문자', 'text', null, 24],
        ['solapi_tpl_pickup_received', null, '알림톡 템플릿 — 고객 접수 안내', '승인된 템플릿 ID. 비우면 문자', 'text', null, 25],
        ['solapi_tpl_pickup_confirm', null, '알림톡 템플릿 — 고객 확인 안내', '승인된 템플릿 ID. 비우면 문자', 'text', null, 26],
    ];
    try {
        $ins = db()->prepare("INSERT IGNORE INTO app_settings
                                (setting_key, setting_val, group_ko, label_ko, help_ko, input_type, options_csv, sort_order)
                              VALUES (?, ?, '알림 (메일 · 카톡)', ?, ?, ?, ?, ?)");
        foreach ($rows as $r) { $ins->execute($r); }
        require_once APP_DIR . '/pickups.php';
        pickup_ensure_table(db());
        $_SESSION['schema_notify_v1'] = 1;
    } catch (PDOException $e) {
        error_log('알림 설정 자리 추가 실패: ' . $e->getMessage());
    }
}

/** 우체국 Open API 인증키 자리 — 환경설정 '연동' 에서 넣습니다 (비밀값: 화면에 다시 안 보임) */
function schema_upgrade_epost(): void
{
    if (!empty($_SESSION['schema_epost_v3'])) { return; }
    try {
        db()->exec("INSERT IGNORE INTO app_settings
                      (setting_key, setting_val, group_ko, label_ko, help_ko, input_type, options_csv, sort_order)
                    VALUES ('epost_api_key', NULL, '연동', '우체국 Open API 인증키',
                            '공공데이터포털(data.go.kr)에서 \"우정사업본부_EMS행방조회 서비스\" 활용신청 후 받은 일반 인증키. 화물추적의 [우체국에서 이력 가져오기] 에 씁니다. 넣은 뒤에는 끝 4자리만 보입니다.',
                            'secret', NULL, 1)");
        db()->exec("INSERT IGNORE INTO app_settings
                      (setting_key, setting_val, group_ko, label_ko, help_ko, input_type, options_csv, sort_order)
                    VALUES ('dhl_api_key', NULL, '연동', 'DHL API 키 (Consumer Key)',
                            'developer.dhl.com 에서 Shipment Tracking - Unified 앱을 만들고 받은 API Key. 화물추적의 [DHL에서 이력 가져오기] 에 씁니다.',
                            'secret', NULL, 2)");
        db()->exec("INSERT IGNORE INTO app_settings
                      (setting_key, setting_val, group_ko, label_ko, help_ko, input_type, options_csv, sort_order)
                    VALUES ('fedex_api_key', NULL, '연동', 'FedEx API Key (Client ID)',
                            'developer.fedex.com 에서 Track API 프로젝트를 만들고 받은 Production API Key. Secret Key 와 같이 있어야 [FedEx에서 이력 가져오기] 가 됩니다.',
                            'secret', NULL, 3),
                           ('fedex_secret_key', NULL, '연동', 'FedEx Secret Key',
                            '같은 프로젝트의 Production Secret Key.', 'secret', NULL, 4)");
        $_SESSION['schema_epost_v3'] = 1;
    } catch (PDOException $e) {
        error_log('우체국 설정 자리 추가 실패: ' . $e->getMessage());
    }
}

/** 매출전표 관련서류용 '기타 서류' 종류 (없으면 추가) */
function schema_upgrade_docs(): void
{
    if (!empty($_SESSION['schema_docs_v2'])) { return; }
    try {
        db()->exec("INSERT INTO document_types (code, name, sort_order)
                    SELECT 'ETC', '기타 서류', 99 FROM DUAL
                     WHERE NOT EXISTS (SELECT 1 FROM document_types WHERE code = 'ETC')");
        // NAS 가 서류를 가져갈 때 쓰는 열쇠 — 해시만 저장, 원문은 만들 때 한 번만 보여줌
        db()->exec("CREATE TABLE IF NOT EXISTS backup_keys (
                      id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                      key_hash     CHAR(64)     NOT NULL,
                      label        VARCHAR(100) NULL,
                      created_by   BIGINT UNSIGNED NULL,
                      created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                      last_used_at DATETIME     NULL,
                      last_ip      VARCHAR(45)  NULL,
                      revoked_at   DATETIME     NULL,
                      PRIMARY KEY (id),
                      UNIQUE KEY uq_bk_hash (key_hash)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                      COMMENT='NAS 서류 백업 열쇠 (NAS 가 가져가는 방식)'");
        $_SESSION['schema_docs_v2'] = 1;
    } catch (PDOException $e) {
        error_log('문서 종류 추가 실패: ' . $e->getMessage());
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
