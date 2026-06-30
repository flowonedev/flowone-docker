<?php

namespace Webmail\Addons\Moodboards\Services;

class MoodBoardPdfService
{
    private const PAGE_W_PT = 841.89;
    private const PAGE_H_PT = 595.28;

    private MoodBoardExportAssets $assets;
    private array $itemMap;

    public function generate(array $board, array $assetMap, array $filePathMap = []): string
    {
        $tempDir = sys_get_temp_dir() . '/mood_pdf_' . uniqid();
        @mkdir($tempDir, 0755, true);
        $this->assets = new MoodBoardExportAssets($assetMap, $tempDir);

        $items = $board['items'] ?? [];
        $this->itemMap = [];
        foreach ($items as $item) {
            $this->itemMap[$item['id']] = $item;
        }

        $slideItems = array_values(array_filter($items, fn($i) => ($i['type'] ?? '') === 'slide'));
        usort($slideItems, fn($a, $b) => ($a['slide_order'] ?? 9999) - ($b['slide_order'] ?? 9999));
        $nonSlideItems = array_filter($items, fn($i) => ($i['type'] ?? '') !== 'slide');

        $bgRgb = MoodBoardExportAssets::parseHexToRgb($board['background_color'] ?? '#f5f5f5');

        $pdf = new \TCPDF('L', 'pt', [self::PAGE_W_PT, self::PAGE_H_PT], true, 'UTF-8', false);
        $pdf->SetCreator('FlowOne');
        $pdf->SetAuthor('FlowOne');
        $pdf->SetTitle($board['name'] ?? 'Moodboard');
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetAutoPageBreak(false, 0);
        $pdf->SetMargins(0, 0, 0);
        $pdf->setCellPaddings(0, 0, 0, 0);

        if (empty($slideItems)) {
            $pdf->AddPage('L', [self::PAGE_W_PT, self::PAGE_H_PT]);
            $this->fillBackground($pdf, $bgRgb);
            $this->renderAllItemsOnPage($pdf, $nonSlideItems);
        } else {
            foreach ($slideItems as $si) {
                $pdf->AddPage('L', [self::PAGE_W_PT, self::PAGE_H_PT]);
                $this->fillBackground($pdf, $bgRgb);
                $this->renderSlide($pdf, $si, $nonSlideItems);
            }
        }

        $outputPath = $this->assets->getTempDir() . '/export.pdf';
        $pdf->Output($outputPath, 'F');
        return $outputPath;
    }

    public function cleanup(): void
    {
        if (!isset($this->assets)) return;
        $dir = $this->assets->getTempDir();
        if (!empty($dir) && is_dir($dir)) {
            $files = glob($dir . '/*');
            foreach ($files as $f) @unlink($f);
            @rmdir($dir);
        }
    }

    // ── Page rendering ──

    private function fillBackground(\TCPDF $pdf, array $rgb): void
    {
        $pdf->SetFillColor($rgb[0], $rgb[1], $rgb[2]);
        $pdf->Rect(0, 0, self::PAGE_W_PT, self::PAGE_H_PT, 'F');
    }

    private function renderSlide(\TCPDF $pdf, array $slideItem, array $nonSlideItems): void
    {
        $sx = (float)($slideItem['pos_x'] ?? 0);
        $sy = (float)($slideItem['pos_y'] ?? 0);
        $sw = (float)($slideItem['width'] ?? 960);
        $sh = (float)($slideItem['height'] ?? 540);

        $scaleX = self::PAGE_W_PT / max($sw, 1);
        $scaleY = self::PAGE_H_PT / max($sh, 1);
        $scale = min($scaleX, $scaleY);

        $offsetX = (self::PAGE_W_PT - $sw * $scale) / 2;
        $offsetY = (self::PAGE_H_PT - $sh * $scale) / 2;

        $visible = $this->collectVisibleItems($nonSlideItems, $sx, $sy, $sw, $sh);
        usort($visible, fn($a, $b) => ($a['z_index'] ?? 0) - ($b['z_index'] ?? 0));

        foreach ($visible as $item) {
            $this->renderItem($pdf, $item, $sx, $sy, $scale, $offsetX, $offsetY);
        }
    }

