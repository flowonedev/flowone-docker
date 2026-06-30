import { Graphics, Text, FillGradient, Color, Texture } from 'pixi.js'
import { isFigmaFormat } from '../../utils/figmaStyleSchema.js'
import { samplePerimeter } from './dashedStroke.js'

/**
 * Converts style_data (Figma format OR legacy format) to PixiJS visual properties.
 * Handles both the new Figma-native schema (fills[], strokes[], effects[])
 * and the legacy flat-key format (shape_fill, fill_color, shadow_enabled, etc.).
 */

/**
 * Normalize legacy style_data to a Figma-like structure inline.
 * Avoids importing the full styleAdapter to keep this module fast and tree-shakeable.
 */
function normalizeLegacyToFigma(itemType, sd) {
  const fills = []
  const strokes = []
  const effects = []
  let opacity = 1
  let cornerRadius = 0
  let rectangleCornerRadii = null
  let blendMode = 'NORMAL'

  const fillKeyMap = {
    shape: 'shape_fill', pen_shape: 'shape_fill',
    frame: 'fill_color', slide: 'fill_color', text: 'text_color',
  }
  const fillTypeKey = {
    shape: 'shape_fill_type', pen_shape: 'shape_fill_type',
    frame: 'fill_type', slide: 'fill_type', text: 'text_fill_type',
  }
  const gradientKey = {
    shape: 'shape_fill_gradient', pen_shape: 'shape_fill_gradient',
    frame: 'fill_gradient', slide: 'fill_gradient', text: 'text_fill_gradient',
  }

  let colorHex = sd[fillKeyMap[itemType]]
  if (!colorHex && (itemType === 'frame' || itemType === 'slide')) {
    colorHex = sd.artboard_bg || sd.background_color
  }
  const fillType = sd[fillTypeKey[itemType]] || 'solid'
  const gradient = sd[gradientKey[itemType]]

  if (fillType === 'linear' || fillType === 'radial') {
    if (gradient?.stops?.length >= 2) {
      fills.push({
        type: fillType === 'radial' ? 'GRADIENT_RADIAL' : 'GRADIENT_LINEAR',
        gradientStops: gradient.stops.map(s => ({
          color: s.color, position: (s.position ?? 0) / 100,
        })),
        angle: gradient.angle ?? 180,
        visible: true,
      })
    }
  } else if (colorHex) {
    fills.push({ type: 'SOLID', color: colorHex, visible: true })
  }

  const strokeKeyMap = {
    shape: ['shape_border_color', 'shape_border_width', 'shape_border_style'],
    pen_shape: ['shape_border_color', 'shape_border_width', 'shape_border_style'],
    frame: ['stroke_color', 'stroke_width', 'stroke_style'],
    slide: ['stroke_color', 'stroke_width', 'stroke_style'],
    text: ['text_stroke_color', 'text_stroke_width'],
    line: ['line_color', 'line_width'],
  }
  const sk = strokeKeyMap[itemType]
  if (sk && sd[sk[0]] && (sd[sk[1]] || 0) > 0) {
    const strokeObj = { type: 'SOLID', color: sd[sk[0]], weight: sd[sk[1]] || 1, visible: true }
    const dashVal = sk[2] ? sd[sk[2]] : undefined
    if (dashVal === 'dashed' || dashVal === 'dotted') strokeObj.dashPattern = dashVal
    strokes.push(strokeObj)
  }

  if (sd.shadow_enabled) {
    const c = sd.shadow_color || '#000000'
    const sAlpha = (sd.shadow_opacity ?? 25) / 100
    effects.push({
      type: 'DROP_SHADOW', visible: true, color: c,
      offset: { x: sd.shadow_x ?? 0, y: sd.shadow_y ?? 4 },
      radius: sd.shadow_blur ?? 8, spread: sd.shadow_spread ?? 0,
      _alpha: sAlpha,
    })
  }
  if (sd.blur_enabled) {
    effects.push({ type: 'LAYER_BLUR', visible: true, radius: sd.blur_amount || 0 })
  }
  const bdBlur = sd.backdrop_blur_enabled && sd.backdrop_blur_amount > 0
    ? sd.backdrop_blur_amount
    : (sd.shape_backdrop_blur || sd.frame_backdrop_blur || 0)
  if (bdBlur > 0) {
    effects.push({ type: 'BACKGROUND_BLUR', visible: true, radius: bdBlur })
  }

  const opacityKeyMap = { shape: 'shape_opacity', pen_shape: 'shape_opacity', frame: 'frame_opacity', text: 'text_opacity', image: 'image_opacity' }
  const opKey = opacityKeyMap[itemType] || 'opacity'
  const opVal = sd[opKey] ?? sd.opacity
  opacity = (opVal != null && opVal <= 100) ? opVal / 100 : (opVal != null && opVal > 1 ? opVal / 100 : opVal ?? 1)

  if (itemType === 'shape' || itemType === 'pen_shape') {
    cornerRadius = sd.shape_border_radius ?? 0
    const tl = sd.shape_border_radius_tl ?? sd.border_radius_tl
    if (tl != null) rectangleCornerRadii = [tl, sd.shape_border_radius_tr ?? sd.border_radius_tr ?? 0, sd.shape_border_radius_br ?? sd.border_radius_br ?? 0, sd.shape_border_radius_bl ?? sd.border_radius_bl ?? 0]
  } else if (itemType === 'frame' || itemType === 'slide') {
    cornerRadius = sd.radius ?? sd.shape_border_radius ?? 0
    const tl = sd.radius_tl ?? sd.shape_border_radius_tl
    if (tl != null) rectangleCornerRadii = [tl, sd.radius_tr ?? sd.shape_border_radius_tr ?? 0, sd.radius_br ?? sd.shape_border_radius_br ?? 0, sd.radius_bl ?? sd.shape_border_radius_bl ?? 0]
  } else if (itemType === 'image') {
    cornerRadius = sd.border_radius ?? 12
  }

  if (sd.blend_mode && sd.blend_mode !== 'normal') {
    blendMode = sd.blend_mode.toUpperCase().replace(/-/g, '_')
  }

  return { fills, strokes, effects, opacity, cornerRadius, rectangleCornerRadii, blendMode, shapeType: sd.shape_type }
}

