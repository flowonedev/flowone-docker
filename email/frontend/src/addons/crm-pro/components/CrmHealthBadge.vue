<script setup>
/**
 * CrmHealthBadge - Displays a client's health score as a colored badge.
 * Health score ranges: 80-100 green, 50-79 yellow, 20-49 orange, 0-19 red
 */
import { computed } from 'vue'

const props = defineProps({
  score: {
    type: Number,
    default: 0,
  },
  showLabel: {
    type: Boolean,
    default: true,
  },
  size: {
    type: String,
    default: 'md', // sm, md, lg
  },
})

const tier = computed(() => {
  if (props.score >= 80) return { label: 'Healthy', color: 'bg-green-100 text-green-700 dark:bg-green-500/20 dark:text-green-400', ring: 'ring-green-500', fill: 'text-green-500' }
  if (props.score >= 50) return { label: 'Moderate', color: 'bg-yellow-100 text-yellow-700 dark:bg-yellow-500/20 dark:text-yellow-400', ring: 'ring-yellow-500', fill: 'text-yellow-500' }
  if (props.score >= 20) return { label: 'At Risk', color: 'bg-orange-100 text-orange-700 dark:bg-orange-500/20 dark:text-orange-400', ring: 'ring-orange-500', fill: 'text-orange-500' }
  return { label: 'Critical', color: 'bg-red-100 text-red-700 dark:bg-red-500/20 dark:text-red-400', ring: 'ring-red-500', fill: 'text-red-500' }
})

const sizeClasses = computed(() => {
  if (props.size === 'sm') return { badge: 'px-1.5 py-0.5 text-[10px]', circle: 'w-5 h-5 text-[10px]' }
  if (props.size === 'lg') return { badge: 'px-3 py-1.5 text-sm', circle: 'w-10 h-10 text-sm' }
  return { badge: 'px-2 py-1 text-xs', circle: 'w-7 h-7 text-xs' }
})
</script>

<template>
  <div class="inline-flex items-center gap-1.5">
    <!-- Score circle -->
    <div :class="['rounded-full flex items-center justify-center font-bold', tier.color, sizeClasses.circle]">
      {{ score }}
    </div>
    <!-- Label -->
    <span v-if="showLabel" :class="['font-medium rounded-full', tier.color, sizeClasses.badge]">
      {{ tier.label }}
    </span>
  </div>
</template>

