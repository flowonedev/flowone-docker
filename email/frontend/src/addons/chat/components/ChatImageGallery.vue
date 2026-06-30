<script setup>
import { ref, computed, watch, onMounted, onUnmounted } from 'vue'
import { getToken } from '@/services/tokenStorage'
import { getApiOrigin } from '@/services/serverRegistry'
import { useChatStore } from '@/addons/chat/stores/chat'

const props = defineProps({
  images: {
    type: Array,
    required: true
  },
  initialIndex: {
    type: Number,
    default: 0
  },
  contentId: {
    type: String,
    default: ''
  }
})

const emit = defineEmits(['close'])

const chatStore = useChatStore()
const currentIndex = ref(props.initialIndex)
const zoom = ref(1)
const imageContainer = ref(null)

const currentImage = computed(() => props.images[currentIndex.value])

const hasNext = computed(() => currentIndex.value < props.images.length - 1)
const hasPrev = computed(() => currentIndex.value > 0)

// View Together: check if we have an active session
const isViewTogether = computed(() => chatStore.viewSession !== null)

// Other participant's current position
const otherPosition = computed(() => {
  const pos = chatStore.otherParticipantPosition
  if (!pos || pos.position?.type !== 'image') return null
  return pos
})

// Other participant's cursor
const otherCursor = computed(() => {
  if (!isViewTogether.value) return null
  return chatStore.otherParticipantCursor
})

// Check if other participant is on the same image
const otherOnSameImage = computed(() => {
  // Check from position data
  if (otherPosition.value?.position?.index === currentIndex.value) {
    return true
  }
  // Also check from cursor's position data if available
  if (otherCursor.value?.position?.index === currentIndex.value) {
    return true
  }
  return false
})

function next() {
  if (hasNext.value) {
    currentIndex.value++
    syncPosition()
  }
}

function prev() {
  if (hasPrev.value) {
    currentIndex.value--
    syncPosition()
  }
}

function goToIndex(index) {
  currentIndex.value = index
  syncPosition()
}

function close() {
  emit('close')
}

function handleKeydown(e) {
  switch (e.key) {
    case 'ArrowRight':
      next()
      break
    case 'ArrowLeft':
      prev()
      break
    case 'Escape':
      close()
      break
    case '+':
    case '=':
      zoomIn()
      break
    case '-':
      zoomOut()
      break
    case '0':
      zoom.value = 1
      syncPosition()
      break
  }
}

function zoomIn() {
  if (zoom.value < 3) {
    zoom.value = Math.min(3, zoom.value + 0.25)
    syncPosition()
  }
}

function zoomOut() {
  if (zoom.value > 0.5) {
    zoom.value = Math.max(0.5, zoom.value - 0.25)
    syncPosition()
  }
}

function handleWheel(e) {
  if (e.ctrlKey || e.metaKey) {
    e.preventDefault()
    if (e.deltaY < 0) {
      zoomIn()
    } else {
      zoomOut()
    }
  }
}

// Handle mouse movement for cursor sync
function handleMouseMove(e) {
  if (!isViewTogether.value || !imageContainer.value) return
  
  const rect = imageContainer.value.getBoundingClientRect()
  const x = e.clientX - rect.left
  const y = e.clientY - rect.top
  
  // Include current position so other user knows we're on same image
  const currentPosition = {
    type: 'image',
    contentId: props.contentId,
    index: currentIndex.value,
    zoom: zoom.value
  }
  
  chatStore.syncCursorPosition(x, y, rect.width, rect.height, currentPosition)
}

function handleMouseLeave() {
  // Clear cursor when leaving the image area
  if (isViewTogether.value) {
    chatStore.syncCursorPosition(-1, -1, 1, 1, null) // Negative values indicate cursor left
  }
}

// Sync position to other participant
function syncPosition() {
  if (isViewTogether.value) {
    chatStore.syncViewPosition({
      type: 'image',
      contentId: props.contentId,
      index: currentIndex.value,
      zoom: zoom.value
    })
  }
}

function downloadImage() {
  if (!currentImage.value) return
  
  const link = document.createElement('a')
  link.href = getImageUrl(currentImage.value)
  link.download = currentImage.value.original_name || currentImage.value.filename || 'image'
  document.body.appendChild(link)
  link.click()
  document.body.removeChild(link)
}

function buildAuthUrl(conversationId, filename) {
  const url = getApiOrigin() + '/api/chat/attachments/' + conversationId + '/' + encodeURIComponent(filename)
  const token = getToken('webmail_token')
  return token ? url + '?token=' + encodeURIComponent(token) : url
}

function getImageUrl(img) {
  if (!img) return ''
  const path = img.path || img.url || ''
  const match = path.match(/\/?chat_attachments\/(\d+)\/(.+)$/)
  if (match) {
    const [, conversationId, filename] = match
    return buildAuthUrl(conversationId, filename)
  }
  console.warn('Unexpected image path format:', path, img)
  return ''
}

