<template>
  <teleport to="body">
    <div
      class="fixed inset-0 z-[10001] flex items-center justify-center"
      @mousedown.self="$emit('close')"
    >
      <!-- Backdrop -->
      <div class="absolute inset-0 bg-black/50 backdrop-blur-sm" />

      <!-- Modal card -->
      <div class="relative bg-white dark:bg-surface-800 rounded-2xl shadow-2xl w-[420px] max-h-[90vh] overflow-hidden flex flex-col animate-scale-in">
        <!-- Header -->
        <div class="flex items-center justify-between px-5 py-3.5 border-b border-surface-100 dark:border-surface-700">
          <h3 class="text-sm font-semibold text-surface-800 dark:text-surface-200 flex items-center gap-2">
            <span class="material-symbols-rounded text-lg text-primary-500">palette</span>
            {{ title }}
          </h3>
          <button
            @click="$emit('close')"
            class="p-1.5 rounded-lg hover:bg-surface-100 dark:hover:bg-surface-700 text-surface-400 hover:text-surface-600 dark:hover:text-surface-300 transition-colors"
          >
            <span class="material-symbols-rounded text-lg">close</span>
          </button>
        </div>

        <!-- Body -->
        <div class="flex-1 overflow-y-auto px-5 py-4 space-y-4">
          <!-- Color area + hue bar -->
          <div class="space-y-3">
            <!-- Saturation/Brightness picker -->
            <div
              ref="satBrightRef"
              class="relative w-full h-48 rounded-xl cursor-crosshair overflow-hidden select-none"
              :style="{ backgroundColor: pureHueColor }"
              @mousedown="startSatBrightDrag"
            >
              <div class="absolute inset-0" style="background: linear-gradient(to right, #fff, transparent);" />
              <div class="absolute inset-0" style="background: linear-gradient(to top, #000, transparent);" />
              <!-- Cursor -->
              <div
                class="absolute w-4 h-4 rounded-full border-2 border-white shadow-md pointer-events-none -translate-x-1/2 -translate-y-1/2"
                :style="{ left: (saturation * 100) + '%', top: ((1 - brightness) * 100) + '%', backgroundColor: currentHex }"
              />
            </div>

            <!-- Hue slider -->
            <div
              ref="hueBarRef"
              class="relative w-full h-4 rounded-full cursor-pointer select-none"
              style="background: linear-gradient(to right, #f00, #ff0, #0f0, #0ff, #00f, #f0f, #f00);"
              @mousedown="startHueDrag"
            >
              <div
                class="absolute top-1/2 -translate-y-1/2 -translate-x-1/2 w-5 h-5 rounded-full border-2 border-white shadow-md pointer-events-none"
                :style="{ left: (hue / 360 * 100) + '%', backgroundColor: pureHueColor }"
              />
            </div>
          </div>

          <!-- Preview + hex input -->
          <div class="flex items-center gap-3">
            <div class="w-14 h-14 rounded-xl border border-surface-200 dark:border-surface-600 overflow-hidden relative flex-shrink-0">
              <div class="absolute inset-0 checkerboard-bg" />
              <div class="absolute inset-0" :style="{ backgroundColor: currentHex }" />
            </div>
            <div class="flex-1 space-y-2">
              <div class="flex items-center gap-2">
                <label class="text-[10px] font-semibold text-surface-400 uppercase tracking-wider w-8 flex-shrink-0">HEX</label>
                <input
                  :value="currentHex"
                  @change="onHexType($event.target.value)"
                  class="flex-1 text-xs font-mono uppercase bg-surface-50 dark:bg-surface-700 border border-surface-200 dark:border-surface-600 rounded-lg px-2.5 py-1.5 text-surface-700 dark:text-surface-300 focus:outline-none focus:ring-1 focus:ring-primary-400"
                  placeholder="#RRGGBB"
                />
              </div>
            </div>
          </div>

          <!-- RGB inputs -->
          <div class="grid grid-cols-3 gap-2">
            <div v-for="(ch, label) in { R: rgb.r, G: rgb.g, B: rgb.b }" :key="label">
              <label class="block text-[10px] font-semibold text-surface-400 uppercase tracking-wider mb-1">{{ label }}</label>
              <input
                type="number"
                :value="ch"
                min="0"
                max="255"
                @change="onRgbChange(label, parseInt($event.target.value) || 0)"
                class="w-full text-xs bg-surface-50 dark:bg-surface-700 border border-surface-200 dark:border-surface-600 rounded-lg px-2.5 py-1.5 text-surface-700 dark:text-surface-300 focus:outline-none focus:ring-1 focus:ring-primary-400 [appearance:textfield] [&::-webkit-outer-spin-button]:appearance-none [&::-webkit-inner-spin-button]:appearance-none"
              />
            </div>
          </div>

          <!-- CMYK (read-only) -->
          <div class="grid grid-cols-4 gap-2">
            <div v-for="(val, label) in cmyk" :key="label">
              <label class="block text-[10px] font-semibold text-surface-400 uppercase tracking-wider mb-1">{{ label }}</label>
              <div class="text-xs bg-surface-50 dark:bg-surface-700 border border-surface-200 dark:border-surface-600 rounded-lg px-2.5 py-1.5 text-surface-500 dark:text-surface-400 text-center">
                {{ val }}%
              </div>
            </div>
          </div>

          <!-- Quick swatches -->
          <div>
            <p class="text-[10px] font-semibold text-surface-400 uppercase tracking-wider mb-2">Quick Colors</p>
            <div class="flex flex-wrap gap-1.5">
              <button
                v-for="(c, idx) in quickColors"
                :key="idx"
                @click="applyQuickColor(c)"
                class="w-7 h-7 rounded-lg border hover:scale-110 transition-transform cursor-pointer"
                :class="c.toLowerCase() === currentHex.toLowerCase() ? 'border-primary-500 ring-2 ring-primary-300' : 'border-surface-200 dark:border-surface-600'"
                :style="{ backgroundColor: c }"
                :title="c"
              />
            </div>
          </div>
        </div>

        <!-- Footer -->
        <div class="flex items-center justify-end gap-2 px-5 py-3 border-t border-surface-100 dark:border-surface-700 bg-surface-50 dark:bg-surface-800">
          <button
            @click="$emit('close')"
            class="px-4 py-1.5 text-xs font-medium rounded-lg text-surface-600 dark:text-surface-400 hover:bg-surface-100 dark:hover:bg-surface-700 transition-colors"
          >
            Cancel
          </button>
          <button
            @click="onConfirm"
            class="px-5 py-1.5 text-xs font-semibold rounded-lg bg-primary-500 hover:bg-primary-600 text-white transition-colors shadow-sm"
          >
            {{ confirmLabel }}
          </button>
        </div>
      </div>
    </div>
  </teleport>
