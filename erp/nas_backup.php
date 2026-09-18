<?php
/**
 * NAS 서류 백업 — NAS(시놀로지 작업 스케줄러)가 밤마다 불러서 새 서류를 가져갑니다.
 *
 *   NAS 쪽에서 이 서버로 나가는 연결만 씁니다. NAS 에 포트를 열 필요가 없습니다.
 *   열쇠(X-Backup-Key 헤더)는 저장소 설정 화면에서 만들고, 여기엔 해시만 있습니다.
 *   할 수 있는 일은 세 가지뿐입니다 — 서류 목록, 서류 파일 받기, "받았음" 표시.
 *
 *   ?do=list&after=N   아직 백업 안 된 서류 (id > N) — 한 줄에 id \t sha256 \t 크기 \t 저장할 경로
 *   ?do=get&id=N       그 서류 파일
 *   ?do=ack  (POST)    id · sha · path — 지문이 맞으면 SYNCED, 아니면 FAILED
 *   ?do=ping           열쇠 확인 + 대기 건수
 */
declare(strict_types=1);

require __DIR__ . '/app/bootstrap.php';

header('X-Robots-Tag: noindex');

function nb_out(int $code, string $text): void
{
    http_response_code($code);
    header('Content-Type: text/plain; charset=utf-8');
    echo $text;
    exit;
}

$key = (string)($_SERVER['HTTP_X_BACKUP_KEY'] ?? '');
if (strlen($key) < 32) {
    nb_out(404, '');
}
try {
    $st = db()->prepare('SELECT id FROM backup_keys WHERE key_hash = ? AND revoked_at IS NULL');
    $st->execute([hash('sha256', $key)]);
    $kid = (int)$st->fetchColumn();
} catch (PDOException $e) {
    $kid = 0;
}
if ($kid === 0) {
    usleep(300000);   // 열쇠 맞히기를 느리게
    nb_out(404, '');
}
db()->prepare('UPDATE backup_keys SET last_used_at = NOW(), last_ip = ? WHERE id = ?')
    ->execute([$_SERVER['REMOTE_ADDR'] ?? null, $kid]);

// 새로 올린 서류만 (옛 시스템 경로만 옮겨 온 행은 파일이 여기 없으므로 제외)
const NB_PATH_RE = '^[0-9]{4}/[0-9]{2}/[0-9a-f]{32}[.][a-z0-9]+$';

/** NAS 폴더 · 파일 이름에 쓸 수 없는 글자를 _ 로 */
function nb_clean(?string $s, int $max = 60): string
{
    $s = (string)preg_replace('/[\\\\\/:*?"<>|\x00-\x1F\x7F]+/u', '_', (string)$s);
    $s = trim((string)preg_replace('/\s+/u', ' ', $s), " .\t");
    if ($s === '') { $s = '_'; }
    return mb_substr($s, 0, $max);
}

/** 서류 파일의 실제 위치 — 보관 폴더 밖으로 나가는 경로는 거부 */
function nb_file(string $rel): ?string
{
    if (!preg_match('#' . NB_PATH_RE . '#', $rel)) { return null; }
    $root = realpath(storage_root());
    $full = realpath(storage_root() . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel));
    if ($root === false || $full === false || strpos($full, $root . DIRECTORY_SEPARATOR) !== 0) { return null; }
    return is_file($full) ? $full : null;
}

$do = (string)($_GET['do'] ?? '');

if ($do === 'ping') {
    $n = (int)db()->query("SELECT COUNT(*) FROM documents WHERE backup_status <> 'SYNCED'")->fetchColumn();
    nb_out(200, "OK\t" . $n . "\n");
}

if ($do === 'list') {
    $after = max(0, (int)($_GET['after'] ?? 0));
    $st = db()->prepare(
        "SELECT d.id, d.stored_path, d.original_name, d.title, d.checksum_sha256, d.size_bytes,
                d.created_at, s.awb_no, s.voucher_date, c.name_ko, t.name AS type_name, e.code AS ent
           FROM documents d
           LEFT JOIN shipments s        ON s.id = d.shipment_id
           LEFT JOIN companies c        ON c.id = COALESCE(d.company_id, s.company_id)
           LEFT JOIN document_types t   ON t.id = d.document_type_id
           LEFT JOIN business_entities e ON e.id = d.business_entity_id
          WHERE d.backup_status <> 'SYNCED' AND d.id > ? AND d.stored_path REGEXP ?
          ORDER BY d.id LIMIT 300");
    $st->execute([$after, NB_PATH_RE]);
    $lines = '';
    foreach ($st->fetchAll() as $d) {
        $sha = (string)$d['checksum_sha256'];
        if ($sha === '') {
            $full = nb_file((string)$d['stored_path']);
            if ($full === null) { continue; }
            $sha = (string)hash_file('sha256', $full);
        }
        // 사업자 / 연 / 월 / AWB_거래처 / 번호_종류_원래이름
        $day = (string)($d['voucher_date'] ?: substr((string)$d['created_at'], 0, 10));
        $folder = $d['awb_no'] ? nb_clean($d['awb_no'], 40) . '_' . nb_clean($d['name_ko'], 40)
                               : ($d['name_ko'] ? '거래처_' . nb_clean($d['name_ko'], 40) : '전표없음');
        $file = $d['id'] . '_' . nb_clean($d['type_name'] ?? '서류', 20) . '_'
              . nb_clean($d['original_name'] ?: $d['title'], 80);
        $path = nb_clean($d['ent'] ?? 'GP', 10) . '/' . substr($day, 0, 4) . '/' . substr($day, 5, 2)
              . '/' . $folder . '/' . $file;
        $lines .= $d['id'] . "\t" . $sha . "\t" . (int)$d['size_bytes'] . "\t" . $path . "\n";
    }
    nb_out(200, $lines);
}

if ($do === 'get') {
    $id = (int)($_GET['id'] ?? 0);
    $st = db()->prepare('SELECT stored_path FROM documents WHERE id = ?');
    $st->execute([$id]);
    $full = nb_file((string)$st->fetchColumn());
    if ($full === null) {
        nb_out(404, "없음\n");
    }
    header('Content-Type: application/octet-stream');
    header('Content-Length: ' . (string)filesize($full));
    readfile($full);
    exit;
}

if ($do === 'ack' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id   = (int)($_POST['id'] ?? 0);
    $sha  = strtolower(trim((string)($_POST['sha'] ?? '')));
    $path = mb_substr(trim((string)($_POST['path'] ?? '')), 0, 480);
    $st = db()->prepare('SELECT stored_path, checksum_sha256 FROM documents WHERE id = ?');
    $st->execute([$id]);
    $d = $st->fetch();
    if (!$d) {
        nb_out(404, "없음\n");
    }
    $want = (string)$d['checksum_sha256'];
    if ($want === '' && ($full = nb_file((string)$d['stored_path'])) !== null) {
        $want = (string)hash_file('sha256', $full);
    }
    $ok = $want !== '' && hash_equals($want, $sha);
    db()->prepare('UPDATE documents SET backup_status = ?, backup_at = NOW(), backup_path = ? WHERE id = ?')
        ->execute([$ok ? 'SYNCED' : 'FAILED', $ok ? 'NAS:' . $path : null, $id]);
    nb_out(200, $ok ? "OK\n" : "지문 다름\n");
}

nb_out(400, "알 수 없는 요청\n");
