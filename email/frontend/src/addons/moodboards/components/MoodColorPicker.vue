<template>
  <div class="relative" ref="rootRef">
    <!-- Trigger swatch button (double-click removes color when transparent is allowed) -->
    <button
      ref="triggerRef"
      @click.stop="toggleOpen"
      @dblclick.stop="onSwatchDoubleClick"
      class="flex items-center gap-1 px-1.5 py-1 rounded-lg hover:bg-surface-100 dark:hover:bg-surface-700 transition-colors"
      :title="label || 'Pick color'"
    >
      <div class="w-5 h-5 rounded border border-surface-300 dark:border-surface-500 flex-shrink-0 overflow-hidden relative">
        <div class="absolute inset-0 checkerboard-bg" />
        <div class="absolute inset-0" :style="{ backgroundColor: displayColor }" />
        <!-- No-color indicator -->
        <div v-if="isTransparent" class="absolute inset-0 flex items-center justify-center">
          <div class="w-[141%] h-[1.5px] bg-red-500 rotate-45 rounded-full" />
        </div>
      </div>
      <span v-if="showCaret" class="material-symbols-rounded text-[11px] text-surface-400">expand_more</span>
    </button>

    <!-- Dropdown panel — teleported to body so it escapes overflow:hidden/auto ancestors -->
    <Teleport to="body">
    <div
      v-if="open"
      ref="dropdownRef"
      class="fixed w-64 bg-white dark:bg-surface-800 border border-surface-200 dark:border-surface-700 rounded-xl shadow-2xl overflow-hidden"
      :style="dropdownFixedStyle"
      style="z-index: 9999;"
      @mousedown.stop
      @click.stop
    >
      <!-- Contrast ratio bar (only when contrastBg is provided) -->
      <div v-if="contrastBg" class="flex items-center justify-between px-3 py-1.5 border-b border-surface-100 dark:border-surface-700">
        <div class="flex items-center gap-1.5">
          <div
            class="w-3.5 h-3.5 rounded-full border border-surface-200 dark:border-surface-600"
            :style="{ backgroundColor: pureHexColor }"
          />
          <span class="text-[10px] font-semibold text-surface-700 dark:text-surface-200">{{ contrastRatioDisplay }} : 1</span>
        </div>
        <span
          class="text-[9px] font-bold px-1.5 py-0.5 rounded-md"
          :class="contrastBadgeClass"
        >{{ contrastLevel }}</span>
      </div>

      <div class="p-2">
        <!-- No color / transparent button -->
        <button
          v-if="allowTransparent"
          @click.stop="applyTransparent"
          class="flex items-center gap-1.5 w-full px-2 py-1 rounded-lg text-[10px] font-medium transition-colors mb-2"
          :class="isTransparent
            ? 'bg-primary-500/15 text-primary-600 dark:text-primary-400'
            : 'hover:bg-surface-100 dark:hover:bg-surface-700 text-surface-500 dark:text-surface-400'"
        >
          <div class="w-4 h-4 rounded border border-surface-300 dark:border-surface-500 relative overflow-hidden flex-shrink-0">
            <div class="absolute inset-0 bg-white dark:bg-surface-700" />
            <div class="absolute inset-0 flex items-center justify-center">
              <div class="w-[141%] h-[1.5px] bg-red-500 rotate-45 rounded-full" />
            </div>
          </div>
          No color
        </button>

        <!-- HSV color area (Saturation X, Value Y) -->
        <div
          ref="svAreaRef"
          class="relative w-full h-40 rounded-lg cursor-crosshair overflow-hidden select-none"
          :style="{ background: `hsl(${hue}, 100%, 50%)` }"
          @mousedown.stop="startSvDrag"
        >
          <div class="absolute inset-0" style="background: linear-gradient(to right, #fff, transparent);" />
          <div class="absolute inset-0" style="background: linear-gradient(to bottom, transparent, #000);" />
          <!-- Contrast boundary curve -->
          <svg
            v-if="contrastBg && contrastCurvePath"
            class="absolute inset-0 w-full h-full pointer-events-none"
            viewBox="0 0 100 100"
            preserveAspectRatio="none"
          >
            <path :d="contrastCurvePath" fill="none" stroke="rgba(255,255,255,0.45)" stroke-width="0.8" vector-effect="non-scaling-stroke" />
          </svg>
          <!-- Cursor -->
          <div
            class="absolute w-3.5 h-3.5 rounded-full border-2 border-white shadow-md pointer-events-none"
            :style="{ left: (sat * 100) + '%', top: ((1 - val) * 100) + '%', transform: 'translate(-50%, -50%)', backgroundColor: pureHexColor }"
          />
        </div>

        <!-- Hue bar + eyedropper -->
        <div class="flex items-center gap-1.5 mt-2">
          <button
            v-if="hasEyeDropper"
            @click.stop="pickWithEyedropper"
            class="flex-shrink-0 w-6 h-6 flex items-center justify-center rounded-md bg-surface-50 dark:bg-surface-700 text-surface-500 hover:text-primary-500 transition-colors"
            title="Pick color from screen"
          >
            <span class="material-symbols-rounded text-[14px]">colorize</span>
          </button>
          <div
            ref="hueBarRef"
            class="relative flex-1 h-2.5 rounded-full cursor-pointer select-none"
            style="background: linear-gradient(to right, #f00, #ff0, #0f0, #0ff, #00f, #f0f, #f00);"
            @mousedown.stop="startHueDrag"
          >
            <div
              class="absolute top-1/2 w-3 h-3 rounded-full border-2 border-white shadow-md pointer-events-none"
              :style="{ left: (hue / 360 * 100) + '%', transform: 'translate(-50%, -50%)', backgroundColor: `hsl(${hue}, 100%, 50%)` }"
            />
          </div>
        </div>

        <!-- Alpha slider -->
        <div class="flex items-center gap-1.5 mt-1.5">
          <div v-if="hasEyeDropper" class="flex-shrink-0 w-6" />
          <div class="relative flex-1 h-2.5 rounded-full overflow-hidden select-none" ref="alphaBarRef" @mousedown.stop="startAlphaDrag">
            <div class="absolute inset-0 checkerboard-bg rounded-full" />
            <div class="absolute inset-0 rounded-full cursor-pointer" :style="{ background: `linear-gradient(to right, transparent, ${pureHexColor})` }" />
            <div
              class="absolute top-1/2 w-3 h-3 rounded-full border-2 border-white shadow-md pointer-events-none"
              :style="{ left: alphaPercent + '%', transform: 'translate(-50%, -50%)' }"
            />
          </div>
        </div>

        <!-- Hex + Opacity (single compact row) -->
        <div class="flex items-center gap-1 mt-2">
          <div class="flex items-center flex-1 bg-surface-50 dark:bg-surface-700 rounded-md border border-surface-200 dark:border-surface-600 overflow-hidden h-7">
            <span class="text-[9px] font-semibold text-surface-400 px-1.5 select-none">#</span>
            <input
              :value="pureHexColor.replace('#', '')"
              @change.stop="onHexType($event.target.value)"
              @click.stop
              class="flex-1 text-[11px] font-mono uppercase bg-transparent px-0.5 text-surface-700 dark:text-surface-300 focus:outline-none min-w-0"
              placeholder="000000"
            />
          </div>
          <div class="flex items-center bg-surface-50 dark:bg-surface-700 rounded-md border border-surface-200 dark:border-surface-600 overflow-hidden h-7 w-14">
            <input
              type="number"
              :value="alphaPercent"
              min="0" max="100"
              @change.stop="onAlphaInput(Math.max(0, Math.min(100, parseInt($event.target.value) || 100)))"
              @click.stop
              class="w-full text-[10px] text-center bg-transparent text-surface-700 dark:text-surface-300 focus:outline-none [appearance:textfield] [&::-webkit-outer-spin-button]:appearance-none [&::-webkit-inner-spin-button]:appearance-none"
            />
            <span class="text-[9px] text-surface-400 pr-1 select-none">%</span>
          </div>
          <!-- Toggle RGB inputs -->
          <button
            @click.stop="showRgb = !showRgb"
            class="flex-shrink-0 w-7 h-7 flex items-center justify-center rounded-md transition-colors"
            :class="showRgb ? 'bg-primary-500/15 text-primary-500' : 'bg-surface-50 dark:bg-surface-700 text-surface-400 hover:text-surface-600 dark:hover:text-surface-300'"
            title="Toggle RGB inputs"
          >
            <span class="text-[9px] font-bold">RGB</span>
          </button>
        </div>

        <!-- RGB inputs (collapsible) -->
        <div v-if="showRgb" class="flex items-center gap-1 mt-1">
          <div v-for="ch in rgbChannels" :key="ch.key" class="flex-1">
            <input
              type="number"
              :value="ch.value"
              min="0" max="255"
              @change.stop="onRgbInput(ch.key, $event.target.value)"
              @click.stop
              class="w-full text-[10px] text-center bg-surface-50 dark:bg-surface-700 border border-surface-200 dark:border-surface-600 rounded-md px-1 py-1 text-surface-700 dark:text-surface-300 focus:outline-none focus:ring-1 focus:ring-primary-400 [appearance:textfield] [&::-webkit-outer-spin-button]:appearance-none [&::-webkit-inner-spin-button]:appearance-none"
              :placeholder="ch.label"
            />
          </div>
        </div>
      </div>

      <!-- Board palette (single section, clear behavior) -->
      <div v-if="palette && palette.length" class="border-t border-surface-100 dark:border-surface-700 px-2 py-1.5">
        <div class="flex items-center justify-between mb-1">
          <span class="text-[9px] font-medium text-surface-400 uppercase tracking-wider">Palette</span>
          <!-- Save current color to palette -->
          <button
            @click.stop="saveCurrentColor"
            :disabled="isSaving"
            class="flex items-center gap-0.5 text-[9px] font-medium transition-colors rounded px-1 py-0.5"
            :class="saveFailed ? 'text-red-500' : justSaved ? 'text-green-500' : isSaving ? 'text-surface-300' : 'text-surface-400 hover:text-primary-500'"
            title="Add current color to palette"
          >
            <span class="material-symbols-rounded" style="font-size: 12px;">{{ saveFailed ? 'error' : justSaved ? 'check' : 'add' }}</span>
            {{ saveFailed ? 'Failed' : justSaved ? 'Added' : 'Add' }}
          </button>
        </div>
        <div class="flex flex-wrap gap-1">
          <div
            v-for="(c, idx) in palette"
            :key="idx"
            class="relative group/swatch"
          >
            <button
              @click.stop="applyPaletteColor(c)"
              class="w-5 h-5 rounded border hover:scale-110 transition-transform cursor-pointer relative overflow-hidden"
              :class="isSameBaseColor(c) ? 'border-primary-500 ring-1 ring-primary-300' : 'border-surface-200 dark:border-surface-600'"
              :title="c"
            >
              <div class="absolute inset-0 checkerboard-bg" />
              <div class="absolute inset-0" :style="{ backgroundColor: c }" />
            </button>
            <!-- Remove button (small X, top-right corner on hover) -->
            <button
              @click.stop="removePaletteColor(idx)"
              class="absolute -top-1 -right-1 w-3 h-3 rounded-full bg-red-500 text-white flex items-center justify-center opacity-0 group-hover/swatch:opacity-100 transition-opacity shadow-sm hover:bg-red-600 z-10"
              title="Remove from palette"
            >
              <span class="text-[7px] font-bold leading-none">✕</span>
            </button>
          </div>
        </div>
      </div>

      <!-- Empty palette — just show Save button -->
      <div v-else class="border-t border-surface-100 dark:border-surface-700 px-2 py-1.5">
        <button
          @click.stop="saveCurrentColor"
          :disabled="isSaving"
          class="flex items-center justify-center gap-1 w-full px-2 py-1 rounded-md text-[10px] font-medium transition-colors"
          :class="saveFailed ? 'bg-red-500/15 text-red-500' : justSaved ? 'bg-green-500/15 text-green-500' : isSaving ? 'bg-surface-50 dark:bg-surface-700 text-surface-300' : 'bg-surface-50 dark:bg-surface-700 text-surface-400 hover:text-primary-500'"
        >
          <span class="material-symbols-rounded" style="font-size: 12px;">{{ saveFailed ? 'error' : justSaved ? 'check' : 'palette' }}</span>
          {{ saveFailed ? 'Save failed' : justSaved ? 'Saved!' : 'Save to palette' }}
        </button>
      </div>
    </div>
    </Teleport>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, onUnmounted, watch } from 'vue'
