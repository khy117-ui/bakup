<?php
// 웹에서 직접 열면 실행되지 않게 막습니다 (nginx 면 .htaccess 가 무시됩니다)
if (!defined('APP_DIR')) { http_response_code(403); exit('Forbidden'); }

require_once APP_DIR . '/layout.php';

/**
 * 입금확인서 — 거래처에 "이 입금들을 받았습니다" 를 확인해 주는 문서.
 *   · 거래처 · 기간을 골라 확정된 입금(IN · CONFIRMED)을 묶어 발행합니다. 금액은 발행 시점에 고정됩니다.
 *   · 수록 입금이 취소되거나 금액이 바뀌면 "재발행 필요" 가 표시되고, [재발행] 하면 현재 값으로 다시 계산해
 *     차수(REV.)가 올라갑니다. 발행 · 재발행 · 취소는 작업로그에 이전값 · 이후값 · 사유와 함께 남습니다.
 */

$eid = entity_id();
$err = '';
schema_upgrade_receipts();

$METHOD = ['TRANSFER' => '계좌이체', 'CARD' => '카드', 'CASH' => '현금', 'NOTE' => '어음', 'PG' => 'PG'];
$STATUS = ['ISSUED' => ['발행', 'b-ok'], 'CANCELLED' => ['취소', 'b-err']];

