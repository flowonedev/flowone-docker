<template>
  <div class="space-y-3">
    <!-- Fill type toggle: Solid / Linear / Radial -->
    <div class="flex items-center gap-0.5 bg-surface-100 dark:bg-surface-700/60 rounded-lg p-0.5">
      <button
        v-for="t in fillTypes"
        :key="t.value"
        @click="setFillType(t.value)"
        :class="[
          'flex-1 flex items-center justify-center gap-1 px-2 py-1.5 rounded-md text-[10px] font-medium transition-all duration-150',
          currentFillType === t.value
            ? 'bg-white dark:bg-surface-600 text-primary-600 dark:text-primary-400 shadow-sm'
            : 'text-surface-500 dark:text-surface-400 hover:text-surface-700 dark:hover:text-surface-300'
        ]"
      >
        <span class="material-symbols-rounded text-sm">{{ t.icon }}</span>
        {{ t.label }}
      </button>
    </div>

    <!-- SOLID: color picker + hex -->
    <div v-if="currentFillType === 'solid'" class="flex items-center gap-2">
      <MoodColorPicker
        :model-value="currentColor"
        @update:model-value="onSolidColorChange"
        :palette="palette"
        :allow-transparent="allowTransparent"
        label="Fill color"
        :show-caret="false"
        dropdown-position="top-full left-0"
      />
      <span class="text-[10px] text-surface-400 font-mono uppercase select-all">{{ currentColor }}</span>
    </div>

    <!-- GRADIENT -->
    <template v-if="currentFillType === 'linear' || currentFillType === 'radial'">
      <!-- Gradient bar with stop handles -->
      <div class="relative select-none">
        <div
          ref="gradientBarRef"
          class="h-8 rounded-lg border border-surface-200 dark:border-surface-600 cursor-crosshair"
          :style="{ background: gradientBarCSS }"
          @click.stop="onBarClick"
        />
        <div class="relative h-5 mt-0.5">
          <div
            v-for="(stop, idx) in currentStops"
            :key="idx"
            class="absolute top-0 flex flex-col items-center"
            :style="{ left: stop.position + '%', transform: 'translateX(-50%)', opacity: dragRemovingIdx === idx ? 0.3 : 1 }"
          >
            <svg width="10" height="5" viewBox="0 0 10 5" class="flex-shrink-0 -mt-px">
              <polygon points="5,0 10,5 0,5" :fill="activeStopIdx === idx ? '#6366f1' : '#64748b'" />
            </svg>
            <div
              @mousedown.stop="startDragStop($event, idx)"
              :class="[
                'w-4 h-4 rounded-sm border-2 cursor-grab shadow-sm transition-shadow',
                activeStopIdx === idx
                  ? 'border-primary-500 ring-2 ring-primary-300/50'
                  : 'border-surface-300 dark:border-surface-500'
              ]"
              :style="{ backgroundColor: stop.color }"
              :title="`Stop at ${Math.round(stop.position)}%`"
            />
          </div>
        </div>
        <p class="text-[8px] text-surface-400/70 mt-0.5 leading-tight">Click bar to add, drag to move, drag off to remove</p>
      </div>

      <!-- Active stop: color + position (compact row) -->
      <div v-if="activeStop" class="flex items-center gap-1.5">
        <MoodColorPicker
          :model-value="activeStop.color"
          @update:model-value="onStopColorChange"
          :palette="palette"
          label="Stop color"
          :show-caret="false"
          dropdown-position="top-full left-0"
        />
        <span class="text-[9px] text-surface-400 font-medium flex-shrink-0">Pos</span>
        <input
          type="number"
          :value="Math.round(activeStop.position)"
          min="0" max="100"
          @change="onStopPositionInput(parseInt($event.target.value))"
          class="w-10 text-[10px] text-center bg-surface-50 dark:bg-surface-700 border border-surface-200 dark:border-surface-600 rounded px-1 py-0.5 text-surface-600 dark:text-surface-300 focus:outline-none [appearance:textfield] [&::-webkit-outer-spin-button]:appearance-none [&::-webkit-inner-spin-button]:appearance-none"
        />
        <span class="text-[9px] text-surface-400">%</span>
      </div>

      <!-- Angle (linear only) — compact row -->
      <div v-if="currentFillType === 'linear'" class="flex items-center gap-1.5">
        <div
          ref="angleWheelRef"
          class="relative w-6 h-6 rounded-full border border-surface-200 dark:border-surface-600 bg-surface-50 dark:bg-surface-700 cursor-pointer flex-shrink-0"
          @mousedown.stop="startAngleDrag"
          title="Drag to set angle"
        >
          <div
            class="absolute top-1/2 left-1/2 w-0.5 h-2.5 bg-primary-500 rounded-full origin-bottom"
            :style="{ transform: `translate(-50%, -100%) rotate(${currentAngle}deg)` }"
          />
          <div class="absolute top-1/2 left-1/2 w-1 h-1 rounded-full bg-primary-500 -translate-x-1/2 -translate-y-1/2" />
        </div>
        <input
          type="range"
          :value="currentAngle"
          min="0" max="360" step="1"
          @input="setAngle(parseInt($event.target.value))"
          class="flex-1 h-1 accent-primary-500 cursor-pointer min-w-0"
        />
        <input
          type="number"
          :value="currentAngle"
          min="0" max="360"
          @change="setAngle(Math.max(0, Math.min(360, parseInt($event.target.value) || 0)))"
          class="w-10 text-[10px] text-center bg-surface-50 dark:bg-surface-700 border border-surface-200 dark:border-surface-600 rounded px-1 py-0.5 text-surface-600 dark:text-surface-300 focus:outline-none [appearance:textfield] [&::-webkit-outer-spin-button]:appearance-none [&::-webkit-inner-spin-button]:appearance-none"
        />
        <span class="text-[8px] text-surface-400 flex-shrink-0">deg</span>
      </div>

      <!-- Divider -->
      <div class="border-t border-surface-200 dark:border-surface-700" />

      <!-- Saved gradients + Save button (single row header) -->
      <div>
        <div class="flex items-center justify-between mb-1.5">
          <span class="text-[9px] text-surface-400 uppercase tracking-wider font-bold">Saved</span>
          <button
            @click="saveCurrentGradient"
            class="flex items-center gap-0.5 px-2 py-0.5 text-[9px] font-semibold text-primary-500 hover:text-primary-400 bg-primary-50 dark:bg-primary-900/20 hover:bg-primary-100 dark:hover:bg-primary-900/30 rounded-md transition-colors"
            title="Save current gradient"
          >
            <span class="material-symbols-rounded" style="font-size: 12px;">bookmark_add</span>
            Save
          </button>
        </div>
        <div v-if="savedGradients.length" class="flex flex-wrap gap-1.5">
          <div
            v-for="(sg, idx) in savedGradients"
            :key="'saved-' + idx"
            class="relative group"
          >
            <button
              @click="applySavedGradient(sg)"
              class="w-7 h-7 rounded-lg border border-surface-200 dark:border-surface-600 hover:scale-110 hover:ring-2 hover:ring-primary-300/50 transition-all cursor-pointer shadow-sm"
              :style="{ background: savedGradientCSS(sg) }"
              :title="sg.name || 'Saved gradient'"
            />
            <button
              @click.stop="removeSavedGradient(idx)"
              class="absolute -top-1 -right-1 w-3.5 h-3.5 bg-red-500 text-white rounded-full flex items-center justify-center opacity-0 group-hover:opacity-100 transition-opacity shadow text-[8px] leading-none"
              title="Remove"
            >
              <span class="material-symbols-rounded" style="font-size: 9px;">close</span>
            </button>
          </div>
        </div>
        <p v-else class="text-[9px] text-surface-400/60 italic">No saved gradients yet</p>
      </div>

      <!-- User palette gradients -->
      <div v-if="userPaletteGradients.length">
        <span class="text-[9px] text-surface-400 uppercase tracking-wider font-bold mb-1.5 block">From Palettes</span>
        <div class="flex flex-wrap gap-1.5">
          <button
            v-for="(ug, idx) in userPaletteGradients"
            :key="'up-' + idx"
            @click="applySavedGradient(ug)"
            class="w-7 h-7 rounded-lg border border-surface-200 dark:border-surface-600 hover:scale-110 hover:ring-2 hover:ring-primary-300/50 transition-all cursor-pointer shadow-sm"
            :style="{ background: savedGradientCSS(ug) }"
            :title="ug._paletteName || 'User palette gradient'"
          />
        </div>
      </div>

      <!-- Divider -->
      <div class="border-t border-surface-200 dark:border-surface-700" />

      <!-- Presets -->
      <div>
        <div class="flex items-center justify-between mb-1.5">
          <span class="text-[9px] text-surface-400 uppercase tracking-wider font-bold">Presets</span>
          <button
            @click="showAllPresets = !showAllPresets"
            class="text-[9px] text-primary-500 hover:text-primary-400 font-medium transition-colors"
          >
            {{ showAllPresets ? 'Less' : `All (${gradientPresets.length})` }}
          </button>
        </div>
        <div class="flex flex-wrap gap-1.5">
          <button
            v-for="preset in visiblePresets"
            :key="preset.name"
            @click="applyPreset(preset)"
            class="w-6 h-6 rounded-lg border border-surface-200 dark:border-surface-600 hover:scale-110 hover:ring-2 hover:ring-primary-300/50 transition-all cursor-pointer flex-shrink-0"
            :style="{ background: presetCSS(preset) }"
            :title="preset.name"
          />
        </div>
      </div>
    </template>
  </div>
