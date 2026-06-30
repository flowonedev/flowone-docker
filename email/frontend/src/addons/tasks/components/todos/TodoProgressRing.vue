<script setup>
import { computed } from 'vue'

const props = defineProps({
  percent: { type: Number, default: 0 },
  size: { type: Number, default: 64 },
  stroke: { type: Number, default: 6 }
})

const radius = computed(() => (props.size - props.stroke) / 2)
const circumference = computed(() => 2 * Math.PI * radius.value)
const dashOffset = computed(() => {
  const pct = Math.max(0, Math.min(100, props.percent))
  return circumference.value * (1 - pct / 100)
})
const center = computed(() => props.size / 2)
</script>

<template>
  <div
    class="relative inline-flex items-center justify-center shrink-0"
    :style="{ width: size + 'px', height: size + 'px' }"
  >
    <svg
      :width="size"
      :height="size"
      class="-rotate-90"
      aria-hidden="true"
    >
      <circle
        :cx="center"
        :cy="center"
        :r="radius"
        :stroke-width="stroke"
        fill="none"
        class="stroke-surface-200 dark:stroke-surface-700"
      />
      <circle
        :cx="center"
        :cy="center"
        :r="radius"
        :stroke-width="stroke"
        fill="none"
        stroke-linecap="round"
        :stroke-dasharray="circumference"
        :stroke-dashoffset="dashOffset"
        class="stroke-primary-500 transition-[stroke-dashoffset] duration-500 ease-out"
      />
    </svg>
    <span class="absolute inset-0 flex items-center justify-center text-sm font-semibold text-surface-900 dark:text-surface-100">
      {{ percent }}<span class="text-[0.65rem] font-medium text-surface-500 ml-0.5">%</span>
    </span>
  </div>
</template>
