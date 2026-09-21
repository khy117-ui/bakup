<?php
// 웹에서 직접 열면 실행되지 않게 막습니다 (nginx 면 .htaccess 가 무시됩니다)
if (!defined('APP_DIR')) { http_response_code(403); exit('Forbidden'); }

require_once APP_DIR . '/layout.php';

/**
 * 입출금 현황 — 돈이 지금 어떻게 돌고 있는지 한 화면에.
 *
 * 상단 사업자 선택([전체] / 각 법인)에 따라 **모든 숫자가 같이 바뀝니다.**
 * 계좌이체는 어디에도 안 잡힙니다 — 회사 통장 사이 이동은 수입도 지출도 아닙니다.
 */

require_perm('CASH_VIEW', '입출금 조회');

$today   = date('Y-m-d');
$mFrom   = date('Y-m-01');
$mTo     = date('Y-m-t');

/** 기간 합계 — 이체 제외 */
function cash_sum(string $from, string $to): array
{
    $params = [];
    $w = entity_where('f.business_entity_id', $params);
    array_push($params, $from, $to);
    $st = db()->prepare(
        "SELECT
           COALESCE(SUM(CASE WHEN f.txn_type='IN'  THEN f.amount END),0) AS in_sum,
           COALESCE(SUM(CASE WHEN f.txn_type='OUT' THEN f.amount END),0) AS out_sum,
           COUNT(CASE WHEN f.txn_type='IN'  THEN 1 END) AS in_cnt,
           COUNT(CASE WHEN f.txn_type='OUT' THEN 1 END) AS out_cnt
         FROM financial_transactions f
        WHERE $w AND f.status = 'CONFIRMED' AND f.txn_type <> 'TRANSFER'
          AND f.txn_date BETWEEN ? AND ?");
    $st->execute($params);
    return $st->fetch() ?: ['in_sum'=>0,'out_sum'=>0,'in_cnt'=>0,'out_cnt'=>0];
}

$d = cash_sum($today, $today);
$m = cash_sum($mFrom, $mTo);

// 이번달 매출
$params = [];
$w = entity_where('s.business_entity_id', $params);
array_push($params, $mFrom, $mTo);
$st = db()->prepare(
    "SELECT COALESCE(SUM(t.grand_total),0) AS sales, COUNT(*) AS cnt
       FROM shipments s JOIN v_shipment_totals t ON t.shipment_id = s.id
      WHERE $w AND s.deleted_at IS NULL AND s.status <> 'CANCELLED'
        AND s.voucher_date BETWEEN ? AND ?");
$st->execute($params);
$mSales = $st->fetch();

// 미수금 — 전환일 이후 전표 + 기초잔액
$params = [];
$w = entity_where('v.business_entity_id', $params);
$st = db()->prepare(
    "SELECT COALESCE(SUM(v.balance_total),0) AS bal,
            COUNT(CASE WHEN v.balance_total > 0 THEN 1 END) AS comp_cnt,
            COALESCE(SUM(v.age_over90),0) AS over90
       FROM v_company_receivable v WHERE $w");
$st->execute($params);
$ar = $st->fetch();

// 미지급금 — 전환일 이후 매입 + 기초 미지급
$params = [];
$w = entity_where('p.business_entity_id', $params);
$st = db()->prepare(
    "SELECT COALESCE(SUM(p.balance),0) AS bal, COUNT(CASE WHEN p.balance > 0 THEN 1 END) AS cnt
       FROM v_purchase_payable p WHERE $w AND p.pay_status NOT IN ('NONE','OPENING')");
$st->execute($params);
$ap = $st->fetch();
try {
    $params = [];
    $w = entity_where('o.business_entity_id', $params);
    $st = db()->prepare(
        "SELECT COALESCE(SUM(o.remaining),0) AS bal, COUNT(CASE WHEN o.remaining > 0 THEN 1 END) AS cnt
           FROM v_opening_balance o
          WHERE $w AND o.balance_type = 'AP' AND o.status = 'CONFIRMED'");
    $st->execute($params);
    $apOb = $st->fetch();
    $ap['bal'] = (float)$ap['bal'] + (float)$apOb['bal'];
    $ap['cnt'] = (int)$ap['cnt'] + (int)$apOb['cnt'];
} catch (PDOException $e) {
    // 19번을 아직 안 돌렸을 수 있습니다
}

// 계좌 잔액
$params = [];
$w = entity_where('v.business_entity_id', $params);
$st = db()->prepare("SELECT v.* FROM v_account_balance v
                      WHERE $w AND v.is_active = 1
                      ORDER BY v.business_entity_id, v.bank_account_id");
$st->execute($params);
$accts = $st->fetchAll();
$cash = 0.0;
foreach ($accts as $a) { $cash += (float)$a['balance']; }

// 최근 입출금
$params = [];
$w = entity_where('f.business_entity_id', $params);
$st = db()->prepare(
    "SELECT f.*, c.name_ko AS company_name, ec.name AS category_name
       FROM financial_transactions f
       LEFT JOIN companies c ON c.id = f.company_id
       LEFT JOIN expense_categories ec ON ec.id = f.category_id
      WHERE $w AND f.status = 'CONFIRMED'
      ORDER BY f.txn_date DESC, f.id DESC LIMIT 12");
$st->execute($params);
$recent = $st->fetchAll();

// 미수 TOP 10
$params = [];
$w = entity_where('v.business_entity_id', $params);
$st = db()->prepare(
    "SELECT v.*, c.name_ko FROM v_company_receivable v
       JOIN companies c ON c.id = v.company_id
      WHERE $w AND v.balance_total > 0
      ORDER BY v.balance_total DESC LIMIT 10");
$st->execute($params);
$top = $st->fetchAll();

// 장기미수 (90일 초과)
$params = [];
$w = entity_where('r.business_entity_id', $params);
$st = db()->prepare(
    "SELECT r.*, c.name_ko FROM v_shipment_receivable r
       JOIN companies c ON c.id = r.company_id
      WHERE $w AND r.balance > 0 AND r.age_days > 90
      ORDER BY r.age_days DESC LIMIT 10");
$st->execute($params);
$longAr = $st->fetchAll();

// 최근 6개월 추이
$params = [];
$w = entity_where('f.business_entity_id', $params);
$st = db()->prepare(
    "SELECT DATE_FORMAT(f.txn_date, '%Y-%m') AS ym,
            COALESCE(SUM(CASE WHEN f.txn_type='IN'  THEN f.amount END),0) AS in_sum,
            COALESCE(SUM(CASE WHEN f.txn_type='OUT' THEN f.amount END),0) AS out_sum
       FROM financial_transactions f
      WHERE $w AND f.status='CONFIRMED' AND f.txn_type <> 'TRANSFER'
        AND f.txn_date >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
      GROUP BY ym ORDER BY ym");
$st->execute($params);
$trend = $st->fetchAll();
$peak = 1.0;
foreach ($trend as $t) { $peak = max($peak, (float)$t['in_sum'], (float)$t['out_sum']); }

$unmatched = 0;
try {
    $params = [];
    $w = entity_where('business_entity_id', $params);
    $st = db()->prepare("SELECT COUNT(*) FROM bank_import_rows
                          WHERE $w AND match_status IN ('UNMATCHED','SUGGESTED')");
    $st->execute($params);
    $unmatched = (int)$st->fetchColumn();
} catch (PDOException $e) {
    // 아직 테이블이 없을 수 있습니다
}

layout_head('입출금 현황', 'cash_dashboard');
?>
<div class="head">
  <h1>입출금 현황</h1>
  <div class="crumb">입출금관리 &gt; 입출금 현황 · <?= h(entity_label()) ?></div>
</div>

<?php cutover_warning(); ?>

<?php if ($unmatched > 0): ?>
<div class="msg" style="background:var(--warn-bg);color:var(--warn-fg)">
  은행에서 가져온 거래 중 <b><?= money($unmatched) ?>건</b>이 아직 처리되지 않았습니다.
  <a href="?p=bank_import">확인하러 가기</a>
</div>
<?php endif; ?>

<div class="kpis">
  <div class="kpi"><div class="lab">오늘 입금</div>
    <div class="val tnum" style="color:#1B7F5A"><?= money($d['in_sum']) ?></div>
    <div class="sub"><?= money($d['in_cnt']) ?>건 · <?= h($today) ?></div></div>
  <div class="kpi"><div class="lab">오늘 출금</div>
    <div class="val tnum" style="color:#B3261E"><?= money($d['out_sum']) ?></div>
    <div class="sub"><?= money($d['out_cnt']) ?>건</div></div>
  <div class="kpi"><div class="lab">현재 총 미수금</div>
    <div class="val tnum" style="color:var(--err-fg)"><?= money($ar['bal']) ?></div>
    <div class="sub">거래처 <?= money($ar['comp_cnt']) ?>곳</div></div>
  <div class="kpi"><div class="lab">현재 미지급금</div>
    <div class="val tnum"><?= money($ap['bal']) ?></div>
    <div class="sub"><?= money($ap['cnt']) ?>건 · 운송사 등에 줄 돈</div></div>
</div>

<div class="kpis">
  <div class="kpi"><div class="lab">이번달 매출</div>
    <div class="val tnum"><?= money($mSales['sales']) ?></div>
    <div class="sub">전표 <?= money($mSales['cnt']) ?>건</div></div>
  <div class="kpi"><div class="lab">이번달 입금</div>
    <div class="val tnum" style="color:#1B7F5A"><?= money($m['in_sum']) ?></div>
    <div class="sub"><?= (float)$mSales['sales'] > 0
        ? '매출 대비 ' . number_format((float)$m['in_sum'] / (float)$mSales['sales'] * 100, 0) . '%'
        : '-' ?></div></div>
  <div class="kpi"><div class="lab">이번달 지출</div>
    <div class="val tnum" style="color:#B3261E"><?= money($m['out_sum']) ?></div>
    <div class="sub">계좌이체 제외</div></div>
  <div class="kpi"><div class="lab">계좌 잔액 합계</div>
    <div class="val tnum"><?= money($cash) ?></div>
    <div class="sub">사용중 계좌 <?= count($accts) ?>개</div></div>
</div>

<?php if ($trend): ?>
<div class="card">
  <div class="ch">최근 6개월 입금 · 출금
    <span style="font-weight:400;color:var(--ink3)">계좌이체 제외</span>
    <span style="margin-left:auto;font-weight:400;font-size:11.5px">
      <span style="display:inline-block;width:10px;height:10px;background:#2a78d6;border-radius:2px"></span> 입금
      &nbsp;<span style="display:inline-block;width:10px;height:10px;background:#eb6834;border-radius:2px"></span> 출금
    </span>
  </div>
  <div class="cb">
    <table style="width:100%;border:none">
      <tbody>
      <?php foreach ($trend as $t):
        $iw = (float)$t['in_sum']  / $peak * 100;
        $ow = (float)$t['out_sum'] / $peak * 100; ?>
        <tr style="border:none">
          <td class="tnum" style="width:80px;border:none;font-weight:600"><?= h($t['ym']) ?></td>
          <td style="border:none;padding:4px 8px">
            <div style="display:flex;align-items:center;gap:8px;margin-bottom:3px">
              <div style="height:14px;border-radius:0 3px 3px 0;background:#2a78d6;width:<?= $iw ?>%"></div>
              <span class="tnum" style="font-size:11.5px;white-space:nowrap"><?= money($t['in_sum']) ?></span>
            </div>
            <div style="display:flex;align-items:center;gap:8px">
              <div style="height:14px;border-radius:0 3px 3px 0;background:#eb6834;width:<?= $ow ?>%"></div>
              <span class="tnum" style="font-size:11.5px;white-space:nowrap"><?= money($t['out_sum']) ?></span>
            </div>
          </td>
          <td class="r tnum" style="width:150px;border:none;font-weight:700;color:<?=
                (float)$t['in_sum'] >= (float)$t['out_sum'] ? '#1B7F5A' : '#B3261E' ?>">
            <?= money((float)$t['in_sum'] - (float)$t['out_sum']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<div class="card">
  <div class="ch">계좌 잔액
    <a class="btn sm" style="margin-left:auto" href="?p=accounts">계좌 관리</a>
  </div>
  <?php if (!$accts): ?>
    <div class="empty">등록된 계좌가 없습니다. <a href="?p=accounts">계좌 관리</a>에서 넣으세요.</div>
  <?php else: ?>
  <table>
    <thead><tr><th style="width:120px">은행</th><th>계좌번호</th>
      <th class="r" style="width:140px">입금계</th><th class="r" style="width:140px">출금계</th>
      <th class="r" style="width:150px">잔액</th><th style="width:110px">최종거래</th></tr></thead>
    <tbody>
    <?php foreach ($accts as $a): ?>
      <tr>
        <td style="font-weight:600"><?= h($a['bank_name']) ?></td>
        <td class="tnum"><?= h($a['account_no']) ?>
          <span style="color:var(--ink3);font-size:11px"><?= h($a['account_holder']) ?></span></td>
        <td class="r tnum" style="color:#1B7F5A"><?= money($a['in_total']) ?></td>
        <td class="r tnum" style="color:#B3261E"><?= money($a['out_total']) ?></td>
        <td class="r tnum" style="font-weight:700"><?= money($a['balance']) ?></td>
        <td class="tnum" style="font-size:11.5px"><?= h($a['last_txn_date'] && $a['last_txn_date'] > '1901-01-01' ? $a['last_txn_date'] : '-') ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>

<div class="card">
  <div class="ch">최근 입출금
    <a class="btn sm" style="margin-left:auto" href="?p=cash_list">전체 보기</a>
  </div>
  <?php if (!$recent): ?>
    <div class="empty">아직 등록된 입출금이 없습니다.</div>
  <?php else: ?>
  <table>
    <thead><tr><th style="width:105px">일자</th><th class="c" style="width:80px">유형</th>
      <th>거래처 / 상대</th><th style="width:120px">분류 · 적요</th>
      <th class="r" style="width:150px">금액</th><th class="c" style="width:55px"></th></tr></thead>
    <tbody>
    <?php foreach ($recent as $r): ?>
      <tr>
        <td class="tnum"><?= h($r['txn_date']) ?></td>
        <td class="c"><span class="badge <?= $r['txn_type']==='IN'?'b-ok':($r['txn_type']==='OUT'?'b-err':'b-info') ?>">
          <?= h(txn_type_label($r['txn_type'])) ?></span></td>
        <td><?= h($r['company_name'] ?: $r['counterparty'] ?: '-') ?></td>
        <td style="font-size:11.5px;color:var(--ink2)">
          <?= h($r['category_name'] ?: $r['summary'] ?: '-') ?></td>
        <td class="r tnum" style="font-weight:700;color:<?=
              $r['txn_type']==='IN' ? '#1B7F5A' : ($r['txn_type']==='OUT' ? '#B3261E' : 'var(--ink2)') ?>">
          <?= $r['txn_type']==='OUT' ? '−' : '' ?><?= money($r['amount']) ?></td>
        <td class="c"><a class="btn sm" href="?p=cash_list&amp;id=<?= (int)$r['id'] ?>">열기</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>

<div class="card">
  <div class="ch">미수금 TOP 10
    <a class="btn sm" style="margin-left:auto" href="?p=receivables">미수금 관리</a>
  </div>
  <?php if (!$top): ?>
    <div class="empty">미수금이 없습니다.</div>
  <?php else: ?>
  <table>
    <thead><tr><th class="c" style="width:45px">#</th><th>거래처</th>
      <th class="c" style="width:65px">전표</th>
      <th class="r" style="width:150px">미수금</th><th class="r" style="width:130px">90일 초과</th>
      <th style="width:110px">최종입금</th><th class="c" style="width:110px"></th></tr></thead>
    <tbody>
    <?php $i = 0; foreach ($top as $t): $i++; ?>
      <tr>
        <td class="c tnum" style="color:var(--ink3)"><?= $i ?></td>
        <td style="font-weight:600"><?= h($t['name_ko']) ?></td>
        <td class="c tnum"><?= money($t['shipment_cnt']) ?></td>
        <td class="r tnum" style="font-weight:700;color:var(--err-fg)"><?= money($t['balance_total']) ?></td>
        <td class="r tnum" style="font-size:11.5px;<?= (float)$t['age_over90'] > 0
              ? 'color:var(--err-fg);font-weight:700' : 'color:var(--ink3)' ?>">
          <?= money($t['age_over90']) ?></td>
        <td class="tnum" style="font-size:11.5px"><?= h($t['last_paid_at'] ?: '-') ?></td>
        <td class="c">
          <a class="btn sm" href="?p=ledger&amp;company_id=<?= (int)$t['company_id'] ?>">원장</a>
          <a class="btn sm pri" href="?p=cash_in&amp;company_id=<?= (int)$t['company_id'] ?>">입금</a>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>

<div class="card">
  <div class="ch">장기 미수 <span style="font-weight:400;color:var(--ink3)">90일 초과 · 오래된 순</span></div>
  <?php if (!$longAr): ?>
    <div class="empty">90일 넘은 미수가 없습니다.</div>
  <?php else: ?>
  <table>
    <thead><tr><th style="width:105px">전표일</th><th style="width:165px">AWB</th><th>거래처</th>
      <th class="r" style="width:150px">미수금</th><th class="c" style="width:90px">경과</th>
      <th class="c" style="width:60px"></th></tr></thead>
    <tbody>
    <?php foreach ($longAr as $r): ?>
      <tr>
        <td class="tnum"><?= h($r['voucher_date']) ?></td>
        <td class="tnum" style="font-weight:600"><?= h($r['awb_no']) ?></td>
        <td><?= h($r['name_ko']) ?></td>
        <td class="r tnum" style="font-weight:700;color:var(--err-fg)"><?= money($r['balance']) ?></td>
        <td class="c tnum" style="font-weight:700;color:var(--err-fg)"><?= (int)$r['age_days'] ?>일</td>
        <td class="c"><a class="btn sm"
              href="?p=cash_in&amp;company_id=<?= (int)$r['company_id'] ?>">입금</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <div class="pager"><span>
    <b>오래 묵을수록 회수율이 떨어집니다.</b> 90일이 넘으면 담당자가 직접 연락할 대상입니다.
  </span></div>
  <?php endif; ?>
</div>
<?php layout_foot();
