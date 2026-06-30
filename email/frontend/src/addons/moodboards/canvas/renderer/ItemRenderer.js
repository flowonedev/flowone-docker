import { Container, Graphics } from 'pixi.js'
import { createShape, updateShape } from './types/ShapeRenderer.js'
import { createText, updateText } from './types/TextRenderer.js'
import { createImage, updateImage, setDimensionFixCallback } from './types/ImageRenderer.js'
import { createNote, updateNote } from './types/NoteRenderer.js'
import { createFrame, updateFrame, computeAutoLayout } from './types/FrameRenderer.js'
import { createRepeatGrid, updateRepeatGrid, computeRepeatGridLayout } from './types/RepeatGridRenderer.js'
import { createLine, updateLine } from './types/LineRenderer.js'
import { createPenShape, updatePenShape } from './types/PenShapeRenderer.js'
import { createDrawing, updateDrawing } from './types/DrawingRenderer.js'
import { createCard, updateCard } from './types/CardRenderer.js'
import { createSlide, updateSlide } from './types/SlideRenderer.js'
import { drawConnection, createConnectionAnimation, createConnectionLabel, getConnectionCurve, updateConnectionAnimation } from './types/ConnectionRenderer.js'

/**
 * Registry and diff engine for rendering board items as PixiJS display objects.
 * Maps item.type to create/update functions and manages the scene graph.
 */
export default class ItemRenderer {
  constructor(stage, spatialIndex, textureCache, getCanvasRuntime = null) {
    this._stage = stage
    this._spatialIndex = spatialIndex
    this._textureCache = textureCache
    this._getCanvasRuntime = getCanvasRuntime
    this._displayObjects = new Map()
    this._childContainers = new Map()
    this._connectionsContainer = new Container()
    this._connectionsContainer.label = 'connections-below-layer'
    this._stage.addChild(this._connectionsContainer)
    this._itemsContainer = new Container()
    this._itemsContainer.label = 'items-layer'
    this._stage.addChild(this._itemsContainer)
    this._connectionsAboveContainer = new Container()
    this._connectionsAboveContainer.label = 'connections-above-layer'
    this._stage.addChild(this._connectionsAboveContainer)
    this._connectionAnimations = []
    this._lod = 'full'
    this._editHiddenId = null
  }

  /**
   * Layer-panel visibility (style_data._hidden) — parity with the DOM
   * renderer which applies opacity:0 + pointer-events:none.
   */
  _applyVisibility(obj, item) {
    if (!obj) return
    if (this._editHiddenId != null && this._editHiddenId === item.id) {
      obj.visible = false
      return
    }
    obj.visible = !item.style_data?._hidden
  }

  setLOD(tier) {
    if (this._lod === tier) return
    this._lod = tier
    for (const [id, obj] of this._displayObjects) {
      this._applyLOD(obj, tier)
    }
  }

  _applyLOD(obj, tier) {
    if (!obj) return
    if (tier === 'low') {
      obj.alpha = Math.min(obj.alpha, 0.92)
      if (obj.children) {
        for (const child of obj.children) {
          if (child.label === 'frame-label' || child.label === 'rg-label') {
            child.visible = false
          }
        }
      }
    } else {
      if (obj.children) {
        for (const child of obj.children) {
          if (child.label === 'frame-label' || child.label === 'rg-label') {
            child.visible = true
          }
        }
      }
    }
  }

  get displayObjects() { return this._displayObjects }

