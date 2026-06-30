<?php

namespace Webmail\Addons\Moodboards\Services;

class MoodBoardExportAssets
{
    private array $assetMap;
    private string $tempDir;

    private static ?string $systemTtfFont = null;

    public function __construct(array $assetMap, string $tempDir)
    {
        $this->assetMap = $assetMap;
        $this->tempDir = $tempDir;
    }

    public function resolveAssetData(string $url): ?string
    {
        if (empty($url)) return null;
        if (str_starts_with($url, 'data:')) return $url;
        if (isset($this->assetMap[$url])) return $this->assetMap[$url];
        $noSlash = ltrim($url, '/');
        if (isset($this->assetMap[$noSlash])) return $this->assetMap[$noSlash];
        return null;
    }

    public function extractBase64(string $dataUri): ?array
    {
        if (!str_starts_with($dataUri, 'data:')) return null;
        $commaPos = strpos($dataUri, ',');
        if ($commaPos === false) return null;
        $header = substr($dataUri, 5, $commaPos - 5);
        $base64Data = substr($dataUri, $commaPos + 1);
        $semiPos = strpos($header, ';');
        $mime = $semiPos !== false ? substr($header, 0, $semiPos) : $header;
        if (empty($base64Data)) return null;
        return ['mime' => $mime, 'base64' => $base64Data];
    }

    public function convertToPng(string $imageData): ?string
    {
        $img = @imagecreatefromstring($imageData);
        if (!$img) return null;
        imagesavealpha($img, true);
        imagealphablending($img, false);
        ob_start();
        imagepng($img, null, 6);
        $png = ob_get_clean();
        imagedestroy($img);
        return $png ?: null;
    }

    public function assetToTempFile(string $url, string $prefix): ?string
    {
        $data = $this->resolveAssetData($url);
        if (!$data) return null;
        $b64 = $this->extractBase64($data);
        if (!$b64) return null;
        $binary = base64_decode($b64['base64'], true);
        if (!$binary || strlen($binary) === 0) return null;
        $mime = $b64['mime'];
        if (in_array($mime, ['image/webp', 'image/avif', 'image/bmp', 'image/svg+xml'])) {
            $converted = $this->convertToPng($binary);
            if ($converted) { $binary = $converted; $mime = 'image/png'; }
        }
        $ext = match ($mime) { 'image/png' => 'png', 'image/gif' => 'gif', default => 'jpg' };
        $path = $this->tempDir . '/' . $prefix . '.' . $ext;
        $written = @file_put_contents($path, $binary);
        return ($written && file_exists($path)) ? $path : null;
    }

    public function resolveBinary(string $url): ?array
    {
        $data = $this->resolveAssetData($url);
        if (!$data) return null;
        $b64 = $this->extractBase64($data);
        if (!$b64) return null;
        $binary = base64_decode($b64['base64'], true);
        if (!$binary || strlen($binary) === 0) return null;
        $mime = $b64['mime'];
        if (in_array($mime, ['image/webp', 'image/avif', 'image/bmp', 'image/svg+xml'])) {
            $converted = $this->convertToPng($binary);
            if ($converted) { $binary = $converted; $mime = 'image/png'; }
        }
        return ['binary' => $binary, 'mime' => $mime];
    }

    public function getTempDir(): string
    {
        return $this->tempDir;
    }

    // ── Color helpers ──

