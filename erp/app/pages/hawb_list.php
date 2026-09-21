<?php
// 웹에서 직접 열면 실행되지 않게 막습니다 (nginx 면 .htaccess 가 무시됩니다)
if (!defined('APP_DIR')) { http_response_code(403); exit('Forbidden'); }

require_once APP_DIR . '/layout.php';
require_once APP_DIR . '/hawb.php';

/**
 * HAWB 발행 — 목록 · 엑셀 붙여넣기로 여러 건 만들기 · 골라서 인쇄.
 *
 * 엑셀(세관 신고용) 에 적던 줄을 그대로 복사해 붙이면 한 줄이 비엘 한 장이 됩니다.
 */

hawb_ensure_table();

$eid = entity_id();
$err = '';

// ---------------------------------------------------------------- 엑셀 붙여넣기로 여러 건 등록
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('act') === 'paste') {
    csrf_check();
    $rows = hawb_parse_paste((string)($_POST['paste'] ?? ''));
    $common = [
        'on_board_date' => preg_match('/^\d{4}-\d{2}-\d{2}$/', post('on_board_date')) ? post('on_board_date') : null,
        'flight_no'     => trim(post('flight_no')) ?: null,
        'master_no'     => trim(post('master_no')) ?: null,
        'origin'        => trim(post('origin')) ?: null,
        'destination'   => trim(post('destination')) ?: null,
        'ship_type'     => array_key_exists(post('ship_type'), HAWB_TYPES) ? post('ship_type') : 'PARCEL',
        'vol_divisor'   => array_key_exists((int)post('vol_divisor'), HAWB_DIVISORS) ? (int)post('vol_divisor') : 6000,
        'batch_id'      => (int)post('batch_id') ?: null,
    ];
    if (!$rows) {
        $err = '읽을 줄이 없습니다. 엑셀에서 자료 줄(머리글 제외)을 복사해 붙여 넣으세요.';
    } else {
        $pdo = db();
        try {
            $pdo->beginTransaction();
            $have = $pdo->prepare('SELECT id FROM hawbs WHERE business_entity_id = ? AND house_no = ? AND deleted_at IS NULL');
            $ins = $pdo->prepare(
                'INSERT INTO hawbs (business_entity_id, house_no, master_no, ship_type, on_board_date, flight_no,
                                    origin, destination, shipper_name, shipper_addr, consignee_name, consignee_addr,
                                    consignee_phone, description, pieces, packing, weight, declared_value,
                                    vol_divisor, batch_id, warehouse, notify, trade_code, sender_country,
                                    use_type, agent_code, allow_code, cn_unit, cn_currency, cn_origin,
                                    shipper_country, shipper_city, decl_type, trade_mode, created_by)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
            $made = 0; $skip = [];
            foreach ($rows as $r) {
                if (!preg_match('/^[\x20-\x7E]+$/', $r['house_no'])) {
                    $skip[] = $r['house_no'] . ' (바코드로 만들 수 없는 글자)';
                    continue;
                }
                $have->execute([$eid, $r['house_no']]);
                if ($have->fetchColumn()) { $skip[] = $r['house_no'] . ' (이미 있음)'; continue; }
                $num = static fn (string $s): ?float => $s === '' ? null : (float)str_replace(',', '', $s);
                $ins->execute([
                    $eid, $r['house_no'], $common['master_no'], $common['ship_type'],
                    $common['on_board_date'], $common['flight_no'], $common['origin'], $common['destination'],
                    $r['shipper_name'] ?: null, $r['shipper_addr'] ?: null,
                    $r['consignee_name'] ?: null, $r['consignee_addr'] ?: null, $r['consignee_phone'] ?: null,
                    $r['description'] ?: null, $num($r['pieces']), $r['packing'] ?: null,
                    $num($r['weight']), $num($r['declared_value']), $common['vol_divisor'], $common['batch_id'],
                    HAWB_DEFAULTS['warehouse'], HAWB_DEFAULTS['notify'], HAWB_DEFAULTS['trade_code'],
                    HAWB_DEFAULTS['sender_country'], HAWB_DEFAULTS['use_type'], HAWB_DEFAULTS['agent_code'],
                    HAWB_DEFAULTS['allow_code'], HAWB_DEFAULTS['cn_unit'], HAWB_DEFAULTS['cn_currency'],
                    HAWB_DEFAULTS['cn_origin'], HAWB_DEFAULTS['shipper_country'], HAWB_DEFAULTS['shipper_city'],
                    HAWB_DEFAULTS['decl_type'], HAWB_DEFAULTS['trade_mode'],
                    $_SESSION['admin_id'] ?? null,
                ]);
                $made++;
            }
            log_action('물류', 'CREATE', 'hawbs', 0, $made . '건', null, 'HAWB 엑셀 붙여넣기 등록');
            $pdo->commit();
            flash($made . '건을 등록했습니다.'
                . ($skip ? ' 건너뛴 것 ' . count($skip) . '건: ' . implode(', ', array_slice($skip, 0, 5))
                    . (count($skip) > 5 ? ' 외' : '') : ''));
            redirect('?p=hawb_list');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            error_log('HAWB 붙여넣기 실패: ' . $e->getMessage());
            $err = '등록하지 못했습니다. 붙여 넣은 내용의 칸 순서를 확인하세요.';
        }
    }
}

