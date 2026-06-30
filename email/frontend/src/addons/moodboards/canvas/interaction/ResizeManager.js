/**
 * Manages item resize via corner/edge handles.
 * Supports single-item resize and multi-item proportional scaling
 * (grouped items or multi-selection).
 */

const SLIDE_RATIO = 16 / 9
const SLIDE_MIN_W = 160
const SLIDE_MIN_H = 90

const HANDLE_SIGN = {
  nw: { sx: -1, sy: -1 }, ne: { sx: 1, sy: -1 },
  sw: { sx: -1, sy: 1 },  se: { sx: 1, sy: 1 },
  n: { sx: 0, sy: -1 },   s: { sx: 0, sy: 1 },
  w: { sx: -1, sy: 0 },   e: { sx: 1, sy: 0 },
}

export default class ResizeManager {
  constructor(store) {
    this._store = store
    this._resizing = false
    this._handle = null
    this._startItem = null
    this._startMouse = null
    this._itemType = null
    this._multiMode = false
    this._multiSnapshots = null
    this._multiBBox = null
    this._multiAnchor = null
    this._signX = 1
    this._signY = 1
  }

  get isResizing() { return this._resizing }
  get handle() { return this._handle }

  startResize(item, handle, screenX, screenY) {
    if (item.locked) return false
    this._resizing = true
    this._handle = handle
    this._startMouse = { x: screenX, y: screenY }

    const signs = HANDLE_SIGN[handle] || { sx: 1, sy: 1 }
    this._signX = signs.sx
    this._signY = signs.sy

    const allItems = this._store.currentBoard?.items || []
    let scaleItems = null

    const groupId = item.style_data?.group_id
    if (groupId) {
      const grp = allItems.filter(i => i.style_data?.group_id === groupId)
      if (grp.length > 1) scaleItems = grp
    }

    if (!scaleItems && (item.type === 'group' || item.type === 'frame')) {
      const children = allItems.filter(i => i.parent_id === item.id)
      if (children.length) scaleItems = [item, ...children]
    }

    if (!scaleItems && this._store.selectedItemIds.size > 1) {
      const sel = allItems.filter(i => this._store.selectedItemIds.has(i.id))
      if (sel.length > 1) scaleItems = sel
    }

    if (scaleItems && scaleItems.length > 1) {
      this._initMultiMode(scaleItems, screenX, screenY)
    } else {
      this._initSingleMode(item)
    }

    return true
  }

  _initSingleMode(item) {
    this._multiMode = false
    this._itemType = item.type
    this._startItem = {
      id: item.id,
      pos_x: item.pos_x || 0,
      pos_y: item.pos_y || 0,
      width: item.width || 100,
      height: item.height || 100,
    }
    this._itemScale = item.style_data?.item_scale ?? 1
  }

  _initMultiMode(items) {
    this._multiMode = true

    let minX = Infinity, minY = Infinity, maxX = -Infinity, maxY = -Infinity
    for (const it of items) {
      const x = it.pos_x || 0
      const y = it.pos_y || 0
      const w = it.width || 100
      const h = it.height || 100
      if (x < minX) minX = x
      if (y < minY) minY = y
      if (x + w > maxX) maxX = x + w
      if (y + h > maxY) maxY = y + h
    }
    this._multiBBox = { x: minX, y: minY, w: maxX - minX, h: maxY - minY }

    this._multiAnchor = {
      x: this._signX === 1 ? minX : maxX,
      y: this._signY === 1 ? minY : maxY,
    }

    this._multiSnapshots = items.map(it => ({
      item: it,
      x: it.pos_x || 0,
      y: it.pos_y || 0,
      w: it.width || 100,
      h: it.height || 100,
      fontSize: it.style_data?.font_size || null,
      shapeFontSize: it.style_data?.shape_font_size || null,
      borderWidth: it.style_data?.border_width || null,
      shapeBorderWidth: it.style_data?.shape_border_width || null,
      strokeWidth: it.style_data?.text_stroke_width || null,
      textPadding: it.type === 'text' ? (it.style_data?.text_padding ?? 12) : null,
      letterSpacing: it.style_data?.letter_spacing ?? null,
    }))

    // Snapshot connections between scaled items so bend points scale proportionally
    const itemIds = new Set(items.map(i => i.id))
    const conns = this._store.currentBoard?.connections || []
    this._connSnapshots = conns
      .filter(c => itemIds.has(c.from_item_id) && itemIds.has(c.to_item_id))
      .map(c => ({
        conn: c,
        bend_x: c.bend_x ?? null,
        bend_y: c.bend_y ?? null,
        bend2_x: c.bend2_x ?? null,
        bend2_y: c.bend2_y ?? null,
      }))
  }

  moveResize(screenX, screenY, zoom, shiftKey) {
    if (!this._resizing) return
    if (this._multiMode) {
      this._moveMulti(screenX, screenY, zoom, shiftKey)
    } else {
      this._moveSingle(screenX, screenY, zoom, shiftKey)
    }
  }

