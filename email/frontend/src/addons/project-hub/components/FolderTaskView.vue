<script setup>
import { ref, computed, watch, defineAsyncComponent } from 'vue'
import { useProjectHubStore } from '@/addons/project-hub/stores/projectHub'
import api from '@/services/api'

const LinkBoardDialog = defineAsyncComponent(() => import('./LinkBoardDialog.vue'))
const FolderTableView = defineAsyncComponent(() => import('./FolderTableView.vue'))
const FolderFileManager = defineAsyncComponent(() => import('./FolderFileManager.vue'))
const FolderOverviewTab = defineAsyncComponent(() => import('./FolderOverviewTab.vue'))

const emit = defineEmits(['open-card', 'select-board'])

const hubStore = useProjectHubStore()

const viewMode = computed({
  get: () => {
    const m = hubStore.folderViewMode
    if (m === 'tasks' || m === 'list') return 'table'
    if (m === 'board') return 'overview'
    return m || 'overview'
  },
  set: (v) => { hubStore.folderViewMode = v }
})

const overview = computed(() => hubStore.folderOverview)
const boards = computed(() => overview.value?.boards || [])
const loading = ref(false)
const showLinkDialog = ref(false)
const creatingBoard = ref(false)

const hasBoards = computed(() => boards.value.length > 0)

const boardsData = ref({})

const allTasks = computed(() => {
  const tasks = []
  for (const bd of Object.values(boardsData.value)) {
    for (const list of (bd.lists || [])) {
      for (const card of (list.cards || [])) {
        if (!card.parent_card_id) {
          tasks.push({
            ...card,
            board_id: bd.board_id,
            board_name: bd.board_name,
            board_owner: bd.board_owner,
            list_name: list.name,
            list_id: list.id,
          })
        }
      }
    }
  }
  return tasks
})

let loadVersion = 0

watch(() => hubStore.activeFolderId, (folderId) => {
  if (folderId) {
    boardsData.value = {}
    if (boards.value.length > 0) loadBoardsData()
  }
}, { immediate: true })

watch(() => hubStore.folderOverviewLoading, (isLoading, wasLoading) => {
  if (wasLoading && !isLoading && boards.value.length > 0 && hubStore.activeFolderId) {
    loadBoardsData()
  }
})

async function loadBoardsData() {
  const folderId = hubStore.activeFolderId
  if (!folderId) return
  const folderBoards = boards.value
  if (folderBoards.length === 0) return
  const thisVersion = ++loadVersion
  loading.value = true
  try {
    // ONE batched request for all boards in the folder.
    const boardIds = folderBoards.map(b => b.board_id).filter(Boolean)
    let boardsMap = {}
    try {
      const { data } = await api.post('/boards/batch-fetch', { board_ids: boardIds })
      if (thisVersion !== loadVersion) return
      boardsMap = data?.data?.boards || data?.boards || {}
    } catch (err) {
      console.error('Failed to batch-fetch boards:', err)
      if (thisVersion !== loadVersion) return
    }

    const result = {}
    for (const board of folderBoards) {
      const boardObj = boardsMap[board.board_id] || boardsMap[String(board.board_id)] || null
      result[board.board_id] = {
        board_id: board.board_id,
        board_name: board.board_name || boardObj?.name || 'Board',
        board_owner: board.owner_email || boardObj?.owner_email || '',
        lists: boardObj?.lists || [],
      }
    }
    if (thisVersion === loadVersion) boardsData.value = result
  } finally {
    if (thisVersion === loadVersion) loading.value = false
  }
}

async function autoCreateBoard() {
  const folderId = hubStore.activeFolderId
  const folderName = hubStore.activeFolder?.name || 'Tasks'
  if (!folderId || creatingBoard.value) return
  creatingBoard.value = true
  try {
    const { data } = await api.post('/boards', { name: folderName })
    const board = data.data?.board || data.board || data
    if (board?.id) {
      await hubStore.linkBoard(folderId, board.id)
      await hubStore.fetchHierarchy()
      await hubStore.fetchFolderOverview(folderId)
      await loadBoardsData()
    }
  } catch (err) { console.error('Failed to auto-create board:', err) }
  finally { creatingBoard.value = false }
}

function handleBoardLinked() {
  hubStore.fetchHierarchy()
  if (hubStore.activeFolderId) hubStore.fetchFolderOverview(hubStore.activeFolderId)
  setTimeout(() => loadBoardsData(), 500)
}
</script>

