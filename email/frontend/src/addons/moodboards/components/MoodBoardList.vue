<template>
  <div class="h-full flex flex-col bg-white dark:bg-[rgb(var(--color-surface))]">
    <!-- Header -->
    <div class="p-4 border-b border-surface-200 dark:border-surface-700">
      <div class="flex items-center justify-between mb-3">
        <h2 class="text-sm font-semibold text-surface-900 dark:text-surface-100">Mood Boards</h2>
        <div class="relative" ref="createMenuRef">
          <button
            @click="showCreateMenu = !showCreateMenu"
            class="w-8 h-8 rounded-full bg-primary-500 hover:bg-primary-600 text-white transition-colors flex items-center justify-center flex-shrink-0"
            title="New..."
          >
            <span class="material-symbols-rounded text-lg">add</span>
          </button>
          <div
            v-if="showCreateMenu"
            class="absolute right-0 top-10 z-50 bg-white dark:bg-surface-800 rounded-xl shadow-lg border border-surface-200 dark:border-surface-700 py-1 min-w-[160px]"
          >
            <button @click="openCreateBoard" class="w-full px-4 py-2 text-sm text-left hover:bg-surface-50 dark:hover:bg-surface-700 flex items-center gap-2 text-surface-700 dark:text-surface-300">
              <span class="material-symbols-rounded text-lg">dashboard_customize</span>
              New Board
            </button>
            <button @click="openCreateFolder" class="w-full px-4 py-2 text-sm text-left hover:bg-surface-50 dark:hover:bg-surface-700 flex items-center gap-2 text-surface-700 dark:text-surface-300">
              <span class="material-symbols-rounded text-lg">create_new_folder</span>
              New Folder
            </button>
          </div>
        </div>
      </div>

      <!-- Export / Import CSV (visible when a board is selected) -->
      <div v-if="selectedId" class="flex items-center gap-1.5 mb-3">
        <button
          @click="handleExportTextsFromHeader"
          class="flex-1 flex items-center justify-center gap-1.5 px-3 py-1.5 text-xs font-medium rounded-full bg-surface-100 dark:bg-surface-700 text-surface-600 dark:text-surface-300 hover:bg-surface-200 dark:hover:bg-surface-600 transition-colors"
          title="Export all text items to CSV"
        >
          <span class="material-symbols-rounded text-base">download</span>
          Export CSV
        </button>
        <button
          @click="handleImportTextsFromHeader"
          class="flex-1 flex items-center justify-center gap-1.5 px-3 py-1.5 text-xs font-medium rounded-full bg-surface-100 dark:bg-surface-700 text-surface-600 dark:text-surface-300 hover:bg-surface-200 dark:hover:bg-surface-600 transition-colors"
          title="Import translated/edited texts from CSV"
        >
          <span class="material-symbols-rounded text-base">upload</span>
          Import CSV
        </button>
      </div>
      
      <!-- Search -->
      <div class="relative">
        <span class="material-symbols-rounded absolute left-2.5 top-1/2 -translate-y-1/2 text-surface-400 text-lg">search</span>
        <input
          v-model="searchQuery"
          type="text"
          placeholder="Search boards..."
          class="w-full pl-9 pr-3 py-2 text-sm rounded-full bg-surface-100 dark:bg-surface-800 border-none focus:ring-2 focus:ring-primary-500 text-surface-900 dark:text-surface-100 placeholder-surface-400"
        />
      </div>
    </div>
    
    <!-- Board/folder list -->
    <div
      class="flex-1 overflow-y-auto p-2 space-y-0.5"
      @dragover.prevent="onRootDragOver"
      @dragleave="onRootDragLeave"
      @drop="onRootDrop"
      :class="{ 'ring-2 ring-inset ring-primary-300 dark:ring-primary-700 rounded-lg': rootDragOver }"
    >
      <div v-if="store.loading" class="flex items-center justify-center py-8">
        <span class="material-symbols-rounded text-2xl text-surface-400 animate-spin">sync</span>
      </div>
      
      <template v-else-if="searchQuery.trim()">
        <!-- Flat search results -->
        <div v-if="filteredBoards.length === 0" class="text-center py-8">
          <span class="material-symbols-rounded text-4xl text-surface-300 dark:text-surface-600">search_off</span>
          <p class="text-sm text-surface-500 mt-2">No boards match your search</p>
        </div>
        <button
          v-for="board in filteredBoards"
          :key="board.id"
          draggable="true"
          @dragstart="onBoardDragStart($event, board)"
          @click="$emit('select', board.id)"
          @contextmenu.prevent="openBoardContext($event, board)"
          :class="boardItemClass(board)"
        >
          <div class="flex items-start gap-3">
            <div class="w-10 h-10 rounded-lg flex-shrink-0 flex items-center justify-center" :style="{ backgroundColor: board.background_color || '#f5f5f5' }">
              <span class="material-symbols-rounded text-lg" :class="getBoardIconColor(board.background_color)">dashboard_customize</span>
            </div>
            <div class="flex-1 min-w-0">
              <p class="text-sm font-medium text-surface-900 dark:text-surface-100 truncate">{{ board.name }}</p>
              <div class="flex items-center gap-2 mt-0.5">
                <span class="text-xs text-surface-500 dark:text-surface-400">{{ board.item_count || 0 }} items</span>
                <span v-if="board.is_ready" class="text-xs text-green-600 dark:text-green-400 flex items-center gap-0.5 font-medium">
                  <span class="material-symbols-rounded text-xs">check_circle</span> Ready
                </span>
              </div>
            </div>
          </div>
        </button>
      </template>
      
      <template v-else>
        <!-- Tree view: folders, then unfiled boards -->
        <div v-if="store.folderTree.length === 0 && store.unfiledBoards.length === 0" class="text-center py-8">
          <span class="material-symbols-rounded text-4xl text-surface-300 dark:text-surface-600">dashboard_customize</span>
          <p class="text-sm text-surface-500 mt-2">No mood boards yet</p>
          <button @click="openCreateBoard" class="mt-3 px-4 py-2 text-sm rounded-full bg-primary-500 hover:bg-primary-600 text-white transition-colors">
            Create your first board
          </button>
        </div>

        <!-- Folders -->
        <MoodBoardFolderNode
          v-for="folder in store.folderTree"
          :key="'f-' + folder.id"
          :folder="folder"
          :selected-id="selectedId"
          :depth="0"
          @select="$emit('select', $event)"
          @folder-context="openFolderContext"
          @board-context="openBoardContext"
        />

        <!-- Unfiled boards -->
        <div v-if="store.unfiledBoards.length > 0 && store.folderTree.length > 0" class="pt-2 mt-1 border-t border-surface-100 dark:border-surface-800">
          <div class="px-2 py-1 text-xs text-surface-400 dark:text-surface-500 font-medium uppercase tracking-wider">
            Unfiled
          </div>
        </div>
        <button
          v-for="board in store.unfiledBoards"
          :key="'b-' + board.id"
          draggable="true"
          @dragstart="onBoardDragStart($event, board)"
          @click="$emit('select', board.id)"
          @contextmenu.prevent="openBoardContext($event, board)"
          :class="boardItemClass(board)"
        >
          <div class="flex items-start gap-3">
            <div class="w-10 h-10 rounded-lg flex-shrink-0 flex items-center justify-center" :style="{ backgroundColor: board.background_color || '#f5f5f5' }">
              <span class="material-symbols-rounded text-lg" :class="getBoardIconColor(board.background_color)">dashboard_customize</span>
            </div>
            <div class="flex-1 min-w-0">
              <p class="text-sm font-medium text-surface-900 dark:text-surface-100 truncate">{{ board.name }}</p>
              <div class="flex items-center gap-2 mt-0.5">
                <span class="text-xs text-surface-500 dark:text-surface-400">{{ board.item_count || 0 }} items</span>
                <span v-if="board.is_ready" class="text-xs text-green-600 dark:text-green-400 flex items-center gap-0.5 font-medium">
                  <span class="material-symbols-rounded text-xs">check_circle</span> Ready
                </span>
                <span v-if="board.client" class="text-xs text-primary-500 dark:text-primary-400 flex items-center gap-0.5">
                  <span class="material-symbols-rounded text-xs">person</span>
                  {{ board.client.display_name || board.client.domain }}
                </span>
              </div>
            </div>
          </div>
        </button>
      </template>
    </div>
    
    <!-- Create Board Modal -->
    <div v-if="showCreateBoardModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40" @click.self="showCreateBoardModal = false">
      <div class="bg-white dark:bg-surface-800 rounded-2xl shadow-xl w-full max-w-md mx-4 p-6">
        <h3 class="text-lg font-semibold text-surface-900 dark:text-surface-100 mb-4">New Mood Board</h3>
        <div class="space-y-4">
          <div>
            <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-1">Name</label>
            <input
              v-model="newBoardName"
              type="text"
              placeholder="My creative board"
              class="w-full px-4 py-2.5 rounded-xl border border-surface-300 dark:border-surface-600 bg-white dark:bg-surface-700 text-surface-900 dark:text-surface-100 focus:ring-2 focus:ring-primary-500 focus:border-transparent"
              @keydown.enter="createBoard"
              ref="boardNameInput"
            />
          </div>
          <div>
            <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-1">Background Color</label>
            <div class="flex gap-2 flex-wrap">
              <button
                v-for="color in boardColors"
                :key="color"
                @click="newBoardColor = color"
                :class="['w-8 h-8 rounded-full border-2 transition-all', newBoardColor === color ? 'border-primary-500 scale-110' : 'border-transparent hover:scale-105']"
                :style="{ backgroundColor: color }"
              />
            </div>
          </div>
        </div>
        <div class="flex justify-end gap-2 mt-6">
          <button @click="showCreateBoardModal = false" class="px-5 py-2 text-sm rounded-full bg-surface-100 dark:bg-surface-700 text-surface-700 dark:text-surface-300 hover:bg-surface-200 dark:hover:bg-surface-600 transition-colors">
            Cancel
          </button>
          <button @click="createBoard" :disabled="!newBoardName.trim()" class="px-5 py-2 text-sm rounded-full bg-primary-500 hover:bg-primary-600 text-white transition-colors disabled:opacity-50 disabled:cursor-not-allowed">
            Create
          </button>
        </div>
      </div>
    </div>

    <!-- Create Folder Modal -->
    <div v-if="showCreateFolderModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40" @click.self="showCreateFolderModal = false">
      <div class="bg-white dark:bg-surface-800 rounded-2xl shadow-xl w-full max-w-md mx-4 p-6">
        <h3 class="text-lg font-semibold text-surface-900 dark:text-surface-100 mb-4">New Folder</h3>
        <div class="space-y-4">
          <div>
            <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-1">Folder Name</label>
            <input
              v-model="newFolderName"
              type="text"
              placeholder="My project folder"
              class="w-full px-4 py-2.5 rounded-xl border border-surface-300 dark:border-surface-600 bg-white dark:bg-surface-700 text-surface-900 dark:text-surface-100 focus:ring-2 focus:ring-primary-500 focus:border-transparent"
              @keydown.enter="createFolder"
              ref="folderNameInput"
            />
          </div>
          <div>
            <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-1">Color</label>
            <div class="flex gap-2 flex-wrap">
              <button
                v-for="color in folderColors"
                :key="color"
                @click="newFolderColor = color"
                :class="['w-7 h-7 rounded-full border-2 transition-all', newFolderColor === color ? 'border-primary-500 scale-110' : 'border-transparent hover:scale-105']"
                :style="{ backgroundColor: color }"
              />
            </div>
          </div>
        </div>
        <div class="flex justify-end gap-2 mt-6">
          <button @click="showCreateFolderModal = false" class="px-5 py-2 text-sm rounded-full bg-surface-100 dark:bg-surface-700 text-surface-700 dark:text-surface-300 hover:bg-surface-200 dark:hover:bg-surface-600 transition-colors">
            Cancel
          </button>
          <button @click="createFolder" :disabled="!newFolderName.trim()" class="px-5 py-2 text-sm rounded-full bg-primary-500 hover:bg-primary-600 text-white transition-colors disabled:opacity-50 disabled:cursor-not-allowed">
            Create
          </button>
        </div>
      </div>
    </div>

    <!-- Rename Folder Modal -->
    <div v-if="showRenameFolderModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40" @click.self="showRenameFolderModal = false">
      <div class="bg-white dark:bg-surface-800 rounded-2xl shadow-xl w-full max-w-md mx-4 p-6">
        <h3 class="text-lg font-semibold text-surface-900 dark:text-surface-100 mb-4">Rename Folder</h3>
        <input
          v-model="renameFolderName"
          type="text"
          class="w-full px-4 py-2.5 rounded-xl border border-surface-300 dark:border-surface-600 bg-white dark:bg-surface-700 text-surface-900 dark:text-surface-100 focus:ring-2 focus:ring-primary-500 focus:border-transparent"
          @keydown.enter="confirmRenameFolder"
          ref="renameFolderInput"
        />
        <div class="flex justify-end gap-2 mt-4">
          <button @click="showRenameFolderModal = false" class="px-5 py-2 text-sm rounded-full bg-surface-100 dark:bg-surface-700 text-surface-700 dark:text-surface-300 hover:bg-surface-200 dark:hover:bg-surface-600 transition-colors">
            Cancel
          </button>
          <button @click="confirmRenameFolder" :disabled="!renameFolderName.trim()" class="px-5 py-2 text-sm rounded-full bg-primary-500 hover:bg-primary-600 text-white transition-colors disabled:opacity-50">
            Save
          </button>
        </div>
      </div>
    </div>

    <!-- Move to Folder Modal -->
    <div v-if="showMoveBoardModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40" @click.self="showMoveBoardModal = false">
      <div class="bg-white dark:bg-surface-800 rounded-2xl shadow-xl w-full max-w-sm mx-4 p-6">
        <h3 class="text-lg font-semibold text-surface-900 dark:text-surface-100 mb-4">Move to Folder</h3>
        <div class="space-y-1 max-h-60 overflow-y-auto">
          <button
            @click="confirmMoveBoard(null)"
            :class="['w-full text-left px-3 py-2 rounded-lg text-sm transition-colors flex items-center gap-2',
              moveBoardTarget?.folder_id == null ? 'bg-surface-100 dark:bg-surface-700' : 'hover:bg-surface-50 dark:hover:bg-surface-800']"
          >
            <span class="material-symbols-rounded text-lg text-surface-400">home</span>
            <span class="text-surface-700 dark:text-surface-300">Root (no folder)</span>
          </button>
          <button
            v-for="folder in store.folders"
            :key="folder.id"
            @click="confirmMoveBoard(folder.id)"
            :class="['w-full text-left px-3 py-2 rounded-lg text-sm transition-colors flex items-center gap-2',
              moveBoardTarget?.folder_id === folder.id ? 'bg-surface-100 dark:bg-surface-700' : 'hover:bg-surface-50 dark:hover:bg-surface-800']"
            :style="{ paddingLeft: (getFolderDepth(folder) * 16 + 12) + 'px' }"
          >
            <span class="material-symbols-rounded text-lg" :style="folder.color ? { color: folder.color } : {}" :class="folder.color ? '' : 'text-amber-500'">folder</span>
            <span class="text-surface-700 dark:text-surface-300">{{ folder.name }}</span>
          </button>
        </div>
      </div>
    </div>

    <!-- Board Context Menu -->
    <div
      v-if="boardContextMenu.show"
      class="fixed z-50 bg-white dark:bg-surface-800 rounded-xl shadow-lg border border-surface-200 dark:border-surface-700 py-1 min-w-[180px]"
      :style="{ left: boardContextMenu.x + 'px', top: boardContextMenu.y + 'px' }"
    >
      <button @click="handleDuplicate" class="w-full px-4 py-2 text-sm text-left hover:bg-surface-50 dark:hover:bg-surface-700 flex items-center gap-2 text-surface-700 dark:text-surface-300">
        <span class="material-symbols-rounded text-lg">content_copy</span> Duplicate
      </button>
      <button @click="handleMoveToFolder" class="w-full px-4 py-2 text-sm text-left hover:bg-surface-50 dark:hover:bg-surface-700 flex items-center gap-2 text-surface-700 dark:text-surface-300">
        <span class="material-symbols-rounded text-lg">drive_file_move</span> Move to Folder...
      </button>
      <button @click="handleArchive" class="w-full px-4 py-2 text-sm text-left hover:bg-surface-50 dark:hover:bg-surface-700 flex items-center gap-2 text-surface-700 dark:text-surface-300">
        <span class="material-symbols-rounded text-lg">archive</span>
        {{ boardContextMenu.board?.archived ? 'Unarchive' : 'Archive' }}
      </button>
      <div class="my-1 border-t border-surface-200 dark:border-surface-700"></div>
      <button @click="handleExportTexts" class="w-full px-4 py-2 text-sm text-left hover:bg-surface-50 dark:hover:bg-surface-700 flex items-center gap-2 text-surface-700 dark:text-surface-300">
        <span class="material-symbols-rounded text-lg">download</span> Export Texts (CSV)
      </button>
      <button @click="handleImportTexts" class="w-full px-4 py-2 text-sm text-left hover:bg-surface-50 dark:hover:bg-surface-700 flex items-center gap-2 text-surface-700 dark:text-surface-300">
        <span class="material-symbols-rounded text-lg">upload</span> Import Texts (CSV)
      </button>
      <div class="my-1 border-t border-surface-200 dark:border-surface-700"></div>
      <button @click="handleDeleteBoard" class="w-full px-4 py-2 text-sm text-left hover:bg-red-50 dark:hover:bg-red-900/20 flex items-center gap-2 text-red-600 dark:text-red-400">
        <span class="material-symbols-rounded text-lg">delete</span> Delete
      </button>
    </div>

    <!-- Folder Context Menu -->
    <div
      v-if="folderContextMenu.show"
      class="fixed z-50 bg-white dark:bg-surface-800 rounded-xl shadow-lg border border-surface-200 dark:border-surface-700 py-1 min-w-[160px]"
      :style="{ left: folderContextMenu.x + 'px', top: folderContextMenu.y + 'px' }"
    >
      <button @click="handleRenameFolder" class="w-full px-4 py-2 text-sm text-left hover:bg-surface-50 dark:hover:bg-surface-700 flex items-center gap-2 text-surface-700 dark:text-surface-300">
        <span class="material-symbols-rounded text-lg">edit</span> Rename
      </button>
      <button @click="handleNewSubfolder" class="w-full px-4 py-2 text-sm text-left hover:bg-surface-50 dark:hover:bg-surface-700 flex items-center gap-2 text-surface-700 dark:text-surface-300">
        <span class="material-symbols-rounded text-lg">create_new_folder</span> New Subfolder
      </button>
      <div class="my-1 border-t border-surface-200 dark:border-surface-700"></div>
      <button @click="handleDeleteFolder" class="w-full px-4 py-2 text-sm text-left hover:bg-red-50 dark:hover:bg-red-900/20 flex items-center gap-2 text-red-600 dark:text-red-400">
        <span class="material-symbols-rounded text-lg">delete</span> Delete Folder
      </button>
    </div>

    <!-- Hidden file input for CSV import -->
    <input type="file" ref="csvFileInput" accept=".csv" class="hidden" @change="onCsvFileSelected" />
  </div>
