/**
 * sketchParser.js
 *
 * Parses .sketch files (ZIP archives with JSON + assets) in the browser.
 * Converts Sketch layers into mood board items using the legacy style_data format
 * (via figmaToLegacy) so items render immediately without rendering layer changes.
 *
 * Sketch file structure:
 *   document.json        — metadata, pages list, shared styles
 *   meta.json            — version, page list
 *   pages/<uuid>.json    — layer tree for each page
 *   images/<sha>.png     — embedded bitmap assets
 */

import JSZip from 'jszip'
import { figmaToLegacy } from './styleAdapter'
import { emptyStyleData, PaintType, EffectType, BlendMode, StrokeAlign, ShapeType } from './figmaStyleSchema'

const SUPPORTED_CLASSES = new Set([
  'rectangle', 'oval', 'shapePath', 'triangle', 'star', 'polygon',
  'text', 'bitmap', 'group', 'artboard', 'shapeGroup',
  'symbolInstance', 'symbolMaster', 'slice',
])

/**
 * Parse a .sketch File object.
 * Returns { pages: [{ name, layers: [...] }], images: Map<sha, Blob>, symbols: Map<id, layer> }
 */
export async function parseSketchFile(file) {
  const zip = await JSZip.loadAsync(file)
  const [metaRaw, docRaw] = await Promise.all([
    zip.file('meta.json').async('string'),
    zip.file('document.json').async('string'),
  ])
  const meta = JSON.parse(metaRaw)
  const doc = JSON.parse(docRaw)

  const pageOrder = doc.pages?.map(p => p._ref) || Object.keys(meta.pagesAndArtboards || {})

  const pagePromises = pageOrder.map(ref => {
    const basePath = ref.endsWith('.json') ? ref : `${ref}.json`
    const candidates = [basePath, basePath.startsWith('pages/') ? basePath : `pages/${basePath}`]
    let pageFile = null
    for (const c of candidates) { pageFile = zip.file(c); if (pageFile) break }
    if (!pageFile) return null
    return pageFile.async('string').then(raw => {
      const d = JSON.parse(raw)
      return { name: d.name || 'Page', layers: d.layers || [] }
    })
  })
  const pages = (await Promise.all(pagePromises)).filter(Boolean)

  const images = new Map()
  const imgFolder = zip.folder('images')
  if (imgFolder) {
    const imgEntries = []
    imgFolder.forEach((relativePath, zipEntry) => {
      if (!zipEntry.dir) imgEntries.push({ name: relativePath, entry: zipEntry })
    })
    const IMG_BATCH = 10
    for (let i = 0; i < imgEntries.length; i += IMG_BATCH) {
      const batch = imgEntries.slice(i, i + IMG_BATCH)
      const blobs = await Promise.all(batch.map(e => e.entry.async('blob')))
      batch.forEach((e, idx) => images.set(e.name, blobs[idx]))
    }
  }

  const symbols = new Map()
  collectSymbols(pages, symbols)

  return { pages, images, symbols, document: doc }
}

function collectSymbols(pages, symbols) {
  for (const page of pages) {
    walkLayers(page.layers, layer => {
      if (layer._class === 'symbolMaster') {
        symbols.set(layer.symbolID, layer)
      }
    })
  }
}

function walkLayers(layers, fn) {
  for (const layer of (layers || [])) {
    fn(layer)
    if (layer.layers) walkLayers(layer.layers, fn)
  }
}

let _nextTempId = 0
let _itemCount = 0
let _maxItems = Infinity
let _maxDepth = 50
let _minSize = 1

function makeTempId() {
  return `_sketch_${++_nextTempId}`
}

/**
 * Convert parsed Sketch pages into mood board items.
 * Each item has `_tempId` and `_tempParentId` to express hierarchy.
 * The caller must:
 *   1. batch-add items (without parent_id)
 *   2. map _tempId -> realId via response order
 *   3. batch-update parent_id for items with _tempParentId
 *
 * @param {Object} parsed - Output of parseSketchFile
 * @param {Object} options
 *   pageIndex: number | 'all' (default 'all')
 *   maxItems: cap total items (default 500)
 *   maxDepth: max nesting depth to recurse (default 3, 0 = artboards only)
 *   minSize: skip layers smaller than this (default 4px)
 * @returns {Promise<Object[]>} Flat array with _tempId/_tempParentId metadata
 */
