import { computed, shallowRef, watch } from 'vue'
import { getConnectionCurve } from '../renderer/types/ConnectionRenderer.js'

/**
 * Connection-drag overlay state for the Pixi canvas: drag preview path,
 * pulsing target handles, and selected-connection curve geometry.
 *
 * @param store moodboards store
 * @param ctx {
 *   getConnectionDrag: () => ConnectionDragManager|null,
 *   connDragActive: Ref<boolean>,
 *   connDragEndpoint: Ref<{x,y}|null>,
 *   selectedConnectionId: Ref<number|null>,
 * }
 */
export function usePixiConnectionUI(store, ctx) {
  const { getConnectionDrag, connDragActive, connDragEndpoint, selectedConnectionId } = ctx

  const connDragFrom = computed(() => {
    const connectionDrag = getConnectionDrag()
    if (!connectionDrag?.fromItemId) return { x: 0, y: 0 }
    const item = store.itemMap.get(connectionDrag.fromItemId)
    if (!item) return { x: 0, y: 0 }
    return { x: (item.pos_x || 0) + (item.width || 0) / 2, y: (item.pos_y || 0) + (item.height || 0) / 2 }
  })

  const accentColorHex = computed(() => {
    try {
      const style = getComputedStyle(document.documentElement)
      const rgb = style.getPropertyValue('--color-primary-500')?.trim()
      if (rgb) {
        const parts = rgb.split(/\s+/).map(Number)
        if (parts.length >= 3) {
          return '#' + parts.map(p => p.toString(16).padStart(2, '0')).join('')
        }
      }
    } catch {}
    return '#8b5cf6'
  })

  const connPreviewPath = computed(() => {
    const connectionDrag = getConnectionDrag()
    if (!connectionDrag?.fromItemId || !connDragEndpoint.value) return ''
    const fromItem = store.itemMap.get(connectionDrag.fromItemId)
    if (!fromItem) return ''
    const sd = fromItem.style_data || {}
    const scaleVal = (sd.item_scale != null && sd.item_scale !== 1) ? sd.item_scale : 1
    const rawW = fromItem.width || 240
    const rawH = fromItem.height || 120
    const w = rawW * scaleVal, h = rawH * scaleVal
    const fx = (fromItem.pos_x || 0) + rawW * (1 - scaleVal) / 2
    const fy = (fromItem.pos_y || 0) + rawH * (1 - scaleVal) / 2
    const cx = fx + w / 2, cy = fy + h / 2
    const tx = connDragEndpoint.value.x, ty = connDragEndpoint.value.y
    const dx = tx - cx, dy = ty - cy
    if (dx === 0 && dy === 0) return ''
    const halfW = w / 2, halfH = h / 2
    const absDx = Math.abs(dx), absDy = Math.abs(dy)
    let x1, y1
    if (absDx / halfW > absDy / halfH) {
      const sign = Math.sign(dx)
      x1 = cx + sign * halfW
      y1 = cy + dy * (halfW / absDx)
      y1 = Math.max(fy, Math.min(fy + h, y1))
    } else {
      const sign = Math.sign(dy)
      y1 = cy + sign * halfH
      x1 = cx + dx * (halfH / absDy)
      x1 = Math.max(fx, Math.min(fx + w, x1))
    }
    const dist = Math.sqrt((tx - x1) ** 2 + (ty - y1) ** 2)
    const curvature = Math.min(dist * 0.25, 80)
    const adx = Math.abs(tx - x1), ady = Math.abs(ty - y1)
    let cx1, cy1, cx2, cy2
    if (adx >= ady) {
      cx1 = x1 + curvature * Math.sign(tx - x1 || 1); cy1 = y1
      cx2 = tx - curvature * Math.sign(tx - x1 || 1); cy2 = ty
    } else {
      cx1 = x1; cy1 = y1 + curvature * Math.sign(ty - y1 || 1)
      cx2 = tx; cy2 = ty - curvature * Math.sign(ty - y1 || 1)
    }
    return `M ${x1} ${y1} C ${cx1} ${cy1}, ${cx2} ${cy2}, ${tx} ${ty}`
  })

  // Populated once on connection-drag start instead of a computed: items don't
  // move during a connection drag, and a computed over all items would re-run
  // (and re-track deep deps) on every unrelated item mutation.
  const connTargetHandles = shallowRef([])
  watch(connDragActive, (active) => {
    const connectionDrag = getConnectionDrag()
    if (!active || !connectionDrag?.fromItemId) {
      connTargetHandles.value = []
      return
    }
    const fromId = connectionDrag.fromItemId
    const items = store.currentBoard?.items || []
    connTargetHandles.value = items
      .filter(i => i.id !== fromId && !i.deleted_at)
      .map(i => {
        const sd = i.style_data || {}
        const scaleVal = (sd.item_scale != null && sd.item_scale !== 1) ? sd.item_scale : 1
        const rawW = i.width || 240, rawH = i.height || 120
        const w = rawW * scaleVal, h = rawH * scaleVal
        const x = (i.pos_x || 0) + rawW * (1 - scaleVal) / 2
        const y = (i.pos_y || 0) + rawH * (1 - scaleVal) / 2
        return { id: i.id, cx: x + w / 2, cy: y + h / 2 }
      })
  })

  function completeConnectionTo(targetId) {
    const connectionDrag = getConnectionDrag()
    if (!connectionDrag?.isActive) return
    const fromId = connectionDrag.fromItemId
    connectionDrag.cancel()
    connDragActive.value = false
    connDragEndpoint.value = null
    if (fromId && targetId && fromId !== targetId) {
      store.addConnection?.({
        from_item_id: fromId,
        to_item_id: targetId,
        line_color: 'accent',
      })
    }
  }

  const anchorHandleRadius = computed(() => Math.min(12, Math.max(6, 7 / (store.zoom || 1))))
  const anchorHandleStroke = computed(() => Math.min(3, Math.max(1.5, 2 / (store.zoom || 1))))

  const selectedConnCurve = computed(() => {
    if (!selectedConnectionId.value) return null
    const conn = (store.currentBoard?.connections || []).find(c => c.id === selectedConnectionId.value)
    if (!conn) return null
    return getConnectionCurve(conn, store.itemMap)
  })

  const selectedConnEndpoints = computed(() => {
    const curve = selectedConnCurve.value
    if (!curve) return null
    const conn = (store.currentBoard?.connections || []).find(c => c.id === selectedConnectionId.value)
    if (!conn) return null
    return {
      conn,
      from: { x: curve.x1, y: curve.y1 },
      to: { x: curve.x2, y: curve.y2 },
    }
  })

  const selectedConnPath = computed(() => {
    const curve = selectedConnCurve.value
    if (!curve) return ''
    return `M ${curve.x1} ${curve.y1} C ${curve.cx1} ${curve.cy1}, ${curve.cx2} ${curve.cy2}, ${curve.x2} ${curve.y2}`
  })

  const selectedConnBendPoints = computed(() => {
    const curve = selectedConnCurve.value
    const selected = selectedConnEndpoints.value
    if (!curve || !selected) return null
    const conn = selected.conn
    return {
      cp1: { x: curve.cx1, y: curve.cy1, isCustom: conn.bend_x != null && conn.bend_y != null },
      cp2: { x: curve.cx2, y: curve.cy2, isCustom: conn.bend2_x != null && conn.bend2_y != null },
    }
  })

  return {
    connDragFrom, accentColorHex, connPreviewPath, connTargetHandles,
    completeConnectionTo,
    anchorHandleRadius, anchorHandleStroke,
    selectedConnCurve, selectedConnEndpoints, selectedConnPath, selectedConnBendPoints,
  }
}
