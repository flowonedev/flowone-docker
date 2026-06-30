/**
 * Manages CSS cursor styles on the canvas container element.
 */
export default class CursorManager {
  constructor(container) {
    this._container = container
    this._stack = []
    this._default = 'default'
  }

  set(cursor) {
    this._container.style.cursor = cursor
  }

  push(cursor) {
    this._stack.push(cursor)
    this.set(cursor)
  }

  pop() {
    this._stack.pop()
    this.set(this._stack.length ? this._stack[this._stack.length - 1] : this._default)
  }

  reset() {
    this._stack = []
    this.set(this._default)
  }

  getCursorForState(state) {
    if (state.isPanning || state.spaceHeld) return state.isPanning ? 'grabbing' : 'grab'
    if (state.connectionMode) return 'crosshair'
    if (state.lineMode) return 'crosshair'
    if (state.measureMode) return 'crosshair'
    if (state.penMode) return 'crosshair'
    if (state.resizeHandle) return this._getResizeCursor(state.resizeHandle, state.rotation || 0)
    if (state.rotating) return 'alias'
    if (state.overItem && !state.itemLocked) return 'move'
    return 'default'
  }

  _getResizeCursor(handle, rotationDeg) {
    const baseAngles = {
      'n': 0, 'ne': 45, 'e': 90, 'se': 135,
      's': 180, 'sw': 225, 'w': 270, 'nw': 315,
    }
    const base = baseAngles[handle] || 0
    const adjusted = ((base + rotationDeg) % 360 + 360) % 360
    const segment = Math.round(adjusted / 45) % 4
    const cursors = ['ns-resize', 'nesw-resize', 'ew-resize', 'nwse-resize']
    return cursors[segment]
  }
}
