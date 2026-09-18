<?php
require APP_DIR . '/layout.php';

$kw     = query('kw');
$status = query('status');
$page   = max(1, (int)query('page', '1'));
$per    = 20;
$off    = ($page - 1) * $per;

// 검색 조건을 배열로 모아 전부 바인딩합니다. 문자열을 이어붙이지 않습니다
$where  = ['deleted_at IS NULL'];
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

layout_head('거래처 관리', 'companies');
?>
<div class="head">
  <h1>거래처 관리</h1>
  <div class="crumb">기준정보 &gt; 거래처 관리</div>
  <div class="right"><a class="btn pri" href="?p=company_form">거래처 등록</a></div>
</div>

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
        <?php foreach (['ACTIVE'=>'거래중','SUSPENDED'=>'거래중지','NEW'=>'신규','CLOSED'=>'종료'] as $k=>$v): ?>
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
      <th class="c" style="width:70px"></th>
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
        <td class="c"><a class="btn sm" href="?p=company_form&amp;id=<?= (int)$r['id'] ?>">수정</a></td>
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
<?php layout_foot();
