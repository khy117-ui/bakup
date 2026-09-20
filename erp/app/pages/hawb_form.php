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
    'vol_weight' => '', 'vol_divisor' => 6000, 'declared_value' => '', 'description' => '', 'remark' => '',
    'payment_by' => 'SHIPPER', 'check_to' => 'CASH',
    'charge_payment' => '', 'charge_other' => '', 'charge_duty' => '', 'charge_total' => '',
    'batch_id' => 0, 'hsn' => '', 'actual_qty' => '', 'special_no' => '', 'homepage' => '',
    'consignee_zip' => '', 'pcc_no' => '', 'consignee_name_ko' => '', 'consignee_addr_ko' => '',
    'consignee_biz_no' => '', 'ecom_type' => '', 'order_no' => '', 'consignee_city' => '',
    'shipper_addr_cn' => '', 'shipper_credit_no' => '',
];
// 양식에 늘 같은 값이 들어가는 칸 (TUN · UNIW · 011 · 502 …) 은 미리 채워 둡니다
$blank = array_merge($blank, HAWB_DEFAULTS);

/** 폼에서 받는 칸 — 이 목록 그대로 저장합니다 */
const HAWB_FORM_FIELDS = [
    'house_no', 'master_no', 'sea_wb_no', 'ship_type', 'on_board_date', 'flight_no',
    'origin', 'via', 'destination',
    'shipper_name', 'shipper_addr', 'shipper_contact', 'shipper_phone',
    'consignee_name', 'consignee_addr', 'consignee_attn', 'consignee_phone',
    'pieces', 'packing', 'weight', 'dim_l', 'dim_w', 'dim_h', 'vol_weight', 'vol_divisor',
    'declared_value', 'description', 'remark', 'payment_by', 'check_to',
    'charge_payment', 'charge_other', 'charge_duty', 'charge_total',
    // 영문 통관목록 · 중문 적하목록 칸
    'hsn', 'actual_qty', 'warehouse', 'notify', 'trade_code', 'sender_country', 'use_type',
    'agent_code', 'special_no', 'homepage', 'allow_code', 'consignee_zip', 'pcc_no',
    'consignee_name_ko', 'consignee_addr_ko', 'consignee_biz_no', 'ecom_type', 'order_no',
    'consignee_city', 'shipper_addr_cn', 'shipper_city', 'shipper_country', 'shipper_credit_no',
    'decl_type', 'trade_mode', 'cn_unit', 'cn_currency', 'cn_origin',
];
/** 숫자로 저장하는 칸 — 빈 칸은 NULL 로 둡니다 */
const HAWB_NUM_FIELDS = ['pieces', 'weight', 'dim_l', 'dim_w', 'dim_h', 'vol_weight',
                         'declared_value', 'charge_payment', 'charge_other', 'charge_duty',
                         'charge_total', 'actual_qty', 'use_type'];
/** 품목 한 줄에서 받는 칸 */
const HAWB_ITEM_FIELDS = ['item_code', 'name_cn', 'name_en', 'spec', 'pieces', 'weight',
                          'qty', 'unit', 'amount', 'currency', 'origin_country'];
