/**
 * Boolean shape operations (Union, Subtract, Intersect, Exclude, Flatten)
 *
 * Converts mood board shapes/pen_shapes into polygons, performs boolean ops
 * via polygon-clipping, and returns a new pen_shape item definition.
 */
import polygonClipping from 'polygon-clipping'

// ── Shape → Polygon conversion ──────────────────────────────────────────────

/**
 * Number of segments for circle/ellipse approximation
 */
const CIRCLE_SEGMENTS = 64

/**
 * Generate polygon points for a shape item in absolute canvas coordinates.
 * Returns an array of [x, y] pairs forming a closed polygon.
 */
export function shapeToPolygon(item) {
  const w = item.width || 100
  const h = item.height || 100
  const x = item.pos_x || 0
  const y = item.pos_y || 0
  const sd = item.style_data || {}
  const type = item.type === 'pen_shape' ? 'pen_shape' : (sd.shape_type || 'rectangle')

  let points

  switch (type) {
    case 'circle':
    case 'ellipse':
      points = ellipseToPolygon(w, h)
      break
    case 'triangle':
      points = [
        [w * 0.5, 0],
        [0, h],
        [w, h],
      ]
      break
    case 'star':
      points = starToPolygon(w, h)
      break
    case 'pen_shape':
      points = penShapeToPolygon(sd.pen_svg_path, w, h)
      break
    default: // rectangle
      {
        const r = Math.min(sd.radius_tl ?? sd.radius ?? 0, w / 2, h / 2)
        if (r > 0) {
          points = roundedRectToPolygon(w, h, r)
        } else {
          points = [
            [0, 0],
            [w, 0],
            [w, h],
            [0, h],
          ]
        }
      }
      break
  }

  // Apply rotation if any
  const rotation = item.rotation || 0
  if (rotation !== 0) {
    const cx = w / 2
    const cy = h / 2
    const rad = (rotation * Math.PI) / 180
    const cos = Math.cos(rad)
    const sin = Math.sin(rad)
    points = points.map(([px, py]) => {
      const dx = px - cx
      const dy = py - cy
      return [
        cx + dx * cos - dy * sin,
        cy + dx * sin + dy * cos,
      ]
    })
  }

  // Translate to absolute canvas position
  return points.map(([px, py]) => [px + x, py + y])
}

function ellipseToPolygon(w, h) {
  const cx = w / 2
  const cy = h / 2
  const rx = w / 2
  const ry = h / 2
  const pts = []
  for (let i = 0; i < CIRCLE_SEGMENTS; i++) {
    const angle = (2 * Math.PI * i) / CIRCLE_SEGMENTS
    pts.push([cx + rx * Math.cos(angle), cy + ry * Math.sin(angle)])
  }
  return pts
}

function starToPolygon(w, h) {
  // 5-point star matching CSS clip-path:
  // polygon(50% 0%, 61% 35%, 98% 35%, 68% 57%, 79% 91%, 50% 70%, 21% 91%, 32% 57%, 2% 35%, 39% 35%)
  const pcts = [
    [50, 0], [61, 35], [98, 35], [68, 57], [79, 91],
    [50, 70], [21, 91], [32, 57], [2, 35], [39, 35],
  ]
  return pcts.map(([px, py]) => [(px / 100) * w, (py / 100) * h])
}

function roundedRectToPolygon(w, h, r, segs = 8) {
  const pts = []
  // Top-right corner
  for (let i = 0; i <= segs; i++) {
    const a = -Math.PI / 2 + (Math.PI / 2) * (i / segs)
    pts.push([w - r + r * Math.cos(a), r + r * Math.sin(a)])
  }
  // Bottom-right corner
  for (let i = 0; i <= segs; i++) {
    const a = (Math.PI / 2) * (i / segs)
    pts.push([w - r + r * Math.cos(a), h - r + r * Math.sin(a)])
  }
  // Bottom-left corner
  for (let i = 0; i <= segs; i++) {
    const a = Math.PI / 2 + (Math.PI / 2) * (i / segs)
    pts.push([r + r * Math.cos(a), h - r + r * Math.sin(a)])
  }
  // Top-left corner
  for (let i = 0; i <= segs; i++) {
    const a = Math.PI + (Math.PI / 2) * (i / segs)
    pts.push([r + r * Math.cos(a), r + r * Math.sin(a)])
  }
  return pts
}

