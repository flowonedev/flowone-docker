/**
 * Computes Figma-style distance measurement overlays between the dragged
 * bounding box and the nearest non-dragged items on each side.
 *
 * For each direction it finds the closest edge from any other item that has
 * perpendicular overlap with the dragged bbox.  This includes edges of items
 * that *contain* the dragged element (e.g. a background shape whose bottom
 * edge is below the dragged item) — not just fully-separated neighbors.
 *
 * Returns an array of measurement objects ready for SVG rendering.
 * All coordinates are in *screen* space (already multiplied by zoom + pan).
 */

/**
 * @param {Object} dragBBox       - { minX, minY, maxX, maxY } in canvas coords
 * @param {Array}  otherItems     - non-dragged items with pos_x, pos_y, width, height
 * @param {number} zoom
 * @param {number} panX
 * @param {number} panY
 * @returns {Array<{ x1, y1, x2, y2, label, axis }>}
 */
export function computeDistanceGuides(dragBBox, otherItems, zoom, panX, panY) {
  const { minX, minY, maxX, maxY } = dragBBox
  const dCX = (minX + maxX) / 2
  const dCY = (minY + maxY) / 2
  const measurements = []

  let bestLeft = Infinity, bestRight = Infinity
  let bestTop = Infinity, bestBottom = Infinity
  let leftEdge = null, rightEdge = null
  let topEdge = null, bottomEdge = null

  for (const item of otherItems) {
    const ix = item.pos_x
    const iy = item.pos_y
    const iw = item.width || 240
    const ih = item.height || 120
    const ir = ix + iw
    const ib = iy + ih

    // Perpendicular overlap checks (at least 1px shared on the other axis)
    const hOverlap = ir > minX && ix < maxX
    const vOverlap = ib > minY && iy < maxY

    // ── LEFT: nearest edge to the left of dragMinX (needs vertical overlap) ──
    if (vOverlap) {
      // Separated item right edge
      if (ir <= minX) {
        const d = minX - ir
        if (d < bestLeft) { bestLeft = d; leftEdge = ir }
      }
      // Containing/overlapping item left edge
      if (ix < minX) {
        const d = minX - ix
        if (d < bestLeft) { bestLeft = d; leftEdge = ix }
      }

      // ── RIGHT: nearest edge to the right of dragMaxX ──
      if (ix >= maxX) {
        const d = ix - maxX
        if (d < bestRight) { bestRight = d; rightEdge = ix }
      }
      if (ir > maxX) {
        const d = ir - maxX
        if (d < bestRight) { bestRight = d; rightEdge = ir }
      }
    }

    // ── TOP: nearest edge above dragMinY (needs horizontal overlap) ──
    if (hOverlap) {
      if (ib <= minY) {
        const d = minY - ib
        if (d < bestTop) { bestTop = d; topEdge = ib }
      }
      if (iy < minY) {
        const d = minY - iy
        if (d < bestTop) { bestTop = d; topEdge = iy }
      }

      // ── BOTTOM: nearest edge below dragMaxY ──
      if (iy >= maxY) {
        const d = iy - maxY
        if (d < bestBottom) { bestBottom = d; bottomEdge = iy }
      }
      if (ib > maxY) {
        const d = ib - maxY
        if (d < bestBottom) { bestBottom = d; bottomEdge = ib }
      }
    }
  }

  const toScreen = (cx, cy) => ({
    sx: cx * zoom + panX,
    sy: cy * zoom + panY,
  })

  const MAX_DIST = 5000

  if (leftEdge != null && bestLeft > 0 && bestLeft < MAX_DIST) {
    const from = toScreen(leftEdge, dCY)
    const to = toScreen(minX, dCY)
    measurements.push({
      x1: from.sx, y1: from.sy,
      x2: to.sx, y2: to.sy,
      label: Math.round(bestLeft),
      axis: 'x',
    })
  }

  if (rightEdge != null && bestRight > 0 && bestRight < MAX_DIST) {
    const from = toScreen(maxX, dCY)
    const to = toScreen(rightEdge, dCY)
    measurements.push({
      x1: from.sx, y1: from.sy,
      x2: to.sx, y2: to.sy,
      label: Math.round(bestRight),
      axis: 'x',
    })
  }

  if (topEdge != null && bestTop > 0 && bestTop < MAX_DIST) {
    const from = toScreen(dCX, topEdge)
    const to = toScreen(dCX, minY)
    measurements.push({
      x1: from.sx, y1: from.sy,
      x2: to.sx, y2: to.sy,
      label: Math.round(bestTop),
      axis: 'y',
    })
  }

  if (bottomEdge != null && bestBottom > 0 && bestBottom < MAX_DIST) {
    const from = toScreen(dCX, maxY)
    const to = toScreen(dCX, bottomEdge)
    measurements.push({
      x1: from.sx, y1: from.sy,
      x2: to.sx, y2: to.sy,
      label: Math.round(bestBottom),
      axis: 'y',
    })
  }

  return measurements
}
