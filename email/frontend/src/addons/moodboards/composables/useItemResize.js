/**
 * Composable for item resize logic.
 * Handles corner (tl/tr/bl/br) and edge (t/r/b/l) resize handles,
 * with rotation-aware position correction and group scaling.
 *
 * Architecture: This file is designed to be imported by MoodCanvasItem.vue.
 * Call `useItemResize({ store, props, emit, itemEl, resizing, ... })` in setup
 * and use the returned `startResize` function on handle @mousedown events.
 *
 * Phase 5 will add edge handles (t/r/b/l) for single-axis resizing here.
 * The existing startResize logic from MoodCanvasItem.vue should be migrated
 * into this file incrementally.
 */

export function getHandleSignXY(handle) {
  switch (handle) {
    case 'tl': return { signX: -1, signY: -1 }
    case 'tr': return { signX: 1, signY: -1 }
    case 'bl': return { signX: -1, signY: 1 }
    case 'br': return { signX: 1, signY: 1 }
    case 't':  return { signX: 0, signY: -1 }
    case 'b':  return { signX: 0, signY: 1 }
    case 'l':  return { signX: -1, signY: 0 }
    case 'r':  return { signX: 1, signY: 0 }
    default:   return { signX: 1, signY: 1 }
  }
}

export function isEdgeHandle(handle) {
  return ['t', 'r', 'b', 'l'].includes(handle)
}

export function getMinSize(type) {
  const defaults = { w: 20, h: 20 }
  if (type === 'text' || type === 'note') return { w: 40, h: 24 }
  if (type === 'shape') return { w: 10, h: 10 }
  if (type === 'image') return { w: 20, h: 20 }
  if (type === 'video' || type === 'youtube') return { w: 100, h: 60 }
  if (type === 'slide') return { w: 160, h: 90 }
  return defaults
}

/**
 * Compute rotation-aware cursor for edge handles.
 * When an item is rotated, the "top" edge handle cursor should
 * rotate with it (e.g. at 90deg rotation, top edge shows e-resize).
 */
export function getRotationAwareCursor(handle, rotationDeg) {
  const cursorMap = {
    t: 'n-resize',
    r: 'e-resize',
    b: 's-resize',
    l: 'w-resize',
    tl: 'nw-resize',
    tr: 'ne-resize',
    bl: 'sw-resize',
    br: 'se-resize'
  }

  const directionOrder = ['n', 'ne', 'e', 'se', 's', 'sw', 'w', 'nw']
  const handleToDir = { t: 0, tr: 1, r: 2, br: 3, b: 4, bl: 5, l: 6, tl: 7 }
  const idx = handleToDir[handle]
  if (idx === undefined) return cursorMap[handle] || 'default'

  const snap = Math.round(rotationDeg / 45) % 8
  const newIdx = ((idx + snap) % 8 + 8) % 8
  return directionOrder[newIdx] + '-resize'
}
