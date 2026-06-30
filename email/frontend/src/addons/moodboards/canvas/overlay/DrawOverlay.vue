<template>
  <div
    class="absolute inset-0"
    style="z-index: 9997; cursor: crosshair;"
    @pointerdown="onPointerDown"
    @pointermove="onPointerMove"
    @pointerup="onPointerUp"
  >
    <svg
      class="absolute inset-0 w-full h-full pointer-events-none overflow-visible"
      :style="{ transformOrigin: '0 0', transform: `translate(${panX}px, ${panY}px) scale(${zoom})` }"
    >
      <path
        v-for="stroke in sessionStrokes"
        :key="stroke.id"
        :d="stroke.svgPath"
        :fill="stroke.eraser ? 'none' : stroke.color"
        :fill-opacity="stroke.eraser ? 0 : 0.85"
        :stroke="stroke.eraser ? (eraserDisplayColor) : 'none'"
        :stroke-width="stroke.eraser ? stroke.width : 0"
        stroke-linecap="round"
        stroke-linejoin="round"
      />
      <path
        v-if="activeStrokePath"
        :d="activeStrokePath"
        :fill="isEraser ? 'none' : strokeColor"
        :fill-opacity="isEraser ? 0 : 0.85"
        :stroke="isEraser ? eraserDisplayColor : 'none'"
        :stroke-width="isEraser ? strokeWidth : 0"
        stroke-linecap="round"
        stroke-linejoin="round"
      />
    </svg>

    <!-- Eraser cursor preview -->
    <div
      v-if="isEraser && cursorPos"
      class="absolute pointer-events-none rounded-full"
      :style="{
        left: (cursorPos.screenX - Math.max(4, strokeWidth * zoom) / 2) + 'px',
        top: (cursorPos.screenY - Math.max(4, strokeWidth * zoom) / 2) + 'px',
        width: Math.max(4, strokeWidth * zoom) + 'px',
        height: Math.max(4, strokeWidth * zoom) + 'px',
        backgroundColor: strokeColor + '50',
        border: '1px solid ' + strokeColor,
      }"
    />

    <!-- Floating draw toolbar -->
    <transition name="draw-bar">
      <div
        class="absolute bottom-20 left-1/2 -translate-x-1/2 z-[10002] flex items-center gap-2 px-3 py-2 bg-white dark:bg-surface-800 rounded-2xl shadow-lg border border-primary-200 dark:border-primary-700/50"
        @mousedown.stop
        @pointerdown.stop
      >
        <!-- Pen / Eraser toggle -->
        <div class="flex items-center gap-0.5 bg-surface-100 dark:bg-surface-700 rounded-xl p-0.5">
          <button
            @click="isEraser = false"
            :class="[
              'p-1.5 rounded-lg transition-colors',
              !isEraser ? 'bg-primary-500 text-white shadow-sm' : 'text-surface-500 hover:bg-surface-200 dark:hover:bg-surface-600'
            ]"
            title="Pen"
          >
            <span class="material-symbols-rounded text-base">draw</span>
          </button>
          <button
            @click="isEraser = true"
            :class="[
              'p-1.5 rounded-lg transition-colors',
              isEraser ? 'bg-red-500 text-white shadow-sm' : 'text-surface-500 hover:bg-surface-200 dark:hover:bg-surface-600'
            ]"
            title="Eraser"
          >
            <span class="material-symbols-rounded text-base">ink_eraser</span>
          </button>
        </div>

        <div class="w-px h-6 bg-surface-200 dark:bg-surface-600"></div>

        <!-- Color picker -->
        <MoodColorPicker
          :model-value="strokeColor"
          @update:model-value="strokeColor = $event"
          label="Drawing color"
          :show-caret="false"
          dropdown-position="bottom-full left-0 mb-2"
        />

        <!-- Eyedropper -->
        <button
          v-if="hasEyeDropper"
          @click="pickColorFromScreen"
          class="p-1.5 rounded-lg text-surface-500 hover:bg-surface-100 dark:hover:bg-surface-700 hover:text-primary-500 transition-colors"
          title="Pick color from screen"
        >
          <span class="material-symbols-rounded text-lg">colorize</span>
        </button>

        <!-- Quick colors -->
        <div class="flex gap-1">
          <button
            v-for="c in quickColors"
            :key="c"
            @click="strokeColor = c; isEraser = false"
            class="w-5 h-5 rounded-full border border-surface-300 dark:border-surface-600 hover:scale-110 transition-transform"
            :style="{ backgroundColor: c }"
            :class="{ 'ring-2 ring-primary-500 ring-offset-1': strokeColor === c && !isEraser }"
          />
        </div>

        <div class="w-px h-6 bg-surface-200 dark:bg-surface-600"></div>

        <!-- Stroke width -->
        <div class="flex items-center gap-1.5" title="Stroke width">
          <span class="material-symbols-rounded text-sm text-surface-400">line_weight</span>
          <input
            type="range"
            v-model.number="strokeWidth"
            min="1"
            max="30"
            class="w-20 accent-primary-500"
          />
          <span class="text-[10px] font-mono text-surface-400 w-4 text-right">{{ strokeWidth }}</span>
        </div>

        <!-- Brush options popup -->
        <div class="relative" ref="brushOptionsRef">
          <button
            @click.stop="showBrushOptions = !showBrushOptions"
            :class="[
              'p-1.5 rounded-lg transition-colors',
              showBrushOptions
                ? 'bg-primary-100 dark:bg-primary-900/30 text-primary-600 dark:text-primary-400'
                : 'text-surface-500 hover:bg-surface-100 dark:hover:bg-surface-700'
            ]"
            title="Brush options"
          >
            <span class="material-symbols-rounded text-base">tune</span>
          </button>
          <transition name="draw-bar">
            <div
              v-if="showBrushOptions"
              class="absolute bottom-full left-1/2 -translate-x-1/2 mb-2 w-72 bg-white dark:bg-surface-800 rounded-2xl shadow-2xl border border-surface-200 dark:border-surface-700 p-4 space-y-3"
              @mousedown.stop
            >
              <span class="text-[10px] font-bold uppercase tracking-wider text-surface-400">Brush Settings</span>

              <div class="flex items-center justify-between gap-3" title="Brush diameter in pixels">
                <label class="text-xs text-surface-600 dark:text-surface-400 w-20 shrink-0">Size</label>
                <input type="range" v-model.number="strokeWidth" min="1" max="50" class="flex-1 accent-primary-500" />
                <span class="text-[11px] font-mono text-surface-500 w-8 text-right">{{ strokeWidth }}</span>
              </div>
              <div class="flex items-center justify-between gap-3" title="Thinning at speed">
                <label class="text-xs text-surface-600 dark:text-surface-400 w-20 shrink-0">Thinning</label>
                <input type="range" v-model.number="brushThinning" min="-1" max="1" step="0.05" class="flex-1 accent-primary-500" />
                <span class="text-[11px] font-mono text-surface-500 w-8 text-right">{{ brushThinning.toFixed(2) }}</span>
              </div>
              <div class="flex items-center justify-between gap-3" title="Smoothing">
                <label class="text-xs text-surface-600 dark:text-surface-400 w-20 shrink-0">Smoothing</label>
                <input type="range" v-model.number="brushSmoothing" min="0" max="1" step="0.05" class="flex-1 accent-primary-500" />
                <span class="text-[11px] font-mono text-surface-500 w-8 text-right">{{ brushSmoothing.toFixed(2) }}</span>
              </div>
              <div class="flex items-center justify-between gap-3" title="Streamline">
                <label class="text-xs text-surface-600 dark:text-surface-400 w-20 shrink-0">Streamline</label>
                <input type="range" v-model.number="brushStreamline" min="0" max="1" step="0.05" class="flex-1 accent-primary-500" />
                <span class="text-[11px] font-mono text-surface-500 w-8 text-right">{{ brushStreamline.toFixed(2) }}</span>
              </div>

              <div class="border-t border-surface-200 dark:border-surface-700"></div>

              <div class="flex items-center justify-between gap-3" title="Taper at start">
                <label class="text-xs text-surface-600 dark:text-surface-400 w-20 shrink-0">Taper Start</label>
                <input type="range" v-model.number="brushTaperStart" min="0" max="200" step="1" class="flex-1 accent-primary-500" />
                <span class="text-[11px] font-mono text-surface-500 w-8 text-right">{{ brushTaperStart }}</span>
              </div>
              <div class="flex items-center justify-between gap-3" title="Taper at end">
                <label class="text-xs text-surface-600 dark:text-surface-400 w-20 shrink-0">Taper End</label>
                <input type="range" v-model.number="brushTaperEnd" min="0" max="200" step="1" class="flex-1 accent-primary-500" />
                <span class="text-[11px] font-mono text-surface-500 w-8 text-right">{{ brushTaperEnd }}</span>
              </div>

              <div class="border-t border-surface-200 dark:border-surface-700"></div>

              <div class="grid grid-cols-2 gap-3">
                <div class="flex items-center justify-between" title="Round cap at start">
                  <label class="text-xs text-surface-600 dark:text-surface-400">Cap Start</label>
                  <button
                    @click="brushCapStart = !brushCapStart"
                    class="w-9 h-5 rounded-full transition-colors relative flex-shrink-0"
                    :class="brushCapStart ? 'bg-primary-500' : 'bg-surface-300 dark:bg-surface-600'"
                  >
                    <span class="absolute top-0.5 left-0.5 w-4 h-4 rounded-full bg-white shadow-sm transition-transform duration-200" :class="brushCapStart ? 'translate-x-4' : 'translate-x-0'" />
                  </button>
                </div>
                <div class="flex items-center justify-between" title="Round cap at end">
                  <label class="text-xs text-surface-600 dark:text-surface-400">Cap End</label>
                  <button
                    @click="brushCapEnd = !brushCapEnd"
                    class="w-9 h-5 rounded-full transition-colors relative flex-shrink-0"
                    :class="brushCapEnd ? 'bg-primary-500' : 'bg-surface-300 dark:bg-surface-600'"
                  >
                    <span class="absolute top-0.5 left-0.5 w-4 h-4 rounded-full bg-white shadow-sm transition-transform duration-200" :class="brushCapEnd ? 'translate-x-4' : 'translate-x-0'" />
                  </button>
                </div>
              </div>

              <div class="flex items-center justify-between" title="Simulates pen pressure from mouse">
                <label class="text-xs text-surface-600 dark:text-surface-400">Simulate Pressure</label>
                <button
                  @click="brushSimulatePressure = !brushSimulatePressure"
                  class="w-9 h-5 rounded-full transition-colors relative flex-shrink-0"
                  :class="brushSimulatePressure ? 'bg-primary-500' : 'bg-surface-300 dark:bg-surface-600'"
                >
                  <span class="absolute top-0.5 left-0.5 w-4 h-4 rounded-full bg-white shadow-sm transition-transform duration-200" :class="brushSimulatePressure ? 'translate-x-4' : 'translate-x-0'" />
                </button>
              </div>

              <div class="border-t border-surface-200 dark:border-surface-700"></div>
              <button
                @click="resetBrushOptions"
                class="w-full text-center text-xs font-medium text-surface-500 dark:text-surface-400 hover:text-primary-500 transition-colors py-1"
              >
                Reset Options
              </button>
            </div>
          </transition>
        </div>

        <div class="w-px h-6 bg-surface-200 dark:bg-surface-600"></div>

        <!-- Undo / Clear -->
        <button
          @click="undoLastStroke"
          :disabled="sessionStrokes.length === 0"
          :class="[
            'p-1.5 rounded-lg transition-colors',
            sessionStrokes.length === 0
              ? 'text-surface-300 dark:text-surface-600 cursor-not-allowed'
              : 'text-surface-600 dark:text-surface-400 hover:bg-surface-100 dark:hover:bg-surface-700'
          ]"
          title="Undo last stroke (Ctrl+Z)"
        >
          <span class="material-symbols-rounded text-base">undo</span>
        </button>
        <button
          @click="clearAllStrokes"
          :disabled="sessionStrokes.length === 0"
          :class="[
            'p-1.5 rounded-lg transition-colors',
            sessionStrokes.length === 0
              ? 'text-surface-300 dark:text-surface-600 cursor-not-allowed'
              : 'text-surface-600 dark:text-surface-400 hover:bg-red-50 dark:hover:bg-red-900/20 hover:text-red-500'
          ]"
          title="Clear all strokes"
        >
          <span class="material-symbols-rounded text-base">delete_sweep</span>
        </button>

        <div class="w-px h-6 bg-surface-200 dark:bg-surface-600"></div>

        <!-- Cancel / Done -->
        <button
          @click="$emit('cancel')"
          class="px-3 py-1 rounded-full text-xs font-medium text-surface-500 hover:bg-surface-100 dark:hover:bg-surface-700 transition-colors"
        >
          Cancel
        </button>
        <button
          @click="saveAndExit"
          class="px-3 py-1 rounded-full text-xs font-medium bg-primary-500 text-white hover:bg-primary-600 transition-colors"
        >
          Done
        </button>
      </div>
    </transition>
  </div>
