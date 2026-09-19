<?php
declare(strict_types=1);

// 웹에서 직접 열면 실행되지 않게 막습니다 (nginx 면 .htaccess 가 무시됩니다)
if (!defined('APP_DIR')) { http_response_code(403); exit('Forbidden'); }

require_once APP_DIR . '/filestore.php';

/**
 * DB 백업 → NAS (/backup/db). 거래처 · 전표 · 입금 기록 등 ERP 표 전체 + 홈페이지 게시판 표.
 *   · 하루 한 번 — ERP 화면이 열려 있으면 자동 작업(track_tick)이 24시간 지난 걸 보고 돌립니다. [지금 백업] 버튼도 있음
 *   · mysqldump 없이 PHP 로 만듭니다: 표 구조(CREATE) + 자료(INSERT 200줄씩) + 뷰 + 트리거, gzip
 *   · 파일 이름 goodpost_erp_YYYYMMDD_HHMM.sql.gz, 보관 개수는 환경설정(기본 30개) — 넘는 오래된 것은 NAS 에서 지움
 *   · 복구: 파일을 풀어서 MySQL 에 넣기 (mysql -u … DB이름 < 파일.sql) — 전체를 덮어쓰므로 조심
 *   · 백업 파일에는 환경설정의 비밀번호 · 키도 들어 있습니다. NAS backup 폴더는 비공개로 두세요.
 */