  syncItems(items, childrenByParentId) {
    const currentIds = new Set()

    const childIdSet = new Set()
    if (childrenByParentId) {
      for (const bucket of childrenByParentId.values()) {
        for (const child of bucket) childIdSet.add(child.id)
      }
    }
    const rootItems = items.filter(i => !childIdSet.has(i.id))
    const sorted = [...rootItems].sort((a, b) => (a.z_index || 0) - (b.z_index || 0))

    for (const item of sorted) {
      currentIds.add(item.id)
      const existing = this._displayObjects.get(item.id)

      if (!existing) {
        this._createItem(item)
      } else {
        this._updateItem(item, existing)
        const obj = this._displayObjects.get(item.id)
        if (obj && obj.parent !== this._itemsContainer) {
          obj.parent?.removeChild(obj)
          this._itemsContainer.addChild(obj)
        }
      }

      const children = childrenByParentId?.get(item.id)
      if (children?.length) {
        this._syncChildren(item, children, currentIds, childrenByParentId)
      }
    }

    this._attachMaskedChildren(items, currentIds)

    const liveObjs = new Set()
    for (const id of currentIds) {
      const o = this._displayObjects.get(id)
      if (o) liveObjs.add(o)
    }
    for (const [id, obj] of this._displayObjects) {
      if (!currentIds.has(id)) {
        this._detachTrackedChildren(obj, liveObjs)
        obj?.destroy({ children: true })
        this._displayObjects.delete(id)
        this._spatialIndex.remove(id)
      }
    }

    this._reorderZIndex(sorted)
    this._rebuildSpatialIndex(sorted, childrenByParentId)
  }

  syncConnections(connections, itemMap) {
    this._connectionsContainer.removeChildren()
    this._connectionsAboveContainer.removeChildren()
    this._connectionData = []
    this._connectionItemMap = itemMap
    this._connectionAnimations = []
    for (const conn of connections) {
      const g = new Graphics()
      drawConnection(g, conn, itemMap)
      const anim = createConnectionAnimation(conn, itemMap)
      const label = createConnectionLabel(conn)
      const renderAbove = !!conn.render_above
      const target = renderAbove ? this._connectionsAboveContainer : this._connectionsContainer
      target.addChild(g)
      if (anim?.container) target.addChild(anim.container)
      if (label) {
        const curve = getConnectionCurve(conn, itemMap)
        if (curve) {
          const mx = _cubicBezierAt(0.5, curve.x1, curve.cx1, curve.cx2, curve.x2)
          const my = _cubicBezierAt(0.5, curve.y1, curve.cy1, curve.cy2, curve.y2)
          label.anchor.set(0.5)
          label.position.set(mx, my - 14)
          target.addChild(label)
        }
      }
      this._connectionData.push(conn)
      if (anim) this._connectionAnimations.push(anim)
    }
  }

  hitTestConnection(canvasX, canvasY, threshold = 14) {
    if (!this._connectionData?.length || !this._connectionItemMap) return null
    for (const conn of this._connectionData) {
      const curve = getConnectionCurve(conn, this._connectionItemMap)
      if (!curve) continue
      if (_pointNearBezier(canvasX, canvasY, curve, threshold)) return conn
    }
    return null
  }

  _createItem(item) {
    const obj = this._buildDisplayObject(item)
    if (!obj) return
    obj._itemSnap = {
      w: item.width, h: item.height, rot: item.rotation || 0,
      sd: item.style_data, type: item.type, title: item.title,
      content: item.content, color: item.color, img: item.image_url,
      todos: item.todos,
    }
    this._applyVisibility(obj, item)
    this._resetMotionOffset(obj)
    this._displayObjects.set(item.id, obj)
    this._itemsContainer.addChild(obj)
    this._spatialIndex.insert(item)
  }