/**
 * Auto-detect legacy vs Figma-native and return normalized fill/stroke/effects.
 */
export function getStyleProps(itemType, sd) {
  if (!sd) return { fills: [], strokes: [], effects: [], opacity: 1, cornerRadius: 0, blendMode: 'NORMAL', shapeType: null }
  if (isFigmaFormat(sd)) {
    const result = { ...sd }
    const hasVisualData = sd.fills.length > 0 || sd.strokes?.length > 0

    if (!hasVisualData) {
      const legacyFillKeys = ['shape_fill', 'fill_color', 'text_color', 'artboard_bg', 'background_color']
      const hasLegacyFill = legacyFillKeys.some(k => sd[k])
      if (hasLegacyFill) {
        const normalized = normalizeLegacyToFigma(itemType, sd)
        Object.assign(result, normalized)
        if (normalized.fills.length) result.fills = normalized.fills
      }
    }

    const legacyEffects = _extractLegacyEffects(sd)
    if (legacyEffects.length) {
      result.effects = [...(result.effects || []), ...legacyEffects]
    }
    return result
  }
  return normalizeLegacyToFigma(itemType, sd)
}

function _extractLegacyEffects(sd) {
  const fx = []
  if (sd.blur_enabled) {
    const hasLayerBlur = sd.effects?.some(e => e.type === 'LAYER_BLUR')
    if (!hasLayerBlur) fx.push({ type: 'LAYER_BLUR', visible: true, radius: sd.blur_amount || 0 })
  }
  if (sd.shadow_enabled) {
    const hasShadow = sd.effects?.some(e => e.type === 'DROP_SHADOW')
    if (!hasShadow) {
      const c = sd.shadow_color || '#000000'
      const sAlpha = (sd.shadow_opacity ?? 25) / 100
      fx.push({
        type: 'DROP_SHADOW', visible: true, color: c,
        offset: { x: sd.shadow_x ?? 0, y: sd.shadow_y ?? 4 },
        radius: sd.shadow_blur ?? 8, spread: sd.shadow_spread ?? 0,
        _alpha: sAlpha,
      })
    }
  }
  const bdBlur = sd.backdrop_blur_enabled && sd.backdrop_blur_amount > 0
    ? sd.backdrop_blur_amount
    : (sd.shape_backdrop_blur || sd.frame_backdrop_blur || 0)
  if (bdBlur > 0) {
    const hasBgBlur = sd.effects?.some(e => e.type === 'BACKGROUND_BLUR')
    if (!hasBgBlur) fx.push({ type: 'BACKGROUND_BLUR', visible: true, radius: bdBlur })
  }
  return fx
}

