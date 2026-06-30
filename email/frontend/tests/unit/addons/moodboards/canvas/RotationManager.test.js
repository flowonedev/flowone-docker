import { describe, it, expect, vi, beforeEach } from 'vitest'
import RotationManager from '@/addons/moodboards/canvas/interaction/RotationManager.js'

describe('RotationManager', () => {
  let store
  let container
  let mgr

  beforeEach(() => {
    store = {
      panX: 0, panY: 0, zoom: 1,
      selectedItemIds: new Set([1]),
      currentBoard: {
        items: [
          { id: 1, pos_x: 100, pos_y: 100, width: 100, height: 100, rotation: 0, locked: 0 },
        ],
      },
      batchUpdateItems: vi.fn(),
      pushUndo: vi.fn(),
    }
    container = {
      getBoundingClientRect: () => ({ left: 0, top: 0, width: 800, height: 600 }),
    }
    mgr = new RotationManager(store, container)
  })

  describe('initial state', () => {
    it('is not rotating', () => {
      expect(mgr.isRotating).toBe(false)
    })
  })

  describe('startRotation', () => {
    it('begins rotation for unlocked items', () => {
      const item = store.currentBoard.items[0]
      const started = mgr.startRotation([item], 200, 50)
      expect(started).toBe(true)
      expect(mgr.isRotating).toBe(true)
    })

    it('refuses to rotate locked items', () => {
      const locked = { ...store.currentBoard.items[0], locked: 1 }
      expect(mgr.startRotation([locked], 200, 50)).toBe(false)
    })
  })

  describe('rotateSelected', () => {
    it('rotates selected items by given degrees', () => {
      mgr.rotateSelected(90)
      expect(store.batchUpdateItems).toHaveBeenCalledWith([
        { id: 1, rotation: 90 },
      ])
    })

    it('accumulates rotation', () => {
      store.currentBoard.items[0].rotation = 45
      mgr.rotateSelected(90)
      expect(store.batchUpdateItems).toHaveBeenCalledWith([
        { id: 1, rotation: 135 },
      ])
    })

    it('supports negative rotation', () => {
      mgr.rotateSelected(-90)
      expect(store.batchUpdateItems).toHaveBeenCalledWith([
        { id: 1, rotation: -90 },
      ])
    })

    it('skips locked items', () => {
      store.currentBoard.items[0].locked = 1
      mgr.rotateSelected(90)
      expect(store.batchUpdateItems).not.toHaveBeenCalled()
    })

    it('handles items with style_data rotation', () => {
      store.currentBoard.items[0].rotation = undefined
      store.currentBoard.items[0].style_data = { rotation: 30 }
      mgr.rotateSelected(60)
      expect(store.batchUpdateItems).toHaveBeenCalledWith([
        { id: 1, rotation: 90 },
      ])
    })
  })

  describe('endRotation', () => {
    it('commits rotation and clears state', () => {
      const item = store.currentBoard.items[0]
      mgr.startRotation([item], 200, 50)
      store.batchUpdateItems.mockClear()

      mgr.endRotation()
      expect(mgr.isRotating).toBe(false)
      expect(store.batchUpdateItems).toHaveBeenCalled()
    })

    it('does nothing when not rotating', () => {
      mgr.endRotation()
      expect(store.batchUpdateItems).not.toHaveBeenCalled()
    })
  })
})
