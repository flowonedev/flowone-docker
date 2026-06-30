<script setup>
import { computed, ref } from 'vue'
import { useDriveStore } from '@/stores/drive'
import DriveFolderTree from '@/components/drive/DriveFolderTree.vue'
import DriveQuotaCard from '@/components/drive/DriveQuotaCard.vue'

const props = defineProps({
  open: { type: Boolean, default: false },
  isMobile: { type: Boolean, default: false },
  // Folder tree state
  folderTree: { type: Array, required: true },
  treeExpanded: { type: Object, required: true },
  dragOverFolder: { type: [Number, String, null], default: null },
  dragOverPosition: { type: String, default: '' },
  draggingFolder: { type: Object, default: null },
  getFolderColor: { type: Function, required: true },
  // Shared-with-me extras (optional)
  groupSharedByOwner: { type: Boolean, default: false },
  showAllSharedFolders: { type: Boolean, default: false },
  sharedFoldersGroupedByOwner: { type: Object, default: () => ({}) },
})

const emit = defineEmits([
  'close',
  // primary nav
  'navigate-root',
  'navigate-shared',
  'navigate-recent',
  'navigate-starred',
  'navigate-trash',
  // tree events (re-emitted from DriveFolderTree)
  'tree-select',
  'tree-toggle',
  'tree-create-subfolder',
  'tree-context-menu',
  'tree-drag-start',
  'tree-drag-end',
  'tree-drag-over-folder',
  'tree-drag-leave-folder',
  'tree-drop-on-folder',
  'tree-touch-start',
  'tree-touch-move',
  'tree-touch-end',
  // root drop zone
  'root-drag-over',
  'root-drag-leave',
  'root-drop',
  // shared-with-me actions
  'open-shared-folder',
  'open-shared-file',
  'toggle-group-shared',
  'toggle-show-all-shared',
])

const drive = useDriveStore()

// Collapsible "Favorites" section (mock has a caret next to the heading)
const favoritesOpen = ref(true)

// Active section flags (computed off the store)
const isMyDriveActive = computed(() =>
  !drive.currentFolder &&
  !drive.isTrashView &&
  !drive.isSharedView &&
  !drive.isSharingAccessView &&
  !drive.isStarredView &&
  !drive.isRecentView
)

function navItemClass(isActive) {
  return [
    'w-full flex items-center gap-2.5 px-2.5 py-1.5 rounded-md text-left text-sm transition-colors',
    isActive
      ? 'bg-primary-50 dark:bg-primary-500/20 text-primary-700 dark:text-primary-300 font-medium'
      : 'hover:bg-surface-100 dark:hover:bg-surface-700 text-surface-700 dark:text-surface-300',
  ]
}
</script>