</template>

<script setup>
import { ref, computed, nextTick, onMounted, onUnmounted } from 'vue'
import { useMoodBoardsStore } from '@/addons/moodboards/stores/moodBoards'
import { useToastStore } from '@/stores/toast'
import MoodBoardFolderNode from './MoodBoardFolderNode.vue'

const props = defineProps({
  selectedId: { type: Number, default: null },
})

const emit = defineEmits(['select'])

const store = useMoodBoardsStore()
const toast = useToastStore()

// Create menu
const showCreateMenu = ref(false)
const createMenuRef = ref(null)

// Search
const searchQuery = ref('')

// Board creation
const showCreateBoardModal = ref(false)
const newBoardName = ref('')
const newBoardColor = ref('#f5f5f5')
const boardNameInput = ref(null)
const boardColors = [
  '#f5f5f5', '#fef3c7', '#dcfce7', '#dbeafe', '#f3e8ff',
  '#fce7f3', '#fed7aa', '#e0e7ff', '#1e1e26', '#374151'
]

// Folder creation
const showCreateFolderModal = ref(false)
const newFolderName = ref('')
const newFolderColor = ref(null)
const folderNameInput = ref(null)
const createFolderParentId = ref(null)
const folderColors = [
  '#f59e0b', '#10b981', '#3b82f6', '#8b5cf6', '#ec4899',
  '#ef4444', '#f97316', '#06b6d4', '#6366f1', '#94a3b8'
]

