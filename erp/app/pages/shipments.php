<?php
require_once APP_DIR . '/layout.php';

$eid  = entity_id();
$err  = '';

// ---------------------------------------------------------------- 삭제 (목록의 휴지통)
// 지우지 않고 deleted_at 만 찍습니다 (목록 · 통계 · 미수에서 빠짐, 기록은 남음).
// 돈이 걸린 전표는 막습니다 — 청구서에 들어 있거나, 입금이 배분됐거나, 매입이 이미 지급된 것.
// 삭제 권한(sys.delete.direct)이 없는 사람이 누르면 index.php 가 '삭제 요청' 으로 돌려 관리자 승인을 받습니다.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('act') === 'delete') {
    csrf_check();
    $sid = (int)post('id');
    $why = trim(post('reason'));
    $st = db()->prepare('SELECT id, awb_no FROM shipments WHERE id = ? AND business_entity_id = ? AND deleted_at IS NULL');
    $st->execute([$sid, $eid]);
    $sh = $st->fetch();
    $inv = db()->prepare("SELECT i.invoice_no FROM invoice_shipments xs JOIN invoices i ON i.id = xs.invoice_id
                           WHERE xs.shipment_id = ? AND i.status <> 'CANCELLED' AND i.deleted_at IS NULL LIMIT 1");
    $inv->execute([$sid]);
    $invNo = $inv->fetchColumn();
    $paid = db()->prepare("SELECT COALESCE(SUM(pa.amount), 0) FROM payment_allocations pa
                             JOIN financial_transactions f ON f.id = pa.transaction_id
                              AND f.status = 'CONFIRMED' AND f.txn_type = 'IN'
                            WHERE pa.shipment_id = ?");
    $paid->execute([$sid]);
    $paidAmt = (float)$paid->fetchColumn();
    $purPaid = false;
    $ps = db()->prepare('SELECT id, is_paid FROM purchases WHERE shipment_id = ? AND deleted_at IS NULL');
    $ps->execute([$sid]);
    foreach ($ps->fetchAll() as $p) {
        if ((int)$p['is_paid'] === 1 || fin_purchase_paid((int)$p['id']) > 0) { $purPaid = true; }
    }
    if (!$sh) {
        $err = '전표를 찾을 수 없습니다 (이미 삭제됐을 수 있음).';
    } elseif (mb_strlen($why) < 2) {
        $err = '삭제 사유를 적어 주세요.';
    } elseif ($invNo) {
        $err = $sh['awb_no'] . ' 은 청구서 ' . $invNo . ' 에 들어 있어 삭제할 수 없습니다. 청구서를 먼저 취소하세요.';
    } elseif ($paidAmt > 0) {
        $err = $sh['awb_no'] . ' 에 입금 ' . money($paidAmt) . '원이 배분되어 있어 삭제할 수 없습니다. 입출금 내역에서 먼저 취소하세요.';
    } elseif ($purPaid) {
        $err = $sh['awb_no'] . ' 의 매입이 이미 지급되어 삭제할 수 없습니다. 출금을 먼저 취소하세요.';
    } else {
        $pdo = db();
        try {
            $pdo->beginTransaction();
            $pdo->prepare('UPDATE shipments SET deleted_at = NOW() WHERE id = ?')->execute([$sid]);
            $pdo->prepare('UPDATE purchases SET deleted_at = NOW() WHERE shipment_id = ? AND deleted_at IS NULL')
                ->execute([$sid]);
            log_action('매출전표', 'DELETE', 'shipments', $sid, (string)$sh['awb_no'], null, '삭제 (목록)', $why);
            $pdo->commit();
            flash('매출전표 ' . $sh['awb_no'] . ' 을 삭제했습니다. 작업로그에 사유와 함께 남았습니다.');
            $back = $_GET;
            redirect('?' . http_build_query($back ?: ['p' => 'shipments']));
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            error_log('전표 삭제 실패: ' . $e->getMessage());
            $err = '삭제하지 못했습니다.';
        }
    }
}

$kw   = query('kw');
$from = query('from');
$to   = query('to');
// 상태 — unbilled 는 대시보드 '미청구 전표' 와 같은 조건 (임시 · 확정)
$STATUS = ['unbilled' => ['DRAFT', 'CONFIRMED'], 'BILLED' => ['BILLED'], 'PAID' => ['PAID'],
           'CANCELLED' => ['CANCELLED']];
$status = array_key_exists(query('status'), $STATUS) ? query('status') : '';
$dest   = trim(query('dest'));   // 도착지 관리에서 '전표 수' 를 누르면
$page = max(1, (int)query('page', '1'));
$per  = 20;
$off  = ($page - 1) * $per;

$where  = ['s.business_entity_id = ?', 's.deleted_at IS NULL'];
$params = [$eid];
if ($kw !== '') {
    $where[] = '(s.awb_no LIKE ? OR c.name_ko LIKE ?)';
    $like = '%' . $kw . '%';
    array_push($params, $like, $like);
}
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
    $where[] = 's.voucher_date >= ?';
    $params[] = $from;
}
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
    $where[] = 's.voucher_date <= ?';
    $params[] = $to;
}
if ($dest !== '') {
    $where[] = 's.dest_city = ?';
    $params[] = $dest;
}
if ($status !== '') {
    $where[] = 's.status IN (' . implode(',', array_fill(0, count($STATUS[$status]), '?')) . ')';
    array_push($params, ...$STATUS[$status]);
}
$w = implode(' AND ', $where);