function getThumbnailUrl(img) {
  if (!img) return ''
  if (img.thumbnail) {
    const match = img.thumbnail.match(/\/?chat_attachments\/(\d+)\/(.+)$/)
    if (match) {
      const [, conversationId, filename] = match
      return buildAuthUrl(conversationId, filename)
    }
  }
  return getImageUrl(img)
}

function getInitials(name) {
  if (!name) return '?'
  return name.substring(0, 2).toUpperCase()
}

// Computed cursor position for display (convert relative to absolute)
const displayCursorPosition = computed(() => {
  if (!otherCursor.value || !imageContainer.value) return null
  if (otherCursor.value.x < 0 || otherCursor.value.y < 0) return null // Cursor left the area
  
  const rect = imageContainer.value.getBoundingClientRect()
  return {
    x: otherCursor.value.x * rect.width,
    y: otherCursor.value.y * rect.height,
    user: otherCursor.value.user
  }
})

onMounted(() => {
  document.addEventListener('keydown', handleKeydown)
  document.body.style.overflow = 'hidden'
  
  // If View Together is active, sync initial position
  if (isViewTogether.value) {
    // Update session with actual content
    if (chatStore.viewSession?.contentType === 'pending') {
      chatStore.startViewSession('image', props.contentId)
    }
    syncPosition()
  }
})

onUnmounted(() => {
  document.removeEventListener('keydown', handleKeydown)
  document.body.style.overflow = ''
})

watch(() => props.initialIndex, (val) => {
  currentIndex.value = val
  syncPosition()
})

// Watch for other participant's position changes - auto-follow if enabled or sync scroll is on
watch(() => chatStore.otherParticipantPosition, (pos) => {
  if (!pos || pos.position?.type !== 'image') return
  
  // Auto-follow if follow mode OR sync scroll mode is enabled (and we're not the presenter)
  const shouldFollow = chatStore.followMode || (chatStore.syncScrollMode && !chatStore.isPresenter)
  
  if (shouldFollow && pos.position.index !== currentIndex.value) {
    currentIndex.value = pos.position.index
    if (pos.position.zoom) {
      zoom.value = pos.position.zoom
    }
  }
}, { deep: true })
</script>

