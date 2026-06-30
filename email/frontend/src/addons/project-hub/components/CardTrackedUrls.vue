<script setup>
import { ref, onMounted } from 'vue'
import api from '@/services/api'
import { useToastStore } from '@/stores/toast'

const props = defineProps({
  cardId: { type: Number, required: true }
})

const toast = useToastStore()
const urls = ref([])
const loading = ref(false)
const showForm = ref(false)
const form = ref({ url_domain: '', display_name: '', title_match: '' })

async function loadUrls() {
  loading.value = true
  try {
    const { data } = await api.get(`/project-hub/cards/${props.cardId}/tracked-urls`)
    urls.value = data.data || []
  } catch {
    toast.error('Failed to load tracked URLs')
  } finally {
    loading.value = false
  }
}

async function addUrl() {
  if (!form.value.url_domain.trim()) return
  try {
    const { data } = await api.post(`/project-hub/cards/${props.cardId}/tracked-urls`, form.value)
    if (data.success && data.data) {
      urls.value.unshift(data.data)
      form.value = { url_domain: '', display_name: '', title_match: '' }
      showForm.value = false
    }
  } catch (err) {
    const msg = err?.response?.data?.message || 'Failed to add URL'
    toast.error(msg)
  }
}

async function toggleUrl(url) {
  const newState = !Number(url.is_active)
  try {
    await api.put(`/project-hub/card-tracked-urls/${url.id}/toggle`, { is_active: newState })
    url.is_active = newState ? 1 : 0
  } catch {
    toast.error('Failed to toggle URL')
  }
}

async function removeUrl(url) {
  try {
    await api.delete(`/project-hub/card-tracked-urls/${url.id}`)
    urls.value = urls.value.filter(u => u.id !== url.id)
  } catch {
    toast.error('Failed to remove URL')
  }
}

onMounted(loadUrls)
</script>

<template>
  <div class="bg-white dark:bg-surface-800 rounded-xl p-4 shadow-sm border border-surface-200/60 dark:border-surface-700/60 space-y-3">
    <div class="flex items-center justify-between">
      <h3 class="text-sm font-semibold text-surface-700 dark:text-surface-200 flex items-center gap-2">
        <span class="material-symbols-rounded text-lg">language</span>
        Tracked Links
        <span v-if="urls.length" class="text-xs text-surface-400 font-normal">({{ urls.length }})</span>
      </h3>
      <button
        @click="showForm = !showForm"
        class="text-xs px-3 py-1.5 rounded-full bg-primary-50 dark:bg-primary-500/10 text-primary-600 dark:text-primary-400 hover:bg-primary-100 dark:hover:bg-primary-500/20 transition-colors flex items-center gap-1"
      >
        <span class="material-symbols-rounded text-sm">{{ showForm ? 'close' : 'add' }}</span>
        {{ showForm ? 'Cancel' : 'Add link' }}
      </button>
    </div>

    <!-- Add form -->
    <div v-if="showForm" class="border border-surface-200 dark:border-surface-700 rounded-lg p-3 space-y-2">
      <input
        v-model="form.url_domain"
        type="text"
        placeholder="Domain (e.g. staging.client.com)"
        class="w-full text-sm px-3 py-2 rounded-lg border border-surface-200 dark:border-surface-600 bg-surface-50 dark:bg-surface-700 text-surface-700 dark:text-surface-200 focus:outline-none focus:ring-2 focus:ring-primary-500/30"
        @keydown.enter="addUrl"
      />
      <input
        v-model="form.display_name"
        type="text"
        placeholder="Display name (optional)"
        class="w-full text-sm px-3 py-2 rounded-lg border border-surface-200 dark:border-surface-600 bg-surface-50 dark:bg-surface-700 text-surface-700 dark:text-surface-200 focus:outline-none focus:ring-2 focus:ring-primary-500/30"
      />
      <input
        v-model="form.title_match"
        type="text"
        placeholder="Title keywords, comma-separated (optional)"
        class="w-full text-sm px-3 py-2 rounded-lg border border-surface-200 dark:border-surface-600 bg-surface-50 dark:bg-surface-700 text-surface-700 dark:text-surface-200 focus:outline-none focus:ring-2 focus:ring-primary-500/30"
      />
      <button
        @click="addUrl"
        :disabled="!form.url_domain.trim()"
        class="text-xs px-4 py-2 rounded-full bg-primary-500 text-white hover:bg-primary-600 disabled:opacity-40 disabled:cursor-not-allowed transition-colors"
      >
        Add tracked link
      </button>
    </div>

    <!-- Loading -->
    <div v-if="loading" class="text-center py-6">
      <span class="material-symbols-rounded text-2xl text-surface-300 animate-spin">progress_activity</span>
    </div>

    <!-- Empty state -->
    <div v-else-if="urls.length === 0 && !showForm" class="text-center py-8">
      <span class="material-symbols-rounded text-4xl text-surface-300 dark:text-surface-600">link_off</span>
      <p class="text-sm text-surface-400 mt-2">No tracked links yet</p>
      <p class="text-xs text-surface-400 mt-1">Add website domains to track time spent working on them</p>
    </div>

    <!-- URL list -->
    <div v-else class="space-y-1.5">
      <div
        v-for="url in urls"
        :key="url.id"
        class="flex items-center gap-3 px-3 py-2.5 rounded-lg border border-surface-100 dark:border-surface-700 hover:bg-surface-50 dark:hover:bg-surface-700/50 transition-colors group"
      >
        <span class="material-symbols-rounded text-lg" :class="url.is_active ? 'text-primary-500' : 'text-surface-300'">language</span>
        <div class="flex-1 min-w-0">
          <p class="text-sm font-medium text-surface-700 dark:text-surface-200 truncate">
            {{ url.display_name || url.url_domain }}
          </p>
          <p v-if="url.display_name" class="text-[10px] text-surface-400 truncate">{{ url.url_domain }}</p>
          <p v-if="url.title_match" class="text-[10px] text-surface-400 truncate">
            <span class="material-symbols-rounded text-[10px] align-middle">filter_alt</span>
            {{ url.title_match }}
          </p>
        </div>

        <!-- Toggle -->
        <button
          @click="toggleUrl(url)"
          class="relative w-9 h-5 rounded-full transition-colors shrink-0"
          :class="Number(url.is_active) ? 'bg-primary-500' : 'bg-surface-300 dark:bg-surface-600'"
        >
          <span
            class="absolute top-0.5 left-0.5 w-4 h-4 rounded-full bg-white shadow transition-transform"
            :class="Number(url.is_active) ? 'translate-x-4' : 'translate-x-0'"
          />
        </button>

        <!-- Delete -->
        <button
          @click="removeUrl(url)"
          class="p-1 rounded text-surface-400 hover:text-red-500 opacity-0 group-hover:opacity-100 transition-all shrink-0"
        >
          <span class="material-symbols-rounded text-sm">delete</span>
        </button>
      </div>
    </div>
  </div>
</template>
