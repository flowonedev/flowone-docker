import { Container, Graphics } from 'pixi.js'
import { parseColor, applyTransform } from '../../utils/styleToPixi.js'
import { drawDashedSegment } from '../../utils/dashedStroke.js'

/**
 * Renders standalone line items (not connection lines).
 */
export function createLine(item) {
  const container = new Container()
  container.label = `line-${item.id}`
  drawLine(container, item)
  applyTransform(container, item)
  return container
}

export function updateLine(container, item) {
  container.removeChildren()
  drawLine(container, item)
  applyTransform(container, item)
}

function drawLine(container, item) {
  const sd = item.style_data || {}
  const w = item.width || 100
  const h = item.height || 20
  const x1 = sd.line_x1 ?? 10
  const y1 = sd.line_y1 ?? (h / 2)
  const x2 = sd.line_x2 ?? (w - 10)
  const y2 = sd.line_y2 ?? (h / 2)
  const lineColor = sd.line_color || sd.stroke_color || '#333333'
  const lineWidth = sd.line_width || sd.stroke_width || 2
  const dash = sd.line_dash || sd.stroke_dash_array || 'solid'
  const dashGap = sd.line_dash_gap || 0
  const arrowStart = sd.line_arrow_start || sd.arrow_start || 'none'
  const arrowEnd = sd.line_arrow_end || sd.arrow_end || 'none'

  // Same dash math as the DOM renderer (lineStyle in MoodCanvasItem.vue)
  let dashLen = 0
  let gapLen = 0
  if (dash === 'dashed') {
    dashLen = dashGap > 0 ? dashGap * 2 : lineWidth * 4
    gapLen = dashGap > 0 ? dashGap : lineWidth * 2
  } else if (dash === 'dotted') {
    dashLen = lineWidth
    gapLen = dashGap > 0 ? dashGap : lineWidth * 2
  }

  const { color, alpha } = parseColor(lineColor)
  const g = new Graphics()

  const drawPath = (target) => {
    if (dashLen > 0) {
      drawDashedSegment(target, x1, y1, x2, y2, dashLen, gapLen)
    } else {
      target.moveTo(x1, y1).lineTo(x2, y2)
    }
  }

  if (sd.line_glow || sd.line_glow_enabled) {
    const glowColor = parseColor(sd.line_glow_color || lineColor)
    const glowAlpha = sd.line_glow_opacity != null ? (sd.line_glow_opacity / 100) * 0.4 : 0.2
    const glowBlur = sd.line_glow_blur ?? 6
    const glow = new Graphics()
    drawPath(glow)
    glow.stroke({ color: glowColor.color, alpha: glowAlpha, width: lineWidth + glowBlur, cap: 'round' })
    container.addChild(glow)
  }

  drawPath(g)
  g.stroke({ color, alpha, width: lineWidth, cap: dash === 'dotted' ? 'round' : 'butt' })
  container.addChild(g)

  if (arrowEnd !== 'none') {
    drawArrow(container, x1, y1, x2, y2, color, alpha, lineWidth)
  }
  if (arrowStart !== 'none') {
    drawArrow(container, x2, y2, x1, y1, color, alpha, lineWidth)
  }
}

function drawArrow(container, fromX, fromY, toX, toY, color, alpha, lineWidth) {
  const angle = Math.atan2(toY - fromY, toX - fromX)
  const size = lineWidth * 4
  const g = new Graphics()
  g.moveTo(toX, toY)
  g.lineTo(
    toX - size * Math.cos(angle - Math.PI / 6),
    toY - size * Math.sin(angle - Math.PI / 6),
  )
  g.lineTo(
    toX - size * Math.cos(angle + Math.PI / 6),
    toY - size * Math.sin(angle + Math.PI / 6),
  )
  g.closePath()
  g.fill({ color, alpha })
  container.addChild(g)
}
