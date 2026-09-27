<?php
// 웹에서 직접 열면 실행되지 않게 막습니다 (nginx 면 .htaccess 가 무시됩니다)
if (!defined('APP_DIR')) { http_response_code(403); exit('Forbidden'); }

require_once APP_DIR . '/layout.php';
require_once APP_DIR . '/shop_biz.php';

/**
 * 고객 문의 — 쿠팡 · 스마트스토어 상품 문의를 한 화면에서 보고 답변합니다.
 * 쇼핑몰 통합관리 서버(commerce-hub)의 /inquiries 로 가져오고, 답변은 /inquiries/reply 로 판매채널에 바로 등록됩니다.
 * 자동 가져오기가 켜져 있으면 주문과 함께 1시간마다 최근 3일 문의를 가져옵니다.
 */
$err = '';
shop_biz_ensure_schema();
$pdo = db();
$hasApi = shop_api_cfg()['shop_api_url'] !== '' && shop_api_cfg()['shop_api_token'] !== '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    try {
        if (post('act') === 'fetch') {
            $days = in_array((int)post('days'), [3, 7, 30], true) ? (int)post('days') : 7;
            [$new, $errs] = shop_inquiries_sync($days);
            flash("최근 {$days}일 문의를 가져왔습니다. 새 문의 {$new}건."
                  . ($errs ? ' 실패: ' . implode(' / ', array_map(fn($k, $v) => shop_channel_label((string)$k) . ' ' . mb_substr((string)$v, 0, 80), array_keys($errs), $errs)) : ''));
            redirect('?p=shop_inquiries');
        } elseif (post('act') === 'reply') {
            $q = shop_inquiry_reply((int)post('id'), post('content'));
            log_action('쇼핑몰', 'UPDATE', 'shop_inquiries', (int)$q['id'], shop_channel_label($q['channel']) . ' 문의 답변',
                       null, mb_substr(post('content'), 0, 200));
            flash(shop_channel_label($q['channel']) . ' 문의에 답변을 등록했습니다.');
            redirect('?p=shop_inquiries' . (query('f') !== '' ? '&f=' . rawurlencode(query('f')) : ''));
        }
    } catch (RuntimeException $e) {
        $err = $e->getMessage();
    } catch (PDOException $e) {
        error_log('쇼핑몰 문의 처리 실패: ' . $e->getMessage());
        $err = '처리하지 못했습니다.';
    }
}