</template>

<script setup>
import { ref, computed, watch, onUnmounted } from 'vue'
import { useMoodBoardsStore } from '@/addons/moodboards/stores/moodBoards'
import MoodColorPicker from './MoodColorPicker.vue'

const store = useMoodBoardsStore()

const props = defineProps({
  fillType: { type: String, default: 'solid' },
  color: { type: String, default: '#6366f1' },
  gradient: { type: Object, default: () => ({ angle: 180, stops: [{ color: '#6366f1', position: 0 }, { color: '#ec4899', position: 100 }] }) },
  palette: { type: Array, default: () => [] },
  allowTransparent: { type: Boolean, default: false },
})

const emit = defineEmits(['update:fillType', 'update:color', 'update:gradient'])

const fillTypes = [
  { value: 'solid', label: 'Solid', icon: 'circle' },
  { value: 'linear', label: 'Linear', icon: 'gradient' },
  { value: 'radial', label: 'Radial', icon: 'target' },
]

const currentFillType = ref(props.fillType)
const currentColor = ref(props.color)
const currentAngle = ref(props.gradient?.angle ?? 180)
const currentStops = ref(JSON.parse(JSON.stringify(props.gradient?.stops || [
  { color: '#6366f1', position: 0 },
  { color: '#ec4899', position: 100 }
])))
const activeStopIdx = ref(0)

