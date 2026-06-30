<script setup>
import { ref, computed, onMounted, watch, defineAsyncComponent } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import { useProjectHubStore } from '@/addons/project-hub/stores/projectHub'
import { useBoardsStore } from '@/addons/kanban-boards/stores/boards'
import { useClientsStore } from '@/stores/clients'
import { useAuthStore } from '@/stores/auth'
import { useToastStore } from '@/stores/toast'
import { useColleaguesStore } from '@/addons/team/stores/colleagues'
import api from '@/services/api'
import { fetchUnseenCounts } from '@/addons/project-hub/services/projectHubFileApi'
import { useSidebarDragDrop } from '@/addons/project-hub/composables/useSidebarDragDrop'
import SidebarContextMenu from './SidebarContextMenu.vue'

const LinkBoardDialog = defineAsyncComponent(() => import('./LinkBoardDialog.vue'))
const SpaceSettings = defineAsyncComponent(() => import('./SpaceSettings.vue'))
const FolderSettings = defineAsyncComponent(() => import('./FolderSettings.vue'))
const TeamPresencePanel = defineAsyncComponent(() => import('./TeamPresencePanel.vue'))

const emit = defineEmits(['select-board', 'select-folder', 'select-my-work'])

const router = useRouter()
const route = useRoute()
const hubStore = useProjectHubStore()
const boardsStore = useBoardsStore()
const clientsStore = useClientsStore()
const authStore = useAuthStore()
const toast = useToastStore()
const colleaguesStore = useColleaguesStore()
const {
  dragState, dropTarget,
  onDragStart, onDragEnd, onDragOver, onDragLeave,
  onDropSpace, onDropFolder, isDropTarget,
} = useSidebarDragDrop(hubStore, api)

const currentEmail = computed(() => authStore.userEmail?.toLowerCase() || '')

function isSharedBoard(board) {
  const owner = (board.owner_email || '').toLowerCase()
  return owner && owner !== currentEmail.value
}

function ownerLabel(board) {
  const owner = (board.owner_email || '')
  if (!owner) return ''
  const name = owner.split('@')[0]
  return name.charAt(0).toUpperCase() + name.slice(1)
}

const showClientPicker = ref({ active: false, spaceId: null, currentClientId: null })
const selectedClientId = ref(null)
const clientPickerLoading = ref(false)

const expandedSpaces = hubStore.sidebarExpandedSpaces
const expandedFolders = hubStore.sidebarExpandedFolders
const showTeamPresence = ref(false)
const showCreateSpace = ref(false)
const newSpaceName = ref('')
const showCreateFolder = ref({ active: false, spaceId: null })
const newFolderName = ref('')
const contextMenu = ref({ show: false, x: 0, y: 0, type: null, item: null })

const showLinkBoardDialog = ref({ active: false, folderId: null, folderName: '' })
const showSpaceSettings = ref({ active: false, space: null })
const showFolderSettings = ref({ active: false, folder: null, spaceId: null })
const showCreateBoard = ref({ active: false, folderId: null })
const newBoardName = ref('')
const renamingBoard = ref({ active: false, boardId: null, folderId: null })
const renameBoardName = ref('')

const routeViews = ['workload', 'ph-director', 'ph-settings', 'time']
const isOnRoutePage = computed(() => routeViews.includes(route.name))
const hasBoardSelection = computed(() => (hubStore.activeView || '').startsWith('board:'))
const isActiveView = (view) => hubStore.activeView === view && (!isOnRoutePage.value || hasBoardSelection.value)

function isSpaceActive(space) {
  const av = hubStore.activeView || ''
  if (av === `space:${space.id}`) return true
  for (const folder of (space.folders || [])) {
    if (av === `folder:${folder.id}`) return true
    for (const board of (folder.boards || [])) {
      if (av === `board:${board.board_id}`) return true
    }
  }
  return false
}

function isFolderActive(folder) {
  const av = hubStore.activeView || ''
  if (av === `folder:${folder.id}`) return true
  for (const board of (folder.boards || [])) {
    if (av === `board:${board.board_id}`) return true
  }
  return false
}

const isUnsortedActive = computed(() => {
  return (hubStore.activeView || '').startsWith('unsorted-board:')
})

const folderUnseenCounts = ref({})

