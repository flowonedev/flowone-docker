<script setup>
import { computed, ref, onMounted, onUnmounted, watch, nextTick } from 'vue'
import { useMailboxStore } from '@/stores/mailbox'
import { useComposeStore } from '@/stores/compose'
import { useToastStore } from '@/stores/toast'
import { useSettingsStore } from '@/stores/settings'
import { useLayoutStore } from '@/stores/layout'
import { useSearchStore } from '@/addons/universal-search/stores/search'
import ConfirmModal from '@/components/shared/ConfirmModal.vue'
import SmartViewsList from '@/components/SmartViewsList.vue'
import MailQuotaCard from '@/components/MailQuotaCard.vue'
import { useI18n } from 'vue-i18n'
import api from '@/services/api'
import { folderResourceUrl } from '@/services/mailRouteService'
import { recordFolderVisit, setActiveUserEmail as setOfflineActiveUserEmail } from '@/services/offlineMailbox'
import { useAccountsStore } from '@/stores/accounts'
import brandLogoUrl from '@/assets/flowone-logo.png'
import { isDebugEnabled } from '@/utils/debug'

const mailbox = useMailboxStore()
const compose = useComposeStore()
const { t } = useI18n()
const toast = useToastStore()
const searchStore = useSearchStore()
const settingsStore = useSettingsStore()
const layout = useLayoutStore()

const emit = defineEmits(['folder-selected'])

// Confirm modal state
const showEmptyConfirm = ref(false)
const emptyConfirmFolder = ref(null)
const showDeleteConfirm = ref(false)
const deleteConfirmFolder = ref(null)
const showCleanConfirm = ref(false)
const cleanConfirmFolder = ref(null)
const cleaningFolder = ref(false)
const cleanProgress = ref({
  total: 0,
  moved: 0,
  failed: 0,
  inProgress: false,
  done: false
})

// Folder ordering and hierarchy
// Initialize from localStorage immediately to prevent ABC flash on load
function getInitialFolderOrder() {
  try {
    const cached = localStorage.getItem('webmail_folder_order')
    if (cached) {
      const parsed = JSON.parse(cached)
      if (Array.isArray(parsed) && parsed.length > 0) {
        return parsed
      }
    }
  } catch (e) {}
  return []
}
const folderOrder = ref(getInitialFolderOrder())
const reorderMode = ref(false)

const showCreateFolder = ref(false)
const newFolderName = ref('')
const parentFolder = ref(null) // For creating subfolders
const creatingFolder = ref(false)
const dragOverFolder = ref(null)
const expandedFolders = ref(new Set(['INBOX']))
const folderNavRef = ref(null)

// Show user folders toggle
const showUserFolders = ref(localStorage.getItem('showUserFolders') !== 'false')

// Show more system folders (Drafts, Trash, Spam, Archive) toggle
const showMoreSystemFolders = ref(localStorage.getItem('showMoreSystemFolders') === 'true')

function toggleMoreSystemFolders() {
  showMoreSystemFolders.value = !showMoreSystemFolders.value
  localStorage.setItem('showMoreSystemFolders', showMoreSystemFolders.value.toString())
}

function toggleShowUserFolders() {
  showUserFolders.value = !showUserFolders.value
  localStorage.setItem('showUserFolders', showUserFolders.value.toString())
}

// Context menu state
const contextMenu = ref({
  show: false,
  x: 0,
  y: 0,
  folder: null
})

// Rename state
const renamingFolder = ref(null)
const renameValue = ref('')

// Move into folder modal state
const showMoveIntoModal = ref(false)
const moveIntoSourceFolder = ref(null)

// Simple drag-and-drop state
const draggingFolder = ref(null)
const dropPosition = ref(null) // { index, position: 'before'|'after' } for reorder
const dropTargetFolder = ref(null) // Folder to nest into (hover over center)

const folderIcons = {
  inbox: 'inbox',
  all_mail: 'all_inbox',
  sent: 'send',
  scheduled: 'schedule_send',
  drafts: 'draft',
  trash: 'delete',
  spam: 'report',
  junk: 'report',
  archive: 'archive',
  important: 'label_important',
  starred: 'star',
  folder: 'folder',
}

// Virtual "All Mail" folder definition
const allMailFolder = {
  name: 'ALL_MAIL',
  type: 'all_mail',
  total: 0,
  unread: 0,
  system: true,
  virtual: true
}

const scheduledFolder = {
  name: 'SCHEDULED',
  type: 'scheduled',
  total: 0,
  unread: 0,
  system: true,
  virtual: true
}

const systemFolderTypes = ['inbox', 'sent', 'drafts', 'trash', 'spam', 'junk', 'archive', 'important', 'starred']

function getFolderIcon(folder) {
  // Gmail system folders use specific icons
  if (folder.type === 'important') return 'label_important'
  if (folder.type === 'starred') return 'star'
  return folderIcons[folder.type] || 'folder'
}

function isSystemFolder(folder) {
  // Use backend's system flag if available, otherwise fall back to type check
  if (folder.system !== undefined) {
    return folder.system
  }
  return systemFolderTypes.includes(folder.type)
}

// Get display name (last part of folder path)
function getDisplayName(folder) {
  const name = folder.name
  if (name === 'INBOX') return t('folderTree.inbox')
  if (name === 'ALL_MAIL') return t('folderTree.allMail')
  if (name === 'SCHEDULED') return t('folderTree.scheduled')
  
  // Get the last part of the path
  const parts = name.split('.')
  const lastPart = parts[parts.length - 1]
  
  // Map common names
  if (lastPart === 'Sent') return t('folderTree.sent')
  if (lastPart === 'Drafts') return t('folderTree.drafts')
  if (lastPart === 'Deleted Items' || lastPart === 'Trash') return t('folderTree.trash')
  if (lastPart === 'Junk E-mail' || lastPart === 'Spam' || lastPart === 'Junk') return t('folderTree.spam')
  if (lastPart === 'Archive') return t('folderTree.archive')
  
  return lastPart
}

// Get folder depth (for visual indentation)
function getFolderDepth(folder) {
  const name = folder.name
  if (name === 'INBOX') return 0
  
  if (name.startsWith('INBOX.')) {
    const afterInbox = name.slice(6)
    const parts = afterInbox.split('.')
    return Math.max(0, parts.length - 1)
  }
  
  return name.split('.').length - 1
}

// Get parent folder name
function getParentFolder(folderName) {
  if (folderName === 'INBOX') return null
  
  if (folderName.startsWith('INBOX.')) {
    const afterInbox = folderName.slice(6)
    const parts = afterInbox.split('.')
    if (parts.length === 1) return null
    parts.pop()
    return 'INBOX.' + parts.join('.')
  }
  
  const parts = folderName.split('.')
  if (parts.length <= 1) return null
  parts.pop()
  return parts.join('.')
}

// Check if folder has children
function hasChildren(folder) {
  if (folder.name === 'INBOX') {
    return mailbox.folders.some(f => {
      if (!f.name.startsWith('INBOX.')) return false
      const afterInbox = f.name.slice(6)
      return afterInbox.includes('.')
    })
  }
  
  return mailbox.folders.some(f => {
    if (f.name === folder.name) return false
    return f.name.startsWith(folder.name + '.')
  })
}

// Get total unread count from all descendant folders (for collapsed folder indicator)
function getDescendantUnreadCount(folder) {
  let total = 0
  const prefix = folder.name + '.'
  
  for (const f of mailbox.folders) {
    if (f.name.startsWith(prefix) && f.unread > 0) {
      total += f.unread
    }
  }
  
  return total
}

// Check if collapsed folder has unread descendants
function hasUnreadDescendants(folder) {
  if (!hasChildren(folder)) return false
  if (expandedFolders.value.has(folder.name)) return false
  return getDescendantUnreadCount(folder) > 0
}

function deduplicateFolders(folders) {
  const seenByType = new Map()
  const seenByName = new Map()
  
  for (const folder of folders) {
    if (isSystemFolder(folder)) {
      const existing = seenByType.get(folder.type)
      if (!existing) {
        seenByType.set(folder.type, folder)
      } else if (folder.name.startsWith('INBOX.') && !existing.name.startsWith('INBOX.')) {
        seenByType.set(folder.type, folder)
      }
    } else {
      if (!seenByName.has(folder.name)) {
        seenByName.set(folder.name, folder)
      }
    }
  }
  
  return [...seenByType.values(), ...seenByName.values()]
}

// Types that should be hidden in the "More" dropdown
const secondaryFolderTypes = ['drafts', 'trash', 'spam', 'junk', 'archive']

// Separate system folders and user folders
const systemFolders = computed(() => {
  const dedupedFolders = deduplicateFolders(mailbox.folders)
  
  const folders = dedupedFolders
    .filter(f => isSystemFolder(f))
    .map(f => ({
      ...f,
      depth: 0,
      isSystem: true,
      children: []
    }))
    .sort((a, b) => {
      if (a.name === 'INBOX') return -1
      if (b.name === 'INBOX') return 1
      return systemFolderTypes.indexOf(a.type) - systemFolderTypes.indexOf(b.type)
    })
  
  // Insert "All Mail" after INBOX
  const inboxIndex = folders.findIndex(f => f.name === 'INBOX')
  const allMail = {
    ...allMailFolder,
    depth: 0,
    isSystem: true,
    children: []
  }
  folders.splice(inboxIndex + 1, 0, allMail)
  
  // Insert "Scheduled" after Sent
  const sentIndex = folders.findIndex(f => f.type === 'sent')
  if (sentIndex !== -1) {
    const scheduled = {
      ...scheduledFolder,
      depth: 0,
      isSystem: true,
      children: []
    }
    folders.splice(sentIndex + 1, 0, scheduled)
  }
  
  return folders
})

