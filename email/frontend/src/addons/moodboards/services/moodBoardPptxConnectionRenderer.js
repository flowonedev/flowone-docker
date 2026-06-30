import { parseSd, resolveItemDimensions, hexClean, escapeXml, rasterizeSvg } from './moodBoardPptxUtils'

function getItemRectForExport(item) {
  const sd = parseSd(item)
  const scaleVal = (sd.item_scale != null && sd.item_scale !== 1) ? sd.item_scale : 1
  const dims = resolveItemDimensions(item)
  const rawW = dims.w
  const rawH = dims.h
  const w = rawW * scaleVal
  const h = rawH * scaleVal
  const x = (Number(item.pos_x) || 0) + rawW * (1 - scaleVal) / 2
  const y = (Number(item.pos_y) || 0) + rawH * (1 - scaleVal) / 2
  return { x, y, w, h, cx: x + w / 2, cy: y + h / 2 }
}

function getEdgePointForExport(rect, targetX, targetY) {
  const { x, y, w, h, cx, cy } = rect
  const dx = targetX - cx
  const dy = targetY - cy
  if (Math.abs(dx) < 0.01 && Math.abs(dy) < 0.01) return { x: cx, y }
  const halfW = w / 2
  const halfH = h / 2
  if (Math.abs(dx) * halfH > Math.abs(dy) * halfW) {
    return { x: cx + halfW * Math.sign(dx), y: cy + dy * (halfW / Math.abs(dx)) }
  }
  return { x: cx + dx * (halfH / Math.abs(dy)), y: cy + halfH * Math.sign(dy) }
}

function resolveConnEndpointForExport(item, anchorX, anchorY, fallbackX, fallbackY) {
  const r = getItemRectForExport(item)
  if (anchorX != null && anchorY != null) {
    return { x: r.x + anchorX * r.w, y: r.y + anchorY * r.h }
  }
  return getEdgePointForExport(r, fallbackX, fallbackY)
}

function buildConnectionSvgPath(conn, fromItem, toItem) {
  const fromRect = getItemRectForExport(fromItem)
  const toRect = getItemRectForExport(toItem)
  const from = resolveConnEndpointForExport(fromItem, conn.from_anchor_x, conn.from_anchor_y, toRect.cx, toRect.cy)
  const to = resolveConnEndpointForExport(toItem, conn.to_anchor_x, conn.to_anchor_y, fromRect.cx, fromRect.cy)

  const x1 = from.x, y1 = from.y, x2 = to.x, y2 = to.y
  const hasBend1 = conn.bend_x != null && conn.bend_y != null
  const hasBend2 = conn.bend2_x != null && conn.bend2_y != null

  let cp1x, cp1y, cp2x, cp2y
  if (hasBend1 || hasBend2) {
    cp1x = hasBend1 ? conn.bend_x : (x1 * 2 / 3 + x2 / 3)
    cp1y = hasBend1 ? conn.bend_y : (y1 * 2 / 3 + y2 / 3)
    cp2x = hasBend2 ? conn.bend2_x : (x1 / 3 + x2 * 2 / 3)
    cp2y = hasBend2 ? conn.bend2_y : (y1 / 3 + y2 * 2 / 3)
  } else {
    const dx = x2 - x1, dy = y2 - y1
    const dist = Math.sqrt(dx * dx + dy * dy)
    const curvature = Math.min(dist * 0.25, 80)
    if (Math.abs(dx) >= Math.abs(dy)) {
      cp1x = x1 + curvature * Math.sign(dx || 1); cp1y = y1
      cp2x = x2 - curvature * Math.sign(dx || 1); cp2y = y2
    } else {
      cp1x = x1; cp1y = y1 + curvature * Math.sign(dy || 1)
      cp2x = x2; cp2y = y2 - curvature * Math.sign(dy || 1)
    }
  }

  return { x1, y1, x2, y2, cp1x, cp1y, cp2x, cp2y }
}

