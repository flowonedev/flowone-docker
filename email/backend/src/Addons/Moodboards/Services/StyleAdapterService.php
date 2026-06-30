<?php

namespace App\Addons\Moodboards\Services;

/**
 * StyleAdapterService
 *
 * PHP equivalent of the frontend styleAdapter.js.
 * Converts between legacy type-prefixed style_data and Figma-native format.
 * Used by MoodBoardPptxService, MoodBoardService (token propagation),
 * and the future DB migration script.
 */
class StyleAdapterService
{
    private const FILL_KEY_MAP = [
        'shape'     => ['color' => 'shape_fill', 'type' => 'shape_fill_type', 'gradient' => 'shape_fill_gradient'],
        'pen_shape' => ['color' => 'shape_fill', 'type' => 'shape_fill_type', 'gradient' => 'shape_fill_gradient'],
        'frame'     => ['color' => 'fill_color', 'type' => 'fill_type', 'gradient' => 'fill_gradient'],
        'slide'     => ['color' => 'fill_color', 'type' => 'fill_type', 'gradient' => 'fill_gradient'],
        'text'      => ['color' => 'text_color', 'type' => 'text_fill_type', 'gradient' => 'text_fill_gradient'],
    ];

    private const STROKE_KEY_MAP = [
        'shape'     => ['color' => 'shape_border_color', 'width' => 'shape_border_width'],
        'pen_shape' => ['color' => 'shape_border_color', 'width' => 'shape_border_width'],
        'frame'     => ['color' => 'stroke_color', 'width' => 'stroke_width'],
        'slide'     => ['color' => 'stroke_color', 'width' => 'stroke_width'],
        'text'      => ['color' => 'text_stroke_color', 'width' => 'text_stroke_width'],
        'line'      => ['color' => 'line_color', 'width' => 'line_width'],
    ];

    private const OPACITY_KEY_MAP = [
        'shape'     => 'shape_opacity',
        'pen_shape' => 'shape_opacity',
        'frame'     => 'frame_opacity',
        'text'      => 'text_opacity',
        'image'     => 'image_opacity',
    ];

    private const GLOBALS_KEY_MAP = [
        'shape_fill'         => 'fills.0.color',
        'fill_color'         => 'fills.0.color',
        'text_color'         => 'fills.0.color',
        'shape_border_color' => 'strokes.0.color',
        'stroke_color'       => 'strokes.0.color',
        'text_stroke_color'  => 'strokes.0.color',
        'line_color'         => 'strokes.0.color',
        'shape_text_color'   => '_flowone.shapeTextColor',
        'background_color'   => 'fills.0.color',
        'font_color'         => 'fills.0.color',
        'border_color'       => 'strokes.0.color',
    ];

    /**
     * Check if style_data is in the Figma-native format.
     */
    public static function isFigmaFormat(?array $sd): bool
    {
        return $sd !== null && isset($sd['fills']) && is_array($sd['fills']);
    }

    /**
     * Check if style_data is in legacy format.
     */
    public static function isLegacyFormat(?array $sd): bool
    {
        if (!$sd) return false;
        return !self::isFigmaFormat($sd);
    }

    /**
     * Auto-detect and normalize to Figma-native format.
     */
    public static function normalizeSd(string $itemType, ?array $sd): array
    {
        if (!$sd) return self::emptyStyleData();
        if (self::isFigmaFormat($sd)) return $sd;
        return self::legacyToFigma($itemType, $sd);
    }

    /**
     * Convert legacy style_data to Figma-native.
     */
    public static function legacyToFigma(string $itemType, array $sd): array
    {
        if (self::isFigmaFormat($sd)) return $sd;

        $result = self::emptyStyleData();

        self::convertFills($itemType, $sd, $result);
        self::convertStrokes($itemType, $sd, $result);
        self::convertEffects($itemType, $sd, $result);
        self::convertOpacity($itemType, $sd, $result);
        self::convertCornerRadius($itemType, $sd, $result);
        self::convertBlendMode($sd, $result);
        self::convertGlobals($itemType, $sd, $result);
        self::copyLayoutKeys($sd, $result);

        return $result;
    }

