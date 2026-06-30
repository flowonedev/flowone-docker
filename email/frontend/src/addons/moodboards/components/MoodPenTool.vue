<template>
  <div class="absolute inset-0" style="z-index: 9998;" @mousedown.prevent="onMouseDown" @mousemove="onMouseMove" @dblclick.prevent="closePath" @contextmenu.prevent="closePath">
    <!-- SVG overlay for pen tool drawing — transforms like the canvas layer -->
    <svg
      class="absolute inset-0 w-full h-full pointer-events-none overflow-visible"
      :style="{
        transformOrigin: '0 0',
        transform: `translate(${panX}px, ${panY}px) scale(${zoom})`,
      }"
    >
      <!-- Completed path preview (filled shape) -->
      <path
        v-if="points.length >= 2"
        :d="pathD"
        :fill="isClosed ? fillColor : 'none'"
        :fill-opacity="isClosed ? 0.3 : 0"
        :stroke="strokeColor"
        :stroke-width="strokeWidth"
        stroke-linejoin="round"
        stroke-linecap="round"
      />

      <!-- Ghost line from last point to current mouse position -->
      <line
        v-if="points.length > 0 && !isClosed"
        :x1="points[points.length - 1].x"
        :y1="points[points.length - 1].y"
        :x2="ghostX"
        :y2="ghostY"
        :stroke="strokeColor"
        stroke-width="1"
        stroke-dasharray="6,4"
        opacity="0.5"
      />

      <!-- Ghost line from first point to current mouse position (when near closing) -->
      <line
        v-if="points.length >= 2 && !isClosed && isNearFirstPoint"
        :x1="points[0].x"
        :y1="points[0].y"
        :x2="ghostX"
        :y2="ghostY"
        :stroke="strokeColor"
        stroke-width="1"
        stroke-dasharray="6,4"
        opacity="0.3"
      />

      <!-- Anchor points -->
      <g v-for="(pt, idx) in points" :key="'pt-' + idx">
        <!-- Control handle lines -->
        <template v-if="pt.cp1">
          <line
            :x1="pt.x"
            :y1="pt.y"
            :x2="pt.cp1.x"
            :y2="pt.cp1.y"
            stroke="#06b6d4"
            stroke-width="1"
            opacity="0.6"
          />
          <circle
            :cx="pt.cp1.x"
            :cy="pt.cp1.y"
            r="4"
            fill="#06b6d4"
            class="pointer-events-auto cursor-move"
            @mousedown.prevent.stop="startDragHandle(idx, 'cp1', $event)"
          />
        </template>
        <template v-if="pt.cp2">
          <line
            :x1="pt.x"
            :y1="pt.y"
            :x2="pt.cp2.x"
            :y2="pt.cp2.y"
            stroke="#06b6d4"
            stroke-width="1"
            opacity="0.6"
          />
          <circle
            :cx="pt.cp2.x"
            :cy="pt.cp2.y"
            r="4"
            fill="#06b6d4"
            class="pointer-events-auto cursor-move"
            @mousedown.prevent.stop="startDragHandle(idx, 'cp2', $event)"
          />
        </template>

        <!-- Anchor point (first point highlighted when near for closing) -->
        <circle
          :cx="pt.x"
          :cy="pt.y"
          :r="(idx === 0 && isNearFirstPoint && points.length >= 2) ? 7 : 5"
          :fill="idx === 0 && isNearFirstPoint && points.length >= 2 ? '#06b6d4' : '#ffffff'"
          :stroke="idx === 0 ? '#06b6d4' : '#8b5cf6'"
          stroke-width="2"
          class="pointer-events-auto cursor-move"
          @mousedown.prevent.stop="startDragPoint(idx, $event)"
        />
      </g>
    </svg>

    <!-- Floating toolbar for pen settings -->
    <div
      class="absolute top-4 left-1/2 -translate-x-1/2 flex items-center gap-2 px-4 py-2 bg-white dark:bg-surface-800 rounded-2xl shadow-lg border border-surface-200 dark:border-surface-700 z-10"
      @mousedown.stop
    >
      <!-- Fill color -->
      <label class="flex items-center gap-1.5 cursor-pointer" title="Fill color">
        <span class="material-symbols-rounded text-sm text-surface-500">format_color_fill</span>
        <div class="w-6 h-6 rounded-md border border-surface-300 dark:border-surface-600 cursor-pointer relative overflow-hidden" :style="{ backgroundColor: fillColor }">
          <input type="color" v-model="fillColor" class="absolute inset-0 w-full h-full opacity-0 cursor-pointer" />
        </div>
      </label>

      <!-- Stroke color -->
      <label class="flex items-center gap-1.5 cursor-pointer" title="Stroke color">
        <span class="material-symbols-rounded text-sm text-surface-500">border_color</span>
        <div class="w-6 h-6 rounded-md border border-surface-300 dark:border-surface-600 cursor-pointer relative overflow-hidden" :style="{ backgroundColor: strokeColor }">
          <input type="color" v-model="strokeColor" class="absolute inset-0 w-full h-full opacity-0 cursor-pointer" />
        </div>
      </label>

      <!-- Stroke width -->
      <div class="flex items-center gap-1.5">
        <span class="material-symbols-rounded text-sm text-surface-500">line_weight</span>
        <input type="range" v-model.number="strokeWidth" min="1" max="10" step="0.5" class="w-16 h-1 accent-primary-500" />
        <span class="text-[10px] text-surface-400 w-6 text-center">{{ strokeWidth }}</span>
      </div>

      <!-- Divider -->
      <div class="w-px h-5 bg-surface-200 dark:bg-surface-700"></div>

      <!-- Close path -->
      <button
        @click="closePath"
        :disabled="points.length < 3"
        class="text-xs px-2.5 py-1 rounded-lg bg-cyan-500 text-white hover:bg-cyan-600 disabled:opacity-40 disabled:cursor-not-allowed transition-colors"
        title="Close path & create shape (double-click)"
      >
        Close Path
      </button>

      <!-- Cancel -->
      <button
        @click="$emit('cancel')"
        class="text-xs px-2.5 py-1 rounded-lg text-surface-500 hover:bg-surface-100 dark:hover:bg-surface-700 transition-colors"
      >
        Cancel
      </button>

      <span class="text-[10px] text-surface-400 ml-1">
        {{ points.length }} points | Click to add, drag for curves, double-click to close
      </span>
    </div>
  </div>
