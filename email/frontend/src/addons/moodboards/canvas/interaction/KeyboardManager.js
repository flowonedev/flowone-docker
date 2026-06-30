/**
 * Dispatches all keyboard shortcuts for the PixiJS canvas.
 * Mirrors the 25+ shortcuts from MoodCanvas.vue onKeyDown/onKeyUp.
 */
export default class KeyboardManager {
  constructor(ctx) {
    this._ctx = ctx
    this._onKeyDown = this._onKeyDown.bind(this)
    this._onKeyUp = this._onKeyUp.bind(this)
  }

  attach() {
    document.addEventListener('keydown', this._onKeyDown)
    document.addEventListener('keyup', this._onKeyUp)
  }

  detach() {
    document.removeEventListener('keydown', this._onKeyDown)
    document.removeEventListener('keyup', this._onKeyUp)
  }

  _isInputFocused() {
    const el = document.activeElement
    if (!el) return false
    const tag = el.tagName
    if (tag === 'INPUT' || tag === 'TEXTAREA') return true
    if (el.isContentEditable) return true
    return false
  }

  _onKeyUp(e) {
    if (e.code === 'Space') {
      this._ctx.panZoom.setSpaceHeld(false)
    }
  }

  _onKeyDown(e) {
    const { store, panZoom, selection, drag, rotation, emit, readonly } = this._ctx
    const isInput = this._isInputFocused()
    const ctrl = e.ctrlKey || e.metaKey
    const shift = e.shiftKey
    const hasSelection = store.selectedItemIds.size > 0

    if (e.code === 'Space' && !isInput) {
      e.preventDefault()
      panZoom.setSpaceHeld(true)
      return
    }

    if (e.code === 'Escape') {
      if (this._ctx.connectionDrag?.isActive) {
        this._ctx.connectionDrag.cancel()
        if (this._ctx.connDragActive) this._ctx.connDragActive.value = false
        if (this._ctx.connDragEndpoint) this._ctx.connDragEndpoint.value = null
        return
      }
      if (this._ctx.drawMode?.value) { this._ctx.drawMode.value = false; return }
      if (this._ctx.penMode?.value) { this._ctx.penMode.value = false; return }
      if (this._ctx.lineMode?.value) { this._ctx.lineMode.value = false; return }
      if (this._ctx.measureMode?.value) { this._ctx.measureMode.value = false; return }
      if (store.editingGroupId) { selection.exitGroup(); return }
      if (hasSelection) { selection.clearSelection(); return }
      return
    }

    // Ctrl+Z / Ctrl+Y must work even when a sidebar control has focus
    if (ctrl && e.code === 'KeyZ') {
      e.preventDefault()
      if (shift) store.redo?.()
      else store.undo?.()
      return
    }
    if (ctrl && e.code === 'KeyY') {
      e.preventDefault()
      store.redo?.()
      return
    }

    if (isInput) return
    if (readonly?.value) return

    if (e.code === 'KeyD' && !ctrl) {
      e.preventDefault()
      if (this._ctx.drawMode) this._ctx.drawMode.value = !this._ctx.drawMode.value
      return
    }
    if (e.code === 'KeyP' && !ctrl) {
      e.preventDefault()
      if (this._ctx.penMode) this._ctx.penMode.value = !this._ctx.penMode.value
      return
    }
    if (e.code === 'KeyL' && !ctrl) {
      e.preventDefault()
      if (this._ctx.lineMode) this._ctx.lineMode.value = !this._ctx.lineMode.value
      return
    }
    if (e.code === 'KeyM' && !ctrl) {
      e.preventDefault()
      if (this._ctx.measureMode) this._ctx.measureMode.value = !this._ctx.measureMode.value
      return
    }
    if (e.code === 'KeyR' && !ctrl && !hasSelection) {
      store.showRulers = !store.showRulers
      return
    }
    if (e.code === 'KeyR' && !ctrl && hasSelection) {
      e.preventDefault()
      rotation.rotateSelected(shift ? -90 : 90)
      return
    }
    if (e.code === 'KeyI' && !ctrl && hasSelection) {
      e.preventDefault()
      const itemId = [...store.selectedItemIds][0]
      const item = store.currentItems.find(i => i.id === itemId)
      if (item) emit('pick-color', item, shift ? 'stroke' : 'fill')
      return
    }

    // Ctrl+Z / Ctrl+Y handled above (before input focus check)

    if (ctrl && (e.code === 'BracketLeft' || e.code === 'BracketRight') && hasSelection) {
      e.preventDefault()
      const dir = e.code === 'BracketRight'
        ? (shift ? 'front' : 'forward')
        : (shift ? 'back' : 'backward')
      store.changeLayerOrder?.(dir)
      return
    }

    if (hasSelection && (e.code === 'ArrowUp' || e.code === 'ArrowDown' || e.code === 'ArrowLeft' || e.code === 'ArrowRight')) {
      e.preventDefault()
      const step = shift ? 10 : 1
      const items = store.currentBoard?.items || []
      const updates = []
      for (const item of items) {
        if (!store.selectedItemIds.has(item.id)) continue
        if (item.locked) continue
        let dx = 0, dy = 0
        if (e.code === 'ArrowUp') dy = -step
        if (e.code === 'ArrowDown') dy = step
        if (e.code === 'ArrowLeft') dx = -step
        if (e.code === 'ArrowRight') dx = step
        updates.push({ id: item.id, pos_x: (item.pos_x || 0) + dx, pos_y: (item.pos_y || 0) + dy })
      }
      if (updates.length) store.batchUpdateItems(updates)
      return
    }

    if ((e.code === 'Delete' || e.code === 'Backspace') && hasSelection) {
      e.preventDefault()
      store.deleteSelectedItems?.()
      return
    }

    if (ctrl && e.code === 'KeyA') {
      e.preventDefault()
      selection.selectAll()
      return
    }
    if (ctrl && e.code === 'KeyC' && hasSelection) {
      e.preventDefault()
      store.copySelectedItems()
      return
    }
    if (ctrl && e.code === 'KeyD' && hasSelection) {
      e.preventDefault()
      store.duplicateSelectedItems(30, 30)
      return
    }
    if (ctrl && e.code === 'KeyG') {
      e.preventDefault()
      if (shift) store.ungroupSelectedItems?.()
      else store.groupSelectedItems?.()
      return
    }

    if (e.altKey && shift) {
      const boolOps = {
        'KeyU': 'union', 'KeyS': 'subtract', 'KeyI': 'intersect',
        'KeyE': 'exclude', 'KeyF': 'flatten',
      }
      if (boolOps[e.code] && store.canBooleanOp?.()) {
        e.preventDefault()
        store.booleanOp?.(boolOps[e.code])
        return
      }
    }
  }
}
