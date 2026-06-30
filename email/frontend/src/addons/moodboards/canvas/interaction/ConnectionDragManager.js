import { screenToCanvas } from '../utils/coordTransform.js'

/**
 * Manages connection creation: click source item -> drag -> click target item.
 */
export default class ConnectionDragManager {
  constructor(store, spatialIndex, container) {
    this._store = store
    this._spatial = spatialIndex
    this._container = container
    this._active = false
    this._fromItemId = null
    this._currentEndpoint = null
  }

  get isActive() { return this._active }
  get fromItemId() { return this._fromItemId }
  get endpoint() { return this._currentEndpoint }

  startConnection(itemId) {
    this._active = true
    this._fromItemId = itemId
    this._store.connectingFrom = itemId
  }

  moveConnection(screenX, screenY) {
    if (!this._active) return
    this._currentEndpoint = this._toCanvas(screenX, screenY)
  }

  endConnection(screenX, screenY) {
    if (!this._active) return null
    const pos = this._toCanvas(screenX, screenY)
    const hits = this._spatial.queryPoint(pos.x, pos.y)
    const fromId = this._fromItemId
    const target = hits.find(h => h.id !== fromId)

    this.cancel()

    if (target) {
      return { fromId, toId: target.id }
    }
    return null
  }

  cancel() {
    this._active = false
    this._fromItemId = null
    this._currentEndpoint = null
    this._store.connectingFrom = null
  }

  /** Abandon any in-flight connection drag (component unmount). */
  destroy() {
    this.cancel()
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
