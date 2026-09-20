<?php
// 웹에서 직접 열면 실행되지 않게 막습니다 (nginx 면 .htaccess 가 무시됩니다)
if (!defined('APP_DIR')) { http_response_code(403); exit('Forbidden'); }

require_once APP_DIR . '/layout.php';
require_once APP_DIR . '/hawb.php';

/**
 * HAWB 등록 · 수정.
 *
 * 엑셀 양식에서 손으로 채우던 칸을 그대로 옮겼습니다. 저장하면 인쇄 화면에서
 * 항공 비엘 모양으로 나오고, 송장번호는 CODE128 바코드로 찍힙니다.
 */

hawb_ensure_table();

$eid = entity_id();
$err = '';
$id  = (int)query('id', '0');

$blank = [
    'id' => 0, 'shipment_id' => null, 'house_no' => '', 'master_no' => '', 'sea_wb_no' => '',
    'ship_type' => 'PARCEL', 'on_board_date' => date('Y-m-d'), 'flight_no' => '',
    'origin' => '', 'via' => '', 'destination' => '',
    'shipper_name' => '', 'shipper_addr' => '', 'shipper_contact' => '', 'shipper_phone' => '',
    'consignee_name' => '', 'consignee_addr' => '', 'consignee_attn' => '', 'consignee_phone' => '',
    'pieces' => '', 'packing' => '', 'weight' => '', 'dim_l' => '', 'dim_w' => '', 'dim_h' => '',
    'vol_weight' => '', 'declared_value' => '', 'description' => '', 'remark' => '',
    'payment_by' => 'SHIPPER', 'check_to' => 'CASH',
    'charge_payment' => '', 'charge_other' => '', 'charge_duty' => '', 'charge_total' => '',
];

/** 폼에서 받는 칸 — 이 목록 그대로 저장합니다 */
const HAWB_FORM_FIELDS = [
    'house_no', 'master_no', 'sea_wb_no', 'ship_type', 'on_board_date', 'flight_no',
    'origin', 'via', 'destination',
    'shipper_name', 'shipper_addr', 'shipper_contact', 'shipper_phone',
    'consignee_name', 'consignee_addr', 'consignee_attn', 'consignee_phone',
    'pieces', 'packing', 'weight', 'dim_l', 'dim_w', 'dim_h', 'vol_weight',
    'declared_value', 'description', 'remark', 'payment_by', 'check_to',
    'charge_payment', 'charge_other', 'charge_duty', 'charge_total',
];
/** 숫자로 저장하는 칸 — 빈 칸은 NULL 로 둡니다 */
const HAWB_NUM_FIELDS = ['pieces', 'weight', 'dim_l', 'dim_w', 'dim_h', 'vol_weight',
                         'declared_value', 'charge_payment', 'charge_other', 'charge_duty', 'charge_total'];

