import { describe, it, expect, beforeEach } from 'vitest'
import SpatialIndex from '@/addons/moodboards/canvas/spatial/SpatialIndex.js'

function makeItem(id, x, y, w, h, z = 0, parentId = null) {
  return { id, pos_x: x, pos_y: y, width: w, height: h, z_index: z, type: 'shape', parent_id: parentId }
}

describe('SpatialIndex', () => {
  let index

  beforeEach(() => {
    index = new SpatialIndex()
  })

  describe('buildFromItems', () => {
    it('populates index with root items', () => {
      const items = [
        makeItem(1, 0, 0, 100, 100),
        makeItem(2, 200, 200, 50, 50),
      ]
      index.buildFromItems(items)
      expect(index.size).toBe(2)
    })

    it('includes child items (those with parent_id)', () => {
      const items = [
        makeItem(1, 0, 0, 100, 100),
        makeItem(2, 10, 10, 30, 30, 0, 1),
      ]
      index.buildFromItems(items)
      expect(index.size).toBe(2)
    })

    it('clears previous entries on rebuild', () => {
      index.buildFromItems([makeItem(1, 0, 0, 50, 50)])
      expect(index.size).toBe(1)
      index.buildFromItems([makeItem(2, 0, 0, 50, 50), makeItem(3, 60, 60, 50, 50)])
      expect(index.size).toBe(2)
    })
  })

  describe('insert / remove / update', () => {
    it('inserts a single item', () => {
      index.insert(makeItem(1, 10, 10, 50, 50))
      expect(index.size).toBe(1)
    })

    it('inserts child items too', () => {
      index.insert(makeItem(1, 10, 10, 50, 50, 0, 99))
      expect(index.size).toBe(1)
    })

    it('removes by id', () => {
      index.insert(makeItem(1, 10, 10, 50, 50))
      index.remove(1)
      expect(index.size).toBe(0)
    })

    it('remove is idempotent for missing ids', () => {
      index.remove(999)
      expect(index.size).toBe(0)
    })

    it('updates an item position', () => {
      index.insert(makeItem(1, 0, 0, 50, 50))
      index.update(makeItem(1, 100, 100, 50, 50))
      expect(index.size).toBe(1)

      const hits = index.queryPoint(0, 0)
      expect(hits.length).toBe(0)

      const hits2 = index.queryPoint(125, 125)
      expect(hits2.length).toBe(1)
      expect(hits2[0].id).toBe(1)
    })
  })

  describe('queryRect', () => {
    it('finds items within bounding box', () => {
      index.buildFromItems([
        makeItem(1, 0, 0, 100, 100, 1),
        makeItem(2, 200, 200, 50, 50, 2),
        makeItem(3, 500, 500, 30, 30, 3),
      ])

      const results = index.queryRect({ minX: -10, minY: -10, maxX: 150, maxY: 150 })
      expect(results.length).toBe(1)
      expect(results[0].id).toBe(1)
    })

    it('returns results sorted by z_index descending', () => {
      index.buildFromItems([
        makeItem(1, 0, 0, 100, 100, 1),
        makeItem(2, 50, 50, 100, 100, 5),
        makeItem(3, 25, 25, 100, 100, 3),
      ])

      const results = index.queryRect({ minX: 0, minY: 0, maxX: 200, maxY: 200 })
      expect(results.length).toBe(3)
      expect(results[0].id).toBe(2)
      expect(results[1].id).toBe(3)
      expect(results[2].id).toBe(1)
    })

    it('returns empty array when no items match', () => {
      index.buildFromItems([makeItem(1, 0, 0, 10, 10)])
      const results = index.queryRect({ minX: 100, minY: 100, maxX: 200, maxY: 200 })
      expect(results.length).toBe(0)
    })
  })

  describe('queryPoint', () => {
    it('finds items at a specific point', () => {
      index.buildFromItems([
        makeItem(1, 0, 0, 100, 100),
        makeItem(2, 200, 200, 50, 50),
      ])

      const hits = index.queryPoint(50, 50)
      expect(hits.length).toBe(1)
      expect(hits[0].id).toBe(1)
    })

    it('returns empty for miss', () => {
      index.buildFromItems([makeItem(1, 0, 0, 10, 10)])
      expect(index.queryPoint(50, 50).length).toBe(0)
    })

    it('returns overlapping items sorted by z_index', () => {
      index.buildFromItems([
        makeItem(1, 0, 0, 100, 100, 1),
        makeItem(2, 0, 0, 100, 100, 10),
      ])
      const hits = index.queryPoint(50, 50)
      expect(hits.length).toBe(2)
      expect(hits[0].id).toBe(2)
    })

    it('prefers higher draw order when provided', () => {
      index.buildFromEntries([
        { minX: 0, minY: 0, maxX: 100, maxY: 100, id: 'group', z_index: 10, draw_order: 1, type: 'group', parent_id: null, locked: false },
        { minX: 10, minY: 10, maxX: 60, maxY: 60, id: 'child', z_index: 1, draw_order: 2, type: 'image', parent_id: 'group', locked: false },
      ])
      const hits = index.queryPoint(20, 20)
      expect(hits.length).toBe(2)
      expect(hits[0].id).toBe('child')
      expect(hits[1].id).toBe('group')
    })
  })

  describe('clear', () => {
    it('removes all entries', () => {
      index.buildFromItems([makeItem(1, 0, 0, 50, 50), makeItem(2, 60, 60, 50, 50)])
      index.clear()
      expect(index.size).toBe(0)
    })
  })
})