import { useMoodBoardsStore } from '@/addons/moodboards/stores/moodBoards'

const props = defineProps({
  modelValue: { type: String, default: '#000000' },
  palette: { type: Array, default: () => [] },
  label: { type: String, default: '' },
  showCaret: { type: Boolean, default: true },
  dropdownPosition: { type: String, default: 'top-full left-0' },
  contrastBg: { type: String, default: '' },
  allowTransparent: { type: Boolean, default: false },
})

const emit = defineEmits(['update:modelValue'])

const store = useMoodBoardsStore()

// ── Transparent / no-color ──
const isTransparent = computed(() => {
  const v = props.modelValue
  return v === 'transparent' || v === 'rgba(0, 0, 0, 0)' || v === 'rgba(0,0,0,0)' || v === '#00000000'
})

function applyTransparent() {
  emit('update:modelValue', 'transparent')
  open.value = false
}

function onSwatchDoubleClick() {
  if (!props.allowTransparent) return
  open.value = false
  if (isTransparent.value) return
  emit('update:modelValue', 'transparent')
}

// ── Save to palette ──
const justSaved = ref(false)
const saveFailed = ref(false)
const isSaving = ref(false)
let saveTimeout = null

async function saveCurrentColor() {
  if (isSaving.value) return
  isSaving.value = true
  saveFailed.value = false
  try {
    const ok = await store.addToPalette(pureHexColor.value)
    if (ok === false) {
      saveFailed.value = true
      clearTimeout(saveTimeout)
      saveTimeout = setTimeout(() => { saveFailed.value = false }, 3000)
      return
    }
    justSaved.value = true
    clearTimeout(saveTimeout)
    saveTimeout = setTimeout(() => { justSaved.value = false }, 1500)
  } catch {
    saveFailed.value = true
    clearTimeout(saveTimeout)
    saveTimeout = setTimeout(() => { saveFailed.value = false }, 3000)
  } finally {
    isSaving.value = false
  }
}

