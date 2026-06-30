<template>
  <div class="fixed inset-0 z-[60] flex items-center justify-center bg-black/40" @click.self="$emit('close')">
    <div class="bg-white dark:bg-surface-800 rounded-2xl shadow-2xl w-[720px] max-h-[80vh] flex flex-col overflow-hidden" @click.stop>
      <!-- Header -->
      <div class="flex items-center justify-between px-5 py-3 border-b border-surface-200 dark:border-surface-700">
        <div class="flex items-center gap-2 min-w-0">
          <span class="material-symbols-rounded text-xl text-amber-500">folder</span>
          <h3 class="text-sm font-semibold text-surface-900 dark:text-surface-100 truncate">{{ folderName }}</h3>
        </div>
        <button @click="$emit('close')" class="p-1 rounded-lg hover:bg-surface-100 dark:hover:bg-surface-700 text-surface-400">
          <span class="material-symbols-rounded text-lg">close</span>
        </button>
      </div>

      <!-- Breadcrumb -->
      <div class="flex items-center gap-1 px-5 py-2 border-b border-surface-100 dark:border-surface-700/50 text-xs flex-wrap">
        <button @click="navigateTo(rootFolderId)" class="text-primary-500 hover:underline truncate max-w-[120px]">{{ folderName }}</button>
        <template v-for="(crumb, idx) in breadcrumbs" :key="crumb.id">
          <span class="material-symbols-rounded text-xs text-surface-400">chevron_right</span>
          <button @click="navigateTo(crumb.id)" class="text-primary-500 hover:underline truncate max-w-[120px]">{{ crumb.name }}</button>
        </template>
      </div>

      <!-- View toggle + search -->
      <div class="flex items-center gap-2 px-5 py-2 border-b border-surface-100 dark:border-surface-700/50">
        <div class="relative flex-1">
          <span class="material-symbols-rounded absolute left-3 top-1/2 -translate-y-1/2 text-lg text-surface-400">search</span>
          <input
            v-model="searchQuery"
            placeholder="Search in folder..."
            class="w-full pl-10 pr-4 py-1.5 text-sm rounded-xl border border-surface-200 dark:border-surface-600 bg-surface-50 dark:bg-surface-700 text-surface-900 dark:text-surface-100 focus:ring-2 focus:ring-primary-500"
          />
        </div>
        <div class="flex items-center gap-0.5 bg-surface-100 dark:bg-surface-700 rounded-lg p-0.5">
          <button
            @click="viewMode = 'grid'"
            :class="[
              'p-1.5 rounded-md transition-colors',
              viewMode === 'grid' ? 'bg-white dark:bg-surface-600 shadow-sm text-primary-500' : 'text-surface-400 hover:text-surface-600'
            ]"
          >
            <span class="material-symbols-rounded text-lg">grid_view</span>
          </button>
          <button
            @click="viewMode = 'list'"
            :class="[
              'p-1.5 rounded-md transition-colors',
              viewMode === 'list' ? 'bg-white dark:bg-surface-600 shadow-sm text-primary-500' : 'text-surface-400 hover:text-surface-600'
            ]"
          >
            <span class="material-symbols-rounded text-lg">view_list</span>
          </button>
        </div>
      </div>

      <!-- Content area -->
      <div class="flex-1 overflow-y-auto p-4 min-h-[300px]">
        <!-- Loading -->
        <div v-if="loading" class="flex items-center justify-center h-full py-10">
          <span class="material-symbols-rounded text-2xl text-surface-400 animate-spin">sync</span>
        </div>

        <!-- Empty -->
        <div v-else-if="allItems.length === 0" class="flex flex-col items-center justify-center py-10 text-surface-400">
          <span class="material-symbols-rounded text-4xl">folder_off</span>
          <p class="text-sm mt-2">This folder is empty</p>
        </div>

        <!-- Grid view -->
        <div v-else-if="viewMode === 'grid'" class="grid grid-cols-4 gap-3">
          <!-- Folder cards -->
          <button
            v-for="folder in filteredFolders"
            :key="'f-' + folder.id"
            @click="navigateTo(folder.id)"
            class="flex flex-col items-center gap-2 p-3 rounded-xl hover:bg-surface-50 dark:hover:bg-surface-700/50 transition-colors group"
          >
            <span class="material-symbols-rounded text-4xl text-amber-500">folder</span>
            <span class="text-xs text-surface-900 dark:text-surface-100 truncate w-full text-center">{{ folder.name }}</span>
          </button>

          <!-- File cards -->
          <div
            v-for="file in filteredFiles"
            :key="'file-' + file.id"
            class="flex flex-col rounded-xl border border-surface-200 dark:border-surface-700 overflow-hidden group hover:border-primary-300 dark:hover:border-primary-700 transition-colors cursor-pointer"
            @click="previewFile(file)"
          >
            <!-- Thumbnail / icon area -->
            <div class="aspect-square bg-surface-50 dark:bg-surface-700/30 flex items-center justify-center relative overflow-hidden">
              <img
                v-if="isImage(file) && thumbnails[file.id]"
                :src="thumbnails[file.id]"
                class="w-full h-full object-cover"
                @error="$event.target.style.display='none'"
              />
              <div v-else class="flex flex-col items-center gap-1">
                <span class="material-symbols-rounded text-3xl" :style="{ color: getFileIconColor(file) }">{{ getFileIcon(file) }}</span>
                <span class="text-[9px] text-surface-400 uppercase tracking-wider">{{ getExt(file) }}</span>
              </div>
            </div>
            <div class="px-2 py-1.5">
              <p class="text-[11px] text-surface-900 dark:text-surface-100 truncate font-medium">{{ getFileName(file) }}</p>
              <p class="text-[10px] text-surface-400">{{ formatSize(file.size) }}</p>
            </div>
          </div>
        </div>

        <!-- List view -->
        <div v-else class="space-y-0.5">
          <!-- Folders -->
          <button
            v-for="folder in filteredFolders"
            :key="'f-' + folder.id"
            @click="navigateTo(folder.id)"
            class="w-full flex items-center gap-3 px-3 py-2.5 rounded-xl hover:bg-surface-50 dark:hover:bg-surface-700/50 text-left transition-colors"
          >
            <span class="material-symbols-rounded text-xl text-amber-500">folder</span>
            <span class="flex-1 text-sm text-surface-900 dark:text-surface-100 truncate">{{ folder.name }}</span>
            <span class="material-symbols-rounded text-lg text-surface-400">chevron_right</span>
          </button>

          <!-- Files -->
          <button
            v-for="file in filteredFiles"
            :key="'file-' + file.id"
            @click="previewFile(file)"
            class="w-full flex items-center gap-3 px-3 py-2.5 rounded-xl hover:bg-surface-50 dark:hover:bg-surface-700/50 text-left transition-colors"
          >
            <span class="material-symbols-rounded text-xl" :style="{ color: getFileIconColor(file) }">{{ getFileIcon(file) }}</span>
            <div class="flex-1 min-w-0">
              <p class="text-sm text-surface-900 dark:text-surface-100 truncate">{{ getFileName(file) }}</p>
              <p class="text-xs text-surface-400 mt-0.5">{{ formatSize(file.size) }}</p>
            </div>
            <img
              v-if="isImage(file) && thumbnails[file.id]"
              :src="thumbnails[file.id]"
              class="w-10 h-10 rounded-lg object-cover flex-shrink-0"
              @error="$event.target.style.display='none'"
            />
            <span class="material-symbols-rounded text-lg text-surface-400 opacity-0 group-hover:opacity-100 transition-opacity">visibility</span>
          </button>
        </div>
      </div>

      <!-- Image gallery lightbox (slider with prev/next + thumbnail strip) -->
      <Teleport to="body">
        <Transition name="lightbox-fade">
          <div
            v-if="lightboxOpen"
            class="fixed inset-0 z-[9999] flex items-center justify-center"
            @click.self="closeLightbox"
          >
            <!-- Backdrop -->
            <div class="absolute inset-0 bg-black/90 backdrop-blur-sm" @click="closeLightbox"></div>

            <!-- Top bar -->
            <div class="absolute top-0 left-0 right-0 z-10 flex items-center justify-between px-4 py-3 bg-gradient-to-b from-black/60 to-transparent">
              <div class="flex items-center gap-3">
                <span class="text-white/90 text-sm font-medium">{{ lightboxIndex + 1 }} / {{ imageFiles.length }}</span>
                <span v-if="currentLightboxImage" class="text-white/60 text-xs truncate max-w-[300px]">{{ getFileName(currentLightboxImage) }}</span>
              </div>
              <button
                @click="closeLightbox"
                class="w-9 h-9 rounded-full bg-white/10 hover:bg-white/20 text-white flex items-center justify-center transition-colors"
              >
                <span class="material-symbols-rounded text-xl">close</span>
              </button>
            </div>

            <!-- Main image area -->
            <div class="relative z-[1] w-full h-full flex items-center justify-center px-16 py-16">
              <img
                v-if="currentLightboxImage && lightboxUrls[currentLightboxImage.id]"
                :src="lightboxUrls[currentLightboxImage.id]"
                :alt="getFileName(currentLightboxImage)"
                class="max-w-full max-h-full object-contain rounded-lg shadow-2xl select-none transition-opacity duration-200"
                draggable="false"
              />
              <div v-else class="flex flex-col items-center gap-3 text-white/50">
                <div class="animate-spin w-8 h-8 border-2 border-primary-500 border-t-transparent rounded-full"></div>
                <span class="text-sm">Loading image...</span>
              </div>
            </div>

            <!-- Previous button -->
            <button
              v-if="imageFiles.length > 1"
              @click.stop="prevImage"
              class="absolute left-3 top-1/2 -translate-y-1/2 z-10 w-11 h-11 rounded-full bg-white/10 hover:bg-white/20 text-white flex items-center justify-center transition-all hover:scale-110"
            >
              <span class="material-symbols-rounded text-2xl">chevron_left</span>
            </button>

            <!-- Next button -->
            <button
              v-if="imageFiles.length > 1"
              @click.stop="nextImage"
              class="absolute right-3 top-1/2 -translate-y-1/2 z-10 w-11 h-11 rounded-full bg-white/10 hover:bg-white/20 text-white flex items-center justify-center transition-all hover:scale-110"
            >
              <span class="material-symbols-rounded text-2xl">chevron_right</span>
            </button>

            <!-- Thumbnail strip at bottom -->
            <div v-if="imageFiles.length > 1" class="absolute bottom-0 left-0 right-0 z-10 bg-gradient-to-t from-black/60 to-transparent py-3 px-4">
              <div class="flex items-center justify-center gap-1.5 overflow-x-auto max-w-full py-1 px-2">
                <button
                  v-for="(img, idx) in imageFiles"
                  :key="img.id"
                  @click.stop="lightboxIndex = idx; ensureLightboxImage(img)"
                  class="flex-shrink-0 w-12 h-12 rounded-lg overflow-hidden border-2 transition-all hover:scale-105"
                  :class="idx === lightboxIndex ? 'border-white shadow-lg scale-105' : 'border-transparent opacity-50 hover:opacity-80'"
                >
                  <img
                    v-if="thumbnails[img.id]"
                    :src="thumbnails[img.id]"
                    class="w-full h-full object-cover"
                    draggable="false"
                  />
                  <div v-else class="w-full h-full bg-surface-700 flex items-center justify-center">
                    <span class="material-symbols-rounded text-xs text-surface-400">image</span>
                  </div>
                </button>
              </div>
            </div>
          </div>
        </Transition>
      </Teleport>

      <!-- File preview modal (PDF, DOC, XLS, TXT, etc.) -->
      <MoodFilePreview
        v-if="showFilePreview && filePreviewItem"
        :item="filePreviewItem"
        @close="showFilePreview = false; filePreviewItem = null"
      />

      <!-- Footer -->
      <div class="flex items-center justify-between px-5 py-3 border-t border-surface-200 dark:border-surface-700">
        <span class="text-xs text-surface-400">{{ filteredFolders.length }} folders, {{ filteredFiles.length }} files</span>
        <button @click="$emit('close')" class="px-4 py-2 text-sm rounded-full hover:bg-surface-100 dark:hover:bg-surface-700 text-surface-600 dark:text-surface-400 transition-colors">
          Close
        </button>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, onBeforeUnmount, nextTick } from 'vue'
