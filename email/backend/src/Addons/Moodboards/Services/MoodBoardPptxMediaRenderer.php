<?php

namespace Webmail\Addons\Moodboards\Services;

use PhpOffice\PhpPresentation\Shape\Hyperlink;
use PhpOffice\PhpPresentation\Shape\Media;
use PhpOffice\PhpPresentation\Shape\RichText;
use PhpOffice\PhpPresentation\Slide;
use PhpOffice\PhpPresentation\Style\Alignment;
use PhpOffice\PhpPresentation\Style\Color;
use PhpOffice\PhpPresentation\Style\Fill;

class MoodBoardPptxMediaRenderer
{
    private array $assetMap;
    private array $filePathMap;
    private string $tempDir;
    private $debugLogger;

    public function __construct(array $assetMap, string $tempDir, callable $debugLogger, array $filePathMap = [])
    {
        $this->assetMap = $assetMap;
        $this->filePathMap = $filePathMap;
        $this->tempDir = $tempDir;
        $this->debugLogger = $debugLogger;
    }

    public function renderVideo(Slide $slide, array $item, array $sd, float $x, float $y, float $w, float $h, float $scale = 1.0): void
    {
        $videoUrl = $item['url'] ?? $item['image_url'] ?? '';
        $this->debug('rVID id=' . ($item['id'] ?? 'unknown'));

        $videoPath = $this->resolveVideoMediaTempFile($videoUrl);
        if ($videoPath) {
            try {
                $this->placeEmbeddedVideo(
                    $slide,
                    $videoPath,
                    $x,
                    $y,
                    $w,
                    $h,
                    $item['title'] ?? $this->deriveVideoLabel($videoUrl)
                );
                $this->debug('  OK embedded=' . basename($videoPath));
                return;
            } catch (\Throwable $e) {
                $this->debug('  EMBED EX: ' . substr($e->getMessage(), 0, 80));
            }
        }

        $previewPath = $this->resolveVideoPreviewTempFile($item, $sd, (int)$w, (int)$h);
        if ($previewPath) {
            $this->placeLinkedImage(
                $slide,
                $previewPath,
                $x,
                $y,
                $w,
                $h,
                $videoUrl,
                $item['title'] ?? 'Play video',
                'vid_' . ($item['id'] ?? '')
            );
            $this->debug('  OK preview linked=' . (!empty($videoUrl) ? 'YES' : 'NO'));
            return;
        }

        $this->renderVideoPlaceholder(
            $slide,
            $item,
            $x,
            $y,
            $w,
            $h,
            $scale,
            $item['title'] ?? $this->deriveVideoLabel($videoUrl)
        );
    }

    public function renderYouTube(Slide $slide, array $item, array $sd, float $x, float $y, float $w, float $h, float $scale = 1.0): void
    {
        $url = $item['url'] ?? '';
        $videoId = $this->extractYouTubeId($url);
        $this->debug('rYT id=' . ($item['id'] ?? 'unknown') . ' vid=' . ($videoId ?? 'none'));

        if (!$videoId) {
            $this->renderVideoPlaceholder($slide, $item, $x, $y, $w, $h, $scale, 'No video URL', 'YouTube');
            return;
        }

        $thumbUrl = "https://img.youtube.com/vi/{$videoId}/hqdefault.jpg";
        $thumbData = @file_get_contents($thumbUrl);

        if (!$thumbData) {
            $thumbUrl = "https://img.youtube.com/vi/{$videoId}/mqdefault.jpg";
            $thumbData = @file_get_contents($thumbUrl);
        }

        if (!$thumbData) {
            $this->renderVideoPlaceholder($slide, $item, $x, $y, $w, $h, $scale, $item['title'] ?? 'YouTube Video', 'YouTube');
            return;
        }

        $withPlay = $this->overlayPlayButton($thumbData, (int)$w, (int)$h);
        $binary = $withPlay ?: $thumbData;
        $ext = $withPlay ? 'png' : 'jpg';
        $tmpFile = $this->tempDir . '/yt_' . ($item['id'] ?? uniqid('', true)) . '.' . $ext;
        @file_put_contents($tmpFile, $binary);

        if (!file_exists($tmpFile)) {
            $this->renderVideoPlaceholder($slide, $item, $x, $y, $w, $h, $scale, $item['title'] ?? 'YouTube Video', 'YouTube');
            return;
        }

        $youtubeUrl = "https://www.youtube.com/watch?v={$videoId}";

        try {
            $this->placeLinkedImage(
                $slide,
                $tmpFile,
                $x,
                $y,
                $w,
                $h,
                $youtubeUrl,
                $item['title'] ?? 'Play on YouTube',
                'yt_' . ($item['id'] ?? '')
            );
            $this->debug('  OK linked=' . $youtubeUrl);
        } catch (\Throwable $e) {
            $this->debug('  EX: ' . substr($e->getMessage(), 0, 50));
            $this->renderVideoPlaceholder($slide, $item, $x, $y, $w, $h, $scale, $item['title'] ?? 'YouTube Video', 'YouTube');
        }
    }

