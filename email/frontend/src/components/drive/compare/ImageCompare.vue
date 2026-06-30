<script setup>
import { ref, computed, watch, nextTick, onMounted, onUnmounted } from 'vue'

const props = defineProps({
  leftContent: Object,
  rightContent: Object,
  leftVersion: Object,
  rightVersion: Object,
  mode: { type: String, default: 'side-by-side' }
})

// Slider state
const sliderPosition = ref(50)
const isDragging = ref(false)
const containerRef = ref(null)

// Zoom state
const zoom = ref(100)
const fitToView = ref(true)

// Sync scroll state
const syncScroll = ref(true)
const leftScrollRef = ref(null)
const rightScrollRef = ref(null)
let isScrolling = false

// Onion-skin state
const onionOpacity = ref(50)

// Pixel diff state
const pixelCanvasRef = ref(null)
const pixelDiffPercent = ref(null)
const pixelDiffComputing = ref(false)
const pixelDiffError = ref(null)
const DIFF_THRESHOLD = 30 // summed RGB delta below this counts as unchanged

const leftImage = computed(() => props.leftContent?.content)
const rightImage = computed(() => props.rightContent?.content)

// ── Sync scroll ──

function onLeftScroll(e) {
  if (!syncScroll.value || isScrolling) return
  isScrolling = true
  const rightEl = rightScrollRef.value
  if (rightEl) {
    rightEl.scrollTop = e.target.scrollTop
    rightEl.scrollLeft = e.target.scrollLeft
  }
  requestAnimationFrame(() => { isScrolling = false })
}

function onRightScroll(e) {
  if (!syncScroll.value || isScrolling) return
  isScrolling = true
  const leftEl = leftScrollRef.value
  if (leftEl) {
    leftEl.scrollTop = e.target.scrollTop
    leftEl.scrollLeft = e.target.scrollLeft
  }
  requestAnimationFrame(() => { isScrolling = false })
}

// ── Slider drag ──

function handleMouseDown(e) {
  isDragging.value = true
  updateSliderPosition(e)
}

function handleMouseMove(e) {
  if (isDragging.value) updateSliderPosition(e)
}

function handleMouseUp() {
  isDragging.value = false
}

function updateSliderPosition(e) {
  if (!containerRef.value) return
  const rect = containerRef.value.getBoundingClientRect()
  const x = e.clientX - rect.left
  sliderPosition.value = Math.min(100, Math.max(0, (x / rect.width) * 100))
}

function handleTouchStart(e) {
  isDragging.value = true
  updateSliderPositionTouch(e)
}

function handleTouchMove(e) {
  if (isDragging.value) updateSliderPositionTouch(e)
}

function updateSliderPositionTouch(e) {
  if (!containerRef.value || !e.touches[0]) return
  const rect = containerRef.value.getBoundingClientRect()
  const x = e.touches[0].clientX - rect.left
  sliderPosition.value = Math.min(100, Math.max(0, (x / rect.width) * 100))
}

// ── Zoom ──

function zoomIn() {
  fitToView.value = false
  zoom.value = Math.min(300, zoom.value + 25)
}

function zoomOut() {
  fitToView.value = false
  zoom.value = Math.max(25, zoom.value - 25)
}

function resetZoom() {
  zoom.value = 100
  fitToView.value = false
}

function toggleFitToView() {
  fitToView.value = !fitToView.value
  if (fitToView.value) zoom.value = 100
}

// ── Pixel diff ──

function loadImageEl(src) {
  return new Promise((resolve, reject) => {
    const img = new Image()
    img.onload = () => resolve(img)
    img.onerror = () => reject(new Error('Image load failed'))
    img.src = src
  })
}

