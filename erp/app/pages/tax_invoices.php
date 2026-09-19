<?php
// 웹에서 직접 열면 실행되지 않게 막습니다 (nginx 면 .htaccess 가 무시됩니다)
if (!defined('APP_DIR')) { http_response_code(403); exit('Forbidden'); }

require_once APP_DIR . '/layout.php';

/**
 * 세금계산서 — 청구서에서 발행합니다.
 *
 * 외부 연동사(팝빌·바로빌 등)가 아직 정해지지 않아 **내부 발행까지만** 합니다.
 * 국세청 전송은 연동사가 정해지면 붙입니다. 그때까지는 여기서 만든 내용을
 * 연동사 화면에 옮겨 적거나, 승인번호를 받아와 여기 기록해 두는 방식으로 씁니다.
 */

$eid = entity_id();
$err = '';

$STATUS = ['DRAFT' => ['작성중', 'b-warn'], 'ISSUED' => ['발행', 'b-ok'],
           'SENT' => ['전송완료', 'b-ok'], 'CANCELLED' => ['취소', 'b-err'],
           'FAILED' => ['실패', 'b-err']];

// ---------------------------------------------------------------- 청구서에서 생성
// 한 청구서에 영세율 · 과세 · 면세가 섞여 있으면 종류별로 따로 만듭니다 (한 장에 섞을 수 없음)
//   영세율분 → 영세율 세금계산서(ZERO) · 과세분 → 세금계산서(TAX) · 면세분 → 계산서(EXEMPT)
// 회사 기준: 특송 · 항공 · 해상 운송 = 영세율, 핸드링 · 도큐멘트 · 국내운송 · 창고 · 검사 · 통관 = 과세 (CHARGE_TYPES)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('act') === 'create') {
    csrf_check();
    $invId = (int)post('invoice_id');
    $date  = post('issue_date', date('Y-m-d'));

    $st = db()->prepare(
        'SELECT i.*, c.name_ko, c.business_number, c.representative, c.address_ko,
                c.business_type, c.business_item, c.tax_email, c.email
           FROM invoices i JOIN companies c ON c.id = i.company_id
          WHERE i.id = ? AND i.business_entity_id = ? AND i.deleted_at IS NULL');
    $st->execute([$invId, $eid]);
    $inv = $st->fetch();

    if (!$inv) {
        $err = '청구서를 찾을 수 없습니다.';
    } elseif ($inv['status'] === 'DRAFT') {
        $err = '아직 발행하지 않은 청구서입니다. 청구서를 먼저 발행하세요.';
    } elseif ($inv['status'] === 'CANCELLED') {
        $err = '취소된 청구서로는 세금계산서를 만들 수 없습니다.';
    } elseif (trim((string)$inv['business_number']) === '') {
        $err = '거래처에 사업자등록번호가 없습니다. 거래처 정보를 먼저 채우세요.';
    } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        $err = '작성일자를 입력하세요.';
    } else {
        $dup = db()->prepare(
            "SELECT id FROM tax_invoices
              WHERE invoice_id = ? AND deleted_at IS NULL AND status <> 'CANCELLED'");
        $dup->execute([$invId]);
        if ($dup->fetchColumn()) {
            $err = '이 청구서로 이미 세금계산서를 만들었습니다.';
        } else {
            $pdo = db();
            try {
                $pdo->beginTransaction();

                // 품목 — 전표마다 세금구분별로 한 줄 (항목 이름은 비용 종류로)
                $labels = charge_labels();
                $ships = $pdo->prepare(
                    "SELECT s.id, s.awb_no, s.voucher_date, ch.tax_type,
                            GROUP_CONCAT(DISTINCT ch.charge_type ORDER BY ch.line_no SEPARATOR ',') AS types,
                            SUM(ch.supply_amount) AS supply, SUM(ch.tax_amount) AS tax
                       FROM invoice_shipments xs
                       JOIN shipments s ON s.id = xs.shipment_id
                       JOIN shipment_charges ch ON ch.shipment_id = s.id
                      WHERE xs.invoice_id = ?
                      GROUP BY s.id, s.awb_no, s.voucher_date, ch.tax_type, xs.line_no
                      ORDER BY xs.line_no");
                $ships->execute([$invId]);
                $lines = ['ZERO' => [], 'TAXABLE' => [], 'EXEMPT' => []];
                foreach ($ships->fetchAll() as $s) {
                    if (!isset($lines[$s['tax_type']]) || ((float)$s['supply'] == 0.0 && (float)$s['tax'] == 0.0)) {
                        continue;
                    }
                    $names = array_map(fn($c) => $labels[$c] ?? $c, array_unique(explode(',', (string)$s['types'])));
                    $lines[$s['tax_type']][] = [$s['voucher_date'], implode('·', $names) . ' ' . $s['awb_no'],
                                                (float)$s['supply'], (float)$s['tax']];
                }
                // 청구서 조정 항목(할인 · 추가 등)도 그 세금구분 쪽에 한 줄씩
                $adj = $pdo->prepare('SELECT item_name, supply_amount, tax_type, tax_amount FROM invoice_items
                                       WHERE invoice_id = ? ORDER BY line_no');
                $adj->execute([$invId]);
                foreach ($adj->fetchAll() as $a) {
                    if (isset($lines[$a['tax_type']]) && ((float)$a['supply_amount'] != 0.0 || (float)$a['tax_amount'] != 0.0)) {
                        $lines[$a['tax_type']][] = [$inv['invoice_date'], (string)$a['item_name'],
                                                    (float)$a['supply_amount'], (float)$a['tax_amount']];
                    }
                }

                $kinds = ['ZERO' => ['ZERO', '영세율'], 'TAXABLE' => ['TAX', '과세'], 'EXEMPT' => ['EXEMPT', '면세']];
                $insT = $pdo->prepare(
                    "INSERT INTO tax_invoices
                       (business_entity_id, doc_no, company_id, invoice_id, issue_date,
                        doc_type, buyer_biz_no, buyer_name, buyer_rep, buyer_address,
                        buyer_biz_type, buyer_biz_item, buyer_email,
                        supply_total, tax_total, grand_total, status, created_by)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,'DRAFT',?)");
                $insI = $pdo->prepare(
                    'INSERT INTO tax_invoice_items
                       (tax_invoice_id, line_no, supply_date, item_name, spec, qty,
                        unit_price, supply_amount, tax_amount)
                     VALUES (?,?,?,?,?,1,?,?,?)');
                $made = [];
                foreach ($kinds as $tt => [$docType, $spec]) {
                    if (!$lines[$tt]) { continue; }
                    $sup = array_sum(array_column($lines[$tt], 2));
                    $vat = $tt === 'TAXABLE' ? array_sum(array_column($lines[$tt], 3)) : 0.0;
                    if ($sup == 0.0 && $vat == 0.0) { continue; }
                    $no = next_doc_no('TAX', 'GPA-T-', '-');
                    $insT->execute([
                        $eid, $no, (int)$inv['company_id'], $invId, $date, $docType,
                        $inv['business_number'], $inv['name_ko'], $inv['representative'],
                        $inv['address_ko'], $inv['business_type'], $inv['business_item'],
                        $inv['tax_email'] ?: $inv['email'],
                        $sup, $vat, $sup + $vat, $_SESSION['admin_id'] ?? null,
                    ]);
                    $tid = (int)$pdo->lastInsertId();
                    $n = 0;
                    foreach ($lines[$tt] as [$d, $name, $amt, $tax]) {
                        $insI->execute([$tid, ++$n, $d, mb_substr($name, 0, 200), $spec, $amt, $amt,
                                        $tt === 'TAXABLE' ? $tax : 0.0]);
                    }
                    log_action('세금계산서', 'CREATE', 'tax_invoices', $tid, $no, null,
                               tax_doc_label($docType) . ' · 청구서 ' . $inv['invoice_no'] . ' · 품목 ' . $n . '건');
                    $made[] = [$tid, $no, tax_doc_label($docType)];
                }
                if (!$made) {
                    throw new RuntimeException('담을 품목이 없습니다. 청구서 금액을 확인하세요.');
                }
                $pdo->commit();
                flash(count($made) === 1
                    ? $made[0][2] . ' ' . $made[0][1] . ' 를 만들었습니다. 내용을 확인하고 발행하세요.'
                    : '영세율 · 과세를 나눠 ' . count($made) . '장을 만들었습니다 ('
                      . implode(', ', array_map(fn($m) => $m[2] . ' ' . $m[1], $made)) . '). 내용을 확인하고 발행하세요.');
                redirect(count($made) === 1 ? '?p=tax_invoices&id=' . $made[0][0] : '?p=tax_invoices');
            } catch (Throwable $e) {
                $pdo->rollBack();
                error_log('세금계산서 생성 실패: ' . $e->getMessage());
                $err = $e instanceof RuntimeException ? $e->getMessage() : '만들지 못했습니다.';
            }
        }
    }
}

// ---------------------------------------------------------------- 상태 변경
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array(post('act'), ['issue', 'cancel'], true)) {
    csrf_check();
    $tid = (int)post('id');
    $st = db()->prepare('SELECT * FROM tax_invoices WHERE id = ? AND business_entity_id = ?');
    $st->execute([$tid, $eid]);
    $t = $st->fetch();
    if (!$t) {
        $err = '세금계산서를 찾을 수 없습니다.';
    } elseif (post('act') === 'issue') {
        $nts = post('nts_approval_no');
        db()->prepare(
            "UPDATE tax_invoices SET status = ?, nts_approval_no = ?, sent_at = NOW()
              WHERE id = ?")
            ->execute([$nts !== '' ? 'SENT' : 'ISSUED', $nts ?: null, $tid]);
        log_action('세금계산서', 'ISSUE', 'tax_invoices', $tid, (string)$t['doc_no'],
                   (string)$t['status'], $nts !== '' ? 'SENT ' . $nts : 'ISSUED');
        flash($nts !== '' ? '승인번호를 기록하고 전송완료로 표시했습니다.' : '발행 처리했습니다.');
        redirect('?p=tax_invoices&id=' . $tid);
    } else {
        $why = post('reason');
        if (mb_strlen($why) < 2) {
            $err = '취소 사유를 적어 주세요.';
        } else {
            db()->prepare("UPDATE tax_invoices SET status = 'CANCELLED' WHERE id = ?")
                ->execute([$tid]);
            log_action('세금계산서', 'CANCEL', 'tax_invoices', $tid, (string)$t['doc_no'],
                       (string)$t['status'], 'CANCELLED', $why);
            flash('취소 처리했습니다. 국세청에 이미 전송했다면 수정세금계산서를 따로 발행해야 합니다.');
            redirect('?p=tax_invoices');
        }
    }
}

// ---------------------------------------------------------------- 조회
$id = (int)query('id', '0');
$cur = null; $items = [];
if ($id > 0) {
    $st = db()->prepare(
        'SELECT t.*, i.invoice_no FROM tax_invoices t
           LEFT JOIN invoices i ON i.id = t.invoice_id
          WHERE t.id = ? AND t.business_entity_id = ? AND t.deleted_at IS NULL');
    $st->execute([$id, $eid]);
    $cur = $st->fetch();
    if ($cur) {
        $st = db()->prepare('SELECT * FROM tax_invoice_items WHERE tax_invoice_id = ?
                              ORDER BY line_no');
        $st->execute([$id]);
        $items = $st->fetchAll();
    }
}

$kw  = query('kw');
$sel = query('status');
$where = ['t.business_entity_id = ?', 't.deleted_at IS NULL'];
$params = [$eid];
if ($kw !== '') {
    $where[] = '(t.doc_no LIKE ? OR t.buyer_name LIKE ? OR t.nts_approval_no LIKE ?)';
    $like = '%' . $kw . '%';
    array_push($params, $like, $like, $like);
}
if (isset($STATUS[$sel])) { $where[] = 't.status = ?'; $params[] = $sel; }
$w = implode(' AND ', $where);

$st = db()->prepare("SELECT t.*, i.invoice_no FROM tax_invoices t
                       LEFT JOIN invoices i ON i.id = t.invoice_id
                      WHERE $w ORDER BY t.issue_date DESC, t.id DESC LIMIT 100");
$st->execute($params);
$rows = $st->fetchAll();

// 세금계산서를 아직 안 만든 청구서
$st = db()->prepare(
    "SELECT i.id, i.invoice_no, i.invoice_date, i.taxable_supply, i.zero_supply,
            i.tax_total, i.grand_total, c.name_ko, c.business_number
       FROM invoices i
       JOIN companies c ON c.id = i.company_id
      WHERE i.business_entity_id = ? AND i.deleted_at IS NULL
        AND i.status NOT IN ('DRAFT','CANCELLED')
        AND NOT EXISTS (SELECT 1 FROM tax_invoices x
                         WHERE x.invoice_id = i.id AND x.deleted_at IS NULL
                           AND x.status <> 'CANCELLED')
      ORDER BY i.invoice_date DESC LIMIT 50");
$st->execute([$eid]);
$pending = $st->fetchAll();

layout_head('전자세금계산서', 'tax_invoices');
?>
<div class="head">
  <h1>전자세금계산서</h1>
  <div class="crumb">회계관리 &gt; 전자세금계산서</div>
</div>

<?php if ($err !== ''): ?><div class="msg err"><?= h($err) ?></div><?php endif; ?>

<div class="msg" style="background:var(--info-bg);color:var(--info-fg)">
  <b>국세청 전송은 아직 붙어 있지 않습니다.</b> 연동사(팝빌·바로빌 등)가 정해지지 않았습니다.
  지금은 내용을 여기서 만들고, 연동사에서 발행한 뒤 <b>승인번호를 받아 여기 기록</b>하는 방식으로 씁니다.
</div>

<?php if ($cur): ?>
<div class="card">
  <div class="ch">
    <span class="tnum" style="font-size:14px"><?= h($cur['doc_no']) ?></span>
    <?php [$lab,$cls] = $STATUS[$cur['status']] ?? [$cur['status'],'b-info']; ?>
    <span class="badge <?= $cls ?>"><?= h($lab) ?></span>
    <span class="badge <?= $cur['doc_type'] === 'TAX' ? 'b-info' : 'b-warn' ?>"><?= h(tax_doc_label((string)$cur['doc_type'])) ?></span>
    <a class="btn sm" style="margin-left:auto" href="?p=tax_invoices">목록</a>
  </div>
  <div class="cb f" style="gap:24px">
    <div><div style="font-size:11px;color:var(--ink2)">공급받는자</div>
      <div style="font-weight:600"><?= h($cur['buyer_name']) ?></div></div>
    <div><div style="font-size:11px;color:var(--ink2)">사업자번호</div>
      <div class="tnum"><?= h($cur['buyer_biz_no']) ?></div></div>
    <div><div style="font-size:11px;color:var(--ink2)">대표자</div>
      <div><?= h($cur['buyer_rep'] ?: '-') ?></div></div>
    <div><div style="font-size:11px;color:var(--ink2)">작성일자</div>
      <div class="tnum"><?= h($cur['issue_date']) ?></div></div>
    <div><div style="font-size:11px;color:var(--ink2)">청구서</div>
      <div><?= $cur['invoice_no']
           ? '<a href="?p=invoice_view&amp;id=' . (int)$cur['invoice_id'] . '">'
             . h($cur['invoice_no']) . '</a>' : '-' ?></div></div>
    <div><div style="font-size:11px;color:var(--ink2)">국세청 승인번호</div>
      <div class="tnum"><?= h($cur['nts_approval_no'] ?: '미기록') ?></div></div>
  </div>
  <table>
    <thead><tr>
      <th class="c" style="width:40px">#</th><th style="width:105px">공급일</th>
      <th>품목</th><th style="width:90px">규격</th>
      <th class="r" style="width:130px">공급가액</th><th class="r" style="width:110px">세액</th>
    </tr></thead>
    <tbody>
    <?php foreach ($items as $it): ?>
      <tr>
        <td class="c tnum"><?= (int)$it['line_no'] ?></td>
        <td class="tnum"><?= h($it['supply_date']) ?></td>
        <td><?= h($it['item_name']) ?></td>
        <td><?= h($it['spec'] ?: '-') ?></td>
        <td class="r tnum"><?= money($it['supply_amount']) ?></td>
        <td class="r tnum"><?= money($it['tax_amount']) ?></td>
      </tr>
    <?php endforeach; ?>
      <tr style="background:#F7FAFB">
        <td colspan="4" class="r" style="font-weight:700">합계</td>
        <td class="r tnum" style="font-weight:700"><?= money($cur['supply_total']) ?></td>
        <td class="r tnum" style="font-weight:700"><?= money($cur['tax_total']) ?></td>
      </tr>
    </tbody>
  </table>
  <div class="cb" style="border-top:1px solid var(--line);display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap">
    <?php if (in_array($cur['status'], ['DRAFT','ISSUED'], true)): ?>
      <form method="post" class="f" style="align-items:flex-end;gap:8px">
        <?= csrf_field() ?>
        <input type="hidden" name="act" value="issue">
        <input type="hidden" name="id" value="<?= (int)$cur['id'] ?>">
        <div class="fw w2"><label>국세청 승인번호 (있으면)</label>
          <input type="text" name="nts_approval_no" class="tnum"
                 value="<?= h($cur['nts_approval_no']) ?>"
                 placeholder="연동사에서 발행 후 받은 번호"></div>
        <button class="btn pri">발행 처리</button>
      </form>
    <?php endif; ?>
    <?php if ($cur['status'] !== 'CANCELLED'): ?>
      <form method="post" class="f" style="align-items:flex-end;gap:8px;margin-left:auto"
            onsubmit="return confirm('세금계산서를 취소 처리합니다.');">
        <?= csrf_field() ?>
        <input type="hidden" name="act" value="cancel">
        <input type="hidden" name="id" value="<?= (int)$cur['id'] ?>">
        <div class="fw w3"><label>취소 사유 *</label>
          <input type="text" name="reason" required placeholder="예) 공급가액 착오"></div>
        <button class="btn" style="border-color:#C9A257;color:#6B4700">취소</button>
      </form>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>

<div class="card">
  <div class="ch">세금계산서를 만들 청구서
    <span style="font-weight:400;color:var(--ink3)">발행된 청구서 중 아직 안 만든 것</span>
  </div>
  <?php if (!$pending): ?>
    <div class="empty">대상 청구서가 없습니다.</div>
  <?php else: ?>
  <table>
    <thead><tr>
      <th style="width:175px">청구번호</th><th style="width:105px">청구일</th>
      <th>거래처</th><th style="width:130px">사업자번호</th>
      <th class="r" style="width:120px">과세</th><th class="r" style="width:120px">영세</th>
      <th class="r" style="width:100px">VAT</th><th style="width:230px"></th>
    </tr></thead>
    <tbody>
    <?php foreach ($pending as $pd): ?>
      <tr>
        <td class="tnum" style="font-weight:600"><?= h($pd['invoice_no']) ?></td>
        <td class="tnum"><?= h($pd['invoice_date']) ?></td>
        <td><?= h($pd['name_ko']) ?></td>
        <td class="tnum"><?= $pd['business_number'] !== null && $pd['business_number'] !== ''
             ? h($pd['business_number'])
             : '<span style="color:var(--err-fg)">없음</span>' ?></td>
        <td class="r tnum"><?= money($pd['taxable_supply']) ?></td>
        <td class="r tnum"><?= money($pd['zero_supply']) ?></td>
        <td class="r tnum"><?= money($pd['tax_total']) ?></td>
        <td>
          <form method="post" class="f" style="gap:6px;align-items:center">
            <?= csrf_field() ?>
            <input type="hidden" name="act" value="create">
            <input type="hidden" name="invoice_id" value="<?= (int)$pd['id'] ?>">
            <input type="date" name="issue_date" value="<?= h(date('Y-m-d')) ?>" style="width:140px">
            <button class="btn sm pri">만들기</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <div class="pager"><span>
    <b>영세율과 과세가 섞인 청구서는 [만들기] 한 번에 두 장으로 나눠 만듭니다</b> —
    운송(특송 · 항공 · 해상)은 영세율 세금계산서, 핸드링 · 도큐멘트 · 국내운송 · 창고 · 검사 · 통관는 과세 세금계산서.
  </span></div>
  <?php endif; ?>
</div>

<div class="card">
  <div class="ch">발행 내역
    <form class="f" method="get" style="margin-left:auto;align-items:flex-end;gap:8px">
      <input type="hidden" name="p" value="tax_invoices">
      <input type="text" name="kw" value="<?= h($kw) ?>" placeholder="번호 · 거래처 · 승인번호"
             style="width:210px">
      <select name="status" style="width:120px">
        <option value="">전체</option>
        <?php foreach ($STATUS as $k=>$v): ?>
          <option value="<?= h($k) ?>"<?= $sel===$k?' selected':'' ?>><?= h($v[0]) ?></option>
        <?php endforeach; ?>
      </select>
      <button class="btn sm">검색</button>
    </form>
  </div>
  <?php if (!$rows): ?>
    <div class="empty">발행한 세금계산서가 없습니다.</div>
  <?php else: ?>
  <table>
    <thead><tr>
      <th style="width:165px">문서번호</th><th style="width:100px">작성일자</th>
      <th>공급받는자</th><th style="width:125px">사업자번호</th>
      <th class="c" style="width:90px">종류</th>
      <th class="r" style="width:125px">공급가액</th><th class="r" style="width:105px">세액</th>
      <th style="width:150px">승인번호</th><th class="c" style="width:85px">상태</th>
      <th class="c" style="width:55px"></th>
    </tr></thead>
    <tbody>
    <?php foreach ($rows as $r): [$lab,$cls] = $STATUS[$r['status']] ?? [$r['status'],'b-info']; ?>
      <tr>
        <td class="tnum" style="font-weight:600"><?= h($r['doc_no']) ?></td>
        <td class="tnum"><?= h($r['issue_date']) ?></td>
        <td><?= h($r['buyer_name']) ?></td>
        <td class="tnum"><?= h($r['buyer_biz_no']) ?></td>
        <td class="c"><?= h(tax_doc_label((string)$r['doc_type'])) ?></td>
        <td class="r tnum"><?= money($r['supply_total']) ?></td>
        <td class="r tnum"><?= money($r['tax_total']) ?></td>
        <td class="tnum" style="font-size:11.5px"><?= h($r['nts_approval_no'] ?: '-') ?></td>
        <td class="c"><span class="badge <?= $cls ?>"><?= h($lab) ?></span></td>
        <td class="c"><a class="btn sm" href="?p=tax_invoices&amp;id=<?= (int)$r['id'] ?>">열기</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>
<?php layout_foot();