    private function resolveVideoPreviewTempFile(array $item, array $sd, int $targetW, int $targetH): ?string
    {
        $candidates = array_values(array_filter([
            $sd['poster'] ?? null,
            $item['thumbnail_url'] ?? null,
            $item['preview_image_url'] ?? null,
            $item['image_url'] ?? null,
        ], fn($value) => is_string($value) && trim($value) !== ''));

        foreach ($candidates as $index => $candidate) {
            $path = $this->imageAssetToTempFile(
                $candidate,
                'video_preview_' . ($item['id'] ?? uniqid('', true)) . '_' . $index,
                $targetW,
                $targetH,
                true
            );

            if ($path) {
                return $path;
            }
        }

        return null;
    }

    private function resolveVideoMediaTempFile(string $source): ?string
    {
        if (empty($source)) {
            return null;
        }

        $diskPath = $this->resolveFilePathOnDisk($source);
        if ($diskPath) {
            $ext = $this->resolveEmbeddableVideoExtension(
                @mime_content_type($diskPath) ?: '',
                $diskPath
            );
            if ($ext) {
                $dest = $this->tempDir . '/video_' . md5($source) . '.' . $ext;
                if (@copy($diskPath, $dest)) {
                    $this->debug('  disk copy OK ' . basename($dest));
                    return $dest;
                }
            }
        }

        $data = $this->resolveAssetData($source);
        if (!$data) {
            $this->debug('  no embeddable asset');
            return null;
        }

        $b64 = $this->extractBase64($data);
        if (!$b64 || !str_starts_with($b64['mime'], 'video/')) {
            $this->debug('  non-video asset');
            return null;
        }

        $extension = $this->resolveEmbeddableVideoExtension($b64['mime'], $source);
        if ($extension === null) {
            $this->debug('  unsupported mime=' . $b64['mime']);
            return null;
        }

        $binary = base64_decode($b64['base64'], true);
        if (!$binary) {
            $this->debug('  decode fail');
            return null;
        }

        $path = $this->tempDir . '/video_' . md5($source) . '.' . $extension;
        $written = @file_put_contents($path, $binary);

        return ($written && file_exists($path)) ? $path : null;
    }

    private function resolveFilePathOnDisk(string $url): ?string
    {
        if (isset($this->filePathMap[$url]) && file_exists($this->filePathMap[$url])) {
            return $this->filePathMap[$url];
        }

        $noSlash = ltrim($url, '/');
        if (isset($this->filePathMap[$noSlash]) && file_exists($this->filePathMap[$noSlash])) {
            return $this->filePathMap[$noSlash];
        }

        return null;
    }

    private function resolveEmbeddableVideoExtension(string $mime, string $source): ?string
    {
        $mimeMap = [
            'video/mp4' => 'mp4',
            'video/ogg' => 'ogv',
            'video/ogv' => 'ogv',
            'video/x-ms-wmv' => 'wmv',
            'video/wmv' => 'wmv',
        ];

        if (isset($mimeMap[$mime])) {
            return $mimeMap[$mime];
        }

        $path = parse_url($source, PHP_URL_PATH) ?: '';
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return in_array($extension, ['mp4', 'ogv', 'wmv'], true) ? $extension : null;
    }

    private function imageAssetToTempFile(string $source, string $prefix, int $targetW, int $targetH, bool $withPlayOverlay = false): ?string
    {
        $data = $this->resolveAssetData($source);
        if (!$data) {
            return null;
        }

        $b64 = $this->extractBase64($data);
        if (!$b64 || !str_starts_with($b64['mime'], 'image/')) {
            return null;
        }

        $binary = base64_decode($b64['base64'], true);
        if (!$binary) {
            return null;
        }

        if ($withPlayOverlay) {
            $overlay = $this->overlayPlayButton($binary, max($targetW, 120), max($targetH, 68));
            if ($overlay) {
                $binary = $overlay;
                $mime = 'image/png';
            } else {
                $mime = $b64['mime'];
            }
        } else {
            $mime = $b64['mime'];
        }

        $ext = match ($mime) {
            'image/png' => 'png',
            'image/gif' => 'gif',
            default => 'jpg',
        };

        $path = $this->tempDir . '/' . $prefix . '.' . $ext;
        $written = @file_put_contents($path, $binary);

        return ($written && file_exists($path)) ? $path : null;
    }