</template>

<script setup>
import { ref, onMounted, onBeforeUnmount } from 'vue'
import getStroke from 'perfect-freehand'
import MoodColorPicker from '../../components/MoodColorPicker.vue'

const props = defineProps({
  zoom: { type: Number, default: 1 },
  panX: { type: Number, default: 0 },
  panY: { type: Number, default: 0 },
  backgroundColor: { type: String, default: '#f5f5f5' },
})

const emit = defineEmits(['save-drawing', 'cancel'])

const quickColors = [
  '#ffffff', '#333333', '#ef4444', '#f97316', '#eab308',
  '#22c55e', '#06b6d4', '#3b82f6', '#ec4899',
]

const strokeColor = ref('#333333')
const strokeWidth = ref(4)
const isEraser = ref(false)
const sessionStrokes = ref([])
const activePoints = ref([])
const activeStrokePath = ref('')
const isDrawing = ref(false)
const showBrushOptions = ref(false)
const brushOptionsRef = ref(null)
const cursorPos = ref(null)

const brushThinning = ref(0.5)
const brushSmoothing = ref(0.6)
const brushStreamline = ref(0.7)
const brushTaperStart = ref(0)
const brushTaperEnd = ref(0)
const brushCapStart = ref(true)
const brushCapEnd = ref(true)
const brushSimulatePressure = ref(true)

