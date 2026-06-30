<script setup>
import { ref, computed, watch, onMounted, onBeforeUnmount } from 'vue'
import { useDriveStore } from '@/stores/drive'
import { useI18n } from 'vue-i18n'
import DriveNewMenu from './DriveNewMenu.vue'
import DriveGlobalSearch from './DriveGlobalSearch.vue'

const props = defineProps({
  // Optional pass-through state
  uploading: { type: Boolean, default: false },
  uploadProgress: { type: Number, default: 0 },
  hideUpload: { type: Boolean, default: false }, // hide upload (e.g. in read-only shared view)
  officeEnabled: { type: Boolean, default: false },
  searchQuery: { type: String, default: '' },
  activeFilterCount: { type: Number, default: 0 },
})

const emit = defineEmits([
  'upload',
  'create-archive',
  'download-all',
  'download-current-folder',
  'download-selected',
  'download-desktop-app',
  'open-sharing-access',
  // search (lives in the explorer bar now)
  'update:search-query',
  'toggle-filters',
  'clear-search',
  // create actions (moved out of the sidebar into the toolbar)
  'new-folder',
  'new-office',
  // selection-aware toolbar actions
  'share-selection',
  'rename-selection',
  'properties-selection',
  'delete-selection',
  'download-selection',
  'move-selection',
  'copy-selection',
])

const drive = useDriveStore()
const { t } = useI18n()

// ---------------------------------------------------------------------------
// Breadcrumb path (rendered inside the explorer path bar)
// ---------------------------------------------------------------------------
const breadcrumbSegments = computed(() => {
  const segments = []

  if (drive.currentSection === 'trash') {
    segments.push({ label: t('driveTrashView.trash'), onClick: null, isLeaf: true, icon: 'delete' })
    return segments
  }
  if (drive.currentSection === 'sharing-access') {
    segments.push({ label: t('driveView.sharingAccess'), onClick: null, isLeaf: true, icon: 'admin_panel_settings' })
    return segments
  }
  if (drive.currentSection === 'starred') {
    segments.push({ label: t('driveView.starred'), onClick: null, isLeaf: true, icon: 'star' })
    return segments
  }
  if (drive.currentSection === 'recent') {
    segments.push({ label: t('driveView.recent'), onClick: null, isLeaf: true, icon: 'schedule' })
    return segments
  }
  if (drive.currentSection === 'shared') {
    const sharedPath = drive.sharedFolderPath || []
    const hasSharedSub = sharedPath.length > 0 || !!drive.currentSharedFolder
    segments.push({
      label: t('driveView.sharedWithMe'),
      onClick: hasSharedSub ? () => drive.exitSharedView() : null,
      isLeaf: !hasSharedSub,
      icon: 'folder_shared',
    })
    if (sharedPath.length > 0) {
      sharedPath.forEach((f, i) => {
        segments.push({
          label: f.name,
          onClick: null, // navigation within shared not exposed here
          isLeaf: i === sharedPath.length - 1,
        })
      })
    } else if (drive.currentSharedFolder) {
      segments.push({ label: drive.currentSharedFolder.name, onClick: null, isLeaf: true })
    }
    return segments
  }

  // Default: My Drive + folder path
  const hasSubPath = (drive.path?.length || 0) > 0
  segments.push({
    label: t('driveView.myDrive'),
    onClick: hasSubPath ? () => drive.navigateToRoot() : null,
    isLeaf: !hasSubPath,
    icon: 'hard_drive',
  })
  ;(drive.path || []).forEach((folder, i, arr) => {
    segments.push({
      label: folder.name,
      onClick: i < arr.length - 1 ? () => drive.navigateToFolder(folder.id) : null,
      isLeaf: i === arr.length - 1,
    })
  })
  return segments
})

// ---------------------------------------------------------------------------
// Explorer navigation: back / forward (local history) + up (parent folder)
// ---------------------------------------------------------------------------
const SECTION_VIEWS = ['trash', 'sharing-access', 'starred', 'recent', 'shared']

const histStack = ref([])
const histIndex = ref(-1)
let navigatingViaHistory = false

