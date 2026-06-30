import { screenToCanvas } from '../utils/coordTransform.js'

const ENTERABLE_CONTAINER_TYPES = new Set(['frame', 'group', 'artboard', 'column', 'repeat_grid', 'slide'])

/**
 * Manages item selection: click, shift-click, rubber band, group enter/exit.
 * Containers (slides, groups, frames) are always deprioritized so content
 * items can be clicked through them. Containers act as fallback if no content
 * item is at the click point.
 */
export default class SelectionManager {
  constructor(store, spatialIndex, container) {
    this._store = store
    this._spatial = spatialIndex
    this._container = container
    this._rubberBand = null
    this._rubberBandStart = null
  }

  handleClick(screenX, screenY, shiftKey) {
    const { x, y } = this._toCanvas(screenX, screenY)
    const hits = this._spatial.queryPoint(x, y)

    const editingGroup = this._store.editingGroupId
    let hit = null
    let containerFallback = null

    for (const entry of hits) {
      if (entry.locked) continue
      if (editingGroup && !this._isEntryInsideGroup(entry, editingGroup)) continue
      if (ENTERABLE_CONTAINER_TYPES.has(entry.type)) {
        if (!containerFallback) containerFallback = entry
        continue
      }
      hit = entry
      break
    }
    if (!hit) hit = containerFallback

    if (!hit) {
      this._store.selectedItemIds = new Set()
      this._store.editingGroupId = null
      return null
    }

    const targetIds = this._getSelectionTargetIds(hit.id)

    if (shiftKey) {
      const sel = new Set(this._store.selectedItemIds)
      const allSelected = targetIds.every(id => sel.has(id))
      for (const id of targetIds) {
        if (allSelected) sel.delete(id)
        else sel.add(id)
      }
      this._store.selectedItemIds = sel
    } else {
      if (targetIds.length === 1 && targetIds[0] === hit.id && typeof this._store.selectItem === 'function') {
        this._store.selectItem(hit.id, false)
      } else {
        this._store.editingGroupId = null
        this._store.selectedItemIds = new Set(targetIds)
      }
    }

    return hit
  }

  handleDoubleClick(screenX, screenY) {
    const { x, y } = this._toCanvas(screenX, screenY)
    const hits = this._spatial.queryPoint(x, y)
    if (!hits.length) return null

    let hit = null
    let containerFallback = null
    for (const h of hits) {
      if (h.locked) continue
      if (ENTERABLE_CONTAINER_TYPES.has(h.type)) {
        if (!containerFallback) containerFallback = h
        continue
      }
      hit = h
      break
    }
    if (!hit) hit = containerFallback
    if (!hit) return null

    const items = this._store.currentBoard?.items || []
    const item = items.find(i => i.id === hit.id)
    if (!item) return null

    if (item.parent_id) {
      const parent = items.find(i => i.id === item.parent_id)
      const isParentContainer = ENTERABLE_CONTAINER_TYPES.has(parent?.type)
      if (isParentContainer && this._store.editingGroupId !== parent.id) {
        if (typeof this._store.enterGroup === 'function') {
          this._store.enterGroup(parent.id)
          this._store.selectedItemIds = new Set([item.id])
        } else {
          this._store.editingGroupId = parent.id
          this._store.selectedItemIds = new Set([item.id])
        }
        return { action: 'enter-group', item: parent }
      }
    }

    const legacyGid = item.style_data?.group_id
    if (legacyGid && this._store.editingGroupId !== legacyGid) {
      if (typeof this._store.enterGroup === 'function') {
        this._store.enterGroup(item.id)
      } else {
        this._store.editingGroupId = legacyGid
        this._store.selectedItemIds = new Set([item.id])
      }
      return { action: 'enter-group', item }
    }

    const isContainer = ENTERABLE_CONTAINER_TYPES.has(item.type)
    if (isContainer) {
      if (typeof this._store.enterGroup === 'function') {
        this._store.enterGroup(item.id)
      } else {
        this._store.editingGroupId = item.id
        this._store.selectedItemIds = new Set()
      }
      return { action: 'enter-group', item }
    }

    return { action: 'edit', item }
  }

  startRubberBand(screenX, screenY) {
    const canvas = this._toCanvas(screenX, screenY)
    this._rubberBandStart = { x: canvas.x, y: canvas.y, screenX, screenY }
    this._rubberBand = { x: canvas.x, y: canvas.y, width: 0, height: 0 }
  }

