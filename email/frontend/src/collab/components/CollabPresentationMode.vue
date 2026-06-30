<template>
  <Teleport to="body">
    <div 
      class="collab-presentation-mode"
      ref="containerRef"
      tabindex="0"
      @keydown="handleKeyDown"
      @click="handleClick"
      @mousemove="handleMouseMove"
    >
      <!-- Current slide -->
      <div 
        class="slide-container"
        :style="slideContainerStyle"
      >
        <div 
          class="slide-content"
          :style="slideBackgroundStyle"
        >
          <!-- SVG shapes layer -->
          <svg 
            class="absolute inset-0 w-full h-full pointer-events-none"
            :viewBox="`0 0 ${slideWidth} ${slideHeight}`"
            preserveAspectRatio="xMidYMid meet"
          >
            <g v-for="obj in currentSlideShapes" :key="obj.id">
              <!-- Rectangle -->
              <rect
                v-if="obj.shapeType === 'rectangle'"
                :x="obj.x"
                :y="obj.y"
                :width="obj.width"
                :height="obj.height"
                :fill="obj.fill"
                :stroke="obj.stroke"
                :stroke-width="obj.strokeWidth"
                :transform="getRotationTransform(obj)"
                :rx="obj.borderRadius || 0"
              />
              
              <!-- Ellipse -->
              <ellipse
                v-else-if="obj.shapeType === 'ellipse'"
                :cx="obj.x + obj.width / 2"
                :cy="obj.y + obj.height / 2"
                :rx="obj.width / 2"
                :ry="obj.height / 2"
                :fill="obj.fill"
                :stroke="obj.stroke"
                :stroke-width="obj.strokeWidth"
                :transform="getRotationTransform(obj)"
              />
              
              <!-- Triangle -->
              <polygon
                v-else-if="obj.shapeType === 'triangle'"
                :points="getTrianglePoints(obj)"
                :fill="obj.fill"
                :stroke="obj.stroke"
                :stroke-width="obj.strokeWidth"
                :transform="getRotationTransform(obj)"
              />
              
              <!-- Line -->
              <line
                v-else-if="obj.shapeType === 'line'"
                :x1="obj.x"
                :y1="obj.y + obj.height / 2"
                :x2="obj.x + obj.width"
                :y2="obj.y + obj.height / 2"
                :stroke="obj.stroke || obj.fill"
                :stroke-width="obj.strokeWidth || 2"
                :transform="getRotationTransform(obj)"
              />
              
              <!-- Arrow -->
              <g v-else-if="obj.shapeType === 'arrow'" :transform="getRotationTransform(obj)">
                <line
                  :x1="obj.x"
                  :y1="obj.y + obj.height / 2"
                  :x2="obj.x + obj.width - 15"
                  :y2="obj.y + obj.height / 2"
                  :stroke="obj.stroke || obj.fill"
                  :stroke-width="obj.strokeWidth || 2"
                />
                <polygon
                  :points="getArrowHeadPoints(obj)"
                  :fill="obj.stroke || obj.fill"
                />
              </g>
            </g>
          </svg>
          
          <!-- DOM objects layer (text, images) -->
          <div class="absolute inset-0" :style="{ transform: `scale(${scale})`, transformOrigin: 'top left' }">
            <div
              v-for="obj in currentSlideNonShapes"
              :key="obj.id"
              class="absolute"
              :style="getObjectStyle(obj)"
            >
              <!-- Text -->
              <div 
                v-if="obj.type === 'text'"
                class="w-full h-full overflow-hidden"
                :class="getListClass(obj)"
                :style="getTextStyle(obj)"
                v-html="formatTextContent(obj)"
              ></div>
              
              <!-- Image -->
              <img 
                v-if="obj.type === 'image'"
                :src="obj.imageUrl"
                :style="{ objectFit: obj.objectFit || 'contain', objectPosition: obj.objectPosition || '50% 50%', borderRadius: obj.borderRadius ? `${obj.borderRadius}px` : '0', display: 'block' }"
                class="w-full h-full"
                draggable="false"
              />
            </div>
          </div>
        </div>
      </div>
      
      <!-- Laser pointer (when enabled) -->
      <div 
        v-if="showLaser && laserPosition.x > 0"
        class="laser-pointer"
        :style="{ left: laserPosition.x + 'px', top: laserPosition.y + 'px' }"
      ></div>
      
      <!-- Controls overlay (hidden until mouse moves) -->
      <div 
        class="controls-overlay"
        :class="{ 'opacity-0': !showControls }"
      >
        <!-- Top bar -->
        <div class="top-bar">
          <div class="text-white/80 text-sm">
            Slide {{ currentIndex + 1 }} of {{ slides.length }}
          </div>
          <button 
            @click.stop="$emit('exit')"
            class="exit-btn"
          >
            <span class="material-symbols-rounded">close</span>
            Exit
          </button>
        </div>
        
        <!-- Bottom navigation -->
        <div class="bottom-bar">
          <button 
            @click.stop="previousSlide"
            :disabled="currentIndex === 0"
            class="nav-btn"
          >
            <span class="material-symbols-rounded">chevron_left</span>
          </button>
          
          <!-- Slide progress dots -->
          <div class="progress-dots">
            <button
              v-for="(_, i) in slides"
              :key="i"
              @click.stop="goToSlide(i)"
              class="dot"
              :class="{ 'active': i === currentIndex, 'passed': i < currentIndex }"
            ></button>
          </div>
          
          <button 
            @click.stop="nextSlide"
            :disabled="currentIndex === slides.length - 1"
            class="nav-btn"
          >
            <span class="material-symbols-rounded">chevron_right</span>
          </button>
          
          <!-- Tools -->
          <div class="tools-separator"></div>
          
          <button 
            @click.stop="toggleLaser"
            class="tool-btn"
            :class="{ 'active': showLaser }"
            title="Laser pointer (L)"
          >
            <span class="material-symbols-rounded">point_scan</span>
          </button>
          
          <button 
            @click.stop="toggleFullscreen"
            class="tool-btn"
            title="Toggle fullscreen (F)"
          >
            <span class="material-symbols-rounded">{{ isFullscreen ? 'fullscreen_exit' : 'fullscreen' }}</span>
          </button>
        </div>
      </div>
      
      <!-- Click areas for navigation -->
      <div class="click-prev" @click.stop="previousSlide"></div>
      <div class="click-next" @click.stop="nextSlide"></div>
    </div>
  </Teleport>