const currentLocation = computed(() => {
  const section = SECTION_VIEWS.includes(drive.currentSection) ? drive.currentSection : 'my-drive'
  const folderId = section === 'my-drive' ? (drive.currentFolderId || null) : null
  return JSON.stringify({ section, folderId })
})

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

function navigateToLocation(serialized) {
  const { section, folderId } = JSON.parse(serialized)
  navigatingViaHistory = true
  if (section === 'my-drive') {
    drive.exitSharingAccessView()
    if (drive.isSharedView) drive.exitSharedView()
    if (folderId) drive.navigateToFolder(folderId)
    else drive.navigateToRoot()
  } else if (section === 'shared') {
    drive.fetchSharedWithMe()
  } else if (section === 'recent') {
    drive.enterRecentView()
  } else if (section === 'starred') {
    drive.enterStarredView()
  } else if (section === 'trash') {
    drive.enterTrashView()
  } else if (section === 'sharing-access') {
    drive.enterSharingAccessView()
  }
}

function goBack() {
  if (!canGoBack.value) return
  histIndex.value -= 1
  navigateToLocation(histStack.value[histIndex.value])
}

function goForward() {
  if (!canGoForward.value) return
  histIndex.value += 1
  navigateToLocation(histStack.value[histIndex.value])
}

const canGoUp = computed(() =>
  !SECTION_VIEWS.includes(drive.currentSection) && !!drive.currentFolderId
)

function goUp() {
  if (!canGoUp.value) return
  const path = drive.path || []
  const parent = path.length > 1 ? path[path.length - 2] : null
  if (parent) drive.navigateToFolder(parent.id)
  else drive.navigateToRoot()
}

// ---------------------------------------------------------------------------
// Selection-aware toolbar actions
// ---------------------------------------------------------------------------
const selectionCount = computed(() => drive.selectedFiles.size + drive.selectedFolders.size)
const hasSelection = computed(() => selectionCount.value > 0)

// Resolve the single selected item (file or folder) for share / rename / info
const singleSelection = computed(() => {
  if (selectionCount.value !== 1) return null
  if (drive.selectedFiles.size === 1) {
    const id = [...drive.selectedFiles][0]
    const item = (drive.files || []).find(f => f.id === id)
    return item ? { item, type: 'file' } : null
  }
  const id = [...drive.selectedFolders][0]
  const item = (drive.folders || []).find(f => f.id === id)
  return item ? { item, type: 'folder' } : null
})

function emitSingle(eventName) {
  if (singleSelection.value) emit(eventName, singleSelection.value)
}

// Flat file-manager toolbar buttons
function toolbarBtnClass(enabled) {
  return [
    'h-8 px-2.5 inline-flex items-center gap-1.5 rounded-md text-[13px] transition-colors flex-shrink-0',
    enabled
      ? 'text-surface-600 dark:text-surface-300 hover:bg-surface-100 dark:hover:bg-surface-800 hover:text-surface-900 dark:hover:text-surface-100'
      : 'text-surface-300 dark:text-surface-600 cursor-default',
  ]
}

// Bordered buttons (New / Upload), matching the file-manager mock
const outlineBtnClass = 'h-8 px-3 inline-flex items-center gap-1.5 rounded-md text-[13px] font-medium border border-surface-200 dark:border-surface-700 bg-white dark:bg-surface-800/60 text-surface-700 dark:text-surface-200 hover:bg-surface-50 dark:hover:bg-surface-700/60 transition-colors flex-shrink-0 disabled:opacity-50 disabled:cursor-not-allowed'

function navBtnClass(enabled) {
  return [
    'h-8 w-8 inline-flex items-center justify-center rounded-md transition-colors flex-shrink-0',
    enabled
      ? 'text-surface-600 dark:text-surface-300 hover:bg-surface-100 dark:hover:bg-surface-800'
      : 'text-surface-300 dark:text-surface-600 cursor-default',
  ]
}

// View toggle buttons (plain squares, active gets a primary-tinted box per mock)
function viewToggleClass(mode) {
  return [
    'h-8 w-8 inline-flex items-center justify-center rounded-md transition-colors flex-shrink-0',
    drive.viewMode === mode
      ? 'bg-primary-50 dark:bg-primary-500/20 text-primary-600 dark:text-primary-300 ring-1 ring-inset ring-primary-200 dark:ring-primary-500/30'
      : 'text-surface-400 dark:text-surface-500 hover:text-surface-700 dark:hover:text-surface-300 hover:bg-surface-100 dark:hover:bg-surface-800',
  ]
}