function dbbackup_ensure_table(PDO $pdo): void
{
    static $done = false;
    if ($done) { return; }
    $pdo->exec("CREATE TABLE IF NOT EXISTS db_backups (
                  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                  file_name   VARCHAR(120) NOT NULL,
                  nas_path    VARCHAR(300) NULL,
                  size_bytes  BIGINT UNSIGNED NULL,
                  sha256      CHAR(64) NULL,
                  tables_n    INT NULL,
                  rows_n      BIGINT NULL,
                  seconds     INT NULL,
                  status      VARCHAR(10) NOT NULL COMMENT 'OK / FAIL / PRUNED(보관 개수 넘어 지움)',
                  message     VARCHAR(500) NULL,
                  trigger_by  VARCHAR(20) NULL COMMENT 'auto / 관리자 이름',
                  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                  KEY ix_dbb (status, created_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='DB 백업 기록 (파일은 NAS)'");
    $done = true;
}

/** 백업 전용 연결 — 큰 표를 한 번에 메모리에 올리지 않게(unbuffered) 따로 엽니다 */
function dbbackup_conn(): PDO
{
    global $CFG;
    $d = $CFG['db'];
    $pdo = new PDO(sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $d['host'], (int)$d['port'], $d['name']),
                   $d['user'], $d['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_NUM,
                                            PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => false]);
    $pdo->exec('SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci');
    $pdo->exec("SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ");
    $pdo->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT');   // 백업 도중 들어온 자료로 앞뒤가 안 맞지 않게
    return $pdo;
}

/** SQL 덤프를 gz 파일로. [표 수, 줄 수] */
function dbbackup_dump(string $gzFile): array
{
    $meta = db();   // 목록 · 구조 조회용 (buffered)
    $pdo  = dbbackup_conn();
    $gz = gzopen($gzFile, 'wb6');
    if (!$gz) { throw new RuntimeException('임시 백업 파일을 만들지 못함'); }
    $dbName = (string)$meta->query('SELECT DATABASE()')->fetchColumn();
    gzwrite($gz, "-- GOODPOST ERP DB 백업 " . date('Y-m-d H:i:s') . " · DB " . $dbName . "\n"
               . "-- 복구: gunzip 후 mysql -u 사용자 -p " . $dbName . " < 이파일.sql  (전체를 덮어씀)\n"
               . "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\nSET UNIQUE_CHECKS=0;\nSET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n\n");
    $tables = [];
    $views = [];
    foreach ($meta->query('SHOW FULL TABLES')->fetchAll(PDO::FETCH_NUM) as $r) {
        if ($r[1] === 'VIEW') { $views[] = $r[0]; } else { $tables[] = $r[0]; }
    }
    $rowsTotal = 0;
    foreach ($tables as $t) {
        $q = '`' . str_replace('`', '``', $t) . '`';
        $create = $meta->query('SHOW CREATE TABLE ' . $q)->fetch(PDO::FETCH_NUM)[1];
        gzwrite($gz, "-- ----- " . $t . "\nDROP TABLE IF EXISTS " . $q . ";\n" . $create . ";\n");
        // 바이너리 칸은 0x… 로 (암호화된 값 등)
        $bin = [];
        $st = $meta->prepare("SELECT COLUMN_NAME, DATA_TYPE FROM information_schema.COLUMNS
                               WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION");
        $st->execute([$t]);
        foreach ($st->fetchAll(PDO::FETCH_NUM) as $i => [$cn, $dt]) {
            $bin[$i] = (bool)preg_match('/binary|blob/i', (string)$dt);
        }
        $res = $pdo->query('SELECT * FROM ' . $q);
        $buf = [];
        while (($row = $res->fetch(PDO::FETCH_NUM)) !== false) {
            $vals = [];
            foreach ($row as $i => $v) {
                if ($v === null)        { $vals[] = 'NULL'; }
                elseif (!empty($bin[$i])) { $vals[] = $v === '' ? "''" : '0x' . bin2hex((string)$v); }
                else                    { $vals[] = $meta->quote((string)$v); }
            }
            $buf[] = '(' . implode(',', $vals) . ')';
            $rowsTotal++;
            if (count($buf) >= 200) {
                gzwrite($gz, 'INSERT INTO ' . $q . ' VALUES ' . implode(",\n", $buf) . ";\n");
                $buf = [];
            }
        }
        $res->closeCursor();
        if ($buf) { gzwrite($gz, 'INSERT INTO ' . $q . ' VALUES ' . implode(",\n", $buf) . ";\n"); }
        gzwrite($gz, "\n");
    }
    foreach ($views as $v) {
        $q = '`' . str_replace('`', '``', $v) . '`';
        $create = (string)$meta->query('SHOW CREATE VIEW ' . $q)->fetch(PDO::FETCH_NUM)[1];
        $create = (string)preg_replace('/\sDEFINER=`[^`]*`@`[^`]*`/', '', $create);
        $create = (string)preg_replace('/^CREATE\s+/', 'CREATE OR REPLACE ', $create);
        gzwrite($gz, "-- ----- 뷰 " . $v . "\nDROP TABLE IF EXISTS " . $q . ";\n" . $create . ";\n\n");
    }
    foreach ($meta->query('SHOW TRIGGERS')->fetchAll(PDO::FETCH_NUM) as $tr) {
        $q = '`' . str_replace('`', '``', $tr[0]) . '`';
        $create = (string)$meta->query('SHOW CREATE TRIGGER ' . $q)->fetch(PDO::FETCH_NUM)[2];
        $create = (string)preg_replace('/\sDEFINER=`[^`]*`@`[^`]*`/', '', $create);
        gzwrite($gz, "-- ----- 트리거 " . $tr[0] . "\nDROP TRIGGER IF EXISTS " . $q . ";\nDELIMITER ;;\n" . $create . ";;\nDELIMITER ;\n\n");
    }
    gzwrite($gz, "SET FOREIGN_KEY_CHECKS=1;\nSET UNIQUE_CHECKS=1;\n-- 끝\n");
    gzclose($gz);
    $pdo->exec('COMMIT');
    return [count($tables), $rowsTotal];
}

/** 백업 한 번 — 만들고 NAS 로 보내고 기록. [성공?, 설명] */
function dbbackup_run(string $by = 'auto'): array
{
    $pdo = db();
    dbbackup_ensure_table($pdo);
    $c = fs_cfg();
    if (!$c['nas']) { return [false, '파일 저장소가 NAS 모드가 아니라 백업할 곳이 없습니다 (환경설정 → 파일 저장소)']; }
    if (!(int)$pdo->query("SELECT GET_LOCK('gp_db_backup', 0)")->fetchColumn()) { return [false, '다른 백업이 진행 중입니다']; }
    @set_time_limit(900);
    $t0 = microtime(true);
    $name = 'goodpost_erp_' . date('Ymd_Hi') . '.sql.gz';
    $tmp = tempnam(sys_get_temp_dir(), 'gpdb');
    try {
        [$tn, $rn] = dbbackup_dump($tmp);
        $size = (int)filesize($tmp);
        $sha = (string)hash_file('sha256', $tmp);
        $dir = $c['dir']['backup'];
        // 백업 폴더(예 /backup/db)의 하위 폴더는 없으면 만듭니다 — 맨 위 공유폴더(backup)는 NAS 에서 미리
        [$code, , $err] = fs_dav('PUT', fs_dav_url('backup', $name), $tmp, null, 600);
        if (in_array($code, [404, 409], true) && fs_dav_mkdirs_abs($dir)) {
            [$code, , $err] = fs_dav('PUT', fs_dav_url('backup', $name), $tmp, null, 600);
        }
        if (!in_array($code, [200, 201, 204], true)) {
            throw new RuntimeException('NAS 저장 실패 (HTTP ' . $code . ($err !== '' ? ' · ' . $err : '') . ') — ' . $dir . ' 폴더와 권한 확인');
        }
        $pdo->prepare("INSERT INTO db_backups (file_name, nas_path, size_bytes, sha256, tables_n, rows_n, seconds, status, message, trigger_by)
                       VALUES (?,?,?,?,?,?,?,'OK',?,?)")
            ->execute([$name, $dir . '/' . $name, $size, $sha, $tn, $rn, (int)(microtime(true) - $t0),
                       '표 ' . $tn . '개 · ' . number_format($rn) . '줄', mb_substr($by, 0, 20)]);
        dbbackup_prune($pdo);
        return [true, 'DB 백업 완료 — ' . $name . ' (' . number_format($size / 1048576, 1) . 'MB, 표 ' . $tn . '개 · ' . number_format($rn) . '줄)'];
    } catch (Throwable $e) {
        error_log('DB 백업 실패: ' . $e->getMessage());
        $pdo->prepare("INSERT INTO db_backups (file_name, status, message, seconds, trigger_by) VALUES (?, 'FAIL', ?, ?, ?)")
            ->execute([$name, mb_substr($e->getMessage(), 0, 500), (int)(microtime(true) - $t0), mb_substr($by, 0, 20)]);
        return [false, 'DB 백업 실패 — ' . $e->getMessage()];
    } finally {
        @unlink($tmp);
        $pdo->query("SELECT RELEASE_LOCK('gp_db_backup')");
    }
}

/** 절대 WebDAV 경로 기준으로 폴더 만들기 (예 /backup/db) */
function fs_dav_mkdirs_abs(string $absDir): bool
{
    $c = fs_cfg();
    $acc = '';
    foreach (array_filter(explode('/', $absDir), 'strlen') as $seg) {
        $acc .= '/' . $seg;
        [$code] = fs_dav('MKCOL', $c['url'] . implode('/', array_map('rawurlencode', explode('/', $acc))) . '/', null, null, 15);
        if (!in_array($code, [201, 405, 301, 200], true)) { return false; }
    }
    return true;
}

/** 보관 개수를 넘은 오래된 백업을 NAS 에서 지움 */
function dbbackup_prune(PDO $pdo): void
{
    $keep = max(3, (int)(db()->query("SELECT setting_val FROM app_settings WHERE setting_key = 'fs_backup_keep'")->fetchColumn() ?: 30));
    $old = $pdo->query("SELECT id, file_name FROM db_backups WHERE status = 'OK' ORDER BY id DESC LIMIT 1000 OFFSET " . $keep)->fetchAll();
    foreach ($old as $o) {
        [$code] = fs_dav('DELETE', fs_dav_url('backup', (string)$o['file_name']), null, null, 20);
        if (in_array($code, [200, 204, 404], true)) {
            $pdo->prepare("UPDATE db_backups SET status = 'PRUNED' WHERE id = ?")->execute([(int)$o['id']]);
        }
    }
}

/** 자동 — 마지막 성공이 23시간 넘었으면 (실패는 3시간 뒤 다시) */
function dbbackup_due(PDO $pdo): bool
{
    dbbackup_ensure_table($pdo);
    if (!fs_cfg()['nas']) { return false; }
    $ok = $pdo->query("SELECT MAX(created_at) FROM db_backups WHERE status = 'OK'")->fetchColumn();
    $fail = $pdo->query("SELECT MAX(created_at) FROM db_backups WHERE status = 'FAIL'")->fetchColumn();
    if ($ok && strtotime((string)$ok) > time() - 23 * 3600) { return false; }
    if ($fail && strtotime((string)$fail) > time() - 3 * 3600) { return false; }
    return true;
}