    public static function parseHexToRgb(string $color): array
    {
        $color = trim($color);
        if (str_starts_with($color, 'rgb')) {
            if (preg_match('/rgba?\(\s*(\d+)[,\s]+(\d+)[,\s]+(\d+)/', $color, $m)) {
                return [(int)$m[1], (int)$m[2], (int)$m[3]];
            }
        }
        if (!str_starts_with($color, '#')) $color = '#' . $color;
        $hex = ltrim($color, '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        if (strlen($hex) === 8) $hex = substr($hex, 0, 6);
        if (!preg_match('/^[0-9a-fA-F]{6}$/', $hex)) $hex = 'F5F5F5';
        return [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
    }

    public static function getContrastRgb(array $rgb): array
    {
        $luminance = ($rgb[0] * 299 + $rgb[1] * 587 + $rgb[2] * 114) / 1000;
        return $luminance > 128 ? [31, 41, 55] : [255, 255, 255];
    }

    public static function applyTextTransform(string $text, string $transform): string
    {
        return match ($transform) {
            'uppercase'  => mb_strtoupper($text),
            'lowercase'  => mb_strtolower($text),
            'capitalize' => mb_convert_case($text, MB_CASE_TITLE),
            default      => $text,
        };
    }

    // ── Font helpers ──

    public static function mapPdfFont(string $family): string
    {
        $first = trim(explode(',', $family)[0], " \t\n\r\0\x0B'\"");
        if (empty($first)) return 'dejavusans';
        $lower = strtolower($first);

        $sansSerif = [
            'sans-serif', 'inter', 'roboto', 'open sans', 'lato', 'montserrat',
            'poppins', 'raleway', 'source sans 3', 'nunito', 'work sans', 'outfit',
            'oswald', 'bebas neue', 'anton', 'archivo black', 'arial', 'calibri',
            'verdana', 'tahoma', 'segoe ui', 'dm sans', 'manrope', 'rubik',
            'noto sans', 'ubuntu', 'barlow', 'josefin sans', 'quicksand',
            'nunito sans', 'figtree', 'plus jakarta sans', 'space grotesk',
        ];
        $serif = [
            'serif', 'playfair display', 'merriweather', 'lora', 'pt serif',
            'libre baskerville', 'georgia', 'times new roman', 'times',
            'noto serif', 'crimson text', 'eb garamond', 'cormorant garamond',
            'bitter', 'alegreya', 'spectral', 'source serif 4',
        ];
        $mono = [
            'monospace', 'roboto mono', 'source code pro', 'fira code',
            'jetbrains mono', 'consolas', 'courier new', 'courier',
            'ibm plex mono', 'space mono', 'ubuntu mono',
        ];

        if (in_array($lower, $sansSerif)) return 'dejavusans';
        if (in_array($lower, $serif)) return 'dejavuserif';
        if (in_array($lower, $mono)) return 'dejavusansmono';
        return 'dejavusans';
    }

    public static function findSystemTtfFont(): ?string
    {
        if (self::$systemTtfFont !== null) return self::$systemTtfFont ?: null;

        $candidates = [
            '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
            '/usr/share/fonts/TTF/DejaVuSans.ttf',
            '/usr/share/fonts/dejavu/DejaVuSans.ttf',
            '/usr/share/fonts/truetype/liberation/LiberationSans-Regular.ttf',
            '/usr/share/fonts/truetype/freefont/FreeSans.ttf',
        ];
        foreach ($candidates as $path) {
            if (file_exists($path)) {
                self::$systemTtfFont = $path;
                return $path;
            }
        }
        self::$systemTtfFont = '';
        return null;
    }

    // ── Gradient helpers ──

    /**
     * Parse gradient data from style_data and return a normalized structure.
     * Returns null if no gradient is configured.
     */
    public static function parseGradient(array $sd, string $itemType): ?array
    {
        $typeKeyMap = [
            'shape' => 'shape_fill_type', 'pen_shape' => 'shape_fill_type',
            'frame' => 'fill_type', 'slide' => 'fill_type', 'text' => 'text_fill_type',
        ];
        $gradKeyMap = [
            'shape' => 'shape_fill_gradient', 'pen_shape' => 'shape_fill_gradient',
            'frame' => 'fill_gradient', 'slide' => 'fill_gradient', 'text' => 'text_fill_gradient',
        ];

        $fillType = $sd[$typeKeyMap[$itemType] ?? ''] ?? 'solid';
        if ($fillType !== 'linear' && $fillType !== 'radial') return null;

        $grad = $sd[$gradKeyMap[$itemType] ?? ''] ?? null;
        if (!$grad || empty($grad['stops']) || count($grad['stops']) < 2) return null;

        $stops = $grad['stops'];
        usort($stops, fn($a, $b) => ($a['position'] ?? 0) - ($b['position'] ?? 0));

        return [
            'type' => $fillType,
            'angle' => (float)($grad['angle'] ?? 180),
            'stops' => array_map(fn($s) => [
                'color' => self::parseHexToRgb($s['color'] ?? '#000000'),
                'position' => ((float)($s['position'] ?? 0)) / 100.0,
            ], $stops),
        ];
    }

    /**
     * Convert a CSS angle to TCPDF LinearGradient normalized coords [x1,y1,x2,y2].
     */
    public static function cssAngleToGradientCoords(float $angle): array
    {
        $rad = deg2rad($angle);
        return [
            0.5 - sin($rad) * 0.5,
            0.5 + cos($rad) * 0.5,
            0.5 + sin($rad) * 0.5,
            0.5 - cos($rad) * 0.5,
        ];
    }

    /**
     * Render a gradient rectangle as a PNG using GD.
     * Used when TCPDF native gradient is not sufficient (e.g. multi-stop).
     */
    public static function renderGradientGd(int $w, int $h, array $gradient): ?string
    {
        if ($w < 1 || $h < 1) return null;
        $w = min($w, 2000);
        $h = min($h, 2000);

        $img = imagecreatetruecolor($w, $h);
        if (!$img) return null;
        imagealphablending($img, true);

        $stops = $gradient['stops'];
        $type = $gradient['type'];
        $angle = $gradient['angle'] ?? 180;

        if ($type === 'radial') {
            self::fillRadialGradientGd($img, $w, $h, $stops);
        } else {
            self::fillLinearGradientGd($img, $w, $h, $stops, $angle);
        }

        ob_start();
        imagepng($img, null, 6);
        $result = ob_get_clean();
        imagedestroy($img);
        return $result ?: null;
    }

    private static function fillLinearGradientGd($img, int $w, int $h, array $stops, float $angle): void
    {
        $rad = deg2rad($angle);
        $sinA = sin($rad);
        $cosA = cos($rad);

        for ($y = 0; $y < $h; $y++) {
            $ny = $h > 1 ? $y / ($h - 1) : 0.5;
            for ($x = 0; $x < $w; $x++) {
                $nx = $w > 1 ? $x / ($w - 1) : 0.5;
                $t = ($nx - 0.5) * $sinA - ($ny - 0.5) * $cosA + 0.5;
                $t = max(0.0, min(1.0, $t));
                $rgb = self::interpolateStops($stops, $t);
                $c = imagecolorallocate($img, $rgb[0], $rgb[1], $rgb[2]);
                imagesetpixel($img, $x, $y, $c);
            }
        }
    }

    private static function fillRadialGradientGd($img, int $w, int $h, array $stops): void
    {
        $cx = $w / 2;
        $cy = $h / 2;
        $maxR = sqrt($cx * $cx + $cy * $cy);

        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                $dx = $x - $cx;
                $dy = $y - $cy;
                $t = sqrt($dx * $dx + $dy * $dy) / max($maxR, 1);
                $t = max(0.0, min(1.0, $t));
                $rgb = self::interpolateStops($stops, $t);
                $c = imagecolorallocate($img, $rgb[0], $rgb[1], $rgb[2]);
                imagesetpixel($img, $x, $y, $c);
            }
        }
    }

