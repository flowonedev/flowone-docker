<script setup>
import { ref, computed, watch, onMounted } from 'vue'
import { useToastStore } from '@/stores/toast'
import { useAddons } from '@/composables/useAddons'
import api from '@/services/api'

const props = defineProps({
  clientId: {
    type: Number,
    required: true
  },
  linkedBoards: {
    type: Array,
    default: () => []
  }
})

const toast = useToastStore()
const { kanbanBoardsEnabled } = useAddons()

// State
const urls = ref([])
const loading = ref(false)
const showAddModal = ref(false)
const form = ref({ url_domain: '', display_name: '', board_id: null })
const formErrors = ref({})

// Load tracked URLs for this client from all boards
async function loadUrls() {
  if (!kanbanBoardsEnabled.value) return
  loading.value = true
  try {
    // Get all URL mappings and filter by client_id
    const response = await api.get('/boards/url-mappings')
    if (response.data.success) {
      const allMappings = response.data.data?.mappings || []
      // Filter URLs for this client
      urls.value = allMappings.filter(m => m.client_id === props.clientId)
    }
  } catch (error) {
    console.error('Failed to load tracked URLs:', error)
  } finally {
    loading.value = false
  }
}

// Open add modal
function openAddModal() {
  form.value = {
    url_domain: '',
    display_name: '',
    board_id: props.linkedBoards[0]?.board_id || null
  }
  formErrors.value = {}
  showAddModal.value = true
}

// Validate form
function validateForm() {
  formErrors.value = {}
  
  if (!form.value.url_domain.trim()) {
    formErrors.value.url_domain = 'Domain is required'
    return false
  }
  
  const domainPattern = /^[a-zA-Z0-9][a-zA-Z0-9-_.]+\.[a-zA-Z]{2,}$/
  if (!domainPattern.test(form.value.url_domain.trim())) {
    formErrors.value.url_domain = 'Invalid domain format'
    return false
  }
  
  if (!form.value.board_id) {
    formErrors.value.board_id = 'Please select a board'
    return false
  }
  
  return true
}

// Add URL
async function addUrl() {
  if (!validateForm()) return
  
  loading.value = true
  try {
    const response = await api.post(`/boards/${form.value.board_id}/tracked-urls`, {
      url_domain: form.value.url_domain.trim(),
      display_name: form.value.display_name.trim()
    })
    
    if (response.data.success) {
      toast.success('Website added for tracking')
      showAddModal.value = false
      loadUrls()
    } else {
      toast.error(response.data.message || 'Failed to add website')
    }
  } catch (error) {
    toast.error(error.response?.data?.message || 'Failed to add website')
  } finally {
    loading.value = false
  }
}

// Delete URL
async function deleteUrl(url) {
  if (!confirm(`Remove ${url.domain} from tracking?`)) return
  
  loading.value = true
  try {
    const response = await api.delete(`/boards/${url.board_id}/tracked-urls/${url.id}`)
    if (response.data.success) {
      toast.success('Website removed')
      loadUrls()
    }
  } catch (error) {
    toast.error('Failed to remove website')
  } finally {
    loading.value = false
  }
}

watch(() => props.clientId, () => {
  if (props.clientId) loadUrls()
}, { immediate: true })
</script>

