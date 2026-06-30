<script setup lang="ts">
import { ref, computed, onMounted, watch, onUnmounted, nextTick } from 'vue'
import { useSyncStore } from '../stores/sync'
import { useConfigStore } from '../stores/config'
import { useFilesStore } from '../stores/files'
import { useWatchFoldersStore } from '../stores/watchFolders'
import SettingsPanel from './SettingsPanel.vue'
import ActivityLog from './ActivityLog.vue'
import WatchFolderManageModal from './WatchFolderManageModal.vue'
import DriveToolbar from './DriveToolbar.vue'
import DriveSidebar from './DriveSidebar.vue'
import FileListView from './FileListView.vue'
import {
  formatSize,
  formatRelativeDate,
  formatDuration,
} from '../utils/format'
import {
  getFileIcon,
  getFileIconBg,
  getFileIconColor,
  getFolderColor,
  getSharingStatus,
} from '../utils/fileVisuals'
import { clampMenuToViewport } from '../utils/menuPosition'

const props = defineProps<{
  currentView: 'files' | 'activity' | 'settings'
}>()

const emit = defineEmits<{
  'update:currentView': [value: 'files' | 'activity' | 'settings']
  'logout': []
}>()

const syncStore = useSyncStore()
const configStore = useConfigStore()
const filesStore = useFilesStore()
const watchFoldersStore = useWatchFoldersStore()

// Build label to confirm latest UI is loaded
const buildLabel = 'Build 2026-06-12 v2.4.0'

// Wave D.7: clicking a watch folder row opens the in-app management modal
// (project/board info, change local folder, remove). The little open_in_new
// icon on the row (in DriveSidebar) still deep-links straight to the cloud board.
const managedWatchFolder = ref<any | null>(null)

const viewMode = ref<'grid' | 'list'>('list')
const searchQuery = ref('')
const currentFolderId = ref<number | null>(null)
const currentFolderName = ref('My Drive')
const isTrash = ref(false)
const breadcrumbs = ref<Array<{ id: number | null, name: string }>>([{ id: null, name: 'My Drive' }])

// ─── Explorer navigation history (back / forward / up, like the web toolbar) ───
const histStack = ref<string[]>([])
const histIndex = ref(-1)
let navigatingViaHistory = false

const currentLocation = computed(() => JSON.stringify({
  trash: isTrash.value,
  folderId: currentFolderId.value,
  name: currentFolderName.value,
}))

watch(currentLocation, (loc) => {
  if (navigatingViaHistory) {
    navigatingViaHistory = false
    return
  }
  if (histStack.value[histIndex.value] === loc) return
  histStack.value.splice(histIndex.value + 1)
  histStack.value.push(loc)
  histIndex.value = histStack.value.length - 1
}, { immediate: true })

const canGoBack = computed(() => histIndex.value > 0)
const canGoForward = computed(() => histIndex.value < histStack.value.length - 1)
const canGoUp = computed(() => !isTrash.value && currentFolderId.value !== null)

function applyLocation(serialized: string) {
  const loc = JSON.parse(serialized)
  navigatingViaHistory = true
  if (loc.trash) {
    openTrash()
  } else {
    navigateToFolder(loc.folderId, loc.folderId === null ? undefined : loc.name)
  }
}

function goBack() {
  if (!canGoBack.value) return
  histIndex.value -= 1
  applyLocation(histStack.value[histIndex.value])
}

function goForward() {
  if (!canGoForward.value) return
  histIndex.value += 1
  applyLocation(histStack.value[histIndex.value])
}

function goUp() {
  if (!canGoUp.value) return
  const folder = filesStore.getFolderById(currentFolderId.value!)
  const parentId = folder?.remoteParentId ?? null
  if (parentId === null) {
    navigateToFolder(null)
  } else {
    const parent = filesStore.getFolderById(parentId)
    navigateToFolder(parentId, parent?.name)
  }
}

// Drag and drop
const isDragging = ref(false)
const dragCounter = ref(0)

const contextMenu = ref({ show: false, x: 0, y: 0, item: null as any, type: '' as 'file' | 'folder' | '' })
const contextMenuRef = ref<HTMLElement | null>(null)
const propertiesPanel = ref({ show: false, item: null as any, type: '' as 'file' | 'folder' | '' })
const expandedFolders = ref<Set<number>>(new Set())

// Editing status - who is editing which files
interface EditingStatus {
  filename: string
  folder_id: number | null
  folder_name?: string
  editor_email: string
  started_at: string
  editing_duration: number
}
const otherEditors = ref<EditingStatus[]>([])
const selfEditing = ref<Array<{ filename: string; folderId: number | null }>>([])

// Website tracking
const activeWebsite = ref<{ domain: string; clientName: string; boardName: string; duration: number } | null>(null)

// Watch folder active sessions
const watchFolderSessions = ref<Array<{ filename: string; processName: string; duration: number; watchFolderId: number; clientName: string; boardName: string | null }>>([])

// Rename dialog
const renameDialog = ref({ show: false, item: null as any, type: '' as 'file' | 'folder', newName: '' })

let editingStatusCleanup: (() => void) | null = null
let selfEditingCleanup: (() => void) | null = null
let appReadyCleanup: (() => void) | null = null
let websitePollingInterval: NodeJS.Timeout | null = null
let watchFolderPollingInterval: NodeJS.Timeout | null = null
let trackingTickInterval: NodeJS.Timeout | null = null

// Wave C.1: visibility-gate the remaining polls so a hidden window stops
// IPC chatter entirely. Active polls run on a 10 s cadence (was 2 s) and
// jump to a fast 2 s tick only while the user is interacting.
const VISIBLE_POLL_MS = 10_000