</template>

<script setup>
import { ref, computed } from 'vue'

const props = defineProps({
  zoom: { type: Number, default: 1 },
  panX: { type: Number, default: 0 },
  panY: { type: Number, default: 0 },
})

const emit = defineEmits(['create-shape', 'cancel'])

const points = ref([])         // Array of { x, y, cp1?: {x,y}, cp2?: {x,y} }
const ghostX = ref(0)
const ghostY = ref(0)
const isClosed = ref(false)
const fillColor = ref('#6366f1')
const strokeColor = ref('#4f46e5')
const strokeWidth = ref(2)

// Dragging state
const dragging = ref(null)     // { type: 'point'|'cp1'|'cp2', index: number }
const dragStart = ref(null)    // { startX, startY }
const wasDragged = ref(false)  // Track if mouse moved after mousedown (for click vs drag)

const CLOSE_THRESHOLD = 15 // px screen distance to snap-close the path

const isNearFirstPoint = computed(() => {
  if (points.value.length < 2) return false
  const first = points.value[0]
  const dx = (ghostX.value - first.x) * props.zoom
  const dy = (ghostY.value - first.y) * props.zoom
  return Math.sqrt(dx * dx + dy * dy) < CLOSE_THRESHOLD
})

// Build the SVG path data string
const pathD = computed(() => {
  const pts = points.value
  if (pts.length === 0) return ''

  let d = `M ${pts[0].x} ${pts[0].y}`

  for (let i = 1; i < pts.length; i++) {
    const prev = pts[i - 1]
    const curr = pts[i]

    if (prev.cp2 || curr.cp1) {
      // Cubic bezier
      const cp1x = prev.cp2 ? prev.cp2.x : prev.x
      const cp1y = prev.cp2 ? prev.cp2.y : prev.y
      const cp2x = curr.cp1 ? curr.cp1.x : curr.x
      const cp2y = curr.cp1 ? curr.cp1.y : curr.y
      d += ` C ${cp1x} ${cp1y}, ${cp2x} ${cp2y}, ${curr.x} ${curr.y}`
    } else {
      // Straight line
      d += ` L ${curr.x} ${curr.y}`
    }
  }

  if (isClosed.value && pts.length >= 3) {
    const last = pts[pts.length - 1]
    const first = pts[0]
    if (last.cp2 || first.cp1) {
      const cp1x = last.cp2 ? last.cp2.x : last.x
      const cp1y = last.cp2 ? last.cp2.y : last.y
      const cp2x = first.cp1 ? first.cp1.x : first.x
      const cp2y = first.cp1 ? first.cp1.y : first.y
      d += ` C ${cp1x} ${cp1y}, ${cp2x} ${cp2y}, ${first.x} ${first.y}`
    } else {
      d += ' Z'
    }
  }

  return d
})

