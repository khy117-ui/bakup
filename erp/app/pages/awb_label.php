<?php
// 웹에서 직접 열면 실행되지 않게 막습니다 (nginx 면 .htaccess 가 무시됩니다)
if (!defined('APP_DIR')) { http_response_code(403); exit('Forbidden'); }
/** AWB 라벨 출력 — 100 × 150 mm 라벨지 기준 */
require APP_DIR . '/barcode.php';

$eid = entity_id();
$id  = (int)query('id', '0');

$st = db()->prepare(
    'SELECT s.*, c.name_ko, ca.code AS carrier, ca.name AS carrier_name
       FROM shipments s
       JOIN companies c ON c.id = s.company_id
       JOIN carriers ca ON ca.id = s.carrier_id
      WHERE s.id = ? AND s.business_entity_id = ? AND s.deleted_at IS NULL');
$st->execute([$id, $eid]);
$sh = $st->fetch();
if (!$sh) { exit('전표를 찾을 수 없습니다.'); }

$st = db()->prepare('SELECT * FROM shipment_parties WHERE shipment_id = ?');
$st->execute([$id]);
$party = [];
foreach ($st->fetchAll() as $p) { $party[$p['party_type']] = $p; }

$st = db()->prepare('SELECT * FROM business_entities WHERE id = ?');
$st->execute([$eid]);
$be = $st->fetch();

$st = db()->prepare('SELECT tracking_no FROM tracking_numbers WHERE shipment_id = ?
                      ORDER BY id LIMIT 1');
$st->execute([$id]);
$trk = $st->fetchColumn() ?: null;

log_action('물류', 'PRINT', 'shipments', $id, (string)$sh['awb_no'], null, 'AWB 라벨');

$svg = code128_svg((string)$sh['awb_no'], 54, 1.7, false);
$trkSvg = $trk ? code128_svg($trk, 40, 1.4, false) : null;

$shipper   = $party['SHIPPER']   ?? null;
$consignee = $party['CONSIGNEE'] ?? null;
$wt = $sh['charge_weight'] !== null
    ? rtrim(rtrim(number_format((float)$sh['charge_weight'], 2), '0'), '.') : '-';
?><!doctype html>
<html lang="ko">
<head>
<meta charset="utf-8">
<title>AWB <?= h($sh['awb_no']) ?></title>
<style>
  @page { size: 100mm 150mm; margin: 0; }
  * { box-sizing: border-box; }
  body { margin: 0; background: #E9EEF2;
         font-family: system-ui, -apple-system, "Segoe UI", "Malgun Gothic", sans-serif; }
  .bar { display: flex; gap: 10px; padding: 14px; }
  .bar .btn { height: 32px; padding: 0 14px; border: 1px solid #D3DEE6; background: #fff;
              border-radius: 5px; font-size: 12.5px; font-weight: 600; color: #34485A;
              cursor: pointer; text-decoration: none; display: inline-flex; align-items: center; }
  .label { width: 100mm; height: 150mm; background: #fff; margin: 0 auto 20px;
           padding: 4mm; border: 1px solid #C9D6DF; display: flex; flex-direction: column; }
  .tnum { font-variant-numeric: tabular-nums; }
  .row { display: flex; }
  .blk { border: 1px solid #0C1A26; padding: 2mm; }
  .cap { font-size: 7pt; font-weight: 700; letter-spacing: .06em; color: #333; }
  .val { font-size: 9pt; line-height: 1.35; margin-top: 1mm; }
  .big { font-size: 15pt; font-weight: 700; letter-spacing: .02em; }
  @media print {
    body { background: #fff; }
    .bar { display: none; }
    .label { margin: 0; border: 0; page-break-after: always; }
  }
</style>
</head>
<body>
<div class="bar">
  <button class="btn" onclick="window.print()">인쇄</button>
  <a class="btn" href="?p=awb_list">목록</a>
  <a class="btn" href="?p=shipment_form&amp;id=<?= $id ?>">전표</a>
  <span style="font-size:12px;color:#4E6273;align-self:center">
    100 × 150 mm 라벨지 기준입니다. 인쇄 설정에서 <b>배율 100%</b>, 여백 없음으로 하세요.</span>
</div>

<div class="label">
  <!-- 머리 -->
  <div class="row" style="align-items:flex-start;border-bottom:1.5px solid #0C1A26;padding-bottom:2mm">
    <div style="flex:1">
      <div style="font-size:12pt;font-weight:700;letter-spacing:.04em">
        <?= h($be['name_en'] ?: $be['name_ko']) ?></div>
      <div class="tnum" style="font-size:7.5pt;color:#333;margin-top:.6mm">
        TEL <?= h($be['phone']) ?><?php if ($be['fax']): ?> · FAX <?= h($be['fax']) ?><?php endif; ?></div>
    </div>
    <div style="text-align:right">
      <div class="cap">CARRIER</div>
      <div class="big"><?= h($sh['carrier']) ?></div>
    </div>
  </div>

  <!-- 바코드 -->
  <div style="text-align:center;padding:3mm 0 1mm">
    <?= $svg ?>
    <div class="tnum" style="font-size:13pt;font-weight:700;letter-spacing:.14em;margin-top:1mm">
      <?= h($sh['awb_no']) ?></div>
  </div>

  <!-- SHIPPER / CONSIGNEE -->
  <div class="blk" style="margin-top:2mm">
    <div class="cap">SHIPPER</div>
    <div class="val">
      <b><?= h($shipper['company_name'] ?? $sh['name_ko']) ?></b><br>
      <?= h($shipper['address'] ?? '-') ?>
      <?php if (!empty($shipper['phone'])): ?><br><span class="tnum">TEL <?= h($shipper['phone']) ?></span><?php endif; ?>
    </div>
  </div>

  <div class="blk" style="margin-top:1.5mm;flex-grow:1">
    <div class="cap">CONSIGNEE</div>
    <div class="val">
      <b style="font-size:10.5pt"><?= h($consignee['company_name'] ?? '-') ?></b><br>
      <?php if (!empty($consignee['contact_name'])): ?>
        ATTN. <?= h($consignee['contact_name']) ?><br><?php endif; ?>
      <?= h($consignee['address'] ?? '-') ?>
      <?php if (!empty($consignee['phone'])): ?><br><span class="tnum">TEL <?= h($consignee['phone']) ?></span><?php endif; ?>
    </div>
  </div>

  <!-- 도착지 · 중량 -->
  <div class="row" style="margin-top:1.5mm;gap:1.5mm">
    <div class="blk" style="flex:1.6">
      <div class="cap">DESTINATION</div>
      <div class="big" style="font-size:13pt"><?= h($sh['dest_city'] ?: '-') ?></div>
    </div>
    <div class="blk" style="flex:1;text-align:center">
      <div class="cap">WEIGHT</div>
      <div class="big"><?= h($wt) ?><span style="font-size:9pt"> kg</span></div>
    </div>
    <div class="blk" style="flex:.7;text-align:center">
      <div class="cap">PCS</div>
      <div class="big"><?= $sh['package_count'] !== null ? (int)$sh['package_count'] : '-' ?></div>
    </div>
  </div>

  <!-- 추적번호 -->
  <?php if ($trkSvg): ?>
  <div class="blk" style="margin-top:1.5mm;text-align:center">
    <div class="cap" style="text-align:left">TRACKING NO</div>
    <?= $trkSvg ?>
    <div class="tnum" style="font-size:9pt;font-weight:700;letter-spacing:.1em"><?= h($trk) ?></div>
  </div>
  <?php endif; ?>

  <!-- 바닥 -->
  <div class="row tnum" style="margin-top:1.5mm;font-size:7.5pt;color:#333">
    <div style="flex:1">DATE <?= h($sh['voucher_date']) ?>
      <?php if ($sh['mawb_no']): ?> · MAWB <?= h($sh['mawb_no']) ?><?php endif; ?></div>
    <div><?= $sh['trade_type'] === 'IMPORT' ? 'IMPORT' : 'EXPORT' ?></div>
  </div>
</div>
</body>
</html>
