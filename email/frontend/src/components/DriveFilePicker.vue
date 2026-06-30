<script setup>
import { ref, computed, onMounted, watch } from 'vue'
import { useDriveStore } from '@/stores/drive'
import api from '@/services/api'

const props = defineProps({
  show: {
    type: Boolean,
    default: false
  },
  title: {
    type: String,
    default: 'Choose from Drive'
  },
  acceptTypes: {
    type: Array,
    default: () => [] // Empty means all types, e.g. ['image/*', 'application/pdf']
  },
  multiple: {
    type: Boolean,
    default: false
  }
})

const emit = defineEmits(['select', 'cancel'])

const driveStore = useDriveStore()

// State
const loading = ref(false)
const currentFolderId = ref(null)
const breadcrumbs = ref([{ id: null, name: 'My Drive' }])
const contents = ref({ folders: [], files: [] })
const selectedFiles = ref([])
const viewMode = ref('grid') // 'grid' or 'list'
const searchQuery = ref('')

// Computed
const filteredContents = computed(() => {
  let folders = contents.value.folders || []
  let files = contents.value.files || []
  
  // Filter by search
  if (searchQuery.value) {
    const q = searchQuery.value.toLowerCase()
    folders = folders.filter(f => f.name.toLowerCase().includes(q))
    files = files.filter(f => f.original_name.toLowerCase().includes(q))
  }
  
  // Filter files by accepted types
  if (props.acceptTypes.length > 0) {
    files = files.filter(file => {
      return props.acceptTypes.some(type => {
        if (type.endsWith('/*')) {
          const category = type.split('/')[0]
          return file.mime_type?.startsWith(category + '/')
        }
        return file.mime_type === type
      })
    })
  }
  
  return { folders, files }
})

const hasSelection = computed(() => selectedFiles.value.length > 0)

// Methods
async function loadContents(folderId = null) {
  loading.value = true
  try {
    await driveStore.fetchContents(folderId)
    contents.value = {
      folders: driveStore.folders || [],
      files: driveStore.files || []
    }
  } catch (e) {
    console.error('Failed to load drive contents:', e)
  } finally {
    loading.value = false
  }
}

async function openFolder(folder) {
  currentFolderId.value = folder.id
  breadcrumbs.value.push({ id: folder.id, name: folder.name })
  await loadContents(folder.id)
}

async function navigateToBreadcrumb(index) {
  const crumb = breadcrumbs.value[index]
  currentFolderId.value = crumb.id
  breadcrumbs.value = breadcrumbs.value.slice(0, index + 1)
  await loadContents(crumb.id)
}

function toggleFileSelection(file) {
  const index = selectedFiles.value.findIndex(f => f.id === file.id)
  
  if (props.multiple) {
    if (index > -1) {
      selectedFiles.value.splice(index, 1)
    } else {
      selectedFiles.value.push(file)
    }
  } else {
    if (index > -1) {
      selectedFiles.value = []
    } else {
      selectedFiles.value = [file]
    }
  }
}

function isSelected(file) {
  return selectedFiles.value.some(f => f.id === file.id)
}

function confirmSelection() {
  if (selectedFiles.value.length === 0) return
  
  if (props.multiple) {
    emit('select', selectedFiles.value)
  } else {
    emit('select', selectedFiles.value[0])
  }
  
  resetState()
}

function cancel() {
  emit('cancel')
  resetState()
}

function resetState() {
  selectedFiles.value = []
  searchQuery.value = ''
  currentFolderId.value = null
  breadcrumbs.value = [{ id: null, name: 'My Drive' }]
}

function getFileIcon(mimeType) {
  if (!mimeType) return 'description'
  if (mimeType.startsWith('image/')) return 'image'
  if (mimeType.startsWith('video/')) return 'videocam'
  if (mimeType.startsWith('audio/')) return 'audio_file'
  if (mimeType.includes('pdf')) return 'picture_as_pdf'
  if (mimeType.includes('spreadsheet') || mimeType.includes('excel')) return 'table_chart'
  if (mimeType.includes('document') || mimeType.includes('word')) return 'article'
  if (mimeType.includes('presentation') || mimeType.includes('powerpoint')) return 'slideshow'
  if (mimeType.includes('zip') || mimeType.includes('archive')) return 'folder_zip'
  return 'description'
}

function formatFileSize(bytes) {
  if (!bytes) return ''
  if (bytes < 1024) return bytes + ' B'
  if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB'
  return (bytes / (1024 * 1024)).toFixed(1) + ' MB'
}

function isImage(file) {
  return file.mime_type?.startsWith('image/')
}

function isVideo(file) {
  return file.mime_type?.startsWith('video/')
}

