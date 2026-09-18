<?php
declare(strict_types=1);

define('APP_DIR', __DIR__);

/** assets/ 파일 주소에 수정시각을 붙인다 — 휴대폰이 예전 CSS 를 계속 쓰지 않게 */
function asset_v(string $path): string
{
    $t = @filemtime(dirname(APP_DIR) . '/' . $path);
    return $path . ($t ? '?v=' . $t : '');
}

/**
 * 설정 읽기.
 *   1순위  app/config.local.php   (직접 올린 서버)
 *   2순위  환경변수               (AISpace 등 DB 정보를 주입해 주는 환경)
 *
 * AISpace 가 넣어 주는 이름은 DB_USER 입니다 (DB_USERNAME 아님).
 */
$localCfg = APP_DIR . '/config.local.php';
if (is_file($localCfg)) {
    $CFG = require $localCfg;
} else {
    $envName = getenv('DB_NAME') ?: '';
    if ($envName === '') {
        http_response_code(500);
        exit('설정이 없습니다. app/config.sample.php 를 app/config.local.php 로 복사해 채우거나, '
           . 'DB_HOST · DB_NAME · DB_USER · DB_PASSWORD 환경변수를 설정하세요.');
    }
    $CFG = [
        'db' => [
            'host' => getenv('DB_HOST') ?: '127.0.0.1',
            'port' => (int)(getenv('DB_PORT') ?: 3306),
            'name' => $envName,
            'user' => getenv('DB_USER') ?: '',
            'pass' => getenv('DB_PASSWORD') ?: '',
        ],
        'entity_code'    => getenv('GP_ENTITY_CODE') ?: 'GPA',
        'login_max_fail' => (int)(getenv('GP_LOGIN_MAX_FAIL') ?: 5),
        'login_lock_min' => (int)(getenv('GP_LOGIN_LOCK_MIN') ?: 10),
        'app_name'       => getenv('GP_APP_NAME') ?: 'GOODPOST 통합 업무관리 시스템',
    ];
}

mb_internal_encoding('UTF-8');
date_default_timezone_set('Asia/Seoul');

// ---------------------------------------------------------------- 세션
if (session_status() !== PHP_SESSION_ACTIVE) {
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
          || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    session_set_cookie_params([
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => $https,
        'path'     => '/',
    ]);
    session_name('GPSESS');
    session_start();
}

