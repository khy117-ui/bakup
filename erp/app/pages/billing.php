<?php
require_once APP_DIR . '/layout.php';

$eid = entity_id();
$err = '';
schema_upgrade_invoices();   // 재발행 컬럼(issued_at · issue_count · changed_at) 보강

// ---------------------------------------------------------------- 지난 청구 정리
// 옛 전산에서 이미 청구한 전표를 날짜 기준으로 한 번에 '청구' 로 표시합니다 (청구서는 만들지 않음).
// 바꾼 전표 번호를 billing_bulk_marks 에 남겨 두어 [되돌리기] 로 정확히 되돌립니다. (2026-10-12)
function bill_marks_table(): void
{
    db()->exec("CREATE TABLE IF NOT EXISTS billing_bulk_marks (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        business_entity_id BIGINT UNSIGNED NOT NULL,
        cutoff_date DATE NOT NULL,
        shipment_ids MEDIUMTEXT NOT NULL,
        cnt INT NOT NULL DEFAULT 0,
        amount DECIMAL(15,2) NOT NULL DEFAULT 0,
        created_by BIGINT UNSIGNED NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        undone_at DATETIME NULL,
        PRIMARY KEY (id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='지난 청구 정리 기록'");
}

/** 날짜까지의 미청구 전표 (임시 · 확정, 청구서에 안 담긴 것) */
function bill_marks_targets(int $eid, string $cutoff): array
{
    $st = db()->prepare("SELECT s.id, COALESCE(t.grand_total, 0) AS amt
                           FROM shipments s
                           LEFT JOIN v_shipment_totals t ON t.shipment_id = s.id
                           LEFT JOIN invoice_shipments xs ON xs.shipment_id = s.id
                          WHERE s.business_entity_id = ? AND s.deleted_at IS NULL
                            AND s.status IN ('DRAFT', 'CONFIRMED') AND xs.id IS NULL
                            AND s.voucher_date <= ?");
    $st->execute([$eid, $cutoff]);
    return $st->fetchAll();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array(post('act'), ['mark_billed', 'mark_undo'], true)) {
    csrf_check();
    bill_marks_table();
    $pdo = db();
    try {
        if (post('act') === 'mark_billed') {
            $cutoff = post('cutoff');
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $cutoff)) { throw new RuntimeException('기준 날짜를 고르세요.'); }
            if ($cutoff >= date('Y-m-d')) { throw new RuntimeException('오늘 이전 날짜만 됩니다.'); }
            $rows = bill_marks_targets($eid, $cutoff);
            if (!$rows) { throw new RuntimeException($cutoff . ' 까지 미청구 전표가 없습니다.'); }
            $ids = array_map(static fn ($r) => (int)$r['id'], $rows);
            $amt = array_sum(array_map(static fn ($r) => (float)$r['amt'], $rows));
            $pdo->beginTransaction();
            foreach (array_chunk($ids, 500) as $chunk) {
                $ph = implode(',', array_fill(0, count($chunk), '?'));
                $pdo->prepare("UPDATE shipments SET status = 'BILLED'
                                WHERE id IN ($ph) AND status IN ('DRAFT', 'CONFIRMED')")->execute($chunk);
            }
            $pdo->prepare('INSERT INTO billing_bulk_marks (business_entity_id, cutoff_date, shipment_ids, cnt, amount, created_by)
                           VALUES (?, ?, ?, ?, ?, ?)')
                ->execute([$eid, $cutoff, implode(',', $ids), count($ids), $amt, $_SESSION['admin_id'] ?? null]);
            $mid = (int)$pdo->lastInsertId();
            log_action('청구', 'UPDATE', 'billing_bulk_marks', $mid, $cutoff . ' 까지',
                       null, count($ids) . '건 청구 표시 · ' . money($amt) . '원', post('reason') ?: '지난 청구 정리');
            $pdo->commit();
            flash($cutoff . ' 까지 미청구 전표 ' . number_format(count($ids)) . '건(' . money($amt)
                . '원)을 \'청구\' 로 표시했습니다. 잘못했으면 아래 [되돌리기] 를 누르세요.');
        } else {
            $mid = (int)post('mark_id');
            $st = $pdo->prepare('SELECT * FROM billing_bulk_marks WHERE id = ? AND business_entity_id = ? AND undone_at IS NULL');
            $st->execute([$mid, $eid]);
            $m = $st->fetch();
            if (!$m) { throw new RuntimeException('되돌릴 기록이 없습니다.'); }
            $ids = array_values(array_filter(array_map('intval', explode(',', (string)$m['shipment_ids']))));
            $pdo->beginTransaction();
            $back = 0;
            foreach (array_chunk($ids, 500) as $chunk) {
                $ph = implode(',', array_fill(0, count($chunk), '?'));
                // 그 사이 청구서에 담겼거나 입금된 전표는 그대로 둡니다
                $u = $pdo->prepare("UPDATE shipments s
                                       LEFT JOIN invoice_shipments xs ON xs.shipment_id = s.id
                                       SET s.status = 'CONFIRMED'
                                     WHERE s.id IN ($ph) AND s.status = 'BILLED' AND xs.id IS NULL");
                $u->execute($chunk);
                $back += $u->rowCount();
            }
            $pdo->prepare('UPDATE billing_bulk_marks SET undone_at = NOW() WHERE id = ?')->execute([$mid]);
            log_action('청구', 'UPDATE', 'billing_bulk_marks', $mid, $m['cutoff_date'] . ' 까지',
                       null, '되돌림 ' . $back . '건', '지난 청구 정리 되돌리기');
            $pdo->commit();
            flash('되돌렸습니다 — ' . number_format($back) . '건이 다시 미청구(확정)입니다.');
        }
        redirect('?p=billing');
    } catch (RuntimeException $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        $err = $e->getMessage();
    }
}

// ---------------------------------------------------------------- 청구서 생성
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('act') === 'create') {
    csrf_check();
    $cid   = (int)post('company_id');
    $pfrom = post('period_from');
    $pto   = post('period_to');
    $idate = post('invoice_date', date('Y-m-d'));
    $due   = post('due_date');
    $picked = $_POST['ship'] ?? [];
    $picked = is_array($picked) ? array_map('intval', $picked) : [];

    if ($cid <= 0) {
        $err = '거래처를 선택하세요.';
    } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $pfrom)
           || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $pto)) {
        $err = '청구 대상기간을 입력하세요.';
    } elseif (!$picked) {
        $err = '청구할 전표를 한 건 이상 선택하세요.';
    }

    if ($err === '') {
        try {
            [$invId, $no] = invoice_create($eid, $cid, $picked, $idate, $due !== '' ? $due : null, $pfrom, $pto);
            flash('청구서 ' . $no . ' 를 만들었습니다. 내용을 확인하고 발행하세요.');
            redirect('?p=invoice_view&id=' . $invId);
        } catch (Throwable $e) {
            error_log('청구서 생성 실패: ' . $e->getMessage());
            $err = $e instanceof RuntimeException
                 ? $e->getMessage()
                 : '청구서를 만들지 못했습니다.';
        }
    }
}

