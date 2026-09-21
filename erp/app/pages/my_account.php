<?php
// 웹에서 직접 열면 실행되지 않게 막습니다 (nginx 면 .htaccess 가 무시됩니다)
if (!defined('APP_DIR')) { http_response_code(403); exit('Forbidden'); }

require_once APP_DIR . '/layout.php';

/**
 * 내 정보 — 비밀번호 바꾸기.
 * 관리자가 만들어 준 임시 비밀번호로 처음 들어오면 여기로 먼저 옵니다.
 */

$me  = (int)($_SESSION['admin_id'] ?? 0);
$err = '';
$mustChange = (int)($ADMIN['must_change_pw'] ?? 0) === 1;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('act') === 'password') {
    csrf_check();
    $cur = (string)($_POST['current'] ?? '');
    $pw1 = (string)($_POST['password'] ?? '');
    $pw2 = (string)($_POST['password2'] ?? '');

    $st = db()->prepare('SELECT password_hash FROM admins WHERE id = ?');
    $st->execute([$me]);
    $hash = (string)$st->fetchColumn();

    if (!password_verify($cur, $hash)) {
        $err = '지금 비밀번호가 맞지 않습니다.';
    } elseif (strlen($pw1) < 8 || !preg_match('/[A-Za-z]/', $pw1) || !preg_match('/[0-9]/', $pw1)) {
        $err = '새 비밀번호는 8자 이상, 영문과 숫자를 섞어 주세요.';
    } elseif (!hash_equals($pw1, $pw2)) {
        $err = '새 비밀번호 두 번이 서로 다릅니다.';
    } elseif (password_verify($pw1, $hash)) {
        $err = '지금 비밀번호와 다른 것으로 정해 주세요.';
    } else {
        db()->prepare('UPDATE admins SET password_hash = ?, must_change_pw = 0 WHERE id = ?')
            ->execute([password_hash($pw1, PASSWORD_DEFAULT), $me]);
        log_action('시스템', 'UPDATE', 'admins', $me, $ADMIN['login_id'] ?? null, null, '본인 비밀번호 변경');
        session_regenerate_id(true);
        flash('비밀번호를 바꿨습니다.');
        redirect('?p=dashboard');
    }
}

$ROLE_KO = [
    'SUPER_ADMIN' => '최고관리자', 'MANAGER' => '관리자', 'SALES' => '영업', 'LOGISTICS' => '물류',
    'ACCOUNTING' => '회계', 'BOARD_MANAGER' => '게시판', 'VIEWER' => '조회전용',
];

layout_head('내 정보', 'my_account');
?>
<div class="head">
  <h1>내 정보</h1>
  <div class="crumb"><?= h($ADMIN['name'] ?? '') ?> (<?= h($ADMIN['login_id'] ?? '') ?>)</div>
</div>

<?php if ($mustChange): ?>
  <div class="msg err">관리자가 정해 준 <b>임시 비밀번호</b>로 들어왔습니다. 새 비밀번호로 바꿔야 다른 화면을 쓸 수 있습니다.</div>
<?php endif; ?>
<?php if ($err !== ''): ?><div class="msg err"><?= h($err) ?></div><?php endif; ?>

<div class="card">
  <div class="ch">계정</div>
  <div class="cb f" style="gap:24px">
    <div><div style="font-size:11px;color:var(--ink2)">아이디</div>
      <div style="font-weight:600"><?= h($ADMIN['login_id'] ?? '') ?></div></div>
    <div><div style="font-size:11px;color:var(--ink2)">이름</div>
      <div><?= h($ADMIN['name'] ?? '') ?></div></div>
    <div><div style="font-size:11px;color:var(--ink2)">역할</div>
      <div><?= h($ROLE_KO[$ADMIN['role_code'] ?? ''] ?? ($ADMIN['role_code'] ?? '')) ?>
        <?php if ((int)($ADMIN['perm_custom'] ?? 0) === 1): ?>
          <span class="badge b-info">계정별 권한</span>
        <?php endif; ?></div></div>
    <div><div style="font-size:11px;color:var(--ink2)">마지막 로그인</div>
      <div class="tnum"><?= h($ADMIN['last_login_at'] ?? '-') ?></div></div>
  </div>
</div>

<div class="card">
  <div class="ch">비밀번호 바꾸기</div>
  <div class="cb">
    <form method="post" class="f" style="align-items:flex-end" autocomplete="off">
      <?= csrf_field() ?>
      <input type="hidden" name="act" value="password">
      <div class="fw w2"><label for="cur">지금 비밀번호</label>
        <input type="password" id="cur" name="current" required autocomplete="current-password"></div>
      <div class="fw w2"><label for="pw1">새 비밀번호 (8자 이상, 영문+숫자)</label>
        <input type="password" id="pw1" name="password" required minlength="8" autocomplete="new-password"></div>
      <div class="fw w2"><label for="pw2">새 비밀번호 확인</label>
        <input type="password" id="pw2" name="password2" required minlength="8" autocomplete="new-password"></div>
      <button class="btn pri">바꾸기</button>
    </form>
  </div>
</div>
<?php layout_foot();
