<?php
declare(strict_types=1);

// 웹에서 직접 열면 실행되지 않게 막습니다 (nginx 면 .htaccess 가 무시됩니다)
if (!defined('APP_DIR')) { http_response_code(403); exit('Forbidden'); }

/**
 * 파일 저장소 — 파일은 DB 가 아니라 파일 서버에, DB 에는 상대경로 · 메타데이터만.
 *
 *   구역(area)            NAS (WebDAV) 위치 — 환경설정 '파일 저장소' 에서 바꿈     공개 여부
 *   ─────────────────────────────────────────────────────────────────────────────────────────────
 *   docs    업무 서류       /erp/documents   (인보이스 · AWB · 신고필증 …)          비공개 — ERP 로그인 · 권한 확인 후 ERP 가 대신 받아 내려줌
 *   uploads 기타 첨부       /erp/uploads     (직인 · 거래처 서류 …)                 비공개 — 〃
 *   public  공개 이미지     /web/images/erp  → https://…:8443/images/erp/…          공개 — 주소만 알면 누구나 (홈페이지 · 메일용 그림)
 *
 * 흐름: 올린 파일은 먼저 이 서버(storage_root)에 저장 → NAS 모드면 바로 NAS 로 PUT.
 *       NAS 가 안 붙으면 서버에 둔 채 'LOCAL' 로 남기고, 자동 작업(track_tick)이 나중에 다시 보냅니다.
 *       → NAS 가 꺼져 있어도 업로드 자체는 실패하지 않습니다.
 * 읽기: 서버에 사본이 있으면 그것, 없으면 NAS 에서 (비공개는 항상 ERP 를 거쳐서만).
 * '서버에도 사본 유지' = 예 이면 서버 · NAS 두 곳에 있어 한쪽이 망가져도 남습니다 (기본값).
 */

const FS_AREAS = ['docs', 'uploads', 'public', 'backup'];   // backup = DB 백업 (app/dbbackup.php)

/** 환경설정 값 한 번에 */
function fs_cfg(): array
{
    static $c = null;
    if ($c !== null) { return $c; }
    $keys = ['fs_mode', 'fs_webdav_url', 'fs_webdav_user', 'fs_webdav_pass', 'fs_dir_docs', 'fs_dir_uploads',
             'fs_dir_public', 'fs_public_base_url', 'fs_keep_local', 'fs_dir_backup'];
    $v = array_fill_keys($keys, '');
    try {
        $st = db()->query("SELECT setting_key, setting_val FROM app_settings WHERE setting_key LIKE 'fs\\_%'");
        foreach ($st->fetchAll() as $r) { $v[$r['setting_key']] = (string)$r['setting_val']; }
    } catch (PDOException $e) {
        // 표가 없으면 로컬 모드
    }
    $trim = fn(string $s) => rtrim(trim($s), '/');
    $dir  = fn(string $s, string $def) => '/' . trim(trim($s) !== '' ? trim($s) : $def, '/');
    return $c = [
        // 비밀번호가 오가므로 https 주소만 받습니다
        'nas'        => $v['fs_mode'] === 'NAS' && stripos(trim($v['fs_webdav_url']), 'https://') === 0,
        'url'        => $trim($v['fs_webdav_url']),
        'user'       => trim($v['fs_webdav_user']),
        'pass'       => (string)preg_replace('/[\r\n]+/', '', $v['fs_webdav_pass']),
        'dir'        => ['docs'    => $dir($v['fs_dir_docs'], '/erp/documents'),
                         'uploads' => $dir($v['fs_dir_uploads'], '/erp/uploads'),
                         'public'  => $dir($v['fs_dir_public'], '/web/images/erp'),
                         'backup'  => $dir($v['fs_dir_backup'], '/backup/db')],
        'public_url' => $trim($v['fs_public_base_url']),
        'keep_local' => $v['fs_keep_local'] !== '아니오',
    ];
}

/** 상대경로 검사 — 영문 · 숫자 · _ - . / 만, .. 금지 */
function fs_rel_ok(string $rel): bool
{
    return $rel !== '' && $rel[0] !== '/' && strpos($rel, '..') === false
        && (bool)preg_match('#^[A-Za-z0-9_./-]+$#', $rel);
}

/** 이 서버 안의 위치 — docs 는 예전과 같은 storage_root(), 나머지는 그 아래 구역 폴더 */
function fs_local_path(string $area, string $rel): string
{
    $base = storage_root();
    if ($area !== 'docs') { $base .= DIRECTORY_SEPARATOR . '_' . $area; }
    return $base . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
}

