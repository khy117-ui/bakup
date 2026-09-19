<?php
require_once APP_DIR . '/layout.php';

$eid = entity_id();
$id  = (int)query('id', '0');
$err = '';
$cur = null;

$CHARGE = charge_labels();   // 종류와 기본 세금구분은 bootstrap 의 CHARGE_TYPES
$TAX     = ['ZERO' => 0.0, 'TAXABLE' => 10.0, 'EXEMPT' => 0.0];
$STATUS  = ['DRAFT' => '작성중', 'SENT' => '발송', 'ACCEPTED' => '수주',
            'REJECTED' => '실주', 'EXPIRED' => '만료'];
$MAXLINE = 6;

$companies = db()->prepare('SELECT id, company_code, name_ko FROM companies
                             WHERE deleted_at IS NULL ORDER BY name_ko');
$companies->execute();
$companies = $companies->fetchAll();
$carriers = db()->query('SELECT id, code FROM carriers WHERE is_active = 1
                          ORDER BY sort_order, code')->fetchAll();

$in = [
    'company_id' => '', 'prospect_name' => '', 'quote_date' => date('Y-m-d'),
    'valid_until' => date('Y-m-d', strtotime('+14 days')),
    'trade_type' => 'EXPORT', 'carrier_id' => '',
    'origin_country' => 'KR', 'dest_country' => '', 'charge_weight' => '',
    'terms' => "· 견적 유효기간 내에만 위 단가가 적용됩니다.\n"
             . "· 유류할증료는 발송일 기준 요율로 정산됩니다.\n"
             . "· 실제 청구중량(실중량·부피중량 중 큰 값)으로 청구됩니다.",
    'remark' => '',
];
$lines = [];
for ($i = 0; $i < $MAXLINE; $i++) {
    $lines[] = ['charge_type' => $i === 0 ? 'AIR_FREIGHT' : 'OTHER', 'item_name' => '',
                'qty' => '1', 'unit_price' => '', 'tax_type' => 'ZERO'];
}

if ($id > 0) {
    $st = db()->prepare('SELECT * FROM quotations
                          WHERE id = ? AND business_entity_id = ? AND deleted_at IS NULL');
    $st->execute([$id, $eid]);
    $cur = $st->fetch();
    if (!$cur) { exit('견적서를 찾을 수 없습니다.'); }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        foreach (array_keys($in) as $k) {
            if (array_key_exists($k, $cur)) { $in[$k] = (string)($cur[$k] ?? ''); }
        }
        $st = db()->prepare('SELECT * FROM quotation_items WHERE quotation_id = ? ORDER BY line_no');
        $st->execute([$id]);
        foreach ($st->fetchAll() as $i => $o) {
            if ($i >= $MAXLINE) { break; }
            $lines[$i] = [
                'charge_type' => $o['charge_type'], 'item_name' => $o['item_name'],
                'qty' => (string)(float)$o['qty'],
                'unit_price' => (string)(int)round((float)$o['unit_price']),
                'tax_type' => $o['tax_type'],
            ];
        }
    }
}

// ---------------------------------------------------------------- 상태 변경
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('act') === 'status' && $id > 0) {
    csrf_check();
    $to = post('status');
    if (!isset($STATUS[$to])) {
        $err = '상태 값이 올바르지 않습니다.';
    } else {
        db()->prepare('UPDATE quotations SET status = ? WHERE id = ? AND business_entity_id = ?')
            ->execute([$to, $id, $eid]);
        log_action('견적', 'UPDATE', 'quotations', $id, (string)$cur['quote_no'],
                   (string)$cur['status'], $to);
        flash('상태를 ' . $STATUS[$to] . ' 로 바꿨습니다.');
        redirect('?p=quotation_form&id=' . $id);
    }
}

// ---------------------------------------------------------------- 전표 전환
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('act') === 'convert' && $id > 0) {
    csrf_check();
    if ($cur['converted_shipment_id']) {
        $err = '이미 전표로 전환된 견적서입니다.';
    } elseif (!$cur['company_id']) {
        $err = '거래처가 지정되지 않은 견적서는 전환할 수 없습니다. 먼저 거래처를 등록하고 연결하세요.';
    } elseif (!$cur['carrier_id']) {
        $err = '운송사가 지정되지 않았습니다.';
    } else {
        $pdo = db();
        try {
            $pdo->beginTransaction();
            $items = $pdo->prepare('SELECT * FROM quotation_items WHERE quotation_id = ? ORDER BY line_no');
            $items->execute([$id]);
            $items = $items->fetchAll();
            if (!$items) { throw new RuntimeException('견적 항목이 없습니다.'); }

            $awb = next_doc_no('AWB', 'GPA');
            $pdo->prepare(
                'INSERT INTO shipments
                   (business_entity_id, company_id, awb_no, awb_source, voucher_date,
                    trade_type, carrier_id, dest_city, charge_weight, actual_weight,
                    status, remark, created_by)
                 VALUES (?,?,?,\'HOUSE\',?,?,?,?,?,?,\'CONFIRMED\',?,?)')
                ->execute([$eid, (int)$cur['company_id'], $awb, date('Y-m-d'),
                           $cur['trade_type'] ?: 'EXPORT', (int)$cur['carrier_id'],
                           $cur['dest_country'] ?: null,
                           $cur['charge_weight'] ?: null, $cur['charge_weight'] ?: null,
                           '견적서 ' . $cur['quote_no'] . ' 에서 전환',
                           $_SESSION['admin_id'] ?? null]);
            $sid = (int)$pdo->lastInsertId();

            $ins = $pdo->prepare(
                'INSERT INTO shipment_charges
                   (shipment_id, line_no, charge_type, item_name, qty, unit_price,
                    supply_amount, tax_type, tax_rate, tax_amount, total_amount)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?)');
            $n = 0;
            foreach ($items as $it) {
                $ins->execute([$sid, ++$n, $it['charge_type'], $it['item_name'],
                               $it['qty'], $it['unit_price'], $it['supply_amount'],
                               $it['tax_type'], $it['tax_rate'], $it['tax_amount'],
                               $it['total_amount']]);
            }

            $pdo->prepare(
                'UPDATE quotations SET status = \'ACCEPTED\', converted_shipment_id = ?
                  WHERE id = ?')->execute([$sid, $id]);
            log_action('견적', 'CONVERT', 'quotations', $id, (string)$cur['quote_no'],
                       null, '매출전표 ' . $awb . ' 생성');
            log_action('매출전표', 'CREATE', 'shipments', $sid, $awb, null,
                       '견적서 ' . $cur['quote_no'] . ' 전환');
            $pdo->commit();
            flash('매출전표 ' . $awb . ' 로 전환했습니다.');
            redirect('?p=shipment_form&id=' . $sid);
        } catch (Throwable $e) {
            $pdo->rollBack();
            error_log('견적 전환 실패: ' . $e->getMessage());
            $err = $e instanceof RuntimeException ? $e->getMessage() : '전환하지 못했습니다.';
        }
    }
}

// ---------------------------------------------------------------- 저장
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('act') === 'save') {
    csrf_check();
    foreach (array_keys($in) as $k) { $in[$k] = post($k); }
    $postLines = $_POST['line'] ?? [];
    if (is_array($postLines)) {
        foreach ($postLines as $i => $l) {
            if (!isset($lines[$i]) || !is_array($l)) { continue; }
            $lines[$i] = [
                'charge_type' => (string)($l['charge_type'] ?? 'OTHER'),
                'item_name'   => trim((string)($l['item_name'] ?? '')),
                'qty'         => (string)($l['qty'] ?? '1'),
                'unit_price'  => (string)($l['unit_price'] ?? ''),
                'tax_type'    => (string)($l['tax_type'] ?? 'ZERO'),
            ];
        }
    }

    $valid = [];
    foreach ($lines as $l) {
        if ($l['item_name'] === '' && num($l['unit_price']) == 0.0) { continue; }
        if (!isset($CHARGE[$l['charge_type']]) || !isset($TAX[$l['tax_type']])) {
            $err = '비용 종류나 세금구분이 올바르지 않습니다.'; break;
        }
        if ($l['item_name'] === '') { $err = '금액이 있는 항목에는 항목명이 필요합니다.'; break; }
        $valid[] = $l;
    }

    if ($err === '') {
        if ($in['company_id'] === '' && $in['prospect_name'] === '') {
            $err = '거래처를 고르거나, 미등록이면 상호를 직접 입력하세요.';
        } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $in['quote_date'])) {
            $err = '견적일을 입력하세요.';
        } elseif (!$valid) {
            $err = '견적 항목을 최소 한 줄 입력하세요.';
        }
    }

    if ($err === '') {
        $pdo = db();
        try {
            $pdo->beginTransaction();
            $supply = 0; $vat = 0;
            foreach ($valid as $l) {
                $amt = round(num($l['qty']) * num($l['unit_price']));
                $supply += $amt;
                $vat += round($amt * $TAX[$l['tax_type']] / 100);
            }

            $args = [
                $in['company_id'] !== '' ? (int)$in['company_id'] : null,
                $in['prospect_name'] !== '' ? $in['prospect_name'] : null,
                $in['quote_date'],
                $in['valid_until'] !== '' ? $in['valid_until'] : null,
                $in['trade_type'] === 'IMPORT' ? 'IMPORT' : 'EXPORT',
                $in['carrier_id'] !== '' ? (int)$in['carrier_id'] : null,
                strtoupper($in['origin_country']) ?: null,
                strtoupper($in['dest_country']) ?: null,
                num($in['charge_weight']) > 0 ? num($in['charge_weight']) : null,
                $supply, $vat, $supply + $vat,
                $in['terms'] ?: null, $in['remark'] ?: null,
            ];

            if ($id > 0) {
                $args[] = $id; $args[] = $eid;
                $pdo->prepare(
                    'UPDATE quotations SET company_id=?, prospect_name=?, quote_date=?,
                            valid_until=?, trade_type=?, carrier_id=?, origin_country=?,
                            dest_country=?, charge_weight=?, supply_total=?, tax_total=?,
                            grand_total=?, terms=?, remark=?
                      WHERE id=? AND business_entity_id=?')->execute($args);
                $qid = $id;
                $no  = (string)$cur['quote_no'];
                $pdo->prepare('DELETE FROM quotation_items WHERE quotation_id = ?')->execute([$qid]);
            } else {
                $no = next_doc_no('QUOTE', 'GPA-Q-', '-');
                array_unshift($args, $eid, $no);
                $args[] = $_SESSION['admin_id'] ?? null;
                $pdo->prepare(
                    'INSERT INTO quotations
                       (business_entity_id, quote_no, company_id, prospect_name, quote_date,
                        valid_until, trade_type, carrier_id, origin_country, dest_country,
                        charge_weight, supply_total, tax_total, grand_total, terms, remark,
                        status, created_by)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,\'DRAFT\',?)')->execute($args);
                $qid = (int)$pdo->lastInsertId();
            }

            $ins = $pdo->prepare(
                'INSERT INTO quotation_items
                   (quotation_id, line_no, charge_type, item_name, qty, unit_price,
                    supply_amount, tax_type, tax_rate, tax_amount, total_amount)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?)');
            $n = 0;
            foreach ($valid as $l) {
                $q = num($l['qty']) ?: 1;
                $u = round(num($l['unit_price']));
                $amt = round($q * $u);
                $rate = $TAX[$l['tax_type']];
                $tax = round($amt * $rate / 100);
                $ins->execute([$qid, ++$n, $l['charge_type'], $l['item_name'], $q, $u,
                               $amt, $l['tax_type'], $rate, $tax, $amt + $tax]);
            }
            log_action('견적', $id > 0 ? 'UPDATE' : 'CREATE', 'quotations', $qid, $no,
                       null, '합계 ' . number_format($supply + $vat));
            $pdo->commit();
            flash('견적서 ' . $no . ' 를 저장했습니다.');
            redirect('?p=quotation_form&id=' . $qid);
        } catch (PDOException $e) {
            $pdo->rollBack();
            error_log('견적서 저장 실패: ' . $e->getMessage());
            $err = '저장하지 못했습니다. 견적 항목 수정에는 DELETE 권한이 필요합니다.';
        }
    }
}

