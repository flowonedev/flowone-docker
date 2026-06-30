<script setup>
import { ref, computed, onMounted } from 'vue'
import { useBoardsStore } from '@/addons/kanban-boards/stores/boards'
import { useDriveStore } from '@/stores/drive'
import { useToastStore } from '@/stores/toast'
import { useAddons } from '@/composables/useAddons'
import ConfirmModal from '@/components/ConfirmModal.vue'
import api from '@/services/api'

const props = defineProps({
  boardId: {
    type: Number,
    required: true
  }
})

const emit = defineEmits(['close', 'deleted'])

const boardsStore = useBoardsStore()
const driveStore = useDriveStore()
const toast = useToastStore()
const { boardProEnabled } = useAddons()

// Board Pro settings
const proFeatureToggles = [
  { key: 'show_financials', icon: 'payments', label: 'Card Financials', desc: 'Revenue, cost, margin fields on cards' },
  { key: 'show_emails', icon: 'mail', label: 'Linked Emails', desc: 'Linked emails panel on card modals' },
  { key: 'show_timeline', icon: 'timeline', label: 'Activity Timeline', desc: 'Unified timeline on card modals' },
  { key: 'show_client_health', icon: 'health_and_safety', label: 'Client Health', desc: 'Client health indicator in sidebar' },
]

const proStorageKey = computed(() => `boardpro_settings_${props.boardId}`)
const proSettings = ref({
  show_financials: true,
  show_emails: true,
  show_timeline: true,
  show_client_health: true,
})

function loadProSettings() {
  try {
    const saved = localStorage.getItem(proStorageKey.value)
    if (saved) proSettings.value = { ...proSettings.value, ...JSON.parse(saved) }
  } catch {}
}

function toggleProFeature(key) {
  proSettings.value[key] = !proSettings.value[key]
  localStorage.setItem(proStorageKey.value, JSON.stringify(proSettings.value))
}

// State
const boardName = ref('')
const boardDescription = ref('')
const backgroundColor = ref('')
const backgroundImage = ref('')
const saving = ref(false)
const uploadingImage = ref(false)
const showDrivePicker = ref(false)
const showArchiveConfirm = ref(false)
const showDeleteConfirm = ref(false)

// Available colors
const colors = [
  '#1e1e26', '#0f766e', '#0369a1', '#7c3aed', '#be185d',
  '#b91c1c', '#c2410c', '#15803d', '#1d4ed8', '#6d28d9',
  '#059669', '#0891b2', '#4f46e5', '#c026d3', '#dc2626'
]

// Computed
const board = computed(() => boardsStore.currentBoard)
const isOwner = computed(() => board.value?.user_role === 'owner')
const hasChanges = computed(() => {
  if (!board.value) return false
  return (
    boardName.value !== board.value.name ||
    boardDescription.value !== (board.value.description || '') ||
    backgroundColor.value !== board.value.background_color ||
    backgroundImage.value !== (board.value.background_image || '')
  )
})

// Methods
function loadBoardData() {
  if (board.value) {
    boardName.value = board.value.name
    boardDescription.value = board.value.description || ''
    backgroundColor.value = board.value.background_color || '#1e1e26'
    backgroundImage.value = board.value.background_image || ''
  }
}

async function saveChanges() {
  if (!boardName.value.trim()) {
    toast.warning('Board name is required')
    return
  }
  
  saving.value = true
  
  const updated = await boardsStore.updateBoard(props.boardId, {
    name: boardName.value.trim(),
    description: boardDescription.value.trim() || null,
    background_color: backgroundColor.value,
    background_image: backgroundImage.value || null
  })
  
  if (updated) {
    toast.success('Board updated')
  } else {
    toast.error('Failed to update board')
  }
  
  saving.value = false
}

function triggerImageUpload() {
  document.getElementById('board-bg-upload')?.click()
}

async function handleImageUpload(e) {
  const file = e.target.files?.[0]
  if (!file) return
  
  // Validate image type
  if (!file.type.startsWith('image/')) {
    toast.error('Please select an image file')
    return
  }
  
  // Validate size (max 5MB)
  if (file.size > 5 * 1024 * 1024) {
    toast.error('Image must be less than 5MB')
    return
  }
  
  uploadingImage.value = true
  
  try {
    // Upload to drive and get URL
    const formData = new FormData()
    formData.append('file', file)
    formData.append('folder_name', 'Board Backgrounds')
    
    const response = await api.post('/drive/upload', formData, {
      headers: { 'Content-Type': 'multipart/form-data' }
    })
    
    if (response.data.success) {
      // Get share URL for the file
      const fileId = response.data.data.file.id
      const shareResponse = await api.post(`/drive/files/${fileId}/share`)
      
      if (shareResponse.data.success) {
        backgroundImage.value = shareResponse.data.data.url
        toast.success('Image uploaded')
      }
    } else {
      toast.error('Failed to upload image')
    }
  } catch (err) {
    console.error('Image upload error:', err)
    toast.error('Failed to upload image')
  } finally {
    uploadingImage.value = false
    e.target.value = ''
  }
}

