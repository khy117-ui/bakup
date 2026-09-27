<?php
// 웹에서 직접 열면 실행되지 않게 막습니다 (nginx 면 .htaccess 가 무시됩니다)
if (!defined('APP_DIR')) { http_response_code(403); exit('Forbidden'); }

require_once APP_DIR . '/layout.php';
require_once APP_DIR . '/shop_claims.php';

/**
 * 반품 · 교환 — 쿠팡 · 스마트스토어 반품 · 교환 요청을 한 화면에서 보고 반품을 승인합니다.
 * 반품이 끝나면 그 주문은 매출 · 판매 수량에서 빠져 재고가 저절로 다시 늘어납니다.
 */
$err = '';
shop_claims_ensure_schema();
$pdo = db();
$hasApi = shop_api_cfg()['shop_api_url'] !== '' && shop_api_cfg()['shop_api_token'] !== '';
$canEdit = route_can_edit('shop_claims');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canEdit) {
    csrf_check();
    try {
        if (post('act') === 'fetch') {
            $days = in_array((int)post('days'), [3, 7, 30], true) ? (int)post('days') : 7;
            [$new, $errs] = shop_claims_sync($days);
            flash("최근 {$days}일 반품 · 교환을 가져왔습니다. 새 요청 {$new}건."
                  . ($errs ? ' 실패: ' . implode(' / ', array_map(fn($k, $v) => shop_channel_label((string)$k) . ' ' . mb_substr((string)$v, 0, 80), array_keys($errs), $errs)) : ''));
            redirect('?p=shop_claims');
        } elseif (post('act') === 'approve') {
            $restock = post('restock') === '1';
            $c = shop_claim_approve((int)post('id'), $restock);
            log_action('쇼핑몰', 'UPDATE', 'shop_claims', (int)$c['id'], shop_channel_label($c['channel']) . ' 반품 승인 ' . $c['order_id'],
                       null, $restock ? '재고에 다시 넣음' : '재고에 넣지 않음');
            flash(shop_channel_label($c['channel']) . ' 반품을 승인했습니다.' . ($restock ? ' 반품이 끝나면 재고가 다시 늘어납니다.' : ' 재고에서 ' . (int)$c['qty'] . '개를 뺐습니다.'));
            redirect('?p=shop_claims');
        }
    } catch (RuntimeException $e) {
        $err = $e->getMessage();
    } catch (PDOException $e) {
        error_log('쇼핑몰 반품 처리 실패: ' . $e->getMessage());
        $err = '처리하지 못했습니다.';
    }
}

