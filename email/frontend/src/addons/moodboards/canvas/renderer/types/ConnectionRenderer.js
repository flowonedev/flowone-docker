import { Graphics, Text, TextStyle, Container } from 'pixi.js'
import { parseColor } from '../../utils/styleToPixi.js'

let _accentColor = 0x22c55e
try {
  const style = typeof document !== 'undefined' ? getComputedStyle(document.documentElement) : null
  const rgb = style?.getPropertyValue('--color-primary-500')?.trim()
  if (rgb) {
    const parts = rgb.split(/\s+/).map(Number)
    if (parts.length >= 3) _accentColor = (parts[0] << 16) | (parts[1] << 8) | parts[2]
  }
} catch {}

export function refreshAccentColor() {
  try {
    const style = getComputedStyle(document.documentElement)
    const rgb = style.getPropertyValue('--color-primary-500')?.trim()
    if (rgb) {
      const parts = rgb.split(/\s+/).map(Number)
      if (parts.length >= 3) _accentColor = (parts[0] << 16) | (parts[1] << 8) | parts[2]
    }
  } catch {}
}

function resolveConnColor(conn) {
  const raw = conn.line_color || conn.color
  if (!raw || raw === 'accent') return { color: _accentColor, alpha: 1 }
  return parseColor(raw)
}

function getItemRect(item) {
  const sd = item.style_data || {}
  const scaleVal = (sd.item_scale != null && sd.item_scale !== 1) ? sd.item_scale : 1
  const rawW = item.width || 240
  const rawH = item.height || 120
  const w = rawW * scaleVal
  const h = rawH * scaleVal
  const x = (item.pos_x || 0) + rawW * (1 - scaleVal) / 2
  const y = (item.pos_y || 0) + rawH * (1 - scaleVal) / 2
  return { x, y, w, h, cx: x + w / 2, cy: y + h / 2 }
}

function getEdgePoint(r, targetX, targetY) {
  const dx = targetX - r.cx
  const dy = targetY - r.cy
  if (dx === 0 && dy === 0) return { x: r.cx, y: r.y }

  const halfW = r.w / 2
  const halfH = r.h / 2
  const absDx = Math.abs(dx)
  const absDy = Math.abs(dy)

  if (absDx / halfW > absDy / halfH) {
    const sign = Math.sign(dx)
    const edgeX = r.cx + sign * halfW
    const edgeY = r.cy + dy * (halfW / absDx)
    return { x: edgeX, y: Math.max(r.y, Math.min(r.y + r.h, edgeY)) }
  } else {
    const sign = Math.sign(dy)
    const edgeY = r.cy + sign * halfH
    const edgeX = r.cx + dx * (halfH / absDy)
    return { x: Math.max(r.x, Math.min(r.x + r.w, edgeX)), y: edgeY }
  }
}

function resolveConnEndpoint(item, anchorX, anchorY, fallbackTargetX, fallbackTargetY) {
  const r = getItemRect(item)
  if (anchorX != null && anchorY != null) {
    return { x: r.x + anchorX * r.w, y: r.y + anchorY * r.h }
  }
  return getEdgePoint(r, fallbackTargetX, fallbackTargetY)
}

/**
 * Renders connection lines between items.
 * Matches the DOM MoodCanvas.vue connection path logic exactly.
 */