</template>

<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue'

const props = defineProps({
  initialColor: { type: String, default: '#6366f1' },
  title: { type: String, default: 'Pick a Color' },
  confirmLabel: { type: String, default: 'Add Swatch' },
})

const emit = defineEmits(['select', 'close'])

// ---- HSB state (canonical) ----
const hue = ref(0)
const saturation = ref(1)
const brightness = ref(1)

// Refs to DOM elements for dragging
const satBrightRef = ref(null)
const hueBarRef = ref(null)
let dragging = null // 'sb' or 'hue'

// ---- Quick color palette ----
const quickColors = [
  '#ef4444', '#f97316', '#f59e0b', '#eab308', '#84cc16',
  '#22c55e', '#10b981', '#14b8a6', '#06b6d4', '#0ea5e9',
  '#3b82f6', '#6366f1', '#8b5cf6', '#a855f7', '#d946ef',
  '#ec4899', '#f43f5e', '#000000', '#374151', '#6b7280',
  '#9ca3af', '#d1d5db', '#f3f4f6', '#ffffff',
]

// ---- Derived values ----
const rgb = computed(() => hsbToRgb(hue.value, saturation.value, brightness.value))

const currentHex = computed(() => {
  const { r, g, b } = rgb.value
  return '#' + [r, g, b].map(c => c.toString(16).padStart(2, '0')).join('')
})

const pureHueColor = computed(() => {
  const { r, g, b } = hsbToRgb(hue.value, 1, 1)
  return `rgb(${r},${g},${b})`
})

const cmyk = computed(() => {
  const { r, g, b } = rgb.value
  const rr = r / 255, gg = g / 255, bb = b / 255
  const k = 1 - Math.max(rr, gg, bb)
  if (k >= 1) return { C: 0, M: 0, Y: 0, K: 100 }
  return {
    C: Math.round(((1 - rr - k) / (1 - k)) * 100),
    M: Math.round(((1 - gg - k) / (1 - k)) * 100),
    Y: Math.round(((1 - bb - k) / (1 - k)) * 100),
    K: Math.round(k * 100),
  }
})

// ---- Initialize from prop ----
onMounted(() => {
  applyHex(props.initialColor)
  document.addEventListener('mousemove', onDrag)
  document.addEventListener('mouseup', stopDrag)
  // Prevent body scroll while modal is open
  document.body.style.overflow = 'hidden'
})

onUnmounted(() => {
  document.removeEventListener('mousemove', onDrag)
  document.removeEventListener('mouseup', stopDrag)
  document.body.style.overflow = ''
})

