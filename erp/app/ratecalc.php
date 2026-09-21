<?php
declare(strict_types=1);

// 웹에서 직접 열면 실행되지 않게 막습니다 (nginx 면 .htaccess 가 무시됩니다)
if (!defined('APP_DIR')) { http_response_code(403); exit('Forbidden'); }

/**
 * 단가 계산 — 한 군데에서만 계산합니다.
 *
 *   기본가격(원가격) → 할인 → 할인 후 운임 → 유류할증 → 공급가격
 *
 * 단가계산기(rate_calculator)와 매출전표(shipment_form)가 같은 값을 쓰도록
 * 여기에 모아 두었습니다. 계산이 안 되면 왜 안 되는지 'why' 에 적어 돌려줍니다.
 */

/**
 * @param  float  $cw      청구중량 (실중량과 부피중량 중 큰 값)
 * @param  string $country 도착 국가코드 (Zone 을 찾을 때 씀). 비우면 $zoneNo 로
 * @param  ?float $manualBase 원가격을 직접 넣은 경우 (EMS 처럼 가격표가 아직 없는 운송사).
 *                            이 값이 있으면 가격표를 찾지 않고 할인 · 유류할증만 계산합니다.
 * @return array  ok · why[] · zone · base · disc · disc_amt · after · fuel · fuel_amt · supply …
 */
