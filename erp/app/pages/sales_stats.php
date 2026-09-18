<?php
require_once APP_DIR . '/layout.php';

$eid  = entity_id();
$from = query('from', date('Y-01-01'));
$to   = query('to', date('Y-m-d'));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) { $from = date('Y-01-01'); }
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to))   { $to   = date('Y-m-d'); }
$tab = query('tab', 'company') === 'month' ? 'month' : 'company';

// 업체별
$st = db()->prepare(
    'SELECT c.name_ko, c.company_code,
            COUNT(*) AS cnt,
            COALESCE(SUM(t.charge_weight),0) AS wt,
            COALESCE(SUM(t.zero_supply),0)    AS zero_supply,
            COALESCE(SUM(t.taxable_supply),0) AS taxable_supply,
            COALESCE(SUM(t.tax_total),0)      AS tax_total,
            COALESCE(SUM(t.grand_total),0)    AS grand_total
       FROM v_shipment_totals t
       JOIN companies c ON c.id = t.company_id
      WHERE t.business_entity_id = ? AND t.voucher_date BETWEEN ? AND ?
      GROUP BY t.company_id
      ORDER BY grand_total DESC
      LIMIT 100');
$st->execute([$eid, $from, $to]);
$byCompany = $st->fetchAll();

// 월별
$st = db()->prepare(
    'SELECT DATE_FORMAT(t.voucher_date, \'%Y-%m\') AS ym,
            COUNT(*) AS cnt,
            COALESCE(SUM(t.zero_supply),0)    AS zero_supply,
            COALESCE(SUM(t.taxable_supply),0) AS taxable_supply,
            COALESCE(SUM(t.tax_total),0)      AS tax_total,
            COALESCE(SUM(t.grand_total),0)    AS grand_total
       FROM v_shipment_totals t
      WHERE t.business_entity_id = ? AND t.voucher_date BETWEEN ? AND ?
      GROUP BY ym ORDER BY ym');
$st->execute([$eid, $from, $to]);
$byMonth = $st->fetchAll();

$rows = $tab === 'month' ? $byMonth : $byCompany;
$maxV = 0.0;
foreach ($rows as $r) { $maxV = max($maxV, (float)$r['grand_total']); }

$tot = ['zero'=>0,'tax'=>0,'vat'=>0,'grand'=>0,'cnt'=>0];
foreach ($byCompany as $r) {
    $tot['zero']  += $r['zero_supply'];
    $tot['tax']   += $r['taxable_supply'];
    $tot['vat']   += $r['tax_total'];
    $tot['grand'] += $r['grand_total'];
    $tot['cnt']   += $r['cnt'];
}

layout_head('매출통계', 'sales_stats');
?>
<div class="head">
  <h1>매출통계</h1>
  <div class="crumb">회계관리 &gt; 매출통계</div>
</div>

<div class="card"><div class="cb">
  <form class="f" method="get" style="align-items:flex-end">
    <input type="hidden" name="p" value="sales_stats">
    <input type="hidden" name="tab" value="<?= h($tab) ?>">
    <div class="fw w1"><label for="from">시작일</label>
      <input type="date" id="from" name="from" value="<?= h($from) ?>"></div>
    <div class="fw w1"><label for="to">종료일</label>
      <input type="date" id="to" name="to" value="<?= h($to) ?>"></div>
    <button class="btn">조회</button>
  </form>
</div></div>

<div class="kpis">
  <div class="kpi"><div class="lab">영세율 공급가액</div>
    <div class="val tnum"><?= money($tot['zero']) ?></div></div>
  <div class="kpi"><div class="lab">과세 공급가액</div>
    <div class="val tnum"><?= money($tot['tax']) ?></div>
    <div class="sub tnum">VAT <?= money($tot['vat']) ?></div></div>
  <div class="kpi"><div class="lab">합계</div>
    <div class="val tnum"><?= money($tot['grand']) ?></div>
    <div class="sub tnum">전표 <?= money($tot['cnt']) ?> 건</div></div>
</div>

<div class="card">
  <div class="ch">
    <a class="btn sm<?= $tab==='company'?' pri':'' ?>"
       href="?p=sales_stats&amp;tab=company&amp;from=<?= h($from) ?>&amp;to=<?= h($to) ?>">업체별</a>
    <a class="btn sm<?= $tab==='month'?' pri':'' ?>"
       href="?p=sales_stats&amp;tab=month&amp;from=<?= h($from) ?>&amp;to=<?= h($to) ?>">월별</a>
    <span style="margin-left:auto;font-weight:400;color:var(--ink3)">
      <?= h($from) ?> ~ <?= h($to) ?></span>
  </div>
  <?php if (!$rows): ?>
    <div class="empty">이 기간에 전표가 없습니다.</div>
  <?php else: ?>
  <table>
    <thead><tr>
      <th style="width:190px"><?= $tab==='month' ? '월' : '업체' ?></th>
      <th class="r" style="width:70px">건수</th>
      <?php if ($tab==='company'): ?><th class="r" style="width:80px">중량</th><?php endif; ?>
      <th class="r" style="width:120px">영세</th>
      <th class="r" style="width:120px">과세</th>
      <th class="r" style="width:100px">VAT</th>
      <th class="r" style="width:130px">합계</th>
      <th style="width:230px">비중</th>
    </tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td style="font-weight:600"><?= h($tab==='month' ? $r['ym'] : $r['name_ko']) ?></td>
        <td class="r tnum"><?= money($r['cnt']) ?></td>
        <?php if ($tab==='company'): ?>
          <td class="r tnum"><?= h(rtrim(rtrim(number_format((float)$r['wt'],1),'0'),'.')) ?></td>
        <?php endif; ?>
        <td class="r tnum"><?= money($r['zero_supply']) ?></td>
        <td class="r tnum"><?= money($r['taxable_supply']) ?></td>
        <td class="r tnum"><?= money($r['tax_total']) ?></td>
        <td class="r tnum" style="font-weight:700"><?= money($r['grand_total']) ?></td>
        <td><div class="barwrap">
          <div class="bar" style="width:<?= $maxV>0 ? round((float)$r['grand_total']/$maxV*160) : 0 ?>px"></div>
          <span class="tnum" style="font-size:11.5px;color:var(--ink3)">
            <?= $tot['grand']>0 ? number_format((float)$r['grand_total']/$tot['grand']*100,1) : '0.0' ?>%</span>
        </div></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>
<?php layout_foot();
