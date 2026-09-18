<?php
// 웹에서 직접 열면 실행되지 않게 막습니다 (nginx 면 .htaccess 가 무시됩니다)
if (!defined('APP_DIR')) { http_response_code(403); exit('Forbidden'); }

require_once APP_DIR . '/layout.php';

/**
 * 미수금 관리.
 *
 *   미수금 = 매출금액 − 배분된 입금액
 *   상태   = 미입금 / 부분입금 / 입금완료   (자동)
 *
 * 저장하지 않고 볼 때 계산합니다. 입금을 넣거나 취소하면 저절로 맞습니다.
 * 연령분석(30/60/90일)이 같이 나옵니다 — 오래 묵은 돈일수록 못 받습니다.
 */

require_perm('CASH_VIEW', '미수금 조회');

$view    = query('view', 'company');       // company | shipment
$kw      = query('kw');
$st_     = query('status');
$aging   = query('aging');
$minAmt  = (float)str_replace(',', '', query('min'));
$compId  = (int)query('company_id', '0');
$page    = max(1, (int)query('page', '1'));
$per     = 40;
$off     = ($page - 1) * $per;

// ---------------------------------------------------------------- 전체 요약
// 거래처별 뷰에서 셉니다 — 전환일 이후 전표 미수 + 기초 미수 잔액이 같이 들어 있습니다
$params = [];
$w = entity_where('v.business_entity_id', $params);
$st = db()->prepare(
    "SELECT COALESCE(SUM(v.balance_total),0) AS bal,
            COALESCE(SUM(v.sales_total),0)   AS sales,
            COALESCE(SUM(v.paid_total),0)    AS paid,
            COUNT(CASE WHEN v.balance_total > 0 THEN 1 END) AS comp_cnt,
            COALESCE(SUM(v.unpaid_cnt + v.partial_cnt),0)   AS ship_cnt,
            COALESCE(SUM(v.age_over90),0)    AS over90,
            COALESCE(SUM(v.opening_total),0) AS opening
       FROM v_company_receivable v
      WHERE $w");
$st->execute($params);
$sum = $st->fetch();

layout_head('미수금 관리', 'receivables');
?>
<div class="head">
  <h1>미수금 관리</h1>
  <div class="crumb">입출금관리 &gt; 미수금 관리 · <?= h(entity_label()) ?></div>
</div>

<?php cutover_warning(); ?>

<div class="kpis">
  <div class="kpi"><div class="lab">총 미수금</div>
    <div class="val tnum" style="color:var(--err-fg)"><?= money($sum['bal']) ?></div>
    <div class="sub"><?= (float)$sum['opening'] > 0
        ? '그중 기초잔액 ' . money($sum['opening'])
        : '매출 ' . money($sum['sales']) . ' · 수금 ' . money($sum['paid']) ?></div></div>
  <div class="kpi"><div class="lab">미수 거래처</div>
    <div class="val tnum"><?= money($sum['comp_cnt']) ?></div>
    <div class="sub">미수 항목 <?= money($sum['ship_cnt']) ?>건</div></div>
  <div class="kpi"><div class="lab">90일 초과</div>
    <div class="val tnum" style="color:<?= (float)$sum['over90'] > 0 ? 'var(--err-fg)' : 'var(--ink)' ?>">
      <?= money($sum['over90']) ?></div>
    <div class="sub">먼저 챙겨야 하는 돈</div></div>
  <div class="kpi"><div class="lab">수금률</div>
    <div class="val tnum"><?= (float)$sum['sales'] > 0
        ? number_format((float)$sum['paid'] / (float)$sum['sales'] * 100, 1) . '%' : '-' ?></div>
    <div class="sub">수금 ÷ 매출</div></div>
</div>

<div class="card"><div class="cb">
  <form class="f" method="get" style="align-items:flex-end">
    <input type="hidden" name="p" value="receivables">
    <input type="hidden" name="view" value="<?= h($view) ?>">
    <div class="fw w2"><label for="kw">거래처 / AWB</label>
      <input type="text" id="kw" name="kw" value="<?= h($kw) ?>"></div>
    <?php if ($view === 'shipment'): ?>
    <div class="fw w1"><label for="ss">상태</label>
      <select id="ss" name="status">
        <option value="">전체</option>
        <option value="UNPAID"<?= $st_==='UNPAID'?' selected':'' ?>>미입금</option>
        <option value="PARTIAL"<?= $st_==='PARTIAL'?' selected':'' ?>>부분입금</option>
        <option value="PAID"<?= $st_==='PAID'?' selected':'' ?>>입금완료</option>
      </select></div>
    <div class="fw w1"><label for="ag">경과</label>
      <select id="ag" name="aging">
        <option value="">전체</option>
        <option value="30"<?= $aging==='30'?' selected':'' ?>>30일 이내</option>
        <option value="60"<?= $aging==='60'?' selected':'' ?>>31~60일</option>
        <option value="90"<?= $aging==='90'?' selected':'' ?>>61~90일</option>
        <option value="over"<?= $aging==='over'?' selected':'' ?>>90일 초과</option>
      </select></div>
    <?php else: ?>
    <div class="fw w1"><label for="mn">미수 최소액</label>
      <input type="text" id="mn" name="min" class="tnum" style="text-align:right"
             value="<?= h(query('min')) ?>" placeholder="0"></div>
    <?php endif; ?>
    <button class="btn">검색</button>
    <a class="btn<?= $view==='company'?' pri':'' ?>" href="?p=receivables&amp;view=company">거래처별</a>
    <a class="btn<?= $view==='shipment'?' pri':'' ?>" href="?p=receivables&amp;view=shipment">전표별</a>
  </form>
</div></div>

<?php if ($view === 'company'):
  // -------------------------------------------------------------- 거래처별
  $params = [];
  $w = entity_where('v.business_entity_id', $params);
  $extra = '';
  if ($kw !== '')     { $extra .= ' AND c.name_ko LIKE ?'; $params[] = '%' . $kw . '%'; }
  if ($minAmt > 0)    { $extra .= ' AND v.balance_total >= ?'; $params[] = $minAmt; }

  $st = db()->prepare("SELECT COUNT(*) FROM v_company_receivable v
                         JOIN companies c ON c.id = v.company_id
                        WHERE $w AND v.balance_total <> 0 $extra");
  $st->execute($params);
  $total = (int)$st->fetchColumn();

  $st = db()->prepare(
      "SELECT v.*, c.name_ko, c.company_code, c.phone, e.name_ko AS entity_name
         FROM v_company_receivable v
         JOIN companies c ON c.id = v.company_id
         JOIN business_entities e ON e.id = v.business_entity_id
        WHERE $w AND v.balance_total <> 0 $extra
        ORDER BY v.balance_total DESC LIMIT $per OFFSET $off");
  $st->execute($params);
  $rows = $st->fetchAll();
?>
<div class="card">
  <div class="ch">거래처별 미수 <span style="font-weight:400;color:var(--ink3)">많은 순</span></div>
  <?php if (!$rows): ?>
    <div class="empty">미수금이 있는 거래처가 없습니다.</div>
  <?php else: ?>
  <table>
    <thead><tr>
      <th>거래처</th><th class="c" style="width:65px">전표</th>
      <th class="r" style="width:125px">기초잔액</th>
      <th class="r" style="width:135px">매출</th><th class="r" style="width:135px">수금</th>
      <th class="r" style="width:140px">미수</th>
      <th class="r" style="width:110px">~30일</th><th class="r" style="width:110px">31~60</th>
      <th class="r" style="width:110px">61~90</th><th class="r" style="width:115px">90일+</th>
      <th style="width:100px">최종입금</th><th class="c" style="width:110px"></th>
    </tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td style="font-weight:600"><?= h($r['name_ko']) ?>
          <div style="font-weight:400;font-size:11px;color:var(--ink3)">
            <?= h($r['company_code']) ?><?= entity_filter() === null
                 ? ' · ' . h($r['entity_name']) : '' ?></div></td>
        <td class="c tnum"><?= money($r['shipment_cnt']) ?></td>
        <td class="r tnum" style="color:<?= (float)$r['opening_total'] > 0 ? 'var(--warn-fg)' : 'var(--ink3)' ?>">
          <?= money($r['opening_total']) ?></td>
        <td class="r tnum"><?= money($r['sales_total']) ?></td>
        <td class="r tnum" style="color:var(--ink2)"><?= money($r['paid_total']) ?></td>
        <td class="r tnum" style="font-weight:700;color:var(--err-fg)"><?= money($r['balance_total']) ?></td>
        <td class="r tnum" style="font-size:11.5px"><?= money($r['age_030']) ?></td>
        <td class="r tnum" style="font-size:11.5px"><?= money($r['age_3160']) ?></td>
        <td class="r tnum" style="font-size:11.5px;color:var(--warn-fg)"><?= money($r['age_6190']) ?></td>
        <td class="r tnum" style="font-size:11.5px;font-weight:700;color:<?=
              (float)$r['age_over90'] > 0 ? 'var(--err-fg)' : 'var(--ink3)' ?>">
          <?= money($r['age_over90']) ?></td>
        <td class="tnum" style="font-size:11.5px"><?= h($r['last_paid_at'] ?: '-') ?></td>
        <td class="c">
          <a class="btn sm" href="?p=ledger&amp;company_id=<?= (int)$r['company_id'] ?>">원장</a>
          <a class="btn sm pri" href="?p=cash_in&amp;company_id=<?= (int)$r['company_id'] ?>">입금</a>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <div class="pager">
    <span>전체 <b class="tnum"><?= money($total) ?></b> 곳</span>
    <div class="right">
      <?php $qs = 'p=receivables&view=company&kw=' . urlencode($kw) . '&min=' . urlencode(query('min')); ?>
      <?php if ($page > 1): ?><a class="btn sm" href="?<?= h($qs) ?>&amp;page=<?= $page-1 ?>">이전</a><?php endif; ?>
      <?php if ($off + $per < $total): ?><a class="btn sm" href="?<?= h($qs) ?>&amp;page=<?= $page+1 ?>">다음</a><?php endif; ?>
    </div>
  </div>
  <?php endif; ?>
</div>

<?php else:
  // -------------------------------------------------------------- 전표별
  $params = [];
  $w = entity_where('r.business_entity_id', $params);
  $extra = " AND r.pay_status NOT IN ('CANCELLED','NONE')";
  if ($kw !== '') {
      $extra .= ' AND (c.name_ko LIKE ? OR r.awb_no LIKE ?)';
      $params[] = '%' . $kw . '%'; $params[] = '%' . $kw . '%';
  }
  if (in_array($st_, ['UNPAID','PARTIAL','PAID'], true)) {
      $extra .= ' AND r.pay_status = ?'; $params[] = $st_;
  } else {
      $extra .= ' AND r.balance > 0';
  }
  if ($aging === '30')        { $extra .= ' AND r.age_days <= 30'; }
  elseif ($aging === '60')    { $extra .= ' AND r.age_days BETWEEN 31 AND 60'; }
  elseif ($aging === '90')    { $extra .= ' AND r.age_days BETWEEN 61 AND 90'; }
  elseif ($aging === 'over')  { $extra .= ' AND r.age_days > 90'; }

  $st = db()->prepare("SELECT COUNT(*) FROM v_shipment_receivable r
                         JOIN companies c ON c.id = r.company_id WHERE $w $extra");
  $st->execute($params);
  $total = (int)$st->fetchColumn();

  $st = db()->prepare(
      "SELECT r.*, c.name_ko FROM v_shipment_receivable r
         JOIN companies c ON c.id = r.company_id
        WHERE $w $extra ORDER BY r.voucher_date, r.shipment_id LIMIT $per OFFSET $off");
  $st->execute($params);
  $rows = $st->fetchAll();
?>
<div class="card">
  <div class="ch">전표별 미수 <span style="font-weight:400;color:var(--ink3)">오래된 것부터</span></div>
  <?php if ((float)$sum['opening'] > 0): ?>
    <div class="cb" style="background:#FBF7EE;font-size:12px">
      전환일 이전 미수 <b class="tnum"><?= money($sum['opening']) ?></b>원은 전표가 아니라
      <b>기초잔액</b>으로 들고 있어서 이 목록에 없습니다.
      <a href="?p=receivables&amp;view=company">거래처별</a>이나
      <a href="?p=opening_balances&amp;only=open">기초잔액 관리</a>에서 보세요.
    </div>
  <?php endif; ?>
  <?php if (!$rows): ?>
    <div class="empty">조건에 맞는 전표가 없습니다.</div>
  <?php else: ?>
  <table>
    <thead><tr>
      <th style="width:105px">전표일</th><th style="width:165px">AWB</th><th>거래처</th>
      <th class="r" style="width:135px">매출금액</th><th class="r" style="width:135px">입금액</th>
      <th class="r" style="width:140px">미수금</th>
      <th class="c" style="width:85px">경과</th><th class="c" style="width:90px">상태</th>
      <th class="c" style="width:60px"></th>
    </tr></thead>
    <tbody>
    <?php foreach ($rows as $r): [$lab, $cls] = pay_status_badge($r['pay_status']);
      $age = (int)$r['age_days']; ?>
      <tr>
        <td class="tnum"><?= h($r['voucher_date']) ?></td>
        <td class="tnum" style="font-weight:600"><?= h($r['awb_no']) ?></td>
        <td><?= h($r['name_ko']) ?></td>
        <td class="r tnum"><?= money($r['sales_amount']) ?></td>
        <td class="r tnum" style="color:var(--ink2)"><?= money($r['paid_amount']) ?></td>
        <td class="r tnum" style="font-weight:700<?= (float)$r['balance'] > 0 ? ';color:var(--err-fg)' : '' ?>">
          <?= money($r['balance']) ?></td>
        <td class="c tnum" style="font-size:11.5px;<?= $age > 90 ? 'color:var(--err-fg);font-weight:700' : '' ?>">
          <?= $age ?>일</td>
        <td class="c"><span class="badge <?= $cls ?>"><?= h($lab) ?></span></td>
        <td class="c"><a class="btn sm"
              href="?p=cash_in&amp;company_id=<?= (int)$r['company_id'] ?>">입금</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <div class="pager">
    <span>전체 <b class="tnum"><?= money($total) ?></b> 건</span>
    <div class="right">
      <?php $qs = 'p=receivables&view=shipment&kw=' . urlencode($kw)
                . '&status=' . urlencode($st_) . '&aging=' . urlencode($aging); ?>
      <?php if ($page > 1): ?><a class="btn sm" href="?<?= h($qs) ?>&amp;page=<?= $page-1 ?>">이전</a><?php endif; ?>
      <?php if ($off + $per < $total): ?><a class="btn sm" href="?<?= h($qs) ?>&amp;page=<?= $page+1 ?>">다음</a><?php endif; ?>
    </div>
  </div>
  <?php endif; ?>
</div>
<?php endif; ?>

<div class="card">
  <div class="ch">이 숫자는 어떻게 나오나</div>
  <div class="cb" style="font-size:12px;color:var(--ink2);line-height:1.9">
    · <b>미수금 = 매출금액 − 배분된 입금액.</b> 어딘가에 저장해 두는 값이 아니라
      볼 때마다 다시 셉니다. 입금을 넣거나 취소하면 <b>즉시</b> 맞습니다.<br>
    · 상태는 자동입니다 — 입금이 하나도 없으면 <b>미입금</b>, 일부만 들어오면
      <b>부분입금</b>, 다 들어오면 <b>입금완료</b>.<br>
    · <b>선수금은 여기에 안 잡힙니다.</b> 전표에 배분하지 않은 입금은 미수를 줄이지 않습니다.
      <a href="?p=cash_list&amp;type=IN">입출금 내역</a>에서 미배분 금액을 확인하세요.<br>
    · <b>90일 초과</b>가 가장 중요한 숫자입니다. 오래 묵을수록 회수율이 떨어집니다.
  </div>
</div>
<?php layout_foot();
