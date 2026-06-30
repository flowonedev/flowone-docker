<template>
  <div
    class="flex items-center bg-surface-50 dark:bg-surface-700 border border-surface-200 dark:border-surface-600 rounded-lg overflow-hidden group/scrub min-w-0"
    :class="{ 'ring-1 ring-primary-400': isFocused }"
  >
    <!-- Scrub handle (label/icon) — drag to adjust, double-click to reset -->
    <div
      ref="handleRef"
      class="flex items-center gap-0.5 px-1.5 select-none flex-shrink-0 transition-colors cursor-ew-resize"
      :class="isDragging ? 'text-primary-500' : isModified ? 'text-emerald-500 dark:text-emerald-400' : 'text-surface-400 hover:text-primary-400'"
      @mousedown.prevent="startScrub"
      @dblclick.prevent="resetToDefault"
    >
      <span v-if="icon" class="material-symbols-rounded" :style="{ fontSize: iconSize + 'px' }">{{ icon }}</span>
      <span v-if="label" class="text-[9px] font-semibold whitespace-nowrap">{{ label }}</span>
    </div>

    <!-- Number input — click to type -->
    <input
      ref="inputRef"
      type="text"
      inputmode="numeric"
      :value="displayValue"
      @focus="onFocus"
      @blur="onBlur"
      @keydown="onKeydown"
      @change="onInputChange"
      class="flex-1 min-w-0 py-1.5 pr-1 text-xs bg-transparent focus:outline-none text-surface-700 dark:text-surface-300 text-right [appearance:textfield] [&::-webkit-outer-spin-button]:appearance-none [&::-webkit-inner-spin-button]:appearance-none"
    />

    <!-- Optional suffix (px, deg, %, etc.) -->
    <span v-if="suffix" class="text-[9px] text-surface-400 pr-1.5 flex-shrink-0 select-none">{{ suffix }}</span>
  </div>
</template>

<script setup>
import { ref, computed, onUnmounted } from 'vue'

const props = defineProps({
  /** Current numeric value */
  modelValue: { type: Number, default: 0 },
  /** Minimum value */
  min: { type: Number, default: -Infinity },
  /** Maximum value */
  max: { type: Number, default: Infinity },
  /** Step increment per pixel of drag */
  step: { type: Number, default: 1 },
  /** Decimal precision for display */
  precision: { type: Number, default: 0 },
  /** Label text shown as drag handle */
  label: { type: String, default: '' },
  /** Material icon name shown as drag handle */
  icon: { type: String, default: '' },
  /** Icon size in px */
  iconSize: { type: Number, default: 13 },
  /** Suffix after the number (px, deg, %, etc.) */
  suffix: { type: String, default: '' },
  /** Multiplier: shift = 10x, alt = 0.1x */
  shiftMultiplier: { type: Number, default: 10 },
  /** Alt/Option multiplier for fine control */
  altMultiplier: { type: Number, default: 0.1 },
  /** Default value to reset to on double-click (null = no reset) */
  defaultValue: { type: Number, default: null },
})

const emit = defineEmits(['update:modelValue'])

const handleRef = ref(null)
const inputRef = ref(null)
const isFocused = ref(false)
const isDragging = ref(false)

const isModified = computed(() => {
  if (props.defaultValue === null) return false
  return Math.round(props.modelValue * 1000) !== Math.round(props.defaultValue * 1000)
})

const displayValue = computed(() => {
  if (props.precision > 0) {
    return props.modelValue.toFixed(props.precision)
  }
  return Math.round(props.modelValue)
})

// ── Focus / blur / type ──
function onFocus() {
  isFocused.value = true
  // Select all text for easy overwrite
  requestAnimationFrame(() => inputRef.value?.select())
}

function onBlur() {
  isFocused.value = false
}

function onInputChange(e) {
  const raw = parseFloat(e.target.value)
  if (isNaN(raw)) {
    e.target.value = displayValue.value
    return
  }
  const clamped = Math.min(props.max, Math.max(props.min, raw))
  emit('update:modelValue', props.precision > 0 ? clamped : Math.round(clamped))
}

function onKeydown(e) {
  if (e.key === 'ArrowUp' || e.key === 'ArrowDown') {
    e.preventDefault()
    const dir = e.key === 'ArrowUp' ? 1 : -1
    const mult = e.shiftKey ? props.shiftMultiplier : e.altKey ? props.altMultiplier : 1
    const newVal = props.modelValue + dir * props.step * mult
    const clamped = Math.min(props.max, Math.max(props.min, newVal))
    emit('update:modelValue', props.precision > 0 ? parseFloat(clamped.toFixed(props.precision)) : Math.round(clamped))
  } else if (e.key === 'Enter') {
    inputRef.value?.blur()
  } else if (e.key === 'Escape') {
    // Reset to current value and blur
    if (inputRef.value) inputRef.value.value = displayValue.value
    inputRef.value?.blur()
  }
}

// ── Double-click reset ──
function resetToDefault() {
  if (props.defaultValue !== null) {
    emit('update:modelValue', props.defaultValue)
  }
}

// ── Scrub drag ──
let _scrubState = null

function startScrub(e) {
  _scrubState = {
    startX: e.clientX,
    startValue: props.modelValue,
  }
  isDragging.value = true
  document.body.style.cursor = 'ew-resize'
  document.body.style.userSelect = 'none'
  document.addEventListener('mousemove', onScrubMove)
  document.addEventListener('mouseup', onScrubEnd)
}

function onScrubMove(e) {
  if (!_scrubState) return
  const dx = e.clientX - _scrubState.startX
  const mult = e.shiftKey ? props.shiftMultiplier : e.altKey ? props.altMultiplier : 1
  const newVal = _scrubState.startValue + dx * props.step * mult
  const clamped = Math.min(props.max, Math.max(props.min, newVal))
  emit('update:modelValue', props.precision > 0 ? parseFloat(clamped.toFixed(props.precision)) : Math.round(clamped))
}

function onScrubEnd() {
  _scrubState = null
  isDragging.value = false
  document.body.style.cursor = ''
  document.body.style.userSelect = ''
  document.removeEventListener('mousemove', onScrubMove)
  document.removeEventListener('mouseup', onScrubEnd)
}

onUnmounted(() => {
  document.removeEventListener('mousemove', onScrubMove)
  document.removeEventListener('mouseup', onScrubEnd)
  document.body.style.cursor = ''
  document.body.style.userSelect = ''
})
</script>

