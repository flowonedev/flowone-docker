import { screenToCanvas } from '../utils/coordTransform.js'
import { buildSnapTargets, computeSnap } from '../spatial/SnapGuides.js'
import { snapPositionToGrid } from '../spatial/GridSnap.js'
import { computeDistanceGuides } from '../../utils/distanceGuides.js'

const ENTERABLE_CONTAINER_TYPES = new Set(['frame', 'group', 'artboard', 'column', 'repeat_grid', 'slide'])

/**
 * Manages item dragging with snap guides and grid snap.
 */
export default class DragManager {
  constructor(store, container) {
    this._store = store
    this._container = container
    this._dragging = false
    this._startPositions = null
    this._startScreenPos = null
    this._snapTargets = null
    this._guides = []
    this._distanceGuides = []
    this._bendConnections = []
  }

  get isDragging() { return this._dragging }
  get guides() { return this._guides }
  get distanceGuides() { return this._distanceGuides }

  startDrag(screenX, screenY, snapCenter) {
    const items = this._store.currentBoard?.items || []
    const selected = this._getSelectedItems()
    if (!selected.length) return false

    if (selected.some(i => i.locked)) return false

    this._startPositions = new Map()
    for (const item of selected) {
      this._addItemAndDescendants(item)
    }
    this._bendConnections = this._captureDraggedConnectionBends()
    this._startScreenPos = { x: screenX, y: screenY }
    this._dragging = true
    this._store.isDragging = true
    this._store.draggedItemIds = new Set(this._startPositions.keys())

    if (snapCenter) {
      const selectedIds = [...this._startPositions.keys()]
      this._snapTargets = buildSnapTargets(items, selectedIds, this._store.guides || [])
    }

    return true
  }

  moveDrag(screenX, screenY, snapCenter, snapGrid, shiftKey) {
    if (!this._dragging || !this._startPositions) return

    const rect = this._container.getBoundingClientRect()
    const zoom = this._store.zoom
    let dx = (screenX - this._startScreenPos.x) / zoom
    let dy = (screenY - this._startScreenPos.y) / zoom

    if (shiftKey) {
      if (Math.abs(dx) >= Math.abs(dy)) dy = 0
      else dx = 0
    }

    let finalDx = dx
    let finalDy = dy
    this._guides = []

    if (snapCenter && this._snapTargets) {
      const bounds = this._getDragBounds(dx, dy)
      const snap = computeSnap(bounds, this._snapTargets, zoom)
      finalDx += snap.snapDx
      finalDy += snap.snapDy
      this._guides = snap.guides
    }

    const updates = []
    for (const [id, start] of this._startPositions) {
      updates.push({ id, pos_x: start.x + finalDx, pos_y: start.y + finalDy })
    }

    this._store.batchUpdateItems(updates, { skipUndo: true })
    this._shiftDraggedConnectionBends(finalDx, finalDy)

    const dragBBox = this._getDragBoundsAbsolute(finalDx, finalDy)
    const allItems = this._store.currentBoard?.items || []
    const draggedIds = new Set(this._startPositions.keys())
    const others = allItems.filter(o => !draggedIds.has(o.id))
    this._distanceGuides = computeDistanceGuides(
      dragBBox, others, this._store.zoom, this._store.panX, this._store.panY,
    )
  }

  endDrag(snapGrid) {
    if (!this._dragging) return
    const itemMap = new Map((this._store.currentBoard?.items || []).map(item => [item.id, item]))
    let bendPersisted = false

    if (snapGrid) {
      const updates = []
      for (const [id] of this._startPositions) {
        const item = itemMap.get(id)
        if (item) {
          const snapped = snapPositionToGrid(item.pos_x || 0, item.pos_y || 0)
          updates.push({ id, pos_x: snapped.x, pos_y: snapped.y })
        }
      }
      if (updates.length) {
        this._store.batchUpdateItems(updates)
        const delta = this._getSharedSnapDelta(updates, itemMap)
        if (delta) {
          this._shiftDraggedConnectionBends(delta.dx, delta.dy)
        }
      }
    } else {
      const updates = []
      for (const [id] of this._startPositions) {
        const item = itemMap.get(id)
        if (item) updates.push({ id, pos_x: item.pos_x, pos_y: item.pos_y })
      }
      if (updates.length) this._store.batchUpdateItems(updates)
    }

    bendPersisted = this._persistDraggedConnectionBends()

    this._dragging = false
    this._startPositions = null
    this._startScreenPos = null
    this._snapTargets = null
    this._guides = []
    this._distanceGuides = []
    this._bendConnections = []
    this._store.isDragging = false
    this._store.draggedItemIds = new Set()
  }

  /** Abandon any in-flight drag without persisting (component unmount). */
  destroy() {
    this._dragging = false
    this._startPositions = null
    this._startScreenPos = null
    this._snapTargets = null
    this._guides = []
    this._distanceGuides = []
    this._bendConnections = []
    this._store.isDragging = false
    this._store.draggedItemIds = new Set()
  }

  cancelDrag() {
    if (!this._dragging) return
    if (this._startPositions) {
      const updates = []
      for (const [id, start] of this._startPositions) {
        updates.push({ id, pos_x: start.x, pos_y: start.y })
      }
      this._store.batchUpdateItems(updates, { skipUndo: true })
    }
    this._restoreDraggedConnectionBends()
    this._dragging = false
    this._startPositions = null
    this._store.isDragging = false
    this._store.draggedItemIds = new Set()
    this._guides = []
    this._distanceGuides = []
    this._bendConnections = []
  }

