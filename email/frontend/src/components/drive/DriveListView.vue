<script setup>
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useDriveStore } from '@/stores/drive'
import DriveRowActionsMenu from '@/components/drive/DriveRowActionsMenu.vue'
import TierBadge from '@/components/storage/TierBadge.vue'
import { fileTypeLabel, FOLDER_TYPE_LABEL } from '@/utils/fileTypeLabel'
import { buildFolderPath, formatFolderPathLabel } from '@/utils/driveFolderPath'

const props = defineProps({
  folders: { type: Array, default: () => [] },
  files: { type: Array, default: () => [] },
  sortField: { type: String, default: 'name' },
  sortDirection: { type: String, default: 'asc' },
  activeEditors: { type: Object, default: () => ({}) },
  folderClientMap: { type: Object, default: () => ({}) }, // folder_id -> { client_id, client_name }
  currentFolderId: { type: [Number, String], default: null },
  parentFolderShared: { type: Boolean, default: false }, // True if current folder is publicly shared
  draggingFiles: { type: Array, default: () => [] }, // Files being dragged
  dragOverFolder: { type: [Number, String], default: null }, // Folder being dragged over
  downloadingIds: { type: [Object, Array], default: () => new Set() } // File ids whose download is being prepared
})

// True while a file's download is being prepared (token fetch / cold-storage
// restore), so the row icon can show a spinner for instant feedback.
function isDownloading(file) {
  const ids = props.downloadingIds
  if (!ids) return false
  if (typeof ids.has === 'function') return ids.has(file.id)
  return Array.isArray(ids) && ids.includes(file.id)
}

// Helper to check if file is being edited
function isFileBeingEdited(file) {
  return props.activeEditors[file.original_name] || props.activeEditors[file.id]
}

function getFileEditor(file) {
  const editor = props.activeEditors[file.original_name] || props.activeEditors[file.id]
  return editor?.user_email || null
}

function isEditedBySelf(file) {
  const editor = props.activeEditors[file.original_name] || props.activeEditors[file.id]
  return editor?.is_self === true
}

// Get editing duration in seconds for a file
function getEditingDuration(file) {
  const editor = props.activeEditors[file.original_name] || props.activeEditors[file.id]
  return editor?.editing_duration || 0
}

// Format seconds to MM:SS or HH:MM:SS
function formatEditingTime(seconds) {
  if (!seconds) return '0:00'
  const hrs = Math.floor(seconds / 3600)
  const mins = Math.floor((seconds % 3600) / 60)
  const secs = seconds % 60
  
  if (hrs > 0) {
    return `${hrs}:${mins.toString().padStart(2, '0')}:${secs.toString().padStart(2, '0')}`
  }
  return `${mins}:${secs.toString().padStart(2, '0')}`
}

const emit = defineEmits([
  'folder-click',
  'folder-dblclick',
  'folder-context',
  'file-click',
  'file-dblclick',
  'file-context',
  'file-download',
  'file-delete',
  'folder-delete',
  'sort-change',
  'show-versions',
  'stop-editing',
  'file-dragstart',
  'file-dragend',
  'folder-dragover',
  'folder-dragleave',
  'folder-drop',
  // Row actions (new 3-dot menu)
  'file-open',
  'file-rename',
  'file-move',
  'file-copy',
  'file-share',
  'file-toggle-star',
  'folder-open',
  'folder-rename',
  'folder-move',
  'folder-copy',
  'folder-share',
  'folder-toggle-star',
  // Jump to a search result's containing folder
  'open-folder-path',
])

const { t } = useI18n()

// Breadcrumb label for the folder that contains a search result, e.g.
// "My Drive / Clients / Acme". Only rendered while a Drive-wide search is active.
function folderPathLabel(containingId) {
  return formatFolderPathLabel(buildFolderPath(drive.allFolders, containingId), t('driveView.pathRoot'))
}

function isFolderProtected(item) {
  if (item.board_id) return true
  if (!item.parent_id && ['Boards', 'Attachments', 'Chats', 'Invoices', 'Moodboards'].includes(item.name)) return true
  return false
}

// Trailing actions gutter: hidden until row hover on desktop (the mock has no
// visible actions column); always visible on mobile and for selected/editing rows.
function actionsCellClass(alwaysVisible) {
  return [
    'flex items-center justify-end gap-1',
    alwaysVisible ? '' : 'sm:opacity-0 sm:group-hover:opacity-100 sm:focus-within:opacity-100 transition-opacity',
  ]
}

