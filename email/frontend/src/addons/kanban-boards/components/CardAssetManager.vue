<script setup>
import { ref, computed, watch, onMounted } from 'vue'
import api from '@/services/api'
import { useToastStore } from '@/stores/toast'
import { useBoardsStore } from '@/addons/kanban-boards/stores/boards'

const props = defineProps({
  cardId: { type: Number, required: true },
  attachments: { type: Array, default: () => [] },
})

const emit = defineEmits(['refresh', 'upload', 'drive-picker', 'delete-attachment', 'preview', 'set-cover'])

const toast = useToastStore()
const boardsStore = useBoardsStore()

const folders = ref([])
const currentFolderId = ref(null)
const loading = ref(false)
const showNewFolder = ref(false)
const newFolderName = ref('')
const renamingId = ref(null)
const renameInput = ref('')
const contextMenu = ref(null)
const syncDrive = ref(true)

const breadcrumb = computed(() => {
  const trail = [{ id: null, name: 'All Assets' }]
  if (!currentFolderId.value) return trail
  const buildPath = (id) => {
    const f = folders.value.find(fl => fl.id === id)
    if (!f) return
    if (f.parent_id) buildPath(f.parent_id)
    trail.push({ id: f.id, name: f.name })
  }
  buildPath(currentFolderId.value)
  return trail
})

const currentSubfolders = computed(() =>
  folders.value
    .filter(f => f.parent_id === currentFolderId.value)
    .sort((a, b) => a.position - b.position || a.name.localeCompare(b.name))
)

const currentFiles = computed(() =>
  props.attachments.filter(a => (a.asset_folder_id || null) === currentFolderId.value)
)

const folderFileCounts = computed(() => {
  const counts = {}
  const countRecursive = (fid) => {
    let c = props.attachments.filter(a => a.asset_folder_id === fid).length
    for (const sub of folders.value.filter(f => f.parent_id === fid)) {
      c += countRecursive(sub.id)
    }
    return c
  }
  for (const f of folders.value) {
    counts[f.id] = countRecursive(f.id)
  }
  return counts
})

const totalCount = computed(() => props.attachments.length)

async function loadFolders() {
  loading.value = true
  try {
    const { data } = await api.get(`/boards/cards/${props.cardId}/asset-folders`)
    folders.value = data.data?.folders || []
  } catch { /* silent */ } finally {
    loading.value = false
  }
}

async function createFolder() {
  const name = newFolderName.value.trim()
  if (!name) return
  try {
    const { data } = await api.post(`/boards/cards/${props.cardId}/asset-folders`, {
      name,
      parent_id: currentFolderId.value,
      sync_drive: syncDrive.value,
    })
    if (data.data?.folder) folders.value.push(data.data.folder)
    newFolderName.value = ''
    showNewFolder.value = false
  } catch {
    toast.error('Failed to create folder')
  }
}

async function renameFolder(id) {
  const name = renameInput.value.trim()
  if (!name) return
  try {
    await api.put(`/boards/asset-folders/${id}`, { name })
    const f = folders.value.find(fl => fl.id === id)
    if (f) f.name = name
    renamingId.value = null
  } catch {
    toast.error('Failed to rename folder')
  }
}

async function deleteFolder(id) {
  if (!confirm('Delete this folder? Files will be moved to root.')) return
  try {
    await api.delete(`/boards/asset-folders/${id}`)
    folders.value = folders.value.filter(f => f.id !== id)
    if (currentFolderId.value === id) currentFolderId.value = null
    emit('refresh')
  } catch {
    toast.error('Failed to delete folder')
  }
}

async function moveFile(attachmentId, targetFolderId) {
  try {
    await api.put(`/boards/attachments/${attachmentId}/move`, { folder_id: targetFolderId })
    emit('refresh')
  } catch {
    toast.error('Failed to move file')
  }
}

function startRename(folder) {
  renamingId.value = folder.id
  renameInput.value = folder.name
  contextMenu.value = null
}

function openContext(e, folder) {
  e.preventDefault()
  contextMenu.value = { x: e.clientX, y: e.clientY, folder }
}

function closeContext() {
  contextMenu.value = null
}

function navigateTo(folderId) {
  currentFolderId.value = folderId
}