  _moveSingle(screenX, screenY, zoom, shiftKey) {
    if (!this._startItem) return
    const sc = this._itemScale || 1
    const dx = (screenX - this._startMouse.x) / zoom / sc
    const dy = (screenY - this._startMouse.y) / zoom / sc
    const s = this._startItem
    let newX = s.pos_x
    let newY = s.pos_y
    let newW = s.width
    let newH = s.height
    const isSlide = this._itemType === 'slide'
    const minW = isSlide ? SLIDE_MIN_W : 20
    const minH = isSlide ? SLIDE_MIN_H : 20

    const h = this._handle
    if (h.includes('e')) newW = Math.max(minW, s.width + dx)
    if (h.includes('w')) { newW = Math.max(minW, s.width - dx); newX = s.pos_x + s.width - newW }
    if (h.includes('s')) newH = Math.max(minH, s.height + dy)
    if (h.includes('n')) { newH = Math.max(minH, s.height - dy); newY = s.pos_y + s.height - newH }

    if (isSlide) {
      const isCorner = (h === 'se' || h === 'nw' || h === 'ne' || h === 'sw')
      const isHEdge = (h === 'e' || h === 'w')
      const isVEdge = (h === 'n' || h === 's')

      if (isCorner || isHEdge) {
        newW = Math.max(SLIDE_MIN_W, newW)
        newH = newW / SLIDE_RATIO
      } else if (isVEdge) {
        newH = Math.max(SLIDE_MIN_H, newH)
        newW = newH * SLIDE_RATIO
      }

      if (newH < SLIDE_MIN_H) { newH = SLIDE_MIN_H; newW = newH * SLIDE_RATIO }
      if (newW < SLIDE_MIN_W) { newW = SLIDE_MIN_W; newH = newW / SLIDE_RATIO }

      if (h.includes('n')) newY = s.pos_y + s.height - newH
      if (h.includes('w')) newX = s.pos_x + s.width - newW
    } else if (shiftKey) {
      const ratio = s.width / s.height
      if (h === 'se' || h === 'nw' || h === 'ne' || h === 'sw') {
        if (Math.abs(dx) > Math.abs(dy)) {
          newH = newW / ratio
          if (h.includes('n')) newY = s.pos_y + s.height - newH
        } else {
          newW = newH * ratio
          if (h.includes('w')) newX = s.pos_x + s.width - newW
        }
      }
    }

    this._store.batchUpdateItems([{
      id: s.id,
      pos_x: newX,
      pos_y: newY,
      width: newW,
      height: newH,
    }], { skipUndo: true })
  }

  _moveMulti(screenX, screenY, zoom, shiftKey) {
    const bbox = this._multiBBox
    const anchor = this._multiAnchor
    if (!bbox || !anchor || !this._multiSnapshots) return

    const rawDx = (screenX - this._startMouse.x) / zoom
    const rawDy = (screenY - this._startMouse.y) / zoom
    const dx = rawDx * this._signX
    const dy = rawDy * this._signY

    const newGW = Math.max(40, bbox.w + dx)
    const newGH = Math.max(40, bbox.h + dy)

    const isEdge = this._signX === 0 || this._signY === 0
    let scaleX, scaleY
    if (isEdge) {
      scaleX = this._signX !== 0 ? newGW / bbox.w : 1
      scaleY = this._signY !== 0 ? newGH / bbox.h : 1
    } else if (shiftKey) {
      scaleX = newGW / bbox.w
      scaleY = newGH / bbox.h
    } else {
      if (Math.abs(dx) >= Math.abs(dy)) {
        scaleX = scaleY = newGW / bbox.w
      } else {
        scaleX = scaleY = newGH / bbox.h
      }
    }

    scaleX = Math.max(0.05, scaleX)
    scaleY = Math.max(0.05, scaleY)

    const updates = []
    for (const snap of this._multiSnapshots) {
      const relX = snap.x - anchor.x
      const relY = snap.y - anchor.y
      const newPosX = Math.round(anchor.x + relX * scaleX)
      const newPosY = Math.round(anchor.y + relY * scaleY)
      const newW = Math.max(20, Math.round(snap.w * scaleX))
      const newH = Math.max(20, Math.round(snap.h * scaleY))

      const upd = { id: snap.item.id, pos_x: newPosX, pos_y: newPosY, width: newW, height: newH }

      const fontScale = (scaleX === scaleY) ? scaleX : Math.sqrt(Math.abs(scaleX * scaleY))
      const sd = snap.item.style_data ? { ...snap.item.style_data } : {}
      let sdChanged = false
      if (snap.fontSize) { sd.font_size = Math.max(6, Math.round(snap.fontSize * fontScale)); sdChanged = true }
      if (snap.shapeFontSize) { sd.shape_font_size = Math.max(6, Math.round(snap.shapeFontSize * fontScale)); sdChanged = true }
      if (snap.borderWidth) { sd.border_width = Math.max(1, Math.round(snap.borderWidth * fontScale)); sdChanged = true }
      if (snap.shapeBorderWidth) { sd.shape_border_width = Math.max(1, Math.round(snap.shapeBorderWidth * fontScale)); sdChanged = true }
      if (snap.strokeWidth) { sd.text_stroke_width = Math.max(0, +(snap.strokeWidth * fontScale).toFixed(1)); sdChanged = true }
      if (snap.textPadding != null) { sd.text_padding = Math.max(0, +(snap.textPadding * fontScale).toFixed(1)); sdChanged = true }
      if (snap.letterSpacing != null && snap.letterSpacing !== 0) { sd.letter_spacing = +(snap.letterSpacing * fontScale).toFixed(2); sdChanged = true }
      if (sdChanged) upd.style_data = sd

      updates.push(upd)
    }

    this._store.batchUpdateItems(updates, { skipUndo: true })

    // Scale connection bend points proportionally
    if (this._connSnapshots?.length) {
      for (const snap of this._connSnapshots) {
        const c = snap.conn
        if (snap.bend_x != null) c.bend_x = anchor.x + (snap.bend_x - anchor.x) * scaleX
        if (snap.bend_y != null) c.bend_y = anchor.y + (snap.bend_y - anchor.y) * scaleY
        if (snap.bend2_x != null) c.bend2_x = anchor.x + (snap.bend2_x - anchor.x) * scaleX
        if (snap.bend2_y != null) c.bend2_y = anchor.y + (snap.bend2_y - anchor.y) * scaleY
      }
    }
  }