  updateRubberBand(screenX, screenY) {
    if (!this._rubberBandStart) return null
    const canvas = this._toCanvas(screenX, screenY)
    const sx = this._rubberBandStart.x
    const sy = this._rubberBandStart.y
    this._rubberBand = {
      x: Math.min(sx, canvas.x),
      y: Math.min(sy, canvas.y),
      width: Math.abs(canvas.x - sx),
      height: Math.abs(canvas.y - sy),
    }

    const hits = this._spatial.queryRect({
      minX: this._rubberBand.x,
      minY: this._rubberBand.y,
      maxX: this._rubberBand.x + this._rubberBand.width,
      maxY: this._rubberBand.y + this._rubberBand.height,
    })

    const editingGroup = this._store.editingGroupId
    this._store.selectedItemIds = new Set(
      hits
        .filter(h => !h.locked && (!editingGroup || this._isEntryInsideGroup(h, editingGroup)))
        .map(h => h.id),
    )
    return this._rubberBand
  }

  endRubberBand() {
    const rect = this._rubberBand
    this._rubberBand = null
    this._rubberBandStart = null
    return rect
  }

  /** Reset transient interaction state (component unmount). */
  destroy() {
    this._rubberBand = null
    this._rubberBandStart = null
  }

  get isRubberBanding() { return this._rubberBandStart !== null }
  get rubberBandRect() { return this._rubberBand }

  selectAll() {
    const items = this._store.currentBoard?.items || []
    const ids = items
      .filter(i => (!this._store.editingGroupId || this._isItemInsideGroup(i.id, this._store.editingGroupId)) && !i.locked)
      .map(i => i.id)
    this._store.selectedItemIds = new Set(ids)
  }

  clearSelection() {
    this._store.selectedItemIds = new Set()
  }

  exitGroup() {
    this._store.editingGroupId = null
    this._store.selectedItemIds = new Set()
  }

  hitTest(screenX, screenY) {
    const { x, y } = this._toCanvas(screenX, screenY)
    const hits = this._spatial.queryPoint(x, y)
    const editingGroup = this._store.editingGroupId
    let containerFallback = null
    for (const h of hits) {
      if (h.locked) continue
      if (editingGroup && !this._isEntryInsideGroup(h, editingGroup)) continue
      if (ENTERABLE_CONTAINER_TYPES.has(h.type)) {
        if (!containerFallback) containerFallback = h
        continue
      }
      return h
    }
    return containerFallback || null
  }

  _isEntryInsideGroup(entry, groupId) {
    if (entry.id === groupId) return true
    if (this._isItemInsideGroup(entry.id, groupId)) return true
    const items = this._store.currentBoard?.items || []
    const item = items.find(i => i.id === entry.id)
    if (item?.style_data?.group_id === groupId) return true
    return false
  }

  _isItemInsideGroup(itemId, groupId) {
    const items = this._store.currentBoard?.items || []
    let item = items.find(i => i.id === itemId)
    if (item?.style_data?.group_id === groupId) return true
    while (item?.parent_id) {
      if (item.parent_id === groupId) return true
      item = items.find(i => i.id === item.parent_id)
    }
    return false
  }

  _getSelectionTargetIds(itemId) {
    const items = this._store.currentBoard?.items || []
    const item = items.find(entry => entry.id === itemId)
    if (!item) return [itemId]
    if (this._store.editingGroupId) return [itemId]

    if (item.parent_id) {
      const parent = items.find(entry => entry.id === item.parent_id)
      if (parent && ENTERABLE_CONTAINER_TYPES.has(parent.type)) {
        return this._getContainerFamilyIds(parent.id)
      }
    }

    if (ENTERABLE_CONTAINER_TYPES.has(item.type)) {
      return this._getContainerFamilyIds(item.id)
    }

    const legacyGroupId = item.style_data?.group_id
    if (legacyGroupId) {
      const legacyMembers = items.filter(entry => entry.style_data?.group_id === legacyGroupId)
      if (legacyMembers.length > 1) {
        return legacyMembers.map(entry => entry.id)
      }
    }

    return [itemId]
  }

  _getContainerFamilyIds(groupId) {
    const items = this._store.currentBoard?.items || []
    const ids = new Set([groupId])
    const queue = [groupId]

    while (queue.length) {
      const parentId = queue.shift()
      for (const item of items) {
        if (item.parent_id !== parentId || ids.has(item.id)) continue
        ids.add(item.id)
        if (ENTERABLE_CONTAINER_TYPES.has(item.type)) {
          queue.push(item.id)
        }
      }
    }

    return [...ids]
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
