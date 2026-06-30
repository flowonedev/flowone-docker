<?php

namespace Webmail\Addons\Moodboards\Services;

use PhpOffice\PhpPresentation\PhpPresentation;
use PhpOffice\PhpPresentation\IOFactory;
use PhpOffice\PhpPresentation\DocumentLayout;
use PhpOffice\PhpPresentation\Style\Color;
use PhpOffice\PhpPresentation\Style\Alignment;
use PhpOffice\PhpPresentation\Style\Fill;
use PhpOffice\PhpPresentation\Style\Border;
use PhpOffice\PhpPresentation\Shape\RichText;
use PhpOffice\PhpPresentation\Slide;
use PhpOffice\PhpPresentation\Slide\Background\Color as BackgroundColor;

class MoodBoardPptxService
{
    private const SLIDE_W = 960;
    private const SLIDE_H = 540;

    private array $assetMap;
    private array $itemMap;
    private string $tempDir;
    private array $debugLog = [];
    private MoodBoardPptxMediaRenderer $mediaRenderer;

    private function dbg(string $msg): void
    {
        $this->debugLog[] = $msg;
    }

    public function getDebugInfo(): string
    {
        return implode("\n", $this->debugLog);
    }

    public function generate(array $board, array $assetMap, array $filePathMap = []): string
    {
        $this->assetMap = $assetMap;
        $this->tempDir = sys_get_temp_dir() . '/mood_pptx_' . uniqid();
        @mkdir($this->tempDir, 0755, true);
        $this->mediaRenderer = new MoodBoardPptxMediaRenderer(
            $assetMap,
            $this->tempDir,
            fn(string $msg) => $this->dbg($msg),
            $filePathMap
        );
        $this->dbg("assetMap keys: " . count($assetMap));

        $items = $board['items'] ?? [];
        $connections = $board['connections'] ?? [];

        $this->itemMap = [];
        foreach ($items as $item) {
            $this->itemMap[$item['id']] = $item;
        }

        $imageItems = array_filter($items, fn($i) => ($i['type'] ?? '') === 'image');
        $this->dbg("total items: " . count($items) . ", image items: " . count($imageItems));
        foreach ($imageItems as $img) {
            $url = $img['image_url'] ?? $img['thumbnail_url'] ?? '(none)';
            $inMap = isset($assetMap[$url]) || isset($assetMap[ltrim($url, '/')]);
            $this->dbg("  IMG id={$img['id']} url=" . substr($url, 0, 80) . " inMap=" . ($inMap ? 'YES' : 'NO'));
        }

        $slideItems = array_values(array_filter($items, fn($i) => ($i['type'] ?? '') === 'slide'));
        usort($slideItems, fn($a, $b) => ($a['slide_order'] ?? 9999) - ($b['slide_order'] ?? 9999));

        $nonSlideItems = array_filter($items, fn($i) => ($i['type'] ?? '') !== 'slide');

        $pres = new PhpPresentation();
        $pres->getLayout()->setDocumentLayout(DocumentLayout::LAYOUT_SCREEN_16X9);

        $pres->removeSlideByIndex(0);

        $bgColor = $this->normalizeHex($board['background_color'] ?? '#f5f5f5');

        if (empty($slideItems)) {
            $slide = $pres->createSlide();
            $this->applySlideBackground($slide, $bgColor);
            $this->renderAllItemsOnSlide($slide, $nonSlideItems, $connections);
        } else {
            foreach ($slideItems as $si) {
                $slide = $pres->createSlide();
                $this->applySlideBackground($slide, $bgColor);
                $this->renderSlide($slide, $si, $nonSlideItems, $connections);
            }
        }

        $outputPath = $this->tempDir . '/export.pptx';
        $writer = IOFactory::createWriter($pres, 'PowerPoint2007');
        $writer->save($outputPath);

        return $outputPath;
    }

    private function addDebugBox(Slide $slide): void
    {
        $text = implode("\n", array_slice($this->debugLog, 0, 50));
        if (empty($text)) return;

        $shape = $slide->createRichTextShape();
        $shape->setOffsetX(5);
        $shape->setOffsetY(self::SLIDE_H - 160);
        $shape->setWidth(self::SLIDE_W - 10);
        $shape->setHeight(155);
        $shape->setAutoFit(RichText::AUTOFIT_SHAPE);

        $fill = $shape->getFill();
        $fill->setFillType(Fill::FILL_SOLID);
        $fill->setStartColor(new Color('DD000000'));

        $shape->setInsetTop(4);
        $shape->setInsetLeft(6);

        $run = $shape->createTextRun($text);
        $font = $run->getFont();
        $font->setSize(5);
        $font->setName('Consolas');
        $font->setColor(new Color('FF00FF00'));
    }

    public function cleanup(): void
    {
        if (!empty($this->tempDir) && is_dir($this->tempDir)) {
            $files = glob($this->tempDir . '/*');
            foreach ($files as $f) {
                @unlink($f);
            }
            @rmdir($this->tempDir);
        }
    }

    // ── Slide rendering ──

    private function renderSlide(Slide $slide, array $slideItem, array $nonSlideItems, array $connections): void
    {
        $sx = (float)($slideItem['pos_x'] ?? 0);
        $sy = (float)($slideItem['pos_y'] ?? 0);
        $sw = (float)($slideItem['width'] ?? self::SLIDE_W);
        $sh = (float)($slideItem['height'] ?? self::SLIDE_H);

        $scaleX = self::SLIDE_W / max($sw, 1);
        $scaleY = self::SLIDE_H / max($sh, 1);
        $scale = min($scaleX, $scaleY);

        $offsetX = (self::SLIDE_W - $sw * $scale) / 2;
        $offsetY = (self::SLIDE_H - $sh * $scale) / 2;

        $visible = [];
        foreach ($nonSlideItems as $item) {
            if (($item['style_data']['_hidden'] ?? false)) continue;
            $ix = (float)($item['pos_x'] ?? 0);
            $iy = (float)($item['pos_y'] ?? 0);
            $iw = (float)($item['width'] ?? 0);
            $ih = (float)($item['height'] ?? 0);
            if ($iw < 1 && $ih < 1) { $iw = 100; $ih = 100; }
            elseif ($iw < 1) { $iw = $ih; }
            elseif ($ih < 1) { $ih = $iw; }

            if ($ix + $iw < $sx || $ix > $sx + $sw) continue;
            if ($iy + $ih < $sy || $iy > $sy + $sh) continue;

            $visible[] = $item;
        }

        usort($visible, fn($a, $b) => ($a['z_index'] ?? 0) - ($b['z_index'] ?? 0));

        foreach ($visible as $item) {
            $this->renderItem($slide, $item, $sx, $sy, $scale, $offsetX, $offsetY);
        }

        $this->renderConnections($slide, $connections, $visible, $sx, $sy, $scale, $offsetX, $offsetY);
    }

    private function renderAllItemsOnSlide(Slide $slide, array $items, array $connections): void
    {
        $filtered = array_filter($items, fn($i) => !($i['style_data']['_hidden'] ?? false));
        if (empty($filtered)) return;

        $minX = PHP_FLOAT_MAX; $minY = PHP_FLOAT_MAX;
        $maxX = PHP_FLOAT_MIN; $maxY = PHP_FLOAT_MIN;
        foreach ($filtered as $item) {
            $x = (float)($item['pos_x'] ?? 0);
            $y = (float)($item['pos_y'] ?? 0);
            $w = (float)($item['width'] ?? 0);
            $h = (float)($item['height'] ?? 0);
            if ($w < 1 && $h < 1) { $w = 100; $h = 100; }
            elseif ($w < 1) { $w = $h; }
            elseif ($h < 1) { $h = $w; }
            $minX = min($minX, $x);
            $minY = min($minY, $y);
            $maxX = max($maxX, $x + $w);
            $maxY = max($maxY, $y + $h);
        }

        $canvasW = $maxX - $minX;
        $canvasH = $maxY - $minY;
        $padding = 40;
        $scaleX = (self::SLIDE_W - $padding * 2) / max($canvasW, 1);
        $scaleY = (self::SLIDE_H - $padding * 2) / max($canvasH, 1);
        $scale = min($scaleX, $scaleY, 1.0);

        $offsetX = (self::SLIDE_W - $canvasW * $scale) / 2;
        $offsetY = (self::SLIDE_H - $canvasH * $scale) / 2;

        usort($filtered, fn($a, $b) => ($a['z_index'] ?? 0) - ($b['z_index'] ?? 0));
        $filtered = array_values($filtered);

        foreach ($filtered as $item) {
            $this->renderItem($slide, $item, $minX, $minY, $scale, $offsetX, $offsetY);
        }

        $this->renderConnections($slide, $connections, $filtered, $minX, $minY, $scale, $offsetX, $offsetY);
    }

    // ── Item renderer ──