<template>
  <aside
    class="drive-sidebar w-64 border-r border-surface-200 dark:border-[rgb(var(--color-border))] bg-white dark:bg-[rgb(var(--color-surface))] flex flex-col overflow-hidden"
    :class="{ 'open': open }"
  >
    <!-- Primary nav + tree (scrolls). The "+ New" menu lives in the
         file-manager toolbar (DriveSubHeader) now. -->
    <div class="flex-1 overflow-y-auto px-2 pb-2 pt-2">
      <!-- Favorites section (primary nav, collapsible per mock) -->
      <button
        type="button"
        @click="favoritesOpen = !favoritesOpen"
        class="w-full flex items-center gap-1 px-1 mb-1 text-xs font-medium text-surface-500 dark:text-surface-400 hover:text-surface-700 dark:hover:text-surface-200 transition-colors"
      >
        <span class="material-symbols-rounded text-sm">{{ favoritesOpen ? 'keyboard_arrow_down' : 'chevron_right' }}</span>
        {{ $t('driveView.favorites') }}
      </button>
      <nav v-if="favoritesOpen" class="space-y-0.5">
        <button
          @click="emit('navigate-root')"
          @dragover.prevent="emit('root-drag-over', $event)"
          @dragleave="emit('root-drag-leave')"
          @drop="emit('root-drop', $event)"
          :class="navItemClass(isMyDriveActive)"
        >
          <span class="material-symbols-rounded text-lg">folder</span>
          {{ $t('driveView.myDrive') }}
        </button>

        <button
          @click="emit('navigate-shared')"
          :class="navItemClass(drive.isSharedView)"
        >
          <span class="material-symbols-rounded text-lg">group</span>
          {{ $t('driveView.sharedWithMe') }}
        </button>

        <button
          @click="emit('navigate-recent')"
          :class="navItemClass(drive.isRecentView)"
        >
          <span class="material-symbols-rounded text-lg">schedule</span>
          {{ $t('driveView.recent') }}
        </button>

        <button
          @click="emit('navigate-starred')"
          :class="navItemClass(drive.isStarredView)"
        >
          <span class="material-symbols-rounded text-lg">star</span>
          {{ $t('driveView.starred') }}
        </button>

        <button
          @click="emit('navigate-trash')"
          :class="navItemClass(drive.isTrashView)"
        >
          <span class="material-symbols-rounded text-lg">delete</span>
          {{ $t('driveTrashView.trash') }}
        </button>
      </nav>

      <!-- Folders section -->
      <div class="mt-5">
        <div class="px-1.5 mb-1 text-xs font-medium text-surface-500 dark:text-surface-400">
          {{ $t('driveView.folders') }}
        </div>

        <DriveFolderTree
          :folders="folderTree"
          :current-folder-id="drive.currentFolder?.id"
          :expanded="treeExpanded"
          :drag-over-folder="dragOverFolder"
          :drag-over-position="dragOverPosition"
          :dragging-folder="draggingFolder"
          :get-folder-color="getFolderColor"
          @select="(id) => emit('tree-select', id)"
          @toggle="(id) => emit('tree-toggle', id)"
          @create-subfolder="(id) => emit('tree-create-subfolder', id)"
          @context-menu="(e, f) => emit('tree-context-menu', e, f)"
          @drag-start="(e, f) => emit('tree-drag-start', e, f)"
          @drag-end="emit('tree-drag-end')"
          @drag-over-folder="(e, f, d) => emit('tree-drag-over-folder', e, f, d)"
          @drag-leave-folder="emit('tree-drag-leave-folder')"
          @drop-on-folder="(e, f) => emit('tree-drop-on-folder', e, f)"
          @touch-start="(e, f) => emit('tree-touch-start', e, f)"
          @touch-move="(e) => emit('tree-touch-move', e)"
          @touch-end="(e) => emit('tree-touch-end', e)"
        />
      </div>

      <!-- Shared with me (existing chip-style listing kept from the old sidebar) -->
      <div
        v-if="drive.sharedWithMe.length > 0"
        class="mt-5 pt-4 border-t border-surface-200 dark:border-surface-700"
      >
        <div class="px-3 py-1 text-xs font-medium text-amber-600 dark:text-amber-400 uppercase tracking-wider flex items-center justify-between">
          <span class="flex items-center gap-1">
            <span class="material-symbols-rounded text-sm">folder_shared</span>
            {{ $t('driveView.sharedWithMe') }}
          </span>
          <button
            @click="emit('toggle-group-shared')"
            class="p-0.5 rounded hover:bg-amber-500/20 transition-colors"
            :title="groupSharedByOwner ? $t('driveView.showAsList') : $t('driveView.groupByOwner')"
          >
            <span class="material-symbols-rounded text-sm">{{ groupSharedByOwner ? 'format_list_bulleted' : 'group' }}</span>
          </button>
        </div>

        <!-- Grouped by owner view -->
        <div v-if="groupSharedByOwner" class="mt-1 space-y-2">
          <div v-for="(folders, ownerEmail) in sharedFoldersGroupedByOwner" :key="ownerEmail">
            <div class="px-3 py-1 text-xs text-surface-500 dark:text-surface-400 truncate flex items-center gap-1">
              <span class="material-symbols-rounded text-xs">person</span>
              {{ ownerEmail }}
            </div>
            <div class="space-y-0.5">
              <button
                v-for="sharedFolder in folders"
                :key="sharedFolder.id"
                @click="emit('open-shared-folder', sharedFolder)"
                :class="[
                  'w-full flex items-center gap-2 px-3 py-1.5 rounded-lg text-left text-sm transition-colors',
                  drive.isSharedView && drive.currentSharedFolder?.id === sharedFolder.id
                    ? 'bg-amber-50 dark:bg-amber-500/20 text-amber-700 dark:text-amber-300'
                    : 'hover:bg-surface-100 dark:hover:bg-surface-700 text-surface-700 dark:text-surface-300',
                ]"
              >
                <span class="material-symbols-rounded text-lg text-amber-500">folder_shared</span>
                <span class="truncate flex-1">{{ sharedFolder.name }}</span>
                <span
                  :class="[
                    'text-xs px-1.5 py-0.5 rounded',
                    sharedFolder.permission === 'editor'
                      ? 'bg-green-100 text-green-700 dark:bg-green-500/20 dark:text-green-400'
                      : 'bg-surface-200 dark:bg-surface-700 text-surface-500',
                  ]"
                >
                  {{ sharedFolder.permission === 'editor' ? $t('driveView.edit') : $t('driveView.view') }}
                </span>
              </button>
            </div>
          </div>
        </div>

        <!-- Flat list view -->
        <div v-else class="mt-1 space-y-0.5">
          <button
            v-for="sharedFolder in drive.sharedWithMe.slice(0, showAllSharedFolders ? undefined : 5)"
            :key="sharedFolder.id"
            @click="emit('open-shared-folder', sharedFolder)"
            :class="[
              'w-full flex items-center gap-2 px-3 py-2 rounded-lg text-left text-sm transition-colors',
              drive.isSharedView && drive.currentSharedFolder?.id === sharedFolder.id
                ? 'bg-amber-50 dark:bg-amber-500/20 text-amber-700 dark:text-amber-300'
                : 'hover:bg-surface-100 dark:hover:bg-surface-700 text-surface-700 dark:text-surface-300',
            ]"
          >
            <span class="material-symbols-rounded text-lg text-amber-500">folder_shared</span>
            <div class="flex-1 min-w-0">
              <div class="truncate">{{ sharedFolder.name }}</div>
              <div class="text-xs text-surface-500 dark:text-surface-400 truncate">{{ sharedFolder.owner_email }}</div>
            </div>
            <span
              :class="[
                'text-xs px-1.5 py-0.5 rounded flex-shrink-0',
                sharedFolder.permission === 'editor'
                  ? 'bg-green-100 text-green-700 dark:bg-green-500/20 dark:text-green-400'
                  : 'bg-surface-200 dark:bg-surface-700 text-surface-500',
              ]"
              :title="sharedFolder.permission === 'editor' ? $t('driveView.canEdit') : $t('driveView.viewOnly')"
            >
              {{ sharedFolder.permission === 'editor' ? $t('driveView.edit') : $t('driveView.view') }}
            </span>
          </button>
          <button
            v-if="drive.sharedWithMe.length > 5 && !showAllSharedFolders"
            @click="emit('toggle-show-all-shared')"
            class="w-full flex items-center gap-2 px-3 py-2 rounded-lg text-left text-sm text-primary-500 hover:bg-primary-50 dark:hover:bg-primary-500/10 transition-colors"
          >
            <span class="material-symbols-rounded text-lg">expand_more</span>
            {{ $t('driveView.showAllCount', { count: drive.sharedWithMe.length }) }}
          </button>
          <button
            v-if="showAllSharedFolders && drive.sharedWithMe.length > 5"
            @click="emit('toggle-show-all-shared')"
            class="w-full flex items-center gap-2 px-3 py-2 rounded-lg text-left text-sm text-surface-500 hover:bg-surface-100 dark:hover:bg-surface-700 transition-colors"
          >
            <span class="material-symbols-rounded text-lg">expand_less</span>
            {{ $t('driveView.showLess') }}
          </button>
        </div>
      </div>

      <!-- Files shared directly with me (person/group file shares) -->
      <div
        v-if="drive.sharedFilesWithMe.length > 0"
        class="mt-5 pt-4 border-t border-surface-200 dark:border-surface-700"
      >
        <div class="px-3 py-1 text-xs font-medium text-amber-600 dark:text-amber-400 uppercase tracking-wider flex items-center gap-1">
          <span class="material-symbols-rounded text-sm">file_open</span>
          {{ $t('driveView.sharedFiles') }}
        </div>
        <div class="mt-1 space-y-0.5">
          <button
            v-for="sharedFile in drive.sharedFilesWithMe"
            :key="sharedFile.id"
            @click="emit('open-shared-file', sharedFile)"
            class="w-full flex items-center gap-2 px-3 py-2 rounded-lg text-left text-sm transition-colors hover:bg-surface-100 dark:hover:bg-surface-700 text-surface-700 dark:text-surface-300"
          >
            <span class="material-symbols-rounded text-lg text-amber-500">draft</span>
            <div class="flex-1 min-w-0">
              <div class="truncate">{{ sharedFile.original_name }}</div>
              <div class="text-xs text-surface-500 dark:text-surface-400 truncate">{{ sharedFile.user_email }}</div>
            </div>
            <span
              :class="[
                'text-xs px-1.5 py-0.5 rounded flex-shrink-0',
                sharedFile.permission === 'editor'
                  ? 'bg-green-100 text-green-700 dark:bg-green-500/20 dark:text-green-400'
                  : 'bg-surface-200 dark:bg-surface-700 text-surface-500',
              ]"
              :title="sharedFile.permission === 'editor' ? $t('driveView.canEdit') : $t('driveView.viewOnly')"
            >
              {{ sharedFile.permission === 'editor' ? $t('driveView.edit') : $t('driveView.view') }}
            </span>
          </button>
        </div>
      </div>
    </div>

    <!-- Storage card pinned to bottom (replaces former content-pane quota bar) -->
    <DriveQuotaCard class="flex-shrink-0" />
  </aside>
</template>
