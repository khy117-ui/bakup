<?php
// 웹에서 직접 열면 실행되지 않게 막습니다 (nginx 면 .htaccess 가 무시됩니다)
if (!defined('APP_DIR')) { http_response_code(403); exit('Forbidden'); }

require APP_DIR . '/layout.php';

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
    · 백업은 웹 요청과 따로 도는 동기화가 <code>documents</code> 를 훑어
      아직 안 올라간 파일만 보냅니다. 실패하면 다음 회차에 다시 시도합니다.<br>
    · NAS 가 ipDISK/DDNS 로 <b>외부에 열려 있으므로</b>, 이 서버에서 붙는 건 됩니다.
      다만 <b>그 포트가 인터넷 전체에 열려 있다면</b> 접속 허용 IP 를 이 호스팅 주소로 좁히는 게 좋습니다.<br>
    · <b>스캔 원본이 진짜 자료입니다.</b> DB 가 날아가도 파일이 남아 있으면 되살릴 수 있고,
      반대는 안 됩니다. 백업은 문서 쪽을 먼저 챙기세요.
  </div>
</div>
<?php layout_foot();
