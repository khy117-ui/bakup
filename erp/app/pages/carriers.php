<?php
require_once APP_DIR . '/layout.php';

$err = '';
$tab = query('tab', 'carrier');
if (!in_array($tab, ['carrier', 'service', 'zone', 'fuel'], true)) { $tab = 'carrier'; }

$TAXT  = ['ZERO' => '영세율', 'TAXABLE' => '과세', 'EXEMPT' => '면세'];
$BASIS = ['AFTER_DISCOUNT' => '할인후 운임 기준', 'BASE_PRICE' => '기본가격 기준'];

$carriers = db()->query(
    'SELECT * FROM carriers ORDER BY is_active DESC, sort_order, code')->fetchAll();
$carrierMap = [];
foreach ($carriers as $c) { $carrierMap[(int)$c['id']] = $c['code']; }

// ---------------------------------------------------------------- 저장
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $act = post('act');
    try {
        if ($act === 'carrier_save') {
            $cid  = (int)post('id');
            $code = strtoupper(post('code'));
            $name = post('name');
            $tt   = post('default_tax_type', 'ZERO');
            if ($code === '' || $name === '') {
                $err = '코드와 이름은 필수입니다.';
            } elseif (!preg_match('/^[A-Z0-9_]{2,20}$/', $code)) {
                $err = '코드는 영문 대문자·숫자 2~20자로 하세요.';
            } elseif (!isset($TAXT[$tt])) {
                $err = '세금구분이 올바르지 않습니다.';
            } else {
                $dup = db()->prepare('SELECT id FROM carriers WHERE code = ? AND id <> ?');
                $dup->execute([$code, $cid]);
                if ($dup->fetchColumn()) {
                    $err = '이미 쓰이는 운송사 코드입니다.';
                } elseif ($cid > 0) {
                    db()->prepare(
                        'UPDATE carriers SET code = ?, name = ?, default_tax_type = ?,
                                tracking_enabled = ?, sort_order = ?, is_active = ?
                          WHERE id = ?')
                        ->execute([$code, $name, $tt, post('tracking_enabled') ? 1 : 0,
                                   (int)post('sort_order'), post('is_active') ? 1 : 0, $cid]);
                    log_action('기준정보', 'UPDATE', 'carriers', $cid, $code);
                    flash('운송사를 수정했습니다.');
                } else {
                    db()->prepare(
                        'INSERT INTO carriers (code, name, default_tax_type,
                                tracking_enabled, sort_order, is_active)
                         VALUES (?,?,?,?,?,?)')
                        ->execute([$code, $name, $tt, post('tracking_enabled') ? 1 : 0,
                                   (int)post('sort_order'), 1]);
                    log_action('기준정보', 'CREATE', 'carriers', (int)db()->lastInsertId(), $code);
                    flash('운송사를 등록했습니다.');
                }
                if ($err === '') { redirect('?p=carriers&tab=carrier'); }
            }

        } elseif ($act === 'service_save') {
            $cid = (int)post('carrier_id');
            $code = strtoupper(post('code'));
            $name = post('name');
            if (!isset($carrierMap[$cid]) || $code === '' || $name === '') {
                $err = '운송사·코드·이름을 모두 입력하세요.';
            } else {
                $dup = db()->prepare('SELECT id FROM carrier_services WHERE carrier_id = ? AND code = ?');
                $dup->execute([$cid, $code]);
                if ($dup->fetchColumn()) {
                    $err = '이 운송사에 같은 서비스 코드가 있습니다.';
                } else {
                    db()->prepare(
                        'INSERT INTO carrier_services (carrier_id, code, name, tax_type, is_active)
                         VALUES (?,?,?,?,1)')
                        ->execute([$cid, $code, $name,
                                   post('tax_type') !== '' ? post('tax_type') : null]);
                    flash('서비스를 추가했습니다.');
                    redirect('?p=carriers&tab=service');
                }
            }

        } elseif ($act === 'zone_bulk') {
            $cid  = (int)post('carrier_id');
            $body = (string)($_POST['bulk'] ?? '');
            if (!isset($carrierMap[$cid])) {
                $err = '운송사를 고르세요.';
            } else {
                $pdo = db();
                $pdo->beginTransaction();
                $ins = $pdo->prepare(
                    'INSERT INTO carrier_zones (carrier_id, zone_no, country_code, country_name)
                     VALUES (?,?,?,?)
                     ON DUPLICATE KEY UPDATE zone_no = VALUES(zone_no),
                                             country_name = VALUES(country_name)');
                $n = 0; $skip = 0;
                foreach (preg_split('/\r?\n/', $body) as $ln) {
                    $ln = trim($ln);
                    if ($ln === '') { continue; }
                    $col = preg_split('/[\t,]+/', $ln);
                    if (count($col) < 2) { $skip++; continue; }
                    $zone = (int)trim($col[0]);
                    $cc   = strtoupper(trim($col[1]));
                    $cn   = isset($col[2]) ? trim($col[2]) : null;
                    if ($zone < 1 || $zone > 99 || !preg_match('/^[A-Z]{2}$/', $cc)) {
                        $skip++; continue;
                    }
                    $ins->execute([$cid, $zone, $cc, $cn]);
                    $n++;
                }
                $pdo->commit();
                log_action('기준정보', 'UPDATE', 'carrier_zones', $cid,
                           $carrierMap[$cid], null, 'Zone ' . $n . '건');
                flash('Zone ' . $n . '건을 넣었습니다.' . ($skip ? ' 형식이 안 맞는 ' . $skip . '줄은 건너뛰었습니다.' : ''));
                redirect('?p=carriers&tab=zone&carrier_id=' . $cid);
            }

        } elseif ($act === 'fuel_save') {
            $cid  = (int)post('carrier_id');
            $rate = (float)str_replace('%', '', post('fuel_rate'));
            $from = post('effective_from');
            $basis = post('calc_basis', 'AFTER_DISCOUNT');
            if (!isset($carrierMap[$cid])) {
                $err = '운송사를 고르세요.';
            } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
                $err = '적용 시작일을 입력하세요.';
            } elseif ($rate < 0 || $rate > 200) {
                $err = '유류할증률이 범위를 벗어났습니다.';
            } elseif (!isset($BASIS[$basis])) {
                $err = '계산 기준이 올바르지 않습니다.';
            } else {
                $pdo = db();
                $pdo->beginTransaction();
                // 앞 구간을 닫고 새 구간을 엽니다. 겹치면 어느 값을 쓸지 알 수 없습니다
                $pdo->prepare(
                    'UPDATE fuel_surcharges SET effective_to = DATE_SUB(?, INTERVAL 1 DAY)
                      WHERE carrier_id = ? AND effective_to IS NULL AND effective_from < ?')
                    ->execute([$from, $cid, $from]);
                $pdo->prepare(
                    'INSERT INTO fuel_surcharges (carrier_id, fuel_rate, calc_basis, effective_from)
                     VALUES (?,?,?,?)')->execute([$cid, $rate, $basis, $from]);
                log_action('기준정보', 'CREATE', 'fuel_surcharges', (int)$pdo->lastInsertId(),
                           $carrierMap[$cid], null, $rate . '% ' . $from . '~');
                $pdo->commit();
                flash('유류할증률을 등록했습니다. 이전 구간은 하루 전으로 닫았습니다.');
                redirect('?p=carriers&tab=fuel');
            }
        }
    } catch (PDOException $e) {
        if (db()->inTransaction()) { db()->rollBack(); }
        error_log('기준정보 저장 실패: ' . $e->getMessage());
        $err = '저장하지 못했습니다.';
    }
    $carriers = db()->query('SELECT * FROM carriers ORDER BY is_active DESC, sort_order, code')->fetchAll();
}

$editId = (int)query('edit', '0');
$edit = null;
foreach ($carriers as $c) { if ((int)$c['id'] === $editId) { $edit = $c; } }

$selCarrier = (int)query('carrier_id', '0');

layout_head('운송사 관리', 'carriers');
?>
<div class="head">
  <h1>운송사 관리</h1>
  <div class="crumb">기준정보 &gt; 운송사 관리</div>
</div>

<?php if ($err !== ''): ?><div class="msg err"><?= h($err) ?></div><?php endif; ?>

<div class="card">
  <div class="ch">
    <?php foreach (['carrier'=>'운송사','service'=>'서비스','zone'=>'Zone','fuel'=>'유류할증'] as $k=>$v): ?>
      <a class="btn sm<?= $tab===$k?' pri':'' ?>" href="?p=carriers&amp;tab=<?= h($k) ?>"><?= h($v) ?></a>
    <?php endforeach; ?>
  </div>

<?php if ($tab === 'carrier'): ?>
  <div class="cb">
    <form method="post" class="f" style="align-items:flex-end">
      <?= csrf_field() ?>
      <input type="hidden" name="act" value="carrier_save">
      <input type="hidden" name="id" value="<?= $edit ? (int)$edit['id'] : 0 ?>">
      <div class="fw w1"><label>코드 *</label>
        <input type="text" name="code" required value="<?= h($edit['code'] ?? '') ?>"
               placeholder="DHL" style="text-transform:uppercase"></div>
      <div class="fw w3"><label>이름 *</label>
        <input type="text" name="name" required value="<?= h($edit['name'] ?? '') ?>"
               placeholder="DHL Express"></div>
      <div class="fw w1"><label>기본 세금구분</label>
        <select name="default_tax_type">
          <?php foreach ($TAXT as $k=>$v): ?>
            <option value="<?= h($k) ?>"<?= ($edit['default_tax_type'] ?? 'ZERO')===$k?' selected':'' ?>><?= h($v) ?></option>
          <?php endforeach; ?>
        </select></div>
      <div class="fw w1"><label>정렬</label>
        <input type="text" name="sort_order" class="tnum" value="<?= h($edit['sort_order'] ?? '0') ?>"></div>
      <div class="fw w1"><label>&nbsp;</label>
        <label style="font-weight:400;font-size:12.5px;display:flex;gap:6px;align-items:center;height:34px">
          <input type="checkbox" name="tracking_enabled" value="1"
                 <?= ($edit['tracking_enabled'] ?? 1) ? 'checked' : '' ?>> 추적 지원</label></div>
      <?php if ($edit): ?>
      <div class="fw w1"><label>&nbsp;</label>
        <label style="font-weight:400;font-size:12.5px;display:flex;gap:6px;align-items:center;height:34px">
          <input type="checkbox" name="is_active" value="1"
                 <?= $edit['is_active'] ? 'checked' : '' ?>> 사용</label></div>
      <?php endif; ?>
      <button class="btn pri"><?= $edit ? '수정' : '추가' ?></button>
      <?php if ($edit): ?><a class="btn" href="?p=carriers">새로 입력</a><?php endif; ?>
    </form>
  </div>
  <table>
    <thead><tr>
      <th style="width:90px">코드</th><th>이름</th>
      <th class="c" style="width:100px">기본 세금</th>
      <th class="c" style="width:80px">추적</th><th class="c" style="width:70px">정렬</th>
      <th class="c" style="width:70px">사용</th><th class="c" style="width:60px"></th>
    </tr></thead>
    <tbody>
    <?php foreach ($carriers as $c): ?>
      <tr<?= $c['is_active'] ? '' : ' style="opacity:.55"' ?>>
        <td class="tnum" style="font-weight:700"><?= h($c['code']) ?></td>
        <td><?= h($c['name']) ?></td>
        <td class="c"><?= h($TAXT[$c['default_tax_type']] ?? $c['default_tax_type']) ?></td>
        <td class="c"><?= $c['tracking_enabled'] ? 'O' : '-' ?></td>
        <td class="c tnum"><?= (int)$c['sort_order'] ?></td>
        <td class="c"><?= $c['is_active']
            ? '<span class="badge b-ok">사용</span>'
            : '<span class="badge b-err">중지</span>' ?></td>
        <td class="c"><a class="btn sm" href="?p=carriers&amp;edit=<?= (int)$c['id'] ?>">수정</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <div class="pager"><span>운송사를 지우지 않습니다. 안 쓰면 <b>사용</b> 체크를 해제하세요 —
    과거 전표가 이 운송사를 참조하고 있습니다.</span></div>

<?php elseif ($tab === 'service'): ?>
  <?php
  $svc = db()->query(
      'SELECT s.*, c.code AS ccode FROM carrier_services s
         JOIN carriers c ON c.id = s.carrier_id
        ORDER BY c.sort_order, c.code, s.code')->fetchAll();
  ?>
  <div class="cb">
    <form method="post" class="f" style="align-items:flex-end">
      <?= csrf_field() ?>
      <input type="hidden" name="act" value="service_save">
      <div class="fw w1"><label>운송사 *</label>
        <select name="carrier_id" required>
          <option value="">선택</option>
          <?php foreach ($carriers as $c): if (!$c['is_active']) continue; ?>
            <option value="<?= (int)$c['id'] ?>"><?= h($c['code']) ?></option>
          <?php endforeach; ?>
        </select></div>
      <div class="fw w1"><label>서비스 코드 *</label>
        <input type="text" name="code" required placeholder="DHL-EW" style="text-transform:uppercase"></div>
      <div class="fw w3"><label>서비스명 *</label>
        <input type="text" name="name" required placeholder="EXPRESS WORLDWIDE"></div>
      <div class="fw w1"><label>세금구분</label>
        <select name="tax_type">
          <option value="">운송사 기본값</option>
          <?php foreach ($TAXT as $k=>$v): ?><option value="<?= h($k) ?>"><?= h($v) ?></option><?php endforeach; ?>
        </select></div>
      <button class="btn pri">추가</button>
    </form>
  </div>
  <table>
    <thead><tr><th style="width:90px">운송사</th><th style="width:140px">코드</th>
      <th>서비스명</th><th class="c" style="width:110px">세금구분</th>
      <th class="c" style="width:70px">사용</th></tr></thead>
    <tbody>
    <?php foreach ($svc as $s): ?>
      <tr>
        <td class="tnum" style="font-weight:700"><?= h($s['ccode']) ?></td>
        <td class="tnum"><?= h($s['code']) ?></td>
        <td><?= h($s['name']) ?></td>
        <td class="c"><?= $s['tax_type'] ? h($TAXT[$s['tax_type']] ?? $s['tax_type'])
             : '<span style="color:var(--ink3)">기본값</span>' ?></td>
        <td class="c"><?= $s['is_active'] ? 'O' : '-' ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$svc): ?><tr><td colspan="5" class="empty">등록된 서비스가 없습니다.</td></tr><?php endif; ?>
    </tbody>
  </table>

<?php elseif ($tab === 'zone'): ?>
  <?php
  $zones = [];
  if ($selCarrier > 0) {
      $st = db()->prepare('SELECT * FROM carrier_zones WHERE carrier_id = ?
                            ORDER BY zone_no, country_code');
      $st->execute([$selCarrier]);
      $zones = $st->fetchAll();
  }
  $byZone = [];
  foreach ($zones as $z) { $byZone[(int)$z['zone_no']][] = $z; }
  ?>
  <div class="cb">
    <form class="f" method="get" style="align-items:flex-end;margin-bottom:12px">
      <input type="hidden" name="p" value="carriers">
      <input type="hidden" name="tab" value="zone">
      <div class="fw w1"><label>운송사</label>
        <select name="carrier_id" onchange="this.form.submit()">
          <option value="0">선택</option>
          <?php foreach ($carriers as $c): ?>
            <option value="<?= (int)$c['id'] ?>"<?= $selCarrier===(int)$c['id']?' selected':'' ?>><?= h($c['code']) ?></option>
          <?php endforeach; ?>
        </select></div>
    </form>
    <?php if ($selCarrier > 0): ?>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="act" value="zone_bulk">
      <input type="hidden" name="carrier_id" value="<?= $selCarrier ?>">
      <label>Zone 일괄 입력 — 한 줄에 <code>존번호, 국가코드, 국가명</code></label>
      <textarea name="bulk" rows="6" style="font-family:monospace;font-size:12px"
                placeholder="1, JP, JAPAN&#10;1, CN, CHINA&#10;4, US, UNITED STATES"></textarea>
      <div style="display:flex;gap:8px;margin-top:8px;align-items:center">
        <button class="btn pri">넣기</button>
        <span style="font-size:11.5px;color:var(--ink3)">
          엑셀에서 복사해 붙여넣어도 됩니다 (탭 구분). 같은 국가가 이미 있으면 존번호를 덮어씁니다.</span>
      </div>
    </form>
    <?php endif; ?>
  </div>
  <?php if ($selCarrier > 0): ?>
    <?php if (!$byZone): ?>
      <div class="empty">등록된 Zone 이 없습니다.</div>
    <?php else: ?>
    <table>
      <thead><tr><th class="c" style="width:80px">Zone</th><th class="c" style="width:80px">국가수</th>
        <th>국가</th></tr></thead>
      <tbody>
      <?php ksort($byZone); foreach ($byZone as $zn => $list): ?>
        <tr>
          <td class="c tnum" style="font-weight:700"><?= (int)$zn ?></td>
          <td class="c tnum"><?= count($list) ?></td>
          <td style="font-size:11.5px;line-height:1.9">
            <?php foreach ($list as $z): ?>
              <span class="tnum" style="display:inline-block;margin-right:10px">
                <b><?= h($z['country_code']) ?></b>
                <span style="color:var(--ink3)"><?= h($z['country_name'] ?: '') ?></span></span>
            <?php endforeach; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  <?php endif; ?>

<?php else: ?>
  <?php
  $fuel = db()->query(
      'SELECT f.*, c.code AS ccode FROM fuel_surcharges f
         JOIN carriers c ON c.id = f.carrier_id
        ORDER BY c.sort_order, c.code, f.effective_from DESC')->fetchAll();
  ?>
  <div class="cb">
    <form method="post" class="f" style="align-items:flex-end">
      <?= csrf_field() ?>
      <input type="hidden" name="act" value="fuel_save">
      <div class="fw w1"><label>운송사 *</label>
        <select name="carrier_id" required>
          <option value="">선택</option>
          <?php foreach ($carriers as $c): if (!$c['is_active']) continue; ?>
            <option value="<?= (int)$c['id'] ?>"><?= h($c['code']) ?></option>
          <?php endforeach; ?>
        </select></div>
      <div class="fw w1"><label>유류할증률 (%) *</label>
        <input type="text" name="fuel_rate" class="tnum" style="text-align:right" required placeholder="25.5"></div>
      <div class="fw w2"><label>계산 기준</label>
        <select name="calc_basis">
          <?php foreach ($BASIS as $k=>$v): ?><option value="<?= h($k) ?>"><?= h($v) ?></option><?php endforeach; ?>
        </select></div>
      <div class="fw w1"><label>적용 시작일 *</label>
        <input type="date" name="effective_from" required value="<?= h(date('Y-m-01')) ?>"></div>
      <button class="btn pri">등록</button>
    </form>
    <div style="font-size:11.5px;color:var(--ink3);margin-top:8px">
      새로 등록하면 <b>같은 운송사의 열린 구간을 하루 전으로 닫습니다.</b>
      구간이 겹치면 어느 값을 쓸지 알 수 없어서입니다.
      계산 기준이 계약서와 다르면 금액이 달라집니다 — 확인하고 고르세요.
    </div>
  </div>
  <table>
    <thead><tr><th style="width:90px">운송사</th><th class="r" style="width:110px">요율</th>
      <th style="width:170px">계산 기준</th><th style="width:120px">시작</th>
      <th style="width:120px">종료</th><th class="c" style="width:90px">현재</th></tr></thead>
    <tbody>
    <?php $today = date('Y-m-d'); foreach ($fuel as $f):
      $live = $f['effective_from'] <= $today && ($f['effective_to'] === null || $f['effective_to'] >= $today); ?>
      <tr<?= $live ? '' : ' style="opacity:.55"' ?>>
        <td class="tnum" style="font-weight:700"><?= h($f['ccode']) ?></td>
        <td class="r tnum" style="font-weight:700"><?= h(rtrim(rtrim(number_format((float)$f['fuel_rate'],2),'0'),'.')) ?>%</td>
        <td><?= h($BASIS[$f['calc_basis']] ?? $f['calc_basis']) ?></td>
        <td class="tnum"><?= h($f['effective_from']) ?></td>
        <td class="tnum"><?= $f['effective_to'] ? h($f['effective_to']) : '<span style="color:var(--ink3)">열림</span>' ?></td>
        <td class="c"><?= $live ? '<span class="badge b-ok">적용중</span>' : '' ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$fuel): ?><tr><td colspan="6" class="empty">등록된 유류할증률이 없습니다.</td></tr><?php endif; ?>
    </tbody>
  </table>
<?php endif; ?>
</div>
<?php layout_foot();
