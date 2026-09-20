<?php
// 웹에서 직접 열면 실행되지 않게 막습니다 (nginx 면 .htaccess 가 무시됩니다)
if (!defined('APP_DIR')) { http_response_code(403); exit('Forbidden'); }

require_once APP_DIR . '/layout.php';
require_once APP_DIR . '/hawb.php';

/**
 * 항공편(적하목록) 관리 — 수입 서류 묶음.
 *
 * 항공편 하나(MAWB · 편명 · 날짜)에 송장(HAWB) 여러 건이 붙고, 그 묶음으로
 * ① 영문 통관목록 ② 중문 적하목록 ③ HAWB 비엘 ④ INVOICE 를 내려받습니다.
 */

hawb_ensure_table();

$eid = entity_id();
$err = '';
$id  = (int)query('id', '0');

const HB_FIELDS = ['flight_date', 'flight_no', 'mawb_no', 'origin_port', 'dest_port', 'io_flag',
                   'transport_mode', 'port_code', 'operator_code', 'operator_name', 'memo'];

// ---------------------------------------------------------------- 저장
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('act') === 'save') {
    csrf_check();
    $id = (int)post('id');
    $d  = [];
    foreach (HB_FIELDS as $f) { $d[$f] = trim(post($f)) ?: null; }
    $d['io_flag'] = array_key_exists((string)$d['io_flag'], HAWB_IO_FLAGS) ? $d['io_flag'] : 'E';
    if ($d['flight_date'] !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$d['flight_date'])) {
        $d['flight_date'] = null;
    }
    if ($d['flight_date'] === null) {
        $err = '항공편 날짜를 입력하세요.';
    } else {
        try {
            $pdo = db();
            if ($id > 0) {
                $set = implode(', ', array_map(static fn ($f) => "$f = ?", HB_FIELDS));
                $pdo->prepare("UPDATE hawb_batches SET $set, updated_by = ?, updated_at = NOW()
                                WHERE id = ? AND business_entity_id = ? AND deleted_at IS NULL")
                    ->execute(array_merge(array_values($d), [$_SESSION['admin_id'] ?? null, $id, $eid]));
                log_action('물류', 'UPDATE', 'hawb_batches', $id, (string)$d['flight_no'], null, '항공편 수정');
                flash('항공편 정보를 저장했습니다.');
            } else {
                $cols = array_merge(['business_entity_id'], HB_FIELDS, ['created_by']);
                $ph   = implode(',', array_fill(0, count($cols), '?'));
                $pdo->prepare('INSERT INTO hawb_batches (' . implode(',', $cols) . ") VALUES ($ph)")
                    ->execute(array_merge([$eid], array_values($d), [$_SESSION['admin_id'] ?? null]));
                $id = (int)$pdo->lastInsertId();
                log_action('물류', 'CREATE', 'hawb_batches', $id, (string)$d['flight_no'], null, '항공편 등록');
                flash('항공편을 만들었습니다. 이제 송장(HAWB)을 넣으세요.');
            }
            redirect('?p=hawb_batches&id=' . $id);
        } catch (Throwable $e) {
            error_log('항공편 저장 실패: ' . $e->getMessage());
            $err = '저장하지 못했습니다.';
        }
    }
}

// ---------------------------------------------------------------- 삭제 (송장이 없을 때만)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('act') === 'delete') {
    csrf_check();
    $did = (int)post('id');
    $st = db()->prepare('SELECT COUNT(*) FROM hawbs WHERE batch_id = ? AND deleted_at IS NULL');
    $st->execute([$did]);
    if ((int)$st->fetchColumn() > 0) {
        flash('이 항공편에 송장이 들어 있습니다. 송장을 먼저 옮기거나 지우세요.');
    } else {
        db()->prepare('UPDATE hawb_batches SET deleted_at = NOW(), updated_by = ?
                        WHERE id = ? AND business_entity_id = ?')
            ->execute([$_SESSION['admin_id'] ?? null, $did, $eid]);
        log_action('물류', 'DELETE', 'hawb_batches', $did, post('label'), null, '항공편 삭제', post('reason'));
        flash('항공편을 삭제했습니다.');
    }
    redirect('?p=hawb_batches');
}