/** WebDAV 주소 (경로 조각마다 인코딩) */
function fs_dav_url(string $area, string $rel = ''): string
{
    $c = fs_cfg();
    $path = $c['dir'][$area] . ($rel !== '' ? '/' . $rel : '');
    return $c['url'] . implode('/', array_map('rawurlencode', explode('/', $path)));
}

/**
 * WebDAV 요청. [HTTP 코드, 본문|null, 오류]. $inFile 이 있으면 PUT 본문, $outStream 이 있으면 받은 본문을 거기에
 */
function fs_dav(string $method, string $url, ?string $inFile = null, $outStream = null, int $timeout = 60): array
{
    $c = fs_cfg();
    if (!function_exists('curl_init')) { return [0, null, '서버에 curl 이 없습니다']; }
    $ch = curl_init($url);
    $opt = [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_USERPWD => $c['user'] . ':' . $c['pass'],
            // Basic 을 처음부터 보냅니다 (HTTPS 전용). 인증 방식 떠보기를 하면 PUT 본문을 두 번 보내야 해서 실패함
            CURLOPT_HTTPAUTH => CURLAUTH_BASIC, CURLOPT_CONNECTTIMEOUT => 6,
            CURLOPT_TIMEOUT => $timeout, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2];
    $fh = null;
    if ($inFile !== null) {
        $fh = fopen($inFile, 'rb');
        $opt += [CURLOPT_UPLOAD => true, CURLOPT_INFILE => $fh, CURLOPT_INFILESIZE => (int)filesize($inFile)];
    }
    if ($outStream !== null) {
        // php://temp 는 CURLOPT_FILE 에 못 넘기므로(파일 핸들 아님) 받은 조각을 직접 씁니다
        $opt[CURLOPT_WRITEFUNCTION] = static fn($ch, string $chunk): int => (int)fwrite($outStream, $chunk);
    } else {
        $opt[CURLOPT_RETURNTRANSFER] = true;
    }
    if ($method === 'HEAD') { $opt[CURLOPT_NOBODY] = true; }
    curl_setopt_array($ch, $opt);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    if ($fh) { fclose($fh); }
    return [$code, is_string($body) ? $body : null, $err];
}

/** 폴더를 위에서부터 차례로 만듭니다 (이미 있으면 405 — 괜찮음). 구역 맨 위 폴더는 NAS 에서 미리 만들어 둬야 함 */
function fs_dav_mkdirs(string $area, string $relDir): bool
{
    $acc = '';
    foreach (array_filter(explode('/', $relDir), 'strlen') as $seg) {
        $acc .= ($acc === '' ? '' : '/') . $seg;
        [$code] = fs_dav('MKCOL', fs_dav_url($area, $acc) . '/', null, null, 15);
        if (!in_array($code, [201, 405, 301, 200], true)) { return false; }
    }
    return true;
}

/** 서버에 있는 파일 하나를 NAS 로. 성공하면 true (+ 설정에 따라 서버 사본 삭제) */
function fs_push(string $area, string $rel, ?string &$why = null): bool
{
    $c = fs_cfg();
    $why = '';
    if (!$c['nas']) { $why = 'NAS 모드가 아님'; return false; }
    if (!in_array($area, FS_AREAS, true) || !fs_rel_ok($rel)) { $why = '경로 오류'; return false; }
    $local = fs_local_path($area, $rel);
    if (!is_file($local)) { $why = '서버에 파일 없음'; return false; }
    $dir = trim(dirname($rel), '.');
    if ($dir !== '' && !fs_dav_mkdirs($area, $dir)) {
        $why = 'NAS 폴더를 만들지 못함 (' . fs_cfg()['dir'][$area] . ' 가 NAS 에 있는지 · 권한 확인)';
        return false;
    }
    [$code, , $err] = fs_dav('PUT', fs_dav_url($area, $rel), $local, null, 300);
    if (!in_array($code, [200, 201, 204], true)) {
        $why = 'NAS 저장 실패 (HTTP ' . $code . ($err !== '' ? ' · ' . $err : '') . ')';
        return false;
    }
    if (!$c['keep_local']) { @unlink($local); }
    return true;
}

/** 파일 내용을 임시 스트림으로 — 서버 사본이 있으면 그것, 없으면 NAS. 없으면 null */
function fs_open(string $area, string $rel)
{
    if (!in_array($area, FS_AREAS, true) || !fs_rel_ok($rel)) { return null; }
    $local = fs_local_path($area, $rel);
    if (is_file($local)) { return fopen($local, 'rb') ?: null; }
    if (!fs_cfg()['nas']) { return null; }
    $tmp = fopen('php://temp/maxmemory:2097152', 'w+b');
    [$code] = fs_dav('GET', fs_dav_url($area, $rel), null, $tmp, 120);
    if ($code !== 200) { fclose($tmp); return null; }
    rewind($tmp);
    return $tmp;
}

