<script setup>
import { ref, onMounted, computed } from 'vue'
import api from '@/services/api'

const props = defineProps({
  id: { type: Number, required: true }
})

const data = ref(null)
const loading = ref(true)
const error = ref(false)

const cache = new Map()

onMounted(async () => {
  const cacheKey = `board_card:${props.id}`
  if (cache.has(cacheKey)) {
    const cached = cache.get(cacheKey)
    if (cached === null) { error.value = true } else { data.value = cached }
    loading.value = false
    return
  }
  
  try {
    const res = await api.get('/chat/embed/resolve', { params: { type: 'board_card', embed_id: props.id } })
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

const dueDateInfo = computed(() => {
  if (!data.value?.due_date) return null
  const due = new Date(data.value.due_date)
  const now = new Date()
  const diffMs = due.getTime() - now.getTime()
  const diffDays = Math.ceil(diffMs / (1000 * 60 * 60 * 24))
  
  let color = 'text-surface-400'
  if (data.value.completed) {
    color = 'text-green-500'
  } else if (diffDays < 0) {
    color = 'text-red-500'
  } else if (diffDays <= 1) {
    color = 'text-orange-500'
  } else if (diffDays <= 3) {
    color = 'text-yellow-600 dark:text-yellow-400'
  }
  
  const dateStr = due.toLocaleDateString(undefined, { month: 'short', day: 'numeric' })
  return { dateStr, color }
})

function handleClick() {
  if (!data.value) return
  window.dispatchEvent(new CustomEvent('navigate-to', { 
    detail: { view: 'boards', boardId: data.value.board_id, cardId: props.id } 
  }))
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
      <span class="text-xs">Card not available</span>
    </div>
    
    <!-- Content -->
    <div v-else>
      <!-- Cover color strip -->
      <div 
        v-if="data.cover_color" 
        class="h-2 w-full" 
        :style="{ backgroundColor: data.cover_color }"
      ></div>
      <div class="p-3">
        <div class="flex items-start gap-3">
          <div class="w-10 h-10 rounded-lg bg-purple-50 dark:bg-purple-900/30 flex items-center justify-center flex-shrink-0">
            <span 
              class="material-symbols-rounded text-xl"
              :class="data.completed ? 'text-green-500' : 'text-purple-500 dark:text-purple-400'"
            >{{ data.completed ? 'check_circle' : 'credit_card' }}</span>
          </div>
          <div class="flex-1 min-w-0">
            <p 
              class="text-sm font-medium text-surface-900 dark:text-surface-100 truncate"
              :class="data.completed && 'line-through opacity-60'"
            >{{ data.title }}</p>
            <p class="text-xs text-surface-400 mt-0.5 truncate">
              {{ data.board_name }} <span class="text-surface-300 dark:text-surface-600 mx-0.5">></span> {{ data.list_name }}
            </p>
          </div>
        </div>
        
        <!-- Labels & due date -->
        <div class="flex items-center gap-1.5 mt-2 ml-[52px] flex-wrap">
          <span 
            v-for="label in (data.labels || []).slice(0, 3)" 
            :key="label.name"
            class="inline-block px-1.5 py-0.5 rounded text-[10px] font-medium text-white leading-none"
            :style="{ backgroundColor: label.color }"
          >{{ label.name || '' }}</span>
          <span v-if="(data.labels || []).length > 3" class="text-[10px] text-surface-400">+{{ data.labels.length - 3 }}</span>
          
          <span v-if="dueDateInfo" class="flex items-center gap-0.5 text-[11px] ml-auto" :class="dueDateInfo.color">
            <span class="material-symbols-rounded text-[12px]">schedule</span>
            {{ dueDateInfo.dateStr }}
          </span>
        </div>
      </div>
    </div>
  </div>
</template>

