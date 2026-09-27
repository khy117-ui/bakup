<?php
declare(strict_types=1);

// 웹에서 직접 열면 실행되지 않게 막습니다 (nginx 면 .htaccess 가 무시됩니다)
if (!defined('APP_DIR')) { http_response_code(403); exit('Forbidden'); }

/**
 * 아주 작은 XLSX 읽기 — 첫 번째 시트의 값만 줄 × 칸 문자열 배열로.
 * 서버에 ZipArchive 가 없어도 되게 ZIP 은 직접 풀고(zlib 의 gzinflate), 서식 · 수식은 보지 않습니다 (저장된 값만).
 *
 *   $rows = xlsx_read_rows(file_get_contents($path));   // [['A1', 'B1'], ['A2', 'B2']]
 */

/** ZIP 안의 파일들 [경로 => 내용] (필요한 것만: $want 가 true 를 돌려주는 이름) */
function xlsx_unzip(string $bin, callable $want): array
{
    $eocd = strrpos($bin, "PK\x05\x06");
    if ($eocd === false) { throw new RuntimeException('엑셀(.xlsx) 파일이 아닙니다.'); }
    $e = unpack('vdisk/vcdisk/vn1/vn/Vsize/Voff', substr($bin, $eocd + 4, 16));
    $p = $e['off'];
    $out = [];
    for ($i = 0; $i < $e['n']; $i++) {
        if (substr($bin, $p, 4) !== "PK\x01\x02") { throw new RuntimeException('엑셀 파일이 깨졌습니다.'); }
        $c = unpack('vver/vneed/vflag/vmethod/vtime/vdate/Vcrc/Vcsize/Vsize/vnl/vxl/vcl/vdisk/vin/Vext/Voff', substr($bin, $p + 4, 42));
        $name = substr($bin, $p + 46, $c['nl']);
        $p += 46 + $c['nl'] + $c['xl'] + $c['cl'];
        if (!$want($name)) { continue; }
        $l = unpack('vnl/vxl', substr($bin, $c['off'] + 26, 4));
        $data = substr($bin, $c['off'] + 30 + $l['nl'] + $l['xl'], $c['csize']);
        if ($c['method'] === 8) {
            $data = @gzinflate($data);
            if ($data === false) { throw new RuntimeException('엑셀 파일 압축을 풀지 못했습니다.'); }
        } elseif ($c['method'] !== 0) {
            throw new RuntimeException('지원하지 않는 압축 방식입니다. CSV 로 저장해 올려 주세요.');
        }
        $out[$name] = $data;
    }
    return $out;
}

function xlsx_read_rows(string $bin, int $maxRows = 20000): array
{
    if (!function_exists('gzinflate') || !function_exists('simplexml_load_string')) {
        throw new RuntimeException('이 서버는 엑셀 파일을 읽을 수 없습니다. CSV 로 저장해 올려 주세요.');
    }
    $f = xlsx_unzip($bin, fn(string $n) => $n === 'xl/sharedStrings.xml' || str_starts_with($n, 'xl/worksheets/sheet'));
    $sheets = array_filter(array_keys($f), fn($n) => str_starts_with($n, 'xl/worksheets/sheet'));
    if (!$sheets) { throw new RuntimeException('엑셀 파일에 시트가 없습니다.'); }
    natsort($sheets);
    $sheet = $f['xl/worksheets/sheet1.xml'] ?? $f[reset($sheets)];

    $shared = [];
    if (isset($f['xl/sharedStrings.xml'])) {
        $x = simplexml_load_string($f['xl/sharedStrings.xml'], 'SimpleXMLElement', LIBXML_NONET);
        foreach ($x->si ?? [] as $si) {
            $t = '';
            foreach ($si->xpath('.//*[local-name()="t"]') as $n) { $t .= (string)$n; }
            $shared[] = $t;
        }
    }
    $x = simplexml_load_string($sheet, 'SimpleXMLElement', LIBXML_NONET);
    if ($x === false) { throw new RuntimeException('엑셀 시트를 읽지 못했습니다.'); }
    $rows = [];
    foreach ($x->sheetData->row ?? [] as $row) {
        if (count($rows) >= $maxRows) { break; }
        $r = [];
        $next = 1;
        foreach ($row->c as $c) {
            $col = 0;
            foreach (str_split((string)preg_replace('/[^A-Z]/', '', strtoupper((string)$c['r']))) as $ch) { $col = $col * 26 + ord($ch) - 64; }
            if ($col <= 0) { $col = $next; }   // r 속성을 안 쓰는 프로그램도 있음
            $next = $col + 1;
            $t = (string)$c['t'];
            if ($t === 's') {
                $v = $shared[(int)$c->v] ?? '';
            } elseif ($t === 'inlineStr') {
                $v = '';
                foreach ($c->xpath('.//*[local-name()="t"]') as $n) { $v .= (string)$n; }
            } else {
                $v = (string)$c->v;
                // 숫자로 저장된 긴 송장번호 (1.23456789012E+11) → 원래 자리수
                if ($t === '' && preg_match('/^\d+(\.\d+)?E\+\d+$/i', $v)) { $v = number_format((float)$v, 0, '', ''); }
            }
            $r[max(0, $col - 1)] = $v;
        }
        if ($r) {
            $full = array_fill(0, max(array_keys($r)) + 1, '');
            foreach ($r as $i => $v) { $full[$i] = $v; }
            $rows[] = $full;
        }
    }
    return $rows;
}
