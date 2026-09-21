<?php
declare(strict_types=1);

// 웹에서 직접 열면 실행되지 않게 막습니다 (nginx 면 .htaccess 가 무시됩니다)
if (!defined('APP_DIR')) { http_response_code(403); exit('Forbidden'); }

/**
 * 홈페이지 요금표 ← ERP 특송 기본가격표.
 *   홈페이지 요금표 칸(시트 이름)마다 ERP 가격표를 하나씩 연결해 두면, 홈페이지가 erp/rates.php 에서 표를 받아 그립니다.
 *   연결한 가격표가 새 버전으로 바뀌면(같은 운송사 · 서비스의 새 가격표 적용) 새 버전을 자동으로 따라갑니다.
 *   연결 안 한 칸(중량물 · 지역표 · EMS 발송조건 등)은 예전처럼 assets/data/rates.xlsx 에서 읽습니다.
 */

/** 홈페이지 칸 이름 => 기본 머리글(Zone 1, 2, … 순서) */
const WEB_RATE_SHEETS = [
    'EMS_비서류'      => '특정지역,1지역,2지역,3지역,4지역,특1지역,특2지역,특3지역,특4지역,특5지역,특6지역',
    'EMS_서류'        => '특정지역,1지역,2지역,3지역,4지역,특1지역,특2지역,특3지역,특4지역,특5지역,특6지역',
    'DHL_수출_비서류' => '1지역,2지역,3지역,4지역,5지역,6지역,7지역,8지역',
    'DHL_수출_서류'   => '1지역,2지역,3지역,4지역,5지역,6지역,7지역,8지역',
    'DHL_수입_비서류' => '1지역,2지역,3지역,4지역,5지역,6지역,7지역,8지역',
    'DHL_수입_서류'   => '1지역,2지역,3지역,4지역,5지역,6지역,7지역,8지역',
];

function webrates_ensure_table(PDO $pdo): void
{
    static $done = false;
    if ($done) { return; }
    $pdo->exec("CREATE TABLE IF NOT EXISTS web_rate_sheets (
                  sheet_name    VARCHAR(40) NOT NULL PRIMARY KEY COMMENT '홈페이지 요금표 칸 (rates.xlsx 시트 이름과 같음)',
                  rate_table_id BIGINT UNSIGNED NULL COMMENT 'ERP 특송 기본가격표 — 새 버전이 적용되면 그것을 따라감',
                  zone_labels   VARCHAR(300) NULL COMMENT 'Zone 1, 2, … 순서의 머리글 (쉼표)',
                  updated_by    BIGINT UNSIGNED NULL,
                  updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='홈페이지 요금표 ↔ ERP 가격표 연결'");
    $done = true;
}

/** 연결한 가격표의 '지금 적용 중인' 버전 — 같은 운송사 · 서비스에서 오늘 적용 중인 최신 ACTIVE, 없으면 연결한 그대로 */
function webrates_current_table(PDO $pdo, int $tid): ?array
{
    $st = $pdo->prepare('SELECT * FROM carrier_rate_tables WHERE id = ?');
    $st->execute([$tid]);
    $t = $st->fetch();
    if (!$t) { return null; }
    $st = $pdo->prepare("SELECT * FROM carrier_rate_tables
                          WHERE carrier_id = ? AND (service_id <=> ?) AND status = 'ACTIVE'
                            AND effective_from <= CURDATE() AND (effective_to IS NULL OR effective_to >= CURDATE())
                          ORDER BY effective_from DESC, id DESC LIMIT 1");
    $st->execute([$t['carrier_id'], $t['service_id']]);
    return $st->fetch() ?: $t;
}

/** 가격표 하나를 홈페이지 표로 — [머리행, 중량별 행 …]. 첫 칸은 중량(kg) */
function webrates_rows(PDO $pdo, int $tid, string $labels): array
{
    $st = $pdo->prepare('SELECT zone_no, weight_to, base_price FROM carrier_rates WHERE rate_table_id = ?
                          ORDER BY weight_to, zone_no');
    $st->execute([$tid]);
    $grid = [];
    $zones = [];
    foreach ($st->fetchAll() as $r) {
        $w = rtrim(rtrim(number_format((float)$r['weight_to'], 2, '.', ''), '0'), '.');
        $grid[$w][(int)$r['zone_no']] = (int)round((float)$r['base_price']);
        $zones[(int)$r['zone_no']] = true;
    }
    ksort($zones);
    $lab = array_map('trim', explode(',', $labels));
    $head = ['중량(kg)'];
    foreach (array_keys($zones) as $i => $z) { $head[] = ($lab[$i] ?? '') !== '' ? $lab[$i] : $z . '지역'; }
    $rows = [$head];
    uksort($grid, fn($a, $b) => (float)$a <=> (float)$b);
    foreach ($grid as $w => $byZone) {
        $row = [(float)$w];
        foreach (array_keys($zones) as $z) { $row[] = $byZone[$z] ?? ''; }
        $rows[] = $row;
    }
    return $rows;
}

/** 홈페이지에 줄 전체 — ['sheets' => [칸 => 표], 'tables' => [칸 => 가격표 이름 · 적용일]] */
function webrates_all(PDO $pdo): array
{
    webrates_ensure_table($pdo);
    $out = ['sheets' => [], 'tables' => []];
    foreach ($pdo->query('SELECT * FROM web_rate_sheets WHERE rate_table_id IS NOT NULL')->fetchAll() as $m) {
        if (!array_key_exists($m['sheet_name'], WEB_RATE_SHEETS)) { continue; }
        $t = webrates_current_table($pdo, (int)$m['rate_table_id']);
        if (!$t) { continue; }
        $rows = webrates_rows($pdo, (int)$t['id'], (string)($m['zone_labels'] ?: WEB_RATE_SHEETS[$m['sheet_name']]));
        if (count($rows) < 2) { continue; }   // 단가가 없는 가격표는 건너뜀 (엑셀 값이 대신 보임)
        $out['sheets'][$m['sheet_name']] = $rows;
        $out['tables'][$m['sheet_name']] = ['name' => $t['name'], 'from' => $t['effective_from']];
    }
    return $out;
}
