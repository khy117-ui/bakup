<?php
// 웹에서 직접 열면 실행되지 않게 막습니다 (nginx 면 .htaccess 가 무시됩니다)
if (!defined('APP_DIR')) { http_response_code(403); exit('Forbidden'); }

require_once APP_DIR . '/layout.php';
require_once APP_DIR . '/pickups.php';

/**
 * 온라인 접수 — 홈페이지 '온라인접수(픽업 예약)' 로 들어온 신청.
 *   새 접수 → [접수 확인] (고객에게 확인 메일 · 카톡, 안내 문구 포함) → [픽업 완료] / [취소]
 *   담당자 메모, 거래처 연결, 알림 보낸 기록. 알림 시험 발송도 여기서.
 */
$err = '';
$pdo = db();
pickup_ensure_table($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $act = post('act');
    try {
        if ($act === 'test_mail' || $act === 'test_kakao') {
            $c = notify_cfg();
            [$ok, $m] = $act === 'test_mail'
                ? notify_mail(post('to') ?: ($c['notify_staff_emails'] ?? ''), '[GOODPOST ERP] 메일 시험 발송',
                              "ERP 에서 보낸 시험 메일입니다.\n" . date('Y-m-d H:i:s'))
                : notify_kakao(post('to') ?: ($c['notify_staff_phones'] ?? ''), "[GOODPOST ERP] 문자 · 카톡 시험 발송 " . date('m-d H:i'));
            log_action('시스템', 'UPDATE', 'app_settings', null, $act === 'test_mail' ? '메일 시험' : '카톡 시험', null, ($ok ? 'OK ' : '실패 ') . $m);
            flash(($ok ? '성공 — ' : '실패 — ') . $m);
            redirect('?p=web_pickups');
        }
        $id = (int)post('id');
        $st = $pdo->prepare('SELECT * FROM web_pickups WHERE id = ?');
        $st->execute([$id]);
        $p = $st->fetch();
        if (!$p) { throw new RuntimeException('접수 건을 찾을 수 없습니다.'); }
        if ($act === 'confirm') {
            $msg = trim(post('staff_msg'));
            $pdo->prepare("UPDATE web_pickups SET status = 'CONFIRMED', handled_by = ?, handled_at = NOW(),
                                  staff_memo = CONCAT(COALESCE(staff_memo, ''), ?) WHERE id = ?")
                ->execute([$_SESSION['admin_id'] ?? null, $msg !== '' ? date('m-d H:i') . ' 안내: ' . $msg . "\n" : '', $id]);
            $sent = post('send') === '1' ? pickup_notify_customer($pdo, $p, 'confirm', $msg) : [];
            log_action('물류', 'UPDATE', 'web_pickups', $id, (string)$p['req_no'], (string)$p['status'], 'CONFIRMED');
            flash($p['req_no'] . ' 접수를 확인했습니다.' . ($sent ? ' 고객에게 ' . implode(' · ', $sent) . '.' : ''));
        } elseif (in_array($act, ['done', 'cancel', 'reopen'], true)) {
            $to = ['done' => 'DONE', 'cancel' => 'CANCELLED', 'reopen' => 'NEW'][$act];
            $pdo->prepare('UPDATE web_pickups SET status = ?, handled_by = ?, handled_at = NOW() WHERE id = ?')
                ->execute([$to, $_SESSION['admin_id'] ?? null, $id]);
            log_action('물류', 'UPDATE', 'web_pickups', $id, (string)$p['req_no'], (string)$p['status'], $to);
            flash($p['req_no'] . ' → ' . PICKUP_STATUS[$to][0]);
        } elseif ($act === 'memo') {
            $pdo->prepare('UPDATE web_pickups SET staff_memo = ?, company_id = ? WHERE id = ?')
                ->execute([mb_substr(post('staff_memo'), 0, 4000) ?: null, (int)post('company_id') ?: null, $id]);
            flash('메모를 저장했습니다.');
        } elseif ($act === 'resend') {
            $sent = pickup_notify_customer($pdo, $p, $p['status'] === 'NEW' ? 'received' : 'confirm');
            flash('고객 알림을 다시 보냈습니다: ' . ($sent ? implode(' · ', $sent) : '보낼 곳 없음 (이메일 · 휴대폰 번호 확인)'));
        }
        redirect('?p=web_pickups&id=' . $id);
    } catch (RuntimeException $e) {
        $err = $e->getMessage();
    } catch (PDOException $e) {
        error_log('온라인 접수 처리 실패: ' . $e->getMessage());
        $err = '처리하지 못했습니다.';
    }
}

$sel = array_key_exists(query('status'), PICKUP_STATUS) ? query('status') : '';
$kw  = trim(query('kw'));
$w = ['1=1']; $pa = [];
if ($sel !== '') { $w[] = 'status = ?'; $pa[] = $sel; }
if ($kw !== '') { $w[] = '(company_name LIKE ? OR manager LIKE ? OR phone LIKE ? OR req_no LIKE ?)'; array_push($pa, "%$kw%", "%$kw%", "%$kw%", "%$kw%"); }
$st = $pdo->prepare('SELECT * FROM web_pickups WHERE ' . implode(' AND ', $w) . ' ORDER BY id DESC LIMIT 200');
$st->execute($pa);
$rows = $st->fetchAll();
$cnt = [];
foreach ($pdo->query('SELECT status, COUNT(*) c FROM web_pickups GROUP BY status')->fetchAll() as $r) { $cnt[$r['status']] = (int)$r['c']; }

$cur = null;
if ((int)query('id') > 0) {
    $st = $pdo->prepare('SELECT p.*, c.name_ko AS erp_company, a.name AS handler FROM web_pickups p
                           LEFT JOIN companies c ON c.id = p.company_id LEFT JOIN admins a ON a.id = p.handled_by
                          WHERE p.id = ?');
    $st->execute([(int)query('id')]);
    $cur = $st->fetch() ?: null;
}
$companies = $cur ? db()->query('SELECT id, company_code, name_ko FROM companies WHERE deleted_at IS NULL ORDER BY name_ko')->fetchAll() : [];
$nc = notify_cfg();
$canSet = route_can_edit('settings');

layout_head('온라인 접수', 'web_pickups');
?>
<div class="head">
  <h1>온라인 접수</h1>
  <div class="crumb">물류관리 &gt; 온라인 접수 (홈페이지 픽업 예약)</div>
</div>
<?php if ($err !== ''): ?><div class="msg err"><?= h($err) ?></div><?php endif; ?>

<?php if ($cur): [$sl, $sc] = PICKUP_STATUS[$cur['status']] ?? [$cur['status'], 'b-info']; ?>
<div class="card">
  <div class="ch"><span class="tnum" style="font-size:14px"><?= h($cur['req_no']) ?></span>
    <span class="badge <?= $sc ?>"><?= h($sl) ?></span>
    <span style="font-weight:400;color:var(--ink3)"><?= h($cur['created_at']) ?> 접수</span>
    <a class="btn sm" style="margin-left:auto" href="?p=web_pickups">목록</a></div>
  <div class="cb f" style="gap:22px">
    <div><div style="font-size:11px;color:var(--ink2)">회사</div><div style="font-weight:700"><?= h($cur['company_name']) ?></div></div>
    <div><div style="font-size:11px;color:var(--ink2)">담당자</div><div><?= h($cur['manager']) ?></div></div>
    <div><div style="font-size:11px;color:var(--ink2)">연락처</div><div class="tnum"><a href="tel:<?= h(preg_replace('/[^0-9+]/', '', (string)$cur['phone'])) ?>"><?= h($cur['phone']) ?></a></div></div>
    <div><div style="font-size:11px;color:var(--ink2)">이메일</div><div><?= $cur['email'] ? '<a href="mailto:' . h($cur['email']) . '">' . h($cur['email']) . '</a>' : '-' ?></div></div>
    <div><div style="font-size:11px;color:var(--ink2)">픽업</div><div class="tnum" style="font-weight:700"><?= h(pickup_when($cur)) ?></div></div>
    <div><div style="font-size:11px;color:var(--ink2)">구분</div><div><?= h($cur['pickup_type']) ?> · <?= h($cur['ship_mode'] ?: '-') ?> · <?= h($cur['pay_terms'] ?: '-') ?></div></div>
    <div><div style="font-size:11px;color:var(--ink2)">무게</div><div class="tnum"><?= h($cur['weight_kg'] ?: '-') ?> kg</div></div>
  </div>
  <div class="cb" style="border-top:1px solid var(--line2)"><div style="font-size:11px;color:var(--ink2)">주소 (<?= h($cur['area'] ?: '-') ?>)</div>
    <div style="font-weight:600"><?= h($cur['address']) ?></div>
    <?php if ($cur['memo']): ?><div style="margin-top:8px;font-size:12.5px;white-space:pre-wrap"><b>고객 비고</b> <?= h($cur['memo']) ?></div><?php endif; ?></div>

  <?php if (route_can_edit('web_pickups')): ?>
  <div class="cb" style="border-top:1px solid var(--line2);display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end">
    <?php if ($cur['status'] === 'NEW'): ?>
    <form method="post" class="f" style="align-items:flex-end;flex:1;min-width:320px">
      <?= csrf_field() ?><input type="hidden" name="act" value="confirm"><input type="hidden" name="id" value="<?= (int)$cur['id'] ?>">
      <div class="fw gr" style="min-width:240px"><label>고객에게 보낼 안내 (선택)</label>
        <input type="text" name="staff_msg" maxlength="200" placeholder="예) 9/20 14시 기사 방문 예정입니다 · 담당 김OO 010-…"></div>
      <label style="font-size:12px;display:flex;gap:4px;align-items:center"><input type="checkbox" name="send" value="1" checked style="width:auto"> 고객에게 메일 · 카톡</label>
      <button class="btn pri">접수 확인</button>
    </form>
    <?php endif; ?>
    <?php foreach (['done' => '픽업 완료', 'cancel' => '취소', 'reopen' => '새 접수로 되돌리기'] as $a => $lab):
      if (($a === 'done' && $cur['status'] === 'DONE') || ($a === 'cancel' && $cur['status'] === 'CANCELLED') || ($a === 'reopen' && $cur['status'] === 'NEW')) { continue; } ?>
      <form method="post" onsubmit="return confirm('<?= h($lab) ?> 로 바꿀까요?');">
        <?= csrf_field() ?><input type="hidden" name="act" value="<?= $a ?>"><input type="hidden" name="id" value="<?= (int)$cur['id'] ?>">
        <button class="btn"><?= h($lab) ?></button></form>
    <?php endforeach; ?>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="act" value="resend"><input type="hidden" name="id" value="<?= (int)$cur['id'] ?>">
      <button class="btn">고객 알림 다시 보내기</button></form>
    <a class="btn" href="?p=shipment_form">전표 등록</a>
  </div>
  <form method="post" class="cb" style="border-top:1px solid var(--line2)">
    <?= csrf_field() ?><input type="hidden" name="act" value="memo"><input type="hidden" name="id" value="<?= (int)$cur['id'] ?>">
    <div class="f" style="align-items:flex-end">
      <div class="fw w3"><label>ERP 거래처 연결</label>
        <select name="company_id"><option value="">— 연결 안 함 —</option>
          <?php foreach ($companies as $co): ?>
            <option value="<?= (int)$co['id'] ?>"<?= (int)$cur['company_id'] === (int)$co['id'] ? ' selected' : '' ?>><?= h($co['name_ko']) ?> (<?= h($co['company_code']) ?>)</option>
          <?php endforeach; ?></select></div>
      <div class="fw gr" style="min-width:260px"><label>담당자 메모</label>
        <textarea name="staff_memo" rows="2"><?= h($cur['staff_memo'] ?? '') ?></textarea></div>
      <button class="btn">메모 저장</button>
    </div>
    <div style="font-size:11.5px;color:var(--ink3);margin-top:6px">처리: <?= h($cur['handler'] ?: '-') ?> <?= h($cur['handled_at'] ?: '') ?></div>
  </form>
  <?php endif; ?>
  <?php if ($cur['notify_log']): ?>
  <details class="cb" style="border-top:1px solid var(--line2)"><summary style="cursor:pointer;font-size:12.5px;font-weight:600">알림 보낸 기록</summary>
    <pre style="white-space:pre-wrap;font-size:11.5px;margin:6px 0 0"><?= h($cur['notify_log']) ?></pre></details>
  <?php endif; ?>
</div>
<?php endif; ?>

<div class="card">
  <div class="ch">
    <a class="btn sm<?= $sel === '' ? ' pri' : '' ?>" href="?p=web_pickups">전체 <?= array_sum($cnt) ?></a>
    <?php foreach (PICKUP_STATUS as $k => [$lab, $cls]): ?>
      <a class="btn sm<?= $sel === $k ? ' pri' : '' ?>" href="?p=web_pickups&amp;status=<?= $k ?>"><?= h($lab) ?> <?= (int)($cnt[$k] ?? 0) ?></a>
    <?php endforeach; ?>
    <form method="get" style="margin-left:auto;display:flex;gap:6px">
      <input type="hidden" name="p" value="web_pickups"><?php if ($sel !== ''): ?><input type="hidden" name="status" value="<?= h($sel) ?>"><?php endif; ?>
      <input type="text" name="kw" value="<?= h($kw) ?>" placeholder="회사 · 담당자 · 연락처 · 접수번호" style="width:220px">
      <button class="btn sm">검색</button></form>
  </div>
  <?php if (!$rows): ?>
    <div class="empty">접수된 건이 없습니다. 홈페이지 <b>온라인접수</b> 에서 신청하면 여기에 쌓이고 담당자에게 알림이 갑니다.</div>
  <?php else: ?>
  <table>
    <thead><tr><th style="width:120px">접수번호</th><th style="width:130px">접수 시각</th><th>회사</th><th>담당자 · 연락처</th>
      <th style="width:130px">픽업 일시</th><th>주소</th><th class="c" style="width:90px">상태</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): [$sl, $sc] = PICKUP_STATUS[$r['status']] ?? [$r['status'], 'b-info']; ?>
      <tr<?= $r['status'] === 'NEW' ? ' style="background:#FFFBEA"' : '' ?>>
        <td class="tnum" style="font-weight:600"><a href="?p=web_pickups&amp;id=<?= (int)$r['id'] ?>"><?= h($r['req_no']) ?></a></td>
        <td class="tnum"><?= h(substr((string)$r['created_at'], 0, 16)) ?></td>
        <td style="font-weight:600"><?= h($r['company_name']) ?></td>
        <td><?= h($r['manager']) ?> <span class="tnum" style="color:var(--ink2)"><?= h($r['phone']) ?></span></td>
        <td class="tnum"><?= h(pickup_when($r)) ?></td>
        <td style="font-size:12px"><?= h(mb_strimwidth((string)$r['address'], 0, 60, '…')) ?></td>
        <td class="c"><span class="badge <?= $sc ?>"><?= h($sl) ?></span></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>

<div class="card">
  <div class="ch">알림 설정 상태
    <?php if ($canSet): ?><a class="btn sm" style="margin-left:auto" href="?p=settings">환경설정 → 알림 (메일 · 카톡)</a><?php endif; ?></div>
  <div class="cb" style="font-size:12.5px;line-height:1.9">
    <?php $has = fn($k) => ($nc[$k] ?? '') !== '' ? '<span class="badge b-ok">있음</span>' : '<span class="badge b-warn">없음</span>'; ?>
    담당자 받는 메일 <?= $has('notify_staff_emails') ?> · 담당자 휴대폰 <?= $has('notify_staff_phones') ?>
    · 메일 서버(SMTP) <?= $has('smtp_pass') ?> · 카톡/문자(SOLAPI) <?= $has('solapi_api_secret') ?>
    · 카카오 채널 <?= $has('solapi_pfid') ?> · 알림톡 템플릿(담당/접수/확인) <?= $has('solapi_tpl_pickup_staff') ?> <?= $has('solapi_tpl_pickup_received') ?> <?= $has('solapi_tpl_pickup_confirm') ?>
    <div style="color:var(--ink3)">알림톡 템플릿이 없으면 같은 내용이 <b>문자</b>로 갑니다. 템플릿 변수: #{접수번호} #{회사명} #{담당자} #{연락처} #{픽업일시} #{주소} #{무게} #{안내}</div>
  </div>
  <?php if ($canSet): ?>
  <div class="cb" style="border-top:1px solid var(--line2);display:flex;gap:10px;flex-wrap:wrap">
    <form method="post" class="f" style="align-items:flex-end"><?= csrf_field() ?><input type="hidden" name="act" value="test_mail">
      <div class="fw w2"><label>메일 시험 (비우면 담당자 메일로)</label><input type="text" name="to" placeholder="받을 메일 주소"></div>
      <button class="btn">메일 시험 발송</button></form>
    <form method="post" class="f" style="align-items:flex-end"><?= csrf_field() ?><input type="hidden" name="act" value="test_kakao">
      <div class="fw w2"><label>문자 · 카톡 시험 (비우면 담당자 번호로)</label><input type="text" name="to" placeholder="010-0000-0000"></div>
      <button class="btn">문자 시험 발송</button></form>
  </div>
  <?php endif; ?>
</div>
<?php layout_foot();
