import { describe, it, expect, vi, beforeEach } from 'vitest'

vi.mock('pixi.js', () => {
  const makeGraphics = () => ({
    moveTo: vi.fn().mockReturnThis(),
    lineTo: vi.fn().mockReturnThis(),
    arcTo: vi.fn().mockReturnThis(),
    bezierCurveTo: vi.fn().mockReturnThis(),
    closePath: vi.fn().mockReturnThis(),
    ellipse: vi.fn().mockReturnThis(),
    rect: vi.fn().mockReturnThis(),
    roundRect: vi.fn().mockReturnThis(),
    fill: vi.fn().mockReturnThis(),
    stroke: vi.fn().mockReturnThis(),
    addChild: vi.fn(),
    children: [],
    mask: null,
    label: '',
  })
  return {
    Graphics: vi.fn().mockImplementation(makeGraphics),
    Text: vi.fn(),
    TextStyle: vi.fn().mockImplementation((opts) => opts),
    FillGradient: vi.fn().mockImplementation((opts) => ({ ...opts, _isGradient: true })),
    Color: vi.fn(),
    Texture: { from: vi.fn(() => ({ destroyed: false, destroy: vi.fn() })) },
  }
})

import {
  parseColor,
  getFillStyle,
  getFillStyles,
  getStrokeStyle,
  getCornerRadius,
  getEffects,
  getBlendMode,
  getStyleProps,
  applyFill,
  applyFills,
  applyEffects,
  drawRoundedRect,
} from '@/addons/moodboards/canvas/utils/styleToPixi.js'

function mockGraphics() {
  return {
    moveTo: vi.fn().mockReturnThis(),
    lineTo: vi.fn().mockReturnThis(),
    arcTo: vi.fn().mockReturnThis(),
    closePath: vi.fn().mockReturnThis(),
    rect: vi.fn().mockReturnThis(),
    roundRect: vi.fn().mockReturnThis(),
    fill: vi.fn().mockReturnThis(),
    stroke: vi.fn().mockReturnThis(),
  }
}

describe('parseColor', () => {
  it('parses hex strings', () => {
    expect(parseColor('#ff0000')).toEqual({ color: 0xff0000, alpha: 1 })
  })

  it('parses rgba strings with alpha', () => {
    expect(parseColor('rgba(255, 0, 0, 0.5)')).toEqual({ color: 0xff0000, alpha: 0.5 })
  })

  it('parses rgb strings', () => {
    expect(parseColor('rgb(0, 128, 255)')).toEqual({ color: 0x0080ff, alpha: 1 })
  })

  it('parses Figma color objects (0..1 channels)', () => {
    expect(parseColor({ r: 1, g: 0, b: 0, a: 0.8 })).toEqual({ color: 0xff0000, alpha: 0.8 })
  })

  it('falls back to black for null', () => {
    expect(parseColor(null)).toEqual({ color: 0x000000, alpha: 1 })
  })
})

describe('getFillStyles (multi-fill parity)', () => {
  it('returns ALL visible fills in bottom-to-top order', () => {
    const fills = [
      { type: 'SOLID', color: '#ff0000', visible: true },
      { type: 'SOLID', color: '#00ff00', visible: true },
    ]
    const result = getFillStyles(fills)
    expect(result).toHaveLength(2)
    expect(result[0].color).toBe(0xff0000)
    expect(result[1].color).toBe(0x00ff00)
  })

  it('skips invisible fills', () => {
    const fills = [
      { type: 'SOLID', color: '#ff0000', visible: false },
      { type: 'SOLID', color: '#00ff00', visible: true },
    ]
    const result = getFillStyles(fills)
    expect(result).toHaveLength(1)
    expect(result[0].color).toBe(0x00ff00)
  })

  it('converts GRADIENT_ANGULAR to a conic fill (was silently dropped)', () => {
    const fills = [{
      type: 'GRADIENT_ANGULAR',
      visible: true,
      gradientStops: [
        { color: '#ff0000', position: 0 },
        { color: '#0000ff', position: 1 },
      ],
    }]
    const result = getFillStyles(fills)
    expect(result).toHaveLength(1)
    expect(result[0].type).toBe('conic')
    expect(result[0].stops).toHaveLength(2)
  })

  it('converts GRADIENT_DIAMOND to linear (DOM renderer fallback parity)', () => {
    const fills = [{
      type: 'GRADIENT_DIAMOND',
      visible: true,
      gradientStops: [
        { color: '#ff0000', position: 0 },
        { color: '#0000ff', position: 1 },
      ],
    }]
    expect(getFillStyles(fills)[0].type).toBe('linear')
  })

  it('converts IMAGE fills (was silently dropped)', () => {
    const fills = [{ type: 'IMAGE', imageUrl: 'https://x/img.png', visible: true, opacity: 0.9 }]
    const result = getFillStyles(fills)
    expect(result).toHaveLength(1)
    expect(result[0]).toEqual({ type: 'image', url: 'https://x/img.png', opacity: 0.9 })
  })

  it('multiplies fill opacity into solid alpha', () => {
    const result = getFillStyles([{ type: 'SOLID', color: 'rgba(0,0,0,0.5)', opacity: 0.5, visible: true }])
    expect(result[0].alpha).toBeCloseTo(0.25)
  })
})

