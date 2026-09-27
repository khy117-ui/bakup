<?php
// 웹에서 직접 열면 실행되지 않게 막습니다 (nginx 면 .htaccess 가 무시됩니다)
if (!defined('APP_DIR')) { http_response_code(403); exit('Forbidden'); }

require_once APP_DIR . '/layout.php';
require_once APP_DIR . '/shop_ads.php';

/**
 * 광고 · 키워드 — 쿠팡 · 스마트스토어 광고 키워드 분석과 키워드 추천 (자세한 기준은 shop_ads.php).
 * 같은 판매처의 지난 보고서와 키워드별로 비교합니다 (ROAS 차이 · 광고비 · 매출 변화, 새 키워드 · 빠진 키워드).
 */
$err = '';
shop_ads_ensure_schema();
$cfg = shop_ads_cfg();
$target = max(50.0, (float)($cfg['ad_target_roas'] ?: 400));
$hasNaver = $cfg['naver_ad_api_key'] !== '' && $cfg['naver_ad_secret_key'] !== '' && $cfg['naver_ad_customer_id'] !== '';
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('act') === 'upload') {
    csrf_check();
    try {
        $chn = post('channel');
        if (!isset(AD_CHANNELS[$chn])) { throw new RuntimeException('판매처를 고르세요.'); }
        $f = $_FILES['csv'] ?? null;
        if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) {
            throw new RuntimeException('키워드 보고서 CSV 파일을 고르세요.');
        }
        if ($f['size'] > 30 * 1024 * 1024) { throw new RuntimeException('파일이 너무 큽니다 (30MB 까지).'); }
        if (preg_match('/\.xlsx?$/i', (string)$f['name'])) {
            throw new RuntimeException('엑셀(.xlsx) 파일은 읽지 못합니다. 엑셀에서 [다른 이름으로 저장 → CSV UTF-8] 로 저장해 올리세요.');
        }
        $rows = shop_ads_parse_csv((string)file_get_contents($f['tmp_name']));
        if (!$rows) { throw new RuntimeException('보고서에 키워드 줄이 없습니다.'); }
        $pf = preg_match('/^\d{4}-\d{2}-\d{2}$/', post('period_from')) ? post('period_from') : null;
        $pt = preg_match('/^\d{4}-\d{2}-\d{2}$/', post('period_to')) ? post('period_to') : null;
        $pdo->beginTransaction();
        $pdo->prepare('INSERT INTO ad_keyword_batches (channel, file_name, period_from, period_to, rows_cnt, uploaded_by)
                       VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$chn, mb_substr((string)$f['name'], 0, 200), $pf, $pt, count($rows), $_SESSION['admin_id'] ?? null]);
        $bid = (int)$pdo->lastInsertId();
        $ins = $pdo->prepare('INSERT INTO ad_keyword_stats (batch_id, channel, keyword, impressions, clicks, cost, revenue, orders)
                              VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
        foreach ($rows as $a) {
            $ins->execute([$bid, $chn, mb_substr($a['keyword'], 0, 200), $a['impressions'], $a['clicks'],
                           round($a['cost']), round($a['revenue']), $a['orders']]);
        }
        $pdo->commit();
        log_action('쇼핑몰', 'CREATE', 'ad_keyword_batches', $bid, AD_CHANNELS[$chn] . ' 키워드 보고서', null, count($rows) . '개 키워드');
        flash(AD_CHANNELS[$chn] . ' 키워드 ' . count($rows) . '개를 분석했습니다.');
        redirect('?p=ad_keywords&ch=' . $chn . '&b=' . $bid);
    } catch (RuntimeException $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        $err = $e->getMessage();
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        error_log('키워드 보고서 저장 실패: ' . $e->getMessage());
        $err = '저장하지 못했습니다.';
    }
}