import api from '@/services/api'
import { getToken } from '@/services/tokenStorage'
import MoodFilePreview from './MoodFilePreview.vue'

const props = defineProps({
  folderId: { type: [Number, String], required: true },
  folderName: { type: String, default: 'Folder' }
})

const emit = defineEmits(['close'])

const loading = ref(false)
const folders = ref([])
const files = ref([])
const searchQuery = ref('')
const viewMode = ref('grid')
const breadcrumbs = ref([])
const currentFolderId = ref(null)
const rootFolderId = ref(null)
const thumbnails = ref({})

// Gallery lightbox state (for images)
const lightboxOpen = ref(false)
const lightboxIndex = ref(0)
const lightboxUrls = ref({})

// File preview state (for non-image files)
const showFilePreview = ref(false)
const filePreviewItem = ref(null)

// Filtered data
const filteredFolders = computed(() => {
  if (!searchQuery.value) return folders.value
  const q = searchQuery.value.toLowerCase()
  return folders.value.filter(f => f.name.toLowerCase().includes(q))
})

const filteredFiles = computed(() => {
  if (!searchQuery.value) return files.value
  const q = searchQuery.value.toLowerCase()
  return files.value.filter(f => getFileName(f).toLowerCase().includes(q))
})

const allItems = computed(() => [...filteredFolders.value, ...filteredFiles.value])