// Folder rename
const showRenameFolderModal = ref(false)
const renameFolderName = ref('')
const renameFolderTarget = ref(null)
const renameFolderInput = ref(null)

// Move board to folder
const showMoveBoardModal = ref(false)
const moveBoardTarget = ref(null)

// Drag & drop on root
const rootDragOver = ref(false)

// Context menus
const boardContextMenu = ref({ show: false, x: 0, y: 0, board: null })
const folderContextMenu = ref({ show: false, x: 0, y: 0, folder: null })

// CSV file input
const csvFileInput = ref(null)
const csvImportBoardId = ref(null)

// Search filter
const filteredBoards = computed(() => {
  const query = searchQuery.value.toLowerCase().trim()
  if (!query) return store.activeBoards
  return store.activeBoards.filter(b =>
    b.name.toLowerCase().includes(query) ||
    (b.client?.display_name || b.client?.domain || '').toLowerCase().includes(query)
  )
})

function boardItemClass(board) {
  return [
    'w-full text-left p-3 rounded-xl transition-all group',
    props.selectedId === board.id
      ? 'bg-primary-50 dark:bg-primary-900/20 border border-primary-200 dark:border-primary-800'
      : 'hover:bg-surface-50 dark:hover:bg-surface-800 border border-transparent'
  ]
}

