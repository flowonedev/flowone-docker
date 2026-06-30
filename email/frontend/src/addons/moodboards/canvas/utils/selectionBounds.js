function resolveItemSize(item) {
  const sd = item.style_data || {}
  const iW = sd.original_width || 0
  const iH = sd.original_height || 0
  let w = item.width || iW || 0
  let h = item.height || 0

  if (!h && w > 0 && iW > 0 && iH > 0) h = Math.round(w * (iH / iW))
  if (!w && h > 0 && iW > 0 && iH > 0) w = Math.round(h * (iW / iH))

  if (item.type === 'text' && w > 0 && h <= 0) {
    const fontSize = sd.font_size || sd.fontSize || 16
    const lineHeight = sd.line_height || 1.4
    const content = item.content || ''
    const lines = Math.max(1, (content.match(/<br|<\/p>|<\/div>|\n/gi) || []).length + 1)
    h = Math.max(40, Math.ceil(fontSize * lineHeight * lines + 16))
  }

  const isImage = item.type === 'image' || item.type === 'image_set'
  if (isImage && w > 0 && h < 40) h = 40
  if (isImage && h > 0 && w < 40) w = 40

  if (w <= 0) w = 200
  if (h <= 0) h = 200
  return { w, h }
}

/**
 * Computes a bounding rectangle encompassing all selected items.
 */
export function computeSelectionBounds(selectedIds, items, containerTypes) {
  if (!selectedIds.size) return null
  let minX = Infinity, minY = Infinity, maxX = -Infinity, maxY = -Infinity

  for (const item of items) {
    if (!selectedIds.has(item.id)) continue
    const x = item.pos_x || 0
    const y = item.pos_y || 0
    const { w, h } = resolveItemSize(item)

    if ((w <= 0 || h <= 0) && containerTypes.has(item.type)) {
      const children = items.filter(c => c.parent_id === item.id)
      if (children.length) {
        let cMinX = Infinity, cMinY = Infinity, cMaxX = -Infinity, cMaxY = -Infinity
        for (const c of children) {
          const cs = resolveItemSize(c)
          const cx = c.pos_x || 0
          const cy = c.pos_y || 0
          if (cx < cMinX) cMinX = cx
          if (cy < cMinY) cMinY = cy
          if (cx + cs.w > cMaxX) cMaxX = cx + cs.w
          if (cy + cs.h > cMaxY) cMaxY = cy + cs.h
        }
        if (cMinX !== Infinity) {
          if (cMinX < minX) minX = cMinX
          if (cMinY < minY) minY = cMinY
          if (cMaxX > maxX) maxX = cMaxX
          if (cMaxY > maxY) maxY = cMaxY
          continue
        }
      }
    }

    if (x < minX) minX = x
    if (y < minY) minY = y
    if (x + w > maxX) maxX = x + w
    if (y + h > maxY) maxY = y + h
  }

  if (minX === Infinity) return null
  return { x: minX, y: minY, width: maxX - minX, height: maxY - minY }
}

/**
 * Computes individual bounding rects for each selected item.
 */
export function computePerItemBounds(selectedIds, items) {
  if (!selectedIds.size) return []
  const result = []
  for (const item of items) {
    if (!selectedIds.has(item.id)) continue
    const { w, h } = resolveItemSize(item)
    result.push({
      id: item.id,
      x: item.pos_x || 0,
      y: item.pos_y || 0,
      width: w,
      height: h,
      rotation: item.rotation || 0,
    })
  }
  return result
}
