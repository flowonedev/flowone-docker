<script setup>
import { ref, onMounted, onUnmounted, nextTick, watch } from 'vue'

const props = defineProps({
  screenshot: { type: String, required: true }
})

const emit = defineEmits(['save', 'cancel'])

const wrapperRef = ref(null)
const canvasRef = ref(null)
const textInputRef = ref(null)

const activeTool = ref('pen')
const activeColor = ref('#EF4444')
const strokeWidth = ref(3)
const shapes = ref([])
const isDrawing = ref(false)
const currentShape = ref(null)
const textInput = ref({ visible: false, x: 0, y: 0, value: '' })

const bgImage = ref(null)
const canvasW = ref(0)
const canvasH = ref(0)
const offsetX = ref(0)
const offsetY = ref(0)
const imgScale = ref(1)

const COLORS = [
  { value: '#EF4444', label: 'Red' },
  { value: '#F59E0B', label: 'Yellow' },
  { value: '#10B981', label: 'Green' },
  { value: '#3B82F6', label: 'Blue' },
  { value: '#FFFFFF', label: 'White' },
  { value: '#000000', label: 'Black' },
]

const TOOLS = [
  { id: 'pen',         icon: 'draw',         label: 'Pen' },
  { id: 'highlighter', icon: 'ink_highlighter', label: 'Highlighter' },
  { id: 'arrow',       icon: 'north_east',   label: 'Arrow' },
  { id: 'rectangle',   icon: 'rectangle',    label: 'Rectangle' },
  { id: 'text',        icon: 'text_fields',  label: 'Text' },
]

function loadImage() {
  return new Promise((resolve) => {
    const img = new Image()
    img.onload = () => {
      bgImage.value = img
      resolve(img)
    }
    img.src = props.screenshot
  })
}

function fitCanvas() {
  if (!wrapperRef.value || !bgImage.value) return
  const wrapper = wrapperRef.value
  const wW = wrapper.clientWidth
  const wH = wrapper.clientHeight
  const iW = bgImage.value.naturalWidth
  const iH = bgImage.value.naturalHeight

  const scale = Math.min(wW / iW, wH / iH, 1)
  imgScale.value = scale
  canvasW.value = Math.round(iW * scale)
  canvasH.value = Math.round(iH * scale)
  offsetX.value = Math.round((wW - canvasW.value) / 2)
  offsetY.value = Math.round((wH - canvasH.value) / 2)

  nextTick(() => redraw())
}

onMounted(async () => {
  await loadImage()
  fitCanvas()
  window.addEventListener('resize', fitCanvas)
  window.addEventListener('keydown', onKeyDown)
})

onUnmounted(() => {
  window.removeEventListener('resize', fitCanvas)
  window.removeEventListener('keydown', onKeyDown)
})

function onKeyDown(e) {
  if (e.key === 'Escape') {
    if (textInput.value.visible) {
      commitText()
    } else {
      emit('cancel')
    }
    return
  }
  if ((e.ctrlKey || e.metaKey) && e.key === 'z') {
    e.preventDefault()
    undo()
  }
}

function getCtx() {
  return canvasRef.value?.getContext('2d')
}

function toCanvasCoords(e) {
  const rect = canvasRef.value.getBoundingClientRect()
  const clientX = e.touches ? e.touches[0].clientX : e.clientX
  const clientY = e.touches ? e.touches[0].clientY : e.clientY
  return {
    x: clientX - rect.left,
    y: clientY - rect.top
  }
}

function onPointerDown(e) {
  if (textInput.value.visible) {
    commitText()
    return
  }
  const pos = toCanvasCoords(e)
  const tool = activeTool.value
  const color = activeColor.value

  if (tool === 'text') {
    textInput.value = { visible: true, x: pos.x, y: pos.y, value: '' }
    nextTick(() => textInputRef.value?.focus())
    return
  }

  isDrawing.value = true

  if (tool === 'pen' || tool === 'highlighter') {
    const w = tool === 'highlighter' ? strokeWidth.value * 6 : strokeWidth.value
    const alpha = tool === 'highlighter' ? 0.35 : 1
    currentShape.value = { type: tool, points: [pos], color, width: w, alpha }
  } else if (tool === 'arrow') {
    currentShape.value = { type: 'arrow', start: pos, end: pos, color, width: strokeWidth.value }
  } else if (tool === 'rectangle') {
    currentShape.value = { type: 'rectangle', start: pos, end: pos, color, width: strokeWidth.value }
  }
}

function onPointerMove(e) {
  if (!isDrawing.value || !currentShape.value) return
  const pos = toCanvasCoords(e)
  const shape = currentShape.value

  if (shape.type === 'pen' || shape.type === 'highlighter') {
    shape.points.push(pos)
  } else {
    shape.end = pos
  }
  redraw()
  drawShape(getCtx(), shape)
}

function onPointerUp() {
  if (!isDrawing.value || !currentShape.value) return
  isDrawing.value = false
  shapes.value.push(currentShape.value)
  currentShape.value = null
  redraw()
}

