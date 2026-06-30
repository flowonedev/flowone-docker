<script setup>
import { ref, computed, watch, onMounted } from 'vue'
import { useProjectHubStore } from '@/addons/project-hub/stores/projectHub'
import { useToastStore } from '@/stores/toast'
import api from '@/services/api'
import {
  fetchFolderFiles,
  addFileToFolder,
  updateFileGroup,
  batchUpdateGroup,
  removeFileFromFolder,
  markFilesSeen,
  getExportUrl,
} from '@/addons/project-hub/services/projectHubFileApi'
import FileGroupContextMenu from './FileGroupContextMenu.vue'
import FolderBoardAttachments from './FolderBoardAttachments.vue'

const hubStore = useProjectHubStore()
const toast = useToastStore()

const files = ref([])
const loading = ref(false)
const uploading = ref(false)
const uploadProgress = ref('')
const searchQuery = ref('')
const sortField = ref('created_at')
const sortDir = ref('desc')
const activeGroup = ref(null)
const selectedIds = ref(new Set())
const lastClickedIndex = ref(null)
const contextMenu = ref({ visible: false, x: 0, y: 0 })
const dragOver = ref(false)

const folderId = computed(() => hubStore.activeFolderId)

const fileGroups = ['General', 'Contract', 'Bills', 'Assets']

const groupCounts = computed(() => {
  const counts = {}
  files.value.forEach(f => {
    counts[f.group_name] = (counts[f.group_name] || 0) + 1
  })
  return counts
})

const filteredFiles = computed(() => {
  let list = [...files.value]

  if (activeGroup.value) {
    list = list.filter(f => f.group_name === activeGroup.value)
  }

  if (searchQuery.value.trim()) {
    const q = searchQuery.value.toLowerCase()
    list = list.filter(f => (f.original_name || '').toLowerCase().includes(q))
  }

  list.sort((a, b) => {
    let va, vb
    if (sortField.value === 'original_name') {
      va = (a.original_name || '').toLowerCase()
      vb = (b.original_name || '').toLowerCase()
    } else if (sortField.value === 'size') {
      va = a.size || 0
      vb = b.size || 0
    } else {
      va = a.created_at || ''
      vb = b.created_at || ''
    }
    if (va < vb) return sortDir.value === 'asc' ? -1 : 1
    if (va > vb) return sortDir.value === 'asc' ? 1 : -1
    return 0
  })

  return list
})

const hasSelection = computed(() => selectedIds.value.size > 0)

watch(folderId, (id) => {
  if (id) loadFiles()
}, { immediate: true })

async function loadFiles() {
  if (!folderId.value) return
  loading.value = true
  try {
    files.value = await fetchFolderFiles(folderId.value)
    await markFilesSeen(folderId.value)
  } catch (err) {
    console.error('[FolderFiles] load error:', err)
  } finally {
    loading.value = false
  }
}

async function handleFileDrop(e) {
  dragOver.value = false
  const droppedFiles = [...(e.dataTransfer?.files || [])]
  if (!droppedFiles.length || !folderId.value) return
  await uploadFiles(droppedFiles)
}

function triggerFileInput() {
  document.getElementById('ph-folder-file-input')?.click()
}

async function handleFileInputChange(e) {
  const picked = [...(e.target.files || [])]
  if (picked.length) await uploadFiles(picked)
  e.target.value = ''
}

async function uploadFiles(fileList) {
  uploading.value = true
  let success = 0
  for (const file of fileList) {
    uploadProgress.value = `Uploading ${file.name}...`
    try {
      const formData = new FormData()
      formData.append('file', file)
      const response = await api.post('/drive/upload', formData, {
        headers: { 'Content-Type': 'multipart/form-data' },
      })
      if (response.data.success) {
        const driveFileId = response.data.data.file.id
        await addFileToFolder(folderId.value, driveFileId, 'General')
        success++
      }
    } catch (err) {
      console.error(`[FolderFiles] upload failed for ${file.name}:`, err)
      toast.error(`Failed to upload ${file.name}`)
    }
  }
  uploading.value = false
  uploadProgress.value = ''
  if (success > 0) {
    toast.success(`${success} file${success > 1 ? 's' : ''} uploaded`)
    await loadFiles()
  }
}