export async function sketchToMoodItems(parsed, options = {}) {
  const {
    startX = 100,
    startY = 100,
    uploadImageFn = null,
    onProgress = null,
    pageIndex = 'all',
    maxItems = Infinity,
    maxDepth = 50,
    minSize = 1,
  } = options

  _nextTempId = 0
  _itemCount = 0
  _maxItems = maxItems
  _maxDepth = maxDepth
  _minSize = minSize

  const imageUrlMap = new Map()

  if (uploadImageFn && parsed.images.size > 0) {
    const entries = [...parsed.images.entries()]
    const UPLOAD_BATCH = 4
    let uploaded = 0
    for (let i = 0; i < entries.length; i += UPLOAD_BATCH) {
      const batch = entries.slice(i, i + UPLOAD_BATCH)
      const results = await Promise.allSettled(
        batch.map(([sha, blob]) => uploadImageFn(blob, sha).then(url => ({ sha, url })))
      )
      for (const r of results) {
        if (r.status === 'fulfilled' && r.value.url) imageUrlMap.set(r.value.sha, r.value.url)
      }
      uploaded += batch.length
      if (onProgress) onProgress(uploaded / entries.length * 0.5)
    }
  } else if (onProgress) {
    onProgress(0.5)
  }

  const pagesToProcess = pageIndex === 'all'
    ? parsed.pages
    : [parsed.pages[pageIndex]].filter(Boolean)

  if (!pagesToProcess.length) return []

  const items = []
  let cursorY = startY
  const totalLayers = pagesToProcess.reduce((sum, p) => sum + (p.layers?.length || 0), 0)
  let processedLayers = 0

  for (const page of pagesToProcess) {
    if (_itemCount >= _maxItems) break
    let pageMaxBottom = cursorY

    for (const layer of page.layers) {
      if (_itemCount >= _maxItems) break
      const converted = convertLayer(layer, startX, cursorY, imageUrlMap, parsed.symbols, null, 0)
      items.push(...converted)
      for (const item of converted) {
        const bottom = (item.pos_y || 0) + (item.height || 0)
        if (bottom > pageMaxBottom) pageMaxBottom = bottom
      }
      processedLayers++
      if (onProgress && totalLayers > 0) {
        onProgress(0.5 + (processedLayers / totalLayers) * 0.5)
      }
    }

    cursorY = pageMaxBottom + 200
  }

  if (_itemCount >= _maxItems) {
    console.warn(`[SketchParser] Reached item limit (${_maxItems}). Some elements were skipped.`)
  }

  if (onProgress) onProgress(1)
  return items
}

/**
 * @param {number} depth - current nesting depth (0 = page root)
 * @returns {Object[]} flat array of items (parent first, then children)
 */
function convertLayer(layer, parentAbsX, parentAbsY, imageUrlMap, symbols, parentTempId, depth) {
  if (_itemCount >= _maxItems) return []

  const cls = layer._class
  if (!cls || cls === 'slice') return []
  if (layer.isVisible === false) return []

  const frame = layer.frame || {}
  const relX = frame.x || 0
  const relY = frame.y || 0
  const absX = parentAbsX + relX
  const absY = parentAbsY + relY
  const w = frame.width || 100
  const h = frame.height || 100

  // Children store RELATIVE position (to their parent); root items store ABSOLUTE
  const posX = parentTempId != null ? relX : absX
  const posY = parentTempId != null ? relY : absY

  if (w < _minSize && h < _minSize) return []

  if (cls === 'artboard' || cls === 'symbolMaster') {
    return convertArtboard(layer, absX, absY, posX, posY, w, h, imageUrlMap, symbols, parentTempId, depth)
  }

  if (depth >= _maxDepth) {
    if (cls === 'text') {
      return [withMeta(convertText(layer, posX, posY, w, h), parentTempId)]
    }
    if (cls === 'bitmap') {
      return [withMeta(convertBitmap(layer, posX, posY, w, h, imageUrlMap), parentTempId)]
    }
    return [withMeta(convertShape(layer, posX, posY, w, h, 'rectangle'), parentTempId)]
  }

  if (cls === 'group' || cls === 'shapeGroup') {
    return convertGroup(layer, absX, absY, posX, posY, w, h, imageUrlMap, symbols, parentTempId, depth)
  }
  if (cls === 'text') {
    return [withMeta(convertText(layer, posX, posY, w, h), parentTempId)]
  }
  if (cls === 'bitmap') {
    return [withMeta(convertBitmap(layer, posX, posY, w, h, imageUrlMap), parentTempId)]
  }
  if (cls === 'symbolInstance') {
    return convertSymbolInstance(layer, absX, absY, posX, posY, w, h, imageUrlMap, symbols, parentTempId, depth)
  }
  if (['rectangle', 'oval', 'shapePath', 'triangle', 'star', 'polygon'].includes(cls)) {
    return [withMeta(convertShape(layer, posX, posY, w, h, cls), parentTempId)]
  }

  return []
}