const gradientBarRef = ref(null)
const angleWheelRef = ref(null)

watch(() => props.fillType, v => { currentFillType.value = v })
watch(() => props.color, v => { currentColor.value = v })
watch(() => props.gradient, v => {
  if (v) {
    currentAngle.value = v.angle ?? 180
    currentStops.value = JSON.parse(JSON.stringify(v.stops || []))
    if (activeStopIdx.value >= currentStops.value.length) activeStopIdx.value = 0
  }
}, { deep: true })

const activeStop = computed(() => currentStops.value[activeStopIdx.value] || null)

const sortedStops = computed(() => {
  return [...currentStops.value].sort((a, b) => a.position - b.position)
})

const gradientBarCSS = computed(() => {
  const stops = sortedStops.value.map(s => `${s.color} ${s.position}%`).join(', ')
  return `linear-gradient(to right, ${stops})`
})

function emitGradient() {
  emit('update:gradient', {
    angle: currentAngle.value,
    stops: JSON.parse(JSON.stringify(currentStops.value))
  })
}

function setFillType(type) {
  currentFillType.value = type
  emit('update:fillType', type)
  if ((type === 'linear' || type === 'radial') && currentStops.value.length < 2) {
    currentStops.value = [
      { color: currentColor.value || '#6366f1', position: 0 },
      { color: '#ec4899', position: 100 }
    ]
    emitGradient()
  }
}

function onSolidColorChange(val) {
  currentColor.value = val
  emit('update:color', val)
}

function onStopColorChange(val) {
  if (activeStop.value) {
    currentStops.value[activeStopIdx.value].color = val
    emitGradient()
  }
}

function onStopPositionInput(val) {
  if (activeStop.value) {
    currentStops.value[activeStopIdx.value].position = Math.max(0, Math.min(100, val || 0))
    emitGradient()
  }
}

function setAngle(val) {
  currentAngle.value = Math.max(0, Math.min(360, val))
  emitGradient()
}

const dragRemovingIdx = ref(-1)
const DRAG_REMOVE_DISTANCE = 40

function onBarClick(e) {
  if (!gradientBarRef.value) return
  if (_stopDrag) return
  const rect = gradientBarRef.value.getBoundingClientRect()
  const position = Math.round(((e.clientX - rect.left) / rect.width) * 100)
  const newColor = interpolateColorAtPosition(position)
  currentStops.value.push({ color: newColor, position })
  activeStopIdx.value = currentStops.value.length - 1
  emitGradient()
}

