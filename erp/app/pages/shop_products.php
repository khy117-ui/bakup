<?php
// 웹에서 직접 열면 실행되지 않게 막습니다 (nginx 면 .htaccess 가 무시됩니다)
if (!defined('APP_DIR')) { http_response_code(403); exit('Forbidden'); }

require_once APP_DIR . '/layout.php';
require_once APP_DIR . '/shop_biz.php';

/**
 * 상품 · 재고 · 순이익 — 쇼핑몰 주문에 나온 상품마다 원가 · 배송비 · 재고를 넣고,
 * 남은 재고 · 품절 예상일과 광고비까지 뺀 순이익을 봅니다 (계산 방법은 shop_biz.php).
 */
$err = '';
shop_biz_ensure_schema();
$pdo = db();
$tab = query('tab') === 'profit' ? 'profit' : 'stock';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('act') === 'save') {
    csrf_check();
    $cost = $_POST['cost'] ?? [];
    $ship = $_POST['ship'] ?? [];
    $stock = $_POST['stock'] ?? [];
    $hide = $_POST['hide'] ?? [];
    try {
        if (!is_array($cost) || !is_array($ship) || !is_array($stock)) { throw new RuntimeException('입력값이 올바르지 않습니다.'); }
        $old = [];
        foreach ($pdo->query('SELECT id, product, unit_cost, ship_cost, stock_base, hidden FROM shop_products')->fetchAll() as $p) { $old[(int)$p['id']] = $p; }
        $upd = $pdo->prepare('UPDATE shop_products SET unit_cost = ?, ship_cost = ?, hidden = ? WHERE id = ?');
        $setStock = $pdo->prepare('UPDATE shop_products SET stock_base = ?, stock_base_at = ? WHERE id = ?');
        $n = 0; $ns = 0;
        $pdo->beginTransaction();
        foreach ($old as $id => $p) {
            if (!array_key_exists($id, $cost)) { continue; }   // 화면에 없던 상품은 그대로
            $c = trim((string)$cost[$id]);
            $s = trim((string)($ship[$id] ?? ''));
            $cv = $c === '' ? null : (string)round(num($c));
            $sv = $s === '' ? null : (string)round(num($s));
            $hv = isset($hide[$id]) ? 1 : 0;
            if ($cv !== ($p['unit_cost'] === null ? null : (string)round((float)$p['unit_cost']))
                || $sv !== ($p['ship_cost'] === null ? null : (string)round((float)$p['ship_cost'])) || $hv !== (int)$p['hidden']) {
                $upd->execute([$cv, $sv, $hv, $id]);
                $n++;
            }
            $q = trim((string)($stock[$id] ?? ''));
            if ($q !== '') {
                if (!preg_match('/^-?\d[\d,]*$/', $q)) { throw new RuntimeException($p['product'] . ' 재고는 숫자로 넣으세요.'); }
                $setStock->execute([(int)str_replace(',', '', $q), date('Y-m-d H:i:s'), $id]);   // 주문 시각과 같은 한국 시간 (DB NOW() 는 UTC 일 수 있음)
                log_action('쇼핑몰', 'UPDATE', 'shop_products', $id, $p['product'], $p['stock_base'] === null ? null : (string)$p['stock_base'],
                           '재고 입력 ' . (int)str_replace(',', '', $q));
                $ns++;
            }
        }
        $pdo->commit();
        flash("저장했습니다. 원가 · 배송비 {$n}개, 재고 입력 {$ns}개.");
        redirect('?p=shop_products');
    } catch (RuntimeException $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        $err = $e->getMessage();
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        error_log('쇼핑몰 상품 저장 실패: ' . $e->getMessage());
        $err = '저장하지 못했습니다.';
    }
}

$canEdit = route_can_edit('shop_products');
$warnDays = (int)shop_setting('shop_stock_warn_days', '7');