    private function renderAllItemsOnPage(\TCPDF $pdf, array $items): void
    {
        $filtered = array_filter($items, fn($i) => !($i['style_data']['_hidden'] ?? false));
        if (empty($filtered)) return;

        $minX = PHP_FLOAT_MAX; $minY = PHP_FLOAT_MAX;
        $maxX = PHP_FLOAT_MIN; $maxY = PHP_FLOAT_MIN;
        foreach ($filtered as $item) {
            $x = (float)($item['pos_x'] ?? 0);
            $y = (float)($item['pos_y'] ?? 0);
            $w = max((float)($item['width'] ?? 100), 1);
            $h = max((float)($item['height'] ?? 100), 1);
            $minX = min($minX, $x); $minY = min($minY, $y);
            $maxX = max($maxX, $x + $w); $maxY = max($maxY, $y + $h);
        }

        $canvasW = $maxX - $minX;
        $canvasH = $maxY - $minY;
        $padding = 30;
        $scaleX = (self::PAGE_W_PT - $padding * 2) / max($canvasW, 1);
        $scaleY = (self::PAGE_H_PT - $padding * 2) / max($canvasH, 1);
        $scale = min($scaleX, $scaleY, 1.0);

        $offsetX = (self::PAGE_W_PT - $canvasW * $scale) / 2;
        $offsetY = (self::PAGE_H_PT - $canvasH * $scale) / 2;

        usort($filtered, fn($a, $b) => ($a['z_index'] ?? 0) - ($b['z_index'] ?? 0));
        $filtered = array_values($filtered);

        foreach ($filtered as $item) {
            $this->renderItem($pdf, $item, $minX, $minY, $scale, $offsetX, $offsetY);
        }
    }

    private function collectVisibleItems(array $items, float $sx, float $sy, float $sw, float $sh): array
    {
        $visible = [];
        foreach ($items as $item) {
            if (($item['style_data']['_hidden'] ?? false)) continue;
            $ix = (float)($item['pos_x'] ?? 0);
            $iy = (float)($item['pos_y'] ?? 0);
            $iw = max((float)($item['width'] ?? 100), 1);
            $ih = max((float)($item['height'] ?? 100), 1);
            if ($ix + $iw < $sx || $ix > $sx + $sw) continue;
            if ($iy + $ih < $sy || $iy > $sy + $sh) continue;
            $visible[] = $item;
        }
        return $visible;
    }

    // ── Item renderer ──

    private function renderItem(\TCPDF $pdf, array $item, float $originX, float $originY, float $scale, float $offsetX, float $offsetY): void
    {
        $rawW = max((float)($item['width'] ?? 100), 1);
        $rawH = max((float)($item['height'] ?? 100), 1);

        $px = ((float)($item['pos_x'] ?? 0) - $originX) * $scale + $offsetX;
        $py = ((float)($item['pos_y'] ?? 0) - $originY) * $scale + $offsetY;
        $pw = $rawW * $scale;
        $ph = $rawH * $scale;

        if ($px + $pw < 0 || $py + $ph < 0 || $px > self::PAGE_W_PT || $py > self::PAGE_H_PT) return;
        if ($px < 0) { $pw += $px; $px = 0; }
        if ($py < 0) { $ph += $py; $py = 0; }
        if ($px + $pw > self::PAGE_W_PT) { $pw = self::PAGE_W_PT - $px; }
        if ($py + $ph > self::PAGE_H_PT) { $ph = self::PAGE_H_PT - $py; }
        if ($pw < 1 || $ph < 1) return;

        $type = $item['type'] ?? 'unknown';
        $sd = $item['style_data'] ?? [];
        if (is_string($sd)) $sd = json_decode($sd, true) ?? [];
        if (!is_array($sd)) $sd = [];

        try {
            switch ($type) {
                case 'image':       $this->renderImage($pdf, $item, $sd, $px, $py, $pw, $ph, $scale); break;
                case 'text':        $this->renderText($pdf, $item, $sd, $px, $py, $pw, $ph, $scale); break;
                case 'note':        $this->renderNote($pdf, $item, $sd, $px, $py, $pw, $ph, $scale); break;
                case 'shape':       $this->renderShape($pdf, $item, $sd, $px, $py, $pw, $ph, $scale); break;
                case 'color_swatch':$this->renderColorSwatch($pdf, $item, $sd, $px, $py, $pw, $ph, $scale); break;
                case 'todo_list':   $this->renderTodoList($pdf, $item, $sd, $px, $py, $pw, $ph, $scale); break;
                case 'link':        $this->renderLink($pdf, $item, $sd, $px, $py, $pw, $ph, $scale); break;
                case 'file':        $this->renderFile($pdf, $item, $sd, $px, $py, $pw, $ph, $scale); break;
                case 'image_set':   $this->renderImageSet($pdf, $item, $sd, $px, $py, $pw, $ph, $scale); break;
                case 'frame': case 'column':
                                    $this->renderFrame($pdf, $item, $sd, $px, $py, $pw, $ph, $scale); break;
                case 'table':       $this->renderTable($pdf, $item, $sd, $px, $py, $pw, $ph, $scale); break;
                case 'drawing': case 'pen_shape':
                                    $this->renderDrawing($pdf, $item, $sd, $px, $py, $pw, $ph, $scale); break;
                case 'group': case 'repeat_grid': case 'artboard':
                                    break;
                case 'video': case 'youtube':
                                    $this->renderPlaceholder($pdf, $item, $px, $py, $pw, $ph, $scale, 'Video'); break;
                case 'audio':       $this->renderPlaceholder($pdf, $item, $px, $py, $pw, $ph, $scale, 'Audio'); break;
                default:            $this->renderGeneric($pdf, $item, $sd, $px, $py, $pw, $ph, $scale); break;
            }
        } catch (\Throwable $e) {
            error_log("[MoodPDF] Item {$item['id']} ({$type}) failed: {$e->getMessage()} at {$e->getFile()}:{$e->getLine()}");
        }
    }

