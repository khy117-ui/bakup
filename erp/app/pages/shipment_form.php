<?php
require_once APP_DIR . '/layout.php';

$eid = entity_id();
$err = '';
$id  = (int)query('id', '0');          // 0 이면 신규, 그 외는 수정
$cur = null;                            // 수정 대상 원본

$CHARGE = ['AIR_FREIGHT' => '특송운임', 'DOMESTIC' => '국내운송',
           'HANDLING' => '취급수수료', 'CUSTOMS' => '통관료',
           'STORAGE' => '창고료', 'OTHER' => '기타'];
$TAX    = ['ZERO' => 0.0, 'TAXABLE' => 10.0, 'EXEMPT' => 0.0];
$MAXLINE = 8;

$companies = db()->prepare(
    'SELECT id, company_code, name_ko FROM companies
      WHERE deleted_at IS NULL AND trade_status <> \'CLOSED\' ORDER BY name_ko');
$companies->execute();
$companies = $companies->fetchAll();

$carriers = db()->query(
    'SELECT id, code, name, default_tax_type FROM carriers
      WHERE is_active = 1 ORDER BY sort_order, code')->fetchAll();

$in = [
    'company_id' => '', 'carrier_id' => '', 'voucher_date' => date('Y-m-d'),
    'ship_date' => '', 'trade_type' => 'EXPORT', 'dest_city' => '',
    'actual_weight' => '', 'volume_weight' => '', 'package_count' => '',
    'remark' => '', 'sales_team' => '', 'sales_rep' => '', 'status' => 'CONFIRMED',
];
$lines = [];
for ($i = 0; $i < $MAXLINE; $i++) {
    $lines[] = ['charge_type' => $i === 0 ? 'AIR_FREIGHT' : 'OTHER', 'item_name' => '',
                'supply_amount' => '', 'tax_type' => 'ZERO'];
}
$reason = '';

// ---------------------------------------------------------------- 기존 전표 읽기
if ($id > 0) {
    $st = db()->prepare(
        'SELECT * FROM shipments
          WHERE id = ? AND business_entity_id = ? AND deleted_at IS NULL');
    $st->execute([$id, $eid]);
    $cur = $st->fetch();
    if (!$cur) {
        exit('전표를 찾을 수 없습니다.');
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        foreach (array_keys($in) as $k) {
            if (array_key_exists($k, $cur)) {
                $in[$k] = (string)($cur[$k] ?? '');
            }
        }
        $st = db()->prepare(
            'SELECT charge_type, item_name, supply_amount, tax_type
               FROM shipment_charges WHERE shipment_id = ? ORDER BY line_no');
        $st->execute([$id]);
        $old = $st->fetchAll();
        foreach ($old as $i => $o) {
            if ($i >= $MAXLINE) {
                break;
            }
            $lines[$i] = [
                'charge_type'   => $o['charge_type'],
                'item_name'     => $o['item_name'],
                'supply_amount' => (string)(int)round((float)$o['supply_amount']),
                'tax_type'      => $o['tax_type'],
            ];
        }
    }
}

/** 전표 한 건의 현재 상태를 한 줄로. 변경 전후 비교에 씁니다 */
function snapshot(int $sid): string
{
    $st = db()->prepare(
        'SELECT s.voucher_date, s.trade_type, s.status, s.charge_weight,
                c.name_ko, ca.code AS carrier
           FROM shipments s
           JOIN companies c  ON c.id = s.company_id
           JOIN carriers  ca ON ca.id = s.carrier_id
          WHERE s.id = ?');
    $st->execute([$sid]);
    $h = $st->fetch() ?: [];
    $st = db()->prepare(
        'SELECT line_no, charge_type, item_name, supply_amount, tax_type, tax_amount
           FROM shipment_charges WHERE shipment_id = ? ORDER BY line_no');
    $st->execute([$sid]);
    $parts = [];
    $sum = 0;
    foreach ($st->fetchAll() as $r) {
        $parts[] = sprintf('%s/%s/%s/%s', $r['charge_type'], $r['item_name'],
                           (int)round((float)$r['supply_amount']), $r['tax_type']);
        $sum += (float)$r['supply_amount'] + (float)$r['tax_amount'];
    }
    return sprintf('%s %s %s 중량%s 거래처=%s 운송사=%s 합계=%s [%s]',
        $h['voucher_date'] ?? '', $h['trade_type'] ?? '', $h['status'] ?? '',
        $h['charge_weight'] ?? '-', $h['name_ko'] ?? '', $h['carrier'] ?? '',
        number_format($sum), implode(' | ', $parts));
}

// ---------------------------------------------------------------- 취소
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('act') === 'cancel' && $id > 0) {
    csrf_check();
    $why = post('cancel_reason');
    if (mb_strlen($why) < 2) {
        $err = '취소 사유를 적어 주세요.';
    } else {
        $before = snapshot($id);
        db()->prepare('UPDATE shipments SET status = \'CANCELLED\' WHERE id = ? AND business_entity_id = ?')
            ->execute([$id, $eid]);
        log_action('매출전표', 'CANCEL', 'shipments', $id, (string)$cur['awb_no'],
                   $before, snapshot($id), $why);
        flash('전표 ' . $cur['awb_no'] . ' 을 취소했습니다. 삭제한 것이 아니라 상태만 바뀌었습니다.');
        redirect('?p=shipments');
    }
}

// ---------------------------------------------------------------- 저장
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('act') !== 'cancel') {
    csrf_check();
    foreach (array_keys($in) as $k) {
        if ($k !== 'status') {
            $in[$k] = post($k);
        }
    }
    $reason = post('reason');

    $postLines = $_POST['line'] ?? [];
    if (is_array($postLines)) {
        foreach ($postLines as $i => $l) {
            if (!isset($lines[$i]) || !is_array($l)) {
                continue;
            }
            $lines[$i] = [
                'charge_type'   => (string)($l['charge_type'] ?? 'OTHER'),
                'item_name'     => trim((string)($l['item_name'] ?? '')),
                'supply_amount' => (string)($l['supply_amount'] ?? ''),
                'tax_type'      => (string)($l['tax_type'] ?? 'ZERO'),
            ];
        }
    }

    $valid = [];
    foreach ($lines as $l) {
        if ($l['item_name'] === '' && num($l['supply_amount']) == 0.0) {
            continue;
        }
        if (!isset($CHARGE[$l['charge_type']])) {
            $err = '비용항목의 종류가 올바르지 않습니다.';
            break;
        }
        if (!isset($TAX[$l['tax_type']])) {
            $err = '세금구분이 올바르지 않습니다.';
            break;
        }
        if ($l['item_name'] === '') {
            $err = '금액이 있는 항목에는 항목명이 필요합니다.';
            break;
        }
        $valid[] = $l;
    }

    if ($err === '') {
        if ($in['company_id'] === '' || $in['carrier_id'] === '') {
            $err = '거래처와 운송사를 선택하세요.';
        } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $in['voucher_date'])) {
            $err = '전표일을 입력하세요.';
        } elseif ($in['ship_date'] !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $in['ship_date'])) {
            $err = '발송일 형식이 올바르지 않습니다.';
        } elseif (!$valid) {
            $err = '비용항목을 최소 한 줄 입력하세요.';
        } elseif ($id > 0 && mb_strlen($reason) < 2) {
            // 금액이 걸린 화면이라 수정에는 사유를 남깁니다 (스펙 [45])
            $err = '수정 사유를 적어 주세요. 작업로그에 남습니다.';
        }
    }

    if ($err === '') {
        $pdo = db();
        try {
            $pdo->beginTransaction();

            $aw = num($in['actual_weight']);
            $vw = num($in['volume_weight']);
            $cw = max($aw, $vw);
            $trade = $in['trade_type'] === 'IMPORT' ? 'IMPORT' : 'EXPORT';

            if ($id > 0) {
                $before = snapshot($id);
                $st = $pdo->prepare(
                    'UPDATE shipments
                        SET company_id = ?, carrier_id = ?, voucher_date = ?, ship_date = ?,
                            trade_type = ?, dest_city = ?, actual_weight = ?, volume_weight = ?,
                            charge_weight = ?, package_count = ?, sales_team = ?, sales_rep = ?,
                            remark = ?, updated_by = ?
                      WHERE id = ? AND business_entity_id = ?');
                $st->execute([
                    (int)$in['company_id'], (int)$in['carrier_id'], $in['voucher_date'],
                    $in['ship_date'] !== '' ? $in['ship_date'] : null, $trade,
                    $in['dest_city'] !== '' ? $in['dest_city'] : null,
                    $aw > 0 ? $aw : null, $vw > 0 ? $vw : null, $cw > 0 ? $cw : null,
                    $in['package_count'] !== '' ? (int)$in['package_count'] : null,
                    $in['sales_team'] !== '' ? $in['sales_team'] : null,
                    $in['sales_rep'] !== '' ? $in['sales_rep'] : null,
                    $in['remark'] !== '' ? $in['remark'] : null,
                    $_SESSION['admin_id'] ?? null, $id, $eid,
                ]);
                // 비용항목은 지우고 다시 넣습니다.
                // 지우기 전 행은 트리거가 shipments_history 에 남깁니다
                $pdo->prepare('DELETE FROM shipment_charges WHERE shipment_id = ?')
                    ->execute([$id]);
                $sid = $id;
                $awb = (string)$cur['awb_no'];
            } else {
                $awb = next_doc_no('AWB', 'GPA');
                $st = $pdo->prepare(
                    'INSERT INTO shipments
                       (business_entity_id, company_id, awb_no, awb_source, voucher_date,
                        ship_date, trade_type, carrier_id, dest_city,
                        actual_weight, volume_weight, charge_weight, package_count,
                        status, sales_team, sales_rep, remark, created_by)
                     VALUES (?,?,?,\'HOUSE\',?,?,?,?,?,?,?,?,?,\'CONFIRMED\',?,?,?,?)');
                $st->execute([
                    $eid, (int)$in['company_id'], $awb, $in['voucher_date'],
                    $in['ship_date'] !== '' ? $in['ship_date'] : null, $trade,
                    (int)$in['carrier_id'],
                    $in['dest_city'] !== '' ? $in['dest_city'] : null,
                    $aw > 0 ? $aw : null, $vw > 0 ? $vw : null, $cw > 0 ? $cw : null,
                    $in['package_count'] !== '' ? (int)$in['package_count'] : null,
                    $in['sales_team'] !== '' ? $in['sales_team'] : null,
                    $in['sales_rep'] !== '' ? $in['sales_rep'] : null,
                    $in['remark'] !== '' ? $in['remark'] : null,
                    $_SESSION['admin_id'] ?? null,
                ]);
                $sid = (int)$pdo->lastInsertId();
                $before = null;
            }

            $ins = $pdo->prepare(
                'INSERT INTO shipment_charges
                   (shipment_id, line_no, charge_type, item_name, qty, unit_price,
                    supply_amount, tax_type, tax_rate, tax_amount, total_amount)
                 VALUES (?,?,?,?,1,?,?,?,?,?,?)');
            $no = 0;
            foreach ($valid as $l) {
                $no++;
                $supply = round(num($l['supply_amount']));
                $rate   = $TAX[$l['tax_type']];
                $tax    = round($supply * $rate / 100);
                $ins->execute([$sid, $no, $l['charge_type'], $l['item_name'],
                               $supply, $supply, $l['tax_type'], $rate, $tax,
                               $supply + $tax]);
            }

            $after = snapshot($sid);
            log_action('매출전표', $id > 0 ? 'UPDATE' : 'CREATE', 'shipments', $sid, $awb,
                       $before, $after, $id > 0 ? $reason : null);
            $pdo->commit();
            flash('매출전표 ' . $awb . ' 을 ' . ($id > 0 ? '수정' : '등록') . '했습니다.');
            redirect('?p=shipments');
        } catch (PDOException $e) {
            $pdo->rollBack();
            error_log('전표 저장 실패: ' . $e->getMessage());
            $err = '저장하지 못했습니다. 입력값을 확인하세요.';
        }
    }
}

$title = $id > 0 ? '매출전표 수정' : '매출전표 등록';
layout_head($title, 'shipments');
?>
<div class="head">
  <h1><?= h($title) ?></h1>
  <div class="crumb">물류관리 &gt; 매출전표 &gt; <?= $id > 0 ? '수정' : '등록' ?></div>
  <div class="right">
    <?php if ($id > 0): ?>
      <span class="badge b-info tnum" style="height:28px"><?= h($cur['awb_no']) ?></span>
    <?php endif; ?>
    <a class="btn" href="?p=shipments">목록</a>
  </div>
</div>

<?php if ($err !== ''): ?><div class="msg err"><?= h($err) ?></div><?php endif; ?>

<?php if ($id > 0 && ($cur['status'] ?? '') === 'CANCELLED'): ?>
  <div class="msg err">이 전표는 <b>취소</b> 상태입니다. 통계에는 그대로 집계되니 필요하면
    항목을 0 으로 고치거나 회계 담당과 상의하세요.</div>
<?php endif; ?>

<form method="post">
<?= csrf_field() ?>
<input type="hidden" name="act" value="save">

<div class="card">
  <div class="ch">전표 정보
    <span style="font-weight:400;color:var(--ink3)">
      <?= $id > 0 ? 'AWB 번호는 바뀌지 않습니다' : 'AWB 번호는 저장할 때 자동으로 부여됩니다' ?></span>
  </div>
  <div class="cb f">
    <div class="fw w3"><label>거래처 *</label>
      <select name="company_id" required>
        <option value="">선택하세요</option>
        <?php foreach ($companies as $c): ?>
          <option value="<?= (int)$c['id'] ?>"<?= (string)$in['company_id']===(string)$c['id']?' selected':'' ?>>
            <?= h($c['name_ko']) ?> (<?= h($c['company_code']) ?>)</option>
        <?php endforeach; ?>
      </select>
      <?php if (!$companies): ?>
        <small style="color:var(--err-fg)">거래처가 없습니다. <a href="?p=company_form">먼저 등록</a>하세요.</small>
      <?php endif; ?>
    </div>
    <div class="fw w1"><label>운송사 *</label>
      <select name="carrier_id" required>
        <option value="">선택</option>
        <?php foreach ($carriers as $c): ?>
          <option value="<?= (int)$c['id'] ?>"<?= (string)$in['carrier_id']===(string)$c['id']?' selected':'' ?>><?= h($c['code']) ?></option>
        <?php endforeach; ?>
      </select></div>
    <div class="fw w1"><label>구분</label>
      <select name="trade_type">
        <option value="EXPORT"<?= $in['trade_type']==='EXPORT'?' selected':'' ?>>수출</option>
        <option value="IMPORT"<?= $in['trade_type']==='IMPORT'?' selected':'' ?>>수입</option>
      </select></div>
    <div class="fw w1"><label>전표일 *</label>
      <input type="date" name="voucher_date" required value="<?= h($in['voucher_date']) ?>"></div>
    <div class="fw w1"><label>발송일</label>
      <input type="date" name="ship_date" value="<?= h($in['ship_date']) ?>"></div>
    <div class="fw w2"><label>도착지</label>
      <input type="text" name="dest_city" value="<?= h($in['dest_city']) ?>" placeholder="LOS ANGELES"></div>
  </div>
  <div class="cb f" style="border-top:1px solid var(--line2)">
    <div class="fw w1"><label>실중량 (kg)</label>
      <input type="text" name="actual_weight" class="tnum" value="<?= h($in['actual_weight']) ?>"></div>
    <div class="fw w1"><label>부피중량 (kg)</label>
      <input type="text" name="volume_weight" class="tnum" value="<?= h($in['volume_weight']) ?>"></div>
    <div class="fw w1"><label>수량 (PCS)</label>
      <input type="text" name="package_count" class="tnum" value="<?= h($in['package_count']) ?>"></div>
    <div class="fw w1"><label>영업팀</label>
      <input type="text" name="sales_team" value="<?= h($in['sales_team']) ?>"></div>
    <div class="fw w1"><label>영업담당자</label>
      <input type="text" name="sales_rep" value="<?= h($in['sales_rep']) ?>"></div>
    <div class="fw gr" style="min-width:260px"><label>비고</label>
      <input type="text" name="remark" value="<?= h($in['remark']) ?>"></div>
  </div>
  <div class="cb" style="border-top:1px solid var(--line2);font-size:11.5px;color:var(--ink3)">
    청구중량은 실중량과 부피중량 중 큰 값이 자동으로 들어갑니다.
  </div>
</div>

<div class="card">
  <div class="ch">비용항목
    <span style="font-weight:400;color:var(--ink3)">세금구분은 줄마다 따로 정합니다 — 한 전표에 영세와 과세가 섞일 수 있습니다</span>
  </div>
  <table>
    <thead><tr>
      <th style="width:40px" class="c">#</th>
      <th style="width:150px">종류</th>
      <th>항목명</th>
      <th style="width:150px" class="r">공급가액</th>
      <th style="width:120px">세금구분</th>
    </tr></thead>
    <tbody>
    <?php foreach ($lines as $i => $l): ?>
      <tr>
        <td class="c tnum"><?= $i+1 ?></td>
        <td><select name="line[<?= $i ?>][charge_type]">
          <?php foreach ($CHARGE as $k=>$v): ?>
            <option value="<?= h($k) ?>"<?= $l['charge_type']===$k?' selected':'' ?>><?= h($v) ?></option>
          <?php endforeach; ?>
        </select></td>
        <td><input type="text" name="line[<?= $i ?>][item_name]" value="<?= h($l['item_name']) ?>"
                   placeholder="<?= $i===0 ? 'EXPRESS WORLDWIDE' : '' ?>"></td>
        <td><input type="text" class="tnum" style="text-align:right"
                   name="line[<?= $i ?>][supply_amount]" value="<?= h($l['supply_amount']) ?>"></td>
        <td><select name="line[<?= $i ?>][tax_type]">
          <option value="ZERO"<?= $l['tax_type']==='ZERO'?' selected':'' ?>>영세율 0%</option>
          <option value="TAXABLE"<?= $l['tax_type']==='TAXABLE'?' selected':'' ?>>과세 10%</option>
          <option value="EXEMPT"<?= $l['tax_type']==='EXEMPT'?' selected':'' ?>>면세</option>
        </select></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <div class="pager"><span>비어 있는 줄은 저장하지 않습니다. VAT 는 과세 항목에만 10% 로 계산됩니다.
    <?= $id > 0 ? '수정 시 기존 항목을 지우고 다시 넣습니다 — 지워진 내용은 이력에 남습니다.' : '' ?></span></div>
</div>

<?php if ($id > 0): ?>
<div class="card">
  <div class="ch">수정 사유 <span style="font-weight:400;color:var(--err-fg)">필수</span></div>
  <div class="cb">
    <input type="text" name="reason" value="<?= h($reason) ?>" required
           placeholder="예) 운임 단가 오입력 정정 — 거래처 확인 완료">
    <div style="font-size:11.5px;color:var(--ink3);margin-top:6px">
      금액이 걸린 화면이라 사유를 남깁니다. 변경 전후 내용과 함께 작업로그에 기록됩니다.
    </div>
  </div>
</div>
<?php endif; ?>

<div style="display:flex;gap:8px">
  <button class="btn pri"><?= $id > 0 ? '수정 저장' : '저장' ?></button>
  <a class="btn" href="?p=shipments">취소</a>
</div>
</form>

<?php if ($id > 0 && ($cur['status'] ?? '') !== 'CANCELLED'): ?>
<div class="card" style="border-color:#F0D9AE;background:#FFFCF6">
  <div class="ch" style="border-color:#F0D9AE">전표 취소</div>
  <div class="cb">
    <form method="post" class="f" style="align-items:flex-end"
          onsubmit="return confirm('이 전표를 취소 상태로 바꿉니다. 계속할까요?');">
      <?= csrf_field() ?>
      <input type="hidden" name="act" value="cancel">
      <div class="fw gr" style="min-width:320px">
        <label>취소 사유 *</label>
        <input type="text" name="cancel_reason" required placeholder="예) 고객 요청으로 발송 취소">
      </div>
      <button class="btn" style="border-color:#C9A257;color:#6B4700">전표 취소</button>
    </form>
    <div style="font-size:11.5px;color:var(--ink3);margin-top:8px">
      <b>지우지 않습니다.</b> 상태만 <code>CANCELLED</code> 로 바뀌고 내용은 그대로 남습니다.
      되돌리려면 이 화면에서 다시 저장하면 됩니다.
    </div>
  </div>
</div>
<?php endif; ?>

<?php
// ---------------------------------------------------------------- 변경 이력
if ($id > 0) {
    $st = db()->prepare(
        'SELECT action, admin_name, reason, before_value, after_value, created_at
           FROM activity_logs
          WHERE ref_table = \'shipments\' AND ref_id = ?
          ORDER BY id DESC LIMIT 10');
    $st->execute([$id]);
    $logs = $st->fetchAll();
    if ($logs):
?>
<div class="card">
  <div class="ch">이 전표의 변경 이력</div>
  <table>
    <thead><tr>
      <th style="width:150px">일시</th><th style="width:90px">작업</th>
      <th style="width:90px">담당</th><th>사유 · 변경 내용</th>
    </tr></thead>
    <tbody>
    <?php foreach ($logs as $g): ?>
      <tr>
        <td class="tnum"><?= h($g['created_at']) ?></td>
        <td><span class="badge <?= $g['action']==='CANCEL'?'b-err':($g['action']==='CREATE'?'b-ok':'b-info') ?>"><?= h($g['action']) ?></span></td>
        <td><?= h($g['admin_name']) ?></td>
        <td style="font-size:11.5px">
          <?php if ($g['reason']): ?><b><?= h($g['reason']) ?></b><br><?php endif; ?>
          <?php if ($g['before_value']): ?>
            <span style="color:var(--ink3)">전 · <?= h($g['before_value']) ?></span><br>
          <?php endif; ?>
          <?php if ($g['after_value']): ?>
            <span style="color:var(--ink3)">후 · <?= h($g['after_value']) ?></span>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php
    endif;
}
layout_foot();
