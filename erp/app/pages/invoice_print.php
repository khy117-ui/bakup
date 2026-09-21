<?php
// 웹에서 직접 열면 실행되지 않게 막습니다 (nginx 면 .htaccess 가 무시됩니다)
if (!defined('APP_DIR')) { http_response_code(403); exit('Forbidden'); }
/**
 * 청구 인보이스 A4 출력.
 * 사이드바 없이 인쇄용 레이아웃만 씁니다. 브라우저 인쇄로 PDF 저장하면 됩니다.
 */
$eid = entity_id();
$id  = (int)query('id', '0');

$st = db()->prepare(
    'SELECT i.*, c.name_ko, c.name_en, c.company_code, c.business_number,
            c.representative, c.address_ko, c.address_en, c.phone, c.fax,
            c.payment_terms
       FROM invoices i
       JOIN companies c ON c.id = i.company_id
      WHERE i.id = ? AND i.business_entity_id = ? AND i.deleted_at IS NULL');
$st->execute([$id, $eid]);
$inv = $st->fetch();
if (!$inv) {
    exit('청구서를 찾을 수 없습니다.');
}

$st = db()->prepare('SELECT * FROM business_entities WHERE id = ?');
$st->execute([$eid]);
$be = $st->fetch();

$st = db()->prepare(
    'SELECT bank_name, account_no, account_holder FROM business_bank_accounts
      WHERE business_entity_id = ? AND is_active = 1 ORDER BY sort_order');
$st->execute([$eid]);
$banks = $st->fetchAll();

$st = db()->prepare(
    'SELECT xs.line_no, s.awb_no, s.voucher_date, s.trade_type, s.dest_city,
            s.charge_weight,
            COALESCE(t.zero_supply,0)    AS zero_supply,
            COALESCE(t.taxable_supply,0) AS taxable_supply,
            COALESCE(t.tax_total,0)      AS tax_total,
            COALESCE(t.grand_total,0)    AS grand_total
       FROM invoice_shipments xs
       JOIN shipments s ON s.id = xs.shipment_id
       LEFT JOIN v_shipment_totals t ON t.shipment_id = s.id
      WHERE xs.invoice_id = ? ORDER BY xs.line_no');
$st->execute([$id]);
$ships = $st->fetchAll();

$st = db()->prepare('SELECT * FROM invoice_items WHERE invoice_id = ? ORDER BY line_no');
$st->execute([$id]);
$items = $st->fetchAll();

// ---------------------------------------------------------------- 엑셀로 저장 (?xlsx=1)
if (query('xlsx') === '1') {
    require_once APP_DIR . '/xlsx.php';
    $num = fn($v) => (int)round((float)$v);
    $rows = [
        ['INVOICE', $inv['invoice_no']],
        ['DATE', $inv['invoice_date'], 'PERIOD', $inv['period_from'] . ' ~ ' . $inv['period_to'], 'DUE', (string)($inv['due_date'] ?? '')],
        [],
        ['공급자', $be['name_ko'], '사업자등록번호', $be['business_number'], '대표자', $be['representative']],
        ['주소', (string)$be['address_ko'], 'TEL', (string)$be['phone'], 'FAX', (string)$be['fax']],
        [],
        ['MESSRS', $inv['name_ko'], '사업자번호', (string)$inv['business_number'], '대표자', (string)$inv['representative']],
        ['ADDRESS', (string)$inv['address_ko'], '결제조건', (string)($inv['payment_terms'] ?? '')],
        [],
        ['NO', 'DATE', 'AWB NO', '구분', 'DESTINATION', 'WEIGHT', '영세율', '과세', 'VAT', 'AMOUNT'],
    ];
    foreach ($ships as $s) {
        $rows[] = [(int)$s['line_no'], $s['voucher_date'], $s['awb_no'], $s['trade_type'] === 'IMPORT' ? '수입' : '수출',
                   (string)$s['dest_city'], $s['charge_weight'] !== null ? (float)$s['charge_weight'] : '',
                   $num($s['zero_supply']), $num($s['taxable_supply']), $num($s['tax_total']), $num($s['grand_total'])];
    }
    foreach ($items as $it) {
        $rows[] = ['', '', $it['item_name'] . ($it['remark'] ? ' (' . $it['remark'] . ')' : ''), '', '', '',
                   $it['tax_type'] === 'ZERO' ? $num($it['supply_amount']) : '',
                   $it['tax_type'] === 'TAXABLE' ? $num($it['supply_amount']) : '',
                   $num($it['tax_amount']), $num($it['total_amount'])];
    }
    $pad = ['', '', '', '', '', '', '', ''];
    $rows[] = [];
    $rows[] = array_merge($pad, ['영세율 공급가액', $num($inv['zero_supply'])]);
    $rows[] = array_merge($pad, ['과세 공급가액', $num($inv['taxable_supply'])]);
    if ((float)$inv['exempt_supply'] != 0.0) {
        $rows[] = array_merge($pad, ['면세 공급가액', $num($inv['exempt_supply'])]);
    }
    $rows[] = array_merge($pad, ['부가세 (VAT)', $num($inv['tax_total'])]);
    $rows[] = array_merge($pad, ['합계 금액', $num($inv['grand_total'])]);
    if ((float)$inv['paid_amount'] > 0) {
        $rows[] = array_merge($pad, ['기수금', $num($inv['paid_amount'])]);
        $rows[] = array_merge($pad, ['미수 잔액', $num($inv['balance'])]);
    }
    $rows[] = [];
    $rows[] = ['입금계좌'];
    foreach ($banks as $b) {
        $rows[] = ['', $b['bank_name'] . ' ' . $b['account_no'] . ' 예금주 ' . $b['account_holder']];
    }
    if ($inv['remark']) { $rows[] = ['비고', (string)$inv['remark']]; }
    $bin = xlsx_build('INVOICE', $rows, [10, 16, 22, 10, 18, 10, 14, 14, 16, 16]);
    log_action('청구', 'EXPORT', 'invoices', $id, (string)$inv['invoice_no'], null, '엑셀 저장');
    $fn = 'INVOICE_' . $inv['invoice_no'] . '_' . $inv['name_ko'] . '.xlsx';
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="invoice.xlsx"; filename*=UTF-8\'\'' . rawurlencode($fn));
    header('Content-Length: ' . strlen($bin));
    header('Cache-Control: no-store');
    echo $bin;
    exit;
}

$stampUri = entity_stamp_data_uri($be ?: null);

log_action('청구', 'PRINT', 'invoices', $id, (string)$inv['invoice_no']);
?><!doctype html>
<html lang="ko">
<head>
<meta charset="utf-8">
<title>INVOICE <?= h($inv['invoice_no']) ?></title>
<style>
  /* PDF 저장 때 여백을 '없음' 으로 골라도 잘리지 않게 — 여백은 종이 쪽이 아니라 .sheet 안쪽 padding 으로 */
  @page { size: A4 landscape; margin: 0; }
  * { box-sizing: border-box; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
  body { margin: 0; background: #F1F5F8; color: #0C1A26;
         font-family: system-ui, -apple-system, "Segoe UI", "Malgun Gothic", sans-serif; }
  .sheet { width: 1123px; min-height: 794px; margin: 18px auto; background: #fff;
           padding: 34px 38px; }
  .tnum { font-variant-numeric: tabular-nums; }
  .bar { display: flex; align-items: center; gap: 10px; margin-bottom: 18px; }
  .bar .btn { height: 32px; padding: 0 14px; border: 1px solid #D3DEE6; background: #fff;
              border-radius: 5px; font-size: 12.5px; font-weight: 600; color: #34485A;
              cursor: pointer; text-decoration: none; display: inline-flex;
              align-items: center; }
  h1 { margin: 0; font-size: 27px; letter-spacing: .22em; font-weight: 700; }
  table { width: 100%; border-collapse: collapse; }
  th { font-size: 11px; font-weight: 700; color: #33485A; background: #EEF3F6;
       border-top: 1.5px solid #0C1A26; border-bottom: 1px solid #C9D6DF;
       padding: 7px 8px; text-align: left; }
  td { font-size: 11.5px; padding: 6px 8px; border-bottom: 1px solid #E7EDF1; }
  td.r, th.r { text-align: right; }
  td.c, th.c { text-align: center; }
  .sum td { border: 0; padding: 4px 8px; font-size: 12px; }
  .sum .lab { color: #4E6273; }
  .sum .big { font-size: 17px; font-weight: 700; }
  .box { border: 1px solid #C9D6DF; }
  .foot { margin-top: 16px; display: flex; gap: 22px; align-items: flex-start; }
  .who { position: relative; display: inline-block; padding-right: 64px; }
  .stamp { position: absolute; right: -6px; top: 50%; transform: translateY(-50%);
           width: 66px; height: 66px; object-fit: contain; mix-blend-mode: multiply; opacity: .92; }
  td { overflow-wrap: anywhere; }
  thead { display: table-header-group; }
  tr { page-break-inside: avoid; break-inside: avoid; }
  @media print {
    html, body { background: #fff; width: 100%; }
    .sheet { margin: 0; width: 100%; min-height: 0; padding: 11mm 12mm; }
    .bar { display: none; }
  }
</style>
</head>
<body>
<div class="sheet">

  <div class="bar">
    <button class="btn" onclick="window.print()">인쇄 / PDF 저장</button>
    <a class="btn" href="?p=invoice_print&amp;id=<?= $id ?>&amp;xlsx=1">엑셀로 저장</a>
    <?php if (!$stampUri): // 화면에만 보이는 안내 (인쇄 · PDF 에는 안 나옴) ?>
      <a class="btn" style="border-color:#E4B9B9;color:#A32020" href="?p=business_entity&amp;id=<?= (int)$eid ?>#stamp">
        <?= trim((string)($be['stamp_path'] ?? '')) === '' ? '직인 미등록 — 사업자 관리에서 올리기' : '직인 파일을 찾지 못함 — 다시 올리기' ?></a>
    <?php endif; ?>
    <a class="btn" href="?p=invoice_view&amp;id=<?= $id ?>">돌아가기</a>
    <?php if ($inv['status'] === 'DRAFT'): ?>
      <span style="font-size:12px;color:#A32020">아직 발행하지 않은 청구서입니다 (작성중)</span>
    <?php elseif ((int)($inv['issue_count'] ?? 0) > 1): ?>
      <span style="font-size:12px;color:#4E6273">재발행 <?= (int)$inv['issue_count'] ?>회차 · 최종 발행 <?= h(substr((string)$inv['issued_at'], 0, 16)) ?></span>
    <?php elseif ($inv['status'] === 'CANCELLED'): ?>
      <span style="font-size:12px;color:#A32020">취소된 청구서입니다</span>
    <?php endif; ?>
  </div>

  <div style="display:flex;align-items:flex-start;margin-bottom:20px">
    <div>
      <h1>INVOICE</h1>
      <div class="tnum" style="margin-top:7px;font-size:13px;font-weight:700">
        <?= h($inv['invoice_no']) ?></div>
      <div class="tnum" style="font-size:11.5px;color:#4E6273;margin-top:3px">
        DATE <?= h($inv['invoice_date']) ?>
        &nbsp;·&nbsp; PERIOD <?= h($inv['period_from']) ?> ~ <?= h($inv['period_to']) ?>
        <?php if ($inv['due_date']): ?>
          &nbsp;·&nbsp; DUE <?= h($inv['due_date']) ?>
        <?php endif; ?>
        <?php if ((int)($inv['issue_count'] ?? 0) > 1): ?>
          &nbsp;·&nbsp; REV. <?= (int)$inv['issue_count'] - 1 ?> (<?= h(substr((string)$inv['issued_at'], 0, 10)) ?>)
        <?php endif; ?>
      </div>
    </div>
    <div style="margin-left:auto;text-align:right;font-size:11.5px;line-height:1.7">
      <div style="font-size:15px;font-weight:700;letter-spacing:.04em">
        <span class="who"><?= h($be['name_en'] ?: $be['name_ko']) ?>
          <?php if ($stampUri): ?><img class="stamp" src="<?= h($stampUri) ?>" alt="직인"><?php endif; ?></span></div>
      <div><?= h($be['address_en'] ?: $be['address_ko']) ?></div>
      <div class="tnum">TEL. <?= h($be['phone']) ?>
        <?php if ($be['fax']): ?> &nbsp; FAX. <?= h($be['fax']) ?><?php endif; ?></div>
      <div class="tnum">사업자등록번호 <?= h($be['business_number']) ?></div>
      <div>대표자 <?= h($be['representative']) ?>
        <?php if ($be['doc_manager']): ?>
          &nbsp;·&nbsp; 담당 <?= h($be['doc_manager']) ?>
        <?php endif; ?></div>
    </div>
  </div>

  <table class="box" style="margin-bottom:16px">
    <tr>
      <th style="width:90px">MESSRS</th>
      <td style="font-weight:700;font-size:13px"><?= h($inv['name_ko']) ?>
        <?php if ($inv['name_en']): ?>
          <span style="font-weight:400;color:#4E6273"> / <?= h($inv['name_en']) ?></span>
        <?php endif; ?></td>
      <th style="width:100px">사업자번호</th>
      <td class="tnum" style="width:150px"><?= h($inv['business_number'] ?: '-') ?></td>
      <th style="width:70px">대표자</th>
      <td style="width:110px"><?= h($inv['representative'] ?: '-') ?></td>
    </tr>
    <tr>
      <th>ADDRESS</th>
      <td colspan="3"><?= h($inv['address_ko'] ?: '-') ?></td>
      <th>결제조건</th>
      <td><?= h($inv['payment_terms'] ?: '-') ?></td>
    </tr>
  </table>

  <table>
    <thead><tr>
      <th class="c" style="width:32px">NO</th>
      <th style="width:88px">DATE</th>
      <th style="width:150px">AWB NO</th>
      <th class="c" style="width:48px">구분</th>
      <th style="width:120px">DESTINATION</th>
      <th class="r" style="width:62px">WEIGHT</th>
      <th class="r" style="width:105px">영세율</th>
      <th class="r" style="width:105px">과세</th>
      <th class="r" style="width:90px">VAT</th>
      <th class="r" style="width:115px">AMOUNT</th>
    </tr></thead>
    <tbody>
    <?php foreach ($ships as $s): ?>
      <tr>
        <td class="c tnum"><?= (int)$s['line_no'] ?></td>
        <td class="tnum"><?= h($s['voucher_date']) ?></td>
        <td class="tnum" style="font-weight:600"><?= h($s['awb_no']) ?></td>
        <td class="c"><?= $s['trade_type']==='IMPORT'?'수입':'수출' ?></td>
        <td><?= h($s['dest_city'] ?: '-') ?></td>
        <td class="r tnum"><?= $s['charge_weight'] !== null
            ? h(rtrim(rtrim(number_format((float)$s['charge_weight'],2),'0'),'.')) : '-' ?></td>
        <td class="r tnum"><?= money($s['zero_supply']) ?></td>
        <td class="r tnum"><?= money($s['taxable_supply']) ?></td>
        <td class="r tnum"><?= money($s['tax_total']) ?></td>
        <td class="r tnum" style="font-weight:700"><?= money($s['grand_total']) ?></td>
      </tr>
    <?php endforeach; ?>
    <?php foreach ($items as $it): ?>
      <tr>
        <td class="c">·</td>
        <td colspan="5" style="color:#4E6273"><?= h($it['item_name']) ?>
          <?php if ($it['remark']): ?> <span style="font-size:10.5px">(<?= h($it['remark']) ?>)</span><?php endif; ?></td>
        <td class="r tnum"><?= $it['tax_type']==='ZERO'   ? money($it['supply_amount']) : '' ?></td>
        <td class="r tnum"><?= $it['tax_type']==='TAXABLE'? money($it['supply_amount']) : '' ?></td>
        <td class="r tnum"><?= money($it['tax_amount']) ?></td>
        <td class="r tnum" style="font-weight:700"><?= money($it['total_amount']) ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$ships && !$items): ?>
      <tr><td colspan="10" class="c" style="padding:30px;color:#4E6273">수록된 내역이 없습니다.</td></tr>
    <?php endif; ?>
    </tbody>
  </table>

  <div class="foot">
    <div class="box" style="flex:1;padding:11px 13px">
      <div style="font-size:11px;font-weight:700;color:#33485A;margin-bottom:7px">입금계좌</div>
      <?php foreach ($banks as $b): ?>
        <div class="tnum" style="font-size:12px;line-height:1.9">
          <?= h($b['bank_name']) ?> <b><?= h($b['account_no']) ?></b>
          <span style="color:#4E6273">예금주 <?= h($b['account_holder']) ?></span>
        </div>
      <?php endforeach; ?>
      <?php if (!$banks): ?>
        <div style="font-size:12px;color:#A32020">등록된 입금계좌가 없습니다.</div>
      <?php endif; ?>
      <?php if ($inv['remark']): ?>
        <div style="margin-top:9px;font-size:11.5px;color:#4E6273">
          <?= nl2br(h($inv['remark'])) ?></div>
      <?php endif; ?>
    </div>

    <table class="sum box" style="width:340px;padding:8px 10px">
      <tr><td class="lab">영세율 공급가액</td>
          <td class="r tnum"><?= money($inv['zero_supply']) ?></td></tr>
      <tr><td class="lab">과세 공급가액</td>
          <td class="r tnum"><?= money($inv['taxable_supply']) ?></td></tr>
      <?php if ((float)$inv['exempt_supply'] != 0.0): ?>
      <tr><td class="lab">면세 공급가액</td>
          <td class="r tnum"><?= money($inv['exempt_supply']) ?></td></tr>
      <?php endif; ?>
      <tr><td class="lab">부가세 (VAT)</td>
          <td class="r tnum"><?= money($inv['tax_total']) ?></td></tr>
      <tr><td colspan="2" style="border-top:1px solid #C9D6DF;padding:0"></td></tr>
      <tr><td class="lab big">합계 금액</td>
          <td class="r tnum big"><?= money($inv['grand_total']) ?></td></tr>
      <?php if ((float)$inv['paid_amount'] > 0): ?>
      <tr><td class="lab">기수금</td>
          <td class="r tnum">- <?= money($inv['paid_amount']) ?></td></tr>
      <tr><td class="lab big">미수 잔액</td>
          <td class="r tnum big"><?= money($inv['balance']) ?></td></tr>
      <?php endif; ?>
    </table>
  </div>

  <div style="margin-top:14px;font-size:11px;color:#4E6273;text-align:center">
    위와 같이 청구합니다. &nbsp;·&nbsp;
    <?= h($be['name_ko']) ?> &nbsp;·&nbsp; <?= h($inv['invoice_date']) ?>
  </div>

</div>
</body>
</html>
