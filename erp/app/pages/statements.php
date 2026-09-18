<?php
require_once APP_DIR . '/layout.php';

$eid = entity_id();
$err = '';

// ---------------------------------------------------------------- 생성
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('act') === 'create') {
    csrf_check();
    $cid   = (int)post('company_id');
    $pfrom = post('period_from');
    $pto   = post('period_to');
    $sdate = post('statement_date', date('Y-m-d'));
    $picked = $_POST['ship'] ?? [];
    $picked = is_array($picked) ? array_map('intval', $picked) : [];

    if ($cid <= 0) {
        $err = '거래처를 선택하세요.';
    } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $pfrom)
           || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $pto)) {
        $err = '대상기간을 입력하세요.';
    } elseif (!$picked) {
        $err = '전표를 한 건 이상 선택하세요.';
    }

    if ($err === '') {
        $pdo = db();
        try {
            $pdo->beginTransaction();
            $ph = implode(',', array_fill(0, count($picked), '?'));
            // 고른 전표가 이 거래처 것이 맞는지 다시 확인합니다
            $st = $pdo->prepare(
                "SELECT s.id, COALESCE(t.supply_total,0) AS supply,
                        COALESCE(t.tax_total,0) AS tax
                   FROM shipments s
                   LEFT JOIN v_shipment_totals t ON t.shipment_id = s.id
                  WHERE s.id IN ($ph) AND s.business_entity_id = ? AND s.company_id = ?
                    AND s.deleted_at IS NULL AND s.status <> 'CANCELLED'");
            $st->execute(array_merge($picked, [$eid, $cid]));
            $ok = $st->fetchAll();
            if (count($ok) !== count($picked)) {
                throw new RuntimeException('조건에 맞지 않는 전표가 섞여 있습니다.');
            }

            $supply = 0; $tax = 0;
            foreach ($ok as $o) { $supply += $o['supply']; $tax += $o['tax']; }

            $no = next_doc_no('STMT', 'GPA-S-', '-');
            $pdo->prepare(
                'INSERT INTO statements
                   (business_entity_id, statement_no, company_id, statement_date,
                    period_from, period_to, supply_total, tax_total, grand_total,
                    status, created_by)
                 VALUES (?,?,?,?,?,?,?,?,?,\'ISSUED\',?)')
                ->execute([$eid, $no, $cid, $sdate, $pfrom, $pto,
                           $supply, $tax, $supply + $tax, $_SESSION['admin_id'] ?? null]);
            $sid = (int)$pdo->lastInsertId();

            $ins = $pdo->prepare(
                'INSERT INTO statement_shipments (statement_id, shipment_id, line_no)
                 VALUES (?,?,?)');
            $n = 0;
            foreach ($ok as $o) { $ins->execute([$sid, (int)$o['id'], ++$n]); }

            log_action('거래명세서', 'CREATE', 'statements', $sid, $no, null,
                       '전표 ' . $n . '건 · ' . number_format($supply + $tax));
            $pdo->commit();
            flash('거래명세서 ' . $no . ' 를 만들었습니다.');
            redirect('?p=statement_print&id=' . $sid);
        } catch (Throwable $e) {
            $pdo->rollBack();
            error_log('명세서 생성 실패: ' . $e->getMessage());
            $err = $e instanceof RuntimeException ? $e->getMessage() : '만들지 못했습니다.';
        }
    }
}

// ---------------------------------------------------------------- 대상 전표
$selCid  = (int)query('company_id', '0');
$selFrom = query('period_from', date('Y-m-01'));
$selTo   = query('period_to', date('Y-m-t'));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $selFrom)) { $selFrom = date('Y-m-01'); }
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $selTo))   { $selTo   = date('Y-m-t'); }

