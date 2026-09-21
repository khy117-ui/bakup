<?php
require_once APP_DIR . '/layout.php';

$eid = entity_id();
$err = '';
schema_upgrade_statements();   // 재발행 컬럼(issued_at · issue_count · revision · reissue_reason) 보강

// ---------------------------------------------------------------- 재발행
// 명세서는 만들 때 금액이 고정됩니다. 수록 전표의 금액을 고쳤으면 여기서 현재 금액으로 다시 계산해 발행합니다.
// 사유가 필수이고, 이전 · 이후 금액이 작업로그(ISSUE)에 남습니다. 취소 · 삭제된 전표는 이때 명세서에서 빠집니다.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('act') === 'reissue') {
    csrf_check();
    $sid  = (int)post('id');
    $why  = trim(post('reason'));
    $sdate = post('statement_date');
    $pfrom = post('period_from');
    $pto   = post('period_to');
    $st = db()->prepare('SELECT * FROM statements WHERE id = ? AND business_entity_id = ? AND deleted_at IS NULL');
    $st->execute([$sid, $eid]);
    $m = $st->fetch();
    $okDate = fn($d) => (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', $d);
    if (!$m) {
        $err = '명세서를 찾을 수 없습니다.';
    } elseif (mb_strlen($why) < 2) {
        $err = '재발행 사유를 적어 주세요. (예: 전표 운임 정정)';
    } elseif (!$okDate($sdate) || ($pfrom !== '' && !$okDate($pfrom)) || ($pto !== '' && !$okDate($pto))) {
        $err = '날짜 형식이 올바르지 않습니다.';
    } else {
        $pdo = db();
        try {
            $pdo->beginTransaction();
            $before = statement_snapshot($sid);
            // 취소 · 삭제된 전표는 뺍니다
            $gone = $pdo->prepare("SELECT x.shipment_id, s.awb_no FROM statement_shipments x
                                     JOIN shipments s ON s.id = x.shipment_id
                                    WHERE x.statement_id = ? AND (s.deleted_at IS NOT NULL OR s.status = 'CANCELLED')");
            $gone->execute([$sid]);
            $dropped = $gone->fetchAll();
            if ($dropped) {
                $ph = implode(',', array_fill(0, count($dropped), '?'));
                $pdo->prepare("DELETE FROM statement_shipments WHERE statement_id = ? AND shipment_id IN ($ph)")
                    ->execute(array_merge([$sid], array_map(fn($d) => (int)$d['shipment_id'], $dropped)));
            }
            $live = statement_live_totals($sid);
            if ($live['cnt'] === 0) {
                throw new RuntimeException('남은 전표가 없어 재발행할 수 없습니다.');
            }
            $pdo->prepare('UPDATE statements
                              SET supply_total = ?, tax_total = ?, grand_total = ?, statement_date = ?,
                                  period_from = ?, period_to = ?, status = \'ISSUED\',
                                  issued_at = NOW(), issue_count = issue_count + 1, revision = revision + 1, reissue_reason = ?
                            WHERE id = ?')
                ->execute([$live['supply_total'], $live['tax_total'], $live['grand_total'], $sdate,
                           $pfrom !== '' ? $pfrom : $m['period_from'], $pto !== '' ? $pto : $m['period_to'], $why, $sid]);
            $after = statement_snapshot($sid)
                   . ($dropped ? ' · 뺀 전표: ' . implode(', ', array_column($dropped, 'awb_no')) : '');
            log_action('거래명세서', 'ISSUE', 'statements', $sid, (string)$m['statement_no'], $before, $after, $why);
            $pdo->commit();
            flash('거래명세서를 재발행했습니다 (REV. ' . ((int)($m['revision'] ?? 1) + 1) . '). 출력물을 다시 보내세요.'
                  . ($dropped ? ' 취소된 전표 ' . count($dropped) . '건은 뺐습니다.' : ''));
            redirect('?p=statements&id=' . $sid);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            error_log('명세서 재발행 실패: ' . $e->getMessage());
            $err = $e instanceof RuntimeException ? $e->getMessage() : '재발행하지 못했습니다.';
        }
    }
}

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
            $pdo->prepare('UPDATE statements SET issued_at = NOW() WHERE id = ?')->execute([$sid]);

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

// ---------------------------------------------------------------- 상세 (?id=)
$cur = null; $curShips = []; $curLive = null; $curMismatch = false; $history = [];
$curId = (int)query('id', '0');
if ($curId > 0) {
    $st = db()->prepare('SELECT s.*, c.name_ko, c.company_code FROM statements s JOIN companies c ON c.id = s.company_id
                          WHERE s.id = ? AND s.business_entity_id = ? AND s.deleted_at IS NULL');
    $st->execute([$curId, $eid]);
    $cur = $st->fetch() ?: null;
    if ($cur) {
        $st = db()->prepare(
            'SELECT x.line_no, sh.id AS sid, sh.awb_no, sh.voucher_date, sh.status AS ship_status, sh.deleted_at, ca.code AS carrier,
                    COALESCE(t.supply_total,0) AS supply, COALESCE(t.tax_total,0) AS tax, COALESCE(t.grand_total,0) AS grand
               FROM statement_shipments x
               JOIN shipments sh ON sh.id = x.shipment_id
               JOIN carriers ca ON ca.id = sh.carrier_id
               LEFT JOIN v_shipment_totals t ON t.shipment_id = sh.id
              WHERE x.statement_id = ? ORDER BY x.line_no');
        $st->execute([$curId]);
        $curShips = $st->fetchAll();
        $curLive = statement_live_totals($curId);
        $curMismatch = abs($curLive['grand_total'] - (float)$cur['grand_total']) > 0.5
                    || $curLive['cnt'] !== count($curShips);
        $st = db()->prepare("SELECT * FROM activity_logs WHERE ref_table = 'statements' AND ref_id = ? ORDER BY id DESC LIMIT 100");
        $st->execute([$curId]);
        $history = $st->fetchAll();
    }
}
$ACT = ['CREATE' => ['만듦', 'b-ok'], 'UPDATE' => ['수정', 'b-info'], 'DELETE' => ['삭제', 'b-err'],
        'CANCEL' => ['취소', 'b-err'], 'ISSUE' => ['발행', 'b-info'], 'PRINT' => ['출력', 'b-warn']];

// ---------------------------------------------------------------- 목록
$st = db()->prepare(
    'SELECT s.*, c.name_ko,
            (SELECT COUNT(*) FROM statement_shipments x WHERE x.statement_id = s.id) AS cnt
       FROM statements s JOIN companies c ON c.id = s.company_id
      WHERE s.business_entity_id = ? AND s.deleted_at IS NULL
      ORDER BY s.statement_date DESC, s.id DESC LIMIT 100');
$st->execute([$eid]);
$rows = $st->fetchAll();
// 수록 전표의 현재 금액 합 — 저장값과 다르면 '재발행 필요'
$liveMap = [];
if ($rows) {
    $ids = array_map(fn($r) => (int)$r['id'], $rows);
    $st = db()->prepare("SELECT x.statement_id, COALESCE(SUM(t.supply_total),0) + COALESCE(SUM(t.tax_total),0) AS grand
                           FROM statement_shipments x
                           JOIN shipments sh ON sh.id = x.shipment_id AND sh.deleted_at IS NULL AND sh.status <> 'CANCELLED'
                           LEFT JOIN v_shipment_totals t ON t.shipment_id = sh.id
                          WHERE x.statement_id IN (" . implode(',', array_fill(0, count($ids), '?')) . ") GROUP BY x.statement_id");
    $st->execute($ids);
    foreach ($st->fetchAll() as $lr) { $liveMap[(int)$lr['statement_id']] = (float)$lr['grand']; }
}

layout_head('거래명세서', 'statements');
?>
<div class="head">
  <h1>거래명세서</h1>
  <div class="crumb">영업관리 &gt; 거래명세서</div>
</div>

<?php if ($err !== ''): ?><div class="msg err"><?= h($err) ?></div><?php endif; ?>

<?php if ($cur): ?>
<div class="card">
  <div class="ch">
    <span class="tnum" style="font-size:14px"><?= h($cur['statement_no']) ?></span>
    <span class="badge b-ok">발행 <?= (int)($cur['issue_count'] ?? 1) ?>회</span>
    <?php if ((int)($cur['revision'] ?? 1) > 1): ?><span class="badge b-info">REV. <?= (int)$cur['revision'] ?></span><?php endif; ?>
    <span style="font-weight:400;color:var(--ink3)"><?= h($cur['name_ko']) ?> · <?= h($cur['statement_date']) ?> · 기간 <?= h($cur['period_from']) ?> ~ <?= h($cur['period_to']) ?>
      · 최종 발행 <?= h(substr((string)$cur['issued_at'], 0, 16)) ?><?= !empty($cur['reissue_reason']) ? ' · 사유: ' . h($cur['reissue_reason']) : '' ?></span>
    <a class="btn sm" style="margin-left:auto" href="?p=statement_print&amp;id=<?= (int)$cur['id'] ?>" target="_blank">출력</a>
    <a class="btn sm" href="?p=statements">목록</a>
  </div>
  <?php if ($curMismatch): ?>
  <div class="msg err" style="margin:10px 12px 0">
    수록 전표의 현재 금액이 명세서와 다릅니다 — 명세서 합계 <b class="tnum"><?= money($cur['grand_total']) ?></b>
    → 현재 전표 합계 <b class="tnum"><?= money($curLive['grand_total']) ?></b>
    <?= $curLive['cnt'] !== count($curShips) ? ' (취소된 전표 ' . (count($curShips) - $curLive['cnt']) . '건 포함)' : '' ?>.
    아래 <b>[재발행]</b> 으로 현재 금액으로 다시 발행하세요. 재발행 전 출력물에는 이전 금액이 나갑니다.
  </div>
  <?php endif; ?>
  <table>
    <thead><tr>
      <th class="c" style="width:40px">#</th><th style="width:105px">전표일</th><th style="width:165px">AWB</th>
      <th class="c" style="width:65px">운송사</th><th class="c" style="width:70px">상태</th>
      <th class="r" style="width:125px">공급가액</th><th class="r" style="width:100px">VAT</th><th class="r" style="width:125px">합계 (현재)</th>
    </tr></thead>
    <tbody>
    <?php foreach ($curShips as $cs): $dead = $cs['deleted_at'] !== null || $cs['ship_status'] === 'CANCELLED'; ?>
      <tr style="<?= $dead ? 'color:var(--err-fg)' : '' ?>">
        <td class="c tnum"><?= (int)$cs['line_no'] ?></td>
        <td class="tnum"><?= h($cs['voucher_date']) ?></td>
        <td class="tnum" style="font-weight:600"><a href="?p=shipment_form&amp;id=<?= (int)$cs['sid'] ?>"><?= h($cs['awb_no']) ?></a></td>
        <td class="c"><?= h($cs['carrier']) ?></td>
        <td class="c"><?= $dead ? '취소' : h($cs['ship_status']) ?></td>
        <td class="r tnum"><?= money($cs['supply']) ?></td>
        <td class="r tnum"><?= money($cs['tax']) ?></td>
        <td class="r tnum" style="font-weight:700"><?= money($cs['grand']) ?></td>
      </tr>
    <?php endforeach; ?>
      <tr style="background:#F7FAFB">
        <td colspan="5" class="r" style="font-weight:700">명세서 저장값 / 현재 합계</td>
        <td class="r tnum"><?= money($cur['supply_total']) ?> / <?= money($curLive['supply_total']) ?></td>
        <td class="r tnum"><?= money($cur['tax_total']) ?> / <?= money($curLive['tax_total']) ?></td>
        <td class="r tnum" style="font-weight:700;color:<?= $curMismatch ? 'var(--err-fg)' : 'var(--ink)' ?>"><?= money($cur['grand_total']) ?> / <?= money($curLive['grand_total']) ?></td>
      </tr>
    </tbody>
  </table>
  <div class="cb" style="border-top:1px solid var(--line)">
    <form method="post" class="f" style="align-items:flex-end;gap:8px"
          onsubmit="return confirm('수록 전표의 현재 금액으로 명세서를 다시 발행합니다. 차수(REV.)가 올라가고 이전 금액과 사유는 변경 이력에 남습니다. 계속할까요?');">
      <?= csrf_field() ?>
      <input type="hidden" name="act" value="reissue">
      <input type="hidden" name="id" value="<?= (int)$cur['id'] ?>">
      <div class="fw w1"><label>명세서 일자</label><input type="date" name="statement_date" value="<?= h($cur['statement_date']) ?>" required></div>
      <div class="fw w1"><label>기간 시작</label><input type="date" name="period_from" value="<?= h((string)$cur['period_from']) ?>"></div>
      <div class="fw w1"><label>기간 종료</label><input type="date" name="period_to" value="<?= h((string)$cur['period_to']) ?>"></div>
      <div class="fw gr" style="min-width:260px"><label>재발행 사유 *</label>
        <input type="text" name="reason" required placeholder="예) 전표 운임 정정으로 금액 변경"></div>
      <button class="btn <?= $curMismatch ? 'pri' : '' ?>">재발행</button>
    </form>
    <div style="font-size:11.5px;color:var(--ink3);margin-top:8px">
      명세서 번호는 그대로 두고 금액 · 일자를 현재 값으로 다시 계산합니다. 취소 · 삭제된 전표는 이때 명세서에서 빠집니다.
      전표를 더하거나 빼려면 아래에서 새 명세서를 만드세요.
    </div>
  </div>
</div>

<div class="card">
  <div class="ch">변경 이력
    <span style="font-weight:400;color:var(--ink3)">발행 · 재발행이 이전값 → 이후값과 사유로 남습니다</span>
    <a class="btn sm" style="margin-left:auto" href="?p=activity_log&amp;kw=<?= h(rawurlencode((string)$cur['statement_no'])) ?>">전체 작업로그</a>
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
      <th class="r" style="width:125px">합계</th><th class="c" style="width:120px"></th>
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
        <td class="r tnum" style="font-weight:700"><?= money($r['grand_total']) ?>
          <?php if ((int)($r['revision'] ?? 1) > 1): ?><div style="font-size:10.5px;color:var(--ink3);font-weight:400">REV. <?= (int)$r['revision'] ?></div><?php endif; ?>
          <?php if (isset($liveMap[(int)$r['id']]) && abs($liveMap[(int)$r['id']] - (float)$r['grand_total']) > 0.5): ?>
            <div style="font-size:10.5px;color:var(--err-fg);font-weight:400">전표 금액 바뀜 · 재발행 필요</div><?php endif; ?></td>
        <td class="c"><a class="btn sm" href="?p=statements&amp;id=<?= (int)$r['id'] ?>">열기</a>
          <a class="btn sm" href="?p=statement_print&amp;id=<?= (int)$r['id'] ?>" target="_blank">출력</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>
<?php layout_foot();