// ---------------------------------------------------------------- 생성
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('act') === 'create') {
    csrf_check();
    $cid   = (int)post('company_id');
    $pfrom = post('period_from');
    $pto   = post('period_to');
    $rdate = post('receipt_date', date('Y-m-d'));
    $picked = $_POST['txn'] ?? [];
    $picked = is_array($picked) ? array_values(array_unique(array_filter(array_map('intval', $picked)))) : [];
    $ok = fn($d) => (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', $d);

    if ($cid <= 0) {
        $err = '거래처를 선택하세요.';
    } elseif (!$ok($pfrom) || !$ok($pto) || !$ok($rdate)) {
        $err = '기간과 확인서 일자를 입력하세요.';
    } elseif (!$picked) {
        $err = '입금을 한 건 이상 선택하세요.';
    } else {
        $pdo = db();
        try {
            $pdo->beginTransaction();
            $ph = implode(',', array_fill(0, count($picked), '?'));
            // 고른 입금이 이 거래처의 확정 입금이 맞는지 다시 확인합니다
            $st = $pdo->prepare(
                "SELECT id, amount FROM financial_transactions
                  WHERE id IN ($ph) AND business_entity_id = ? AND company_id = ?
                    AND txn_type = 'IN' AND status = 'CONFIRMED'
                  ORDER BY txn_date, id");
            $st->execute(array_merge($picked, [$eid, $cid]));
            $rows = $st->fetchAll();
            if (count($rows) !== count($picked)) {
                throw new RuntimeException('취소됐거나 조건에 맞지 않는 입금이 섞여 있습니다.');
            }
            $sum = 0.0;
            foreach ($rows as $r) { $sum += (float)$r['amount']; }
            $no = next_doc_no('RECEIPT', 'GPA-RC-', '-');
            $pdo->prepare(
                'INSERT INTO receipts
                   (business_entity_id, receipt_no, company_id, receipt_date, period_from, period_to,
                    amount_total, txn_count, status, issued_at, issue_count, revision, remark, created_by)
                 VALUES (?,?,?,?,?,?,?,?,\'ISSUED\',NOW(),1,1,?,?)')
                ->execute([$eid, $no, $cid, $rdate, $pfrom, $pto, $sum, count($rows),
                           post('remark') !== '' ? mb_substr(post('remark'), 0, 255) : null, $_SESSION['admin_id'] ?? null]);
            $rid = (int)$pdo->lastInsertId();
            $ins = $pdo->prepare('INSERT INTO receipt_transactions (receipt_id, transaction_id, line_no, amount) VALUES (?,?,?,?)');
            $n = 0;
            foreach ($rows as $r) { $ins->execute([$rid, (int)$r['id'], ++$n, (float)$r['amount']]); }
            log_action('입금확인서', 'CREATE', 'receipts', $rid, $no, null, receipt_snapshot($rid));
            $pdo->commit();
            flash('입금확인서 ' . $no . ' 를 발행했습니다.');
            redirect('?p=receipts&id=' . $rid);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            error_log('입금확인서 생성 실패: ' . $e->getMessage());
            $err = $e instanceof RuntimeException ? $e->getMessage() : '만들지 못했습니다.';
        }
    }
}

// ---------------------------------------------------------------- 재발행 · 취소
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array(post('act'), ['reissue', 'cancel'], true)) {
    csrf_check();
    $rid = (int)post('id');
    $why = trim(post('reason'));
    $st = db()->prepare('SELECT * FROM receipts WHERE id = ? AND business_entity_id = ? AND deleted_at IS NULL');
    $st->execute([$rid, $eid]);
    $rc = $st->fetch();
    $ok = fn($d) => (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', $d);
    if (!$rc) {
        $err = '입금확인서를 찾을 수 없습니다.';
    } elseif (mb_strlen($why) < 2) {
        $err = (post('act') === 'cancel' ? '취소' : '재발행') . ' 사유를 적어 주세요.';
    } elseif ($rc['status'] === 'CANCELLED') {
        $err = '취소된 확인서입니다. 필요하면 새로 만드세요.';
    } elseif (post('act') === 'cancel') {
        $before = receipt_snapshot($rid);
        db()->prepare("UPDATE receipts SET status = 'CANCELLED' WHERE id = ?")->execute([$rid]);
        log_action('입금확인서', 'CANCEL', 'receipts', $rid, (string)$rc['receipt_no'], $before, receipt_snapshot($rid), $why);
        flash('입금확인서를 취소했습니다. 문서는 지워지지 않고 취소 상태로 남습니다.');
        redirect('?p=receipts');
    } else {
        $rdate = post('receipt_date', (string)$rc['receipt_date']);
        $pfrom = post('period_from');
        $pto   = post('period_to');
        if (!$ok($rdate) || ($pfrom !== '' && !$ok($pfrom)) || ($pto !== '' && !$ok($pto))) {
            $err = '날짜 형식이 올바르지 않습니다.';
        } else {
            $pdo = db();
            try {
                $pdo->beginTransaction();
                $before = receipt_snapshot($rid);
                // 취소된 입금은 뺍니다
                $gone = $pdo->prepare("SELECT x.transaction_id, f.doc_no FROM receipt_transactions x
                                         JOIN financial_transactions f ON f.id = x.transaction_id
                                        WHERE x.receipt_id = ? AND (f.status <> 'CONFIRMED' OR f.txn_type <> 'IN')");
                $gone->execute([$rid]);
                $dropped = $gone->fetchAll();
                if ($dropped) {
                    $ph = implode(',', array_fill(0, count($dropped), '?'));
                    $pdo->prepare("DELETE FROM receipt_transactions WHERE receipt_id = ? AND transaction_id IN ($ph)")
                        ->execute(array_merge([$rid], array_map(fn($d) => (int)$d['transaction_id'], $dropped)));
                }
                // 남은 입금은 현재 금액으로 다시 적습니다
                $pdo->prepare('UPDATE receipt_transactions x JOIN financial_transactions f ON f.id = x.transaction_id
                                  SET x.amount = f.amount WHERE x.receipt_id = ?')->execute([$rid]);
                $live = receipt_live_totals($rid);
                if ($live['cnt'] === 0) {
                    throw new RuntimeException('남은 입금이 없어 재발행할 수 없습니다. 확인서를 취소하고 새로 만드세요.');
                }
                $pdo->prepare('UPDATE receipts
                                  SET amount_total = ?, txn_count = ?, receipt_date = ?, period_from = ?, period_to = ?, remark = ?,
                                      status = \'ISSUED\', issued_at = NOW(), issue_count = issue_count + 1,
                                      revision = revision + 1, reissue_reason = ?
                                WHERE id = ?')
                    ->execute([$live['amount_total'], $live['cnt'], $rdate,
                               $pfrom !== '' ? $pfrom : $rc['period_from'], $pto !== '' ? $pto : $rc['period_to'],
                               post('remark') !== '' ? mb_substr(post('remark'), 0, 255) : null, $why, $rid]);
                $after = receipt_snapshot($rid) . ($dropped ? ' · 뺀 입금: ' . implode(', ', array_map(fn($d) => $d['doc_no'] ?: '#' . $d['transaction_id'], $dropped)) : '');
                log_action('입금확인서', 'ISSUE', 'receipts', $rid, (string)$rc['receipt_no'], $before, $after, $why);
                $pdo->commit();
                flash('입금확인서를 재발행했습니다 (REV. ' . ((int)$rc['revision'] + 1) . '). 출력물을 다시 보내세요.'
                      . ($dropped ? ' 취소된 입금 ' . count($dropped) . '건은 뺐습니다.' : ''));
                redirect('?p=receipts&id=' . $rid);
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) { $pdo->rollBack(); }
                error_log('입금확인서 재발행 실패: ' . $e->getMessage());
                $err = $e instanceof RuntimeException ? $e->getMessage() : '재발행하지 못했습니다.';
            }
        }
    }
}

// ---------------------------------------------------------------- 대상 입금
$selCid  = (int)query('company_id', '0');
$selFrom = query('period_from', date('Y-m-01'));
$selTo   = query('period_to', date('Y-m-t'));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $selFrom)) { $selFrom = date('Y-m-01'); }
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $selTo))   { $selTo   = date('Y-m-t'); }

$companies = db()->prepare('SELECT id, company_code, name_ko FROM companies WHERE deleted_at IS NULL ORDER BY name_ko');
$companies->execute();
$companies = $companies->fetchAll();

$cand = [];
if ($selCid > 0) {
    $st = db()->prepare(
        "SELECT f.id, f.doc_no, f.txn_date, f.method, f.counterparty, f.amount, f.summary,
                b.bank_name, b.account_no,
                (SELECT GROUP_CONCAT(DISTINCT COALESCE(i.invoice_no, s.awb_no) ORDER BY pa.line_no SEPARATOR ', ')
                   FROM payment_allocations pa
                   LEFT JOIN invoices i ON i.id = pa.invoice_id
                   LEFT JOIN shipments s ON s.id = pa.shipment_id
                  WHERE pa.transaction_id = f.id) AS applied,
                (SELECT COUNT(*) FROM receipt_transactions x JOIN receipts r ON r.id = x.receipt_id
                  WHERE x.transaction_id = f.id AND r.status <> 'CANCELLED' AND r.deleted_at IS NULL) AS used
           FROM financial_transactions f
           LEFT JOIN business_bank_accounts b ON b.id = f.to_account_id
          WHERE f.business_entity_id = ? AND f.company_id = ? AND f.txn_type = 'IN' AND f.status = 'CONFIRMED'
            AND f.txn_date BETWEEN ? AND ?
          ORDER BY f.txn_date, f.id");
    $st->execute([$eid, $selCid, $selFrom, $selTo]);
    $cand = $st->fetchAll();
}
$candSum = 0.0;
foreach ($cand as $c) { $candSum += (float)$c['amount']; }

// ---------------------------------------------------------------- 상세 (?id=)
$cur = null; $curTxns = []; $curLive = null; $curMismatch = false; $history = [];
$curId = (int)query('id', '0');
if ($curId > 0) {
    $st = db()->prepare('SELECT r.*, c.name_ko, c.company_code FROM receipts r JOIN companies c ON c.id = r.company_id
                          WHERE r.id = ? AND r.business_entity_id = ? AND r.deleted_at IS NULL');
    $st->execute([$curId, $eid]);
    $cur = $st->fetch() ?: null;
    if ($cur) {
        $st = db()->prepare(
            "SELECT x.line_no, x.amount AS amount_at_issue, f.id AS fid, f.doc_no, f.txn_date, f.method, f.counterparty,
                    f.amount, f.status AS txn_status, f.summary, b.bank_name, b.account_no
               FROM receipt_transactions x
               JOIN financial_transactions f ON f.id = x.transaction_id
               LEFT JOIN business_bank_accounts b ON b.id = f.to_account_id
              WHERE x.receipt_id = ? ORDER BY x.line_no");
        $st->execute([$curId]);
        $curTxns = $st->fetchAll();
        $curLive = receipt_live_totals($curId);
        $curMismatch = $cur['status'] === 'ISSUED'
                    && (abs($curLive['amount_total'] - (float)$cur['amount_total']) > 0.5 || $curLive['cnt'] !== count($curTxns));
        $st = db()->prepare("SELECT * FROM activity_logs WHERE ref_table = 'receipts' AND ref_id = ? ORDER BY id DESC LIMIT 100");
        $st->execute([$curId]);
        $history = $st->fetchAll();
    }
}
$ACT = ['CREATE' => ['발행', 'b-ok'], 'UPDATE' => ['수정', 'b-info'], 'CANCEL' => ['취소', 'b-err'],
        'ISSUE' => ['재발행', 'b-info'], 'PRINT' => ['출력', 'b-warn']];

// ---------------------------------------------------------------- 목록
$st = db()->prepare(
    'SELECT r.*, c.name_ko FROM receipts r JOIN companies c ON c.id = r.company_id
      WHERE r.business_entity_id = ? AND r.deleted_at IS NULL
      ORDER BY r.receipt_date DESC, r.id DESC LIMIT 100');
$st->execute([$eid]);
$rows = $st->fetchAll();
$liveMap = [];
if ($rows) {
    $ids = array_map(fn($r) => (int)$r['id'], $rows);
    $st = db()->prepare("SELECT x.receipt_id, COALESCE(SUM(f.amount),0) AS amt, COUNT(*) AS cnt
                           FROM receipt_transactions x
                           JOIN financial_transactions f ON f.id = x.transaction_id AND f.status = 'CONFIRMED' AND f.txn_type = 'IN'
                          WHERE x.receipt_id IN (" . implode(',', array_fill(0, count($ids), '?')) . ") GROUP BY x.receipt_id");
    $st->execute($ids);
    foreach ($st->fetchAll() as $lr) { $liveMap[(int)$lr['receipt_id']] = [(float)$lr['amt'], (int)$lr['cnt']]; }
}

layout_head('입금확인서', 'receipts');
?>
<div class="head">
  <h1>입금확인서</h1>
  <div class="crumb">입출금관리 &gt; 입금확인서</div>
</div>

<?php if ($err !== ''): ?><div class="msg err"><?= h($err) ?></div><?php endif; ?>

<?php if ($cur): [$slab, $scls] = $STATUS[$cur['status']] ?? [$cur['status'], 'b-info']; ?>
<div class="card">
  <div class="ch">
    <span class="tnum" style="font-size:14px"><?= h($cur['receipt_no']) ?></span>
    <span class="badge <?= $scls ?>"><?= h($slab) ?> <?= (int)$cur['issue_count'] ?>회</span>
    <?php if ((int)$cur['revision'] > 1): ?><span class="badge b-info">REV. <?= (int)$cur['revision'] ?></span><?php endif; ?>
    <span style="font-weight:400;color:var(--ink3)"><?= h($cur['name_ko']) ?> · <?= h($cur['receipt_date']) ?>
      · 기간 <?= h($cur['period_from'] ?: '-') ?> ~ <?= h($cur['period_to'] ?: '-') ?>
      · 최종 발행 <?= h(substr((string)$cur['issued_at'], 0, 16)) ?><?= !empty($cur['reissue_reason']) ? ' · 사유: ' . h($cur['reissue_reason']) : '' ?></span>
    <a class="btn sm" style="margin-left:auto" href="?p=receipt_print&amp;id=<?= (int)$cur['id'] ?>" target="_blank">출력</a>
    <a class="btn sm" href="?p=receipts">목록</a>
  </div>
  <?php if ($curMismatch): ?>
  <div class="msg err" style="margin:10px 12px 0">
    수록 입금의 현재 내용이 확인서와 다릅니다 — 확인서 합계 <b class="tnum"><?= money($cur['amount_total']) ?></b>
    → 현재 입금 합계 <b class="tnum"><?= money($curLive['amount_total']) ?></b>
    <?= $curLive['cnt'] !== count($curTxns) ? ' (취소된 입금 ' . (count($curTxns) - $curLive['cnt']) . '건 포함)' : '' ?>.
    아래 <b>[재발행]</b> 으로 현재 값으로 다시 발행하세요. 재발행 전 출력물에는 이전 금액이 나갑니다.
  </div>
  <?php endif; ?>
  <table>
    <thead><tr>
      <th class="c" style="width:40px">#</th><th style="width:150px">입금번호</th><th style="width:100px">입금일</th>
      <th style="width:80px">방법</th><th style="width:140px">입금자</th><th>입금계좌 · 적요</th>
      <th class="c" style="width:70px">상태</th>
      <th class="r" style="width:120px">발행 시 금액</th><th class="r" style="width:120px">현재 금액</th>
    </tr></thead>
    <tbody>
    <?php foreach ($curTxns as $t): $dead = $t['txn_status'] !== 'CONFIRMED'; ?>
      <tr style="<?= $dead ? 'color:var(--err-fg)' : '' ?>">
        <td class="c tnum"><?= (int)$t['line_no'] ?></td>
        <td class="tnum" style="font-weight:600"><a href="?p=cash_list&amp;id=<?= (int)$t['fid'] ?>"><?= h($t['doc_no'] ?: '#' . $t['fid']) ?></a></td>
        <td class="tnum"><?= h($t['txn_date']) ?></td>
        <td><?= h($METHOD[$t['method']] ?? $t['method']) ?></td>
        <td><?= h($t['counterparty'] ?: '-') ?></td>
        <td style="font-size:11.5px"><?= h(trim(($t['bank_name'] ?? '') . ' ' . ($t['account_no'] ?? ''))) ?><?= $t['summary'] ? ' · ' . h($t['summary']) : '' ?></td>
        <td class="c"><?= $dead ? '취소' : '확정' ?></td>
        <td class="r tnum"><?= money($t['amount_at_issue']) ?></td>
        <td class="r tnum" style="font-weight:700"><?= $dead ? '-' : money($t['amount']) ?></td>
      </tr>
    <?php endforeach; ?>
      <tr style="background:#F7FAFB">
        <td colspan="7" class="r" style="font-weight:700">확인서 저장값 / 현재 합계</td>
        <td class="r tnum"><?= money($cur['amount_total']) ?></td>
        <td class="r tnum" style="font-weight:700;color:<?= $curMismatch ? 'var(--err-fg)' : 'var(--ink)' ?>"><?= money($curLive['amount_total']) ?></td>
      </tr>
    </tbody>
  </table>
  <?php if ($cur['status'] === 'ISSUED'): ?>
  <div class="cb" style="border-top:1px solid var(--line)">
    <form method="post" class="f" style="align-items:flex-end;gap:8px"
          onsubmit="return confirm('수록 입금의 현재 내용으로 확인서를 다시 발행합니다. 차수(REV.)가 올라가고 이전 내용과 사유는 변경 이력에 남습니다. 계속할까요?');">
      <?= csrf_field() ?>
      <input type="hidden" name="act" value="reissue">
      <input type="hidden" name="id" value="<?= (int)$cur['id'] ?>">
      <div class="fw w1"><label>확인서 일자</label><input type="date" name="receipt_date" value="<?= h($cur['receipt_date']) ?>" required></div>
      <div class="fw w1"><label>기간 시작</label><input type="date" name="period_from" value="<?= h((string)$cur['period_from']) ?>"></div>
      <div class="fw w1"><label>기간 종료</label><input type="date" name="period_to" value="<?= h((string)$cur['period_to']) ?>"></div>
      <div class="fw w2"><label>비고 (출력물)</label><input type="text" name="remark" value="<?= h((string)$cur['remark']) ?>"></div>
      <div class="fw gr" style="min-width:240px"><label>재발행 사유 *</label>
        <input type="text" name="reason" required placeholder="예) 입금 취소분 제외, 금액 정정"></div>
      <button class="btn <?= $curMismatch ? 'pri' : '' ?>">재발행</button>
    </form>
    <form method="post" class="f" style="align-items:flex-end;gap:8px;margin-top:10px"
          onsubmit="return confirm('입금확인서를 취소 처리합니다. 문서는 지워지지 않고 취소 상태로 남습니다.');">
      <?= csrf_field() ?>
      <input type="hidden" name="act" value="cancel">
      <input type="hidden" name="id" value="<?= (int)$cur['id'] ?>">
      <div class="fw w3"><label>취소 사유 *</label><input type="text" name="reason" required placeholder="예) 잘못 발행"></div>
      <button class="btn" style="border-color:#C9A257;color:#6B4700">확인서 취소</button>
    </form>
    <div style="font-size:11.5px;color:var(--ink3);margin-top:8px">
      확인서 번호는 그대로 두고 금액 · 일자 · 비고를 현재 값으로 다시 적습니다. 취소된 입금은 이때 확인서에서 빠집니다.
      입금을 더하거나 빼려면 아래에서 새 확인서를 만드세요.
    </div>
  </div>
  <?php endif; ?>
</div>

<div class="card">
  <div class="ch">변경 이력
    <span style="font-weight:400;color:var(--ink3)">발행 · 재발행 · 취소가 이전값 → 이후값과 사유로 남습니다</span>
    <a class="btn sm" style="margin-left:auto" href="?p=activity_log&amp;kw=<?= h(rawurlencode((string)$cur['receipt_no'])) ?>">전체 작업로그</a>
  </div>
  <?php if (!$history): ?>
    <div class="empty">기록이 없습니다.</div>
  <?php else: ?>
  <table>
    <thead><tr>
      <th style="width:130px">일시</th><th style="width:90px">담당</th><th style="width:80px">구분</th>
      <th>내용 (이전 → 이후)</th><th style="width:180px">사유</th>
    </tr></thead>
    <tbody>
    <?php foreach ($history as $hrow): [$al, $ac] = $ACT[$hrow['action']] ?? [$hrow['action'], 'b-info']; ?>
      <tr>
        <td class="tnum" style="font-size:11.5px"><?= h(substr((string)$hrow['created_at'], 0, 16)) ?></td>
        <td><?= h($hrow['admin_name']) ?></td>
        <td><span class="badge <?= $ac ?>"><?= h($al) ?></span></td>
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
<?php endif; ?>

<div class="card">
  <div class="ch">입금확인서 만들기
    <span style="font-weight:400;color:var(--ink3)">거래처와 기간을 고르면 확정된 입금이 나옵니다. 골라서 한 장으로 묶어 발행합니다</span>
  </div>
  <div class="cb">
    <form class="f" method="get" style="align-items:flex-end">
      <input type="hidden" name="p" value="receipts">
      <div class="fw w3"><label for="cid">거래처</label>
        <select id="cid" name="company_id">
          <option value="0">선택하세요</option>
          <?php foreach ($companies as $c): ?>
            <option value="<?= (int)$c['id'] ?>"<?= $selCid===(int)$c['id']?' selected':'' ?>><?= h($c['name_ko']) ?> (<?= h($c['company_code']) ?>)</option>
          <?php endforeach; ?>
        </select></div>
      <div class="fw w1"><label for="pf">기간 시작</label><input type="date" id="pf" name="period_from" value="<?= h($selFrom) ?>"></div>
      <div class="fw w1"><label for="pt">기간 종료</label><input type="date" id="pt" name="period_to" value="<?= h($selTo) ?>"></div>
      <button class="btn">입금 조회</button>
    </form>
  </div>
  <?php if ($selCid > 0): ?>
    <?php if (!$cand): ?>
      <div class="empty">이 기간에 확정된 입금이 없습니다.</div>
    <?php else: ?>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="act" value="create">
      <input type="hidden" name="company_id" value="<?= $selCid ?>">
      <input type="hidden" name="period_from" value="<?= h($selFrom) ?>">
      <input type="hidden" name="period_to" value="<?= h($selTo) ?>">
      <table>
        <thead><tr>
          <th class="c" style="width:40px"><input type="checkbox" id="all"></th>
          <th style="width:150px">입금번호</th><th style="width:100px">입금일</th><th style="width:80px">방법</th>
          <th style="width:140px">입금자</th><th>입금계좌 · 충당</th>
          <th class="r" style="width:125px">금액</th><th class="c" style="width:70px">기존</th>
        </tr></thead>
        <tbody>
        <?php foreach ($cand as $c): ?>
          <tr>
            <td class="c"><input type="checkbox" class="pick" name="txn[]" value="<?= (int)$c['id'] ?>" checked></td>
            <td class="tnum" style="font-weight:600"><?= h($c['doc_no'] ?: '#' . $c['id']) ?></td>
            <td class="tnum"><?= h($c['txn_date']) ?></td>
            <td><?= h($METHOD[$c['method']] ?? $c['method']) ?></td>
            <td><?= h($c['counterparty'] ?: '-') ?></td>
            <td style="font-size:11.5px"><?= h(trim(($c['bank_name'] ?? '') . ' ' . ($c['account_no'] ?? ''))) ?><?= $c['applied'] ? ' · ' . h($c['applied']) : '' ?></td>
            <td class="r tnum" style="font-weight:700"><?= money($c['amount']) ?></td>
            <td class="c"><?= $c['used'] ? '<span class="badge b-info">' . (int)$c['used'] . '회</span>' : '' ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <div class="cb f" style="border-top:1px solid var(--line);align-items:flex-end">
        <div class="fw w1"><label>확인서 일자</label><input type="date" name="receipt_date" value="<?= h(date('Y-m-d')) ?>"></div>
        <div class="fw w3"><label>비고 (출력물에 표시)</label><input type="text" name="remark" placeholder="예) 2026년 9월분 운송료 입금"></div>
        <div style="margin-left:auto;text-align:right">
          <div style="font-size:11.5px;color:var(--ink2)">선택한 입금 합계</div>
          <div class="tnum" style="font-size:19px;font-weight:700"><?= money($candSum) ?></div>
        </div>
        <button class="btn pri">확인서 발행</button>
      </div>
      <div class="cb" style="font-size:11.5px;color:var(--ink3)">
        확인서는 입금 기록을 바꾸지 않습니다. 같은 입금으로 여러 번 만들 수 있고, '기존' 칸은 그 입금이 이미 확인서에 몇 번 실렸는지입니다.
      </div>
    </form>
    <script>
    document.getElementById('all').addEventListener('change', function () {
      document.querySelectorAll('.pick').forEach(function (c) { c.checked = this.checked; }, this);
    });
    </script>
    <?php endif; ?>
  <?php endif; ?>
</div>

<div class="card">
  <div class="ch">확인서 목록</div>
  <?php if (!$rows): ?>
    <div class="empty">입금확인서가 없습니다.</div>
  <?php else: ?>
  <table>
    <thead><tr>
      <th style="width:165px">확인서번호</th><th style="width:105px">일자</th><th>거래처</th>
      <th style="width:170px">대상기간</th><th class="r" style="width:60px">입금</th>
      <th class="r" style="width:130px">합계</th><th class="c" style="width:90px">상태</th><th class="c" style="width:120px"></th>
    </tr></thead>
    <tbody>
    <?php foreach ($rows as $r): [$lab, $cls] = $STATUS[$r['status']] ?? [$r['status'], 'b-info'];
          $lv = $liveMap[(int)$r['id']] ?? [0.0, 0];
          $chg = $r['status'] === 'ISSUED' && (abs($lv[0] - (float)$r['amount_total']) > 0.5 || $lv[1] !== (int)$r['txn_count']); ?>
      <tr>
        <td class="tnum" style="font-weight:600"><?= h($r['receipt_no']) ?></td>
        <td class="tnum"><?= h($r['receipt_date']) ?></td>
        <td><?= h($r['name_ko']) ?></td>
        <td class="tnum" style="font-size:11.5px;color:var(--ink2)"><?= h($r['period_from'] ?: '-') ?> ~ <?= h($r['period_to'] ?: '-') ?></td>
        <td class="r tnum"><?= (int)$r['txn_count'] ?></td>
        <td class="r tnum" style="font-weight:700"><?= money($r['amount_total']) ?>
          <?php if ((int)$r['revision'] > 1): ?><div style="font-size:10.5px;color:var(--ink3);font-weight:400">REV. <?= (int)$r['revision'] ?></div><?php endif; ?>
          <?php if ($chg): ?><div style="font-size:10.5px;color:var(--err-fg);font-weight:400">입금 바뀜 · 재발행 필요</div><?php endif; ?></td>
        <td class="c"><span class="badge <?= $cls ?>"><?= h($lab) ?></span></td>
        <td class="c"><a class="btn sm" href="?p=receipts&amp;id=<?= (int)$r['id'] ?>">열기</a>
          <a class="btn sm" href="?p=receipt_print&amp;id=<?= (int)$r['id'] ?>" target="_blank">출력</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>
<?php layout_foot();