<template>
  <div class="bg-white dark:bg-surface-800 rounded-xl border border-surface-200 dark:border-surface-700 p-4">
    <div class="flex items-center justify-between mb-3">
      <h4 class="text-sm font-semibold text-surface-900 dark:text-surface-100 flex items-center gap-2">
        <span class="material-symbols-rounded text-lg text-cyan-500">language</span>
        Tracked Websites
      </h4>
      <button
        v-if="linkedBoards.length > 0"
        @click="openAddModal"
        class="p-1 rounded-lg hover:bg-surface-100 dark:hover:bg-surface-700 text-surface-500 hover:text-primary-500 transition-colors"
        title="Add website to track"
      >
        <span class="material-symbols-rounded text-lg">add</span>
      </button>
    </div>
    
    <!-- Loading -->
    <div v-if="loading && urls.length === 0" class="py-4 text-center">
      <span class="material-symbols-rounded text-2xl text-surface-400 animate-spin">sync</span>
    </div>
    
    <!-- Empty state -->
    <div v-else-if="urls.length === 0" class="text-center py-4">
      <span class="material-symbols-rounded text-3xl text-surface-300 dark:text-surface-600">language</span>
      <p class="text-xs text-surface-500 mt-1">No websites tracked</p>
      <button
        v-if="linkedBoards.length > 0"
        @click="openAddModal"
        class="mt-2 text-xs text-primary-500 hover:text-primary-600"
      >
        Add a website
      </button>
      <p v-else class="text-xs text-surface-400 mt-1">Link a board first</p>
    </div>
    
    <!-- URLs list -->
    <div v-else class="space-y-2">
      <div
        v-for="url in urls"
        :key="url.domain"
        class="flex items-center gap-2 p-2 rounded-lg bg-surface-50 dark:bg-surface-900 group"
      >
        <span class="material-symbols-rounded text-base text-cyan-500">language</span>
        <div class="flex-1 min-w-0">
          <p class="text-sm font-medium text-surface-900 dark:text-surface-100 truncate">
            {{ url.display_name || url.domain }}
          </p>
          <p v-if="url.display_name" class="text-xs text-surface-500 truncate">{{ url.domain }}</p>
        </div>
        <button
          @click="deleteUrl(url)"
          class="p-1 rounded-lg text-surface-400 hover:text-red-500 hover:bg-red-50 dark:hover:bg-red-500/10 opacity-0 group-hover:opacity-100 transition-all"
          title="Remove"
        >
          <span class="material-symbols-rounded text-base">close</span>
        </button>
      </div>
    </div>
    
    <!-- Add Modal -->
    <div
      v-if="showAddModal"
      class="fixed inset-0 bg-black/50 flex items-center justify-center z-50"
      @mousedown.self="showAddModal = false"
    >
      <div class="bg-white dark:bg-surface-800 rounded-xl shadow-xl w-full max-w-sm p-5 mx-4" @mousedown.stop>
        <h3 class="text-base font-semibold text-surface-900 dark:text-surface-100 mb-4">
          Add Tracked Website
        </h3>
        
        <div class="space-y-4">
          <div>
            <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-1">
              Domain
            </label>
            <input
              v-model="form.url_domain"
              type="text"
              placeholder="example.com"
              class="w-full px-3 py-2 rounded-lg border border-surface-300 dark:border-surface-600 bg-white dark:bg-surface-900 text-surface-900 dark:text-surface-100 focus:ring-2 focus:ring-primary-500 focus:border-primary-500"
              :class="{ 'border-red-500': formErrors.url_domain }"
            />
            <p v-if="formErrors.url_domain" class="text-xs text-red-500 mt-1">{{ formErrors.url_domain }}</p>
          </div>
          
          <div>
            <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-1">
              Display Name (optional)
            </label>
            <input
              v-model="form.display_name"
              type="text"
              placeholder="My Website"
              class="w-full px-3 py-2 rounded-lg border border-surface-300 dark:border-surface-600 bg-white dark:bg-surface-900 text-surface-900 dark:text-surface-100 focus:ring-2 focus:ring-primary-500 focus:border-primary-500"
            />
          </div>
          
          <div v-if="linkedBoards.length > 1">
            <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-1">
              Add to Board
            </label>
            <select
              v-model="form.board_id"
              class="w-full px-3 py-2 rounded-lg border border-surface-300 dark:border-surface-600 bg-white dark:bg-surface-900 text-surface-900 dark:text-surface-100 focus:ring-2 focus:ring-primary-500"
            >
              <option v-for="board in linkedBoards" :key="board.board_id" :value="board.board_id">
                {{ board.board_name }}
              </option>
            </select>
          </div>
        </div>
        
        <div class="flex justify-end gap-3 mt-6">
          <button
            @click="showAddModal = false"
            class="px-4 py-2 text-sm font-medium text-surface-700 dark:text-surface-300 hover:bg-surface-100 dark:hover:bg-surface-700 rounded-lg transition-colors"
          >
            Cancel
          </button>
          <button
            @click="addUrl"
            :disabled="loading"
            class="px-4 py-2 text-sm font-medium text-white bg-primary-500 hover:bg-primary-600 rounded-lg transition-colors disabled:opacity-50"
          >
            {{ loading ? 'Adding...' : 'Add Website' }}
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

