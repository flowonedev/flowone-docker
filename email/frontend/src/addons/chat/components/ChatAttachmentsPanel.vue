<script setup>
import { ref, computed, watch, onMounted } from 'vue'
import { getToken } from '@/services/tokenStorage'
import { getApiOrigin } from '@/services/serverRegistry'
import { useChatStore } from '@/addons/chat/stores/chat'
import { useToastStore } from '@/stores/toast'
import ChatImageGallery from './ChatImageGallery.vue'

const props = defineProps({
  show: Boolean,
  conversationId: [Number, String]
})

const emit = defineEmits(['close'])

const chatStore = useChatStore()
const toast = useToastStore()

// Tabs
const activeTab = ref('media')
const tabs = [
  { id: 'media', label: 'Media', icon: 'photo_library' },
  { id: 'links', label: 'Links', icon: 'link' },
  { id: 'docs', label: 'Documents', icon: 'description' }
]

// Search
const searchQuery = ref('')

// Data
const loading = ref(false)
const attachments = ref([])
const links = ref([])
const savingToDrive = ref(false)

// Gallery
const showGallery = ref(false)
const galleryStartIndex = ref(0)

// Filter by search
function matchesSearch(item, query) {
  if (!query) return true
  const q = query.toLowerCase()
  const name = (item.original_name || item.filename || item.domain || item.url || '').toLowerCase()
  const sender = (item.sender_name || '').toLowerCase()
  return name.includes(q) || sender.includes(q)
}

// Group items by month
function groupByMonth(items) {
  const groups = {}
  
  for (const item of items) {
    const date = new Date(item.sent_at || item.uploaded_at || item.created_at)
    const monthKey = `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}`
    const monthLabel = date.toLocaleDateString([], { year: 'numeric', month: 'long' })
    
    if (!groups[monthKey]) {
      groups[monthKey] = { label: monthLabel, items: [] }
    }
    groups[monthKey].items.push(item)
  }
  
  // Sort by month (newest first)
  return Object.entries(groups)
    .sort(([a], [b]) => b.localeCompare(a))
    .map(([key, value]) => ({ key, ...value }))
}

// Computed filtered and grouped data
const mediaAttachments = computed(() => {
  return attachments.value.filter(a => 
    (a.category === 'image' || a.category === 'video' || 
    a.type?.startsWith('image/') || a.type?.startsWith('video/')) &&
    matchesSearch(a, searchQuery.value)
  )
})

const mediaGrouped = computed(() => groupByMonth(mediaAttachments.value))

const docAttachments = computed(() => {
  return attachments.value.filter(a => 
    (a.category === 'document' || a.category === 'archive' ||
    a.type?.includes('pdf') || a.type?.includes('word') || 
    a.type?.includes('sheet') || a.type?.includes('excel') ||
    a.type?.includes('presentation') || a.type?.includes('powerpoint') ||
    a.type?.includes('zip') || a.type?.includes('rar')) &&
    matchesSearch(a, searchQuery.value)
  )
})

const docsGrouped = computed(() => groupByMonth(docAttachments.value))

const filteredLinks = computed(() => {
  return links.value.filter(l => matchesSearch(l, searchQuery.value))
})

const linksGrouped = computed(() => groupByMonth(filteredLinks.value))

// Highlight search matches
function highlightText(text, query) {
  if (!query || !text) return text
  const regex = new RegExp(`(${query.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')})`, 'gi')
  return text.replace(regex, '<mark class="bg-yellow-300 dark:bg-yellow-600 text-inherit rounded px-0.5">$1</mark>')
}

// Load data
async function loadData() {
  if (!props.conversationId) return
  
  loading.value = true
  
  // Load attachments
  const result = await chatStore.getConversationAttachments(props.conversationId)
  if (result.success) {
    attachments.value = result.attachments || []
  }
  
  // Extract links from messages
  extractLinks()
  
  loading.value = false
}

