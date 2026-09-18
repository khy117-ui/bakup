<?php
require_once APP_DIR . '/layout.php';

$eid = entity_id();
$err = '';

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
        $pdo = db();
        try {
            $pdo->beginTransaction();

            // 고른 전표가 정말 이 거래처의 미청구 건인지 다시 확인합니다.
            // 화면에서 넘어온 id 를 그대로 믿지 않습니다
            $ph = implode(',', array_fill(0, count($picked), '?'));
            $st = $pdo->prepare(
                "SELECT s.id FROM shipments s
                   LEFT JOIN invoice_shipments xs ON xs.shipment_id = s.id
                  WHERE s.id IN ($ph) AND s.business_entity_id = ? AND s.company_id = ?
                    AND s.deleted_at IS NULL AND s.status <> 'CANCELLED'
                    AND xs.id IS NULL");
            $st->execute(array_merge($picked, [$eid, $cid]));
            $ok = array_column($st->fetchAll(), 'id');

            if (count($ok) !== count($picked)) {
                throw new RuntimeException('이미 청구됐거나 조건에 맞지 않는 전표가 섞여 있습니다.');
            }

            $no = next_doc_no('INVOICE', 'GPA-INV-', '-');
            $bank = $pdo->prepare(
                'SELECT id FROM business_bank_accounts
                  WHERE business_entity_id = ? AND is_active = 1
                  ORDER BY sort_order LIMIT 1');
            $bank->execute([$eid]);
            $bankId = $bank->fetchColumn() ?: null;

            $pdo->prepare(
                'INSERT INTO invoices
                   (business_entity_id, invoice_no, company_id, invoice_date,
                    period_from, period_to, due_date, bank_account_id, status, created_by)
                 VALUES (?,?,?,?,?,?,?,?,\'DRAFT\',?)')
                ->execute([$eid, $no, $cid, $idate, $pfrom, $pto,
                           $due !== '' ? $due : null, $bankId,
                           $_SESSION['admin_id'] ?? null]);
            $invId = (int)$pdo->lastInsertId();

            $ins = $pdo->prepare(
                'INSERT INTO invoice_shipments (invoice_id, shipment_id, line_no)
                 VALUES (?,?,?)');
            $n = 0;
            foreach ($ok as $sid) {
                $ins->execute([$invId, $sid, ++$n]);
            }
            $pdo->prepare(
                "UPDATE shipments SET status = 'BILLED'
                  WHERE id IN ($ph) AND status NOT IN ('PAID','CANCELLED')")
                ->execute($ok);

            invoice_recalc($invId);
            log_action('청구', 'CREATE', 'invoices', $invId, $no, null,
                       '전표 ' . $n . '건');
            $pdo->commit();
            flash('청구서 ' . $no . ' 를 만들었습니다. 내용을 확인하고 발행하세요.');
            redirect('?p=invoice_view&id=' . $invId);
        } catch (Throwable $e) {
            $pdo->rollBack();
            error_log('청구서 생성 실패: ' . $e->getMessage());
            $err = $e instanceof RuntimeException
                 ? $e->getMessage()
                 : '청구서를 만들지 못했습니다.';
        }
    }
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
            AND xs.id IS NULL
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

layout_head('청구관리', 'billing');
?>
<div class="head">
  <h1>청구관리</h1>
  <div class="crumb">회계관리 &gt; 청구관리</div>
</div>

<?php if ($err !== ''): ?><div class="msg err"><?= h($err) ?></div><?php endif; ?>

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
    <?php foreach ($invoices as $i): [$lab,$cls] = invoice_state($i); ?>
      <tr>
        <td class="tnum" style="font-weight:600"><?= h($i['invoice_no']) ?></td>
        <td class="tnum"><?= h($i['invoice_date']) ?></td>
        <td><?= h($i['name_ko']) ?></td>
        <td class="tnum" style="font-size:11.5px;color:var(--ink2)">
          <?= h($i['period_from']) ?> ~ <?= h($i['period_to']) ?></td>
        <td class="r tnum"><?= money($i['ship_cnt']) ?></td>
        <td class="r tnum" style="font-weight:700"><?= money($i['grand_total']) ?></td>
        <td class="r tnum"><?= money($i['paid_amount']) ?></td>
        <td class="r tnum" style="color:<?= $i['balance']>0?'var(--err-fg)':'var(--ink3)' ?>">
          <?= money($i['balance']) ?></td>
        <td class="c"><span class="badge <?= $cls ?>"><?= h($lab) ?></span></td>
        <td class="c"><a class="btn sm" href="?p=invoice_view&amp;id=<?= (int)$i['id'] ?>">열기</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>
<?php layout_foot();
