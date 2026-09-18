<?php
declare(strict_types=1);

// 웹에서 직접 열면 실행되지 않게 막습니다 (nginx 면 .htaccess 가 무시됩니다)
if (!defined('APP_DIR')) { http_response_code(403); exit('Forbidden'); }

/**
 * CODE128 (코드셋 B) 바코드 → SVG.
 *
 * 외부 라이브러리 없이 쓰기 위해 직접 구현했습니다 (composer 를 쓰지 않는 구성).
 * 패턴표는 생성 시 모듈 합계를 검증했습니다 — 0~102 와 시작문자는 11모듈,
 * 정지문자는 13모듈입니다.
 *
 * ★ 실제 스캐너로 한 번 읽어보고 쓰세요. 표에 전치 오타가 있으면
 *   합계 검증만으로는 걸러지지 않습니다.
 */

const CODE128_PATTERNS = [
    '212222', '222122', '222221', '121223', '121322', '131222',
    '122213', '122312', '132212', '221213', '221312', '231212',
    '112232', '122132', '122231', '113222', '123122', '123221',
    '223211', '221132', '221231', '213212', '223112', '312131',
    '311222', '321122', '321221', '312212', '322112', '322211',
    '212123', '212321', '232121', '111323', '131123', '131321',
    '112313', '132113', '132311', '211313', '231113', '231311',
    '112133', '112331', '132131', '113123', '113321', '133121',
    '313121', '211331', '231131', '213113', '213311', '213131',
    '311123', '311321', '331121', '312113', '312311', '332111',
    '314111', '221411', '431111', '111224', '111422', '121124',
    '121421', '141122', '141221', '112214', '112412', '122114',
    '122411', '142112', '142211', '241211', '221114', '413111',
    '241112', '134111', '111242', '121142', '121241', '114212',
    '124112', '124211', '411212', '421112', '421211', '212141',
    '214121', '412121', '111143', '111341', '131141', '114113',
    '114311', '411113', '411311', '113141', '114131', '311141',
    '411131', '211412', '211214', '211232', '2331112',
];

/**
 * 코드셋 B 로 인코딩한 모듈 폭 배열을 돌려줍니다.
 * 첫 값은 검은 막대 폭, 그다음은 흰 칸 폭, 번갈아 이어집니다.
 *
 * @return int[]|null 쓸 수 없는 문자가 있으면 null
 */
function code128b_widths(string $text): ?array
{
    $vals = [104];                       // Start B
    $sum  = 104;
    $pos  = 0;
    $len  = strlen($text);
    for ($i = 0; $i < $len; $i++) {
        $o = ord($text[$i]);
        if ($o < 32 || $o > 126) {
            return null;                 // 코드셋 B 밖의 문자
        }
        $v = $o - 32;
        $vals[] = $v;
        $pos++;
        $sum += $v * $pos;
    }
    $vals[] = $sum % 103;                // 체크문자
    $vals[] = 106;                       // Stop

    $out = [];
    foreach ($vals as $v) {
        $p = CODE128_PATTERNS[$v] ?? null;
        if ($p === null) {
            return null;
        }
        for ($k = 0, $n = strlen($p); $k < $n; $k++) {
            $out[] = (int)$p[$k];
        }
    }
    return $out;
}

/**
 * CODE128 바코드를 SVG 문자열로 그립니다.
 *
 * @param string $text     인코딩할 값 (ASCII 32~126)
 * @param int    $height   막대 높이(px)
 * @param float  $module   1 모듈 폭(px). 스캐너가 읽으려면 0.33mm 이상 권장
 * @param bool   $withText 아래에 값을 글자로 같이 적을지
 */
function code128_svg(string $text, int $height = 60, float $module = 1.6,
                     bool $withText = true): string
{
    $w = code128b_widths($text);
    if ($w === null) {
        return '<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"></svg>';
    }
    $quiet   = 10 * $module;             // 좌우 여백 (규격상 10모듈 이상)
    $modules = array_sum($w);
    $barW    = $modules * $module;
    $textH   = $withText ? 15 : 0;
    $totalW  = $barW + $quiet * 2;
    $totalH  = $height + $textH;

    $rects = '';
    $x     = $quiet;
    $dark  = true;                       // 첫 값은 항상 검은 막대
    foreach ($w as $mod) {
        $ww = $mod * $module;
        if ($dark) {
            $rects .= sprintf('<rect x="%.2f" y="0" width="%.2f" height="%d"/>', $x, $ww, $height);
        }
        $x   += $ww;
        $dark = !$dark;
    }

    $label = '';
    if ($withText) {
        $label = sprintf(
            '<text x="%.2f" y="%d" text-anchor="middle" font-family="monospace" '
            . 'font-size="12" letter-spacing="1.5">%s</text>',
            $totalW / 2, $height + 12,
            htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
    }

    return sprintf(
        '<svg xmlns="http://www.w3.org/2000/svg" width="%.2f" height="%d" '
        . 'viewBox="0 0 %.2f %d"><rect width="100%%" height="100%%" fill="#fff"/>'
        . '<g fill="#000">%s</g>%s</svg>',
        $totalW, $totalH, $totalW, $totalH, $rects, $label);
}