export function parseColor(color) {
  if (!color) return { color: 0x000000, alpha: 1 }
  if (typeof color === 'string') {
    if (color.startsWith('#')) {
      const hex = parseInt(color.slice(1), 16)
      return { color: hex, alpha: 1 }
    }
    if (color.startsWith('rgb')) {
      const m = color.match(/rgba?\((\d+),\s*(\d+),\s*(\d+)(?:,\s*([\d.]+))?\)/)
      if (m) {
        const c = (parseInt(m[1]) << 16) | (parseInt(m[2]) << 8) | parseInt(m[3])
        return { color: c, alpha: parseFloat(m[4] ?? '1') }
      }
    }
    return { color: 0x000000, alpha: 1 }
  }
  if (typeof color === 'object' && 'r' in color) {
    const r = Math.round((color.r ?? 0) * 255)
    const g = Math.round((color.g ?? 0) * 255)
    const b = Math.round((color.b ?? 0) * 255)
    return { color: (r << 16) | (g << 8) | b, alpha: color.a ?? 1 }
  }
  return { color: 0x000000, alpha: 1 }
}

function convertFill(fill) {
  if (!fill) return null
  if (fill.type === 'SOLID') {
    const { color, alpha } = parseColor(fill.color)
    return { type: 'solid', color, alpha: alpha * (fill.opacity ?? 1) }
  }
  if (fill.type === 'GRADIENT_LINEAR' || fill.type === 'GRADIENT_RADIAL'
    || fill.type === 'GRADIENT_ANGULAR' || fill.type === 'GRADIENT_DIAMOND') {
    return buildGradientFill(fill)
  }
  if (fill.type === 'IMAGE' && fill.imageUrl) {
    return { type: 'image', url: fill.imageUrl, opacity: fill.opacity ?? 1 }
  }
  return null
}

/** Topmost visible fill (back-compat single-fill API). */
export function getFillStyle(fills) {
  const all = getFillStyles(fills)
  return all.length ? all[all.length - 1] : null
}

/** All visible fills, bottom-to-top draw order (parity with stacked CSS backgrounds). */
export function getFillStyles(fills) {
  if (!fills || !fills.length) return []
  return fills
    .filter(f => f.visible !== false)
    .map(convertFill)
    .filter(Boolean)
}

function buildGradientFill(fill) {
  if (!fill.gradientStops?.length) return null
  const stops = fill.gradientStops.map(s => ({
    offset: s.position ?? 0,
    ...parseColor(s.color),
  }))
  let type = 'linear'
  if (fill.type === 'GRADIENT_RADIAL') type = 'radial'
  else if (fill.type === 'GRADIENT_ANGULAR') type = 'conic'
  // GRADIENT_DIAMOND falls back to linear — same as the DOM renderer
  return {
    type,
    stops,
    handles: fill.gradientHandlePositions || null,
    angle: fill.angle ?? fill.gradientAngle ?? 180,
  }
}

