<script setup>
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useDriveStore } from '@/stores/drive'
import DriveRowActionsMenu from '@/components/drive/DriveRowActionsMenu.vue'
import { fileTypeLabel, FOLDER_TYPE_LABEL } from '@/utils/fileTypeLabel'
import { buildFolderPath, formatFolderPathLabel } from '@/utils/driveFolderPath'

const props = defineProps({
  folders: { type: Array, default: () => [] },
  files: { type: Array, default: () => [] },
  sortField: { type: String, default: 'name' },
  sortDirection: { type: String, default: 'asc' },
  activeEditors: { type: Object, default: () => ({}) },
  currentFolderId: { type: [Number, String], default: null },
  parentFolderShared: { type: Boolean, default: false },
  draggingFiles: { type: Array, default: () => [] },
  dragOverFolder: { type: [Number, String], default: null },
})

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
  'background-context',
  // Jump to a search result's containing folder
  'open-folder-path',
])

const drive = useDriveStore()
const { t } = useI18n()

// Breadcrumb label for the folder that contains a search result, e.g.
// "My Drive / Clients / Acme". Only rendered while a Drive-wide search is active.
function folderPathLabel(containingId) {
  return formatFolderPathLabel(buildFolderPath(drive.allFolders, containingId), t('driveView.pathRoot'))
}

// Long-press support (mobile context menu)
const longPressTimer = ref(null)
const longPressTarget = ref(null)
const LONG_PRESS_DURATION = 500

