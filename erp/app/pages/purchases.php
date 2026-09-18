<?php
require_once APP_DIR . '/layout.php';

$eid = entity_id();
$err = '';

$CHARGE = ['AIR_FREIGHT' => '특송운임', 'DOMESTIC' => '국내운송',
           'HANDLING' => '취급수수료', 'CUSTOMS' => '통관료',
           'STORAGE' => '창고료', 'EXTRA' => '추가매입', 'OTHER' => '기타'];
$TAX    = ['ZERO' => 0.0, 'TAXABLE' => 10.0, 'EXEMPT' => 0.0];

$carriers = db()->query('SELECT id, code FROM carriers WHERE is_active = 1
                          ORDER BY sort_order, code')->fetchAll();

$in = ['purchase_date' => date('Y-m-d'), 'vendor_name' => '', 'carrier_id' => '',
       'awb_no' => '', 'charge_type' => 'AIR_FREIGHT', 'supply_amount' => '',
       'tax_type' => 'ZERO', 'remark' => ''];

// ---------------------------------------------------------------- 등록
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('act') === 'create') {
    csrf_check();
    foreach (array_keys($in) as $k) {
        $in[$k] = post($k);
    }
    $supply = round(num($in['supply_amount']));

    $sid = null;
    if ($in['awb_no'] !== '') {
        $st = db()->prepare(
            'SELECT id FROM shipments
              WHERE awb_no = ? AND business_entity_id = ? AND deleted_at IS NULL');
        $st->execute([$in['awb_no'], $eid]);
        $sid = $st->fetchColumn();
        if (!$sid) {
            $err = 'AWB 번호 ' . $in['awb_no'] . ' 인 전표를 찾을 수 없습니다.';
        }
    }
    if ($err === '' && $in['vendor_name'] === '') {
        $err = '거래상대(운송사·업체)를 입력하세요.';
    }
    if ($err === '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $in['purchase_date'])) {
        $err = '매입일 형식이 올바르지 않습니다.';
    }
    if ($err === '' && !isset($CHARGE[$in['charge_type']])) {
        $err = '비용 종류가 올바르지 않습니다.';
    }
    if ($err === '' && !isset($TAX[$in['tax_type']])) {
        $err = '세금구분이 올바르지 않습니다.';
    }
    if ($err === '' && $supply <= 0) {
        $err = '매입금액을 입력하세요.';
    }

    if ($err === '') {
        $rate = $TAX[$in['tax_type']];
        $tax  = round($supply * $rate / 100);

        // 전표에 붙는 매입이면 예상 매출과 비교해 대사 상태를 정합니다
        $recon = 'UNMATCHED';
        $diff  = null;
        if ($sid) {
            $st = db()->prepare(
                'SELECT COALESCE(SUM(supply_amount),0) FROM shipment_charges
                  WHERE shipment_id = ? AND charge_type = ?');
            $st->execute([$sid, $in['charge_type']]);
            $sale = (float)$st->fetchColumn();
            if ($sale > 0) {
                $diff  = $sale - $supply;
                $recon = ($diff == 0.0) ? 'MATCHED' : 'DIFF';
            }
        }

        try {
            db()->prepare(
                'INSERT INTO purchases
                   (business_entity_id, shipment_id, vendor_name, carrier_id,
                    purchase_date, charge_type, supply_amount, tax_type, tax_amount,
                    total_amount, recon_status, recon_diff, is_paid, remark)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,0,?)')
                ->execute([
                    $eid, $sid ?: null, $in['vendor_name'],
                    $in['carrier_id'] !== '' ? (int)$in['carrier_id'] : null,
                    $in['purchase_date'], $in['charge_type'], $supply,
                    $in['tax_type'], $tax, $supply + $tax, $recon, $diff,
                    $in['remark'] !== '' ? $in['remark'] : null,
                ]);
            $pid = (int)db()->lastInsertId();
            log_action('매입', 'CREATE', 'purchases', $pid,
                       $in['awb_no'] !== '' ? $in['awb_no'] : $in['vendor_name'],
                       null, $in['vendor_name'] . ' ' . number_format($supply));
            flash('매입을 등록했습니다.');
            redirect('?p=purchases');
        } catch (PDOException $e) {
            error_log('매입 저장 실패: ' . $e->getMessage());
            $err = '저장하지 못했습니다.';
        }
    }
}

// ---------------------------------------------------------------- 지급 표시
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('act') === 'paid') {
    csrf_check();
    $pid = (int)post('pid');
    db()->prepare('UPDATE purchases SET is_paid = 1 - is_paid
                    WHERE id = ? AND business_entity_id = ?')->execute([$pid, $eid]);
    log_action('매입', 'UPDATE', 'purchases', $pid, null, null, '지급여부 변경');
    redirect('?p=purchases&from=' . urlencode(post('from')) . '&to=' . urlencode(post('to')));
}

// ---------------------------------------------------------------- 목록
$from = query('from', date('Y-m-01'));
$to   = query('to', date('Y-m-d'));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) { $from = date('Y-m-01'); }
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to))   { $to   = date('Y-m-d'); }