$title = $id > 0 ? '견적서 ' . $cur['quote_no'] : '견적서 작성';
layout_head($title, 'quotations');
?>
<div class="head">
  <h1><?= h($id > 0 ? $cur['quote_no'] : '견적서 작성') ?></h1>
  <?php if ($id > 0): ?>
    <span class="badge <?= $cur['status']==='ACCEPTED'?'b-ok':($cur['status']==='REJECTED'?'b-err':'b-info') ?>"
          style="height:24px"><?= h($STATUS[$cur['status']] ?? $cur['status']) ?></span>
  <?php endif; ?>
  <div class="crumb">영업관리 &gt; 견적서</div>
  <div class="right">
    <a class="btn" href="?p=quotations">목록</a>
    <?php if ($id > 0): ?>
      <a class="btn" href="?p=quotation_print&amp;id=<?= $id ?>" target="_blank">출력</a>
    <?php endif; ?>
  </div>
</div>

<?php if ($err !== ''): ?><div class="msg err"><?= h($err) ?></div><?php endif; ?>

<?php if ($id > 0 && $cur['converted_shipment_id']): ?>
  <div class="msg ok">이 견적서는 매출전표로 전환됐습니다 —
    <a href="?p=shipment_form&amp;id=<?= (int)$cur['converted_shipment_id'] ?>">전표 열기</a></div>