const hasEyeDropper = typeof window !== 'undefined' && 'EyeDropper' in window
const eraserDisplayColor = ref('#cccccc')

function getFreehandOptions() {
  return {
    size: strokeWidth.value,
    thinning: brushThinning.value,
    smoothing: brushSmoothing.value,
    streamline: brushStreamline.value,
    easing: (t) => t,
    start: { taper: brushTaperStart.value, cap: brushCapStart.value, easing: (t) => t },
    end: { taper: brushTaperEnd.value, cap: brushCapEnd.value, easing: (t) => t },
    simulatePressure: brushSimulatePressure.value,
  }
}

function resetBrushOptions() {
  strokeWidth.value = 4
  brushThinning.value = 0.5
  brushSmoothing.value = 0.6
  brushStreamline.value = 0.7
  brushTaperStart.value = 0
  brushTaperEnd.value = 0
  brushCapStart.value = true
  brushCapEnd.value = true
  brushSimulatePressure.value = true
}

async function pickColorFromScreen() {
  if (!hasEyeDropper) return
  try {
    const dropper = new window.EyeDropper()
    const result = await dropper.open()
    if (result?.sRGBHex) {
      strokeColor.value = result.sRGBHex
      isEraser.value = false
    }
  } catch {}
}

function screenToCanvas(e) {
  const el = e.currentTarget
  const rect = el.getBoundingClientRect()
  return {
    x: (e.clientX - rect.left - props.panX) / props.zoom,
    y: (e.clientY - rect.top - props.panY) / props.zoom,
    screenX: e.clientX - rect.left,
    screenY: e.clientY - rect.top,
  }
}

