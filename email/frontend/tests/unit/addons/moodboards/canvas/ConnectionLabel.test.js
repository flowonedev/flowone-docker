import { describe, it, expect, vi } from 'vitest'

vi.mock('pixi.js', () => ({
  Graphics: vi.fn().mockImplementation(() => ({
    moveTo: vi.fn().mockReturnThis(),
    lineTo: vi.fn().mockReturnThis(),
    bezierCurveTo: vi.fn().mockReturnThis(),
    closePath: vi.fn().mockReturnThis(),
    ellipse: vi.fn().mockReturnThis(),
    circle: vi.fn().mockReturnThis(),
    rect: vi.fn().mockReturnThis(),
    roundRect: vi.fn().mockReturnThis(),
    fill: vi.fn().mockReturnThis(),
    stroke: vi.fn().mockReturnThis(),
    addChild: vi.fn(),
    children: [],
    filters: null,
    label: '',
  })),
  Container: vi.fn().mockImplementation(() => ({
    addChild: vi.fn(),
    addChildAt: vi.fn(),
    removeChild: vi.fn(),
    removeChildren: vi.fn(),
    children: [],
    position: { set: vi.fn() },
    label: '',
    mask: null,
    filters: null,
    eventMode: 'none',
  })),
  Text: vi.fn().mockImplementation(({ text }) => ({
    text,
    anchor: { set: vi.fn() },
    position: { set: vi.fn() },
    style: {},
    label: '',
  })),
  TextStyle: vi.fn().mockImplementation((opts) => opts),
  Sprite: vi.fn().mockImplementation(() => ({
    width: 0,
    height: 0,
    alpha: 1,
    mask: null,
    position: { set: vi.fn() },
    anchor: { set: vi.fn() },
  })),
  Texture: { from: vi.fn() },
  BlurFilter: vi.fn(),
}))

describe('ConnectionLabel', () => {
  it('createConnectionLabel returns null for connections without label', async () => {
    const { createConnectionLabel } = await import('@/addons/moodboards/canvas/renderer/types/ConnectionRenderer.js')
    const result = createConnectionLabel({ id: 1, line_color: '#333' })
    expect(result).toBeNull()
  })

  it('createConnectionLabel returns a Text object when label exists', async () => {
    const { createConnectionLabel } = await import('@/addons/moodboards/canvas/renderer/types/ConnectionRenderer.js')
    const result = createConnectionLabel({ id: 1, label: 'Test Label', line_color: '#333' })
    expect(result).not.toBeNull()
    expect(result.text).toBe('Test Label')
  })

  it('getConnectionCurve returns null when items are missing', async () => {
    const { getConnectionCurve } = await import('@/addons/moodboards/canvas/renderer/types/ConnectionRenderer.js')
    const itemMap = new Map()
    const conn = { from_item_id: 1, to_item_id: 2 }
    const result = getConnectionCurve(conn, itemMap)
    expect(result).toBeNull()
  })

  it('getConnectionCurve returns curve when both items exist', async () => {
    const { getConnectionCurve } = await import('@/addons/moodboards/canvas/renderer/types/ConnectionRenderer.js')
    const itemMap = new Map([
      [1, { id: 1, pos_x: 0, pos_y: 0, width: 100, height: 100 }],
      [2, { id: 2, pos_x: 300, pos_y: 200, width: 100, height: 100 }],
    ])
    const conn = { from_item_id: 1, to_item_id: 2 }
    const result = getConnectionCurve(conn, itemMap)
    expect(result).not.toBeNull()
    expect(result).toHaveProperty('x1')
    expect(result).toHaveProperty('y1')
    expect(result).toHaveProperty('cx1')
    expect(result).toHaveProperty('cy1')
    expect(result).toHaveProperty('cx2')
    expect(result).toHaveProperty('cy2')
    expect(result).toHaveProperty('x2')
    expect(result).toHaveProperty('y2')
  })
})
