import { Texture } from 'pixi.js'

// Cap GPU uploads: a single 8000px photo would allocate ~256 MB of VRAM and
// can exceed device texture limits. Downscale on a canvas first.
const MAX_TEXTURE_DIM = 4096

function downscaleIfNeeded(img) {
  const w = img.naturalWidth || img.width || 0
  const h = img.naturalHeight || img.height || 0
  if (!w || !h || (w <= MAX_TEXTURE_DIM && h <= MAX_TEXTURE_DIM)) return img
  const scale = Math.min(MAX_TEXTURE_DIM / w, MAX_TEXTURE_DIM / h)
  const cw = Math.max(1, Math.round(w * scale))
  const ch = Math.max(1, Math.round(h * scale))
  try {
    const canvas = typeof OffscreenCanvas !== 'undefined'
      ? new OffscreenCanvas(cw, ch)
      : document.createElement('canvas')
    canvas.width = cw
    canvas.height = ch
    const ctx = canvas.getContext('2d')
    if (!ctx) return img
    ctx.drawImage(img, 0, 0, cw, ch)
    return canvas
  } catch {
    return img
  }
}

/**
 * LRU texture cache for PixiJS.
 * Uses native Image elements (same as <img> tags) to ensure cookies/auth
 * are sent correctly, then creates PixiJS textures from loaded images.
 */
export default class PixiTextureCache {
  constructor(maxSize = 500) {
    this._cache = new Map()
    this._accessOrder = []
    this._maxSize = maxSize
    this._loading = new Map()
    // Optional hook fired after an async load completes (e.g. to wake an
    // idle render ticker so the new texture actually appears)
    this.onTextureLoaded = null
  }

  has(url) {
    return this._cache.has(url)
  }

  get(url) {
    const entry = this._cache.get(url)
    if (entry) {
      this._touch(url)
      return entry.texture
    }
    return null
  }

  async load(url) {
    if (!url) return Texture.WHITE
    if (this._cache.has(url)) {
      this._touch(url)
      return this._cache.get(url).texture
    }
    if (this._loading.has(url)) return this._loading.get(url)

    const promise = this._loadViaImage(url)
    this._loading.set(url, promise)
    return promise
  }

  async _loadViaImage(url) {
    try {
      const img = new Image()
      img.crossOrigin = 'anonymous'
      const loaded = await new Promise((resolve, reject) => {
        img.onload = () => resolve(img)
        img.onerror = (e) => reject(e)
        img.src = url
      })
      const texture = Texture.from(downscaleIfNeeded(loaded))
      this._cache.set(url, { texture, url })
      this._accessOrder.push(url)
      this._loading.delete(url)
      this._evict()
      try { this.onTextureLoaded?.() } catch {}
      return texture
    } catch (err) {
      console.warn('[PixiTextureCache] Failed to load:', url, err)
      this._loading.delete(url)
      return null
    }
  }

  loadSync(url) {
    if (!url) return Texture.WHITE
    const cached = this.get(url)
    if (cached) return cached
    this.load(url)
    return null
  }

  unload(url) {
    const entry = this._cache.get(url)
    if (entry) {
      entry.texture.destroy?.(true)
      this._cache.delete(url)
      this._accessOrder = this._accessOrder.filter(u => u !== url)
    }
  }

  _touch(url) {
    const idx = this._accessOrder.indexOf(url)
    if (idx > -1) this._accessOrder.splice(idx, 1)
    this._accessOrder.push(url)
  }

  _evict() {
    while (this._cache.size > this._maxSize && this._accessOrder.length > 0) {
      const oldest = this._accessOrder.shift()
      const entry = this._cache.get(oldest)
      if (entry) {
        entry.texture.destroy?.(true)
        this._cache.delete(oldest)
      }
    }
  }

  clear() {
    for (const entry of this._cache.values()) {
      entry.texture.destroy?.(true)
    }
    this._cache.clear()
    this._accessOrder = []
    this._loading.clear()
  }

  get size() { return this._cache.size }
}
