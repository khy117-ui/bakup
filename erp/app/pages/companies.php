<?php
require_once APP_DIR . '/layout.php';

$kw     = query('kw');
$status = query('status');
$page   = max(1, (int)query('page', '1'));
$per    = 20;
$off    = ($page - 1) * $per;
$err    = '';

// ---------------------------------------------------------------- 삭제 · 복구
// 삭제는 목록 · 선택칸에서 숨기는 것(deleted_at)입니다. 지난 전표 · 입금 · 이력은 그대로 남고, 복구할 수 있습니다.
// 삭제·취소 바로 실행 권한이 없으면 index.php 가 삭제 요청으로 바꿔 관리자 승인을 받습니다
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array(post('act'), ['delete', 'restore'], true)) {
    csrf_check();
    $cid = (int)post('id');
    $st = db()->prepare('SELECT id, company_code, name_ko, deleted_at FROM companies WHERE id = ?');
    $st->execute([$cid]);
    $co = $st->fetch();
    $back = '?' . http_build_query(['p' => 'companies', 'kw' => $kw, 'status' => $status, 'page' => $page]);
    if (!$co) {
        $err = '거래처를 찾을 수 없습니다.';
    } elseif ($co['company_code'] === 'UNMATCHED') {
        $err = '(거래처 미확인)은 이관에 쓰는 자리라 지울 수 없습니다.';
    } elseif (post('act') === 'delete') {
        $why = trim(post('reason'));
        if (mb_strlen($why) < 2) {
            $err = '삭제 사유를 적어 주세요.';
        } else {
            db()->prepare('UPDATE companies SET deleted_at = NOW() WHERE id = ? AND deleted_at IS NULL')->execute([$cid]);
            log_action('거래처', 'DELETE', 'companies', $cid, $co['name_ko'] . ' (' . $co['company_code'] . ')',
                       null, '삭제 (목록에서 숨김, 복구 가능)', $why);
            flash($co['name_ko'] . ' 을 삭제했습니다. 지난 전표는 그대로이고, "삭제된 거래처" 에서 복구할 수 있습니다.');
            redirect($back);
        }
    } else {
        db()->prepare('UPDATE companies SET deleted_at = NULL WHERE id = ?')->execute([$cid]);
        log_action('거래처', 'UPDATE', 'companies', $cid, $co['name_ko'] . ' (' . $co['company_code'] . ')',
                   null, '삭제 취소 (복구)');
        flash($co['name_ko'] . ' 을 복구했습니다.');
        redirect('?p=companies&kw=' . urlencode((string)$co['company_code']));
    }
}

// 검색 조건을 배열로 모아 전부 바인딩합니다. 문자열을 이어붙이지 않습니다
$where  = [$status === 'DELETED' ? 'deleted_at IS NOT NULL' : 'deleted_at IS NULL'];
$params = [];
if ($kw !== '') {
    $where[] = '(name_ko LIKE ? OR company_code LIKE ? OR business_number LIKE ?'
             . ' OR name_en LIKE ?)';
    $like = '%' . $kw . '%';
    array_push($params, $like, $like, $like, $like);
}
if (in_array($status, ['ACTIVE', 'SUSPENDED', 'NEW', 'CLOSED'], true)) {
    $where[] = 'trade_status = ?';
    $params[] = $status;
}
$w = implode(' AND ', $where);

$st = db()->prepare("SELECT COUNT(*) FROM companies WHERE $w");
$st->execute($params);
$total = (int)$st->fetchColumn();