export function drawConnection(g, conn, itemMap) {
  const fromItem = itemMap.get(conn.from_item_id)
  const toItem = itemMap.get(conn.to_item_id)
  if (!fromItem || !toItem) return

  const fromRect = getItemRect(fromItem)
  const toRect = getItemRect(toItem)

  const from = resolveConnEndpoint(fromItem, conn.from_anchor_x, conn.from_anchor_y, toRect.cx, toRect.cy)
  const to = resolveConnEndpoint(toItem, conn.to_anchor_x, conn.to_anchor_y, fromRect.cx, fromRect.cy)

  const x1 = from.x, y1 = from.y
  const x2 = to.x, y2 = to.y

  const hasBend1 = conn.bend_x != null && conn.bend_y != null
  const hasBend2 = conn.bend2_x != null && conn.bend2_y != null

  let cx1, cy1, cx2, cy2

  if (hasBend1 || hasBend2) {
    cx1 = hasBend1 ? conn.bend_x : (x1 * 2 / 3 + x2 / 3)
    cy1 = hasBend1 ? conn.bend_y : (y1 * 2 / 3 + y2 / 3)
    cx2 = hasBend2 ? conn.bend2_x : (x1 / 3 + x2 * 2 / 3)
    cy2 = hasBend2 ? conn.bend2_y : (y1 / 3 + y2 * 2 / 3)
  } else {
    const dx = x2 - x1
    const dy = y2 - y1
    const dist = Math.sqrt(dx * dx + dy * dy)
    const curvature = Math.min(dist * 0.25, 80)
    const absDx = Math.abs(dx)
    const absDy = Math.abs(dy)
    if (absDx >= absDy) {
      cx1 = x1 + curvature * Math.sign(dx || 1); cy1 = y1
      cx2 = x2 - curvature * Math.sign(dx || 1); cy2 = y2
    } else {
      cx1 = x1; cy1 = y1 + curvature * Math.sign(dy || 1)
      cx2 = x2; cy2 = y2 - curvature * Math.sign(dy || 1)
    }
  }

  const lineWidth = conn.line_width || 2
  const { color, alpha } = resolveConnColor(conn)
  const useGradient = conn.gradient_enabled && conn.gradient_color_start && conn.gradient_color_end
  const lineStyle = conn.line_style || 'solid'

  if (conn.glow_enabled) {
    const glowAlpha = (conn.glow_opacity ?? 60) / 100
    const glowBlur = conn.glow_blur || 6

    let glowColor = color
    if (useGradient) {
      const sc = parseColor(conn.gradient_color_start || conn.line_color || '#6366f1').color
      const ec = parseColor(conn.gradient_color_end || '#8b5cf6').color
      glowColor = lerpColor(sc, ec, 0.5)
    } else if (conn.glow_color) {
      glowColor = parseColor(conn.glow_color).color
    }

    const glowGfx = new Graphics()
    const passes = 6
    for (let i = passes; i >= 1; i--) {
      const t = i / passes
      const w = lineWidth + glowBlur * 2 * t
      const a = glowAlpha / passes
      glowGfx.moveTo(x1, y1)
      glowGfx.bezierCurveTo(cx1, cy1, cx2, cy2, x2, y2)
      glowGfx.stroke({ color: glowColor, alpha: a, width: w, cap: 'round' })
    }
    g.addChild(glowGfx)
  }

  if (useGradient) {
    drawGradientBezier(g, x1, y1, cx1, cy1, cx2, cy2, x2, y2, conn, lineWidth, alpha, lineStyle)
  } else {
    drawStyledBezier(g, x1, y1, cx1, cy1, cx2, cy2, x2, y2, { color, alpha, width: lineWidth, lineStyle })
  }

  if (conn.arrow_end) {
    const endColor = useGradient ? parseColor(conn.gradient_color_end).color : color
    drawConnectionArrow(g, cx2, cy2, x2, y2, endColor, alpha, lineWidth)
  }
  if (conn.arrow_start) {
    const startColor = useGradient ? parseColor(conn.gradient_color_start).color : color
    drawConnectionArrow(g, cx1, cy1, x1, y1, startColor, alpha, lineWidth)
  }
}

export function createConnectionAnimation(conn, itemMap) {
  const curve = getConnectionCurve(conn, itemMap)
  if (!curve) return null

  const dotCount = getConnDotCount(conn, itemMap)
  const duration = getAnimDuration(conn, itemMap)
  const lineWidth = conn.line_width || 2
  const color = conn.gradient_enabled
    ? parseColor(conn.gradient_color_end || '#8b5cf6').color
    : resolveConnColor(conn).color

  const container = new Container()
  container.label = `connection-animation-${conn.id}`
  container.eventMode = 'none'

  const dots = []
  for (let i = 0; i < dotCount; i++) {
    const dot = new Graphics()
    dot.circle(0, 0, lineWidth * 1.5)
    dot.fill({ color, alpha: 0.7 + 0.15 * Math.sin((i + 1) * 1.8) })
    container.addChild(dot)
    dots.push({ dot, phase: i / dotCount })
  }

  return { connId: conn.id, container, curve, dots, duration }
}

export function updateConnectionAnimation(animation, elapsedMs, visible) {
  if (!animation?.container) return
  animation.container.visible = visible
  if (!visible) return

  const elapsedSeconds = elapsedMs / 1000
  const duration = Math.max(animation.duration || 1.5, 0.1)
  const progress = (elapsedSeconds % duration) / duration

  for (const entry of animation.dots) {
    const t = (progress + entry.phase) % 1
    entry.dot.position.set(
      cubicBezierPoint(t, animation.curve.x1, animation.curve.cx1, animation.curve.cx2, animation.curve.x2),
      cubicBezierPoint(t, animation.curve.y1, animation.curve.cy1, animation.curve.cy2, animation.curve.y2),
    )
  }
}

