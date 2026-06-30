import PptxGenJS from 'pptxgenjs'
import { getStroke } from 'perfect-freehand'
import { renderConnectionsOverlay } from './moodBoardPptxConnectionRenderer'
import { hexClean, parseSd, resolveItemDimensions, rasterizeSvg } from './moodBoardPptxUtils'

const SLIDE_W_IN = 10
const SLIDE_H_IN = 5.625
const PX_TO_IN = SLIDE_W_IN / 960
const ICON_FONTS = new Set(['Material Symbols Rounded', 'Material Symbols Outlined'])

function isIconFont(fontFamily) {
  return ICON_FONTS.has(fontFamily)
}

function normalizeItemOpacity(item) {
  const sd = parseSd(item)
  const opacityKeyMap = { shape: 'shape_opacity', pen_shape: 'shape_opacity', frame: 'frame_opacity', text: 'text_opacity', image: 'image_opacity' }
  const opKey = opacityKeyMap[item.type] || 'opacity'
  const opVal = sd[opKey] ?? sd.opacity
  if (opVal == null) return 1
  return opVal > 1 ? opVal / 100 : opVal
}

function toTransparency(opacity) {
  if (opacity >= 0.999) return 0
  return Math.round((1 - Math.max(0, Math.min(1, opacity))) * 100)
}

function contrastColor(hex) {
  const h = hexClean(hex)
  const r = parseInt(h.slice(0, 2), 16)
  const g = parseInt(h.slice(2, 4), 16)
  const b = parseInt(h.slice(4, 6), 16)
  return (r * 0.299 + g * 0.587 + b * 0.114) > 150 ? '111827' : 'FFFFFF'
}