// ---- Conversions ----
function hsbToRgb(h, s, v) {
  let r, g, b
  const i = Math.floor(h / 60) % 6
  const f = h / 60 - i
  const p = v * (1 - s)
  const q = v * (1 - f * s)
  const t = v * (1 - (1 - f) * s)
  switch (i) {
    case 0: r = v; g = t; b = p; break
    case 1: r = q; g = v; b = p; break
    case 2: r = p; g = v; b = t; break
    case 3: r = p; g = q; b = v; break
    case 4: r = t; g = p; b = v; break
    case 5: r = v; g = p; b = q; break
  }
  return { r: Math.round(r * 255), g: Math.round(g * 255), b: Math.round(b * 255) }
}

function rgbToHsb(r, g, b) {
  r /= 255; g /= 255; b /= 255
  const max = Math.max(r, g, b), min = Math.min(r, g, b)
  const d = max - min
  let h = 0
  if (d > 0) {
    if (max === r) h = ((g - b) / d + 6) % 6 * 60
    else if (max === g) h = ((b - r) / d + 2) * 60
    else h = ((r - g) / d + 4) * 60
  }
  const s = max === 0 ? 0 : d / max
  return { h, s, b: max }
}

function applyHex(hex) {
  hex = (hex || '#000000').replace('#', '')
  if (hex.length === 3) hex = hex[0] + hex[0] + hex[1] + hex[1] + hex[2] + hex[2]
  const r = parseInt(hex.substring(0, 2), 16) || 0
  const g = parseInt(hex.substring(2, 4), 16) || 0
  const b = parseInt(hex.substring(4, 6), 16) || 0
  const hsb = rgbToHsb(r, g, b)
  hue.value = hsb.h
  saturation.value = hsb.s
  brightness.value = hsb.b
}

// ---- User inputs ----
function onHexType(val) {
  val = val.trim()
  if (!val.startsWith('#')) val = '#' + val
  if (/^#[0-9a-fA-F]{3,6}$/.test(val)) {
    applyHex(val)
  }
}

function onRgbChange(channel, value) {
  value = Math.max(0, Math.min(255, value))
  const current = { ...rgb.value }
  if (channel === 'R') current.r = value
  else if (channel === 'G') current.g = value
  else current.b = value
  const hsb = rgbToHsb(current.r, current.g, current.b)
  hue.value = hsb.h
  saturation.value = hsb.s
  brightness.value = hsb.b
}

function applyQuickColor(hex) {
  applyHex(hex)
}

// ---- Drag logic: Saturation/Brightness area ----
function startSatBrightDrag(e) {
  dragging = 'sb'
  updateSatBright(e)
}

function updateSatBright(e) {
  const rect = satBrightRef.value?.getBoundingClientRect()
  if (!rect) return
  const x = Math.max(0, Math.min(1, (e.clientX - rect.left) / rect.width))
  const y = Math.max(0, Math.min(1, (e.clientY - rect.top) / rect.height))
  saturation.value = x
  brightness.value = 1 - y
}

// ---- Drag logic: Hue bar ----
function startHueDrag(e) {
  dragging = 'hue'
  updateHue(e)
}

function updateHue(e) {
  const rect = hueBarRef.value?.getBoundingClientRect()
  if (!rect) return
  const x = Math.max(0, Math.min(1, (e.clientX - rect.left) / rect.width))
  hue.value = x * 360
}

function onDrag(e) {
  if (!dragging) return
  e.preventDefault()
  if (dragging === 'sb') updateSatBright(e)
  else if (dragging === 'hue') updateHue(e)
}

function stopDrag() {
  dragging = null
}

// ---- Confirm ----
function onConfirm() {
  const { r, g, b } = rgb.value
  const hex = currentHex.value

  // RGB -> CMYK
  const rr = r / 255, gg = g / 255, bb = b / 255
  const k = 1 - Math.max(rr, gg, bb)
  let c = 0, m = 0, y = 0
  if (k < 1) {
    c = Math.round(((1 - rr - k) / (1 - k)) * 100)
    m = Math.round(((1 - gg - k) / (1 - k)) * 100)
    y = Math.round(((1 - bb - k) / (1 - k)) * 100)
  }

  emit('select', {
    hex,
    rgb: { r, g, b },
    cmyk: { c, m, y, k: Math.round(k * 100) }
  })
}
</script>

<style scoped>
.animate-scale-in {
  animation: scaleIn 0.15s ease-out;
}
@keyframes scaleIn {
  from { transform: scale(0.95); opacity: 0; }
  to   { transform: scale(1);    opacity: 1; }
}
.checkerboard-bg {
  background-image:
    linear-gradient(45deg, #ccc 25%, transparent 25%),
    linear-gradient(-45deg, #ccc 25%, transparent 25%),
    linear-gradient(45deg, transparent 75%, #ccc 75%),
    linear-gradient(-45deg, transparent 75%, #ccc 75%);
  background-size: 8px 8px;
  background-position: 0 0, 0 4px, 4px -4px, -4px 0px;
}
</style>

