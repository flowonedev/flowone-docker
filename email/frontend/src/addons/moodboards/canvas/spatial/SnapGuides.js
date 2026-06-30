/**
 * Smart guide computation for drag alignment.
 * Replicates the snap guide logic from MoodCanvas.vue _buildDragState.
 */

const SNAP_THRESHOLD = 6

export function buildSnapTargets(items, draggedIds, guides = []) {
  const targets = { xs: [], ys: [] }
  const dragSet = new Set(draggedIds)

  for (const item of items) {
    if (dragSet.has(item.id)) continue
    if (item.parent_id) continue

    const x = item.pos_x || 0
    const y = item.pos_y || 0
    const w = item.width || 0
    const h = item.height || 0
    const cx = x + w / 2
    const cy = y + h / 2

    targets.xs.push(
      { value: x, label: 'left', itemId: item.id },
      { value: cx, label: 'center', itemId: item.id },
      { value: x + w, label: 'right', itemId: item.id },
    )
    targets.ys.push(
      { value: y, label: 'top', itemId: item.id },
      { value: cy, label: 'center', itemId: item.id },
      { value: y + h, label: 'bottom', itemId: item.id },
    )
  }

  for (const guide of guides) {
    if (guide.axis === 'x') {
      targets.xs.push({ value: guide.position, label: 'guide', itemId: null })
    } else {
      targets.ys.push({ value: guide.position, label: 'guide', itemId: null })
    }
  }

  return targets
}

export function computeSnap(dragBounds, targets, zoom) {
  const threshold = SNAP_THRESHOLD / zoom
  const guides = []
  let snapDx = 0
  let snapDy = 0

  const dragXs = [
    { value: dragBounds.x, label: 'left' },
    { value: dragBounds.x + dragBounds.width / 2, label: 'center' },
    { value: dragBounds.x + dragBounds.width, label: 'right' },
  ]
  const dragYs = [
    { value: dragBounds.y, label: 'top' },
    { value: dragBounds.y + dragBounds.height / 2, label: 'center' },
    { value: dragBounds.y + dragBounds.height, label: 'bottom' },
  ]

  let bestXDist = threshold
  let bestYDist = threshold

  for (const dx of dragXs) {
    for (const tx of targets.xs) {
      const dist = Math.abs(dx.value - tx.value)
      if (dist < bestXDist) {
        bestXDist = dist
        snapDx = tx.value - dx.value
        guides.push({ axis: 'x', value: tx.value, from: dx.label, to: tx.label })
      }
    }
  }

  for (const dy of dragYs) {
    for (const ty of targets.ys) {
      const dist = Math.abs(dy.value - ty.value)
      if (dist < bestYDist) {
        bestYDist = dist
        snapDy = ty.value - dy.value
        guides.push({ axis: 'y', value: ty.value, from: dy.label, to: ty.label })
      }
    }
  }

  return { snapDx, snapDy, guides }
}

export function snapToGrid(value, gridSize = 10) {
  return Math.round(value / gridSize) * gridSize
}
