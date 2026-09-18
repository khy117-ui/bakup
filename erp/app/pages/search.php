<?php
// 웹에서 직접 열면 실행되지 않게 막습니다 (nginx 면 .htaccess 가 무시됩니다)
if (!defined('APP_DIR')) { http_response_code(403); exit('Forbidden'); }

require_once APP_DIR . '/layout.php';

/**
 * 통합검색 — 하나만 알 때 쓰는 화면.
 *
 * 실제로 물어보는 건 거의 이 세 가지입니다:
 *   "이 AWB 번호 누구 건이야" · "이 업체 이번달 얼마야" · "이 인보이스 받았나"
 * 그래서 AWB · 거래처 · 문서번호(견적/명세/청구/세금계산서) · 추적번호를 한 번에 봅니다.
 */

$eid = entity_id();
$kw  = trim(query('kw'));
$LIM = 20;

$ship = $comp = $invs = $quos = $stmts = $taxs = $trks = $pays = [];
$hit  = 0;

if ($kw !== '') {
    $like = '%' . $kw . '%';

    // 매출전표 — AWB / MAWB / 상대 이름
    $st = db()->prepare(
        'SELECT s.id, s.awb_no, s.mawb_no, s.voucher_date, s.status, c.name_ko,
                COALESCE(t.grand_total,0) AS grand_total
           FROM shipments s
           JOIN companies c ON c.id = s.company_id
           LEFT JOIN v_shipment_totals t ON t.shipment_id = s.id
          WHERE s.business_entity_id = ? AND s.deleted_at IS NULL
            AND (s.awb_no LIKE ? OR s.mawb_no LIKE ?)
          ORDER BY s.voucher_date DESC LIMIT ' . $LIM);
    $st->execute([$eid, $like, $like]);
    $ship = $st->fetchAll();

    // 거래처 — 이름 / 코드 / 사업자번호 / 전화.
    // companies 는 사업자 구분이 없는 공용 테이블입니다 (거래처는 두 법인이 같이 씁니다)
    $st = db()->prepare(
        'SELECT id, company_code, name_ko, name_en, business_number, phone, deleted_at
           FROM companies
          WHERE (name_ko LIKE ? OR name_en LIKE ? OR company_code LIKE ?
                 OR business_number LIKE ? OR phone LIKE ?)
          ORDER BY (deleted_at IS NOT NULL), name_ko LIMIT ' . $LIM);
    $st->execute([$like, $like, $like, $like, $like]);
    $comp = $st->fetchAll();

    // 청구서
    $st = db()->prepare(
        'SELECT i.id, i.invoice_no, i.invoice_date, i.grand_total, i.balance, i.status,
                i.due_date, c.name_ko
           FROM invoices i JOIN companies c ON c.id = i.company_id
          WHERE i.business_entity_id = ? AND i.deleted_at IS NULL AND i.invoice_no LIKE ?
          ORDER BY i.invoice_date DESC LIMIT ' . $LIM);
    $st->execute([$eid, $like]);
    $invs = $st->fetchAll();

    // 견적서
    $st = db()->prepare(
        'SELECT q.id, q.quote_no, q.quote_date, q.status, c.name_ko
           FROM quotations q JOIN companies c ON c.id = q.company_id
          WHERE q.business_entity_id = ? AND q.deleted_at IS NULL AND q.quote_no LIKE ?
          ORDER BY q.quote_date DESC LIMIT ' . $LIM);
    $st->execute([$eid, $like]);
    $quos = $st->fetchAll();

    // 거래명세서
    $st = db()->prepare(
        'SELECT s.id, s.statement_no, s.statement_date, c.name_ko
           FROM statements s JOIN companies c ON c.id = s.company_id
          WHERE s.business_entity_id = ? AND s.deleted_at IS NULL AND s.statement_no LIKE ?
          ORDER BY s.statement_date DESC LIMIT ' . $LIM);
    $st->execute([$eid, $like]);
    $stmts = $st->fetchAll();

    // 세금계산서 — 내부번호와 국세청 승인번호 둘 다
    $st = db()->prepare(
        'SELECT id, doc_no, nts_approval_no, issue_date, buyer_name, grand_total, status
           FROM tax_invoices
          WHERE business_entity_id = ? AND deleted_at IS NULL
            AND (doc_no LIKE ? OR nts_approval_no LIKE ?)
          ORDER BY issue_date DESC LIMIT ' . $LIM);
    $st->execute([$eid, $like, $like]);
    $taxs = $st->fetchAll();

    // 추적번호 — 운송사 번호로 들어오는 문의
    $st = db()->prepare(
        'SELECT t.tracking_no, t.id, s.id AS shipment_id, s.awb_no, s.voucher_date, c.name_ko
           FROM tracking_numbers t
           JOIN shipments s ON s.id = t.shipment_id
           JOIN companies c ON c.id = s.company_id
          WHERE s.business_entity_id = ? AND s.deleted_at IS NULL AND t.tracking_no LIKE ?
          ORDER BY s.voucher_date DESC LIMIT ' . $LIM);
    $st->execute([$eid, $like]);
    $trks = $st->fetchAll();

    // 입금 — 입금자명·적요·문서번호로 찾는 일이 많습니다
    $st = db()->prepare(
        "SELECT f.id, f.txn_date AS paid_at, f.amount, f.counterparty AS depositor,
                f.method, f.doc_no, c.name_ko
           FROM financial_transactions f
           LEFT JOIN companies c ON c.id = f.company_id
          WHERE f.business_entity_id = ? AND f.txn_type = 'IN' AND f.status = 'CONFIRMED'
            AND (f.counterparty LIKE ? OR f.summary LIKE ? OR f.doc_no LIKE ?)
          ORDER BY f.txn_date DESC LIMIT " . $LIM);
    $st->execute([$eid, $like, $like, $like]);
    $pays = $st->fetchAll();

    // 볼 권한이 없는 화면의 결과는 보여주지 않습니다 (메뉴를 숨겨도 검색으로 보이면 안 되니까)
    if (!route_can_view('shipments'))    { $ship = []; }
    if (!route_can_view('companies'))    { $comp = []; }
    if (!route_can_view('billing'))      { $invs = []; }
    if (!route_can_view('quotations'))   { $quos = []; }
    if (!route_can_view('statements'))   { $stmts = []; }
    if (!route_can_view('tax_invoices')) { $taxs = []; }
    if (!route_can_view('tracking'))     { $trks = []; }
    if (!route_can_view('cash_list'))    { $pays = []; }

    $hit = count($ship) + count($comp) + count($invs) + count($quos)
         + count($stmts) + count($taxs) + count($trks) + count($pays);
    // 검색은 작업로그에 남기지 않습니다 — 하루에 수백 건이 쌓여 정작 볼 기록이 묻힙니다
}

layout_head('통합검색', 'search');
?>
<div class="head">
  <h1>통합검색</h1>
  <div class="crumb">통합검색<?= $kw !== '' ? ' &gt; ' . h($kw) : '' ?></div>
</div>

<div class="card"><div class="cb">
  <form class="f" method="get" style="align-items:flex-end">
    <input type="hidden" name="p" value="search">
    <div class="fw w4"><label for="kw">AWB · 업체명 · 사업자번호 · 전화 · 문서번호 · 추적번호 · 입금자명</label>
      <input type="text" id="kw" name="kw" value="<?= h($kw) ?>" autofocus
             placeholder="아는 것 하나만 넣으세요"></div>
    <button class="btn pri">검색</button>
    <?php if ($kw !== ''): ?><a class="btn" href="?p=search">지우기</a><?php endif; ?>
  </form>
</div></div>

<?php if ($kw === ''): ?>
  <div class="card"><div class="empty">
    찾을 값을 넣으세요. 번호 일부만으로도 찾습니다 — <code>0666</code> 처럼 뒷자리만도 됩니다.
  </div></div>
<?php elseif ($hit === 0): ?>
  <div class="card"><div class="empty">
    <b><?= h($kw) ?></b> 에 걸리는 것이 없습니다.<br>
    <span style="font-size:12px">숨은 거래처일 수도 있습니다 — 거래처 관리에서 <b>숨긴 항목 포함</b>으로 다시 보세요.</span>
  </div></div>
<?php else: ?>

<div class="msg ok"><b><?= h($kw) ?></b> · 모두 <?= money($hit) ?> 건
  <?php if ($hit >= $LIM): ?>
    <span style="font-size:11.5px">(각 항목 <?= $LIM ?>건까지만 보여줍니다 — 더 있으면 해당 화면에서 거르세요)</span>
  <?php endif; ?>
</div>

<?php if ($ship): ?>
<div class="card">
  <div class="ch">매출전표 · <?= count($ship) ?>건</div>
  <table>
    <thead><tr>
      <th style="width:150px">AWB</th><th style="width:150px">MAWB</th>
      <th style="width:100px">전표일</th><th>거래처</th>
      <th class="r" style="width:130px">금액</th><th class="c" style="width:55px"></th>
    </tr></thead>
    <tbody>
    <?php foreach ($ship as $r): ?>
      <tr>
        <td class="tnum" style="font-weight:600"><?= h($r['awb_no']) ?></td>
        <td class="tnum"><?= h($r['mawb_no'] ?: '-') ?></td>
        <td class="tnum"><?= h($r['voucher_date']) ?></td>
        <td><?= h($r['name_ko']) ?></td>
        <td class="r tnum"><?= money($r['grand_total']) ?></td>
        <td class="c"><a class="btn sm" href="?p=shipment_form&amp;id=<?= (int)$r['id'] ?>">열기</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<?php if ($comp): ?>
<div class="card">
  <div class="ch">거래처 · <?= count($comp) ?>건</div>
  <table>
    <thead><tr>
      <th style="width:110px">코드</th><th>업체명</th>
      <th style="width:130px">사업자번호</th><th style="width:130px">전화</th>
      <th class="c" style="width:70px">상태</th><th class="c" style="width:55px"></th>
    </tr></thead>
    <tbody>
    <?php foreach ($comp as $r): ?>
      <tr>
        <td class="tnum"><?= h($r['company_code']) ?></td>
        <td style="font-weight:600"><?= h($r['name_ko']) ?>
          <?php if ($r['name_en']): ?>
            <span style="font-weight:400;font-size:11px;color:var(--ink3)"><?= h($r['name_en']) ?></span>
          <?php endif; ?></td>
        <td class="tnum"><?= h($r['business_number'] ?: '-') ?></td>
        <td class="tnum"><?= h($r['phone'] ?: '-') ?></td>
        <td class="c"><?= $r['deleted_at'] === null
             ? '<span class="badge b-ok">사용</span>'
             : '<span class="badge b-warn">숨김</span>' ?></td>
        <td class="c"><a class="btn sm" href="?p=company_form&amp;id=<?= (int)$r['id'] ?>">열기</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<?php if ($invs): ?>
<div class="card">
  <div class="ch">청구서 · <?= count($invs) ?>건</div>
  <table>
    <thead><tr>
      <th style="width:175px">청구번호</th><th style="width:100px">청구일</th>
      <th>거래처</th><th class="r" style="width:130px">청구액</th>
      <th class="r" style="width:130px">미수</th><th class="c" style="width:85px">상태</th>
      <th class="c" style="width:55px"></th>
    </tr></thead>
    <tbody>
    <?php foreach ($invs as $r): [$lab,$cls] = invoice_state($r); ?>
      <tr>
        <td class="tnum" style="font-weight:600"><?= h($r['invoice_no']) ?></td>
        <td class="tnum"><?= h($r['invoice_date']) ?></td>
        <td><?= h($r['name_ko']) ?></td>
        <td class="r tnum"><?= money($r['grand_total']) ?></td>
        <td class="r tnum" style="<?= (float)$r['balance']>0?'color:var(--err-fg);font-weight:600':'' ?>">
          <?= money($r['balance']) ?></td>
        <td class="c"><span class="badge <?= $cls ?>"><?= h($lab) ?></span></td>
        <td class="c"><a class="btn sm" href="?p=invoice_view&amp;id=<?= (int)$r['id'] ?>">열기</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<?php if ($taxs): ?>
<div class="card">
  <div class="ch">세금계산서 · <?= count($taxs) ?>건</div>
  <table>
    <thead><tr>
      <th style="width:165px">문서번호</th><th style="width:150px">승인번호</th>
      <th style="width:100px">작성일자</th><th>공급받는자</th>
      <th class="r" style="width:130px">합계</th><th class="c" style="width:55px"></th>
    </tr></thead>
    <tbody>
    <?php foreach ($taxs as $r): ?>
      <tr>
        <td class="tnum" style="font-weight:600"><?= h($r['doc_no']) ?></td>
        <td class="tnum" style="font-size:11.5px"><?= h($r['nts_approval_no'] ?: '-') ?></td>
        <td class="tnum"><?= h($r['issue_date']) ?></td>
        <td><?= h($r['buyer_name']) ?></td>
        <td class="r tnum"><?= money($r['grand_total']) ?></td>
        <td class="c"><a class="btn sm" href="?p=tax_invoices&amp;id=<?= (int)$r['id'] ?>">열기</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<?php if ($quos): ?>
<div class="card">
  <div class="ch">견적서 · <?= count($quos) ?>건</div>
  <table>
    <thead><tr>
      <th style="width:175px">견적번호</th><th style="width:100px">견적일</th>
      <th>거래처</th><th class="c" style="width:85px">상태</th><th class="c" style="width:55px"></th>
    </tr></thead>
    <tbody>
    <?php foreach ($quos as $r): ?>
      <tr>
        <td class="tnum" style="font-weight:600"><?= h($r['quote_no']) ?></td>
        <td class="tnum"><?= h($r['quote_date']) ?></td>
        <td><?= h($r['name_ko']) ?></td>
        <td class="c"><?= h($r['status']) ?></td>
        <td class="c"><a class="btn sm" href="?p=quotation_form&amp;id=<?= (int)$r['id'] ?>">열기</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<?php if ($stmts): ?>
<div class="card">
  <div class="ch">거래명세서 · <?= count($stmts) ?>건</div>
  <table>
    <thead><tr>
      <th style="width:175px">명세번호</th><th style="width:100px">작성일</th>
      <th>거래처</th><th class="c" style="width:55px"></th>
    </tr></thead>
    <tbody>
    <?php foreach ($stmts as $r): ?>
      <tr>
        <td class="tnum" style="font-weight:600"><?= h($r['statement_no']) ?></td>
        <td class="tnum"><?= h($r['statement_date']) ?></td>
        <td><?= h($r['name_ko']) ?></td>
        <td class="c"><a class="btn sm"
              href="?p=statement_print&amp;id=<?= (int)$r['id'] ?>" target="_blank">보기</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<?php if ($trks): ?>
<div class="card">
  <div class="ch">추적번호 · <?= count($trks) ?>건</div>
  <table>
    <thead><tr>
      <th style="width:190px">추적번호</th><th style="width:150px">AWB</th>
      <th style="width:100px">전표일</th><th>거래처</th><th class="c" style="width:55px"></th>
    </tr></thead>
    <tbody>
    <?php foreach ($trks as $r): ?>
      <tr>
        <td class="tnum" style="font-weight:600"><?= h($r['tracking_no']) ?></td>
        <td class="tnum"><?= h($r['awb_no']) ?></td>
        <td class="tnum"><?= h($r['voucher_date']) ?></td>
        <td><?= h($r['name_ko']) ?></td>
        <td class="c"><a class="btn sm"
              href="?p=tracking&amp;kw=<?= h(urlencode((string)$r['tracking_no'])) ?>">추적</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<?php if ($pays): ?>
<div class="card">
  <div class="ch">입금 · <?= count($pays) ?>건
    <span style="font-weight:400;color:var(--ink3)">입금자명 · 적요 · 문서번호로 찾은 것</span>
  </div>
  <table>
    <thead><tr>
      <th style="width:100px">입금일</th><th style="width:160px">문서번호</th>
      <th style="width:130px">입금자</th><th>거래처</th>
      <th class="c" style="width:90px">방법</th><th class="r" style="width:130px">금액</th>
      <th class="c" style="width:55px"></th>
    </tr></thead>
    <tbody>
    <?php foreach ($pays as $r): ?>
      <tr>
        <td class="tnum"><?= h($r['paid_at']) ?></td>
        <td class="tnum" style="font-size:11.5px"><?= h($r['doc_no'] ?: ('#' . $r['id'])) ?></td>
        <td style="font-weight:600"><?= h($r['depositor'] ?: '-') ?></td>
        <td><?= h($r['name_ko'] ?: '(미지정)') ?></td>
        <td class="c"><?= h($r['method']) ?></td>
        <td class="r tnum"><?= money($r['amount']) ?></td>
        <td class="c"><a class="btn sm" href="?p=cash_list&amp;id=<?= (int)$r['id'] ?>">열기</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<?php endif; ?>
<?php layout_foot();