    /**
     * Convert Figma-native back to legacy format.
     */
    public static function figmaToLegacy(string $itemType, array $sd): array
    {
        if (!self::isFigmaFormat($sd)) return $sd;

        $out = [];

        self::writeLegacyFills($itemType, $sd, $out);
        self::writeLegacyStrokes($itemType, $sd, $out);
        self::writeLegacyEffects($itemType, $sd, $out);
        self::writeLegacyOpacity($itemType, $sd, $out);
        self::writeLegacyCornerRadius($itemType, $sd, $out);
        self::writeLegacyBlendMode($sd, $out);
        self::writeLegacyGlobals($itemType, $sd, $out);
        self::copyLayoutKeysReverse($sd, $out);

        return $out;
    }

    public static function emptyStyleData(): array
    {
        return [
            'fills'                => [],
            'strokes'              => [],
            'strokeWeight'         => 0,
            'strokeAlign'          => 'INSIDE',
            'effects'              => [],
            'cornerRadius'         => 0,
            'rectangleCornerRadii' => null,
            'opacity'              => 1.0,
            'blendMode'            => 'NORMAL',
            'text'                 => null,
            'shapeType'            => null,
            'vectorPaths'          => null,
            '_flowone'             => [],
            '_globals'             => [],
        ];
    }

    // ── Fill conversion ──

    private static function convertFills(string $type, array $sd, array &$result): void
    {
        $keys = self::FILL_KEY_MAP[$type] ?? null;
        if (!$keys) return;

        $fillType = $sd[$keys['type']] ?? 'solid';
        $colorHex = $sd[$keys['color']] ?? null;
        $gradient = $sd[$keys['gradient']] ?? null;

        if (($fillType === 'linear' || $fillType === 'radial') && $gradient && count($gradient['stops'] ?? []) >= 2) {
            $stops = array_map(fn($s) => [
                'color'    => self::hexToFigma($s['color'] ?? '#000000'),
                'position' => ($s['position'] ?? 0) / 100,
            ], $gradient['stops']);

            $result['fills'][] = [
                'type'          => $fillType === 'radial' ? 'GRADIENT_RADIAL' : 'GRADIENT_LINEAR',
                'gradientStops' => $stops,
                'gradientAngle' => $gradient['angle'] ?? 180,
                'visible'       => true,
            ];
        } elseif ($colorHex) {
            $result['fills'][] = [
                'type'    => 'SOLID',
                'color'   => self::hexToFigma($colorHex),
                'visible' => true,
            ];
        }
    }

    private static function writeLegacyFills(string $type, array $sd, array &$out): void
    {
        $keys = self::FILL_KEY_MAP[$type] ?? null;
        if (!$keys || empty($sd['fills'])) return;

        $primary = $sd['fills'][0] ?? null;
        if (!$primary) return;

        if (in_array($primary['type'], ['GRADIENT_LINEAR', 'GRADIENT_RADIAL'])) {
            $out[$keys['type']] = $primary['type'] === 'GRADIENT_RADIAL' ? 'radial' : 'linear';
            $out[$keys['gradient']] = [
                'angle' => $primary['gradientAngle'] ?? 180,
                'stops' => array_map(fn($s) => [
                    'color'    => self::figmaToHex($s['color'] ?? []),
                    'position' => round(($s['position'] ?? 0) * 100),
                ], $primary['gradientStops'] ?? []),
            ];
        } elseif ($primary['type'] === 'SOLID') {
            $out[$keys['type']] = 'solid';
            $out[$keys['color']] = self::figmaToHex($primary['color'] ?? []);
        }
    }

    // ── Stroke conversion ──

    private static function convertStrokes(string $type, array $sd, array &$result): void
    {
        $keys = self::STROKE_KEY_MAP[$type] ?? null;
        if (!$keys) return;

        $colorHex = $sd[$keys['color']] ?? null;
        $width = $sd[$keys['width']] ?? 0;

        if ($colorHex && $width > 0) {
            $result['strokes'][] = [
                'type'    => 'SOLID',
                'color'   => self::hexToFigma($colorHex),
                'visible' => true,
            ];
            $result['strokeWeight'] = $width;
        }
    }

