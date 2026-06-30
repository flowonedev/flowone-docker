/**
 * styleAdapter.js
 *
 * Bidirectional adapter between legacy type-prefixed style_data keys and the
 * Figma-native schema (fills[], strokes[], effects[], etc.).
 *
 * Legacy keys handled per item type:
 *   Fill:   shape_fill / fill_color / text_color + *_fill_type + *_fill_gradient
 *   Stroke: shape_border_* / stroke_* / text_stroke_* / line_*
 *   Shadow: shadow_* / text_shadow_*
 *   Blur:   blur_* / backdrop_blur_* / shape_backdrop_blur / frame_backdrop_blur
 *   Opacity: shape_opacity / frame_opacity / text_opacity / image_opacity / opacity (0-100)
 *   Radius: shape_border_radius* / radius* / border_radius*
 *   Text:   font_* / shape_font_* / shape_text_*
 */

import { hexToFigma, figmaToHex, figmaToHexAlpha } from './colorConvert'
import {
  PaintType, EffectType, StrokeAlign, BlendMode, ShapeType,
  LEGACY_SHAPE_TYPE_MAP, LEGACY_BLEND_MODE_MAP,
  isFigmaFormat, emptyStyleData,
} from './figmaStyleSchema'

// ── Public API ──

/**
 * Detect whether style_data uses the legacy (flat key) format.
 */
export function isLegacyFormat(sd) {
  if (!sd || typeof sd !== 'object') return false
  return !isFigmaFormat(sd)
}

/**
 * Auto-detect format and always return Figma-native.
 * When already Figma-native, still merges any legacy flat-key effects
 * (blur_enabled, shadow_enabled, etc.) that coexist alongside the
 * effects[] array — the sidebar writes these flat keys, so they must
 * be reflected in the normalised output.
 */
export function normalizeSd(itemType, sd) {
  if (!sd) return emptyStyleData()
  if (isFigmaFormat(sd)) return _patchFigma(itemType, sd)
  return legacyToFigma(itemType, sd)
}

/**
 * Patch a Figma-format sd: sync legacy flat-key fills and effects that
 * coexist alongside the Figma arrays.  The sidebar and canvas renderers
 * sometimes write legacy keys (shape_fill, blur_enabled, etc.) without
 * updating the Figma arrays — this function reconciles both sides.
 */
function _patchFigma(itemType, sd) {
  let result = sd
  result = _syncLegacyFills(itemType, result)
  result = _syncLegacyEffects(result)
  return result
}

const _FILL_LEGACY_KEYS = {
  shape:     'shape_fill',
  pen_shape: 'shape_fill',
  frame:     'fill_color',
  slide:     'fill_color',
  text:      'text_color',
}

function _syncLegacyFills(itemType, sd) {
  const legacyKey = _FILL_LEGACY_KEYS[itemType]
  if (!legacyKey) return sd
  const legacyHex = sd[legacyKey]
  if (!legacyHex) return sd

  const fills = sd.fills || []
  if (!fills.length) return sd

  const firstFill = fills[0]
  if (!firstFill || firstFill.type !== PaintType.SOLID) return sd

  const currentHex = figmaToHex(firstFill.color)
  if (currentHex.toLowerCase().slice(0, 7) === legacyHex.toLowerCase().slice(0, 7)) return sd

  const newFill = { ...firstFill, color: hexToFigma(legacyHex) }
  return { ...sd, fills: [newFill, ...fills.slice(1)] }
}

