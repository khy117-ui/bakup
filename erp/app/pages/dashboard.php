<?php
require_once APP_DIR . '/layout.php';

$eid = entity_id();
$ym  = date('Y-m');

$st = db()->prepare(
    'SELECT COALESCE(SUM(t.supply_total),0) AS supply,
            COALESCE(SUM(t.tax_total),0)    AS tax,
            COUNT(*)                        AS cnt
       FROM v_shipment_totals t
      WHERE t.business_entity_id = ?
        AND DATE_FORMAT(t.voucher_date, \'%Y-%m\') = ?');
$st->execute([$eid, $ym]);
$mon = $st->fetch() ?: ['supply' => 0, 'tax' => 0, 'cnt' => 0];

$st = db()->prepare(
    'SELECT COUNT(*) FROM shipments s
      WHERE s.business_entity_id = ? AND s.deleted_at IS NULL
        AND s.status IN (\'DRAFT\',\'CONFIRMED\')');
$st->execute([$eid]);
$unbilled = (int)$st->fetchColumn();

$st = db()->prepare('SELECT COUNT(*) FROM companies WHERE deleted_at IS NULL');
$st->execute();
$compCnt = (int)$st->fetchColumn();

$st = db()->prepare(
    'SELECT s.id, s.awb_no, s.voucher_date, s.status, c.name_ko,
            COALESCE(t.supply_total,0) AS supply, COALESCE(t.tax_total,0) AS tax
       FROM shipments s
       JOIN companies c ON c.id = s.company_id
       LEFT JOIN v_shipment_totals t ON t.shipment_id = s.id
      WHERE s.business_entity_id = ? AND s.deleted_at IS NULL
      ORDER BY s.voucher_date DESC, s.id DESC
      LIMIT 10');
$st->execute([$eid]);
$recent = $st->fetchAll();

// 입출금 — 상단 사업자 선택을 따릅니다. 계좌이체는 어디에도 안 잡힙니다
$cashToday = ['in_sum'=>0,'out_sum'=>0];
$cashMonth = ['in_sum'=>0,'out_sum'=>0];
$arSum = ['bal'=>0,'comp_cnt'=>0];
$apSum = ['bal'=>0];
$cashBal = 0.0;
try {
    $params = [];
    $w = entity_where('f.business_entity_id', $params);
    $sql = "SELECT
              COALESCE(SUM(CASE WHEN f.txn_type='IN'  THEN f.amount END),0) AS in_sum,
              COALESCE(SUM(CASE WHEN f.txn_type='OUT' THEN f.amount END),0) AS out_sum
            FROM financial_transactions f
           WHERE $w AND f.status='CONFIRMED' AND f.txn_type <> 'TRANSFER'
             AND f.txn_date BETWEEN ? AND ?";

    $p1 = $params; array_push($p1, date('Y-m-d'), date('Y-m-d'));
    $st = db()->prepare($sql); $st->execute($p1); $cashToday = $st->fetch() ?: $cashToday;

    $p2 = $params; array_push($p2, date('Y-m-01'), date('Y-m-t'));
    $st = db()->prepare($sql); $st->execute($p2); $cashMonth = $st->fetch() ?: $cashMonth;

    // 미수 = 전환일 이후 전표 + 기초잔액 (거래처별 뷰에 둘 다 들어 있습니다)
    $params = [];
    $w = entity_where('v.business_entity_id', $params);
    $st = db()->prepare("SELECT COALESCE(SUM(v.balance_total),0) AS bal,
                                COUNT(CASE WHEN v.balance_total > 0 THEN 1 END) AS comp_cnt
                           FROM v_company_receivable v WHERE $w");
    $st->execute($params); $arSum = $st->fetch() ?: $arSum;

    // 미지급 = 전환일 이후 매입 + 기초 미지급
    $params = [];
    $w = entity_where('p.business_entity_id', $params);
    $st = db()->prepare("SELECT COALESCE(SUM(p.balance),0) AS bal FROM v_purchase_payable p
                          WHERE $w AND p.pay_status NOT IN ('NONE','OPENING')");
    $st->execute($params); $apSum = $st->fetch() ?: $apSum;
    try {
        $params = [];
        $w = entity_where('o.business_entity_id', $params);
        $st = db()->prepare("SELECT COALESCE(SUM(o.remaining),0) FROM v_opening_balance o
                              WHERE $w AND o.balance_type = 'AP' AND o.status = 'CONFIRMED'");
        $st->execute($params);
        $apSum['bal'] = (float)$apSum['bal'] + (float)$st->fetchColumn();
    } catch (PDOException $e) {
        // 19번을 아직 안 돌렸을 수 있습니다
    }

    $params = [];
    $w = entity_where('v.business_entity_id', $params);
    $st = db()->prepare("SELECT COALESCE(SUM(v.balance),0) FROM v_account_balance v
                          WHERE $w AND v.is_active = 1");
    $st->execute($params); $cashBal = (float)$st->fetchColumn();
} catch (PDOException $e) {
    // 18_cash_management.sql 을 아직 안 돌렸을 수 있습니다
    $cashReady = false;
}
$cashReady = $cashReady ?? true;

layout_head('대시보드', 'dashboard');
// 권한에 맞는 카드만 보여줍니다 — 매출은 매출전표 조회, 입출금은 입출금 조회 권한이 있어야
$seeSales = route_can_view('shipments');
$seeComp  = route_can_view('companies');
$seeCash  = route_can_view('cash_dashboard');
?>
<div class="head">
  <h1>대시보드</h1>
  <div class="crumb"><?= h(date('Y년 n월 j일')) ?> 기준</div>
  <?php if ($seeSales && route_can_edit('shipment_form')): ?>
  <div class="right">
    <a class="btn pri" href="?p=shipment_form">매출전표 등록</a>
  </div>
  <?php endif; ?>
</div>

<?php if ($seeSales || $seeComp): ?>
<div class="kpis">
  <?php if ($seeSales): ?>
  <div class="kpi">
    <div class="lab">이번 달 매출 (공급가액)</div>
    <div class="val tnum"><?= money($mon['supply']) ?><span style="font-size:13px;font-weight:600"> 원</span></div>
    <div class="sub tnum">VAT <?= money($mon['tax']) ?> 원 · 전표 <?= money($mon['cnt']) ?> 건</div>
  </div>
  <div class="kpi">
    <div class="lab">미청구 전표</div>
    <div class="val tnum"><?= money($unbilled) ?><span style="font-size:13px;font-weight:600"> 건</span></div>
    <div class="sub">청구관리 화면은 준비중입니다</div>
  </div>
  <?php endif; ?>
  <?php if ($seeComp): ?>
  <div class="kpi">
    <div class="lab">거래처</div>
    <div class="val tnum"><?= money($compCnt) ?><span style="font-size:13px;font-weight:600"> 곳</span></div>
    <div class="sub"><a href="?p=companies">목록 보기</a></div>
  </div>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php if ($cashReady && $seeCash): ?>
<div class="kpis">
  <div class="kpi"><div class="lab">오늘 입금 · 출금</div>
    <div class="val tnum" style="font-size:19px">
      <span style="color:#1B7F5A"><?= money($cashToday['in_sum']) ?></span>
      <span style="color:var(--ink3);font-size:14px"> / </span>
      <span style="color:#B3261E"><?= money($cashToday['out_sum']) ?></span></div>
    <div class="sub">이번달 <?= money($cashMonth['in_sum']) ?> / <?= money($cashMonth['out_sum']) ?></div></div>
  <div class="kpi"><div class="lab">현재 총 미수금</div>
    <div class="val tnum" style="color:var(--err-fg)"><?= money($arSum['bal']) ?></div>
    <div class="sub">거래처 <?= money($arSum['comp_cnt']) ?>곳 ·
      <a href="?p=receivables">미수금 관리</a></div></div>
  <div class="kpi"><div class="lab">현재 미지급금</div>
    <div class="val tnum"><?= money($apSum['bal']) ?></div>
    <div class="sub">운송사 등에 줄 돈</div></div>
  <div class="kpi"><div class="lab">계좌 잔액 합계</div>
    <div class="val tnum"><?= money($cashBal) ?></div>
    <div class="sub"><a href="?p=cash_dashboard">입출금 현황</a></div></div>
</div>
<?php endif; ?>

<?php if (!$seeSales && !$seeComp && !$seeCash): ?>
  <div class="card"><div class="empty">왼쪽 메뉴에서 맡은 업무 화면으로 들어가세요.</div></div>
<?php endif; ?>

<?php if ($seeSales): ?>
<div class="card">
  <div class="ch">최근 매출전표
    <a class="btn sm" style="margin-left:auto" href="?p=shipments">전체 보기</a>
  </div>
  <?php if (!$recent): ?>
    <div class="empty">등록된 전표가 없습니다. <a href="?p=shipment_form">첫 전표를 등록</a>해 보세요.</div>
  <?php else: ?>
  <table>
    <thead><tr>
      <th style="width:110px">전표일</th><th style="width:170px">AWB</th>
      <th>거래처</th><th class="r" style="width:120px">공급가액</th>
      <th class="r" style="width:100px">VAT</th><th class="c" style="width:90px">상태</th>
    </tr></thead>
    <tbody>
    <?php foreach ($recent as $r): ?>
      <tr>
        <td class="tnum"><?= h($r['voucher_date']) ?></td>
        <td class="tnum" style="font-weight:600"><?= h($r['awb_no']) ?></td>
        <td><?= h($r['name_ko']) ?></td>
        <td class="r tnum"><?= money($r['supply']) ?></td>
        <td class="r tnum"><?= money($r['tax']) ?></td>
        <td class="c"><span class="badge <?= $r['status'] === 'PAID' ? 'b-ok'
            : ($r['status'] === 'BILLED' ? 'b-info' : 'b-warn') ?>"><?= h($r['status']) ?></span></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>
<?php endif; ?>
<?php layout_foot();
