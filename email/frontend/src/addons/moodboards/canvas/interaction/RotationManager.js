import { screenToCanvas } from '../utils/coordTransform.js'

// Effective rotation: top-level wins, legacy style_data.rotation as fallback
// (same precedence as styleToPixi applyTransform / the DOM renderer)
function rotationOf(item) {
  return item.rotation || item.style_data?.rotation || 0
}

function rotatePoint(px, py, cx, cy, angleDeg) {
  const rad = angleDeg * Math.PI / 180
  const cos = Math.cos(rad)
  const sin = Math.sin(rad)
  const dx = px - cx
  const dy = py - cy
  return {
    x: cx + dx * cos - dy * sin,
    y: cy + dx * sin + dy * cos,
  }
}

export default class RotationManager {
  constructor(store, container) {
    this._store = store
    this._container = container
    this._rotating = false
    this._startAngle = 0
    this._multiMode = false
    this._snapshots = null
    this._groupCenter = null
    this._itemId = null
    this._itemStartRotation = 0
    this._itemCenter = null
  }

  get isRotating() { return this._rotating }

  startRotation(items, screenX, screenY) {
    if (!items?.length) return false
    let live = items.filter(i => !i.locked)
    if (!live.length) return false

    // If a single group/frame container is selected, expand to include all children
    if (live.length === 1 && (live[0].type === 'group' || live[0].type === 'frame')) {
      const parent = live[0]
      const allItems = this._store.currentBoard?.items || []
      const children = allItems.filter(i => i.parent_id === parent.id && !i.locked)
      if (children.length) {
        live = [parent, ...children]
      }
    }

    this._rotating = true

    if (live.length === 1) {
      this._multiMode = false
      const item = live[0]
      this._itemId = item.id
      this._itemStartRotation = rotationOf(item)
      this._itemCenter = {
        x: (item.pos_x || 0) + (item.width || 200) / 2,
        y: (item.pos_y || 0) + (item.height || 200) / 2,
      }
      const c = this._toCanvas(screenX, screenY)
      this._startAngle = Math.atan2(c.y - this._itemCenter.y, c.x - this._itemCenter.x) * 180 / Math.PI
    } else {
      this._multiMode = true
      this._snapshots = live.map(item => ({
        id: item.id,
        x: item.pos_x || 0,
        y: item.pos_y || 0,
        w: item.width || 200,
        h: item.height || 200,
        rotation: rotationOf(item),
      }))
      let cx = 0, cy = 0
      for (const s of this._snapshots) {
        cx += s.x + s.w / 2
        cy += s.y + s.h / 2
      }
      cx /= this._snapshots.length
      cy /= this._snapshots.length
      this._groupCenter = { x: cx, y: cy }

      const c = this._toCanvas(screenX, screenY)
      this._startAngle = Math.atan2(c.y - cy, c.x - cx) * 180 / Math.PI
    }
    return true
  }

  moveRotation(screenX, screenY, shiftKey) {
    if (!this._rotating) return
    if (this._multiMode) {
      this._moveMulti(screenX, screenY, shiftKey)
    } else {
      this._moveSingle(screenX, screenY, shiftKey)
    }
  }

  /** Abandon any in-flight rotation without persisting (component unmount). */
  destroy() {
    this._rotating = false
    this._multiMode = false
    this._snapshots = null
    this._groupCenter = null
    this._itemId = null
  }

  endRotation() {
    if (!this._rotating) return
    if (this._multiMode) {
      this._endMulti()
    } else {
      this._endSingle()
    }
    this._rotating = false
    this._multiMode = false
    this._snapshots = null
    this._groupCenter = null
    this._itemId = null
  }