function _syncLegacyEffects(sd) {
  let effects = sd.effects || []
  let changed = false

  const disableMap = {
    blur_enabled:          EffectType.LAYER_BLUR,
    backdrop_blur_enabled: EffectType.BACKGROUND_BLUR,
    shadow_enabled:        EffectType.DROP_SHADOW,
    text_shadow_enabled:   EffectType.TEXT_SHADOW,
  }
  for (const [key, type] of Object.entries(disableMap)) {
    if (sd[key] === false && effects.some(e => e.type === type)) {
      effects = effects.filter(e => e.type !== type)
      changed = true
    }
  }

  const toAdd = []
  if (sd.blur_enabled && sd.blur_amount > 0 && !effects.some(e => e.type === EffectType.LAYER_BLUR)) {
    toAdd.push({ type: EffectType.LAYER_BLUR, radius: sd.blur_amount, visible: true })
  }
  if (sd.backdrop_blur_enabled && sd.backdrop_blur_amount > 0 && !effects.some(e => e.type === EffectType.BACKGROUND_BLUR)) {
    toAdd.push({ type: EffectType.BACKGROUND_BLUR, radius: sd.backdrop_blur_amount, visible: true })
  }
  if (sd.shadow_enabled && !effects.some(e => e.type === EffectType.DROP_SHADOW)) {
    const c = hexToFigma(sd.shadow_color || '#000000')
    if (sd.shadow_opacity != null) c.a = (sd.shadow_opacity ?? 25) / 100
    toAdd.push({
      type: EffectType.DROP_SHADOW, visible: true, color: c,
      offset: { x: sd.shadow_x ?? 0, y: sd.shadow_y ?? 4 },
      radius: sd.shadow_blur ?? 8, spread: sd.shadow_spread ?? 0,
    })
  }
  if (sd.text_shadow_enabled && !effects.some(e => e.type === EffectType.TEXT_SHADOW)) {
    const c = hexToFigma(sd.text_shadow_color || '#000000')
    if (sd.text_shadow_opacity != null) c.a = (sd.text_shadow_opacity ?? 40) / 100
    toAdd.push({
      type: EffectType.TEXT_SHADOW, visible: true, color: c,
      offset: { x: sd.text_shadow_x ?? 1, y: sd.text_shadow_y ?? 2 },
      radius: sd.text_shadow_blur ?? 4,
    })
  }

  if (!toAdd.length && !changed) return sd
  return { ...sd, effects: [...effects, ...toAdd] }
}

/**
 * Convert legacy style_data to Figma-native format.
 */
export function legacyToFigma(itemType, sd) {
  if (!sd) return emptyStyleData()
  if (isFigmaFormat(sd)) return sd

  const result = emptyStyleData()

  convertFills(itemType, sd, result)
  convertStrokes(itemType, sd, result)
  convertEffects(itemType, sd, result)
  convertOpacity(itemType, sd, result)
  convertCornerRadius(itemType, sd, result)
  convertBlendMode(sd, result)
  convertText(itemType, sd, result)
  convertShapeType(sd, result)
  convertVectorPaths(sd, result)
  convertFlowone(itemType, sd, result)
  convertGlobals(itemType, sd, result)
  copyLayoutKeys(sd, result)

  return result
}

/**
 * Convert Figma-native style_data back to legacy format for a given item type.
 * Used during the transition period for backward compatibility.
 */
export function figmaToLegacy(itemType, sd) {
  if (!sd || !isFigmaFormat(sd)) return sd || {}
  const out = {}

  writeLegacyFills(itemType, sd, out)
  writeLegacyStrokes(itemType, sd, out)
  writeLegacyEffects(itemType, sd, out)
  writeLegacyOpacity(itemType, sd, out)
  writeLegacyCornerRadius(itemType, sd, out)
  writeLegacyBlendMode(sd, out)
  writeLegacyText(itemType, sd, out)
  writeLegacyShapeType(sd, out)
  writeLegacyVectorPaths(sd, out)
  writeLegacyFlowone(sd, out)
  writeLegacyGlobals(itemType, sd, out)
  copyLayoutKeysReverse(sd, out)

  return out
}

/**
 * Convert old _globals keys to path-based keys.
 * e.g. { shape_fill: { type: 'color', id: 'gc-abc' } }
 *    → { 'fills.0.color': { type: 'color', id: 'gc-abc' } }
 */
export function migrateGlobalsKeys(oldGlobals, itemType) {
  if (!oldGlobals || typeof oldGlobals !== 'object') return {}
  const newGlobals = {}

  for (const [key, ref] of Object.entries(oldGlobals)) {
    if (!ref?.id) continue

    if (key === '_item_color') {
      newGlobals._item_color = ref
      continue
    }

    const newKey = GLOBALS_KEY_MAP[key]
    if (newKey) {
      newGlobals[newKey] = ref
    } else if (key === 'text_style' || key === 'shape_text_style') {
      newGlobals.text = ref
    } else if (key === 'gradient') {
      newGlobals['fills.0'] = ref
    } else if (!key.startsWith('fills.') && !key.startsWith('strokes.') && !key.startsWith('effects.')) {
      newGlobals[key] = ref
    } else {
      newGlobals[key] = ref
    }
  }

  return newGlobals
}

// ── Fill conversion ──

const FILL_KEY_MAP = {
  shape: { color: 'shape_fill', type: 'shape_fill_type', gradient: 'shape_fill_gradient' },
  pen_shape: { color: 'shape_fill', type: 'shape_fill_type', gradient: 'shape_fill_gradient' },
  frame: { color: 'fill_color', type: 'fill_type', gradient: 'fill_gradient' },
  slide: { color: 'fill_color', type: 'fill_type', gradient: 'fill_gradient' },
  text: { color: 'text_color', type: 'text_fill_type', gradient: 'text_fill_gradient' },
}

