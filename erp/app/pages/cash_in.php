<?php
// 웹에서 직접 열면 실행되지 않게 막습니다 (nginx 면 .htaccess 가 무시됩니다)
if (!defined('APP_DIR')) { http_response_code(403); exit('Forbidden'); }

require APP_DIR . '/layout.php';

/**
 * 입금 등록.
 *
 * 거래처를 고르면 그 거래처의 **미수 매출전표**가 뜹니다. 정산할 전표를 골라
 * 금액을 나눠 붙입니다.
 *   · 한 번의 입금으로 여러 전표를 처리   (1 : N)
 *   · 하나의 전표에 여러 번 나눠 입금     (N : 1)
 *
 * 배분하지 않고 남긴 금액은 **선수금**입니다. 나중에 전표가 생기면 그때 붙입니다.
 * 금액 검사는 전부 트랜잭션 안에서 합니다 — 화면에서 계산한 값을 믿지 않습니다.
 */

$err = '';
$eid = entity_id();                       // 새로 만드는 것은 한 사업자에 속합니다

require_perm('CASH_WRITE', '입금 등록');

// ---------------------------------------------------------------- 저장
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('act') === 'save') {
    csrf_check();
    $entId   = (int)post('business_entity_id', (string)$eid);
    $date    = post('txn_date', date('Y-m-d'));
    $compId  = (int)post('company_id');
    $acctId  = (int)post('to_account_id');
    $amount  = (float)str_replace(',', '', post('amount'));
    $method  = post('method', 'TRANSFER');
    $taxInv  = (int)post('tax_invoice_id');
    $summary = post('summary');
    $memo    = post('memo');
    $alloc   = $_POST['alloc'] ?? [];      // shipment_id => 금액
    $allocOb = $_POST['alloc_ob'] ?? [];   // opening_balance_id => 금액 (전환일 이전 미수)

    if (!is_array($alloc))   { $alloc = []; }
    if (!is_array($allocOb)) { $allocOb = []; }

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        $err = '입금일자를 입력하세요.';
    } elseif ($compId <= 0) {
        $err = '거래처를 선택하세요.';
    } elseif ($amount <= 0) {
        $err = '입금금액을 입력하세요.';
    } else {
        $pdo = db();
        try {
            $pdo->beginTransaction();

            // 배분액을 먼저 검사합니다. 하나라도 어긋나면 아무것도 넣지 않습니다
            $lines = [];
            $sum = 0.0;
            foreach ($alloc as $sid => $raw) {
                $sid = (int)$sid;
                $v = (float)str_replace(',', '', (string)$raw);
                if ($sid <= 0 || $v <= 0) { continue; }

                $st = $pdo->prepare(
                    'SELECT r.sales_amount, r.balance, r.business_entity_id, s.awb_no
                       FROM v_shipment_receivable r
                       JOIN shipments s ON s.id = r.shipment_id
                      WHERE r.shipment_id = ? AND r.company_id = ?');
                $st->execute([$sid, $compId]);
                $row = $st->fetch();
                if (!$row) {
                    throw new RuntimeException('이 거래처의 전표가 아닙니다.');
                }
                // 입금은 한 사업자에 속합니다. 다른 법인의 전표를 갚을 수 없습니다
                if ((int)$row['business_entity_id'] !== $entId) {
                    throw new RuntimeException(
                        $row['awb_no'] . ' 는 다른 사업자의 전표입니다. 입금 사업자와 같은 법인의 전표에만 배분할 수 있습니다.');
                }
                if ($v > (float)$row['balance'] + 0.001) {
                    throw new RuntimeException(
                        $row['awb_no'] . ' 의 미수는 ' . money($row['balance'])
                        . ' 원입니다. 그보다 많이 배분할 수 없습니다.');
                }
                $lines[] = [$sid, $v];
                $sum += $v;
            }

            // 기초잔액(전환일 이전 미수)에 배분
            $obLines = [];
            foreach ($allocOb as $obId => $raw) {
                $obId = (int)$obId;
                $v = (float)str_replace(',', '', (string)$raw);
                if ($obId <= 0 || $v <= 0) { continue; }
                $st = $pdo->prepare(
                    "SELECT remaining, business_entity_id FROM v_opening_balance
                      WHERE id = ? AND company_id = ? AND balance_type = 'AR' AND status = 'CONFIRMED'");
                $st->execute([$obId, $compId]);
                $ob = $st->fetch();
                if (!$ob) {
                    throw new RuntimeException('이 거래처의 기초잔액이 아닙니다.');
                }
                if ((int)$ob['business_entity_id'] !== $entId) {
                    throw new RuntimeException('기초잔액이 다른 사업자 것입니다.');
                }
                if ($v > (float)$ob['remaining'] + 0.001) {
                    throw new RuntimeException(
                        '기초 미수는 ' . money($ob['remaining']) . ' 원 남았습니다. 그보다 많이 배분할 수 없습니다.');
                }
                $obLines[] = [$obId, $v];
                $sum += $v;
            }

            if ($sum > $amount + 0.001) {
                throw new RuntimeException(
                    '배분 합계(' . money($sum) . ')가 입금액(' . money($amount) . ')보다 많습니다.');
            }

            $no = next_doc_no('CASH_IN', entity_code($entId) . '-R-', '-', $entId);

            $pdo->prepare(
                'INSERT INTO financial_transactions
                   (business_entity_id, doc_no, txn_type, txn_date, company_id, counterparty,
                    to_account_id, method, supply_amount, tax_type, vat_amount, amount,
                    tax_invoice_id, alloc_amount, summary, memo, status, created_by)
                 VALUES (?,?,\'IN\',?,?,?,?,?,?,\'ZERO\',0,?,?,?,?,?,\'CONFIRMED\',?)')
                ->execute([
                    $entId, $no, $date, $compId, post('counterparty') ?: null,
                    $acctId ?: null, $method, $amount, $amount,
                    $taxInv ?: null, $sum, $summary ?: null, $memo ?: null,
                    $_SESSION['admin_id'] ?? null,
                ]);
            $txnId = (int)$pdo->lastInsertId();

            $ins = $pdo->prepare(
                'INSERT INTO payment_allocations
                   (transaction_id, line_no, shipment_id, amount, created_by)
                 VALUES (?,?,?,?,?)');
            $n = 0;
            foreach ($lines as [$sid, $v]) {
                $ins->execute([$txnId, ++$n, $sid, $v, $_SESSION['admin_id'] ?? null]);
            }
            $insOb = $pdo->prepare(
                'INSERT INTO payment_allocations
                   (transaction_id, line_no, opening_balance_id, amount, created_by)
                 VALUES (?,?,?,?,?)');
            foreach ($obLines as [$obId, $v]) {
                $insOb->execute([$txnId, ++$n, $obId, $v, $_SESSION['admin_id'] ?? null]);
            }

            // 이 전표들이 담긴 청구서의 수금액도 같이 맞춥니다
            fin_resync_invoices($txnId);

            fin_audit($txnId, 'CREATE', null, null,
                      money($amount) . '원 · 배분 ' . $n . '건 ' . money($sum) . '원');
            log_action('입출금', 'CREATE', 'financial_transactions', $txnId, $no,
                       null, '입금 ' . money($amount));
            $pdo->commit();

            $left = $amount - $sum;
            flash('입금 ' . $no . ' 을 등록했습니다.'
                . ($left > 0 ? ' 배분하지 않은 ' . money($left) . '원은 선수금으로 남습니다.' : ''));
            redirect('?p=cash_list&type=IN');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            if ($e instanceof RuntimeException) {
                $err = $e->getMessage();
            } else {
                error_log('입금 등록 실패: ' . $e->getMessage());
                $err = '등록하지 못했습니다.';
            }
        }
    }
}

// ---------------------------------------------------------------- 조회
$compId = (int)query('company_id', (string)(int)post('company_id'));

$companies = db()->query(
    'SELECT id, company_code, name_ko FROM companies
      WHERE deleted_at IS NULL ORDER BY name_ko')->fetchAll();

$accounts = db()->prepare(
    "SELECT id, bank_name, account_no, account_holder, purpose
       FROM business_bank_accounts
      WHERE business_entity_id = ? AND is_active = 1 AND purpose IN ('IN','BOTH')
      ORDER BY sort_order, id");
$accounts->execute([$eid]);
$accounts = $accounts->fetchAll();

// 선택한 거래처의 미수 전표 — 오래된 것부터
$unpaid = [];
$obRows = [];
$unpaidTotal = 0.0;
$taxInvoices = [];
// 상단에서 사업자를 골라 두었으면 그 사업자로 등록합니다
$formEnt = entity_filter() ?? $eid;
if ($compId > 0) {
    $params = [];
    $w = entity_where('r.business_entity_id', $params);
    $params[] = $compId;
    $st = db()->prepare(
        "SELECT r.*, s.awb_no, s.voucher_date, e.code AS ent_code
           FROM v_shipment_receivable r
           JOIN shipments s ON s.id = r.shipment_id
           JOIN business_entities e ON e.id = r.business_entity_id
          WHERE $w AND r.company_id = ? AND r.balance > 0
            AND r.pay_status IN ('UNPAID','PARTIAL')
          ORDER BY r.voucher_date, r.shipment_id");
    $st->execute($params);
    $unpaid = $st->fetchAll();
    foreach ($unpaid as $u) { $unpaidTotal += (float)$u['balance']; }

    // 기초잔액 — 전환일 이전 미수. 가장 오래된 돈이라 맨 위에 둡니다
    try {
        $params = [];
        $w = entity_where('o.business_entity_id', $params);
        $params[] = $compId;
        $st = db()->prepare(
            "SELECT o.*, e.code AS ent_code FROM v_opening_balance o
               JOIN business_entities e ON e.id = o.business_entity_id
              WHERE $w AND o.company_id = ? AND o.balance_type = 'AR'
                AND o.status = 'CONFIRMED' AND o.remaining > 0
              ORDER BY o.as_of_date");
        $st->execute($params);
        $obRows = $st->fetchAll();
        foreach ($obRows as $o) { $unpaidTotal += (float)$o['remaining']; }
    } catch (PDOException $e) {
        $obRows = [];                       // 19번을 아직 안 돌렸을 수 있습니다
    }

    $st = db()->prepare(
        "SELECT id, doc_no, issue_date, grand_total FROM tax_invoices
          WHERE company_id = ? AND deleted_at IS NULL AND status <> 'CANCELLED'
          ORDER BY issue_date DESC LIMIT 30");
    $st->execute([$compId]);
    $taxInvoices = $st->fetchAll();
}

layout_head('입금 등록', 'cash_in');
?>
<div class="head">
  <h1>입금 등록</h1>
  <div class="crumb">입출금관리 &gt; 입금 등록</div>
</div>

<?php if ($err !== ''): ?><div class="msg err"><?= h($err) ?></div><?php endif; ?>

<div class="card">
  <div class="ch">거래처 선택</div>
  <div class="cb">
    <form class="f" method="get" style="align-items:flex-end">
      <input type="hidden" name="p" value="cash_in">
      <div class="fw w2"><label for="c">거래처 *</label>
        <select id="c" name="company_id" onchange="this.form.submit()">
          <option value="">— 선택 —</option>
          <?php foreach ($companies as $c): ?>
            <option value="<?= (int)$c['id'] ?>"<?= $compId === (int)$c['id'] ? ' selected' : '' ?>>
              <?= h($c['name_ko']) ?> (<?= h($c['company_code']) ?>)</option>
          <?php endforeach; ?>
        </select></div>
      <button class="btn">미수 불러오기</button>
    </form>
  </div>
</div>

<?php if ($compId <= 0): ?>
  <div class="card"><div class="empty">
    거래처를 먼저 고르세요. 그 거래처의 미수 매출전표가 여기에 나옵니다.
  </div></div>
<?php else: ?>

<form method="post" id="payform">
<?= csrf_field() ?>
<input type="hidden" name="act" value="save">
<input type="hidden" name="company_id" value="<?= $compId ?>">

<div class="card">
  <div class="ch">입금 내용</div>
  <div class="cb">
    <div class="f" style="align-items:flex-end">
      <div class="fw w1"><label for="e">사업자 *</label>
        <select id="e" name="business_entity_id">
          <?php foreach (entity_list() as $en): ?>
            <option value="<?= (int)$en['id'] ?>"<?= (int)$en['id'] === $formEnt ? ' selected' : '' ?>>
              <?= h($en['name_ko']) ?></option>
          <?php endforeach; ?>
        </select></div>
      <div class="fw w1"><label for="d">입금일자 *</label>
        <input type="date" id="d" name="txn_date" required value="<?= h(date('Y-m-d')) ?>"></div>
      <div class="fw w1"><label for="a">입금금액 *</label>
        <input type="text" id="a" name="amount" class="tnum" required
               style="text-align:right;font-weight:700" placeholder="0"
               oninput="recalc()"></div>
      <div class="fw w1"><label for="m">입금방법</label>
        <select id="m" name="method">
          <option value="TRANSFER">계좌이체</option>
          <option value="CARD">카드</option>
          <option value="CASH">현금</option>
          <option value="NOTE">어음</option>
          <option value="PG">PG</option>
        </select></div>
    </div>
    <div class="f" style="align-items:flex-end;margin-top:8px">
      <div class="fw w2"><label for="ac">입금계좌</label>
        <select id="ac" name="to_account_id">
          <option value="">— 지정 안 함 —</option>
          <?php foreach ($accounts as $ac): ?>
            <option value="<?= (int)$ac['id'] ?>">
              <?= h($ac['bank_name']) ?> <?= h($ac['account_no']) ?></option>
          <?php endforeach; ?>
        </select>
        <?php if (!$accounts): ?>
          <div style="font-size:11px;color:var(--err-fg)">
            등록된 입금계좌가 없습니다. <a href="?p=accounts">계좌 관리</a>에서 먼저 넣으세요.</div>
        <?php endif; ?>
      </div>
      <div class="fw w1"><label for="cp">입금자명</label>
        <input type="text" id="cp" name="counterparty" placeholder="업체명과 다른 경우"></div>
      <div class="fw w2"><label for="ti">관련 세금계산서</label>
        <select id="ti" name="tax_invoice_id">
          <option value="">— 없음 —</option>
          <?php foreach ($taxInvoices as $t): ?>
            <option value="<?= (int)$t['id'] ?>">
              <?= h($t['doc_no']) ?> · <?= h($t['issue_date']) ?> · <?= money($t['grand_total']) ?>원</option>
          <?php endforeach; ?>
        </select></div>
    </div>
    <div class="f" style="align-items:flex-end;margin-top:8px">
      <div class="fw w2"><label for="sm">적요</label>
        <input type="text" id="sm" name="summary" placeholder="예) 9월분 운임"></div>
      <div class="fw w2"><label for="mo">메모</label>
        <input type="text" id="mo" name="memo"></div>
    </div>
  </div>
</div>

<div class="card">
  <div class="ch">정산할 매출전표
    <span style="font-weight:400;color:var(--ink3)">오래된 것부터</span>
    <?php if ($unpaid || $obRows): ?>
      <button type="button" class="btn sm" style="margin-left:auto" onclick="autoAlloc()">
        입금액만큼 자동배분</button>
      <button type="button" class="btn sm" onclick="clearAlloc()">배분 지우기</button>
    <?php endif; ?>
  </div>
  <?php if (!$unpaid && !$obRows): ?>
    <div class="empty">
      이 거래처는 미수 전표가 없습니다.<br>
      <span style="font-size:12px">그대로 등록하면 전액 <b>선수금</b>으로 남고,
      나중에 전표가 생겼을 때 붙일 수 있습니다.</span>
    </div>
  <?php else: ?>
  <table>
    <thead><tr>
      <th class="c" style="width:45px"></th>
      <th style="width:110px">전표일</th><th style="width:170px">AWB</th>
      <th class="r" style="width:135px">매출금액</th>
      <th class="r" style="width:135px">기수금</th>
      <th class="r" style="width:135px">미수</th>
      <th class="c" style="width:90px">상태</th>
      <th class="r" style="width:160px">이번 배분액</th>
    </tr></thead>
    <tbody>
    <?php foreach ($obRows as $o): [$lab, $cls] = pay_status_badge($o['pay_status']); ?>
      <tr style="background:#FBF7EE">
        <td class="c">
          <input type="checkbox" class="pick" data-sid="ob<?= (int)$o['id'] ?>"
                 data-bal="<?= (float)$o['remaining'] ?>" onchange="pickChanged(this)"></td>
        <td class="tnum"><?= h($o['as_of_date']) ?></td>
        <td style="font-weight:600">기초잔액
          <div style="font-weight:400;font-size:11px;color:var(--ink3)">전환일 이전 미수<?=
            entity_filter() === null ? ' · ' . h($o['ent_code']) : '' ?></div></td>
        <td class="r tnum"><?= money($o['amount']) ?></td>
        <td class="r tnum" style="color:var(--ink2)"><?= money($o['allocated']) ?></td>
        <td class="r tnum" style="font-weight:700"><?= money($o['remaining']) ?></td>
        <td class="c"><span class="badge <?= $cls ?>"><?= h($lab) ?></span></td>
        <td class="r">
          <input type="text" class="tnum allocin" style="text-align:right;width:145px"
                 name="alloc_ob[<?= (int)$o['id'] ?>]"
                 data-sid="ob<?= (int)$o['id'] ?>"
                 data-bal="<?= (float)$o['remaining'] ?>"
                 oninput="recalc()" placeholder="0"></td>
      </tr>
    <?php endforeach; ?>
    <?php foreach ($unpaid as $u): [$lab, $cls] = pay_status_badge($u['pay_status']); ?>
      <tr>
        <td class="c">
          <input type="checkbox" class="pick" data-sid="<?= (int)$u['shipment_id'] ?>"
                 data-bal="<?= (float)$u['balance'] ?>" onchange="pickChanged(this)"></td>
        <td class="tnum"><?= h($u['voucher_date']) ?></td>
        <td class="tnum" style="font-weight:600"><?= h($u['awb_no']) ?><?=
          entity_filter() === null
            ? ' <span style="font-weight:400;font-size:11px;color:var(--ink3)">' . h($u['ent_code']) . '</span>'
            : '' ?></td>
        <td class="r tnum"><?= money($u['sales_amount']) ?></td>
        <td class="r tnum" style="color:var(--ink2)"><?= money($u['paid_amount']) ?></td>
        <td class="r tnum" style="font-weight:700"><?= money($u['balance']) ?></td>
        <td class="c"><span class="badge <?= $cls ?>"><?= h($lab) ?></span></td>
        <td class="r">
          <input type="text" class="tnum allocin" style="text-align:right;width:145px"
                 name="alloc[<?= (int)$u['shipment_id'] ?>]"
                 data-sid="<?= (int)$u['shipment_id'] ?>"
                 data-bal="<?= (float)$u['balance'] ?>"
                 oninput="recalc()" placeholder="0"></td>
      </tr>
    <?php endforeach; ?>
      <tr style="background:#F7FAFB">
        <td colspan="5" class="r" style="font-weight:700">미수 합계</td>
        <td class="r tnum" style="font-weight:700"><?= money($unpaidTotal) ?></td>
        <td></td>
        <td class="r tnum" style="font-weight:700"><span id="allocsum">0</span></td>
      </tr>
    </tbody>
  </table>
  <?php endif; ?>
  <div class="pager"><span id="balline">
    입금액과 배분 합계를 맞추면 딱 떨어집니다. 남기면 선수금으로 갑니다.
  </span></div>
</div>

<div style="display:flex;gap:8px;align-items:center">
  <button class="btn pri">입금 등록</button>
  <a class="btn" href="?p=cash_in">취소</a>
  <span style="font-size:11.5px;color:var(--ink3)">
    등록자와 등록일시는 자동으로 남습니다. 등록 후 수정·취소 이력도 전부 기록됩니다.
  </span>
</div>
</form>

<script>
function num(v){ v = (v||'').toString().replace(/[^0-9.-]/g,''); return v === '' ? 0 : parseFloat(v); }
function fmt(n){ return Math.round(n).toLocaleString('ko-KR'); }

function recalc(){
  var sum = 0;
  document.querySelectorAll('.allocin').forEach(function(el){ sum += num(el.value); });
  var amt = num(document.getElementById('a').value);
  document.getElementById('allocsum').textContent = fmt(sum);
  var left = amt - sum, line = document.getElementById('balline');
  if (amt === 0) {
    line.innerHTML = '입금액을 넣고 <b>자동배분</b>을 누르면 오래된 전표부터 채웁니다.';
  } else if (left > 0) {
    line.innerHTML = '배분하지 않은 <b>' + fmt(left) + '원</b>은 <b>선수금</b>으로 남습니다.';
  } else if (left < 0) {
    line.innerHTML = '<b style="color:#B3261E">배분 합계가 입금액보다 ' + fmt(-left)
                   + '원 많습니다.</b> 이대로는 저장되지 않습니다.';
  } else {
    line.innerHTML = '<b style="color:#1B7F5A">입금액과 배분이 정확히 맞습니다.</b>';
  }
}
function pickChanged(cb){
  var el = document.querySelector('.allocin[data-sid="' + cb.dataset.sid + '"]');
  el.value = cb.checked ? fmt(parseFloat(cb.dataset.bal)) : '';
  recalc();
}
function autoAlloc(){
  var left = num(document.getElementById('a').value);
  document.querySelectorAll('.allocin').forEach(function(el){
    var bal = parseFloat(el.dataset.bal);
    var put = Math.min(left, bal);
    el.value = put > 0 ? fmt(put) : '';
    var cb = document.querySelector('.pick[data-sid="' + el.dataset.sid + '"]');
    if (cb) cb.checked = put > 0;
    left -= put;
  });
  recalc();
}
function clearAlloc(){
  document.querySelectorAll('.allocin').forEach(function(el){ el.value = ''; });
  document.querySelectorAll('.pick').forEach(function(el){ el.checked = false; });
  recalc();
}
recalc();
</script>
<?php endif; ?>
<?php layout_foot();
