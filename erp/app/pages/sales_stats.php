<?php
require_once APP_DIR . '/layout.php';

/**
 * 매출통계.
 *   · 업체별 / 월별 합계. 금액을 누르면 그 묶음의 전표 목록(view=list)이 나옵니다.
 *   · 전표 목록에서 합계를 누르면 그 자리에서 입금완료 처리 — 입금등록과 똑같이
 *     입금(financial_transactions IN) + 배분(payment_allocations)을 남깁니다.
 *     그래서 미수금 · 거래처원장 · 청구서 수금액이 같이 맞습니다. 취소는 입출금 내역에서 역분개.
 */

$eid  = entity_id();
$err  = '';
$from = query('from', date('Y-01-01'));
$to   = query('to', date('Y-m-d'));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) { $from = date('Y-01-01'); }
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to))   { $to   = date('Y-m-d'); }
$tab  = query('tab', 'company') === 'month' ? 'month' : 'company';
$view = query('view') === 'list' ? 'list' : 'stats';

// 목록 조건 — 업체 · 월 · 입금상태
$fCompany = (int)query('company_id', '0');
$fYm      = preg_match('/^\d{4}-\d{2}$/', query('ym')) ? query('ym') : '';
$fPay     = in_array(query('pay'), ['unpaid', 'paid'], true) ? query('pay') : '';
if ($fYm !== '') {
    $from = $fYm . '-01';
    $to   = date('Y-m-t', strtotime($from));
}
$canPay  = can('CASH_WRITE');
$cutover = ar_cutover();