/**
 * Convert an SVG path (in 0-100 viewBox) to polygon points scaled to actual dimensions.
 * Handles M, L, Z commands. For curves (C, Q), approximates with line segments.
 */
function penShapeToPolygon(svgPath, w, h) {
  if (!svgPath) return [[0, 0], [w, 0], [w, h], [0, h]]

  const pts = []
  // Parse SVG path d attribute
  const commands = svgPath.match(/[MLCQZHVAmlcqzhva][^MLCQZHVAmlcqzhva]*/gi) || []
  let cx = 0, cy = 0

  for (const cmd of commands) {
    const type = cmd[0]
    const nums = (cmd.slice(1).match(/-?\d+\.?\d*/g) || []).map(Number)

    switch (type) {
      case 'M':
        cx = nums[0]; cy = nums[1]
        pts.push([cx, cy])
        break
      case 'L':
        cx = nums[0]; cy = nums[1]
        pts.push([cx, cy])
        break
      case 'H':
        cx = nums[0]
        pts.push([cx, cy])
        break
      case 'V':
        cy = nums[0]
        pts.push([cx, cy])
        break
      case 'C':
        // Cubic bezier: approximate with 10 segments
        for (let i = 0; i < nums.length; i += 6) {
          const x1 = nums[i], y1 = nums[i + 1]
          const x2 = nums[i + 2], y2 = nums[i + 3]
          const x3 = nums[i + 4], y3 = nums[i + 5]
          for (let t = 0.1; t <= 1; t += 0.1) {
            const mt = 1 - t
            const bx = mt * mt * mt * cx + 3 * mt * mt * t * x1 + 3 * mt * t * t * x2 + t * t * t * x3
            const by = mt * mt * mt * cy + 3 * mt * mt * t * y1 + 3 * mt * t * t * y2 + t * t * t * y3
            pts.push([bx, by])
          }
          cx = x3; cy = y3
        }
        break
      case 'Q':
        // Quadratic bezier: approximate with 10 segments
        for (let i = 0; i < nums.length; i += 4) {
          const x1 = nums[i], y1 = nums[i + 1]
          const x2 = nums[i + 2], y2 = nums[i + 3]
          for (let t = 0.1; t <= 1; t += 0.1) {
            const mt = 1 - t
            const bx = mt * mt * cx + 2 * mt * t * x1 + t * t * x2
            const by = mt * mt * cy + 2 * mt * t * y1 + t * t * y2
            pts.push([bx, by])
          }
          cx = x2; cy = y2
        }
        break
      case 'Z':
      case 'z':
        // Close path, nothing to add
        break
    }
  }

  // Scale from 0-100 viewBox to actual dimensions
  if (pts.length < 3) return [[0, 0], [w, 0], [w, h], [0, h]]
  return pts.map(([px, py]) => [(px / 100) * w, (py / 100) * h])
}

// ── Boolean operations ──────────────────────────────────────────────────────

/**
 * Convert polygon points to polygon-clipping format: [[[x,y], [x,y], ...]]
 * (MultiPolygon ring format)
 */
function toClipperPoly(points) {
  // polygon-clipping expects [Polygon, ...] where Polygon = [Ring, ...]
  // and Ring = [[x,y], [x,y], ...]
  // Ensure the ring is closed
  const ring = [...points]
  if (ring.length > 0) {
    const first = ring[0]
    const last = ring[ring.length - 1]
    if (first[0] !== last[0] || first[1] !== last[1]) {
      ring.push([...first])
    }
  }
  return [ring]
}