function extractLinks() {
  const messages = chatStore.messages[props.conversationId] || []
  const urlRegex = /https?:\/\/[^\s<>"{}|\\^`\[\]]+/g
  const foundLinks = []
  const seenUrls = new Set()
  
  for (const msg of messages) {
    const content = msg.content || ''
    const matches = content.match(urlRegex) || []
    
    for (const url of matches) {
      if (seenUrls.has(url)) continue
      seenUrls.add(url)
      
      // Try to get domain and title
      let domain = ''
      try {
        domain = new URL(url).hostname.replace('www.', '')
      } catch (e) {}
      
      // Detect link type
      let type = 'link'
      let icon = 'link'
      if (url.includes('youtube.com') || url.includes('youtu.be')) {
        type = 'youtube'
        icon = 'smart_display'
      } else if (url.includes('github.com')) {
        type = 'github'
        icon = 'code'
      } else if (url.includes('twitter.com') || url.includes('x.com')) {
        type = 'twitter'
        icon = 'tag'
      } else if (url.includes('linkedin.com')) {
        type = 'linkedin'
        icon = 'work'
      } else if (url.match(/\.(jpg|jpeg|png|gif|webp)$/i)) {
        type = 'image'
        icon = 'image'
      } else if (url.match(/\.(pdf|doc|docx|xls|xlsx|ppt|pptx)$/i)) {
        type = 'document'
        icon = 'description'
      }
      
      foundLinks.push({
        url,
        domain,
        type,
        icon,
        sender_name: msg.sender_name,
        sent_at: msg.created_at
      })
    }
  }
  
  links.value = foundLinks.reverse() // Most recent first
}

// Actions
function buildAuthUrl(conversationId, filename) {
  const url = getApiOrigin() + '/api/chat/attachments/' + conversationId + '/' + encodeURIComponent(filename)
  const token = getToken('webmail_token')
  return token ? url + '?token=' + encodeURIComponent(token) : url
}

function getAttachmentUrl(att) {
  const path = att.path || att.url || ''
  const match = path.match(/\/?chat_attachments\/(\d+)\/(.+)$/)
  if (match) {
    const [, conversationId, filename] = match
    return buildAuthUrl(conversationId, filename)
  }
  console.warn('Unexpected attachment path format:', path, att)
  return ''
}

function getThumbnailUrl(att) {
  if (att.thumbnail) {
    const match = att.thumbnail.match(/\/?chat_attachments\/(\d+)\/(.+)$/)
    if (match) {
      const [, conversationId, filename] = match
      return buildAuthUrl(conversationId, filename)
    }
  }
  return getAttachmentUrl(att)
}

function openGallery(groupIndex, itemIndex) {
  // Calculate flat index
  let flatIndex = 0
  for (let g = 0; g < groupIndex; g++) {
    flatIndex += mediaGrouped.value[g].items.length
  }
  flatIndex += itemIndex
  galleryStartIndex.value = flatIndex
  showGallery.value = true
}

function downloadAttachment(att) {
  const link = document.createElement('a')
  link.href = getAttachmentUrl(att)
  link.download = att.original_name || att.filename || 'file'
  document.body.appendChild(link)
  link.click()
  document.body.removeChild(link)
}

function openLink(url) {
  window.open(url, '_blank', 'noopener,noreferrer')
}

function copyLink(url) {
  navigator.clipboard.writeText(url)
  toast.success('Link copied!')
}

async function saveAllToDrive() {
  savingToDrive.value = true
  const result = await chatStore.saveAttachmentsToDrive(props.conversationId)
  
  if (result.success) {
    toast.success(`Saved ${result.savedCount} files to "${result.folderPath}"`)
  } else {
    toast.error(result.error || 'Failed to save to Drive')
  }
  savingToDrive.value = false
}

function getFileIcon(mimeType) {
  if (!mimeType) return 'attach_file'
  if (mimeType.includes('pdf')) return 'picture_as_pdf'
  if (mimeType.includes('word') || mimeType.includes('document')) return 'description'
  if (mimeType.includes('sheet') || mimeType.includes('excel')) return 'table_chart'
  if (mimeType.includes('presentation') || mimeType.includes('powerpoint')) return 'slideshow'
  if (mimeType.includes('zip') || mimeType.includes('rar') || mimeType.includes('7z')) return 'folder_zip'
  return 'attach_file'
}

function formatFileSize(bytes) {
  if (!bytes) return ''
  if (bytes < 1024) return bytes + ' B'
  if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB'
  return (bytes / (1024 * 1024)).toFixed(1) + ' MB'
}

function formatDate(dateString) {
  if (!dateString) return ''
  return new Date(dateString).toLocaleDateString([], { month: 'short', day: 'numeric' })
}

// Clear search when changing tabs
watch(activeTab, () => {
  searchQuery.value = ''
})

// Load on show
watch(() => props.show, (val) => {
  if (val) loadData()
})

onMounted(() => {
  if (props.show) loadData()
})
</script>

<template>
  <Teleport to="body">
    <Transition name="slide">
      <div 
        v-if="show"
        class="fixed inset-y-0 right-0 w-96 max-w-full bg-white dark:bg-surface-900 shadow-2xl z-[9999] flex flex-col"
      >
        <!-- Header -->
        <div class="flex items-center justify-between px-4 py-3 border-b border-surface-200 dark:border-surface-700">
          <h2 class="font-semibold text-surface-900 dark:text-surface-100">Chat Files</h2>
          <button
            @click="emit('close')"
            class="p-2 hover:bg-surface-100 dark:hover:bg-surface-800 rounded-lg transition-colors"
          >
            <span class="material-symbols-rounded">close</span>
          </button>
        </div>
        
        <!-- Search -->
        <div class="px-4 py-2 border-b border-surface-200 dark:border-surface-700">
          <div class="relative">
            <span class="material-symbols-rounded absolute left-3 top-1/2 -translate-y-1/2 text-surface-400 text-lg">
              search
            </span>
            <input
              v-model="searchQuery"
              type="text"
              placeholder="Search files..."
              class="w-full pl-10 pr-4 py-2 bg-surface-100 dark:bg-surface-800 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-primary-500"
            />
            <button
              v-if="searchQuery"
              @click="searchQuery = ''"
              class="absolute right-2 top-1/2 -translate-y-1/2 p-1 hover:bg-surface-200 dark:hover:bg-surface-700 rounded transition-colors"
            >
              <span class="material-symbols-rounded text-surface-400 text-sm">close</span>
            </button>
          </div>
        </div>
        
        <!-- Tabs -->
        <div class="flex border-b border-surface-200 dark:border-surface-700">
          <button
            v-for="tab in tabs"
            :key="tab.id"
            @click="activeTab = tab.id"
            :class="[
              'flex-1 flex items-center justify-center gap-2 px-4 py-3 text-sm font-medium transition-colors',
              activeTab === tab.id
                ? 'text-primary-600 dark:text-primary-400 border-b-2 border-primary-500'
                : 'text-surface-500 hover:text-surface-700 dark:hover:text-surface-300'
            ]"
          >
            <span class="material-symbols-rounded text-lg">{{ tab.icon }}</span>
            <span>{{ tab.label }}</span>
            <span 
              v-if="tab.id === 'media' && mediaAttachments.length"
              class="px-1.5 py-0.5 text-xs bg-surface-100 dark:bg-surface-800 rounded-full"
            >
              {{ mediaAttachments.length }}
            </span>
            <span 
              v-else-if="tab.id === 'links' && filteredLinks.length"
              class="px-1.5 py-0.5 text-xs bg-surface-100 dark:bg-surface-800 rounded-full"
            >
              {{ filteredLinks.length }}
            </span>
            <span 
              v-else-if="tab.id === 'docs' && docAttachments.length"
              class="px-1.5 py-0.5 text-xs bg-surface-100 dark:bg-surface-800 rounded-full"
            >
              {{ docAttachments.length }}
            </span>
          </button>
        </div>
        
        <!-- Content -->
        <div class="flex-1 overflow-y-auto">
          <!-- Loading -->
          <div v-if="loading" class="flex items-center justify-center py-12">
            <span class="material-symbols-rounded text-2xl text-surface-400 animate-spin">progress_activity</span>
          </div>
          
          <!-- Media Tab -->
          <div v-else-if="activeTab === 'media'" class="p-4">
            <div v-if="mediaAttachments.length === 0" class="text-center py-12 text-surface-400">
              <span class="material-symbols-rounded text-4xl mb-2 block">photo_library</span>
              <p>{{ searchQuery ? 'No matching media' : 'No media shared yet' }}</p>
            </div>
            <div v-else class="space-y-6">
              <div v-for="(group, groupIndex) in mediaGrouped" :key="group.key">
                <!-- Month Header -->
                <div class="sticky top-0 bg-white dark:bg-surface-900 py-2 -mx-4 px-4 border-b border-surface-100 dark:border-surface-800 z-10">
                  <span class="text-xs font-semibold text-surface-500 uppercase tracking-wider">
                    {{ group.label }}
                  </span>
                </div>
                
                <!-- Grid -->
                <div class="grid grid-cols-3 gap-2">
                  <div
                    v-for="(att, i) in group.items"
                    :key="att.id || i"
                    class="aspect-square rounded-lg overflow-hidden cursor-pointer group relative bg-surface-100 dark:bg-surface-800"
                    @click="openGallery(groupIndex, i)"
                  >
                    <img
                      :src="getThumbnailUrl(att)"
                      :alt="att.original_name"
                      class="w-full h-full object-cover transition-transform group-hover:scale-110"
                      @error="$event.target.classList.add('hidden')"
                    />
                    <!-- Placeholder on error -->
                    <div class="absolute inset-0 flex items-center justify-center">
                      <span class="material-symbols-rounded text-2xl text-surface-400">image</span>
                    </div>
                    <div class="absolute inset-0 bg-black/0 group-hover:bg-black/30 transition-colors flex items-center justify-center z-10">
                      <span class="material-symbols-rounded text-white text-2xl opacity-0 group-hover:opacity-100 transition-opacity">
                        zoom_in
                      </span>
                    </div>
                    <!-- Video indicator -->
                    <div 
                      v-if="att.type?.startsWith('video/')"
                      class="absolute bottom-1 right-1 bg-black/70 rounded px-1.5 py-0.5 z-10"
                    >
                      <span class="material-symbols-rounded text-white text-sm">play_arrow</span>
                    </div>
                    <!-- Name highlight on search -->
                    <div 
                      v-if="searchQuery && att.original_name"
                      class="absolute bottom-0 left-0 right-0 bg-gradient-to-t from-black/70 to-transparent p-2 z-10"
                    >
                      <p class="text-xs text-white truncate" v-html="highlightText(att.original_name, searchQuery)"></p>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </div>
          
          <!-- Links Tab -->
          <div v-else-if="activeTab === 'links'" class="p-4">
            <div v-if="filteredLinks.length === 0" class="text-center py-12 text-surface-400">
              <span class="material-symbols-rounded text-4xl mb-2 block">link</span>
              <p>{{ searchQuery ? 'No matching links' : 'No links shared yet' }}</p>
            </div>
            <div v-else class="space-y-6">
              <div v-for="group in linksGrouped" :key="group.key">
                <!-- Month Header -->
                <div class="sticky top-0 bg-white dark:bg-surface-900 py-2 -mx-4 px-4 border-b border-surface-100 dark:border-surface-800 z-10">
                  <span class="text-xs font-semibold text-surface-500 uppercase tracking-wider">
                    {{ group.label }}
                  </span>
                </div>
                
                <div class="space-y-2">
                  <div
                    v-for="(link, i) in group.items"
                    :key="i"
                    class="flex items-start gap-3 p-3 bg-surface-50 dark:bg-surface-800 rounded-lg hover:bg-surface-100 dark:hover:bg-surface-700 transition-colors group"
                  >
                    <div class="w-10 h-10 rounded-lg bg-surface-200 dark:bg-surface-700 flex items-center justify-center flex-shrink-0">
                      <span class="material-symbols-rounded text-surface-500">{{ link.icon }}</span>
                    </div>
                    <div class="flex-1 min-w-0">
                      <p 
                        class="text-sm font-medium text-surface-700 dark:text-surface-300 truncate"
                        v-html="highlightText(link.domain, searchQuery)"
                      ></p>
                      <p 
                        class="text-xs text-surface-400 truncate"
                        v-html="highlightText(link.url, searchQuery)"
                      ></p>
                      <p class="text-xs text-surface-400 mt-1">
                        <span v-html="highlightText(link.sender_name, searchQuery)"></span> · {{ formatDate(link.sent_at) }}
                      </p>
                    </div>
                    <div class="flex gap-1 opacity-0 group-hover:opacity-100 transition-opacity">
                      <button
                        @click.stop="openLink(link.url)"
                        class="p-1.5 hover:bg-surface-200 dark:hover:bg-surface-600 rounded transition-colors"
                        title="Open"
                      >
                        <span class="material-symbols-rounded text-sm">open_in_new</span>
                      </button>
                      <button
                        @click.stop="copyLink(link.url)"
                        class="p-1.5 hover:bg-surface-200 dark:hover:bg-surface-600 rounded transition-colors"
                        title="Copy"
                      >
                        <span class="material-symbols-rounded text-sm">content_copy</span>
                      </button>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </div>
          
          <!-- Docs Tab -->
          <div v-else-if="activeTab === 'docs'" class="p-4">
            <div v-if="docAttachments.length === 0" class="text-center py-12 text-surface-400">
              <span class="material-symbols-rounded text-4xl mb-2 block">description</span>
              <p>{{ searchQuery ? 'No matching documents' : 'No documents shared yet' }}</p>
            </div>
            <div v-else class="space-y-6">
              <div v-for="group in docsGrouped" :key="group.key">
                <!-- Month Header -->
                <div class="sticky top-0 bg-white dark:bg-surface-900 py-2 -mx-4 px-4 border-b border-surface-100 dark:border-surface-800 z-10">
                  <span class="text-xs font-semibold text-surface-500 uppercase tracking-wider">
                    {{ group.label }}
                  </span>
                </div>
                
                <div class="space-y-2">
                  <div
                    v-for="(doc, i) in group.items"
                    :key="doc.id || i"
                    @click="downloadAttachment(doc)"
                    class="flex items-center gap-3 p-3 bg-surface-50 dark:bg-surface-800 rounded-lg hover:bg-surface-100 dark:hover:bg-surface-700 transition-colors cursor-pointer group"
                  >
                    <div class="w-10 h-10 rounded-lg bg-surface-200 dark:bg-surface-700 flex items-center justify-center flex-shrink-0">
                      <span class="material-symbols-rounded text-surface-500">{{ getFileIcon(doc.type) }}</span>
                    </div>
                    <div class="flex-1 min-w-0">
                      <p 
                        class="text-sm font-medium text-surface-700 dark:text-surface-300 truncate"
                        v-html="highlightText(doc.original_name || doc.filename, searchQuery)"
                      ></p>
                      <p class="text-xs text-surface-400">
                        {{ formatFileSize(doc.size) }} · 
                        <span v-html="highlightText(doc.sender_name, searchQuery)"></span> · 
                        {{ formatDate(doc.sent_at) }}
                      </p>
                    </div>
                    <span class="material-symbols-rounded text-surface-400 opacity-0 group-hover:opacity-100 transition-opacity">
                      download
                    </span>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
        
        <!-- Footer Actions -->
        <div 
          v-if="attachments.length > 0"
          class="p-4 border-t border-surface-200 dark:border-surface-700"
        >
          <button
            @click="saveAllToDrive"
            :disabled="savingToDrive"
            class="w-full btn-secondary flex items-center justify-center gap-2"
          >
            <span :class="['material-symbols-rounded', savingToDrive && 'animate-spin']">
              {{ savingToDrive ? 'progress_activity' : 'cloud_upload' }}
            </span>
            <span>Save All to Drive</span>
          </button>
        </div>
        
        <!-- Image Gallery -->
        <ChatImageGallery
          v-if="showGallery"
          :images="mediaAttachments"
          :initial-index="galleryStartIndex"
          @close="showGallery = false"
        />
      </div>
    </Transition>
    
    <!-- Backdrop -->
    <Transition name="fade">
      <div 
        v-if="show"
        class="fixed inset-0 bg-black/30 z-[9998]"
        @click="emit('close')"
      ></div>
    </Transition>
  </Teleport>
</template>

<style scoped>
.slide-enter-active,
.slide-leave-active {
  transition: transform 0.3s ease;
}

.slide-enter-from,
.slide-leave-to {
  transform: translateX(100%);
}

.fade-enter-active,
.fade-leave-active {
  transition: opacity 0.3s ease;
}

.fade-enter-from,
.fade-leave-to {
  opacity: 0;
}
</style>