function convertFills(itemType, sd, result) {
  const keys = FILL_KEY_MAP[itemType]
  if (!keys) {
    if (sd.mask_image_url) {
      result._flowone.mask = {
        imageUrl: sd.mask_image_url,
        fit: sd.mask_image_fit || 'cover',
      }
    }
    return
  }

  const fillType = sd[keys.type] || 'solid'
  const colorHex = sd[keys.color]
  const gradient = sd[keys.gradient]

  if (fillType === 'linear' || fillType === 'radial') {
    if (gradient?.stops?.length >= 2) {
      result.fills.push({
        type: fillType === 'radial' ? PaintType.GRADIENT_RADIAL : PaintType.GRADIENT_LINEAR,
        gradientStops: gradient.stops.map(s => ({
          color: hexToFigma(s.color),
          position: (s.position ?? 0) / 100,
        })),
        gradientAngle: gradient.angle ?? 180,
        visible: true,
      })
    }
  } else if (colorHex) {
    result.fills.push({
      type: PaintType.SOLID,
      color: hexToFigma(colorHex),
      visible: true,
    })
  }

  if (sd.mask_image_url) {
    result._flowone.mask = {
      imageUrl: sd.mask_image_url,
      fit: sd.mask_image_fit || 'cover',
    }
  }

  if (itemType === 'text') {
    if (sd.text_clip_image) {
      result._flowone.textClip = {
        imageUrl: sd.text_clip_image,
        imageSize: sd.text_clip_image_size || 'cover',
      }
    }
  }
}

function writeLegacyFills(itemType, sd, out) {
  const keys = FILL_KEY_MAP[itemType]
  if (!keys || !sd.fills?.length) return

  const primary = sd.fills[0]
  if (!primary) return

  if (primary.type === PaintType.GRADIENT_LINEAR || primary.type === PaintType.GRADIENT_RADIAL) {
    out[keys.type] = primary.type === PaintType.GRADIENT_RADIAL ? 'radial' : 'linear'
    out[keys.gradient] = {
      angle: primary.gradientAngle ?? 180,
      stops: (primary.gradientStops || []).map(s => ({
        color: figmaToHex(s.color),
        position: Math.round((s.position ?? 0) * 100),
      })),
    }
    if (primary.gradientStops?.[0]) {
      out[keys.color] = figmaToHex(primary.gradientStops[0].color)
    }
  } else if (primary.type === PaintType.SOLID) {
    out[keys.type] = 'solid'
    out[keys.color] = figmaToHex(primary.color)
  }

  if (sd._flowone?.mask) {
    out.mask_image_url = sd._flowone.mask.imageUrl
    out.mask_image_fit = sd._flowone.mask.fit
  }
  if (sd._flowone?.textClip) {
    out.text_clip_image = sd._flowone.textClip.imageUrl
    out.text_clip_image_size = sd._flowone.textClip.imageSize
  }
}

// ── Stroke conversion ──

const STROKE_KEY_MAP = {
  shape: { color: 'shape_border_color', width: 'shape_border_width', style: 'shape_border_style' },
  pen_shape: { color: 'shape_border_color', width: 'shape_border_width', style: 'shape_border_style' },
  frame: { color: 'stroke_color', width: 'stroke_width', style: 'stroke_style', dash: 'stroke_dash' },
  slide: { color: 'stroke_color', width: 'stroke_width', style: 'stroke_style', dash: 'stroke_dash' },
  text: { color: 'text_stroke_color', width: 'text_stroke_width' },
  line: { color: 'line_color', width: 'line_width', dash: 'line_dash', dashGap: 'line_dash_gap' },
}

function convertStrokes(itemType, sd, result) {
  const keys = STROKE_KEY_MAP[itemType]
  if (!keys) return

  const colorHex = sd[keys.color]
  const width = sd[keys.width]

  if (colorHex && width > 0) {
    const stroke = {
      type: PaintType.SOLID,
      color: hexToFigma(colorHex),
      visible: true,
    }
    const dashVal = sd[keys.dash] || sd[keys.style]
    if (dashVal === 'dashed' || dashVal === 'dotted') {
      stroke.dashPattern = dashVal
    }
    result.strokes.push(stroke)
    result.strokeWeight = width
  }

  if (itemType === 'line') {
    result._flowone.line = {
      x1: sd.line_x1, y1: sd.line_y1,
      x2: sd.line_x2, y2: sd.line_y2,
      arrowStart: sd.line_arrow_start || false,
      arrowEnd: sd.line_arrow_end || false,
      dashGap: sd.line_dash_gap || 0,
      glow: sd.line_glow_enabled ? {
        color: sd.line_glow_color || sd.line_color,
        opacity: sd.line_glow_opacity ?? 60,
        blur: sd.line_glow_blur ?? 6,
      } : null,
    }
  }
}