export function getStrokeStyle(strokes) {
  if (!strokes || !strokes.length) return null
  const visible = strokes.filter(s => s.visible !== false)
  if (!visible.length) return null

  const stroke = visible[0]
  const { color, alpha } = parseColor(stroke.color)
  const result = {
    color,
    alpha: alpha * (stroke.opacity ?? 1),
    width: stroke.weight ?? 1,
    alignment: stroke.alignment === 'INSIDE' ? 1 : stroke.alignment === 'OUTSIDE' ? 0 : 0.5,
  }
  if (stroke.dashPattern === 'dashed' || stroke.dashPattern === 'dotted') {
    result.dashPattern = stroke.dashPattern
  }
  return result
}

export function getCornerRadius(sd) {
  if (sd.cornerRadius != null) return sd.cornerRadius
  if (sd.topLeftRadius != null) {
    return [sd.topLeftRadius || 0, sd.topRightRadius || 0, sd.bottomRightRadius || 0, sd.bottomLeftRadius || 0]
  }
  return 0
}

export function getEffects(effects) {
  if (!effects?.length) return { shadows: [], blurs: [] }
  const shadows = []
  const blurs = []
  for (const fx of effects) {
    if (fx.visible === false) continue
    if (fx.type === 'DROP_SHADOW' || fx.type === 'INNER_SHADOW') {
      const { color, alpha } = parseColor(fx.color)
      shadows.push({
        type: fx.type,
        color,
        alpha,
        offsetX: fx.offset?.x ?? 0,
        offsetY: fx.offset?.y ?? 0,
        blur: fx.radius ?? 0,
        spread: fx.spread ?? 0,
      })
    }
    if (fx.type === 'LAYER_BLUR' || fx.type === 'BACKGROUND_BLUR') {
      blurs.push({ type: fx.type, radius: fx.radius ?? 0 })
    }
  }
  return { shadows, blurs }
}

export function getBlendMode(blendMode) {
  const map = {
    'NORMAL': 'normal',
    'MULTIPLY': 'multiply',
    'SCREEN': 'screen',
    'OVERLAY': 'overlay',
    'DARKEN': 'darken',
    'LIGHTEN': 'lighten',
    'COLOR_DODGE': 'color-dodge',
    'COLOR_BURN': 'color-burn',
    'HARD_LIGHT': 'hard-light',
    'SOFT_LIGHT': 'soft-light',
    'DIFFERENCE': 'difference',
    'EXCLUSION': 'exclusion',
    'HUE': 'hue',
    'SATURATION': 'saturation',
    'COLOR': 'color',
    'LUMINOSITY': 'luminosity',
  }
  return map[blendMode] || 'normal'
}

export function applyTransform(container, item) {
  const sd = item.style_data || {}
  const rotation = (item.rotation || sd.rotation || 0) * Math.PI / 180
  container.rotation = rotation

  const s = sd.item_scale || 1
  const scaleX = s * (sd.flip_x ? -1 : 1)
  const scaleY = s * (sd.flip_y ? -1 : 1)
  if (scaleX !== 1 || scaleY !== 1 || rotation !== 0) {
    const hw = (item.width || 0) / 2
    const hh = (item.height || 0) / 2
    container.pivot.set(hw, hh)
    container.position.set((item.pos_x || 0) + hw, (item.pos_y || 0) + hh)
    container.scale.set(scaleX, scaleY)
  } else {
    container.pivot.set(0, 0)
    container.position.set(item.pos_x || 0, item.pos_y || 0)
  }

  let alpha = sd.opacity ?? 1
  const legacyOp = sd.shape_opacity ?? sd.frame_opacity ?? sd.text_opacity ?? sd.image_opacity
  if (legacyOp != null) {
    alpha = legacyOp > 1 ? legacyOp / 100 : legacyOp
  }
  container.alpha = alpha

  const bmRaw = sd.blendMode || sd.blend_mode
  const bm = getBlendMode(bmRaw ? String(bmRaw).toUpperCase().replace(/-/g, '_') : undefined)
  if (bm !== 'normal') container.blendMode = bm
}