const drive = useDriveStore()

// Long-press support for mobile context menu
const longPressTimer = ref(null)
const longPressTarget = ref(null)
const LONG_PRESS_DURATION = 500 // ms

function handleTouchStart(e, item, type) {
  const touch = e.touches[0]
  longPressTarget.value = { item, type, x: touch.clientX, y: touch.clientY }
  
  longPressTimer.value = setTimeout(() => {
    if (longPressTarget.value) {
      // Haptic feedback if available
      if (navigator.vibrate) {
        navigator.vibrate(50)
      }
      // Emit the context event
      const eventName = type === 'folder' ? 'folder-context' : 'file-context'
      const fakeEvent = { 
        preventDefault: () => {}, 
        stopPropagation: () => {}, 
        clientX: longPressTarget.value.x, 
        clientY: longPressTarget.value.y 
      }
      emit(eventName, fakeEvent, longPressTarget.value.item)
      longPressTarget.value = null
    }
  }, LONG_PRESS_DURATION)
}

function handleTouchMove() {
  if (longPressTimer.value) {
    clearTimeout(longPressTimer.value)
    longPressTimer.value = null
  }
}

function handleTouchEnd() {
  if (longPressTimer.value) {
    clearTimeout(longPressTimer.value)
    longPressTimer.value = null
  }
  longPressTarget.value = null
}

// File type icons and colors
function getFileIconInfo(mimeType) {
  // Collab Documents (check first as they use custom mime types)
  if (mimeType === 'application/vnd.collab.document') {
    return { icon: 'article', color: 'text-blue-500', bgColor: 'bg-blue-100 dark:bg-blue-500/20', type: 'Document', sortOrder: 0, isCollab: true }
  }
  if (mimeType === 'application/vnd.collab.presentation') {
    return { icon: 'slideshow', color: 'text-orange-500', bgColor: 'bg-orange-100 dark:bg-orange-500/20', type: 'Slides', sortOrder: 0, isCollab: true }
  }
  // Images
  if (mimeType?.startsWith('image/')) {
    return { icon: 'image', color: 'text-pink-500', bgColor: 'bg-pink-100 dark:bg-pink-500/20', type: 'Image', sortOrder: 1 }
  }
  // Videos
  if (mimeType?.startsWith('video/')) {
    return { icon: 'movie', color: 'text-purple-500', bgColor: 'bg-purple-100 dark:bg-purple-500/20', type: 'Video', sortOrder: 2 }
  }
  // Audio
  if (mimeType?.startsWith('audio/')) {
    return { icon: 'audio_file', color: 'text-violet-500', bgColor: 'bg-violet-100 dark:bg-violet-500/20', type: 'Audio', sortOrder: 3 }
  }
  // PDF
  if (mimeType?.includes('pdf')) {
    return { icon: 'picture_as_pdf', color: 'text-red-500', bgColor: 'bg-red-100 dark:bg-red-500/20', type: 'PDF', sortOrder: 4 }
  }
  // Excel/Spreadsheets - CHECK BEFORE WORD (both have "document" in mime)
  if (mimeType?.includes('spreadsheet') || mimeType?.includes('sheet') || mimeType?.includes('excel') || mimeType?.includes('csv')) {
    return { icon: 'table_chart', color: 'text-green-600', bgColor: 'bg-green-100 dark:bg-green-500/20', type: 'Spreadsheet', sortOrder: 5 }
  }
  // PowerPoint/Presentations - CHECK BEFORE WORD
  if (mimeType?.includes('presentation') || mimeType?.includes('powerpoint')) {
    return { icon: 'slideshow', color: 'text-orange-500', bgColor: 'bg-orange-100 dark:bg-orange-500/20', type: 'Presentation', sortOrder: 6 }
  }
  // Word documents
  if (mimeType?.includes('word') || mimeType?.includes('msword') || mimeType?.includes('wordprocessing')) {
    return { icon: 'description', color: 'text-blue-600', bgColor: 'bg-blue-100 dark:bg-blue-500/20', type: 'Document', sortOrder: 7 }
  }
  // Archives
  if (mimeType?.includes('zip') || mimeType?.includes('compressed') || mimeType?.includes('archive') || mimeType?.includes('rar') || mimeType?.includes('7z')) {
    return { icon: 'folder_zip', color: 'text-amber-600', bgColor: 'bg-amber-100 dark:bg-amber-500/20', type: 'Archive', sortOrder: 8 }
  }
  // Code files
  if (mimeType?.includes('javascript') || mimeType?.includes('json') || mimeType?.includes('xml') || mimeType?.includes('html') || mimeType?.includes('css') || mimeType?.includes('php')) {
    return { icon: 'code', color: 'text-cyan-500', bgColor: 'bg-cyan-100 dark:bg-cyan-500/20', type: 'Code', sortOrder: 9 }
  }
  // Text files
  if (mimeType?.includes('text/')) {
    return { icon: 'article', color: 'text-slate-500', bgColor: 'bg-slate-100 dark:bg-slate-500/20', type: 'Text', sortOrder: 10 }
  }
  // Default
  return { icon: 'draft', color: 'text-surface-500', bgColor: 'bg-surface-100 dark:bg-surface-500/20', type: 'File', sortOrder: 99 }
}