function stripHtml(html) {
  if (!html) return ''
  return html
    .replace(/<br\s*\/?>/gi, '\n')
    .replace(/<\/p>\s*<p[^>]*>/gi, '\n')
    .replace(/<\/div>\s*<div[^>]*>/gi, '\n')
    .replace(/<[^>]+>/g, '')
    .replace(/&amp;/g, '&')
    .replace(/&lt;/g, '<')
    .replace(/&gt;/g, '>')
    .replace(/&quot;/g, '"')
    .replace(/&#039;/g, "'")
    .replace(/&nbsp;/g, ' ')
}

function resolveUrl(url) {
  if (!url) return ''
  if (url.startsWith('data:') || url.startsWith('http://') || url.startsWith('https://')) return url
  if (url.startsWith('/')) return `${window.location.origin}${url}`
  return url
}

async function fetchImageBase64(url) {
  const resolved = resolveUrl(url)
  if (!resolved) return null
  if (resolved.startsWith('data:')) return resolved

  try {
    const response = await fetch(resolved, { credentials: 'include' })
    if (!response.ok) return null
    const blob = await response.blob()
    return await new Promise((resolve) => {
      const reader = new FileReader()
      reader.onloadend = () => resolve(reader.result)
      reader.onerror = () => resolve(null)
      reader.readAsDataURL(blob)
    })
  } catch {
    return null
  }
}


function isVisible(item) {
  const sd = parseSd(item)
  return !sd._hidden
}

function getSortedSlides(board) {
  return (board?.items || [])
    .filter(i => i.type === 'slide' && isVisible(i))
    .sort((a, b) => (a.slide_order ?? 9999) - (b.slide_order ?? 9999))
}

function getVisibleItems(board) {
  return (board?.items || []).filter(i => i.type !== 'slide' && isVisible(i))
}

function computeViewport(items) {
  if (!items.length) return { minX: 0, minY: 0, w: 1600, h: 900 }
  let minX = Infinity, minY = Infinity, maxX = -Infinity, maxY = -Infinity
  for (const item of items) {
    const x = Number(item.pos_x || 0)
    const y = Number(item.pos_y || 0)
    const w = Math.max(Number(item.width || 0), 1)
    const h = Math.max(Number(item.height || 0), 1)
    minX = Math.min(minX, x)
    minY = Math.min(minY, y)
    maxX = Math.max(maxX, x + w)
    maxY = Math.max(maxY, y + h)
  }
  const padding = 40
  return {
    minX: minX - padding,
    minY: minY - padding,
    w: maxX - minX + padding * 2,
    h: maxY - minY + padding * 2,
  }
}

function buildPages(board, selectedSlideIds = null) {
  let slides = getSortedSlides(board)
  const items = getVisibleItems(board)

  if (selectedSlideIds) {
    const idSet = new Set(selectedSlideIds)
    slides = slides.filter(s => idSet.has(s.id))
  }

  if (slides.length) {
    return slides.map(slide => {
      const sx = Number(slide.pos_x || 0)
      const sy = Number(slide.pos_y || 0)
      const sw = Math.max(Number(slide.width || 1600), 1)
      const sh = Math.max(Number(slide.height || 900), 1)

      const visible = items.filter(item => {
        const ix = Number(item.pos_x || 0)
        const iy = Number(item.pos_y || 0)
        const d = resolveItemDimensions(item)
        return !(ix + d.w < sx || ix > sx + sw || iy + d.h < sy || iy > sy + sh)
      })

      return { originX: sx, originY: sy, canvasW: sw, canvasH: sh, items: visible }
    })
  }

  const vp = computeViewport(items)
  return [{ originX: vp.minX, originY: vp.minY, canvasW: vp.w, canvasH: vp.h, items }]
}

function scaleFactors(canvasW, canvasH) {
  const sx = SLIDE_W_IN / Math.max(canvasW, 1)
  const sy = SLIDE_H_IN / Math.max(canvasH, 1)
  const s = Math.min(sx, sy)
  const ox = (SLIDE_W_IN - canvasW * s) / 2
  const oy = (SLIDE_H_IN - canvasH * s) / 2
  return { s, ox, oy }
}

function toInches(px, originPx, scale, offsetIn) {
  return (px - originPx) * scale + offsetIn
}

function clampBox(x, y, w, h) {
  const cx = Math.max(0, x)
  const cy = Math.max(0, y)
  const cw = Math.max(0.05, Math.min(w - (cx - x), SLIDE_W_IN - cx))
  const ch = Math.max(0.05, Math.min(h - (cy - y), SLIDE_H_IN - cy))
  return { x: cx, y: cy, w: cw, h: ch }
}

async function clipImageRounded(dataUri, radius) {
  const img = new Image()
  await new Promise((resolve, reject) => {
    img.onload = resolve
    img.onerror = reject
    img.src = dataUri
  })

  const natW = img.naturalWidth || img.width
  const natH = img.naturalHeight || img.height
  if (!natW || !natH) return null

  const MAX = 1600
  let w = natW, h = natH
  if (w > MAX || h > MAX) {
    const s = MAX / Math.max(w, h)
    w = Math.round(w * s)
    h = Math.round(h * s)
  }

  const scaleR = w / natW
  const r = Math.max(0, Math.min(radius * scaleR, w / 2, h / 2))

  const canvas = document.createElement('canvas')
  canvas.width = w
  canvas.height = h
  const ctx = canvas.getContext('2d')

  ctx.beginPath()
  ctx.moveTo(r, 0)
  ctx.lineTo(w - r, 0)
  ctx.arcTo(w, 0, w, r, r)
  ctx.lineTo(w, h - r)
  ctx.arcTo(w, h, w - r, h, r)
  ctx.lineTo(r, h)
  ctx.arcTo(0, h, 0, h - r, r)
  ctx.lineTo(0, r)
  ctx.arcTo(0, 0, r, 0, r)
  ctx.closePath()
  ctx.clip()

  ctx.drawImage(img, 0, 0, w, h)
  return canvas.toDataURL('image/png')
}

async function renderImage(slide, item, sd, box) {
  const url = item.image_url || item.thumbnail_url
  const originalData = await fetchImageBase64(url)
  if (!originalData) {
    renderPlaceholder(slide, item.title || 'Image', box, 'E5E7EB', 'EF4444')
    return
  }

  const br = sd.border_radius || sd.shape_border_radius || 0
  let data = originalData
  let useRounding = false

  if (br > 0) {
    try {
      const clipped = await clipImageRounded(originalData, br)
      if (clipped) data = clipped
      else useRounding = true
    } catch {
      useRounding = true
    }
  }

  const opacity = normalizeItemOpacity(item)
  try {
    const opts = { data, x: box.x, y: box.y, w: box.w, h: box.h }
    if (useRounding) opts.rounding = true
    if (opacity < 0.999) opts.transparency = toTransparency(opacity)
    slide.addImage(opts)
  } catch {
    renderPlaceholder(slide, item.title || 'Image', box, 'E5E7EB', 'EF4444')
  }
}

async function rasterizeIcon(iconName, fontFamily, sizePx, colorHex) {
  await document.fonts.ready
  if (!document.fonts.check(`${sizePx}px '${fontFamily}'`)) return null

  const s = Math.max(96, Math.round(sizePx * 2))
  const canvas = document.createElement('canvas')
  canvas.width = s
  canvas.height = s
  const ctx = canvas.getContext('2d')
  ctx.font = `${s}px '${fontFamily}'`
  ctx.textAlign = 'center'
  ctx.textBaseline = 'middle'
  ctx.fillStyle = `#${hexClean(colorHex)}`
  ctx.fillText(iconName, s / 2, s / 2)

  const imgData = ctx.getImageData(0, 0, s, s)
  const hasPixels = imgData.data.some((v, i) => i % 4 === 3 && v > 0)
  if (!hasPixels) return null

  return canvas.toDataURL('image/png')
}

async function renderIcon(slide, item, sd, box) {
  const iconName = stripHtml(item.content || '').trim()
  if (!iconName) return

  const fontFamily = sd.font_family || 'Material Symbols Rounded'
  const color = resolveTextColor(sd)
  const fontSize = sd.font_size || 48
  const opacity = normalizeItemOpacity(item)

  const itemW = Number(item.width) || 200
  const itemH = Number(item.height) || 200
  const iconSize = Math.min(fontSize * (box.w / itemW), fontSize * (box.h / itemH))
  const iconX = box.x + (box.w - iconSize) / 2
  const iconY = box.y + (box.h - iconSize) / 2

  try {
    const data = await rasterizeIcon(iconName, fontFamily, fontSize, color)
    if (data) {
      const opts = { data, x: iconX, y: iconY, w: iconSize, h: iconSize }
      if (opacity < 0.999) opts.transparency = toTransparency(opacity)
      slide.addImage(opts)
      return
    }
  } catch { /* fall through to placeholder */ }
  renderPlaceholder(slide, iconName, box, 'F3F4F6', '6B7280')
}

function applyTextTransform(text, transform) {
  if (!transform || transform === 'none') return text
  if (transform === 'uppercase') return text.toUpperCase()
  if (transform === 'lowercase') return text.toLowerCase()
  if (transform === 'capitalize') return text.replace(/\b\w/g, c => c.toUpperCase())
  return text
}

function resolveTextColor(sd) {
  const ft = sd.text_fill_type
  if (ft && ft !== 'solid' && sd.text_fill_gradient?.stops?.length >= 2) {
    const sorted = [...sd.text_fill_gradient.stops].sort((a, b) => a.position - b.position)
    return hexClean(sorted[0].color || '#1f2937')
  }
  return hexClean(sd.text_color || '#1f2937')
}

function renderText(slide, item, sd, box, scale) {
  let raw = stripHtml(item.content || '')
  if (!raw.trim()) return

  raw = applyTextTransform(raw, sd.text_transform)

  const fontSizePt = Math.max(4, (sd.font_size || 16) * scale * 72)
  const color = resolveTextColor(sd)
  const bold = ['bold', '600', '700', '800', '900'].includes(String(sd.font_weight || ''))
  const align = sd.text_align || 'left'

  const textOpts = {
    x: box.x,
    y: box.y,
    w: box.w,
    h: box.h,
    fontSize: fontSizePt,
    color,
    bold,
    align,
    valign: 'top',
    fontFace: sd.font_family || 'Arial',
    wrap: true,
    shrinkText: true,
    paraSpaceAfter: 0,
    paraSpaceBefore: 0,
  }

  if (sd.line_height && sd.line_height > 0 && sd.line_height <= 10) {
    textOpts.lineSpacingMultiple = sd.line_height
  }

  if (sd.letter_spacing) {
    textOpts.charSpacing = sd.letter_spacing * scale * 72
  }

  if (sd.text_padding) {
    const padPt = sd.text_padding * scale * 72
    textOpts.margin = [padPt, padPt, padPt, padPt]
  }

  slide.addText(raw, textOpts)
}

function renderNote(slide, item, sd, box, scale) {
  const bg = hexClean(item.color || '#fef3c7')
  const fg = contrastColor(bg)
  const content = stripHtml(item.content || '')
  const title = item.title || ''
  const lines = []
  if (title) lines.push({ text: title + '\n', options: { bold: true, fontSize: Math.max(6, Math.round(11 * (scale / PX_TO_IN))), color: fg } })
  if (content) lines.push({ text: content, options: { fontSize: Math.max(6, Math.round(9 * (scale / PX_TO_IN))), color: fg } })
  if (!lines.length) return

  const opacity = normalizeItemOpacity(item)
  const trans = toTransparency(opacity)
  slide.addText(lines, {
    x: box.x, y: box.y, w: box.w, h: box.h,
    fill: { color: bg, transparency: trans },
    valign: 'top',
    margin: [6, 8, 6, 8],
  })
}

function hasBlurEffect(sd) {
  if (sd.blur_enabled && (sd.blur_amount || 0) > 0) return sd.blur_amount
  const fx = sd.effects || []
  for (const e of fx) {
    if (e.type === 'LAYER_BLUR' && e.visible !== false && (e.radius || 0) > 0) return e.radius
  }
  return 0
}

async function rasterizeBlurredShape(item, sd, pxW, pxH) {
  const shapeType = sd.shape_type || 'rectangle'
  const fillHex = sd.shape_fill || item.color || sd.background_color || '#6366f1'
  const blurAmount = hasBlurEffect(sd)
  const borderRadius = sd.shape_border_radius || 0
  const opacity = normalizeItemOpacity(item)

  const pad = Math.ceil(blurAmount * 3)
  const cW = pxW + pad * 2
  const cH = pxH + pad * 2

  const canvas = document.createElement('canvas')
  canvas.width = cW
  canvas.height = cH
  const ctx = canvas.getContext('2d')

  if (blurAmount > 0) ctx.filter = `blur(${blurAmount}px)`
  if (opacity < 0.999) ctx.globalAlpha = opacity

  const gradient = sd.shape_fill_gradient
  const fillType = sd.shape_fill_type
  if (fillType && fillType !== 'solid' && gradient?.stops?.length >= 2) {
    const sorted = [...gradient.stops].sort((a, b) => a.position - b.position)
    let grad
    if (fillType === 'radial') {
      grad = ctx.createRadialGradient(cW / 2, cH / 2, 0, cW / 2, cH / 2, Math.max(pxW, pxH) / 2)
    } else {
      const angle = ((gradient.angle ?? 180) - 90) * Math.PI / 180
      const dx = Math.cos(angle) * cW / 2
      const dy = Math.sin(angle) * cH / 2
      grad = ctx.createLinearGradient(cW / 2 - dx, cH / 2 - dy, cW / 2 + dx, cH / 2 + dy)
    }
    for (const s of sorted) grad.addColorStop(s.position, s.color || '#6366f1')
    ctx.fillStyle = grad
  } else {
    ctx.fillStyle = fillHex.startsWith('#') ? fillHex : `#${fillHex}`
  }

  const x = pad, y = pad
  if (shapeType === 'circle' || shapeType === 'ellipse') {
    ctx.beginPath()
    ctx.ellipse(cW / 2, cH / 2, pxW / 2, pxH / 2, 0, 0, Math.PI * 2)
    ctx.fill()
  } else {
    const r = Math.min(borderRadius, pxW / 2, pxH / 2)
    ctx.beginPath()
    ctx.moveTo(x + r, y)
    ctx.lineTo(x + pxW - r, y)
    ctx.arcTo(x + pxW, y, x + pxW, y + r, r)
    ctx.lineTo(x + pxW, y + pxH - r)
    ctx.arcTo(x + pxW, y + pxH, x + pxW - r, y + pxH, r)
    ctx.lineTo(x + r, y + pxH)
    ctx.arcTo(x, y + pxH, x, y + pxH - r, r)
    ctx.lineTo(x, y + r)
    ctx.arcTo(x, y, x + r, y, r)
    ctx.closePath()
    ctx.fill()
  }

  const borderWidth = sd.shape_border_width || sd.border_width || 0
  if (borderWidth > 0) {
    ctx.filter = 'none'
    ctx.globalAlpha = opacity < 0.999 ? opacity : 1
    ctx.strokeStyle = (sd.shape_border_color || sd.border_color || '#000000')
    ctx.lineWidth = borderWidth
    ctx.stroke()
  }

  return { data: canvas.toDataURL('image/png'), pad }
}

async function renderShape(slide, item, sd, box, scale) {
  const blurAmount = hasBlurEffect(sd)

  if (blurAmount > 0) {
    const itemW = Math.max(Number(item.width || 0), 1)
    const itemH = Math.max(Number(item.height || 0), 1)
    const pxW = Math.max(Math.round(itemW), 10)
    const pxH = Math.max(Math.round(itemH), 10)

    try {
      const { data, pad } = await rasterizeBlurredShape(item, sd, pxW, pxH)
      const padIn = pad * scale
      slide.addImage({
        data,
        x: box.x - padIn,
        y: box.y - padIn,
        w: box.w + padIn * 2,
        h: box.h + padIn * 2,
      })
    } catch {
      renderPlaceholder(slide, 'Shape', box)
    }

    const textContent = stripHtml(item.content || item.title || sd.shape_text || '')
    if (textContent) {
      slide.addText(textContent, {
        x: box.x, y: box.y, w: box.w, h: box.h,
        color: hexClean(sd.shape_text_color || '#ffffff'),
        fontSize: Math.max(6, Math.round((sd.shape_font_size || 14) * 0.55)),
        align: 'center', valign: 'middle',
        bold: ['bold', '600', '700', '800', '900'].includes(String(sd.shape_font_weight || '600')),
      })
    }
    return
  }

  const bg = hexClean(sd.shape_fill || item.color || sd.background_color || '#6366f1')
  const shapeType = sd.shape_type || 'rectangle'
  const isCircle = ['circle', 'ellipse'].includes(shapeType)
  const opacity = normalizeItemOpacity(item)
  const trans = toTransparency(opacity)

  const opts = {
    x: box.x, y: box.y, w: box.w, h: box.h,
    fill: { color: bg, transparency: trans },
  }

  const borderWidth = sd.shape_border_width || sd.border_width || 0
  if (borderWidth > 0) {
    opts.line = {
      color: hexClean(sd.shape_border_color || sd.border_color || '#000000'),
      width: Math.max(1, borderWidth),
      transparency: trans,
    }
  }

  const borderRadiusPx = sd.shape_border_radius || 0
  let rectType = 'rect'
  if (isCircle) {
    rectType = 'ellipse'
  } else if (borderRadiusPx > 0) {
    rectType = 'roundRect'
    opts.rectRadius = borderRadiusPx * scale
  }
  slide.addShape(rectType, opts)

  const textContent = stripHtml(item.content || item.title || sd.shape_text || '')
  if (textContent) {
    slide.addText(textContent, {
      x: box.x, y: box.y, w: box.w, h: box.h,
      color: hexClean(sd.shape_text_color || '#ffffff'),
      fontSize: Math.max(6, Math.round((sd.shape_font_size || 14) * 0.55)),
      align: 'center',
      valign: 'middle',
      bold: ['bold', '600', '700', '800', '900'].includes(String(sd.shape_font_weight || '600')),
    })
  }
}

function renderColorSwatch(slide, item, box) {
  const bg = hexClean(item.color || '#6366f1')
  const fg = contrastColor(bg)
  const opacity = normalizeItemOpacity(item)
  const trans = toTransparency(opacity)

  slide.addShape('roundRect', {
    x: box.x, y: box.y, w: box.w, h: box.h,
    fill: { color: bg, transparency: trans },
    rectRadius: 0.06,
  })

  slide.addText(item.color || '#6366f1', {
    x: box.x, y: box.y, w: box.w, h: box.h,
    color: fg,
    fontSize: 8,
    fontFace: 'Consolas',
    align: 'center',
    valign: 'bottom',
    margin: [0, 0, 6, 0],
  })
}

function renderTodoList(slide, item, sd, box) {
  const lines = []
  if (item.title) {
    lines.push({ text: item.title + '\n', options: { bold: true, fontSize: 10, color: '111827' } })
  }
  for (const todo of item.todos || []) {
    const check = todo.completed ? '[x] ' : '[ ] '
    lines.push({
      text: check + (todo.text || '') + '\n',
      options: {
        fontSize: 8,
        color: todo.completed ? '9CA3AF' : '374151',
        strike: todo.completed ? 'sngStrike' : undefined,
      },
    })
  }
  if (!lines.length) return

  const opacity = normalizeItemOpacity(item)
  const trans = toTransparency(opacity)
  slide.addText(lines, {
    x: box.x, y: box.y, w: box.w, h: box.h,
    fill: { color: 'FFFFFF', transparency: trans },
    line: { color: 'E5E7EB', width: 1, transparency: trans },
    valign: 'top',
    margin: [8, 10, 8, 10],
  })
}

function renderLink(slide, item, box) {
  const lines = []
  if (item.title) lines.push({ text: item.title + '\n', options: { bold: true, fontSize: 10, color: '111827' } })
  if (item.url) lines.push({ text: item.url, options: { fontSize: 7, color: '6366F1', hyperlink: { url: item.url } } })
  if (!lines.length) return

  const opacity = normalizeItemOpacity(item)
  const trans = toTransparency(opacity)
  slide.addText(lines, {
    x: box.x, y: box.y, w: box.w, h: box.h,
    fill: { color: 'FFFFFF', transparency: trans },
    line: { color: 'E5E7EB', width: 1, transparency: trans },
    valign: 'top',
    margin: [8, 10, 8, 10],
  })
}

function renderFrame(slide, item, sd, box, scale) {
  const opacity = normalizeItemOpacity(item)
  const trans = toTransparency(opacity)

  const bgColor = sd.artboard_bg || sd.background_color || sd.shape_fill || item.color
  const borderWidth = sd.stroke_width || sd.shape_border_width || sd.border_width || 0
  const borderColor = sd.stroke_color || sd.shape_border_color || sd.border_color || 'D1D5DB'
  const radiusPx = sd.radius ?? sd.shape_border_radius ?? 0

  const opts = {
    x: box.x, y: box.y, w: box.w, h: box.h,
    fill: bgColor ? { color: hexClean(bgColor), transparency: trans } : { type: 'none' },
  }

  if (borderWidth > 0) {
    opts.line = { color: hexClean(borderColor), width: Math.max(1, borderWidth), transparency: trans }
  }

  let rectType = 'rect'
  if (radiusPx > 0) {
    rectType = 'roundRect'
    opts.rectRadius = radiusPx * scale
  }

  slide.addShape(rectType, opts)

  if (item.title) {
    slide.addText(item.title, {
      x: box.x + 0.03, y: box.y + 0.02, w: box.w - 0.06, h: 0.2,
      fontSize: 6, color: '9CA3AF', bold: true,
    })
  }
}

async function renderImageSet(slide, item, sd, box) {
  const images = item.images || []
  if (!images.length) return
  const cols = images.length <= 1 ? 1 : (images.length <= 4 ? 2 : 3)
  const rows = Math.ceil(images.length / cols)
  const gap = 0.02
  const cellW = (box.w - gap * (cols - 1)) / cols
  const cellH = (box.h - gap * (rows - 1)) / rows

  const promises = images.map(async (img, idx) => {
    const url = img.image_url || img.thumbnail_url
    const data = await fetchImageBase64(url)
    if (!data) return
    const col = idx % cols
    const row = Math.floor(idx / cols)
    try {
      slide.addImage({
        data,
        x: box.x + col * (cellW + gap),
        y: box.y + row * (cellH + gap),
        w: cellW,
        h: cellH,
      })
    } catch {}
  })
  await Promise.all(promises)
}

function parseDrawingContent(item) {
  const raw = item.content
  if (!raw) return null
  try { return typeof raw === 'string' ? JSON.parse(raw) : raw } catch { return null }
}

function buildDrawingSvg(item) {
  const data = parseDrawingContent(item)
  const sd = parseSd(item)
  const itemW = Math.max(Number(item.width || 0), 1)
  const itemH = Math.max(Number(item.height || 0), 1)

  let strokes = data?.strokes || []
  if (!strokes.length) {
    strokes = sd.strokes_data || sd.drawing_strokes || []
  }
  if (!strokes.length) return null

  const vbW = parseInt(data?.width) || sd.original_width || itemW
  const vbH = parseInt(data?.height) || sd.original_height || itemH

  let paths = ''
  for (const stroke of strokes) {
    const color = stroke.color || '#000000'
    if (stroke.svgPath) {
      paths += `<path d="${stroke.svgPath}" fill="${color}" />`
    } else if (stroke.points?.length >= 2) {
      const pts = stroke.points
      if (stroke.width && Array.isArray(pts[0])) {
        const opts = stroke.options || {}
        const sz = stroke.width || 8
        const outline = getStroke(pts, {
          size: sz,
          thinning: opts.thinning ?? 0.5,
          smoothing: opts.smoothing ?? 0.7,
          streamline: opts.streamline ?? 0.6,
          easing: (t) => Math.sin((t * Math.PI) / 2),
          start: { taper: opts.taperEnabled !== false ? sz * 0.5 : 0, cap: true, easing: (t) => t * t },
          end: { taper: opts.taperEnabled !== false ? sz * 0.5 : 0, cap: true, easing: (t) => 1 - (1 - t) * (1 - t) },
          simulatePressure: opts.simulatePressure ?? true,
        })
        if (outline?.length >= 2) {
          const len = outline.length
          let d = `M ${outline[0][0].toFixed(2)} ${outline[0][1].toFixed(2)} Q`
          for (let i = 0; i < len; i++) {
            const [x0, y0] = outline[i]
            const [x1, y1] = outline[(i + 1) % len]
            d += ` ${x0.toFixed(2)} ${y0.toFixed(2)} ${((x0 + x1) / 2).toFixed(2)} ${((y0 + y1) / 2).toFixed(2)}`
          }
          d += ' Z'
          paths += `<path d="${d}" fill="${color}" />`
          continue
        }
      }
      let d = ''
      if (Array.isArray(pts[0])) {
        d = `M${pts[0][0]},${pts[0][1]}` + pts.slice(1).map(p => `L${p[0]},${p[1]}`).join('') + 'Z'
      } else if (pts[0].x != null) {
        d = `M${pts[0].x},${pts[0].y}` + pts.slice(1).map(p => `L${p.x},${p.y}`).join('') + (stroke.closed !== false ? 'Z' : '')
      }
      if (d) paths += `<path d="${d}" fill="${color}" />`
    }
  }
  if (!paths) return null

  return `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ${vbW} ${vbH}" width="${itemW}" height="${itemH}">${paths}</svg>`
}


async function renderDrawing(slide, item, box) {
  const svg = buildDrawingSvg(item)
  if (!svg) return

  const pxW = Math.max(Math.round(box.w * 96), 10)
  const pxH = Math.max(Math.round(box.h * 96), 10)

  try {
    const data = await rasterizeSvg(svg, pxW, pxH)
    if (data) {
      slide.addImage({ data, x: box.x, y: box.y, w: box.w, h: box.h })
      return
    }
  } catch {}
  renderPlaceholder(slide, item.title || 'Drawing', box)
}

function renderPlaceholder(slide, label, box, bgHex = 'F9FAFB', fgHex = '6B7280') {
  slide.addShape('rect', {
    x: box.x, y: box.y, w: box.w, h: box.h,
    fill: { color: bgHex },
    line: { color: 'E5E7EB', width: 1 },
  })
  slide.addText(label, {
    x: box.x, y: box.y, w: box.w, h: box.h,
    fontSize: 8, color: fgHex, align: 'center', valign: 'middle',
  })
}

async function renderItemOnSlide(slide, item, page, sf) {
  const sd = parseSd(item)
  const dims = resolveItemDimensions(item)
  const rawX = toInches(Number(item.pos_x || 0), page.originX, sf.s, sf.ox)
  const rawY = toInches(Number(item.pos_y || 0), page.originY, sf.s, sf.oy)
  const rawW = Math.max(dims.w, 1) * sf.s
  const rawH = Math.max(dims.h, 1) * sf.s
  const box = clampBox(rawX, rawY, rawW, rawH)

  if (box.w < 0.05 || box.h < 0.05) return

  switch (item.type) {
    case 'image':
      await renderImage(slide, item, sd, box)
      break
    case 'text': {
      const fontFamily = sd.font_family || 'Arial'
      if (isIconFont(fontFamily)) {
        await renderIcon(slide, item, sd, box)
      } else {
        renderText(slide, item, sd, box, sf.s)
      }
      break
    }
    case 'note':
      renderNote(slide, item, sd, box, sf.s)
      break
    case 'shape':
    case 'pen_shape':
      await renderShape(slide, item, sd, box, sf.s)
      break
    case 'color_swatch':
      renderColorSwatch(slide, item, box)
      break
    case 'todo_list':
      renderTodoList(slide, item, sd, box)
      break
    case 'link':
      renderLink(slide, item, box)
      break
    case 'frame':
    case 'column':
      renderFrame(slide, item, sd, box, sf.s)
      break
    case 'image_set':
      await renderImageSet(slide, item, sd, box)
      break
    case 'drawing':
      await renderDrawing(slide, item, box)
      break
    case 'group':
    case 'repeat_grid':
    case 'artboard':
      break
    case 'video':
    case 'youtube':
      renderPlaceholder(slide, item.title || 'Video', box, '111827', 'A855F7')
      break
    default:
      renderPlaceholder(slide, item.title || item.type || 'item', box)
      break
  }
}

function readAccentColor() {
  try {
    const rgb = getComputedStyle(document.documentElement)
      .getPropertyValue('--color-primary-500').trim()
    if (rgb) {
      const parts = rgb.split(/\s+/)
      if (parts.length === 3) {
        const r = Math.min(255, Number(parts[0]))
        const g = Math.min(255, Number(parts[1]))
        const b = Math.min(255, Number(parts[2]))
        return '#' + ((1 << 24) | (r << 16) | (g << 8) | b).toString(16).slice(1)
      }
    }
  } catch {}
  return '#6366f1'
}

export async function exportMoodBoardPptx(board, selectedSlideIds = null) {
  if (!board) return false

  const pptx = new PptxGenJS()
  pptx.author = 'FlowONE'
  pptx.title = board.name || 'Moodboard'
  pptx.defineLayout({ name: 'MOOD_16x9', width: SLIDE_W_IN, height: SLIDE_H_IN })
  pptx.layout = 'MOOD_16x9'

  const accentColor = readAccentColor()
  const pages = buildPages(board, selectedSlideIds)
  const bgColor = hexClean(board.background_color || '#f5f5f5')
  const connections = board.connections || []

  for (const page of pages) {
    const slide = pptx.addSlide()
    slide.background = { color: bgColor }

    const sf = scaleFactors(page.canvasW, page.canvasH)
    const sorted = [...page.items].sort((a, b) => (a.z_index || 0) - (b.z_index || 0))

    for (const item of sorted) {
      await renderItemOnSlide(slide, item, page, sf)
    }

    const pageItemIds = new Set(page.items.map(i => i.id))
    const pageConns = connections.filter(c => pageItemIds.has(c.from_item_id) && pageItemIds.has(c.to_item_id))
    if (pageConns.length) {
      await renderConnectionsOverlay(slide, pageConns, page, sf, accentColor, page.items)
    }
  }

  const blob = await pptx.write({ outputType: 'blob' })
  const safeName = (board.name || 'moodboard').replace(/[^a-zA-Z0-9_-]/g, '_')
  const url = URL.createObjectURL(blob)
  const link = document.createElement('a')
  link.href = url
  link.download = `${safeName}.pptx`
  document.body.appendChild(link)
  link.click()
  document.body.removeChild(link)
  URL.revokeObjectURL(url)
  return true
}