if ($tab === 'stock') {
    $showHidden = query('hidden') === '1';
    $rows = shop_stock_rows($showHidden);
    $alerts = shop_stock_alerts(array_values(array_filter($rows, fn($r) => !(int)$r['hidden'])));
} else {
    $from = query('from', date('Y-m-d', strtotime('-29 days')));
    $to   = query('to', date('Y-m-d'));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) { $from = date('Y-m-d', strtotime('-29 days')); }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) { $to = date('Y-m-d'); }
    if ($from > $to) { [$from, $to] = [$to, $from]; }
    $pf = shop_profit($from, $to);
    $t = $pf['total'];
}

layout_head('상품 · 재고 · 순이익', 'shop_products');
?>
<div class="head">
  <h1>상품 · 재고 · 순이익</h1>
  <div class="crumb">쇼핑몰관리 &gt; 상품마다 원가 · 배송비 · 재고를 넣으면 남은 재고와 순이익을 계산합니다</div>
</div>
<?php if ($err !== ''): ?><div class="msg err"><?= h($err) ?></div><?php endif; ?>

<div class="card"><div class="ch">
  <a class="btn sm<?= $tab === 'stock' ? ' pri' : '' ?>" href="?p=shop_products">재고 · 원가 입력</a>
  <a class="btn sm<?= $tab === 'profit' ? ' pri' : '' ?>" href="?p=shop_products&amp;tab=profit">상품별 순이익</a>
  <?php if (route_can_edit('settings')): ?><a class="btn sm" style="margin-left:auto" href="?p=settings">수수료 · 알림 기준 (환경설정 → 쇼핑몰)</a><?php endif; ?>
</div></div>

<?php if ($tab === 'stock'): ?>
<?php if ($alerts): ?>
<div class="msg err"><b>품절 · 품절 임박 <?= count($alerts) ?>개</b> —
  <?= h(implode(' · ', array_map(fn($r) => $r['product'] . ' (' . ($r['stock_now'] <= 0 ? '품절' : $r['stock_now'] . '개, 약 ' . $r['days_left'] . '일') . ')', array_slice($alerts, 0, 6)))) ?><?= count($alerts) > 6 ? ' 외' : '' ?></div>