    private function placeEmbeddedVideo(
        Slide $slide,
        string $videoPath,
        float $x,
        float $y,
        float $w,
        float $h,
        string $name
    ): void {
        $media = new Media();
        $media->setPath($videoPath, false);
        $media->setName($name);
        $media->setWidth(max(1, (int)$w));
        $media->setHeight(max(1, (int)$h));
        $media->setOffsetX((int)$x);
        $media->setOffsetY((int)$y);
        $slide->addShape($media);
    }

    private function placeLinkedImage(
        Slide $slide,
        string $imagePath,
        float $x,
        float $y,
        float $w,
        float $h,
        string $url,
        string $tooltip,
        string $name
    ): void {
        $shape = $slide->createDrawingShape();
        $shape->setResizeProportional(false);
        $shape->setWidth(max(1, (int)$w));
        $shape->setHeight(max(1, (int)$h));
        $shape->setPath($imagePath, true);
        $shape->setOffsetX((int)$x);
        $shape->setOffsetY((int)$y);
        $shape->setName($name);

        if (!empty($url)) {
            $shape->setHyperlink(new Hyperlink($url));
            $shape->getHyperlink()->setTooltip($tooltip);
        }
    }

    private function renderVideoPlaceholder(
        Slide $slide,
        array $item,
        float $x,
        float $y,
        float $w,
        float $h,
        float $scale,
        string $label,
        string $badge = 'Video'
    ): void {
        $shape = $slide->createRichTextShape();
        $shape->setOffsetX((int)$x);
        $shape->setOffsetY((int)$y);
        $shape->setWidth(max(1, (int)$w));
        $shape->setHeight(max(1, (int)$h));
        $shape->setAutoFit(RichText::AUTOFIT_SHAPE);
        $shape->getActiveParagraph()->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $shape->getActiveParagraph()->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);

        $fill = $shape->getFill();
        $fill->setFillType(Fill::FILL_SOLID);
        $fill->setStartColor(new Color('FF111827'));

        $badgeRun = $shape->createTextRun($badge);
        $badgeRun->getFont()->setSize(max(5, (int)(10 * $scale)));
        $badgeRun->getFont()->setColor(new Color('FFA855F7'));
        $badgeRun->getFont()->setBold(true);

        $shape->createBreak();

        $titleRun = $shape->createTextRun($label ?: 'Video');
        $titleRun->getFont()->setSize(max(5, (int)(8 * $scale)));
        $titleRun->getFont()->setColor(new Color('FFFFFFFF'));