function onPointerDown(e) {
  if (e.button !== 0) return
  e.target.setPointerCapture(e.pointerId)
  isDrawing.value = true
  const pos = screenToCanvas(e)
  activePoints.value = [[pos.x, pos.y, e.pressure || 0.5]]
  activeStrokePath.value = ''
}

function onPointerMove(e) {
  const pos = screenToCanvas(e)
  cursorPos.value = { screenX: pos.screenX, screenY: pos.screenY }

  if (!isDrawing.value) return
  const pts = activePoints.value
  if (pts.length > 0) {
    const last = pts[pts.length - 1]
    if ((pos.x - last[0]) ** 2 + (pos.y - last[1]) ** 2 < 4) return
  }
  pts.push([pos.x, pos.y, e.pressure || 0.5])

  if (isEraser.value) {
    activeStrokePath.value = buildEraserPath(pts, strokeWidth.value)
  } else {
    const outline = getStroke(pts, getFreehandOptions())
    activeStrokePath.value = outlineToSvgPath(outline)
  }
}

function onPointerUp(e) {
  if (!isDrawing.value) return
  isDrawing.value = false
  try { e.target.releasePointerCapture(e.pointerId) } catch {}

  if (activePoints.value.length === 1) {
    const [x, y, p] = activePoints.value[0]
    activePoints.value.push([x + 0.1, y + 0.1, p])
  }

  let svgPath
  if (isEraser.value) {
    svgPath = buildEraserPath(activePoints.value, strokeWidth.value)
  } else {
    const outline = getStroke(activePoints.value, getFreehandOptions())
    svgPath = outlineToSvgPath(outline)
  }

  if (svgPath) {
    sessionStrokes.value.push({
      id: Date.now() + '-' + Math.random().toString(36).substr(2, 6),
      points: [...activePoints.value],
      color: strokeColor.value,
      width: strokeWidth.value,
      eraser: isEraser.value,
      options: getFreehandOptions(),
      svgPath,
    })
  }
  activePoints.value = []
  activeStrokePath.value = ''
}

