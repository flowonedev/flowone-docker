<script setup>
import { ref, onMounted, onUnmounted, computed, watch, defineAsyncComponent, nextTick } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import { useI18n } from 'vue-i18n'
import api from '@/services/api'
import { getToken } from '@/services/tokenStorage'
import { useDriveStore } from '@/stores/drive'
import { useMailSync, EventTypes } from '@/services/mailSyncSocket'
import { useShareModalStore } from '@/stores/shareModal'
import { useThemeStore } from '@/stores/theme'
import { useToastStore } from '@/stores/toast'
import { useAccountsStore } from '@/stores/accounts'
import { useAuthStore } from '@/stores/auth'
import AppHeader from '@/components/shared/AppHeader.vue'
import ConfirmModal from '@/components/shared/ConfirmModal.vue'
import MobileBottomNav from '@/components/MobileBottomNav.vue'
import DriveListView from '@/components/drive/DriveListView.vue'
import DriveCompactView from '@/components/drive/DriveCompactView.vue'
import DriveTrashView from '@/components/drive/DriveTrashView.vue'
import SharingAccessTab from '@/components/drive/SharingAccessTab.vue'
import DriveSidebar from '@/components/drive/DriveSidebar.vue'
import DriveSubHeader from '@/components/drive/DriveSubHeader.vue'
import DriveVersionsPanel from '@/components/drive/versions/DriveVersionsPanel.vue'
import FilePropertiesPanel from '@/components/drive/FilePropertiesPanel.vue'
import ActivityLogPanel from '@/components/drive/ActivityLogPanel.vue'
import ZipDebugPanel from '@/components/ZipDebugPanel.vue'
import { isDebugEnabled } from '@/utils/debug'
import { clampMenuToViewport } from '@/utils/menuPosition'
import { buildFolderPath, formatFolderPathLabel } from '@/utils/driveFolderPath'
import FeatureGuide from '@/components/shared/FeatureGuide.vue'
import HowItWorksButton from '@/components/shared/HowItWorksButton.vue'
import { featureGuides } from '@/data/featureGuides'
import StepGuide from '@/components/shared/StepGuide.vue'
import { driveGuide } from '@/data/stepGuides'
import {
  renderDocxToHtml,
  getExcelSheetNames,
  renderExcelSheetToHtml,
} from '@/composables/useFilePreviewRenderer'
import { useOfficeStatus } from '@/composables/useOfficeStatus'
import { marked } from 'marked'
import DOMPurify from 'dompurify'
import clientTimeTracker from '@/addons/time-tracker/services/clientTimeTracker'
// Collab editing imports - use async components to prevent bundling issues
import { useCollabStore } from '@collab/stores/collabStore'
import { useClientsStore } from '@/stores/clients'
import { officeApi } from '@/services/officeApiService'

// Lazy load heavy collab components to separate chunks
const CollabDocumentEditor = defineAsyncComponent(() => 
  import('@collab/components/CollabDocumentEditor.vue')
)
const CollabPresentationEditor = defineAsyncComponent(() => 
  import('@collab/components/CollabPresentationEditor.vue')
)
const CollabShareModal = defineAsyncComponent(() => 
  import('@collab/components/CollabShareModal.vue')
)
const CollabVersionHistoryPanel = defineAsyncComponent(() => 
  import('@collab/components/CollabVersionHistoryPanel.vue')
)

const accountsStore = useAccountsStore()
const clientsStore = useClientsStore()
const authStore = useAuthStore()
const router = useRouter()
const route = useRoute()
const drive = useDriveStore()
const shareModal = useShareModalStore()
const theme = useThemeStore()
const toast = useToastStore()
const collabStore = useCollabStore()
const { on: onMailSync } = useMailSync()
const { t, locale } = useI18n()
const localeTag = computed(() => (locale.value === 'hu' ? 'hu-HU' : 'en-US'))

// Helper: build full auth headers for fetch() calls (matching axios interceptor)
function getAuthHeaders() {
  const headers = {}
  const token = getToken('webmail_token')
  if (token) headers['Authorization'] = `Bearer ${token}`
  const sessionToken = getToken('webmail_session_token')
  if (sessionToken) headers['X-Session-Token'] = sessionToken
  const activeAccountId = getToken('webmail_active_account')
  if (activeAccountId && activeAccountId !== 'primary') headers['X-Account-Id'] = activeAccountId
  return headers
}

// Collab editor state
const showCollabEditor = ref(false)
const collabEditorMode = ref('document') // 'document' or 'presentation'
const collabDocumentId = ref(null)
const collabDocumentTitle = ref('')
const collabDriveFileId = ref(null) // Tracks if document is linked to a Drive file
// Folder ID to return to after closing the collab editor. Captured when opening a doc
// and restored from the URL query (?folder=...) on refresh so the user lands back in
// the same folder rather than at the Drive root.
const collabReturnFolderId = ref(null)
const openingPresentationEditor = ref(false)
const showNewDocumentModal = ref(false)
const newDocumentTitle = ref(t('driveView.untitledDocument'))
const newDocumentType = ref('document')
const showCollabShareModal = ref(false)
const showVersionHistory = ref(false)

const showFeatureGuide = ref(false)
const showStepGuide = ref(false)
const guideData = featureGuides.drive

// Format document date for display
function formatDocDate(dateStr) {
  if (!dateStr) return ''
  const date = new Date(dateStr)
  const now = new Date()
  const diff = now - date
  
  if (diff < 60000) return t('driveView.justNow')
  if (diff < 3600000) {
    const minutes = Math.floor(diff / 60000)
    return t('driveView.minutesAgo', minutes, { count: minutes })
  }
  if (diff < 86400000) {
    const hours = Math.floor(diff / 3600000)
    return t('driveView.hoursAgo', hours, { count: hours })
  }
  if (diff < 604800000) {
    const days = Math.floor(diff / 86400000)
    return t('driveView.daysAgo', days, { count: days })
  }
  
  return date.toLocaleDateString(localeTag.value)
}

// Refresh collab documents list (needed to track collab doc status)
async function refreshCollabDocuments() {
  try {
    await collabStore.fetchDocuments()
  } catch (e) {
    console.error('Failed to load documents:', e)
  }
}

// Open a collab document in the editor
function openCollabDocument(doc) {
  collabDocumentId.value = doc.uuid
  collabDocumentTitle.value = doc.title
  collabEditorMode.value = doc.type
  openingPresentationEditor.value = doc.type === 'presentation'
  showCollabEditor.value = true

  // Remember the folder we came from so closing returns there.
  collabReturnFolderId.value = drive.currentFolderId

  // Update URL to reflect the open document, preserving folder context.
  const routeName = doc.type === 'presentation' ? 'drive-presentation' : 'drive-document'
  const query = drive.currentFolderId ? { folder: String(drive.currentFolderId) } : {}
  router.push({ name: routeName, params: { uuid: doc.uuid }, query })
}

// Delete a collab document
async function deleteCollabDocument(doc) {
  if (!confirm(t('driveView.confirmDeleteDocument', { title: doc.title || t('driveView.untitled') }))) {
    return
  }
  try {
    await collabStore.deleteDocument(doc.uuid)
    toast.success(t('driveView.documentDeleted'))
  } catch (e) {
    toast.error(t('driveView.failedToDeleteDocument'))
  }
}

// Open collab share modal
function openCollabShareModal() {
  if (collabDocumentId.value) {
    showCollabShareModal.value = true
  }
}

// Open version history panel
function openVersionHistory() {
  if (collabDocumentId.value) {
    showVersionHistory.value = true
  }
}

// Handle version restored - need to reload document
function handleVersionRestored() {
  // Close version history panel
  showVersionHistory.value = false
  // The document will auto-sync via WebSocket
}

// Account dropdown state
const showAccountDropdown = ref(false)

// Computed for account display
const allAccounts = computed(() => {
  const primaryAccount = {
    id: 'primary',
    account_email: authStore.userEmail,
    display_name: authStore.displayName,
    is_primary: true,
    is_default: accountsStore.accounts.length === 0,
  }
  return [primaryAccount, ...accountsStore.accounts]
})

const currentAccount = computed(() => {
  if (!accountsStore.activeAccountId || accountsStore.activeAccountId === 'primary') {
    return allAccounts.value[0]
  }
  return allAccounts.value.find(a => a.id === parseInt(accountsStore.activeAccountId)) || allAccounts.value[0]
})

function getAccountInitials(account) {
  const name = account.display_name || account.account_email
  return name.substring(0, 2).toUpperCase()
}

// Accent color mapping (ID -> hex color) - matches theme store colors
const accentColorMap = {
  green: '#22c55e',
  red: '#ef4444',
  purple: '#a855f7',
  blue: '#3b82f6',
  gold: '#eab308',
  mono: '#404040',
  teal: '#14b8a6',
  orange: '#f97316',
  gradient: '#a855f7', // Use purple as fallback for gradient
}

// Reactive key to force re-render when account/theme changes
const avatarKey = ref(0)

// Watch for theme changes to update avatars
watch(() => theme.accentColor, () => {
  avatarKey.value++
})

watch(() => accountsStore.activeAccountId, () => {
  avatarKey.value++
})

// Track folder browsing for client time tracking
// Also sync URL when folder changes
watch(() => drive.currentFolderId, (folderId, oldFolderId) => {
  // Clear any active search/filter when moving to a different folder. The search
  // filters the *current* folder's contents, so a sticky query would otherwise
  // hide everything in the new folder ("No files match your search") until a
  // manual page refresh.
  if (oldFolderId !== undefined && folderId !== oldFolderId && (searchQuery.value || activeFilterCount.value > 0)) {
    clearSearch()
  }

  if (folderId) {
    // Track browsing of the folder
    clientTimeTracker.trackDriveBrowse(folderId, drive.currentFolder?.name)
  } else {
    // At root - stop tracking
    clientTimeTracker.stopTracking()
  }
  
  // Update URL to reflect current folder (skip if we just loaded from URL)
  // Only update if this is a navigation action (not initial load)
  // Skip if in special views (trash/shared/sharing) - they have their own URL handling
  if (oldFolderId !== undefined && !drive.isTrashView && !drive.isSharedView && !drive.isSharingAccessView) {
    updateDriveUrl()
  }
})

// Sync URL when entering/exiting trash view
watch(() => drive.isTrashView, (isTrash) => {
  updateDriveUrl()
})

// Sync URL when entering/exiting shared view
watch(() => drive.isSharedView, (isShared) => {
  updateDriveUrl()
})

// Sync URL when entering/exiting sharing access view
watch(() => drive.isSharingAccessView, () => {
  updateDriveUrl()
})

// Sync URL when entering/exiting starred / recent virtual views
watch(() => drive.isStarredView, () => {
  updateDriveUrl()
})
watch(() => drive.isRecentView, () => {
  updateDriveUrl()
})

// Update URL to reflect current drive state
function updateDriveUrl() {
  const query = {}
  
  if (drive.isTrashView) {
    query.view = 'trash'
  } else if (drive.isSharingAccessView) {
    query.view = 'sharing'
  } else if (drive.isStarredView) {
    query.view = 'starred'
  } else if (drive.isRecentView) {
    query.view = 'recent'
  } else if (drive.isSharedView && drive.currentSharedFolder) {
    query.view = 'shared'
    query.shared = drive.currentSharedFolder.id.toString()
  } else if (drive.currentFolderId) {
    query.folder = drive.currentFolderId.toString()
  }
  
  // Use replace to avoid creating history entries for every navigation
  router.replace({ query })
}

// Get the stored accent color for a specific account
function getAccountAccentColor(account) {
  // Access avatarKey to create reactive dependency
  const _ = avatarKey.value
  const accountId = account.id === 'primary' ? 'primary' : account.id
  const accentId = localStorage.getItem(`webmail_accent_${accountId}`) 
    || localStorage.getItem('webmail_accent') 
    || 'green'
  return accentColorMap[accentId] || accentColorMap.green
}

// Get inline style for account avatar background
function getAccountAvatarStyle(account) {
  const color = getAccountAccentColor(account)
  return { backgroundColor: color }
}

const showNewFolderModal = ref(false)
const newFolderName = ref('')
const creatingFolder = ref(false)
const newFolderParentId = ref(null) // For creating subfolders

// Mobile action sheet
const showMobileActions = ref(false)
const mobileActionItem = ref(null)
const mobileActionType = ref(null) // 'file' or 'folder'

function openMobileActions(item, type) {
  mobileActionItem.value = item
  mobileActionType.value = type
  showMobileActions.value = true
}

function closeMobileActions() {
  showMobileActions.value = false
  mobileActionItem.value = null
  mobileActionType.value = null
}

// Sidebar folder drag-and-drop
const draggingSidebarFolder = ref(null)
const dragOverSidebarFolder = ref(null)
const dragOverSidebarPosition = ref(null) // 'inside', 'before', 'after'

// Context menu for folder tree
const contextMenu = ref({ show: false, x: 0, y: 0, folder: null })
const folderMenuRef = ref(null)

// Context menu for trash
const trashContextMenu = ref({ show: false, x: 0, y: 0 })
const trashMenuRef = ref(null)

// Reposition a just-opened cursor menu so it never overflows the viewport
async function clampOpenedMenu(menuState, menuElRef) {
  await nextTick()
  const { x, y } = clampMenuToViewport(menuState.value.x, menuState.value.y, menuElRef.value)
  menuState.value.x = x
  menuState.value.y = y
}
const showEmptyTrashConfirm = ref(false)

// Long-press support for mobile context menu
const longPressTimer = ref(null)
const longPressTarget = ref(null)
const LONG_PRESS_DURATION = 500 // ms

function handleTouchStart(e, item, type) {
  // Store touch position for context menu
  const touch = e.touches[0]
  longPressTarget.value = { item, type, x: touch.clientX, y: touch.clientY }
  
  // Start long-press timer
  longPressTimer.value = setTimeout(() => {
    if (longPressTarget.value) {
      // Trigger haptic feedback if available
      if (navigator.vibrate) {
        navigator.vibrate(50)
      }
      // Show context menu at touch position
      showContentContextMenu(
        { preventDefault: () => {}, stopPropagation: () => {}, clientX: longPressTarget.value.x, clientY: longPressTarget.value.y },
        longPressTarget.value.item,
        longPressTarget.value.type
      )
      longPressTarget.value = null
    }
  }, LONG_PRESS_DURATION)
}

function handleTouchMove(e) {
  // Cancel long-press if user moves finger
  if (longPressTimer.value) {
    clearTimeout(longPressTimer.value)
    longPressTimer.value = null
  }
}

function handleTouchEnd() {
  // Cancel long-press timer
  if (longPressTimer.value) {
    clearTimeout(longPressTimer.value)
    longPressTimer.value = null
  }
  longPressTarget.value = null
}

// Long-press for sidebar folders
function handleSidebarTouchStart(e, folder) {
  const touch = e.touches[0]
  longPressTarget.value = { folder, x: touch.clientX, y: touch.clientY, isSidebar: true }
  
  longPressTimer.value = setTimeout(() => {
    if (longPressTarget.value && longPressTarget.value.isSidebar) {
      if (navigator.vibrate) {
        navigator.vibrate(50)
      }
      showFolderContextMenu(
        { preventDefault: () => {}, clientX: longPressTarget.value.x, clientY: longPressTarget.value.y },
        longPressTarget.value.folder
      )
      longPressTarget.value = null
    }
  }, LONG_PRESS_DURATION)
}

const showDeleteConfirm = ref(false)
const deleteTarget = ref(null)
const deleteConfirmText = ref('')
const isBulkDelete = ref(false) // Track if deleting multiple selected items
const deleteInProgress = ref(false) // Track if delete operation is running
const deleteProgress = ref({ current: 0, total: 0, currentItem: '' }) // Progress tracking

// Protected folder modal
const showProtectedModal = ref(false)
const protectedFolderInfo = ref({ name: '', reason: '', isSystem: false })

const showRenameModal = ref(false)
const renameTarget = ref(null)
const renameValue = ref('')

const fileInput = ref(null)
const dragOver = ref(false)
const dragOverFolder = ref(null)

// Download menu state
const showDownloadMenu = ref(false)
const downloadingZip = ref(false)
const creatingArchive = ref(false)
const downloadMenuRef = ref(null)

// Grid is now responsive via CSS classes (removed gridColumns slider)
const draggingFile = ref(null)
const draggingFiles = ref([]) // For multi-file drag
const dragGhost = ref(null) // Custom drag image element

// Preview state
const showPreview = ref(false)
const previewFile = ref(null)
const previewIndex = ref(0)
const thumbnailStripRef = ref(null)

// Mobile state
const isMobile = ref(false)
const sidebarOpen = ref(false)

// List view sorting
const sortField = ref('name')
const sortDirection = ref('asc')

// Client-folder mapping (from time tracker)
const folderClientMap = ref({})

// Tracking state (for tracking indicator)
const trackingInfo = ref({
  isTracking: false,
  clientName: null,
  fileName: null,
  elapsedSeconds: 0
})
const trackingTimer = ref(null)

// Load folder-client mappings (from time tracker AND direct client_id on folders)
async function loadFolderClientMap() {
  // Load time tracker mappings first
  await clientTimeTracker.loadMappings()
  folderClientMap.value = { ...clientTimeTracker.folderMapping }
  
  // Also check allFolders for folders with client_id set directly
  // (from attachment saves that detect client by email)
  if (drive.allFolders.length > 0 && clientsStore.clients.length > 0) {
    drive.allFolders.forEach(folder => {
      if (folder.client_id && !folderClientMap.value[String(folder.id)]) {
        const client = clientsStore.clients.find(c => c.id === folder.client_id)
        if (client) {
          folderClientMap.value[String(folder.id)] = {
            client_id: folder.client_id,
            client_name: client.name || client.email
          }
        }
      }
    })
  }
}

// Reload folder-client map when allFolders or clients change
watch([() => drive.allFolders, () => clientsStore.clients], () => {
  if (drive.allFolders.length > 0 && clientsStore.clients.length > 0) {
    loadFolderClientMap()
  }
}, { deep: true })

// Start tracking timer for UI display
function startTrackingTimer() {
  if (trackingTimer.value) {
    clearInterval(trackingTimer.value)
  }
  
  trackingInfo.value.elapsedSeconds = 0
  trackingTimer.value = setInterval(() => {
    if (clientTimeTracker.trackingStartTime) {
      trackingInfo.value.elapsedSeconds = Math.floor((Date.now() - clientTimeTracker.trackingStartTime) / 1000)
    }
  }, 1000)
}

// Stop tracking timer
function stopTrackingTimer() {
  if (trackingTimer.value) {
    clearInterval(trackingTimer.value)
    trackingTimer.value = null
  }
  trackingInfo.value.isTracking = false
  trackingInfo.value.elapsedSeconds = 0
}

// Format seconds to MM:SS
function formatTrackingTime(seconds) {
  const mins = Math.floor(seconds / 60)
  const secs = seconds % 60
  return `${mins.toString().padStart(2, '0')}:${secs.toString().padStart(2, '0')}`
}

// Get current client name for tracking
function getCurrentTrackingClientName() {
  if (!clientTimeTracker.currentClientId) return null
  
  const mapping = Object.values(folderClientMap.value).find(
    m => m.client_id === clientTimeTracker.currentClientId
  )
  return mapping?.client_name || `Client #${clientTimeTracker.currentClientId}`
}

// Get client for current folder (for breadcrumb display)
const currentFolderClient = computed(() => {
  if (!drive.currentFolderId) return null
  return folderClientMap.value[String(drive.currentFolderId)] || null
})

// Search state
const searchQuery = ref('')
const showSearchFilters = ref(false)
const searchFilters = ref({
  type: '', // '', 'image', 'video', 'audio', 'document', 'archive', 'other'
  sharing: '', // '', 'public', 'shared', 'private'
  dateRange: '', // '', 'today', 'week', 'month', 'year'
  sizeRange: '', // '', 'small', 'medium', 'large', 'oversized'
  clientId: '', // '', or client id
})

// Fetch all folders and clients when filter popup is opened (needed for client filter)
watch(showSearchFilters, async (isOpen) => {
  if (isOpen) {
    // Fetch folders if needed
    if (drive.allFolders.length === 0) {
      drive.fetchAllFolders()
    }
    // Fetch clients if needed
    if (clientsStore.clients.length === 0) {
      await clientsStore.fetchClients()
    }
  }
})

// Draggable filter popup
const filterPopupPos = ref({ x: null, y: null })
const isDraggingFilter = ref(false)
const dragOffset = ref({ x: 0, y: 0 })

function startDragFilter(e) {
  if (e.target.closest('input, select, button')) return
  isDraggingFilter.value = true
  const rect = e.currentTarget.parentElement.getBoundingClientRect()
  dragOffset.value = {
    x: e.clientX - rect.left,
    y: e.clientY - rect.top
  }
  document.addEventListener('mousemove', onDragFilter)
  document.addEventListener('mouseup', stopDragFilter)
}

function onDragFilter(e) {
  if (!isDraggingFilter.value) return
  filterPopupPos.value = {
    x: e.clientX - dragOffset.value.x,
    y: e.clientY - dragOffset.value.y
  }
}

function stopDragFilter() {
  isDraggingFilter.value = false
  document.removeEventListener('mousemove', onDragFilter)
  document.removeEventListener('mouseup', stopDragFilter)
}

function resetFilterPopupPos() {
  filterPopupPos.value = { x: null, y: null }
}

// Anchor the filter popup directly under the global search bar (in AppHeader).
// Called whenever the filter toggle is fired; if the popup is opening we compute
// its position from the search bar's bounding rect, otherwise we just close it.
function toggleSearchFiltersAnchored() {
  if (showSearchFilters.value) {
    showSearchFilters.value = false
    resetFilterPopupPos()
    return
  }
  showSearchFilters.value = true
  // Defer one frame so the input's layout is settled
  nextTick(() => {
    const el = document.getElementById('drive-global-search-bar')
    if (!el) {
      resetFilterPopupPos()
      return
    }
    const rect = el.getBoundingClientRect()
    // Popup max width ~420px; align left edge with the search bar's left edge
    // but clamp so it never overflows the viewport on the right.
    const popupWidth = Math.min(420, window.innerWidth * 0.9)
    const maxLeft = window.innerWidth - popupWidth - 8
    filterPopupPos.value = {
      x: Math.max(8, Math.min(rect.left, maxLeft)),
      y: rect.bottom + 8,
    }
  })
}

const fileTypeOptions = [
  { value: '', label: 'All Types' },
  { value: 'image', label: 'Images', icon: 'image' },
  { value: 'video', label: 'Videos', icon: 'movie' },
  { value: 'audio', label: 'Audio', icon: 'audio_file' },
  { value: 'document', label: 'Documents', icon: 'description' },
  { value: 'spreadsheet', label: 'Spreadsheets', icon: 'table_chart' },
  { value: 'pdf', label: 'PDFs', icon: 'picture_as_pdf' },
  { value: 'archive', label: 'Archives', icon: 'folder_zip' },
]

const sharingOptions = [
  { value: '', label: 'All' },
  { value: 'public', label: 'Public', icon: 'public' },
  { value: 'shared', label: 'Shared', icon: 'group' },
  { value: 'private', label: 'Private', icon: 'lock' },
]

const dateRangeOptions = [
  { value: '', label: 'Any time' },
  { value: 'today', label: 'Today' },
  { value: 'week', label: 'This week' },
  { value: 'month', label: 'This month' },
  { value: 'year', label: 'This year' },
]

const sizeRangeOptions = [
  { value: '', label: 'Any size' },
  { value: 'small', label: 'Small (< 1MB)' },
  { value: 'medium', label: 'Medium (1-10MB)' },
  { value: 'large', label: 'Large (10-100MB)' },
  { value: 'oversized', label: 'Very large (> 100MB)' },
]

// Active filter count
const activeFilterCount = computed(() => {
  let count = 0
  if (searchFilters.value.type) count++
  if (searchFilters.value.sharing) count++
  if (searchFilters.value.dateRange) count++
  if (searchFilters.value.sizeRange) count++
  if (searchFilters.value.clientId) count++
  return count
})

// Mime type -> sort order, matching DriveListView/DriveCompactView ordering
function getMimeSortOrder(mimeType) {
  if (mimeType === 'application/vnd.collab.document') return 0
  if (mimeType === 'application/vnd.collab.presentation') return 0
  if (mimeType?.startsWith('image/')) return 1
  if (mimeType?.startsWith('video/')) return 2
  if (mimeType?.startsWith('audio/')) return 3
  if (mimeType?.includes('pdf')) return 4
  if (mimeType?.includes('spreadsheet') || mimeType?.includes('sheet') || mimeType?.includes('excel') || mimeType?.includes('csv')) return 5
  if (mimeType?.includes('presentation') || mimeType?.includes('powerpoint')) return 6
  if (mimeType?.includes('word') || mimeType?.includes('msword') || mimeType?.includes('wordprocessing')) return 7
  if (mimeType?.includes('zip') || mimeType?.includes('compressed') || mimeType?.includes('archive') || mimeType?.includes('rar') || mimeType?.includes('7z')) return 8
  if (mimeType?.includes('javascript') || mimeType?.includes('json') || mimeType?.includes('xml') || mimeType?.includes('html') || mimeType?.includes('css') || mimeType?.includes('php')) return 9
  if (mimeType?.includes('text/')) return 10
  return 99
}

// Compare two drive items using the active sortField/sortDirection. Mirrors the
// comparator in DriveListView/DriveCompactView so shift-click range math (which
// indexes into filteredFolders+filteredFiles) matches the rendered row order.
// Folders-first is preserved by keeping folders and files in separate arrays.
function compareDriveItems(a, b, isFolder) {
  let aVal, bVal
  switch (sortField.value) {
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
      aVal = isFolder ? 0 : getMimeSortOrder(a.mime_type)
      bVal = isFolder ? 0 : getMimeSortOrder(b.mime_type)
      break
    case 'sharing': {
      const getSharingOrder = (item) => {
        if (item.share_token) return 2
        if (item.collaborator_count > 0) return 1
        return 0
      }
      aVal = getSharingOrder(a)
      bVal = getSharingOrder(b)
      break
    }
    default:
      return 0
  }
  if (sortDirection.value === 'asc') {
    return aVal > bVal ? 1 : aVal < bVal ? -1 : 0
  }
  return aVal < bVal ? 1 : aVal > bVal ? -1 : 0
}

// Filtered folders and files
const filteredFolders = computed(() => {
  // When a Drive-wide search is active, use the server-side results
  // (already filtered by name across all folders).
  let folders = drive.searchActive ? drive.searchFolders : drive.folders
  
  // Apply search query (local filter only when not using server-side search)
  if (!drive.searchActive && searchQuery.value.trim()) {
    const query = searchQuery.value.toLowerCase()
    folders = folders.filter(f => f.name.toLowerCase().includes(query))
  }
  
  // Apply sharing filter
  if (searchFilters.value.sharing) {
    folders = folders.filter(f => {
      if (searchFilters.value.sharing === 'public') return f.share_token
      if (searchFilters.value.sharing === 'shared') return f.collaborator_count > 0
      if (searchFilters.value.sharing === 'private') return !f.share_token && (!f.collaborator_count || f.collaborator_count === 0)
      return true
    })
  }
  
  // Apply date filter
  if (searchFilters.value.dateRange) {
    const now = new Date()
    const cutoff = new Date()
    switch (searchFilters.value.dateRange) {
      case 'today': cutoff.setHours(0, 0, 0, 0); break
      case 'week': cutoff.setDate(now.getDate() - 7); break
      case 'month': cutoff.setMonth(now.getMonth() - 1); break
      case 'year': cutoff.setFullYear(now.getFullYear() - 1); break
    }
    folders = folders.filter(f => new Date(f.updated_at || f.created_at) >= cutoff)
  }
  
  // Apply client filter - show folders linked to this client or ALL folders/files within them
  if (searchFilters.value.clientId) {
    const clientId = parseInt(searchFilters.value.clientId)
    // Get all folder IDs that belong to this client (directly or through parent hierarchy)
    const clientFolderIds = new Set()
    
    // Find directly linked folders
    drive.allFolders.forEach(f => {
      if (f.client_id === clientId) {
        clientFolderIds.add(f.id)
        // Also add all child folders (recursively)
        addChildFolders(f.id, clientFolderIds)
      }
    })
    
    folders = folders.filter(f => clientFolderIds.has(f.id))
  }
  
  // Sort to match what list/compact views render, so shift-click range math
  // operates on the same order as the displayed rows.
  return [...folders].sort((a, b) => compareDriveItems(a, b, true))
})

// Helper to recursively add child folder IDs
function addChildFolders(parentId, folderIds) {
  drive.allFolders.forEach(f => {
    if (f.parent_id === parentId && !folderIds.has(f.id)) {
      folderIds.add(f.id)
      addChildFolders(f.id, folderIds)
    }
  })
}

// Get collab documents for the current folder
const currentFolderCollabDocs = computed(() => {
  return collabStore.documents.filter(doc => {
    // Show docs with matching folder_id, or docs with null folder_id when in root
    if (drive.currentFolderId === null || drive.currentFolderId === 'null') {
      return !doc.folder_id
    }
    return doc.folder_id === drive.currentFolderId
  })
})

const filteredFiles = computed(() => {
  // When a Drive-wide search is active, use the server-side results
  // (already filtered by name across all folders).
  let files = drive.searchActive ? drive.searchFiles : drive.files
  
  // Add collab documents as virtual files (only when not searching)
  if (!drive.searchActive && !searchQuery.value.trim()) {
    const collabAsFiles = currentFolderCollabDocs.value.map(doc => ({
      id: `collab-${doc.uuid}`,
      uuid: doc.uuid,
      original_name: doc.title || 'Untitled',
      mime_type: doc.type === 'presentation' ? 'application/vnd.collab.presentation' : 'application/vnd.collab.document',
      size: 0,
      created_at: doc.created_at,
      updated_at: doc.updated_at,
      is_collab_document: true,
      collab_type: doc.type,
      owner_email: doc.owner_email,
    }))
    files = [...collabAsFiles, ...files]
  }
  
  // Apply search query (local filter only when not using server-side search)
  if (!drive.searchActive && searchQuery.value.trim()) {
    const query = searchQuery.value.toLowerCase()
    files = files.filter(f => f.original_name.toLowerCase().includes(query))
  }
  
  // Apply type filter
  if (searchFilters.value.type) {
    files = files.filter(f => {
      const mime = f.mime_type || ''
      switch (searchFilters.value.type) {
        case 'image': return mime.startsWith('image/')
        case 'video': return mime.startsWith('video/')
        case 'audio': return mime.startsWith('audio/')
        case 'document': return mime.includes('word') || mime.includes('document') || mime.includes('text/')
        case 'spreadsheet': return mime.includes('sheet') || mime.includes('excel') || mime.includes('csv')
        case 'pdf': return mime.includes('pdf')
        case 'archive': return mime.includes('zip') || mime.includes('rar') || mime.includes('archive') || mime.includes('compressed')
        default: return true
      }
    })
  }
  
  // Apply sharing filter
  if (searchFilters.value.sharing) {
    files = files.filter(f => {
      if (searchFilters.value.sharing === 'public') return f.share_token
      if (searchFilters.value.sharing === 'private') return !f.share_token
      return true // 'shared' doesn't apply to files directly
    })
  }
  
  // Apply date filter
  if (searchFilters.value.dateRange) {
    const now = new Date()
    const cutoff = new Date()
    switch (searchFilters.value.dateRange) {
      case 'today': cutoff.setHours(0, 0, 0, 0); break
      case 'week': cutoff.setDate(now.getDate() - 7); break
      case 'month': cutoff.setMonth(now.getMonth() - 1); break
      case 'year': cutoff.setFullYear(now.getFullYear() - 1); break
    }
    files = files.filter(f => new Date(f.updated_at || f.created_at) >= cutoff)
  }
  
  // Apply size filter
  if (searchFilters.value.sizeRange) {
    files = files.filter(f => {
      const size = f.size || 0
      switch (searchFilters.value.sizeRange) {
        case 'small': return size < 1024 * 1024
        case 'medium': return size >= 1024 * 1024 && size < 10 * 1024 * 1024
        case 'large': return size >= 10 * 1024 * 1024 && size < 100 * 1024 * 1024
        case 'oversized': return size >= 100 * 1024 * 1024
        default: return true
      }
    })
  }
  
  // Apply client filter - show files in folders linked to this client
  if (searchFilters.value.clientId) {
    const clientId = parseInt(searchFilters.value.clientId)
    // Get all folder IDs that belong to this client
    const clientFolderIds = new Set()
    
    drive.allFolders.forEach(f => {
      if (f.client_id === clientId) {
        clientFolderIds.add(f.id)
        addChildFolders(f.id, clientFolderIds)
      }
    })
    
    files = files.filter(f => f.folder_id && clientFolderIds.has(f.folder_id))
  }
  
  // Sort to match what list/compact views render, so shift-click range math
  // operates on the same order as the displayed rows.
  return [...files].sort((a, b) => compareDriveItems(a, b, false))
})

// Timer handle for the debounced Drive-wide search (declared here so clearSearch
// can cancel a pending run).
let searchDebounceTimer = null

function clearSearch() {
  // Cancel any pending debounced search so a late timer can't re-open results.
  if (searchDebounceTimer) {
    clearTimeout(searchDebounceTimer)
    searchDebounceTimer = null
  }
  searchQuery.value = ''
  searchFilters.value = { type: '', sharing: '', dateRange: '', sizeRange: '', clientId: '' }
  // Reset the store immediately (covers the case where searchQuery was already
  // empty, so the watcher above wouldn't fire).
  drive.clearDriveSearch()
}