const thumbCache = ref({})

function getThumbnailUrl(file) {
  if (file.share_url) return file.share_url
  if (file.thumbnail_url) return file.thumbnail_url
  const cached = thumbCache.value[file.id]
  if (cached === 'error' || cached === 'loading') return null
  if (cached) return cached
  loadThumb(file.id)
  return null
}

async function loadThumb(fileId) {
  if (thumbCache.value[fileId]) return
  thumbCache.value[fileId] = 'loading'
  try {
    const res = await api.get(`/drive/files/${fileId}/preview`, { responseType: 'blob' })
    if (res.data) {
      thumbCache.value[fileId] = URL.createObjectURL(res.data)
    } else {
      thumbCache.value[fileId] = 'error'
    }
  } catch {
    thumbCache.value[fileId] = 'error'
  }
}

function canShowThumbnail(file) {
  return isImage(file) || isVideo(file)
}

watch(() => props.show, (newVal) => {
  if (newVal) {
    thumbCache.value = {}
    loadContents(null)
  }
})

// Load on mount if already showing
onMounted(() => {
  if (props.show) {
    loadContents(null)
  }
})
</script>

<template>
  <Teleport to="body">
    <Transition name="modal">
      <div 
        v-if="show"
        class="fixed inset-0 z-[20000] flex items-center justify-center p-4"
      >
        <!-- Backdrop -->
        <div 
          class="absolute inset-0 bg-black/60 backdrop-blur-sm"
          @click="cancel"
        ></div>
        
        <!-- Modal -->
        <div class="relative bg-white dark:bg-surface-800 rounded-2xl shadow-2xl w-full max-w-4xl h-[80vh] max-h-[700px] overflow-hidden flex flex-col">
          <!-- Header -->
          <div class="px-6 py-4 border-b border-surface-200 dark:border-surface-700 flex items-center justify-between shrink-0">
            <h3 class="text-lg font-semibold text-surface-900 dark:text-surface-100">
              {{ title }}
            </h3>
            <button 
              @click="cancel"
              class="p-1 hover:bg-surface-100 dark:hover:bg-surface-700 rounded-lg transition-colors"
            >
              <span class="material-symbols-rounded text-surface-500">close</span>
            </button>
          </div>
          
          <!-- Toolbar -->
          <div class="px-6 py-3 border-b border-surface-200 dark:border-surface-700 flex items-center gap-4 shrink-0">
            <!-- Breadcrumbs -->
            <div class="flex items-center gap-1 flex-1 min-w-0 overflow-x-auto">
              <button
                v-for="(crumb, index) in breadcrumbs"
                :key="crumb.id ?? 'root'"
                @click="navigateToBreadcrumb(index)"
                class="flex items-center gap-1 shrink-0"
              >
                <span 
                  v-if="index > 0" 
                  class="material-symbols-rounded text-surface-400 text-sm"
                >chevron_right</span>
                <span 
                  :class="[
                    'text-sm px-2 py-1 rounded-lg transition-colors',
                    index === breadcrumbs.length - 1 
                      ? 'text-surface-900 dark:text-surface-100 font-medium bg-surface-100 dark:bg-surface-700' 
                      : 'text-surface-500 hover:text-surface-900 dark:hover:text-surface-100 hover:bg-surface-100 dark:hover:bg-surface-700'
                  ]"
                >
                  {{ crumb.name }}
                </span>
              </button>
            </div>
            
            <!-- Search -->
            <div class="relative w-64">
              <span class="material-symbols-rounded absolute left-3 top-1/2 -translate-y-1/2 text-surface-400 text-lg">search</span>
              <input
                v-model="searchQuery"
                type="text"
                placeholder="Search files..."
                class="w-full pl-10 pr-4 py-2 bg-surface-100 dark:bg-surface-700 border-0 rounded-xl text-sm text-surface-900 dark:text-surface-100 placeholder:text-surface-400 outline-none focus:ring-2 focus:ring-primary-500"
              />
            </div>
            
            <!-- View toggle -->
            <div class="flex items-center gap-1 bg-surface-100 dark:bg-surface-700 rounded-lg p-1">
              <button
                @click="viewMode = 'grid'"
                :class="[
                  'p-1.5 rounded transition-colors',
                  viewMode === 'grid' ? 'bg-white dark:bg-surface-600 shadow-sm' : 'hover:bg-surface-200 dark:hover:bg-surface-600'
                ]"
              >
                <span class="material-symbols-rounded text-lg text-surface-600 dark:text-surface-300">grid_view</span>
              </button>
              <button
                @click="viewMode = 'list'"
                :class="[
                  'p-1.5 rounded transition-colors',
                  viewMode === 'list' ? 'bg-white dark:bg-surface-600 shadow-sm' : 'hover:bg-surface-200 dark:hover:bg-surface-600'
                ]"
              >
                <span class="material-symbols-rounded text-lg text-surface-600 dark:text-surface-300">view_list</span>
              </button>
            </div>
          </div>
          
          <!-- Content -->
          <div class="flex-1 overflow-y-auto p-6">
            <!-- Loading -->
            <div v-if="loading" class="flex items-center justify-center py-12">
              <span class="material-symbols-rounded animate-spin text-4xl text-primary-500">progress_activity</span>
            </div>
            
            <!-- Empty state -->
            <div 
              v-else-if="filteredContents.folders.length === 0 && filteredContents.files.length === 0"
              class="flex flex-col items-center justify-center py-12"
            >
              <span class="material-symbols-rounded text-5xl text-surface-300 dark:text-surface-600 mb-3">folder_off</span>
              <p class="text-surface-500">
                {{ searchQuery ? 'No files match your search' : 'This folder is empty' }}
              </p>
            </div>
            
            <!-- Grid view -->
            <div v-else-if="viewMode === 'grid'">
              <!-- Folders -->
              <div v-if="filteredContents.folders.length > 0" class="mb-6">
                <h4 class="text-xs font-semibold text-surface-500 uppercase tracking-wide mb-3">Folders</h4>
                <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 gap-3">
                  <div
                    v-for="folder in filteredContents.folders"
                    :key="folder.id"
                    @dblclick="openFolder(folder)"
                    class="p-4 rounded-xl border border-surface-200 dark:border-surface-700 hover:border-primary-500 dark:hover:border-primary-500 hover:bg-surface-50 dark:hover:bg-surface-700/50 cursor-pointer transition-all group"
                  >
                    <div class="flex flex-col items-center text-center">
                      <span 
                        class="material-symbols-rounded text-4xl mb-2"
                        :style="{ color: folder.color || '#a855f7' }"
                      >folder</span>
                      <span class="text-sm text-surface-900 dark:text-surface-100 truncate w-full">
                        {{ folder.name }}
                      </span>
                    </div>
                  </div>
                </div>
              </div>
              
              <!-- Files -->
              <div v-if="filteredContents.files.length > 0">
                <h4 class="text-xs font-semibold text-surface-500 uppercase tracking-wide mb-3">Files</h4>
                <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 gap-3">
                  <div
                    v-for="file in filteredContents.files"
                    :key="file.id"
                    @click="toggleFileSelection(file)"
                    :class="[
                      'p-3 rounded-xl border-2 cursor-pointer transition-all',
                      isSelected(file) 
                        ? 'border-primary-500 bg-primary-500/10' 
                        : 'border-surface-200 dark:border-surface-700 hover:border-primary-300 dark:hover:border-primary-700 hover:bg-surface-50 dark:hover:bg-surface-700/50'
                    ]"
                  >
                    <div class="flex flex-col items-center text-center">
                      <!-- Thumbnail or icon -->
                      <div class="w-full aspect-square rounded-lg overflow-hidden bg-surface-100 dark:bg-surface-700 mb-2 flex items-center justify-center relative">
                        <img
                          v-if="canShowThumbnail(file) && getThumbnailUrl(file)"
                          :src="getThumbnailUrl(file)"
                          :alt="file.original_name"
                          class="w-full h-full object-cover"
                          loading="lazy"
                          @error="$event.target.classList.add('hidden')"
                        />
                        <span 
                          :class="[
                            'material-symbols-rounded text-4xl text-primary-500 absolute',
                            canShowThumbnail(file) ? 'opacity-0' : ''
                          ]"
                        >{{ getFileIcon(file.mime_type) }}</span>
                        <!-- Video play indicator -->
                        <div 
                          v-if="isVideo(file)" 
                          class="absolute inset-0 flex items-center justify-center bg-black/20"
                        >
                          <span class="material-symbols-rounded text-white text-3xl drop-shadow-lg">play_circle</span>
                        </div>
                      </div>
                      <span class="text-sm text-surface-900 dark:text-surface-100 truncate w-full">
                        {{ file.original_name }}
                      </span>
                      <span class="text-xs text-surface-500">
                        {{ formatFileSize(file.size) }}
                      </span>
                    </div>
                    
                    <!-- Selection indicator -->
                    <div 
                      v-if="isSelected(file)"
                      class="absolute top-2 right-2 w-6 h-6 bg-primary-500 rounded-full flex items-center justify-center"
                    >
                      <span class="material-symbols-rounded text-white text-sm">check</span>
                    </div>
                  </div>
                </div>
              </div>
            </div>
            
            <!-- List view -->
            <div v-else class="space-y-1">
              <!-- Folders -->
              <div
                v-for="folder in filteredContents.folders"
                :key="folder.id"
                @dblclick="openFolder(folder)"
                class="flex items-center gap-3 p-3 rounded-xl hover:bg-surface-100 dark:hover:bg-surface-700 cursor-pointer transition-colors"
              >
                <span 
                  class="material-symbols-rounded text-2xl"
                  :style="{ color: folder.color || '#a855f7' }"
                >folder</span>
                <span class="flex-1 text-sm text-surface-900 dark:text-surface-100 truncate">
                  {{ folder.name }}
                </span>
                <span class="text-xs text-surface-400">Folder</span>
              </div>
              
              <!-- Files -->
              <div
                v-for="file in filteredContents.files"
                :key="file.id"
                @click="toggleFileSelection(file)"
                :class="[
                  'flex items-center gap-3 p-3 rounded-xl cursor-pointer transition-colors',
                  isSelected(file) 
                    ? 'bg-primary-500/10 ring-2 ring-primary-500' 
                    : 'hover:bg-surface-100 dark:hover:bg-surface-700'
                ]"
              >
                <!-- Thumbnail or icon -->
                <div class="w-10 h-10 rounded-lg overflow-hidden bg-surface-100 dark:bg-surface-700 flex items-center justify-center shrink-0 relative">
                  <img
                    v-if="canShowThumbnail(file) && getThumbnailUrl(file)"
                    :src="getThumbnailUrl(file)"
                    :alt="file.original_name"
                    class="w-full h-full object-cover"
                    loading="lazy"
                    @error="$event.target.classList.add('hidden')"
                  />
                  <span 
                    :class="[
                      'material-symbols-rounded text-xl text-primary-500 absolute',
                      canShowThumbnail(file) ? 'opacity-0' : ''
                    ]"
                  >{{ getFileIcon(file.mime_type) }}</span>
                  <!-- Video play indicator -->
                  <span 
                    v-if="isVideo(file)" 
                    class="material-symbols-rounded text-white text-sm absolute drop-shadow"
                  >play_circle</span>
                </div>
                
                <div class="flex-1 min-w-0">
                  <p class="text-sm text-surface-900 dark:text-surface-100 truncate">
                    {{ file.original_name }}
                  </p>
                  <p class="text-xs text-surface-500">
                    {{ formatFileSize(file.size) }}
                  </p>
                </div>
                
                <!-- Selection indicator -->
                <div 
                  v-if="isSelected(file)"
                  class="w-6 h-6 bg-primary-500 rounded-full flex items-center justify-center shrink-0"
                >
                  <span class="material-symbols-rounded text-white text-sm">check</span>
                </div>
              </div>
            </div>
          </div>
          
          <!-- Footer -->
          <div class="px-6 py-4 border-t border-surface-200 dark:border-surface-700 flex items-center justify-between shrink-0 bg-surface-50 dark:bg-surface-900">
            <div class="text-sm text-surface-500">
              <span v-if="selectedFiles.length === 0">No file selected</span>
              <span v-else-if="selectedFiles.length === 1">{{ selectedFiles[0].original_name }}</span>
              <span v-else>{{ selectedFiles.length }} files selected</span>
            </div>
            <div class="flex items-center gap-3">
              <button
                @click="cancel"
                class="px-4 py-2 text-sm font-medium text-surface-700 dark:text-surface-300 hover:bg-surface-200 dark:hover:bg-surface-700 rounded-xl transition-colors"
              >
                Cancel
              </button>
              <button
                @click="confirmSelection"
                :disabled="!hasSelection"
                class="px-4 py-2 text-sm font-medium bg-primary-500 hover:bg-primary-600 disabled:bg-surface-300 disabled:dark:bg-surface-600 text-white rounded-xl transition-colors disabled:cursor-not-allowed"
              >
                Select
              </button>
            </div>
          </div>
        </div>
      </div>
    </Transition>
  </Teleport>
</template>

<style scoped>
.modal-enter-active,
.modal-leave-active {
  transition: all 0.2s ease;
}

.modal-enter-from,
.modal-leave-to {
  opacity: 0;
}

.modal-enter-from .relative,
.modal-leave-to .relative {
  transform: scale(0.95);
}

@keyframes spin {
  from { transform: rotate(0deg); }
  to { transform: rotate(360deg); }
}

.animate-spin {
  animation: spin 1s linear infinite;
}

/* Hide scrollbar but keep functionality */
.overflow-x-auto::-webkit-scrollbar {
  height: 0;
}

/* Show icon when image is hidden/fails to load */
img.hidden + span {
  opacity: 1 !important;
}
</style>

