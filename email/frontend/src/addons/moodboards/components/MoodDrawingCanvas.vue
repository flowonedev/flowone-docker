<template>
  <div
    class="fixed inset-0 z-[60] flex flex-col bg-white dark:bg-surface-900"
    ref="wrapper"
    tabindex="0"
    @keydown="onKeyDown"
  >
    <!-- Drawing Toolbar -->
    <div class="flex items-center gap-3 px-4 py-2.5 bg-white dark:bg-surface-800 border-b border-surface-200 dark:border-surface-700 shadow-sm flex-shrink-0">
      <!-- Tool buttons -->
      <div class="flex items-center gap-0.5 bg-surface-100 dark:bg-surface-700 rounded-xl p-1">
        <button
          v-for="t in toolList"
          :key="t.id"
          @click="currentTool = t.id"
          :class="[
            'p-2 rounded-lg transition-colors relative group',
            currentTool === t.id
              ? 'bg-primary-500 text-white shadow-sm'
              : 'text-surface-600 dark:text-surface-400 hover:bg-surface-200 dark:hover:bg-surface-600'
          ]"
          :title="t.label"
        >
          <span class="material-symbols-rounded text-lg">{{ t.icon }}</span>
          <span class="absolute -bottom-7 left-1/2 -translate-x-1/2 text-[10px] bg-surface-800 text-white px-1.5 py-0.5 rounded whitespace-nowrap opacity-0 group-hover:opacity-100 transition-opacity pointer-events-none z-10">
            {{ t.label }}
          </span>
        </button>
      </div>

      <!-- Divider -->
      <div class="w-px h-7 bg-surface-200 dark:bg-surface-600"></div>

      <!-- Color picker -->
      <div class="flex items-center gap-2">
        <MoodColorPicker
          :model-value="penColor"
          @update:model-value="penColor = $event"
          label="Drawing color"
          :show-caret="false"
          dropdown-position="top-full left-0"
        />
        <!-- Quick colors -->
        <div class="flex gap-1">
          <button
            v-for="c in quickColors"
            :key="c"
            @click="penColor = c"
            class="w-5 h-5 rounded-full border border-surface-300 dark:border-surface-600 hover:scale-110 transition-transform"
            :style="{ backgroundColor: c }"
            :class="{ 'ring-2 ring-primary-500 ring-offset-1': penColor === c }"
          />
        </div>
      </div>

      <!-- Divider -->
      <div class="w-px h-7 bg-surface-200 dark:bg-surface-600"></div>

      <!-- Stroke width -->
      <div class="flex items-center gap-2">
        <span class="material-symbols-rounded text-sm text-surface-500">line_weight</span>
        <input
          type="range"
          v-model.number="strokeWidth"
          min="1"
          max="40"
          class="w-24 accent-primary-500"
        />
        <span class="text-xs font-mono text-surface-500 w-5 text-right">{{ strokeWidth }}</span>
      </div>

      <!-- Divider -->
      <div class="w-px h-7 bg-surface-200 dark:bg-surface-600"></div>

      <!-- Freehand style controls -->
      <div class="flex items-center gap-3">
        <div class="flex items-center gap-1.5" title="Thinning — how much stroke narrows with speed">
          <span class="text-[10px] text-surface-400 uppercase tracking-wider font-medium w-6">Thin</span>
          <input
            type="range"
            v-model.number="thinning"
            min="0"
            max="1"
            step="0.05"
            class="w-16 accent-primary-500"
          />
        </div>
        <div class="flex items-center gap-1.5" title="Smoothing — stroke path smoothness">
          <span class="text-[10px] text-surface-400 uppercase tracking-wider font-medium w-6">Smth</span>
          <input
            type="range"
            v-model.number="smoothing"
            min="0"
            max="1"
            step="0.05"
            class="w-16 accent-primary-500"
          />
        </div>
        <div class="flex items-center gap-1.5" title="Streamline — how much cursor jitter is absorbed">
          <span class="text-[10px] text-surface-400 uppercase tracking-wider font-medium w-6">Strm</span>
          <input
            type="range"
            v-model.number="streamline"
            min="0"
            max="1"
            step="0.05"
            class="w-16 accent-primary-500"
          />
        </div>
        <button
          @click="taperEnabled = !taperEnabled"
          :class="[
            'px-2 py-1 rounded-lg text-[10px] uppercase tracking-wider font-medium transition-colors',
            taperEnabled
              ? 'bg-primary-100 dark:bg-primary-900/30 text-primary-600 dark:text-primary-400'
              : 'text-surface-400 hover:bg-surface-100 dark:hover:bg-surface-700'
          ]"
          title="Toggle taper (smooth start/end)"
        >
          Taper
        </button>
      </div>

      <!-- Divider -->
      <div class="w-px h-7 bg-surface-200 dark:bg-surface-600"></div>

      <!-- Undo / Redo / Clear -->
      <div class="flex items-center gap-0.5">
        <button
          @click="undo"
          :disabled="historyIndex < 1"
          :class="[
            'p-2 rounded-lg transition-colors',
            historyIndex < 1
              ? 'text-surface-300 dark:text-surface-600 cursor-not-allowed'
              : 'text-surface-600 dark:text-surface-400 hover:bg-surface-100 dark:hover:bg-surface-700'
          ]"
          title="Undo (Ctrl+Z)"
        >
          <span class="material-symbols-rounded text-lg">undo</span>
        </button>
        <button
          @click="redo"
          :disabled="historyIndex >= history.length - 1"
          :class="[
            'p-2 rounded-lg transition-colors',
            historyIndex >= history.length - 1
              ? 'text-surface-300 dark:text-surface-600 cursor-not-allowed'
              : 'text-surface-600 dark:text-surface-400 hover:bg-surface-100 dark:hover:bg-surface-700'
          ]"
          title="Redo (Ctrl+Y)"
        >
          <span class="material-symbols-rounded text-lg">redo</span>
        </button>
        <button
          @click="clearCanvas"
          class="p-2 rounded-lg text-surface-600 dark:text-surface-400 hover:bg-red-50 dark:hover:bg-red-900/20 hover:text-red-500 transition-colors"
          title="Clear all"
        >
          <span class="material-symbols-rounded text-lg">delete_sweep</span>
        </button>
      </div>

      <!-- Spacer -->
      <div class="flex-1"></div>

      <!-- Pressure indicator -->
      <div v-if="hasPressure" class="flex items-center gap-1.5 text-xs text-green-500" title="Stylus pressure detected">
        <span class="material-symbols-rounded text-sm">stylus</span>
        <span class="font-medium">Pressure</span>
      </div>

      <!-- Save / Discard -->
      <div class="flex items-center gap-2">
        <button
          @click="discard"
          class="px-4 py-1.5 rounded-full text-sm font-medium text-surface-600 dark:text-surface-400 hover:bg-surface-100 dark:hover:bg-surface-700 border border-surface-300 dark:border-surface-600 transition-colors"
        >
          Discard
        </button>
        <button
          @click="save"
          class="px-4 py-1.5 rounded-full text-sm font-medium bg-primary-500 text-white hover:bg-primary-600 transition-colors shadow-sm"
          :disabled="saving"
        >
          <span v-if="saving" class="flex items-center gap-1.5">
            <span class="material-symbols-rounded text-sm animate-spin">sync</span>
            Saving...
          </span>
          <span v-else>Save Drawing</span>
        </button>
      </div>
    </div>

    <!-- Canvas area -->
    <div
      class="flex-1 relative overflow-hidden"
      ref="canvasWrapper"
      :style="{ cursor: canvasCursor }"
    >
      <!-- Checkerboard pattern for transparency preview -->
      <div class="absolute inset-0 opacity-10" style="background-image: linear-gradient(45deg, #ccc 25%, transparent 25%), linear-gradient(-45deg, #ccc 25%, transparent 25%), linear-gradient(45deg, transparent 75%, #ccc 75%), linear-gradient(-45deg, transparent 75%, #ccc 75%); background-size: 20px 20px; background-position: 0 0, 0 10px, 10px -10px, -10px 0px;"></div>

      <canvas
        ref="canvas"
        @pointerdown="onPointerDown"
        @pointermove="onPointerMove"
        @pointerup="onPointerUp"
        @pointerleave="onPointerUp"
        class="absolute inset-0"
        style="touch-action: none;"
      />

      <!-- Eraser cursor preview -->
      <div
        v-if="currentTool === 'eraser' && cursorPos"
        class="absolute pointer-events-none rounded-full border-2 border-red-400/60"
        :style="{
          left: (cursorPos.x - strokeWidth / 2) + 'px',
          top: (cursorPos.y - strokeWidth / 2) + 'px',
          width: strokeWidth + 'px',
          height: strokeWidth + 'px'
        }"
      />

      <!-- Pen cursor preview -->
      <div
        v-if="currentTool === 'pen' && cursorPos"
        class="absolute pointer-events-none rounded-full"
        :style="{
          left: (cursorPos.x - strokeWidth / 2) + 'px',
          top: (cursorPos.y - strokeWidth / 2) + 'px',
          width: Math.max(4, strokeWidth) + 'px',
          height: Math.max(4, strokeWidth) + 'px',
          backgroundColor: penColor + '60',
          border: '1px solid ' + penColor
        }"
      />
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, onUnmounted, nextTick } from 'vue'
import { getStroke } from 'perfect-freehand'
import MoodColorPicker from './MoodColorPicker.vue'

