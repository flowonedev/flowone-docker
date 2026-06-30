<template>
  <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/40" @click.self="$emit('close')">
    <div class="bg-white dark:bg-surface-800 rounded-2xl shadow-2xl w-[600px] max-h-[80vh] flex flex-col overflow-hidden" @click.stop>
      <!-- Header -->
      <div class="flex items-center justify-between px-5 py-3 border-b border-surface-200 dark:border-surface-700">
        <h3 class="text-sm font-semibold text-surface-900 dark:text-surface-100 flex items-center gap-2">
          <span class="material-symbols-rounded text-lg text-primary-500">cloud</span>
          Add from Drive
        </h3>
        <button @click="$emit('close')" class="p-1 rounded-lg hover:bg-surface-100 dark:hover:bg-surface-700 text-surface-400">
          <span class="material-symbols-rounded text-lg">close</span>
        </button>
      </div>

      <!-- Breadcrumb -->
      <div class="flex items-center gap-1 px-5 py-2 border-b border-surface-100 dark:border-surface-700/50 text-xs">
        <button @click="navigateTo(null)" class="text-primary-500 hover:underline">Drive</button>
        <template v-for="(crumb, idx) in breadcrumbs" :key="crumb.id">
          <span class="material-symbols-rounded text-xs text-surface-400">chevron_right</span>
          <button @click="navigateTo(crumb.id)" class="text-primary-500 hover:underline truncate max-w-[120px]">{{ crumb.name }}</button>
        </template>
      </div>

      <!-- Search -->
      <div class="px-5 py-2 border-b border-surface-100 dark:border-surface-700/50">
        <div class="relative">
          <span class="material-symbols-rounded absolute left-3 top-1/2 -translate-y-1/2 text-lg text-surface-400">search</span>
          <input
            v-model="searchQuery"
            @input="debouncedSearch"
            placeholder="Search files..."
            class="w-full pl-10 pr-4 py-2 text-sm rounded-xl border border-surface-200 dark:border-surface-600 bg-surface-50 dark:bg-surface-700 text-surface-900 dark:text-surface-100 focus:ring-2 focus:ring-primary-500"
          />
        </div>
      </div>

      <!-- Content -->
      <div class="flex-1 overflow-y-auto p-3 min-h-[300px]">
        <!-- Loading -->
        <div v-if="loadingDrive" class="flex items-center justify-center h-full py-10">
          <span class="material-symbols-rounded text-2xl text-surface-400 animate-spin">sync</span>
        </div>

        <!-- Empty -->
        <div v-else-if="filteredItems.length === 0" class="flex flex-col items-center justify-center py-10 text-surface-400">
          <span class="material-symbols-rounded text-4xl">folder_off</span>
          <p class="text-sm mt-2">No files found</p>
        </div>

        <!-- File/folder list -->
        <div v-else class="space-y-0.5">
          <!-- Select all / Deselect all bar -->
          <div v-if="filteredFiles.length > 0" class="flex items-center justify-between px-3 py-1.5 mb-1">
            <button
              @click="toggleSelectAll"
              class="text-xs text-primary-500 hover:text-primary-600 font-medium flex items-center gap-1"
            >
              <span class="material-symbols-rounded text-sm">{{ allFilesSelected ? 'deselect' : 'select_all' }}</span>
              {{ allFilesSelected ? 'Deselect all' : 'Select all' }}
            </button>
            <span class="text-[10px] text-surface-400">{{ filteredFiles.length }} file(s)</span>
          </div>

          <!-- Folders first -->
          <div
            v-for="folder in filteredFolders"
            :key="'f-' + folder.id"
            class="w-full flex items-center gap-3 px-3 py-2.5 rounded-xl hover:bg-surface-50 dark:hover:bg-surface-700/50 text-left transition-colors"
          >
            <button @click="navigateTo(folder.id)" class="flex items-center gap-3 flex-1 min-w-0">
              <span class="material-symbols-rounded text-xl text-amber-500">folder</span>
              <span class="flex-1 text-sm text-surface-900 dark:text-surface-100 truncate text-left">{{ folder.name }}</span>
            </button>
            <button
              @click.stop="selectFolder(folder)"
              class="px-2.5 py-1 text-[11px] font-medium rounded-full border border-primary-300 dark:border-primary-700 text-primary-500 hover:bg-primary-50 dark:hover:bg-primary-900/20 transition-colors flex items-center gap-1 flex-shrink-0"
              title="Add folder to mood board"
            >
              <span class="material-symbols-rounded text-sm">add</span>
              Add folder
            </button>
            <button @click="navigateTo(folder.id)" class="flex-shrink-0">
              <span class="material-symbols-rounded text-lg text-surface-400">chevron_right</span>
            </button>
          </div>

          <!-- Files -->
          <button
            v-for="file in filteredFiles"
            :key="'file-' + file.id"
            @click="toggleFileSelection(file)"
            :class="[
              'w-full flex items-center gap-3 px-3 py-2.5 rounded-xl text-left transition-colors',
              isFileSelected(file)
                ? 'bg-primary-50 dark:bg-primary-900/20 ring-1 ring-primary-300 dark:ring-primary-700'
                : 'hover:bg-surface-50 dark:hover:bg-surface-700/50'
            ]"
          >
            <span class="material-symbols-rounded text-xl" :class="getFileIconColor(file)">{{ getFileIcon(file) }}</span>
            <div class="flex-1 min-w-0">
              <p class="text-sm text-surface-900 dark:text-surface-100 truncate">{{ file.original_name || file.name || file.filename || 'Unnamed file' }}</p>
              <p class="text-xs text-surface-400 mt-0.5">{{ formatSize(file.size) }}</p>
            </div>
            <!-- Thumbnail preview for images -->
            <img
              v-if="isImage(file)"
              :src="getPreviewUrl(file)"
              class="w-10 h-10 rounded-lg object-cover flex-shrink-0"
              @error="$event.target.style.display='none'"
            />
            <div v-if="isFileSelected(file)" class="w-5 h-5 rounded-full bg-primary-500 flex items-center justify-center flex-shrink-0">
              <span class="material-symbols-rounded text-sm text-white">check</span>
            </div>
          </button>
        </div>
      </div>

      <!-- Footer -->
      <div class="flex items-center justify-between px-5 py-3 border-t border-surface-200 dark:border-surface-700">
        <span class="text-xs text-surface-400">{{ selectedFiles.length }} file(s) selected</span>
        <div class="flex items-center gap-2">
          <button @click="$emit('close')" class="px-4 py-2 text-sm rounded-full hover:bg-surface-100 dark:hover:bg-surface-700 text-surface-600 dark:text-surface-400 transition-colors">
            Cancel
          </button>
          <button
            @click="confirmSelection"
            :disabled="selectedFiles.length === 0"
            :class="[
              'px-5 py-2 text-sm font-medium rounded-full transition-colors',
              selectedFiles.length > 0
                ? 'bg-primary-500 hover:bg-primary-600 text-white'
                : 'bg-surface-200 dark:bg-surface-700 text-surface-400 cursor-not-allowed'
            ]"
          >
            Add {{ selectedFiles.length > 0 ? `(${selectedFiles.length})` : '' }}
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import api from '@/services/api'
import { getToken } from '@/services/tokenStorage'

