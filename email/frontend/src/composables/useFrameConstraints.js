/**
 * useFrameConstraints.js
 * 
 * Constraint engine for children inside STATIC (non-auto-layout) frames.
 * When a parent frame is resized, children are repositioned / resized based on
 * their constraint_h and constraint_v style_data properties.
 *
 * Horizontal constraints: left | right | center | left_right | scale
 * Vertical constraints:   top  | bottom | center | top_bottom | scale
 */

/**
 * Snapshot a child's constraint anchors relative to the parent frame BEFORE resizing starts.
 * Call once per child at the beginning of the resize interaction.
 *
 * @param {Object} child       - The child item (reactive)
 * @param {Object} parentBounds - { x, y, w, h } of the parent frame at resize start
 * @returns {Object} snapshot   - Frozen distances used during the drag
 */
export function snapshotChildAnchors(child, parentBounds) {
  const cx = (child.pos_x || 0) - parentBounds.x
  const cy = (child.pos_y || 0) - parentBounds.y
  const cw = child.width || 240
  const ch = child.height || 120

  return {
    id: child.id,
    // Relative offsets inside the parent
    leftOffset: cx,                            // distance from parent left edge
    rightOffset: parentBounds.w - (cx + cw),   // distance from parent right edge
    topOffset: cy,                             // distance from parent top edge
    bottomOffset: parentBounds.h - (cy + ch),  // distance from parent bottom edge
    // Original proportional position (for scale mode)
    ratioX: parentBounds.w > 0 ? cx / parentBounds.w : 0,
    ratioY: parentBounds.h > 0 ? cy / parentBounds.h : 0,
    ratioW: parentBounds.w > 0 ? cw / parentBounds.w : 0,
    ratioH: parentBounds.h > 0 ? ch / parentBounds.h : 0,
    // Store original size for clamping
    origW: cw,
    origH: ch,
    // Constraint modes
    constraintH: child.style_data?.constraint_h || 'left',
    constraintV: child.style_data?.constraint_v || 'top',
    // Min/max from style_data
    minW: child.style_data?.min_w ?? null,
    maxW: child.style_data?.max_w ?? null,
    minH: child.style_data?.min_h ?? null,
    maxH: child.style_data?.max_h ?? null,
  }
}

/**
 * Apply a single child's constraints given the NEW parent bounds (during drag).
 * Returns { pos_x, pos_y, width, height } in absolute canvas coordinates.
 *
 * @param {Object} snap        - The snapshot from snapshotChildAnchors
 * @param {Object} newParent   - { x, y, w, h } of the parent frame NOW
 * @returns {Object}           - { pos_x, pos_y, width, height }
 */
export function applyConstraint(snap, newParent) {
  let cx, cw // relative x, width inside parent
  let cy, ch // relative y, height inside parent

  // ── Horizontal ──
  switch (snap.constraintH) {
    case 'right':
      // Keep distance from right edge fixed, width stays original
      cw = snap.origW
      cx = newParent.w - snap.rightOffset - cw
      break
    case 'center':
      // Keep centered horizontally, width stays original
      cw = snap.origW
      cx = (newParent.w - cw) / 2
      break
    case 'left_right':
      // Stretch: preserve left and right margins, width changes
      cx = snap.leftOffset
      cw = newParent.w - snap.leftOffset - snap.rightOffset
      break
    case 'scale':
      // Scale proportionally
      cx = snap.ratioX * newParent.w
      cw = snap.ratioW * newParent.w
      break
    case 'left':
    default:
      // Keep distance from left edge fixed (default — current behavior)
      cx = snap.leftOffset
      cw = snap.origW
      break
  }

  // ── Vertical ──
  switch (snap.constraintV) {
    case 'bottom':
      ch = snap.origH
      cy = newParent.h - snap.bottomOffset - ch
      break
    case 'center':
      ch = snap.origH
      cy = (newParent.h - ch) / 2
      break
    case 'top_bottom':
      cy = snap.topOffset
      ch = newParent.h - snap.topOffset - snap.bottomOffset
      break
    case 'scale':
      cy = snap.ratioY * newParent.h
      ch = snap.ratioH * newParent.h
      break
    case 'top':
    default:
      cy = snap.topOffset
      ch = snap.origH
      break
  }

  // Clamp to min/max if defined
  if (snap.minW != null) cw = Math.max(snap.minW, cw)
  if (snap.maxW != null) cw = Math.min(snap.maxW, cw)
  if (snap.minH != null) ch = Math.max(snap.minH, ch)
  if (snap.maxH != null) ch = Math.min(snap.maxH, ch)

  // Ensure positive dimensions
  cw = Math.max(20, Math.round(cw))
  ch = Math.max(20, Math.round(ch))

  return {
    pos_x: Math.round(newParent.x + cx),
    pos_y: Math.round(newParent.y + cy),
    width: cw,
    height: ch,
  }
}

/**
 * Convenience: snapshot ALL children of a frame before resize starts.
 *
 * @param {Array}  children    - Array of child items (from store)
 * @param {Object} parentBounds - { x, y, w, h }
 * @returns {Array}            - Array of snapshots
 */
export function snapshotAllChildren(children, parentBounds) {
  return children.map(c => snapshotChildAnchors(c, parentBounds))
}

/**
 * Apply constraints to ALL children during drag.
 * Mutates the child items in-place (optimistic update) and returns
 * an array of { id, pos_x, pos_y, width, height } for batch persist.
 *
 * @param {Array}  children    - Reactive child items from store
 * @param {Array}  snapshots   - From snapshotAllChildren
 * @param {Object} newParent   - { x, y, w, h } current parent bounds
 * @returns {Array}            - Array of update payloads
 */
export function applyAllConstraints(children, snapshots, newParent) {
  const updates = []
  for (const snap of snapshots) {
    const child = children.find(c => c.id === snap.id)
    if (!child) continue
    const result = applyConstraint(snap, newParent)
    // Mutate in-place for instant visual feedback
    child.pos_x = result.pos_x
    child.pos_y = result.pos_y
    child.width = result.width
    child.height = result.height
    updates.push({ id: child.id, ...result })
  }
  return updates
}