</template>

<script setup>
import { ref, computed, onMounted, onUnmounted, nextTick } from 'vue'

const props = defineProps({
  slides: {
    type: Array,
    required: true,
  },
  startIndex: {
    type: Number,
    default: 0,
  },
  slideWidth: {
    type: Number,
    default: 1920,
  },
  slideHeight: {
    type: Number,
    default: 1080,
  },
})

const emit = defineEmits(['exit'])

// State
const containerRef = ref(null)
const currentIndex = ref(props.startIndex)
const showControls = ref(true)
const showLaser = ref(false)
const laserPosition = ref({ x: 0, y: 0 })
const isFullscreen = ref(false)
const scale = ref(1)

// Control hide timer
let controlsTimer = null

// Current slide data
const currentSlide = computed(() => props.slides[currentIndex.value] || null)

const currentSlideShapes = computed(() => {
  return (currentSlide.value?.objects || []).filter(o => o.type === 'shape')
})

const currentSlideNonShapes = computed(() => {
  return (currentSlide.value?.objects || []).filter(o => o.type !== 'shape')
})

// Calculate scale to fit slide in viewport
const slideContainerStyle = computed(() => {
  return {
    width: `${props.slideWidth * scale.value}px`,
    height: `${props.slideHeight * scale.value}px`,
  }
})

const slideBackgroundStyle = computed(() => {
  if (!currentSlide.value) return { backgroundColor: '#ffffff' }
  
  const bg = currentSlide.value.background || { type: 'solid', value: '#ffffff' }
  
  if (bg.type === 'gradient') {
    return { background: bg.value }
  } else if (bg.type === 'image') {
    return { 
      backgroundImage: `url(${bg.value})`,
      backgroundSize: 'cover',
      backgroundPosition: 'center',
    }
  }
  return { backgroundColor: bg.value }
})

