<template>
  <div class="absolute inset-0 z-10 pointer-events-none overflow-hidden">
    <div
      v-for="cursor in visibleCursors"
      :key="cursor.email"
      class="absolute transition-transform duration-100 ease-out"
      :style="cursorStyle(cursor)"
    >
      <!-- Cursor arrow -->
      <svg width="16" height="20" viewBox="0 0 16 20" class="drop-shadow-sm">
        <path
          d="M0 0L16 12L8 12L4 20L0 0Z"
          :fill="cursor.color"
          stroke="white"
          stroke-width="1"
        />
      </svg>
      <!-- Name label -->
      <div
        class="ml-4 -mt-1 px-2 py-0.5 rounded-full text-white text-[10px] font-medium whitespace-nowrap"
        :style="{ backgroundColor: cursor.color }"
      >
        {{ cursor.name }}
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue'

const props = defineProps({
  collaborators: { type: Array, default: () => [] },
  zoom: { type: Number, default: 1 },
  panX: { type: Number, default: 0 },
  panY: { type: Number, default: 0 },
})

const visibleCursors = computed(() =>
  props.collaborators.filter(c => c.cursor_x != null && c.cursor_y != null)
)

function cursorStyle(cursor) {
  const x = (cursor.cursor_x || 0) * props.zoom + props.panX
  const y = (cursor.cursor_y || 0) * props.zoom + props.panY
  return { transform: `translate(${x}px, ${y}px)` }
}
</script>