async function computePixelDiff() {
  if (!leftImage.value || !rightImage.value) return
  pixelDiffComputing.value = true
  pixelDiffError.value = null
  pixelDiffPercent.value = null

  try {
    const [imgA, imgB] = await Promise.all([
      loadImageEl(leftImage.value),
      loadImageEl(rightImage.value),
    ])

    const w = Math.max(imgA.naturalWidth, imgB.naturalWidth)
    const h = Math.max(imgA.naturalHeight, imgB.naturalHeight)

    const draw = (img) => {
      const c = document.createElement('canvas')
      c.width = w
      c.height = h
      const ctx = c.getContext('2d', { willReadFrequently: true })
      ctx.drawImage(img, 0, 0, w, h)
      return ctx.getImageData(0, 0, w, h)
    }

    const dataA = draw(imgA)
    const dataB = draw(imgB)

    await nextTick()
    const canvas = pixelCanvasRef.value
    if (!canvas) return
    canvas.width = w
    canvas.height = h
    const outCtx = canvas.getContext('2d')
    const out = outCtx.createImageData(w, h)

    let diffCount = 0
    const a = dataA.data
    const b = dataB.data
    const o = out.data

    for (let i = 0; i < a.length; i += 4) {
      const delta = Math.abs(a[i] - b[i]) + Math.abs(a[i + 1] - b[i + 1]) + Math.abs(a[i + 2] - b[i + 2])
      if (delta > DIFF_THRESHOLD) {
        // Highlight differing pixel in magenta, intensity scaled by delta
        const strength = Math.min(255, 120 + delta / 3)
        o[i] = strength
        o[i + 1] = 0
        o[i + 2] = strength
        o[i + 3] = 255
        diffCount++
      } else {
        // Dimmed grayscale of the newer image as context
        const gray = (b[i] * 0.299 + b[i + 1] * 0.587 + b[i + 2] * 0.114) * 0.35 + 40
        o[i] = gray
        o[i + 1] = gray
        o[i + 2] = gray
        o[i + 3] = 255
      }
    }

    outCtx.putImageData(out, 0, 0)
    pixelDiffPercent.value = ((diffCount / (w * h)) * 100).toFixed(2)
  } catch (e) {
    console.error('Pixel diff failed:', e)
    pixelDiffError.value = e.message
  } finally {
    pixelDiffComputing.value = false
  }
}

watch([() => props.mode, leftImage, rightImage], ([mode]) => {
  if (mode === 'pixel' && leftImage.value && rightImage.value) {
    computePixelDiff()
  }
}, { immediate: true })

onMounted(() => {
  document.addEventListener('mouseup', handleMouseUp)
  document.addEventListener('mousemove', handleMouseMove)
})

onUnmounted(() => {
  document.removeEventListener('mouseup', handleMouseUp)
  document.removeEventListener('mousemove', handleMouseMove)
})
</script>

