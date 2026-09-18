<?php
require_once APP_DIR . '/layout.php';

$eid = entity_id();
$kw  = query('kw');
$from = query('from');
$to   = query('to');
$page = max(1, (int)query('page', '1'));
$per = 25; $off = ($page - 1) * $per;

$where = ['s.business_entity_id = ?', 's.deleted_at IS NULL'];
$params = [$eid];
if ($kw !== '') {
    $where[] = '(s.awb_no LIKE ? OR s.mawb_no LIKE ? OR c.name_ko LIKE ?
                 OR EXISTS (SELECT 1 FROM tracking_numbers t
                             WHERE t.shipment_id = s.id AND t.tracking_no LIKE ?))';
    $like = '%' . $kw . '%';
    array_push($params, $like, $like, $like, $like);
}
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) { $where[] = 's.voucher_date >= ?'; $params[] = $from; }
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $to))   { $where[] = 's.voucher_date <= ?'; $params[] = $to; }
$w = implode(' AND ', $where);

$st = db()->prepare("SELECT COUNT(*) FROM shipments s
                     JOIN companies c ON c.id = s.company_id WHERE $w");
$st->execute($params);
$total = (int)$st->fetchColumn();

$st = db()->prepare(
    "SELECT s.id, s.awb_no, s.mawb_no, s.awb_source, s.voucher_date, s.ship_date,
            s.trade_type, s.dest_city, s.charge_weight, s.package_count, s.status,
            c.name_ko, ca.code AS carrier,
            (SELECT COUNT(*) FROM tracking_numbers t WHERE t.shipment_id = s.id) AS trk_cnt,
            (SELECT t.current_status FROM tracking_numbers t
              WHERE t.shipment_id = s.id ORDER BY t.id DESC LIMIT 1) AS trk_status,
            (SELECT COUNT(*) FROM documents d
              WHERE d.shipment_id = s.id AND d.deleted_at IS NULL) AS doc_cnt
       FROM shipments s
       JOIN companies c ON c.id = s.company_id
       JOIN carriers ca ON ca.id = s.carrier_id
      WHERE $w ORDER BY s.voucher_date DESC, s.id DESC LIMIT $per OFFSET $off");
$st->execute($params);
$rows = $st->fetchAll();

layout_head('AWB 관리', 'awb_list');
?>
<div class="head">
  <h1>AWB 관리</h1>
  <div class="crumb">물류관리 &gt; AWB 관리</div>
  <div class="right"><a class="btn" href="?p=shipment_form">전표 등록</a></div>
</div>

<div class="card"><div class="cb">
  <form class="f" method="get" style="align-items:flex-end">
    <input type="hidden" name="p" value="awb_list">
    <div class="fw w3"><label for="kw">검색</label>
      <input type="text" id="kw" name="kw" value="<?= h($kw) ?>"
             placeholder="AWB · MAWB · 추적번호 · 거래처명"></div>
    <div class="fw w1"><label for="f">전표일 시작</label>
      <input type="date" id="f" name="from" value="<?= h($from) ?>"></div>
    <div class="fw w1"><label for="t">전표일 종료</label>
      <input type="date" id="t" name="to" value="<?= h($to) ?>"></div>
    <button class="btn">검색</button>
    <a class="btn" href="?p=awb_list">초기화</a>
  </form>
</div></div>

<div class="card">
  <?php if (!$rows): ?>
    <div class="empty">AWB 가 없습니다.</div>
  <?php else: ?>
  <table>
    <thead><tr>
      <th style="width:100px">전표일</th><th style="width:160px">AWB</th>
      <th style="width:130px">MAWB</th><th>거래처</th>
      <th class="c" style="width:65px">운송사</th><th class="c" style="width:55px">구분</th>
      <th style="width:110px">도착지</th>
      <th class="r" style="width:65px">중량</th><th class="r" style="width:50px">PCS</th>
      <th style="width:130px">추적</th><th class="c" style="width:55px">문서</th>
      <th class="c" style="width:120px"></th>
    </tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr<?= $r['status']==='CANCELLED' ? ' style="opacity:.5"' : '' ?>>
        <td class="tnum"><?= h($r['voucher_date']) ?></td>
        <td class="tnum" style="font-weight:600">
          <a href="?p=shipment_form&amp;id=<?= (int)$r['id'] ?>"><?= h($r['awb_no']) ?></a>
          <?php if ($r['awb_source'] === 'CARRIER'): ?>
            <span class="badge b-info" style="margin-left:3px">운송사</span>
          <?php elseif ($r['awb_source'] === 'MANUAL'): ?>
            <span class="badge b-warn" style="margin-left:3px">수동</span>
          <?php endif; ?></td>
        <td class="tnum" style="font-size:11.5px"><?= h($r['mawb_no'] ?: '-') ?></td>
        <td><?= h($r['name_ko']) ?></td>
        <td class="c"><?= h($r['carrier']) ?></td>
        <td class="c"><?= $r['trade_type']==='IMPORT'?'수입':'수출' ?></td>
        <td><?= h($r['dest_city'] ?: '-') ?></td>
        <td class="r tnum"><?= $r['charge_weight'] !== null
            ? h(rtrim(rtrim(number_format((float)$r['charge_weight'],1),'0'),'.')) : '-' ?></td>
        <td class="r tnum"><?= $r['package_count'] !== null ? (int)$r['package_count'] : '-' ?></td>
        <td style="font-size:11.5px">
          <?php if ($r['trk_cnt']): ?>
            <span style="color:var(--ink2)"><?= h($r['trk_status'] ?: '등록됨') ?></span>
            <?php if ($r['trk_cnt'] > 1): ?>
              <span class="badge b-info"><?= (int)$r['trk_cnt'] ?></span>
            <?php endif; ?>
          <?php else: ?>
            <a href="?p=tracking&amp;shipment_id=<?= (int)$r['id'] ?>">번호 등록</a>
          <?php endif; ?></td>
        <td class="c"><?= $r['doc_cnt']
            ? '<a href="?p=documents&amp;shipment_id=' . (int)$r['id'] . '">' . (int)$r['doc_cnt'] . '</a>'
            : '<a href="?p=documents&amp;shipment_id=' . (int)$r['id'] . '" style="color:var(--ink3)">+</a>' ?></td>
        <td class="c">
          <a class="btn sm" href="?p=awb_label&amp;id=<?= (int)$r['id'] ?>" target="_blank">라벨</a>
          <a class="btn sm" href="?p=tracking&amp;shipment_id=<?= (int)$r['id'] ?>">추적</a>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <div class="pager">
    <span>전체 <b class="tnum"><?= money($total) ?></b> 건 ·
      <span class="tnum"><?= money($off+1) ?>–<?= money(min($off+$per,$total)) ?></span></span>
    <div class="right">
      <?php $qs='p=awb_list&kw='.urlencode($kw).'&from='.urlencode($from).'&to='.urlencode($to); ?>
      <?php if ($page>1): ?><a class="btn sm" href="?<?= h($qs) ?>&amp;page=<?= $page-1 ?>">이전</a><?php endif; ?>
      <?php if ($off+$per<$total): ?><a class="btn sm" href="?<?= h($qs) ?>&amp;page=<?= $page+1 ?>">다음</a><?php endif; ?>
    </div>
  </div>
  <?php endif; ?>
</div>
<?php layout_foot();
