<template>
  <div class="collab-cursors-overlay pointer-events-none absolute inset-0 overflow-hidden">
    <!-- Remote cursors for presentation canvas -->
    <div
      v-for="cursor in cursors"
      :key="cursor.clientId"
      class="collab-cursor absolute transition-all duration-75"
      :style="getCursorStyle(cursor)"
    >
      <!-- Cursor pointer -->
      <svg 
        class="w-4 h-4 drop-shadow-md" 
        viewBox="0 0 24 24"
        :style="{ color: cursor.user.color }"
      >
        <path 
          fill="currentColor" 
          d="M4.5 2L20 12L13.5 13.5L17 22L14 23L10.5 15L4.5 19V2Z"
        />
      </svg>
      
      <!-- User label -->
      <div 
        class="absolute left-4 top-3 px-2 py-0.5 rounded text-xs text-white whitespace-nowrap shadow-sm"
        :style="{ backgroundColor: cursor.user.color }"
      >
        {{ cursor.user.name || cursor.user.email?.split('@')[0] }}
      </div>
    </div>
    
    <!-- Object selection indicators -->
    <div
      v-for="cursor in selectingCursors"
      :key="`sel-${cursor.clientId}`"
      class="collab-selection absolute border-2 rounded pointer-events-none"
      :style="getSelectionStyle(cursor)"
    >
      <div 
        class="absolute -top-5 left-0 px-1.5 py-0.5 text-xs text-white rounded"
        :style="{ backgroundColor: cursor.user.color }"
      >
        {{ cursor.user.name || cursor.user.email?.split('@')[0] }}
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue'

const props = defineProps({
  cursors: {
    type: Array,
    default: () => [],
  },
  // For mapping object selections to positions
  objectPositions: {
    type: Object,
    default: () => ({}),
  },
  // Canvas scale factor
  scale: {
    type: Number,
    default: 1,
  },
})

// Cursors that are just pointing (have x,y)
const pointingCursors = computed(() => {
  return props.cursors.filter(c => c.x !== undefined && c.y !== undefined)
})

// Cursors that have an object selected
const selectingCursors = computed(() => {
  return props.cursors.filter(c => c.selectedObjectId && props.objectPositions[c.selectedObjectId])
})

// Get cursor position style
function getCursorStyle(cursor) {
  return {
    left: `${cursor.x * props.scale}px`,
    top: `${cursor.y * props.scale}px`,
    zIndex: 1000,
  }
}

// Get selection box style for selected object
function getSelectionStyle(cursor) {
  const objPos = props.objectPositions[cursor.selectedObjectId]
  if (!objPos) return { display: 'none' }
  
  return {
    left: `${objPos.x * props.scale}px`,
    top: `${objPos.y * props.scale}px`,
    width: `${objPos.width * props.scale}px`,
    height: `${objPos.height * props.scale}px`,
    borderColor: cursor.user.color,
    zIndex: 999,
  }
}
</script>

<style>
.collab-cursor {
  will-change: transform, left, top;
}

.collab-selection {
  box-shadow: 0 0 0 1px rgba(255, 255, 255, 0.5);
}
</style>