// ---------------------------------------------------------------------------
// Dropdown menus: kebab (global), upload, more (selection)
// ---------------------------------------------------------------------------
const openMenu = ref(null) // 'kebab' | 'upload' | 'more' | null
const kebabRef = ref(null)
const uploadRef = ref(null)
const moreRef = ref(null)

function toggleMenu(name) {
  openMenu.value = openMenu.value === name ? null : name
}

// Mobile-only: search is hidden by default and revealed via a toggle in Row 1.
const mobileSearchOpen = ref(false)
function toggleMobileSearch() {
  mobileSearchOpen.value = !mobileSearchOpen.value
}

function closeMenus() {
  openMenu.value = null
}

function emitAndClose(name) {
  emit(name)
  closeMenus()
}

function onDocClick(e) {
  if (!openMenu.value) return
  const refs = { kebab: kebabRef, upload: uploadRef, more: moreRef }
  const host = refs[openMenu.value]?.value
  if (host && !host.contains(e.target)) closeMenus()
}

function onKey(e) { if (e.key === 'Escape') closeMenus() }

onMounted(() => {
  document.addEventListener('mousedown', onDocClick)
  document.addEventListener('keydown', onKey)
})

onBeforeUnmount(() => {
  document.removeEventListener('mousedown', onDocClick)
  document.removeEventListener('keydown', onKey)
})

// Whether upload / create actions should appear:
//  - Hidden in trash / sharing-access / starred / recent (read-only-ish)
//  - Hidden when the parent says so (e.g. shared folder without edit perm)
const showUpload = computed(() => {
  if (props.hideUpload) return false
  if (['trash', 'sharing-access', 'starred', 'recent'].includes(drive.currentSection)) return false
  return true
})

const showSelectionDownload = computed(() => hasSelection.value)
const showCurrentFolderDownload = computed(() => !!drive.currentFolderId)

const menuItemClass = 'w-full px-3 py-2.5 text-left flex items-center gap-3 hover:bg-surface-100 dark:hover:bg-surface-700 text-sm text-surface-700 dark:text-surface-200'
</script>

