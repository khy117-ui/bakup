<?php
/**
 * 관리자 비밀번호 설정 (Basic 인증용 htpasswd 파일 생성 · 변경)
 *
 *   파일 위치 : /app/user_data/goodpost.htpasswd  (없으면 프로젝트의 data/ — 로컬 테스트용)
 *   최초 생성 : 서버 환경변수 ADMIN_SETUP_TOKEN 값을 입력해야 합니다. (AI SPACE 콘솔 · project_env 로 설정)
 *   변경      : 파일이 이미 있으면 Apache 가 먼저 로그인(Basic 인증)을 요구하므로, 로그인한 관리자만 바꿀 수 있습니다.
 */
declare(strict_types=1);
header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store');

$dir = is_dir('/app/user_data') ? '/app/user_data' : dirname(__DIR__) . '/data';
$file = $dir . '/goodpost.htpasswd';
$exists = is_file($file);
$loggedIn = !empty($_SERVER['REMOTE_USER']) || !empty($_SERVER['PHP_AUTH_USER']);
$setupToken = (string) getenv('ADMIN_SETUP_TOKEN');
$msg = '';
$err = '';

function h(?string $s): string { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user = trim((string) ($_POST['user'] ?? ''));
    $pw = (string) ($_POST['password'] ?? '');
    $pw2 = (string) ($_POST['password2'] ?? '');
    $token = (string) ($_POST['token'] ?? '');
    if ($exists && !$loggedIn) {
        $err = '이미 비밀번호가 설정되어 있습니다. 관리자 로그인 후 변경할 수 있습니다.';
    } elseif (!$exists && ($setupToken === '' || !hash_equals($setupToken, $token))) {
        $err = $setupToken === '' ? '서버 환경변수 ADMIN_SETUP_TOKEN 이 설정되어 있지 않습니다.' : '설정 토큰이 맞지 않습니다.';
    } elseif (!preg_match('/^[A-Za-z0-9_.-]{3,32}$/', $user)) {
        $err = '아이디는 영문 · 숫자 3~32자로 입력해 주세요.';
    } elseif (strlen($pw) < 8) {
        $err = '비밀번호는 8자 이상이어야 합니다.';
    } elseif ($pw !== $pw2) {
        $err = '비밀번호 확인이 일치하지 않습니다.';
    } else {
        if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
            $err = '비밀번호 파일 폴더를 만들 수 없습니다: ' . $dir;
        } else {
            $line = $user . ':' . password_hash($pw, PASSWORD_BCRYPT) . "\n";   // Apache 2.4 는 $2y$ bcrypt 지원
            if (file_put_contents($file, $line, LOCK_EX) === false) {
                $err = '비밀번호 파일을 저장하지 못했습니다. 폴더 쓰기 권한을 확인하세요.';
            } else {
                chmod($file, 0640);
                $exists = true;
                $msg = ($loggedIn ? '비밀번호를 변경했습니다.' : '관리자 비밀번호를 만들었습니다.') . ' 이제 /admin/ 은 어디서 접속하든 로그인이 필요합니다. 새로고침하면 로그인 창이 뜹니다.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>관리자 비밀번호 설정 | 굿배송항공 GOODPOST</title>
<link rel="stylesheet" href="../assets/css/style.css">
<style>
.wrap { max-width: 560px; margin: 0 auto; padding: 40px var(--gutter) 60px; }
.field { margin-bottom: 14px; } .field label { display: block; font-size: 13px; font-weight: 700; color: var(--navy); margin-bottom: 6px; }
.field input { width: 100%; padding: 11px 12px; border: 1px solid var(--line-2); border-radius: 10px; font: inherit; font-size: 14px; }
.flash { border-radius: 10px; padding: 12px 16px; margin-bottom: 16px; font-size: 14px; background: var(--tint); color: var(--navy); }
.flash--err { background: var(--warn-bg); color: var(--warn); }
</style>
</head>
<body>
<header class="site-header"><div class="container"><a class="logo" href="../index.html"><img src="../assets/img/logo.png" alt="GOODPOST"></a><div class="header-tools" style="margin-left:auto"><a class="btn btn--outline btn--sm btn--square" href="rates.html">관리자 홈</a></div></div></header>
<main class="wrap">
  <span class="eyebrow">ADMIN PASSWORD</span>
  <h1 class="h2" style="margin-bottom:8px"><?= $exists ? '관리자 비밀번호 변경' : '관리자 비밀번호 만들기' ?></h1>
  <p class="note" style="margin-bottom:20px">저장 위치: <code><?= h($file) ?></code> · 상태: <?= $exists ? '설정됨' : '아직 없음 (지금은 기본 도메인 · 허용 IP 에서만 /admin/ 이 열립니다)' ?></p>
  <?php if ($msg): ?><div class="flash"><?= h($msg) ?></div><?php endif; ?>
  <?php if ($err): ?><div class="flash flash--err"><?= h($err) ?></div><?php endif; ?>
  <?php if ($exists && !$loggedIn): ?>
    <p class="note">비밀번호가 이미 설정되어 있습니다. 관리자 로그인 후 이 페이지에서 변경할 수 있습니다.</p>
  <?php else: ?>
  <form method="post" class="card" autocomplete="off">
    <?php if (!$exists): ?>
    <div class="field"><label for="token">설정 토큰 (서버 환경변수 ADMIN_SETUP_TOKEN)</label><input id="token" name="token" type="password" required></div>
    <?php endif; ?>
    <div class="field"><label for="user">관리자 아이디</label><input id="user" name="user" type="text" required pattern="[A-Za-z0-9_.-]{3,32}" value="<?= h($_SERVER['REMOTE_USER'] ?? $_SERVER['PHP_AUTH_USER'] ?? 'admin') ?>"></div>
    <div class="field"><label for="password">새 비밀번호 (8자 이상)</label><input id="password" name="password" type="password" required minlength="8"></div>
    <div class="field"><label for="password2">비밀번호 확인</label><input id="password2" name="password2" type="password" required minlength="8"></div>
    <div class="form-actions"><button class="btn btn--navy btn--sm btn--square" type="submit"><?= $exists ? '변경' : '만들기' ?></button></div>
  </form>
  <?php endif; ?>
</main>
</body>
</html>