function clearFilters() {
  searchFilters.value = { type: '', sharing: '', dateRange: '', sizeRange: '', clientId: '' }
}

// Drive-wide search: debounce the query, then hit the server. Clearing (or a
// query shorter than 2 chars) is applied immediately so navigating into a
// folder doesn't briefly keep showing stale search results.
watch(searchQuery, (q) => {
  const val = (q || '').trim()
  if (searchDebounceTimer) {
    clearTimeout(searchDebounceTimer)
    searchDebounceTimer = null
  }
  if (val.length < 2) {
    drive.clearDriveSearch()
    return
  }
  searchDebounceTimer = setTimeout(() => {
    drive.searchDrive(val)
  }, 250)
})

onUnmounted(() => {
  if (searchDebounceTimer) clearTimeout(searchDebounceTimer)
})

// Breadcrumb label for a search result's containing folder (grid view).
function searchFolderPathLabel(folderId) {
  return formatFolderPathLabel(buildFolderPath(drive.allFolders, folderId), t('driveView.pathRoot'))
}

// Open a search result's containing folder: clear the active search, then
// navigate (null = Drive root). Clearing searchQuery cascades to clearDriveSearch().
function openFolderFromSearch(folderId) {
  searchQuery.value = ''
  drive.navigateToFolder(folderId ?? null)
}

// File versions sidebar panel (list/pin/label/cleanup/compare lives in DriveVersionsPanel)
const showVersionsSidebar = ref(false)
const versionsFile = ref(null)

// Properties panel
const showPropertiesPanel = ref(false)
const propertiesItem = ref(null)
const propertiesType = ref('file') // 'file' or 'folder'

function openPropertiesPanel(item, type = 'file') {
  propertiesItem.value = item
  propertiesType.value = type
  showPropertiesPanel.value = true
}

function closePropertiesPanel() {
  showPropertiesPanel.value = false
  propertiesItem.value = null
}

// Activity log panel
const showActivityLogPanel = ref(false)

function openActivityLogPanel() {
  showActivityLogPanel.value = true
}

function closeActivityLogPanel() {
  showActivityLogPanel.value = false
}

function openVersionsSidebar(file) {
  versionsFile.value = file
  showVersionsSidebar.value = true
}

function closeVersionsSidebar() {
  showVersionsSidebar.value = false
  versionsFile.value = null
}

function handleSortChange(field, direction) {
  sortField.value = field
  sortDirection.value = direction
}

function checkMobile() {
  isMobile.value = window.innerWidth < 768
  if (!isMobile.value) {
    sidebarOpen.value = false
  }
}

function toggleSidebar() {
  sidebarOpen.value = !sidebarOpen.value
}

function closeSidebar() {
  sidebarOpen.value = false
}

// Get all previewable files for slider
const previewableFiles = computed(() => {
  return drive.files.filter(f => canPreview(f) || canUseOfficeViewer(f))
})

// Images get a "lightbox" sizing treatment: the preview card hugs the image
// and is allowed to grow up to nearly the full viewport, instead of being
// capped at the narrow document width (which left large empty margins).
const previewIsImage = computed(() => !!previewFile.value?.mime_type?.startsWith('image/'))

// Tree view state
const treeExpanded = ref({})
const allFolders = ref([])

// Fetch all folders for tree view
async function fetchAllFolders() {
  try {
    const response = await drive.fetchAllFolders()
    if (response) {
      allFolders.value = response
    }
  } catch (e) {
    console.error('Failed to fetch folders:', e)
  }
}

// Build folder tree structure
const folderTree = computed(() => {
  const roots = allFolders.value.filter(f => !f.parent_id)
  
  function buildTree(parentId) {
    return allFolders.value
      .filter(f => f.parent_id === parentId)
      .map(folder => ({
        ...folder,
        children: buildTree(folder.id)
      }))
  }
  
  return roots.map(folder => ({
    ...folder,
    children: buildTree(folder.id)
  }))
})

function toggleTreeNode(folderId) {
  treeExpanded.value[folderId] = !treeExpanded.value[folderId]
}

function selectFolder(folderId) {
  drive.navigateToFolder(folderId)
}

// Walk up the parent chain of a folder and expand every ancestor (plus the
// folder itself) in the sidebar tree, so the left tree "keeps opening" to
// follow the user's current location like a native file manager.
function expandTreeToFolder(folderId) {
  if (!folderId) return
  const byId = new Map(allFolders.value.map(f => [f.id, f]))
  let current = byId.get(folderId)
  if (!current) return
  const next = { ...treeExpanded.value }
  // Expand the current folder so its subfolders are revealed too.
  next[current.id] = true
  let guard = 0
  while (current?.parent_id && guard < 100) {
    next[current.parent_id] = true
    current = byId.get(current.parent_id)
    guard++
  }
  treeExpanded.value = next
}

// Keep the sidebar tree expanded to the active folder. Re-runs when the
// folder changes or when the folder list (re)loads (e.g. after a deep-link
// refresh where allFolders arrives after currentFolderId is already set).
watch(
  [() => drive.currentFolderId, allFolders],
  ([folderId]) => expandTreeToFolder(folderId),
  { immediate: true },
)

// Available folder colors
const folderColors = computed(() => ([
  { id: 'amber', name: t('driveView.folderColors.yellow'), class: 'text-amber-500', bg: 'bg-amber-500' },
  { id: 'blue', name: t('driveView.folderColors.blue'), class: 'text-blue-500', bg: 'bg-blue-500' },
  { id: 'green', name: t('driveView.folderColors.green'), class: 'text-green-500', bg: 'bg-green-500' },
  { id: 'purple', name: t('driveView.folderColors.purple'), class: 'text-purple-500', bg: 'bg-purple-500' },
  { id: 'pink', name: t('driveView.folderColors.pink'), class: 'text-pink-500', bg: 'bg-pink-500' },
  { id: 'red', name: t('driveView.folderColors.red'), class: 'text-red-500', bg: 'bg-red-500' },
  { id: 'orange', name: t('driveView.folderColors.orange'), class: 'text-orange-500', bg: 'bg-orange-500' },
  { id: 'teal', name: t('driveView.folderColors.teal'), class: 'text-teal-500', bg: 'bg-teal-500' },
  { id: 'slate', name: t('driveView.folderColors.gray'), class: 'text-slate-500', bg: 'bg-slate-500' },
]))

// Folder color mapping - uses custom color if set, otherwise primary accent
function getFolderColor(folder) {
  const folderObj = typeof folder === 'string' ? { name: folder } : folder
  
  // If custom color is set, use it
  if (folderObj?.color) {
    const colorConfig = folderColors.value.find(c => c.id === folderObj.color)
    if (colorConfig) return colorConfig.class
  }
  
  // Default = primary accent color for unified look
  return 'text-primary-500'
}

// Update folder color
async function changeFolderColor(folder, colorId) {
  const result = await drive.updateFolderColor(folder.id, colorId)
  if (result.success) {
    toast.success(t('driveView.folderColorUpdated'))
    // Update in allFolders too
    const idx = allFolders.value.findIndex(f => f.id === folder.id)
    if (idx !== -1) {
      allFolders.value[idx].color = colorId
    }
    // Force reactivity
    allFolders.value = [...allFolders.value]
  } else {
    toast.error(result.error || t('driveView.failedToUpdateColor'))
  }
}

// File type icons and colors
function getFileIconInfo(mimeType) {
  // Images
  if (mimeType?.startsWith('image/')) {
    return { icon: 'image', color: 'text-pink-500', bgColor: 'bg-pink-100 dark:bg-pink-500/20' }
  }
  // Videos
  if (mimeType?.startsWith('video/')) {
    return { icon: 'movie', color: 'text-purple-500', bgColor: 'bg-purple-100 dark:bg-purple-500/20' }
  }
  // Audio
  if (mimeType?.startsWith('audio/')) {
    return { icon: 'audio_file', color: 'text-violet-500', bgColor: 'bg-violet-100 dark:bg-violet-500/20' }
  }
  // PDF
  if (mimeType?.includes('pdf')) {
    return { icon: 'picture_as_pdf', color: 'text-red-500', bgColor: 'bg-red-100 dark:bg-red-500/20' }
  }
  // Excel/Spreadsheets - CHECK BEFORE WORD (both have "document" in mime)
  if (mimeType?.includes('spreadsheet') || mimeType?.includes('sheet') || mimeType?.includes('excel') || mimeType?.includes('csv')) {
    return { icon: 'table_chart', color: 'text-green-600', bgColor: 'bg-green-100 dark:bg-green-500/20' }
  }
  // PowerPoint/Presentations - CHECK BEFORE WORD
  if (mimeType?.includes('presentation') || mimeType?.includes('powerpoint')) {
    return { icon: 'slideshow', color: 'text-orange-500', bgColor: 'bg-orange-100 dark:bg-orange-500/20' }
  }
  // Word documents
  if (mimeType?.includes('word') || mimeType?.includes('msword') || mimeType?.includes('wordprocessing')) {
    return { icon: 'description', color: 'text-blue-600', bgColor: 'bg-blue-100 dark:bg-blue-500/20' }
  }
  // Archives
  if (mimeType?.includes('zip') || mimeType?.includes('compressed') || mimeType?.includes('archive') || mimeType?.includes('rar') || mimeType?.includes('7z')) {
    return { icon: 'folder_zip', color: 'text-amber-600', bgColor: 'bg-amber-100 dark:bg-amber-500/20' }
  }
  // Code files
  if (mimeType?.includes('javascript') || mimeType?.includes('json') || mimeType?.includes('xml') || mimeType?.includes('html') || mimeType?.includes('css') || mimeType?.includes('php')) {
    return { icon: 'code', color: 'text-cyan-500', bgColor: 'bg-cyan-100 dark:bg-cyan-500/20' }
  }
  // Text files
  if (mimeType?.includes('text/')) {
    return { icon: 'article', color: 'text-slate-500', bgColor: 'bg-slate-100 dark:bg-slate-500/20' }
  }
  // Default
  return { icon: 'draft', color: 'text-surface-500', bgColor: 'bg-surface-100 dark:bg-surface-500/20' }
}

// Keep simple getFileIcon for backward compatibility
function getFileIcon(mimeType) {
  return getFileIconInfo(mimeType).icon
}

function formatSize(bytes) {
  if (!bytes) return '0 B'
  if (bytes >= 1073741824) return (bytes / 1073741824).toFixed(2) + ' GB'
  if (bytes >= 1048576) return (bytes / 1048576).toFixed(2) + ' MB'
  if (bytes >= 1024) return (bytes / 1024).toFixed(2) + ' KB'
  return bytes + ' B'
}

function formatDate(dateStr) {
  const date = new Date(dateStr)
  return date.toLocaleDateString(localeTag.value) + ' ' + date.toLocaleTimeString(localeTag.value, { hour: '2-digit', minute: '2-digit' })
}

// Folder actions
async function createFolder() {
  if (!newFolderName.value.trim()) return
  
  creatingFolder.value = true
  const parentId = newFolderParentId.value ?? drive.currentFolder?.id ?? null
  let result

  if (drive.isSharedView) {
    if (!drive.canEditSharedFolder) {
      creatingFolder.value = false
      toast.error(t('driveView.youDoNotHavePermission'))
      return
    }

    result = await drive.createFolderInShared(parentId, newFolderName.value.trim())
  } else {
    result = await drive.createFolder(newFolderName.value.trim(), parentId)
  }

  creatingFolder.value = false
  
  if (result.success) {
    toast.success(t('driveView.folderCreated'))
    showNewFolderModal.value = false
    newFolderName.value = ''
    newFolderParentId.value = null
    fetchAllFolders() // Refresh tree
    if (drive.isSharedView && drive.currentSharedFolder) {
      if (drive.currentFolderId === drive.currentSharedFolder.id) {
        await drive.enterSharedFolder(drive.currentSharedFolder)
      } else if (drive.currentFolderId) {
        await drive.navigateSharedSubfolder(drive.currentFolderId)
      }
    } else if (drive.currentFolder?.id === parentId || (!drive.currentFolder && !parentId)) {
      drive.fetchContents(drive.currentFolder?.id ?? null)
    }
  } else {
    toast.error(result.error || t('driveView.failedToCreateFolder'))
  }
}

function openNewFolderModal(parentId = null) {
  if (drive.isSharedView && !drive.canEditSharedFolder) {
    toast.error(t('driveView.youDoNotHavePermission'))
    return
  }

  newFolderParentId.value = parentId
  newFolderName.value = ''
  showNewFolderModal.value = true
}

// Context menu for sidebar folders
function showFolderContextMenu(e, folder) {
  e.preventDefault()
  contextMenu.value = {
    show: true,
    x: e.clientX,
    y: e.clientY,
    folder: folder
  }
  clampOpenedMenu(contextMenu, folderMenuRef)
}

function closeContextMenu() {
  contextMenu.value.show = false
}

// Trash context menu
function showTrashContextMenu(e) {
  e.preventDefault()
  trashContextMenu.value = {
    show: true,
    x: e.clientX,
    y: e.clientY
  }
  clampOpenedMenu(trashContextMenu, trashMenuRef)
}

function closeTrashContextMenu() {
  trashContextMenu.value.show = false
}

async function emptyTrashFromSidebar() {
  closeTrashContextMenu()
  const result = await drive.emptyTrash()
  if (result.success) {
    toast.success(t('driveView.deletedResultcountItemsPermanently', { count: result.count }))
    showEmptyTrashConfirm.value = false
  } else {
    toast.error(t('driveTrashView.failedToEmptyTrash'))
  }
}

// Sidebar folder drag-and-drop
function onSidebarFolderDragStart(e, folder) {
  draggingSidebarFolder.value = folder
  e.dataTransfer.effectAllowed = 'move'
  e.dataTransfer.setData('text/plain', `folder:${folder.id}`)
}

function onSidebarFolderDragEnd() {
  draggingSidebarFolder.value = null
  dragOverSidebarFolder.value = null
  dragOverSidebarPosition.value = null
}

function onSidebarFolderDragOver(e, folder, level = 0) {
  e.preventDefault()
  e.stopPropagation()
  
  // Can't drop folder onto itself
  if (draggingSidebarFolder.value?.id === folder.id) return
  
  // Can't drop parent into child
  if (isDescendant(folder.id, draggingSidebarFolder.value?.id)) return
  
  dragOverSidebarFolder.value = folder.id
  
  // Determine position based on mouse Y
  const rect = e.currentTarget.getBoundingClientRect()
  const y = e.clientY - rect.top
  const height = rect.height
  
  if (y < height * 0.25) {
    dragOverSidebarPosition.value = 'before'
  } else if (y > height * 0.75) {
    dragOverSidebarPosition.value = 'after'
  } else {
    dragOverSidebarPosition.value = 'inside' // Drop as child
  }
}

function onSidebarRootDragOver(e) {
  e.preventDefault()
  if (!draggingSidebarFolder.value) return
  dragOverSidebarFolder.value = 'root'
  dragOverSidebarPosition.value = 'inside'
}

function onSidebarRootDragLeave() {
  if (dragOverSidebarFolder.value === 'root') {
    dragOverSidebarFolder.value = null
    dragOverSidebarPosition.value = null
  }
}

async function onSidebarRootDrop(e) {
  e.preventDefault()
  e.stopPropagation()
  
  if (draggingSidebarFolder.value && dragOverSidebarFolder.value === 'root') {
    const success = await drive.moveFolder(draggingSidebarFolder.value.id, null)
    if (success) {
      toast.success(t('driveView.movedFolderToRoot', { name: draggingSidebarFolder.value.name }))
      fetchAllFolders()
      // Refresh current view if affected
      drive.fetchContents(drive.currentFolder?.id ?? null)
    } else {
      toast.error(t('driveView.failedToMoveFolder'))
    }
  }
  
  draggingSidebarFolder.value = null
  dragOverSidebarFolder.value = null
  dragOverSidebarPosition.value = null
}

function onSidebarFolderDragLeave(e) {
  // The event may be emitted by child components without a DOM event payload,
  // so guard against a missing event/currentTarget. Only keep the highlight
  // when the pointer is still inside the same element.
  if (!e?.currentTarget || !e.currentTarget.contains(e.relatedTarget)) {
    dragOverSidebarFolder.value = null
    dragOverSidebarPosition.value = null
  }
}

async function onSidebarFolderDrop(e, targetFolder) {
  e.preventDefault()
  e.stopPropagation()
  
  // Handle multi-file drops
  if (draggingFiles.value.length > 0) {
    const files = [...draggingFiles.value]
    let successCount = 0
    let failCount = 0
    
    for (const file of files) {
      const success = await drive.moveFile(file.id, targetFolder.id)
      if (success) {
        successCount++
      } else {
        failCount++
      }
    }
    
    if (successCount > 0) {
      if (files.length === 1) {
        toast.success(t('driveView.movedFileToFolder', { file: files[0].original_name, folder: targetFolder.name }))
      } else {
        toast.success(t('driveView.movedFilesToFolder', successCount, { count: successCount, folder: targetFolder.name }))
      }
      drive.fetchContents(drive.currentFolder?.id)
    }
    
    if (failCount > 0) {
      toast.error(t('driveView.failedToMoveFilesCount', failCount, { count: failCount }))
    }
    
    drive.clearSelection()
    draggingFile.value = null
    draggingFiles.value = []
  }
  
  // Handle folder drops (new functionality)
  if (draggingSidebarFolder.value && draggingSidebarFolder.value.id !== targetFolder.id) {
    let newParentId = null
    
    if (dragOverSidebarPosition.value === 'inside') {
      // Move folder inside target
      newParentId = targetFolder.id
    } else {
      // Move to same parent as target (before/after just reorders visually but same parent)
      newParentId = targetFolder.parent_id
    }
    
    const success = await drive.moveFolder(draggingSidebarFolder.value.id, newParentId)
    if (success) {
      toast.success(t('driveView.movedFolder', { name: draggingSidebarFolder.value.name }))
      fetchAllFolders()
      // Refresh current view if affected
      drive.fetchContents(drive.currentFolder?.id ?? null)
    } else {
      toast.error(t('driveView.failedToMoveFolder'))
    }
  }
  
  draggingSidebarFolder.value = null
  dragOverSidebarFolder.value = null
  dragOverSidebarPosition.value = null
}

// Check if a folder is a descendant of another
function isDescendant(folderId, ancestorId) {
  if (!ancestorId) return false
  
  const findFolder = (id, folders) => {
    for (const f of folders) {
      if (f.id === id) return f
      if (f.children) {
        const found = findFolder(id, f.children)
        if (found) return found
      }
    }
    return null
  }
  
  const ancestor = findFolder(ancestorId, folderTree.value)
  if (!ancestor) return false
  
  const checkDescendants = (folder) => {
    if (folder.id === folderId) return true
    if (folder.children) {
      return folder.children.some(checkDescendants)
    }
    return false
  }
  
  return checkDescendants(ancestor)
}

// Upload
function triggerUpload() {
  fileInput.value?.click()
}

// Wrappers used by DriveSubHeader overflow menu
async function downloadCurrentFolderAsZip() {
  // If we're inside a folder, download it; otherwise fall back to "Download All".
  if (drive.currentFolder) {
    await downloadFolderAsZip(drive.currentFolder)
  } else {
    await downloadAllDrive()
  }
}

function openDesktopAppDownload() {
  // FlowOneDrive desktop sync app. Served via the public share endpoint.
  // Opening in a new tab is friendlier than navigating away from the Drive view.
  window.open('https://flowone.pro/api/drive/share/3da3c12b6627241d10b207e04153fb7e389b66a3db9fc4818a85bfd844f1e4eb?fn=FlowOneDrive.rar', '_blank', 'noopener')
}

// Block files that won't fit in the user's remaining storage up-front and
// explain why, so an upload that can't succeed fails immediately with a clear
// message instead of silently erroring mid-upload. Returns the files that are
// safe to upload (oversized ones are skipped with a warning toast).
function checkUploadQuota(fileList) {
  const files = Array.from(fileList)
  const q = drive.quota
  // Unlimited or unknown quota: nothing to enforce on the client.
  if (!q || q.unlimited || q.available == null || q.available < 0) {
    return files
  }

  const allowed = []
  const tooBig = []
  let remaining = q.available
  for (const f of files) {
    if (f.size > remaining) {
      tooBig.push(f)
    } else {
      allowed.push(f)
      remaining -= f.size
    }
  }

  if (tooBig.length === 1) {
    toast.error(t('driveView.fileTooLargeForQuota', {
      name: tooBig[0].name,
      size: formatSize(tooBig[0].size),
      available: formatSize(q.available),
    }))
  } else if (tooBig.length > 1) {
    toast.error(t('driveView.filesTooLargeForQuota', {
      count: tooBig.length,
      available: formatSize(q.available),
    }))
  }

  return allowed
}

// Turn the failed-file list from a bulk upload into a message that states the
// real reason instead of just a count. One failure shows the file + reason;
// several show the count plus the distinct reasons.
function formatUploadFailures(failedFiles) {
  const list = failedFiles || []
  if (list.length === 1) {
    return t('driveView.uploadFailedReason', { name: list[0].name, reason: list[0].error })
  }
  const reasons = [...new Set(list.map((f) => f.error).filter(Boolean))]
  const base = t('driveView.failedToUploadFilesCount', list.length, { count: list.length })
  return reasons.length ? `${base}: ${reasons.join(', ')}` : base
}

async function handleFileSelect(e) {
  const files = e.target.files
  if (!files?.length) return
  
  // Handle shared folder upload
  if (drive.isSharedView && drive.currentSharedFolder) {
    if (!drive.canEditSharedFolder) {
      toast.error(t('driveView.youDoNotHavePermission'))
      e.target.value = ''
      return
    }
    
    // Use bulk upload for shared folders
    const targetFolderId = drive.currentFolderId || drive.currentSharedFolder.id
    const result = await drive.uploadToSharedFolderBulk(targetFolderId, files)
    // Refresh shared folder view
    if (targetFolderId === drive.currentSharedFolder.id) {
      await drive.enterSharedFolder(drive.currentSharedFolder)
    } else {
      await drive.navigateSharedSubfolder(targetFolderId)
    }
    if (result.completed > 0) {
      toast.success(t('driveView.uploadedFilesCount', result.completed, { count: result.completed }))
    }
    if (result.failed > 0) {
      console.error('Upload failures:', result.failedFiles)
      toast.error(formatUploadFailures(result.failedFiles), { duration: 6000 })
    }
    e.target.value = ''
    return
  }
  
  // Skip files that exceed the available quota (warns the user) before uploading
  const allowed = checkUploadQuota(files)
  if (allowed.length === 0) {
    e.target.value = ''
    return
  }

  // Use bulk upload for normal uploads
  const result = await drive.uploadFilesBulk(allowed)
  if (result.completed > 0) {
    toast.success(t('driveView.uploadedFilesCount', result.completed, { count: result.completed }))
  }
  if (result.failed > 0) {
    console.error('Upload failures:', result.failedFiles)
    toast.error(formatUploadFailures(result.failedFiles), { duration: 6000 })
  }

  // Refresh the current folder so newly uploaded files show up immediately.
  // uploadFilesBulk only pushes into the in-memory list, which a background
  // sync-event refetch can clobber - re-fetching makes the server authoritative.
  if (result.completed > 0) {
    await drive.fetchContents(drive.currentFolderId)
  }

  // Reset input
  e.target.value = ''
}

function handleDrop(e) {
  e.preventDefault()
  dragOver.value = false
  
  const files = e.dataTransfer?.files
  if (!files?.length) return
  
  handleFilesUpload(files)
}

async function handleFilesUpload(files) {
  // Handle shared folder upload
  if (drive.isSharedView && drive.currentSharedFolder) {
    if (!drive.canEditSharedFolder) {
      toast.error(t('driveView.youDoNotHavePermission'))
      return
    }
    
    // Use bulk upload for shared folders
    const targetFolderId = drive.currentFolderId || drive.currentSharedFolder.id
    const result = await drive.uploadToSharedFolderBulk(targetFolderId, files)
    // Refresh shared folder view
    if (targetFolderId === drive.currentSharedFolder.id) {
      await drive.enterSharedFolder(drive.currentSharedFolder)
    } else {
      await drive.navigateSharedSubfolder(targetFolderId)
    }
    if (result.completed > 0) {
      toast.success(t('driveView.uploadedFilesCount', result.completed, { count: result.completed }))
    }
    if (result.failed > 0) {
      console.error('Upload failures:', result.failedFiles)
      toast.error(formatUploadFailures(result.failedFiles), { duration: 6000 })
    }
    return
  }
  
  // Skip files that exceed the available quota (warns the user) before uploading
  const allowed = checkUploadQuota(files)
  if (allowed.length === 0) return

  // Use bulk upload for normal uploads
  const result = await drive.uploadFilesBulk(allowed)
  if (result.completed > 0) {
    toast.success(t('driveView.uploadedFilesCount', result.completed, { count: result.completed }))
  }
  if (result.failed > 0) {
    console.error('Upload failures:', result.failedFiles)
    toast.error(formatUploadFailures(result.failedFiles), { duration: 6000 })
  }

  // Refresh the current folder so newly uploaded files show up immediately.
  if (result.completed > 0) {
    await drive.fetchContents(drive.currentFolderId)
  }
}

// Delete
function confirmDelete(item, type) {
  // Check if folder is protected (board-linked or system folder)
  if (type === 'folder') {
    if (item.board_id) {
      protectedFolderInfo.value = {
        name: item.name,
        reason: 'driveView.protectedFolder.reasons.linkedToBoard',
        isSystem: false,
        hint: 'driveView.protectedFolder.hints.unlinkFromBoard'
      }
      showProtectedModal.value = true
      return
    }
    if (item.name === 'Boards' && !item.parent_id) {
      protectedFolderInfo.value = {
        name: item.name,
        reason: 'driveView.protectedFolder.reasons.boardsSystemFolder',
        isSystem: true,
        hint: 'driveView.protectedFolder.hints.boardsSystemFolder'
      }
      showProtectedModal.value = true
      return
    }
    if (item.name === 'Attachments' && !item.parent_id) {
      protectedFolderInfo.value = {
        name: item.name,
        reason: 'driveView.protectedFolder.reasons.attachmentsSystemFolder',
        isSystem: true,
        hint: 'driveView.protectedFolder.hints.attachmentsSystemFolder'
      }
      showProtectedModal.value = true
      return
    }
    if (item.name === 'Chats' && !item.parent_id) {
      protectedFolderInfo.value = {
        name: item.name,
        reason: 'driveView.protectedFolder.reasons.chatsSystemFolder',
        isSystem: true,
        hint: 'driveView.protectedFolder.hints.chatsSystemFolder'
      }
      showProtectedModal.value = true
      return
    }
    if (item.name === 'Invoices' && !item.parent_id) {
      protectedFolderInfo.value = {
        name: item.name,
        reason: 'driveView.protectedFolder.reasons.invoicesSystemFolder',
        isSystem: true,
        hint: 'driveView.protectedFolder.hints.invoicesSystemFolder'
      }
      showProtectedModal.value = true
      return
    }
    if (item.name === 'Moodboards' && !item.parent_id) {
      protectedFolderInfo.value = {
        name: item.name,
        reason: 'driveView.protectedFolder.reasons.moodboardsSystemFolder',
        isSystem: true,
        hint: 'driveView.protectedFolder.hints.moodboardsSystemFolder'
      }
      showProtectedModal.value = true
      return
    }
  }
  
  // Check if this item is part of a multi-selection
  const isItemSelected = type === 'file' 
    ? drive.isFileSelected(item.id) 
    : drive.isFolderSelected(item.id)
  
  // If multiple items are selected and this item is one of them, do bulk delete
  if (drive.selectionCount > 1 && isItemSelected) {
    // Check if any selected folders are protected
    for (const folderId of drive.selectedFolders) {
      const folder = drive.folders.find(f => f.id === folderId)
      if (folder?.board_id) {
        protectedFolderInfo.value = {
          name: folder.name,
          reason: 'This folder is linked to a board project and cannot be deleted.',
          isSystem: false,
          hint: 'Remove this folder from your selection or unlink it from the board first.'
        }
        showProtectedModal.value = true
        return
      }
      if (folder?.name === 'Boards' && !folder.parent_id) {
        protectedFolderInfo.value = {
          name: folder.name,
          reason: 'This is a system folder that cannot be deleted.',
          isSystem: true,
          hint: 'Remove "Boards" from your selection to continue.'
        }
        showProtectedModal.value = true
        return
      }
      if (folder?.name === 'Attachments' && !folder.parent_id) {
        protectedFolderInfo.value = {
          name: folder.name,
          reason: 'This is a system folder that cannot be deleted.',
          isSystem: true,
          hint: 'Remove "Attachments" from your selection to continue.'
        }
        showProtectedModal.value = true
        return
      }
      if (folder?.name === 'Chats' && !folder.parent_id) {
        protectedFolderInfo.value = {
          name: folder.name,
          reason: 'This is a system folder that cannot be deleted.',
          isSystem: true,
          hint: 'Remove "Chats" from your selection to continue.'
        }
        showProtectedModal.value = true
        return
      }
      if (folder?.name === 'Invoices' && !folder.parent_id) {
        protectedFolderInfo.value = {
          name: folder.name,
          reason: 'This is a system folder that cannot be deleted.',
          isSystem: true,
          hint: 'Remove "Invoices" from your selection to continue.'
        }
        showProtectedModal.value = true
        return
      }
      if (folder?.name === 'Moodboards' && !folder.parent_id) {
        protectedFolderInfo.value = {
          name: folder.name,
          reason: 'This is a system folder that cannot be deleted.',
          isSystem: true,
          hint: 'Remove "Moodboards" from your selection to continue.'
        }
        showProtectedModal.value = true
        return
      }
    }
    
    isBulkDelete.value = true
    deleteTarget.value = { 
      item: null, 
      type: 'bulk',
      count: drive.selectionCount,
      hasFolder: drive.selectedFolders.size > 0
    }
  } else {
    // Single item delete
    isBulkDelete.value = false
    deleteTarget.value = { item, type }
  }
  
  deleteConfirmText.value = ''
  showDeleteConfirm.value = true
}

// Check if delete can proceed (always true for trash since items can be restored)
const canConfirmDelete = computed(() => {
  return !!deleteTarget.value
})

async function executeDelete() {
  if (!deleteTarget.value) return
  
  const { item, type } = deleteTarget.value
  
  // Handle bulk delete - move to trash
  if (type === 'bulk') {
    const fileIds = [...drive.selectedFiles]
    const folderIds = [...drive.selectedFolders]
    const totalItems = fileIds.length + folderIds.length

    // Initialize progress (single batch == one "current item" tick).
    deleteInProgress.value = true
    deleteProgress.value = { current: 0, total: totalItems, currentItem: t('driveView.movingToTrash') || '...' }

    // ONE HTTP call instead of N. Server runs the folder-size walk
    // once per affected parent, not per item.
    const result = await drive.bulkTrash(fileIds, folderIds)
    const successCount = result.success || 0
    const failedCount = result.failed || 0
    const protectedFolderError = (result.errors || []).find(
      e => typeof e === 'string' && (e.includes('system folder') || e.includes('linked to board'))
    ) || null

    deleteProgress.value.current = totalItems
    deleteInProgress.value = false
    drive.clearSelection()

    if (successCount > 0) {
      toast.success(t('driveView.movedItemsToTrashCount', successCount, { count: successCount }))
      fetchAllFolders() // Refresh tree
    }
    if (protectedFolderError) {
      toast.error(protectedFolderError, { duration: 6000 })
    } else if (failedCount > 0) {
      toast.error(t('driveView.failedToMoveItemsToTrashCount', failedCount, { count: failedCount }))
    }
  } else {
    // Single item delete - move to trash
    deleteInProgress.value = true
    deleteProgress.value = { current: 0, total: 1, currentItem: item?.original_name || item?.name || '' }
    
    let success = false
    let errorMessage = null
    
    if (type === 'folder') {
      const result = await drive.trashFolder(item.id)
      success = result.success
      errorMessage = result.error
      if (success) fetchAllFolders() // Refresh tree
    } else if (item.is_collab_document) {
      // Delete collab document
      try {
        await collabStore.deleteDocument(item.uuid)
        success = true
      } catch (e) {
        errorMessage = e.message || t('driveView.failedToDeleteDocument')
      }
    } else {
      success = await drive.trashFile(item.id)
    }
    
    deleteInProgress.value = false
    
    if (success) {
      const itemLabel = type === 'folder'
        ? t('driveView.folder')
        : (item.is_collab_document ? t('driveView.documentItem') : t('driveView.file'))
      toast.success(item.is_collab_document
        ? t('driveView.itemDeleted', { item: itemLabel })
        : t('driveView.itemMovedToTrash', { item: itemLabel })
      )
    } else if (errorMessage) {
      toast.error(errorMessage, { duration: 6000 })
    } else {
      const itemLabel = type === 'folder'
        ? t('driveView.folder')
        : (item.is_collab_document ? t('driveView.documentItem') : t('driveView.file'))
      toast.error(item.is_collab_document
        ? t('driveView.failedToDeleteItem', { item: itemLabel })
        : t('driveView.failedToMoveItemToTrash', { item: itemLabel })
      )
    }
  }
  
  showDeleteConfirm.value = false
  deleteTarget.value = null
  deleteConfirmText.value = ''
  isBulkDelete.value = false
}

function cancelDelete() {
  showDeleteConfirm.value = false
  deleteTarget.value = null
  deleteConfirmText.value = ''
  isBulkDelete.value = false
}