// Wave D.6: the IPC polls re-sync ground truth every 10 s; this local 1 s
// tick advances the visible counters in between so tracked time counts
// continuously instead of jumping 10 s at a time. Pure in-memory increment,
// no IPC — effectively free.
function tickTrackingDurations() {
  if (activeWebsite.value) activeWebsite.value.duration += 1
  for (const session of watchFolderSessions.value) session.duration += 1
}

function startMainViewPolls() {
  if (!websitePollingInterval) {
    fetchActiveWebsites()
    websitePollingInterval = setInterval(fetchActiveWebsites, VISIBLE_POLL_MS)
  }
  if (!watchFolderPollingInterval) {
    fetchWatchFolderSessions()
    watchFolderPollingInterval = setInterval(fetchWatchFolderSessions, VISIBLE_POLL_MS)
  }
  if (!trackingTickInterval) {
    trackingTickInterval = setInterval(tickTrackingDurations, 1_000)
  }
}
function stopMainViewPolls() {
  if (websitePollingInterval) {
    clearInterval(websitePollingInterval)
    websitePollingInterval = null
  }
  if (watchFolderPollingInterval) {
    clearInterval(watchFolderPollingInterval)
    watchFolderPollingInterval = null
  }
  if (trackingTickInterval) {
    clearInterval(trackingTickInterval)
    trackingTickInterval = null
  }
}
function onMainViewVisibilityChange() {
  if (document.visibilityState === 'visible') startMainViewPolls()
  else stopMainViewPolls()
}

onMounted(async () => {
  console.log('[MAINVIEW] Mounted!')
  
  // Load files and folder tree in parallel (not sequentially)
  filesStore.loadFiles()
  filesStore.loadAllFolders()
  // loadFiles already returns quota, no separate fetchQuota() needed

  // Load watch folders from cache immediately (fast, no network)
  watchFoldersStore.loadWatchFolders()

  document.addEventListener('click', closeContextMenu)
  
  // Listen for app-ready signal (when sync engine + services are initialized)
  appReadyCleanup = window.api.onAppReady(() => {
    console.log('[MAINVIEW] Received app-ready signal, reloading data...')
    filesStore.loadFiles()
    filesStore.loadAllFolders()
    // Now the watch folder service is ready, do a real network refresh
    watchFoldersStore.refresh()
  })
  
  // Subscribe to other editors updates
  editingStatusCleanup = window.api.onEditingUpdate((editors) => {
    otherEditors.value = editors
  })
  
  // Subscribe to self-editing updates (real-time from main process)
  selfEditingCleanup = window.api.onSelfEditingUpdate((editing) => {
    console.log('[SELF-EDITING] Real-time update:', editing)
    selfEditing.value = editing
  })
  
  // Initial fetch of self-editing status
  try {
    const editing = await window.api.getSelfEditing()
    console.log('[SELF-EDITING] Initial fetch:', editing)
    selfEditing.value = editing
  } catch (e: any) {
    console.error('[SELF-EDITING] Fetch error:', e)
  }
  
  // Initial fetch of editing status
  fetchEditingStatus()
  
  // Wave C.1: visibility-gated polling at 10 s cadence (was 2 s).
  startMainViewPolls()
  document.addEventListener('visibilitychange', onMainViewVisibilityChange)
})

onUnmounted(() => {
  document.removeEventListener('click', closeContextMenu)
  document.removeEventListener('visibilitychange', onMainViewVisibilityChange)
  if (editingStatusCleanup) editingStatusCleanup()
  if (selfEditingCleanup) selfEditingCleanup()
  if (appReadyCleanup) appReadyCleanup()
  stopMainViewPolls()
})

async function fetchEditingStatus() {
  try {
    const editors = await window.api.getOtherEditors()
    otherEditors.value = editors
  } catch (e) {
    console.error('Failed to fetch editing status:', e)
  }
}

async function fetchSelfEditing() {
  try {
    const editing = await window.api.getSelfEditing()
    console.log('[SELF-EDITING] Fetched:', editing)
    selfEditing.value = editing
  } catch (e) {
    console.error('Failed to fetch self-editing status:', e)
  }
}

// Wave D.6: re-sync helper — keep the locally ticked value when the polled
// ground truth is only off by timing jitter (<= 3 s), so the visible counter
// never stutters backwards. Large differences (session reset) are accepted.
function smoothDuration(polled: number, current: number | undefined): number {
  if (current === undefined) return polled
  if (Math.abs(polled - current) <= 3) return Math.max(polled, current)
  return polled
}

async function fetchActiveWebsites() {
  try {
    const websites = await window.api.getActiveTrackedWebsites()
    const next = websites.length > 0 ? websites[0] : null
    if (next && activeWebsite.value && next.domain === activeWebsite.value.domain) {
      next.duration = smoothDuration(next.duration, activeWebsite.value.duration)
    }
    activeWebsite.value = next
  } catch (e) {
    console.error('Failed to fetch active websites:', e)
  }
}

async function fetchWatchFolderSessions() {
  try {
    const sessions = await window.api.getEditingSessions()
    const prevDurations = new Map(
      watchFolderSessions.value.map(s => [`${s.watchFolderId}:${s.filename}`, s.duration])
    )
    watchFolderSessions.value = sessions
      .filter((s: any) => s.watchFolder)
      .map((s: any) => {
        const watchFolderId = s.watchFolder.watchFolderId
        return {
          filename: s.filename,
          processName: s.processName,
          duration: smoothDuration(s.duration, prevDurations.get(`${watchFolderId}:${s.filename}`)),
          watchFolderId,
          clientName: s.watchFolder.clientName || '',
          boardName: s.watchFolder.boardName || null,
        }
      })
  } catch {
    // Silently ignore
  }
}