// ---------------------------------------------------------------- 한 건 보기
$cur = null;
$rows = [];
if ($id > 0) {
    $st = db()->prepare('SELECT * FROM hawb_batches WHERE id = ? AND business_entity_id = ? AND deleted_at IS NULL');
    $st->execute([$id, $eid]);
    $cur = $st->fetch();
    if (!$cur) { flash('항공편을 찾을 수 없습니다.'); redirect('?p=hawb_batches'); }
    $st = db()->prepare("SELECT h.*, (SELECT COUNT(*) FROM hawb_items i WHERE i.hawb_id = h.id) AS item_cnt
                           FROM hawbs h WHERE h.batch_id = ? AND h.deleted_at IS NULL
                          ORDER BY h.house_no, h.id");
    $st->execute([$id]);
    $rows = $st->fetchAll();
}

// 새로 만들 때는 양식의 고정값을 미리 채웁니다
$new = array_merge(['id' => 0, 'flight_date' => date('Y-m-d'), 'flight_no' => '', 'mawb_no' => '',
                    'origin_port' => '', 'dest_port' => '', 'memo' => ''], HAWB_BATCH_DEFAULTS);
$f = $cur ?: $new;
$v = static fn (string $k): string => h((string)($f[$k] ?? ''));

// 목록
$st = db()->prepare("SELECT b.*, (SELECT COUNT(*) FROM hawbs h WHERE h.batch_id = b.id AND h.deleted_at IS NULL) AS cnt,
                            (SELECT COALESCE(SUM(h.weight),0) FROM hawbs h WHERE h.batch_id = b.id AND h.deleted_at IS NULL) AS wt
                       FROM hawb_batches b
                      WHERE b.business_entity_id = ? AND b.deleted_at IS NULL
                      ORDER BY b.flight_date DESC, b.id DESC LIMIT 60");
$st->execute([$eid]);
$list = $st->fetchAll();

layout_head('수입 서류 · 항공편', 'hawb_batches');
?>
<div class="head">
  <h1>수입 서류 <span style="font-size:13px;font-weight:400;color:var(--ink3)">항공편(적하목록) 단위</span></h1>
  <div class="crumb">물류관리 &gt; 수입 서류</div>
  <div class="right"><a class="btn" href="?p=hawb_list">HAWB 전체 목록</a></div>
</div>

<?php if ($err !== ''): ?><div class="msg err"><?= h($err) ?></div><?php endif; ?>

<?php if ($cur): ?>
<div class="card">
  <div class="ch">서류 내려받기
    <span style="font-weight:400;color:var(--ink3)">받은 엑셀 양식 그대로 — 세관에 그대로 올릴 수 있습니다</span></div>
  <div class="cb" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
    <a class="btn pri" href="?p=hawb_export&amp;type=en&amp;batch=<?= $id ?>">① 영문 통관목록 (엑셀)</a>
    <a class="btn pri" href="?p=hawb_export&amp;type=cn&amp;batch=<?= $id ?>">② 중문 적하목록 (엑셀)</a>
    <a class="btn" href="?p=hawb_print&amp;batch=<?= $id ?>" target="_blank">③ HAWB 비엘 전체 인쇄</a>
    <span style="font-size:11.5px;color:var(--ink3)">
      ④ INVOICE 는 송장마다 내용이 달라서 아래 목록에서 건별로 받습니다.
      <?= count($rows) ?>건 · 중량 합계 <?= h(hawb_num(array_sum(array_column($rows, 'weight')))) ?> KG
    </span>
  </div>
</div>
<?php endif; ?>

<div class="card">
  <div class="ch"><?= $cur ? '항공편 정보' : '새 항공편' ?>
    <span style="font-weight:400;color:var(--ink3)">영문 · 중문 서류의 머리 부분에 들어갑니다</span></div>
  <form method="post" class="cb">
    <?= csrf_field() ?>
    <input type="hidden" name="act" value="save">
    <input type="hidden" name="id" value="<?= (int)($f['id'] ?? 0) ?>">
    <div class="f" style="align-items:flex-end">
      <div class="fw w1"><label for="fd">항공편 날짜 *</label>
        <input type="date" id="fd" name="flight_date" required value="<?= $v('flight_date') ?>"></div>
      <div class="fw w1"><label for="fn">FLT NO (航次)</label>
        <input type="text" id="fn" name="flight_no" maxlength="30" value="<?= $v('flight_no') ?>" placeholder="GI4217"></div>
      <div class="fw w1"><label for="mw">MAWB NO (总运单号)</label>
        <input type="text" id="mw" name="mawb_no" class="tnum" maxlength="50" value="<?= $v('mawb_no') ?>"></div>
      <div class="fw w1"><label for="op">起运港 (출발지)</label>
        <input type="text" id="op" name="origin_port" maxlength="40" value="<?= $v('origin_port') ?>" placeholder="QINGDAO"></div>
      <div class="fw w1"><label for="dp">抵运地 (도착지)</label>
        <input type="text" id="dp" name="dest_port" maxlength="40" value="<?= $v('dest_port') ?>" placeholder="INCHEON"></div>
    </div>
    <div class="f" style="align-items:flex-end;margin-top:8px">
      <div class="fw w1"><label for="io">进/出口标志</label>
        <select id="io" name="io_flag">
          <?php foreach (HAWB_IO_FLAGS as $k => $lab): ?>
            <option value="<?= $k ?>"<?= (string)($f['io_flag'] ?? 'E') === $k ? ' selected' : '' ?>><?= h($lab) ?></option>
          <?php endforeach; ?></select></div>
      <div class="fw w1"><label for="tm">运输方式</label>
        <input type="text" id="tm" name="transport_mode" maxlength="10" value="<?= $v('transport_mode') ?>"></div>
      <div class="fw w1"><label for="pc">进出口岸代码</label>
        <input type="text" id="pc" name="port_code" maxlength="10" value="<?= $v('port_code') ?>"></div>
      <div class="fw w1"><label for="oc">经营单位代码</label>
        <input type="text" id="oc" name="operator_code" maxlength="40" value="<?= $v('operator_code') ?>"></div>
      <div class="fw w2"><label for="on">经营单位名称</label>
        <input type="text" id="on" name="operator_name" maxlength="150" value="<?= $v('operator_name') ?>"></div>
    </div>
    <div class="fw" style="margin-top:8px"><label for="mm">메모</label>
      <input type="text" id="mm" name="memo" maxlength="500" value="<?= $v('memo') ?>"></div>
    <div style="display:flex;gap:8px;align-items:center;margin-top:12px">
      <button class="btn pri"><?= $cur ? '저장' : '항공편 만들기' ?></button>
      <?php if ($cur): ?>
        <a class="btn" href="?p=hawb_form&amp;batch_id=<?= $id ?>">이 항공편에 송장 추가</a>
        <a class="btn" href="?p=hawb_batches">새 항공편</a>
      <?php endif; ?>
    </div>
  </form>
</div>

<?php if ($cur): ?>
<div class="card">
  <div class="ch">이 항공편의 송장 <span style="font-weight:400;color:var(--ink3)"><?= count($rows) ?>건</span></div>
  <?php if (!$rows): ?>
    <div class="empty">아직 송장이 없습니다. [이 항공편에 송장 추가] 또는
      <a href="?p=hawb_list">HAWB 목록</a>의 엑셀 붙여넣기로 한 번에 넣으세요.</div>
  <?php else: ?>
  <table>
    <thead><tr>
      <th style="width:120px">송장번호</th><th style="width:150px">보내는 분</th><th style="width:150px">받는 분</th>
      <th>내용물</th><th class="r" style="width:60px">PCS</th><th class="r" style="width:80px">중량</th>
      <th class="r" style="width:80px">VALUE</th><th class="c" style="width:70px">품목</th>
      <th class="c" style="width:210px"></th>
    </tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td class="tnum" style="font-weight:700"><a href="?p=hawb_form&amp;id=<?= (int)$r['id'] ?>"><?= h($r['house_no']) ?></a></td>
        <td><?= h((string)$r['shipper_name']) ?></td>
        <td><?= h((string)$r['consignee_name']) ?></td>
        <td style="font-size:11.5px;color:var(--ink2)"><?= h((string)$r['description']) ?></td>
        <td class="r tnum"><?= $r['pieces'] !== null ? (int)$r['pieces'] : '' ?></td>
        <td class="r tnum"><?= h(hawb_num($r['weight'])) ?></td>
        <td class="r tnum"><?= h(hawb_num($r['declared_value'])) ?></td>
        <td class="c"><?= (int)$r['item_cnt'] > 0
            ? '<span class="badge b-ok">' . (int)$r['item_cnt'] . '</span>'
            : '<span class="badge b-warn" title="중문 적하목록 · INVOICE 에 쓸 품목이 없습니다">없음</span>' ?></td>
        <td class="c">
          <a class="btn sm" href="?p=hawb_form&amp;id=<?= (int)$r['id'] ?>">수정</a>
          <a class="btn sm" href="?p=hawb_print&amp;ids=<?= (int)$r['id'] ?>" target="_blank">비엘</a>
          <a class="btn sm" href="?p=hawb_export&amp;type=inv&amp;id=<?= (int)$r['id'] ?>">INVOICE</a>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>
<?php endif; ?>

<div class="card">
  <div class="ch">항공편 목록 <span style="font-weight:400;color:var(--ink3)">최근 60건</span></div>
  <?php if (!$list): ?>
    <div class="empty">아직 항공편이 없습니다. 위에서 만드세요.</div>
  <?php else: ?>
  <table>
    <thead><tr>
      <th style="width:110px">날짜</th><th style="width:110px">FLT NO</th><th style="width:150px">MAWB</th>
      <th style="width:180px">출발 → 도착</th><th class="r" style="width:80px">송장</th>
      <th class="r" style="width:90px">중량</th><th>메모</th><th class="c" style="width:230px"></th>
    </tr></thead>
    <tbody>
    <?php foreach ($list as $b): ?>
      <tr<?= (int)$b['id'] === $id ? ' style="background:#EEF6FA"' : '' ?>>
        <td class="tnum"><a href="?p=hawb_batches&amp;id=<?= (int)$b['id'] ?>"><?= h((string)$b['flight_date']) ?></a></td>
        <td class="tnum"><?= h((string)$b['flight_no']) ?></td>
        <td class="tnum"><?= h((string)$b['mawb_no']) ?></td>
        <td style="font-size:11.5px"><?= h(trim((string)$b['origin_port'] . ' → ' . (string)$b['dest_port'], ' →')) ?></td>
        <td class="r tnum"><?= (int)$b['cnt'] ?>건</td>
        <td class="r tnum"><?= h(hawb_num($b['wt'])) ?></td>
        <td style="font-size:11.5px;color:var(--ink2)"><?= h((string)$b['memo']) ?></td>
        <td class="c">
          <a class="btn sm" href="?p=hawb_batches&amp;id=<?= (int)$b['id'] ?>">열기</a>
          <a class="btn sm" href="?p=hawb_export&amp;type=en&amp;batch=<?= (int)$b['id'] ?>">영문</a>
          <a class="btn sm" href="?p=hawb_export&amp;type=cn&amp;batch=<?= (int)$b['id'] ?>">중문</a>
          <?php if ((int)$b['cnt'] === 0): ?>
            <button type="button" class="btn sm del" data-id="<?= (int)$b['id'] ?>"
                    data-label="<?= h((string)$b['flight_date'] . ' ' . (string)$b['flight_no']) ?>"
                    style="color:#A32020;padding:0 8px" title="삭제">🗑</button>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>

<form method="post" id="delform" style="display:none">
  <?= csrf_field() ?>
  <input type="hidden" name="act" value="delete">
  <input type="hidden" name="id" value=""><input type="hidden" name="label" value="">
  <input type="hidden" name="reason" value="">
</form>
<script>
document.querySelectorAll('.del').forEach(function (b) {
  b.addEventListener('click', function () {
    var why = prompt('항공편 ' + b.dataset.label + ' 을(를) 삭제합니다.\n삭제 사유를 적어 주세요.');
    if (why === null) { return; }
    if (why.trim().length < 2) { alert('삭제 사유를 두 글자 이상 적어 주세요.'); return; }
    var f = document.getElementById('delform');
    f.elements['id'].value = b.dataset.id;
    f.elements['label'].value = b.dataset.label;
    f.elements['reason'].value = why.trim();
    f.submit();
  });
});
</script>
<?php layout_foot();
