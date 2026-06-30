import { describe, it, expect } from 'vitest'
import {
  getMeasureTransform,
  canvasPointToMeasure,
  snapMeasureAngle,
} from '@/addons/moodboards/canvas/tools/MeasureToolBridge.js'

describe('MeasureToolBridge', () => {
  describe('getMeasureTransform', () => {
    it('returns current pan/zoom from store', () => {
      const store = { panX: 10, panY: 20, zoom: 2.5 }
      const result = getMeasureTransform(store)
      expect(result.panX).toBe(10)
      expect(result.panY).toBe(20)
      expect(result.zoom).toBe(2.5)
    })
  })

  describe('canvasPointToMeasure', () => {
    it('passes through canvas coordinates', () => {
      const result = canvasPointToMeasure(100, 200)
      expect(result.x).toBe(100)
      expect(result.y).toBe(200)
    })
  })

  describe('snapMeasureAngle', () => {
    it('snaps to 5-degree increments', () => {
      const result = snapMeasureAngle(0, 0, 100, 3)
      expect(result.y).toBeCloseTo(0, 0)
    })

    it('preserves distance when snapping', () => {
      const result = snapMeasureAngle(0, 0, 100, 10)
      const dist = Math.hypot(result.x, result.y)
      const origDist = Math.hypot(100, 10)
      expect(dist).toBeCloseTo(origDist)
    })

    it('returns exact endpoint for aligned angles', () => {
      const result = snapMeasureAngle(0, 0, 100, 0)
      expect(result.x).toBeCloseTo(100)
      expect(result.y).toBeCloseTo(0)
    })
  })
})