// Rename
function startRename(item, type) {
  renameTarget.value = { item, type }
  renameValue.value = type === 'folder' ? item.name : item.original_name
  showRenameModal.value = true
}

async function executeRename() {
  if (!renameTarget.value || !renameValue.value.trim()) return
  
  const { item, type } = renameTarget.value
  let success = false
  
  if (type === 'folder') {
    success = await drive.renameFolder(item.id, renameValue.value.trim())
    if (success) fetchAllFolders() // Refresh tree
  } else if (item.is_collab_document) {
    // Rename collab document
    try {
      await collabStore.updateDocument(item.uuid, { title: renameValue.value.trim() })
      success = true
    } catch (e) {
      console.error('Failed to rename document:', e)
    }
  } else {
    success = await drive.renameFile(item.id, renameValue.value.trim())
  }
  
  if (success) {
    toast.success(t('driveView.renamedSuccessfully'))
  } else {
    toast.error(t('driveView.failedToRename'))
  }
  
  showRenameModal.value = false
  renameTarget.value = null
}

// Tracks files currently being restored from cold storage so the UI can
// surface a spinner / disabled state instead of letting the user spam
// clicks. Keyed by file id.
const restoringFiles = ref(new Set())

// Files whose download is being prepared (token request / cold-storage
// restore). Surfaced as a per-row spinner so the user gets instant feedback
// instead of clicking into the void.
const downloadingFiles = ref(new Set())

function markDownloading(id, on) {
  const next = new Set(downloadingFiles.value)
  if (on) next.add(id)
  else next.delete(id)
  downloadingFiles.value = next
}

// Secure download. For owned files we fetch a short-lived signed token and let
// the BROWSER download the file natively (streamed straight to disk with its
// own progress bar). The old approach buffered the entire file into memory via
// fetch()+blob() before the save dialog even appeared - that's what made big
// downloads sit silently for a long time and could crash the tab on multi-GB
// files. Shared-folder files use a public endpoint the token doesn't cover, so
// they keep the fetch-based path (downloadFileViaFetch).
//
// Cold-storage recall: the token endpoint returns HTTP 202 {status:'restoring',
// retry_after} while a NAS-only file is being warmed; we surface a toast, wait,
// and retry so the actual byte transfer only ever hits a hot file.
async function downloadFile(file, retryCount = 0) {
  const MAX_RESTORE_RETRIES = 8 // ~total cap ~120s with retry_after=15 cap

  if (drive.isSharedView && drive.currentSharedFolder) {
    return downloadFileViaFetch(file, retryCount)
  }

  if (retryCount === 0) {
    markDownloading(file.id, true)
    toast.info(t('driveView.preparingDownload', { defaultValue: 'Preparing download…' }))
  }

  try {
    const res = await drive.requestDownloadToken(file.id)

    if (res.status === 'restoring') {
      restoringFiles.value.add(file.id)
      if (retryCount === 0) {
        toast.info(t('driveView.restoringFromArchive', { defaultValue: 'Restoring file from cold storage...' }))
      }
      if (retryCount >= MAX_RESTORE_RETRIES) {
        restoringFiles.value.delete(file.id)
        markDownloading(file.id, false)
        toast.error(t('driveView.restoreTimedOut', { defaultValue: 'File is still being restored. Please try again in a moment.' }))
        return
      }
      await new Promise((resolve) => setTimeout(resolve, res.retryAfter * 1000))
      return downloadFile(file, retryCount + 1)
    }

    if (res.status === 'ready' && res.token) {
      restoringFiles.value.delete(file.id)
      const url = `${getSecureFileUrl(file)}?dl_token=${encodeURIComponent(res.token)}`
      const link = document.createElement('a')
      link.href = url
      link.download = file.original_name
      document.body.appendChild(link)
      link.click()
      document.body.removeChild(link)
    } else {
      restoringFiles.value.delete(file.id)
      toast.error(t('driveView.failedToDownloadFile'))
    }
  } catch (e) {
    restoringFiles.value.delete(file.id)
    console.error('Download failed:', e)
    toast.error(t('driveView.downloadFailed'))
  } finally {
    // Keep the spinner up briefly so feedback is visible while the browser
    // takes over the native download.
    setTimeout(() => markDownloading(file.id, false), 1200)
  }
}

// Legacy fetch-based download, retained for shared-folder files whose public
// endpoint isn't covered by the signed-token flow.
async function downloadFileViaFetch(file, retryCount = 0) {
  const MAX_RESTORE_RETRIES = 8

  if (retryCount === 0) markDownloading(file.id, true)

  try {
    const response = await fetch(getSecureFileUrl(file), {
      headers: getAuthHeaders()
    })

    if (response.status === 202) {
      restoringFiles.value.add(file.id)
      let retryAfter = 5
      try {
        const body = await response.json()
        retryAfter = Math.max(2, Math.min(30, Number(body?.retry_after) || 5))
      } catch (_) { /* ignore parse failure */ }

      if (retryCount === 0) {
        toast.info(t('driveView.restoringFromArchive', { defaultValue: 'Restoring file from cold storage...' }))
      }

      if (retryCount >= MAX_RESTORE_RETRIES) {
        restoringFiles.value.delete(file.id)
        markDownloading(file.id, false)
        toast.error(t('driveView.restoreTimedOut', { defaultValue: 'File is still being restored. Please try again in a moment.' }))
        return
      }

      await new Promise((resolve) => setTimeout(resolve, retryAfter * 1000))
      return downloadFileViaFetch(file, retryCount + 1)
    }

    if (response.ok) {
      restoringFiles.value.delete(file.id)
      const blob = await response.blob()
      const blobUrl = URL.createObjectURL(blob)

      const link = document.createElement('a')
      link.href = blobUrl
      link.download = file.original_name
      document.body.appendChild(link)
      link.click()
      document.body.removeChild(link)

      setTimeout(() => URL.revokeObjectURL(blobUrl), 1000)
    } else {
      restoringFiles.value.delete(file.id)
      toast.error(t('driveView.failedToDownloadFile'))
    }
  } catch (e) {
    restoringFiles.value.delete(file.id)
    console.error('Download failed:', e)
    toast.error(t('driveView.downloadFailed'))
  } finally {
    setTimeout(() => markDownloading(file.id, false), 600)
  }
}

// Download entire drive as zip
async function downloadAllDrive() {
  showDownloadMenu.value = false
  downloadingZip.value = true
  
  try {
    const apiUrl = import.meta.env.VITE_API_URL || '/api'
    const response = await fetch(`${apiUrl}/drive/download-zip`, {
      headers: getAuthHeaders()
    })
    
    if (response.ok) {
      const blob = await response.blob()
      const blobUrl = URL.createObjectURL(blob)
      
      const link = document.createElement('a')
      link.href = blobUrl
      link.download = `${t('driveView.myDrive')}.zip`
      document.body.appendChild(link)
      link.click()
      document.body.removeChild(link)
      
      setTimeout(() => URL.revokeObjectURL(blobUrl), 1000)
      toast.success(t('driveView.driveDownloadedSuccessfully'))
    } else {
      const data = await response.json().catch(() => ({}))
      toast.error(data.message || t('driveView.failedToDownloadDrive'))
    }
  } catch (e) {
    console.error('Download failed:', e)
    toast.error(t('driveView.downloadFailed'))
  } finally {
    downloadingZip.value = false
  }
}

// Download current folder as zip
async function downloadCurrentFolder() {
  showDownloadMenu.value = false
  
  if (!drive.currentFolderId) {
    downloadAllDrive()
    return
  }
  
  downloadingZip.value = true
  
  try {
    const apiUrl = import.meta.env.VITE_API_URL || '/api'
    const response = await fetch(`${apiUrl}/drive/download-zip?folder=${drive.currentFolderId}`, {
      headers: getAuthHeaders()
    })
    
    if (response.ok) {
      const blob = await response.blob()
      const blobUrl = URL.createObjectURL(blob)
      
      // Get folder name from path or use default
      const folderName = drive.path?.length > 0 
        ? drive.path[drive.path.length - 1].name 
        : t('driveView.folder')
      
      const link = document.createElement('a')
      link.href = blobUrl
      link.download = `${folderName}.zip`
      document.body.appendChild(link)
      link.click()
      document.body.removeChild(link)
      
      setTimeout(() => URL.revokeObjectURL(blobUrl), 1000)
      toast.success(t('driveView.folderDownloadedSuccessfully'))
    } else {
      const data = await response.json().catch(() => ({}))
      toast.error(data.message || t('driveView.failedToDownloadFolder'))
    }
  } catch (e) {
    console.error('Download failed:', e)
    toast.error(t('driveView.downloadFailed'))
  } finally {
    downloadingZip.value = false
  }
}

// Download selected files as zip
async function downloadSelectedFiles() {
  showDownloadMenu.value = false
  
  if (drive.selectedFiles.size === 0 && drive.selectedFolders.size === 0) {
    toast.info(t('driveView.noItemsSelected'))
    return
  }
  
  downloadingZip.value = true
  
  try {
    const token = getToken('webmail_token')
    const fileIds = Array.from(drive.selectedFiles)
    const folderIds = Array.from(drive.selectedFolders)
    
    // Build query params
    const params = new URLSearchParams()
    if (fileIds.length > 0) {
      params.append('files', fileIds.join(','))
    }
    if (folderIds.length > 0) {
      params.append('folders', folderIds.join(','))
    }
    
    const apiUrl = import.meta.env.VITE_API_URL || '/api'
    const response = await fetch(`${apiUrl}/drive/download-selection-zip?${params}`, {
      headers: getAuthHeaders()
    })
    
    if (response.ok) {
      const blob = await response.blob()
      const blobUrl = URL.createObjectURL(blob)
      
      const itemCount = fileIds.length + folderIds.length
      const filename = itemCount === 1
        ? t('driveView.downloadZipFilenameSingle')
        : t('driveView.downloadZipFilenameMultiple', { count: itemCount })
      
      const link = document.createElement('a')
      link.href = blobUrl
      link.download = filename
      document.body.appendChild(link)
      link.click()
      document.body.removeChild(link)
      
      setTimeout(() => URL.revokeObjectURL(blobUrl), 1000)
      toast.success(t('driveView.downloadCompleted'))
    } else {
      const data = await response.json().catch(() => ({}))
      toast.error(data.message || 'Failed to download')
    }
  } catch (e) {
    console.error('Download failed:', e)
    toast.error(t('driveView.downloadFailed'))
  } finally {
    downloadingZip.value = false
  }
}

// Close download menu when clicking outside
function handleClickOutside(e) {
  if (downloadMenuRef.value && !downloadMenuRef.value.contains(e.target)) {
    showDownloadMenu.value = false
  }
}

// Drag and drop to folders
function onFileDragStart(e, file) {
  // Check if this file is part of a selection
  const isSelected = drive.isFileSelected(file.id)
  
  if (isSelected && drive.selectedFiles.size > 1) {
    // Multi-file drag - get all selected files
    draggingFiles.value = filteredFiles.value.filter(f => drive.isFileSelected(f.id))
    draggingFile.value = file // Primary file for reference
  } else {
    // Single file drag (either not selected or only one selected)
    draggingFiles.value = [file]
    draggingFile.value = file
    // Select this file if not already selected
    if (!isSelected) {
      drive.clearSelection()
      drive.selectFile(file.id, true)
    }
  }
  
  e.dataTransfer.effectAllowed = 'move'
  e.dataTransfer.setData('text/plain', draggingFiles.value.map(f => f.id).join(','))
  
  // Create custom drag ghost showing file count
  createDragGhost(e, draggingFiles.value)
}

function createDragGhost(e, files) {
  // Create a custom drag image
  const ghost = document.createElement('div')
  ghost.className = 'drag-ghost'
  ghost.style.cssText = `
    position: fixed;
    top: -1000px;
    left: -1000px;
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 10px 16px;
    background: linear-gradient(135deg, rgb(var(--color-primary-500)), rgb(var(--color-primary-600)));
    color: white;
    border-radius: 12px;
    font-size: 14px;
    font-weight: 600;
    box-shadow: 0 8px 24px rgba(0,0,0,0.25);
    z-index: 99999;
    pointer-events: none;
  `
  
  // Add stacked file icons if multiple files
  if (files.length > 1) {
    const stackIcon = document.createElement('div')
    stackIcon.style.cssText = `
      position: relative;
      width: 32px;
      height: 32px;
    `
    // Create stacked effect
    for (let i = Math.min(files.length - 1, 2); i >= 0; i--) {
      const layer = document.createElement('div')
      layer.style.cssText = `
        position: absolute;
        top: ${i * 3}px;
        left: ${i * 3}px;
        width: 24px;
        height: 28px;
        background: white;
        border-radius: 4px;
        border: 2px solid rgba(255,255,255,0.5);
      `
      stackIcon.appendChild(layer)
    }
    ghost.appendChild(stackIcon)
    
    const text = document.createElement('span')
    text.textContent = `${files.length} files`
    ghost.appendChild(text)
  } else {
    // Single file
    const icon = document.createElement('span')
    icon.className = 'material-symbols-rounded'
    icon.style.fontSize = '24px'
    icon.textContent = 'draft'
    ghost.appendChild(icon)
    
    const text = document.createElement('span')
    text.textContent = files[0].original_name.length > 25 
      ? files[0].original_name.substring(0, 22) + '...' 
      : files[0].original_name
    ghost.appendChild(text)
  }
  
  document.body.appendChild(ghost)
  dragGhost.value = ghost
  
  // Set as drag image with offset
  e.dataTransfer.setDragImage(ghost, 20, 20)
  
  // Remove ghost after a short delay (after browser captures it)
  setTimeout(() => {
    if (ghost.parentNode) {
      ghost.parentNode.removeChild(ghost)
    }
  }, 0)
}

function onFileDragEnd() {
  draggingFile.value = null
  draggingFiles.value = []
  dragOverFolder.value = null
  
  // Cleanup ghost if still exists
  if (dragGhost.value && dragGhost.value.parentNode) {
    dragGhost.value.parentNode.removeChild(dragGhost.value)
    dragGhost.value = null
  }
}

function onFolderDragOver(e, folderId) {
  e.preventDefault()
  dragOverFolder.value = folderId
}

function onFolderDragLeave() {
  dragOverFolder.value = null
}

async function onFolderDrop(e, folderId) {
  e.preventDefault()
  dragOverFolder.value = null
  
  // Handle multi-file drop
  if (draggingFiles.value.length > 0) {
    const files = [...draggingFiles.value]
    const folder = filteredFolders.value.find(f => f.id === folderId)
    const folderName = folder?.name || t('driveView.folder')
    
    let successCount = 0
    let failCount = 0
    
    for (const file of files) {
      const success = await drive.moveFile(file.id, folderId)
      if (success) {
        successCount++
      } else {
        failCount++
      }
    }
    
    if (successCount > 0) {
      if (files.length === 1) {
        toast.success(t('driveView.movedFileToFolder', { file: files[0].original_name, folder: folderName }))
      } else {
        toast.success(t('driveView.movedFilesToFolder', successCount, { count: successCount, folder: folderName }))
      }
      drive.fetchContents(drive.currentFolder?.id)
    }
    
    if (failCount > 0) {
      toast.error(t('driveView.failedToMoveFilesCount', failCount, { count: failCount }))
    }
    
    drive.clearSelection()
    draggingFile.value = null
    draggingFiles.value = []
  }
}

// File preview
function canPreview(file) {
  // Markdown files don't always carry a text/* mime type (the backend can
  // emit application/octet-stream for them), so check the extension first.
  if (isMarkdownFile(file)) return true
  const previewable = [
    'image/', 'video/', 'audio/',
    'application/pdf',
    'text/',
    'application/json'
  ]
  return previewable.some(type => file.mime_type?.startsWith(type))
}

function canUseOfficeViewer(file) {
  // We now handle office files directly - docx via mammoth, others via download
  // This is kept for compatibility but returns true for all office types
  const officeTypes = [
    'application/msword',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'application/vnd.ms-excel',
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'application/vnd.ms-powerpoint',
    'application/vnd.openxmlformats-officedocument.presentationml.presentation'
  ]
  return officeTypes.includes(file.mime_type)
}

// Preview blob URL cache
const previewBlobUrl = ref(null)
const previewLoading = ref(false)
const docxHtmlContent = ref(null)
const excelHtmlContent = ref(null)
const excelSheets = ref([])
const activeExcelSheet = ref(0)
// Cached ArrayBuffer of the currently-previewed spreadsheet so that
// switching between sheet tabs doesn't trigger another network round
// trip — we re-render from the in-memory buffer.
const previewArrayBuffer = ref(null)
// Sanitised HTML rendered from a Markdown file (read-only preview).
const markdownHtml = ref(null)
// Raw text payload for plain text / json / code previews so the modal
// can actually display the contents instead of a "Loading..." stub.
const textContent = ref(null)

// --- Image preview zoom & pan ---------------------------------------------
// Zoom factor (1 = fit to container). Translation is in CSS pixels and is
// applied around the container centre via translate() before scale().
const imageZoom = ref(1)
const imagePanX = ref(0)
const imagePanY = ref(0)
const isImagePanning = ref(false)
const imagePanStart = ref({ x: 0, y: 0, panX: 0, panY: 0 })
// Active touches for pinch zoom on mobile.
const imageTouchState = ref({ initialDistance: 0, initialZoom: 1, midX: 0, midY: 0 })
const imageContainerRef = ref(null)

const IMAGE_MIN_ZOOM = 1
const IMAGE_MAX_ZOOM = 8

function resetImageZoom() {
  imageZoom.value = 1
  imagePanX.value = 0
  imagePanY.value = 0
  isImagePanning.value = false
}

function clampImagePan() {
  // Allow panning roughly proportional to the extra pixels the zoom adds; we
  // don't have exact image dimensions here without onload, so just keep the
  // pan within a generous box that scales with zoom level.
  if (!imageContainerRef.value) return
  const rect = imageContainerRef.value.getBoundingClientRect()
  const overflow = (imageZoom.value - 1) / 2
  const maxX = rect.width * overflow
  const maxY = rect.height * overflow
  imagePanX.value = Math.max(-maxX, Math.min(maxX, imagePanX.value))
  imagePanY.value = Math.max(-maxY, Math.min(maxY, imagePanY.value))
}

function handleImageWheel(e) {
  e.preventDefault()
  if (!imageContainerRef.value) return
  const rect = imageContainerRef.value.getBoundingClientRect()
  // Cursor offset from container centre.
  const cx = e.clientX - (rect.left + rect.width / 2)
  const cy = e.clientY - (rect.top + rect.height / 2)
  const oldZoom = imageZoom.value
  const factor = e.deltaY < 0 ? 1.15 : 1 / 1.15
  const newZoom = Math.max(IMAGE_MIN_ZOOM, Math.min(IMAGE_MAX_ZOOM, oldZoom * factor))
  if (newZoom === oldZoom) return
  // Keep the point under the cursor stable while zooming.
  // image-space point under cursor: (c - pan) / zoom — must remain constant.
  const ratio = newZoom / oldZoom
  imagePanX.value = cx - (cx - imagePanX.value) * ratio
  imagePanY.value = cy - (cy - imagePanY.value) * ratio
  imageZoom.value = newZoom
  if (newZoom === 1) {
    imagePanX.value = 0
    imagePanY.value = 0
  } else {
    clampImagePan()
  }
}

function _onWindowMouseMoveDuringPan(e) {
  if (!isImagePanning.value) return
  e.preventDefault()
  imagePanX.value = imagePanStart.value.panX + (e.clientX - imagePanStart.value.x)
  imagePanY.value = imagePanStart.value.panY + (e.clientY - imagePanStart.value.y)
  clampImagePan()
}

function _onWindowMouseUpDuringPan() {
  if (!isImagePanning.value) return
  isImagePanning.value = false
  window.removeEventListener('mousemove', _onWindowMouseMoveDuringPan)
  window.removeEventListener('mouseup', _onWindowMouseUpDuringPan)
}

function handleImageMouseDown(e) {
  if (imageZoom.value <= 1) return
  if (e.button !== 0) return
  e.preventDefault()
  isImagePanning.value = true
  imagePanStart.value = {
    x: e.clientX,
    y: e.clientY,
    panX: imagePanX.value,
    panY: imagePanY.value,
  }
  // Track outside the container so pans don't get stuck when the mouse
  // leaves the modal area.
  window.addEventListener('mousemove', _onWindowMouseMoveDuringPan)
  window.addEventListener('mouseup', _onWindowMouseUpDuringPan)
}

function handleImageDoubleClick(e) {
  if (!imageContainerRef.value) return
  if (imageZoom.value > 1) {
    // Zoom out completely.
    resetImageZoom()
    return
  }
  // Zoom in toward the double-click point.
  const rect = imageContainerRef.value.getBoundingClientRect()
  const cx = e.clientX - (rect.left + rect.width / 2)
  const cy = e.clientY - (rect.top + rect.height / 2)
  const newZoom = 2.5
  imagePanX.value = cx - cx * newZoom
  imagePanY.value = cy - cy * newZoom
  imageZoom.value = newZoom
  clampImagePan()
}

function zoomImageIn() {
  const oldZoom = imageZoom.value
  const newZoom = Math.min(IMAGE_MAX_ZOOM, oldZoom * 1.25)
  if (newZoom === oldZoom) return
  const ratio = newZoom / oldZoom
  imagePanX.value = imagePanX.value * ratio
  imagePanY.value = imagePanY.value * ratio
  imageZoom.value = newZoom
  clampImagePan()
}

function zoomImageOut() {
  const oldZoom = imageZoom.value
  const newZoom = Math.max(IMAGE_MIN_ZOOM, oldZoom / 1.25)
  if (newZoom === oldZoom) return
  const ratio = newZoom / oldZoom
  imagePanX.value = imagePanX.value * ratio
  imagePanY.value = imagePanY.value * ratio
  imageZoom.value = newZoom
  if (newZoom === 1) {
    imagePanX.value = 0
    imagePanY.value = 0
  } else {
    clampImagePan()
  }
}

// --- Touch handlers (pinch zoom + one-finger drag pan) ---
function handleImageTouchStart(e) {
  if (e.touches.length === 2) {
    const [t1, t2] = e.touches
    const dx = t2.clientX - t1.clientX
    const dy = t2.clientY - t1.clientY
    const rect = imageContainerRef.value?.getBoundingClientRect()
    imageTouchState.value = {
      initialDistance: Math.hypot(dx, dy),
      initialZoom: imageZoom.value,
      midX: rect ? (t1.clientX + t2.clientX) / 2 - (rect.left + rect.width / 2) : 0,
      midY: rect ? (t1.clientY + t2.clientY) / 2 - (rect.top + rect.height / 2) : 0,
      initialPanX: imagePanX.value,
      initialPanY: imagePanY.value,
    }
  } else if (e.touches.length === 1 && imageZoom.value > 1) {
    isImagePanning.value = true
    imagePanStart.value = {
      x: e.touches[0].clientX,
      y: e.touches[0].clientY,
      panX: imagePanX.value,
      panY: imagePanY.value,
    }
  }
}

function handleImageTouchMove(e) {
  if (e.touches.length === 2 && imageTouchState.value.initialDistance) {
    e.preventDefault()
    const [t1, t2] = e.touches
    const dx = t2.clientX - t1.clientX
    const dy = t2.clientY - t1.clientY
    const dist = Math.hypot(dx, dy)
    const ratio = dist / imageTouchState.value.initialDistance
    const newZoom = Math.max(IMAGE_MIN_ZOOM, Math.min(IMAGE_MAX_ZOOM, imageTouchState.value.initialZoom * ratio))
    const z = newZoom / imageTouchState.value.initialZoom
    const { midX, midY, initialPanX, initialPanY } = imageTouchState.value
    imagePanX.value = midX - (midX - initialPanX) * z
    imagePanY.value = midY - (midY - initialPanY) * z
    imageZoom.value = newZoom
    if (newZoom === 1) {
      imagePanX.value = 0
      imagePanY.value = 0
    } else {
      clampImagePan()
    }
  } else if (e.touches.length === 1 && isImagePanning.value) {
    e.preventDefault()
    imagePanX.value = imagePanStart.value.panX + (e.touches[0].clientX - imagePanStart.value.x)
    imagePanY.value = imagePanStart.value.panY + (e.touches[0].clientY - imagePanStart.value.y)
    clampImagePan()
  }
}

function handleImageTouchEnd(e) {
  if (e.touches.length === 0) {
    isImagePanning.value = false
    imageTouchState.value = { initialDistance: 0, initialZoom: imageZoom.value, midX: 0, midY: 0 }
  }
}

// Check if file is a DOCX (for mammoth preview - only works with .docx)
function isDocxFile(file) {
  return file.mime_type === 'application/vnd.openxmlformats-officedocument.wordprocessingml.document' ||
         file.original_name?.toLowerCase().endsWith('.docx')
}

// Check if file is a Markdown document. Backend may not always emit a
// dedicated text/markdown mime type for .md files (it sometimes ends up as
// text/plain or application/octet-stream), so the extension check is
// authoritative.
function isMarkdownFile(file) {
  if (!file) return false
  const mime = file.mime_type || ''
  if (mime === 'text/markdown' || mime === 'text/x-markdown') return true
  const name = (file.original_name || file.name || '').toLowerCase()
  return name.endsWith('.md') || name.endsWith('.markdown') || name.endsWith('.mdown')
}

// Check if file is a generic text-ish file we can render inline as plain
// text (covers text/* mime types and JSON). Markdown is handled separately
// so it can be rendered as HTML.
function isPlainTextFile(file) {
  if (!file) return false
  if (isMarkdownFile(file)) return false
  const mime = file.mime_type || ''
  if (mime.startsWith('text/')) return true
  if (mime === 'application/json') return true
  return false
}

// Check if file is a Word document (.doc or .docx) - for opening in collab editor
function isWordFile(file) {
  return file.mime_type === 'application/msword' ||
         file.mime_type === 'application/vnd.openxmlformats-officedocument.wordprocessingml.document' ||
         file.original_name?.toLowerCase().endsWith('.doc') ||
         file.original_name?.toLowerCase().endsWith('.docx')
}

// Check if file is Excel
function isExcelFile(file) {
  return file.mime_type === 'application/vnd.ms-excel' ||
         file.mime_type === 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' ||
         file.original_name?.toLowerCase().endsWith('.xlsx') ||
         file.original_name?.toLowerCase().endsWith('.xls')
}

// Check if file is PowerPoint
function isPptFile(file) {
  return file.mime_type === 'application/vnd.ms-powerpoint' ||
         file.mime_type === 'application/vnd.openxmlformats-officedocument.presentationml.presentation' ||
         file.original_name?.toLowerCase().endsWith('.pptx') ||
         file.original_name?.toLowerCase().endsWith('.ppt')
}

// Check if file can be edited collaboratively (LEGACY - for old collab system)
function canEditInCollab(file) {
  return isWordFile(file) || isPptFile(file)
}

// Check if file can be opened in collab editor
function canOpenInCollab(file) {
  // Exclude legacy collab documents (they have UUIDs instead of numeric IDs)
  if (file.is_collab_document || file.uuid) {
    return false
  }
  
  const ext = getFileExtension(file)
  // Only docx and pptx are supported in collab editor
  return ['docx', 'pptx'].includes(ext)
}

// Get file extension helper
function getFileExtension(file) {
  const name = file.original_name || file.name || ''
  return name.toLowerCase().split('.').pop() || ''
}

// =========================================================================
// OnlyOffice editor (runs in parallel with the legacy collab editor)
// =========================================================================
// Shared office availability state (module-level cache, also consumed by
// AttachmentPreview's "Edit in Office" button).
const { officeEnabled, officeExtensions, ensureOfficeStatus } = useOfficeStatus()

// Office-editable Drive file (real file, not a legacy collab document)
function canOpenInOffice(file) {
  if (!officeEnabled.value || !file) return false
  if (file.is_collab_document || file.uuid) return false
  return officeExtensions.value.includes(getFileExtension(file))
}

function openInOffice(file) {
  const folderId = file.folder_id ?? drive.currentFolderId
  router.push({
    name: 'office-editor',
    params: { fileId: String(file.id) },
    query: folderId ? { folder: String(folderId) } : {},
  })
}

// "Edit" button in the fullscreen preview: OnlyOffice first, legacy collab
// editor as fallback (same priority as the double-click handler in openFile).
function canEditPreviewFile(file) {
  return !!file && (canOpenInOffice(file) || canOpenInCollab(file))
}

function editPreviewFile() {
  const file = previewFile.value
  if (!file) return
  closePreview()
  if (canOpenInOffice(file)) {
    openInOffice(file)
  } else if (canOpenInCollab(file)) {
    openInCollabEditor(file)
  }
}

// Create a blank office file in the current folder and open it
async function createOfficeFile(type) {
  try {
    const res = await officeApi.createFile({
      type,
      title: t('officeEditor.untitled'),
      folderId: drive.currentFolderId,
    })
    const file = res.data?.data?.file
    if (!file) throw new Error(res.data?.message || 'Create failed')
    await drive.fetchContents(drive.currentFolderId, { quiet: true })
    openInOffice(file)
  } catch (e) {
    console.error('Failed to create office file:', e)
    toast.error(e?.response?.data?.message || t('officeEditor.createFailed'))
  }
}

// Open file in collab editor
const openingCollabFile = ref(false)

async function openInCollabEditor(file) {
  openingCollabFile.value = true
  try {
    const type = isPptFile(file) ? 'presentation' : 'document'
    openingPresentationEditor.value = type === 'presentation'
    const response = await collabStore.createFromDriveFile(file, type)
    
    if (response) {
      collabDocumentId.value = response.uuid
      collabDocumentTitle.value = file.original_name || file.name
      collabEditorMode.value = type
      collabDriveFileId.value = file.id
      showCollabEditor.value = true

      // Remember the folder we came from so closing returns there.
      // Prefer the file's own folder (works even when opened from search/recent).
      collabReturnFolderId.value = file.folder_id ?? drive.currentFolderId

      const routeName = type === 'presentation' ? 'drive-presentation' : 'drive-document'
      const query = collabReturnFolderId.value ? { folder: String(collabReturnFolderId.value) } : {}
      router.push({ name: routeName, params: { uuid: response.uuid }, query })
    }
  } catch (error) {
    console.error('Failed to open in collab editor:', error)
    openingPresentationEditor.value = false
    const status = error?.response?.status
    const serverMsg = error?.response?.data?.message
    if (status === 404 && serverMsg) {
      toast.error(serverMsg)
    } else if (status === 404) {
      toast.error(t('driveView.collabServiceUnavailable', 'Collab editor service unavailable. Please contact admin.'))
    } else {
      toast.error(t('driveView.failedToOpenDocumentIn'))
    }
  } finally {
    openingCollabFile.value = false
  }
}

// Create new collaborative document
async function createNewCollabDocument() {
  try {
    const title = newDocumentTitle.value.trim() || (
      newDocumentType.value === 'presentation'
        ? t('driveView.untitledPresentation')
        : t('driveView.untitledDocument')
    )
    const response = await collabStore.createDocument(title, newDocumentType.value, drive.currentFolderId)
    
    if (response) {
      collabDocumentId.value = response.uuid
      collabDocumentTitle.value = title
      collabEditorMode.value = newDocumentType.value
      showNewDocumentModal.value = false
      showCollabEditor.value = true
      newDocumentTitle.value = t('driveView.untitledDocument')
      newDocumentType.value = 'document'

      // Remember the folder the document was created in so closing returns there.
      collabReturnFolderId.value = drive.currentFolderId

      // Update URL to reflect the new document, preserving folder context.
      const routeName = newDocumentType.value === 'presentation' ? 'drive-presentation' : 'drive-document'
      const query = drive.currentFolderId ? { folder: String(drive.currentFolderId) } : {}
      router.push({ name: routeName, params: { uuid: response.uuid }, query })
    }
  } catch (error) {
    console.error('Failed to create document:', error)
    toast.error(t('driveView.failedToCreateDocument'))
  }
}

// Close collab editor
function closeCollabEditor() {
  openingPresentationEditor.value = false
  showCollabEditor.value = false
  collabDocumentId.value = null
  collabDocumentTitle.value = ''
  collabDriveFileId.value = null
  // Reset collab store state (clears connected users, etc.)
  collabStore.resetState()

  // Resolve the folder to return to: explicit return target captured when
  // opening the doc, or the current folder if we navigated within Drive.
  const targetFolderId = collabReturnFolderId.value ?? drive.currentFolderId
  collabReturnFolderId.value = null

  // The editor is an overlay, so the folder content behind it never changed.
  // Return to the SAME query-based URL that normal browsing uses so the route
  // scheme stays consistent and the view never flips to root and back. Refresh
  // quietly so any newly created collab doc shows up without blanking the list.
  const query = targetFolderId ? { folder: String(targetFolderId) } : {}
  router.push({ name: 'drive', query })
  drive.fetchContents(targetFolderId, { quiet: true })
}

function handlePresentationEditorReady() {
  openingPresentationEditor.value = false
}

// Open new document modal
function openNewDocumentModal(type = 'document') {
  newDocumentType.value = type
  newDocumentTitle.value = type === 'presentation'
    ? t('driveView.untitledPresentation')
    : t('driveView.untitledDocument')
  showNewDocumentModal.value = true
}