function removePaletteColor(idx) {
  if (props.palette && props.palette[idx]) {
    store.removeFromPalette(props.palette[idx])
  }
}

// ── Eyedropper API ──
const hasEyeDropper = typeof window !== 'undefined' && 'EyeDropper' in window

async function pickWithEyedropper() {
  if (!hasEyeDropper) return
  try {
    const dropper = new window.EyeDropper()
    const result = await dropper.open()
    if (result?.sRGBHex) {
      onHexType(result.sRGBHex)
    }
  } catch {
    // User cancelled or API unavailable
  }
}

const open = ref(false)
const rootRef = ref(null)
const triggerRef = ref(null)
const dropdownRef = ref(null)
const svAreaRef = ref(null)
const hueBarRef = ref(null)

const showRgb = ref(false)

// ── Fixed position for teleported dropdown ──
const dropdownFixedStyle = ref({})

function toggleOpen() {
  open.value = !open.value
  if (open.value) {
    requestAnimationFrame(updateDropdownPosition)
  }
}

function updateDropdownPosition() {
  if (!triggerRef.value) return
  const rect = triggerRef.value.getBoundingClientRect()
  const dropdownW = 256 // w-64 = 16rem = 256px
  const dropdownH = 420 // approximate max height
  const viewW = window.innerWidth
  const viewH = window.innerHeight

  let top = rect.bottom + 4
  let left = rect.left

  if (top + dropdownH > viewH - 8) {
    top = Math.max(8, rect.top - dropdownH - 4)
  }
  if (left + dropdownW > viewW - 8) {
    left = Math.max(8, viewW - dropdownW - 8)
  }
  if (left < 8) left = 8

  dropdownFixedStyle.value = {
    top: top + 'px',
    left: left + 'px',
  }
}
const alphaBarRef = ref(null)