// 어느 보고서를 볼지 — 판매처별 최근 올린 것
$ch = array_key_exists(query('ch'), AD_CHANNELS) ? query('ch') : 'coupang';
$st = $pdo->prepare('SELECT * FROM ad_keyword_batches WHERE channel = ? ORDER BY id DESC LIMIT 20');
$st->execute([$ch]);
$batches = $st->fetchAll();
$batch = null;
foreach ($batches as $b) { if ((int)$b['id'] === (int)query('b')) { $batch = $b; } }
$batch = $batch ?? ($batches[0] ?? null);
// 비교할 보고서 — 고르지 않으면 이번 보고서 바로 전에 올린 것, cmp=0 이면 비교 안 함
$prevBatch = null;
if ($batch && query('cmp') !== '0') {
    foreach ($batches as $b) {
        if ((int)$b['id'] === (int)$batch['id']) { continue; }
        if (query('cmp') !== '' ? (int)$b['id'] === (int)query('cmp') : (int)$b['id'] < (int)$batch['id']) { $prevBatch = $b; break; }
    }
}

$rows = [];
if ($batch) {
    $st = $pdo->prepare('SELECT * FROM ad_keyword_stats WHERE batch_id = ? ORDER BY cost DESC, clicks DESC');
    $st->execute([(int)$batch['id']]);
    foreach ($st->fetchAll() as $r) {
        $r['roas'] = (float)$r['cost'] > 0 ? round((float)$r['revenue'] / (float)$r['cost'] * 100) : 0;
        $r['ctr'] = (int)$r['impressions'] > 0 ? round((int)$r['clicks'] / (int)$r['impressions'] * 100, 2) : 0;
        $r['cpc'] = (int)$r['clicks'] > 0 ? round((float)$r['cost'] / (int)$r['clicks']) : 0;
        [$r['action'], $r['cls']] = shop_ads_action(['clicks' => (int)$r['clicks'], 'cost' => (float)$r['cost'], 'revenue' => (float)$r['revenue']], $target);
        $rows[] = $r;
    }
}
$cmp = null;
if ($prevBatch) {
    $st = $pdo->prepare('SELECT keyword, impressions, clicks, cost, revenue FROM ad_keyword_stats WHERE batch_id = ?');
    $st->execute([(int)$prevBatch['id']]);
    $cmp = shop_ads_compare($rows, $st->fetchAll(), $batch, $prevBatch);
    $rows = $cmp['rows'];
}
$sum = ['cost' => 0, 'revenue' => 0, 'clicks' => 0, 'impressions' => 0];
$byAct = [];
foreach ($rows as $r) {
    foreach ($sum as $k => $_) { $sum[$k] += (float)$r[$k]; }
    $byAct[$r['action']][] = $r;
    if (!empty($r['cmp']['new'])) { $byAct['새 키워드'][] = $r; }
    if (!empty($r['cmp']['trend'])) { $byAct[$r['cmp']['trend'] === 'up' ? 'ROAS 좋아짐' : 'ROAS 나빠짐'][] = $r; }
}
$excludeCost = array_sum(array_map(fn($r) => (float)$r['cost'], $byAct['제외키워드 등록'] ?? []));
$act = query('act');
$shown = $act !== '' && isset($byAct[$act]) ? $byAct[$act] : $rows;
// 지난 보고서 합계 (기간 길이가 다르면 이번 기간 길이로 맞춤)
$prevSum = null;
if ($cmp) {
    $st = $pdo->prepare('SELECT SUM(cost) cost, SUM(revenue) revenue, SUM(clicks) clicks FROM ad_keyword_stats WHERE batch_id = ?');
    $st->execute([(int)$prevBatch['id']]);
    $prevSum = array_map('floatval', $st->fetch());
}
$pctTxt = function (float $now, float $was): string {
    if ($was <= 0) { return '-'; }
    $v = round(($now - $was) / $was * 100);
    return ($v > 0 ? '+' : '') . $v . '%';
};
$batchLabel = fn(array $b) => $b['period_from'] ? $b['period_from'] . '~' . $b['period_to'] : substr((string)$b['uploaded_at'], 0, 10) . ' 올림';

