import { ref, computed, reactive, watch } from 'vue'
import api from '@/services/api'

const SNAP_DEG = 5
const SNAP_RAD = SNAP_DEG * (Math.PI / 180)

let settingsDebounce = null

export function useCanvasMeasure() {
  const visible = ref(true)
  const dragging = ref(false)
  const start = reactive({ x: 0, y: 0 })
  const end = reactive({ x: 0, y: 0 })

  const measurements = ref([])
  const boardId = ref(null)

  const lineColor = ref('#0ea5e9')
  const lineWidth = ref(1.5)

  const currentLine = computed(() => {
    if (!dragging.value) return null
    const dx = end.x - start.x
    const dy = end.y - start.y
    const dist = Math.sqrt(dx * dx + dy * dy)
    const angle = Math.atan2(dy, dx) * (180 / Math.PI)
    return {
      x1: start.x, y1: start.y,
      x2: end.x, y2: end.y,
      distance: Math.round(dist),
      width: Math.abs(Math.round(dx)),
      height: Math.abs(Math.round(dy)),
      angle: +angle.toFixed(1),
    }
  })

  function persistSettings() {
    if (!boardId.value) return
    clearTimeout(settingsDebounce)
    settingsDebounce = setTimeout(() => {
      api.put(`/mood-boards/${boardId.value}/measure-settings`, {
        measure_color: lineColor.value,
        measure_width: lineWidth.value,
        measure_visible: visible.value ? 1 : 0,
      }).catch(() => {})
    }, 500)
  }

  function loadBoard(id, board = null) {
    boardId.value = id
    if (board) {
      measurements.value = (board.measurements || []).map(m => ({
        id: m.id,
        x1: parseFloat(m.x1), y1: parseFloat(m.y1),
        x2: parseFloat(m.x2), y2: parseFloat(m.y2),
        distance: parseInt(m.distance) || 0,
        width: parseInt(m.width) || 0,
        height: parseInt(m.height) || 0,
        angle: parseFloat(m.angle) || 0,
      }))
      if (board.measure_color) lineColor.value = board.measure_color
      if (board.measure_width != null) lineWidth.value = parseFloat(board.measure_width)
      if (board.measure_visible != null) visible.value = !!parseInt(board.measure_visible)
    } else {
      measurements.value = []
    }
    dragging.value = false
  }

  function unloadBoard() {
    boardId.value = null
    measurements.value = []
    dragging.value = false
  }

  watch(lineColor, persistSettings)
  watch(lineWidth, persistSettings)
  watch(visible, persistSettings)

  function beginMeasure(canvasX, canvasY) {
    start.x = canvasX
    start.y = canvasY
    end.x = canvasX
    end.y = canvasY
    dragging.value = true
  }

  function updateMeasure(canvasX, canvasY, constrained = false) {
    if (!dragging.value) return
    if (constrained) {
      const dx = canvasX - start.x
      const dy = canvasY - start.y
      const dist = Math.sqrt(dx * dx + dy * dy)
      const angle = Math.atan2(dy, dx)
      const snapped = Math.round(angle / SNAP_RAD) * SNAP_RAD
      end.x = Math.round(start.x + dist * Math.cos(snapped))
      end.y = Math.round(start.y + dist * Math.sin(snapped))
    } else {
      end.x = canvasX
      end.y = canvasY
    }
  }

  async function finishMeasure() {
    if (!dragging.value) return
    const line = currentLine.value
    dragging.value = false
    if (!line || line.distance < 2 || !boardId.value) return

    try {
      const res = await api.post(`/mood-boards/${boardId.value}/measurements`, {
        x1: line.x1, y1: line.y1,
        x2: line.x2, y2: line.y2,
        distance: line.distance,
        width: line.width,
        height: line.height,
        angle: line.angle,
      })
      if (res.data?.success) {
        const m = res.data.data.measurement
        measurements.value.push({
          id: m.id,
          x1: parseFloat(m.x1), y1: parseFloat(m.y1),
          x2: parseFloat(m.x2), y2: parseFloat(m.y2),
          distance: parseInt(m.distance) || 0,
          width: parseInt(m.width) || 0,
          height: parseInt(m.height) || 0,
          angle: parseFloat(m.angle) || 0,
        })
      }
    } catch (e) {
      console.error('Failed to save measurement:', e)
    }
  }

  async function removeMeasurement(id) {
    if (!boardId.value) return
    measurements.value = measurements.value.filter(m => m.id !== id)
    try {
      await api.delete(`/mood-boards/${boardId.value}/measurements/${id}`)
    } catch (e) {
      console.error('Failed to delete measurement:', e)
    }
  }

  async function clearAll() {
    if (!boardId.value) return
    measurements.value = []
    dragging.value = false
    try {
      await api.post(`/mood-boards/${boardId.value}/measurements/clear`)
    } catch (e) {
      console.error('Failed to clear measurements:', e)
    }
  }

  function toggleVisibility() {
    visible.value = !visible.value
  }

  return {
    visible,
    dragging,
    currentLine,
    measurements,
    lineColor,
    lineWidth,
    beginMeasure,
    updateMeasure,
    finishMeasure,
    removeMeasurement,
    clearAll,
    toggleVisibility,
    loadBoard,
    unloadBoard,
  }
}
