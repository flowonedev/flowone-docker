import { ref, computed } from 'vue'

/**
 * Handles connection selection, anchor dragging, bend-point dragging,
 * and related connection editing interactions.
 */
export function useConnectionInteraction(store, { toCanvasCoordsFn, getCanvasItemRectFn, readonly }) {
  const selectedConnectionId = ref(null)
  let anchorDragState = null
  let bendDragState = null

  function onAnchorPointerDown(event, conn, endpoint) {
    if (readonly.value) return
    event.stopPropagation()
    event.preventDefault()
    selectedConnectionId.value = conn.id
    anchorDragState = { connId: conn.id, endpoint, itemId: endpoint === 'from' ? conn.from_item_id : conn.to_item_id }
    window.addEventListener('pointermove', onAnchorPointerMove)
    window.addEventListener('pointerup', onAnchorPointerUp)
  }

  function onAnchorPointerMove(event) {
    if (!anchorDragState) return
    const pt = toCanvasCoordsFn(event.clientX, event.clientY)
    const rect = getCanvasItemRectFn(anchorDragState.itemId)
    const relX = rect.w > 0 ? (pt.x - rect.x) / rect.w : 0.5
    const relY = rect.h > 0 ? (pt.y - rect.y) / rect.h : 0.5
    const conn = (store.currentBoard?.connections || []).find(c => c.id === anchorDragState.connId)
    if (!conn) return
    if (anchorDragState.endpoint === 'from') {
      conn.from_anchor_x = relX
      conn.from_anchor_y = relY
    } else {
      conn.to_anchor_x = relX
      conn.to_anchor_y = relY
    }
  }

  function onAnchorPointerUp() {
    window.removeEventListener('pointermove', onAnchorPointerMove)
    window.removeEventListener('pointerup', onAnchorPointerUp)
    if (!anchorDragState) return
    const conn = (store.currentBoard?.connections || []).find(c => c.id === anchorDragState.connId)
    if (conn) {
      store.updateConnection?.(conn.id, {
        from_anchor_x: conn.from_anchor_x,
        from_anchor_y: conn.from_anchor_y,
        to_anchor_x: conn.to_anchor_x,
        to_anchor_y: conn.to_anchor_y,
      })
    }
    anchorDragState = null
  }

  function onBendPointerDown(event, conn, pointIndex) {
    if (readonly.value) return
    event.stopPropagation()
    event.preventDefault()
    selectedConnectionId.value = conn.id
    bendDragState = { connId: conn.id, pointIndex }
    window.addEventListener('pointermove', onBendPointerMove)
    window.addEventListener('pointerup', onBendPointerUp)
  }

  function onBendPointerMove(event) {
    if (!bendDragState) return
    const pt = toCanvasCoordsFn(event.clientX, event.clientY)
    const conn = (store.currentBoard?.connections || []).find(c => c.id === bendDragState.connId)
    if (!conn) return
    if (bendDragState.pointIndex === 1) {
      conn.bend_x = pt.x
      conn.bend_y = pt.y
    } else {
      conn.bend2_x = pt.x
      conn.bend2_y = pt.y
    }
  }

  function onBendPointerUp() {
    window.removeEventListener('pointermove', onBendPointerMove)
    window.removeEventListener('pointerup', onBendPointerUp)
    if (!bendDragState) return
    const conn = (store.currentBoard?.connections || []).find(c => c.id === bendDragState.connId)
    if (conn) {
      store.updateConnection?.(conn.id, {
        bend_x: conn.bend_x ?? null,
        bend_y: conn.bend_y ?? null,
        bend2_x: conn.bend2_x ?? null,
        bend2_y: conn.bend2_y ?? null,
      })
    }
    bendDragState = null
  }

  function resetBendPoint(conn, pointIndex) {
    if (!conn) return
    if (pointIndex === 1) {
      conn.bend_x = null
      conn.bend_y = null
      store.updateConnection?.(conn.id, { bend_x: null, bend_y: null })
    } else {
      conn.bend2_x = null
      conn.bend2_y = null
      store.updateConnection?.(conn.id, { bend2_x: null, bend2_y: null })
    }
  }

  function resetConnectionAnchors(conn = null) {
    const target = conn || (store.currentBoard?.connections || []).find(c => c.id === selectedConnectionId.value)
    if (!target) return
    target.from_anchor_x = null
    target.from_anchor_y = null
    target.to_anchor_x = null
    target.to_anchor_y = null
    store.updateConnection?.(target.id, {
      from_anchor_x: null,
      from_anchor_y: null,
      to_anchor_x: null,
      to_anchor_y: null,
    })
  }

  function connColorCss(conn) {
    if (!conn?.line_color || conn.line_color === 'accent') {
      try {
        const rgb = getComputedStyle(document.documentElement).getPropertyValue('--color-primary-500').trim()
        if (rgb) {
          const parts = rgb.split(/\s+/)
          if (parts.length === 3) return `rgb(${parts[0]}, ${parts[1]}, ${parts[2]})`
        }
      } catch {}
      return '#22c55e'
    }
    return conn.line_color
  }

  return {
    selectedConnectionId,
    onAnchorPointerDown,
    onBendPointerDown,
    resetBendPoint,
    resetConnectionAnchors,
    connColorCss,
  }
}
