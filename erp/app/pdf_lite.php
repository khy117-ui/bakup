<?php
declare(strict_types=1);

// 웹에서 직접 열면 실행되지 않게 막습니다 (nginx 면 .htaccess 가 무시됩니다)
if (!defined('APP_DIR')) { http_response_code(403); exit('Forbidden'); }

/**
 * 아주 작은 PDF 만들기 — 라이브러리 · 글꼴 파일 없이 한글 보고서 한두 장.
 * 한글은 PDF 표준 한글 글꼴(HYGoThic-Medium, Adobe-Korea1)을 이름으로만 부르고 파일은 넣지 않습니다.
 * 아크로뱃 · 크롬 · 엣지 · 맥 미리보기 · 휴대폰 뷰어가 PC 의 고딕 글꼴로 대신 그립니다. 영문 · 숫자는 Helvetica.
 *
 *   $p = new PdfLite();  $p->text(40, 60, 18, '월간 보고서', true);  $p->rect(40, 80, 100, 20, [0.9, 0.9, 0.9]);
 *   $bin = $p->output();
 * 좌표는 pt(1/72인치), 왼쪽 위가 (0,0). A4 = 595 × 842.
 */
final class PdfLite
{
    public const W = 595.28;
    public const H = 841.89;

    /** Helvetica 글자 폭 (1000 기준, 32~126) */
    private const HELV = [278, 278, 355, 556, 556, 889, 667, 191, 333, 333, 389, 584, 278, 333, 278, 278, 556, 556, 556, 556, 556, 556, 556, 556,
        556, 556, 278, 278, 584, 584, 584, 556, 1015, 667, 667, 722, 722, 667, 611, 778, 722, 278, 500, 667, 556, 833, 722, 778, 667, 778, 722,
        667, 611, 722, 667, 944, 667, 667, 611, 278, 278, 278, 469, 556, 333, 556, 556, 500, 556, 556, 278, 556, 556, 222, 222, 500, 222, 833,
        556, 556, 556, 556, 333, 500, 278, 556, 500, 722, 500, 500, 500, 334, 260, 334, 584];
    private const HELVB = [278, 333, 474, 556, 556, 889, 722, 238, 333, 333, 389, 584, 278, 333, 278, 278, 556, 556, 556, 556, 556, 556, 556, 556,
        556, 556, 333, 333, 584, 584, 584, 611, 975, 722, 722, 722, 722, 667, 611, 778, 722, 278, 556, 722, 611, 833, 722, 778, 667, 778, 722,
        667, 611, 722, 667, 944, 667, 667, 611, 333, 278, 333, 584, 556, 333, 556, 611, 556, 611, 556, 333, 611, 611, 278, 278, 556, 278, 889,
        611, 611, 611, 611, 389, 556, 333, 611, 556, 778, 556, 556, 500, 389, 280, 389, 584];

    /** Helvetica 로 그릴 기호 (WinAnsi 코드, 폭) — 한글 글꼴에선 전각이라 사이가 벌어짐 */
    private const SYM = ['·' => ["\xB7", 278], '×' => ["\xD7", 584], '…' => ["\x85", 1000], '–' => ["\x96", 556], '•' => ["\x95", 350], '−' => ['-', 333], '—' => ["\x97", 1000]];

    /** @var string[] 쪽마다 그리기 명령 */
    private array $pages = [];
    private int $cur = -1;

    public function __construct()
    {
        $this->addPage();
    }

    public function addPage(): void
    {
        $this->pages[] = '';
        $this->cur = count($this->pages) - 1;
    }

    private function put(string $s): void
    {
        $this->pages[$this->cur] .= $s . "\n";
    }

    private static function num(float $v): string
    {
        return rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.') ?: '0';
    }

    private static function rgb(array $c): string
    {
        return implode(' ', array_map(fn($v) => self::num((float)$v), $c));
    }

    /** 글을 영문(ASCII) · 한글 조각으로 나눔 */
    private static function runs(string $s): array
    {
        preg_match_all('/[\x20-\x7E·×…–•−—]+|[^\x20-\x7E·×…–•−—]+/u', $s, $m);
        return $m[0];
    }

    public function width(string $s, float $size, bool $bold = false): float
    {
        $w = 0;
        $tab = $bold ? self::HELVB : self::HELV;
        foreach (self::runs($s) as $r) {
            if (preg_match('/^[\x20-\x7E·×…–•−—]/u', $r)) {
                foreach (mb_str_split($r) as $ch) { $w += self::SYM[$ch][1] ?? $tab[ord($ch) - 32]; }
            } else {
                $w += 1000 * mb_strlen($r);
            }
        }
        return $w * $size / 1000;
    }

