<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue'
import { NodeViewWrapper } from '@tiptap/vue-3'

const props = defineProps({
  node: {
    type: Object,
    required: true,
  },
  updateAttributes: {
    type: Function,
    required: true,
  },
  selected: {
    type: Boolean,
    default: false,
  },
})

const imageRef = ref(null)
const isResizing = ref(false)
const startX = ref(0)
const startY = ref(0)
const startWidth = ref(0)
const startHeight = ref(0)
const aspectRatio = ref(1)
const currentHandle = ref(null)

const sizing = computed(() => props.node.attrs.sizing || 'auto')
const width = computed(() => props.node.attrs.width || 'auto')
const height = computed(() => props.node.attrs.height || 'auto')

const imageStyle = computed(() => {
  if (sizing.value === 'fixed' && typeof width.value === 'number') {
    return {
      width: width.value + 'px',
      height: typeof height.value === 'number' ? height.value + 'px' : 'auto',
    }
  }
  // Auto: fill container responsively
  return {
    maxWidth: '100%',
    width: '100%',
    height: 'auto',
  }
})

const dimensionLabel = computed(() => {
  if (sizing.value === 'fixed' && typeof width.value === 'number') {
    const h = typeof height.value === 'number' ? height.value : '?'
    return `${width.value} x ${h} px`
  }
  return 'Responsive'
})

function setSizing(mode) {
  if (mode === 'auto') {
    props.updateAttributes({ sizing: 'auto' })
  } else {
    // Switch to fixed: capture current rendered size
    const img = imageRef.value
    if (img) {
      props.updateAttributes({
        sizing: 'fixed',
        width: img.offsetWidth,
        height: img.offsetHeight,
      })
    } else {
      props.updateAttributes({ sizing: 'fixed' })
    }
  }
}

function startResize(e, handle) {
  // Only allow resize handles in fixed mode
  if (sizing.value !== 'fixed') {
    // Auto-switch to fixed when user starts resizing
    const img = imageRef.value
    if (img) {
      props.updateAttributes({
        sizing: 'fixed',
        width: img.offsetWidth,
        height: img.offsetHeight,
      })
    }
  }

  e.preventDefault()
  e.stopPropagation()
  
  isResizing.value = true
  currentHandle.value = handle
  startX.value = e.clientX
  startY.value = e.clientY
  
  const img = imageRef.value
  if (img) {
    startWidth.value = img.offsetWidth
    startHeight.value = img.offsetHeight
    aspectRatio.value = startWidth.value / startHeight.value
  }
  
  document.addEventListener('mousemove', onResize)
  document.addEventListener('mouseup', stopResize)
  document.body.style.cursor = handle.includes('e') ? 'ew-resize' : 
                               handle.includes('w') ? 'ew-resize' : 
                               handle.includes('n') ? 'ns-resize' : 
                               handle.includes('s') ? 'ns-resize' : 'nwse-resize'
  document.body.style.userSelect = 'none'
}

function onResize(e) {
  if (!isResizing.value) return
  
  const dx = e.clientX - startX.value
  const dy = e.clientY - startY.value
  const handle = currentHandle.value
  
  let newWidth = startWidth.value
  let newHeight = startHeight.value
  
  // Calculate new dimensions based on handle
  if (handle.includes('e')) {
    newWidth = Math.max(50, startWidth.value + dx)
  }
  if (handle.includes('w')) {
    newWidth = Math.max(50, startWidth.value - dx)
  }
  if (handle.includes('s')) {
    newHeight = Math.max(50, startHeight.value + dy)
  }
  if (handle.includes('n')) {
    newHeight = Math.max(50, startHeight.value - dy)
  }
  
  // For corner handles, maintain aspect ratio
  if (handle.length === 2) {
    // Use the larger change to determine new size
    const widthRatio = newWidth / startWidth.value
    const heightRatio = newHeight / startHeight.value
    
    if (Math.abs(widthRatio - 1) > Math.abs(heightRatio - 1)) {
      newHeight = newWidth / aspectRatio.value
    } else {
      newWidth = newHeight * aspectRatio.value
    }
  }
  
  props.updateAttributes({
    sizing: 'fixed',
    width: Math.round(newWidth),
    height: Math.round(newHeight),
  })
}

function stopResize() {
  isResizing.value = false
  currentHandle.value = null
  document.removeEventListener('mousemove', onResize)
  document.removeEventListener('mouseup', stopResize)
  document.body.style.cursor = ''
  document.body.style.userSelect = ''
}

// Touch support for mobile
function startTouchResize(e, handle) {
  if (e.touches.length !== 1) return
  
  const touch = e.touches[0]
  const syntheticEvent = {
    clientX: touch.clientX,
    clientY: touch.clientY,
    preventDefault: () => e.preventDefault(),
    stopPropagation: () => e.stopPropagation(),
  }
  startResize(syntheticEvent, handle)
  
  document.addEventListener('touchmove', onTouchResize, { passive: false })
  document.addEventListener('touchend', stopTouchResize)
}