// Decode a Blob to text robustly. Some server configurations (LiteSpeed
// + mod_deflate) end up compressing text/* responses but the browser
// doesn't auto-decompress because the Content-Encoding header gets
// stripped or conflicts with PHP's `Content-Encoding: identity`. The
// blob bytes that arrive then look like binary garbage when decoded as
// UTF-8. This helper inspects the magic bytes and, if it sees a known
// compression format (gzip / zlib-deflate), runs the buffer through
// DecompressionStream before decoding. Falls back to a plain UTF-8
// decode for actual text payloads.
async function decodeBlobToText(blob) {
  const buffer = await blob.arrayBuffer()
  const bytes = new Uint8Array(buffer)

  const tryDecompress = async (format) => {
    if (typeof DecompressionStream === 'undefined') return null
    try {
      const stream = new Blob([bytes]).stream().pipeThrough(new DecompressionStream(format))
      const decompressedBuf = await new Response(stream).arrayBuffer()
      return new TextDecoder('utf-8', { fatal: false }).decode(decompressedBuf)
    } catch (e) {
      return null
    }
  }

  // gzip: 1F 8B
  if (bytes.length >= 2 && bytes[0] === 0x1f && bytes[1] === 0x8b) {
    const out = await tryDecompress('gzip')
    if (out !== null) return out
  }
  // zlib (deflate with header): 78 01 / 78 9C / 78 DA
  if (bytes.length >= 2 && bytes[0] === 0x78 && (bytes[1] === 0x01 || bytes[1] === 0x9c || bytes[1] === 0xda)) {
    const out = await tryDecompress('deflate')
    if (out !== null) return out
  }

  // Default: decode as UTF-8.
  let text = new TextDecoder('utf-8', { fatal: false }).decode(buffer)

  // Heuristic: if the decoded result is dominated by replacement chars or
  // null bytes the underlying payload was binary (or compressed with a
  // format we don't auto-detect). Try raw deflate as a last-ditch attempt
  // before giving up — some servers strip the zlib header on text/* responses.
  if (looksLikeBinaryText(text)) {
    const raw = await tryDecompress('deflate-raw')
    if (raw !== null && !looksLikeBinaryText(raw)) return raw
  }

  return text
}

// Quick heuristic: a UTF-8 decode of binary data tends to produce a high
// density of replacement characters (U+FFFD) and / or NUL bytes. We sample
// the first chunk because that's enough to spot obviously non-text payloads
// without iterating MB of text.
function looksLikeBinaryText(text) {
  if (!text) return false
  const sample = text.slice(0, 2048)
  if (sample.length === 0) return false
  let bad = 0
  for (let i = 0; i < sample.length; i++) {
    const c = sample.charCodeAt(i)
    if (c === 0xfffd || c === 0x00) bad++
  }
  return bad / sample.length > 0.05
}

// Load file as blob URL for preview. Routes the bytes through the
// shared file-preview composable so docx/xlsx rendering matches what
// the email attachment modal and moodboards file preview produce.
async function loadPreviewBlob(file) {
  previewLoading.value = true
  previewBlobUrl.value = null
  docxHtmlContent.value = null
  excelHtmlContent.value = null
  excelSheets.value = []
  activeExcelSheet.value = 0
  previewArrayBuffer.value = null
  markdownHtml.value = null
  textContent.value = null

  try {
    const response = await fetch(getSecureFileUrl(file), {
      headers: getAuthHeaders()
    })

    if (response.ok) {
      const blob = await response.blob()

      if (isDocxFile(file)) {
        const arrayBuffer = await blob.arrayBuffer()
        const result = await renderDocxToHtml(arrayBuffer)
        docxHtmlContent.value = result.html
      } else if (isExcelFile(file)) {
        const arrayBuffer = await blob.arrayBuffer()
        // Cache so tab-switch rerenders without refetching.
        previewArrayBuffer.value = arrayBuffer
        excelSheets.value = await getExcelSheetNames(arrayBuffer)
        if (excelSheets.value.length > 0) {
          excelHtmlContent.value = await renderExcelSheetToHtml(arrayBuffer, 0)
        }
      } else if (isMarkdownFile(file)) {
        // Render Markdown to sanitised HTML. We deliberately read as text
        // and route the parsed output through DOMPurify to neutralise any
        // embedded scripts, event handlers, or javascript: URLs.
        // decodeBlobToText() handles the case where the server returned
        // gzip-compressed bytes without a proper Content-Encoding header.
        const raw = await decodeBlobToText(blob)
        if (looksLikeBinaryText(raw)) {
          // Bytes still look binary after auto-decompression — surface an
          // error rather than feeding gibberish to the parser.
          markdownHtml.value = '<p style="color:#dc2626"><strong>Could not decode this file as text.</strong> The server returned a non-text payload. Try downloading it instead.</p>'
        } else {
          const html = marked.parse(raw, { gfm: true, breaks: true })
          markdownHtml.value = DOMPurify.sanitize(html)
        }
      } else if (isPlainTextFile(file)) {
        const raw = await decodeBlobToText(blob)
        textContent.value = looksLikeBinaryText(raw)
          ? '[Could not decode this file as text. The server returned a non-text payload. Try downloading it instead.]'
          : raw
      } else {
        previewBlobUrl.value = URL.createObjectURL(blob)
      }
    }
  } catch (e) {
    console.error('Failed to load preview:', e)
  }
  previewLoading.value = false
}

// Switch Excel sheet — re-renders from the in-memory ArrayBuffer cached
// in loadPreviewBlob, no network round trip.
async function switchExcelSheet(index) {
  if (!previewFile.value || index === activeExcelSheet.value) return
  if (!previewArrayBuffer.value) return

  activeExcelSheet.value = index
  previewLoading.value = true
  try {
    excelHtmlContent.value = await renderExcelSheetToHtml(previewArrayBuffer.value, index)
  } catch (e) {
    console.error('Failed to switch sheet:', e)
  } finally {
    previewLoading.value = false
  }
}

async function openPreview(file) {
  previewFile.value = file
  // Find index in previewable files for slider
  previewIndex.value = previewableFiles.value.findIndex(f => f.id === file.id)
  if (previewIndex.value === -1) previewIndex.value = 0
  showPreview.value = true
  
  // Load the blob for preview
  await loadPreviewBlob(file)
  
  // Preload thumbnails for all previewable image files (for thumbnail strip)
  previewableFiles.value.forEach(f => {
    if (f.mime_type?.startsWith('image/') && !drive.hasThumbnail(f.id)) {
      drive.loadThumbnail(f.id)
    }
  })
  
  // Track document_open activity for time tracking
  trackDocumentOpen(file)
}

// Track document open activity
function trackDocumentOpen(file) {
  if (!file) return
  
  // Find client from current folder context
  const folderId = file.folder_id || drive.currentFolderId
  const clientId = clientTimeTracker.getClientIdFromFolderId(folderId)
  
  if (clientId) {
    clientTimeTracker.startTracking(
      clientId,
      'document_open',
      file.id?.toString(),
      file.original_name || file.name
    )
    
    // Update tracking info for UI
    trackingInfo.value.isTracking = true
    trackingInfo.value.fileName = file.original_name || file.name
    trackingInfo.value.clientName = getCurrentTrackingClientName()
    startTrackingTimer()
  }
}

async function nextPreview() {
  if (previewableFiles.value.length <= 1) return
  // Cleanup old blob
  if (previewBlobUrl.value) URL.revokeObjectURL(previewBlobUrl.value)
  
  previewIndex.value = (previewIndex.value + 1) % previewableFiles.value.length
  previewFile.value = previewableFiles.value[previewIndex.value]
  await loadPreviewBlob(previewFile.value)
}

async function prevPreview() {
  if (previewableFiles.value.length <= 1) return
  // Cleanup old blob
  if (previewBlobUrl.value) URL.revokeObjectURL(previewBlobUrl.value)
  
  previewIndex.value = (previewIndex.value - 1 + previewableFiles.value.length) % previewableFiles.value.length
  previewFile.value = previewableFiles.value[previewIndex.value]
  await loadPreviewBlob(previewFile.value)
}

// Jump to specific preview by index (for thumbnail navigation)
async function goToPreview(index) {
  if (index < 0 || index >= previewableFiles.value.length || index === previewIndex.value) return
  // Cleanup old blob
  if (previewBlobUrl.value) URL.revokeObjectURL(previewBlobUrl.value)
  
  previewIndex.value = index
  previewFile.value = previewableFiles.value[index]
  await loadPreviewBlob(previewFile.value)
}

// Auto-scroll thumbnail strip to current item
function scrollThumbnailToView() {
  if (!thumbnailStripRef.value) return
  const container = thumbnailStripRef.value
  const thumbnails = container.children
  if (thumbnails[previewIndex.value]) {
    const thumb = thumbnails[previewIndex.value]
    const scrollLeft = thumb.offsetLeft - (container.clientWidth / 2) + (thumb.clientWidth / 2)
    container.scrollTo({ left: scrollLeft, behavior: 'smooth' })
  }
}

// Watch for preview index changes to scroll thumbnail strip
watch(previewIndex, () => {
  nextTick(() => scrollThumbnailToView())
})

// Reset image zoom/pan whenever the previewed file changes (open, next/prev,
// close) so each image starts fitted and unscaled.
watch(previewFile, () => {
  resetImageZoom()
})

function closePreview() {
  // Cleanup blob URL
  if (previewBlobUrl.value) {
    URL.revokeObjectURL(previewBlobUrl.value)
    previewBlobUrl.value = null
  }
  docxHtmlContent.value = null
  excelHtmlContent.value = null
  excelSheets.value = []
  activeExcelSheet.value = 0
  previewArrayBuffer.value = null
  markdownHtml.value = null
  textContent.value = null
  showPreview.value = false
  previewFile.value = null
  
  // Stop document open tracking
  if (trackingInfo.value.isTracking && clientTimeTracker.currentActivityType === 'document_open') {
    clientTimeTracker.stopTracking()
    stopTrackingTimer()
  }
}

// Whether the currently previewed file can be sent to the printer. Covers
// every type we render inline (docx, xlsx, markdown, plain text/code), plus
// PDFs and images.
const canPrintPreview = computed(() => {
  const f = previewFile.value
  if (!f) return false
  if (f.mime_type === 'application/pdf') return true
  if (f.mime_type?.startsWith('image/')) return true
  return isDocxFile(f) || isExcelFile(f) || isMarkdownFile(f) || isPlainTextFile(f)
})