function isImage(att) {
  if (att.mime_type?.startsWith('image/')) return true
  const ext = (att.name || att.original_name || '').split('.').pop()?.toLowerCase()
  return ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'bmp'].includes(ext)
}

function getIcon(att) {
  if (att.type === 'url') return 'link'
  const mime = att.mime_type || ''
  if (mime.includes('pdf')) return 'picture_as_pdf'
  if (mime.includes('spreadsheet') || mime.includes('csv') || mime.includes('excel')) return 'table_chart'
  if (mime.includes('document') || mime.includes('word')) return 'description'
  if (mime.includes('video')) return 'movie'
  return 'insert_drive_file'
}

function formatSize(bytes) {
  if (!bytes) return ''
  if (bytes < 1024) return bytes + ' B'
  if (bytes < 1048576) return (bytes / 1024).toFixed(1) + ' KB'
  return (bytes / 1048576).toFixed(1) + ' MB'
}

function onDragStart(e, attachmentId) {
  e.dataTransfer.setData('text/plain', String(attachmentId))
  e.dataTransfer.effectAllowed = 'move'
}

function onFolderDrop(e, folderId) {
  e.preventDefault()
  const attId = parseInt(e.dataTransfer.getData('text/plain'))
  if (attId) moveFile(attId, folderId)
}

function onRootDrop(e) {
  e.preventDefault()
  const attId = parseInt(e.dataTransfer.getData('text/plain'))
  if (attId) moveFile(attId, null)
}

function allowDrop(e) {
  e.preventDefault()
  e.dataTransfer.dropEffect = 'move'
}

onMounted(loadFolders)
watch(() => props.cardId, loadFolders)

defineExpose({ loadFolders, currentFolderId })
</script>

