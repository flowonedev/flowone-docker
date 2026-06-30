import { describe, it, expect } from 'vitest'
import { buildSnapTargets, computeSnap, snapToGrid } from '@/addons/moodboards/canvas/spatial/SnapGuides.js'

function makeItem(id, x, y, w, h) {
  return { id, pos_x: x, pos_y: y, width: w, height: h, parent_id: null }
}

describe('SnapGuides', () => {
  describe('buildSnapTargets', () => {
    it('builds snap targets from items excluding dragged ones', () => {
      const items = [
        makeItem(1, 0, 0, 100, 100),
        makeItem(2, 200, 200, 50, 50),
      ]
      const targets = buildSnapTargets(items, [1])
      expect(targets.xs.length).toBe(3)
      expect(targets.ys.length).toBe(3)
      expect(targets.xs.every(t => t.itemId === 2)).toBe(true)
    })

    it('excludes items with parent_id', () => {
      const items = [
        makeItem(1, 0, 0, 100, 100),
        { ...makeItem(2, 50, 50, 30, 30), parent_id: 1 },
      ]
      const targets = buildSnapTargets(items, [])
      expect(targets.xs.length).toBe(3)
    })

    it('includes guide lines', () => {
      const items = [makeItem(1, 0, 0, 100, 100)]
      const guides = [
        { axis: 'x', position: 250 },
        { axis: 'y', position: 300 },
      ]
      const targets = buildSnapTargets(items, [1], guides)
      expect(targets.xs.some(t => t.value === 250)).toBe(true)
      expect(targets.ys.some(t => t.value === 300)).toBe(true)
    })

    it('generates left/center/right for x and top/center/bottom for y', () => {
      const items = [makeItem(1, 100, 200, 60, 40)]
      const targets = buildSnapTargets(items, [])
      const xVals = targets.xs.map(t => t.value)
      expect(xVals).toContain(100)
      expect(xVals).toContain(130)
      expect(xVals).toContain(160)
      const yVals = targets.ys.map(t => t.value)
      expect(yVals).toContain(200)
      expect(yVals).toContain(220)
      expect(yVals).toContain(240)
    })
  })

  describe('computeSnap', () => {
    it('returns zero snap when no targets are close', () => {
      const targets = { xs: [{ value: 1000, label: 'left', itemId: 1 }], ys: [] }
      const bounds = { x: 0, y: 0, width: 50, height: 50 }
      const result = computeSnap(bounds, targets, 1)
      expect(result.snapDx).toBe(0)
      expect(result.snapDy).toBe(0)
    })

    it('snaps to a nearby target within threshold', () => {
      const targets = {
        xs: [{ value: 100, label: 'left', itemId: 1 }],
        ys: [{ value: 200, label: 'top', itemId: 1 }],
      }
      const bounds = { x: 97, y: 197, width: 50, height: 50 }
      const result = computeSnap(bounds, targets, 1)
      expect(result.snapDx).toBe(3)
      expect(result.snapDy).toBe(3)
    })

    it('produces guide lines when snapping', () => {
      const targets = {
        xs: [{ value: 100, label: 'left', itemId: 1 }],
        ys: [],
      }
      const bounds = { x: 98, y: 0, width: 50, height: 50 }
      const result = computeSnap(bounds, targets, 1)
      expect(result.guides.length).toBeGreaterThan(0)
      expect(result.guides[0].axis).toBe('x')
    })

    it('respects zoom for threshold scaling', () => {
      const targets = {
        xs: [{ value: 100, label: 'left', itemId: 1 }],
        ys: [],
      }
      const farBounds = { x: 90, y: 0, width: 50, height: 50 }
      const atZoom1 = computeSnap(farBounds, targets, 1)
      expect(atZoom1.snapDx).toBe(0)

      const closeBounds = { x: 99, y: 0, width: 50, height: 50 }
      const atZoom4 = computeSnap(closeBounds, targets, 4)
      expect(atZoom4.snapDx).not.toBe(0)
    })
  })

  describe('snapToGrid', () => {
    it('snaps to nearest grid point', () => {
      expect(snapToGrid(14)).toBe(10)
      expect(snapToGrid(16)).toBe(20)
      expect(snapToGrid(15)).toBe(20)
    })

    it('handles custom grid size', () => {
      expect(snapToGrid(7, 5)).toBe(5)
      expect(snapToGrid(13, 5)).toBe(15)
    })

    it('snaps zero to zero', () => {
      expect(snapToGrid(0)).toBe(0)
    })

    it('handles negative values', () => {
      expect(snapToGrid(-14)).toBe(-10)
      expect(snapToGrid(-16)).toBe(-20)
    })
  })
})
