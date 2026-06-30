<?php

namespace Collab\Services;

use PhpOffice\PhpPresentation\IOFactory;
use PhpOffice\PhpPresentation\Shape;
use PhpOffice\PhpPresentation\Style;

/**
 * PptxConversionService
 *
 * Converts PHPPresentation objects into the slide/object format
 * understood by the CollabPresentation frontend editor.
 */
class PptxConversionService
{
    private const DEFAULT_IMPORT_WIDTH = 960;
    private const DEFAULT_IMPORT_HEIGHT = 540;
    private const MAX_IMAGE_DIMENSION = 1200;
    private const JPEG_QUALITY = 72;
    private const MIN_OBJECT_SIZE = 20;

    private array $presentationMeta = [
        'aspectRatio' => '16:9',
        'slideWidth' => self::DEFAULT_IMPORT_WIDTH,
        'slideHeight' => self::DEFAULT_IMPORT_HEIGHT,
    ];
    private array $currentSlideImageMeta = [];
    private int $currentSlideImageMetaIndex = 0;

    public function convertFile(string $filePath, string $extension): ?array
    {
        try {
            $sizeMB = round(filesize($filePath) / 1048576, 1);
            error_log("[PptxConversion] Loading {$filePath} ({$sizeMB} MB)");

            // Large PPTX files need more resources
            $prevMemory = ini_get('memory_limit');
            $prevTime   = ini_get('max_execution_time');
            ini_set('memory_limit', '512M');
            set_time_limit(120);

            $phpPresentation = IOFactory::load($filePath);
            $this->capturePresentationMeta($phpPresentation);
            $slideImageMetaMap = $this->extractAllSlideImageMeta($filePath, $extension);

            $slides = [];
            foreach ($phpPresentation->getAllSlides() as $slideIndex => $phpSlide) {
                $this->currentSlideImageMeta = $slideImageMetaMap[$slideIndex] ?? [];
                $this->currentSlideImageMetaIndex = 0;
                $objects = [];
                $zIndex = 0;

                foreach ($phpSlide->getShapeCollection() as $shape) {
                    $extracted = $this->extractShape($shape, $zIndex);
                    foreach ($extracted as $obj) {
                        $objects[] = $obj;
                        $zIndex++;
                    }
                }

                $slides[] = [
                    'id'         => $this->uuid(),
                    'background' => $this->extractBackground($phpSlide),
                    'objects'    => $objects,
                ];
            }

            $this->currentSlideImageMeta = [];
            $this->currentSlideImageMetaIndex = 0;

            error_log("[PptxConversion] Done: " . count($slides) . " slides converted");

            ini_set('memory_limit', $prevMemory);
            set_time_limit((int)$prevTime);

            return $slides;
        } catch (\Throwable $e) {
            error_log("PptxConversionService::convertFile error: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Expose imported slide dimensions so the frontend canvas matches
     * the coordinate space PHPPresentation uses for shapes.
     */
    public function getPresentationMeta(): array
    {
        return $this->presentationMeta;
    }

    private function capturePresentationMeta($phpPresentation): void
    {
        $this->presentationMeta = [
            'aspectRatio' => '16:9',
            'slideWidth' => self::DEFAULT_IMPORT_WIDTH,
            'slideHeight' => self::DEFAULT_IMPORT_HEIGHT,
        ];

        try {
            $layout = $phpPresentation->getLayout();
            $cx = (float) ($layout->getCX() ?? 0);
            $cy = (float) ($layout->getCY() ?? 0);

            if ($cx <= 0 || $cy <= 0) {
                return;
            }

            $ratio = $cx / $cy;
            $height = self::DEFAULT_IMPORT_HEIGHT;
            $width = (int) round($height * $ratio);

            $aspectRatio = '16:9';
            if (abs($ratio - (4 / 3)) < 0.03) {
                $aspectRatio = '4:3';
            }

            $this->presentationMeta = [
                'aspectRatio' => $aspectRatio,
                'slideWidth' => max($width, self::MIN_OBJECT_SIZE),
                'slideHeight' => $height,
            ];

            error_log('[PptxConversion] Import meta: ' . json_encode($this->presentationMeta));
        } catch (\Throwable $e) {
            error_log('[PptxConversion] capturePresentationMeta error: ' . $e->getMessage());
        }
    }

    // ------------------------------------------------------------------
    // Background
    // ------------------------------------------------------------------

    private function extractBackground($phpSlide): array
    {
        try {
            $bg = $phpSlide->getBackground();
            if (!$bg) {
                return ['type' => 'solid', 'value' => '#ffffff'];
            }

            if ($bg instanceof \PhpOffice\PhpPresentation\Slide\Background\Color) {
                $color = $bg->getColor();
                if ($color) {
                    return ['type' => 'solid', 'value' => '#' . $color->getRGB()];
                }
            }
        } catch (\Throwable $e) {
            error_log("extractBackground error: " . $e->getMessage());
        }

        return ['type' => 'solid', 'value' => '#ffffff'];
    }

    // ------------------------------------------------------------------
    // Shape dispatcher — returns an ARRAY of objects (groups expand)
    // ------------------------------------------------------------------

    private function extractShape($shape, int $zIndex): array
    {
        try {
            // Groups: flatten children into the parent coordinate space
            if ($shape instanceof Shape\Group) {
                return $this->extractGroupShapes($shape, $zIndex);
            }

            $base = $this->buildBase($shape, $zIndex);
            if (!$base) {
                return [];
            }

            if ($shape instanceof Shape\RichText) {
                return [$this->buildText($shape, $base)];
            }

            if ($shape instanceof Shape\Drawing\AbstractDrawingAdapter) {
                return [$this->buildImage($shape, $base)];
            }

            if ($shape instanceof Shape\Table) {
                return [$this->buildTable($shape, $base)];
            }

            if ($shape instanceof Shape\AutoShape) {
                return [$this->buildAutoShape($shape, $base)];
            }

            if ($shape instanceof Shape\Line) {
                return [$this->buildLine($shape, $base)];
            }

            return [];
        } catch (\Throwable $e) {
            error_log("extractShape error: " . $e->getMessage());
            return [];
        }
    }

    // ------------------------------------------------------------------
    // Base object shared by all types
    // ------------------------------------------------------------------

    private function buildBase($shape, int $zIndex): ?array
    {
        try {
            $x = round((float) ($shape->getOffsetX() ?? 0));
            $y = round((float) ($shape->getOffsetY() ?? 0));
            $w = round((float) ($shape->getWidth() ?? 0));
            $h = round((float) ($shape->getHeight() ?? 0));

            return [
                'id'       => $this->uuid(),
                'x'        => $x,
                'y'        => $y,
                'width'    => max($w, self::MIN_OBJECT_SIZE),
                'height'   => max($h, self::MIN_OBJECT_SIZE),
                'rotation' => $shape->getRotation() ?? 0,
                'zIndex'   => $zIndex,
            ];
        } catch (\Throwable $e) {
            error_log("buildBase error: " . $e->getMessage());
            return null;
        }
    }

    // ------------------------------------------------------------------
    // Group shapes — recursively flatten children
    // ------------------------------------------------------------------

    private function extractGroupShapes(Shape\Group $group, int $startZ): array
    {
        $results = [];
        $z = $startZ;
        $groupBounds = $this->resolveGroupChildBounds($group);

        foreach ($group->getShapeCollection() as $child) {
            $childObjects = $this->extractShape($child, $z);
            foreach ($childObjects as &$obj) {
                $this->applyGroupTransform($obj, $group, $groupBounds);
                $z++;
            }
            unset($obj);
            $results = array_merge($results, $childObjects);
        }

        return $results;
    }

    private function resolveGroupChildBounds(Shape\Group $group): array
    {
        $minX = null;
        $minY = null;
        $maxX = null;
        $maxY = null;

        foreach ($group->getShapeCollection() as $child) {
            try {
                $childX = (float) ($child->getOffsetX() ?? 0);
                $childY = (float) ($child->getOffsetY() ?? 0);
                $childWidth = (float) ($child->getWidth() ?? 0);
                $childHeight = (float) ($child->getHeight() ?? 0);

                $minX = $minX === null ? $childX : min($minX, $childX);
                $minY = $minY === null ? $childY : min($minY, $childY);
                $maxX = $maxX === null ? ($childX + $childWidth) : max($maxX, $childX + $childWidth);
                $maxY = $maxY === null ? ($childY + $childHeight) : max($maxY, $childY + $childHeight);
            } catch (\Throwable $e) {
                error_log("resolveGroupChildBounds error: " . $e->getMessage());
            }
        }

        $extentX = method_exists($group, 'getExtentX') ? (float) ($group->getExtentX() ?? 0) : 0;
        $extentY = method_exists($group, 'getExtentY') ? (float) ($group->getExtentY() ?? 0) : 0;

        return [
            'minX' => $minX ?? 0.0,
            'minY' => $minY ?? 0.0,
            'width' => $extentX > 0 ? $extentX : max(($maxX ?? 0.0) - ($minX ?? 0.0), 1.0),
            'height' => $extentY > 0 ? $extentY : max(($maxY ?? 0.0) - ($minY ?? 0.0), 1.0),
        ];
    }

    private function applyGroupTransform(array &$obj, Shape\Group $group, array $groupBounds): void
    {
        $groupX = (float) ($group->getOffsetX() ?? 0);
        $groupY = (float) ($group->getOffsetY() ?? 0);
        $groupWidth = max((float) ($group->getWidth() ?? 0), 1.0);
        $groupHeight = max((float) ($group->getHeight() ?? 0), 1.0);
        $innerWidth = max((float) ($groupBounds['width'] ?? 0), 1.0);
        $innerHeight = max((float) ($groupBounds['height'] ?? 0), 1.0);
        $innerMinX = (float) ($groupBounds['minX'] ?? 0);
        $innerMinY = (float) ($groupBounds['minY'] ?? 0);

        $scaleX = $groupWidth / $innerWidth;
        $scaleY = $groupHeight / $innerHeight;

        $obj['x'] = (int) round($groupX + (((float) ($obj['x'] ?? 0)) - $innerMinX) * $scaleX);
        $obj['y'] = (int) round($groupY + (((float) ($obj['y'] ?? 0)) - $innerMinY) * $scaleY);
        $obj['width'] = (int) round(max(self::MIN_OBJECT_SIZE, ((float) ($obj['width'] ?? self::MIN_OBJECT_SIZE)) * $scaleX));
        $obj['height'] = (int) round(max(self::MIN_OBJECT_SIZE, ((float) ($obj['height'] ?? self::MIN_OBJECT_SIZE)) * $scaleY));
    }

    // ------------------------------------------------------------------
    // Text (RichText / Placeholders)
    // ------------------------------------------------------------------

    private function buildText(Shape\RichText $shape, array $base): array
    {
        $base['type'] = 'text';

        $html = '';
        foreach ($shape->getParagraphs() as $para) {
            $html .= '<p>';
            foreach ($para->getRichTextElements() as $el) {
                if ($el instanceof Shape\RichText\BreakElement) {
                    $html .= '<br>';
                    continue;
                }
                $text = htmlspecialchars($el->getText());
                if ($el instanceof Shape\RichText\Run) {
                    $text = $this->wrapRunFormatting($el, $text);
                }
                $html .= $text;
            }
            $html .= '</p>';
        }
        $base['content'] = $html ?: '<p></p>';

        $this->applyFontDefaults($shape, $base);
        $this->applyTextBoxStyles($shape, $base);
        return $base;
    }

    private function wrapRunFormatting(Shape\RichText\Run $run, string $text): string
    {
        try {
            $font = $run->getFont();
            if (!$font) return $text;
            if ($font->isBold())      $text = '<strong>' . $text . '</strong>';
            if ($font->isItalic())     $text = '<em>' . $text . '</em>';
            if ($font->isUnderline())  $text = '<u>' . $text . '</u>';
        } catch (\Throwable $e) {
            // ignore formatting failures
        }
        return $text;
    }

    private function applyFontDefaults(Shape\RichText $shape, array &$base): void
    {
        $base['fontSize']        = 24;
        $base['fontFamily']      = 'Inter';
        $base['color']           = '#000000';
        $base['fontWeight']      = 'normal';
        $base['fontStyle']       = 'normal';
        $base['textAlign']       = 'left';
        $base['backgroundColor'] = 'transparent';

        try {
            $paragraphs = $shape->getParagraphs();
            if (empty($paragraphs)) return;

            $firstPara = $paragraphs[0];
            $elements  = $firstPara->getRichTextElements();

            if (!empty($elements) && $elements[0] instanceof Shape\RichText\Run) {
                $font = $elements[0]->getFont();
                if ($font) {
                    $base['fontSize']   = $font->getSize() ?: 24;
                    $base['fontFamily'] = $font->getName() ?: 'Inter';
                    $base['fontWeight'] = $font->isBold() ? 'bold' : 'normal';
                    $base['fontStyle']  = $font->isItalic() ? 'italic' : 'normal';

                    $rgb = $font->getColor()?->getRGB();
                    if ($rgb) {
                        $base['color'] = '#' . $rgb;
                    }
                }
            }

            $alignment = $firstPara->getAlignment();
            if ($alignment) {
                $map = [
                    Style\Alignment::HORIZONTAL_LEFT    => 'left',
                    Style\Alignment::HORIZONTAL_CENTER  => 'center',
                    Style\Alignment::HORIZONTAL_RIGHT   => 'right',
                    Style\Alignment::HORIZONTAL_JUSTIFY => 'justify',
                ];
                $base['textAlign'] = $map[$alignment->getHorizontal()] ?? 'left';
            }
        } catch (\Throwable $e) {
            // keep defaults
        }
    }

    private function applyTextBoxStyles(Shape\RichText $shape, array &$base): void
    {
        $base['backgroundColor'] = $base['backgroundColor'] ?? 'transparent';
        $base['borderColor'] = 'transparent';
        $base['borderWidth'] = 0;
        $base['borderRadius'] = 0;

        try {
            if (method_exists($shape, 'getFill')) {
                $fill = $shape->getFill();
                $fillColor = $this->resolveColorHex($fill?->getStartColor());
                if ($fillColor) {
                    $base['backgroundColor'] = $fillColor;
                }
            }

            if (method_exists($shape, 'getBorder')) {
                $border = $shape->getBorder();
                $borderColor = $this->resolveColorHex($border?->getColor());
                $borderWidth = max(0, round((float) (($border?->getLineWidth() ?? 0) / 9525)));

                if ($borderColor && $borderWidth > 0) {
                    $base['borderColor'] = $borderColor;
                    $base['borderWidth'] = $borderWidth;
                }
            }
        } catch (\Throwable $e) {
            error_log("applyTextBoxStyles error: " . $e->getMessage());
        }
    }

    // ------------------------------------------------------------------
    // Images (Drawing adapters — Base64 / File / Gd)
    // ------------------------------------------------------------------

    private function buildImage(Shape\Drawing\AbstractDrawingAdapter $shape, array $base): array
    {
        $base['type'] = 'image';
        $base['objectFit'] = 'contain';
        $base['objectPosition'] = '50% 50%';
        $base['imageUrl'] = '';
        $imageMeta = $this->consumeNextImageMeta();

        try {
            $imageData = null;
            $mimeType  = 'image/png';

            if ($shape instanceof Shape\Drawing\Base64) {
                $raw = base64_decode($shape->getData());
                $mimeType = $shape->getMimeType() ?: 'image/png';
                $imageData = $raw;

            } elseif ($shape instanceof Shape\Drawing\Gd) {
                $resource = $shape->getImageResource();
                if ($resource) {
                    $mimeType = $shape->getMimeType() ?: 'image/png';
                    $imageData = $this->gdResourceToString($resource, $mimeType);
                }

            } elseif ($shape instanceof Shape\Drawing\File) {
                $path = $shape->getPath();
                if ($path && file_exists($path)) {
                    $imageData = file_get_contents($path);
                    $mimeType = mime_content_type($path) ?: 'image/png';
                } elseif ($path) {
                    // Embedded images via zip:// protocol
                    $imageData = @file_get_contents($path);
                    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
                    $mimeType = match ($ext) {
                        'jpg', 'jpeg' => 'image/jpeg',
                        'gif'         => 'image/gif',
                        'webp'        => 'image/webp',
                        default       => 'image/png',
                    };
                }
            }

            if ($imageData && strlen($imageData) > 0) {
                [$imageData, $outputMimeType] = $this->compressImage($imageData, $mimeType);
                $base['imageUrl'] = 'data:' . $outputMimeType . ';base64,' . base64_encode($imageData);
            }
        } catch (\Throwable $e) {
            error_log("buildImage error: " . $e->getMessage());
        }

        if (!empty($imageMeta)) {
            $base['objectFit'] = $imageMeta['objectFit'] ?? $base['objectFit'];
            $base['objectPosition'] = $imageMeta['objectPosition'] ?? $base['objectPosition'];

            if (!empty($imageMeta['cornerRadiusRatio'])) {
                $base['borderRadius'] = (int) round(min($base['width'], $base['height']) * (float) $imageMeta['cornerRadiusRatio']);
            }
        }

        return $base;
    }

    private function gdResourceToString($resource, string $mimeType): ?string
    {
        if (!$resource) return null;

        ob_start();
        if (str_contains($mimeType, 'jpeg') || str_contains($mimeType, 'jpg')) {
            imagejpeg($resource, null, self::JPEG_QUALITY);
        } elseif (str_contains($mimeType, 'gif')) {
            imagegif($resource);
        } elseif (str_contains($mimeType, 'webp')) {
            imagewebp($resource, null, self::JPEG_QUALITY);
        } else {
            imagealphablending($resource, false);
            imagesavealpha($resource, true);
            imagepng($resource, null, 6);
        }
        $data = ob_get_clean();
        return $data ?: null;
    }

    /**
     * Downscale and compress images to keep the JSON payload manageable.
     */
    private function compressImage(string $data, string $mimeType): array
    {
        $normalizedMimeType = $this->normalizeImageMimeType($mimeType);

        try {
            $img = @imagecreatefromstring($data);
            if (!$img) {
                return [$data, $normalizedMimeType];
            }

            $w = imagesx($img);
            $h = imagesy($img);
            $targetMimeType = $normalizedMimeType;
            $preserveAlpha = $this->shouldPreserveAlpha($normalizedMimeType);

            if ($preserveAlpha && $w <= self::MAX_IMAGE_DIMENSION && $h <= self::MAX_IMAGE_DIMENSION) {
                imagedestroy($img);
                return [$data, $normalizedMimeType];
            }

            if ($w > self::MAX_IMAGE_DIMENSION || $h > self::MAX_IMAGE_DIMENSION) {
                $ratio = min(self::MAX_IMAGE_DIMENSION / $w, self::MAX_IMAGE_DIMENSION / $h);
                $nw = (int) round($w * $ratio);
                $nh = (int) round($h * $ratio);
                $resized = imagecreatetruecolor($nw, $nh);

                if ($preserveAlpha) {
                    imagealphablending($resized, false);
                    imagesavealpha($resized, true);
                    $transparent = imagecolorallocatealpha($resized, 0, 0, 0, 127);
                    imagefilledrectangle($resized, 0, 0, $nw, $nh, $transparent);
                } else {
                    $white = imagecolorallocate($resized, 255, 255, 255);
                    imagefilledrectangle($resized, 0, 0, $nw, $nh, $white);
                }

                imagecopyresampled($resized, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);
                imagedestroy($img);
                $img = $resized;
            }

            ob_start();
            if ($preserveAlpha) {
                imagealphablending($img, false);
                imagesavealpha($img, true);
                imagepng($img, null, 6);
                $targetMimeType = 'image/png';
            } elseif ($normalizedMimeType === 'image/webp' && function_exists('imagewebp')) {
                imagewebp($img, null, self::JPEG_QUALITY);
            } else {
                imagejpeg($img, null, self::JPEG_QUALITY);
                $targetMimeType = 'image/jpeg';
            }
            $compressed = ob_get_clean();
            imagedestroy($img);

            return [$compressed ?: $data, $compressed ? $targetMimeType : $normalizedMimeType];
        } catch (\Throwable $e) {
            return [$data, $normalizedMimeType];
        }
    }

    private function shouldPreserveAlpha(string $mimeType): bool
    {
        return in_array($mimeType, ['image/png', 'image/gif'], true);
    }

    private function normalizeImageMimeType(string $mimeType): string
    {
        $mimeType = strtolower(trim($mimeType));

        return match ($mimeType) {
            'image/jpg' => 'image/jpeg',
            'image/x-png' => 'image/png',
            'image/apng' => 'image/png',
            default => $mimeType ?: 'image/png',
        };
    }

    private function consumeNextImageMeta(): ?array
    {
        if (!isset($this->currentSlideImageMeta[$this->currentSlideImageMetaIndex])) {
            return null;
        }

        return $this->currentSlideImageMeta[$this->currentSlideImageMetaIndex++] ?? null;
    }

    private function extractAllSlideImageMeta(string $filePath, string $extension): array
    {
        if (strtolower($extension) !== 'pptx' || !class_exists(\ZipArchive::class)) {
            return [];
        }

        $zip = new \ZipArchive();
        if ($zip->open($filePath) !== true) {
            return [];
        }

        $slideMetaMap = [];

        try {
            for ($slideNumber = 1; ; $slideNumber++) {
                $slideXml = $zip->getFromName("ppt/slides/slide{$slideNumber}.xml");
                if ($slideXml === false) {
                    break;
                }

                $slideMetaMap[$slideNumber - 1] = $this->extractSlideImageMetaFromXml((string) $slideXml);
            }
        } catch (\Throwable $e) {
            error_log('[PptxConversion] extractAllSlideImageMeta error: ' . $e->getMessage());
        } finally {
            $zip->close();
        }

        return $slideMetaMap;
    }

    private function extractSlideImageMetaFromXml(string $slideXml): array
    {
        $meta = [];

        try {
            $doc = new \DOMDocument();
            if (!@$doc->loadXML($slideXml)) {
                return [];
            }

            $xpath = new \DOMXPath($doc);
            $xpath->registerNamespace('p', 'http://schemas.openxmlformats.org/presentationml/2006/main');
            $xpath->registerNamespace('a', 'http://schemas.openxmlformats.org/drawingml/2006/main');

            foreach ($xpath->query('//p:pic') as $picNode) {
                $entry = [
                    'objectFit' => 'contain',
                    'objectPosition' => '50% 50%',
                    'cornerRadiusRatio' => 0,
                ];

                $srcRect = $xpath->query('./p:blipFill/a:srcRect', $picNode)->item(0);
                if ($srcRect instanceof \DOMElement) {
                    $left = $this->pptPercentToCss((float) ($srcRect->getAttribute('l') ?: 0));
                    $top = $this->pptPercentToCss((float) ($srcRect->getAttribute('t') ?: 0));
                    $right = $this->pptPercentToCss((float) ($srcRect->getAttribute('r') ?: 0));
                    $bottom = $this->pptPercentToCss((float) ($srcRect->getAttribute('b') ?: 0));

                    if ($left > 0 || $top > 0 || $right > 0 || $bottom > 0) {
                        $entry['objectFit'] = 'cover';
                        $entry['objectPosition'] = $this->buildObjectPositionFromCrop($left, $top, $right, $bottom);
                    }
                }

                $geometry = $xpath->query('./p:spPr/a:prstGeom', $picNode)->item(0);
                if ($geometry instanceof \DOMElement) {
                    $entry['cornerRadiusRatio'] = $this->mapPictureGeometryCornerRatio((string) $geometry->getAttribute('prst'));
                }

                $meta[] = $entry;
            }
        } catch (\Throwable $e) {
            error_log('[PptxConversion] extractSlideImageMetaFromXml error: ' . $e->getMessage());
        }

        return $meta;
    }

    private function pptPercentToCss(float $value): float
    {
        return max(0.0, min(100.0, $value / 1000));
    }

    private function buildObjectPositionFromCrop(float $left, float $top, float $right, float $bottom): string
    {
        $visibleWidth = max(0.01, 100 - $left - $right);
        $visibleHeight = max(0.01, 100 - $top - $bottom);
        $xCenter = $left + ($visibleWidth / 2);
        $yCenter = $top + ($visibleHeight / 2);

        return round($xCenter, 2) . '% ' . round($yCenter, 2) . '%';
    }

    private function mapPictureGeometryCornerRatio(string $preset): float
    {
        $preset = strtolower(trim($preset));

        return match ($preset) {
            'roundrect', 'round1rect', 'round2samerect', 'sniproundrect', 'snip1rect', 'snip2samerect' => 0.08,
            'ellipse' => 0.5,
            default => 0.0,
        };
    }

    private function resolveColorHex($color): ?string
    {
        if (!$color) {
            return null;
        }

        try {
            $rgb = $color->getRGB();
            if (is_string($rgb) && preg_match('/^[A-Fa-f0-9]{6}$/', $rgb)) {
                return '#' . strtoupper($rgb);
            }

            if (method_exists($color, 'getARGB')) {
                $argb = $color->getARGB();
                if (is_string($argb) && preg_match('/^[A-Fa-f0-9]{8}$/', $argb)) {
                    return '#' . strtoupper(substr($argb, 2));
                }
            }
        } catch (\Throwable $e) {
            error_log("resolveColorHex error: " . $e->getMessage());
        }

        return null;
    }

    // ------------------------------------------------------------------
    // Table — flatten to a single text object with HTML table
    // ------------------------------------------------------------------

    private function buildTable(Shape\Table $table, array $base): array
    {
        $base['type'] = 'text';

        $html = '<table>';
        try {
            foreach ($table->getRows() as $row) {
                $html .= '<tr>';
                foreach ($row->getCells() as $cell) {
                    $html .= '<td>';
                    foreach ($cell->getParagraphs() as $para) {
                        foreach ($para->getRichTextElements() as $el) {
                            $html .= htmlspecialchars($el->getText());
                        }
                        $html .= '<br>';
                    }
                    $html .= '</td>';
                }
                $html .= '</tr>';
            }
        } catch (\Throwable $e) {
            error_log("buildTable error: " . $e->getMessage());
        }
        $html .= '</table>';

        $base['content']         = $html;
        $base['fontSize']        = 14;
        $base['fontFamily']      = 'Inter';
        $base['color']           = '#000000';
        $base['fontWeight']      = 'normal';
        $base['fontStyle']       = 'normal';
        $base['textAlign']       = 'left';
        $base['backgroundColor'] = 'transparent';

        return $base;
    }

    // ------------------------------------------------------------------
    // AutoShape (rectangle, ellipse, triangle …)
    // ------------------------------------------------------------------

    private function buildAutoShape(Shape\AutoShape $shape, array $base): array
    {
        $base['type'] = 'shape';

        $map = [
            Shape\AutoShape::TYPE_RECTANGLE         => 'rectangle',
            Shape\AutoShape::TYPE_ROUNDED_RECTANGLE  => 'rectangle',
            Shape\AutoShape::TYPE_ELLIPSE            => 'ellipse',
            Shape\AutoShape::TYPE_TRIANGLE           => 'triangle',
        ];
        $base['shapeType'] = $map[$shape->getType()] ?? 'rectangle';

        try {
            $fill = $shape->getFill();
            $startColor = $fill?->getStartColor();
            $base['fill'] = '#' . ($startColor ? $startColor->getRGB() : '2196F3');

            $border = $shape->getBorder();
            $borderColor = $border?->getColor();
            $base['stroke'] = '#' . ($borderColor ? $borderColor->getRGB() : '1976D2');
            $base['strokeWidth'] = max(1, round(($border?->getLineWidth() ?? 0) / 9525));
        } catch (\Throwable $e) {
            $base['fill'] = '#2196F3';
            $base['stroke'] = '#1976D2';
            $base['strokeWidth'] = 2;
        }

        if ($shape->getType() === Shape\AutoShape::TYPE_ROUNDED_RECTANGLE) {
            $base['borderRadius'] = 10;
        }

        return $base;
    }

    // ------------------------------------------------------------------
    // Line
    // ------------------------------------------------------------------

    private function buildLine(Shape\Line $shape, array $base): array
    {
        $base['type'] = 'shape';
        $base['shapeType'] = 'line';
        $base['fill'] = 'transparent';

        try {
            $border = $shape->getBorder();
            $borderColor = $border?->getColor();
            $base['stroke'] = '#' . ($borderColor ? $borderColor->getRGB() : '000000');
            $base['strokeWidth'] = max(1, round(($border?->getLineWidth() ?? 0) / 9525));
        } catch (\Throwable $e) {
            $base['stroke'] = '#000000';
            $base['strokeWidth'] = 2;
        }

        return $base;
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function uuid(): string
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }
}