  _updateItem(item, existing) {
    if (existing.destroyed || !existing.position) {
      this._displayObjects.delete(item.id)
      this._createItem(item)
      return
    }
    const snap = existing._itemSnap
    if (snap && snap.w === item.width && snap.h === item.height
      && snap.rot === (item.rotation || 0) && snap.sd === item.style_data
      && snap.type === item.type && snap.title === item.title
      && snap.content === item.content && snap.color === item.color
      && snap.img === item.image_url && snap.todos === item.todos) {
      const rotation = (item.rotation || 0) * Math.PI / 180
      const sd = item.style_data || {}
      const s = sd.item_scale || 1
      const scaleX = s * (sd.flip_x ? -1 : 1)
      const scaleY = s * (sd.flip_y ? -1 : 1)
      if (scaleX !== 1 || scaleY !== 1 || rotation !== 0) {
        const hw = (item.width || 0) / 2
        const hh = (item.height || 0) / 2
        existing.position.set((item.pos_x || 0) + hw, (item.pos_y || 0) + hh)
      } else {
        existing.position.set(item.pos_x || 0, item.pos_y || 0)
      }
      this._applyVisibility(existing, item)
      this._resetMotionOffset(existing)
      this._spatialIndex.update(item)
      return
    }
    const updater = UPDATERS[item.type]
    let target = existing
    if (updater) {
      updater(existing, item, this._textureCache)
    } else {
      existing.parent?.removeChild(existing)
      existing.destroy({ children: true })
      const obj = this._buildDisplayObject(item)
      if (obj) {
        this._displayObjects.set(item.id, obj)
        this._itemsContainer.addChild(obj)
        target = obj
      } else {
        this._displayObjects.delete(item.id)
        return
      }
    }
    target._itemSnap = {
      w: item.width, h: item.height, rot: item.rotation || 0,
      sd: item.style_data, type: item.type, title: item.title,
      content: item.content, color: item.color, img: item.image_url,
      todos: item.todos,
    }
    this._applyVisibility(target, item)
    this._resetMotionOffset(target)
    this._spatialIndex.update(item)
  }

  _buildDisplayObject(item) {
    const creator = CREATORS[item.type]
    if (!creator) return null
    return creator(item, this._textureCache)
  }

  _syncChildren(parentItem, children, currentIds, childrenByParentId) {
    const parentObj = this._displayObjects.get(parentItem.id)
    if (!parentObj) return

    let posMap = null
    if (parentItem.type === 'repeat_grid' && children.length > 0) {
      const template = children[0]
      const grid = computeRepeatGridLayout(parentItem, template)
      if (grid.length > 0) {
        posMap = new Map()
        posMap.set(template.id, { id: template.id, x: grid[0].x, y: grid[0].y })
      }
    } else {
      const autoPositions = computeAutoLayout(parentItem, children)
      posMap = autoPositions ? new Map(autoPositions.map(p => [p.id, p])) : null
    }

    const parentX = parentItem.pos_x || 0
    const parentY = parentItem.pos_y || 0

    for (const child of children) {
      currentIds.add(child.id)
      let childObj = this._displayObjects.get(child.id)

      if (!childObj || childObj.destroyed || !childObj.position) {
        if (childObj) this._displayObjects.delete(child.id)
        childObj = this._buildDisplayObject(child)
        if (!childObj) continue
        this._displayObjects.set(child.id, childObj)
      } else {
        const updater = UPDATERS[child.type]
        if (updater) updater(childObj, child, this._textureCache)
      }

      if (posMap?.has(child.id)) {
        const p = posMap.get(child.id)
        childObj.position.set(p.x, p.y)
      } else {
        childObj.position.set(
          (child.pos_x || 0) - parentX,
          (child.pos_y || 0) - parentY,
        )
      }

      this._applyVisibility(childObj, child)

      if (childObj.parent !== parentObj) {
        parentObj.addChild(childObj)
      }

      const grandchildren = childrenByParentId?.get(child.id)
      if (grandchildren?.length) {
        this._syncChildren(child, grandchildren, currentIds, childrenByParentId)
      }
    }
  }

