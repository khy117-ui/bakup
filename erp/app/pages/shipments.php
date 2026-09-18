<?php
require_once APP_DIR . '/layout.php';

$eid  = entity_id();
$kw   = query('kw');
$from = query('from');
$to   = query('to');
// 상태 — unbilled 는 대시보드 '미청구 전표' 와 같은 조건 (임시 · 확정)
$STATUS = ['unbilled' => ['DRAFT', 'CONFIRMED'], 'BILLED' => ['BILLED'], 'PAID' => ['PAID'],
           'CANCELLED' => ['CANCELLED']];
$status = array_key_exists(query('status'), $STATUS) ? query('status') : '';
$dest   = trim(query('dest'));   // 도착지 관리에서 '전표 수' 를 누르면
$page = max(1, (int)query('page', '1'));
$per  = 20;
$off  = ($page - 1) * $per;

$where  = ['s.business_entity_id = ?', 's.deleted_at IS NULL'];
$params = [$eid];
if ($kw !== '') {
    $where[] = '(s.awb_no LIKE ? OR c.name_ko LIKE ?)';
    $like = '%' . $kw . '%';
    array_push($params, $like, $like);
}
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
    $where[] = 's.voucher_date >= ?';
    $params[] = $from;
}
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
    $where[] = 's.voucher_date <= ?';
    $params[] = $to;
}
if ($dest !== '') {
    $where[] = 's.dest_city = ?';
    $params[] = $dest;
}
if ($status !== '') {
    $where[] = 's.status IN (' . implode(',', array_fill(0, count($STATUS[$status]), '?')) . ')';
    array_push($params, ...$STATUS[$status]);
}
$w = implode(' AND ', $where);

$st = db()->prepare("SELECT COUNT(*) FROM shipments s JOIN companies c ON c.id = s.company_id WHERE $w");
$st->execute($params);
$total = (int)$st->fetchColumn();