$st = db()->prepare(
    'SELECT p.id, p.purchase_date, p.vendor_name, p.charge_type, p.supply_amount,
            p.tax_type, p.tax_amount, p.total_amount, p.recon_status, p.recon_diff,
            p.is_paid, p.remark, s.awb_no, c.name_ko
       FROM purchases p
       LEFT JOIN shipments s ON s.id = p.shipment_id
       LEFT JOIN companies c ON c.id = s.company_id
      WHERE p.business_entity_id = ? AND p.deleted_at IS NULL
        AND p.purchase_date BETWEEN ? AND ?
      ORDER BY p.purchase_date DESC, p.id DESC
      LIMIT 200');
$st->execute([$eid, $from, $to]);
$rows = $st->fetchAll();

$sum = ['supply' => 0, 'tax' => 0, 'unpaid' => 0, 'diff' => 0];
foreach ($rows as $r) {
    $sum['supply'] += $r['supply_amount'];
    $sum['tax']    += $r['tax_amount'];
    if (!$r['is_paid']) {
        $sum['unpaid'] += $r['total_amount'];
    }
    if ($r['recon_status'] === 'DIFF') {
        $sum['diff']++;
    }
}

layout_head('매입관리', 'purchases');
?>
<div class="head">
  <h1>매입관리</h1>
  <div class="crumb">회계관리 &gt; 매입관리</div>
</div>

<?php if ($err !== ''): ?><div class="msg err"><?= h($err) ?></div><?php endif; ?>

<div class="card">
  <div class="ch">매입 등록
    <span style="font-weight:400;color:var(--ink3)">AWB 번호를 넣으면 해당 전표의 매출과 대사합니다</span>
  </div>
  <div class="cb">
    <form method="post" class="f" style="align-items:flex-end">
      <?= csrf_field() ?>
      <input type="hidden" name="act" value="create">
      <div class="fw w1"><label>매입일 *</label>
        <input type="date" name="purchase_date" required value="<?= h($in['purchase_date']) ?>"></div>
      <div class="fw w2"><label>거래상대 *</label>
        <input type="text" name="vendor_name" required value="<?= h($in['vendor_name']) ?>"
               placeholder="DHL / 한성통운 / 관세법인"></div>
      <div class="fw w1"><label>운송사</label>
        <select name="carrier_id">
          <option value="">해당없음</option>
          <?php foreach ($carriers as $c): ?>
            <option value="<?= (int)$c['id'] ?>"<?= (string)$in['carrier_id']===(string)$c['id']?' selected':'' ?>><?= h($c['code']) ?></option>
          <?php endforeach; ?>
        </select></div>
      <div class="fw w2"><label>AWB 번호 (선택)</label>
        <input type="text" name="awb_no" class="tnum" value="<?= h($in['awb_no']) ?>"
               placeholder="비우면 전표에 붙지 않습니다"></div>
      <div class="fw w1"><label>비용 종류</label>
        <select name="charge_type">
          <?php foreach ($CHARGE as $k=>$v): ?>
            <option value="<?= h($k) ?>"<?= $in['charge_type']===$k?' selected':'' ?>><?= h($v) ?></option>
          <?php endforeach; ?>
        </select></div>
      <div class="fw w1"><label>매입금액 *</label>
        <input type="text" name="supply_amount" class="tnum" style="text-align:right"
               required value="<?= h($in['supply_amount']) ?>"></div>
      <div class="fw w1"><label>세금구분</label>
        <select name="tax_type">
          <option value="ZERO"<?= $in['tax_type']==='ZERO'?' selected':'' ?>>영세율 0%</option>
          <option value="TAXABLE"<?= $in['tax_type']==='TAXABLE'?' selected':'' ?>>과세 10%</option>
          <option value="EXEMPT"<?= $in['tax_type']==='EXEMPT'?' selected':'' ?>>면세</option>
        </select></div>
      <div class="fw gr" style="min-width:180px"><label>비고</label>
        <input type="text" name="remark" value="<?= h($in['remark']) ?>"></div>
      <button class="btn pri">등록</button>
    </form>
  </div>
</div>

<div class="card"><div class="cb">
  <form class="f" method="get" style="align-items:flex-end">
    <input type="hidden" name="p" value="purchases">
    <div class="fw w1"><label for="from">시작일</label>
      <input type="date" id="from" name="from" value="<?= h($from) ?>"></div>
    <div class="fw w1"><label for="to">종료일</label>
      <input type="date" id="to" name="to" value="<?= h($to) ?>"></div>
    <button class="btn">조회</button>
  </form>
</div></div>

<div class="kpis">
  <div class="kpi"><div class="lab">매입 공급가액</div>
    <div class="val tnum"><?= money($sum['supply']) ?></div>
    <div class="sub tnum">VAT <?= money($sum['tax']) ?></div></div>
  <div class="kpi"><div class="lab">미지급</div>
    <div class="val tnum"><?= money($sum['unpaid']) ?></div>
    <div class="sub">지급 표시가 안 된 건의 합계</div></div>
  <div class="kpi"><div class="lab">대사 차액 건</div>
    <div class="val tnum"><?= money($sum['diff']) ?></div>
    <div class="sub">매출 예상액과 매입이 다른 건</div></div>
</div>

<div class="card">
  <div class="ch">매입 목록 <span style="font-weight:400;color:var(--ink3)"><?= h($from) ?> ~ <?= h($to) ?> · 최근 200건</span></div>
  <?php if (!$rows): ?>
    <div class="empty">이 기간에 매입이 없습니다.</div>
  <?php else: ?>
  <table>
    <thead><tr>
      <th style="width:100px">매입일</th><th style="width:140px">거래상대</th>
      <th style="width:150px">AWB</th><th>거래처</th>
      <th style="width:90px">종류</th>
      <th class="r" style="width:110px">공급가액</th><th class="r" style="width:90px">VAT</th>
      <th class="c" style="width:110px">대사</th><th class="c" style="width:80px">지급</th>
    </tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td class="tnum"><?= h($r['purchase_date']) ?></td>
        <td><?= h($r['vendor_name']) ?></td>
        <td class="tnum"><?= $r['awb_no'] ? h($r['awb_no']) : '<span style="color:var(--ink3)">—</span>' ?></td>
        <td><?= h($r['name_ko'] ?? '') ?></td>
        <td><?= h($CHARGE[$r['charge_type']] ?? $r['charge_type']) ?></td>
        <td class="r tnum"><?= money($r['supply_amount']) ?></td>
        <td class="r tnum"><?= money($r['tax_amount']) ?></td>
        <td class="c"><?php
          if ($r['recon_status'] === 'MATCHED') {
              echo '<span class="badge b-ok">일치</span>';
          } elseif ($r['recon_status'] === 'DIFF') {
              echo '<span class="badge b-warn" title="매출 예상액과의 차이">차액 '
                 . h(money($r['recon_diff'])) . '</span>';
          } else {
              echo '<span class="badge b-info">미대사</span>';
          }
        ?></td>
        <td class="c">
          <form method="post" style="display:inline">
            <?= csrf_field() ?>
            <input type="hidden" name="act" value="paid">
            <input type="hidden" name="pid" value="<?= (int)$r['id'] ?>">
            <input type="hidden" name="from" value="<?= h($from) ?>">
            <input type="hidden" name="to" value="<?= h($to) ?>">
            <button class="btn sm" style="<?= $r['is_paid'] ? 'background:var(--ok-bg);color:var(--ok-fg);border-color:var(--ok-bg)' : '' ?>">
              <?= $r['is_paid'] ? '지급완료' : '미지급' ?></button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>
<?php layout_foot();