// Primary system folders (always visible): Inbox, All Mail, Sent, Scheduled
const primarySystemFolders = computed(() => {
  return systemFolders.value.filter(f => !secondaryFolderTypes.includes(f.type))
})

// Secondary system folders (hidden in "More" dropdown): Drafts, Trash, Spam, Archive
const secondarySystemFolders = computed(() => {
  return systemFolders.value.filter(f => secondaryFolderTypes.includes(f.type))
})

// Check if any secondary folder has unread count (for badge on "More" toggle)
const secondaryUnreadCount = computed(() => {
  return secondarySystemFolders.value.reduce((sum, f) => sum + (f.unread || 0), 0)
})

// Hidden system folders that shouldn't be shown to users
const hiddenFolderNames = ['sieve', 'INBOX.sieve', 'dovecot', 'INBOX.dovecot', 'cur', 'new', 'tmp']

function isHiddenFolder(folder) {
  const name = folder.name.toLowerCase()
  const displayName = getDisplayName(folder).toLowerCase()
  return hiddenFolderNames.some(hidden => 
    name === hidden.toLowerCase() || 
    name.endsWith('.' + hidden.toLowerCase()) ||
    displayName === hidden.toLowerCase()
  )
}

// Build user folders tree
const userFoldersTree = computed(() => {
  const dedupedFolders = deduplicateFolders(mailbox.folders)
  // Filter out system folders AND folders that have a system type (to avoid showing Spam twice)
  const userFolders = dedupedFolders.filter(f => 
    !isSystemFolder(f) && 
    !isHiddenFolder(f) && 
    !systemFolderTypes.includes(f.type)
  )
  
  const tree = []
  const folderMap = new Map()
  
  // STEP 1: First, create ALL nodes in the map (without establishing relationships)
  // This ensures parent nodes exist before children try to find them
  for (const folder of userFolders) {
    const node = {
      ...folder,
      children: [],
      depth: getFolderDepth(folder),
      isSystem: false
    }
    folderMap.set(folder.name, node)
  }
  
  // STEP 2: Now establish parent-child relationships
  for (const folder of userFolders) {
    const node = folderMap.get(folder.name)
    const parentName = getParentFolder(folder.name)
    
    if (!parentName || !folderMap.has(parentName)) {
      // Root level folder
      tree.push(node)
    } else {
      // Child folder - add to parent
      const parent = folderMap.get(parentName)
      parent.children.push(node)
    }
  }
  
  // STEP 3: Sort by custom position or alphabetically (at each level)
  const sortNodes = (nodes) => {
    return nodes
      .sort((a, b) => {
        const posA = getFolderPosition(a.name)
        const posB = getFolderPosition(b.name)
        if (posA !== 999 && posB !== 999) return posA - posB
        if (posA !== 999) return -1
        if (posB !== 999) return 1
        return getDisplayName(a).localeCompare(getDisplayName(b))
      })
      .map(node => ({
        ...node,
        children: sortNodes(node.children)
      }))
  }
  
  return sortNodes(tree)
})

// Flatten user folders for rendering
const flattenedUserFolders = computed(() => {
  if (!showUserFolders.value) return []
  
  const result = []
  
  const flatten = (nodes, parentExpanded = true) => {
    for (const node of nodes) {
      if (parentExpanded) {
        result.push(node)
      }
      
      if (node.children.length > 0) {
        const isExpanded = expandedFolders.value.has(node.name)
        flatten(node.children, parentExpanded && isExpanded)
      }
    }
  }
  
  flatten(userFoldersTree.value)
  return result
})

// Count user folders
const userFolderCount = computed(() => {
  let count = 0
  const countAll = (nodes) => {
    for (const node of nodes) {
      count++
      countAll(node.children)
    }
  }
  countAll(userFoldersTree.value)
  return count
})

function toggleExpand(folder) {
  if (expandedFolders.value.has(folder.name)) {
    expandedFolders.value.delete(folder.name)
  } else {
    expandedFolders.value.add(folder.name)
  }
}

// Click-freshness window: when the user clicks a folder in the sidebar
// AND we are already on that folder AND we fetched it less than this many
// milliseconds ago, the click becomes a no-op (no network call, no view
// replace). This eliminates two real complaints we observed in production:
//
//   1. Repeatedly clicking "Inbox" returned a slightly-different page-1
//      from Gmail each time (the distributed-backend replica flap), so
//      the visible top-25 kept shuffling without any user action.
//   2. Every click round-tripped to IMAP, which on OAuth (Gmail) accounts
//      can take 1-3 seconds, making the sidebar feel unresponsive.
//
// Inside the window we still re-emit folder-selected so any listeners
// (e.g. mobile sidebar collapse, conversation panel reset) get to do
// their lightweight per-click work, but we do not invalidate the view.
// Outside the window, behavior is unchanged: a real fetch happens.
//
// The window is short enough (5s) that a stale-while-revalidate of the
// active folder will still hit on the user's next deliberate revisit,
// and outside-folder revalidation continues to run on its own cadence
// (useFolderRevalidationInterval / WebSocket sync).
const FOLDER_CLICK_FRESH_WINDOW_MS = 5000

async function selectFolder(folder) {
  // Track folder visits in IndexedDB. Powers the top-10 smart-prefetch
  // list on next login. Cheap and best-effort.
  if (folder?.name && folder.name !== 'ALL_MAIL' && folder.name !== 'SCHEDULED' && folder.name !== 'SEARCH_RESULTS') {
    try {
      const accountsStore = useAccountsStore()
      const email = accountsStore?.activeAccount?.email
      if (email) {
        setOfflineActiveUserEmail(email)
        recordFolderVisit(folder.name).catch(() => {})
      }
    } catch (_e) {
      // ignore
    }
  }

  // Handle virtual "All Mail" folder.
  // We deliberately clear ALL_MAIL's own folderView (NOT the source folder
  // via the legacy `mailbox.messages = []` setter), because that legacy
  // setter writes to currentFolder.value which is still the source folder
  // pre-navigation -- wiping the source folder's cache caused the
  // "jumps back to Inbox" race (background-sync fallback to full page-1
  // refetch that synchronously yanks currentFolder back). fetchAllMail
  // sets currentFolder = "ALL_MAIL" itself and is also fetchMessagesToken-
  // fenced, so we just give it a clean view to populate.
  if (folder.name === 'ALL_MAIL') {
    mailbox.clearFolderView('ALL_MAIL')
    await mailbox.fetchAllMail()
    mailbox.clearCurrentMessage()
    emit('folder-selected', folder)
    return
  }
  
  // Handle virtual "Scheduled" folder
  if (folder.name === 'SCHEDULED') {
    mailbox.clearFolderView('SCHEDULED')
    await mailbox.fetchScheduledEmails()
    mailbox.clearCurrentMessage()
    emit('folder-selected', folder)
    return
  }
  
  // Auto-expand folder if it has children
  if (hasChildren(folder) && !expandedFolders.value.has(folder.name)) {
    expandedFolders.value.add(folder.name)
  }

  // Freshness gate: if the user is "tapping" the already-active folder
  // (e.g. clicking Inbox while already viewing Inbox), and the data we
  // showed them is recent, skip the page-1 refetch entirely. See the
  // FOLDER_CLICK_FRESH_WINDOW_MS comment above for full rationale.
  const isSameFolder = mailbox.currentFolder === folder.name
  const lastRefreshedAt = mailbox.getLastRefreshed ? mailbox.getLastRefreshed(folder.name) : null
  const isFresh = lastRefreshedAt && (Date.now() - lastRefreshedAt) < FOLDER_CLICK_FRESH_WINDOW_MS
  const hasData = (mailbox.folderViews?.get?.(folder.name)?.length || 0) > 0

  if (isSameFolder && isFresh && hasData) {
    isDebugEnabled() && console.log(
      `[FolderTree] Lightweight revalidate for ${folder.name} -- already active and fresh (${Math.round((Date.now() - lastRefreshedAt) / 1000)}s old)`
    )
    // Even on a "fresh" same-folder tap we still issue a sync-state
    // probe via revalidateActiveFolder. That call is just a single
    // STATUS round-trip when nothing changed (and returns immediately
    // with {unchanged: true}), but it will detect external deletions
    // (e.g. user emptied Spam in Gmail web while we were sitting on the
    // folder) and force a refetch via the EXISTS-shrink gate inside
    // mailbox.revalidateActiveFolder. Skipping entirely meant FlowOne
    // could show messages that no longer exist server-side.
    void mailbox.revalidateActiveFolder()
    emit('folder-selected', folder)
    return
  }

  // Fetch messages for this folder (stale-while-revalidate; list updates in place)
  await mailbox.fetchMessages(folder.name, 1)
  mailbox.clearCurrentMessage()
  emit('folder-selected', folder)
  
  // If folder is empty and has children, auto-select first child (visual order)
  if (mailbox.messages.length === 0 && hasChildren(folder)) {
    const firstChild = getFirstVisibleChild(folder)
    if (firstChild) {
      // Expand the parent and select the child
      expandedFolders.value.add(folder.name)
      await mailbox.fetchMessages(firstChild.name, 1)
      emit('folder-selected', firstChild)
    }
  }
}

