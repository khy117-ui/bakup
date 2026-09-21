<?php
// 웹에서 직접 열면 실행되지 않게 막습니다 (nginx 면 .htaccess 가 무시됩니다)
if (!defined('APP_DIR')) { http_response_code(403); exit('Forbidden'); }
/** 입금확인서 A4 세로 출력 */
$eid = entity_id();
$id  = (int)query('id', '0');
schema_upgrade_receipts();

$st = db()->prepare(
    'SELECT r.*, c.name_ko, c.representative, c.business_number, c.address_ko
       FROM receipts r JOIN companies c ON c.id = r.company_id
      WHERE r.id = ? AND r.business_entity_id = ? AND r.deleted_at IS NULL');
$st->execute([$id, $eid]);
$rc = $st->fetch();
if (!$rc) { exit('입금확인서를 찾을 수 없습니다.'); }

$st = db()->prepare('SELECT * FROM business_entities WHERE id = ?');
$st->execute([$eid]);
$be = $st->fetch();

$st = db()->prepare(
    "SELECT x.line_no, x.amount, f.doc_no, f.txn_date, f.method, f.counterparty, f.summary, f.status AS txn_status,
            b.bank_name, b.account_no,
            (SELECT GROUP_CONCAT(DISTINCT COALESCE(i.invoice_no, s.awb_no) ORDER BY pa.line_no SEPARATOR ', ')
               FROM payment_allocations pa
               LEFT JOIN invoices i ON i.id = pa.invoice_id
               LEFT JOIN shipments s ON s.id = pa.shipment_id
              WHERE pa.transaction_id = f.id) AS applied
       FROM receipt_transactions x
       JOIN financial_transactions f ON f.id = x.transaction_id
       LEFT JOIN business_bank_accounts b ON b.id = f.to_account_id
      WHERE x.receipt_id = ? ORDER BY x.line_no");
$st->execute([$id]);
$rows = $st->fetchAll();

log_action('입금확인서', 'PRINT', 'receipts', $id, (string)$rc['receipt_no']);

$METHOD = ['TRANSFER' => '계좌이체', 'CARD' => '카드', 'CASH' => '현금', 'NOTE' => '어음', 'PG' => 'PG'];
$live = receipt_live_totals($id);
$mismatch = $rc['status'] === 'ISSUED' && (abs($live['amount_total'] - (float)$rc['amount_total']) > 0.5 || $live['cnt'] !== count($rows));
$stampUri = entity_stamp_data_uri($be ?: null);
?><!doctype html>
<html lang="ko">
<head>
<meta charset="utf-8">
<title>입금확인서 <?= h($rc['receipt_no']) ?></title>
<style>
  @page { size: A4 portrait; margin: 13mm; }
  * { box-sizing: border-box; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
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
       border-top: 1.5px solid #0C1A26; border-bottom: 1px solid #C9D6DF; padding: 6px 7px; text-align: left; }
  td { font-size: 11px; padding: 6px 7px; border-bottom: 1px solid #E7EDF1; }
  td.r, th.r { text-align: right; } td.c, th.c { text-align: center; }
  .box { border: 1px solid #C9D6DF; }
  .cancel { position: absolute; left: 50%; top: 45%; transform: translate(-50%,-50%) rotate(-20deg);
            font-size: 64px; font-weight: 900; color: rgba(163,32,32,.18); letter-spacing: .3em; pointer-events: none; }
  @media print { body { background: #fff; } .sheet { margin: 0; width: auto; min-height: 0; padding: 0; } .bar { display: none; } }
</style>
</head>
<body>
<div class="sheet" style="position:relative">
  <?php if ($rc['status'] === 'CANCELLED'): ?><div class="cancel">취 소</div><?php endif; ?>
  <div class="bar">
    <button class="btn" onclick="window.print()">인쇄 / PDF 저장</button>
    <a class="btn" href="?p=receipts&amp;id=<?= (int)$rc['id'] ?>">확인서 화면</a>
    <a class="btn" href="?p=receipts">돌아가기</a>
    <?php if ($rc['status'] === 'CANCELLED'): ?>
      <span style="font-size:12px;color:#A32020;align-self:center">취소된 확인서입니다</span>
    <?php elseif ($mismatch): ?>
      <span style="font-size:12px;color:#A32020;align-self:center">수록 입금이 바뀌었습니다 — 확인서 화면에서 [재발행] 하세요</span>
    <?php elseif ((int)$rc['revision'] > 1): ?>
      <span style="font-size:12px;color:#4E6273;align-self:center">재발행 REV. <?= (int)$rc['revision'] ?> · 최종 발행 <?= h(substr((string)$rc['issued_at'], 0, 16)) ?></span>
    <?php endif; ?>
    <?php if (!$stampUri): ?>
      <a class="btn" style="border-color:#E4B9B9;color:#A32020" href="?p=business_entity&amp;id=<?= (int)($be['id'] ?? 0) ?>#stamp">직인 미등록 — 사업자 관리에서 올리기</a>
    <?php endif; ?>
  </div>

  <h1>입 금 확 인 서</h1>
  <div class="tnum" style="text-align:center;margin:8px 0 20px;font-size:12px;color:#4E6273">
    <?= h($rc['receipt_no']) ?> &nbsp;·&nbsp; <?= h($rc['receipt_date']) ?>
    <?php if ($rc['period_from'] && $rc['period_to']): ?>&nbsp;·&nbsp; 대상기간 <?= h($rc['period_from']) ?> ~ <?= h($rc['period_to']) ?><?php endif; ?>
    <?php if ((int)$rc['revision'] > 1): ?>&nbsp;·&nbsp; REV. <?= (int)$rc['revision'] ?> (<?= h(substr((string)$rc['issued_at'], 0, 10)) ?>)<?php endif; ?>
  </div>

  <table class="box" style="margin-bottom:14px">
    <tr><th style="width:80px">수 신</th><td style="font-size:14px;font-weight:700"><?= h($rc['name_ko']) ?> 귀중</td>
        <th style="width:90px">사업자번호</th><td class="tnum" style="width:150px"><?= h($rc['business_number'] ?: '-') ?></td></tr>
    <tr><th>대표자</th><td><?= h($rc['representative'] ?: '-') ?></td>
        <th>주소</th><td style="font-size:10.5px"><?= h($rc['address_ko'] ?: '-') ?></td></tr>
  </table>

  <div style="font-size:12.5px;line-height:1.9;margin:6px 0 14px">
    귀사에서 아래와 같이 입금하신 내역을 확인합니다. 감사합니다.
  </div>

  <table>
    <thead><tr>
      <th class="c" style="width:30px">NO</th>
      <th style="width:82px">입금일</th>
      <th style="width:120px">입금번호</th>
      <th style="width:60px">방법</th>
      <th style="width:110px">입금자</th>
      <th>입금계좌 · 충당 내역</th>
      <th class="r" style="width:110px">금액</th>
    </tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td class="c tnum"><?= (int)$r['line_no'] ?></td>
        <td class="tnum"><?= h(substr((string)$r['txn_date'], 2)) ?></td>
        <td class="tnum"><?= h($r['doc_no'] ?: '-') ?></td>
        <td><?= h($METHOD[$r['method']] ?? $r['method']) ?></td>
        <td><?= h($r['counterparty'] ?: '-') ?></td>
        <td style="font-size:10.5px"><?= h(trim(($r['bank_name'] ?? '') . ' ' . ($r['account_no'] ?? ''))) ?>
          <?= $r['applied'] ? '<br><span style="color:#4E6273">' . h($r['applied']) . '</span>' : '' ?>
          <?= $r['summary'] ? '<br><span style="color:#4E6273">' . h($r['summary']) . '</span>' : '' ?></td>
        <td class="r tnum" style="font-weight:700"><?= money($r['amount']) ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?>
      <tr><td colspan="7" class="c" style="padding:30px;color:#4E6273">수록된 입금이 없습니다.</td></tr>
    <?php endif; ?>
    </tbody>
  </table>

  <table class="box" style="margin-top:12px">
    <tr>
      <th class="r" style="width:120px">입금 건수</th>
      <td class="r tnum" style="width:100px"><?= (int)$rc['txn_count'] ?>건</td>
      <th class="r" style="width:120px">입금 합계</th>
      <td class="r tnum" style="font-size:15px;font-weight:700"><?= money($rc['amount_total']) ?> 원</td>
    </tr>
    <?php if ($rc['remark']): ?>
    <tr><th class="r">비고</th><td colspan="3" style="font-size:11px"><?= h($rc['remark']) ?></td></tr>
    <?php endif; ?>
  </table>

  <div style="margin-top:34px;text-align:center;font-size:12.5px;line-height:2">
    위 금액을 정히 입금받았음을 확인합니다.<br>
    <span class="tnum"><?= h(str_replace('-', '. ', (string)$rc['receipt_date'])) ?>.</span>
  </div>

  <div style="margin-top:22px;text-align:right;font-size:12px;line-height:1.9">
    <div style="font-size:14px;font-weight:700"><?= h($be['name_ko']) ?></div>
    <div class="tnum">사업자등록번호 <?= h($be['business_number']) ?></div>
    <div style="font-size:10.5px;color:#4E6273"><?= h($be['address_ko']) ?></div>
    <div class="tnum">TEL <?= h($be['phone']) ?><?php if ($be['fax']): ?> &nbsp; FAX <?= h($be['fax']) ?><?php endif; ?></div>
    <div>대표자 <?= h($be['representative']) ?> <span class="inwrap">(인)<?php if ($stampUri): ?><img class="stamp" src="<?= h($stampUri) ?>" alt="직인"><?php endif; ?></span></div>
  </div>
</div>
</body>
</html>