function writeLegacyStrokes(itemType, sd, out) {
  const keys = STROKE_KEY_MAP[itemType]
  if (!keys) return

  const primary = sd.strokes?.[0]
  if (primary?.color) {
    out[keys.color] = figmaToHex(primary.color)
    out[keys.width] = sd.strokeWeight || 0
    if (primary.dashPattern && keys.dash) {
      out[keys.dash] = primary.dashPattern
    }
    if (primary.dashPattern && keys.style) {
      out[keys.style] = primary.dashPattern
    }
  }

  const line = sd._flowone?.line
  if (line && itemType === 'line') {
    out.line_x1 = line.x1; out.line_y1 = line.y1
    out.line_x2 = line.x2; out.line_y2 = line.y2
    out.line_arrow_start = line.arrowStart
    out.line_arrow_end = line.arrowEnd
    out.line_dash_gap = line.dashGap
    if (line.glow) {
      out.line_glow_enabled = true
      out.line_glow_color = line.glow.color
      out.line_glow_opacity = line.glow.opacity
      out.line_glow_blur = line.glow.blur
    }
  }
}

// ── Effects conversion ──

function convertEffects(itemType, sd, result) {
  if (sd.shadow_enabled) {
    result.effects.push({
      type: EffectType.DROP_SHADOW,
      color: hexToFigma(sd.shadow_color || '#000000'),
      offset: { x: sd.shadow_x ?? 0, y: sd.shadow_y ?? 4 },
      radius: sd.shadow_blur ?? 8,
      spread: sd.shadow_spread ?? 0,
      visible: true,
    })
    if (sd.shadow_opacity != null && sd.shadow_opacity < 100) {
      const last = result.effects[result.effects.length - 1]
      last.color.a = (sd.shadow_opacity ?? 25) / 100
    }
  }

  if (sd.text_shadow_enabled && (itemType === 'text' || !itemType)) {
    result.effects.push({
      type: EffectType.TEXT_SHADOW,
      color: hexToFigma(sd.text_shadow_color || '#000000'),
      offset: { x: sd.text_shadow_x ?? 1, y: sd.text_shadow_y ?? 2 },
      radius: sd.text_shadow_blur ?? 4,
      visible: true,
    })
    if (sd.text_shadow_opacity != null) {
      const last = result.effects[result.effects.length - 1]
      last.color.a = (sd.text_shadow_opacity ?? 40) / 100
    }
  }

  if (sd.blur_enabled && sd.blur_amount > 0) {
    result.effects.push({
      type: EffectType.LAYER_BLUR,
      radius: sd.blur_amount,
      visible: true,
    })
  }

  const bdBlur = sd.backdrop_blur_enabled && sd.backdrop_blur_amount > 0
    ? sd.backdrop_blur_amount
    : (sd.shape_backdrop_blur || sd.frame_backdrop_blur || 0)
  if (bdBlur > 0) {
    result.effects.push({
      type: EffectType.BACKGROUND_BLUR,
      radius: bdBlur,
      visible: true,
    })
  }
}

function writeLegacyEffects(itemType, sd, out) {
  if (!sd.effects?.length) return

  for (const e of sd.effects) {
    if (!e?.visible) continue
    if (e.type === EffectType.DROP_SHADOW) {
      out.shadow_enabled = true
      const { hex, alpha } = figmaToHexAlpha(e.color)
      out.shadow_color = hex
      out.shadow_opacity = Math.round(alpha * 100)
      out.shadow_x = e.offset?.x ?? 0
      out.shadow_y = e.offset?.y ?? 4
      out.shadow_blur = e.radius ?? 8
      out.shadow_spread = e.spread ?? 0
      break
    }
  }

  for (const e of sd.effects) {
    if (!e?.visible) continue
    if (e.type === EffectType.TEXT_SHADOW) {
      out.text_shadow_enabled = true
      const { hex, alpha } = figmaToHexAlpha(e.color)
      out.text_shadow_color = hex
      out.text_shadow_opacity = Math.round(alpha * 100)
      out.text_shadow_x = e.offset?.x ?? 1
      out.text_shadow_y = e.offset?.y ?? 2
      out.text_shadow_blur = e.radius ?? 4
      break
    }
  }

  for (const e of sd.effects) {
    if (!e?.visible) continue
    if (e.type === EffectType.LAYER_BLUR) {
      out.blur_enabled = true
      out.blur_amount = e.radius
      break
    }
  }

  for (const e of sd.effects) {
    if (!e?.visible) continue
    if (e.type === EffectType.BACKGROUND_BLUR) {
      out.backdrop_blur_enabled = true
      out.backdrop_blur_amount = e.radius
      if (itemType === 'shape' || itemType === 'pen_shape') out.shape_backdrop_blur = e.radius
      if (itemType === 'frame') out.frame_backdrop_blur = e.radius
      break
    }
  }
}