function commitText() {
  const t = textInput.value
  if (t.value.trim()) {
    shapes.value.push({
      type: 'text',
      x: t.x,
      y: t.y,
      text: t.value.trim(),
      color: activeColor.value,
      fontSize: Math.max(16, strokeWidth.value * 6)
    })
    redraw()
  }
  textInput.value = { visible: false, x: 0, y: 0, value: '' }
}

function drawShape(ctx, shape) {
  ctx.save()
  if (shape.type === 'pen' || shape.type === 'highlighter') {
    ctx.globalAlpha = shape.alpha ?? 1
    ctx.strokeStyle = shape.color
    ctx.lineWidth = shape.width
    ctx.lineCap = 'round'
    ctx.lineJoin = 'round'
    ctx.beginPath()
    const pts = shape.points
    if (pts.length < 2) {
      ctx.arc(pts[0].x, pts[0].y, shape.width / 2, 0, Math.PI * 2)
      ctx.fillStyle = shape.color
      ctx.fill()
    } else {
      ctx.moveTo(pts[0].x, pts[0].y)
      for (let i = 1; i < pts.length; i++) {
        ctx.lineTo(pts[i].x, pts[i].y)
      }
      ctx.stroke()
    }
  } else if (shape.type === 'arrow') {
    drawArrow(ctx, shape.start, shape.end, shape.color, shape.width)
  } else if (shape.type === 'rectangle') {
    ctx.strokeStyle = shape.color
    ctx.lineWidth = shape.width
    ctx.lineJoin = 'round'
    const x = Math.min(shape.start.x, shape.end.x)
    const y = Math.min(shape.start.y, shape.end.y)
    const w = Math.abs(shape.end.x - shape.start.x)
    const h = Math.abs(shape.end.y - shape.start.y)
    ctx.strokeRect(x, y, w, h)
  } else if (shape.type === 'text') {
    ctx.font = `bold ${shape.fontSize}px Inter, system-ui, sans-serif`
    ctx.fillStyle = shape.color
    ctx.textBaseline = 'top'

    const outlineColor = shape.color === '#000000' || shape.color === '#FFFFFF' ? '' : 'rgba(0,0,0,0.5)'
    if (outlineColor) {
      ctx.strokeStyle = outlineColor
      ctx.lineWidth = 3
      ctx.lineJoin = 'round'
      ctx.strokeText(shape.text, shape.x, shape.y)
    }
    ctx.fillText(shape.text, shape.x, shape.y)
  }
  ctx.restore()
}

function drawArrow(ctx, from, to, color, width) {
  const dx = to.x - from.x
  const dy = to.y - from.y
  const angle = Math.atan2(dy, dx)
  const headLen = Math.max(12, width * 4)

  ctx.strokeStyle = color
  ctx.fillStyle = color
  ctx.lineWidth = width
  ctx.lineCap = 'round'

  ctx.beginPath()
  ctx.moveTo(from.x, from.y)
  ctx.lineTo(to.x, to.y)
  ctx.stroke()

  ctx.beginPath()
  ctx.moveTo(to.x, to.y)
  ctx.lineTo(to.x - headLen * Math.cos(angle - Math.PI / 6), to.y - headLen * Math.sin(angle - Math.PI / 6))
  ctx.lineTo(to.x - headLen * Math.cos(angle + Math.PI / 6), to.y - headLen * Math.sin(angle + Math.PI / 6))
  ctx.closePath()
  ctx.fill()
}

function redraw() {
  const ctx = getCtx()
  if (!ctx || !bgImage.value) return

  ctx.clearRect(0, 0, canvasW.value, canvasH.value)
  ctx.drawImage(bgImage.value, 0, 0, canvasW.value, canvasH.value)

  for (const s of shapes.value) {
    drawShape(ctx, s)
  }
}

function undo() {
  if (shapes.value.length === 0) return
  shapes.value.pop()
  redraw()
}

function clearAll() {
  shapes.value = []
  redraw()
}

function save() {
  if (textInput.value.visible) commitText()

  const exportCanvas = document.createElement('canvas')
  const iW = bgImage.value.naturalWidth
  const iH = bgImage.value.naturalHeight
  exportCanvas.width = iW
  exportCanvas.height = iH
  const ctx = exportCanvas.getContext('2d')
  ctx.drawImage(bgImage.value, 0, 0, iW, iH)

  const scaleRatio = iW / canvasW.value
  ctx.save()
  ctx.scale(scaleRatio, scaleRatio)
  for (const s of shapes.value) {
    drawShape(ctx, s)
  }
  ctx.restore()

  const dataUrl = exportCanvas.toDataURL('image/png', 0.9)
  emit('save', dataUrl)
}

watch(activeTool, () => {
  if (textInput.value.visible) commitText()
})
</script>

