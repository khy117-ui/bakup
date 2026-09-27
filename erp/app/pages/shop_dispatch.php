<?php
// 웹에서 직접 열면 실행되지 않게 막습니다 (nginx 면 .htaccess 가 무시됩니다)
if (!defined('APP_DIR')) { http_response_code(403); exit('Forbidden'); }

require_once APP_DIR . '/layout.php';
require_once APP_DIR . '/shop_ship.php';

/**
 * 송장 등록 — 택배 프로그램에서 받은 송장 엑셀을 올리면 쿠팡 · 스마트스토어 · 카페24 에 한 번에 발송처리합니다.
 *   ① 송장 대기 주문 엑셀을 받아 택배 프로그램에 넣고 ② 송장번호가 채워진 엑셀을 올리면
 *   ③ ERP 주문과 맞춰 본 뒤 ④ 확인을 누를 때 판매처에 보냅니다 (쇼핑몰 통합관리 서버 POST /dispatch).
 */
$err = '';
shop_ship_ensure_schema();
$pdo = db();
$hasApi = shop_api_cfg()['shop_api_url'] !== '' && shop_api_cfg()['shop_api_token'] !== '';
$canEdit = route_can_edit('shop_dispatch');
$defCourier = shop_setting('shop_default_courier', 'CJ대한통운');

