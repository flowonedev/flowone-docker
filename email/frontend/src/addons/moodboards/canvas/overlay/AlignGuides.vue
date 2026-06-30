<template>
  <svg
    v-if="guides.length > 0"
    class="absolute inset-0 z-10 pointer-events-none"
    :style="{ width: '100%', height: '100%' }"
  >
    <line
      v-for="(guide, idx) in screenGuides"
      :key="idx"
      :x1="guide.x1" :y1="guide.y1"
      :x2="guide.x2" :y2="guide.y2"
      stroke="#FF1744"
      stroke-width="1"
      stroke-dasharray="3"
    />
  </svg>
</template>

<script setup>
import { computed } from 'vue'

const props = defineProps({
  guides: { type: Array, default: () => [] },
  zoom: { type: Number, default: 1 },
  panX: { type: Number, default: 0 },
  panY: { type: Number, default: 0 },
  containerWidth: { type: Number, default: 0 },
  containerHeight: { type: Number, default: 0 },
})

const screenGuides = computed(() =>
  props.guides.map(g => {
    if (g.axis === 'x') {
      const x = g.value * props.zoom + props.panX
      return { x1: x, y1: 0, x2: x, y2: props.containerHeight }
    } else {
      const y = g.value * props.zoom + props.panY
      return { x1: 0, y1: y, x2: props.containerWidth, y2: y }
    }
  })
)
</script>
