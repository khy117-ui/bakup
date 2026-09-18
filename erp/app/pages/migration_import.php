<?php
// 웹에서 직접 열면 실행되지 않게 막습니다 (nginx 면 .htaccess 가 무시됩니다)
if (!defined('APP_DIR')) { http_response_code(403); exit('Forbidden'); }

require_once APP_DIR . '/layout.php';

/**
 * 옛 자료 가져오기 — 옛 시스템 CSV(CUSTOMERS · IS_SALES)를 staging 에 넣고 13b · 14b 로 이관.
 *
 *   1. 준비        13a · 14a (staging · 이력 · 보관 테이블, 트리거). 이미 있으면 건너뜀
 *   2. 원본 올리기  브라우저가 CSV 를 읽어 400행씩 보냄 — 서버에 파일을 남기지 않음
 *   3. 이관 실행    15[D] → 13b → 14b 를 한 문장씩. 진행 상황은 migration_run_log 에 남음
 *   4. 대조        CSV 에서 센 건수·금액 합계와 DB 를 비교
 *   5. 리허설 자료 지우기 — 업무 자료만 비우고 사업자·관리자·권한·설정·게시판은 남김
 *
 * 실행하는 SQL 은 서버의 sql/ 폴더에 있는 정해진 파일뿐입니다. 요청으로 SQL 을 받지 않습니다.
 * 관리자(SUPER_ADMIN)만 씁니다.
 */

if (($_SESSION['role'] ?? '') !== 'SUPER_ADMIN') {
    http_response_code(403);
    layout_head('옛 자료 가져오기', 'migration_import');
    echo '<div class="msg err">최고관리자(SUPER_ADMIN)만 쓸 수 있는 화면입니다.</div>';
    layout_foot();
    exit;
}

const MI_FILES = [
    '13a' => '13a_companies_guard.sql',
    '14a' => '14a_shipments_guard.sql',
    '15'  => '15_staging_import.sql',
    '13b' => '13b_companies_migrate.sql',
    '14b' => '14b_shipments_migrate.sql',
];

const MI_COLS = [
    'companies' => ['IDX', 'CUTCODE', 'COMPANY', 'ECOMPANY', 'PRESIDENT', 'BNUMBER', 'JUMBER',
        'CATEGORY', 'PHONE', 'EVENTSD', 'FAX', 'ADDRESS', 'EADDRESS', 'SINCEDATE', 'TEAM', 'SPOT',
        'BUSINESS', 'CONTENTS', 'PAYMENTS', 'BILL', 'EMAIL', 'RPHONE', 'RPHONE2', 'PAYMETHOD',
        'PAYREGDATE', 'PUBLICATION', 'REGDATE', 'DCDHL', 'DCTNT', 'DCUPS', 'DCEMS', 'IDC_DHL',
        'IDC_TNT', 'C_FUEL', 'C_FUEL2', 'C_FUEL3', 'SALEDATE', 'file1', 'filenum', 'DEL'],
    'shipments' => ['IDX', 'COMPANY', 'EADDRESS', 'PHONE', 'FAX', 'BUYERCODE', 'CONTACTNAME',
        'BEADDRESS', 'BPHONE', 'BFAX', 'ARRIVAL_N', 'FACTORY_A', 'TRANSIT_A', 'WEIGHT', 'AMOUNT',
        'FUEL', 'DANGA_NUM', 'DANGA_NAME', 'PRICE', 'DCYUL', 'COLMONEY', 'TSNUM', 'TSWEIGHT',
        'TSAMOUNT', 'DESCRIPTION', 'PCS', 'UNIT', 'MAMOUNT', 'DATESHIP', 'CONTENTS', 'SPOT', 'COMM',
        'REGDATE', 'BLNUM', 'INOUT', 'COLLECT', 'SALEDATE', 'DIVISION', 'BUSINESS', 'TEAM',
        'LICENCE', 'BILL', 'TAXES', 'DEPOSIT', 'file1', 'filenum', 'remark', 'GUBUN', 'TSWEIGHT2',
        'UPTDATE', 'UPTUSER'],
];

/**
 * 준비 단계에서 '이미 있음' 은 실패가 아닙니다 — 다시 눌러도 되게.
 *   1050 테이블 있음 · 1060 컬럼 있음 · 1061 인덱스 있음 · 1359 트리거 있음
 * 1419 는 트리거 만들 권한이 없다는 뜻입니다. 트리거가 없어도 이관은 됩니다(이력만 덜 남음).
 */
const MI_SKIP_CODES = [1050, 1060, 1061, 1359];
const MI_WARN_CODES = [1419, 1142, 1227];

/** 업무 자료 — 리허설을 지울 때 비우는 표. 사업자·관리자·권한·설정·게시판·단가표는 남깁니다 */
const MI_RESET_TABLES = [
    'payment_allocations', 'transaction_attachments', 'financial_audit_logs',
    'financial_transactions', 'bank_import_rows', 'bank_imports', 'opening_balances',
    'tax_invoice_items', 'tax_invoices', 'invoice_items', 'invoice_shipments', 'invoices',
    'payments', 'statement_shipments', 'statements', 'quotation_items', 'quotations',
    'tracking_events', 'tracking_numbers', 'documents', 'purchases',
    'shipment_charges', 'shipment_parties', 'shipment_items', 'shipments_legacy_extra',
    'shipments_history', 'shipments',
    'company_carrier_terms', 'company_files', 'company_contacts', 'companies_legacy_extra',
    'companies_history', 'companies',
    'migration_errors', 'migration_run_log', 'doc_sequences',
];
const MI_STAGING_TABLES = ['companies_staging', 'shipments_staging'];