function escapeHtmlForPrint(str) {
  return String(str ?? '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
}

// Print a PDF (or any blob the browser can render) via a hidden iframe so we
// don't depend on a popup window.
function printBlobViaIframe(url) {
  const iframe = document.createElement('iframe')
  iframe.style.cssText = 'position:fixed;right:0;bottom:0;width:0;height:0;border:0;'
  iframe.src = url
  iframe.onload = () => {
    try {
      iframe.contentWindow.focus()
      iframe.contentWindow.print()
    } catch (e) {
      window.open(url, '_blank')
    }
    setTimeout(() => iframe.remove(), 60000)
  }
  document.body.appendChild(iframe)
}

// Build the printable HTML body + extra styles for the active preview, or
// null if the type can't be rendered to print HTML.
function buildPrintContent() {
  const file = previewFile.value
  if (!file) return null

  if (isDocxFile(file) && docxHtmlContent.value) {
    return { body: `<div class="doc">${docxHtmlContent.value}</div>`, styles: '' }
  }
  if (isExcelFile(file) && excelHtmlContent.value) {
    return {
      body: `<div class="doc">${excelHtmlContent.value}</div>`,
      styles: 'table{border-collapse:collapse;width:100%;}td,th{border:1px solid #cbd5e1;padding:4px 8px;font-size:12px;}',
    }
  }
  if (isMarkdownFile(file) && markdownHtml.value) {
    return { body: `<div class="doc markdown">${markdownHtml.value}</div>`, styles: '' }
  }
  if (isPlainTextFile(file) && textContent.value != null) {
    return { body: `<pre class="text">${escapeHtmlForPrint(textContent.value)}</pre>`, styles: '' }
  }
  if (file.mime_type?.startsWith('image/') && previewBlobUrl.value) {
    return {
      body: `<img src="${previewBlobUrl.value}" alt="" />`,
      styles: 'img{display:block;margin:0 auto;max-width:100%;}',
    }
  }
  return null
}

// Send the current preview to the printer.
function printPreview() {
  const file = previewFile.value
  if (!file) return

  // PDFs render natively in the browser's print preview.
  if (file.mime_type === 'application/pdf' && previewBlobUrl.value) {
    printBlobViaIframe(previewBlobUrl.value)
    return
  }

  const content = buildPrintContent()
  if (!content) {
    toast.error(t('driveView.printNotAvailable'))
    return
  }

  const baseStyles = `
    *{box-sizing:border-box;}
    html,body{margin:0;padding:0;}
    body{font-family:-apple-system,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;color:#111;background:#fff;line-height:1.5;padding:24px;}
    img{max-width:100%;height:auto;}
    table{border-collapse:collapse;}
    pre.text{white-space:pre-wrap;word-wrap:break-word;font-family:ui-monospace,Menlo,Consolas,monospace;font-size:12px;}
    h1,h2,h3,h4{page-break-after:avoid;}
    a{color:#111;text-decoration:underline;}
    @page{margin:16mm;}
  `

  const iframe = document.createElement('iframe')
  iframe.style.cssText = 'position:fixed;right:0;bottom:0;width:0;height:0;border:0;'
  document.body.appendChild(iframe)

  const doc = iframe.contentDocument || iframe.contentWindow.document
  doc.open()
  doc.write(`<!DOCTYPE html><html><head><meta charset="utf-8"><title>${escapeHtmlForPrint(file.original_name || file.name || 'Document')}</title><style>${baseStyles}${content.styles}</style></head><body>${content.body}</body></html>`)
  doc.close()

  const win = iframe.contentWindow
  const cleanup = () => { try { iframe.remove() } catch (e) {} }
  let hasPrinted = false
  const doPrint = () => {
    if (hasPrinted) return
    hasPrinted = true
    try {
      win.focus()
      win.onafterprint = cleanup
      win.print()
    } catch (e) {
      cleanup()
    }
    // Safety net in case onafterprint never fires.
    setTimeout(cleanup, 60000)
  }

  // Wait for any embedded images (e.g. markdown/docx) to load before printing.
  const imgs = Array.from(doc.images || [])
  const pending = imgs.filter(img => !img.complete)
  if (pending.length === 0) {
    setTimeout(doPrint, 50)
  } else {
    let remaining = pending.length
    const done = () => { if (--remaining <= 0) doPrint() }
    pending.forEach(img => { img.onload = done; img.onerror = done })
    // Don't hang forever if an image stalls.
    setTimeout(doPrint, 3000)
  }
}

// Keyboard navigation for preview
function handlePreviewKeydown(e) {
  if (!showPreview.value) return
  if ((e.ctrlKey || e.metaKey) && (e.key === 'p' || e.key === 'P') && canPrintPreview.value) {
    e.preventDefault()
    printPreview()
    return
  }
  if (e.key === 'ArrowRight') nextPreview()
  else if (e.key === 'ArrowLeft') prevPreview()
  else if (e.key === 'Escape') closePreview()
}

// Secure file URL - uses file ID only, backend verifies ownership
// For shared folders, uses the shared file endpoint
function getSecureFileUrl(file) {
  if (drive.isSharedView && drive.currentSharedFolder) {
    return `${window.location.origin}/api/drive/shared/${drive.currentSharedFolder.id}/file/${file.id}/download`
  }
  return `${window.location.origin}/api/drive/files/${file.id}/download`
}

// For Office viewer - only works with shared files (public URL)
function getOfficeViewerUrl(file) {
  if (file.share_token) {
    const shareUrl = `${window.location.origin}/api/drive/share/${file.share_token}`
    return `https://view.officeapps.live.com/op/embed.aspx?src=${encodeURIComponent(shareUrl)}`
  }
  return null
}


// Use store's thumbnail cache for persistence across component mounts
function getThumbnailUrl(file) {
  return drive.getThumbnailUrl(file)
}

// Get cached thumbnail for a file (handles shared folder context)
function getCachedThumbnail(file) {
  const sharedFolderId = drive.isSharedView ? drive.currentSharedFolder?.id : null
  const cacheKey = sharedFolderId ? `shared-${sharedFolderId}-${file.id}` : file.id
  return drive.thumbnailCache[cacheKey]
}

// Track last clicked item for shift-click range selection
const lastClickedItem = ref({ type: null, id: null, index: null })

// Get all items in order for shift-click range selection (uses FILTERED arrays)
const allItems = computed(() => {
  const items = []
  filteredFolders.value.forEach((f, i) => items.push({ type: 'folder', id: f.id, index: i }))
  filteredFiles.value.forEach((f, i) => items.push({ type: 'file', id: f.id, index: filteredFolders.value.length + i }))
  return items
})

// Recalculate anchor index from stored ID (handles stale indices after list changes)
function getAnchorIndex() {
  if (!lastClickedItem.value.id) return -1
  
  if (lastClickedItem.value.type === 'folder') {
    return filteredFolders.value.findIndex(f => f.id === lastClickedItem.value.id)
  } else {
    const fileIdx = filteredFiles.value.findIndex(f => f.id === lastClickedItem.value.id)
    return fileIdx >= 0 ? filteredFolders.value.length + fileIdx : -1
  }
}

// Click handlers - single click selects, double click opens (on mobile single tap opens folder)
function handleFolderClick(e, folder) {
  e.stopPropagation()
  // Prevent text selection on shift-click
  if (e.shiftKey) e.preventDefault()
  
  // On mobile, single tap opens the folder
  if (isMobile.value) {
    if (drive.isSharedView) {
      drive.navigateSharedSubfolder(folder.id)
    } else {
      drive.navigateToFolder(folder.id)
    }
    closeSidebar()
    return
  }
  
  // Use filtered arrays for correct index calculation
  const itemIndex = filteredFolders.value.findIndex(f => f.id === folder.id)
  
  if (e.ctrlKey || e.metaKey) {
    // Ctrl+click: toggle selection without clearing others
    drive.toggleFolderSelection(folder.id)
    lastClickedItem.value = { type: 'folder', id: folder.id, index: itemIndex }
  } else if (e.shiftKey && lastClickedItem.value.id !== null) {
    // Shift+click: range select - recalculate anchor index in case list changed
    const anchorIndex = getAnchorIndex()
    if (anchorIndex >= 0) {
      selectRange(anchorIndex, itemIndex)
    } else {
      // Anchor no longer exists, just select this item
      drive.clearSelection()
      drive.selectFolder(folder.id, true)
      lastClickedItem.value = { type: 'folder', id: folder.id, index: itemIndex }
    }
  } else {
    // Single click: clear selection and select this item
    drive.clearSelection()
    drive.selectFolder(folder.id, true)
    lastClickedItem.value = { type: 'folder', id: folder.id, index: itemIndex }
  }
}

function handleFolderDoubleClick(e, folder) {
  e.stopPropagation()
  if (drive.isSharedView) {
    drive.navigateSharedSubfolder(folder.id)
  } else {
    openFolder(folder.id)
  }
}

function handleFileClick(e, file) {
  e.stopPropagation()
  // Prevent text selection on shift-click
  if (e.shiftKey) e.preventDefault()
  
  // On mobile, open action sheet on long press / single tap
  if (isMobile.value) {
    openMobileActions(file, 'file')
    return
  }
  
  // Use filtered arrays for correct index calculation
  const itemIndex = filteredFolders.value.length + filteredFiles.value.findIndex(f => f.id === file.id)
  
  if (e.ctrlKey || e.metaKey) {
    // Ctrl+click: toggle selection without clearing others
    drive.toggleFileSelection(file.id)
    lastClickedItem.value = { type: 'file', id: file.id, index: itemIndex }
  } else if (e.shiftKey && lastClickedItem.value.id !== null) {
    // Shift+click: range select - recalculate anchor index in case list changed
    const anchorIndex = getAnchorIndex()
    if (anchorIndex >= 0) {
      selectRange(anchorIndex, itemIndex)
    } else {
      // Anchor no longer exists, just select this item
      drive.clearSelection()
      drive.selectFile(file.id, true)
      lastClickedItem.value = { type: 'file', id: file.id, index: itemIndex }
    }
  } else {
    // Single click: clear selection and select this item
    drive.clearSelection()
    drive.selectFile(file.id, true)
    lastClickedItem.value = { type: 'file', id: file.id, index: itemIndex }
  }
}

function handleFileDoubleClick(e, file) {
  e?.stopPropagation()

  // Record access for the Recent view (fire-and-forget; non-collab files only,
  // collab documents track their own access on the backend).
  if (!file.is_collab_document && file.id) {
    drive.recordFileAccess(file.id)
  }

  // Handle collab documents (they have UUIDs)
  if (file.is_collab_document) {
    openCollabDocument({
      uuid: file.uuid,
      title: file.original_name,
      type: file.collab_type
    })
    return
  }
  
  // Office is the default editor for all supported formats now
  // (docx/xlsx/pptx/md). The legacy collab editor remains the fallback
  // below when Office is unavailable.
  if (canOpenInOffice(file)) {
    openInOffice(file)
    return
  }

  // Legacy collab editor fallback (used when Office is disabled/offline)
  if (canOpenInCollab(file)) {
    openInCollabEditor(file)
    return
  }
  
  // Double click: preview/download file
  if (canPreview(file) || canUseOfficeViewer(file)) {
    openPreview(file)
  } else {
    downloadFile(file)
  }
}

// Right-click context menu for content area items
const contentContextMenu = ref({ show: false, x: 0, y: 0, item: null, type: null })
const contentMenuRef = ref(null)

function showContentContextMenu(e, item, type) {
  e.preventDefault()
  e.stopPropagation()
  contentContextMenu.value = {
    show: true,
    x: e.clientX,
    y: e.clientY,
    item: item,
    type: type
  }
  clampOpenedMenu(contentContextMenu, contentMenuRef)
}

function closeContentContextMenu() {
  contentContextMenu.value.show = false
}

// Context menu actions
function contextMenuOpen() {
  const { item, type } = contentContextMenu.value
  closeContentContextMenu()
  
  if (type === 'folder') {
    openFolder(item.id)
  } else if (item.is_collab_document) {
    // Legacy: Open collab document in editor
    openCollabDocument({
      uuid: item.uuid,
      title: item.original_name,
      type: item.collab_type
    })
  } else {
    // Spreadsheets open in the OnlyOffice editor
    if (canOpenInOffice(item) && getFileExtension(item) === 'xlsx') {
      openInOffice(item)
    } else if (canOpenInCollab(item)) {
      // Open DOCX/PPTX files in collab editor
      openInCollabEditor(item)
    } else if (canPreview(item) || canUseOfficeViewer(item)) {
      openPreview(item)
    } else {
      downloadFile(item)
    }
  }
}

// Open collab share modal from context menu
function openCollabShareFromContext() {
  const { item } = contentContextMenu.value
  if (item?.is_collab_document && item?.uuid) {
    collabDocumentId.value = item.uuid
    showCollabShareModal.value = true
  }
}

// Computed properties for context menu download
const contextMenuDownloadIcon = computed(() => {
  const { item, type } = contentContextMenu.value
  if (!item) return 'download'
  
  const hasMultiSelection = drive.selectedFiles.size + drive.selectedFolders.size > 1
  const clickedIsSelected = type === 'file' 
    ? drive.selectedFiles.has(item.id) 
    : drive.selectedFolders.has(item.id)
  
  if (hasMultiSelection && clickedIsSelected) {
    return 'folder_zip'
  }
  return type === 'folder' ? 'folder_zip' : 'download'
})

const contextMenuDownloadLabel = computed(() => {
  const { item, type } = contentContextMenu.value
  if (!item) return t('driveView.download')
  
  const hasMultiSelection = drive.selectedFiles.size + drive.selectedFolders.size > 1
  const clickedIsSelected = type === 'file' 
    ? drive.selectedFiles.has(item.id) 
    : drive.selectedFolders.has(item.id)
  
  if (hasMultiSelection && clickedIsSelected) {
    const count = drive.selectedFiles.size + drive.selectedFolders.size
    return t('driveView.downloadItemsCount', count, { count })
  }
  return type === 'folder' ? t('driveView.downloadAsZip') : t('driveView.download')
})

function contextMenuDownload() {
  const { item, type } = contentContextMenu.value
  closeContentContextMenu()
  
  // Check if we have multi-selection and the clicked item is part of it
  const hasMultiSelection = drive.selectedFiles.size + drive.selectedFolders.size > 1
  const clickedIsSelected = type === 'file' 
    ? drive.selectedFiles.has(item.id) 
    : drive.selectedFolders.has(item.id)
  
  if (hasMultiSelection && clickedIsSelected) {
    // Download all selected items as ZIP
    downloadSelectedAsZip()
  } else if (type === 'file') {
    downloadFile(item)
  } else if (type === 'folder') {
    downloadFolderAsZip(item)
  }
}

// Download all selected files and folders as a single ZIP
const zipDebugPanelRef = ref(null)

async function downloadSelectedAsZip() {
  if (drive.selectedFiles.size === 0 && drive.selectedFolders.size === 0) {
    toast.info(t('driveView.noItemsSelected'))
    return
  }
  
  downloadingZip.value = true
  
  // Generate debug session ID (only used when debug is enabled)
  const debugSessionId = 'zip_' + Date.now()
  isDebugEnabled() && console.log('DriveView: Generated debug session ID:', debugSessionId)
  
  // Show debug panel only if debug is enabled in settings
  if (isDebugEnabled() && zipDebugPanelRef.value) {
    isDebugEnabled() && console.log('DriveView: Setting debug session and showing panel')
    zipDebugPanelRef.value.setSessionId(debugSessionId)
    zipDebugPanelRef.value.show()
  }
  
  try {
    const token = getToken('webmail_token')
    const fileIds = Array.from(drive.selectedFiles)
    const folderIds = Array.from(drive.selectedFolders)
    
    isDebugEnabled() && console.log('DriveView: Starting ZIP download', { fileIds, folderIds, debugSessionId })
    
    // Build query params
    const params = new URLSearchParams()
    if (fileIds.length > 0) {
      params.append('files', fileIds.join(','))
    }
    if (folderIds.length > 0) {
      params.append('folders', folderIds.join(','))
    }
    params.append('debug_session', debugSessionId)
    
    const apiUrl = import.meta.env.VITE_API_URL || '/api'
    const downloadUrl = `${apiUrl}/drive/download-selection-zip?${params}`
    isDebugEnabled() && console.log('DriveView: Fetching from:', downloadUrl)
    
    const response = await fetch(downloadUrl, {
      headers: getAuthHeaders()
    })
    
    isDebugEnabled() && console.log('DriveView: Response status:', response.status, response.statusText)
    
    if (response.ok) {
      const blob = await response.blob()
      const blobUrl = URL.createObjectURL(blob)
      
      const itemCount = fileIds.length + folderIds.length
      const filename = itemCount === 1 ? 'Download.zip' : `${itemCount} Items.zip`
      
      const link = document.createElement('a')
      link.href = blobUrl
      link.download = filename
      document.body.appendChild(link)
      link.click()
      document.body.removeChild(link)
      
      setTimeout(() => URL.revokeObjectURL(blobUrl), 1000)
      toast.success(t('driveView.downloadCompleted'))
    } else {
      const data = await response.json().catch(() => ({}))
      toast.error(data.message || t('driveView.failedToDownload'))
    }
  } catch (e) {
    console.error('Download failed:', e)
    toast.error(t('driveView.downloadFailed'))
  } finally {
    downloadingZip.value = false
  }
}

// Download a single folder as zip (from context menu)
async function downloadFolderAsZip(folder) {
  downloadingZip.value = true
  
  try {
    const apiUrl = import.meta.env.VITE_API_URL || '/api'
    const response = await fetch(`${apiUrl}/drive/download-zip?folder=${folder.id}`, {
      headers: getAuthHeaders()
    })
    
    if (response.ok) {
      const blob = await response.blob()
      const blobUrl = URL.createObjectURL(blob)
      
      const link = document.createElement('a')
      link.href = blobUrl
      link.download = `${folder.name}.zip`
      document.body.appendChild(link)
      link.click()
      document.body.removeChild(link)
      
      setTimeout(() => URL.revokeObjectURL(blobUrl), 1000)
      toast.success(t('driveView.folderDownloadedSuccessfully'))
    } else {
      const data = await response.json().catch(() => ({}))
      toast.error(data.message || t('driveView.failedToDownloadFolder'))
    }
  } catch (e) {
    console.error('Download failed:', e)
    toast.error(t('driveView.downloadFailed'))
  } finally {
    downloadingZip.value = false
  }
}

// Create archive and save to Drive (with 1GB splitting)
async function createArchiveToDrive() {
  showDownloadMenu.value = false
  creatingArchive.value = true
  
  try {
    const response = await api.post('/drive/create-archive', {
      folder_id: drive.currentFolderId || null
    })
    
    if (response.data.success) {
      const data = response.data.data
      const partsCount = data.parts_count || 1
      
      if (partsCount === 1) {
        toast.success(t('driveView.archiveCreatedInDownloadsFolder'))
      } else {
        toast.success(t('driveView.archiveSplitIntoPartsInDownloadsFolder', partsCount, { count: partsCount }))
      }
      
      // Refresh the current view to show the new files if we're in root or Downloads
      await drive.fetchContents(drive.currentFolderId)
    } else {
      toast.error(response.data.message || t('driveView.failedToCreateArchive'))
    }
  } catch (e) {
    console.error('Archive creation failed:', e)
    toast.error(e.response?.data?.message || t('driveView.failedToCreateArchive'))
  } finally {
    creatingArchive.value = false
  }
}

function contextMenuShare() {
  const { item, type } = contentContextMenu.value
  closeContentContextMenu()
  
  if (type === 'file') {
    openShareModal(item, 'file')
  } else {
    // Share folder
    openShareFolderModal(item)
  }
}

function contextMenuRename() {
  const { item, type } = contentContextMenu.value
  closeContentContextMenu()
  startRename(item, type)
}

function contextMenuDelete() {
  const { item, type } = contentContextMenu.value
  closeContentContextMenu()
  confirmDelete(item, type)
}

function contextMenuProperties() {
  const { item, type } = contentContextMenu.value
  closeContentContextMenu()
  openPropertiesPanel(item, type)
}

function contextMenuCut() {
  const { item, type } = contentContextMenu.value
  ensureItemSelected(item, type)
  drive.clipboardCut()
  closeContentContextMenu()
  toast.success(t('driveView.itemsCut', { count: drive.selectionCount }))
}

function contextMenuCopy() {
  const { item, type } = contentContextMenu.value
  ensureItemSelected(item, type)
  drive.clipboardCopy()
  closeContentContextMenu()
  toast.success(t('driveView.itemsCopied', { count: drive.selectionCount }))
}

async function contextMenuPaste() {
  closeContentContextMenu()
  const targetId = drive.currentFolderId
  const mode = drive.clipboard.mode
  const results = await drive.clipboardPaste(targetId)
  if (results.success > 0) {
    toast.success(t(mode === 'cut' ? 'driveView.itemsMoved' : 'driveView.itemsPasted', { count: results.success }))
  }
  if (results.failed > 0) {
    toast.error(t('driveView.operationPartialFail', { failed: results.failed }))
  }
}

function contextMenuMoveTo() {
  const { item, type } = contentContextMenu.value
  ensureItemSelected(item, type)
  closeContentContextMenu()
  moveSelectedToFolder()
}

function ensureItemSelected(item, type) {
  if (!item) return
  const isAlreadySelected = type === 'folder'
    ? drive.isFolderSelected(item.id)
    : drive.isFileSelected(item.id)
  if (!isAlreadySelected || drive.selectionCount === 0) {
    drive.clearSelection()
    if (type === 'folder') {
      drive.selectFolder(item.id)
    } else {
      drive.selectFile(item.id)
    }
  }
}

const bgContextMenu = ref({ show: false, x: 0, y: 0 })
const bgMenuRef = ref(null)

function showBackgroundContextMenu(e) {
  if (!drive.hasClipboard) return
  e.preventDefault()
  bgContextMenu.value = { show: true, x: e.clientX, y: e.clientY }
  clampOpenedMenu(bgContextMenu, bgMenuRef)
}

function closeBgContextMenu() {
  bgContextMenu.value.show = false
}

async function bgContextMenuPaste() {
  closeBgContextMenu()
  const mode = drive.clipboard.mode
  const results = await drive.clipboardPaste(drive.currentFolderId)
  if (results.success > 0) {
    toast.success(t(mode === 'cut' ? 'driveView.itemsMoved' : 'driveView.itemsPasted', { count: results.success }))
  }
  if (results.failed > 0) {
    toast.error(t('driveView.operationPartialFail', { failed: results.failed }))
  }
}

const isContextItemInSelection = computed(() => {
  const { item, type } = contentContextMenu.value
  if (!item) return false
  return type === 'folder'
    ? drive.isFolderSelected(item.id)
    : drive.isFileSelected(item.id)
})

const contextMenuBulkCount = computed(() => {
  if (isContextItemInSelection.value && drive.selectionCount > 1) {
    return drive.selectionCount
  }
  return 1
})

// Keyboard shortcuts
async function handleKeyDown(e) {
  // Ignore keyboard shortcuts when user is typing in an input
  if (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA' || e.target.isContentEditable) {
    return
  }
  
  // Ctrl+A: select all
  if ((e.ctrlKey || e.metaKey) && e.key === 'a') {
    e.preventDefault()
    drive.selectAll()
  }

  // Ctrl+X: cut
  if ((e.ctrlKey || e.metaKey) && e.key === 'x' && drive.selectionCount > 0) {
    e.preventDefault()
    drive.clipboardCut()
    toast.success(t('driveView.itemsCut', { count: drive.selectionCount }))
  }

  // Ctrl+C: copy
  if ((e.ctrlKey || e.metaKey) && e.key === 'c' && drive.selectionCount > 0) {
    e.preventDefault()
    drive.clipboardCopy()
    toast.success(t('driveView.itemsCopied', { count: drive.selectionCount }))
  }

  // Ctrl+V: paste
  if ((e.ctrlKey || e.metaKey) && e.key === 'v' && drive.hasClipboard) {
    e.preventDefault()
    const mode = drive.clipboard.mode
    const results = await drive.clipboardPaste(drive.currentFolderId)
    if (results.success > 0) {
      toast.success(t(mode === 'cut' ? 'driveView.itemsMoved' : 'driveView.itemsPasted', { count: results.success }))
    }
    if (results.failed > 0) {
      toast.error(t('driveView.operationPartialFail', { failed: results.failed }))
    }
  }

  // Delete key: delete selected items
  if (e.key === 'Delete' && drive.selectionCount > 0) {
    e.preventDefault()
    deleteSelectedItems()
  }
  
  // Escape: clear selection and clipboard indicator
  if (e.key === 'Escape') {
    drive.clearSelection()
    closeContentContextMenu()
  }
}

// Unified Share Modal - opens the single, app-wide modal via the store
function openShareModal(item, type = 'file') {
  if (!item?.id) return
  shareModal.open(item, type, {
    defaultTab: 'link',
    onUpdated: () => drive.fetchContents(drive.currentFolderId),
  })
}

// Folder sharing flows through the same unified modal
function openShareFolderModal(folder) {
  openShareModal(folder, 'folder')
}

// Shared with me functionality
const showAllSharedFolders = ref(false)
const groupSharedByOwner = ref(false)

// Group shared folders by owner email
const sharedFoldersGroupedByOwner = computed(() => {
  const grouped = {}
  for (const folder of drive.sharedWithMe) {
    const owner = folder.owner_email || 'Unknown'
    if (!grouped[owner]) {
      grouped[owner] = []
    }
    grouped[owner].push(folder)
  }
  return grouped
})

// Click handler for shared folders in sidebar
function openSharedFolder(folder) {
  drive.enterSharedFolder(folder)
  closeSidebar() // Close mobile sidebar if open
}

// Click handler for files shared directly with me (person/group file share):
// office-editable files open in the editor, everything else downloads.
function openSharedFile(file) {
  closeSidebar()
  if (canOpenInOffice(file)) {
    router.push({ name: 'office-editor', params: { fileId: String(file.id) } })
    return
  }
  window.open(`${window.location.origin}/api/drive/shared-files/${file.id}/download`, '_blank')
}

// Handle folder click when in shared view (navigate to subfolder)
function handleSharedFolderClick(e, folder) {
  e.stopPropagation()
  // Prevent text selection on shift-click
  if (e.shiftKey) e.preventDefault()
  
  if (isMobile.value) {
    drive.navigateSharedSubfolder(folder.id)
    closeSidebar()
    return
  }
  
  // Desktop: single click selects, double click opens
  // Use filtered arrays for correct index calculation
  const itemIndex = filteredFolders.value.findIndex(f => f.id === folder.id)
  
  if (e.ctrlKey || e.metaKey) {
    drive.toggleFolderSelection(folder.id)
    lastClickedItem.value = { type: 'folder', id: folder.id, index: itemIndex }
  } else if (e.shiftKey && lastClickedItem.value.id !== null) {
    // Recalculate anchor index in case list changed
    const anchorIndex = getAnchorIndex()
    if (anchorIndex >= 0) {
      selectRange(anchorIndex, itemIndex)
    } else {
      drive.clearSelection()
      drive.selectFolder(folder.id, true)
      lastClickedItem.value = { type: 'folder', id: folder.id, index: itemIndex }
    }
  } else {
    drive.clearSelection()
    drive.selectFolder(folder.id, true)
    lastClickedItem.value = { type: 'folder', id: folder.id, index: itemIndex }
  }
}

function handleSharedFolderDoubleClick(e, folder) {
  e.stopPropagation()
  drive.navigateSharedSubfolder(folder.id)
}

// Upload to shared folder
async function uploadToSharedFolder(files) {
  if (!drive.isSharedView || !drive.currentSharedFolder) return
  
  const targetFolderId = drive.currentFolderId || drive.currentSharedFolder.id
  for (const file of files) {
    await drive.uploadToSharedFolder(targetFolderId, file)
  }
  
  // Refresh
  if (targetFolderId === drive.currentSharedFolder.id) {
    await drive.enterSharedFolder(drive.currentSharedFolder)
  } else {
    await drive.navigateSharedSubfolder(targetFolderId)
  }
  toast.success(t('driveView.uploadComplete'))
}

// Select range of items (for shift+click) - uses FILTERED arrays for correct selection
function selectRange(startIndex, endIndex) {
  const start = Math.min(startIndex, endIndex)
  const end = Math.max(startIndex, endIndex)
  
  // Clear existing selection and select range
  drive.clearSelection()
  
  // Use filtered arrays to match what's displayed on screen
  const folders = filteredFolders.value
  const files = filteredFiles.value
  
  for (let i = start; i <= end; i++) {
    if (i < folders.length) {
      drive.selectFolder(folders[i].id, true)
    } else {
      const fileIndex = i - folders.length
      if (fileIndex < files.length) {
        drive.selectFile(files[fileIndex].id, true)
      }
    }
  }
}

function openFolder(folderId) {
  isDebugEnabled() && console.log('[Drive] Opening folder:', folderId)
  drive.clearSelection()
  lastClickedItem.value = { type: null, id: null, index: null }
  drive.navigateToFolder(folderId)
}

// Bulk operations
const showMoveModal = ref(false)

function deleteSelectedItems() {
  // Use the proper modal confirmation
  isBulkDelete.value = true
  deleteTarget.value = { 
    item: null, 
    type: 'bulk',
    count: drive.selectionCount,
    hasFolder: drive.selectedFolders.size > 0
  }
  deleteConfirmText.value = ''
  showDeleteConfirm.value = true
}

// Toolbar download: single file downloads directly, anything else goes as a zip
function downloadSelection() {
  if (drive.selectedFiles.size === 1 && drive.selectedFolders.size === 0) {
    const id = [...drive.selectedFiles][0]
    const file = drive.files.find(f => f.id === id)
    if (file) {
      downloadFile(file)
      return
    }
  }
  downloadSelectedAsZip()
}

function moveSelectedToFolder() {
  showMoveModal.value = true
}

async function confirmMoveToFolder(targetFolderId) {
  showMoveModal.value = false
  
  const results = await drive.moveSelected(targetFolderId)
  
  if (results.success > 0) {
    toast.success(t('driveView.movedItemsCount', results.success, { count: results.success }))
  }
  if (results.failed > 0) {
    toast.error(t('driveView.failedToMoveItemsCount', results.failed, { count: results.failed }))
  }
}

// Lightweight sync events polling (much more efficient than full file polling)
const SYNC_EVENTS_POLL_INTERVAL = 10000 // 10 seconds - lightweight
const FULL_REFRESH_INTERVAL = 60000 // 60 seconds - backup full refresh (rare)
const EDITING_STATUS_POLL_INTERVAL = 15000 // 15 seconds for editing status
let syncEventsTimer = null
let fullRefreshTimer = null
let editingStatusTimer = null
let editingDurationTimer = null // Timer to increment local editing duration every second
const lastEventTimestamp = ref(Math.floor(Date.now() / 1000))
let rateLimitBackoff = 0 // Exponential backoff counter for 429 responses

// Active editors tracking
const activeEditors = ref({}) // Map of fileId -> { user_email, started_at, editing_duration }

// Check for sync events (lightweight - just returns event IDs)
async function checkForSyncEvents() {
  if (drive.isTrashView || drive.isSharedView) return
  
  // Skip if we're in a backoff period from rate limiting
  if (rateLimitBackoff > 0) {
    rateLimitBackoff--
    isDebugEnabled() && console.log(`[Drive] Rate limit backoff, skipping (${rateLimitBackoff} cycles left)`)
    return
  }
  
  try {
    const response = await api.get('/drive/sync-events', {
      params: { since: lastEventTimestamp.value }
    })
    
    if (response.data.success) {
      const events = response.data.data.events || []
      const serverTime = response.data.data.server_time
      
      if (events.length > 0) {
        isDebugEnabled() && console.log('[Drive] Sync events detected:', events.length)
        await drive.fetchContents(drive.currentFolderId, { force: true, quiet: true })
      }
      
      if (serverTime) {
        lastEventTimestamp.value = serverTime
      }
    }
    
  } catch (error) {
    if (error?.response?.status === 429) {
      // Exponential backoff: skip next 3, 6, 12... poll cycles
      rateLimitBackoff = Math.min(rateLimitBackoff > 0 ? rateLimitBackoff * 2 : 3, 30)
      console.warn(`[Drive] Rate limited (429), backing off for ${rateLimitBackoff} cycles`)
    } else {
      isDebugEnabled() && console.warn('[Drive] Sync events check failed:', error?.message)
    }
  }
}

// Check who is currently editing files in the folder
async function checkEditingStatus() {
  // Share the same backoff as sync events
  if (rateLimitBackoff > 0) return
  
  try {
    const response = await api.get('/drive/editing-status', {
      params: { folder_id: drive.currentFolderId || '' }
    })
    
    if (response.data.success) {
      const editors = response.data.data?.editors || response.data.data || []
      
      if (editors.length > 0) {
        isDebugEnabled() && console.log('[Drive] Active editors:', editors)
      }
      
      const newEditors = {}
      
      editors.forEach(editor => {
        const key = editor.filename || editor.file_id
        newEditors[key] = {
          user_email: editor.user_email,
          started_at: editor.started_at,
          filename: editor.filename,
          is_self: editor.is_self === 1 || editor.is_self === '1' || editor.is_self === true
        }
      })
      
      if (Object.keys(newEditors).length > 0) {
        isDebugEnabled() && console.log('[Drive] Editing status keys:', Object.keys(newEditors))
        isDebugEnabled() && console.log('[Drive] Current files:', drive.files.map(f => f.original_name))
      }
      
      activeEditors.value = newEditors
    }
  } catch (error) {
    if (error?.response?.status === 429) {
      rateLimitBackoff = Math.min(rateLimitBackoff > 0 ? rateLimitBackoff * 2 : 3, 30)
    }
    // Silent fail - don't spam console
  }
}

// Helper to check if a file is being edited
function isFileBeingEdited(file) {
  return activeEditors.value[file.original_name] || activeEditors.value[file.id]
}

// Helper to get who is editing a file
function getFileEditor(file) {
  const editor = activeEditors.value[file.original_name] || activeEditors.value[file.id]
  return editor?.user_email || null
}

// Stop editing a file (clear editing status)
async function stopEditingFile(file) {
  if (!file) return
  
  try {
    await api.delete('/drive/editing-status', {
      data: {
        filename: file.original_name,
        folder_id: file.folder_id || drive.currentFolderId
      }
    })
    
    // Remove from local active editors immediately
    const key = file.original_name || file.id
    delete activeEditors.value[key]
    activeEditors.value = { ...activeEditors.value }
    
    toast.success(t('driveView.editingStatusCleared'))
    
    // Refresh editing status
    await checkEditingStatus()
  } catch (error) {
    console.error('Failed to stop editing:', error)
    toast.error(t('driveView.failedToClearEditingStatus'))
  }
}

// Start lightweight events polling
function startSyncEventsPolling() {
  stopSyncEventsPolling() // Clear any existing timers
  
  lastEventTimestamp.value = Math.floor(Date.now() / 1000)
  rateLimitBackoff = 0 // Reset backoff on fresh start
  
  // Run first check immediately
  checkForSyncEvents()
  
  // Then poll every interval for sync events (lightweight)
  syncEventsTimer = setInterval(checkForSyncEvents, SYNC_EVENTS_POLL_INTERVAL)
  
  // Poll for editing status on a separate (slower) timer
  editingStatusTimer = setInterval(checkEditingStatus, EDITING_STATUS_POLL_INTERVAL)
  
  // Increment editing duration locally every second for smooth counter display
  editingDurationTimer = setInterval(() => {
    let updated = false
    for (const key in activeEditors.value) {
      if (activeEditors.value[key].editing_duration !== undefined) {
        activeEditors.value[key].editing_duration++
        updated = true
      }
    }
    if (updated) {
      activeEditors.value = { ...activeEditors.value }
    }
  }, 1000)
  
  // Full folder refresh as a rare backup (only if sync events missed something)
  fullRefreshTimer = setInterval(async () => {
    if (!drive.isTrashView && !drive.isSharedView && rateLimitBackoff === 0) {
      isDebugEnabled() && console.log('[Drive] Periodic refresh')
      await drive.fetchContents(drive.currentFolderId, { force: true, quiet: true })
    }
  }, FULL_REFRESH_INTERVAL)
  
  isDebugEnabled() && console.log('[Drive] Sync polling started (events: 10s, editing: 15s, full refresh: 60s)')
}

// Stop events polling
function stopSyncEventsPolling() {
  if (syncEventsTimer) {
    clearInterval(syncEventsTimer)
    syncEventsTimer = null
  }
  if (fullRefreshTimer) {
    clearInterval(fullRefreshTimer)
    fullRefreshTimer = null
  }
  if (editingStatusTimer) {
    clearInterval(editingStatusTimer)
    editingStatusTimer = null
  }
  if (editingDurationTimer) {
    clearInterval(editingDurationTimer)
    editingDurationTimer = null
  }
}

// ============================================================
// Real-time sync via WebSocket (instant cross-device updates)
//
// The HTTP polling above is the fallback. When the mailsync WebSocket is
// connected, DRIVE_* events published by the backend (on this user's OTHER
// devices/tabs) arrive here and trigger an immediate refresh - no waiting
// for the 10s/60s poll, and it works even in Shared/Trash views.
// ============================================================
let driveEventUnsubscribers = []
let driveRealtimeRefreshTimer = null

// Coalesce bursts (e.g. a 20-file bulk upload emits 20 events) into one refetch.
function scheduleRealtimeRefresh(refreshTree = false) {
  if (driveRealtimeRefreshTimer) clearTimeout(driveRealtimeRefreshTimer)
  driveRealtimeRefreshTimer = setTimeout(async () => {
    driveRealtimeRefreshTimer = null
    try {
      await drive.fetchContents(drive.currentFolderId, { force: true, quiet: true })
      if (refreshTree) fetchAllFolders()
    } catch (e) {
      isDebugEnabled() && console.warn('[Drive] Realtime refresh failed:', e?.message)
    }
  }, 400)
}

// True when an event's folder_id refers to the folder currently on screen.
// Drive root is represented as null on the client and may arrive as null/0/'' .
function eventTargetsCurrentFolder(payload) {
  const current = drive.currentFolderId ?? null
  let target = payload?.folder_id
  if (target === undefined || target === null || target === '' || target === 0 || target === '0') {
    target = null
  } else {
    target = Number(target)
  }
  return target === current
}

function handleDriveFileEvent(payload) {
  if (eventTargetsCurrentFolder(payload)) {
    scheduleRealtimeRefresh(false)
  }
}

function handleDriveFolderEvent() {
  // A folder event's folder_id is the created/deleted folder itself (not its
  // parent), so we can't cheaply tell whether it belongs to the current view.
  // Refresh both the listing and the sidebar tree (debounced/coalesced).
  scheduleRealtimeRefresh(true)
}

function startDriveRealtimeSync() {
  stopDriveRealtimeSync()
  driveEventUnsubscribers = [
    onMailSync(EventTypes.DRIVE_FILE_CREATED, handleDriveFileEvent),
    onMailSync(EventTypes.DRIVE_FILE_UPDATED, handleDriveFileEvent),
    onMailSync(EventTypes.DRIVE_FILE_DELETED, handleDriveFileEvent),
    onMailSync(EventTypes.DRIVE_FOLDER_CREATED, handleDriveFolderEvent),
    onMailSync(EventTypes.DRIVE_FOLDER_DELETED, handleDriveFolderEvent),
  ]
  isDebugEnabled() && console.log('[Drive] Realtime WebSocket sync started')
}

function stopDriveRealtimeSync() {
  driveEventUnsubscribers.forEach(unsub => {
    try { unsub && unsub() } catch (e) { /* ignore */ }
  })
  driveEventUnsubscribers = []
  if (driveRealtimeRefreshTimer) {
    clearTimeout(driveRealtimeRefreshTimer)
    driveRealtimeRefreshTimer = null
  }
}

onMounted(async () => {
  checkMobile()
  window.addEventListener('resize', checkMobile)
  window.addEventListener('click', handleClickOutside)
  
  // Load collaborative documents
  refreshCollabDocuments()
  
  // Load chat-shared file/folder IDs (non-blocking)
  drive.fetchChatSharedIds()

  // Check OnlyOffice availability (non-blocking; office actions stay hidden when off)
  ensureOfficeStatus()

  // Paint the sidebar folder tree INSTANTLY from any already-cached folder
  // list (e.g. when re-entering Drive), so it never waits on the network.
  if (drive.allFolders.length > 0 && allFolders.value.length === 0) {
    allFolders.value = drive.allFolders
  }

  // Fire the independent data loads up-front, in PARALLEL, so each section
  // paints as soon as its OWN request returns. Previously these were awaited
  // one-after-another, which left the left sidebar's folder tree waiting behind
  // the clients -> contents chain even though its data was ready first (and it
  // was then fetched a second time at the very end of mount).
  const foldersPromise = fetchAllFolders()          // -> sidebar tree + drive.allFolders
  const sharedPromise = drive.fetchSharedWithMe()   // needed only to restore a shared view
  const clientsPromise = clientsStore.clients.length === 0
    ? clientsStore.fetchClients().catch(() => {})
    : Promise.resolve()

  // The folder->client map only feeds the (non-critical) client column, so let
  // it resolve in the background once both inputs are ready instead of blocking
  // first paint. A reactive watch also keeps it in sync afterwards.
  Promise.all([clientsPromise, foldersPromise])
    .then(() => loadFolderClientMap())
    .catch(() => {})

  // Check for URL parameters to restore view state
  const viewParam = route.query.view
  const folderId = route.query.folder ? parseInt(route.query.folder) : null
  const sharedId = route.query.shared ? parseInt(route.query.shared) : null
  const fileId = route.query.file ? parseInt(route.query.file) : null

  if (viewParam === 'trash') {
    // Restore trash view
    drive.enterTrashView()
  } else if (viewParam === 'sharing') {
    // Restore sharing & access view
    drive.enterSharingAccessView()
  } else if (viewParam === 'starred') {
    drive.enterStarredView()
  } else if (viewParam === 'recent') {
    drive.enterRecentView()
  } else if (viewParam === 'shared' && sharedId) {
    // Restore shared folder view (this branch is the only one that needs the
    // shared-with-me list, so wait for the in-flight parallel fetch here).
    await sharedPromise
    const sharedFolder = drive.sharedWithMe.find(f => f.id === sharedId)
    if (sharedFolder) {
      await drive.enterSharedFolder(sharedFolder)
    } else {
      // Shared folder not found, go to root
      await drive.fetchContents(null, { quiet: true })
    }
  } else if (folderId) {
    // Navigate to the specified folder (force fetch for deep links)
    await drive.fetchContents(folderId, { force: true })
  } else {
    // Use cached data if available, otherwise fetch
    await drive.fetchContents(null, { quiet: true })
  }

  // Recalculate folder sizes in background (once per session). Runs AFTER the
  // target folder has loaded so the follow-up refresh re-fetches the folder the
  // user actually opened - never the root. Previously this could resolve while
  // currentFolderId was still null, briefly flashing root files on a deep-link
  // refresh before jumping to the opened folder.
  if (!sessionStorage.getItem('drive_sizes_recalculated')) {
    const recalcFolderId = drive.currentFolderId
    api.post('/drive/recalculate-sizes').then(() => {
      sessionStorage.setItem('drive_sizes_recalculated', '1')
      // Only refresh if the user is still viewing the same folder.
      if (drive.currentFolderId === recalcFolderId) {
        drive.fetchContents(recalcFolderId, { force: true })
      }
    }).catch(() => {})
  }

  // Start lightweight sync events polling (fallback when WS is down)
  startSyncEventsPolling()
  // Start instant cross-device sync over the mailsync WebSocket
  startDriveRealtimeSync()
  
  // If a specific file was requested, open its preview
  if (fileId) {
    const file = drive.files.find(f => f.id === fileId)
    if (file) {
      openPreview(file)
    }
  }
  
  // Only clear the file param after opening preview (keep folder in URL for refresh)
  if (fileId) {
    const { file, ...restQuery } = route.query
    router.replace({ query: restQuery })
  }
  
  // Handle document/presentation routes (for deep linking and refresh)
  if (route.name === 'drive-document' || route.name === 'drive-presentation') {
    const docUuid = route.params.uuid
    if (docUuid) {
      // Restore folder context from the URL so closing the editor returns the
      // user to the folder the doc was opened from (instead of falling back to
      // the Drive root after a refresh).
      const queryFolder = route.query.folder ? parseInt(route.query.folder) : null
      if (queryFolder && !Number.isNaN(queryFolder)) {
        collabReturnFolderId.value = queryFolder
        if (drive.currentFolderId !== queryFolder) {
          await drive.fetchContents(queryFolder, { force: true }).catch(() => {})
        }
      }

      // Wait for collab documents to load
      await refreshCollabDocuments()
      // Find the document
      const doc = collabStore.documents.find(d => d.uuid === docUuid)
      if (doc) {
        collabDocumentId.value = doc.uuid
        collabDocumentTitle.value = doc.title
        collabEditorMode.value = doc.type
        openingPresentationEditor.value = doc.type === 'presentation'
        showCollabEditor.value = true
      } else {
        // Document not found, try to load it directly
        try {
          const response = await api.get(`/collab/documents/${docUuid}`)
          if (response.data.success && response.data.data) {
            const loadedDoc = response.data.data
            collabDocumentId.value = loadedDoc.uuid
            collabDocumentTitle.value = loadedDoc.title
            collabEditorMode.value = loadedDoc.type
            openingPresentationEditor.value = loadedDoc.type === 'presentation'
            showCollabEditor.value = true
          } else {
            toast.error(t('driveView.documentNotFound'))
            router.replace({ name: 'drive' })
          }
        } catch (e) {
          console.error('Failed to load document:', e)
          toast.error(t('driveView.failedToLoadDocument'))
          router.replace({ name: 'drive' })
        }
      }
    }
  }
  
  // Handle folder route
  if (route.name === 'drive-folder' && route.params.folderId) {
    const folderId = parseInt(route.params.folderId)
    if (folderId && folderId !== drive.currentFolderId) {
      await drive.fetchContents(folderId, { force: true })
    }
  }
  
  // Add keyboard listener for preview navigation
  window.addEventListener('keydown', handlePreviewKeydown)
  // Add keyboard listener for Ctrl+A, Delete, Escape
  window.addEventListener('keydown', handleKeyDown)
  // Close context menus on click outside
  window.addEventListener('click', closeContextMenu)
  window.addEventListener('click', closeContentContextMenu)
  window.addEventListener('click', closeBgContextMenu)
})

// Watch for file query param changes (navigating to drive from board, client, etc.)
// Folder navigation is handled by our own updateDriveUrl(), so we only watch for external changes
watch(() => route.query.file, async (fileId) => {
  if (fileId) {
    const file = drive.files.find(f => f.id === parseInt(fileId))
    if (file) {
      openPreview(file)
    }
    // Clear the file param after opening
    const { file: _, ...restQuery } = route.query
    router.replace({ query: restQuery })
  }
})

// Watch for document/presentation route changes
watch(() => [route.name, route.params.uuid], async ([routeName, uuid]) => {
  // Handle navigating to a document/presentation
  if ((routeName === 'drive-document' || routeName === 'drive-presentation') && uuid) {
    // Capture folder context from the URL so close returns to the right folder.
    const queryFolder = route.query.folder ? parseInt(route.query.folder) : null
    if (queryFolder && !Number.isNaN(queryFolder)) {
      collabReturnFolderId.value = queryFolder
    }

    // Only open if not already open
    if (collabDocumentId.value !== uuid) {
      const doc = collabStore.documents.find(d => d.uuid === uuid)
      if (doc) {
        collabDocumentId.value = doc.uuid
        collabDocumentTitle.value = doc.title
        collabEditorMode.value = doc.type
        openingPresentationEditor.value = doc.type === 'presentation'
        showCollabEditor.value = true
      } else {
        // Try to load directly
        try {
          const response = await api.get(`/collab/documents/${uuid}`)
          if (response.data.success && response.data.data) {
            const loadedDoc = response.data.data
            collabDocumentId.value = loadedDoc.uuid
            collabDocumentTitle.value = loadedDoc.title
            collabEditorMode.value = loadedDoc.type
            openingPresentationEditor.value = loadedDoc.type === 'presentation'
            showCollabEditor.value = true
          }
        } catch (e) {
          console.error('Failed to load document:', e)
        }
      }
    }
  }
  // Handle navigating back to drive (closing document)
  else if ((routeName === 'drive' || routeName === 'drive-folder') && showCollabEditor.value) {
    showCollabEditor.value = false
    collabDocumentId.value = null
    collabDocumentTitle.value = ''
    collabDriveFileId.value = null
  }
})

// Reset event timestamp and selection anchor when navigating to different folder
watch(() => drive.currentFolderId, () => {
  lastEventTimestamp.value = Math.floor(Date.now() / 1000)
  // Reset selection anchor to prevent stale index issues
  lastClickedItem.value = { type: null, id: null, index: null }
})

onUnmounted(() => {
  // Stop sync events polling
  stopSyncEventsPolling()
  // Stop instant cross-device WebSocket sync
  stopDriveRealtimeSync()
  
  // Stop tracking timer
  stopTrackingTimer()
  
  window.removeEventListener('resize', checkMobile)
  window.removeEventListener('keydown', handlePreviewKeydown)
  window.removeEventListener('keydown', handleKeyDown)
  window.removeEventListener('click', closeContextMenu)
  window.removeEventListener('click', closeContentContextMenu)
  window.removeEventListener('click', closeBgContextMenu)
  window.removeEventListener('click', handleClickOutside)
  
  // Cleanup blob URLs
  if (previewBlobUrl.value) {
    URL.revokeObjectURL(previewBlobUrl.value)
  }
  // Note: Thumbnail cache is now in the store for persistence
  // Don't clear it on component unmount - only clear on logout
})
</script>

<template>
  <div class="h-[100dvh] bg-surface-50 dark:bg-surface-900 flex flex-col ambient-tint" :class="isMobile ? 'overflow-y-auto pb-20' : 'overflow-hidden'">
    <!-- Header -->
    <AppHeader
      current-view="drive"
      icon="cloud"
      :title="$t('driveView.drive')"
      :show-mobile-menu="isMobile"
      @toggle-sidebar="toggleSidebar"
    />

    <!-- Drive sub-header (file-manager style): spans the FULL width above the
         sidebar + content, like a desktop file manager. Row 1 = back/forward/up
         + path bar + search; Row 2 = New/Upload + selection actions + views. -->
    <DriveSubHeader
      :uploading="drive.uploading"
      :upload-progress="drive.uploadProgress"
      :hide-upload="drive.isSharedView && !drive.canEditSharedFolder"
      :office-enabled="officeEnabled"
      :search-query="searchQuery"
      :active-filter-count="activeFilterCount"
      @update:search-query="searchQuery = $event"
      @toggle-filters="toggleSearchFiltersAnchored"
      @clear-search="clearSearch"
      @upload="triggerUpload"
      @new-folder="openNewFolderModal(null)"
      @new-office="createOfficeFile"
      @share-selection="({ item, type }) => type === 'folder' ? openShareFolderModal(item) : openShareModal(item, 'file')"
      @rename-selection="({ item, type }) => startRename(item, type)"
      @properties-selection="({ item, type }) => openPropertiesPanel(item, type)"
      @delete-selection="deleteSelectedItems"
      @download-selection="downloadSelection"
      @move-selection="moveSelectedToFolder"
      @copy-selection="drive.clipboardCopy()"
      @create-archive="createArchiveToDrive"
      @download-all="downloadAllDrive"
      @download-current-folder="downloadCurrentFolderAsZip"
      @download-selected="downloadSelectedAsZip"
      @download-desktop-app="openDesktopAppDownload"
      @open-sharing-access="drive.enterSharingAccessView()"
    >
      <template #trailing>
        <HowItWorksButton @click="showStepGuide = true" />
        <HowItWorksButton variant="features" :active="showFeatureGuide" @click="showFeatureGuide = !showFeatureGuide" />
      </template>
    </DriveSubHeader>

    <!-- Main content area -->
    <div class="flex-1 flex overflow-hidden relative">
      <!-- Mobile sidebar overlay -->
      <div 
        v-if="isMobile"
        class="sidebar-overlay"
        :class="{ 'open': sidebarOpen }"
        @click="closeSidebar"
      ></div>
      
      <!-- Sidebar - Drive navigation + folder tree -->
      <DriveSidebar
        :open="sidebarOpen"
        :is-mobile="isMobile"
        :folder-tree="folderTree"
        :tree-expanded="treeExpanded"
        :drag-over-folder="dragOverSidebarFolder"
        :drag-over-position="dragOverSidebarPosition"
        :dragging-folder="draggingSidebarFolder"
        :get-folder-color="getFolderColor"
        :group-shared-by-owner="groupSharedByOwner"
        :show-all-shared-folders="showAllSharedFolders"
        :shared-folders-grouped-by-owner="sharedFoldersGroupedByOwner"
        class="drive-sidebar-host"
        :class="{ 'open': sidebarOpen }"
        @close="closeSidebar"
        @navigate-root="drive.exitSharingAccessView(); drive.isSharedView ? drive.exitSharedView() : null; drive.navigateToRoot()"
        @navigate-shared="drive.fetchSharedWithMe()"
        @navigate-recent="drive.enterRecentView()"
        @navigate-starred="drive.enterStarredView()"
        @navigate-trash="drive.enterTrashView()"
        @tree-select="selectFolder"
        @tree-toggle="toggleTreeNode"
        @tree-create-subfolder="openNewFolderModal"
        @tree-context-menu="(e, folder) => showFolderContextMenu(e, folder)"
        @tree-drag-start="(e, folder) => onSidebarFolderDragStart(e, folder)"
        @tree-drag-end="onSidebarFolderDragEnd"
        @tree-drag-over-folder="(e, folder, depth) => onSidebarFolderDragOver(e, folder, depth)"
        @tree-drag-leave-folder="onSidebarFolderDragLeave"
        @tree-drop-on-folder="(e, folder) => onSidebarFolderDrop(e, folder)"
        @tree-touch-start="(e, folder) => handleSidebarTouchStart(e, folder)"
        @tree-touch-move="handleTouchMove"
        @tree-touch-end="handleTouchEnd"
        @root-drag-over="onSidebarRootDragOver"
        @root-drag-leave="onSidebarRootDragLeave"
        @root-drop="onSidebarRootDrop"
        @open-shared-folder="openSharedFolder"
        @open-shared-file="openSharedFile"
        @toggle-group-shared="groupSharedByOwner = !groupSharedByOwner"
        @toggle-show-all-shared="showAllSharedFolders = !showAllSharedFolders"
      />

      
      <!-- Main content (transparent: table area blends with the page bg in both themes) -->
      <main class="flex-1 flex flex-col overflow-hidden bg-transparent">
        <!-- Hidden file input used by triggerUpload() -->
        <input
          ref="fileInput"
          type="file"
          multiple
          class="hidden"
          @change="handleFileSelect"
        />
        <!-- Legacy desktop search bar block - visible chrome hidden, but the
             block stays mounted so the Teleported filter popup (rendered to
             body via Teleport) keeps working until that's extracted too. -->
        <div class="hidden">
          <div class="flex items-center gap-3">
            <!-- Activity Log button (kept for accessibility -- can still be triggered programmatically) -->
            <button
              @click="openActivityLogPanel"
              class="btn-secondary btn-icon py-2.5"
              :title="$t('driveView.activityLog')"
            >
              <span class="material-symbols-rounded">history</span>
            </button>
            
            <!-- Filter Panel (Draggable, Teleported) -->
            <Teleport to="body">
              <Transition name="dropdown">
                <div 
                  v-if="showSearchFilters"
                  class="fixed z-[100] w-[90vw] max-w-[420px] bg-white dark:bg-surface-800 rounded-xl shadow-2xl border border-surface-200 dark:border-surface-700"
                  :style="filterPopupPos.x !== null ? { left: filterPopupPos.x + 'px', top: filterPopupPos.y + 'px' } : { top: '100px', left: '50%', transform: 'translateX(-50%)' }"
                >
                  <!-- Draggable Header -->
                  <div 
                    @mousedown="startDragFilter"
                    class="flex items-center justify-between px-3 py-2 border-b border-surface-200 dark:border-surface-700 cursor-move select-none bg-surface-50 dark:bg-surface-900 rounded-t-xl"
                  >
                    <h4 class="text-sm font-semibold text-surface-800 dark:text-surface-100 flex items-center gap-1.5">
                      <span class="material-symbols-rounded text-primary-500 text-lg">filter_alt</span>
                      {{ $t('driveView.filterFiles') }}
                      <span class="material-symbols-rounded text-surface-400 text-xs hidden sm:inline">drag_indicator</span>
                    </h4>
                    <button 
                      @click="showSearchFilters = false; resetFilterPopupPos()"
                      class="text-surface-400 hover:text-surface-600 p-1"
                    >
                      <span class="material-symbols-rounded text-lg">close</span>
                    </button>
                  </div>
                  
                  <div class="p-3 space-y-3">
                    <!-- File Type - Compact horizontal pills -->
                    <div>
                      <label class="block text-[10px] font-semibold text-surface-500 dark:text-surface-400 uppercase tracking-wider mb-1.5">
                        {{ $t('driveView.typeLabel') }}
                      </label>
                      <div class="flex flex-wrap gap-1">
                        <button
                          v-for="opt in fileTypeOptions"
                          :key="opt.value"
                          @click="searchFilters.type = searchFilters.type === opt.value ? '' : opt.value"
                          :class="[
                            'inline-flex items-center gap-1 px-2 py-1 rounded-full text-xs transition-all',
                            searchFilters.type === opt.value 
                              ? 'bg-primary-500 text-white' 
                              : 'bg-surface-100 dark:bg-surface-700 text-surface-600 dark:text-surface-300 hover:bg-surface-200 dark:hover:bg-surface-600'
                          ]"
                        >
                          <span v-if="opt.icon" class="material-symbols-rounded text-sm">{{ opt.icon }}</span>
                          <span v-else class="material-symbols-rounded text-sm">apps</span>
                          <span>{{ opt.label.split(' ')[0] }}</span>
                        </button>
                      </div>
                    </div>
                    
                    <!-- Sharing Status - Compact horizontal pills -->
                    <div>
                      <label class="block text-[10px] font-semibold text-surface-500 dark:text-surface-400 uppercase tracking-wider mb-1.5">
                        {{ $t('driveView.sharingLabel') }}
                      </label>
                      <div class="flex flex-wrap gap-1">
                        <button
                          v-for="opt in sharingOptions"
                          :key="opt.value"
                          @click="searchFilters.sharing = searchFilters.sharing === opt.value ? '' : opt.value"
                          :class="[
                            'inline-flex items-center gap-1 px-2 py-1 rounded-full text-xs transition-all',
                            searchFilters.sharing === opt.value 
                              ? 'bg-primary-500 text-white' 
                              : 'bg-surface-100 dark:bg-surface-700 text-surface-600 dark:text-surface-300 hover:bg-surface-200 dark:hover:bg-surface-600'
                          ]"
                        >
                          <span v-if="opt.icon" class="material-symbols-rounded text-sm">{{ opt.icon }}</span>
                          <span>{{ opt.label }}</span>
                        </button>
                      </div>
                    </div>
                    
                    <!-- Date Range & Size Range Row -->
                    <div class="grid grid-cols-2 gap-2">
                      <div>
                        <label class="block text-[10px] font-semibold text-surface-500 dark:text-surface-400 uppercase tracking-wider mb-1">
                          {{ $t('driveView.date') }}
                        </label>
                        <select v-model="searchFilters.dateRange" class="input w-full py-1.5 text-sm">
                          <option v-for="opt in dateRangeOptions" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
                        </select>
                      </div>
                      <div>
                        <label class="block text-[10px] font-semibold text-surface-500 dark:text-surface-400 uppercase tracking-wider mb-1">
                          {{ $t('driveView.size') }}
                        </label>
                        <select v-model="searchFilters.sizeRange" class="input w-full py-1.5 text-sm">
                          <option v-for="opt in sizeRangeOptions" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
                        </select>
                      </div>
                    </div>
                    
                    <!-- Client Filter -->
                    <div>
                      <label class="block text-[10px] font-semibold text-surface-500 dark:text-surface-400 uppercase tracking-wider mb-1">
                        {{ $t('driveView.client') }}
                      </label>
                      <select v-model="searchFilters.clientId" class="input w-full py-1.5 text-sm">
                        <option value="">{{ $t('driveView.allClients') }}</option>
                        <option v-for="client in clientsStore.clients" :key="client.id" :value="client.id">
                          {{ client.display_name || client.domain }}
                        </option>
                      </select>
                    </div>
                  </div>
                  
                  <!-- Footer -->
                  <div class="flex items-center justify-between px-3 py-2 bg-surface-50 dark:bg-surface-900 border-t border-surface-200 dark:border-surface-700 rounded-b-xl">
                    <button 
                      @click="clearFilters"
                      class="text-xs text-surface-500 hover:text-surface-700 dark:hover:text-surface-300"
                      :disabled="activeFilterCount === 0"
                    >
                      {{ $t('driveView.clear') }}
                    </button>
                    <button 
                      @click="showSearchFilters = false; resetFilterPopupPos()"
                      class="btn-primary btn-sm text-xs px-4"
                    >
                      {{ $t('driveView.apply') }}
                    </button>
                  </div>
                </div>
              </Transition>
            </Teleport>
            
            <!-- Clear all button -->
            <button 
              v-if="searchQuery || activeFilterCount > 0"
              @click="clearSearch"
              class="btn-ghost btn-sm"
            >
              {{ $t('driveView.clearAll') }}
            </button>
          </div>
          
          <!-- Active filters display -->
          <div v-if="activeFilterCount > 0" class="flex items-center gap-2 mt-2 flex-wrap">
            <span class="text-xs text-surface-500">{{ $t('driveView.activeFilters') }}</span>
            <span 
              v-if="searchFilters.type"
              class="inline-flex items-center gap-1 px-2 py-1 rounded-full text-xs bg-primary-100 dark:bg-primary-500/20 text-primary-600 dark:text-primary-400"
            >
              {{ $t('driveView.typeLabel') }}: {{ fileTypeOptions.find(o => o.value === searchFilters.type)?.label }}
              <button @click="searchFilters.type = ''" class="hover:text-primary-800">
                <span class="material-symbols-rounded text-sm">close</span>
              </button>
            </span>
            <span 
              v-if="searchFilters.sharing"
              class="inline-flex items-center gap-1 px-2 py-1 rounded-full text-xs bg-primary-100 dark:bg-primary-500/20 text-primary-600 dark:text-primary-400"
            >
              {{ sharingOptions.find(o => o.value === searchFilters.sharing)?.label }}
              <button @click="searchFilters.sharing = ''" class="hover:text-primary-800">
                <span class="material-symbols-rounded text-sm">close</span>
              </button>
            </span>
            <span 
              v-if="searchFilters.dateRange"
              class="inline-flex items-center gap-1 px-2 py-1 rounded-full text-xs bg-primary-100 dark:bg-primary-500/20 text-primary-600 dark:text-primary-400"
            >
              {{ dateRangeOptions.find(o => o.value === searchFilters.dateRange)?.label }}
              <button @click="searchFilters.dateRange = ''" class="hover:text-primary-800">
                <span class="material-symbols-rounded text-sm">close</span>
              </button>
            </span>
            <span 
              v-if="searchFilters.sizeRange"
              class="inline-flex items-center gap-1 px-2 py-1 rounded-full text-xs bg-primary-100 dark:bg-primary-500/20 text-primary-600 dark:text-primary-400"
            >
              {{ sizeRangeOptions.find(o => o.value === searchFilters.sizeRange)?.label }}
              <button @click="searchFilters.sizeRange = ''" class="hover:text-primary-800">
                <span class="material-symbols-rounded text-sm">close</span>
              </button>
            </span>
            <span 
              v-if="searchFilters.clientId"
              class="inline-flex items-center gap-1 px-2 py-1 rounded-full text-xs bg-blue-100 dark:bg-blue-500/20 text-blue-600 dark:text-blue-400"
            >
              <span class="material-symbols-rounded text-sm">person</span>
              {{ clientsStore.clients.find(c => c.id == searchFilters.clientId)?.display_name || clientsStore.clients.find(c => c.id == searchFilters.clientId)?.domain || $t('driveView.client') }}
              <button @click="searchFilters.clientId = ''" class="hover:text-blue-800">
                <span class="material-symbols-rounded text-sm">close</span>
              </button>
            </span>
          </div>
          
          <!-- Search results count -->
          <div v-if="searchQuery || activeFilterCount > 0" class="mt-2 text-sm text-surface-500">
            {{ $t('driveView.searchResultsCount', { folders: filteredFolders.length, files: filteredFiles.length }) }}
          </div>
        </div>
        
        <!-- Feature Guide -->
        <div class="px-4 md:px-6 pt-4">
          <FeatureGuide v-model="showFeatureGuide" :tiers="guideData.tiers" :integrations="guideData.integrations" :title-key="guideData.titleKey" :footer-key="guideData.footerKey" :layer-key="guideData.layerKey" :layer-icon="guideData.layerIcon" />
        </div>
        
        <!-- Trash View -->
        <div v-if="drive.isTrashView" class="flex-1 overflow-y-auto p-3 md:p-6">
          <div class="bg-white dark:bg-surface-900 rounded-2xl border border-surface-200 dark:border-surface-700 shadow-sm overflow-hidden">
            <DriveTrashView />
          </div>
        </div>

        <!-- Sharing & Access View -->
        <div v-else-if="drive.isSharingAccessView" class="flex-1 overflow-y-auto p-3 md:p-6">
          <div class="bg-white dark:bg-surface-900 rounded-2xl border border-surface-200 dark:border-surface-700 shadow-sm overflow-hidden">
            <SharingAccessTab />
          </div>
        </div>

        <!-- Starred View (virtual section: reuse DriveListView with drive.starredItems) -->
        <div v-else-if="drive.isStarredView" class="flex-1 overflow-y-auto p-3 md:p-6">
          <div class="bg-white dark:bg-surface-900 rounded-2xl border border-surface-200 dark:border-surface-700 shadow-sm overflow-hidden">
          <div v-if="drive.loadingStarred" class="flex items-center justify-center py-12">
            <span class="spinner text-primary-500 w-8 h-8"></span>
          </div>
          <div
            v-else-if="drive.starredItems.files.length === 0 && drive.starredItems.folders.length === 0"
            class="text-center py-16"
          >
            <span class="material-symbols-rounded text-6xl text-surface-300 dark:text-surface-600 mb-4">star_outline</span>
            <p class="text-surface-700 dark:text-surface-200 font-medium">{{ $t('driveView.noStarredItems') }}</p>
            <p class="text-sm text-surface-500 mt-1">{{ $t('driveView.noStarredItemsHint') }}</p>
          </div>
          <DriveListView
            v-else
            :folders="drive.starredItems.folders"
            :files="drive.starredItems.files"
            :downloading-ids="downloadingFiles"
            :sort-field="drive.sortField"
            :sort-direction="drive.sortDirection"
            :active-editors="activeEditors"
            :folder-client-map="folderClientMap"
            :current-folder-id="null"
            :parent-folder-shared="false"
            :dragging-files="[]"
            :drag-over-folder="null"
            @folder-click="(e, folder) => drive.navigateToFolder(folder.id)"
            @folder-dblclick="(e, folder) => drive.navigateToFolder(folder.id)"
            @folder-context="(e, folder) => showContentContextMenu(e, folder, 'folder')"
            @file-click="(e, file) => handleFileClick(e, file)"
            @file-dblclick="(e, file) => handleFileDoubleClick(e, file)"
            @file-context="(e, file) => showContentContextMenu(e, file, 'file')"
            @file-download="downloadFile"
            @file-delete="(file) => confirmDelete(file, 'file')"
            @folder-delete="(folder) => confirmDelete(folder, 'folder')"
            @sort-change="(field, dir) => drive.setSort(field, dir)"
            @show-versions="openVersionsSidebar"
            @stop-editing="stopEditingFile"
            @file-open="(file) => handleFileDoubleClick(null, file)"
            @file-rename="(file) => startRename(file, 'file')"
            @file-share="(file) => openShareModal(file, 'file')"
            @file-toggle-star="(file) => drive.toggleStar('file', file.id)"
            @folder-open="(folder) => drive.navigateToFolder(folder.id)"
            @folder-rename="(folder) => startRename(folder, 'folder')"
            @folder-share="(folder) => openShareFolderModal(folder)"
            @folder-toggle-star="(folder) => drive.toggleStar('folder', folder.id)"
          />
          </div>
        </div>

        <!-- Recent View (virtual section: reuse DriveListView with drive.recentItems) -->
        <div v-else-if="drive.isRecentView" class="flex-1 overflow-y-auto p-3 md:p-6">
          <div class="bg-white dark:bg-surface-900 rounded-2xl border border-surface-200 dark:border-surface-700 shadow-sm overflow-hidden">
          <div v-if="drive.loadingRecent" class="flex items-center justify-center py-12">
            <span class="spinner text-primary-500 w-8 h-8"></span>
          </div>
          <div
            v-else-if="drive.recentItems.files.length === 0 && drive.recentItems.folders.length === 0"
            class="text-center py-16"
          >
            <span class="material-symbols-rounded text-6xl text-surface-300 dark:text-surface-600 mb-4">schedule</span>
            <p class="text-surface-700 dark:text-surface-200 font-medium">{{ $t('driveView.noRecentItems') }}</p>
            <p class="text-sm text-surface-500 mt-1">{{ $t('driveView.noRecentItemsHint') }}</p>
          </div>
          <DriveListView
            v-else
            :folders="drive.recentItems.folders"
            :files="drive.recentItems.files"
            :downloading-ids="downloadingFiles"
            :sort-field="drive.sortField"
            :sort-direction="drive.sortDirection"
            :active-editors="activeEditors"
            :folder-client-map="folderClientMap"
            :current-folder-id="null"
            :parent-folder-shared="false"
            :dragging-files="[]"
            :drag-over-folder="null"
            @folder-click="(e, folder) => drive.navigateToFolder(folder.id)"
            @folder-dblclick="(e, folder) => drive.navigateToFolder(folder.id)"
            @folder-context="(e, folder) => showContentContextMenu(e, folder, 'folder')"
            @file-click="(e, file) => handleFileClick(e, file)"
            @file-dblclick="(e, file) => handleFileDoubleClick(e, file)"
            @file-context="(e, file) => showContentContextMenu(e, file, 'file')"
            @file-download="downloadFile"
            @file-delete="(file) => confirmDelete(file, 'file')"
            @folder-delete="(folder) => confirmDelete(folder, 'folder')"
            @sort-change="(field, dir) => drive.setSort(field, dir)"
            @show-versions="openVersionsSidebar"
            @stop-editing="stopEditingFile"
            @file-open="(file) => handleFileDoubleClick(null, file)"
            @file-rename="(file) => startRename(file, 'file')"
            @file-share="(file) => openShareModal(file, 'file')"
            @file-toggle-star="(file) => drive.toggleStar('file', file.id)"
            @folder-open="(folder) => drive.navigateToFolder(folder.id)"
            @folder-rename="(folder) => startRename(folder, 'folder')"
            @folder-share="(folder) => openShareFolderModal(folder)"
            @folder-toggle-star="(folder) => drive.toggleStar('folder', folder.id)"
          />
          </div>
        </div>

        <!-- Content -->
        <div
          v-else
          class="relative flex-1 px-2 pt-7 pb-4 md:px-3 md:pt-2 md:pb-6"
          :class="drive.viewMode === 'compact' ? 'overflow-hidden flex flex-col min-h-0' : 'overflow-y-auto'"
          @dragover.prevent="dragOver = true"
          @dragleave="dragOver = false"
          @drop="handleDrop"
          @contextmenu.self="showBackgroundContextMenu"
          @click.self="drive.clearSelection()"
        >
          <!-- Clipboard indicator (floats above the white card on grey bg) -->
          <div
            v-if="drive.hasClipboard"
            class="mb-3 flex items-center gap-2 px-3 py-1.5 rounded-lg text-xs"
            :class="drive.clipboard.mode === 'cut'
              ? 'bg-amber-50 dark:bg-amber-900/20 text-amber-700 dark:text-amber-400 border border-amber-200 dark:border-amber-800'
              : 'bg-blue-50 dark:bg-blue-900/20 text-blue-700 dark:text-blue-400 border border-blue-200 dark:border-blue-800'"
          >
            <span class="material-symbols-rounded text-sm">{{ drive.clipboard.mode === 'cut' ? 'content_cut' : 'content_copy' }}</span>
            <span>{{ drive.clipboardCount }} {{ drive.clipboard.mode === 'cut' ? $t('driveView.cutLabel') : $t('driveView.copyLabel') }}</span>
            <span class="text-[10px] opacity-60">Ctrl+V {{ $t('driveView.toPaste') }}</span>
            <button
              class="ml-auto p-0.5 rounded hover:bg-black/10 dark:hover:bg-white/10 transition-colors"
              @click="drive.clipboardClear()"
              :title="$t('driveView.clearClipboard')"
            >
              <span class="material-symbols-rounded text-sm">close</span>
            </button>
          </div>
          <!-- Loading overlay - doesn't push content -->
          <div v-if="drive.loading" class="absolute inset-0 z-30 flex items-center justify-center bg-white/70 dark:bg-surface-900/70 backdrop-blur-sm">
            <span class="spinner text-primary-500 w-8 h-8"></span>
          </div>

          <!-- Opening collab document overlay -->
          <div v-if="openingCollabFile" class="absolute inset-0 z-30 flex flex-col items-center justify-center bg-white/80 dark:bg-surface-900/80 backdrop-blur-sm gap-3">
            <span class="material-symbols-rounded text-4xl text-primary-500 animate-spin">progress_activity</span>
            <span class="text-sm font-medium text-surface-600 dark:text-surface-300">Opening document...</span>
          </div>
          
          <!-- Drop overlay -->
          <div 
            v-if="dragOver" 
            class="fixed inset-0 z-40 bg-primary-500/10 border-4 border-dashed border-primary-500 flex items-center justify-center pointer-events-none"
          >
            <div class="bg-white dark:bg-surface-800 rounded-2xl p-8 text-center shadow-xl">
              <span class="material-symbols-rounded text-5xl text-primary-500 mb-3">cloud_upload</span>
              <p class="text-lg font-medium text-surface-900 dark:text-surface-100">{{ t('driveView.dropFilesToUpload') }}</p>
            </div>
          </div>
          
          <!-- Flat file list area (file-manager style: no card box, list sits
               directly on the page background). Clicking the empty surface
               below the items clears the selection. -->
          <div
            @click.self="drive.clearSelection()"
            :class="drive.viewMode === 'compact' ? 'flex-1 min-h-0 flex flex-col' : ''"
          >
          
          <!-- Searching state - Drive-wide search in progress -->
          <div v-if="drive.searchLoading && filteredFolders.length === 0 && filteredFiles.length === 0" class="text-center py-12">
            <span class="material-symbols-rounded text-5xl text-surface-300 dark:text-surface-600 mb-4 animate-spin">progress_activity</span>
            <p class="text-surface-500 dark:text-surface-400">{{ t('driveView.searchingDrive') }}</p>
          </div>
          
          <!-- Empty state - no matches for search/filter -->
          <div v-if="!drive.loading && !drive.searchLoading && (searchQuery || activeFilterCount > 0) && filteredFolders.length === 0 && filteredFiles.length === 0 && (drive.searchActive || drive.folders.length > 0 || drive.files.length > 0)" class="text-center py-12">
            <span class="material-symbols-rounded text-6xl text-surface-300 dark:text-surface-600 mb-4">search_off</span>
            <p class="text-surface-500 dark:text-surface-400 mb-2">{{ t('driveView.noFilesMatchYourSearch') }}</p>
            <p class="text-sm text-surface-400 mb-6">{{ t('driveView.tryAdjustingYourFiltersOr') }}</p>
            <button @click="clearSearch" class="btn-secondary">
              <span class="material-symbols-rounded">clear_all</span>
              Clear Search
            </button>
          </div>
          
          <!-- Empty state - folder is truly empty (also check collab docs which are virtual files) -->
          <div v-if="!drive.loading && !drive.searchActive && drive.folders.length === 0 && drive.files.length === 0 && currentFolderCollabDocs.length === 0" class="text-center py-12">
            <span class="material-symbols-rounded text-6xl text-surface-300 dark:text-surface-600 mb-4">cloud_off</span>
            <p class="text-surface-500 dark:text-surface-400 mb-6">{{ t('driveView.thisFolderIsEmpty') }}</p>
            <div class="flex justify-center gap-3">
              <button @click="openNewFolderModal()" class="btn-secondary">
                <span class="material-symbols-rounded">create_new_folder</span>
                New Folder
              </button>
              <button @click="triggerUpload" class="btn-primary">
                <span class="material-symbols-rounded">upload</span>
                Upload Files
              </button>
            </div>
          </div>
          
          <!-- Compact view - dense, file-manager-style single-line rows -->
          <DriveCompactView
            v-if="(filteredFolders.length > 0 || filteredFiles.length > 0) && drive.viewMode === 'compact'"
            class="flex-1 min-h-0"
            @background-context="showBackgroundContextMenu"
            :folders="filteredFolders"
            :files="filteredFiles"
            :sort-field="sortField"
            :sort-direction="sortDirection"
            :active-editors="activeEditors"
            :current-folder-id="drive.currentFolderId"
            :parent-folder-shared="!!drive.currentFolder?.share_token"
            :dragging-files="draggingFiles"
            :drag-over-folder="dragOverFolder"
            @folder-click="(e, folder) => handleFolderClick(e, folder)"
            @folder-dblclick="(e, folder) => handleFolderDoubleClick(e, folder)"
            @folder-context="(e, folder) => showContentContextMenu(e, folder, 'folder')"
            @file-click="(e, file) => handleFileClick(e, file)"
            @file-dblclick="(e, file) => handleFileDoubleClick(e, file)"
            @file-context="(e, file) => showContentContextMenu(e, file, 'file')"
            @file-download="downloadFile"
            @file-delete="(file) => confirmDelete(file, 'file')"
            @folder-delete="(folder) => confirmDelete(folder, 'folder')"
            @sort-change="handleSortChange"
            @show-versions="openVersionsSidebar"
            @stop-editing="stopEditingFile"
            @file-dragstart="onFileDragStart"
            @file-dragend="onFileDragEnd"
            @folder-dragover="onFolderDragOver"
            @folder-dragleave="onFolderDragLeave"
            @folder-drop="onFolderDrop"
            @file-open="(file) => handleFileDoubleClick(null, file)"
            @file-rename="(file) => startRename(file, 'file')"
            @file-move="(file) => { drive.selectFile(file.id); moveSelectedToFolder() }"
            @file-copy="(file) => { drive.selectFile(file.id); drive.clipboardCopy() }"
            @file-share="(file) => openShareModal(file, 'file')"
            @file-toggle-star="(file) => drive.toggleStar('file', file.id)"
            @folder-open="(folder) => drive.navigateToFolder(folder.id)"
            @folder-rename="(folder) => startRename(folder, 'folder')"
            @folder-move="(folder) => { drive.selectFolder(folder.id); moveSelectedToFolder() }"
            @folder-copy="(folder) => { drive.selectFolder(folder.id); drive.clipboardCopy() }"
            @folder-share="(folder) => openShareFolderModal(folder)"
            @folder-toggle-star="(folder) => drive.toggleStar('folder', folder.id)"
            @open-folder-path="openFolderFromSearch"
          />

          <!-- List view -->
          <DriveListView 
            v-if="(filteredFolders.length > 0 || filteredFiles.length > 0) && drive.viewMode === 'list'"
            :folders="filteredFolders"
            :files="filteredFiles"
            :downloading-ids="downloadingFiles"
            :sort-field="sortField"
            :sort-direction="sortDirection"
            :active-editors="activeEditors"
            :folder-client-map="folderClientMap"
            :current-folder-id="drive.currentFolderId"
            :parent-folder-shared="!!drive.currentFolder?.share_token"
            :dragging-files="draggingFiles"
            :drag-over-folder="dragOverFolder"
            @folder-click="(e, folder) => handleFolderClick(e, folder)"
            @folder-dblclick="(e, folder) => handleFolderDoubleClick(e, folder)"
            @folder-context="(e, folder) => showContentContextMenu(e, folder, 'folder')"
            @file-click="(e, file) => handleFileClick(e, file)"
            @file-dblclick="(e, file) => handleFileDoubleClick(e, file)"
            @file-context="(e, file) => showContentContextMenu(e, file, 'file')"
            @file-download="downloadFile"
            @file-delete="(file) => confirmDelete(file, 'file')"
            @folder-delete="(folder) => confirmDelete(folder, 'folder')"
            @sort-change="handleSortChange"
            @show-versions="openVersionsSidebar"
            @stop-editing="stopEditingFile"
            @file-dragstart="onFileDragStart"
            @file-dragend="onFileDragEnd"
            @folder-dragover="onFolderDragOver"
            @folder-dragleave="onFolderDragLeave"
            @folder-drop="onFolderDrop"
            @file-open="(file) => handleFileDoubleClick(null, file)"
            @file-rename="(file) => startRename(file, 'file')"
            @file-move="(file) => { drive.selectFile(file.id); moveSelectedToFolder() }"
            @file-copy="(file) => { drive.selectFile(file.id); drive.clipboardCopy() }"
            @file-share="(file) => openShareModal(file, 'file')"
            @file-toggle-star="(file) => drive.toggleStar('file', file.id)"
            @folder-open="(folder) => drive.navigateToFolder(folder.id)"
            @folder-rename="(folder) => startRename(folder, 'folder')"
            @folder-move="(folder) => { drive.selectFolder(folder.id); moveSelectedToFolder() }"
            @folder-copy="(folder) => { drive.selectFolder(folder.id); drive.clipboardCopy() }"
            @folder-share="(folder) => openShareFolderModal(folder)"
            @folder-toggle-star="(folder) => drive.toggleStar('folder', folder.id)"
            @open-folder-path="openFolderFromSearch"
          />
          
          <!-- Grid view - square cards (responsive: 3 cols mobile, up to 7 cols desktop) -->
          <div 
            v-if="(filteredFolders.length > 0 || filteredFiles.length > 0) && drive.viewMode === 'grid'" 
            class="grid grid-cols-3 sm:grid-cols-4 md:grid-cols-5 lg:grid-cols-6 xl:grid-cols-7 gap-1 sm:gap-1.5 md:gap-2" 
            @click.self="drive.clearSelection()">
            <!-- Folders -->
            <div 
              v-for="folder in filteredFolders" 
              :key="'folder-' + folder.id"
              @click="handleFolderClick($event, folder)"
              @dblclick="handleFolderDoubleClick($event, folder)"
              @contextmenu="showContentContextMenu($event, folder, 'folder')"
              @touchstart="handleTouchStart($event, folder, 'folder')"
              @touchmove="handleTouchMove"
              @touchend="handleTouchEnd"
              @dragover="onFolderDragOver($event, folder.id)"
              @dragleave="onFolderDragLeave"
              @drop="onFolderDrop($event, folder.id)"
              :class="[
                'group relative aspect-square card p-2 sm:p-2 md:p-3 cursor-pointer hover:shadow-md transition-all select-none flex flex-col items-center justify-center',
                dragOverFolder === folder.id ? 'ring-2 ring-primary-500 bg-primary-50 dark:bg-primary-500/20' : '',
                drive.isFolderSelected(folder.id) ? 'ring-2 ring-primary-500 bg-primary-100 dark:bg-primary-500/25' : ''
              ]"
            >
              <!-- Folder icon and name -->
              <span :class="['material-symbols-rounded icon-filled text-4xl sm:text-4xl md:text-5xl', dragOverFolder === folder.id ? 'text-primary-500' : getFolderColor(folder)]">
                {{ dragOverFolder === folder.id ? 'folder_open' : 'folder' }}
              </span>
              <p class="text-xs sm:text-xs md:text-sm font-medium text-surface-900 dark:text-surface-100 truncate w-full text-center mt-1.5 px-0.5" :title="folder.name">
                {{ folder.name }}
              </p>
              
              <!-- Protected folder indicator (linked to board OR system folder) -->
              <div 
                v-if="folder.board_id || ((folder.name === 'Boards' || folder.name === 'Attachments' || folder.name === 'Chats' || folder.name === 'Invoices' || folder.name === 'Moodboards') && !folder.parent_id)" 
                class="absolute top-1 right-1 w-5 h-5 rounded-full bg-amber-100 dark:bg-amber-500/30 flex items-center justify-center shadow-sm"
                :title="folder.board_id ? $t('driveView.protectedLinkedToBoard') : $t('driveView.systemFolder')"
              >
                <span class="material-symbols-rounded text-xs text-amber-600 dark:text-amber-400">shield</span>
              </div>
              
              <!-- Shared indicator -->
              <div v-if="folder.share_token" class="absolute top-1.5 left-1.5">
                <span class="material-symbols-rounded text-sm text-green-500" :title="$t('driveView.shared')">link</span>
              </div>
              
              <!-- Chat shared indicator -->
              <div 
                v-if="drive.isSharedInChat('folder', folder.id)" 
                class="absolute bottom-7 right-1 w-5 h-5 rounded-full bg-indigo-100 dark:bg-indigo-500/30 flex items-center justify-center shadow-sm"
                :title="$t('driveView.sharedInChat')"
              >
                <span class="material-symbols-rounded text-[11px] text-indigo-600 dark:text-indigo-400">chat</span>
              </div>
            </div>
            
            <!-- Files -->
            <div 
              v-for="file in filteredFiles" 
              :key="'file-' + file.id"
              draggable="true"
              @click="handleFileClick($event, file)"
              @dblclick="handleFileDoubleClick($event, file)"
              @contextmenu="showContentContextMenu($event, file, 'file')"
              @touchstart="handleTouchStart($event, file, 'file')"
              @touchmove="handleTouchMove"
              @touchend="handleTouchEnd"
              @dragstart="onFileDragStart($event, file)"
              @dragend="onFileDragEnd"
              :class="[
                'group relative aspect-square card p-0 sm:p-1 md:p-2 cursor-pointer hover:shadow-md transition-shadow select-none flex flex-col overflow-hidden',
                draggingFiles.some(f => f.id === file.id) ? 'opacity-50 scale-95' : '',
                drive.isFileSelected(file.id) ? 'ring-2 ring-primary-500 bg-primary-100 dark:bg-primary-500/25' : ''
              ]"
            >
              <!-- Image thumbnail (fills most of the square) -->
              <div v-if="file.mime_type?.startsWith('image/')" class="flex-1 w-full flex items-center justify-center overflow-hidden rounded-none sm:rounded bg-surface-100 dark:bg-surface-700">
                <!-- Show cached image immediately (prevents flash on view switch) -->
                <img 
                  v-if="drive.hasThumbnail(file.id)"
                  :src="drive.thumbnailCache[file.id]" 
                  :alt="file.original_name"
                  class="w-full h-full object-cover"
                />
                <!-- Loading state, failed state, or trigger load -->
                <template v-else>
                  <span 
                    v-if="drive.thumbnailCache[file.id] === 'loading'"
                    class="material-symbols-rounded text-2xl text-surface-400 animate-spin"
                  >progress_activity</span>
                  <span 
                    v-else-if="drive.thumbnailCache[file.id] === 'failed'"
                    class="material-symbols-rounded text-2xl text-surface-400"
                    :title="$t('driveView.thumbnailUnavailable')"
                  >broken_image</span>
                  <span 
                    v-else
                    class="material-symbols-rounded text-2xl text-surface-400"
                    :data-load="getThumbnailUrl(file)"
                  >image</span>
                </template>
              </div>
              <!-- File icon for non-images -->
              <div v-else class="flex-1 flex items-center justify-center">
                <div :class="['w-14 h-14 sm:w-14 sm:h-14 md:w-16 md:h-16 rounded-xl flex items-center justify-center', getFileIconInfo(file.mime_type).bgColor]">
                  <span :class="['material-symbols-rounded text-3xl sm:text-3xl md:text-4xl', getFileIconInfo(file.mime_type).color]">
                    {{ getFileIconInfo(file.mime_type).icon }}
                  </span>
                </div>
              </div>
              
              <!-- File name and size -->
              <div class="w-full text-center mt-0.5 sm:mt-1 px-0.5">
                <p class="text-[10px] sm:text-xs md:text-sm font-medium text-surface-900 dark:text-surface-100 truncate" :title="file.original_name">
                  {{ file.original_name }}
                </p>
                <p class="text-[10px] sm:text-xs text-surface-500">{{ formatSize(file.size) }}</p>
                <!-- Containing folder path (search results only) -->
                <button
                  v-if="drive.searchActive"
                  type="button"
                  @click.stop="openFolderFromSearch(file.folder_id ?? null)"
                  class="mt-0.5 inline-flex items-center justify-center gap-0.5 max-w-full text-[10px] text-surface-400 hover:text-primary-500 dark:hover:text-primary-400 transition-colors"
                  :title="$t('driveView.openContainingFolder') + ': ' + searchFolderPathLabel(file.folder_id)"
                >
                  <span class="material-symbols-rounded text-[12px] flex-shrink-0">folder</span>
                  <span class="truncate">{{ searchFolderPathLabel(file.folder_id) }}</span>
                </button>
              </div>
              
              <!-- Shared indicator -->
              <div v-if="file.share_token" class="absolute top-1.5 left-1.5">
                <span class="material-symbols-rounded text-sm text-primary-500" :title="$t('driveView.shared')">link</span>
              </div>
              
              <!-- Chat shared indicator -->
              <div 
                v-if="drive.isSharedInChat('file', file.id)" 
                class="absolute top-1.5 flex items-center"
                :class="file.share_token ? 'left-7' : 'left-1.5'"
                :title="$t('driveView.sharedInChat')"
              >
                <span class="material-symbols-rounded text-sm text-indigo-500">chat</span>
              </div>
              
              <!-- Version indicator -->
              <button 
                v-if="file.current_version > 1" 
                @click.stop="openVersionsSidebar(file)"
                class="absolute top-1.5 right-1.5 flex items-center gap-0.5 px-1.5 py-0.5 rounded bg-primary-500 hover:bg-primary-600 shadow transition-colors z-10"
                :title="$t('driveView.versionsClickToViewHistory', file.current_version, { count: file.current_version })"
              >
                <span class="material-symbols-rounded text-sm text-white">history</span>
                <span class="text-xs font-semibold text-white">v{{ file.current_version }}</span>
              </button>
            </div>
          </div>
          </div><!-- /white card -->
        </div>
        
        <!-- Quota bar moved into DriveSidebar (DriveQuotaCard) per redesign. -->
      </main>
    </div>
    
    <!-- New Folder Modal -->
    <Teleport to="body">
      <div v-if="showNewFolderModal" class="modal-overlay" @click.self="showNewFolderModal = false; newFolderParentId = null">
        <div class="modal max-w-sm">
          <div class="modal-header">
            <h3 class="font-semibold">{{ newFolderParentId ? $t('driveView.newSubfolder') : $t('driveView.newFolder') }}</h3>
            <button @click="showNewFolderModal = false; newFolderParentId = null" class="btn-ghost btn-icon">
              <span class="material-symbols-rounded">close</span>
            </button>
          </div>
          <div class="modal-body space-y-3">
            <div v-if="newFolderParentId" class="text-sm text-surface-500 flex items-center gap-2">
              <span class="material-symbols-rounded text-primary-500">folder</span>
              <span>{{ $t('driveView.inside') }}: {{ allFolders.find(f => f.id === newFolderParentId)?.name || $t('driveView.unknown') }}</span>
            </div>
            <input 
              v-model="newFolderName" 
              type="text" 
              class="input" 
              :placeholder="$t('driveView.folderName')"
              @keyup.enter="createFolder"
              autofocus
            />
          </div>
          <div class="modal-footer">
            <button @click="showNewFolderModal = false; newFolderParentId = null" class="btn-ghost">{{ $t('driveView.cancel') }}</button>
            <button @click="createFolder" class="btn-primary" :disabled="creatingFolder || !newFolderName.trim()">
              <span v-if="creatingFolder" class="spinner w-4 h-4"></span>
              {{ $t('driveView.create') }}
            </button>
          </div>
        </div>
      </div>
    </Teleport>
    
    <!-- Rename Modal -->
    <Teleport to="body">
      <div v-if="showRenameModal" class="modal-overlay" @click.self="showRenameModal = false">
        <div class="modal max-w-sm">
          <div class="modal-header">
            <h3 class="font-semibold">{{ $t('driveView.rename') }}</h3>
            <button @click="showRenameModal = false" class="btn-ghost btn-icon">
              <span class="material-symbols-rounded">close</span>
            </button>
          </div>
          <div class="modal-body">
            <input 
              v-model="renameValue" 
              type="text" 
              class="input" 
              @keyup.enter="executeRename"
              autofocus
            />
          </div>
          <div class="modal-footer">
            <button @click="showRenameModal = false" class="btn-ghost">{{ $t('driveView.cancel') }}</button>
            <button @click="executeRename" class="btn-primary" :disabled="!renameValue.trim()">
              {{ $t('driveView.rename') }}
            </button>
          </div>
        </div>
      </div>
    </Teleport>
    
    <!-- File/Folder Properties Panel -->
    <FilePropertiesPanel
      :show="showPropertiesPanel"
      :item="propertiesItem"
      :type="propertiesType"
      @close="closePropertiesPanel"
    />
    
    <!-- Activity Log Panel -->
    <ActivityLogPanel
      :show="showActivityLogPanel"
      @close="closeActivityLogPanel"
    />
    
    <!-- Delete (Move to Trash) Confirmation Modal -->
    <Teleport to="body">
      <div v-if="showDeleteConfirm" class="modal-overlay" @click.self="!deleteInProgress && cancelDelete()">
        <div class="modal max-w-md">
          <div class="modal-header">
            <div class="flex items-center gap-3">
              <div class="w-10 h-10 rounded-full bg-amber-100 dark:bg-amber-500/20 flex items-center justify-center">
                <span class="material-symbols-rounded text-xl text-amber-600 dark:text-amber-400">delete</span>
              </div>
              <h3 class="font-semibold text-surface-900 dark:text-surface-100">
                <template v-if="deleteTarget?.type === 'bulk'">
                  {{ $t('driveView.moveItemsToTrashTitleBulk', { count: deleteTarget?.count }) }}
                </template>
                <template v-else>
                  {{ $t('driveView.moveItemToTrashTitle', { itemType: deleteTarget?.type === 'folder' ? $t('driveView.folder') : $t('driveView.file') }) }}
                </template>
              </h3>
            </div>
            <button 
              @click="cancelDelete" 
              class="btn-ghost btn-icon"
              :disabled="deleteInProgress"
            >
              <span class="material-symbols-rounded">close</span>
            </button>
          </div>
          
          <div class="modal-body">
            <!-- Progress indicator (shown during deletion) -->
            <div v-if="deleteInProgress" class="mb-4">
              <div class="flex items-center justify-between mb-2">
                <span class="text-sm font-medium text-surface-700 dark:text-surface-300">
                  {{ $t('driveView.deletingProgress', { current: deleteProgress.current, total: deleteProgress.total }) }}
                </span>
                <span class="text-sm text-surface-500">
                  {{ Math.round((deleteProgress.current / deleteProgress.total) * 100) }}%
                </span>
              </div>
              <div class="w-full bg-surface-200 dark:bg-surface-700 rounded-full h-2 mb-2">
                <div 
                  class="bg-amber-500 h-2 rounded-full transition-all duration-150"
                  :style="{ width: `${(deleteProgress.current / deleteProgress.total) * 100}%` }"
                ></div>
              </div>
              <p class="text-xs text-surface-500 dark:text-surface-400 truncate">
                <span class="material-symbols-rounded text-xs align-middle mr-1">delete</span>
                {{ deleteProgress.currentItem }}
              </p>
            </div>
            
            <!-- Info message (hidden during deletion) -->
            <div v-else class="bg-surface-100 dark:bg-surface-700/50 rounded-xl p-4 mb-4">
              <template v-if="deleteTarget?.type === 'bulk'">
                <p class="text-sm text-surface-700 dark:text-surface-300 mb-2">
                  <span class="font-semibold">{{ deleteTarget?.count }}</span> {{ $t('driveView.bulkMoveToTrashInfo', { count: deleteTarget?.count }) }}
                  <template v-if="deleteTarget?.hasFolder">
                    {{ $t('driveView.bulkMoveToTrashIncludesFolders') }}
                  </template>
                </p>
              </template>
              <template v-else-if="deleteTarget?.type === 'folder'">
                <p class="text-sm text-surface-700 dark:text-surface-300 mb-2">
                  {{ $t('driveView.folderMoveToTrashInfo', { name: deleteTarget?.item?.name }) }}
                </p>
              </template>
              <template v-else>
                <p class="text-sm text-surface-700 dark:text-surface-300 mb-2">
                  {{ $t('driveView.fileMoveToTrashInfo', { name: deleteTarget?.item?.original_name }) }}
                </p>
              </template>
              
              <div class="flex items-center gap-2 text-xs text-surface-500 dark:text-surface-400 mt-3 pt-3 border-t border-surface-200 dark:border-surface-600">
                <span class="material-symbols-rounded text-sm">info</span>
                <span>{{ $t('driveView.itemsInTrashCanBe') }}</span>
              </div>
            </div>
          </div>
          
          <div class="modal-footer">
            <button 
              @click="cancelDelete" 
              class="btn-secondary"
              :disabled="deleteInProgress"
            >
              {{ $t('driveView.cancel') }}
            </button>
            <button 
              @click="executeDelete" 
              class="btn-primary !bg-amber-500 hover:!bg-amber-600"
              :disabled="deleteInProgress"
            >
              <span v-if="deleteInProgress" class="spinner w-4 h-4"></span>
              <span v-else class="material-symbols-rounded text-lg">delete</span>
              <template v-if="deleteInProgress">
                {{ $t('driveView.deleting') }}
              </template>
              <template v-else-if="deleteTarget?.type === 'bulk'">
                {{ $t('driveView.moveItemsToTrashActionBulk', { count: deleteTarget?.count }) }}
              </template>
              <template v-else>
                {{ $t('driveView.moveToTrashAction') }}
              </template>
            </button>
          </div>
        </div>
      </div>
    </Teleport>
    
    <!-- Protected Folder Modal -->
    <Teleport to="body">
      <div v-if="showProtectedModal" class="modal-overlay" @click.self="showProtectedModal = false">
        <div class="modal max-w-sm">
          <div class="modal-body text-center py-8">
            <!-- Shield Icon -->
            <div class="w-16 h-16 mx-auto mb-4 rounded-full bg-amber-100 dark:bg-amber-500/20 flex items-center justify-center">
              <span class="material-symbols-rounded text-3xl text-amber-600 dark:text-amber-400">shield</span>
            </div>
            
            <!-- Title -->
            <h3 class="text-lg font-semibold text-surface-900 dark:text-surface-100 mb-2">
              {{ protectedFolderInfo.isSystem ? $t('driveView.protectedFolder.systemTitle') : $t('driveView.protectedFolder.title') }}
            </h3>
            
            <!-- Folder name -->
            <div class="inline-flex items-center gap-2 px-3 py-1.5 rounded-full bg-surface-100 dark:bg-surface-700 mb-4">
              <span class="material-symbols-rounded text-sm text-primary-500">folder</span>
              <span class="text-sm font-medium text-surface-700 dark:text-surface-300">{{ protectedFolderInfo.name }}</span>
            </div>
            
            <!-- Reason -->
            <p class="text-sm text-surface-600 dark:text-surface-400 mb-3">
              {{ $t(protectedFolderInfo.reason) }}
            </p>
            
            <!-- Hint -->
            <div class="flex items-start gap-2 text-xs text-surface-500 dark:text-surface-400 bg-surface-50 dark:bg-surface-800 rounded-lg p-3 text-left">
              <span class="material-symbols-rounded text-sm flex-shrink-0 mt-0.5">lightbulb</span>
              <span>{{ $t(protectedFolderInfo.hint) }}</span>
            </div>
          </div>
          
          <div class="modal-footer justify-center border-t border-surface-200 dark:border-surface-700">
            <button 
              @click="showProtectedModal = false" 
              class="btn-primary !bg-amber-500 hover:!bg-amber-600 px-8"
            >
              {{ $t('driveView.protectedFolder.okGotIt') }}
            </button>
          </div>
        </div>
      </div>
    </Teleport>
    
    <!-- Move Modal -->
    <Teleport to="body">
      <div v-if="showMoveModal" class="modal-overlay" @click.self="showMoveModal = false">
        <div class="modal max-w-md">
          <div class="modal-header">
            <h3 class="font-semibold">{{ $t('driveView.moveItemsTo', drive.selectionCount, { count: drive.selectionCount }) }}</h3>
            <button @click="showMoveModal = false" class="btn-ghost btn-icon">
              <span class="material-symbols-rounded">close</span>
            </button>
          </div>
          <div class="modal-body max-h-80 overflow-y-auto">
            <!-- Root option -->
            <button 
              @click="confirmMoveToFolder(null)"
              class="w-full flex items-center gap-3 p-3 rounded-lg hover:bg-surface-100 dark:hover:bg-surface-700 transition-colors text-left"
            >
              <span class="material-symbols-rounded text-2xl text-amber-500">home</span>
              <span class="text-sm font-medium text-surface-900 dark:text-surface-100">{{ $t('driveView.myDriveRoot') }}</span>
            </button>
            
