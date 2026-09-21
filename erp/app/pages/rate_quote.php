<?php
// 웹에서 직접 열면 실행되지 않게 막습니다 (nginx 면 .htaccess 가 무시됩니다)
if (!defined('APP_DIR')) { http_response_code(403); exit('Forbidden'); }

require_once APP_DIR . '/ratecalc.php';

/**
 * 단가 조회 (JSON) — 매출전표 화면이 뒤에서 부릅니다.
 * 운송사 · 도착지 · 중량 · 거래처를 주면 원가격(기본단가) 과 할인 · 유류할증을 계산해 돌려줍니다.
 * 저장은 하지 않습니다.
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$carrierId = (int)query('carrier_id', '0');
$companyId = (int)query('company_id', '0') ?: null;
$trade     = query('trade_type', 'EXPORT');
$day       = query('on_date', date('Y-m-d'));
$country   = query('country', '');
$zone      = (int)query('zone_no', '0');

$aw = (float)str_replace(',', '', query('actual_weight', '0'));
$vw = (float)str_replace(',', '', query('volume_weight', '0'));
$cw = max($aw, $vw);

// 도착지 이름만 온 경우 국가코드를 찾아 줍니다 (전표 화면은 도시 이름을 씁니다)
$destCity = query('dest_city', '');
if ($country === '' && $destCity !== '') {
    try {
        $st = db()->prepare('SELECT country_code FROM destinations
                              WHERE name = ? OR name_ko = ? ORDER BY id LIMIT 1');
        $st->execute([$destCity, $destCity]);
        $country = (string)($st->fetchColumn() ?: '');
    } catch (PDOException $e) {
        $country = '';
    }
}

// 원가격을 직접 넣었으면 (EMS 처럼 가격표가 없는 경우) 가격표를 찾지 않습니다
$manual = (float)str_replace(',', '', query('base', '0'));
$R = rate_quote($carrierId, $companyId, $trade, $day, $cw, $country, $zone,
                $manual > 0 ? $manual : null);
$R['country'] = strtoupper($country);
echo json_encode($R, JSON_UNESCAPED_UNICODE);