<?php endif; ?>
<div class="card">
  <div class="ch">상품 <?= count($rows) ?>개
    <span style="font-weight:400;color:var(--ink3);font-size:12px">재고 칸에 지금 실제 수량을 넣고 저장하면, 그 뒤 팔린 수량(취소 · 반품 제외)만큼 자동으로 줄어듭니다. 비워 두면 그대로.</span>
    <a class="btn sm" style="margin-left:auto" href="?p=shop_products<?= $showHidden ? '' : '&amp;hidden=1' ?>"><?= $showHidden ? '숨긴 상품 빼고 보기' : '숨긴 상품도 보기' ?></a></div>
  <?php if (!$rows): ?>
    <div class="empty">상품이 없습니다. <a href="?p=shop_orders">주문 · 매출</a> 에서 주문을 가져오면 상품이 자동으로 채워집니다.</div>
  <?php else: ?>
  <form method="post">
    <?= csrf_field() ?><input type="hidden" name="act" value="save">
    <table>
      <thead><tr><th>상품명</th><th class="r" style="width:110px">원가 (1개)</th><th class="r" style="width:110px">배송비 (1주문)</th>
        <th class="r" style="width:80px">입력 재고</th><th class="r" style="width:70px">이후 판매</th><th class="r" style="width:80px">남은 재고</th>
        <th class="r" style="width:80px">하루 판매</th><th class="r" style="width:90px">품절까지</th>
        <th style="width:110px">재고 새로 입력</th><th class="c" style="width:50px">숨김</th></tr></thead>
      <tbody>
      <?php foreach ($rows as $r): $id = (int)$r['id'];
        $bad = $r['stock_now'] !== null && ($r['stock_now'] <= 0 || ($r['days_left'] !== null && $r['days_left'] <= $warnDays)); ?>
        <tr<?= $bad ? ' style="background:#FFF1F0"' : ((int)$r['hidden'] ? ' style="opacity:.55"' : '') ?>>
          <td style="font-size:12.5px;font-weight:600"><?= h($r['product']) ?>
            <div style="font-weight:400;color:var(--ink3);font-size:11px">마지막 주문 <?= h(substr((string)$r['last_order'], 0, 10) ?: '-') ?>
              <?= $r['stock_base_at'] ? ' · 재고 입력 ' . h(substr((string)$r['stock_base_at'], 0, 16)) : '' ?></div></td>
          <td class="r"><input type="text" name="cost[<?= $id ?>]" value="<?= $r['unit_cost'] === null ? '' : h(money($r['unit_cost'])) ?>"
            <?= $canEdit ? '' : 'readonly' ?> style="text-align:right;<?= $r['unit_cost'] === null ? 'border-color:#E0A800' : '' ?>" placeholder="원가"></td>
          <td class="r"><input type="text" name="ship[<?= $id ?>]" value="<?= $r['ship_cost'] === null ? '' : h(money($r['ship_cost'])) ?>"
            <?= $canEdit ? '' : 'readonly' ?> style="text-align:right" placeholder="0"></td>
          <td class="r tnum"><?= $r['stock_base'] === null ? '-' : money($r['stock_base']) ?></td>
          <td class="r tnum"><?= $r['stock_base'] === null ? '-' : money($r['sold_since']) ?></td>
          <td class="r tnum" style="font-weight:700;<?= $bad ? 'color:#C62828' : '' ?>"><?= $r['stock_now'] === null ? '-' : money($r['stock_now']) ?></td>
          <td class="r tnum"><?= $r['daily'] > 0 ? h(rtrim(rtrim(number_format($r['daily'], 1), '0'), '.')) : '0' ?></td>
          <td class="r tnum"><?= $r['stock_now'] === null ? '-' : ($r['stock_now'] <= 0 ? '<span class="badge b-err">품절</span>'
              : ($r['days_left'] === null ? '판매 없음' : '약 ' . $r['days_left'] . '일')) ?></td>
          <td><input type="text" name="stock[<?= $id ?>]" value="" <?= $canEdit ? '' : 'readonly' ?> placeholder="실제 수량" style="text-align:right"></td>
          <td class="c"><input type="checkbox" name="hide[<?= $id ?>]" value="1"<?= (int)$r['hidden'] ? ' checked' : '' ?><?= $canEdit ? '' : ' disabled' ?> style="width:auto" title="판매 종료 — 목록 · 알림에서 숨김"></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php if ($canEdit): ?><div class="cb" style="text-align:right"><button class="btn pri">저장</button></div><?php endif; ?>
  </form>
  <?php endif; ?>
</div>

<?php else: ?>
<div class="card"><div class="cb">
  <form class="f" method="get" style="align-items:flex-end">
    <input type="hidden" name="p" value="shop_products"><input type="hidden" name="tab" value="profit">
    <div class="fw w1"><label for="f">시작일</label><input type="date" id="f" name="from" value="<?= h($from) ?>"></div>
    <div class="fw w1"><label for="t">종료일</label><input type="date" id="t" name="to" value="<?= h($to) ?>"></div>
    <button class="btn pri">조회</button>
    <?php foreach (['7일' => 6, '30일' => 29, '90일' => 89] as $lab => $n): ?>
      <a class="btn sm" href="?p=shop_products&amp;tab=profit&amp;from=<?= date('Y-m-d', strtotime("-{$n} days")) ?>&amp;to=<?= date('Y-m-d') ?>"><?= $lab ?></a>
    <?php endforeach; ?>
  </form>
</div></div>
<?php if ($pf['missing_cost'] > 0): ?>
  <div class="msg err">원가를 넣지 않은 상품이 <b><?= $pf['missing_cost'] ?>개</b> 있어 그만큼 이익이 크게 나옵니다. <a href="?p=shop_products">재고 · 원가 입력</a> 에서 넣으세요.</div>
