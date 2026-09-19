<?php
require_once APP_DIR . '/layout.php';

$err = '';
$id  = (int)query('id', '0');       // 선택한 단가표

$carriers = db()->query('SELECT id, code, name FROM carriers WHERE is_active = 1
                          ORDER BY sort_order, code')->fetchAll();
$services = db()->query(
    'SELECT s.id, s.carrier_id, s.code, s.name FROM carrier_services s
      WHERE s.is_active = 1 ORDER BY s.carrier_id, s.code')->fetchAll();

// ---------------------------------------------------------------- 저장
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $act = post('act');
    $pdo = db();
    try {
        if ($act === 'table_create') {
            $cid  = (int)post('carrier_id');
            $sid  = post('service_id');
            $name = post('name');
            $from = post('effective_from');
            if ($cid <= 0 || $name === '') {
                $err = '운송사와 가격표 이름을 입력하세요.';
            } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
                $err = '적용 시작일을 입력하세요.';
            } else {
                $pdo->prepare(
                    'INSERT INTO carrier_rate_tables
                       (carrier_id, service_id, name, effective_from, status, created_by)
                     VALUES (?,?,?,?,\'DRAFT\',?)')
                    ->execute([$cid, $sid !== '' ? (int)$sid : null, $name, $from,
                               $_SESSION['admin_id'] ?? null]);
                $newId = (int)$pdo->lastInsertId();
                log_action('기준정보', 'CREATE', 'carrier_rate_tables', $newId, $name);
                flash('가격표를 만들었습니다. 단가를 넣으세요.');
                redirect('?p=rate_table&id=' . $newId);
            }

        } elseif ($act === 'rate_bulk') {
            $tid  = (int)post('table_id');
            $body = (string)($_POST['bulk'] ?? '');
            $st = $pdo->prepare('SELECT id FROM carrier_rate_tables WHERE id = ?');
            $st->execute([$tid]);
            if (!$st->fetchColumn()) {
                $err = '가격표를 찾을 수 없습니다.';
            } else {
                $pdo->beginTransaction();
                $ins = $pdo->prepare(
                    'INSERT INTO carrier_rates
                       (rate_table_id, zone_no, weight_from, weight_to, base_price, currency)
                     VALUES (?,?,?,?,?,\'KRW\')
                     ON DUPLICATE KEY UPDATE base_price = VALUES(base_price)');
                $n = 0; $skip = []; $ln0 = 0;
                foreach (preg_split('/\r?\n/', $body) as $ln) {
                    $ln0++;
                    $ln = trim($ln);
                    if ($ln === '') { continue; }
                    $col = preg_split('/[\t,]+/', $ln);
                    if (count($col) < 4) { $skip[] = $ln0; continue; }
                    $zone = (int)trim($col[0]);
                    $wf   = (float)str_replace(',', '', trim($col[1]));
                    $wt   = (float)str_replace(',', '', trim($col[2]));
                    $pr   = (float)str_replace(',', '', trim($col[3]));
                    if ($zone < 1 || $zone > 99 || $wt <= $wf || $pr < 0) {
                        $skip[] = $ln0; continue;
                    }
                    $ins->execute([$tid, $zone, $wf, $wt, $pr]);
                    $n++;
                }
                $pdo->commit();
                log_action('기준정보', 'UPDATE', 'carrier_rates', $tid, null, null, '단가 ' . $n . '건');
                flash('단가 ' . $n . '건을 넣었습니다.'
                      . ($skip ? ' 건너뛴 줄: ' . implode(', ', array_slice($skip, 0, 10))
                               . (count($skip) > 10 ? ' 외 ' . (count($skip)-10) . '줄' : '') : ''));
                redirect('?p=rate_table&id=' . $tid);
            }

        } elseif ($act === 'table_activate') {
            $tid = (int)post('table_id');
            $st = $pdo->prepare('SELECT * FROM carrier_rate_tables WHERE id = ?');
            $st->execute([$tid]);
            $t = $st->fetch();
            if (!$t) {
                $err = '가격표를 찾을 수 없습니다.';
            } else {
                $cnt = $pdo->prepare('SELECT COUNT(*) FROM carrier_rates WHERE rate_table_id = ?');
                $cnt->execute([$tid]);
                if ((int)$cnt->fetchColumn() === 0) {
                    $err = '단가가 한 건도 없는 가격표는 적용할 수 없습니다.';
                } else {
                    $pdo->beginTransaction();
                    // 같은 운송사·서비스의 기존 적용본을 하루 전으로 닫습니다
                    $pdo->prepare(
                        'UPDATE carrier_rate_tables
                            SET effective_to = DATE_SUB(?, INTERVAL 1 DAY), status = \'EXPIRED\'
                          WHERE carrier_id = ? AND status = \'ACTIVE\' AND id <> ?
                            AND (service_id <=> ?) AND effective_from < ?')
                        ->execute([$t['effective_from'], $t['carrier_id'], $tid,
                                   $t['service_id'], $t['effective_from']]);
                    $pdo->prepare('UPDATE carrier_rate_tables SET status = \'ACTIVE\' WHERE id = ?')
                        ->execute([$tid]);
                    log_action('기준정보', 'UPDATE', 'carrier_rate_tables', $tid,
                               (string)$t['name'], 'DRAFT', 'ACTIVE');
                    $pdo->commit();
                    flash('가격표를 적용했습니다. 같은 운송사의 이전 가격표는 닫혔습니다.');
                    redirect('?p=rate_table&id=' . $tid);
                }
            }

        } elseif ($act === 'web_sheets') {
            // 홈페이지 요금표 칸 ↔ 가격표 연결 (app/webrates.php)
            require_once APP_DIR . '/webrates.php';
            webrates_ensure_table($pdo);
            $up = $pdo->prepare('INSERT INTO web_rate_sheets (sheet_name, rate_table_id, zone_labels, updated_by)
                                 VALUES (?,?,?,?)
                                 ON DUPLICATE KEY UPDATE rate_table_id = VALUES(rate_table_id),
                                                         zone_labels = VALUES(zone_labels), updated_by = VALUES(updated_by)');
            $sel = (array)($_POST['sheet'] ?? []);
            $labs = (array)($_POST['labels'] ?? []);
            $n = 0;
            foreach (array_keys(WEB_RATE_SHEETS) as $sheet) {
                $tid = (int)($sel[$sheet] ?? 0);
                $lab = mb_substr(trim((string)($labs[$sheet] ?? '')), 0, 300);
                $up->execute([$sheet, $tid ?: null, $lab !== '' && $lab !== WEB_RATE_SHEETS[$sheet] ? $lab : null,
                              $_SESSION['admin_id'] ?? null]);
                if ($tid) { $n++; }
            }
            log_action('기준정보', 'UPDATE', 'web_rate_sheets', null, '홈페이지 요금표 연결', null, $n . '칸 연결');
            flash('홈페이지 요금표 연결을 저장했습니다 (' . $n . '칸). 홈페이지를 새로고침하면 바로 보입니다.');
            redirect('?p=rate_table' . ($id ? '&id=' . $id : '') . '#web');

        } elseif ($act === 'rate_clear') {
            $tid = (int)post('table_id');
            $st = $pdo->prepare('SELECT status FROM carrier_rate_tables WHERE id = ?');
            $st->execute([$tid]);
            if ($st->fetchColumn() !== 'DRAFT') {
                $err = '이미 적용된 가격표의 단가는 지울 수 없습니다. 새 가격표를 만드세요.';
            } else {
                $pdo->prepare('DELETE FROM carrier_rates WHERE rate_table_id = ?')->execute([$tid]);
                flash('단가를 모두 지웠습니다.');
                redirect('?p=rate_table&id=' . $tid);
            }
        }
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        error_log('단가표 처리 실패: ' . $e->getMessage());
        $err = '처리하지 못했습니다. 계정 권한을 확인하세요 (단가 삭제에는 DELETE 권한이 필요합니다).';
    }
}

// ---------------------------------------------------------------- 조회
$tables = db()->query(
    'SELECT t.*, c.code AS ccode, s.code AS scode,
            (SELECT COUNT(*) FROM carrier_rates r WHERE r.rate_table_id = t.id) AS rate_cnt
       FROM carrier_rate_tables t
       JOIN carriers c ON c.id = t.carrier_id
       LEFT JOIN carrier_services s ON s.id = t.service_id
      ORDER BY t.effective_from DESC, t.id DESC')->fetchAll();

$cur = null;
$grid = [];
$zones = [];
$bands = [];
if ($id > 0) {
    foreach ($tables as $t) { if ((int)$t['id'] === $id) { $cur = $t; } }
    if ($cur) {
        $st = db()->prepare('SELECT * FROM carrier_rates WHERE rate_table_id = ?
                              ORDER BY weight_from, zone_no');
        $st->execute([$id]);
        foreach ($st->fetchAll() as $r) {
            $band = rtrim(rtrim(number_format((float)$r['weight_from'], 2), '0'), '.')
                  . '~' . rtrim(rtrim(number_format((float)$r['weight_to'], 2), '0'), '.');
            $grid[$band][(int)$r['zone_no']] = (float)$r['base_price'];
            $zones[(int)$r['zone_no']] = true;
            $bands[$band] = (float)$r['weight_from'];
        }
        ksort($zones);
        asort($bands);
    }
}

layout_head('특송 기본가격표', 'rate_table');
?>
<div class="head">
  <h1>특송 기본가격표</h1>
  <div class="crumb">기준정보 &gt; 특송 기본가격표</div>
</div>

<?php if ($err !== ''): ?><div class="msg err"><?= h($err) ?></div><?php endif; ?>

<div class="card">
  <div class="ch">가격표 만들기
    <span style="font-weight:400;color:var(--ink3)">덮어쓰지 않고 버전으로 쌓입니다 — 과거 전표의 계산근거가 보존됩니다</span>
  </div>
  <div class="cb">
    <form method="post" class="f" style="align-items:flex-end">
      <?= csrf_field() ?>
      <input type="hidden" name="act" value="table_create">
      <div class="fw w1"><label>운송사 *</label>
        <select name="carrier_id" required>
          <option value="">선택</option>
          <?php foreach ($carriers as $c): ?>
            <option value="<?= (int)$c['id'] ?>"><?= h($c['code']) ?></option>
          <?php endforeach; ?>
        </select></div>
      <div class="fw w2"><label>서비스 (선택)</label>
        <select name="service_id">
          <option value="">전 서비스 공통</option>
          <?php foreach ($services as $s): ?>
            <option value="<?= (int)$s['id'] ?>"><?= h($s['code'] . ' · ' . $s['name']) ?></option>
          <?php endforeach; ?>
        </select></div>
      <div class="fw w3"><label>가격표 이름 *</label>
        <input type="text" name="name" required placeholder="DHL 2026 PRICE TABLE"></div>
      <div class="fw w1"><label>적용 시작일 *</label>
        <input type="date" name="effective_from" required value="<?= h(date('Y-01-01')) ?>"></div>
      <button class="btn pri">만들기</button>
    </form>
  </div>
  <table>
    <thead><tr>
      <th style="width:80px">운송사</th><th>가격표</th><th style="width:130px">서비스</th>
      <th style="width:110px">시작</th><th style="width:110px">종료</th>
      <th class="r" style="width:80px">단가수</th><th class="c" style="width:90px">상태</th>
      <th class="c" style="width:60px"></th>
    </tr></thead>
    <tbody>
    <?php foreach ($tables as $t): ?>
      <tr<?= $t['status']==='EXPIRED' ? ' style="opacity:.55"' : '' ?>>
        <td class="tnum" style="font-weight:700"><?= h($t['ccode']) ?></td>
        <td><?= h($t['name']) ?></td>
        <td class="tnum" style="font-size:11.5px"><?= $t['scode'] ? h($t['scode'])
             : '<span style="color:var(--ink3)">공통</span>' ?></td>
        <td class="tnum"><?= h($t['effective_from']) ?></td>
        <td class="tnum"><?= $t['effective_to'] ? h($t['effective_to'])
             : '<span style="color:var(--ink3)">열림</span>' ?></td>
        <td class="r tnum"><?= money($t['rate_cnt']) ?></td>
        <td class="c"><?php
          $m = ['DRAFT'=>['작성중','b-warn'],'ACTIVE'=>['적용중','b-ok'],'EXPIRED'=>['종료','b-info']];
          [$l,$cl] = $m[$t['status']] ?? [$t['status'],'b-info'];
          echo '<span class="badge '.$cl.'">'.h($l).'</span>'; ?></td>
        <td class="c"><a class="btn sm" href="?p=rate_table&amp;id=<?= (int)$t['id'] ?>">열기</a></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$tables): ?><tr><td colspan="8" class="empty">가격표가 없습니다.</td></tr><?php endif; ?>
    </tbody>
  </table>
</div>

<?php if ($cur): ?>
<div class="card">
  <div class="ch">
    <?= h($cur['ccode']) ?> · <?= h($cur['name']) ?>
    <span style="font-weight:400;color:var(--ink3)">
      <?= h($cur['effective_from']) ?> ~ <?= h($cur['effective_to'] ?: '열림') ?></span>
    <div style="margin-left:auto;display:flex;gap:8px">
      <?php if ($cur['status'] === 'DRAFT'): ?>
        <form method="post" style="display:inline"
              onsubmit="return confirm('이 가격표를 적용합니다. 같은 운송사의 기존 적용본은 닫힙니다.');">
          <?= csrf_field() ?><input type="hidden" name="act" value="table_activate">
          <input type="hidden" name="table_id" value="<?= $id ?>">
          <button class="btn pri sm">적용하기</button>
        </form>
        <form method="post" style="display:inline"
              onsubmit="return confirm('이 가격표의 단가를 모두 지웁니다.');">
          <?= csrf_field() ?><input type="hidden" name="act" value="rate_clear">
          <input type="hidden" name="table_id" value="<?= $id ?>">
          <button class="btn sm">단가 비우기</button>
        </form>
      <?php endif; ?>
    </div>
  </div>

  <?php if ($cur['status'] === 'DRAFT'): ?>
  <div class="cb">
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="act" value="rate_bulk">
      <input type="hidden" name="table_id" value="<?= $id ?>">
      <label>단가 일괄 입력 — 한 줄에 <code>존번호, 중량시작, 중량끝, 가격</code></label>
      <textarea name="bulk" rows="8" style="font-family:monospace;font-size:12px"
placeholder="1, 0, 0.5, 18000
1, 0.5, 1, 23000
1, 1, 1.5, 27500
4, 0, 0.5, 31000"></textarea>
      <div style="display:flex;gap:8px;margin-top:8px;align-items:center">
        <button class="btn pri">넣기</button>
        <span style="font-size:11.5px;color:var(--ink3)">
          엑셀에서 복사해 붙여넣어도 됩니다 (탭 구분). 중량 시작은 <b>초과</b>, 끝은 <b>이하</b>입니다.
          같은 존·구간이 있으면 가격만 바뀝니다.</span>
      </div>
    </form>
  </div>
  <?php endif; ?>

  <?php if (!$grid): ?>
    <div class="empty">단가가 없습니다. 위에 붙여넣어 주세요.</div>
  <?php else: ?>
  <div style="overflow-x:auto">
    <table style="min-width:100%">
      <thead><tr>
        <th style="width:130px">중량 (kg)</th>
        <?php foreach (array_keys($zones) as $z): ?>
          <th class="r" style="width:105px">Zone <?= (int)$z ?></th>
        <?php endforeach; ?>
      </tr></thead>
      <tbody>
      <?php foreach (array_keys($bands) as $band): ?>
        <tr>
          <td class="tnum" style="font-weight:600"><?= h($band) ?></td>
          <?php foreach (array_keys($zones) as $z): ?>
            <td class="r tnum"><?= isset($grid[$band][$z]) ? money($grid[$band][$z])
                 : '<span style="color:var(--ink3)">-</span>' ?></td>
          <?php endforeach; ?>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <div class="pager"><span>구간 <?= count($bands) ?>개 · Zone <?= count($zones) ?>개 ·
    단가 <?= money($cur['rate_cnt']) ?>건.
    빈 칸은 그 구간에 단가가 없다는 뜻입니다 — 계산기에서 조회되지 않습니다.</span></div>
  <?php endif; ?>
</div>
<?php endif; ?>
<?php
// ---------------------------------------------------------------- 홈페이지 요금표 연결
require_once APP_DIR . '/webrates.php';
$webMap = [];
try {
    webrates_ensure_table(db());
    foreach (db()->query('SELECT * FROM web_rate_sheets')->fetchAll() as $m) { $webMap[$m['sheet_name']] = $m; }
} catch (PDOException $e) {
    $webMap = [];
}
$canEditRates = route_can_edit('rate_table');
?>
<div class="card" id="web">
  <div class="ch">홈페이지 요금표 연결
    <span style="font-weight:400;color:var(--ink3)">연결한 칸은 이 단가표 숫자로 홈페이지(국제특송 요금표)에 바로 보입니다 — 새 버전을 적용하면 자동으로 따라감</span>
    <a class="btn sm" style="margin-left:auto" href="../service/express.html" target="_blank" rel="noopener">홈페이지에서 보기</a></div>
  <form method="post">
    <?= csrf_field() ?><input type="hidden" name="act" value="web_sheets">
    <table>
      <thead><tr><th style="width:170px">홈페이지 칸</th><th>연결할 가격표</th><th>머리글 (Zone 1, 2, … 순서, 쉼표)</th><th style="width:170px">지금 보이는 버전</th></tr></thead>
      <tbody>
      <?php foreach (WEB_RATE_SHEETS as $sheet => $defLab):
        $m = $webMap[$sheet] ?? null;
        $curT = $m && $m['rate_table_id'] ? webrates_current_table(db(), (int)$m['rate_table_id']) : null; ?>
        <tr>
          <td style="font-weight:600"><?= h(str_replace('_', ' ', $sheet)) ?></td>
          <td><select name="sheet[<?= h($sheet) ?>]"<?= $canEditRates ? '' : ' disabled' ?>>
            <option value="">— 연결 안 함 (엑셀 값 그대로) —</option>
            <?php foreach ($tables as $t): ?>
              <option value="<?= (int)$t['id'] ?>"<?= $m && (int)$m['rate_table_id'] === (int)$t['id'] ? ' selected' : '' ?>>
                <?= h($t['ccode'] . ($t['scode'] ? '/' . $t['scode'] : '') . ' · ' . $t['name'] . ' (' . $t['effective_from'] . ' · ' . $t['status'] . ')') ?></option>
            <?php endforeach; ?></select></td>
          <td><input type="text" name="labels[<?= h($sheet) ?>]" value="<?= h($m['zone_labels'] ?? $defLab) ?>" style="font-size:12px"<?= $canEditRates ? '' : ' disabled' ?>></td>
          <td style="font-size:12px"><?= $curT ? h($curT['name']) . '<div style="color:var(--ink3)">' . h($curT['effective_from']) . '부터</div>' : '<span style="color:var(--ink3)">엑셀</span>' ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php if ($canEditRates): ?>
    <div class="cb" style="display:flex;gap:10px;align-items:center;border-top:1px solid var(--line2)">
      <button class="btn pri">연결 저장</button>
      <span style="font-size:11.5px;color:var(--ink3)">중량물 · 지역표 · EMS 발송조건은 ERP 단가표에 없어 예전처럼 홈페이지 엑셀(rates.xlsx)에서 읽습니다.
        머리글은 홈페이지 표의 칸 이름입니다 — Zone 번호가 작은 것부터 차례로 붙습니다.</span>
    </div>
    <?php endif; ?>
  </form>
</div>
<?php layout_foot();