// Get first child folder in visual order (from the tree)
function getFirstVisibleChild(folder) {
  // Find the folder node in the tree
  const findInTree = (nodes, targetName) => {
    for (const node of nodes) {
      if (node.name === targetName) {
        return node
      }
      if (node.children.length > 0) {
        const found = findInTree(node.children, targetName)
        if (found) return found
      }
    }
    return null
  }
  
  const node = findInTree(userFoldersTree.value, folder.name)
  if (node && node.children.length > 0) {
    // Return the first child in visual order (already sorted)
    return node.children[0]
  }
  return null
}

function isActive(folder) {
  return mailbox.currentFolder === folder.name
}

// Context menu handlers
function showContextMenu(e, folder) {
  e.preventDefault()
  
  // Calculate menu height based on folder type
  // Base items: New subfolder, Clean folder = ~90px
  // System folders (trash/spam): + Empty button ~45px = ~135px
  // User folders: + Rename, Move into, Delete = +140px = ~230px
  // Nested folders: + Move out, Move to root = +80px = ~310px
  const isSystem = isSystemFolder(folder)
  const isTrashOrSpam = folder.type === 'trash' || folder.type === 'spam' || folder.type === 'junk'
  const isNested = getParentFolder(folder?.name)
  const isDeeplyNested = getFolderDepth(folder) > 1
  
  let estimatedHeight = 100 // Base: new subfolder + clean folder
  if (isTrashOrSpam) estimatedHeight += 50 // Empty button
  if (!isSystem) estimatedHeight += 150 // Rename, move into, delete
  if (!isSystem && isNested) estimatedHeight += 45 // Move out of parent
  if (!isSystem && isDeeplyNested) estimatedHeight += 45 // Move to root
  
  const estimatedWidth = 220
  
  let x = e.clientX
  let y = e.clientY
  
  // Check if menu would go off bottom - if so, position ABOVE the click
  const spaceBelow = window.innerHeight - e.clientY
  const spaceAbove = e.clientY
  
  if (spaceBelow < estimatedHeight && spaceAbove > estimatedHeight) {
    // Open upward - position menu so bottom is at click point
    y = e.clientY - estimatedHeight
  } else if (spaceBelow < estimatedHeight) {
    // Not enough space above either, just clamp to bottom
    y = window.innerHeight - estimatedHeight - 10
  }
  
  // Check if menu would go off right edge
  if (x + estimatedWidth > window.innerWidth - 10) {
    x = window.innerWidth - estimatedWidth - 10
  }
  
  // Ensure minimum positions
  x = Math.max(10, x)
  y = Math.max(10, y)
  
  contextMenu.value = {
    show: true,
    x,
    y,
    folder
  }
}

function closeContextMenu() {
  contextMenu.value.show = false
}

function createSubfolder() {
  const folder = contextMenu.value.folder
  parentFolder.value = folder?.name
  newFolderName.value = ''
  showCreateFolder.value = true
  // Auto-expand the parent folder so the input is visible
  if (folder?.name) {
    expandedFolders.value.add(folder.name)
  }
  // Make sure user folders section is visible
  showUserFolders.value = true
  closeContextMenu()
}

function startRename() {
  renamingFolder.value = contextMenu.value.folder
  renameValue.value = getDisplayName(contextMenu.value.folder)
  closeContextMenu()
}

async function confirmRename() {
  // Guard against double-fire: pressing Enter triggers @keyup.enter, which
  // in turn causes the input to lose focus and fire @blur. Both bind to
  // confirmRename, so without this we'd send the rename request twice.
  // Capture the target and clear renamingFolder SYNCHRONOUSLY so the
  // blur-driven second call hits the early-return below.
  const target = renamingFolder.value
  const newName = renameValue.value.trim()
  renamingFolder.value = null

  if (!target || !newName) {
    return
  }

  const success = await mailbox.renameFolder(target.name, newName)

  if (success) {
    toast.success(t('folderTree.folderRenamed'))
  } else {
    toast.error(t('folderTree.failedToRenameFolder'))
  }
}

function deleteFolderAction() {
  const folder = contextMenu.value.folder
  closeContextMenu()
  
  if (!folder || isSystemFolder(folder)) {
    toast.error(t('folderTree.cannotDeleteSystemFolder'))
    return
  }
  
  deleteConfirmFolder.value = folder
  showDeleteConfirm.value = true
}

async function executeDeleteFolder() {
  const folder = deleteConfirmFolder.value
  showDeleteConfirm.value = false
  
  if (!folder) return
  
  const success = await mailbox.deleteFolder(folder.name)
  
  if (success) {
    toast.success(t('folderTree.folderDeleted'))
  } else {
    toast.error(t('folderTree.failedToDeleteFolder'))
  }
  
  deleteConfirmFolder.value = null
}

function emptyFolderAction() {
  const folder = contextMenu.value.folder
  closeContextMenu()
  
  if (!folder) return
  
  emptyConfirmFolder.value = folder
  showEmptyConfirm.value = true
}

function cleanFolderAction() {
  const folder = contextMenu.value.folder
  closeContextMenu()
  
  if (!folder) return
  
  // Don't clean trash (use empty instead) or spam
  if (folder.type === 'trash' || folder.type === 'spam' || folder.type === 'junk') {
    toast.info(t('folderTree.useEmptyForTrashSpam'))
    return
  }
  
  cleanConfirmFolder.value = folder
  showCleanConfirm.value = true
}

async function executeCleanFolder() {
  const folder = cleanConfirmFolder.value
  
  if (!folder) return
  
  // Find the trash folder
  const trashFolder = mailbox.folders.find(f => f.type === 'trash')
  if (!trashFolder) {
    toast.error(t('folderTree.trashFolderNotFound'))
    showCleanConfirm.value = false
    cleanConfirmFolder.value = null
    return
  }
  
  // Initialize progress
  cleanProgress.value = {
    total: 0,
    moved: 0,
    failed: 0,
    inProgress: true,
    done: false
  }
  cleaningFolder.value = true
  
  try {
    let hasMore = true
    const batchSize = 50
    
    while (hasMore) {
      const response = await api.post('/mailbox/clean-folder', {
        folder: folder.name,
        targetFolder: trashFolder.name,
        batchSize
      })
      
      const result = response.data
      
      if (!result.success) {
        toast.error(result.message || t('folderTree.failedToCleanFolder'))
        break
      }
      
      // Update progress
      cleanProgress.value.total = result.data.total
      cleanProgress.value.moved += result.data.moved
      cleanProgress.value.failed += result.data.failed
      hasMore = result.data.hasMore
      
      // Small delay between batches
      if (hasMore) {
        await new Promise(r => setTimeout(r, 100))
      }
    }
    
    cleanProgress.value.done = true
    cleanProgress.value.inProgress = false
    
    if (cleanProgress.value.moved > 0) {
      toast.success(`${cleanProgress.value.moved} ${t('folderTree.messagesMoved')}`)
    } else {
      toast.info(t('folderTree.folderIsAlreadyEmpty'))
    }
    
    // Refresh the mailbox
    await mailbox.fetchMessages()
    
  } catch (error) {
    console.error('Clean folder error:', error)
    toast.error(t('folderTree.failedToCleanFolder'))
    cleanProgress.value.inProgress = false
  }
  
  // Close after a short delay to show completion
  setTimeout(() => {
    cleaningFolder.value = false
    cleanConfirmFolder.value = null
    showCleanConfirm.value = false
    cleanProgress.value = { total: 0, moved: 0, failed: 0, inProgress: false, done: false }
  }, 1500)
}

async function unnestFolderAction() {
  const folder = contextMenu.value.folder
  closeContextMenu()
  
  if (!folder) return
  
  await unnestFolder(folder)
}

function openMoveIntoModal() {
  moveIntoSourceFolder.value = contextMenu.value.folder
  closeContextMenu()
  showMoveIntoModal.value = true
}

async function moveToRootAction() {
  const folder = contextMenu.value.folder
  closeContextMenu()
  
  if (!folder) return
  
  try {
    await moveFolderToParent(folder.name, null)
    toast.success(`${getDisplayName(folder)} - ${t('folderTree.folderMoved')}`)
    saveFolderOrder()
  } catch (err) {
    console.error('Failed to move folder:', err)
    toast.error(t('folderTree.failedToMoveFolder'))
  }
}

// Get folders that can be targets for "Move into"
const moveIntoTargetFolders = computed(() => {
  if (!moveIntoSourceFolder.value) return []
  const source = moveIntoSourceFolder.value.name
  return flattenedUserFolders.value.filter(f => {
    // Can't move into self
    if (f.name === source) return false
    // Can't move into children
    if (f.name.startsWith(source + '.')) return false
    // Can't move into current parent (already there)
    if (f.name === getParentFolder(source)) return false
    return true
  })
})

async function confirmMoveInto(targetFolder) {
  if (!moveIntoSourceFolder.value || !targetFolder) return
  
  await nestFolderInto(moveIntoSourceFolder.value, targetFolder)
  showMoveIntoModal.value = false
  moveIntoSourceFolder.value = null
}

async function executeEmptyFolder() {
  const folder = emptyConfirmFolder.value
  showEmptyConfirm.value = false
  
  if (!folder) return
  
  const folderType = folder.type === 'trash' ? t('folderTree.trash') : t('folderTree.spam')
  
  try {
    const response = await api.post(folderResourceUrl(mailbox.folders, folder.name, 'empty'))
    if (response.data.success) {
      toast.success(t('folderTree.folderEmptied', { type: folderType, count: response.data.data?.deleted || 0 }))
      // Clear UI and refresh folder counts
      await mailbox.clearFolderCompletely(folder.name)
    } else {
      // If failed, refetch to restore messages
      toast.error(response.data.message || t('folderTree.failedToEmptyFolder', { type: folderType }))
      if (mailbox.currentFolder === folder.name) {
        mailbox.fetchMessages(folder.name)
      }
    }
  } catch (e) {
    toast.error(t('folderTree.failedToEmptyFolder', { type: folderType }))
    // If failed, refetch to restore messages
    if (mailbox.currentFolder === folder.name) {
      mailbox.fetchMessages(folder.name)
    }
  }
  
  emptyConfirmFolder.value = null
}