  _attachMaskedChildren(allItems, currentIds) {
    for (const item of allItems) {
      const maskParentId = item.style_data?.mask_parent_id
      if (!maskParentId) continue
      const parentObj = this._displayObjects.get(maskParentId)
      if (!parentObj) continue

      currentIds.add(item.id)
      let childObj = this._displayObjects.get(item.id)
      if (!childObj || childObj.destroyed || !childObj.position) {
        if (childObj) this._displayObjects.delete(item.id)
        childObj = this._buildDisplayObject(item)
        if (!childObj) continue
        childObj._itemSnap = {
          w: item.width, h: item.height, rot: item.rotation || 0,
          sd: item.style_data, type: item.type, title: item.title,
          content: item.content, color: item.color, img: item.image_url,
          todos: item.todos,
        }
        this._displayObjects.set(item.id, childObj)
      }

      const parentItem = allItems.find(i => i.id === maskParentId)
      if (!parentItem) continue

      const offsetX = (item.style_data?.mask_offset_x || 0)
      const offsetY = (item.style_data?.mask_offset_y || 0)
      childObj.position.set(
        (item.pos_x || 0) - (parentItem.pos_x || 0) + offsetX,
        (item.pos_y || 0) - (parentItem.pos_y || 0) + offsetY,
      )

      if (childObj.parent !== parentObj) {
        parentObj.addChild(childObj)
      }
    }
  }

  _detachTrackedChildren(parentObj, liveObjs) {
    if (!parentObj?.children) return
    for (let i = parentObj.children.length - 1; i >= 0; i--) {
      const child = parentObj.children[i]
      if (liveObjs.has(child)) {
        parentObj.removeChild(child)
      }
    }
  }

  _reorderZIndex(sorted) {
    for (let i = 0; i < sorted.length; i++) {
      const obj = this._displayObjects.get(sorted[i].id)
      if (obj && obj.parent === this._itemsContainer) {
        this._itemsContainer.setChildIndex(obj, Math.min(i, this._itemsContainer.children.length - 1))
      }
    }
  }

  _rebuildSpatialIndex(rootItems, childrenByParentId) {
    const entries = []
    const state = { drawOrder: 0 }
    for (const item of rootItems) {
      this._collectSpatialEntries(item, childrenByParentId, entries, state, null)
    }
    this._spatialIndex.buildFromEntries(entries)
  }

  _collectSpatialEntries(item, childrenByParentId, entries, state, parentAbs) {
    // Hidden items are not clickable in the DOM renderer (pointer-events:none)
    if (item.style_data?._hidden) return
    const itemSize = resolveSpatialSize(item)
    const hasExplicitSize = itemSize.w > 0 && itemSize.h > 0

    let x, y, w, h

    if (hasExplicitSize) {
      x = parentAbs?.autoPos ? parentAbs.autoPos.x : (item.pos_x || 0)
      y = parentAbs?.autoPos ? parentAbs.autoPos.y : (item.pos_y || 0)
      w = itemSize.w
      h = itemSize.h
    } else {
      const displayBounds = this._getCanvasBoundsForItem(item.id)
      x = displayBounds?.x ?? (parentAbs?.autoPos ? parentAbs.autoPos.x : (item.pos_x || 0))
      y = displayBounds?.y ?? (parentAbs?.autoPos ? parentAbs.autoPos.y : (item.pos_y || 0))
      w = displayBounds?.w ?? itemSize.w
      h = displayBounds?.h ?? itemSize.h
    }

    const children = childrenByParentId?.get(item.id)

    if (w <= 0 || h <= 0) {
      const childBounds = _computeChildrenBounds(item, children)
      if (childBounds) {
        x = childBounds.x
        y = childBounds.y
        w = childBounds.w
        h = childBounds.h
      }
    }

    if (w > 0 && h > 0) {
      entries.push({
        minX: x,
        minY: y,
        maxX: x + w,
        maxY: y + h,
        id: item.id,
        z_index: item.z_index || 0,
        draw_order: ++state.drawOrder,
        type: item.type,
        parent_id: item.parent_id || null,
        locked: !!(item.locked === true || item.locked === 1 || item.locked === '1'),
      })
    }

    if (!children?.length) return

    const autoPositions = computeAutoLayout(item, children)
    const posMap = autoPositions ? new Map(autoPositions.map(p => [p.id, p])) : null

    for (const child of children) {
      const childAuto = posMap?.has(child.id)
        ? {
            x: x + posMap.get(child.id).x,
            y: y + posMap.get(child.id).y,
          }
        : null
      this._collectSpatialEntries(child, childrenByParentId, entries, state, { autoPos: childAuto })
    }
  }