        $url = $item['url'] ?? $item['image_url'] ?? '';
        if (!empty($url) && preg_match('#^https?://#i', $url)) {
            $shape->setHyperlink(new Hyperlink($url));
            $shape->getHyperlink()->setTooltip($item['title'] ?? 'Open video');
        }
    }

    private function deriveVideoLabel(string $url): string
    {
        if (empty($url)) {
            return 'Video';
        }

        $path = parse_url($url, PHP_URL_PATH) ?: '';
        $filename = basename($path);

        return $filename !== '' ? $filename : 'Video';
    }

    private function resolveAssetData(string $url): ?string
    {
        if (empty($url)) {
            return null;
        }

        if (str_starts_with($url, 'data:')) {
            return $url;
        }

        if (isset($this->assetMap[$url])) {
            return $this->assetMap[$url];
        }

        $noSlash = ltrim($url, '/');
        if (isset($this->assetMap[$noSlash])) {
            return $this->assetMap[$noSlash];
        }

        return null;
    }

    private function extractBase64(string $dataUri): ?array
    {
        if (!str_starts_with($dataUri, 'data:')) {
            return null;
        }

        $commaPos = strpos($dataUri, ',');
        if ($commaPos === false) {
            return null;
        }

        $header = substr($dataUri, 5, $commaPos - 5);
        $base64Data = substr($dataUri, $commaPos + 1);
        $semiPos = strpos($header, ';');
        $mime = $semiPos !== false ? substr($header, 0, $semiPos) : $header;

        if (empty($base64Data)) {
            return null;
        }

        return [
            'mime' => $mime,
            'base64' => $base64Data,
        ];
    }

    private function extractYouTubeId(string $url): ?string
    {
        if (empty($url)) {
            return null;
        }

        if (preg_match('/[?&]v=([a-zA-Z0-9_-]{11})/', $url, $match)) {
            return $match[1];
        }
        if (preg_match('/youtu\.be\/([a-zA-Z0-9_-]{11})/', $url, $match)) {
            return $match[1];
        }
        if (preg_match('/youtube\.com\/embed\/([a-zA-Z0-9_-]{11})/', $url, $match)) {
            return $match[1];
        }
        if (preg_match('/youtube\.com\/shorts\/([a-zA-Z0-9_-]{11})/', $url, $match)) {
            return $match[1];
        }

        return null;
    }

    private function overlayPlayButton(string $thumbData, int $targetW, int $targetH): ?string
    {
        $src = @imagecreatefromstring($thumbData);
        if (!$src) {
            return null;
        }

        $srcW = imagesx($src);
        $srcH = imagesy($src);
        $outW = max($targetW, 120);
        $outH = max($targetH, 68);
        $ss = 4;
        $bigW = $outW * $ss;
        $bigH = $outH * $ss;

        $big = imagecreatetruecolor($bigW, $bigH);
        imagealphablending($big, false);
        imagesavealpha($big, true);
        $transparent = imagecolorallocatealpha($big, 0, 0, 0, 127);
        imagefill($big, 0, 0, $transparent);
        imagealphablending($big, true);

        imagecopyresampled($big, $src, 0, 0, 0, 0, $bigW, $bigH, $srcW, $srcH);
        imagedestroy($src);

        $vignette = imagecolorallocatealpha($big, 0, 0, 0, 90);
        imagefilledrectangle($big, 0, 0, $bigW - 1, $bigH - 1, $vignette);

        $cx = (int)($bigW / 2);
        $cy = (int)($bigH / 2);
        $btnH = max(80, min(280, (int)($bigH * 0.22)));
        $btnW = (int)($btnH * 1.42);
        $btnR = (int)($btnH * 0.28);

        $redBg = imagecolorallocatealpha($big, 204, 0, 0, 10);
        $this->drawRoundedRect(
            $big,
            $cx - (int)($btnW / 2),
            $cy - (int)($btnH / 2),
            $cx + (int)($btnW / 2),
            $cy + (int)($btnH / 2),
            $btnR,
            $redBg
        );

        $white = imagecolorallocate($big, 255, 255, 255);
        $triH = (int)($btnH * 0.55);
        $triW = (int)($triH * 0.85);
        $triCx = $cx + (int)($triW * 0.10);
        $points = [
            $triCx - (int)($triW * 0.45), $cy - $triH,
            $triCx - (int)($triW * 0.45), $cy + $triH,
            $triCx + (int)($triW * 0.55), $cy,
        ];
        imagefilledpolygon($big, $points, 3, $white);

        $dst = imagecreatetruecolor($outW, $outH);
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        imagecopyresampled($dst, $big, 0, 0, 0, 0, $outW, $outH, $bigW, $bigH);
        imagedestroy($big);

        ob_start();
        imagepng($dst);
        $result = ob_get_clean();
        imagedestroy($dst);

        return $result ?: null;
    }

    private function drawRoundedRect($img, int $x1, int $y1, int $x2, int $y2, int $r, $color): void
    {
        $r = min($r, (int)(($x2 - $x1) / 2), (int)(($y2 - $y1) / 2));
        imagefilledrectangle($img, $x1 + $r, $y1, $x2 - $r, $y2, $color);
        imagefilledrectangle($img, $x1, $y1 + $r, $x2, $y2 - $r, $color);
        imagefilledellipse($img, $x1 + $r, $y1 + $r, $r * 2, $r * 2, $color);
        imagefilledellipse($img, $x2 - $r, $y1 + $r, $r * 2, $r * 2, $color);
        imagefilledellipse($img, $x1 + $r, $y2 - $r, $r * 2, $r * 2, $color);
        imagefilledellipse($img, $x2 - $r, $y2 - $r, $r * 2, $r * 2, $color);
    }

    private function debug(string $message): void
    {
        ($this->debugLogger)($message);
    }
}