// Check if a file is being edited by someone else
function getFileEditor(filename: string, folderId: number | null): EditingStatus | null {
  return otherEditors.value.find(e => 
    e.filename === filename && e.folder_id === folderId
  ) || null
}

// Check if YOU are editing this file (match by filename - simple approach)
function isSelfEditing(filename: string, _folderId: number | null): boolean {
  // Match by filename only - works for most cases
  // (users rarely edit files with same name in different folders simultaneously)
  const isEditing = selfEditing.value.some(e => e.filename === filename)
  if (isEditing) {
    console.log(`[SELF-EDITING] Detected: ${filename}`)
  }
  return isEditing
}

// Format editor email to show just the name part
function formatEditorName(email: string): string {
  const name = email.split('@')[0]
  return name.charAt(0).toUpperCase() + name.slice(1)
}

watch(currentFolderId, () => {
  if (!isTrash.value) {
    filesStore.loadFiles(currentFolderId.value ?? undefined)
  }
})

const filteredItems = computed(() => {
  const query = searchQuery.value.toLowerCase()
  if (!query) return { folders: filesStore.folders, files: filesStore.files }
  return {
    folders: filesStore.folders.filter(f => f.name.toLowerCase().includes(query)),
    files: filesStore.files.filter(f => f.filename.toLowerCase().includes(query)),
  }
})

function toggleFolderExpand(folderId: number, event?: MouseEvent) {
  if (event) {
    event.stopPropagation()
  }
  if (expandedFolders.value.has(folderId)) {
    expandedFolders.value.delete(folderId)
  } else {
    expandedFolders.value.add(folderId)
  }
  // Trigger reactivity
  expandedFolders.value = new Set(expandedFolders.value)
}

// Expand folder path when navigating (like Windows Explorer).
// Uses the O(1) folderById lookup instead of Array.find.
function expandToFolder(folderId: number) {
  const folder = filesStore.getFolderById(folderId)
  if (folder && folder.remoteParentId) {
    expandedFolders.value.add(folder.remoteParentId)
    expandToFolder(folder.remoteParentId)
  }
  expandedFolders.value = new Set(expandedFolders.value)
}

function navigateToFolder(folderId: number | null, folderName?: string) {
  const wasTrash = isTrash.value
  isTrash.value = false
  // Leaving trash without changing folder id: the watcher won't fire, reload explicitly
  if (wasTrash && currentFolderId.value === folderId) {
    filesStore.loadFiles(folderId ?? undefined)
  }
  currentFolderId.value = folderId
  currentFolderName.value = folderName || 'My Drive'
  if (folderId === null) {
    breadcrumbs.value = [{ id: null, name: 'My Drive' }]
  } else if (folderName) {
    const idx = breadcrumbs.value.findIndex(b => b.id === folderId)
    if (idx >= 0) breadcrumbs.value = breadcrumbs.value.slice(0, idx + 1)
    else breadcrumbs.value.push({ id: folderId, name: folderName })
    // Expand parent folders in sidebar tree
    expandToFolder(folderId)
  }
}

function openTrash() {
  isTrash.value = true
  currentFolderId.value = null
  currentFolderName.value = 'Trash'
  breadcrumbs.value = [{ id: null, name: 'Trash' }]
  filesStore.loadTrash()
}

function setView(view: 'files' | 'activity' | 'settings') { emit('update:currentView', view) }
const showLogoutDialog = ref(false)

function handleLogoutClick() {
  showLogoutDialog.value = true
}

function handleLogoutThisApp() {
  showLogoutDialog.value = false
  emit('logout')
}

async function handleLogoutGlobal() {
  showLogoutDialog.value = false
  if ((window as any).api?.sso?.logout) {
    await (window as any).api.sso.logout()
  } else {
    emit('logout')
  }
}

function openLocalFolder() { window.api.openSyncFolder() }

function showContextMenu(event: MouseEvent, item: any, type: 'file' | 'folder') {
  event.preventDefault()
  event.stopPropagation()
  contextMenu.value = { show: true, x: event.clientX, y: event.clientY, item, type }
  // Reposition after render so the menu never overflows the viewport
  nextTick(() => {
    const { x, y } = clampMenuToViewport(event.clientX, event.clientY, contextMenuRef.value)
    contextMenu.value.x = x
    contextMenu.value.y = y
  })
}

function closeContextMenu() { contextMenu.value.show = false }

function openProperties() {
  propertiesPanel.value = { show: true, item: contextMenu.value.item, type: contextMenu.value.type }
  closeContextMenu()
}

function closeProperties() { propertiesPanel.value.show = false }

// Context menu actions
async function handleShare() {
  const item = contextMenu.value.item
  const type = contextMenu.value.type
  // TODO: Open share dialog
  console.log('Share:', type, item)
  closeContextMenu()
}

async function handleCopyLink() {
  const item = contextMenu.value.item
  const type = contextMenu.value.type
  const apiUrl = configStore.config.apiUrl
  
  // Generate shareable link
  let link = ''
  if (type === 'folder') {
    link = `${apiUrl}/drive/folder/${item.remoteId}`
  } else {
    // Use public token if available
    if (item.public_token || item.publicToken) {
      link = `${apiUrl}/drive/share/${item.public_token || item.publicToken}`
    } else {
      link = `${apiUrl}/drive/file/${item.remoteId}`
    }
  }
  
  try {
    await navigator.clipboard.writeText(link)
    console.log('Link copied:', link)
  } catch (e) {
    console.error('Failed to copy link:', e)
  }
  closeContextMenu()
}