// Convert mouse event to canvas (world) coordinates
// Using the same formula as MoodCanvas: (clientX - rect.left - panX) / zoom
function toCanvas(e) {
  const el = e.currentTarget?.closest?.('[style*="z-index: 9998"]') || e.currentTarget
  const rect = el ? el.getBoundingClientRect() : { left: 0, top: 0 }
  return {
    x: (e.clientX - rect.left - props.panX) / props.zoom,
    y: (e.clientY - rect.top - props.panY) / props.zoom,
  }
}

function onMouseDown(e) {
  if (e.button !== 0) return // left-click only
  if (isClosed.value) return

  const pos = toCanvas(e)
  wasDragged.value = false

  // Check if clicking near first point to close
  if (isNearFirstPoint.value && points.value.length >= 3) {
    closePath()
    return
  }

  // Add new anchor point
  const newPt = { x: pos.x, y: pos.y, cp1: null, cp2: null }
  points.value.push(newPt)

  // Start drag to create bezier handles
  const idx = points.value.length - 1
  dragStart.value = { x: e.clientX, y: e.clientY }

  const onMove = (me) => {
    const dx = me.clientX - dragStart.value.x
    const dy = me.clientY - dragStart.value.y
    if (Math.abs(dx) > 3 || Math.abs(dy) > 3) {
      wasDragged.value = true
    }
    if (!wasDragged.value) return

    const mpos = toCanvasFromClient(me)
    // Create symmetric bezier handles
    const px = points.value[idx].x
    const py = points.value[idx].y
    points.value[idx].cp2 = { x: mpos.x, y: mpos.y }
    // Mirror the control point on the opposite side
    points.value[idx].cp1 = { x: 2 * px - mpos.x, y: 2 * py - mpos.y }
  }

  const onUp = () => {
    document.removeEventListener('mousemove', onMove)
    document.removeEventListener('mouseup', onUp)
    dragStart.value = null
  }

  document.addEventListener('mousemove', onMove)
  document.addEventListener('mouseup', onUp)
}

function toCanvasFromClient(e) {
  const container = document.querySelector('[style*="z-index: 9998"]')
  if (!container) return { x: 0, y: 0 }
  const rect = container.getBoundingClientRect()
  return {
    x: (e.clientX - rect.left - props.panX) / props.zoom,
    y: (e.clientY - rect.top - props.panY) / props.zoom,
  }
}

function onMouseMove(e) {
  const pos = toCanvas(e)
  ghostX.value = pos.x
  ghostY.value = pos.y
}

function startDragPoint(index, e) {
  e.preventDefault()
  const onMove = (me) => {
    const pos = toCanvasFromClient(me)
    const dx = pos.x - points.value[index].x
    const dy = pos.y - points.value[index].y
    points.value[index].x = pos.x
    points.value[index].y = pos.y
    // Move control points with the anchor
    if (points.value[index].cp1) {
      points.value[index].cp1.x += dx
      points.value[index].cp1.y += dy
    }
    if (points.value[index].cp2) {
      points.value[index].cp2.x += dx
      points.value[index].cp2.y += dy
    }
  }
  const onUp = () => {
    document.removeEventListener('mousemove', onMove)
    document.removeEventListener('mouseup', onUp)
  }
  document.addEventListener('mousemove', onMove)
  document.addEventListener('mouseup', onUp)
}