const props = defineProps({
  /** Optional existing drawing data (JSON string) for editing */
  initialData: { type: String, default: null },
  /** Initial canvas width */
  canvasWidth: { type: Number, default: null },
  /** Initial canvas height */
  canvasHeight: { type: Number, default: null }
})

const emit = defineEmits(['save', 'discard'])

// Refs
const wrapper = ref(null)
const canvasWrapper = ref(null)
const canvas = ref(null)
const cursorPos = ref(null)
const saving = ref(false)
const hasPressure = ref(false)

// Tools
const TOOLS = {
  POINTER: 'pointer',
  PEN: 'pen',
  ERASER: 'eraser',
  FILL: 'fill'
}

const toolList = [
  { id: TOOLS.POINTER, icon: 'arrow_selector_tool', label: 'Pointer' },
  { id: TOOLS.PEN, icon: 'draw', label: 'Pen (P)' },
  { id: TOOLS.ERASER, icon: 'ink_eraser', label: 'Eraser (E)' },
  { id: TOOLS.FILL, icon: 'format_color_fill', label: 'Fill (F)' },
]

const quickColors = [
  '#000000', '#ffffff', '#ef4444', '#f97316', '#eab308',
  '#22c55e', '#3b82f6', '#8b5cf6', '#ec4899', '#6b7280'
]