function downloadFile(item: any) {
  const apiUrl = configStore.config.apiUrl
  const downloadUrl = `${apiUrl}/api/drive/files/${item.remoteId}/download`
  window.open(downloadUrl, '_blank')
}

async function handleDownload() {
  if (contextMenu.value.type === 'file') {
    downloadFile(contextMenu.value.item)
  }
  closeContextMenu()
}

function handleRename() {
  const item = contextMenu.value.item
  const type = contextMenu.value.type
  if (type !== 'file' && type !== 'folder') return
  renameDialog.value = {
    show: true,
    item,
    type,
    newName: type === 'folder' ? item.name : item.filename
  }
  closeContextMenu()
}

async function submitRename() {
  // TODO: Call API to rename
  console.log('Rename to:', renameDialog.value.newName)
  renameDialog.value.show = false
}

async function handleDelete() {
  const item = contextMenu.value.item
  const type = contextMenu.value.type
  // TODO: Call API to delete
  console.log('Delete:', type, item)
  closeContextMenu()
}

function triggerSync() { window.api.triggerSync() }

const syncingTrackings = ref(false)

async function refreshTrackings() {
  if (syncingTrackings.value) return
  syncingTrackings.value = true
  try {
    // Refresh both URL mappings (tracked websites) and folder mappings
    await window.api.refreshUrlMappings()
    await window.api.refreshFolderMapping()
    console.log('[MainView] Trackings refreshed')
  } finally {
    syncingTrackings.value = false
  }
}

// Create new folder
function createNewFolder() {
  // TODO: Show create folder dialog
  console.log('Create new folder in:', currentFolderId.value)
}

// Trigger file upload
function triggerFileUpload() {
  // TODO: Open file picker and upload
  console.log('Upload files to:', currentFolderId.value)
}

// Drag and drop handlers
function handleDragEnter(e: DragEvent) {
  e.preventDefault()
  e.stopPropagation()
  dragCounter.value++
  if (e.dataTransfer?.types.includes('Files')) {
    isDragging.value = true
  }
}

function handleDragLeave(e: DragEvent) {
  e.preventDefault()
  e.stopPropagation()
  dragCounter.value--
  if (dragCounter.value === 0) {
    isDragging.value = false
  }
}

function handleDragOver(e: DragEvent) {
  e.preventDefault()
  e.stopPropagation()
}

function handleDrop(e: DragEvent) {
  e.preventDefault()
  e.stopPropagation()
  isDragging.value = false
  dragCounter.value = 0
  
  if (isTrash.value) return
  
  const files = e.dataTransfer?.files
  if (files && files.length > 0) {
    // TODO: Upload files
    console.log('Dropped files:', Array.from(files).map(f => f.name))
  }
}
</script>