<template>
  <Teleport to="body">
    <div class="fixed inset-0 z-[100001] bg-surface-900 flex flex-col select-none">

      <!-- Toolbar -->
      <div class="flex items-center gap-1 px-3 py-2 bg-surface-800 border-b border-surface-700 flex-shrink-0 overflow-x-auto">

        <!-- Tool buttons -->
        <div class="flex items-center gap-0.5 mr-3">
          <button
            v-for="tool in TOOLS"
            :key="tool.id"
            @click="activeTool = tool.id"
            :class="[
              'p-2 rounded-lg transition-colors',
              activeTool === tool.id
                ? 'bg-primary-600 text-white'
                : 'text-surface-400 hover:bg-surface-700 hover:text-white'
            ]"
            :title="tool.label"
          >
            <span class="material-symbols-rounded text-lg">{{ tool.icon }}</span>
          </button>
        </div>

        <div class="w-px h-6 bg-surface-600 mx-1 flex-shrink-0"></div>

        <!-- Colors -->
        <div class="flex items-center gap-1 mx-2">
          <button
            v-for="c in COLORS"
            :key="c.value"
            @click="activeColor = c.value"
            class="w-6 h-6 rounded-full border-2 transition-transform flex-shrink-0"
            :class="activeColor === c.value ? 'scale-125 border-white' : 'border-transparent hover:scale-110'"
            :style="{ backgroundColor: c.value }"
            :title="c.label"
          ></button>
        </div>

        <div class="w-px h-6 bg-surface-600 mx-1 flex-shrink-0"></div>

        <!-- Stroke width -->
        <div class="flex items-center gap-1 mx-2">
          <button
            v-for="sw in [2, 3, 5]"
            :key="sw"
            @click="strokeWidth = sw"
            :class="[
              'w-8 h-8 rounded-lg flex items-center justify-center transition-colors',
              strokeWidth === sw
                ? 'bg-surface-600 text-white'
                : 'text-surface-400 hover:bg-surface-700 hover:text-white'
            ]"
            :title="`Size ${sw}`"
          >
            <span class="rounded-full bg-current" :style="{ width: sw * 2 + 'px', height: sw * 2 + 'px' }"></span>
          </button>
        </div>

        <div class="w-px h-6 bg-surface-600 mx-1 flex-shrink-0"></div>

        <!-- Undo / Clear -->
        <div class="flex items-center gap-0.5 mx-2">
          <button
            @click="undo"
            :disabled="shapes.length === 0"
            class="p-2 rounded-lg text-surface-400 hover:bg-surface-700 hover:text-white transition-colors disabled:opacity-30 disabled:cursor-not-allowed"
            title="Undo (Ctrl+Z)"
          >
            <span class="material-symbols-rounded text-lg">undo</span>
          </button>
          <button
            @click="clearAll"
            :disabled="shapes.length === 0"
            class="p-2 rounded-lg text-surface-400 hover:bg-surface-700 hover:text-white transition-colors disabled:opacity-30 disabled:cursor-not-allowed"
            title="Clear all"
          >
            <span class="material-symbols-rounded text-lg">delete_sweep</span>
          </button>
        </div>

        <div class="flex-1"></div>

        <!-- Cancel / Save -->
        <div class="flex items-center gap-2">
          <button
            @click="$emit('cancel')"
            class="px-4 py-1.5 rounded-full text-sm text-surface-300 hover:text-white hover:bg-surface-700 transition-colors"
          >
            Cancel
          </button>
          <button
            @click="save"
            class="px-4 py-1.5 rounded-full text-sm bg-primary-600 text-white hover:bg-primary-500 transition-colors flex items-center gap-1.5"
          >
            <span class="material-symbols-rounded text-base">check</span>
            Done
          </button>
        </div>
      </div>

      <!-- Canvas area -->
      <div ref="wrapperRef" class="flex-1 overflow-hidden relative">
        <canvas
          ref="canvasRef"
          :width="canvasW"
          :height="canvasH"
          :style="{
            position: 'absolute',
            left: offsetX + 'px',
            top: offsetY + 'px',
            width: canvasW + 'px',
            height: canvasH + 'px',
            cursor: activeTool === 'text' ? 'text' : 'crosshair'
          }"
          @mousedown.prevent="onPointerDown"
          @mousemove.prevent="onPointerMove"
          @mouseup.prevent="onPointerUp"
          @mouseleave="onPointerUp"
          @touchstart.prevent="onPointerDown"
          @touchmove.prevent="onPointerMove"
          @touchend.prevent="onPointerUp"
        ></canvas>

        <!-- Floating text input -->
        <input
          v-if="textInput.visible"
          ref="textInputRef"
          v-model="textInput.value"
          @keydown.enter="commitText"
          @blur="commitText"
          class="absolute bg-transparent outline-none border-b-2 border-dashed text-white font-bold"
          :style="{
            left: (offsetX + textInput.x) + 'px',
            top: (offsetY + textInput.y) + 'px',
            fontSize: Math.max(16, strokeWidth * 6) + 'px',
            color: activeColor,
            borderColor: activeColor,
            minWidth: '60px',
            zIndex: 10
          }"
          placeholder="Type..."
        />
      </div>

    </div>
  </Teleport>
</template>