$f = in_array(query('f'), ['open', 'all', 'done'], true) ? query('f') : 'open';
$ch = array_key_exists(query('ch'), SHOP_CHANNELS) ? query('ch') : '';
$kw = trim(query('kw'));
$w = ['1=1']; $pa = [];
if ($f === 'open') { $w[] = 'answered = 0'; } elseif ($f === 'done') { $w[] = 'answered = 1'; }
if ($ch !== '') { $w[] = 'channel = ?'; $pa[] = $ch; }
if ($kw !== '') { $w[] = '(product LIKE ? OR question LIKE ?)'; array_push($pa, "%$kw%", "%$kw%"); }
$st = $pdo->prepare('SELECT q.*, a.name AS answerer FROM shop_inquiries q LEFT JOIN admins a ON a.id = q.answered_by
                      WHERE ' . implode(' AND ', $w) . ' ORDER BY answered ASC, asked_at ' . ($f === 'open' ? 'ASC' : 'DESC') . ', id DESC LIMIT 200');
$st->execute($pa);
$rows = $st->fetchAll();
$cnt = ['open' => 0, 'done' => 0];
foreach ($pdo->query('SELECT answered, COUNT(*) c FROM shop_inquiries GROUP BY answered')->fetchAll() as $r) {
    $cnt[(int)$r['answered'] ? 'done' : 'open'] = (int)$r['c'];
}
$canEdit = route_can_edit('shop_inquiries');
$tpls = array_values(array_filter(array_map('trim', explode('|', shop_setting('shop_reply_templates',
    '안녕하세요, 고객님. 문의 주셔서 감사합니다.|확인 후 다시 안내드리겠습니다.|오늘 오후 3시 이전 주문은 당일 출고됩니다.|추가로 궁금하신 점은 언제든 문의 주세요. 감사합니다.'))), 'strlen'));
$lastFetch = shop_state_get('last_inquiry_fetch');
$base = '?p=shop_inquiries' . ($ch !== '' ? '&ch=' . $ch : '') . ($kw !== '' ? '&kw=' . rawurlencode($kw) : '');

layout_head('고객 문의', 'shop_inquiries');
?>
<div class="head">
  <h1>고객 문의</h1>
  <div class="crumb">쇼핑몰관리 &gt; 쿠팡 · 스마트스토어 상품 문의 (답변하면 판매채널에 바로 등록)</div>
</div>
<?php if ($err !== ''): ?><div class="msg err"><?= h($err) ?></div><?php endif; ?>

<div class="card">
  <div class="ch">
    <a class="btn sm<?= $f === 'open' ? ' pri' : '' ?>" href="<?= h($base . '&f=open') ?>">답변 대기 <?= $cnt['open'] ?></a>
    <a class="btn sm<?= $f === 'done' ? ' pri' : '' ?>" href="<?= h($base . '&f=done') ?>">답변 완료 <?= $cnt['done'] ?></a>
    <a class="btn sm<?= $f === 'all' ? ' pri' : '' ?>" href="<?= h($base . '&f=all') ?>">전체</a>
    <form method="get" style="display:flex;gap:6px;margin-left:12px">
      <input type="hidden" name="p" value="shop_inquiries"><input type="hidden" name="f" value="<?= h($f) ?>">
      <select name="ch" style="width:auto"><option value="">전체 판매처</option>
        <?php foreach (['coupang', 'naver'] as $k): ?><option value="<?= $k ?>"<?= $ch === $k ? ' selected' : '' ?>><?= h(shop_channel_label($k)) ?></option><?php endforeach; ?></select>
      <input type="text" name="kw" value="<?= h($kw) ?>" placeholder="상품명 · 문의 내용" style="width:180px">
      <button class="btn sm">검색</button></form>
    <?php if ($canEdit): ?>
    <form method="post" style="margin-left:auto;display:flex;gap:6px;align-items:center">
      <?= csrf_field() ?><input type="hidden" name="act" value="fetch">
      <select name="days" style="width:auto"<?= $hasApi ? '' : ' disabled' ?>><option value="3">최근 3일</option><option value="7" selected>최근 7일</option><option value="30">최근 30일</option></select>
      <button class="btn sm pri"<?= $hasApi ? '' : ' disabled title="환경설정 → 연동 에 쇼핑몰 서버 주소 · 토큰을 넣으면 쓸 수 있습니다"' ?>>지금 가져오기</button></form>
    <?php endif; ?>
  </div>
  <div class="cb" style="font-size:12px;color:var(--ink2);border-bottom:1px solid var(--line2)">
    마지막으로 가져온 때: <?= h($lastFetch ?? '-') ?> · 자동 가져오기가 켜져 있으면 주문과 함께 1시간마다 가져옵니다.
    카페24 게시판 문의는 아직 가져오지 않습니다.
  </div>
  <?php if (!$rows): ?>
    <div class="empty"><?= $f === 'open' ? '답변할 문의가 없습니다.' : '문의가 없습니다.' ?><?= $hasApi ? '' : ' 환경설정 → 연동 에 쇼핑몰 서버 주소와 토큰을 넣으세요.' ?></div>
  <?php else: foreach ($rows as $q): $late = !(int)$q['answered'] && $q['asked_at'] && strtotime((string)$q['asked_at']) < strtotime('-1 day'); ?>
    <div class="cb" style="border-bottom:1px solid var(--line2)<?= $late ? ';background:#FFF7F6' : '' ?>">
      <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;font-size:12px;color:var(--ink2)">
        <span class="badge <?= $q['channel'] === 'coupang' ? 'b-info' : 'b-ok' ?>"><?= h(shop_channel_label($q['channel'])) ?></span>
        <b style="color:var(--ink)"><?= h($q['product'] ?: '(상품명 없음)') ?></b>
        <span class="tnum"><?= h(substr((string)$q['asked_at'], 0, 16)) ?></span>
        <?= (int)$q['answered'] ? '<span class="badge b-ok">답변 완료</span>' : ($late ? '<span class="badge b-err">하루 넘음</span>' : '<span class="badge b-warn">답변 대기</span>') ?>
      </div>
      <div style="margin-top:6px;white-space:pre-wrap;font-size:13.5px"><?= h($q['question']) ?></div>
      <?php if ((int)$q['answered']): ?>
        <div style="margin-top:8px;padding:8px 10px;background:var(--line2);border-radius:6px;white-space:pre-wrap;font-size:12.5px"><b>답변</b><?= $q['answerer'] ? ' · ' . h($q['answerer']) : '' ?><?= $q['answered_at'] ? ' · ' . h(substr((string)$q['answered_at'], 0, 16)) : '' ?>
<?= h($q['answer'] ?? '(판매채널에서 답변함)') ?></div>
      <?php elseif ($canEdit): ?>
        <form method="post" action="<?= h('?p=shop_inquiries&f=' . $f) ?>" style="margin-top:8px" onsubmit="return confirm('<?= h(shop_channel_label($q['channel'])) ?> 에 이 답변을 등록할까요? 고객에게 바로 보입니다.');">
          <?= csrf_field() ?><input type="hidden" name="act" value="reply"><input type="hidden" name="id" value="<?= (int)$q['id'] ?>">
          <textarea name="content" rows="3" maxlength="2000" required placeholder="답변을 적으세요 — 등록하면 판매채널에 바로 올라갑니다"></textarea>
          <div style="display:flex;gap:6px;flex-wrap:wrap;margin-top:6px;align-items:center">
            <?php foreach ($tpls as $t): ?>
              <button type="button" class="btn sm" data-tpl="<?= h($t) ?>" onclick="var a=this.form.content;a.value=(a.value?a.value+' ':'')+this.dataset.tpl;a.focus()"><?= h(mb_strimwidth($t, 0, 24, '…')) ?></button>
            <?php endforeach; ?>
            <button class="btn pri" style="margin-left:auto">답변 등록</button>
          </div>
        </form>
      <?php endif; ?>
    </div>
  <?php endforeach; endif; ?>
</div>
<?php layout_foot();