describe('getFillStyle (single-fill back-compat)', () => {
  it('returns the topmost visible fill', () => {
    const fills = [
      { type: 'SOLID', color: '#ff0000', visible: true },
      { type: 'SOLID', color: '#00ff00', visible: true },
    ]
    expect(getFillStyle(fills).color).toBe(0x00ff00)
  })

  it('returns null for empty input', () => {
    expect(getFillStyle([])).toBeNull()
    expect(getFillStyle(null)).toBeNull()
  })
})

describe('getStrokeStyle', () => {
  it('converts the first visible stroke', () => {
    const result = getStrokeStyle([{ type: 'SOLID', color: '#112233', weight: 3, visible: true }])
    expect(result).toEqual({ color: 0x112233, alpha: 1, width: 3, alignment: 0.5 })
  })

  it('carries dashPattern through', () => {
    const result = getStrokeStyle([{ type: 'SOLID', color: '#000', weight: 1, dashPattern: 'dashed' }])
    expect(result.dashPattern).toBe('dashed')
  })

  it('maps INSIDE/OUTSIDE alignment', () => {
    expect(getStrokeStyle([{ color: '#000', alignment: 'INSIDE' }]).alignment).toBe(1)
    expect(getStrokeStyle([{ color: '#000', alignment: 'OUTSIDE' }]).alignment).toBe(0)
  })
})

describe('getCornerRadius', () => {
  it('returns scalar cornerRadius', () => {
    expect(getCornerRadius({ cornerRadius: 12 })).toBe(12)
  })

  it('returns per-corner array [tl, tr, br, bl]', () => {
    expect(getCornerRadius({
      topLeftRadius: 1, topRightRadius: 2, bottomRightRadius: 3, bottomLeftRadius: 4,
    })).toEqual([1, 2, 3, 4])
  })

  it('returns 0 when nothing set', () => {
    expect(getCornerRadius({})).toBe(0)
  })
})

describe('getEffects', () => {
  it('collects DROP_SHADOW and INNER_SHADOW', () => {
    const { shadows } = getEffects([
      { type: 'DROP_SHADOW', color: '#000000', offset: { x: 1, y: 2 }, radius: 8 },
      { type: 'INNER_SHADOW', color: '#ff0000', offset: { x: 0, y: 0 }, radius: 4 },
    ])
    expect(shadows).toHaveLength(2)
    expect(shadows[0].type).toBe('DROP_SHADOW')
    expect(shadows[1].type).toBe('INNER_SHADOW')
  })

  it('skips invisible effects', () => {
    const { shadows, blurs } = getEffects([
      { type: 'DROP_SHADOW', color: '#000', visible: false },
      { type: 'LAYER_BLUR', radius: 5, visible: false },
    ])
    expect(shadows).toHaveLength(0)
    expect(blurs).toHaveLength(0)
  })
})

describe('getBlendMode', () => {
  it('maps Figma blend modes to pixi names', () => {
    expect(getBlendMode('MULTIPLY')).toBe('multiply')
    expect(getBlendMode('COLOR_DODGE')).toBe('color-dodge')
    expect(getBlendMode('NORMAL')).toBe('normal')
    expect(getBlendMode(undefined)).toBe('normal')
  })
})

describe('getStyleProps legacy normalization', () => {
  it('normalizes legacy shape_fill to a SOLID fill', () => {
    const props = getStyleProps('shape', { shape_fill: '#3b82f6' })
    expect(props.fills).toHaveLength(1)
    expect(props.fills[0].type).toBe('SOLID')
  })

  it('normalizes legacy gradients', () => {
    const props = getStyleProps('shape', {
      shape_fill_type: 'linear',
      shape_fill_gradient: {
        angle: 90,
        stops: [{ color: '#fff', position: 0 }, { color: '#000', position: 100 }],
      },
    })
    expect(props.fills[0].type).toBe('GRADIENT_LINEAR')
    expect(props.fills[0].gradientStops[1].position).toBe(1)
  })

  it('normalizes legacy shadow to DROP_SHADOW with _alpha', () => {
    const props = getStyleProps('shape', {
      shape_fill: '#fff',
      shadow_enabled: true, shadow_opacity: 50, shadow_blur: 10,
    })
    const shadow = props.effects.find(e => e.type === 'DROP_SHADOW')
    expect(shadow).toBeTruthy()
    expect(shadow._alpha).toBe(0.5)
    expect(shadow.radius).toBe(10)
  })
})