function cubicBezierPoint(t, p0, p1, p2, p3) {
  const mt = 1 - t
  return mt * mt * mt * p0 + 3 * mt * mt * t * p1 + 3 * mt * t * t * p2 + t * t * t * p3
}

function lerpColor(c1, c2, t) {
  const r1 = (c1 >> 16) & 0xFF, g1 = (c1 >> 8) & 0xFF, b1 = c1 & 0xFF
  const r2 = (c2 >> 16) & 0xFF, g2 = (c2 >> 8) & 0xFF, b2 = c2 & 0xFF
  const r = Math.round(r1 + (r2 - r1) * t)
  const g = Math.round(g1 + (g2 - g1) * t)
  const b = Math.round(b1 + (b2 - b1) * t)
  return (r << 16) | (g << 8) | b
}

const GRADIENT_SEGMENTS = 32

function drawGradientBezier(g, x1, y1, cx1, cy1, cx2, cy2, x2, y2, conn, lineWidth, alpha, lineStyle = 'solid') {
  const startColor = parseColor(conn.gradient_color_start || conn.line_color || '#6366f1').color
  const endColor = parseColor(conn.gradient_color_end || '#8b5cf6').color
  const segments = sampleBezierSegments(x1, y1, cx1, cy1, cx2, cy2, x2, y2, GRADIENT_SEGMENTS * 2)
  const visibleRanges = getVisibleRanges(segments, lineStyle, lineWidth)

  for (const range of visibleRanges) {
    const midT = (range.t0 + range.t1) / 2
    const segColor = lerpColor(startColor, endColor, midT)
    g.moveTo(range.x0, range.y0)
    g.lineTo(range.x1, range.y1)
    g.stroke({ color: segColor, alpha, width: lineWidth, cap: 'round', join: 'round' })
  }
}

function drawStyledBezier(g, x1, y1, cx1, cy1, cx2, cy2, x2, y2, opts) {
  const { color, alpha, width, lineStyle } = opts
  if (lineStyle === 'dashed' || lineStyle === 'dotted') {
    const segments = sampleBezierSegments(x1, y1, cx1, cy1, cx2, cy2, x2, y2, 80)
    const visibleRanges = getVisibleRanges(segments, lineStyle, width)
    if (lineStyle === 'dotted') {
      for (const range of visibleRanges) {
        const cx = (range.x0 + range.x1) / 2
        const cy = (range.y0 + range.y1) / 2
        g.circle(cx, cy, Math.max(width * 0.9, 1))
        g.fill({ color, alpha })
      }
      return
    }
    for (const range of visibleRanges) {
      g.moveTo(range.x0, range.y0)
      g.lineTo(range.x1, range.y1)
      g.stroke({ color, alpha, width, cap: 'round', join: 'round' })
    }
    return
  }

  g.moveTo(x1, y1)
  g.bezierCurveTo(cx1, cy1, cx2, cy2, x2, y2)
  g.stroke({ color, alpha, width, cap: 'round', join: 'round' })
}

function sampleBezierSegments(x1, y1, cx1, cy1, cx2, cy2, x2, y2, steps = 80) {
  const points = []
  let prevX = x1
  let prevY = y1
  let accLen = 0
  points.push({ x: x1, y: y1, t: 0, len: 0 })
  for (let i = 1; i <= steps; i++) {
    const t = i / steps
    const x = cubicBezierPoint(t, x1, cx1, cx2, x2)
    const y = cubicBezierPoint(t, y1, cy1, cy2, y2)
    accLen += Math.hypot(x - prevX, y - prevY)
    points.push({ x, y, t, len: accLen })
    prevX = x
    prevY = y
  }
  return points
}

function getVisibleRanges(points, lineStyle, lineWidth) {
  if (!points.length) return []
  if (lineStyle !== 'dashed' && lineStyle !== 'dotted') {
    const last = points[points.length - 1]
    return [{ x0: points[0].x, y0: points[0].y, x1: last.x, y1: last.y, t0: 0, t1: 1 }]
  }

  const dash = lineStyle === 'dotted' ? Math.max(lineWidth * 1.2, 2) : Math.max(lineWidth * 4, 6)
  const gap = Math.max(lineWidth * 2, 4)
  const period = dash + gap
  const ranges = []

  for (let i = 1; i < points.length; i++) {
    const a = points[i - 1]
    const b = points[i]
    const segLen = b.len - a.len
    if (segLen <= 0) continue
    const midLen = (a.len + b.len) / 2
    if ((midLen % period) > dash) continue
    ranges.push({ x0: a.x, y0: a.y, x1: b.x, y1: b.y, t0: a.t, t1: b.t })
  }
  return ranges
}

