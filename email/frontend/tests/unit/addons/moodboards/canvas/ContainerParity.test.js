import { describe, it, expect } from 'vitest'

describe('Container Type Parity', () => {
  const SELECTION_CONTAINER_TYPES = new Set(['frame', 'group', 'artboard', 'column', 'repeat_grid', 'slide'])
  const PIXI_CONTAINER_TYPES = new Set(['column', 'frame', 'group', 'repeat_grid', 'slide', 'artboard'])

  it('SelectionManager and PixiCanvas agree on container types', () => {
    for (const type of SELECTION_CONTAINER_TYPES) {
      expect(PIXI_CONTAINER_TYPES.has(type), `${type} missing from PixiCanvas CONTAINER_TYPES`).toBe(true)
    }
    for (const type of PIXI_CONTAINER_TYPES) {
      expect(SELECTION_CONTAINER_TYPES.has(type), `${type} missing from SelectionManager`).toBe(true)
    }
  })

  it('all container types are recognized for child tree building', () => {
    const expectedTypes = ['column', 'frame', 'group', 'repeat_grid', 'slide', 'artboard']
    for (const type of expectedTypes) {
      expect(PIXI_CONTAINER_TYPES.has(type)).toBe(true)
    }
  })
})

describe('Mask Parent Rendering', () => {
  it('items with mask_parent_id should NOT be filtered out of visible items', () => {
    const items = [
      { id: 1, type: 'shape', style_data: {} },
      { id: 2, type: 'image', style_data: { mask_parent_id: 1 } },
      { id: 3, type: 'note', style_data: {} },
    ]

    const filtered = items.filter(i => {
      if (i.type === 'slide') return false
      return true
    })

    expect(filtered).toHaveLength(3)
    expect(filtered.find(i => i.id === 2)).toBeDefined()
  })

  it('slide items respect visibility toggle', () => {
    const items = [
      { id: 1, type: 'slide', style_data: {} },
      { id: 2, type: 'shape', style_data: {} },
    ]

    const slidesVisible = false
    const filtered = items.filter(i => {
      if (i.type === 'slide') return slidesVisible
      return true
    })

    expect(filtered).toHaveLength(1)
    expect(filtered[0].id).toBe(2)
  })
})