const props = defineProps({
  filterType: { type: String, default: 'all' } // 'all', 'images', 'documents'
})

const emit = defineEmits(['select', 'select-folder', 'close'])

const loadingDrive = ref(false)
const driveFolders = ref([])
const driveFiles = ref([])
const currentFolderId = ref(null)
const breadcrumbs = ref([])
const selectedFiles = ref([])
const searchQuery = ref('')

let searchTimeout = null

// Filtered items
const filteredFolders = computed(() => {
  if (searchQuery.value) return [] // hide folders during search
  return driveFolders.value
})

const filteredFiles = computed(() => {
  let result = driveFiles.value

  if (props.filterType === 'images') {
    result = result.filter(f => isImage(f))
  } else if (props.filterType === 'documents') {
    result = result.filter(f => isDocument(f))
  }

  if (searchQuery.value) {
    const q = searchQuery.value.toLowerCase()
    result = result.filter(f => getFileName(f).toLowerCase().includes(q))
  }

  return result
})

const filteredItems = computed(() => [...filteredFolders.value, ...filteredFiles.value])

// ========================================
// DATA LOADING
// ========================================

async function fetchDriveContents(folderId = null) {
  loadingDrive.value = true
  try {
    const params = folderId ? { folder_id: folderId } : {}
    const response = await api.get('/drive', { params })
    if (response.data.success) {
      driveFolders.value = response.data.data.folders || []
      driveFiles.value = response.data.data.files || []
    }
  } catch (e) {
    console.error('Failed to fetch drive contents:', e)
  } finally {
    loadingDrive.value = false
  }
}

async function navigateTo(folderId) {
  if (folderId === null) {
    breadcrumbs.value = []
  } else if (currentFolderId.value === null) {
    // Going into a subfolder from root
    const folder = driveFolders.value.find(f => f.id === folderId)
    if (folder) breadcrumbs.value.push({ id: folder.id, name: folder.name })
  } else {
    // Check if navigating back in breadcrumb
    const idx = breadcrumbs.value.findIndex(c => c.id === folderId)
    if (idx >= 0) {
      breadcrumbs.value = breadcrumbs.value.slice(0, idx + 1)
    } else {
      const folder = driveFolders.value.find(f => f.id === folderId)
      if (folder) breadcrumbs.value.push({ id: folder.id, name: folder.name })
    }
  }
  currentFolderId.value = folderId
  await fetchDriveContents(folderId)
}