// Navigation
async function fetchContents(folderId) {
  loading.value = true
  try {
    const params = folderId ? { folder_id: folderId } : {}
    const response = await api.get('/drive', { params })
    if (response.data.success) {
      folders.value = response.data.data.folders || []
      files.value = response.data.data.files || []
      
      // Load thumbnails for image files
      for (const file of files.value) {
        if (isImage(file)) loadThumbnail(file)
      }
    }
  } catch (e) {
    console.error('Failed to fetch folder contents:', e)
  } finally {
    loading.value = false
  }
}

function navigateTo(folderId) {
  if (folderId === rootFolderId.value) {
    breadcrumbs.value = []
  } else if (currentFolderId.value === rootFolderId.value) {
    const folder = folders.value.find(f => f.id === folderId)
    if (folder) breadcrumbs.value.push({ id: folder.id, name: folder.name })
  } else {
    const idx = breadcrumbs.value.findIndex(c => c.id === folderId)
    if (idx >= 0) {
      breadcrumbs.value = breadcrumbs.value.slice(0, idx + 1)
    } else {
      const folder = folders.value.find(f => f.id === folderId)
      if (folder) breadcrumbs.value.push({ id: folder.id, name: folder.name })
    }
  }
  currentFolderId.value = folderId
  fetchContents(folderId)
}

