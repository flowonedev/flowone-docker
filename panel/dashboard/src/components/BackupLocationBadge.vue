<script setup>
import { computed } from 'vue'

const props = defineProps({
  // 'local' | 'nas' | 'both' (falls back to nasUploaded when absent)
  location: { type: String, default: null },
  nasUploaded: { type: Boolean, default: false },
})

const resolved = computed(() => {
  if (props.location) return props.location
  return props.nasUploaded ? 'both' : 'local'
})

const config = computed(() => {
  switch (resolved.value) {
    case 'nas':
      return { icon: 'hard_drive', label: 'NAS', classes: 'bg-cyan-100 text-cyan-700 dark:bg-cyan-900/40 dark:text-cyan-300' }
    case 'both':
      return { icon: 'sync', label: 'Server + NAS', classes: 'bg-purple-100 text-purple-700 dark:bg-purple-900/40 dark:text-purple-300' }
    default:
      return { icon: 'dns', label: 'Server', classes: 'bg-surface-100 text-surface-700 dark:bg-surface-800 dark:text-surface-300' }
  }
})
</script>

<template>
  <span
    :class="['inline-flex items-center gap-1 px-2 py-0.5 rounded-lg text-xs font-medium whitespace-nowrap', config.classes]"
    :title="resolved === 'both' ? 'Stored on the server and on the NAS' : (resolved === 'nas' ? 'Stored on the NAS only' : 'Stored on this server only')"
  >
    <span class="material-symbols-rounded text-sm leading-none">{{ config.icon }}</span>
    {{ config.label }}
  </span>
</template>
