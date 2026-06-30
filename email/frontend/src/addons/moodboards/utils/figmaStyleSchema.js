/**
 * figmaStyleSchema.js
 *
 * Figma-native constants, node type mappings, and default style factories.
 * This is the single source of truth for the style_data schema after migration.
 * All enum values match Figma's REST/Plugin API naming conventions.
 */

// ── Paint types (fills / strokes) ──
export const PaintType = Object.freeze({
  SOLID: 'SOLID',
  GRADIENT_LINEAR: 'GRADIENT_LINEAR',
  GRADIENT_RADIAL: 'GRADIENT_RADIAL',
  GRADIENT_ANGULAR: 'GRADIENT_ANGULAR',
  GRADIENT_DIAMOND: 'GRADIENT_DIAMOND',
  IMAGE: 'IMAGE',
})

export const GRADIENT_TYPES = new Set([
  PaintType.GRADIENT_LINEAR,
  PaintType.GRADIENT_RADIAL,
  PaintType.GRADIENT_ANGULAR,
  PaintType.GRADIENT_DIAMOND,
])

// ── Effect types ──
export const EffectType = Object.freeze({
  DROP_SHADOW: 'DROP_SHADOW',
  INNER_SHADOW: 'INNER_SHADOW',
  LAYER_BLUR: 'LAYER_BLUR',
  BACKGROUND_BLUR: 'BACKGROUND_BLUR',
  TEXT_SHADOW: 'TEXT_SHADOW',
})

export const SHADOW_TYPES = new Set([
  EffectType.DROP_SHADOW,
  EffectType.INNER_SHADOW,
  EffectType.TEXT_SHADOW,
])

// ── Stroke alignment ──
export const StrokeAlign = Object.freeze({
  INSIDE: 'INSIDE',
  OUTSIDE: 'OUTSIDE',
  CENTER: 'CENTER',
})

// ── Blend modes ──
export const BlendMode = Object.freeze({
  NORMAL: 'NORMAL',
  MULTIPLY: 'MULTIPLY',
  SCREEN: 'SCREEN',
  OVERLAY: 'OVERLAY',
  DARKEN: 'DARKEN',
  LIGHTEN: 'LIGHTEN',
  COLOR_DODGE: 'COLOR_DODGE',
  COLOR_BURN: 'COLOR_BURN',
  HARD_LIGHT: 'HARD_LIGHT',
  SOFT_LIGHT: 'SOFT_LIGHT',
  DIFFERENCE: 'DIFFERENCE',
  EXCLUSION: 'EXCLUSION',
  HUE: 'HUE',
  SATURATION: 'SATURATION',
  COLOR: 'COLOR',
  LUMINOSITY: 'LUMINOSITY',
})

// ── Text alignment ──
export const TextAlignHorizontal = Object.freeze({
  LEFT: 'LEFT',
  CENTER: 'CENTER',
  RIGHT: 'RIGHT',
  JUSTIFIED: 'JUSTIFIED',
})

export const TextAlignVertical = Object.freeze({
  TOP: 'TOP',
  CENTER: 'CENTER',
  BOTTOM: 'BOTTOM',
})

export const TextCase = Object.freeze({
  ORIGINAL: 'ORIGINAL',
  UPPER: 'UPPER',
  LOWER: 'LOWER',
  TITLE: 'TITLE',
})

export const TextDecoration = Object.freeze({
  NONE: 'NONE',
  UNDERLINE: 'UNDERLINE',
  STRIKETHROUGH: 'STRIKETHROUGH',
})

// ── Shape sub-types (FlowOne extension) ──
export const ShapeType = Object.freeze({
  RECTANGLE: 'RECTANGLE',
  ELLIPSE: 'ELLIPSE',
  POLYGON: 'POLYGON',
  STAR: 'STAR',
  TRIANGLE: 'TRIANGLE',
  LINE: 'LINE',
})

// ── Node type mapping: FlowOne legacy → Figma-aligned ──
export const NODE_TYPE_MAP = Object.freeze({
  shape: 'RECTANGLE',
  pen_shape: 'VECTOR',
  frame: 'FRAME',
  group: 'GROUP',
  artboard: 'FRAME',
  text: 'TEXT',
  image: 'RECTANGLE',
  line: 'LINE',
  slide: 'FRAME',
  repeat_grid: 'FRAME',
  note: 'NOTE',
  todo_list: 'TODO_LIST',
  color_swatch: 'COLOR_SWATCH',
  board_link: 'BOARD_LINK',
  image_set: 'IMAGE_SET',
  calendar_event: 'CALENDAR_EVENT',
  drawing: 'DRAWING',
  table: 'TABLE',
  column: 'COLUMN',
  folder: 'FOLDER',
  video: 'VIDEO',
  youtube: 'YOUTUBE',
  audio: 'AUDIO',
  file: 'FILE',
  link: 'LINK',
})