// Thumbnails
function loadThumbnail(file) {
  if (thumbnails.value[file.id]) return
  const token = getToken('webmail_token')
  fetch(`${api.defaults.baseURL}/drive/files/${file.id}/thumbnail`, {
    headers: { Authorization: `Bearer ${token}` }
  }).then(resp => {
    if (!resp.ok) throw new Error('Failed')
    return resp.blob()
  }).then(blob => {
    thumbnails.value = { ...thumbnails.value, [file.id]: URL.createObjectURL(blob) }
  }).catch(() => {
    // ignore
  })
}

// Computed: all image files in current folder (for gallery slider)
const imageFiles = computed(() => files.value.filter(f => isImage(f)))

const currentLightboxImage = computed(() => {
  if (!lightboxOpen.value || lightboxIndex.value < 0 || lightboxIndex.value >= imageFiles.value.length) return null
  return imageFiles.value[lightboxIndex.value]
})

// Ensure we have a full-size URL for the lightbox image
async function ensureLightboxImage(file) {
  if (lightboxUrls.value[file.id]) return
  // Use thumbnail first as placeholder
  if (thumbnails.value[file.id]) {
    lightboxUrls.value = { ...lightboxUrls.value, [file.id]: thumbnails.value[file.id] }
  }
  // Load full resolution
  try {
    const token = getToken('webmail_token')
    const resp = await fetch(`${api.defaults.baseURL}/drive/files/${file.id}/download`, {
      headers: { Authorization: `Bearer ${token}` }
    })
    if (resp.ok) {
      const blob = await resp.blob()
      lightboxUrls.value = { ...lightboxUrls.value, [file.id]: URL.createObjectURL(blob) }
    }
  } catch { /* ignore */ }
}