  _getCanvasBoundsForItem(itemId) {
    const obj = this._displayObjects.get(itemId)
    if (!obj) return null
    try {
      const bounds = obj.getBounds()
      const bx = bounds.x ?? bounds.minX
      const by = bounds.y ?? bounds.minY
      const bw = bounds.width ?? (bounds.maxX != null ? bounds.maxX - bounds.minX : 0)
      const bh = bounds.height ?? (bounds.maxY != null ? bounds.maxY - bounds.minY : 0)
      if (!Number.isFinite(bx) || !Number.isFinite(by) || !Number.isFinite(bw) || !Number.isFinite(bh)) return null
      if (bw <= 0 || bh <= 0) return null
      const stageScaleX = this._stage.scale.x || 1
      const stageScaleY = this._stage.scale.y || 1
      const stageX = this._stage.position.x || 0
      const stageY = this._stage.position.y || 0
      const x = (bx - stageX) / stageScaleX
      const y = (by - stageY) / stageScaleY
      const w = bw / stageScaleX
      const h = bh / stageScaleY
      if (!Number.isFinite(x) || !Number.isFinite(y) || !Number.isFinite(w) || !Number.isFinite(h)) return null
      return { x, y, w, h }
    } catch {
      return null
    }
  }

  getDisplayObject(itemId) {
    return this._displayObjects.get(itemId) || null
  }

  hideItem(itemId) {
    this._editHiddenId = itemId
    const obj = this._displayObjects.get(itemId)
    if (obj) obj.visible = false
  }

  showItem(itemId) {
    if (this._editHiddenId === itemId) this._editHiddenId = null
    const obj = this._displayObjects.get(itemId)
    // Respect the layer-panel hidden flag when restoring after edit
    if (obj) obj.visible = !obj._itemSnap?.sd?._hidden
  }

  applyFocusDimming(focusedItemId) {
    for (const [id, obj] of this._displayObjects) {
      if (!focusedItemId) {
        obj.alpha = 1
      } else {
        obj.alpha = (id === focusedItemId) ? 1 : 0.15
      }
    }
    const dimConns = !!focusedItemId
    for (const child of this._connectionsContainer.children) {
      child.alpha = dimConns ? 0.12 : 1
    }
    for (const child of this._connectionsAboveContainer.children) {
      child.alpha = dimConns ? 0.12 : 1
    }
    if (focusedItemId && this._connectionData) {
      for (let i = 0; i < this._connectionData.length; i++) {
        const conn = this._connectionData[i]
        if (conn.from_item_id === focusedItemId || conn.to_item_id === focusedItemId) {
          const gIdx = i * 2
          if (this._connectionsContainer.children[gIdx]) {
            this._connectionsContainer.children[gIdx].alpha = 1
          }
          if (this._connectionsAboveContainer.children[gIdx]) {
            this._connectionsAboveContainer.children[gIdx].alpha = 1
          }
        }
      }
    }
  }

  tick(elapsedMs) {
    const runtime = this._getCanvasRuntime?.() || {}

    if (this._connectionAnimations.length) {
      const showAnimations = !!runtime.motionEnabled
        && !!runtime.motionLines
        && !runtime.isDragging
        && (runtime.zoom || 1) >= 0.3
        && !(this._connectionData.length > 20 && (runtime.zoom || 1) < 0.5)

      for (const anim of this._connectionAnimations) {
        updateConnectionAnimation(anim, elapsedMs, showAnimations)
      }
    }

    this._tickMotion(elapsedMs, runtime)
  }

