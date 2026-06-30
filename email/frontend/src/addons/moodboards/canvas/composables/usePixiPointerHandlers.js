import { nextTick } from 'vue'
import { hitTestCardAction } from '../renderer/types/CardRenderer.js'
import { CONTAINER_TYPES } from '../utils/containerTypes.js'

/**
 * Pointer / mouse interaction handlers for the Pixi canvas.
 *
 * Managers are created asynchronously in initPixi() and torn down on canvas
 * recovery, so they are accessed through the `mgr` getter object rather than
 * captured once.
 *
 * @param ctx {
 *   store, props, emit, measure,
 *   modes: { drawMode, penMode, lineMode, measureMode, commentMode, snapGrid, snapCenter },
 *   state: { connDragActive, connDragEndpoint, rubberBandRect, dragGuides,
 *            dragDistanceGuides, pixiIsResizing, pixiIsRotating },
 *   mgr: getters for { panZoom, selectionMgr, dragMgr, resizeMgr, rotationMgr,
 *                      connectionDrag, lineToolMgr, cursorMgr, itemRenderer },
 *   selectedConnectionId: Ref,
 *   fns: { toCanvasCoords, completeConnectionTo, kickTicker, onItemsMutated,
 *          startEditing, onReplaceImage, onAddImagesToSet },
 * }
 */