function getBoardIconColor(bgColor) {
  if (!bgColor) return 'text-surface-500'
  const hex = bgColor.replace('#', '')
  if (hex.length >= 6) {
    const r = parseInt(hex.substr(0, 2), 16)
    const g = parseInt(hex.substr(2, 2), 16)
    const b = parseInt(hex.substr(4, 2), 16)
    const brightness = (r * 299 + g * 587 + b * 114) / 1000
    return brightness < 128 ? 'text-white/70' : 'text-surface-600'
  }
  return 'text-surface-500'
}

function getFolderDepth(folder) {
  let depth = 0
  let parentId = folder.parent_id
  while (parentId) {
    depth++
    const parent = store.folders.find(f => f.id === parentId)
    parentId = parent?.parent_id || null
  }
  return depth
}

// ------ Create menu ------

function openCreateBoard() {
  showCreateMenu.value = false
  showCreateBoardModal.value = true
  nextTick(() => boardNameInput.value?.focus())
}

function openCreateFolder() {
  showCreateMenu.value = false
  createFolderParentId.value = null
  newFolderName.value = ''
  newFolderColor.value = folderColors[0]
  showCreateFolderModal.value = true
  nextTick(() => folderNameInput.value?.focus())
}

async function createBoard() {
  if (!newBoardName.value.trim()) return
  const board = await store.createBoard({
    name: newBoardName.value.trim(),
    background_color: newBoardColor.value
  })
  if (board) {
    showCreateBoardModal.value = false
    newBoardName.value = ''
    newBoardColor.value = '#f5f5f5'
    emit('select', board.id)
    toast.show('Board created', 'success')
  }
}