// ---------------------------------------------------------------- 매출전표에서 바로 청구
// 매출전표 목록에서 고른 전표 · 전표 화면의 [청구서 만들기] 가 여기로 옵니다.
// 거래처가 여러 곳이면 거래처마다 청구서를 하나씩 만듭니다. 이미 청구된 전표는 건너뜁니다.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('act') === 'create_quick') {
    csrf_check();
    $picked = $_POST['ship'] ?? [];
    $picked = is_array($picked) ? array_values(array_unique(array_filter(array_map('intval', $picked)))) : [];
    $back   = post('back') === 'form' && count($picked) === 1 ? '?p=shipment_form&id=' . $picked[0] : '?p=shipments';
    $idate  = preg_match('/^\d{4}-\d{2}-\d{2}$/', post('invoice_date')) ? post('invoice_date') : date('Y-m-d');
    $due    = preg_match('/^\d{4}-\d{2}-\d{2}$/', post('due_date')) ? post('due_date')
            : date('Y-m-d', strtotime($idate . ' +30 days'));
    if (!$picked) {
        flash('청구할 전표를 한 건 이상 선택하세요.');
        redirect($back);
    }
    // 이 사업자의 아직 청구 안 된 전표만, 거래처별로 묶습니다
    $ph = implode(',', array_fill(0, count($picked), '?'));
    $st = db()->prepare(
        "SELECT s.id, s.company_id FROM shipments s
           LEFT JOIN invoice_shipments xs ON xs.shipment_id = s.id
          WHERE s.id IN ($ph) AND s.business_entity_id = ?
            AND s.deleted_at IS NULL AND s.status <> 'CANCELLED' AND xs.id IS NULL
          ORDER BY s.company_id, s.voucher_date, s.id");
    $st->execute(array_merge($picked, [$eid]));
    $groups = [];
    foreach ($st->fetchAll() as $r) { $groups[(int)$r['company_id']][] = (int)$r['id']; }
    $skipped = count($picked) - array_sum(array_map('count', $groups));
    $made = [];
    $fail = [];
    foreach ($groups as $cid => $ids) {
        try {
            $made[] = invoice_create($eid, $cid, $ids, $idate, $due);
        } catch (Throwable $e) {
            error_log('청구서 생성 실패: ' . $e->getMessage());
            $fail[] = $e instanceof RuntimeException ? $e->getMessage() : '청구서를 만들지 못했습니다.';
        }
    }
    $msg = $made ? '청구서 ' . count($made) . '장을 만들었습니다 (' . implode(', ', array_column($made, 1)) . ').'
                   . ' 내용을 확인하고 발행하세요.' : '만든 청구서가 없습니다.';
    if ($skipped > 0) { $msg .= ' 이미 청구됐거나 취소된 전표 ' . $skipped . '건은 뺐습니다.'; }
    if ($fail) { $msg .= ' 실패: ' . implode(' / ', array_unique($fail)); }
    flash($msg);
    redirect(count($made) === 1 ? '?p=invoice_view&id=' . $made[0][0] : ($made ? '?p=billing' : $back));
}