$companies = db()->prepare('SELECT id, company_code, name_ko FROM companies
                             WHERE deleted_at IS NULL ORDER BY name_ko');
$companies->execute();
$companies = $companies->fetchAll();

$cand = [];
if ($selCid > 0) {
    $st = db()->prepare(
        'SELECT s.id, s.awb_no, s.voucher_date, s.trade_type, s.dest_city,
                s.charge_weight, ca.code AS carrier,
                COALESCE(t.supply_total,0) AS supply, COALESCE(t.tax_total,0) AS tax,
                COALESCE(t.grand_total,0) AS grand,
                (SELECT COUNT(*) FROM statement_shipments x WHERE x.shipment_id = s.id) AS used
           FROM shipments s
           JOIN carriers ca ON ca.id = s.carrier_id
           LEFT JOIN v_shipment_totals t ON t.shipment_id = s.id
          WHERE s.business_entity_id = ? AND s.company_id = ?
            AND s.deleted_at IS NULL AND s.status <> \'CANCELLED\'
            AND s.voucher_date BETWEEN ? AND ?
          ORDER BY s.voucher_date, s.id');
    $st->execute([$eid, $selCid, $selFrom, $selTo]);
    $cand = $st->fetchAll();
}
$candSum = 0;
foreach ($cand as $c) { $candSum += $c['grand']; }

// ---------------------------------------------------------------- 목록
$st = db()->prepare(
    'SELECT s.*, c.name_ko,
            (SELECT COUNT(*) FROM statement_shipments x WHERE x.statement_id = s.id) AS cnt
       FROM statements s JOIN companies c ON c.id = s.company_id
      WHERE s.business_entity_id = ? AND s.deleted_at IS NULL
      ORDER BY s.statement_date DESC, s.id DESC LIMIT 100');
$st->execute([$eid]);
$rows = $st->fetchAll();

layout_head('거래명세서', 'statements');
?>
<div class="head">
  <h1>거래명세서</h1>
  <div class="crumb">영업관리 &gt; 거래명세서</div>
</div>

<?php if ($err !== ''): ?><div class="msg err"><?= h($err) ?></div><?php endif; ?>

<div class="card">
  <div class="ch">명세서 만들기
    <span style="font-weight:400;color:var(--ink3)">기간 안의 전표를 묶어 출력용 명세서를 만듭니다</span>
  </div>
  <div class="cb">
    <form class="f" method="get" style="align-items:flex-end">
      <input type="hidden" name="p" value="statements">
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
      <button class="btn">전표 조회</button>
    </form>
  </div>

  <?php if ($selCid > 0): ?>
    <?php if (!$cand): ?>
      <div class="empty">이 기간에 전표가 없습니다.</div>
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
          <th style="width:105px">전표일</th><th style="width:165px">AWB</th>
          <th class="c" style="width:65px">운송사</th><th>도착지</th>
          <th class="r" style="width:70px">중량</th>
          <th class="r" style="width:125px">공급가액</th><th class="r" style="width:100px">VAT</th>
          <th class="r" style="width:125px">합계</th><th class="c" style="width:80px">기존</th>
        </tr></thead>
        <tbody>
        <?php foreach ($cand as $c): ?>
          <tr>
            <td class="c"><input type="checkbox" class="pick" name="ship[]" value="<?= (int)$c['id'] ?>" checked></td>
            <td class="tnum"><?= h($c['voucher_date']) ?></td>
            <td class="tnum" style="font-weight:600"><?= h($c['awb_no']) ?></td>
            <td class="c"><?= h($c['carrier']) ?></td>
            <td><?= h($c['dest_city'] ?: '-') ?></td>
            <td class="r tnum"><?= $c['charge_weight'] !== null
                ? h(rtrim(rtrim(number_format((float)$c['charge_weight'],2),'0'),'.')) : '-' ?></td>
            <td class="r tnum"><?= money($c['supply']) ?></td>
            <td class="r tnum"><?= money($c['tax']) ?></td>
            <td class="r tnum" style="font-weight:700"><?= money($c['grand']) ?></td>
            <td class="c"><?= $c['used'] ? '<span class="badge b-info">' . (int)$c['used'] . '회</span>' : '' ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <div class="cb f" style="border-top:1px solid var(--line);align-items:flex-end">
        <div class="fw w1"><label>명세서 일자</label>
          <input type="date" name="statement_date" value="<?= h(date('Y-m-d')) ?>"></div>
        <div style="margin-left:auto;text-align:right">
          <div style="font-size:11.5px;color:var(--ink2)">선택한 전표 합계</div>
          <div class="tnum" style="font-size:19px;font-weight:700"><?= money($candSum) ?></div>
        </div>
        <button class="btn pri">명세서 만들기</button>
      </div>
      <div class="cb" style="font-size:11.5px;color:var(--ink3)">
        명세서는 <b>청구와 별개</b>입니다. 같은 전표로 여러 번 만들 수 있고, 전표 상태를 바꾸지 않습니다.
        '기존' 칸은 그 전표가 이미 명세서에 몇 번 실렸는지입니다.
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
  <div class="ch">명세서 목록</div>
  <?php if (!$rows): ?>
    <div class="empty">거래명세서가 없습니다.</div>
  <?php else: ?>
  <table>
    <thead><tr>
      <th style="width:165px">명세서번호</th><th style="width:105px">일자</th>
      <th>거래처</th><th style="width:170px">대상기간</th>
      <th class="r" style="width:60px">전표</th>
      <th class="r" style="width:125px">공급가액</th><th class="r" style="width:100px">VAT</th>
      <th class="r" style="width:125px">합계</th><th class="c" style="width:60px"></th>
    </tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td class="tnum" style="font-weight:600"><?= h($r['statement_no']) ?></td>
        <td class="tnum"><?= h($r['statement_date']) ?></td>
        <td><?= h($r['name_ko']) ?></td>
        <td class="tnum" style="font-size:11.5px;color:var(--ink2)">
          <?= h($r['period_from']) ?> ~ <?= h($r['period_to']) ?></td>
        <td class="r tnum"><?= money($r['cnt']) ?></td>
        <td class="r tnum"><?= money($r['supply_total']) ?></td>
        <td class="r tnum"><?= money($r['tax_total']) ?></td>
        <td class="r tnum" style="font-weight:700"><?= money($r['grand_total']) ?></td>
        <td class="c"><a class="btn sm" href="?p=statement_print&amp;id=<?= (int)$r['id'] ?>" target="_blank">출력</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>
<?php layout_foot();
