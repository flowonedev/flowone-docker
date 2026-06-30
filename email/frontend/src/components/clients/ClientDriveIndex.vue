<script setup>
import { ref, computed, watch, onMounted } from 'vue'
import api from '@/services/api'

const props = defineProps({
  clientId: {
    type: Number,
    required: true
  },
  // Currently linked drive folder id; watched so the panel reloads
  // after the parent links/unlinks a folder.
  driveFolderId: {
    type: [Number, String],
    default: null
  }
})

const emit = defineEmits(['change-folder'])

// State
const loading = ref(false)
const driveData = ref(null)
const expandedFolders = ref({})
const expandedSection = ref(true)

// Load data
async function loadDriveIndex() {
  loading.value = true
  try {
    const response = await api.get(`/clients/${props.clientId}/drive-index`)
    if (response.data.success) {
      driveData.value = response.data.data
    }
  } catch (error) {
    console.error('Failed to load drive index:', error)
  } finally {
    loading.value = false
  }
}

function toggleFolder(folderId) {
  expandedFolders.value[folderId] = !expandedFolders.value[folderId]
}

function formatSize(bytes) {
  if (!bytes || bytes === 0) return '0 B'
  const units = ['B', 'KB', 'MB', 'GB']
  let i = 0
  while (bytes >= 1024 && i < units.length - 1) {
    bytes /= 1024
    i++
  }
  return `${bytes.toFixed(i > 0 ? 1 : 0)} ${units[i]}`
}

function getFileIcon(mimeType) {
  if (!mimeType) return 'description'
  
  if (mimeType.startsWith('image/')) return 'image'
  if (mimeType.startsWith('video/')) return 'movie'
  if (mimeType.startsWith('audio/')) return 'audio_file'
  if (mimeType.includes('pdf')) return 'picture_as_pdf'
  if (mimeType.includes('word') || mimeType.includes('document')) return 'description'
  if (mimeType.includes('sheet') || mimeType.includes('excel')) return 'table_chart'
  if (mimeType.includes('presentation') || mimeType.includes('powerpoint')) return 'slideshow'
  if (mimeType.includes('zip') || mimeType.includes('archive') || mimeType.includes('compressed')) return 'folder_zip'
  if (mimeType.includes('text/')) return 'article'
  
  return 'description'
}

function getFileIconColor(mimeType) {
  if (!mimeType) return 'text-surface-400'
  
  if (mimeType.startsWith('image/')) return 'text-pink-500'
  if (mimeType.startsWith('video/')) return 'text-purple-500'
  if (mimeType.startsWith('audio/')) return 'text-orange-500'
  if (mimeType.includes('pdf')) return 'text-red-500'
  if (mimeType.includes('word') || mimeType.includes('document')) return 'text-blue-500'
  if (mimeType.includes('sheet') || mimeType.includes('excel')) return 'text-green-500'
  if (mimeType.includes('presentation') || mimeType.includes('powerpoint')) return 'text-amber-500'
  
  return 'text-surface-400'
}

watch(() => props.clientId, () => {
  loadDriveIndex()
})

// Reload after the linked folder changes (link/unlink/relink in parent)
watch(() => props.driveFolderId, () => {
  loadDriveIndex()
})

onMounted(() => {
  loadDriveIndex()
})
</script>

