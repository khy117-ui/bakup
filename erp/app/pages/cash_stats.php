<?php
// 웹에서 직접 열면 실행되지 않게 막습니다 (nginx 면 .htaccess 가 무시됩니다)
if (!defined('APP_DIR')) { http_response_code(403); exit('Forbidden'); }

require APP_DIR . '/layout.php';

/**
 * 입출금 통계.
 *
 * **계좌이체는 전부 빠져 있습니다.** 회사 통장 사이의 이동은 수입도 지출도
 * 아니기 때문입니다 — 모든 쿼리에 txn_type <> 'TRANSFER' 가 붙어 있습니다.
 *
 * 사업자별로 나눠 보려면 상단에서 사업자를 고르면 됩니다. [전체]면 합산입니다.
 */

require_perm('CASH_VIEW', '입출금 통계');

$year = (int)query('year', date('Y'));
if ($year < 2000 || $year > 2100) { $year = (int)date('Y'); }
$from = query('from', $year . '-01-01');
$to   = query('to',   $year . '-12-31');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) { $from = $year . '-01-01'; }
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to))   { $to   = $year . '-12-31'; }

/** 공통 WHERE — 확정된 것, 이체 제외 */
function stat_where(array &$params, string $from, string $to): string
{
    $w = entity_where('f.business_entity_id', $params);
    array_push($params, $from, $to);
    return "$w AND f.status = 'CONFIRMED' AND f.txn_type <> 'TRANSFER'
            AND f.txn_date BETWEEN ? AND ?";
}