$st = db()->prepare("SELECT COUNT(*) FROM shipments s JOIN companies c ON c.id = s.company_id WHERE $w");
$st->execute($params);
$total = (int)$st->fetchColumn();

$st = db()->prepare(
    "SELECT s.id, s.awb_no, s.voucher_date, s.trade_type, s.status, s.charge_weight,
            c.name_ko, ca.code AS carrier,
            COALESCE(t.zero_supply,0) AS zero_supply,
            COALESCE(t.taxable_supply,0) AS taxable_supply,
            COALESCE(t.tax_total,0) AS tax_total,
            COALESCE(t.grand_total,0) AS grand_total
       FROM shipments s
       JOIN companies c  ON c.id = s.company_id
       JOIN carriers  ca ON ca.id = s.carrier_id
       LEFT JOIN v_shipment_totals t ON t.shipment_id = s.id
      WHERE $w
      ORDER BY s.voucher_date DESC, s.id DESC
      LIMIT $per OFFSET $off");
$st->execute($params);
$rows = $st->fetchAll();

$st = db()->prepare(
    "SELECT COALESCE(SUM(t.supply_total),0) AS supply, COALESCE(SUM(t.tax_total),0) AS tax
       FROM shipments s JOIN companies c ON c.id = s.company_id
       LEFT JOIN v_shipment_totals t ON t.shipment_id = s.id
      WHERE $w");
$st->execute($params);
$sum = $st->fetch() ?: ['supply'=>0,'tax'=>0];

// 청구 권한이 있으면 목록에서 골라 바로 청구서를 만듭니다 (billing 의 create_quick — 거래처별로 한 장씩)
$canBill = route_can_edit('billing');
$canEdit = route_can_edit('shipments');   // 휴지통 — 삭제 권한이 없으면 삭제 요청으로 넘어감
$billedIds = [];
if ($rows) {
    $ids = array_map('intval', array_column($rows, 'id'));
    $st = db()->prepare('SELECT shipment_id FROM invoice_shipments WHERE shipment_id IN ('
                        . implode(',', array_fill(0, count($ids), '?')) . ')');
    $st->execute($ids);
    $billedIds = array_flip(array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN)));
}

layout_head('매출전표', 'shipments');
?>
<div class="head">
  <h1>매출전표</h1>
  <div class="crumb">물류관리 &gt; 매출전표</div>
  <div class="right"><a class="btn pri" href="?p=shipment_form">전표 등록</a></div>
</div>

<?php if ($err !== ''): ?><div class="msg err"><?= h($err) ?></div><?php endif; ?>