    private static function writeLegacyStrokes(string $type, array $sd, array &$out): void
    {
        $keys = self::STROKE_KEY_MAP[$type] ?? null;
        if (!$keys || empty($sd['strokes'])) return;

        $primary = $sd['strokes'][0] ?? null;
        if ($primary && ($primary['color'] ?? null)) {
            $out[$keys['color']] = self::figmaToHex($primary['color']);
            $out[$keys['width']] = $sd['strokeWeight'] ?? 0;
        }
    }

    // ── Effects conversion ──

    private static function convertEffects(string $type, array $sd, array &$result): void
    {
        if (!empty($sd['shadow_enabled'])) {
            $color = self::hexToFigma($sd['shadow_color'] ?? '#000000');
            $opacity = ($sd['shadow_opacity'] ?? 25) / 100;
            $color['a'] = $opacity;
            $result['effects'][] = [
                'type'    => 'DROP_SHADOW',
                'color'   => $color,
                'offset'  => ['x' => $sd['shadow_x'] ?? 0, 'y' => $sd['shadow_y'] ?? 4],
                'radius'  => $sd['shadow_blur'] ?? 8,
                'spread'  => $sd['shadow_spread'] ?? 0,
                'visible' => true,
            ];
        }

        if (!empty($sd['blur_enabled']) && ($sd['blur_amount'] ?? 0) > 0) {
            $result['effects'][] = [
                'type'    => 'LAYER_BLUR',
                'radius'  => $sd['blur_amount'],
                'visible' => true,
            ];
        }

        $bdBlur = 0;
        if (!empty($sd['backdrop_blur_enabled']) && ($sd['backdrop_blur_amount'] ?? 0) > 0) {
            $bdBlur = $sd['backdrop_blur_amount'];
        } elseif (($sd['shape_backdrop_blur'] ?? 0) > 0) {
            $bdBlur = $sd['shape_backdrop_blur'];
        } elseif (($sd['frame_backdrop_blur'] ?? 0) > 0) {
            $bdBlur = $sd['frame_backdrop_blur'];
        }
        if ($bdBlur > 0) {
            $result['effects'][] = [
                'type'    => 'BACKGROUND_BLUR',
                'radius'  => $bdBlur,
                'visible' => true,
            ];
        }
    }

    private static function writeLegacyEffects(string $type, array $sd, array &$out): void
    {
        foreach ($sd['effects'] ?? [] as $e) {
            if (!($e['visible'] ?? false)) continue;

            if (($e['type'] ?? '') === 'DROP_SHADOW' && !isset($out['shadow_enabled'])) {
                $out['shadow_enabled'] = true;
                $hexAlpha = self::figmaToHexAlpha($e['color'] ?? []);
                $out['shadow_color'] = $hexAlpha['hex'];
                $out['shadow_opacity'] = $hexAlpha['opacity100'];
                $out['shadow_x'] = $e['offset']['x'] ?? 0;
                $out['shadow_y'] = $e['offset']['y'] ?? 4;
                $out['shadow_blur'] = $e['radius'] ?? 8;
                $out['shadow_spread'] = $e['spread'] ?? 0;
            }

            if (($e['type'] ?? '') === 'LAYER_BLUR' && !isset($out['blur_enabled'])) {
                $out['blur_enabled'] = true;
                $out['blur_amount'] = $e['radius'] ?? 4;
            }

            if (($e['type'] ?? '') === 'BACKGROUND_BLUR' && !isset($out['backdrop_blur_enabled'])) {
                $out['backdrop_blur_enabled'] = true;
                $out['backdrop_blur_amount'] = $e['radius'] ?? 10;
                if ($type === 'shape' || $type === 'pen_shape') $out['shape_backdrop_blur'] = $e['radius'];
                if ($type === 'frame') $out['frame_backdrop_blur'] = $e['radius'];
            }
        }
    }

    // ── Opacity conversion ──

    private static function convertOpacity(string $type, array $sd, array &$result): void
    {
        $key = self::OPACITY_KEY_MAP[$type] ?? 'opacity';
        $val = $sd[$key] ?? $sd['opacity'] ?? 100;
        $result['opacity'] = ($val < 100) ? round($val / 100, 4) : 1.0;
    }