/** SQL 파일을 문장으로 나눕니다 — bootstrap 의 sql_split() */
function mi_split(string $sql): array
{
    return sql_split($sql);
}

function mi_file_stmts(string $f): array
{
    static $cache = [];
    if (!isset($cache[$f])) {
        $path = dirname(APP_DIR) . '/sql/' . MI_FILES[$f];
        $sql = is_file($path) ? (string)file_get_contents($path) : '';
        $cache[$f] = $sql === '' ? [] : mi_split($sql);
    }
    return $cache[$f];
}

/** 실행 단계 목록. key 는 '파일:번호' */
function mi_steps(): array
{
    $steps = [];
    foreach (['13a', '14a'] as $f) {
        foreach (mi_file_stmts($f) as $n => $st) {
            $steps[] = ['key' => "$f:$n", 'file' => $f, 'n' => $n, 'phase' => 'prep', 'sql' => $st];
        }
    }
    // 15번은 [D] (raw_json · 지문 채우기, NOT NULL 복구) 와 확인 조회만.
    // [B] 의 'JSON NULL' 은 필요 없습니다 — 올릴 때 raw_json 을 서버가 바로 채웁니다.
    foreach (mi_file_stmts('15') as $n => $st) {
        if (preg_match('/^ALTER TABLE \w+_staging MODIFY raw_json JSON NULL/i', $st)) { continue; }
        $steps[] = ['key' => "15:$n", 'file' => '15', 'n' => $n, 'phase' => 'finish', 'sql' => $st];
    }
    foreach (['13b', '14b'] as $f) {
        foreach (mi_file_stmts($f) as $n => $st) {
            $steps[] = ['key' => "$f:$n", 'file' => $f, 'n' => $n, 'phase' => 'migrate', 'sql' => $st];
        }
    }
    return $steps;
}

function mi_head(string $sql): string
{
    return mb_substr((string)preg_replace('/\s+/', ' ', $sql), 0, 120);
}