<?php endif; ?>

<form method="post">
<?= csrf_field() ?>
<input type="hidden" name="act" value="save">

<div class="card">
  <div class="ch">견적 정보</div>
  <div class="cb f">
    <div class="fw w3"><label>거래처</label>
      <select name="company_id">
        <option value="">미등록 (아래 상호 직접 입력)</option>
        <?php foreach ($companies as $c): ?>
          <option value="<?= (int)$c['id'] ?>"<?= (string)$in['company_id']===(string)$c['id']?' selected':'' ?>>
            <?= h($c['name_ko']) ?> (<?= h($c['company_code']) ?>)</option>
        <?php endforeach; ?>
      </select></div>
    <div class="fw w2"><label>미등록 상호</label>
      <input type="text" name="prospect_name" value="<?= h($in['prospect_name']) ?>"
             placeholder="신규 문의 업체명"></div>
    <div class="fw w1"><label>견적일 *</label>
      <input type="date" name="quote_date" required value="<?= h($in['quote_date']) ?>"></div>
    <div class="fw w1"><label>유효기한</label>
      <input type="date" name="valid_until" value="<?= h($in['valid_until']) ?>"></div>
  </div>
  <div class="cb f" style="border-top:1px solid var(--line2)">
    <div class="fw w1"><label>구분</label>
      <select name="trade_type">
        <option value="EXPORT"<?= $in['trade_type']==='EXPORT'?' selected':'' ?>>수출</option>
        <option value="IMPORT"<?= $in['trade_type']==='IMPORT'?' selected':'' ?>>수입</option>
      </select></div>
    <div class="fw w1"><label>운송사</label>
      <select name="carrier_id">
        <option value="">선택</option>
        <?php foreach ($carriers as $c): ?>
          <option value="<?= (int)$c['id'] ?>"<?= (string)$in['carrier_id']===(string)$c['id']?' selected':'' ?>><?= h($c['code']) ?></option>
        <?php endforeach; ?>
      </select></div>
    <div class="fw w1"><label>출발국</label>
      <input type="text" name="origin_country" maxlength="2" value="<?= h($in['origin_country']) ?>"
             style="text-transform:uppercase"></div>
    <div class="fw w1"><label>도착국</label>
      <input type="text" name="dest_country" maxlength="2" value="<?= h($in['dest_country']) ?>"
             style="text-transform:uppercase" placeholder="US"></div>
    <div class="fw w1"><label>청구중량 (kg)</label>
      <input type="text" name="charge_weight" class="tnum" style="text-align:right"
             value="<?= h($in['charge_weight']) ?>"></div>
    <div class="fw gr" style="min-width:200px"><label>비고</label>
      <input type="text" name="remark" value="<?= h($in['remark']) ?>"></div>
  </div>
