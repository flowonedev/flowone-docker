<template>
  <div v-if="badges.length" class="absolute inset-0 pointer-events-none" style="z-index: 25;">
    <div
      v-for="b in badges"
      :key="'cb-' + b.id"
      class="absolute"
      :style="badgeStyle(b)"
    >
      <span
        class="material-symbols-rounded text-sm text-cyan-600 bg-cyan-50 dark:bg-cyan-900/40 dark:text-cyan-400 rounded-full p-0.5 shadow border border-cyan-200 dark:border-cyan-800"
        title="Linked to component"
      >widgets</span>
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue'

const props = defineProps({
  items: { type: Array, default: () => [] },
  zoom: { type: Number, default: 1 },
  panX: { type: Number, default: 0 },
  panY: { type: Number, default: 0 },
  presentationMode: Boolean,
})

const badges = computed(() => {
  if (props.presentationMode || props.zoom < 0.15) return []
  return props.items.filter(i => i.component_id)
})

function badgeStyle(item) {
  const x = ((item.pos_x || 0) + (item.width || 0)) * props.zoom + props.panX
  const y = (item.pos_y || 0) * props.zoom + props.panY
  const scale = Math.min(1, Math.max(0.4, props.zoom))
  return {
    left: x + 'px',
    top: (y - 8 * scale) + 'px',
    transform: `scale(${scale})`,
    transformOrigin: 'bottom left',
  }
}
</script>