// ---------------------------------------------------------------- 미청구 조회
$selCid  = (int)query('company_id', '0');
$selFrom = query('period_from', date('Y-m-01'));
$selTo   = query('period_to', date('Y-m-t'));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $selFrom)) { $selFrom = date('Y-m-01'); }
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $selTo))   { $selTo   = date('Y-m-t'); }

$companies = db()->prepare(
    'SELECT id, company_code, name_ko FROM companies
      WHERE deleted_at IS NULL ORDER BY name_ko');
$companies->execute();
$companies = $companies->fetchAll();

$unbilled = [];
if ($selCid > 0) {
    $st = db()->prepare(
        'SELECT s.id, s.awb_no, s.voucher_date, s.trade_type, ca.code AS carrier,
                COALESCE(t.zero_supply,0) AS zero_supply,
                COALESCE(t.taxable_supply,0) AS taxable_supply,
                COALESCE(t.tax_total,0) AS tax_total,
                COALESCE(t.grand_total,0) AS grand_total
           FROM shipments s
           JOIN carriers ca ON ca.id = s.carrier_id
           LEFT JOIN v_shipment_totals t  ON t.shipment_id = s.id
           LEFT JOIN invoice_shipments xs ON xs.shipment_id = s.id
          WHERE s.business_entity_id = ? AND s.company_id = ?
            AND s.deleted_at IS NULL AND s.status <> \'CANCELLED\'
            AND xs.id IS NULL AND s.status <> \'BILLED\'
            AND s.voucher_date BETWEEN ? AND ?
          ORDER BY s.voucher_date, s.id');
    $st->execute([$eid, $selCid, $selFrom, $selTo]);
    $unbilled = $st->fetchAll();
}
$uSum = 0;
foreach ($unbilled as $u) { $uSum += $u['grand_total']; }

// ---------------------------------------------------------------- 청구서 목록
$st = db()->prepare(
    'SELECT i.*, c.name_ko,
            (SELECT COUNT(*) FROM invoice_shipments x WHERE x.invoice_id = i.id) AS ship_cnt
       FROM invoices i
       JOIN companies c ON c.id = i.company_id
      WHERE i.business_entity_id = ? AND i.deleted_at IS NULL
      ORDER BY i.invoice_date DESC, i.id DESC
      LIMIT 100');
$st->execute([$eid]);
$invoices = $st->fetchAll();

$openTotal = 0;
foreach ($invoices as $i) {
    if ($i['status'] !== 'CANCELLED') { $openTotal += $i['balance']; }
}

// 지난 청구 정리 — 미리보기 · 최근 기록
bill_marks_table();
$markCut = preg_match('/^\d{4}-\d{2}-\d{2}$/', query('mark_cut')) ? query('mark_cut') : date('Y-m-t', strtotime('first day of last month'));
$markPrev = bill_marks_targets($eid, $markCut);
$markAmt = array_sum(array_map(static fn ($r) => (float)$r['amt'], $markPrev));
$st = db()->prepare('SELECT * FROM billing_bulk_marks WHERE business_entity_id = ? ORDER BY id DESC LIMIT 5');
$st->execute([$eid]);
$marks = $st->fetchAll();

layout_head('청구관리', 'billing');
?>
<div class="head">
  <h1>청구관리</h1>
  <div class="crumb">회계관리 &gt; 청구관리</div>
</div>

<?php if ($err !== ''): ?><div class="msg err"><?= h($err) ?></div><?php endif; ?>

<details class="card"<?= $markPrev ? ' open' : '' ?>>
  <summary class="ch" style="cursor:pointer">지난 청구 정리
    <span style="font-weight:400;color:var(--ink3)">옛 전산 등에서 이미 청구한 전표를 날짜 기준으로 한 번에 '청구' 로 표시 — 청구서는 만들지 않습니다</span></summary>
  <div class="cb">
    <form method="get" class="f" style="align-items:flex-end">
      <input type="hidden" name="p" value="billing">
      <div class="fw w1"><label for="mc">이 날짜까지 (전표일)</label>
        <input type="date" id="mc" name="mark_cut" value="<?= h($markCut) ?>" onchange="this.form.submit()"></div>
      <div class="fw w2"><label>대상 — 미청구(임시 · 확정) 이면서 청구서에 안 담긴 전표</label>
        <div style="height:34px;display:flex;align-items:center;font-weight:700">
          <?= number_format(count($markPrev)) ?>건 · <?= money($markAmt) ?>원</div></div>
    </form>
    <?php if ($markPrev): ?>
    <form method="post" class="f" style="align-items:flex-end;margin-top:10px"
          onsubmit="return confirm('<?= h($markCut) ?> 까지 미청구 전표 <?= number_format(count($markPrev)) ?>건을 \'청구\' 로 표시합니다. 진행할까요?')">
      <?= csrf_field() ?>
      <input type="hidden" name="act" value="mark_billed">
      <input type="hidden" name="cutoff" value="<?= h($markCut) ?>">
      <div class="fw gr"><label for="mr">메모</label>
        <input type="text" id="mr" name="reason" maxlength="200" value="옛 전산에서 청구 완료"></div>
      <button class="btn pri"><?= h($markCut) ?> 까지 <?= number_format(count($markPrev)) ?>건 '청구' 로 표시</button>
    </form>
    <?php endif; ?>
    <?php if ($marks): ?>
    <table style="margin-top:12px">
      <thead><tr><th style="width:150px">한 때</th><th style="width:120px">기준일</th>
        <th class="r" style="width:90px">건수</th><th class="r" style="width:140px">금액</th><th>상태</th></tr></thead>
      <tbody>
      <?php foreach ($marks as $m): ?>
        <tr>
          <td class="tnum"><?= h(substr((string)$m['created_at'], 0, 16)) ?></td>
          <td class="tnum"><?= h((string)$m['cutoff_date']) ?> 까지</td>
          <td class="r tnum"><?= number_format((int)$m['cnt']) ?></td>
          <td class="r tnum"><?= money($m['amount']) ?></td>
          <td><?php if ($m['undone_at']): ?>
                <span class="badge b-info">되돌림 <?= h(substr((string)$m['undone_at'], 0, 16)) ?></span>
              <?php else: ?>
                <form method="post" style="display:inline" onsubmit="return confirm('이 정리를 되돌려 다시 미청구로 만들까요? (그 사이 청구서에 담긴 전표는 그대로 둡니다)')">
                  <?= csrf_field() ?><input type="hidden" name="act" value="mark_undo">
                  <input type="hidden" name="mark_id" value="<?= (int)$m['id'] ?>">
                  <button class="btn sm">되돌리기</button></form>
              <?php endif; ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>
</details>

<div class="card">
  <div class="ch">청구서 만들기
    <span style="font-weight:400;color:var(--ink3)">거래처와 기간을 고르면 아직 청구하지 않은 전표가 나옵니다</span>
  </div>
  <div class="cb">
    <form class="f" method="get" style="align-items:flex-end">
      <input type="hidden" name="p" value="billing">
      <div class="fw w3"><label for="cid">거래처</label>
        <select id="cid" name="company_id">
          <option value="0">선택하세요</option>
          <?php foreach ($companies as $c): ?>
            <option value="<?= (int)$c['id'] ?>"<?= $selCid===(int)$c['id']?' selected':'' ?>>
              <?= h($c['name_ko']) ?> (<?= h($c['company_code']) ?>)</option>
          <?php endforeach; ?>
        </select></div>
      <div class="fw w1"><label for="pf">기간 시작</label>
        <input type="date" id="pf" name="period_from" value="<?= h($selFrom) ?>"></div>
      <div class="fw w1"><label for="pt">기간 종료</label>
        <input type="date" id="pt" name="period_to" value="<?= h($selTo) ?>"></div>
      <button class="btn">미청구 전표 조회</button>
    </form>
  </div>

  <?php if ($selCid > 0): ?>
    <?php if (!$unbilled): ?>
      <div class="empty">이 기간에 청구할 전표가 없습니다.
        이미 청구됐거나 취소된 전표는 나오지 않습니다.</div>
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
          <th style="width:110px">전표일</th><th style="width:170px">AWB</th>
          <th class="c" style="width:70px">운송사</th><th class="c" style="width:60px">구분</th>
          <th class="r" style="width:120px">영세</th><th class="r" style="width:120px">과세</th>
          <th class="r" style="width:100px">VAT</th><th class="r" style="width:130px">합계</th>
        </tr></thead>
        <tbody>
        <?php foreach ($unbilled as $u): ?>
          <tr>
            <td class="c"><input type="checkbox" class="pick" name="ship[]" value="<?= (int)$u['id'] ?>" checked></td>
            <td class="tnum"><?= h($u['voucher_date']) ?></td>
            <td class="tnum" style="font-weight:600"><?= h($u['awb_no']) ?></td>
            <td class="c"><?= h($u['carrier']) ?></td>
            <td class="c"><?= $u['trade_type']==='IMPORT'?'수입':'수출' ?></td>
            <td class="r tnum"><?= money($u['zero_supply']) ?></td>
            <td class="r tnum"><?= money($u['taxable_supply']) ?></td>
            <td class="r tnum"><?= money($u['tax_total']) ?></td>
            <td class="r tnum" style="font-weight:700"><?= money($u['grand_total']) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <div class="cb f" style="border-top:1px solid var(--line);align-items:flex-end">
        <div class="fw w1"><label>청구일</label>
          <input type="date" name="invoice_date" value="<?= h(date('Y-m-d')) ?>"></div>
        <div class="fw w1"><label>지급기한</label>
          <input type="date" name="due_date"
                 value="<?= h(date('Y-m-d', strtotime('+30 days'))) ?>"></div>
        <div style="margin-left:auto;text-align:right">
          <div style="font-size:11.5px;color:var(--ink2)">선택한 전표 합계</div>
          <div class="tnum" style="font-size:19px;font-weight:700"><?= money($uSum) ?></div>
        </div>
        <button class="btn pri">청구서 만들기</button>
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

<div class="kpis">
  <div class="kpi"><div class="lab">미수 합계</div>
    <div class="val tnum" style="color:<?= $openTotal > 0 ? 'var(--err-fg)' : 'var(--ink)' ?>">
      <?= money($openTotal) ?></div>
    <div class="sub">취소분 제외 · 최근 100건 기준</div></div>
</div>

<div class="card">
  <div class="ch">청구서 목록</div>
  <?php if (!$invoices): ?>
    <div class="empty">청구서가 없습니다.</div>
  <?php else: ?>
  <table>
    <thead><tr>
      <th style="width:175px">청구번호</th><th style="width:100px">청구일</th>
      <th>거래처</th><th style="width:170px">대상기간</th>
      <th class="r" style="width:60px">전표</th>
      <th class="r" style="width:130px">합계</th><th class="r" style="width:120px">수금</th>
      <th class="r" style="width:120px">미수</th><th class="c" style="width:90px">상태</th>
      <th class="c" style="width:60px"></th>
    </tr></thead>
    <tbody>
    <?php foreach ($invoices as $i): [$lab,$cls] = invoice_state($i);
      // 취소된 청구서는 받을 돈이 아닙니다 — 금액은 흐리게, 미수는 '-' 로 (미수 합계에도 안 들어감)
      $cancelled = $i['status'] === 'CANCELLED'; ?>
      <tr<?= $cancelled ? ' style="color:var(--ink3)"' : '' ?>>
        <td class="tnum" style="font-weight:600"><?= h($i['invoice_no']) ?></td>
        <td class="tnum"><?= h($i['invoice_date']) ?></td>
        <td><?= h($i['name_ko']) ?></td>
        <td class="tnum" style="font-size:11.5px;color:var(--ink2)">
          <?= h($i['period_from']) ?> ~ <?= h($i['period_to']) ?></td>
        <td class="r tnum"><?= money($i['ship_cnt']) ?></td>
        <td class="r tnum" style="font-weight:700<?= $cancelled ? ';text-decoration:line-through' : '' ?>"><?= money($i['grand_total']) ?></td>
        <td class="r tnum"><?= money($i['paid_amount']) ?></td>
        <td class="r tnum" style="color:<?= !$cancelled && $i['balance']>0?'var(--err-fg)':'var(--ink3)' ?>">
          <?= $cancelled ? '-' : money($i['balance']) ?></td>
        <td class="c"><span class="badge <?= $cls ?>"><?= h($lab) ?></span>
          <?php if ((int)($i['issue_count'] ?? 0) > 1): ?><div style="font-size:10.5px;color:var(--ink3)">재발행 <?= (int)$i['issue_count'] ?>회</div><?php endif; ?>
          <?php if ($i['status'] !== 'DRAFT' && $i['status'] !== 'CANCELLED' && !empty($i['changed_at']) && (empty($i['issued_at']) || $i['changed_at'] > $i['issued_at'])): ?>
            <div style="font-size:10.5px;color:var(--err-fg)">수정됨 · 재발행 필요</div><?php endif; ?></td>
        <td class="c"><a class="btn sm" href="?p=invoice_view&amp;id=<?= (int)$i['id'] ?>">열기</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>
<?php layout_foot();