export async function renderConnectionsOverlay(slide, connections, page, sf, accentColor, allItems) {
  if (!connections?.length) return

  const itemMap = new Map()
  for (const item of allItems) itemMap.set(item.id, item)

  const validConns = connections.filter(c => itemMap.has(c.from_item_id) && itemMap.has(c.to_item_id))
  if (!validConns.length) return

  const { originX, originY, canvasW, canvasH } = page
  const lx = (v) => v - originX
  const ly = (v) => v - originY

  let defs = ''
  let pathsStr = ''

  for (const conn of validConns) {
    const fromItem = itemMap.get(conn.from_item_id)
    const toItem = itemMap.get(conn.to_item_id)
    const pts = buildConnectionSvgPath(conn, fromItem, toItem)

    const x1 = lx(pts.x1), y1 = ly(pts.y1), x2 = lx(pts.x2), y2 = ly(pts.y2)
    const cp1x = lx(pts.cp1x), cp1y = ly(pts.cp1y), cp2x = lx(pts.cp2x), cp2y = ly(pts.cp2y)

    const rawColor = conn.line_color
    const baseColor = (!rawColor || rawColor === 'accent') ? accentColor : rawColor
    const color = '#' + hexClean(baseColor)
    const lineWidth = conn.line_width || 2
    const cid = conn.id || Math.random().toString(36).slice(2)

    let dashAttr = ''
    if (conn.line_style === 'dashed') dashAttr = ` stroke-dasharray="${lineWidth * 4},${lineWidth * 2}"`
    else if (conn.line_style === 'dotted') dashAttr = ` stroke-dasharray="${lineWidth},${lineWidth * 2}"`

    if (conn.arrow_end) {
      const mw = 10 + lineWidth, mh = 6 + lineWidth
      defs += `<marker id="ae-${cid}" markerWidth="${mw}" markerHeight="${mh}" refX="${6 + lineWidth * 0.35}" refY="${mh / 2}" orient="auto" markerUnits="userSpaceOnUse"><polygon points="0 0, ${mw} ${mh / 2}, 0 ${mh}" fill="${color}" /></marker>`
    }
    if (conn.arrow_start) {
      const mw = 10 + lineWidth, mh = 6 + lineWidth
      defs += `<marker id="as-${cid}" markerWidth="${mw}" markerHeight="${mh}" refX="${4 + lineWidth * 0.35}" refY="${mh / 2}" orient="auto-start-reverse" markerUnits="userSpaceOnUse"><polygon points="${mw} 0, 0 ${mh / 2}, ${mw} ${mh}" fill="${color}" /></marker>`
    }

    let strokeVal = color
    if (conn.gradient_enabled) {
      const gs = '#' + hexClean(conn.gradient_color_start || baseColor)
      const ge = '#' + hexClean(conn.gradient_color_end || '#8b5cf6')
      defs += `<linearGradient id="g-${cid}" gradientUnits="userSpaceOnUse" x1="${x1}" y1="${y1}" x2="${x2}" y2="${y2}"><stop offset="0%" stop-color="${gs}" /><stop offset="100%" stop-color="${ge}" /></linearGradient>`
      strokeVal = `url(#g-${cid})`
    }

    const pathD = `M ${x1} ${y1} C ${cp1x} ${cp1y}, ${cp2x} ${cp2y}, ${x2} ${y2}`
    pathsStr += `<path d="${pathD}" stroke="${strokeVal}" stroke-width="${lineWidth}" fill="none" stroke-linecap="round"${dashAttr}${conn.arrow_end ? ` marker-end="url(#ae-${cid})"` : ''}${conn.arrow_start ? ` marker-start="url(#as-${cid})"` : ''} />`

    if (conn.label) {
      const t = 0.5
      const mx = (1 - t) ** 3 * x1 + 3 * (1 - t) ** 2 * t * cp1x + 3 * (1 - t) * t ** 2 * cp2x + t ** 3 * x2
      const my = (1 - t) ** 3 * y1 + 3 * (1 - t) ** 2 * t * cp1y + 3 * (1 - t) * t ** 2 * cp2y + t ** 3 * y2
      pathsStr += `<rect x="${mx - 50}" y="${my - 10}" width="100" height="20" rx="10" fill="${color}" />`
      pathsStr += `<text x="${mx}" y="${my}" fill="white" font-size="10" font-weight="600" font-family="Arial,sans-serif" text-anchor="middle" dominant-baseline="middle">${escapeXml(conn.label)}</text>`
    }
  }

  if (!pathsStr) return

  const svg = `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ${canvasW} ${canvasH}" width="${canvasW}" height="${canvasH}"><defs>${defs}</defs>${pathsStr}</svg>`

  const MAX_DIM = 1920
  const aspect = canvasW / canvasH
  const rW = aspect >= 1 ? MAX_DIM : Math.round(MAX_DIM * aspect)
  const rH = aspect >= 1 ? Math.round(MAX_DIM / aspect) : MAX_DIM

  try {
    const data = await rasterizeSvg(svg, rW, rH)
    if (data) {
      const contentW = canvasW * sf.s
      const contentH = canvasH * sf.s
      slide.addImage({ data, x: sf.ox, y: sf.oy, w: contentW, h: contentH })
    }
  } catch { /* connection overlay failed silently */ }
}
