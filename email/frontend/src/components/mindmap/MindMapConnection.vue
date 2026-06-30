<script setup>
import { computed } from 'vue'

const props = defineProps({
  connection: {
    type: Object,
    required: true
  },
  isActive: {
    type: Boolean,
    default: false
  },
  isHighlighted: {
    type: Boolean,
    default: false
  }
})

const isLink = computed(() => props.connection.isLink || props.connection.type === 'link')
const isActiveOrHighlighted = computed(() => props.isActive || props.isHighlighted)

const strokeColor = computed(() => {
  if (isLink.value) {
    return isActiveOrHighlighted.value ? 'rgba(168, 85, 247, 0.6)' : 'rgba(168, 85, 247, 0.25)'
  }
  return isActiveOrHighlighted.value ? 'rgba(99, 102, 241, 0.6)' : 'rgba(99, 102, 241, 0.25)'
})

const glowColor = computed(() => {
  return isLink.value ? 'rgba(168, 85, 247, 0.3)' : 'rgba(99, 102, 241, 0.3)'
})

const dotColor = computed(() => {
  return isLink.value ? '#a855f7' : '#6366f1'
})

const strokeWidth = computed(() => {
  if (isActiveOrHighlighted.value) return isLink.value ? 2 : 2.5
  return isLink.value ? 1.5 : 2
})
</script>

<template>
  <g class="mind-map-connection">
    <!-- Glow effect for active connections -->
    <path
      v-if="isActiveOrHighlighted && connection.path"
      :d="connection.path"
      class="fill-none"
      :stroke="glowColor"
      stroke-width="6"
      stroke-linecap="round"
      filter="url(#conn-glow)"
    />

    <!-- Main path -->
    <path
      v-if="connection.path"
      :d="connection.path"
      class="fill-none transition-all duration-200"
      :stroke="strokeColor"
      :stroke-width="strokeWidth"
      stroke-linecap="round"
      :stroke-dasharray="isLink ? '6 4' : 'none'"
    />

    <!-- Animated flow dot for active connections -->
    <circle
      v-if="isActiveOrHighlighted && connection.path"
      :r="isLink ? 2.5 : 3"
      :fill="dotColor"
      opacity="0.7"
    >
      <animateMotion
        :path="connection.path"
        :dur="isLink ? '2.5s' : '2s'"
        repeatCount="indefinite"
      />
    </circle>
  </g>
</template>

<style scoped>
/* No extra styles needed - all handled inline */
</style>