    private function renderItem(Slide $slide, array $item, float $originX, float $originY, float $scale, float $offsetX, float $offsetY): void
    {
        $rawW = (float)($item['width'] ?? 0);
        $rawH = (float)($item['height'] ?? 0);
        if ($rawW < 1 && $rawH < 1) { $rawW = 100; $rawH = 100; }
        elseif ($rawW < 1) { $rawW = $rawH; }
        elseif ($rawH < 1) { $rawH = $rawW; }

        $px = ((float)($item['pos_x'] ?? 0) - $originX) * $scale + $offsetX;
        $py = ((float)($item['pos_y'] ?? 0) - $originY) * $scale + $offsetY;
        $pw = $rawW * $scale;
        $ph = $rawH * $scale;

        if ($px + $pw < 0 || $py + $ph < 0 || $px > self::SLIDE_W || $py > self::SLIDE_H) return;

        if ($px < 0) { $pw += $px; $px = 0; }
        if ($py < 0) { $ph += $py; $py = 0; }
        if ($px + $pw > self::SLIDE_W) { $pw = self::SLIDE_W - $px; }
        if ($py + $ph > self::SLIDE_H) { $ph = self::SLIDE_H - $py; }
        if ($pw < 1 || $ph < 1) return;

        $type = $item['type'] ?? 'unknown';
        $sd = $item['style_data'] ?? [];
        if (is_string($sd)) {
            $sd = json_decode($sd, true) ?? [];
        }

        $shapeCountBefore = count($slide->getShapeCollection());

        switch ($type) {
            case 'image':
                $this->renderImage($slide, $item, $sd, $px, $py, $pw, $ph, $scale);
                break;
            case 'text':
                $this->renderText($slide, $item, $sd, $px, $py, $pw, $ph, $scale);
                break;
            case 'note':
                $this->renderNote($slide, $item, $sd, $px, $py, $pw, $ph, $scale);
                break;
            case 'shape':
                $this->renderShape($slide, $item, $sd, $px, $py, $pw, $ph, $scale);
                break;
            case 'color_swatch':
                $this->renderColorSwatch($slide, $item, $sd, $px, $py, $pw, $ph, $scale);
                break;
            case 'todo_list':
                $this->renderTodoList($slide, $item, $sd, $px, $py, $pw, $ph, $scale);
                break;
            case 'link':
                $this->renderLink($slide, $item, $sd, $px, $py, $pw, $ph, $scale);
                break;
            case 'file':
                $this->renderFile($slide, $item, $sd, $px, $py, $pw, $ph, $scale);
                break;
            case 'image_set':
                $this->renderImageSet($slide, $item, $sd, $px, $py, $pw, $ph, $scale);
                break;
            case 'frame':
            case 'column':
                $this->renderFrame($slide, $item, $sd, $px, $py, $pw, $ph, $scale);
                break;
            case 'table':
                $this->renderTable($slide, $item, $sd, $px, $py, $pw, $ph, $scale);
                break;
            case 'calendar_event':
                $this->renderCalendarEvent($slide, $item, $sd, $px, $py, $pw, $ph, $scale);
                break;
            case 'video':
                $this->mediaRenderer->renderVideo($slide, $item, $sd, $px, $py, $pw, $ph, $scale);
                break;
            case 'youtube':
                $this->mediaRenderer->renderYouTube($slide, $item, $sd, $px, $py, $pw, $ph, $scale);
                break;
            case 'drawing':
                $this->renderDrawing($slide, $item, $sd, $px, $py, $pw, $ph, $scale);
                break;
            case 'pen_shape':
                $this->renderPenShape($slide, $item, $sd, $px, $py, $pw, $ph, $scale);
                break;
            default:
                $this->renderGeneric($slide, $item, $sd, $px, $py, $pw, $ph, $scale);
                break;
        }

        $rotation = (float)($item['rotation'] ?? 0);
        if ($rotation != 0) {
            $shapes = $slide->getShapeCollection();
            $shapeCountAfter = count($shapes);
            for ($si = $shapeCountBefore; $si < $shapeCountAfter; $si++) {
                $shapes[$si]->setRotation($rotation);
            }
        }
    }

    // ── Individual item renderers ──

    private function renderImage(Slide $slide, array $item, array $sd, float $x, float $y, float $w, float $h, float $scale = 1.0): void
    {
        $url = $item['image_url'] ?? $item['thumbnail_url'] ?? '';
        $this->dbg("rI id={$item['id']} " . substr($url, -30));

        $data = $this->resolveAssetData($url);
        if (!$data) {
            $this->dbg("  MISS");
            $this->renderImagePlaceholder($slide, $item, $x, $y, $w, $h, 'asset miss', $scale);
            return;
        }

        $b64 = $this->extractBase64($data);
        if (!$b64) {
            $this->dbg("  BAD URI");
            $this->renderImagePlaceholder($slide, $item, $x, $y, $w, $h, 'bad data', $scale);
            return;
        }

        $binary = base64_decode($b64['base64'], true);
        if (!$binary || strlen($binary) === 0) {
            $this->dbg("  DECODE FAIL");
            $this->renderImagePlaceholder($slide, $item, $x, $y, $w, $h, 'decode fail', $scale);
            return;
        }

        $mime = $b64['mime'];
        $needsConvert = in_array($mime, ['image/webp', 'image/avif', 'image/bmp', 'image/svg+xml']);
        if ($needsConvert) {
            $converted = $this->convertToPng($binary);
            if ($converted) {
                $binary = $converted;
                $mime = 'image/png';
            }
        }

        $scaled = $this->downscaleIfNeeded($binary);
        if ($scaled) {
            $binary = $scaled;
            $mime = 'image/jpeg';
        }

        $borderRadius = (int)($sd['border_radius'] ?? 8);
        if ($borderRadius > 0) {
            $rounded = $this->applyRoundedCorners($binary, $borderRadius, (int)$w, (int)$h);
            if ($rounded) {
                $binary = $rounded;
                $mime = 'image/png';
            }
        }

        $ext = $mime === 'image/png' ? 'png' : ($mime === 'image/gif' ? 'gif' : 'jpg');
        $tmpFile = $this->tempDir . '/img_' . ($item['id'] ?? uniqid()) . '.' . $ext;
        $written = @file_put_contents($tmpFile, $binary);

        if (!$written || !file_exists($tmpFile)) {
            $this->dbg("  WRITE FAIL");
            $this->renderImagePlaceholder($slide, $item, $x, $y, $w, $h, 'write fail', $scale);
            return;
        }

        $this->dbg("  file={$ext} {$written}b");

        try {
            $imgSize = @getimagesizefromstring($binary);
            $drawW = (int)$w;
            $drawH = (int)$h;
            $drawX = (int)$x;
            $drawY = (int)$y;

            if ($imgSize && $imgSize[0] > 0 && $imgSize[1] > 0) {
                $natW = $imgSize[0];
                $natH = $imgSize[1];
                $fitScale = min($w / $natW, $h / $natH);
                $drawW = max(1, (int)($natW * $fitScale));
                $drawH = max(1, (int)($natH * $fitScale));
                $drawX = (int)($x + ($w - $drawW) / 2);
                $drawY = (int)($y + ($h - $drawH) / 2);
            }

            $shape = $slide->createDrawingShape();
            $shape->setResizeProportional(false);
            $shape->setWidth($drawW);
            $shape->setHeight($drawH);
            $shape->setPath($tmpFile, true);
            $shape->setName('img_' . ($item['id'] ?? ''));
            $shape->setOffsetX($drawX);
            $shape->setOffsetY($drawY);

            $this->dbg("  OK {$drawW}x{$drawH} (box {$w}x{$h})");
        } catch (\Throwable $e) {
            $this->dbg("  EX: " . substr($e->getMessage(), 0, 50));
            $this->renderImagePlaceholder($slide, $item, $x, $y, $w, $h, 'err: ' . substr($e->getMessage(), 0, 40), $scale);
        }
    }

    private function renderImagePlaceholder(Slide $slide, array $item, float $x, float $y, float $w, float $h, string $reason, float $scale = 1.0): void
    {
        $shape = $slide->createRichTextShape();
        $shape->setOffsetX((int)$x);
        $shape->setOffsetY((int)$y);
        $shape->setWidth((int)$w);
        $shape->setHeight((int)$h);

        $fill = $shape->getFill();
        $fill->setFillType(Fill::FILL_SOLID);
        $fill->setStartColor(new Color('FFE5E7EB'));

        $shape->getActiveParagraph()->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $shape->getActiveParagraph()->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);

