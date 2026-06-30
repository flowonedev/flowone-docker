/**
 * Coordinate transformation utilities for PixiJS canvas.
 * Converts between screen (pixel) coordinates and canvas (world) coordinates.
 */

export function screenToCanvas(screenX, screenY, panX, panY, zoom) {
  return {
    x: (screenX - panX) / zoom,
    y: (screenY - panY) / zoom,
  }
}

export function canvasToScreen(canvasX, canvasY, panX, panY, zoom) {
  return {
    x: canvasX * zoom + panX,
    y: canvasY * zoom + panY,
  }
}

export function canvasBoundsToScreen(item, panX, panY, zoom) {
  const x = item.pos_x * zoom + panX
  const y = item.pos_y * zoom + panY
  const w = (item.width || 0) * zoom
  const h = (item.height || 0) * zoom
  return { x, y, width: w, height: h }
}

export function screenRectToCanvas(rect, panX, panY, zoom) {
  const tl = screenToCanvas(rect.x, rect.y, panX, panY, zoom)
  const br = screenToCanvas(rect.x + rect.width, rect.y + rect.height, panX, panY, zoom)
  return {
    x: tl.x,
    y: tl.y,
    width: br.x - tl.x,
    height: br.y - tl.y,
  }
}

export function getViewportBounds(containerWidth, containerHeight, panX, panY, zoom, padding = 0) {
  const tl = screenToCanvas(-padding, -padding, panX, panY, zoom)
  const br = screenToCanvas(containerWidth + padding, containerHeight + padding, panX, panY, zoom)
  return {
    minX: tl.x,
    minY: tl.y,
    maxX: br.x,
    maxY: br.y,
  }
}
