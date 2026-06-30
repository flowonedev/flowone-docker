<template>
  <div class="border-b border-surface-200 dark:border-surface-700">
    <!-- Header (click to toggle) -->
    <button
      @click="isOpen = !isOpen"
      class="w-full flex items-center gap-1.5 px-3 py-2 text-left bg-surface-100 dark:bg-[#1c1c22] hover:bg-surface-200 dark:hover:bg-[#24242c] transition-colors"
    >
      <span class="material-symbols-rounded text-[16px] text-surface-400">{{ icon }}</span>
      <span class="flex-1 text-[10px] font-semibold text-surface-500 dark:text-surface-400 uppercase tracking-[0.14em]">{{ title }}</span>
      <span v-if="$slots['header-right']" class="flex items-center gap-1 flex-shrink-0" @click.stop><slot name="header-right" /></span>
      <span class="material-symbols-rounded text-[16px] text-surface-400 transition-transform" :class="isOpen ? 'rotate-180' : ''">
        expand_more
      </span>
    </button>
    <!-- Content (collapsible) -->
    <div v-if="isOpen" class="px-3 pb-2 pt-1.5">
      <slot />
    </div>
  </div>
</template>

<script setup>
import { ref, watch, onMounted, onUnmounted } from 'vue'

const STORAGE_KEY = 'mood_sidebar_section_states'

const props = defineProps({
  title: { type: String, required: true },
  icon: { type: String, default: 'settings' },
  open: { type: Boolean, default: true },
  persist: { type: Boolean, default: true },
})

function loadSavedState() {
  if (!props.persist) return undefined
  try {
    const states = JSON.parse(localStorage.getItem(STORAGE_KEY) || '{}')
    return states[props.title]
  } catch { return undefined }
}

const savedState = loadSavedState()
const isOpen = ref(savedState !== undefined ? savedState : props.open)

watch(() => props.open, (v) => {
  if (loadSavedState() === undefined) {
    isOpen.value = v
  }
})

watch(isOpen, (v) => {
  if (!props.persist) return
  try {
    const states = JSON.parse(localStorage.getItem(STORAGE_KEY) || '{}')
    states[props.title] = v
    localStorage.setItem(STORAGE_KEY, JSON.stringify(states))
  } catch { /* ignore */ }
})

function onCollapseAll() {
  isOpen.value = false
  if (props.persist) {
    try {
      const states = JSON.parse(localStorage.getItem(STORAGE_KEY) || '{}')
      states[props.title] = false
      localStorage.setItem(STORAGE_KEY, JSON.stringify(states))
    } catch { /* ignore */ }
  }
}
function onExpandAll() {
  isOpen.value = true
  if (props.persist) {
    try {
      const states = JSON.parse(localStorage.getItem(STORAGE_KEY) || '{}')
      states[props.title] = true
      localStorage.setItem(STORAGE_KEY, JSON.stringify(states))
    } catch { /* ignore */ }
  }
}

onMounted(() => {
  window.addEventListener('mood-sidebar-collapse-all', onCollapseAll)
  window.addEventListener('mood-sidebar-expand-all', onExpandAll)
})
onUnmounted(() => {
  window.removeEventListener('mood-sidebar-collapse-all', onCollapseAll)
  window.removeEventListener('mood-sidebar-expand-all', onExpandAll)
})
</script>