    private static function writeLegacyOpacity(string $type, array $sd, array &$out): void
    {
        $key = self::OPACITY_KEY_MAP[$type] ?? 'opacity';
        $op = isset($sd['opacity']) ? round($sd['opacity'] * 100) : 100;
        $out[$key] = $op;
    }

    // ── Corner radius conversion ──

    private static function convertCornerRadius(string $type, array $sd, array &$result): void
    {
        if ($type === 'shape' || $type === 'pen_shape') {
            $all = $sd['shape_border_radius'] ?? 0;
            $tl = $sd['shape_border_radius_tl'] ?? null;
            $tr = $sd['shape_border_radius_tr'] ?? null;
            $br = $sd['shape_border_radius_br'] ?? null;
            $bl = $sd['shape_border_radius_bl'] ?? null;
            if ($tl !== null || $tr !== null || $br !== null || $bl !== null) {
                $result['rectangleCornerRadii'] = [$tl ?? $all, $tr ?? $all, $br ?? $all, $bl ?? $all];
            }
            $result['cornerRadius'] = $all;
        } elseif ($type === 'frame' || $type === 'slide') {
            $all = $sd['radius'] ?? $sd['shape_border_radius'] ?? 0;
            $tl = $sd['radius_tl'] ?? $sd['shape_border_radius_tl'] ?? null;
            $tr = $sd['radius_tr'] ?? $sd['shape_border_radius_tr'] ?? null;
            $br = $sd['radius_br'] ?? $sd['shape_border_radius_br'] ?? null;
            $bl = $sd['radius_bl'] ?? $sd['shape_border_radius_bl'] ?? null;
            if ($tl !== null || $tr !== null || $br !== null || $bl !== null) {
                $result['rectangleCornerRadii'] = [$tl ?? $all, $tr ?? $all, $br ?? $all, $bl ?? $all];
            }
            $result['cornerRadius'] = $all;
        } elseif ($type === 'image') {
            $result['cornerRadius'] = $sd['border_radius'] ?? 0;
        }
    }

    private static function writeLegacyCornerRadius(string $type, array $sd, array &$out): void
    {
        if ($type === 'shape' || $type === 'pen_shape') {
            $out['shape_border_radius'] = $sd['cornerRadius'] ?? 0;
            if ($sd['rectangleCornerRadii'] ?? null) {
                [$tl, $tr, $br, $bl] = $sd['rectangleCornerRadii'];
                $out['shape_border_radius_tl'] = $tl;
                $out['shape_border_radius_tr'] = $tr;
                $out['shape_border_radius_br'] = $br;
                $out['shape_border_radius_bl'] = $bl;
            }
        } elseif ($type === 'frame' || $type === 'slide') {
            $out['radius'] = $sd['cornerRadius'] ?? 0;
            if ($sd['rectangleCornerRadii'] ?? null) {
                [$tl, $tr, $br, $bl] = $sd['rectangleCornerRadii'];
                $out['radius_tl'] = $tl; $out['radius_tr'] = $tr;
                $out['radius_br'] = $br; $out['radius_bl'] = $bl;
            }
        } elseif ($type === 'image') {
            $out['border_radius'] = $sd['cornerRadius'] ?? 0;
        }
    }

    // ── Blend mode ──

    private static function convertBlendMode(array $sd, array &$result): void
    {
        $raw = $sd['blend_mode'] ?? null;
        if ($raw && $raw !== 'normal') {
            $result['blendMode'] = strtoupper(str_replace('-', '_', $raw));
        }
    }

    private static function writeLegacyBlendMode(array $sd, array &$out): void
    {
        if (($sd['blendMode'] ?? 'NORMAL') !== 'NORMAL') {
            $out['blend_mode'] = strtolower(str_replace('_', '-', $sd['blendMode']));
        }
    }

    // ── Globals ──

    private static function convertGlobals(string $type, array $sd, array &$result): void
    {
        if (empty($sd['_globals'])) return;
        $newGlobals = [];
        foreach ($sd['_globals'] as $key => $ref) {
            if (!isset($ref['id'])) continue;
            $mapped = self::GLOBALS_KEY_MAP[$key] ?? null;
            if ($mapped) {
                $newGlobals[$mapped] = $ref;
            } elseif ($key === 'text_style' || $key === 'shape_text_style') {
                $newGlobals['text'] = $ref;
            } elseif ($key === 'gradient') {
                $newGlobals['fills.0'] = $ref;
            } else {
                $newGlobals[$key] = $ref;
            }
        }
        $result['_globals'] = $newGlobals;
    }