$st = db()->prepare(
    "SELECT s.id, s.awb_no, s.voucher_date, s.trade_type, s.status, s.charge_weight,
            c.name_ko, ca.code AS carrier,
            COALESCE(t.zero_supply,0) AS zero_supply,
            COALESCE(t.taxable_supply,0) AS taxable_supply,
            COALESCE(t.tax_total,0) AS tax_total,
            COALESCE(t.grand_total,0) AS grand_total
       FROM shipments s
       JOIN companies c  ON c.id = s.company_id
       JOIN carriers  ca ON ca.id = s.carrier_id
       LEFT JOIN v_shipment_totals t ON t.shipment_id = s.id
      WHERE $w
      ORDER BY s.voucher_date DESC, s.id DESC
      LIMIT $per OFFSET $off");
$st->execute($params);
$rows = $st->fetchAll();

$st = db()->prepare(
    "SELECT COALESCE(SUM(t.supply_total),0) AS supply, COALESCE(SUM(t.tax_total),0) AS tax
       FROM shipments s JOIN companies c ON c.id = s.company_id
       LEFT JOIN v_shipment_totals t ON t.shipment_id = s.id
      WHERE $w");
$st->execute($params);
$sum = $st->fetch() ?: ['supply'=>0,'tax'=>0];

layout_head('매출전표', 'shipments');
?>
<div class="head">
  <h1>매출전표</h1>
  <div class="crumb">물류관리 &gt; 매출전표</div>
  <div class="right"><a class="btn pri" href="?p=shipment_form">전표 등록</a></div>
</div>

<div class="card"><div class="cb">
  <form class="f" method="get" style="align-items:flex-end">
    <input type="hidden" name="p" value="shipments">
    <div class="fw w3"><label for="kw">검색</label>
      <input type="text" id="kw" name="kw" value="<?= h($kw) ?>" placeholder="AWB · 거래처명"></div>
    <div class="fw w1"><label for="from">전표일 시작</label>
      <input type="date" id="from" name="from" value="<?= h($from) ?>"></div>
    <div class="fw w1"><label for="to">전표일 종료</label>
      <input type="date" id="to" name="to" value="<?= h($to) ?>"></div>
    <div class="fw w1"><label for="status">상태</label>
      <select id="status" name="status">
        <option value="">전체</option>
        <option value="unbilled"<?= $status==='unbilled'?' selected':'' ?>>미청구</option>
        <option value="BILLED"<?= $status==='BILLED'?' selected':'' ?>>청구</option>
        <option value="PAID"<?= $status==='PAID'?' selected':'' ?>>입금</option>
        <option value="CANCELLED"<?= $status==='CANCELLED'?' selected':'' ?>>취소</option>
      </select></div>
    <?php if ($dest !== ''): ?><input type="hidden" name="dest" value="<?= h($dest) ?>">
      <span class="badge b-info" style="height:34px">도착지: <?= h($dest) ?></span><?php endif; ?>
    <button class="btn">검색</button>
    <a class="btn" href="?p=shipments">초기화</a>
  </form>
</div></div>

<div class="kpis">
  <div class="kpi"><div class="lab">검색 결과 공급가액</div>
    <div class="val tnum"><?= money($sum['supply']) ?></div>
    <div class="sub tnum">VAT <?= money($sum['tax']) ?> · 합계 <?= money($sum['supply']+$sum['tax']) ?></div></div>
  <div class="kpi"><div class="lab">전표 건수</div>
    <div class="val tnum"><?= money($total) ?></div>
    <div class="sub">조건에 맞는 전표</div></div>
</div>

<div class="card">
  <?php if (!$rows): ?>
    <div class="empty">전표가 없습니다.</div>
  <?php else: ?>
  <table>
    <thead><tr>
      <th style="width:100px">전표일</th><th style="width:160px">AWB</th>
      <th>거래처</th><th class="c" style="width:70px">운송사</th>
      <th class="c" style="width:60px">구분</th><th class="r" style="width:75px">중량</th>
      <th class="r" style="width:105px">영세</th><th class="r" style="width:105px">과세</th>
      <th class="r" style="width:90px">VAT</th><th class="r" style="width:110px">합계</th>
      <th class="c" style="width:85px">상태</th>
      <th class="c" style="width:60px"></th>
    </tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td class="tnum"><?= h($r['voucher_date']) ?></td>
        <td class="tnum" style="font-weight:600"><a href="?p=shipment_form&amp;id=<?= (int)$r['id'] ?>"><?= h($r['awb_no']) ?></a></td>
        <td><?= h($r['name_ko']) ?></td>
        <td class="c"><?= h($r['carrier']) ?></td>
        <td class="c"><?= $r['trade_type'] === 'IMPORT' ? '수입' : '수출' ?></td>
        <td class="r tnum"><?= $r['charge_weight'] !== null ? h(rtrim(rtrim(number_format((float)$r['charge_weight'],2),'0'),'.')) : '-' ?></td>
        <td class="r tnum"><?= money($r['zero_supply']) ?></td>
        <td class="r tnum"><?= money($r['taxable_supply']) ?></td>
        <td class="r tnum"><?= money($r['tax_total']) ?></td>
        <td class="r tnum" style="font-weight:700"><?= money($r['grand_total']) ?></td>
        <?php [$sl, $sc] = shipment_status_badge((string)$r['status']); ?>
        <td class="c"><span class="badge <?= $sc ?>"><?= h($sl) ?></span></td>
        <td class="c"><a class="btn sm" href="?p=shipment_form&amp;id=<?= (int)$r['id'] ?>">보기</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <div class="pager">
    <span>전체 <b class="tnum"><?= money($total) ?></b> 건 ·
      <span class="tnum"><?= money($off+1) ?>–<?= money(min($off+$per,$total)) ?></span></span>
    <div class="right">
      <?php $qs='p=shipments&kw='.urlencode($kw).'&from='.urlencode($from).'&to='.urlencode($to).'&status='.urlencode($status).'&dest='.urlencode($dest); ?>
      <?php if ($page>1): ?><a class="btn sm" href="?<?= h($qs) ?>&amp;page=<?= $page-1 ?>">이전</a><?php endif; ?>
      <?php if ($off+$per<$total): ?><a class="btn sm" href="?<?= h($qs) ?>&amp;page=<?= $page+1 ?>">다음</a><?php endif; ?>
    </div>
  </div>
  <?php endif; ?>
</div>
<?php layout_foot();