// Calculate scale on mount and resize
function calculateScale() {
  const padding = 40
  const maxWidth = window.innerWidth - padding * 2
  const maxHeight = window.innerHeight - padding * 2
  
  const scaleX = maxWidth / props.slideWidth
  const scaleY = maxHeight / props.slideHeight
  
  scale.value = Math.min(scaleX, scaleY, 1)
}

// Shape helpers
function getRotationTransform(obj) {
  if (!obj.rotation) return ''
  const cx = obj.x + obj.width / 2
  const cy = obj.y + obj.height / 2
  return `rotate(${obj.rotation} ${cx} ${cy})`
}

function getTrianglePoints(obj) {
  const { x, y, width, height } = obj
  const topX = x + width / 2
  const topY = y
  const bottomLeftX = x
  const bottomLeftY = y + height
  const bottomRightX = x + width
  const bottomRightY = y + height
  
  return `${topX},${topY} ${bottomLeftX},${bottomLeftY} ${bottomRightX},${bottomRightY}`
}

function getArrowHeadPoints(obj) {
  const { x, y, width, height } = obj
  const tipX = x + width
  const tipY = y + height / 2
  const backX = x + width - 15
  const backTopY = y + height / 2 - 8
  const backBottomY = y + height / 2 + 8
  
  return `${tipX},${tipY} ${backX},${backTopY} ${backX},${backBottomY}`
}

function getObjectStyle(obj) {
  return {
    left: `${obj.x}px`,
    top: `${obj.y}px`,
    width: `${obj.width}px`,
    height: `${obj.height}px`,
    transform: obj.rotation ? `rotate(${obj.rotation}deg)` : undefined,
    zIndex: obj.zIndex || 0,
  }
}

function getTextStyle(obj) {
  const borderWidth = Number(obj.borderWidth || 0)
  const borderColor = obj.borderColor || 'transparent'

  return {
    fontSize: `${obj.fontSize || 24}px`,
    fontFamily: obj.fontFamily || 'Inter',
    fontWeight: obj.fontWeight || 'normal',
    fontStyle: obj.fontStyle || 'normal',
    textAlign: obj.textAlign || 'left',
    color: obj.color || '#000000',
    backgroundColor: obj.backgroundColor || 'transparent',
    letterSpacing: obj.letterSpacing ? `${obj.letterSpacing}px` : 'normal',
    textDecoration: obj.textDecoration || 'none',
    textTransform: obj.textTransform || 'none',
    padding: '8px',
    border: borderWidth > 0 && borderColor !== 'transparent' ? `${borderWidth}px solid ${borderColor}` : 'none',
    borderRadius: obj.borderRadius ? `${obj.borderRadius}px` : '0',
    boxSizing: 'border-box',
  }
}

function getListClass(obj) {
  if (obj.listType === 'bullet') return 'list-style-bullet'
  if (obj.listType === 'numbered') return 'list-style-numbered'
  return ''
}

function formatTextContent(obj) {
  const content = obj.content || ''
  const listType = obj.listType
  
  // If no list type or content is already formatted, return as-is
  if (!listType || listType === 'none') {
    return content
  }
  
  // Check if content is already a list
  if (content.includes('<ul>') || content.includes('<ol>')) {
    return content
  }
  
  // Split content by line breaks or paragraphs and wrap in list items
  const tempDiv = document.createElement('div')
  tempDiv.innerHTML = content
  
  // Get text nodes and paragraphs
  const lines = []
  const walker = document.createTreeWalker(tempDiv, NodeFilter.SHOW_TEXT | NodeFilter.SHOW_ELEMENT)
  let node
  let currentText = ''
  
  while (node = walker.nextNode()) {
    if (node.nodeType === Node.TEXT_NODE) {
      currentText += node.textContent
    } else if (node.tagName === 'P' || node.tagName === 'BR' || node.tagName === 'DIV') {
      if (currentText.trim()) {
        lines.push(currentText.trim())
        currentText = ''
      }
    }
  }
  if (currentText.trim()) {
    lines.push(currentText.trim())
  }
  
  // If we couldn't extract lines, just return content
  if (lines.length === 0) {
    return content
  }
  
  // Wrap in appropriate list
  const tag = listType === 'bullet' ? 'ul' : 'ol'
  const listItems = lines.map(line => `<li>${line}</li>`).join('')
  return `<${tag} class="slide-list">${listItems}</${tag}>`
}

