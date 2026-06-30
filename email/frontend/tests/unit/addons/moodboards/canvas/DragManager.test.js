import { describe, it, expect, beforeEach, vi } from 'vitest'
import DragManager from '@/addons/moodboards/canvas/interaction/DragManager.js'

describe('DragManager', () => {
  let store
  let container
  let mgr

  beforeEach(() => {
    store = {
      zoom: 1,
      panX: 0,
      panY: 0,
      guides: [],
      isDragging: false,
      selectedItemIds: new Set([1]),
      currentBoard: {
        items: [
          { id: 1, type: 'group', pos_x: 100, pos_y: 100, width: 300, height: 200 },
          { id: 2, type: 'shape', parent_id: 1, pos_x: 120, pos_y: 120, width: 80, height: 80 },
          { id: 3, type: 'shape', parent_id: 1, pos_x: 260, pos_y: 180, width: 80, height: 80 },
        ],
        connections: [
          {
            id: 10,
            from_item_id: 2,
            to_item_id: 3,
            bend_x: 210,
            bend_y: 150,
            bend2_x: 240,
            bend2_y: 220,
          },
        ],
      },
      getChildrenOf: vi.fn((parentId) =>
        store.currentBoard.items.filter(item => item.parent_id === parentId)
      ),
      batchUpdateItems: vi.fn((updates) => {
        for (const update of updates) {
          const item = store.currentBoard.items.find(entry => entry.id === update.id)
          if (item) Object.assign(item, update)
        }
      }),
      updateConnection: vi.fn(),
    }

    container = {
      getBoundingClientRect: () => ({ left: 0, top: 0, width: 1000, height: 800 }),
    }

    mgr = new DragManager(store, container)
  })

  it('moves custom bend points with an internal grouped connection during drag', () => {
    expect(mgr.startDrag(0, 0, false)).toBe(true)

    mgr.moveDrag(30, 20, false, false)

    const conn = store.currentBoard.connections[0]
    expect(conn.bend_x).toBe(240)
    expect(conn.bend_y).toBe(170)
    expect(conn.bend2_x).toBe(270)
    expect(conn.bend2_y).toBe(240)

    mgr.endDrag(false)

    expect(store.updateConnection).toHaveBeenCalledWith(10, {
      bend_x: 240,
      bend_y: 170,
      bend2_x: 270,
      bend2_y: 240,
    })
  })

  it('moves frame descendants when dragging a selected frame', () => {
    store.currentBoard.items = [
      { id: 1, type: 'frame', pos_x: 100, pos_y: 100, width: 300, height: 200 },
      { id: 2, type: 'text', parent_id: 1, pos_x: 120, pos_y: 120, width: 80, height: 80 },
    ]
    store.currentBoard.connections = []
    store.selectedItemIds = new Set([1])

    expect(mgr.startDrag(0, 0, false)).toBe(true)
    mgr.moveDrag(25, 15, false, false)

    expect(store.currentBoard.items.find(item => item.id === 1)).toMatchObject({ pos_x: 125, pos_y: 115 })
    expect(store.currentBoard.items.find(item => item.id === 2)).toMatchObject({ pos_x: 145, pos_y: 135 })
  })
})