/**
 * Convert polygon-clipping result back to flat polygon points
 * and compute bounding box for the new pen_shape item.
 */
function resultToShape(result, sourceItems) {
  if (!result || result.length === 0) return null

  // Flatten MultiPolygon result — take all rings
  const allPoints = []
  for (const polygon of result) {
    for (const ring of polygon) {
      allPoints.push(...ring)
    }
  }

  if (allPoints.length < 3) return null

  // Compute bounding box
  let minX = Infinity, minY = Infinity, maxX = -Infinity, maxY = -Infinity
  for (const [x, y] of allPoints) {
    if (x < minX) minX = x
    if (y < minY) minY = y
    if (x > maxX) maxX = x
    if (y > maxY) maxY = y
  }

  const width = maxX - minX
  const height = maxY - minY

  if (width <= 0 || height <= 0) return null

  // Build SVG path(s) in 0-100 viewBox
  let svgPath = ''
  for (const polygon of result) {
    for (const ring of polygon) {
      if (ring.length < 3) continue
      const scaled = ring.map(([x, y]) => [
        ((x - minX) / width) * 100,
        ((y - minY) / height) * 100,
      ])
      svgPath += 'M' + scaled.map(([x, y]) => `${x.toFixed(2)},${y.toFixed(2)}`).join(' L') + ' Z '
    }
  }

  // Inherit visual properties from first source shape
  const first = sourceItems[0]
  const sd = first.style_data || {}

  return {
    type: 'pen_shape',
    pos_x: Math.round(minX),
    pos_y: Math.round(minY),
    width: Math.round(width),
    height: Math.round(height),
    style_data: {
      pen_svg_path: svgPath.trim(),
      shape_fill: sd.shape_fill || '#6366f1',
      shape_border_color: sd.shape_border_color || sd.stroke_color || '#4f46e5',
      shape_border_width: sd.shape_border_width ?? sd.stroke_width ?? 2,
      shape_opacity: sd.shape_opacity ?? 100,
      boolean_op: true, // Flag to indicate this was a boolean result
    },
  }
}

/**
 * Perform a boolean operation on selected shape items.
 *
 * @param {'union'|'subtract'|'intersect'|'exclude'|'flatten'} operation
 * @param {Array} items - Array of shape/pen_shape items (minimum 2)
 * @returns {Object|null} New pen_shape item definition, or null on failure
 */
export function performBooleanOp(operation, items) {
  if (!items || items.length < 2) return null

  // Convert all shapes to polygons
  const polygons = items.map(item => toClipperPoly(shapeToPolygon(item)))

  let result
  try {
    switch (operation) {
      case 'union':
        result = polygons[0]
        for (let i = 1; i < polygons.length; i++) {
          result = polygonClipping.union(result, polygons[i])
        }
        break

      case 'subtract':
        result = polygons[0]
        for (let i = 1; i < polygons.length; i++) {
          result = polygonClipping.difference(result, polygons[i])
        }
        break

      case 'intersect':
        result = polygons[0]
        for (let i = 1; i < polygons.length; i++) {
          result = polygonClipping.intersection(result, polygons[i])
        }
        break

      case 'exclude':
        result = polygons[0]
        for (let i = 1; i < polygons.length; i++) {
          result = polygonClipping.xor(result, polygons[i])
        }
        break

      case 'flatten':
        // Flatten = union all shapes into one
        result = polygons[0]
        for (let i = 1; i < polygons.length; i++) {
          result = polygonClipping.union(result, polygons[i])
        }
        break

      default:
        return null
    }
  } catch (e) {
    console.error('Boolean operation failed:', e)
    return null
  }

  return resultToShape(result, items)
}

/**
 * Check if an item is eligible for boolean operations (shape or pen_shape).
 */
export function isBooleanEligible(item) {
  return item && (item.type === 'shape' || item.type === 'pen_shape')
}