function rate_quote(int $carrierId, ?int $companyId, string $tradeType, string $day,
                    float $cw, string $country = '', int $zoneNo = 0, ?float $manualBase = null): array
{
    $why = [];
    $R = ['ok' => false, 'why' => [], 'cw' => $cw,
          'zone' => 0, 'zone_src' => '', 'base' => null, 'table' => null,
          'disc' => 0.0, 'disc_amt' => 0.0, 'disc_src' => '할인율 없음 (0%)', 'after' => null,
          'fuel' => 0.0, 'fuel_amt' => 0.0, 'fuel_src' => '유류할증 없음 (0%)',
          'fuel_basis' => 'AFTER_DISCOUNT', 'fuel_applied' => true, 'supply' => null];

    $R['base_src'] = '가격표';
    if ($carrierId <= 0) { $R['why'] = ['운송사를 고르세요.']; return $R; }
    if ($cw <= 0 && $manualBase === null) { $R['why'] = ['중량을 넣으세요.']; return $R; }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) { $day = date('Y-m-d'); }

    // 원가격을 직접 넣었으면 Zone · 가격표를 건너뜁니다 (EMS 처럼 가격표가 아직 없는 경우)
    if ($manualBase !== null && $manualBase > 0) {
        $R['base'] = $manualBase;
        $R['base_src'] = '직접 입력';
        $R['zone_src'] = '가격표를 쓰지 않음';
        return rate_quote_apply($R, $carrierId, $companyId, $tradeType, $day, $manualBase);
    }

    // 1) Zone — 국가코드로 찾거나 직접 받은 번호로
    $zone = $zoneNo;
    $zoneSrc = '직접 입력';
    if ($zone === 0 && $country !== '') {
        $st = db()->prepare('SELECT zone_no, country_name FROM carrier_zones
                              WHERE carrier_id = ? AND country_code = ?');
        $st->execute([$carrierId, strtoupper($country)]);
        $z = $st->fetch();
        if ($z) {
            $zone = (int)$z['zone_no'];
            $zoneSrc = strtoupper($country) . ($z['country_name'] ? ' (' . $z['country_name'] . ')' : '');
        } else {
            $why[] = '이 운송사에 ' . strtoupper($country) . ' 의 Zone 이 등록돼 있지 않습니다.';
        }
    }
    if ($zone === 0 && !$why) {
        $why[] = '도착지 국가코드가 없어 Zone 을 찾지 못했습니다.';
    }
    $R['zone'] = $zone;
    $R['zone_src'] = $zoneSrc;

    // 2) 기본가격(원가격) — 적용중인 가격표에서 Zone × 중량구간
    $base = null; $rateTable = null;
    if ($zone > 0) {
        $st = db()->prepare(
            "SELECT r.base_price, t.id AS tid, t.name, t.effective_from,
                    r.weight_from, r.weight_to
               FROM carrier_rates r
               JOIN carrier_rate_tables t ON t.id = r.rate_table_id
              WHERE t.carrier_id = ? AND t.status = 'ACTIVE'
                AND t.effective_from <= ?
                AND (t.effective_to IS NULL OR t.effective_to >= ?)
                AND r.zone_no = ?
                AND r.weight_from < ? AND r.weight_to >= ?
              ORDER BY t.effective_from DESC, r.weight_to ASC LIMIT 1");
        $st->execute([$carrierId, $day, $day, $zone, $cw, $cw]);
        $row = $st->fetch();
        if ($row) {
            $base = (float)$row['base_price'];
            $rateTable = $row;
        } else {
            $why[] = 'Zone ' . $zone . ' · 청구중량 ' . $cw . 'kg 에 맞는 단가가 없습니다. '
                   . '적용중인 가격표에 그 구간이 있는지 확인하세요.';
        }
    }
    $R['base'] = $base;
    $R['table'] = $rateTable;

    return rate_quote_apply($R, $carrierId, $companyId, $tradeType, $day, $base, $why);
}

/** 원가격이 정해진 뒤 — 할인 → 유류할증 → 공급가격 */
function rate_quote_apply(array $R, int $carrierId, ?int $companyId, string $tradeType,
                          string $day, ?float $base, array $why = []): array
{
    // 3) 할인율 — 거래처별. 없으면 0%
    $disc = 0.0; $fuelApplied = true;
    if ($companyId) {
        $st = db()->prepare(
            "SELECT t.discount_rate, t.fuel_applied, t.effective_from, t.memo
               FROM company_carrier_terms t
              WHERE t.company_id = ? AND t.carrier_id = ?
                AND t.trade_type IN (?, 'ALL')
                AND t.effective_from <= ?
                AND (t.effective_to IS NULL OR t.effective_to >= ?)
              ORDER BY t.trade_type = 'ALL', t.effective_from DESC LIMIT 1");
        $st->execute([$companyId, $carrierId, $tradeType === 'IMPORT' ? 'IMPORT' : 'EXPORT', $day, $day]);
        $d = $st->fetch();
        if ($d) {
            $disc = (float)$d['discount_rate'];
            $fuelApplied = (bool)$d['fuel_applied'];
            $R['disc_src'] = $d['effective_from'] . ' 부터 적용' . ($d['memo'] ? ' · ' . $d['memo'] : '');
        }
    }
    $R['disc'] = $disc;
    $R['fuel_applied'] = $fuelApplied;

    // 4) 유류할증 — 운송사별
    $fuel = 0.0; $basis = 'AFTER_DISCOUNT';
    $st = db()->prepare(
        'SELECT fuel_rate, calc_basis, effective_from FROM fuel_surcharges
          WHERE carrier_id = ? AND effective_from <= ?
            AND (effective_to IS NULL OR effective_to >= ?)
          ORDER BY effective_from DESC LIMIT 1');
    $st->execute([$carrierId, $day, $day]);
    $f = $st->fetch();
    if ($f) {
        $fuel  = (float)$f['fuel_rate'];
        $basis = (string)$f['calc_basis'];
        $R['fuel_src'] = $f['effective_from'] . ' 부터 적용';
    }
    $R['fuel'] = $fuel;
    $R['fuel_basis'] = $basis;

    if ($base !== null) {
        $discAmt  = round($base * $disc / 100);
        $afterDsc = $base - $discAmt;
        $fuelBase = ($basis === 'BASE_PRICE') ? $base : $afterDsc;
        $fuelAmt  = $fuelApplied ? round($fuelBase * $fuel / 100) : 0.0;
        $R['disc_amt'] = $discAmt;
        $R['after']    = $afterDsc;
        $R['fuel_amt'] = $fuelAmt;
        $R['supply']   = $afterDsc + $fuelAmt;
        $R['ok']       = true;
    }
    $R['why'] = $why;
    return $R;
}

/** 부피중량 = 가로 × 세로 × 높이 ÷ 5000 (cm, 항공 표준) */
function rate_volume_weight(float $l, float $w, float $h): float
{
    if ($l <= 0 || $w <= 0 || $h <= 0) { return 0.0; }
    return round($l * $w * $h / 5000, 2);
}