<div class="card"><div class="cb">
  <form class="f" method="get" style="align-items:flex-end">
    <input type="hidden" name="p" value="shipments">
    <div class="fw w3"><label for="kw">검색</label>
      <input type="text" id="kw" name="kw" value="<?= h($kw) ?>" placeholder="AWB · 거래처명"></div>
    <div class="fw w1"><label for="from">전표일 시작</label>
      <input type="date" id="from" name="from" value="<?= h($from) ?>"></div>
    <div class="fw w1"><label for="to">전표일 종료</label>
      <input type="date" id="to" name="to" value="<?= h($to) ?>"></div>
    <div class="fw w1"><label for="status">상태</label>
      <select id="status" name="status">
        <option value="">전체</option>
        <option value="unbilled"<?= $status==='unbilled'?' selected':'' ?>>미청구</option>
        <option value="BILLED"<?= $status==='BILLED'?' selected':'' ?>>청구</option>
        <option value="PAID"<?= $status==='PAID'?' selected':'' ?>>입금</option>
        <option value="CANCELLED"<?= $status==='CANCELLED'?' selected':'' ?>>취소</option>
      </select></div>
    <?php if ($dest !== ''): ?><input type="hidden" name="dest" value="<?= h($dest) ?>">
      <span class="badge b-info" style="height:34px">도착지: <?= h($dest) ?></span><?php endif; ?>
    <button class="btn">검색</button>
    <a class="btn" href="?p=shipments">초기화</a>
  </form>
</div></div>

<div class="kpis">
  <div class="kpi"><div class="lab">검색 결과 공급가액</div>
    <div class="val tnum"><?= money($sum['supply']) ?></div>
    <div class="sub tnum">VAT <?= money($sum['tax']) ?> · 합계 <?= money($sum['supply']+$sum['tax']) ?></div></div>
  <div class="kpi"><div class="lab">전표 건수</div>
    <div class="val tnum"><?= money($total) ?></div>
    <div class="sub">조건에 맞는 전표</div></div>
</div>

