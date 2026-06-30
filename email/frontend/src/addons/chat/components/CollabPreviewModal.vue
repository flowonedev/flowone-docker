<script setup>
import { ref, computed, watch, onMounted, onUnmounted } from 'vue'
import { getToken } from '@/services/tokenStorage'
import { getApiOrigin } from '@/services/serverRegistry'
import { useChatStore } from '@/addons/chat/stores/chat'
import ChatImageGallery from './ChatImageGallery.vue'
import CollabDocumentViewer from './CollabDocumentViewer.vue'
import CollabSpreadsheetViewer from './CollabSpreadsheetViewer.vue'

const props = defineProps({
  attachment: {
    type: Object,
    required: true
  },
  allImages: {
    type: Array,
    default: () => []
  },
  initialIndex: {
    type: Number,
    default: 0
  },
  conversationId: {
    type: [Number, String],
    required: true
  }
})

const emit = defineEmits(['close'])

const chatStore = useChatStore()

// Determine file type
const fileType = computed(() => {
  const mime = props.attachment?.mime_type || props.attachment?.type || ''
  const filename = props.attachment?.filename || props.attachment?.original_name || ''
  const ext = filename.split('.').pop()?.toLowerCase()
  
  // Images
  if (mime.startsWith('image/') || ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'bmp'].includes(ext)) {
    return 'image'
  }
  
  // PDFs
  if (mime === 'application/pdf' || ext === 'pdf') {
    return 'document'
  }
  
  // Spreadsheets
  if (
    mime === 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' ||
    mime === 'application/vnd.ms-excel' ||
    mime === 'text/csv' ||
    ['xlsx', 'xls', 'csv', 'ods'].includes(ext)
  ) {
    return 'spreadsheet'
  }
  
  // Word documents - show as document
  if (
    mime === 'application/vnd.openxmlformats-officedocument.wordprocessingml.document' ||
    mime === 'application/msword' ||
    ['docx', 'doc', 'odt'].includes(ext)
  ) {
    return 'document'
  }
  
  // Text files
  if (mime.startsWith('text/') || ['txt', 'md', 'json', 'xml', 'html', 'css', 'js'].includes(ext)) {
    return 'document'
  }
  
  return 'unknown'
})

// Generate content ID for sync
const contentId = computed(() => {
  return `${props.conversationId}_${props.attachment?.id || props.attachment?.filename || 'file'}`
})

// Get file URL
const fileUrl = computed(() => {
  const path = props.attachment?.path || props.attachment?.url || ''
  const match = path.match(/\/?chat_attachments\/(\d+)\/(.+)$/)
  if (match) {
    const [, conversationId, filename] = match
    const url = getApiOrigin() + '/api/chat/attachments/' + conversationId + '/' + encodeURIComponent(filename)
    const token = getToken('webmail_token')
    return token ? url + '?token=' + encodeURIComponent(token) : url
  }
  return path
})

// Get filename
const filename = computed(() => {
  return props.attachment?.original_name || props.attachment?.filename || 'File'
})

// Get MIME type
const mimeType = computed(() => {
  return props.attachment?.mime_type || props.attachment?.type || 'application/octet-stream'
})

function close() {
  emit('close')
}

// Handle keyboard events
function handleKeydown(e) {
  if (e.key === 'Escape') {
    close()
  }
}

onMounted(() => {
  // If View Together is active and content type is pending, update it
  if (chatStore.viewSession?.contentType === 'pending') {
    chatStore.startViewSession(fileType.value, contentId.value)
  }
  
  document.addEventListener('keydown', handleKeydown)
})

onUnmounted(() => {
  document.removeEventListener('keydown', handleKeydown)
})
</script>

<template>
  <!-- Image Gallery -->
  <ChatImageGallery
    v-if="fileType === 'image' && allImages.length > 0"
    :images="allImages"
    :initial-index="initialIndex"
    :content-id="contentId"
    @close="close"
  />
  
  <!-- Document Viewer (PDF, etc.) -->
  <CollabDocumentViewer
    v-else-if="fileType === 'document'"
    :url="fileUrl"
    :filename="filename"
    :mime-type="mimeType"
    :content-id="contentId"
    @close="close"
  />
  
  <!-- Spreadsheet Viewer -->
  <CollabSpreadsheetViewer
    v-else-if="fileType === 'spreadsheet'"
    :url="fileUrl"
    :filename="filename"
    :content-id="contentId"
    @close="close"
  />
  
  <!-- Unknown file type - show download option -->
  <Teleport v-else to="body">
    <div class="fixed inset-0 z-[99990] flex items-center justify-center">
      <div class="absolute inset-0 bg-black/90" @click="close"></div>
      
      <div class="relative z-10 bg-surface-800 rounded-xl p-8 max-w-md mx-4 text-center">
        <span class="material-symbols-rounded text-6xl text-surface-400 mb-4">description</span>
        <h3 class="text-xl font-medium text-white mb-2">{{ filename }}</h3>
        <p class="text-surface-400 mb-6">This file type cannot be previewed in the browser.</p>
        
        <div class="flex gap-3 justify-center">
          <a
            :href="fileUrl"
            download
            class="flex items-center gap-2 px-4 py-2 bg-primary-500 text-white rounded-lg hover:bg-primary-600 transition-colors"
          >
            <span class="material-symbols-rounded">download</span>
            <span>Download</span>
          </a>
          <button
            @click="close"
            class="px-4 py-2 bg-surface-700 text-white rounded-lg hover:bg-surface-600 transition-colors"
          >
            Close
          </button>
        </div>
      </div>
    </div>
  </Teleport>
</template>

