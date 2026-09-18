<?php
require_once APP_DIR . '/layout.php';

$eid = entity_id();
$STATUS = ['DRAFT'=>['작성중','b-warn'], 'SENT'=>['발송','b-info'],
           'ACCEPTED'=>['수주','b-ok'], 'REJECTED'=>['실주','b-err'],
           'EXPIRED'=>['만료','b-err']];

$kw  = query('kw');
$sel = query('status');
$page = max(1, (int)query('page', '1'));
$per = 20; $off = ($page - 1) * $per;

$where = ['q.business_entity_id = ?', 'q.deleted_at IS NULL'];
$params = [$eid];
if ($kw !== '') {
    $where[] = '(q.quote_no LIKE ? OR c.name_ko LIKE ? OR q.prospect_name LIKE ?)';
    $like = '%' . $kw . '%';
    array_push($params, $like, $like, $like);
}
if (isset($STATUS[$sel])) { $where[] = 'q.status = ?'; $params[] = $sel; }
$w = implode(' AND ', $where);

$st = db()->prepare("SELECT COUNT(*) FROM quotations q
                     LEFT JOIN companies c ON c.id = q.company_id WHERE $w");
$st->execute($params);
$total = (int)$st->fetchColumn();

$st = db()->prepare(
    "SELECT q.*, c.name_ko, ca.code AS carrier
       FROM quotations q
       LEFT JOIN companies c ON c.id = q.company_id
       LEFT JOIN carriers ca ON ca.id = q.carrier_id
      WHERE $w ORDER BY q.quote_date DESC, q.id DESC LIMIT $per OFFSET $off");
$st->execute($params);
$rows = $st->fetchAll();

$today = date('Y-m-d');
layout_head('견적서 관리', 'quotations');
?>
<div class="head">
  <h1>견적서 관리</h1>
  <div class="crumb">영업관리 &gt; 견적서 관리</div>
  <div class="right"><a class="btn pri" href="?p=quotation_form">견적서 작성</a></div>
</div>

<div class="card"><div class="cb">
  <form class="f" method="get" style="align-items:flex-end">
    <input type="hidden" name="p" value="quotations">
    <div class="fw w3"><label for="kw">검색</label>
      <input type="text" id="kw" name="kw" value="<?= h($kw) ?>" placeholder="견적번호 · 업체명"></div>
    <div class="fw w1"><label for="st">상태</label>
      <select id="st" name="status">
        <option value="">전체</option>
        <?php foreach ($STATUS as $k=>$v): ?>
          <option value="<?= h($k) ?>"<?= $sel===$k?' selected':'' ?>><?= h($v[0]) ?></option>
        <?php endforeach; ?>
      </select></div>
    <button class="btn">검색</button>
    <a class="btn" href="?p=quotations">초기화</a>
  </form>
</div></div>

<div class="card">
  <?php if (!$rows): ?>
    <div class="empty">견적서가 없습니다.</div>
  <?php else: ?>
  <table>
    <thead><tr>
      <th style="width:160px">견적번호</th><th style="width:100px">견적일</th>
      <th>거래처</th><th class="c" style="width:70px">운송사</th>
      <th style="width:100px">유효기한</th>
      <th class="r" style="width:130px">합계</th><th class="c" style="width:80px">상태</th>
      <th class="c" style="width:110px"></th>
    </tr></thead>
    <tbody>
    <?php foreach ($rows as $r):
      [$lab,$cls] = $STATUS[$r['status']] ?? [$r['status'],'b-info'];
      $expired = $r['valid_until'] && $r['valid_until'] < $today
                 && in_array($r['status'], ['DRAFT','SENT'], true); ?>
      <tr>
        <td class="tnum" style="font-weight:600"><?= h($r['quote_no']) ?></td>
        <td class="tnum"><?= h($r['quote_date']) ?></td>
        <td><?= h($r['name_ko'] ?: $r['prospect_name'] ?: '-') ?>
          <?php if (!$r['company_id']): ?>
            <span class="badge b-warn" style="margin-left:4px">미등록</span>
          <?php endif; ?></td>
        <td class="c"><?= h($r['carrier'] ?: '-') ?></td>
        <td class="tnum" style="color:<?= $expired?'var(--err-fg)':'var(--ink)' ?>">
          <?= h($r['valid_until'] ?: '-') ?>
          <?php if ($expired): ?><span style="font-size:11px">지남</span><?php endif; ?></td>
        <td class="r tnum" style="font-weight:700"><?= money($r['grand_total']) ?></td>
        <td class="c"><span class="badge <?= $cls ?>"><?= h($lab) ?></span></td>
        <td class="c">
          <a class="btn sm" href="?p=quotation_form&amp;id=<?= (int)$r['id'] ?>">열기</a>
          <?php if ($r['converted_shipment_id']): ?>
            <a class="btn sm" href="?p=shipment_form&amp;id=<?= (int)$r['converted_shipment_id'] ?>">전표</a>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <div class="pager">
    <span>전체 <b class="tnum"><?= money($total) ?></b> 건 ·
      <span class="tnum"><?= money($off+1) ?>–<?= money(min($off+$per,$total)) ?></span></span>
    <div class="right">
      <?php $qs='p=quotations&kw='.urlencode($kw).'&status='.urlencode($sel); ?>
      <?php if ($page>1): ?><a class="btn sm" href="?<?= h($qs) ?>&amp;page=<?= $page-1 ?>">이전</a><?php endif; ?>
      <?php if ($off+$per<$total): ?><a class="btn sm" href="?<?= h($qs) ?>&amp;page=<?= $page+1 ?>">다음</a><?php endif; ?>
    </div>
  </div>
  <?php endif; ?>
</div>
<?php layout_foot();