// State
const currentTool = ref(TOOLS.PEN)
const penColor = ref('#000000')
const strokeWidth = ref(8)
const isDrawing = ref(false)

// Freehand style options
const thinning = ref(0.5)
const smoothing = ref(0.7)
const streamline = ref(0.6)
const taperEnabled = ref(true)

// History (array of stroke objects for replay)
const strokes = ref([])
const currentStrokePoints = ref([])
const historyIndex = ref(-1)
const history = ref([])

// Snapshot of canvas before the current in-progress stroke
let preStrokeSnapshot = null

// Canvas cursor
const canvasCursor = computed(() => {
  switch (currentTool.value) {
    case TOOLS.POINTER: return 'default'
    case TOOLS.PEN: return 'crosshair'
    case TOOLS.ERASER: return 'none'
    case TOOLS.FILL: return 'crosshair'
    default: return 'default'
  }
})

// ========================================
// PERFECT-FREEHAND HELPERS
// ========================================

/**
 * Build the options object for getStroke() from current UI state.
 */
function getFreehandOptions(size) {
  return {
    size: size || strokeWidth.value,
    thinning: thinning.value,
    smoothing: smoothing.value,
    streamline: streamline.value,
    easing: (t) => Math.sin((t * Math.PI) / 2),  // sine ease-out for natural feel
    start: {
      taper: taperEnabled.value ? size * 0.5 : 0,
      easing: (t) => t * t,  // ease-in at start
      cap: true
    },
    end: {
      taper: taperEnabled.value ? size * 0.5 : 0,
      easing: (t) => 1 - (1 - t) * (1 - t),  // ease-out at end
      cap: true
    },
    simulatePressure: !hasPressure.value
  }
}