const HAWB_ITEM_NUM = ['pieces', 'weight', 'qty', 'amount'];

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
    $data['vol_divisor'] = array_key_exists((int)$data['vol_divisor'], HAWB_DIVISORS) ? (int)$data['vol_divisor'] : 6000;
    if ($data['on_board_date'] !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$data['on_board_date'])) {
        $data['on_board_date'] = null;
    }
    // 적어 두지 않았으면 가로×세로×높이÷나누는 수 로 채웁니다
    if ($data['vol_weight'] === null) {
        $data['vol_weight'] = hawb_vol_weight((float)$data['dim_l'], (float)$data['dim_w'],
                                              (float)$data['dim_h'], (int)$data['vol_divisor']);
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

            $batchId = (int)post('batch_id');
            if ($batchId > 0) {
                $chk = $pdo->prepare('SELECT id FROM hawb_batches WHERE id = ? AND business_entity_id = ? AND deleted_at IS NULL');
                $chk->execute([$batchId, $eid]);
                if (!$chk->fetchColumn()) { throw new RuntimeException('고른 항공편을 찾을 수 없습니다.'); }
            }
            $pdo->beginTransaction();
            if ($id > 0) {
                $set = [];
                foreach (HAWB_FORM_FIELDS as $f) { $set[] = "$f = ?"; }
                $params = array_values($data);
                $params[] = $batchId ?: null;
                $params[] = $_SESSION['admin_id'] ?? null;
                $params[] = $id;
                $params[] = $eid;
                $st = $pdo->prepare('UPDATE hawbs SET ' . implode(', ', $set)
                    . ', batch_id = ?, updated_by = ?, updated_at = NOW()
                       WHERE id = ? AND business_entity_id = ? AND deleted_at IS NULL');
                $st->execute($params);
                log_action('물류', 'UPDATE', 'hawbs', $id, (string)$data['house_no'], null, 'HAWB 수정');
                flash('HAWB ' . $data['house_no'] . ' 을 저장했습니다.');
            } else {
                $cols = array_merge(['business_entity_id', 'shipment_id', 'batch_id'], HAWB_FORM_FIELDS, ['created_by']);
                $ph   = implode(',', array_fill(0, count($cols), '?'));
                $params = array_merge([$eid, (int)post('shipment_id') ?: null, $batchId ?: null],
                                      array_values($data), [$_SESSION['admin_id'] ?? null]);
                $st = $pdo->prepare('INSERT INTO hawbs (' . implode(',', $cols) . ") VALUES ($ph)");
                $st->execute($params);
                $id = (int)$pdo->lastInsertId();
                log_action('물류', 'CREATE', 'hawbs', $id, (string)$data['house_no'], null, 'HAWB 등록');
                flash('HAWB ' . $data['house_no'] . ' 을 등록했습니다. [인쇄] 로 비엘을 뽑을 수 있습니다.');
            }
            // 품목 — 적은 줄로 통째로 바꿉니다 (중문 적하목록 · INVOICE 에 들어갑니다)
            $pdo->prepare('DELETE FROM hawb_items WHERE hawb_id = ?')->execute([$id]);
            $ins = $pdo->prepare('INSERT INTO hawb_items (hawb_id, line_no, ' . implode(',', HAWB_ITEM_FIELDS)
                . ') VALUES (?,?,' . implode(',', array_fill(0, count(HAWB_ITEM_FIELDS), '?')) . ')');
            $line = 0;
            foreach ((array)($_POST['item'] ?? []) as $it) {
                if (!is_array($it)) { continue; }
                $vals = [];
                foreach (HAWB_ITEM_FIELDS as $k) {
                    $x = trim((string)($it[$k] ?? ''));
                    $vals[] = in_array($k, HAWB_ITEM_NUM, true)
                        ? ($x === '' ? null : (float)str_replace(',', '', $x)) : ($x === '' ? null : $x);
                }
                // 이름이 비어 있으면 빈 줄로 봅니다
                if (($vals[1] ?? null) === null && ($vals[2] ?? null) === null) { continue; }
                $ins->execute(array_merge([$id, ++$line], $vals));
            }
            $pdo->commit();
            redirect('?p=hawb_form&id=' . $id);
        } catch (Throwable $e) {
            if (isset($pdo) && $pdo->inTransaction()) { $pdo->rollBack(); }
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
    $st = db()->prepare('SELECT * FROM hawb_items WHERE hawb_id = ? ORDER BY line_no, id');
    $st->execute([$id]);
    $items = $st->fetchAll();
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

if (!isset($items)) { $items = []; }
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $err !== '') {
    // 저장에 실패했으면 적어 둔 품목도 그대로 다시 보여 줍니다
    $items = [];
    foreach ((array)($_POST['item'] ?? []) as $it) { if (is_array($it)) { $items[] = $it; } }
}
if ($cur['batch_id'] ?? 0) { /* 이미 고른 항공편 */ } elseif (($qb = (int)query('batch_id', '0')) > 0) {
    $cur['batch_id'] = $qb;
}
$batches = db()->prepare("SELECT id, flight_date, flight_no, mawb_no FROM hawb_batches
                           WHERE business_entity_id = ? AND deleted_at IS NULL
                           ORDER BY flight_date DESC, id DESC LIMIT 50");
$batches->execute([$eid]);
$batches = $batches->fetchAll();

$v = static fn (string $k): string => h((string)($cur[$k] ?? ''));

layout_head($id > 0 ? 'HAWB 수정' : 'HAWB 등록', 'hawb_list');
?>
<div class="head">
  <h1><?= $id > 0 ? 'HAWB 수정' : 'HAWB 등록' ?></h1>
  <div class="crumb">물류관리 &gt; HAWB 발행 &gt; <?= $id > 0 ? '수정' : '등록' ?></div>
  <div class="right">
    <?php if ($id > 0): ?>
      <a class="btn pri" href="?p=hawb_print&amp;ids=<?= $id ?>" target="_blank">비엘 인쇄 · PDF</a>
      <a class="btn" href="?p=hawb_export&amp;type=inv&amp;id=<?= $id ?>">INVOICE 엑셀</a>
      <?php if ((int)($cur['batch_id'] ?? 0) > 0): ?>
        <a class="btn" href="?p=hawb_batches&amp;id=<?= (int)$cur['batch_id'] ?>">항공편 서류</a>
      <?php endif; ?>
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
      <div class="fw w2"><label for="bt">항공편 (적하목록)</label>
        <select id="bt" name="batch_id">
          <option value="">— 지정 안 함 —</option>
          <?php foreach ($batches as $bt): ?>
            <option value="<?= (int)$bt['id'] ?>"<?= (int)($cur['batch_id'] ?? 0) === (int)$bt['id'] ? ' selected' : '' ?>>
              <?= h($bt['flight_date'] . ' · ' . $bt['flight_no'] . ' · ' . $bt['mawb_no']) ?></option>
          <?php endforeach; ?></select></div>
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
    <span style="font-weight:400;color:var(--ink3)">부피중량 = 가로 × 세로 × 높이 ÷ 나누는 수 (비우면 자동 계산)</span></div>
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
      <div class="fw w1"><label for="vd">나누는 수</label>
        <select id="vd" name="vol_divisor" onchange="vol(true)">
          <?php foreach (HAWB_DIVISORS as $k => $lab): ?>
            <option value="<?= $k ?>"<?= (int)$cur['vol_divisor'] === $k ? ' selected' : '' ?>><?= h($lab) ?></option>
          <?php endforeach; ?></select></div>
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
  <div class="ch">품목 <span style="font-weight:400;color:var(--ink3)">중문 적하목록 · INVOICE 에 줄 단위로 들어갑니다</span>
    <button type="button" class="btn sm" style="margin-left:auto" id="additem">줄 추가</button></div>
  <div class="cb" style="overflow-x:auto">
    <table id="items">
      <thead><tr>
        <th style="width:110px">商品编号</th><th style="width:150px">중문 품명</th><th style="width:150px">영문 품명</th>
        <th style="width:110px">规格</th><th style="width:70px">件数</th><th style="width:80px">重量</th>
        <th style="width:70px">数量</th><th style="width:70px">单位</th><th style="width:90px">申报总价</th>
        <th style="width:70px">币制</th><th style="width:70px">原产国</th><th class="c" style="width:40px"></th>
      </tr></thead>
      <tbody>
      <?php $rowsN = max(count($items) + 1, 4);
      for ($i = 0; $i < $rowsN; $i++): $it = $items[$i] ?? []; ?>
        <tr>
          <td><input type="text" name="item[<?= $i ?>][item_code]" value="<?= h((string)($it['item_code'] ?? '')) ?>"></td>
          <td><input type="text" name="item[<?= $i ?>][name_cn]" value="<?= h((string)($it['name_cn'] ?? '')) ?>"></td>
          <td><input type="text" name="item[<?= $i ?>][name_en]" value="<?= h((string)($it['name_en'] ?? '')) ?>"></td>
          <td><input type="text" name="item[<?= $i ?>][spec]" value="<?= h((string)($it['spec'] ?? '')) ?>"></td>
          <td><input type="text" class="tnum" style="text-align:right" name="item[<?= $i ?>][pieces]" value="<?= h(hawb_num($it['pieces'] ?? '')) ?>"></td>
          <td><input type="text" class="tnum" style="text-align:right" name="item[<?= $i ?>][weight]" value="<?= h(hawb_num($it['weight'] ?? '')) ?>"></td>
          <td><input type="text" class="tnum" style="text-align:right" name="item[<?= $i ?>][qty]" value="<?= h(hawb_num($it['qty'] ?? '')) ?>"></td>
          <td><input type="text" name="item[<?= $i ?>][unit]" value="<?= h((string)($it['unit'] ?? $cur['cn_unit'])) ?>"></td>
          <td><input type="text" class="tnum" style="text-align:right" name="item[<?= $i ?>][amount]" value="<?= h(hawb_num($it['amount'] ?? '')) ?>"></td>
          <td><input type="text" name="item[<?= $i ?>][currency]" value="<?= h((string)($it['currency'] ?? $cur['cn_currency'])) ?>"></td>
          <td><input type="text" name="item[<?= $i ?>][origin_country]" value="<?= h((string)($it['origin_country'] ?? $cur['cn_origin'])) ?>"></td>
          <td class="c"><button type="button" class="btn sm rmitem" title="이 줄 비우기">×</button></td>
        </tr>
      <?php endfor; ?>
      </tbody>
    </table>
    <div style="font-size:11.5px;color:var(--ink2);margin-top:8px">
      중문·영문 품명이 둘 다 비면 저장하지 않습니다. 单位 <b>011</b>, 币制 <b>502</b>, 原产国 <b>133</b> 은 양식 기본값입니다.
    </div>
  </div>
</div>

<div class="card">
  <div class="ch">통관 정보 <span style="font-weight:400;color:var(--ink3)">영문 통관목록 · 중문 적하목록에 들어갑니다</span></div>
  <div class="cb">
    <div class="f" style="align-items:flex-end">
      <div class="fw w1"><label for="hsn">HSN (일련번호)</label>
        <input type="text" id="hsn" name="hsn" class="tnum" maxlength="20" value="<?= $v('hsn') ?>" placeholder="0001"></div>
      <div class="fw w1"><label for="aq">실제수량</label>
        <input type="text" id="aq" name="actual_qty" class="tnum" style="text-align:right" value="<?= $v('actual_qty') ?>"></div>
      <div class="fw w1"><label for="wh">WAREHOUSE</label>
        <input type="text" id="wh" name="warehouse" maxlength="20" value="<?= $v('warehouse') ?>"></div>
      <div class="fw w1"><label for="tc">거래코드</label>
        <select id="tc" name="trade_code">
          <?php foreach (HAWB_TRADE_CODES as $k => $lab): ?>
            <option value="<?= $k ?>"<?= (string)$cur['trade_code'] === $k ? ' selected' : '' ?>><?= h($lab) ?></option>
          <?php endforeach; ?></select></div>
      <div class="fw w1"><label for="ut">용도구분</label>
        <select id="ut" name="use_type">
          <?php foreach (HAWB_USE_TYPES as $k => $lab): ?>
            <option value="<?= $k ?>"<?= (int)$cur['use_type'] === $k ? ' selected' : '' ?>><?= h($lab) ?></option>
          <?php endforeach; ?></select></div>
      <div class="fw w1"><label for="sc">발송국가코드</label>
        <input type="text" id="sc" name="sender_country" maxlength="2" value="<?= $v('sender_country') ?>"></div>
    </div>
    <div class="f" style="align-items:flex-end;margin-top:8px">
      <div class="fw w1"><label for="pcc">개인통관고유부호</label>
        <input type="text" id="pcc" name="pcc_no" class="tnum" maxlength="30" value="<?= $v('pcc_no') ?>"></div>
      <div class="fw w1"><label for="cz">수하인 우편번호</label>
        <input type="text" id="cz" name="consignee_zip" class="tnum" maxlength="20" value="<?= $v('consignee_zip') ?>"></div>
      <div class="fw w1"><label for="cbn">수하인 사업자번호</label>
        <input type="text" id="cbn" name="consignee_biz_no" class="tnum" maxlength="30" value="<?= $v('consignee_biz_no') ?>"></div>
      <div class="fw w1"><label for="ck">수하인 한글 상호</label>
        <input type="text" id="ck" name="consignee_name_ko" maxlength="100" value="<?= $v('consignee_name_ko') ?>"></div>
      <div class="fw w2"><label for="cak">수하인 한글 주소</label>
        <input type="text" id="cak" name="consignee_addr_ko" maxlength="255" value="<?= $v('consignee_addr_ko') ?>"></div>
    </div>
    <div class="f" style="align-items:flex-end;margin-top:8px">
      <div class="fw w1"><label for="ac">주선업자부호</label>
        <input type="text" id="ac" name="agent_code" maxlength="20" value="<?= $v('agent_code') ?>"></div>
      <div class="fw w1"><label for="al">통관허용품목</label>
        <input type="text" id="al" name="allow_code" maxlength="20" value="<?= $v('allow_code') ?>"></div>
      <div class="fw w1"><label for="sn">특별통관 지정번호</label>
        <input type="text" id="sn" name="special_no" maxlength="30" value="<?= $v('special_no') ?>"></div>
      <div class="fw w1"><label for="et">전자상거래 유형</label>
        <select id="et" name="ecom_type">
          <?php foreach (HAWB_ECOM_TYPES as $k => $lab): ?>
            <option value="<?= $k ?>"<?= (string)$cur['ecom_type'] === (string)$k ? ' selected' : '' ?>><?= h($lab) ?></option>
          <?php endforeach; ?></select></div>
      <div class="fw w1"><label for="orn">주문번호</label>
        <input type="text" id="orn" name="order_no" maxlength="50" value="<?= $v('order_no') ?>"></div>
      <div class="fw w1"><label for="hp">홈페이지주소</label>
        <input type="text" id="hp" name="homepage" maxlength="150" value="<?= $v('homepage') ?>"></div>
    </div>
    <div class="f" style="align-items:flex-end;margin-top:8px">
      <div class="fw w1"><label for="nt">NOTIFY</label>
        <input type="text" id="nt" name="notify" maxlength="100" value="<?= $v('notify') ?>"></div>
      <div class="fw w1"><label for="cc">받는회사 도시 (중문)</label>
        <input type="text" id="cc" name="consignee_city" maxlength="50" value="<?= $v('consignee_city') ?>"></div>
      <div class="fw w2"><label for="sacn">보내는회사 주소 (중문)</label>
        <input type="text" id="sacn" name="shipper_addr_cn" maxlength="255" value="<?= $v('shipper_addr_cn') ?>"></div>
      <div class="fw w1"><label for="scr">发件公司社会信用代码</label>
        <input type="text" id="scr" name="shipper_credit_no" class="tnum" maxlength="40" value="<?= $v('shipper_credit_no') ?>"></div>
    </div>
    <div class="f" style="align-items:flex-end;margin-top:8px">
      <div class="fw w1"><label for="scy">发件人城市</label>
        <input type="text" id="scy" name="shipper_city" maxlength="40" value="<?= $v('shipper_city') ?>"></div>
      <div class="fw w1"><label for="sco">发件人国别</label>
        <input type="text" id="sco" name="shipper_country" maxlength="10" value="<?= $v('shipper_country') ?>"></div>
      <div class="fw w1"><label for="dt2">报关类别</label>
        <input type="text" id="dt2" name="decl_type" maxlength="10" value="<?= $v('decl_type') ?>"></div>
      <div class="fw w1"><label for="tmd">贸易方式</label>
        <input type="text" id="tmd" name="trade_mode" maxlength="10" value="<?= $v('trade_mode') ?>"></div>
      <div class="fw w1"><label for="cu">计量单位</label>
        <input type="text" id="cu" name="cn_unit" maxlength="10" value="<?= $v('cn_unit') ?>"></div>
      <div class="fw w1"><label for="ccy">币制</label>
        <input type="text" id="ccy" name="cn_currency" maxlength="10" value="<?= $v('cn_currency') ?>"></div>
      <div class="fw w1"><label for="cog">原产/消费国</label>
        <input type="text" id="cog" name="cn_origin" maxlength="10" value="<?= $v('cn_origin') ?>"></div>
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
// 부피중량 — 손으로 적어 두었으면 건드리지 않습니다 (나누는 수를 바꾸면 다시 계산합니다)
function vol(force){
  var el = document.getElementById('vw');
  if (el.dataset.touched && !force) { return; }
  var div = parseFloat(document.getElementById('vd').value) || 6000;
  var v = n(document.getElementById('dl').value) * n(document.getElementById('dw').value)
        * n(document.getElementById('dh').value) / div;
  if (force) { delete el.dataset.touched; }
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
// 품목 — 줄 추가 · 비우기
(function () {
  var tb = document.querySelector('#items tbody');
  document.getElementById('additem').addEventListener('click', function () {
    var last = tb.rows[tb.rows.length - 1], tr = last.cloneNode(true), n = tb.rows.length;
    tr.querySelectorAll('input').forEach(function (i) {
      i.name = i.name.replace(/item\[\d+\]/, 'item[' + n + ']');
      if (!/\[(unit|currency|origin_country)\]$/.test(i.name)) { i.value = ''; }
    });
    tb.appendChild(tr);
  });
  tb.addEventListener('click', function (e) {
    if (!e.target.classList.contains('rmitem')) { return; }
    e.target.closest('tr').querySelectorAll('input').forEach(function (i) {
      if (/\[(name_cn|name_en)\]$/.test(i.name) || !/\[(unit|currency|origin_country)\]$/.test(i.name)) { i.value = ''; }
    });
  });
})();
</script>
<?php layout_foot();