  /**
   * Item motion animations (DOM parity: .motion-wobble / .motion-float CSS
   * keyframes). Applied as a small positional offset on root display objects.
   */
  _tickMotion(elapsedMs, runtime) {
    const active = !!runtime.motionEnabled
      && !runtime.isDragging
      && (runtime.motionCards || runtime.motionElements)
      && this._lod !== 'low'

    if (!active) {
      if (this._motionActive) this._clearAllMotionOffsets()
      return
    }
    this._motionActive = true

    const speed = runtime.motionSpeed || 1
    for (const [id, obj] of this._displayObjects) {
      if (obj.destroyed || obj.parent !== this._itemsContainer) continue
      const type = obj._itemSnap?.type
      let amp = 0
      let wobble = false
      if (MOTION_CARD_TYPES.has(type) && runtime.motionCards) {
        amp = runtime.motionCardIntensity || 1
        wobble = true
      } else if (MOTION_ELEMENT_TYPES.has(type) && runtime.motionElements) {
        amp = runtime.motionIntensity || 1
      } else {
        if (obj._motionDx || obj._motionDy) this._removeMotionOffset(obj)
        continue
      }

      // Same per-item variety scheme as the DOM renderer: hashed seed drives
      // delay, duration and direction so neighbours don't move in lockstep.
      const seed = _motionSeed(id)
      const dur = (3500 + ((seed * 13) % 4500)) * speed
      const delay = (seed * 7) % 10000
      const dir = (seed % 2 === 0) ? 1 : -1
      const t = ((elapsedMs + delay) / dur) * Math.PI * 2 * dir

      let dx, dy
      if (wobble) {
        // figure-8 loop (~1.6px peak, like @keyframes wobble)
        dx = Math.sin(t) * 1.4 * amp
        dy = Math.sin(t * 2 + 1) * 1.1 * amp
      } else {
        // gentle hovering breath (~2px vertical bias, like @keyframes floaty)
        dx = Math.sin(t + 0.5) * 0.8 * amp
        dy = (Math.sin(t) - 0.5) * 1.4 * amp
      }

      obj.position.set(
        obj.position.x - (obj._motionDx || 0) + dx,
        obj.position.y - (obj._motionDy || 0) + dy,
      )
      obj._motionDx = dx
      obj._motionDy = dy
    }
  }

  /** Forget the stored offset (renderer just re-set the base position). */
  _resetMotionOffset(obj) {
    if (!obj) return
    obj._motionDx = 0
    obj._motionDy = 0
  }

  _removeMotionOffset(obj) {
    if (obj.destroyed) return
    obj.position.set(
      obj.position.x - (obj._motionDx || 0),
      obj.position.y - (obj._motionDy || 0),
    )
    obj._motionDx = 0
    obj._motionDy = 0
  }

  _clearAllMotionOffsets() {
    for (const obj of this._displayObjects.values()) {
      if (obj._motionDx || obj._motionDy) this._removeMotionOffset(obj)
    }
    this._motionActive = false
  }

  clear() {
    for (const obj of this._displayObjects.values()) {
      obj?.destroy({ children: true })
    }
    this._displayObjects.clear()
    this._connectionsContainer.removeChildren()
    this._connectionsAboveContainer.removeChildren()
    this._connectionData = []
    this._connectionAnimations = []
  }
}

// DOM parity: which item types wobble (cards) vs float (elements).
// 'text' is excluded — it renders via the static DOM TextOverlay.
const MOTION_CARD_TYPES = new Set(['note', 'todo_list', 'link', 'file', 'calendar_event', 'table'])
const MOTION_ELEMENT_TYPES = new Set(['image', 'color_swatch', 'drawing', 'folder', 'board_link', 'shape'])

function _motionSeed(id) {
  let h = 0
  const s = String(id || '')
  for (let i = 0; i < s.length; i++) { h = ((h << 5) - h) + s.charCodeAt(i); h |= 0 }
  return Math.abs(h)
}