// Navigation
function nextSlide() {
  if (currentIndex.value < props.slides.length - 1) {
    currentIndex.value++
  }
}

function previousSlide() {
  if (currentIndex.value > 0) {
    currentIndex.value--
  }
}

function goToSlide(index) {
  if (index >= 0 && index < props.slides.length) {
    currentIndex.value = index
  }
}

// Controls
function showControlsTemporarily() {
  showControls.value = true
  clearTimeout(controlsTimer)
  controlsTimer = setTimeout(() => {
    showControls.value = false
  }, 3000)
}

function handleMouseMove(e) {
  showControlsTemporarily()
  
  if (showLaser.value) {
    laserPosition.value = { x: e.clientX, y: e.clientY }
  }
}

function handleClick(e) {
  // If clicking on controls area, don't navigate
  if (e.target.closest('.controls-overlay')) return
  if (e.target.closest('.click-prev') || e.target.closest('.click-next')) return
  
  showControlsTemporarily()
}

function handleKeyDown(e) {
  switch (e.key) {
    case 'ArrowRight':
    case 'ArrowDown':
    case ' ':
    case 'PageDown':
      e.preventDefault()
      nextSlide()
      break
    case 'ArrowLeft':
    case 'ArrowUp':
    case 'PageUp':
      e.preventDefault()
      previousSlide()
      break
    case 'Home':
      e.preventDefault()
      goToSlide(0)
      break
    case 'End':
      e.preventDefault()
      goToSlide(props.slides.length - 1)
      break
    case 'Escape':
      e.preventDefault()
      emit('exit')
      break
    case 'f':
    case 'F':
      e.preventDefault()
      toggleFullscreen()
      break
    case 'l':
    case 'L':
      e.preventDefault()
      toggleLaser()
      break
  }
}

function toggleLaser() {
  showLaser.value = !showLaser.value
  if (!showLaser.value) {
    laserPosition.value = { x: 0, y: 0 }
  }
}

async function toggleFullscreen() {
  try {
    if (!document.fullscreenElement) {
      await containerRef.value?.requestFullscreen()
      isFullscreen.value = true
    } else {
      await document.exitFullscreen()
      isFullscreen.value = false
    }
  } catch (err) {
    console.error('Fullscreen error:', err)
  }
}

function handleFullscreenChange() {
  isFullscreen.value = !!document.fullscreenElement
  calculateScale()
}

onMounted(() => {
  calculateScale()
  window.addEventListener('resize', calculateScale)
  document.addEventListener('fullscreenchange', handleFullscreenChange)
  
  // Auto-hide controls after 3 seconds
  showControlsTemporarily()
  
  // Focus container for keyboard events
  nextTick(() => {
    containerRef.value?.focus()
  })
})

onUnmounted(() => {
  window.removeEventListener('resize', calculateScale)
  document.removeEventListener('fullscreenchange', handleFullscreenChange)
  clearTimeout(controlsTimer)
  
  // Exit fullscreen if still in it
  if (document.fullscreenElement) {
    document.exitFullscreen().catch(() => {})
  }
})
</script>

<style scoped>
.collab-presentation-mode {
  position: fixed;
  inset: 0;
  z-index: 99999;
  background: #000;
  display: flex;
  align-items: center;
  justify-content: center;
  outline: none;
}

.slide-container {
  position: relative;
  box-shadow: 0 25px 100px rgba(0, 0, 0, 0.5);
}

.slide-content {
  position: relative;
  width: 100%;
  height: 100%;
  overflow: hidden;
}