function startDragHandle(index, handleKey, e) {
  e.preventDefault()
  const onMove = (me) => {
    const pos = toCanvasFromClient(me)
    points.value[index][handleKey] = { x: pos.x, y: pos.y }
  }
  const onUp = () => {
    document.removeEventListener('mousemove', onMove)
    document.removeEventListener('mouseup', onUp)
  }
  document.addEventListener('mousemove', onMove)
  document.addEventListener('mouseup', onUp)
}

function closePath() {
  if (points.value.length < 3) return
  isClosed.value = true

  // Calculate bounding box
  let minX = Infinity, minY = Infinity, maxX = -Infinity, maxY = -Infinity
  for (const pt of points.value) {
    minX = Math.min(minX, pt.x)
    minY = Math.min(minY, pt.y)
    maxX = Math.max(maxX, pt.x)
    maxY = Math.max(maxY, pt.y)
    if (pt.cp1) {
      minX = Math.min(minX, pt.cp1.x)
      minY = Math.min(minY, pt.cp1.y)
      maxX = Math.max(maxX, pt.cp1.x)
      maxY = Math.max(maxY, pt.cp1.y)
    }
    if (pt.cp2) {
      minX = Math.min(minX, pt.cp2.x)
      minY = Math.min(minY, pt.cp2.y)
      maxX = Math.max(maxX, pt.cp2.x)
      maxY = Math.max(maxY, pt.cp2.y)
    }
  }

  const width = maxX - minX || 100
  const height = maxY - minY || 100

  // Normalize points relative to bounding box (0-1 range) for resolution independence
  const normalizedPoints = points.value.map(pt => {
    const norm = {
      x: (pt.x - minX) / width,
      y: (pt.y - minY) / height,
    }
    if (pt.cp1) {
      norm.cp1 = { x: (pt.cp1.x - minX) / width, y: (pt.cp1.y - minY) / height }
    }
    if (pt.cp2) {
      norm.cp2 = { x: (pt.cp2.x - minX) / width, y: (pt.cp2.y - minY) / height }
    }
    return norm
  })

  // Build the final SVG path (normalized to 100x100 viewbox for storage)
  const svgPath = buildSvgPath(normalizedPoints, 100, 100)

  emit('create-shape', {
    pos_x: minX,
    pos_y: minY,
    width: Math.max(width, 40),
    height: Math.max(height, 40),
    pathData: normalizedPoints,
    svgPath,
    fillColor: fillColor.value,
    strokeColor: strokeColor.value,
    strokeWidth: strokeWidth.value,
  })

  // Reset state
  points.value = []
  isClosed.value = false
}

function buildSvgPath(pts, w, h) {
  if (pts.length === 0) return ''
  let d = `M ${pts[0].x * w} ${pts[0].y * h}`

  for (let i = 1; i < pts.length; i++) {
    const prev = pts[i - 1]
    const curr = pts[i]
    if (prev.cp2 || curr.cp1) {
      const cp1x = prev.cp2 ? prev.cp2.x * w : prev.x * w
      const cp1y = prev.cp2 ? prev.cp2.y * h : prev.y * h
      const cp2x = curr.cp1 ? curr.cp1.x * w : curr.x * w
      const cp2y = curr.cp1 ? curr.cp1.y * h : curr.y * h
      d += ` C ${cp1x} ${cp1y}, ${cp2x} ${cp2y}, ${curr.x * w} ${curr.y * h}`
    } else {
      d += ` L ${curr.x * w} ${curr.y * h}`
    }
  }

  // Close
  const last = pts[pts.length - 1]
  const first = pts[0]
  if (last.cp2 || first.cp1) {
    const cp1x = last.cp2 ? last.cp2.x * w : last.x * w
    const cp1y = last.cp2 ? last.cp2.y * h : last.y * h
    const cp2x = first.cp1 ? first.cp1.x * w : first.x * w
    const cp2y = first.cp1 ? first.cp1.y * h : first.y * h
    d += ` C ${cp1x} ${cp1y}, ${cp2x} ${cp2y}, ${first.x * w} ${first.y * h}`
  }
  d += ' Z'
  return d
}
</script>

