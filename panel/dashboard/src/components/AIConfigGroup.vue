<script setup>
const props = defineProps({
  service: {
    type: String,
    required: true
  },
  label: {
    type: String,
    required: true
  },
  icon: {
    type: String,
    default: 'settings'
  },
  loading: {
    type: Boolean,
    default: false
  }
})

const emit = defineEmits(['select'])

const handleClick = () => {
  if (props.loading) return
  emit('select', props.service)
}
</script>

<template>
  <button
    @click="handleClick"
    :disabled="loading"
    :class="[
      'flex items-center gap-2 px-2.5 py-2 rounded-lg transition-colors text-left w-full group',
      loading 
        ? 'bg-primary-50 dark:bg-primary-500/10 border border-primary-300 dark:border-primary-500/30 cursor-wait' 
        : 'bg-surface-50 dark:bg-surface-900/50 hover:bg-surface-100 dark:hover:bg-surface-700 border border-surface-200 dark:border-surface-700'
    ]"
  >
    <span 
      v-if="loading"
      class="spinner text-primary-500 dark:text-primary-400"
      style="width: 14px; height: 14px;"
    ></span>
    <span 
      v-else
      class="material-symbols-rounded text-surface-400 dark:text-surface-500 group-hover:text-primary-500 transition-colors text-base"
    >
      {{ icon }}
    </span>
    <span class="font-medium text-sm text-surface-700 dark:text-surface-300 flex-1 truncate">
      {{ label }}
      <span v-if="loading" class="text-xs text-primary-600 dark:text-primary-400 ml-1 animate-pulse">...</span>
    </span>
  </button>
</template>

