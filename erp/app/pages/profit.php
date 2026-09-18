<?php
require APP_DIR . '/layout.php';

$eid  = entity_id();
$from = query('from', date('Y-01-01'));
$to   = query('to', date('Y-m-d'));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) { $from = date('Y-01-01'); }
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to))   { $to   = date('Y-m-d'); }
$tab = query('tab', 'month');
if (!in_array($tab, ['month', 'company', 'voucher'], true)) { $tab = 'month'; }

// 전표 단위 손익 — v_shipment_profit 이 매출·매입을 이미 묶어 놓았습니다
$base = 'FROM v_shipment_profit p
          JOIN shipments s ON s.id = p.shipment_id
          JOIN companies c ON c.id = p.company_id
         WHERE p.business_entity_id = ? AND p.voucher_date BETWEEN ? AND ?
           AND s.deleted_at IS NULL';

$st = db()->prepare(
    'SELECT DATE_FORMAT(p.voucher_date, \'%Y-%m\') AS k, COUNT(*) AS cnt,
            COALESCE(SUM(p.revenue),0) AS revenue,
            COALESCE(SUM(p.cost),0)    AS cost,
            COALESCE(SUM(p.profit),0)  AS profit ' . $base . '
     GROUP BY k ORDER BY k');
$st->execute([$eid, $from, $to]);
$byMonth = $st->fetchAll();

$st = db()->prepare(
    'SELECT c.name_ko AS k, COUNT(*) AS cnt,
            COALESCE(SUM(p.revenue),0) AS revenue,
            COALESCE(SUM(p.cost),0)    AS cost,
            COALESCE(SUM(p.profit),0)  AS profit ' . $base . '
     GROUP BY p.company_id ORDER BY profit DESC LIMIT 100');
$st->execute([$eid, $from, $to]);
$byCompany = $st->fetchAll();

// 이익률이 낮거나 적자인 전표 — 먼저 봐야 하는 것
$st = db()->prepare(
    'SELECT s.awb_no AS k, s.voucher_date, c.name_ko, 1 AS cnt,
            p.revenue, p.cost, p.profit, p.profit_rate ' . $base . '
       AND p.cost > 0
     ORDER BY p.profit ASC LIMIT 30');
$st->execute([$eid, $from, $to]);
$worst = $st->fetchAll();

$rows = $tab === 'company' ? $byCompany : ($tab === 'voucher' ? $worst : $byMonth);

$tot = ['revenue' => 0, 'cost' => 0, 'profit' => 0, 'cnt' => 0];
foreach ($byMonth as $r) {
    $tot['revenue'] += $r['revenue'];
    $tot['cost']    += $r['cost'];
    $tot['profit']  += $r['profit'];
    $tot['cnt']     += $r['cnt'];
}
$rate = $tot['revenue'] > 0 ? $tot['profit'] / $tot['revenue'] * 100 : null;

// 매입이 하나도 없으면 손익이 매출과 같아져 오해를 부릅니다. 그걸 알려줍니다
$st = db()->prepare('SELECT COUNT(*) FROM purchases
                      WHERE business_entity_id = ? AND deleted_at IS NULL
                        AND purchase_date BETWEEN ? AND ?');
$st->execute([$eid, $from, $to]);
$purCnt = (int)$st->fetchColumn();

$maxAbs = 0.0;
foreach ($rows as $r) {
    $maxAbs = max($maxAbs, abs((float)$r['profit']));
}

layout_head('손익관리', 'profit');
?>
<div class="head">
  <h1>손익관리</h1>
  <div class="crumb">회계관리 &gt; 손익관리</div>
</div>

<?php if ($purCnt === 0): ?>
  <div class="msg err">이 기간에 <b>매입이 한 건도 없습니다.</b> 그래서 이익이 매출과 같게 나옵니다.
    실제 손익을 보려면 <a href="?p=purchases">매입관리</a>에서 운송사 정산액을 먼저 넣으세요.</div>
<?php endif; ?>

<div class="card"><div class="cb">
  <form class="f" method="get" style="align-items:flex-end">
    <input type="hidden" name="p" value="profit">
    <input type="hidden" name="tab" value="<?= h($tab) ?>">
    <div class="fw w1"><label for="from">시작일</label>
      <input type="date" id="from" name="from" value="<?= h($from) ?>"></div>
    <div class="fw w1"><label for="to">종료일</label>
      <input type="date" id="to" name="to" value="<?= h($to) ?>"></div>
    <button class="btn">조회</button>
  </form>
</div></div>

<div class="kpis">
  <div class="kpi"><div class="lab">매출 (공급가액)</div>
    <div class="val tnum" style="color:#2a78d6"><?= money($tot['revenue']) ?></div>
    <div class="sub tnum">전표 <?= money($tot['cnt']) ?> 건</div></div>
  <div class="kpi"><div class="lab">매입</div>
    <div class="val tnum" style="color:#eb6834"><?= money($tot['cost']) ?></div>
    <div class="sub tnum">매입 <?= money($purCnt) ?> 건</div></div>
  <div class="kpi"><div class="lab">이익</div>
    <div class="val tnum" style="color:<?= $tot['profit'] < 0 ? 'var(--err-fg)' : '#1baf7a' ?>">
      <?= money($tot['profit']) ?></div>
    <div class="sub tnum">이익률 <?= $rate === null ? '-' : number_format($rate, 1) . '%' ?></div></div>
</div>

<div class="card">
  <div class="ch">
    <?php $qs = '&amp;from=' . h($from) . '&amp;to=' . h($to); ?>
    <a class="btn sm<?= $tab==='month'?' pri':'' ?>"   href="?p=profit&amp;tab=month<?= $qs ?>">월별</a>
    <a class="btn sm<?= $tab==='company'?' pri':'' ?>" href="?p=profit&amp;tab=company<?= $qs ?>">업체별</a>
    <a class="btn sm<?= $tab==='voucher'?' pri':'' ?>" href="?p=profit&amp;tab=voucher<?= $qs ?>">이익 낮은 전표</a>
    <span style="margin-left:auto;font-weight:400;color:var(--ink3)">
      <?= h($from) ?> ~ <?= h($to) ?></span>
  </div>
  <?php if (!$rows): ?>
    <div class="empty">이 기간에 자료가 없습니다.</div>
  <?php else: ?>
  <table>
    <thead><tr>
      <th style="width:180px"><?= $tab==='month' ? '월' : ($tab==='company' ? '업체' : 'AWB') ?></th>
      <?php if ($tab==='voucher'): ?>
        <th style="width:100px">전표일</th><th style="width:150px">거래처</th>
      <?php else: ?>
        <th class="r" style="width:70px">건수</th>
      <?php endif; ?>
      <th class="r" style="width:130px">매출</th>
      <th class="r" style="width:130px">매입</th>
      <th class="r" style="width:130px">이익</th>
      <th class="r" style="width:80px">이익률</th>
      <th style="width:200px">이익 크기</th>
    </tr></thead>
    <tbody>
    <?php foreach ($rows as $r):
      $pr = (float)$r['profit'];
      $rv = (float)$r['revenue'];
      $pct = $rv > 0 ? $pr / $rv * 100 : null;
      $w = $maxAbs > 0 ? round(abs($pr) / $maxAbs * 150) : 0;
    ?>
      <tr>
        <td style="font-weight:600"><?= h($r['k']) ?></td>
        <?php if ($tab==='voucher'): ?>
          <td class="tnum"><?= h($r['voucher_date']) ?></td>
          <td><?= h($r['name_ko']) ?></td>
        <?php else: ?>
          <td class="r tnum"><?= money($r['cnt']) ?></td>
        <?php endif; ?>
        <td class="r tnum"><?= money($rv) ?></td>
        <td class="r tnum"><?= money($r['cost']) ?></td>
        <td class="r tnum" style="font-weight:700;color:<?= $pr < 0 ? 'var(--err-fg)' : 'var(--ink)' ?>">
          <?= money($pr) ?></td>
        <td class="r tnum" style="color:<?= $pct !== null && $pct < 10 ? 'var(--err-fg)' : 'var(--ink2)' ?>">
          <?= $pct === null ? '-' : number_format($pct, 1) . '%' ?></td>
        <td><div class="barwrap">
          <div class="bar" style="width:<?= $w ?>px;background:<?= $pr < 0 ? '#eb6834' : '#1baf7a' ?>"></div>
          <?php if ($pr < 0): ?>
            <span class="badge b-err">적자</span>
          <?php endif; ?>
        </div></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <div class="pager">
    <span>
      <?php if ($tab === 'voucher'): ?>
        매입이 등록된 전표만 나옵니다. 이익이 낮은 순서입니다.
      <?php else: ?>
        막대는 이익 크기입니다. <span style="color:#1baf7a;font-weight:700">초록</span>은 이익,
        <span style="color:#eb6834;font-weight:700">주황</span>은 적자입니다.
      <?php endif; ?>
    </span>
  </div>
  <?php endif; ?>
</div>
<?php layout_foot();