<template>
  <div
    class="bg-white dark:bg-surface-800 rounded-xl p-4 shadow-sm border border-surface-200/60 dark:border-surface-700/60 space-y-4"
    @click="closeContext"
  >
    <!-- Header -->
    <div class="flex items-center justify-between">
      <h3 class="text-sm font-semibold text-surface-700 dark:text-surface-200 flex items-center gap-2">
        <span class="material-symbols-rounded text-lg">folder_open</span>
        All Assets
        <span v-if="totalCount" class="text-xs text-surface-400 font-normal">({{ totalCount }})</span>
      </h3>
      <div class="flex items-center gap-1.5">
        <button
          @click="showNewFolder = !showNewFolder"
          class="text-xs px-2.5 py-1.5 rounded-full bg-surface-100 dark:bg-surface-700 text-surface-600 dark:text-surface-300 hover:bg-surface-200 dark:hover:bg-surface-600 transition-colors flex items-center gap-1"
        >
          <span class="material-symbols-rounded text-sm">create_new_folder</span>
          Folder
        </button>
        <slot name="add-button" />
      </div>
    </div>

    <!-- Breadcrumb -->
    <nav v-if="currentFolderId" class="flex items-center gap-1 text-xs text-surface-500 overflow-x-auto">
      <template v-for="(crumb, i) in breadcrumb" :key="crumb.id ?? 'root'">
        <span v-if="i > 0" class="material-symbols-rounded text-xs text-surface-300">chevron_right</span>
        <button
          @click="navigateTo(crumb.id)"
          class="shrink-0 px-1.5 py-0.5 rounded hover:bg-surface-100 dark:hover:bg-surface-700 transition-colors"
          :class="i === breadcrumb.length - 1 ? 'font-semibold text-surface-700 dark:text-surface-200' : ''"
        >
          {{ crumb.name }}
        </button>
      </template>
    </nav>

    <!-- New folder form -->
    <div v-if="showNewFolder" class="flex items-center gap-2">
      <span class="material-symbols-rounded text-lg text-amber-500">folder</span>
      <input
        v-model="newFolderName"
        @keydown.enter="createFolder"
        @keydown.escape="showNewFolder = false"
        placeholder="Folder name..."
        class="flex-1 text-sm px-3 py-1.5 rounded-lg border border-surface-200 dark:border-surface-600 bg-surface-50 dark:bg-surface-700 text-surface-700 dark:text-surface-200 focus:outline-none focus:ring-2 focus:ring-primary-500/30"
        autofocus
      />
      <label class="flex items-center gap-1 text-[10px] text-surface-400 shrink-0">
        <input type="checkbox" v-model="syncDrive" class="sr-only peer" />
        <span class="relative w-7 h-4 rounded-full bg-surface-300 dark:bg-surface-600 peer-checked:bg-primary-500 transition-colors cursor-pointer">
          <span class="absolute top-0.5 left-0.5 w-3 h-3 rounded-full bg-white shadow transition-transform peer-checked:translate-x-3" :class="syncDrive ? 'translate-x-3' : ''" />
        </span>
        Drive
      </label>
      <button @click="createFolder" :disabled="!newFolderName.trim()" class="px-3 py-1.5 rounded-full bg-primary-500 text-white text-xs font-medium disabled:opacity-40">Create</button>
      <button @click="showNewFolder = false" class="p-1 rounded text-surface-400 hover:text-surface-600"><span class="material-symbols-rounded text-sm">close</span></button>
    </div>

    <!-- Subfolders -->
    <div
      v-if="currentSubfolders.length"
      class="grid grid-cols-2 sm:grid-cols-3 gap-2"
      @dragover="allowDrop"
      @drop.prevent="onRootDrop"
    >
      <div
        v-for="folder in currentSubfolders"
        :key="'folder-' + folder.id"
        class="group relative flex items-center gap-2.5 px-3 py-2.5 rounded-xl border border-surface-200 dark:border-surface-700 hover:border-primary-300 dark:hover:border-primary-600 hover:bg-primary-50/50 dark:hover:bg-primary-500/5 cursor-pointer transition-colors"
        @click="navigateTo(folder.id)"
        @contextmenu="openContext($event, folder)"
        @dragover="allowDrop"
        @drop.stop="onFolderDrop($event, folder.id)"
      >
        <span class="material-symbols-rounded text-xl text-amber-500">folder</span>
        <div v-if="renamingId === folder.id" class="flex-1 min-w-0" @click.stop>
          <input
            v-model="renameInput"
            @keydown.enter="renameFolder(folder.id)"
            @keydown.escape="renamingId = null"
            @blur="renameFolder(folder.id)"
            class="w-full text-sm px-1 py-0.5 rounded border border-primary-300 bg-white dark:bg-surface-700 focus:outline-none"
            autofocus
          />
        </div>
        <div v-else class="flex-1 min-w-0">
          <p class="text-sm font-medium text-surface-700 dark:text-surface-200 truncate">{{ folder.name }}</p>
          <p class="text-[10px] text-surface-400">{{ folderFileCounts[folder.id] || 0 }} files</p>
        </div>
        <div class="opacity-0 group-hover:opacity-100 flex items-center gap-0.5 shrink-0 transition-opacity" @click.stop>
          <button @click="startRename(folder)" class="p-0.5 rounded text-surface-400 hover:text-primary-500">
            <span class="material-symbols-rounded text-sm">edit</span>
          </button>
          <button @click="deleteFolder(folder.id)" class="p-0.5 rounded text-surface-400 hover:text-red-500">
            <span class="material-symbols-rounded text-sm">delete</span>
          </button>
        </div>
        <span v-if="folder.drive_folder_id" class="absolute top-1 right-1">
          <span class="material-symbols-rounded text-[10px] text-primary-400" title="Synced to Drive">cloud_done</span>
        </span>
      </div>
    </div>

    <!-- Drop zone hint when no files in current folder -->
    <div
      v-if="currentFiles.length === 0 && currentSubfolders.length === 0 && !loading"
      class="text-center py-12"
      @dragover="allowDrop"
      @drop.prevent="onRootDrop"
    >
      <span class="material-symbols-rounded text-5xl text-surface-300 dark:text-surface-600">folder_off</span>
      <p class="text-sm text-surface-400 mt-3">{{ currentFolderId ? 'This folder is empty' : 'No files attached yet' }}</p>
      <p class="text-xs text-surface-400 mt-1">Add files, images, or links from the card actions menu</p>
    </div>

    <!-- Files grid -->
    <div
      v-if="currentFiles.length"
      class="grid grid-cols-2 md:grid-cols-3 gap-3"
      @dragover="allowDrop"
      @drop.prevent="onRootDrop"
    >
      <div
        v-for="att in currentFiles"
        :key="'att-' + att.id"
        class="group relative border border-surface-200 dark:border-surface-700 rounded-xl overflow-hidden hover:border-primary-300 dark:hover:border-primary-600 transition-colors"
        draggable="true"
        @dragstart="onDragStart($event, att.id)"
      >
        <div
          v-if="isImage(att)"
          class="aspect-[4/3] bg-surface-100 dark:bg-surface-700 flex items-center justify-center overflow-hidden cursor-pointer"
          @click="emit('preview', att)"
        >
          <img
            v-if="att._thumbnailUrl && att._thumbnailUrl !== 'loading'"
            :src="att._thumbnailUrl"
            :alt="att.name || att.original_name"
            class="w-full h-full object-cover"
          />
          <span v-else class="material-symbols-rounded text-3xl text-surface-300">image</span>
        </div>
        <div
          v-else
          class="aspect-[4/3] bg-surface-50 dark:bg-surface-800 flex flex-col items-center justify-center gap-2 cursor-pointer"
          @click="emit('preview', att)"
        >
          <span class="material-symbols-rounded text-3xl text-surface-300 dark:text-surface-500">{{ getIcon(att) }}</span>
          <span class="text-[10px] text-surface-400 uppercase font-medium">
            {{ att.type === 'url' ? 'Link' : (att.name || att.original_name || '').split('.').pop()?.toUpperCase() || 'FILE' }}
          </span>
        </div>

        <div class="px-2.5 py-2 border-t border-surface-100 dark:border-surface-700">
          <p class="text-xs font-medium text-surface-700 dark:text-surface-300 truncate">{{ att.name || att.original_name || 'Untitled' }}</p>
          <p class="text-[10px] text-surface-400 mt-0.5">{{ formatSize(att.size) }}</p>
        </div>

        <div class="absolute top-2 right-2 opacity-0 group-hover:opacity-100 transition-opacity flex gap-1">
          <button
            v-if="att.drive_file_id"
            @click.stop="emit('preview', { ...att, _forceDownload: true })"
            class="p-1 rounded-lg bg-white/90 dark:bg-surface-800/90 shadow text-surface-500 hover:text-primary-500"
            title="Download"
          >
            <span class="material-symbols-rounded text-sm">download</span>
          </button>
          <button
            v-if="isImage(att)"
            @click.stop="emit('set-cover', att)"
            class="p-1 rounded-lg bg-white/90 dark:bg-surface-800/90 shadow text-surface-500 hover:text-primary-500"
            title="Set as cover"
          >
            <span class="material-symbols-rounded text-sm">image</span>
          </button>
          <button
            @click.stop="emit('delete-attachment', att.id)"
            class="p-1 rounded-lg bg-white/90 dark:bg-surface-800/90 shadow text-surface-500 hover:text-red-500"
            title="Delete"
          >
            <span class="material-symbols-rounded text-sm">delete</span>
          </button>
        </div>
      </div>
    </div>

    <!-- Context menu (for folder right-click) -->
    <Teleport to="body">
      <div
        v-if="contextMenu"
        class="fixed z-[9999] bg-white dark:bg-surface-800 rounded-xl shadow-2xl border border-surface-200 dark:border-surface-700 py-1.5 w-44"
        :style="{ left: contextMenu.x + 'px', top: contextMenu.y + 'px' }"
        @click.stop
      >
        <button @click="startRename(contextMenu.folder)" class="w-full px-3 py-1.5 text-left text-sm text-surface-700 dark:text-surface-300 hover:bg-surface-100 dark:hover:bg-surface-700 flex items-center gap-2">
          <span class="material-symbols-rounded text-base">edit</span> Rename
        </button>
        <button @click="deleteFolder(contextMenu.folder.id)" class="w-full px-3 py-1.5 text-left text-sm text-red-600 hover:bg-red-50 dark:hover:bg-red-500/10 flex items-center gap-2">
          <span class="material-symbols-rounded text-base">delete</span> Delete
        </button>
      </div>
    </Teleport>
  </div>
</template>
