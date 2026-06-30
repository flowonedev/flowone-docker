/**
 * cssClassResolver.js
 *
 * Resolves global CSS class overrides into style_data-compatible fields.
 * Maps generic class properties (color, background, font_size, etc.)
 * to the correct item-type-specific style_data keys.
 */

const TYPE_SHAPE = new Set(['shape', 'pen_shape'])

export function resolveClassStyleOverrides(item, globalCssClasses) {
  if (!item?.title || !globalCssClasses?.length) return null

  const names = item.title.split(',').map(s => s.trim()).filter(Boolean)
  if (names.length <= 1) return null

  const classMap = new Map(globalCssClasses.map(c => [c.name.toLowerCase(), c]))
  const overrides = {}
  let hasAny = false

  for (let i = 1; i < names.length; i++) {
    const cls = classMap.get(names[i].toLowerCase())
    if (!cls?.properties) continue

    const props = cls.properties
    const isShape = TYPE_SHAPE.has(item.type)

    if (props.color) {
      if (isShape) {
        overrides.shape_text_color = props.color
      } else {
        overrides.text_color = props.color
        overrides.text_fill_type = null
        overrides.text_fill_gradient = null
        overrides.text_clip_image = null
      }
      hasAny = true
    }

    if (props.background) {
      if (isShape) {
        overrides.shape_fill = props.background
        overrides.shape_fill_type = 'solid'
        overrides.shape_fill_gradient = null
      } else if (item.type === 'frame' || item.type === 'slide') {
        overrides.fill_color = props.background
        overrides.fill_type = 'solid'
        overrides.fill_gradient = null
      } else if (item.type === 'note' || item.type === 'color_swatch') {
        overrides._item_color = props.background
      } else {
        overrides.shape_fill = props.background
      }
      hasAny = true
    }

    if (props.font_size) {
      const v = parseFloat(props.font_size)
      if (!isNaN(v)) {
        if (isShape) overrides.shape_font_size = v
        else overrides.font_size = v
        hasAny = true
      }
    }

    if (props.font_weight) {
      if (isShape) overrides.shape_font_weight = props.font_weight
      else overrides.font_weight = props.font_weight
      hasAny = true
    }

    if (props.font_family) {
      if (isShape) overrides.shape_font_family = props.font_family
      else overrides.font_family = props.font_family
      hasAny = true
    }

    if (props.text_align) {
      if (isShape) overrides.shape_text_align = props.text_align
      else overrides.text_align = props.text_align
      hasAny = true
    }

    if (props.padding != null) {
      const v = parseFloat(props.padding)
      if (!isNaN(v)) {
        overrides.padding = v
        hasAny = true
      }
    }

    if (props.border_radius != null) {
      const v = parseFloat(props.border_radius)
      if (!isNaN(v)) {
        overrides.shape_border_radius = v
        hasAny = true
      }
    }

    if (props.opacity != null) {
      const v = parseFloat(props.opacity)
      if (!isNaN(v)) {
        const scaled = v <= 1 ? v * 100 : v
        if (isShape) overrides.shape_opacity = scaled
        else if (item.type === 'frame' || item.type === 'slide') overrides.frame_opacity = scaled
        else overrides.opacity = scaled
        hasAny = true
      }
    }

    if (props.border_color) {
      if (isShape) overrides.shape_border_color = props.border_color
      else overrides.stroke_color = props.border_color
      hasAny = true
    }

    if (props.border_width != null) {
      const v = parseFloat(props.border_width)
      if (!isNaN(v)) {
        if (isShape) overrides.shape_border_width = v
        else overrides.stroke_width = v
        hasAny = true
      }
    }
  }

  return hasAny ? overrides : null
}

export function mergeWithClassOverrides(styleData, overrides) {
  if (!overrides) return styleData || {}
  return { ...(styleData || {}), ...overrides }
}