export function getConnectionCurve(conn, itemMap) {
  const fromItem = itemMap.get(conn.from_item_id)
  const toItem = itemMap.get(conn.to_item_id)
  if (!fromItem || !toItem) return null

  const fromRect = getItemRect(fromItem)
  const toRect = getItemRect(toItem)

  const from = resolveConnEndpoint(fromItem, conn.from_anchor_x, conn.from_anchor_y, toRect.cx, toRect.cy)
  const to = resolveConnEndpoint(toItem, conn.to_anchor_x, conn.to_anchor_y, fromRect.cx, fromRect.cy)

  const x1 = from.x, y1 = from.y
  const x2 = to.x, y2 = to.y

  const hasBend1 = conn.bend_x != null && conn.bend_y != null
  const hasBend2 = conn.bend2_x != null && conn.bend2_y != null

  let cx1, cy1, cx2, cy2

  if (hasBend1 || hasBend2) {
    cx1 = hasBend1 ? conn.bend_x : (x1 * 2 / 3 + x2 / 3)
    cy1 = hasBend1 ? conn.bend_y : (y1 * 2 / 3 + y2 / 3)
    cx2 = hasBend2 ? conn.bend2_x : (x1 / 3 + x2 * 2 / 3)
    cy2 = hasBend2 ? conn.bend2_y : (y1 / 3 + y2 * 2 / 3)
  } else {
    const dx = x2 - x1
    const dy = y2 - y1
    const dist = Math.sqrt(dx * dx + dy * dy)
    const curvature = Math.min(dist * 0.25, 80)
    const absDx = Math.abs(dx)
    const absDy = Math.abs(dy)
    if (absDx >= absDy) {
      cx1 = x1 + curvature * Math.sign(dx || 1); cy1 = y1
      cx2 = x2 - curvature * Math.sign(dx || 1); cy2 = y2
    } else {
      cx1 = x1; cy1 = y1 + curvature * Math.sign(dy || 1)
      cx2 = x2; cy2 = y2 - curvature * Math.sign(dy || 1)
    }
  }

  return { x1, y1, cx1, cy1, cx2, cy2, x2, y2 }
}

function getAnimDuration(conn, itemMap) {
  const fromItem = itemMap.get(conn.from_item_id)
  const toItem = itemMap.get(conn.to_item_id)
  if (!fromItem || !toItem) return 1.5
  const from = getItemRect(fromItem)
  const to = getItemRect(toItem)
  const dist = Math.sqrt(((to.cx - from.cx) ** 2) + ((to.cy - from.cy) ** 2))
  return Math.max(1.5, Math.min(6, dist / 150))
}

function getConnDotCount(conn, itemMap) {
  const fromItem = itemMap.get(conn.from_item_id)
  const toItem = itemMap.get(conn.to_item_id)
  if (!fromItem || !toItem) return 0
  const from = getItemRect(fromItem)
  const to = getItemRect(toItem)
  const dist = Math.sqrt(((to.cx - from.cx) ** 2) + ((to.cy - from.cy) ** 2))
  if (dist < 400) return 2
  return 3
}

export function drawConnectionArrow(g, fromX, fromY, toX, toY, color, alpha, lineWidth) {
  const angle = Math.atan2(toY - fromY, toX - fromX)
  const ux = Math.cos(angle)
  const uy = Math.sin(angle)
  const nx = -uy
  const ny = ux

  // Match the DOM SVG marker geometry:
  // markerWidth = 10 + lineWidth
  // markerHeight = 6 + lineWidth
  // refX = 6 + lineWidth * 0.35
  const headLength = 10 + lineWidth
  const halfHeadWidth = (6 + lineWidth) / 2
  const tipAdvance = headLength - (6 + lineWidth * 0.35)

  const tipX = toX + ux * tipAdvance
  const tipY = toY + uy * tipAdvance
  const baseCenterX = tipX - ux * headLength
  const baseCenterY = tipY - uy * headLength
  const leftX = baseCenterX + nx * halfHeadWidth
  const leftY = baseCenterY + ny * halfHeadWidth
  const rightX = baseCenterX - nx * halfHeadWidth
  const rightY = baseCenterY - ny * halfHeadWidth

  g.moveTo(leftX, leftY)
  g.lineTo(tipX, tipY)
  g.lineTo(rightX, rightY)
  g.closePath()
  g.fill({ color, alpha })
}

export function createConnectionLabel(conn) {
  if (!conn.label) return null
  const style = new TextStyle({
    fontFamily: 'Inter, sans-serif',
    fontSize: 11,
    fill: 0x666666,
    align: 'center',
  })
  return new Text({ text: conn.label, style, resolution: Math.max(window.devicePixelRatio || 1, 3) })
}
