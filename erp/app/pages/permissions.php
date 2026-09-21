<?php
// 웹에서 직접 열면 실행되지 않게 막습니다 (nginx 면 .htaccess 가 무시됩니다)
if (!defined('APP_DIR')) { http_response_code(403); exit('Forbidden'); }

require_once APP_DIR . '/layout.php';

$err = '';
$tab = query('tab', 'admin') === 'role' ? 'role' : 'admin';

$ROLES = [
    'SUPER_ADMIN'   => '최고관리자',
    'MANAGER'       => '관리자',
    'SALES'         => '영업',
    'LOGISTICS'     => '물류',
    'ACCOUNTING'    => '회계',
    'BOARD_MANAGER' => '게시판',
    'VIEWER'        => '조회전용',
];

$isSuper = is_super();

/** 대상 계정 한 줄 */
function pm_admin(int $id): ?array
{
    $st = db()->prepare('SELECT * FROM admins WHERE id = ? AND deleted_at IS NULL');
    $st->execute([$id]);
    $a = $st->fetch();
    return $a ?: null;
}

/** 임시 비밀번호 규칙 — 본인이 첫 로그인 때 바꿉니다 */
function pm_pw_error(string $pw1, string $pw2): string
{
    if (strlen($pw1) < 8 || !preg_match('/[A-Za-z]/', $pw1) || !preg_match('/[0-9]/', $pw1)) {
        return '임시 비밀번호는 8자 이상, 영문과 숫자를 섞어 주세요.';
    }
    if (!hash_equals($pw1, $pw2)) {
        return '임시 비밀번호 두 번이 서로 다릅니다.';
    }
    return '';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $act = post('act');
    $pdo = db();
    $me  = (int)($_SESSION['admin_id'] ?? 0);
    // 최고관리자 계정은 최고관리자만 손댈 수 있습니다 (권한을 스스로 올리는 길을 막습니다)
    $tgtId = (int)post('id');
    $tgt = $tgtId > 0 ? pm_admin($tgtId) : null;
    if ($tgt && $tgt['role_code'] === 'SUPER_ADMIN' && !$isSuper && $act !== '') {
        $act = '__deny';
        $err = '최고관리자 계정은 최고관리자만 바꿀 수 있습니다.';
    }
    try {
        if ($act === 'admin_create') {
            $login = trim(post('login_id'));
            $name  = trim(post('name'));
            $role  = post('role_code');
            $pwErr = pm_pw_error((string)($_POST['password'] ?? ''), (string)($_POST['password2'] ?? ''));
            $dup = $pdo->prepare('SELECT COUNT(*) FROM admins WHERE login_id = ?');
            $dup->execute([$login]);
            if (!preg_match('/^[A-Za-z0-9_.-]{3,50}$/', $login)) {
                $err = '아이디는 영문 · 숫자 · _ . - 로 3~50자입니다.';
            } elseif ((int)$dup->fetchColumn() > 0) {
                $err = '이미 있는 아이디입니다. (중지된 계정도 아이디를 차지합니다)';
            } elseif ($name === '' || !isset($ROLES[$role])) {
                $err = '이름과 역할을 확인하세요.';
            } elseif ($role === 'SUPER_ADMIN' && !$isSuper) {
                $err = '최고관리자 계정은 최고관리자만 만들 수 있습니다.';
            } elseif ($pwErr !== '') {
                $err = $pwErr;
            } else {
                $pdo->prepare(
                    'INSERT INTO admins (login_id, password_hash, must_change_pw, name, role_code,
                                         team, phone, email, is_active)
                     VALUES (?,?,1,?,?,?,?,?,1)')
                    ->execute([$login, password_hash((string)$_POST['password'], PASSWORD_DEFAULT), $name, $role,
                               post('team') ?: null, post('phone') ?: null, post('email') ?: null]);
                $nid = (int)$pdo->lastInsertId();
                log_action('시스템', 'CREATE', 'admins', $nid, $login, null, '역할 ' . $role);
                flash("계정 {$login} 을 만들었습니다. 임시 비밀번호를 본인에게 알려 주세요 — 첫 로그인 때 바꾸게 됩니다.");
                redirect('?p=permissions&perm=' . $nid);
            }

        } elseif ($act === 'pw_reset') {
            $pwErr = pm_pw_error((string)($_POST['password'] ?? ''), (string)($_POST['password2'] ?? ''));
            if (!$isSuper) {
                // 남의 비밀번호를 정할 수 있으면 그 사람으로 들어갈 수 있습니다
                $err = '비밀번호 초기화는 최고관리자만 할 수 있습니다.';
            } elseif (!$tgt) {
                $err = '계정을 찾을 수 없습니다.';
            } elseif ($pwErr !== '') {
                $err = $pwErr;
            } else {
                $pdo->prepare('UPDATE admins SET password_hash = ?, must_change_pw = 1,
                                      fail_count = 0, locked_at = NULL WHERE id = ?')
                    ->execute([password_hash((string)$_POST['password'], PASSWORD_DEFAULT), $tgtId]);
                log_action('시스템', 'UPDATE', 'admins', $tgtId, $tgt['login_id'], null, '임시 비밀번호로 초기화');
                flash($tgt['login_id'] . ' 의 비밀번호를 임시 비밀번호로 바꿨습니다. 다음 로그인 때 본인이 바꾸게 됩니다.');
                redirect('?p=permissions');
            }

        } elseif ($act === 'admin_perm_save') {
            if (!$isSuper) {
                $err = '계정별 권한은 최고관리자만 정할 수 있습니다.';
            } elseif (!$tgt) {
                $err = '계정을 찾을 수 없습니다.';
            } elseif ($tgt['role_code'] === 'SUPER_ADMIN') {
                $err = '최고관리자는 항상 모든 권한을 갖습니다.';
            } else {
                $custom = post('mode') === 'custom';
                $picked = $_POST['perm'] ?? [];
                $picked = is_array($picked) ? array_values(array_unique(array_map('intval', $picked))) : [];
                $pdo->beginTransaction();
                $pdo->prepare('UPDATE admins SET perm_custom = ? WHERE id = ?')->execute([$custom ? 1 : 0, $tgtId]);
                $pdo->prepare('DELETE FROM admin_permissions WHERE admin_id = ?')->execute([$tgtId]);
                if ($custom && $picked) {
                    $ins = $pdo->prepare('INSERT INTO admin_permissions (admin_id, permission_id) VALUES (?,?)');
                    foreach ($picked as $pid) { $ins->execute([$tgtId, $pid]); }
                }
                log_action('시스템', 'UPDATE', 'admin_permissions', $tgtId, $tgt['login_id'], null,
                           $custom ? '계정별 권한 ' . count($picked) . '개' : '역할 권한 사용');
                $pdo->commit();
                flash($tgt['login_id'] . ' 의 권한을 저장했습니다.');
                redirect('?p=permissions&perm=' . $tgtId);
            }

        } elseif ($act === 'admin_save') {
            $aid  = (int)post('id');
            $name = post('name');
            $role = post('role_code');
            if ($name === '' || !isset($ROLES[$role])) {
                $err = '이름과 역할을 확인하세요.';
            } elseif ($role === 'SUPER_ADMIN' && !$isSuper) {
                $err = '최고관리자 역할은 최고관리자만 줄 수 있습니다.';
            } elseif ($aid === $me && $role !== 'SUPER_ADMIN') {
                // 스스로 권한을 낮춰 아무도 관리자가 없는 상태가 되는 걸 막습니다
                $err = '자기 자신의 역할은 낮출 수 없습니다. 다른 최고관리자에게 요청하세요.';
            } else {
                $pdo->prepare(
                    'UPDATE admins SET name = ?, role_code = ?, team = ?, phone = ?, email = ?
                      WHERE id = ?')
                    ->execute([$name, $role, post('team') ?: null,
                               post('phone') ?: null, post('email') ?: null, $aid]);
                log_action('시스템', 'UPDATE', 'admins', $aid, $name, null, '역할 ' . $role);
                flash('관리자 정보를 수정했습니다.');
                redirect('?p=permissions');
            }

        } elseif ($act === 'admin_toggle') {
            $aid = (int)post('id');
            if ($aid === $me) {
                $err = '자기 자신은 중지할 수 없습니다.';
            } else {
                $n = (int)$pdo->query(
                    "SELECT COUNT(*) FROM admins
                      WHERE role_code = 'SUPER_ADMIN' AND is_active = 1 AND deleted_at IS NULL")
                    ->fetchColumn();
                $t = $pdo->prepare('SELECT role_code, is_active, name FROM admins WHERE id = ?');
                $t->execute([$aid]);
                $tgt = $t->fetch();
                if ($tgt && $tgt['role_code'] === 'SUPER_ADMIN' && $tgt['is_active'] && $n <= 1) {
                    $err = '마지막 최고관리자는 중지할 수 없습니다. 아무도 못 들어오게 됩니다.';
                } else {
                    $pdo->prepare('UPDATE admins SET is_active = 1 - is_active WHERE id = ?')
                        ->execute([$aid]);
                    log_action('시스템', 'UPDATE', 'admins', $aid, $tgt['name'] ?? null,
                               null, '사용 여부 변경');
                    flash('변경했습니다.');
                    redirect('?p=permissions');
                }
            }

        } elseif ($act === 'unlock') {
            $aid = (int)post('id');
            $pdo->prepare('UPDATE admins SET fail_count = 0, locked_at = NULL WHERE id = ?')
                ->execute([$aid]);
            log_action('시스템', 'UPDATE', 'admins', $aid, null, null, '계정 잠금 해제');
            flash('잠금을 풀었습니다.');
            redirect('?p=permissions');

        } elseif ($act === 'role_save') {
            $role = post('role_code');
            if (!$isSuper) {
                $err = '역할별 권한은 최고관리자만 바꿀 수 있습니다.';
            } elseif (!isset($ROLES[$role])) {
                $err = '역할이 올바르지 않습니다.';
            } elseif ($role === 'SUPER_ADMIN') {
                $err = '최고관리자의 권한은 바꿀 수 없습니다. 항상 전부 허용입니다.';
            } else {
                $picked = $_POST['perm'] ?? [];
                $picked = is_array($picked) ? array_map('intval', $picked) : [];
                $pdo->beginTransaction();
                $pdo->prepare('DELETE FROM role_permissions WHERE role_code = ?')->execute([$role]);
                if ($picked) {
                    $ins = $pdo->prepare(
                        'INSERT INTO role_permissions (role_code, permission_id) VALUES (?,?)');
                    foreach ($picked as $pid) { $ins->execute([$role, $pid]); }
                }
                log_action('시스템', 'UPDATE', 'role_permissions', null, $role,
                           null, count($picked) . '개 허용');
                $pdo->commit();
                flash($ROLES[$role] . ' 역할의 권한을 저장했습니다.');
                redirect('?p=permissions&tab=role&role=' . urlencode($role));
            }
        }
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        error_log('권한 저장 실패: ' . $e->getMessage());
        $err = '저장하지 못했습니다. 역할 권한 수정에는 DELETE 권한이 필요합니다.';
    }
}

$admins = db()->query(
    'SELECT * FROM admins WHERE deleted_at IS NULL
      ORDER BY is_active DESC, role_code, login_id')->fetchAll();

$editId = (int)query('edit', '0');
$edit = null;
foreach ($admins as $a) { if ((int)$a['id'] === $editId) { $edit = $a; } }

$selRole = query('role', 'MANAGER');
if (!isset($ROLES[$selRole])) { $selRole = 'MANAGER'; }

$perms = db()->query('SELECT * FROM permissions ORDER BY group_ko, code')->fetchAll();
$byGroup = [];
foreach ($perms as $p) { $byGroup[$p['group_ko']][] = $p; }

$st = db()->prepare('SELECT permission_id FROM role_permissions WHERE role_code = ?');
$st->execute([$selRole]);
$has = array_flip(array_column($st->fetchAll(), 'permission_id'));

// 계정별 권한 편집 (?perm=계정id)
$permId = (int)query('perm', '0');
$permFor = null;
foreach ($admins as $a) { if ((int)$a['id'] === $permId) { $permFor = $a; } }
$pwFor = null;
$pwId = (int)query('pw', '0');
foreach ($admins as $a) { if ((int)$a['id'] === $pwId) { $pwFor = $a; } }
$permHasRole = [];
$permHasOwn = [];
if ($permFor) {
    $st = db()->prepare('SELECT permission_id FROM role_permissions WHERE role_code = ?');
    $st->execute([$permFor['role_code']]);
    $permHasRole = array_flip(array_map('intval', array_column($st->fetchAll(), 'permission_id')));
    $st = db()->prepare('SELECT permission_id FROM admin_permissions WHERE admin_id = ?');
    $st->execute([(int)$permFor['id']]);
    $permHasOwn = array_flip(array_map('intval', array_column($st->fetchAll(), 'permission_id')));
}

$roleCount = [];
foreach (array_keys($ROLES) as $r) {
    $st = db()->prepare('SELECT COUNT(*) FROM role_permissions WHERE role_code = ?');
    $st->execute([$r]);
    $roleCount[$r] = (int)$st->fetchColumn();
}

$today = date('Y-m-d H:i:s');
layout_head('관리자 / 권한', 'permissions');
?>
<div class="head">
  <h1>관리자 / 권한</h1>
  <div class="crumb">시스템 &gt; 관리자 / 권한</div>
</div>

<?php if ($err !== ''): ?><div class="msg err"><?= h($err) ?></div><?php endif; ?>

<div class="card">
  <div class="ch">
    <a class="btn sm<?= $tab==='admin'?' pri':'' ?>" href="?p=permissions&amp;tab=admin">관리자</a>
    <a class="btn sm<?= $tab==='role'?' pri':'' ?>" href="?p=permissions&amp;tab=role">역할별 권한</a>
  </div>

<?php if ($tab === 'admin' && $permFor): ?>
  <?php $custom = (int)($permFor['perm_custom'] ?? 0) === 1;
        $cur = $custom ? $permHasOwn : $permHasRole; ?>
  <div class="cb" style="border-bottom:1px solid var(--line)">
    <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
      <b><?= h($permFor['name']) ?> (<?= h($permFor['login_id']) ?>)</b>
      <span class="badge b-info"><?= h($ROLES[$permFor['role_code']] ?? $permFor['role_code']) ?></span>
      <a class="btn sm" href="?p=permissions" style="margin-left:auto">목록으로</a>
    </div>
  </div>
  <?php if ($permFor['role_code'] === 'SUPER_ADMIN'): ?>
    <div class="empty">최고관리자는 <b>항상 모든 권한</b>을 갖습니다.</div>
  <?php elseif (!$isSuper): ?>
    <div class="empty">계정별 권한은 최고관리자만 정할 수 있습니다.</div>
  <?php else: ?>
  <form method="post" id="permform">
    <?= csrf_field() ?>
    <input type="hidden" name="act" value="admin_perm_save">
    <input type="hidden" name="id" value="<?= (int)$permFor['id'] ?>">
    <div class="cb" style="display:flex;gap:18px;flex-wrap:wrap;font-size:12.5px">
      <label style="display:flex;gap:6px;align-items:center;font-weight:400;font-size:12.5px;cursor:pointer">
        <input type="radio" name="mode" value="role"<?= $custom ? '' : ' checked' ?> onchange="permMode()">
        역할(<?= h($ROLES[$permFor['role_code']] ?? '') ?>) 권한 그대로 쓰기</label>
      <label style="display:flex;gap:6px;align-items:center;font-weight:400;font-size:12.5px;cursor:pointer">
        <input type="radio" name="mode" value="custom"<?= $custom ? ' checked' : '' ?> onchange="permMode()">
        이 계정만 따로 정하기</label>
    </div>
    <div class="cb" style="padding-top:0;font-size:12px;color:var(--ink2)">
      <b>조회</b> 권한이 없는 화면은 메뉴에서 보이지 않습니다. <b>등록/수정</b> 권한이 있어야 입력 · 저장할 수 있습니다.
      <b>삭제·취소 바로 실행</b>이 없으면 삭제 · 취소를 눌러도 바로 되지 않고 <b>삭제 요청</b>으로 올라와 승인을 기다립니다.
    </div>
    <?php foreach ($byGroup as $grp => $items): ?>
      <div class="cb" style="border-top:1px solid var(--line2)">
        <div style="display:flex;gap:8px;align-items:center;margin-bottom:8px">
          <div class="sec" style="font-weight:700;font-size:12.5px"><?= h($grp) ?></div>
          <button type="button" class="btn sm pbulk" data-g="<?= h($grp) ?>" data-v="1">모두</button>
          <button type="button" class="btn sm pbulk" data-g="<?= h($grp) ?>" data-v="0">해제</button>
        </div>
        <div style="display:flex;flex-wrap:wrap;gap:8px 18px">
          <?php foreach ($items as $p): ?>
            <label style="display:flex;gap:6px;align-items:center;font-size:12.5px;font-weight:400;
                          min-width:230px;cursor:pointer">
              <input type="checkbox" name="perm[]" value="<?= (int)$p['id'] ?>" data-g="<?= h($grp) ?>"
                     data-role="<?= isset($permHasRole[(int)$p['id']]) ? 1 : 0 ?>"
                     <?= isset($cur[(int)$p['id']]) ? 'checked' : '' ?>>
              <?= h($p['name_ko']) ?>
            </label>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endforeach; ?>
    <div class="cb" style="border-top:1px solid var(--line);display:flex;gap:8px;align-items:center;flex-wrap:wrap">
      <button class="btn pri">권한 저장</button>
      <span style="font-size:11.5px;color:var(--ink3)">역할 그대로 쓰기를 고르면 체크는 역할 권한을 보여 줄 뿐 바꿀 수 없습니다.</span>
    </div>
  </form>
  <script>
  function permMode() {
    var custom = document.querySelector('#permform input[name=mode]:checked').value === 'custom';
    document.querySelectorAll('#permform input[name="perm[]"]').forEach(function (c) {
      if (!custom) { c.checked = c.getAttribute('data-role') === '1'; }
      c.disabled = !custom;
    });
    document.querySelectorAll('#permform .pbulk').forEach(function (b) { b.disabled = !custom; });
  }
  document.querySelectorAll('#permform .pbulk').forEach(function (b) {
    b.addEventListener('click', function () {
      var g = b.getAttribute('data-g'), v = b.getAttribute('data-v') === '1';
      document.querySelectorAll('#permform input[name="perm[]"]').forEach(function (c) {
        if (c.getAttribute('data-g') === g) { c.checked = v; }
      });
    });
  });
  permMode();
  </script>
  <?php endif; ?>

<?php elseif ($tab === 'admin'): ?>
  <?php if ($pwFor): ?>
  <div class="cb" style="border-bottom:1px solid var(--line)">
    <form method="post" class="f" style="align-items:flex-end" autocomplete="off">
      <?= csrf_field() ?>
      <input type="hidden" name="act" value="pw_reset">
      <input type="hidden" name="id" value="<?= (int)$pwFor['id'] ?>">
      <div class="fw w2"><label>계정</label>
        <input type="text" value="<?= h($pwFor['name'] . ' (' . $pwFor['login_id'] . ')') ?>" disabled></div>
      <div class="fw w2"><label>임시 비밀번호 (8자 이상, 영문+숫자)</label>
        <input type="password" name="password" required minlength="8" autocomplete="new-password"></div>
      <div class="fw w2"><label>임시 비밀번호 확인</label>
        <input type="password" name="password2" required minlength="8" autocomplete="new-password"></div>
      <button class="btn pri">비밀번호 초기화</button>
      <a class="btn" href="?p=permissions">취소</a>
    </form>
    <div style="font-size:11.5px;color:var(--ink3);margin-top:6px">
      본인에게 임시 비밀번호를 알려 주세요. 다음 로그인 때 본인이 새 비밀번호로 바꾸게 됩니다. 잠긴 계정도 함께 풀립니다.</div>
  </div>
  <?php elseif (!$edit): ?>
  <details class="cb" style="border-bottom:1px solid var(--line)"<?= $err !== '' && post('act') === 'admin_create' ? ' open' : '' ?>>
    <summary style="cursor:pointer;font-weight:600;font-size:12.5px">＋ 계정 추가</summary>
    <form method="post" class="f" style="align-items:flex-end;margin-top:12px" autocomplete="off">
      <?= csrf_field() ?>
      <input type="hidden" name="act" value="admin_create">
      <div class="fw w1"><label>아이디 *</label>
        <input type="text" name="login_id" required pattern="[A-Za-z0-9_.\-]{3,50}"
               value="<?= h(post('login_id')) ?>" autocomplete="off"></div>
      <div class="fw w1"><label>이름 *</label>
        <input type="text" name="name" required value="<?= h(post('name')) ?>"></div>
      <div class="fw w1"><label>역할 (기본 권한)</label>
        <select name="role_code">
          <?php foreach ($ROLES as $k=>$v): if ($k === 'SUPER_ADMIN' && !$isSuper) continue; ?>
            <option value="<?= h($k) ?>"<?= (post('role_code') ?: 'VIEWER')===$k?' selected':'' ?>><?= h($v) ?></option>
          <?php endforeach; ?>
        </select></div>
      <div class="fw w1"><label>팀</label>
        <input type="text" name="team" value="<?= h(post('team')) ?>"></div>
      <div class="fw w2"><label>임시 비밀번호 * (8자 이상, 영문+숫자)</label>
        <input type="password" name="password" required minlength="8" autocomplete="new-password"></div>
      <div class="fw w2"><label>임시 비밀번호 확인 *</label>
        <input type="password" name="password2" required minlength="8" autocomplete="new-password"></div>
      <button class="btn pri">계정 만들기</button>
    </form>
    <div style="font-size:11.5px;color:var(--ink3);margin-top:6px">
      만든 뒤 바로 <b>권한</b> 화면으로 넘어갑니다. 역할의 기본 권한을 그대로 쓰거나, 이 계정만 따로 고를 수 있습니다.</div>
  </details>
  <?php endif; ?>
  <?php if ($edit): ?>
  <div class="cb">
    <form method="post" class="f" style="align-items:flex-end">
      <?= csrf_field() ?>
      <input type="hidden" name="act" value="admin_save">
      <input type="hidden" name="id" value="<?= (int)$edit['id'] ?>">
      <div class="fw w1"><label>아이디</label>
        <input type="text" class="tnum" value="<?= h($edit['login_id']) ?>" disabled></div>
      <div class="fw w1"><label>이름 *</label>
        <input type="text" name="name" required value="<?= h($edit['name']) ?>"></div>
      <div class="fw w1"><label>역할</label>
        <select name="role_code">
          <?php foreach ($ROLES as $k=>$v): ?>
            <option value="<?= h($k) ?>"<?= $edit['role_code']===$k?' selected':'' ?>><?= h($v) ?></option>
          <?php endforeach; ?>
        </select></div>
      <div class="fw w1"><label>팀</label>
        <input type="text" name="team" value="<?= h($edit['team']) ?>"></div>
      <div class="fw w2"><label>전화</label>
        <input type="text" name="phone" value="<?= h($edit['phone']) ?>"></div>
      <div class="fw w2"><label>이메일</label>
        <input type="text" name="email" value="<?= h($edit['email']) ?>"></div>
      <button class="btn pri">수정</button>
      <a class="btn" href="?p=permissions">취소</a>
    </form>
  </div>
  <?php endif; ?>

  <table>
    <thead><tr>
      <th style="width:120px">아이디</th><th style="width:100px">이름</th>
      <th style="width:110px">역할</th><th style="width:90px">팀</th>
      <th style="width:150px">마지막 로그인</th><th style="width:120px">접속 IP</th>
      <th class="c" style="width:90px">상태</th><th class="c" style="width:300px"></th>
    </tr></thead>
    <tbody>
    <?php foreach ($admins as $a):
      $locked = $a['locked_at'] !== null; ?>
      <tr<?= $a['is_active'] ? '' : ' style="opacity:.55"' ?>>
        <td class="tnum" style="font-weight:600"><?= h($a['login_id']) ?>
          <?php if ((int)$a['id'] === (int)($_SESSION['admin_id'] ?? 0)): ?>
            <span class="badge b-info">나</span>
          <?php endif; ?></td>
        <td><?= h($a['name']) ?></td>
        <td><?= h($ROLES[$a['role_code']] ?? $a['role_code']) ?>
          <?php if ((int)($a['perm_custom'] ?? 0) === 1): ?><span class="badge b-info">계정별</span><?php endif; ?>
          <?php if ((int)($a['must_change_pw'] ?? 0) === 1): ?><span class="badge b-warn">임시비번</span><?php endif; ?></td>
        <td><?= h($a['team'] ?: '-') ?></td>
        <td class="tnum" style="font-size:11.5px"><?= h($a['last_login_at'] ?: '없음') ?></td>
        <td class="tnum" style="font-size:11.5px"><?= h($a['last_login_ip'] ?: '-') ?></td>
        <td class="c"><?php
          if ($locked) {
              echo '<span class="badge b-err">잠김</span>';
          } elseif ($a['is_active']) {
              echo '<span class="badge b-ok">사용</span>';
          } else {
              echo '<span class="badge b-warn">중지</span>';
          }
          if ((int)$a['fail_count'] > 0) {
              echo '<div style="font-size:11px;color:var(--err-fg)">실패 '
                 . (int)$a['fail_count'] . '회</div>';
          }
        ?></td>
        <td class="c">
          <a class="btn sm" href="?p=permissions&amp;edit=<?= (int)$a['id'] ?>">수정</a>
          <a class="btn sm" href="?p=permissions&amp;perm=<?= (int)$a['id'] ?>">권한</a>
          <?php if ($isSuper): ?>
          <a class="btn sm" href="?p=permissions&amp;pw=<?= (int)$a['id'] ?>">비밀번호</a>
          <?php endif; ?>
          <?php if ($locked): ?>
          <form method="post" style="display:inline">
            <?= csrf_field() ?><input type="hidden" name="act" value="unlock">
            <input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
            <button class="btn sm">잠금해제</button>
          </form>
          <?php endif; ?>
          <form method="post" style="display:inline">
            <?= csrf_field() ?><input type="hidden" name="act" value="admin_toggle">
            <input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
            <button class="btn sm"><?= $a['is_active'] ? '중지' : '사용' ?></button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$admins): ?>
      <tr><td colspan="8" class="empty">관리자가 없습니다.</td></tr>
    <?php endif; ?>
    </tbody>
  </table>
  <div class="pager"><span>
    계정은 지우지 않습니다 — 작업로그가 이 계정을 참조합니다. 안 쓰면 <b>중지</b>로 내리세요.
    <b>비밀번호는 이 화면에서 볼 수 없습니다.</b> 잊었으면 <b>비밀번호</b> 버튼으로 임시 비밀번호를 정해 주고, 본인이 다음 로그인 때 바꿉니다 (내 정보).
  </span></div>

<?php else: ?>
  <div class="cb" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
    <span style="font-size:12px;color:var(--ink2)">역할</span>
    <?php foreach ($ROLES as $k=>$v): ?>
      <a class="btn sm<?= $selRole===$k?' pri':'' ?>"
         href="?p=permissions&amp;tab=role&amp;role=<?= urlencode($k) ?>">
        <?= h($v) ?> <span class="tnum" style="opacity:.7"><?= (int)$roleCount[$k] ?></span></a>
    <?php endforeach; ?>
  </div>

  <?php if ($selRole === 'SUPER_ADMIN'): ?>
    <div class="empty">최고관리자는 <b>항상 모든 권한</b>을 갖습니다. 여기서 바꿀 수 없습니다 —
      잘못 건드리면 아무도 시스템을 관리할 수 없게 됩니다.</div>
  <?php else: ?>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="act" value="role_save">
    <input type="hidden" name="role_code" value="<?= h($selRole) ?>">
    <?php foreach ($byGroup as $grp => $items): ?>
      <div class="cb" style="border-top:1px solid var(--line2)">
        <div class="sec" style="margin-bottom:8px"><?= h($grp) ?></div>
        <div style="display:flex;flex-wrap:wrap;gap:8px 18px">
          <?php foreach ($items as $p): ?>
            <label style="display:flex;gap:6px;align-items:center;font-size:12.5px;
                          min-width:230px;cursor:pointer">
              <input type="checkbox" name="perm[]" value="<?= (int)$p['id'] ?>"
                     <?= isset($has[(int)$p['id']]) ? 'checked' : '' ?>>
              <?= h($p['name_ko']) ?>
              <span class="tnum" style="color:var(--ink3);font-size:11px"><?= h($p['code']) ?></span>
            </label>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endforeach; ?>
    <div class="cb" style="border-top:1px solid var(--line);display:flex;gap:8px;align-items:center">
      <button class="btn pri"><?= h($ROLES[$selRole]) ?> 권한 저장</button>
      <span style="font-size:11.5px;color:var(--ink3)">
        체크를 모두 풀면 그 역할은 아무것도 못 합니다. 저장 전에 확인하세요.</span>
    </div>
  </form>
  <?php endif; ?>
<?php endif; ?>
</div>

<div class="card">
  <div class="ch">지금 로그인한 세션</div>
  <div class="cb f" style="gap:24px">
    <div><div style="font-size:11px;color:var(--ink2)">계정</div>
      <div style="font-weight:600"><?= h($ADMIN['name'] ?? '') ?>
        (<?= h($ADMIN['login_id'] ?? '') ?>)</div></div>
    <div><div style="font-size:11px;color:var(--ink2)">역할</div>
      <div><?= h($ROLES[$ADMIN['role_code'] ?? ''] ?? ($ADMIN['role_code'] ?? '')) ?></div></div>
    <div><div style="font-size:11px;color:var(--ink2)">접속 IP</div>
      <div class="tnum"><?= h($_SERVER['REMOTE_ADDR'] ?? '-') ?></div></div>
    <div><div style="font-size:11px;color:var(--ink2)">서버 시각</div>
      <div class="tnum"><?= h($today) ?></div></div>
  </div>
</div>
<?php layout_foot();