async function loadUnseenCounts() {
  const allFolderIds = hubStore.spacesWithFolders.flatMap(s => (s.folders || []).map(f => f.id))
  if (!allFolderIds.length) return
  try {
    folderUnseenCounts.value = await fetchUnseenCounts(allFolderIds)
  } catch (err) {
    console.error('[Sidebar] unseen counts error:', err)
  }
}

onMounted(async () => {
  if (!hubStore.hierarchyLoaded) {
    await hubStore.fetchHierarchy()
  }
  hubStore.ensureSpacesExpanded()
  loadUnseenCounts()
})

watch(() => hubStore.spaces, () => {
  hubStore.ensureSpacesExpanded()
})

function toggleSpace(id) {
  hubStore.toggleSidebarSpace(id)
}

function selectSpace(space) {
  hubStore.activeView = `space:${space.id}`
  hubStore.activeSpace = space
  hubStore.activeFolder = null
  hubStore.ensureSpacesExpanded()
  hubStore.toggleSidebarSpace(space.id, true)
  router.push({ name: 'boards' })
}

function toggleFolder(id) {
  hubStore.toggleSidebarFolder(id)
}

function selectMyWork() {
  hubStore.selectMyWork()
  emit('select-my-work')
}

function selectFolder(folder, space) {
  hubStore.selectFolder(folder, space)
  emit('select-folder', folder)
}

function selectBoard(boardId) {
  hubStore.selectBoard(boardId)
  emit('select-board', boardId)
}

function selectUnsortedBoard(boardId) {
  hubStore.selectUnsortedBoard(boardId)
  emit('select-board', boardId)
}

// Create space
async function handleCreateSpace() {
  if (!newSpaceName.value.trim()) return
  try {
    await hubStore.createSpace({ name: newSpaceName.value.trim() })
    newSpaceName.value = ''
    showCreateSpace.value = false
  } catch (err) {
    console.error('Failed to create space:', err)
  }
}

// Create folder
function startCreateFolder(spaceId) {
  showCreateFolder.value = { active: true, spaceId }
  newFolderName.value = ''
}

async function handleCreateFolder() {
  if (!newFolderName.value.trim()) return
  try {
    await hubStore.createFolder(showCreateFolder.value.spaceId, { name: newFolderName.value.trim() })
    newFolderName.value = ''
    showCreateFolder.value = { active: false, spaceId: null }
  } catch (err) {
    console.error('Failed to create folder:', err)
  }
}

// Context menu
function showContextMenu(e, type, item) {
  e.preventDefault()
  contextMenu.value = { show: true, x: e.clientX, y: e.clientY, type, item }
}

function closeContextMenu() {
  contextMenu.value = { show: false, x: 0, y: 0, type: null, item: null }
}

async function toggleFavoriteSpace(space) {
  closeContextMenu()
  try {
    await api.put(`/project-hub/spaces/${space.id}`, { is_favorite: space.is_favorite ? 0 : 1 })
    await hubStore.fetchHierarchy()
  } catch (err) {
    console.error('Failed to toggle favorite:', err)
  }
}

function viewSpaceTime(space) {
  closeContextMenu()
  const clientId = space.client_id
  if (clientId) router.push({ path: '/workload', query: { mode: 'task-time', client_id: clientId } })
  else router.push({ path: '/workload', query: { mode: 'task-time' } })
}

async function handleDuplicateFolder(folderId) {
  closeContextMenu()
  try {
    await api.post(`/project-hub/folders/${folderId}/duplicate`)
    await hubStore.fetchHierarchy()
  } catch (err) {
    console.error('Failed to duplicate folder:', err)
  }
}

function viewFolderTime(folder) {
  closeContextMenu()
  router.push({ path: '/workload', query: { mode: 'task-time' } })
}

async function handleDeleteSpace(id) {
  if (confirm('Delete this space and all its folders?')) {
    await hubStore.deleteSpace(id)
  }
  closeContextMenu()
}

async function handleDeleteFolder(id) {
  if (confirm('Delete this folder? Boards will move to Unsorted.')) {
    await hubStore.deleteFolder(id)
  }
  closeContextMenu()
}

function openLinkBoardDialog(folder) {
  showLinkBoardDialog.value = { active: true, folderId: folder.id, folderName: folder.name }
  closeContextMenu()
}