function openLightbox(file) {
  const idx = imageFiles.value.findIndex(f => f.id === file.id)
  if (idx < 0) return
  lightboxIndex.value = idx
  lightboxOpen.value = true
  ensureLightboxImage(file)
  nextTick(() => document.addEventListener('keydown', onLightboxKeydown))
}

function closeLightbox() {
  lightboxOpen.value = false
  document.removeEventListener('keydown', onLightboxKeydown)
}

function nextImage() {
  if (imageFiles.value.length <= 1) return
  lightboxIndex.value = (lightboxIndex.value + 1) % imageFiles.value.length
  ensureLightboxImage(imageFiles.value[lightboxIndex.value])
}

function prevImage() {
  if (imageFiles.value.length <= 1) return
  lightboxIndex.value = (lightboxIndex.value - 1 + imageFiles.value.length) % imageFiles.value.length
  ensureLightboxImage(imageFiles.value[lightboxIndex.value])
}

function onLightboxKeydown(e) {
  if (!lightboxOpen.value) return
  if (e.key === 'Escape') { closeLightbox(); e.stopPropagation() }
  else if (e.key === 'ArrowRight' || e.key === 'ArrowDown') { nextImage(); e.preventDefault() }
  else if (e.key === 'ArrowLeft' || e.key === 'ArrowUp') { prevImage(); e.preventDefault() }
}

// File preview
function previewFile(file) {
  if (isImage(file)) {
    // Open gallery slider at this image
    openLightbox(file)
  } else {
    // Open file preview modal (PDF, DOC, XLS, TXT, etc.)
    filePreviewItem.value = {
      title: getFileName(file),
      drive_file_id: file.id,
      style_data: {
        mime_type: file.mime_type,
        file_size: file.size
      }
    }
    showFilePreview.value = true
  }
}

// Helpers
function getFileName(file) {
  return file.original_name || file.name || file.filename || 'Unnamed'
}

function getExt(file) {
  const name = getFileName(file)
  return name.split('.').pop().toLowerCase()
}

function isImage(file) {
  if (file.mime_type?.startsWith('image/')) return true
  const ext = getExt(file)
  return ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'bmp', 'avif'].includes(ext)
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
  if (['ai', 'psd', 'eps'].includes(ext)) return 'palette'
  return 'insert_drive_file'
}

function getFileIconColor(file) {
  if (isImage(file)) return '#22c55e'
  const ext = getExt(file)
  if (['pdf'].includes(ext)) return '#ef4444'
  if (['doc', 'docx', 'odt', 'txt'].includes(ext)) return '#3b82f6'
  if (['xls', 'xlsx', 'ods', 'csv'].includes(ext)) return '#10b981'
  if (['ppt', 'pptx', 'odp'].includes(ext)) return '#f59e0b'
  if (['ai', 'psd', 'eps'].includes(ext)) return '#f97316'
  return '#94a3b8'
}

function formatSize(bytes) {
  if (!bytes) return ''
  if (bytes < 1024) return bytes + ' B'
  if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB'
  return (bytes / (1024 * 1024)).toFixed(1) + ' MB'
}

// Cleanup blob URLs on unmount
onBeforeUnmount(() => {
  for (const url of Object.values(thumbnails.value)) {
    if (url?.startsWith('blob:')) URL.revokeObjectURL(url)
  }
  for (const url of Object.values(lightboxUrls.value)) {
    if (url?.startsWith('blob:')) URL.revokeObjectURL(url)
  }
  document.removeEventListener('keydown', onLightboxKeydown)
})

onMounted(() => {
  rootFolderId.value = props.folderId
  currentFolderId.value = props.folderId
  fetchContents(props.folderId)
})
</script>

<style scoped>
.lightbox-fade-enter-active,
.lightbox-fade-leave-active {
  transition: opacity 0.2s ease;
}
.lightbox-fade-enter-from,
.lightbox-fade-leave-to {
  opacity: 0;
}
</style>