// ── HSV + Alpha state ──
const hue = ref(0)
const sat = ref(1)
const val = ref(1)
const alphaPercent = ref(100)

// Guard: skip syncFromProp while user is actively dragging (SV, hue, alpha)
let _interacting = false

// ── Derived RGB ──
const rgbR = computed(() => hsvToRgb(hue.value, sat.value, val.value).r)
const rgbG = computed(() => hsvToRgb(hue.value, sat.value, val.value).g)
const rgbB = computed(() => hsvToRgb(hue.value, sat.value, val.value).b)

const rgbChannels = computed(() => [
  { key: 'r', label: 'R', value: rgbR.value },
  { key: 'g', label: 'G', value: rgbG.value },
  { key: 'b', label: 'B', value: rgbB.value },
])

const pureHexColor = computed(() => {
  const rgb = hsvToRgb(hue.value, sat.value, val.value)
  return '#' + [rgb.r, rgb.g, rgb.b].map(c => c.toString(16).padStart(2, '0')).join('')
})

const displayColor = computed(() => {
  if (alphaPercent.value >= 100) return pureHexColor.value
  const rgb = hsvToRgb(hue.value, sat.value, val.value)
  return `rgba(${rgb.r}, ${rgb.g}, ${rgb.b}, ${(alphaPercent.value / 100).toFixed(2)})`
})

