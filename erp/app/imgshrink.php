<?php
declare(strict_types=1);

// 웹에서 직접 열면 실행되지 않게 막습니다 (nginx 면 .htaccess 가 무시됩니다)
if (!defined('APP_DIR')) { http_response_code(403); exit('Forbidden'); }

/**
 * 그림 자동 축소.
 *
 * 화면에 쓰는 그림(홈페이지 공개 이미지 · 직인 · 로고)은 크게 올려도 소용이 없고
 * 휴대폰에서 느려지기만 합니다. 올릴 때 한 번 줄여 둡니다.
 *
 *   · 긴 변을 정해진 크기까지 줄입니다
 *   · 투명한 그림(PNG · WEBP 알파)은 PNG 그대로 — 투명이 깨지면 직인이 흉해집니다
 *   · 투명이 없으면 JPG 로 바꿔 용량을 줄입니다 (확장자도 같이 바뀝니다)
 *   · 목표 용량에 닿을 때까지 화질 → 크기 순서로 낮춥니다
 *
 * 서버에 GD 가 없거나 읽지 못하는 그림이면 **원본을 그대로 둡니다** (업로드는 실패하지 않습니다).
 */

/** 화면용 기본값 — 긴 변 1600px · 300KB */
const IMG_MAX_DIM   = 1600;
const IMG_MAX_BYTES = 300 * 1024;

/** 이 그림에 투명한 곳이 있는지 (PNG · WEBP) */
function img_has_alpha($im): bool
{
    if (!function_exists('imageistruecolor')) { return false; }
    $w = imagesx($im); $h = imagesy($im);
    // 큰 그림을 전부 훑으면 느려서 일정 간격으로만 봅니다
    $step = max(1, (int)floor(min($w, $h) / 60));
    for ($y = 0; $y < $h; $y += $step) {
        for ($x = 0; $x < $w; $x += $step) {
            if (((imagecolorat($im, $x, $y) >> 24) & 0x7F) > 0) { return true; }
        }
    }
    return false;
}

/**
 * 파일을 제자리에서 줄입니다.
 *
 * @param  string $path      줄일 파일 (덮어씁니다)
 * @param  string $ext       지금 확장자 (png · jpg · gif · webp)
 * @param  int    $maxDim    긴 변 최대 픽셀
 * @param  int    $maxBytes  목표 용량
 * @param  string $note      사람이 읽을 결과 ('1054×1577 1.4MB → 1200×1796 180KB')
 * @return string 줄인 뒤의 확장자 (JPG 로 바뀌면 'jpg'). 손대지 않았으면 들어온 확장자 그대로
 */
function img_shrink(string $path, string $ext, int $maxDim = IMG_MAX_DIM,
                    int $maxBytes = IMG_MAX_BYTES, ?string &$note = null): string
{
    $note = '';
    $ext = strtolower($ext);
    $before = (int)@filesize($path);
    $info = @getimagesize($path);
    if (!$info) { return $ext; }
    [$w, $h] = $info;

    // GIF 는 움직이는 그림일 수 있어 건드리지 않습니다
    if ($ext === 'gif' || !function_exists('imagecreatetruecolor')) {
        return $ext;
    }
    // 이미 작으면 그대로 둡니다
    if ($w <= $maxDim && $h <= $maxDim && $before <= $maxBytes) {
        return $ext;
    }

    $src = null;
    if ($ext === 'png' && function_exists('imagecreatefrompng'))   { $src = @imagecreatefrompng($path); }
    if ($ext === 'webp' && function_exists('imagecreatefromwebp')) { $src = @imagecreatefromwebp($path); }
    if (($ext === 'jpg' || $ext === 'jpeg') && function_exists('imagecreatefromjpeg')) {
        $src = @imagecreatefromjpeg($path);
    }
    if (!$src) { return $ext; }

    // 팔레트 그림(색 256개 PNG)은 픽셀 값이 색 번호라 투명 검사가 어긋납니다 — 먼저 트루컬러로
    if (function_exists('imagepalettetotruecolor') && !imageistruecolor($src)) {
        @imagepalettetotruecolor($src);
    }
    $alpha = ($ext === 'png' || $ext === 'webp') && img_has_alpha($src);
    $outExt = $alpha ? 'png' : 'jpg';
    if ($outExt === 'jpg' && !function_exists('imagejpeg')) { imagedestroy($src); return $ext; }

    // 크기 → 화질 순서로 낮춰 가며 목표 용량에 맞춥니다
    $dims = [$maxDim, (int)round($maxDim * 0.8), (int)round($maxDim * 0.64), (int)round($maxDim * 0.5)];
    $qualities = $alpha ? [null] : [82, 72, 62];
    $tmp = $path . '.shrink';
    $bestBytes = 0; $bestW = $w; $bestH = $h; $done = false;

    foreach ($dims as $dim) {
        $scale = min(1.0, $dim / max($w, $h));
        $nw = max(1, (int)round($w * $scale));
        $nh = max(1, (int)round($h * $scale));

        $dst = imagecreatetruecolor($nw, $nh);
        if ($alpha) {
            imagealphablending($dst, false);
            imagesavealpha($dst, true);
            imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 255, 255, 255, 127));
        } else {
            // 투명을 JPG 로 바꾸면 검게 나오므로 흰 바탕을 깔아 줍니다
            imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
        }
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);

        foreach ($qualities as $q) {
            $ok = $alpha ? @imagepng($dst, $tmp, 9) : @imagejpeg($dst, $tmp, (int)$q);
            if (!$ok) { continue; }
            $bytes = (int)@filesize($tmp);
            if ($bytes > 0 && ($bytes <= $maxBytes || $bestBytes === 0 || $bytes < $bestBytes)) {
                $bestBytes = $bytes; $bestW = $nw; $bestH = $nh;
                @rename($tmp, $path . '.best');
            }
            if ($bytes > 0 && $bytes <= $maxBytes) { $done = true; break; }
        }
        imagedestroy($dst);
        if ($done) { break; }
    }
    imagedestroy($src);
    @unlink($tmp);

    if ($bestBytes === 0 || !is_file($path . '.best')) { @unlink($path . '.best'); return $ext; }
    // 줄인 것이 원본보다 크면 원본을 둡니다 (작은 PNG 를 JPG 로 바꿔 커지는 경우)
    if ($bestBytes >= $before && $bestW === $w && $bestH === $h) {
        @unlink($path . '.best');
        return $ext;
    }
    $newPath = $outExt === $ext ? $path : preg_replace('/\.[A-Za-z0-9]+$/', '', $path) . '.' . $outExt;
    if (!@rename($path . '.best', $newPath)) { @unlink($path . '.best'); return $ext; }
    if ($newPath !== $path) { @unlink($path); }

    $note = sprintf('%d×%d %s → %d×%d %s', $w, $h, img_bytes($before), $bestW, $bestH, img_bytes($bestBytes))
          . ($outExt !== $ext ? ' · ' . strtoupper($ext) . '→' . strtoupper($outExt) : '');
    return $outExt;
}

/** 1421143 → 1.4MB */
function img_bytes(int $n): string
{
    if ($n >= 1024 * 1024) { return round($n / 1024 / 1024, 1) . 'MB'; }
    return max(1, (int)round($n / 1024)) . 'KB';
}
