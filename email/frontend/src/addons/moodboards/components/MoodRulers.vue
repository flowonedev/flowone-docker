<template>
  <div v-if="visible" class="absolute inset-0 pointer-events-none z-[10001]">
    <!-- Corner square (top-left overlap, offset below header) -->
    <div
      class="absolute left-0 w-6 h-6 bg-surface-100 dark:bg-surface-800 border-r border-b border-surface-300 dark:border-surface-600 z-[3] pointer-events-auto cursor-pointer flex items-center justify-center"
      :style="{ top: topOffset + 'px' }"
      title="Toggle guides visibility"
      @click="store.showGuides = !store.showGuides"
    >
      <span class="material-symbols-rounded text-surface-400" style="font-size: 12px;">{{ store.showGuides ? 'grid_on' : 'grid_off' }}</span>
    </div>

    <!-- Horizontal ruler (top edge, offset below header) -->
    <canvas
      ref="hRulerCanvas"
      class="absolute left-6 h-6 pointer-events-auto cursor-col-resize"
      :style="{ top: topOffset + 'px', width: (containerWidth - 24) + 'px', height: '24px' }"
      @mousedown="onHRulerMouseDown"
    />

    <!-- Vertical ruler (left edge, offset below header + ruler height) -->
    <canvas
      ref="vRulerCanvas"
      class="absolute left-0 w-6 pointer-events-auto cursor-row-resize"
      :style="{ top: (topOffset + 24) + 'px', width: '24px', height: (containerHeight - 24 - topOffset) + 'px' }"
      @mousedown="onVRulerMouseDown"
    />

    <!-- Guide lines (rendered in viewport space, draggable) -->
    <template v-if="store.showGuides">
      <div
        v-for="guide in store.guides"
        :key="guide.id"
        class="absolute pointer-events-auto"
        :class="guide.axis === 'x' ? 'cursor-col-resize' : 'cursor-row-resize'"
        :style="guideStyle(guide)"
        @mousedown.stop="onGuideDragStart($event, guide)"
        @dblclick.stop="store.removeGuide(guide.id)"
      >
        <!-- Guide label -->
        <div
          v-if="hoveredGuideId === guide.id || draggingGuideId === guide.id"
          class="absolute bg-orange-500 text-white text-[9px] font-mono px-1.5 py-0.5 rounded shadow-sm whitespace-nowrap z-50 pointer-events-none"
          :style="guideLabelStyle(guide)"
        >
          {{ Math.round(guide.position) }}px
        </div>
      </div>
    </template>

    <!-- Preview guide line while dragging from ruler -->
    <div
      v-if="previewGuide"
      class="absolute pointer-events-none"
      :style="previewGuideStyle"
    />
  </div>
</template>

<script setup>
import { ref, computed, watch, onMounted, onUnmounted, nextTick } from 'vue'
import { useMoodBoardsStore } from '@/addons/moodboards/stores/moodBoards'

const props = defineProps({
  containerWidth: { type: Number, default: 1000 },
  containerHeight: { type: Number, default: 700 },
  topOffset: { type: Number, default: 0 },
})

const store = useMoodBoardsStore()

const visible = computed(() => store.showRulers)

const hRulerCanvas = ref(null)
const vRulerCanvas = ref(null)
const hoveredGuideId = ref(null)
const draggingGuideId = ref(null)
const previewGuide = ref(null) // { axis, screenPos }

// ─── Ruler drawing ────────────────────────────────

function niceStep(raw) {
  const steps = [1, 2, 5, 10, 25, 50, 100, 250, 500, 1000, 2500, 5000]
  for (const s of steps) {
    if (raw <= s * 1.5) return s
  }
  return 5000
}

