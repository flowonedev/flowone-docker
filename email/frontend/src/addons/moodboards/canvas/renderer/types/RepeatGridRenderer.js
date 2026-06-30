import { Container, Graphics, Text, TextStyle } from 'pixi.js'
import {
  getFillStyles, getStrokeStyle, getCornerRadius,
  applyFills, applyStroke, applyTransform, drawRoundedRect,
  getStyleProps
} from '../../utils/styleToPixi.js'

/**
 * Renders repeat_grid items with tiled visual clones.
 * The first child of the repeat_grid acts as the template cell.
 * Additional clones are rendered based on grid columns/rows and gap settings.
 */
export function createRepeatGrid(item) {
  const container = new Container()
  container.label = `repeat-grid-${item.id}`
  drawRepeatGridBackground(container, item)
  applyTransform(container, item)
  return container
}

export function updateRepeatGrid(container, item) {
  const bg = container.children.find(c => c.label === 'rg-bg')
  const mask = container.mask
  const dashBorder = container.children.find(c => c.label === 'rg-dash')
  const cloneContainer = container.children.find(c => c.label === 'rg-clones')
  if (bg) { container.removeChild(bg); bg.destroy() }
  if (dashBorder) { container.removeChild(dashBorder); dashBorder.destroy() }
  if (cloneContainer) { container.removeChild(cloneContainer); cloneContainer.destroy({ children: true }) }
  if (mask) { container.mask = null; mask.destroy?.() }
  container.filters = null
  drawRepeatGridBackground(container, item)
  applyTransform(container, item)
}

function drawRepeatGridBackground(container, item) {
  const rawSd = item.style_data || {}
  const sd = getStyleProps('frame', rawSd)
  const w = item.width || 0
  const h = item.height || 0
  if (w <= 0 || h <= 0) return

  const fillStyles = getFillStyles(sd.fills)
  const strokeStyle = getStrokeStyle(sd.strokes)
  const radius = sd.cornerRadius ?? getCornerRadius(rawSd)

  if (fillStyles.length || strokeStyle) {
    const g = new Graphics()
    g.label = 'rg-bg'
    drawRoundedRect(g, 0, 0, w, h, radius)
    applyFills(g, fillStyles, w, h)
    if (strokeStyle) applyStroke(g, strokeStyle)
    container.addChildAt(g, 0)
  }

  const dashBorder = new Graphics()
  dashBorder.label = 'rg-dash'
  const dashLen = 6
  const gapLen = 4
  drawDashedRect(dashBorder, 0, 0, w, h, dashLen, gapLen, 0x8b5cf6, 0.4, 1)
  container.addChild(dashBorder)

  const clipMask = new Graphics()
  drawRoundedRect(clipMask, 0, 0, w, h, radius || 0)
  clipMask.fill({ color: 0xffffff })
  container.addChild(clipMask)
  container.mask = clipMask

  if (item.title) {
    const labelStyle = new TextStyle({
      fontFamily: 'Inter, sans-serif',
      fontSize: 11,
      fill: 0x8b5cf6,
      fontWeight: '600',
    })
    const label = new Text({ text: item.title || 'Repeat Grid', style: labelStyle, resolution: Math.max(window.devicePixelRatio || 1, 3) })
    label.label = 'rg-label'
    label.position.set(0, -16)
    container.addChild(label)
  }
}

/**
 * Compute repeat-grid clone positions.
 * Returns an array of { col, row, x, y } for each tile.
 */
export function computeRepeatGridLayout(parentItem, templateChild) {
  if (!templateChild) return []
  const sd = parentItem.style_data || {}
  const cols = sd.repeat_columns || sd.grid_columns || 1
  const rows = sd.repeat_rows || sd.grid_rows || 1
  const gapX = sd.repeat_gap_x ?? sd.grid_gap_x ?? sd.repeat_gap ?? 10
  const gapY = sd.repeat_gap_y ?? sd.grid_gap_y ?? sd.repeat_gap ?? 10
  const cellW = templateChild.width || 100
  const cellH = templateChild.height || 100

  const positions = []
  for (let r = 0; r < rows; r++) {
    for (let c = 0; c < cols; c++) {
      positions.push({
        col: c,
        row: r,
        x: c * (cellW + gapX),
        y: r * (cellH + gapY),
      })
    }
  }
  return positions
}

function drawDashedRect(g, x, y, w, h, dashLen, gapLen, color, alpha, lineWidth) {
  g.setStrokeStyle?.({ color, alpha, width: lineWidth })
  const edges = [
    [x, y, x + w, y],
    [x + w, y, x + w, y + h],
    [x + w, y + h, x, y + h],
    [x, y + h, x, y],
  ]
  for (const [x0, y0, x1, y1] of edges) {
    const dx = x1 - x0
    const dy = y1 - y0
    const len = Math.sqrt(dx * dx + dy * dy)
    const ux = dx / len
    const uy = dy / len
    let cursor = 0
    let draw = true
    while (cursor < len) {
      const segLen = draw ? dashLen : gapLen
      const end = Math.min(cursor + segLen, len)
      if (draw) {
        g.moveTo(x0 + ux * cursor, y0 + uy * cursor)
        g.lineTo(x0 + ux * end, y0 + uy * end)
        g.stroke({ color, alpha, width: lineWidth })
      }
      cursor = end
      draw = !draw
    }
  }
}