async function createFolder() {
  if (!newFolderName.value.trim()) return
  const folder = await store.createFolder({
    name: newFolderName.value.trim(),
    parent_id: createFolderParentId.value,
    color: newFolderColor.value
  })
  if (folder) {
    showCreateFolderModal.value = false
    newFolderName.value = ''
    toast.show('Folder created', 'success')
  }
}

// ------ Board context menu ------

function openBoardContext(event, board) {
  closeFolderContext()
  boardContextMenu.value = { show: true, x: event.clientX, y: event.clientY, board }
}

function closeBoardContext() {
  boardContextMenu.value.show = false
}

async function handleDuplicate() {
  const board = boardContextMenu.value.board
  closeBoardContext()
  const dup = await store.duplicateBoard(board.id)
  if (dup) {
    toast.show('Board duplicated', 'success')
    emit('select', dup.id)
  }
}

async function handleArchive() {
  const board = boardContextMenu.value.board
  closeBoardContext()
  await store.updateBoard(board.id, { archived: board.archived ? 0 : 1 })
  toast.show(board.archived ? 'Board unarchived' : 'Board archived', 'success')
}

async function handleDeleteBoard() {
  const board = boardContextMenu.value.board
  closeBoardContext()
  if (confirm(`Delete "${board.name}"? This cannot be undone.`)) {
    await store.deleteBoard(board.id)
    toast.show('Board deleted', 'success')
  }
}

