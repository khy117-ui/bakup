<?php
/**
 * 최초 설치 — 스키마 적용과 첫 관리자 계정 생성.
 *
 * 이 파일은 설치가 끝나면 스스로 닫힙니다.
 *   · 관리자 계정이 하나라도 있으면 403
 *   · 그 전에도 GP_SETUP_TOKEN 환경변수와 맞는 토큰이 있어야 열립니다
 *
 * 설치가 끝나면 이 파일을 지우세요. 남아 있어도 위 두 조건 때문에 열리지 않습니다.
 */
declare(strict_types=1);

require __DIR__ . '/app/bootstrap.php';

/**
 * 설치 토큰.
 *
 * 환경변수로 두지 않습니다 — AI Space 에서 사용자 환경변수를 설정하면
 * 자동 주입되던 DB_* 변수가 지워지는 일이 있었습니다.
 * 그래서 토큰의 해시만 여기 박아 두고, 실제 토큰은 URL 로 받습니다.
 */
const SETUP_TOKEN_HASH = '4d35b9aa356ff39a3faef7d93c233ae27bf18371adfca025b28a22008efdcad7';

$given = (string)($_REQUEST['token'] ?? '');

if ($given === '' || !hash_equals(SETUP_TOKEN_HASH, hash('sha256', $given))) {
    http_response_code(403);
    exit('Forbidden');
}

