<?php
declare(strict_types=1);

// 웹에서 직접 열면 실행되지 않게 막습니다 (nginx 면 .htaccess 가 무시됩니다)
if (!defined('APP_DIR')) { http_response_code(403); exit('Forbidden'); }

require_once APP_DIR . '/barcode.php';

/**
 * HAWB (House Air Waybill) — 항공 하우스 비엘.
 *
 * 엑셀로 쓰던 양식(세관 신고용 시트에 줄을 적으면 HAWB 시트가 채워지던 방식)을
 * 그대로 전산으로 옮긴 것입니다. 화면에 넣은 내용이 인쇄 양식에 들어가고,
 * 송장번호는 **CODE128** 바코드로 같이 찍힙니다.
 *
 * 부피중량은 가로 × 세로 × 높이 ÷ 6000 — 엑셀 양식의 식 그대로입니다.
 */

/** 서류 / 소포 구분 */
const HAWB_TYPES = ['DOCUMENT' => 'Document', 'PARCEL' => 'Parcel'];
/** 운임을 누가 내는지 */
const HAWB_PAYERS = ['SHIPPER' => 'Shipper', 'CONSIGNEE' => 'Consignee', 'THIRD' => 'Third Party'];
/** 결제 방법 */
const HAWB_CHECKS = ['CASH' => 'Cash', 'ONLINE' => 'On-line', 'CREDIT' => 'Credit'];

