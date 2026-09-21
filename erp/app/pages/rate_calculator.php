<?php
require_once APP_DIR . '/layout.php';
require_once APP_DIR . '/ratecalc.php';

/**
 * 단가계산기 — 스펙 [21]
 *   기본가격 → 할인 → 할인후 운임 → 유류할증 → 공급가격
 * 각 단계의 근거(어느 가격표, 어느 할인율, 어느 유류할증)를 같이 보여줍니다.
 * 금액이 이상하면 어느 단계에서 어긋났는지 바로 알 수 있어야 하기 때문입니다.
 */

$carriers = db()->query('SELECT id, code, name FROM carriers WHERE is_active = 1
                          ORDER BY sort_order, code')->fetchAll();
$companies = db()->prepare('SELECT id, company_code, name_ko FROM companies
                             WHERE deleted_at IS NULL ORDER BY name_ko');
$companies->execute();
$companies = $companies->fetchAll();

$in = [
    'company_id' => query('company_id', ''),
    'carrier_id' => query('carrier_id', ''),
    'trade_type' => query('trade_type', 'EXPORT'),
    'country'    => strtoupper(query('country', '')),
    'zone_no'    => query('zone_no', ''),
    'actual_weight' => query('actual_weight', ''),
    'volume_l' => query('volume_l', ''), 'volume_w' => query('volume_w', ''),
    'volume_h' => query('volume_h', ''),
    'on_date'  => query('on_date', date('Y-m-d')),
];
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $in['on_date'])) { $in['on_date'] = date('Y-m-d'); }

$R = null;   // 계산 결과
$why = [];   // 계산이 안 되는 이유

if ($in['carrier_id'] !== '' && $in['actual_weight'] !== '') {
    $carId = (int)$in['carrier_id'];
    $day   = $in['on_date'];

    // 1) 청구중량 — 실중량과 부피중량 중 큰 값
    $aw = num($in['actual_weight']);
    $vw = 0.0;
    if ($in['volume_l'] !== '' && $in['volume_w'] !== '' && $in['volume_h'] !== '') {
        // 부피중량 = 가로 × 세로 × 높이 ÷ 5000 (cm 기준, 항공 표준)
        $vw = round(num($in['volume_l']) * num($in['volume_w']) * num($in['volume_h']) / 5000, 2);
    }
    $cw = max($aw, $vw);

    // 2~5) Zone · 기본가격 · 할인 · 유류할증 — 계산은 app/ratecalc.php 한 곳에서 (매출전표와 같은 값)
    $Q = rate_quote($carId, $in['company_id'] !== '' ? (int)$in['company_id'] : null,
                    $in['trade_type'], $day, $cw, $in['country'],
                    $in['zone_no'] !== '' ? (int)$in['zone_no'] : 0);
    $why = $Q['why'];
    if ($Q['ok']) {
        $R = [
            'aw' => $aw, 'vw' => $vw, 'cw' => $cw,
            'zone' => $Q['zone'], 'zone_src' => $Q['zone_src'],
            'base' => $Q['base'], 'table' => $Q['table'],
            'disc' => $Q['disc'], 'disc_amt' => $Q['disc_amt'], 'disc_src' => $Q['disc_src'],
            'after' => $Q['after'],
            'fuel' => $Q['fuel'], 'fuel_amt' => $Q['fuel_amt'], 'fuel_src' => $Q['fuel_src'],
            'fuel_basis' => $Q['fuel_basis'], 'fuel_applied' => $Q['fuel_applied'],
            'supply' => $Q['supply'],
        ];
    }
}

layout_head('단가계산기', 'rate_calculator');
?>
<div class="head">
  <h1>단가계산기</h1>
  <div class="crumb">영업관리 &gt; 단가계산기</div>
</div>