<?php endif; ?>
<div class="kpis">
  <div class="kpi"><div class="lab">매출 (취소 · 반품 제외)</div><div class="val tnum"><?= money($t['sales']) ?></div><div class="sub">수량 <?= money($t['qty']) ?></div></div>
  <div class="kpi"><div class="lab">원가 + 수수료 + 배송비</div><div class="val tnum"><?= money($t['cost'] + $t['fee'] + $t['ship']) ?></div>
    <div class="sub">원가 <?= money($t['cost']) ?> · 수수료 <?= money($t['fee']) ?> · 배송 <?= money($t['ship']) ?></div></div>
  <div class="kpi"><div class="lab">광고비 (추정)</div><div class="val tnum"><?= money($t['ad']) ?></div>
    <div class="sub"><?= $pf['ad'] ? h(implode(' · ', array_map(fn($k, $v) => ($k === 'coupang' ? '쿠팡' : ($k === 'naver' ? '네이버' : $k)) . ' ' . money($v), array_keys($pf['ad']), $pf['ad']))) : '기간이 겹치는 광고 보고서 없음' ?></div></div>
  <div class="kpi"><div class="lab">순이익</div><div class="val tnum" style="color:<?= $t['profit'] < 0 ? '#C62828' : '#1B7F5A' ?>"><?= money($t['profit']) ?></div>
    <div class="sub">이익률 <?= h($t['margin']) ?>% · 광고비 빼기 전 <?= money($t['before_ad']) ?></div></div>
</div>
<div class="card">
  <div class="ch">상품별 순이익 <span style="font-weight:400;color:var(--ink3);font-size:12px">광고비는 판매처 광고비를 그 판매처 매출 비중으로 나눈 추정치입니다. 광고 · 키워드 에 보고서를 올릴 때 기간을 넣어야 잡힙니다.</span></div>
  <?php if (!$pf['rows']): ?><div class="empty">이 기간에 주문이 없습니다.</div><?php else: ?>
  <table>
    <thead><tr><th>상품명</th><th>판매처</th><th class="r">수량</th><th class="r">매출</th><th class="r">원가</th><th class="r">수수료</th>
      <th class="r">배송비</th><th class="r">광고비</th><th class="r">순이익</th><th class="r">이익률</th><th class="r">ROAS</th></tr></thead>
    <tbody>
    <?php foreach ($pf['rows'] as $r): $loss = $r['profit'] < 0; ?>
      <tr<?= $loss ? ' style="background:#FFF1F0"' : '' ?>>
        <td style="font-size:12.5px;font-weight:600"><?= h($r['product']) ?><?= $r['no_cost'] ? ' <span class="badge b-warn">원가 없음</span>' : '' ?>
          <?= $loss && $r['roas'] !== null && $r['roas'] >= (int)shop_setting('ad_target_roas', '400') ? ' <span class="badge b-err">ROAS 는 좋지만 적자</span>' : '' ?></td>
        <td style="font-size:12px"><?= h(implode(', ', array_map('shop_channel_label', array_keys($r['channels'])))) ?></td>
        <td class="r tnum"><?= money($r['qty']) ?></td><td class="r tnum"><?= money($r['sales']) ?></td>
        <td class="r tnum"><?= money($r['cost']) ?></td><td class="r tnum"><?= money($r['fee']) ?></td>
        <td class="r tnum"><?= money($r['ship']) ?></td><td class="r tnum"><?= money($r['ad']) ?></td>
        <td class="r tnum" style="font-weight:700;color:<?= $loss ? '#C62828' : '#1B7F5A' ?>"><?= money($r['profit']) ?></td>
        <td class="r tnum"><?= h($r['margin']) ?>%</td><td class="r tnum"><?= $r['roas'] === null ? '-' : $r['roas'] . '%' ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>
<?php endif; ?>
<?php layout_foot();
