import { describe, it, expect } from 'vitest'
import { computeRepeatGridLayout } from '@/addons/moodboards/canvas/renderer/types/RepeatGridRenderer.js'

describe('RepeatGridRenderer', () => {
  describe('computeRepeatGridLayout', () => {
    it('returns empty for no template child', () => {
      const parent = { style_data: { repeat_columns: 3, repeat_rows: 2 } }
      expect(computeRepeatGridLayout(parent, null)).toEqual([])
    })

    it('produces correct tile positions for 2x2 grid', () => {
      const parent = {
        style_data: {
          repeat_columns: 2,
          repeat_rows: 2,
          repeat_gap_x: 10,
          repeat_gap_y: 10,
        },
      }
      const template = { width: 100, height: 80 }
      const result = computeRepeatGridLayout(parent, template)

      expect(result).toHaveLength(4)
      expect(result[0]).toEqual({ col: 0, row: 0, x: 0, y: 0 })
      expect(result[1]).toEqual({ col: 1, row: 0, x: 110, y: 0 })
      expect(result[2]).toEqual({ col: 0, row: 1, x: 0, y: 90 })
      expect(result[3]).toEqual({ col: 1, row: 1, x: 110, y: 90 })
    })

    it('defaults to 1x1 grid when style_data is empty', () => {
      const parent = { style_data: {} }
      const template = { width: 50, height: 50 }
      const result = computeRepeatGridLayout(parent, template)

      expect(result).toHaveLength(1)
      expect(result[0]).toEqual({ col: 0, row: 0, x: 0, y: 0 })
    })

    it('uses default gaps when not specified', () => {
      const parent = {
        style_data: { repeat_columns: 2, repeat_rows: 1 },
      }
      const template = { width: 100, height: 100 }
      const result = computeRepeatGridLayout(parent, template)

      expect(result).toHaveLength(2)
      expect(result[1].x).toBe(110)
    })

    it('handles 3x3 grid with custom gaps', () => {
      const parent = {
        style_data: {
          repeat_columns: 3,
          repeat_rows: 3,
          repeat_gap_x: 20,
          repeat_gap_y: 15,
        },
      }
      const template = { width: 50, height: 40 }
      const result = computeRepeatGridLayout(parent, template)

      expect(result).toHaveLength(9)
      expect(result[8]).toEqual({ col: 2, row: 2, x: 140, y: 110 })
    })
  })
})