// ══════════════════════════════════════════
// Contrast ratio (WCAG 2.1)
// ══════════════════════════════════════════
function sRGBtoLinear(c) {
  c = c / 255
  return c <= 0.04045 ? c / 12.92 : Math.pow((c + 0.055) / 1.055, 2.4)
}

function relativeLuminance(r, g, b) {
  return 0.2126 * sRGBtoLinear(r) + 0.7152 * sRGBtoLinear(g) + 0.0722 * sRGBtoLinear(b)
}

function getContrastRatio(l1, l2) {
  const lighter = Math.max(l1, l2)
  const darker = Math.min(l1, l2)
  return (lighter + 0.05) / (darker + 0.05)
}

const bgLuminance = computed(() => {
  if (!props.contrastBg) return null
  const parsed = parseColor(props.contrastBg)
  return relativeLuminance(parsed.r, parsed.g, parsed.b)
})

const fgLuminance = computed(() => relativeLuminance(rgbR.value, rgbG.value, rgbB.value))

const contrastRatio = computed(() => {
  if (bgLuminance.value === null) return 0
  return getContrastRatio(fgLuminance.value, bgLuminance.value)
})

const contrastRatioDisplay = computed(() => contrastRatio.value.toFixed(2))

const contrastLevel = computed(() => {
  const r = contrastRatio.value
  if (r >= 7) return 'AAA'
  if (r >= 4.5) return 'AA'
  if (r >= 3) return 'AA Large'
  return 'Fail'
})

const contrastBadgeClass = computed(() => {
  const level = contrastLevel.value
  if (level === 'AAA') return 'bg-green-500/15 text-green-600 dark:text-green-400'
  if (level === 'AA' || level === 'AA Large') return 'bg-amber-500/15 text-amber-600 dark:text-amber-400'
  return 'bg-red-500/15 text-red-500 dark:text-red-400'
})

const contrastCurvePath = computed(() => {
  if (bgLuminance.value === null) return null
  const bgL = bgLuminance.value
  const targetRatio = 4.5
  const points = []

  for (let sx = 0; sx <= 100; sx += 2) {
    const s = sx / 100
    let lo = 0, hi = 1
    for (let iter = 0; iter < 20; iter++) {
      const mid = (lo + hi) / 2
      const rgb = hsvToRgb(hue.value, s, mid)
      const l = relativeLuminance(rgb.r, rgb.g, rgb.b)
      const ratio = getContrastRatio(l, bgL)
      if (ratio > targetRatio) {
        if (bgL < 0.5) hi = mid
        else lo = mid
      } else {
        if (bgL < 0.5) lo = mid
        else hi = mid
      }
    }
    const v = (lo + hi) / 2
    const py = (1 - v) * 100
    if (py >= 0 && py <= 100) {
      points.push({ x: sx, y: py })
    }
  }

  if (points.length < 2) return null
  let d = `M ${points[0].x} ${points[0].y}`
  for (let i = 1; i < points.length; i++) {
    d += ` L ${points[i].x} ${points[i].y}`
  }
  return d
})

// ── Color conversion helpers ──
function hsvToRgb(h, s, v) {
  h = h / 360
  let r, g, b
  const i = Math.floor(h * 6)
  const f = h * 6 - i
  const p = v * (1 - s)
  const q = v * (1 - f * s)
  const t = v * (1 - (1 - f) * s)
  switch (i % 6) {
    case 0: r = v; g = t; b = p; break
    case 1: r = q; g = v; b = p; break
    case 2: r = p; g = v; b = t; break
    case 3: r = p; g = q; b = v; break
    case 4: r = t; g = p; b = v; break
    case 5: r = v; g = p; b = q; break
  }
  return { r: Math.round(r * 255), g: Math.round(g * 255), b: Math.round(b * 255) }
}

