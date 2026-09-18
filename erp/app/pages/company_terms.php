<?php
require APP_DIR . '/layout.php';

$err = '';
$cid = (int)query('company_id', '0');
$TRADE = ['ALL' => '수출입 공통', 'EXPORT' => '수출', 'IMPORT' => '수입'];

$companies = db()->prepare(
    'SELECT id, company_code, name_ko FROM companies
      WHERE deleted_at IS NULL ORDER BY name_ko');
$companies->execute();
$companies = $companies->fetchAll();

$carriers = db()->query('SELECT id, code FROM carriers WHERE is_active = 1
                          ORDER BY sort_order, code')->fetchAll();
$services = db()->query('SELECT id, carrier_id, code, name FROM carrier_services
                          WHERE is_active = 1 ORDER BY carrier_id, code')->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $act = post('act');
    $pdo = db();
    try {
        if ($act === 'add') {
            $cid   = (int)post('company_id');
            $car   = (int)post('carrier_id');
            $svc   = post('service_id');
            $trade = post('trade_type', 'ALL');
            $rate  = (float)str_replace('%', '', post('discount_rate'));
            $from  = post('effective_from');

            if ($cid <= 0 || $car <= 0) {
                $err = '거래처와 운송사를 고르세요.';
            } elseif (!isset($TRADE[$trade])) {
                $err = '수출입 구분이 올바르지 않습니다.';
            } elseif ($rate < 0 || $rate > 100) {
                $err = '할인율은 0~100 사이여야 합니다. 이 값이 100 을 넘으면 운임이 음수가 됩니다.';
            } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
                $err = '적용 시작일을 입력하세요.';
            } else {
                $pdo->beginTransaction();
                // 같은 조합의 열린 구간을 닫습니다. 겹치면 어느 할인율인지 알 수 없습니다
                $pdo->prepare(
                    'UPDATE company_carrier_terms
                        SET effective_to = DATE_SUB(?, INTERVAL 1 DAY)
                      WHERE company_id = ? AND carrier_id = ? AND trade_type = ?
                        AND (service_id <=> ?) AND effective_to IS NULL
                        AND effective_from < ?')
                    ->execute([$from, $cid, $car, $trade,
                               $svc !== '' ? (int)$svc : null, $from]);
                $pdo->prepare(
                    'INSERT INTO company_carrier_terms
                       (company_id, carrier_id, service_id, trade_type, discount_rate,
                        fuel_applied, effective_from, memo)
                     VALUES (?,?,?,?,?,?,?,?)')
                    ->execute([$cid, $car, $svc !== '' ? (int)$svc : null, $trade, $rate,
                               post('fuel_applied') ? 1 : 0, $from, post('memo') ?: null]);
                log_action('기준정보', 'CREATE', 'company_carrier_terms',
                           (int)$pdo->lastInsertId(), null, null,
                           '할인율 ' . $rate . '% ' . $from . '~');
                $pdo->commit();
                flash('할인율을 등록했습니다. 같은 조건의 이전 구간은 닫았습니다.');
                redirect('?p=company_terms&company_id=' . $cid);
            }
        } elseif ($act === 'close') {
            $tid = (int)post('tid');
            $pdo->prepare(
                'UPDATE company_carrier_terms SET effective_to = ?
                  WHERE id = ? AND effective_to IS NULL')
                ->execute([date('Y-m-d'), $tid]);
            log_action('기준정보', 'UPDATE', 'company_carrier_terms', $tid, null, null, '오늘자로 종료');
            flash('오늘자로 종료 처리했습니다.');
            redirect('?p=company_terms&company_id=' . (int)post('company_id'));
        }
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        error_log('할인율 저장 실패: ' . $e->getMessage());
        $err = '저장하지 못했습니다.';
    }
}

$terms = [];
if ($cid > 0) {
    $st = db()->prepare(
        'SELECT t.*, ca.code AS ccode, s.code AS scode
           FROM company_carrier_terms t
           JOIN carriers ca ON ca.id = t.carrier_id
           LEFT JOIN carrier_services s ON s.id = t.service_id
          WHERE t.company_id = ?
          ORDER BY ca.sort_order, ca.code, t.trade_type, t.effective_from DESC');
    $st->execute([$cid]);
    $terms = $st->fetchAll();
}
$today = date('Y-m-d');

layout_head('업체별 할인율', 'company_terms');
?>
<div class="head">
  <h1>업체별 할인율</h1>
  <div class="crumb">기준정보 &gt; 업체별 할인율</div>
</div>

<?php if ($err !== ''): ?><div class="msg err"><?= h($err) ?></div><?php endif; ?>

<div class="card"><div class="cb">
  <form class="f" method="get" style="align-items:flex-end">
    <input type="hidden" name="p" value="company_terms">
    <div class="fw w3"><label for="cc">거래처</label>
      <select id="cc" name="company_id" onchange="this.form.submit()">
        <option value="0">선택하세요</option>
        <?php foreach ($companies as $c): ?>
          <option value="<?= (int)$c['id'] ?>"<?= $cid===(int)$c['id']?' selected':'' ?>>
            <?= h($c['name_ko']) ?> (<?= h($c['company_code']) ?>)</option>
        <?php endforeach; ?>
      </select></div>
  </form>
</div></div>

<?php if ($cid > 0): ?>
<div class="card">
  <div class="ch">할인율 등록
    <span style="font-weight:400;color:var(--ink3)">기간이 겹치지 않게 관리합니다 — 겹치면 어느 값을 쓸지 알 수 없습니다</span>
  </div>
  <div class="cb">
    <form method="post" class="f" style="align-items:flex-end">
      <?= csrf_field() ?>
      <input type="hidden" name="act" value="add">
      <input type="hidden" name="company_id" value="<?= $cid ?>">
      <div class="fw w1"><label>운송사 *</label>
        <select name="carrier_id" required>
          <option value="">선택</option>
          <?php foreach ($carriers as $c): ?>
            <option value="<?= (int)$c['id'] ?>"><?= h($c['code']) ?></option>
          <?php endforeach; ?>
        </select></div>
      <div class="fw w2"><label>서비스</label>
        <select name="service_id">
          <option value="">전 서비스 공통</option>
          <?php foreach ($services as $s): ?>
            <option value="<?= (int)$s['id'] ?>"><?= h($s['code'] . ' · ' . $s['name']) ?></option>
          <?php endforeach; ?>
        </select></div>
      <div class="fw w1"><label>수출입 구분</label>
        <select name="trade_type">
          <?php foreach ($TRADE as $k=>$v): ?><option value="<?= h($k) ?>"><?= h($v) ?></option><?php endforeach; ?>
        </select></div>
      <div class="fw w1"><label>할인율 (%) *</label>
        <input type="text" name="discount_rate" class="tnum" style="text-align:right" required placeholder="30"></div>
      <div class="fw w1"><label>적용 시작일 *</label>
        <input type="date" name="effective_from" required value="<?= h(date('Y-m-d')) ?>"></div>
      <div class="fw w1"><label>&nbsp;</label>
        <label style="font-weight:400;font-size:12.5px;display:flex;gap:6px;align-items:center;height:34px">
          <input type="checkbox" name="fuel_applied" value="1" checked> 유류할증 적용</label></div>
      <div class="fw gr" style="min-width:160px"><label>메모</label>
        <input type="text" name="memo"></div>
      <button class="btn pri">등록</button>
    </form>
  </div>

  <?php if (!$terms): ?>
    <div class="empty">등록된 할인율이 없습니다. 할인율이 없으면 단가계산기에서 <b>0%</b> 로 계산됩니다.</div>
  <?php else: ?>
  <table>
    <thead><tr>
      <th style="width:80px">운송사</th><th style="width:130px">서비스</th>
      <th style="width:110px">구분</th><th class="r" style="width:90px">할인율</th>
      <th class="c" style="width:100px">유류할증</th>
      <th style="width:110px">시작</th><th style="width:110px">종료</th>
      <th>메모</th><th class="c" style="width:80px">상태</th>
      <th class="c" style="width:70px"></th>
    </tr></thead>
    <tbody>
    <?php foreach ($terms as $t):
      $live = $t['effective_from'] <= $today
              && ($t['effective_to'] === null || $t['effective_to'] >= $today); ?>
      <tr<?= $live ? '' : ' style="opacity:.55"' ?>>
        <td class="tnum" style="font-weight:700"><?= h($t['ccode']) ?></td>
        <td class="tnum" style="font-size:11.5px"><?= $t['scode'] ? h($t['scode'])
             : '<span style="color:var(--ink3)">공통</span>' ?></td>
        <td><?= h($TRADE[$t['trade_type']] ?? $t['trade_type']) ?></td>
        <td class="r tnum" style="font-weight:700">
          <?= h(rtrim(rtrim(number_format((float)$t['discount_rate'],2),'0'),'.')) ?>%</td>
        <td class="c"><?= $t['fuel_applied'] ? '적용' : '<span style="color:var(--ink3)">미적용</span>' ?></td>
        <td class="tnum"><?= h($t['effective_from']) ?></td>
        <td class="tnum"><?= $t['effective_to'] ? h($t['effective_to'])
             : '<span style="color:var(--ink3)">열림</span>' ?></td>
        <td style="font-size:11.5px;color:var(--ink2)"><?= h($t['memo'] ?: '') ?></td>
        <td class="c"><?= $live ? '<span class="badge b-ok">적용중</span>' : '' ?></td>
        <td class="c">
          <?php if ($t['effective_to'] === null): ?>
          <form method="post" style="display:inline"
                onsubmit="return confirm('오늘자로 종료 처리합니다.');">
            <?= csrf_field() ?>
            <input type="hidden" name="act" value="close">
            <input type="hidden" name="tid" value="<?= (int)$t['id'] ?>">
            <input type="hidden" name="company_id" value="<?= $cid ?>">
            <button class="btn sm">종료</button>
          </form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <div class="pager"><span>할인율은 <b>지우지 않습니다.</b> 바뀌면 새로 등록하세요 —
    과거 전표가 그때의 할인율로 계산됐기 때문에 이력이 남아야 합니다.</span></div>
  <?php endif; ?>
</div>
<?php endif; ?>
<?php layout_foot();