$f = in_array(query('f'), ['open', 'all', 'done'], true) ? query('f') : 'open';
$ty = array_key_exists(query('t'), SHOP_CLAIM_TYPES) ? query('t') : '';
$w = ['1=1'];
$pa = [];
if ($f === 'open') { $w[] = "done = 0 AND status NOT IN ('RETURN_REJECT', 'EXCHANGE_REJECT', 'REJECT', 'CANCEL')"; }
elseif ($f === 'done') { $w[] = 'done = 1'; }
if ($ty !== '') { $w[] = 'type = ?'; $pa[] = $ty; }
$st = $pdo->prepare('SELECT c.*, a.name AS approver FROM shop_claims c LEFT JOIN admins a ON a.id = c.approved_by
                      WHERE ' . implode(' AND ', $w) . ' ORDER BY can_approve DESC, requested_at DESC, id DESC LIMIT 300');
$st->execute($pa);
$rows = $st->fetchAll();
$cnt = ['approve' => 0, 'open' => shop_claims_open()];
foreach ($rows as $r) { if ((int)$r['can_approve']) { $cnt['approve']++; } }
$reasonRank = $pdo->query("SELECT reason, COUNT(*) c FROM shop_claims WHERE reason IS NOT NULL AND requested_at >= DATE_SUB(CURDATE(), INTERVAL 90 DAY)
                            GROUP BY reason ORDER BY c DESC LIMIT 5")->fetchAll();
$prodRank = $pdo->query("SELECT product, SUM(qty) q FROM shop_claims WHERE type = 'RETURN' AND requested_at >= DATE_SUB(CURDATE(), INTERVAL 90 DAY)
                          GROUP BY product ORDER BY q DESC LIMIT 5")->fetchAll();
$base = '?p=shop_claims' . ($ty !== '' ? '&t=' . $ty : '');

layout_head('반품 · 교환', 'shop_claims');
?>
<div class="head">
  <h1>반품 · 교환</h1>
  <div class="crumb">쇼핑몰관리 &gt; 쿠팡 · 스마트스토어 반품 · 교환 요청 (반품은 여기서 바로 승인)</div>
</div>
<?php if ($err !== ''): ?><div class="msg err"><?= h($err) ?></div><?php endif; ?>

<div class="kpis">
  <div class="kpi"><div class="lab">처리할 반품 · 교환</div><div class="val tnum"><?= $cnt['open'] ?>건</div><div class="sub">최근 30일, 끝나지 않은 것</div></div>
  <div class="kpi"><div class="lab">자주 나온 사유 (90일)</div>
    <div class="sub" style="margin-top:6px;line-height:1.7"><?= $reasonRank ? implode('<br>', array_map(fn($r) => h(mb_strimwidth((string)$r['reason'], 0, 40, '…')) . ' · ' . (int)$r['c'] . '건', $reasonRank)) : '-' ?></div></div>
  <div class="kpi"><div class="lab">반품 많은 상품 (90일)</div>
    <div class="sub" style="margin-top:6px;line-height:1.7"><?= $prodRank ? implode('<br>', array_map(fn($r) => h(mb_strimwidth((string)$r['product'], 0, 34, '…')) . ' · ' . (int)$r['q'] . '개', $prodRank)) : '-' ?></div></div>
</div>

<div class="card">
  <div class="ch">
    <a class="btn sm<?= $f === 'open' ? ' pri' : '' ?>" href="<?= h($base . '&f=open') ?>">처리할 것</a>
    <a class="btn sm<?= $f === 'done' ? ' pri' : '' ?>" href="<?= h($base . '&f=done') ?>">끝난 것</a>
    <a class="btn sm<?= $f === 'all' ? ' pri' : '' ?>" href="<?= h($base . '&f=all') ?>">전체</a>
    <form method="get" style="display:flex;gap:6px;margin-left:12px">
      <input type="hidden" name="p" value="shop_claims"><input type="hidden" name="f" value="<?= h($f) ?>">
      <select name="t" onchange="this.form.submit()" style="width:auto"><option value="">반품 · 교환</option>
        <?php foreach (SHOP_CLAIM_TYPES as $k => [$lab]): ?><option value="<?= $k ?>"<?= $ty === $k ? ' selected' : '' ?>><?= h($lab) ?></option><?php endforeach; ?></select></form>
    <?php if ($canEdit): ?>
    <form method="post" style="margin-left:auto;display:flex;gap:6px;align-items:center">
      <?= csrf_field() ?><input type="hidden" name="act" value="fetch">
      <select name="days" style="width:auto"<?= $hasApi ? '' : ' disabled' ?>><option value="3">최근 3일</option><option value="7" selected>최근 7일</option><option value="30">최근 30일</option></select>
      <button class="btn sm pri"<?= $hasApi ? '' : ' disabled title="환경설정 → 연동 에 쇼핑몰 서버 주소 · 토큰을 넣으면 쓸 수 있습니다"' ?>>지금 가져오기</button></form>
    <?php endif; ?>
  </div>
  <div class="cb" style="font-size:12px;color:var(--ink2);border-bottom:1px solid var(--line2)">
    마지막으로 가져온 때: <?= h(shop_state_get('last_claim_fetch') ?? '-') ?> · 자동 가져오기가 켜져 있으면 주문과 함께 1시간마다 가져옵니다.
    반품이 끝나면 그 주문은 매출 · 재고 계산에서 빠집니다(재고가 다시 늘어남). 교환 · 카페24 반품은 판매처 관리자에서 처리하세요.
  </div>
  <?php if (!$rows): ?><div class="empty"><?= $f === 'open' ? '처리할 반품 · 교환이 없습니다.' : '반품 · 교환이 없습니다.' ?></div><?php else: ?>
  <table>
    <thead><tr><th>요청일</th><th>판매처</th><th>구분</th><th>상태</th><th>주문번호</th><th>상품</th><th class="r">수량</th><th>사유</th><th>처리</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $c): [$tl, $tc] = SHOP_CLAIM_TYPES[$c['type']] ?? [$c['type'], '']; ?>
      <tr><td class="tnum" style="white-space:nowrap"><?= h(substr((string)$c['requested_at'], 0, 16)) ?></td>
        <td><?= h(shop_channel_label($c['channel'])) ?></td>
        <td><span class="badge <?= $tc ?>"><?= h($tl) ?></span></td>
        <td style="font-size:12px"><?= h(shop_claim_status_label((string)$c['status'])) ?></td>
        <td class="tnum"><?= h($c['order_id']) ?></td>
        <td style="font-size:12.5px"><?= h(mb_strimwidth((string)$c['product'], 0, 50, '…')) ?></td>
        <td class="r tnum"><?= (int)$c['qty'] ?></td>
        <td style="font-size:12px;color:var(--ink2)"><?= h(mb_strimwidth((string)$c['reason'], 0, 60, '…')) ?></td>
        <td style="white-space:nowrap">
          <?php if ((int)$c['can_approve'] && $canEdit): ?>
            <form method="post" style="display:flex;gap:6px;align-items:center" onsubmit="return confirm('<?= h(shop_channel_label($c['channel'])) ?> 반품을 승인할까요? 고객에게 환불이 진행됩니다.');">
              <?= csrf_field() ?><input type="hidden" name="act" value="approve"><input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
              <select name="restock" style="width:auto;font-size:12px"><option value="1">재고에 다시 넣기</option><option value="0">파손 · 재고에 안 넣음</option></select>
              <button class="btn sm pri">반품 승인</button></form>
          <?php elseif ($c['approved_at']): ?>
            <span style="font-size:12px">승인 <?= h(substr((string)$c['approved_at'], 5, 11)) ?> · <?= h((string)($c['approver'] ?? '')) ?><?= (int)$c['no_restock'] ? ' · 재고 안 넣음' : '' ?></span>
          <?php elseif ((int)$c['done']): ?><span class="badge b-ok">완료</span>
          <?php else: ?><span style="font-size:12px;color:var(--ink3)">판매처에서 처리</span><?php endif; ?>
        </td></tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>
<?php layout_foot();