// Check if image is oversized (> 3MB)
function isOversizedImage(item) {
  if (!item.mime_type?.startsWith('image/')) return false
  return item.size > 3 * 1024 * 1024 // 3MB
}

function formatSize(bytes) {
  if (!bytes) return '0 B'
  if (bytes >= 1073741824) return (bytes / 1073741824).toFixed(1) + ' GB'
  if (bytes >= 1048576) return (bytes / 1048576).toFixed(1) + ' MB'
  if (bytes >= 1024) return (bytes / 1024).toFixed(1) + ' KB'
  return bytes + ' B'
}

// Compact relative dates per mock: "2m ago", "8h ago", "2d ago", "Jun 3, 2026"
function formatRelativeDate(dateStr) {
  if (!dateStr) return '—'
  const date = new Date(dateStr)
  const now = new Date()
  const diff = now - date
  const days = Math.floor(diff / (1000 * 60 * 60 * 24))

  if (days === 0) {
    const hours = Math.floor(diff / (1000 * 60 * 60))
    if (hours === 0) {
      const minutes = Math.floor(diff / (1000 * 60))
      if (minutes < 1) return 'Just now'
      return `${minutes}m ago`
    }
    return `${hours}h ago`
  }
  if (days < 7) return `${days}d ago`
  return date.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })
}

// Combined and sorted items
const sortedItems = computed(() => {
  const items = [
    ...props.folders.map(f => ({ ...f, itemType: 'folder' })),
    ...props.files.map(f => ({ ...f, itemType: 'file' }))
  ]
  
  // Sort folders first, then files
  items.sort((a, b) => {
    // Folders always come first
    if (a.itemType !== b.itemType) {
      return a.itemType === 'folder' ? -1 : 1
    }
    
    // Then sort by field
    let aVal, bVal
    switch (props.sortField) {
      case 'name':
        aVal = (a.original_name || a.name || '').toLowerCase()
        bVal = (b.original_name || b.name || '').toLowerCase()
        break
      case 'modified':
        aVal = new Date(a.updated_at || a.created_at || 0)
        bVal = new Date(b.updated_at || b.created_at || 0)
        break
      case 'size':
        aVal = a.size || 0
        bVal = b.size || 0
        break
      case 'type':
        // Get sort order from file type info
        const aTypeInfo = getFileIconInfo(a.mime_type)
        const bTypeInfo = getFileIconInfo(b.mime_type)
        aVal = a.itemType === 'folder' ? 0 : aTypeInfo.sortOrder
        bVal = b.itemType === 'folder' ? 0 : bTypeInfo.sortOrder
        break
      case 'sharing':
        // Sort by sharing status: Public (2) > Shared with people (1) > Private (0)
        const getSharingOrder = (item) => {
          if (item.share_token) return 2 // Public
          if (item.collaborator_count > 0) return 1 // Shared with people
          return 0 // Private
        }
        aVal = getSharingOrder(a)
        bVal = getSharingOrder(b)
        break
      default:
        return 0
    }
    
    if (props.sortDirection === 'asc') {
      return aVal > bVal ? 1 : aVal < bVal ? -1 : 0
    } else {
      return aVal < bVal ? 1 : aVal > bVal ? -1 : 0
    }
  })
  
  return items
})

function toggleSort(field) {
  if (props.sortField === field) {
    emit('sort-change', field, props.sortDirection === 'asc' ? 'desc' : 'asc')
  } else {
    emit('sort-change', field, 'asc')
  }
}

function getSortIcon(field) {
  if (props.sortField !== field) return null
  return props.sortDirection === 'asc' ? 'arrow_upward' : 'arrow_downward'
}

