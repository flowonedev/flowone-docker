import { Graphics, Container, Sprite, Text as PixiText, TextStyle } from 'pixi.js'
import {
  getFillStyles, getStrokeStyle, getCornerRadius,
  applyFills, applyStroke, applyTransform, drawRoundedRect,
  getStyleProps, applyEffects, parseColor
} from '../../utils/styleToPixi.js'
import { drawDashedShape } from '../../utils/dashedStroke.js'

/**
 * Renders shape items: rect, circle, triangle, star, polygon, custom clips.
 */
export function createShape(item, textureCache) {
  const container = new Container()
  container.label = `shape-${item.id}`
  drawShapeGraphics(container, item, textureCache)
  applyTransform(container, item)
  return container
}

export function updateShape(container, item, textureCache) {
  container.removeChildren()
  container.filters = []
  drawShapeGraphics(container, item, textureCache)
  applyTransform(container, item)
}

function hasBlurOverlay(rawSd, sd) {
  if (rawSd.backdrop_blur_enabled || rawSd.shape_backdrop_blur > 0
    || (rawSd.backdrop_blur_enabled && rawSd.backdrop_blur_amount > 0)
    || sd.effects?.some(e => e.type === 'BACKGROUND_BLUR' && e.visible !== false && e.radius > 0))
    return true
  if (sd.effects?.some(e => e.type === 'LAYER_BLUR' && e.visible !== false && (e.radius ?? 0) > 0))
    return true
  return false
}

function drawShapeGraphics(container, item, textureCache) {
  const rawSd = item.style_data || {}
  const sd = getStyleProps('shape', rawSd)
  const w = item.width || 100
  const h = item.height || 100
  const g = new Graphics()
  const shapeType = rawSd.shape_type || sd.shapeType || 'rectangle'
  const fillStyles = getFillStyles(sd.fills)
  const strokeStyle = getStrokeStyle(sd.strokes)
  const radius = sd.cornerRadius ?? getCornerRadius(rawSd)
  const shapeInfo = { shapeType, w, h, radius, sd: rawSd }

  buildShapePath(g, shapeType, w, h, radius, sd)

  if (hasBlurOverlay(rawSd, sd)) {
    g.fill({ color: 0x000000, alpha: 0.001 })
    container.addChild(g)
    return
  }

  if (fillStyles.length) {
    // Stack all visible fills bottom-to-top (parity with CSS backgrounds)
    applyFills(g, fillStyles, w, h)
    if (!fillStyles.some(f => f.type !== 'image')) {
      g.fill({ color: 0x000000, alpha: 0.001 })
    }
  } else if (strokeStyle?.dashPattern) {
    g.fill({ color: 0x000000, alpha: 0.001 })
  }

  if (strokeStyle?.dashPattern) {
    container.addChild(g)
    addImageFills(container, fillStyles, shapeInfo, sd, textureCache, item)
    const dashG = new Graphics()
    drawDashedShape(dashG, shapeType, w, h, radius, rawSd, strokeStyle, strokeStyle.dashPattern)
    container.addChild(dashG)
  } else {
    if (strokeStyle && !fillStyles.some(f => f.type === 'image')) applyStroke(g, strokeStyle)
    container.addChild(g)
    addImageFills(container, fillStyles, shapeInfo, sd, textureCache, item)
    if (strokeStyle && fillStyles.some(f => f.type === 'image')) {
      // Stroke above image fill sprites so the border stays visible
      const strokeG = new Graphics()
      buildShapePath(strokeG, shapeType, w, h, radius, sd)
      applyStroke(strokeG, strokeStyle)
      container.addChild(strokeG)
    }
  }

  if (sd.effects?.length) {
    applyEffects(container, sd.effects, shapeInfo)
  }

  if (rawSd.mask_image_url) {
    loadMaskImage(container, rawSd.mask_image_url, w, h, textureCache)
  }

  if (item.content) {
    addShapeText(container, item.content, w, h, sd, rawSd)
  }
}

/**
 * IMAGE-type fills: cover-fit sprite masked to the shape path.
 * Async-loaded textures trigger a redraw when ready.
 */
function addImageFills(container, fillStyles, shapeInfo, normSd, textureCache, item) {
  if (!textureCache) return
  for (const fs of fillStyles) {
    if (fs.type !== 'image' || !fs.url) continue
    const tex = textureCache.loadSync(fs.url)
    if (tex) {
      attachImageFillSprite(container, tex, fs, shapeInfo, normSd)
    } else {
      textureCache.load(fs.url).then(loadedTex => {
        if (loadedTex && container.parent && !container.destroyed) {
          updateShape(container, item, textureCache)
        }
      })
    }
  }
}