<!-- Folders -->
                            <div v-for="folder in allFolders" :key="folder.id">
                              <button 
                                @click="confirmMoveToFolder(folder.id)"
                                :disabled="drive.isFolderSelected(folder.id)"
                                :class="[
                                  'w-full flex items-center gap-3 p-3 rounded-lg hover:bg-surface-100 dark:hover:bg-surface-700 transition-colors text-left',
                                  drive.isFolderSelected(folder.id) ? 'opacity-50 cursor-not-allowed' : ''
                                ]"
                              >
                                <span :class="['material-symbols-rounded text-2xl', getFolderColor(folder)]">folder</span>
                                <span class="text-sm font-medium text-surface-900 dark:text-surface-100">{{ folder.name }}</span>
                              </button>
                            </div>
          </div>
          <div class="modal-footer">
            <button @click="showMoveModal = false" class="btn-ghost">{{ $t('driveView.cancel') }}</button>
          </div>
        </div>
      </div>
    </Teleport>
    
    <!-- File Preview Modal -->
    <Teleport to="body">
      <div v-if="showPreview && previewFile" class="fixed inset-0 z-50 flex flex-col bg-black/95 md:bg-black/90" @click.self="closePreview">
        <!-- Mobile Header -->
        <div class="md:hidden flex items-center justify-between p-3 bg-black/50 safe-area-top">
          <div class="flex-1 min-w-0">
            <p class="font-medium text-white text-sm truncate">{{ previewFile.original_name }}</p>
            <p class="text-xs text-surface-400">{{ formatSize(previewFile.size) }}</p>
          </div>
          <div class="flex items-center gap-2">
            <span v-if="previewableFiles.length > 1" class="text-xs text-surface-400 bg-white/10 px-2 py-0.5 rounded-full">
              {{ previewIndex + 1 }}/{{ previewableFiles.length }}
            </span>
            <button v-if="canPrintPreview" @click="printPreview" class="w-10 h-10 flex items-center justify-center text-white" :title="$t('driveView.print')">
              <span class="material-symbols-rounded text-2xl">print</span>
            </button>
            <button @click="closePreview" class="w-10 h-10 flex items-center justify-center text-white">
              <span class="material-symbols-rounded text-2xl">close</span>
            </button>
          </div>
        </div>
        
        <!-- Main content area -->
        <div class="flex-1 flex items-center justify-center overflow-hidden relative">
          <!-- Navigation arrows - Desktop -->
          <button 
            v-if="previewableFiles.length > 1"
            @click="prevPreview"
            class="hidden md:flex absolute left-4 top-1/2 -translate-y-1/2 w-12 h-12 rounded-full bg-white/10 hover:bg-white/20 items-center justify-center text-white transition-colors z-10"
          >
            <span class="material-symbols-rounded text-3xl">chevron_left</span>
          </button>
          <button 
            v-if="previewableFiles.length > 1"
            @click="nextPreview"
            class="hidden md:flex absolute right-4 top-1/2 -translate-y-1/2 w-12 h-12 rounded-full bg-white/10 hover:bg-white/20 items-center justify-center text-white transition-colors z-10"
          >
            <span class="material-symbols-rounded text-3xl">chevron_right</span>
          </button>
          
          <div
            class="relative w-full max-h-full h-full mx-0"
            :class="previewIsImage
              ? 'max-w-[95vw] md:h-[90vh] md:max-h-[90vh] md:mx-auto'
              : 'max-w-5xl md:h-auto md:max-h-[90vh] md:mx-16'"
          >
            <!-- Close button - Desktop only -->
            <button 
              @click="closePreview"
              class="hidden md:block absolute -top-12 right-0 text-white hover:text-surface-300 transition-colors"
            >
              <span class="material-symbols-rounded text-3xl">close</span>
            </button>
            
            <!-- File info & counter - Desktop only -->
            <div class="hidden md:flex absolute -top-12 left-0 text-white items-center gap-4">
              <div>
                <p class="font-medium">{{ previewFile.original_name }}</p>
                <p class="text-sm text-surface-400">{{ formatSize(previewFile.size) }}</p>
              </div>
              <span v-if="previewableFiles.length > 1" class="text-sm text-surface-400 bg-white/10 px-3 py-1 rounded-full">
                {{ previewIndex + 1 }} / {{ previewableFiles.length }}
              </span>
              <!-- Tracking indicator -->
              <div 
                v-if="trackingInfo.isTracking"
                class="flex items-center gap-2 px-3 py-1.5 rounded-full bg-green-500/20 border border-green-500/40 animate-pulse"
              >
                <span class="material-symbols-rounded text-green-400 text-sm">timer</span>
                <span class="text-sm text-green-400 font-medium">{{ $t('driveView.tracking') }}</span>
                <span class="text-green-400 font-mono font-semibold">{{ formatTrackingTime(trackingInfo.elapsedSeconds) }}</span>
                <span v-if="trackingInfo.clientName" class="text-xs text-green-300 border-l border-green-500/40 pl-2 ml-1">
                  {{ trackingInfo.clientName }}
                </span>
              </div>
            </div>
            
            <!-- Preview content. Cap the flex column at 90vh on desktop so
                 scrollable children (markdown/text/docx) can actually scroll
                 instead of overflowing past the clipped modal. Images use a
                 fit-content card so it hugs the picture (lightbox style). -->
            <div
              class="bg-white dark:bg-surface-800 md:rounded-xl overflow-hidden shadow-2xl h-full w-full flex flex-col"
              :class="previewIsImage ? 'md:h-full md:max-h-full' : 'md:h-auto md:max-h-[90vh]'"
            >
            <!-- Loading state. Images use a dark backdrop so the spinner
                 matches the lightbox; the large image card already gives it a
                 generous area to center in. -->
            <div
              v-if="previewLoading"
              class="flex-1 flex items-center justify-center p-8 min-h-[200px] md:min-h-[400px]"
              :class="previewIsImage ? 'bg-surface-900' : ''"
            >
              <div class="animate-spin w-8 h-8 border-2 border-primary-500 border-t-transparent rounded-full"></div>
            </div>
            
            <!-- Image preview (zoom + pan). The container is a large lightbox
                 viewport that fills the modal so it uses the available screen
                 width (great for ultrawide + zooming); the image is centered
                 with object-contain inside it. -->
            <div
              v-else-if="previewFile.mime_type?.startsWith('image/')"
              ref="imageContainerRef"
              class="relative flex-1 min-h-0 w-full flex items-center justify-center bg-surface-900 overflow-hidden select-none"
              :class="imageZoom > 1 ? (isImagePanning ? 'cursor-grabbing' : 'cursor-grab') : 'cursor-zoom-in'"
              @wheel="handleImageWheel"
              @mousedown="handleImageMouseDown"
              @dblclick="handleImageDoubleClick"
              @touchstart="handleImageTouchStart"
              @touchmove="handleImageTouchMove"
              @touchend="handleImageTouchEnd"
              @touchcancel="handleImageTouchEnd"
            >
              <img
                v-if="previewBlobUrl"
                :src="previewBlobUrl"
                :alt="previewFile.original_name"
                draggable="false"
                class="max-w-full max-h-full object-contain pointer-events-none will-change-transform"
                :style="{
                  transform: `translate(${imagePanX}px, ${imagePanY}px) scale(${imageZoom})`,
                  transition: isImagePanning ? 'none' : 'transform 120ms ease-out'
                }"
              />

              <!-- Zoom controls (desktop / tablet) -->
              <div
                class="absolute bottom-3 left-1/2 -translate-x-1/2 flex items-center gap-1 px-2 py-1 rounded-full bg-black/60 backdrop-blur-sm text-white shadow-lg pointer-events-auto"
                @mousedown.stop
                @dblclick.stop
                @click.stop
              >
                <button
                  type="button"
                  @click="zoomImageOut"
                  :disabled="imageZoom <= IMAGE_MIN_ZOOM"
                  class="w-8 h-8 inline-flex items-center justify-center rounded-full hover:bg-white/15 disabled:opacity-40 disabled:cursor-not-allowed"
                  :title="$t('driveView.zoomOut') || 'Zoom out'"
                >
                  <span class="material-symbols-rounded text-[20px]">zoom_out</span>
                </button>
                <button
                  type="button"
                  @click="resetImageZoom"
                  class="px-2 h-8 inline-flex items-center justify-center rounded-full text-xs font-mono tabular-nums hover:bg-white/15 min-w-[3.5rem]"
                  :title="$t('driveView.resetZoom') || 'Reset zoom'"
                >
                  {{ Math.round(imageZoom * 100) }}%
                </button>
                <button
                  type="button"
                  @click="zoomImageIn"
                  :disabled="imageZoom >= IMAGE_MAX_ZOOM"
                  class="w-8 h-8 inline-flex items-center justify-center rounded-full hover:bg-white/15 disabled:opacity-40 disabled:cursor-not-allowed"
                  :title="$t('driveView.zoomIn') || 'Zoom in'"
                >
                  <span class="material-symbols-rounded text-[20px]">zoom_in</span>
                </button>
              </div>
            </div>
            
            <!-- Video preview -->
            <div v-else-if="previewFile.mime_type?.startsWith('video/')" class="flex-1 flex items-center justify-center bg-black min-h-0">
              <video 
                v-if="previewBlobUrl"
                :src="previewBlobUrl" 
                controls 
                playsinline
                class="w-full max-h-full md:max-h-[80vh]"
              >
                {{ $t('driveView.videoPlaybackNotSupported') }}
              </video>
            </div>
            
            <!-- Audio preview -->
            <div v-else-if="previewFile.mime_type?.startsWith('audio/')" class="flex-1 flex flex-col items-center justify-center gap-4 p-6 md:p-8">
              <span class="material-symbols-rounded text-5xl md:text-6xl text-primary-500">audio_file</span>
              <p class="text-base md:text-lg font-medium text-surface-900 dark:text-surface-100 text-center">{{ previewFile.original_name }}</p>
              <audio v-if="previewBlobUrl" :src="previewBlobUrl" controls class="w-full max-w-md">
                {{ $t('driveView.audioPlaybackNotSupported') }}
              </audio>
            </div>
            
            <!-- PDF preview -->
            <div v-else-if="previewFile.mime_type === 'application/pdf'" class="flex-1 flex flex-col min-h-0">
              <!-- Desktop: iframe -->
              <iframe 
                v-if="previewBlobUrl"
                :src="previewBlobUrl" 
                class="hidden md:block w-full h-[80vh]"
                frameborder="0"
              ></iframe>
              <!-- Mobile: Download prompt (iframes often don't work well on mobile for PDFs) -->
              <div class="md:hidden flex-1 flex flex-col items-center justify-center gap-4 p-6 bg-surface-100 dark:bg-surface-900">
                <span class="material-symbols-rounded text-6xl text-red-500">picture_as_pdf</span>
                <h3 class="text-lg font-semibold text-surface-900 dark:text-surface-100 text-center">{{ previewFile.original_name }}</h3>
                <p class="text-surface-500">{{ formatSize(previewFile.size) }}</p>
                <p class="text-surface-600 dark:text-surface-400 text-center text-sm">{{ $t('driveView.tapDownloadToOpenThe') }}</p>
                <button @click="downloadFile(previewFile)" class="btn-primary mt-2">
                  <span class="material-symbols-rounded">download</span>
                  {{ $t('driveView.downloadPdf') }}
                </button>
              </div>
            </div>
            
            <!-- DOCX documents - rendered as HTML using mammoth.js -->
            <div v-else-if="isDocxFile(previewFile)" class="flex-1 overflow-auto bg-white dark:bg-surface-100 min-h-0">
              <div 
                v-if="docxHtmlContent" 
                class="docx-preview p-4"
                v-html="docxHtmlContent"
              ></div>
              <div v-else class="flex items-center justify-center h-full">
                <p class="text-surface-500">{{ $t('driveView.failedToLoadDocument') }}</p>
              </div>
            </div>
            
            <!-- Excel spreadsheets - rendered as HTML table -->
            <div v-else-if="isExcelFile(previewFile)" class="flex-1 flex flex-col min-h-0 bg-white dark:bg-surface-100">
              <!-- Sheet tabs -->
              <div v-if="excelSheets.length > 1" class="flex-shrink-0 flex items-center gap-1 px-4 py-2 bg-surface-100 dark:bg-surface-200 border-b border-surface-200 dark:border-surface-300 overflow-x-auto">
                <button
                  v-for="(sheet, idx) in excelSheets"
                  :key="idx"
                  @click="switchExcelSheet(idx)"
                  :class="[
                    'px-3 py-1.5 text-sm rounded-t-lg transition-colors whitespace-nowrap',
                    activeExcelSheet === idx 
                      ? 'bg-white dark:bg-surface-100 text-green-600 font-medium border-t border-x border-surface-200 dark:border-surface-300 -mb-px' 
                      : 'text-surface-600 hover:bg-surface-200 dark:hover:bg-surface-300'
                  ]"
                >
                  {{ sheet }}
                </button>
              </div>
              <!-- Spreadsheet content -->
              <div 
                v-if="excelHtmlContent" 
                class="excel-preview flex-1 overflow-auto p-2"
                v-html="excelHtmlContent"
              ></div>
              <div v-else class="flex items-center justify-center h-full">
                <p class="text-surface-500">{{ $t('driveView.failedToLoadSpreadsheet') }}</p>
              </div>
            </div>
            
            <!-- PowerPoint - show download prompt (no client-side viewer) -->
            <div v-else-if="isPptFile(previewFile)" class="flex-1 flex flex-col items-center justify-center p-6 md:p-8 text-center bg-surface-100 dark:bg-surface-900">
              <span class="material-symbols-rounded text-5xl md:text-6xl text-primary-500 mb-4">slideshow</span>
              <h3 class="text-base md:text-lg font-semibold text-surface-900 dark:text-surface-100 mb-2">
                {{ previewFile.original_name }}
              </h3>
              <p class="text-surface-500 mb-2">{{ formatSize(previewFile.size) }}</p>
              <p class="text-surface-600 dark:text-surface-400 mb-6 max-w-md text-sm md:text-base">
                {{ $t('driveView.powerPointDownloadHint') }}
              </p>
              <button @click="downloadFile(previewFile)" class="btn-primary">
                <span class="material-symbols-rounded">download</span>
                {{ $t('driveView.downloadFile') }}
              </button>
            </div>
            
            <!-- Markdown files (rendered, read-only) -->
            <div v-else-if="isMarkdownFile(previewFile)" class="flex-1 overflow-auto bg-white dark:bg-surface-800 min-h-0">
              <div v-if="previewLoading" class="flex items-center justify-center py-12">
                <span class="spinner text-primary-500 w-8 h-8"></span>
              </div>
              <div
                v-else-if="markdownHtml"
                class="markdown-preview prose prose-sm md:prose-base max-w-3xl mx-auto px-4 md:px-8 py-6 text-surface-800 dark:text-surface-100 dark:prose-invert"
                v-html="markdownHtml"
              ></div>
              <div v-else class="text-center py-12 text-surface-500 dark:text-surface-400">
                {{ $t('driveView.previewNotAvailableForThis') }}
              </div>
            </div>

            <!-- Text / JSON / code files -->
            <div v-else-if="isPlainTextFile(previewFile)" class="flex-1 overflow-auto bg-white dark:bg-surface-800 min-h-0">
              <div v-if="previewLoading" class="flex items-center justify-center py-12">
                <span class="spinner text-primary-500 w-8 h-8"></span>
              </div>
              <pre v-else class="text-sm text-surface-700 dark:text-surface-200 whitespace-pre-wrap font-mono p-4">{{ textContent }}</pre>
            </div>
            
            <!-- Unsupported format -->
            <div v-else class="flex-1 flex flex-col items-center justify-center p-6 md:p-8 text-center">
              <span class="material-symbols-rounded text-5xl md:text-6xl text-surface-400 mb-4">draft</span>
              <p class="text-surface-600 dark:text-surface-400">{{ $t('driveView.previewNotAvailableForThis') }}</p>
              <button @click="downloadFile(previewFile)" class="btn-primary mt-4">
                <span class="material-symbols-rounded">download</span>
                {{ $t('driveView.downloadFile') }}
              </button>
            </div>
          </div>
          
          <!-- Actions - Desktop only -->
          <div class="hidden md:flex absolute -bottom-14 right-0 gap-2">
            <button v-if="canEditPreviewFile(previewFile)" @click="editPreviewFile" class="btn-primary">
              <span class="material-symbols-rounded">edit_document</span>
              {{ $t('driveView.edit') }}
            </button>
            <button v-if="canPrintPreview" @click="printPreview" class="btn-secondary">
              <span class="material-symbols-rounded">print</span>
              {{ $t('driveView.print') }}
            </button>
            <button @click="downloadFile(previewFile)" class="btn-secondary">
              <span class="material-symbols-rounded">download</span>
              {{ $t('driveView.download') }}
            </button>
            <button @click="openShareModal(previewFile); closePreview()" class="btn-secondary">
              <span class="material-symbols-rounded">share</span>
              {{ $t('driveView.share') }}
            </button>
          </div>
        </div>
        </div>
        
        <!-- Mobile Thumbnail Strip -->
        <div 
          v-if="previewableFiles.length > 1" 
          class="md:hidden bg-black/70 py-2 px-1"
        >
          <div 
            ref="thumbnailStripRef"
            class="flex gap-1.5 overflow-x-auto scrollbar-hide px-2"
            style="scroll-snap-type: x mandatory; -webkit-overflow-scrolling: touch;"
          >
            <button 
              v-for="(file, idx) in previewableFiles" 
              :key="file.id"
              @click="goToPreview(idx)"
              :class="[
                'flex-shrink-0 w-14 h-14 rounded-lg overflow-hidden border-2 transition-all scroll-snap-align-center',
                idx === previewIndex 
                  ? 'border-primary-500 ring-2 ring-primary-500/50 scale-105' 
                  : 'border-transparent opacity-60 hover:opacity-100'
              ]"
              style="scroll-snap-align: center;"
            >
              <!-- Image thumbnail -->
              <template v-if="file.mime_type?.startsWith('image/')">
                <img 
                  v-if="drive.hasThumbnail(file.id)"
                  :src="getCachedThumbnail(file)" 
                  :alt="file.original_name"
                  class="w-full h-full object-cover"
                />
                <!-- Loading state -->
                <div v-else-if="getCachedThumbnail(file) === 'loading'" class="w-full h-full bg-surface-800 flex items-center justify-center">
                  <span class="material-symbols-rounded text-surface-500 text-lg animate-spin">progress_activity</span>
                </div>
                <!-- Fallback/trigger load -->
                <div v-else class="w-full h-full bg-surface-800 flex items-center justify-center" :data-load="getThumbnailUrl(file)">
                  <span class="material-symbols-rounded text-surface-500 text-lg">image</span>
                </div>
              </template>
              <!-- Video thumbnail -->
              <template v-else-if="file.mime_type?.startsWith('video/')">
                <div class="w-full h-full bg-surface-800 flex items-center justify-center">
                  <span class="material-symbols-rounded text-blue-400 text-lg">videocam</span>
                </div>
              </template>
              <!-- Audio thumbnail -->
              <template v-else-if="file.mime_type?.startsWith('audio/')">
                <div class="w-full h-full bg-surface-800 flex items-center justify-center">
                  <span class="material-symbols-rounded text-purple-400 text-lg">audio_file</span>
                </div>
              </template>
              <!-- PDF thumbnail -->
              <template v-else-if="file.mime_type === 'application/pdf'">
                <div class="w-full h-full bg-surface-800 flex items-center justify-center">
                  <span class="material-symbols-rounded text-red-400 text-lg">picture_as_pdf</span>
                </div>
              </template>
              <!-- Document thumbnail -->
              <template v-else>
                <div class="w-full h-full bg-surface-800 flex items-center justify-center">
                  <span :class="['material-symbols-rounded text-lg', getFileIconInfo(file.mime_type).color]">
                    {{ getFileIconInfo(file.mime_type).icon }}
                  </span>
                </div>
              </template>
            </button>
          </div>
        </div>
        
        <!-- Mobile Footer with actions and navigation -->
        <div class="md:hidden flex items-center justify-between p-3 bg-black/50 safe-area-bottom">
          <!-- Prev button -->
          <button 
            v-if="previewableFiles.length > 1"
            @click="prevPreview"
            class="w-12 h-12 rounded-full bg-white/10 flex items-center justify-center text-white"
          >
            <span class="material-symbols-rounded text-2xl">chevron_left</span>
          </button>
          <div v-else class="w-12"></div>
          
          <!-- Center actions -->
          <div class="flex items-center gap-3">
            <button
              v-if="canEditPreviewFile(previewFile)"
              @click="editPreviewFile"
              class="w-12 h-12 rounded-full bg-primary-500 flex items-center justify-center text-white"
              :title="$t('driveView.edit')"
            >
              <span class="material-symbols-rounded text-xl">edit_document</span>
            </button>
            <button @click="downloadFile(previewFile)" class="w-12 h-12 rounded-full bg-white/10 flex items-center justify-center text-white">
              <span class="material-symbols-rounded text-xl">download</span>
            </button>
            <button @click="openShareModal(previewFile); closePreview()" class="w-12 h-12 rounded-full bg-white/10 flex items-center justify-center text-white">
              <span class="material-symbols-rounded text-xl">share</span>
            </button>
          </div>
          
          <!-- Next button -->
          <button 
            v-if="previewableFiles.length > 1"
            @click="nextPreview"
            class="w-12 h-12 rounded-full bg-white/10 flex items-center justify-center text-white"
          >
            <span class="material-symbols-rounded text-2xl">chevron_right</span>
          </button>
          <div v-else class="w-12"></div>
        </div>
      </div>
    </Teleport>
    
    <!-- Folder Context Menu (sidebar) -->
    <Teleport to="body">
      <div 
        v-if="contextMenu.show" 
        ref="folderMenuRef"
        class="fixed z-50 bg-white dark:bg-surface-800 rounded-lg shadow-xl border border-surface-200 dark:border-surface-700 py-1 min-w-[160px]"
        :style="{ left: contextMenu.x + 'px', top: contextMenu.y + 'px' }"
        @click.stop
      >
        <button 
          @click="openNewFolderModal(contextMenu.folder?.id); closeContextMenu()"
          class="w-full flex items-center gap-3 px-4 py-2 text-sm text-left hover:bg-surface-100 dark:hover:bg-surface-700 transition-colors"
        >
          <span class="material-symbols-rounded text-lg">create_new_folder</span>
          New Subfolder
        </button>
        <button 
          @click="startRename(contextMenu.folder, 'folder'); closeContextMenu()"
          class="w-full flex items-center gap-3 px-4 py-2 text-sm text-left hover:bg-surface-100 dark:hover:bg-surface-700 transition-colors"
        >
          <span class="material-symbols-rounded text-lg">edit</span>
          Rename
        </button>
        <div class="h-px bg-surface-200 dark:bg-surface-700 my-1"></div>
        <button 
          @click="confirmDelete(contextMenu.folder, 'folder'); closeContextMenu()"
          class="w-full flex items-center gap-3 px-4 py-2 text-sm text-left text-red-500 hover:bg-red-50 dark:hover:bg-red-900/20 transition-colors"
        >
          <span class="material-symbols-rounded text-lg">delete</span>
          Delete
        </button>
      </div>
    </Teleport>
    
    <!-- Trash Context Menu (sidebar) -->
    <Teleport to="body">
      <div 
        v-if="trashContextMenu.show" 
        ref="trashMenuRef"
        class="fixed z-50 bg-white dark:bg-surface-800 rounded-lg shadow-xl border border-surface-200 dark:border-surface-700 py-1 min-w-[160px]"
        :style="{ left: trashContextMenu.x + 'px', top: trashContextMenu.y + 'px' }"
        @click.stop
      >
        <button 
          @click="drive.enterTrashView(); closeTrashContextMenu()"
          class="w-full flex items-center gap-3 px-4 py-2 text-sm text-left hover:bg-surface-100 dark:hover:bg-surface-700 transition-colors"
        >
          <span class="material-symbols-rounded text-lg">folder_open</span>
          Open Trash
        </button>
        <div class="h-px bg-surface-200 dark:bg-surface-700 my-1"></div>
        <button 
          @click="closeTrashContextMenu(); showEmptyTrashConfirm = true"
          class="w-full flex items-center gap-3 px-4 py-2 text-sm text-left text-red-500 hover:bg-red-50 dark:hover:bg-red-900/20 transition-colors"
        >
          <span class="material-symbols-rounded text-lg">delete_forever</span>
          Empty Trash
        </button>
      </div>
    </Teleport>
    
    <!-- Content Area Context Menu (right-click on files/folders) -->
    <Teleport to="body">
      <div 
        v-if="contentContextMenu.show" 
        ref="contentMenuRef"
        class="fixed z-50 bg-white dark:bg-surface-800 rounded-lg shadow-xl border border-surface-200 dark:border-surface-700 py-1 min-w-[180px] max-h-[calc(100vh-16px)] overflow-y-auto"
        :style="{ left: contentContextMenu.x + 'px', top: contentContextMenu.y + 'px' }"
        @click.stop
      >
        <button 
          @click="contextMenuOpen"
          class="w-full flex items-center gap-3 px-4 py-2 text-sm text-left hover:bg-surface-100 dark:hover:bg-surface-700 transition-colors"
        >
          <span class="material-symbols-rounded text-lg">{{ contentContextMenu.type === 'folder' ? 'folder_open' : (contentContextMenu.item?.is_collab_document ? 'edit_document' : 'visibility') }}</span>
          {{ contentContextMenu.type === 'folder' ? 'Open' : (contentContextMenu.item?.is_collab_document ? 'Open in Editor' : 'Preview') }}
        </button>
        <!-- Edit in Editor - for DOCX/PPTX files -->
        <button 
          v-if="contentContextMenu.type === 'file' && canOpenInCollab(contentContextMenu.item) && !contentContextMenu.item?.is_collab_document"
          @click="openInCollabEditor(contentContextMenu.item); closeContentContextMenu()"
          class="w-full flex items-center gap-3 px-4 py-2 text-sm text-left hover:bg-primary-50 dark:hover:bg-primary-900/20 transition-colors text-primary-600 dark:text-primary-400"
        >
          <span class="material-symbols-rounded text-lg">edit_document</span>
          Edit in Editor
        </button>
        <!-- Open in Office (OnlyOffice) - for docx/xlsx/pptx Drive files -->
        <button
          v-if="contentContextMenu.type === 'file' && canOpenInOffice(contentContextMenu.item)"
          @click="openInOffice(contentContextMenu.item); closeContentContextMenu()"
          class="w-full flex items-center gap-3 px-4 py-2 text-sm text-left hover:bg-primary-50 dark:hover:bg-primary-900/20 transition-colors text-primary-600 dark:text-primary-400"
        >
          <span class="material-symbols-rounded text-lg">edit_note</span>
          {{ $t('officeEditor.openInOffice') }}
        </button>
        <!-- Download - not for collab documents -->
        <button 
          v-if="!contentContextMenu.item?.is_collab_document"
          @click="contextMenuDownload"
          class="w-full flex items-center gap-3 px-4 py-2 text-sm text-left hover:bg-surface-100 dark:hover:bg-surface-700 transition-colors"
        >
          <span class="material-symbols-rounded text-lg">{{ contextMenuDownloadIcon }}</span>
          {{ contextMenuDownloadLabel }}
        </button>
        <div class="h-px bg-surface-200 dark:bg-surface-700 my-1"></div>

        <!-- Cut / Copy / Paste / Move -->
        <button
          @click="contextMenuCut"
          class="w-full flex items-center gap-3 px-4 py-2 text-sm text-left hover:bg-surface-100 dark:hover:bg-surface-700 transition-colors"
        >
          <span class="material-symbols-rounded text-lg">content_cut</span>
          {{ $t('driveView.cut') }}<span v-if="contextMenuBulkCount > 1" class="ml-auto text-[10px] text-surface-400 bg-surface-100 dark:bg-surface-700 px-1.5 py-0.5 rounded-full">{{ contextMenuBulkCount }}</span>
        </button>
        <button
          @click="contextMenuCopy"
          class="w-full flex items-center gap-3 px-4 py-2 text-sm text-left hover:bg-surface-100 dark:hover:bg-surface-700 transition-colors"
        >
          <span class="material-symbols-rounded text-lg">content_copy</span>
          {{ $t('driveView.copy') }}<span v-if="contextMenuBulkCount > 1" class="ml-auto text-[10px] text-surface-400 bg-surface-100 dark:bg-surface-700 px-1.5 py-0.5 rounded-full">{{ contextMenuBulkCount }}</span>
        </button>
        <button
          v-if="drive.hasClipboard"
          @click="contextMenuPaste"
          class="w-full flex items-center gap-3 px-4 py-2 text-sm text-left hover:bg-surface-100 dark:hover:bg-surface-700 transition-colors"
        >
          <span class="material-symbols-rounded text-lg">content_paste</span>
          {{ $t('driveView.paste') }}
          <span class="ml-auto text-[10px] text-surface-400 bg-surface-100 dark:bg-surface-700 px-1.5 py-0.5 rounded-full">{{ drive.clipboardCount }} {{ drive.clipboard.mode === 'cut' ? $t('driveView.cutLabel') : $t('driveView.copyLabel') }}</span>
        </button>
        <button
          @click="contextMenuMoveTo"
          class="w-full flex items-center gap-3 px-4 py-2 text-sm text-left hover:bg-surface-100 dark:hover:bg-surface-700 transition-colors"
        >
          <span class="material-symbols-rounded text-lg">drive_file_move</span>
          {{ $t('driveView.moveTo') }}<span v-if="contextMenuBulkCount > 1" class="ml-auto text-[10px] text-surface-400 bg-surface-100 dark:bg-surface-700 px-1.5 py-0.5 rounded-full">{{ contextMenuBulkCount }}</span>
        </button>
        <div class="h-px bg-surface-200 dark:bg-surface-700 my-1"></div>
        
        <!-- Color picker for folders -->
        <div v-if="contentContextMenu.type === 'folder'" class="px-4 py-2">
          <div class="text-xs text-surface-500 mb-2 flex items-center gap-2">
            <span class="material-symbols-rounded text-sm">palette</span>
            {{ $t('driveView.folderColor') }}
          </div>
          <div class="flex flex-wrap gap-1.5">
            <button
              v-for="color in folderColors"
              :key="color.id"
              @click="changeFolderColor(contentContextMenu.item, color.id); closeContentContextMenu()"
              :title="color.name"
              :class="[
                'w-6 h-6 rounded-full transition-transform hover:scale-110 flex items-center justify-center',
                color.bg,
                contentContextMenu.item?.color === color.id ? 'ring-2 ring-offset-2 ring-surface-400 dark:ring-offset-surface-800' : ''
              ]"
            >
              <span v-if="contentContextMenu.item?.color === color.id" class="material-symbols-rounded text-xs text-white">check</span>
            </button>
            <!-- Auto/Reset option -->
            <button
              @click="changeFolderColor(contentContextMenu.item, null); closeContentContextMenu()"
              :title="$t('driveView.autoReset')"
              :class="[
                'w-6 h-6 rounded-full border-2 border-dashed border-surface-400 transition-transform hover:scale-110 flex items-center justify-center',
                !contentContextMenu.item?.color ? 'bg-surface-200 dark:bg-surface-600' : ''
              ]"
            >
              <span class="material-symbols-rounded text-xs text-surface-500">auto_awesome</span>
            </button>
          </div>
        </div>
        <div v-if="contentContextMenu.type === 'folder'" class="h-px bg-surface-200 dark:bg-surface-700 my-1"></div>
        
        <!-- Share Link - not for collab documents (they have their own sharing) -->
        <button 
          v-if="!contentContextMenu.item?.is_collab_document"
          @click="contextMenuShare"
          class="w-full flex items-center gap-3 px-4 py-2 text-sm text-left hover:bg-surface-100 dark:hover:bg-surface-700 transition-colors"
        >
          <span class="material-symbols-rounded text-lg">share</span>
          {{ $t('driveView.shareLink') }}
        </button>
        <!-- Collab Share - for collab documents -->
        <button 
          v-if="contentContextMenu.item?.is_collab_document"
          @click="openCollabShareFromContext(); closeContentContextMenu()"
          class="w-full flex items-center gap-3 px-4 py-2 text-sm text-left hover:bg-surface-100 dark:hover:bg-surface-700 transition-colors"
        >
          <span class="material-symbols-rounded text-lg">group_add</span>
          {{ $t('driveView.shareWithPeople') }}
        </button>
        <!-- Version History - for regular files, not collab documents -->
        <button 
          v-if="contentContextMenu.type === 'file' && !contentContextMenu.item?.is_collab_document"
          @click="openVersionsSidebar(contentContextMenu.item); closeContentContextMenu()"
          class="w-full flex items-center gap-3 px-4 py-2 text-sm text-left hover:bg-surface-100 dark:hover:bg-surface-700 transition-colors"
        >
          <span class="material-symbols-rounded text-lg">history</span>
          {{ $t('driveView.versionHistory') }}
        </button>
        <div class="h-px bg-surface-200 dark:bg-surface-700 my-1"></div>
        <button 
          @click="contextMenuRename"
          class="w-full flex items-center gap-3 px-4 py-2 text-sm text-left hover:bg-surface-100 dark:hover:bg-surface-700 transition-colors"
        >
          <span class="material-symbols-rounded text-lg">edit</span>
          {{ $t('driveView.rename') }}
        </button>
        <button 
          @click="contextMenuProperties"
          class="w-full flex items-center gap-3 px-4 py-2 text-sm text-left hover:bg-surface-100 dark:hover:bg-surface-700 transition-colors"
        >
          <span class="material-symbols-rounded text-lg">info</span>
          {{ $t('driveView.properties') }}
        </button>
        <div class="h-px bg-surface-200 dark:bg-surface-700 my-1"></div>
        <button 
          @click="contextMenuDelete"
          class="w-full flex items-center gap-3 px-4 py-2 text-sm text-left text-red-500 hover:bg-red-50 dark:hover:bg-red-900/20 transition-colors"
        >
          <span class="material-symbols-rounded text-lg">delete</span>
          {{ $t('driveView.delete') }}
        </button>
      </div>
    </Teleport>

    <!-- Background Context Menu (right-click on empty space - paste) -->
    <Teleport to="body">
      <div
        v-if="bgContextMenu.show"
        ref="bgMenuRef"
        class="fixed z-50 bg-white dark:bg-surface-800 rounded-lg shadow-xl border border-surface-200 dark:border-surface-700 py-1 min-w-[160px]"
        :style="{ left: bgContextMenu.x + 'px', top: bgContextMenu.y + 'px' }"
        @click.stop
      >
        <button
          @click="bgContextMenuPaste"
          class="w-full flex items-center gap-3 px-4 py-2 text-sm text-left hover:bg-surface-100 dark:hover:bg-surface-700 transition-colors"
        >
          <span class="material-symbols-rounded text-lg">content_paste</span>
          {{ $t('driveView.pasteHere') }}
          <span class="ml-auto text-[10px] text-surface-400 bg-surface-100 dark:bg-surface-700 px-1.5 py-0.5 rounded-full">{{ drive.clipboardCount }}</span>
        </button>
      </div>
    </Teleport>
    
    <!-- Bulk Upload Progress Overlay -->
    <Teleport to="body">
      <Transition
        enter-active-class="transition-all duration-300 ease-out"
        leave-active-class="transition-all duration-200 ease-in"
        enter-from-class="opacity-0 translate-y-4"
        leave-to-class="opacity-0 translate-y-4"
      >
        <div 
          v-if="drive.bulkUpload.active" 
          class="fixed bottom-20 left-4 right-4 md:bottom-8 md:left-auto md:right-8 md:w-96 z-50"
        >
          <div class="bg-white dark:bg-surface-800 rounded-xl shadow-xl border border-surface-200 dark:border-surface-700 p-4">
            <!-- Header -->
            <div class="flex items-center justify-between mb-3">
              <div class="flex items-center gap-2">
                <span class="material-symbols-rounded text-primary-500 animate-pulse">cloud_upload</span>
                <span class="font-medium text-surface-900 dark:text-surface-100">
                  {{ $t('driveView.uploadingProgress', { current: drive.bulkUpload.current, total: drive.bulkUpload.total }) }}
                </span>
              </div>
              <span class="text-sm text-surface-500">
                {{ $t('driveView.uploadDoneCount', { count: drive.bulkUpload.completed }) }}
              </span>
            </div>
            
            <!-- Current file name -->
            <p class="text-sm text-surface-600 dark:text-surface-400 truncate mb-2">
              {{ drive.bulkUpload.currentFileName }}
            </p>
            
            <!-- Current file progress bar -->
            <div class="h-2 bg-surface-200 dark:bg-surface-700 rounded-full overflow-hidden mb-2">
              <div 
                class="h-full bg-primary-500 transition-all duration-150"
                :style="{ width: drive.bulkUpload.currentProgress + '%' }"
              ></div>
            </div>
            
            <!-- Overall progress bar -->
            <div class="h-1.5 bg-surface-200 dark:bg-surface-700 rounded-full overflow-hidden">
              <div 
                class="h-full bg-primary-300 dark:bg-primary-600 transition-all duration-150"
                :style="{ width: Math.min(100, ((drive.bulkUpload.current - 1) + drive.bulkUpload.currentProgress / 100) / drive.bulkUpload.total * 100) + '%' }"
              ></div>
            </div>
            
            <!-- Stats row -->
            <div class="flex items-center justify-between mt-2 text-xs text-surface-500">
              <span>{{ Math.min(100, Math.round(((drive.bulkUpload.current - 1) + drive.bulkUpload.currentProgress / 100) / drive.bulkUpload.total * 100)) }}% overall</span>
              <span v-if="drive.bulkUpload.failed > 0" class="text-red-500">
                {{ drive.bulkUpload.failed }} failed
              </span>
            </div>
          </div>
        </div>
      </Transition>
    </Teleport>
    
    <!-- Mobile bottom navigation -->
    <MobileBottomNav v-if="isMobile" :show-todo-button="false" />
    
    <!-- Mobile floating upload button -->
    <button 
      v-if="isMobile && !showPreview"
      @click="triggerUpload"
      class="btn-primary mobile-fab"
      :disabled="drive.uploading"
    >
      <span v-if="drive.uploading" class="spinner" style="width: 1.5rem; height: 1.5rem;"></span>
      <span v-else class="material-symbols-rounded" style="font-size: 1.5rem; line-height: 1;">add</span>
    </button>
    
    <!-- Mobile Action Sheet -->
    <Teleport to="body">
      <div 
        v-if="showMobileActions" 
        class="fixed inset-0 z-50 flex items-end bg-black/50"
        @click.self="closeMobileActions"
      >
        <div class="w-full bg-white dark:bg-surface-800 rounded-t-2xl shadow-lg safe-area-bottom">
          <!-- Header -->
          <div class="flex items-center justify-between p-4 border-b border-surface-200 dark:border-surface-700">
            <div class="flex items-center gap-3 min-w-0">
              <span :class="[
                'material-symbols-rounded text-2xl',
                mobileActionType === 'folder' ? 'text-amber-500' : getFileIconInfo(mobileActionItem?.mime_type).color
              ]">
                {{ mobileActionType === 'folder' ? 'folder' : getFileIconInfo(mobileActionItem?.mime_type).icon }}
              </span>
              <span class="font-medium text-surface-900 dark:text-surface-100 truncate">
                {{ mobileActionItem?.name || mobileActionItem?.original_name }}
              </span>
            </div>
            <button @click="closeMobileActions" class="btn-ghost btn-icon">
              <span class="material-symbols-rounded">close</span>
            </button>
          </div>
          
          <!-- Actions -->
          <div class="p-2">
            <!-- File actions -->
            <template v-if="mobileActionType === 'file'">
              <!-- Open in editor - same priority cascade as the desktop
                   double-click (collab doc -> Office editor -> preview ->
                   download), so docx/xlsx/pptx open for editing on mobile. -->
              <button
                v-if="mobileActionItem && (canOpenInOffice(mobileActionItem) || canOpenInCollab(mobileActionItem) || mobileActionItem.is_collab_document)"
                @click="handleFileDoubleClick(null, mobileActionItem); closeMobileActions()"
                class="w-full flex items-center gap-4 px-4 py-3 rounded-lg hover:bg-surface-100 dark:hover:bg-surface-700"
              >
                <span class="material-symbols-rounded text-primary-500">open_in_new</span>
                <span class="text-surface-700 dark:text-surface-200">{{ $t('driveView.open') }}</span>
              </button>
              <!-- Preview - only for supported files (images, videos, PDFs, Office docs) -->
              <button 
                v-if="mobileActionItem && (canPreview(mobileActionItem) || canUseOfficeViewer(mobileActionItem))"
                @click="openPreview(mobileActionItem); closeMobileActions()"
                class="w-full flex items-center gap-4 px-4 py-3 rounded-lg hover:bg-surface-100 dark:hover:bg-surface-700"
              >
                <span class="material-symbols-rounded text-surface-500">visibility</span>
                <span class="text-surface-700 dark:text-surface-200">{{ $t('driveView.preview') }}</span>
              </button>
              <button 
                @click="downloadFile(mobileActionItem); closeMobileActions()"
                class="w-full flex items-center gap-4 px-4 py-3 rounded-lg hover:bg-surface-100 dark:hover:bg-surface-700"
              >
                <span class="material-symbols-rounded text-surface-500">download</span>
                <span class="text-surface-700 dark:text-surface-200">{{ $t('driveView.download') }}</span>
              </button>
              <button 
                @click="openShareModal(mobileActionItem); closeMobileActions()"
                class="w-full flex items-center gap-4 px-4 py-3 rounded-lg hover:bg-surface-100 dark:hover:bg-surface-700"
              >
                <span class="material-symbols-rounded text-surface-500">share</span>
                <span class="text-surface-700 dark:text-surface-200">{{ $t('driveView.share') }}</span>
              </button>
              <button 
                @click="startRename(mobileActionItem, 'file'); closeMobileActions()"
                class="w-full flex items-center gap-4 px-4 py-3 rounded-lg hover:bg-surface-100 dark:hover:bg-surface-700"
              >
                <span class="material-symbols-rounded text-surface-500">edit</span>
                <span class="text-surface-700 dark:text-surface-200">{{ $t('driveView.rename') }}</span>
              </button>
              <button 
                @click="openPropertiesPanel(mobileActionItem, 'file'); closeMobileActions()"
                class="w-full flex items-center gap-4 px-4 py-3 rounded-lg hover:bg-surface-100 dark:hover:bg-surface-700"
              >
                <span class="material-symbols-rounded text-surface-500">info</span>
                <span class="text-surface-700 dark:text-surface-200">{{ $t('driveView.properties') }}</span>
              </button>
              <button 
                @click="confirmDelete(mobileActionItem, 'file'); closeMobileActions()"
                class="w-full flex items-center gap-4 px-4 py-3 rounded-lg hover:bg-red-50 dark:hover:bg-red-900/20"
              >
                <span class="material-symbols-rounded text-red-500">delete</span>
                <span class="text-red-500">{{ $t('driveView.delete') }}</span>
              </button>
            </template>
            
            <!-- Folder actions -->
            <template v-if="mobileActionType === 'folder'">
              <button 
                @click="drive.navigateToFolder(mobileActionItem.id); closeMobileActions()"
                class="w-full flex items-center gap-4 px-4 py-3 rounded-lg hover:bg-surface-100 dark:hover:bg-surface-700"
              >
                <span class="material-symbols-rounded text-surface-500">folder_open</span>
                <span class="text-surface-700 dark:text-surface-200">{{ $t('driveView.open') }}</span>
              </button>
              <button 
                @click="openShareFolderModal(mobileActionItem); closeMobileActions()"
                class="w-full flex items-center gap-4 px-4 py-3 rounded-lg hover:bg-surface-100 dark:hover:bg-surface-700"
              >
                <span class="material-symbols-rounded text-surface-500">share</span>
                <span class="text-surface-700 dark:text-surface-200">{{ $t('driveView.share') }}</span>
              </button>
              <button 
                @click="startRename(mobileActionItem, 'folder'); closeMobileActions()"
                class="w-full flex items-center gap-4 px-4 py-3 rounded-lg hover:bg-surface-100 dark:hover:bg-surface-700"
              >
                <span class="material-symbols-rounded text-surface-500">edit</span>
                <span class="text-surface-700 dark:text-surface-200">{{ $t('driveView.rename') }}</span>
              </button>
              <button 
                @click="openPropertiesPanel(mobileActionItem, 'folder'); closeMobileActions()"
                class="w-full flex items-center gap-4 px-4 py-3 rounded-lg hover:bg-surface-100 dark:hover:bg-surface-700"
              >
                <span class="material-symbols-rounded text-surface-500">info</span>
                <span class="text-surface-700 dark:text-surface-200">{{ $t('driveView.properties') }}</span>
              </button>
              <button 
                @click="confirmDelete(mobileActionItem, 'folder'); closeMobileActions()"
                class="w-full flex items-center gap-4 px-4 py-3 rounded-lg hover:bg-red-50 dark:hover:bg-red-900/20"
              >
                <span class="material-symbols-rounded text-red-500">delete</span>
                <span class="text-red-500">{{ $t('driveView.delete') }}</span>
              </button>
            </template>
          </div>
          
          <!-- Cancel -->
          <div class="p-2 border-t border-surface-200 dark:border-surface-700">
            <button 
              @click="closeMobileActions"
              class="w-full py-3 rounded-lg text-center font-medium text-surface-500 hover:bg-surface-100 dark:hover:bg-surface-700"
            >
              {{ $t('driveView.cancel') }}
            </button>
          </div>
        </div>
      </div>
    </Teleport>
    
    <!-- File Versions Sidebar Panel -->
    <Transition name="slide-right">
      <DriveVersionsPanel
        v-if="showVersionsSidebar && versionsFile"
        :file="versionsFile"
        @close="closeVersionsSidebar"
      />
    </Transition>
    
    <!-- Backdrop for sidebar -->
    <Transition name="fade">
      <div 
        v-if="showVersionsSidebar"
        @click="closeVersionsSidebar"
        class="fixed inset-0 bg-black/20 z-40"
      ></div>
    </Transition>
    
    <!-- Empty Trash Confirmation Modal (from sidebar right-click) -->
    <Teleport to="body">
      <div 
        v-if="showEmptyTrashConfirm" 
        class="fixed inset-0 z-50 flex items-center justify-center bg-black/50"
        @click.self="showEmptyTrashConfirm = false"
      >
        <div class="bg-white dark:bg-surface-800 rounded-2xl p-6 w-full max-w-md shadow-xl mx-4">
          <div class="flex items-center gap-3 mb-4">
            <div class="w-10 h-10 rounded-full bg-red-100 dark:bg-red-500/20 flex items-center justify-center">
              <span class="material-symbols-rounded text-xl text-red-500">delete_forever</span>
            </div>
            <h3 class="text-lg font-semibold text-surface-900 dark:text-surface-100">{{ $t('driveTrashView.emptyTrash') }}</h3>
          </div>
          
          <p class="text-surface-600 dark:text-surface-400 mb-6">
            {{ $t('driveTrashView.emptyTrashWarning') }}
          </p>
          
          <div class="flex justify-end gap-3">
            <button @click="showEmptyTrashConfirm = false" class="btn-secondary">
              {{ $t('driveView.cancel') }}
            </button>
            <button @click="emptyTrashFromSidebar" class="btn-primary !bg-red-500 hover:!bg-red-600">
              <span class="material-symbols-rounded">delete_forever</span>
              {{ $t('driveTrashView.emptyTrashAction') }}
            </button>
          </div>
        </div>
      </div>
    </Teleport>
    
    <!-- Click outside to close trash context menu -->
    <Teleport to="body">
      <div 
        v-if="trashContextMenu.show"
        class="fixed inset-0 z-40"
        @click="closeTrashContextMenu"
      ></div>
    </Teleport>
    
    <!-- New Document Modal -->
    <Teleport to="body">
      <Transition name="fade">
        <div 
          v-if="showNewDocumentModal"
          class="fixed inset-0 z-50 flex items-center justify-center bg-black/50"
          @click.self="showNewDocumentModal = false"
        >
          <div class="bg-white dark:bg-surface-800 rounded-2xl shadow-2xl w-full max-w-md mx-4 overflow-hidden">
            <div class="px-6 py-4 border-b border-surface-200 dark:border-surface-700">
              <div class="flex items-center gap-3">
                <span class="material-symbols-rounded text-primary-500">
                  {{ newDocumentType === 'presentation' ? 'slideshow' : 'article' }}
                </span>
                <h2 class="text-lg font-semibold text-surface-900 dark:text-surface-100">
                  {{ newDocumentType === 'presentation' ? $t('driveView.newPresentation') : $t('driveView.newDocument') }}
                </h2>
              </div>
            </div>
            <div class="p-6">
              <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-2">
                {{ $t('driveView.title') }}
              </label>
              <input 
                v-model="newDocumentTitle"
                type="text"
                class="input w-full"
                :placeholder="newDocumentType === 'presentation' ? $t('driveView.untitledPresentation') : $t('driveView.untitledDocument')"
                @keyup.enter="createNewCollabDocument"
              />
              <p class="text-xs text-surface-500 mt-2">
                {{ newDocumentType === 'presentation' ? $t('driveView.newCollabPresentationDescription') : $t('driveView.newCollabDocumentDescription') }}
              </p>
            </div>
            <div class="px-6 py-4 bg-surface-50 dark:bg-surface-900/50 flex justify-end gap-3">
              <button 
                @click="showNewDocumentModal = false"
                class="btn-secondary"
              >
                {{ $t('driveView.cancel') }}
              </button>
              <button 
                @click="createNewCollabDocument"
                class="btn-primary"
              >
                <span class="material-symbols-rounded">add</span>
                {{ $t('driveView.create') }}
              </button>
            </div>
          </div>
        </div>
      </Transition>
    </Teleport>
    
    <!-- Collab Editor Modal (Full Screen) -->
    <Teleport to="body">
      <Transition name="fade">
        <div 
          v-if="showCollabEditor"
          class="fixed inset-0 z-50 flex flex-col"
        >
          <!-- Editor Content (full screen, editor has its own header) -->
          <CollabDocumentEditor 
            v-if="collabEditorMode === 'document' && collabDocumentId"
            :document-uuid="collabDocumentId"
            :user="{ email: authStore.user?.email, name: authStore.user?.name || authStore.user?.email }"
            :drive-file-id="collabDriveFileId"
            @close="closeCollabEditor"
            @share="openCollabShareModal"
            @versions="openVersionHistory"
          />
          <CollabPresentationEditor 
            v-else-if="collabEditorMode === 'presentation' && collabDocumentId"
            :document-uuid="collabDocumentId"
            :user="{ email: authStore.user?.email, name: authStore.user?.name || authStore.user?.email }"
            @close="closeCollabEditor"
            @share="openCollabShareModal"
            @ready="handlePresentationEditorReady"
          />
          <div
            v-if="openingPresentationEditor && collabEditorMode === 'presentation'"
            class="absolute inset-0 z-[60] flex flex-col items-center justify-center gap-4 bg-white/92 dark:bg-surface-950/92 backdrop-blur-sm"
          >
            <span class="material-symbols-rounded text-5xl text-primary-500 animate-spin">progress_activity</span>
            <div class="text-center">
              <div class="text-base font-semibold text-surface-800 dark:text-surface-100">Opening presentation...</div>
              <div class="text-sm text-surface-500 dark:text-surface-400">Importing slides and preparing the editor</div>
            </div>
          </div>
        </div>
      </Transition>
    </Teleport>
    
    <!-- Collab Share Modal -->
    <CollabShareModal
      v-if="showCollabShareModal && collabDocumentId"
      :show="showCollabShareModal"
      :document-uuid="collabDocumentId"
      :current-user-email="authStore.user?.email"
      @close="showCollabShareModal = false"
    />
    
    <!-- ZIP Debug Panel -->
    <ZipDebugPanel ref="zipDebugPanelRef" />
    
    <!-- Collab Version History Panel -->
    <CollabVersionHistoryPanel
      v-if="showVersionHistory && collabDocumentId"
      :show="showVersionHistory"
      :document-uuid="collabDocumentId"
      :document-title="collabDocumentTitle || t('driveView.documentFallback')"
      @close="showVersionHistory = false"
      @restored="handleVersionRestored"
    />

    <StepGuide
      v-if="showStepGuide"
      :title-key="driveGuide.titleKey"
      :subtitle-key="driveGuide.subtitleKey"
      :header-icon="driveGuide.headerIcon"
      :header-color="driveGuide.headerColor"
      :storage-key="driveGuide.storageKey"
      :steps="driveGuide.steps"
      @close="showStepGuide = false"
    />
    
  </div>