function startCreateFolder() {
  parentFolder.value = null
  newFolderName.value = ''
  showCreateFolder.value = true
}

async function createFolder() {
  if (!newFolderName.value.trim()) return
  
  creatingFolder.value = true
  
  const success = await mailbox.createFolder(newFolderName.value.trim(), parentFolder.value)
  
  if (success) {
    toast.success(t('folderTree.folderCreated'))
    if (parentFolder.value) {
      expandedFolders.value.add(parentFolder.value)
    }
    newFolderName.value = ''
    showCreateFolder.value = false
    parentFolder.value = null
    // Show user folders after creating
    showUserFolders.value = true
    localStorage.setItem('showUserFolders', 'true')
  } else {
    toast.error(t('folderTree.failedToCreateFolder'))
  }
  
  creatingFolder.value = false
}

// Drag and drop for messages
function onDragOver(e, folder) {
  e.preventDefault()
  dragOverFolder.value = folder.name
}

function onDragLeave() {
  dragOverFolder.value = null
}

async function onDrop(e, folder) {
  e.preventDefault()
  dragOverFolder.value = null
  
  const data = e.dataTransfer.getData('application/json')
  if (!data) return
  
  try {
    const parsed = JSON.parse(data)
    
    if (parsed.folderName) {
      await moveFolderTo(parsed.folderName, folder.name)
    } else if (parsed.items || parsed.uids) {
      const items = parsed.items || parsed.uids.map(uid => ({ uid, folder: parsed.sourceFolder }))
      const validItems = items.filter(item => {
        const srcFolder = item.folder || parsed.sourceFolder
        return srcFolder && srcFolder !== folder.name
      })
      if (validItems.length === 0) return

      const result = await mailbox.bulkMoveMessages(
        validItems.map(item => ({ uid: item.uid, folder: item.folder || parsed.sourceFolder })),
        folder.name
      )
      if (result.failed > 0) {
        toast.error(t('folderTree.failedToMove'))
      } else {
        toast.success(`${result.success} ${t('folderTree.messagesMoved')} - ${getDisplayName(folder)}`)
      }
    }
  } catch (err) {
    toast.error(t('folderTree.failedToMove'))
  }
}

function onFolderDragStart(e, folder) {
  if (isSystemFolder(folder)) {
    e.preventDefault()
    return
  }
  
  draggingFolder.value = folder
  e.dataTransfer.effectAllowed = 'move'
  e.dataTransfer.setData('application/json', JSON.stringify({
    folderName: folder.name
  }))
}

function onFolderDragEnd() {
  draggingFolder.value = null
}

async function moveFolderTo(folderName, targetParent) {
  if (folderName === targetParent) return
  if (targetParent.startsWith(folderName + '.')) {
    toast.error(t('folderTree.cannotMoveFolderIntoIts'))
    return
  }
  
  const displayName = getDisplayName({ name: folderName })
  const success = await mailbox.renameFolder(folderName, displayName, targetParent)
  
  if (success) {
    toast.success(t('folderTree.folderMoved'))
    expandedFolders.value.add(targetParent)
  } else {
    toast.error(t('folderTree.failedToMoveFolder'))
  }
}

function handleClickOutside(e) {
  if (contextMenu.value.show && !e.target.closest('.context-menu')) {
    closeContextMenu()
  }
}

// Load folder order from localStorage (instant, prevents flash)
function loadFolderOrderFromCache() {
  try {
    const cached = localStorage.getItem('webmail_folder_order')
    if (cached) {
      const savedOrder = JSON.parse(cached)
      if (Array.isArray(savedOrder) && savedOrder.length > 0) {
        folderOrder.value = savedOrder
        return true
      }
    }
  } catch (e) {
    console.warn('Failed to load cached folder order:', e)
  }
  return false
}

// Save folder order to localStorage (for instant load on refresh)
function saveFolderOrderToCache() {
  try {
    localStorage.setItem('webmail_folder_order', JSON.stringify(folderOrder.value))
  } catch (e) {
    console.warn('Failed to cache folder order:', e)
  }
}

// Load folder order from settings (syncs with server)
async function loadFolderOrder() {
  // First, load from cache immediately (prevents ABC flash)
  const hadCache = loadFolderOrderFromCache()
  
  // Then sync from settings store (already loaded by bootstrap) instead of a separate API call
  try {
    const savedOrder = settingsStore.settings.folder_order
    if (Array.isArray(savedOrder) && savedOrder.length > 0) {
      if (typeof savedOrder[0] === 'string') {
        folderOrder.value = savedOrder.map((name, i) => ({ name, position: i }))
      } else {
        folderOrder.value = savedOrder
      }
      saveFolderOrderToCache()
    }
  } catch (e) {
    console.error('Failed to load folder order:', e)
  }
}

// Save folder order to settings
async function saveFolderOrder() {
  // Save to localStorage immediately (for instant reload)
  saveFolderOrderToCache()
  
  // Then save to server
  try {
    await api.put('/settings', { folder_order: folderOrder.value })
  } catch (e) {
    console.error('Failed to save folder order:', e)
    toast.error(t('folderTree.failedToSaveFolderOrder'))
  }
}

// Get position for a folder from saved order
function getFolderPosition(folderName) {
  const entry = folderOrder.value.find(f => f.name === folderName)
  return entry?.position ?? 999
}

// Update folder positions after drag
function updateFolderPositions(folders) {
  // Create new order entries preserving hierarchy
  const newOrder = folders.map((f, i) => ({
    name: f.name,
    position: i
  }))
  folderOrder.value = newOrder
}

// Simple drag handlers - position-based detection
// Top/bottom 25% of folder = reorder, Center 50% = nest into
function onReorderDragStart(e, folder) {
  if (isSystemFolder(folder)) {
    e.preventDefault()
    return
  }
  draggingFolder.value = folder
  e.dataTransfer.effectAllowed = 'move'
  e.dataTransfer.setData('text/plain', folder.name)
  e.target.classList.add('dragging')
}

function onFolderDragOver(e, folder, index) {
  e.preventDefault()
  if (!draggingFolder.value) return
  if (folder.name === draggingFolder.value.name) {
    dropTargetFolder.value = null
    dropPosition.value = null
    return
  }
  
  const rect = e.currentTarget.getBoundingClientRect()
  const y = e.clientY - rect.top
  const height = rect.height
  
  // Top 25% = reorder before, Bottom 25% = reorder after, Middle 50% = nest
  const threshold = height * 0.25
  
  if (y < threshold) {
    // Top edge - reorder before this folder
    dropTargetFolder.value = null
    dropPosition.value = { index, position: 'before' }
  } else if (y > height - threshold) {
    // Bottom edge - reorder after this folder
    dropTargetFolder.value = null
    dropPosition.value = { index, position: 'after' }
  } else {
    // Center - nest into this folder
    if (folder.name.startsWith(draggingFolder.value.name + '.')) {
      // Can't nest into own child
      dropTargetFolder.value = null
      dropPosition.value = null
      return
    }
    if (getParentFolder(draggingFolder.value.name) === folder.name) {
      // Already in this folder - show nothing
      dropTargetFolder.value = null
      dropPosition.value = null
      return
    }
    dropPosition.value = null
    dropTargetFolder.value = folder
  }
}

function onFolderDragLeave(e) {
  if (!e.relatedTarget || !e.currentTarget.contains(e.relatedTarget)) {
    dropTargetFolder.value = null
    dropPosition.value = null
  }
}

async function onFolderDrop(e, folder, index) {
  e.preventDefault()
  if (!draggingFolder.value) {
    resetDragState()
    return
  }
  
  const sourceFolder = draggingFolder.value
  
  // Store values before async operations (they might get reset)
  const dropTarget = dropTargetFolder.value
  const dropPos = dropPosition.value ? { ...dropPosition.value } : null
  
  try {
    if (dropTarget) {
      // Dropped on center - nest into folder
      await nestFolderInto(sourceFolder, dropTarget)
    } else if (dropPos) {
      // Dropped on edge - reorder
      const targetFolder = flattenedUserFolders.value[dropPos.index]
      if (targetFolder && dropPos.position) {
        const targetParent = getParentFolder(targetFolder.name)
        const sourceParent = getParentFolder(sourceFolder.name)
        
        if (targetParent !== sourceParent) {
          await moveFolderToParent(sourceFolder.name, targetParent)
        }
        
        await reorderFoldersAtLevel(sourceFolder.name, targetFolder.name, dropPos.position)
        toast.success(t('folderTree.folderMoved'))
        saveFolderOrder()
      }
    }
  } catch (err) {
    console.error('Failed to move folder:', err)
    toast.error(t('folderTree.failedToMoveFolder'))
  }
  
  resetDragState()
}

// Move folder INTO another folder (nest)
async function nestFolderInto(folder, targetFolder) {
  if (!folder || !targetFolder) return
  if (folder.name === targetFolder.name) return
  if (targetFolder.name.startsWith(folder.name + '.')) {
    toast.error(t('folderTree.cannotMoveFolderIntoIts'))
    return
  }
  
  try {
    await moveFolderToParent(folder.name, targetFolder.name)
    expandedFolders.value.add(targetFolder.name)
    toast.success(`${getDisplayName(folder)} -> ${getDisplayName(targetFolder)}`)
    saveFolderOrder()
  } catch (err) {
    console.error('Failed to nest folder:', err)
    toast.error(t('folderTree.failedToMoveFolder'))
  }
}

