/**
 * globalStyleResolver.js
 *
 * Pure utility functions for the semantic global-styles system.
 * Items store a `_globals` map inside `style_data` that links individual
 * properties to board-level global color / text-style / gradient token IDs.
 *
 * Legacy _globals keys (type-prefixed):
 *   { "shape_fill": { type: "color", id: "gc-abc" } }
 *
 * Path-based _globals keys (Figma-native):
 *   { "fills.0.color": { type: "color", id: "gc-abc" } }
 */

const COLOR_STYLE_FIELDS = [
  'text_color', 'shape_fill', 'shape_border_color', 'shape_text_color',
  'border_color', 'background_color', 'font_color', 'fill_color',
  'line_color', 'text_stroke_color',
]

const PATH_COLOR_FIELDS = [
  'fills.0.color', 'fills.1.color', 'fills.2.color',
  'strokes.0.color', 'strokes.1.color',
  'effects.0.color', 'effects.1.color',
]

const LEGACY_TO_PATH_MAP = {
  shape_fill: 'fills.0.color',
  fill_color: 'fills.0.color',
  text_color: 'fills.0.color',
  font_color: 'fills.0.color',
  background_color: 'fills.0.color',
  shape_border_color: 'strokes.0.color',
  border_color: 'strokes.0.color',
  stroke_color: 'strokes.0.color',
  line_color: 'strokes.0.color',
  shape_text_color: 'fills.0.color',
  text_stroke_color: 'strokes.0.color',
}

const TEXT_STYLE_FIELDS = [
  'font_family', 'font_weight', 'font_size', 'line_height',
  'letter_spacing', 'text_transform',
]

const SHAPE_TEXT_STYLE_FIELDS = [
  'shape_font_family', 'shape_font_weight', 'shape_font_size',
  'shape_line_height', 'shape_letter_spacing', 'shape_text_transform',
]

export {
  COLOR_STYLE_FIELDS, TEXT_STYLE_FIELDS, SHAPE_TEXT_STYLE_FIELDS,
  PATH_COLOR_FIELDS, LEGACY_TO_PATH_MAP,
}

/**
 * Get the _globals map from an item's style_data (never null).
 */
export function getGlobalsMap(item) {
  return item?.style_data?._globals || {}
}

/**
 * Check whether a specific style key on an item is linked to any global.
 */
export function isLinkedToGlobal(item, styleKey) {
  return !!getGlobalsMap(item)[styleKey]
}

/**
 * Get the global reference for a specific key.
 * Returns { type, id } or null.
 */
export function getGlobalRef(item, styleKey) {
  return getGlobalsMap(item)[styleKey] || null
}

/**
 * Build a new style_data object that links a style key to a global color token.
 * Also sets the resolved literal value.
 */
export function linkColorToItem(currentStyleData, styleKey, colorToken) {
  const sd = { ...(currentStyleData || {}) }
  sd[styleKey] = colorToken.value
  sd._globals = {
    ...(sd._globals || {}),
    [styleKey]: { type: 'color', id: colorToken.id },
  }
  return sd
}

/**
 * Build a new style_data that links a text style to an item.
 * Copies all typography fields from the text style definition.
 */
export function linkTextStyleToItem(currentStyleData, textStyle, isShapeText = false) {
  const sd = { ...(currentStyleData || {}) }
  const fields = isShapeText ? SHAPE_TEXT_STYLE_FIELDS : TEXT_STYLE_FIELDS
  const sourceFields = TEXT_STYLE_FIELDS

  for (let i = 0; i < fields.length; i++) {
    if (textStyle[sourceFields[i]] != null) {
      sd[fields[i]] = textStyle[sourceFields[i]]
    }
  }

  if (textStyle.text_color != null && !isShapeText) {
    sd.text_color = textStyle.text_color
  }
  if (textStyle.text_color != null && isShapeText) {
    sd.shape_text_color = textStyle.text_color
  }

  sd._globals = {
    ...(sd._globals || {}),
    [isShapeText ? 'shape_text_style' : 'text_style']: { type: 'text_style', id: textStyle.id },
  }
  return sd
}

/**
 * Unlink a single key from globals, keeping the resolved value in place.
 */
export function unlinkFromGlobal(currentStyleData, styleKey) {
  const sd = { ...(currentStyleData || {}) }
  if (sd._globals) {
    const g = { ...sd._globals }
    delete g[styleKey]
    sd._globals = Object.keys(g).length ? g : undefined
  }
  return sd
}

/**
 * Find all items on the board that reference a specific global token ID.
 */
export function findItemsUsingGlobal(items, globalId) {
  return (items || []).filter(item => {
    const globals = getGlobalsMap(item)
    return Object.values(globals).some(ref => ref?.id === globalId)
  })
}

/**
 * Count how many items reference a specific global token ID.
 */
export function countGlobalUsage(items, globalId) {
  return findItemsUsingGlobal(items, globalId).length
}

/**
 * Given a global color change, build the list of item updates needed.
 * Returns array of { itemId, patch } to feed into batchUpdateItems.
 */
