<?php
// 웹에서 직접 열면 실행되지 않게 막습니다 (nginx 면 .htaccess 가 무시됩니다)
if (!defined('APP_DIR')) { http_response_code(403); exit('Forbidden'); }

require APP_DIR . '/layout.php';

/**
 * 입출금 내역 + 한 건 상세.
 *
 * **삭제는 없습니다.** 재무 기록이라 지우면 미수금이 조용히 틀어집니다.
 * 취소(역분개)를 쓰면
 *   · 원본은 status='CANCELLED' 로 남고
 *   · 배분이 풀려 미수금이 자동으로 되돌아오고
 *   · 누가 언제 왜 취소했는지 이력이 남습니다
 */

$err = '';

require_perm('CASH_VIEW', '입출금 조회');

// ---------------------------------------------------------------- 취소 (역분개)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('act') === 'cancel') {
    csrf_check();
    require_perm('CASH_CANCEL', '입출금 취소');
    $id     = (int)post('id');
    $reason = trim(post('reason'));

    if (mb_strlen($reason) < 2) {
        $err = '취소 사유를 적어 주세요. 돈이 움직인 기록이라 사유 없이는 취소하지 않습니다.';
    } else {
        $pdo = db();
        try {
            $pdo->beginTransaction();
            $st = $pdo->prepare('SELECT * FROM financial_transactions WHERE id = ? FOR UPDATE');
            $st->execute([$id]);
            $t = $st->fetch();
            if (!$t) {
                throw new RuntimeException('거래를 찾을 수 없습니다.');
            }
            if ($t['status'] === 'CANCELLED') {
                throw new RuntimeException('이미 취소된 거래입니다.');
            }

            $pdo->prepare(
                "UPDATE financial_transactions
                    SET status = 'CANCELLED', cancelled_at = NOW(), cancelled_by = ?,
                        cancel_reason = ?, updated_by = ?
                  WHERE id = ?")
                ->execute([$_SESSION['admin_id'] ?? null, $reason,
                           $_SESSION['admin_id'] ?? null, $id]);

            // 배분을 풀기 **전에** 영향받는 청구서를 찾아 둡니다. 풀고 나면 연결이 사라져
            // 어느 청구서를 다시 셀지 알 수 없습니다
            $iv = $pdo->prepare(
                'SELECT DISTINCT x.invoice_id FROM payment_allocations pa
                   JOIN invoice_shipments x ON x.shipment_id = pa.shipment_id
                  WHERE pa.transaction_id = ?
                 UNION
                 SELECT DISTINCT pa.invoice_id FROM payment_allocations pa
                  WHERE pa.transaction_id = ? AND pa.invoice_id IS NOT NULL');
            $iv->execute([$id, $id]);
            $touchedInvoices = $iv->fetchAll(PDO::FETCH_COLUMN);

            // 배분은 지웁니다 — 남겨두면 미수금이 줄어든 채로 굳습니다.
            // 무엇을 풀었는지는 이력에 남깁니다
            $al = $pdo->prepare('SELECT * FROM payment_allocations WHERE transaction_id = ?');
            $al->execute([$id]);
            $rows = $al->fetchAll();
            foreach ($rows as $a) {
                fin_audit($id, 'UNALLOCATE', 'amount', (string)$a['amount'], '0', $reason,
                          json_encode($a, JSON_UNESCAPED_UNICODE),
                          'payment_allocations', (int)$a['id']);
            }
            $pdo->prepare('DELETE FROM payment_allocations WHERE transaction_id = ?')
                ->execute([$id]);
            $pdo->prepare('UPDATE financial_transactions SET alloc_amount = 0 WHERE id = ?')
                ->execute([$id]);

            // 청구서 수금액을 되돌립니다 — 이게 빠지면 청구관리에서는 여전히 수금된 것으로 보입니다
            foreach ($touchedInvoices as $invId) {
                invoice_recalc((int)$invId);
            }

            fin_audit($id, 'CANCEL', 'status', 'CONFIRMED', 'CANCELLED', $reason,
                      json_encode($t, JSON_UNESCAPED_UNICODE));
            log_action('입출금', 'CANCEL', 'financial_transactions', $id,
                       (string)$t['doc_no'], 'CONFIRMED', 'CANCELLED', $reason);
            $pdo->commit();
            flash('취소했습니다. 배분이 풀려 미수금이 되돌아왔습니다. 기록은 남아 있습니다.');
            redirect('?p=cash_list&id=' . $id);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            $err = $e instanceof RuntimeException ? $e->getMessage() : '취소하지 못했습니다.';
            if (!($e instanceof RuntimeException)) { error_log('취소 실패: ' . $e->getMessage()); }
        }
    }
}

// ---------------------------------------------------------------- 상세
$id = (int)query('id', '0');
$cur = null; $allocs = []; $audit = [];
if ($id > 0) {
    $st = db()->prepare(
        'SELECT f.*, c.name_ko AS company_name, e.name_ko AS entity_name,
                ec.name AS category_name,
                ba.bank_name AS from_bank, ba.account_no AS from_no,
                bb.bank_name AS to_bank,   bb.account_no AS to_no,
                ti.doc_no AS tax_doc_no
           FROM financial_transactions f
           JOIN business_entities e ON e.id = f.business_entity_id
           LEFT JOIN companies c  ON c.id = f.company_id
           LEFT JOIN expense_categories ec ON ec.id = f.category_id
           LEFT JOIN business_bank_accounts ba ON ba.id = f.from_account_id
           LEFT JOIN business_bank_accounts bb ON bb.id = f.to_account_id
           LEFT JOIN tax_invoices ti ON ti.id = f.tax_invoice_id
          WHERE f.id = ?');
    $st->execute([$id]);
    $cur = $st->fetch();

    if ($cur) {
        $st = db()->prepare(
            'SELECT pa.*, s.awb_no, s.voucher_date, i.invoice_no,
                    pu.vendor_name, pu.purchase_date,
                    ob.as_of_date AS ob_date, ob.balance_type AS ob_type
               FROM payment_allocations pa
               LEFT JOIN shipments s ON s.id = pa.shipment_id
               LEFT JOIN invoices i  ON i.id = pa.invoice_id
               LEFT JOIN purchases pu ON pu.id = pa.purchase_id
               LEFT JOIN opening_balances ob ON ob.id = pa.opening_balance_id
              WHERE pa.transaction_id = ? ORDER BY pa.line_no');
        $st->execute([$id]);
        $allocs = $st->fetchAll();

        $st = db()->prepare('SELECT * FROM financial_audit_logs
                              WHERE transaction_id = ? ORDER BY id DESC LIMIT 50');
        $st->execute([$id]);
        $audit = $st->fetchAll();
    }
}

// ---------------------------------------------------------------- 목록
$type   = query('type');
$kw     = query('kw');
$from   = query('from');
$to     = query('to');
$acct   = (int)query('acct', '0');
$status = query('status');
$page   = max(1, (int)query('page', '1'));
$per    = 30;
$off    = ($page - 1) * $per;

$params = [];
$where = [entity_where('f.business_entity_id', $params)];
if (in_array($type, ['IN', 'OUT', 'TRANSFER'], true)) {
    $where[] = 'f.txn_type = ?'; $params[] = $type;
}
if (in_array($status, ['CONFIRMED', 'CANCELLED'], true)) {
    $where[] = 'f.status = ?'; $params[] = $status;
}
if ($acct > 0) {
    $where[] = '(f.from_account_id = ? OR f.to_account_id = ?)';
    $params[] = $acct; $params[] = $acct;
}
if ($kw !== '') {
    $where[] = '(f.doc_no LIKE ? OR f.counterparty LIKE ? OR f.summary LIKE ?
                 OR c.name_ko LIKE ?)';
    $like = '%' . $kw . '%';
    array_push($params, $like, $like, $like, $like);
}
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) { $where[] = 'f.txn_date >= ?'; $params[] = $from; }
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $to))   { $where[] = 'f.txn_date <= ?'; $params[] = $to; }
$w = implode(' AND ', $where);