// Move folder OUT of parent (unnest)
async function unnestFolder(folder) {
  const parentFolder = getParentFolder(folder.name)
  if (!parentFolder) {
    toast.info(t('folderTree.folderIsAlreadyAtRoot'))
    return
  }
  
  const grandparent = getParentFolder(parentFolder)
  
  try {
    await moveFolderToParent(folder.name, grandparent)
    toast.success(`${getDisplayName(folder)} - ${t('folderTree.folderMoved')}`)
    saveFolderOrder()
  } catch (err) {
    console.error('Failed to unnest folder:', err)
    toast.error(t('folderTree.failedToMoveFolder'))
  }
}

async function moveFolderToParent(folderName, newParent) {
  const displayName = getDisplayName({ name: folderName })
  const success = await mailbox.renameFolder(folderName, displayName, newParent || 'INBOX')
  
  if (success && newParent) {
    expandedFolders.value.add(newParent)
  }
  
  return success
}

async function reorderFoldersAtLevel(sourceFolder, targetFolder, position) {
  // Get all folders at the target's level
  const targetParent = getParentFolder(targetFolder)
  const sourceParent = getParentFolder(sourceFolder)
  
  // Get sibling folders at this level
  let siblings = flattenedUserFolders.value.filter(f => {
    const fParent = getParentFolder(f.name)
    return fParent === targetParent
  })
  
  // Find current positions
  const sourceIndex = siblings.findIndex(f => f.name === sourceFolder)
  let targetIndex = siblings.findIndex(f => f.name === targetFolder)
  
  if (sourceIndex !== -1) {
    siblings.splice(sourceIndex, 1)
    // Recalculate target index after removal
    targetIndex = siblings.findIndex(f => f.name === targetFolder)
  }
  
  // Insert at new position
  const insertIndex = position === 'before' ? targetIndex : targetIndex + 1
  const sourceFolderObj = flattenedUserFolders.value.find(f => f.name === sourceFolder)
  if (sourceFolderObj) {
    siblings.splice(insertIndex, 0, sourceFolderObj)
  }
  
  // Update positions for all folders (including other levels)
  const allFolders = flattenedUserFolders.value.filter(f => {
    const fParent = getParentFolder(f.name)
    return fParent !== targetParent
  })
  
  // Combine: others first, then reordered siblings
  updateFolderPositions([...allFolders, ...siblings])
}

function resetDragState() {
  draggingFolder.value = null
  dropPosition.value = null
  dropTargetFolder.value = null
  document.querySelectorAll('.dragging').forEach(el => el.classList.remove('dragging'))
}

function onReorderDragEnd() {
  resetDragState()
}

// Toggle reorder mode
function toggleReorderMode() {
  reorderMode.value = !reorderMode.value
  if (!reorderMode.value) {
    saveFolderOrder()
    resetDragState()
  }
}

// Auto-expand parent folders based on current folder
function expandPathToFolder(folderName) {
  if (!folderName) return
  
  // Get all parent folders and expand them
  let current = folderName
  while (current) {
    const parent = getParentFolder(current)
    if (parent) {
      expandedFolders.value.add(parent)
    }
    current = parent
  }
}

// Watch current folder and expand path to it
watch(() => mailbox.currentFolder, (newFolder) => {
  if (newFolder) {
    expandPathToFolder(newFolder)
  }
}, { immediate: true })

// Auto-expand "More" section if a secondary folder is active
watch(() => mailbox.currentFolder, (newFolder) => {
  if (!newFolder) return
  const isSecondary = secondarySystemFolders.value.some(f => f.name === newFolder)
  if (isSecondary && !showMoreSystemFolders.value) {
    showMoreSystemFolders.value = true
    localStorage.setItem('showMoreSystemFolders', 'true')
  }
}, { immediate: true })

onMounted(() => {
  document.addEventListener('click', handleClickOutside)
  loadFolderOrder()
  
  // Expand path to current folder on mount
  if (mailbox.currentFolder) {
    expandPathToFolder(mailbox.currentFolder)
  }
})

onUnmounted(() => {
  document.removeEventListener('click', handleClickOutside)
})
</script>