/* Laser pointer */
.laser-pointer {
  position: fixed;
  width: 12px;
  height: 12px;
  background: radial-gradient(circle, #ff0000 0%, #ff0000 40%, transparent 70%);
  border-radius: 50%;
  pointer-events: none;
  z-index: 100000;
  transform: translate(-50%, -50%);
  box-shadow: 0 0 20px #ff0000, 0 0 40px #ff0000;
  animation: laser-pulse 0.5s ease-in-out infinite;
}

@keyframes laser-pulse {
  0%, 100% { opacity: 1; transform: translate(-50%, -50%) scale(1); }
  50% { opacity: 0.8; transform: translate(-50%, -50%) scale(1.1); }
}

/* Controls overlay */
.controls-overlay {
  position: fixed;
  inset: 0;
  pointer-events: none;
  transition: opacity 0.3s ease;
}

.controls-overlay > * {
  pointer-events: auto;
}

.top-bar {
  position: absolute;
  top: 0;
  left: 0;
  right: 0;
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 16px 24px;
  background: linear-gradient(to bottom, rgba(0,0,0,0.6), transparent);
}

.exit-btn {
  display: flex;
  align-items: center;
  gap: 6px;
  padding: 8px 16px;
  background: rgba(255,255,255,0.1);
  border: none;
  border-radius: 8px;
  color: white;
  font-size: 14px;
  font-weight: 500;
  cursor: pointer;
  transition: background 0.2s;
}

.exit-btn:hover {
  background: rgba(255,255,255,0.2);
}

.exit-btn .material-symbols-rounded {
  font-size: 20px;
}

.bottom-bar {
  position: absolute;
  bottom: 0;
  left: 0;
  right: 0;
  display: flex;
  justify-content: center;
  align-items: center;
  gap: 16px;
  padding: 20px;
  background: linear-gradient(to top, rgba(0,0,0,0.6), transparent);
}

.nav-btn {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 48px;
  height: 48px;
  background: rgba(255,255,255,0.1);
  border: none;
  border-radius: 50%;
  color: white;
  cursor: pointer;
  transition: all 0.2s;
}

.nav-btn:hover:not(:disabled) {
  background: rgba(255,255,255,0.2);
  transform: scale(1.05);
}

.nav-btn:disabled {
  opacity: 0.3;
  cursor: not-allowed;
}

.nav-btn .material-symbols-rounded {
  font-size: 28px;
}

.progress-dots {
  display: flex;
  gap: 8px;
  padding: 0 16px;
}

.dot {
  width: 10px;
  height: 10px;
  background: rgba(255,255,255,0.3);
  border: none;
  border-radius: 50%;
  cursor: pointer;
  transition: all 0.2s;
  padding: 0;
}

.dot:hover {
  background: rgba(255,255,255,0.5);
  transform: scale(1.2);
}

.dot.passed {
  background: rgba(255,255,255,0.5);
}

.dot.active {
  background: white;
  transform: scale(1.3);
}

.tools-separator {
  width: 1px;
  height: 32px;
  background: rgba(255,255,255,0.2);
}

.tool-btn {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 40px;
  height: 40px;
  background: transparent;
  border: none;
  border-radius: 8px;
  color: rgba(255,255,255,0.7);
  cursor: pointer;
  transition: all 0.2s;
}

.tool-btn:hover {
  background: rgba(255,255,255,0.1);
  color: white;
}

.tool-btn.active {
  background: rgba(255,255,255,0.2);
  color: #ff6b6b;
}

.tool-btn .material-symbols-rounded {
  font-size: 24px;
}

/* Click areas for easy navigation */
.click-prev,
.click-next {
  position: absolute;
  top: 80px;
  bottom: 80px;
  width: 15%;
  cursor: pointer;
}

.click-prev {
  left: 0;
}

.click-next {
  right: 0;
}

/* List styles for text objects */
.list-style-bullet ul,
.list-style-bullet ol,
.list-style-bullet .slide-list {
  list-style-type: disc;
  padding-left: 1.5em;
  margin: 0;
}

.list-style-bullet li {
  margin-bottom: 0.25em;
}

.list-style-numbered ul,
.list-style-numbered ol,
.list-style-numbered .slide-list {
  list-style-type: decimal;
  padding-left: 1.5em;
  margin: 0;
}

.list-style-numbered li {
  margin-bottom: 0.25em;
}

/* Slide list base styles */
.slide-list {
  margin: 0;
  padding-left: 1.5em;
}

.slide-list li {
  margin-bottom: 0.25em;
}
</style>

