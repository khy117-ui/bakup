<?php
// 웹에서 직접 열면 실행되지 않게 막습니다 (nginx 면 .htaccess 가 무시됩니다)
if (!defined('APP_DIR')) { http_response_code(403); exit('Forbidden'); }

require_once APP_DIR . '/layout.php';
require_once APP_DIR . '/shop_biz.php';

/**
 * 쇼핑몰 주문 · 매출 — 쇼핑몰 통합관리 프로그램(commerce-hub)이 모은 쿠팡 · 스마트스토어 · 카페24 주문.
 *   commerce-hub serve 서버에서 주문을 가져와 쌓고, 기간별로 판매처 · 날짜 · 상품 매출을 봅니다.
 *   판매 · 마케팅 리포트(채널별 ROAS · 상품 TOP 20 · 할 일 추천)는 서버에서 그때그때 불러옵니다.
 *   목록은 CSV 로 내려받을 수 있습니다.
 */
$err = '';
shop_biz_ensure_schema();
$cfg = shop_api_cfg();
$hasApi = $cfg['shop_api_url'] !== '' && $cfg['shop_api_token'] !== '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $act = post('act');
    try {
        if ($act === 'fetch') {
            $days = max(1, min((int)post('days', '1'), 90));
            $rows = shop_api_fetch($days);
            [$new, $chg] = shop_upsert($rows, 'API');
            $gone = shop_sync_channel($rows, 'cafe24', $days);
            log_action('쇼핑몰', 'INSERT', 'shop_orders', null, "서버에서 가져오기 최근 {$days}일", null, "새 {$new} · 바뀜 {$chg} · 카페24 정리 {$gone}");
            flash("최근 {$days}일 주문 " . count($rows) . "줄을 가져왔습니다. 새 주문 {$new}줄 · 바뀐 주문 {$chg}줄."
                . ($gone ? " 카페24에 같이 들어온 다른 마켓 주문 {$gone}줄은 정리했습니다." : ''));
        }
        redirect('?p=shop_orders');
    } catch (RuntimeException $e) {
        $err = $e->getMessage();
    } catch (PDOException $e) {
        error_log('쇼핑몰 주문 저장 실패: ' . $e->getMessage());
        $err = '저장하지 못했습니다.';
    }
}

// 조회 조건
$today = date('Y-m-d');
$from = query('from', date('Y-m-d', strtotime('-6 days')));
$to   = query('to', $today);
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) { $from = date('Y-m-d', strtotime('-6 days')); }
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) { $to = $today; }
if ($from > $to) { [$from, $to] = [$to, $from]; }
$ch = array_key_exists(query('ch'), SHOP_CHANNELS) ? query('ch') : '';
$kw = trim(query('kw'));

$w = ['ordered_at >= ?', 'ordered_at < DATE_ADD(?, INTERVAL 1 DAY)'];
$pa = [$from, $to];
if ($ch !== '') { $w[] = 'channel = ?'; $pa[] = $ch; }
if ($kw !== '') { $w[] = '(product LIKE ? OR order_id LIKE ?)'; array_push($pa, "%$kw%", "%$kw%"); }
$where = implode(' AND ', $w);
$pdo = db();

// 취소 · 반품은 매출에서 뺍니다
$cancelSql = shop_cancel_sql();