<div class="card">
  <div class="ch">조건</div>
  <div class="cb">
    <form class="f" method="get" style="align-items:flex-end">
      <input type="hidden" name="p" value="rate_calculator">
      <div class="fw w3"><label>거래처 <span style="font-weight:400;color:var(--ink3)">(할인율 적용)</span></label>
        <select name="company_id">
          <option value="">선택 안 함 (할인 0%)</option>
          <?php foreach ($companies as $c): ?>
            <option value="<?= (int)$c['id'] ?>"<?= (string)$in['company_id']===(string)$c['id']?' selected':'' ?>>
              <?= h($c['name_ko']) ?> (<?= h($c['company_code']) ?>)</option>
          <?php endforeach; ?>
        </select></div>
      <div class="fw w1"><label>운송사 *</label>
        <select name="carrier_id" required>
          <option value="">선택</option>
          <?php foreach ($carriers as $c): ?>
            <option value="<?= (int)$c['id'] ?>"<?= (string)$in['carrier_id']===(string)$c['id']?' selected':'' ?>>
              <?= h($c['code']) ?></option>
          <?php endforeach; ?>
        </select></div>
      <div class="fw w1"><label>구분</label>
        <select name="trade_type">
          <option value="EXPORT"<?= $in['trade_type']==='EXPORT'?' selected':'' ?>>수출</option>
          <option value="IMPORT"<?= $in['trade_type']==='IMPORT'?' selected':'' ?>>수입</option>
        </select></div>
      <div class="fw w1"><label>국가코드</label>
        <input type="text" name="country" maxlength="2" value="<?= h($in['country']) ?>"
               placeholder="US" style="text-transform:uppercase"></div>
      <div class="fw w1"><label>또는 Zone</label>
        <input type="text" name="zone_no" class="tnum" value="<?= h($in['zone_no']) ?>" placeholder="4"></div>
      <div class="fw w1"><label>기준일</label>
        <input type="date" name="on_date" value="<?= h($in['on_date']) ?>"></div>
    </form>
    <form class="f" method="get" style="align-items:flex-end;margin-top:12px;
          border-top:1px solid var(--line2);padding-top:12px">
      <input type="hidden" name="p" value="rate_calculator">
      <input type="hidden" name="company_id" value="<?= h($in['company_id']) ?>">
      <input type="hidden" name="carrier_id" value="<?= h($in['carrier_id']) ?>">
      <input type="hidden" name="trade_type" value="<?= h($in['trade_type']) ?>">
      <input type="hidden" name="country" value="<?= h($in['country']) ?>">
      <input type="hidden" name="zone_no" value="<?= h($in['zone_no']) ?>">
      <input type="hidden" name="on_date" value="<?= h($in['on_date']) ?>">
      <div class="fw w1"><label>실중량 (kg) *</label>
        <input type="text" name="actual_weight" class="tnum" style="text-align:right"
               value="<?= h($in['actual_weight']) ?>" required></div>
      <div class="fw w1"><label>가로 (cm)</label>
        <input type="text" name="volume_l" class="tnum" style="text-align:right" value="<?= h($in['volume_l']) ?>"></div>
      <div class="fw w1"><label>세로 (cm)</label>
        <input type="text" name="volume_w" class="tnum" style="text-align:right" value="<?= h($in['volume_w']) ?>"></div>
      <div class="fw w1"><label>높이 (cm)</label>
        <input type="text" name="volume_h" class="tnum" style="text-align:right" value="<?= h($in['volume_h']) ?>"></div>
      <button class="btn pri">계산</button>
    </form>
    <div style="font-size:11.5px;color:var(--ink3);margin-top:8px">
      부피중량 = 가로 × 세로 × 높이 ÷ 5000. 청구중량은 실중량과 부피중량 중 <b>큰 값</b>입니다.
    </div>
  </div>
</div>

<?php if ($why): ?>
  <div class="msg err">
    <?php foreach ($why as $w): ?><div><?= h($w) ?></div><?php endforeach; ?>
    <div style="margin-top:6px">
      <a href="?p=rate_table">가격표 확인</a> ·
      <a href="?p=carriers&amp;tab=zone">Zone 확인</a> ·
      <a href="?p=carriers&amp;tab=fuel">유류할증 확인</a>
    </div>
  </div>
<?php endif; ?>

