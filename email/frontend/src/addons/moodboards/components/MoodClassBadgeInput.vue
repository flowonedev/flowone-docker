<template>
  <div class="relative" ref="wrapperRef">
    <div
      ref="containerRef"
      class="flex flex-wrap items-center gap-1 min-h-[30px] w-full bg-surface-50 dark:bg-surface-700 border rounded-lg px-1.5 py-1 transition-colors cursor-text"
      :class="focused
        ? 'border-primary-400 ring-1 ring-primary-400'
        : 'border-surface-200 dark:border-surface-600 hover:border-surface-300 dark:hover:border-surface-500'"
      @click="focusInput"
    >
      <TransitionGroup name="badge">
        <span
          v-for="(tag, idx) in tags"
          :key="tag"
          class="inline-flex items-center gap-0.5 max-w-[140px] pl-2 pr-0.5 py-0.5 rounded-md text-[11px] font-medium leading-tight select-none transition-all"
          :class="badgeClass(tag, idx)"
        >
          <span class="truncate" :title="tag">{{ tag }}</span>
          <button
            class="flex-shrink-0 p-0.5 rounded hover:bg-black/10 dark:hover:bg-white/10 transition-colors"
            @mousedown.prevent
            @click.stop="removeTag(idx)"
            tabindex="-1"
          >
            <span class="material-symbols-rounded text-[13px] leading-none">close</span>
          </button>
        </span>
      </TransitionGroup>

      <input
        ref="inputRef"
        v-model="inputValue"
        class="flex-1 min-w-[60px] bg-transparent text-xs text-surface-700 dark:text-surface-300 outline-none placeholder:text-surface-400"
        :placeholder="tags.length === 0 ? placeholder : ''"
        @focus="focused = true"
        @blur="onBlur"
        @keydown="onKeydown"
      />
    </div>

    <!-- Autocomplete dropdown -->
    <Transition name="dropdown">
      <div
        v-if="showDropdown && filteredSuggestions.length"
        class="absolute z-50 left-0 right-0 mt-1 bg-white dark:bg-surface-800 border border-surface-200 dark:border-surface-600 rounded-lg shadow-lg overflow-hidden max-h-[160px] overflow-y-auto"
      >
        <button
          v-for="(s, i) in filteredSuggestions"
          :key="s.name"
          @mousedown.prevent="selectSuggestion(s)"
          class="w-full flex items-center gap-2 px-2.5 py-1.5 text-left transition-colors"
          :class="i === highlightIdx
            ? 'bg-primary-50 dark:bg-primary-900/30'
            : 'hover:bg-surface-50 dark:hover:bg-surface-700'"
        >
          <span class="inline-flex items-center px-1.5 py-0.5 rounded text-[9px] font-medium bg-primary-100 dark:bg-primary-900/40 text-primary-700 dark:text-primary-300">.{{ s.name }}</span>
          <span v-if="s.summary" class="text-[9px] text-surface-400 truncate">{{ s.summary }}</span>
        </button>
      </div>
    </Transition>
  </div>
</template>

<script setup>
import { ref, computed, watch, nextTick } from 'vue'

const props = defineProps({
  modelValue: { type: String, default: '' },
  placeholder: { type: String, default: 'Add class name...' },
  separator: { type: String, default: ',' },
  suggestions: { type: Array, default: () => [] },
})

const emit = defineEmits(['update:modelValue'])

const wrapperRef = ref(null)
const containerRef = ref(null)
const inputRef = ref(null)
const inputValue = ref('')
const focused = ref(false)
const selectedIdx = ref(-1)
const highlightIdx = ref(0)

const tags = computed(() => {
  if (!props.modelValue) return []
  return props.modelValue
    .split(props.separator)
    .map(t => t.trim())
    .filter(Boolean)
})

const filteredSuggestions = computed(() => {
  const q = inputValue.value.trim().toLowerCase()
  const existing = new Set(tags.value.map(t => t.toLowerCase()))
  return props.suggestions
    .filter(s => !existing.has(s.name.toLowerCase()))
    .filter(s => !q || s.name.toLowerCase().includes(q))
    .slice(0, 8)
})

const showDropdown = computed(() => focused.value && filteredSuggestions.value.length > 0)

function badgeClass(tag, idx) {
  if (idx === selectedIdx.value) return 'bg-primary-500 text-white'
  const isGlobal = props.suggestions.some(s => s.name.toLowerCase() === tag.toLowerCase())
  if (idx === 0) return 'bg-primary-100 dark:bg-primary-900/40 text-primary-700 dark:text-primary-300'
  if (isGlobal) return 'bg-violet-100 dark:bg-violet-900/40 text-violet-700 dark:text-violet-300'
  return 'bg-surface-200 dark:bg-surface-600 text-surface-600 dark:text-surface-300'
}

function focusInput() {
  inputRef.value?.focus()
}

function onBlur() {
  commitInput()
  setTimeout(() => { focused.value = false }, 150)
}

function commitInput() {
  const val = inputValue.value.trim()
  if (!val) return
  addTag(val)
  inputValue.value = ''
}

function addTag(name) {
  const clean = name.trim()
  if (!clean) return
  if (tags.value.some(t => t.toLowerCase() === clean.toLowerCase())) return
  const updated = [...tags.value, clean]
  emit('update:modelValue', updated.join(props.separator + ' '))
  nextTick(() => { inputValue.value = '' })
}

function removeTag(idx) {
  const updated = tags.value.filter((_, i) => i !== idx)
  emit('update:modelValue', updated.length ? updated.join(props.separator + ' ') : null)
  selectedIdx.value = -1
  nextTick(() => focusInput())
}

function selectSuggestion(s) {
  addTag(s.name)
  highlightIdx.value = 0
  nextTick(() => focusInput())
}

function onKeydown(e) {
  if (showDropdown.value) {
    if (e.key === 'ArrowDown') {
      e.preventDefault()
      highlightIdx.value = Math.min(highlightIdx.value + 1, filteredSuggestions.value.length - 1)
      return
    }
    if (e.key === 'ArrowUp') {
      e.preventDefault()
      highlightIdx.value = Math.max(highlightIdx.value - 1, 0)
      return
    }
    if (e.key === 'Enter' && filteredSuggestions.value[highlightIdx.value]) {
      e.preventDefault()
      selectSuggestion(filteredSuggestions.value[highlightIdx.value])
      return
    }
  }

  if (e.key === 'Enter' || e.key === props.separator) {
    e.preventDefault()
    commitInput()
    return
  }

  if (e.key === 'Backspace' && inputValue.value === '' && tags.value.length > 0) {
    if (selectedIdx.value >= 0) {
      removeTag(selectedIdx.value)
    } else {
      selectedIdx.value = tags.value.length - 1
    }
    return
  }

  if (e.key === 'Escape') {
    inputValue.value = ''
    selectedIdx.value = -1
    inputRef.value?.blur()
    return
  }

  selectedIdx.value = -1
}

watch(inputValue, () => {
  selectedIdx.value = -1
  highlightIdx.value = 0
})
</script>

<style scoped>
.badge-enter-active {
  transition: all 0.15s ease-out;
}
.badge-leave-active {
  transition: all 0.1s ease-in;
}
.badge-enter-from {
  opacity: 0;
  transform: scale(0.85);
}
.badge-leave-to {
  opacity: 0;
  transform: scale(0.85);
}
.dropdown-enter-active {
  transition: all 0.12s ease-out;
}
.dropdown-leave-active {
  transition: all 0.08s ease-in;
}
.dropdown-enter-from,
.dropdown-leave-to {
  opacity: 0;
  transform: translateY(-4px);
}
</style>