// 분석 결과 CSV 내려받기
if (query('download') === '1' && $batch) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="ad-keywords-' . $ch . '-' . (int)$batch['id'] . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, array_merge(['키워드', '노출수', '클릭수', '클릭률(%)', 'CPC', '광고비', '전환매출', 'ROAS(%)', '할 일'],
                              $cmp ? ['지난 ROAS(%)', 'ROAS 차이(%p)', '지난 광고비', '광고비 변화(%)', '매출 변화(%)'] : []), ',', '"', '');
    foreach ($shown as $r) {
        $c = $r['cmp'] ?? null;
        fputcsv($out, array_merge([$r['keyword'], $r['impressions'], $r['clicks'], $r['ctr'], $r['cpc'], $r['cost'], $r['revenue'], $r['roas'], $r['action']],
                                  !$cmp ? [] : (!empty($c['new']) ? ['새 키워드', '', '', '', ''] : [$c['prev_roas'], $c['roas_diff'], $c['prev_cost'], $c['cost_pct'] ?? '', $c['rev_pct'] ?? ''])), ',', '"', '');
    }
    fclose($out);
    exit;
}

// 키워드 추천 — 씨앗 키워드: 직접 넣은 것, 없으면 이 보고서에서 매출 잘 나온 키워드 3개
$known = [];
foreach ($pdo->query('SELECT s.channel, s.keyword, s.cost, s.revenue FROM ad_keyword_stats s
                        JOIN (SELECT channel, MAX(id) AS id FROM ad_keyword_batches GROUP BY channel) l ON l.id = s.batch_id')->fetchAll() as $k) {
    $key = mb_strtolower(str_replace(' ', '', $k['keyword']));
    $roas = (float)$k['cost'] > 0 ? round((float)$k['revenue'] / (float)$k['cost'] * 100) : 0;
    $label = ($k['channel'] === 'coupang' ? '쿠팡' : '네이버') . ' ROAS ' . $roas . '%';
    $known[$key] = isset($known[$key]) ? $known[$key] . ' · ' . $label : $label;
}
$goodSeeds = array_slice(array_map(fn($r) => $r['keyword'],
    array_values(array_filter($rows, fn($r) => (float)$r['revenue'] > 0 && $r['roas'] >= $target))), 0, 3);
$seedIn = trim(query('seed'));
$recs = null;
$recErr = '';
if ($seedIn !== '') {
    try {
        $recs = shop_ads_recommend(preg_split('/[,\n]+/', $seedIn) ?: [], $known);
    } catch (RuntimeException $e) {
        $recErr = $e->getMessage();
    }
}
$canEdit = route_can_edit('ad_keywords');
$base = '?p=ad_keywords&ch=' . $ch . ($batch ? '&b=' . (int)$batch['id'] : '') . (query('cmp') !== '' ? '&cmp=' . (int)query('cmp') : '');

layout_head('광고 · 키워드', 'ad_keywords');
?>
<div class="head">
  <h1>광고 · 키워드</h1>
  <div class="crumb">쇼핑몰관리 &gt; 쿠팡 · 스마트스토어 광고 키워드 분석 · 추천 (목표 ROAS <?= (int)$target ?>%)</div>
</div>
<?php if ($err !== ''): ?><div class="msg err"><?= h($err) ?></div><?php endif; ?>

<?php if ($canEdit): ?>
<div class="card">
  <div class="ch">키워드 보고서 올리기</div>
  <form method="post" enctype="multipart/form-data" class="cb f" style="align-items:flex-end">
    <?= csrf_field() ?><input type="hidden" name="act" value="upload">
    <div class="fw w2"><label for="uc">광고</label>
      <select id="uc" name="channel"><?php foreach (AD_CHANNELS as $k => $lab): ?>
        <option value="<?= $k ?>"<?= $ch === $k ? ' selected' : '' ?>><?= h($lab) ?></option><?php endforeach; ?></select></div>
    <div class="fw w3"><label for="uf">키워드 보고서 CSV</label><input type="file" id="uf" name="csv" accept=".csv,.txt,text/csv" required></div>
    <div class="fw w1"><label for="pf">보고서 기간 (선택)</label><input type="date" id="pf" name="period_from"></div>
    <div class="fw w1"><label for="pt">&nbsp;</label><input type="date" id="pt" name="period_to"></div>
    <button class="btn pri">분석하기</button>
  </form>
  <div class="cb" style="border-top:1px solid var(--line2);font-size:12px;color:var(--ink2);line-height:1.8">
    <b>쿠팡</b> 광고센터 → 보고서 → 키워드 보고서 → 기간 선택 후 다운로드 (엑셀이면 CSV 로 다시 저장) ·
    <b>네이버</b> 검색광고 → 보고서 → 다차원 보고서(키워드) → CSV 다운로드.
    필요한 열: 키워드 · 노출수 · 클릭수 · 광고비(총비용) · 전환매출액. 광고그룹별로 나뉜 줄은 키워드로 합칩니다.
  </div>
</div>
<?php endif; ?>

<div class="card">
  <div class="ch">
    <?php foreach (AD_CHANNELS as $k => $lab): ?>
      <a class="btn sm<?= $ch === $k ? ' pri' : '' ?>" href="?p=ad_keywords&amp;ch=<?= $k ?>"><?= h($lab) ?></a>
    <?php endforeach; ?>
    <?php if ($batches): ?>
    <form method="get" style="margin-left:auto;display:flex;gap:6px;align-items:center">
      <input type="hidden" name="p" value="ad_keywords"><input type="hidden" name="ch" value="<?= h($ch) ?>">
      <select name="b" onchange="this.form.submit()" style="width:auto">
        <?php foreach ($batches as $b): ?>
          <option value="<?= (int)$b['id'] ?>"<?= $batch && (int)$batch['id'] === (int)$b['id'] ? ' selected' : '' ?>>
            <?= h(substr((string)$b['uploaded_at'], 0, 16)) ?> · <?= h($b['period_from'] ? $b['period_from'] . '~' . $b['period_to'] : (string)$b['file_name']) ?> (<?= (int)$b['rows_cnt'] ?>개)</option>
        <?php endforeach; ?></select>
      <?php if (count($batches) > 1): ?>
      <span style="font-size:12px;color:var(--ink2)">비교</span>
      <select name="cmp" onchange="this.form.submit()" style="width:auto">
        <option value="0"<?= !$prevBatch ? ' selected' : '' ?>>비교 안 함</option>
        <?php foreach ($batches as $b): if ($batch && (int)$b['id'] === (int)$batch['id']) { continue; } ?>
          <option value="<?= (int)$b['id'] ?>"<?= $prevBatch && (int)$prevBatch['id'] === (int)$b['id'] ? ' selected' : '' ?>>
            <?= h(substr((string)$b['uploaded_at'], 0, 16)) ?> · <?= h($b['period_from'] ? $b['period_from'] . '~' . $b['period_to'] : (string)$b['file_name']) ?></option>
        <?php endforeach; ?></select>
      <?php endif; ?></form>
    <?php endif; ?>
  </div>
  <?php if (!$batch): ?>
    <div class="empty"><?= h(AD_CHANNELS[$ch]) ?> 키워드 보고서를 아직 올리지 않았습니다. 위에서 CSV 를 올리면 키워드마다 할 일을 알려 드립니다.</div>
  <?php else: $roasAll = $sum['cost'] > 0 ? round($sum['revenue'] / $sum['cost'] * 100) : 0; ?>
  <div class="cb kpis" style="padding-top:12px">
    <?php $pRoasAll = $prevSum && $prevSum['cost'] > 0 ? round($prevSum['revenue'] / $prevSum['cost'] * 100) : null; $sc = $cmp['scale'] ?? 1; ?>
    <div class="kpi"><div class="lab">광고비</div><div class="val tnum"><?= money($sum['cost']) ?></div>
      <div class="sub">클릭 <?= money($sum['clicks']) ?> · 노출 <?= money($sum['impressions']) ?><?= $prevSum ? '<br>지난 대비 ' . $pctTxt($sum['cost'], $prevSum['cost'] * $sc) : '' ?></div></div>
    <div class="kpi"><div class="lab">전환매출</div><div class="val tnum"><?= money($sum['revenue']) ?></div>
      <?php if ($prevSum): ?><div class="sub">지난 대비 <?= $pctTxt($sum['revenue'], $prevSum['revenue'] * $sc) ?></div><?php endif; ?></div>
    <div class="kpi"><div class="lab">ROAS</div><div class="val tnum" style="color:<?= $roasAll >= $target ? '#1B7F5A' : '#C62828' ?>"><?= $roasAll ?>%</div>
      <div class="sub">목표 <?= (int)$target ?>%<?= $pRoasAll !== null ? ' · 지난 ' . $pRoasAll . '% (' . ($roasAll - $pRoasAll >= 0 ? '+' : '') . ($roasAll - $pRoasAll) . '%p)' : '' ?></div></div>
    <div class="kpi"><div class="lab">제외할 키워드</div><div class="val tnum" style="color:#C62828"><?= count($byAct['제외키워드 등록'] ?? []) ?>개</div>
      <div class="sub">매출 없이 쓴 광고비 <?= money($excludeCost) ?>원</div></div>
  </div>
  <?php if ($cmp): ?>
  <div class="cb" style="border-top:1px solid var(--line2);font-size:12px;color:var(--ink2)">
    <b>지난 보고서</b> <?= h($batchLabel($prevBatch)) ?> 와 비교합니다.
    <?= $cmp['scale'] != 1 ? '기간 길이가 달라(' . (int)$cmp['days'][0] . '일 → ' . (int)$cmp['days'][1] . '일) 광고비 · 매출 변화는 하루 평균으로 맞췄습니다. ' : '' ?>
    <?= !$cmp['days'][0] || !$cmp['days'][1] ? '보고서 기간을 넣지 않은 보고서가 있어 광고비 · 매출은 합계 그대로 비교합니다. ' : '' ?>
    좋아짐 · 나빠짐은 두 보고서 모두 클릭 20회 이상이고 ROAS 가 30% 넘게 달라진 키워드입니다.
  </div>
  <?php endif; ?>
  <div class="cb" style="border-top:1px solid var(--line2);display:flex;gap:6px;flex-wrap:wrap;align-items:center">
    <a class="btn sm<?= $act === '' ? ' pri' : '' ?>" href="<?= h($base) ?>">전체 <?= count($rows) ?></a>
    <?php foreach (['ROAS 나빠짐' => 'b-err', 'ROAS 좋아짐' => 'b-ok', '새 키워드' => 'b-info'] as $a => $_): if (empty($byAct[$a])) { continue; } ?>
      <a class="btn sm<?= $act === $a ? ' pri' : '' ?>" href="<?= h($base . '&act=' . rawurlencode($a)) ?>"><?= h($a) ?> <?= count($byAct[$a]) ?></a>
    <?php endforeach; ?>
    <?php if ($cmp && $cmp['dropped']): ?><a class="btn sm" href="#dropped">빠진 키워드 <?= count($cmp['dropped']) ?></a><?php endif; ?>
    <span style="width:1px;height:18px;background:var(--line2)"></span>
    <?php foreach (['제외키워드 등록', '입찰가 대폭 인하 또는 OFF', '입찰가 -10~20%', '유지', '입찰가 +10~20%', '데이터 부족 - 유지'] as $a): if (empty($byAct[$a])) { continue; } ?>
      <a class="btn sm<?= $act === $a ? ' pri' : '' ?>" href="<?= h($base . '&act=' . rawurlencode($a)) ?>"><?= h($a) ?> <?= count($byAct[$a]) ?></a>
    <?php endforeach; ?>
    <a class="btn sm" style="margin-left:auto" href="<?= h($base . ($act !== '' ? '&act=' . rawurlencode($act) : '') . '&download=1') ?>">CSV 내려받기</a>
  </div>
  <?php if (!empty($byAct['제외키워드 등록'])): ?>
  <details class="cb" style="border-top:1px solid var(--line2)">
    <summary style="cursor:pointer;font-size:12.5px;font-weight:600">제외키워드 목록 (복사해서 광고센터에 붙여넣기)</summary>
    <textarea rows="4" readonly onclick="this.select()" style="margin-top:6px;font-size:12px"><?= h(implode("\n", array_map(fn($r) => $r['keyword'], $byAct['제외키워드 등록']))) ?></textarea>
  </details>
  <?php endif; ?>
  <table>
    <thead><tr><th>키워드</th><th class="r">노출</th><th class="r">클릭</th><th class="r">클릭률</th><th class="r">CPC</th>
      <th class="r">광고비</th><th class="r">전환매출</th><th class="r">ROAS</th><?= $cmp ? '<th class="r">지난 대비</th>' : '' ?><th class="c">할 일</th><th class="c" style="width:60px"></th></tr></thead>
    <tbody>
    <?php foreach (array_slice($shown, 0, 500) as $r): ?>
      <tr><td style="font-weight:600"><?= h($r['keyword']) ?></td>
        <td class="r tnum"><?= money($r['impressions']) ?></td><td class="r tnum"><?= money($r['clicks']) ?></td>
        <td class="r tnum"><?= h($r['ctr']) ?>%</td><td class="r tnum"><?= money($r['cpc']) ?></td>
        <td class="r tnum"><?= money($r['cost']) ?></td><td class="r tnum"><?= money($r['revenue']) ?></td>
        <td class="r tnum"><?= (int)$r['roas'] ?>%</td>
        <?php if ($cmp): $c = $r['cmp']; ?>
        <td class="r tnum" style="font-size:12px;white-space:nowrap">
          <?php if ($c['new']): ?><span class="badge b-info">새 키워드</span>
          <?php else: ?>
            <span style="font-weight:600;color:<?= $c['trend'] === 'up' ? '#1B7F5A' : ($c['trend'] === 'down' ? '#C62828' : 'var(--ink2)') ?>"
                  title="지난 ROAS <?= (int)$c['prev_roas'] ?>%"><?= $c['roas_diff'] >= 0 ? '+' : '' ?><?= (int)$c['roas_diff'] ?>%p</span>
            <br><span style="color:var(--ink3)" title="지난 광고비 <?= h(money($c['prev_cost'])) ?>원">광고비 <?= $c['cost_pct'] === null ? '-' : ($c['cost_pct'] > 0 ? '+' : '') . $c['cost_pct'] . '%' ?></span>
          <?php endif; ?></td>
        <?php endif; ?>
        <td class="c"><span class="badge <?= $r['cls'] ?>"><?= h($r['action']) ?></span></td>
        <td class="c"><a class="btn sm" href="<?= h($base . '&seed=' . rawurlencode($r['keyword'])) ?>#rec" title="이 키워드로 연관 키워드 추천">추천</a></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php if (count($shown) > 500): ?><div class="cb" style="font-size:12px;color:var(--ink3)">500개까지 표시합니다. 전체는 CSV 로 내려받으세요.</div><?php endif; ?>
  <?php if ($cmp && $cmp['dropped']): ?>
  <details class="cb" id="dropped" style="border-top:1px solid var(--line2)">
    <summary style="cursor:pointer;font-size:12.5px;font-weight:600">빠진 키워드 <?= count($cmp['dropped']) ?>개 — 지난 보고서엔 있었지만 이번엔 없음 (광고를 껐거나 노출이 없었음)</summary>
    <table style="margin-top:6px">
      <thead><tr><th>키워드</th><th class="r">지난 클릭</th><th class="r">지난 광고비</th><th class="r">지난 ROAS</th></tr></thead>
      <tbody>
      <?php foreach (array_slice($cmp['dropped'], 0, 200) as $d): ?>
        <tr><td><?= h($d['keyword']) ?></td><td class="r tnum"><?= money($d['clicks']) ?></td>
          <td class="r tnum"><?= money($d['cost']) ?></td><td class="r tnum"><?= (int)$d['roas'] ?>%</td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </details>
  <?php endif; ?>
  <?php endif; ?>
</div>

<div class="card" id="rec">
  <div class="ch">키워드 추천 <span style="font-weight:400;color:var(--ink3);font-size:12px">네이버 검색광고 키워드도구 · 월 검색수와 경쟁도로 점수</span></div>
  <form method="get" class="cb f" style="align-items:flex-end">
    <input type="hidden" name="p" value="ad_keywords"><input type="hidden" name="ch" value="<?= h($ch) ?>">
    <?php if ($batch): ?><input type="hidden" name="b" value="<?= (int)$batch['id'] ?>"><?php endif; ?>
    <div class="fw gr" style="min-width:280px"><label for="sd">씨앗 키워드 (쉼표로 5개까지)</label>
      <input type="text" id="sd" name="seed" value="<?= h($seedIn !== '' ? $seedIn : implode(', ', $goodSeeds)) ?>" placeholder="예) 캠핑의자, 백패킹의자"></div>
    <button class="btn pri"<?= $hasNaver ? '' : ' disabled title="환경설정 → 연동 에 네이버 검색광고 API 키를 넣으면 쓸 수 있습니다"' ?>>추천 받기</button>
  </form>
  <?php if (!$hasNaver): ?>
    <div class="empty">키워드 추천은 네이버 검색광고 API 를 씁니다. searchad.naver.com → 도구 → API 사용 관리 에서 키를 받아
      <?= route_can_edit('settings') ? '<a href="?p=settings">환경설정 → 연동</a>' : '환경설정 → 연동' ?> 에 넣으세요. 스마트스토어 광고를 안 해도 키는 무료로 받을 수 있습니다.</div>
  <?php elseif ($recErr !== ''): ?>
    <div class="cb"><div class="msg err" style="margin:0"><?= h($recErr) ?></div></div>
  <?php elseif ($recs !== null): ?>
    <div class="cb" style="border-top:1px solid var(--line2);font-size:12px;color:var(--ink2)">
      <b>점수</b> = 월 검색수 × 경쟁도(낮음 1 · 중간 0.6 · 높음 0.3). <span class="badge b-ok">새 후보</span> 는 지금 광고하지 않는 키워드입니다.
      쿠팡은 키워드 검색수를 공개하지 않아 네이버 검색수를 참고로 보세요.</div>
    <table>
      <thead><tr><th>키워드</th><th class="r">월 검색수</th><th class="r">PC · 모바일</th><th class="r">월 클릭</th>
        <th class="c">경쟁도</th><th class="r">광고 수</th><th class="r">점수</th><th>지금 광고</th></tr></thead>
      <tbody>
      <?php foreach (array_slice($recs, 0, 100) as $k): ?>
        <tr><td style="font-weight:600"><?= h($k['keyword']) ?><?= $k['seed'] ? ' <span style="color:var(--ink3);font-weight:400">(씨앗)</span>' : '' ?></td>
          <td class="r tnum"><?= money($k['total']) ?></td>
          <td class="r tnum" style="color:var(--ink2)"><?= money($k['pc']) ?> · <?= money($k['mobile']) ?></td>
          <td class="r tnum"><?= money($k['clicks']) ?></td>
          <td class="c"><span class="badge <?= ['낮음' => 'b-ok', '중간' => 'b-warn', '높음' => 'b-err'][$k['comp']] ?? 'b-info' ?>"><?= h($k['comp'] ?: '-') ?></span></td>
          <td class="r tnum"><?= (int)$k['depth'] ?></td>
          <td class="r tnum" style="font-weight:700"><?= money($k['score']) ?></td>
          <td style="font-size:12px"><?= $k['known'] ? h($k['known']) : '<span class="badge b-ok">새 후보</span>' ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php else: ?>
    <div class="empty">씨앗 키워드를 넣고 [추천 받기] 를 누르세요.<?= $goodSeeds ? ' 목표 ROAS 를 넘긴 키워드를 미리 넣어 두었습니다.' : '' ?></div>
  <?php endif; ?>
</div>
<?php layout_foot();