/**
 * Convert getStroke() outline to an SVG fill-path string (standard perfect-freehand approach).
 * Uses midpoint quadratic curves with proper wrap-around for seamless closure.
 */
function outlineToSvgPath(outline) {
  if (!outline || outline.length < 2) return ''
  const len = outline.length
  let d = `M ${outline[0][0].toFixed(2)} ${outline[0][1].toFixed(2)} Q`
  for (let i = 0; i < len; i++) {
    const [x0, y0] = outline[i]
    const [x1, y1] = outline[(i + 1) % len]
    d += ` ${x0.toFixed(2)} ${y0.toFixed(2)} ${((x0 + x1) / 2).toFixed(2)} ${((y0 + y1) / 2).toFixed(2)}`
  }
  d += ' Z'
  return d
}

/**
 * Convert getStroke() outline points into a Canvas Path2D using smooth quadratic curves.
 */
function outlineToPath2D(outline) {
  if (!outline || outline.length < 2) return null

  const path = new Path2D()
  const [startX, startY] = outline[0]
  path.moveTo(startX, startY)

  for (let i = 1; i < outline.length; i++) {
    const [x0, y0] = outline[i - 1]
    const [x1, y1] = outline[i]
    // Use midpoints as control points for smooth quadratic curves
    const mx = (x0 + x1) / 2
    const my = (y0 + y1) / 2
    path.quadraticCurveTo(x0, y0, mx, my)
  }

  // Close back to the start with a final curve
  const [lastX, lastY] = outline[outline.length - 1]
  path.quadraticCurveTo(lastX, lastY, startX, startY)
  path.closePath()

  return path
}

/**
 * Render a single perfect-freehand stroke onto a canvas context.
 * Points must be [[x, y, pressure], ...] tuples.
 */
function drawFreehandStroke(ctx, points, color, size, options, isEraser) {
  if (!points || points.length === 0) return

  const outline = getStroke(points, options || getFreehandOptions(size))
  if (!outline || outline.length < 2) return

  const path = outlineToPath2D(outline)
  if (!path) return

  ctx.save()
  if (isEraser) {
    ctx.globalCompositeOperation = 'destination-out'
    ctx.fillStyle = 'rgba(0,0,0,1)'
  } else {
    ctx.globalCompositeOperation = 'source-over'
    ctx.fillStyle = color || '#000000'
  }
  ctx.fill(path)
  ctx.restore()
}

// ========================================
// CANVAS SETUP
// ========================================

function initCanvas() {
  if (!canvas.value || !canvasWrapper.value) return

  const rect = canvasWrapper.value.getBoundingClientRect()
  const dpr = window.devicePixelRatio || 1

  canvas.value.width = rect.width * dpr
  canvas.value.height = rect.height * dpr
  canvas.value.style.width = rect.width + 'px'
  canvas.value.style.height = rect.height + 'px'

  const ctx = canvas.value.getContext('2d')
  ctx.scale(dpr, dpr)

  // White background
  ctx.fillStyle = '#ffffff'
  ctx.fillRect(0, 0, rect.width, rect.height)

  // Load initial data if editing existing drawing
  if (props.initialData) {
    loadDrawingData(props.initialData)
  }

  // Save initial state
  saveToHistory()
}

function loadDrawingData(jsonStr) {
  try {
    const data = typeof jsonStr === 'string' ? JSON.parse(jsonStr) : jsonStr
    if (data.strokes && Array.isArray(data.strokes)) {
      strokes.value = data.strokes
      replayStrokes()
    }
  } catch (e) {
    console.error('Failed to load drawing data:', e)
  }
}

// ========================================
// POINTER EVENTS (replacing mouse events)
// ========================================

function getPos(e) {
  const rect = canvas.value.getBoundingClientRect()
  return {
    x: e.clientX - rect.left,
    y: e.clientY - rect.top
  }
}

