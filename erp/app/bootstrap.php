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

// 운영 화면에 PHP 경고 · 경로가 그대로 찍히지 않게 (기록은 error_log 로)
@ini_set('display_errors', '0');
@ini_set('log_errors', '1');

/**
 * 오류 기록 — 500 이 나면 화면에는 짧은 안내만, 내용은 파일에 남깁니다.
 * 서버 로그를 열어 볼 수 없는 환경이라 시스템 → 오류 기록 화면에서 바로 봅니다.
 */
function gp_log_error(string $kind, string $msg, string $file = '', int $line = 0): string
{
    $code = date('md-His') . '-' . substr(bin2hex(random_bytes(3)), 0, 4);
    $who  = ($_SESSION['admin_name'] ?? '-') . '#' . ($_SESSION['admin_id'] ?? 0);
    $rec = sprintf("[%s] %s | %s | %s | %s:%d | %s %s\n", date('Y-m-d H:i:s'), $code, $kind, $who,
                   $file, $line, ($_SERVER['REQUEST_METHOD'] ?? ''), ($_SERVER['REQUEST_URI'] ?? ''));
    $rec .= '    ' . str_replace("\n", ' ', $msg) . "\n";
    error_log('GP ' . $code . ' ' . $kind . ': ' . $msg . ' @ ' . $file . ':' . $line);
    try {
        $dir = storage_root() . DIRECTORY_SEPARATOR . 'logs';
        if (!is_dir($dir)) { @mkdir($dir, 0750, true); }
        $path = $dir . DIRECTORY_SEPARATOR . 'php_errors.log';
        if (is_file($path) && filesize($path) > 512 * 1024) { @rename($path, $path . '.1'); }
        @file_put_contents($path, $rec, FILE_APPEND | LOCK_EX);
    } catch (Throwable $e) {
        // 기록에 실패해도 화면은 계속 안내를 냅니다
    }
    return $code;
}

/** 500 화면 — 내용은 남기고, 사람에게는 번호만 */
function gp_fatal_page(string $code): void
{
    if (!headers_sent()) { http_response_code(500); header('Content-Type: text/html; charset=utf-8'); }
    echo '<!doctype html><meta charset="utf-8"><div style="font:15px/1.8 system-ui,sans-serif;'
       . 'max-width:560px;margin:60px auto;padding:24px;border:1px solid #E1E8ED;border-radius:10px">'
       . '<b style="font-size:17px">화면을 여는 중 문제가 생겼습니다.</b><br>'
       . '잠시 뒤 다시 해 보시고, 계속 그러면 아래 번호를 알려 주세요.<br>'
       . '<div style="margin-top:12px;font-family:monospace;font-size:15px;background:#F5F8FA;'
       . 'padding:10px 12px;border-radius:6px">' . htmlspecialchars($code, ENT_QUOTES, 'UTF-8') . '</div>'
       . '<div style="margin-top:14px"><a href="?p=dashboard">첫 화면으로</a> · '
       . '<a href="?p=error_log">오류 기록 보기 (관리자)</a></div></div>';
}

set_exception_handler(static function (Throwable $e): void {
    gp_fatal_page(gp_log_error(get_class($e), $e->getMessage(), $e->getFile(), $e->getLine()));
});
register_shutdown_function(static function (): void {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        gp_fatal_page(gp_log_error('FatalError', (string)$e['message'], (string)$e['file'], (int)$e['line']));
    }
});

mb_internal_encoding('UTF-8');
date_default_timezone_set('Asia/Seoul');