    /** 글 쓰기. $y 는 글자 윗부분 기준. $align: L · R · C ($x 가 오른쪽 끝 · 가운데) */
    public function text(float $x, float $y, float $size, string $s, bool $bold = false, array $color = [0.1, 0.15, 0.2], string $align = 'L'): void
    {
        $s = (string)preg_replace('/[\x00-\x1F]/', ' ', $s);
        if ($s === '') { return; }
        if ($align !== 'L') {
            $w = $this->width($s, $size, $bold);
            $x -= $align === 'R' ? $w : $w / 2;
        }
        $base = self::H - $y - $size * 0.86;
        $out = 'BT ' . self::rgb($color) . ' rg ' . self::rgb($color) . ' RG';
        foreach (self::runs($s) as $r) {
            if (preg_match('/^[\x20-\x7E·×…–•−—]/u', $r)) {
                $font = $bold ? '/F2' : '/F1';
                $str = '(' . strtr($r, ['\\' => '\\\\', '(' => '\\(', ')' => '\\)'] + array_map(fn($x) => $x[0], self::SYM)) . ')';
                $mode = '0 Tr';
            } else {
                $font = '/F3';
                // UCS-2 만 됩니다 (이모지 등 BMP 밖 글자는 빼기)
                $u = (string)preg_replace('/[^\x{0000}-\x{FFFF}]/u', '', $r);
                $str = '<' . strtoupper(bin2hex((string)mb_convert_encoding($u, 'UTF-16BE', 'UTF-8'))) . '>';
                $mode = $bold ? '2 Tr ' . self::num($size * 0.035) . ' w' : '0 Tr';
            }
            $out .= ' ' . $font . ' ' . self::num($size) . ' Tf ' . $mode . ' 1 0 0 1 ' . self::num($x) . ' ' . self::num($base) . ' Tm ' . $str . ' Tj';
            $x += $this->width($r, $size, $bold);
        }
        $this->put($out . ' ET');
    }

    /** 폭 안에 들어가게 자름 (…) */
    public function fit(string $s, float $size, float $maxW, bool $bold = false): string
    {
        if ($this->width($s, $size, $bold) <= $maxW) { return $s; }
        while ($s !== '' && $this->width($s . '…', $size, $bold) > $maxW) { $s = mb_substr($s, 0, -1); }
        return $s . '…';
    }

    public function rect(float $x, float $y, float $w, float $h, ?array $fill = null, ?array $stroke = null, float $lw = 0.5): void
    {
        $cmd = self::num($x) . ' ' . self::num(self::H - $y - $h) . ' ' . self::num($w) . ' ' . self::num($h) . ' re';
        $pre = '';
        if ($fill) { $pre .= self::rgb($fill) . ' rg '; }
        if ($stroke) { $pre .= self::rgb($stroke) . ' RG ' . self::num($lw) . ' w '; }
        $this->put($pre . $cmd . ' ' . ($fill && $stroke ? 'B' : ($fill ? 'f' : 'S')));
    }

    public function line(float $x1, float $y1, float $x2, float $y2, array $color = [0.85, 0.87, 0.9], float $lw = 0.5): void
    {
        $this->put(self::rgb($color) . ' RG ' . self::num($lw) . ' w ' . self::num($x1) . ' ' . self::num(self::H - $y1) . ' m '
                   . self::num($x2) . ' ' . self::num(self::H - $y2) . ' l S');
    }

    public function output(): string
    {
        $objs = [];
        $objs[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $objs[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
        $objs[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';
        $objs[5] = '<< /Type /Font /Subtype /Type0 /BaseFont /HYGoThic-Medium /Encoding /UniKS-UCS2-H /DescendantFonts [6 0 R] >>';
        $objs[6] = '<< /Type /Font /Subtype /CIDFontType0 /BaseFont /HYGoThic-Medium'
                 . ' /CIDSystemInfo << /Registry (Adobe) /Ordering (Korea1) /Supplement 1 >> /FontDescriptor 7 0 R /DW 1000 >>';
        $objs[7] = '<< /Type /FontDescriptor /FontName /HYGoThic-Medium /Flags 6 /FontBBox [-6 -145 1003 880]'
                 . ' /ItalicAngle 0 /Ascent 880 /Descent -120 /CapHeight 720 /StemV 93 >>';
        $kids = [];
        $n = 8;
        foreach ($this->pages as $content) {
            $z = gzcompress($content);
            $objs[$n + 1] = '<< /Length ' . strlen($z) . " /Filter /FlateDecode >>\nstream\n" . $z . "\nendstream";
            $objs[$n] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 ' . self::W . ' ' . self::H . ']'
                      . ' /Resources << /Font << /F1 3 0 R /F2 4 0 R /F3 5 0 R >> >> /Contents ' . ($n + 1) . ' 0 R >>';
            $kids[] = $n . ' 0 R';
            $n += 2;
        }
        $objs[2] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . count($kids) . ' >>';
        ksort($objs);
        $out = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $off = [];
        foreach ($objs as $i => $o) {
            $off[$i] = strlen($out);
            $out .= $i . " 0 obj\n" . $o . "\nendobj\n";
        }
        $xref = strlen($out);
        $max = max(array_keys($objs));
        $out .= "xref\n0 " . ($max + 1) . "\n0000000000 65535 f \n";
        for ($i = 1; $i <= $max; $i++) { $out .= sprintf("%010d 00000 n \n", $off[$i] ?? 0); }
        return $out . "trailer\n<< /Size " . ($max + 1) . " /Root 1 0 R >>\nstartxref\n" . $xref . "\n%%EOF";
    }
}
