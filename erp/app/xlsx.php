<?php
declare(strict_types=1);

// 웹에서 직접 열면 실행되지 않게 막습니다 (nginx 면 .htaccess 가 무시됩니다)
if (!defined('APP_DIR')) { http_response_code(403); exit('Forbidden'); }

/**
 * 아주 작은 XLSX 만들기 — 시트 하나, 모든 칸을 '텍스트'로 (홈택스 양식이 텍스트 칸이라 '01' 같은 앞자리 0 이 살아야 함).
 * 서버에 ZipArchive 가 없어도 되게 압축 없이(stored) ZIP 을 직접 씁니다.
 *
 *   $bin = xlsx_build('시트이름', [['A1', 'B1'], ['A2', 'B2']], [20, 12]);
 */

/** 0 → A, 25 → Z, 26 → AA */
function xlsx_col(int $i): string
{
    $s = '';
    for ($i++; $i > 0; $i = intdiv($i - 1, 26)) {
        $s = chr(65 + ($i - 1) % 26) . $s;
    }
    return $s;
}

function xlsx_esc(string $s): string
{
    // XML 에 쓸 수 없는 제어문자는 뺍니다 (줄바꿈 · 탭은 둠)
    $s = (string)preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $s);
    return htmlspecialchars($s, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

/** 압축 없는 ZIP. $files = [경로 => 내용] */
function xlsx_zip(array $files): string
{
    $t = getdate();
    $dosTime = ($t['hours'] << 11) | ($t['minutes'] << 5) | intdiv($t['seconds'], 2);
    $dosDate = (($t['year'] - 1980) << 9) | ($t['mon'] << 5) | $t['mday'];
    $out = '';
    $cd  = '';
    $n   = 0;
    foreach ($files as $name => $data) {
        $crc = crc32($data);
        $len = strlen($data);
        $nl  = strlen($name);
        $off = strlen($out);
        // 0x0800 = 파일 이름 UTF-8
        $out .= pack('VvvvvvVVVvv', 0x04034b50, 20, 0x0800, 0, $dosTime, $dosDate, $crc, $len, $len, $nl, 0)
              . $name . $data;
        $cd  .= pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, 0x0800, 0, $dosTime, $dosDate,
                     $crc, $len, $len, $nl, 0, 0, 0, 0, 0, $off) . $name;
        $n++;
    }
    return $out . $cd . pack('VvvvvVVv', 0x06054b50, 0, 0, $n, $n, strlen($cd), strlen($out), 0);
}

/**
 * $rows : 줄마다 칸 값 배열 (문자열로 씀). $widths : 열 너비(문자 수), 비우면 기본.
 * $wrapRows : 줄바꿈을 보이게 할 행 번호(0부터) — 머리말 · 제목 행
 */
function xlsx_build(string $sheetName, array $rows, array $widths = [], array $wrapRows = []): string
{
    // 공유 문자열 표 (엑셀 · 홈택스 등 대부분 프로그램이 이 방식을 기본으로 읽음)
    $sst = [];
    $sstXml = '';
    $sheet = '';
    $count = 0;
    foreach (array_values($rows) as $r => $cells) {
        $rowXml = '';
        foreach (array_values($cells) as $c => $v) {
            $v = (string)($v ?? '');
            if ($v === '') { continue; }
            if (!isset($sst[$v])) {
                $sst[$v] = count($sst);
                $sstXml .= '<si><t xml:space="preserve">' . xlsx_esc($v) . '</t></si>';
            }
            $count++;
            $style = in_array($r, $wrapRows, true) ? 2 : 1;
            $rowXml .= '<c r="' . xlsx_col($c) . ($r + 1) . '" t="s" s="' . $style . '"><v>' . $sst[$v] . '</v></c>';
        }
        $sheet .= '<row r="' . ($r + 1) . '">' . $rowXml . '</row>';
    }
    $cols = '';
    if ($widths) {
        $cols = '<cols>';
        foreach (array_values($widths) as $i => $w) {
            $cols .= '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="' . (float)$w . '" customWidth="1"/>';
        }
        $cols .= '</cols>';
    }
    $x = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
    $ns = 'xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"';
    $nsr = 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"';
    $rel = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
    $files = [
        '[Content_Types].xml' => $x . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . '<Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/>'
            . '</Types>',
        '_rels/.rels' => $x . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="' . $rel . '/officeDocument" Target="xl/workbook.xml"/></Relationships>',
        'xl/workbook.xml' => $x . '<workbook ' . $ns . ' ' . $nsr . '><sheets>'
            . '<sheet name="' . xlsx_esc($sheetName) . '" sheetId="1" r:id="rId1"/></sheets></workbook>',
        'xl/_rels/workbook.xml.rels' => $x . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="' . $rel . '/worksheet" Target="worksheets/sheet1.xml"/>'
            . '<Relationship Id="rId2" Type="' . $rel . '/styles" Target="styles.xml"/>'
            . '<Relationship Id="rId3" Type="' . $rel . '/sharedStrings" Target="sharedStrings.xml"/>'
            . '</Relationships>',
        // 0 기본 · 1 텍스트(@) · 2 텍스트 + 줄바꿈
        'xl/styles.xml' => $x . '<styleSheet ' . $ns . '>'
            . '<fonts count="1"><font><sz val="10"/><name val="맑은 고딕"/></font></fonts>'
            . '<fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills>'
            . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="3"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            . '<xf numFmtId="49" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
            . '<xf numFmtId="49" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1" applyAlignment="1">'
            . '<alignment wrapText="1" vertical="top"/></xf></cellXfs>'
            . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            . '</styleSheet>',
        'xl/sharedStrings.xml' => $x . '<sst ' . $ns . ' count="' . $count . '" uniqueCount="' . count($sst) . '">'
            . $sstXml . '</sst>',
        'xl/worksheets/sheet1.xml' => $x . '<worksheet ' . $ns . ' ' . $nsr . '>' . $cols
            . '<sheetData>' . $sheet . '</sheetData></worksheet>',
    ];
    return xlsx_zip($files);
}
