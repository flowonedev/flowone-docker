<script setup>
import { ref, computed, onMounted } from 'vue'
import { useToastStore } from '@/stores/toast'
import api from '@/services/api'
import ConfirmModal from '@/components/ConfirmModal.vue'

const props = defineProps({
  boardId: {
    type: Number,
    required: true
  }
})

const toast = useToastStore()

// State
const urls = ref([])
const loading = ref(false)
const showAddModal = ref(false)
const editingUrl = ref(null)
const urlToDelete = ref(null)
const showDeleteConfirm = ref(false)

// Form state
const form = ref({
  url_domain: '',
  display_name: '',
  title_match: ''
})

const formErrors = ref({})

// Computed
const isEditing = computed(() => !!editingUrl.value)

// Load tracked URLs
async function loadUrls() {
  loading.value = true
  try {
    const response = await api.get(`/boards/${props.boardId}/tracked-urls`)
    if (response.data.success) {
      urls.value = response.data.data.urls || []
    }
  } catch (error) {
    console.error('Failed to load tracked URLs:', error)
    toast.error('Failed to load tracked URLs')
  } finally {
    loading.value = false
  }
}

// Open add modal
function openAddModal() {
  editingUrl.value = null
  form.value = {
    url_domain: '',
    display_name: '',
    title_match: ''
  }
  formErrors.value = {}
  showAddModal.value = true
}

