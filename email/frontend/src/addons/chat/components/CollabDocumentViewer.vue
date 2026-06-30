<script setup>
import { ref, computed, watch, onMounted, onUnmounted } from 'vue'
import { useChatStore } from '@/addons/chat/stores/chat'
import mammoth from 'mammoth'

const props = defineProps({
  url: {
    type: String,
    required: true
  },
  filename: {
    type: String,
    default: 'Document'
  },
  mimeType: {
    type: String,
    default: 'application/pdf'
  },
  contentId: {
    type: String,
    default: ''
  }
})

const emit = defineEmits(['close'])

const chatStore = useChatStore()

// State
const currentPage = ref(1)
const totalPages = ref(1)
const zoom = ref(1)
const scrollContainer = ref(null)
const loading = ref(true)
const error = ref(null)
const docxHtml = ref('') // For DOCX content

// View Together state
const isViewTogether = computed(() => chatStore.viewSession !== null)
const documentContainer = ref(null)

const otherPosition = computed(() => {
  const pos = chatStore.otherParticipantPosition
  if (!pos || pos.position?.type !== 'document') return null
  return pos
})

// Other participant's cursor
const otherCursor = computed(() => {
  if (!isViewTogether.value) return null
  return chatStore.otherParticipantCursor
})

// Check if other participant is on the same page
const otherOnSamePage = computed(() => {
  if (otherPosition.value?.position?.page === currentPage.value) return true
  if (otherCursor.value?.position?.page === currentPage.value) return true
  return false
})

// Computed cursor position for display
const displayCursorPosition = computed(() => {
  if (!otherCursor.value || !documentContainer.value) return null
  if (otherCursor.value.x < 0 || otherCursor.value.y < 0) return null
  
  const rect = documentContainer.value.getBoundingClientRect()
  return {
    x: otherCursor.value.x * rect.width,
    y: otherCursor.value.y * rect.height,
    user: otherCursor.value.user
  }
})

// Check file types
const isPdf = computed(() => {
  return props.mimeType === 'application/pdf' || props.filename?.toLowerCase().endsWith('.pdf')
})

const isDocx = computed(() => {
  const ext = props.filename?.toLowerCase().split('.').pop()
  return props.mimeType === 'application/vnd.openxmlformats-officedocument.wordprocessingml.document' ||
         props.mimeType === 'application/msword' ||
         ext === 'docx' || ext === 'doc'
})

const isImage = computed(() => {
  return props.mimeType?.startsWith('image/')
})

const isText = computed(() => {
  const ext = props.filename?.toLowerCase().split('.').pop()
  return props.mimeType?.startsWith('text/') ||
         ['txt', 'md', 'json', 'xml', 'html', 'css', 'js', 'ts', 'py', 'php'].includes(ext)
})

function close() {
  emit('close')
}

function handleKeydown(e) {
  switch (e.key) {
    case 'Escape':
      close()
      break
    case 'ArrowDown':
    case 'PageDown':
      nextPage()
      break
    case 'ArrowUp':
    case 'PageUp':
      prevPage()
      break
    case '+':
    case '=':
      zoomIn()
      break
    case '-':
      zoomOut()
      break
  }
}

function nextPage() {
  if (currentPage.value < totalPages.value) {
    currentPage.value++
    syncPosition()
  }
}

function prevPage() {
  if (currentPage.value > 1) {
    currentPage.value--
    syncPosition()
  }
}

