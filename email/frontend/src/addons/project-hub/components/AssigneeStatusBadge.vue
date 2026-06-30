<script setup>
import { computed } from 'vue'

const props = defineProps({
  status: { type: String, default: 'assigned' },
  clickable: { type: Boolean, default: false },
  size: { type: String, default: 'sm' },
  customStatuses: { type: Array, default: () => [] },
})

const emit = defineEmits(['click'])

const defaultStatusConfig = {
  assigned: { label: 'Assigned', color: 'bg-surface-200 dark:bg-surface-600 text-surface-600 dark:text-surface-300', icon: 'person' },
  working: { label: 'Working', color: 'bg-blue-100 dark:bg-blue-900/30 text-blue-700 dark:text-blue-300', icon: 'engineering' },
  review: { label: 'Review', color: 'bg-yellow-100 dark:bg-yellow-900/30 text-yellow-700 dark:text-yellow-300', icon: 'rate_review' },
  done: { label: 'Done', color: 'bg-green-100 dark:bg-green-900/30 text-green-700 dark:text-green-300', icon: 'check_circle' },
  blocked: { label: 'Blocked', color: 'bg-red-100 dark:bg-red-900/30 text-red-700 dark:text-red-300', icon: 'block' },
}

function hexToTwClass(hexColor) {
  const map = {
    '#ef4444': 'bg-red-100 dark:bg-red-900/30 text-red-700 dark:text-red-300',
    '#f59e0b': 'bg-yellow-100 dark:bg-yellow-900/30 text-yellow-700 dark:text-yellow-300',
    '#22c55e': 'bg-green-100 dark:bg-green-900/30 text-green-700 dark:text-green-300',
    '#3b82f6': 'bg-blue-100 dark:bg-blue-900/30 text-blue-700 dark:text-blue-300',
    '#6366f1': 'bg-indigo-100 dark:bg-indigo-900/30 text-indigo-700 dark:text-indigo-300',
    '#8b5cf6': 'bg-violet-100 dark:bg-violet-900/30 text-violet-700 dark:text-violet-300',
    '#ec4899': 'bg-pink-100 dark:bg-pink-900/30 text-pink-700 dark:text-pink-300',
    '#6b7280': 'bg-surface-200 dark:bg-surface-600 text-surface-600 dark:text-surface-300',
  }
  return map[hexColor] || 'bg-surface-200 dark:bg-surface-600 text-surface-600 dark:text-surface-300'
}

const resolvedConfig = computed(() => {
  if (props.customStatuses.length > 0) {
    const match = props.customStatuses.find(s => s.slug === props.status)
    if (match) {
      return {
        label: match.name,
        color: hexToTwClass(match.color),
        icon: match.icon || 'circle',
      }
    }
  }
  return defaultStatusConfig[props.status] || defaultStatusConfig.assigned
})

const sizeClasses = {
  xs: 'text-[10px] px-1.5 py-0.5 gap-0.5',
  sm: 'text-xs px-2 py-0.5 gap-1',
  md: 'text-sm px-2.5 py-1 gap-1',
}

const iconSizes = {
  xs: 'text-[12px]',
  sm: 'text-[14px]',
  md: 'text-[16px]',
}
</script>

<template>
  <button
    :class="[
      'inline-flex items-center rounded-full font-medium transition-all',
      resolvedConfig.color,
      sizeClasses[size],
      clickable ? 'cursor-pointer hover:opacity-80 active:scale-95' : 'cursor-default',
    ]"
    @click="clickable && emit('click')"
    :title="resolvedConfig.label"
  >
    <span class="material-symbols-rounded" :class="iconSizes[size]">
      {{ resolvedConfig.icon }}
    </span>
    <span>{{ resolvedConfig.label }}</span>
  </button>
</template>
