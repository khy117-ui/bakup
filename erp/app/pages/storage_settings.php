<?php
// 웹에서 직접 열면 실행되지 않게 막습니다 (nginx 면 .htaccess 가 무시됩니다)
if (!defined('APP_DIR')) { http_response_code(403); exit('Forbidden'); }

require_once APP_DIR . '/layout.php';

/**
 * 저장소 설정 — 스캔한 인보이스·AWB·통관서류를 어디에 두는지.
 *
 * PRIMARY 하나 + BACKUP 여럿. PRIMARY 는 이 서버의 LOCAL 경로여야 합니다.
 * NAS 는 BACKUP 으로 두고 주기 동기화합니다 — 웹 요청 중에 NAS 가 안 붙으면
 * 업로드 자체가 실패해 버리기 때문입니다.
 *
 * 접속 비밀번호는 암호화해서 넣고, 넣은 뒤에는 화면에서 다시 볼 수 없습니다.
 */

$err = '';
$keyOk = app_key() !== null;

// ---------------------------------------------------------------- 저장 / 추가
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('act') === 'save') {
    csrf_check();
    $id     = (int)post('id');
    $name   = post('name');
    $type   = post('storage_type');
    $role   = post('role');
    $host   = post('host');
    $port   = (int)post('port', '22');
    $user   = post('username');
    $base   = post('base_path');
    $secret = $_POST['secret'] ?? '';        // 비우면 기존 값 유지
    $active = post('is_active') === '1' ? 1 : 0;

    if (trim($name) === '') {
        $err = '이름을 입력하세요.';
    } elseif (!in_array($type, ['LOCAL', 'SFTP', 'SMB', 'S3'], true)) {
        $err = '저장소 종류가 올바르지 않습니다.';
    } elseif (!in_array($role, ['PRIMARY', 'BACKUP'], true)) {
        $err = '역할이 올바르지 않습니다.';
    } elseif ($type !== 'LOCAL' && trim($host) === '') {
        $err = 'LOCAL 이 아니면 주소(host)가 필요합니다.';
    } elseif ($role === 'PRIMARY' && $type !== 'LOCAL') {
        $err = '원본(PRIMARY)은 이 서버의 LOCAL 경로여야 합니다. NAS 는 백업으로 두세요.';
    } elseif (trim($base) === '') {
        $err = '보관 경로를 입력하세요.';
    } elseif ($secret !== '' && !$keyOk) {
        $err = '암호화 열쇠(app_key)가 설정되지 않아 비밀번호를 저장할 수 없습니다. '
             . 'config.local.php 의 app_key 를 먼저 채우세요.';
    } else {
        $pdo = db();
        try {
            $pdo->beginTransaction();
            if ($role === 'PRIMARY') {
                // 원본은 하나만. 나머지는 백업으로 내립니다
                $pdo->prepare("UPDATE storage_settings SET role = 'BACKUP'
                                WHERE role = 'PRIMARY'" . ($id ? ' AND id <> ?' : ''))
                    ->execute($id ? [$id] : []);
            }
            if ($id > 0) {
                $pdo->prepare(
                    'UPDATE storage_settings
                        SET name = ?, storage_type = ?, host = ?, port = ?, username = ?,
                            base_path = ?, role = ?, is_active = ?
                      WHERE id = ?')
                    ->execute([$name, $type, $host ?: null, $port ?: null, $user ?: null,
                               $base, $role, $active, $id]);
                if ($secret !== '') {
                    $pdo->prepare('UPDATE storage_settings SET secret_enc = ? WHERE id = ?')
                        ->execute([secret_encrypt($secret), $id]);
                }
                log_action('시스템', 'UPDATE', 'storage_settings', $id, $name, null,
                           $type . ' / ' . $role . ($secret !== '' ? ' / 비밀번호 변경' : ''));
                flash($name . ' 을 저장했습니다.');
            } else {
                $pdo->prepare(
                    'INSERT INTO storage_settings
                       (name, storage_type, host, port, username, base_path, role, is_active,
                        secret_enc)
                     VALUES (?,?,?,?,?,?,?,?,?)')
                    ->execute([$name, $type, $host ?: null, $port ?: null, $user ?: null,
                               $base, $role, $active,
                               $secret !== '' ? secret_encrypt($secret) : null]);
                $newId = (int)$pdo->lastInsertId();
                log_action('시스템', 'CREATE', 'storage_settings', $newId, $name, null,
                           $type . ' / ' . $role);
                flash($name . ' 을 추가했습니다.');
            }
            $pdo->commit();
            redirect('?p=storage_settings');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            error_log('저장소 설정 실패: ' . $e->getMessage());
            $err = $e instanceof RuntimeException ? $e->getMessage()
                 : '저장하지 못했습니다. 이름이 이미 있는지 확인하세요.';
        }
    }
}

// ---------------------------------------------------------------- 연결 확인
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('act') === 'test') {
    csrf_check();
    $id = (int)post('id');
    $st = db()->prepare('SELECT * FROM storage_settings WHERE id = ?');
    $st->execute([$id]);
    $s = $st->fetch();
    if (!$s) {
        $err = '저장소를 찾을 수 없습니다.';
    } else {
        $ok = null; $why = '';
        if ($s['storage_type'] === 'LOCAL') {
            $p = (string)$s['base_path'];
            if (!is_dir($p))            { $ok = 0; $why = '경로가 없습니다.'; }
            elseif (!is_writable($p))   { $ok = 0; $why = '쓰기 권한이 없습니다.'; }
            else                        { $ok = 1; $why = '쓰기 가능.'; }
        } else {
            // 원격 저장소는 웹 요청에서 붙지 않습니다. 붙는지만 봅니다
            $eno = 0; $estr = '';
            $fp = @fsockopen((string)$s['host'], (int)($s['port'] ?: 22), $eno, $estr, 3);
            if ($fp) { fclose($fp); $ok = 1; $why = '포트가 열려 있습니다.'; }
            else     { $ok = 0; $why = '접속 실패 · ' . $estr; }
        }
        db()->prepare('UPDATE storage_settings SET last_test_at = NOW(), last_test_ok = ?
                        WHERE id = ?')->execute([$ok, $id]);
        log_action('시스템', 'UPDATE', 'storage_settings', $id, (string)$s['name'], null,
                   '연결확인 ' . ($ok ? 'OK' : 'FAIL') . ' · ' . $why);
        flash($s['name'] . ' · ' . ($ok ? '확인됨' : '실패') . ' — ' . $why);
        redirect('?p=storage_settings');
    }
}

// ---------------------------------------------------------------- NAS 백업 열쇠
// NAS 가 이 서버에서 서류를 가져가는 방식 — 열쇠는 한 개만 살아 있게 합니다
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array(post('act'), ['nas_key_new', 'nas_key_revoke'], true)) {
    csrf_check();
    try {
        db()->exec('UPDATE backup_keys SET revoked_at = NOW() WHERE revoked_at IS NULL');
        if (post('act') === 'nas_key_new') {
            $plain = bin2hex(random_bytes(24));
            db()->prepare('INSERT INTO backup_keys (key_hash, label, created_by) VALUES (?,?,?)')
                ->execute([hash('sha256', $plain), 'NAS 서류 백업', $_SESSION['admin_id'] ?? null]);
            // 원문은 이번 한 번만 화면에 보여주고 저장하지 않습니다
            $_SESSION['nas_key_once'] = $plain;
            log_action('시스템', 'CREATE', 'backup_keys', (int)db()->lastInsertId(), 'NAS 백업 열쇠');
            flash('NAS 백업 열쇠를 새로 만들었습니다. 아래 스크립트를 NAS 에 넣으세요 — 이 화면을 벗어나면 다시 볼 수 없습니다.');
        } else {
            log_action('시스템', 'DELETE', 'backup_keys', null, 'NAS 백업 열쇠', null, '끊음');
            flash('NAS 백업 열쇠를 끊었습니다. NAS 는 더 이상 서류를 가져갈 수 없습니다.');
        }
        redirect('?p=storage_settings#nas');
    } catch (PDOException $e) {
        error_log('NAS 열쇠 실패: ' . $e->getMessage());
        $err = 'NAS 백업 열쇠를 저장하지 못했습니다. 다시 로그인한 뒤 해 보세요.';
    }
}
$nasKey = null;
try {
    $nasKey = db()->query('SELECT * FROM backup_keys WHERE revoked_at IS NULL ORDER BY id DESC LIMIT 1')->fetch() ?: null;
} catch (PDOException $e) {
    $nasKey = null;
}
$nasOnce = $_SESSION['nas_key_once'] ?? null;
unset($_SESSION['nas_key_once']);
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
       || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') ? 'https' : 'http';
$nasUrl = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost')
        . rtrim(dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/index.php')), '/\\') . '/nas_backup.php';

// ---------------------------------------------------------------- 조회
$rows = db()->query('SELECT * FROM storage_settings ORDER BY role, id')->fetchAll();

$eid  = (int)query('edit', '0');
$edit = null;
foreach ($rows as $r) { if ((int)$r['id'] === $eid) { $edit = $r; } }

$root = storage_root();
$diskFree = @disk_free_space($root) ?: @disk_free_space(dirname($root));
$diskAll  = @disk_total_space($root) ?: @disk_total_space(dirname($root));

// 실제로 얼마나 쌓였나
$docCnt = 0; $docBytes = 0;
$bk = ['PENDING' => 0, 'SYNCED' => 0, 'FAILED' => 0];
try {
    $r = db()->query('SELECT COUNT(*) c, COALESCE(SUM(size_bytes),0) b FROM documents
                       WHERE deleted_at IS NULL')->fetch();
    $docCnt = (int)$r['c']; $docBytes = (int)$r['b'];
    foreach (db()->query('SELECT backup_status, COUNT(*) c FROM documents
                           WHERE deleted_at IS NULL GROUP BY backup_status')->fetchAll() as $b) {
        $bk[$b['backup_status']] = (int)$b['c'];
    }
} catch (PDOException $e) {
    // documents 테이블이 아직 없을 수 있습니다
}

$hasPrimary = false;
foreach ($rows as $r) { if ($r['role'] === 'PRIMARY' && (int)$r['is_active']) { $hasPrimary = true; } }

layout_head('저장소 설정', 'storage_settings');

$gb = static fn($b) => $b > 0 ? number_format($b / 1073741824, 1) . ' GB' : '-';
?>
<div class="head">
  <h1>저장소 설정</h1>
  <div class="crumb">시스템 &gt; 저장소 설정</div>
</div>

<?php if ($err !== ''): ?><div class="msg err"><?= h($err) ?></div><?php endif; ?>

<?php if (!$keyOk): ?>
<div class="msg err">
  <b>암호화 열쇠가 없습니다.</b> <code>config.local.php</code> 의 <code>app_key</code> 에
  32자 이상 임의 문자열을 넣으세요. 그 전까지는 NAS 접속 비밀번호를 저장할 수 없습니다
  (평문으로 남기지 않기 위해 막아 둡니다).
</div>
<?php endif; ?>

<?php if (!$hasPrimary): ?>
<div class="msg" style="background:var(--warn-bg);color:var(--warn-fg)">
  <b>원본 저장소가 지정되지 않았습니다.</b> 지금은 아래 <b>현재 쓰는 경로</b>가 그대로 쓰입니다.
  잘 돌아가지만, 경로를 옮길 계획이 있으면 여기에 등록해 두는 게 낫습니다.
</div>
<?php endif; ?>

<div class="kpis">
  <div class="kpi"><div class="lab">보관 중 문서</div>
    <div class="val tnum"><?= money($docCnt) ?></div>
    <div class="sub"><?= h($gb($docBytes)) ?></div></div>
  <div class="kpi"><div class="lab">디스크 남은 용량</div>
    <div class="val tnum"><?= h($gb((int)$diskFree)) ?></div>
    <div class="sub">전체 <?= h($gb((int)$diskAll)) ?></div></div>
  <div class="kpi"><div class="lab">백업 대기</div>
    <div class="val tnum" style="color:<?= $bk['FAILED']>0?'var(--err-fg)':'var(--ink)' ?>">
      <?= money($bk['PENDING'] + $bk['FAILED']) ?></div>
    <div class="sub">완료 <?= money($bk['SYNCED']) ?> · 실패 <?= money($bk['FAILED']) ?></div></div>
  <div class="kpi"><div class="lab">등록된 저장소</div>
    <div class="val tnum"><?= count($rows) ?></div>
    <div class="sub">원본 1 + 백업 여러 개</div></div>
</div>

<div class="card">
  <div class="ch">현재 쓰는 경로
    <span style="font-weight:400;color:var(--ink3)">코드가 실제로 파일을 쓰는 자리</span>
  </div>
  <table><tbody>
    <tr><td style="width:180px;font-weight:600">문서 보관 경로</td>
        <td class="tnum" style="font-size:12px"><?= h($root) ?></td></tr>
    <tr><td style="font-weight:600">쓰기 가능</td>
        <td><?= is_dir($root) && is_writable($root)
             ? '<span class="badge b-ok">예</span>'
             : '<span class="badge b-err">아니오 — 폴더를 만들고 쓰기 권한을 주세요</span>' ?></td></tr>
    <tr><td style="font-weight:600">업로드 한도</td>
        <td class="tnum"><?= h(ini_get('upload_max_filesize')) ?>
            · POST <?= h(ini_get('post_max_size')) ?></td></tr>
  </tbody></table>
  <div class="pager"><span>
    이 경로는 <code>storage_root()</code> 가 정합니다 — <code>/app/user_data</code> 가 있으면 그쪽,
    없으면 프로그램 폴더 아래 <code>storage/documents</code>. 호스팅에서 <b>다시 배포하면
    프로그램 폴더는 덮어써지므로</b> 문서는 반드시 <code>/app/user_data</code> 쪽에 있어야 합니다.
  </span></div>
</div>

<?php
// 시놀로지 작업 스케줄러에 넣을 스크립트. NAS 가 이 서버에서 새 서류만 받아 폴더별로 저장합니다
$nasScript = <<<'SH'
#!/bin/bash
# GOODPOST ERP 서류 → NAS 백업
# 시놀로지: 제어판 > 작업 스케줄러 > 생성 > 예약된 작업 > 사용자 정의 스크립트 (매일 새벽)
URL='__URL__'
KEY='__KEY__'
DEST='/volume1/GOODPOST/ERP서류'      # 저장할 공유폴더 경로 — 바꿔도 됩니다
LOG="$DEST/_백업기록.log"

mkdir -p "$DEST" || exit 1
echo "$(date '+%F %T') 시작" >> "$LOG"
after=0; got=0; bad=0
while : ; do
  LIST=$(curl -fsS --max-time 60 -H "X-Backup-Key: $KEY" "$URL?do=list&after=$after") \
    || { echo "$(date '+%F %T') 목록을 못 받음 (인터넷 또는 열쇠 확인)" >> "$LOG"; exit 1; }
  [ -z "$LIST" ] && break
  while IFS=$'\t' read -r id sha size path; do
    [ -z "$id" ] && continue
    after=$id
    target="$DEST/$path"
    mkdir -p "$(dirname "$target")"
    if curl -fsS --max-time 900 -H "X-Backup-Key: $KEY" "$URL?do=get&id=$id" -o "$target.part" \
       && [ "$(sha256sum "$target.part" | cut -d' ' -f1)" = "$sha" ]; then
      mv -f "$target.part" "$target"
      curl -fsS -X POST -H "X-Backup-Key: $KEY" --data-urlencode "id=$id" \
           --data-urlencode "sha=$sha" --data-urlencode "path=$path" "$URL?do=ack" > /dev/null \
        && got=$((got+1))
    else
      rm -f "$target.part"; bad=$((bad+1))
      echo "$(date '+%F %T') 실패 #$id $path" >> "$LOG"
    fi
  done <<< "$LIST"
done
echo "$(date '+%F %T') 끝 — 받음 $got · 실패 $bad" >> "$LOG"
SH;
$nasScript = strtr($nasScript, ['__URL__' => $nasUrl, '__KEY__' => $nasOnce ?? '(열쇠를 새로 만들면 여기에 들어갑니다)']);
?>
<div class="card" id="nas">
  <div class="ch">NAS 자동 백업
    <span style="font-weight:400;color:var(--ink3)">NAS 가 매일 이 서버에서 새 서류를 가져갑니다 — NAS 에 포트를 열 필요 없음</span></div>
  <div class="cb">
    <div class="f" style="align-items:center;gap:16px">
      <div><div style="font-size:11px;color:var(--ink2)">열쇠</div>
        <div><?= $nasKey ? '<span class="badge b-ok">사용 중</span> <span class="tnum" style="font-size:12px">만든 날 ' . h($nasKey['created_at']) . '</span>'
                         : '<span class="badge b-warn">없음</span>' ?></div></div>
      <div><div style="font-size:11px;color:var(--ink2)">NAS 가 마지막으로 온 때</div>
        <div class="tnum"><?= h($nasKey['last_used_at'] ?? '아직 없음') ?>
          <?= !empty($nasKey['last_ip']) ? '<span style="color:var(--ink3);font-size:11.5px">(' . h($nasKey['last_ip']) . ')</span>' : '' ?></div></div>
      <div><div style="font-size:11px;color:var(--ink2)">백업 대기</div>
        <div class="tnum"><?= money($bk['PENDING'] + $bk['FAILED']) ?>개 · 완료 <?= money($bk['SYNCED']) ?>개</div></div>
      <form method="post" style="margin-left:auto;display:flex;gap:8px"
            onsubmit="return confirm(this.act.value === 'nas_key_new' ? '새 열쇠를 만들면 예전 열쇠는 바로 끊깁니다. NAS 스크립트도 새로 넣어야 합니다. 계속할까요?' : 'NAS 백업을 끊을까요?');">
        <?= csrf_field() ?>
        <input type="hidden" name="act" value="nas_key_new">
        <button class="btn pri" onclick="this.form.act.value='nas_key_new'"><?= $nasKey ? '열쇠 새로 만들기' : '백업 열쇠 만들기' ?></button>
        <?php if ($nasKey): ?>
          <button class="btn" onclick="this.form.act.value='nas_key_revoke'">끊기</button>
        <?php endif; ?>
      </form>
    </div>
  </div>
  <?php if ($nasOnce !== null): ?>
  <div class="cb" style="border-top:1px solid var(--line)">
    <div class="msg err" style="margin-bottom:10px">열쇠가 들어간 스크립트입니다. <b>지금 복사해 NAS 에 넣으세요 — 이 화면을 벗어나면 다시 볼 수 없습니다.</b>
      남에게 보내지 마세요 (서류를 받을 수 있는 열쇠입니다).</div>
    <textarea id="nas-script" rows="14" readonly style="font-family:ui-monospace,Consolas,monospace;font-size:12px;white-space:pre"><?= h($nasScript) ?></textarea>
    <div style="margin-top:8px"><button type="button" class="btn sm" onclick="var t=document.getElementById('nas-script');t.select();navigator.clipboard&&navigator.clipboard.writeText(t.value);this.textContent='복사했습니다'">스크립트 복사</button></div>
  </div>
  <?php endif; ?>
  <div class="cb" style="border-top:1px solid var(--line2);font-size:12px;color:var(--ink2);line-height:1.9">
    <b>NAS 에 넣는 법 (시놀로지, 한 번만)</b><br>
    1. 위 <b>백업 열쇠 만들기</b> → 나온 스크립트 복사<br>
    2. DSM <b>제어판 → 작업 스케줄러 → 생성 → 예약된 작업 → 사용자 정의 스크립트</b><br>
    3. 사용자: 저장할 공유폴더에 쓸 수 있는 계정 · 일정: 매일 새벽 3시 · <b>작업 설정</b> 칸에 스크립트 붙여넣기<br>
    4. 스크립트의 <code>DEST=</code> 를 서류를 둘 공유폴더 경로로 (예: <code>/volume1/GOODPOST/ERP서류</code>)<br>
    5. 만든 작업을 골라 <b>실행</b> 을 한 번 눌러 보면, 이 화면의 "NAS 가 마지막으로 온 때" 와 백업 완료 수가 바뀝니다<br>
    NAS 에는 <code>사업자/연/월/AWB_거래처/번호_종류_파일이름</code> 으로 쌓이고, 받은 뒤 지문(SHA-256)을 맞춰 본 것만 완료로 표시합니다.
  </div>
</div>

<div class="card">
  <div class="ch">등록된 저장소</div>
  <?php if (!$rows): ?>
    <div class="empty">등록된 저장소가 없습니다.</div>
  <?php else: ?>
  <table>
    <thead><tr>
      <th style="width:140px">이름</th><th class="c" style="width:80px">종류</th>
      <th class="c" style="width:80px">역할</th><th>주소 · 경로</th>
      <th class="c" style="width:80px">비밀번호</th>
      <th style="width:170px">마지막 확인</th>
      <th class="c" style="width:70px">사용</th><th class="c" style="width:145px"></th>
    </tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td style="font-weight:600"><?= h($r['name']) ?></td>
        <td class="c"><?= h($r['storage_type']) ?></td>
        <td class="c"><?= $r['role']==='PRIMARY'
             ? '<span class="badge b-ok">원본</span>'
             : '<span class="badge b-info">백업</span>' ?></td>
        <td class="tnum" style="font-size:11.5px">
          <?php if ($r['host']): ?>
            <?= h($r['username'] ? $r['username'] . '@' : '') ?><?= h($r['host']) ?>:<?= (int)$r['port'] ?><br>
          <?php endif; ?>
          <?= h($r['base_path']) ?>
        </td>
        <td class="c"><?= $r['secret_enc'] !== null
             ? '<span class="badge b-ok">저장됨</span>'
             : '<span style="color:var(--ink3)">없음</span>' ?></td>
        <td class="tnum" style="font-size:11.5px">
          <?php if ($r['last_test_at']): ?>
            <?= h($r['last_test_at']) ?>
            <?= (int)$r['last_test_ok'] ? '<span class="badge b-ok">OK</span>'
                                        : '<span class="badge b-err">실패</span>' ?>
          <?php else: ?><span style="color:var(--ink3)">-</span><?php endif; ?>
        </td>
        <td class="c"><?= (int)$r['is_active']
             ? '<span class="badge b-ok">사용</span>'
             : '<span class="badge b-err">중지</span>' ?></td>
        <td class="c">
          <a class="btn sm" href="?p=storage_settings&amp;edit=<?= (int)$r['id'] ?>">수정</a>
          <form method="post" style="display:inline">
            <?= csrf_field() ?>
            <input type="hidden" name="act" value="test">
            <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
            <button class="btn sm">연결확인</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>

<div class="card">
  <div class="ch"><?= $edit ? h($edit['name']) . ' 수정' : '저장소 추가' ?>
    <?php if ($edit): ?>
      <a class="btn sm" style="margin-left:auto" href="?p=storage_settings">새로 추가</a>
    <?php endif; ?>
  </div>
  <div class="cb">
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="act" value="save">
      <input type="hidden" name="id" value="<?= $edit ? (int)$edit['id'] : 0 ?>">
      <div class="f" style="align-items:flex-end">
        <div class="fw w1"><label for="nm">이름 *</label>
          <input type="text" id="nm" name="name" required
                 value="<?= h($edit['name'] ?? '') ?>" placeholder="회사 NAS"></div>
        <div class="fw w1"><label for="ty">종류 *</label>
          <select id="ty" name="storage_type">
            <?php foreach (['LOCAL'=>'이 서버 폴더','SFTP'=>'SFTP','SMB'=>'SMB 공유','S3'=>'S3 호환']
                           as $k=>$v): ?>
              <option value="<?= h($k) ?>"<?= ($edit['storage_type'] ?? 'SFTP')===$k?' selected':'' ?>>
                <?= h($v) ?></option>
            <?php endforeach; ?>
          </select></div>
        <div class="fw w1"><label for="ro">역할 *</label>
          <select id="ro" name="role">
            <option value="BACKUP"<?= ($edit['role'] ?? 'BACKUP')==='BACKUP'?' selected':'' ?>>백업</option>
            <option value="PRIMARY"<?= ($edit['role'] ?? '')==='PRIMARY'?' selected':'' ?>>원본</option>
          </select></div>
        <div class="fw w1"><label for="ac">사용</label>
          <select id="ac" name="is_active">
            <option value="1"<?= (int)($edit['is_active'] ?? 1)===1?' selected':'' ?>>사용</option>
            <option value="0"<?= (int)($edit['is_active'] ?? 1)===0?' selected':'' ?>>중지</option>
          </select></div>
      </div>
      <div class="f" style="align-items:flex-end;margin-top:8px">
        <div class="fw w2"><label for="ho">주소 (host)</label>
          <input type="text" id="ho" name="host" class="tnum"
                 value="<?= h($edit['host'] ?? '') ?>"
                 placeholder="nas.example.ipdisk.co.kr — LOCAL 이면 비웁니다"></div>
        <div class="fw w1"><label for="po">포트</label>
          <input type="text" id="po" name="port" class="tnum"
                 value="<?= h((string)($edit['port'] ?? 22)) ?>"></div>
        <div class="fw w1"><label for="us">사용자</label>
          <input type="text" id="us" name="username"
                 value="<?= h($edit['username'] ?? '') ?>"></div>
      </div>
      <div class="f" style="align-items:flex-end;margin-top:8px">
        <div class="fw w3"><label for="bp">보관 경로 *</label>
          <input type="text" id="bp" name="base_path" class="tnum" required
                 value="<?= h($edit['base_path'] ?? '') ?>"
                 placeholder="/volume1/goodpost/documents"></div>
        <div class="fw w1"><label for="se">접속 비밀번호</label>
          <input type="password" id="se" name="secret" autocomplete="new-password"
                 <?= $keyOk ? '' : 'disabled' ?>
                 placeholder="<?= ($edit && $edit['secret_enc'] !== null)
                                   ? '바꿀 때만 입력' : ($keyOk ? '' : 'app_key 필요') ?>"></div>
      </div>
      <div style="margin-top:10px;display:flex;gap:8px;align-items:center">
        <button class="btn pri">저장</button>
        <span style="font-size:11.5px;color:var(--ink3)">
          비밀번호는 암호화해 넣고 <b>다시 볼 수 없습니다.</b> 비우면 기존 값을 그대로 둡니다.
        </span>
      </div>
    </form>
  </div>
</div>

<div class="card">
  <div class="ch">왜 NAS 를 원본으로 쓰지 않나</div>
  <div class="cb" style="font-size:12px;color:var(--ink2);line-height:1.9">
    · 파일 업로드가 <b>웹 요청 안에서</b> 일어납니다. 원본이 NAS 면 NAS 가 잠깐 안 붙는 동안
      <b>업로드 자체가 실패</b>합니다. 그래서 원본은 이 서버 폴더로 두고, NAS 는 백업으로 둡니다.<br>
    · 백업은 <b>NAS 가 가져가는 방식</b>입니다(위 NAS 자동 백업). NAS 가 밤마다 아직 안 가져간 서류만 받고,
      실패한 것은 다음 날 다시 받습니다. NAS 쪽 포트를 인터넷에 열 필요가 없습니다.<br>
    · <b>스캔 원본이 진짜 자료입니다.</b> DB 가 날아가도 파일이 남아 있으면 되살릴 수 있고,
      반대는 안 됩니다. 백업은 문서 쪽을 먼저 챙기세요.
  </div>
</div>
<?php layout_foot();
