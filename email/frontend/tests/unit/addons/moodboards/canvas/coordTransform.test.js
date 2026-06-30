import { describe, it, expect } from 'vitest'
import {
  screenToCanvas,
  canvasToScreen,
  canvasBoundsToScreen,
  screenRectToCanvas,
  getViewportBounds,
} from '@/addons/moodboards/canvas/utils/coordTransform.js'

describe('coordTransform', () => {
  describe('screenToCanvas', () => {
    it('converts with no pan/zoom', () => {
      const result = screenToCanvas(100, 200, 0, 0, 1)
      expect(result.x).toBe(100)
      expect(result.y).toBe(200)
    })

    it('accounts for pan offset', () => {
      const result = screenToCanvas(150, 250, 50, 50, 1)
      expect(result.x).toBe(100)
      expect(result.y).toBe(200)
    })

    it('accounts for zoom', () => {
      const result = screenToCanvas(200, 400, 0, 0, 2)
      expect(result.x).toBe(100)
      expect(result.y).toBe(200)
    })

    it('accounts for combined pan and zoom', () => {
      const result = screenToCanvas(250, 450, 50, 50, 2)
      expect(result.x).toBe(100)
      expect(result.y).toBe(200)
    })

    it('handles fractional zoom', () => {
      const result = screenToCanvas(50, 50, 0, 0, 0.5)
      expect(result.x).toBe(100)
      expect(result.y).toBe(100)
    })
  })

  describe('canvasToScreen', () => {
    it('is the inverse of screenToCanvas', () => {
      const panX = 30, panY = -20, zoom = 1.5
      const screen = canvasToScreen(100, 200, panX, panY, zoom)
      const back = screenToCanvas(screen.x, screen.y, panX, panY, zoom)
      expect(back.x).toBeCloseTo(100)
      expect(back.y).toBeCloseTo(200)
    })

    it('converts at zoom 1 with no pan', () => {
      const result = canvasToScreen(100, 200, 0, 0, 1)
      expect(result.x).toBe(100)
      expect(result.y).toBe(200)
    })

    it('applies zoom and pan', () => {
      const result = canvasToScreen(100, 200, 10, 20, 2)
      expect(result.x).toBe(210)
      expect(result.y).toBe(420)
    })
  })

  describe('canvasBoundsToScreen', () => {
    it('transforms item bounds to screen coordinates', () => {
      const item = { pos_x: 50, pos_y: 100, width: 200, height: 100 }
      const result = canvasBoundsToScreen(item, 10, 20, 2)
      expect(result.x).toBe(110)
      expect(result.y).toBe(220)
      expect(result.width).toBe(400)
      expect(result.height).toBe(200)
    })

    it('handles missing dimensions', () => {
      const item = { pos_x: 10, pos_y: 20 }
      const result = canvasBoundsToScreen(item, 0, 0, 1)
      expect(result.width).toBe(0)
      expect(result.height).toBe(0)
    })
  })

  describe('screenRectToCanvas', () => {
    it('converts a screen rectangle to canvas coords', () => {
      const rect = { x: 100, y: 100, width: 200, height: 200 }
      const result = screenRectToCanvas(rect, 0, 0, 2)
      expect(result.x).toBe(50)
      expect(result.y).toBe(50)
      expect(result.width).toBe(100)
      expect(result.height).toBe(100)
    })
  })

  describe('getViewportBounds', () => {
    it('returns canvas-space bounds of the viewport', () => {
      const bounds = getViewportBounds(800, 600, 0, 0, 1)
      expect(bounds.minX).toBeCloseTo(0)
      expect(bounds.minY).toBeCloseTo(0)
      expect(bounds.maxX).toBe(800)
      expect(bounds.maxY).toBe(600)
    })

    it('accounts for pan and zoom', () => {
      const bounds = getViewportBounds(800, 600, 100, 50, 2)
      expect(bounds.minX).toBe(-50)
      expect(bounds.minY).toBe(-25)
      expect(bounds.maxX).toBe(350)
      expect(bounds.maxY).toBe(275)
    })

    it('applies padding buffer', () => {
      const bounds = getViewportBounds(800, 600, 0, 0, 1, 100)
      expect(bounds.minX).toBe(-100)
      expect(bounds.minY).toBe(-100)
      expect(bounds.maxX).toBe(900)
      expect(bounds.maxY).toBe(700)
    })
  })
})
