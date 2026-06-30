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
  const cacheKey = `collab_doc:${props.id}`
  if (cache.has(cacheKey)) {
    const cached = cache.get(cacheKey)
    if (cached === null) { error.value = true } else { data.value = cached }
    loading.value = false
    return
  }
  
  try {
    const res = await api.get('/chat/embed/resolve', { params: { type: 'collab_doc', embed_id: props.id } })
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

const docIcon = computed(() => {
  if (!data.value) return 'description'
  return data.value.doc_type === 'presentation' ? 'slideshow' : 'description'
})

const iconColor = computed(() => {
  if (!data.value) return 'text-amber-500'
  return data.value.doc_type === 'presentation' ? 'text-orange-500' : 'text-amber-500'
})

const bgColor = computed(() => {
  if (!data.value) return 'bg-amber-50 dark:bg-amber-900/30'
  return data.value.doc_type === 'presentation' ? 'bg-orange-50 dark:bg-orange-900/30' : 'bg-amber-50 dark:bg-amber-900/30'
})

function formatDate(dateStr) {
  if (!dateStr) return ''
  const d = new Date(dateStr)
  return d.toLocaleDateString(undefined, { month: 'short', day: 'numeric' })
}

function handleClick() {
  if (!data.value?.uuid) return
  // Navigate to drive view and open the collab document
  window.dispatchEvent(new CustomEvent('navigate-to', { 
    detail: { view: 'drive', collabDocId: data.value.uuid } 
  }))
}
</script>

<template>
  <div 
    class="embed-card group cursor-pointer rounded-xl border border-surface-200 dark:border-surface-700 bg-white dark:bg-surface-800 overflow-hidden hover:border-amber-300 dark:hover:border-amber-600 transition-colors w-64"
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
      <span class="text-xs">Document not available</span>
    </div>
    
    <!-- Content -->
    <div v-else class="flex items-center gap-3 p-3">
      <div class="w-10 h-10 rounded-lg flex items-center justify-center flex-shrink-0" :class="bgColor">
        <span class="material-symbols-rounded text-xl" :class="iconColor">{{ docIcon }}</span>
      </div>
      <div class="flex-1 min-w-0">
        <p class="text-sm font-medium text-surface-900 dark:text-surface-100 truncate">{{ data.title || 'Untitled' }}</p>
        <div class="flex items-center gap-1.5 text-xs text-surface-400 mt-0.5">
          <span class="capitalize">{{ data.doc_type || 'document' }}</span>
          <span v-if="data.updated_at" class="text-surface-300 dark:text-surface-600">|</span>
          <span v-if="data.updated_at">{{ formatDate(data.updated_at) }}</span>
        </div>
        <p v-if="data.owner_email && !data.is_own" class="text-xs text-surface-400 truncate mt-0.5">
          <span class="material-symbols-rounded text-[10px] align-middle mr-0.5">person</span>
          {{ data.owner_email }}
        </p>
      </div>
      <span class="material-symbols-rounded text-surface-300 group-hover:text-amber-500 text-lg transition-colors flex-shrink-0">open_in_new</span>
    </div>
  </div>
</template>