// ---------------------------------------------------------------- 저장
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('act') === 'save') {
    csrf_check();
    $id   = (int)post('id');
    $data = [];
    foreach (HAWB_FORM_FIELDS as $f) {
        $v = trim(post($f));
        if (in_array($f, HAWB_NUM_FIELDS, true)) {
            $v = str_replace(',', '', $v);
            $data[$f] = $v === '' ? null : (float)$v;
        } else {
            $data[$f] = $v === '' ? null : $v;
        }
    }
    $data['ship_type']  = array_key_exists((string)$data['ship_type'], HAWB_TYPES) ? $data['ship_type'] : 'PARCEL';
    $data['payment_by'] = array_key_exists((string)$data['payment_by'], HAWB_PAYERS) ? $data['payment_by'] : 'SHIPPER';
    $data['check_to']   = array_key_exists((string)$data['check_to'], HAWB_CHECKS) ? $data['check_to'] : 'CASH';
    if ($data['on_board_date'] !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$data['on_board_date'])) {
        $data['on_board_date'] = null;
    }
    // 적어 두지 않았으면 가로×세로×높이÷6000 으로 채웁니다
    if ($data['vol_weight'] === null) {
        $data['vol_weight'] = hawb_vol_weight((float)$data['dim_l'], (float)$data['dim_w'], (float)$data['dim_h']);
    }

    if ($data['house_no'] === null) {
        $err = '송장번호(House No)를 입력하세요. 바코드가 이 번호로 만들어집니다.';
    } elseif (!preg_match('/^[\x20-\x7E]+$/', (string)$data['house_no'])) {
        $err = '송장번호는 영문·숫자·기호만 됩니다. 한글이 들어가면 바코드를 만들 수 없습니다.';
    } else {
        try {
            $pdo = db();
            $chk = $pdo->prepare('SELECT id FROM hawbs WHERE business_entity_id = ? AND house_no = ?
                                   AND deleted_at IS NULL AND id <> ?');
            $chk->execute([$eid, $data['house_no'], $id]);
            if ($chk->fetchColumn()) {
                throw new RuntimeException('같은 송장번호의 HAWB 가 이미 있습니다. 번호를 확인하세요.');
            }

            if ($id > 0) {
                $set = [];
                foreach (HAWB_FORM_FIELDS as $f) { $set[] = "$f = ?"; }
                $params = array_values($data);
                $params[] = $_SESSION['admin_id'] ?? null;
                $params[] = $id;
                $params[] = $eid;
                $st = $pdo->prepare('UPDATE hawbs SET ' . implode(', ', $set)
                    . ', updated_by = ?, updated_at = NOW() WHERE id = ? AND business_entity_id = ? AND deleted_at IS NULL');
                $st->execute($params);
                log_action('물류', 'UPDATE', 'hawbs', $id, (string)$data['house_no'], null, 'HAWB 수정');
                flash('HAWB ' . $data['house_no'] . ' 을 저장했습니다.');
            } else {
                $cols = array_merge(['business_entity_id', 'shipment_id'], HAWB_FORM_FIELDS, ['created_by']);
                $ph   = implode(',', array_fill(0, count($cols), '?'));
                $params = array_merge([$eid, (int)post('shipment_id') ?: null], array_values($data),
                                      [$_SESSION['admin_id'] ?? null]);
                $st = $pdo->prepare('INSERT INTO hawbs (' . implode(',', $cols) . ") VALUES ($ph)");
                $st->execute($params);
                $id = (int)$pdo->lastInsertId();
                log_action('물류', 'CREATE', 'hawbs', $id, (string)$data['house_no'], null, 'HAWB 등록');
                flash('HAWB ' . $data['house_no'] . ' 을 등록했습니다. [인쇄] 로 비엘을 뽑을 수 있습니다.');
            }
            redirect('?p=hawb_form&id=' . $id);
        } catch (Throwable $e) {
            if ($e instanceof RuntimeException) {
                $err = $e->getMessage();
            } else {
                error_log('HAWB 저장 실패: ' . $e->getMessage());
                $err = '저장하지 못했습니다.';
            }
        }
    }
}

// ---------------------------------------------------------------- 불러오기
$cur = $blank;
if ($id > 0) {
    $st = db()->prepare('SELECT * FROM hawbs WHERE id = ? AND business_entity_id = ? AND deleted_at IS NULL');
    $st->execute([$id, $eid]);
    $row = $st->fetch();
    if (!$row) {
        flash('HAWB 를 찾을 수 없습니다.');
        redirect('?p=hawb_list');
    }
    $cur = array_merge($blank, $row);
} elseif (($sid = (int)query('shipment_id', '0')) > 0) {
    // 매출전표에서 값을 끌어와 채웁니다 — 저장 전까지는 아무것도 바뀌지 않습니다
    $pre = hawb_from_shipment($sid, $eid);
    if ($pre) {
        $cur = array_merge($blank, array_filter($pre, static fn ($v) => $v !== null && $v !== ''));
        $cur['shipment_id'] = $sid;
    } else {
        $err = '그 전표를 찾을 수 없습니다.';
    }
}
// 저장에 실패했으면 적어 둔 값을 그대로 다시 보여 줍니다
if ($err !== '' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach (HAWB_FORM_FIELDS as $f) { $cur[$f] = post($f); }
    $cur['id'] = $id;
}

