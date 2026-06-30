<script setup>
import { ref, watch, onMounted } from 'vue'
import { useChatStore } from '@/addons/chat/stores/chat'

const props = defineProps({
  url: {
    type: String,
    required: true
  }
})

const chatStore = useChatStore()
const preview = ref(null)
const loading = ref(true)
const error = ref(false)
const imageError = ref(false)

onMounted(async () => {
  await fetchPreview()
})

watch(() => props.url, async () => {
  await fetchPreview()
})

async function fetchPreview() {
  loading.value = true
  error.value = false
  imageError.value = false
  try {
    const result = await chatStore.fetchLinkPreview(props.url)
    if (result) {
      preview.value = result
    } else {
      error.value = true
    }
  } catch (e) {
    error.value = true
  } finally {
    loading.value = false
  }
}

function getDomain(url) {
  try {
    return new URL(url).hostname.replace('www.', '')
  } catch {
    return ''
  }
}
</script>

<template>
  <!-- Loading skeleton -->
  <div v-if="loading" class="mt-2 max-w-md animate-pulse">
    <div class="flex gap-3 p-3 bg-surface-50 dark:bg-surface-800 rounded-xl border border-surface-200 dark:border-surface-700">
      <div class="w-16 h-16 bg-surface-200 dark:bg-surface-700 rounded-lg flex-shrink-0"></div>
      <div class="flex-1 space-y-2">
        <div class="h-3 bg-surface-200 dark:bg-surface-700 rounded w-3/4"></div>
        <div class="h-2.5 bg-surface-200 dark:bg-surface-700 rounded w-full"></div>
        <div class="h-2.5 bg-surface-200 dark:bg-surface-700 rounded w-2/3"></div>
      </div>
    </div>
  </div>

  <!-- Preview card -->
  <a
    v-else-if="preview && !error"
    :href="url"
    target="_blank"
    rel="noopener noreferrer"
    class="mt-2 max-w-md block group"
  >
    <div class="bg-surface-50 dark:bg-surface-800 rounded-xl border border-surface-200 dark:border-surface-700 overflow-hidden hover:border-primary-300 dark:hover:border-primary-500/50 transition-colors">
      <!-- Large image (if available and no error) -->
      <div v-if="preview.image && !imageError" class="relative">
        <img
          :src="preview.image"
          :alt="preview.title || ''"
          class="w-full h-40 object-cover"
          @error="imageError = true"
          loading="lazy"
        />
      </div>

      <!-- Text content -->
      <div class="p-3">
        <!-- Site name / domain -->
        <div class="flex items-center gap-1.5 mb-1">
          <img
            v-if="preview.favicon"
            :src="preview.favicon"
            class="w-4 h-4 rounded-sm"
            @error="$event.target.style.display = 'none'"
            loading="lazy"
          />
          <span class="text-xs text-surface-500 truncate">
            {{ preview.site_name || getDomain(url) }}
          </span>
        </div>

        <!-- Title -->
        <h4 v-if="preview.title" class="text-sm font-semibold text-surface-900 dark:text-surface-100 line-clamp-2 group-hover:text-primary-600 dark:group-hover:text-primary-400 transition-colors">
          {{ preview.title }}
        </h4>

        <!-- Description -->
        <p v-if="preview.description" class="text-xs text-surface-500 mt-1 line-clamp-2">
          {{ preview.description }}
        </p>
      </div>
    </div>
  </a>

  <!-- No preview / error: just show nothing (the URL is already in the message) -->
</template>

