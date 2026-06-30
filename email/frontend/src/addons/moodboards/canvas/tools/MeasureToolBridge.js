/**
 * Bridge for the existing useCanvasMeasure composable.
 * The measure tool draws SVG overlays using store panX/panY/zoom.
 * This bridge exports helpers for coordinate alignment.
 */
export function getMeasureTransform(store) {
  return {
    panX: store.panX,
    panY: store.panY,
    zoom: store.zoom,
  }
}

export function canvasPointToMeasure(canvasX, canvasY) {
  return { x: canvasX, y: canvasY }
}

export function snapMeasureAngle(startX, startY, endX, endY) {
  const dx = endX - startX
  const dy = endY - startY
  const angle = Math.atan2(dy, dx)
  const SNAP_RAD = (5 * Math.PI) / 180
  const snapped = Math.round(angle / SNAP_RAD) * SNAP_RAD
  const dist = Math.hypot(dx, dy)
  return {
    x: startX + Math.cos(snapped) * dist,
    y: startY + Math.sin(snapped) * dist,
  }
}