if (query('dl') === 'waiting') {
    require_once APP_DIR . '/xlsx.php';
    $x = [['판매처', '주문번호', '주문일시', '상품', '수량', '택배사', '송장번호']];
    foreach (shop_ship_waiting(14) as $r) {
        $x[] = [shop_channel_label($r['channel']), (string)$r['order_id'], substr((string)$r['ordered_at'], 0, 16),
                (string)$r['products'], (int)$r['qty'], $defCourier, ''];
    }
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="invoice-waiting-' . date('Ymd') . '.xlsx"');
    echo xlsx_build('송장대기', $x, [12, 22, 16, 50, 6, 12, 18]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canEdit) {
    csrf_check();
    try {
        if (post('act') === 'preview') {
            $f = $_FILES['file'] ?? null;
            $bin = '';
            if ($f && ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                if ($f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) { throw new RuntimeException('파일을 올리지 못했습니다.'); }
                if ($f['size'] > 10 * 1024 * 1024) { throw new RuntimeException('10MB 보다 큰 파일은 올릴 수 없습니다.'); }
                $bin = (string)file_get_contents($f['tmp_name']);
            }
            if ($bin === '' && trim(post('paste')) === '') { throw new RuntimeException('송장 엑셀 파일을 고르거나 주문번호 · 송장번호를 붙여 넣으세요.'); }
            $courier = array_key_exists(post('courier'), SHOP_COURIERS) ? shop_courier_label(post('courier')) : $defCourier;
            $rows = shop_ship_parse($bin, (string)($f['name'] ?? ''), post('paste'));
            if (count($rows) > 2000) { throw new RuntimeException('한 번에 2000줄까지 올릴 수 있습니다.'); }
            $_SESSION['ship_preview'] = ['token' => bin2hex(random_bytes(8)), 'file' => (string)($f['name'] ?? '붙여넣기'),
                                         'rows' => shop_ship_match($rows, $courier)];
            unset($_SESSION['ship_result']);
            redirect('?p=shop_dispatch');
        } elseif (post('act') === 'send') {
            $pv = $_SESSION['ship_preview'] ?? null;
            if (!$pv || !hash_equals($pv['token'], post('token'))) { throw new RuntimeException('미리보기가 지났습니다. 파일을 다시 올려 주세요.'); }
            // 미리보기 뒤에 다른 사람이 먼저 보냈을 수 있으니 다시 맞춰 봄
            $again = shop_ship_match(array_map(fn($e) => [$e['channel'], $e['order_id'], $e['courier'], $e['tracking_no']], $pv['rows']), $defCourier);
            [$n, $ok, $bad, $res] = shop_ship_send($again);
            log_action('쇼핑몰', 'UPDATE', 'shop_dispatch_log', null, "송장 등록 {$n}건 (성공 {$ok} · 실패 {$bad})", null, $pv['file']);
            $_SESSION['ship_result'] = $res;
            unset($_SESSION['ship_preview']);
            flash("송장 {$n}건을 보냈습니다. 성공 {$ok}건" . ($bad ? " · 실패 {$bad}건 (아래 결과를 확인하세요)" : '') . '.');
            redirect('?p=shop_dispatch');
        } elseif (post('act') === 'clear') {
            unset($_SESSION['ship_preview'], $_SESSION['ship_result']);
            redirect('?p=shop_dispatch');
        }
    } catch (RuntimeException $e) {
        $err = $e->getMessage();
    } catch (PDOException $e) {
        error_log('송장 등록 실패: ' . $e->getMessage());
        $err = '처리하지 못했습니다.';
    }
}

$waiting = shop_ship_waiting(14);
$wait = ['coupang' => 0, 'naver' => 0, 'cafe24' => 0];
foreach ($waiting as $r) { $wait[$r['channel']] = ($wait[$r['channel']] ?? 0) + 1; }
$pv = $_SESSION['ship_preview'] ?? null;
$res = $_SESSION['ship_result'] ?? null;
$log = $pdo->query('SELECT l.*, a.name AS sender FROM shop_dispatch_log l LEFT JOIN admins a ON a.id = l.sent_by
                     ORDER BY l.id DESC LIMIT 100')->fetchAll();
$STATE = ['ok' => ['보냄', 'b-ok'], 'none' => ['주문 없음', 'b-err'], 'courier' => ['택배사 모름', 'b-err'], 'blank' => ['송장 없음', 'b-err'],
          'same' => ['이미 등록', 'b-info'], 'dup' => ['중복 줄', 'b-warn'], 'noline' => ['다시 가져오기', 'b-warn']];

layout_head('송장 등록', 'shop_dispatch');
?>
<div class="head">
  <h1>송장 등록</h1>
  <div class="crumb">쇼핑몰관리 &gt; 송장 엑셀을 올리면 쿠팡 · 스마트스토어 · 카페24 에 한 번에 발송처리</div>
</div>
<?php if ($err !== ''): ?><div class="msg err"><?= h($err) ?></div><?php endif; ?>

<div class="kpis">
  <div class="kpi"><div class="lab">송장 기다리는 주문 (최근 14일)</div><div class="val tnum"><?= count($waiting) ?>건</div>
    <div class="sub"><?= h(implode(' · ', array_map(fn($k, $v) => shop_channel_label($k) . ' ' . $v, array_keys($wait), $wait))) ?></div></div>
  <div class="kpi"><div class="lab">기본 택배사</div><div class="val" style="font-size:18px"><?= h($defCourier) ?></div>
    <div class="sub">엑셀에 택배사 칸이 없으면 이걸로 · 환경설정 → 쇼핑몰</div></div>
</div>

<?php if ($canEdit): ?>
<div class="card">
  <div class="ch">송장 엑셀 올리기
    <a class="btn sm" style="margin-left:auto" href="?p=shop_dispatch&amp;dl=waiting">송장 대기 주문 엑셀 받기 (<?= count($waiting) ?>건)</a></div>
  <div class="cb">
    <form method="post" enctype="multipart/form-data" style="display:grid;gap:10px;max-width:760px">
      <?= csrf_field() ?><input type="hidden" name="act" value="preview">
      <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center">
        <input type="file" name="file" accept=".xlsx,.csv,.txt" style="width:auto">
        <label style="font-size:12.5px">택배사 칸이 없으면
          <select name="courier" style="width:auto">
            <?php foreach (SHOP_COURIERS as $code => [$label]): ?><option value="<?= h($code) ?>"<?= $label === $defCourier ? ' selected' : '' ?>><?= h($label) ?></option><?php endforeach; ?>
          </select></label>
      </div>
      <textarea name="paste" rows="4" placeholder="또는 엑셀에서 복사해 붙여 넣기 — 한 줄에 주문번호 · 송장번호 (· 택배사)&#10;2025092712345	640123456789	CJ대한통운"></textarea>
      <div style="display:flex;gap:10px;align-items:center">
        <button class="btn pri">맞춰 보기</button>
        <span style="font-size:12px;color:var(--ink2)">첫 줄 머리글에 "주문번호" · "송장번호" (· "택배사" · "판매처") 가 있으면 칸 순서는 상관없습니다.
          스마트스토어 상품주문번호, 카페24 품목별 주문번호로도 찾습니다. 올린 뒤 확인을 눌러야 판매처로 보냅니다.</span>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<?php if ($pv): $cnt = array_count_values(array_column($pv['rows'], 'state')); $okN = $cnt['ok'] ?? 0; ?>
<div class="card">
  <div class="ch">맞춰 본 결과 — <?= h($pv['file']) ?> · <?= count($pv['rows']) ?>줄
    <span style="margin-left:10px;font-size:12.5px;font-weight:400">
      <?php foreach ($cnt as $k => $v): ?><span class="badge <?= $STATE[$k][1] ?>"><?= h($k === 'ok' ? '보낼 것' : $STATE[$k][0]) ?> <?= $v ?></span> <?php endforeach; ?></span>
    <div style="margin-left:auto;display:flex;gap:6px">
      <form method="post"><?= csrf_field() ?><input type="hidden" name="act" value="clear"><button class="btn sm">취소</button></form>
      <?php if ($okN && $hasApi): ?>
      <form method="post" onsubmit="return confirm('<?= $okN ?>건을 판매처에 발송처리할까요? 고객에게 배송 시작 알림이 갑니다.');">
        <?= csrf_field() ?><input type="hidden" name="act" value="send"><input type="hidden" name="token" value="<?= h($pv['token']) ?>">
        <button class="btn sm pri"><?= $okN ?>건 판매처에 보내기</button></form>
      <?php endif; ?>
    </div>
  </div>
  <?php if (!$hasApi): ?><div class="cb" style="font-size:12.5px;color:var(--ink2)">환경설정 → 연동 에 쇼핑몰 서버 주소 · 토큰을 넣어야 보낼 수 있습니다.</div><?php endif; ?>
  <?php if (isset($cnt['noline'])): ?><div class="cb" style="font-size:12.5px;color:var(--ink2)">"다시 가져오기" 줄은 이 기능이 생기기 전에 가져온 주문입니다. 주문 · 매출 에서 지금 가져오기를 한 번 누른 뒤 다시 올리세요.</div><?php endif; ?>
  <table>
    <thead><tr><th class="r">줄</th><th>상태</th><th>판매처</th><th>주문번호</th><th>상품</th><th>택배사</th><th>송장번호</th><th>메모</th></tr></thead>
    <tbody>
    <?php foreach ($pv['rows'] as $e): ?>
      <tr><td class="r tnum"><?= (int)$e['n'] ?></td>
        <td><span class="badge <?= $STATE[$e['state']][1] ?>"><?= h($STATE[$e['state']][0]) ?></span></td>
        <td><?= h($e['channel'] !== '' ? shop_channel_label($e['channel']) : '-') ?></td>
        <td class="tnum"><?= h($e['order_id']) ?></td>
        <td style="font-size:12px"><?= h(mb_strimwidth(implode(' / ', array_map(fn($l) => $l['product'] . ' × ' . $l['qty'], $e['lines'])), 0, 70, '…')) ?></td>
        <td><?= h($e['courier']) ?></td><td class="tnum"><?= h($e['tracking_no']) ?></td>
        <td style="font-size:12px;color:var(--ink2)"><?= h($e['msg']) ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<?php if ($res): ?>
<div class="card">
  <div class="ch">방금 보낸 결과
    <form method="post" style="margin-left:auto"><?= csrf_field() ?><input type="hidden" name="act" value="clear"><button class="btn sm">닫기</button></form></div>
  <table>
    <thead><tr><th>결과</th><th>판매처</th><th>주문번호</th><th>택배사</th><th>송장번호</th><th>판매처 응답</th></tr></thead>
    <tbody>
    <?php foreach ($res as $x): ?>
      <tr><td><?= $x['ok'] ? '<span class="badge b-ok">성공</span>' : '<span class="badge b-err">실패</span>' ?></td>
        <td><?= h(shop_channel_label($x['channel'])) ?></td><td class="tnum"><?= h($x['order_id']) ?></td>
        <td><?= h($x['courier']) ?></td><td class="tnum"><?= h($x['tracking_no']) ?></td>
        <td style="font-size:12px"><?= h($x['message']) ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<div class="card">
  <div class="ch">송장 등록 기록 (최근 100건)</div>
  <?php if (!$log): ?><div class="empty">아직 등록한 송장이 없습니다.</div><?php else: ?>
  <table>
    <thead><tr><th>보낸 때</th><th>결과</th><th>판매처</th><th>주문번호</th><th>택배사</th><th>송장번호</th><th>응답</th><th>보낸 사람</th></tr></thead>
    <tbody>
    <?php foreach ($log as $l): ?>
      <tr><td class="tnum"><?= h(substr((string)$l['sent_at'], 0, 16)) ?></td>
        <td><?= (int)$l['ok'] ? '<span class="badge b-ok">성공</span>' : '<span class="badge b-err">실패</span>' ?></td>
        <td><?= h(shop_channel_label($l['channel'])) ?></td><td class="tnum"><?= h($l['order_id']) ?></td>
        <td><?= h($l['courier']) ?></td><td class="tnum"><?= h($l['tracking_no']) ?></td>
        <td style="font-size:12px"><?= h((string)$l['message']) ?></td><td><?= h((string)($l['sender'] ?? '')) ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>
<?php layout_foot();