function onTouchResize(e) {
  if (e.touches.length !== 1) return
  e.preventDefault()
  
  const touch = e.touches[0]
  onResize({ clientX: touch.clientX, clientY: touch.clientY })
}

function stopTouchResize() {
  stopResize()
  document.removeEventListener('touchmove', onTouchResize)
  document.removeEventListener('touchend', stopTouchResize)
}

function onImageDblClick(e) {
  e.preventDefault()
  e.stopPropagation()
  const event = new CustomEvent('image-replace', {
    bubbles: true,
    detail: { updateSrc: (newSrc) => props.updateAttributes({ src: newSrc }) }
  })
  imageRef.value?.dispatchEvent(event)
}

onUnmounted(() => {
  document.removeEventListener('mousemove', onResize)
  document.removeEventListener('mouseup', stopResize)
  document.removeEventListener('touchmove', onTouchResize)
  document.removeEventListener('touchend', stopTouchResize)
})
</script>

<template>
  <NodeViewWrapper class="resizable-image-wrapper" :class="{ 'is-selected': selected, 'is-resizing': isResizing }">
    <div class="resizable-image-container">
      <img 
        ref="imageRef"
        :src="node.attrs.src" 
        :alt="node.attrs.alt || ''"
        :title="node.attrs.title || ''"
        :style="imageStyle"
        draggable="false"
        @dblclick="onImageDblClick"
      />
      
      <!-- Double-click hint overlay -->
      <div class="image-dblclick-overlay" @dblclick="onImageDblClick">
        <span class="material-symbols-rounded text-white text-2xl drop-shadow">photo_camera</span>
        <span class="text-white text-xs font-medium drop-shadow">Double-click to replace</span>
      </div>
      
      <!-- Sizing toolbar - show when selected -->
      <div v-if="selected" class="image-sizing-toolbar" @mousedown.stop>
        <button 
          @click="setSizing('auto')"
          :class="['sizing-btn', sizing === 'auto' ? 'active' : '']"
          title="Auto / Responsive - fills available space"
        >
          <span class="material-symbols-rounded text-sm">fit_screen</span>
          Auto
        </button>
        <button 
          @click="setSizing('fixed')"
          :class="['sizing-btn', sizing === 'fixed' ? 'active' : '']"
          title="Fixed pixel size - exact dimensions"
        >
          <span class="material-symbols-rounded text-sm">aspect_ratio</span>
          Fixed
        </button>
        <span class="sizing-label">{{ dimensionLabel }}</span>
      </div>
      
      <!-- Resize handles - only show when selected -->
      <template v-if="selected">
        <!-- Corner handles -->
        <div 
          class="resize-handle resize-handle-nw" 
          @mousedown="startResize($event, 'nw')"
          @touchstart="startTouchResize($event, 'nw')"
        ></div>
        <div 
          class="resize-handle resize-handle-ne" 
          @mousedown="startResize($event, 'ne')"
          @touchstart="startTouchResize($event, 'ne')"
        ></div>
        <div 
          class="resize-handle resize-handle-sw" 
          @mousedown="startResize($event, 'sw')"
          @touchstart="startTouchResize($event, 'sw')"
        ></div>
        <div 
          class="resize-handle resize-handle-se" 
          @mousedown="startResize($event, 'se')"
          @touchstart="startTouchResize($event, 'se')"
        ></div>
        
        <!-- Edge handles -->
        <div 
          class="resize-handle resize-handle-n" 
          @mousedown="startResize($event, 'n')"
          @touchstart="startTouchResize($event, 'n')"
        ></div>
        <div 
          class="resize-handle resize-handle-e" 
          @mousedown="startResize($event, 'e')"
          @touchstart="startTouchResize($event, 'e')"
        ></div>
        <div 
          class="resize-handle resize-handle-s" 
          @mousedown="startResize($event, 's')"
          @touchstart="startTouchResize($event, 's')"
        ></div>
        <div 
          class="resize-handle resize-handle-w" 
          @mousedown="startResize($event, 'w')"
          @touchstart="startTouchResize($event, 'w')"
        ></div>
      </template>
    </div>
  </NodeViewWrapper>
</template>

<style scoped>
.resizable-image-wrapper {
  display: inline-block;
  position: relative;
  margin: 0.25rem 0;
  line-height: 0;
}

.resizable-image-container {
  position: relative;
  display: inline-block;
  line-height: 0;
}

.resizable-image-container img {
  display: block;
  border-radius: 0.375rem;
  transition: box-shadow 0.15s ease;
}