export function usePixiPointerHandlers(ctx) {
  const { store, props, emit, measure, modes, state, mgr, selectedConnectionId, fns } = ctx
  const { drawMode, penMode, lineMode, measureMode, commentMode, snapGrid, snapCenter } = modes
  const {
    connDragActive, connDragEndpoint, rubberBandRect,
    dragGuides, dragDistanceGuides, pixiIsResizing, pixiIsRotating,
  } = state

  function _resolveGroupParent(itemId) {
    if (store.editingGroupId) return itemId
    const items = store.currentBoard?.items || []
    let item = items.find(i => i.id === itemId)
    if (!item) return itemId
    while (item?.parent_id) {
      const parent = items.find(i => i.id === item.parent_id)
      if (!parent || !CONTAINER_TYPES.has(parent.type)) break
      if (store.editingGroupId === parent.id) return item.id
      item = parent
    }
    return item?.id ?? itemId
  }

  /** Canvas point → item-local coords (inverse of the item's center transform). */
  function toItemLocalCoords(item, pt) {
    const sd = item.style_data || {}
    const w = item.width || 0
    const h = item.height || 0
    const cx = (item.pos_x || 0) + w / 2
    const cy = (item.pos_y || 0) + h / 2
    let dx = pt.x - cx
    let dy = pt.y - cy
    const rot = (item.rotation || sd.rotation || 0) * Math.PI / 180
    if (rot) {
      const cos = Math.cos(-rot)
      const sin = Math.sin(-rot)
      const rx = dx * cos - dy * sin
      const ry = dx * sin + dy * cos
      dx = rx
      dy = ry
    }
    const s = sd.item_scale || 1
    dx /= s * (sd.flip_x ? -1 : 1)
    dy /= s * (sd.flip_y ? -1 : 1)
    return { x: dx + w / 2, y: dy + h / 2 }
  }

  async function toggleCardTodo(item, todo) {
    const next = (todo.completed ?? todo.done) ? 0 : 1
    // Optimistic local flip so the canvas re-renders immediately
    if ('completed' in todo || !('done' in todo)) todo.completed = next
    if ('done' in todo) todo.done = next
    // Swap the array reference so the renderer's snapshot diff picks it up
    if (Array.isArray(item.todos)) item.todos = [...item.todos]
    await store.updateTodo(todo.id, { completed: next })
  }

  async function altDragDuplicate(e) {
    store.duplicateSelectedItems(0, 0)
    await nextTick()
    if (!store.selectedItemIds.size) return
    mgr.dragMgr.startDrag(e.clientX, e.clientY, snapCenter.value)
  }

  function onPointerDown(e) {
    if (e.button === 1) return
    if (store.presentationMode) return
    if (!mgr.panZoom) return
    fns.kickTicker()

    if (mgr.panZoom.isSpaceHeld) {
      mgr.panZoom.startSpacePan(e.clientX, e.clientY)
      return
    }

    if (lineMode.value && !props.readonly) {
      mgr.lineToolMgr.startLine(e.clientX, e.clientY)
      return
    }

    if (measureMode.value && !props.readonly && e.button === 0) {
      const pt = fns.toCanvasCoords(e.clientX, e.clientY)
      measure.beginMeasure(Math.round(pt.x), Math.round(pt.y))
      return
    }

    if (drawMode.value && !props.readonly) return
    if (penMode.value && !props.readonly) return

    if (mgr.connectionDrag?.isActive) {
      const hit = mgr.selectionMgr.hitTest(e.clientX, e.clientY)
      if (hit && hit.id !== mgr.connectionDrag.fromItemId) {
        fns.completeConnectionTo(hit.id)
      } else {
        mgr.connectionDrag.cancel()
        connDragActive.value = false
        connDragEndpoint.value = null
      }
      return
    }

    if (commentMode.value) {
      const pt = fns.toCanvasCoords(e.clientX, e.clientY)
      emit('comment-canvas', { x: pt.x, y: pt.y })
      return
    }

    const pt = fns.toCanvasCoords(e.clientX, e.clientY)
    const preciseConnHit = mgr.itemRenderer?.hitTestConnection(pt.x, pt.y, 8 / (store.zoom || 1))
    if (preciseConnHit) {
      store.selectedItemIds = new Set()
      selectedConnectionId.value = preciseConnHit.id
      preciseConnHit._clickX = e.clientX
      preciseConnHit._clickY = e.clientY
      emit('select-connection', preciseConnHit)
      return
    }

    const hit = mgr.selectionMgr.hitTest(e.clientX, e.clientY)

    if (!hit) {
      const connHit = mgr.itemRenderer?.hitTestConnection(pt.x, pt.y, 14 / (store.zoom || 1))
      if (connHit) {
        store.selectedItemIds = new Set()
        selectedConnectionId.value = connHit.id
        connHit._clickX = e.clientX
        connHit._clickY = e.clientY
        emit('select-connection', connHit)
        return
      }
    }

    selectedConnectionId.value = null

    // Interactive card regions (todo checkboxes, link URLs) — DOM parity
    if (hit && !props.readonly && e.button === 0 && !e.shiftKey && !e.altKey) {
      const cardItem = (store.currentBoard?.items || []).find(i => i.id === hit.id)
      if (cardItem && (cardItem.type === 'todo_list' || cardItem.type === 'link') && !cardItem.locked) {
        const local = toItemLocalCoords(cardItem, pt)
        const action = hitTestCardAction(cardItem, local.x, local.y)
        if (action?.action === 'toggle-todo') {
          toggleCardTodo(cardItem, action.todo)
          return
        }
        if (action?.action === 'open-link') {
          window.open(action.url, '_blank', 'noopener')
          return
        }
      }
    }

    const resolvedHitId = hit ? _resolveGroupParent(hit.id) : null

    if (resolvedHitId && store.selectedItemIds.has(resolvedHitId) && !props.readonly && !e.shiftKey) {
      const items = store.currentBoard?.items || []
      const item = items.find(i => i.id === resolvedHitId)
      if (item && !item.locked) {
        if (e.altKey) {
          altDragDuplicate(e)
        } else {
          store.flushPendingUpdates(store.selectedItemIds)
          mgr.dragMgr.startDrag(e.clientX, e.clientY, snapCenter.value)
        }
      }
      return
    }

    mgr.selectionMgr.handleClick(e.clientX, e.clientY, e.shiftKey)

    if (!hit) {
      mgr.selectionMgr.startRubberBand(e.clientX, e.clientY)
    } else if (!props.readonly) {
      const items = store.currentBoard?.items || []
      const selectedId = store.selectedItemIds.size === 1 ? [...store.selectedItemIds][0] : (resolvedHitId || hit.id)
      const item = items.find(i => i.id === selectedId)
      if (item && !item.locked) {
        if (e.altKey) {
          altDragDuplicate(e)
        } else {
          store.flushPendingUpdates(store.selectedItemIds)
          mgr.dragMgr.startDrag(e.clientX, e.clientY, snapCenter.value)
        }
      }
    }
  }

  function onPointerMove(e) {
    if (!mgr.panZoom) return
    if (mgr.panZoom.isPanning) {
      mgr.panZoom.moveSpacePan(e.clientX, e.clientY)
      return
    }

    if (mgr.dragMgr.isDragging) {
      mgr.dragMgr.moveDrag(e.clientX, e.clientY, snapCenter.value, snapGrid.value, e.shiftKey)
      dragGuides.value = mgr.dragMgr.guides
      dragDistanceGuides.value = mgr.dragMgr.distanceGuides
      fns.onItemsMutated() // items watcher is paused during drag
      return
    }

    if (mgr.resizeMgr.isResizing) {
      mgr.resizeMgr.moveResize(e.clientX, e.clientY, store.zoom, e.shiftKey)
      fns.onItemsMutated() // items watcher is paused during resize
      return
    }

    if (mgr.rotationMgr.isRotating) {
      mgr.rotationMgr.moveRotation(e.clientX, e.clientY, e.shiftKey)
      fns.onItemsMutated() // items watcher is paused during rotation
      return
    }

    if (mgr.selectionMgr.isRubberBanding) {
      const rect = mgr.selectionMgr.updateRubberBand(e.clientX, e.clientY)
      rubberBandRect.value = rect ? { ...rect } : null
      return
    }

    if (lineMode.value && mgr.lineToolMgr.isDrawing) {
      mgr.lineToolMgr.moveLine(e.clientX, e.clientY, e.shiftKey)
      return
    }

    if (measureMode.value && measure.dragging.value) {
      const pt = fns.toCanvasCoords(e.clientX, e.clientY)
      measure.updateMeasure(Math.round(pt.x), Math.round(pt.y), e.shiftKey)
      return
    }

    if (mgr.connectionDrag?.isActive) {
      mgr.connectionDrag.moveConnection(e.clientX, e.clientY)
      connDragEndpoint.value = mgr.connectionDrag.endpoint
      return
    }

    const hit = mgr.selectionMgr.hitTest(e.clientX, e.clientY)
    const cursorState = {
      isPanning: mgr.panZoom.isPanning,
      spaceHeld: mgr.panZoom.isSpaceHeld,
      connectionMode: mgr.connectionDrag?.isActive,
      lineMode: lineMode.value,
      measureMode: measureMode.value,
      penMode: penMode.value,
      overItem: !!hit,
      itemLocked: hit ? (store.currentBoard?.items?.find(i => i.id === hit.id)?.locked) : false,
    }
    mgr.cursorMgr.set(mgr.cursorMgr.getCursorForState(cursorState))
  }

  function onPointerUp(e) {
    if (!mgr.panZoom) return
    fns.kickTicker()
    if (mgr.panZoom.isPanning) {
      mgr.panZoom.endSpacePan()
      return
    }

    if (mgr.dragMgr.isDragging) {
      mgr.dragMgr.endDrag(snapGrid.value)
      dragGuides.value = []
      dragDistanceGuides.value = []
      return
    }

    if (mgr.resizeMgr.isResizing) {
      mgr.resizeMgr.endResize()
      pixiIsResizing.value = false
      return
    }

    if (mgr.rotationMgr.isRotating) {
      mgr.rotationMgr.endRotation()
      pixiIsRotating.value = false
      return
    }

    if (mgr.selectionMgr.isRubberBanding) {
      mgr.selectionMgr.endRubberBand()
      rubberBandRect.value = null
      return
    }

    if (lineMode.value && mgr.lineToolMgr.isDrawing) {
      const result = mgr.lineToolMgr.endLine(e.clientX, e.clientY)
      if (result) {
        store.addItem?.(result)
        lineMode.value = false
      }
      return
    }

    if (measureMode.value && measure.dragging.value) {
      measure.finishMeasure()
      return
    }

    if (mgr.connectionDrag?.isActive) {
      const result = mgr.connectionDrag.endConnection(e.clientX, e.clientY)
      connDragActive.value = false
      connDragEndpoint.value = null
      if (result) {
        store.addConnection?.({
          from_item_id: result.fromId,
          to_item_id: result.toId,
          line_color: 'accent',
        })
      }
      return
    }
  }

  function onDoubleClick(e) {
    if (props.readonly || store.presentationMode) return
    const result = mgr.selectionMgr.handleDoubleClick(e.clientX, e.clientY)

    if (!result) {
      const pt = fns.toCanvasCoords(e.clientX, e.clientY)
      store.addItem?.({
        type: 'note',
        pos_x: Math.round(pt.x) - 120,
        pos_y: Math.round(pt.y) - 60,
        width: 240,
        color: '#fef3c7',
        title: '',
        content: '',
      })
      return
    }

    if (result.action !== 'edit') return

    const item = result.item
    switch (item.type) {
      case 'image':
        fns.onReplaceImage(item)
        break
      case 'image_set':
        fns.onAddImagesToSet(item)
        break
      case 'file':
        emit('preview-file', item)
        break
      case 'folder':
        emit('browse-folder', item)
        break
      case 'drawing':
        emit('edit-drawing', item)
        break
      case 'color_swatch':
        emit('open-color-picker', item)
        break
      case 'video':
      case 'youtube':
      case 'audio':
        emit('preview-file', item)
        break
      case 'board_link': {
        let linkData = item.content || {}
        if (typeof linkData === 'string') {
          try { linkData = JSON.parse(linkData || '{}') } catch { linkData = {} }
        }
        if (linkData.board_id) store.openBoard?.(linkData.board_id)
        break
      }
      default:
        fns.startEditing(item)
        break
    }
  }

  function onContextMenu(e) {
    const pt = fns.toCanvasCoords(e.clientX, e.clientY)
    const preciseConnHit = mgr.itemRenderer?.hitTestConnection(pt.x, pt.y, 8 / (store.zoom || 1))
    if (preciseConnHit) {
      store.selectedItemIds = new Set()
      selectedConnectionId.value = preciseConnHit.id
      emit('connection-context', e, preciseConnHit)
      return
    }

    const hit = mgr.selectionMgr.hitTest(e.clientX, e.clientY)
    if (hit) {
      mgr.selectionMgr.handleClick(e.clientX, e.clientY, false)
      const items = store.currentBoard?.items || []
      const item = items.find(i => i.id === hit.id)
      emit('item-context', e, item)
      return
    }
    const connHit = mgr.itemRenderer?.hitTestConnection(pt.x, pt.y, 14 / (store.zoom || 1))
    if (connHit) {
      store.selectedItemIds = new Set()
      selectedConnectionId.value = connHit.id
      emit('connection-context', e, connHit)
    }
  }

  function onResizeStart(handle, event) {
    if (props.readonly) return
    window.getSelection()?.removeAllRanges()
    const items = store.currentBoard?.items || []
    const id = [...store.selectedItemIds][0]
    const item = items.find(i => i.id === id)
    if (item) {
      mgr.resizeMgr.startResize(item, handle, event.clientX, event.clientY)
      pixiIsResizing.value = true
    }
  }

  function onRotationStart(event) {
    if (props.readonly) return
    const allItems = store.currentBoard?.items || []
    const sel = store.selectedItemIds
    const selected = allItems.filter(i => sel.has(i.id))
    if (selected.length && mgr.rotationMgr.startRotation(selected, event.clientX, event.clientY)) {
      pixiIsRotating.value = true
    }
  }

  return {
    onPointerDown, onPointerMove, onPointerUp,
    onDoubleClick, onContextMenu,
    onResizeStart, onRotationStart,
    altDragDuplicate, toggleCardTodo, toItemLocalCoords, _resolveGroupParent,
  }
}