  _getSelectedItems() {
    const items = this._store.currentBoard?.items || []
    const sel = this._store.selectedItemIds
    return items.filter(i => sel.has(i.id))
  }

  _addItemAndDescendants(item) {
    if (this._startPositions.has(item.id)) return
    this._startPositions.set(item.id, { x: item.pos_x || 0, y: item.pos_y || 0 })
    if (!ENTERABLE_CONTAINER_TYPES.has(item.type)) return
    const children = this._store.getChildrenOf?.(item.id) || []
    for (const child of children) {
      this._addItemAndDescendants(child)
    }
  }

  _captureDraggedConnectionBends() {
    const connections = this._store.currentBoard?.connections || []
    if (this._startPositions.size <= 1 || !connections.length) return []

    const draggedIds = new Set(this._startPositions.keys())
    const bendConnections = []

    for (const conn of connections) {
      if (!draggedIds.has(conn.from_item_id) || !draggedIds.has(conn.to_item_id)) continue
      const hasBend1 = conn.bend_x != null && conn.bend_y != null
      const hasBend2 = conn.bend2_x != null && conn.bend2_y != null
      if (!hasBend1 && !hasBend2) continue
      bendConnections.push({
        conn,
        startBend1: hasBend1 ? { x: conn.bend_x, y: conn.bend_y } : null,
        startBend2: hasBend2 ? { x: conn.bend2_x, y: conn.bend2_y } : null,
      })
    }

    return bendConnections
  }

  _shiftDraggedConnectionBends(dx, dy) {
    if (!this._bendConnections.length) return
    for (const bendConn of this._bendConnections) {
      if (bendConn.startBend1) {
        bendConn.conn.bend_x = bendConn.startBend1.x + dx
        bendConn.conn.bend_y = bendConn.startBend1.y + dy
      }
      if (bendConn.startBend2) {
        bendConn.conn.bend2_x = bendConn.startBend2.x + dx
        bendConn.conn.bend2_y = bendConn.startBend2.y + dy
      }
    }
  }

  _restoreDraggedConnectionBends() {
    if (!this._bendConnections.length) return
    for (const bendConn of this._bendConnections) {
      if (bendConn.startBend1) {
        bendConn.conn.bend_x = bendConn.startBend1.x
        bendConn.conn.bend_y = bendConn.startBend1.y
      }
      if (bendConn.startBend2) {
        bendConn.conn.bend2_x = bendConn.startBend2.x
        bendConn.conn.bend2_y = bendConn.startBend2.y
      }
    }
  }

  _persistDraggedConnectionBends() {
    if (!this._bendConnections.length || typeof this._store.updateConnection !== 'function') return false
    for (const bendConn of this._bendConnections) {
      const update = {}
      if (bendConn.startBend1) {
        update.bend_x = bendConn.conn.bend_x
        update.bend_y = bendConn.conn.bend_y
      }
      if (bendConn.startBend2) {
        update.bend2_x = bendConn.conn.bend2_x
        update.bend2_y = bendConn.conn.bend2_y
      }
      if (Object.keys(update).length) {
        this._store.updateConnection(bendConn.conn.id, update)
      }
    }
    return true
  }

  _getSharedSnapDelta(updates, itemMap) {
    let delta = null
    for (const update of updates) {
      const item = itemMap.get(update.id)
      if (!item) continue
      const currentDx = update.pos_x - (item.pos_x || 0)
      const currentDy = update.pos_y - (item.pos_y || 0)
      if (currentDx === 0 && currentDy === 0) continue
      if (delta == null) {
        delta = { dx: currentDx, dy: currentDy }
        continue
      }
      if (delta.dx !== currentDx || delta.dy !== currentDy) {
        return null
      }
    }
    return delta
  }

  _getDragBounds(dx, dy) {
    let minX = Infinity, minY = Infinity, maxX = -Infinity, maxY = -Infinity
    for (const [id, start] of this._startPositions) {
      const items = this._store.currentBoard?.items || []
      const item = items.find(i => i.id === id)
      if (!item) continue
      const x = start.x + dx
      const y = start.y + dy
      const w = item.width || 0
      const h = item.height || 0
      if (x < minX) minX = x
      if (y < minY) minY = y
      if (x + w > maxX) maxX = x + w
      if (y + h > maxY) maxY = y + h
    }
    return { x: minX, y: minY, width: maxX - minX, height: maxY - minY }
  }

  _getDragBoundsAbsolute(dx, dy) {
    let minX = Infinity, minY = Infinity, maxX = -Infinity, maxY = -Infinity
    for (const [id, start] of this._startPositions) {
      const items = this._store.currentBoard?.items || []
      const item = items.find(i => i.id === id)
      if (!item) continue
      const x = start.x + dx
      const y = start.y + dy
      const w = item.width || 240
      const h = item.height || 120
      if (x < minX) minX = x
      if (y < minY) minY = y
      if (x + w > maxX) maxX = x + w
      if (y + h > maxY) maxY = y + h
    }
    return { minX, minY, maxX, maxY }
  }
}
