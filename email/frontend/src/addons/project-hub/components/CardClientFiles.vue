<script setup>
import { ref, watch, onMounted, onBeforeUnmount } from 'vue'
import api from '@/services/api'
import { useBoardsStore } from '@/addons/kanban-boards/stores/boards'
import { useToastStore } from '@/stores/toast'

const props = defineProps({
  cardId: { type: Number, required: true }
})

const emit = defineEmits(['attached'])

const boardsStore = useBoardsStore()
const toast = useToastStore()
const clientName = ref(null)
const files = ref([])
const subfolders = ref([])
const loading = ref(false)
const expanded = ref(true)
const attaching = ref(null)
const thumbs = ref({})
const expandedFolders = ref(new Set())

const imageExts = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'bmp']

function isImage(file) {
  if (file.mime_type?.startsWith('image/')) return true
  const ext = (file.original_name || '').split('.').pop()?.toLowerCase()
  return imageExts.includes(ext)
}

const fileIcons = {
  'application/pdf': 'picture_as_pdf',
  'image/': 'image',
  'video/': 'movie',
  'application/vnd.openxmlformats-officedocument.spreadsheetml': 'table_chart',
  'application/vnd.ms-excel': 'table_chart',
  'application/vnd.openxmlformats-officedocument.wordprocessingml': 'description',
  'application/msword': 'description',
  'application/vnd.openxmlformats-officedocument.presentationml': 'slideshow',
}

function getIcon(mime) {
  if (!mime) return 'insert_drive_file'
  for (const [key, icon] of Object.entries(fileIcons)) {
    if (mime.startsWith(key)) return icon
  }
  return 'insert_drive_file'
}

function formatSize(bytes) {
  if (!bytes) return ''
  if (bytes < 1024) return bytes + ' B'
  if (bytes < 1048576) return (bytes / 1024).toFixed(1) + ' KB'
  return (bytes / 1048576).toFixed(1) + ' MB'
}

function toggleFolder(folderId) {
  if (expandedFolders.value.has(folderId)) {
    expandedFolders.value.delete(folderId)
  } else {
    expandedFolders.value.add(folderId)
  }
  expandedFolders.value = new Set(expandedFolders.value)
}

function countAllFiles(folder) {
  let count = folder.files?.length || 0
  for (const sf of (folder.subfolders || [])) {
    count += countAllFiles(sf)
  }
  return count
}

async function loadThumb(file) {
  if (thumbs.value[file.id] || !isImage(file)) return
  thumbs.value[file.id] = 'loading'
  try {
    const res = await api.get(`/drive/files/${file.id}/preview`, { responseType: 'blob' })
    if (res.data) thumbs.value[file.id] = URL.createObjectURL(res.data)
  } catch {
    thumbs.value[file.id] = 'error'
  }
}

function loadVisibleThumbs(fileList) {
  for (const f of fileList) {
    if (isImage(f)) loadThumb(f)
  }
}

async function load() {
  loading.value = true
  try {
    const { data } = await api.get(`/project-hub/cards/${props.cardId}/client-files`)
    clientName.value = data.data?.client_name || null
    files.value = data.data?.files || []
    subfolders.value = data.data?.subfolders || []
    if (expanded.value) loadVisibleThumbs(files.value)
  } catch {
    // silently fail
  } finally {
    loading.value = false
  }
}

async function attachFile(file) {
  attaching.value = file.id
  try {
    await boardsStore.addDriveAttachment(props.cardId, file.id, file.original_name)
    toast.success(`Attached ${file.original_name}`)
    emit('attached')
  } catch {
    toast.error('Failed to attach file')
  } finally {
    attaching.value = null
  }
}

watch(expanded, (v) => { if (v) loadVisibleThumbs(files.value) })

onMounted(load)

onBeforeUnmount(() => {
  for (const url of Object.values(thumbs.value)) {
    if (url && typeof url === 'string' && url.startsWith('blob:')) URL.revokeObjectURL(url)
  }
})
</script>

