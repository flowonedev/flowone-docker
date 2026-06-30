import { screenToCanvas } from '../utils/coordTransform.js'

/**
 * Line tool (L key): click-drag or click-click to create line items.
 */
export default class LineToolManager {
  constructor(store, container) {
    this._store = store
    this._container = container
    this._drawing = false
    this._start = null
    this._end = null
    this._placed = false
  }

  get isDrawing() { return this._drawing }
  get startPoint() { return this._start }
  get endPoint() { return this._end }

  startLine(screenX, screenY) {
    const pt = this._toCanvas(screenX, screenY)
    this._start = pt
    this._end = pt
    this._drawing = true
    this._placed = false
  }

  moveLine(screenX, screenY, shiftKey) {
    if (!this._drawing || !this._start) return
    const pt = this._toCanvas(screenX, screenY)

    if (shiftKey) {
      const dx = pt.x - this._start.x
      const dy = pt.y - this._start.y
      const angle = Math.atan2(dy, dx)
      const snapped = Math.round(angle / (Math.PI / 4)) * (Math.PI / 4)
      const dist = Math.hypot(dx, dy)
      pt.x = this._start.x + Math.cos(snapped) * dist
      pt.y = this._start.y + Math.sin(snapped) * dist
    }

    this._end = pt
  }

  endLine(screenX, screenY) {
    if (!this._drawing || !this._start || !this._end) {
      this.cancel()
      return null
    }

    const dx = this._end.x - this._start.x
    const dy = this._end.y - this._start.y
    if (Math.hypot(dx, dy) < 8 / this._store.zoom) {
      if (!this._placed) {
        this._placed = true
        return null
      }
    }

    return this._commitLine()
  }

  clickEnd(screenX, screenY, shiftKey) {
    if (!this._placed) return null
    this.moveLine(screenX, screenY, shiftKey)
    return this._commitLine()
  }

  _commitLine() {
    const s = this._start
    const e = this._end
    const x = Math.min(s.x, e.x)
    const y = Math.min(s.y, e.y)
    const w = Math.abs(e.x - s.x) || 1
    const h = Math.abs(e.y - s.y) || 1

    const result = {
      type: 'line',
      pos_x: x,
      pos_y: y,
      width: w,
      height: h,
      style_data: {
        line_x1: s.x - x,
        line_y1: s.y - y,
        line_x2: e.x - x,
        line_y2: e.y - y,
        line_color: '#333333',
        line_width: 2,
      },
    }

    this.cancel()
    return result
  }

  cancel() {
    this._drawing = false
    this._start = null
    this._end = null
    this._placed = false
  }

  _toCanvas(screenX, screenY) {
    const rect = this._container.getBoundingClientRect()
    return screenToCanvas(
      screenX - rect.left,
      screenY - rect.top,
      this._store.panX,
      this._store.panY,
      this._store.zoom,
    )
  }
}
