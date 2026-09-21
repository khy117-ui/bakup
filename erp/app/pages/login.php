<?php
// 웹에서 직접 열면 실행되지 않게 막습니다 (nginx 면 .htaccess 가 무시됩니다)
if (!defined('APP_DIR')) { http_response_code(403); exit('Forbidden'); }
global $CFG;
$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $err = attempt_login(post('login_id'), (string)($_POST['password'] ?? ''));
    if ($err === '') {
        redirect('?p=dashboard');
    }
}
if (current_admin()) {
    redirect('?p=dashboard');
}
?><!doctype html>
<html lang="ko">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>로그인 · <?= h($CFG['app_name']) ?></title>
<link rel="stylesheet" href="<?= h(asset_v('assets/app.css')) ?>">
</head>
<body>
<div class="login">
  <div class="box">
    <h2><?= h($CFG['app_name']) ?></h2>
    <p>사내 전용입니다. 계정이 없으면 관리자에게 요청하세요.</p>
    <?php if ($err !== ''): ?>
      <div class="msg err" style="margin-bottom:14px"><?= h($err) ?></div>
    <?php endif; ?>
    <form method="post" autocomplete="off">
      <?= csrf_field() ?>
      <div class="fw" style="width:100%;margin-bottom:12px">
        <label for="lid">아이디</label>
        <input type="text" id="lid" name="login_id" required autofocus
               value="<?= h(post('login_id')) ?>">
      </div>
      <div class="fw" style="width:100%;margin-bottom:18px">
        <label for="pw">비밀번호</label>
        <input type="password" id="pw" name="password" required>
      </div>
      <button class="btn pri" style="width:100%">로그인</button>
    </form>
  </div>
</div>
</body>
</html>