function handleRowClick(file, index, e) {
  if (e.ctrlKey || e.metaKey) {
    const next = new Set(selectedIds.value)
    next.has(file.id) ? next.delete(file.id) : next.add(file.id)
    selectedIds.value = next
    lastClickedIndex.value = index
  } else if (e.shiftKey && lastClickedIndex.value !== null) {
    const start = Math.min(lastClickedIndex.value, index)
    const end = Math.max(lastClickedIndex.value, index)
    const next = new Set(selectedIds.value)
    filteredFiles.value.slice(start, end + 1).forEach(f => next.add(f.id))
    selectedIds.value = next
  } else {
    selectedIds.value = new Set([file.id])
    lastClickedIndex.value = index
  }
}

function handleContextMenu(e) {
  if (!hasSelection.value) return
  e.preventDefault()
  contextMenu.value = { visible: true, x: e.clientX, y: e.clientY }
}

async function assignGroupToSelection(group) {
  const ids = [...selectedIds.value]
  if (!ids.length) return
  try {
    await batchUpdateGroup(ids, group)
    files.value = files.value.map(f =>
      ids.includes(f.id) ? { ...f, group_name: group } : f
    )
    selectedIds.value = new Set()
    toast.success(`${ids.length} file${ids.length > 1 ? 's' : ''} moved to ${group}`)
  } catch (err) {
    toast.error('Failed to update group')
  }
}

async function handleSingleGroupChange(file, group) {
  try {
    await updateFileGroup(file.id, group)
    const idx = files.value.findIndex(f => f.id === file.id)
    if (idx >= 0) files.value[idx] = { ...files.value[idx], group_name: group }
  } catch (err) {
    toast.error('Failed to update group')
  }
}

async function handleRemove(file) {
  try {
    await removeFileFromFolder(file.id)
    files.value = files.value.filter(f => f.id !== file.id)
    selectedIds.value = new Set([...selectedIds.value].filter(id => id !== file.id))
    toast.success('File removed from folder')
  } catch (err) {
    toast.error('Failed to remove file')
  }
}

function downloadZip(group) {
  window.open(getExportUrl(folderId.value, group), '_blank')
}

function formatSize(bytes) {
  if (!bytes) return '0 B'
  const k = 1024
  const sizes = ['B', 'KB', 'MB', 'GB']
  const i = Math.floor(Math.log(bytes) / Math.log(k))
  return parseFloat((bytes / Math.pow(k, i)).toFixed(1)) + ' ' + sizes[i]
}

function getFileIcon(mime) {
  if (mime?.startsWith('image/')) return 'image'
  if (mime === 'application/pdf') return 'picture_as_pdf'
  if (mime?.includes('spreadsheet') || mime?.includes('csv')) return 'table_chart'
  if (mime?.includes('document') || mime?.includes('word')) return 'description'
  if (mime?.includes('zip') || mime?.includes('archive')) return 'folder_zip'
  if (mime?.includes('video')) return 'videocam'
  if (mime?.includes('audio')) return 'audio_file'
  return 'insert_drive_file'
}

function formatDate(d) {
  if (!d) return ''
  return new Date(d).toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: '2-digit' })
}

function toggleSort(field) {
  if (sortField.value === field) {
    sortDir.value = sortDir.value === 'asc' ? 'desc' : 'asc'
  } else {
    sortField.value = field
    sortDir.value = field === 'original_name' ? 'asc' : 'desc'
  }
}
</script>