$byCh = [];
$st = $pdo->prepare("SELECT channel, COUNT(DISTINCT order_id) AS orders, SUM(qty) AS qty,
                            SUM(CASE WHEN $cancelSql THEN 0 ELSE amount END) AS sales,
                            SUM(CASE WHEN $cancelSql THEN amount ELSE 0 END) AS cancelled
                       FROM shop_orders WHERE $where GROUP BY channel");
$st->execute($pa);
foreach ($st->fetchAll() as $r) { $byCh[$r['channel']] = $r; }
$tot = ['orders' => 0, 'qty' => 0, 'sales' => 0, 'cancelled' => 0];
foreach ($byCh as $r) { foreach ($tot as $k => $_) { $tot[$k] += (float)$r[$k]; } }

// 지금 발송할 주문 — 주문일과 상관없이 판매처 상태로 셉니다 (쿠팡 Wing · 스마트스토어 발송관리 화면과 같은 기준)
//   발송 전: 결제완료 · 상품준비중 (카페24 배송준비 포함) / 송장 넣음 · 집하 전: 쿠팡 배송지시
$toShip = [];
$shipSt = SHOP_TO_SHIP_STATUS;
$in = implode(',', array_fill(0, count($shipSt), '?'));
$st = $pdo->prepare("SELECT channel, status, COUNT(DISTINCT order_id) AS n FROM shop_orders
                      WHERE ordered_at >= ? AND status IN ($in) AND NOT $cancelSql
                      GROUP BY channel, status");
$st->execute(array_merge([date('Y-m-d 00:00:00', strtotime('-30 days'))], $shipSt));
foreach ($st->fetchAll() as $r) {
    $toShip[$r['channel']]['n'] = ($toShip[$r['channel']]['n'] ?? 0) + (int)$r['n'];
    $toShip[$r['channel']]['by'][shop_status_label($r['channel'], $r['status'])] = (int)$r['n'];
}

$st = $pdo->prepare("SELECT DATE(ordered_at) AS d, channel, SUM(CASE WHEN $cancelSql THEN 0 ELSE amount END) AS sales
                       FROM shop_orders WHERE $where GROUP BY DATE(ordered_at), channel ORDER BY d DESC");
$st->execute($pa);
$daily = [];
foreach ($st->fetchAll() as $r) { $daily[$r['d']][$r['channel']] = (float)$r['sales']; }

$st = $pdo->prepare("SELECT product, GROUP_CONCAT(DISTINCT channel) AS chs, SUM(qty) AS qty,
                            SUM(CASE WHEN $cancelSql THEN 0 ELSE amount END) AS sales
                       FROM shop_orders WHERE $where GROUP BY product ORDER BY sales DESC LIMIT 20");
$st->execute($pa);
$top = $st->fetchAll();

// CSV 내려받기 — commerce-hub export 와 같은 열
if (query('download') === '1') {
    $st = $pdo->prepare("SELECT channel, order_id, ordered_at, product, qty, amount, status
                           FROM shop_orders WHERE $where ORDER BY ordered_at DESC, id DESC");
    $st->execute($pa);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="shop-orders-' . $from . '_' . $to . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['판매처', '주문번호', '주문일시', '상품명', '수량', '금액', '상태'], ',', '"', '');
    while ($r = $st->fetch()) {
        fputcsv($out, [shop_channel_label($r['channel']), $r['order_id'], $r['ordered_at'], $r['product'],
                       $r['qty'], $r['amount'], shop_status_label((string)$r['channel'], $r['status'])], ',', '"', '');
    }
    fclose($out);
    exit;
}

$st = $pdo->prepare("SELECT * FROM shop_orders WHERE $where ORDER BY ordered_at DESC, id DESC LIMIT 300");
$st->execute($pa);
$rows = $st->fetchAll();
// 판매 · 마케팅 리포트 — [리포트 불러오기] 를 눌렀을 때만 서버를 부릅니다 (주문 수집에 시간이 걸림)
$repDays = in_array((int)query('report'), [7, 14, 30], true) ? (int)query('report') : 0;
$rep = null;
$repErr = '';
if ($repDays > 0) {
    try {
        $rep = shop_api_report($repDays);
    } catch (RuntimeException $e) {
        $repErr = $e->getMessage();
    }
}
$pct = fn($v) => $v === null ? '-' : '<span style="color:' . ($v >= 0 ? '#1B7F5A' : '#C62828') . '">' . ($v >= 0 ? '+' : '') . (int)$v . '%</span>';

$last = $pdo->query('SELECT MAX(updated_at) FROM shop_orders')->fetchColumn();
$autoOn = shop_setting('shop_auto_fetch', '예') === '예';
$autoRes = shop_state_get('last_fetch_result');
$alertRes = shop_state_get('last_alert_result');
$alertNow = shop_alert_lines();
$canEdit = route_can_edit('shop_orders');
$qs = http_build_query(['p' => 'shop_orders', 'from' => $from, 'to' => $to, 'ch' => $ch, 'kw' => $kw]);

layout_head('쇼핑몰 주문 · 매출', 'shop_orders');
?>
<div class="head">
  <h1>쇼핑몰 주문 · 매출</h1>
  <div class="crumb">쇼핑몰관리 &gt; 쿠팡 · 스마트스토어 · 카페24 주문 (쇼핑몰 통합관리 프로그램 연동)</div>
</div>
<?php if ($err !== ''): ?><div class="msg err"><?= h($err) ?></div><?php endif; ?>

<div class="card"><div class="cb">
  <form class="f" method="get" style="align-items:flex-end">
    <input type="hidden" name="p" value="shop_orders">
    <div class="fw w1"><label for="f">시작일</label><input type="date" id="f" name="from" value="<?= h($from) ?>"></div>
    <div class="fw w1"><label for="t">종료일</label><input type="date" id="t" name="to" value="<?= h($to) ?>"></div>
    <div class="fw w1"><label for="c">판매처</label>
      <select id="c" name="ch"><option value="">전체</option>
        <?php foreach (SHOP_CHANNELS as $k => $lab): ?>
          <option value="<?= $k ?>"<?= $ch === $k ? ' selected' : '' ?>><?= h($lab) ?></option>
        <?php endforeach; ?></select></div>
    <div class="fw w2"><label for="k">상품명 · 주문번호</label><input type="text" id="k" name="kw" value="<?= h($kw) ?>"></div>
    <button class="btn pri">조회</button>
    <?php foreach (['오늘' => 0, '7일' => 6, '30일' => 29, '90일' => 89] as $lab => $n): ?>
      <a class="btn sm" href="?p=shop_orders&amp;from=<?= date('Y-m-d', strtotime("-{$n} days")) ?>&amp;to=<?= $today ?>"><?= $lab ?></a>
    <?php endforeach; ?>
  </form>
</div></div>

<?php if ($alertNow): ?>
<div class="msg err"><b>확인할 일</b><br><?= implode('<br>', array_map('h', $alertNow)) ?></div>
<?php endif; ?>

<div class="kpis">
  <div class="kpi"><div class="lab">매출 합계</div>
    <div class="val tnum"><?= money($tot['sales']) ?></div>
    <div class="sub">주문 <?= money($tot['orders']) ?>건 · 취소/반품 <?= money($tot['cancelled']) ?>원 제외</div></div>
  <?php foreach (SHOP_CHANNELS as $k => $lab): $r = $byCh[$k] ?? null; ?>
  <div class="kpi"><div class="lab"><?= h($lab) ?></div>
    <div class="val tnum"><?= money($r['sales'] ?? 0) ?></div>
    <div class="sub">주문 <?= money($r['orders'] ?? 0) ?>건 · 수량 <?= money($r['qty'] ?? 0) ?>
      <?= $tot['sales'] > 0 ? ' · ' . round(100 * (float)($r['sales'] ?? 0) / $tot['sales']) . '%' : '' ?></div></div>
  <?php endforeach; ?>
</div>

<div class="card">
  <div class="ch">지금 발송할 주문 <span style="font-weight:400;color:var(--ink3);font-size:12px">주문일과 상관없이 판매처 상태 기준 (최근 30일 주문) · 마지막 가져오기 때의 상태</span></div>
  <div class="cb" style="display:flex;gap:28px;flex-wrap:wrap;font-size:13px">
    <?php foreach (SHOP_CHANNELS as $k => $lab): $x = $toShip[$k] ?? ['n' => 0, 'by' => []]; ?>
    <div><b><?= h($lab) ?></b> <span class="tnum" style="font-size:18px;font-weight:700;margin-left:4px"><?= (int)$x['n'] ?></span>건
      <?php if ($x['by']): ?><span style="color:var(--ink3)">(<?= h(implode(' · ', array_map(fn($l, $n) => "$l $n", array_keys($x['by']), $x['by']))) ?>)</span><?php endif; ?></div>
    <?php endforeach; ?>
  </div>
</div>

<div class="card">
  <div class="ch">주문 가져오기
    <span style="font-weight:400;color:var(--ink3);font-size:12px">마지막 반영 <?= h($last ?: '없음') ?></span></div>
  <?php if ($canEdit): ?>
  <div class="cb" style="display:flex;gap:18px;flex-wrap:wrap;align-items:flex-end">
    <form method="post" class="f" style="align-items:flex-end">
      <?= csrf_field() ?><input type="hidden" name="act" value="fetch">
      <div class="fw w2"><label for="d">쇼핑몰 서버에서 주문 가져오기</label>
        <select id="d" name="days"<?= $hasApi ? '' : ' disabled' ?>>
          <?php foreach ([1 => '최근 1일', 3 => '최근 3일', 7 => '최근 7일', 30 => '최근 30일'] as $n => $lab): ?>
            <option value="<?= $n ?>"><?= $lab ?></option>
          <?php endforeach; ?></select></div>
      <button class="btn"<?= $hasApi ? '' : ' disabled title="환경설정 → 연동 에 서버 주소 · 토큰을 넣으면 쓸 수 있습니다"' ?>>지금 가져오기</button>
    </form>
  </div>
  <?php endif; ?>
  <div class="cb" style="border-top:1px solid var(--line2);font-size:12px;color:var(--ink2);line-height:1.8">
    같은 주문을 다시 가져와도 두 번 쌓이지 않고 수량 · 금액 · 상태만 새 값으로 바뀝니다.<br>
    쇼핑몰 통합관리 프로그램에서 <code>node src/cli.js serve</code> 를 띄운 뒤
    <?= route_can_edit('settings') ? '<a href="?p=settings">환경설정 → 연동</a>' : '환경설정 → 연동' ?> 에 서버 주소와 토큰을 넣으면 켜집니다.
    서버 연결: <?= $hasApi ? '<span class="badge b-ok">설정됨</span>' : '<span class="badge b-warn">없음</span>' ?><br>
    자동 가져오기: <?= $autoOn && $hasApi ? '<span class="badge b-ok">1시간마다</span>' : '<span class="badge b-warn">꺼짐</span>' ?>
    (ERP 화면이 하나라도 열려 있을 때 돕니다) · 마지막 <?= h($autoRes ?? '-') ?>
    · 알림(하루 한 번 오전 9시 이후): 마지막 <?= h($alertRes ?? '-') ?>
  </div>
</div>

<div class="card">
  <div class="ch">판매 · 마케팅 리포트
    <span style="font-weight:400;color:var(--ink3);font-size:12px">이번 기간 vs 직전 기간 · 광고비는 쇼핑몰 서버가 네이버 · 메타에서 모음</span>
    <span style="margin-left:auto;display:flex;gap:6px">
    <?php foreach ([7, 14, 30] as $n): ?>
      <a class="btn sm<?= $repDays === $n ? ' pri' : '' ?>" href="?<?= h($qs) ?>&amp;report=<?= $n ?>">최근 <?= $n ?>일 불러오기</a>
    <?php endforeach; ?></span></div>
  <?php if ($repErr !== ''): ?>
    <div class="cb"><div class="msg err" style="margin:0"><?= h($repErr) ?></div></div>
  <?php elseif (!$rep): ?>
    <div class="empty"><?= $hasApi ? '위 버튼을 누르면 쇼핑몰 서버에서 리포트를 만들어 옵니다. 주문이 많으면 조금 걸립니다.' : '환경설정 → 연동 에 쇼핑몰 서버 주소와 토큰을 넣으면 쓸 수 있습니다.' ?></div>
  <?php else: $t = $rep['total'] ?? []; ?>
    <div class="cb kpis" style="padding-top:12px">
      <div class="kpi"><div class="lab">총매출 (최근 <?= $repDays ?>일)</div><div class="val tnum"><?= money($t['매출'] ?? 0) ?></div><div class="sub">직전 대비 <?= $pct($t['전기대비'] ?? null) ?></div></div>
      <div class="kpi"><div class="lab">주문</div><div class="val tnum"><?= money($t['주문'] ?? 0) ?></div></div>
      <div class="kpi"><div class="lab">광고비</div><div class="val tnum"><?= money($t['광고비'] ?? 0) ?></div></div>
      <div class="kpi"><div class="lab">통합 ROAS</div><div class="val tnum"><?= isset($t['ROAS']) ? (int)$t['ROAS'] . '%' : '-' ?></div></div>
    </div>
    <div class="cb" style="border-top:1px solid var(--line2)">
      <div style="font-weight:700;font-size:13px;margin-bottom:6px">할 일 (자동 추천)</div>
      <?php if (empty($rep['tips'])): ?><div style="font-size:12.5px;color:var(--ink3)">특이사항 없음</div><?php else: ?>
      <ul style="margin:0;padding-left:18px;font-size:12.5px;line-height:1.8">
        <?php foreach ($rep['tips'] as $tip): ?><li><?= h((string)$tip) ?></li><?php endforeach; ?></ul>
      <?php endif; ?>
    </div>
    <table>
      <thead><tr><th>채널</th><th class="r">매출</th><th class="r">주문</th><th class="r">객단가</th><th class="r">직전 대비</th>
        <th class="r">광고비</th><th class="r">ROAS</th><th class="r">매출 비중</th><th class="r">광고비 비중</th></tr></thead>
      <tbody>
      <?php foreach ($rep['channels'] ?? [] as $c): ?>
        <tr><td style="font-weight:600"><?= h((string)($c['채널'] ?? '')) ?></td>
          <td class="r tnum"><?= money($c['매출'] ?? 0) ?></td><td class="r tnum"><?= money($c['주문수'] ?? 0) ?></td>
          <td class="r tnum"><?= money($c['객단가'] ?? 0) ?></td><td class="r tnum"><?= $pct($c['전기대비'] ?? null) ?></td>
          <td class="r tnum"><?= money($c['광고비'] ?? 0) ?></td><td class="r tnum"><?= isset($c['ROAS']) ? (int)$c['ROAS'] . '%' : '-' ?></td>
          <td class="r tnum"><?= (int)($c['매출비중'] ?? 0) ?>%</td><td class="r tnum"><?= (int)($c['광고비중'] ?? 0) ?>%</td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>

<div style="display:flex;gap:14px;flex-wrap:wrap;align-items:flex-start">
<div class="card" style="flex:1 1 360px">
  <div class="ch">날짜별 매출</div>
  <?php if (!$daily): ?><div class="empty">이 기간에 주문이 없습니다.</div><?php else: ?>
  <table>
    <thead><tr><th>날짜</th><?php foreach (SHOP_CHANNELS as $lab): ?><th class="r"><?= h($lab) ?></th><?php endforeach; ?><th class="r">합계</th></tr></thead>
    <tbody>
    <?php foreach ($daily as $d => $v): ?>
      <tr><td class="tnum"><?= h($d) ?></td>
        <?php foreach (SHOP_CHANNELS as $k => $_): ?><td class="r tnum"><?= money($v[$k] ?? 0) ?></td><?php endforeach; ?>
        <td class="r tnum" style="font-weight:700"><?= money(array_sum($v)) ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>
<div class="card" style="flex:1 1 360px">
  <div class="ch">상품 매출 TOP 20</div>
  <?php if (!$top): ?><div class="empty">이 기간에 주문이 없습니다.</div><?php else: ?>
  <table>
    <thead><tr><th>상품명</th><th>판매처</th><th class="r">수량</th><th class="r">매출</th></tr></thead>
    <tbody>
    <?php foreach ($top as $r): ?>
      <tr><td style="font-size:12.5px"><?= h(mb_strimwidth((string)$r['product'], 0, 60, '…')) ?></td>
        <td style="font-size:12px"><?= h(implode(', ', array_map('shop_channel_label', explode(',', (string)$r['chs'])))) ?></td>
        <td class="r tnum"><?= money($r['qty']) ?></td><td class="r tnum"><?= money($r['sales']) ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>
</div>

<div class="card">
  <div class="ch">주문 목록 <span style="font-weight:400;color:var(--ink3);font-size:12px">최근 300줄까지 표시</span>
    <a class="btn sm" style="margin-left:auto" href="?<?= h($qs) ?>&amp;download=1">CSV 내려받기</a></div>
  <?php if (!$rows): ?>
    <div class="empty">주문이 없습니다. 위에서 [지금 가져오기] 를 누르세요.</div>
  <?php else: ?>
  <table>
    <thead><tr><th style="width:130px">주문일시</th><th style="width:100px">판매처</th><th style="width:160px">주문번호</th>
      <th>상품명</th><th class="r" style="width:60px">수량</th><th class="r" style="width:100px">금액</th><th style="width:140px">상태</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr><td class="tnum"><?= h(substr((string)$r['ordered_at'], 0, 16)) ?></td>
        <td><?= h(shop_channel_label($r['channel'])) ?></td>
        <td class="tnum" style="font-size:12px"><?= h($r['order_id']) ?></td>
        <td style="font-size:12.5px"><?= h($r['product']) ?></td>
        <td class="r tnum"><?= money($r['qty']) ?></td>
        <td class="r tnum"><?= money($r['amount']) ?></td>
        <td style="font-size:12px"><?= h(shop_status_label((string)$r['channel'], $r['status'] ?? '')) ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>
<?php layout_foot();