function handleMoveToFolder() {
  moveBoardTarget.value = boardContextMenu.value.board
  closeBoardContext()
  showMoveBoardModal.value = true
}

async function confirmMoveBoard(folderId) {
  if (!moveBoardTarget.value) return
  await store.moveBoard(moveBoardTarget.value.id, folderId)
  showMoveBoardModal.value = false
  moveBoardTarget.value = null
  toast.show('Board moved', 'success')
}

// ------ CSV Export / Import ------

async function handleExportTexts() {
  const board = boardContextMenu.value.board
  closeBoardContext()
  const ok = await store.exportTexts(board.id)
  if (ok) {
    toast.show('Text items exported', 'success')
  } else {
    toast.show('Export failed', 'error')
  }
}

function handleImportTexts() {
  csvImportBoardId.value = boardContextMenu.value.board?.id
  closeBoardContext()
  csvFileInput.value?.click()
}

async function onCsvFileSelected(event) {
  const file = event.target.files?.[0]
  if (!file || !csvImportBoardId.value) return
  try {
    const result = await store.importTexts(csvImportBoardId.value, file)
    if (result) {
      toast.show(`Updated ${result.updated} items, skipped ${result.skipped}`, 'success')
    } else {
      toast.show('Import failed', 'error')
    }
  } catch (e) {
    toast.show(e.message || 'Import failed', 'error')
  }
  csvFileInput.value.value = ''
  csvImportBoardId.value = null
}