function handleTouchStart(e, item, type) {
  const touch = e.touches[0]
  longPressTarget.value = { item, type, x: touch.clientX, y: touch.clientY }
  longPressTimer.value = setTimeout(() => {
    if (longPressTarget.value) {
      if (navigator.vibrate) navigator.vibrate(50)
      const eventName = type === 'folder' ? 'folder-context' : 'file-context'
      const fakeEvent = {
        preventDefault: () => {},
        stopPropagation: () => {},
        clientX: longPressTarget.value.x,
        clientY: longPressTarget.value.y,
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

function isFolderProtected(item) {
  if (item.board_id) return true
  if (!item.parent_id && ['Boards', 'Attachments', 'Chats', 'Invoices', 'Moodboards'].includes(item.name)) return true
  return false
}

function isFileBeingEdited(file) {
  return props.activeEditors[file.original_name] || props.activeEditors[file.id]
}

function isEditedBySelf(file) {
  const editor = props.activeEditors[file.original_name] || props.activeEditors[file.id]
  return editor?.is_self === true
}

// Match DriveListView's icon mapping but use it only for inline glyphs.
function getFileIconInfo(mimeType) {
  if (mimeType === 'application/vnd.collab.document') {
    return { icon: 'article', color: 'text-blue-500', sortOrder: 0, type: 'Document' }
  }
  if (mimeType === 'application/vnd.collab.presentation') {
    return { icon: 'slideshow', color: 'text-orange-500', sortOrder: 0, type: 'Slides' }
  }
  if (mimeType?.startsWith('image/')) return { icon: 'image', color: 'text-pink-500', sortOrder: 1, type: 'Image' }
  if (mimeType?.startsWith('video/')) return { icon: 'movie', color: 'text-purple-500', sortOrder: 2, type: 'Video' }
  if (mimeType?.startsWith('audio/')) return { icon: 'audio_file', color: 'text-violet-500', sortOrder: 3, type: 'Audio' }
  if (mimeType?.includes('pdf')) return { icon: 'picture_as_pdf', color: 'text-red-500', sortOrder: 4, type: 'PDF' }
  if (mimeType?.includes('spreadsheet') || mimeType?.includes('sheet') || mimeType?.includes('excel') || mimeType?.includes('csv')) {
    return { icon: 'table_chart', color: 'text-green-600', sortOrder: 5, type: 'Spreadsheet' }
  }
  if (mimeType?.includes('presentation') || mimeType?.includes('powerpoint')) {
    return { icon: 'slideshow', color: 'text-orange-500', sortOrder: 6, type: 'Presentation' }
  }
  if (mimeType?.includes('word') || mimeType?.includes('msword') || mimeType?.includes('wordprocessing')) {
    return { icon: 'description', color: 'text-blue-600', sortOrder: 7, type: 'Document' }
  }
  if (mimeType?.includes('zip') || mimeType?.includes('compressed') || mimeType?.includes('archive') || mimeType?.includes('rar') || mimeType?.includes('7z')) {
    return { icon: 'folder_zip', color: 'text-amber-600', sortOrder: 8, type: 'Archive' }
  }
  if (mimeType?.includes('javascript') || mimeType?.includes('json') || mimeType?.includes('xml') || mimeType?.includes('html') || mimeType?.includes('css') || mimeType?.includes('php')) {
    return { icon: 'code', color: 'text-cyan-500', sortOrder: 9, type: 'Code' }
  }
  if (mimeType?.includes('text/')) return { icon: 'article', color: 'text-slate-500', sortOrder: 10, type: 'Text' }
  return { icon: 'draft', color: 'text-surface-500', sortOrder: 99, type: 'File' }
}

function formatSize(bytes) {
  if (!bytes) return ''
  if (bytes >= 1073741824) return (bytes / 1073741824).toFixed(1) + ' GB'
  if (bytes >= 1048576) return (bytes / 1048576).toFixed(1) + ' MB'
  if (bytes >= 1024) return (bytes / 1024).toFixed(1) + ' KB'
  return bytes + ' B'
}

function formatRelativeDate(dateStr) {
  if (!dateStr) return ''
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
  if (days === 1) return 'Yesterday'
  if (days < 7) return `${days}d ago`
  return date.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })
}

const sortedItems = computed(() => {
  const items = [
    ...props.folders.map(f => ({ ...f, itemType: 'folder' })),
    ...props.files.map(f => ({ ...f, itemType: 'file' })),
  ]
  items.sort((a, b) => {
    if (a.itemType !== b.itemType) return a.itemType === 'folder' ? -1 : 1
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
      case 'type': {
        const aT = getFileIconInfo(a.mime_type)
        const bT = getFileIconInfo(b.mime_type)
        aVal = a.itemType === 'folder' ? 0 : aT.sortOrder
        bVal = b.itemType === 'folder' ? 0 : bT.sortOrder
        break
      }
      default:
        return 0
    }
    if (props.sortDirection === 'asc') return aVal > bVal ? 1 : aVal < bVal ? -1 : 0
    return aVal < bVal ? 1 : aVal > bVal ? -1 : 0
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
</script>

<template>
  <div class="drive-compact-view text-[13px] leading-tight flex flex-col min-h-0">
    <!-- Header row (file-manager style: sentence case, subtle, blends with page bg).
         Static (not sticky): rows scroll in their own container below, so they
         clip at the header's bottom edge without needing a background. -->
    <div
      class="flex-shrink-0 grid grid-cols-[1fr_120px_70px] sm:grid-cols-[minmax(0,1fr)_140px_220px_110px] gap-3 px-3 py-2.5 sm:py-2 bg-transparent border-b border-surface-200/80 dark:border-surface-700/40 text-[11px] font-medium text-surface-500 dark:text-surface-400 select-none"
    >
      <button
        type="button"
        class="flex items-center gap-1 hover:text-surface-900 dark:hover:text-surface-100 text-left"
        @click="toggleSort('name')"
      >
        <span>Name</span>
        <span v-if="getSortIcon('name')" class="material-symbols-rounded text-[14px]">{{ getSortIcon('name') }}</span>
      </button>
      <button
        type="button"
        class="hidden sm:flex items-center gap-1 hover:text-surface-900 dark:hover:text-surface-100 text-left"
        @click="toggleSort('modified')"
      >
        <span>Modified</span>
        <span v-if="getSortIcon('modified')" class="material-symbols-rounded text-[14px]">{{ getSortIcon('modified') }}</span>
      </button>
      <button
        type="button"
        class="flex items-center gap-1 hover:text-surface-900 dark:hover:text-surface-100 text-left"
        @click="toggleSort('type')"
      >
        <span>Type</span>
        <span v-if="getSortIcon('type')" class="material-symbols-rounded text-[14px]">{{ getSortIcon('type') }}</span>
      </button>
      <button
        type="button"
        class="flex items-center gap-1 justify-end hover:text-surface-900 dark:hover:text-surface-100 text-right"
        @click="toggleSort('size')"
      >
        <span>Size</span>
        <span v-if="getSortIcon('size')" class="material-symbols-rounded text-[14px]">{{ getSortIcon('size') }}</span>
      </button>
    </div>

    <!-- Items scroll independently so they are clipped just below the header -->
    <div
      class="flex-1 min-h-0 overflow-y-auto divide-y divide-surface-100/80 dark:divide-surface-800/40"
      @click.self="drive.clearSelection()"
      @contextmenu.self="emit('background-context', $event)"
    >
    <template v-for="item in sortedItems" :key="item.itemType + '-' + item.id">
      <!-- Folder row -->
      <div
        v-if="item.itemType === 'folder'"
        class="group grid grid-cols-[1fr_120px_70px] sm:grid-cols-[minmax(0,1fr)_140px_220px_110px] items-center gap-3 px-3 rounded-md cursor-pointer select-none border-l-[3px]"
        :class="[
          drive.isFolderSelected(item.id)
            ? 'bg-primary-50 dark:bg-primary-900/30 border-l-primary-500'
            : 'hover:bg-surface-50 dark:hover:bg-surface-800/50 border-l-transparent',
          props.dragOverFolder === item.id ? 'ring-1 ring-primary-500 bg-primary-100 dark:bg-primary-500/30' : ''
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
        <div class="flex items-center gap-2 min-w-0">
          <span
            class="material-symbols-rounded icon-filled text-lg text-primary-500 flex-shrink-0"
          >{{ props.dragOverFolder === item.id ? 'folder_open' : 'folder' }}</span>
          <div class="flex flex-col min-w-0">
            <div class="flex items-center gap-2 min-w-0">
          <span
            class="truncate text-surface-900 dark:text-surface-100 font-medium"
            :title="item.name"
          >{{ item.name }}</span>
          <span
            v-if="item.is_starred"
            class="material-symbols-rounded icon-filled text-[14px] text-amber-500 flex-shrink-0"
            title="Starred"
          >star</span>
          <span
            v-if="item.share_token"
            class="material-symbols-rounded text-[14px] text-green-500 flex-shrink-0"
            title="Shared"
          >link</span>
            </div>
            <!-- Containing folder path (search results only) -->
            <button
              v-if="drive.searchActive"
              type="button"
              @click.stop="emit('open-folder-path', item.parent_id ?? null)"
              class="flex items-center gap-1 text-[11px] text-surface-400 hover:text-primary-500 dark:hover:text-primary-400 max-w-full text-left transition-colors"
              :title="$t('driveView.openContainingFolder') + ': ' + folderPathLabel(item.parent_id)"
            >
              <span class="material-symbols-rounded text-[13px] flex-shrink-0">folder</span>
              <span class="truncate">{{ folderPathLabel(item.parent_id) }}</span>
            </button>
          </div>
        </div>
        <span class="hidden sm:block text-surface-500 dark:text-surface-400 text-xs truncate">
          {{ formatRelativeDate(item.updated_at || item.created_at) }}
        </span>
        <span class="text-surface-500 dark:text-surface-400 text-xs truncate">{{ FOLDER_TYPE_LABEL }}</span>
        <div class="flex items-center justify-end gap-1">
          <span class="text-surface-400 dark:text-surface-500 text-xs tabular-nums">{{ item.size ? formatSize(item.size) : '' }}</span>
          <DriveRowActionsMenu
            class="sm:opacity-0 sm:group-hover:opacity-100 sm:focus-within:opacity-100 transition-opacity"
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
        class="group grid grid-cols-[1fr_120px_70px] sm:grid-cols-[minmax(0,1fr)_140px_220px_110px] items-center gap-3 px-3 rounded-md cursor-pointer select-none border-l-[3px]"
        :class="[
          drive.isFileSelected(item.id)
            ? 'bg-primary-50 dark:bg-primary-900/30 border-l-primary-500'
            : 'hover:bg-surface-50 dark:hover:bg-surface-800/50 border-l-transparent',
          isEditedBySelf(item) ? '!bg-green-500/10 dark:!bg-green-500/15 !border-l-green-500' : '',
          isFileBeingEdited(item) && !isEditedBySelf(item) ? '!bg-red-500/10 dark:!bg-red-500/15 !border-l-red-500' : '',
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
        <div class="flex items-center gap-2 min-w-0">
          <span
            :class="['material-symbols-rounded text-[16px] flex-shrink-0', getFileIconInfo(item.mime_type).color]"
          >{{ getFileIconInfo(item.mime_type).icon }}</span>
          <div class="flex flex-col min-w-0">
            <div class="flex items-center gap-2 min-w-0">
          <span
            class="truncate text-surface-900 dark:text-surface-100"
            :title="item.original_name"
          >{{ item.original_name }}</span>
          <span
            v-if="item.is_starred"
            class="material-symbols-rounded icon-filled text-[14px] text-amber-500 flex-shrink-0"
            title="Starred"
          >star</span>
          <button
            v-if="item.current_version > 1"
            @click.stop="emit('show-versions', item)"
            class="text-[10px] font-mono px-1 rounded bg-green-100 dark:bg-green-500/20 text-green-700 dark:text-green-400 flex-shrink-0
                   hover:bg-green-200 dark:hover:bg-green-500/30 transition-colors"
            :title="$t('driveView.versionsClickToViewHistory', item.current_version, { count: item.current_version })"
          >v{{ item.current_version }}</button>
            </div>
            <!-- Containing folder path (search results only) -->
            <button
              v-if="drive.searchActive"
              type="button"
              @click.stop="emit('open-folder-path', item.folder_id ?? null)"
              class="flex items-center gap-1 text-[11px] text-surface-400 hover:text-primary-500 dark:hover:text-primary-400 max-w-full text-left transition-colors"
              :title="$t('driveView.openContainingFolder') + ': ' + folderPathLabel(item.folder_id)"
            >
              <span class="material-symbols-rounded text-[13px] flex-shrink-0">folder</span>
              <span class="truncate">{{ folderPathLabel(item.folder_id) }}</span>
            </button>
          </div>
        </div>
        <span class="hidden sm:block text-surface-500 dark:text-surface-400 text-xs truncate">
          {{ formatRelativeDate(item.updated_at || item.created_at) }}
        </span>
        <span class="text-surface-500 dark:text-surface-400 text-xs line-clamp-2 leading-snug">{{ fileTypeLabel(item) }}</span>
        <div class="flex items-center justify-end gap-1">
          <span class="text-surface-400 dark:text-surface-500 text-xs tabular-nums">{{ formatSize(item.size) }}</span>
          <DriveRowActionsMenu
            class="sm:opacity-0 sm:group-hover:opacity-100 sm:focus-within:opacity-100 transition-opacity"
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
    <div v-if="sortedItems.length === 0" class="text-center py-10">
      <span class="material-symbols-rounded text-4xl text-surface-300 dark:text-surface-600 mb-2">folder_off</span>
      <p class="text-sm text-surface-500 dark:text-surface-400">No items to display</p>
    </div>
  </div>
</template>

<style scoped>
.drive-compact-view {
  @apply w-full;
}
</style>