function removeStop(idx) {
  if (currentStops.value.length <= 2) return
  currentStops.value.splice(idx, 1)
  if (activeStopIdx.value >= currentStops.value.length) {
    activeStopIdx.value = currentStops.value.length - 1
  }
  emitGradient()
}

function interpolateColorAtPosition(pos) {
  const sorted = sortedStops.value
  if (sorted.length === 0) return '#888888'
  if (sorted.length === 1) return sorted[0].color
  if (pos <= sorted[0].position) return sorted[0].color
  if (pos >= sorted[sorted.length - 1].position) return sorted[sorted.length - 1].color
  for (let i = 0; i < sorted.length - 1; i++) {
    if (pos >= sorted[i].position && pos <= sorted[i + 1].position) {
      const t = (pos - sorted[i].position) / (sorted[i + 1].position - sorted[i].position)
      return lerpColor(sorted[i].color, sorted[i + 1].color, t)
    }
  }
  return '#888888'
}

function lerpColor(c1, c2, t) {
  const r1 = parseInt(c1.slice(1, 3), 16), g1 = parseInt(c1.slice(3, 5), 16), b1 = parseInt(c1.slice(5, 7), 16)
  const r2 = parseInt(c2.slice(1, 3), 16), g2 = parseInt(c2.slice(3, 5), 16), b2 = parseInt(c2.slice(5, 7), 16)
  const r = Math.round(r1 + (r2 - r1) * t)
  const g = Math.round(g1 + (g2 - g1) * t)
  const b = Math.round(b1 + (b2 - b1) * t)
  return '#' + [r, g, b].map(c => c.toString(16).padStart(2, '0')).join('')
}

let _stopDrag = null

function startDragStop(e, idx) {
  activeStopIdx.value = idx
  dragRemovingIdx.value = -1
  _stopDrag = { idx, startX: e.clientX, startY: e.clientY, startPos: currentStops.value[idx].position }
  document.addEventListener('mousemove', onStopDrag)
  document.addEventListener('mouseup', endStopDrag)
}

function onStopDrag(e) {
  if (!_stopDrag || !gradientBarRef.value) return
  const rect = gradientBarRef.value.getBoundingClientRect()
  const newPos = Math.max(0, Math.min(100, ((e.clientX - rect.left) / rect.width) * 100))
  currentStops.value[_stopDrag.idx].position = Math.round(newPos)
  const dy = Math.abs(e.clientY - (rect.top + rect.height / 2))
  if (dy > DRAG_REMOVE_DISTANCE && currentStops.value.length > 2) {
    dragRemovingIdx.value = _stopDrag.idx
  } else {
    dragRemovingIdx.value = -1
  }
}

function endStopDrag() {
  if (_stopDrag) {
    if (dragRemovingIdx.value === _stopDrag.idx && currentStops.value.length > 2) {
      currentStops.value.splice(_stopDrag.idx, 1)
      if (activeStopIdx.value >= currentStops.value.length) {
        activeStopIdx.value = currentStops.value.length - 1
      }
    }
    dragRemovingIdx.value = -1
    emitGradient()
    _stopDrag = null
  }
  document.removeEventListener('mousemove', onStopDrag)
  document.removeEventListener('mouseup', endStopDrag)
}

let _angleDrag = false

function startAngleDrag(e) {
  _angleDrag = true
  updateAngleFromEvent(e)
  document.addEventListener('mousemove', onAngleDrag)
  document.addEventListener('mouseup', endAngleDrag)
}

function onAngleDrag(e) {
  if (!_angleDrag) return
  updateAngleFromEvent(e)
}

function updateAngleFromEvent(e) {
  if (!angleWheelRef.value) return
  const rect = angleWheelRef.value.getBoundingClientRect()
  const cx = rect.left + rect.width / 2
  const cy = rect.top + rect.height / 2
  const angle = Math.atan2(e.clientX - cx, -(e.clientY - cy)) * (180 / Math.PI)
  currentAngle.value = Math.round((angle + 360) % 360)
}

function endAngleDrag() {
  if (_angleDrag) {
    _angleDrag = false
    emitGradient()
  }
  document.removeEventListener('mousemove', onAngleDrag)
  document.removeEventListener('mouseup', endAngleDrag)
}

const savedGradients = computed(() => store.getGradientPalette())