export function drawRoundedRect(g, x, y, w, h, radius) {
  if (Array.isArray(radius)) {
    const [tl, tr, br, bl] = radius
    g.moveTo(x + tl, y)
    g.lineTo(x + w - tr, y)
    if (tr) g.arcTo(x + w, y, x + w, y + tr, tr)
    g.lineTo(x + w, y + h - br)
    if (br) g.arcTo(x + w, y + h, x + w - br, y + h, br)
    g.lineTo(x + bl, y + h)
    if (bl) g.arcTo(x, y + h, x, y + h - bl, bl)
    g.lineTo(x, y + tl)
    if (tl) g.arcTo(x, y, x + tl, y, tl)
    g.closePath()
  } else if (radius > 0) {
    g.roundRect(x, y, w, h, radius)
  } else {
    g.rect(x, y, w, h)
  }
}

export function applyFill(g, fillStyle, w, h) {
  if (!fillStyle) return
  if (fillStyle.type === 'solid') {
    g.fill({ color: fillStyle.color, alpha: fillStyle.alpha })
  } else if ((fillStyle.type === 'linear' || fillStyle.type === 'radial') && fillStyle.stops?.length) {
    try {
      const grad = buildPixiGradient(fillStyle, w || 100, h || 100)
      g.fill(grad)
    } catch (err) {
      console.error('[styleToPixi] gradient fill failed, falling back to solid:', err)
      g.fill({ color: fillStyle.stops[0]?.color || 0, alpha: fillStyle.stops[0]?.alpha || 1 })
    }
  } else if (fillStyle.type === 'conic' && fillStyle.stops?.length) {
    try {
      const tex = buildConicTexture(fillStyle, w || 100, h || 100)
      if (tex) {
        g.fill({ texture: tex })
      } else {
        g.fill({ color: fillStyle.stops[0]?.color || 0, alpha: fillStyle.stops[0]?.alpha || 1 })
      }
    } catch (err) {
      console.error('[styleToPixi] conic fill failed, falling back to solid:', err)
      g.fill({ color: fillStyle.stops[0]?.color || 0, alpha: fillStyle.stops[0]?.alpha || 1 })
    }
  }
  // 'image' fills are handled by the renderers (sprite masked to the path)
}

/**
 * Apply ALL fills bottom-to-top on the same path (parity with stacked CSS
 * backgrounds). The Graphics active path persists across fill() calls in
 * Pixi v8, so each fill layers on top of the previous one.
 */
export function applyFills(g, fillStyles, w, h) {
  if (!fillStyles?.length) return
  for (const fs of fillStyles) {
    if (!fs || fs.type === 'image') continue
    applyFill(g, fs, w, h)
  }
}

// Conic gradients aren't supported by Pixi's FillGradient — rasterize via
// canvas2d createConicGradient and fill with the resulting texture.
const _conicTexCache = new Map()
const CONIC_CACHE_MAX = 40