const CREATORS = {
  shape: createShape,
  text: createText,
  image: createImage,
  image_set: createImage,
  note: createNote,
  frame: createFrame,
  group: createFrame,
  artboard: createFrame,
  column: createFrame,
  repeat_grid: createRepeatGrid,
  slide: createSlide,
  line: createLine,
  pen_shape: createPenShape,
  drawing: createDrawing,
  link: createCard,
  todo_list: createCard,
  file: createCard,
  folder: createCard,
  board_link: createCard,
  table: createCard,
  color_swatch: createCard,
  calendar_event: createCard,
  video: createCard,
  youtube: createCard,
  audio: createCard,
}

function _computeChildrenBounds(parentItem, children) {
  if (!children?.length) return null
  let minX = Infinity, minY = Infinity, maxX = -Infinity, maxY = -Infinity
  for (const child of children) {
    const cx = child.pos_x || 0
    const cy = child.pos_y || 0
    const cSize = resolveSpatialSize(child)
    const cw = cSize.w || 0
    const ch = cSize.h || 0
    if (cw <= 0 && ch <= 0) continue
    if (cx < minX) minX = cx
    if (cy < minY) minY = cy
    if (cx + cw > maxX) maxX = cx + cw
    if (cy + ch > maxY) maxY = cy + ch
  }
  if (minX === Infinity) return null
  return { x: minX, y: minY, w: maxX - minX, h: maxY - minY }
}

function resolveSpatialSize(item) {
  const sd = item.style_data || {}
  const intrinsicW = sd.original_width || 0
  const intrinsicH = sd.original_height || 0
  let w = item.width || intrinsicW || 0
  let h = item.height || 0
  if (!h && w > 0 && intrinsicW > 0 && intrinsicH > 0) {
    h = Math.round(w * (intrinsicH / intrinsicW))
  }
  if (!w && h > 0 && intrinsicW > 0 && intrinsicH > 0) {
    w = Math.round(h * (intrinsicW / intrinsicH))
  }
  const isImage = item.type === 'image' || item.type === 'image_set'
  if (isImage) {
    if (w > 0 && h < 40) h = 40
    if (h > 0 && w < 40) w = 40
  }
  if (item.type === 'text' && w > 0 && h <= 0) {
    const sd = item.style_data || {}
    const fontSize = sd.font_size || sd.fontSize || 16
    const lineHeight = sd.line_height || 1.4
    const content = item.content || ''
    const lines = Math.max(1, (content.match(/<br|<\/p>|<\/div>|\n/gi) || []).length + 1)
    h = Math.max(40, Math.ceil(fontSize * lineHeight * lines + 16))
  }
  return { w, h }
}

function _cubicBezierAt(t, p0, p1, p2, p3) {
  const mt = 1 - t
  return mt * mt * mt * p0 + 3 * mt * mt * t * p1 + 3 * mt * t * t * p2 + t * t * t * p3
}

function _pointNearBezier(px, py, curve, threshold) {
  const { x1, y1, cx1, cy1, cx2, cy2, x2, y2 } = curve
  const steps = 40
  for (let i = 0; i <= steps; i++) {
    const t = i / steps
    const bx = _cubicBezierAt(t, x1, cx1, cx2, x2)
    const by = _cubicBezierAt(t, y1, cy1, cy2, y2)
    const dx = px - bx
    const dy = py - by
    if (dx * dx + dy * dy <= threshold * threshold) return true
  }
  return false
}

const UPDATERS = {
  shape: updateShape,
  text: updateText,
  image: updateImage,
  image_set: updateImage,
  note: updateNote,
  frame: updateFrame,
  group: updateFrame,
  artboard: updateFrame,
  column: updateFrame,
  repeat_grid: updateRepeatGrid,
  slide: updateSlide,
  line: updateLine,
  pen_shape: updatePenShape,
  drawing: updateDrawing,
  link: updateCard,
  todo_list: updateCard,
  file: updateCard,
  folder: updateCard,
  board_link: updateCard,
  table: updateCard,
  color_swatch: updateCard,
  calendar_event: updateCard,
  video: updateCard,
  youtube: updateCard,
  audio: updateCard,
}