function removeBackgroundImage() {
  backgroundImage.value = ''
}

function confirmArchive() {
  showArchiveConfirm.value = true
}

async function archiveBoard() {
  showArchiveConfirm.value = false
  
  const updated = await boardsStore.archiveBoard(props.boardId)
  if (updated) {
    toast.success('Board archived')
    emit('deleted')
  } else {
    toast.error('Failed to archive board')
  }
}

function confirmDelete() {
  showDeleteConfirm.value = true
}

async function deleteBoard() {
  showDeleteConfirm.value = false
  
  const deleted = await boardsStore.deleteBoard(props.boardId)
  if (deleted) {
    toast.success('Board deleted')
    emit('deleted')
  } else {
    toast.error('Failed to delete board')
  }
}

onMounted(() => {
  loadBoardData()
  if (boardProEnabled.value) loadProSettings()
})
</script>

<template>
  <div class="fixed inset-0 bg-black/50 z-50 flex items-center justify-center p-4" @click.self="emit('close')">
    <div class="bg-white dark:bg-surface-800 rounded-2xl shadow-2xl w-full max-w-md overflow-hidden">
      <!-- Header -->
      <div class="px-6 py-4 border-b border-surface-200 dark:border-surface-700 flex items-center justify-between">
        <h2 class="text-lg font-semibold text-surface-900 dark:text-surface-100">
          Board Settings
        </h2>
        <button 
          @click="emit('close')"
          class="p-1 hover:bg-surface-100 dark:hover:bg-surface-700 rounded-lg transition-colors"
        >
          <span class="material-symbols-rounded text-surface-500">close</span>
        </button>
      </div>
      
      <!-- Content -->
      <div class="px-6 py-4 space-y-4">
        <!-- Board name -->
        <div>
          <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-1.5">
            Board name
          </label>
          <input
            v-model="boardName"
            type="text"
            :disabled="!isOwner"
            class="w-full px-3 py-2 bg-surface-50 dark:bg-surface-700 border border-surface-200 dark:border-surface-600 rounded-xl text-surface-900 dark:text-surface-100 placeholder:text-surface-400 outline-none focus:border-primary-500 disabled:opacity-50"
            placeholder="Enter board name..."
          />
        </div>
        
        <!-- Description -->
        <div>
          <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-1.5">
            Description
          </label>
          <textarea
            v-model="boardDescription"
            rows="3"
            :disabled="!isOwner"
            class="w-full px-3 py-2 bg-surface-50 dark:bg-surface-700 border border-surface-200 dark:border-surface-600 rounded-xl text-surface-900 dark:text-surface-100 placeholder:text-surface-400 outline-none focus:border-primary-500 resize-none disabled:opacity-50"
            placeholder="Add a description..."
          ></textarea>
        </div>
        
        <!-- Background image -->
        <div>
          <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-1.5">
            Background image
          </label>
          
          <!-- Current image preview -->
          <div v-if="backgroundImage" class="relative mb-2">
            <img 
              :src="backgroundImage" 
              alt="Board background" 
              class="w-full h-24 object-cover rounded-lg"
            />
            <button
              v-if="isOwner"
              @click="removeBackgroundImage"
              class="absolute top-1 right-1 p-1 bg-black/50 hover:bg-black/70 rounded-lg transition-colors"
              title="Remove image"
            >
              <span class="material-symbols-rounded text-white text-sm">close</span>
            </button>
          </div>
          
          <!-- Upload button -->
          <div v-if="isOwner" class="flex gap-2">
            <input
              id="board-bg-upload"
              type="file"
              accept="image/*"
              class="hidden"
              @change="handleImageUpload"
            />
            <button
              @click="triggerImageUpload"
              :disabled="uploadingImage"
              class="flex-1 px-3 py-2 border border-surface-300 dark:border-surface-600 rounded-lg text-sm text-surface-700 dark:text-surface-300 hover:bg-surface-100 dark:hover:bg-surface-700 transition-colors flex items-center justify-center gap-2"
            >
              <span v-if="uploadingImage" class="material-symbols-rounded animate-spin">progress_activity</span>
              <span v-else class="material-symbols-rounded text-lg">upload</span>
              {{ uploadingImage ? 'Uploading...' : 'Upload Image' }}
            </button>
          </div>
          <p class="text-xs text-surface-400 mt-1">Recommended: 1920x1080 or larger. Max 5MB.</p>
        </div>
        
        <!-- Background color -->
        <div>
          <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-1.5">
            Background color (used as tint)
          </label>
          <div class="flex flex-wrap gap-2">
            <button
              v-for="color in colors"
              :key="color"
              @click="isOwner && (backgroundColor = color)"
              :class="[
                'w-8 h-8 rounded-lg transition-transform',
                backgroundColor === color ? 'ring-2 ring-offset-2 ring-primary-500 scale-110' : 'hover:scale-105',
                !isOwner ? 'cursor-not-allowed opacity-50' : ''
              ]"
              :style="{ backgroundColor: color }"
              :disabled="!isOwner"
            ></button>
          </div>
        </div>
        
        <!-- Save button -->
        <button 
          v-if="isOwner && hasChanges"
          @click="saveChanges"
          :disabled="saving"
          class="w-full px-4 py-2 bg-primary-500 hover:bg-primary-600 disabled:bg-primary-500/50 text-white rounded-xl text-sm font-medium transition-colors flex items-center justify-center gap-2"
        >
          <span v-if="saving" class="material-symbols-rounded animate-spin text-lg">progress_activity</span>
          <span>{{ saving ? 'Saving...' : 'Save Changes' }}</span>
        </button>
      </div>
      
      <!-- Board Pro Feature Toggles -->
      <div v-if="boardProEnabled" class="px-6 py-4 border-t border-surface-200 dark:border-surface-700">
        <h3 class="text-sm font-semibold text-surface-700 dark:text-surface-300 flex items-center gap-2 mb-3">
          <span class="material-symbols-rounded text-base text-primary-500">build</span>
          Board Pro
        </h3>
        <div class="space-y-2">
          <div v-for="feat in proFeatureToggles" :key="feat.key" class="flex items-center justify-between py-1.5">
            <div class="flex items-center gap-2 min-w-0">
              <span class="material-symbols-rounded text-base text-surface-400">{{ feat.icon }}</span>
              <div class="min-w-0">
                <p class="text-sm text-surface-800 dark:text-surface-200">{{ feat.label }}</p>
                <p class="text-[10px] text-surface-400">{{ feat.desc }}</p>
              </div>
            </div>
            <button
              class="relative w-9 h-5 rounded-full transition-colors shrink-0 ml-3"
              :class="proSettings[feat.key] ? 'bg-primary-500' : 'bg-surface-300 dark:bg-surface-600'"
              @click="toggleProFeature(feat.key)"
            >
              <span
                class="absolute top-0.5 w-4 h-4 bg-white rounded-full shadow transition-transform"
                :class="proSettings[feat.key] ? 'left-[18px]' : 'left-0.5'"
              ></span>
            </button>
          </div>
        </div>
      </div>

      <!-- Danger zone (only for owner) -->
      <div v-if="isOwner" class="px-6 py-4 bg-red-50 dark:bg-red-900/20 border-t border-surface-200 dark:border-surface-700">
        <h3 class="text-sm font-semibold text-red-600 dark:text-red-400 mb-3">
          Danger Zone
        </h3>
        <div class="space-y-2">
          <button 
            @click="confirmArchive"
            class="w-full px-4 py-2 border border-red-300 dark:border-red-700 text-red-600 dark:text-red-400 hover:bg-red-100 dark:hover:bg-red-900/30 rounded-xl text-sm font-medium transition-colors flex items-center justify-center gap-2"
          >
            <span class="material-symbols-rounded text-lg">archive</span>
            Archive Board
          </button>
          <button 
            @click="confirmDelete"
            class="w-full px-4 py-2 bg-red-500 hover:bg-red-600 text-white rounded-xl text-sm font-medium transition-colors flex items-center justify-center gap-2"
          >
            <span class="material-symbols-rounded text-lg">delete_forever</span>
            Delete Board Permanently
          </button>
        </div>
      </div>
      
      <!-- View-only notice -->
      <div v-if="!isOwner" class="px-6 py-3 bg-surface-50 dark:bg-surface-900 border-t border-surface-200 dark:border-surface-700">
        <p class="text-xs text-surface-500 flex items-center gap-2">
          <span class="material-symbols-rounded text-base">info</span>
          Only the board owner can modify settings
        </p>
      </div>
    </div>
    
    <!-- Archive Confirm Modal -->
    <ConfirmModal
      :show="showArchiveConfirm"
      title="Archive Board"
      message="Archive this board? You can restore it later from the archived boards."
      confirm-text="Archive"
      @confirm="archiveBoard"
      @cancel="showArchiveConfirm = false"
    />
    
    <!-- Delete Confirm Modal -->
    <ConfirmModal
      :show="showDeleteConfirm"
      title="Delete Board Permanently"
      message="Permanently delete this board and all its cards, comments, and attachments? This action cannot be undone."
      confirm-text="Delete Forever"
      :danger="true"
      @confirm="deleteBoard"
      @cancel="showDeleteConfirm = false"
    />
  </div>
</template>

<style scoped>
@keyframes spin {
  from { transform: rotate(0deg); }
  to { transform: rotate(360deg); }
}

.animate-spin {
  animation: spin 1s linear infinite;
}
</style>