// Open edit modal
function openEditModal(url) {
  editingUrl.value = url
  form.value = {
    url_domain: url.url_domain,
    display_name: url.display_name || '',
    title_match: url.title_match || ''
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
  
  // Basic domain validation
  const domainPattern = /^[a-zA-Z0-9][a-zA-Z0-9-_.]+\.[a-zA-Z]{2,}$/
  if (!domainPattern.test(form.value.url_domain.trim())) {
    formErrors.value.url_domain = 'Invalid domain format (e.g., example.com)'
    return false
  }
  
  return true
}

// Save URL (add or update)
async function saveUrl() {
  if (!validateForm()) {
    return
  }
  
  loading.value = true
  try {
    if (isEditing.value) {
      // Update existing URL
      const response = await api.put(`/boards/${props.boardId}/tracked-urls/${editingUrl.value.id}`, {
        url_domain: form.value.url_domain.trim(),
        display_name: form.value.display_name.trim(),
        title_match: form.value.title_match.trim()
      })
      
      if (response.data.success) {
        toast.success('Tracked URL updated')
        showAddModal.value = false
        loadUrls()
      } else {
        toast.error(response.data.message || 'Failed to update tracked URL')
      }
    } else {
      // Add new URL
      const response = await api.post(`/boards/${props.boardId}/tracked-urls`, {
        url_domain: form.value.url_domain.trim(),
        display_name: form.value.display_name.trim(),
        title_match: form.value.title_match.trim()
      })
      
      if (response.data.success) {
        toast.success('Tracked URL added')
        showAddModal.value = false
        loadUrls()
      } else {
        toast.error(response.data.message || 'Failed to add tracked URL')
      }
    }
  } catch (error) {
    console.error('Failed to save tracked URL:', error)
    const errorMessage = error.response?.data?.message || 'Failed to save tracked URL'
    toast.error(errorMessage)
  } finally {
    loading.value = false
  }
}

// Confirm delete
function confirmDelete(url) {
  urlToDelete.value = url
  showDeleteConfirm.value = true
}

// Delete URL
async function deleteUrl() {
  if (!urlToDelete.value) return
  
  loading.value = true
  try {
    const response = await api.delete(`/boards/${props.boardId}/tracked-urls/${urlToDelete.value.id}`)
    
    if (response.data.success) {
      toast.success('Tracked URL deleted')
      showDeleteConfirm.value = false
      urlToDelete.value = null
      loadUrls()
    } else {
      toast.error(response.data.message || 'Failed to delete tracked URL')
    }
  } catch (error) {
    console.error('Failed to delete tracked URL:', error)
    const errorMessage = error.response?.data?.message || 'Failed to delete tracked URL'
    toast.error(errorMessage)
  } finally {
    loading.value = false
  }
}

onMounted(() => {
  loadUrls()
})
</script>

<template>
  <div class="p-6">
    <!-- Header -->
    <div class="flex items-center justify-between mb-6">
      <div>
        <h3 class="text-lg font-semibold text-surface-900 dark:text-surface-100">Tracked Websites</h3>
        <p class="text-sm text-surface-500 mt-1">
          Track time spent on specific websites for this client
        </p>
      </div>
      <button
        @click="openAddModal"
        class="btn btn-primary flex items-center gap-2"
      >
        <span class="material-symbols-rounded text-lg">add</span>
        Add Website
      </button>
    </div>
    
    <!-- Loading state -->
    <div v-if="loading && urls.length === 0" class="text-center py-12">
      <span class="material-symbols-rounded text-4xl text-surface-400 animate-spin">sync</span>
    </div>
    
    <!-- Empty state -->
    <div v-else-if="urls.length === 0" class="text-center py-12">
      <span class="material-symbols-rounded text-6xl text-surface-400 mb-4">language</span>
      <p class="text-surface-600 dark:text-surface-400 mb-2">No tracked websites yet</p>
      <p class="text-sm text-surface-500 mb-4">
        Add website domains to track time spent on client websites
      </p>
      <button @click="openAddModal" class="btn btn-primary">
        Add Your First Website
      </button>
    </div>
    
    <!-- URLs list -->
    <div v-else class="space-y-3">
      <div
        v-for="url in urls"
        :key="url.id"
        class="bg-surface-50 dark:bg-surface-800 rounded-lg border border-surface-200 dark:border-surface-700 p-4 hover:border-primary-300 dark:hover:border-primary-600 transition-colors"
      >
        <div class="flex items-start justify-between">
          <div class="flex-1 min-w-0">
            <div class="flex items-center gap-2 mb-1">
              <span class="material-symbols-rounded text-lg text-primary-500">language</span>
              <h4 class="font-medium text-surface-900 dark:text-surface-100">
                {{ url.display_name || url.url_domain }}
              </h4>
              <span
                v-if="!url.is_active"
                class="px-2 py-0.5 text-xs rounded-full bg-surface-200 dark:bg-surface-700 text-surface-600 dark:text-surface-400"
              >
                Inactive
              </span>
            </div>
            <p class="text-sm text-surface-600 dark:text-surface-400">
              {{ url.url_domain }}
            </p>
            <p v-if="url.title_match" class="text-xs text-primary-600 dark:text-primary-400 mt-1 flex items-center gap-1">
              <span class="material-symbols-rounded text-sm">search</span>
              Match: {{ url.title_match }}
            </p>
            <p class="text-xs text-surface-500 mt-1">
              Added {{ new Date(url.created_at).toLocaleDateString() }}
            </p>
          </div>
          
          <div class="flex items-center gap-2">
            <button
              @click="openEditModal(url)"
              class="p-2 rounded-lg hover:bg-surface-200 dark:hover:bg-surface-700 text-surface-600 dark:text-surface-400 hover:text-primary-600 dark:hover:text-primary-400 transition-colors"
              title="Edit"
            >
              <span class="material-symbols-rounded text-lg">edit</span>
            </button>
            <button
              @click="confirmDelete(url)"
              class="p-2 rounded-lg hover:bg-red-100 dark:hover:bg-red-900/20 text-surface-600 dark:text-surface-400 hover:text-red-600 dark:hover:text-red-400 transition-colors"
              title="Delete"
            >
              <span class="material-symbols-rounded text-lg">delete</span>
            </button>
          </div>
        </div>
      </div>
    </div>
    
    <!-- Add/Edit Modal -->
    <div
      v-if="showAddModal"
      class="fixed inset-0 bg-black/50 flex items-center justify-center z-50"
      @mousedown.self="showAddModal = false"
    >
      <div class="bg-white dark:bg-surface-800 rounded-lg shadow-xl w-full max-w-md mx-4">
        <div class="px-6 py-4 border-b border-surface-200 dark:border-surface-700">
          <h3 class="text-lg font-semibold text-surface-900 dark:text-surface-100">
            {{ isEditing ? 'Edit' : 'Add' }} Tracked Website
          </h3>
        </div>
        
        <div class="p-6 space-y-4">
          <!-- Domain input -->
          <div>
            <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-1">
              Domain <span class="text-red-500">*</span>
            </label>
            <input
              v-model="form.url_domain"
              type="text"
              placeholder="example.com"
              class="input w-full"
              :class="{ 'border-red-500': formErrors.url_domain }"
            />
            <p v-if="formErrors.url_domain" class="text-xs text-red-500 mt-1">
              {{ formErrors.url_domain }}
            </p>
            <p class="text-xs text-surface-500 mt-1">
              Enter the domain without http:// or https:// (e.g., example.com)
            </p>
          </div>
          
          <!-- Display name input -->
          <div>
            <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-1">
              Display Name (Optional)
            </label>
            <input
              v-model="form.display_name"
              type="text"
              placeholder="Client Website"
              class="input w-full"
            />
            <p class="text-xs text-surface-500 mt-1">
              Friendly name to identify this website
            </p>
          </div>
          
          <!-- Title Match input -->
          <div>
            <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-1">
              Title Match Keywords (Optional)
            </label>
            <input
              v-model="form.title_match"
              type="text"
              placeholder="Romania, Romanian, Catherine"
              class="input w-full"
            />
            <p class="text-xs text-surface-500 mt-1">
              Comma-separated keywords to match in browser title. Useful when multiple similar domains exist (e.g., mercedes-benz.hu vs mercedes-benz.ro - add "Romania" to identify .ro)
            </p>
          </div>
        </div>
        
        <div class="px-6 py-4 border-t border-surface-200 dark:border-surface-700 flex justify-end gap-2">
          <button
            @click="showAddModal = false"
            class="btn btn-secondary"
          >
            Cancel
          </button>
          <button
            @click="saveUrl"
            :disabled="loading"
            class="btn btn-primary"
          >
            {{ isEditing ? 'Update' : 'Add' }}
          </button>
        </div>
      </div>
    </div>
    
    <!-- Delete Confirmation Modal -->
    <ConfirmModal
      v-if="showDeleteConfirm"
      title="Delete Tracked Website"
      :message="`Are you sure you want to delete ${urlToDelete?.display_name || urlToDelete?.url_domain}? This action cannot be undone.`"
      confirm-text="Delete"
      confirm-style="danger"
      @confirm="deleteUrl"
      @cancel="showDeleteConfirm = false; urlToDelete = null"
    />
  </div>
</template>