    // ── Individual renderers ──

    private function renderImage(\TCPDF $pdf, array $item, array $sd, float $x, float $y, float $w, float $h, float $scale = 1.0): void
    {
        $url = $item['image_url'] ?? $item['thumbnail_url'] ?? '';
        $resolved = $this->assets->resolveBinary($url);
        if (!$resolved) {
            $this->renderPlaceholder($pdf, $item, $x, $y, $w, $h, $scale, 'Image');
            return;
        }

        $binary = $resolved['binary'];
        $mime = $resolved['mime'];
        $ext = match ($mime) { 'image/png' => 'png', 'image/gif' => 'gif', default => 'jpg' };
        $tmpFile = $this->assets->getTempDir() . '/img_' . ($item['id'] ?? uniqid()) . '.' . $ext;
        @file_put_contents($tmpFile, $binary);
        if (!file_exists($tmpFile)) {
            $this->renderPlaceholder($pdf, $item, $x, $y, $w, $h, $scale, 'Image');
            return;
        }

        try {
            $imgSize = @getimagesizefromstring($binary);
            if ($imgSize && $imgSize[0] > 0 && $imgSize[1] > 0) {
                $fit = $sd['image_fit'] ?? $sd['objectFit'] ?? 'cover';
                if ($fit === 'contain') {
                    $fitScale = min($w / $imgSize[0], $h / $imgSize[1]);
                } else {
                    $fitScale = max($w / $imgSize[0], $h / $imgSize[1]);
                }
                $drawW = max(1, $imgSize[0] * $fitScale);
                $drawH = max(1, $imgSize[1] * $fitScale);
                $drawX = $x + ($w - $drawW) / 2;
                $drawY = $y + ($h - $drawH) / 2;

                if ($fit !== 'contain' && ($drawW > $w + 0.5 || $drawH > $h + 0.5)) {
                    $pdf->StartTransform();
                    $pdf->Rect($x, $y, $w, $h, 'CNZ', [], []);
                    $pdf->Image($tmpFile, $drawX, $drawY, $drawW, $drawH, $ext, '', '', false, 150);
                    $pdf->StopTransform();
                } else {
                    $pdf->Image($tmpFile, $drawX, $drawY, $drawW, $drawH, $ext, '', '', false, 150);
                }
            } else {
                $pdf->Image($tmpFile, $x, $y, $w, $h, $ext, '', '', false, 150, '', false, false, 0, 'CM');
            }
        } catch (\Throwable $e) {
            $this->renderPlaceholder($pdf, $item, $x, $y, $w, $h, $scale, 'Image');
        }
    }