function goToPage(page) {
  if (page >= 1 && page <= totalPages.value) {
    currentPage.value = page
    syncPosition()
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

function handleScroll() {
  if (!scrollContainer.value) return
  
  const { scrollTop, scrollHeight, clientHeight } = scrollContainer.value
  const scrollY = scrollHeight > clientHeight ? scrollTop / (scrollHeight - clientHeight) : 0
  
  // Sync scroll position (throttled by the store)
  if (isViewTogether.value) {
    chatStore.syncViewPosition({
      type: 'document',
      contentId: props.contentId,
      page: currentPage.value,
      scrollY: Math.round(scrollY * 100) / 100,
      zoom: zoom.value
    })
  }
}

function syncPosition() {
  if (isViewTogether.value) {
    chatStore.syncViewPosition({
      type: 'document',
      contentId: props.contentId,
      page: currentPage.value,
      scrollY: 0,
      zoom: zoom.value
    })
  }
}

function jumpToOther() {
  if (otherPosition.value?.position) {
    const { page, scrollY } = otherPosition.value.position
    if (page) {
      currentPage.value = page
    }
    if (scrollY !== undefined && scrollContainer.value) {
      const { scrollHeight, clientHeight } = scrollContainer.value
      scrollContainer.value.scrollTop = scrollY * (scrollHeight - clientHeight)
    }
  }
}

function downloadDocument() {
  const link = document.createElement('a')
  link.href = props.url
  link.download = props.filename
  document.body.appendChild(link)
  link.click()
  document.body.removeChild(link)
}

function getInitials(name) {
  if (!name) return '?'
  return name.substring(0, 2).toUpperCase()
}

// Handle mouse movement for cursor sync
function handleMouseMove(e) {
  if (!isViewTogether.value || !documentContainer.value) return
  
  const rect = documentContainer.value.getBoundingClientRect()
  const x = e.clientX - rect.left
  const y = e.clientY - rect.top
  
  // Include current position so other user knows we're on same page
  // Don't include scrollY here - only sync scroll explicitly when scrolling
  const currentPosition = {
    type: 'document',
    contentId: props.contentId,
    page: currentPage.value,
    zoom: zoom.value
  }
  
  chatStore.syncCursorPosition(x, y, rect.width, rect.height, currentPosition)
}

function handleMouseLeave() {
  if (isViewTogether.value) {
    chatStore.syncCursorPosition(-1, -1, 1, 1, null)
  }
}

// Text selection tracking
const textSelection = ref(null)
let selectionCheckInterval = null

function checkTextSelection() {
  if (!isViewTogether.value || !documentContainer.value) return
  
  const selection = window.getSelection()
  if (!selection || selection.isCollapsed) {
    if (textSelection.value) {
      textSelection.value = null
      syncTextSelection(null)
    }
    return
  }
  
  // Check if selection is within our document container
  const range = selection.getRangeAt(0)
  if (!documentContainer.value.contains(range.commonAncestorContainer)) {
    return
  }
  
  // Get selection details relative to container
  const containerRect = documentContainer.value.getBoundingClientRect()
  const rangeRects = range.getClientRects()
  
  if (rangeRects.length === 0) return
  
  // Convert rects to relative positions
  const rects = Array.from(rangeRects).map(rect => ({
    x: (rect.left - containerRect.left) / containerRect.width,
    y: (rect.top - containerRect.top) / containerRect.height,
    width: rect.width / containerRect.width,
    height: rect.height / containerRect.height
  }))
  
  const selectionData = {
    text: selection.toString(),
    rects
  }
  
  textSelection.value = selectionData
  syncTextSelection(selectionData)
}

function syncTextSelection(selection) {
  if (!viewSession.value?.conversationId) return
  
  chatStore.syncViewPosition({
    type: 'document',
    contentId: props.contentId,
    page: currentPage.value,
    textSelection: selection
  })
}

function handleMouseUp() {
  // Check for text selection after mouse up
  setTimeout(checkTextSelection, 10)
}

// Other participant's text selection
const otherTextSelection = computed(() => {
  const pos = chatStore.otherParticipantPosition
  if (!pos || pos.position?.type !== 'document') return null
  return pos.position?.textSelection
})

function handleIframeLoad() {
  loading.value = false
}

function handleIframeError() {
  loading.value = false
  error.value = 'Failed to load document'
}

// Load DOCX file and convert to HTML
async function loadDocx() {
  loading.value = true
  error.value = null
  
  try {
    const response = await fetch(props.url)
    if (!response.ok) throw new Error('Failed to fetch document')
    
    const arrayBuffer = await response.arrayBuffer()
    
    const result = await mammoth.convertToHtml({ arrayBuffer })
    docxHtml.value = result.value
    
    if (result.messages.length > 0) {
      console.warn('Mammoth conversion messages:', result.messages)
    }
    
    loading.value = false
  } catch (e) {
    console.error('Failed to load DOCX:', e)
    error.value = 'Failed to load document'
    loading.value = false
  }
}

// Load text file
const textContent = ref('')

async function loadText() {
  loading.value = true
  error.value = null
  
  try {
    const response = await fetch(props.url)
    if (!response.ok) throw new Error('Failed to fetch file')
    
    textContent.value = await response.text()
    loading.value = false
  } catch (e) {
    console.error('Failed to load text:', e)
    error.value = 'Failed to load file'
    loading.value = false
  }
}

onMounted(() => {
  document.addEventListener('keydown', handleKeydown)
  document.body.style.overflow = 'hidden'
  
  // Load content based on file type
  if (isDocx.value) {
    loadDocx()
  } else if (isText.value) {
    loadText()
  } else if (isPdf.value || isImage.value) {
    // These will handle their own loading via iframe/img
  } else {
    loading.value = false
  }
  
  // If View Together is active, sync initial position
  if (isViewTogether.value) {
    if (chatStore.viewSession?.contentType === 'pending') {
      chatStore.startViewSession('document', props.contentId)
    }
    syncPosition()
  }
})

onUnmounted(() => {
  document.removeEventListener('keydown', handleKeydown)
  document.body.style.overflow = ''
})

// Watch for other participant's position - auto-follow if enabled or sync scroll is on
watch(() => chatStore.otherParticipantPosition, (pos) => {
  if (!pos || pos.position?.type !== 'document') return
  
  // Auto-follow if follow mode OR sync scroll mode is enabled (and we're not the presenter)
  const shouldFollow = chatStore.followMode || (chatStore.syncScrollMode && !chatStore.isPresenter)
  
  if (shouldFollow) {
    const { page, scrollY } = pos.position
    
    // Jump to page
    if (page && page !== currentPage.value) {
      currentPage.value = page
    }
    
    // Only scroll if scrollY is explicitly provided (not just from cursor sync)
    // scrollY must be a number between 0 and 1, not undefined
    if (typeof scrollY === 'number' && scrollY >= 0 && scrollContainer.value) {
      const { scrollHeight, clientHeight } = scrollContainer.value
      scrollContainer.value.scrollTop = scrollY * (scrollHeight - clientHeight)
    }
  }
}, { deep: true })
</script>

<template>
  <Teleport to="body">
    <div class="fixed inset-0 z-[99990] flex flex-col bg-surface-900">
      <!-- Header -->
      <div class="flex items-center justify-between px-4 py-3 bg-surface-800 border-b border-surface-700">
        <div class="flex items-center gap-4">
          <span class="material-symbols-rounded text-2xl text-surface-400">
            {{ isPdf ? 'picture_as_pdf' : isDocx ? 'article' : 'description' }}
          </span>
          <div>
            <p class="font-medium text-white">{{ filename }}</p>
            <p class="text-sm text-surface-400">
              <span v-if="isPdf && totalPages > 1">Page {{ currentPage }} of {{ totalPages }}</span>
              <span v-if="zoom !== 1" class="ml-2">({{ Math.round(zoom * 100) }}%)</span>
            </p>
          </div>
        </div>
        
        <div class="flex items-center gap-2">
          <!-- View Together indicator -->
          <div 
            v-if="isViewTogether"
            class="flex items-center gap-2 px-3 py-1.5 bg-primary-500 rounded-full text-sm text-white mr-2"
          >
            <span class="material-symbols-rounded text-sm animate-pulse">screen_share</span>
            <span>View Together</span>
            <span v-if="chatStore.followMode" class="text-xs bg-white/20 px-2 py-0.5 rounded-full">Following</span>
          </div>
          
          <!-- Page navigation (for PDF) -->
          <template v-if="isPdf && totalPages > 1">
            <button
              @click="prevPage"
              :disabled="currentPage <= 1"
              class="p-2 hover:bg-surface-700 rounded-lg transition-colors text-white disabled:opacity-30"
              title="Previous page"
            >
              <span class="material-symbols-rounded">chevron_left</span>
            </button>
            <input
              type="number"
              :value="currentPage"
              @change="goToPage(parseInt($event.target.value))"
              min="1"
              :max="totalPages"
              class="w-16 px-2 py-1 bg-surface-700 border border-surface-600 rounded text-center text-white text-sm"
            />
            <button
              @click="nextPage"
              :disabled="currentPage >= totalPages"
              class="p-2 hover:bg-surface-700 rounded-lg transition-colors text-white disabled:opacity-30"
              title="Next page"
            >
              <span class="material-symbols-rounded">chevron_right</span>
            </button>
            
            <div class="w-px h-6 bg-surface-600 mx-2"></div>
          </template>
          
          <!-- Zoom controls -->
          <button
            @click="zoomOut"
            :disabled="zoom <= 0.5"
            class="p-2 hover:bg-surface-700 rounded-lg transition-colors text-white disabled:opacity-30"
            title="Zoom out"
          >
            <span class="material-symbols-rounded">zoom_out</span>
          </button>
          <button
            @click="zoomIn"
            :disabled="zoom >= 3"
            class="p-2 hover:bg-surface-700 rounded-lg transition-colors text-white disabled:opacity-30"
            title="Zoom in"
          >
            <span class="material-symbols-rounded">zoom_in</span>
          </button>
          
          <div class="w-px h-6 bg-surface-600 mx-2"></div>
          
          <button
            @click="downloadDocument"
            class="p-2 hover:bg-surface-700 rounded-lg transition-colors text-white"
            title="Download"
          >
            <span class="material-symbols-rounded">download</span>
          </button>
          
          <button
            @click="close"
            class="p-2 hover:bg-surface-700 rounded-lg transition-colors text-white"
            title="Close (Esc)"
          >
            <span class="material-symbols-rounded">close</span>
          </button>
        </div>
      </div>
      
      <!-- Other participant viewing different page indicator -->
      <div 
        v-if="isViewTogether && otherPosition && otherPosition.position?.page !== currentPage"
        class="absolute top-16 left-1/2 -translate-x-1/2 z-20"
      >
        <button
          @click="jumpToOther"
          class="flex items-center gap-2 px-4 py-2 bg-amber-500 text-white rounded-full text-sm hover:bg-amber-600 transition-colors"
        >
          <span class="w-6 h-6 bg-white text-amber-500 rounded-full flex items-center justify-center text-xs font-medium">
            {{ getInitials(otherPosition.user?.name) }}
          </span>
          <span>{{ otherPosition.user?.name }} is on page {{ otherPosition.position.page }}</span>
          <span class="material-symbols-rounded text-sm">arrow_forward</span>
        </button>
      </div>
      
      <!-- Document content -->
      <div 
        ref="scrollContainer"
        class="flex-1 overflow-auto relative"
        @scroll="handleScroll"
      >
        <!-- Document container for cursor tracking -->
        <div
          ref="documentContainer"
          class="relative min-h-full"
          @mousemove="handleMouseMove"
          @mouseleave="handleMouseLeave"
          @mouseup="handleMouseUp"
        >
          <!-- Loading state -->
          <div v-if="loading" class="flex items-center justify-center h-full min-h-[400px]">
            <span class="material-symbols-rounded text-4xl text-surface-400 animate-spin">progress_activity</span>
          </div>
          
          <!-- Error state -->
          <div v-else-if="error" class="flex flex-col items-center justify-center h-full min-h-[400px] text-surface-400">
            <span class="material-symbols-rounded text-4xl mb-2">error</span>
            <p>{{ error }}</p>
            <button
              @click="downloadDocument"
              class="mt-4 flex items-center gap-2 px-4 py-2 bg-primary-500 text-white rounded-lg hover:bg-primary-600 transition-colors"
            >
              <span class="material-symbols-rounded">download</span>
              <span>Download Instead</span>
            </button>
          </div>
          
          <!-- PDF viewer using iframe -->
          <div 
            v-else-if="isPdf"
            class="flex items-center justify-center min-h-full p-4"
            :style="{ transform: `scale(${zoom})`, transformOrigin: 'top center' }"
          >
            <iframe
              :src="`${url}#page=${currentPage}`"
              class="w-full h-[calc(100vh-120px)] bg-white rounded-lg"
              @load="handleIframeLoad"
              @error="handleIframeError"
            ></iframe>
          </div>
          
          <!-- DOCX viewer -->
          <div 
            v-else-if="isDocx && docxHtml"
            class="flex justify-center min-h-full p-4"
          >
            <div 
              class="bg-white rounded-lg shadow-xl p-8 max-w-4xl w-full docx-content"
              :style="{ transform: `scale(${zoom})`, transformOrigin: 'top center' }"
              v-html="docxHtml"
            ></div>
          </div>
          
          <!-- Text file viewer -->
          <div 
            v-else-if="isText && textContent"
            class="flex justify-center min-h-full p-4"
          >
            <pre 
              class="bg-surface-800 rounded-lg p-6 max-w-4xl w-full text-surface-200 text-sm overflow-x-auto"
              :style="{ transform: `scale(${zoom})`, transformOrigin: 'top center' }"
            >{{ textContent }}</pre>
          </div>
          
          <!-- Image viewer -->
          <div 
            v-else-if="isImage"
            class="flex items-center justify-center min-h-full p-4"
          >
            <img
              :src="url"
              :alt="filename"
              class="max-w-full max-h-[calc(100vh-120px)] object-contain rounded-lg"
              :style="{ transform: `scale(${zoom})` }"
              @load="loading = false"
              @error="error = 'Failed to load image'"
            />
          </div>
          
          <!-- Generic file - show download prompt -->
          <div v-else class="flex flex-col items-center justify-center h-full min-h-[400px] text-surface-400">
            <span class="material-symbols-rounded text-6xl mb-4">description</span>
            <p class="text-lg mb-2">{{ filename }}</p>
            <p class="text-sm mb-4">This file type cannot be previewed</p>
            <button
              @click="downloadDocument"
              class="flex items-center gap-2 px-4 py-2 bg-primary-500 text-white rounded-lg hover:bg-primary-600 transition-colors"
            >
              <span class="material-symbols-rounded">download</span>
              <span>Download</span>
            </button>
          </div>
          
          <!-- Other participant's text selection highlight -->
          <template v-if="isViewTogether && otherOnSamePage && otherTextSelection?.rects">
            <div
              v-for="(rect, i) in otherTextSelection.rects"
              :key="'sel-' + i"
              class="absolute pointer-events-none bg-amber-400/40 z-40"
              :style="{
                left: (rect.x * 100) + '%',
                top: (rect.y * 100) + '%',
                width: (rect.width * 100) + '%',
                height: (rect.height * 100) + '%'
              }"
            ></div>
          </template>
          
          <!-- Other participant's cursor -->
          <div 
            v-if="isViewTogether && otherOnSamePage && displayCursorPosition"
            class="absolute pointer-events-none transition-all duration-75 z-50"
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
        </div>
      </div>
      
      <!-- Same page indicator -->
      <div 
        v-if="isViewTogether && otherPosition && otherPosition.position?.page === currentPage"
        class="absolute bottom-4 right-4 flex items-center gap-2 px-3 py-1.5 bg-green-500 text-white rounded-full text-sm"
      >
        <span class="w-5 h-5 bg-white text-green-500 rounded-full flex items-center justify-center text-xs font-medium">
          {{ getInitials(otherPosition?.user?.name) }}
        </span>
        <span>{{ otherPosition?.user?.name }} is here</span>
      </div>
    </div>
  </Teleport>
</template>

<style scoped>
/* Styles for DOCX content */
.docx-content :deep(h1) {
  font-size: 2rem;
  font-weight: bold;
  margin-bottom: 1rem;
  color: #1a1a1a;
}

.docx-content :deep(h2) {
  font-size: 1.5rem;
  font-weight: bold;
  margin-bottom: 0.75rem;
  color: #1a1a1a;
}

.docx-content :deep(h3) {
  font-size: 1.25rem;
  font-weight: bold;
  margin-bottom: 0.5rem;
  color: #1a1a1a;
}

.docx-content :deep(p) {
  margin-bottom: 0.75rem;
  line-height: 1.6;
  color: #333;
}

.docx-content :deep(ul),
.docx-content :deep(ol) {
  margin-left: 1.5rem;
  margin-bottom: 0.75rem;
}

.docx-content :deep(li) {
  margin-bottom: 0.25rem;
}

.docx-content :deep(table) {
  width: 100%;
  border-collapse: collapse;
  margin-bottom: 1rem;
}

.docx-content :deep(th),
.docx-content :deep(td) {
  border: 1px solid #ddd;
  padding: 0.5rem;
  text-align: left;
}

.docx-content :deep(th) {
  background: #f5f5f5;
  font-weight: bold;
}

.docx-content :deep(img) {
  max-width: 100%;
  height: auto;
}

.docx-content :deep(a) {
  color: #6366f1;
  text-decoration: underline;
}

.docx-content :deep(strong),
.docx-content :deep(b) {
  font-weight: bold;
}

.docx-content :deep(em),
.docx-content :deep(i) {
  font-style: italic;
}
</style>