<div class="card">
  <?php if (!$rows): ?>
    <div class="empty">전표가 없습니다.</div>
  <?php else: ?>
  <?php if ($canBill): ?>
  <form method="post" action="?p=billing" id="bill-form"
        onsubmit="var n=this.querySelectorAll('.bill-pick:checked').length; if(!n){alert('청구할 전표를 고르세요.');return false;} return confirm('고른 전표 '+n+'건으로 청구서를 만듭니다 (거래처별로 한 장씩). 계속할까요?');">
    <?= csrf_field() ?>
    <input type="hidden" name="act" value="create_quick">
    <div class="cb" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;border-bottom:1px solid var(--line)">
      <span style="font-size:12.5px;color:var(--ink2)">미청구 전표를 골라 바로 청구 —
        <b id="bill-n">0</b>건 · <b class="tnum" id="bill-s">0</b>원</span>
      <button class="btn sm pri" style="margin-left:auto">선택 전표 청구서 만들기</button>
    </div>
  <?php endif; ?>
  <table>
    <thead><tr>
      <?php if ($canBill): ?><th class="c" style="width:40px"><input type="checkbox" id="bill-all" title="이 쪽의 미청구 전표 모두"></th><?php endif; ?>
      <th style="width:100px">전표일</th><th style="width:160px">AWB</th>
      <th>거래처</th><th class="c" style="width:70px">운송사</th>
      <th class="c" style="width:60px">구분</th><th class="r" style="width:75px">중량</th>
      <th class="r" style="width:105px">영세</th><th class="r" style="width:105px">과세</th>
      <th class="r" style="width:90px">VAT</th><th class="r" style="width:110px">합계</th>
      <th class="c" style="width:85px">상태</th>
      <th class="c" style="width:60px"></th>
    </tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr>
        <?php if ($canBill): ?>
        <td class="c"><?php if (!isset($billedIds[(int)$r['id']]) && $r['status'] !== 'CANCELLED'): ?>
          <input type="checkbox" class="bill-pick" name="ship[]" value="<?= (int)$r['id'] ?>" data-amt="<?= (float)$r['grand_total'] ?>">
        <?php endif; ?></td>
        <?php endif; ?>
        <td class="tnum"><?= h($r['voucher_date']) ?></td>
        <td class="tnum" style="font-weight:600"><a href="?p=shipment_form&amp;id=<?= (int)$r['id'] ?>"><?= h($r['awb_no']) ?></a></td>
        <td><?= h($r['name_ko']) ?></td>
        <td class="c"><?= h($r['carrier']) ?></td>
        <td class="c"><?= $r['trade_type'] === 'IMPORT' ? '수입' : '수출' ?></td>
        <td class="r tnum"><?= $r['charge_weight'] !== null ? h(rtrim(rtrim(number_format((float)$r['charge_weight'],2),'0'),'.')) : '-' ?></td>
        <td class="r tnum"><?= money($r['zero_supply']) ?></td>
        <td class="r tnum"><?= money($r['taxable_supply']) ?></td>
        <td class="r tnum"><?= money($r['tax_total']) ?></td>
        <td class="r tnum" style="font-weight:700"><?= money($r['grand_total']) ?></td>
        <?php [$sl, $sc] = shipment_status_badge((string)$r['status']); ?>
        <td class="c"><span class="badge <?= $sc ?>"><?= h($sl) ?></span></td>
        <td class="c" style="white-space:nowrap"><a class="btn sm" href="?p=shipment_form&amp;id=<?= (int)$r['id'] ?>">보기</a>
          <?php if ($canEdit): ?>
          <button type="button" class="btn sm ship-del" title="삭제" aria-label="<?= h($r['awb_no']) ?> 삭제"
                  data-id="<?= (int)$r['id'] ?>" data-awb="<?= h($r['awb_no']) ?>" style="padding:0 7px;color:#A32020">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 6h18"/><path d="M8 6V4h8v2"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/></svg>
          </button>
          <?php endif; ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php if ($canBill): ?>
  </form>
  <script>
  (function () {
    var picks = document.querySelectorAll('.bill-pick');
    function upd() {
      var n = 0, t = 0;
      picks.forEach(function (c) { if (c.checked) { n++; t += parseFloat(c.getAttribute('data-amt')) || 0; } });
      document.getElementById('bill-n').textContent = n;
      document.getElementById('bill-s').textContent = Math.round(t).toLocaleString('ko-KR');
    }
    picks.forEach(function (c) { c.addEventListener('change', upd); });
    document.getElementById('bill-all').addEventListener('change', function () {
      var on = this.checked; picks.forEach(function (c) { c.checked = on; }); upd();
    });
  })();
  </script>
  <?php endif; ?>
  <div class="pager">
    <span>전체 <b class="tnum"><?= money($total) ?></b> 건 ·
      <span class="tnum"><?= money($off+1) ?>–<?= money(min($off+$per,$total)) ?></span></span>
    <div class="right">
      <?php $qs='p=shipments&kw='.urlencode($kw).'&from='.urlencode($from).'&to='.urlencode($to).'&status='.urlencode($status).'&dest='.urlencode($dest); ?>
      <?php if ($page>1): ?><a class="btn sm" href="?<?= h($qs) ?>&amp;page=<?= $page-1 ?>">이전</a><?php endif; ?>
      <?php if ($off+$per<$total): ?><a class="btn sm" href="?<?= h($qs) ?>&amp;page=<?= $page+1 ?>">다음</a><?php endif; ?>
    </div>
  </div>
  <?php endif; ?>
</div>
<?php if ($canEdit): ?>
<!-- 삭제는 목록(청구 폼) 밖의 별도 폼으로 보냅니다 — 폼 안에 폼을 넣으면 브라우저가 합쳐 버림 -->
<form method="post" id="ship-del-form" style="display:none">
  <?= csrf_field() ?>
  <input type="hidden" name="act" value="delete">
  <input type="hidden" name="id" value="">
  <input type="hidden" name="reason" value="">
</form>
<script>
document.querySelectorAll('.ship-del').forEach(function (b) {
  b.addEventListener('click', function () {
    var why = prompt('매출전표 ' + b.getAttribute('data-awb') + ' 을 삭제합니다.\n삭제 사유를 적어 주세요 (작업로그에 남습니다).\n\n청구 · 입금된 전표는 삭제되지 않습니다.');
    if (why === null) return;
    if (why.trim().length < 2) { alert('삭제 사유를 두 글자 이상 적어 주세요.'); return; }
    var f = document.getElementById('ship-del-form');
    f.elements['id'].value = b.getAttribute('data-id');
    f.elements['reason'].value = why.trim();
    f.submit();
  });
});
</script>
<?php endif; ?>
<?php layout_foot();