export function buildConicTexture(fillStyle, w, h) {
  if (typeof document === 'undefined') return null
  const cw = Math.max(2, Math.min(512, Math.round(w)))
  const ch = Math.max(2, Math.min(512, Math.round(h)))
  const key = cw + 'x' + ch + ':' + fillStyle.stops.map(s => `${s.offset}-${s.color}-${s.alpha}`).join('|')
  const cached = _conicTexCache.get(key)
  if (cached && !cached.destroyed) return cached

  const canvas = document.createElement('canvas')
  canvas.width = cw
  canvas.height = ch
  const ctx = canvas.getContext('2d')
  if (!ctx || typeof ctx.createConicGradient !== 'function') return null

  // DOM parity: conic-gradient() default starts at 12 o'clock
  const grad = ctx.createConicGradient(-Math.PI / 2, cw / 2, ch / 2)
  const sorted = [...fillStyle.stops].sort((a, b) => (a.offset ?? 0) - (b.offset ?? 0))
  for (const s of sorted) {
    const hex = numColorToHex(s.color)
    const a = s.alpha ?? 1
    const r = parseInt(hex.slice(1, 3), 16)
    const gC = parseInt(hex.slice(3, 5), 16)
    const b = parseInt(hex.slice(5, 7), 16)
    grad.addColorStop(Math.min(1, Math.max(0, s.offset ?? 0)), `rgba(${r},${gC},${b},${a})`)
  }
  ctx.fillStyle = grad
  ctx.fillRect(0, 0, cw, ch)

  const tex = Texture.from(canvas)
  if (_conicTexCache.size >= CONIC_CACHE_MAX) {
    const firstKey = _conicTexCache.keys().next().value
    _conicTexCache.get(firstKey)?.destroy(true)
    _conicTexCache.delete(firstKey)
  }
  _conicTexCache.set(key, tex)
  return tex
}

export function clearConicTextureCache() {
  for (const tex of _conicTexCache.values()) tex?.destroy(true)
  _conicTexCache.clear()
}

function numColorToHex(c) {
  if (typeof c === 'string') return c.startsWith('#') ? c : '#' + c
  if (typeof c === 'number') return '#' + (c & 0xFFFFFF).toString(16).padStart(6, '0')
  if (typeof c === 'object' && 'r' in c) {
    const r = Math.round((c.r ?? 0) * 255)
    const g = Math.round((c.g ?? 0) * 255)
    const b = Math.round((c.b ?? 0) * 255)
    return '#' + ((r << 16) | (g << 8) | b).toString(16).padStart(6, '0')
  }
  return '#000000'
}

export function buildPixiGradient(fillStyle, w, h) {
  const handles = fillStyle.handles
  let sx, sy, ex, ey
  if (handles?.length >= 2) {
    sx = handles[0].x
    sy = handles[0].y
    ex = handles[1].x
    ey = handles[1].y
  } else {
    const angle = fillStyle.angle ?? 180
    const rad = (angle - 90) * Math.PI / 180
    sx = 0.5 - Math.cos(rad) * 0.5
    sy = 0.5 - Math.sin(rad) * 0.5
    ex = 0.5 + Math.cos(rad) * 0.5
    ey = 0.5 + Math.sin(rad) * 0.5
  }
  const colorStops = fillStyle.stops.map(s => ({
    offset: s.offset,
    color: numColorToHex(s.color),
  }))

  if (fillStyle.type === 'radial') {
    return new FillGradient({
      type: 'radial',
      center: { x: 0.5, y: 0.5 },
      innerRadius: 0,
      outerCenter: { x: 0.5, y: 0.5 },
      outerRadius: 0.5,
      colorStops,
    })
  }

  return new FillGradient({
    type: 'linear',
    start: { x: sx, y: sy },
    end: { x: ex, y: ey },
    colorStops,
  })
}

export function applyStroke(g, strokeStyle) {
  if (!strokeStyle) return
  g.stroke({
    color: strokeStyle.color,
    alpha: strokeStyle.alpha,
    width: strokeStyle.width,
    alignment: strokeStyle.alignment,
  })
}

/**
 * @param {Object|null} shapeInfo - optional { shapeType, w, h, radius, sd } so
 *   shadows can follow the actual shape geometry instead of a rectangle.
 */
export function applyEffects(container, effects, shapeInfo = null) {
  if (!effects?.length) return
  for (const fx of effects) {
    if (fx.visible === false) continue
    if (fx.type === 'BACKGROUND_BLUR') continue
    if (fx.type === 'LAYER_BLUR') continue
    if (fx.type === 'DROP_SHADOW') {
      drawDropShadow(container, fx, shapeInfo)
    } else if (fx.type === 'INNER_SHADOW') {
      drawInnerShadow(container, fx, shapeInfo)
    }
  }
}