$v = static fn (string $k): string => h((string)($cur[$k] ?? ''));

layout_head($id > 0 ? 'HAWB 수정' : 'HAWB 등록', 'hawb_list');
?>
<div class="head">
  <h1><?= $id > 0 ? 'HAWB 수정' : 'HAWB 등록' ?></h1>
  <div class="crumb">물류관리 &gt; HAWB 발행 &gt; <?= $id > 0 ? '수정' : '등록' ?></div>
  <div class="right">
    <?php if ($id > 0): ?>
      <a class="btn pri" href="?p=hawb_print&amp;ids=<?= $id ?>" target="_blank">인쇄 · PDF</a>
    <?php endif; ?>
    <a class="btn" href="?p=hawb_list">목록</a>
  </div>
</div>

<?php if ($err !== ''): ?><div class="msg err"><?= h($err) ?></div><?php endif; ?>

<form method="post">
<?= csrf_field() ?>
<input type="hidden" name="act" value="save">
<input type="hidden" name="id" value="<?= (int)$cur['id'] ?>">
<input type="hidden" name="shipment_id" value="<?= (int)($cur['shipment_id'] ?? 0) ?>">

<div class="card">
  <div class="ch">운송 정보
    <span style="font-weight:400;color:var(--ink3)">송장번호가 바코드(CODE128)로 찍힙니다</span></div>
  <div class="cb">
    <div class="f" style="align-items:flex-end">
      <div class="fw w1"><label for="hn">송장번호 (House No) *</label>
        <input type="text" id="hn" name="house_no" class="tnum" required maxlength="50"
               value="<?= $v('house_no') ?>" placeholder="26091890"></div>
      <div class="fw w1"><label for="mn">Master Number</label>
        <input type="text" id="mn" name="master_no" class="tnum" maxlength="50" value="<?= $v('master_no') ?>"></div>
      <div class="fw w1"><label for="sw">SEA Way Bill No</label>
        <input type="text" id="sw" name="sea_wb_no" class="tnum" maxlength="50" value="<?= $v('sea_wb_no') ?>"></div>
      <div class="fw w1"><label for="st">구분</label>
        <select id="st" name="ship_type">
          <?php foreach (HAWB_TYPES as $k => $lab): ?>
            <option value="<?= $k ?>"<?= $cur['ship_type'] === $k ? ' selected' : '' ?>><?= h($lab) ?></option>
          <?php endforeach; ?></select></div>
    </div>
    <div class="f" style="align-items:flex-end;margin-top:8px">
      <div class="fw w1"><label for="ob">On Board Date</label>
        <input type="date" id="ob" name="on_board_date" value="<?= $v('on_board_date') ?>"></div>
      <div class="fw w1"><label for="fl">Flight No</label>
        <input type="text" id="fl" name="flight_no" maxlength="30" value="<?= $v('flight_no') ?>" placeholder="KE857"></div>
      <div class="fw w1"><label for="og">Origin</label>
        <input type="text" id="og" name="origin" maxlength="40" value="<?= $v('origin') ?>" placeholder="QINGDAO"></div>
      <div class="fw w1"><label for="vi">Via</label>
        <input type="text" id="vi" name="via" maxlength="40" value="<?= $v('via') ?>"></div>
      <div class="fw w1"><label for="de">Destination</label>
        <input type="text" id="de" name="destination" maxlength="40" value="<?= $v('destination') ?>" placeholder="SEOUL"></div>
    </div>
  </div>
</div>

