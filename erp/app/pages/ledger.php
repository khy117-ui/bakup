<?php
// 웹에서 직접 열면 실행되지 않게 막습니다 (nginx 면 .htaccess 가 무시됩니다)
if (!defined('APP_DIR')) { http_response_code(403); exit('Forbidden'); }

require_once APP_DIR . '/layout.php';

/**
 * 거래처별 원장.
 *
 *   전월이월  ← 기간 시작일 **이전** 의 (매출 − 입금) 누계
 *   + 매출    차변
 *   − 입금    대변
 *   = 현재 미수금
 *
 * 누계는 뷰가 아니라 여기서 위에서부터 더해 내려갑니다. 전 거래처에 대해
 * 누계를 도는 뷰를 만들면 건수가 늘수록 급격히 느려집니다.
 *
 * 엑셀(CSV)과 인쇄를 지원합니다.
 */

require_perm('LEDGER_VIEW', '거래처원장 조회');

$compId = (int)query('company_id', '0');
$from   = query('from', date('Y-m-01'));
$to     = query('to', date('Y-m-d'));
$dl     = query('dl');

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) { $from = date('Y-m-01'); }
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to))   { $to   = date('Y-m-d'); }

$company = null; $rows = []; $carry = 0.0;
if ($compId > 0) {
    $st = db()->prepare('SELECT id, company_code, name_ko, phone, payment_terms
                           FROM companies WHERE id = ?');
    $st->execute([$compId]);
    $company = $st->fetch();
}

if ($company) {
    // 전월이월 — 기간 시작 전까지의 (차변 − 대변)
    $params = [];
    $w = entity_where('l.business_entity_id', $params);
    $params[] = $compId;
    $params[] = $from;
    $st = db()->prepare(
        "SELECT COALESCE(SUM(l.debit),0) - COALESCE(SUM(l.credit),0)
           FROM v_company_ledger l
          WHERE $w AND l.company_id = ? AND l.txn_date < ?");
    $st->execute($params);
    $carry = (float)$st->fetchColumn();

    // 기간 내역
    $params = [];
    $w = entity_where('l.business_entity_id', $params);
    $params[] = $compId;
    $params[] = $from;
    $params[] = $to;
    $st = db()->prepare(
        "SELECT l.* FROM v_company_ledger l
          WHERE $w AND l.company_id = ? AND l.txn_date BETWEEN ? AND ?
          ORDER BY l.txn_date, FIELD(l.txn_type, 'OPENING', 'SALES', 'REFUND', 'PAYMENT'), l.ref_id");
    $st->execute($params);
    $rows = $st->fetchAll();
}

// ---------------------------------------------------------------- 엑셀(CSV)
if ($dl === 'csv' && $company) {
    log_action('입출금', 'EXPORT', 'v_company_ledger', $compId,
               (string)$company['name_ko'], null, $from . ' ~ ' . $to);

    $file = '원장_' . preg_replace('/[^\p{L}\p{N}_-]+/u', '', (string)$company['name_ko'])
          . '_' . $from . '_' . $to . '.csv';
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename*=UTF-8\'\'' . rawurlencode($file));
    header('X-Content-Type-Options: nosniff');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");             // 엑셀이 UTF-8 로 읽게 하는 표식
    fputcsv($out, ['거래처', $company['name_ko'], '기간', $from . ' ~ ' . $to,
                   '사업자', entity_label()]);
    fputcsv($out, []);
    fputcsv($out, ['일자', '구분', '번호', '적요', '매출(차변)', '입금(대변)', '잔액']);
    $bal = $carry;
    fputcsv($out, ['', '전월이월', '', '', '', '', $carry]);
    foreach ($rows as $r) {
        $bal += (float)$r['debit'] - (float)$r['credit'];
        fputcsv($out, [
            $r['txn_date'],
            ['SALES' => '매출', 'PAYMENT' => '입금', 'REFUND' => '환불',
             'OPENING' => '기초잔액'][$r['txn_type']] ?? $r['txn_type'],
            $r['ref_no'], $r['summary'],
            (float)$r['debit']  ?: '',
            (float)$r['credit'] ?: '',
            $bal,
        ]);
    }
    fputcsv($out, ['', '합계', '', '',
                   array_sum(array_map(fn($x) => (float)$x['debit'], $rows)),
                   array_sum(array_map(fn($x) => (float)$x['credit'], $rows)),
                   $bal]);
    fclose($out);
    exit;
}

$companies = db()->query('SELECT id, company_code, name_ko FROM companies
                           WHERE deleted_at IS NULL ORDER BY name_ko')->fetchAll();

$debitSum = 0.0; $creditSum = 0.0;
foreach ($rows as $r) { $debitSum += (float)$r['debit']; $creditSum += (float)$r['credit']; }
$closing = $carry + $debitSum - $creditSum;

$TYPE = ['SALES' => ['매출', 'b-info'], 'PAYMENT' => ['입금', 'b-ok'], 'REFUND' => ['환불', 'b-warn'],
         'OPENING' => ['기초잔액', 'b-warn']];

layout_head('거래처별 원장', 'ledger');
?>
<style>
@media print {
  .side, .top, .noprint { display: none !important; }
  .main, .body { margin: 0 !important; padding: 0 !important; }
  .card { border: none !important; box-shadow: none !important; }
}
</style>

<div class="head noprint">
  <h1>거래처별 원장</h1>
  <div class="crumb">입출금관리 &gt; 거래처별 원장 · <?= h(entity_label()) ?></div>
</div>

<div class="card noprint"><div class="cb">
  <form class="f" method="get" style="align-items:flex-end">
    <input type="hidden" name="p" value="ledger">
    <div class="fw w2"><label for="c">거래처 *</label>
      <select id="c" name="company_id" onchange="this.form.submit()">
        <option value="">— 선택 —</option>
        <?php foreach ($companies as $c): ?>
          <option value="<?= (int)$c['id'] ?>"<?= $compId === (int)$c['id'] ? ' selected' : '' ?>>
            <?= h($c['name_ko']) ?> (<?= h($c['company_code']) ?>)</option>
        <?php endforeach; ?>
      </select></div>
    <div class="fw w1"><label for="f">시작일</label>
      <input type="date" id="f" name="from" value="<?= h($from) ?>"></div>
    <div class="fw w1"><label for="t">종료일</label>
      <input type="date" id="t" name="to" value="<?= h($to) ?>"></div>
    <button class="btn">조회</button>
    <?php if ($company): ?>
      <a class="btn" href="?p=ledger&amp;company_id=<?= $compId ?>&amp;from=<?= h($from)
         ?>&amp;to=<?= h($to) ?>&amp;dl=csv">엑셀 받기</a>
      <button type="button" class="btn" onclick="window.print()">인쇄</button>
    <?php endif; ?>
  </form>
</div></div>

<?php if (!$company): ?>
  <div class="card"><div class="empty">
    거래처를 고르세요. 그 거래처의 매출과 입금이 날짜순으로 나옵니다.
  </div></div>
<?php else: ?>

<div class="card">
  <div class="ch">
    <span style="font-size:15px"><?= h($company['name_ko']) ?></span>
    <span style="font-weight:400;color:var(--ink3)"><?= h($company['company_code']) ?></span>
    <span style="margin-left:auto;font-weight:400;font-size:12px">
      <?= h(entity_label()) ?> · <?= h($from) ?> ~ <?= h($to) ?></span>
  </div>
  <table>
    <thead><tr>
      <th style="width:110px">일자</th><th class="c" style="width:80px">구분</th>
      <th style="width:170px">번호</th><th>적요</th>
      <th class="r" style="width:145px">매출 (차변)</th>
      <th class="r" style="width:145px">입금 (대변)</th>
      <th class="r" style="width:155px">잔액</th>
    </tr></thead>
    <tbody>
      <tr style="background:#F7FAFB">
        <td colspan="4" style="font-weight:700">전월이월</td>
        <td></td><td></td>
        <td class="r tnum" style="font-weight:700"><?= money($carry) ?></td>
      </tr>
    <?php if (!$rows): ?>
      <tr><td colspan="7" class="empty">이 기간에 거래가 없습니다.</td></tr>
    <?php else: $bal = $carry; foreach ($rows as $r):
        $bal += (float)$r['debit'] - (float)$r['credit'];
        [$lab, $cls] = $TYPE[$r['txn_type']] ?? [$r['txn_type'], 'b-info']; ?>
      <tr>
        <td class="tnum"><?= h($r['txn_date']) ?></td>
        <td class="c"><span class="badge <?= $cls ?>"><?= h($lab) ?></span></td>
        <td class="tnum" style="font-size:11.5px"><?= h($r['ref_no']) ?></td>
        <td style="font-size:12px"><?= h($r['summary']) ?></td>
        <td class="r tnum"><?= (float)$r['debit']  > 0 ? money($r['debit'])  : '' ?></td>
        <td class="r tnum" style="color:#1B7F5A"><?=
          (float)$r['credit'] > 0 ? '−' . money($r['credit']) : '' ?></td>
        <td class="r tnum" style="font-weight:600"><?= money($bal) ?></td>
      </tr>
    <?php endforeach; endif; ?>
      <tr style="background:#F2F7FB;border-top:2px solid var(--line)">
        <td colspan="4" class="r" style="font-weight:700">기간 합계</td>
        <td class="r tnum" style="font-weight:700"><?= money($debitSum) ?></td>
        <td class="r tnum" style="font-weight:700;color:#1B7F5A">−<?= money($creditSum) ?></td>
        <td class="r tnum" style="font-weight:700;font-size:14px;color:<?=
              $closing > 0 ? 'var(--err-fg)' : 'var(--ink)' ?>"><?= money($closing) ?></td>
      </tr>
    </tbody>
  </table>
  <div class="pager"><span>
    <b>현재 미수금 <span class="tnum"><?= money($closing) ?></span>원</b>
    &nbsp;=&nbsp; 전월이월 <?= money($carry) ?>
    + 매출 <?= money($debitSum) ?> − 입금 <?= money($creditSum) ?>
  </span></div>
</div>

<div class="card noprint">
  <div class="ch">바로가기</div>
  <div class="cb" style="display:flex;gap:8px;flex-wrap:wrap">
    <a class="btn pri" href="?p=cash_in&amp;company_id=<?= $compId ?>">이 거래처 입금 등록</a>
    <a class="btn" href="?p=receivables&amp;view=shipment&amp;kw=<?= h(urlencode((string)$company['name_ko'])) ?>">
      미수 전표 보기</a>
    <a class="btn" href="?p=company_form&amp;id=<?= $compId ?>">거래처 정보</a>
    <a class="btn" href="?p=shipments&amp;kw=<?= h(urlencode((string)$company['name_ko'])) ?>">매출전표</a>
  </div>
  <div class="cb" style="border-top:1px solid var(--line);font-size:12px;color:var(--ink2);line-height:1.9">
    · <b>전월이월</b>은 시작일 <b>이전</b> 거래를 전부 더한 값입니다. 기간을 바꾸면 같이 바뀝니다.<br>
    · <b>잔액</b>은 위에서부터 더해 내려간 누계입니다. 마지막 줄이 현재 미수금입니다.<br>
    · 상단에서 <b>사업자</b>를 바꾸면 그 법인의 거래만 나옵니다. [전체]로 두면 두 법인이 합쳐집니다.<br>
    · 취소된 입금은 빠져 있습니다. 취소 이력은
      <a href="?p=cash_list">입출금 내역</a>에서 볼 수 있습니다.
  </div>
</div>
<?php endif; ?>
<?php layout_foot();