/** 표가 없으면 만듭니다 (세션당 한 번) */
function hawb_ensure_table(): void
{
    if (!empty($_SESSION['schema_hawb_v1'])) { return; }
    try {
        db()->exec("CREATE TABLE IF NOT EXISTS hawbs (
          id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
          business_entity_id BIGINT UNSIGNED NOT NULL,
          shipment_id        BIGINT UNSIGNED NULL          COMMENT '매출전표에서 만들었으면 그 전표',
          house_no           VARCHAR(50)  NOT NULL         COMMENT '송장번호 — 바코드로 찍힙니다',
          master_no          VARCHAR(50)  NULL             COMMENT 'Master Number',
          sea_wb_no          VARCHAR(50)  NULL             COMMENT 'SEA Way Bill No',
          ship_type          VARCHAR(10)  NOT NULL DEFAULT 'PARCEL' COMMENT 'DOCUMENT / PARCEL',
          on_board_date      DATE         NULL,
          flight_no          VARCHAR(30)  NULL,
          origin             VARCHAR(40)  NULL,
          via                VARCHAR(40)  NULL,
          destination        VARCHAR(40)  NULL,
          shipper_name       VARCHAR(150) NULL,
          shipper_addr       VARCHAR(500) NULL,
          shipper_contact    VARCHAR(100) NULL             COMMENT 'Send By',
          shipper_phone      VARCHAR(50)  NULL,
          consignee_name     VARCHAR(150) NULL,
          consignee_addr     VARCHAR(500) NULL,
          consignee_attn     VARCHAR(100) NULL             COMMENT 'Attention Of',
          consignee_phone    VARCHAR(50)  NULL,
          pieces             INT          NULL             COMMENT 'Pickup C/T',
          packing            VARCHAR(30)  NULL,
          weight             DECIMAL(10,2) NULL,
          dim_l              DECIMAL(10,2) NULL,
          dim_w              DECIMAL(10,2) NULL,
          dim_h              DECIMAL(10,2) NULL,
          vol_weight         DECIMAL(10,2) NULL            COMMENT 'L×W×H÷6000',
          declared_value     DECIMAL(15,2) NULL            COMMENT 'Value — 세관 신고가',
          description        VARCHAR(500) NULL             COMMENT 'Description of contents',
          remark             VARCHAR(500) NULL,
          payment_by         VARCHAR(12)  NOT NULL DEFAULT 'SHIPPER',
          check_to           VARCHAR(12)  NOT NULL DEFAULT 'CASH',
          charge_payment     DECIMAL(15,2) NULL,
          charge_other       DECIMAL(15,2) NULL,
          charge_duty        DECIMAL(15,2) NULL,
          charge_total       DECIMAL(15,2) NULL,
          print_count        INT UNSIGNED NOT NULL DEFAULT 0,
          printed_at         DATETIME     NULL,
          created_by         BIGINT UNSIGNED NULL,
          created_at         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
          updated_by         BIGINT UNSIGNED NULL,
          updated_at         DATETIME     NULL,
          deleted_at         DATETIME     NULL,
          PRIMARY KEY (id),
          UNIQUE KEY uq_hawb_no (business_entity_id, house_no),
          KEY ix_hawb_ship (shipment_id),
          KEY ix_hawb_date (business_entity_id, on_board_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='항공 하우스 비엘 (HAWB)'");
        $_SESSION['schema_hawb_v1'] = 1;
    } catch (PDOException $e) {
        error_log('HAWB 표 준비 실패: ' . $e->getMessage());
    }
}

/** 부피중량 = 가로 × 세로 × 높이 ÷ 6000 (엑셀 양식과 같은 식) */
function hawb_vol_weight(?float $l, ?float $w, ?float $h): ?float
{
    if (!$l || !$w || !$h) { return null; }
    return round($l * $w * $h / 6000, 2);
}

/** 숫자를 보기 좋게 — 8.40 → 8.4, 9.00 → 9 */
function hawb_num($v): string
{
    if ($v === null || $v === '') { return ''; }
    return rtrim(rtrim(number_format((float)$v, 2, '.', ','), '0'), '.');
}

/** 매출전표에서 값을 끌어옵니다 (없는 칸은 비워 둡니다) */
function hawb_from_shipment(int $shipmentId, int $entId): ?array
{
    $st = db()->prepare(
        'SELECT s.*, c.name_ko FROM shipments s
           JOIN companies c ON c.id = s.company_id
          WHERE s.id = ? AND s.business_entity_id = ? AND s.deleted_at IS NULL');
    $st->execute([$shipmentId, $entId]);
    $s = $st->fetch();
    if (!$s) { return null; }

    $st = db()->prepare('SELECT * FROM shipment_parties WHERE shipment_id = ?');
    $st->execute([$shipmentId]);
    $party = [];
    foreach ($st->fetchAll() as $p) { $party[$p['party_type']] = $p; }
    $sp = $party['SHIPPER'] ?? [];
    $cn = $party['CONSIGNEE'] ?? [];

    $addr = static function (array $p): string {
        $bits = array_filter([$p['address'] ?? null, $p['city'] ?? null,
                              $p['zipcode'] ?? null, $p['country'] ?? null]);
        return implode(', ', $bits);
    };

    return [
        'shipment_id'     => $shipmentId,
        'house_no'        => (string)$s['awb_no'],
        'master_no'       => $s['mawb_no'],
        'on_board_date'   => $s['ship_date'] ?: $s['voucher_date'],
        'origin'          => $s['origin_city'] ?: $s['origin_country'],
        'destination'     => $s['dest_city'] ?: $s['dest_country'],
        'shipper_name'    => $sp['company_name'] ?? ($s['trade_type'] === 'EXPORT' ? $s['name_ko'] : null),
        'shipper_addr'    => $sp ? $addr($sp) : null,
        'shipper_contact' => $sp['contact_name'] ?? null,
        'shipper_phone'   => $sp['phone'] ?? null,
        'consignee_name'  => $cn['company_name'] ?? ($s['trade_type'] === 'IMPORT' ? $s['name_ko'] : null),
        'consignee_addr'  => $cn ? $addr($cn) : null,
        'consignee_attn'  => $cn['contact_name'] ?? null,
        'consignee_phone' => $cn['phone'] ?? null,
        'pieces'          => $s['package_count'],
        'weight'          => $s['charge_weight'] ?: $s['actual_weight'],
    ];
}

/**
 * 엑셀(세관 신고용) 한 줄 → HAWB 값.
 * 엑셀에서 줄을 그대로 복사해 붙이면 탭으로 나뉜 칸이 이 순서로 들어옵니다.
 * A NO · B 송장번호 · C 보내는분 · D 주소 · E 받는분 · F 주소 · G 전화 · H 내용물
 * I 개수 · J 단위 · K 무게 · L 부피 · M 금액
 */
const HAWB_PASTE_COLS = ['', 'house_no', 'shipper_name', 'shipper_addr', 'consignee_name',
                         'consignee_addr', 'consignee_phone', 'description', 'pieces',
                         'packing', 'weight', '', 'declared_value'];

/** 붙여넣은 표(탭 구분) 를 줄 단위로 읽습니다 — 머리글 줄과 빈 줄은 건너뜁니다 */
function hawb_parse_paste(string $text): array
{
    $out = [];
    foreach (preg_split('/\r\n|\r|\n/', $text) as $line) {
        if (trim($line) === '') { continue; }
        $cells = preg_split('/\t/', $line);
        if (count($cells) < 3) {
            // 탭이 없으면 쉼표로 나뉜 CSV 로 봅니다
            $cells = str_getcsv($line);
        }
        $row = [];
        foreach (HAWB_PASTE_COLS as $i => $key) {
            if ($key === '') { continue; }
            $row[$key] = trim((string)($cells[$i] ?? ''));
        }
        // 머리글 줄 (송장번호 칸에 숫자가 아닌 글자) 과 번호만 있는 빈 줄은 건너뜁니다
        if ($row['house_no'] === '' || !preg_match('/[0-9]/', $row['house_no'])) { continue; }
        if ($row['shipper_name'] === '' && $row['consignee_name'] === '') { continue; }
        $out[] = $row;
    }
    return $out;
}

/**
 * 인쇄용 로고.
 * 사업자 관리에 올린 로고가 있으면 그것을, 없으면 전산에 들어 있는 GOODPOST 로고를 씁니다.
 */
function hawb_logo_src(?array $be = null): ?string
{
    $rel = (string)($be['logo_path'] ?? '');
    if (preg_match('/^logos\/[A-Za-z0-9_-]+\.(png|jpg|jpeg|webp)$/', $rel)) {
        require_once APP_DIR . '/filestore.php';
        $bin = fs_read('uploads', $rel);
        if ($bin !== null && $bin !== '') {
            $ext  = strtolower(pathinfo($rel, PATHINFO_EXTENSION));
            $mime = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'webp' => 'image/webp'][$ext];
            return 'data:' . $mime . ';base64,' . base64_encode($bin);
        }
    }
    foreach (['assets/hawb_logo.png', 'assets/logo.png'] as $file) {
        $abs = dirname(APP_DIR) . '/' . $file;
        if (is_file($abs)) {
            $data = @file_get_contents($abs);
            if ($data !== false) { return 'data:image/png;base64,' . base64_encode($data); }
        }
    }
    return null;
}

/**
 * HAWB 한 장 — 인쇄용 HTML.
 * 엑셀 양식의 칸 배치를 그대로 옮겼습니다 (왼쪽 보내는분 / 가운데 받는분 / 오른쪽 운송정보).
 */
function hawb_render_form(array $h, ?string $logo, array $be): string
{
    $box = static fn (?string $v): string => $v !== null && $v !== '' ? h($v) : '';
    $chk = static fn (bool $on): string => $on ? '■' : '□';

    $bc = trim((string)$h['house_no']) !== ''
        ? code128_svg((string)$h['house_no'], 46, 1.5, false) : '';

    $vol = $h['vol_weight'] !== null && $h['vol_weight'] !== ''
        ? $h['vol_weight'] : hawb_vol_weight((float)$h['dim_l'], (float)$h['dim_w'], (float)$h['dim_h']);

    $dims = trim(hawb_num($h['dim_l']) . ' x ' . hawb_num($h['dim_w']) . ' x ' . hawb_num($h['dim_h']));
    $company = trim((string)($be['name_en'] ?? '')) !== '' ? (string)$be['name_en'] : 'GOOD POST CO.,LTD.';
    $tel = trim((string)($be['phone'] ?? '')) !== '' ? (string)$be['phone'] : '02-6929-0666';
    $fax = trim((string)($be['fax'] ?? '')) !== '' ? (string)$be['fax'] : '02-6929-0667';

    ob_start(); ?>
<div class="hawb">
  <div class="hd">
    <div class="hd-l">
      <?php if ($logo): ?><img src="<?= $logo ?>" alt=""><?php endif; ?>
      <div class="hd-txt">
        <div class="co"><?= h($company) ?></div>
        <div class="tel">SEOUL TEL:82-<?= h(ltrim((string)$tel, '0')) ?> FAX:82-<?= h(ltrim((string)$fax, '0')) ?></div>
      </div>
    </div>
    <div class="hd-r">
      <div class="cell"><span class="lb">On Board Date</span>
        <span class="vl big"><?= $box($h['on_board_date']) ?></span></div>
      <div class="cell"><span class="lb">Flight No</span>
        <span class="vl big"><?= $box($h['flight_no']) ?></span></div>
    </div>
  </div>

  <div class="row3">
    <div class="c-l">
      <div class="cell tall">
        <span class="lb">From (Shipper)</span>
        <span class="vl name"><?= $box($h['shipper_name']) ?></span>
        <span class="vl addr"><?= $box($h['shipper_addr']) ?></span>
      </div>
      <div class="cell2">
        <div class="cell"><span class="lb">Send By</span><span class="vl"><?= $box($h['shipper_contact']) ?></span></div>
        <div class="cell"><span class="lb">Phone</span><span class="vl"><?= $box($h['shipper_phone']) ?></span></div>
      </div>
    </div>
    <div class="c-m">
      <div class="cell tall">
        <span class="lb">To (Consignee)</span>
        <span class="vl name"><?= $box($h['consignee_name']) ?></span>
        <span class="vl addr"><?= $box($h['consignee_addr']) ?></span>
      </div>
      <div class="cell2">
        <div class="cell"><span class="lb">Attention Of</span><span class="vl"><?= $box($h['consignee_attn']) ?></span></div>
        <div class="cell"><span class="lb">Phone</span><span class="vl"><?= $box($h['consignee_phone']) ?></span></div>
      </div>
    </div>
    <div class="c-r">
      <div class="cell2 chk">
        <div class="cell"><span class="mark"><?= $chk($h['ship_type'] === 'DOCUMENT') ?></span> Document</div>
        <div class="cell"><span class="mark"><?= $chk($h['ship_type'] !== 'DOCUMENT') ?></span> Parcel</div>
      </div>
      <div class="cell3">
        <div class="cell"><span class="lb">Origin</span><span class="vl"><?= $box($h['origin']) ?></span></div>
        <div class="cell"><span class="lb">Via</span><span class="vl"><?= $box($h['via']) ?></span></div>
        <div class="cell"><span class="lb">Destination</span><span class="vl"><?= $box($h['destination']) ?></span></div>
      </div>
      <div class="cell3">
        <div class="cell"><span class="lb">Pickup C/T</span><span class="vl"><?= $box($h['pieces'] !== null ? (string)(int)$h['pieces'] : '') ?></span></div>
        <div class="cell"><span class="lb">Packing</span><span class="vl"><?= $box($h['packing']) ?></span></div>
        <div class="cell"><span class="lb">Weight</span><span class="vl"><?= h(hawb_num($h['weight'])) ?><?= $h['weight'] !== null && $h['weight'] !== '' ? ' KG' : '' ?></span></div>
      </div>
      <div class="cell dim">
        <span class="lb">Dimension</span>
        <div class="dim3">
          <div><span class="lb">L</span><span class="vl"><?= h(hawb_num($h['dim_l'])) ?></span></div>
          <div><span class="lb">W</span><span class="vl"><?= h(hawb_num($h['dim_w'])) ?></span></div>
          <div><span class="lb">H</span><span class="vl"><?= h(hawb_num($h['dim_h'])) ?></span></div>
        </div>
        <div class="volline">
          <span><?= $dims !== 'x  x' ? h($dims) : '&nbsp;&nbsp;&nbsp;x&nbsp;&nbsp;&nbsp;x&nbsp;&nbsp;' ?> / 6000</span>
          <span class="vol">Volumetric Weight <b><?= h(hawb_num($vol)) ?></b> KG</span>
        </div>
      </div>
    </div>
  </div>

  <div class="row3">
    <div class="c-l">
      <div class="cell desc"><span class="lb">Description of contents</span>
        <span class="vl"><?= $box($h['description']) ?></span></div>
    </div>
    <div class="c-m">
      <div class="cell bc">
        <span class="lb">House No<?= $h['sea_wb_no'] ? ' / SEA Way Bill No' : '' ?></span>
        <div class="bcimg"><?= $bc ?></div>
        <div class="bcno"><?= $box($h['house_no']) ?></div>
        <?php if ($h['sea_wb_no']): ?><div class="bcsub">SEA W/B : <?= $box($h['sea_wb_no']) ?></div><?php endif; ?>
        <?php if ($h['master_no']): ?><div class="bcsub">Master : <?= $box($h['master_no']) ?></div><?php endif; ?>
      </div>
    </div>
    <div class="c-r">
      <div class="cell2 pay">
        <div class="cell"><span class="lb">Payment</span>
          <?php foreach (HAWB_PAYERS as $k => $lab): ?>
            <div class="opt"><span class="mark"><?= $chk($h['payment_by'] === $k) ?></span> <?= h($lab) ?></div>
          <?php endforeach; ?>
        </div>
        <div class="cell"><span class="lb">Check To</span>
          <?php foreach (HAWB_CHECKS as $k => $lab): ?>
            <div class="opt"><span class="mark"><?= $chk($h['check_to'] === $k) ?></span> <?= h($lab) ?></div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
  </div>

  <div class="row3">
    <div class="c-l">
      <div class="cell rem"><span class="lb">Remark &amp; Information</span>
        <span class="vl"><?= $box($h['remark']) ?></span></div>
      <div class="cell2 sig">
        <div class="cell"><span class="lb">Shipper's Signature</span><span class="sigbox"></span></div>
        <div class="cell"><span class="lb">Consignee's Signature</span><span class="sigbox"></span></div>
      </div>
      <div class="cell4">
        <div class="cell"><span class="lb">SENDER</span></div>
        <div class="cell"><span class="lb">PICK UP</span></div>
        <div class="cell"><span class="lb">DELIVERY</span></div>
        <div class="cell"><span class="lb">RECIPIENT</span></div>
      </div>
      <div class="cell2 dt">
        <div class="cell"><span class="lb">PICKUP DATE</span><span class="vl">&nbsp; / &nbsp; / 20&nbsp;&nbsp;&nbsp;
          <span class="ampm">□ AM &nbsp; □ PM</span></span></div>
        <div class="cell"><span class="lb">RECIPIENT DATE</span><span class="vl">&nbsp; / &nbsp; / 20&nbsp;&nbsp;&nbsp;
          <span class="ampm">□ AM &nbsp; □ PM</span></span></div>
      </div>
      <div class="terms">
        I/we agree that <?= h($company) ?> standard terms apply to this shipment and limit its liability for loss or damage
        to U.S$100.00. The Warsaw Convention may also apply (see reverse). I/we authorize <?= h($company) ?> to complete
        other documents. I/we agree to pay all charges if the recipient or third party does not pay.
      </div>
    </div>
    <div class="c-r wide">
      <div class="charges">
        <div><span class="lb">Payment Charge</span><span class="vl"><?= h(hawb_num($h['charge_payment'])) ?></span></div>
        <div><span class="lb">Other Charge</span><span class="vl"><?= h(hawb_num($h['charge_other'])) ?></span></div>
        <div><span class="lb">Duty &amp; Tax</span><span class="vl"><?= h(hawb_num($h['charge_duty'])) ?></span></div>
        <div class="tot"><span class="lb">Total Charge</span><span class="vl"><?= h(hawb_num($h['charge_total'])) ?></span></div>
      </div>
      <?php if ($h['declared_value'] !== null && $h['declared_value'] !== ''): ?>
        <div class="value">Declared Value : <?= h(hawb_num($h['declared_value'])) ?></div>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php
    return (string)ob_get_clean();
}

/** 인쇄 화면 공통 CSS */
function hawb_print_css(): string
{
    return <<<'CSS'
  @page { size: A4 portrait; margin: 8mm; }
  * { box-sizing: border-box; }
  body { margin: 0; font-family: "Malgun Gothic", "맑은 고딕", Arial, sans-serif;
         font-size: 9px; color: #000; background: #F2F4F6; }
  .sheet { width: 194mm; min-height: 138mm; margin: 6mm auto; background: #fff; padding: 0; }
  @media print { body { background: #fff; } .sheet { margin: 0; page-break-after: always; }
                 .sheet:last-child { page-break-after: auto; } .noprint { display: none !important; } }
  .hawb { border: 1.4px solid #000; }
  .hawb .lb { display: block; font-size: 7.5px; color: #333; letter-spacing: .2px; }
  .hawb .vl { display: block; font-size: 10.5px; font-weight: 700; min-height: 12px; word-break: break-word; }
  .hawb .cell { border-right: .8px solid #000; padding: 2px 4px; flex: 1; min-width: 0; }
  .hawb .cell:last-child { border-right: 0; }
  .hd { display: flex; border-bottom: 1.4px solid #000; }
  .hd-l { flex: 2; display: flex; align-items: center; gap: 8px; padding: 4px 8px; }
  .hd-l img { height: 13mm; width: auto; }
  .hd-txt .co { font-size: 15px; font-weight: 800; letter-spacing: .3px; }
  .hd-txt .tel { font-size: 9px; }
  .hd-r { flex: 1; display: flex; border-left: 1.4px solid #000; }
  .hd-r .vl.big { font-size: 12px; }
  .row3 { display: flex; border-bottom: .8px solid #000; }
  .row3:last-child { border-bottom: 0; }
  .c-l, .c-m { flex: 12; border-right: .8px solid #000; min-width: 0; }
  .c-m { flex: 13; }
  .c-r { flex: 12; min-width: 0; }
  .c-r.wide { flex: 12; }
  .cell2, .cell3, .cell4 { display: flex; border-top: .8px solid #000; }
  .cell.tall { min-height: 22mm; }
  .cell.tall .vl.name { font-size: 11.5px; }
  .cell.tall .vl.addr { font-weight: 400; font-size: 9px; margin-top: 2px; }
  .chk .cell { display: flex; align-items: center; gap: 4px; font-size: 9.5px; }
  .cell2.chk { border-top: 0; }
  .mark { font-size: 11px; }
  .dim { border-top: .8px solid #000; }
  .dim3 { display: flex; }
  .dim3 > div { flex: 1; border-right: .8px dotted #666; padding-right: 3px; }
  .dim3 > div:last-child { border-right: 0; }
  .volline { display: flex; justify-content: space-between; font-size: 8px; margin-top: 2px; border-top: .8px dotted #666; padding-top: 2px; }
  .cell.desc, .cell.rem { min-height: 17mm; }
  .cell.bc { text-align: center; min-height: 17mm; }
  .bcimg svg { max-width: 100%; height: 12mm; }
  .bcno { font-size: 13px; font-weight: 800; letter-spacing: 2px; }
  .bcsub { font-size: 8px; }
  .pay .cell { font-size: 9px; }
  .pay .opt { display: flex; gap: 4px; align-items: center; margin-top: 1px; }
  .sig .cell { min-height: 12mm; }
  .cell4 .cell { min-height: 9mm; }
  .dt .vl { font-weight: 400; }
  .ampm { font-size: 8px; }
  .terms { border-top: .8px solid #000; padding: 3px 4px; font-size: 6.6px; line-height: 1.35; color: #222; }
  .charges > div { display: flex; justify-content: space-between; align-items: center;
                   border-bottom: .8px solid #000; padding: 3px 5px; }
  .charges > div:last-child { border-bottom: 0; }
  .charges .lb { display: inline; font-size: 8.5px; }
  .charges .vl { display: inline; font-size: 11px; }
  .charges .tot { background: #EFEFEF; }
  .charges .tot .vl { font-size: 13px; }
  .value { padding: 3px 5px; font-size: 8.5px; border-top: .8px solid #000; }
CSS;
}
