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
  const cacheKey = `calendar_event:${props.id}`
  if (cache.has(cacheKey)) {
    const cached = cache.get(cacheKey)
    if (cached === null) { error.value = true } else { data.value = cached }
    loading.value = false
    return
  }
  
  try {
    const res = await api.get('/chat/embed/resolve', { params: { type: 'calendar_event', embed_id: props.id } })
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

const formattedDate = computed(() => {
  if (!data.value) return ''
  const start = new Date(data.value.start_time)
  const end = new Date(data.value.end_time)
  
  const dateStr = start.toLocaleDateString(undefined, { weekday: 'short', month: 'short', day: 'numeric' })
  
  if (data.value.all_day) {
    return dateStr + ' (All day)'
  }
  
  const startTime = start.toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit' })
  const endTime = end.toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit' })
  return `${dateStr}  ${startTime} - ${endTime}`
})

const isPast = computed(() => {
  if (!data.value) return false
  return new Date(data.value.end_time) < new Date()
})

function handleClick() {
  window.dispatchEvent(new CustomEvent('navigate-to', { detail: { view: 'calendar', eventId: props.id } }))
}

</script>

<template>
  <div 
    class="embed-card group cursor-pointer rounded-xl border border-surface-200 dark:border-surface-700 bg-white dark:bg-surface-800 overflow-hidden hover:border-green-300 dark:hover:border-green-600 transition-colors w-64"
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
      <span class="text-xs">Event not available</span>
    </div>
    
    <!-- Content -->
    <div v-else class="p-3">
      <div class="flex items-start gap-3">
        <div class="w-10 h-10 rounded-lg bg-green-50 dark:bg-green-900/30 flex items-center justify-center flex-shrink-0">
          <span class="material-symbols-rounded text-green-500 dark:text-green-400 text-xl">calendar_month</span>
        </div>
        <div class="flex-1 min-w-0">
          <p class="text-sm font-medium text-surface-900 dark:text-surface-100 truncate" :class="isPast && 'line-through opacity-60'">{{ data.title }}</p>
          <p class="text-xs text-surface-500 dark:text-surface-400 mt-0.5">{{ formattedDate }}</p>
        </div>
      </div>
      
      <!-- Location & calendar -->
      <div class="flex items-center gap-2 mt-2 ml-[52px]">
        <div v-if="data.location" class="flex items-center gap-1 text-xs text-surface-400 truncate">
          <span class="material-symbols-rounded text-[12px]">location_on</span>
          <span class="truncate">{{ data.location }}</span>
        </div>
        <div class="flex items-center gap-1 text-xs text-surface-400">
          <span 
            class="w-2 h-2 rounded-full flex-shrink-0" 
            :style="{ backgroundColor: data.calendar_color || '#3b82f6' }"
          ></span>
          <span class="truncate">{{ data.calendar_name }}</span>
        </div>
      </div>
    </div>
  </div>
</template>

