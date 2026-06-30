/**
 * useZoneDrag - Composable for creating and resizing signature/stamp zones
 * on a PDF page overlay via mouse/touch drag.
 *
 * All zone coordinates are stored as percentages of the page container size
 * so they are resolution-independent.
 */
import { ref } from 'vue'

const MIN_SIZE_PERCENT = 3

export function useZoneDrag() {
  const isDragging = ref(false)
  const isResizing = ref(false)
  const activeZoneId = ref(null)
  const dragStart = ref({ x: 0, y: 0 })

  /**
   * Convert pixel position within a container to percentages.
   */
  function pxToPercent(px, py, containerRect) {
    return {
      x: (px / containerRect.width) * 100,
      y: (py / containerRect.height) * 100,
    }
  }

  /**
   * Clamp a value between min and max.
   */
  function clamp(val, min, max) {
    return Math.min(Math.max(val, min), max)
  }

  /**
   * Get pointer position relative to a container element.
   */
  function getRelativePos(event, containerEl) {
    const rect = containerEl.getBoundingClientRect()
    const clientX = event.clientX ?? event.touches?.[0]?.clientX ?? 0
    const clientY = event.clientY ?? event.touches?.[0]?.clientY ?? 0
    return {
      px: clamp(clientX - rect.left, 0, rect.width),
      py: clamp(clientY - rect.top, 0, rect.height),
      rect,
    }
  }

  /**
   * Start creating a new zone by dragging on the page overlay.
   * @param {MouseEvent|TouchEvent} event
   * @param {HTMLElement} containerEl
   * @param {Function} onNewZone - called with the new zone partial { x_percent, y_percent }
   * @returns {{ startPercent: {x, y} }}
   */
  function startCreate(event, containerEl, onNewZone) {
    const { px, py, rect } = getRelativePos(event, containerEl)
    const start = pxToPercent(px, py, rect)
    dragStart.value = { x: start.x, y: start.y }
    isDragging.value = true

    if (onNewZone) onNewZone(start)

    return { startPercent: start }
  }

  /**
   * Update zone dimensions while dragging to create.
   * @param {MouseEvent|TouchEvent} event
   * @param {HTMLElement} containerEl
   * @param {object} zone - zone object with x_percent, y_percent, width_percent, height_percent
   */
  function updateCreate(event, containerEl, zone) {
    if (!isDragging.value || !zone) return
    const { px, py, rect } = getRelativePos(event, containerEl)
    const current = pxToPercent(px, py, rect)

    const x = Math.min(dragStart.value.x, current.x)
    const y = Math.min(dragStart.value.y, current.y)
    const w = Math.abs(current.x - dragStart.value.x)
    const h = Math.abs(current.y - dragStart.value.y)

    zone.x_percent = clamp(x, 0, 100 - MIN_SIZE_PERCENT)
    zone.y_percent = clamp(y, 0, 100 - MIN_SIZE_PERCENT)
    zone.width_percent = clamp(w, MIN_SIZE_PERCENT, 100 - zone.x_percent)
    zone.height_percent = clamp(h, MIN_SIZE_PERCENT, 100 - zone.y_percent)
  }

  /**
   * Finish creating a zone.
   * @param {object} zone
   * @returns {boolean} true if zone is large enough to keep
   */
  function endCreate(zone) {
    isDragging.value = false
    if (!zone) return false
    return zone.width_percent >= MIN_SIZE_PERCENT && zone.height_percent >= MIN_SIZE_PERCENT
  }

  /**
   * Start moving an existing zone.
   */
  function startMove(event, containerEl, zone) {
    const { px, py, rect } = getRelativePos(event, containerEl)
    const pos = pxToPercent(px, py, rect)
    dragStart.value = {
      x: pos.x - zone.x_percent,
      y: pos.y - zone.y_percent,
    }
    activeZoneId.value = zone.id ?? zone._tempId
    isDragging.value = true
  }

  /**
   * Update position while moving a zone.
   */
  function updateMove(event, containerEl, zone) {
    if (!isDragging.value || !zone) return
    const { px, py, rect } = getRelativePos(event, containerEl)
    const pos = pxToPercent(px, py, rect)

    zone.x_percent = clamp(pos.x - dragStart.value.x, 0, 100 - zone.width_percent)
    zone.y_percent = clamp(pos.y - dragStart.value.y, 0, 100 - zone.height_percent)
  }

  /**
   * Start resizing from a corner handle.
   */
  function startResize(event, containerEl, zone, handle) {
    const { px, py, rect } = getRelativePos(event, containerEl)
    const pos = pxToPercent(px, py, rect)
    dragStart.value = { x: pos.x, y: pos.y, handle, origZone: { ...zone } }
    activeZoneId.value = zone.id ?? zone._tempId
    isResizing.value = true
  }

  /**
   * Update while resizing.
   */
  function updateResize(event, containerEl, zone) {
    if (!isResizing.value || !zone) return
    const { px, py, rect } = getRelativePos(event, containerEl)
    const pos = pxToPercent(px, py, rect)
    const { handle, origZone } = dragStart.value

    const dx = pos.x - dragStart.value.x
    const dy = pos.y - dragStart.value.y

    if (handle === 'se') {
      zone.width_percent = clamp(origZone.width_percent + dx, MIN_SIZE_PERCENT, 100 - zone.x_percent)
      zone.height_percent = clamp(origZone.height_percent + dy, MIN_SIZE_PERCENT, 100 - zone.y_percent)
    } else if (handle === 'sw') {
      const newX = clamp(origZone.x_percent + dx, 0, origZone.x_percent + origZone.width_percent - MIN_SIZE_PERCENT)
      zone.width_percent = origZone.width_percent + (origZone.x_percent - newX)
      zone.x_percent = newX
      zone.height_percent = clamp(origZone.height_percent + dy, MIN_SIZE_PERCENT, 100 - zone.y_percent)
    } else if (handle === 'ne') {
      zone.width_percent = clamp(origZone.width_percent + dx, MIN_SIZE_PERCENT, 100 - zone.x_percent)
      const newY = clamp(origZone.y_percent + dy, 0, origZone.y_percent + origZone.height_percent - MIN_SIZE_PERCENT)
      zone.height_percent = origZone.height_percent + (origZone.y_percent - newY)
      zone.y_percent = newY
    } else if (handle === 'nw') {
      const newX = clamp(origZone.x_percent + dx, 0, origZone.x_percent + origZone.width_percent - MIN_SIZE_PERCENT)
      const newY = clamp(origZone.y_percent + dy, 0, origZone.y_percent + origZone.height_percent - MIN_SIZE_PERCENT)
      zone.width_percent = origZone.width_percent + (origZone.x_percent - newX)
      zone.height_percent = origZone.height_percent + (origZone.y_percent - newY)
      zone.x_percent = newX
      zone.y_percent = newY
    }
  }

  function endDrag() {
    isDragging.value = false
    isResizing.value = false
    activeZoneId.value = null
  }

  return {
    isDragging,
    isResizing,
    activeZoneId,
    startCreate,
    updateCreate,
    endCreate,
    startMove,
    updateMove,
    startResize,
    updateResize,
    endDrag,
    getRelativePos,
    pxToPercent,
  }
}
