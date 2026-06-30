/**
 * Draws dashed / dotted strokes for PixiJS shapes.
 * PixiJS v8 has no native dash support, so we sample the shape
 * perimeter and draw individual line segments.
 */

export function drawDashedShape(g, shapeType, w, h, radius, sd, strokeStyle, dashPattern) {
  const points = samplePerimeter(shapeType, w, h, radius, sd)
  if (!points.length) return

  const sw = strokeStyle.width || 1
  const { dashLen, gapLen } = dashLengths(dashPattern, sw)

  drawDashedPath(g, points, dashLen, gapLen, true)

  g.stroke({
    color: strokeStyle.color,
    alpha: strokeStyle.alpha ?? 1,
    width: sw,
    cap: 'round',
    join: 'round',
  })
}

function dashLengths(pattern, sw) {
  if (pattern === 'dotted') {
    return { dashLen: Math.max(sw * 1.2, 2), gapLen: Math.max(sw * 2.5, 4) }
  }
  return { dashLen: Math.max(sw * 4, 8), gapLen: Math.max(sw * 3, 5) }
}

/**
 * Draw a dashed straight segment (used by LineRenderer).
 * Caller applies the stroke afterwards.
 */
export function drawDashedSegment(g, x1, y1, x2, y2, dashLen, gapLen) {
  drawDashedPath(g, [{ x: x1, y: y1 }, { x: x2, y: y2 }], dashLen, gapLen, false)
}

function drawDashedPath(g, points, dashLen, gapLen, closed) {
  let dist = 0
  let drawing = true
  let segLen = dashLen
  let started = false

  const total = closed ? points.length : points.length - 1

  for (let i = 0; i < total; i++) {
    const a = points[i]
    const b = points[(i + 1) % points.length]
    let dx = b.x - a.x
    let dy = b.y - a.y
    const edgeLen = Math.sqrt(dx * dx + dy * dy)
    if (edgeLen < 0.001) continue

    const nx = dx / edgeLen
    const ny = dy / edgeLen
    let consumed = 0

    while (consumed < edgeLen - 0.001) {
      const remaining = segLen - dist
      const available = edgeLen - consumed
      const step = Math.min(remaining, available)

      const x0 = a.x + nx * consumed
      const y0 = a.y + ny * consumed
      const x1 = a.x + nx * (consumed + step)
      const y1 = a.y + ny * (consumed + step)

      if (drawing) {
        if (!started || dist === 0) {
          g.moveTo(x0, y0)
          started = true
        }
        g.lineTo(x1, y1)
      }

      consumed += step
      dist += step

      if (dist >= segLen - 0.001) {
        drawing = !drawing
        segLen = drawing ? dashLen : gapLen
        dist = 0
      }
    }
  }
}

export function samplePerimeter(shapeType, w, h, radius, sd) {
  switch (shapeType) {
    case 'circle':
    case 'ellipse':
      return sampleEllipse(w / 2, h / 2, w / 2, h / 2)
    case 'triangle':
      return [{ x: w / 2, y: 0 }, { x: w, y: h }, { x: 0, y: h }]
    case 'star':
      return sampleStar(w, h, sd)
    case 'polygon':
      return samplePolygon(w, h, sd)
    default:
      return sampleRoundedRect(w, h, radius)
  }
}

function sampleEllipse(cx, cy, rx, ry) {
  const perimeter = estimateEllipsePerimeter(rx, ry)
  const n = Math.max(64, Math.ceil(perimeter / 2))
  const pts = []
  for (let i = 0; i < n; i++) {
    const t = (i / n) * Math.PI * 2
    pts.push({ x: cx + rx * Math.cos(t), y: cy + ry * Math.sin(t) })
  }
  return pts
}

function estimateEllipsePerimeter(a, b) {
  return Math.PI * (3 * (a + b) - Math.sqrt((3 * a + b) * (a + 3 * b)))
}

function sampleRoundedRect(w, h, radius) {
  const r = normalizeRadius(radius, w, h)
  const pts = []

  sampleArc(pts, w - r.tr, r.tr, r.tr, -Math.PI / 2, 0)
  sampleArc(pts, w - r.br, h - r.br, r.br, 0, Math.PI / 2)
  sampleArc(pts, r.bl, h - r.bl, r.bl, Math.PI / 2, Math.PI)
  sampleArc(pts, r.tl, r.tl, r.tl, Math.PI, Math.PI * 1.5)

  return pts
}

function normalizeRadius(radius, w, h) {
  const maxR = Math.min(w, h) / 2
  if (Array.isArray(radius)) {
    return {
      tl: Math.min(radius[0] || 0, maxR),
      tr: Math.min(radius[1] || 0, maxR),
      br: Math.min(radius[2] || 0, maxR),
      bl: Math.min(radius[3] || 0, maxR),
    }
  }
  const r = Math.min(radius || 0, maxR)
  return { tl: r, tr: r, br: r, bl: r }
}

function sampleArc(pts, cx, cy, r, startAngle, endAngle) {
  if (r < 0.5) {
    pts.push({ x: cx + r * Math.cos(startAngle), y: cy + r * Math.sin(startAngle) })
    return
  }
  const arcLen = r * Math.abs(endAngle - startAngle)
  const steps = Math.max(4, Math.ceil(arcLen / 2))
  for (let i = 0; i <= steps; i++) {
    const t = startAngle + (endAngle - startAngle) * (i / steps)
    pts.push({ x: cx + r * Math.cos(t), y: cy + r * Math.sin(t) })
  }
}

function sampleStar(w, h, sd) {
  const pointCount = sd?.star_points || 5
  const inner = sd?.star_inner_radius || 0.38
  const cx = w / 2, cy = h / 2
  const outerR = Math.min(w, h) / 2
  const innerR = outerR * inner
  const step = Math.PI / pointCount
  const pts = []
  for (let i = 0; i < pointCount * 2; i++) {
    const r = i % 2 === 0 ? outerR : innerR
    const angle = i * step - Math.PI / 2
    pts.push({ x: cx + r * Math.cos(angle), y: cy + r * Math.sin(angle) })
  }
  return pts
}

function samplePolygon(w, h, sd) {
  const sides = sd?.polygon_sides || 6
  const cx = w / 2, cy = h / 2
  const r = Math.min(w, h) / 2
  const pts = []
  const offset = -Math.PI / 2
  for (let i = 0; i < sides; i++) {
    const angle = offset + (2 * Math.PI * i) / sides
    pts.push({ x: cx + r * Math.cos(angle), y: cy + r * Math.sin(angle) })
  }
  return pts
}