<template>
  <div class="client-drive-index">
    <!-- Header -->
    <div 
      class="flex items-center justify-between cursor-pointer"
      @click="expandedSection = !expandedSection"
    >
      <div class="flex items-center gap-2">
        <span class="material-symbols-rounded text-teal-500">folder_open</span>
        <h3 class="text-sm font-semibold text-surface-900 dark:text-surface-100">Indexed Drive Files</h3>
      </div>
      <div class="flex items-center gap-1">
        <button
          type="button"
          @click.stop="emit('change-folder')"
          class="p-1 rounded-md text-surface-400 hover:text-teal-600 hover:bg-teal-50 dark:hover:bg-teal-500/10 transition-colors"
          title="Change linked Drive folder"
        >
          <span class="material-symbols-rounded text-lg">folder_managed</span>
        </button>
        <span 
          class="material-symbols-rounded text-surface-400 transition-transform"
          :class="{ 'rotate-180': !expandedSection }"
        >
          expand_more
        </span>
      </div>
    </div>
    
    <div v-if="expandedSection" class="mt-4">
      <!-- Loading -->
      <div v-if="loading" class="flex items-center justify-center py-6">
        <span class="material-symbols-rounded text-xl text-surface-400 animate-spin">progress_activity</span>
      </div>
      
      <!-- No folder linked -->
      <div v-else-if="!driveData?.has_folder" class="text-center py-6">
        <span class="material-symbols-rounded text-3xl text-surface-300 dark:text-surface-600">folder_off</span>
        <p class="mt-2 text-sm text-surface-500">No Drive folder linked</p>
        <button
          type="button"
          @click="emit('change-folder')"
          class="mt-3 inline-flex items-center gap-2 px-3 py-1.5 rounded-lg text-sm text-teal-600 dark:text-teal-400 border border-teal-200 dark:border-teal-500/30 hover:bg-teal-50 dark:hover:bg-teal-500/10 transition-colors"
        >
          <span class="material-symbols-rounded text-base">add_link</span>
          Link Drive folder
        </button>
      </div>
      
      <!-- Drive Index -->
      <template v-else>
        <!-- Folder Info Banner -->
        <div class="p-3 bg-teal-50 dark:bg-teal-500/10 rounded-xl mb-4">
          <div class="flex items-center gap-3">
            <div class="w-10 h-10 rounded-lg bg-teal-500/20 flex items-center justify-center">
              <span class="material-symbols-rounded text-xl text-teal-600 dark:text-teal-400">folder</span>
            </div>
            <div class="flex-1 min-w-0">
              <p class="text-sm font-medium text-teal-700 dark:text-teal-300 truncate">
                {{ driveData.folder.name }}
              </p>
              <p class="text-xs text-teal-600/70 dark:text-teal-400/70">
                {{ driveData.stats.total_folders }} folders, {{ driveData.stats.total_files }} files
                <span v-if="driveData.stats.total_size > 0" class="ml-1">
                  ({{ formatSize(driveData.stats.total_size) }})
                </span>
              </p>
            </div>
          </div>
        </div>
        
        <!-- Folder Tree -->
        <div v-if="driveData.tree.length > 0" class="space-y-1 max-h-80 overflow-y-auto">
          <template v-for="item in driveData.tree" :key="item.id">
            <!-- Folder -->
            <div v-if="item.type === 'folder'">
              <div 
                class="flex items-center gap-2 p-2 rounded-lg hover:bg-surface-50 dark:hover:bg-surface-700/50 cursor-pointer transition-colors"
                @click="toggleFolder(item.id)"
              >
                <span 
                  class="material-symbols-rounded text-base text-surface-400 transition-transform"
                  :class="{ 'rotate-90': expandedFolders[item.id] }"
                >
                  chevron_right
                </span>
                <span 
                  class="material-symbols-rounded text-lg"
                  :style="{ color: item.color || '#14B8A6' }"
                >
                  folder
                </span>
                <span class="text-sm text-surface-900 dark:text-surface-100 flex-1 truncate">
                  {{ item.name }}
                </span>
                <span class="text-xs text-surface-400">
                  {{ item.file_count }} files
                </span>
              </div>
              
              <!-- Expanded folder contents -->
              <div 
                v-if="expandedFolders[item.id]"
                class="ml-6 pl-4 border-l border-surface-200 dark:border-surface-700 space-y-1 mt-1"
              >
                <!-- Files in folder -->
                <div 
                  v-for="file in item.files"
                  :key="file.id"
                  class="flex items-center gap-2 p-2 rounded-lg hover:bg-surface-50 dark:hover:bg-surface-700/50 transition-colors"
                >
                  <span :class="['material-symbols-rounded text-lg', getFileIconColor(file.mime_type)]">
                    {{ getFileIcon(file.mime_type) }}
                  </span>
                  <span class="text-sm text-surface-700 dark:text-surface-300 flex-1 truncate">
                    {{ file.name }}
                  </span>
                  <span class="text-xs text-surface-400">
                    {{ formatSize(file.size) }}
                  </span>
                </div>
                
                <!-- Subfolders (recursive) -->
                <template v-for="child in item.children" :key="child.id">
                  <div 
                    class="flex items-center gap-2 p-2 rounded-lg hover:bg-surface-50 dark:hover:bg-surface-700/50 cursor-pointer transition-colors"
                    @click="toggleFolder(child.id)"
                  >
                    <span 
                      class="material-symbols-rounded text-base text-surface-400 transition-transform"
                      :class="{ 'rotate-90': expandedFolders[child.id] }"
                    >
                      chevron_right
                    </span>
                    <span 
                      class="material-symbols-rounded text-lg"
                      :style="{ color: child.color || '#14B8A6' }"
                    >
                      folder
                    </span>
                    <span class="text-sm text-surface-900 dark:text-surface-100 flex-1 truncate">
                      {{ child.name }}
                    </span>
                    <span class="text-xs text-surface-400">
                      {{ child.file_count }} files
                    </span>
                  </div>
                  
                  <!-- Child folder files -->
                  <div 
                    v-if="expandedFolders[child.id]"
                    class="ml-6 pl-4 border-l border-surface-200 dark:border-surface-700 space-y-1 mt-1"
                  >
                    <div 
                      v-for="file in child.files"
                      :key="file.id"
                      class="flex items-center gap-2 p-2 rounded-lg hover:bg-surface-50 dark:hover:bg-surface-700/50 transition-colors"
                    >
                      <span :class="['material-symbols-rounded text-lg', getFileIconColor(file.mime_type)]">
                        {{ getFileIcon(file.mime_type) }}
                      </span>
                      <span class="text-sm text-surface-700 dark:text-surface-300 flex-1 truncate">
                        {{ file.name }}
                      </span>
                      <span class="text-xs text-surface-400">
                        {{ formatSize(file.size) }}
                      </span>
                    </div>
                  </div>
                </template>
                
                <!-- Empty folder -->
                <div v-if="item.files.length === 0 && item.children.length === 0" class="text-xs text-surface-400 italic p-2">
                  Empty folder
                </div>
              </div>
            </div>
            
            <!-- Root level file -->
            <div 
              v-else
              class="flex items-center gap-2 p-2 rounded-lg hover:bg-surface-50 dark:hover:bg-surface-700/50 transition-colors"
            >
              <span class="w-4"></span>
              <span :class="['material-symbols-rounded text-lg', getFileIconColor(item.mime_type)]">
                {{ getFileIcon(item.mime_type) }}
              </span>
              <span class="text-sm text-surface-700 dark:text-surface-300 flex-1 truncate">
                {{ item.name }}
              </span>
              <span class="text-xs text-surface-400">
                {{ formatSize(item.size) }}
              </span>
            </div>
          </template>
        </div>
        
        <!-- Empty tree -->
        <div v-else class="text-center py-4">
          <p class="text-sm text-surface-500">Folder is empty</p>
        </div>
      </template>
    </div>
  </div>
</template>

<style scoped>
.client-drive-index {
  @apply bg-white dark:bg-surface-800 rounded-xl border border-surface-200 dark:border-surface-700 p-4;
}
</style>

