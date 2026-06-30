<script setup>
import { ref, onMounted, watch } from 'vue'

const emit = defineEmits(['select', 'close'])

const searchQuery = ref('')
const gifs = ref([])
const isLoading = ref(false)
const error = ref(null)
const searchInputRef = ref(null)

// Tenor API key (free tier)
const TENOR_API_KEY = 'AIzaSyAyimkuYQYF_FXVALexPuGQctUWRURdCYQ'
const TENOR_CLIENT_KEY = 'mailflow_chat'

async function fetchGifs(query = '') {
  isLoading.value = true
  error.value = null
  
  try {
    const endpoint = query 
      ? 'https://tenor.googleapis.com/v2/search'
      : 'https://tenor.googleapis.com/v2/featured'
    
    const params = new URLSearchParams({
      key: TENOR_API_KEY,
      client_key: TENOR_CLIENT_KEY,
      limit: 30,
      media_filter: 'gif,tinygif',
      contentfilter: 'medium',
    })
    
    if (query) {
      params.append('q', query)
    }
    
    const response = await fetch(`${endpoint}?${params}`)
    const data = await response.json()
    
    if (data.results) {
      gifs.value = data.results.map(gif => ({
        id: gif.id,
        url: gif.media_formats.gif?.url || gif.media_formats.tinygif?.url,
        preview: gif.media_formats.tinygif?.url || gif.media_formats.nanogif?.url,
        width: gif.media_formats.gif?.dims?.[0] || 200,
        height: gif.media_formats.gif?.dims?.[1] || 200,
        title: gif.content_description || gif.title || ''
      }))
    }
  } catch (e) {
    console.error('Failed to fetch GIFs:', e)
    error.value = 'Failed to load GIFs. Please try again.'
    gifs.value = []
  }
  
  isLoading.value = false
}

function handleSearch() {
  fetchGifs(searchQuery.value)
}

function selectGif(gif) {
  emit('select', gif)
}

// Debounce search
let searchTimeout = null
watch(searchQuery, (newVal) => {
  if (searchTimeout) clearTimeout(searchTimeout)
  searchTimeout = setTimeout(() => {
    fetchGifs(newVal)
  }, 300)
})

onMounted(() => {
  fetchGifs()
  searchInputRef.value?.focus()
})
</script>

<template>
  <!-- Backdrop -->
  <div class="fixed inset-0 z-40" @click="emit('close')"></div>
  
  <!-- Picker Panel -->
  <div 
    class="absolute bottom-full mb-2 left-0 w-80 max-h-[420px] bg-white dark:bg-surface-800 rounded-xl shadow-2xl border border-surface-200 dark:border-surface-700 z-50 flex flex-col overflow-hidden"
  >
    <!-- Header with Search -->
    <div class="p-3 border-b border-surface-200 dark:border-surface-700">
      <div class="relative">
        <span class="absolute left-3 top-1/2 -translate-y-1/2 material-symbols-rounded text-surface-400 text-lg">search</span>
        <input
          ref="searchInputRef"
          v-model="searchQuery"
          type="text"
          placeholder="Search GIFs..."
          class="w-full pl-9 pr-4 py-2 bg-surface-100 dark:bg-surface-900 border border-surface-200 dark:border-surface-700 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-primary-500 text-surface-900 dark:text-surface-100"
          @keydown.enter="handleSearch"
        />
      </div>
    </div>
    
    <!-- GIF Grid -->
    <div class="flex-1 overflow-y-auto p-2">
      <!-- Loading -->
      <div v-if="isLoading" class="flex items-center justify-center py-8">
        <span class="material-symbols-rounded text-2xl text-surface-400 animate-spin">progress_activity</span>
      </div>
      
      <!-- Error -->
      <div v-else-if="error" class="flex flex-col items-center justify-center py-8 text-center">
        <span class="material-symbols-rounded text-3xl text-surface-400 mb-2">error</span>
        <p class="text-sm text-surface-500">{{ error }}</p>
        <button 
          @click="fetchGifs(searchQuery)"
          class="mt-2 text-primary-500 text-sm hover:underline"
        >
          Try again
        </button>
      </div>
      
      <!-- No results -->
      <div v-else-if="gifs.length === 0" class="flex flex-col items-center justify-center py-8 text-center">
        <span class="material-symbols-rounded text-3xl text-surface-400 mb-2">gif_box</span>
        <p class="text-sm text-surface-500">No GIFs found</p>
      </div>
      
      <!-- GIF Grid - Masonry-like layout -->
      <div v-else class="grid grid-cols-2 gap-1.5">
        <button
          v-for="gif in gifs"
          :key="gif.id"
          @click="selectGif(gif)"
          class="relative rounded-lg overflow-hidden bg-surface-200 dark:bg-surface-700 hover:ring-2 hover:ring-primary-500 transition-all group"
          :style="{ aspectRatio: gif.width / gif.height > 1.5 ? '16/9' : gif.width / gif.height < 0.67 ? '9/16' : '1/1' }"
        >
          <img
            :src="gif.preview"
            :alt="gif.title"
            class="w-full h-full object-cover"
            loading="lazy"
          />
          <!-- Hover overlay -->
          <div class="absolute inset-0 bg-black/0 group-hover:bg-black/10 transition-colors"></div>
        </button>
      </div>
    </div>
    
    <!-- Footer - Powered by Tenor -->
    <div class="px-3 py-2 border-t border-surface-200 dark:border-surface-700 flex items-center justify-center gap-1 text-xs text-surface-400">
      <span>Powered by</span>
      <svg class="h-3" viewBox="0 0 90 22" fill="currentColor">
        <path d="M0 4.5h10.8v2.4H6.6v12.6H4.2V6.9H0V4.5zm12.3 0h8.4v2.4h-6v4.2h5.4v2.4h-5.4v4.2h6v2.4h-8.4V4.5zm11.4 0h3.6l4.2 10.8 4.2-10.8h3.6v15H37v-10.8l-4.2 10.8h-2.4L26.1 8.7v10.8h-2.4V4.5zm19.2 0h2.4v12.6h5.4v2.4h-7.8V4.5zm10.8 7.5c0-4.5 3.3-7.8 7.8-7.8 4.5 0 7.8 3.3 7.8 7.8s-3.3 7.8-7.8 7.8c-4.5 0-7.8-3.3-7.8-7.8zm13.2 0c0-3.3-2.4-5.4-5.4-5.4-3 0-5.4 2.1-5.4 5.4 0 3.3 2.4 5.4 5.4 5.4 3 0 5.4-2.1 5.4-5.4zm6.6-7.5h5.4c3.3 0 5.4 2.1 5.4 4.8 0 2.4-1.5 4.2-3.9 4.8l4.5 5.4h-3l-4.2-5.1h-1.8v5.1h-2.4V4.5zm5.1 7.5c2.1 0 3.3-1.2 3.3-2.7 0-1.5-1.2-2.4-3.3-2.4h-2.7v5.1h2.7z"/>
      </svg>
    </div>
  </div>
</template>


