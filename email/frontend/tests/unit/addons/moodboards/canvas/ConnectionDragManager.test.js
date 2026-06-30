import { describe, it, expect, vi, beforeEach } from 'vitest'
import ConnectionDragManager from '@/addons/moodboards/canvas/interaction/ConnectionDragManager.js'
import SpatialIndex from '@/addons/moodboards/canvas/spatial/SpatialIndex.js'

describe('ConnectionDragManager', () => {
  let store
  let spatial
  let container
  let mgr

  beforeEach(() => {
    store = {
      panX: 0, panY: 0, zoom: 1,
      connectingFrom: null,
    }
    spatial = new SpatialIndex()
    spatial.buildFromItems([
      { id: 1, pos_x: 0, pos_y: 0, width: 100, height: 100, z_index: 0 },
      { id: 2, pos_x: 300, pos_y: 300, width: 100, height: 100, z_index: 0 },
    ])
    container = {
      getBoundingClientRect: () => ({ left: 0, top: 0, width: 800, height: 600 }),
    }
    mgr = new ConnectionDragManager(store, spatial, container)
  })

  describe('initial state', () => {
    it('is not active', () => {
      expect(mgr.isActive).toBe(false)
      expect(mgr.fromItemId).toBeNull()
      expect(mgr.endpoint).toBeNull()
    })
  })

  describe('startConnection', () => {
    it('activates connection mode from an item', () => {
      mgr.startConnection(1)
      expect(mgr.isActive).toBe(true)
      expect(mgr.fromItemId).toBe(1)
      expect(store.connectingFrom).toBe(1)
    })
  })

  describe('moveConnection', () => {
    it('tracks the mouse position', () => {
      mgr.startConnection(1)
      mgr.moveConnection(200, 200)
      expect(mgr.endpoint).not.toBeNull()
      expect(mgr.endpoint.x).toBe(200)
      expect(mgr.endpoint.y).toBe(200)
    })

    it('does nothing when not active', () => {
      mgr.moveConnection(200, 200)
      expect(mgr.endpoint).toBeNull()
    })
  })

  describe('endConnection', () => {
    it('returns from/to ids when dropped on a different item', () => {
      mgr.startConnection(1)
      const result = mgr.endConnection(350, 350)
      expect(result).toEqual({ fromId: 1, toId: 2 })
    })

    it('returns null when not dropped on an item', () => {
      mgr.startConnection(1)
      const result = mgr.endConnection(600, 600)
      expect(result).toBeNull()
    })

    it('returns null when dropped on the source item', () => {
      mgr.startConnection(1)
      const result = mgr.endConnection(50, 50)
      expect(result).toBeNull()
    })

    it('clears state after ending', () => {
      mgr.startConnection(1)
      mgr.endConnection(350, 350)
      expect(mgr.isActive).toBe(false)
      expect(store.connectingFrom).toBeNull()
    })
  })

  describe('cancel', () => {
    it('clears all state', () => {
      mgr.startConnection(1)
      mgr.moveConnection(200, 200)
      mgr.cancel()
      expect(mgr.isActive).toBe(false)
      expect(mgr.fromItemId).toBeNull()
      expect(mgr.endpoint).toBeNull()
      expect(store.connectingFrom).toBeNull()
    })
  })
})