function withMeta(item, parentTempId) {
  if (!item || _itemCount >= _maxItems) return null
  item._tempId = makeTempId()
  item._tempParentId = parentTempId
  _itemCount++
  return item
}

function convertArtboard(layer, absX, absY, posX, posY, w, h, imageUrlMap, symbols, parentTempId, depth) {
  if (_itemCount >= _maxItems) return []
  const myTempId = makeTempId()
  _itemCount++

  const sd = buildStyleData('frame', layer)

  const frameItem = {
    _tempId: myTempId,
    _tempParentId: parentTempId,
    type: 'frame',
    pos_x: posX,
    pos_y: posY,
    width: w,
    height: h,
    title: layer.name || 'Artboard',
    style_data: sd,
  }

  const items = [frameItem]
  for (const child of (layer.layers || [])) {
    if (_itemCount >= _maxItems) break
    items.push(...convertLayer(child, absX, absY, imageUrlMap, symbols, myTempId, depth + 1))
  }

  return items
}

function convertGroup(layer, absX, absY, posX, posY, w, h, imageUrlMap, symbols, parentTempId, depth) {
  if (_itemCount >= _maxItems) return []
  const myTempId = makeTempId()
  _itemCount++

  const groupItem = {
    _tempId: myTempId,
    _tempParentId: parentTempId,
    type: 'group',
    pos_x: posX,
    pos_y: posY,
    width: w,
    height: h,
    title: layer.name || 'Group',
    style_data: buildStyleData('shape', layer),
  }

  const items = [groupItem]
  for (const child of (layer.layers || [])) {
    if (_itemCount >= _maxItems) break
    items.push(...convertLayer(child, absX, absY, imageUrlMap, symbols, myTempId, depth + 1))
  }

  return items
}

function convertText(layer, x, y, w, h) {
  const sd = buildStyleData('text', layer)
  const textStr = layer.attributedString?.string || layer.name || ''

  const textAttrs = layer.style?.textStyle?.encodedAttributes || {}
  const font = textAttrs.MSAttributedStringFontAttribute?.attributes || {}
  const paragraphStyle = textAttrs.paragraphStyle || {}

  sd.font_family = font.name?.split('-')[0] || 'Inter'
  sd.font_size = font.size || 14
  sd.font_weight = fontNameToWeight(font.name)
  if (paragraphStyle.alignment != null) {
    sd.text_align = sketchAlignToCSS(paragraphStyle.alignment)
  }
  if (paragraphStyle.maximumLineHeight || paragraphStyle.minimumLineHeight) {
    const lh = paragraphStyle.maximumLineHeight || paragraphStyle.minimumLineHeight
    sd.line_height = lh / (sd.font_size || 14)
  }

  const textColor = textAttrs.MSAttributedStringColorAttribute
  if (textColor) {
    sd.text_color = sketchColorToHex(textColor)
  }

  return {
    type: 'text',
    pos_x: x,
    pos_y: y,
    width: w,
    height: h,
    title: '',
    content: textStr,
    style_data: sd,
  }
}

function convertBitmap(layer, x, y, w, h, imageUrlMap) {
  const imageRef = layer.image?._ref || ''
  const imageName = imageRef.replace('images/', '')
  const imageUrl = imageUrlMap.get(imageName) || null

  return {
    type: 'image',
    pos_x: x,
    pos_y: y,
    width: w,
    height: h || w * 0.75,
    title: layer.name || '',
    image_url: imageUrl,
    style_data: {},
  }
}