        $label = ($item['title'] ?? 'Image') . "\n[" . $reason . "]";
        $run = $shape->createTextRun($label);
        $font = $run->getFont();
        $font->setSize(max(5, (int)(7 * $scale)));
        $font->setColor(new Color('FFEF4444'));
    }

    private function renderText(Slide $slide, array $item, array $sd, float $x, float $y, float $w, float $h, float $scale = 1.0): void
    {
        $rawHtml = $item['content'] ?? '';
        $fontSize = (int)($sd['font_size'] ?? 16);
        $scaledSize = max(5, (int)round($fontSize * 0.75 * $scale));
        $defaultColor = $this->normalizeHex($sd['text_color'] ?? '#1f2937');
        $fontWeight = $sd['font_weight'] ?? 'normal';
        $fontFamily = $sd['font_family'] ?? 'Inter';
        $isBold = in_array($fontWeight, ['bold', '700', '600', '800', '900']);
        $lineHeight = (float)($sd['line_height'] ?? 1.0);
        $lineSpacing = max(25, (int)($lineHeight * 100));
        $letterSpacing = (float)($sd['letter_spacing'] ?? 0);
        $textPadding = (float)($sd['text_padding'] ?? 12) * $scale;
        $paddingEmu = (int)($textPadding * 12700);

        $segments = $this->parseHtmlSegments($rawHtml, $defaultColor, $isBold);

        $textTransform = $sd['text_transform'] ?? 'none';
        if ($textTransform !== 'none') {
            foreach ($segments as &$seg) {
                if (!$seg['break'] && $seg['text'] !== '') {
                    $seg['text'] = $this->applyTextTransform($seg['text'], $textTransform);
                }
            }
            unset($seg);
        }

        $innerW = max(10, (int)$w - (int)($textPadding * 2));
        $estimatedH = $this->estimateTextHeight($segments, $scaledSize, $innerW, $lineHeight);
        $finalH = max($estimatedH + (int)($textPadding * 2), (int)$h, 10);

        $shape = $slide->createRichTextShape();
        $shape->setOffsetX((int)($x));
        $shape->setOffsetY((int)($y));
        $shape->setWidth((int)($w));
        $shape->setHeight(max(10, (int)$finalH));
        $shape->setAutoFit(RichText::AUTOFIT_NORMAL);
        $shape->setVerticalOverflow(RichText::OVERFLOW_OVERFLOW);
        $shape->setInsetTop($paddingEmu);
        $shape->setInsetBottom($paddingEmu);
        $shape->setInsetLeft($paddingEmu);
        $shape->setInsetRight($paddingEmu);

        $align = $this->mapTextAlign($sd['text_align'] ?? 'left');
        $shape->getActiveParagraph()->getAlignment()->setHorizontal($align);
        $shape->getActiveParagraph()->setLineSpacing($lineSpacing);

        foreach ($segments as $seg) {
            if ($seg['break']) {
                $para = $shape->createParagraph();
                $para->getAlignment()->setHorizontal($align);
                $para->setLineSpacing($lineSpacing);
                continue;
            }
            $run = $shape->createTextRun($seg['text']);
            $font = $run->getFont();
            $font->setSize($scaledSize);
            $segColor = $this->normalizeHex($seg['color']);
            $font->setColor(new Color('FF' . ltrim($segColor, '#')));
            $font->setName($this->mapFontFamily($fontFamily));
            if ($seg['bold']) $font->setBold(true);
            if ($seg['italic'] ?? false) $font->setItalic(true);
            if ($seg['underline'] ?? false) $font->setUnderline(\PhpOffice\PhpPresentation\Style\Font::UNDERLINE_SINGLE);
            if ($letterSpacing != 0) {
                $font->setCharacterSpacing($letterSpacing * $scale);
            }
        }
    }

    private function parseHtmlSegments(string $html, string $defaultColor, bool $defaultBold): array
    {
        if (empty(trim($html))) return [];

        $segments = [];
        $html = str_replace(['<br>', '<br/>', '<br />', '</p><p>', '</div><div>'], "\n", $html);

        $parts = preg_split('/(\n)/', strip_tags($html, '<span><b><strong><i><em><u>'), -1, PREG_SPLIT_DELIM_CAPTURE);

        if (empty($parts) || (count($parts) === 1 && trim($parts[0]) === '')) {
            $plainText = strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $html));
            if (empty(trim($plainText))) return [];
            return [['text' => $plainText, 'color' => $defaultColor, 'bold' => $defaultBold, 'italic' => false, 'underline' => false, 'break' => false]];
        }

        $inlineColor = $defaultColor;
        if (preg_match('/color\s*:\s*([#\w]+)/i', $html, $cm)) {
            $inlineColor = $cm[1];
        }
        if (preg_match('/style\s*=\s*["\'][^"\']*color\s*:\s*([^;"\']+)/i', $html, $cm)) {
            $inlineColor = trim($cm[1]);
        }

        $isBold = $defaultBold || (bool)preg_match('/<(b|strong)\b/i', $html);
        $isItalic = (bool)preg_match('/<(i|em)\b/i', $html);
        $isUnderline = (bool)preg_match('/<u\b/i', $html);

        $plainText = strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $html));
        $lines = explode("\n", $plainText);

        foreach ($lines as $li => $line) {
            if ($li > 0) {
                $segments[] = ['text' => '', 'color' => $inlineColor, 'bold' => $isBold, 'italic' => $isItalic, 'underline' => $isUnderline, 'break' => true];
            }
            if ($line !== '') {
                $segments[] = ['text' => $line, 'color' => $inlineColor, 'bold' => $isBold, 'italic' => $isItalic, 'underline' => $isUnderline, 'break' => false];
            }
        }

        return $segments;
    }

    private function renderNote(Slide $slide, array $item, array $sd, float $x, float $y, float $w, float $h, float $scale = 1.0): void
    {
        $bgColor = $this->normalizeHex($item['color'] ?? '#fef3c7');
        $textColor = $this->getContrastColor($bgColor);

        $shape = $slide->createRichTextShape();
        $shape->setOffsetX((int)($x));
        $shape->setOffsetY((int)($y));
        $shape->setWidth((int)($w));
        $shape->setHeight((int)($h));
        $shape->setAutoFit(RichText::AUTOFIT_SHAPE);

        $fill = $shape->getFill();
        $fill->setFillType(Fill::FILL_SOLID);
        $fill->setStartColor(new Color('FF' . ltrim($bgColor, '#')));

        $shape->setInsetTop(max(1, (int)(8 * $scale)));
        $shape->setInsetLeft(max(1, (int)(10 * $scale)));
        $shape->setInsetRight(max(1, (int)(10 * $scale)));
        $shape->setInsetBottom(max(1, (int)(8 * $scale)));

        if (!empty($item['title'])) {
            $run = $shape->createTextRun($item['title']);
            $font = $run->getFont();
            $font->setSize(max(5, (int)(11 * $scale)));
            $font->setBold(true);
            $font->setColor(new Color('FF' . ltrim($textColor, '#')));
            $shape->createBreak();
        }

        $content = strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $item['content'] ?? ''));
        if ($content) {
            $run = $shape->createTextRun($content);
            $font = $run->getFont();
            $font->setSize(max(5, (int)(9 * $scale)));
            $font->setColor(new Color('FF' . ltrim($textColor, '#')));
        }
    }

    private function renderShape(Slide $slide, array $item, array $sd, float $x, float $y, float $w, float $h, float $scale = 1.0): void
    {
        $bgColor = $this->normalizeHex(
            $sd['shape_fill'] ?? $item['color'] ?? $sd['background_color'] ?? '#6366f1'
        );

        $maskUrl = $sd['mask_image_url'] ?? null;
        if ($maskUrl) {
            $tmpFile = $this->assetToTempFile($maskUrl, 'mask_' . ($item['id'] ?? ''));
            if ($tmpFile) {
                try {
                    $shape = $slide->createDrawingShape();
                    $shape->setResizeProportional(false);
                    $shape->setWidth(max(1, (int)$w));
                    $shape->setHeight(max(1, (int)$h));
                    $shape->setPath($tmpFile, true);
                    $shape->setOffsetX((int)$x);
                    $shape->setOffsetY((int)$y);
                    return;
                } catch (\Throwable $e) {}
            }
        }

        $shapeType = $sd['shape_type'] ?? 'rectangle';
        $hasRadius = ($sd['shape_border_radius'] ?? 0) > 0;
        $isCircle = in_array($shapeType, ['circle', 'ellipse']);

        $autoShapeType = null;
        if ($isCircle) {
            $autoShapeType = \PhpOffice\PhpPresentation\Shape\AutoShape::TYPE_OVAL;
        } elseif ($hasRadius) {
            $autoShapeType = \PhpOffice\PhpPresentation\Shape\AutoShape::TYPE_ROUNDED_RECTANGLE;
        }

        $textContent = strip_tags($item['content'] ?? $item['title'] ?? '');
        $shapeTextTransform = $sd['shape_text_transform'] ?? 'none';
        if ($shapeTextTransform !== 'none' && !empty($textContent)) {
            $textContent = $this->applyTextTransform($textContent, $shapeTextTransform);
        }

        if ($autoShapeType !== null) {
            $shape = new \PhpOffice\PhpPresentation\Shape\AutoShape();
            $shape->setType($autoShapeType);
            if (!empty($textContent)) {
                $shape->setText($textContent);
            }
            $slide->addShape($shape);
        } else {
            $shape = $slide->createRichTextShape();
        }

        $shape->setOffsetX((int)($x));
        $shape->setOffsetY((int)($y));
        $shape->setWidth((int)($w));
        $shape->setHeight((int)($h));

        $fill = $shape->getFill();
        $fill->setFillType(Fill::FILL_SOLID);
        $fill->setStartColor(new Color('FF' . ltrim($bgColor, '#')));

        $borderWidth = max(1, (int)(($sd['shape_border_width'] ?? $sd['border_width'] ?? 0) * $scale));
        if (($sd['shape_border_width'] ?? $sd['border_width'] ?? 0) > 0) {
            $borderColor = $this->normalizeHex($sd['shape_border_color'] ?? $sd['border_color'] ?? '#000000');
            $shape->getBorder()->setLineWidth($borderWidth);
            $shape->getBorder()->setColor(new Color('FF' . ltrim($borderColor, '#')));
            $shape->getBorder()->setLineStyle(Border::LINE_SINGLE);
        }

        if ($autoShapeType === null && !empty($textContent)) {
            $textColor = $this->normalizeHex($sd['shape_text_color'] ?? '#ffffff');
            $shapeLineHeight = (float)($sd['shape_line_height'] ?? 1.0);
            $shapeLineSpacing = max(25, (int)($shapeLineHeight * 100));
            $shape->getActiveParagraph()->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $shape->getActiveParagraph()->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
            $shape->getActiveParagraph()->setLineSpacing($shapeLineSpacing);
            $run = $shape->createTextRun($textContent);
            $font = $run->getFont();
            $font->setSize(max(5, (int)(($sd['shape_font_size'] ?? 14) * 0.7 * $scale)));
            $font->setColor(new Color('FF' . ltrim($textColor, '#')));
            $font->setBold(in_array($sd['shape_font_weight'] ?? '600', ['bold', '600', '700', '800', '900']));
        }
    }

    private function renderColorSwatch(Slide $slide, array $item, array $sd, float $x, float $y, float $w, float $h, float $scale = 1.0): void
    {
        $bgColor = $this->normalizeHex($item['color'] ?? '#6366f1');
        $textColor = $this->getContrastColor($bgColor);

        $shape = $slide->createRichTextShape();
        $shape->setOffsetX((int)($x));
        $shape->setOffsetY((int)($y));
        $shape->setWidth((int)($w));
        $shape->setHeight((int)($h));

        $fill = $shape->getFill();
        $fill->setFillType(Fill::FILL_SOLID);
        $fill->setStartColor(new Color('FF' . ltrim($bgColor, '#')));

        $shape->setInsetBottom(max(1, (int)(6 * $scale)));
        $shape->setInsetLeft(max(1, (int)(8 * $scale)));
        $shape->setInsetRight(max(1, (int)(8 * $scale)));
        $shape->getActiveParagraph()->getAlignment()->setVertical(Alignment::VERTICAL_BOTTOM);
        $shape->getActiveParagraph()->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        $hexLabel = $item['color'] ?? '#6366f1';
        $run = $shape->createTextRun($hexLabel);
        $font = $run->getFont();
        $font->setSize(max(5, (int)(8 * $scale)));
        $font->setName('Consolas');
        $font->setColor(new Color('FF' . ltrim($textColor, '#')));

        $colorData = $item['color_data'] ?? [];
        if (is_string($colorData)) {
            $colorData = json_decode($colorData, true) ?? [];
        }
        if (!empty($colorData['rgb'])) {
            $shape->createBreak();
            $rgb = $colorData['rgb'];
            $run2 = $shape->createTextRun("R{$rgb['r']} G{$rgb['g']} B{$rgb['b']}");
            $font2 = $run2->getFont();
            $font2->setSize(max(5, (int)(6 * $scale)));
            $font2->setName('Consolas');
            $font2->setColor(new Color('CC' . ltrim($textColor, '#')));
        }
    }

    private function renderTodoList(Slide $slide, array $item, array $sd, float $x, float $y, float $w, float $h, float $scale = 1.0): void
    {
        $shape = $slide->createRichTextShape();
        $shape->setOffsetX((int)($x));
        $shape->setOffsetY((int)($y));
        $shape->setWidth((int)($w));
        $shape->setHeight((int)($h));
        $shape->setAutoFit(RichText::AUTOFIT_SHAPE);

        $fill = $shape->getFill();
        $fill->setFillType(Fill::FILL_SOLID);
        $fill->setStartColor(new Color('FFFFFFFF'));

        $shape->getBorder()->setLineWidth(1);
        $shape->getBorder()->setColor(new Color('FFE5E7EB'));
        $shape->getBorder()->setLineStyle(Border::LINE_SINGLE);

        $shape->setInsetTop(max(1, (int)(8 * $scale)));
        $shape->setInsetLeft(max(1, (int)(10 * $scale)));
        $shape->setInsetRight(max(1, (int)(10 * $scale)));
        $shape->setInsetBottom(max(1, (int)(8 * $scale)));

        if (!empty($item['title'])) {
            $run = $shape->createTextRun($item['title']);
            $font = $run->getFont();
            $font->setSize(max(5, (int)(10 * $scale)));
            $font->setBold(true);
            $font->setColor(new Color('FF111827'));
            $shape->createBreak();
        }

        foreach ($item['todos'] ?? [] as $todo) {
            $check = !empty($todo['completed']) ? '[x] ' : '[ ] ';
            $text = $check . ($todo['text'] ?? '');
            $run = $shape->createTextRun($text);
            $font = $run->getFont();
            $font->setSize(max(5, (int)(8 * $scale)));
            $font->setColor(new Color(!empty($todo['completed']) ? 'FF9CA3AF' : 'FF374151'));
            if (!empty($todo['completed'])) {
                $font->setStrikethrough(true);
            }
            $shape->createBreak();
        }
    }

    private function renderLink(Slide $slide, array $item, array $sd, float $x, float $y, float $w, float $h, float $scale = 1.0): void
    {
        $shape = $slide->createRichTextShape();
        $shape->setOffsetX((int)($x));
        $shape->setOffsetY((int)($y));
        $shape->setWidth((int)($w));
        $shape->setHeight((int)($h));
        $shape->setAutoFit(RichText::AUTOFIT_SHAPE);

        $fill = $shape->getFill();
        $fill->setFillType(Fill::FILL_SOLID);
        $fill->setStartColor(new Color('FFFFFFFF'));

        $shape->getBorder()->setLineWidth(1);
        $shape->getBorder()->setColor(new Color('FFE5E7EB'));
        $shape->getBorder()->setLineStyle(Border::LINE_SINGLE);

        $shape->setInsetTop(max(1, (int)(8 * $scale)));
        $shape->setInsetLeft(max(1, (int)(10 * $scale)));

        if (!empty($item['title'])) {
            $run = $shape->createTextRun($item['title']);
            $font = $run->getFont();
            $font->setSize(max(5, (int)(10 * $scale)));
            $font->setBold(true);
            $font->setColor(new Color('FF111827'));
            $shape->createBreak();
        }
        if (!empty($item['url'])) {
            $run = $shape->createTextRun($item['url']);
            $font = $run->getFont();
            $font->setSize(max(5, (int)(7 * $scale)));
            $font->setColor(new Color('FF6366F1'));
        }
    }

    private function renderFile(Slide $slide, array $item, array $sd, float $x, float $y, float $w, float $h, float $scale = 1.0): void
    {
        $url = $item['image_url'] ?? $item['thumbnail_url'] ?? '';
        $tmpFile = $this->assetToTempFile($url, 'file_' . ($item['id'] ?? ''));
        $placed = false;
        $labelH = max(10, (int)(24 * $scale));

        if ($tmpFile) {
            try {
                $shape = $slide->createDrawingShape();
                $shape->setResizeProportional(false);
                $shape->setWidth(max(1, (int)$w));
                $shape->setHeight(max(1, (int)(max($h - $labelH, 10))));
                $shape->setPath($tmpFile, true);
                $shape->setOffsetX((int)$x);
                $shape->setOffsetY((int)$y);
                $placed = true;
            } catch (\Throwable $e) {}
        }

        $label = $slide->createRichTextShape();
        $label->setOffsetX((int)($x));
        $label->setOffsetY((int)($y + ($placed ? $h - $labelH : 0)));
        $label->setWidth((int)($w));
        $label->setHeight($placed ? $labelH : (int)$h);

        $fill = $label->getFill();
        $fill->setFillType(Fill::FILL_SOLID);
        $fill->setStartColor(new Color('FFF9FAFB'));

        $label->setInsetLeft(max(1, (int)(6 * $scale)));
        $run = $label->createTextRun($item['title'] ?? 'File');
        $font = $run->getFont();
        $font->setSize(max(5, (int)(7 * $scale)));
        $font->setColor(new Color('FF374151'));
    }

    private function renderImageSet(Slide $slide, array $item, array $sd, float $x, float $y, float $w, float $h, float $scale = 1.0): void
    {
        $images = $item['images'] ?? [];
        if (empty($images)) return;

        $cols = count($images) <= 1 ? 1 : (count($images) <= 4 ? 2 : 3);
        $rows = (int)ceil(count($images) / $cols);
        $cellW = $w / $cols;
        $cellH = $h / max($rows, 1);
        $gap = 2;

        foreach ($images as $idx => $img) {
            $url = $img['image_url'] ?? $img['thumbnail_url'] ?? '';
            $tmpFile = $this->assetToTempFile($url, 'imgset_' . ($item['id'] ?? '') . '_' . $idx);
            if (!$tmpFile) continue;

            $col = $idx % $cols;
            $row = (int)floor($idx / $cols);
            $ix = $x + $col * $cellW + $gap;
            $iy = $y + $row * $cellH + $gap;

            try {
                $shape = $slide->createDrawingShape();
                $shape->setResizeProportional(false);
                $shape->setWidth(max(1, (int)($cellW - $gap * 2)));
                $shape->setHeight(max(1, (int)($cellH - $gap * 2)));
                $shape->setPath($tmpFile, true);
                $shape->setOffsetX((int)$ix);
                $shape->setOffsetY((int)$iy);
            } catch (\Throwable $e) {}
        }
    }

    private function renderFrame(Slide $slide, array $item, array $sd, float $x, float $y, float $w, float $h, float $scale = 1.0): void
    {
        $shape = $slide->createRichTextShape();
        $shape->setOffsetX((int)($x));
        $shape->setOffsetY((int)($y));
        $shape->setWidth((int)($w));
        $shape->setHeight((int)($h));

        $shape->getBorder()->setLineWidth(1);
        $shape->getBorder()->setColor(new Color('FFD1D5DB'));
        $shape->getBorder()->setLineStyle(Border::LINE_SINGLE);

        if (!empty($item['title']) && ($sd['frame_label'] ?? true) !== false) {
            $shape->setInsetTop(max(1, (int)(4 * $scale)));
            $shape->setInsetLeft(max(1, (int)(6 * $scale)));
            $run = $shape->createTextRun($item['title']);
            $font = $run->getFont();
            $font->setSize(max(5, (int)(6 * $scale)));
            $font->setColor(new Color('FF9CA3AF'));
            $font->setBold(true);
        }
    }

    private function renderTable(Slide $slide, array $item, array $sd, float $x, float $y, float $w, float $h, float $scale = 1.0): void
    {
        $tableData = $item['content'] ?? null;
        if (is_string($tableData)) {
            $tableData = json_decode($tableData, true);
        }
        if (!$tableData || empty($tableData['rows'])) {
            $this->renderGeneric($slide, $item, $sd, $x, $y, $w, $h, $scale);
            return;
        }

        $rows = $tableData['rows'];
        $numRows = count($rows);
        $numCols = max(1, max(array_map(fn($r) => count($r['cells'] ?? $r), $rows)));

        try {
            $tableShape = $slide->createTableShape($numCols);
            $tableShape->setOffsetX((int)($x));
            $tableShape->setOffsetY((int)($y));
            $tableShape->setWidth((int)($w));
            $tableShape->setHeight((int)($h));

            $cellFontSize = max(5, (int)(7 * $scale));
            foreach ($rows as $ri => $row) {
                $tr = $tableShape->createRow();
                $tr->setHeight((int)($h / $numRows));
                $cells = $row['cells'] ?? $row;
                foreach ($cells as $ci => $cell) {
                    if ($ci >= $numCols) break;
                    $cellObj = $tr->getCell($ci);
                    $text = is_array($cell) ? ($cell['value'] ?? $cell['text'] ?? '') : ($cell ?? '');
                    $run = $cellObj->createTextRun($text);
                    $font = $run->getFont();
                    $font->setSize($cellFontSize);
                    $font->setColor(new Color($ri === 0 ? 'FF111827' : 'FF374151'));
                    if ($ri === 0) {
                        $font->setBold(true);
                        $cellFill = $cellObj->getFill();
                        $cellFill->setFillType(Fill::FILL_SOLID);
                        $cellFill->setStartColor(new Color('FFF3F4F6'));
                    }
                }
            }
        } catch (\Throwable $e) {
            $this->renderGeneric($slide, $item, $sd, $x, $y, $w, $h, $scale);
        }
    }

    private function renderCalendarEvent(Slide $slide, array $item, array $sd, float $x, float $y, float $w, float $h, float $scale = 1.0): void
    {
        $shape = $slide->createRichTextShape();
        $shape->setOffsetX((int)($x));
        $shape->setOffsetY((int)($y));
        $shape->setWidth((int)($w));
        $shape->setHeight((int)($h));
        $shape->setAutoFit(RichText::AUTOFIT_SHAPE);

        $fill = $shape->getFill();
        $fill->setFillType(Fill::FILL_SOLID);
        $fill->setStartColor(new Color('FFFFFFFF'));

        $shape->getBorder()->setLineWidth(1);
        $shape->getBorder()->setColor(new Color('FFE5E7EB'));
        $shape->getBorder()->setLineStyle(Border::LINE_SINGLE);

        $shape->setInsetTop(max(1, (int)(8 * $scale)));
        $shape->setInsetLeft(max(1, (int)(10 * $scale)));

        $run = $shape->createTextRun($item['title'] ?? 'Event');
        $font = $run->getFont();
        $font->setSize(max(5, (int)(10 * $scale)));
        $font->setBold(true);
        $font->setColor(new Color('FF111827'));

        if (!empty($item['content'])) {
            $shape->createBreak();
            $run2 = $shape->createTextRun($item['content']);
            $font2 = $run2->getFont();
            $font2->setSize(max(5, (int)(8 * $scale)));
            $font2->setColor(new Color('FF6B7280'));
        }

        if (!empty($sd['event_location'])) {
            $shape->createBreak();
            $run3 = $shape->createTextRun($sd['event_location']);
            $font3 = $run3->getFont();
            $font3->setSize(max(5, (int)(7 * $scale)));
            $font3->setColor(new Color('FF9CA3AF'));
        }
    }

    private function renderGeneric(Slide $slide, array $item, array $sd, float $x, float $y, float $w, float $h, float $scale = 1.0): void
    {
        $shape = $slide->createRichTextShape();
        $shape->setOffsetX((int)($x));
        $shape->setOffsetY((int)($y));
        $shape->setWidth((int)($w));
        $shape->setHeight((int)($h));

        $fill = $shape->getFill();
        $fill->setFillType(Fill::FILL_SOLID);
        $fill->setStartColor(new Color('FFF9FAFB'));

        $shape->getBorder()->setLineWidth(1);
        $shape->getBorder()->setColor(new Color('FFE5E7EB'));
        $shape->getBorder()->setLineStyle(Border::LINE_SINGLE);

        $shape->getActiveParagraph()->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $shape->getActiveParagraph()->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);

        $label = $item['title'] ?? ($item['type'] ?? 'item');
        $run = $shape->createTextRun($label);
        $font = $run->getFont();
        $font->setSize(max(5, (int)(8 * $scale)));
        $font->setColor(new Color('FF6B7280'));
    }

    // ── Connection lines ──

    private function renderConnections(Slide $slide, array $connections, array $visibleItems, float $originX, float $originY, float $scale, float $offsetX, float $offsetY): void
    {
        $visibleIds = [];
        foreach ($visibleItems as $item) {
            $visibleIds[$item['id']] = true;
        }

        foreach ($connections as $conn) {
            $fromId = $conn['from_item_id'] ?? null;
            $toId = $conn['to_item_id'] ?? null;
            if (!$fromId || !$toId) continue;

            $fromItem = $this->itemMap[$fromId] ?? null;
            $toItem = $this->itemMap[$toId] ?? null;
            if (!$fromItem || !$toItem) continue;

            if (!isset($visibleIds[$fromId]) && !isset($visibleIds[$toId])) continue;

            $fromW = (float)($fromItem['width'] ?? 240);
            $fromH = (float)($fromItem['height'] ?? 200);
            $toW = (float)($toItem['width'] ?? 240);
            $toH = (float)($toItem['height'] ?? 200);

            $fromAx = $conn['from_anchor_x'] ?? 0.5;
            $fromAy = $conn['from_anchor_y'] ?? 0.5;
            $toAx = $conn['to_anchor_x'] ?? 0.5;
            $toAy = $conn['to_anchor_y'] ?? 0.5;

            $x1 = ((float)($fromItem['pos_x'] ?? 0) + $fromAx * $fromW - $originX) * $scale + $offsetX;
            $y1 = ((float)($fromItem['pos_y'] ?? 0) + $fromAy * $fromH - $originY) * $scale + $offsetY;
            $x2 = ((float)($toItem['pos_x'] ?? 0) + $toAx * $toW - $originX) * $scale + $offsetX;
            $y2 = ((float)($toItem['pos_y'] ?? 0) + $toAy * $toH - $originY) * $scale + $offsetY;

            $color = $this->normalizeHex($conn['color'] ?? '#94a3b8');
            $width = max(1, (int)($conn['line_width'] ?? 2));

            try {
                $line = $slide->createLineShape(
                    (int)$x1,
                    (int)$y1,
                    (int)$x2,
                    (int)$y2
                );
                $line->getBorder()->setColor(new Color('FF' . ltrim($color, '#')));
                $line->getBorder()->setLineWidth($width);
                $line->getBorder()->setLineStyle(Border::LINE_SINGLE);

                $lineStyle = $conn['line_style'] ?? 'solid';
                if ($lineStyle === 'dashed') {
                    $line->getBorder()->setDashStyle(Border::DASH_DASH);
                } elseif ($lineStyle === 'dotted') {
                    $line->getBorder()->setDashStyle(Border::DASH_DOT);
                }
            } catch (\Throwable $e) {}
        }
    }

    // ── Slide background ──

    private function applySlideBackground(Slide $slide, string $hexColor): void
    {
        $bg = new BackgroundColor();
        $color = new Color();
        $color->setRGB(ltrim($hexColor, '#'));
        $bg->setColor($color);
        $slide->setBackground($bg);
    }

    // ── Asset helpers ──

    private function assetToTempFile(string $url, string $prefix): ?string
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
            if ($converted) {
                $binary = $converted;
                $mime = 'image/png';
            }
        }

        $scaled = $this->downscaleIfNeeded($binary);
        if ($scaled) {
            $binary = $scaled;
            $mime = 'image/jpeg';
        }

        $ext = match($mime) { 'image/png' => 'png', 'image/gif' => 'gif', default => 'jpg' };
        $path = $this->tempDir . '/' . $prefix . '.' . $ext;
        $written = @file_put_contents($path, $binary);
        return ($written && file_exists($path)) ? $path : null;
    }

    private function resolveAssetData(string $url): ?string
    {
        if (empty($url)) return null;
        if (str_starts_with($url, 'data:')) return $url;
        if (isset($this->assetMap[$url])) return $this->assetMap[$url];
        $noSlash = ltrim($url, '/');
        if (isset($this->assetMap[$noSlash])) return $this->assetMap[$noSlash];
        return null;
    }

    private function extractBase64(string $dataUri): ?array
    {
        if (!str_starts_with($dataUri, 'data:')) return null;

        $commaPos = strpos($dataUri, ',');
        if ($commaPos === false) return null;

        $header = substr($dataUri, 5, $commaPos - 5);
        $base64Data = substr($dataUri, $commaPos + 1);

        $semiPos = strpos($header, ';');
        $mime = $semiPos !== false ? substr($header, 0, $semiPos) : $header;

        if (empty($base64Data)) return null;

        return [
            'mime' => $mime,
            'base64' => $base64Data,
        ];
    }

    private function applyRoundedCorners(string $imageData, int $radius, int $targetW, int $targetH): ?string
    {
        $src = @imagecreatefromstring($imageData);
        if (!$src) return null;

        $srcW = imagesx($src);
        $srcH = imagesy($src);

        $dimScale = min($srcW / max($targetW, 1), $srcH / max($targetH, 1));
        $r = (int)round($radius * $dimScale);
        $r = (int)min($r, $srcW / 2, $srcH / 2);
        if ($r < 1) {
            imagedestroy($src);
            return null;
        }

        $dst = imagecreatetruecolor($srcW, $srcH);
        imagesavealpha($dst, true);
        imagealphablending($dst, false);
        $transparent = imagecolorallocatealpha($dst, 0, 0, 0, 127);
        imagefill($dst, 0, 0, $transparent);

        imagealphablending($dst, true);
        imagecopy($dst, $src, 0, 0, 0, 0, $srcW, $srcH);
        imagealphablending($dst, false);

        // Mask out the four corners
        for ($cx = 0; $cx < $r; $cx++) {
            for ($cy = 0; $cy < $r; $cy++) {
                $dist = sqrt(($r - $cx) * ($r - $cx) + ($r - $cy) * ($r - $cy));
                if ($dist > $r) {
                    // Top-left
                    imagesetpixel($dst, $cx, $cy, $transparent);
                    // Top-right
                    imagesetpixel($dst, $srcW - 1 - $cx, $cy, $transparent);
                    // Bottom-left
                    imagesetpixel($dst, $cx, $srcH - 1 - $cy, $transparent);
                    // Bottom-right
                    imagesetpixel($dst, $srcW - 1 - $cx, $srcH - 1 - $cy, $transparent);
                }
            }
        }

        ob_start();
        imagepng($dst, null, 6);
        $png = ob_get_clean();
        imagedestroy($src);
        imagedestroy($dst);

        return $png ?: null;
    }

    private function convertToPng(string $imageData): ?string
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

    /**
     * Downscale large images to keep PPTX file size and memory usage reasonable.
     * Images wider/taller than the max will be proportionally scaled down.
     */
    private function downscaleIfNeeded(string $imageData, int $maxDim = 1920): ?string
    {
        $img = @imagecreatefromstring($imageData);
        if (!$img) return null;

        $w = imagesx($img);
        $h = imagesy($img);
        if ($w <= $maxDim && $h <= $maxDim) {
            imagedestroy($img);
            return null;
        }

        $ratio = min($maxDim / $w, $maxDim / $h);
        $newW = (int)($w * $ratio);
        $newH = (int)($h * $ratio);

        $dst = imagecreatetruecolor($newW, $newH);
        imagesavealpha($dst, true);
        imagealphablending($dst, false);
        $transparent = imagecolorallocatealpha($dst, 0, 0, 0, 127);
        imagefill($dst, 0, 0, $transparent);
        imagealphablending($dst, true);

        imagecopyresampled($dst, $img, 0, 0, 0, 0, $newW, $newH, $w, $h);
        imagedestroy($img);

        ob_start();
        imagejpeg($dst, null, 85);
        $result = ob_get_clean();
        imagedestroy($dst);

        $this->dbg("  downscaled {$w}x{$h} -> {$newW}x{$newH}");
        return $result ?: null;
    }

    // ── Text transform ──

    private function applyTextTransform(string $text, string $transform): string
    {
        return match ($transform) {
            'uppercase'  => mb_strtoupper($text),
            'lowercase'  => mb_strtolower($text),
            'capitalize' => mb_convert_case($text, MB_CASE_TITLE),
            default      => $text,
        };
    }

    // ── Text height estimation ──

    private function estimateTextHeight(array $segments, int $fontSizePt, int $shapeWidth, float $lineHeight = 1.5): int
    {
        if (empty($segments)) return 20;

        $lineHeightPx = $fontSizePt * max($lineHeight, 1.0) * 1.15;
        $avgCharWidth = $fontSizePt * 0.62;
        $usableWidth = max($shapeWidth - 4, 20);
        $charsPerLine = max(1, (int)floor($usableWidth / max($avgCharWidth, 1)));

        $totalLines = 0;
        foreach ($segments as $seg) {
            if ($seg['break']) {
                $totalLines++;
                continue;
            }
            $textLen = mb_strlen($seg['text']);
            if ($textLen === 0) continue;
            $wrappedLines = max(1, (int)ceil($textLen / $charsPerLine));
            $totalLines += $wrappedLines;
        }

        $totalLines = max($totalLines, 1);
        return (int)ceil($totalLines * $lineHeightPx) + 12;
    }

    // ── Conversion helpers ──

    private function normalizeHex(string $color): string
    {
        $color = trim($color);
        if (!str_starts_with($color, '#')) {
            $color = '#' . $color;
        }
        $hex = ltrim($color, '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        if (strlen($hex) === 8) {
            $hex = substr($hex, 0, 6);
        }
        if (!preg_match('/^[0-9a-fA-F]{6}$/', $hex)) {
            $hex = 'F5F5F5';
        }
        return '#' . strtoupper($hex);
    }

    private function getContrastColor(string $hex): string
    {
        $hex = ltrim($hex, '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        $r = hexdec(substr($hex, 0, 2));
        $g = hexdec(substr($hex, 2, 2));
        $b = hexdec(substr($hex, 4, 2));
        $luminance = ($r * 299 + $g * 587 + $b * 114) / 1000;
        return $luminance > 128 ? '#1F2937' : '#FFFFFF';
    }

    private function mapTextAlign(string $align): string
    {
        return match ($align) {
            'center' => Alignment::HORIZONTAL_CENTER,
            'right'  => Alignment::HORIZONTAL_RIGHT,
            default  => Alignment::HORIZONTAL_LEFT,
        };
    }

    private function mapFontFamily(string $family): string
    {
        $first = trim(explode(',', $family)[0], " \t\n\r\0\x0B'\"");
        if (empty($first)) return 'Calibri';

        $lower = strtolower($first);

        // Map generic CSS families to safe PowerPoint fonts
        if ($lower === 'sans-serif') return 'Calibri';
        if ($lower === 'serif') return 'Georgia';
        if ($lower === 'monospace') return 'Consolas';
        if ($lower === 'cursive') return 'Segoe Script';
        if ($lower === 'fantasy') return 'Impact';

        // System fonts that exist natively in Windows/Office
        $systemFonts = [
            'arial', 'calibri', 'times new roman', 'georgia', 'verdana',
            'trebuchet ms', 'tahoma', 'garamond', 'palatino linotype',
            'comic sans ms', 'impact', 'lucida console', 'courier new',
            'segoe ui', 'consolas', 'cambria',
        ];
        if (in_array($lower, $systemFonts)) return $first;

        // Map common web fallbacks
        $systemMap = [
            'helvetica' => 'Arial',
            'helvetica neue' => 'Arial',
            'system-ui' => 'Calibri',
            '-apple-system' => 'Calibri',
            'segoe ui' => 'Segoe UI',
            'inter' => 'Calibri',
        ];
        if (isset($systemMap[$lower])) return $systemMap[$lower];

        // Map Google Fonts to closest PowerPoint-safe equivalents
        $googleFallbacks = [
            'roboto' => 'Arial',
            'open sans' => 'Arial',
            'lato' => 'Calibri',
            'montserrat' => 'Arial',
            'poppins' => 'Calibri',
            'raleway' => 'Calibri',
            'source sans 3' => 'Arial',
            'nunito' => 'Calibri',
            'work sans' => 'Calibri',
            'outfit' => 'Calibri',
            'playfair display' => 'Georgia',
            'merriweather' => 'Georgia',
            'lora' => 'Georgia',
            'pt serif' => 'Times New Roman',
            'libre baskerville' => 'Georgia',
            'oswald' => 'Arial',
            'bebas neue' => 'Impact',
            'anton' => 'Impact',
            'archivo black' => 'Impact',
            'roboto mono' => 'Consolas',
            'source code pro' => 'Consolas',
            'fira code' => 'Consolas',
            'jetbrains mono' => 'Consolas',
        ];
        if (isset($googleFallbacks[$lower])) return $googleFallbacks[$lower];

        // Pass through the actual font name
        return $first;
    }

    // ── Drawing (freehand brush strokes stored as SVG paths in content JSON) ──

    private function renderDrawing(Slide $slide, array $item, array $sd, float $x, float $y, float $w, float $h, float $scale = 1.0): void
    {
        $this->dbg("rDraw id={$item['id']}");

        $content = $item['content'] ?? '';
        if (is_string($content)) {
            $data = @json_decode($content, true);
        } else {
            $data = $content;
        }

        $strokes = $data['strokes'] ?? [];
        if (empty($strokes)) {
            if (!empty($item['image_url'])) {
                $this->renderImage($slide, $item, $sd, $x, $y, $w, $h, $scale);
                return;
            }
            $this->dbg("  no strokes, skip");
            return;
        }

        $vbW = (int)(isset($data['width']) ? intval($data['width']) : ($sd['original_width'] ?? $item['width'] ?? 200));
        $vbH = (int)(isset($data['height']) ? intval($data['height']) : ($sd['original_height'] ?? $item['height'] ?? 150));
        if ($vbW < 1) $vbW = 200;
        if ($vbH < 1) $vbH = 150;

        // Try SVG-based conversion first (Imagick / CLI)
        $pngData = $this->renderDrawingSvg($strokes, $vbW, $vbH, (int)$w, (int)$h);

        // Fallback: pure GD rendering
        if (!$pngData) {
            $pngData = $this->renderDrawingGd($strokes, $vbW, $vbH, (int)$w, (int)$h);
        }

        if ($pngData) {
            $tmpFile = $this->tempDir . '/draw_' . ($item['id'] ?? uniqid()) . '.png';
            @file_put_contents($tmpFile, $pngData);
            if (file_exists($tmpFile)) {
                try {
                    $shape = $slide->createDrawingShape();
                    $shape->setResizeProportional(false);
                    $shape->setWidth(max(1, (int)$w));
                    $shape->setHeight(max(1, (int)$h));
                    $shape->setPath($tmpFile, true);
                    $shape->setOffsetX((int)$x);
                    $shape->setOffsetY((int)$y);
                    $this->dbg("  OK {$w}x{$h}");
                    return;
                } catch (\Throwable $e) {
                    $this->dbg("  shape EX: " . $e->getMessage());
                }
            }
        }

        if (!empty($item['image_url'])) {
            $this->renderImage($slide, $item, $sd, $x, $y, $w, $h, $scale);
        } else {
            $this->dbg("  all methods failed");
        }
    }

    private function renderDrawingSvg(array $strokes, int $vbW, int $vbH, int $w, int $h): ?string
    {
        $svgPaths = '';
        foreach ($strokes as $stroke) {
            $d = $stroke['svgPath'] ?? '';
            $color = $stroke['color'] ?? '#000000';
            if (empty($d)) continue;
            $d = htmlspecialchars($d, ENT_QUOTES | ENT_XML1, 'UTF-8');
            $color = htmlspecialchars($color, ENT_QUOTES | ENT_XML1, 'UTF-8');
            $svgPaths .= '<path d="' . $d . '" fill="' . $color . '" stroke-linecap="round" stroke-linejoin="round"/>';
        }
        if (empty($svgPaths)) return null;

        $svg = '<?xml version="1.0" encoding="UTF-8"?>'
             . '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $vbW . ' ' . $vbH . '"'
             . ' width="' . $w . '" height="' . $h . '">'
             . $svgPaths . '</svg>';

        return $this->svgToPng($svg, $w, $h);
    }

    private function renderDrawingGd(array $strokes, int $vbW, int $vbH, int $outW, int $outH): ?string
    {
        $ss = 2;
        $cW = $outW * $ss;
        $cH = $outH * $ss;
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

            $points = $this->svgPathToPoints($d, $scaleX, $scaleY);
            if (count($points) >= 6) {
                imagefilledpolygon($img, $points, (int)(count($points) / 2), $gdColor);
            }
        }

        $dst = imagecreatetruecolor($outW, $outH);
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        imagecopyresampled($dst, $img, 0, 0, 0, 0, $outW, $outH, $cW, $cH);
        imagedestroy($img);

        ob_start();
        imagepng($dst);
        $result = ob_get_clean();
        imagedestroy($dst);

        $this->dbg("  renderDrawingGd: OK");
        return $result ?: null;
    }

    // ── Pen Shape (vector path in style_data.pen_svg_path with viewBox 0 0 100 100) ──

    private function renderPenShape(Slide $slide, array $item, array $sd, float $x, float $y, float $w, float $h, float $scale = 1.0): void
    {
        $this->dbg("rPen id={$item['id']}");

        $path = $sd['pen_svg_path'] ?? '';
        if (empty($path)) {
            $this->dbg("  no path, skip");
            return;
        }

        $maskUrl = $sd['mask_image_url'] ?? null;
        if ($maskUrl) {
            $tmpFile = $this->assetToTempFile($maskUrl, 'penmask_' . ($item['id'] ?? ''));
            if ($tmpFile) {
                try {
                    $shape = $slide->createDrawingShape();
                    $shape->setResizeProportional(false);
                    $shape->setWidth(max(1, (int)$w));
                    $shape->setHeight(max(1, (int)$h));
                    $shape->setPath($tmpFile, true);
                    $shape->setOffsetX((int)$x);
                    $shape->setOffsetY((int)$y);
                    $this->dbg("  mask image OK");
                    return;
                } catch (\Throwable $e) {}
            }
        }

        $fill = $sd['shape_fill'] ?? '#6366f1';
        $borderColor = $sd['shape_border_color'] ?? '#4f46e5';
        $borderWidth = (float)($sd['shape_border_width'] ?? 2);
        $opacity = ((float)($sd['shape_opacity'] ?? 100)) / 100;

        // Try SVG conversion first
        $pngData = $this->renderPenShapeSvg($path, $fill, $borderColor, $borderWidth, $opacity, (int)$w, (int)$h);

        // Fallback: GD rendering
        if (!$pngData) {
            $pngData = $this->renderPenShapeGd($path, $fill, $borderColor, $borderWidth, $opacity, (int)$w, (int)$h);
        }

        if ($pngData) {
            $tmpFile = $this->tempDir . '/pen_' . ($item['id'] ?? uniqid()) . '.png';
            @file_put_contents($tmpFile, $pngData);
            if (file_exists($tmpFile)) {
                try {
                    $shape = $slide->createDrawingShape();
                    $shape->setResizeProportional(false);
                    $shape->setWidth(max(1, (int)$w));
                    $shape->setHeight(max(1, (int)$h));
                    $shape->setPath($tmpFile, true);
                    $shape->setOffsetX((int)$x);
                    $shape->setOffsetY((int)$y);
                    $this->dbg("  OK {$w}x{$h}");
                    return;
                } catch (\Throwable $e) {
                    $this->dbg("  pen EX: " . $e->getMessage());
                }
            }
        }

        $this->renderShape($slide, $item, $sd, $x, $y, $w, $h, $scale);
    }

    private function renderPenShapeSvg(string $path, string $fill, string $borderColor, float $borderWidth, float $opacity, int $w, int $h): ?string
    {
        $fillRgba = $this->hexToRgba($fill, $opacity);
        $borderRgba = $this->hexToRgba($borderColor, 1.0);
        $dEsc = htmlspecialchars($path, ENT_QUOTES | ENT_XML1, 'UTF-8');
        $strokeAttr = $borderWidth > 0
            ? ' stroke="' . $borderRgba . '" stroke-width="' . ($borderWidth * (100 / max($w, 1))) . '" stroke-linejoin="round" stroke-linecap="round"'
            : '';

        $svg = '<?xml version="1.0" encoding="UTF-8"?>'
             . '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"'
             . ' width="' . $w . '" height="' . $h . '" preserveAspectRatio="none">'
             . '<path d="' . $dEsc . '" fill="' . $fillRgba . '"' . $strokeAttr . '/>'
             . '</svg>';

        return $this->svgToPng($svg, $w, $h);
    }

    private function renderPenShapeGd(string $pathD, string $fill, string $borderColor, float $borderWidth, float $opacity, int $outW, int $outH): ?string
    {
        $ss = 2;
        $cW = $outW * $ss;
        $cH = $outH * $ss;
        $scaleX = $cW / 100.0;
        $scaleY = $cH / 100.0;

        $img = imagecreatetruecolor($cW, $cH);
        imagealphablending($img, false);
        imagesavealpha($img, true);
        $trans = imagecolorallocatealpha($img, 0, 0, 0, 127);
        imagefill($img, 0, 0, $trans);
        imagealphablending($img, true);

        $hex = ltrim($fill, '#');
        if (strlen($hex) === 3) $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        $r = hexdec(substr($hex, 0, 2));
        $g = hexdec(substr($hex, 2, 2));
        $b = hexdec(substr($hex, 4, 2));
        $alpha = max(0, (int)(127 - $opacity * 127));
        $gdFill = imagecolorallocatealpha($img, $r, $g, $b, $alpha);

        $points = $this->svgPathToPoints($pathD, $scaleX, $scaleY);
        if (count($points) >= 6) {
            imagefilledpolygon($img, $points, (int)(count($points) / 2), $gdFill);

            if ($borderWidth > 0) {
                $bHex = ltrim($borderColor, '#');
                if (strlen($bHex) === 3) $bHex = $bHex[0].$bHex[0].$bHex[1].$bHex[1].$bHex[2].$bHex[2];
                $gdBorder = imagecolorallocate($img, hexdec(substr($bHex, 0, 2)), hexdec(substr($bHex, 2, 2)), hexdec(substr($bHex, 4, 2)));
                imagesetthickness($img, max(1, (int)($borderWidth * $ss)));
                $n = count($points) / 2;
                for ($i = 0; $i < $n; $i++) {
                    $nx = ($i + 1) % $n;
                    imageline($img, (int)$points[$i * 2], (int)$points[$i * 2 + 1], (int)$points[$nx * 2], (int)$points[$nx * 2 + 1], $gdBorder);
                }
            }
        }

        $dst = imagecreatetruecolor($outW, $outH);
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        imagecopyresampled($dst, $img, 0, 0, 0, 0, $outW, $outH, $cW, $cH);
        imagedestroy($img);

        ob_start();
        imagepng($dst);
        $result = ob_get_clean();
        imagedestroy($dst);

        $this->dbg("  renderPenShapeGd: OK");
        return $result ?: null;
    }

    // ── SVG path parser: converts d="" attribute to flat array of [x,y,...] points ──

    private function svgPathToPoints(string $d, float $scaleX, float $scaleY): array
    {
        $points = [];
        $cx = 0; $cy = 0;
        $startX = 0; $startY = 0;

        $d = preg_replace('/,/', ' ', $d);
        preg_match_all('/([MmLlHhVvCcSsQqTtAaZz])([^MmLlHhVvCcSsQqTtAaZz]*)/', $d, $matches, PREG_SET_ORDER);

        foreach ($matches as $m) {
            $cmd = $m[1];
            $args = array_values(array_filter(preg_split('/[\s]+/', trim($m[2])), fn($v) => $v !== ''));
            $nums = array_map('floatval', $args);
            $isRel = ctype_lower($cmd);
            $CMD = strtoupper($cmd);

            $i = 0;
            do {
                switch ($CMD) {
                    case 'M':
                        $mx = $isRel ? $cx + ($nums[$i] ?? 0) : ($nums[$i] ?? 0);
                        $my = $isRel ? $cy + ($nums[$i+1] ?? 0) : ($nums[$i+1] ?? 0);
                        $cx = $mx; $cy = $my;
                        $startX = $cx; $startY = $cy;
                        $points[] = $cx * $scaleX;
                        $points[] = $cy * $scaleY;
                        $i += 2;
                        $CMD = $isRel ? 'L' : 'L';
                        $isRel = ctype_lower($cmd) && $cmd !== 'M';
                        break;

                    case 'L':
                        $cx = $isRel ? $cx + ($nums[$i] ?? 0) : ($nums[$i] ?? 0);
                        $cy = $isRel ? $cy + ($nums[$i+1] ?? 0) : ($nums[$i+1] ?? 0);
                        $points[] = $cx * $scaleX;
                        $points[] = $cy * $scaleY;
                        $i += 2;
                        break;

                    case 'H':
                        $cx = $isRel ? $cx + ($nums[$i] ?? 0) : ($nums[$i] ?? 0);
                        $points[] = $cx * $scaleX;
                        $points[] = $cy * $scaleY;
                        $i += 1;
                        break;

                    case 'V':
                        $cy = $isRel ? $cy + ($nums[$i] ?? 0) : ($nums[$i] ?? 0);
                        $points[] = $cx * $scaleX;
                        $points[] = $cy * $scaleY;
                        $i += 1;
                        break;

                    case 'C':
                        $x1 = $isRel ? $cx + ($nums[$i] ?? 0) : ($nums[$i] ?? 0);
                        $y1 = $isRel ? $cy + ($nums[$i+1] ?? 0) : ($nums[$i+1] ?? 0);
                        $x2 = $isRel ? $cx + ($nums[$i+2] ?? 0) : ($nums[$i+2] ?? 0);
                        $y2 = $isRel ? $cy + ($nums[$i+3] ?? 0) : ($nums[$i+3] ?? 0);
                        $ex = $isRel ? $cx + ($nums[$i+4] ?? 0) : ($nums[$i+4] ?? 0);
                        $ey = $isRel ? $cy + ($nums[$i+5] ?? 0) : ($nums[$i+5] ?? 0);
                        $steps = 12;
                        for ($t = 1; $t <= $steps; $t++) {
                            $u = $t / $steps;
                            $u2 = $u * $u; $u3 = $u2 * $u;
                            $iv = 1 - $u; $iv2 = $iv * $iv; $iv3 = $iv2 * $iv;
                            $px = $iv3 * $cx + 3 * $iv2 * $u * $x1 + 3 * $iv * $u2 * $x2 + $u3 * $ex;
                            $py = $iv3 * $cy + 3 * $iv2 * $u * $y1 + 3 * $iv * $u2 * $y2 + $u3 * $ey;
                            $points[] = $px * $scaleX;
                            $points[] = $py * $scaleY;
                        }
                        $cx = $ex; $cy = $ey;
                        $i += 6;
                        break;

                    case 'Q':
                        $x1 = $isRel ? $cx + ($nums[$i] ?? 0) : ($nums[$i] ?? 0);
                        $y1 = $isRel ? $cy + ($nums[$i+1] ?? 0) : ($nums[$i+1] ?? 0);
                        $ex = $isRel ? $cx + ($nums[$i+2] ?? 0) : ($nums[$i+2] ?? 0);
                        $ey = $isRel ? $cy + ($nums[$i+3] ?? 0) : ($nums[$i+3] ?? 0);
                        $steps = 10;
                        for ($t = 1; $t <= $steps; $t++) {
                            $u = $t / $steps;
                            $iv = 1 - $u;
                            $px = $iv * $iv * $cx + 2 * $iv * $u * $x1 + $u * $u * $ex;
                            $py = $iv * $iv * $cy + 2 * $iv * $u * $y1 + $u * $u * $ey;
                            $points[] = $px * $scaleX;
                            $points[] = $py * $scaleY;
                        }
                        $cx = $ex; $cy = $ey;
                        $i += 4;
                        break;

                    case 'S':
                        $x2 = $isRel ? $cx + ($nums[$i] ?? 0) : ($nums[$i] ?? 0);
                        $y2 = $isRel ? $cy + ($nums[$i+1] ?? 0) : ($nums[$i+1] ?? 0);
                        $ex = $isRel ? $cx + ($nums[$i+2] ?? 0) : ($nums[$i+2] ?? 0);
                        $ey = $isRel ? $cy + ($nums[$i+3] ?? 0) : ($nums[$i+3] ?? 0);
                        $x1 = $cx; $y1 = $cy;
                        $steps = 12;
                        for ($t = 1; $t <= $steps; $t++) {
                            $u = $t / $steps;
                            $u2 = $u * $u; $u3 = $u2 * $u;
                            $iv = 1 - $u; $iv2 = $iv * $iv; $iv3 = $iv2 * $iv;
                            $px = $iv3 * $cx + 3 * $iv2 * $u * $x1 + 3 * $iv * $u2 * $x2 + $u3 * $ex;
                            $py = $iv3 * $cy + 3 * $iv2 * $u * $y1 + 3 * $iv * $u2 * $y2 + $u3 * $ey;
                            $points[] = $px * $scaleX;
                            $points[] = $py * $scaleY;
                        }
                        $cx = $ex; $cy = $ey;
                        $i += 4;
                        break;

                    case 'Z':
                        $cx = $startX; $cy = $startY;
                        $points[] = $cx * $scaleX;
                        $points[] = $cy * $scaleY;
                        $i = count($nums);
                        break;

                    default:
                        $i = count($nums);
                        break;
                }
            } while ($i < count($nums));
        }

        return array_map('intval', $points);
    }

    // ── SVG to PNG conversion (tries Imagick, then CLI rsvg-convert, then CLI convert) ──

    private function svgToPng(string $svgXml, int $w, int $h): ?string
    {
        $w = max($w, 10);
        $h = max($h, 10);

        if (extension_loaded('imagick')) {
            try {
                $im = new \Imagick();
                $im->setResolution(150, 150);
                $im->readImageBlob($svgXml);
                $im->setImageFormat('png');
                $im->resizeImage($w, $h, \Imagick::FILTER_LANCZOS, 1, true);
                $im->setImageBackgroundColor(new \ImagickPixel('transparent'));
                $im->setImageAlphaChannel(\Imagick::ALPHACHANNEL_ACTIVATE);
                $data = $im->getImageBlob();
                $im->clear();
                $im->destroy();
                $this->dbg("  svgToPng: imagick OK");
                return $data;
            } catch (\Throwable $e) {
                $this->dbg("  svgToPng: imagick failed: " . substr($e->getMessage(), 0, 80));
            }
        }

        $svgTmp = $this->tempDir . '/svg_' . uniqid() . '.svg';
        $pngTmp = $this->tempDir . '/svg_' . uniqid() . '.png';
        @file_put_contents($svgTmp, $svgXml);

        $rsvg = @shell_exec("which rsvg-convert 2>/dev/null");
        if ($rsvg) {
            $cmd = sprintf('rsvg-convert -w %d -h %d -o %s %s 2>&1', $w, $h, escapeshellarg($pngTmp), escapeshellarg($svgTmp));
            @exec($cmd);
            if (file_exists($pngTmp) && filesize($pngTmp) > 0) {
                $data = @file_get_contents($pngTmp);
                @unlink($svgTmp); @unlink($pngTmp);
                $this->dbg("  svgToPng: rsvg OK");
                return $data;
            }
        }

        $convert = @shell_exec("which convert 2>/dev/null");
        if ($convert) {
            $cmd = sprintf('convert -background none -resize %dx%d %s %s 2>&1', $w, $h, escapeshellarg($svgTmp), escapeshellarg($pngTmp));
            @exec($cmd);
            if (file_exists($pngTmp) && filesize($pngTmp) > 0) {
                $data = @file_get_contents($pngTmp);
                @unlink($svgTmp); @unlink($pngTmp);
                $this->dbg("  svgToPng: convert OK");
                return $data;
            }
        }

        @unlink($svgTmp); @unlink($pngTmp);
        $this->dbg("  svgToPng: no method available");
        return null;
    }

    private function hexToRgba(string $hex, float $alpha = 1.0): string
    {
        $hex = ltrim($hex, '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        $r = hexdec(substr($hex, 0, 2));
        $g = hexdec(substr($hex, 2, 2));
        $b = hexdec(substr($hex, 4, 2));
        if ($alpha >= 1.0) return "rgb($r,$g,$b)";
        return "rgba($r,$g,$b," . round($alpha, 2) . ")";
    }
}
