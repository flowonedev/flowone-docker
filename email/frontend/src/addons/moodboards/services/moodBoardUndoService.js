import { ref } from 'vue'
import api from '@/services/api'

/**
 * Undo/redo history for the moodboards store.
 *
 * Owns the undo/redo stacks (count + byte bounded), label generation,
 * IndexedDB persistence and the server round-trips that revert/reapply
 * actions. Extracted from stores/moodBoards.js per the modularity rules.
 *
 * @param ctx {
 *   currentBoard: Ref<board|null>,
 *   selectedItemIds: Ref<Set<number>>,
 *   boardApiBase: () => string,
 *   cancelPendingTimers: (itemIds) => void,   // cancels item debounce timers in the store
 *   locallyCreatedIds: Set<number>,           // WS echo suppression for our own creations
 * }
 */
export function createUndoRedoService(ctx) {
  const { currentBoard, selectedItemIds, boardApiBase, cancelPendingTimers, locallyCreatedIds } = ctx

  const undoStack = ref([]) // Array of { type, data } action records
  const redoStack = ref([])
  const MAX_UNDO = 200
  // Byte budget so huge snapshots (multi-delete of image-heavy boards) can't
  // hold hundreds of MB; oldest actions are evicted first.
  const MAX_UNDO_BYTES = 15 * 1024 * 1024
  let _undoBytes = 0

  function _estimateActionSize(action) {
    try {
      return JSON.stringify(action)?.length || 256
    } catch {
      return 2048
    }
  }

  let _undoSaveTimer = null
  function persistUndoStack() {
    clearTimeout(_undoSaveTimer)
    _undoSaveTimer = setTimeout(() => {
      const bid = currentBoard.value?.id
      if (bid) {
        import('./undoIndexedDB.js').then(m => m.saveUndoStack(bid, undoStack.value, redoStack.value))
      }
    }, 1000)
  }

  function _generateUndoLabel(action) {
    const t = action.type
    if (t === 'add') return { label: `Add ${action.item?.type || 'item'}`, icon: 'add_circle' }
    if (t === 'delete') return { label: `Delete ${action.item?.title || action.item?.type || 'item'}`, icon: 'delete' }
    if (t === 'multi-delete') return { label: `Delete ${action.items?.length || 0} items`, icon: 'delete_sweep' }
    if (t === 'multi-add') return { label: `Paste ${action.items?.length || action.itemIds?.length || 0} items`, icon: 'content_paste' }
    if (t === 'batch-update') return { label: `Update ${action.previousUpdates?.length || 0} items`, icon: 'sync_alt' }
    if (t === 'update') {
      const keys = Object.keys(action.previousData || {})
      if (keys.includes('pos_x') || keys.includes('pos_y')) return { label: 'Move item', icon: 'open_with' }
      if (keys.includes('width') || keys.includes('height')) return { label: 'Resize item', icon: 'aspect_ratio' }
      if (keys.includes('rotation')) return { label: 'Rotate item', icon: 'rotate_right' }
      if (keys.includes('color')) return { label: 'Change fill', icon: 'palette' }
      if (keys.includes('style_data')) return { label: 'Change style', icon: 'brush' }
      if (keys.includes('title') || keys.includes('content')) return { label: 'Edit text', icon: 'edit' }
      return { label: 'Update item', icon: 'tune' }
    }
    return { label: t || 'Action', icon: 'history' }
  }

  function pushUndo(action) {
    action.timestamp = Date.now()
    const meta = _generateUndoLabel(action)
    action.label = action.label || meta.label
    action.icon = action.icon || meta.icon
    action._size = _estimateActionSize(action)
    undoStack.value.push(action)
    _undoBytes += action._size
    while (undoStack.value.length > MAX_UNDO
      || (_undoBytes > MAX_UNDO_BYTES && undoStack.value.length > 1)) {
      const evicted = undoStack.value.shift()
      _undoBytes -= evicted?._size || 0
    }
    if (_undoBytes < 0) _undoBytes = 0
    redoStack.value = []
    persistUndoStack()
  }

  function clearHistory() {
    undoStack.value = []
    redoStack.value = []
    _undoBytes = 0
  }

  /** Replace the stacks with a persisted snapshot (board load from IndexedDB). */
  function restorePersistedStacks(saved) {
    undoStack.value = saved.undoStack
    redoStack.value = saved.redoStack
    _undoBytes = saved.undoStack.reduce((sum, a) => sum + (a?._size || 0), 0)
  }

  // Used by redo(): re-push onto the undo stack with byte accounting but
  // WITHOUT clearing the redo stack (unlike pushUndo).
  function _pushUndoRaw(action) {
    action._size = _estimateActionSize(action)
    undoStack.value.push(action)
    _undoBytes += action._size
  }

  function getAffectedItemIds(action) {
    if (!action) return []
    if (action.itemIds) return action.itemIds
    if (action.itemId) return [action.itemId]
    if (action.previousUpdates) return action.previousUpdates.map(u => u.id).filter(Boolean)
    if (action.newUpdates) return action.newUpdates.map(u => u.id).filter(Boolean)
    if (action.item?.id) return [action.item.id]
    if (action.items) return action.items.map(i => i.id).filter(Boolean)
    return []
  }

  function _markLocallyCreated(id) {
    locallyCreatedIds.add(id)
    setTimeout(() => locallyCreatedIds.delete(id), 15000)
  }

  async function undo() {
    if (!undoStack.value.length || !currentBoard.value) return
    const action = undoStack.value.pop()
    _undoBytes -= action._size || 0
    if (_undoBytes < 0) _undoBytes = 0
    const base = boardApiBase()

    // Cancel any pending debounced updates for items affected by this undo
    // to prevent stale data from overwriting the restored state
    const affectedIds = getAffectedItemIds(action)
    if (affectedIds.length) cancelPendingTimers(affectedIds)

    try {
      if (action.type === 'add') {
        const itemId = action.itemId
        await api.delete(`${base}/items/${itemId}`)
        currentBoard.value.items = currentBoard.value.items.filter(i => i.id !== itemId)
        currentBoard.value.connections = (currentBoard.value.connections || []).filter(
          c => c.from_item_id !== itemId && c.to_item_id !== itemId
        )
        const next = new Set(selectedItemIds.value)
        next.delete(itemId)
        selectedItemIds.value = next
        redoStack.value.push({ type: 'delete-undo', item: action.item })
      } else if (action.type === 'delete') {
        // Soft-delete restore: unset deleted_at on the original item (preserves ID + connections)
        const itemId = action.item?.id || action.itemId
        const response = await api.post(`${base}/items/${itemId}/restore`)
        if (response.data.success) {
          const restored = response.data.data?.item || action.item
          _markLocallyCreated(restored.id)
          if (!currentBoard.value.items.find(i => i.id === restored.id)) {
            currentBoard.value.items.push(restored)
          }
          redoStack.value.push({ type: 'add-undo', itemId: restored.id, item: restored })
        }
      } else if (action.type === 'update') {
        const idx = currentBoard.value.items.findIndex(i => i.id === action.itemId)
        const currentState = idx !== -1 ? { ...currentBoard.value.items[idx] } : null
        if (idx !== -1) {
          currentBoard.value.items[idx] = { ...currentBoard.value.items[idx], ...action.previousData }
        }
        await api.put(`${base}/items/${action.itemId}`, action.previousData)
        if (currentState) {
          redoStack.value.push({ type: 'update', itemId: action.itemId, previousData: action.newData, newData: action.previousData })
        }
      } else if (action.type === 'batch-update') {
        for (const prev of action.previousUpdates) {
          const idx = currentBoard.value.items.findIndex(i => i.id === prev.id)
          if (idx !== -1) {
            currentBoard.value.items[idx] = { ...currentBoard.value.items[idx], ...prev }
          }
        }
        const restoreUpdates = action.previousUpdates.map(u => ({ ...u }))
        await api.put(`${base}/items/batch`, { updates: restoreUpdates })
        redoStack.value.push({ type: 'batch-update', previousUpdates: action.newUpdates, newUpdates: action.previousUpdates })
      } else if (action.type === 'multi-add') {
        const idSet = new Set(action.itemIds)
        try { await api.post(`${base}/items/batch-delete`, { item_ids: action.itemIds }) } catch (e) { /* best-effort */ }
        currentBoard.value.items = currentBoard.value.items.filter(i => !idSet.has(i.id))
        currentBoard.value.connections = (currentBoard.value.connections || []).filter(
          c => !idSet.has(c.from_item_id) && !idSet.has(c.to_item_id)
        )
        selectedItemIds.value = new Set([...selectedItemIds.value].filter(id => !idSet.has(id)))
        redoStack.value.push({ type: 'multi-add-redo', items: action.items })
      } else if (action.type === 'multi-delete') {
        // Soft-delete restore: batch unset deleted_at (preserves IDs + connections)
        const itemIds = action.items.map(i => i.id).filter(Boolean)
        if (itemIds.length) {
          await api.post(`${base}/items/restore-batch`, { item_ids: itemIds })
          for (const item of action.items) {
            _markLocallyCreated(item.id)
            if (!currentBoard.value.items.find(i => i.id === item.id)) {
              currentBoard.value.items.push(item)
            }
          }
        }
        redoStack.value.push({ type: 'multi-add-undo', items: action.items })
      }
    } catch (e) {
      console.error('Undo failed:', e)
    }
    if (currentBoard.value) {
      currentBoard.value.items = [...currentBoard.value.items]
    }
    persistUndoStack()
  }

  async function redo() {
    if (!redoStack.value.length || !currentBoard.value) return
    const action = redoStack.value.pop()
    const base = boardApiBase()

    const affectedIds = getAffectedItemIds(action)
    if (affectedIds.length) cancelPendingTimers(affectedIds)

    try {
      if (action.type === 'delete-undo') {
        // Redo of undo-delete = restore the item again
        const itemId = action.item?.id
        const response = await api.post(`${base}/items/${itemId}/restore`)
        if (response.data.success) {
          const item = response.data.data?.item || action.item
          _markLocallyCreated(item.id)
          if (!currentBoard.value.items.find(i => i.id === item.id)) {
            currentBoard.value.items.push(item)
          }
          _pushUndoRaw({ type: 'add', itemId: item.id, item })
        }
      } else if (action.type === 'add-undo') {
        await api.delete(`${base}/items/${action.itemId}`)
        currentBoard.value.items = currentBoard.value.items.filter(i => i.id !== action.itemId)
        _pushUndoRaw({ type: 'delete', item: action.item })
      } else if (action.type === 'update') {
        const idx = currentBoard.value.items.findIndex(i => i.id === action.itemId)
        if (idx !== -1) {
          currentBoard.value.items[idx] = { ...currentBoard.value.items[idx], ...action.previousData }
        }
        await api.put(`${base}/items/${action.itemId}`, action.previousData)
        _pushUndoRaw({ type: 'update', itemId: action.itemId, previousData: action.newData, newData: action.previousData })
      } else if (action.type === 'batch-update') {
        for (const upd of action.previousUpdates) {
          const idx = currentBoard.value.items.findIndex(i => i.id === upd.id)
          if (idx !== -1) {
            currentBoard.value.items[idx] = { ...currentBoard.value.items[idx], ...upd }
          }
        }
        const reapplyUpdates = action.previousUpdates.map(u => ({ ...u }))
        await api.put(`${base}/items/batch`, { updates: reapplyUpdates })
        _pushUndoRaw({ type: 'batch-update', previousUpdates: action.newUpdates, newUpdates: action.previousUpdates })
      } else if (action.type === 'multi-add-undo') {
        for (const item of action.items) {
          try {
            await api.delete(`${base}/items/${item.id}`)
          } catch (e) { /* skip */ }
        }
        const ids = new Set(action.items.map(i => i.id))
        currentBoard.value.items = currentBoard.value.items.filter(i => !ids.has(i.id))
        _pushUndoRaw({ type: 'multi-delete', items: action.items })
      } else if (action.type === 'multi-add-redo') {
        // Redo of multi-delete undo = restore batch
        const itemIds = action.items.map(i => i.id).filter(Boolean)
        if (itemIds.length) {
          await api.post(`${base}/items/restore-batch`, { item_ids: itemIds })
          for (const item of action.items) {
            _markLocallyCreated(item.id)
            if (!currentBoard.value.items.find(i => i.id === item.id)) {
              currentBoard.value.items.push(item)
            }
          }
        }
        _pushUndoRaw({ type: 'multi-add', itemIds: action.items.map(i => i.id), items: action.items.map(i => ({ ...i })) })
      }
    } catch (e) {
      console.error('Redo failed:', e)
    }
    if (currentBoard.value) {
      currentBoard.value.items = [...currentBoard.value.items]
    }
    persistUndoStack()
  }

  return {
    undoStack, redoStack,
    pushUndo, clearHistory, undo, redo,
    getAffectedItemIds, persistUndoStack, restorePersistedStacks,
  }
}
