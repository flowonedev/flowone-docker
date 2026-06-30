<script setup>
import { ref, computed } from 'vue'
import api from '@/services/api'
import { useToastStore } from '@/stores/toast'
import { useBoardsStore } from '@/addons/kanban-boards/stores/boards'

const emit = defineEmits(['close', 'imported'])
const toast = useToastStore()
const boardsStore = useBoardsStore()

const step = ref('upload')
const dragging = ref(false)
const zipFile = ref(null)
const parsing = ref(false)
const importing = ref(false)
const importProgress = ref({ current: 0, total: 0, boardName: '' })

const parsedData = ref(null)
const selectedBoards = ref({})

function onDragOver(e) {
  e.preventDefault()
  dragging.value = true
}

function onDragLeave() {
  dragging.value = false
}

function onDrop(e) {
  e.preventDefault()
  dragging.value = false
  const file = e.dataTransfer?.files?.[0]
  if (file && file.name.endsWith('.zip')) {
    zipFile.value = file
    parseZip()
  } else {
    toast.error('Please drop a ZIP file exported from Trello')
  }
}

function onFileSelect(e) {
  const file = e.target.files?.[0]
  if (file) {
    zipFile.value = file
    parseZip()
  }
}

async function parseZip() {
  if (!zipFile.value) return
  parsing.value = true

  try {
    const formData = new FormData()
    formData.append('file', zipFile.value)
    const response = await api.post('/boards/import-trello/preview', formData, {
      headers: { 'Content-Type': 'multipart/form-data' }
    })

    if (response.data.success) {
      parsedData.value = response.data.data
      parsedData.value.boards.forEach(b => {
        selectedBoards.value[b.id] = true
      })
      step.value = 'preview'
    } else {
      toast.error(response.data.error || 'Failed to parse ZIP file')
    }
  } catch (e) {
    toast.error(e.response?.data?.error || 'Failed to parse Trello export')
  } finally {
    parsing.value = false
  }
}

const selectedCount = computed(() => {
  return Object.values(selectedBoards.value).filter(Boolean).length
})

function toggleBoard(boardId) {
  selectedBoards.value[boardId] = !selectedBoards.value[boardId]
}

async function startImport() {
  const boardIds = Object.keys(selectedBoards.value).filter(k => selectedBoards.value[k])
  if (boardIds.length === 0) {
    toast.warning('Select at least one board to import')
    return
  }

  importing.value = true
  importProgress.value = { current: 0, total: boardIds.length, boardName: '' }
  step.value = 'importing'

  try {
    const formData = new FormData()
    formData.append('file', zipFile.value)
    formData.append('board_ids', JSON.stringify(boardIds))
    const response = await api.post('/boards/import-trello', formData, {
      headers: { 'Content-Type': 'multipart/form-data' }
    })

    if (response.data.success) {
      const results = response.data.data
      step.value = 'done'
      await boardsStore.fetchBoards()
      toast.success(`Imported ${results.imported_count} board(s) from Trello`)
    } else {
      toast.error(response.data.error || 'Import failed')
      step.value = 'preview'
    }
  } catch (e) {
    toast.error(e.response?.data?.error || 'Import failed')
    step.value = 'preview'
  } finally {
    importing.value = false
  }
}

function reset() {
  step.value = 'upload'
  zipFile.value = null
  parsedData.value = null
  selectedBoards.value = {}
}

function formatFileSize(bytes) {
  if (bytes < 1024) return bytes + ' B'
  if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB'
  return (bytes / (1024 * 1024)).toFixed(1) + ' MB'
}
</script>

