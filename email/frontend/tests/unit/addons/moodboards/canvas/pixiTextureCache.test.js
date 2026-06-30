import { describe, it, expect, beforeEach, vi, afterEach } from 'vitest'

const mockTextureFrom = vi.fn()

vi.mock('pixi.js', () => ({
  Texture: {
    WHITE: { width: 1, height: 1 },
    from: (...args) => mockTextureFrom(...args),
  },
}))

import PixiTextureCache from '@/addons/moodboards/canvas/utils/pixiTextureCache.js'

describe('PixiTextureCache', () => {
  let cache
  let originalImage

  beforeEach(() => {
    cache = new PixiTextureCache(5)
    vi.clearAllMocks()
    originalImage = globalThis.Image
    globalThis.Image = class MockImage {
      constructor() {
        setTimeout(() => {
          if (this.src && !this.src.includes('bad')) {
            this.width = 100
            this.height = 100
            this.onload?.()
          } else {
            this.onerror?.(new Error('load failed'))
          }
        }, 0)
      }
      set src(v) { this._src = v }
      get src() { return this._src }
    }
    mockTextureFrom.mockImplementation((img) => ({ _img: img, width: 100, height: 100 }))
  })

  afterEach(() => {
    globalThis.Image = originalImage
  })

  describe('initial state', () => {
    it('starts empty', () => {
      expect(cache.size).toBe(0)
    })

    it('has() returns false for unknown URLs', () => {
      expect(cache.has('http://example.com/img.png')).toBe(false)
    })

    it('get() returns null for unknown URLs', () => {
      expect(cache.get('http://example.com/img.png')).toBeNull()
    })
  })

  describe('load', () => {
    it('loads a texture and caches it', async () => {
      const tex = await cache.load('http://example.com/a.png')
      expect(tex).toBeTruthy()
      expect(tex.width).toBe(100)
      expect(cache.has('http://example.com/a.png')).toBe(true)
      expect(cache.size).toBe(1)
    })

    it('returns cached texture on second call', async () => {
      const tex1 = await cache.load('http://example.com/b.png')
      const tex2 = await cache.load('http://example.com/b.png')
      expect(tex1).toBe(tex2)
      expect(mockTextureFrom).toHaveBeenCalledTimes(1)
    })

    it('returns WHITE texture for null URL', async () => {
      const tex = await cache.load(null)
      expect(tex).toEqual({ width: 1, height: 1 })
    })

    it('returns null on load error', async () => {
      const tex = await cache.load('http://example.com/bad.png')
      expect(tex).toBeNull()
    })
  })

  describe('loadSync', () => {
    it('returns cached texture if available', async () => {
      await cache.load('http://example.com/c.png')
      const tex = cache.loadSync('http://example.com/c.png')
      expect(tex).toBeTruthy()
      expect(tex.width).toBe(100)
    })

    it('returns null and triggers async load if not cached', () => {
      const tex = cache.loadSync('http://example.com/d.png')
      expect(tex).toBeNull()
    })

    it('returns WHITE for null URL', () => {
      const tex = cache.loadSync(null)
      expect(tex).toEqual({ width: 1, height: 1 })
    })
  })

  describe('unload', () => {
    it('removes a cached texture', async () => {
      mockTextureFrom.mockReturnValue({ width: 100, height: 100, destroy: vi.fn() })
      await cache.load('http://example.com/e.png')
      cache.unload('http://example.com/e.png')
      expect(cache.has('http://example.com/e.png')).toBe(false)
      expect(cache.size).toBe(0)
    })

    it('does nothing for unknown URL', () => {
      cache.unload('http://example.com/unknown.png')
      expect(cache.size).toBe(0)
    })
  })

  describe('eviction', () => {
    it('evicts oldest entries when max size is exceeded', async () => {
      mockTextureFrom.mockImplementation(() => ({ destroy: vi.fn() }))

      for (let i = 0; i < 7; i++) {
        await cache.load(`http://example.com/${i}.png`)
      }

      expect(cache.size).toBe(5)
      expect(cache.has('http://example.com/0.png')).toBe(false)
      expect(cache.has('http://example.com/1.png')).toBe(false)
      expect(cache.has('http://example.com/6.png')).toBe(true)
    })
  })

  describe('clear', () => {
    it('removes all cached textures', async () => {
      mockTextureFrom.mockReturnValue({ destroy: vi.fn() })
      await cache.load('http://example.com/f.png')
      await cache.load('http://example.com/g.png')
      cache.clear()
      expect(cache.size).toBe(0)
    })
  })
})
