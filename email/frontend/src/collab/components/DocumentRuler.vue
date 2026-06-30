<template>
  <div class="document-ruler" v-if="visible">
    <div class="ruler-container">
      <!-- Ruler scale -->
      <div class="ruler-scale">
        <div
          v-for="mark in rulerMarks"
          :key="mark.position"
          class="ruler-mark"
          :class="{ 'ruler-mark-major': mark.major }"
          :style="{ left: mark.position + '%' }"
        >
          <span v-if="mark.major" class="ruler-number">{{ mark.label }}</span>
        </div>
      </div>
      
      <!-- Left margin indicator -->
      <div
        class="ruler-margin-indicator ruler-margin-left"
        :style="{ left: leftMarginPercent + '%' }"
        @mousedown="startDrag('left', $event)"
        @touchstart="startDrag('left', $event)"
      >
        <div class="margin-handle"></div>
        <div class="margin-line"></div>
      </div>
      
      <!-- Right margin indicator -->
      <div
        class="ruler-margin-indicator ruler-margin-right"
        :style="{ right: rightMarginPercent + '%' }"
        @mousedown="startDrag('right', $event)"
        @touchstart="startDrag('right', $event)"
      >
        <div class="margin-handle"></div>
        <div class="margin-line"></div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue'

const props = defineProps({
  visible: {
    type: Boolean,
    default: true
  },
  pageWidth: {
    type: Number,
    default: 794 // A4 width at 96 DPI
  },
  leftMargin: {
    type: Number,
    default: 96 // 1 inch (Word default)
  },
  rightMargin: {
    type: Number,
    default: 96 // 1 inch (Word default)
  }
})

const emit = defineEmits(['update:leftMargin', 'update:rightMargin', 'margins-changed'])

// Calculate margin percentages
const leftMarginPercent = computed(() => {
  return (props.leftMargin / props.pageWidth) * 100
})

const rightMarginPercent = computed(() => {
  return (props.rightMargin / props.pageWidth) * 100
})

// Generate ruler marks
const rulerMarks = computed(() => {
  const marks = []
  const totalInches = props.pageWidth / 96 // Convert pixels to inches at 96 DPI
  const marksPerInch = 8 // 8 marks per inch (every 1/8 inch)
  
  for (let i = 0; i <= totalInches * marksPerInch; i++) {
    const position = (i / (totalInches * marksPerInch)) * 100
    const isMajor = i % marksPerInch === 0
    const inchNumber = Math.floor(i / marksPerInch)
    
    marks.push({
      position,
      major: isMajor,
      label: isMajor ? inchNumber : ''
    })
  }
  
  return marks
})

// Drag handling
let isDragging = ref(false)
let dragType = ref(null)
let startX = 0
let startMargin = 0

function startDrag(type, event) {
  event.preventDefault()
  event.stopPropagation()
  isDragging.value = true
  dragType.value = type
  
  // Support both mouse and touch events
  const clientX = event.touches ? event.touches[0].clientX : event.clientX
  startX = clientX
  startMargin = type === 'left' ? props.leftMargin : props.rightMargin
  
  document.addEventListener('mousemove', handleDrag)
  document.addEventListener('mouseup', stopDrag)
  document.addEventListener('touchmove', handleDrag)
  document.addEventListener('touchend', stopDrag)
}

function handleDrag(event) {
  if (!isDragging.value) return
  
  event.preventDefault()
  
  const rulerContainer = event.target.closest('.ruler-container')?.parentElement || 
                         document.querySelector('.document-ruler')
  if (!rulerContainer) return
  
  const rect = rulerContainer.getBoundingClientRect()
  // Support both mouse and touch events
  const clientX = event.touches ? event.touches[0].clientX : event.clientX
  const deltaX = clientX - startX
  const deltaPixels = (deltaX / rect.width) * props.pageWidth
  
  // Fix direction: left margin moves with mouse, right margin moves opposite
  let newMargin
  if (dragType.value === 'left') {
    newMargin = Math.max(0, Math.min(props.pageWidth / 2, startMargin + deltaPixels))
  } else {
    // Right margin: moving right decreases margin, moving left increases it
    newMargin = Math.max(0, Math.min(props.pageWidth / 2, startMargin - deltaPixels))
  }
  
  if (dragType.value === 'left') {
    emit('update:leftMargin', Math.round(newMargin))
  } else {
    emit('update:rightMargin', Math.round(newMargin))
  }
  
  emit('margins-changed', {
    left: dragType.value === 'left' ? Math.round(newMargin) : props.leftMargin,
    right: dragType.value === 'right' ? Math.round(newMargin) : props.rightMargin
  })
}

function stopDrag() {
  isDragging.value = false
  dragType.value = null
  document.removeEventListener('mousemove', handleDrag)
  document.removeEventListener('mouseup', stopDrag)
  document.removeEventListener('touchmove', handleDrag)
  document.removeEventListener('touchend', stopDrag)
}

onUnmounted(() => {
  document.removeEventListener('mousemove', handleDrag)
  document.removeEventListener('mouseup', stopDrag)
})
</script>

<style scoped>
.document-ruler {
  width: 100%;
  height: 24px;
  background: #fff;
  user-select: none;
}

.ruler-container {
  position: relative;
  width: 100%;
  height: 100%;
}

.ruler-scale {
  position: relative;
  width: 100%;
  height: 100%;
}

.ruler-mark {
  position: absolute;
  top: 0;
  width: 1px;
  height: 6px;
  background: #d1d5db;
}

.ruler-mark-major {
  height: 12px;
  background: #9ca3af;
}

.ruler-number {
  position: absolute;
  top: 12px;
  left: 4px;
  font-size: 9px;
  color: #9ca3af;
  font-weight: 500;
}

.ruler-margin-indicator {
  position: absolute;
  top: 0;
  width: 0;
  height: 100%;
  cursor: ew-resize;
  z-index: 10;
}

.ruler-margin-left {
  left: 0;
}

.ruler-margin-right {
  right: 0;
}

.margin-handle {
  position: absolute;
  top: 2px;
  bottom: 2px;
  left: -4px;
  width: 8px;
  background: rgb(var(--color-primary-500));
  border-radius: 2px;
  cursor: ew-resize;
}

.ruler-margin-right .margin-handle {
  left: auto;
  right: -4px;
}

.margin-handle:hover {
  background: rgb(var(--color-primary-600));
}

.margin-line {
  display: none;
}
</style>

