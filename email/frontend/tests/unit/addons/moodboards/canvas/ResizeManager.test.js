import { describe, it, expect, vi, beforeEach } from 'vitest'
import ResizeManager from '@/addons/moodboards/canvas/interaction/ResizeManager.js'

describe('ResizeManager', () => {
  let store
  let mgr

  beforeEach(() => {
    store = {
      batchUpdateItems: vi.fn(),
      pushUndo: vi.fn(),
      selectedItemIds: new Set([1]),
      currentBoard: {
        items: [
          { id: 1, pos_x: 100, pos_y: 100, width: 200, height: 150, locked: 0 },
        ],
        connections: [],
      },
    }
    mgr = new ResizeManager(store)
  })

  describe('initial state', () => {
    it('is not resizing', () => {
      expect(mgr.isResizing).toBe(false)
      expect(mgr.handle).toBeNull()
    })
  })

  describe('startResize', () => {
    it('begins resize with a handle', () => {
      const item = store.currentBoard.items[0]
      const started = mgr.startResize(item, 'se', 300, 250)
      expect(started).toBe(true)
      expect(mgr.isResizing).toBe(true)
      expect(mgr.handle).toBe('se')
    })

    it('refuses to resize locked items', () => {
      const locked = { ...store.currentBoard.items[0], locked: 1 }
      expect(mgr.startResize(locked, 'se', 300, 250)).toBe(false)
      expect(mgr.isResizing).toBe(false)
    })
  })

  describe('moveResize', () => {
    it('updates item dimensions for SE handle', () => {
      const item = store.currentBoard.items[0]
      mgr.startResize(item, 'se', 300, 250)
      mgr.moveResize(350, 300, 1, false)

      expect(store.batchUpdateItems).toHaveBeenCalledWith(
        expect.arrayContaining([
          expect.objectContaining({
            id: 1,
            width: 250,
            height: 200,
          }),
        ]),
        { skipUndo: true },
      )
    })

    it('updates item dimensions for NW handle (also moves pos)', () => {
      const item = store.currentBoard.items[0]
      mgr.startResize(item, 'nw', 100, 100)
      mgr.moveResize(80, 80, 1, false)

      expect(store.batchUpdateItems).toHaveBeenCalledWith(
        expect.arrayContaining([
          expect.objectContaining({
            id: 1,
            pos_x: 80,
            pos_y: 80,
            width: 220,
            height: 170,
          }),
        ]),
        { skipUndo: true },
      )
    })

    it('enforces minimum size', () => {
      const item = store.currentBoard.items[0]
      mgr.startResize(item, 'se', 300, 250)
      mgr.moveResize(100, 100, 1, false)

      const call = store.batchUpdateItems.mock.calls[0][0][0]
      expect(call.width).toBeGreaterThanOrEqual(20)
      expect(call.height).toBeGreaterThanOrEqual(20)
    })

    it('does nothing when not resizing', () => {
      mgr.moveResize(200, 200, 1, false)
      expect(store.batchUpdateItems).not.toHaveBeenCalled()
    })
  })

  describe('endResize', () => {
    it('commits the final size and clears state', () => {
      const item = store.currentBoard.items[0]
      mgr.startResize(item, 'se', 300, 250)
      mgr.moveResize(350, 300, 1, false)
      store.batchUpdateItems.mockClear()

      mgr.endResize()
      expect(store.batchUpdateItems).toHaveBeenCalled()
      expect(mgr.isResizing).toBe(false)
      expect(mgr.handle).toBeNull()
    })

    it('does nothing when not resizing', () => {
      mgr.endResize()
      expect(store.batchUpdateItems).not.toHaveBeenCalled()
    })
  })
})