export function buildColorPropagationUpdates(items, colorId, newValue) {
  const updates = []
  for (const item of items || []) {
    const globals = getGlobalsMap(item)
    const sd = { ...(item.style_data || {}) }
    let changed = false

    for (const [key, ref] of Object.entries(globals)) {
      if (ref?.type === 'color' && ref.id === colorId) {
        sd[key] = newValue
        changed = true
      }
    }

    if (changed) {
      sd._globals = { ...globals }
      updates.push({ id: item.id, style_data: sd })
    }

    if (item.color && globals._item_color?.id === colorId) {
      updates.push({ id: item.id, color: newValue })
    }
  }
  return updates
}

/**
 * Given a global text style change, build the list of item updates needed.
 */
export function buildTextStylePropagationUpdates(items, styleId, newProps) {
  const updates = []
  for (const item of items || []) {
    const globals = getGlobalsMap(item)
    const sd = { ...(item.style_data || {}) }
    let changed = false

    if (globals.text_style?.id === styleId) {
      for (const f of TEXT_STYLE_FIELDS) {
        if (newProps[f] != null) { sd[f] = newProps[f]; changed = true }
      }
      if (newProps.text_color != null) { sd.text_color = newProps.text_color; changed = true }
    }

    if (globals.shape_text_style?.id === styleId) {
      for (let i = 0; i < SHAPE_TEXT_STYLE_FIELDS.length; i++) {
        if (newProps[TEXT_STYLE_FIELDS[i]] != null) {
          sd[SHAPE_TEXT_STYLE_FIELDS[i]] = newProps[TEXT_STYLE_FIELDS[i]]
          changed = true
        }
      }
      if (newProps.text_color != null) { sd.shape_text_color = newProps.text_color; changed = true }
    }

    if (changed) {
      sd._globals = { ...globals }
      updates.push({ id: item.id, style_data: sd })
    }
  }
  return updates
}

/**
 * Link a gradient token to an item's style_data.
 */
export function linkGradientToItem(currentStyleData, gradientToken) {
  const sd = { ...(currentStyleData || {}) }
  sd.fill_type = gradientToken.type || 'linear'
  sd.gradient = {
    angle: gradientToken.angle ?? 135,
    stops: JSON.parse(JSON.stringify(gradientToken.stops)),
  }
  sd._globals = {
    ...(sd._globals || {}),
    gradient: { type: 'gradient', id: gradientToken.id },
  }
  return sd
}

/**
 * Given a global gradient change, build item updates needed.
 */
export function buildGradientPropagationUpdates(items, gradientId, newGradient) {
  const updates = []
  for (const item of items || []) {
    const globals = getGlobalsMap(item)
    if (globals.gradient?.id !== gradientId) continue
    const sd = { ...(item.style_data || {}) }
    sd.fill_type = newGradient.type || 'linear'
    sd.gradient = {
      angle: newGradient.angle ?? 135,
      stops: JSON.parse(JSON.stringify(newGradient.stops)),
    }
    sd._globals = { ...globals }
    updates.push({ id: item.id, style_data: sd })
  }
  return updates
}

/**
 * Return a human-readable map of all globals an item uses.
 * { "shape_fill": { type, id, name }, "text_style": { type, id, name } }
 */
export function buildReadableGlobalsMap(item, globalColors, globalTextStyles, globalGradients) {
  const globals = getGlobalsMap(item)
  const result = {}

  const colorMap = new Map((globalColors || []).map(c => [c.id, c]))
  const tsMap = new Map((globalTextStyles || []).map(s => [s.id, s]))
  const gradMap = new Map((globalGradients || []).map(g => [g.id, g]))

  for (const [key, ref] of Object.entries(globals)) {
    if (!ref?.id) continue
    if (ref.type === 'color') {
      const token = colorMap.get(ref.id)
      result[key] = { type: 'color', id: ref.id, name: token?.name || 'Unknown', value: token?.value }
    } else if (ref.type === 'text_style') {
      const style = tsMap.get(ref.id)
      result[key] = { type: 'text_style', id: ref.id, name: style?.name || 'Unknown' }
    } else if (ref.type === 'gradient') {
      const grad = gradMap.get(ref.id)
      result[key] = { type: 'gradient', id: ref.id, name: grad?.name || 'Gradient' }
    }
  }
  return result
}

/**
 * Migrate legacy _globals keys to path-based keys.
 * Keeps original keys for backward compatibility until all consumers updated.
 */
export function migrateGlobalsToPathBased(globals) {
  if (!globals || typeof globals !== 'object') return globals
  const migrated = { ...globals }
  for (const [legacyKey, pathKey] of Object.entries(LEGACY_TO_PATH_MAP)) {
    if (migrated[legacyKey] && !migrated[pathKey]) {
      migrated[pathKey] = { ...migrated[legacyKey] }
    }
  }
  return migrated
}

/**
 * Resolve a path-based global reference.
 * Checks both path-based and legacy keys in _globals.
 */
export function resolvePathGlobal(globals, path) {
  if (!globals) return null
  if (globals[path]) return globals[path]
  const legacyKey = Object.entries(LEGACY_TO_PATH_MAP).find(([, p]) => p === path)?.[0]
  if (legacyKey && globals[legacyKey]) return globals[legacyKey]
  return null
}

/**
 * Link a color to an item using path-based globals key.
 */
export function linkColorToItemByPath(currentStyleData, path, colorToken) {
  const sd = { ...(currentStyleData || {}) }
  sd._globals = {
    ...(sd._globals || {}),
    [path]: { type: 'color', id: colorToken.id },
  }
  return sd
}