// ---------------------------------------------------------------- 환경 진단
// DB 에 붙기 전에 실행됩니다. 비밀번호는 값이 아니라 '설정됨/없음' 만 보여줍니다.
if ((string)($_REQUEST['step'] ?? '') === 'env') {
    header('Content-Type: text/plain; charset=utf-8');
    foreach (['DB_HOST','DB_PORT','DB_NAME','DB_USER','DB_USERNAME','DB_PASSWORD',
              'DATABASE_URL','MYSQL_HOST','MYSQL_PORT','MYSQL_DATABASE','MYSQL_USER',
              'DB_SOCKET','GP_SETUP_TOKEN'] as $k) {
        $v = getenv($k);
        if ($v === false) { echo str_pad($k, 16) . ': (없음)' . PHP_EOL; continue; }
        $show = in_array($k, ['DB_PASSWORD','GP_SETUP_TOKEN'], true)
              ? '(설정됨, ' . strlen($v) . '자)' : $v;
        echo str_pad($k, 16) . ': ' . $show . PHP_EOL;
    }
    echo PHP_EOL . 'DB_ 로 시작하는 환경변수 전체:' . PHP_EOL;
    foreach ($_ENV as $k => $v) {
        if (strpos($k, 'DB') === 0 || strpos($k, 'MYSQL') === 0) {
            echo '  ' . $k . PHP_EOL;
        }
    }
    echo PHP_EOL . 'pdo 드라이버: ' . implode(', ', PDO::getAvailableDrivers()) . PHP_EOL;

    // 실제 접속을 시도해 진짜 오류 메시지를 보여줍니다
    $h = getenv('DB_HOST') ?: '127.0.0.1';
    $pt = (int)(getenv('DB_PORT') ?: 3306);
    $n = getenv('DB_NAME') ?: '';
    $u = getenv('DB_USER') ?: '';
    $pw = getenv('DB_PASSWORD') ?: '';
    echo PHP_EOL . '접속 방식별 시도' . PHP_EOL;
    // MySQL 은 localhost(소켓)와 127.0.0.1(TCP)을 다른 호스트로 봅니다.
    // 계정이 어느 쪽으로 만들어졌는지 확인합니다.
    $tries = [
        'DB_HOST 그대로'  => 'mysql:host=' . $h . ';port=' . $pt . ';dbname=' . $n . ';charset=utf8mb4',
        'localhost (소켓)' => 'mysql:host=localhost;dbname=' . $n . ';charset=utf8mb4',
        'localhost + 포트' => 'mysql:host=localhost;port=' . $pt . ';dbname=' . $n . ';charset=utf8mb4',
        'db 호스트명'      => 'mysql:host=db;port=' . $pt . ';dbname=' . $n . ';charset=utf8mb4',
        'mysql 호스트명'   => 'mysql:host=mysql;port=' . $pt . ';dbname=' . $n . ';charset=utf8mb4',
    ];
    foreach ($tries as $label => $dsn) {
        echo '  ' . str_pad($label, 18) . ' ';
        try {
            $c = new PDO($dsn, $u, $pw, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                                          PDO::ATTR_TIMEOUT => 4]);
            echo '성공  (' . $c->query('SELECT VERSION()')->fetchColumn() . ')' . PHP_EOL;
        } catch (PDOException $e) {
            echo '실패  ' . substr($e->getMessage(), 0, 110) . PHP_EOL;
        }
    }
    // 주입된 비밀번호가 배포 때 발급된 것과 같은지 해시로만 대조합니다
    echo PHP_EOL . '주입된 비밀번호' . PHP_EOL;
    echo '  길이      : ' . strlen($pw) . '자' . PHP_EOL;
    echo '  앞뒤 공백 : ' . ($pw !== trim($pw) ? '있음' : '없음') . PHP_EOL;
    echo '  해시 앞8  : ' . substr(hash('sha256', $pw), 0, 8) . PHP_EOL;

    // 플랫폼이 자격증명을 파일로 떨궈 두었는지 찾아봅니다
    echo PHP_EOL . '자격증명이 있을 만한 파일' . PHP_EOL;
    $cands = ['/app/.env', '/app/user_data/.env', '/.env', '/app/config/db.php',
              '/etc/mysql/my.cnf', '/etc/my.cnf', '/root/.my.cnf',
              getenv('HOME') . '/.my.cnf', '/app/db.json', '/app/credentials.json'];
    foreach ($cands as $f) {
        echo '  ' . str_pad($f, 30) . (is_file($f) ? (is_readable($f) ? '있음·읽기가능' : '있음·읽기불가') : '없음') . PHP_EOL;
    }
    // /app 바로 아래 숨김파일 목록 (값이 아니라 이름만)
    echo PHP_EOL . '/app 아래 항목:' . PHP_EOL;
    foreach ((array)@scandir('/app') as $e) {
        if ($e === '.' || $e === '..') { continue; }
        echo '  ' . $e . (is_dir('/app/' . $e) ? '/' : '') . PHP_EOL;
    }

    // 소켓 파일이 있는지도 봅니다
    echo PHP_EOL . '소켓 후보:' . PHP_EOL;
    foreach (['/var/run/mysqld/mysqld.sock','/tmp/mysql.sock','/var/lib/mysql/mysql.sock',
              '/run/mysqld/mysqld.sock'] as $sk) {
        echo '  ' . str_pad($sk, 34) . (file_exists($sk) ? '있음' : '없음') . PHP_EOL;
    }
    echo PHP_EOL . 'pdo_mysql 기본 소켓: ' . (ini_get('pdo_mysql.default_socket') ?: '(없음)') . PHP_EOL;
    exit;
}

/** 관리자가 이미 있으면 설치는 끝난 것 */
function already_installed(): bool
{
    try {
        $n = (int)db()->query('SELECT COUNT(*) FROM admins')->fetchColumn();
        return $n > 0;
    } catch (PDOException $e) {
        return false;   // 테이블 자체가 없으면 아직 설치 전
    }
}

if (already_installed() && ($_REQUEST['step'] ?? '') !== 'done') {
    http_response_code(403);
    exit('이미 설치가 끝났습니다. 이 파일을 지우세요.');
}

$step = (string)($_REQUEST['step'] ?? '');
$msg  = '';
$err  = '';

