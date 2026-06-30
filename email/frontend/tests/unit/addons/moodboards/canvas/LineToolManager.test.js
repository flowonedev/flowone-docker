import { describe, it, expect, beforeEach } from 'vitest'
import LineToolManager from '@/addons/moodboards/canvas/tools/LineToolManager.js'

describe('LineToolManager', () => {
  let store
  let container
  let mgr

  beforeEach(() => {
    store = { panX: 0, panY: 0, zoom: 1 }
    container = {
      getBoundingClientRect: () => ({ left: 0, top: 0, width: 800, height: 600 }),
    }
    mgr = new LineToolManager(store, container)
  })

  describe('initial state', () => {
    it('is not drawing', () => {
      expect(mgr.isDrawing).toBe(false)
    })

    it('has no start/end points', () => {
      expect(mgr.startPoint).toBeNull()
      expect(mgr.endPoint).toBeNull()
    })
  })

  describe('startLine', () => {
    it('begins drawing mode', () => {
      mgr.startLine(100, 100)
      expect(mgr.isDrawing).toBe(true)
      expect(mgr.startPoint).toEqual({ x: 100, y: 100 })
    })
  })

  describe('moveLine', () => {
    it('updates the end point', () => {
      mgr.startLine(100, 100)
      mgr.moveLine(200, 150, false)
      expect(mgr.endPoint).toEqual({ x: 200, y: 150 })
    })

    it('snaps to 45-degree increments with shift', () => {
      mgr.startLine(100, 100)
      mgr.moveLine(200, 110, true)
      expect(mgr.endPoint.y).toBeCloseTo(100)
    })

    it('does nothing when not drawing', () => {
      mgr.moveLine(200, 200, false)
      expect(mgr.endPoint).toBeNull()
    })
  })

  describe('endLine', () => {
    it('returns line data for a sufficient drag', () => {
      mgr.startLine(100, 100)
      mgr.moveLine(250, 100, false)
      const result = mgr.endLine(250, 100)
      expect(result).not.toBeNull()
      expect(result.type).toBe('line')
      expect(result.width).toBe(150)
      expect(result.style_data.line_color).toBe('#333333')
    })

    it('enters placed mode for short drag (click-click mode)', () => {
      mgr.startLine(100, 100)
      mgr.moveLine(102, 100, false)
      const result = mgr.endLine(102, 100)
      expect(result).toBeNull()
      expect(mgr.isDrawing).toBe(true)
    })

    it('returns null when not drawing', () => {
      const result = mgr.endLine(200, 200)
      expect(result).toBeNull()
    })
  })

  describe('cancel', () => {
    it('resets all state', () => {
      mgr.startLine(100, 100)
      mgr.moveLine(200, 200, false)
      mgr.cancel()
      expect(mgr.isDrawing).toBe(false)
      expect(mgr.startPoint).toBeNull()
      expect(mgr.endPoint).toBeNull()
    })
  })

  describe('with pan and zoom', () => {
    it('converts screen coords to canvas coords', () => {
      store.panX = 50
      store.panY = 50
      store.zoom = 2

      mgr.startLine(150, 150)
      expect(mgr.startPoint.x).toBe(50)
      expect(mgr.startPoint.y).toBe(50)
    })
  })
})