// ---------------------------------------------------------------- 세션
// 홈페이지 공개 조회(track.php)처럼 로그인과 상관없는 입구는 GP_NO_SESSION 을 먼저 정의해 세션을 열지 않습니다
if (session_status() !== PHP_SESSION_ACTIVE && !defined('GP_NO_SESSION')) {
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

// ---------------------------------------------------------------- 서류 파일
// 올릴 수 있는 확장자. 실행 가능한 형식은 넣지 않습니다
const DOC_EXT = ['pdf', 'jpg', 'jpeg', 'png', 'gif', 'webp', 'xlsx', 'xls', 'csv',
                 'docx', 'doc', 'pptx', 'ppt', 'hwp', 'hwpx', 'txt', 'zip'];
const DOC_MAX = 100 * 1024 * 1024;   // 100MB — 서버 PHP 한도는 .htaccess (php_value) 에서 맞춤
const DOC_MAX_LABEL = '100MB';

/**
 * 보관 폴더를 웹에서 바로 못 열게 막습니다.
 * AISpace 에서는 /app/user_data 가 웹 폴더 안에 있어서, 파일 이름을 알면 주소로 열릴 수 있습니다.
 * 파일은 로그인한 사람만 file_download 화면으로 받게 합니다.
 */
function doc_protect_root(string $root): void
{
    $ht = $root . DIRECTORY_SEPARATOR . '.htaccess';
    if (is_dir($root) && !is_file($ht)) {
        @file_put_contents($ht, "# 서류 보관 폴더 — 웹에서 직접 열지 못하게 (ERP 의 file_download 로만)\n"
                              . "<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n"
                              . "<IfModule !mod_authz_core.c>\n  Order deny,allow\n  Deny from all\n</IfModule>\n");
    }
}

/**
 * 사업자 직인 이미지 — 공개 저장소(GitHub)에 두지 않고 비공개 구역(uploads/stamps)에만 둡니다.
 * 파일 저장소가 NAS 면 NAS /erp/uploads/stamps 에도 올라갑니다 (app/filestore.php).
 * 문서 화면에는 파일 주소가 아니라 data: 로 바로 넣어, 로그인한 화면에서만 보입니다.
 */
function entity_stamp_dir(): string
{
    require_once APP_DIR . '/filestore.php';
    return dirname(fs_local_path('uploads', 'stamps/x'));
}

function entity_stamp_data_uri(?array $be): ?string
{
    $rel = (string)($be['stamp_path'] ?? '');
    if (!preg_match('/^stamps\/[A-Za-z0-9_-]+\.(png|jpg|jpeg|webp)$/', $rel)) { return null; }
    require_once APP_DIR . '/filestore.php';
    $bin = fs_read('uploads', $rel);
    if ($bin === null) {
        // 저장소 도입 전에 서류 폴더(documents/stamps)에 올린 직인
        $old = storage_root() . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
        $bin = is_file($old) ? (string)file_get_contents($old) : null;
    }
    if ($bin === null || $bin === '') { return null; }
    $ext  = strtolower(pathinfo($rel, PATHINFO_EXTENSION));
    $mime = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'webp' => 'image/webp'][$ext];
    return 'data:' . $mime . ';base64,' . base64_encode($bin);
}

