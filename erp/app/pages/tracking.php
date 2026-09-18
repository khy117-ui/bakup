<?php
require_once APP_DIR . '/layout.php';

$eid = entity_id();
$err = '';
$sid = (int)query('shipment_id', '0');

$carriers = db()->query('SELECT id, code, tracking_enabled FROM carriers
                          WHERE is_active = 1 ORDER BY sort_order, code')->fetchAll();

$sh = null;
if ($sid > 0) {
    $st = db()->prepare(
        'SELECT s.*, c.name_ko, ca.code AS carrier
           FROM shipments s
           JOIN companies c ON c.id = s.company_id
           JOIN carriers ca ON ca.id = s.carrier_id
          WHERE s.id = ? AND s.business_entity_id = ? AND s.deleted_at IS NULL');
    $st->execute([$sid, $eid]);
    $sh = $st->fetch();
    if (!$sh) { exit('전표를 찾을 수 없습니다.'); }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $act = post('act');
    $pdo = db();
    try {
        if ($act === 'add_trk') {
            $sid = (int)post('shipment_id');
            $car = (int)post('carrier_id');
            $no  = strtoupper(trim(post('tracking_no')));
            if ($sid <= 0 || $car <= 0 || $no === '') {
                $err = '전표 · 운송사 · 추적번호를 모두 입력하세요.';
            } elseif (!preg_match('/^[A-Z0-9\-]{4,60}$/', $no)) {
                $err = '추적번호는 영문·숫자·하이픈만 쓸 수 있습니다.';
            } else {
                $dup = $pdo->prepare('SELECT id FROM tracking_numbers
                                       WHERE carrier_id = ? AND tracking_no = ?');
                $dup->execute([$car, $no]);
                if ($dup->fetchColumn()) {
                    $err = '같은 운송사에 이미 등록된 추적번호입니다.';
                } else {
                    $pdo->prepare(
                        'INSERT INTO tracking_numbers
                           (shipment_id, carrier_id, tracking_no, accepted_on)
                         VALUES (?,?,?,?)')
                        ->execute([$sid, $car, $no, post('accepted_on') ?: null]);
                    log_action('물류', 'CREATE', 'tracking_numbers',
                               (int)$pdo->lastInsertId(), $no);
                    flash('추적번호를 등록했습니다.');
                    redirect('?p=tracking&shipment_id=' . $sid);
                }
            }

        } elseif ($act === 'add_event') {
            $tid = (int)post('tracking_number_id');
            $at  = post('event_at');
            $stt = post('status');
            if ($tid <= 0 || $stt === '') {
                $err = '상태를 입력하세요.';
            } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}/', $at)) {
                $err = '일시 형식이 올바르지 않습니다.';
            } else {
                $at = str_replace('T', ' ', $at);
                if (strlen($at) === 16) { $at .= ':00'; }
                $pdo->beginTransaction();
                $pdo->prepare(
                    'INSERT INTO tracking_events
                       (tracking_number_id, event_at, location, status, description)
                     VALUES (?,?,?,?,?)
                     ON DUPLICATE KEY UPDATE description = VALUES(description)')
                    ->execute([$tid, $at, post('location') ?: null, $stt, post('description') ?: null]);
                // 가장 최근 이벤트를 현재 상태로 올려둡니다
                $pdo->prepare(
                    'UPDATE tracking_numbers t
                        SET t.current_status = (SELECT e.status FROM tracking_events e
                                                 WHERE e.tracking_number_id = t.id
                                                 ORDER BY e.event_at DESC LIMIT 1),
                            t.current_location = (SELECT e.location FROM tracking_events e
                                                   WHERE e.tracking_number_id = t.id
                                                   ORDER BY e.event_at DESC LIMIT 1),
                            t.last_checked_at = NOW(), t.last_checked_by = ?
                      WHERE t.id = ?')
                    ->execute([$_SESSION['admin_id'] ?? null, $tid]);
                $pdo->commit();
                flash('배송 이력을 추가했습니다.');
                redirect('?p=tracking&shipment_id=' . (int)post('shipment_id'));
            }

        } elseif ($act === 'delivered') {
            $tid = (int)post('tracking_number_id');
            $pdo->prepare('UPDATE tracking_numbers SET delivered_at = NOW(),
                                  current_status = \'DELIVERED\' WHERE id = ?')->execute([$tid]);
            log_action('물류', 'UPDATE', 'tracking_numbers', $tid, null, null, '배송완료 표시');
            flash('배송완료로 표시했습니다.');
            redirect('?p=tracking&shipment_id=' . (int)post('shipment_id'));

        } elseif ($act === 'epost_fetch') {
            // 우체국 EMS 행방조회 Open API 에서 이력을 가져와 쌓습니다 (같은 일시 · 상태는 건너뜀)
            require_once APP_DIR . '/epost.php';
            $tid = (int)post('tracking_number_id');
            $st = $pdo->prepare('SELECT t.id, t.tracking_no, t.shipment_id FROM tracking_numbers t
                                   JOIN shipments s ON s.id = t.shipment_id
                                  WHERE t.id = ? AND s.business_entity_id = ?');
            $st->execute([$tid, $eid]);
            $trk = $st->fetch();
            if (!$trk) {
                $err = '추적번호를 찾을 수 없습니다.';
            } else {
                $r = epost_ems_trace((string)$trk['tracking_no']);
                $_SESSION['epost_raw'][$tid] = mb_substr((string)$r['raw'], 0, 4000);
                if (!$r['ok']) {
                    $err = $r['error'];
                } else {
                    $pdo->beginTransaction();
                    $ins = $pdo->prepare('INSERT IGNORE INTO tracking_events
                                            (tracking_number_id, event_at, location, status, description)
                                          VALUES (?,?,?,?,?)');
                    $new = 0;
                    foreach ($r['events'] as $e) {
                        $ins->execute([$tid, $e['at'], $e['location'], $e['status'], $e['description']]);
                        $new += $ins->rowCount();
                    }
                    $last = $r['events'] ? end($r['events']) : null;
                    $done = $last && preg_match('/배달완료|delivered/iu', $last['status'] . ' ' . ($last['description'] ?? ''));
                    $pdo->prepare('UPDATE tracking_numbers
                                      SET current_status = COALESCE(?, current_status),
                                          current_location = COALESCE(?, current_location),
                                          delivered_at = CASE WHEN ? = 1 AND delivered_at IS NULL THEN ? ELSE delivered_at END,
                                          last_checked_at = NOW(), last_checked_by = ?
                                    WHERE id = ?')
                        ->execute([$last['status'] ?? null, $last['location'] ?? null, $done ? 1 : 0,
                                   $last['at'] ?? null, $_SESSION['admin_id'] ?? null, $tid]);
                    $pdo->commit();
                    log_action('물류', 'UPDATE', 'tracking_numbers', $tid, (string)$trk['tracking_no'], null,
                               '우체국 조회 — 이력 ' . count($r['events']) . '건 중 새 것 ' . $new . '건');
                    flash($r['events']
                        ? '우체국에서 이력 ' . count($r['events']) . '건을 받았습니다 (새로 ' . $new . '건).'
                          . ($done ? ' 배달완료로 표시했습니다.' : '')
                        : '우체국 응답은 받았지만 이력을 읽지 못했습니다. 아래 "우체국 응답 원문" 을 캡처해 보내 주세요.');
                    redirect('?p=tracking&shipment_id=' . (int)$trk['shipment_id']);
                }
            }
        }
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        error_log('추적 저장 실패: ' . $e->getMessage());
        $err = '저장하지 못했습니다.';
    }
}

// 검색 (전표 지정이 없을 때)
$kw = query('kw');
$found = [];
if ($sid === 0 && $kw !== '') {
    $st = db()->prepare(
        'SELECT s.id, s.awb_no, s.voucher_date, c.name_ko, ca.code AS carrier,
                (SELECT COUNT(*) FROM tracking_numbers t WHERE t.shipment_id = s.id) AS cnt
           FROM shipments s
           JOIN companies c ON c.id = s.company_id
           JOIN carriers ca ON ca.id = s.carrier_id
          WHERE s.business_entity_id = ? AND s.deleted_at IS NULL
            AND (s.awb_no LIKE ? OR c.name_ko LIKE ?
                 OR EXISTS (SELECT 1 FROM tracking_numbers t
                             WHERE t.shipment_id = s.id AND t.tracking_no LIKE ?))
          ORDER BY s.voucher_date DESC LIMIT 30');
    $like = '%' . $kw . '%';
    $st->execute([$eid, $like, $like, $like]);
    $found = $st->fetchAll();
}

$trks = [];
if ($sid > 0) {
    $st = db()->prepare(
        'SELECT t.*, ca.code AS carrier FROM tracking_numbers t
           JOIN carriers ca ON ca.id = t.carrier_id
          WHERE t.shipment_id = ? ORDER BY t.id');
    $st->execute([$sid]);
    $trks = $st->fetchAll();
    foreach ($trks as $i => $t) {
        $e = db()->prepare('SELECT * FROM tracking_events WHERE tracking_number_id = ?
                             ORDER BY event_at DESC');
        $e->execute([$t['id']]);
        $trks[$i]['events'] = $e->fetchAll();
    }
}

layout_head('화물추적', 'tracking');
?>
<div class="head">
  <h1>화물추적</h1>
  <div class="crumb">물류관리 &gt; 화물추적</div>
  <?php if ($sh): ?>
    <div class="right"><a class="btn" href="?p=tracking">다른 전표 찾기</a>
      <a class="btn" href="?p=shipment_form&amp;id=<?= $sid ?>">전표 열기</a></div>
  <?php endif; ?>
</div>

<?php if ($err !== ''): ?><div class="msg err"><?= h($err) ?></div><?php endif; ?>

<div class="msg" style="background:var(--info-bg);color:var(--info-fg)">
  운송사 API 연동은 아직 없습니다. <b>지금은 사람이 조회해서 넣는 방식</b>입니다.
  기존 시스템은 추적 이력을 아예 저장하지 않았는데, 여기서는 넣는 만큼 쌓입니다.
</div>

<?php if (!$sh): ?>
<div class="card">
  <div class="ch">전표 찾기</div>
  <div class="cb">
    <form class="f" method="get" style="align-items:flex-end">
      <input type="hidden" name="p" value="tracking">
      <div class="fw w3"><label for="kw">AWB · 거래처명 · 추적번호</label>
        <input type="text" id="kw" name="kw" value="<?= h($kw) ?>" autofocus></div>
      <button class="btn pri">검색</button>
    </form>
  </div>
  <?php if ($kw !== ''): ?>
    <?php if (!$found): ?>
      <div class="empty">찾는 전표가 없습니다.</div>
    <?php else: ?>
    <table>
      <thead><tr><th style="width:105px">전표일</th><th style="width:165px">AWB</th>
        <th>거래처</th><th class="c" style="width:65px">운송사</th>
        <th class="c" style="width:80px">추적번호</th><th class="c" style="width:60px"></th></tr></thead>
      <tbody>
      <?php foreach ($found as $f): ?>
        <tr>
          <td class="tnum"><?= h($f['voucher_date']) ?></td>
          <td class="tnum" style="font-weight:600"><?= h($f['awb_no']) ?></td>
          <td><?= h($f['name_ko']) ?></td>
          <td class="c"><?= h($f['carrier']) ?></td>
          <td class="c tnum"><?= (int)$f['cnt'] ?></td>
          <td class="c"><a class="btn sm" href="?p=tracking&amp;shipment_id=<?= (int)$f['id'] ?>">열기</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  <?php endif; ?>
</div>
<?php else: ?>

<div class="card">
  <div class="ch"><?= h($sh['awb_no']) ?> · <?= h($sh['name_ko']) ?>
    <span style="font-weight:400;color:var(--ink3)">
      <?= h($sh['voucher_date']) ?> · <?= h($sh['carrier']) ?> ·
      <?= h($sh['dest_city'] ?: '-') ?></span>
  </div>
  <div class="cb">
    <form method="post" class="f" style="align-items:flex-end">
      <?= csrf_field() ?>
      <input type="hidden" name="act" value="add_trk">
      <input type="hidden" name="shipment_id" value="<?= $sid ?>">
      <div class="fw w1"><label>운송사 *</label>
        <select name="carrier_id" required>
          <?php foreach ($carriers as $c): ?>
            <option value="<?= (int)$c['id'] ?>"<?= $c['code']===$sh['carrier']?' selected':'' ?>>
              <?= h($c['code']) ?></option>
          <?php endforeach; ?>
        </select></div>
      <div class="fw w2"><label>추적번호 *</label>
        <input type="text" name="tracking_no" class="tnum" required
               style="text-transform:uppercase" placeholder="1234567890"></div>
      <div class="fw w1"><label>접수일</label>
        <input type="date" name="accepted_on" value="<?= h($sh['ship_date'] ?: $sh['voucher_date']) ?>"></div>
      <button class="btn pri">번호 추가</button>
    </form>
    <div style="font-size:11.5px;color:var(--ink3);margin-top:8px">
      한 건에 추적번호가 여러 개 붙을 수 있습니다 (분할 발송 등).
    </div>
  </div>
</div>

<?php if (!$trks): ?>
  <div class="card"><div class="empty">등록된 추적번호가 없습니다.</div></div>
<?php endif; ?>

<?php foreach ($trks as $t): ?>
<div class="card">
  <div class="ch">
    <span class="tnum" style="font-size:14px"><?= h($t['tracking_no']) ?></span>
    <span class="badge b-info"><?= h($t['carrier']) ?></span>
    <?php if ($t['delivered_at']): ?>
      <span class="badge b-ok">배송완료</span>
    <?php elseif ($t['current_status']): ?>
      <span class="badge b-warn"><?= h($t['current_status']) ?></span>
    <?php endif; ?>
    <span style="margin-left:auto;font-weight:400;font-size:11.5px;color:var(--ink3)">
      <?php if ($t['last_checked_at']): ?>
        마지막 확인 <?= h($t['last_checked_at']) ?>
      <?php else: ?>확인 이력 없음<?php endif; ?>
    </span>
  </div>
  <?php
    // EMS · 국제등기 번호 모양이면 우체국 Open API 로 바로 가져올 수 있습니다
    $isEms = (bool)preg_match('/^[A-Z]{2}\d{9}[A-Z]{2}$/', (string)$t['tracking_no']);
    $raw = $_SESSION['epost_raw'][(int)$t['id']] ?? '';
  ?>
  <?php if ($isEms): ?>
  <div class="cb" style="border-bottom:1px solid var(--line2);display:flex;gap:8px;align-items:center;flex-wrap:wrap">
    <?php if (route_can_edit('tracking')): ?>
    <form method="post" style="display:inline">
      <?= csrf_field() ?>
      <input type="hidden" name="act" value="epost_fetch">
      <input type="hidden" name="tracking_number_id" value="<?= (int)$t['id'] ?>">
      <button class="btn pri">우체국에서 이력 가져오기</button>
    </form>
    <?php endif; ?>
    <a class="btn" target="_blank" rel="noopener"
       href="https://service.epost.go.kr/trace.RetrieveEmsRigiTraceList.comm?POST_CODE=<?= h(urlencode((string)$t['tracking_no'])) ?>&amp;displayHeader=N">우체국 사이트에서 보기</a>
    <span style="font-size:11.5px;color:var(--ink3)">EMS 행방조회 Open API — 같은 이력은 두 번 쌓이지 않습니다</span>
    <?php if ($raw !== ''): ?>
      <details style="width:100%;margin-top:6px"><summary style="cursor:pointer;font-size:12px">우체국 응답 원문 (마지막 조회)</summary>
        <pre style="white-space:pre-wrap;word-break:break-all;font-size:11px;max-height:240px;overflow:auto;background:#F7FAFB;padding:8px;border-radius:6px"><?= h($raw) ?></pre></details>
    <?php endif; ?>
  </div>
  <?php endif; ?>
  <div class="cb">
    <form method="post" class="f" style="align-items:flex-end">
      <?= csrf_field() ?>
      <input type="hidden" name="act" value="add_event">
      <input type="hidden" name="shipment_id" value="<?= $sid ?>">
      <input type="hidden" name="tracking_number_id" value="<?= (int)$t['id'] ?>">
      <div class="fw w2"><label>일시 *</label>
        <input type="datetime-local" name="event_at" required
               value="<?= h(date('Y-m-d\TH:i')) ?>"></div>
      <div class="fw w1"><label>위치</label>
        <input type="text" name="location" placeholder="INCHEON"></div>
      <div class="fw w2"><label>상태 *</label>
        <input type="text" name="status" required placeholder="발송 / 통관중 / 배송중 / 배송완료"></div>
      <div class="fw gr" style="min-width:160px"><label>설명</label>
        <input type="text" name="description"></div>
      <button class="btn">이력 추가</button>
    </form>
    <?php if (!$t['delivered_at']): ?>
      <!-- 이력 추가 폼 **밖에** 둡니다. 안에 넣으면 브라우저가 두 폼을 하나로 합쳐
           act 값이 겹치고, '이력 추가'를 눌러도 배송완료로 찍힙니다 -->
      <form method="post" style="margin-top:8px">
        <?= csrf_field() ?>
        <input type="hidden" name="act" value="delivered">
        <input type="hidden" name="shipment_id" value="<?= $sid ?>">
        <input type="hidden" name="tracking_number_id" value="<?= (int)$t['id'] ?>">
        <button class="btn">배송완료 표시</button>
      </form>
    <?php endif; ?>
  </div>
  <?php if (!$t['events']): ?>
    <div class="empty">배송 이력이 없습니다.</div>
  <?php else: ?>
  <table>
    <thead><tr><th style="width:160px">일시</th><th style="width:130px">위치</th>
      <th style="width:160px">상태</th><th>설명</th></tr></thead>
    <tbody>
    <?php foreach ($t['events'] as $e): ?>
      <tr>
        <td class="tnum"><?= h($e['event_at']) ?></td>
        <td><?= h($e['location'] ?: '-') ?></td>
        <td style="font-weight:600"><?= h($e['status']) ?></td>
        <td style="color:var(--ink2)"><?= h($e['description'] ?: '') ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>
<?php endforeach; ?>
<?php endif; ?>
<?php layout_foot();