<template>
  <Teleport to="body">
    <div class="fixed inset-0 z-[99990] flex items-center justify-center" @wheel="handleWheel">
      <!-- Backdrop -->
      <div 
        class="absolute inset-0 bg-black/90"
        @click="close"
      ></div>
      
      <!-- Header -->
      <div class="absolute top-0 left-0 right-0 p-4 flex items-center justify-between z-10">
        <div class="text-white">
          <p class="font-medium">{{ currentImage?.original_name || currentImage?.filename }}</p>
          <p class="text-sm text-white/60">
            {{ currentIndex + 1 }} of {{ images.length }}
            <span v-if="currentImage?.sender_name" class="ml-2">
              from {{ currentImage.sender_name }}
            </span>
            <span v-if="zoom !== 1" class="ml-2">
              ({{ Math.round(zoom * 100) }}%)
            </span>
          </p>
        </div>
        
        <div class="flex items-center gap-2">
          <!-- View Together indicator -->
          <div 
            v-if="isViewTogether"
            class="flex items-center gap-2 px-3 py-1.5 bg-primary-500 rounded-full text-sm mr-2"
          >
            <span class="material-symbols-rounded text-sm animate-pulse">screen_share</span>
            <span>View Together</span>
            <span v-if="chatStore.followMode" class="text-xs bg-white/20 px-2 py-0.5 rounded-full">Following</span>
          </div>
          
          <!-- Zoom controls -->
          <button
            @click="zoomOut"
            :disabled="zoom <= 0.5"
            class="p-2 hover:bg-white/10 rounded-full transition-colors text-white disabled:opacity-30"
            title="Zoom out (-)"
          >
            <span class="material-symbols-rounded">zoom_out</span>
          </button>
          <button
            @click="zoomIn"
            :disabled="zoom >= 3"
            class="p-2 hover:bg-white/10 rounded-full transition-colors text-white disabled:opacity-30"
            title="Zoom in (+)"
          >
            <span class="material-symbols-rounded">zoom_in</span>
          </button>
          
          <button
            @click="downloadImage"
            class="p-2 hover:bg-white/10 rounded-full transition-colors text-white"
            title="Download"
          >
            <span class="material-symbols-rounded">download</span>
          </button>
          <button
            @click="close"
            class="p-2 hover:bg-white/10 rounded-full transition-colors text-white"
            title="Close (Esc)"
          >
            <span class="material-symbols-rounded">close</span>
          </button>
        </div>
      </div>
      
      <!-- Other participant viewing different image indicator -->
      <div 
        v-if="isViewTogether && otherPosition && !otherOnSameImage && !chatStore.followMode"
        class="absolute top-16 left-1/2 -translate-x-1/2 z-20"
      >
        <button
          @click="goToIndex(otherPosition.position.index)"
          class="flex items-center gap-2 px-4 py-2 bg-amber-500 text-white rounded-full text-sm hover:bg-amber-600 transition-colors"
        >
          <span class="w-6 h-6 bg-white text-amber-500 rounded-full flex items-center justify-center text-xs font-medium">
            {{ getInitials(otherPosition.user?.name) }}
          </span>
          <span>{{ otherPosition.user?.name }} is viewing image {{ otherPosition.position.index + 1 }}</span>
          <span class="material-symbols-rounded text-sm">arrow_forward</span>
        </button>
      </div>
      
      <!-- Navigation arrows -->
      <button
        v-if="hasPrev"
        @click.stop="prev"
        class="absolute left-4 top-1/2 -translate-y-1/2 p-3 bg-black/50 hover:bg-black/70 rounded-full text-white transition-colors z-10"
        title="Previous"
      >
        <span class="material-symbols-rounded text-2xl">chevron_left</span>
      </button>
      
      <button
        v-if="hasNext"
        @click.stop="next"
        class="absolute right-4 top-1/2 -translate-y-1/2 p-3 bg-black/50 hover:bg-black/70 rounded-full text-white transition-colors z-10"
        title="Next"
      >
        <span class="material-symbols-rounded text-2xl">chevron_right</span>
      </button>
      
      <!-- Main image container -->
      <div 
        ref="imageContainer"
        class="relative max-w-[90vw] max-h-[85vh] z-10 overflow-hidden"
        :style="{ transform: `scale(${zoom})`, transition: 'transform 0.2s ease' }"
        @mousemove="handleMouseMove"
        @mouseleave="handleMouseLeave"
      >
        <img
          v-if="currentImage"
          :src="getImageUrl(currentImage)"
          :alt="currentImage.original_name || 'Image'"
          class="max-w-full max-h-[85vh] object-contain rounded-lg"
          @click.stop
        />
        
        <!-- Other participant's cursor -->
        <div 
          v-if="isViewTogether && otherOnSameImage && displayCursorPosition"
          class="absolute pointer-events-none transition-all duration-75"
          :style="{ 
            left: displayCursorPosition.x + 'px', 
            top: displayCursorPosition.y + 'px',
            transform: 'translate(-4px, -4px)'
          }"
        >
          <!-- Cursor pointer -->
          <svg width="24" height="24" viewBox="0 0 24 24" class="drop-shadow-lg">
            <path 
              d="M4 4 L4 20 L9 15 L14 20 L16 18 L11 13 L18 13 Z" 
              fill="#f59e0b" 
              stroke="white" 
              stroke-width="1.5"
            />
          </svg>
          <!-- Name tag -->
          <div class="absolute left-5 top-4 px-2 py-0.5 bg-amber-500 text-white text-xs rounded whitespace-nowrap">
            {{ displayCursorPosition.user?.name || 'Participant' }}
          </div>
        </div>
        
        <!-- Same image indicator - show other user's avatar -->
        <div 
          v-if="isViewTogether && otherOnSameImage && !displayCursorPosition"
          class="absolute top-4 right-4 flex items-center gap-2 px-3 py-1.5 bg-green-500 text-white rounded-full text-sm"
        >
          <span class="w-5 h-5 bg-white text-green-500 rounded-full flex items-center justify-center text-xs font-medium">
            {{ getInitials(otherPosition?.user?.name) }}
          </span>
          <span>{{ otherPosition?.user?.name }} is here</span>
        </div>
      </div>
      
      <!-- Thumbnails -->
      <div 
        v-if="images.length > 1"
        class="absolute bottom-4 left-1/2 -translate-x-1/2 flex gap-2 px-4 py-2 bg-black/50 rounded-xl z-10 max-w-[90vw] overflow-x-auto"
      >
        <button
          v-for="(img, index) in images"
          :key="img.id || index"
          @click.stop="goToIndex(index)"
          :class="[
            'relative w-12 h-12 rounded-lg overflow-hidden flex-shrink-0 transition-all',
            index === currentIndex ? 'ring-2 ring-primary-500 ring-offset-2 ring-offset-black' : 'opacity-60 hover:opacity-100'
          ]"
        >
          <img
            :src="getThumbnailUrl(img)"
            :alt="img.original_name || 'Thumbnail'"
            class="w-full h-full object-cover"
          />
          <!-- Other participant indicator on thumbnail -->
          <div 
            v-if="isViewTogether && otherPosition?.position?.index === index && index !== currentIndex"
            class="absolute inset-0 flex items-center justify-center bg-amber-500/70"
          >
            <span class="w-6 h-6 bg-white text-amber-500 rounded-full flex items-center justify-center text-xs font-bold">
              {{ getInitials(otherPosition?.user?.name) }}
            </span>
          </div>
        </button>
      </div>
    </div>
  </Teleport>
</template>
