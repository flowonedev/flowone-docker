import { describe, it, expect } from 'vitest'
import {
  snapPositionToGrid,
  snapDimensionToGrid,
  shouldSnapToGrid,
} from '@/addons/moodboards/canvas/spatial/GridSnap.js'

describe('GridSnap', () => {
  describe('snapPositionToGrid', () => {
    it('snaps x and y to default 10px grid', () => {
      const result = snapPositionToGrid(14, 27)
      expect(result.x).toBe(10)
      expect(result.y).toBe(30)
    })

    it('handles exact grid positions', () => {
      const result = snapPositionToGrid(20, 30)
      expect(result.x).toBe(20)
      expect(result.y).toBe(30)
    })

    it('handles negative positions', () => {
      const result = snapPositionToGrid(-14, -27)
      expect(result.x).toBe(-10)
      expect(result.y).toBe(-30)
    })

    it('uses custom grid size', () => {
      const result = snapPositionToGrid(7, 13, 5)
      expect(result.x).toBe(5)
      expect(result.y).toBe(15)
    })

    it('snaps zero to zero', () => {
      const result = snapPositionToGrid(0, 0)
      expect(result.x).toBe(0)
      expect(result.y).toBe(0)
    })
  })

  describe('snapDimensionToGrid', () => {
    it('snaps a single value', () => {
      expect(snapDimensionToGrid(54)).toBe(50)
      expect(snapDimensionToGrid(56)).toBe(60)
    })

    it('uses custom grid size', () => {
      expect(snapDimensionToGrid(12, 5)).toBe(10)
      expect(snapDimensionToGrid(13, 5)).toBe(15)
    })
  })

  describe('shouldSnapToGrid', () => {
    it('returns true for truthy values', () => {
      expect(shouldSnapToGrid(true)).toBe(true)
      expect(shouldSnapToGrid(1)).toBe(true)
    })

    it('returns false for falsy values', () => {
      expect(shouldSnapToGrid(false)).toBe(false)
      expect(shouldSnapToGrid(null)).toBe(false)
      expect(shouldSnapToGrid(undefined)).toBe(false)
      expect(shouldSnapToGrid(0)).toBe(false)
    })
  })
})