  rotateSelected(degrees) {
    const items = this._store.currentBoard?.items || []
    const sel = this._store.selectedItemIds
    let live = items.filter(i => sel.has(i.id) && !i.locked)
    if (!live.length) return

    // Expand group/frame containers to include children
    if (live.length === 1 && (live[0].type === 'group' || live[0].type === 'frame')) {
      const parent = live[0]
      const children = items.filter(i => i.parent_id === parent.id && !i.locked)
      if (children.length) live = [parent, ...children]
    }

    if (live.length === 1) {
      const item = live[0]
      this._store.batchUpdateItems([{ id: item.id, rotation: rotationOf(item) + degrees }])
      return
    }

    let cx = 0, cy = 0
    const snaps = live.map(item => {
      const x = item.pos_x || 0
      const y = item.pos_y || 0
      const w = item.width || 200
      const h = item.height || 200
      cx += x + w / 2
      cy += y + h / 2
      return { id: item.id, x, y, w, h, rotation: rotationOf(item) }
    })
    cx /= snaps.length
    cy /= snaps.length

    const updates = snaps.map(s => {
      const itemCx = s.x + s.w / 2
      const itemCy = s.y + s.h / 2
      const rotated = rotatePoint(itemCx, itemCy, cx, cy, degrees)
      return {
        id: s.id,
        pos_x: Math.round(rotated.x - s.w / 2),
        pos_y: Math.round(rotated.y - s.h / 2),
        rotation: s.rotation + degrees,
      }
    })
    this._store.batchUpdateItems(updates)
  }

  _moveSingle(screenX, screenY, shiftKey) {
    const c = this._toCanvas(screenX, screenY)
    let angle = Math.atan2(c.y - this._itemCenter.y, c.x - this._itemCenter.x) * 180 / Math.PI
    let delta = angle - this._startAngle
    if (shiftKey) delta = Math.round(delta / 15) * 15
    this._store.batchUpdateItems([{
      id: this._itemId,
      rotation: this._itemStartRotation + delta,
    }], { skipUndo: true })
  }

  _moveMulti(screenX, screenY, shiftKey) {
    const c = this._toCanvas(screenX, screenY)
    const gc = this._groupCenter
    let angle = Math.atan2(c.y - gc.y, c.x - gc.x) * 180 / Math.PI
    let delta = angle - this._startAngle
    if (shiftKey) delta = Math.round(delta / 15) * 15

    const updates = this._snapshots.map(s => {
      const itemCx = s.x + s.w / 2
      const itemCy = s.y + s.h / 2
      const rotated = rotatePoint(itemCx, itemCy, gc.x, gc.y, delta)
      return {
        id: s.id,
        pos_x: Math.round(rotated.x - s.w / 2),
        pos_y: Math.round(rotated.y - s.h / 2),
        rotation: s.rotation + delta,
      }
    })
    this._store.batchUpdateItems(updates, { skipUndo: true })
  }

  _endSingle() {
    const items = this._store.currentBoard?.items || []
    const item = items.find(i => i.id === this._itemId)
    if (item) {
      const prev = this._itemStartRotation
      const next = item.rotation || 0
      if (prev !== next) {
        this._store.pushUndo({
          type: 'batch-update',
          previousUpdates: [{ id: this._itemId, rotation: prev }],
          newUpdates: [{ id: this._itemId, rotation: next }],
        })
      }
      this._store.batchUpdateItems([{ id: this._itemId, rotation: item.rotation }], { skipUndo: true })
    }
  }

  _endMulti() {
    if (!this._snapshots) return
    const items = this._store.currentBoard?.items || []
    const previousUpdates = this._snapshots.map(s => ({
      id: s.id, pos_x: s.x, pos_y: s.y, rotation: s.rotation,
    }))
    const newUpdates = this._snapshots.map(s => {
      const item = items.find(i => i.id === s.id)
      return {
        id: s.id,
        pos_x: item?.pos_x ?? s.x,
        pos_y: item?.pos_y ?? s.y,
        rotation: item?.rotation ?? s.rotation,
      }
    })
    this._store.pushUndo({ type: 'batch-update', previousUpdates, newUpdates })
    this._store.batchUpdateItems(newUpdates, { skipUndo: true })
  }

  _toCanvas(screenX, screenY) {
    const rect = this._container.getBoundingClientRect()
    return screenToCanvas(
      screenX - rect.left,
      screenY - rect.top,
      this._store.panX,
      this._store.panY,
      this._store.zoom,
    )
  }
}
