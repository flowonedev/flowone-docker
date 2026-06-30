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
  const cacheKey = `board:${props.id}`
  if (cache.has(cacheKey)) {
    const cached = cache.get(cacheKey)
    if (cached === null) { error.value = true } else { data.value = cached }
    loading.value = false
    return
  }
  
  try {
    const res = await api.get('/chat/embed/resolve', { params: { type: 'board', embed_id: props.id } })
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

function handleClick() {
  window.dispatchEvent(new CustomEvent('navigate-to', { detail: { view: 'boards', boardId: props.id } }))
}
</script>

<template>
  <div 
    class="embed-card group cursor-pointer rounded-xl border border-surface-200 dark:border-surface-700 bg-white dark:bg-surface-800 overflow-hidden hover:border-purple-300 dark:hover:border-purple-600 transition-colors w-64"
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
      <span class="text-xs">Board not available</span>
    </div>
    
    <!-- Content -->
    <div v-else>
      <!-- Colored header strip -->
      <div 
        class="h-2 w-full" 
        :style="{ backgroundColor: data.background_color || '#7c3aed' }"
      ></div>
      <div class="flex items-center gap-3 p-3">
        <div class="w-10 h-10 rounded-lg bg-purple-50 dark:bg-purple-900/30 flex items-center justify-center flex-shrink-0">
          <span class="material-symbols-rounded text-purple-500 dark:text-purple-400 text-xl">dashboard</span>
        </div>
        <div class="flex-1 min-w-0">
          <p class="text-sm font-medium text-surface-900 dark:text-surface-100 truncate">
            {{ data.name }}
            <span v-if="data.archived" class="text-xs text-surface-400 font-normal ml-1">(archived)</span>
          </p>
          <div class="flex items-center gap-1.5 text-xs text-surface-400 mt-0.5">
            <span>{{ data.list_count }} lists</span>
            <span class="text-surface-300 dark:text-surface-600">|</span>
            <span>{{ data.card_count }} cards</span>
            <span class="text-surface-300 dark:text-surface-600">|</span>
            <span class="flex items-center gap-0.5">
              <span class="material-symbols-rounded text-[12px]">group</span>
              {{ data.member_count }}
            </span>
          </div>
        </div>
        <span class="material-symbols-rounded text-surface-300 group-hover:text-purple-500 text-lg transition-colors flex-shrink-0">open_in_new</span>
      </div>
    </div>
  </div>
</template>

