import { Container, Graphics, Sprite } from 'pixi.js'
import { getFillStyles, getStrokeStyle, applyFills, applyStroke, applyTransform, getStyleProps } from '../../utils/styleToPixi.js'

/**
 * Renders pen_shape items (SVG paths from the pen tool).
 */
export function createPenShape(item, textureCache) {
  const container = new Container()
  container.label = `pen-shape-${item.id}`
  drawPenShape(container, item, textureCache)
  applyTransform(container, item)
  return container
}

export function updatePenShape(container, item, textureCache) {
  container.removeChildren()
  container.mask = null
  drawPenShape(container, item, textureCache)
  applyTransform(container, item)
}

function drawPenShape(container, item, textureCache) {
  const rawSd = item.style_data || {}
  const sd = getStyleProps('pen_shape', rawSd)
  const w = item.width || 100
  const h = item.height || 100
  const pathData = rawSd.pen_path || rawSd.vectorPaths || sd.vectorPaths
  const fillStyles = getFillStyles(sd.fills)
  const strokeStyle = getStrokeStyle(sd.strokes)

  const g = new Graphics()

  if (pathData && Array.isArray(pathData)) {
    for (const segment of pathData) {
      drawPathSegment(g, segment, w, h)
    }
  } else if (rawSd.pen_svg_path) {
    drawSvgPath(g, rawSd.pen_svg_path, w, h)
  }

  applyFills(g, fillStyles, w, h)
  if (strokeStyle) applyStroke(g, strokeStyle)
  else g.stroke({ color: 0x333333, width: 2 })

  container.addChild(g)

  if (rawSd.mask_image_url && textureCache) {
    loadPenMaskImage(container, rawSd.mask_image_url, w, h, g, textureCache)
  }
}

function drawPathSegment(g, segment, w, h) {
  if (!segment.points || segment.points.length < 2) return
  const points = segment.points

  g.moveTo(points[0].x * w, points[0].y * h)
  for (let i = 1; i < points.length; i++) {
    const prev = points[i - 1]
    const curr = points[i]

    if (prev.handleOut && curr.handleIn) {
      g.bezierCurveTo(
        prev.handleOut.x * w, prev.handleOut.y * h,
        curr.handleIn.x * w, curr.handleIn.y * h,
        curr.x * w, curr.y * h,
      )
    } else {
      g.lineTo(curr.x * w, curr.y * h)
    }
  }

  if (segment.closed) g.closePath()
}

function drawSvgPath(g, svgPath, w, h) {
  const commands = parseSvgPathCommands(svgPath)
  let cx = 0, cy = 0

  for (const cmd of commands) {
    const scaleX = w / 100
    const scaleY = h / 100
    switch (cmd.type) {
      case 'M':
        cx = cmd.x * scaleX; cy = cmd.y * scaleY
        g.moveTo(cx, cy)
        break
      case 'L':
        cx = cmd.x * scaleX; cy = cmd.y * scaleY
        g.lineTo(cx, cy)
        break
      case 'C':
        g.bezierCurveTo(
          cmd.x1 * scaleX, cmd.y1 * scaleY,
          cmd.x2 * scaleX, cmd.y2 * scaleY,
          cmd.x * scaleX, cmd.y * scaleY,
        )
        cx = cmd.x * scaleX; cy = cmd.y * scaleY
        break
      case 'Z':
        g.closePath()
        break
    }
  }
}

function parseSvgPathCommands(d) {
  const commands = []
  const regex = /([MLCQAHVSZRTA])([^MLCQAHVSZRTA]*)/gi
  let match
  while ((match = regex.exec(d)) !== null) {
    const type = match[1].toUpperCase()
    const nums = match[2].trim().split(/[\s,]+/).map(Number).filter(n => !isNaN(n))
    if (type === 'M' && nums.length >= 2) commands.push({ type: 'M', x: nums[0], y: nums[1] })
    else if (type === 'L' && nums.length >= 2) commands.push({ type: 'L', x: nums[0], y: nums[1] })
    else if (type === 'C' && nums.length >= 6) commands.push({ type: 'C', x1: nums[0], y1: nums[1], x2: nums[2], y2: nums[3], x: nums[4], y: nums[5] })
    else if (type === 'Z') commands.push({ type: 'Z' })
  }
  return commands
}

function loadPenMaskImage(container, url, w, h, pathGraphics, textureCache) {
  const tex = textureCache.loadSync(url)
  if (tex) {
    const sprite = new Sprite(tex)
    sprite.width = w
    sprite.height = h
    container.addChild(sprite)
    if (pathGraphics) sprite.mask = pathGraphics
  } else {
    textureCache.load(url).then(loadedTex => {
      if (loadedTex && container.parent && !container.destroyed) {
        const sprite = new Sprite(loadedTex)
        sprite.width = w
        sprite.height = h
        container.addChild(sprite)
        const pg = container.children.find(c => c instanceof Graphics)
        if (pg) sprite.mask = pg
      }
    })
  }
}