// Get client/board info for a folder/file
function getClientForItem(item) {
  // For folders, first check if it has a board_name (linked to board)
  if (item.itemType === 'folder' && item.board_name) {
    return { client_name: item.board_name, is_board: true }
  }
  
  // For folders, check if it has client_id directly on the object
  if (item.itemType === 'folder' && item.client_id && item.client_name) {
    return { client_id: item.client_id, client_name: item.client_name }
  }
  
  // For folders, check if this folder or its parent is linked to a client via mapping
  if (item.itemType === 'folder') {
    const mapping = props.folderClientMap[String(item.id)]
    if (mapping) return mapping
  }
  
  // For files, check parent folder mapping
  if (item.itemType === 'file' && item.folder_id) {
    const mapping = props.folderClientMap[String(item.folder_id)]
    if (mapping) return mapping
  }
  
  // Also check current folder context
  if (props.currentFolderId) {
    const mapping = props.folderClientMap[String(props.currentFolderId)]
    if (mapping) return mapping
  }
  
  return null
}
</script>

<template>
  <div class="drive-list-view">
    <!-- Header row (sticky). Selection works via click / shift-click /
         ctrl-click on the rows themselves (file-manager style), so there
         are no checkbox columns. Columns per mock: Name | Modified | Type |
         Size, with thin separators between header cells and a slim trailing
         gutter for the hover row-actions menu. -->
    <div class="sticky top-0 z-20 grid grid-cols-[minmax(0,1fr)_104px_40px] sm:grid-cols-[minmax(0,1fr)_150px_210px_110px_44px] px-3 bg-transparent border-b border-surface-200/80 dark:border-surface-700/40 text-xs font-medium text-surface-500 dark:text-surface-400">
      <div class="flex items-center gap-1 py-3 sm:py-2 pr-2 cursor-pointer hover:text-surface-900 dark:hover:text-surface-100" @click="toggleSort('name')">
        Name
        <span v-if="getSortIcon('name')" class="material-symbols-rounded text-xs">{{ getSortIcon('name') }}</span>
      </div>
      <div class="flex items-center gap-1 py-3 sm:py-2 px-2 sm:border-l border-surface-200/80 dark:border-surface-700/40 cursor-pointer hover:text-surface-900 dark:hover:text-surface-100" @click="toggleSort('modified')">
        Modified
        <span v-if="getSortIcon('modified')" class="material-symbols-rounded text-xs">{{ getSortIcon('modified') }}</span>
      </div>
      <div class="hidden sm:flex items-center gap-1 py-2 px-2 border-l border-surface-200/80 dark:border-surface-700/40 cursor-pointer hover:text-surface-900 dark:hover:text-surface-100" @click="toggleSort('type')">
        Type
        <span v-if="getSortIcon('type')" class="material-symbols-rounded text-xs">{{ getSortIcon('type') }}</span>
      </div>
      <div class="hidden sm:flex items-center gap-1 py-2 px-2 border-l border-surface-200/80 dark:border-surface-700/40 cursor-pointer hover:text-surface-900 dark:hover:text-surface-100" @click="toggleSort('size')">
        Size
        <span v-if="getSortIcon('size')" class="material-symbols-rounded text-xs">{{ getSortIcon('size') }}</span>
      </div>
      <div class="hidden sm:block"></div>
    </div>
    
    <!-- Items (near-invisible separators, file-manager style) -->
    <div class="divide-y divide-surface-100/80 dark:divide-surface-800/40">
      <template v-for="item in sortedItems" :key="item.itemType + '-' + item.id">
        <!-- Folder row -->
        <div 
          v-if="item.itemType === 'folder'"
          class="grid grid-cols-[minmax(0,1fr)_104px_40px] sm:grid-cols-[minmax(0,1fr)_150px_210px_110px_44px] items-center px-3 py-2 cursor-pointer transition-all group select-none"
          :class="[
            drive.isFolderSelected(item.id)
              ? 'bg-primary-50 dark:bg-primary-900/30 border-l-[3px] border-l-primary-500 pl-[9px]'
              : 'hover:bg-surface-50 dark:hover:bg-surface-800/50 border-l-[3px] border-l-transparent pl-[9px]',
            props.dragOverFolder === item.id ? 'ring-2 ring-primary-500 bg-primary-100 dark:bg-primary-500/30' : ''
          ]"
          @click="emit('folder-click', $event, item)"
          @dblclick="emit('folder-dblclick', $event, item)"
          @contextmenu.prevent="emit('folder-context', $event, item)"
          @touchstart="handleTouchStart($event, item, 'folder')"
          @touchmove="handleTouchMove"
          @touchend="handleTouchEnd"
          @dragover.prevent="emit('folder-dragover', $event, item.id)"
          @dragleave="emit('folder-dragleave', $event)"
          @drop="emit('folder-drop', $event, item.id)"
        >
          <!-- Name (+ inline badges: star / chat / sharing / client) -->
          <div class="flex items-center gap-2.5 min-w-0 pr-2">
            <div class="relative flex-shrink-0">
              <span class="material-symbols-rounded icon-filled text-lg text-primary-500">{{ props.dragOverFolder === item.id ? 'folder_open' : 'folder' }}</span>
              <!-- Protected folder indicator (linked to board OR system folder) -->
              <div 
                v-if="item.board_id || ((item.name === 'Boards' || item.name === 'Attachments' || item.name === 'Chats' || item.name === 'Invoices' || item.name === 'Moodboards') && !item.parent_id)" 
                class="absolute -top-1 -right-1 w-3.5 h-3.5 rounded-full bg-amber-100 dark:bg-amber-500/30 flex items-center justify-center shadow-sm"
                :title="item.board_id ? 'Protected - linked to a board' : 'System folder'"
              >
                <span class="material-symbols-rounded text-[9px] text-amber-600 dark:text-amber-400">shield</span>
              </div>
            </div>
            <div class="flex flex-col min-w-0">
              <div class="flex items-center gap-2 min-w-0">
                <span class="text-[13px] font-medium text-surface-900 dark:text-surface-100 truncate" :title="item.name">{{ item.name }}</span>
            <span
              v-if="item.is_starred"
              class="material-symbols-rounded text-sm text-amber-500 flex-shrink-0"
              :title="$t('driveView.removeFromStarred')"
            >star</span>
            <!-- Shared in Chat indicator -->
            <span 
              v-if="drive.isSharedInChat('folder', item.id)"
              class="hidden sm:inline-flex items-center gap-0.5 px-1.5 py-0.5 rounded text-[11px] font-medium bg-indigo-100 dark:bg-indigo-500/20 text-indigo-600 dark:text-indigo-400 flex-shrink-0"
              title="Shared in Chat"
            >
              <span class="material-symbols-rounded text-xs">chat</span>
              Chat
            </span>
            <!-- Sharing status (icon-only, inline per mock) -->
            <span
              v-if="item.share_token"
              class="hidden sm:inline-flex items-center justify-center w-5 h-5 rounded-full bg-green-100 dark:bg-green-500/20 text-green-700 dark:text-green-400 flex-shrink-0"
              title="Public link"
            >
              <span class="material-symbols-rounded text-xs">public</span>
            </span>
            <span
              v-else-if="item.collaborator_count > 0"
              class="hidden sm:inline-flex items-center gap-0.5 px-1.5 h-5 rounded-full bg-blue-100 dark:bg-blue-500/20 text-blue-700 dark:text-blue-400 text-[11px] font-medium flex-shrink-0"
              :title="`Shared with ${item.collaborator_count} ${item.collaborator_count === 1 ? 'person' : 'people'}`"
            >
              <span class="material-symbols-rounded text-xs">group</span>
              {{ item.collaborator_count }}
            </span>
            <span
              v-else-if="parentFolderShared"
              class="hidden sm:inline-flex items-center justify-center w-5 h-5 rounded-full bg-green-50 dark:bg-green-500/10 text-green-600 dark:text-green-400 flex-shrink-0"
              title="Shared via parent folder"
            >
              <span class="material-symbols-rounded text-xs">folder_shared</span>
            </span>
            <!-- Client / board link (icon-only, inline) -->
            <span
              v-if="getClientForItem(item)"
              class="hidden sm:inline-flex items-center justify-center w-5 h-5 rounded-full flex-shrink-0"
              :class="getClientForItem(item).is_board 
                ? 'bg-cyan-100 dark:bg-cyan-500/20 text-cyan-700 dark:text-cyan-400'
                : 'bg-violet-100 dark:bg-violet-500/20 text-violet-700 dark:text-violet-400'"
              :title="getClientForItem(item).client_name"
            >
              <span class="material-symbols-rounded text-xs">{{ getClientForItem(item).is_board ? 'dashboard' : 'business' }}</span>
            </span>
              </div>
              <!-- Containing folder path (search results only) -->
              <button
                v-if="drive.searchActive"
                type="button"
                @click.stop="emit('open-folder-path', item.parent_id ?? null)"
                class="flex items-center gap-1 mt-0.5 text-[11px] text-surface-400 hover:text-primary-500 dark:hover:text-primary-400 max-w-full text-left transition-colors"
                :title="$t('driveView.openContainingFolder') + ': ' + folderPathLabel(item.parent_id)"
              >
                <span class="material-symbols-rounded text-[13px] flex-shrink-0">folder</span>
                <span class="truncate">{{ folderPathLabel(item.parent_id) }}</span>
              </button>
            </div>
          </div>
          <!-- Modified -->
          <div class="text-xs sm:text-[13px] text-surface-500 px-2 truncate">
            {{ formatRelativeDate(item.updated_at || item.created_at) }}
          </div>
          <!-- Type -->
          <div class="hidden sm:block text-[13px] text-surface-500 px-2 truncate">
            {{ FOLDER_TYPE_LABEL }}
          </div>
          <!-- Size -->
          <div class="hidden sm:block text-[13px] text-surface-500 px-2 truncate">
            {{ item.size !== null && item.size !== undefined ? formatSize(item.size) : '—' }}
          </div>
          <!-- Row actions (revealed on hover on desktop) -->
          <div :class="actionsCellClass(drive.isFolderSelected(item.id))">
            <DriveRowActionsMenu
              :item="item"
              item-type="folder"
              :is-protected="isFolderProtected(item)"
              @open="emit('folder-open', item)"
              @download="emit('file-download', item)"
              @rename="emit('folder-rename', item)"
              @move="emit('folder-move', item)"
              @copy="emit('folder-copy', item)"
              @share="emit('folder-share', item)"
              @toggle-star="emit('folder-toggle-star', item)"
              @delete="emit('folder-delete', item)"
            />
          </div>
        </div>
        
        <!-- File row -->
        <div 
          v-else
          draggable="true"
          class="grid grid-cols-[minmax(0,1fr)_104px_40px] sm:grid-cols-[minmax(0,1fr)_150px_210px_110px_44px] items-center px-3 py-2 cursor-pointer transition-all group select-none"
          :class="[
            drive.isFileSelected(item.id)
              ? 'bg-primary-50 dark:bg-primary-900/30 border-l-[3px] border-l-primary-500 pl-[9px]'
              : 'hover:bg-surface-50 dark:hover:bg-surface-800/50 border-l-[3px] border-l-transparent pl-[9px]',
            isEditedBySelf(item) ? 'editing-self-row !bg-green-500/10 dark:!bg-green-500/15 !border-l-green-500' : '',
            isFileBeingEdited(item) && !isEditedBySelf(item) ? 'editing-other-row !bg-red-500/10 dark:!bg-red-500/15 !border-l-red-500' : '',
            props.draggingFiles.some(f => f.id === item.id) ? 'opacity-50' : ''
          ]"
          @click="emit('file-click', $event, item)"
          @dblclick="emit('file-dblclick', $event, item)"
          @contextmenu.prevent="emit('file-context', $event, item)"
          @touchstart="handleTouchStart($event, item, 'file')"
          @touchmove="handleTouchMove"
          @touchend="handleTouchEnd"
          @dragstart="emit('file-dragstart', $event, item)"
          @dragend="emit('file-dragend', $event)"
        >
          <!-- Name (+ inline badges) -->
          <div class="flex items-center gap-2.5 min-w-0 pr-2">
            <div :class="['w-6 h-6 rounded-md flex items-center justify-center flex-shrink-0', getFileIconInfo(item.mime_type).bgColor]">
              <span v-if="isDownloading(item)" class="spinner w-3.5 h-3.5 text-primary-500" :title="$t('driveView.preparingDownload')"></span>
              <span v-else :class="['material-symbols-rounded text-sm', getFileIconInfo(item.mime_type).color]">
                {{ getFileIconInfo(item.mime_type).icon }}
              </span>
            </div>
            <div class="flex flex-col min-w-0">
              <div class="flex items-center gap-2 min-w-0">
                <span class="text-[13px] font-medium text-surface-900 dark:text-surface-100 truncate" :title="item.original_name">{{ item.original_name }}</span>
                <span
                  v-if="item.is_starred"
                  class="material-symbols-rounded text-sm text-amber-500 flex-shrink-0"
                  :title="$t('driveView.removeFromStarred')"
                >star</span>
                <!-- Storage location indicator -->
                <span 
                  v-if="item.storage_location === 'nfs'" 
                  class="hidden sm:inline-flex items-center gap-0.5 px-1.5 py-0.5 rounded text-[11px] font-medium bg-cyan-100 dark:bg-cyan-500/20 text-cyan-600 dark:text-cyan-400 flex-shrink-0"
                  title="Stored on NAS"
                >
                  <span class="material-symbols-rounded text-xs">cloud_sync</span>
                  NAS
                </span>
                <!-- Phase 8: tier_state badge (hidden when 'hot') -->
                <TierBadge
                  :tier-state="item.tier_state"
                  :tier-changed-at="item.tier_changed_at"
                />

                <button 
                  v-if="item.current_version > 1" 
                  @click.stop="emit('show-versions', item)"
                  class="hidden sm:inline-flex items-center gap-0.5 text-[11px] font-medium px-1.5 py-0.5 rounded transition-colors flex-shrink-0
                         text-green-600 dark:text-green-400 bg-green-100 dark:bg-green-500/20
                         hover:bg-green-200 dark:hover:bg-green-500/30"
                  :title="$t('driveView.versionsClickToViewHistory', item.current_version, { count: item.current_version })"
                >
                  v{{ item.current_version }}
                </button>
                <!-- Shared in Chat indicator -->
                <span 
                  v-if="drive.isSharedInChat('file', item.id)"
                  class="hidden sm:inline-flex items-center gap-0.5 px-1.5 py-0.5 rounded text-[11px] font-medium bg-indigo-100 dark:bg-indigo-500/20 text-indigo-600 dark:text-indigo-400 flex-shrink-0"
                  title="Shared in Chat"
                >
                  <span class="material-symbols-rounded text-xs">chat</span>
                  Chat
                </span>
                <!-- Sharing status (icon-only, inline per mock) -->
                <span
                  v-if="item.share_token"
                  class="hidden sm:inline-flex items-center justify-center w-5 h-5 rounded-full bg-green-100 dark:bg-green-500/20 text-green-700 dark:text-green-400 flex-shrink-0"
                  title="Public link"
                >
                  <span class="material-symbols-rounded text-xs">link</span>
                </span>
                <span
                  v-else-if="parentFolderShared"
                  class="hidden sm:inline-flex items-center justify-center w-5 h-5 rounded-full bg-green-50 dark:bg-green-500/10 text-green-600 dark:text-green-400 flex-shrink-0"
                  title="Shared via parent folder"
                >
                  <span class="material-symbols-rounded text-xs">folder_shared</span>
                </span>
                <!-- Client / board link (icon-only, inline) -->
                <span
                  v-if="getClientForItem(item)"
                  class="hidden sm:inline-flex items-center justify-center w-5 h-5 rounded-full flex-shrink-0"
                  :class="getClientForItem(item).is_board 
                    ? 'bg-cyan-100 dark:bg-cyan-500/20 text-cyan-700 dark:text-cyan-400'
                    : 'bg-violet-100 dark:bg-violet-500/20 text-violet-700 dark:text-violet-400'"
                  :title="getClientForItem(item).client_name"
                >
                  <span class="material-symbols-rounded text-xs">{{ getClientForItem(item).is_board ? 'dashboard' : 'business' }}</span>
                </span>
              </div>
              <!-- Editing indicator - below filename with tracking counter -->
              <div 
                v-if="isEditedBySelf(item)"
                class="hidden sm:flex items-center gap-2 mt-0.5"
              >
                <span class="flex items-center gap-1 text-xs text-green-500 dark:text-green-400">
                  <span class="material-symbols-rounded text-xs animate-pulse">edit</span>
                  You are editing this file
                </span>
                <!-- Tracking counter -->
                <span class="flex items-center gap-1 px-2 py-0.5 rounded bg-green-500/20 text-green-500 dark:text-green-400 font-mono text-xs font-semibold">
                  <span class="material-symbols-rounded text-xs">timer</span>
                  {{ formatEditingTime(getEditingDuration(item)) }}
                </span>
              </div>
              <span 
                v-else-if="isFileBeingEdited(item)"
                class="hidden sm:flex items-center gap-1 text-xs text-red-500 dark:text-red-400 mt-0.5"
              >
                <span class="material-symbols-rounded text-xs">lock</span>
                Being edited by {{ getFileEditor(item) }}
              </span>
              <!-- Containing folder path (search results only) -->
              <button
                v-if="drive.searchActive"
                type="button"
                @click.stop="emit('open-folder-path', item.folder_id ?? null)"
                class="flex items-center gap-1 mt-0.5 text-[11px] text-surface-400 hover:text-primary-500 dark:hover:text-primary-400 max-w-full text-left transition-colors"
                :title="$t('driveView.openContainingFolder') + ': ' + folderPathLabel(item.folder_id)"
              >
                <span class="material-symbols-rounded text-[13px] flex-shrink-0">folder</span>
                <span class="truncate">{{ folderPathLabel(item.folder_id) }}</span>
              </button>
            </div>
          </div>
          <!-- Modified -->
          <div
            class="text-xs sm:text-[13px] text-surface-500 px-2 truncate"
            :title="item.last_modified_by ? `by ${item.last_modified_by}` : undefined"
          >
            {{ formatRelativeDate(item.updated_at || item.created_at) }}
          </div>
          <!-- Type -->
          <div class="hidden sm:block text-[13px] text-surface-500 px-2 truncate">
            {{ fileTypeLabel(item) }}
          </div>
          <!-- Size -->
          <div class="hidden sm:flex items-center gap-1 text-[13px] px-2 min-w-0">
            <span 
              v-if="isOversizedImage(item)" 
              class="material-symbols-rounded text-sm text-orange-500 flex-shrink-0"
              title="Large image - consider optimizing"
            >warning</span>
            <span class="truncate" :class="isOversizedImage(item) ? 'text-orange-500 font-medium' : 'text-surface-500'">
              {{ formatSize(item.size) }}
            </span>
          </div>
          <!-- Row actions (revealed on hover on desktop) -->
          <div :class="actionsCellClass(drive.isFileSelected(item.id) || isEditedBySelf(item))">
            <!-- STOP EDITING BUTTON - kept prominent when user is editing -->
            <button
              v-if="isEditedBySelf(item)"
              @click.stop="emit('stop-editing', item)"
              class="flex items-center gap-1 px-2 py-0.5 rounded-md bg-red-500 hover:bg-red-600 text-white text-xs font-medium transition-colors shadow-sm flex-shrink-0"
              :title="$t('driveListView.stopEditingThisFile')"
            >
              <span class="material-symbols-rounded text-sm">stop</span>
              Stop
            </button>
            <DriveRowActionsMenu
              :item="item"
              item-type="file"
              :has-versions="item.current_version > 1"
              :is-edited-by-self="isEditedBySelf(item)"
              @open="emit('file-open', item)"
              @download="emit('file-download', item)"
              @rename="emit('file-rename', item)"
              @move="emit('file-move', item)"
              @copy="emit('file-copy', item)"
              @share="emit('file-share', item)"
              @toggle-star="emit('file-toggle-star', item)"
              @show-versions="emit('show-versions', item)"
              @stop-editing="emit('stop-editing', item)"
              @delete="emit('file-delete', item)"
            />
          </div>
        </div>
      </template>
    </div>
    
    <!-- Empty state -->
    <div v-if="sortedItems.length === 0" class="text-center py-12">
      <span class="material-symbols-rounded text-5xl text-surface-300 dark:text-surface-600 mb-3">folder_off</span>
      <p class="text-surface-500 dark:text-surface-400">No items to display</p>
    </div>
  </div>
</template>

<style scoped>
.drive-list-view {
  @apply w-full;
}

/* Pulsating border animation for files being edited */
.editing-self-row {
  animation: pulse-green 2s ease-in-out infinite;
}

.editing-other-row {
  animation: pulse-red 2s ease-in-out infinite;
}

@keyframes pulse-green {
  0%, 100% {
    box-shadow: 0 0 0 0 rgba(34, 197, 94, 0.4);
    border-color: rgba(34, 197, 94, 0.6);
  }
  50% {
    box-shadow: 0 0 0 4px rgba(34, 197, 94, 0.2);
    border-color: rgba(34, 197, 94, 1);
  }
}

@keyframes pulse-red {
  0%, 100% {
    box-shadow: 0 0 0 0 rgba(239, 68, 68, 0.4);
    border-color: rgba(239, 68, 68, 0.6);
  }
  50% {
    box-shadow: 0 0 0 4px rgba(239, 68, 68, 0.2);
    border-color: rgba(239, 68, 68, 1);
  }
}
</style>

