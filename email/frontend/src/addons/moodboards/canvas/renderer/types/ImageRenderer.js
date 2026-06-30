import { Container, Sprite, Graphics, Texture } from 'pixi.js'
import { getCornerRadius, applyTransform, parseColor as parseColorFn, getStyleProps, applyEffects, drawRoundedRect } from '../../utils/styleToPixi.js'

let _dimensionFixCb = null
export function setDimensionFixCallback(cb) { _dimensionFixCb = cb }

/** Reset module-level state on canvas unmount (avoids leaking ids across boards). */
export function resetImageRendererState() {
  _dimensionFixCb = null
  _fixedIds.clear()
}

export function createImage(item, textureCache) {
  const container = new Container()
  container.label = `image-${item.id}`
  drawImage(container, item, textureCache)
  applyTransform(container, item)
  return container
}

export function updateImage(container, item, textureCache) {
  container.removeChildren()
  container.mask = null
  container.filters = []
  drawImage(container, item, textureCache)
  applyTransform(container, item)
}

function drawImage(container, item, textureCache) {
  const url = item.image_url || item.thumbnail_url
  const sd = item.style_data || {}
  const normalSd = getStyleProps('image', sd)

  if (item.type === 'image_set') {
    drawImageSet(container, item, textureCache)
    return
  }

  const fit = sd.image_fit || sd.objectFit || 'cover'
  const tex = url ? textureCache.loadSync(url) : null

  if (tex) {
    const { w, h } = resolveImageBox(item, tex)
    const sprite = new Sprite(tex)
    fitSpriteToBox(sprite, w, h, fit)
    container.addChild(sprite)
    applyCoverClip(container, w, h, normalSd)
    applyImageBorder(container, w, h, sd)
    if (normalSd.effects?.length) applyEffects(container, normalSd.effects)
    _tryFixDimensions(item, tex)
  } else {
    const { w, h } = resolveImageBox(item, null)
    const placeholder = new Graphics()
    placeholder.rect(0, 0, w, h).fill({ color: 0xeeeeee })
    container.addChild(placeholder)

    if (url) {
      textureCache.load(url).then(loadedTex => {
        if (loadedTex && container.parent && !container.destroyed) {
          const { w: loadedW, h: loadedH } = resolveImageBox(item, loadedTex)
          container.removeChildren()
          container.mask = null
          container.filters = null
          const sprite = new Sprite(loadedTex)
          fitSpriteToBox(sprite, loadedW, loadedH, fit)
          container.addChild(sprite)
          applyCoverClip(container, loadedW, loadedH, normalSd)
          applyImageBorder(container, loadedW, loadedH, sd)
          if (normalSd.effects?.length) applyEffects(container, normalSd.effects)
          _tryFixDimensions(item, loadedTex)
        }
      })
    }
  }
}

function drawImageSet(container, item, textureCache) {
  const sd = item.style_data || {}
  const images = sd.images || []
  const w = item.width || 200
  const h = item.height || 200
  const stackOffset = 4

  const bg = new Graphics()
  bg.rect(0, 0, w, h).fill({ color: 0xf5f5f5 })
  container.addChild(bg)

  const displayImages = images.slice(0, 5)
  displayImages.forEach((img, i) => {
    const offset = (displayImages.length - 1 - i) * stackOffset
    const tex = img.url ? textureCache.loadSync(img.url) : null
    if (tex) {
      const sprite = new Sprite(tex)
      fitSpriteToBox(sprite, w - offset * 2, h - offset * 2, 'cover')
      sprite.position.set(offset, offset)
      container.addChild(sprite)
    }
  })
}

function fitSpriteToBox(sprite, boxW, boxH, fit) {
  const texW = sprite.texture.width || 1
  const texH = sprite.texture.height || 1

  if (fit === 'contain') {
    const scale = Math.min(boxW / texW, boxH / texH)
    sprite.width = texW * scale
    sprite.height = texH * scale
    sprite.position.set((boxW - sprite.width) / 2, (boxH - sprite.height) / 2)
  } else {
    const scale = Math.max(boxW / texW, boxH / texH)
    sprite.width = texW * scale
    sprite.height = texH * scale
    sprite.position.set((boxW - sprite.width) / 2, (boxH - sprite.height) / 2)
  }
}

const MIN_IMAGE_DIM = 40

const _fixedIds = new Set()
function _tryFixDimensions(item, texture) {
  if (!_dimensionFixCb || _fixedIds.has(item.id)) return
  const storedW = Number(item.width) || 0
  const storedH = Number(item.height) || 0
  if (storedW > 0 && storedH > 0) return

  const texW = texture?.width || 0
  const texH = texture?.height || 0
  if (texW <= 0 || texH <= 0) return

  let fixedW = storedW, fixedH = storedH
  if (!fixedW && !fixedH) {
    fixedW = 300
    fixedH = Math.max(MIN_IMAGE_DIM, Math.round(300 * (texH / texW)))
  } else if (fixedW > 0 && !fixedH) {
    fixedH = Math.max(MIN_IMAGE_DIM, Math.round(fixedW * (texH / texW)))
  } else if (fixedH > 0 && !fixedW) {
    fixedW = Math.max(MIN_IMAGE_DIM, Math.round(fixedH * (texW / texH)))
  }

  _fixedIds.add(item.id)
  _dimensionFixCb(item.id, fixedW, fixedH)
}

function resolveImageBox(item, texture) {
  const sd = item.style_data || {}
  const intrinsicW = sd.original_width || texture?.width || 0
  const intrinsicH = sd.original_height || texture?.height || 0
  const w = item.width || intrinsicW || 200
  let h = item.height || 0

  if (!h && intrinsicW > 0 && intrinsicH > 0) {
    h = Math.round(w * (intrinsicH / intrinsicW))
  }

  if (!h) h = intrinsicH || 200

  if (h < MIN_IMAGE_DIM && intrinsicW > 0 && intrinsicH > 0) {
    h = Math.max(MIN_IMAGE_DIM, Math.round(w * (intrinsicH / intrinsicW)))
  }
  if (h < MIN_IMAGE_DIM) h = MIN_IMAGE_DIM

  return { w, h }
}

function applyCoverClip(container, w, h, sd) {
  const radius = sd.cornerRadius ?? sd.border_radius ?? getCornerRadius(sd)
  const hasRadius = radius && (Array.isArray(radius) ? radius.some(r => r > 0) : radius > 0)
  const mask = new Graphics()
  if (hasRadius) {
    // Honor per-corner radii ([tl, tr, br, bl]) — parity with CSS border-radius
    drawRoundedRect(mask, 0, 0, w, h, radius)
  } else {
    mask.rect(0, 0, w, h)
  }
  mask.fill({ color: 0xffffff })
  container.addChild(mask)
  container.mask = mask
}

function applyImageBorder(container, w, h, sd) {
  const strokes = sd.strokes
  if (!strokes?.length) return
  const s = strokes.find(st => st.visible !== false)
  if (!s) return
  const parsed = parseColorFn(s.color)
  const radius = sd.cornerRadius ?? sd.border_radius ?? 12
  const border = new Graphics()
  drawRoundedRect(border, 0, 0, w, h, radius || 0)
  border.stroke({ color: parsed.color, alpha: parsed.alpha, width: s.weight || 1 })
  container.addChild(border)
}