function onPointerDown(e) {
  if (e.button !== 0) return

  const pos = getPos(e)

  // Detect real pressure support (stylus/pen)
  if (e.pressure > 0 && e.pressure !== 0.5) {
    hasPressure.value = true
  }

  if (currentTool.value === TOOLS.POINTER) return

  if (currentTool.value === TOOLS.FILL) {
    performFill(pos.x, pos.y)
    return
  }

  // Capture the pointer for smooth tracking even if cursor leaves canvas
  canvas.value.setPointerCapture(e.pointerId)

  isDrawing.value = true
  const pressure = e.pressure || 0.5
  currentStrokePoints.value = [[pos.x, pos.y, pressure]]

  // Save snapshot of canvas state before this stroke
  const ctx = canvas.value.getContext('2d')
  preStrokeSnapshot = ctx.getImageData(0, 0, canvas.value.width, canvas.value.height)
}

function onPointerMove(e) {
  const pos = getPos(e)
  cursorPos.value = pos

  if (!isDrawing.value) return

  const pressure = e.pressure || 0.5
  if (pressure > 0 && pressure !== 0.5) {
    hasPressure.value = true
  }

  // Interpolate between last point and current to fill gaps for smoother curves
  const pts = currentStrokePoints.value
  if (pts.length > 0) {
    const [lx, ly, lp] = pts[pts.length - 1]
    const dx = pos.x - lx
    const dy = pos.y - ly
    const dist = Math.sqrt(dx * dx + dy * dy)
    // If the gap is larger than 3px, insert intermediate points
    const step = 3
    if (dist > step) {
      const steps = Math.floor(dist / step)
      for (let i = 1; i < steps; i++) {
        const t = i / steps
        pts.push([
          lx + dx * t,
          ly + dy * t,
          lp + (pressure - lp) * t
        ])
      }
    }
  }

  currentStrokePoints.value.push([pos.x, pos.y, pressure])

  // Restore the pre-stroke snapshot, then re-render the full in-progress stroke
  const ctx = canvas.value.getContext('2d')
  if (preStrokeSnapshot) {
    ctx.putImageData(preStrokeSnapshot, 0, 0)
  }

  const isEraser = currentTool.value === TOOLS.ERASER
  drawFreehandStroke(
    ctx,
    currentStrokePoints.value,
    penColor.value,
    strokeWidth.value,
    getFreehandOptions(strokeWidth.value),
    isEraser
  )
}

function onPointerUp(e) {
  if (!isDrawing.value) return
  isDrawing.value = false

  // Release pointer capture
  if (e && e.pointerId != null && canvas.value) {
    try { canvas.value.releasePointerCapture(e.pointerId) } catch (_) {}
  }

  const ctx = canvas.value.getContext('2d')

  // Handle single click (dot) — still generate a tiny stroke via perfect-freehand
  if (currentStrokePoints.value.length === 1) {
    const [x, y, p] = currentStrokePoints.value[0]
    // Add a tiny offset so getStroke has 2 points to work with
    currentStrokePoints.value.push([x + 0.1, y + 0.1, p])
  }

  // Final render on canvas (restore snapshot + draw finalized stroke)
  if (preStrokeSnapshot) {
    ctx.putImageData(preStrokeSnapshot, 0, 0)
  }

  const isEraser = currentTool.value === TOOLS.ERASER
  const opts = getFreehandOptions(strokeWidth.value)

  drawFreehandStroke(
    ctx,
    currentStrokePoints.value,
    penColor.value,
    strokeWidth.value,
    opts,
    isEraser
  )

  // Store stroke data
  const stroke = {
    tool: currentTool.value,
    points: [...currentStrokePoints.value],
    color: penColor.value,
    width: strokeWidth.value,
    options: {
      thinning: thinning.value,
      smoothing: smoothing.value,
      streamline: streamline.value,
      taperEnabled: taperEnabled.value,
      simulatePressure: !hasPressure.value
    }
  }

  // Truncate future redo states
  strokes.value = strokes.value.slice(0, historyIndex.value + 1)
  history.value = history.value.slice(0, historyIndex.value + 1)

  strokes.value.push(stroke)
  saveToHistory()

  currentStrokePoints.value = []
  preStrokeSnapshot = null
}

