<?php
declare(strict_types=1);

// 웹에서 직접 열면 실행되지 않게 막습니다 (nginx 면 .htaccess 가 무시됩니다)
if (!defined('APP_DIR')) { http_response_code(403); exit('Forbidden'); }

require_once APP_DIR . '/xlsx.php';

/**
 * 모양을 갖춘 XLSX 만들기 — 칸 테두리 · 바탕색 · 병합 · 그림(직인)까지.
 *
 * app/xlsx.php 의 xlsx_build 는 값만 넣는 단순한 표용입니다. 인쇄물(청구서 등)을
 * 엑셀로도 똑같이 보이게 하려고 이 파일을 따로 두었습니다. 외부 라이브러리는 쓰지 않습니다.
 *
 *   $bin = xlsx_rich([
 *     'sheet'  => 'INVOICE',
 *     'widths' => [10, 16, ...],
 *     'heights'=> [0 => 30],                 // 0부터 세는 줄 번호 => 높이(pt)
 *     'merges' => ['A1:C1', 'H1:J1'],
 *     'rows'   => [[ ['v' => 'INVOICE', 's' => 'title'], ... ], ...],
 *     'image'  => ['bin' => $png, 'col' => 8, 'row' => 0, 'w' => 90, 'h' => 90],
 *   ]);
 *
 * 칸 하나는 그냥 값(문자열 · 숫자)이거나 ['v' => 값, 's' => 스타일이름] 입니다.
 * 스타일 이름은 아래 XLSX_STYLES 의 열쇠입니다.
 */

/** 스타일 이름 → cellXfs 번호 (styles.xml 의 차례와 같아야 합니다) */
const XLSX_STYLES = [
    ''        => 0,   // 기본
    'title'   => 1,   // 큰 제목
    'sub'     => 2,   // 작은 회색 글씨
    'lbl'     => 3,   // 표 안 라벨 (회색 바탕 + 테두리)
    'box'     => 4,   // 표 안 값 (테두리)
    'th'      => 5,   // 표 머리글
    'thr'     => 6,   // 표 머리글 — 오른쪽
    'td'      => 7,   // 표 값
    'tdc'     => 8,   // 표 값 — 가운데
    'tdn'     => 9,   // 표 값 — 숫자(오른쪽, #,##0)
    'tdb'     => 10,  // 표 값 — 굵게
    'sumlbl'  => 11,  // 합계칸 라벨
    'sumn'    => 12,  // 합계칸 숫자
    'totlbl'  => 13,  // 합계 금액 라벨 (굵게 + 바탕)
    'totn'    => 14,  // 합계 금액 (굵게 + 바탕)
    'co'      => 15,  // 회사 이름 (오른쪽 굵게)
    'cor'     => 16,  // 회사 정보 (오른쪽 작게)
    'wrap'    => 17,  // 줄바꿈되는 값
    'foot'    => 18,  // 맨 아래 가운데 안내
];