// ── Opacity conversion ──

const OPACITY_KEY_MAP = {
  shape: 'shape_opacity',
  pen_shape: 'shape_opacity',
  frame: 'frame_opacity',
  text: 'text_opacity',
  image: 'image_opacity',
}

function convertOpacity(itemType, sd, result) {
  const key = OPACITY_KEY_MAP[itemType] || 'opacity'
  const val = sd[key] ?? sd.opacity
  result.opacity = (val != null && val < 100) ? val / 100 : 1.0
}

function writeLegacyOpacity(itemType, sd, out) {
  const key = OPACITY_KEY_MAP[itemType] || 'opacity'
  const op = sd.opacity != null ? Math.round(sd.opacity * 100) : 100
  out[key] = op
}

// ── Corner radius conversion ──

function convertCornerRadius(itemType, sd, result) {
  if (itemType === 'shape' || itemType === 'pen_shape') {
    const all = sd.shape_border_radius ?? 0
    const tl = sd.shape_border_radius_tl
    const tr = sd.shape_border_radius_tr
    const br = sd.shape_border_radius_br
    const bl = sd.shape_border_radius_bl
    if (tl != null || tr != null || br != null || bl != null) {
      result.rectangleCornerRadii = [tl ?? all, tr ?? all, br ?? all, bl ?? all]
      result.cornerRadius = all
    } else {
      result.cornerRadius = all
    }
  } else if (itemType === 'frame' || itemType === 'slide') {
    const all = sd.radius ?? sd.shape_border_radius ?? 0
    const tl = sd.radius_tl ?? sd.shape_border_radius_tl
    const tr = sd.radius_tr ?? sd.shape_border_radius_tr
    const br = sd.radius_br ?? sd.shape_border_radius_br
    const bl = sd.radius_bl ?? sd.shape_border_radius_bl
    if (tl != null || tr != null || br != null || bl != null) {
      result.rectangleCornerRadii = [tl ?? all, tr ?? all, br ?? all, bl ?? all]
      result.cornerRadius = all
    } else {
      result.cornerRadius = all
    }
  } else if (itemType === 'image') {
    result.cornerRadius = sd.border_radius ?? 0
  }
}

function writeLegacyCornerRadius(itemType, sd, out) {
  if (itemType === 'shape' || itemType === 'pen_shape') {
    out.shape_border_radius = sd.cornerRadius || 0
    if (sd.rectangleCornerRadii) {
      const [tl, tr, br, bl] = sd.rectangleCornerRadii
      out.shape_border_radius_tl = tl
      out.shape_border_radius_tr = tr
      out.shape_border_radius_br = br
      out.shape_border_radius_bl = bl
    }
  } else if (itemType === 'frame' || itemType === 'slide') {
    out.radius = sd.cornerRadius || 0
    if (sd.rectangleCornerRadii) {
      const [tl, tr, br, bl] = sd.rectangleCornerRadii
      out.radius_tl = tl; out.radius_tr = tr
      out.radius_br = br; out.radius_bl = bl
      out.shape_border_radius_tl = tl; out.shape_border_radius_tr = tr
      out.shape_border_radius_br = br; out.shape_border_radius_bl = bl
    }
  } else if (itemType === 'image') {
    out.border_radius = sd.cornerRadius || 0
  }
}

// ── Blend mode conversion ──

function convertBlendMode(sd, result) {
  const raw = sd.blend_mode
  if (raw && raw !== 'normal') {
    result.blendMode = LEGACY_BLEND_MODE_MAP[raw] || BlendMode.NORMAL
  }
}

function writeLegacyBlendMode(sd, out) {
  if (sd.blendMode && sd.blendMode !== BlendMode.NORMAL) {
    out.blend_mode = sd.blendMode.toLowerCase().replace(/_/g, '-')
  }
}

// ── Text properties conversion ──

