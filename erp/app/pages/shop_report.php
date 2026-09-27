<?php
// 웹에서 직접 열면 실행되지 않게 막습니다 (nginx 면 .htaccess 가 무시됩니다)
if (!defined('APP_DIR')) { http_response_code(403); exit('Forbidden'); }

require_once APP_DIR . '/layout.php';
require_once APP_DIR . '/shop_report.php';

/**
 * 월간 보고서 — 한 달 매출 · 순이익 · 광고 · 채널 · 상품 TOP 10 · 운영을 A4 한 장 PDF 로 보고 받고 메일로 보냅니다.
 * 매월 1일 오전 9시 뒤 지난달 보고서가 자동으로 메일로 갑니다 (환경설정 → 쇼핑몰 → 월간 보고서 메일).
 */
$err = '';
shop_report_ensure_schema();
$months = [];
for ($i = 0; $i < 13; $i++) { $months[] = date('Y-m', strtotime(date('Y-m-01') . " -{$i} month")); }
$ym = in_array(query('ym'), $months, true) ? query('ym') : $months[1];

if (query('pdf') === '1') {
    try {
        $bin = shop_report_pdf(shop_report_data($ym));
        header('Content-Type: application/pdf');
        header('Content-Disposition: ' . (query('dl') === '1' ? 'attachment' : 'inline') . '; filename="shop-report-' . $ym . '.pdf"');
        header('Content-Length: ' . strlen($bin));
        echo $bin;
        exit;
    } catch (Throwable $e) {
        error_log('월간 보고서 PDF 실패: ' . $e->getMessage());
        $err = '보고서를 만들지 못했습니다.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('act') === 'send' && route_can_edit('shop_report')) {
    csrf_check();
    $to = trim(post('to'));
    [$ok, $msg] = shop_report_send($ym, $to);
    log_action('쇼핑몰', 'EXPORT', 'shop_report', null, "월간 보고서 {$ym} 메일", null, $msg);
    if ($ok) {
        flash("{$ym} 보고서를 보냈습니다. {$msg}");
        redirect('?p=shop_report&ym=' . $ym);
    }
    $err = '메일을 보내지 못했습니다: ' . $msg;
}

$r = shop_report_data($ym);
require_once APP_DIR . '/notify.php';
$defTo = shop_setting('shop_report_emails', '') ?: (notify_cfg()['notify_staff_emails'] ?? '');
$autoOn = shop_setting('shop_report_on', '예') === '예';
$g = fn(?float $v) => $v === null ? '-' : ($v >= 0 ? '+' : '') . number_format($v, 1) . '%';

layout_head('월간 보고서', 'shop_report');
?>
<div class="head">
  <h1>월간 보고서</h1>
  <div class="crumb">쇼핑몰관리 &gt; 한 달 매출 · 순이익 · 광고 · 상품을 한 장 PDF 로 (매월 1일 메일)</div>
</div>
<?php if ($err !== ''): ?><div class="msg err"><?= h($err) ?></div><?php endif; ?>

<div class="card">
  <div class="ch" style="flex-wrap:wrap;gap:8px">
    <form method="get" style="display:flex;gap:6px;align-items:center">
      <input type="hidden" name="p" value="shop_report">
      <select name="ym" onchange="this.form.submit()" style="width:auto">
        <?php foreach ($months as $m): ?><option value="<?= $m ?>"<?= $m === $ym ? ' selected' : '' ?>><?= h(substr($m, 0, 4) . '년 ' . (int)substr($m, 5) . '월' . ($m === $months[0] ? ' (이번 달, 진행 중)' : '')) ?></option><?php endforeach; ?>
      </select></form>
    <a class="btn sm" href="?p=shop_report&amp;ym=<?= $ym ?>&amp;pdf=1" target="_blank">PDF 새 창으로 보기</a>
    <a class="btn sm pri" href="?p=shop_report&amp;ym=<?= $ym ?>&amp;pdf=1&amp;dl=1">PDF 받기</a>
    <?php if (route_can_edit('shop_report')): ?>
    <form method="post" style="margin-left:auto;display:flex;gap:6px;align-items:center" onsubmit="return confirm('이 보고서를 메일로 보낼까요?');">
      <?= csrf_field() ?><input type="hidden" name="act" value="send">
      <input type="text" name="to" value="<?= h($defTo) ?>" placeholder="받는 메일 (쉼표로 여러 개)" style="width:260px">
      <button class="btn sm">메일로 보내기</button></form>
    <?php endif; ?>
  </div>
  <div class="cb" style="font-size:12px;color:var(--ink2);border-bottom:1px solid var(--line2)">
    자동 메일: <?= $autoOn ? '<span class="badge b-ok">매월 1일 오전 9시 뒤</span>' : '<span class="badge b-warn">꺼짐</span>' ?>
    · 받는 곳 <?= h($defTo !== '' ? $defTo : '없음 — 환경설정 → 쇼핑몰 → 월간 보고서 받는 메일') ?>
    · 마지막 발송 <?= h(shop_state_get('last_report_result') ?? '-') ?>
  </div>
  <div class="cb kpis" style="padding-top:12px">
    <div class="kpi"><div class="lab">매출</div><div class="val tnum"><?= money($r['sales']) ?></div><div class="sub">전월 대비 <?= $g($r['sales_g']) ?></div></div>
    <div class="kpi"><div class="lab">주문</div><div class="val tnum"><?= money($r['orders']) ?>건</div><div class="sub">객단가 <?= money(round($r['aov'])) ?>원</div></div>
    <div class="kpi"><div class="lab">순이익</div><div class="val tnum"><?= money(round($r['profit'])) ?></div><div class="sub">이익률 <?= number_format((float)$r['margin'], 1) ?>%</div></div>
    <div class="kpi"><div class="lab">광고비</div><div class="val tnum"><?= money(round($r['ad'])) ?></div><div class="sub">ROAS <?= $r['roas'] !== null ? $r['roas'] . '%' : '-' ?></div></div>
  </div>
  <iframe src="?p=shop_report&amp;ym=<?= $ym ?>&amp;pdf=1" title="월간 보고서 PDF" style="width:100%;height:1000px;border:0;border-top:1px solid var(--line2)"></iframe>
</div>
<?php layout_foot();