</div>

<div class="card">
  <div class="ch">견적 항목
    <span style="font-weight:400;color:var(--ink3)">세금구분은 줄마다 따로 — 국제운송은 영세율, 부대비용은 과세인 경우가 많습니다</span>
  </div>
  <table>
    <thead><tr>
      <th class="c" style="width:40px">#</th><th style="width:140px">종류</th>
      <th>항목명</th><th class="r" style="width:80px">수량</th>
      <th class="r" style="width:130px">단가</th><th style="width:120px">세금구분</th>
    </tr></thead>
    <tbody>
    <?php foreach ($lines as $i => $l): ?>
      <tr>
        <td class="c tnum"><?= $i+1 ?></td>
        <td><select name="line[<?= $i ?>][charge_type]" class="ctype">
          <?php foreach ($CHARGE as $k=>$v): ?>
            <option value="<?= h($k) ?>" data-tax="<?= h(charge_default_tax($k)) ?>"<?= $l['charge_type']===$k?' selected':'' ?>><?= h($v) ?></option>
          <?php endforeach; ?>
        </select></td>
        <td><input type="text" name="line[<?= $i ?>][item_name]" value="<?= h($l['item_name']) ?>"
                   placeholder="<?= $i===0 ? 'EXPRESS WORLDWIDE (Zone 4, 5kg)' : '' ?>"></td>
        <td><input type="text" class="tnum" style="text-align:right"
                   name="line[<?= $i ?>][qty]" value="<?= h($l['qty']) ?>"></td>
        <td><input type="text" class="tnum" style="text-align:right"
                   name="line[<?= $i ?>][unit_price]" value="<?= h($l['unit_price']) ?>"></td>
        <td><select name="line[<?= $i ?>][tax_type]">
          <option value="ZERO"<?= $l['tax_type']==='ZERO'?' selected':'' ?>>영세율 0%</option>
          <option value="TAXABLE"<?= $l['tax_type']==='TAXABLE'?' selected':'' ?>>과세 10%</option>
          <option value="EXEMPT"<?= $l['tax_type']==='EXEMPT'?' selected':'' ?>>면세</option>
        </select></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <script>
  // 종류를 고르면 회사 기준 세금구분으로 (운송 = 영세율, 핸드링 · 도큐멘트 · 국내운송 · 창고 · 검사 = 과세)
  document.querySelectorAll('select.ctype').forEach(function (s) {
    s.addEventListener('change', function () {
      var tax = s.options[s.selectedIndex].getAttribute('data-tax');
      var t = s.closest('tr').querySelector('select[name$="[tax_type]"]');
      if (tax && t) { t.value = tax; }
    });
  });
  </script>
  <div class="pager"><span>공급가액 = 수량 × 단가. 빈 줄은 저장하지 않습니다.
    <a href="?p=rate_calculator">단가계산기</a>로 금액을 먼저 뽑아보실 수 있습니다.</span></div>