<template>
  <div v-if="clientName && (files.length || subfolders.length)" class="bg-white dark:bg-surface-800 rounded-xl p-4 shadow-sm border border-surface-200/60 dark:border-surface-700/60 space-y-3">
    <button
      @click="expanded = !expanded"
      class="flex items-center justify-between w-full"
    >
      <h3 class="text-sm font-semibold text-surface-700 dark:text-surface-200 flex items-center gap-2">
        <span class="material-symbols-rounded text-lg text-amber-500">folder_shared</span>
        {{ clientName }} Files
        <span class="text-xs text-surface-400 font-normal">({{ files.length + subfolders.reduce((a, sf) => a + countAllFiles(sf), 0) }})</span>
      </h3>
      <span class="material-symbols-rounded text-lg text-surface-400 transition-transform" :class="expanded ? 'rotate-180' : ''">expand_more</span>
    </button>

    <template v-if="expanded">
      <!-- Recursive subfolder tree -->
      <template v-for="sf in subfolders" :key="sf.id">
        <SubfolderNode
          :folder="sf"
          :depth="0"
          :expanded-folders="expandedFolders"
          :thumbs="thumbs"
          :attaching="attaching"
          @toggle="toggleFolder"
          @attach="attachFile"
          @load-thumb="loadThumb"
        />
      </template>

      <!-- Root-level files grid -->
      <div v-if="files.length" class="grid grid-cols-2 md:grid-cols-3 gap-3">
        <div
          v-for="file in files"
          :key="file.id"
          class="group relative border border-surface-200 dark:border-surface-700 rounded-xl overflow-hidden hover:border-primary-300 dark:hover:border-primary-600 transition-colors"
        >
          <div
            v-if="isImage(file)"
            class="aspect-[4/3] bg-surface-100 dark:bg-surface-700 flex items-center justify-center overflow-hidden"
          >
            <img
              v-if="thumbs[file.id] && thumbs[file.id] !== 'loading' && thumbs[file.id] !== 'error'"
              :src="thumbs[file.id]"
              :alt="file.original_name"
              class="w-full h-full object-cover"
            />
            <span v-else-if="thumbs[file.id] === 'loading'" class="material-symbols-rounded text-2xl text-surface-300 animate-pulse">image</span>
            <span v-else class="material-symbols-rounded text-2xl text-surface-300">image</span>
          </div>

          <div
            v-else
            class="aspect-[4/3] bg-surface-50 dark:bg-surface-800 flex flex-col items-center justify-center gap-1.5"
          >
            <span class="material-symbols-rounded text-3xl text-surface-300 dark:text-surface-500">{{ getIcon(file.mime_type) }}</span>
            <span class="text-[10px] text-surface-400 uppercase font-medium">
              {{ (file.original_name || '').split('.').pop()?.toUpperCase() || 'FILE' }}
            </span>
          </div>

          <div class="px-2.5 py-2 border-t border-surface-100 dark:border-surface-700">
            <p class="text-xs font-medium text-surface-700 dark:text-surface-300 truncate">{{ file.original_name }}</p>
            <p class="text-[10px] text-surface-400 mt-0.5">{{ formatSize(file.size) }}</p>
          </div>

          <div class="absolute inset-0 bg-black/0 group-hover:bg-black/20 transition-colors flex items-center justify-center">
            <button
              @click.stop="attachFile(file)"
              :disabled="attaching === file.id"
              class="px-3 py-1.5 rounded-full text-xs font-medium bg-white dark:bg-surface-800 text-primary-600 dark:text-primary-400 shadow-lg opacity-0 group-hover:opacity-100 transition-all disabled:opacity-50 flex items-center gap-1"
            >
              <span v-if="attaching === file.id" class="material-symbols-rounded text-sm animate-spin">progress_activity</span>
              <template v-else>
                <span class="material-symbols-rounded text-sm">attach_file</span>
                Attach
              </template>
            </button>
          </div>
        </div>
      </div>

      <div v-if="!files.length && !subfolders.length" class="text-center py-6">
        <span class="material-symbols-rounded text-3xl text-surface-300 dark:text-surface-600">folder_off</span>
        <p class="text-xs text-surface-400 mt-2">No files in this client folder</p>
      </div>
    </template>
  </div>
</template>

<script>
import SubfolderNode from './SubfolderNode.vue'
export default { components: { SubfolderNode } }
</script>
