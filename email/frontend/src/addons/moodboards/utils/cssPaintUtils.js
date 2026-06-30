/**
 * cssPaintUtils.js
 *
 * Converts Figma-native fills[], strokes[], effects[] to CSS strings.
 * Used by both the canvas rendering layer and the CSS inspect/export pipeline.
 * No type-switching -- same functions work for shapes, frames, text, etc.
 */

import { figmaToCssRgba, figmaToHex } from './colorConvert'
import { PaintType, EffectType, GRADIENT_TYPES, SHADOW_TYPES } from './figmaStyleSchema'

/**
 * Convert a Figma gradient paint to a CSS gradient string.
 */
export function gradientPaintToCss(paint) {
  if (!paint?.gradientStops?.length) return null
  const stops = [...paint.gradientStops]
    .sort((a, b) => a.position - b.position)
    .map(s => `${figmaToCssRgba(s.color)} ${(s.position * 100).toFixed(1)}%`)
    .join(', ')
  if (paint.type === PaintType.GRADIENT_RADIAL) return `radial-gradient(circle, ${stops})`
  if (paint.type === PaintType.GRADIENT_ANGULAR) return `conic-gradient(${stops})`
  const angle = paint.gradientAngle ?? 180
  return `linear-gradient(${angle}deg, ${stops})`
}

/**
 * Convert a single paint to a CSS background value.
 * @param {Object} paint - Figma paint object
 * @param {string|null} varRef - CSS var() reference if globally linked
 */
export function paintToCssBackground(paint, varRef = null) {
  if (!paint?.visible) return null
  if (GRADIENT_TYPES.has(paint.type)) {
    return varRef || gradientPaintToCss(paint)
  }
  if (paint.type === PaintType.IMAGE && paint.imageUrl) {
    return `url('${paint.imageUrl}')`
  }
  if (paint.type === PaintType.SOLID) {
    return varRef || figmaToCssRgba(paint.color)
  }
  return null
}

/**
 * Convert fills[] array to a CSS background property.
 * Stacks multiple visible fills (later fills render on top).
 * @param {Array} fills
 * @param {Object} globalsMap - path-based _globals map
 * @param {Object} options - { globalColors, globalGradients } for var() resolution
 * @returns {string|null}
 */
export function fillsToCssBackground(fills, globalsMap = {}, options = {}) {
  if (!fills?.length) return null
  const layers = []
  for (let i = fills.length - 1; i >= 0; i--) {
    const paint = fills[i]
    if (!paint?.visible) continue
    const varRef = resolveGlobalVar(globalsMap, `fills.${i}`, paint, options)
    const css = paintToCssBackground(paint, varRef)
    if (css) layers.push(css)
  }
  return layers.length ? layers.join(', ') : null
}

/**
 * Convert strokes[] + strokeWeight + strokeAlign to CSS border.
 * CSS border only supports one stroke; uses the first visible one.
 */
export function strokesToCssBorder(strokes, strokeWeight = 0, strokeAlign = 'INSIDE', globalsMap = {}, options = {}) {
  if (!strokes?.length || !strokeWeight) return null
  const stroke = strokes.find(s => s?.visible)
  if (!stroke) return null
  const varRef = resolveGlobalVar(globalsMap, 'strokes.0', stroke, options)
  const color = varRef || figmaToCssRgba(stroke.color)
  const style = stroke.dashPattern === 'dotted' ? 'dotted' : stroke.dashPattern === 'dashed' ? 'dashed' : 'solid'
  return `${strokeWeight}px ${style} ${color}`
}

/**
 * Convert effects[] to a CSS box-shadow string (multi-shadow).
 * Handles DROP_SHADOW and INNER_SHADOW.
 */
export function effectsToBoxShadow(effects, globalsMap = {}, options = {}) {
  if (!effects?.length) return null
  const shadows = []
  for (let i = 0; i < effects.length; i++) {
    const e = effects[i]
    if (!e?.visible) continue
    if (e.type !== EffectType.DROP_SHADOW && e.type !== EffectType.INNER_SHADOW) continue
    const varRef = resolveGlobalVar(globalsMap, `effects.${i}`, e, options)
    const color = varRef || figmaToCssRgba(e.color)
    const x = e.offset?.x ?? 0
    const y = e.offset?.y ?? 4
    const r = e.radius ?? 8
    const s = e.spread ?? 0
    const inset = e.type === EffectType.INNER_SHADOW ? 'inset ' : ''
    shadows.push(`${inset}${x}px ${y}px ${r}px ${s}px ${color}`)
  }
  return shadows.length ? shadows.join(', ') : null
}

/**
 * Convert effects[] to a CSS text-shadow string.
 */
export function effectsToTextShadow(effects) {
  if (!effects?.length) return null
  const shadows = []
  for (const e of effects) {
    if (!e?.visible) continue
    if (e.type !== EffectType.TEXT_SHADOW) continue
    const color = figmaToCssRgba(e.color)
    const x = e.offset?.x ?? 0
    const y = e.offset?.y ?? 2
    const r = e.radius ?? 4
    shadows.push(`${x}px ${y}px ${r}px ${color}`)
  }
  return shadows.length ? shadows.join(', ') : null
}