<div class="card">
  <div class="ch">보내는 분 / 받는 분</div>
  <div class="cb">
    <div class="f">
      <div class="fw w2"><label for="sn">From (Shipper) — 상호 · 이름</label>
        <input type="text" id="sn" name="shipper_name" maxlength="150" value="<?= $v('shipper_name') ?>"></div>
      <div class="fw w2"><label for="cn">To (Consignee) — 상호 · 이름</label>
        <input type="text" id="cn" name="consignee_name" maxlength="150" value="<?= $v('consignee_name') ?>"></div>
    </div>
    <div class="f" style="margin-top:8px">
      <div class="fw w2"><label for="sa">보내는 분 주소</label>
        <textarea id="sa" name="shipper_addr" rows="3" maxlength="500"><?= $v('shipper_addr') ?></textarea></div>
      <div class="fw w2"><label for="ca">받는 분 주소</label>
        <textarea id="ca" name="consignee_addr" rows="3" maxlength="500"><?= $v('consignee_addr') ?></textarea></div>
    </div>
    <div class="f" style="align-items:flex-end;margin-top:8px">
      <div class="fw w1"><label for="sc">Send By (담당자)</label>
        <input type="text" id="sc" name="shipper_contact" maxlength="100" value="<?= $v('shipper_contact') ?>"></div>
      <div class="fw w1"><label for="sp">보내는 분 전화</label>
        <input type="text" id="sp" name="shipper_phone" maxlength="50" value="<?= $v('shipper_phone') ?>"></div>
      <div class="fw w1"><label for="cat">Attention Of (받는 담당자)</label>
        <input type="text" id="cat" name="consignee_attn" maxlength="100" value="<?= $v('consignee_attn') ?>"></div>
      <div class="fw w1"><label for="cp">받는 분 전화</label>
        <input type="text" id="cp" name="consignee_phone" maxlength="50" value="<?= $v('consignee_phone') ?>"></div>
    </div>
  </div>
</div>

<div class="card">
  <div class="ch">화물
    <span style="font-weight:400;color:var(--ink3)">부피중량 = 가로 × 세로 × 높이 ÷ 6000 (비우면 자동)</span></div>
  <div class="cb">
    <div class="f" style="align-items:flex-end">
      <div class="fw w1"><label for="pc">개수 (Pickup C/T)</label>
        <input type="text" id="pc" name="pieces" class="tnum" style="text-align:right" value="<?= $v('pieces') ?>"></div>
      <div class="fw w1"><label for="pk">Packing</label>
        <input type="text" id="pk" name="packing" maxlength="30" value="<?= $v('packing') ?>" placeholder="CTN / BOX"></div>
      <div class="fw w1"><label for="wt">실중량 (KG)</label>
        <input type="text" id="wt" name="weight" class="tnum" style="text-align:right" value="<?= $v('weight') ?>"></div>
      <div class="fw w1"><label for="dv">Value (신고가)</label>
        <input type="text" id="dv" name="declared_value" class="tnum" style="text-align:right" value="<?= $v('declared_value') ?>"></div>
    </div>
    <div class="f" style="align-items:flex-end;margin-top:8px">
      <div class="fw w1"><label for="dl">가로 L (cm)</label>
        <input type="text" id="dl" name="dim_l" class="tnum" style="text-align:right" value="<?= $v('dim_l') ?>" oninput="vol()"></div>
      <div class="fw w1"><label for="dw">세로 W (cm)</label>
        <input type="text" id="dw" name="dim_w" class="tnum" style="text-align:right" value="<?= $v('dim_w') ?>" oninput="vol()"></div>
      <div class="fw w1"><label for="dh">높이 H (cm)</label>
        <input type="text" id="dh" name="dim_h" class="tnum" style="text-align:right" value="<?= $v('dim_h') ?>" oninput="vol()"></div>
      <div class="fw w1"><label for="vw">부피중량 (KG)</label>
        <input type="text" id="vw" name="vol_weight" class="tnum" style="text-align:right" value="<?= $v('vol_weight') ?>"></div>
    </div>
    <div class="f" style="margin-top:8px">
      <div class="fw w2"><label for="ds">Description of contents</label>
        <textarea id="ds" name="description" rows="2" maxlength="500"><?= $v('description') ?></textarea></div>
      <div class="fw w2"><label for="rm">Remark &amp; Information</label>
        <textarea id="rm" name="remark" rows="2" maxlength="500"><?= $v('remark') ?></textarea></div>
    </div>
  </div>
