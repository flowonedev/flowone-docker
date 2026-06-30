<template>
  <div class="absolute inset-0 z-10 pointer-events-none overflow-hidden">
    <div
      v-for="thread in visibleThreads"
      :key="thread.id"
      class="absolute pointer-events-auto cursor-pointer"
      :style="pinStyle(thread)"
      @click.stop="$emit('select-thread', thread)"
      @contextmenu.stop.prevent="$emit('thread-context', thread, $event)"
    >
      <div
        class="w-7 h-7 rounded-full flex items-center justify-center text-white text-xs font-bold shadow-md"
        :class="thread.resolved ? 'bg-green-500' : 'bg-blue-500'"
      >
        {{ thread.count || '1' }}
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue'

const props = defineProps({
  threads: { type: Array, default: () => [] },
  zoom: { type: Number, default: 1 },
  panX: { type: Number, default: 0 },
  panY: { type: Number, default: 0 },
})

defineEmits(['select-thread', 'thread-context'])

const visibleThreads = computed(() =>
  props.threads.filter(t => t.pin_x != null && t.pin_y != null)
)

function pinStyle(thread) {
  const x = (thread.pin_x || 0) * props.zoom + props.panX
  const y = (thread.pin_y || 0) * props.zoom + props.panY
  return { transform: `translate(${x - 14}px, ${y - 14}px)` }
}
</script>
