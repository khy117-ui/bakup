<?php
// 웹에서 직접 열면 실행되지 않게 막습니다 (nginx 면 .htaccess 가 무시됩니다)
if (!defined('APP_DIR')) { http_response_code(403); exit('Forbidden'); }

require_once APP_DIR . '/layout.php';

/**
 * 환경설정 — 시스템 전체에 하나만 있으면 되는 값.
 *
 * 세금·회계에 직접 영향을 주는 값(세율 제외: 사업자별 계좌·직인·문서번호·세금계산서 API)은
 * 여기가 아니라 [사업자 관리] 에 있습니다. 사업자마다 달라야 하기 때문입니다.
 */

$err = '';
$hasTable = true;
try {
    db()->query('SELECT 1 FROM app_settings LIMIT 1');
} catch (PDOException $e) {
    $hasTable = false;
}

if ($hasTable && $_SERVER['REQUEST_METHOD'] === 'POST' && post('act') === 'save') {
    csrf_check();
    $vals = $_POST['s'] ?? [];
    if (!is_array($vals)) { $vals = []; }
    $pdo = db();
    try {
        $rows = $pdo->query('SELECT setting_key, setting_val, input_type, options_csv, label_ko
                               FROM app_settings')->fetchAll();
        $map = [];
        foreach ($rows as $r) { $map[$r['setting_key']] = $r; }

        $pdo->beginTransaction();
        $upd = $pdo->prepare('UPDATE app_settings SET setting_val = ?, updated_by = ?
                               WHERE setting_key = ?');
        $changed = [];
        foreach ($vals as $k => $v) {
            if (!isset($map[$k])) { continue; }        // 모르는 키는 무시합니다
            // 전환일은 전 거래처 미수를 한꺼번에 움직입니다. 사유를 받고 재무 이력에 남기는
            // [기초잔액 관리] 에서만 바꿉니다
            if ($k === 'ar_cutover_date') { continue; }
            $r = $map[$k];
            $v = is_string($v) ? trim($v) : '';
            if ($r['input_type'] === 'secret') {
                // 비밀값 — 비워 두면 그대로, 새로 적었을 때만 바꿉니다. 이력에도 값은 남기지 않습니다.
                // 복사하다 섞인 공백 · 줄바꿈은 뺍니다 (인증키가 두 줄로 보이는 화면이 많음)
                $v = (string)preg_replace('/\s+/u', '', $v);
                if ($v === '') { continue; }
                if ($v !== (string)$r['setting_val']) {
                    $changed[] = $r['label_ko'] . ': (새 값으로 바꿈)';
                    $upd->execute([$v, $_SESSION['admin_id'] ?? null, $k]);
                }
                continue;
            }
            if ($r['input_type'] === 'number') {
                if ($v !== '' && !is_numeric(str_replace(',', '', $v))) {
                    throw new RuntimeException($r['label_ko'] . ' 은 숫자여야 합니다.');
                }
                $v = str_replace(',', '', $v);
            } elseif ($r['input_type'] === 'select') {
                $opts = array_map('trim', explode(',', (string)$r['options_csv']));
                if ($v !== '' && !in_array($v, $opts, true)) {
                    throw new RuntimeException($r['label_ko'] . ' 값이 선택지에 없습니다.');
                }
            }
            if ((string)$r['setting_val'] !== $v) {
                $changed[] = $r['label_ko'] . ': ' . $r['setting_val'] . ' → ' . $v;
                $upd->execute([$v, $_SESSION['admin_id'] ?? null, $k]);
            }
        }
        $pdo->commit();
        if ($changed) {
            log_action('시스템', 'UPDATE', 'app_settings', null, '환경설정',
                       null, implode(' / ', array_slice($changed, 0, 8)));
            flash(count($changed) . '개 항목을 저장했습니다.');
        } else {
            flash('바뀐 값이 없습니다.');
        }
        redirect('?p=settings');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        $err = $e instanceof RuntimeException ? $e->getMessage() : '저장하지 못했습니다.';
    }
}

$byGroup = [];
if ($hasTable) {
    $rows = db()->query('SELECT * FROM app_settings ORDER BY group_ko, sort_order, setting_key')
                ->fetchAll();
    foreach ($rows as $r) { $byGroup[$r['group_ko']][] = $r; }
}

// 시스템 정보 — 문제가 생겼을 때 먼저 보는 값들
$info = [];
try {
    $info['DB 서버']  = (string)db()->query('SELECT VERSION()')->fetchColumn();
    $info['DB 이름']  = (string)db()->query('SELECT DATABASE()')->fetchColumn();
    $info['collation'] = (string)db()->query(
        'SELECT GROUP_CONCAT(DISTINCT COLLATION_NAME) FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND COLLATION_NAME IS NOT NULL')->fetchColumn();
    $info['테이블 수'] = (string)db()->query(
        "SELECT COUNT(*) FROM information_schema.TABLES
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'")->fetchColumn();
} catch (PDOException $e) {
    $info['DB'] = '조회 실패';
}
$info['PHP']       = PHP_VERSION;
$info['시간대']     = date_default_timezone_get() . ' · ' . date('Y-m-d H:i:s');
$info['문서 보관']  = storage_root();
$info['업로드 한도'] = ini_get('upload_max_filesize') . ' / POST ' . ini_get('post_max_size');

layout_head('환경설정', 'settings');
?>
<div class="head">
  <h1>환경설정</h1>
  <div class="crumb">시스템 &gt; 환경설정</div>
</div>

<?php if ($err !== ''): ?><div class="msg err"><?= h($err) ?></div><?php endif; ?>

<?php if (!$hasTable): ?>
  <div class="msg err">
    <b>환경설정 테이블이 없습니다.</b> <code>17_app_settings.sql</code> 을 실행하세요.
    그 전까지는 각 화면의 기본값이 쓰입니다.
  </div>
<?php else: ?>
<form method="post">
<?= csrf_field() ?>
<input type="hidden" name="act" value="save">
<?php foreach ($byGroup as $grp => $items): ?>
<div class="card">
  <div class="ch"><?= h($grp) ?></div>
  <table>
    <thead><tr>
      <th style="width:250px">항목</th><th style="width:180px">값</th><th>설명</th>
      <th style="width:150px">마지막 변경</th>
    </tr></thead>
    <tbody>
    <?php foreach ($items as $s): ?>
      <tr>
        <td style="font-weight:600"><?= h($s['label_ko']) ?>
          <div class="tnum" style="font-weight:400;font-size:11px;color:var(--ink3)">
            <?= h($s['setting_key']) ?></div></td>
        <td>
          <?php if ($s['setting_key'] === 'ar_cutover_date'): ?>
            <b class="tnum"><?= h($s['setting_val'] ?: '설정 안 됨') ?></b>
            <div style="font-size:11px"><a href="?p=opening_balances">기초잔액 관리에서 바꿈</a></div>
          <?php elseif ($s['input_type'] === 'secret'): $sv = (string)$s['setting_val']; ?>
            <input type="password" name="s[<?= h($s['setting_key']) ?>]" autocomplete="new-password"
                   placeholder="<?= $sv !== '' ? '저장됨 ····' . h(mb_substr($sv, -4)) . ' (바꿀 때만 입력)' : '아직 없음' ?>">
          <?php elseif ($s['input_type'] === 'select'): ?>
            <select name="s[<?= h($s['setting_key']) ?>]">
              <?php foreach (array_map('trim', explode(',', (string)$s['options_csv'])) as $o): ?>
                <option value="<?= h($o) ?>"<?= (string)$s['setting_val']===$o?' selected':'' ?>>
                  <?= h($o) ?></option>
              <?php endforeach; ?>
            </select>
          <?php else: ?>
            <input type="text" class="tnum"
                   style="<?= $s['input_type']==='number' ? 'text-align:right' : '' ?>"
                   name="s[<?= h($s['setting_key']) ?>]"
                   value="<?= h($s['setting_val']) ?>">
          <?php endif; ?>
        </td>
        <td style="font-size:11.5px;color:var(--ink2)"><?= h($s['help_ko'] ?: '') ?></td>
        <td class="tnum" style="font-size:11.5px;color:var(--ink3)"><?= h($s['updated_at']) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endforeach; ?>

<div style="display:flex;gap:8px">
  <button class="btn pri">저장</button>
  <span style="font-size:11.5px;color:var(--ink3);align-self:center">
    바뀐 항목만 작업로그에 남습니다.</span>
</div>
</form>
<?php endif; ?>

<div class="card">
  <div class="ch">여기 없는 설정은 어디에</div>
  <table>
    <thead><tr><th style="width:220px">찾는 값</th><th>있는 곳</th></tr></thead>
    <tbody>
      <tr><td style="font-weight:600">사업자등록번호 · 주소 · 대표자</td>
          <td><a href="?p=business_entity">사업자 관리</a> — 인보이스·세금계산서에 찍히는 값입니다</td></tr>
      <tr><td style="font-weight:600">입금계좌 · 문서번호 머리말</td>
          <td><a href="?p=business_entity">사업자 관리</a> — 사업자마다 달라야 합니다</td></tr>
      <tr><td style="font-weight:600">전자세금계산서 연동</td>
          <td><a href="?p=business_entity">사업자 관리</a> — 인증키는 서버에 암호화해 넣습니다</td></tr>
      <tr><td style="font-weight:600">운송사 · 유류할증률 · Zone</td>
          <td><a href="?p=carriers">운송사 관리</a></td></tr>
      <tr><td style="font-weight:600">특송 단가표</td>
          <td><a href="?p=rate_table">특송 기본가격표</a> — 기간별로 버전이 쌓입니다</td></tr>
      <tr><td style="font-weight:600">업체별 할인율</td>
          <td><a href="?p=company_terms">업체별 할인율</a></td></tr>
      <tr><td style="font-weight:600">문서 보관 위치 · NAS 백업</td>
          <td><a href="?p=storage_settings">저장소 설정</a></td></tr>
      <tr><td style="font-weight:600">관리자 계정 · 역할별 권한</td>
          <td><a href="?p=permissions">관리자 / 권한</a></td></tr>
    </tbody>
  </table>
</div>

<div class="card">
  <div class="ch">시스템 정보
    <span style="font-weight:400;color:var(--ink3)">문제가 생기면 먼저 보는 값들</span>
  </div>
  <table>
    <tbody>
    <?php foreach ($info as $k => $v): ?>
      <tr>
        <td style="width:180px;font-weight:600"><?= h($k) ?></td>
        <td class="tnum" style="font-size:12px"><?= h($v) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <div class="pager"><span>
    <b>collation 이 한 종류여야 합니다.</b> 섞이면 조인할 때
    <code>Illegal mix of collations</code> 오류가 납니다.
  </span></div>
</div>
<?php layout_foot();
