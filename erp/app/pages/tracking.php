<?php
require_once APP_DIR . '/layout.php';

$eid = entity_id();
$err = '';
$sid = (int)query('shipment_id', '0');

$carriers = db()->query('SELECT id, code, name, tracking_enabled FROM carriers
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

        } elseif ($act === 'api_key') {
            // 화물추적 화면에서 바로 조회 인증키 넣기 — 환경설정 권한자만. which = epost(우체국) / dhl / fedex
            // FedEx 는 API Key · Secret Key 두 개 — 적은 칸만 바꿉니다
            require_once APP_DIR . '/epost.php';
            require_once APP_DIR . '/dhl.php';
            require_once APP_DIR . '/fedex.php';
            $which = in_array(post('which'), ['dhl', 'fedex'], true) ? post('which') : 'epost';
            $k = (string)preg_replace('/\s+/u', '', post('api_key'));
            $k2 = (string)preg_replace('/\s+/u', '', post('api_secret'));
            $label = ['epost' => '우체국 Open API 인증키', 'dhl' => 'DHL API 키', 'fedex' => 'FedEx 키'][$which];
            $okShape = fn(string $v) => (bool)preg_match('/^[A-Za-z0-9%+\/=_-]{16,300}$/', $v);
            if (!route_can_edit('settings')) {
                $err = '인증키는 환경설정 권한이 있는 관리자만 넣을 수 있습니다.';
            } elseif ($which === 'fedex' ? ($k === '' && $k2 === '') || ($k !== '' && !$okShape($k))
                                           || ($k2 !== '' && !$okShape($k2))
                                         : !$okShape($k)) {
                $err = $label . ' 모양이 아닙니다. 발급 화면의 키를 그대로 붙여넣어 주세요.';
            } else {
                if ($which === 'fedex') {
                    if ($k !== '')  { fedex_save_key('api', $k); }
                    if ($k2 !== '') { fedex_save_key('secret', $k2); }
                    $saved = trim(($k !== '' ? 'API Key ····' . substr($k, -4) : '') . ' '
                                  . ($k2 !== '' ? 'Secret Key ····' . substr($k2, -4) : ''));
                } else {
                    $which === 'dhl' ? dhl_save_key($k) : epost_save_key($k);
                    $saved = '끝 4자리 ' . substr($k, -4);
                }
                log_action('시스템', 'UPDATE', 'app_settings', null, $label, null, '(새 값으로 바꿈)');
                flash($label . '를 저장했습니다 (' . $saved . ').');
                redirect('?p=tracking' . ((int)post('shipment_id') > 0 ? '&shipment_id=' . (int)post('shipment_id') : ''));
            }

        } elseif ($act === 'track_fetch') {
            // 운송사 조회 API 에서 이력을 가져와 쌓습니다 (같은 일시 · 상태는 건너뜀). src = epost(우체국 EMS) / dhl / fedex
            $src = in_array(post('src'), ['dhl', 'fedex'], true) ? post('src') : 'epost';
            $who = ['epost' => '우체국', 'dhl' => 'DHL', 'fedex' => 'FedEx'][$src];
            $tid = (int)post('tracking_number_id');
            $st = $pdo->prepare('SELECT t.id, t.tracking_no, t.shipment_id FROM tracking_numbers t
                                   JOIN shipments s ON s.id = t.shipment_id
                                  WHERE t.id = ? AND s.business_entity_id = ?');
            $st->execute([$tid, $eid]);
            $trk = $st->fetch();
            if (!$trk) {
                $err = '추적번호를 찾을 수 없습니다.';
            } else {
                require_once APP_DIR . '/track_any.php';
                $r = track_fetch($src, (string)$trk['tracking_no']);
                $_SESSION['track_raw'][$tid] = [$who, mb_substr((string)$r['raw'], 0, 4000)];
                if (!$r['ok']) {
                    $err = $r['error'];
                } else {
                    // 홈페이지 조회와 같은 저장 로직 (배달완료 판별 포함)
                    [$new, $done] = track_save_events($pdo, $tid, $r['events'], $_SESSION['admin_id'] ?? null);
                    log_action('물류', 'UPDATE', 'tracking_numbers', $tid, (string)$trk['tracking_no'], null,
                               $who . ' 조회 — 이력 ' . count($r['events']) . '건 중 새 것 ' . $new . '건');
                    flash($r['events']
                        ? $who . '에서 이력 ' . count($r['events']) . '건을 받았습니다 (새로 ' . $new . '건).'
                          . ($done ? ' 배달완료로 표시했습니다.' : '')
                        : $who . ' 응답은 받았지만 이력을 읽지 못했습니다. 아래 "응답 원문" 을 캡처해 보내 주세요.');
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

$loadTrks = function (int $sid): array {
    $st = db()->prepare(
        'SELECT t.*, ca.code AS carrier, ca.name AS carrier_name FROM tracking_numbers t
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
    return $trks;
};
$trks = $sid > 0 ? $loadTrks($sid) : [];

// 자동 추적 — 전표를 열면:
//   · 추적번호가 없고 AWB 가 그 운송사 번호 모양이면 AWB 를 추적번호로 등록 (이관 전표, 예: FedEx 871350927454)
//   · 배송완료 전인데 한 번도 안 봤거나 1시간 넘게 안 본 번호는 운송사에서 바로 가져옴 (한 번에 2건까지)
require_once APP_DIR . '/track_any.php';
if ($sh && $_SERVER['REQUEST_METHOD'] === 'GET' && route_can_edit('tracking')) {
    try {
        if (!$trks && ($newTid = track_auto_register(db(), $sid)) > 0) {
            log_action('물류', 'CREATE', 'tracking_numbers', $newTid, (string)$sh['awb_no'], null, 'AWB 번호를 추적번호로 자동 등록');
            $trks = $loadTrks($sid);
        }
        $pulled = 0;
        foreach ($trks as $t) {
            if ($pulled >= 2 || $t['delivered_at']
                || ($t['last_checked_at'] && strtotime((string)$t['last_checked_at']) > time() - 3600)) { continue; }
            $asrc = track_detect_src((string)$t['tracking_no'], $t['carrier'] . ' ' . ($t['carrier_name'] ?? ''));
            if (!in_array($asrc, TRACK_API_SRC, true) || !track_has_key($asrc)) { continue; }
            $pulled++;
            $r = track_fetch($asrc, (string)$t['tracking_no']);
            $_SESSION['track_raw'][(int)$t['id']] = [TRACK_SRC_NAME[$asrc], mb_substr((string)$r['raw'], 0, 4000)];
            if ($r['ok']) {
                track_save_events(db(), (int)$t['id'], $r['events'], $_SESSION['admin_id'] ?? null);
            } else {
                db()->prepare('UPDATE tracking_numbers SET last_checked_at = NOW() WHERE id = ?')->execute([(int)$t['id']]);
                $err = $r['error'];
            }
        }
        if ($pulled > 0) { $trks = $loadTrks($sid); }
    } catch (PDOException $e) {
        error_log('자동 추적 실패: ' . $e->getMessage());
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

<?php
// 운송사 조회 인증키 — 여기서 바로 넣을 수 있게 (환경설정 권한자만)
require_once APP_DIR . '/epost.php';
require_once APP_DIR . '/dhl.php';
require_once APP_DIR . '/fedex.php';
require_once APP_DIR . '/track_any.php';
$epKey  = epost_key();
$dhlKey = dhl_key();
[$fxId, $fxSecret] = fedex_creds();
$keyBadge = fn(string $k) => $k !== '' ? '<span class="badge b-ok">저장됨 ····' . h(substr($k, -4)) . '</span>'
                                       : '<span class="badge b-warn">없음</span>';
if (route_can_edit('settings')): ?>
<details class="card"<?= $epKey === '' || $dhlKey === '' || $fxId === '' || $fxSecret === '' ? ' open' : '' ?>>
  <summary class="ch" style="cursor:pointer">배송조회 인증키
    <span style="font-weight:400;font-size:12px">우체국 EMS <?= $keyBadge($epKey) ?> · DHL <?= $keyBadge($dhlKey) ?>
      · FedEx <?= $keyBadge($fxId !== '' && $fxSecret !== '' ? $fxSecret : '') ?></span></summary>
  <?php foreach ([['epost', '우체국 EMS — 공공데이터포털 일반 인증키', '마이페이지의 일반 인증키 붙여넣기'],
                  ['dhl', 'DHL — developer.dhl.com 앱의 API Key (Consumer Key)', 'MyApps > 앱 > Credentials 의 API Key 붙여넣기']] as [$w, $lab, $ph]): ?>
  <div class="cb" style="border-top:1px solid var(--line2)">
    <form method="post" class="f" style="align-items:flex-end" autocomplete="off">
      <?= csrf_field() ?>
      <input type="hidden" name="act" value="api_key">
      <input type="hidden" name="which" value="<?= $w ?>">
      <input type="hidden" name="shipment_id" value="<?= $sid ?>">
      <div class="fw gr" style="min-width:260px"><label for="k-<?= $w ?>"><?= h($lab) ?></label>
        <input type="text" id="k-<?= $w ?>" name="api_key" class="tnum" required spellcheck="false"
               placeholder="<?= h($ph) ?> (공백은 자동으로 뺍니다)"></div>
      <button class="btn pri">저장</button>
    </form>
  </div>
  <?php endforeach; ?>
  <div class="cb" style="border-top:1px solid var(--line2)">
    <form method="post" class="f" style="align-items:flex-end" autocomplete="off">
      <?= csrf_field() ?>
      <input type="hidden" name="act" value="api_key">
      <input type="hidden" name="which" value="fedex">
      <input type="hidden" name="shipment_id" value="<?= $sid ?>">
      <div class="fw gr" style="min-width:220px"><label for="k-fx1">FedEx — API Key (Client ID)
          <?= $fxId !== '' ? '<span style="color:var(--ink3)">····' . h(substr($fxId, -4)) . '</span>' : '' ?></label>
        <input type="text" id="k-fx1" name="api_key" class="tnum" spellcheck="false"
               placeholder="developer.fedex.com 프로젝트의 Production API Key"></div>
      <div class="fw gr" style="min-width:220px"><label for="k-fx2">FedEx — Secret Key
          <?= $fxSecret !== '' ? '<span style="color:var(--ink3)">····' . h(substr($fxSecret, -4)) . '</span>' : '' ?></label>
        <input type="password" id="k-fx2" name="api_secret" class="tnum" spellcheck="false" autocomplete="new-password"
               placeholder="같은 화면의 Secret Key"></div>
      <button class="btn pri">저장</button>
    </form>
  </div>
  <div class="cb" style="padding-top:0;font-size:11.5px;color:var(--ink3)">
    저장 후에는 끝 4자리만 보입니다. DHL 은 처음 한도가 하루 250건 · 5초에 1건입니다.
    FedEx 는 두 칸 중 바꿀 칸만 적으면 됩니다 (샌드박스 키는 실제 번호가 조회되지 않습니다).</div>
</details>
<?php endif; ?>

<div class="msg" style="background:var(--info-bg);color:var(--info-fg)">
  <b>우체국 EMS</b>(영문2+숫자9+영문2) · <b>DHL</b>(숫자 10자리) · <b>FedEx</b>(숫자 12 · 15자리) 번호는 버튼 한 번으로 이력을 가져옵니다
  (위 인증키 필요). 다른 운송사는 사람이 조회해서 넣습니다. 넣은 이력은 전부 쌓입니다.
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
              <?= h($c['name'] . ' (' . $c['code'] . ')') ?></option>
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
    // 번호 모양 · 운송사로 어디에 물어볼지 정합니다 (app/track_any.php — 홈페이지 조회와 같은 규칙)
    $no      = (string)$t['tracking_no'];
    $src     = track_detect_src($no, $t['carrier'] . ' ' . ($t['carrier_name'] ?? ''));
    $srcName = ['epost' => '우체국', 'dhl' => 'DHL', 'fedex' => 'FedEx', 'ups' => 'UPS'][$src] ?? '';
    $siteUrl = track_site_url($src, $no);
    [$rawWho, $raw] = $_SESSION['track_raw'][(int)$t['id']] ?? ['', ''];
  ?>
  <?php if ($src !== ''): ?>
  <div class="cb" style="border-bottom:1px solid var(--line2);display:flex;gap:8px;align-items:center;flex-wrap:wrap">
    <?php if (in_array($src, TRACK_API_SRC, true) && route_can_edit('tracking')): ?>
    <form method="post" style="display:inline">
      <?= csrf_field() ?>
      <input type="hidden" name="act" value="track_fetch">
      <input type="hidden" name="src" value="<?= $src ?>">
      <input type="hidden" name="tracking_number_id" value="<?= (int)$t['id'] ?>">
      <button class="btn pri"><?= $srcName ?>에서 이력 가져오기</button>
    </form>
    <?php endif; ?>
      <a class="btn" target="_blank" rel="noopener" href="<?= h($siteUrl) ?>"><?= $srcName ?> 사이트에서 보기</a>
    <span style="font-size:11.5px;color:var(--ink3)">같은 이력은 두 번 쌓이지 않습니다</span>
    <?php if ($raw !== ''): ?>
      <details style="width:100%;margin-top:6px"><summary style="cursor:pointer;font-size:12px"><?= h($rawWho) ?> 응답 원문 (마지막 조회)</summary>
        <pre style="white-space:pre-wrap;word-break:break-all;font-size:11px;max-height:240px;overflow:auto;background:#F7FAFB;padding:8px;border-radius:6px"><?= h($raw) ?></pre></details>
    <?php endif; ?>
  </div>
  <?php endif; ?>
  <?php if (!$t['events']): ?>
    <div class="empty">배송 이력이 없습니다.<?= $src !== '' ? ' 위 [' . $srcName . '에서 이력 가져오기] 를 누르면 여기에 추적 세부사항이 뜹니다.' : '' ?></div>
  <?php else: ?>
  <!-- 추적 세부사항 — 운송사 사이트처럼 날짜별로 묶고, 최신이 위 -->
  <div class="ch" style="border-top:0;font-size:13px">추적 세부사항
    <span style="font-weight:400;font-size:12px;color:var(--ink3)"><?= count($t['events']) ?>건 · 최신순</span></div>
  <table>
    <thead><tr><th style="width:70px">시각</th><th style="width:170px">상태</th>
      <th style="width:170px">위치</th><th>설명</th></tr></thead>
    <tbody>
    <?php $prevDay = ''; foreach ($t['events'] as $i => $e):
          $day = substr((string)$e['event_at'], 0, 10);
          if ($day !== $prevDay): $prevDay = $day; ?>
      <tr><td colspan="4" style="background:var(--line2,#F1F4F6);font-weight:700;font-size:12px">
        <?= h($day) ?> (<?= ['일','월','화','수','목','금','토'][(int)date('w', strtotime($day))] ?>)</td></tr>
    <?php endif; ?>
      <tr<?= $i === 0 ? ' style="background:#F3FAF6"' : '' ?>>
        <td class="tnum"><?= h(substr((string)$e['event_at'], 11, 5)) ?></td>
        <td style="font-weight:600"><?= h($e['status']) ?></td>
        <td><?= h($e['location'] ?: '-') ?></td>
        <td style="color:var(--ink2)"><?= h($e['description'] ?: '') ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
  <details class="cb" style="border-top:1px solid var(--line2)"<?= $src === '' && !$t['events'] ? ' open' : '' ?>>
    <summary style="cursor:pointer;font-size:12.5px;font-weight:600">직접 이력 추가 · 배송완료 표시</summary>
    <form method="post" class="f" style="align-items:flex-end;margin-top:8px">
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
  </details>
</div>
<?php endforeach; ?>
<?php endif; ?>
<?php layout_foot();