/** styles.xml — 글꼴 · 바탕 · 테두리 · 칸 모양 */
function xlsx_rich_styles(): string
{
    $f = '맑은 고딕';
    $fonts = [
        '<font><sz val="10"/><name val="' . $f . '"/></font>',                                    // 0
        '<font><b/><sz val="22"/><name val="' . $f . '"/></font>',                                // 1 제목
        '<font><sz val="9"/><color rgb="FF6B7C8A"/><name val="' . $f . '"/></font>',              // 2 작은 회색
        '<font><b/><sz val="10"/><color rgb="FF33485A"/><name val="' . $f . '"/></font>',         // 3 라벨
        '<font><b/><sz val="12"/><name val="' . $f . '"/></font>',                                // 4 합계
        '<font><b/><sz val="11"/><name val="' . $f . '"/></font>',                                // 5 회사명
        '<font><b/><sz val="10"/><name val="' . $f . '"/></font>',                                // 6 굵은 본문
    ];
    $fills = [
        '<fill><patternFill patternType="none"/></fill>',
        '<fill><patternFill patternType="gray125"/></fill>',
        '<fill><patternFill patternType="solid"><fgColor rgb="FFEEF3F6"/><bgColor indexed="64"/></patternFill></fill>', // 2
        '<fill><patternFill patternType="solid"><fgColor rgb="FFF7FAFB"/><bgColor indexed="64"/></patternFill></fill>', // 3
    ];
    $thin  = '<left style="thin"><color rgb="FFC9D6DF"/></left><right style="thin"><color rgb="FFC9D6DF"/></right>'
           . '<top style="thin"><color rgb="FFC9D6DF"/></top><bottom style="thin"><color rgb="FFC9D6DF"/></bottom>';
    $under = '<left/><right/><top/><bottom style="thin"><color rgb="FFE7EDF1"/></bottom>';
    $head  = '<left/><right/><top style="medium"><color rgb="FF0C1A26"/></top>'
           . '<bottom style="thin"><color rgb="FFC9D6DF"/></bottom>';
    $borders = [
        '<border><left/><right/><top/><bottom/><diagonal/></border>',            // 0 없음
        '<border>' . $thin . '<diagonal/></border>',                             // 1 사방
        '<border>' . $under . '<diagonal/></border>',                            // 2 아래만
        '<border>' . $head . '<diagonal/></border>',                             // 3 머리글
    ];
    // numFmtId 3 = #,##0
    $xf = static fn (int $font, int $fill, int $border, string $align = '', int $fmt = 0): string =>
        '<xf numFmtId="' . $fmt . '" fontId="' . $font . '" fillId="' . $fill . '" borderId="' . $border
        . '" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyNumberFormat="1"'
        . ($align !== '' ? ' applyAlignment="1"><alignment ' . $align . '/></xf>' : '/>');

    $cellXfs = [
        $xf(0, 0, 0),                                                           // 0 기본
        $xf(1, 0, 0, 'vertical="center"'),                                      // 1 제목
        $xf(2, 0, 0, 'vertical="center"'),                                      // 2 작은 회색
        $xf(3, 2, 1, 'vertical="center"'),                                      // 3 라벨
        $xf(0, 0, 1, 'vertical="center" wrapText="1"'),                         // 4 값 상자
        $xf(3, 2, 3, 'vertical="center"'),                                      // 5 머리글
        $xf(3, 2, 3, 'vertical="center" horizontal="right"'),                   // 6 머리글 오른쪽
        $xf(0, 0, 2, 'vertical="center"'),                                      // 7 값
        $xf(0, 0, 2, 'vertical="center" horizontal="center"'),                  // 8 값 가운데
        $xf(0, 0, 2, 'vertical="center" horizontal="right"', 3),                // 9 값 숫자
        $xf(6, 0, 2, 'vertical="center"'),                                      // 10 값 굵게
        $xf(0, 0, 1, 'vertical="center"'),                                      // 11 합계 라벨
        $xf(0, 0, 1, 'vertical="center" horizontal="right"', 3),                // 12 합계 숫자
        $xf(4, 3, 1, 'vertical="center"'),                                      // 13 합계금액 라벨
        $xf(4, 3, 1, 'vertical="center" horizontal="right"', 3),                // 14 합계금액
        $xf(5, 0, 0, 'vertical="center" horizontal="right"'),                   // 15 회사명
        $xf(2, 0, 0, 'vertical="center" horizontal="right"'),                   // 16 회사정보
        $xf(0, 0, 0, 'vertical="top" wrapText="1"'),                            // 17 줄바꿈
        $xf(2, 0, 0, 'vertical="center" horizontal="center"'),                  // 18 아래 안내
    ];
    return '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<fonts count="' . count($fonts) . '">' . implode('', $fonts) . '</fonts>'
        . '<fills count="' . count($fills) . '">' . implode('', $fills) . '</fills>'
        . '<borders count="' . count($borders) . '">' . implode('', $borders) . '</borders>'
        . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
        . '<cellXfs count="' . count($cellXfs) . '">' . implode('', $cellXfs) . '</cellXfs>'
        . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
        . '</styleSheet>';
}

/** px → EMU (엑셀 그림 좌표 단위) */
function xlsx_emu(float $px): int
{
    return (int)round($px * 9525);
}

