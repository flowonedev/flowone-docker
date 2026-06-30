<script setup>
import { ref, onMounted, computed } from 'vue'
import api from '@/services/api'

const props = defineProps({
  id: { type: Number, required: true }
})

const data = ref(null)
const loading = ref(true)
const error = ref(false)

// Cache resolved embeds in memory to avoid re-fetching
const cache = new Map()

onMounted(async () => {
  const cacheKey = `drive_file:${props.id}`
  if (cache.has(cacheKey)) {
    const cached = cache.get(cacheKey)
    if (cached === null) { error.value = true } else { data.value = cached }
    loading.value = false
    return
  }
  
  try {
    const res = await api.get('/chat/embed/resolve', { params: { type: 'drive_file', embed_id: props.id } })
    if (res.data.success) {
      data.value = res.data.data
      cache.set(cacheKey, data.value)
    } else {
      error.value = true
      cache.set(cacheKey, null)
    }
  } catch {
    error.value = true
    cache.set(cacheKey, null)
  }
  loading.value = false
})

const fileIcon = computed(() => {
  if (!data.value) return 'attach_file'
  const mime = data.value.mime_type || ''
  if (mime.startsWith('image/')) return 'image'
  if (mime.startsWith('video/')) return 'movie'
  if (mime.startsWith('audio/')) return 'audio_file'
  if (mime.includes('pdf')) return 'picture_as_pdf'
  if (mime.includes('word') || mime.includes('document')) return 'description'
  if (mime.includes('sheet') || mime.includes('excel')) return 'table_chart'
  if (mime.includes('zip') || mime.includes('rar') || mime.includes('7z')) return 'folder_zip'
  if (mime.includes('presentation') || mime.includes('powerpoint')) return 'slideshow'
  return 'draft'
})

function formatSize(bytes) {
  if (!bytes) return ''
  if (bytes < 1024) return bytes + ' B'
  if (bytes < 1048576) return (bytes / 1024).toFixed(1) + ' KB'
  if (bytes < 1073741824) return (bytes / 1048576).toFixed(1) + ' MB'
  return (bytes / 1073741824).toFixed(1) + ' GB'
}

function formatDate(dateStr) {
  if (!dateStr) return ''
  const d = new Date(dateStr)
  return d.toLocaleDateString(undefined, { month: 'short', day: 'numeric' })
}

function handleClick() {
  if (!data.value) return
  
  if (data.value.is_own) {
    // Own file — navigate to its folder in Drive
    const folderId = data.value.folder_id || null
    window.dispatchEvent(new CustomEvent('navigate-to', { detail: { view: 'drive', folderId } }))
  } else if (data.value.share_token) {
    // Shared file — download via share link
    window.open(`/api/drive/share/${data.value.share_token}`, '_blank')
  } else {
    // Fallback — navigate to drive
    window.dispatchEvent(new CustomEvent('navigate-to', { detail: { view: 'drive' } }))
  }
}
</script>

<template>
  <div 
    class="embed-card group cursor-pointer rounded-xl border border-surface-200 dark:border-surface-700 bg-white dark:bg-surface-800 overflow-hidden hover:border-blue-300 dark:hover:border-blue-600 transition-colors w-64"
    @click="handleClick"
  >
    <!-- Loading state -->
    <div v-if="loading" class="flex items-center gap-3 p-3">
      <div class="w-10 h-10 rounded-lg bg-surface-100 dark:bg-surface-700 animate-pulse"></div>
      <div class="flex-1 space-y-1.5">
        <div class="h-3.5 bg-surface-100 dark:bg-surface-700 rounded animate-pulse w-3/4"></div>
        <div class="h-2.5 bg-surface-100 dark:bg-surface-700 rounded animate-pulse w-1/2"></div>
      </div>
    </div>
    
    <!-- Error state -->
    <div v-else-if="error || !data" class="flex items-center gap-3 p-3 text-surface-400">
      <span class="material-symbols-rounded text-xl">error_outline</span>
      <span class="text-xs">File not available</span>
    </div>
    
    <!-- Content -->
    <div v-else class="flex items-center gap-3 p-3">
      <div class="w-10 h-10 rounded-lg bg-blue-50 dark:bg-blue-900/30 flex items-center justify-center flex-shrink-0">
        <span class="material-symbols-rounded text-blue-500 dark:text-blue-400 text-xl">{{ fileIcon }}</span>
      </div>
      <div class="flex-1 min-w-0">
        <p class="text-sm font-medium text-surface-900 dark:text-surface-100 truncate">{{ data.name }}</p>
        <div class="flex items-center gap-1.5 text-xs text-surface-400 mt-0.5">
          <span v-if="data.size">{{ formatSize(data.size) }}</span>
          <span v-if="data.size && data.updated_at" class="text-surface-300 dark:text-surface-600">|</span>
          <span v-if="data.updated_at">{{ formatDate(data.updated_at) }}</span>
        </div>
        <p v-if="data.folder_path" class="text-xs text-surface-400 truncate mt-0.5">
          <span class="material-symbols-rounded text-[10px] align-middle mr-0.5">folder</span>
          {{ data.folder_path }}
        </p>
      </div>
      <span class="material-symbols-rounded text-surface-300 group-hover:text-blue-500 text-lg transition-colors flex-shrink-0">{{ data.is_own ? 'open_in_new' : 'download' }}</span>
    </div>
  </div>
</template>