<?php if ($R): ?>
<div class="card">
  <div class="ch">계산 결과
    <span style="font-weight:400;color:var(--ink3)">
      기준일 <?= h($in['on_date']) ?> · Zone <?= (int)$R['zone'] ?> (<?= h($R['zone_src']) ?>)</span>
  </div>

  <div class="cb" style="display:flex;gap:14px;flex-wrap:wrap">
    <div class="kpi" style="flex:0 0 190px"><div class="lab">실중량</div>
      <div class="val tnum" style="font-size:19px"><?= h(rtrim(rtrim(number_format($R['aw'],2),'0'),'.')) ?> kg</div></div>
    <div class="kpi" style="flex:0 0 190px"><div class="lab">부피중량</div>
      <div class="val tnum" style="font-size:19px">
        <?= $R['vw'] > 0 ? h(rtrim(rtrim(number_format($R['vw'],2),'0'),'.')) . ' kg' : '-' ?></div></div>
    <div class="kpi" style="flex:0 0 190px;border-color:var(--accent-line);background:var(--accent-tint)">
      <div class="lab" style="color:var(--accent-deep)">청구중량</div>
      <div class="val tnum" style="font-size:19px;color:var(--accent-deep)">
        <?= h(rtrim(rtrim(number_format($R['cw'],2),'0'),'.')) ?> kg</div></div>
  </div>

  <table>
    <thead><tr>
      <th style="width:40px" class="c">#</th><th style="width:170px">단계</th>
      <th class="r" style="width:150px">금액</th><th>근거</th>
    </tr></thead>
    <tbody>
      <tr>
        <td class="c tnum">1</td>
        <td style="font-weight:600">기본가격</td>
        <td class="r tnum" style="font-weight:700"><?= money($R['base']) ?></td>
        <td style="font-size:11.5px;color:var(--ink2)">
          <?= h($R['table']['name']) ?>
          (<?= h(rtrim(rtrim(number_format((float)$R['table']['weight_from'],2),'0'),'.')) ?>
           ~ <?= h(rtrim(rtrim(number_format((float)$R['table']['weight_to'],2),'0'),'.')) ?> kg 구간)
          · <a href="?p=rate_table&amp;id=<?= (int)$R['table']['tid'] ?>">가격표 보기</a></td>
      </tr>
      <tr>
        <td class="c tnum">2</td>
        <td style="font-weight:600">할인
          <span class="tnum" style="color:var(--ink3)"><?= h(rtrim(rtrim(number_format($R['disc'],2),'0'),'.')) ?>%</span></td>
        <td class="r tnum" style="color:var(--err-fg)">
          <?= $R['disc_amt'] > 0 ? '− ' . money($R['disc_amt']) : money(0) ?></td>
        <td style="font-size:11.5px;color:var(--ink2)"><?= h($R['disc_src']) ?></td>
      </tr>
      <tr style="background:#F7FAFB">
        <td class="c"></td>
        <td style="font-weight:700">할인 후 운임</td>
        <td class="r tnum" style="font-weight:700"><?= money($R['after']) ?></td>
        <td></td>
      </tr>
      <tr>
        <td class="c tnum">3</td>
        <td style="font-weight:600">유류할증
          <span class="tnum" style="color:var(--ink3)"><?= h(rtrim(rtrim(number_format($R['fuel'],2),'0'),'.')) ?>%</span></td>
        <td class="r tnum" style="color:<?= $R['fuel_amt']>0?'var(--ok-fg)':'var(--ink3)' ?>">
          <?= $R['fuel_amt'] > 0 ? '+ ' . money($R['fuel_amt']) : money(0) ?></td>
        <td style="font-size:11.5px;color:var(--ink2)">
          <?= h($R['fuel_src']) ?> ·
          <?= $R['fuel_basis'] === 'BASE_PRICE' ? '기본가격 기준' : '할인후 운임 기준' ?>
          <?php if (!$R['fuel_applied']): ?>
            · <span style="color:var(--err-fg)">이 거래처는 유류할증 미적용</span>
          <?php endif; ?></td>
      </tr>
      <tr style="background:var(--accent-tint)">
        <td class="c"></td>
        <td style="font-weight:700;color:var(--accent-deep)">공급가격</td>
        <td class="r tnum" style="font-weight:700;font-size:16px;color:var(--accent-deep)">
          <?= money($R['supply']) ?></td>
        <td style="font-size:11.5px;color:var(--ink2)">
          영세율 기준입니다. 과세 항목이면 VAT 10% 가 따로 붙습니다.</td>
      </tr>
    </tbody>
  </table>
  <div class="pager"><span>
    이 금액은 <b>참고용</b>입니다. 매출전표에 자동으로 들어가지 않습니다 —
    전표에는 실제 청구할 금액을 직접 넣으세요.</span></div>
</div>
<?php endif; ?>
<?php layout_foot();