    private static function writeLegacyGlobals(string $type, array $sd, array &$out): void
    {
        if (empty($sd['_globals'])) return;

        $reverseMap = [
            'shape'     => ['fills.0.color' => 'shape_fill', 'strokes.0.color' => 'shape_border_color'],
            'pen_shape' => ['fills.0.color' => 'shape_fill', 'strokes.0.color' => 'shape_border_color'],
            'frame'     => ['fills.0.color' => 'fill_color', 'strokes.0.color' => 'stroke_color'],
            'slide'     => ['fills.0.color' => 'fill_color', 'strokes.0.color' => 'stroke_color'],
            'text'      => ['fills.0.color' => 'text_color', 'strokes.0.color' => 'text_stroke_color'],
            'line'      => ['strokes.0.color' => 'line_color'],
        ];
        $map = $reverseMap[$type] ?? [];
        $legacy = [];
        foreach ($sd['_globals'] as $key => $ref) {
            if (!isset($ref['id'])) continue;
            $legacyKey = $map[$key] ?? null;
            if ($legacyKey) {
                $legacy[$legacyKey] = $ref;
            } elseif ($key === 'text') {
                $isShape = $type === 'shape' || $type === 'pen_shape';
                $legacy[$isShape ? 'shape_text_style' : 'text_style'] = $ref;
            } elseif ($key === 'fills.0') {
                $legacy['gradient'] = $ref;
            } else {
                $legacy[$key] = $ref;
            }
        }
        if (!empty($legacy)) $out['_globals'] = $legacy;
    }

    // ── Layout keys (pass-through) ──

    private const LAYOUT_KEYS = [
        'auto_layout', 'layout_mode', 'layout_direction', 'layout_gap',
        'layout_align', 'layout_justify', 'layout_wrap',
        'padding', 'padding_top', 'padding_right', 'padding_bottom', 'padding_left',
        'grid_columns', 'grid_rows', 'grid_h_gap', 'grid_v_gap',
        'flex_grow', 'flex_shrink', 'flex_basis', 'align_self',
        'grid_column', 'grid_row', 'clip_content',
        'min_w', 'min_h', 'max_w', 'max_h',
    ];

    private static function copyLayoutKeys(array $sd, array &$result): void
    {
        foreach (self::LAYOUT_KEYS as $key) {
            if (isset($sd[$key])) $result[$key] = $sd[$key];
        }
    }

    private static function copyLayoutKeysReverse(array $sd, array &$out): void
    {
        foreach (self::LAYOUT_KEYS as $key) {
            if (isset($sd[$key])) $out[$key] = $sd[$key];
        }
    }

    // ── Color helpers ──

    public static function hexToFigma(string $hex): array
    {
        $hex = ltrim($hex, '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        $r = (hexdec(substr($hex, 0, 2)) ?: 0) / 255;
        $g = (hexdec(substr($hex, 2, 2)) ?: 0) / 255;
        $b = (hexdec(substr($hex, 4, 2)) ?: 0) / 255;
        $a = strlen($hex) >= 8 ? (hexdec(substr($hex, 6, 2)) ?: 0) / 255 : 1.0;
        return ['r' => round($r, 4), 'g' => round($g, 4), 'b' => round($b, 4), 'a' => round($a, 4)];
    }

    public static function figmaToHex(array $color): string
    {
        $r = (int) round(max(0, min(1, $color['r'] ?? 0)) * 255);
        $g = (int) round(max(0, min(1, $color['g'] ?? 0)) * 255);
        $b = (int) round(max(0, min(1, $color['b'] ?? 0)) * 255);
        return sprintf('#%02x%02x%02x', $r, $g, $b);
    }

    public static function figmaToHexAlpha(array $color): array
    {
        $hex = self::figmaToHex($color);
        $alpha = max(0, min(1, $color['a'] ?? 1));
        return ['hex' => $hex, 'opacity100' => (int) round($alpha * 100)];
    }
}