function shadowAlphaOf(fx) {
  if (fx._alpha != null) return fx._alpha
  const { alpha } = parseColor(fx.color)
  return alpha < 1 ? alpha : 0.25
}

/**
 * Draw one shadow pass path. When shapeInfo is provided the actual shape
 * perimeter is sampled and scaled outward from the center; otherwise the
 * legacy rounded-rect approximation around the child bounds is used.
 */
function addShadowPassPath(shadow, shapeInfo, bounds, expand, offsetX, offsetY) {
  if (shapeInfo?.w > 0 && shapeInfo?.h > 0) {
    const { shapeType, w, h, radius, sd } = shapeInfo
    const pts = samplePerimeter(shapeType || 'rectangle', w, h, radius || 0, sd || {})
    if (pts.length >= 3) {
      const cx = w / 2
      const cy = h / 2
      const sx = (w + expand) / w
      const sy = (h + expand) / h
      shadow.moveTo(cx + (pts[0].x - cx) * sx + offsetX, cy + (pts[0].y - cy) * sy + offsetY)
      for (let i = 1; i < pts.length; i++) {
        shadow.lineTo(cx + (pts[i].x - cx) * sx + offsetX, cy + (pts[i].y - cy) * sy + offsetY)
      }
      shadow.closePath()
      return true
    }
  }
  if (!bounds) return false
  shadow.roundRect(
    bounds.x + offsetX - expand / 2,
    bounds.y + offsetY - expand / 2,
    bounds.width + expand,
    bounds.height + expand,
    4 + expand / 4
  )
  return true
}

function drawDropShadow(container, fx, shapeInfo = null) {
  const { color } = parseColor(fx.color)
  const alpha = shadowAlphaOf(fx)
  const offsetX = fx.offset?.x ?? 0
  const offsetY = fx.offset?.y ?? 4
  const blur = fx.radius ?? 8
  const spread = fx.spread ?? 0
  const first = container.children[0]
  if (!first) return
  const bounds = first.getLocalBounds?.()

  const shadow = new Graphics()
  const passes = Math.max(4, Math.min(8, Math.ceil(blur / 2)))
  for (let i = passes; i >= 1; i--) {
    const t = i / passes
    const expand = spread * 2 + blur * t
    if (!addShadowPassPath(shadow, shapeInfo, bounds, expand, offsetX, offsetY)) return
    shadow.fill({ color, alpha: alpha / passes })
  }
  container.addChildAt(shadow, 0)
}

/**
 * Inner shadow approximation: concentric inset rings drawn on top of the
 * item, masked to the shape so nothing bleeds outside.
 */
function drawInnerShadow(container, fx, shapeInfo = null) {
  const { color } = parseColor(fx.color)
  const alpha = shadowAlphaOf(fx)
  const offsetX = fx.offset?.x ?? 0
  const offsetY = fx.offset?.y ?? 0
  const blur = Math.max(1, fx.radius ?? 8)
  const first = container.children[0]
  if (!first) return
  const bounds = first.getLocalBounds?.()

  const shadow = new Graphics()
  const passes = Math.max(3, Math.min(6, Math.ceil(blur / 3)))
  for (let i = 1; i <= passes; i++) {
    const t = i / passes
    // Negative expand = inset ring; offset shifts the ring like CSS inset shadow
    const inset = -blur * t
    if (!addShadowPassPath(shadow, shapeInfo, bounds, inset * 2, offsetX, offsetY)) return
    shadow.stroke({ color, alpha: alpha / passes, width: blur, alignment: 0.5 })
  }

  // Mask the rings to the shape so the shadow stays inside
  const mask = new Graphics()
  if (!addShadowPassPath(mask, shapeInfo, bounds, 0, 0, 0)) return
  mask.fill({ color: 0xffffff })
  container.addChild(mask)
  shadow.mask = mask
  container.addChild(shadow)
}