function drawHorizontalRuler() {
  const canvas = hRulerCanvas.value
  if (!canvas) return
  const w = props.containerWidth - 24
  const h = 24
  const dpr = window.devicePixelRatio || 1
  canvas.width = w * dpr
  canvas.height = h * dpr
  canvas.style.width = w + 'px'
  canvas.style.height = h + 'px'

  const ctx = canvas.getContext('2d')
  ctx.scale(dpr, dpr)
  const isDark = document.documentElement.classList.contains('dark')

  // Background — neutral zinc tones (no blue tint)
  ctx.fillStyle = isDark ? '#18181b' : '#fafafa'
  ctx.fillRect(0, 0, w, h)

  // Bottom border
  ctx.strokeStyle = isDark ? '#3f3f46' : '#d4d4d8'
  ctx.lineWidth = 1
  ctx.beginPath()
  ctx.moveTo(0, h - 0.5)
  ctx.lineTo(w, h - 0.5)
  ctx.stroke()

  const z = store.zoom || 1
  const pan = store.panX || 0
  const rawStep = 80 / z
  const step = niceStep(rawStep)

  // Canvas coordinates at viewport edges
  const startCanvas = -(pan + 24) / z
  const endCanvas = (w - pan) / z
  const first = Math.floor(startCanvas / step) * step

  ctx.fillStyle = isDark ? '#a1a1aa' : '#71717a'
  ctx.font = '9px Inter, system-ui, sans-serif'
  ctx.textAlign = 'center'

  for (let val = first; val <= endCanvas; val += step) {
    const screenX = val * z + pan + 24 - 24 // offset for left ruler
    if (screenX < 0 || screenX > w) continue

    // Major tick
    ctx.strokeStyle = isDark ? '#52525b' : '#a1a1aa'
    ctx.lineWidth = 1
    ctx.beginPath()
    ctx.moveTo(screenX, h - 8)
    ctx.lineTo(screenX, h)
    ctx.stroke()

    // Label
    ctx.fillText(Math.round(val).toString(), screenX, h - 10)

    // Minor ticks (5 subdivisions)
    const minorStep = step / 5
    for (let m = 1; m < 5; m++) {
      const mv = val + m * minorStep
      const ms = mv * z + pan + 24 - 24
      if (ms < 0 || ms > w) continue
      ctx.strokeStyle = isDark ? '#3f3f46' : '#d4d4d8'
      ctx.lineWidth = 0.5
      ctx.beginPath()
      ctx.moveTo(ms, h - 4)
      ctx.lineTo(ms, h)
      ctx.stroke()
    }
  }
}

function drawVerticalRuler() {
  const canvas = vRulerCanvas.value
  if (!canvas) return
  const w = 24
  const h = props.containerHeight - 24
  const dpr = window.devicePixelRatio || 1
  canvas.width = w * dpr
  canvas.height = h * dpr
  canvas.style.width = w + 'px'
  canvas.style.height = h + 'px'

  const ctx = canvas.getContext('2d')
  ctx.scale(dpr, dpr)
  const isDark = document.documentElement.classList.contains('dark')

  // Background — neutral zinc tones (no blue tint)
  ctx.fillStyle = isDark ? '#18181b' : '#fafafa'
  ctx.fillRect(0, 0, w, h)

  // Right border
  ctx.strokeStyle = isDark ? '#3f3f46' : '#d4d4d8'
  ctx.lineWidth = 1
  ctx.beginPath()
  ctx.moveTo(w - 0.5, 0)
  ctx.lineTo(w - 0.5, h)
  ctx.stroke()

  const z = store.zoom || 1
  const pan = store.panY || 0
  const rawStep = 80 / z
  const step = niceStep(rawStep)

  const startCanvas = -(pan + 24) / z
  const endCanvas = (h - pan) / z
  const first = Math.floor(startCanvas / step) * step

  ctx.fillStyle = isDark ? '#a1a1aa' : '#71717a'
  ctx.font = '9px Inter, system-ui, sans-serif'
  ctx.textAlign = 'center'

  for (let val = first; val <= endCanvas; val += step) {
    const screenY = val * z + pan + 24 - 24
    if (screenY < 0 || screenY > h) continue

    // Major tick
    ctx.strokeStyle = isDark ? '#52525b' : '#a1a1aa'
    ctx.lineWidth = 1
    ctx.beginPath()
    ctx.moveTo(w - 8, screenY)
    ctx.lineTo(w, screenY)
    ctx.stroke()

    // Label (rotated)
    ctx.save()
    ctx.translate(w - 10, screenY)
    ctx.rotate(-Math.PI / 2)
    ctx.fillText(Math.round(val).toString(), 0, 0)
    ctx.restore()

    // Minor ticks
    const minorStep = step / 5
    for (let m = 1; m < 5; m++) {
      const mv = val + m * minorStep
      const ms = mv * z + pan + 24 - 24
      if (ms < 0 || ms > h) continue
      ctx.strokeStyle = isDark ? '#3f3f46' : '#d4d4d8'
      ctx.lineWidth = 0.5
      ctx.beginPath()
      ctx.moveTo(w - 4, ms)
      ctx.lineTo(w, ms)
      ctx.stroke()
    }
  }
}

