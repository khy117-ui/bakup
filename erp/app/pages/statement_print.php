<?php
// 웹에서 직접 열면 실행되지 않게 막습니다 (nginx 면 .htaccess 가 무시됩니다)
if (!defined('APP_DIR')) { http_response_code(403); exit('Forbidden'); }
/** 거래명세서 A4 세로 출력 */
$eid = entity_id();
$id  = (int)query('id', '0');

$st = db()->prepare(
    'SELECT s.*, c.name_ko, c.representative, c.business_number, c.address_ko,
            c.business_type, c.business_item
       FROM statements s JOIN companies c ON c.id = s.company_id
      WHERE s.id = ? AND s.business_entity_id = ? AND s.deleted_at IS NULL');
$st->execute([$id, $eid]);
$sm = $st->fetch();
if (!$sm) { exit('거래명세서를 찾을 수 없습니다.'); }

$st = db()->prepare('SELECT * FROM business_entities WHERE id = ?');
$st->execute([$eid]);
$be = $st->fetch();

$st = db()->prepare(
    'SELECT bank_name, account_no, account_holder FROM business_bank_accounts
      WHERE business_entity_id = ? AND is_active = 1 ORDER BY sort_order LIMIT 2');
$st->execute([$eid]);
$banks = $st->fetchAll();

$st = db()->prepare(
    'SELECT x.line_no, sh.awb_no, sh.voucher_date, sh.trade_type, sh.dest_city,
            sh.charge_weight, ca.code AS carrier,
            COALESCE(t.zero_supply,0) AS zero_supply,
            COALESCE(t.taxable_supply,0) AS taxable_supply,
            COALESCE(t.tax_total,0) AS tax_total,
            COALESCE(t.grand_total,0) AS grand_total
       FROM statement_shipments x
       JOIN shipments sh ON sh.id = x.shipment_id
       JOIN carriers ca ON ca.id = sh.carrier_id
       LEFT JOIN v_shipment_totals t ON t.shipment_id = sh.id
      WHERE x.statement_id = ? ORDER BY x.line_no');
$st->execute([$id]);
$rows = $st->fetchAll();

log_action('거래명세서', 'PRINT', 'statements', $id, (string)$sm['statement_no']);

$zero = 0; $taxable = 0;
foreach ($rows as $r) { $zero += $r['zero_supply']; $taxable += $r['taxable_supply']; }
$stampUri = entity_stamp_data_uri($be ?: null);   // 사업자 관리에서 올린 직인 (없으면 '(인)' 글자만)
?><!doctype html>
<html lang="ko">
<head>
<meta charset="utf-8">
<title>거래명세서 <?= h($sm['statement_no']) ?></title>
<style>
  @page { size: A4 portrait; margin: 13mm; }
  * { box-sizing: border-box; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
  /* 직인 — 공급자 대표자 옆 '(인)' 위에 겹쳐 찍음 (흰 바탕은 비쳐 보이게) */
  .inwrap { position: relative; display: inline-block; }
  .stamp { position: absolute; left: 50%; top: 50%; transform: translate(-50%, -50%);
           width: 60px; height: 60px; object-fit: contain; mix-blend-mode: multiply; opacity: .9; pointer-events: none; }
  body { margin: 0; background: #F1F5F8; color: #0C1A26;
         font-family: system-ui, -apple-system, "Segoe UI", "Malgun Gothic", sans-serif; }
  .sheet { width: 794px; min-height: 1123px; margin: 18px auto; background: #fff; padding: 30px 34px; }
  .tnum { font-variant-numeric: tabular-nums; }
  .bar { display: flex; gap: 10px; margin-bottom: 16px; }
  .bar .btn { height: 32px; padding: 0 14px; border: 1px solid #D3DEE6; background: #fff;
              border-radius: 5px; font-size: 12.5px; font-weight: 600; color: #34485A;
              cursor: pointer; text-decoration: none; display: inline-flex; align-items: center; }
  h1 { margin: 0; font-size: 26px; letter-spacing: .38em; text-align: center; font-weight: 700; }
  table { width: 100%; border-collapse: collapse; }
  th { font-size: 10.5px; font-weight: 700; color: #33485A; background: #EEF3F6;
       border-top: 1.5px solid #0C1A26; border-bottom: 1px solid #C9D6DF;
       padding: 6px 7px; text-align: left; }
  td { font-size: 11px; padding: 6px 7px; border-bottom: 1px solid #E7EDF1; }
  td.r, th.r { text-align: right; } td.c, th.c { text-align: center; }
  .box { border: 1px solid #C9D6DF; }
  @media print { body { background: #fff; } .sheet { margin: 0; width: auto; min-height: 0; padding: 0; }
                 .bar { display: none; } }
</style>
</head>
<body>
<div class="sheet">
  <div class="bar">
    <button class="btn" onclick="window.print()">인쇄 / PDF 저장</button>
    <a class="btn" href="?p=statements&amp;id=<?= (int)$sm['id'] ?>">명세서 화면</a>
    <a class="btn" href="?p=statements">돌아가기</a>
    <?php $liveS = statement_live_totals((int)$sm['id']);
          if (abs($liveS['grand_total'] - (float)$sm['grand_total']) > 0.5): ?>
      <span style="font-size:12px;color:#A32020;align-self:center">수록 전표 금액이 바뀌었습니다 — 명세서 화면에서 [재발행] 하세요</span>
    <?php elseif ((int)($sm['revision'] ?? 1) > 1): ?>
      <span style="font-size:12px;color:#4E6273;align-self:center">재발행 REV. <?= (int)$sm['revision'] ?> · 최종 발행 <?= h(substr((string)$sm['issued_at'], 0, 16)) ?></span>
    <?php endif; ?>
    <?php if (!$stampUri): // 화면에만 보이는 안내 (인쇄 · PDF 에는 안 나옴) ?>
      <a class="btn" style="border-color:#E4B9B9;color:#A32020" href="?p=business_entity&amp;id=<?= (int)($be['id'] ?? 0) ?>#stamp">직인 미등록 — 사업자 관리에서 올리기</a>
    <?php endif; ?>
  </div>

  <h1>거 래 명 세 서</h1>
  <div class="tnum" style="text-align:center;margin:8px 0 20px;font-size:12px;color:#4E6273">
    <?= h($sm['statement_no']) ?> &nbsp;·&nbsp; <?= h($sm['statement_date']) ?>
    &nbsp;·&nbsp; 대상기간 <?= h($sm['period_from']) ?> ~ <?= h($sm['period_to']) ?>
    <?php if ((int)($sm['revision'] ?? 1) > 1): ?>
      &nbsp;·&nbsp; REV. <?= (int)$sm['revision'] ?> (<?= h(substr((string)$sm['issued_at'], 0, 10)) ?>)
    <?php endif; ?>
  </div>

  <div style="display:flex;gap:10px;margin-bottom:16px">
    <table class="box" style="flex:1">
      <tr><th colspan="2" style="text-align:center;border-top:1.5px solid #0C1A26">공 급 받 는 자</th></tr>
      <tr><th style="width:74px">상호</th><td style="font-weight:700"><?= h($sm['name_ko']) ?></td></tr>
      <tr><th>사업자번호</th><td class="tnum"><?= h($sm['business_number'] ?: '-') ?></td></tr>
      <tr><th>대표자</th><td><?= h($sm['representative'] ?: '-') ?></td></tr>
      <tr><th>주소</th><td style="font-size:10.5px"><?= h($sm['address_ko'] ?: '-') ?></td></tr>
      <tr><th>업태 / 종목</th><td style="font-size:10.5px">
        <?= h(($sm['business_type'] ?: '-') . ' / ' . ($sm['business_item'] ?: '-')) ?></td></tr>
    </table>
    <table class="box" style="flex:1">
      <tr><th colspan="2" style="text-align:center;border-top:1.5px solid #0C1A26">공 급 자</th></tr>
      <tr><th style="width:74px">상호</th><td style="font-weight:700"><?= h($be['name_ko']) ?></td></tr>
      <tr><th>사업자번호</th><td class="tnum"><?= h($be['business_number']) ?></td></tr>
      <tr><th>대표자</th><td><?= h($be['representative']) ?> <span class="inwrap">(인)<?php if ($stampUri): ?><img class="stamp" src="<?= h($stampUri) ?>" alt="직인"><?php endif; ?></span></td></tr>
      <tr><th>주소</th><td style="font-size:10.5px"><?= h($be['address_ko']) ?></td></tr>
      <tr><th>업태 / 종목</th><td style="font-size:10.5px">
        <?= h(($be['business_type'] ?: '-') . ' / ' . ($be['business_item'] ?: '-')) ?></td></tr>
    </table>
  </div>

  <table>
    <thead><tr>
      <th class="c" style="width:30px">NO</th>
      <th style="width:78px">일자</th>
      <th style="width:135px">AWB</th>
      <th class="c" style="width:52px">운송사</th>
      <th>도착지</th>
      <th class="r" style="width:52px">중량</th>
      <th class="r" style="width:92px">영세율</th>
      <th class="r" style="width:92px">과세</th>
      <th class="r" style="width:78px">세액</th>
      <th class="r" style="width:100px">합계</th>
    </tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td class="c tnum"><?= (int)$r['line_no'] ?></td>
        <td class="tnum"><?= h(substr((string)$r['voucher_date'], 2)) ?></td>
        <td class="tnum" style="font-weight:600"><?= h($r['awb_no']) ?></td>
        <td class="c"><?= h($r['carrier']) ?></td>
        <td><?= h($r['dest_city'] ?: '-') ?></td>
        <td class="r tnum"><?= $r['charge_weight'] !== null
            ? h(rtrim(rtrim(number_format((float)$r['charge_weight'],1),'0'),'.')) : '-' ?></td>
        <td class="r tnum"><?= money($r['zero_supply']) ?></td>
        <td class="r tnum"><?= money($r['taxable_supply']) ?></td>
        <td class="r tnum"><?= money($r['tax_total']) ?></td>
        <td class="r tnum" style="font-weight:700"><?= money($r['grand_total']) ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?>
      <tr><td colspan="10" class="c" style="padding:30px;color:#4E6273">수록된 전표가 없습니다.</td></tr>
    <?php endif; ?>
    </tbody>
  </table>

  <table class="box" style="margin-top:12px">
    <tr>
      <th class="r" style="width:92px">영세율</th>
      <td class="r tnum" style="width:110px"><?= money($zero) ?></td>
      <th class="r" style="width:80px">과세</th>
      <td class="r tnum" style="width:110px"><?= money($taxable) ?></td>
      <th class="r" style="width:70px">부가세</th>
      <td class="r tnum" style="width:100px"><?= money($sm['tax_total']) ?></td>
      <th class="r" style="width:80px">합계</th>
      <td class="r tnum" style="font-size:15px;font-weight:700"><?= money($sm['grand_total']) ?></td>
    </tr>
  </table>

  <div style="display:flex;gap:10px;margin-top:12px">
    <?php if ($banks): ?>
    <div class="box" style="flex:1;padding:10px 12px">
      <div style="font-size:10.5px;font-weight:700;color:#33485A;margin-bottom:5px">입금계좌</div>
      <?php foreach ($banks as $b): ?>
        <div class="tnum" style="font-size:11px;line-height:1.8">
          <?= h($b['bank_name']) ?> <b><?= h($b['account_no']) ?></b>
          <span style="color:#4E6273">예금주 <?= h($b['account_holder']) ?></span></div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
    <div class="box" style="flex:1;padding:10px 12px">
      <div style="font-size:10.5px;font-weight:700;color:#33485A;margin-bottom:5px">문의</div>
      <div class="tnum" style="font-size:11px;line-height:1.8">
        TEL <?= h($be['phone']) ?>
        <?php if ($be['fax']): ?> &nbsp; FAX <?= h($be['fax']) ?><?php endif; ?></div>
      <?php if ($be['doc_manager']): ?>
        <div style="font-size:11px;line-height:1.8">담당 <?= h($be['doc_manager']) ?></div>
      <?php endif; ?>
    </div>
  </div>

  <div style="margin-top:20px;text-align:center;font-size:11.5px;color:#4E6273">
    위와 같이 거래하였음을 확인합니다.
  </div>
</div>
</body>
</html>