function openEditSpace(space) {
  showSpaceSettings.value = { active: true, space }
  closeContextMenu()
}

function openEditFolder(folder, spaceId) {
  showFolderSettings.value = { active: true, folder, spaceId }
  closeContextMenu()
}

function startCreateBoard(folderId) {
  showCreateBoard.value = { active: true, folderId }
  newBoardName.value = ''
  hubStore.expandSidebarFolder(folderId)
}

async function handleCreateBoard() {
  if (!newBoardName.value.trim()) return
  const folderId = showCreateBoard.value.folderId
  try {
    const board = await boardsStore.createBoard({ name: newBoardName.value.trim() })
    if (board?.id) {
      await hubStore.linkBoard(folderId, board.id)
    }
    newBoardName.value = ''
    showCreateBoard.value = { active: false, folderId: null }
  } catch (err) {
    console.error('Failed to create board:', err)
  }
}

function startRenameBoard(board, folderId) {
  renamingBoard.value = { active: true, boardId: board.board_id, folderId }
  renameBoardName.value = board.board_name
  closeContextMenu()
}

async function handleRenameBoard() {
  if (!renameBoardName.value.trim()) return
  try {
    await boardsStore.updateBoard(renamingBoard.value.boardId, { name: renameBoardName.value.trim() })
    await hubStore.fetchHierarchy()
    renamingBoard.value = { active: false, boardId: null, folderId: null }
    renameBoardName.value = ''
  } catch (err) {
    console.error('Failed to rename board:', err)
  }
}

function cancelRenameBoard() {
  renamingBoard.value = { active: false, boardId: null, folderId: null }
  renameBoardName.value = ''
}

async function handleUnlinkBoard(boardId, folderId) {
  closeContextMenu()
  await hubStore.unlinkBoard(folderId, boardId)
}

async function handleDeleteBoard(boardId) {
  closeContextMenu()
  if (confirm('Permanently delete this board and all its cards?')) {
    await boardsStore.deleteBoard(boardId)
    await hubStore.fetchHierarchy()
  }
}

async function handleArchiveBoard(boardId) {
  closeContextMenu()
  await boardsStore.archiveBoard(boardId)
  await hubStore.fetchHierarchy()
}

async function openClientPicker(space) {
  closeContextMenu()
  showClientPicker.value = { active: true, spaceId: space.id, currentClientId: space.client_id || null }
  selectedClientId.value = space.client_id || null
  if (!clientsStore.clients.length) {
    clientPickerLoading.value = true
    await clientsStore.fetchClients()
    clientPickerLoading.value = false
  }
}

async function assignClientToSpace() {
  const spaceId = showClientPicker.value.spaceId
  const newClientId = selectedClientId.value
  try {
    await hubStore.updateSpace(spaceId, { client_id: newClientId || null })
    toast.success(newClientId ? 'Client assigned to space' : 'Client removed from space')
    showClientPicker.value = { active: false, spaceId: null, currentClientId: null }
  } catch (err) {
    console.error('Failed to assign client:', err)
    toast.error('Failed to assign client')
  }
}

async function removeClientFromSpace() {
  const spaceId = showClientPicker.value.spaceId
  try {
    await hubStore.updateSpace(spaceId, { client_id: null })
    toast.success('Client removed from space')
    showClientPicker.value = { active: false, spaceId: null, currentClientId: null }
  } catch (err) {
    console.error('Failed to remove client:', err)
    toast.error('Failed to remove client')
  }
}

function handleBoardLinked() {
  hubStore.fetchHierarchy().then(() => loadUnseenCounts())
  if (hubStore.activeFolderId) {
    hubStore.fetchFolderOverview(hubStore.activeFolderId)
  }
}

function handleSettingsSaved() {
  hubStore.fetchHierarchy()
}

</script>

