<?php
// 웹에서 직접 열면 실행되지 않게 막습니다 (nginx 면 .htaccess 가 무시됩니다)
if (!defined('APP_DIR')) { http_response_code(403); exit('Forbidden'); }

require_once APP_DIR . '/layout.php';

/**
 * 도착지 관리.
 *   · 매출전표 도착지(shipments.dest_city)를 목록으로 관리합니다. 전표 화면에서는 여기 있는 것만 고릅니다.
 *   · 처음 열면 전표에 쓰인 도착지를 불러오고, 아는 나라는 국가코드 · 한글 이름을 채웁니다.
 *   · 이름을 바꾸거나 합치면 그 도착지를 쓴 전표도 같이 바뀝니다 (예: U.K → UNITED KINGDOM).
 *   · 국가코드는 이 목록에 둡니다. 새로 저장하는 전표에는 dest_country 로도 같이 적힙니다
 *     (옛 전표 수만 건을 한꺼번에 고치면 변경 이력이 수만 줄 쌓이므로, 옛 전표는 이름으로 찾습니다).
 */

$err = '';
$MAP = require APP_DIR . '/destinations_seed.php';

/** 전표에 쓰였는데 목록에 없는 도착지를 넣고, 빈 국가코드 · 한글 이름을 아는 것으로 채웁니다 */
function dest_import(array $map): array
{
    $pdo = db();
    $added = $pdo->exec("INSERT IGNORE INTO destinations (name)
                         SELECT DISTINCT TRIM(dest_city) FROM shipments
                          WHERE deleted_at IS NULL AND TRIM(COALESCE(dest_city, '')) <> ''");
    $filled = 0;
    $rows = $pdo->query("SELECT id, name FROM destinations WHERE country_code IS NULL OR name_ko IS NULL")->fetchAll();
    $up = $pdo->prepare('UPDATE destinations SET country_code = COALESCE(country_code, ?),
                                                 name_ko = COALESCE(name_ko, ?) WHERE id = ?');
    foreach ($rows as $r) {
        $k = strtoupper(trim((string)$r['name']));
        if (isset($map[$k])) {
            $up->execute([$map[$k][0], $map[$k][1], (int)$r['id']]);
            $filled++;
        }
    }
    return [(int)$added, $filled];
}

// 처음 열었을 때 한 번 채웁니다
try {
    if ((int)db()->query('SELECT COUNT(*) FROM destinations')->fetchColumn() === 0) {
        [$a, $f] = dest_import($MAP);
        if ($a > 0) {
            // 옛 전표 수만 건은 건드리지 않습니다 (나라는 이 목록에서 이름으로 찾습니다)
            log_action('기준정보', 'CREATE', 'destinations', null, '도착지 목록',
                       null, '전표에서 ' . $a . '개 불러옴 · 국가코드 ' . $f . '개 채움');
            flash('전표에 쓰인 도착지 ' . $a . '개를 불러왔습니다. 그중 ' . $f . '개는 국가코드 · 한글 이름을 채웠습니다.');
        }
    }
} catch (PDOException $e) {
    error_log('도착지 불러오기 실패: ' . $e->getMessage());
    $err = '도착지 목록을 준비하지 못했습니다. 다시 로그인한 뒤 열어 보세요.';
}

// ---------------------------------------------------------------- 저장
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $act = post('act');
    $pdo = db();
    try {
        if ($act === 'import') {
            [$a, $f] = dest_import($MAP);
            flash('새 도착지 ' . $a . '개를 불러오고, 국가코드 · 한글 이름 ' . $f . '개를 채웠습니다.');
            redirect('?p=destinations');

        } elseif ($act === 'save') {
            $id   = (int)post('id');
            $name = strtoupper(trim((string)preg_replace('/\s+/u', ' ', post('name'))));
            $ko   = trim(post('name_ko'));
            $code = strtoupper(trim(post('country_code')));
            $memo = trim(post('memo'));
            $on   = post('is_active') === '1' ? 1 : 0;
            if ($name === '' || mb_strlen($name) > 50) {
                $err = '도착지 표기를 1~50자로 적어 주세요.';
            } elseif ($code !== '' && !preg_match('/^[A-Z]{2}$/', $code)) {
                $err = '국가코드는 영문 2자리입니다 (예: US, CN, JP).';
            } else {
                $dup = $pdo->prepare('SELECT id FROM destinations WHERE name = ? AND id <> ?');
                $dup->execute([$name, $id]);
                if ($dup->fetchColumn()) {
                    $err = '"' . $name . '" 은 이미 있습니다. 같은 곳이면 아래 합치기를 쓰세요.';
                }
            }
            if ($err === '') {
                $pdo->beginTransaction();
                $moved = 0;
                if ($id > 0) {
                    $old = $pdo->prepare('SELECT * FROM destinations WHERE id = ?');
                    $old->execute([$id]);
                    $old = $old->fetch();
                    if (!$old) { throw new RuntimeException('도착지를 찾을 수 없습니다.'); }
                    $pdo->prepare('UPDATE destinations SET name = ?, name_ko = ?, country_code = ?, memo = ?, is_active = ?
                                    WHERE id = ?')
                        ->execute([$name, $ko ?: null, $code ?: null, $memo ?: null, $on, $id]);
                    // 이름을 바꾸면 그 이름을 쓴 전표도 같이 바꿉니다
                    if ($old['name'] !== $name) {
                        $st = $pdo->prepare('UPDATE shipments SET dest_city = ?, dest_country = ? WHERE dest_city = ?');
                        $st->execute([$name, $code ?: null, $old['name']]);
                        $moved = $st->rowCount();
                    }
                    log_action('기준정보', 'UPDATE', 'destinations', $id, $name,
                               $old['name'] . ' / ' . ($old['country_code'] ?? '-'), $name . ' / ' . ($code ?: '-'),
                               $moved ? '전표 ' . $moved . '건 도착지 이름 변경' : null);
                    $msg = '도착지를 저장했습니다.' . ($moved ? ' 전표 ' . $moved . '건의 도착지 이름도 바꿨습니다.' : '');
                } else {
                    $pdo->prepare('INSERT INTO destinations (name, name_ko, country_code, memo, is_active) VALUES (?,?,?,?,?)')
                        ->execute([$name, $ko ?: null, $code ?: null, $memo ?: null, $on]);
                    log_action('기준정보', 'CREATE', 'destinations', (int)$pdo->lastInsertId(), $name);
                    $msg = '도착지 ' . $name . ' 을 추가했습니다.';
                }
                $pdo->commit();
                flash($msg);
                redirect('?p=destinations&kw=' . urlencode($name));
            }

        } elseif ($act === 'merge') {
            // from 을 to 로 합칩니다 — from 을 쓴 전표를 to 로 옮기고 from 은 목록에서 지웁니다
            $fromId = (int)post('from_id');
            $toId   = (int)post('to_id');
            $st = $pdo->prepare('SELECT * FROM destinations WHERE id IN (?, ?)');
            $st->execute([$fromId, $toId]);
            $two = [];
            foreach ($st->fetchAll() as $r) { $two[(int)$r['id']] = $r; }
            if ($fromId === $toId || !isset($two[$fromId], $two[$toId])) {
                $err = '합칠 두 도착지를 다르게 고르세요.';
            } else {
                $from = $two[$fromId];
                $to   = $two[$toId];
                $pdo->beginTransaction();
                $st = $pdo->prepare('UPDATE shipments SET dest_city = ?, dest_country = ? WHERE dest_city = ?');
                $st->execute([$to['name'], $to['country_code'], $from['name']]);
                $n = $st->rowCount();
                $pdo->prepare('DELETE FROM destinations WHERE id = ?')->execute([$fromId]);
                log_action('기준정보', 'UPDATE', 'destinations', $toId, $to['name'],
                           $from['name'], $to['name'], '도착지 합치기 — 전표 ' . $n . '건 옮김');
                $pdo->commit();
                flash($from['name'] . ' 을 ' . $to['name'] . ' 로 합쳤습니다. 전표 ' . number_format($n) . '건을 옮겼습니다.');
                redirect('?p=destinations&kw=' . urlencode($to['name']));
            }
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        error_log('도착지 저장 실패: ' . $e->getMessage());
        $err = $e instanceof RuntimeException ? $e->getMessage() : '저장하지 못했습니다.';
    }
}

// ---------------------------------------------------------------- 목록
$kw   = trim(query('kw'));
$show = in_array(query('show'), ['nocode', 'unused', 'off'], true) ? query('show') : '';
$use = [];
foreach (db()->query("SELECT dest_city AS n, COUNT(*) AS c, MAX(voucher_date) AS last
                        FROM shipments WHERE deleted_at IS NULL AND dest_city IS NOT NULL
                       GROUP BY dest_city") as $u) {
    $use[mb_strtoupper((string)$u['n'])] = $u;
}
$w = ['1=1'];
$p = [];
if ($kw !== '') {
    $w[] = '(name LIKE ? OR name_ko LIKE ? OR country_code = ?)';
    array_push($p, '%' . $kw . '%', '%' . $kw . '%', strtoupper($kw));
}
if ($show === 'nocode') { $w[] = 'country_code IS NULL'; }
if ($show === 'off')    { $w[] = 'is_active = 0'; }
$st = db()->prepare('SELECT * FROM destinations WHERE ' . implode(' AND ', $w) . ' ORDER BY name');
$st->execute($p);
$rows = $st->fetchAll();
foreach ($rows as &$r) {
    $u = $use[mb_strtoupper((string)$r['name'])] ?? null;
    $r['cnt']  = $u ? (int)$u['c'] : 0;
    $r['last'] = $u['last'] ?? null;
}
unset($r);
if ($show === 'unused') { $rows = array_values(array_filter($rows, fn($r) => $r['cnt'] === 0)); }
usort($rows, fn($a, $b) => $b['cnt'] <=> $a['cnt'] ?: strcmp($a['name'], $b['name']));

$all = db()->query('SELECT id, name, name_ko, country_code FROM destinations ORDER BY name')->fetchAll();
$editId = (int)query('edit', '0');
$edit = null;
foreach ($all as $a) { if ((int)$a['id'] === $editId) { $edit = $a; } }
if ($edit) {
    $st = db()->prepare('SELECT * FROM destinations WHERE id = ?');
    $st->execute([$editId]);
    $edit = $st->fetch();
}
$canEdit = route_can_edit('destinations');
$noCode = count(array_filter($all, fn($a) => $a['country_code'] === null));

layout_head('도착지 관리', 'destinations');
?>
<div class="head">
  <h1>도착지 관리</h1>
  <div class="crumb">기준정보 &gt; 도착지 관리 · 매출전표에서 고르는 도착지 목록</div>
  <?php if ($canEdit): ?>
  <div class="right">
    <form method="post" style="display:inline">
      <?= csrf_field() ?><input type="hidden" name="act" value="import">
      <button class="btn">전표에서 새 도착지 불러오기</button>
    </form>
  </div>
  <?php endif; ?>
</div>

<?php if ($err !== ''): ?><div class="msg err"><?= h($err) ?></div><?php endif; ?>

<?php if ($canEdit): ?>
<div class="card">
  <div class="ch"><?= $edit ? '도착지 수정 — ' . h($edit['name']) : '도착지 추가' ?></div>
  <div class="cb">
    <form method="post" class="f" style="align-items:flex-end">
      <?= csrf_field() ?>
      <input type="hidden" name="act" value="save">
      <input type="hidden" name="id" value="<?= $edit ? (int)$edit['id'] : 0 ?>">
      <div class="fw w2"><label for="dn">도착지 표기 * <small style="font-weight:400">(전표에 적히는 이름)</small></label>
        <input type="text" id="dn" name="name" required maxlength="50" value="<?= h($edit['name'] ?? '') ?>"
               placeholder="UNITED STATES" style="text-transform:uppercase"></div>
      <div class="fw w2"><label for="dk">한글 이름</label>
        <input type="text" id="dk" name="name_ko" maxlength="50" value="<?= h($edit['name_ko'] ?? '') ?>" placeholder="미국"></div>
      <div class="fw w1"><label for="dc">국가코드</label>
        <input type="text" id="dc" name="country_code" maxlength="2" value="<?= h($edit['country_code'] ?? '') ?>"
               placeholder="US" style="text-transform:uppercase"></div>
      <div class="fw w2"><label for="dm">메모</label>
        <input type="text" id="dm" name="memo" maxlength="200" value="<?= h($edit['memo'] ?? '') ?>"></div>
      <label style="display:flex;gap:6px;align-items:center;font-weight:400;font-size:12.5px;height:34px">
        <input type="checkbox" name="is_active" value="1"<?= ($edit['is_active'] ?? 1) ? ' checked' : '' ?>> 전표에서 고를 수 있게</label>
      <button class="btn pri"><?= $edit ? '저장' : '추가' ?></button>
      <?php if ($edit): ?><a class="btn" href="?p=destinations">취소</a><?php endif; ?>
    </form>
    <?php if ($edit): ?>
    <div style="font-size:11.5px;color:var(--ink3);margin-top:6px">
      표기를 바꾸면 이 도착지를 쓴 전표의 도착지도 같이 바뀝니다 (변경 이력에 남음).</div>
    <?php endif; ?>
  </div>
</div>

<div class="card">
  <div class="ch">합치기 <span style="font-weight:400;color:var(--ink3)">같은 곳인데 표기가 다른 것 (예: U.K → UNITED KINGDOM)</span></div>
  <div class="cb">
    <form method="post" class="f" style="align-items:flex-end"
          onsubmit="return confirm('왼쪽 도착지를 쓴 전표를 모두 오른쪽 도착지로 옮기고, 왼쪽은 목록에서 지웁니다. 계속할까요?');">
      <?= csrf_field() ?>
      <input type="hidden" name="act" value="merge">
      <div class="fw w3"><label for="mf">이 도착지를 (없앨 것)</label>
        <select id="mf" name="from_id" required data-search="없앨 도착지 검색">
          <option value="">선택</option>
          <?php foreach ($all as $a): ?>
            <option value="<?= (int)$a['id'] ?>"><?= h($a['name'] . ($a['name_ko'] ? ' · ' . $a['name_ko'] : '')) ?><?= $a['country_code'] ? ' (' . h($a['country_code']) . ')' : '' ?></option>
          <?php endforeach; ?>
        </select></div>
      <div class="fw w3"><label for="mt">이 도착지로 (남길 것)</label>
        <select id="mt" name="to_id" required data-search="남길 도착지 검색">
          <option value="">선택</option>
          <?php foreach ($all as $a): ?>
            <option value="<?= (int)$a['id'] ?>"><?= h($a['name'] . ($a['name_ko'] ? ' · ' . $a['name_ko'] : '')) ?><?= $a['country_code'] ? ' (' . h($a['country_code']) . ')' : '' ?></option>
          <?php endforeach; ?>
        </select></div>
      <button class="btn">합치기</button>
    </form>
  </div>
</div>
<?php endif; ?>

<div class="card">
  <div class="ch">
    <form method="get" class="f" style="align-items:center;gap:8px;width:100%">
      <input type="hidden" name="p" value="destinations">
      <input type="text" name="kw" value="<?= h($kw) ?>" placeholder="도착지 · 한글 이름 · 국가코드" style="max-width:260px">
      <select name="show" style="max-width:160px">
        <option value="">전체</option>
        <option value="nocode"<?= $show==='nocode'?' selected':'' ?>>국가코드 없음</option>
        <option value="unused"<?= $show==='unused'?' selected':'' ?>>전표에 안 쓰임</option>
        <option value="off"<?= $show==='off'?' selected':'' ?>>사용 안 함</option>
      </select>
      <button class="btn sm">찾기</button>
      <span style="margin-left:auto;font-weight:400;color:var(--ink3)">
        <?= count($all) ?>곳<?= $noCode ? ' · 국가코드 없음 ' . $noCode . '곳' : '' ?></span>
    </form>
  </div>
  <?php if (!$rows): ?>
    <div class="empty">도착지가 없습니다.</div>
  <?php else: ?>
  <table>
    <thead><tr>
      <th>도착지 표기</th><th style="width:170px">한글 이름</th><th class="c" style="width:70px">국가</th>
      <th class="r" style="width:90px">전표 수</th><th style="width:100px">최근 사용</th>
      <th class="c" style="width:70px">사용</th><th class="c" style="width:70px"></th>
    </tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr<?= (int)$r['is_active'] ? '' : ' style="opacity:.55"' ?>>
        <td style="font-weight:600"><?= h($r['name']) ?>
          <?php if ($r['memo']): ?><div style="font-weight:400;font-size:11.5px;color:var(--ink3)"><?= h($r['memo']) ?></div><?php endif; ?></td>
        <td><?= h($r['name_ko'] ?? '') ?></td>
        <td class="c tnum"><?= $r['country_code'] ? h($r['country_code']) : '<span class="badge b-warn">없음</span>' ?></td>
        <td class="r tnum"><?= $r['cnt'] ? '<a href="?p=shipments&amp;dest=' . h(urlencode((string)$r['name'])) . '">' . money($r['cnt']) . '</a>' : '0' ?></td>
        <td class="tnum" style="font-size:12px"><?= h($r['last'] ?? '-') ?></td>
        <td class="c"><?= (int)$r['is_active'] ? '<span class="badge b-ok">사용</span>' : '<span class="badge b-info">안 함</span>' ?></td>
        <td class="c"><?php if ($canEdit): ?><a class="btn sm" href="?p=destinations&amp;edit=<?= (int)$r['id'] ?>">수정</a><?php endif; ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>
<?php layout_foot();
