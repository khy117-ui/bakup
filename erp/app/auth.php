<?php
declare(strict_types=1);

// 웹에서 직접 열면 실행되지 않게 막습니다 (nginx 면 .htaccess 가 무시됩니다)
if (!defined('APP_DIR')) { http_response_code(403); exit('Forbidden'); }

function current_admin(): ?array
{
    if (empty($_SESSION['admin_id'])) {
        return null;
    }
    $st = db()->prepare(
        'SELECT id, login_id, name, role_code, team
           FROM admins
          WHERE id = ? AND is_active = 1 AND deleted_at IS NULL');
    $st->execute([$_SESSION['admin_id']]);
    $a = $st->fetch();
    return $a ?: null;
}

function require_login(): array
{
    $a = current_admin();
    if (!$a) {
        session_unset();
        redirect('?p=login');
    }
    return $a;
}

function attempt_login(string $loginId, string $password): string
{
    global $CFG;
    $pdo = db();
    $st = $pdo->prepare(
        'SELECT id, login_id, name, role_code, password_hash, fail_count, locked_at,
                is_active
           FROM admins
          WHERE login_id = ? AND deleted_at IS NULL LIMIT 1');
    $st->execute([$loginId]);
    $a = $st->fetch();

    // 계정이 없어도 같은 메시지를 보여줍니다. 아이디 존재 여부를 알려주지 않습니다
    if (!$a) {
        return '아이디 또는 비밀번호가 맞지 않습니다.';
    }
    if ((int)$a['is_active'] !== 1) {
        return '사용이 중지된 계정입니다.';
    }
    if ($a['locked_at'] !== null) {
        $until = strtotime($a['locked_at']) + $CFG['login_lock_min'] * 60;
        if (time() < $until) {
            $min = (int)ceil(($until - time()) / 60);
            return "로그인 실패가 많아 잠긴 계정입니다. {$min}분 후 다시 시도하세요.";
        }
    }

    if (!password_verify($password, (string)$a['password_hash'])) {
        $fail = (int)$a['fail_count'] + 1;
        if ($fail >= $CFG['login_max_fail']) {
            $pdo->prepare('UPDATE admins SET fail_count = ?, locked_at = NOW() WHERE id = ?')
                ->execute([$fail, $a['id']]);
        } else {
            $pdo->prepare('UPDATE admins SET fail_count = ? WHERE id = ?')
                ->execute([$fail, $a['id']]);
        }
        return '아이디 또는 비밀번호가 맞지 않습니다.';
    }

    // 성공
    $pdo->prepare(
        'UPDATE admins
            SET fail_count = 0, locked_at = NULL, last_login_at = NOW(), last_login_ip = ?
          WHERE id = ?')
        ->execute([$_SERVER['REMOTE_ADDR'] ?? '-', $a['id']]);

    session_regenerate_id(true);
    $_SESSION['admin_id']   = (int)$a['id'];
    $_SESSION['admin_name'] = $a['name'];
    $_SESSION['role']       = $a['role_code'];
    log_action('시스템', 'LOGIN', 'admins', (int)$a['id'], $a['login_id']);
    return '';
}

function logout(): void
{
    if (!empty($_SESSION['admin_id'])) {
        log_action('시스템', 'LOGOUT', 'admins', (int)$_SESSION['admin_id'],
                   $_SESSION['admin_name'] ?? null);
    }
    session_unset();
    session_destroy();
}