<template>
  <aside class="w-64 border-r border-surface-200 dark:border-surface-700 bg-surface-50 dark:bg-surface-900 flex flex-col h-full overflow-hidden select-none">
    <!-- Quick Nav (compact icon row) -->
    <div class="px-3 pt-3 pb-1 flex items-center gap-1">
      <button
        class="flex-1 flex items-center justify-center p-2 rounded-lg transition-colors"
        :class="route.name === 'workload' && (!route.query.mode || route.query.mode === 'my-work')
          ? 'bg-primary-100 dark:bg-primary-900/30 text-primary-600 dark:text-primary-400'
          : 'text-surface-500 hover:bg-surface-100 dark:hover:bg-surface-700'"
        title="My Work"
        @click="router.push({ name: 'workload', query: { mode: 'my-work' } })"
      >
        <span class="material-symbols-rounded text-[20px]">task_alt</span>
      </button>
      <button
        class="flex-1 flex items-center justify-center p-2 rounded-lg transition-colors"
        :class="route.name === 'workload' && route.query.mode === 'team'
          ? 'bg-primary-100 dark:bg-primary-900/30 text-primary-600 dark:text-primary-400'
          : 'text-surface-500 hover:bg-surface-100 dark:hover:bg-surface-700'"
        title="Team Overview"
        @click="router.push({ name: 'workload', query: { mode: 'team' } })"
      >
        <span class="material-symbols-rounded text-[20px]">monitoring</span>
      </button>
      <button
        v-if="colleaguesStore.isAdmin"
        class="flex-1 flex items-center justify-center p-2 rounded-lg transition-colors"
        :class="route.name === 'ph-director'
          ? 'bg-primary-100 dark:bg-primary-900/30 text-primary-600 dark:text-primary-400'
          : 'text-surface-500 hover:bg-surface-100 dark:hover:bg-surface-700'"
        title="Director Dashboard"
        @click="router.push({ name: 'ph-director' })"
      >
        <span class="material-symbols-rounded text-[20px]">leaderboard</span>
      </button>
      <button
        class="flex-1 flex items-center justify-center p-2 rounded-lg transition-colors"
        :class="route.name === 'workload' && route.query.mode === 'task-time'
          ? 'bg-primary-100 dark:bg-primary-900/30 text-primary-600 dark:text-primary-400'
          : 'text-surface-500 hover:bg-surface-100 dark:hover:bg-surface-700'"
        title="Time"
        @click="router.push({ path: '/workload', query: { mode: 'task-time' } })"
      >
        <span class="material-symbols-rounded text-[20px]">schedule</span>
      </button>
      <button
        class="flex-1 flex items-center justify-center p-2 rounded-lg transition-colors"
        :class="route.name === 'ph-settings'
          ? 'bg-primary-100 dark:bg-primary-900/30 text-primary-600 dark:text-primary-400'
          : 'text-surface-500 hover:bg-surface-100 dark:hover:bg-surface-700'"
        title="Settings"
        @click="router.push({ name: 'ph-settings' })"
      >
        <span class="material-symbols-rounded text-[20px]">settings</span>
      </button>
    </div>

    <div class="mx-3 my-1 border-t border-surface-200 dark:border-surface-700"></div>

    <!-- Spaces Tree -->
    <div class="flex-1 overflow-y-auto px-3 py-1 space-y-0.5">
      <div class="flex items-center justify-between px-2 mb-1">
        <span class="text-[11px] font-semibold text-surface-400 dark:text-surface-500 uppercase tracking-wider">Spaces</span>
        <div class="flex items-center gap-0.5">
          <button
            class="p-0.5 rounded hover:bg-surface-200 dark:hover:bg-surface-600 transition-colors"
            title="Expand all"
            @click="hubStore.expandAllSidebar()"
          >
            <span class="material-symbols-rounded text-[14px] text-surface-400 dark:text-surface-500">unfold_more</span>
          </button>
          <button
            class="p-0.5 rounded hover:bg-surface-200 dark:hover:bg-surface-600 transition-colors"
            title="Collapse all"
            @click="hubStore.collapseAllSidebar()"
          >
            <span class="material-symbols-rounded text-[14px] text-surface-400 dark:text-surface-500">unfold_less</span>
          </button>
        </div>
      </div>

      <div v-for="space in hubStore.spacesWithFolders" :key="space.id" class="mb-0.5">
        <!-- Space row -->
        <div
          class="flex items-center gap-1.5 px-2 py-1.5 rounded-lg cursor-pointer group text-sm transition-all"
          :class="[
            isSpaceActive(space)
              ? 'bg-primary-50/50 dark:bg-primary-900/10 hover:bg-primary-50 dark:hover:bg-primary-900/20'
              : 'hover:bg-surface-100 dark:hover:bg-surface-700',
            isDropTarget('space', space.id) ? 'border-t-2 border-primary-500' : ''
          ]"
          draggable="true"
          @dragstart="onDragStart($event, 'space', space.id)"
          @dragend="onDragEnd"
          @dragover="onDragOver($event, 'space', space.id)"
          @dragleave="onDragLeave"
          @drop="onDropSpace($event, space.id)"
          @click="selectSpace(space)"
          @contextmenu="showContextMenu($event, 'space', space)"
        >
          <span class="material-symbols-rounded text-[16px] transition-transform cursor-pointer" :class="expandedSpaces[space.id] ? 'rotate-90' : ''" @click.stop="toggleSpace(space.id)">
            chevron_right
          </span>
          <span
            class="w-2 h-2 rounded-full shrink-0"
            :style="{ backgroundColor: space.color || '#6366f1' }"
          ></span>
          <span class="truncate font-medium" :class="isSpaceActive(space) ? 'text-primary-600 dark:text-primary-400' : 'text-surface-700 dark:text-surface-300'">{{ space.name }}</span>
          <span v-if="space.client_name" class="text-[10px] text-surface-400 dark:text-surface-500 truncate max-w-[80px]" :title="space.client_name">{{ space.client_name }}</span>
          <button
            class="ml-auto opacity-0 group-hover:opacity-100 material-symbols-rounded text-[16px] text-surface-400 hover:text-surface-600 dark:hover:text-surface-300"
            @click.stop="startCreateFolder(space.id)"
            title="Add folder"
          >
            create_new_folder
          </button>
        </div>

        <!-- Folders inside space -->
        <div v-if="expandedSpaces[space.id]" class="ml-3 border-l-2 border-surface-200 dark:border-surface-700 space-y-0.5 pl-0.5">
          <div v-for="folder in space.folders" :key="folder.id">
            <!-- Folder row -->
            <div
              class="flex items-center gap-1.5 px-2 py-1.5 rounded-lg cursor-pointer group text-sm transition-all"
              :class="[
                isActiveView(`folder:${folder.id}`)
                  ? 'bg-primary-50 dark:bg-primary-900/20 text-primary-700 dark:text-primary-300'
                  : isFolderActive(folder)
                    ? 'bg-primary-50/40 dark:bg-primary-900/10 text-primary-600 dark:text-primary-400'
                    : 'hover:bg-surface-100 dark:hover:bg-surface-700 text-surface-600 dark:text-surface-400',
                isDropTarget('folder', folder.id) ? 'border-t-2 border-primary-500' : ''
              ]"
              draggable="true"
              @dragstart="onDragStart($event, 'folder', folder.id, space.id)"
              @dragend="onDragEnd"
              @dragover="onDragOver($event, 'folder', folder.id)"
              @dragleave="onDragLeave"
              @drop="onDropFolder($event, folder.id, space.id)"
              @click="selectFolder(folder, space)"
              @contextmenu="showContextMenu($event, 'folder', folder)"
            >
              <span
                class="material-symbols-rounded text-[16px] transition-transform cursor-pointer"
                :class="expandedFolders[folder.id] ? 'rotate-90' : ''"
                @click.stop="toggleFolder(folder.id)"
              >
                chevron_right
              </span>
              <span class="material-symbols-rounded text-[16px]">folder</span>
              <span class="truncate flex-1">{{ folder.name }}</span>
              <span
                v-if="folderUnseenCounts[folder.id]"
                class="w-2 h-2 rounded-full bg-primary-500 shrink-0"
                :title="`${folderUnseenCounts[folder.id]} new files/links`"
              ></span>
              <button
                class="opacity-0 group-hover:opacity-100 material-symbols-rounded text-[16px] text-surface-400 hover:text-green-500 shrink-0"
                @click.stop="startCreateBoard(folder.id)"
                title="Create new board"
              >
                add
              </button>
              <button
                class="opacity-0 group-hover:opacity-100 material-symbols-rounded text-[16px] text-surface-400 hover:text-primary-500 shrink-0"
                @click.stop="openLinkBoardDialog(folder)"
                title="Link existing board"
              >
                add_link
              </button>
            </div>

            <!-- Boards inside folder -->
            <div v-if="expandedFolders[folder.id]" class="ml-5 pl-2.5 border-l border-surface-200/60 dark:border-surface-700/60 space-y-px">
              <template v-for="board in folder.boards" :key="board.board_id">
                <!-- Inline rename mode -->
                <div
                  v-if="renamingBoard.active && renamingBoard.boardId === board.board_id"
                  class="flex items-center gap-1 px-1 py-0.5"
                >
                  <input
                    v-model="renameBoardName"
                    class="flex-1 text-[13px] px-2 py-1 rounded-md border border-primary-400 dark:border-primary-600 bg-white dark:bg-surface-700 text-surface-800 dark:text-surface-200 outline-none focus:ring-1 focus:ring-primary-500"
                    @keydown.enter="handleRenameBoard"
                    @keydown.escape="cancelRenameBoard"
                    autofocus
                  />
                  <button class="material-symbols-rounded text-[16px] text-green-500 hover:text-green-600" @click="handleRenameBoard">check</button>
                  <button class="material-symbols-rounded text-[16px] text-surface-400 hover:text-surface-600" @click="cancelRenameBoard">close</button>
                </div>
                <!-- Normal board row -->
                <div
                  v-else
                  class="flex items-center gap-2 px-2 py-1 rounded-md cursor-pointer text-[13px] transition-colors group"
                  :class="isActiveView(`board:${board.board_id}`)
                    ? 'bg-primary-50 dark:bg-primary-900/20 text-primary-700 dark:text-primary-300'
                    : 'hover:bg-surface-100 dark:hover:bg-surface-700 text-surface-500 dark:text-surface-400'"
                  @click="selectBoard(board.board_id)"
                  @contextmenu="showContextMenu($event, 'board', { ...board, folderId: folder.id })"
                >
                  <span class="material-symbols-rounded text-[14px] opacity-60">view_kanban</span>
                  <span class="truncate flex-1">{{ board.board_name }}</span>
                  <span
                    v-if="isSharedBoard(board)"
                    class="text-[9px] px-1.5 py-0.5 rounded-full bg-amber-100 dark:bg-amber-900/30 text-amber-600 dark:text-amber-400 font-medium shrink-0 leading-none"
                    :title="'Shared by ' + ownerLabel(board)"
                  >{{ ownerLabel(board) }}</span>
                  <button
                    class="opacity-0 group-hover:opacity-100 material-symbols-rounded text-[14px] text-surface-400 hover:text-surface-600 dark:hover:text-surface-300 shrink-0"
                    @click.stop="showContextMenu($event, 'board', { ...board, folderId: folder.id })"
                    title="Board options"
                  >
                    more_horiz
                  </button>
                </div>
              </template>

              <!-- Inline create board -->
              <div v-if="showCreateBoard.active && showCreateBoard.folderId === folder.id" class="flex items-center gap-1 px-1 py-1">
                <input
                  v-model="newBoardName"
                  class="flex-1 text-[13px] px-2 py-1 rounded-md border border-surface-300 dark:border-surface-600 bg-white dark:bg-surface-700 text-surface-800 dark:text-surface-200 outline-none focus:ring-1 focus:ring-primary-500"
                  placeholder="Board name"
                  @keydown.enter="handleCreateBoard"
                  @keydown.escape="showCreateBoard = { active: false, folderId: null }"
                  autofocus
                />
                <button class="material-symbols-rounded text-[16px] text-green-500 hover:text-green-600" @click="handleCreateBoard">check</button>
                <button class="material-symbols-rounded text-[16px] text-surface-400 hover:text-surface-600" @click="showCreateBoard = { active: false, folderId: null }">close</button>
              </div>
            </div>
          </div>

          <!-- Inline create folder -->
          <div v-if="showCreateFolder.active && showCreateFolder.spaceId === space.id" class="flex items-center gap-1 px-2 py-1">
            <input
              v-model="newFolderName"
              class="flex-1 text-sm px-2 py-1 rounded-lg border border-surface-300 dark:border-surface-600 bg-white dark:bg-surface-700 text-surface-800 dark:text-surface-200 outline-none focus:ring-1 focus:ring-primary-500"
              placeholder="Folder name"
              @keydown.enter="handleCreateFolder"
              @keydown.escape="showCreateFolder = { active: false, spaceId: null }"
              autofocus
            />
            <button class="material-symbols-rounded text-[18px] text-green-500 hover:text-green-600" @click="handleCreateFolder">check</button>
            <button class="material-symbols-rounded text-[18px] text-surface-400 hover:text-surface-600" @click="showCreateFolder = { active: false, spaceId: null }">close</button>
          </div>
        </div>
      </div>

      <!-- Unsorted boards -->
      <div v-if="hubStore.unsortedBoards.length > 0" class="mt-2">
        <div class="mx-3 mb-1 border-t border-surface-200 dark:border-surface-700"></div>
        <div class="flex items-center px-2 mb-1">
          <span
            class="text-[11px] font-semibold uppercase tracking-wider"
            :class="isUnsortedActive ? 'text-primary-500 dark:text-primary-400' : 'text-surface-400 dark:text-surface-500'"
          >Unsorted</span>
        </div>
        <div
          v-for="board in hubStore.unsortedBoards"
          :key="board.id"
          class="flex items-center gap-2 px-2 py-1.5 rounded-lg cursor-pointer text-[13px] transition-colors group"
          :class="isActiveView(`unsorted-board:${board.id}`)
            ? 'bg-primary-50 dark:bg-primary-900/20 text-primary-700 dark:text-primary-300'
            : 'hover:bg-surface-100 dark:hover:bg-surface-700 text-surface-500 dark:text-surface-400'"
          @click="selectUnsortedBoard(board.id)"
          @contextmenu="showContextMenu($event, 'unsorted-board', { board_id: board.id, board_name: board.name })"
        >
          <span class="material-symbols-rounded text-[14px] opacity-60">view_kanban</span>
          <span class="truncate flex-1">{{ board.name }}</span>
          <span
            v-if="isSharedBoard(board)"
            class="text-[9px] px-1.5 py-0.5 rounded-full bg-amber-100 dark:bg-amber-900/30 text-amber-600 dark:text-amber-400 font-medium shrink-0 leading-none"
            :title="'Shared by ' + ownerLabel(board)"
          >{{ ownerLabel(board) }}</span>
        </div>
      </div>

      <!-- Team Presence (collapsible) -->
      <div class="mt-2">
        <div class="mx-3 mb-1 border-t border-surface-200 dark:border-surface-700"></div>
        <button
          class="w-full flex items-center gap-1.5 px-2 py-1.5 text-[11px] font-semibold text-surface-400 dark:text-surface-500 uppercase tracking-wider hover:text-surface-600 dark:hover:text-surface-300 transition-colors"
          @click="showTeamPresence = !showTeamPresence"
        >
          <span class="material-symbols-rounded text-[14px] transition-transform" :class="showTeamPresence ? 'rotate-90' : ''">chevron_right</span>
          Team
        </button>
        <TeamPresencePanel v-if="showTeamPresence" />
      </div>
    </div>

    <!-- Create Space button -->
    <div class="px-3 py-2 border-t border-surface-200 dark:border-surface-700">
      <div v-if="!showCreateSpace">
        <button
          class="w-full flex items-center justify-center gap-1.5 px-3 py-2 rounded-xl text-sm font-medium text-surface-500 dark:text-surface-400 hover:bg-surface-100 dark:hover:bg-surface-700 transition-colors"
          @click="showCreateSpace = true"
        >
          <span class="material-symbols-rounded text-[18px]">add</span>
          Create Space
        </button>
      </div>
      <div v-else class="flex items-center gap-1">
        <input
          v-model="newSpaceName"
          class="flex-1 text-sm px-3 py-2 rounded-xl border border-surface-300 dark:border-surface-600 bg-white dark:bg-surface-700 text-surface-800 dark:text-surface-200 outline-none focus:ring-1 focus:ring-primary-500"
          placeholder="Space name"
          @keydown.enter="handleCreateSpace"
          @keydown.escape="showCreateSpace = false"
          autofocus
        />
        <button class="material-symbols-rounded text-[20px] text-green-500 hover:text-green-600" @click="handleCreateSpace">check</button>
        <button class="material-symbols-rounded text-[20px] text-surface-400 hover:text-surface-600" @click="showCreateSpace = false">close</button>
      </div>
    </div>

    <SidebarContextMenu
      :context-menu="contextMenu"
      @close="closeContextMenu"
      @favorite-space="toggleFavoriteSpace"
      @edit-space="openEditSpace"
      @assign-client="openClientPicker"
      @add-folder="startCreateFolder"
      @view-space-time="viewSpaceTime"
      @delete-space="handleDeleteSpace"
      @edit-folder="(item) => openEditFolder(item, hubStore.activeSpace?.id)"
      @new-board="(folderId) => { startCreateBoard(folderId); closeContextMenu() }"
      @link-board="openLinkBoardDialog"
      @duplicate-folder="handleDuplicateFolder"
      @view-folder-time="viewFolderTime"
      @delete-folder="handleDeleteFolder"
      @rename-board="(item) => startRenameBoard(item, item.folderId)"
      @archive-board="handleArchiveBoard"
      @unlink-board="({ boardId, folderId }) => handleUnlinkBoard(boardId, folderId)"
      @delete-board="handleDeleteBoard"
    />

    <!-- Link Board Dialog -->
    <Teleport to="body">
      <LinkBoardDialog
        v-if="showLinkBoardDialog.active"
        :folder-id="showLinkBoardDialog.folderId"
        :folder-name="showLinkBoardDialog.folderName"
        @close="showLinkBoardDialog = { active: false, folderId: null, folderName: '' }"
        @linked="handleBoardLinked"
      />
    </Teleport>

    <!-- Space Settings Dialog -->
    <Teleport to="body">
      <SpaceSettings
        v-if="showSpaceSettings.active"
        :space="showSpaceSettings.space"
        @close="showSpaceSettings = { active: false, space: null }"
        @saved="handleSettingsSaved"
      />
    </Teleport>

    <!-- Folder Settings Dialog -->
    <Teleport to="body">
      <FolderSettings
        v-if="showFolderSettings.active"
        :folder="showFolderSettings.folder"
        :space-id="showFolderSettings.spaceId"
        @close="showFolderSettings = { active: false, folder: null, spaceId: null }"
        @saved="handleSettingsSaved"
      />
    </Teleport>

    <!-- Client Picker Modal (Space-level) -->
    <Teleport to="body">
      <div v-if="showClientPicker.active" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40" @click.self="showClientPicker = { active: false, spaceId: null, currentClientId: null }">
        <div class="bg-white dark:bg-surface-800 rounded-2xl shadow-xl w-full max-w-sm p-5">
          <h3 class="text-sm font-bold text-surface-800 dark:text-surface-100 mb-3 flex items-center gap-2">
            <span class="material-symbols-rounded text-lg">person</span>
            Assign Client to Space
          </h3>
          <p class="text-[11px] text-surface-400 mb-3">All folders and boards in this space will inherit this client.</p>

          <div v-if="clientPickerLoading" class="flex justify-center py-6">
            <span class="material-symbols-rounded animate-spin text-primary-500">progress_activity</span>
          </div>
          <template v-else>
            <select
              v-model="selectedClientId"
              class="w-full px-3 py-2 rounded-xl border border-surface-300 dark:border-surface-600 bg-white dark:bg-surface-700 text-sm text-surface-800 dark:text-surface-200 outline-none focus:ring-2 focus:ring-primary-500"
            >
              <option :value="null">-- No Client --</option>
              <option v-for="client in clientsStore.clients" :key="client.id" :value="client.id">
                {{ client.display_name || client.domain }}
              </option>
            </select>

            <div class="flex items-center justify-between mt-4">
              <button
                v-if="showClientPicker.currentClientId"
                class="text-xs text-red-500 hover:text-red-600 transition-colors"
                @click="removeClientFromSpace"
              >
                Remove Client
              </button>
              <div v-else></div>
              <div class="flex items-center gap-2">
                <button
                  class="px-3 py-1.5 rounded-full text-xs text-surface-500 hover:bg-surface-100 dark:hover:bg-surface-700 transition-colors"
                  @click="showClientPicker = { active: false, spaceId: null, currentClientId: null }"
                >
                  Cancel
                </button>
                <button
                  class="px-3 py-1.5 rounded-full text-xs font-medium bg-primary-500 text-white hover:bg-primary-600 transition-colors"
                  @click="assignClientToSpace"
                >
                  Save
                </button>
              </div>
            </div>
          </template>
        </div>
      </div>
    </Teleport>
  </aside>
</template>