$st = db()->prepare(
    "SELECT id, company_code, name_ko, representative, business_number, phone,
            sales_team, trade_status, joined_on
       FROM companies WHERE $w
      ORDER BY name_ko ASC
      LIMIT $per OFFSET $off");
$st->execute($params);
$rows = $st->fetchAll();

// 이 쪽 거래처들의 전표 수 — 삭제할 때 확인 문구에 씁니다
$shipCnt = [];
if ($rows) {
    $ids = array_map(fn($r) => (int)$r['id'], $rows);
    $st = db()->prepare('SELECT company_id, COUNT(*) FROM shipments
                          WHERE deleted_at IS NULL AND company_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')
                          GROUP BY company_id');
    $st->execute($ids);
    $shipCnt = $st->fetchAll(PDO::FETCH_KEY_PAIR);
}
$canEdit = route_can_edit('companies');

layout_head('거래처 관리', 'companies');
?>
<div class="head">
  <h1>거래처 관리</h1>
  <div class="crumb">기준정보 &gt; 거래처 관리</div>
  <div class="right"><a class="btn pri" href="?p=company_form">거래처 등록</a></div>
</div>

<?php if ($err !== ''): ?><div class="msg err"><?= h($err) ?></div><?php endif; ?>

<div class="card"><div class="cb">
  <form class="f" method="get" style="align-items:flex-end">
    <input type="hidden" name="p" value="companies">
    <div class="fw w3">
      <label for="kw">검색</label>
      <input type="text" id="kw" name="kw" value="<?= h($kw) ?>"
             placeholder="업체명 · 코드 · 사업자번호">
    </div>
    <div class="fw w1">
      <label for="status">거래상태</label>
      <select id="status" name="status">
        <option value="">전체</option>
        <?php foreach (['ACTIVE'=>'거래중','SUSPENDED'=>'거래중지','NEW'=>'신규','CLOSED'=>'종료','DELETED'=>'삭제된 거래처'] as $k=>$v): ?>
          <option value="<?= h($k) ?>"<?= $status===$k?' selected':'' ?>><?= h($v) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <button class="btn">검색</button>
    <a class="btn" href="?p=companies">초기화</a>
  </form>
</div></div>

<div class="card">
  <?php if (!$rows): ?>
    <div class="empty"><?= $kw !== '' || $status !== ''
      ? '조건에 맞는 거래처가 없습니다.'
      : '등록된 거래처가 없습니다.' ?></div>
  <?php else: ?>
  <table>
    <thead><tr>
      <th style="width:110px">코드</th><th>업체명</th><th style="width:90px">대표자</th>
      <th style="width:130px">사업자번호</th><th style="width:130px">전화</th>
      <th style="width:90px">영업팀</th><th class="c" style="width:90px">상태</th>
      <th class="c" style="width:130px"></th>
    </tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td class="tnum"><?= h($r['company_code']) ?></td>
        <td style="font-weight:600"><?= h($r['name_ko']) ?></td>
        <td><?= h($r['representative']) ?></td>
        <td class="tnum"><?= h($r['business_number']) ?></td>
        <td class="tnum"><?= h($r['phone']) ?></td>
        <td><?= h($r['sales_team']) ?></td>
        <td class="c"><?php
          $map = ['ACTIVE'=>['거래중','b-ok'],'SUSPENDED'=>['거래중지','b-warn'],
                  'NEW'=>['신규','b-info'],'CLOSED'=>['종료','b-err']];
          [$lab,$cls] = $map[$r['trade_status']] ?? [$r['trade_status'],'b-info'];
          echo '<span class="badge '.$cls.'">'.h($lab).'</span>';
        ?></td>
        <td class="c">
          <?php if ($status === 'DELETED'): ?>
            <?php if ($canEdit): ?>
            <form method="post" style="display:inline" onsubmit="return confirm('이 거래처를 복구할까요?');">
              <?= csrf_field() ?><input type="hidden" name="act" value="restore">
              <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
              <button class="btn sm">복구</button>
            </form>
            <?php endif; ?>
          <?php else: ?>
            <a class="btn sm" href="?p=company_form&amp;id=<?= (int)$r['id'] ?>">수정</a>
            <?php if ($canEdit && $r['company_code'] !== 'UNMATCHED'): $n = (int)($shipCnt[$r['id']] ?? 0); ?>
            <form method="post" style="display:inline" class="co-del"
                  data-name="<?= h($r['name_ko']) ?>" data-ships="<?= $n ?>">
              <?= csrf_field() ?><input type="hidden" name="act" value="delete">
              <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
              <input type="hidden" name="reason" value="">
              <button class="btn sm" style="color:var(--err-fg)">삭제</button>
            </form>
            <?php endif; ?>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <div class="pager">
    <span>전체 <b class="tnum"><?= money($total) ?></b> 곳 ·
      <span class="tnum"><?= money($off+1) ?>–<?= money(min($off+$per,$total)) ?></span></span>
    <div class="right">
      <?php $qs = 'p=companies&kw='.urlencode($kw).'&status='.urlencode($status); ?>
      <?php if ($page > 1): ?>
        <a class="btn sm" href="?<?= h($qs) ?>&amp;page=<?= $page-1 ?>">이전</a><?php endif; ?>
      <?php if ($off + $per < $total): ?>
        <a class="btn sm" href="?<?= h($qs) ?>&amp;page=<?= $page+1 ?>">다음</a><?php endif; ?>
    </div>
  </div>
  <?php endif; ?>
</div>
<script>
// 삭제 — 사유를 받고, 전표가 있는 거래처는 한 번 더 알려 줍니다
document.querySelectorAll('form.co-del').forEach(function (f) {
  f.addEventListener('submit', function (e) {
    var n = Number(f.getAttribute('data-ships'));
    var msg = f.getAttribute('data-name') + ' 을(를) 삭제합니다.\n'
            + (n > 0 ? '이 거래처의 전표 ' + n.toLocaleString('ko-KR') + '건은 그대로 남고, 거래처 목록 · 선택칸에서만 빠집니다.\n' : '')
            + '"삭제된 거래처" 에서 복구할 수 있습니다.\n\n삭제 사유를 적어 주세요:';
    var why = prompt(msg, '');
    if (why === null || why.trim().length < 2) { e.preventDefault(); if (why !== null) alert('사유를 2자 이상 적어 주세요.'); return; }
    f.querySelector('input[name=reason]').value = why.trim();
  });
});
</script>
<?php layout_foot();