.image-dblclick-overlay {
  position: absolute;
  inset: 0;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 4px;
  background: rgba(0, 0, 0, 0.45);
  border-radius: 0.375rem;
  opacity: 0;
  pointer-events: none;
  transition: opacity 0.2s ease;
  cursor: pointer;
}

.resizable-image-container:hover .image-dblclick-overlay {
  opacity: 1;
  pointer-events: auto;
}

.resizable-image-wrapper.is-selected .resizable-image-container img {
  box-shadow: 0 0 0 3px rgb(var(--color-primary-500) / 0.4);
}

.resizable-image-wrapper.is-resizing .resizable-image-container img {
  box-shadow: 0 0 0 3px rgb(var(--color-primary-500) / 0.6);
}

/* Image sizing toolbar */
.image-sizing-toolbar {
  position: absolute;
  bottom: 8px;
  left: 50%;
  transform: translateX(-50%);
  display: flex;
  align-items: center;
  gap: 2px;
  background: rgba(30, 30, 40, 0.9);
  backdrop-filter: blur(8px);
  border-radius: 9999px;
  padding: 3px 8px 3px 3px;
  box-shadow: 0 4px 12px rgba(0, 0, 0, 0.3);
  z-index: 20;
  white-space: nowrap;
}

.sizing-btn {
  display: flex;
  align-items: center;
  gap: 3px;
  padding: 3px 8px;
  border-radius: 9999px;
  font-size: 11px;
  font-weight: 500;
  color: rgba(255, 255, 255, 0.7);
  border: none;
  background: transparent;
  cursor: pointer;
  transition: all 0.15s ease;
  line-height: 1;
}

.sizing-btn:hover {
  color: white;
  background: rgba(255, 255, 255, 0.1);
}

.sizing-btn.active {
  color: white;
  background: rgb(var(--color-primary-500));
}

.sizing-label {
  font-size: 10px;
  font-weight: 500;
  color: rgba(255, 255, 255, 0.5);
  padding-left: 6px;
  border-left: 1px solid rgba(255, 255, 255, 0.15);
  font-family: ui-monospace, monospace;
  line-height: 1;
}

/* Resize handles */
.resize-handle {
  position: absolute;
  background: rgb(var(--color-primary-500));
  border: 2px solid white;
  border-radius: 50%;
  z-index: 10;
  box-shadow: 0 2px 4px rgba(0, 0, 0, 0.2);
}

/* Corner handles - larger for easier grabbing */
.resize-handle-nw,
.resize-handle-ne,
.resize-handle-sw,
.resize-handle-se {
  width: 14px;
  height: 14px;
}

.resize-handle-nw {
  top: -7px;
  left: -7px;
  cursor: nwse-resize;
}

.resize-handle-ne {
  top: -7px;
  right: -7px;
  cursor: nesw-resize;
}

.resize-handle-sw {
  bottom: -7px;
  left: -7px;
  cursor: nesw-resize;
}

.resize-handle-se {
  bottom: -7px;
  right: -7px;
  cursor: nwse-resize;
}

/* Edge handles - smaller */
.resize-handle-n,
.resize-handle-e,
.resize-handle-s,
.resize-handle-w {
  width: 10px;
  height: 10px;
}

.resize-handle-n {
  top: -5px;
  left: 50%;
  transform: translateX(-50%);
  cursor: ns-resize;
}

.resize-handle-e {
  top: 50%;
  right: -5px;
  transform: translateY(-50%);
  cursor: ew-resize;
}

.resize-handle-s {
  bottom: -5px;
  left: 50%;
  transform: translateX(-50%);
  cursor: ns-resize;
}

.resize-handle-w {
  top: 50%;
  left: -5px;
  transform: translateY(-50%);
  cursor: ew-resize;
}

/* Touch-friendly larger handles on mobile */
@media (hover: none) and (pointer: coarse) {
  .resize-handle-nw,
  .resize-handle-ne,
  .resize-handle-sw,
  .resize-handle-se {
    width: 20px;
    height: 20px;
  }

  .resize-handle-nw {
    top: -10px;
    left: -10px;
  }

  .resize-handle-ne {
    top: -10px;
    right: -10px;
  }

  .resize-handle-sw {
    bottom: -10px;
    left: -10px;
  }

  .resize-handle-se {
    bottom: -10px;
    right: -10px;
  }

  .resize-handle-n,
  .resize-handle-e,
  .resize-handle-s,
  .resize-handle-w {
    width: 16px;
    height: 16px;
  }

  .resize-handle-n {
    top: -8px;
  }

  .resize-handle-e {
    right: -8px;
  }

  .resize-handle-s {
    bottom: -8px;
  }

  .resize-handle-w {
    left: -8px;
  }
}
</style>