    private static function interpolateStops(array $stops, float $t): array
    {
        if (empty($stops)) return [0, 0, 0];
        if ($t <= $stops[0]['position']) return $stops[0]['color'];
        $last = end($stops);
        if ($t >= $last['position']) return $last['color'];

        for ($i = 0; $i < count($stops) - 1; $i++) {
            $s0 = $stops[$i];
            $s1 = $stops[$i + 1];
            if ($t >= $s0['position'] && $t <= $s1['position']) {
                $range = $s1['position'] - $s0['position'];
                $frac = $range > 0 ? ($t - $s0['position']) / $range : 0;
                return [
                    (int)round($s0['color'][0] + ($s1['color'][0] - $s0['color'][0]) * $frac),
                    (int)round($s0['color'][1] + ($s1['color'][1] - $s0['color'][1]) * $frac),
                    (int)round($s0['color'][2] + ($s1['color'][2] - $s0['color'][2]) * $frac),
                ];
            }
        }
        return $last['color'];
    }

    /**
     * Render text with a gradient fill as a transparent PNG using GD.
     * Requires a TTF font on the system; returns null if unavailable.
     */
    public static function renderGradientTextGd(
        string $text, float $fontSize, string $fontFamily,
        bool $isBold, bool $isItalic, string $align,
        int $imgW, int $imgH, array $gradient, int $padding = 0
    ): ?string {
        $ttfPath = self::findSystemTtfFont();
        if (!$ttfPath || empty(trim($text))) return null;

        $imgW = max(20, min($imgW, 3000));
        $imgH = max(10, min($imgH, 2000));
        $gdFontSize = max(6, $fontSize);

        $img = imagecreatetruecolor($imgW, $imgH);
        if (!$img) return null;
        imagealphablending($img, false);
        imagesavealpha($img, true);
        $trans = imagecolorallocatealpha($img, 0, 0, 0, 127);
        imagefill($img, 0, 0, $trans);
        imagealphablending($img, true);

        $white = imagecolorallocate($img, 255, 255, 255);
        $lines = explode("\n", $text);
        $lineHeight = (int)($gdFontSize * 1.4);
        $yPos = $padding + (int)$gdFontSize;

        foreach ($lines as $line) {
            if (empty(trim($line))) { $yPos += $lineHeight; continue; }
            $bbox = @imagettfbbox($gdFontSize, 0, $ttfPath, $line);
            $textW = $bbox ? abs($bbox[2] - $bbox[0]) : mb_strlen($line) * $gdFontSize * 0.6;
            $xPos = $padding;
            if ($align === 'center') $xPos = ($imgW - $textW) / 2;
            elseif ($align === 'right') $xPos = $imgW - $textW - $padding;
            @imagettftext($img, $gdFontSize, 0, (int)$xPos, $yPos, $white, $ttfPath, $line);
            $yPos += $lineHeight;
        }

        $gradImg = imagecreatetruecolor($imgW, $imgH);
        if (!$gradImg) { imagedestroy($img); return null; }
        imagealphablending($gradImg, true);

        $stops = $gradient['stops'];
        $type = $gradient['type'];
        $angle = $gradient['angle'] ?? 180;

        if ($type === 'radial') {
            self::fillRadialGradientGd($gradImg, $imgW, $imgH, $stops);
        } else {
            self::fillLinearGradientGd($gradImg, $imgW, $imgH, $stops, $angle);
        }

        $result = imagecreatetruecolor($imgW, $imgH);
        if (!$result) { imagedestroy($img); imagedestroy($gradImg); return null; }
        imagealphablending($result, false);
        imagesavealpha($result, true);
        imagefill($result, 0, 0, $trans);
        imagealphablending($result, true);

        for ($y = 0; $y < $imgH; $y++) {
            for ($x = 0; $x < $imgW; $x++) {
                $textPixel = imagecolorat($img, $x, $y);
                $textAlpha = ($textPixel >> 24) & 0x7F;
                if ($textAlpha >= 127) continue;
                $gradPixel = imagecolorat($gradImg, $x, $y);
                $gr = ($gradPixel >> 16) & 0xFF;
                $gg = ($gradPixel >> 8) & 0xFF;
                $gb = $gradPixel & 0xFF;
                $c = imagecolorallocatealpha($result, $gr, $gg, $gb, $textAlpha);
                imagesetpixel($result, $x, $y, $c);
            }
        }

        imagedestroy($img);
        imagedestroy($gradImg);

        ob_start();
        imagepng($result, null, 6);
        $pngData = ob_get_clean();
        imagedestroy($result);
        return $pngData ?: null;
    }

