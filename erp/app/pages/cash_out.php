<?php
// 웹에서 직접 열면 실행되지 않게 막습니다 (nginx 면 .htaccess 가 무시됩니다)
if (!defined('APP_DIR')) { http_response_code(403); exit('Forbidden'); }

require_once APP_DIR . '/layout.php';

/**
 * 출금 등록 — 비용 지급과 계좌이체.
 *
 * 계좌이체는 회사 통장 사이의 이동이라 **비용이 아닙니다.** 같은 화면에서
 * 넣지만 txn_type 이 달라서 통계·손익에 잡히지 않습니다. 이체수수료만 비용입니다.
 *
 * 운송사에 준 돈은 매입(purchases)에 붙여 미지급금을 줄일 수 있습니다.
 */

$err = '';
$eid = entity_id();

require_perm('CASH_WRITE', '출금 등록');

$cats = db()->query('SELECT id, code, name, default_tax, is_cogs FROM expense_categories
                      WHERE is_active = 1 ORDER BY sort_order, id')->fetchAll();

// ---------------------------------------------------------------- 저장
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array(post('act'), ['out', 'transfer'], true)) {
    csrf_check();
    $isTransfer = post('act') === 'transfer';
    $entId = (int)post('business_entity_id', (string)$eid);
    $date  = post('txn_date', date('Y-m-d'));

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        $err = '일자를 입력하세요.';
    } elseif ($isTransfer) {
        // ---------------------------------------------------- 계좌이체
        $from = (int)post('from_account_id');
        $to   = (int)post('to_account_id');
        $amt  = (float)str_replace(',', '', post('amount'));
        $fee  = (float)str_replace(',', '', post('fee', '0'));
        if ($from <= 0 || $to <= 0) {
            $err = '출발계좌와 도착계좌를 모두 고르세요.';
        } elseif ($from === $to) {
            $err = '같은 계좌로는 이체할 수 없습니다.';
        } elseif ($amt <= 0) {
            $err = '이체금액을 입력하세요.';
        } else {
            try {
                $no = next_doc_no('CASH_TR', entity_code($entId) . '-T-', '-', $entId);
                db()->prepare(
                    'INSERT INTO financial_transactions
                       (business_entity_id, doc_no, txn_type, txn_date,
                        from_account_id, to_account_id, method,
                        supply_amount, tax_type, vat_amount, amount, fee,
                        summary, memo, status, created_by)
                     VALUES (?,?,\'TRANSFER\',?,?,?,\'TRANSFER\',0,\'EXEMPT\',0,?,?,?,?,\'CONFIRMED\',?)')
                    ->execute([$entId, $no, $date, $from, $to, $amt, $fee,
                               post('summary') ?: null, post('memo') ?: null,
                               $_SESSION['admin_id'] ?? null]);
                $txnId = (int)db()->lastInsertId();
                fin_audit($txnId, 'CREATE', null, null,
                          '계좌이체 ' . money($amt) . '원 (수수료 ' . money($fee) . ')');
                log_action('입출금', 'CREATE', 'financial_transactions', $txnId, $no,
                           null, '계좌이체 ' . money($amt));
                flash('계좌이체 ' . $no . ' 을 등록했습니다. 비용·손익에는 잡히지 않습니다'
                    . ($fee > 0 ? ' (수수료 ' . money($fee) . '원은 비용입니다).' : '.'));
                redirect('?p=cash_list&type=TRANSFER');
            } catch (Throwable $e) {
                error_log('계좌이체 실패: ' . $e->getMessage());
                $err = '등록하지 못했습니다.';
            }
        }
    } else {
        // ---------------------------------------------------- 출금
        $catId   = (int)post('category_id');
        $payee   = trim(post('payee_name'));
        $supply  = (float)str_replace(',', '', post('supply_amount'));
        $taxType = post('tax_type', 'TAXABLE');
        $vat     = (float)str_replace(',', '', post('vat_amount', '0'));
        $compId  = (int)post('company_id');
        $allocs  = $_POST['alloc'] ?? [];
        $allocOb = $_POST['alloc_ob'] ?? [];      // 기초 미지급
        if (!is_array($allocs))  { $allocs = []; }
        if (!is_array($allocOb)) { $allocOb = []; }

        if ($catId <= 0) {
            $err = '비용분류를 고르세요.';
        } elseif ($payee === '') {
            $err = '받는 쪽을 입력하세요.';
        } elseif ($supply <= 0) {
            $err = '금액을 입력하세요.';
        } else {
            $total = $supply + $vat;
            $pdo = db();
            try {
                $pdo->beginTransaction();

                $lines = []; $sum = 0.0;
                foreach ($allocs as $pid => $raw) {
                    $pid = (int)$pid;
                    $v = (float)str_replace(',', '', (string)$raw);
                    if ($pid <= 0 || $v <= 0) { continue; }
                    $st = $pdo->prepare('SELECT balance, vendor_name, business_entity_id
                                           FROM v_purchase_payable WHERE purchase_id = ?');
                    $st->execute([$pid]);
                    $row = $st->fetch();
                    if (!$row) { throw new RuntimeException('매입 건을 찾을 수 없습니다.'); }
                    if ((int)$row['business_entity_id'] !== $entId) {
                        throw new RuntimeException($row['vendor_name'] . ' 매입은 다른 사업자 것입니다.');
                    }
                    if ($v > (float)$row['balance'] + 0.001) {
                        throw new RuntimeException(
                            $row['vendor_name'] . ' 의 미지급은 ' . money($row['balance'])
                            . ' 원입니다. 그보다 많이 배분할 수 없습니다.');
                    }
                    $lines[] = [$pid, $v];
                    $sum += $v;
                }

                $obLines = [];
                foreach ($allocOb as $obId => $raw) {
                    $obId = (int)$obId;
                    $v = (float)str_replace(',', '', (string)$raw);
                    if ($obId <= 0 || $v <= 0) { continue; }
                    $st = $pdo->prepare(
                        "SELECT remaining, counterparty_name, business_entity_id FROM v_opening_balance
                          WHERE id = ? AND balance_type = 'AP' AND status = 'CONFIRMED'");
                    $st->execute([$obId]);
                    $ob = $st->fetch();
                    if (!$ob) { throw new RuntimeException('기초 미지급을 찾을 수 없습니다.'); }
                    if ((int)$ob['business_entity_id'] !== $entId) {
                        throw new RuntimeException('기초 미지급이 다른 사업자 것입니다.');
                    }
                    if ($v > (float)$ob['remaining'] + 0.001) {
                        throw new RuntimeException(
                            $ob['counterparty_name'] . ' 기초 미지급은 ' . money($ob['remaining'])
                            . ' 원 남았습니다.');
                    }
                    $obLines[] = [$obId, $v];
                    $sum += $v;
                }

                if ($sum > $total + 0.001) {
                    throw new RuntimeException('배분 합계가 출금액보다 많습니다.');
                }

                $no = next_doc_no('CASH_OUT', entity_code($entId) . '-D-', '-', $entId);
                $pdo->prepare(
                    'INSERT INTO financial_transactions
                       (business_entity_id, doc_no, txn_type, txn_date, company_id, counterparty,
                        from_account_id, method, category_id, payee_type,
                        supply_amount, tax_type, vat_amount, amount, alloc_amount,
                        evidence_type, evidence_no, summary, memo, status, created_by)
                     VALUES (?,?,\'OUT\',?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,\'CONFIRMED\',?)')
                    ->execute([
                        $entId, $no, $date, $compId ?: null, $payee,
                        (int)post('from_account_id') ?: null, post('method', 'TRANSFER'),
                        $catId, post('payee_type', 'VENDOR'),
                        $supply, $taxType, $vat, $total, $sum,
                        post('evidence_type', 'NONE'), post('evidence_no') ?: null,
                        post('summary') ?: null, post('memo') ?: null,
                        $_SESSION['admin_id'] ?? null,
                    ]);
                $txnId = (int)$pdo->lastInsertId();

                $ins = $pdo->prepare(
                    'INSERT INTO payment_allocations
                       (transaction_id, line_no, purchase_id, amount, created_by)
                     VALUES (?,?,?,?,?)');
                $n = 0;
                foreach ($lines as [$pid, $v]) {
                    $ins->execute([$txnId, ++$n, $pid, $v, $_SESSION['admin_id'] ?? null]);
                }
                $insOb = $pdo->prepare(
                    'INSERT INTO payment_allocations
                       (transaction_id, line_no, opening_balance_id, amount, created_by)
                     VALUES (?,?,?,?,?)');
                foreach ($obLines as [$obId, $v]) {
                    $insOb->execute([$txnId, ++$n, $obId, $v, $_SESSION['admin_id'] ?? null]);
                }

                fin_audit($txnId, 'CREATE', null, null,
                          money($total) . '원 · 매입배분 ' . $n . '건');
                log_action('입출금', 'CREATE', 'financial_transactions', $txnId, $no,
                           null, '출금 ' . money($total));
                $pdo->commit();
                flash('출금 ' . $no . ' 을 등록했습니다.');
                redirect('?p=cash_list&type=OUT');
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) { $pdo->rollBack(); }
                $err = $e instanceof RuntimeException ? $e->getMessage() : '등록하지 못했습니다.';
                if (!($e instanceof RuntimeException)) { error_log('출금 실패: ' . $e->getMessage()); }
            }
        }
    }
}

// ---------------------------------------------------------------- 조회
$mode = query('mode', 'out');            // out | transfer

$accounts = db()->prepare(
    'SELECT b.id, b.bank_name, b.account_no, b.purpose, v.balance
       FROM business_bank_accounts b
       LEFT JOIN v_account_balance v ON v.bank_account_id = b.id
      WHERE b.business_entity_id = ? AND b.is_active = 1
      ORDER BY b.sort_order, b.id');
$accounts->execute([$eid]);
$accounts = $accounts->fetchAll();

$companies = db()->query('SELECT id, company_code, name_ko FROM companies
                           WHERE deleted_at IS NULL ORDER BY name_ko')->fetchAll();

// 미지급 매입 — 출금을 붙일 대상
$vendor = query('vendor');
$payable = [];
$obPayable = [];
if ($mode === 'out') {
    $params = [];
    $w = entity_where('p.business_entity_id', $params);
    $extra = '';
    if ($vendor !== '') { $extra = ' AND p.vendor_name LIKE ?'; $params[] = '%' . $vendor . '%'; }
    $st = db()->prepare(
        "SELECT p.*, s.awb_no
           FROM v_purchase_payable p
           LEFT JOIN shipments s ON s.id = p.shipment_id
          WHERE $w AND p.balance > 0 $extra
          ORDER BY p.purchase_date LIMIT 60");
    $st->execute($params);
    $payable = $st->fetchAll();

    // 기초 미지급 — 전환일 이전에 줄 돈
    try {
        $params = [];
        $w = entity_where('o.business_entity_id', $params);
        $extra = '';
        if ($vendor !== '') { $extra = ' AND o.counterparty_name LIKE ?'; $params[] = '%' . $vendor . '%'; }
        $st = db()->prepare(
            "SELECT o.* FROM v_opening_balance o
              WHERE $w AND o.balance_type = 'AP' AND o.status = 'CONFIRMED'
                AND o.remaining > 0 $extra
              ORDER BY o.remaining DESC LIMIT 60");
        $st->execute($params);
        $obPayable = $st->fetchAll();
    } catch (PDOException $e) {
        $obPayable = [];
    }
}

layout_head($mode === 'transfer' ? '계좌이체' : '출금 등록', 'cash_out');
?>
<div class="head">
  <h1><?= $mode === 'transfer' ? '계좌이체' : '출금 등록' ?></h1>
  <div class="crumb">입출금관리 &gt; <?= $mode === 'transfer' ? '계좌이체' : '출금 등록' ?></div>
</div>

<?php if ($err !== ''): ?><div class="msg err"><?= h($err) ?></div><?php endif; ?>

<div class="card"><div class="cb" style="display:flex;gap:8px">
  <a class="btn<?= $mode !== 'transfer' ? ' pri' : '' ?>" href="?p=cash_out&amp;mode=out">비용 출금</a>
  <a class="btn<?= $mode === 'transfer' ? ' pri' : '' ?>" href="?p=cash_out&amp;mode=transfer">계좌이체</a>
  <span style="font-size:11.5px;color:var(--ink2);align-self:center">
    <?= $mode === 'transfer'
        ? '회사 통장 사이의 이동입니다. 비용·손익에 잡히지 않습니다.'
        : '실제로 나간 비용입니다. 손익에 반영됩니다.' ?>
  </span>
</div></div>

<?php if ($mode === 'transfer'): ?>
<!-- ============================ 계좌이체 ============================ -->
<form method="post">
<?= csrf_field() ?>
<input type="hidden" name="act" value="transfer">
<div class="card">
  <div class="ch">계좌이체</div>
  <div class="cb">
    <div class="f" style="align-items:flex-end">
      <div class="fw w1"><label for="te">사업자 *</label>
        <select id="te" name="business_entity_id">
          <?php foreach (entity_list() as $en): ?>
            <option value="<?= (int)$en['id'] ?>"<?= (int)$en['id'] === $eid ? ' selected' : '' ?>>
              <?= h($en['name_ko']) ?></option>
          <?php endforeach; ?>
        </select></div>
      <div class="fw w1"><label for="td">이체일 *</label>
        <input type="date" id="td" name="txn_date" required value="<?= h(date('Y-m-d')) ?>"></div>
      <div class="fw w1"><label for="ta">이체금액 *</label>
        <input type="text" id="ta" name="amount" class="tnum" required
               style="text-align:right;font-weight:700"></div>
      <div class="fw w1"><label for="tf">이체수수료</label>
        <input type="text" id="tf" name="fee" class="tnum" style="text-align:right" value="0"></div>
    </div>
    <div class="f" style="align-items:flex-end;margin-top:8px">
      <div class="fw w2"><label for="tfr">출발계좌 *</label>
        <select id="tfr" name="from_account_id" required>
          <option value="">— 선택 —</option>
          <?php foreach ($accounts as $a): ?>
            <option value="<?= (int)$a['id'] ?>"><?= h($a['bank_name']) ?> <?= h($a['account_no']) ?>
              (잔액 <?= money($a['balance'] ?? 0) ?>)</option>
          <?php endforeach; ?>
        </select></div>
      <div class="fw w2"><label for="tto">도착계좌 *</label>
        <select id="tto" name="to_account_id" required>
          <option value="">— 선택 —</option>
          <?php foreach ($accounts as $a): ?>
            <option value="<?= (int)$a['id'] ?>"><?= h($a['bank_name']) ?> <?= h($a['account_no']) ?>
              (잔액 <?= money($a['balance'] ?? 0) ?>)</option>
          <?php endforeach; ?>
        </select></div>
    </div>
    <div class="f" style="align-items:flex-end;margin-top:8px">
      <div class="fw w2"><label for="ts">적요</label>
        <input type="text" id="ts" name="summary" placeholder="예) 운영자금 이동"></div>
      <div class="fw w2"><label for="tm">메모</label>
        <input type="text" id="tm" name="memo"></div>
    </div>
  </div>
  <div class="pager"><span>
    <b>이체는 비용이 아닙니다.</b> 출발계좌 잔액이 줄고 도착계좌 잔액이 늘지만,
    매출·비용·손익 어디에도 반영되지 않습니다. <b>이체수수료만</b> 비용으로 잡힙니다.
  </span></div>
</div>
<div style="display:flex;gap:8px">
  <button class="btn pri">계좌이체 등록</button>
  <?php if (count($accounts) < 2): ?>
    <span style="font-size:11.5px;color:var(--err-fg);align-self:center">
      계좌가 2개 이상 있어야 이체할 수 있습니다.</span>
  <?php endif; ?>
</div>
</form>

<?php else: ?>
<!-- ============================ 비용 출금 ============================ -->
<form id="vsearch" method="get">
  <input type="hidden" name="p" value="cash_out">
  <input type="hidden" name="mode" value="out">
</form>
<form method="post">
<?= csrf_field() ?>
<input type="hidden" name="act" value="out">
<div class="card">
  <div class="ch">출금 내용</div>
  <div class="cb">
    <div class="f" style="align-items:flex-end">
      <div class="fw w1"><label for="oe">사업자 *</label>
        <select id="oe" name="business_entity_id">
          <?php foreach (entity_list() as $en): ?>
            <option value="<?= (int)$en['id'] ?>"<?= (int)$en['id'] === $eid ? ' selected' : '' ?>>
              <?= h($en['name_ko']) ?></option>
          <?php endforeach; ?>
        </select></div>
      <div class="fw w1"><label for="od">출금일 *</label>
        <input type="date" id="od" name="txn_date" required value="<?= h(date('Y-m-d')) ?>"></div>
      <div class="fw w1"><label for="oc">비용분류 *</label>
        <select id="oc" name="category_id" required onchange="catChanged(this)">
          <option value="">— 선택 —</option>
          <?php foreach ($cats as $c): ?>
            <option value="<?= (int)$c['id'] ?>" data-tax="<?= h($c['default_tax']) ?>">
              <?= h($c['name']) ?><?= (int)$c['is_cogs'] ? ' (원가)' : '' ?></option>
          <?php endforeach; ?>
        </select></div>
      <div class="fw w1"><label for="op">지급 구분</label>
        <select id="op" name="payee_type">
          <option value="VENDOR">업체 지급</option>
          <option value="COMPANY">거래처 환불</option>
          <option value="EXPENSE">일반 경비</option>
          <option value="TAX">세금·공과금</option>
          <option value="PAYROLL">급여</option>
          <option value="OTHER">기타</option>
        </select></div>
    </div>
    <div class="f" style="align-items:flex-end;margin-top:8px">
      <div class="fw w2"><label for="opn">받는 쪽 *</label>
        <input type="text" id="opn" name="payee_name" required
               placeholder="DHL / 한성통운 / 세무법인 …"></div>
      <div class="fw w2"><label for="ocm">거래처 (환불일 때)</label>
        <select id="ocm" name="company_id">
          <option value="">— 해당 없음 —</option>
          <?php foreach ($companies as $c): ?>
            <option value="<?= (int)$c['id'] ?>"><?= h($c['name_ko']) ?> (<?= h($c['company_code']) ?>)</option>
          <?php endforeach; ?>
        </select></div>
    </div>
    <div class="f" style="align-items:flex-end;margin-top:8px">
      <div class="fw w1"><label for="os">공급가액 *</label>
        <input type="text" id="os" name="supply_amount" class="tnum" required
               style="text-align:right;font-weight:700" oninput="calcVat()"></div>
      <div class="fw w1"><label for="ot">부가세 구분</label>
        <select id="ot" name="tax_type" onchange="calcVat()">
          <option value="TAXABLE">과세 (10%)</option>
          <option value="ZERO">영세율</option>
          <option value="EXEMPT">면세</option>
        </select></div>
      <div class="fw w1"><label for="ov">부가세</label>
        <input type="text" id="ov" name="vat_amount" class="tnum"
               style="text-align:right" value="0" oninput="calcTotal()"></div>
      <div class="fw w1"><label>합계</label>
        <input type="text" id="ottl" class="tnum" style="text-align:right;font-weight:700"
               value="0" readonly></div>
    </div>
    <div class="f" style="align-items:flex-end;margin-top:8px">
      <div class="fw w2"><label for="oa">출금계좌</label>
        <select id="oa" name="from_account_id">
          <option value="">— 지정 안 함 —</option>
          <?php foreach ($accounts as $a): ?>
            <option value="<?= (int)$a['id'] ?>"><?= h($a['bank_name']) ?> <?= h($a['account_no']) ?>
              (잔액 <?= money($a['balance'] ?? 0) ?>)</option>
          <?php endforeach; ?>
        </select></div>
      <div class="fw w1"><label for="om">지급방법</label>
        <select id="om" name="method">
          <option value="TRANSFER">계좌이체</option>
          <option value="CARD">카드</option>
          <option value="CASH">현금</option>
          <option value="NOTE">어음</option>
        </select></div>
      <div class="fw w1"><label for="oev">증빙유형</label>
        <select id="oev" name="evidence_type">
          <option value="TAX_INVOICE">세금계산서</option>
          <option value="INVOICE">계산서</option>
          <option value="CARD">신용카드</option>
          <option value="CASH_RCPT">현금영수증</option>
          <option value="SIMPLE">간이영수증</option>
          <option value="NONE" selected>없음</option>
        </select></div>
      <div class="fw w1"><label for="oen">증빙번호</label>
        <input type="text" id="oen" name="evidence_no" class="tnum"></div>
    </div>
    <div class="f" style="align-items:flex-end;margin-top:8px">
      <div class="fw w2"><label for="osm">적요</label>
        <input type="text" id="osm" name="summary"></div>
      <div class="fw w2"><label for="omm">메모</label>
        <input type="text" id="omm" name="memo"></div>
    </div>
  </div>
</div>

<div class="card">
  <div class="ch">미지급 매입에 붙이기
    <span style="font-weight:400;color:var(--ink3)">선택 — 안 붙여도 됩니다</span>
    <!-- 출금 폼 안에 폼을 또 넣으면 브라우저가 안쪽을 무시합니다.
         그래서 검색 칸은 바깥의 vsearch 폼에 form 속성으로 붙입니다 -->
    <span style="margin-left:auto;display:flex;gap:6px">
      <input type="text" name="vendor" form="vsearch" value="<?= h($vendor) ?>"
             placeholder="업체명" style="width:160px">
      <button class="btn sm" form="vsearch">찾기</button>
    </span>
  </div>
  <?php if (!$payable && !$obPayable): ?>
    <div class="empty">미지급 매입이 없습니다.</div>
  <?php else: ?>
  <table>
    <thead><tr>
      <th style="width:110px">매입일</th><th>업체</th><th style="width:150px">AWB</th>
      <th class="r" style="width:130px">매입액</th><th class="r" style="width:130px">기지급</th>
      <th class="r" style="width:130px">미지급</th><th class="r" style="width:150px">이번 배분</th>
    </tr></thead>
    <tbody>
    <?php foreach ($obPayable as $o): ?>
      <tr style="background:#FBF7EE">
        <td class="tnum"><?= h($o['as_of_date']) ?></td>
        <td><?= h($o['counterparty_name']) ?>
          <div style="font-size:11px;color:var(--ink3)">기초 미지급 · 전환일 이전</div></td>
        <td class="tnum">-</td>
        <td class="r tnum"><?= money($o['amount']) ?></td>
        <td class="r tnum" style="color:var(--ink2)"><?= money($o['allocated']) ?></td>
        <td class="r tnum" style="font-weight:700"><?= money($o['remaining']) ?></td>
        <td class="r"><input type="text" class="tnum" style="text-align:right;width:135px"
               name="alloc_ob[<?= (int)$o['id'] ?>]" placeholder="0"></td>
      </tr>
    <?php endforeach; ?>
    <?php foreach ($payable as $p): ?>
      <tr>
        <td class="tnum"><?= h($p['purchase_date']) ?></td>
        <td><?= h($p['vendor_name']) ?></td>
        <td class="tnum"><?= h($p['awb_no'] ?: '-') ?></td>
        <td class="r tnum"><?= money($p['purchase_amount']) ?></td>
        <td class="r tnum" style="color:var(--ink2)"><?= money($p['paid_amount']) ?></td>
        <td class="r tnum" style="font-weight:700"><?= money($p['balance']) ?></td>
        <td class="r"><input type="text" class="tnum" style="text-align:right;width:135px"
               name="alloc[<?= (int)$p['purchase_id'] ?>]" placeholder="0"></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>

<div style="display:flex;gap:8px;align-items:center">
  <button class="btn pri">출금 등록</button>
  <span style="font-size:11.5px;color:var(--ink3)">
    증빙 파일은 등록 후 문서보관함에서 이 건에 붙입니다.
  </span>
</div>
</form>

<script>
function num(v){ v=(v||'').toString().replace(/[^0-9.-]/g,''); return v===''?0:parseFloat(v); }
function fmt(n){ return Math.round(n).toLocaleString('ko-KR'); }
function calcVat(){
  var s = num(document.getElementById('os').value);
  var t = document.getElementById('ot').value;
  document.getElementById('ov').value = (t === 'TAXABLE') ? fmt(Math.round(s * 0.1)) : '0';
  calcTotal();
}
function calcTotal(){
  document.getElementById('ottl').value =
    fmt(num(document.getElementById('os').value) + num(document.getElementById('ov').value));
}
function catChanged(sel){
  var t = sel.options[sel.selectedIndex].dataset.tax;
  if (t) { document.getElementById('ot').value = t; calcVat(); }
}
</script>
<?php endif; ?>
<?php layout_foot();