<template>
  <div class="flex-1 flex flex-col overflow-hidden">
    <!-- Board Attachments (aggregated from linked boards) -->
    <div class="px-5 pt-4 shrink-0">
      <FolderBoardAttachments />
    </div>

    <!-- Toolbar -->
    <div class="flex items-center gap-3 px-5 py-3 border-b border-surface-200 dark:border-surface-700 bg-white dark:bg-surface-900 shrink-0 flex-wrap">
      <!-- Group filter pills -->
      <div class="flex items-center gap-1">
        <button
          class="px-3 py-1.5 rounded-full text-[11px] font-medium transition-colors"
          :class="!activeGroup
            ? 'bg-primary-500 text-white'
            : 'bg-surface-100 dark:bg-surface-700 text-surface-500 hover:bg-surface-200 dark:hover:bg-surface-600'"
          @click="activeGroup = null"
        >
          All ({{ files.length }})
        </button>
        <button
          v-for="group in fileGroups"
          :key="group"
          class="px-3 py-1.5 rounded-full text-[11px] font-medium transition-colors"
          :class="activeGroup === group
            ? 'bg-primary-500 text-white'
            : 'bg-surface-100 dark:bg-surface-700 text-surface-500 hover:bg-surface-200 dark:hover:bg-surface-600'"
          @click="activeGroup = activeGroup === group ? null : group"
        >
          {{ group }} ({{ groupCounts[group] || 0 }})
        </button>
      </div>

      <div class="flex-1"></div>

      <!-- Search -->
      <div class="relative">
        <span class="material-symbols-rounded text-[16px] text-surface-400 absolute left-2.5 top-1/2 -translate-y-1/2">search</span>
        <input
          v-model="searchQuery"
          class="pl-8 pr-3 py-1.5 w-48 rounded-full border border-surface-300 dark:border-surface-600 bg-white dark:bg-surface-700 text-sm text-surface-800 dark:text-surface-200 outline-none focus:ring-2 focus:ring-primary-500 focus:border-transparent"
          placeholder="Search files..."
        />
      </div>

      <!-- Export ZIP dropdown -->
      <div class="relative group/export">
        <button
          class="px-3 py-1.5 rounded-full text-[11px] font-medium bg-green-500/10 text-green-600 dark:text-green-400 hover:bg-green-500/20 transition-colors flex items-center gap-1"
          :class="{ 'opacity-50 pointer-events-none': !files.length }"
          @click="downloadZip(activeGroup)"
        >
          <span class="material-symbols-rounded text-[14px]">download</span>
          Download {{ activeGroup || 'All' }} ZIP
        </button>
      </div>

      <!-- Batch group button -->
      <button
        v-if="hasSelection"
        class="px-3 py-1.5 rounded-full text-[11px] font-medium bg-amber-500/10 text-amber-600 dark:text-amber-400 hover:bg-amber-500/20 transition-colors flex items-center gap-1"
        @click="contextMenu = { visible: true, x: $event.clientX, y: $event.clientY }"
      >
        <span class="material-symbols-rounded text-[14px]">label</span>
        Group ({{ selectedIds.size }})
      </button>
    </div>

    <!-- Upload drop zone -->
    <div
      class="mx-5 mt-4 mb-2 border-2 border-dashed rounded-xl p-4 text-center cursor-pointer transition-colors"
      :class="dragOver
        ? 'border-primary-400 bg-primary-50 dark:bg-primary-500/10'
        : 'border-surface-300 dark:border-surface-600 hover:border-primary-300 dark:hover:border-primary-500'"
      @click="triggerFileInput"
      @dragover.prevent="dragOver = true"
      @dragenter.prevent="dragOver = true"
      @dragleave.prevent="dragOver = false"
      @drop.prevent="handleFileDrop"
    >
      <span class="material-symbols-rounded text-2xl text-surface-400 mb-1 block">cloud_upload</span>
      <p class="text-sm text-surface-500 dark:text-surface-400">
        {{ uploading ? uploadProgress : 'Drag & drop files or click to upload' }}
      </p>
      <p v-if="uploading" class="text-xs text-primary-500 mt-1">Uploading to Drive...</p>
    </div>
    <input id="ph-folder-file-input" type="file" multiple class="hidden" @change="handleFileInputChange" />

    <!-- Loading -->
    <div v-if="loading" class="flex items-center justify-center py-16 flex-1">
      <div class="animate-spin rounded-full h-8 w-8 border-2 border-primary-500 border-t-transparent"></div>
    </div>

    <!-- Empty state -->
    <div v-else-if="!files.length" class="text-center py-16 text-surface-400 flex-1">
      <span class="material-symbols-rounded text-5xl mb-3 block">folder_open</span>
      <p class="text-base font-medium text-surface-600 dark:text-surface-300 mb-1">No files yet</p>
      <p class="text-sm">Upload files or drag & drop to get started</p>
    </div>

    <!-- File list -->
    <div v-else class="flex-1 overflow-auto px-5 pb-4" @contextmenu="handleContextMenu">
      <!-- Column headers -->
      <div class="flex items-center gap-3 px-3 py-2 text-[10px] font-semibold text-surface-400 uppercase tracking-wide border-b border-surface-200 dark:border-surface-700 sticky top-0 bg-white dark:bg-surface-900 z-10">
        <div class="w-8"></div>
        <button class="flex-1 text-left flex items-center gap-1 hover:text-surface-600 dark:hover:text-surface-300" @click="toggleSort('original_name')">
          Name
          <span v-if="sortField === 'original_name'" class="material-symbols-rounded text-[12px]">{{ sortDir === 'asc' ? 'arrow_upward' : 'arrow_downward' }}</span>
        </button>
        <div class="w-24 text-center">Group</div>
        <button class="w-20 text-right flex items-center justify-end gap-1 hover:text-surface-600 dark:hover:text-surface-300" @click="toggleSort('size')">
          Size
          <span v-if="sortField === 'size'" class="material-symbols-rounded text-[12px]">{{ sortDir === 'asc' ? 'arrow_upward' : 'arrow_downward' }}</span>
        </button>
        <button class="w-24 text-right flex items-center justify-end gap-1 hover:text-surface-600 dark:hover:text-surface-300" @click="toggleSort('created_at')">
          Added
          <span v-if="sortField === 'created_at'" class="material-symbols-rounded text-[12px]">{{ sortDir === 'asc' ? 'arrow_upward' : 'arrow_downward' }}</span>
        </button>
        <div class="w-8"></div>
      </div>

      <!-- File rows -->
      <div
        v-for="(file, idx) in filteredFiles"
        :key="file.id"
        class="flex items-center gap-3 px-3 py-2 rounded-lg transition-colors cursor-pointer group/row"
        :class="{
          'bg-primary-50 dark:bg-primary-500/10': selectedIds.has(file.id),
          'hover:bg-surface-50 dark:hover:bg-surface-800': !selectedIds.has(file.id),
          'ring-1 ring-primary-300 dark:ring-primary-600': file.unseen,
        }"
        @click="handleRowClick(file, idx, $event)"
        @contextmenu="handleRowClick(file, idx, $event)"
      >
        <!-- Type icon -->
        <span class="material-symbols-rounded text-lg text-surface-400 w-8 text-center shrink-0">{{ getFileIcon(file.mime_type) }}</span>

        <!-- Name + unseen dot -->
        <div class="flex-1 min-w-0 flex items-center gap-2">
          <span v-if="file.unseen" class="w-2 h-2 rounded-full bg-primary-500 shrink-0"></span>
          <span class="text-sm text-surface-700 dark:text-surface-300 truncate">{{ file.original_name }}</span>
        </div>

        <!-- Group badge -->
        <div class="w-24 text-center">
          <select
            :value="file.group_name"
            class="px-2 py-0.5 rounded-full text-[10px] font-medium border-0 bg-surface-100 dark:bg-surface-700 text-surface-600 dark:text-surface-300 cursor-pointer outline-none text-center appearance-none"
            @click.stop
            @change="handleSingleGroupChange(file, $event.target.value)"
          >
            <option v-for="g in fileGroups" :key="g" :value="g">{{ g }}</option>
          </select>
        </div>

        <!-- Size -->
        <div class="w-20 text-right text-xs text-surface-400">{{ formatSize(file.size) }}</div>

        <!-- Date -->
        <div class="w-24 text-right text-xs text-surface-400">{{ formatDate(file.created_at) }}</div>

        <!-- Remove -->
        <button
          class="w-8 flex items-center justify-center rounded-full opacity-0 group-hover/row:opacity-100 hover:bg-red-100 dark:hover:bg-red-900/20 transition-all shrink-0"
          @click.stop="handleRemove(file)"
          title="Remove from folder"
        >
          <span class="material-symbols-rounded text-[16px] text-red-500">close</span>
        </button>
      </div>
    </div>

    <!-- Context menu for batch group -->
    <FileGroupContextMenu
      :visible="contextMenu.visible"
      :x="contextMenu.x"
      :y="contextMenu.y"
      :groups="fileGroups"
      @assign="assignGroupToSelection"
      @close="contextMenu.visible = false"
    />
  </div>
</template>