function mi_ensure_log(): void
{
    db()->exec(
        "CREATE TABLE IF NOT EXISTS migration_run_log (
           run_key     VARCHAR(190) NOT NULL,
           status      VARCHAR(10)  NOT NULL COMMENT 'RUNNING / OK / SKIP / WARN / FAIL',
           head        VARCHAR(255) NULL,
           affected    BIGINT       NULL,
           result_json MEDIUMTEXT   NULL,
           error_msg   TEXT         NULL,
           conn_id     BIGINT       NULL,
           started_at  DATETIME     NOT NULL,
           ended_at    DATETIME     NULL,
           PRIMARY KEY (run_key)
         ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
           COMMENT='옛 자료 가져오기 진행 기록'");
}

function mi_table_exists(string $t): bool
{
    $st = db()->prepare('SELECT COUNT(*) FROM information_schema.TABLES
                          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
    $st->execute([$t]);
    return (int)$st->fetchColumn() > 0;
}

/** 기록 한 줄. RUNNING 인데 그 DB 연결이 이미 끊겼으면 '중단' 으로 고쳐 돌려줍니다 */
function mi_log_get(string $key): ?array
{
    // 경과 시간은 DB 시계로 잽니다 (PHP 와 DB 의 시간대가 다를 수 있어서)
    $st = db()->prepare('SELECT *, TIMESTAMPDIFF(SECOND, started_at, COALESCE(ended_at, NOW())) AS elapsed_sec
                           FROM migration_run_log WHERE run_key = ?');
    $st->execute([$key]);
    $row = $st->fetch();
    if (!$row) { return null; }
    if ($row['status'] === 'RUNNING' && $row['conn_id'] !== null) {
        // 연결이 남아 있어도 'Sleep' 이면 문장은 이미 끝난(또는 멈춘) 것입니다
        $alive = db()->prepare("SELECT COUNT(*) FROM information_schema.PROCESSLIST
                                 WHERE ID = ? AND COMMAND <> 'Sleep'");
        $alive->execute([(int)$row['conn_id']]);
        if ((int)$alive->fetchColumn() === 0) {
            db()->prepare("UPDATE migration_run_log
                              SET status = 'FAIL', error_msg = '실행 도중 끊겼습니다. 다시 실행하세요.',
                                  ended_at = NOW()
                            WHERE run_key = ? AND status = 'RUNNING'")->execute([$key]);
            $row['status'] = 'FAIL';
            $row['error_msg'] = '실행 도중 끊겼습니다. 다시 실행하세요.';
        }
    }
    return $row;
}

function mi_log_out(?array $row): array
{
    if (!$row) { return ['status' => 'NONE']; }
    return [
        'status'   => $row['status'],
        'affected' => $row['affected'] === null ? null : (int)$row['affected'],
        'result'   => $row['result_json'] ? json_decode($row['result_json'], true) : null,
        'error'    => $row['error_msg'],
        'started'  => $row['started_at'],
        'ended'    => $row['ended_at'],
        'elapsed'  => isset($row['elapsed_sec']) ? (int)$row['elapsed_sec'] : null,
    ];
}

function mi_json(array $a, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($a, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    exit;
}

/** 대조용 숫자 */
function mi_counts(): array
{
    $one = function (string $sql) {
        try { return db()->query($sql)->fetchColumn(); } catch (PDOException $e) { return null; }
    };
    $num = "CAST(NULLIF(REPLACE(REPLACE(TRIM(%s), ',', ''), ' ', ''), '') AS DECIMAL(18,2))";
    return [
        'stage_comp'   => $one('SELECT COUNT(*) FROM companies_staging'),
        'stage_ship'   => $one('SELECT COUNT(*) FROM shipments_staging'),
        'stage_amount' => $one('SELECT SUM(' . sprintf($num, 'AMOUNT') . ') FROM shipments_staging'),
        'stage_ts'     => $one('SELECT SUM(' . sprintf($num, 'TSAMOUNT') . ') FROM shipments_staging'),
        'live_comp'    => $one('SELECT COUNT(*) FROM companies WHERE legacy_idx IS NOT NULL'),
        'live_ship'    => $one('SELECT COUNT(*) FROM shipments WHERE legacy_idx IS NOT NULL'),
        'all_comp'     => $one('SELECT COUNT(*) FROM companies'),
        'all_ship'     => $one('SELECT COUNT(*) FROM shipments'),
        'unmatched'    => $one("SELECT COUNT(*) FROM shipments s JOIN companies c ON c.id = s.company_id
                                 WHERE c.company_code = 'UNMATCHED'"),
        'purchases'    => $one('SELECT COUNT(*) FROM purchases'),
    ];
}

// ================================================================ 요청 처리 (JSON)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = post('action');

    // 긴 문장이 도는 동안 같은 사용자의 진행확인 요청이 세션 잠금에 막히지 않게
    session_write_close();
    @set_time_limit(0);
    ignore_user_abort(true);

    try {
        mi_ensure_log();

        if ($action === 'status') {
            $key = post('key');
            mi_json(['key' => $key] + mi_log_out(mi_log_get($key)));
        }

        if ($action === 'counts') {
            mi_json(mi_counts());
        }

        // 너무 오래 도는 단계 멈추기 — 그 단계를 돌리던 DB 연결만 끊습니다.
        // InnoDB 가 그 문장이 넣던 것을 전부 되돌리므로 반쯤 들어간 자료는 남지 않습니다
        if ($action === 'kill_step') {
            $key = post('key');
            $row = mi_log_get($key);
            if (!$row || $row['status'] !== 'RUNNING' || $row['conn_id'] === null) {
                mi_json(['key' => $key, 'status' => $row['status'] ?? 'NONE',
                         'error' => '지금 실행 중인 단계가 아닙니다.']);
            }
            $cid = (int)$row['conn_id'];
            // 같은 DB 계정의 우리 연결인지 한 번 더 봅니다 (다른 사람 연결은 끊지 않음)
            $own = db()->prepare('SELECT COUNT(*) FROM information_schema.PROCESSLIST
                                   WHERE ID = ? AND USER = SUBSTRING_INDEX(CURRENT_USER(), \'@\', 1)
                                     AND DB = DATABASE()');
            $own->execute([$cid]);
            if ((int)$own->fetchColumn() === 1 && $cid !== (int)db()->query('SELECT CONNECTION_ID()')->fetchColumn()) {
                db()->exec('KILL ' . $cid);
            }
            db()->prepare("UPDATE migration_run_log
                              SET status = 'FAIL', ended_at = NOW(),
                                  error_msg = '관리자가 중단했습니다. 넣던 것은 되돌려졌습니다. 다시 실행하세요.'
                            WHERE run_key = ? AND status = 'RUNNING'")->execute([$key]);
            log_action('시스템', 'UPDATE', 'migration_run_log', null, $key, null, '이관 단계 중단');
            mi_json(['key' => $key] + mi_log_out(mi_log_get($key)));
        }

        if ($action === 'run_step') {
            $key = post('key');
            $step = null;
            foreach (mi_steps() as $s) {
                if ($s['key'] === $key) { $step = $s; break; }
            }
            if ($step === null) {
                mi_json(['status' => 'FAIL', 'error' => '없는 단계입니다: ' . $key], 400);
            }
            $prev = mi_log_get($key);
            if ($prev && in_array($prev['status'], ['OK', 'SKIP', 'WARN', 'RUNNING'], true)) {
                mi_json(['key' => $key] + mi_log_out($prev));
            }
            if ($step['phase'] !== 'prep' && !mi_table_exists('shipments_staging')) {
                mi_json(['key' => $key, 'status' => 'FAIL', 'error' => '먼저 1단계 [준비] 를 실행하세요.']);
            }

            $pdo = db();
            $conn = (int)$pdo->query('SELECT CONNECTION_ID()')->fetchColumn();
            $pdo->prepare("REPLACE INTO migration_run_log (run_key, status, head, conn_id, started_at)
                           VALUES (?, 'RUNNING', ?, ?, NOW())")
                ->execute([$key, mi_head($step['sql']), $conn]);

            $status = 'OK';
            $affected = null;
            $result = null;
            $err = null;
            try {
                if (preg_match('/^\s*(SELECT|SHOW|WITH)\b/i', $step['sql'])) {
                    $res = $pdo->query($step['sql']);
                    $rows = $res->fetchAll();
                    $res->closeCursor();
                    $affected = count($rows);
                    $result = array_slice($rows, 0, 60);
                } else {
                    // exec() 는 준비문을 쓰지 않아 CREATE TRIGGER 도 됩니다
                    $affected = $pdo->exec($step['sql']);
                }
            } catch (PDOException $e) {
                $code = (int)($e->errorInfo[1] ?? 0);
                $err = $e->getMessage();
                if ($step['phase'] === 'prep' && in_array($code, MI_SKIP_CODES, true)) {
                    $status = 'SKIP';
                } elseif ($step['phase'] === 'prep' && in_array($code, MI_WARN_CODES, true)) {
                    $status = 'WARN';
                } else {
                    $status = 'FAIL';
                }
            }
            $pdo->prepare('UPDATE migration_run_log
                              SET status = ?, affected = ?, result_json = ?, error_msg = ?, ended_at = NOW()
                            WHERE run_key = ?')
                ->execute([$status, $affected,
                           $result === null ? null : json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR),
                           $err, $key]);
            mi_json(['key' => $key] + mi_log_out(mi_log_get($key)));
        }

        if ($action === 'stage') {
            $kind = post('kind');
            if (!array_key_exists($kind, MI_COLS)) {
                mi_json(['status' => 'FAIL', 'error' => '종류가 틀렸습니다'], 400);
            }
            $table = $kind === 'companies' ? 'companies_staging' : 'shipments_staging';
            if (!mi_table_exists($table)) {
                mi_json(['status' => 'FAIL', 'error' => '먼저 1단계 [준비] 를 실행하세요.']);
            }
            $payload = json_decode(post('payload'), true);
            if (!is_array($payload) || !isset($payload['header'], $payload['rows'], $payload['batch'])) {
                mi_json(['status' => 'FAIL', 'error' => '보낸 자료를 읽지 못했습니다'], 400);
            }
            $cols = MI_COLS[$kind];
            if ($payload['header'] !== $cols) {
                mi_json(['status' => 'FAIL',
                         'error' => '첫 줄(컬럼 이름)이 옛 시스템 ' . ($kind === 'companies' ? 'CUSTOMERS' : 'IS_SALES')
                                  . ' 형식과 다릅니다. 순서와 이름이 같아야 합니다.']);
            }
            $sig = sha1((string)($payload['sig'] ?? ''));
            $batch = (int)$payload['batch'];
            $key = "stage:$kind:$sig:$batch";
            $prev = mi_log_get($key);
            if ($prev && $prev['status'] === 'OK') {
                mi_json(['key' => $key, 'status' => 'OK', 'skipped' => true, 'affected' => (int)$prev['affected']]);
            }
            $rows = $payload['rows'];
            foreach ($rows as $i => $r) {
                if (!is_array($r) || count($r) !== count($cols)) {
                    mi_json(['status' => 'FAIL',
                             'error' => sprintf('%d번째 묶음 %d번째 줄의 칸 수가 %d개입니다 (%d개여야 함)',
                                                $batch + 1, $i + 1, is_array($r) ? count($r) : 0, count($cols))]);
                }
            }
            $source = mb_substr((string)($payload['file'] ?? ''), 0, 200);
            $colSql = implode(', ', array_map(fn($c) => "`$c`", $cols)) . ', raw_json, source_file';
            $one = '(' . implode(',', array_fill(0, count($cols) + 2, '?')) . ')';
            $pdo = db();
            $pdo->beginTransaction();
            try {
                foreach (array_chunk($rows, 200) as $chunk) {
                    $vals = [];
                    foreach ($chunk as $r) {
                        foreach ($r as $v) { $vals[] = (string)$v; }
                        $vals[] = json_encode(array_combine($cols, array_map('strval', $r)),
                                              JSON_UNESCAPED_UNICODE);
                        $vals[] = $source;
                    }
                    $pdo->prepare("INSERT INTO $table ($colSql) VALUES "
                                  . implode(',', array_fill(0, count($chunk), $one)))
                        ->execute($vals);
                }
                $pdo->prepare("REPLACE INTO migration_run_log (run_key, status, head, affected, started_at, ended_at)
                               VALUES (?, 'OK', ?, ?, NOW(), NOW())")
                    ->execute([$key, "$table ← $source 묶음 " . ($batch + 1), count($rows)]);
                $pdo->commit();
            } catch (Throwable $e) {
                $pdo->rollBack();
                mi_json(['status' => 'FAIL', 'error' => sprintf('%d번째 묶음을 넣지 못했습니다: %s',
                                                               $batch + 1, $e->getMessage())]);
            }
            mi_json(['key' => $key, 'status' => 'OK', 'affected' => count($rows)]);
        }

        if ($action === 'reset') {
            if (post('confirm') !== '지우기') {
                mi_json(['status' => 'FAIL', 'error' => "확인란에 '지우기' 라고 적어야 합니다."]);
            }
            $withStaging = post('with_staging') === '1';
            $pdo = db();
            $done = [];
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
            try {
                $tables = $withStaging ? array_merge(MI_RESET_TABLES, MI_STAGING_TABLES) : MI_RESET_TABLES;
                foreach ($tables as $t) {
                    if (mi_table_exists($t)) {
                        $pdo->exec("TRUNCATE TABLE `$t`");
                        $done[] = $t;
                    }
                }
                // 14b 가 옛 운송사 이름으로 만든 운송사 (코드 T + 7자리, 순서 500) 와 (운송사 미확인)
                $n = $pdo->exec("DELETE FROM carriers
                                  WHERE code = 'UNKNOWN'
                                     OR (sort_order = 500 AND code REGEXP '^T[0-9A-F]{7}$')");
            } finally {
                $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
            }
            log_action('시스템', 'DELETE', null, null, '옛 자료 가져오기',
                       null, null, '리허설 자료 지우기 — ' . count($done) . '개 표'
                       . ($withStaging ? ' (원본 staging 포함)' : ''));
            mi_json(['status' => 'OK', 'tables' => $done, 'carriers' => $n]);
        }

        mi_json(['status' => 'FAIL', 'error' => '알 수 없는 요청'], 400);
    } catch (Throwable $e) {
        mi_json(['status' => 'FAIL', 'error' => $e->getMessage()], 500);
    }
}

// ================================================================ 화면
$logs = [];
try {
    mi_ensure_log();
    foreach (db()->query('SELECT run_key, status, affected, result_json, error_msg,
                                 TIMESTAMPDIFF(SECOND, started_at, COALESCE(ended_at, NOW())) AS elapsed_sec
                            FROM migration_run_log') as $r) {
        $logs[$r['run_key']] = $r;
    }
} catch (PDOException $e) {
    $logs = [];
}
$steps = [];
foreach (mi_steps() as $s) {
    $l = $logs[$s['key']] ?? null;
    $steps[] = [
        'key'    => $s['key'],
        'phase'  => $s['phase'],
        'head'   => mi_head($s['sql']),
        'select' => (bool)preg_match('/^\s*(SELECT|SHOW|WITH)\b/i', $s['sql']),
        'status' => $l['status'] ?? 'NONE',
        'affected' => $l ? ($l['affected'] === null ? null : (int)$l['affected']) : null,
        'result' => $l && $l['result_json'] ? json_decode($l['result_json'], true) : null,
        'error'  => $l['error_msg'] ?? null,
        'elapsed' => $l ? (int)$l['elapsed_sec'] : null,
    ];
}
$missing = [];
foreach (MI_FILES as $f => $name) {
    if (!mi_file_stmts($f)) { $missing[] = $name; }
}
$counts = mi_counts();

layout_head('옛 자료 가져오기', 'migration_import');
?>
<div class="head">
  <h1>옛 자료 가져오기</h1>
  <div class="crumb">옛 시스템 CSV → staging → 이관</div>
  <div class="right"><a class="btn" href="?p=migration">이관 검수 보기</a></div>
</div>

<?php if ($missing): ?>
  <div class="msg err">서버에 이관 SQL 파일이 없습니다: <?= h(implode(', ', $missing)) ?></div>
<?php endif; ?>

<div class="card">
  <div class="ch">지금 상태</div>
  <div class="cb">
    <div class="kpis" id="mi-counts">
      <div class="kpi"><div class="lab">원본 거래처 (staging)</div><div class="val tnum" data-k="stage_comp">-</div>
        <div class="sub">이관된 거래처 <b class="tnum" data-k="live_comp">-</b></div></div>
      <div class="kpi"><div class="lab">원본 전표 (staging)</div><div class="val tnum" data-k="stage_ship">-</div>
        <div class="sub">이관된 전표 <b class="tnum" data-k="live_ship">-</b> · 매입 <b class="tnum" data-k="purchases">-</b></div></div>
      <div class="kpi"><div class="lab">원본 매출 AMOUNT 합</div><div class="val tnum" data-k="stage_amount">-</div>
        <div class="sub">매입 TSAMOUNT 합 <b class="tnum" data-k="stage_ts">-</b></div></div>
      <div class="kpi"><div class="lab">거래처 못 찾은 전표</div><div class="val tnum" data-k="unmatched">-</div>
        <div class="sub">(거래처 미확인)에 붙은 것</div></div>
    </div>
  </div>
</div>

<div class="card">
  <div class="ch">1. 준비 <span class="badge b-info" id="ph-prep">-</span></div>
  <div class="cb">
    <p style="margin:0 0 10px;font-size:12.5px;color:var(--ink2)">
      원본을 담을 staging 표와 이력 · 보관 표를 만듭니다. 이미 있으면 건너뜁니다.</p>
    <button class="btn pri" type="button" onclick="runPhase('prep')">준비 실행</button>
  </div>
</div>

<div class="card">
  <div class="ch">2. 원본 CSV 올리기</div>
  <div class="cb">
    <p style="margin:0 0 10px;font-size:12.5px;color:var(--ink2)">
      옛 시스템에서 뽑은 <b>CUSTOMERS</b>(거래처) · <b>IS_SALES</b>(매출전표) CSV 를 고르세요.
      UTF-8 · CP949 모두 읽습니다. 파일은 서버에 남지 않고, 행만 staging 에 들어갑니다.
      같은 파일을 다시 올려도 이미 들어간 묶음은 건너뜁니다.</p>
    <div class="f">
      <div class="fw w3"><label for="f-comp">거래처 CSV (CUSTOMERS, 40칸)</label>
        <input type="file" id="f-comp" accept=".csv,text/csv"></div>
      <div class="fw w3"><label for="f-ship">매출전표 CSV (IS_SALES, 51칸)</label>
        <input type="file" id="f-ship" accept=".csv,text/csv"></div>
    </div>
    <div style="margin-top:12px;display:flex;gap:8px;align-items:center">
      <button class="btn pri" type="button" id="btn-stage" onclick="stageAll()">올리기</button>
      <span id="stage-msg" style="font-size:12.5px;color:var(--ink2)"></span>
    </div>
    <div id="stage-sum" style="margin-top:10px;font-size:12.5px"></div>
  </div>
</div>

<div class="card">
  <div class="ch">3. 이관 실행 <span class="badge b-info" id="ph-finish">-</span>
    <span class="badge b-info" id="ph-migrate">-</span></div>
  <div class="cb">
    <p style="margin:0 0 10px;font-size:12.5px;color:var(--ink2)">
      원본 지문을 채우고(15), 거래처(13b) → 매출전표·매입(14b) 순서로 옮깁니다.
      전표가 많아 몇 분 걸릴 수 있습니다. 창을 닫아도 서버에서는 계속 돌고, 다시 열면 이어서 봅니다.</p>
    <button class="btn pri" type="button" onclick="runPhases(['finish','migrate'])">이관 실행</button>
    <div id="run-msg" class="msg" style="display:none;margin-top:10px"></div>
  </div>
</div>

<div class="card">
  <div class="ch">진행 기록</div>
  <div id="steps"></div>
</div>

<div class="card">
  <div class="ch">리허설 자료 지우기</div>
  <div class="cb">
    <p style="margin:0 0 10px;font-size:12.5px;color:var(--ink2)">
      거래처 · 전표 · 매입 · 청구 · 입출금 등 <b>업무 자료를 모두 비웁니다</b>.
      사업자 · 관리자 · 권한 · 환경설정 · 단가표 · 게시판은 남습니다.
      리허설 뒤, 그리고 실제 전환 직전에 씁니다. 되돌릴 수 없습니다.</p>
    <div class="f" style="align-items:flex-end">
      <div class="fw w2"><label for="rs-confirm">확인 — '지우기' 라고 적으세요</label>
        <input type="text" id="rs-confirm" autocomplete="off"></div>
      <label style="display:flex;gap:6px;align-items:center;font-weight:400;font-size:12.5px;height:34px">
        <input type="checkbox" id="rs-staging" checked> 원본(staging)도 지우기</label>
      <button class="btn" type="button" onclick="resetAll()" style="color:var(--err-fg)">지우기</button>
    </div>
  </div>
</div>

<script>
var CSRF = <?= json_encode(csrf_token()) ?>;
var STEPS = <?= json_encode($steps, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR) ?>;
var COUNTS = <?= json_encode($counts) ?>;
var COLS = <?= json_encode(MI_COLS) ?>;
var PHASE_LABEL = {prep: '준비', finish: '원본 지문', migrate: '이관'};
var BUSY = false;

function fmt(v) {
  if (v === null || v === undefined || v === '') return '-';
  var n = Number(v);
  if (isNaN(n)) return String(v);
  return n.toLocaleString('ko-KR', {maximumFractionDigits: 2});
}
function esc(s) {
  return String(s === null || s === undefined ? '' : s).replace(/[&<>"']/g, function (c) {
    return {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c];
  });
}
function post(data) {
  var fd = new FormData();
  fd.append('_csrf', CSRF);
  Object.keys(data).forEach(function (k) { fd.append(k, data[k]); });
  return fetch(location.pathname + '?p=migration_import', {method: 'POST', body: fd, credentials: 'same-origin'})
    .then(function (r) {
      if (r.status === 419 || r.status === 403 || r.redirected) {
        var e = new Error('로그인이 끝났거나 권한이 없습니다. 새로고침 후 다시 로그인하세요.');
        e.fatal = true;
        throw e;
      }
      return r.text();
    })
    .then(function (t) {
      try { return JSON.parse(t); } catch (e) { throw new Error('응답이 JSON 이 아님'); }
    });
}
function sleep(ms) { return new Promise(function (r) { setTimeout(r, ms); }); }

function renderCounts(c) {
  COUNTS = c;
  document.querySelectorAll('#mi-counts [data-k]').forEach(function (el) {
    el.textContent = fmt(c[el.getAttribute('data-k')]);
  });
}
function refreshCounts() { return post({action: 'counts'}).then(renderCounts).catch(function () {}); }

var BADGE = {NONE: ['', '대기'], RUNNING: ['b-info', '실행중'], OK: ['b-ok', '완료'], SKIP: ['b-ok', '이미 있음'],
             WARN: ['b-warn', '경고'], FAIL: ['b-err', '실패']};
function renderSteps() {
  var html = '<div class="tscroll"><table><thead><tr><th>단계</th><th>상태</th><th class="r">행</th><th>내용</th></tr></thead><tbody>';
  STEPS.forEach(function (s) {
    var b = BADGE[s.status] || BADGE.NONE;
    html += '<tr><td class="tnum">' + esc(PHASE_LABEL[s.phase]) + ' ' + esc(s.key) + '</td>'
          + '<td><span class="badge ' + b[0] + '">' + b[1] + '</span></td>'
          + '<td class="r tnum">' + (s.affected === null || s.affected === undefined ? '' : fmt(s.affected)) + '</td>'
          + '<td style="white-space:normal;min-width:260px">' + esc(s.head);
    if (s.error) html += '<div style="color:var(--err-fg);margin-top:4px">' + esc(s.error) + '</div>';
    if (s.result && s.result.length) {
      var cols = Object.keys(s.result[0]);
      html += '<details style="margin-top:6px"' + (s.phase !== 'prep' ? ' open' : '') + '><summary>결과 '
            + s.result.length + '줄</summary><div class="tscroll"><table><thead><tr>'
            + cols.map(function (c) { return '<th>' + esc(c) + '</th>'; }).join('') + '</tr></thead><tbody>'
            + s.result.map(function (r) {
                return '<tr>' + cols.map(function (c) { return '<td>' + esc(r[c]) + '</td>'; }).join('') + '</tr>';
              }).join('')
            + '</tbody></table></div></details>';
    }
    html += '</td></tr>';
  });
  document.getElementById('steps').innerHTML = html + '</tbody></table></div>';
  ['prep', 'finish', 'migrate'].forEach(function (ph) {
    var list = STEPS.filter(function (s) { return s.phase === ph; });
    var done = list.filter(function (s) { return ['OK', 'SKIP', 'WARN'].indexOf(s.status) >= 0; }).length;
    var fail = list.some(function (s) { return s.status === 'FAIL'; });
    var el = document.getElementById('ph-' + ph);
    el.textContent = PHASE_LABEL[ph] + ' ' + done + '/' + list.length;
    el.className = 'badge ' + (fail ? 'b-err' : done === list.length ? 'b-ok' : 'b-info');
  });
}

function applyResult(s, r) {
  s.status = r.status || 'FAIL';
  s.affected = r.affected === undefined ? null : r.affected;
  s.result = r.result || null;
  s.error = r.error || null;
}

// 한 단계 실행. 연결이 끊겨도(프록시 시간초과 등) 서버에서는 계속 도니, 끝날 때까지 상태를 확인합니다
// 버튼 아래 한 줄 — 지금 무엇을 하고 있는지 (진행 기록 표는 아래에 있어 잘 안 보입니다)
function runMsg(text, kind, stopKey) {
  var el = document.getElementById('run-msg');
  if (!text) { el.style.display = 'none'; return; }
  el.style.display = '';
  el.className = 'msg ' + (kind || 'ok');
  el.innerHTML = esc(text) + (stopKey
    ? ' <button type="button" class="btn sm" style="margin-left:8px" onclick="stopStep(\'' + esc(stopKey) + '\')">중단</button>'
    : '');
}

// 너무 오래 걸리면 그 단계를 멈춥니다. 넣던 것은 DB 가 전부 되돌리고, 다시 실행하면 처음부터 합니다
function stopStep(key) {
  if (!confirm(key + ' 단계를 중단할까요?\n넣던 자료는 되돌려지고, 이관 실행을 다시 누르면 이 단계부터 다시 합니다.')) return;
  post({action: 'kill_step', key: key}).then(function (r) {
    if (r.error && r.status !== 'FAIL') { alert(r.error); }
  }).catch(function (e) { alert(e.message); });
}
function elapsed(ms) {
  var s = Math.floor(ms / 1000);
  return (s >= 60 ? Math.floor(s / 60) + '분 ' : '') + (s % 60) + '초';
}

function runStep(s) {
  s.status = 'RUNNING'; s.error = null; renderSteps();
  // 경과 시간은 서버가 이 단계를 시작한 때부터 (창을 새로 열어도 0 부터 다시 세지 않게)
  var started = Date.now() - ((s.elapsed || 0) * 1000), resent = 0;
  var tick = setInterval(function () {
    runMsg(PHASE_LABEL[s.phase] + ' ' + s.key + ' 실행 중 — ' + elapsed(Date.now() - started)
           + ' 경과. ' + (s.key.indexOf('14b') === 0 ? '전표 49,360건이라 몇 분 걸릴 수 있습니다. ' : '')
           + '창을 닫아도 서버에서는 계속 돕니다.'
           + (Date.now() - started > 5 * 60 * 1000 ? ' 너무 오래 걸리면 중단하고 다시 실행하세요.' : ''),
           'ok', s.key);
  }, 1000);
  function soft(e) {
    if (e && e.fatal) return {status: 'FAIL', error: e.message};
    return {status: 'RUNNING'};
  }
  return post({action: 'run_step', key: s.key})
    .catch(soft)
    .then(function poll(r) {
      // 서버에 기록이 없다 = 실행 요청이 가지도 못했다 → 다시 보냅니다
      if (r.status === 'NONE' && resent < 3) {
        resent++;
        return post({action: 'run_step', key: s.key}).catch(soft).then(poll);
      }
      if (r.elapsed !== undefined && r.elapsed !== null) { started = Date.now() - r.elapsed * 1000; }
      if (r.status !== 'RUNNING') { clearInterval(tick); applyResult(s, r); renderSteps(); return s.status; }
      return sleep(5000).then(function () {
        return post({action: 'status', key: s.key}).catch(soft);
      }).then(poll);
    });
}

async function runPhases(phases) {
  if (BUSY) {
    alert('이미 실행 중입니다. 버튼 아래 안내에 경과 시간이 나옵니다. 끝날 때까지 기다려 주세요.');
    return;
  }
  if (phases.indexOf('migrate') >= 0 && !(Number(COUNTS.stage_comp) > 0 && Number(COUNTS.stage_ship) > 0)) {
    alert('원본(staging)이 비어 있습니다. 2단계에서 거래처 · 매출전표 CSV 를 먼저 올리세요.');
    return;
  }
  BUSY = true;
  try {
    for (var p = 0; p < phases.length; p++) {
      var list = STEPS.filter(function (s) { return s.phase === phases[p]; });
      for (var i = 0; i < list.length; i++) {
        if (['OK', 'SKIP', 'WARN'].indexOf(list[i].status) >= 0) continue;
        var st = await runStep(list[i]);
        if (st === 'FAIL') {
          runMsg(PHASE_LABEL[phases[p]] + ' 단계 ' + list[i].key + ' 에서 멈췄습니다: ' + (list[i].error || ''), 'err');
          alert(PHASE_LABEL[phases[p]] + ' 단계 ' + list[i].key + ' 에서 멈췄습니다.\n' + (list[i].error || ''));
          return;
        }
      }
    }
    runMsg(phases.map(function (x) { return PHASE_LABEL[x]; }).join(' · ') + ' 끝났습니다. 위 "지금 상태" 숫자를 확인하세요.', 'ok');
  } finally {
    BUSY = false;
    refreshCounts();
  }
}
function runPhase(ph) { return runPhases([ph]); }

// ---------------------------------------------------------------- CSV
function decode(buf) {
  try { return new TextDecoder('utf-8', {fatal: true}).decode(buf); }
  catch (e) { return new TextDecoder('euc-kr').decode(buf); }
}
function parseCsv(text) {
  if (text.charCodeAt(0) === 0xFEFF) text = text.slice(1);
  var rows = [], row = [], f = '', q = false, i = 0, n = text.length, c;
  while (i < n) {
    c = text[i];
    if (q) {
      if (c === '"') {
        if (text[i + 1] === '"') { f += '"'; i += 2; continue; }
        q = false; i++; continue;
      }
      f += c; i++; continue;
    }
    if (c === '"') { q = true; i++; continue; }
    if (c === ',') { row.push(f); f = ''; i++; continue; }
    if (c === '\r') { i++; continue; }
    if (c === '\n') { row.push(f); rows.push(row); row = []; f = ''; i++; continue; }
    f += c; i++;
  }
  if (f !== '' || row.length) { row.push(f); rows.push(row); }
  return rows;
}
function toNum(v) {
  var t = String(v || '').replace(/[,\s]/g, '');
  if (t === '' || isNaN(Number(t))) return 0;
  return Number(t);
}

async function stageFile(kind, file) {
  var msg = document.getElementById('stage-msg');
  var label = kind === 'companies' ? '거래처' : '매출전표';
  msg.textContent = label + ' 파일 읽는 중…';
  var rows = parseCsv(decode(await file.arrayBuffer()));
  while (rows.length && rows[rows.length - 1].length === 1 && rows[rows.length - 1][0] === '') rows.pop();
  var header = rows.shift() || [];
  if (JSON.stringify(header) !== JSON.stringify(COLS[kind])) {
    throw new Error(label + ' CSV 첫 줄이 옛 시스템 형식(' + COLS[kind].length + '칸)과 다릅니다. 지금 ' + header.length + '칸.');
  }
  var bad = rows.findIndex(function (r) { return r.length !== header.length; });
  if (bad >= 0) throw new Error(label + ' CSV ' + (bad + 2) + '번째 줄의 칸 수가 ' + rows[bad].length + '개입니다.');

  var sum = {rows: rows.length, amount: 0, ts: 0};
  if (kind === 'shipments') {
    var ia = header.indexOf('AMOUNT'), it = header.indexOf('TSAMOUNT');
    rows.forEach(function (r) { sum.amount += toNum(r[ia]); sum.ts += toNum(r[it]); });
  }
  var sig = file.name + '|' + file.size + '|' + file.lastModified;
  var size = 400, total = Math.ceil(rows.length / size);
  for (var b = 0; b < total; b++) {
    var payload = JSON.stringify({header: header, rows: rows.slice(b * size, (b + 1) * size),
                                  batch: b, sig: sig, file: file.name});
    var r = null;
    for (var tries = 0; tries < 3; tries++) {
      try { r = await post({action: 'stage', kind: kind, payload: payload}); break; }
      catch (e) { await sleep(3000); }
    }
    if (!r) throw new Error(label + ' ' + (b + 1) + '번째 묶음을 보내지 못했습니다 (연결 끊김). 다시 누르면 이어서 올립니다.');
    if (r.status !== 'OK') throw new Error(r.error || '실패');
    msg.textContent = label + ' ' + Math.min((b + 1) * size, rows.length).toLocaleString() + ' / '
                    + rows.length.toLocaleString() + '행';
  }
  return sum;
}

async function stageAll() {
  if (BUSY) return;
  var fc = document.getElementById('f-comp').files[0];
  var fs = document.getElementById('f-ship').files[0];
  if (!fc && !fs) { alert('CSV 파일을 고르세요.'); return; }
  BUSY = true;
  var btn = document.getElementById('btn-stage');
  btn.disabled = true;
  var out = [];
  try {
    if (fc) { var a = await stageFile('companies', fc); out.push('거래처 파일 ' + a.rows.toLocaleString() + '행'); }
    if (fs) {
      var s = await stageFile('shipments', fs);
      out.push('전표 파일 ' + s.rows.toLocaleString() + '행 · AMOUNT 합 ' + fmt(Math.round(s.amount * 100) / 100)
               + ' · TSAMOUNT 합 ' + fmt(Math.round(s.ts * 100) / 100));
    }
    document.getElementById('stage-msg').textContent = '올리기 끝';
    document.getElementById('stage-sum').innerHTML = '<b>파일에서 센 값</b> — ' + esc(out.join(' / '))
      + '<br><span style="color:var(--ink2)">위 "지금 상태" 의 원본 숫자와 같아야 합니다.'
      + ' 같은 파일을 두 번 올렸다면 건너뛰었으니 그대로입니다.</span>';
  } catch (e) {
    document.getElementById('stage-msg').textContent = '';
    alert(e.message);
  } finally {
    BUSY = false;
    btn.disabled = false;
    refreshCounts();
  }
}

async function resetAll() {
  if (BUSY) return;
  var conf = document.getElementById('rs-confirm').value.trim();
  if (conf !== '지우기') { alert("확인란에 '지우기' 라고 적으세요."); return; }
  if (!confirm('업무 자료를 모두 비웁니다. 되돌릴 수 없습니다. 계속할까요?')) return;
  BUSY = true;
  try {
    var r = await post({action: 'reset', confirm: conf,
                        with_staging: document.getElementById('rs-staging').checked ? '1' : '0'});
    if (r.status !== 'OK') throw new Error(r.error || '실패');
    alert('지웠습니다 — 표 ' + r.tables.length + '개');
    location.reload();
  } catch (e) {
    alert(e.message);
  } finally {
    BUSY = false;
  }
}

renderCounts(COUNTS);
renderSteps();
// 서버에서 돌고 있는 단계가 있으면(창을 닫았다 다시 연 경우) 이어서 지켜보고, 끝나면 다음 단계로 갑니다
(function () {
  var running = STEPS.filter(function (s) { return s.status === 'RUNNING'; })[0];
  if (!running) return;
  runPhases(running.phase === 'prep' ? ['prep'] : ['finish', 'migrate']);
})();
</script>
<?php
layout_foot();