function debouncedSearch() {
  clearTimeout(searchTimeout)
  searchTimeout = setTimeout(() => {
    // Just local filter, no API call
  }, 200)
}

// ========================================
// SELECTION
// ========================================

function toggleFileSelection(file) {
  const idx = selectedFiles.value.findIndex(f => f.id === file.id)
  if (idx >= 0) {
    selectedFiles.value.splice(idx, 1)
  } else {
    selectedFiles.value.push(file)
  }
}

function isFileSelected(file) {
  return selectedFiles.value.some(f => f.id === file.id)
}

const allFilesSelected = computed(() => {
  if (filteredFiles.value.length === 0) return false
  return filteredFiles.value.every(f => selectedFiles.value.some(s => s.id === f.id))
})

function toggleSelectAll() {
  if (allFilesSelected.value) {
    // Deselect all files currently shown
    const visibleIds = new Set(filteredFiles.value.map(f => f.id))
    selectedFiles.value = selectedFiles.value.filter(f => !visibleIds.has(f.id))
  } else {
    // Select all files currently shown (avoid duplicates)
    const alreadySelected = new Set(selectedFiles.value.map(f => f.id))
    for (const file of filteredFiles.value) {
      if (!alreadySelected.has(file.id)) {
        selectedFiles.value.push(file)
      }
    }
  }
}

function selectFolder(folder) {
  // Build the full path from breadcrumbs + current folder name
  const pathParts = breadcrumbs.value.map(b => b.name)
  pathParts.push(folder.name)
  
  emit('select-folder', {
    id: folder.id,
    name: folder.name,
    path: pathParts.join(' / ')
  })
}

function confirmSelection() {
  emit('select', selectedFiles.value)
}

// ========================================
// HELPERS
// ========================================

function getFileName(file) {
  return file.original_name || file.name || file.filename || ''
}

function isImage(file) {
  // Check mime_type first, then fallback to extension from any name field
  if (file.mime_type?.startsWith('image/')) return true
  const name = file.original_name || file.name || file.filename || ''
  const ext = name.split('.').pop().toLowerCase()
  return ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'bmp', 'ico', 'avif'].includes(ext)
}

function isDocument(file) {
  const ext = getExt(file)
  return ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt', 'csv', 'odt', 'ods', 'odp'].includes(ext)
}

function getExt(file) {
  const name = file.original_name || file.name || file.filename || ''
  return name.split('.').pop().toLowerCase()
}

function getFileIcon(file) {
  if (isImage(file)) return 'image'
  const ext = getExt(file)
  if (['pdf'].includes(ext)) return 'picture_as_pdf'
  if (['doc', 'docx', 'odt', 'txt'].includes(ext)) return 'description'
  if (['xls', 'xlsx', 'ods', 'csv'].includes(ext)) return 'table_chart'
  if (['ppt', 'pptx', 'odp'].includes(ext)) return 'slideshow'
  if (['zip', 'rar', '7z', 'tar', 'gz'].includes(ext)) return 'folder_zip'
  if (['mp4', 'webm', 'mov', 'avi'].includes(ext)) return 'videocam'
  if (['mp3', 'wav', 'ogg', 'flac'].includes(ext)) return 'audio_file'
  return 'insert_drive_file'
}

function getFileIconColor(file) {
  if (isImage(file)) return 'text-green-500'
  const ext = getExt(file)
  if (['pdf'].includes(ext)) return 'text-red-500'
  if (['doc', 'docx', 'odt', 'txt'].includes(ext)) return 'text-blue-500'
  if (['xls', 'xlsx', 'ods', 'csv'].includes(ext)) return 'text-emerald-500'
  return 'text-surface-400'
}

const thumbnailCache = ref({})

function getPreviewUrl(file) {
  if (!file?.id) return ''
  const cached = thumbnailCache.value[file.id]
  if (cached && cached !== 'loading') return cached
  if (cached === 'loading') return ''
  
  // Start authenticated fetch
  thumbnailCache.value[file.id] = 'loading'
  const token = getToken('webmail_token')
  fetch(`${api.defaults.baseURL}/drive/files/${file.id}/thumbnail`, {
    headers: { Authorization: `Bearer ${token}` }
  }).then(resp => {
    if (resp.ok) return resp.blob()
    throw new Error('Failed')
  }).then(blob => {
    thumbnailCache.value = { ...thumbnailCache.value, [file.id]: URL.createObjectURL(blob) }
  }).catch(() => {
    thumbnailCache.value = { ...thumbnailCache.value, [file.id]: '' }
  })
  return ''
}

function formatSize(bytes) {
  if (!bytes) return ''
  if (bytes < 1024) return bytes + ' B'
  if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB'
  return (bytes / (1024 * 1024)).toFixed(1) + ' MB'
}

onMounted(() => {
  fetchDriveContents()
})
</script>