<template>
  <div class="flex-1 flex flex-col overflow-hidden">
    <!-- Tab bar -->
    <div class="flex items-center justify-between px-5 py-0 border-b border-surface-200 dark:border-surface-700 bg-white dark:bg-surface-900 shrink-0">
      <div class="flex items-center">
        <button
          class="px-4 py-2.5 text-[13px] font-semibold transition-colors border-b-2 -mb-px"
          :class="viewMode === 'overview'
            ? 'border-primary-500 text-primary-600 dark:text-primary-400'
            : 'border-transparent text-surface-500 hover:text-surface-700 dark:hover:text-surface-300'"
          @click="viewMode = 'overview'"
        >
          <span class="material-symbols-rounded text-[16px] align-middle mr-1">dashboard</span>
          Overview
        </button>
        <button
          class="px-4 py-2.5 text-[13px] font-semibold transition-colors border-b-2 -mb-px"
          :class="viewMode === 'table'
            ? 'border-primary-500 text-primary-600 dark:text-primary-400'
            : 'border-transparent text-surface-500 hover:text-surface-700 dark:hover:text-surface-300'"
          @click="viewMode = 'table'"
        >
          <span class="material-symbols-rounded text-[16px] align-middle mr-1">table_chart</span>
          Table
        </button>

        <button
          class="px-4 py-2.5 text-[13px] font-semibold transition-colors border-b-2 -mb-px"
          :class="viewMode === 'files'
            ? 'border-primary-500 text-primary-600 dark:text-primary-400'
            : 'border-transparent text-surface-500 hover:text-surface-700 dark:hover:text-surface-300'"
          @click="viewMode = 'files'"
        >
          <span class="material-symbols-rounded text-[16px] align-middle mr-1">folder_open</span>
          Files
        </button>
      </div>

    </div>

    <!-- Loading -->
    <div v-if="loading" class="flex items-center justify-center py-20 flex-1">
      <div class="animate-spin rounded-full h-8 w-8 border-2 border-primary-500 border-t-transparent"></div>
    </div>

    <!-- No boards linked (show on table tab) -->
    <div v-else-if="!hasBoards && viewMode === 'table'" class="text-center py-16 text-surface-400 flex-1">
      <span class="material-symbols-rounded text-5xl mb-3 block">view_kanban</span>
      <p class="text-base font-medium text-surface-600 dark:text-surface-300 mb-1">No boards linked to this folder</p>
      <p class="text-sm mb-4">Create a board to start adding tasks, or link an existing one</p>
      <div class="flex items-center justify-center gap-3">
        <button
          class="px-5 py-2.5 rounded-full text-sm font-medium bg-primary-500 hover:bg-primary-600 text-white transition-colors inline-flex items-center gap-1.5 disabled:opacity-50"
          :disabled="creatingBoard" @click="autoCreateBoard"
        >
          <span class="material-symbols-rounded text-[16px]">{{ creatingBoard ? 'progress_activity' : 'add' }}</span>
          {{ creatingBoard ? 'Creating...' : 'Create Board & Start' }}
        </button>
        <button
          class="px-5 py-2.5 rounded-full text-sm font-medium bg-surface-100 dark:bg-surface-700 text-surface-600 dark:text-surface-400 hover:bg-surface-200 dark:hover:bg-surface-600 transition-colors inline-flex items-center gap-1.5"
          @click="showLinkDialog = true"
        >
          <span class="material-symbols-rounded text-[16px]">add_link</span>
          Link Existing
        </button>
      </div>
    </div>

    <!-- ==================== FOLDER OVERVIEW ==================== -->
    <FolderOverviewTab
      v-else-if="viewMode === 'overview'"
      class="flex-1 overflow-auto px-6 py-5"
      @open-card="(card) => emit('open-card', card)"
      @select-board="(id) => emit('select-board', id)"
    />

    <!-- ==================== TABLE VIEW ==================== -->
    <FolderTableView
      v-else-if="viewMode === 'table'"
      :tasks="allTasks"
      :boards="boards"
      @open-card="(task) => emit('open-card', task)"
    />

    <!-- Files view -->
    <FolderFileManager v-else-if="viewMode === 'files'" />


    <!-- Link Board Dialog -->
    <Teleport to="body">
      <LinkBoardDialog
        v-if="showLinkDialog && hubStore.activeFolderId"
        :folder-id="hubStore.activeFolderId"
        :folder-name="hubStore.activeFolder?.name || ''"
        @close="showLinkDialog = false"
        @linked="handleBoardLinked"
      />
    </Teleport>
  </div>
</template>