function convertSymbolInstance(layer, absX, absY, posX, posY, w, h, imageUrlMap, symbols, parentTempId, depth) {
  const master = symbols.get(layer.symbolID)
  if (master) {
    return convertGroup(
      { ...master, frame: layer.frame, name: layer.name || master.name },
      absX, absY, posX, posY, w, h,
      imageUrlMap, symbols, parentTempId, depth
    )
  }
  return [withMeta(convertShape(layer, posX, posY, w, h, 'rectangle'), parentTempId)]
}

function convertShape(layer, x, y, w, h, cls) {
  const sd = buildStyleData('shape', layer)

  if (cls === 'oval') sd.shape_type = 'circle'
  else if (cls === 'triangle') sd.shape_type = 'triangle'
  else if (cls === 'star') sd.shape_type = 'star'
  else sd.shape_type = 'rectangle'

  if (cls === 'rectangle' && layer.fixedRadius) {
    sd.shape_border_radius = layer.fixedRadius
    sd.border_radius_tl = layer.fixedRadius
    sd.border_radius_tr = layer.fixedRadius
    sd.border_radius_br = layer.fixedRadius
    sd.border_radius_bl = layer.fixedRadius
  }

  if (layer.points?.length && cls === 'rectangle') {
    const corners = layer.points.map(p => p.cornerRadius || 0)
    if (corners.length >= 4) {
      sd.border_radius_tl = corners[0]
      sd.border_radius_tr = corners[1]
      sd.border_radius_br = corners[2]
      sd.border_radius_bl = corners[3]
    }
  }

  return {
    type: 'shape',
    pos_x: x,
    pos_y: y,
    width: w,
    height: h,
    title: layer.name || '',
    style_data: sd,
  }
}

/**
 * Extract style properties from a Sketch layer and build legacy style_data.
 * Converts Sketch's 0-1 RGBA fills/borders/shadows to legacy flat keys.
 */
function buildStyleData(itemType, layer) {
  const figma = emptyStyleData()
  const sketchStyle = layer.style || {}

  // Fills
  const fills = (sketchStyle.fills || []).filter(f => f.isEnabled !== false)
  if (fills.length > 0) {
    figma.fills = fills.map(convertSketchFill)
  }

  // Borders
  const borders = (sketchStyle.borders || []).filter(b => b.isEnabled !== false)
  if (borders.length > 0) {
    figma.strokes = borders.map(b => ({
      type: PaintType.SOLID,
      visible: true,
      opacity: 1,
      color: sketchColorToFigma(b.color),
    }))
    figma.strokeWeight = borders[0].thickness || 1
    const posMap = { 0: StrokeAlign.CENTER, 1: StrokeAlign.INSIDE, 2: StrokeAlign.OUTSIDE }
    figma.strokeAlign = posMap[borders[0].position] || StrokeAlign.INSIDE
  }

  // Shadows
  const shadows = (sketchStyle.shadows || []).filter(s => s.isEnabled !== false)
  for (const s of shadows) {
    figma.effects.push({
      type: EffectType.DROP_SHADOW,
      visible: true,
      color: sketchColorToFigma(s.color),
      offset: { x: s.offsetX || 0, y: s.offsetY || 0 },
      radius: s.blurRadius || 0,
      spread: s.spread || 0,
    })
  }

  const innerShadows = (sketchStyle.innerShadows || []).filter(s => s.isEnabled !== false)
  for (const s of innerShadows) {
    figma.effects.push({
      type: EffectType.INNER_SHADOW,
      visible: true,
      color: sketchColorToFigma(s.color),
      offset: { x: s.offsetX || 0, y: s.offsetY || 0 },
      radius: s.blurRadius || 0,
      spread: s.spread || 0,
    })
  }

  // Blur
  if (sketchStyle.blur?.isEnabled) {
    const blurType = sketchStyle.blur.type === 3 ? EffectType.BACKGROUND_BLUR : EffectType.LAYER_BLUR
    figma.effects.push({
      type: blurType,
      visible: true,
      radius: sketchStyle.blur.radius || 0,
    })
  }

  // Opacity
  figma.opacity = layer.style?.contextSettings?.opacity ?? 1

  // Blend mode
  const bmMap = {
    0: BlendMode.NORMAL, 1: BlendMode.DARKEN, 2: BlendMode.MULTIPLY,
    3: BlendMode.COLOR_BURN, 5: BlendMode.LIGHTEN, 6: BlendMode.SCREEN,
    7: BlendMode.COLOR_DODGE, 9: BlendMode.OVERLAY, 10: BlendMode.SOFT_LIGHT,
    11: BlendMode.HARD_LIGHT, 12: BlendMode.DIFFERENCE, 13: BlendMode.EXCLUSION,
    14: BlendMode.HUE, 15: BlendMode.SATURATION, 16: BlendMode.COLOR,
    17: BlendMode.LUMINOSITY,
  }
  figma.blendMode = bmMap[sketchStyle.contextSettings?.blendMode] || BlendMode.NORMAL

  // Rotation
  const rotation = layer.rotation || 0

  return figmaToLegacy(itemType, figma)
}