async function handleExportTextsFromHeader() {
  if (!props.selectedId) return
  const ok = await store.exportTexts(props.selectedId)
  if (ok) {
    toast.show('Text items exported', 'success')
  } else {
    toast.show('Export failed', 'error')
  }
}

function handleImportTextsFromHeader() {
  if (!props.selectedId) return
  csvImportBoardId.value = props.selectedId
  csvFileInput.value?.click()
}

// ------ Folder context menu ------

function openFolderContext(event, folder) {
  closeBoardContext()
  folderContextMenu.value = { show: true, x: event.clientX, y: event.clientY, folder }
}

function closeFolderContext() {
  folderContextMenu.value.show = false
}

function handleRenameFolder() {
  renameFolderTarget.value = folderContextMenu.value.folder
  renameFolderName.value = folderContextMenu.value.folder.name
  closeFolderContext()
  showRenameFolderModal.value = true
  nextTick(() => renameFolderInput.value?.focus())
}

async function confirmRenameFolder() {
  if (!renameFolderName.value.trim() || !renameFolderTarget.value) return
  await store.updateFolder(renameFolderTarget.value.id, { name: renameFolderName.value.trim() })
  showRenameFolderModal.value = false
  renameFolderTarget.value = null
  toast.show('Folder renamed', 'success')
}

function handleNewSubfolder() {
  createFolderParentId.value = folderContextMenu.value.folder.id
  closeFolderContext()
  newFolderName.value = ''
  newFolderColor.value = folderColors[0]
  showCreateFolderModal.value = true
  nextTick(() => folderNameInput.value?.focus())
}

async function handleDeleteFolder() {
  const folder = folderContextMenu.value.folder
  closeFolderContext()
  if (confirm(`Delete folder "${folder.name}"? Boards inside will be moved to root.`)) {
    await store.deleteFolder(folder.id)
    toast.show('Folder deleted', 'success')
  }
}

// ------ Drag & drop on root area ------

function onBoardDragStart(event, board) {
  event.dataTransfer.setData('application/mood-board-id', String(board.id))
  event.dataTransfer.effectAllowed = 'move'
}

function onRootDragOver(event) {
  if (event.dataTransfer.types.includes('application/mood-board-id')) {
    rootDragOver.value = true
    event.dataTransfer.dropEffect = 'move'
  }
}

function onRootDragLeave() {
  rootDragOver.value = false
}

function onRootDrop(event) {
  rootDragOver.value = false
  const boardId = event.dataTransfer.getData('application/mood-board-id')
  if (boardId) {
    store.moveBoard(parseInt(boardId), null)
    toast.show('Board moved to root', 'success')
  }
}

// ------ Close menus on outside click ------

function onDocumentClick(e) {
  closeBoardContext()
  closeFolderContext()
  if (createMenuRef.value && !createMenuRef.value.contains(e.target)) {
    showCreateMenu.value = false
  }
}

onMounted(() => {
  store.fetchBoards()
  store.fetchFolders()
  document.addEventListener('click', onDocumentClick)
})

onUnmounted(() => {
  document.removeEventListener('click', onDocumentClick)
})
</script>