/** <input type=file name=x[] multiple> 를 파일 하나씩의 배열로 */
function uploaded_files(string $field): array
{
    $f = $_FILES[$field] ?? null;
    if (!$f || !isset($f['name'])) { return []; }
    if (!is_array($f['name'])) { return [$f]; }
    $out = [];
    foreach ($f['name'] as $i => $name) {
        if (($f['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) { continue; }
        $out[] = ['name' => $name, 'type' => $f['type'][$i] ?? '', 'tmp_name' => $f['tmp_name'][$i] ?? '',
                  'error' => $f['error'][$i] ?? UPLOAD_ERR_NO_FILE, 'size' => $f['size'][$i] ?? 0];
    }
    return $out;
}

/**
 * 올린 파일 하나를 보관하고 documents 에 한 줄 남깁니다. 성공이면 '' , 실패면 이유.
 * 저장 이름은 원본과 무관하게 새로 만듭니다 — 원본 이름을 쓰면 경로 조작 · 덮어쓰기 · 실행 위험.
 * backup_status = PENDING 으로 두면 NAS 백업이 가져갑니다.
 */
function doc_store_upload(array $f, int $eid, int $typeId, ?int $shipmentId = null, ?int $companyId = null,
                          string $title = '', ?string $docDate = null): string
{
    $name = (string)($f['name'] ?? '');
    if (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return $name . ': ' . (in_array($f['error'] ?? 0, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
            ? '서버가 받을 수 있는 크기를 넘습니다'
            : '업로드 실패 (오류 ' . (int)($f['error'] ?? 0) . ')');
    }
    if (!is_uploaded_file((string)$f['tmp_name'])) { return $name . ': 정상적인 업로드가 아닙니다'; }
    if ((int)$f['size'] > DOC_MAX) { return $name . ': ' . DOC_MAX_LABEL . ' 를 넘습니다'; }
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if (!in_array($ext, DOC_EXT, true)) { return $name . ': 올릴 수 없는 형식 (허용: ' . implode(', ', DOC_EXT) . ')'; }
    if ($typeId <= 0) { return $name . ': 문서 종류를 고르세요'; }

    $root = storage_root();
    $dir  = $root . DIRECTORY_SEPARATOR . date('Y') . DIRECTORY_SEPARATOR . date('m');
    if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
        return $name . ': 저장 폴더를 만들지 못했습니다';
    }
    doc_protect_root($root);

    $stored = bin2hex(random_bytes(16)) . '.' . $ext;
    $full   = $dir . DIRECTORY_SEPARATOR . $stored;
    $rel    = date('Y') . '/' . date('m') . '/' . $stored;
    if (!@move_uploaded_file((string)$f['tmp_name'], $full)) {
        return $name . ': 파일을 저장하지 못했습니다 (폴더 쓰기 권한)';
    }
    @chmod($full, 0640);
    $hash = hash_file('sha256', $full) ?: null;
    $mime = function_exists('mime_content_type') ? (mime_content_type($full) ?: null) : null;
    try {
        db()->prepare(
            'INSERT INTO documents
               (business_entity_id, document_type_id, shipment_id, company_id,
                doc_date, title, original_name, stored_path, mime_type,
                size_bytes, checksum_sha256, backup_status, uploaded_by)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,\'PENDING\',?)')
            ->execute([$eid, $typeId, $shipmentId ?: null, $companyId ?: null, $docDate ?: null,
                       $title !== '' ? $title : $name, $name, $rel, $mime, (int)$f['size'], $hash,
                       $_SESSION['admin_id'] ?? null]);
        $docId = (int)db()->lastInsertId();
        log_action('문서', 'CREATE', 'documents', $docId, $name, null,
                   number_format((int)$f['size']) . ' bytes' . ($shipmentId ? ' · 전표 #' . $shipmentId : ''));
        // 파일 저장소가 NAS 면 바로 보냅니다. 안 붙으면 서버에 둔 채(LOCAL) 자동 작업이 나중에 다시 보냄
        require_once APP_DIR . '/filestore.php';
        if (fs_cfg()['nas'] && fs_push('docs', $rel, $why)) {
            db()->prepare("UPDATE documents SET storage = 'NAS', backup_status = 'SYNCED', backup_at = NOW(),
                                  backup_path = ? WHERE id = ?")
                ->execute(['NAS:' . fs_cfg()['dir']['docs'] . '/' . $rel, $docId]);
        }
    } catch (PDOException $e) {
        @unlink($full);
        error_log('문서 저장 실패: ' . $e->getMessage());
        return $name . ': 기록을 남기지 못해 올리지 않았습니다';
    }
    return '';
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

/**
 * 전표로 청구서를 만듭니다 (청구관리 · 매출전표 목록 · 전표 화면이 같이 씀).
 * 한 거래처의 아직 청구 안 된 전표만 받습니다 — 하나라도 어긋나면 만들지 않고 RuntimeException.
 * 대상기간을 안 주면 고른 전표의 첫 · 마지막 전표일. 돌려주는 값: [청구서 id, 청구번호]
 */
function invoice_create(int $eid, int $cid, array $shipIds, string $invoiceDate, ?string $dueDate = null,
                        ?string $periodFrom = null, ?string $periodTo = null): array
{
    $shipIds = array_values(array_unique(array_filter(array_map('intval', $shipIds))));
    if ($cid <= 0 || !$shipIds) {
        throw new RuntimeException('청구할 전표를 한 건 이상 선택하세요.');
    }
    $pdo = db();
    $own = !$pdo->inTransaction();
    if ($own) { $pdo->beginTransaction(); }
    try {
        // 고른 전표가 정말 이 거래처의 미청구 건인지 다시 확인합니다. 화면에서 넘어온 id 를 그대로 믿지 않습니다
        $ph = implode(',', array_fill(0, count($shipIds), '?'));
        $st = $pdo->prepare(
            "SELECT s.id, s.voucher_date FROM shipments s
               LEFT JOIN invoice_shipments xs ON xs.shipment_id = s.id
              WHERE s.id IN ($ph) AND s.business_entity_id = ? AND s.company_id = ?
                AND s.deleted_at IS NULL AND s.status <> 'CANCELLED'
                AND xs.id IS NULL
              ORDER BY s.voucher_date, s.id
              FOR UPDATE");
        $st->execute(array_merge($shipIds, [$eid, $cid]));
        $rows = $st->fetchAll();
        if (count($rows) !== count($shipIds)) {
            throw new RuntimeException('이미 청구됐거나 조건에 맞지 않는 전표가 섞여 있습니다.');
        }
        $ok = array_map('intval', array_column($rows, 'id'));
        $periodFrom = $periodFrom ?: (string)$rows[0]['voucher_date'];
        $periodTo   = $periodTo ?: (string)end($rows)['voucher_date'];

        $no = next_doc_no('INVOICE', 'GPA-INV-', '-', $eid);
        $bank = $pdo->prepare('SELECT id FROM business_bank_accounts
                                WHERE business_entity_id = ? AND is_active = 1 ORDER BY sort_order LIMIT 1');
        $bank->execute([$eid]);
        $bankId = $bank->fetchColumn() ?: null;

        $pdo->prepare(
            'INSERT INTO invoices
               (business_entity_id, invoice_no, company_id, invoice_date,
                period_from, period_to, due_date, bank_account_id, status, created_by)
             VALUES (?,?,?,?,?,?,?,?,\'DRAFT\',?)')
            ->execute([$eid, $no, $cid, $invoiceDate, $periodFrom, $periodTo,
                       $dueDate ?: null, $bankId, $_SESSION['admin_id'] ?? null]);
        $invId = (int)$pdo->lastInsertId();

        $ins = $pdo->prepare('INSERT INTO invoice_shipments (invoice_id, shipment_id, line_no) VALUES (?,?,?)');
        $n = 0;
        foreach ($ok as $sid) {
            $ins->execute([$invId, $sid, ++$n]);
        }
        $pdo->prepare("UPDATE shipments SET status = 'BILLED'
                        WHERE id IN ($ph) AND status NOT IN ('PAID','CANCELLED')")
            ->execute($ok);

        invoice_recalc($invId);
        log_action('청구', 'CREATE', 'invoices', $invId, $no, null, '전표 ' . $n . '건');
        if ($own) { $pdo->commit(); }
        return [$invId, $no];
    } catch (Throwable $e) {
        if ($own && $pdo->inTransaction()) { $pdo->rollBack(); }
        throw $e;
    }
}

/**
 * 비용 종류와 기본 세금구분 — 매출전표 · 견적 · 매입이 같이 씁니다.
 * 회사 기준 (2026-09-19): 특송 · 항공 · 해상 운송(수출입)은 영세율,
 *   핸드링차지 · 도큐멘트피 · 국내운송 · 창고료 · 검사료 · 통관료는 과세 10%.
 *   기타는 기본값이 없습니다 (줄에서 직접 고름).
 * 종류를 고르면 화면이 세금구분을 기본값으로 바꿔 주고, 필요하면 그 줄만 다시 고칠 수 있습니다.
 */
const CHARGE_TYPES = [
    // 매출전표에서 쓰는 두 가지 — 영세 운임과 과세 운임
    'FREIGHT'     => ['운임',       'ZERO'],
    'FREIGHT_TAX' => ['과세운임',   'TAXABLE'],
    'AIR_FREIGHT' => ['특송운임',   'ZERO'],
    'AIR_CARGO'   => ['항공운임',   'ZERO'],
    'SEA_FREIGHT' => ['해상운임',   'ZERO'],
    'HANDLING'    => ['핸드링차지', 'TAXABLE'],
    'DOC_FEE'     => ['도큐멘트피', 'TAXABLE'],
    'DOMESTIC'    => ['국내운송',   'TAXABLE'],
    'STORAGE'     => ['창고료',     'TAXABLE'],
    'INSPECTION'  => ['검사료',     'TAXABLE'],
    'CUSTOMS'     => ['통관료',     'TAXABLE'],
    'OTHER'       => ['기타',       ''],
];

/** 종류 코드 → 이름 */
function charge_labels(): array
{
    return array_map(fn($v) => $v[0], CHARGE_TYPES);
}

/** 종류 코드 → 기본 세금구분 ('' 이면 없음) */
function charge_default_tax(string $code): string
{
    return CHARGE_TYPES[$code][1] ?? '';
}

/** 세금계산서 종류 이름 — TAX 일반(과세) / ZERO 영세율 / EXEMPT 계산서(면세) */
function tax_doc_label(string $type): string
{
    return ['TAX' => '세금계산서', 'ZERO' => '영세율 세금계산서', 'EXEMPT' => '계산서',
            'MODIFY' => '수정세금계산서'][$type] ?? $type;
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
/**
 * 거래처 코드의 머리글자 — 옛 시스템 규칙(팀-머리글자+번호, 예 01-D045)을 따릅니다.
 * 업체명 첫 글자의 소리를 로마자로: ㄷ→D, ㅎ→H … ㅇ 으로 시작하면 모음으로 (아→A, 이→I, 와·워→W, 야·유→Y).
 * (주) · 주식회사 · ㈜ 같은 앞붙이는 건너뜁니다. 영문 이름이면 그 첫 글자.
 */
function company_code_letter(string $name): string
{
    $n = (string)preg_replace('/^\s*(\(주\)|㈜|주식회사|\(유\)|유한회사|\(합\)|\(사\))\s*/u', '', $name);
    // ㄱ→K · ㄹ→L 은 옛 코드에서 더 많이 쓴 쪽입니다 (885곳 중 옛 규칙과 약 60% 일치, 나머지는 사람이 고른 것)
    $cho  = ['K','K','N','D','D','L','M','B','P','S','S','','J','J','C','K','T','P','H'];
    // ㅇ 뒤 모음: ㅏㅐㅑㅒㅓㅔㅕㅖㅗㅘㅙㅚㅛㅜㅝㅞㅟㅠㅡㅢㅣ
    $vow  = ['A','A','Y','Y','E','E','Y','Y','O','W','W','O','Y','U','W','W','W','Y','E','E','I'];
    foreach (preg_split('//u', $n, -1, PREG_SPLIT_NO_EMPTY) as $ch) {
        $o = mb_ord($ch, 'UTF-8');
        if ($o >= 0xAC00 && $o <= 0xD7A3) {
            $idx = $o - 0xAC00;
            $c = $cho[intdiv($idx, 588)];
            return $c !== '' ? $c : $vow[intdiv($idx % 588, 28)];
        }
        if (preg_match('/[A-Za-z]/', $ch)) { return strtoupper($ch); }
    }
    return 'X';
}

/** 다음 거래처 코드 — 같은 팀 · 같은 머리글자에서 가장 큰 번호 + 1 (세 자리) */
function company_code_suggest(string $team, string $name): string
{
    $team = preg_match('/^\d{1,2}$/', trim($team)) ? str_pad(trim($team), 2, '0', STR_PAD_LEFT) : '01';
    $prefix = $team . '-' . company_code_letter($name);
    $st = db()->prepare('SELECT company_code FROM companies WHERE company_code LIKE ?');
    $st->execute([$prefix . '%']);
    $max = 0;
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $c) {
        if (preg_match('/^' . preg_quote($prefix, '/') . '(\d+)$/i', (string)$c, $m)) {
            $max = max($max, (int)$m[1]);
        }
    }
    return $prefix . str_pad((string)($max + 1), 3, '0', STR_PAD_LEFT);
}

/** 매출전표 상태 → [한글, 배지색] */
function shipment_status_badge(string $s): array
{
    return [
        'DRAFT'     => ['임시',        'b-warn'],
        'CONFIRMED' => ['확정·미청구', 'b-warn'],
        'BILLED'    => ['청구',        'b-info'],
        'PAID'      => ['입금',        'b-ok'],
        'CANCELLED' => ['취소',        'b-err'],
    ][$s] ?? [$s, 'b-info'];
}

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
