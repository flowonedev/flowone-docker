import { screenToCanvas } from '../utils/coordTransform.js'
import { parseFromClipboard } from '../../utils/clipboardSerializer.js'

/**
 * Handles clipboard operations: Ctrl+C/V, system image paste, internal clipboard.
 * Supports cross-tab paste via system clipboard with serialized FlowOne data.
 */
export default class ClipboardHandler {
  constructor(store, container, callbacks) {
    this._store = store
    this._container = container
    this._cb = callbacks
    this._onPaste = this._onPaste.bind(this)
  }

  attach() {
    document.addEventListener('paste', this._onPaste)
  }

  detach() {
    document.removeEventListener('paste', this._onPaste)
  }

  async _onPaste(e) {
    const el = document.activeElement
    if (el?.tagName === 'INPUT' || el?.tagName === 'TEXTAREA' || el?.isContentEditable) return

    const clipItems = Array.from(e.clipboardData?.items || [])
    const images = clipItems.filter(i => i.type.startsWith('image/'))

    if (images.length > 0) {
      e.preventDefault()
      const files = images.map(i => i.getAsFile()).filter(Boolean)
      const center = this._getViewportCenter()

      if (files.length === 1) {
        this._cb.onImagePaste?.(files[0], center)
      } else {
        this._cb.onImageSetPaste?.(files, center)
      }
      return
    }

    // Cross-tab: check system clipboard for serialized FlowOne data.
    // Prefer text/html (JSON hidden in data attribute), fall back to text/plain.
    const html = e.clipboardData?.getData('text/html')
    const text = e.clipboardData?.getData('text/plain')
    const source = html || text
    if (source && parseFromClipboard(source)) {
      e.preventDefault()
      this._store.loadClipboardFromText(source)
      const center = this._getViewportCenter()
      this._cb.onInternalPaste?.(center)
      return
    }

    if (this._store.clipboard.length) {
      e.preventDefault()
      const center = this._getViewportCenter()
      this._cb.onInternalPaste?.(center)
    }
  }

  _getViewportCenter() {
    const rect = this._container.getBoundingClientRect()
    return screenToCanvas(
      rect.width / 2,
      rect.height / 2,
      this._store.panX,
      this._store.panY,
      this._store.zoom,
    )
  }
}