// 월별
$params = [];
$w = stat_where($params, $from, $to);
$st = db()->prepare(
    "SELECT DATE_FORMAT(f.txn_date, '%Y-%m') AS ym,
            COALESCE(SUM(CASE WHEN f.txn_type='IN'  THEN f.amount END),0) AS in_sum,
            COALESCE(SUM(CASE WHEN f.txn_type='OUT' THEN f.amount END),0) AS out_sum,
            COUNT(CASE WHEN f.txn_type='IN'  THEN 1 END) AS in_cnt,
            COUNT(CASE WHEN f.txn_type='OUT' THEN 1 END) AS out_cnt
       FROM financial_transactions f
      WHERE $w GROUP BY ym ORDER BY ym");
$st->execute($params);
$months = $st->fetchAll();

// 비용분류별
$params = [];
$w = stat_where($params, $from, $to);
$st = db()->prepare(
    "SELECT COALESCE(ec.name, '(분류 없음)') AS name, ec.is_cogs,
            SUM(f.amount) AS total, COUNT(*) AS cnt
       FROM financial_transactions f
       LEFT JOIN expense_categories ec ON ec.id = f.category_id
      WHERE $w AND f.txn_type = 'OUT'
      GROUP BY ec.id, ec.name, ec.is_cogs ORDER BY total DESC");
$st->execute($params);
$byCat = $st->fetchAll();

// 거래처별 입금
$params = [];
$w = stat_where($params, $from, $to);
$st = db()->prepare(
    "SELECT COALESCE(c.name_ko, f.counterparty, '(미지정)') AS name,
            SUM(f.amount) AS total, COUNT(*) AS cnt
       FROM financial_transactions f
       LEFT JOIN companies c ON c.id = f.company_id
      WHERE $w AND f.txn_type = 'IN'
      GROUP BY c.id, name ORDER BY total DESC LIMIT 20");
$st->execute($params);
$byComp = $st->fetchAll();

// 결제수단별
$params = [];
$w = stat_where($params, $from, $to);
$st = db()->prepare(
    "SELECT f.txn_type, f.method, SUM(f.amount) AS total, COUNT(*) AS cnt
       FROM financial_transactions f
      WHERE $w GROUP BY f.txn_type, f.method ORDER BY total DESC");
$st->execute($params);
$byMethod = $st->fetchAll();

// 사업자별 — [전체]일 때 의미가 있습니다
$params = [];
$w = stat_where($params, $from, $to);
$st = db()->prepare(
    "SELECT e.name_ko,
            COALESCE(SUM(CASE WHEN f.txn_type='IN'  THEN f.amount END),0) AS in_sum,
            COALESCE(SUM(CASE WHEN f.txn_type='OUT' THEN f.amount END),0) AS out_sum
       FROM financial_transactions f
       JOIN business_entities e ON e.id = f.business_entity_id
      WHERE $w GROUP BY e.id, e.name_ko ORDER BY e.id");
$st->execute($params);
$byEntity = $st->fetchAll();

// 이체 (참고용 — 통계에서 빠졌다는 것을 보여주기 위해)
$params = [];
$w = entity_where('f.business_entity_id', $params);
array_push($params, $from, $to);
$st = db()->prepare(
    "SELECT COALESCE(SUM(f.amount),0) AS amt, COALESCE(SUM(f.fee),0) AS fee, COUNT(*) AS cnt
       FROM financial_transactions f
      WHERE $w AND f.status='CONFIRMED' AND f.txn_type='TRANSFER'
        AND f.txn_date BETWEEN ? AND ?");
$st->execute($params);
$tr = $st->fetch();

$inTotal = 0.0; $outTotal = 0.0; $peak = 1.0;
foreach ($months as $m) {
    $inTotal  += (float)$m['in_sum'];
    $outTotal += (float)$m['out_sum'];
    $peak = max($peak, (float)$m['in_sum'], (float)$m['out_sum']);
}
$catPeak = 1.0;
foreach ($byCat as $c) { $catPeak = max($catPeak, (float)$c['total']); }

layout_head('입출금 통계', 'cash_stats');
?>
<div class="head">
  <h1>입출금 통계</h1>
  <div class="crumb">입출금관리 &gt; 입출금 통계 · <?= h(entity_label()) ?></div>
</div>

<div class="card"><div class="cb">
  <form class="f" method="get" style="align-items:flex-end">
    <input type="hidden" name="p" value="cash_stats">
    <div class="fw w1"><label for="f">시작일</label>
      <input type="date" id="f" name="from" value="<?= h($from) ?>"></div>
    <div class="fw w1"><label for="t">종료일</label>
      <input type="date" id="t" name="to" value="<?= h($to) ?>"></div>
    <button class="btn">조회</button>
    <?php for ($y = (int)date('Y'); $y >= (int)date('Y') - 3; $y--): ?>
      <a class="btn sm" href="?p=cash_stats&amp;from=<?= $y ?>-01-01&amp;to=<?= $y ?>-12-31"><?= $y ?></a>
    <?php endfor; ?>
  </form>
</div></div>

<div class="kpis">
  <div class="kpi"><div class="lab">입금 합계</div>
    <div class="val tnum" style="color:#1B7F5A"><?= money($inTotal) ?></div>
    <div class="sub"><?= h($from) ?> ~ <?= h($to) ?></div></div>
  <div class="kpi"><div class="lab">출금 합계</div>
    <div class="val tnum" style="color:#B3261E"><?= money($outTotal) ?></div>
    <div class="sub">계좌이체 제외</div></div>
  <div class="kpi"><div class="lab">순현금흐름</div>
    <div class="val tnum" style="color:<?= $inTotal >= $outTotal ? '#1B7F5A' : '#B3261E' ?>">
      <?= money($inTotal - $outTotal) ?></div>
    <div class="sub">입금 − 출금</div></div>
  <div class="kpi"><div class="lab">계좌이체</div>
    <div class="val tnum" style="color:var(--ink2)"><?= money($tr['amt']) ?></div>
    <div class="sub"><?= money($tr['cnt']) ?>건 · <b>위 숫자에 안 들어감</b></div></div>
</div>

<div class="card">
  <div class="ch">월별 입금 · 출금
    <span style="margin-left:auto;font-weight:400;font-size:11.5px">
      <span style="display:inline-block;width:10px;height:10px;background:#2a78d6;border-radius:2px"></span> 입금
      &nbsp;<span style="display:inline-block;width:10px;height:10px;background:#eb6834;border-radius:2px"></span> 출금
    </span>
  </div>
  <?php if (!$months): ?>
    <div class="empty">이 기간에 입출금이 없습니다.</div>
  <?php else: ?>
  <table>
    <thead><tr><th style="width:90px">월</th><th>추이</th>
      <th class="r" style="width:145px">입금</th><th class="r" style="width:145px">출금</th>
      <th class="r" style="width:150px">차액</th><th class="c" style="width:90px">건수</th></tr></thead>
    <tbody>
    <?php foreach ($months as $m):
      $iw = (float)$m['in_sum'] / $peak * 100;
      $ow = (float)$m['out_sum'] / $peak * 100;
      $diff = (float)$m['in_sum'] - (float)$m['out_sum']; ?>
      <tr>
        <td class="tnum" style="font-weight:600"><?= h($m['ym']) ?></td>
        <td style="padding:5px 8px">
          <div style="height:12px;border-radius:0 3px 3px 0;background:#2a78d6;width:<?= $iw ?>%;margin-bottom:3px"></div>
          <div style="height:12px;border-radius:0 3px 3px 0;background:#eb6834;width:<?= $ow ?>%"></div>
        </td>
        <td class="r tnum" style="color:#1B7F5A"><?= money($m['in_sum']) ?></td>
        <td class="r tnum" style="color:#B3261E"><?= money($m['out_sum']) ?></td>
        <td class="r tnum" style="font-weight:700;color:<?= $diff >= 0 ? '#1B7F5A' : '#B3261E' ?>">
          <?= money($diff) ?></td>
        <td class="c tnum" style="font-size:11.5px;color:var(--ink2)">
          <?= (int)$m['in_cnt'] ?> / <?= (int)$m['out_cnt'] ?></td>
      </tr>
    <?php endforeach; ?>
      <tr style="background:#F2F7FB">
        <td style="font-weight:700">합계</td><td></td>
        <td class="r tnum" style="font-weight:700"><?= money($inTotal) ?></td>
        <td class="r tnum" style="font-weight:700"><?= money($outTotal) ?></td>
        <td class="r tnum" style="font-weight:700"><?= money($inTotal - $outTotal) ?></td>
        <td></td>
      </tr>
    </tbody>
  </table>
  <?php endif; ?>
</div>

<div class="card">
  <div class="ch">비용분류별 지출 <span style="font-weight:400;color:var(--ink3)">많은 순</span></div>
  <?php if (!$byCat): ?>
    <div class="empty">이 기간에 출금이 없습니다.</div>
  <?php else: ?>
  <table>
    <thead><tr><th style="width:170px">분류</th><th class="c" style="width:90px">구분</th>
      <th>비중</th><th class="r" style="width:150px">금액</th>
      <th class="r" style="width:80px">비율</th><th class="c" style="width:70px">건수</th></tr></thead>
    <tbody>
    <?php foreach ($byCat as $c):
      $pct = $outTotal > 0 ? (float)$c['total'] / $outTotal * 100 : 0; ?>
      <tr>
        <td style="font-weight:600"><?= h($c['name']) ?></td>
        <td class="c"><?= (int)$c['is_cogs']
             ? '<span class="badge b-warn">매출원가</span>'
             : '<span class="badge b-info">판관비</span>' ?></td>
        <td style="padding:5px 8px">
          <div style="height:14px;border-radius:0 3px 3px 0;background:#eb6834;width:<?=
            (float)$c['total'] / $catPeak * 100 ?>%"></div></td>
        <td class="r tnum" style="font-weight:600"><?= money($c['total']) ?></td>
        <td class="r tnum" style="color:var(--ink2)"><?= number_format($pct, 1) ?>%</td>
        <td class="c tnum" style="color:var(--ink2)"><?= money($c['cnt']) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>

<?php if (count($byEntity) > 1): ?>
<div class="card">
  <div class="ch">사업자별 <span style="font-weight:400;color:var(--ink3)">두 법인 비교</span></div>
  <table>
    <thead><tr><th>사업자</th>
      <th class="r" style="width:170px">입금</th><th class="r" style="width:170px">출금</th>
      <th class="r" style="width:170px">차액</th></tr></thead>
    <tbody>
    <?php foreach ($byEntity as $e): $d = (float)$e['in_sum'] - (float)$e['out_sum']; ?>
      <tr>
        <td style="font-weight:600"><?= h($e['name_ko']) ?></td>
        <td class="r tnum" style="color:#1B7F5A"><?= money($e['in_sum']) ?></td>
        <td class="r tnum" style="color:#B3261E"><?= money($e['out_sum']) ?></td>
        <td class="r tnum" style="font-weight:700;color:<?= $d >= 0 ? '#1B7F5A' : '#B3261E' ?>">
          <?= money($d) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<div class="card">
  <div class="ch">거래처별 입금 <span style="font-weight:400;color:var(--ink3)">상위 20</span></div>
  <?php if (!$byComp): ?>
    <div class="empty">이 기간에 입금이 없습니다.</div>
  <?php else: ?>
  <table>
    <thead><tr><th class="c" style="width:45px">#</th><th>거래처</th>
      <th class="r" style="width:160px">입금액</th><th class="r" style="width:80px">비중</th>
      <th class="c" style="width:70px">건수</th></tr></thead>
    <tbody>
    <?php $i = 0; foreach ($byComp as $c): $i++;
      $pct = $inTotal > 0 ? (float)$c['total'] / $inTotal * 100 : 0; ?>
      <tr>
        <td class="c tnum" style="color:var(--ink3)"><?= $i ?></td>
        <td style="font-weight:600"><?= h($c['name']) ?></td>
        <td class="r tnum"><?= money($c['total']) ?></td>
        <td class="r tnum" style="color:var(--ink2)"><?= number_format($pct, 1) ?>%</td>
        <td class="c tnum" style="color:var(--ink2)"><?= money($c['cnt']) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>

<div class="card">
  <div class="ch">결제수단별</div>
  <?php if (!$byMethod): ?>
    <div class="empty">자료가 없습니다.</div>
  <?php else: ?>
  <table>
    <thead><tr><th class="c" style="width:90px">유형</th><th style="width:150px">수단</th>
      <th class="r" style="width:170px">금액</th><th class="c" style="width:90px">건수</th></tr></thead>
    <tbody>
    <?php $M = ['TRANSFER'=>'계좌이체','CARD'=>'카드','CASH'=>'현금','NOTE'=>'어음','PG'=>'PG'];
    foreach ($byMethod as $m): ?>
      <tr>
        <td class="c"><span class="badge <?= $m['txn_type']==='IN'?'b-ok':'b-err' ?>">
          <?= h(txn_type_label($m['txn_type'])) ?></span></td>
        <td><?= h($M[$m['method']] ?? $m['method']) ?></td>
        <td class="r tnum" style="font-weight:600"><?= money($m['total']) ?></td>
        <td class="c tnum" style="color:var(--ink2)"><?= money($m['cnt']) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>

<div class="card">
  <div class="ch">이 통계에서 빠진 것</div>
  <div class="cb" style="font-size:12px;color:var(--ink2);line-height:1.9">
    · <b>계좌이체 <?= money($tr['amt']) ?>원 (<?= money($tr['cnt']) ?>건)</b> 은 위 숫자에
      들어가 있지 않습니다. 회사 통장 사이의 이동이라 수입도 지출도 아닙니다.
      <b>이체수수료 <?= money($tr['fee']) ?>원</b>만 실제 비용입니다.<br>
    · <b>취소된 거래</b>도 빠져 있습니다. 취소 내역은
      <a href="?p=cash_list&amp;status=CANCELLED">입출금 내역</a>에서 볼 수 있습니다.<br>
    · 여기는 <b>실제로 돈이 오간 것</b>만 셉니다. 매출은 잡혔지만 아직 안 들어온 돈은
      <a href="?p=receivables">미수금 관리</a>에서 보세요.
  </div>
</div>
<?php layout_foot();
