/**
 * Handles item-level drop interactions:
 * - Drop image on shape (mask)
 * - Drop image on text (clip)
 * - Drop files to image_set
 * - Column drop highlight
 */
export default class ItemDropHandler {
  constructor(store) {
    this._store = store
  }

  handleImageOnShape(imageUrl, shapeItem) {
    if (!shapeItem || shapeItem.type !== 'shape') return false
    const sd = { ...shapeItem.style_data, mask_image_url: imageUrl, mask_image_fit: 'cover' }
    this._store.batchUpdateItems([{ id: shapeItem.id, style_data: sd }])
    return true
  }

  handleImageOnText(imageUrl, textItem) {
    if (!textItem || textItem.type !== 'text') return false
    const sd = { ...textItem.style_data, text_clip_image: imageUrl }
    this._store.batchUpdateItems([{ id: textItem.id, style_data: sd }])
    return true
  }

  handleFilesToImageSet(files, imageSetItem) {
    if (!imageSetItem || imageSetItem.type !== 'image_set') return false
    return true
  }

  findColumnAtPoint(x, y, items) {
    for (const item of items) {
      if (item.type !== 'column') continue
      const ix = item.pos_x || 0
      const iy = item.pos_y || 0
      const iw = item.width || 0
      const ih = item.height || 0
      if (x >= ix && x <= ix + iw && y >= iy && y <= iy + ih) {
        return item
      }
    }
    return null
  }
}
