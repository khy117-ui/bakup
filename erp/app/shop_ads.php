<?php
declare(strict_types=1);

// 웹에서 직접 열면 실행되지 않게 막습니다 (nginx 면 .htaccess 가 무시됩니다)
if (!defined('APP_DIR')) { http_response_code(403); exit('Forbidden'); }

require_once APP_DIR . '/shop.php';

/**
 * 광고 · 키워드 — 쿠팡 · 스마트스토어(네이버 검색광고) 키워드 분석과 추천.
 *   ① 키워드 보고서 CSV 올리기 → 키워드별 ROAS · 클릭률 · 할 일(제외키워드 · 입찰가 조정)
 *      쿠팡: 광고센터 > 보고서 > 키워드 보고서 / 네이버: 검색광고 > 보고서 > 키워드 보고서 (CSV)
 *   ② 키워드 추천 → 네이버 검색광고 키워드도구(/keywordstool)로 연관 키워드 · 월 검색수 · 경쟁도를 받아
 *      '검색은 많고 경쟁은 낮은' 키워드를 점수로 골라 줍니다. 쿠팡은 공개 키워드 API 가 없어 네이버 검색수를 참고로 씁니다.
 * 판단 기준은 commerce-hub 의 ads.js 와 같습니다 (목표 ROAS, 클릭 20회 이상 · 전환 0 = 제외키워드).
 */

const AD_CHANNELS = ['coupang' => '쿠팡', 'naver' => '스마트스토어 (네이버 검색광고)'];

/** 보고서 열 이름 — 쿠팡 · 네이버 보고서 어느 쪽이든 받습니다 */
const AD_ALIASES = [
    'keyword'     => ['키워드', 'keyword'],
    'impressions' => ['노출수', 'impressions'],
    'clicks'      => ['클릭수', 'clicks'],
    'cost'        => ['광고비', '총비용(VAT포함,원)', '총비용(VAT포함)', '총비용', '광고비(원)', 'cost', 'spend'],
    'revenue'     => ['총 전환매출액(14일)', '전환매출액(14일)', '총 전환매출액', '전환매출액(원)', '전환매출액', '전환매출', '구매완료 전환매출액(원)', 'revenue'],
    'orders'      => ['총 주문수(14일)', '주문수(14일)', '전환수', '총 전환수', '구매완료 전환수', 'orders', 'conversions'],
];

