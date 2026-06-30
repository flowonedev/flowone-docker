/**
 * colorConvert.js
 *
 * Bidirectional color conversion between hex strings, CSS rgba(),
 * and Figma-native { r, g, b, a } (0-1 floats).
 */

/**
 * Parse a hex color string to Figma { r, g, b, a } (0-1 floats).
 * Accepts #RGB, #RRGGBB, #RRGGBBAA (with or without #).
 */
export function hexToFigma(hex) {
  if (!hex || typeof hex !== 'string') return { r: 0, g: 0, b: 0, a: 1 }
  hex = hex.replace('#', '')
  if (hex.length === 3) {
    hex = hex[0] + hex[0] + hex[1] + hex[1] + hex[2] + hex[2]
  }
  const r = (parseInt(hex.substring(0, 2), 16) || 0) / 255
  const g = (parseInt(hex.substring(2, 4), 16) || 0) / 255
  const b = (parseInt(hex.substring(4, 6), 16) || 0) / 255
  const a = hex.length >= 8
    ? (parseInt(hex.substring(6, 8), 16) || 0) / 255
    : 1
  return { r: round4(r), g: round4(g), b: round4(b), a: round4(a) }
}

/**
 * Convert Figma { r, g, b, a } to hex string.
 * Returns #RRGGBB when fully opaque, #RRGGBBAA otherwise.
 */
export function figmaToHex(color) {
  if (!color) return '#000000'
  const r = Math.round(clamp01(color.r) * 255)
  const g = Math.round(clamp01(color.g) * 255)
  const b = Math.round(clamp01(color.b) * 255)
  const hex = `#${hex2(r)}${hex2(g)}${hex2(b)}`
  if (color.a != null && color.a < 0.999) {
    const a = Math.round(clamp01(color.a) * 255)
    return `${hex}${hex2(a)}`
  }
  return hex
}

/**
 * Convert Figma { r, g, b, a } to CSS rgba() string.
 */
export function figmaToCssRgba(color) {
  if (!color) return 'rgba(0,0,0,1)'
  const r = Math.round(clamp01(color.r) * 255)
  const g = Math.round(clamp01(color.g) * 255)
  const b = Math.round(clamp01(color.b) * 255)
  const a = round4(clamp01(color.a ?? 1))
  return `rgba(${r}, ${g}, ${b}, ${a})`
}

/**
 * Convert Figma { r, g, b, a } to a 6-digit hex (ignoring alpha)
 * plus a separate 0-1 alpha value. Useful for legacy compatibility.
 */
export function figmaToHexAlpha(color) {
  if (!color) return { hex: '#000000', alpha: 1 }
  const r = Math.round(clamp01(color.r) * 255)
  const g = Math.round(clamp01(color.g) * 255)
  const b = Math.round(clamp01(color.b) * 255)
  return {
    hex: `#${hex2(r)}${hex2(g)}${hex2(b)}`,
    alpha: round4(clamp01(color.a ?? 1)),
  }
}

/**
 * Parse CSS rgba() / rgb() string to Figma { r, g, b, a }.
 */
export function cssRgbaToFigma(str) {
  if (!str || typeof str !== 'string') return null
  const m = str.match(/rgba?\(\s*([\d.]+)\s*,\s*([\d.]+)\s*,\s*([\d.]+)(?:\s*,\s*([\d.]+))?\s*\)/)
  if (!m) return null
  return {
    r: round4((parseFloat(m[1]) || 0) / 255),
    g: round4((parseFloat(m[2]) || 0) / 255),
    b: round4((parseFloat(m[3]) || 0) / 255),
    a: round4(m[4] != null ? parseFloat(m[4]) : 1),
  }
}

/**
 * Parse any CSS color value to Figma { r, g, b, a }.
 * Handles hex, rgb(), rgba(). Returns null for unrecognized formats.
 */
export function cssColorToFigma(str) {
  if (!str || typeof str !== 'string') return null
  str = str.trim()
  if (str.startsWith('#')) return hexToFigma(str)
  if (str.startsWith('rgb')) return cssRgbaToFigma(str)
  return null
}

/**
 * Merge a hex color and a separate opacity (0-100 scale) into Figma color.
 * This is the pattern used by legacy style_data (hex + opacity as 0-100 int).
 */
export function hexAndOpacityToFigma(hex, opacity100) {
  const c = hexToFigma(hex)
  if (opacity100 != null && opacity100 < 100) {
    c.a = round4(opacity100 / 100)
  }
  return c
}

/**
 * Convert Figma color to { hex, opacity100 } for legacy compatibility.
 */
export function figmaToHexAndOpacity(color) {
  const { hex, alpha } = figmaToHexAlpha(color || { r: 0, g: 0, b: 0, a: 1 })
  return { hex, opacity100: Math.round(alpha * 100) }
}

/**
 * Convert Figma { r, g, b, a } to HSL { h, s, l, a }.
 * h: 0-360, s: 0-100, l: 0-100, a: 0-1.
 */
export function figmaToHsl(color) {
  if (!color) return { h: 0, s: 0, l: 0, a: 1 }
  const r = clamp01(color.r)
  const g = clamp01(color.g)
  const b = clamp01(color.b)
  const max = Math.max(r, g, b)
  const min = Math.min(r, g, b)
  const l = (max + min) / 2
  if (max === min) return { h: 0, s: 0, l: Math.round(l * 100), a: color.a ?? 1 }
  const d = max - min
  const s = l > 0.5 ? d / (2 - max - min) : d / (max + min)
  let h
  if (max === r) h = ((g - b) / d + (g < b ? 6 : 0)) / 6
  else if (max === g) h = ((b - r) / d + 2) / 6
  else h = ((r - g) / d + 4) / 6
  return {
    h: Math.round(h * 360),
    s: Math.round(s * 100),
    l: Math.round(l * 100),
    a: round4(color.a ?? 1),
  }
}

/**
 * Check if two Figma colors are equal (within float tolerance).
 */
export function colorsEqual(a, b) {
  if (!a || !b) return a === b
  const eps = 0.002
  return Math.abs(a.r - b.r) < eps
    && Math.abs(a.g - b.g) < eps
    && Math.abs(a.b - b.b) < eps
    && Math.abs((a.a ?? 1) - (b.a ?? 1)) < eps
}

// ── Internal helpers ──

function clamp01(v) {
  return Math.max(0, Math.min(1, v ?? 0))
}

function round4(v) {
  return Math.round(v * 10000) / 10000
}

function hex2(n) {
  return n.toString(16).padStart(2, '0')
}