function xlsx_rich(array $spec): string
{
    $x   = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
    $ns  = 'xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"';
    $nsr = 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"';
    $rel = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

    $sst = []; $sstXml = ''; $count = 0; $sheetRows = '';
    foreach (array_values($spec['rows'] ?? []) as $r => $cells) {
        $rowXml = '';
        foreach (array_values((array)$cells) as $c => $cell) {
            $style = 0; $v = $cell;
            if (is_array($cell)) {
                $v = $cell['v'] ?? '';
                $style = XLSX_STYLES[(string)($cell['s'] ?? '')] ?? 0;
            }
            $ref = xlsx_col($c) . ($r + 1);
            if (is_int($v) || is_float($v)) {
                $rowXml .= '<c r="' . $ref . '" s="' . $style . '"><v>'
                    . (is_float($v) ? rtrim(rtrim(sprintf('%.4F', $v), '0'), '.') : $v) . '</v></c>';
                continue;
            }
            $v = (string)($v ?? '');
            if ($v === '') {
                // 값이 없어도 테두리 · 바탕색은 남겨야 하므로 빈 칸을 씁니다
                if ($style !== 0) { $rowXml .= '<c r="' . $ref . '" s="' . $style . '"/>'; }
                continue;
            }
            if (!isset($sst[$v])) {
                $sst[$v] = count($sst);
                $sstXml .= '<si><t xml:space="preserve">' . xlsx_esc($v) . '</t></si>';
            }
            $count++;
            $rowXml .= '<c r="' . $ref . '" t="s" s="' . $style . '"><v>' . $sst[$v] . '</v></c>';
        }
        $h = $spec['heights'][$r] ?? null;
        $sheetRows .= '<row r="' . ($r + 1) . '"'
            . ($h ? ' ht="' . (float)$h . '" customHeight="1"' : '') . '>' . $rowXml . '</row>';
    }

    $cols = '';
    if (!empty($spec['widths'])) {
        $cols = '<cols>';
        foreach (array_values($spec['widths']) as $i => $w) {
            $cols .= '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="' . (float)$w . '" customWidth="1"/>';
        }
        $cols .= '</cols>';
    }
    $merges = '';
    if (!empty($spec['merges'])) {
        $merges = '<mergeCells count="' . count($spec['merges']) . '">';
        foreach ($spec['merges'] as $m) { $merges .= '<mergeCell ref="' . xlsx_esc((string)$m) . '"/>'; }
        $merges .= '</mergeCells>';
    }

    $img = $spec['image'] ?? null;
    $hasImg = is_array($img) && !empty($img['bin']);
    $drawingTag = $hasImg ? '<drawing r:id="rId2"/>' : '';

    $files = [
        '[Content_Types].xml' => $x . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . ($hasImg ? '<Default Extension="png" ContentType="image/png"/>' : '')
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . '<Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/>'
            . ($hasImg ? '<Override PartName="/xl/drawings/drawing1.xml" ContentType="application/vnd.openxmlformats-officedocument.drawing+xml"/>' : '')
            . '</Types>',
        '_rels/.rels' => $x . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="' . $rel . '/officeDocument" Target="xl/workbook.xml"/></Relationships>',
        'xl/workbook.xml' => $x . '<workbook ' . $ns . ' ' . $nsr . '><sheets>'
            . '<sheet name="' . xlsx_esc((string)($spec['sheet'] ?? 'Sheet1')) . '" sheetId="1" r:id="rId1"/>'
            . '</sheets></workbook>',
        'xl/_rels/workbook.xml.rels' => $x . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="' . $rel . '/worksheet" Target="worksheets/sheet1.xml"/>'
            . '<Relationship Id="rId2" Type="' . $rel . '/styles" Target="styles.xml"/>'
            . '<Relationship Id="rId3" Type="' . $rel . '/sharedStrings" Target="sharedStrings.xml"/>'
            . '</Relationships>',
        'xl/styles.xml' => $x . xlsx_rich_styles(),
        'xl/sharedStrings.xml' => $x . '<sst ' . $ns . ' count="' . $count . '" uniqueCount="' . count($sst) . '">'
            . $sstXml . '</sst>',
        // 칸 순서(cols → sheetData → mergeCells → drawing)를 지켜야 엑셀이 엽니다
        'xl/worksheets/sheet1.xml' => $x . '<worksheet ' . $ns . ' ' . $nsr . '>'
            . '<sheetPr><pageSetUpPr fitToPage="1"/></sheetPr>'
            . $cols . '<sheetData>' . $sheetRows . '</sheetData>' . $merges
            . '<pageMargins left="0.3" right="0.3" top="0.4" bottom="0.4" header="0.2" footer="0.2"/>'
            . '<pageSetup paperSize="9" orientation="' . (($spec['landscape'] ?? true) ? 'landscape' : 'portrait')
            . '" fitToWidth="1" fitToHeight="0"/>' . $drawingTag . '</worksheet>',
    ];

    if ($hasImg) {
        $files['xl/worksheets/_rels/sheet1.xml.rels'] = $x
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId2" Type="' . $rel . '/drawing" Target="../drawings/drawing1.xml"/></Relationships>';
        $files['xl/drawings/_rels/drawing1.xml.rels'] = $x
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="' . $rel . '/image" Target="../media/image1.png"/></Relationships>';
        $files['xl/media/image1.png'] = (string)$img['bin'];
        $files['xl/drawings/drawing1.xml'] = $x
            . '<xdr:wsDr xmlns:xdr="http://schemas.openxmlformats.org/drawingml/2006/spreadsheetDrawing" '
            . 'xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main">'
            . '<xdr:oneCellAnchor>'
            . '<xdr:from><xdr:col>' . (int)($img['col'] ?? 0) . '</xdr:col>'
            . '<xdr:colOff>' . xlsx_emu((float)($img['dx'] ?? 0)) . '</xdr:colOff>'
            . '<xdr:row>' . (int)($img['row'] ?? 0) . '</xdr:row>'
            . '<xdr:rowOff>' . xlsx_emu((float)($img['dy'] ?? 0)) . '</xdr:rowOff></xdr:from>'
            . '<xdr:ext cx="' . xlsx_emu((float)($img['w'] ?? 90)) . '" cy="' . xlsx_emu((float)($img['h'] ?? 90)) . '"/>'
            . '<xdr:pic><xdr:nvPicPr><xdr:cNvPr id="1" name="stamp"/><xdr:cNvPicPr/></xdr:nvPicPr>'
            . '<xdr:blipFill><a:blip xmlns:r="' . $rel . '" r:embed="rId1"/><a:stretch><a:fillRect/></a:stretch></xdr:blipFill>'
            . '<xdr:spPr><a:xfrm><a:off x="0" y="0"/>'
            . '<a:ext cx="' . xlsx_emu((float)($img['w'] ?? 90)) . '" cy="' . xlsx_emu((float)($img['h'] ?? 90)) . '"/></a:xfrm>'
            . '<a:prstGeom prst="rect"><a:avLst/></a:prstGeom></xdr:spPr></xdr:pic>'
            . '<xdr:clientData/></xdr:oneCellAnchor></xdr:wsDr>';
    }
    return xlsx_zip($files);
}

/** data:image/...;base64,... 를 PNG 원본으로. PNG 가 아니면 GD 로 바꿔 줍니다 */
function xlsx_png_from_data_uri(?string $uri): ?string
{
    if (!$uri || !preg_match('#^data:image/(png|jpeg|jpg|webp);base64,(.+)$#s', $uri, $m)) { return null; }
    $bin = base64_decode($m[2], true);
    if ($bin === false || $bin === '') { return null; }
    if ($m[1] === 'png') { return $bin; }
    if (!function_exists('imagecreatefromstring') || !function_exists('imagepng')) { return null; }
    $im = @imagecreatefromstring($bin);
    if (!$im) { return null; }
    ob_start();
    imagesavealpha($im, true);
    @imagepng($im, null, 9);
    $out = (string)ob_get_clean();
    imagedestroy($im);
    return $out !== '' ? $out : null;
}