$st = db()->prepare("SELECT COUNT(*) FROM financial_transactions f
                       LEFT JOIN companies c ON c.id = f.company_id WHERE $w");
$st->execute($params);
$total = (int)$st->fetchColumn();

$st = db()->prepare(
    "SELECT f.*, c.name_ko AS company_name, ec.name AS category_name,
            e.code AS entity_code,
            ba.bank_name AS from_bank, bb.bank_name AS to_bank
       FROM financial_transactions f
       JOIN business_entities e ON e.id = f.business_entity_id
       LEFT JOIN companies c ON c.id = f.company_id
       LEFT JOIN expense_categories ec ON ec.id = f.category_id
       LEFT JOIN business_bank_accounts ba ON ba.id = f.from_account_id
       LEFT JOIN business_bank_accounts bb ON bb.id = f.to_account_id
      WHERE $w ORDER BY f.txn_date DESC, f.id DESC LIMIT $per OFFSET $off");
$st->execute($params);
$rows = $st->fetchAll();

// 조건에 맞는 합계 — 이체는 빼고 셉니다
$st = db()->prepare(
    "SELECT
       COALESCE(SUM(CASE WHEN f.txn_type='IN'  AND f.status='CONFIRMED' THEN f.amount END),0) AS in_sum,
       COALESCE(SUM(CASE WHEN f.txn_type='OUT' AND f.status='CONFIRMED' THEN f.amount END),0) AS out_sum,
       COALESCE(SUM(CASE WHEN f.txn_type='TRANSFER' AND f.status='CONFIRMED' THEN f.amount END),0) AS tr_sum
     FROM financial_transactions f
     LEFT JOIN companies c ON c.id = f.company_id WHERE $w");
$st->execute($params);
$sum = $st->fetch();

$accounts = db()->query('SELECT id, bank_name, account_no FROM business_bank_accounts
                          ORDER BY sort_order, id')->fetchAll();

layout_head('입출금 내역', 'cash_list');
?>
<div class="head">
  <h1>입출금 내역</h1>
  <div class="crumb">입출금관리 &gt; 입출금 내역 · <?= h(entity_label()) ?></div>
</div>

<?php if ($err !== ''): ?><div class="msg err"><?= h($err) ?></div><?php endif; ?>

<?php if ($cur): ?>
<div class="card">
  <div class="ch">
    <span class="tnum" style="font-size:14px"><?= h($cur['doc_no'] ?: ('#' . $cur['id'])) ?></span>
    <span class="badge <?= $cur['txn_type'] === 'IN' ? 'b-ok'
                         : ($cur['txn_type'] === 'OUT' ? 'b-err' : 'b-info') ?>">
      <?= h(txn_type_label($cur['txn_type'])) ?></span>
    <?php if ($cur['status'] === 'CANCELLED'): ?>
      <span class="badge b-err">취소됨</span>
    <?php endif; ?>
    <a class="btn sm" style="margin-left:auto" href="?p=cash_list">목록</a>
  </div>
  <div class="cb f" style="gap:24px;flex-wrap:wrap">
    <div><div style="font-size:11px;color:var(--ink2)">사업자</div>
      <div style="font-weight:600"><?= h($cur['entity_name']) ?></div></div>
    <div><div style="font-size:11px;color:var(--ink2)">일자</div>
      <div class="tnum"><?= h($cur['txn_date']) ?></div></div>
    <div><div style="font-size:11px;color:var(--ink2)">금액</div>
      <div class="tnum" style="font-weight:700;font-size:15px"><?= money($cur['amount']) ?></div></div>
    <?php if ($cur['txn_type'] !== 'TRANSFER'): ?>
      <div><div style="font-size:11px;color:var(--ink2)">거래처 / 상대</div>
        <div><?= h($cur['company_name'] ?: $cur['counterparty'] ?: '-') ?></div></div>
      <div><div style="font-size:11px;color:var(--ink2)">배분됨</div>
        <div class="tnum"><?= money($cur['alloc_amount']) ?>
          <?php $left = (float)$cur['amount'] - (float)$cur['alloc_amount'];
                if ($left > 0): ?>
            <span style="color:var(--warn-fg);font-size:11px">
              (미배분 <?= money($left) ?>)</span>
          <?php endif; ?></div></div>
    <?php else: ?>
      <div><div style="font-size:11px;color:var(--ink2)">출발 → 도착</div>
        <div><?= h($cur['from_bank'] . ' ' . $cur['from_no']) ?> →
             <?= h($cur['to_bank'] . ' ' . $cur['to_no']) ?></div></div>
      <div><div style="font-size:11px;color:var(--ink2)">이체수수료</div>
        <div class="tnum"><?= money($cur['fee']) ?></div></div>
    <?php endif; ?>
    <?php if ($cur['category_name']): ?>
      <div><div style="font-size:11px;color:var(--ink2)">비용분류</div>
        <div><?= h($cur['category_name']) ?></div></div>
    <?php endif; ?>
    <?php if ($cur['tax_doc_no']): ?>
      <div><div style="font-size:11px;color:var(--ink2)">세금계산서</div>
        <div class="tnum"><?= h($cur['tax_doc_no']) ?></div></div>
    <?php endif; ?>
    <div><div style="font-size:11px;color:var(--ink2)">적요</div>
      <div><?= h($cur['summary'] ?: '-') ?></div></div>
  </div>

  <?php if ($allocs): ?>
  <table>
    <thead><tr><th class="c" style="width:45px">#</th><th>충당 대상</th>
      <th style="width:110px">일자</th><th class="r" style="width:150px">배분액</th></tr></thead>
    <tbody>
    <?php foreach ($allocs as $a): ?>
      <tr>
        <td class="c tnum"><?= (int)$a['line_no'] ?></td>
        <td class="tnum">
          <?php if ($a['awb_no']): ?>
            매출전표 <b><?= h($a['awb_no']) ?></b>
          <?php elseif ($a['invoice_no']): ?>
            청구서 <b><?= h($a['invoice_no']) ?></b>
          <?php elseif ($a['vendor_name']): ?>
            매입 <b><?= h($a['vendor_name']) ?></b>
          <?php elseif ($a['ob_date']): ?>
            <b>기초잔액</b> <span style="color:var(--ink3)">(전환일 이전 <?=
              $a['ob_type'] === 'AP' ? '미지급' : '미수' ?>)</span>
          <?php else: ?>-<?php endif; ?>
        </td>
        <td class="tnum"><?= h($a['voucher_date'] ?: $a['purchase_date'] ?: $a['ob_date'] ?: '-') ?></td>
        <td class="r tnum" style="font-weight:600"><?= money($a['amount']) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php elseif ($cur['status'] !== 'CANCELLED'): ?>
    <div class="empty">배분된 전표가 없습니다 — 전액 <?=
      $cur['txn_type'] === 'IN' ? '선수금' : '미배분' ?>입니다.</div>
  <?php endif; ?>

  <?php if ($cur['status'] !== 'CANCELLED'): ?>
  <div class="cb" style="border-top:1px solid var(--line)">
    <form method="post" class="f" style="align-items:flex-end;gap:8px"
          onsubmit="return confirm('이 거래를 취소합니다. 배분이 풀리고 미수금이 되돌아옵니다.');">
      <?= csrf_field() ?>
      <input type="hidden" name="act" value="cancel">
      <input type="hidden" name="id" value="<?= (int)$cur['id'] ?>">
      <div class="fw w3"><label>취소 사유 *</label>
        <input type="text" name="reason" required placeholder="예) 입금자 착오 — 다른 거래처 건"></div>
      <button class="btn" style="border-color:#C9A257;color:#6B4700">취소 (역분개)</button>
      <span style="font-size:11.5px;color:var(--ink3);align-self:center">
        삭제가 아닙니다. 기록은 남고 배분만 풀립니다.</span>
    </form>
  </div>
  <?php else: ?>
  <div class="cb" style="border-top:1px solid var(--line);background:#FDF6F5">
    <b style="color:var(--err-fg)">취소된 거래입니다.</b>
    <span class="tnum" style="font-size:11.5px"><?= h($cur['cancelled_at']) ?></span> ·
    사유 <b><?= h($cur['cancel_reason']) ?></b>
  </div>
  <?php endif; ?>

  <?php if ($audit): ?>
  <div class="ch" style="border-top:1px solid var(--line)">변경 이력</div>
  <table>
    <thead><tr><th style="width:150px">일시</th><th style="width:100px">담당</th>
      <th style="width:90px">작업</th><th style="width:110px">항목</th>
      <th>전 → 후</th><th style="width:180px">사유</th></tr></thead>
    <tbody>
    <?php foreach ($audit as $g): ?>
      <tr>
        <td class="tnum" style="font-size:11.5px"><?= h($g['created_at']) ?></td>
        <td><?= h($g['admin_name']) ?></td>
        <td><?= h($g['action']) ?></td>
        <td class="tnum" style="font-size:11.5px"><?= h($g['field_name'] ?: '-') ?></td>
        <td style="font-size:11.5px">
          <?php if ($g['before_value'] !== null || $g['after_value'] !== null): ?>
            <span style="color:var(--ink3)"><?= h(mb_strimwidth((string)$g['before_value'], 0, 60, '…')) ?></span>
            → <b><?= h(mb_strimwidth((string)$g['after_value'], 0, 60, '…')) ?></b>
          <?php else: ?>-<?php endif; ?>
        </td>
        <td style="font-size:11.5px"><?= h($g['reason'] ?: '-') ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>
<?php endif; ?>

<div class="kpis">
  <div class="kpi"><div class="lab">입금 합계</div>
    <div class="val tnum" style="color:#1B7F5A"><?= money($sum['in_sum']) ?></div>
    <div class="sub">조건에 맞는 것</div></div>
  <div class="kpi"><div class="lab">출금 합계</div>
    <div class="val tnum" style="color:#B3261E"><?= money($sum['out_sum']) ?></div>
    <div class="sub">이체는 빠져 있습니다</div></div>
  <div class="kpi"><div class="lab">차액</div>
    <div class="val tnum"><?= money((float)$sum['in_sum'] - (float)$sum['out_sum']) ?></div>
    <div class="sub">입금 − 출금</div></div>
  <div class="kpi"><div class="lab">계좌이체</div>
    <div class="val tnum" style="color:var(--ink2)"><?= money($sum['tr_sum']) ?></div>
    <div class="sub">비용·손익에 안 잡힘</div></div>
</div>

<div class="card"><div class="cb">
  <form class="f" method="get" style="align-items:flex-end">
    <input type="hidden" name="p" value="cash_list">
    <div class="fw w2"><label for="kw">검색</label>
      <input type="text" id="kw" name="kw" value="<?= h($kw) ?>"
             placeholder="번호 · 거래처 · 상대 · 적요"></div>
    <div class="fw w1"><label for="ty">거래유형</label>
      <select id="ty" name="type">
        <option value="">전체</option>
        <option value="IN"<?= $type==='IN'?' selected':'' ?>>입금</option>
        <option value="OUT"<?= $type==='OUT'?' selected':'' ?>>출금</option>
        <option value="TRANSFER"<?= $type==='TRANSFER'?' selected':'' ?>>계좌이체</option>
      </select></div>
    <div class="fw w1"><label for="ac">계좌</label>
      <select id="ac" name="acct">
        <option value="">전체</option>
        <?php foreach ($accounts as $a): ?>
          <option value="<?= (int)$a['id'] ?>"<?= $acct===(int)$a['id']?' selected':'' ?>>
            <?= h($a['bank_name']) ?> <?= h($a['account_no']) ?></option>
        <?php endforeach; ?>
      </select></div>
    <div class="fw w1"><label for="sf">시작일</label>
      <input type="date" id="sf" name="from" value="<?= h($from) ?>"></div>
    <div class="fw w1"><label for="stt">종료일</label>
      <input type="date" id="stt" name="to" value="<?= h($to) ?>"></div>
    <div class="fw w1"><label for="ss">상태</label>
      <select id="ss" name="status">
        <option value="">전체</option>
        <option value="CONFIRMED"<?= $status==='CONFIRMED'?' selected':'' ?>>확정</option>
        <option value="CANCELLED"<?= $status==='CANCELLED'?' selected':'' ?>>취소</option>
      </select></div>
    <button class="btn">검색</button>
    <a class="btn" href="?p=cash_list">초기화</a>
  </form>
</div></div>

<div class="card">
  <?php if (!$rows): ?>
    <div class="empty">조건에 맞는 거래가 없습니다.</div>
  <?php else: ?>
  <table>
    <thead><tr>
      <th style="width:105px">일자</th><th class="c" style="width:80px">유형</th>
      <th style="width:150px">문서번호</th><th>거래처 / 상대</th>
      <th style="width:110px">분류</th><th style="width:110px">계좌</th>
      <th class="r" style="width:140px">금액</th>
      <th class="r" style="width:120px">배분</th>
      <th class="c" style="width:70px">상태</th><th class="c" style="width:55px"></th>
    </tr></thead>
    <tbody>
    <?php foreach ($rows as $r):
      $isCancel = $r['status'] === 'CANCELLED';
      $col = $r['txn_type'] === 'IN' ? '#1B7F5A' : ($r['txn_type'] === 'OUT' ? '#B3261E' : 'var(--ink2)');
    ?>
      <tr<?= $isCancel ? ' style="color:var(--ink3);text-decoration:line-through"' : '' ?>>
        <td class="tnum"><?= h($r['txn_date']) ?></td>
        <td class="c"><span class="badge <?= $r['txn_type']==='IN'?'b-ok':($r['txn_type']==='OUT'?'b-err':'b-info') ?>">
          <?= h(txn_type_label($r['txn_type'])) ?></span></td>
        <td class="tnum" style="font-size:11.5px"><?= h($r['doc_no'] ?: ('#'.$r['id'])) ?></td>
        <td><?= h($r['company_name'] ?: $r['counterparty'] ?: '-') ?>
          <?php if ($r['summary']): ?>
            <div style="font-size:11px;color:var(--ink3)"><?= h(mb_strimwidth((string)$r['summary'],0,40,'…')) ?></div>
          <?php endif; ?></td>
        <td style="font-size:11.5px"><?= h($r['category_name'] ?: '-') ?></td>
        <td style="font-size:11.5px"><?= h($r['to_bank'] ?: $r['from_bank'] ?: '-') ?></td>
        <td class="r tnum" style="font-weight:700;color:<?= $isCancel ? 'inherit' : $col ?>">
          <?= $r['txn_type']==='OUT' ? '−' : '' ?><?= money($r['amount']) ?></td>
        <td class="r tnum" style="font-size:11.5px;color:var(--ink2)">
          <?= $r['txn_type']==='TRANSFER' ? '-' : money($r['alloc_amount']) ?></td>
        <td class="c"><?= $isCancel
             ? '<span class="badge b-err">취소</span>'
             : '<span class="badge b-ok">확정</span>' ?></td>
        <td class="c"><a class="btn sm" href="?p=cash_list&amp;id=<?= (int)$r['id'] ?>">열기</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <div class="pager">
    <span>전체 <b class="tnum"><?= money($total) ?></b> 건 ·
      <span class="tnum"><?= money($off+1) ?>–<?= money(min($off+$per, $total)) ?></span></span>
    <div class="right">
      <?php $qs = 'p=cash_list&type=' . urlencode($type) . '&kw=' . urlencode($kw)
                . '&from=' . urlencode($from) . '&to=' . urlencode($to)
                . '&acct=' . $acct . '&status=' . urlencode($status); ?>
      <?php if ($page > 1): ?>
        <a class="btn sm" href="?<?= h($qs) ?>&amp;page=<?= $page-1 ?>">이전</a><?php endif; ?>
      <?php if ($off + $per < $total): ?>
        <a class="btn sm" href="?<?= h($qs) ?>&amp;page=<?= $page+1 ?>">다음</a><?php endif; ?>
    </div>
  </div>
  <?php endif; ?>
</div>
<?php layout_foot();