</div>

<div class="card">
  <div class="ch">운임</div>
  <div class="cb">
    <div class="f" style="align-items:flex-end">
      <div class="fw w1"><label for="pb">Payment (누가 냄)</label>
        <select id="pb" name="payment_by">
          <?php foreach (HAWB_PAYERS as $k => $lab): ?>
            <option value="<?= $k ?>"<?= $cur['payment_by'] === $k ? ' selected' : '' ?>><?= h($lab) ?></option>
          <?php endforeach; ?></select></div>
      <div class="fw w1"><label for="ct">Check To (결제 방법)</label>
        <select id="ct" name="check_to">
          <?php foreach (HAWB_CHECKS as $k => $lab): ?>
            <option value="<?= $k ?>"<?= $cur['check_to'] === $k ? ' selected' : '' ?>><?= h($lab) ?></option>
          <?php endforeach; ?></select></div>
      <div class="fw w1"><label for="c1">Payment Charge</label>
        <input type="text" id="c1" name="charge_payment" class="tnum" style="text-align:right" value="<?= $v('charge_payment') ?>" oninput="sum()"></div>
      <div class="fw w1"><label for="c2">Other Charge</label>
        <input type="text" id="c2" name="charge_other" class="tnum" style="text-align:right" value="<?= $v('charge_other') ?>" oninput="sum()"></div>
      <div class="fw w1"><label for="c3">Duty &amp; Tax</label>
        <input type="text" id="c3" name="charge_duty" class="tnum" style="text-align:right" value="<?= $v('charge_duty') ?>" oninput="sum()"></div>
      <div class="fw w1"><label for="c4">Total Charge</label>
        <input type="text" id="c4" name="charge_total" class="tnum" style="text-align:right;font-weight:700" value="<?= $v('charge_total') ?>"></div>
    </div>
  </div>
</div>

<div style="display:flex;gap:8px;align-items:center">
  <button class="btn pri"><?= $id > 0 ? '저장' : '등록' ?></button>
  <a class="btn" href="?p=hawb_list">취소</a>
  <span style="font-size:11.5px;color:var(--ink3)">저장한 뒤 [인쇄 · PDF] 를 누르면 비엘 양식으로 나옵니다.</span>
</div>
</form>

<script>
function n(v){ v = (v||'').toString().replace(/[^0-9.]/g,''); return v === '' ? 0 : parseFloat(v); }
// 부피중량 — 손으로 적어 두었으면 건드리지 않습니다
function vol(){
  var el = document.getElementById('vw');
  if (el.dataset.touched) { return; }
  var v = n(document.getElementById('dl').value) * n(document.getElementById('dw').value)
        * n(document.getElementById('dh').value) / 6000;
  el.value = v > 0 ? (Math.round(v * 100) / 100) : '';
}
document.getElementById('vw').addEventListener('input', function(){ this.dataset.touched = '1'; });
function sum(){
  var t = document.getElementById('c4');
  if (t.dataset.touched) { return; }
  var v = n(document.getElementById('c1').value) + n(document.getElementById('c2').value)
        + n(document.getElementById('c3').value);
  t.value = v > 0 ? v.toLocaleString('ko-KR') : '';
}
document.getElementById('c4').addEventListener('input', function(){ this.dataset.touched = '1'; });
</script>
<?php layout_foot();
