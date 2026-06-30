import { describe, it, expect, vi, beforeEach } from 'vitest'
import SelectionManager from '@/addons/moodboards/canvas/interaction/SelectionManager.js'
import SpatialIndex from '@/addons/moodboards/canvas/spatial/SpatialIndex.js'

describe('SelectionManager', () => {
  let store
  let spatial
  let container
  let mgr

  beforeEach(() => {
    store = {
      panX: 0, panY: 0, zoom: 1,
      selectedItemIds: new Set(),
      editingGroupId: null,
      currentBoard: {
        items: [
          { id: 1, pos_x: 0, pos_y: 0, width: 100, height: 100, type: 'shape' },
          { id: 2, pos_x: 200, pos_y: 200, width: 100, height: 100, type: 'shape' },
          { id: 3, pos_x: 400, pos_y: 400, width: 100, height: 100, type: 'frame' },
          { id: 4, pos_x: 500, pos_y: 0, width: 200, height: 200, type: 'group', z_index: 0 },
          { id: 5, pos_x: 540, pos_y: 40, width: 80, height: 80, type: 'image', parent_id: 4, z_index: 1 },
          { id: 6, pos_x: 640, pos_y: 100, width: 40, height: 40, type: 'shape', parent_id: 4, z_index: 2 },
          { id: 7, pos_x: 0, pos_y: 200, width: 80, height: 80, type: 'note', style_data: { group_id: 'legacy-1' } },
          { id: 8, pos_x: 90, pos_y: 200, width: 80, height: 80, type: 'note', style_data: { group_id: 'legacy-1' } },
          { id: 9, pos_x: 420, pos_y: 420, width: 40, height: 40, type: 'text', parent_id: 3, z_index: 1 },
        ],
      },
    }
    spatial = new SpatialIndex()
    spatial.buildFromItems(store.currentBoard.items)
    container = {
      getBoundingClientRect: () => ({ left: 0, top: 0, width: 800, height: 600 }),
    }
    mgr = new SelectionManager(store, spatial, container)
  })

  describe('handleClick', () => {
    it('selects an item on click', () => {
      const hit = mgr.handleClick(50, 50, false)
      expect(hit).not.toBeNull()
      expect(hit.id).toBe(1)
      expect(store.selectedItemIds.has(1)).toBe(true)
    })

    it('clears selection on empty click', () => {
      store.selectedItemIds = new Set([1])
      mgr.handleClick(600, 600, false)
      expect(store.selectedItemIds.size).toBe(0)
    })

    it('adds to selection with shift', () => {
      mgr.handleClick(50, 50, false)
      expect(store.selectedItemIds.size).toBe(1)
      mgr.handleClick(250, 250, true)
      expect(store.selectedItemIds.size).toBe(2)
      expect(store.selectedItemIds.has(1)).toBe(true)
      expect(store.selectedItemIds.has(2)).toBe(true)
    })

    it('selects the full real group on click', () => {
      const hit = mgr.handleClick(560, 60, false)
      expect(hit).not.toBeNull()
      expect(hit.id).toBe(5)
      expect(store.selectedItemIds).toEqual(new Set([4, 5, 6]))
    })

    it('selects the full legacy group on click', () => {
      const hit = mgr.handleClick(20, 220, false)
      expect(hit).not.toBeNull()
      expect(hit.id).toBe(7)
      expect(store.selectedItemIds).toEqual(new Set([7, 8]))
    })

    it('shift-click toggles a full group selection as a unit', () => {
      mgr.handleClick(560, 60, true)
      expect(store.selectedItemIds).toEqual(new Set([4, 5, 6]))
      mgr.handleClick(560, 60, true)
      expect(store.selectedItemIds.size).toBe(0)
    })

    it('removes from selection with shift on already-selected', () => {
      store.selectedItemIds = new Set([1, 2])
      mgr.handleClick(50, 50, true)
      expect(store.selectedItemIds.has(1)).toBe(false)
      expect(store.selectedItemIds.has(2)).toBe(true)
    })

    it('clears editing group on empty click', () => {
      store.editingGroupId = 3
      mgr.handleClick(600, 600, false)
      expect(store.editingGroupId).toBeNull()
    })
  })

  describe('handleDoubleClick', () => {
    it('returns enter-group for container types', () => {
      const result = mgr.handleDoubleClick(450, 450)
      expect(result).not.toBeNull()
      expect(result.action).toBe('enter-group')
      expect(result.item.id).toBe(3)
      expect(store.editingGroupId).toBe(3)
    })

    it('returns edit for non-container types', () => {
      const result = mgr.handleDoubleClick(50, 50)
      expect(result.action).toBe('edit')
      expect(result.item.id).toBe(1)
    })

    it('enters parent group when double-clicking a child inside it', () => {
      store.enterGroup = vi.fn((id) => { store.editingGroupId = id })
      const result = mgr.handleDoubleClick(560, 60)
      expect(result).not.toBeNull()
      expect(result.action).toBe('enter-group')
      expect(result.item.id).toBe(4)
      expect(store.enterGroup).toHaveBeenCalledWith(4)
      expect(store.selectedItemIds.has(5)).toBe(true)
    })

    it('enters parent frame when double-clicking a child inside it', () => {
      store.enterGroup = vi.fn((id) => { store.editingGroupId = id })
      const result = mgr.handleDoubleClick(430, 430)
      expect(result).not.toBeNull()
      expect(result.action).toBe('enter-group')
      expect(result.item.id).toBe(3)
      expect(store.enterGroup).toHaveBeenCalledWith(3)
      expect(store.selectedItemIds.has(9)).toBe(true)
    })

    it('returns null when double-clicking empty space', () => {
      const result = mgr.handleDoubleClick(600, 600)
      expect(result).toBeNull()
    })
  })

  describe('rubber band selection', () => {
    it('starts and updates rubber band', () => {
      mgr.startRubberBand(0, 0)
      expect(mgr.isRubberBanding).toBe(true)

      const rect = mgr.updateRubberBand(150, 150)
      expect(rect).not.toBeNull()
      expect(rect.width).toBe(150)
      expect(rect.height).toBe(150)
      expect(store.selectedItemIds.has(1)).toBe(true)
      expect(store.selectedItemIds.has(2)).toBe(false)
    })

    it('ends rubber band and returns the rect', () => {
      mgr.startRubberBand(0, 0)
      mgr.updateRubberBand(500, 500)
      const rect = mgr.endRubberBand()
      expect(rect).not.toBeNull()
      expect(mgr.isRubberBanding).toBe(false)
    })
  })

  describe('selectAll', () => {
    it('selects all items', () => {
      mgr.selectAll()
      expect(store.selectedItemIds.size).toBe(9)
    })
  })

  describe('clearSelection', () => {
    it('clears all selections', () => {
      store.selectedItemIds = new Set([1, 2])
      mgr.clearSelection()
      expect(store.selectedItemIds.size).toBe(0)
    })
  })

  describe('exitGroup', () => {
    it('clears editing group and selection', () => {
      store.editingGroupId = 3
      store.selectedItemIds = new Set([1])
      mgr.exitGroup()
      expect(store.editingGroupId).toBeNull()
      expect(store.selectedItemIds.size).toBe(0)
    })
  })

  describe('hitTest', () => {
    it('returns the top-most item at point', () => {
      const hit = mgr.hitTest(50, 50)
      expect(hit).not.toBeNull()
      expect(hit.id).toBe(1)
    })

    it('returns null for empty areas', () => {
      const hit = mgr.hitTest(700, 700)
      expect(hit).toBeNull()
    })
  })
})