describe('applyFill / applyFills', () => {
  let g
  beforeEach(() => { g = mockGraphics() })

  it('applies solid fills', () => {
    applyFill(g, { type: 'solid', color: 0xff0000, alpha: 0.5 }, 100, 100)
    expect(g.fill).toHaveBeenCalledWith({ color: 0xff0000, alpha: 0.5 })
  })

  it('stacks ALL fills bottom-to-top on the same path', () => {
    const fills = [
      { type: 'solid', color: 0xff0000, alpha: 1 },
      { type: 'solid', color: 0x00ff00, alpha: 0.5 },
    ]
    applyFills(g, fills, 100, 100)
    expect(g.fill).toHaveBeenCalledTimes(2)
    expect(g.fill.mock.calls[0][0]).toEqual({ color: 0xff0000, alpha: 1 })
    expect(g.fill.mock.calls[1][0]).toEqual({ color: 0x00ff00, alpha: 0.5 })
  })

  it('skips image fills in applyFills (handled by renderers)', () => {
    applyFills(g, [{ type: 'image', url: 'x.png' }], 100, 100)
    expect(g.fill).not.toHaveBeenCalled()
  })

  it('falls back to first stop color when conic texture is unavailable', () => {
    // jsdom has no canvas 2d context, so buildConicTexture returns null
    applyFill(g, {
      type: 'conic',
      stops: [{ offset: 0, color: 0xff0000, alpha: 1 }, { offset: 1, color: 0x0000ff, alpha: 1 }],
    }, 100, 100)
    expect(g.fill).toHaveBeenCalledWith({ color: 0xff0000, alpha: 1 })
  })
})

describe('applyEffects', () => {
  function mockContainer() {
    const first = { getLocalBounds: () => ({ x: 0, y: 0, width: 100, height: 80 }) }
    return {
      children: [first],
      addChild: vi.fn(),
      addChildAt: vi.fn(),
    }
  }

  it('renders DROP_SHADOW behind the item', () => {
    const container = mockContainer()
    applyEffects(container, [{ type: 'DROP_SHADOW', color: '#000000', offset: { x: 0, y: 4 }, radius: 8 }])
    expect(container.addChildAt).toHaveBeenCalledTimes(1)
    expect(container.addChildAt.mock.calls[0][1]).toBe(0)
  })

  it('renders INNER_SHADOW (was silently dropped)', () => {
    const container = mockContainer()
    applyEffects(container, [{ type: 'INNER_SHADOW', color: '#000000', offset: { x: 0, y: 0 }, radius: 6 }])
    // mask + shadow rings added on top
    expect(container.addChild).toHaveBeenCalled()
    expect(container.addChild.mock.calls.length).toBeGreaterThanOrEqual(2)
  })

  it('skips blur effects (handled by DOM overlay)', () => {
    const container = mockContainer()
    applyEffects(container, [
      { type: 'LAYER_BLUR', radius: 5 },
      { type: 'BACKGROUND_BLUR', radius: 10 },
    ])
    expect(container.addChild).not.toHaveBeenCalled()
    expect(container.addChildAt).not.toHaveBeenCalled()
  })

  it('follows shape geometry when shapeInfo is provided', () => {
    const container = mockContainer()
    applyEffects(
      container,
      [{ type: 'DROP_SHADOW', color: '#000000', offset: { x: 0, y: 4 }, radius: 8 }],
      { shapeType: 'circle', w: 100, h: 100, radius: 0, sd: {} },
    )
    expect(container.addChildAt).toHaveBeenCalledTimes(1)
    const shadowG = container.addChildAt.mock.calls[0][0]
    // Perimeter-sampled path → moveTo/lineTo, not roundRect
    expect(shadowG.moveTo).toHaveBeenCalled()
    expect(shadowG.roundRect).not.toHaveBeenCalled()
  })
})

describe('drawRoundedRect', () => {
  it('honors per-corner radii arrays', () => {
    const g = mockGraphics()
    drawRoundedRect(g, 0, 0, 100, 100, [10, 0, 20, 0])
    expect(g.moveTo).toHaveBeenCalled()
    expect(g.arcTo).toHaveBeenCalledTimes(2) // only tl + br have radius
    expect(g.closePath).toHaveBeenCalled()
  })

  it('uses roundRect for scalar radius', () => {
    const g = mockGraphics()
    drawRoundedRect(g, 0, 0, 100, 100, 8)
    expect(g.roundRect).toHaveBeenCalledWith(0, 0, 100, 100, 8)
  })

  it('uses plain rect for zero radius', () => {
    const g = mockGraphics()
    drawRoundedRect(g, 0, 0, 100, 100, 0)
    expect(g.rect).toHaveBeenCalledWith(0, 0, 100, 100)
  })
})
