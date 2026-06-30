<script setup>
import { ref, onMounted } from 'vue'
import api from '@/services/api'

const props = defineProps({
  id: { type: Number, required: true }
})

const data = ref(null)
const loading = ref(true)
const error = ref(false)

const cache = new Map()

onMounted(async () => {
  const cacheKey = `drive_folder:${props.id}`
  if (cache.has(cacheKey)) {
    const cached = cache.get(cacheKey)
    if (cached === null) { error.value = true } else { data.value = cached }
    loading.value = false
    return
  }
  
  try {
    const res = await api.get('/chat/embed/resolve', { params: { type: 'drive_folder', embed_id: props.id } })
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

function formatSize(bytes) {
  if (!bytes) return '0 B'
  if (bytes < 1024) return bytes + ' B'
  if (bytes < 1048576) return (bytes / 1024).toFixed(1) + ' KB'
  if (bytes < 1073741824) return (bytes / 1048576).toFixed(1) + ' MB'
  return (bytes / 1073741824).toFixed(1) + ' GB'
}

function handleClick() {
  const ownerEmail = data.value?.owner_email || null
  window.dispatchEvent(new CustomEvent('navigate-to', { detail: { view: 'drive', folderId: props.id, ownerEmail } }))
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
      <span class="text-xs">Folder not available</span>
    </div>
    
    <!-- Content -->
    <div v-else class="flex items-center gap-3 p-3">
      <div 
        class="w-10 h-10 rounded-lg flex items-center justify-center flex-shrink-0"
        :style="data.color ? { backgroundColor: data.color + '20' } : {}"
        :class="!data.color && 'bg-blue-50 dark:bg-blue-900/30'"
      >
        <span 
          class="material-symbols-rounded text-xl"
          :style="data.color ? { color: data.color } : {}"
          :class="!data.color && 'text-blue-500 dark:text-blue-400'"
        >folder</span>
      </div>
      <div class="flex-1 min-w-0">
        <p class="text-sm font-medium text-surface-900 dark:text-surface-100 truncate">{{ data.name }}</p>
        <div class="flex items-center gap-1.5 text-xs text-surface-400 mt-0.5">
          <span>{{ data.file_count }} files</span>
          <span v-if="data.subfolder_count > 0">, {{ data.subfolder_count }} folders</span>
          <span class="text-surface-300 dark:text-surface-600">|</span>
          <span>{{ formatSize(data.total_size) }}</span>
        </div>
      </div>
      <span class="material-symbols-rounded text-surface-300 group-hover:text-blue-500 text-lg transition-colors flex-shrink-0">open_in_new</span>
    </div>
  </div>
</template>