const userPaletteGradients = computed(() => {
  const boardGrads = savedGradients.value
  const boardKeys = new Set(boardGrads.map(g => JSON.stringify({ type: g.type, angle: g.angle, stops: g.stops })))
  const result = []
  for (const palette of store.userPalettes) {
    if (!palette.gradients?.length) continue
    for (const g of palette.gradients) {
      const key = JSON.stringify({ type: g.type, angle: g.angle, stops: g.stops })
      if (!boardKeys.has(key)) {
        result.push({ ...g, _paletteName: palette.name })
        boardKeys.add(key)
      }
    }
  }
  return result
})

function savedGradientCSS(sg) {
  const stops = sg.stops.map(s => `${s.color} ${s.position}%`).join(', ')
  const type = sg.type || 'linear'
  if (type === 'radial') {
    return `radial-gradient(circle, ${stops})`
  }
  return `linear-gradient(${sg.angle || 135}deg, ${stops})`
}

function saveCurrentGradient() {
  const gradient = {
    type: currentFillType.value,
    angle: currentAngle.value,
    stops: JSON.parse(JSON.stringify(currentStops.value)),
  }
  store.addGradientTopalette(gradient)
}

function applySavedGradient(sg) {
  const type = sg.type || 'linear'
  if (type !== currentFillType.value) {
    currentFillType.value = type
    emit('update:fillType', type)
  }
  currentAngle.value = sg.angle ?? 135
  currentStops.value = JSON.parse(JSON.stringify(sg.stops))
  activeStopIdx.value = 0
  emitGradient()
}

function removeSavedGradient(idx) {
  store.removeGradientFromPalette(idx)
}

const showAllPresets = ref(false)