/**
 * Convert effects[] to CSS filter and backdrop-filter.
 * Returns { filter, backdropFilter } -- either may be null.
 */
export function effectsToFilters(effects) {
  if (!effects?.length) return { filter: null, backdropFilter: null }
  const filterParts = []
  const backdropParts = []
  for (const e of effects) {
    if (!e?.visible) continue
    if (e.type === EffectType.LAYER_BLUR && e.radius > 0) {
      filterParts.push(`blur(${e.radius}px)`)
    }
    if (e.type === EffectType.BACKGROUND_BLUR && e.radius > 0) {
      backdropParts.push(`blur(${e.radius}px)`)
    }
  }
  return {
    filter: filterParts.length ? filterParts.join(' ') : null,
    backdropFilter: backdropParts.length ? backdropParts.join(' ') : null,
  }
}

/**
 * Convert cornerRadius / rectangleCornerRadii to CSS border-radius.
 * Returns null if all corners are 0.
 */
export function cornerRadiusToCss(cornerRadius, rectangleCornerRadii, useRem = false) {
  const u = useRem ? rem : px
  if (rectangleCornerRadii) {
    const [tl, tr, br, bl] = rectangleCornerRadii
    if (!tl && !tr && !br && !bl) return null
    if (tl === tr && tr === br && br === bl) return u(tl)
    return `${u(tl)} ${u(tr)} ${u(br)} ${u(bl)}`
  }
  if (cornerRadius > 0) return u(cornerRadius)
  return null
}

/**
 * Convert opacity (0-1 float) to CSS string.
 * Returns null if fully opaque.
 */
export function opacityToCss(opacity) {
  if (opacity == null || opacity >= 0.999) return null
  return Math.max(0, Math.min(1, opacity)).toFixed(2)
}

/**
 * Convert blendMode to CSS mix-blend-mode value.
 * Returns null for NORMAL.
 */
export function blendModeToCss(blendMode) {
  if (!blendMode || blendMode === 'NORMAL') return null
  return blendMode.toLowerCase().replace(/_/g, '-')
}

/**
 * Convert text properties to CSS style object.
 * @param {Object} text - Figma text properties
 * @returns {Object} CSS properties
 */
export function textToCssStyle(text) {
  if (!text) return {}
  const style = {}
  if (text.fontFamily) style.fontFamily = `'${text.fontFamily}', sans-serif`
  if (text.fontWeight) style.fontWeight = text.fontWeight
  if (text.fontSize) style.fontSize = text.fontSize + 'px'
  if (text.letterSpacing) {
    const ls = text.letterSpacing
    if (typeof ls === 'object') {
      style.letterSpacing = ls.unit === 'PERCENT'
        ? `${ls.value / 100}em`
        : `${ls.value}px`
    } else {
      style.letterSpacing = ls + 'px'
    }
  }
  if (text.lineHeight) {
    const lh = text.lineHeight
    if (typeof lh === 'object') {
      if (lh.unit === 'AUTO') style.lineHeight = 'normal'
      else if (lh.unit === 'PERCENT') style.lineHeight = lh.value / 100
      else style.lineHeight = lh.value + 'px'
    } else {
      style.lineHeight = lh
    }
  }
  if (text.textAlignHorizontal) {
    style.textAlign = text.textAlignHorizontal.toLowerCase()
  }
  if (text.textCase && text.textCase !== 'ORIGINAL') {
    const map = { UPPER: 'uppercase', LOWER: 'lowercase', TITLE: 'capitalize' }
    style.textTransform = map[text.textCase] || 'none'
  }
  if (text.textDecoration && text.textDecoration !== 'NONE') {
    style.textDecoration = text.textDecoration.toLowerCase()
  }
  return style
}

// ── Internal helpers ──

function px(v) {
  return v ? v + 'px' : '0'
}

const REM_BASE = 16
function rem(v) {
  if (!v) return '0'
  const r = +(v / REM_BASE).toFixed(4).replace(/\.?0+$/, '')
  return r === 0 ? '0' : `${r}rem`
}

/**
 * Resolve a global var() reference for a paint/effect at a given path.
 * Returns CSS var() string or null.
 */
function resolveGlobalVar(globalsMap, path, paintOrEffect, options = {}) {
  const colorPath = `${path}.color`
  const ref = globalsMap[path] || globalsMap[colorPath]
  if (!ref?.id) return null

  if (ref.type === 'color' && options.globalColors) {
    const token = options.globalColors.find(c => c.id === ref.id)
    if (token) return `var(--color-${cssName(token.name)})`
  }
  if (ref.type === 'gradient' && options.globalGradients) {
    const token = options.globalGradients.find(g => g.id === ref.id)
    if (token) return `var(--gradient-${cssName(token.name)})`
  }
  return null
}

function cssName(str) {
  if (!str) return 'unnamed'
  return str
    .replace(/([a-z0-9])([A-Z])/g, '$1-$2')
    .replace(/[\s_]+/g, '-')
    .replace(/[^a-zA-Z0-9-]/g, '')
    .replace(/-+/g, '-')
    .replace(/^-|-$/g, '')
    .toLowerCase() || 'unnamed'
}