// ---------------------------------------------------------------- 삭제 (휴지통)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('act') === 'delete') {
    csrf_check();
    $did = (int)post('id');
    $st = db()->prepare('UPDATE hawbs SET deleted_at = NOW(), updated_by = ?
                          WHERE id = ? AND business_entity_id = ? AND deleted_at IS NULL');
    $st->execute([$_SESSION['admin_id'] ?? null, $did, $eid]);
    log_action('물류', 'DELETE', 'hawbs', $did, post('house_no'), null, 'HAWB 삭제', post('reason'));
    flash($st->rowCount() ? 'HAWB 를 삭제했습니다.' : '삭제할 HAWB 를 찾지 못했습니다.');
    redirect('?p=hawb_list');
}

// ---------------------------------------------------------------- 조회
$kw   = query('kw');
$from = query('from');
$to   = query('to');
$page = max(1, (int)query('page', '1'));
$per  = 30; $off = ($page - 1) * $per;

$where = ['business_entity_id = ?', 'deleted_at IS NULL'];
$params = [$eid];
if ($kw !== '') {
    $where[] = '(house_no LIKE ? OR master_no LIKE ? OR shipper_name LIKE ? OR consignee_name LIKE ?)';
    $like = '%' . $kw . '%';
    array_push($params, $like, $like, $like, $like);
}
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) { $where[] = 'on_board_date >= ?'; $params[] = $from; }
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $to))   { $where[] = 'on_board_date <= ?'; $params[] = $to; }
$w = implode(' AND ', $where);
$w2 = str_replace(['house_no', 'master_no', 'shipper_name', 'consignee_name', 'business_entity_id',
                   'deleted_at', 'on_board_date'],
                  ['h.house_no', 'h.master_no', 'h.shipper_name', 'h.consignee_name', 'h.business_entity_id',
                   'h.deleted_at', 'h.on_board_date'], $w);

$st = db()->prepare("SELECT COUNT(*) FROM hawbs WHERE $w");
$st->execute($params);
$total = (int)$st->fetchColumn();