  /** Abandon any in-flight resize without persisting (component unmount). */
  destroy() {
    this._resizing = false
    this._handle = null
    this._startItem = null
    this._startMouse = null
    this._itemType = null
    this._multiMode = false
    this._multiSnapshots = null
    this._connSnapshots = null
    this._multiBBox = null
    this._multiAnchor = null
  }

  endResize() {
    if (!this._resizing) return

    if (this._multiMode) {
      this._endMulti()
    } else {
      this._endSingle()
    }

    this._resizing = false
    this._handle = null
    this._startItem = null
    this._startMouse = null
    this._itemType = null
    this._multiMode = false
    this._multiSnapshots = null
    this._connSnapshots = null
    this._multiBBox = null
    this._multiAnchor = null
  }

  _endSingle() {
    if (!this._startItem) return
    const items = this._store.currentBoard?.items || []
    const item = items.find(i => i.id === this._startItem.id)
    if (item) {
      this._store.batchUpdateItems([{
        id: item.id,
        pos_x: item.pos_x,
        pos_y: item.pos_y,
        width: item.width,
        height: item.height,
      }])
    }
  }

  _endMulti() {
    if (!this._multiSnapshots) return
    const undoPrev = this._multiSnapshots.map(snap => {
      const prevSd = { ...(snap.item.style_data || {}) }
      if (snap.fontSize != null) prevSd.font_size = snap.fontSize
      if (snap.shapeFontSize != null) prevSd.shape_font_size = snap.shapeFontSize
      if (snap.borderWidth != null) prevSd.border_width = snap.borderWidth
      if (snap.shapeBorderWidth != null) prevSd.shape_border_width = snap.shapeBorderWidth
      if (snap.strokeWidth != null) prevSd.text_stroke_width = snap.strokeWidth
      if (snap.textPadding != null) prevSd.text_padding = snap.textPadding
      if (snap.letterSpacing != null) prevSd.letter_spacing = snap.letterSpacing
      return {
        id: snap.item.id,
        pos_x: snap.x, pos_y: snap.y,
        width: snap.w, height: snap.h,
        style_data: prevSd,
      }
    })

    const undoNew = this._multiSnapshots.map(snap => ({
      id: snap.item.id,
      pos_x: snap.item.pos_x,
      pos_y: snap.item.pos_y,
      width: snap.item.width,
      height: snap.item.height,
      style_data: snap.item.style_data,
    }))

    this._store.pushUndo({ type: 'batch-update', previousUpdates: undoPrev, newUpdates: undoNew })

    const updates = this._multiSnapshots.map(snap => ({
      id: snap.item.id,
      pos_x: snap.item.pos_x,
      pos_y: snap.item.pos_y,
      width: snap.item.width,
      height: snap.item.height,
      style_data: snap.item.style_data,
    }))
    this._store.batchUpdateItems(updates, { skipUndo: true })

    // Persist scaled connection bend points to server
    if (this._connSnapshots?.length) {
      for (const snap of this._connSnapshots) {
        const c = snap.conn
        const hasChanged = c.bend_x !== snap.bend_x || c.bend_y !== snap.bend_y
          || c.bend2_x !== snap.bend2_x || c.bend2_y !== snap.bend2_y
        if (hasChanged) {
          this._store.updateConnection(c.id, {
            bend_x: c.bend_x ?? null,
            bend_y: c.bend_y ?? null,
            bend2_x: c.bend2_x ?? null,
            bend2_y: c.bend2_y ?? null,
          })
        }
      }
    }
  }
}