function shop_ads_ensure_schema(): void
{
    if (!empty($_SESSION['schema_shop_ads_v1'])) { return; }
    $pdo = db();
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS ad_keyword_stats (
                      id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                      batch_id    BIGINT UNSIGNED NOT NULL COMMENT '같은 파일에서 올라온 줄 묶음',
                      channel     VARCHAR(20)   NOT NULL COMMENT 'coupang / naver',
                      keyword     VARCHAR(200)  NOT NULL,
                      impressions BIGINT        NOT NULL DEFAULT 0,
                      clicks      INT           NOT NULL DEFAULT 0,
                      cost        DECIMAL(14,0) NOT NULL DEFAULT 0,
                      revenue     DECIMAL(14,0) NOT NULL DEFAULT 0,
                      orders      INT           NOT NULL DEFAULT 0,
                      PRIMARY KEY (id),
                      KEY ix_aks_batch (batch_id),
                      KEY ix_aks_kw (channel, keyword)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                      COMMENT='광고 키워드 보고서 (키워드별 합계)'");
        $pdo->exec("CREATE TABLE IF NOT EXISTS ad_keyword_batches (
                      id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                      channel     VARCHAR(20)   NOT NULL,
                      file_name   VARCHAR(200)  NULL,
                      period_from DATE          NULL,
                      period_to   DATE          NULL,
                      rows_cnt    INT           NOT NULL DEFAULT 0,
                      uploaded_by BIGINT UNSIGNED NULL,
                      uploaded_at DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
                      PRIMARY KEY (id),
                      KEY ix_akb_ch (channel, id)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                      COMMENT='광고 키워드 보고서 올린 기록'");
        $pdo->exec("INSERT IGNORE INTO app_settings
                      (setting_key, setting_val, group_ko, label_ko, help_ko, input_type, options_csv, sort_order)
                    VALUES ('naver_ad_api_key', NULL, '연동', '네이버 검색광고 API 액세스라이선스',
                            'searchad.naver.com → 도구 → API 사용 관리 에서 받은 액세스라이선스. 광고 · 키워드 화면의 키워드 추천에 씁니다.',
                            'secret', NULL, 40),
                           ('naver_ad_secret_key', NULL, '연동', '네이버 검색광고 API 비밀키', '같은 화면의 비밀키.', 'secret', NULL, 41),
                           ('naver_ad_customer_id', NULL, '연동', '네이버 검색광고 CUSTOMER_ID', '같은 화면 위쪽의 CUSTOMER_ID (숫자).', 'text', NULL, 42),
                           ('ad_target_roas', '400', '연동', '광고 목표 ROAS (%)',
                            '광고 · 키워드 분석의 기준. 광고비 1원당 매출 4원이면 400.', 'number', NULL, 43)");
        $pdo->exec("INSERT INTO permissions (code, group_ko, name_ko) VALUES
                      ('shop.ad.read',  '쇼핑몰관리', '광고 · 키워드 분석 조회'),
                      ('shop.ad.write', '쇼핑몰관리', '광고 키워드 보고서 올리기')
                    ON DUPLICATE KEY UPDATE name_ko = VALUES(name_ko)");
        $pdo->exec("INSERT IGNORE INTO role_permissions (role_code, permission_id)
                    SELECT r.role_code, p.id
                      FROM permissions p
                      JOIN (SELECT 'SUPER_ADMIN' AS role_code UNION ALL SELECT 'MANAGER') r
                     WHERE p.code IN ('shop.ad.read', 'shop.ad.write')");
        $_SESSION['schema_shop_ads_v1'] = 1;
    } catch (PDOException $e) {
        error_log('광고 키워드 표 준비 실패: ' . $e->getMessage());
    }
}

function shop_ads_cfg(): array
{
    $c = ['naver_ad_api_key' => '', 'naver_ad_secret_key' => '', 'naver_ad_customer_id' => '', 'ad_target_roas' => '400'];
    try {
        $st = db()->query("SELECT setting_key, setting_val FROM app_settings
                            WHERE setting_key IN ('naver_ad_api_key', 'naver_ad_secret_key', 'naver_ad_customer_id', 'ad_target_roas')");
        foreach ($st->fetchAll() as $r) { $c[$r['setting_key']] = trim((string)$r['setting_val']); }
    } catch (PDOException $e) {
        // 표가 없으면 기본값
    }
    return $c;
}

/**
 * 키워드 보고서 CSV → 키워드별 합계. 광고그룹 · 날짜별로 여러 줄이어도 키워드로 묶습니다.
 * 보고서 위쪽에 제목 · 기간 줄이 있으면 '키워드' 열이 나오는 줄부터 읽습니다.
 * @return array<string, array{keyword:string, impressions:int, clicks:int, cost:float, revenue:float, orders:int}>
 */
function shop_ads_parse_csv(string $text): array
{
    $text = preg_replace('/^\xEF\xBB\xBF/', '', $text) ?? $text;
    if (!mb_check_encoding($text, 'UTF-8')) {
        $text = (string)mb_convert_encoding($text, 'UTF-8', 'CP949');   // 엑셀에서 저장한 CSV
    }
    $delim = substr_count(strtok($text, "\n") ?: '', "\t") > substr_count(strtok($text, "\n") ?: '', ',') ? "\t" : ',';
    $fh = fopen('php://temp', 'r+');
    fwrite($fh, $text);
    rewind($fh);
    $idx = null;
    $out = [];
    $n = 0;
    while (($r = fgetcsv($fh, null, $delim, '"', '')) !== false) {
        if (++$n > 200000) { break; }
        if ($idx === null) {
            $found = [];
            foreach ($r as $i => $h) {
                $h = trim((string)$h);
                foreach (AD_ALIASES as $k => $names) {
                    if (!isset($found[$k]) && in_array($h, $names, true)) { $found[$k] = $i; }
                }
            }
            if (isset($found['keyword'], $found['clicks'], $found['cost'])) { $idx = $found; }
            continue;
        }
        $kw = trim((string)($r[$idx['keyword']] ?? ''));
        if ($kw === '' || $kw === '-' || in_array($kw, ['합계', '총계', 'Total'], true)) { continue; }
        $g = fn(string $k) => isset($idx[$k]) ? num(str_replace(['%', '원'], '', (string)($r[$idx[$k]] ?? '0'))) : 0.0;
        $key = mb_strtolower($kw);
        $a = $out[$key] ?? ['keyword' => $kw, 'impressions' => 0, 'clicks' => 0, 'cost' => 0.0, 'revenue' => 0.0, 'orders' => 0];
        $a['impressions'] += (int)$g('impressions');
        $a['clicks']      += (int)$g('clicks');
        $a['cost']        += $g('cost');
        $a['revenue']     += $g('revenue');
        $a['orders']      += (int)$g('orders');
        $out[$key] = $a;
    }
    fclose($fh);
    if ($idx === null) {
        throw new RuntimeException("CSV 에서 '키워드 · 클릭수 · 광고비(총비용)' 열을 찾지 못했습니다. 광고센터의 키워드 보고서를 CSV 로 받아 올리세요.");
    }
    if (!isset($idx['revenue'])) {
        throw new RuntimeException("CSV 에 '전환매출액' 열이 없습니다. 보고서를 받을 때 전환매출 항목을 넣어 주세요.");
    }
    return $out;
}

/**
 * 키워드별 할 일 — commerce-hub ads.js 와 같은 기준.
 * @return array{0:string,1:string} [할 일, 배지 색]
 */
function shop_ads_action(array $a, float $targetRoas, int $minClicks = 20): array
{
    $roas = $a['cost'] > 0 ? $a['revenue'] / $a['cost'] * 100 : 0;
    if ($a['clicks'] >= $minClicks && $a['revenue'] <= 0) { return ['제외키워드 등록', 'b-err']; }
    if ($a['clicks'] < $minClicks) { return ['데이터 부족 - 유지', 'b-info']; }
    if ($roas >= $targetRoas * 1.5) { return ['입찰가 +10~20%', 'b-ok']; }
    if ($roas >= $targetRoas) { return ['유지', 'b-ok']; }
    if ($roas >= $targetRoas * 0.5) { return ['입찰가 -10~20%', 'b-warn']; }
    return ['입찰가 대폭 인하 또는 OFF', 'b-err'];
}

/** 네이버 검색광고 API 호출 (X-Signature = base64(HMAC-SHA256(secret, "timestamp.METHOD.uri"))) */
function naver_ad_get(string $uri, array $params): array
{
    $c = shop_ads_cfg();
    if ($c['naver_ad_api_key'] === '' || $c['naver_ad_secret_key'] === '' || $c['naver_ad_customer_id'] === '') {
        throw new RuntimeException('환경설정 → 연동 에 네이버 검색광고 API 액세스라이선스 · 비밀키 · CUSTOMER_ID 를 먼저 넣으세요.');
    }
    if (!function_exists('curl_init')) {
        throw new RuntimeException('이 서버에 PHP curl 이 없어 네이버 검색광고 API 를 부를 수 없습니다.');
    }
    $ts = (string)round(microtime(true) * 1000);
    $sig = base64_encode(hash_hmac('sha256', "{$ts}.GET.{$uri}", $c['naver_ad_secret_key'], true));
    $host = getenv('NAVER_AD_HOST') ?: 'https://api.searchad.naver.com';   // 시험 서버로 바꿀 때만
    $ch = curl_init($host . $uri . '?' . http_build_query($params));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_HTTPHEADER     => ['X-Timestamp: ' . $ts, 'X-API-KEY: ' . $c['naver_ad_api_key'],
                                   'X-Customer: ' . $c['naver_ad_customer_id'], 'X-Signature: ' . $sig],
    ]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $cerr = curl_error($ch);
    curl_close($ch);
    if ($body === false) { throw new RuntimeException('네이버 검색광고 API 에 연결하지 못했습니다: ' . $cerr); }
    $data = json_decode((string)$body, true);
    if ($code !== 200 || !is_array($data)) {
        $msg = is_array($data) ? (string)($data['title'] ?? $data['message'] ?? '') : '';
        throw new RuntimeException('네이버 검색광고 API 오류 (' . $code . ')' . ($msg !== '' ? ': ' . mb_substr($msg, 0, 200) : ''));
    }
    return $data;
}

/** 네이버 검색량 숫자 ('< 10' 처럼 오는 값 포함) */
function naver_qc($v): int
{
    return is_numeric($v) ? (int)$v : 5;
}

/**
 * 연관 키워드 추천 — 네이버 키워드도구. 씨앗 키워드는 최대 5개 (API 제한).
 * 점수 = 월 검색수 × 경쟁도 가중치(낮음 1 · 중간 0.6 · 높음 0.3) — 찾는 사람은 많고 광고는 덜 붙는 키워드가 위로.
 */
function shop_ads_recommend(array $seeds, array $known = []): array
{
    $seeds = array_slice(array_values(array_unique(array_filter(array_map(
        fn($s) => str_replace(' ', '', trim((string)$s)), $seeds), 'strlen'))), 0, 5);
    if (!$seeds) { throw new RuntimeException('추천받을 씨앗 키워드를 1개 이상 넣으세요.'); }
    $data = naver_ad_get('/keywordstool', ['hintKeywords' => implode(',', $seeds), 'showDetail' => '1']);
    $weight = ['낮음' => 1.0, '중간' => 0.6, '높음' => 0.3];
    $out = [];
    foreach ($data['keywordList'] ?? [] as $k) {
        $pc = naver_qc($k['monthlyPcQcCnt'] ?? 0);
        $mo = naver_qc($k['monthlyMobileQcCnt'] ?? 0);
        $comp = (string)($k['compIdx'] ?? '');
        $kw = (string)($k['relKeyword'] ?? '');
        if ($kw === '') { continue; }
        $out[] = [
            'keyword' => $kw,
            'pc' => $pc, 'mobile' => $mo, 'total' => $pc + $mo,
            'clicks' => (float)($k['monthlyAvePcClkCnt'] ?? 0) + (float)($k['monthlyAveMobileClkCnt'] ?? 0),
            'ctr' => (float)($k['monthlyAveMobileCtr'] ?? 0),
            'depth' => (int)($k['plAvgDepth'] ?? 0),
            'comp' => $comp,
            'score' => (int)round(($pc + $mo) * ($weight[$comp] ?? 0.5)),
            'seed' => in_array($kw, $seeds, true),
            'known' => $known[mb_strtolower($kw)] ?? null,
        ];
    }
    usort($out, fn($a, $b) => $b['score'] <=> $a['score']);
    return $out;
}