function rgbToHsv(r, g, b) {
  r /= 255; g /= 255; b /= 255
  const max = Math.max(r, g, b), min = Math.min(r, g, b)
  const d = max - min
  let h = 0, s = max === 0 ? 0 : d / max, v = max

  if (d !== 0) {
    switch (max) {
      case r: h = ((g - b) / d + (g < b ? 6 : 0)) / 6; break
      case g: h = ((b - r) / d + 2) / 6; break
      case b: h = ((r - g) / d + 4) / 6; break
    }
  }
  return { h: Math.round(h * 360), s, v }
}

// ── Parse incoming color ──
function parseColor(val) {
  if (!val) return { r: 0, g: 0, b: 0, a: 100 }

  const rgbaMatch = val.match(/rgba?\(\s*(\d+)\s*,\s*(\d+)\s*,\s*(\d+)\s*(?:,\s*([\d.]+))?\s*\)/)
  if (rgbaMatch) {
    return {
      r: parseInt(rgbaMatch[1]),
      g: parseInt(rgbaMatch[2]),
      b: parseInt(rgbaMatch[3]),
      a: rgbaMatch[4] !== undefined ? Math.round(parseFloat(rgbaMatch[4]) * 100) : 100
    }
  }
  if (/^#[0-9a-fA-F]{8}$/.test(val)) {
    return {
      r: parseInt(val.slice(1, 3), 16),
      g: parseInt(val.slice(3, 5), 16),
      b: parseInt(val.slice(5, 7), 16),
      a: Math.round(parseInt(val.slice(7, 9), 16) / 255 * 100)
    }
  }
  if (/^#[0-9a-fA-F]{6}$/.test(val)) {
    return {
      r: parseInt(val.slice(1, 3), 16),
      g: parseInt(val.slice(3, 5), 16),
      b: parseInt(val.slice(5, 7), 16),
      a: 100
    }
  }
  if (/^#[0-9a-fA-F]{3}$/.test(val)) {
    return {
      r: parseInt(val[1] + val[1], 16),
      g: parseInt(val[2] + val[2], 16),
      b: parseInt(val[3] + val[3], 16),
      a: 100
    }
  }
  return { r: 0, g: 0, b: 0, a: 100 }
}

function syncFromProp() {
  const parsed = parseColor(props.modelValue)
  const hsv = rgbToHsv(parsed.r, parsed.g, parsed.b)
  if (hsv.s > 0.001 && hsv.v > 0.001) {
    hue.value = hsv.h
  }
  sat.value = hsv.s
  val.value = hsv.v
  alphaPercent.value = parsed.a
}

watch(() => props.modelValue, () => {
  // Skip resetting HSV while user is actively dragging (SV area, hue bar, alpha bar).
  // The emitted color round-trips through the parent and comes back as modelValue —
  // without this guard the watcher would fight the drag and cause jitter.
  if (!_interacting) syncFromProp()
}, { immediate: true })

// ── Emit ──
function emitColor() {
  emit('update:modelValue', displayColor.value)
}

// ── Input handlers ──
function onHexType(v) {
  v = v.trim()
  if (!v.startsWith('#')) v = '#' + v
  if (/^#[0-9a-fA-F]{3,8}$/.test(v)) {
    const parsed = parseColor(v)
    const hsv = rgbToHsv(parsed.r, parsed.g, parsed.b)
    hue.value = hsv.h
    sat.value = hsv.s
    val.value = hsv.v
    if (v.length === 9) alphaPercent.value = parsed.a
    emitColor()
  }
}

function onRgbInput(channel, rawVal) {
  const c = Math.max(0, Math.min(255, parseInt(rawVal) || 0))
  const rgb = hsvToRgb(hue.value, sat.value, val.value)
  if (channel === 'r') rgb.r = c
  else if (channel === 'g') rgb.g = c
  else rgb.b = c
  const hsv = rgbToHsv(rgb.r, rgb.g, rgb.b)
  if (hsv.s > 0.001 && hsv.v > 0.001) hue.value = hsv.h
  sat.value = hsv.s
  val.value = hsv.v
  emitColor()
}

function onAlphaInput(pct) {
  alphaPercent.value = pct
  emitColor()
}

function applyPaletteColor(color) {
  const parsed = parseColor(color)
  const hsv = rgbToHsv(parsed.r, parsed.g, parsed.b)
  hue.value = hsv.h
  sat.value = hsv.s
  val.value = hsv.v
  alphaPercent.value = parsed.a
  emitColor()
}