function redrawRulers() {
  drawHorizontalRuler()
  drawVerticalRuler()
}

// Redraw rulers when zoom/pan changes
let rulerRaf = null
watch([() => store.zoom, () => store.panX, () => store.panY, () => props.containerWidth, () => props.containerHeight, visible], () => {
  if (!visible.value) return
  if (rulerRaf) cancelAnimationFrame(rulerRaf)
  rulerRaf = requestAnimationFrame(redrawRulers)
}, { immediate: false })

// Watch for dark/light theme changes to redraw rulers with correct colors
let themeObserver = null

onMounted(() => {
  if (visible.value) nextTick(redrawRulers)

  // Observe class changes on <html> to detect dark/light theme toggle
  themeObserver = new MutationObserver(() => {
    if (visible.value) {
      if (rulerRaf) cancelAnimationFrame(rulerRaf)
      rulerRaf = requestAnimationFrame(redrawRulers)
    }
  })
  themeObserver.observe(document.documentElement, {
    attributes: true,
    attributeFilter: ['class']
  })
})

onUnmounted(() => {
  themeObserver?.disconnect()
})

watch(visible, (v) => {
  if (v) nextTick(redrawRulers)
})

// ─── Guide line styles ────────────────────────────

// Guide color — orange is visible on both light and dark backgrounds
const GUIDE_COLOR = '#f97316'

function guideStyle(guide) {
  const z = store.zoom || 1
  if (guide.axis === 'x') {
    const screenX = guide.position * z + (store.panX || 0)
    return {
      left: screenX + 'px',
      top: '0',
      width: '3px',
      height: '100%',
      marginLeft: '-1.5px',
      background: `linear-gradient(to bottom, transparent 0px, ${GUIDE_COLOR} 24px, ${GUIDE_COLOR} calc(100%), transparent 100%)`,
      opacity: draggingGuideId.value === guide.id ? 1 : 0.6,
      zIndex: 43,
    }
  } else {
    const screenY = guide.position * z + (store.panY || 0)
    return {
      top: screenY + 'px',
      left: '0',
      height: '3px',
      width: '100%',
      marginTop: '-1.5px',
      background: `linear-gradient(to right, transparent 0px, ${GUIDE_COLOR} 24px, ${GUIDE_COLOR} calc(100%), transparent 100%)`,
      opacity: draggingGuideId.value === guide.id ? 1 : 0.6,
      zIndex: 43,
    }
  }
}

function guideLabelStyle(guide) {
  if (guide.axis === 'x') {
    return { left: '6px', top: '28px' }
  } else {
    return { left: '28px', top: '-4px' }
  }
}

const previewGuideStyle = computed(() => {
  if (!previewGuide.value) return {}
  const { axis, screenPos } = previewGuide.value
  if (axis === 'x') {
    return {
      left: screenPos + 'px',
      top: '0',
      width: '1px',
      height: '100%',
      borderLeft: `1px dashed ${GUIDE_COLOR}`,
      opacity: 0.7,
      zIndex: 43,
    }
  } else {
    return {
      top: screenPos + 'px',
      left: '0',
      height: '1px',
      width: '100%',
      borderTop: `1px dashed ${GUIDE_COLOR}`,
      opacity: 0.7,
      zIndex: 43,
    }
  }
})