<template>
  <div class="h-full flex flex-col">
    <!-- Sidebar branding (FlowOne is in the sidebar for Email — AppHeader sets
         hideBranding=true on MailboxView). Clicking the logo collapses the
         sidebar to the icon rail. -->
    <div class="px-4 pt-3 pb-2 flex items-center gap-2.5">
      <button
        type="button"
        @click="layout.toggleSidebarCollapsed()"
        class="w-8 h-8 rounded-lg overflow-hidden flex items-center justify-center hover:opacity-80 transition-opacity"
        :title="$t('folderTree.collapseSidebar')"
      >
        <img :src="brandLogoUrl" alt="FlowOne" class="w-full h-full object-contain" />
      </button>
      <span class="font-semibold text-surface-900 dark:text-surface-100 truncate">FlowOne.Pro</span>
      <button
        type="button"
        @click="layout.toggleSidebarCollapsed()"
        class="ml-auto w-8 h-8 rounded-lg flex items-center justify-center text-surface-400 hover:text-surface-700 dark:hover:text-surface-200 hover:bg-surface-100 dark:hover:bg-surface-800 transition-colors"
        :title="$t('folderTree.collapseSidebar')"
      >
        <span class="material-symbols-rounded text-lg">dock_to_right</span>
      </button>
    </div>

    <!-- Compose button -->
    <div class="px-4 pt-1 pb-3 space-y-2">
      <button @click="compose.open('new')" class="btn-primary w-full">
        <span class="material-symbols-rounded text-lg">edit</span>
        {{ $t('folderTree.compose') }}
      </button>

      <!-- Super Search button -->
      <button
        @click="searchStore.openSearch()"
        class="w-full flex items-center justify-center gap-2 px-4 py-2 rounded-xl bg-surface-100 dark:bg-surface-800 hover:bg-surface-200 dark:hover:bg-surface-700 text-surface-600 dark:text-surface-300 transition-colors border border-surface-200 dark:border-surface-700"
      >
        <span class="material-symbols-rounded text-lg">manage_search</span>
        <span class="flex-1 text-left text-sm">{{ $t('folderTree.searchAll') }}</span>
        <kbd class="hidden sm:inline-block px-1.5 py-0.5 text-[10px] bg-surface-200 dark:bg-surface-700 rounded font-mono">Ctrl+K</kbd>
      </button>
    </div>
    
    <!-- Bulk operation progress bar -->
    <!--
      Bulk delete / move / flag are single-shot batched API calls, so
      `current` stays at 0 for the whole server round-trip and only jumps
      to `total` once the response arrives. Rendering "0/32" with a 0%
      bar made the UI look frozen even when the backend was actively
      working. While the request is in flight we instead render an
      indeterminate striped bar with just the count of items being
      processed; once we have a real current > 0 (per-message paths,
      e.g. very old fallbacks) we switch to the determinate ratio bar.
    -->
    <div v-if="mailbox.bulkProgress.active" class="px-4 pb-2">
      <div class="bg-surface-100 dark:bg-surface-800 rounded-lg p-2">
        <div class="flex items-center justify-between text-xs text-surface-600 dark:text-surface-400 mb-1">
          <span class="capitalize">{{ mailbox.bulkProgress.action }} {{ mailbox.bulkProgress.total }} {{ mailbox.bulkProgress.total === 1 ? 'message' : 'messages' }}...</span>
          <span v-if="mailbox.bulkProgress.current > 0 && mailbox.bulkProgress.current < mailbox.bulkProgress.total">{{ mailbox.bulkProgress.current }}/{{ mailbox.bulkProgress.total }}</span>
        </div>
        <div class="w-full bg-surface-200 dark:bg-surface-700 rounded-full h-1.5 overflow-hidden">
          <div
            v-if="mailbox.bulkProgress.current === 0 || mailbox.bulkProgress.current >= mailbox.bulkProgress.total"
            class="bg-primary-500 h-1.5 rounded-full bulk-progress-indeterminate"
          ></div>
          <div
            v-else
            class="bg-primary-500 h-1.5 rounded-full transition-all duration-150"
            :style="{ width: `${(mailbox.bulkProgress.current / mailbox.bulkProgress.total) * 100}%` }"
          ></div>
        </div>
      </div>
    </div>
    
    <!-- Folders list -->
    <nav ref="folderNavRef" class="relative flex-1 overflow-y-auto px-3 pb-4" style="overflow-anchor: none;">
      <!-- Loading overlay - doesn't push content -->
      <div v-if="mailbox.loading.folders && !mailbox.bulkProgress.active && mailbox.folders.length === 0" class="absolute inset-0 z-10 flex items-center justify-center bg-white/70 dark:bg-surface-900/70">
        <span class="spinner text-primary-500"></span>
      </div>
      
      <div>
        <!-- Primary system folders (always visible): Inbox, All Mail, Sent, Scheduled -->
        <ul class="space-y-0.5">
          <li v-for="folder in primarySystemFolders" :key="folder.name">
            <button
              @click="selectFolder(folder)"
              @contextmenu="showContextMenu($event, folder)"
              @dragover="onDragOver($event, folder)"
              @dragleave="onDragLeave"
              @drop="onDrop($event, folder)"
              :class="[
                'folder-item w-full transition-all group',
                { 'active': isActive(folder) },
                { 'ring-2 ring-primary-500 bg-primary-50 dark:bg-primary-500/20': dragOverFolder === folder.name }
              ]"
            >
              <span class="material-symbols-rounded text-lg">
                {{ getFolderIcon(folder) }}
              </span>
              <span class="flex-1 text-left truncate">{{ getDisplayName(folder) }}</span>
              <span 
                v-if="folder.name === 'SCHEDULED' && mailbox.scheduledCount > 0" 
                class="text-xs font-medium text-amber-500"
              >
                {{ mailbox.scheduledCount }}
              </span>
              <span 
                v-else-if="folder.unread > 0" 
                class="text-xs font-medium text-primary-500"
              >
                {{ Math.max(0, folder.unread) }}
              </span>
            </button>
            
            <!-- Inline subfolder creation for system folders -->
            <div 
              v-if="showCreateFolder && !reorderMode && parentFolder === folder.name"
              class="py-1 pl-8 pr-2"
            >
              <div class="p-2 bg-surface-100 dark:bg-surface-800 rounded-lg">
                <div class="flex items-center gap-2 mb-2 text-xs text-surface-500">
                  <span class="material-symbols-rounded text-sm">subdirectory_arrow_right</span>
                  {{ $t('folderTree.newSubfolderIn') }} "{{ getDisplayName(folder) }}"
                </div>
                <input
                  v-model="newFolderName"
                  type="text"
                  class="input text-sm mb-2 w-full"
                  placeholder="Subfolder name..."
                  @keyup.enter="createFolder"
                  @keyup.escape="showCreateFolder = false; newFolderName = ''; parentFolder = null"
                  autofocus
                />
                <div class="flex gap-2">
                  <button 
                    @click="createFolder"
                    :disabled="creatingFolder || !newFolderName.trim()"
                    class="btn-primary btn-sm flex-1"
                  >
                    <span v-if="creatingFolder" class="spinner"></span>
                    {{ $t('folderTree.create') }}
                  </button>
                  <button 
                    @click="showCreateFolder = false; newFolderName = ''; parentFolder = null"
                    class="btn-ghost btn-sm"
                  >
                    {{ $t('folderTree.cancel') }}
                  </button>
                </div>
              </div>
            </div>
          </li>
        </ul>
        
        <!-- More folders toggle (Drafts, Trash, Spam, Archive) -->
        <div v-if="secondarySystemFolders.length > 0" class="mt-1">
          <button
            @click="toggleMoreSystemFolders"
            class="folder-item w-full transition-all group text-surface-500 dark:text-surface-400 hover:text-surface-700 dark:hover:text-surface-200"
          >
            <span class="material-symbols-rounded text-lg transition-transform" :class="{ 'rotate-180': showMoreSystemFolders }">
              expand_more
            </span>
            <span class="flex-1 text-left text-xs font-medium uppercase tracking-wider">{{ $t('folderTree.more') }}</span>
            <span 
              v-if="!showMoreSystemFolders && secondaryUnreadCount > 0"
              class="text-xs font-medium text-primary-500"
            >
              {{ secondaryUnreadCount }}
            </span>
          </button>
          
          <Transition name="slide-folders">
            <ul v-if="showMoreSystemFolders" class="space-y-0.5 overflow-hidden">
              <li v-for="folder in secondarySystemFolders" :key="folder.name">
                <button
                  @click="selectFolder(folder)"
                  @contextmenu="showContextMenu($event, folder)"
                  @dragover="onDragOver($event, folder)"
                  @dragleave="onDragLeave"
                  @drop="onDrop($event, folder)"
                  :class="[
                    'folder-item w-full transition-all group pl-7',
                    { 'active': isActive(folder) },
                    { 'ring-2 ring-primary-500 bg-primary-50 dark:bg-primary-500/20': dragOverFolder === folder.name }
                  ]"
                >
                  <span class="material-symbols-rounded text-lg">
                    {{ getFolderIcon(folder) }}
                  </span>
                  <span class="flex-1 text-left truncate">{{ getDisplayName(folder) }}</span>
                  <span 
                    v-if="folder.unread > 0" 
                    class="text-xs font-medium text-primary-500"
                  >
                    {{ Math.max(0, folder.unread) }}
                  </span>
                </button>
                
                <!-- Inline subfolder creation for secondary system folders -->
                <div 
                  v-if="showCreateFolder && !reorderMode && parentFolder === folder.name"
                  class="py-1 pl-8 pr-2"
                >
                  <div class="p-2 bg-surface-100 dark:bg-surface-800 rounded-lg">
                    <div class="flex items-center gap-2 mb-2 text-xs text-surface-500">
                      <span class="material-symbols-rounded text-sm">subdirectory_arrow_right</span>
                      {{ $t('folderTree.newSubfolderIn') }} "{{ getDisplayName(folder) }}"
                    </div>
                    <input
                      v-model="newFolderName"
                      type="text"
                      class="input text-sm mb-2 w-full"
                      :placeholder="$t('folderTree.subfolderName')"
                      @keyup.enter="createFolder"
                      @keyup.escape="showCreateFolder = false; newFolderName = ''; parentFolder = null"
                      autofocus
                    />
                    <div class="flex gap-2">
                      <button 
                        @click="createFolder"
                        :disabled="creatingFolder || !newFolderName.trim()"
                        class="btn-primary btn-sm flex-1"
                      >
                        <span v-if="creatingFolder" class="spinner"></span>
                        {{ $t('folderTree.create') }}
                      </button>
                      <button 
                        @click="showCreateFolder = false; newFolderName = ''; parentFolder = null"
                        class="btn-ghost btn-sm"
                      >
                        {{ $t('folderTree.cancel') }}
                      </button>
                    </div>
                  </div>
                </div>
              </li>
            </ul>
          </Transition>
        </div>
        
        <!-- Smart Views (saved searches) -->
        <SmartViewsList />

        <!-- Divider & User Folders Section -->
        <div class="mt-4 pt-3 border-t border-surface-200 dark:border-surface-700">
          <!-- Section header -->
          <div class="flex items-center justify-between px-3 mb-2">
            <button 
              @click="toggleShowUserFolders"
              class="flex items-center gap-1 text-xs font-medium text-surface-500 hover:text-surface-700 dark:hover:text-surface-300 uppercase tracking-wider"
            >
              <span class="material-symbols-rounded text-sm">
                {{ showUserFolders ? 'expand_more' : 'chevron_right' }}
              </span>
              {{ $t('folderTree.folders') }}
              <span v-if="userFolderCount > 0" class="text-surface-400">({{ userFolderCount }})</span>
            </button>
            <div class="flex items-center gap-1">
              <!-- Reorder toggle -->
              <button
                v-if="userFolderCount > 1"
                @click="toggleReorderMode"
                :class="[
                  'w-6 h-6 flex items-center justify-center rounded-full transition-colors',
                  reorderMode 
                    ? 'text-primary-500 bg-primary-100 dark:bg-primary-900/30' 
                    : 'text-surface-500 hover:text-surface-700 dark:hover:text-surface-300 hover:bg-surface-200 dark:hover:bg-surface-700'
                ]"
                :title="reorderMode ? $t('folderTree.doneReordering') : $t('folderTree.reorderFolders')"
              >
                <span class="material-symbols-rounded text-lg">{{ reorderMode ? 'check' : 'swap_vert' }}</span>
              </button>
              <!-- New folder button -->
              <button
                @click="startCreateFolder"
                class="w-6 h-6 flex items-center justify-center rounded-full text-surface-500 hover:text-surface-700 dark:hover:text-surface-300 hover:bg-surface-200 dark:hover:bg-surface-700 transition-colors"
                :title="$t('folderTree.newFolder')"
              >
                <span class="material-symbols-rounded text-lg">add</span>
              </button>
            </div>
          </div>
          
          
          <!-- Create folder input (inline) - only show here for root-level folders -->
          <div v-if="showCreateFolder && !reorderMode && !parentFolder" class="mx-2 mb-2 p-2 bg-surface-100 dark:bg-surface-800 rounded-lg">
            <input
              v-model="newFolderName"
              type="text"
              class="input text-sm mb-2"
              :placeholder="$t('folderTree.folderName')"
              @keyup.enter="createFolder"
              @keyup.escape="showCreateFolder = false; newFolderName = ''; parentFolder = null"
              autofocus
            />
            <div class="flex gap-2">
              <button 
                @click="createFolder"
                :disabled="creatingFolder || !newFolderName.trim()"
                class="btn-primary btn-sm flex-1"
              >
                <span v-if="creatingFolder" class="spinner"></span>
                {{ $t('folderTree.create') }}
              </button>
              <button 
                @click="showCreateFolder = false; newFolderName = ''; parentFolder = null"
                class="btn-ghost btn-sm"
              >
                {{ $t('folderTree.cancel') }}
              </button>
            </div>
          </div>
          
          
          <!-- User folders list -->
          <div v-if="showUserFolders && userFolderCount > 0" class="space-y-0.5">
            <template v-for="(folder, index) in flattenedUserFolders" :key="folder.name">
              <!-- Rename input -->
              <div v-if="renamingFolder?.name === folder.name" class="flex items-center gap-1 py-1" :style="{ paddingLeft: (folder.depth * 16) + 'px' }">
                <input
                  v-model="renameValue"
                  type="text"
                  class="input text-sm flex-1"
                  @keyup.enter="confirmRename"
                  @keyup.escape="renamingFolder = null"
                  @blur="confirmRename"
                  autofocus
                />
              </div>
              
              <!-- Normal folder item -->
              <div
                v-else
                :class="[
                  'folder-item w-full transition-all group relative',
                  { 'active': isActive(folder) },
                  { 'ring-2 ring-primary-500 bg-primary-50 dark:bg-primary-500/20': !reorderMode && dragOverFolder === folder.name },
                  { 'opacity-40 scale-95': draggingFolder?.name === folder.name },
                  { 'cursor-grab hover:bg-surface-100 dark:hover:bg-surface-700': reorderMode && !draggingFolder },
                  { 'cursor-grabbing': reorderMode && draggingFolder },
                  { 'scale-110 bg-primary-100 dark:bg-primary-900/40 ring-2 ring-primary-500 z-10': reorderMode && dropTargetFolder?.name === folder.name }
                ]"
                :style="{ paddingLeft: (folder.depth * 16 + 24) + 'px' }"
                :draggable="reorderMode ? 'true' : 'false'"
                @click="!reorderMode && selectFolder(folder)"
                @contextmenu="showContextMenu($event, folder)"
                @dragover="reorderMode ? onFolderDragOver($event, folder, index) : onDragOver($event, folder)"
                @dragleave="reorderMode ? onFolderDragLeave($event) : onDragLeave()"
                @drop="reorderMode ? onFolderDrop($event, folder, index) : onDrop($event, folder)"
                @dragstart="reorderMode && onReorderDragStart($event, folder)"
                @dragend="reorderMode && onReorderDragEnd()"
              >
                <!-- Reorder position indicator - BEFORE -->
                <div 
                  v-if="reorderMode && dropPosition?.index === index && dropPosition?.position === 'before'"
                  class="absolute -top-1 left-2 right-2 h-1 bg-primary-500 rounded-full shadow-lg shadow-primary-500/50"
                ></div>
                
                <!-- Reorder position indicator - AFTER -->
                <div 
                  v-if="reorderMode && dropPosition?.index === index && dropPosition?.position === 'after'"
                  class="absolute -bottom-1 left-2 right-2 h-1 bg-primary-500 rounded-full shadow-lg shadow-primary-500/50"
                ></div>
                
                <!-- Drag handle in reorder mode -->
                <span v-if="reorderMode" class="material-symbols-rounded text-lg text-surface-400 cursor-grab shrink-0">
                  drag_indicator
                </span>
                
                <!-- Expand/collapse arrow with unread indicator -->
                <span 
                  v-else-if="hasChildren(folder)"
                  @click.stop="toggleExpand(folder)"
                  class="relative material-symbols-rounded text-sm text-surface-400 hover:text-surface-600 -ml-4 w-4 shrink-0"
                >
                  {{ expandedFolders.has(folder.name) ? 'expand_more' : 'chevron_right' }}
                  <span 
                    v-if="hasUnreadDescendants(folder)"
                    class="absolute -top-0.5 -right-0.5 w-2 h-2 bg-primary-500 rounded-full"
                  ></span>
                </span>
                <span v-else class="w-0"></span>
                
                <span class="material-symbols-rounded text-lg shrink-0">folder</span>
                <span class="flex-1 text-left truncate">{{ getDisplayName(folder) }}</span>
                
                <!-- Unread counts (hidden in edit mode) -->
                <template v-if="!reorderMode">
                  <span v-if="folder.unread > 0" class="text-xs font-medium text-primary-500">
                    {{ Math.max(0, folder.unread) }}
                  </span>
                  <span 
                    v-if="hasUnreadDescendants(folder)" 
                    class="text-[10px] font-medium text-primary-400 opacity-75"
                    :title="`${getDescendantUnreadCount(folder)} ${$t('folderTree.unreadInSubfolders')}`"
                  >
                    +{{ getDescendantUnreadCount(folder) }}
                  </span>
                </template>
                
              </div>
              
              <!-- Inline subfolder creation input - appears right after the parent folder -->
              <div 
                v-if="showCreateFolder && !reorderMode && parentFolder === folder.name"
                class="py-1 px-2"
                :style="{ paddingLeft: ((folder.depth + 1) * 16 + 24) + 'px' }"
              >
                <div class="p-2 bg-surface-100 dark:bg-surface-800 rounded-lg">
                  <div class="flex items-center gap-2 mb-2 text-xs text-surface-500">
                    <span class="material-symbols-rounded text-sm">subdirectory_arrow_right</span>
                    {{ $t('folderTree.newSubfolderIn') }} "{{ getDisplayName(folder) }}"
                  </div>
                  <input
                    v-model="newFolderName"
                    type="text"
                    class="input text-sm mb-2 w-full"
                    :placeholder="$t('folderTree.subfolderName')"
                    @keyup.enter="createFolder"
                    @keyup.escape="showCreateFolder = false; newFolderName = ''; parentFolder = null"
                    autofocus
                  />
                  <div class="flex gap-2">
                    <button 
                      @click="createFolder"
                      :disabled="creatingFolder || !newFolderName.trim()"
                      class="btn-primary btn-sm flex-1"
                    >
                      <span v-if="creatingFolder" class="spinner"></span>
                      {{ $t('folderTree.create') }}
                    </button>
                    <button 
                      @click="showCreateFolder = false; newFolderName = ''; parentFolder = null"
                      class="btn-ghost btn-sm"
                    >
                      {{ $t('folderTree.cancel') }}
                    </button>
                  </div>
                </div>
              </div>
            </template>
          </div>
          
          <!-- Empty state -->
          <p v-else-if="showUserFolders && userFolderCount === 0" class="px-3 py-2 text-xs text-surface-400">
            {{ $t('folderTree.noCustomFoldersYet') }}
          </p>
        </div>
      </div>
    </nav>

    <!-- Mailbox storage card pinned to bottom (mirrors the Drive quota card).
         Self-hides unless the server reports an enforced mailbox limit. -->
    <MailQuotaCard class="flex-shrink-0" />
    
    <!-- Context Menu -->
    <Teleport to="body">
      <div 
        v-if="contextMenu.show"
        class="fixed inset-0 z-50"
        @click="closeContextMenu"
        @contextmenu.prevent="closeContextMenu"
      ></div>
      <div
        v-if="contextMenu.show"
        class="context-menu fixed z-50 w-48 bg-white dark:bg-surface-800 rounded-xl shadow-xl border border-surface-200 dark:border-surface-700 py-1 overflow-y-auto"
        :style="{ left: contextMenu.x + 'px', top: contextMenu.y + 'px', maxHeight: 'calc(100vh - 20px)' }"
      >
        <!-- Empty Trash/Spam option for those folders -->
        <button 
          v-if="contextMenu.folder?.type === 'trash' || contextMenu.folder?.type === 'spam' || contextMenu.folder?.type === 'junk'"
          @click="emptyFolderAction" 
          class="context-menu-item text-red-500 hover:bg-red-50 dark:hover:bg-red-500/10"
        >
          <span class="material-symbols-rounded text-lg">delete_forever</span>
          {{ contextMenu.folder?.type === 'trash' ? $t('folderTree.emptyTrash') : $t('folderTree.emptySpam') }}
        </button>
        
        <!-- New subfolder - hidden for system folders (trash, spam, drafts, archive, sent) -->
        <button 
          v-if="!['trash', 'spam', 'junk', 'drafts', 'archive', 'sent'].includes(contextMenu.folder?.type)"
          @click="createSubfolder" 
          class="context-menu-item"
        >
          <span class="material-symbols-rounded text-lg">create_new_folder</span>
          {{ $t('folderTree.newSubfolder') }}
        </button>
        
        <!-- Clean folder - move all messages to trash -->
        <button 
          v-if="contextMenu.folder?.type !== 'trash' && contextMenu.folder?.type !== 'spam' && contextMenu.folder?.type !== 'junk'"
          @click="cleanFolderAction" 
          class="context-menu-item-ghost-yellow"
        >
          <span class="material-symbols-rounded text-lg">cleaning_services</span>
          {{ $t('folderTree.cleanFolder') }}
        </button>
        
        <template v-if="!isSystemFolder(contextMenu.folder)">
          <button @click="startRename" class="context-menu-item">
            <span class="material-symbols-rounded text-lg">edit</span>
            {{ $t('folderTree.rename') }}
          </button>
          
          <div class="border-t border-surface-200 dark:border-surface-700 my-1"></div>
          
          <!-- Move into another folder (nest) -->
          <button @click="openMoveIntoModal" class="context-menu-item">
            <span class="material-symbols-rounded text-lg">subdirectory_arrow_right</span>
            {{ $t('folderTree.moveIntoFolder') }}
          </button>
          
          <!-- Move to parent (unnest) - only for nested folders -->
          <button 
            v-if="getParentFolder(contextMenu.folder?.name)"
            @click="unnestFolderAction" 
            class="context-menu-item"
          >
            <span class="material-symbols-rounded text-lg">subdirectory_arrow_left</span>
            {{ $t('folderTree.moveOutOfParent') }}
          </button>
          
          <!-- Move to root level - only for deeply nested folders -->
          <button 
            v-if="getFolderDepth(contextMenu.folder) > 1"
            @click="moveToRootAction" 
            class="context-menu-item"
          >
            <span class="material-symbols-rounded text-lg">drive_file_move</span>
            {{ $t('folderTree.moveToRootLevel') }}
          </button>
          
          <div class="border-t border-surface-200 dark:border-surface-700 my-1"></div>
          
          <button @click="deleteFolderAction" class="context-menu-item-ghost-red">
            <span class="material-symbols-rounded text-lg">delete</span>
            {{ $t('folderTree.delete') }}
          </button>
        </template>
      </div>
    </Teleport>
    
    <!-- Empty Folder Confirmation Modal -->
    <ConfirmModal
      :show="showEmptyConfirm"
      :title="emptyConfirmFolder?.type === 'trash' ? $t('folderTree.emptyTrash') : $t('folderTree.emptySpam')"
      :message="$t('folderTree.emptyConfirmMessage', { type: emptyConfirmFolder?.type === 'trash' ? $t('folderTree.trash') : $t('folderTree.spam') })"
      :confirm-text="emptyConfirmFolder?.type === 'trash' ? $t('folderTree.emptyTrash') : $t('folderTree.emptySpam')"
      type="danger"
      @confirm="executeEmptyFolder"
      @cancel="showEmptyConfirm = false; emptyConfirmFolder = null"
    />
    
    <!-- Delete Folder Confirmation Modal -->
    <ConfirmModal
      :show="showDeleteConfirm"
      :title="$t('folderTree.deleteFolder')"
      :message="$t('folderTree.confirmDeleteFolder', { name: deleteConfirmFolder ? getDisplayName(deleteConfirmFolder) : '' })"
      :confirm-text="$t('folderTree.delete')"
      type="danger"
      @confirm="executeDeleteFolder"
      @cancel="showDeleteConfirm = false; deleteConfirmFolder = null"
    />
    
    <!-- Clean Folder Modal with Progress -->
    <Teleport to="body">
      <Transition name="fade">
        <div v-if="showCleanConfirm" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50">
          <div class="w-full max-w-md bg-white dark:bg-surface-800 rounded-2xl shadow-2xl overflow-hidden">
            <div class="px-6 py-4 border-b border-surface-200 dark:border-surface-700">
              <h3 class="text-lg font-semibold text-surface-900 dark:text-surface-100">
                {{ cleanProgress.inProgress ? $t('folderTree.cleaningFolder') : (cleanProgress.done ? $t('folderTree.complete') : $t('folderTree.cleanFolderTitle')) }}
              </h3>
            </div>
            
            <div class="p-6">
              <!-- Confirmation View (before cleaning) -->
              <div v-if="!cleanProgress.inProgress && !cleanProgress.done" class="space-y-4">
                <div class="flex items-center gap-3 text-amber-600 dark:text-amber-500">
                  <span class="material-symbols-rounded text-2xl">cleaning_services</span>
                  <p class="text-surface-700 dark:text-surface-300">
                    {{ $t('folderTree.moveAllToTrash', { name: cleanConfirmFolder ? getDisplayName(cleanConfirmFolder) : '' }) }}
                  </p>
                </div>
              </div>
              
              <!-- Progress View -->
              <div v-if="cleanProgress.inProgress" class="space-y-4">
                <div class="flex flex-col items-center py-4">
                  <span class="spinner text-primary-500 w-10 h-10 mb-4"></span>
                  <p class="text-surface-900 dark:text-surface-100 font-medium mb-2">
                    {{ $t('folderTree.movingMessagesToTrash') }}
                  </p>
                  <div class="text-sm text-surface-500 text-center space-y-1">
                    <p>{{ cleanProgress.moved }} {{ $t('folderTree.messagesOf') }} {{ cleanProgress.total }} {{ $t('folderTree.messagesMoved') }}</p>
                    <p v-if="cleanProgress.failed > 0" class="text-red-500">{{ cleanProgress.failed }} {{ $t('folderTree.messagesFailed') }}</p>
                  </div>
                  
                  <!-- Progress bar -->
                  <div class="w-full mt-4 bg-surface-200 dark:bg-surface-700 rounded-full h-2">
                    <div 
                      class="bg-primary-500 h-2 rounded-full transition-all duration-300"
                      :style="{ width: `${cleanProgress.total > 0 ? Math.min(100, (cleanProgress.moved / cleanProgress.total) * 100) : 0}%` }"
                    ></div>
                  </div>
                </div>
              </div>
              
              <!-- Complete View -->
              <div v-if="cleanProgress.done" class="space-y-4">
                <div class="flex flex-col items-center py-4">
                  <span class="material-symbols-rounded text-4xl text-green-500 mb-2">check_circle</span>
                  <p class="text-surface-900 dark:text-surface-100 font-medium">
                    {{ cleanProgress.moved > 0 ? `${cleanProgress.moved} ${t('folderTree.messagesMoved')}` : t('folderTree.folderIsEmpty') }}
                  </p>
                  <p v-if="cleanProgress.failed > 0" class="text-sm text-red-500 mt-1">
                    {{ cleanProgress.failed }} {{ $t('folderTree.messagesFailed') }}
                  </p>
                </div>
              </div>
            </div>
            
            <!-- Footer -->
            <div class="px-6 py-4 border-t border-surface-200 dark:border-surface-700 flex justify-end gap-3">
              <template v-if="!cleanProgress.inProgress && !cleanProgress.done">
                <button @click="showCleanConfirm = false; cleanConfirmFolder = null" class="btn-ghost">
                  {{ $t('folderTree.cancel') }}
                </button>
                <button @click="executeCleanFolder" class="btn-primary bg-amber-500 hover:bg-amber-600">
                  <span class="material-symbols-rounded">cleaning_services</span>
                  {{ $t('folderTree.moveToTrash') }}
                </button>
              </template>
            </div>
          </div>
        </div>
      </Transition>
    </Teleport>
    
    <!-- Move Into Folder Modal -->
    <Teleport to="body">
      <div v-if="showMoveIntoModal" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50">
        <div class="w-full max-w-sm bg-white dark:bg-surface-800 rounded-2xl shadow-2xl overflow-hidden">
          <div class="px-4 py-3 border-b border-surface-200 dark:border-surface-700 flex items-center justify-between">
            <h3 class="font-semibold text-surface-900 dark:text-surface-100">
              {{ $t('folderTree.moveIntoTitle', { name: moveIntoSourceFolder ? getDisplayName(moveIntoSourceFolder) : '' }) }}
            </h3>
            <button @click="showMoveIntoModal = false; moveIntoSourceFolder = null" class="btn-ghost btn-icon btn-sm">
              <span class="material-symbols-rounded">close</span>
            </button>
          </div>
          
          <div class="p-2 max-h-64 overflow-y-auto">
            <button
              v-for="folder in moveIntoTargetFolders"
              :key="folder.name"
              @click="confirmMoveInto(folder)"
              class="w-full flex items-center gap-2 px-3 py-2 rounded-lg hover:bg-surface-100 dark:hover:bg-surface-700 transition-colors text-left"
              :style="{ paddingLeft: (folder.depth * 12 + 12) + 'px' }"
            >
              <span class="material-symbols-rounded text-surface-400">folder</span>
              <span class="text-sm text-surface-700 dark:text-surface-200 truncate">{{ getDisplayName(folder) }}</span>
            </button>
            
            <p v-if="moveIntoTargetFolders.length === 0" class="text-center py-4 text-sm text-surface-500">
              {{ $t('folderTree.noAvailableFolders') }}
            </p>
          </div>
          
          <div class="px-4 py-3 border-t border-surface-200 dark:border-surface-700">
            <button @click="showMoveIntoModal = false; moveIntoSourceFolder = null" class="btn-ghost w-full">
              {{ $t('folderTree.cancel') }}
            </button>
          </div>
        </div>
      </div>
    </Teleport>
  </div>
