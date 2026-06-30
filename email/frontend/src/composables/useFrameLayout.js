/**
 * useFrameLayout.js
 *
 * Computes inline CSS styles for children inside AUTO-LAYOUT frames.
 * Maps the Figma-style sizing_h / sizing_v / align_self / min/max properties
 * to CSS flexbox values.
 *
 * Sizing modes:
 *   fixed - explicit width/height (default, current behavior)
 *   fill  - stretches to fill available space on the axis (flex: 1)
 *   hug   - shrinks to content (flex: 0 0 auto, width/height: auto)
 *
 * Align self:
 *   auto | start | center | end | stretch
 *
 * Min/Max:
 *   min_w, max_w, min_h, max_h (px)
 */

function applyMargin(sd, style) {
  const mv = v => v === 'auto' ? 'auto' : (v || 0) + 'px'
  const mt = sd.margin_top, mr = sd.margin_right, mb = sd.margin_bottom, ml = sd.margin_left
  if (mt || mr || mb || ml) {
    style.margin = `${mv(mt)} ${mv(mr)} ${mv(mb)} ${mv(ml)}`
  }
}

/**
 * Compute the inline style object for a child item inside an auto-layout frame.
 *
 * @param {Object} child         - The child item
 * @param {String} parentDirection - 'row' or 'column' (parent's layout_direction)
 * @returns {Object}             - CSS style object to apply on the child wrapper
 */
export function getAutoLayoutChildStyle(child, parentDirection = 'column') {
  const sd = child.style_data || {}
  const sizingH = sd.sizing_h || 'fixed'
  const sizingV = sd.sizing_v || 'fixed'
  const alignSelf = sd.align_self || 'auto'
  const isRow = parentDirection === 'row'

  // Main axis = direction axis, cross axis = the other
  const mainSizing = isRow ? sizingH : sizingV
  const crossSizing = isRow ? sizingV : sizingH

  const style = {
    position: 'relative',
  }

  // ── Main axis (flex shorthand) ──
  switch (mainSizing) {
    case 'fill':
      style.flex = '1 1 0'
      break
    case 'hug':
      style.flex = '0 0 auto'
      break
    case 'fixed':
    default:
      if (isRow) {
        style.flex = `0 0 ${child.width ? child.width + 'px' : 'auto'}`
      } else {
        style.flex = `0 0 ${child.height ? child.height + 'px' : 'auto'}`
      }
      break
  }

  // ── Cross axis sizing ──
  // For the cross axis, we set explicit width or height
  if (isRow) {
    // Cross axis is vertical (height)
    switch (crossSizing) {
      case 'fill':
        style.alignSelf = 'stretch'
        style.height = 'auto'
        break
      case 'hug':
        style.height = 'auto'
        break
      case 'fixed':
      default:
        style.height = child.height ? child.height + 'px' : 'auto'
        break
    }
    // Width on main axis: fill/hug handled by flex, fixed needs explicit width
    if (mainSizing === 'hug') {
      style.width = 'auto'
    } else if (mainSizing === 'fixed') {
      style.width = child.width ? child.width + 'px' : 'auto'
    }
    // fill: width determined by flex
  } else {
    // Cross axis is horizontal (width)
    switch (crossSizing) {
      case 'fill':
        style.alignSelf = 'stretch'
        style.width = 'auto'
        break
      case 'hug':
        style.width = 'auto'
        break
      case 'fixed':
      default:
        style.width = child.width ? child.width + 'px' : 'auto'
        break
    }
    // Height on main axis
    if (mainSizing === 'hug') {
      style.height = 'auto'
    } else if (mainSizing === 'fixed') {
      style.height = child.height ? child.height + 'px' : 'auto'
    }
    // fill: height determined by flex
  }

  // ── Align self (override parent's align-items for this child) ──
  // Only apply if not already set to 'stretch' by cross-fill above
  if (alignSelf !== 'auto' && style.alignSelf !== 'stretch') {
    style.alignSelf = alignSelf === 'start' ? 'flex-start'
      : alignSelf === 'end' ? 'flex-end'
      : alignSelf // 'center' | 'stretch'
  }

  // ── Min/Max dimensions ──
  if (sd.min_w != null) style.minWidth = sd.min_w + 'px'
  if (sd.max_w != null) style.maxWidth = sd.max_w + 'px'
  if (sd.min_h != null) style.minHeight = sd.min_h + 'px'
  if (sd.max_h != null) style.maxHeight = sd.max_h + 'px'

  // ── Margin (applies in flex flow) ──
  applyMargin(sd, style)

  return style
}

/**
 * Compute inline style for a child inside a CSS grid container.
 */
export function getGridChildStyle(child) {
  const sd = child.style_data || {}
  const style = { position: 'relative' }
  if (child.width) style.width = child.width + 'px'
  if (child.height) style.height = child.height + 'px'
  if (sd.grid_column) style.gridColumn = sd.grid_column
  if (sd.grid_row) style.gridRow = sd.grid_row
  if (sd.align_self) style.alignSelf = sd.align_self
  if (sd.justify_self) style.justifySelf = sd.justify_self
  if (sd.min_w != null) style.minWidth = sd.min_w + 'px'
  if (sd.max_w != null) style.maxWidth = sd.max_w + 'px'
  if (sd.min_h != null) style.minHeight = sd.min_h + 'px'
  if (sd.max_h != null) style.maxHeight = sd.max_h + 'px'

  applyMargin(sd, style)

  return style
}

/**
 * Compute the frame's own size style when it uses hug sizing.
 * Returns overrides for the frame's width/height.
 *
 * @param {Object} frameStyleData - The frame's style_data
 * @param {Number} explicitW      - The frame's stored width
 * @param {Number} explicitH      - The frame's stored height
 * @returns {Object}              - { width, height } CSS values (or undefined to keep explicit)
 */
export function getFrameSizingStyle(frameStyleData, explicitW, explicitH) {
  const sd = frameStyleData || {}
  const style = {}

  if (sd.sizing_h === 'hug') {
    style.width = 'fit-content'
  } else if (explicitW) {
    style.width = explicitW + 'px'
  }

  if (sd.sizing_v === 'hug') {
    style.height = 'fit-content'
  } else if (explicitH) {
    style.height = explicitH + 'px'
  }

  return style
}