// ---------------------------------------------------------------- 스키마 적용
if ($step === 'schema') {
    header('Content-Type: text/plain; charset=utf-8');
    $file = __DIR__ . '/sql/install.sql';
    if (!is_file($file)) {
        exit("install.sql 이 없습니다.\n");
    }
    $sql = file_get_contents($file);
    if ($sql === false) {
        exit("install.sql 을 읽지 못했습니다.\n");
    }

    // 문장 단위로 쪼갠다. 문자열 안의 세미콜론을 건너뛰어야 한다
    $stmts = [];
    $buf = '';
    $len = strlen($sql);
    $i = 0;
    while ($i < $len) {
        $c = $sql[$i];
        if ($c === "'" || $c === '"' || $c === '`') {
            $q = $c;
            $buf .= $c;
            $i++;
            while ($i < $len) {
                if ($sql[$i] === "\\") { $buf .= substr($sql, $i, 2); $i += 2; continue; }
                $buf .= $sql[$i];
                if ($sql[$i] === $q) { $i++; break; }
                $i++;
            }
            continue;
        }
        if ($c === '-' && substr($sql, $i, 2) === '--') {
            $j = strpos($sql, "\n", $i);
            $i = $j === false ? $len : $j;
            continue;
        }
        if ($c === ';') {
            $t = trim($buf);
            if ($t !== '') { $stmts[] = $t; }
            $buf = '';
            $i++;
            continue;
        }
        $buf .= $c;
        $i++;
    }
    if (trim($buf) !== '') { $stmts[] = trim($buf); }

    $pdo = db();
    $ok = 0; $fail = 0;
    echo "문장 " . count($stmts) . "개를 실행합니다.\n\n";
    foreach ($stmts as $n => $st) {
        try {
            $pdo->exec($st);
            $ok++;
        } catch (PDOException $e) {
            $fail++;
            $head = preg_replace('/\s+/', ' ', substr($st, 0, 90));
            echo "X [" . ($n + 1) . "] " . $head . "\n    " . $e->getMessage() . "\n";
            if ($fail > 12) { echo "\n오류가 너무 많아 중단합니다.\n"; break; }
        }
    }
    echo "\n성공 $ok · 실패 $fail\n\n";

    // 결과 요약
    try {
        $q = function (string $s) use ($pdo) {
            return (string)$pdo->query($s)->fetchColumn();
        };
        echo "서버      : " . $q('SELECT VERSION()') . "\n";
        echo "DB        : " . $q('SELECT DATABASE()') . "\n";
        echo "테이블    : " . $q("SELECT COUNT(*) FROM information_schema.TABLES
                                   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE='BASE TABLE'")
             . " (기대 40)\n";
        echo "뷰        : " . $q('SELECT COUNT(*) FROM information_schema.VIEWS
                                   WHERE TABLE_SCHEMA = DATABASE()') . " (기대 3)\n";
        echo "외래키    : " . $q("SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
                                   WHERE TABLE_SCHEMA = DATABASE()
                                     AND CONSTRAINT_TYPE='FOREIGN KEY'") . " (기대 54)\n";
        echo "collation : " . $q("SELECT GROUP_CONCAT(DISTINCT COLLATION_NAME)
                                   FROM information_schema.COLUMNS
                                  WHERE TABLE_SCHEMA = DATABASE()
                                    AND COLLATION_NAME IS NOT NULL") . "\n";
        echo "사업자    : " . $q('SELECT COUNT(*) FROM business_entities') . " (기대 2)\n";
        echo "운송사    : " . $q('SELECT COUNT(*) FROM carriers') . " (기대 4)\n";
        echo "권한항목  : " . $q('SELECT COUNT(*) FROM permissions') . " (기대 43)\n";
        echo "역할권한  : " . $q('SELECT COUNT(*) FROM role_permissions') . " (기대 140)\n";
        echo "문서종류  : " . $q('SELECT COUNT(*) FROM document_types') . " (기대 9)\n";
    } catch (PDOException $e) {
        echo "확인 쿼리 실패: " . $e->getMessage() . "\n";
    }
    exit;
}

// ---------------------------------------------------------------- 첫 관리자
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $step === 'admin') {
    $loginId = trim((string)($_POST['login_id'] ?? ''));
    $name    = trim((string)($_POST['name'] ?? ''));
    $pw1     = (string)($_POST['password'] ?? '');
    $pw2     = (string)($_POST['password2'] ?? '');

    if ($loginId === '' || $name === '') {
        $err = '아이디와 이름을 입력하세요.';
    } elseif (!preg_match('/^[A-Za-z0-9_.-]{3,50}$/', $loginId)) {
        $err = '아이디는 영문·숫자·_.- 로 3~50자입니다.';
    } elseif (strlen($pw1) < 10) {
        $err = '비밀번호는 10자 이상으로 하세요.';
    } elseif (!hash_equals($pw1, $pw2)) {
        $err = '두 번 입력한 비밀번호가 다릅니다.';
    } else {
        try {
            $hash = password_hash($pw1, PASSWORD_DEFAULT);
            db()->prepare(
                'INSERT INTO admins (login_id, password_hash, name, role_code, is_active)
                 VALUES (?,?,?,\'SUPER_ADMIN\',1)')
                ->execute([$loginId, $hash, $name]);
            $id = (int)db()->lastInsertId();
            db()->prepare(
                'INSERT INTO activity_logs (admin_id, admin_name, ip, module, ref_table,
                                            ref_id, ref_label, action)
                 VALUES (?,?,?,?,?,?,?,?)')
                ->execute([$id, $name, $_SERVER['REMOTE_ADDR'] ?? '-', '시스템',
                           'admins', $id, $loginId, 'CREATE']);
            $msg = '관리자 계정을 만들었습니다. 이제 로그인하세요.';
        } catch (PDOException $e) {
            $err = '만들지 못했습니다: ' . $e->getMessage();
        }
    }
}

$tokenSafe = htmlspecialchars($given, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?><!doctype html>
<html lang="ko">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>최초 설치 · GOODPOST</title>
<link rel="stylesheet" href="assets/app.css">
</head>
<body>
<div class="login">
  <div class="box" style="width:440px">
    <h2>최초 관리자 계정</h2>
    <p>이 계정으로 시스템 전체를 쓸 수 있습니다. 비밀번호는 <b>직접</b> 정하세요 —
       아무도 대신 입력하지 않습니다.</p>

    <?php if ($msg !== ''): ?>
      <div class="msg ok" style="margin-bottom:14px"><?= htmlspecialchars($msg) ?></div>
      <a class="btn pri" style="width:100%" href="?p=login">로그인하러 가기</a>
      <p style="margin-top:14px;font-size:11.5px;color:var(--ink3)">
        설치가 끝났습니다. 이 페이지는 이제 열리지 않습니다.</p>
    <?php else: ?>
      <?php if ($err !== ''): ?>
        <div class="msg err" style="margin-bottom:14px"><?= htmlspecialchars($err) ?></div>
      <?php endif; ?>
      <form method="post">
        <input type="hidden" name="token" value="<?= $tokenSafe ?>">
        <input type="hidden" name="step" value="admin">
        <div class="fw" style="width:100%;margin-bottom:12px">
          <label for="lid">아이디</label>
          <input type="text" id="lid" name="login_id" required autofocus
                 value="<?= htmlspecialchars((string)($_POST['login_id'] ?? '')) ?>">
        </div>
        <div class="fw" style="width:100%;margin-bottom:12px">
          <label for="nm">이름</label>
          <input type="text" id="nm" name="name" required
                 value="<?= htmlspecialchars((string)($_POST['name'] ?? '')) ?>">
        </div>
        <div class="fw" style="width:100%;margin-bottom:12px">
          <label for="pw">비밀번호 (10자 이상)</label>
          <input type="password" id="pw" name="password" required minlength="10">
        </div>
        <div class="fw" style="width:100%;margin-bottom:18px">
          <label for="pw2">비밀번호 확인</label>
          <input type="password" id="pw2" name="password2" required minlength="10">
        </div>
        <button class="btn pri" style="width:100%">만들기</button>
      </form>
    <?php endif; ?>
  </div>
</div>
</body>
</html>