<template>
  <div class="flex-1 flex flex-col overflow-hidden" style="font-size: 13px; background: var(--bg-main);">
    <!-- File-manager toolbar: full width, ABOVE the sidebar (web Drive parity) -->
    <DriveToolbar
      v-if="currentView === 'files'"
      :breadcrumbs="breadcrumbs"
      :is-trash="isTrash"
      v-model:search-query="searchQuery"
      v-model:view-mode="viewMode"
      :can-go-back="canGoBack"
      :can-go-forward="canGoForward"
      :can-go-up="canGoUp"
      :syncing="syncStore.status.status === 'syncing'"
      :syncing-trackings="syncingTrackings"
      @back="goBack"
      @forward="goForward"
      @up="goUp"
      @navigate="(id, name) => navigateToFolder(id, name)"
      @open-local-folder="openLocalFolder"
      @sync-now="triggerSync"
      @sync-trackings="refreshTrackings"
    />

    <div class="flex-1 flex overflow-hidden">
    <!-- Sidebar - resizable, with Favorites / Folders / Watch Folders / storage card -->
    <DriveSidebar
      :current-folder-id="currentFolderId"
      :is-trash="isTrash"
      :current-view="currentView"
      :expanded-folders="expandedFolders"
      :watch-folder-sessions="watchFolderSessions"
      :build-label="buildLabel"
      @navigate="(id, name) => { navigateToFolder(id, name); setView('files') }"
      @open-trash="openTrash(); setView('files')"
      @toggle-expand="toggleFolderExpand"
      @logout="handleLogoutClick"
      @manage-watch-folder="(wf) => managedWatchFolder = wf"
    />
    
    <!-- Main -->
    <div style="flex: 1; display: flex; flex-direction: column; overflow: hidden; background: var(--bg-main);">
      <template v-if="currentView === 'files'">
        <!-- File List (navigation + search now live in the DriveToolbar above) -->
        <div 
          style="flex: 1; overflow-y: auto; overflow-x: hidden; position: relative;"
          @dragenter="handleDragEnter"
          @dragleave="handleDragLeave"
          @dragover="handleDragOver"
          @drop="handleDrop"
        >
          <div v-if="filesStore.isLoading" style="display: flex; align-items: center; justify-content: center; height: 150px;">
            <span class="material-symbols-rounded animate-spin" style="font-size: 32px; color: #22c55e;">sync</span>
          </div>
          
          <div v-else-if="filteredItems.folders.length === 0 && filteredItems.files.length === 0" style="display: flex; flex-direction: column; align-items: center; justify-content: center; height: 100%; text-align: center; padding: 60px 20px;">
            <!-- Empty cloud icon -->
            <div style="margin-bottom: 20px;">
              <span class="material-symbols-rounded" style="font-size: 64px; color: #3a3a42;">{{ isTrash ? 'delete_forever' : 'cloud_off' }}</span>
            </div>
            <p style="color: var(--text-muted); font-size: 16px; margin-bottom: 24px;">{{ isTrash ? 'Trash is empty' : 'This folder is empty' }}</p>
            
            <!-- Action buttons for non-trash -->
            <div v-if="!isTrash" style="display: flex; align-items: center; gap: 12px;">
              <button @click="createNewFolder" style="display: flex; align-items: center; gap: 8px; padding: 10px 20px; border-radius: 9999px; background: var(--bg-elevated); border: 1px solid #3a3a42; color: var(--text-primary); font-size: 14px; cursor: pointer;" class="hover:bg-[--bg-elevated-hover] transition-colors">
                <span class="material-symbols-rounded" style="font-size: 18px;">create_new_folder</span>
                New Folder
              </button>
              <button @click="triggerFileUpload" style="display: flex; align-items: center; gap: 8px; padding: 10px 20px; border-radius: 9999px; background: #22c55e; border: none; color: white; font-size: 14px; cursor: pointer;" class="hover:bg-[#15803d] transition-colors">
                <span class="material-symbols-rounded" style="font-size: 18px;">upload</span>
                Upload Files
              </button>
            </div>
            <p v-else style="color: var(--text-dim); font-size: 13px;">Deleted items will appear here</p>
          </div>
          
          <!-- Drag and drop overlay -->
          <div 
            v-if="isDragging && !isTrash" 
            style="position: absolute; inset: 0; background: rgba(22, 163, 74, 0.1); border: 2px dashed #22c55e; border-radius: 12px; display: flex; flex-direction: column; align-items: center; justify-content: center; z-index: 20; pointer-events: none;"
          >
            <span class="material-symbols-rounded" style="font-size: 56px; color: #22c55e; margin-bottom: 12px;">cloud_upload</span>
            <p style="font-size: 18px; font-weight: 500; color: #22c55e;">Drop files here to upload</p>
            <p style="font-size: 13px; color: var(--text-dim); margin-top: 4px;">Files will be uploaded to the current folder</p>
          </div>
          
          <!-- List View: Name / Modified / Type / Size (web Drive parity) -->
          <FileListView
            v-else-if="viewMode === 'list'"
            :folders="filteredItems.folders"
            :files="filteredItems.files"
            :get-file-editor="getFileEditor"
            :is-self-editing="isSelfEditing"
            @navigate="navigateToFolder"
            @context-menu="showContextMenu"
            @download="downloadFile"
          />
          
          <!-- Grid View -->
          <div v-else style="padding: 16px; display: grid; grid-template-columns: repeat(auto-fill, minmax(140px, 1fr)); gap: 12px;">
            <div 
              v-for="folder in filteredItems.folders" 
              :key="'folder-' + folder.remoteId" 
              @click="navigateToFolder(folder.remoteId, folder.name)" 
              @contextmenu="showContextMenu($event, folder, 'folder')" 
              style="aspect-ratio: 1; padding: 16px; border-radius: 10px; background: var(--bg-card); border: 1px solid var(--border); cursor: pointer; display: flex; flex-direction: column; align-items: center; justify-content: center;" 
              class="hover:bg-[--bg-hover] hover:border-[#3a3a42]"
            >
              <span class="material-symbols-rounded" :style="'font-size: 48px; color: ' + getFolderColor(folder)">folder</span>
              <p style="margin-top: 10px; font-size: 12px; color: var(--text-primary); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; width: 100%; text-align: center;">{{ folder.name }}</p>
            </div>
            <div 
              v-for="file in filteredItems.files" 
              :key="'file-' + file.remoteId" 
              @contextmenu="showContextMenu($event, file, 'file')" 
              :style="getFileEditor(file.filename, file.remoteFolderId) 
                ? 'aspect-ratio: 1; padding: 16px; border-radius: 10px; background: rgba(239, 68, 68, 0.15); border: 2px solid #ef4444; display: flex; flex-direction: column; align-items: center; justify-content: center; position: relative;' 
                : isSelfEditing(file.filename, file.remoteFolderId)
                  ? 'aspect-ratio: 1; padding: 16px; border-radius: 10px; background: rgba(34, 197, 94, 0.15); border: 2px solid #22c55e; display: flex; flex-direction: column; align-items: center; justify-content: center; position: relative;'
                  : 'aspect-ratio: 1; padding: 16px; border-radius: 10px; background: var(--bg-card); border: 1px solid var(--border); display: flex; flex-direction: column; align-items: center; justify-content: center;'"
              :class="getFileEditor(file.filename, file.remoteFolderId) ? 'editing-pulse' : isSelfEditing(file.filename, file.remoteFolderId) ? 'self-editing-pulse' : 'hover:bg-[--bg-hover] hover:border-[#3a3a42]'"
            >
              <div 
                v-if="getFileEditor(file.filename, file.remoteFolderId)" 
                class="editing-dot-pulse"
                style="position: absolute; top: 8px; right: 8px; width: 14px; height: 14px; background: #ef4444; border-radius: 50%;"
              ></div>
              <div 
                v-else-if="isSelfEditing(file.filename, file.remoteFolderId)" 
                class="self-editing-dot-pulse"
                style="position: absolute; top: 8px; right: 8px; width: 14px; height: 14px; background: #22c55e; border-radius: 50%;"
              ></div>
              <span class="material-symbols-rounded" :style="getFileEditor(file.filename, file.remoteFolderId) ? 'color: #ef4444; font-size: 48px;' : isSelfEditing(file.filename, file.remoteFolderId) ? 'color: #22c55e; font-size: 48px;' : getFileIconColor(file.mimeType) + '; font-size: 48px;'">
                {{ getFileEditor(file.filename, file.remoteFolderId) || isSelfEditing(file.filename, file.remoteFolderId) ? 'edit_document' : getFileIcon(file.mimeType) }}
              </span>
              <p :style="getFileEditor(file.filename, file.remoteFolderId) ? 'color: #ef4444; font-weight: 500;' : isSelfEditing(file.filename, file.remoteFolderId) ? 'color: #22c55e; font-weight: 500;' : 'color: var(--text-primary);'" style="margin-top: 10px; font-size: 12px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; width: 100%; text-align: center;">{{ file.filename }}</p>
              <p v-if="getFileEditor(file.filename, file.remoteFolderId)" style="font-size: 10px; color: #ef4444; font-weight: 500; margin-top: 2px; display: flex; align-items: center; gap: 3px;">
                <span class="material-symbols-rounded editing-icon-pulse" style="font-size: 12px;">person</span>
                {{ formatEditorName(getFileEditor(file.filename, file.remoteFolderId)!.editor_email) }} editing
              </p>
              <p v-else-if="isSelfEditing(file.filename, file.remoteFolderId)" style="font-size: 10px; color: #22c55e; font-weight: 500; margin-top: 2px; display: flex; align-items: center; gap: 3px;">
                <span class="material-symbols-rounded self-editing-icon-pulse" style="font-size: 12px;">edit</span>
                You editing
              </p>
              <p v-else style="font-size: 11px; color: var(--text-dim);">{{ formatSize(file.size) }}</p>
            </div>
          </div>
        </div>
        
        <!-- Status bar -->
        <div style="height: 40px; border-top: 1px solid var(--border); display: flex; align-items: center; justify-content: space-between; padding: 0 20px; background: var(--bg-main); font-size: 12px;">
          <div style="display: flex; align-items: center; gap: 6px;">
            <span class="material-symbols-rounded" :class="syncStore.status.status === 'syncing' ? 'animate-spin' : ''" style="font-size: 16px; color: #22c55e;">{{ syncStore.status.status === 'syncing' ? 'sync' : 'cloud_done' }}</span>
            <span style="color: var(--text-dim);">{{ syncStore.status.message }}</span>
          </div>
          
          <div style="display: flex; align-items: center; gap: 16px;">
            <!-- Website Tracking Indicator -->
            <div v-if="activeWebsite" style="display: flex; align-items: center; gap: 6px; padding: 6px 12px; background: rgba(34, 197, 94, 0.15); border-radius: 8px;">
              <span class="material-symbols-rounded" style="font-size: 16px; color: #22c55e;">language</span>
              <span style="color: #22c55e; font-weight: 500;">{{ activeWebsite.domain }}</span>
              <span style="color: var(--text-dim);">|</span>
              <span style="color: var(--text-muted);">{{ formatDuration(activeWebsite.duration) }}</span>
            </div>
            
            <button @click="setView('settings')" style="color: var(--text-muted); display: flex; align-items: center;" class="hover:text-white" title="Settings">
              <span class="material-symbols-rounded" style="font-size: 20px;">settings</span>
            </button>
          </div>
        </div>
      </template>
      
      <template v-else-if="currentView === 'activity'"><ActivityLog /></template>
      <template v-else-if="currentView === 'settings'"><SettingsPanel /></template>
    </div>
    </div>
    
    <!-- Context Menu -->
    <Teleport to="body">
      <div v-if="contextMenu.show" ref="contextMenuRef" :style="{ left: contextMenu.x + 'px', top: contextMenu.y + 'px' }" style="position: fixed; z-index: 50; min-width: 180px; padding: 6px 0; background: var(--bg-card); border: 1px solid var(--border); border-radius: 10px; box-shadow: 0 20px 40px rgba(0,0,0,0.5);">
        <button @click="openProperties" style="width: 100%; display: flex; align-items: center; gap: 10px; padding: 10px 14px; text-align: left; color: var(--text-secondary); font-size: 13px;" class="hover:bg-[--bg-elevated]">
          <span class="material-symbols-rounded" style="font-size: 18px;">info</span>Properties
        </button>
        <div style="height: 1px; background: var(--bg-elevated); margin: 4px 0;"></div>
        <button @click="handleShare" style="width: 100%; display: flex; align-items: center; gap: 10px; padding: 10px 14px; text-align: left; color: var(--text-secondary); font-size: 13px;" class="hover:bg-[--bg-elevated]">
          <span class="material-symbols-rounded" style="font-size: 18px;">share</span>Share
        </button>
        <button @click="handleCopyLink" style="width: 100%; display: flex; align-items: center; gap: 10px; padding: 10px 14px; text-align: left; color: var(--text-secondary); font-size: 13px;" class="hover:bg-[--bg-elevated]">
          <span class="material-symbols-rounded" style="font-size: 18px;">link</span>Copy Link
        </button>
        <div style="height: 1px; background: var(--bg-elevated); margin: 4px 0;"></div>
        <button @click="handleDownload" style="width: 100%; display: flex; align-items: center; gap: 10px; padding: 10px 14px; text-align: left; color: var(--text-secondary); font-size: 13px;" class="hover:bg-[--bg-elevated]">
          <span class="material-symbols-rounded" style="font-size: 18px;">download</span>Download
        </button>
        <button @click="handleRename" style="width: 100%; display: flex; align-items: center; gap: 10px; padding: 10px 14px; text-align: left; color: var(--text-secondary); font-size: 13px;" class="hover:bg-[--bg-elevated]">
          <span class="material-symbols-rounded" style="font-size: 18px;">edit</span>Rename
        </button>
        <div style="height: 1px; background: var(--bg-elevated); margin: 4px 0;"></div>
        <button @click="handleDelete" style="width: 100%; display: flex; align-items: center; gap: 10px; padding: 10px 14px; text-align: left; color: #ef4444; font-size: 13px;" class="hover:bg-[#3f1d1d]">
          <span class="material-symbols-rounded" style="font-size: 18px;">delete</span>Delete
        </button>
      </div>
    </Teleport>
    
    <!-- Rename Dialog -->
    <Teleport to="body">
      <div v-if="renameDialog.show" style="position: fixed; inset: 0; z-index: 50; display: flex; align-items: center; justify-content: center; background: rgba(0,0,0,0.6);" @click.self="renameDialog.show = false">
        <div style="width: 400px; background: var(--bg-card); border: 1px solid var(--border); border-radius: 12px; box-shadow: 0 25px 50px rgba(0,0,0,0.5); overflow: hidden;">
          <div style="padding: 16px 18px; border-bottom: 1px solid var(--border);">
            <h3 style="color: var(--text-primary); font-weight: 600; font-size: 15px;">Rename</h3>
          </div>
          <div style="padding: 18px;">
            <input v-model="renameDialog.newName" type="text" style="width: 100%; padding: 10px 12px; border-radius: 6px; background: var(--bg-main); border: 1px solid var(--border); color: var(--text-primary); font-size: 14px;" class="focus:border-[#22c55e] focus:outline-none" @keyup.enter="submitRename" />
          </div>
          <div style="display: flex; justify-content: flex-end; gap: 10px; padding: 14px 18px; border-top: 1px solid var(--border); background: var(--bg-main);">
            <button @click="renameDialog.show = false" style="padding: 8px 18px; border-radius: 9999px; background: var(--bg-elevated); color: var(--text-primary); font-size: 13px;" class="hover:bg-[--bg-elevated-hover]">Cancel</button>
            <button @click="submitRename" style="padding: 8px 18px; border-radius: 9999px; background: #22c55e; color: white; font-size: 13px;" class="hover:bg-[#15803d]">Rename</button>
          </div>
        </div>
      </div>
    </Teleport>
    
    <!-- Properties Panel -->
    <Teleport to="body">
      <div v-if="propertiesPanel.show" style="position: fixed; inset: 0; z-index: 50; display: flex; align-items: center; justify-content: center; background: rgba(0,0,0,0.6);" @click.self="closeProperties">
        <div style="width: 400px; background: var(--bg-card); border: 1px solid var(--border); border-radius: 14px; box-shadow: 0 25px 50px rgba(0,0,0,0.5); overflow: hidden;">
          <div style="display: flex; align-items: center; justify-content: space-between; padding: 14px 18px; border-bottom: 1px solid var(--border);">
            <h3 style="color: var(--text-primary); font-weight: 600; font-size: 15px;">Properties</h3>
            <button @click="closeProperties" style="padding: 4px; border-radius: 5px; color: var(--text-muted);" class="hover:bg-[--bg-elevated] hover:text-white">
              <span class="material-symbols-rounded" style="font-size: 20px;">close</span>
            </button>
          </div>
          <div style="padding: 18px;">
            <div style="display: flex; align-items: center; gap: 14px; margin-bottom: 18px;">
              <div v-if="propertiesPanel.type === 'folder'" style="width: 48px; height: 48px; border-radius: 10px; background: rgba(34,197,94,0.15); display: flex; align-items: center; justify-content: center;">
                <span class="material-symbols-rounded" :style="'font-size: 28px; color: ' + getFolderColor(propertiesPanel.item)">folder</span>
              </div>
              <div v-else :style="getFileIconBg(propertiesPanel.item?.mimeType) + '; width: 48px; height: 48px; border-radius: 10px; display: flex; align-items: center; justify-content: center;'">
                <span class="material-symbols-rounded" :style="getFileIconColor(propertiesPanel.item?.mimeType) + '; font-size: 28px;'">{{ getFileIcon(propertiesPanel.item?.mimeType) }}</span>
              </div>
              <div>
                <p style="color: var(--text-primary); font-weight: 500; font-size: 14px;">{{ propertiesPanel.type === 'folder' ? propertiesPanel.item?.name : propertiesPanel.item?.filename }}</p>
                <p style="color: var(--text-dim); font-size: 12px;">{{ propertiesPanel.type === 'folder' ? 'Folder' : propertiesPanel.item?.mimeType }}</p>
              </div>
            </div>
            <div style="display: flex; flex-direction: column; gap: 10px; font-size: 13px;">
              <div style="display: flex; justify-content: space-between;"><span style="color: var(--text-muted);">Type</span><span style="color: var(--text-primary);">{{ propertiesPanel.type === 'folder' ? 'Folder' : propertiesPanel.item?.mimeType?.split('/')[0] || 'File' }}</span></div>
              <div v-if="propertiesPanel.type === 'file'" style="display: flex; justify-content: space-between;"><span style="color: var(--text-muted);">Size</span><span style="color: var(--text-primary);">{{ formatSize(propertiesPanel.item?.size || 0) }}</span></div>
              <div style="display: flex; justify-content: space-between;"><span style="color: var(--text-muted);">Modified</span><span style="color: var(--text-primary);">{{ formatRelativeDate(propertiesPanel.item?.lastSyncAt || propertiesPanel.item?.remoteUpdatedAt || '') }}</span></div>
            </div>
            <div style="margin-top: 18px; padding-top: 18px; border-top: 1px solid var(--border);">
              <h4 style="color: var(--text-primary); font-weight: 500; margin-bottom: 12px; display: flex; align-items: center; gap: 6px; font-size: 14px;"><span class="material-symbols-rounded" style="font-size: 18px; color: #22c55e;">share</span>Sharing</h4>
              <div style="display: flex; flex-direction: column; gap: 10px; font-size: 13px;">
                <div style="display: flex; justify-content: space-between; align-items: center;">
                  <span style="color: var(--text-muted);">Status</span>
                  <span v-if="getSharingStatus(propertiesPanel.item).hasLink" style="display: inline-flex; align-items: center; gap: 3px; padding: 4px 10px; border-radius: 9999px; font-size: 11px; border: 1px solid #22c55e; color: #22c55e;">
                    <span class="material-symbols-rounded" style="font-size: 12px;">link</span> Public link
                  </span>
                  <span v-else-if="getSharingStatus(propertiesPanel.item).isPublic" style="padding: 4px 10px; border-radius: 9999px; font-size: 11px; background: rgba(22,163,74,0.2); color: #22c55e;">Public</span>
                  <span v-else style="padding: 4px 10px; border-radius: 5px; font-size: 11px; background: var(--bg-elevated); color: #d1d5db;">Private</span>
                </div>
              </div>
              <div style="margin-top: 12px;">
                <p style="color: var(--text-muted); font-size: 12px; margin-bottom: 8px;">People with access</p>
                <div style="display: flex; align-items: center; gap: 10px; padding: 10px; border-radius: 8px; background: var(--bg-main); border: 1px solid var(--border);">
                  <div style="width: 32px; height: 32px; border-radius: 50%; background: #22c55e; display: flex; align-items: center; justify-content: center; color: white; font-weight: 600; font-size: 13px;">{{ (configStore.config.userEmail || 'U')[0].toUpperCase() }}</div>
                  <div style="flex: 1;"><p style="color: var(--text-primary); font-size: 13px;">{{ configStore.config.userEmail }}</p><p style="color: var(--text-dim); font-size: 11px;">Owner</p></div>
                </div>
              </div>
            </div>
          </div>
          <div style="display: flex; justify-content: flex-end; padding: 14px 18px; border-top: 1px solid var(--border); background: var(--bg-main);">
            <button @click="closeProperties" style="padding: 10px 22px; border-radius: 9999px; background: #22c55e; color: white; font-weight: 500; font-size: 13px;" class="hover:bg-[#15803d]">Close</button>
          </div>
        </div>
      </div>
    </Teleport>

    <!-- Logout confirmation dialog -->
    <Teleport to="body">
      <div v-if="showLogoutDialog" class="fixed inset-0 z-[9999] flex items-center justify-center" style="background: rgba(0,0,0,0.5); backdrop-filter: blur(4px);" @click.self="showLogoutDialog = false">
        <div style="background: var(--bg-card); border: 1px solid var(--border); border-radius: 16px; width: 100%; max-width: 380px; padding: 24px; margin: 16px;">
          <div style="text-align: center; margin-bottom: 20px;">
            <div style="width: 48px; height: 48px; margin: 0 auto 12px; border-radius: 12px; background: rgba(239,68,68,0.1); display: flex; align-items: center; justify-content: center;">
              <span class="material-symbols-rounded" style="font-size: 24px; color: #ef4444;">logout</span>
            </div>
            <h3 style="font-size: 18px; font-weight: 600; color: var(--text-primary);">Sign Out</h3>
            <p style="font-size: 14px; color: var(--text-muted); margin-top: 4px;">How would you like to sign out?</p>
          </div>
          <div style="display: flex; flex-direction: column; gap: 8px;">
            <button @click="handleLogoutThisApp" style="width: 100%; display: flex; align-items: center; gap: 12px; padding: 12px 16px; border-radius: 12px; border: 1px solid var(--border); text-align: left;" class="hover:bg-[var(--bg-hover)] transition-colors">
              <span class="material-symbols-rounded" style="font-size: 20px; color: var(--text-muted);">monitor</span>
              <div>
                <p style="font-size: 14px; font-weight: 500; color: var(--text-primary);">This app only</p>
                <p style="font-size: 12px; color: var(--text-muted);">Sign out from FlowOne Drive</p>
              </div>
            </button>
            <button @click="handleLogoutGlobal" style="width: 100%; display: flex; align-items: center; gap: 12px; padding: 12px 16px; border-radius: 12px; border: 1px solid rgba(239,68,68,0.3); text-align: left;" class="hover:bg-[rgba(239,68,68,0.05)] transition-colors">
              <span class="material-symbols-rounded" style="font-size: 20px; color: #ef4444;">devices</span>
              <div>
                <p style="font-size: 14px; font-weight: 500; color: #ef4444;">All FlowOne apps</p>
                <p style="font-size: 12px; color: var(--text-muted);">Sign out from Email, Chat, and Drive</p>
              </div>
            </button>
          </div>
          <button @click="showLogoutDialog = false" style="width: 100%; margin-top: 12px; padding: 10px; font-size: 14px; color: var(--text-muted);" class="hover:text-[var(--text-secondary)] transition-colors">Cancel</button>
        </div>
      </div>
    </Teleport>

    <!-- Watch folder management (project info, change folder, remove) -->
    <WatchFolderManageModal :folder="managedWatchFolder" @close="managedWatchFolder = null" />
  </div>
</template>