/** 내용 전체 (작은 파일 — 직인 등) */
function fs_read(string $area, string $rel): ?string
{
    $h = fs_open($area, $rel);
    if (!$h) { return null; }
    $s = stream_get_contents($h);
    fclose($h);
    return is_string($s) ? $s : null;
}

/**
 * 비공개 파일 내려보내기 — 부르기 전에 로그인 · 권한 확인은 부른 쪽에서 끝나 있어야 합니다.
 * NAS 주소는 밖으로 절대 내보내지 않고, ERP 가 받아서 그대로 흘려줍니다.
 */
function fs_send_private(string $area, string $rel, string $downloadName, ?string $mime = null, bool $inline = false): bool
{
    if ($area === 'public') { return false; }
    $h = fs_open($area, $rel);
    if (!$h) { return false; }
    $stat = fstat($h);
    $safe = (string)preg_replace('/[\r\n"]/', '', $downloadName) ?: 'file';
    header('Content-Type: ' . ($inline && $mime ? $mime : 'application/octet-stream'));
    if (!empty($stat['size'])) { header('Content-Length: ' . $stat['size']); }
    header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . rawurlencode($safe) . '"; '
           . "filename*=UTF-8''" . rawurlencode($safe));
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, no-store');
    fpassthru($h);
    fclose($h);
    return true;
}

/** 공개 이미지 주소 — 기준 주소(fs_public_base_url)를 바꾸면 모든 그림 주소가 같이 바뀝니다 */
function fs_public_url(string $rel): string
{
    $base = fs_cfg()['public_url'];
    return $base !== '' ? $base . '/' . implode('/', array_map('rawurlencode', explode('/', $rel))) : '';
}

/** 공개 이미지 올리기 — NAS 모드에서만 (공개 주소는 NAS 웹서버가 내줌). 성공하면 public_files id */
function fs_store_public_image(array $f, string $purpose, ?string &$why = null): int
{
    $why = '';
    $c = fs_cfg();
    if (!$c['nas'] || $c['public_url'] === '') {
        $why = '공개 이미지는 NAS 모드 + 공개 기준 주소가 있어야 올릴 수 있습니다 (환경설정 → 파일 저장소)';
        return 0;
    }
    if (($f['error'] ?? 1) !== UPLOAD_ERR_OK || !is_uploaded_file((string)$f['tmp_name'])) { $why = '업로드 실패'; return 0; }
    if ((int)$f['size'] > 10 * 1024 * 1024) { $why = '10MB 이하 그림만'; return 0; }
    $info = @getimagesize((string)$f['tmp_name']);
    $ext = $info ? (['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/gif' => 'gif', 'image/webp' => 'webp'][$info['mime']] ?? '') : '';
    if ($ext === '') { $why = 'PNG · JPG · GIF · WEBP 그림만'; return 0; }
    $rel = date('Y') . '/' . date('m') . '/' . bin2hex(random_bytes(12)) . '.' . $ext;
    $local = fs_local_path('public', $rel);
    if (!is_dir(dirname($local)) && !@mkdir(dirname($local), 0750, true)) { $why = '임시 폴더를 만들지 못함'; return 0; }
    if (!@move_uploaded_file((string)$f['tmp_name'], $local)) { $why = '파일을 받지 못함'; return 0; }
    $sha = hash_file('sha256', $local) ?: null;
    $size = (int)filesize($local);
    if (!fs_push('public', $rel, $why)) { @unlink($local); return 0; }
    db()->prepare('INSERT INTO public_files (rel_path, original_name, mime_type, size_bytes, width, height,
                                             checksum_sha256, purpose, uploaded_by)
                   VALUES (?,?,?,?,?,?,?,?,?)')
        ->execute([$rel, mb_substr((string)$f['name'], 0, 255), $info['mime'], $size, (int)$info[0], (int)$info[1],
                   $sha, mb_substr($purpose, 0, 50), $_SESSION['admin_id'] ?? null]);
    $id = (int)db()->lastInsertId();
    log_action('시스템', 'CREATE', 'public_files', $id, (string)$f['name'], null, '공개 이미지 → NAS ' . $rel);
    return $id;
}