function isSameBaseColor(c) {
  const parsed = parseColor(c)
  return parsed.r === rgbR.value && parsed.g === rgbG.value && parsed.b === rgbB.value
}

// ══════════════════════════════════════════
// SV area drag
// ══════════════════════════════════════════
let _svDragging = false

function startSvDrag(e) {
  _svDragging = true
  _interacting = true
  updateSvFromEvent(e)
  document.addEventListener('mousemove', onSvDrag)
  document.addEventListener('mouseup', endSvDrag)
}

function onSvDrag(e) {
  if (_svDragging) updateSvFromEvent(e)
}

function updateSvFromEvent(e) {
  if (!svAreaRef.value) return
  const rect = svAreaRef.value.getBoundingClientRect()
  sat.value = Math.max(0, Math.min(1, (e.clientX - rect.left) / rect.width))
  val.value = Math.max(0, Math.min(1, 1 - (e.clientY - rect.top) / rect.height))
  emitColor()
}

function endSvDrag() {
  _svDragging = false
  _interacting = false
  document.removeEventListener('mousemove', onSvDrag)
  document.removeEventListener('mouseup', endSvDrag)
}

// ══════════════════════════════════════════
// Hue bar drag
// ══════════════════════════════════════════
let _hueDragging = false

function startHueDrag(e) {
  _hueDragging = true
  _interacting = true
  updateHueFromEvent(e)
  document.addEventListener('mousemove', onHueDrag)
  document.addEventListener('mouseup', endHueDrag)
}

function onHueDrag(e) {
  if (_hueDragging) updateHueFromEvent(e)
}

function updateHueFromEvent(e) {
  if (!hueBarRef.value) return
  const rect = hueBarRef.value.getBoundingClientRect()
  hue.value = Math.round(Math.max(0, Math.min(360, (e.clientX - rect.left) / rect.width * 360)))
  emitColor()
}

function endHueDrag() {
  _hueDragging = false
  _interacting = false
  document.removeEventListener('mousemove', onHueDrag)
  document.removeEventListener('mouseup', endHueDrag)
}

// ══════════════════════════════════════════
// Alpha bar drag
// ══════════════════════════════════════════
let _alphaDragging = false

function startAlphaDrag(e) {
  _alphaDragging = true
  _interacting = true
  updateAlphaFromEvent(e)
  document.addEventListener('mousemove', onAlphaDragMove)
  document.addEventListener('mouseup', endAlphaDrag)
}

function onAlphaDragMove(e) {
  if (_alphaDragging) updateAlphaFromEvent(e)
}

function updateAlphaFromEvent(e) {
  if (!alphaBarRef.value) return
  const rect = alphaBarRef.value.getBoundingClientRect()
  alphaPercent.value = Math.round(Math.max(0, Math.min(100, (e.clientX - rect.left) / rect.width * 100)))
  emitColor()
}

function endAlphaDrag() {
  _alphaDragging = false
  _interacting = false
  document.removeEventListener('mousemove', onAlphaDragMove)
  document.removeEventListener('mouseup', endAlphaDrag)
}

// ── Close on outside click ──
function onDocClick(e) {
  if (!open.value) return
  const inRoot = rootRef.value && rootRef.value.contains(e.target)
  const inDropdown = dropdownRef.value && dropdownRef.value.contains(e.target)
  if (!inRoot && !inDropdown) {
    open.value = false
  }
}

function onScrollOrResize() {
  if (open.value) updateDropdownPosition()
}

onMounted(() => {
  document.addEventListener('mousedown', onDocClick)
  window.addEventListener('resize', onScrollOrResize)
  window.addEventListener('scroll', onScrollOrResize, true)
})
onUnmounted(() => {
  document.removeEventListener('mousedown', onDocClick)
  window.removeEventListener('resize', onScrollOrResize)
  window.removeEventListener('scroll', onScrollOrResize, true)
  document.removeEventListener('mousemove', onSvDrag)
  document.removeEventListener('mouseup', endSvDrag)
  document.removeEventListener('mousemove', onHueDrag)
  document.removeEventListener('mouseup', endHueDrag)
  document.removeEventListener('mousemove', onAlphaDragMove)
  document.removeEventListener('mouseup', endAlphaDrag)
})
</script>

<style scoped>
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
