import { describe, it, expect, vi, beforeEach } from 'vitest'
import ItemDropHandler from '@/addons/moodboards/canvas/dnd/ItemDropHandler.js'

describe('ItemDropHandler', () => {
  let store
  let handler

  beforeEach(() => {
    store = {
      batchUpdateItems: vi.fn(),
    }
    handler = new ItemDropHandler(store)
  })

  describe('handleImageOnShape', () => {
    it('sets mask_image_url on a shape item', () => {
      const shapeItem = { id: 1, type: 'shape', style_data: { fills: [] } }
      const result = handler.handleImageOnShape('http://img.png', shapeItem)
      expect(result).toBe(true)
      expect(store.batchUpdateItems).toHaveBeenCalledWith([{
        id: 1,
        style_data: expect.objectContaining({
          mask_image_url: 'http://img.png',
          mask_image_fit: 'cover',
        }),
      }])
    })

    it('returns false for non-shape items', () => {
      expect(handler.handleImageOnShape('url', { type: 'text' })).toBe(false)
      expect(handler.handleImageOnShape('url', null)).toBe(false)
    })
  })

  describe('handleImageOnText', () => {
    it('sets text_clip_image on a text item', () => {
      const textItem = { id: 2, type: 'text', style_data: {} }
      const result = handler.handleImageOnText('http://img.png', textItem)
      expect(result).toBe(true)
      expect(store.batchUpdateItems).toHaveBeenCalledWith([{
        id: 2,
        style_data: expect.objectContaining({ text_clip_image: 'http://img.png' }),
      }])
    })

    it('returns false for non-text items', () => {
      expect(handler.handleImageOnText('url', { type: 'shape' })).toBe(false)
    })
  })

  describe('handleFilesToImageSet', () => {
    it('returns true for image_set items', () => {
      expect(handler.handleFilesToImageSet([], { type: 'image_set' })).toBe(true)
    })

    it('returns false for non image_set items', () => {
      expect(handler.handleFilesToImageSet([], { type: 'image' })).toBe(false)
    })
  })

  describe('findColumnAtPoint', () => {
    it('finds column containing the point', () => {
      const items = [
        { id: 1, type: 'column', pos_x: 0, pos_y: 0, width: 200, height: 400 },
        { id: 2, type: 'column', pos_x: 200, pos_y: 0, width: 200, height: 400 },
        { id: 3, type: 'shape', pos_x: 0, pos_y: 0, width: 100, height: 100 },
      ]
      expect(handler.findColumnAtPoint(100, 200, items).id).toBe(1)
      expect(handler.findColumnAtPoint(300, 200, items).id).toBe(2)
    })

    it('returns null when no column at point', () => {
      const items = [
        { id: 1, type: 'column', pos_x: 0, pos_y: 0, width: 100, height: 100 },
      ]
      expect(handler.findColumnAtPoint(500, 500, items)).toBeNull()
    })

    it('ignores non-column items', () => {
      const items = [
        { id: 1, type: 'shape', pos_x: 0, pos_y: 0, width: 1000, height: 1000 },
      ]
      expect(handler.findColumnAtPoint(50, 50, items)).toBeNull()
    })
  })
})