<template>
  <div class="h-full flex flex-col">
    <!-- Toolbar -->
    <div class="flex items-center justify-between px-4 py-2 border-b border-surface-200 dark:border-surface-700 bg-surface-50 dark:bg-surface-800/50">
      <!-- Zoom controls (not relevant for pixel diff) -->
      <div v-if="mode !== 'pixel'" class="flex items-center gap-2">
        <button
          @click="toggleFitToView"
          :class="[
            'px-3 py-1 text-sm font-medium rounded-lg flex items-center gap-1.5 transition-colors',
            fitToView
              ? 'bg-primary-500 text-white'
              : 'text-surface-600 dark:text-surface-400 hover:bg-surface-200 dark:hover:bg-surface-700'
          ]"
          :title="$t('imageCompare.fitToViewport')"
        >
          <span class="material-symbols-rounded text-sm">fit_screen</span>
          {{ $t('imageCompare.fit') }}
        </button>
        <div class="w-px h-5 bg-surface-300 dark:bg-surface-600"></div>
        <button @click="zoomOut" class="p-1.5 hover:bg-surface-200 dark:hover:bg-surface-700 rounded-lg" :title="$t('imageCompare.zoomOut')">
          <span class="material-symbols-rounded text-surface-600 dark:text-surface-400">remove</span>
        </button>
        <button @click="resetZoom" class="px-3 py-1 text-sm font-medium text-surface-600 dark:text-surface-400 hover:bg-surface-200 dark:hover:bg-surface-700 rounded-lg min-w-[60px]">
          {{ fitToView ? $t('imageCompare.fit') : zoom + '%' }}
        </button>
        <button @click="zoomIn" class="p-1.5 hover:bg-surface-200 dark:hover:bg-surface-700 rounded-lg" :title="$t('imageCompare.zoomIn')">
          <span class="material-symbols-rounded text-surface-600 dark:text-surface-400">add</span>
        </button>
      </div>
      <div v-else class="flex items-center gap-2 text-sm text-surface-500">
        <span class="w-3 h-3 rounded-sm" style="background:#d633d6"></span>
        {{ $t('imageCompare.pixelDiffLegend') }}
        <span v-if="pixelDiffPercent !== null" class="font-medium text-surface-700 dark:text-surface-300">
          · {{ $t('imageCompare.pixelDiffChanged', { percent: pixelDiffPercent }) }}
        </span>
      </div>

      <!-- Onion opacity slider -->
      <div v-if="mode === 'onion'" class="flex items-center gap-3 text-sm text-surface-600 dark:text-surface-400">
        <span class="text-xs font-medium text-amber-600 dark:text-amber-400">v{{ leftVersion?.version_number }}</span>
        <input
          type="range"
          min="0"
          max="100"
          v-model.number="onionOpacity"
          class="w-40 accent-primary-500"
          :title="$t('imageCompare.onionOpacity')"
        />
        <span class="text-xs font-medium text-green-600 dark:text-green-400">v{{ rightVersion?.version_number }}</span>
        <span class="text-xs text-surface-400 min-w-[36px]">{{ onionOpacity }}%</span>
      </div>

      <!-- Sync scroll toggle (only for side-by-side) -->
      <label v-if="mode === 'side-by-side'" class="flex items-center gap-2 text-sm text-surface-600 dark:text-surface-400 cursor-pointer select-none">
        <div
          @click="syncScroll = !syncScroll"
          :class="[
            'relative w-10 h-5 rounded-full transition-colors cursor-pointer',
            syncScroll ? 'bg-primary-500' : 'bg-surface-300 dark:bg-surface-600'
          ]"
        >
          <div
            :class="[
              'absolute top-0.5 w-4 h-4 bg-white rounded-full shadow transition-transform',
              syncScroll ? 'translate-x-5' : 'translate-x-0.5'
            ]"
          ></div>
        </div>
        <span class="flex items-center gap-1">
          <span class="material-symbols-rounded text-sm">sync</span>
          {{ $t('imageCompare.syncScroll') }}
        </span>
      </label>
    </div>

    <!-- Side by Side View -->
    <div v-if="mode === 'side-by-side'" class="flex-1 flex overflow-hidden">
      <!-- Left (older) -->
      <div class="flex-1 flex flex-col border-r border-surface-200 dark:border-surface-700 min-w-0">
        <div class="px-3 py-1.5 bg-amber-50 dark:bg-amber-500/10 border-b border-surface-200 dark:border-surface-700 flex-shrink-0">
          <div class="flex items-center gap-2 text-xs">
            <span class="material-symbols-rounded text-sm text-amber-500">history</span>
            <span class="font-medium text-amber-700 dark:text-amber-300">
              {{ $t('versionCompare.olderVersion', { number: leftVersion?.version_number }) }}
            </span>
          </div>
        </div>
        <div
          ref="leftScrollRef"
          @scroll="onLeftScroll"
          :class="[
            'flex-1 image-bg',
            fitToView ? 'overflow-hidden flex items-center justify-center p-4' : 'overflow-auto'
          ]"
        >
          <img
            :src="leftImage"
            :style="fitToView ? {} : { transform: `scale(${zoom / 100})` }"
            :class="[
              'transition-transform shadow-lg',
              fitToView
                ? 'max-w-full max-h-full object-contain'
                : 'max-w-none origin-center m-auto block'
            ]"
            alt=""
          />
        </div>
      </div>

      <!-- Right (newer) -->
      <div class="flex-1 flex flex-col min-w-0">
        <div class="px-3 py-1.5 bg-green-50 dark:bg-green-500/10 border-b border-surface-200 dark:border-surface-700 flex-shrink-0">
          <div class="flex items-center gap-2 text-xs">
            <span class="material-symbols-rounded text-sm text-green-500">update</span>
            <span class="font-medium text-green-700 dark:text-green-300">
              {{ $t('versionCompare.newerVersion', { number: rightVersion?.version_number }) }}
            </span>
          </div>
        </div>
        <div
          ref="rightScrollRef"
          @scroll="onRightScroll"
          :class="[
            'flex-1 image-bg',
            fitToView ? 'overflow-hidden flex items-center justify-center p-4' : 'overflow-auto'
          ]"
        >
          <img
            :src="rightImage"
            :style="fitToView ? {} : { transform: `scale(${zoom / 100})` }"
            :class="[
              'transition-transform shadow-lg',
              fitToView
                ? 'max-w-full max-h-full object-contain'
                : 'max-w-none origin-center m-auto block'
            ]"
            alt=""
          />
        </div>
      </div>
    </div>

    <!-- Slider View -->
    <div
      v-else-if="mode === 'slider'"
      ref="containerRef"
      class="flex-1 relative overflow-hidden cursor-ew-resize image-bg"
      @mousedown="handleMouseDown"
      @touchstart="handleTouchStart"
      @touchmove="handleTouchMove"
      @touchend="handleMouseUp"
    >
      <!-- Right image (newer - background) -->
      <div class="absolute inset-0 flex items-center justify-center p-4">
        <img
          :src="rightImage"
          :style="fitToView ? {} : { transform: `scale(${zoom / 100})` }"
          :class="[
            'transition-transform origin-center',
            fitToView ? 'max-w-full max-h-full object-contain' : 'max-w-none'
          ]"
          alt=""
        />
      </div>

      <!-- Left image (older - clipped overlay) -->
      <div
        class="absolute inset-0 flex items-center justify-center overflow-hidden p-4"
        :style="{ clipPath: `inset(0 ${100 - sliderPosition}% 0 0)` }"
      >
        <img
          :src="leftImage"
          :style="fitToView ? {} : { transform: `scale(${zoom / 100})` }"
          :class="[
            'transition-transform origin-center',
            fitToView ? 'max-w-full max-h-full object-contain' : 'max-w-none'
          ]"
          alt=""
        />
      </div>

      <!-- Slider handle -->
      <div
        class="absolute top-0 bottom-0 w-1 bg-white shadow-lg cursor-ew-resize z-10"
        :style="{ left: `${sliderPosition}%`, transform: 'translateX(-50%)' }"
      >
        <div class="absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 w-10 h-10 bg-white rounded-full shadow-lg flex items-center justify-center">
          <span class="material-symbols-rounded text-surface-600">drag_indicator</span>
        </div>

        <div class="absolute top-4 right-4 px-2 py-1 bg-amber-500 text-white text-xs font-medium rounded shadow">
          v{{ leftVersion?.version_number }}
        </div>
        <div class="absolute top-4 left-4 px-2 py-1 bg-green-500 text-white text-xs font-medium rounded shadow">
          v{{ rightVersion?.version_number }}
        </div>
      </div>

      <div class="absolute bottom-4 left-1/2 -translate-x-1/2 px-3 py-1.5 bg-black/50 text-white text-xs rounded-full backdrop-blur-sm">
        {{ $t('imageCompare.dragToCompare') }}
      </div>
    </div>

    <!-- Onion-Skin View -->
    <div v-else-if="mode === 'onion'" class="flex-1 relative overflow-hidden image-bg">
      <!-- Older (base layer) -->
      <div class="absolute inset-0 flex items-center justify-center p-4">
        <img :src="leftImage" class="max-w-full max-h-full object-contain" alt="" />
      </div>
      <!-- Newer (fading layer) -->
      <div class="absolute inset-0 flex items-center justify-center p-4" :style="{ opacity: onionOpacity / 100 }">
        <img :src="rightImage" class="max-w-full max-h-full object-contain" alt="" />
      </div>
    </div>

    <!-- Pixel Diff View -->
    <div v-else-if="mode === 'pixel'" class="flex-1 relative overflow-auto image-bg flex items-center justify-center p-4">
      <div v-if="pixelDiffComputing" class="text-center">
        <div class="animate-spin rounded-full h-10 w-10 border-b-2 border-primary-500 mx-auto mb-3"></div>
        <p class="text-surface-400 text-sm">{{ $t('imageCompare.computingDiff') }}</p>
      </div>
      <p v-else-if="pixelDiffError" class="text-red-400 text-sm">{{ pixelDiffError }}</p>
      <canvas
        v-show="!pixelDiffComputing && !pixelDiffError"
        ref="pixelCanvasRef"
        class="max-w-full max-h-full object-contain shadow-lg"
      ></canvas>
    </div>
  </div>
</template>

<style scoped>
.image-bg {
  background-color: #18181b;
}
</style>
