import RBush from 'rbush'

/**
 * R-tree spatial index for O(log n) viewport culling and hit testing.
 * Wraps rbush with item-aware insertion, removal, and query methods.
 */
export default class SpatialIndex {
  constructor() {
    this._tree = new RBush()
    this._entries = new Map()
  }

  _itemToEntry(item) {
    const x = item.pos_x || 0
    const y = item.pos_y || 0
    const sd = item.style_data || {}
    const intrinsicW = sd.original_width || 0
    const intrinsicH = sd.original_height || 0
    let w = item.width || intrinsicW || 0
    let h = item.height || 0
    if (!h && w > 0 && intrinsicW > 0 && intrinsicH > 0) {
      h = Math.round(w * (intrinsicH / intrinsicW))
    }
    if (!w && h > 0 && intrinsicW > 0 && intrinsicH > 0) {
      w = Math.round(h * (intrinsicW / intrinsicH))
    }
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
    return {
      minX: x,
      minY: y,
      maxX: x + w,
      maxY: y + h,
      id: item.id,
      z_index: item.z_index || 0,
      type: item.type,
      parent_id: item.parent_id || null,
      locked: !!(item.locked === true || item.locked === 1 || item.locked === '1'),
    }
  }

  buildFromItems(items) {
    this._tree.clear()
    this._entries.clear()
    const entries = []
    for (const item of items) {
      const entry = this._itemToEntry(item)
      this._entries.set(item.id, entry)
      entries.push(entry)
    }
    this._tree.load(entries)
  }

  buildFromEntries(entries) {
    this._tree.clear()
    this._entries.clear()
    const valid = []
    for (const entry of entries) {
      if (!Number.isFinite(entry.minX) || !Number.isFinite(entry.minY)
        || !Number.isFinite(entry.maxX) || !Number.isFinite(entry.maxY)
        || entry.maxX <= entry.minX || entry.maxY <= entry.minY) continue
      this._entries.set(entry.id, entry)
      valid.push(entry)
    }
    this._tree.load(valid)
  }

  insert(item) {
    this.remove(item.id)
    const entry = this._itemToEntry(item)
    if (!Number.isFinite(entry.minX) || !Number.isFinite(entry.minY)
      || !Number.isFinite(entry.maxX) || !Number.isFinite(entry.maxY)
      || entry.maxX <= entry.minX || entry.maxY <= entry.minY) return
    this._entries.set(item.id, entry)
    this._tree.insert(entry)
  }

  remove(itemId) {
    const existing = this._entries.get(itemId)
    if (existing) {
      this._tree.remove(existing)
      this._entries.delete(itemId)
    }
  }

  update(item) {
    this.remove(item.id)
    this.insert(item)
  }

  /**
   * Query items within a bounding box (viewport culling).
   * Returns array of entries sorted by z_index descending (top-most first).
   */
  queryRect(bounds) {
    const results = this._tree.search(bounds)
    return this._sortResults(results)
  }

  /**
   * Query items at a specific point (hit testing).
   * Returns entries sorted by z_index descending.
   */
  queryPoint(x, y) {
    return this.queryRect({
      minX: x,
      minY: y,
      maxX: x,
      maxY: y,
    })
  }

  /**
   * Get all items in the viewport with a padding buffer.
   */
  queryViewport(viewportBounds) {
    return this.queryRect(viewportBounds)
  }

  clear() {
    this._tree.clear()
    this._entries.clear()
  }

  get size() {
    return this._entries.size
  }

  _sortResults(results) {
    results.sort((a, b) => {
      const orderDelta = (b.draw_order || 0) - (a.draw_order || 0)
      if (orderDelta !== 0) return orderDelta
      return (b.z_index || 0) - (a.z_index || 0)
    })
    return results
  }
}