</template>

<style scoped>
.context-menu-item {
  @apply w-full flex items-center gap-3 px-4 py-2 text-sm text-surface-700 dark:text-surface-200 hover:bg-surface-100 dark:hover:bg-surface-700 transition-colors;
}

/* Red text style */
.context-menu-item-ghost-red {
  @apply w-full flex items-center gap-3 px-4 py-2 text-sm transition-colors;
  @apply text-red-500 hover:bg-red-50 dark:hover:bg-red-500/10;
}

/* Yellow/Amber text style */
.context-menu-item-ghost-yellow {
  @apply w-full flex items-center gap-3 px-4 py-2 text-sm transition-colors;
  @apply text-amber-600 dark:text-amber-500 hover:bg-amber-50 dark:hover:bg-amber-500/10;
}

/* Smooth drag transitions */
.folder-item {
  transition: transform 0.15s ease, opacity 0.15s ease, background-color 0.15s ease, box-shadow 0.15s ease;
}

/* Folder being dragged */
.folder-item.dragging {
  opacity: 0.5;
  transform: scale(0.95);
}

/* Drop zone animations */
.drop-indicator-line {
  animation: pulse-line 1s ease-in-out infinite;
}

@keyframes pulse-line {
  0%, 100% { opacity: 1; }
  50% { opacity: 0.6; }
}

/*
 * Indeterminate progress bar for single-shot batch operations.
 * Shows a 40%-wide bar sliding left-to-right so users see motion
 * even when we don't have a real per-item current count to report.
 */
.bulk-progress-indeterminate {
  width: 40%;
  animation: bulk-progress-slide 1.1s ease-in-out infinite;
}

@keyframes bulk-progress-slide {
  0%   { transform: translateX(-100%); }
  100% { transform: translateX(250%); }
}

/* Fade transition */
.fade-enter-active,
.fade-leave-active {
  transition: opacity 0.2s ease;
}

.fade-enter-from,
.fade-leave-to {
  opacity: 0;
}

/* Slide folders transition */
.slide-folders-enter-active,
.slide-folders-leave-active {
  transition: all 0.2s ease;
  max-height: 300px;
}

.slide-folders-enter-from,
.slide-folders-leave-to {
  opacity: 0;
  max-height: 0;
}
</style>