function convertText(itemType, sd, result) {
  const isShape = itemType === 'shape' || itemType === 'pen_shape'
  const hasText = (isShape ? sd.shape_font_family || sd.shape_font_size : sd.font_family || sd.font_size)
  if (!hasText && itemType !== 'text') return

  const family = isShape ? sd.shape_font_family : sd.font_family
  const weight = isShape ? (sd.shape_font_weight || '400') : (sd.font_weight || '400')
  const size = isShape ? sd.shape_font_size : sd.font_size
  const lh = isShape ? sd.shape_line_height : sd.line_height
  const ls = isShape ? sd.shape_letter_spacing : sd.letter_spacing
  const align = isShape ? sd.shape_text_align : sd.text_align
  const valign = isShape ? sd.shape_text_valign : null
  const transform = isShape ? sd.shape_text_transform : sd.text_transform

  result.text = {
    fontFamily: family || 'Inter',
    fontStyle: weightToStyle(weight),
    fontSize: size || 16,
    fontWeight: parseWeight(weight),
    letterSpacing: ls != null ? { value: ls, unit: 'PIXELS' } : { value: 0, unit: 'PIXELS' },
    lineHeight: lh != null ? (typeof lh === 'number' && lh <= 10
      ? { value: lh * 100, unit: 'PERCENT' }
      : { value: lh, unit: 'PIXELS' }) : { unit: 'AUTO' },
    textAlignHorizontal: (align || 'left').toUpperCase(),
    textAlignVertical: (valign || 'top').toUpperCase(),
    textCase: transformToCase(transform),
    textDecoration: 'NONE',
  }

  if (isShape) {
    const textColor = sd.shape_text_color
    if (textColor) {
      if (!result.fills.length) {
        result.fills.push({ type: PaintType.SOLID, color: hexToFigma(textColor), visible: true })
      }
      result._flowone.shapeTextColor = textColor
    }
  }
}

function writeLegacyText(itemType, sd, out) {
  if (!sd.text) return
  const t = sd.text
  const isShape = itemType === 'shape' || itemType === 'pen_shape'

  const fam = t.fontFamily || 'Inter'
  const wgt = t.fontWeight || 400
  const size = t.fontSize || 16
  const ls = t.letterSpacing?.value ?? 0
  const align = (t.textAlignHorizontal || 'LEFT').toLowerCase()
  const transform = caseToTransform(t.textCase)

  let lh = null
  if (t.lineHeight) {
    if (t.lineHeight.unit === 'PERCENT') lh = t.lineHeight.value / 100
    else if (t.lineHeight.unit === 'PIXELS') lh = t.lineHeight.value
  }

  if (isShape) {
    out.shape_font_family = fam
    out.shape_font_weight = String(wgt)
    out.shape_font_size = size
    if (lh != null) out.shape_line_height = lh
    if (ls) out.shape_letter_spacing = ls
    out.shape_text_align = align
    if (transform && transform !== 'none') out.shape_text_transform = transform
    if (sd._flowone?.shapeTextColor) out.shape_text_color = sd._flowone.shapeTextColor
    else if (t.textAlignVertical) out.shape_text_valign = (t.textAlignVertical || 'TOP').toLowerCase()
  } else {
    out.font_family = fam
    out.font_weight = String(wgt)
    out.font_size = size
    if (lh != null) out.line_height = lh
    if (ls) out.letter_spacing = ls
    out.text_align = align
    if (transform && transform !== 'none') out.text_transform = transform
  }
}

// ── Shape type + vector paths ──

function convertShapeType(sd, result) {
  const raw = sd.shape_type
  if (raw) {
    result.shapeType = LEGACY_SHAPE_TYPE_MAP[raw] || ShapeType.RECTANGLE
  }
}

function writeLegacyShapeType(sd, out) {
  if (!sd.shapeType) return
  const reverse = {
    RECTANGLE: 'rectangle', ELLIPSE: 'circle', TRIANGLE: 'triangle',
    STAR: 'star', POLYGON: 'polygon',
  }
  out.shape_type = reverse[sd.shapeType] || 'rectangle'
}

function convertVectorPaths(sd, result) {
  if (sd.pen_svg_path) {
    result.vectorPaths = [{ windingRule: 'EVENODD', data: sd.pen_svg_path }]
  }
}

function writeLegacyVectorPaths(sd, out) {
  if (sd.vectorPaths?.[0]?.data) {
    out.pen_svg_path = sd.vectorPaths[0].data
  }
}

// ── FlowOne custom extensions ──

