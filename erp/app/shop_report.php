<?php
declare(strict_types=1);

// 웹에서 직접 열면 실행되지 않게 막습니다 (nginx 면 .htaccess 가 무시됩니다)
if (!defined('APP_DIR')) { http_response_code(403); exit('Forbidden'); }

require_once APP_DIR . '/shop_ship.php';
require_once APP_DIR . '/pdf_lite.php';

/**
 * 쇼핑몰 월간 보고서 — 한 달 매출 · 순이익 · 광고 · 채널 · 상품 · 운영을 A4 한 장 PDF 로.
 * 매월 1일 오전 9시 뒤 처음 ERP 가 열릴 때 지난달 보고서를 메일로 보냅니다 (환경설정 → 쇼핑몰).
 */

function shop_report_ensure_schema(): void
{
    shop_ship_ensure_schema();
    if (!empty($_SESSION['schema_shop_report_v1'])) { return; }
    try {
        db()->exec("INSERT IGNORE INTO app_settings
                      (setting_key, setting_val, group_ko, label_ko, help_ko, input_type, options_csv, sort_order)
                    VALUES ('shop_report_on', '예', '쇼핑몰', '월간 보고서 메일',
                            '예 = 매월 1일 지난달 보고서(PDF)를 메일로 보냅니다', 'select', '예,아니오', 50),
                           ('shop_report_emails', NULL, '쇼핑몰', '월간 보고서 받는 메일',
                            '쉼표로 여러 개. 비우면 알림 설정의 담당자 메일로 보냅니다', 'text', NULL, 51)");
        $_SESSION['schema_shop_report_v1'] = 1;
    } catch (PDOException $e) {
        error_log('월간 보고서 설정 준비 실패: ' . $e->getMessage());
    }
}

/** 'YYYY-MM' 한 달 숫자 모으기 */
function shop_report_data(string $ym): array
{
    if (!preg_match('/^\d{4}-\d{2}$/', $ym)) { throw new RuntimeException('월 형식이 맞지 않습니다.'); }
    $from = $ym . '-01';
    $to = date('Y-m-t', strtotime($from));
    $next = date('Y-m-d', strtotime($to . ' +1 day'));
    $pFrom = date('Y-m-01', strtotime($from . ' -1 month'));
    $pTo = date('Y-m-t', strtotime($pFrom));
    $pdo = db();
    $cx = shop_cancel_sql();

    $cur = shop_sales_between($from, $next);
    $prev = shop_sales_between($pFrom, $from);
    $profit = shop_profit($from, $to);
    $pProfit = shop_profit($pFrom, $pTo);

    $st = $pdo->prepare("SELECT channel, SUM(amount) AS sales, COUNT(DISTINCT order_id) AS orders
                           FROM shop_orders WHERE ordered_at >= ? AND ordered_at < ? AND NOT $cx GROUP BY channel");
    $st->execute([$from, $next]);
    $chs = [];
    foreach ($st->fetchAll() as $r) { $chs[$r['channel']] = ['sales' => (float)$r['sales'], 'orders' => (int)$r['orders']]; }
    $st->execute([$pFrom, $from]);
    $pch = [];
    foreach ($st->fetchAll() as $r) { $pch[$r['channel']] = (float)$r['sales']; }
    $channels = [];
    foreach (SHOP_CHANNELS as $k => $label) {
        $s = $chs[$k]['sales'] ?? 0.0;
        $ad = (float)($profit['ad'][$k] ?? 0);
        $channels[] = ['channel' => $k, 'label' => $label, 'sales' => $s, 'orders' => $chs[$k]['orders'] ?? 0,
                       'share' => $cur['sales'] > 0 ? $s / $cur['sales'] * 100 : 0, 'ad' => $ad,
                       'roas' => $ad > 0 ? (int)round($s / $ad * 100) : null,
                       'growth' => ($pch[$k] ?? 0) > 0 ? ($s / $pch[$k] - 1) * 100 : null];
    }

    $st = $pdo->prepare("SELECT DATE(ordered_at) AS d, SUM(amount) AS sales FROM shop_orders
                          WHERE ordered_at >= ? AND ordered_at < ? AND NOT $cx GROUP BY DATE(ordered_at)");
    $st->execute([$from, $next]);
    $daily = [];
    for ($d = $from; $d <= $to; $d = date('Y-m-d', strtotime($d . ' +1 day'))) { $daily[$d] = 0.0; }
    foreach ($st->fetchAll() as $r) { $daily[$r['d']] = (float)$r['sales']; }

    $ops = ['inq' => 0, 'inq_done' => 0, 'ship_ok' => 0, 'ship_fail' => 0];
    try {
        $st = $pdo->prepare('SELECT COUNT(*) AS c, COALESCE(SUM(answered), 0) AS a FROM shop_inquiries WHERE asked_at >= ? AND asked_at < ?');
        $st->execute([$from, $next]);
        $r = $st->fetch();
        $ops['inq'] = (int)$r['c'];
        $ops['inq_done'] = (int)$r['a'];
        $st = $pdo->prepare('SELECT COALESCE(SUM(ok), 0) AS ok, COALESCE(SUM(1 - ok), 0) AS bad FROM shop_dispatch_log WHERE sent_at >= ? AND sent_at < ?');
        $st->execute([$from, $next]);
        $r = $st->fetch();
        $ops['ship_ok'] = (int)$r['ok'];
        $ops['ship_fail'] = (int)$r['bad'];
    } catch (PDOException $e) {
        // 표가 아직 없으면 0
    }
    $stock = shop_stock_rows();
    $ops['soldout'] = count(array_filter($stock, fn($r) => $r['stock_now'] !== null && $r['stock_now'] <= 0 && !(int)$r['hidden']));
    $ops['reorder'] = count(array_filter(shop_reorder_rows($stock), fn($r) => $r['order_now'] && $r['qty'] > 0 && !(int)$r['hidden']));

    $pct = fn(float $a, float $b) => $b > 0 ? ($a / $b - 1) * 100 : null;
    $t = $profit['total'];
    $tips = [];
    $best = $channels;
    usort($best, fn($a, $b) => $b['sales'] <=> $a['sales']);
    if ($best && $best[0]['sales'] > 0) {
        $tips[] = sprintf('매출 1위 채널은 %s (%d%%)입니다.', $best[0]['label'], (int)round($best[0]['share']));
    }
    if ($profit['rows']) {
        $top = $profit['rows'][0];
        $tips[] = sprintf('가장 많이 판 상품은 %s — %s원 · %s개.', mb_strimwidth($top['product'], 0, 40, '…'), money($top['sales']), money($top['qty']));
        $loss = array_values(array_filter($profit['rows'], fn($r) => !$r['no_cost'] && $r['profit'] < 0));
        if ($loss) {
            $tips[] = '손해 보고 판 상품 ' . count($loss) . '개: ' . implode(', ', array_map(fn($r) => mb_strimwidth($r['product'], 0, 24, '…'), array_slice($loss, 0, 3)))
                    . ' — 가격 · 광고를 확인하세요.';
        }
    }
    $target = (int)shop_setting('ad_target_roas', '400');
    foreach ($channels as $c) {
        if ($c['roas'] !== null && $c['roas'] < $target) {
            $tips[] = "{$c['label']} 광고 ROAS {$c['roas']}% — 목표 {$target}% 아래입니다. 제외 키워드를 정리하세요.";
        }
    }
    if ($ops['inq'] > $ops['inq_done']) { $tips[] = '이달 문의 중 ' . ($ops['inq'] - $ops['inq_done']) . '건이 아직 답변 전입니다.'; }
    if ($ops['reorder']) { $tips[] = "지금 발주할 상품 {$ops['reorder']}개가 있습니다 (상품 · 재고 > 발주 추천)."; }
    if ($profit['missing_cost']) { $tips[] = "원가를 안 넣은 상품 {$profit['missing_cost']}개는 원가 0원으로 계산했습니다."; }

    return [
        'ym' => $ym, 'from' => $from, 'to' => $to, 'prev_ym' => substr($pFrom, 0, 7),
        'sales' => $cur['sales'], 'sales_prev' => $prev['sales'], 'orders' => $cur['orders'], 'aov' => $cur['orders'] ? $cur['sales'] / $cur['orders'] : 0,
        'sales_g' => $pct($cur['sales'], $prev['sales']), 'orders_g' => $pct((float)$cur['orders'], (float)$prev['orders']),
        'profit' => $t['profit'], 'margin' => $t['margin'], 'profit_prev' => $pProfit['total']['profit'],
        'ad' => $t['ad'], 'roas' => $t['ad'] > 0 ? (int)round($t['sales'] / $t['ad'] * 100) : null,
        'cost' => $t['cost'], 'fee' => $t['fee'], 'ship' => $t['ship'],
        'channels' => $channels, 'daily' => $daily, 'products' => array_slice($profit['rows'], 0, 10), 'ops' => $ops, 'tips' => $tips,
        'missing_cost' => $profit['missing_cost'],
    ];
}

/** A4 한 장 PDF */
function shop_report_pdf(array $r): string
{
    $p = new PdfLite();
    $M = 36.0;
    $W = PdfLite::W - 2 * $M;
    $ink = [0.1, 0.15, 0.2];
    $sub = [0.42, 0.47, 0.53];
    $pri = [0.12, 0.43, 0.55];
    $soft = [0.94, 0.96, 0.97];
    $up = [0.1, 0.55, 0.3];
    $down = [0.8, 0.2, 0.2];
    $won = fn(float $v) => money(round($v)) . '원';
    $g = fn(?float $v) => $v === null ? '' : ($v >= 0 ? '+' : '') . number_format($v, 1) . '%';
    [$y, $m] = array_map('intval', explode('-', $r['ym']));

    // 머리
    $p->rect(0, 0, PdfLite::W, 70, $pri);
    $p->text($M, 20, 20, '쇼핑몰 월간 보고서', true, [1, 1, 1]);
    $p->text($M, 47, 10.5, "{$y}년 {$m}월 · " . substr($r['from'], 5) . ' ~ ' . substr($r['to'], 5) . ' · 쿠팡 · 스마트스토어 · 카페24', false, [0.88, 0.94, 0.97]);
    $p->text($M + $W, 24, 9, 'GOODPOST ERP', true, [1, 1, 1], 'R');
    $p->text($M + $W, 40, 8.5, '만든 날 ' . date('Y-m-d H:i'), false, [0.88, 0.94, 0.97], 'R');

    // 핵심 숫자 4칸
    $y0 = 86.0;
    $bw = ($W - 3 * 8) / 4;
    $kpi = [
        ['매출', $won($r['sales']), '전월 ' . $won($r['sales_prev']), $r['sales_g']],
        ['주문', money($r['orders']) . '건', '객단가 ' . $won($r['aov']), $r['orders_g']],
        ['순이익', $won($r['profit']), '이익률 ' . number_format((float)$r['margin'], 1) . '%', $r['profit_prev'] > 0 ? ($r['profit'] / $r['profit_prev'] - 1) * 100 : null],
        ['광고비', $won($r['ad']), 'ROAS ' . ($r['roas'] !== null ? $r['roas'] . '%' : '-'), null],
    ];
    foreach ($kpi as $i => [$lab, $val, $note, $gr]) {
        $x = $M + $i * ($bw + 8);
        $p->rect($x, $y0, $bw, 62, $soft);
        $p->text($x + 10, $y0 + 9, 8.5, $lab, false, $sub);
        $p->text($x + 10, $y0 + 23, 15, $p->fit($val, 15, $bw - 20, true), true, $ink);
        $p->text($x + 10, $y0 + 45, 8, $note, false, $sub);
        if ($gr !== null) { $p->text($x + $bw - 10, $y0 + 9, 8, $g($gr), true, $gr >= 0 ? $up : $down, 'R'); }
    }

    // 날짜별 매출 막대
    $y1 = $y0 + 80;
    $p->text($M, $y1, 10.5, '날짜별 매출', true, $ink);
    $ch = 92.0;
    $top = $y1 + 20;
    $max = max(1.0, max($r['daily']));
    $n = count($r['daily']);
    $step = $W / $n;
    $p->line($M, $top + $ch, $M + $W, $top + $ch, [0.8, 0.83, 0.86]);
    $p->text($M + $W, $top - 12, 7.5, '최고 ' . $won($max), false, $sub, 'R');
    $i = 0;
    foreach ($r['daily'] as $d => $v) {
        $h = $v / $max * $ch;
        $x = $M + $i * $step;
        if ($h > 0) { $p->rect($x + $step * 0.18, $top + $ch - $h, $step * 0.64, $h, date('N', strtotime($d)) >= 6 ? [0.55, 0.73, 0.8] : $pri); }
        if ($i % 5 === 0 || $i === $n - 1) { $p->text($x + $step / 2, $top + $ch + 3, 7, (string)(int)substr($d, 8), false, $sub, 'C'); }
        $i++;
    }

    // 채널별 (왼쪽) · 운영 (오른쪽)
    $y2 = $top + $ch + 22;
    $lw = $W * 0.62;
    $p->text($M, $y2, 10.5, '채널별', true, $ink);
    $cols = [[$M, '채널', 'L'], [$M + 140, '매출', 'R'], [$M + 182, '전월비', 'R'], [$M + 212, '비중', 'R'], [$M + 245, '주문', 'R'], [$M + 295, '광고비', 'R'], [$M + $lw, 'ROAS', 'R']];
    $ty = $y2 + 18;
    $p->rect($M, $ty - 3, $lw, 16, $soft);
    foreach ($cols as [$x, $h, $a]) { $p->text($a === 'R' ? $x - 4 : $x + 4, $ty, 8, $h, true, $sub, $a); }
    $ty += 17;
    foreach ($r['channels'] as $c) {
        $vals = [$c['label'], $won($c['sales']), $c['growth'] !== null ? $g($c['growth']) : '-', (int)round($c['share']) . '%', money($c['orders']),
                 $c['ad'] > 0 ? $won($c['ad']) : '-', $c['roas'] !== null ? $c['roas'] . '%' : '-'];
        foreach ($cols as $k => [$x, , $a]) {
            $col = $k === 2 && $c['growth'] !== null ? ($c['growth'] >= 0 ? $up : $down) : $ink;
            $p->text($a === 'R' ? $x - 4 : $x + 4, $ty, $k === 2 ? 7.5 : 8.5, $vals[$k], $k === 0, $col, $a);
        }
        $p->line($M, $ty + 13, $M + $lw, $ty + 13);
        $ty += 17;
    }
    // 이익 구성
    $p->text($M + 4, $ty + 2, 7.5, '매출 ' . $won($r['sales']) . ' − 원가 ' . $won($r['cost']) . ' − 수수료 ' . $won($r['fee'])
             . ' − 배송비 ' . $won($r['ship']) . ' − 광고비 ' . $won($r['ad']) . ' = 순이익 ' . $won($r['profit']), false, $sub);

    $rx = $M + $lw + 16;
    $rw = $W - $lw - 16;
    $p->text($rx, $y2, 10.5, '운영', true, $ink);
    $o = $r['ops'];
    $opsRows = [
        ['상품 문의', $o['inq'] . '건 (답변 ' . $o['inq_done'] . ')'],
        ['송장 등록', $o['ship_ok'] . '건' . ($o['ship_fail'] ? ' (실패 ' . $o['ship_fail'] . ')' : '')],
        ['지금 품절', $o['soldout'] . '개'],
        ['지금 발주 필요', $o['reorder'] . '개'],
    ];
    $oy = $y2 + 18;
    $p->rect($rx, $oy - 3, $rw, 17 * count($opsRows) + 4, $soft);
    foreach ($opsRows as [$k, $v]) {
        $p->text($rx + 8, $oy + 2, 8.5, $k, false, $sub);
        $p->text($rx + $rw - 8, $oy + 2, 8.5, $v, true, $ink, 'R');
        $oy += 17;
    }

    // 상품 TOP 10
    $y3 = max($ty, $oy) + 26;
    $p->text($M, $y3, 10.5, '상품 TOP 10 (매출순)', true, $ink);
    $pc = [[$M, '상품', 'L'], [$M + $W - 250, '수량', 'R'], [$M + $W - 170, '매출', 'R'], [$M + $W - 85, '순이익', 'R'], [$M + $W, '이익률', 'R']];
    $ty = $y3 + 18;
    $p->rect($M, $ty - 3, $W, 16, $soft);
    foreach ($pc as [$x, $h, $a]) { $p->text($a === 'R' ? $x - 4 : $x + 4, $ty, 8, $h, true, $sub, $a); }
    $ty += 17;
    if (!$r['products']) { $p->text($M + 4, $ty, 8.5, '이 달 주문이 없습니다.', false, $sub); $ty += 17; }
    foreach ($r['products'] as $i => $pr) {
        $name = ($i + 1) . '. ' . $pr['product'];
        $vals = [$p->fit($name, 8.5, $W - 270), money($pr['qty']), $won($pr['sales']),
                 $pr['no_cost'] ? '원가 없음' : $won($pr['profit']), $pr['no_cost'] ? '-' : number_format((float)$pr['margin'], 1) . '%'];
        foreach ($pc as $k => [$x, , $a]) {
            $col = $k === 3 && !$pr['no_cost'] && $pr['profit'] < 0 ? $down : ($k === 3 && $pr['no_cost'] ? $sub : $ink);
            $p->text($a === 'R' ? $x - 4 : $x + 4, $ty, 8.5, $vals[$k], false, $col, $a);
        }
        $p->line($M, $ty + 13, $M + $W, $ty + 13);
        $ty += 16.5;
    }

    // 요약 · 할 일
    $y4 = $ty + 16;
    $p->text($M, $y4, 10.5, '요약 · 다음 달에 할 일', true, $ink);
    $ly = $y4 + 18;
    foreach (array_slice($r['tips'], 0, 7) as $t) {
        if ($ly > PdfLite::H - 40) { break; }
        $p->text($M + 4, $ly, 8.5, '•', true, $pri);
        $p->text($M + 14, $ly, 8.5, $p->fit($t, 8.5, $W - 18), false, $ink);
        $ly += 14;
    }
    if (!$r['tips']) { $p->text($M + 4, $ly, 8.5, '특이사항 없음', false, $sub); }

    $p->line($M, PdfLite::H - 30, $M + $W, PdfLite::H - 30);
    $p->text($M, PdfLite::H - 24, 7, '취소 · 반품 제외. 순이익 = 매출 − 원가 − 판매 수수료 − 배송비 − 광고비 (광고비는 채널 매출 비중으로 나눔). 쇼핑몰관리 > 월간 보고서', false, $sub);
    return $p->output();
}

/** 보고서 PDF 를 메일로. [성공?, 메시지] */
function shop_report_send(string $ym, string $to = ''): array
{
    require_once APP_DIR . '/notify.php';
    $to = $to !== '' ? $to : (shop_setting('shop_report_emails', '') ?: (notify_cfg()['notify_staff_emails'] ?? ''));
    if (trim($to) === '') { return [false, '받는 메일이 없습니다 (환경설정 → 쇼핑몰 → 월간 보고서 받는 메일)']; }
    $r = shop_report_data($ym);
    [$y, $m] = array_map('intval', explode('-', $ym));
    $g = fn(?float $v) => $v === null ? '-' : ($v >= 0 ? '+' : '') . number_format($v, 1) . '%';
    $text = "[GOODPOST 쇼핑몰] {$y}년 {$m}월 보고서\n\n"
          . '매출 ' . money($r['sales']) . '원 (전월 대비 ' . $g($r['sales_g']) . ")\n"
          . '주문 ' . money($r['orders']) . "건\n"
          . '순이익 ' . money(round($r['profit'])) . '원 (이익률 ' . number_format((float)$r['margin'], 1) . "%)\n"
          . '광고비 ' . money(round($r['ad'])) . '원' . ($r['roas'] !== null ? " (ROAS {$r['roas']}%)" : '') . "\n\n"
          . ($r['tips'] ? '- ' . implode("\n- ", $r['tips']) . "\n\n" : '')
          . '자세한 내용은 첨부한 PDF 를 보세요.';
    [$ok, $msg] = notify_mail($to, "[GOODPOST 쇼핑몰] {$y}년 {$m}월 보고서", $text, '',
                              [["shop-report-{$ym}.pdf", 'application/pdf', shop_report_pdf($r)]]);
    shop_state_set('last_report_result', date('Y-m-d H:i') . " {$ym} · " . ($ok ? $msg : '실패 ' . $msg));
    return [$ok, $msg];
}

/** 자동: 매월 1일 오전 9시 뒤 지난달 보고서 (한 번만) */
function shop_report_tick(): ?string
{
    shop_report_ensure_schema();
    $prev = date('Y-m', strtotime(date('Y-m-01') . ' -1 month'));
    if (shop_setting('shop_report_on', '예') !== '예' || (int)date('G') < 9 || shop_state_get('report_month') === $prev) { return null; }
    shop_state_set('report_month', $prev);   // 실패해도 다시 보내지 않음 (결과는 화면에)
    [$ok, $msg] = shop_report_send($prev);
    return ($ok ? 'OK ' : 'fail ') . $msg;
}