function convertSketchFill(fill) {
  const fillType = fill.fillType ?? 0
  if (fillType === 1 && fill.gradient) {
    return convertSketchGradient(fill.gradient)
  }
  return {
    type: PaintType.SOLID,
    visible: true,
    opacity: 1,
    color: sketchColorToFigma(fill.color),
  }
}

function convertSketchGradient(gradient) {
  const typeMap = { 0: PaintType.GRADIENT_LINEAR, 1: PaintType.GRADIENT_RADIAL, 2: PaintType.GRADIENT_ANGULAR }
  const stops = (gradient.stops || []).map(s => ({
    position: s.position ?? 0,
    color: sketchColorToFigma(s.color),
  }))

  let angle = 180
  if (gradient.from && gradient.to) {
    const dx = (gradient.to.x || 0.5) - (gradient.from.x || 0.5)
    const dy = (gradient.to.y || 1) - (gradient.from.y || 0)
    angle = Math.round(Math.atan2(dy, dx) * 180 / Math.PI + 90)
    if (angle < 0) angle += 360
  }

  return {
    type: typeMap[gradient.gradientType] || PaintType.GRADIENT_LINEAR,
    visible: true,
    opacity: 1,
    gradientAngle: angle,
    gradientStops: stops,
  }
}

// ── Color conversion helpers ──

function sketchColorToFigma(c) {
  if (!c) return { r: 0, g: 0, b: 0, a: 1 }
  return {
    r: round4(c.red ?? 0),
    g: round4(c.green ?? 0),
    b: round4(c.blue ?? 0),
    a: round4(c.alpha ?? 1),
  }
}

function sketchColorToHex(c) {
  if (!c) return '#000000'
  const r = Math.round((c.red ?? 0) * 255)
  const g = Math.round((c.green ?? 0) * 255)
  const b = Math.round((c.blue ?? 0) * 255)
  return '#' + [r, g, b].map(v => v.toString(16).padStart(2, '0')).join('')
}

function round4(n) {
  return Math.round(n * 10000) / 10000
}

function fontNameToWeight(name) {
  if (!name) return 400
  const lower = name.toLowerCase()
  if (lower.includes('thin') || lower.includes('hairline')) return 100
  if (lower.includes('extralight') || lower.includes('ultralight')) return 200
  if (lower.includes('light')) return 300
  if (lower.includes('medium')) return 500
  if (lower.includes('semibold') || lower.includes('demibold')) return 600
  if (lower.includes('extrabold') || lower.includes('ultrabold')) return 800
  if (lower.includes('black') || lower.includes('heavy')) return 900
  if (lower.includes('bold')) return 700
  return 400
}

function sketchAlignToCSS(alignment) {
  const map = { 0: 'left', 1: 'right', 2: 'center', 3: 'justify' }
  return map[alignment] || 'left'
}

/**
 * Get page names from a parsed sketch file for the import dialog.
 */
export function getSketchPageNames(parsed) {
  return parsed.pages.map((p, i) => ({ index: i, name: p.name }))
}

/**
 * Count elements in a specific page for progress estimation.
 */
export function countPageElements(parsed, pageIndex = 0) {
  let count = 0
  const page = parsed.pages[pageIndex]
  if (!page) return 0
  walkLayers(page.layers, () => count++)
  return count
}
