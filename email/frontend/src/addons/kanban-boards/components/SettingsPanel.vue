<script setup>
import { ref, computed, onMounted, watch } from 'vue'
import { useBoardsStore } from '@/addons/kanban-boards/stores/boards'
import { useToastStore } from '@/stores/toast'
import ConfirmModal from '@/components/ConfirmModal.vue'
import api from '@/services/api'

const props = defineProps({
  boardId: {
    type: Number,
    required: true
  }
})

const emit = defineEmits(['deleted'])

const boardsStore = useBoardsStore()
const toast = useToastStore()

// State
const boardName = ref('')
const boardDescription = ref('')
const backgroundColor = ref('')
const backgroundImage = ref('')
const saving = ref(false)
const uploadingImage = ref(false)
const showArchiveConfirm = ref(false)
const showDeleteConfirm = ref(false)
const showDrivePicker = ref(false)
const driveImages = ref([])
const loadingDriveImages = ref(false)

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
  document.getElementById('board-settings-bg-upload')?.click()
}

async function handleImageUpload(e) {
  const file = e.target.files?.[0]
  if (!file) return
  
  if (!file.type.startsWith('image/')) {
    toast.error('Please select an image file')
    return
  }
  
  if (file.size > 5 * 1024 * 1024) {
    toast.error('Image must be less than 5MB')
    return
  }
  
  uploadingImage.value = true
  
  try {
    const formData = new FormData()
    formData.append('file', file)
    formData.append('folder_name', 'Board Backgrounds')
    
    const response = await api.post('/drive/upload', formData, {
      headers: { 'Content-Type': 'multipart/form-data' }
    })
    
    if (response.data.success) {
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

async function openDrivePicker() {
  showDrivePicker.value = true
  loadingDriveImages.value = true
  
  try {
    // Fetch images from Drive (uses /drive endpoint with type filter)
    const response = await api.get('/drive', {
      params: { type: 'image' }
    })
    
    if (response.data.success) {
      driveImages.value = response.data.data.files || []
    }
  } catch (err) {
    console.error('Failed to load Drive images:', err)
    toast.error('Failed to load images from Drive')
  } finally {
    loadingDriveImages.value = false
  }
}

async function selectDriveImage(file) {
  try {
    // Get shareable URL for the image
    const shareResponse = await api.post(`/drive/files/${file.id}/share`)
    
    if (shareResponse.data.success) {
      backgroundImage.value = shareResponse.data.data.url
      showDrivePicker.value = false
      toast.success('Image selected from Drive')
    } else {
      toast.error('Failed to get image URL')
    }
  } catch (err) {
    console.error('Failed to select Drive image:', err)
    toast.error('Failed to select image')
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
})

watch(() => props.boardId, () => {
  loadBoardData()
})

watch(() => board.value, () => {
  loadBoardData()
}, { deep: true })
</script>

<template>
  <div class="h-full bg-white dark:bg-surface-800 flex flex-col relative">
    <!-- Header -->
    <div class="px-6 py-4 border-b border-surface-200 dark:border-surface-700">
      <div class="flex items-center gap-3">
        <span class="material-symbols-rounded text-2xl text-primary-500">tune</span>
        <div>
          <h2 class="text-lg font-semibold text-surface-900 dark:text-surface-100">Board Settings</h2>
          <p class="text-sm text-surface-500">Customize your board</p>
        </div>
      </div>
    </div>
    
    <!-- Content -->
    <div class="flex-1 overflow-y-auto p-6 pb-24">
      <div class="max-w-3xl mx-auto space-y-6">
        <!-- Basic Info Group -->
        <div class="bg-surface-50 dark:bg-surface-700/50 rounded-xl p-5 space-y-4">
          <h3 class="text-sm font-semibold text-surface-700 dark:text-surface-300 uppercase tracking-wide flex items-center gap-2">
            <span class="material-symbols-rounded text-lg">info</span>
            Basic Info
          </h3>
          
          <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <!-- Board name -->
            <div>
              <label class="block text-xs font-medium text-surface-600 dark:text-surface-400 mb-1.5">
                Board Name
              </label>
              <input
                v-model="boardName"
                type="text"
                :disabled="!isOwner"
                class="w-full px-3 py-2 bg-white dark:bg-surface-600 border border-surface-200 dark:border-surface-500 rounded-lg text-sm text-surface-900 dark:text-surface-100 placeholder:text-surface-400 outline-none focus:border-primary-500 focus:ring-1 focus:ring-primary-500 disabled:opacity-50"
                placeholder="Enter board name..."
              />
            </div>
            
            <!-- Description -->
            <div>
              <label class="block text-xs font-medium text-surface-600 dark:text-surface-400 mb-1.5">
                Description
              </label>
              <input
                v-model="boardDescription"
                type="text"
                :disabled="!isOwner"
                class="w-full px-3 py-2 bg-white dark:bg-surface-600 border border-surface-200 dark:border-surface-500 rounded-lg text-sm text-surface-900 dark:text-surface-100 placeholder:text-surface-400 outline-none focus:border-primary-500 focus:ring-1 focus:ring-primary-500 disabled:opacity-50"
                placeholder="Add a description..."
              />
            </div>
          </div>
        </div>
        
        <!-- Appearance Group -->
        <div class="bg-surface-50 dark:bg-surface-700/50 rounded-xl p-5 space-y-4">
          <h3 class="text-sm font-semibold text-surface-700 dark:text-surface-300 uppercase tracking-wide flex items-center gap-2">
            <span class="material-symbols-rounded text-lg">palette</span>
            Appearance
          </h3>
          
          <!-- Background Image -->
          <div>
            <label class="block text-xs font-medium text-surface-600 dark:text-surface-400 mb-2">
              Background Image
            </label>
            
            <div class="flex gap-3">
              <!-- Preview or placeholder -->
              <div 
                class="w-32 h-20 rounded-lg border-2 border-dashed border-surface-300 dark:border-surface-500 flex items-center justify-center overflow-hidden flex-shrink-0"
                :class="backgroundImage ? 'border-solid border-primary-500' : ''"
              >
                <img 
                  v-if="backgroundImage" 
                  :src="backgroundImage" 
                  alt="Background" 
                  class="w-full h-full object-cover"
                />
                <span v-else class="material-symbols-rounded text-2xl text-surface-400">image</span>
              </div>
              
              <!-- Actions -->
              <div v-if="isOwner" class="flex flex-col gap-2 flex-1">
                <input
                  id="board-settings-bg-upload"
                  type="file"
                  accept="image/*"
                  class="hidden"
                  @change="handleImageUpload"
                />
                <div class="flex gap-2">
                  <button
                    @click="triggerImageUpload"
                    :disabled="uploadingImage"
                    class="flex-1 px-3 py-2 bg-white dark:bg-surface-600 border border-surface-200 dark:border-surface-500 rounded-lg text-xs font-medium text-surface-700 dark:text-surface-300 hover:bg-surface-100 dark:hover:bg-surface-500 transition-colors flex items-center justify-center gap-1.5"
                  >
                    <span v-if="uploadingImage" class="material-symbols-rounded text-sm animate-spin">progress_activity</span>
                    <span v-else class="material-symbols-rounded text-sm">upload</span>
                    Upload
                  </button>
                  <button
                    @click="openDrivePicker"
                    class="flex-1 px-3 py-2 bg-white dark:bg-surface-600 border border-surface-200 dark:border-surface-500 rounded-lg text-xs font-medium text-surface-700 dark:text-surface-300 hover:bg-surface-100 dark:hover:bg-surface-500 transition-colors flex items-center justify-center gap-1.5"
                  >
                    <span class="material-symbols-rounded text-sm">cloud</span>
                    From Drive
                  </button>
                </div>
                <button
                  v-if="backgroundImage"
                  @click="removeBackgroundImage"
                  class="px-3 py-1.5 text-xs text-red-500 hover:bg-red-50 dark:hover:bg-red-500/10 rounded-lg transition-colors flex items-center justify-center gap-1"
                >
                  <span class="material-symbols-rounded text-sm">close</span>
                  Remove image
                </button>
              </div>
            </div>
          </div>
          
          <!-- Background Color -->
          <div>
            <label class="block text-xs font-medium text-surface-600 dark:text-surface-400 mb-2">
              Background Color
            </label>
            <div class="flex flex-wrap gap-2">
              <button
                v-for="color in colors"
                :key="color"
                @click="isOwner && (backgroundColor = color)"
                :class="[
                  'w-8 h-8 rounded-lg transition-all',
                  backgroundColor === color ? 'ring-2 ring-offset-2 ring-primary-500 scale-110' : 'hover:scale-105',
                  !isOwner ? 'cursor-not-allowed opacity-50' : ''
                ]"
                :style="{ backgroundColor: color }"
                :disabled="!isOwner"
              ></button>
            </div>
          </div>
        </div>
        
        <!-- Danger Zone -->
        <div v-if="isOwner" class="bg-red-50 dark:bg-red-900/20 rounded-xl p-5 border border-red-200 dark:border-red-800">
          <h3 class="text-sm font-semibold text-red-600 dark:text-red-400 uppercase tracking-wide flex items-center gap-2 mb-4">
            <span class="material-symbols-rounded text-lg">warning</span>
            Danger Zone
          </h3>
          <div class="flex gap-3">
            <button 
              @click="confirmArchive"
              class="px-4 py-2 border border-red-300 dark:border-red-700 text-red-600 dark:text-red-400 hover:bg-red-100 dark:hover:bg-red-900/30 rounded-lg text-sm font-medium transition-colors flex items-center gap-2"
            >
              <span class="material-symbols-rounded text-lg">archive</span>
              Archive
            </button>
            <button 
              @click="confirmDelete"
              class="px-4 py-2 bg-red-500 hover:bg-red-600 text-white rounded-lg text-sm font-medium transition-colors flex items-center gap-2"
            >
              <span class="material-symbols-rounded text-lg">delete_forever</span>
              Delete
            </button>
          </div>
        </div>
        
        <!-- View-only notice -->
        <div v-if="!isOwner" class="p-4 rounded-xl bg-surface-100 dark:bg-surface-700 text-center">
          <p class="text-sm text-surface-500 flex items-center justify-center gap-2">
            <span class="material-symbols-rounded">info</span>
            Only the board owner can modify settings
          </p>
        </div>
      </div>
    </div>
    
    <!-- Floating Save Button -->
    <Transition name="slide-up">
      <div 
        v-if="isOwner && hasChanges" 
        class="absolute bottom-6 left-1/2 -translate-x-1/2 z-10"
      >
        <button 
          @click="saveChanges"
          :disabled="saving"
          class="px-6 py-3 bg-primary-500 hover:bg-primary-600 disabled:bg-primary-500/50 text-white rounded-full text-sm font-medium shadow-lg hover:shadow-xl transition-all flex items-center gap-2"
        >
          <span v-if="saving" class="material-symbols-rounded animate-spin">progress_activity</span>
          <span v-else class="material-symbols-rounded">save</span>
          {{ saving ? 'Saving...' : 'Save Changes' }}
        </button>
      </div>
    </Transition>
    
    <!-- Drive Image Picker Modal -->
    <Teleport to="body">
      <div 
        v-if="showDrivePicker"
        class="fixed inset-0 z-50 flex items-center justify-center p-4"
        @click.self="showDrivePicker = false"
      >
        <div class="absolute inset-0 bg-black/50 backdrop-blur-sm"></div>
        <div class="relative bg-white dark:bg-surface-800 rounded-2xl shadow-2xl w-full max-w-2xl max-h-[80vh] flex flex-col">
          <!-- Modal Header -->
          <div class="px-6 py-4 border-b border-surface-200 dark:border-surface-700 flex items-center justify-between">
            <div class="flex items-center gap-3">
              <span class="material-symbols-rounded text-2xl text-primary-500">cloud</span>
              <h3 class="text-lg font-semibold text-surface-900 dark:text-surface-100">Select from Drive</h3>
            </div>
            <button 
              @click="showDrivePicker = false"
              class="p-2 hover:bg-surface-100 dark:hover:bg-surface-700 rounded-lg transition-colors"
            >
              <span class="material-symbols-rounded">close</span>
            </button>
          </div>
          
          <!-- Modal Content -->
          <div class="flex-1 overflow-y-auto p-4">
            <div v-if="loadingDriveImages" class="flex items-center justify-center py-12">
              <span class="material-symbols-rounded text-4xl text-primary-500 animate-spin">progress_activity</span>
            </div>
            
            <div v-else-if="driveImages.length === 0" class="text-center py-12">
              <span class="material-symbols-rounded text-4xl text-surface-400">image</span>
              <p class="text-surface-500 mt-2">No images found in Drive</p>
            </div>
            
            <div v-else class="grid grid-cols-3 sm:grid-cols-4 gap-3">
              <button
                v-for="file in driveImages"
                :key="file.id"
                @click="selectDriveImage(file)"
                class="aspect-video rounded-lg overflow-hidden border-2 border-transparent hover:border-primary-500 transition-colors group"
              >
                <img 
                  :src="file.thumbnail_url || file.url" 
                  :alt="file.name"
                  class="w-full h-full object-cover group-hover:scale-105 transition-transform"
                />
              </button>
            </div>
          </div>
        </div>
      </div>
    </Teleport>
    
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

.slide-up-enter-active,
.slide-up-leave-active {
  transition: all 0.3s ease;
}

.slide-up-enter-from,
.slide-up-leave-to {
  opacity: 0;
  transform: translate(-50%, 20px);
}
</style>