    private function renderText(\TCPDF $pdf, array $item, array $sd, float $x, float $y, float $w, float $h, float $scale = 1.0): void
    {
        $rawHtml = $item['content'] ?? '';
        $fontSize = (float)($sd['font_size'] ?? 16);
        $scaledSize = max(4, $fontSize * 0.75 * $scale);
        $fontWeight = $sd['font_weight'] ?? 'normal';
        $fontFamily = MoodBoardExportAssets::mapPdfFont($sd['font_family'] ?? 'Inter');
        $isBold = in_array($fontWeight, ['bold', '700', '600', '800', '900']);
        $isItalic = ($sd['font_style'] ?? 'normal') === 'italic';
        $textAlign = strtolower($sd['text_align'] ?? 'left');
        $textPadding = (float)($sd['text_padding'] ?? 12) * $scale;

        $plainText = strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $rawHtml));
        $textTransform = $sd['text_transform'] ?? 'none';
        if ($textTransform !== 'none') {
            $plainText = MoodBoardExportAssets::applyTextTransform($plainText, $textTransform);
        }
        if (empty(trim($plainText))) return;

        $gradient = MoodBoardExportAssets::parseGradient($sd, 'text');

        if ($gradient) {
            $imgW = (int)max(20, $w * 2);
            $imgH = (int)max(10, $h * 2);
            $pngData = MoodBoardExportAssets::renderGradientTextGd(
                $plainText, $scaledSize * 2, $fontFamily,
                $isBold, $isItalic, $textAlign,
                $imgW, $imgH, $gradient, (int)($textPadding * 2)
            );
            if ($pngData) {
                $tmpFile = $this->assets->getTempDir() . '/gradtext_' . ($item['id'] ?? uniqid()) . '.png';
                @file_put_contents($tmpFile, $pngData);
                if (file_exists($tmpFile)) {
                    try {
                        $pdf->Image($tmpFile, $x, $y, $w, $h, 'PNG', '', '', false, 300, '', false, false, 0, false);
                        return;
                    } catch (\Throwable $e) {}
                }
            }
            $textColor = $gradient['stops'][0]['color'] ?? [31, 41, 55];
        } else {
            $textColor = MoodBoardExportAssets::parseHexToRgb($sd['text_color'] ?? '#1f2937');
        }

        $style = $isBold ? ($isItalic ? 'BI' : 'B') : ($isItalic ? 'I' : '');
        $pdf->SetFont($fontFamily, $style, $scaledSize);
        $pdf->SetTextColor($textColor[0], $textColor[1], $textColor[2]);

        $alignMap = ['center' => 'C', 'right' => 'R', 'justify' => 'J'];
        $align = $alignMap[$textAlign] ?? 'L';

        $pdf->MultiCell(
            $w - $textPadding * 2, 0, $plainText, 0, $align, false, 1,
            $x + $textPadding, $y + $textPadding, true, 0, false, true,
            $h - $textPadding * 2, 'T', false
        );
    }

    private function renderNote(\TCPDF $pdf, array $item, array $sd, float $x, float $y, float $w, float $h, float $scale = 1.0): void
    {
        $bgRgb = MoodBoardExportAssets::parseHexToRgb($item['color'] ?? '#fef3c7');
        $textRgb = MoodBoardExportAssets::getContrastRgb($bgRgb);
        $padding = max(4, 8 * $scale);
        $font = MoodBoardExportAssets::mapPdfFont($sd['font_family'] ?? 'Inter');

        $radius = min(6 * $scale, $w / 2, $h / 2);
        $pdf->SetFillColor($bgRgb[0], $bgRgb[1], $bgRgb[2]);
        if ($radius > 1) {
            $pdf->RoundedRect($x, $y, $w, $h, $radius, '1111', 'F');
        } else {
            $pdf->Rect($x, $y, $w, $h, 'F');
        }

        $pdf->SetTextColor($textRgb[0], $textRgb[1], $textRgb[2]);
        $curY = $y + $padding;

        if (!empty($item['title'])) {
            $pdf->SetFont($font, 'B', max(5, 11 * $scale * 0.75));
            $pdf->MultiCell($w - $padding * 2, 0, $item['title'], 0, 'L', false, 1, $x + $padding, $curY, true, 0, false, true, 0, 'T');
            $curY = $pdf->GetY() + 2;
        }

        $content = strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $item['content'] ?? ''));
        if ($content) {
            $pdf->SetFont($font, '', max(4, 9 * $scale * 0.75));
            $remainH = max(5, $h - ($curY - $y) - $padding);
            $pdf->MultiCell($w - $padding * 2, 0, $content, 0, 'L', false, 1, $x + $padding, $curY, true, 0, false, true, $remainH, 'T');
        }
    }

    private function renderShape(\TCPDF $pdf, array $item, array $sd, float $x, float $y, float $w, float $h, float $scale = 1.0): void
    {
        $maskUrl = $sd['mask_image_url'] ?? null;
        if ($maskUrl) {
            $tmpFile = $this->assets->assetToTempFile($maskUrl, 'mask_' . ($item['id'] ?? ''));
            if ($tmpFile) {
                try { $pdf->Image($tmpFile, $x, $y, $w, $h, '', '', '', false, 150, '', false, false, 0, 'CM'); return; }
                catch (\Throwable $e) {}
            }
        }

        $shapeType = $sd['shape_type'] ?? 'rectangle';
        $borderW = (float)($sd['shape_border_width'] ?? $sd['border_width'] ?? 0) * $scale;
        $isCircle = in_array($shapeType, ['circle', 'ellipse']);
        $radius = (float)($sd['shape_border_radius'] ?? 0) * $scale;

        $gradient = MoodBoardExportAssets::parseGradient($sd, 'shape');

        if ($gradient) {
            $this->renderShapeGradient($pdf, $x, $y, $w, $h, $gradient, $shapeType, $radius, $isCircle);
        } else {
            $bgRgb = MoodBoardExportAssets::parseHexToRgb(
                $sd['shape_fill'] ?? $item['color'] ?? $sd['background_color'] ?? '#6366f1'
            );
            $pdf->SetFillColor($bgRgb[0], $bgRgb[1], $bgRgb[2]);
            $drawStyle = 'F';
            if ($borderW > 0) {
                $borderRgb = MoodBoardExportAssets::parseHexToRgb($sd['shape_border_color'] ?? $sd['border_color'] ?? '#000000');
                $pdf->SetDrawColor($borderRgb[0], $borderRgb[1], $borderRgb[2]);
                $pdf->SetLineWidth($borderW);
                $drawStyle = 'DF';
            }
            $this->drawShapePath($pdf, $x, $y, $w, $h, $shapeType, $radius, $isCircle, $drawStyle);
        }

        if ($borderW > 0 && $gradient) {
            $borderRgb = MoodBoardExportAssets::parseHexToRgb($sd['shape_border_color'] ?? $sd['border_color'] ?? '#000000');
            $pdf->SetDrawColor($borderRgb[0], $borderRgb[1], $borderRgb[2]);
            $pdf->SetLineWidth($borderW);
            $this->drawShapePath($pdf, $x, $y, $w, $h, $shapeType, $radius, $isCircle, 'D');
        }

        $textContent = strip_tags($item['content'] ?? $item['title'] ?? '');
        if (!empty($textContent)) {
            $shapeTextTransform = $sd['shape_text_transform'] ?? 'none';
            if ($shapeTextTransform !== 'none') $textContent = MoodBoardExportAssets::applyTextTransform($textContent, $shapeTextTransform);
            $textRgb = MoodBoardExportAssets::parseHexToRgb($sd['shape_text_color'] ?? '#ffffff');
            $font = MoodBoardExportAssets::mapPdfFont($sd['font_family'] ?? 'Inter');
            $pdf->SetFont($font, '', max(4, ($sd['shape_font_size'] ?? 14) * 0.7 * $scale));
            $pdf->SetTextColor($textRgb[0], $textRgb[1], $textRgb[2]);
            $pdf->MultiCell($w, $h, $textContent, 0, 'C', false, 1, $x, $y, true, 0, false, true, $h, 'M');
        }
    }

    private function drawShapePath(\TCPDF $pdf, float $x, float $y, float $w, float $h, string $shapeType, float $radius, bool $isCircle, string $drawStyle): void
    {
        if ($isCircle) {
            $pdf->Ellipse($x + $w / 2, $y + $h / 2, $w / 2, $h / 2, 0, 0, 360, $drawStyle);
        } elseif ($radius > 0) {
            $pdf->RoundedRect($x, $y, $w, $h, min($radius, $w / 2, $h / 2), '1111', $drawStyle);
        } else {
            $pdf->Rect($x, $y, $w, $h, $drawStyle);
        }
    }

    private function renderShapeGradient(\TCPDF $pdf, float $x, float $y, float $w, float $h, array $gradient, string $shapeType, float $radius, bool $isCircle): void
    {
        $stops = $gradient['stops'] ?? [];
        if (count($stops) < 2) return;
        $firstColor = $stops[0]['color'] ?? [0, 0, 0];
        $lastStop = $stops[count($stops) - 1];
        $lastColor = $lastStop['color'] ?? [0, 0, 0];

        if ($gradient['type'] === 'radial') {
            if ($isCircle) {
                $pdf->StartTransform();
                $pdf->Ellipse($x + $w / 2, $y + $h / 2, $w / 2, $h / 2, 0, 0, 360, 'CNZ');
                $pdf->RadialGradient($x, $y, $w, $h, $firstColor, $lastColor);
                $pdf->StopTransform();
            } else {
                $pdf->RadialGradient($x, $y, $w, $h, $firstColor, $lastColor);
            }
        } else {
            $coords = MoodBoardExportAssets::cssAngleToGradientCoords($gradient['angle']);
            if ($isCircle) {
                $pdf->StartTransform();
                $pdf->Ellipse($x + $w / 2, $y + $h / 2, $w / 2, $h / 2, 0, 0, 360, 'CNZ');
                $pdf->LinearGradient($x, $y, $w, $h, $firstColor, $lastColor, $coords);
                $pdf->StopTransform();
            } elseif ($radius > 0) {
                $pdf->StartTransform();
                $pdf->RoundedRect($x, $y, $w, $h, min($radius, $w / 2, $h / 2), '1111', 'CNZ');
                $pdf->LinearGradient($x, $y, $w, $h, $firstColor, $lastColor, $coords);
                $pdf->StopTransform();
            } else {
                $pdf->LinearGradient($x, $y, $w, $h, $firstColor, $lastColor, $coords);
            }
        }
    }

    private function renderColorSwatch(\TCPDF $pdf, array $item, array $sd, float $x, float $y, float $w, float $h, float $scale = 1.0): void
    {
        $bgRgb = MoodBoardExportAssets::parseHexToRgb($item['color'] ?? '#6366f1');
        $textRgb = MoodBoardExportAssets::getContrastRgb($bgRgb);

        $radius = min(8 * $scale, $w / 2, $h / 2);
        $pdf->SetFillColor($bgRgb[0], $bgRgb[1], $bgRgb[2]);
        if ($radius > 1) {
            $pdf->RoundedRect($x, $y, $w, $h, $radius, '1111', 'F');
        } else {
            $pdf->Rect($x, $y, $w, $h, 'F');
        }

        $font = MoodBoardExportAssets::mapPdfFont('monospace');
        $pdf->SetFont($font, '', max(4, 8 * $scale * 0.75));
        $pdf->SetTextColor($textRgb[0], $textRgb[1], $textRgb[2]);
        $pdf->MultiCell($w, $h, $item['color'] ?? '#6366f1', 0, 'C', false, 1, $x, $y, true, 0, false, true, $h, 'B');
    }

    private function renderTodoList(\TCPDF $pdf, array $item, array $sd, float $x, float $y, float $w, float $h, float $scale = 1.0): void
    {
        $radius = min(6 * $scale, $w / 2, $h / 2);
        $pdf->SetFillColor(255, 255, 255);
        $pdf->SetDrawColor(229, 231, 235);
        $pdf->SetLineWidth(0.5);
        if ($radius > 1) {
            $pdf->RoundedRect($x, $y, $w, $h, $radius, '1111', 'DF');
        } else {
            $pdf->Rect($x, $y, $w, $h, 'DF');
        }

        $padding = max(4, 8 * $scale);
        $curY = $y + $padding;
        $font = MoodBoardExportAssets::mapPdfFont($sd['font_family'] ?? 'Inter');

        if (!empty($item['title'])) {
            $pdf->SetFont($font, 'B', max(4, 10 * $scale * 0.75));
            $pdf->SetTextColor(17, 24, 39);
            $pdf->MultiCell($w - $padding * 2, 0, $item['title'], 0, 'L', false, 1, $x + $padding, $curY, true, 0, false, true, 0, 'T');
            $curY = $pdf->GetY() + 2;
        }

        $todoSize = max(4, 8 * $scale * 0.75);
        foreach ($item['todos'] ?? [] as $todo) {
            if ($curY > $y + $h - $padding) break;
            $done = !empty($todo['completed']);
            $check = $done ? '[x] ' : '[ ] ';
            $pdf->SetFont($font, '', $todoSize);
            $pdf->SetTextColor($done ? 156 : 55, $done ? 163 : 65, $done ? 175 : 81);
            $pdf->MultiCell($w - $padding * 2, 0, $check . ($todo['text'] ?? ''), 0, 'L', false, 1, $x + $padding, $curY, true, 0, false, true, 0, 'T');
            $curY = $pdf->GetY() + 1;
        }
    }

    private function renderLink(\TCPDF $pdf, array $item, array $sd, float $x, float $y, float $w, float $h, float $scale = 1.0): void
    {
        $radius = min(6 * $scale, $w / 2, $h / 2);
        $pdf->SetFillColor(255, 255, 255);
        $pdf->SetDrawColor(229, 231, 235);
        $pdf->SetLineWidth(0.5);
        if ($radius > 1) {
            $pdf->RoundedRect($x, $y, $w, $h, $radius, '1111', 'DF');
        } else {
            $pdf->Rect($x, $y, $w, $h, 'DF');
        }

        $padding = max(4, 8 * $scale);
        $curY = $y + $padding;
        $font = MoodBoardExportAssets::mapPdfFont($sd['font_family'] ?? 'Inter');

        if (!empty($item['title'])) {
            $pdf->SetFont($font, 'B', max(4, 10 * $scale * 0.75));
            $pdf->SetTextColor(17, 24, 39);
            $pdf->MultiCell($w - $padding * 2, 0, $item['title'], 0, 'L', false, 1, $x + $padding, $curY, true, 0, false, true, 0, 'T');
            $curY = $pdf->GetY() + 2;
        }

        if (!empty($item['url'])) {
            $pdf->SetFont($font, '', max(4, 7 * $scale * 0.75));
            $pdf->SetTextColor(99, 102, 241);
            $pdf->MultiCell($w - $padding * 2, 0, $item['url'], 0, 'L', false, 1, $x + $padding, $curY, true, 0, false, true, 0, 'T');
        }
    }

    private function renderFile(\TCPDF $pdf, array $item, array $sd, float $x, float $y, float $w, float $h, float $scale = 1.0): void
    {
        $url = $item['image_url'] ?? $item['thumbnail_url'] ?? '';
        $labelH = max(10, 20 * $scale);
        $placed = false;

        if ($url) {
            $tmpFile = $this->assets->assetToTempFile($url, 'file_' . ($item['id'] ?? ''));
            if ($tmpFile) {
                try { $pdf->Image($tmpFile, $x, $y, $w, max(5, $h - $labelH), '', '', '', false, 150, '', false, false, 0, 'CM'); $placed = true; }
                catch (\Throwable $e) {}
            }
        }

        $labelY = $placed ? ($y + $h - $labelH) : $y;
        $pdf->SetFillColor(249, 250, 251);
        $pdf->Rect($x, $labelY, $w, $placed ? $labelH : $h, 'F');

        $font = MoodBoardExportAssets::mapPdfFont($sd['font_family'] ?? 'Inter');
        $pdf->SetFont($font, '', max(4, 7 * $scale * 0.75));
        $pdf->SetTextColor(55, 65, 81);
        $pdf->MultiCell($w - 8, $placed ? $labelH : $h, $item['title'] ?? 'File', 0, 'L', false, 1, $x + 4, $labelY, true, 0, false, true, $placed ? $labelH : $h, 'M');
    }

    private function renderImageSet(\TCPDF $pdf, array $item, array $sd, float $x, float $y, float $w, float $h, float $scale = 1.0): void
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
            $tmpFile = $this->assets->assetToTempFile($url, 'imgset_' . ($item['id'] ?? '') . '_' . $idx);
            if (!$tmpFile) continue;

            $col = $idx % $cols;
            $row = (int)floor($idx / $cols);
            try { $pdf->Image($tmpFile, $x + $col * $cellW + $gap, $y + $row * $cellH + $gap, $cellW - $gap * 2, $cellH - $gap * 2, '', '', '', false, 150, '', false, false, 0, 'CM'); }
            catch (\Throwable $e) {}
        }
    }

    private function renderFrame(\TCPDF $pdf, array $item, array $sd, float $x, float $y, float $w, float $h, float $scale = 1.0): void
    {
        $gradient = MoodBoardExportAssets::parseGradient($sd, 'frame');
        if ($gradient && !empty($gradient['stops']) && count($gradient['stops']) >= 2) {
            $stops = $gradient['stops'];
            $firstColor = $stops[0]['color'] ?? [0, 0, 0];
            $lastStop = $stops[count($stops) - 1];
            $lastColor = $lastStop['color'] ?? [0, 0, 0];
            if ($gradient['type'] === 'radial') {
                $pdf->RadialGradient($x, $y, $w, $h, $firstColor, $lastColor);
            } else {
                $coords = MoodBoardExportAssets::cssAngleToGradientCoords($gradient['angle'] ?? 180);
                $pdf->LinearGradient($x, $y, $w, $h, $firstColor, $lastColor, $coords);
            }
        } else {
            $bgColor = $sd['fill_color'] ?? $sd['artboard_bg'] ?? $sd['background_color'] ?? null;
            if ($bgColor) {
                $bgRgb = MoodBoardExportAssets::parseHexToRgb($bgColor);
                $pdf->SetFillColor($bgRgb[0], $bgRgb[1], $bgRgb[2]);
                $pdf->Rect($x, $y, $w, $h, 'F');
            }
        }

        $pdf->SetDrawColor(209, 213, 219);
        $pdf->SetLineWidth(0.5);
        $pdf->Rect($x, $y, $w, $h, 'D');

        if (!empty($item['title']) && ($sd['frame_label'] ?? true) !== false) {
            $font = MoodBoardExportAssets::mapPdfFont($sd['font_family'] ?? 'Inter');
            $pdf->SetFont($font, 'B', max(4, 6 * $scale * 0.75));
            $pdf->SetTextColor(156, 163, 175);
            $pdf->Text($x + 4, $y + 4, $item['title']);
        }
    }

    private function renderTable(\TCPDF $pdf, array $item, array $sd, float $x, float $y, float $w, float $h, float $scale = 1.0): void
    {
        $tableData = $item['content'] ?? null;
        if (is_string($tableData)) $tableData = json_decode($tableData, true);
        if (!$tableData || empty($tableData['rows'])) {
            $this->renderGeneric($pdf, $item, $sd, $x, $y, $w, $h, $scale);
            return;
        }

        $rows = $tableData['rows'];
        $numRows = count($rows);
        $numCols = max(1, max(array_map(fn($r) => count($r['cells'] ?? $r), $rows)));
        $cellW = $w / $numCols;
        $cellH = $h / $numRows;
        $fontSize = max(4, 7 * $scale * 0.75);
        $font = MoodBoardExportAssets::mapPdfFont($sd['font_family'] ?? 'Inter');

        foreach ($rows as $ri => $row) {
            $cells = $row['cells'] ?? $row;
            foreach ($cells as $ci => $cell) {
                if ($ci >= $numCols) break;
                $cx = $x + $ci * $cellW;
                $cy = $y + $ri * $cellH;

                if ($ri === 0) { $pdf->SetFillColor(243, 244, 246); $pdf->Rect($cx, $cy, $cellW, $cellH, 'F'); }
                $pdf->SetDrawColor(229, 231, 235);
                $pdf->SetLineWidth(0.3);
                $pdf->Rect($cx, $cy, $cellW, $cellH, 'D');

                $text = is_array($cell) ? ($cell['value'] ?? $cell['text'] ?? '') : ($cell ?? '');
                $pdf->SetFont($font, $ri === 0 ? 'B' : '', $fontSize);
                $pdf->SetTextColor($ri === 0 ? 17 : 55, $ri === 0 ? 24 : 65, $ri === 0 ? 39 : 81);
                $pdf->MultiCell($cellW - 4, $cellH, $text, 0, 'L', false, 1, $cx + 2, $cy, true, 0, false, true, $cellH, 'M');
            }
        }
    }

    private function renderDrawing(\TCPDF $pdf, array $item, array $sd, float $x, float $y, float $w, float $h, float $scale = 1.0): void
    {
        $url = $item['image_url'] ?? '';
        if ($url) {
            $tmpFile = $this->assets->assetToTempFile($url, 'draw_' . ($item['id'] ?? ''));
            if ($tmpFile) {
                try { $pdf->Image($tmpFile, $x, $y, $w, $h, '', '', '', false, 150, '', false, false, 0, 'CM'); return; }
                catch (\Throwable $e) {}
            }
        }

        $content = $item['content'] ?? '';
        $data = is_string($content) ? (@json_decode($content, true) ?? []) : ($content ?? []);
        $strokes = $data['strokes'] ?? [];
        if (empty($strokes)) return;

        $vbW = max((int)($data['width'] ?? $sd['original_width'] ?? $item['width'] ?? 200), 1);
        $vbH = max((int)($data['height'] ?? $sd['original_height'] ?? $item['height'] ?? 150), 1);

        $pngData = MoodBoardExportAssets::renderStrokesGd($strokes, $vbW, $vbH, (int)$w, (int)$h);
        if (!$pngData) return;

        $tmpFile = $this->assets->getTempDir() . '/draw_' . ($item['id'] ?? uniqid()) . '.png';
        @file_put_contents($tmpFile, $pngData);
        if (!file_exists($tmpFile)) return;

        try { $pdf->Image($tmpFile, $x, $y, $w, $h, 'PNG', '', '', false, 150, '', false, false, 0, 'CM'); }
        catch (\Throwable $e) {}
    }

    private function renderGeneric(\TCPDF $pdf, array $item, array $sd, float $x, float $y, float $w, float $h, float $scale = 1.0): void
    {
        $pdf->SetFillColor(249, 250, 251);
        $pdf->SetDrawColor(229, 231, 235);
        $pdf->SetLineWidth(0.5);
        $pdf->Rect($x, $y, $w, $h, 'DF');

        $label = $item['title'] ?? ($item['type'] ?? 'item');
        $font = MoodBoardExportAssets::mapPdfFont($sd['font_family'] ?? 'Inter');
        $pdf->SetFont($font, '', max(4, 8 * $scale * 0.75));
        $pdf->SetTextColor(107, 114, 128);
        $pdf->MultiCell($w, $h, $label, 0, 'C', false, 1, $x, $y, true, 0, false, true, $h, 'M');
    }

    private function renderPlaceholder(\TCPDF $pdf, array $item, float $x, float $y, float $w, float $h, float $scale, string $typeLabel): void
    {
        $pdf->SetFillColor(229, 231, 235);
        $pdf->Rect($x, $y, $w, $h, 'F');

        $font = MoodBoardExportAssets::mapPdfFont('Inter');
        $pdf->SetFont($font, '', max(4, 7 * $scale * 0.75));
        $pdf->SetTextColor(107, 114, 128);
        $pdf->MultiCell($w, $h, $item['title'] ?? $typeLabel, 0, 'C', false, 1, $x, $y, true, 0, false, true, $h, 'M');
    }
}
