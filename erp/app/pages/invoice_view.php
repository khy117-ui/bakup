<?php
require_once APP_DIR . '/layout.php';

$eid = entity_id();
$id  = (int)query('id', '0');
$err = '';
schema_upgrade_invoices();   // 재발행 컬럼(issued_at · issue_count · reissue_reason · changed_at) 보강

$st = db()->prepare(
    'SELECT i.*, c.name_ko, c.company_code, c.business_number, c.representative,
            c.address_ko, c.payment_terms
       FROM invoices i
       JOIN companies c ON c.id = i.company_id
      WHERE i.id = ? AND i.business_entity_id = ? AND i.deleted_at IS NULL');
$st->execute([$id, $eid]);
$inv = $st->fetch();
if (!$inv) {
    exit('청구서를 찾을 수 없습니다.');
}

$TAX = ['ZERO' => 0.0, 'TAXABLE' => 10.0, 'EXEMPT' => 0.0];

// ---------------------------------------------------------------- 동작
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $act = post('act');
    $pdo = db();

    try {
        // 발행 뒤에 내용을 고쳤을 때 청구서에 '내용 바뀜' 표시 (재발행 필요)
        $touch = function () use ($pdo, $id, $inv): void {
            if ($inv['status'] !== 'DRAFT') {
                $pdo->prepare('UPDATE invoices SET changed_at = NOW() WHERE id = ?')->execute([$id]);
            }
        };

        if ($act === 'issue' && $inv['status'] !== 'CANCELLED') {
            // 처음 발행(DRAFT → ISSUED) 과 재발행(이미 발행된 청구서를 고친 뒤 다시 발행) 을 같이 처리합니다.
            // 재발행은 사유가 필수이고, 이전 · 이후 금액이 로그에 남습니다.
            $first = $inv['status'] === 'DRAFT';
            $why   = trim(post('reason'));
            if (!$first && mb_strlen($why) < 2) {
                $err = '재발행 사유를 적어 주세요. (예: 운임 정정, 조정항목 추가)';
            } else {
                $pdo->beginTransaction();
                $before = invoice_snapshot($id);
                if ($first) {
                    $pdo->prepare("UPDATE invoices SET status = 'ISSUED' WHERE id = ?")->execute([$id]);
                }
                invoice_recalc($id);   // 전표 · 조정항목의 현재 금액으로 합계를 다시 계산
                $pdo->prepare('UPDATE invoices
                                  SET issued_at = NOW(), issue_count = issue_count + 1,
                                      reissue_reason = ?, changed_at = NULL
                                WHERE id = ?')->execute([$first ? null : $why, $id]);
                $after = invoice_snapshot($id);
                log_action('청구', 'ISSUE', 'invoices', $id, $inv['invoice_no'],
                           $first ? null : $before, $after, $first ? null : $why);
                $pdo->commit();
                $taxNote = '';
                if (!$first) {
                    $tx = $pdo->prepare("SELECT COUNT(*) FROM tax_invoices WHERE invoice_id = ? AND deleted_at IS NULL AND status <> 'CANCELLED' AND replaced_by IS NULL");
                    try { $tx->execute([$id]); if ((int)$tx->fetchColumn() > 0) { $taxNote = ' 이 청구서로 만든 세금계산서가 있습니다. [전자세금계산서] 에서 재발행하세요.'; } }
                    catch (PDOException $e) { /* replaced_by 컬럼이 아직 없으면 넘어감 */ }
                }
                flash($first ? '청구서를 발행했습니다.'
                             : '청구서를 재발행했습니다 (' . ((int)$inv['issue_count'] + 1) . '회차). 인보이스를 다시 출력해 보내세요.' . $taxNote);
                redirect('?p=invoice_view&id=' . $id);
            }

        } elseif ($act === 'edit_head') {
            // 청구일 · 지급기한 · 대상기간 · 비고 수정 (발행 뒤에도 가능 — 로그에 이전 · 이후가 남고 재발행이 필요해집니다)
            $idate = post('invoice_date');
            $due   = post('due_date');
            $pf    = post('period_from');
            $pt    = post('period_to');
            $why   = trim(post('reason'));
            $ok = fn($d) => (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', $d);
            if (!$ok($idate) || !$ok($pf) || !$ok($pt) || ($due !== '' && !$ok($due))) {
                $err = '날짜 형식이 올바르지 않습니다.';
            } elseif ($pf > $pt) {
                $err = '대상기간 시작이 종료보다 늦습니다.';
            } elseif ($inv['status'] !== 'DRAFT' && mb_strlen($why) < 2) {
                $err = '발행된 청구서를 고칠 때는 수정 사유를 적어 주세요.';
            } else {
                $pdo->beginTransaction();
                $before = invoice_snapshot($id);
                $pdo->prepare('UPDATE invoices SET invoice_date = ?, due_date = ?, period_from = ?, period_to = ?, remark = ?
                                WHERE id = ?')
                    ->execute([$idate, $due !== '' ? $due : null, $pf, $pt, post('remark') !== '' ? post('remark') : null, $id]);
                $touch();
                $after = invoice_snapshot($id);
                log_action('청구', 'UPDATE', 'invoices', $id, $inv['invoice_no'], $before, $after, $why !== '' ? $why : null);
                $pdo->commit();
                flash('청구서 내용을 수정했습니다.' . ($inv['status'] !== 'DRAFT' ? ' 인보이스에 반영하려면 [재발행] 하세요.' : ''));
                redirect('?p=invoice_view&id=' . $id);
            }

        } elseif ($act === 'cancel') {
            $why = post('reason');
            if (mb_strlen($why) < 2) {
                $err = '취소 사유를 적어 주세요.';
            } elseif ((float)$inv['paid_amount'] > 0) {
                $err = '이미 수금된 청구서입니다. 수금을 먼저 정리하세요.';
            } else {
                $pdo->beginTransaction();
                // 수록 전표를 풀어 줍니다. 안 풀면 다시 청구할 수 없습니다
                $st = $pdo->prepare('SELECT shipment_id FROM invoice_shipments WHERE invoice_id = ?');
                $st->execute([$id]);
                $sids = array_column($st->fetchAll(), 'shipment_id');
                $pdo->prepare('DELETE FROM invoice_shipments WHERE invoice_id = ?')->execute([$id]);
                if ($sids) {
                    $ph = implode(',', array_fill(0, count($sids), '?'));
                    $pdo->prepare(
                        "UPDATE shipments SET status = 'CONFIRMED'
                          WHERE id IN ($ph) AND status = 'BILLED'")->execute($sids);
                }
                $pdo->prepare("UPDATE invoices SET status = 'CANCELLED' WHERE id = ?")->execute([$id]);
                log_action('청구', 'CANCEL', 'invoices', $id, $inv['invoice_no'],
                           '전표 ' . count($sids) . '건 청구', '청구 해제', $why);
                $pdo->commit();
                flash('청구서를 취소하고 전표 ' . count($sids) . '건을 미청구로 되돌렸습니다.');
                redirect('?p=billing');
            }

        } elseif ($act === 'drop_ship') {
            $sid = (int)post('sid');
            $pdo->beginTransaction();
            $pdo->prepare('DELETE FROM invoice_shipments WHERE invoice_id = ? AND shipment_id = ?')
                ->execute([$id, $sid]);
            $pdo->prepare("UPDATE shipments SET status = 'CONFIRMED'
                            WHERE id = ? AND status = 'BILLED'")->execute([$sid]);
            $before = invoice_snapshot($id);
            invoice_recalc($id);
            $touch();
            $sa = $pdo->prepare('SELECT awb_no FROM shipments WHERE id = ?');
            $sa->execute([$sid]);
            log_action('청구', 'UPDATE', 'invoices', $id, $inv['invoice_no'],
                       $before, '전표 제외 ' . ($sa->fetchColumn() ?: ('#' . $sid)) . ' → ' . invoice_snapshot($id),
                       trim(post('reason')) !== '' ? trim(post('reason')) : null);
            $pdo->commit();
            flash('전표를 청구서에서 뺐습니다.' . ($inv['status'] !== 'DRAFT' ? ' 인보이스에 반영하려면 [재발행] 하세요.' : ''));
            redirect('?p=invoice_view&id=' . $id);

        } elseif ($act === 'add_item') {
            $name = post('item_name');
            $amt  = round(num(post('supply_amount')));
            $tt   = post('tax_type', 'TAXABLE');
            if ($name === '' || $amt == 0) {
                $err = '항목명과 금액을 입력하세요. 차감은 음수로 넣으면 됩니다.';
            } elseif (!isset($TAX[$tt])) {
                $err = '세금구분이 올바르지 않습니다.';
            } else {
                $st = $pdo->prepare('SELECT COALESCE(MAX(line_no),0)+1 FROM invoice_items WHERE invoice_id = ?');
                $st->execute([$id]);
                $ln = (int)$st->fetchColumn();
                $tax = round($amt * $TAX[$tt] / 100);
                $pdo->prepare(
                    'INSERT INTO invoice_items
                       (invoice_id, line_no, item_name, supply_amount, tax_type,
                        tax_amount, total_amount, remark)
                     VALUES (?,?,?,?,?,?,?,?)')
                    ->execute([$id, $ln, $name, $amt, $tt, $tax, $amt + $tax, post('remark') ?: null]);
                $before = invoice_snapshot($id);
                invoice_recalc($id);
                $touch();
                log_action('청구', 'UPDATE', 'invoices', $id, $inv['invoice_no'],
                           $before, '조정항목 추가 ' . $name . ' ' . number_format($amt) . ' → ' . invoice_snapshot($id),
                           post('remark') !== '' ? post('remark') : null);
                flash('조정항목을 추가했습니다.' . ($inv['status'] !== 'DRAFT' ? ' 인보이스에 반영하려면 [재발행] 하세요.' : ''));
                redirect('?p=invoice_view&id=' . $id);
            }

        } elseif ($act === 'save_remark') {
            // 비고 — 청구서 인쇄 화면과 엑셀에 그대로 나옵니다 (취소된 청구서는 손대지 않습니다)
            if ($inv['status'] === 'CANCELLED') {
                $err = '취소된 청구서는 고칠 수 없습니다.';
            } else {
                $note = trim(post('remark'));
                db()->prepare('UPDATE invoices SET remark = ? WHERE id = ? AND business_entity_id = ?')
                    ->execute([$note !== '' ? mb_substr($note, 0, 1000) : null, $id, $eid]);
                log_action('청구', 'UPDATE', 'invoices', $id, (string)$inv['invoice_no'], null, '비고 수정');
                flash('비고를 저장했습니다. 청구서 인쇄 화면과 엑셀에 나옵니다.');
                redirect('?p=invoice_view&id=' . $id);
            }

        } elseif ($act === 'drop_item') {
            $it = $pdo->prepare('SELECT item_name, supply_amount FROM invoice_items WHERE invoice_id = ? AND id = ?');
            $it->execute([$id, (int)post('iid')]);
            $itRow = $it->fetch();
            $before = invoice_snapshot($id);
            $pdo->prepare('DELETE FROM invoice_items WHERE invoice_id = ? AND id = ?')
                ->execute([$id, (int)post('iid')]);
            invoice_recalc($id);
            $touch();
            log_action('청구', 'UPDATE', 'invoices', $id, $inv['invoice_no'], $before,
                       '조정항목 삭제 ' . ($itRow ? $itRow['item_name'] . ' ' . number_format((float)$itRow['supply_amount']) : '#' . (int)post('iid'))
                       . ' → ' . invoice_snapshot($id));
            flash('조정항목을 뺐습니다.' . ($inv['status'] !== 'DRAFT' ? ' 인보이스에 반영하려면 [재발행] 하세요.' : ''));
            redirect('?p=invoice_view&id=' . $id);
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        error_log('청구서 처리 실패: ' . $e->getMessage());
        $err = '처리하지 못했습니다. 계정에 이 작업 권한이 있는지 확인하세요.';
    }

    // 값이 바뀌었을 수 있으니 다시 읽습니다
    $st = db()->prepare('SELECT i.*, c.name_ko, c.company_code, c.business_number,
                                c.representative, c.address_ko, c.payment_terms
                           FROM invoices i JOIN companies c ON c.id = i.company_id
                          WHERE i.id = ?');
    $st->execute([$id]);
    $inv = $st->fetch();
}

// ---------------------------------------------------------------- 조회
$st = db()->prepare(
    'SELECT xs.line_no, s.id AS sid, s.awb_no, s.voucher_date, s.trade_type,
            s.charge_weight, ca.code AS carrier,
            COALESCE(t.zero_supply,0) AS zero_supply,
            COALESCE(t.taxable_supply,0) AS taxable_supply,
            COALESCE(t.tax_total,0) AS tax_total,
            COALESCE(t.grand_total,0) AS grand_total
       FROM invoice_shipments xs
       JOIN shipments s ON s.id = xs.shipment_id
       JOIN carriers ca ON ca.id = s.carrier_id
       LEFT JOIN v_shipment_totals t ON t.shipment_id = s.id
      WHERE xs.invoice_id = ? ORDER BY xs.line_no');
$st->execute([$id]);
$ships = $st->fetchAll();

$st = db()->prepare('SELECT * FROM invoice_items WHERE invoice_id = ? ORDER BY line_no');
$st->execute([$id]);
$items = $st->fetchAll();

$st = db()->prepare(
    "SELECT f.id, f.txn_date AS paid_at, f.amount, f.method, f.doc_no,
            f.counterparty AS depositor, f.summary AS remark,
            b.bank_name, b.account_no
       FROM payment_allocations pa
       JOIN financial_transactions f
         ON f.id = pa.transaction_id AND f.status = 'CONFIRMED' AND f.txn_type = 'IN'
       LEFT JOIN business_bank_accounts b ON b.id = f.to_account_id
      WHERE pa.invoice_id = ?
         OR pa.shipment_id IN (SELECT shipment_id FROM invoice_shipments WHERE invoice_id = ?)
      GROUP BY f.id, b.bank_name, b.account_no
      ORDER BY f.txn_date, f.id");
$st->execute([$id, $id]);
$pays = $st->fetchAll();

[$lab, $cls] = invoice_state($inv);
$editable = in_array($inv['status'], ['DRAFT', 'ISSUED', 'PARTIAL'], true);
$issued   = !in_array($inv['status'], ['DRAFT', 'CANCELLED'], true);
$canHead  = $inv['status'] !== 'CANCELLED';

// 발행 뒤에 바뀐 것이 있는지 — 저장된 합계와 현재 전표 · 조정항목으로 계산한 합계가 다르거나, 내용 변경 표시가 발행 시각보다 뒤이면
$live = invoice_live_totals($id);
$diffAmt = abs((float)$live['grand_total'] - (float)$inv['grand_total']) > 0.5;
$changedAfter = !empty($inv['changed_at']) && (empty($inv['issued_at']) || $inv['changed_at'] > $inv['issued_at']);
$needsReissue = $issued && ($diffAmt || $changedAfter);

// 변경 이력 — 이 청구서에 남은 로그 + 수록 전표의 수정 로그
$sids = array_map(fn($r) => (int)$r['sid'], $ships);
$hsql = "SELECT l.* FROM activity_logs l WHERE (l.ref_table = 'invoices' AND l.ref_id = ?)";
$hp = [$id];
if ($sids) {
    $hsql .= " OR (l.ref_table = 'shipments' AND l.ref_id IN (" . implode(',', array_fill(0, count($sids), '?')) . ") AND l.created_at >= ?)";
    $hp = array_merge($hp, $sids, [(string)$inv['created_at']]);
}
$st = db()->prepare($hsql . ' ORDER BY l.id DESC LIMIT 100');
$st->execute($hp);
$history = $st->fetchAll();
$ACT = ['CREATE' => ['만듦', 'b-ok'], 'UPDATE' => ['수정', 'b-info'], 'DELETE' => ['삭제', 'b-err'],
        'CANCEL' => ['취소', 'b-err'], 'ISSUE' => ['발행', 'b-info'], 'PRINT' => ['출력', 'b-warn'],
        'EXPORT' => ['내보내기', 'b-warn'], 'DOWNLOAD' => ['다운로드', 'b-warn']];

layout_head('청구서 ' . $inv['invoice_no'], 'billing');
?>
<div class="head">
  <h1 class="tnum"><?= h($inv['invoice_no']) ?></h1>
  <span class="badge <?= $cls ?>" style="height:24px"><?= h($lab) ?></span>
  <div class="crumb">회계관리 &gt; 청구관리 &gt; 청구서</div>
  <div class="right">
    <a class="btn" href="?p=billing">목록</a>
    <a class="btn" href="?p=invoice_print&amp;id=<?= $id ?>" target="_blank">인보이스 출력</a>
    <?php if ($inv['status'] === 'DRAFT'): ?>
      <form method="post" style="display:inline">
        <?= csrf_field() ?><input type="hidden" name="act" value="issue">
        <button class="btn pri">발행</button>
      </form>
    <?php elseif ($issued): ?>
      <form method="post" style="display:inline-flex;gap:6px;align-items:center"
            onsubmit="return confirm('현재 전표 · 조정항목 금액으로 청구서를 다시 발행합니다. 이전 금액과 사유는 변경 이력에 남습니다. 계속할까요?');">
        <?= csrf_field() ?><input type="hidden" name="act" value="issue">
        <input type="text" name="reason" required placeholder="재발행 사유 (필수)" style="width:200px">
        <button class="btn <?= $needsReissue ? 'pri' : '' ?>">재발행</button>
      </form>
    <?php endif; ?>
  </div>
</div>

<?php if ($err !== ''): ?><div class="msg err"><?= h($err) ?></div><?php endif; ?>
<?php if ($needsReissue): ?>
  <div class="msg err">
    발행 뒤에 내용이 바뀌었습니다.
    <?php if ($diffAmt): ?>
      저장된 합계 <b class="tnum"><?= money($inv['grand_total']) ?></b> → 현재 계산 <b class="tnum"><?= money($live['grand_total']) ?></b>.
    <?php endif; ?>
    <?php if ($changedAfter): ?>
      마지막 변경 <span class="tnum"><?= h(substr((string)$inv['changed_at'], 0, 16)) ?></span>
      (발행 <span class="tnum"><?= h(substr((string)$inv['issued_at'], 0, 16)) ?></span>).
    <?php endif; ?>
    오른쪽 위 <b>[재발행]</b> 을 눌러 인보이스에 반영하세요. 재발행 전에는 출력물에 이전 금액이 나갑니다.
  </div>
<?php endif; ?>

<div class="kpis">
  <div class="kpi"><div class="lab">영세율 / 과세</div>
    <div class="val tnum" style="font-size:18px">
      <?= money($inv['zero_supply']) ?> / <?= money($inv['taxable_supply']) ?></div>
    <div class="sub tnum">VAT <?= money($inv['tax_total']) ?></div></div>
  <div class="kpi"><div class="lab">청구 합계</div>
    <div class="val tnum"><?= money($inv['grand_total']) ?></div>
    <div class="sub"><?= h($inv['period_from']) ?> ~ <?= h($inv['period_to']) ?></div></div>
  <div class="kpi"><div class="lab">수금 / 미수</div>
    <div class="val tnum" style="font-size:18px">
      <?= money($inv['paid_amount']) ?> /
      <span style="color:<?= $inv['balance']>0?'var(--err-fg)':'var(--ok-fg)' ?>"><?= money($inv['balance']) ?></span>
    </div>
    <div class="sub">지급기한 <?= $inv['due_date'] ? h($inv['due_date']) : '미지정' ?></div></div>
  <div class="kpi"><div class="lab">발행</div>
    <div class="val tnum" style="font-size:18px"><?= (int)($inv['issue_count'] ?? 0) ?>회
      <?php if ((int)($inv['issue_count'] ?? 0) > 1): ?><span style="font-size:12px;font-weight:600;color:var(--ink2)"> (재발행 <?= (int)$inv['issue_count'] - 1 ?>회)</span><?php endif; ?></div>
    <div class="sub"><?= !empty($inv['issued_at']) ? '최종 발행 ' . h(substr((string)$inv['issued_at'], 0, 16)) : '아직 발행 전' ?>
      <?php if (!empty($inv['reissue_reason'])): ?> · 사유: <?= h($inv['reissue_reason']) ?><?php endif; ?></div></div>
</div>

<?php if ($canHead): ?>
<div class="card">
  <div class="ch">청구서 내용 수정
    <span style="font-weight:400;color:var(--ink3)">청구일 · 지급기한 · 대상기간 · 비고<?= $issued ? ' — 발행된 청구서는 수정 사유가 남고 [재발행] 이 필요합니다' : '' ?></span>
  </div>
  <div class="cb">
    <form method="post" class="f" style="align-items:flex-end">
      <?= csrf_field() ?>
      <input type="hidden" name="act" value="edit_head">
      <div class="fw w1"><label>청구일</label><input type="date" name="invoice_date" value="<?= h($inv['invoice_date']) ?>" required></div>
      <div class="fw w1"><label>지급기한</label><input type="date" name="due_date" value="<?= h((string)$inv['due_date']) ?>"></div>
      <div class="fw w1"><label>대상기간 시작</label><input type="date" name="period_from" value="<?= h($inv['period_from']) ?>" required></div>
      <div class="fw w1"><label>대상기간 종료</label><input type="date" name="period_to" value="<?= h($inv['period_to']) ?>" required></div>
      <div class="fw gr" style="min-width:200px"><label>비고 (인보이스 하단)</label><input type="text" name="remark" value="<?= h((string)$inv['remark']) ?>"></div>
      <div class="fw gr" style="min-width:200px"><label>수정 사유<?= $issued ? ' *' : '' ?></label>
        <input type="text" name="reason" <?= $issued ? 'required' : '' ?> placeholder="예) 지급기한 연장 요청"></div>
      <button class="btn">저장</button>
    </form>
  </div>
</div>
<?php endif; ?>

<div class="card">
  <div class="ch">거래처</div>
  <div class="cb f" style="gap:24px">
    <div><div class="lab" style="font-size:11px;color:var(--ink2)">업체명</div>
      <div style="font-weight:600"><?= h($inv['name_ko']) ?> (<?= h($inv['company_code']) ?>)</div></div>
    <div><div class="lab" style="font-size:11px;color:var(--ink2)">사업자등록번호</div>
      <div class="tnum"><?= h($inv['business_number'] ?: '-') ?></div></div>
    <div><div class="lab" style="font-size:11px;color:var(--ink2)">대표자</div>
      <div><?= h($inv['representative'] ?: '-') ?></div></div>
    <div><div class="lab" style="font-size:11px;color:var(--ink2)">결제조건</div>
      <div><?= h($inv['payment_terms'] ?: '-') ?></div></div>
  </div>
</div>

<div class="card">
  <div class="ch">수록 전표 <span style="font-weight:400;color:var(--ink3)"><?= count($ships) ?>건</span></div>
  <?php if (!$ships): ?>
    <div class="empty">수록된 전표가 없습니다.</div>
  <?php else: ?>
  <table>
    <thead><tr>
      <th class="c" style="width:40px">#</th><th style="width:105px">전표일</th>
      <th style="width:170px">AWB</th><th class="c" style="width:70px">운송사</th>
      <th class="r" style="width:75px">중량</th>
      <th class="r" style="width:115px">영세</th><th class="r" style="width:115px">과세</th>
      <th class="r" style="width:95px">VAT</th><th class="r" style="width:125px">합계</th>
      <?php if ($editable): ?><th class="c" style="width:60px"></th><?php endif; ?>
    </tr></thead>
    <tbody>
    <?php foreach ($ships as $s): ?>
      <tr>
        <td class="c tnum"><?= (int)$s['line_no'] ?></td>
        <td class="tnum"><?= h($s['voucher_date']) ?></td>
        <td class="tnum" style="font-weight:600">
          <a href="?p=shipment_form&amp;id=<?= (int)$s['sid'] ?>"><?= h($s['awb_no']) ?></a></td>
        <td class="c"><?= h($s['carrier']) ?></td>
        <td class="r tnum"><?= $s['charge_weight'] !== null
            ? h(rtrim(rtrim(number_format((float)$s['charge_weight'],2),'0'),'.')) : '-' ?></td>
        <td class="r tnum"><?= money($s['zero_supply']) ?></td>
        <td class="r tnum"><?= money($s['taxable_supply']) ?></td>
        <td class="r tnum"><?= money($s['tax_total']) ?></td>
        <td class="r tnum" style="font-weight:700"><?= money($s['grand_total']) ?></td>
        <?php if ($editable): ?>
        <td class="c">
          <form method="post" style="display:inline"
                onsubmit="return confirm('이 전표를 청구서에서 뺍니다. 계속할까요?');">
            <?= csrf_field() ?>
            <input type="hidden" name="act" value="drop_ship">
            <input type="hidden" name="sid" value="<?= (int)$s['sid'] ?>">
            <input type="hidden" name="reason" value="">
            <button class="btn sm" onclick="this.form.reason.value = prompt('전표를 빼는 사유 (비워도 됩니다)') || '';">빼기</button>
          </form>
        </td>
        <?php endif; ?>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>

<div class="card">
  <div class="ch">조정항목
    <span style="font-weight:400;color:var(--ink3)">전표에 붙지 않는 할인·지연료 등. 차감은 음수로</span>
  </div>
  <?php if ($items): ?>
  <table>
    <thead><tr>
      <th class="c" style="width:40px">#</th><th>항목명</th>
      <th class="r" style="width:130px">공급가액</th><th class="c" style="width:90px">세금</th>
      <th class="r" style="width:100px">VAT</th><th class="r" style="width:130px">합계</th>
      <?php if ($editable): ?><th class="c" style="width:60px"></th><?php endif; ?>
    </tr></thead>
    <tbody>
    <?php foreach ($items as $it): ?>
      <tr>
        <td class="c tnum"><?= (int)$it['line_no'] ?></td>
        <td><?= h($it['item_name']) ?>
          <?php if ($it['remark']): ?><span style="color:var(--ink3);font-size:11.5px"> · <?= h($it['remark']) ?></span><?php endif; ?></td>
        <td class="r tnum" style="color:<?= $it['supply_amount']<0?'var(--err-fg)':'var(--ink)' ?>">
          <?= money($it['supply_amount']) ?></td>
        <td class="c"><?= $it['tax_type']==='TAXABLE'?'과세':($it['tax_type']==='EXEMPT'?'면세':'영세') ?></td>
        <td class="r tnum"><?= money($it['tax_amount']) ?></td>
        <td class="r tnum" style="font-weight:700"><?= money($it['total_amount']) ?></td>
        <?php if ($editable): ?>
        <td class="c">
          <form method="post" style="display:inline">
            <?= csrf_field() ?>
            <input type="hidden" name="act" value="drop_item">
            <input type="hidden" name="iid" value="<?= (int)$it['id'] ?>">
            <button class="btn sm">빼기</button>
          </form>
        </td>
        <?php endif; ?>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
  <?php if ($editable): ?>
  <div class="cb">
    <form method="post" class="f" style="align-items:flex-end">
      <?= csrf_field() ?>
      <input type="hidden" name="act" value="add_item">
      <div class="fw w3"><label>항목명</label>
        <input type="text" name="item_name" placeholder="예) 장기거래 할인"></div>
      <div class="fw w1"><label>공급가액</label>
        <input type="text" name="supply_amount" class="tnum" style="text-align:right"
               placeholder="-50000"></div>
      <div class="fw w1"><label>세금구분</label>
        <select name="tax_type">
          <option value="TAXABLE">과세 10%</option>
          <option value="ZERO">영세율 0%</option>
          <option value="EXEMPT">면세</option>
        </select></div>
      <div class="fw gr" style="min-width:160px"><label>비고</label>
        <input type="text" name="remark"></div>
      <button class="btn">추가</button>
    </form>
  </div>
  <?php endif; ?>
</div>

<div class="card">
  <div class="ch">비고
    <span style="font-weight:400;color:var(--ink3)">청구서 인쇄 화면과 엑셀 아래쪽에 그대로 나옵니다</span></div>
  <form method="post" class="cb">
    <?= csrf_field() ?>
    <input type="hidden" name="act" value="save_remark">
    <div class="fw" style="width:100%">
      <label for="invrem">비고 내용</label>
      <textarea id="invrem" name="remark" rows="3" maxlength="1000"
                placeholder="예) 9월분 운임 청구 · 입금은 10월 22일까지 부탁드립니다"<?= $inv['status'] === 'CANCELLED' ? ' disabled' : '' ?>><?= h((string)$inv['remark']) ?></textarea>
    </div>
    <?php if ($inv['status'] !== 'CANCELLED'): ?>
      <div style="margin-top:10px"><button class="btn pri">비고 저장</button></div>
    <?php endif; ?>
  </form>
</div>

<div class="card">
  <div class="ch">수금 내역
    <a class="btn sm" style="margin-left:auto"
       href="?p=cash_in&amp;company_id=<?= (int)$inv['company_id'] ?>">입금 등록</a>
  </div>
  <?php if (!$pays): ?>
    <div class="empty">수금 내역이 없습니다.</div>
  <?php else: ?>
  <table>
    <thead><tr>
      <th style="width:110px">입금일</th><th style="width:110px">방법</th>
      <th style="width:150px">입금자</th><th>입금계좌</th>
      <th class="r" style="width:130px">금액</th>
    </tr></thead>
    <tbody>
    <?php foreach ($pays as $p): ?>
      <tr>
        <td class="tnum"><?= h($p['paid_at']) ?></td>
        <td><?= h($p['method']) ?></td>
        <td><?= h($p['depositor'] ?: '-') ?></td>
        <td class="tnum" style="font-size:11.5px">
          <?= h(($p['bank_name'] ?? '') . ' ' . ($p['account_no'] ?? '')) ?></td>
        <td class="r tnum" style="font-weight:700"><?= money($p['amount']) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>

<div class="card">
  <div class="ch">변경 이력
    <span style="font-weight:400;color:var(--ink3)">발행 · 재발행 · 내용 수정 · 전표/조정항목 변경이 이전값 → 이후값과 사유로 남습니다. 수록 전표의 수정도 함께 보입니다.</span>
    <a class="btn sm" style="margin-left:auto" href="?p=activity_log&amp;kw=<?= h(rawurlencode($inv['invoice_no'])) ?>">전체 작업로그</a>
  </div>
  <?php if (!$history): ?>
    <div class="empty">기록이 없습니다.</div>
  <?php else: ?>
  <table>
    <thead><tr>
      <th style="width:130px">일시</th><th style="width:90px">담당</th><th style="width:110px">구분</th>
      <th style="width:150px">대상</th><th>내용 (이전 → 이후)</th><th style="width:180px">사유</th>
    </tr></thead>
    <tbody>
    <?php foreach ($history as $hrow): [$al, $ac] = $ACT[$hrow['action']] ?? [$hrow['action'], 'b-info']; ?>
      <tr>
        <td class="tnum" style="font-size:11.5px"><?= h(substr((string)$hrow['created_at'], 0, 16)) ?></td>
        <td><?= h($hrow['admin_name']) ?></td>
        <td><span class="badge <?= $ac ?>"><?= h($hrow['module']) ?> <?= h($al) ?></span></td>
        <td class="tnum" style="font-size:11.5px"><?= h((string)$hrow['ref_label']) ?></td>
        <td style="font-size:11.5px;line-height:1.5;word-break:break-all">
          <?php if ($hrow['before_value'] !== null && $hrow['before_value'] !== ''): ?>
            <div style="color:var(--ink3)">이전: <?= h(mb_substr((string)$hrow['before_value'], 0, 400)) ?></div>
          <?php endif; ?>
          <?php if ($hrow['after_value'] !== null && $hrow['after_value'] !== ''): ?>
            <div>이후: <?= h(mb_substr((string)$hrow['after_value'], 0, 400)) ?></div>
          <?php endif; ?>
        </td>
        <td style="font-size:11.5px"><?= h((string)$hrow['reason']) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>

<?php if ($inv['status'] !== 'CANCELLED'): ?>
<div class="card" style="border-color:#F0D9AE;background:#FFFCF6">
  <div class="ch" style="border-color:#F0D9AE">청구서 취소</div>
  <div class="cb">
    <form method="post" class="f" style="align-items:flex-end"
          onsubmit="return confirm('청구서를 취소하고 수록 전표를 미청구로 되돌립니다. 계속할까요?');">
      <?= csrf_field() ?>
      <input type="hidden" name="act" value="cancel">
      <div class="fw gr" style="min-width:320px"><label>취소 사유 *</label>
        <input type="text" name="reason" required placeholder="예) 기간 착오로 재발행"></div>
      <button class="btn" style="border-color:#C9A257;color:#6B4700">청구 취소</button>
    </form>
    <div style="font-size:11.5px;color:var(--ink3);margin-top:8px">
      청구서는 <b>지워지지 않습니다.</b> 상태만 <code>CANCELLED</code> 가 되고,
      수록됐던 전표는 <b>미청구로 돌아가</b> 다시 청구할 수 있습니다.
      이미 수금된 청구서는 취소할 수 없습니다.
    </div>
  </div>
</div>
<?php endif; ?>
<?php layout_foot();