/**
 * 아직 NAS 에 없는 업무 서류를 보냅니다 (업로드 때 NAS 가 안 붙었던 것 · 모드를 NAS 로 바꾸기 전 서류).
 * 자동 작업(track_tick)과 저장소 설정의 [지금 보내기]가 부릅니다. [보냄, 실패, 마지막 실패 이유]
 */
function fs_sync_pending(PDO $pdo, int $limit = 10): array
{
    if (!fs_cfg()['nas']) { return [0, 0, 'NAS 모드가 아님']; }
    $st = $pdo->prepare("SELECT id, stored_path FROM documents
                          WHERE storage = 'LOCAL' AND deleted_at IS NULL
                            AND stored_path REGEXP '^[0-9]{4}/[0-9]{2}/[0-9a-f]{32}[.][a-z0-9]+$'
                          ORDER BY id LIMIT " . max(1, min(200, $limit)));
    $st->execute();
    $ok = 0; $bad = 0; $why = '';
    $upd = $pdo->prepare("UPDATE documents SET storage = 'NAS', backup_status = 'SYNCED', backup_at = NOW(),
                                 backup_path = ? WHERE id = ?");
    foreach ($st->fetchAll() as $d) {
        if (fs_push('docs', (string)$d['stored_path'], $w)) {
            $upd->execute(['NAS:' . fs_cfg()['dir']['docs'] . '/' . $d['stored_path'], (int)$d['id']]);
            $ok++;
        } else {
            $bad++;
            $why = $w;
            if ($bad >= 3) { break; }   // NAS 가 안 붙는 중이면 더 두드리지 않음
        }
    }
    return [$ok, $bad, $why];
}

/** 연결 시험 — 구역마다 폴더 확인 → 시험 파일 쓰기 · 읽기 · 지우기. [구역 => [성공?, 설명]] */
function fs_test(): array
{
    $c = fs_cfg();
    $out = [];
    if (!$c['nas']) { return ['설정' => [false, 'NAS 모드가 아니거나 WebDAV 주소가 비어 있습니다']]; }
    $probe = tempnam(sys_get_temp_dir(), 'gpfs');
    file_put_contents($probe, 'GOODPOST ERP 연결 시험 ' . date('c'));
    foreach (FS_AREAS as $area) {
        $rel = '_erp_test_' . bin2hex(random_bytes(4)) . '.txt';
        [$code, , $err] = fs_dav('PUT', fs_dav_url($area, $rel), $probe, null, 20);
        if (!in_array($code, [200, 201, 204], true)) {
            $hint = [0 => '접속 안 됨 — 주소 · 포트(공유기 포트포워딩) · 인증서 확인', 401 => '계정 · 비밀번호가 틀림',
                     403 => '이 계정에 그 폴더 쓰기 권한이 없음', 404 => '폴더가 NAS 에 없음 (공유폴더 · 하위폴더를 만들어 두세요)',
                     409 => '상위 폴더가 NAS 에 없음 (공유폴더 · 하위폴더를 만들어 두세요)'][$code] ?? ('HTTP ' . $code);
            $out[$area] = [false, $c['dir'][$area] . ' — ' . $hint . ($err !== '' ? ' (' . $err . ')' : '')];
            continue;
        }
        $tmp = fopen('php://temp', 'w+b');
        [$g] = fs_dav('GET', fs_dav_url($area, $rel), null, $tmp, 20);
        fclose($tmp);
        $msg = $c['dir'][$area] . ' — 쓰기 · 읽기 · 지우기 성공';
        if ($area === 'public' && $c['public_url'] !== '') {
            // 방금 올린 시험 파일이 공개 주소로 실제로 열리는지 (지우기 전에)
            $ch = curl_init(fs_public_url($rel));
            curl_setopt_array($ch, [CURLOPT_TIMEOUT => 10, CURLOPT_RETURNTRANSFER => true]);
            $pb = curl_exec($ch);
            $pc = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            $pubOk = $pc === 200 && is_string($pb) && strpos($pb, 'GOODPOST ERP') !== false;
            $msg .= $pubOk ? ' · 공개 주소로도 열림 (' . $c['public_url'] . '/…)'
                           : ' · 공개 주소로는 안 열림 (HTTP ' . ($pc ?: '없음') . ') — 공개 기준 주소가 이 폴더를 가리키는지 확인';
            if (!$pubOk) { $g = -1; }
        }
        fs_dav('DELETE', fs_dav_url($area, $rel), null, null, 20);
        $out[$area] = [$g === 200, $g === 200 || $g === -1 ? $msg : $c['dir'][$area] . ' — 썼지만 읽기 실패 (HTTP ' . $g . ')'];
    }
    @unlink($probe);
    return $out;
}