<template>
  <!-- relative z-20: the content area below is a positioned (relative) sibling
       that paints AFTER this header in the DOM. On iOS/WKWebView its box can
       overlap the toolbar's bottom row, swallowing taps on New/Upload/view
       toggles ("something is over the buttons"). Lifting the whole sub-header
       into its own stacking layer keeps every toolbar button clickable. -->
  <header class="drive-subheader relative z-20 bg-white dark:bg-[rgb(var(--color-surface))]">
    <!-- ============ Row 1: explorer bar (back / forward / up + path + search + kebab) ============ -->
    <div class="flex items-center gap-2 px-2.5 md:px-3 py-1.5 border-b border-surface-200 dark:border-[rgb(var(--color-border))]">
      <!-- History navigation -->
      <div class="flex items-center gap-0.5 flex-shrink-0">
        <button type="button" :disabled="!canGoBack" @click="goBack" :class="navBtnClass(canGoBack)" :title="$t('driveView.back')">
          <span class="material-symbols-rounded text-xl">arrow_back</span>
        </button>
        <button type="button" :disabled="!canGoForward" @click="goForward" :class="navBtnClass(canGoForward)" :title="$t('driveView.forward')">
          <span class="material-symbols-rounded text-xl">arrow_forward</span>
        </button>
        <button type="button" :disabled="!canGoUp" @click="goUp" :class="navBtnClass(canGoUp)" :title="$t('driveView.upOneLevel')">
          <span class="material-symbols-rounded text-xl">arrow_upward</span>
        </button>
      </div>

      <!-- Path bar (leading location icon + clickable text breadcrumb, like a file manager) -->
      <nav
        class="flex-1 min-w-0 h-9 flex items-center gap-0.5 px-2 rounded-lg border border-surface-200 dark:border-surface-700 bg-white dark:bg-surface-800/40 overflow-hidden"
        aria-label="Breadcrumb"
      >
        <span class="material-symbols-rounded text-[18px] text-surface-500 dark:text-surface-400 flex-shrink-0 pl-0.5">
          {{ breadcrumbSegments[0]?.icon || 'hard_drive' }}
        </span>
        <template v-for="(seg, i) in breadcrumbSegments" :key="i">
          <span class="material-symbols-rounded text-surface-300 dark:text-surface-600 text-base flex-shrink-0">chevron_right</span>
          <button
            v-if="seg.onClick"
            type="button"
            @click="seg.onClick"
            class="px-1.5 py-0.5 rounded-md text-sm font-medium text-surface-600 dark:text-surface-300 hover:bg-surface-100 dark:hover:bg-surface-700 hover:text-surface-900 dark:hover:text-surface-100 transition-colors min-w-0 truncate"
          >{{ seg.label }}</button>
          <span
            v-else
            class="px-1.5 py-0.5 text-sm font-medium text-surface-900 dark:text-surface-100 min-w-0 truncate"
          >{{ seg.label }}</span>
        </template>
      </nav>

      <!-- Search (explorer-style, right of the path bar) -->
      <DriveGlobalSearch
        :search-query="searchQuery"
        :active-filter-count="activeFilterCount"
        class="hidden md:block w-72 flex-shrink-0"
        @update:search-query="emit('update:search-query', $event)"
        @toggle-filters="emit('toggle-filters', $event)"
        @clear-search="emit('clear-search')"
      />

      <!-- Mobile-only search toggle (reveals the collapsible search row below) -->
      <button
        type="button"
        class="md:hidden flex-shrink-0 relative"
        :class="navBtnClass(true)"
        @click="toggleMobileSearch"
        :title="$t('driveView.searchInDrive')"
      >
        <span class="material-symbols-rounded text-xl">{{ mobileSearchOpen ? 'search_off' : 'search' }}</span>
        <span
          v-if="activeFilterCount > 0"
          class="absolute -top-0.5 -right-0.5 inline-flex items-center justify-center w-4 h-4 text-[10px] font-bold rounded-full bg-primary-500 text-white"
        >{{ activeFilterCount }}</span>
      </button>

      <!-- Global kebab menu (downloads / archive / desktop app) -->
      <div ref="kebabRef" class="relative flex-shrink-0">
        <button type="button" @click="toggleMenu('kebab')" :class="navBtnClass(true)" :title="$t('driveView.more')">
          <span class="material-symbols-rounded text-xl">more_vert</span>
        </button>
        <Transition
          enter-active-class="transition ease-out duration-150"
          leave-active-class="transition ease-in duration-100"
          enter-from-class="opacity-0 scale-95"
          leave-to-class="opacity-0 scale-95"
        >
          <div
            v-if="openMenu === 'kebab'"
            class="absolute right-0 top-full mt-1 w-64 z-30 bg-white dark:bg-surface-800 border border-surface-200 dark:border-surface-700 rounded-xl shadow-lg overflow-hidden"
          >
            <button @click="emitAndClose('download-all')" :class="menuItemClass">
              <span class="material-symbols-rounded text-lg text-surface-500">cloud_download</span>
              {{ $t('driveView.downloadAllMyDrive') }}
            </button>
            <button v-if="showCurrentFolderDownload" @click="emitAndClose('download-current-folder')" :class="menuItemClass">
              <span class="material-symbols-rounded text-lg text-surface-500">folder_zip</span>
              {{ $t('driveView.downloadThisFolder') }}
            </button>
            <button v-if="showSelectionDownload" @click="emitAndClose('download-selected')" :class="menuItemClass">
              <span class="material-symbols-rounded text-lg text-surface-500">file_download</span>
              {{ $t('driveView.downloadSelectedCount', { count: selectionCount }) }}
            </button>
            <div class="border-t border-surface-200 dark:border-surface-700 my-1"></div>
            <button @click="emitAndClose('create-archive')" class="w-full px-3 py-2.5 text-left flex items-start gap-3 hover:bg-surface-100 dark:hover:bg-surface-700 text-sm text-surface-700 dark:text-surface-200">
              <span class="material-symbols-rounded text-lg text-surface-500 flex-shrink-0">archive</span>
              <div class="min-w-0 flex flex-col items-start">
                <span>{{ drive.currentFolderId ? 'Archive This Folder' : 'Archive My Drive' }}</span>
                <span class="text-[11px] text-surface-400">{{ $t('driveView.savesToDownloadsSplitsAt') }}</span>
              </div>
            </button>
            <div class="border-t border-surface-200 dark:border-surface-700 my-1"></div>
            <button @click="emitAndClose('open-sharing-access')" :class="menuItemClass">
              <span class="material-symbols-rounded text-lg text-surface-500">admin_panel_settings</span>
              {{ $t('driveView.sharingAccess') }}
            </button>
            <div class="border-t border-surface-200 dark:border-surface-700 my-1"></div>
            <button @click="emitAndClose('download-desktop-app')" class="w-full px-3 py-2.5 text-left flex items-center gap-3 hover:bg-surface-100 dark:hover:bg-surface-700 text-sm text-primary-700 dark:text-primary-300">
              <span class="material-symbols-rounded text-lg">download_for_offline</span>
              {{ $t('driveView.downloadDesktopApp') }}
            </button>
          </div>
        </Transition>
      </div>
    </div>

    <!-- ============ Mobile search row (collapsible, md:hidden) ============ -->
    <Transition
      enter-active-class="transition ease-out duration-150"
      leave-active-class="transition ease-in duration-100"
      enter-from-class="opacity-0 -translate-y-1"
      leave-to-class="opacity-0 -translate-y-1"
    >
      <div
        v-if="mobileSearchOpen"
        class="md:hidden px-2.5 py-2 border-b border-surface-200 dark:border-[rgb(var(--color-border))]"
      >
        <DriveGlobalSearch
          :search-query="searchQuery"
          :active-filter-count="activeFilterCount"
          class="w-full"
          @update:search-query="emit('update:search-query', $event)"
          @toggle-filters="emit('toggle-filters', $event)"
          @clear-search="emit('clear-search')"
        />
      </div>
    </Transition>

    <!-- ============ Row 2: flat toolbar (New / Upload | selection actions | More | views) ============ -->
    <div class="flex items-center gap-1 px-2.5 md:px-3 py-2 border-b border-surface-200 dark:border-[rgb(var(--color-border))]">
      <!-- Create + upload -->
      <template v-if="showUpload">
        <DriveNewMenu
          compact
          :office-enabled="officeEnabled"
          @new-folder="emit('new-folder')"
          @new-office="(type) => emit('new-office', type)"
          @upload-files="emit('upload')"
          @upload-folder="emit('upload')"
        />

        <!-- Upload dropdown -->
        <div ref="uploadRef" class="relative flex-shrink-0">
          <button
            type="button"
            @click="toggleMenu('upload')"
            :disabled="uploading"
            :class="outlineBtnClass"
          >
            <span v-if="uploading" class="spinner w-4 h-4"></span>
            <span v-else class="material-symbols-rounded text-base">upload</span>
            <span class="hidden sm:inline">{{ uploading ? `${uploadProgress}%` : $t('driveView.upload') }}</span>
            <span class="material-symbols-rounded text-base opacity-70 -ml-1">arrow_drop_down</span>
          </button>
          <Transition
            enter-active-class="transition ease-out duration-150"
            leave-active-class="transition ease-in duration-100"
            enter-from-class="opacity-0 scale-95"
            leave-to-class="opacity-0 scale-95"
          >
            <div
              v-if="openMenu === 'upload'"
              class="absolute left-0 top-full mt-1 w-56 z-30 bg-white dark:bg-surface-800 border border-surface-200 dark:border-surface-700 rounded-xl shadow-lg overflow-hidden"
            >
              <button @click="emitAndClose('upload')" :class="menuItemClass">
                <span class="material-symbols-rounded text-lg text-surface-500">upload_file</span>
                {{ $t('driveView.uploadFiles') }}
              </button>
              <button @click="emitAndClose('upload')" :class="menuItemClass">
                <span class="material-symbols-rounded text-lg text-surface-500">drive_folder_upload</span>
                {{ $t('driveView.uploadFolder') }}
              </button>
            </div>
          </Transition>
        </div>

        <div class="h-4 w-px bg-surface-200 dark:bg-surface-700 mx-1.5 flex-shrink-0"></div>
      </template>

      <!-- Contextual selection actions (visible but dimmed without selection).
           Hidden on mobile: per-item actions are reached via long-press -> action sheet. -->
      <div class="hidden md:flex items-center gap-1">
        <button type="button" :disabled="!singleSelection" @click="emitSingle('share-selection')" :class="toolbarBtnClass(!!singleSelection)" :title="$t('driveView.share')">
          <span class="material-symbols-rounded text-base">share</span>
          <span class="hidden md:inline">{{ $t('driveView.share') }}</span>
        </button>
        <button type="button" :disabled="!hasSelection" @click="hasSelection && emit('download-selection')" :class="toolbarBtnClass(hasSelection)" :title="$t('driveView.download')">
          <span class="material-symbols-rounded text-base">download</span>
          <span class="hidden md:inline">{{ $t('driveView.download') }}</span>
        </button>
        <button type="button" :disabled="!hasSelection" @click="hasSelection && emit('delete-selection')" :class="toolbarBtnClass(hasSelection)" :title="$t('driveView.delete')">
          <span class="material-symbols-rounded text-base">delete</span>
          <span class="hidden md:inline">{{ $t('driveView.delete') }}</span>
        </button>
        <button type="button" :disabled="!singleSelection" @click="emitSingle('rename-selection')" :class="toolbarBtnClass(!!singleSelection)" :title="$t('driveView.rename')">
          <span class="material-symbols-rounded text-base">edit</span>
          <span class="hidden md:inline">{{ $t('driveView.rename') }}</span>
        </button>

        <!-- More (selection actions overflow: move / copy / properties) -->
        <div ref="moreRef" class="relative flex-shrink-0">
          <button type="button" :disabled="!hasSelection" @click="hasSelection && toggleMenu('more')" :class="toolbarBtnClass(hasSelection)" :title="$t('driveView.more')">
            <span class="material-symbols-rounded text-base">more_horiz</span>
            <span class="hidden md:inline">{{ $t('driveView.more') }}</span>
          </button>
          <Transition
            enter-active-class="transition ease-out duration-150"
            leave-active-class="transition ease-in duration-100"
            enter-from-class="opacity-0 scale-95"
            leave-to-class="opacity-0 scale-95"
          >
            <div
              v-if="openMenu === 'more'"
              class="absolute left-0 top-full mt-1 w-56 z-30 bg-white dark:bg-surface-800 border border-surface-200 dark:border-surface-700 rounded-xl shadow-lg overflow-hidden"
            >
              <button @click="emitAndClose('move-selection')" :class="menuItemClass">
                <span class="material-symbols-rounded text-lg text-surface-500">drive_file_move</span>
                {{ $t('driveView.moveTo') }}
              </button>
              <button @click="emitAndClose('copy-selection')" :class="menuItemClass">
                <span class="material-symbols-rounded text-lg text-surface-500">content_copy</span>
                {{ $t('driveView.copy') }}
              </button>
              <button v-if="singleSelection" @click="emitSingle('properties-selection'); closeMenus()" :class="menuItemClass">
                <span class="material-symbols-rounded text-lg text-surface-500">info</span>
                {{ $t('driveView.properties') }}
              </button>
            </div>
          </Transition>
        </div>
      </div>

      <div class="flex-1"></div>

      <!-- View mode toggles (plain squares, active highlighted per mock) -->
      <div class="inline-flex items-center gap-0.5 flex-shrink-0">
        <button type="button" @click="drive.setViewMode('compact')" :class="viewToggleClass('compact')" :title="$t('driveView.compactView') || 'Compact view'">
          <span class="material-symbols-rounded text-lg">view_headline</span>
        </button>
        <button type="button" @click="drive.setViewMode('list')" :class="viewToggleClass('list')" :title="$t('driveView.listView')">
          <span class="material-symbols-rounded text-lg">view_list</span>
        </button>
        <button type="button" @click="drive.setViewMode('grid')" :class="viewToggleClass('grid')" :title="$t('driveView.gridView')">
          <span class="material-symbols-rounded text-lg">grid_view</span>
        </button>
      </div>

      <!-- Trailing controls (e.g. the How-It-Works guide buttons from the view) -->
      <slot name="trailing"></slot>
    </div>
  </header>
</template>