function convertFlowone(itemType, sd, result) {
  const fl = result._flowone

  if (sd.audio_volume != null || sd.audio_loop != null) {
    fl.audio = {
      volume: sd.audio_volume ?? 80,
      loop: sd.audio_loop ?? false,
      autoplay: sd.audio_autoplay ?? false,
      accent: sd.audio_accent,
      bg: sd.audio_bg,
      text: sd.audio_text,
    }
  }

  if (sd.slide_order != null || sd.transition_type) {
    fl.presentation = {
      slideOrder: sd.slide_order,
      transitionType: sd.transition_type,
      transitionDuration: sd.transition_duration,
    }
  }

  if (sd._hidden != null) fl.hidden = sd._hidden
  if (sd._youtubeInteractive != null) fl.youtubeInteractive = sd._youtubeInteractive
  if (sd.poster) fl.poster = sd.poster
  if (sd.frame_device) fl.frameDevice = sd.frame_device
  if (sd.frame_label != null) fl.frameLabel = sd.frame_label
  if (sd.preset_name) fl.presetName = sd.preset_name
  if (sd.artboard_bg) fl.artboardBg = sd.artboard_bg
  if (sd.clip_content != null) fl.clipContent = sd.clip_content
  if (sd.audio_hidden_in_pres) fl.audioHiddenInPres = true
  if (sd.audio_bg) fl.audioBg = sd.audio_bg
  if (sd.boolean_op) fl.booleanOp = true

  if (sd.mask_parent_id) fl.maskParentId = sd.mask_parent_id
  if (sd.mask_offset_x != null) fl.maskOffsetX = sd.mask_offset_x
  if (sd.mask_offset_y != null) fl.maskOffsetY = sd.mask_offset_y

  if (sd.item_scale != null && sd.item_scale !== 1) fl.itemScale = sd.item_scale
  if (sd.flip_x) fl.flipX = true
  if (sd.flip_y) fl.flipY = true

  if (sd.is_background) fl.isBackground = true
  if (sd.original_width) fl.originalWidth = sd.original_width
  if (sd.original_height) fl.originalHeight = sd.original_height
  if (sd.image_fit) fl.imageFit = sd.image_fit
}

function writeLegacyFlowone(sd, out) {
  const fl = sd._flowone || {}

  if (fl.audio) {
    out.audio_volume = fl.audio.volume
    out.audio_loop = fl.audio.loop
    out.audio_autoplay = fl.audio.autoplay
    if (fl.audio.accent) out.audio_accent = fl.audio.accent
    if (fl.audio.bg) out.audio_bg = fl.audio.bg
    if (fl.audio.text) out.audio_text = fl.audio.text
  }

  if (fl.presentation) {
    if (fl.presentation.slideOrder != null) out.slide_order = fl.presentation.slideOrder
    if (fl.presentation.transitionType) out.transition_type = fl.presentation.transitionType
    if (fl.presentation.transitionDuration) out.transition_duration = fl.presentation.transitionDuration
  }

  if (fl.hidden != null) out._hidden = fl.hidden
  if (fl.youtubeInteractive != null) out._youtubeInteractive = fl.youtubeInteractive
  if (fl.poster) out.poster = fl.poster
  if (fl.frameDevice) out.frame_device = fl.frameDevice
  if (fl.frameLabel != null) out.frame_label = fl.frameLabel
  if (fl.presetName) out.preset_name = fl.presetName
  if (fl.artboardBg) out.artboard_bg = fl.artboardBg
  if (fl.clipContent != null) out.clip_content = fl.clipContent
  if (fl.audioHiddenInPres) out.audio_hidden_in_pres = true
  if (fl.booleanOp) out.boolean_op = true

  if (fl.maskParentId) out.mask_parent_id = fl.maskParentId
  if (fl.maskOffsetX != null) out.mask_offset_x = fl.maskOffsetX
  if (fl.maskOffsetY != null) out.mask_offset_y = fl.maskOffsetY

  if (fl.itemScale != null) out.item_scale = fl.itemScale
  if (fl.flipX) out.flip_x = true
  if (fl.flipY) out.flip_y = true

  if (fl.isBackground) out.is_background = true
  if (fl.originalWidth) out.original_width = fl.originalWidth
  if (fl.originalHeight) out.original_height = fl.originalHeight
  if (fl.imageFit) out.image_fit = fl.imageFit
}

// ── Globals conversion ──

const GLOBALS_KEY_MAP = {
  shape_fill: 'fills.0.color',
  fill_color: 'fills.0.color',
  text_color: 'fills.0.color',
  shape_border_color: 'strokes.0.color',
  stroke_color: 'strokes.0.color',
  text_stroke_color: 'strokes.0.color',
  line_color: 'strokes.0.color',
  shape_text_color: '_flowone.shapeTextColor',
  background_color: 'fills.0.color',
  font_color: 'fills.0.color',
  border_color: 'strokes.0.color',
}