function buildEraserPath(pts, width) {
  if (!pts.length) return ''
  if (pts.length === 1) {
    const [x, y] = pts[0]
    const r = width / 2
    return `M ${x - r} ${y} A ${r} ${r} 0 1 0 ${x + r} ${y} A ${r} ${r} 0 1 0 ${x - r} ${y} Z`
  }
  let d = `M ${pts[0][0].toFixed(2)} ${pts[0][1].toFixed(2)}`
  for (let i = 1; i < pts.length; i++) {
    d += ` L ${pts[i][0].toFixed(2)} ${pts[i][1].toFixed(2)}`
  }
  return d
}

function outlineToSvgPath(outline) {
  if (!outline?.length || outline.length < 2) return ''
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

function undoLastStroke() {
  if (sessionStrokes.value.length) {
    sessionStrokes.value.pop()
  }
}

function clearAllStrokes() {
  sessionStrokes.value = []
}

function onKeydown(e) {
  if (e.key === 'z' && (e.ctrlKey || e.metaKey) && !e.shiftKey) {
    e.preventDefault()
    undoLastStroke()
  }
}

function onClickOutsideBrush(e) {
  if (showBrushOptions.value && brushOptionsRef.value && !brushOptionsRef.value.contains(e.target)) {
    showBrushOptions.value = false
  }
}

onMounted(() => {
  document.addEventListener('keydown', onKeydown)
  document.addEventListener('pointerdown', onClickOutsideBrush)
  eraserDisplayColor.value = props.backgroundColor || '#f5f5f5'
})

onBeforeUnmount(() => {
  document.removeEventListener('keydown', onKeydown)
  document.removeEventListener('pointerdown', onClickOutsideBrush)
})

function getStrokesBounds(strokes) {
  let minX = Infinity, minY = Infinity, maxX = -Infinity, maxY = -Infinity
  for (const stroke of strokes) {
    for (const pt of stroke.points) {
      const pad = (stroke.width || 4) / 2
      if (pt[0] - pad < minX) minX = pt[0] - pad
      if (pt[1] - pad < minY) minY = pt[1] - pad
      if (pt[0] + pad > maxX) maxX = pt[0] + pad
      if (pt[1] + pad > maxY) maxY = pt[1] + pad
    }
  }
  return { minX, minY, maxX, maxY, width: maxX - minX, height: maxY - minY }
}

function saveAndExit() {
  const penStrokes = sessionStrokes.value.filter(s => !s.eraser)
  if (!penStrokes.length) {
    emit('cancel')
    return
  }

  const PADDING = 8
  const bounds = getStrokesBounds(penStrokes)
  const relativeStrokes = penStrokes.map(s => {
    const pts = s.points.map(([x, y, p]) => [x - bounds.minX + PADDING, y - bounds.minY + PADDING, p])
    const outline = getStroke(pts, s.options || getFreehandOptions())
    return {
      points: pts,
      color: s.color,
      width: s.width,
      options: s.options || {},
      svgPath: outlineToSvgPath(outline),
    }
  })

  const itemWidth = Math.max(60, Math.round(bounds.width + PADDING * 2))
  const itemHeight = Math.max(40, Math.round(bounds.height + PADDING * 2))

  emit('save-drawing', {
    items: [{
      type: 'drawing',
      pos_x: Math.round(bounds.minX - PADDING),
      pos_y: Math.round(bounds.minY - PADDING),
      width: itemWidth,
      height: itemHeight,
      content: JSON.stringify({ strokes: relativeStrokes, width: itemWidth, height: itemHeight, engine: 'perfect-freehand' }),
      style_data: { strokes_data: relativeStrokes },
    }],
  })
}
</script>

<style scoped>
.draw-bar-enter-active,
.draw-bar-leave-active {
  transition: opacity 0.15s ease, transform 0.2s cubic-bezier(0.22, 1, 0.36, 1);
}
.draw-bar-enter-from,
.draw-bar-leave-to {
  opacity: 0;
  transform: translateX(-50%) translateY(8px);
}
</style>