// ─── Drag from ruler to create guide ──────────────

function onHRulerMouseDown(e) {
  e.preventDefault()
  const startY = e.clientY
  let moved = false
  const rulerBottom = props.topOffset + 24 // ruler ends at this Y in container space

  const onMove = (ev) => {
    const dy = Math.abs(ev.clientY - startY)
    if (dy > 4) moved = true
    if (!moved) return
    previewGuide.value = { axis: 'y', screenPos: ev.clientY - getContainerTop() }
  }

  const onUp = (ev) => {
    document.removeEventListener('mousemove', onMove)
    document.removeEventListener('mouseup', onUp)
    if (moved) {
      const z = store.zoom || 1
      const containerTop = getContainerTop()
      const screenY = ev.clientY - containerTop
      const canvasY = (screenY - (store.panY || 0)) / z
      // If dropped back on ruler area, don't create
      if (screenY > rulerBottom) {
        store.addGuide('y', Math.round(canvasY))
      }
    }
    previewGuide.value = null
  }

  document.addEventListener('mousemove', onMove)
  document.addEventListener('mouseup', onUp)
}

function onVRulerMouseDown(e) {
  e.preventDefault()
  const startX = e.clientX
  let moved = false

  const onMove = (ev) => {
    const dx = Math.abs(ev.clientX - startX)
    if (dx > 4) moved = true
    if (!moved) return
    previewGuide.value = { axis: 'x', screenPos: ev.clientX - getContainerLeft() }
  }

  const onUp = (ev) => {
    document.removeEventListener('mousemove', onMove)
    document.removeEventListener('mouseup', onUp)
    if (moved) {
      const z = store.zoom || 1
      const containerLeft = getContainerLeft()
      const screenX = ev.clientX - containerLeft
      const canvasX = (screenX - (store.panX || 0)) / z
      if (screenX > 24) {
        store.addGuide('x', Math.round(canvasX))
      }
    }
    previewGuide.value = null
  }

  document.addEventListener('mousemove', onMove)
  document.addEventListener('mouseup', onUp)
}

// ─── Drag existing guide line ─────────────────────

function onGuideDragStart(e, guide) {
  e.preventDefault()
  draggingGuideId.value = guide.id
  hoveredGuideId.value = guide.id

  const onMove = (ev) => {
    const z = store.zoom || 1
    if (guide.axis === 'x') {
      const containerLeft = getContainerLeft()
      const screenX = ev.clientX - containerLeft
      const canvasX = (screenX - (store.panX || 0)) / z
      store.moveGuide(guide.id, canvasX)
    } else {
      const containerTop = getContainerTop()
      const screenY = ev.clientY - containerTop
      const canvasY = (screenY - (store.panY || 0)) / z
      store.moveGuide(guide.id, canvasY)
    }
    // Redraw rulers to show position
    if (rulerRaf) cancelAnimationFrame(rulerRaf)
    rulerRaf = requestAnimationFrame(redrawRulers)
  }

  const onUp = (ev) => {
    document.removeEventListener('mousemove', onMove)
    document.removeEventListener('mouseup', onUp)
    // If dragged back onto ruler, remove guide
    if (guide.axis === 'x') {
      const screenX = ev.clientX - getContainerLeft()
      if (screenX < 24) store.removeGuide(guide.id)
    } else {
      const screenY = ev.clientY - getContainerTop()
      if (screenY < props.topOffset + 24) store.removeGuide(guide.id)
    }
    draggingGuideId.value = null
    hoveredGuideId.value = null
  }

  document.addEventListener('mousemove', onMove)
  document.addEventListener('mouseup', onUp)
}

// ─── Helpers ──────────────────────────────────────

function getContainerTop() {
  const el = hRulerCanvas.value?.parentElement?.parentElement
  return el ? el.getBoundingClientRect().top : 0
}

function getContainerLeft() {
  const el = vRulerCanvas.value?.parentElement?.parentElement
  return el ? el.getBoundingClientRect().left : 0
}
</script>