function attachImageFillSprite(container, tex, fs, shapeInfo, normSd) {
  const { shapeType, w, h, radius } = shapeInfo
  const sprite = new Sprite(tex)
  const texW = tex.width || 1
  const texH = tex.height || 1
  const scale = Math.max(w / texW, h / texH)
  sprite.width = texW * scale
  sprite.height = texH * scale
  sprite.position.set((w - sprite.width) / 2, (h - sprite.height) / 2)
  sprite.alpha = fs.opacity ?? 1

  const mask = new Graphics()
  buildShapePath(mask, shapeType, w, h, radius, normSd)
  mask.fill({ color: 0xffffff })
  container.addChild(mask)
  sprite.mask = mask
  container.addChild(sprite)
}

function buildShapePath(g, shapeType, w, h, radius, sd) {
  switch (shapeType) {
    case 'circle':
    case 'ellipse':
      g.ellipse(w / 2, h / 2, w / 2, h / 2)
      break
    case 'triangle':
      g.moveTo(w / 2, 0)
      g.lineTo(w, h)
      g.lineTo(0, h)
      g.closePath()
      break
    case 'star': {
      const points = sd.star_points || 5
      const inner = sd.star_inner_radius || 0.38
      drawStar(g, w / 2, h / 2, Math.min(w, h) / 2, points, inner)
      break
    }
    case 'polygon': {
      const sides = sd.polygon_sides || 6
      drawRegularPolygon(g, w / 2, h / 2, Math.min(w, h) / 2, sides)
      break
    }
    default:
      drawRoundedRect(g, 0, 0, w, h, radius)
  }
}

function drawStar(g, cx, cy, outerR, points, innerRatio) {
  const innerR = outerR * innerRatio
  const step = Math.PI / points
  g.moveTo(cx + outerR * Math.sin(0), cy - outerR * Math.cos(0))
  for (let i = 1; i < points * 2; i++) {
    const r = i % 2 === 0 ? outerR : innerR
    const angle = i * step
    g.lineTo(cx + r * Math.sin(angle), cy - r * Math.cos(angle))
  }
  g.closePath()
}

function drawRegularPolygon(g, cx, cy, r, sides) {
  const step = (2 * Math.PI) / sides
  const offset = -Math.PI / 2
  g.moveTo(cx + r * Math.cos(offset), cy + r * Math.sin(offset))
  for (let i = 1; i <= sides; i++) {
    const angle = offset + i * step
    g.lineTo(cx + r * Math.cos(angle), cy + r * Math.sin(angle))
  }
  g.closePath()
}

function loadMaskImage(container, url, w, h, textureCache) {
  if (!textureCache) return
  const shapeGraphics = container.children.find(c => c instanceof Graphics)
  const tex = textureCache.loadSync(url)
  if (tex) {
    const sprite = new Sprite(tex)
    sprite.width = w
    sprite.height = h
    container.addChild(sprite)
    if (shapeGraphics) {
      sprite.mask = shapeGraphics
    }
  } else {
    textureCache.load(url).then(loadedTex => {
      if (loadedTex && container.parent && !container.destroyed) {
        const sprite = new Sprite(loadedTex)
        sprite.width = w
        sprite.height = h
        container.addChild(sprite)
        const sg = container.children.find(c => c instanceof Graphics)
        if (sg) sprite.mask = sg
      }
    })
  }
}

function applyTextTransform(text, transform) {
  if (!text || !transform || transform === 'none') return text
  if (transform === 'uppercase') return text.toUpperCase()
  if (transform === 'lowercase') return text.toLowerCase()
  if (transform === 'capitalize') return text.replace(/\b\w/g, c => c.toUpperCase())
  return text
}

function addShapeText(container, content, w, h, sd, rawSd = {}) {
  const transform = rawSd.shape_text_transform || sd.shape_text_transform || sd.text?.textTransform || 'none'
  const displayContent = applyTextTransform(content, transform)
  // DOM parity (shapeTextStyle in MoodCanvasItem.vue): color defaults to
  // white, weight 600, size 16 — never a hardcoded dark gray.
  const colorValue = rawSd.shape_text_color || sd.shape_text_color || sd.text?.color || '#ffffff'
  const { color, alpha } = parseColor(colorValue)
  const style = new TextStyle({
    fontFamily: rawSd.shape_font_family || sd.text?.fontFamily || 'Inter, sans-serif',
    fontSize: rawSd.shape_font_size || sd.text?.fontSize || 16,
    fontWeight: String(rawSd.shape_font_weight || sd.text?.fontWeight || '600'),
    fill: color,
    wordWrap: true,
    wordWrapWidth: w - 16,
    align: rawSd.shape_text_align || sd.text?.textAlign || 'center',
    letterSpacing: rawSd.shape_letter_spacing ?? 0,
  })
  const text = new PixiText({ text: displayContent, style })
  text.alpha = alpha
  text.anchor.set(0.5)
  text.position.set(w / 2, h / 2)
  container.addChild(text)
}