</div>

<div class="card">
  <div class="ch">견적 조건</div>
  <div class="cb">
    <textarea name="terms" rows="4"><?= h($in['terms']) ?></textarea>
    <div style="font-size:11.5px;color:var(--ink3);margin-top:6px">출력물 하단에 그대로 찍힙니다.</div>
  </div>
</div>

<div style="display:flex;gap:8px">
  <button class="btn pri">저장</button>
  <a class="btn" href="?p=quotations">취소</a>
</div>
</form>

<?php if ($id > 0): ?>
<div class="card">
  <div class="ch">상태 · 전환</div>
  <div class="cb f" style="align-items:flex-end">
    <form method="post" class="f" style="align-items:flex-end;gap:8px">
      <?= csrf_field() ?>
      <input type="hidden" name="act" value="status">
      <div class="fw w1"><label>상태</label>
        <select name="status">
          <?php foreach ($STATUS as $k=>$v): ?>
            <option value="<?= h($k) ?>"<?= $cur['status']===$k?' selected':'' ?>><?= h($v) ?></option>
          <?php endforeach; ?>
        </select></div>
      <button class="btn">상태 바꾸기</button>
    </form>
    <?php if (!$cur['converted_shipment_id']): ?>
    <form method="post" style="margin-left:auto"
          onsubmit="return confirm('이 견적서로 매출전표를 만듭니다. AWB 번호가 새로 부여됩니다.');">
      <?= csrf_field() ?>
      <input type="hidden" name="act" value="convert">
      <button class="btn pri">매출전표로 전환</button>
    </form>
    <?php endif; ?>
  </div>
  <div class="cb" style="border-top:1px solid var(--line2);font-size:11.5px;color:var(--ink3)">
    전환하면 견적 항목이 그대로 매출전표의 비용항목으로 복사되고, 상태가 <b>수주</b>로 바뀝니다.
    전표일은 오늘로 들어가니 필요하면 전표 화면에서 고치세요.
  </div>
</div>
<?php endif; ?>
<?php layout_foot();