$st = db()->prepare("SELECT h.*, b.flight_date AS b_date, b.flight_no AS b_flt,
                            (SELECT COUNT(*) FROM hawb_items i WHERE i.hawb_id = h.id) AS item_cnt
                       FROM hawbs h LEFT JOIN hawb_batches b ON b.id = h.batch_id
                      WHERE $w2 ORDER BY h.on_board_date DESC, h.id DESC LIMIT $per OFFSET $off");
$st->execute($params);
$rows = $st->fetchAll();

// 붙여넣기 칸에서 고를 항공편
$batchQ = (int)query('batch_id', '0');
$batches = db()->prepare('SELECT id, flight_date, flight_no, mawb_no FROM hawb_batches
                           WHERE business_entity_id = ? AND deleted_at IS NULL
                           ORDER BY flight_date DESC, id DESC LIMIT 50');
$batches->execute([$eid]);
$batches = $batches->fetchAll();

layout_head('HAWB 발행', 'hawb_list');
?>
<div class="head">
  <h1>HAWB 발행 <span style="font-size:13px;font-weight:400;color:var(--ink3)">항공 하우스 비엘</span></h1>
  <div class="crumb">물류관리 &gt; HAWB 발행</div>
  <div class="right"><a class="btn" href="?p=hawb_batches">수입 서류 (항공편)</a>
    <a class="btn pri" href="?p=hawb_form">새 HAWB</a></div>
</div>

<?php if ($err !== ''): ?><div class="msg err"><?= h($err) ?></div><?php endif; ?>

<details class="card"<?= $err !== '' ? ' open' : '' ?>>
  <summary class="ch" style="cursor:pointer">엑셀에서 붙여넣기 — 여러 건 한 번에
    <span style="font-weight:400;color:var(--ink3)">세관 신고용 시트의 줄을 그대로 복사해 붙이세요</span></summary>
  <form method="post" class="cb">
    <?= csrf_field() ?>
    <input type="hidden" name="act" value="paste">
    <div class="f" style="align-items:flex-end">
      <div class="fw w1"><label for="pob">On Board Date</label>
        <input type="date" id="pob" name="on_board_date" value="<?= h(date('Y-m-d')) ?>"></div>
      <div class="fw w1"><label for="pfl">Flight No</label>
        <input type="text" id="pfl" name="flight_no" maxlength="30"></div>
      <div class="fw w1"><label for="pmn">Master Number</label>
        <input type="text" id="pmn" name="master_no" class="tnum" maxlength="50"></div>
      <div class="fw w1"><label for="pog">Origin</label>
        <input type="text" id="pog" name="origin" maxlength="40" placeholder="QINGDAO"></div>
      <div class="fw w1"><label for="pde">Destination</label>
        <input type="text" id="pde" name="destination" maxlength="40" placeholder="SEOUL"></div>
      <div class="fw w2"><label for="pbt">항공편 (적하목록)</label>
        <select id="pbt" name="batch_id">
          <option value="">— 지정 안 함 —</option>
          <?php foreach ($batches as $bt): ?>
            <option value="<?= (int)$bt['id'] ?>"<?= $batchQ === (int)$bt['id'] ? ' selected' : '' ?>>
              <?= h($bt['flight_date'] . ' · ' . $bt['flight_no'] . ' · ' . $bt['mawb_no']) ?></option>
          <?php endforeach; ?></select></div>
      <div class="fw w1"><label for="pvd">부피중량 나누는 수</label>
        <select id="pvd" name="vol_divisor">
          <?php foreach (HAWB_DIVISORS as $k => $lab): ?><option value="<?= $k ?>"><?= h($lab) ?></option><?php endforeach; ?>
        </select></div>
      <div class="fw w1"><label for="pst">구분</label>
        <select id="pst" name="ship_type">
          <?php foreach (HAWB_TYPES as $k => $lab): ?><option value="<?= $k ?>"><?= h($lab) ?></option><?php endforeach; ?>
        </select></div>
    </div>
    <div class="fw" style="margin-top:10px"><label for="pt">붙여넣기</label>
      <textarea id="pt" name="paste" rows="7" placeholder="엑셀에서 줄을 선택해 Ctrl+C 한 뒤 여기에 Ctrl+V"></textarea></div>
    <div style="font-size:11.5px;color:var(--ink2);line-height:1.8;margin-top:8px">
      칸 순서는 <b>세관 신고용 시트와 같습니다</b> —
      NO · <b>송장번호</b> · 보내는분 · 보내는분 주소 · 받는분 · 받는분 주소 · 전화 · 내용물 · 개수 · 단위 · 무게 · 부피 · 금액<br>
      · 머리글 줄은 저절로 걸러집니다. 이미 있는 송장번호는 건너뜁니다.<br>
      · 위에 적은 <b>On Board Date · Flight No · Master · Origin · Destination</b> 은 붙여 넣은 모든 줄에 똑같이 들어갑니다.<br>
      · 나머지 칸(치수 · 운임 등)은 등록한 뒤 한 건씩 열어서 채우면 됩니다.
    </div>
    <div style="margin-top:12px"><button class="btn pri">붙여넣은 줄 등록</button></div>
  </form>
</details>

<div class="card"><div class="cb">
  <form class="f" method="get" style="align-items:flex-end">
    <input type="hidden" name="p" value="hawb_list">
    <div class="fw w3"><label for="kw">검색</label>
      <input type="text" id="kw" name="kw" value="<?= h($kw) ?>" placeholder="송장번호 · Master · 보내는분 · 받는분"></div>
    <div class="fw w1"><label for="f">On Board 시작</label>
      <input type="date" id="f" name="from" value="<?= h($from) ?>"></div>
    <div class="fw w1"><label for="t">끝</label>
      <input type="date" id="t" name="to" value="<?= h($to) ?>"></div>
    <button class="btn">조회</button>
    <a class="btn" href="?p=hawb_list">초기화</a>
  </form>
</div></div>

<div class="card">
  <div class="ch">HAWB 목록 <span style="font-weight:400;color:var(--ink3)"><?= number_format($total) ?>건</span>
    <span style="margin-left:auto;display:flex;gap:6px">
      <button type="button" class="btn sm pri" id="printsel">선택한 것 인쇄</button>
    </span>
  </div>
  <?php if (!$rows): ?>
    <div class="empty">등록된 HAWB 가 없습니다. [새 HAWB] 또는 위의 엑셀 붙여넣기로 만드세요.</div>
  <?php else: ?>
  <table>
    <thead><tr>
      <th class="c" style="width:34px"><input type="checkbox" id="all" aria-label="전체 선택" style="width:auto"></th>
      <th style="width:120px">송장번호</th>
      <th style="width:100px">On Board</th>
      <th style="width:150px">보내는 분</th>
      <th style="width:150px">받는 분</th>
      <th>내용물</th>
      <th class="r" style="width:70px">개수</th>
      <th class="r" style="width:90px">중량</th>
      <th style="width:120px">항공편</th><th class="c" style="width:55px">품목</th>
      <th class="c" style="width:150px"></th>
    </tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td class="c"><input type="checkbox" class="pick" value="<?= (int)$r['id'] ?>" style="width:auto"
                             aria-label="<?= h($r['house_no']) ?> 고르기"></td>
        <td class="tnum" style="font-weight:700"><a href="?p=hawb_form&amp;id=<?= (int)$r['id'] ?>"><?= h($r['house_no']) ?></a>
          <?php if ((int)$r['print_count'] > 0): ?>
            <span class="badge b-ok" title="<?= h((string)$r['printed_at']) ?>">인쇄 <?= (int)$r['print_count'] ?></span>
          <?php endif; ?></td>
        <td class="tnum"><?= h((string)$r['on_board_date']) ?></td>
        <td><?= h((string)$r['shipper_name']) ?></td>
        <td><?= h((string)$r['consignee_name']) ?></td>
        <td style="font-size:11.5px;color:var(--ink2)"><?= h((string)$r['description']) ?></td>
        <td class="r tnum"><?= $r['pieces'] !== null ? (int)$r['pieces'] : '' ?></td>
        <td class="r tnum"><?= h(hawb_num($r['weight'])) ?></td>
        <td style="font-size:11.5px"><?= $r['batch_id']
            ? '<a href="?p=hawb_batches&amp;id=' . (int)$r['batch_id'] . '">' . h((string)$r['b_date'] . ' ' . (string)$r['b_flt']) . '</a>'
            : h(trim((string)$r['flight_no'] . ' ' . (string)$r['destination'])) ?></td>
        <td class="c"><?= (int)$r['item_cnt'] > 0 ? '<span class="badge b-ok">' . (int)$r['item_cnt'] . '</span>' : '' ?></td>
        <td class="c">
          <a class="btn sm" href="?p=hawb_print&amp;ids=<?= (int)$r['id'] ?>" target="_blank">인쇄</a>
          <a class="btn sm" href="?p=hawb_form&amp;id=<?= (int)$r['id'] ?>">수정</a>
          <button type="button" class="btn sm del" data-id="<?= (int)$r['id'] ?>" data-no="<?= h($r['house_no']) ?>"
                  style="color:#A32020;padding:0 8px" title="삭제">🗑</button>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
  <?php if ($total > $per): $pages = (int)ceil($total / $per); ?>
    <div class="pager">
      <?php for ($i = max(1, $page - 4); $i <= min($pages, $page + 4); $i++): ?>
        <a class="<?= $i === $page ? 'on' : '' ?>"
           href="?p=hawb_list&amp;page=<?= $i ?>&amp;kw=<?= urlencode($kw) ?>&amp;from=<?= urlencode($from) ?>&amp;to=<?= urlencode($to) ?>"><?= $i ?></a>
      <?php endfor; ?>
    </div>
  <?php endif; ?>
</div>

<form method="post" id="delform" style="display:none">
  <?= csrf_field() ?>
  <input type="hidden" name="act" value="delete">
  <input type="hidden" name="id" value=""><input type="hidden" name="house_no" value="">
  <input type="hidden" name="reason" value="">
</form>

<script>
(function () {
  var picks = document.querySelectorAll('.pick'), all = document.getElementById('all');
  if (all) all.addEventListener('change', function () { picks.forEach(function (p) { p.checked = all.checked; }); });
  document.getElementById('printsel').addEventListener('click', function () {
    var ids = [];
    picks.forEach(function (p) { if (p.checked) ids.push(p.value); });
    if (!ids.length) { alert('인쇄할 HAWB 를 고르세요.'); return; }
    window.open('?p=hawb_print&ids=' + ids.join(','), '_blank');
  });
  document.querySelectorAll('.del').forEach(function (b) {
    b.addEventListener('click', function () {
      var why = prompt('HAWB ' + b.dataset.no + ' 을(를) 삭제합니다.\n삭제 사유를 적어 주세요.');
      if (why === null) { return; }
      if (why.trim().length < 2) { alert('삭제 사유를 두 글자 이상 적어 주세요.'); return; }
      var f = document.getElementById('delform');
      f.elements['id'].value = b.dataset.id;
      f.elements['house_no'].value = b.dataset.no;
      f.elements['reason'].value = why.trim();
      f.submit();
    });
  });
})();
</script>
<?php layout_foot();
