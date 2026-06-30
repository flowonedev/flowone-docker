import { describe, it, expect } from 'vitest'
import { verifyPenToolAlignment, createPenShapeFromTool } from '@/addons/moodboards/canvas/tools/PenToolBridge.js'

describe('PenToolBridge', () => {
  describe('verifyPenToolAlignment', () => {
    it('returns current pan/zoom from store', () => {
      const store = { panX: 100, panY: 200, zoom: 1.5 }
      const result = verifyPenToolAlignment(store)
      expect(result.panX).toBe(100)
      expect(result.panY).toBe(200)
      expect(result.zoom).toBe(1.5)
    })
  })

  describe('createPenShapeFromTool', () => {
    it('creates a pen_shape item from tool output', () => {
      const pathData = {
        pos_x: 50,
        pos_y: 100,
        width: 200,
        height: 150,
        pen_svg_path: 'M0 0 L100 100',
      }
      const result = createPenShapeFromTool(pathData)
      expect(result.type).toBe('pen_shape')
      expect(result.pos_x).toBe(50)
      expect(result.pos_y).toBe(100)
      expect(result.width).toBe(200)
      expect(result.height).toBe(150)
      expect(result.style_data.pen_svg_path).toBe('M0 0 L100 100')
    })

    it('provides default fills and strokes', () => {
      const result = createPenShapeFromTool({ pos_x: 0, pos_y: 0, width: 100, height: 100 })
      expect(result.style_data.fills.length).toBe(1)
      expect(result.style_data.fills[0].type).toBe('SOLID')
      expect(result.style_data.strokes.length).toBe(1)
    })

    it('uses provided fills/strokes when available', () => {
      const fills = [{ type: 'SOLID', color: { r: 1, g: 0, b: 0, a: 1 }, visible: true }]
      const result = createPenShapeFromTool({
        pos_x: 0, pos_y: 0, width: 100, height: 100,
        fills,
      })
      expect(result.style_data.fills).toBe(fills)
    })
  })
})