</template>

<style scoped>
/* Hide scrollbar but keep scroll functionality */
.scrollbar-hide {
  -ms-overflow-style: none;
  scrollbar-width: none;
}
.scrollbar-hide::-webkit-scrollbar {
  display: none;
}

/* Prevent text selection on grid/list items and header */
:deep(.drive-list-view),
:deep(.drive-list-view *) {
  user-select: none;
  -webkit-user-select: none;
}

/* Drag transition for selected files */
[draggable="true"] {
  transition: opacity 0.15s ease, transform 0.15s ease;
}

/* Folder drop target highlight */
.ring-2.ring-primary-500 {
  transition: all 0.15s ease;
}

/* Dropdown transition */
.dropdown-enter-active,
.dropdown-leave-active {
  transition: all 0.2s ease;
}

.dropdown-enter-from,
.dropdown-leave-to {
  opacity: 0;
  transform: translateY(-8px);
}

/* Slide from right transition */
.slide-right-enter-active,
.slide-right-leave-active {
  transition: transform 0.3s ease;
}

.slide-right-enter-from,
.slide-right-leave-to {
  transform: translateX(100%);
}

/* Fade transition */
.fade-enter-active,
.fade-leave-active {
  transition: opacity 0.3s ease;
}

.fade-enter-from,
.fade-leave-to {
  opacity: 0;
}