    // ── Drawing strokes (unchanged) ──

    public static function renderStrokesGd(array $strokes, int $vbW, int $vbH, int $outW, int $outH): ?string
    {
        $cW = max($outW, 10) * 2;
        $cH = max($outH, 10) * 2;
        $scaleX = $cW / max($vbW, 1);
        $scaleY = $cH / max($vbH, 1);

        $img = imagecreatetruecolor($cW, $cH);
        imagealphablending($img, false);
        imagesavealpha($img, true);
        $trans = imagecolorallocatealpha($img, 0, 0, 0, 127);
        imagefill($img, 0, 0, $trans);
        imagealphablending($img, true);

        foreach ($strokes as $stroke) {
            $d = $stroke['svgPath'] ?? '';
            $color = $stroke['color'] ?? '#000000';
            if (empty($d)) continue;

            $hex = ltrim($color, '#');
            if (strlen($hex) === 3) $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
            $r = hexdec(substr($hex, 0, 2));
            $g = hexdec(substr($hex, 2, 2));
            $b = hexdec(substr($hex, 4, 2));
            $gdColor = imagecolorallocate($img, $r, $g, $b);

            if (preg_match_all('/[ML]\s*([\d.]+)[,\s]+([\d.]+)/', $d, $m, PREG_SET_ORDER)) {
                $pts = [];
                foreach ($m as $match) {
                    $pts[] = (int)(floatval($match[1]) * $scaleX);
                    $pts[] = (int)(floatval($match[2]) * $scaleY);
                }
                if (count($pts) >= 6) {
                    imagefilledpolygon($img, $pts, $gdColor);
                } elseif (count($pts) >= 4) {
                    imageline($img, $pts[0], $pts[1], $pts[2], $pts[3], $gdColor);
                }
            }
        }

        $dst = imagecreatetruecolor($outW, $outH);
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        $trans2 = imagecolorallocatealpha($dst, 0, 0, 0, 127);
        imagefill($dst, 0, 0, $trans2);
        imagealphablending($dst, true);
        imagecopyresampled($dst, $img, 0, 0, 0, 0, $outW, $outH, $cW, $cH);
        imagedestroy($img);

        ob_start();
        imagepng($dst, null, 6);
        $result = ob_get_clean();
        imagedestroy($dst);
        return $result ?: null;
    }
}
