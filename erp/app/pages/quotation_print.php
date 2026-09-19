<?php
// 웹에서 직접 열면 실행되지 않게 막습니다 (nginx 면 .htaccess 가 무시됩니다)
if (!defined('APP_DIR')) { http_response_code(403); exit('Forbidden'); }
/** 견적서 A4 세로 출력 */
$eid = entity_id();
$id  = (int)query('id', '0');

$st = db()->prepare(
    'SELECT q.*, c.name_ko, c.name_en, c.representative, c.business_number,
            c.address_ko, c.phone AS cphone, ca.code AS carrier, ca.name AS carrier_name
       FROM quotations q
       LEFT JOIN companies c  ON c.id = q.company_id
       LEFT JOIN carriers  ca ON ca.id = q.carrier_id
      WHERE q.id = ? AND q.business_entity_id = ? AND q.deleted_at IS NULL');
$st->execute([$id, $eid]);
$q = $st->fetch();
if (!$q) { exit('견적서를 찾을 수 없습니다.'); }

$st = db()->prepare('SELECT * FROM quotation_items WHERE quotation_id = ? ORDER BY line_no');
$st->execute([$id]);
$items = $st->fetchAll();

$st = db()->prepare('SELECT * FROM business_entities WHERE id = ?');
$st->execute([$eid]);
$be = $st->fetch();

$st = db()->prepare(
    'SELECT bank_name, account_no, account_holder FROM business_bank_accounts
      WHERE business_entity_id = ? AND is_active = 1 ORDER BY sort_order LIMIT 2');
$st->execute([$eid]);
$banks = $st->fetchAll();

log_action('견적', 'PRINT', 'quotations', $id, (string)$q['quote_no']);
$buyer = $q['name_ko'] ?: ($q['prospect_name'] ?: '-');
?><!doctype html>
<html lang="ko">
<head>
<meta charset="utf-8">
<title>견적서 <?= h($q['quote_no']) ?></title>
<style>
  @page { size: A4 portrait; margin: 14mm; }
  * { box-sizing: border-box; }
  body { margin: 0; background: #F1F5F8; color: #0C1A26;
         font-family: system-ui, -apple-system, "Segoe UI", "Malgun Gothic", sans-serif; }
  .sheet { width: 794px; min-height: 1123px; margin: 18px auto; background: #fff; padding: 32px 36px; }
  .tnum { font-variant-numeric: tabular-nums; }
  .bar { display: flex; gap: 10px; margin-bottom: 16px; }
  .bar .btn { height: 32px; padding: 0 14px; border: 1px solid #D3DEE6; background: #fff;
              border-radius: 5px; font-size: 12.5px; font-weight: 600; color: #34485A;
              cursor: pointer; text-decoration: none; display: inline-flex; align-items: center; }
  h1 { margin: 0; font-size: 30px; letter-spacing: .5em; text-align: center; font-weight: 700; }
  table { width: 100%; border-collapse: collapse; }
  th { font-size: 11px; font-weight: 700; color: #33485A; background: #EEF3F6;
       border-top: 1.5px solid #0C1A26; border-bottom: 1px solid #C9D6DF;
       padding: 7px 8px; text-align: left; }
  td { font-size: 11.5px; padding: 7px 8px; border-bottom: 1px solid #E7EDF1; }
  td.r, th.r { text-align: right; } td.c, th.c { text-align: center; }
  .box { border: 1px solid #C9D6DF; }
  .box td, .box th { border-bottom: 1px solid #E7EDF1; }
  @media print { body { background: #fff; } .sheet { margin: 0; width: auto; min-height: 0; padding: 0; }
                 .bar { display: none; } }
</style>
</head>
<body>
<div class="sheet">
  <div class="bar">
    <button class="btn" onclick="window.print()">인쇄 / PDF 저장</button>
    <a class="btn" href="?p=quotation_form&amp;id=<?= $id ?>">돌아가기</a>
    <?php if ($q['status'] === 'DRAFT'): ?>
      <span style="font-size:12px;color:#A32020;align-self:center">작성중인 견적서입니다</span>
    <?php endif; ?>
  </div>

  <h1>견 적 서</h1>
  <div class="tnum" style="text-align:center;margin:8px 0 22px;font-size:12px;color:#4E6273">
    <?= h($q['quote_no']) ?> &nbsp;·&nbsp; <?= h($q['quote_date']) ?>
    <?php if ($q['valid_until']): ?>
      &nbsp;·&nbsp; 유효기한 <?= h($q['valid_until']) ?>
    <?php endif; ?>
  </div>

  <table class="box" style="margin-bottom:6px">
    <tr>
      <th style="width:80px">수 신</th>
      <td style="font-size:14px;font-weight:700"><?= h($buyer) ?> 귀중</td>
      <th style="width:80px">담 당</th>
      <td style="width:150px"><?= h($q['representative'] ?: '-') ?></td>
    </tr>
    <tr>
      <th>아래와 같이</th>
      <td colspan="3">견적합니다.</td>
    </tr>
  </table>

  <table class="box" style="margin-bottom:16px">
    <tr>
      <th style="width:80px">공급자</th>
      <td style="font-weight:700"><?= h($be['name_ko']) ?></td>
      <th style="width:90px">사업자번호</th>
      <td class="tnum" style="width:140px"><?= h($be['business_number']) ?></td>
    </tr>
    <tr>
      <th>주소</th>
      <td colspan="3"><?= h($be['address_ko']) ?></td>
    </tr>
    <tr>
      <th>연락처</th>
      <td class="tnum">TEL <?= h($be['phone']) ?>
        <?php if ($be['fax']): ?> &nbsp; FAX <?= h($be['fax']) ?><?php endif; ?></td>
      <th>대표자 / 담당</th>
      <td><?= h($be['representative']) ?>
        <?php if ($be['doc_manager']): ?> / <?= h($be['doc_manager']) ?><?php endif; ?></td>
    </tr>
  </table>

  <table class="box" style="margin-bottom:16px">
    <tr>
      <th style="width:80px">구분</th>
      <td style="width:110px"><?= $q['trade_type']==='IMPORT'?'수입':'수출' ?></td>
      <th style="width:70px">운송사</th>
      <td style="width:130px"><?= h($q['carrier_name'] ?: $q['carrier'] ?: '-') ?></td>
      <th style="width:70px">구간</th>
      <td><?= h(($q['origin_country'] ?: '-') . ' → ' . ($q['dest_country'] ?: '-')) ?>
        <?php if ($q['charge_weight']): ?>
          &nbsp;·&nbsp; <span class="tnum"><?= h(rtrim(rtrim(number_format((float)$q['charge_weight'],2),'0'),'.')) ?> kg</span>
        <?php endif; ?></td>
    </tr>
  </table>

  <table>
    <thead><tr>
      <th class="c" style="width:34px">NO</th>
      <th style="width:100px">구분</th>
      <th>항목</th>
      <th class="r" style="width:55px">수량</th>
      <th class="r" style="width:95px">단가</th>
      <th class="c" style="width:60px">세금</th>
      <th class="r" style="width:105px">공급가액</th>
    </tr></thead>
    <tbody>
    <?php
    $CT = charge_labels();
    foreach ($items as $it): ?>
      <tr>
        <td class="c tnum"><?= (int)$it['line_no'] ?></td>
        <td><?= h($CT[$it['charge_type']] ?? $it['charge_type']) ?></td>
        <td><?= h($it['item_name']) ?></td>
        <td class="r tnum"><?= h(rtrim(rtrim(number_format((float)$it['qty'],2),'0'),'.')) ?></td>
        <td class="r tnum"><?= money($it['unit_price']) ?></td>
        <td class="c"><?= $it['tax_type']==='TAXABLE'?'과세':($it['tax_type']==='EXEMPT'?'면세':'영세') ?></td>
        <td class="r tnum" style="font-weight:700"><?= money($it['supply_amount']) ?></td>
      </tr>
    <?php endforeach; ?>
    <?php for ($i = count($items); $i < 8; $i++): ?>
      <tr><td class="c">&nbsp;</td><td></td><td></td><td></td><td></td><td></td><td></td></tr>
    <?php endfor; ?>
    </tbody>
  </table>

  <table class="box" style="margin-top:14px">
    <tr>
      <th class="r" style="width:120px">공급가액 합계</th>
      <td class="r tnum" style="width:140px"><?= money($q['supply_total']) ?></td>
      <th class="r" style="width:100px">부가세</th>
      <td class="r tnum" style="width:120px"><?= money($q['tax_total']) ?></td>
      <th class="r" style="width:100px">총 견적금액</th>
      <td class="r tnum" style="font-size:16px;font-weight:700"><?= money($q['grand_total']) ?></td>
    </tr>
  </table>

  <?php if ($q['terms']): ?>
  <div class="box" style="margin-top:14px;padding:11px 13px">
    <div style="font-size:11px;font-weight:700;color:#33485A;margin-bottom:6px">견적 조건</div>
    <div style="font-size:11.5px;line-height:1.9;color:#33485A"><?= nl2br(h($q['terms'])) ?></div>
  </div>
  <?php endif; ?>

  <?php if ($banks): ?>
  <div class="box" style="margin-top:10px;padding:11px 13px">
    <div style="font-size:11px;font-weight:700;color:#33485A;margin-bottom:6px">입금계좌</div>
    <?php foreach ($banks as $b): ?>
      <div class="tnum" style="font-size:11.5px;line-height:1.8">
        <?= h($b['bank_name']) ?> <b><?= h($b['account_no']) ?></b>
        <span style="color:#4E6273">예금주 <?= h($b['account_holder']) ?></span></div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <div style="margin-top:26px;text-align:center;font-size:13px;font-weight:700">
    <?= h($be['name_ko']) ?>
    <span style="font-weight:400;color:#4E6273">&nbsp; 대표 <?= h($be['representative']) ?> (인)</span>
  </div>
</div>
</body>
</html>