// ── Legacy shape_type → ShapeType ──
export const LEGACY_SHAPE_TYPE_MAP = Object.freeze({
  rectangle: ShapeType.RECTANGLE,
  circle: ShapeType.ELLIPSE,
  ellipse: ShapeType.ELLIPSE,
  triangle: ShapeType.TRIANGLE,
  star: ShapeType.STAR,
  polygon: ShapeType.POLYGON,
})

// ── Legacy blend_mode (lowercase) → BlendMode ──
export const LEGACY_BLEND_MODE_MAP = Object.freeze({
  normal: BlendMode.NORMAL,
  multiply: BlendMode.MULTIPLY,
  screen: BlendMode.SCREEN,
  overlay: BlendMode.OVERLAY,
  darken: BlendMode.DARKEN,
  lighten: BlendMode.LIGHTEN,
  'color-dodge': BlendMode.COLOR_DODGE,
  'color-burn': BlendMode.COLOR_BURN,
  'hard-light': BlendMode.HARD_LIGHT,
  'soft-light': BlendMode.SOFT_LIGHT,
  difference: BlendMode.DIFFERENCE,
  exclusion: BlendMode.EXCLUSION,
  hue: BlendMode.HUE,
  saturation: BlendMode.SATURATION,
  color: BlendMode.COLOR,
  luminosity: BlendMode.LUMINOSITY,
})

// ── Factory: default color (opaque white) ──
export function defaultColor(r = 1, g = 1, b = 1, a = 1) {
  return { r, g, b, a }
}

// ── Factory: default solid paint ──
export function solidPaint(r, g, b, a = 1) {
  return { type: PaintType.SOLID, color: { r, g, b, a }, visible: true }
}

// ── Factory: default gradient paint ──
export function gradientPaint(type, stops, angle = 180) {
  return {
    type: type || PaintType.GRADIENT_LINEAR,
    gradientStops: stops || [
      { color: { r: 0.39, g: 0.4, b: 0.95, a: 1 }, position: 0 },
      { color: { r: 0.93, g: 0.28, b: 0.6, a: 1 }, position: 1 },
    ],
    gradientAngle: angle,
    visible: true,
  }
}

// ── Factory: default drop shadow ──
export function dropShadow(x = 0, y = 4, radius = 8, spread = 0, color = null) {
  return {
    type: EffectType.DROP_SHADOW,
    color: color || { r: 0, g: 0, b: 0, a: 0.25 },
    offset: { x, y },
    radius,
    spread,
    visible: true,
  }
}

// ── Factory: default text shadow ──
export function textShadow(x = 1, y = 2, radius = 4, color = null) {
  return {
    type: EffectType.TEXT_SHADOW,
    color: color || { r: 0, g: 0, b: 0, a: 0.4 },
    offset: { x, y },
    radius,
    visible: true,
  }
}

// ── Factory: blur effect ──
export function layerBlur(radius = 4) {
  return { type: EffectType.LAYER_BLUR, radius, visible: true }
}

export function backgroundBlur(radius = 10) {
  return { type: EffectType.BACKGROUND_BLUR, radius, visible: true }
}

// ── Factory: default text properties ──
export function defaultText() {
  return {
    fontFamily: 'Inter',
    fontStyle: 'Regular',
    fontSize: 16,
    fontWeight: 400,
    letterSpacing: { value: 0, unit: 'PIXELS' },
    lineHeight: { value: 150, unit: 'PERCENT' },
    textAlignHorizontal: TextAlignHorizontal.LEFT,
    textAlignVertical: TextAlignVertical.TOP,
    textCase: TextCase.ORIGINAL,
    textDecoration: TextDecoration.NONE,
  }
}

// ── Factory: empty style_data in Figma-native format ──
export function emptyStyleData() {
  return {
    fills: [],
    strokes: [],
    strokeWeight: 0,
    strokeAlign: StrokeAlign.INSIDE,
    effects: [],
    cornerRadius: 0,
    rectangleCornerRadii: null,
    opacity: 1.0,
    blendMode: BlendMode.NORMAL,
    text: null,
    shapeType: null,
    vectorPaths: null,
    _flowone: {},
    _globals: {},
  }
}

// ── Detect whether a style_data object uses the new Figma-native format ──
export function isFigmaFormat(sd) {
  return sd != null && Array.isArray(sd.fills)
}