const GLOBALS_REVERSE_MAP = {
  shape: {
    'fills.0.color': 'shape_fill',
    'strokes.0.color': 'shape_border_color',
    '_flowone.shapeTextColor': 'shape_text_color',
  },
  pen_shape: {
    'fills.0.color': 'shape_fill',
    'strokes.0.color': 'shape_border_color',
  },
  frame: {
    'fills.0.color': 'fill_color',
    'strokes.0.color': 'stroke_color',
  },
  slide: {
    'fills.0.color': 'fill_color',
    'strokes.0.color': 'stroke_color',
  },
  text: {
    'fills.0.color': 'text_color',
    'strokes.0.color': 'text_stroke_color',
  },
  line: {
    'strokes.0.color': 'line_color',
  },
}

function convertGlobals(itemType, sd, result) {
  if (!sd._globals) return
  result._globals = migrateGlobalsKeys(sd._globals, itemType)
  if (sd._overrides) {
    result._flowone._overrides = sd._overrides
  }
}

function writeLegacyGlobals(itemType, sd, out) {
  if (!sd._globals || !Object.keys(sd._globals).length) return
  const reverseMap = GLOBALS_REVERSE_MAP[itemType] || {}
  const legacyGlobals = {}

  for (const [key, ref] of Object.entries(sd._globals)) {
    if (!ref?.id) continue
    const legacyKey = reverseMap[key]
    if (legacyKey) {
      legacyGlobals[legacyKey] = ref
    } else if (key === 'text' || key === 'shape_text_style') {
      const isShape = itemType === 'shape' || itemType === 'pen_shape'
      legacyGlobals[isShape ? 'shape_text_style' : 'text_style'] = ref
    } else if (key === 'fills.0') {
      legacyGlobals.gradient = ref
    } else {
      legacyGlobals[key] = ref
    }
  }

  if (Object.keys(legacyGlobals).length) out._globals = legacyGlobals
  if (sd._flowone?._overrides) out._overrides = sd._flowone._overrides
}

// ── Layout keys (pass-through, already close to standard) ──

const LAYOUT_KEYS = [
  'auto_layout', 'layout_mode', 'layout_direction', 'layout_gap',
  'layout_align', 'layout_justify', 'layout_wrap',
  'padding', 'padding_top', 'padding_right', 'padding_bottom', 'padding_left',
  'margin_top', 'margin_right', 'margin_bottom', 'margin_left',
  'grid_columns', 'grid_rows', 'grid_h_gap', 'grid_v_gap',
  'grid_align_items', 'grid_justify_items',
  'flex_grow', 'flex_shrink', 'flex_basis', 'align_self', 'justify_self',
  'grid_column', 'grid_row',
  'constraint_h', 'constraint_v',
  'sizing_h', 'sizing_v',
  'min_w', 'min_h', 'max_w', 'max_h',
  'group_id',
]

function copyLayoutKeys(sd, result) {
  for (const key of LAYOUT_KEYS) {
    if (sd[key] != null) result[key] = sd[key]
  }
}

function copyLayoutKeysReverse(sd, out) {
  for (const key of LAYOUT_KEYS) {
    if (sd[key] != null) out[key] = sd[key]
  }
}

// ── Text helper utilities ──

function parseWeight(w) {
  if (typeof w === 'number') return w
  const n = parseInt(w)
  if (!isNaN(n)) return n
  const map = { thin: 100, light: 300, regular: 400, medium: 500, semibold: 600, bold: 700, extrabold: 800, black: 900 }
  return map[(w || '').toLowerCase()] || 400
}

function weightToStyle(w) {
  const n = parseWeight(w)
  if (n >= 700) return 'Bold'
  if (n >= 600) return 'Semi Bold'
  if (n >= 500) return 'Medium'
  if (n <= 300) return 'Light'
  return 'Regular'
}

function transformToCase(transform) {
  if (!transform || transform === 'none') return 'ORIGINAL'
  const map = { uppercase: 'UPPER', lowercase: 'LOWER', capitalize: 'TITLE' }
  return map[transform] || 'ORIGINAL'
}

function caseToTransform(textCase) {
  if (!textCase || textCase === 'ORIGINAL') return 'none'
  const map = { UPPER: 'uppercase', LOWER: 'lowercase', TITLE: 'capitalize' }
  return map[textCase] || 'none'
}