// ========================================
// FLOOD FILL (unchanged — pixel-based)
// ========================================

function performFill(x, y) {
  const ctx = canvas.value.getContext('2d')
  const dpr = window.devicePixelRatio || 1
  const pixelX = Math.floor(x * dpr)
  const pixelY = Math.floor(y * dpr)
  const w = canvas.value.width
  const h = canvas.value.height

  const imageData = ctx.getImageData(0, 0, w, h)
  const data = imageData.data

  const startIdx = (pixelY * w + pixelX) * 4
  if (startIdx < 0 || startIdx >= data.length) return

  const targetR = data[startIdx]
  const targetG = data[startIdx + 1]
  const targetB = data[startIdx + 2]
  const targetA = data[startIdx + 3]

  const fill = hexToRgb(penColor.value)
  if (!fill) return

  // Skip if target is same as fill
  if (targetR === fill.r && targetG === fill.g && targetB === fill.b && targetA === 255) return

  const tolerance = 32

  function matches(idx) {
    return Math.abs(data[idx] - targetR) <= tolerance &&
           Math.abs(data[idx + 1] - targetG) <= tolerance &&
           Math.abs(data[idx + 2] - targetB) <= tolerance &&
           Math.abs(data[idx + 3] - targetA) <= tolerance
  }

  const stack = [pixelX, pixelY]
  const visited = new Uint8Array(w * h)

  while (stack.length > 0) {
    const cy = stack.pop()
    const cx = stack.pop()

    if (cx < 0 || cx >= w || cy < 0 || cy >= h) continue

    const key = cy * w + cx
    if (visited[key]) continue
    visited[key] = 1

    const idx = key * 4
    if (!matches(idx)) continue

    data[idx] = fill.r
    data[idx + 1] = fill.g
    data[idx + 2] = fill.b
    data[idx + 3] = 255

    stack.push(cx + 1, cy)
    stack.push(cx - 1, cy)
    stack.push(cx, cy + 1)
    stack.push(cx, cy - 1)
  }

  ctx.putImageData(imageData, 0, 0)

  // Record fill as a stroke
  const stroke = {
    tool: 'fill',
    x: x,
    y: y,
    color: penColor.value,
    width: strokeWidth.value
  }

  strokes.value = strokes.value.slice(0, historyIndex.value + 1)
  history.value = history.value.slice(0, historyIndex.value + 1)

  strokes.value.push(stroke)
  saveToHistory()
}

function hexToRgb(hex) {
  const result = /^#?([a-f\d]{2})([a-f\d]{2})([a-f\d]{2})$/i.exec(hex)
  return result ? {
    r: parseInt(result[1], 16),
    g: parseInt(result[2], 16),
    b: parseInt(result[3], 16)
  } : null
}

// ========================================
// HISTORY (UNDO/REDO)
// ========================================

function saveToHistory() {
  const ctx = canvas.value.getContext('2d')
  const snapshot = ctx.getImageData(0, 0, canvas.value.width, canvas.value.height)
  history.value.push(snapshot)
  historyIndex.value = history.value.length - 1

  // Limit history to 50 entries
  if (history.value.length > 50) {
    history.value.shift()
    historyIndex.value--
  }
}

function undo() {
  if (historyIndex.value < 1) return

  historyIndex.value--
  restoreHistory()

  // Also remove the corresponding stroke
  strokes.value = strokes.value.slice(0, historyIndex.value)
}

function redo() {
  if (historyIndex.value >= history.value.length - 1) return

  historyIndex.value++
  restoreHistory()
}

function restoreHistory() {
  const ctx = canvas.value.getContext('2d')
  const snapshot = history.value[historyIndex.value]
  if (snapshot) {
    ctx.putImageData(snapshot, 0, 0)
  }
}