// ================================================================ 입금완료 처리
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('act') === 'quick_pay') {
    csrf_check();
    $ids    = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['sid'] ?? [])))));
    $date   = post('txn_date', date('Y-m-d'));
    $acctId = (int)post('to_account_id');
    $method = in_array(post('method'), ['TRANSFER', 'CASH', 'CARD', 'NOTE', 'PG'], true) ? post('method') : 'TRANSFER';
    $custom = trim(str_replace(',', '', post('amount')));   // 한 건일 때만 — 부분 입금
    if (!$canPay) {
        $err = '입금 등록 권한이 없습니다.';
    } elseif (!$ids) {
        $err = '입금 처리할 전표를 고르세요.';
    } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        $err = '입금일자를 확인하세요.';
    } elseif ($custom !== '' && (count($ids) !== 1 || !is_numeric($custom) || (float)$custom <= 0)) {
        $err = '입금액은 전표 한 건일 때만 따로 적을 수 있습니다 (0 보다 커야 함).';
    } else {
        $pdo = db();
        try {
            $pdo->beginTransaction();
            $ph = implode(',', array_fill(0, count($ids), '?'));
            $st = $pdo->prepare(
                "SELECT s.id, s.business_entity_id, s.company_id, s.awb_no, s.voucher_date, s.status,
                        c.name_ko,
                        COALESCE((SELECT SUM(ch.total_amount) FROM shipment_charges ch WHERE ch.shipment_id = s.id), 0) AS grand,
                        COALESCE((SELECT SUM(pa.amount) FROM payment_allocations pa
                                   JOIN financial_transactions f ON f.id = pa.transaction_id
                                    AND f.status = 'CONFIRMED' AND f.txn_type = 'IN'
                                  WHERE pa.shipment_id = s.id), 0) AS paid
                   FROM shipments s JOIN companies c ON c.id = s.company_id
                  WHERE s.id IN ($ph) AND s.deleted_at IS NULL
                  FOR UPDATE");
            $st->execute($ids);
            $groups = [];
            $skipped = [];
            foreach ($st->fetchAll() as $r) {
                $bal = (float)$r['grand'] - (float)$r['paid'];
                if ($r['status'] === 'CANCELLED') {
                    $skipped[] = $r['awb_no'] . '(취소된 전표)';
                } elseif ($cutover !== null && $r['voucher_date'] < $cutover) {
                    $skipped[] = $r['awb_no'] . '(전환일 이전 — 기초잔액에서 처리)';
                } elseif ($bal <= 0.001) {
                    $skipped[] = $r['awb_no'] . '(이미 입금완료)';
                } else {
                    $amt = $custom !== '' ? min((float)$custom, $bal) : $bal;
                    $k = $r['business_entity_id'] . ':' . $r['company_id'];
                    $groups[$k]['ent']  = (int)$r['business_entity_id'];
                    $groups[$k]['comp'] = (int)$r['company_id'];
                    $groups[$k]['name'] = $r['name_ko'];
                    $groups[$k]['lines'][] = [(int)$r['id'], $amt, $r['awb_no']];
                }
            }
            if (!$groups) {
                throw new RuntimeException('입금 처리할 전표가 없습니다. ' . implode(', ', $skipped));
            }
            // 거래처(사업자)마다 입금 한 건 — 입금등록 화면과 같은 모양으로 남깁니다
            $made = [];
            foreach ($groups as $g) {
                if ($acctId > 0) {
                    $chk = $pdo->prepare('SELECT COUNT(*) FROM business_bank_accounts
                                           WHERE id = ? AND business_entity_id = ? AND is_active = 1');
                    $chk->execute([$acctId, $g['ent']]);
                    if ((int)$chk->fetchColumn() === 0) {
                        throw new RuntimeException('고른 계좌가 이 전표의 사업자 계좌가 아닙니다.');
                    }
                }
                $sum = 0.0;
                foreach ($g['lines'] as $l) { $sum += $l[1]; }
                $no = next_doc_no('CASH_IN', entity_code($g['ent']) . '-R-', '-', $g['ent']);
                $pdo->prepare(
                    "INSERT INTO financial_transactions
                       (business_entity_id, doc_no, txn_type, txn_date, company_id, counterparty,
                        to_account_id, method, supply_amount, tax_type, vat_amount, amount,
                        alloc_amount, summary, status, created_by)
                     VALUES (?,?,'IN',?,?,?,?,?,?,'ZERO',0,?,?,?,'CONFIRMED',?)")
                    ->execute([$g['ent'], $no, $date, $g['comp'], $g['name'], $acctId ?: null, $method,
                               $sum, $sum, $sum, '매출통계에서 입금완료 처리 · 전표 ' . count($g['lines']) . '건',
                               $_SESSION['admin_id'] ?? null]);
                $txnId = (int)$pdo->lastInsertId();
                $ins = $pdo->prepare('INSERT INTO payment_allocations
                                        (transaction_id, line_no, shipment_id, amount, created_by)
                                      VALUES (?,?,?,?,?)');
                $n = 0;
                foreach ($g['lines'] as [$sid, $amt]) {
                    $ins->execute([$txnId, ++$n, $sid, $amt, $_SESSION['admin_id'] ?? null]);
                }
                fin_resync_invoices($txnId);
                fin_audit($txnId, 'CREATE', null, null, money($sum) . '원 · 배분 ' . $n . '건 (매출통계)');
                log_action('입출금', 'CREATE', 'financial_transactions', $txnId, $no, null,
                           '입금 ' . money($sum) . ' · ' . $g['name'] . ' (매출통계 입금완료)');
                $made[] = $g['name'] . ' ' . money($sum) . '원';
            }
            $pdo->commit();
            flash('입금 처리했습니다 — ' . implode(' / ', $made)
                  . ($skipped ? ' · 건너뜀: ' . implode(', ', $skipped) : ''));
            $back = $_GET;
            redirect('?' . http_build_query($back));
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            if ($e instanceof RuntimeException) {
                $err = $e->getMessage();
            } else {
                error_log('매출통계 입금 처리 실패: ' . $e->getMessage());
                $err = '입금 처리하지 못했습니다. 입출금 내역에서 확인해 주세요.';
            }
        }
    }
}

// ================================================================ 통계
if ($view === 'stats') {
    $st = db()->prepare(
        'SELECT t.company_id, c.name_ko, c.company_code,
                COUNT(*) AS cnt,
                COALESCE(SUM(t.charge_weight),0) AS wt,
                COALESCE(SUM(t.zero_supply),0)    AS zero_supply,
                COALESCE(SUM(t.taxable_supply),0) AS taxable_supply,
                COALESCE(SUM(t.tax_total),0)      AS tax_total,
                COALESCE(SUM(t.grand_total),0)    AS grand_total
           FROM v_shipment_totals t
           JOIN companies c ON c.id = t.company_id
          WHERE t.business_entity_id = ? AND t.voucher_date BETWEEN ? AND ?
          GROUP BY t.company_id
          ORDER BY grand_total DESC
          LIMIT 100');
    $st->execute([$eid, $from, $to]);
    $byCompany = $st->fetchAll();

    $st = db()->prepare(
        'SELECT DATE_FORMAT(t.voucher_date, \'%Y-%m\') AS ym,
                COUNT(*) AS cnt,
                COALESCE(SUM(t.zero_supply),0)    AS zero_supply,
                COALESCE(SUM(t.taxable_supply),0) AS taxable_supply,
                COALESCE(SUM(t.tax_total),0)      AS tax_total,
                COALESCE(SUM(t.grand_total),0)    AS grand_total
           FROM v_shipment_totals t
          WHERE t.business_entity_id = ? AND t.voucher_date BETWEEN ? AND ?
          GROUP BY ym ORDER BY ym');
    $st->execute([$eid, $from, $to]);
    $byMonth = $st->fetchAll();

    $rows = $tab === 'month' ? $byMonth : $byCompany;
    $maxV = 0.0;
    foreach ($rows as $r) { $maxV = max($maxV, (float)$r['grand_total']); }

    // 합계는 월별에서 셉니다 — 업체별은 상위 100곳만이라 전체가 아닙니다
    $tot = ['zero' => 0, 'tax' => 0, 'vat' => 0, 'grand' => 0, 'cnt' => 0];
    foreach ($byMonth as $r) {
        $tot['zero']  += $r['zero_supply'];
        $tot['tax']   += $r['taxable_supply'];
        $tot['vat']   += $r['tax_total'];
        $tot['grand'] += $r['grand_total'];
        $tot['cnt']   += $r['cnt'];
    }
}

// ================================================================ 전표 목록
if ($view === 'list') {
    $PER = 200;
    $page = max(1, (int)query('pg', '1'));
    $w = ['s.business_entity_id = ?', 's.deleted_at IS NULL', 's.voucher_date BETWEEN ? AND ?'];
    $p = [$eid, $from, $to];
    if ($fCompany > 0) { $w[] = 's.company_id = ?'; $p[] = $fCompany; }
    $where = implode(' AND ', $w);
    $paid = "SELECT pa.shipment_id, SUM(pa.amount) AS paid
               FROM payment_allocations pa
               JOIN financial_transactions f ON f.id = pa.transaction_id
                AND f.status = 'CONFIRMED' AND f.txn_type = 'IN'
              WHERE pa.shipment_id IS NOT NULL GROUP BY pa.shipment_id";
    // GROUP BY s.id — 거래처 · 운송사 이름은 MAX() 로 감쌉니다 (MySQL 8 ONLY_FULL_GROUP_BY)
    $base = "SELECT s.id, s.voucher_date, s.awb_no, s.trade_type, s.status, s.company_id,
                    MAX(c.name_ko) AS name_ko, MAX(c.company_code) AS company_code, MAX(ca.name) AS carrier,
                    COALESCE(SUM(ch.supply_amount), 0) AS supply,
                    COALESCE(SUM(ch.tax_amount), 0)    AS vat,
                    COALESCE(SUM(ch.total_amount), 0)  AS grand,
                    COALESCE(MAX(a.paid), 0)           AS paid
               FROM shipments s
               JOIN companies c ON c.id = s.company_id
               LEFT JOIN carriers ca ON ca.id = s.carrier_id
               LEFT JOIN shipment_charges ch ON ch.shipment_id = s.id
               LEFT JOIN ($paid) a ON a.shipment_id = s.id
              WHERE $where
              GROUP BY s.id";
    $having = $fPay === 'unpaid' ? ' HAVING grand - paid > 0.001 AND MAX(s.status) <> \'CANCELLED\''
            : ($fPay === 'paid' ? ' HAVING grand > 0 AND grand - paid <= 0.001' : '');

    $st = db()->prepare("SELECT COUNT(*) AS cnt, COALESCE(SUM(grand),0) AS grand, COALESCE(SUM(paid),0) AS paid
                           FROM ($base$having) x");
    $st->execute($p);
    $sum = $st->fetch();

    $st = db()->prepare("$base$having ORDER BY s.voucher_date DESC, s.id DESC LIMIT $PER OFFSET " . (($page - 1) * $PER));
    $st->execute($p);
    $list = $st->fetchAll();
    $pages = max(1, (int)ceil((int)$sum['cnt'] / $PER));

    $compName = '';
    if ($fCompany > 0) {
        $st = db()->prepare('SELECT CONCAT(name_ko, \' (\', company_code, \')\') FROM companies WHERE id = ?');
        $st->execute([$fCompany]);
        $compName = (string)$st->fetchColumn();
    }
    $accounts = [];
    if ($canPay) {
        $st = db()->prepare("SELECT id, bank_name, account_no FROM business_bank_accounts
                              WHERE business_entity_id = ? AND is_active = 1 AND purpose IN ('IN','BOTH')
                              ORDER BY sort_order, id");
        $st->execute([$eid]);
        $accounts = $st->fetchAll();
    }
}

/** 한 전표의 입금 상태 — 미수금 뷰(v_shipment_receivable)와 같은 규칙 */
function ss_status(array $r, ?string $cutover): string
{
    if ($r['status'] === 'CANCELLED') { return 'CANCELLED'; }
    if ($cutover !== null && $r['voucher_date'] < $cutover) { return 'OPENING'; }
    if ((float)$r['grand'] <= 0) { return 'NONE'; }
    if ((float)$r['paid'] <= 0) { return 'UNPAID'; }
    return (float)$r['paid'] < (float)$r['grand'] ? 'PARTIAL' : 'PAID';
}

/** 목록으로 가는 주소 */
function ss_link(array $q): string
{
    return '?' . http_build_query(['p' => 'sales_stats', 'view' => 'list'] + $q);
}

layout_head('매출통계', 'sales_stats');
?>
<div class="head">
  <h1>매출통계</h1>
  <div class="crumb">회계관리 &gt; 매출통계<?= $view === 'list' ? ' &gt; 전표 목록' : '' ?></div>
  <?php if ($view === 'list'): ?>
  <div class="right">
    <a class="btn" href="?p=sales_stats&amp;tab=<?= h($fYm !== '' ? 'month' : 'company') ?>&amp;from=<?= h($fYm !== '' ? date('Y-01-01', strtotime($from)) : $from) ?>&amp;to=<?= h($fYm !== '' ? date('Y-12-31', strtotime($from)) : $to) ?>">← 통계로</a>
  </div>
  <?php endif; ?>
</div>

<?php if ($err !== ''): ?><div class="msg err"><?= h($err) ?></div><?php endif; ?>

<?php if ($view === 'stats'): ?>

<div class="card"><div class="cb">
  <form class="f" method="get" style="align-items:flex-end">
    <input type="hidden" name="p" value="sales_stats">
    <input type="hidden" name="tab" value="<?= h($tab) ?>">
    <div class="fw w1"><label for="from">시작일</label>
      <input type="date" id="from" name="from" value="<?= h($from) ?>"></div>
    <div class="fw w1"><label for="to">종료일</label>
      <input type="date" id="to" name="to" value="<?= h($to) ?>"></div>
    <button class="btn">조회</button>
  </form>
</div></div>

<div class="kpis">
  <div class="kpi"><div class="lab">영세율 공급가액</div>
    <div class="val tnum"><?= money($tot['zero']) ?></div></div>
  <div class="kpi"><div class="lab">과세 공급가액</div>
    <div class="val tnum"><?= money($tot['tax']) ?></div>
    <div class="sub tnum">VAT <?= money($tot['vat']) ?></div></div>
  <div class="kpi"><div class="lab">합계</div>
    <div class="val tnum"><a href="<?= h(ss_link(['from' => $from, 'to' => $to])) ?>"><?= money($tot['grand']) ?></a></div>
    <div class="sub tnum">전표 <?= money($tot['cnt']) ?> 건 · 누르면 전표 목록</div></div>
</div>

<div class="card">
  <div class="ch">
    <a class="btn sm<?= $tab==='company'?' pri':'' ?>"
       href="?p=sales_stats&amp;tab=company&amp;from=<?= h($from) ?>&amp;to=<?= h($to) ?>">업체별</a>
    <a class="btn sm<?= $tab==='month'?' pri':'' ?>"
       href="?p=sales_stats&amp;tab=month&amp;from=<?= h($from) ?>&amp;to=<?= h($to) ?>">월별</a>
    <span style="margin-left:auto;font-weight:400;color:var(--ink3)">
      <?= h($from) ?> ~ <?= h($to) ?> · 금액을 누르면 그 전표 목록</span>
  </div>
  <?php if (!$rows): ?>
    <div class="empty">이 기간에 전표가 없습니다.</div>
  <?php else: ?>
  <table>
    <thead><tr>
      <th style="width:190px"><?= $tab==='month' ? '월' : '업체' ?></th>
      <th class="r" style="width:70px">건수</th>
      <?php if ($tab==='company'): ?><th class="r" style="width:80px">중량</th><?php endif; ?>
      <th class="r" style="width:120px">영세</th>
      <th class="r" style="width:120px">과세</th>
      <th class="r" style="width:100px">VAT</th>
      <th class="r" style="width:130px">합계</th>
      <th style="width:230px">비중</th>
    </tr></thead>
    <tbody>
    <?php foreach ($rows as $r):
      $lq = $tab === 'month' ? ['ym' => $r['ym']] : ['company_id' => (int)$r['company_id'], 'from' => $from, 'to' => $to];
      $lk = h(ss_link($lq)); ?>
      <tr>
        <td style="font-weight:600"><a href="<?= $lk ?>"><?= h($tab==='month' ? $r['ym'] : $r['name_ko']) ?></a>
          <?php if ($tab==='company'): ?><span class="tnum" style="font-weight:400;color:var(--ink3);font-size:11.5px"><?= h($r['company_code']) ?></span><?php endif; ?></td>
        <td class="r tnum"><?= money($r['cnt']) ?></td>
        <?php if ($tab==='company'): ?>
          <td class="r tnum"><?= h(rtrim(rtrim(number_format((float)$r['wt'],1),'0'),'.')) ?></td>
        <?php endif; ?>
        <td class="r tnum"><a href="<?= $lk ?>"><?= money($r['zero_supply']) ?></a></td>
        <td class="r tnum"><a href="<?= $lk ?>"><?= money($r['taxable_supply']) ?></a></td>
        <td class="r tnum"><?= money($r['tax_total']) ?></td>
        <td class="r tnum" style="font-weight:700"><a href="<?= $lk ?>"><?= money($r['grand_total']) ?></a></td>
        <td><div class="barwrap">
          <div class="bar" style="width:<?= $maxV>0 ? round((float)$r['grand_total']/$maxV*160) : 0 ?>px"></div>
          <span class="tnum" style="font-size:11.5px;color:var(--ink3)">
            <?= $tot['grand']>0 ? number_format((float)$r['grand_total']/$tot['grand']*100,1) : '0.0' ?>%</span>
        </div></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>

<?php else: /* ---------------------------------------------------- 전표 목록 */ ?>

<div class="card"><div class="cb">
  <form class="f" method="get" style="align-items:flex-end">
    <input type="hidden" name="p" value="sales_stats">
    <input type="hidden" name="view" value="list">
    <?php if ($fCompany > 0): ?><input type="hidden" name="company_id" value="<?= $fCompany ?>"><?php endif; ?>
    <div class="fw w1"><label for="lfrom">시작일</label>
      <input type="date" id="lfrom" name="from" value="<?= h($from) ?>"></div>
    <div class="fw w1"><label for="lto">종료일</label>
      <input type="date" id="lto" name="to" value="<?= h($to) ?>"></div>
    <div class="fw w1"><label for="lpay">입금상태</label>
      <select id="lpay" name="pay">
        <option value="">전체</option>
        <option value="unpaid"<?= $fPay==='unpaid'?' selected':'' ?>>미입금 · 부분입금</option>
        <option value="paid"<?= $fPay==='paid'?' selected':'' ?>>입금완료</option>
      </select></div>
    <button class="btn">조회</button>
    <?php if ($fCompany > 0): ?>
      <a class="btn" href="<?= h(ss_link(['from' => $from, 'to' => $to, 'pay' => $fPay])) ?>">거래처 조건 풀기</a>
    <?php endif; ?>
  </form>
</div></div>

<div class="kpis">
  <div class="kpi"><div class="lab"><?= h($compName !== '' ? $compName : '전체 거래처') ?></div>
    <div class="val tnum"><?= money($sum['cnt']) ?> 건</div>
    <div class="sub tnum"><?= h($from) ?> ~ <?= h($to) ?></div></div>
  <div class="kpi"><div class="lab">매출 합계</div>
    <div class="val tnum"><?= money($sum['grand']) ?></div></div>
  <div class="kpi"><div class="lab">입금</div>
    <div class="val tnum"><?= money($sum['paid']) ?></div>
    <div class="sub tnum">잔액 <?= money((float)$sum['grand'] - (float)$sum['paid']) ?></div></div>
</div>

<?php if ($cutover === null): ?>
  <div class="msg" style="background:var(--warn-bg);color:var(--warn-fg)">
    <b>전환일(기초잔액 기준일)이 아직 정해지지 않아</b> 옛 시스템 전표도 모두 미입금으로 보입니다.
    옛 전표의 입금 여부는 <b>옛 시스템</b> 칸(입금Y)을 참고하세요. 전환일을 정하면 그 이전 전표는 '기초잔액' 으로 닫힙니다.</div>
<?php endif; ?>

<form method="post" id="payform">
<?= csrf_field() ?>
<input type="hidden" name="act" value="quick_pay">
<div class="card">
  <?php if ($canPay): ?>
  <div class="cb" style="border-bottom:1px solid var(--line)">
    <div class="f" style="align-items:flex-end">
      <div class="fw w1"><label for="pdate">입금일</label>
        <input type="date" id="pdate" name="txn_date" value="<?= h(date('Y-m-d')) ?>"></div>
      <div class="fw w2"><label for="pacct">입금 계좌</label>
        <select id="pacct" name="to_account_id">
          <option value="">— 지정 안 함 —</option>
          <?php foreach ($accounts as $ac): ?>
            <option value="<?= (int)$ac['id'] ?>"><?= h($ac['bank_name']) ?> <?= h($ac['account_no']) ?></option>
          <?php endforeach; ?>
        </select></div>
      <div class="fw w1"><label for="pmethod">방법</label>
        <select id="pmethod" name="method">
          <option value="TRANSFER">계좌이체</option><option value="CASH">현금</option>
          <option value="CARD">카드</option><option value="NOTE">어음</option><option value="PG">PG</option>
        </select></div>
      <div class="fw w1" id="amtbox" style="display:none"><label for="pamt">입금액 (부분 입금)</label>
        <input type="text" id="pamt" name="amount" class="tnum" inputmode="numeric" placeholder="비우면 잔액 전부"></div>
      <button type="submit" class="btn pri" id="paybtn" disabled>선택한 전표 입금완료</button>
      <span id="paysum" class="tnum" style="font-size:12.5px;color:var(--ink2)"></span>
    </div>
    <div style="font-size:11.5px;color:var(--ink3);margin-top:6px">
      <b>합계</b> 금액을 누르면 그 전표 하나만 고릅니다. 입금은 거래처마다 한 건으로 남고(입출금 내역),
      미수금 · 거래처원장 · 청구서 수금액에 바로 반영됩니다. 잘못 넣었으면 입출금 내역에서 취소(역분개)하세요.</div>
  </div>
  <?php endif; ?>

  <?php if (!$list): ?>
    <div class="empty">조건에 맞는 전표가 없습니다.</div>
  <?php else: ?>
  <table>
    <thead><tr>
      <?php if ($canPay): ?><th class="c" style="width:34px"><input type="checkbox" id="chkall" aria-label="이 쪽 전부 고르기"></th><?php endif; ?>
      <th style="width:96px">전표일</th><th style="width:150px">AWB</th>
      <th>거래처</th><th style="width:110px">운송사</th><th class="c" style="width:50px">구분</th>
      <th class="r" style="width:110px">공급가액</th><th class="r" style="width:90px">VAT</th>
      <th class="r" style="width:120px">합계</th><th class="r" style="width:110px">입금</th>
      <th class="r" style="width:110px">잔액</th><th class="c" style="width:84px">입금상태</th>
      <th class="c" style="width:70px">옛 시스템</th>
    </tr></thead>
    <tbody>
    <?php foreach ($list as $r):
      $stt = ss_status($r, $cutover);
      $bal = (float)$r['grand'] - (float)$r['paid'];
      $open = in_array($stt, ['UNPAID', 'PARTIAL'], true);
      [$bl, $bc] = pay_status_badge($stt); ?>
      <tr<?= $r['status']==='CANCELLED' ? ' style="opacity:.5"' : '' ?>>
        <?php if ($canPay): ?>
        <td class="c"><?php if ($open): ?><input type="checkbox" name="sid[]" value="<?= (int)$r['id'] ?>"
            data-bal="<?= h((string)$bal) ?>" class="pchk" aria-label="<?= h($r['awb_no']) ?> 고르기"><?php endif; ?></td>
        <?php endif; ?>
        <td class="tnum"><?= h($r['voucher_date']) ?></td>
        <td class="tnum" style="font-weight:600"><a href="?p=shipment_form&amp;id=<?= (int)$r['id'] ?>"><?= h($r['awb_no']) ?></a></td>
        <td><?= h($r['name_ko']) ?> <span class="tnum" style="color:var(--ink3);font-size:11.5px"><?= h($r['company_code']) ?></span></td>
        <td><?= h($r['carrier'] ?? '-') ?></td>
        <td class="c"><?= $r['trade_type'] === 'IMPORT' ? '수입' : '수출' ?></td>
        <td class="r tnum"><?= money($r['supply']) ?></td>
        <td class="r tnum"><?= money($r['vat']) ?></td>
        <td class="r tnum" style="font-weight:700">
          <?php if ($canPay && $open): ?>
            <button type="button" class="btn sm pone" data-id="<?= (int)$r['id'] ?>" title="이 전표 입금완료"
                    style="font-weight:700"><?= money($r['grand']) ?></button>
          <?php else: ?><?= money($r['grand']) ?><?php endif; ?></td>
        <td class="r tnum"><?= money($r['paid']) ?></td>
        <td class="r tnum"<?= $open ? ' style="color:var(--err-fg)"' : '' ?>><?= money($bal) ?></td>
        <td class="c"><span class="badge <?= $bc ?>"><?= h($bl) ?></span></td>
        <td class="c"><?= $r['status'] === 'PAID' ? '<span class="badge b-ok">입금Y</span>' : '' ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php if ($pages > 1): ?>
  <div class="pager">
    <span><?= money($sum['cnt']) ?>건 중 <?= ($page - 1) * $PER + 1 ?>~<?= min($page * $PER, (int)$sum['cnt']) ?></span>
    <span class="right">
      <?php $q = $_GET; ?>
      <?php if ($page > 1): $q['pg'] = $page - 1; ?><a class="btn sm" href="?<?= h(http_build_query($q)) ?>">이전</a><?php endif; ?>
      <span class="tnum" style="align-self:center"><?= $page ?> / <?= $pages ?></span>
      <?php if ($page < $pages): $q['pg'] = $page + 1; ?><a class="btn sm" href="?<?= h(http_build_query($q)) ?>">다음</a><?php endif; ?>
    </span>
  </div>
  <?php endif; ?>
  <?php endif; ?>
</div>
</form>

<?php if ($canPay): ?>
<script>
(function () {
  var form = document.getElementById('payform');
  var btn = document.getElementById('paybtn');
  var sumEl = document.getElementById('paysum');
  var amtBox = document.getElementById('amtbox');
  var boxes = function () { return Array.prototype.slice.call(document.querySelectorAll('.pchk')); };

  function refresh() {
    var picked = boxes().filter(function (c) { return c.checked; });
    var total = picked.reduce(function (s, c) { return s + Number(c.getAttribute('data-bal')); }, 0);
    btn.disabled = picked.length === 0;
    btn.textContent = picked.length === 1 ? '이 전표 입금완료' : '선택한 전표 ' + picked.length + '건 입금완료';
    sumEl.textContent = picked.length ? '잔액 합계 ' + total.toLocaleString('ko-KR') + '원' : '';
    // 한 건일 때만 부분 입금액을 적을 수 있습니다
    amtBox.style.display = picked.length === 1 ? '' : 'none';
    if (picked.length !== 1) { document.getElementById('pamt').value = ''; }
  }
  boxes().forEach(function (c) { c.addEventListener('change', refresh); });
  var all = document.getElementById('chkall');
  if (all) {
    all.addEventListener('change', function () {
      boxes().forEach(function (c) { c.checked = all.checked; });
      refresh();
    });
  }
  // 합계 금액을 누르면 그 전표만 골라서 입금 칸으로 올려 줍니다
  document.querySelectorAll('.pone').forEach(function (b) {
    b.addEventListener('click', function () {
      var id = b.getAttribute('data-id');
      boxes().forEach(function (c) { c.checked = c.value === id; });
      if (all) { all.checked = false; }
      refresh();
      form.scrollIntoView({behavior: 'smooth', block: 'start'});
      btn.focus();
    });
  });
  form.addEventListener('submit', function (e) {
    var picked = boxes().filter(function (c) { return c.checked; });
    var total = picked.reduce(function (s, c) { return s + Number(c.getAttribute('data-bal')); }, 0);
    var custom = document.getElementById('pamt').value.replace(/,/g, '');
    var msg = picked.length + '건을 입금완료로 처리할까요?\n입금액 ' +
              (custom ? Number(custom).toLocaleString('ko-KR') + '원 (부분 입금)' : total.toLocaleString('ko-KR') + '원 (잔액 전부)') +
              '\n입출금 내역에 입금으로 남습니다.';
    if (!confirm(msg)) { e.preventDefault(); }
  });
  refresh();
})();
</script>
<?php endif; ?>

<?php endif; ?>
<?php layout_foot();