/* Excel preview styles */
.excel-preview :deep(table) {
  border-collapse: collapse;
  width: 100%;
  font-size: 13px;
  font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
}

.excel-preview :deep(th),
.excel-preview :deep(td) {
  border: 1px solid #d1d5db;
  padding: 6px 10px;
  text-align: left;
  white-space: nowrap;
  max-width: 300px;
  overflow: hidden;
  text-overflow: ellipsis;
}

.excel-preview :deep(th) {
  background-color: #f3f4f6;
  font-weight: 600;
  color: #374151;
  position: sticky;
  top: 0;
  z-index: 1;
}

.excel-preview :deep(tr:nth-child(even)) {
  background-color: #f9fafb;
}

.excel-preview :deep(tr:hover) {
  background-color: #e5e7eb;
}

/* Dark mode Excel styles */
@media (prefers-color-scheme: dark) {
  .excel-preview :deep(th),
  .excel-preview :deep(td) {
    border-color: #4b5563;
  }
  
  .excel-preview :deep(th) {
    background-color: #374151;
    color: #f3f4f6;
  }
  
  .excel-preview :deep(tr:nth-child(even)) {
    background-color: #1f2937;
  }
  
  .excel-preview :deep(tr:hover) {
    background-color: #374151;
  }
}

/* Markdown preview - keep code blocks scrollable instead of breaking layout
   and override the default `prose` link colour to match the app accent. */
.markdown-preview :deep(pre) {
  overflow-x: auto;
  max-width: 100%;
}

.markdown-preview :deep(img) {
  max-width: 100%;
  height: auto;
}

.markdown-preview :deep(table) {
  display: block;
  overflow-x: auto;
  max-width: 100%;
}

.markdown-preview :deep(a) {
  word-break: break-word;
}
</style>