function replayStrokes() {
  const ctx = canvas.value.getContext('2d')
  const rect = canvasWrapper.value.getBoundingClientRect()

  // Clear to white
  ctx.globalCompositeOperation = 'source-over'
  ctx.fillStyle = '#ffffff'
  ctx.fillRect(0, 0, rect.width, rect.height)

  for (const stroke of strokes.value) {
    if (stroke.tool === 'clear') {
      ctx.globalCompositeOperation = 'source-over'
      ctx.fillStyle = '#ffffff'
      ctx.fillRect(0, 0, rect.width, rect.height)
    } else if (stroke.tool === 'fill') {
      replayFill(ctx, stroke)
    } else {
      replayPenStroke(ctx, stroke)
    }
  }
}

/**
 * Replay a pen/eraser stroke using perfect-freehand.
 * Supports backward compatibility with old {x, y} point format.
 */
function replayPenStroke(ctx, stroke) {
  if (!stroke.points || stroke.points.length === 0) return

  // Normalize points: old format {x, y} -> [x, y, 0.5]; new format is already [x, y, pressure]
  const normalizedPoints = stroke.points.map(pt => {
    if (Array.isArray(pt)) return pt
    // Old format: { x, y } object
    return [pt.x, pt.y, 0.5]
  })

  // Rebuild freehand options from stored data, or use sensible defaults
  const storedOpts = stroke.options || {}
  const size = stroke.width || 8
  const opts = {
    size,
    thinning: storedOpts.thinning ?? 0.5,
    smoothing: storedOpts.smoothing ?? 0.7,
    streamline: storedOpts.streamline ?? 0.6,
    easing: (t) => Math.sin((t * Math.PI) / 2),
    start: {
      taper: storedOpts.taperEnabled !== false ? size * 0.5 : 0,
      easing: (t) => t * t,
      cap: true
    },
    end: {
      taper: storedOpts.taperEnabled !== false ? size * 0.5 : 0,
      easing: (t) => 1 - (1 - t) * (1 - t),
      cap: true
    },
    simulatePressure: storedOpts.simulatePressure !== false
  }

  const isEraser = stroke.tool === 'eraser'
  drawFreehandStroke(ctx, normalizedPoints, stroke.color, size, opts, isEraser)
}

function replayFill(ctx, stroke) {
  const dpr = window.devicePixelRatio || 1
  const pixelX = Math.floor(stroke.x * dpr)
  const pixelY = Math.floor(stroke.y * dpr)
  const w = canvas.value.width
  const h = canvas.value.height

  const imageData = ctx.getImageData(0, 0, w, h)
  const data = imageData.data

  const startIdx = (pixelY * w + pixelX) * 4
  if (startIdx < 0 || startIdx >= data.length) return

  const targetR = data[startIdx]
  const targetG = data[startIdx + 1]
  const targetB = data[startIdx + 2]
  const targetA = data[startIdx + 3]

  const fill = hexToRgb(stroke.color)
  if (!fill) return
  if (targetR === fill.r && targetG === fill.g && targetB === fill.b && targetA === 255) return

  const tolerance = 32
  function matches(idx) {
    return Math.abs(data[idx] - targetR) <= tolerance &&
           Math.abs(data[idx + 1] - targetG) <= tolerance &&
           Math.abs(data[idx + 2] - targetB) <= tolerance &&
           Math.abs(data[idx + 3] - targetA) <= tolerance
  }

  const stack = [pixelX, pixelY]
  const visited = new Uint8Array(w * h)

  while (stack.length > 0) {
    const cy = stack.pop()
    const cx = stack.pop()
    if (cx < 0 || cx >= w || cy < 0 || cy >= h) continue
    const key = cy * w + cx
    if (visited[key]) continue
    visited[key] = 1
    const idx = key * 4
    if (!matches(idx)) continue
    data[idx] = fill.r
    data[idx + 1] = fill.g
    data[idx + 2] = fill.b
    data[idx + 3] = 255
    stack.push(cx + 1, cy)
    stack.push(cx - 1, cy)
    stack.push(cx, cy + 1)
    stack.push(cx, cy - 1)
  }

  ctx.putImageData(imageData, 0, 0)
}

function clearCanvas() {
  const ctx = canvas.value.getContext('2d')
  const rect = canvasWrapper.value.getBoundingClientRect()

  ctx.globalCompositeOperation = 'source-over'
  ctx.fillStyle = '#ffffff'
  ctx.fillRect(0, 0, rect.width, rect.height)

  // Truncate history and strokes
  strokes.value = strokes.value.slice(0, historyIndex.value + 1)
  history.value = history.value.slice(0, historyIndex.value + 1)

  strokes.value.push({ tool: 'clear' })
  saveToHistory()
}