const gradientPresets = [
  { name: 'Sunset', angle: 135, stops: [{ color: '#f97316', position: 0 }, { color: '#ec4899', position: 100 }] },
  { name: 'Gold', angle: 135, stops: [{ color: '#f59e0b', position: 0 }, { color: '#ef4444', position: 100 }] },
  { name: 'Peach', angle: 135, stops: [{ color: '#fbbf24', position: 0 }, { color: '#fb923c', position: 50 }, { color: '#f472b6', position: 100 }] },
  { name: 'Fire', angle: 180, stops: [{ color: '#dc2626', position: 0 }, { color: '#f97316', position: 50 }, { color: '#fbbf24', position: 100 }] },
  { name: 'Coral', angle: 135, stops: [{ color: '#fb7185', position: 0 }, { color: '#fdba74', position: 100 }] },
  { name: 'Warm Glow', angle: 160, stops: [{ color: '#fde68a', position: 0 }, { color: '#f87171', position: 100 }] },
  { name: 'Ocean', angle: 180, stops: [{ color: '#06b6d4', position: 0 }, { color: '#3b82f6', position: 100 }] },
  { name: 'Ice', angle: 135, stops: [{ color: '#e0f2fe', position: 0 }, { color: '#7dd3fc', position: 50 }, { color: '#0ea5e9', position: 100 }] },
  { name: 'Deep Sea', angle: 180, stops: [{ color: '#0c4a6e', position: 0 }, { color: '#0284c7', position: 50 }, { color: '#22d3ee', position: 100 }] },
  { name: 'Frost', angle: 135, stops: [{ color: '#dbeafe', position: 0 }, { color: '#93c5fd', position: 100 }] },
  { name: 'Arctic', angle: 180, stops: [{ color: '#cffafe', position: 0 }, { color: '#67e8f9', position: 50 }, { color: '#06b6d4', position: 100 }] },
  { name: 'Forest', angle: 135, stops: [{ color: '#22c55e', position: 0 }, { color: '#0d9488', position: 100 }] },
  { name: 'Emerald', angle: 180, stops: [{ color: '#d1fae5', position: 0 }, { color: '#34d399', position: 50 }, { color: '#059669', position: 100 }] },
  { name: 'Lime Fresh', angle: 135, stops: [{ color: '#a3e635', position: 0 }, { color: '#22c55e', position: 100 }] },
  { name: 'Moss', angle: 160, stops: [{ color: '#365314', position: 0 }, { color: '#4ade80', position: 100 }] },
  { name: 'Purple Haze', angle: 135, stops: [{ color: '#8b5cf6', position: 0 }, { color: '#ec4899', position: 100 }] },
  { name: 'Lavender', angle: 180, stops: [{ color: '#e9d5ff', position: 0 }, { color: '#a78bfa', position: 50 }, { color: '#7c3aed', position: 100 }] },
  { name: 'Grape', angle: 135, stops: [{ color: '#6d28d9', position: 0 }, { color: '#db2777', position: 100 }] },
  { name: 'Ultraviolet', angle: 180, stops: [{ color: '#4c1d95', position: 0 }, { color: '#7c3aed', position: 50 }, { color: '#c084fc', position: 100 }] },
  { name: 'Berry', angle: 135, stops: [{ color: '#9333ea', position: 0 }, { color: '#e11d48', position: 100 }] },
  { name: 'Midnight', angle: 180, stops: [{ color: '#1e293b', position: 0 }, { color: '#6366f1', position: 100 }] },
  { name: 'Dark Teal', angle: 135, stops: [{ color: '#0f172a', position: 0 }, { color: '#0e7490', position: 100 }] },
  { name: 'Charcoal', angle: 180, stops: [{ color: '#18181b', position: 0 }, { color: '#3f3f46', position: 50 }, { color: '#71717a', position: 100 }] },
  { name: 'Noir', angle: 135, stops: [{ color: '#09090b', position: 0 }, { color: '#27272a', position: 100 }] },
  { name: 'Deep Space', angle: 180, stops: [{ color: '#020617', position: 0 }, { color: '#312e81', position: 50 }, { color: '#581c87', position: 100 }] },
  { name: 'Greyscale', angle: 180, stops: [{ color: '#f1f5f9', position: 0 }, { color: '#334155', position: 100 }] },
  { name: 'Silver', angle: 135, stops: [{ color: '#f8fafc', position: 0 }, { color: '#cbd5e1', position: 50 }, { color: '#94a3b8', position: 100 }] },
  { name: 'Warm Grey', angle: 180, stops: [{ color: '#fafaf9', position: 0 }, { color: '#d6d3d1', position: 50 }, { color: '#78716c', position: 100 }] },
  { name: 'Rainbow', angle: 90, stops: [{ color: '#ef4444', position: 0 }, { color: '#f59e0b', position: 25 }, { color: '#22c55e', position: 50 }, { color: '#3b82f6', position: 75 }, { color: '#8b5cf6', position: 100 }] },
  { name: 'Neon', angle: 135, stops: [{ color: '#f0abfc', position: 0 }, { color: '#818cf8', position: 33 }, { color: '#34d399', position: 66 }, { color: '#fde047', position: 100 }] },
  { name: 'Cotton Candy', angle: 135, stops: [{ color: '#fbcfe8', position: 0 }, { color: '#c4b5fd', position: 50 }, { color: '#a5f3fc', position: 100 }] },
  { name: 'Aurora', angle: 135, stops: [{ color: '#22d3ee', position: 0 }, { color: '#818cf8', position: 33 }, { color: '#e879f9', position: 66 }, { color: '#fb923c', position: 100 }] },
  { name: 'Pastel Dream', angle: 180, stops: [{ color: '#fecdd3', position: 0 }, { color: '#fde68a', position: 25 }, { color: '#bbf7d0', position: 50 }, { color: '#bfdbfe', position: 75 }, { color: '#e9d5ff', position: 100 }] },
  { name: 'Sky', angle: 180, stops: [{ color: '#38bdf8', position: 0 }, { color: '#818cf8', position: 100 }] },
  { name: 'Indigo Rose', angle: 135, stops: [{ color: '#6366f1', position: 0 }, { color: '#f43f5e', position: 100 }] },
  { name: 'Teal Pink', angle: 135, stops: [{ color: '#14b8a6', position: 0 }, { color: '#f472b6', position: 100 }] },
  { name: 'Blue Orange', angle: 135, stops: [{ color: '#3b82f6', position: 0 }, { color: '#fb923c', position: 100 }] },
  { name: 'Emerald Gold', angle: 135, stops: [{ color: '#10b981', position: 0 }, { color: '#fbbf24', position: 100 }] },
]

const visiblePresets = computed(() => showAllPresets.value ? gradientPresets : gradientPresets.slice(0, 8))

function presetCSS(preset) {
  const stops = preset.stops.map(s => `${s.color} ${s.position}%`).join(', ')
  return `linear-gradient(${preset.angle}deg, ${stops})`
}

function applyPreset(preset) {
  currentAngle.value = preset.angle
  currentStops.value = JSON.parse(JSON.stringify(preset.stops))
  activeStopIdx.value = 0
  emitGradient()
}

onUnmounted(() => {
  document.removeEventListener('mousemove', onStopDrag)
  document.removeEventListener('mouseup', endStopDrag)
  document.removeEventListener('mousemove', onAngleDrag)
  document.removeEventListener('mouseup', endAngleDrag)
})
</script>