<template>
  <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm" @click.self="$emit('close')">
    <div class="bg-white dark:bg-surface-800 rounded-2xl shadow-2xl w-full max-w-lg mx-4 overflow-hidden">
      <!-- Header -->
      <div class="flex items-center justify-between px-6 py-4 border-b border-surface-200 dark:border-surface-700">
        <div class="flex items-center gap-3">
          <div class="w-9 h-9 rounded-xl flex items-center justify-center bg-blue-500/10">
            <span class="material-symbols-rounded text-lg text-blue-500">download</span>
          </div>
          <div>
            <h2 class="text-lg font-semibold text-surface-800 dark:text-surface-100">Import from Trello</h2>
            <p class="text-xs text-surface-500">Upload a Trello workspace export (.zip)</p>
          </div>
        </div>
        <button
          @click="$emit('close')"
          class="p-1.5 rounded-lg hover:bg-surface-100 dark:hover:bg-surface-700 text-surface-400 hover:text-surface-600 dark:hover:text-surface-200 transition-colors"
        >
          <span class="material-symbols-rounded text-xl">close</span>
        </button>
      </div>

      <!-- Body -->
      <div class="p-6">
        <!-- Step 1: Upload -->
        <div v-if="step === 'upload'">
          <div
            @dragover="onDragOver"
            @dragleave="onDragLeave"
            @drop="onDrop"
            :class="[
              'border-2 border-dashed rounded-xl p-8 text-center transition-colors cursor-pointer',
              dragging
                ? 'border-blue-500 bg-blue-500/5'
                : 'border-surface-300 dark:border-surface-600 hover:border-blue-400 hover:bg-blue-500/5'
            ]"
            @click="$refs.fileInput.click()"
          >
            <span v-if="parsing" class="material-symbols-rounded text-4xl text-blue-500 animate-spin mb-3 block">progress_activity</span>
            <span v-else class="material-symbols-rounded text-4xl text-surface-400 mb-3 block">upload_file</span>
            <p class="text-sm font-medium text-surface-700 dark:text-surface-300 mb-1">
              {{ parsing ? 'Parsing export...' : 'Drop your Trello export here' }}
            </p>
            <p class="text-xs text-surface-500">or click to browse (.zip file)</p>
          </div>
          <input ref="fileInput" type="file" accept=".zip" class="hidden" @change="onFileSelect" />

          <div class="mt-4 p-3 bg-surface-50 dark:bg-surface-700/50 rounded-xl">
            <p class="text-xs text-surface-500 leading-relaxed">
              <span class="font-medium text-surface-700 dark:text-surface-300">How to export from Trello:</span><br />
              Go to <span class="font-medium">trello.com</span> &rarr; Workspace Settings &rarr; Export &rarr; Download as ZIP.
              The export includes all boards, lists, cards, checklists, labels, and attachments.
            </p>
          </div>
        </div>

        <!-- Step 2: Preview -->
        <div v-else-if="step === 'preview'" class="space-y-4">
          <div class="flex items-center gap-3 p-3 bg-surface-50 dark:bg-surface-700/50 rounded-xl">
            <span class="material-symbols-rounded text-lg text-blue-500">folder_zip</span>
            <div class="flex-1 min-w-0">
              <p class="text-sm font-medium text-surface-800 dark:text-surface-100 truncate">{{ parsedData?.workspace_name || zipFile?.name }}</p>
              <p class="text-xs text-surface-500">{{ parsedData?.boards?.length || 0 }} board(s) found</p>
            </div>
            <button @click="reset" class="text-xs text-surface-500 hover:text-surface-700 dark:hover:text-surface-300">Change file</button>
          </div>

          <p class="text-xs font-medium text-surface-500 uppercase tracking-wide">Select boards to import</p>

          <div class="space-y-2 max-h-[280px] overflow-y-auto">
            <button
              v-for="board in parsedData?.boards"
              :key="board.id"
              @click="toggleBoard(board.id)"
              :class="[
                'w-full flex items-center gap-3 p-3 rounded-xl border transition-colors text-left',
                selectedBoards[board.id]
                  ? 'border-blue-500 bg-blue-500/5'
                  : 'border-surface-200 dark:border-surface-600 hover:border-surface-300 dark:hover:border-surface-500'
              ]"
            >
              <div :class="[
                'w-5 h-5 rounded-md flex items-center justify-center transition-colors shrink-0',
                selectedBoards[board.id]
                  ? 'bg-blue-500 text-white'
                  : 'bg-surface-200 dark:bg-surface-600'
              ]">
                <span v-if="selectedBoards[board.id]" class="material-symbols-rounded text-sm">check</span>
              </div>
              <div class="flex-1 min-w-0">
                <p class="text-sm font-medium text-surface-800 dark:text-surface-100 truncate">{{ board.name }}</p>
                <p class="text-xs text-surface-500">
                  {{ board.list_count }} lists, {{ board.card_count }} cards, {{ board.label_count }} labels
                  <span v-if="board.attachment_count">, {{ board.attachment_count }} attachments</span>
                </p>
              </div>
            </button>
          </div>
        </div>

        <!-- Step 3: Importing -->
        <div v-else-if="step === 'importing'" class="text-center py-6">
          <span class="material-symbols-rounded text-4xl text-blue-500 animate-spin mb-4 block">progress_activity</span>
          <p class="text-sm font-medium text-surface-800 dark:text-surface-100 mb-1">Importing boards...</p>
          <p class="text-xs text-surface-500">This may take a moment depending on the export size</p>
        </div>

        <!-- Step 4: Done -->
        <div v-else-if="step === 'done'" class="text-center py-6">
          <span class="material-symbols-rounded text-4xl text-emerald-500 mb-4 block">check_circle</span>
          <p class="text-sm font-medium text-surface-800 dark:text-surface-100 mb-1">Import complete</p>
          <p class="text-xs text-surface-500">Your Trello boards have been imported successfully</p>
        </div>
      </div>

      <!-- Footer -->
      <div class="px-6 py-3 border-t border-surface-200 dark:border-surface-700 flex items-center justify-between">
        <div>
          <span v-if="step === 'preview'" class="text-xs text-surface-500">{{ selectedCount }} selected</span>
        </div>
        <div class="flex items-center gap-2">
          <button
            v-if="step !== 'importing'"
            @click="$emit('close')"
            class="px-5 py-2 rounded-full text-sm font-medium text-surface-600 dark:text-surface-300 hover:bg-surface-100 dark:hover:bg-surface-700 transition-colors"
          >
            {{ step === 'done' ? 'Close' : 'Cancel' }}
          </button>
          <button
            v-if="step === 'preview'"
            @click="startImport"
            :disabled="selectedCount === 0"
            class="px-5 py-2 rounded-full bg-blue-500 text-white text-sm font-medium hover:bg-blue-600 transition-colors disabled:opacity-50 flex items-center gap-2"
          >
            <span class="material-symbols-rounded text-lg">download</span>
            Import {{ selectedCount }} board(s)
          </button>
          <button
            v-if="step === 'done'"
            @click="$emit('close')"
            class="px-5 py-2 rounded-full bg-primary-500 text-white text-sm font-medium hover:bg-primary-600 transition-colors"
          >
            Done
          </button>
        </div>
      </div>
    </div>
  </div>
</template>