// ========================================
// SAVE / DISCARD
// ========================================

async function save() {
  if (saving.value) return
  saving.value = true

  try {
    const rawStrokes = strokes.value.slice(0, historyIndex.value)
    const renderableStrokes = rawStrokes
      .filter(s => (s.tool === 'pen' || !s.tool) && s.points?.length >= 2)
      .map(s => {
        const opts = s.options || {}
        const outline = getStroke(s.points, {
          size: s.width,
          thinning: opts.thinning ?? 0.5,
          smoothing: opts.smoothing ?? 0.6,
          streamline: opts.streamline ?? 0.7,
          easing: (t) => Math.sin((t * Math.PI) / 2),
          start: { taper: opts.taperEnabled ? s.width * 0.5 : 0, cap: true, easing: (t) => t * t },
          end: { taper: opts.taperEnabled ? s.width * 0.5 : 0, cap: true, easing: (t) => 1 - (1 - t) * (1 - t) },
          simulatePressure: opts.simulatePressure ?? true,
        })
        return {
          points: s.points,
          color: s.color,
          width: s.width,
          options: opts,
          svgPath: outlineToSvgPath(outline),
        }
      })

    const drawingData = {
      strokes: renderableStrokes,
      width: canvas.value.style.width,
      height: canvas.value.style.height,
      engine: 'perfect-freehand'
    }

    // Export canvas to blob
    const blob = await new Promise(resolve => {
      canvas.value.toBlob(resolve, 'image/png')
    })

    emit('save', {
      imageBlob: blob,
      drawingData: JSON.stringify(drawingData),
      width: parseInt(canvas.value.style.width) || 600,
      height: parseInt(canvas.value.style.height) || 400
    })
  } catch (e) {
    console.error('Failed to save drawing:', e)
  } finally {
    saving.value = false
  }
}

function discard() {
  emit('discard')
}

// ========================================
// KEYBOARD SHORTCUTS
// ========================================

function onKeyDown(e) {
  // Ctrl+Z = Undo
  if ((e.ctrlKey || e.metaKey) && e.key === 'z' && !e.shiftKey) {
    e.preventDefault()
    undo()
    return
  }
  // Ctrl+Y or Ctrl+Shift+Z = Redo
  if ((e.ctrlKey || e.metaKey) && (e.key === 'y' || (e.key === 'z' && e.shiftKey))) {
    e.preventDefault()
    redo()
    return
  }
  // P = Pen
  if (e.key === 'p' || e.key === 'P') {
    currentTool.value = TOOLS.PEN
    return
  }
  // E = Eraser
  if (e.key === 'e' || e.key === 'E') {
    currentTool.value = TOOLS.ERASER
    return
  }
  // F = Fill
  if (e.key === 'f' || e.key === 'F') {
    currentTool.value = TOOLS.FILL
    return
  }
  // V = Pointer
  if (e.key === 'v' || e.key === 'V') {
    currentTool.value = TOOLS.POINTER
    return
  }
  // Escape = Discard
  if (e.key === 'Escape') {
    discard()
    return
  }
  // [ and ] = stroke width
  if (e.key === '[') {
    strokeWidth.value = Math.max(1, strokeWidth.value - 2)
    return
  }
  if (e.key === ']') {
    strokeWidth.value = Math.min(40, strokeWidth.value + 2)
    return
  }
}

// ========================================
// LIFECYCLE
// ========================================

let resizeObserver = null

onMounted(() => {
  nextTick(() => {
    initCanvas()
    wrapper.value?.focus()
  })

  resizeObserver = new ResizeObserver(() => {
    // On resize, we'd need to re-init canvas. For now, keep the original size.
  })
  if (canvasWrapper.value) {
    resizeObserver.observe(canvasWrapper.value)
  }
})

onUnmounted(() => {
  if (resizeObserver) {
    resizeObserver.disconnect()
  }
})
</script>