// ---------------------------------------------------------------- DB
function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    global $CFG;
    $d = $CFG['db'];
    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                   $d['host'], (int)$d['port'], $d['name']);
    try {
        $pdo = new PDO($dsn, $d['user'], $d['pass'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            // 진짜 prepared statement 를 씁니다. 에뮬레이션을 끄지 않으면
            // 드라이버가 문자열을 조립하므로 인젝션 방어가 약해집니다
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
        // 표는 전부 utf8mb4_unicode_ci 입니다. MySQL 8 은 연결 기본값이 utf8mb4_0900_ai_ci 라서
        // CAST(... AS CHAR) · CONCAT 결과와 표 컬럼을 비교하면 'Illegal mix of collations' 가 납니다.
        // 이 문장이 실패해도 화면은 떠야 하니 기록만 남기고 넘어갑니다.
        try {
            $pdo->exec('SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci');
        } catch (PDOException $e) {
            error_log('SET NAMES 실패(무시): ' . $e->getMessage());
        }
    } catch (PDOException $e) {
        http_response_code(500);
        error_log('DB 접속 실패: ' . $e->getMessage());
        exit('데이터베이스에 접속할 수 없습니다. 관리자에게 문의하세요.');
    }
    return $pdo;
}

/**
 * 업로드 문서를 둘 폴더.
 *
 * AISpace 같은 환경에서는 /app/user_data 안에 있는 파일만 재배포 후에도 남습니다.
 * 그 폴더가 있으면 거기에, 없으면 프로젝트 안 storage/ 에 둡니다.
 * (주석 안에서 별표 두 개 뒤에 슬래시를 붙이면 주석이 거기서 끝나 버립니다)
 */
function storage_root(): string
{
    static $root = null;
    if ($root !== null) {
        return $root;
    }
    $candidates = ['/app/user_data', dirname(APP_DIR) . DIRECTORY_SEPARATOR . 'storage'];
    foreach ($candidates as $base) {
        if (is_dir($base) && is_writable($base)) {
            return $root = $base . DIRECTORY_SEPARATOR . 'documents';
        }
    }
    return $root = dirname(APP_DIR) . DIRECTORY_SEPARATOR . 'storage'
                 . DIRECTORY_SEPARATOR . 'documents';
}

/** SQL 파일을 문장으로 나눕니다. 따옴표 안 · 주석 · DELIMITER 를 압니다 (서버의 sql/ 파일 전용) */
function sql_split(string $sql): array
{
    $out = [];
    $buf = '';
    $len = strlen($sql);
    $delim = ';';
    $i = 0;
    while ($i < $len) {
        if (($i === 0 || $sql[$i - 1] === "\n") && strncasecmp(substr($sql, $i, 10), 'DELIMITER ', 10) === 0) {
            $j = strpos($sql, "\n", $i);
            $j = $j === false ? $len : $j;
            $delim = trim(substr($sql, $i + 10, $j - $i - 10));
            $i = $j + 1;
            continue;
        }
        $c = $sql[$i];
        if ($c === "'" || $c === '"' || $c === '`') {
            $buf .= $c;
            $i++;
            while ($i < $len) {
                if ($sql[$i] === "\\") { $buf .= substr($sql, $i, 2); $i += 2; continue; }
                $buf .= $sql[$i];
                if ($sql[$i] === $c) { $i++; break; }
                $i++;
            }
            continue;
        }
        if (substr($sql, $i, 2) === '--' || $c === '#') {
            $j = strpos($sql, "\n", $i);
            $i = $j === false ? $len : $j;
            continue;
        }
        if (substr($sql, $i, 2) === '/*') {
            $j = strpos($sql, '*/', $i);
            $i = $j === false ? $len : $j + 2;
            continue;
        }
        if (substr($sql, $i, strlen($delim)) === $delim) {
            $t = trim($buf);
            if ($t !== '') { $out[] = $t; }
            $buf = '';
            $i += strlen($delim);
            continue;
        }
        $buf .= $c;
        $i++;
    }
    if (trim($buf) !== '') { $out[] = trim($buf); }
    return $out;
}

// ---------------------------------------------------------------- 헬퍼
function h($v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function money($v): string
{
    return number_format((float)$v);
}

function post(string $k, string $def = ''): string
{
    $v = $_POST[$k] ?? $def;
    return is_string($v) ? trim($v) : $def;
}

function query(string $k, string $def = ''): string
{
    $v = $_GET[$k] ?? $def;
    return is_string($v) ? trim($v) : $def;
}

/** 금액 문자열을 숫자로. 기존 DB 처럼 콤마가 섞여 들어와도 받아냅니다 */
function num(string $s): float
{
    $s = str_replace([',', ' ', "\xe2\x82\xa9"], '', $s);
    return is_numeric($s) ? (float)$s : 0.0;
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . h(csrf_token()) . '">';
}

function csrf_check(): void
{
    $sent = $_POST['_csrf'] ?? '';
    if (!is_string($sent) || !hash_equals($_SESSION['csrf'] ?? '', $sent)) {
        http_response_code(419);
        exit('요청이 만료되었습니다. 새로고침 후 다시 시도하세요.');
    }
}

function redirect(string $url): void
{
    header('Location: ' . $url);
    exit;
}

function flash(?string $msg = null): ?string
{
    if ($msg !== null) {
        $_SESSION['flash'] = $msg;
        return null;
    }
    $m = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $m;
}

function entity_id(): int
{
    global $CFG;
    static $id = null;
    if ($id !== null) {
        return $id;
    }
    $st = db()->prepare('SELECT id FROM business_entities WHERE code = ? LIMIT 1');
    $st->execute([$CFG['entity_code']]);
    $v = $st->fetchColumn();
    if ($v === false) {
        exit('사업자 정보가 없습니다. 12_seed.sql 을 실행했는지 확인하세요.');
    }
    return $id = (int)$v;
}

/** 작업로그. 금액·거래처 변경은 반드시 남깁니다 */
function log_action(string $module, string $action, ?string $table = null,
                    ?int $refId = null, ?string $label = null,
                    ?string $before = null, ?string $after = null,
                    ?string $reason = null): void
{
    $st = db()->prepare(
        'INSERT INTO activity_logs
           (admin_id, admin_name, ip, module, ref_table, ref_id, ref_label,
            action, before_value, after_value, reason)
         VALUES (?,?,?,?,?,?,?,?,?,?,?)');
    $st->execute([
        $_SESSION['admin_id'] ?? null,
        $_SESSION['admin_name'] ?? '(미로그인)',
        $_SERVER['REMOTE_ADDR'] ?? '-',
        $module, $table, $refId, $label, $action, $before, $after, $reason,
    ]);
}

/** 문서번호 채번. 동시 발행에서도 번호가 겹치지 않습니다 */
function next_doc_no(string $kind, string $prefix, string $sep = '', ?int $entityId = null): string
{
    $pdo = db();
    $ym = date('Ym');
    // 사업자를 고를 수 있는 화면(입출금 등)은 그 사업자로 채번해야 합니다.
    // 안 넘기면 기본 사업자입니다
    $eid = $entityId ?? entity_id();

    $ins = $pdo->prepare(
        'INSERT INTO doc_sequences (business_entity_id, doc_kind, yyyymm, last_seq)
         VALUES (?,?,?,0)
         ON DUPLICATE KEY UPDATE last_seq = last_seq');
    $ins->execute([$eid, $kind, $ym]);

    // 행을 잠그고 증가시킨 값을 그대로 돌려받습니다.
    // SELECT MAX()+1 은 동시 요청에서 같은 번호를 줍니다
    $upd = $pdo->prepare(
        'UPDATE doc_sequences SET last_seq = LAST_INSERT_ID(last_seq + 1)
          WHERE business_entity_id = ? AND doc_kind = ? AND yyyymm = ?');
    $upd->execute([$eid, $kind, $ym]);

    $seq = (int)$pdo->query('SELECT LAST_INSERT_ID()')->fetchColumn();
    return $prefix . $ym . $sep . sprintf('%04d', $seq);
}

/**
 * 청구서 금액을 다시 계산해 저장합니다.
 *
 * 합계를 화면에서 더하지 않고 여기 한 곳에서만 만듭니다.
 * 전표가 빠지거나 조정항목이 바뀌면 이 함수를 다시 부르면 됩니다.
 */
function invoice_recalc(int $invoiceId): void
{
    $pdo = db();

    // 수록 전표의 합
    $st = $pdo->prepare(
        'SELECT COALESCE(SUM(t.zero_supply),0)    AS zero_supply,
                COALESCE(SUM(t.taxable_supply),0) AS taxable_supply,
                COALESCE(SUM(t.exempt_supply),0)  AS exempt_supply,
                COALESCE(SUM(t.tax_total),0)      AS tax_total
           FROM invoice_shipments xs
           JOIN v_shipment_totals t ON t.shipment_id = xs.shipment_id
          WHERE xs.invoice_id = ?');
    $st->execute([$invoiceId]);
    $s = $st->fetch() ?: ['zero_supply'=>0,'taxable_supply'=>0,'exempt_supply'=>0,'tax_total'=>0];

    // 조정항목(할인·지연료 등)의 합
    $st = $pdo->prepare(
        'SELECT COALESCE(SUM(CASE WHEN tax_type = \'ZERO\'    THEN supply_amount END),0) AS z,
                COALESCE(SUM(CASE WHEN tax_type = \'TAXABLE\' THEN supply_amount END),0) AS t,
                COALESCE(SUM(CASE WHEN tax_type = \'EXEMPT\'  THEN supply_amount END),0) AS e,
                COALESCE(SUM(tax_amount),0) AS v
           FROM invoice_items WHERE invoice_id = ?');
    $st->execute([$invoiceId]);
    $a = $st->fetch() ?: ['z'=>0,'t'=>0,'e'=>0,'v'=>0];

    $zero    = (float)$s['zero_supply']    + (float)$a['z'];
    $taxable = (float)$s['taxable_supply'] + (float)$a['t'];
    $exempt  = (float)$s['exempt_supply']  + (float)$a['e'];
    $vat     = (float)$s['tax_total']      + (float)$a['v'];
    $grand   = $zero + $taxable + $exempt + $vat;

    // 수금액 — 청구서에 직접 붙은 것 + 그 청구서에 담긴 전표에 붙은 것
    $st = $pdo->prepare(
        "SELECT COALESCE(SUM(pa.amount), 0)
           FROM payment_allocations pa
           JOIN financial_transactions f
             ON f.id = pa.transaction_id AND f.status = 'CONFIRMED' AND f.txn_type = 'IN'
          WHERE pa.invoice_id = ?
             OR pa.shipment_id IN (SELECT shipment_id FROM invoice_shipments
                                    WHERE invoice_id = ?)");
    $st->execute([$invoiceId, $invoiceId]);
    $paid = (float)$st->fetchColumn();

    $balance = $grand - $paid;

    // 상태 : 발행 전(DRAFT)·취소(CANCELLED)는 건드리지 않습니다
    $st = $pdo->prepare('SELECT status FROM invoices WHERE id = ?');
    $st->execute([$invoiceId]);
    $cur = (string)$st->fetchColumn();

    $status = $cur;
    if (!in_array($cur, ['DRAFT', 'CANCELLED'], true)) {
        if ($paid <= 0)            { $status = 'ISSUED'; }
        elseif ($balance > 0.0001) { $status = 'PARTIAL'; }
        else                       { $status = 'PAID'; }
    }

    $pdo->prepare(
        'UPDATE invoices
            SET zero_supply = ?, taxable_supply = ?, exempt_supply = ?,
                tax_total = ?, grand_total = ?, paid_amount = ?, balance = ?,
                status = ?
          WHERE id = ?')
        ->execute([$zero, $taxable, $exempt, $vat, $grand, $paid, $balance,
                   $status, $invoiceId]);
}

/** 연체 여부는 저장하지 않고 볼 때 계산합니다 (날짜가 지나면 저절로 바뀌므로) */
function invoice_state(array $inv): array
{
    $st = (string)$inv['status'];
    if ($st === 'CANCELLED') { return ['취소', 'b-err']; }
    if ($st === 'DRAFT')     { return ['작성중', 'b-warn']; }
    if ($st === 'PAID')      { return ['수금완료', 'b-ok']; }
    $overdue = !empty($inv['due_date']) && $inv['due_date'] < date('Y-m-d')
               && (float)$inv['balance'] > 0;
    if ($overdue)            { return ['연체', 'b-err']; }
    if ($st === 'PARTIAL')   { return ['부분수금', 'b-info']; }
    return ['발행', 'b-info'];
}

/**
 * 저장소 접속 비밀번호 같은 값을 암호화해 넣기 위한 열쇠.
 * config.local.php 의 app_key 또는 환경변수 GP_APP_KEY. 32자 이상이어야 씁니다.
 * 열쇠가 없으면 비밀값은 저장하지 않습니다 — 평문으로 남기는 것보다 안 넣는 게 맞습니다.
 */
function app_key(): ?string
{
    global $CFG;
    $k = (string)($CFG['app_key'] ?? (getenv('GP_APP_KEY') ?: ''));
    return strlen($k) >= 32 ? hash('sha256', $k, true) : null;
}

/** AES-256-GCM. 돌려주는 값은 그대로 VARBINARY 컬럼에 넣습니다 */
function secret_encrypt(string $plain): string
{
    $key = app_key();
    if ($key === null) {
        throw new RuntimeException('암호화 열쇠(app_key)가 없어 비밀값을 저장할 수 없습니다.');
    }
    $iv  = random_bytes(12);
    $tag = '';
    $ct  = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    if ($ct === false) {
        throw new RuntimeException('암호화에 실패했습니다.');
    }
    return 'GP1' . $iv . $tag . $ct;
}

/** 복호화는 화면이 아니라 동기화 작업에서만 씁니다 */
function secret_decrypt(?string $blob): ?string
{
    if ($blob === null || strlen($blob) < 32 || substr($blob, 0, 3) !== 'GP1') {
        return null;
    }
    $key = app_key();
    if ($key === null) {
        return null;
    }
    $iv  = substr($blob, 3, 12);
    $tag = substr($blob, 15, 16);
    $ct  = substr($blob, 31);
    $pt  = openssl_decrypt($ct, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    return $pt === false ? null : $pt;
}

// ============================================================================
//  사업자 선택 — [전체] · (주)굿배송항공 · 굿포스트미디어
//
//  entity_id()     : 새로 무엇을 만들 때 쓰는 "지금 작업 중인 사업자". 하나입니다.
//  entity_filter() : 조회할 때 쓰는 필터. NULL 이면 전체입니다.
//  기록은 반드시 한 사업자에 속하지만, 조회는 합쳐 볼 수 있어야 해서 나눴습니다.
// ============================================================================

/** 활성 사업자 목록 */
function entity_list(): array
{
    static $rows = null;
    if ($rows === null) {
        $rows = db()->query('SELECT id, code, name_ko FROM business_entities
                              WHERE is_active = 1 ORDER BY id')->fetchAll();
    }
    return $rows;
}

/**
 * 조회 필터. NULL = 전체.
 * ?ent= 로 넘기면 바뀌고 세션에 남습니다. ?ent=all 이면 전체입니다.
 */
function entity_filter(): ?int
{
    static $v = false;
    if ($v !== false) {
        return $v;
    }
    $q = $_GET['ent'] ?? null;
    if ($q !== null) {
        if ($q === 'all') {
            $_SESSION['ent'] = 'all';
        } else {
            $id = (int)$q;
            foreach (entity_list() as $e) {
                if ((int)$e['id'] === $id) { $_SESSION['ent'] = $id; break; }
            }
        }
    }
    $s = $_SESSION['ent'] ?? null;
    if ($s === 'all') { return $v = null; }
    if (is_int($s))   { return $v = $s; }
    return $v = entity_id();          // 정해진 적 없으면 기본 사업자
}

/** 조회용 WHERE 조각. 전체면 조건이 사라집니다 */
function entity_where(string $col, array &$params): string
{
    $e = entity_filter();
    if ($e === null) {
        return '1=1';
    }
    $params[] = $e;
    return $col . ' = ?';
}

/** 지금 보고 있는 사업자 이름 */
function entity_label(): string
{
    $e = entity_filter();
    if ($e === null) { return '전체 사업자'; }
    foreach (entity_list() as $x) {
        if ((int)$x['id'] === $e) { return (string)$x['name_ko']; }
    }
    return '(알 수 없음)';
}

// ============================================================================
//  재무 — 배분 · 이력 · 취소
// ============================================================================

/** 배분 합계를 다시 세어 financial_transactions.alloc_amount 에 씁니다 */
function fin_sync_alloc(int $txnId): void
{
    db()->prepare(
        'UPDATE financial_transactions
            SET alloc_amount = COALESCE(
                (SELECT SUM(amount) FROM payment_allocations WHERE transaction_id = ?), 0)
          WHERE id = ?')
        ->execute([$txnId, $txnId]);
}

/**
 * 매출전표에 이미 배분된 금액. 초과 배분을 막을 때 씁니다.
 * 자기 자신(제외할 거래)은 빼고 셉니다 — 수정할 때 필요합니다.
 */
function fin_shipment_paid(int $shipmentId, int $exceptTxnId = 0): float
{
    $st = db()->prepare(
        'SELECT COALESCE(SUM(pa.amount), 0)
           FROM payment_allocations pa
           JOIN financial_transactions f ON f.id = pa.transaction_id
          WHERE pa.shipment_id = ? AND f.status = ? AND f.id <> ?');
    $st->execute([$shipmentId, 'CONFIRMED', $exceptTxnId]);
    return (float)$st->fetchColumn();
}

/** 매입에 이미 지급된 금액 */
function fin_purchase_paid(int $purchaseId, int $exceptTxnId = 0): float
{
    $st = db()->prepare(
        'SELECT COALESCE(SUM(pa.amount), 0)
           FROM payment_allocations pa
           JOIN financial_transactions f ON f.id = pa.transaction_id
          WHERE pa.purchase_id = ? AND f.status = ? AND f.id <> ?');
    $st->execute([$purchaseId, 'CONFIRMED', $exceptTxnId]);
    return (float)$st->fetchColumn();
}

/** 재무 전용 이력. 돈이 움직인 기록은 activity_logs 보다 자세히 남깁니다 */
function fin_audit(int $txnId, string $action, ?string $field = null,
                   ?string $before = null, ?string $after = null,
                   ?string $reason = null, ?string $rowBefore = null,
                   string $table = 'financial_transactions', ?int $refId = null): void
{
    db()->prepare(
        'INSERT INTO financial_audit_logs
           (transaction_id, ref_table, ref_id, action, field_name,
            before_value, after_value, row_before, reason, admin_id, admin_name, ip)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?)')
        ->execute([
            $txnId ?: null, $table, $refId ?? $txnId ?: null, $action, $field,
            $before, $after, $rowBefore, $reason,
            $_SESSION['admin_id'] ?? null,
            $_SESSION['admin_name'] ?? '(미로그인)',
            $_SERVER['REMOTE_ADDR'] ?? '-',
        ]);
}

/** 바뀐 컬럼마다 한 줄씩 이력을 남깁니다 */
function fin_audit_diff(int $txnId, array $before, array $after, ?string $reason = null): int
{
    $n = 0;
    foreach ($after as $k => $v) {
        $b = (string)($before[$k] ?? '');
        $a = (string)$v;
        if ($b === $a) { continue; }
        fin_audit($txnId, 'UPDATE', $k, $b, $a, $reason);
        $n++;
    }
    return $n;
}

/** 거래유형 한글 이름 */
function txn_type_label(string $t): string
{
    return ['IN' => '입금', 'OUT' => '출금', 'TRANSFER' => '계좌이체'][$t] ?? $t;
}

/** 미수 상태 뱃지 */
function pay_status_badge(string $s): array
{
    return [
        'UNPAID'    => ['미입금',   'b-err'],
        'PARTIAL'   => ['부분입금', 'b-warn'],
        'PAID'      => ['입금완료', 'b-ok'],
        'CANCELLED' => ['취소',     'b-err'],
        'NONE'      => ['금액없음', 'b-info'],
        // 전환일 이전 전표 — 미수에서 닫힘(입금처리로 봄). 남은 미수가 있으면 기초잔액으로 따로 관리
        'OPENING'   => ['입금처리(전환 전)', 'b-ok'],
    ][$s] ?? [$s, 'b-info'];
}

/**
 * 입금 배분이 바뀌면 그 전표가 담긴 청구서의 수금액·잔액·상태도 다시 셉니다.
 * 이게 빠지면 입금을 넣었는데 청구관리 화면에서는 여전히 미수로 보입니다.
 */
function fin_resync_invoices(int $txnId): void
{
    $st = db()->prepare(
        'SELECT DISTINCT x.invoice_id
           FROM payment_allocations pa
           JOIN invoice_shipments x ON x.shipment_id = pa.shipment_id
          WHERE pa.transaction_id = ?
         UNION
         SELECT DISTINCT pa.invoice_id
           FROM payment_allocations pa
          WHERE pa.transaction_id = ? AND pa.invoice_id IS NOT NULL');
    $st->execute([$txnId, $txnId]);
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $invId) {
        invoice_recalc((int)$invId);
    }
}

/**
 * 권한 확인. 역할(role_code)에 그 권한 코드가 붙어 있는지 봅니다.
 * SUPER_ADMIN 은 전부 통과합니다 — 권한표를 잘못 건드려도 잠기지 않게.
 */
function can(string $code): bool
{
    static $codes = null;
    $role = (string)($_SESSION['role'] ?? '');
    if ($role === '') { return false; }
    if ($role === 'SUPER_ADMIN') { return true; }
    if ($codes === null) {
        // 계정별 권한(perm_custom = 1)이면 그 계정 것만, 아니면 역할 것을 씁니다.
        // 한 요청에 한 번만 읽습니다 — 메뉴를 그릴 때 수십 번 물어봅니다.
        $codes = [];
        $aid = (int)($_SESSION['admin_id'] ?? 0);
        $custom = false;
        try {
            $st = db()->prepare('SELECT perm_custom FROM admins WHERE id = ?');
            $st->execute([$aid]);
            $custom = (int)$st->fetchColumn() === 1;
        } catch (PDOException $e) {
            $custom = false;   // 컬럼이 아직 없으면 역할 권한
        }
        if ($custom) {
            $st = db()->prepare('SELECT p.code FROM admin_permissions ap
                                   JOIN permissions p ON p.id = ap.permission_id
                                  WHERE ap.admin_id = ?');
            $st->execute([$aid]);
        } else {
            $st = db()->prepare('SELECT p.code FROM role_permissions rp
                                   JOIN permissions p ON p.id = rp.permission_id
                                  WHERE rp.role_code = ?');
            $st->execute([$role]);
        }
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $c) { $codes[$c] = true; }
    }
    return isset($codes[$code]);
}

/** 권한이 없으면 화면을 막습니다 */
function require_perm(string $code, string $what): void
{
    if (!can($code)) {
        http_response_code(403);
        layout_head('권한 없음', '');
        echo '<div class="msg err"><b>' . h($what) . '</b> 권한이 없습니다. '
           . '관리자에게 요청하세요.</div>';
        layout_foot();
        exit;
    }
}

/**
 * 기초잔액 기준일(전환일). 없으면 NULL — 아무것도 닫히지 않습니다.
 * 뷰도 같은 값을 app_settings 에서 직접 읽습니다.
 */
function ar_cutover(): ?string
{
    static $v = false;
    if ($v !== false) { return $v; }
    try {
        $st = db()->prepare("SELECT setting_val FROM app_settings WHERE setting_key = 'ar_cutover_date'");
        $st->execute();
        $s = trim((string)$st->fetchColumn());
    } catch (PDOException $e) {
        $s = '';
    }
    return $v = preg_match('/^\d{4}-\d{2}-\d{2}$/', $s) ? $s : null;
}

/**
 * 전환일이 없는데 이관된 옛 전표가 있으면 그 건수.
 * 0 이 아니면 옛 전표 전부가 미수로 잡혀 있다는 뜻입니다 — 화면에 경고를 띄웁니다.
 */
function legacy_uncut_count(): int
{
    if (ar_cutover() !== null) { return 0; }
    try {
        return (int)db()->query('SELECT COUNT(*) FROM shipments
                                  WHERE legacy_idx IS NOT NULL AND deleted_at IS NULL')
                        ->fetchColumn();
    } catch (PDOException $e) {
        return 0;
    }
}

/** 경고 막대 — 미수 관련 화면 맨 위에 붙입니다 */
function cutover_warning(): void
{
    $n = legacy_uncut_count();
    if ($n <= 0) { return; }
    echo '<div class="msg err"><b>이관된 옛 전표 ' . money($n) . '건이 전부 미수로 잡혀 있습니다.</b> '
       . '전환일(기초잔액 기준일)이 설정되지 않았기 때문입니다. '
       . '<a href="?p=opening_balances">기초잔액 관리</a>에서 전환일을 정하고 '
       . '옛 시스템의 거래처별 미수 잔액을 넣으세요.</div>';
}

/** 사업자 코드 (GPA / GPM) — 문서번호 머리말에 씁니다 */
function entity_code(int $id): string
{
    foreach (entity_list() as $e) {
        if ((int)$e['id'] === $id) { return (string)$e['code']; }
    }
    return 'GP';
}
