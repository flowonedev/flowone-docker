<script setup>
import { ref, computed, watch, onMounted, onUnmounted, nextTick } from 'vue'
import { useMailboxStore } from '@/stores/mailbox'
import { useComposeStore } from '@/stores/compose'
import { useLabelsStore } from '@/stores/labels'
import { useFiltersStore } from '@/stores/filters'
import { useToastStore } from '@/stores/toast'
import { useSpamStore } from '@/stores/spam'
import { useConversationsStore } from '@/stores/conversations'
import { useMindMapStore } from '@/stores/mindmap'
import { useMindMapData } from '@/composables/useMindMapData'
import FilterModal from './FilterModal.vue'
import ConfirmModal from './shared/ConfirmModal.vue'

const props = defineProps({
  x: Number,
  y: Number,
  show: Boolean,
  message: Object, // Single message for right-click on specific email
})

const emit = defineEmits(['close', 'action'])

const mailbox = useMailboxStore()
const compose = useComposeStore()
const labelsStore = useLabelsStore()
const filtersStore = useFiltersStore()
const toast = useToastStore()
const spamStore = useSpamStore()
const conversationsStore = useConversationsStore()
const mindmapStore = useMindMapStore()
const { transformQuickConversation } = useMindMapData()

const showFilterModal = ref(false)
const filterInitialData = ref(null)
const editingExistingFilter = ref(null) // For editing existing filter

// Menu element ref for dynamic positioning
const menuRef = ref(null)
const adjustedY = ref(null) // Will be set after menu renders

// Add to existing filter state
const showAddToFilterModal = ref(false)
const filterSearchQuery = ref('')

const showMoveSubmenu = ref(false)
const showLabelSubmenu = ref(false)
const showMoveToConvSubmenu = ref(false)
const processing = ref(false)

// Submenu close timers
let moveSubmenuTimer = null
let labelSubmenuTimer = null

function openMoveSubmenu() {
  clearTimeout(moveSubmenuTimer)
  clearTimeout(labelSubmenuTimer)
  showLabelSubmenu.value = false
  showMoveSubmenu.value = true
}

function closeMoveSubmenuDelayed() {
  moveSubmenuTimer = setTimeout(() => {
    showMoveSubmenu.value = false
  }, 150)
}

function keepMoveSubmenuOpen() {
  clearTimeout(moveSubmenuTimer)
}

function openLabelSubmenu() {
  clearTimeout(labelSubmenuTimer)
  clearTimeout(moveSubmenuTimer)
  showMoveSubmenu.value = false
  showLabelSubmenu.value = true
}

function closeLabelSubmenuDelayed() {
  // Don't close if the mini context menu is open
  if (labelContextMenu.value) return
  
  labelSubmenuTimer = setTimeout(() => {
    showLabelSubmenu.value = false
  }, 150)
}

function keepLabelSubmenuOpen() {
  clearTimeout(labelSubmenuTimer)
}

// Move to conversation submenu
let moveToConvSubmenuTimer = null
const conversationSearch = ref('')

function openMoveToConvSubmenu() {
  clearTimeout(moveToConvSubmenuTimer)
  clearTimeout(moveSubmenuTimer)
  clearTimeout(labelSubmenuTimer)
  showMoveSubmenu.value = false
  showLabelSubmenu.value = false
  showMoveToConvSubmenu.value = true
}

function closeMoveToConvSubmenuDelayed() {
  moveToConvSubmenuTimer = setTimeout(() => {
    showMoveToConvSubmenu.value = false
  }, 150)
}

function keepMoveToConvSubmenuOpen() {
  clearTimeout(moveToConvSubmenuTimer)
}

// Get available conversations to move to (excluding current conversation)
const availableConversations = computed(() => {
  const convs = mailbox.getConversationsForFolder() || []
  const currentConvKey = props.message?._parentConversation?.conversation_id
    || props.message?.conversation_id
    || props.message?.conversationKey
  
  // Filter out the current conversation and single-message threads
  return convs.filter(c => {
    if (!c.conversation_id) return false
    if (c.conversation_id === currentConvKey) return false
    if (c.message_count <= 0) return false
    return true
  })
})

// Filtered conversations based on search
const filteredConversations = computed(() => {
  const convs = availableConversations.value
  if (!conversationSearch.value.trim()) {
    return convs.slice(0, 10) // Show max 10
  }
  const search = conversationSearch.value.toLowerCase()
  return convs.filter(c => 
    c.subject?.toLowerCase().includes(search) ||
    c.latest_from?.toLowerCase().includes(search)
  ).slice(0, 10)
})

// Handle moving message to another conversation
async function moveToConversation(targetConversationId) {
  const messageToMove = props.message
  if (!messageToMove?.message_id) {
    toast.error('Cannot move - message ID not available')
    return
  }
  
  processing.value = true
  const success = await mailbox.moveMessageToConversation(
    messageToMove.message_id,
    targetConversationId
  )
  processing.value = false
  
  if (success) {
    toast.success('Message moved to conversation')
    emit('close')
  } else {
    toast.error('Failed to move message')
  }
}

// Move submenu state
const folderSearch = ref('')
const showCreateFolder = ref(false)
const newFolderName = ref('')
const creatingFolder = ref(false)
const newFolderParent = ref(null) // Parent folder for subfolder creation

// Label submenu state
const labelSearch = ref('')
const showCreateLabel = ref(false)
const newLabelName = ref('')
const newLabelColor = ref('#3b82f6')
const creatingLabel = ref(false)

// Label edit/delete state
const editingLabelId = ref(null)
const editLabelName = ref('')
const editLabelColor = ref('')
const showDeleteLabelConfirm = ref(false)
const labelToDelete = ref(null)
const labelContextMenu = ref(null) // Which label has context menu open
const labelContextMenuPos = ref({ x: 0, y: 0 }) // Position for the teleported menu
const labelContextMenuData = ref(null) // The actual label object for the menu

// Filtered labels based on search
const filteredLabels = computed(() => {
  if (!labelSearch.value.trim()) {
    return labelsStore.labels
  }
  const search = labelSearch.value.toLowerCase()
  return labelsStore.labels.filter(l => l.name.toLowerCase().includes(search))
})

// Filtered filters based on search (for add to existing filter modal)
const filteredFiltersForModal = computed(() => {
  if (!filterSearchQuery.value.trim()) {
    return filtersStore.filters
  }
  const search = filterSearchQuery.value.toLowerCase()
  return filtersStore.filters.filter(f => f.name.toLowerCase().includes(search))
})

// Color options for new label (fallback if not from API)
const defaultColors = {
  red: '#ef4444',
  orange: '#f97316',
  amber: '#f59e0b',
  yellow: '#eab308',
  lime: '#84cc16',
  green: '#22c55e',
  teal: '#14b8a6',
  cyan: '#06b6d4',
  blue: '#3b82f6',
  indigo: '#6366f1',
  violet: '#8b5cf6',
  purple: '#a855f7',
  pink: '#ec4899',
  rose: '#f43f5e',
}

const colorOptions = computed(() => {
  const colors = Object.keys(labelsStore.colors).length > 0 ? labelsStore.colors : defaultColors
  return Object.entries(colors).map(([name, hex]) => ({
    name,
    hex
  }))
})

// Get selected messages or the right-clicked message
const targetMessages = computed(() => {
  // Helper to check if message is selected using composite key
  const isSelected = (m) => {
    const folder = m.folder || mailbox.currentFolder
    return mailbox.isMessageSelected(m.uid, folder)
  }
  
  // If we have a right-clicked message prop, prioritize it
  // This handles messages inside expanded conversations that aren't in mailbox.messages
  if (props.message) {
    // If there are other selected messages, include them too
    if (mailbox.selectedMessages.length > 1) {
      const selected = mailbox.messages.filter(m => isSelected(m))
      // Make sure we include the right-clicked message if not already in list
      const msgFolder = props.message.folder || mailbox.currentFolder
      const hasCurrentMsg = selected.some(m => m.uid === props.message.uid && (m.folder || mailbox.currentFolder) === msgFolder)
      if (!hasCurrentMsg) {
        return [props.message, ...selected]
      }
      return selected
    }
    // Single message from context menu - use it directly
    return [props.message]
  }
  // Fallback: use selected messages from mailbox
  if (mailbox.selectedMessages.length > 0) {
    return mailbox.messages.filter(m => isSelected(m))
  }
  return []
})

const targetUids = computed(() => targetMessages.value.map(m => m.uid))
const messageCount = computed(() => targetMessages.value.length)
const singleMessage = computed(() => targetMessages.value.length === 1 ? targetMessages.value[0] : null)

// Check if right-clicked item is a conversation with multiple messages
// OR if it's a single message that belongs to a conversation (expanded view)
const isConversation = computed(() => {
  // Case 1: Right-clicked on a conversation header
  if (props.message?.isConversation && props.message?.messages?.length > 1) {
    return true
  }
  // Case 2: Right-clicked on an individual message inside an expanded conversation
  if (props.message?._parentConversation?.messageCount > 1) {
    return true
  }
  return false
})

// Check if this is an individual message from expanded conversation (for split UI)
const isMessageInsideConversation = computed(() => {
  return !!props.message?._parentConversation
})

// Get all UIDs from a conversation
const conversationUids = computed(() => {
  if (!isConversation.value) return []
  return props.message.messages.map(m => m.uid)
})

const conversationCount = computed(() => conversationUids.value.length)

// Get folder display name with arrow separators
function getFolderDisplayName(name) {
  if (name === 'INBOX') return 'Inbox'
  if (name?.startsWith('INBOX.')) {
    // Replace dots with arrows for subfolder paths
    return name.slice(6).replace(/\./g, ' -> ')
  }
  return name?.replace(/\./g, ' -> ') || name
}

// Get folder depth for indentation
function getFolderDepth(name) {
  if (name === 'INBOX') return 0
  if (name.startsWith('INBOX.')) {
    return name.slice(6).split('.').length
  }
  return name.split('.').length
}

// Check if we're in a trash folder
const isInTrash = computed(() => {
  const folder = mailbox.folders.find(f => f.name === mailbox.currentFolder)
  return folder?.type === 'trash'
})

// Check if we're in a spam/junk folder
const isInSpam = computed(() => {
  const folder = mailbox.folders.find(f => f.name === mailbox.currentFolder)
  return folder?.type === 'spam' || folder?.type === 'junk' || 
         mailbox.currentFolder?.toLowerCase().includes('spam') ||
         mailbox.currentFolder?.toLowerCase().includes('junk')
})

// Get sender email from message
function getSenderEmail(msg) {
  if (!msg) return null
  if (msg.from_email) return msg.from_email
  if (msg.from) {
    const from = Array.isArray(msg.from) ? msg.from[0] : msg.from
    return from?.email || null
  }
  return null
}

// Spam action state
const showSpamConfirm = ref(false)
const showNotSpamConfirm = ref(false)
const showBlockSenderConfirm = ref(false)
const pendingSpamUids = ref([])
const pendingSpamMessage = ref(null)
const blockSenderToo = ref(false)
const addToSafeToo = ref(false)
const spamProcessing = ref(false)

// Split conversation state
const showSplitConfirm = ref(false)
const pendingSplitMessage = ref(null)
const dontAskSplitAgain = ref(localStorage.getItem('split_confirm_disabled') === 'true')
const splitProcessing = ref(false)

// Folders for move submenu, sorted hierarchically
const moveFolders = computed(() => {
  return mailbox.folders
    .filter(f => f.name !== mailbox.currentFolder)
    .sort((a, b) => a.name.localeCompare(b.name))
})

// Filtered folders based on search
const filteredFolders = computed(() => {
  if (!folderSearch.value.trim()) {
    return moveFolders.value
  }
  const search = folderSearch.value.toLowerCase()
  return moveFolders.value.filter(f => f.name.toLowerCase().includes(search))
})

// Close on escape
function handleKeydown(e) {
  if (e.key === 'Escape') {
    resetAllSubmenus()
    emit('close')
  }
}

// Reset move submenu state
function resetMoveSubmenu() {
  folderSearch.value = ''
  showCreateFolder.value = false
  newFolderName.value = ''
  newFolderParent.value = null
}

// Reset label submenu state
function resetLabelSubmenu() {
  labelSearch.value = ''
  showCreateLabel.value = false
  newLabelName.value = ''
  newLabelColor.value = '#3b82f6'
  // Reset edit/delete state
  editingLabelId.value = null
  editLabelName.value = ''
  editLabelColor.value = ''
  labelContextMenu.value = null
}

// Reset all submenus
function resetAllSubmenus() {
  resetMoveSubmenu()
  resetLabelSubmenu()
  showMoveSubmenu.value = false
  showLabelSubmenu.value = false
  showMoveToConvSubmenu.value = false
  showRestoreSubmenu.value = false
}

onMounted(() => {
  document.addEventListener('keydown', handleKeydown)
})

onUnmounted(() => {
  document.removeEventListener('keydown', handleKeydown)
  clearTimeout(moveSubmenuTimer)
  clearTimeout(labelSubmenuTimer)
  clearTimeout(moveToConvSubmenuTimer)
  clearTimeout(restoreSubmenuTimer)
})

// Actions
async function reply() {
  if (singleMessage.value) {
    emit('close')
    // Fetch full message content first
    await mailbox.fetchMessage(singleMessage.value.uid)
    if (mailbox.currentMessage) {
      compose.open('reply', mailbox.currentMessage)
    }
  } else {
    emit('close')
  }
}

async function replyAll() {
  if (singleMessage.value) {
    emit('close')
    // Fetch full message content first
    await mailbox.fetchMessage(singleMessage.value.uid)
    if (mailbox.currentMessage) {
      compose.open('replyAll', mailbox.currentMessage)
    }
  } else {
    emit('close')
  }
}

async function forward() {
  if (singleMessage.value) {
    emit('close')
    // Fetch full message content first
    await mailbox.fetchMessage(singleMessage.value.uid)
    if (mailbox.currentMessage) {
      compose.open('forward', mailbox.currentMessage)
    }
  } else {
    emit('close')
  }
}

async function archive() {
  processing.value = true
  const items = targetMessages.value.map(m => ({ uid: m.uid, folder: m.folder || mailbox.currentFolder }))
  const result = await mailbox.bulkMoveMessages(items, 'INBOX.Archive')
  processing.value = false
  mailbox.clearSelection()
  if (result.success > 0) toast.success(`Archived ${result.success} message(s)`)
  emit('close')
}

// Confirmation for permanent delete
const showPermanentDeleteConfirm = ref(false)
const pendingDeleteItems = ref([]) // Store {uid, folder} before closing menu
const pendingDeleteCount = ref(0) // Store count for modal message

// Confirmation for delete conversation
const showDeleteConversationConfirm = ref(false)
const pendingConversationItems = ref([]) // Store {uid, folder} before closing menu
const pendingConversationCount = ref(0) // Store count for modal message

async function deleteMessages() {
  // If in trash, ask for confirmation before permanent delete
  if (isInTrash.value) {
    pendingDeleteItems.value = targetMessages.value.map(m => ({ uid: m.uid, folder: m.folder || mailbox.currentFolder }))
    pendingDeleteCount.value = targetMessages.value.length
    emit('close') // Close context menu first
    showPermanentDeleteConfirm.value = true
    return
  }
  
  processing.value = true
  const items = [...targetMessages.value].map(m => ({ uid: m.uid, folder: m.folder || mailbox.currentFolder }))
  const result = await mailbox.bulkDeleteMessages(items)
  processing.value = false
  mailbox.clearSelection()
  if (result.success > 0) toast.success(`Deleted ${result.success} message(s)`)
  emit('close')
}

async function confirmPermanentDelete() {
  showPermanentDeleteConfirm.value = false
  const itemsToDelete = [...pendingDeleteItems.value]
  pendingDeleteItems.value = []
  pendingDeleteCount.value = 0
  
  const result = await mailbox.bulkDeleteMessages(itemsToDelete, true)
  mailbox.clearSelection()
  if (result.success > 0) toast.success(`Permanently deleted ${result.success} message(s)`)
}

function cancelPermanentDelete() {
  showPermanentDeleteConfirm.value = false
  pendingDeleteItems.value = []
  pendingDeleteCount.value = 0
}

// Delete entire conversation
function promptDeleteConversation() {
  pendingConversationItems.value = conversationUids.value.map(uid => {
    const msg = mailbox.findMessageByUid(uid)
    return { uid, folder: msg?.folder || mailbox.currentFolder }
  })
  pendingConversationCount.value = conversationUids.value.length
  emit('close') // Close context menu first
  showDeleteConversationConfirm.value = true
}

async function confirmDeleteConversation() {
  showDeleteConversationConfirm.value = false
  const itemsToDelete = [...pendingConversationItems.value]
  pendingConversationItems.value = []
  pendingConversationCount.value = 0
  
  const result = await mailbox.bulkDeleteMessages(itemsToDelete)
  mailbox.clearSelection()
  if (result.success > 0) toast.success(`Deleted conversation (${result.success} message${result.success > 1 ? 's' : ''})`)
}

function cancelDeleteConversation() {
  showDeleteConversationConfirm.value = false
  pendingConversationItems.value = []
  pendingConversationCount.value = 0
}

// Spam actions
function promptReportSpam() {
  if (singleMessage.value) {
    pendingSpamMessage.value = singleMessage.value
  }
  pendingSpamUids.value = [...targetUids.value]
  blockSenderToo.value = false
  emit('close')
  showSpamConfirm.value = true
}

async function confirmReportSpam() {
  showSpamConfirm.value = false
  spamProcessing.value = true

  const folder = mailbox.currentFolder
  const items = pendingSpamUids.value.map(uid => ({ uid, folder }))
  const senderToBlock = blockSenderToo.value && pendingSpamMessage.value
    ? getSenderEmail(pendingSpamMessage.value)
    : null

  const result = await spamStore.bulkReportSpam(items, { train: true })
  const moved = result?.moved || 0

  if (moved > 0 && senderToBlock) {
    try {
      await spamStore.blockSender(senderToBlock, { createFilter: true })
    } catch (e) {
      console.error('Failed to block sender after batch report:', e)
    }
  }

  spamProcessing.value = false
  pendingSpamUids.value = []
  pendingSpamMessage.value = null
  blockSenderToo.value = false
  mailbox.clearSelection()
}

function cancelReportSpam() {
  showSpamConfirm.value = false
  pendingSpamUids.value = []
  pendingSpamMessage.value = null
  blockSenderToo.value = false
}

function promptNotSpam() {
  if (singleMessage.value) {
    pendingSpamMessage.value = singleMessage.value
  }
  pendingSpamUids.value = [...targetUids.value]
  addToSafeToo.value = false
  emit('close')
  showNotSpamConfirm.value = true
}

async function confirmNotSpam() {
  showNotSpamConfirm.value = false
  spamProcessing.value = true

  const folder = mailbox.currentFolder
  const items = pendingSpamUids.value.map(uid => ({ uid, folder }))
  const senderToAdd = addToSafeToo.value && pendingSpamMessage.value
    ? getSenderEmail(pendingSpamMessage.value)
    : null

  const result = await spamStore.bulkNotSpam(items, { train: true })
  const moved = result?.moved || 0

  if (moved > 0 && senderToAdd) {
    try {
      await spamStore.addSafeSender(senderToAdd, false)
    } catch (e) {
      console.error('Failed to add safe sender after batch not-spam:', e)
    }
  }

  spamProcessing.value = false
  pendingSpamUids.value = []
  pendingSpamMessage.value = null
  addToSafeToo.value = false
  mailbox.clearSelection()
}

function cancelNotSpam() {
  showNotSpamConfirm.value = false
  pendingSpamUids.value = []
  pendingSpamMessage.value = null
  addToSafeToo.value = false
}

function promptBlockSender() {
  if (singleMessage.value) {
    pendingSpamMessage.value = singleMessage.value
  }
  emit('close')
  showBlockSenderConfirm.value = true
}

async function confirmBlockSender() {
  showBlockSenderConfirm.value = false
  
  const email = getSenderEmail(pendingSpamMessage.value)
  if (!email) {
    toast.error('Could not determine sender email')
    return
  }
  
  spamProcessing.value = true
  
  // Block the sender
  const blocked = await spamStore.blockSender(email, { reason: 'Blocked from context menu' })
  
  if (blocked && pendingSpamMessage.value?.uid) {
    const srcFolder = pendingSpamMessage.value.folder || mailbox.currentFolder
    await mailbox.deleteMessage(pendingSpamMessage.value.uid, srcFolder)
    toast.success(`Blocked ${email} and deleted message`)
  }
  
  spamProcessing.value = false
  pendingSpamMessage.value = null
}

function cancelBlockSender() {
  showBlockSenderConfirm.value = false
  pendingSpamMessage.value = null
}

// Split conversation functions
function promptSplitConversation() {
  if (!singleMessage.value) return
  
  // For messages inside expanded conversations, use the message directly
  // For conversation headers, we'd use the latest message (already handled by singleMessage)
  pendingSplitMessage.value = singleMessage.value
  
  emit('close')
  
  // Skip confirmation if user chose "Don't ask again"
  if (dontAskSplitAgain.value) {
    executeSplit()
  } else {
    showSplitConfirm.value = true
  }
}

async function executeSplit() {
  showSplitConfirm.value = false
  
  // Save preference if user checked "Don't ask again"
  if (dontAskSplitAgain.value) {
    localStorage.setItem('split_confirm_disabled', 'true')
  }
  
  const messageToSplit = pendingSplitMessage.value
  
  if (!messageToSplit?.message_id) {
    toast.error('Cannot split - message ID not available')
    pendingSplitMessage.value = null
    return
  }
  
  splitProcessing.value = true
  
  // Use the new database-backed split system
  const newConversationId = await mailbox.splitMessageToNewConversation(
    messageToSplit.message_id,
    mailbox.currentFolder
  )
  
  splitProcessing.value = false
  
  if (newConversationId) {
    // Store messageId in closure for undo - important!
    const splitMessageId = messageToSplit.message_id
    
    // Show success toast with undo option
    toast.success('Conversation split', {
      action: {
        label: 'Undo',
        onClick: async () => {
          await conversationsStore.resetOverride(mailbox.currentFolder, splitMessageId)
          toast.success('Original conversation restored')
        }
      },
      duration: 8000 // 8 second undo window
    })
    
    emit('close')
  } else {
    toast.error('Failed to split conversation')
  }
  
  pendingSplitMessage.value = null
}

function cancelSplit() {
  showSplitConfirm.value = false
  pendingSplitMessage.value = null
}

// Restore submenu state
const showRestoreSubmenu = ref(false)
let restoreSubmenuTimer = null

function openRestoreSubmenu() {
  clearTimeout(restoreSubmenuTimer)
  clearTimeout(moveSubmenuTimer)
  clearTimeout(labelSubmenuTimer)
  showMoveSubmenu.value = false
  showLabelSubmenu.value = false
  showRestoreSubmenu.value = true
}

function closeRestoreSubmenuDelayed() {
  restoreSubmenuTimer = setTimeout(() => {
    showRestoreSubmenu.value = false
  }, 150)
}

function keepRestoreSubmenuOpen() {
  clearTimeout(restoreSubmenuTimer)
}

// Get folders for restore (exclude trash)
const restoreFolders = computed(() => {
  return mailbox.folders.filter(f => f.type !== 'trash')
})

async function restoreToFolder(folder) {
  showRestoreSubmenu.value = false
  processing.value = true
  const items = targetMessages.value.map(m => ({
    uid: m.uid,
    folder: m.folder || mailbox.currentFolder,
  }))
  const result = await mailbox.bulkRestoreMessages(items, folder.name)
  processing.value = false
  mailbox.clearSelection()
  toast.success(`Restored ${result.success} message(s) to ${getFolderDisplayName(folder.name)}`)
  emit('close')
}

async function markAsRead() {
  processing.value = true
  const items = targetMessages.value.map(m => ({
    uid: m.uid,
    folder: m.folder || mailbox.currentFolder,
  }))
  await mailbox.bulkSetFlag(items, 'seen', true)
  processing.value = false
  mailbox.clearSelection()
  toast.success(`Marked ${messageCount.value} message(s) as read`)
  emit('close')
}

async function markAsUnread() {
  processing.value = true
  const items = targetMessages.value.map(m => ({
    uid: m.uid,
    folder: m.folder || mailbox.currentFolder,
  }))
  await mailbox.bulkSetFlag(items, 'seen', false)
  processing.value = false
  mailbox.clearSelection()
  toast.success(`Marked ${messageCount.value} message(s) as unread`)
  emit('close')
}

async function toggleStar() {
  processing.value = true
  const shouldStar = !targetMessages.value.every(m => m.flagged)
  const items = targetMessages.value.map(m => ({
    uid: m.uid,
    folder: m.folder || mailbox.currentFolder,
  }))
  await mailbox.bulkSetFlag(items, 'flagged', shouldStar)
  processing.value = false
  mailbox.clearSelection()
  toast.success(shouldStar ? `Starred ${messageCount.value} message(s)` : `Unstarred ${messageCount.value} message(s)`)
  emit('close')
}

// Check if all selected messages are pinned
const allPinned = computed(() => {
  // Use message's actual folder for virtual views (ALL_MAIL, SEARCH_RESULTS)
  return targetMessages.value.every(m => mailbox.isEmailPinned(m.uid, m.folder || mailbox.currentFolder))
})

async function togglePin() {
  processing.value = true
  const shouldPin = !allPinned.value
  const items = targetMessages.value.map(msg => ({
    uid: msg.uid,
    folder: msg.folder || mailbox.currentFolder,
    message_id: msg.message_id,
    subject: msg.subject,
  }))
  await mailbox.bulkSetPin(items, shouldPin)
  processing.value = false
  mailbox.clearSelection()
  toast.success(shouldPin ? `Pinned ${messageCount.value} message(s)` : `Unpinned ${messageCount.value} message(s)`)
  emit('close')
}

async function moveToFolder(folder) {
  processing.value = true
  const items = [...targetMessages.value].map(m => ({ uid: m.uid, folder: m.folder || mailbox.currentFolder }))
  const result = await mailbox.bulkMoveMessages(items, folder)
  processing.value = false
  mailbox.clearSelection()
  if (result.success > 0) toast.success(`Moved ${result.success} message(s) to ${folder}`)
  emit('close')
}

async function createAndMoveToFolder() {
  if (!newFolderName.value.trim()) return
  
  creatingFolder.value = true
  
  // Create the folder first
  const folderName = newFolderName.value.trim()
  const success = await mailbox.createFolder(folderName, newFolderParent.value)
  
  if (success) {
    // Build the full folder name for moving
    let fullFolderName = folderName
    if (newFolderParent.value) {
      if (newFolderParent.value === 'INBOX') {
        fullFolderName = 'INBOX.' + folderName
      } else {
        fullFolderName = newFolderParent.value + '.' + folderName
      }
    } else {
      fullFolderName = 'INBOX.' + folderName
    }
    
    // Now move the messages to the new folder
    await moveToFolder(fullFolderName)
    toast.success(`Created folder "${folderName}" and moved messages`)
    newFolderName.value = ''
    newFolderParent.value = null
    showCreateFolder.value = false
  } else {
    toast.error('Failed to create folder')
  }
  
  creatingFolder.value = false
}

async function toggleLabel(label, closeAfter = false) {
  if (!singleMessage.value) {
    toast.error('Labels can only be applied to one message at a time')
    return
  }
  
  const messageId = singleMessage.value.message_id
  if (!messageId) {
    toast.error('Cannot add label - message ID not available')
    return
  }

  const canonicalMsg = mailbox.findMessageByUid(
    singleMessage.value.uid,
    singleMessage.value.folder || mailbox.currentFolder
  )
  const labelExists = hasLabel(label.id)
  
  if (labelExists) {
    const success = await labelsStore.removeLabelFromMessage(messageId, label.id)
    if (success) {
      const update = (m) => { if (m && Array.isArray(m.labels)) m.labels = m.labels.filter(l => l.id !== label.id) }
      update(canonicalMsg)
      update(singleMessage.value)
      if (mailbox.currentMessage?.message_id === messageId) update(mailbox.currentMessage)
      mailbox.notifyMessagesChanged()
    } else {
      toast.error('Failed to remove label')
    }
  } else {
    const success = await labelsStore.addLabelToMessage(messageId, label.id)
    if (success) {
      const add = (m) => { if (m) { if (!Array.isArray(m.labels)) m.labels = []; if (!m.labels.some(l => l.id === label.id)) m.labels.push(label) } }
      add(canonicalMsg)
      add(singleMessage.value)
      if (mailbox.currentMessage?.message_id === messageId) add(mailbox.currentMessage)
      mailbox.notifyMessagesChanged()
    } else {
      toast.error('Failed to add label')
    }
  }
  
  if (closeAfter) {
    emit('close')
  }
}

function hasLabel(labelId) {
  const messageId = singleMessage.value?.message_id
  if (!messageId) return false
  const canonicalMsg = mailbox.findMessageByUid(
    singleMessage.value?.uid,
    singleMessage.value?.folder || mailbox.currentFolder
  )
  const labels = canonicalMsg?.labels ?? singleMessage.value?.labels
  return labels?.some(l => l.id === labelId) ?? false
}

// Get unique senders from selected messages
function extractSendersFromMessages() {
  const senders = new Set()
  for (const msg of targetMessages.value) {
    if (msg.from_email) {
      senders.add(msg.from_email)
    } else if (msg.from) {
      // Extract email from "Name <email>" format
      const match = msg.from.match(/<([^>]+)>/)
      if (match) {
        senders.add(match[1])
      } else {
        senders.add(msg.from)
      }
    }
  }
  return senders
}

// Create filter from selected messages
function createFilter() {
  // Build conditions from selected messages
  const conditions = {
    match: 'any',
    rules: []
  }
  
  const senders = extractSendersFromMessages()
  
  // Add conditions for each sender
  for (const sender of senders) {
    conditions.rules.push({
      field: 'from',
      operator: 'contains',
      value: sender
    })
  }
  
  // If no senders found, add empty rule
  if (conditions.rules.length === 0) {
    conditions.rules.push({ field: 'from', operator: 'contains', value: '' })
  }
  
  // Open filter modal with pre-populated data
  filterInitialData.value = {
    name: senders.size === 1 ? `Filter for ${[...senders][0]}` : `Filter for ${senders.size} senders`,
    conditions,
    actions: [{ action: 'move', value: '' }]
  }
  
  editingExistingFilter.value = null
  emit('close')
  showFilterModal.value = true
}

// Open the "Add to existing filter" selection modal
async function openAddToFilterModal() {
  emit('close')
  showAddToFilterModal.value = true
  // Always fetch filters to get latest
  await filtersStore.fetchFilters()
}

// Open Mind Map for conversation/email
function openMindMap(mode = 'conversation') {
  emit('close')
  
  // Get the conversation or single message
  const message = props.message || targetMessages.value[0]
  if (!message) return
  
  if (mode === 'conversation') {
    // Build mind map data from the conversation
    let mindMapData
    
    // If it's a conversation with multiple messages
    if (message._parentConversation || message.isConversation) {
      const conv = message._parentConversation || message
      const messages = conv.messages || mailbox.getConversationMessages(conv.conversationKey) || [message]
      
      mindMapData = transformQuickConversation({
        ...conv,
        messages,
        subject: conv.subject || message.subject,
      })
    } else {
      // Single email - show as simple node
      mindMapData = {
        id: `email-${message.message_id || message.uid}`,
        type: 'email',
        label: message.subject || 'No Subject',
        icon: 'mail',
        meta: {
          messageId: message.message_id,
          uid: message.uid,
          folder: mailbox.currentFolder,
          from: message.from_name || message.from_email,
          timestamp: message.timestamp,
          unread: !message.seen,
          hasAttachment: message.has_attachment,
        },
        children: [],
      }
    }
    
    if (mindMapData) {
      const senderEmail = message.from_email || message.from?.address
      mindmapStore.openMindMap('conversation', message.conversation_id || message.conversationKey, mindMapData, {
        senderEmail: senderEmail,
        senderName: message.from_name || message.from?.name,
        subject: message.subject,
        folder: mailbox.currentFolder,
        conversationId: message.conversation_id || message.conversationKey,
      })
    }
  } else if (mode === 'client') {
    // Open client mind map - will fetch from API
    const email = message.from_email || message.from?.address
    if (email) {
      mindmapStore.openMindMap('client', email, null, {
        senderEmail: email,
        senderName: message.from_name || message.from?.name,
        subject: message.subject,
        folder: mailbox.currentFolder,
      })
    }
  } else if (mode === 'topic') {
    // Open topic cluster - will fetch from API based on subject keywords
    const subject = message.subject || ''
    mindmapStore.openMindMap('topic', subject, null, {
      subject: subject,
      folder: mailbox.currentFolder,
      senderEmail: message.from_email || message.from?.address,
    })
  }
}

// Get sender info for context menu
const senderEmail = computed(() => {
  const message = props.message || targetMessages.value[0]
  return message?.from_email || message?.from?.address || null
})

const senderName = computed(() => {
  const message = props.message || targetMessages.value[0]
  return message?.from_name || message?.from?.name || null
})

// Add selected emails to an existing filter
function addToExistingFilter(filter) {
  showAddToFilterModal.value = false
  filterSearchQuery.value = ''
  
  const senders = extractSendersFromMessages()
  if (senders.size === 0) {
    toast.error('No sender information available')
    return
  }
  
  // Deep clone the filter to avoid mutating the original
  const filterCopy = structuredClone(filter)
  
  // Get existing conditions - handle both legacy and groups format
  let existingRules = []
  if (filterCopy.conditions?.groups) {
    // New groups format - get rules from first group (usually OR conditions)
    existingRules = filterCopy.conditions.groups[0]?.rules || []
  } else if (filterCopy.conditions?.rules) {
    // Legacy format
    existingRules = filterCopy.conditions.rules
  }
  
  // Get existing sender values to avoid duplicates
  const existingSenders = new Set(
    existingRules
      .filter(r => r.field === 'from' && r.operator === 'contains')
      .map(r => r.value.toLowerCase())
  )
  
  // Add new senders that don't already exist
  let addedCount = 0
  for (const sender of senders) {
    if (!existingSenders.has(sender.toLowerCase())) {
      existingRules.push({
        field: 'from',
        operator: 'contains',
        value: sender
      })
      addedCount++
    }
  }
  
  if (addedCount === 0) {
    toast.info('All selected senders already exist in this filter')
    return
  }
  
  // Update the conditions structure
  if (filterCopy.conditions?.groups) {
    filterCopy.conditions.groups[0].rules = existingRules
    // Ensure the group uses 'any' match for OR behavior
    filterCopy.conditions.groups[0].match = 'any'
  } else {
    filterCopy.conditions = {
      match: 'any',
      rules: existingRules
    }
  }
  
  // Open filter modal in edit mode with the updated filter
  // Pass filterCopy as editingFilter so the modal uses the MODIFIED data (not original)
  filterInitialData.value = null
  editingExistingFilter.value = filterCopy  // Use the modified copy, not original
  showFilterModal.value = true
  
  toast.info(`Added ${addedCount} new sender(s) to filter`)
}

// Create and apply a new label
async function createAndApplyLabel() {
  if (!newLabelName.value.trim()) return
  
  creatingLabel.value = true
  const label = await labelsStore.createLabel(newLabelName.value.trim(), newLabelColor.value)
  
  if (label) {
    // Apply the new label to the message
    await toggleLabel(label)
    toast.success(`Created and applied "${label.name}" label`)
    newLabelName.value = ''
    showCreateLabel.value = false
  } else {
    toast.error('Failed to create label')
  }
  
  creatingLabel.value = false
}

// Label edit functions
function openLabelContextMenu(event, label) {
  event.stopPropagation()
  const rect = event.currentTarget.getBoundingClientRect()
  const menuWidth = 130
  const menuHeight = 80
  const padding = 8
  
  let x = rect.left - menuWidth // Position to the left of the button
  let y = rect.top
  
  // Check left edge overflow - if it would overflow left, position to the right
  if (x < padding) {
    x = rect.right + 4
  }
  
  // Check right edge overflow
  if (x + menuWidth > window.innerWidth - padding) {
    x = window.innerWidth - menuWidth - padding
  }
  
  // Check bottom edge overflow
  if (y + menuHeight > window.innerHeight - padding) {
    y = window.innerHeight - menuHeight - padding
  }
  
  labelContextMenuPos.value = { x, y }
  labelContextMenuData.value = label
  labelContextMenu.value = label.id
  // Keep the submenu open
  keepLabelSubmenuOpen()
}

function closeLabelContextMenu() {
  labelContextMenu.value = null
  labelContextMenuData.value = null
}

function startEditLabel(label) {
  editingLabelId.value = label.id
  editLabelName.value = label.name
  editLabelColor.value = label.color
  closeLabelContextMenu()
}

function cancelEditLabel() {
  editingLabelId.value = null
  editLabelName.value = ''
  editLabelColor.value = ''
}

async function saveEditLabel() {
  if (!editLabelName.value.trim()) return
  
  const success = await labelsStore.updateLabel(editingLabelId.value, editLabelName.value.trim(), editLabelColor.value)
  if (success) {
    toast.success('Label updated')
    cancelEditLabel()
  } else {
    toast.error('Failed to update label')
  }
}

function confirmDeleteLabel(label) {
  labelToDelete.value = label
  showDeleteLabelConfirm.value = true
  closeLabelContextMenu()
}

async function executeDeleteLabel() {
  if (!labelToDelete.value) return
  
  const success = await labelsStore.deleteLabel(labelToDelete.value.id)
  if (success) {
    toast.success(`Label "${labelToDelete.value.name}" deleted`)
    // If the deleted label was on the current message, remove it
    if (singleMessage.value && hasLabel(labelToDelete.value.id)) {
      singleMessage.value.labels = singleMessage.value.labels.filter(l => l.id !== labelToDelete.value.id)
      const msg = mailbox.messages.find(m => m.message_id === singleMessage.value.message_id)
      if (msg) {
        msg.labels = singleMessage.value.labels
      }
    }
  } else {
    toast.error('Failed to delete label')
  }
  
  showDeleteLabelConfirm.value = false
  labelToDelete.value = null
}

// Menu position adjustment - keep menu within viewport
const menuStyle = computed(() => {
  const menuWidth = 272 // w-68 = 17rem = 272px
  const padding = 8 // Padding from viewport edge
  
  // Default to click position
  let x = props.x || 0
  // Use adjusted Y if available (set after menu renders), otherwise use props.y
  let y = adjustedY.value !== null ? adjustedY.value : (props.y || 0)
  
  // Ensure we have valid window dimensions
  const winWidth = window.innerWidth || 1024
  
  // Check right edge - position menu to the left of click if it would overflow
  if (x + menuWidth > winWidth - padding) {
    x = Math.max(padding, winWidth - menuWidth - padding)
  }
  
  // Check left edge
  if (x < padding) {
    x = padding
  }
  
  return {
    left: `${x}px`,
    top: `${y}px`,
  }
})

// Adjust menu Y position after it renders based on actual height
async function adjustMenuPosition() {
  await nextTick()
  
  if (!menuRef.value || !props.show) {
    adjustedY.value = null
    return
  }
  
  const padding = 8
  const winHeight = window.innerHeight || 768
  const menuRect = menuRef.value.getBoundingClientRect()
  const menuHeight = menuRect.height
  
  let y = props.y || 0
  
  // Check if menu would overflow bottom
  if (y + menuHeight > winHeight - padding) {
    // Position menu above the click point if there's more room above
    const spaceAbove = props.y
    const spaceBelow = winHeight - props.y
    
    if (spaceAbove > spaceBelow && menuHeight <= spaceAbove) {
      // Position above click point
      y = props.y - menuHeight - padding
    } else {
      // Shift up to fit in viewport
      y = Math.max(padding, winHeight - menuHeight - padding)
    }
  }
  
  // Ensure y is not negative
  if (y < padding) {
    y = padding
  }
  
  adjustedY.value = y
}

// Submenu positioning - align bottom of submenu with bottom of main menu when overflow would occur
// Button row height is ~44px

const restoreSubmenuStyle = computed(() => {
  const submenuWidth = 224 // w-56 = 14rem = 224px
  const submenuHeight = 280 // Estimated submenu height
  const mainMenuWidth = 272 // Updated to match main menu width
  const padding = 8
  
  const menuX = parseFloat(menuStyle.value.left) || props.x
  const menuY = parseFloat(menuStyle.value.top) || props.y
  
  let style = {}
  
  // Check if submenu would overflow right edge
  if (menuX + mainMenuWidth + submenuWidth + padding > window.innerWidth) {
    style.right = 'calc(100% + 4px)'
    style.left = 'auto'
  } else {
    style.left = 'calc(100% + 4px)'
  }
  
  // Check if submenu would overflow bottom
  if (menuY + submenuHeight > window.innerHeight - padding) {
    const shiftUp = (menuY + submenuHeight) - (window.innerHeight - padding)
    style.top = `-${shiftUp}px`
  } else {
    style.top = '0'
  }
  
  return style
})

const moveSubmenuStyle = computed(() => {
  const submenuWidth = 224 // w-56 = 14rem = 224px
  const submenuHeight = 320 // Estimated submenu height
  const mainMenuWidth = 272 // Updated to match main menu width
  const padding = 8
  const buttonRowHeight = 44
  
  const menuX = parseFloat(menuStyle.value.left) || props.x
  const menuY = parseFloat(menuStyle.value.top) || props.y
  const buttonOffsetFromTop = 370 // Move to button position from menu top
  const buttonY = menuY + buttonOffsetFromTop
  
  let style = {}
  
  // Check if submenu would overflow right edge
  if (menuX + mainMenuWidth + submenuWidth + padding > window.innerWidth) {
    // Position to the left of the main menu
    style.right = 'calc(100% + 4px)'
    style.left = 'auto'
  } else {
    style.left = 'calc(100% + 4px)'
  }
  
  // Check if submenu would overflow bottom when positioned at top:0
  if (buttonY + submenuHeight > window.innerHeight - padding) {
    // Align submenu bottom with main menu bottom
    const distanceToMenuBottom = buttonRowHeight + buttonRowHeight + 8 // Label as row + padding
    const shiftUp = submenuHeight - distanceToMenuBottom
    style.top = `-${shiftUp}px`
  } else {
    style.top = '0'
  }
  
  return style
})

const labelSubmenuStyle = computed(() => {
  const submenuWidth = 256 // w-64 = 16rem = 256px
  const submenuHeight = 350 // Estimated submenu height  
  const mainMenuWidth = 272 // Updated to match main menu width
  const padding = 8
  const buttonRowHeight = 44
  
  const menuX = parseFloat(menuStyle.value.left) || props.x
  const menuY = parseFloat(menuStyle.value.top) || props.y
  const buttonOffsetFromTop = 420 // Label as button position from menu top
  const buttonY = menuY + buttonOffsetFromTop
  
  let style = {}
  
  // Check if submenu would overflow right edge
  if (menuX + mainMenuWidth + submenuWidth + padding > window.innerWidth) {
    // Position to the left of the main menu
    style.right = 'calc(100% + 4px)'
    style.left = 'auto'
  } else {
    style.left = 'calc(100% + 4px)'
  }
  
  // Check if submenu would overflow bottom when positioned at top:0
  if (buttonY + submenuHeight > window.innerHeight - padding) {
    // Align submenu bottom with main menu bottom
    const distanceToMenuBottom = buttonRowHeight + 8
    const shiftUp = submenuHeight - distanceToMenuBottom
    style.top = `-${shiftUp}px`
  } else {
    style.top = '0'
  }
  
  return style
})

// Reset label submenu when menu opens and adjust position
watch(() => props.show, (newVal) => {
  if (newVal) {
    resetAllSubmenus()
    conversationSearch.value = ''
    folderSearch.value = ''
    labelSearch.value = ''
    filterSearchQuery.value = ''
    adjustedY.value = props.y // Start with props.y, then adjust
    
    // Adjust position after menu renders to prevent overflow
    adjustMenuPosition()
  } else {
    // Reset adjusted position when menu closes
    adjustedY.value = null
  }
})
</script>

<template>
  <!-- Backdrop -->
  <div 
    v-if="show"
    class="fixed inset-0 z-[100]"
    @click="resetAllSubmenus(); emit('close')"
    @contextmenu.prevent="resetAllSubmenus(); emit('close')"
  ></div>
  
  <!-- Context Menu -->
  <Transition
    enter-active-class="transition-all duration-100"
    leave-active-class="transition-all duration-75"
    enter-from-class="opacity-0 scale-95"
    leave-to-class="opacity-0 scale-95"
  >
    <div 
      ref="menuRef"
      v-if="show && targetMessages.length > 0"
      :style="menuStyle"
      class="fixed z-[101] w-[272px] bg-white dark:bg-surface-800 rounded-xl shadow-xl border border-surface-200 dark:border-surface-700 py-1 flex flex-col"
    >
      <!-- Reply actions (only for single message) -->
      <template v-if="singleMessage">
        <button @click="replyAll" class="context-menu-item">
          <span class="material-symbols-rounded text-lg">reply_all</span>
          Reply to all
        </button>
        <button @click="reply" class="context-menu-item">
          <span class="material-symbols-rounded text-lg">reply</span>
          Reply
        </button>
        <div class="border-t border-surface-200 dark:border-surface-700 my-1"></div>
        
        <!-- Split conversation (for messages in a conversation OR individual messages inside expanded conversation) -->
        <button 
          v-if="isConversation || isMessageInsideConversation"
          @click="promptSplitConversation" 
          class="context-menu-item"
          :disabled="splitProcessing"
          title="This message and future replies will start a new conversation"
        >
          <span class="material-symbols-rounded text-lg">call_split</span>
          Split into a new conversation
        </button>
        
        <!-- Move to another conversation (for messages inside expanded conversation) -->
        <div 
          v-if="isMessageInsideConversation && availableConversations.length > 0" 
          class="relative"
        >
          <button 
            class="context-menu-item justify-between w-full"
            @mouseenter="openMoveToConvSubmenu"
            @mouseleave="closeMoveToConvSubmenuDelayed"
            @click.stop="showMoveToConvSubmenu = !showMoveToConvSubmenu"
            :disabled="processing"
            title="Attach this message to a different conversation"
          >
            <span class="flex items-center gap-3">
              <span class="material-symbols-rounded text-lg">merge</span>
              Move to another conversation
            </span>
            <span class="material-symbols-rounded text-sm">chevron_right</span>
          </button>
          
          <!-- Move to conversation submenu -->
          <div 
            v-if="showMoveToConvSubmenu"
            class="absolute left-full top-0 ml-1 min-w-[280px] max-w-[320px] bg-white dark:bg-surface-800 rounded-lg shadow-xl border border-surface-200 dark:border-surface-700 py-1 z-10 max-h-[300px] overflow-y-auto"
            @mouseenter="keepMoveToConvSubmenuOpen"
            @mouseleave="closeMoveToConvSubmenuDelayed"
          >
            <!-- Search -->
            <div class="px-3 py-2 border-b border-surface-200 dark:border-surface-700">
              <input 
                v-model="conversationSearch"
                type="text"
                placeholder="Search conversations..."
                class="w-full px-2 py-1 text-sm bg-surface-100 dark:bg-surface-700 rounded border-0 focus:ring-1 focus:ring-primary-500"
                @click.stop
              >
            </div>
            
            <!-- Conversations list -->
            <div v-if="filteredConversations.length > 0" class="max-h-[220px] overflow-y-auto">
              <button
                v-for="conv in filteredConversations"
                :key="conv.conversation_id"
                @click="moveToConversation(conv.conversation_id)"
                class="w-full px-3 py-2 text-left hover:bg-surface-100 dark:hover:bg-surface-700 flex flex-col gap-0.5"
              >
                <span class="text-sm truncate">{{ conv.subject || '(No subject)' }}</span>
                <span class="text-xs text-surface-500 flex items-center gap-2">
                  <span class="truncate">{{ conv.latest_from || 'Unknown' }}</span>
                  <span class="text-surface-400">{{ conv.message_count }} msg</span>
                </span>
              </button>
            </div>
            <div v-else class="px-3 py-2 text-sm text-surface-500 text-center">
              No other conversations found
            </div>
          </div>
        </div>
        
        <div v-if="isConversation || isMessageInsideConversation" class="border-t border-surface-200 dark:border-surface-700 my-1"></div>
      </template>
      
      <!-- Restore submenu (only in Trash) -->
      <div v-if="isInTrash" class="relative">
        <button 
          class="context-menu-item justify-between w-full text-green-600 dark:text-green-400" 
          @mouseenter="openRestoreSubmenu"
          @mouseleave="closeRestoreSubmenuDelayed"
          @click.stop="showRestoreSubmenu = !showRestoreSubmenu"
          :disabled="processing"
        >
          <span class="flex items-center gap-3">
            <span class="material-symbols-rounded text-lg">restore</span>
            Restore to
          </span>
          <span class="material-symbols-rounded text-sm">chevron_right</span>
        </button>
        
        <!-- Restore submenu -->
        <div 
          v-if="showRestoreSubmenu"
          class="absolute w-56 bg-white dark:bg-surface-800 rounded-xl shadow-2xl border border-surface-200 dark:border-surface-700 overflow-hidden z-[102]"
          :style="restoreSubmenuStyle"
          @click.stop
          @mouseenter="keepRestoreSubmenuOpen"
          @mouseleave="closeRestoreSubmenuDelayed"
        >
          <div class="px-4 py-2 border-b border-surface-200 dark:border-surface-700">
            <p class="text-sm font-medium text-surface-700 dark:text-surface-200">Restore to:</p>
          </div>
          <div class="max-h-64 overflow-y-auto py-1">
            <button
              v-for="folder in restoreFolders"
              :key="folder.name"
              @click="restoreToFolder(folder)"
              class="context-menu-item w-full text-left"
            >
              <span class="material-symbols-rounded text-lg">
                {{ folder.type === 'inbox' ? 'inbox' : folder.type === 'sent' ? 'send' : folder.type === 'drafts' ? 'draft' : folder.type === 'archive' ? 'archive' : 'folder' }}
              </span>
              {{ getFolderDisplayName(folder.name) }}
            </button>
          </div>
        </div>
      </div>
      
      <!-- Delete / Delete permanently -->
      <button 
        @click="deleteMessages" 
        :class="[
          'context-menu-item',
          isInTrash ? 'text-red-600 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-900/30' : ''
        ]"
        :disabled="processing"
      >
        <span class="material-symbols-rounded text-lg">{{ isInTrash ? 'delete_forever' : 'delete' }}</span>
        {{ isInTrash ? 'Delete permanently' : 'Delete' }}
      </button>
      
      <!-- Delete Conversation (only for conversations with multiple messages) -->
      <button 
        v-if="isConversation"
        @click="promptDeleteConversation" 
        class="context-menu-item text-red-600 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-900/30"
        :disabled="processing"
      >
        <span class="material-symbols-rounded text-lg">delete_sweep</span>
        Delete Conversation ({{ conversationCount }})
      </button>
      
      <!-- Mark as read (only show if unread) -->
      <button 
        v-if="targetMessages.some(m => !m.seen)"
        @click="markAsRead" 
        class="context-menu-item" 
        :disabled="processing"
      >
        <span class="material-symbols-rounded text-lg">mark_email_read</span>
        Mark as read
      </button>
      
      <!-- Pin/Unpin -->
      <button 
        @click="togglePin" 
        class="context-menu-item" 
        :disabled="processing"
      >
        <span class="material-symbols-rounded text-lg" :class="allPinned ? 'text-amber-500' : ''">push_pin</span>
        {{ allPinned ? 'Unpin' : 'Pin' }}
      </button>
      
      <div class="border-t border-surface-200 dark:border-surface-700 my-1"></div>
      
      <!-- Create filter -->
      <button @click="createFilter" class="context-menu-item">
        <span class="material-symbols-rounded text-lg">filter_alt</span>
        Filter emails like this
      </button>
      
      <!-- Add to existing filter -->
      <button 
        @click="openAddToFilterModal" 
        class="context-menu-item"
      >
        <span class="material-symbols-rounded text-lg">playlist_add</span>
        Add to existing filter
      </button>
      
      <!-- Email Mind Map -->
      <button 
        v-if="singleMessage && senderEmail"
        @click="openMindMap('client')" 
        class="context-menu-item"
      >
        <span class="material-symbols-rounded text-lg">hub</span>
        Email Mind Map
      </button>
      
      <div class="border-t border-surface-200 dark:border-surface-700 my-1"></div>
      
      <!-- Not Spam (only in spam folder) -->
      <button 
        v-if="isInSpam"
        @click="promptNotSpam" 
        class="context-menu-item text-green-600 dark:text-green-400 hover:bg-green-50 dark:hover:bg-green-900/30"
        :disabled="processing"
      >
        <span class="material-symbols-rounded text-lg">verified</span>
        Not spam
      </button>
      
      <!-- Block Sender (for single message) -->
      <button 
        v-if="singleMessage && getSenderEmail(singleMessage)"
        @click="promptBlockSender" 
        class="context-menu-item"
        :disabled="processing"
      >
        <span class="material-symbols-rounded text-lg">block</span>
        Block sender
      </button>
      
      <div class="border-t border-surface-200 dark:border-surface-700 my-1"></div>
      
      <!-- Move to submenu -->
      <div class="relative">
        <button 
          class="context-menu-item justify-between w-full" 
          @mouseenter="openMoveSubmenu"
          @mouseleave="closeMoveSubmenuDelayed"
          @click.stop="showMoveSubmenu = !showMoveSubmenu; showLabelSubmenu = false"
        >
          <span class="flex items-center gap-3">
            <span class="material-symbols-rounded text-lg">drive_file_move</span>
            Move to
          </span>
          <span class="material-symbols-rounded text-sm">chevron_right</span>
        </button>
        
        <!-- Move submenu - positioned to the right, smart vertical positioning -->
        <div 
          v-if="showMoveSubmenu"
          class="absolute w-56 bg-white dark:bg-surface-800 rounded-xl shadow-2xl border border-surface-200 dark:border-surface-700 overflow-hidden z-[102]"
          :style="moveSubmenuStyle"
          @click.stop
          @mouseenter="keepMoveSubmenuOpen"
          @mouseleave="closeMoveSubmenuDelayed"
        >
          <!-- Header -->
          <div class="px-4 py-2 border-b border-surface-200 dark:border-surface-700">
            <p class="text-sm font-medium text-surface-700 dark:text-surface-200">Move to:</p>
          </div>
          
          <!-- Search -->
          <div class="px-3 py-2 border-b border-surface-200 dark:border-surface-700">
            <div class="relative">
              <input
                id="ctx-folder-search"
                name="ctx-folder-search"
                v-model="folderSearch"
                type="text"
                class="w-full text-sm bg-transparent border-b border-surface-300 dark:border-surface-600 focus:border-primary-500 outline-none pb-1 pr-6"
                placeholder="Search folders..."
                autocomplete="off"
              />
              <span class="absolute right-0 top-0 material-symbols-rounded text-surface-400 text-lg">search</span>
            </div>
          </div>
          
          <!-- Folders list with hierarchy -->
          <div class="max-h-48 overflow-y-auto py-1">
            <div v-if="filteredFolders.length === 0" class="px-4 py-2 text-sm text-surface-500">
              No folders found
            </div>
            <button
              v-for="folder in filteredFolders"
              :key="folder.name"
              @click="moveToFolder(folder.name)"
              class="w-full flex items-center gap-2 py-2 text-sm hover:bg-surface-100 dark:hover:bg-surface-700 transition-colors"
              :style="{ paddingLeft: (getFolderDepth(folder.name) * 12 + 16) + 'px' }"
            >
              <span class="material-symbols-rounded text-lg text-surface-400">folder</span>
              <span class="flex-1 text-left truncate text-surface-700 dark:text-surface-200">{{ getFolderDisplayName(folder.name) }}</span>
            </button>
          </div>
          
          <!-- Create new folder -->
          <div class="border-t border-surface-200 dark:border-surface-700 py-1">
            <button
              v-if="!showCreateFolder"
              @click="showCreateFolder = true"
              class="w-full flex items-center gap-3 px-4 py-2 text-sm text-surface-600 dark:text-surface-300 hover:bg-surface-100 dark:hover:bg-surface-700 transition-colors"
            >
              <span class="material-symbols-rounded text-lg">create_new_folder</span>
              Create new folder
            </button>
            
            <!-- Create folder form -->
            <div v-else class="px-4 py-2 space-y-2">
              <!-- Parent folder selector -->
              <select
                id="ctx-folder-parent"
                name="ctx-folder-parent"
                v-model="newFolderParent"
                class="w-full text-sm border border-surface-300 dark:border-surface-600 rounded-lg px-3 py-1.5 bg-white dark:bg-surface-800 focus:border-primary-500 outline-none"
              >
                <option :value="null">Root (top level)</option>
                <option 
                  v-for="folder in moveFolders" 
                  :key="folder.name" 
                  :value="folder.name"
                >
                  {{ '  '.repeat(getFolderDepth(folder.name)) }}{{ getFolderDisplayName(folder.name) }}
                </option>
              </select>
              
              <input
                id="ctx-new-folder-name"
                name="ctx-new-folder-name"
                v-model="newFolderName"
                type="text"
                class="w-full text-sm border border-surface-300 dark:border-surface-600 rounded-lg px-3 py-1.5 bg-transparent focus:border-primary-500 outline-none"
                :placeholder="newFolderParent ? 'Subfolder name...' : 'Folder name...'"
                @keyup.enter="createAndMoveToFolder"
                autocomplete="off"
              />
              <div class="flex gap-2">
                <button
                  @click="createAndMoveToFolder"
                  :disabled="!newFolderName.trim() || creatingFolder"
                  class="flex-1 text-xs bg-primary-500 text-white px-3 py-1 rounded-lg hover:bg-primary-600 disabled:opacity-50"
                >
                  {{ creatingFolder ? 'Creating...' : 'Create & Move' }}
                </button>
                <button
                  @click="showCreateFolder = false; newFolderName = ''; newFolderParent = null"
                  class="text-xs text-surface-500 hover:text-surface-700 dark:hover:text-surface-300 px-2"
                >
                  Cancel
                </button>
              </div>
            </div>
          </div>
        </div>
      </div>
      
      <!-- Label as submenu (only for single message) -->
      <div v-if="singleMessage" class="relative">
        <button 
          class="context-menu-item justify-between w-full" 
          @mouseenter="openLabelSubmenu"
          @mouseleave="closeLabelSubmenuDelayed"
          @click.stop="showLabelSubmenu = !showLabelSubmenu; showMoveSubmenu = false"
        >
          <span class="flex items-center gap-3">
            <span class="material-symbols-rounded text-lg">label</span>
            Label as
          </span>
          <span class="material-symbols-rounded text-sm">chevron_right</span>
        </button>
        
        <!-- Label submenu - positioned to the right, smart vertical positioning -->
        <div 
          v-if="showLabelSubmenu"
          class="absolute w-64 bg-white dark:bg-surface-800 rounded-xl shadow-2xl border border-surface-200 dark:border-surface-700 overflow-hidden z-[102]"
          :style="labelSubmenuStyle"
          @click.stop
          @mouseenter="keepLabelSubmenuOpen"
          @mouseleave="closeLabelSubmenuDelayed"
        >
          <!-- Header -->
          <div class="px-4 py-2 border-b border-surface-200 dark:border-surface-700">
            <p class="text-sm font-medium text-surface-700 dark:text-surface-200">Label as:</p>
          </div>
          
          <!-- Search -->
          <div class="px-3 py-2 border-b border-surface-200 dark:border-surface-700">
            <div class="relative">
              <input
                id="ctx-label-search"
                name="ctx-label-search"
                v-model="labelSearch"
                type="text"
                class="w-full text-sm bg-transparent border-b border-surface-300 dark:border-surface-600 focus:border-primary-500 outline-none pb-1 pr-6"
                placeholder="Search labels..."
                autocomplete="off"
              />
              <span class="absolute right-0 top-0 material-symbols-rounded text-surface-400 text-lg">search</span>
            </div>
          </div>
          
          <!-- Labels list -->
          <div class="max-h-48 overflow-y-auto py-1">
            <div v-if="filteredLabels.length === 0" class="px-4 py-2 text-sm text-surface-500">
              No labels found
            </div>
            
            <div
              v-for="label in filteredLabels"
              :key="label.id"
              class="group"
            >
              <!-- Edit mode -->
              <div v-if="editingLabelId === label.id" class="p-2 bg-surface-50 dark:bg-surface-700 mx-1 rounded-lg">
                <input
                  v-model="editLabelName"
                  type="text"
                  class="w-full text-sm border border-surface-300 dark:border-surface-600 rounded-lg px-3 py-1.5 bg-transparent focus:border-primary-500 outline-none mb-2"
                  placeholder="Label name..."
                  @keyup.enter="saveEditLabel"
                  @keyup.escape="cancelEditLabel"
                  autofocus
                />
                <div class="flex flex-wrap gap-1 mb-2">
                  <button
                    v-for="color in colorOptions"
                    :key="color.name"
                    @click="editLabelColor = color.hex"
                    class="w-5 h-5 rounded-full transition-transform hover:scale-110"
                    :class="{ 'ring-2 ring-offset-1 ring-surface-900 dark:ring-white': editLabelColor === color.hex }"
                    :style="{ backgroundColor: color.hex }"
                    :title="color.name"
                  ></button>
                </div>
                <div class="flex gap-2">
                  <button @click="saveEditLabel" class="flex-1 text-xs bg-primary-500 text-white px-3 py-1 rounded-lg hover:bg-primary-600">Save</button>
                  <button @click="cancelEditLabel" class="text-xs text-surface-500 hover:text-surface-700 dark:hover:text-surface-300 px-2">Cancel</button>
                </div>
              </div>
              
              <!-- Normal display -->
              <div
                v-else
                class="relative flex items-center hover:bg-surface-100 dark:hover:bg-surface-700 transition-colors"
              >
                <button
                  @click="toggleLabel(label)"
                  class="flex-1 flex items-center gap-3 px-4 py-2 text-sm"
                >
                  <span class="material-symbols-rounded text-lg text-surface-400">
                    {{ hasLabel(label.id) ? 'check_box' : 'check_box_outline_blank' }}
                  </span>
                  <span 
                    class="w-3 h-3 rounded-full shrink-0"
                    :style="{ backgroundColor: label.color }"
                  ></span>
                  <span class="flex-1 text-left truncate text-surface-700 dark:text-surface-200">{{ label.name }}</span>
                </button>
                
                <!-- More actions button -->
                <button
                  @click="openLabelContextMenu($event, label)"
                  class="opacity-0 group-hover:opacity-100 mr-2 w-6 h-6 flex items-center justify-center rounded hover:bg-surface-200 dark:hover:bg-surface-600 transition-all"
                >
                  <span class="material-symbols-rounded text-base text-surface-500">more_vert</span>
                </button>
              </div>
            </div>
          </div>
          
          <!-- Create new / Manage -->
          <div class="border-t border-surface-200 dark:border-surface-700 py-1">
            <button
              v-if="!showCreateLabel"
              @click="showCreateLabel = true"
              class="w-full flex items-center gap-3 px-4 py-2 text-sm text-surface-600 dark:text-surface-300 hover:bg-surface-100 dark:hover:bg-surface-700 transition-colors"
            >
              <span class="material-symbols-rounded text-lg">add</span>
              Create new
            </button>
            
            <!-- Create label form -->
            <div v-else class="px-4 py-2 space-y-2">
              <input
                id="ctx-new-label-name"
                name="ctx-new-label-name"
                v-model="newLabelName"
                type="text"
                class="w-full text-sm border border-surface-300 dark:border-surface-600 rounded-lg px-3 py-1.5 bg-transparent focus:border-primary-500 outline-none"
                placeholder="Label name..."
                @keyup.enter="createAndApplyLabel"
                autocomplete="off"
              />
              <div class="flex flex-wrap gap-1">
                <button
                  v-for="color in colorOptions"
                  :key="color.name"
                  @click="newLabelColor = color.hex"
                  class="w-5 h-5 rounded-full transition-transform hover:scale-110"
                  :class="{ 'ring-2 ring-offset-1 ring-surface-900 dark:ring-white': newLabelColor === color.hex }"
                  :style="{ backgroundColor: color.hex }"
                  :title="color.name"
                ></button>
              </div>
              <div class="flex gap-2">
                <button
                  @click="createAndApplyLabel"
                  :disabled="!newLabelName.trim() || creatingLabel"
                  class="flex-1 text-xs bg-primary-500 text-white px-3 py-1 rounded-lg hover:bg-primary-600 disabled:opacity-50"
                >
                  Create
                </button>
                <button
                  @click="showCreateLabel = false; newLabelName = ''"
                  class="text-xs text-surface-500 hover:text-surface-700 dark:hover:text-surface-300 px-2"
                >
                  Cancel
                </button>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </Transition>
  
  <!-- Filter Modal -->
  <FilterModal 
    :show="showFilterModal" 
    :initial-data="filterInitialData"
    :editing-filter="editingExistingFilter"
    @close="showFilterModal = false; editingExistingFilter = null; filterInitialData = null"
    @saved="showFilterModal = false; editingExistingFilter = null; filterInitialData = null"
  />
  
  <!-- Add to Existing Filter Selection Modal -->
  <Teleport to="body">
    <Transition name="fade">
      <div 
        v-if="showAddToFilterModal" 
        class="fixed inset-0 z-[110] flex items-center justify-center p-4 bg-black/50"
        @click.self="showAddToFilterModal = false; filterSearchQuery = ''"
      >
        <div class="w-full max-w-md bg-white dark:bg-surface-800 rounded-2xl shadow-2xl overflow-hidden">
          <div class="px-6 py-4 border-b border-surface-200 dark:border-surface-700 flex items-center justify-between">
            <h3 class="text-lg font-semibold text-surface-900 dark:text-surface-100">Add to Existing Filter</h3>
            <button @click="showAddToFilterModal = false; filterSearchQuery = ''" class="btn-ghost btn-icon btn-sm">
              <span class="material-symbols-rounded">close</span>
            </button>
          </div>
          
          <div class="p-4">
            <!-- Search input -->
            <div class="relative mb-4">
              <span class="absolute left-3 top-1/2 -translate-y-1/2 material-symbols-rounded text-surface-400">search</span>
              <input
                v-model="filterSearchQuery"
                type="text"
                class="w-full pl-10 pr-4 py-2 text-sm border border-surface-200 dark:border-surface-700 rounded-xl bg-surface-50 dark:bg-surface-900 focus:border-primary-500 focus:ring-1 focus:ring-primary-500 outline-none"
                placeholder="Search filters..."
                autocomplete="off"
              />
            </div>
            
            <!-- Loading state -->
            <div v-if="filtersStore.loading" class="flex items-center justify-center py-8">
              <span class="spinner text-primary-500"></span>
            </div>
            
            <div v-else class="max-h-72 overflow-y-auto space-y-2">
              <button
                v-for="filter in filteredFiltersForModal"
                :key="filter.id"
                @click="addToExistingFilter(filter)"
                class="w-full p-3 rounded-xl border border-surface-200 dark:border-surface-700 hover:border-primary-300 dark:hover:border-primary-700 hover:bg-surface-50 dark:hover:bg-surface-700 transition-all text-left"
              >
                <div class="flex items-center gap-3">
                  <span class="material-symbols-rounded text-lg text-primary-500">filter_alt</span>
                  <div class="flex-1 min-w-0">
                    <p class="font-medium text-surface-900 dark:text-surface-100 truncate">{{ filter.name }}</p>
                    <p class="text-xs text-surface-500 mt-0.5">
                      {{ filter.conditions?.groups?.[0]?.rules?.length || filter.conditions?.rules?.length || 0 }} condition(s)
                      <span v-if="!filter.enabled" class="text-amber-500 ml-2">(disabled)</span>
                    </p>
                  </div>
                  <span class="material-symbols-rounded text-surface-400">chevron_right</span>
                </div>
              </button>
              
              <!-- No results for search -->
              <div v-if="filterSearchQuery && filteredFiltersForModal.length === 0" class="text-center py-6 text-surface-500">
                <span class="material-symbols-rounded text-3xl mb-2 block">search_off</span>
                <p>No filters match "{{ filterSearchQuery }}"</p>
              </div>
              
              <!-- No filters at all -->
              <div v-else-if="filtersStore.filters.length === 0" class="text-center py-8 text-surface-500">
                <span class="material-symbols-rounded text-4xl mb-2 block">filter_alt_off</span>
                <p>No filters created yet</p>
                <p class="text-xs mt-1">Create a new filter below</p>
              </div>
            </div>
          </div>
          
          <div class="px-6 py-4 border-t border-surface-200 dark:border-surface-700 flex justify-between">
            <button @click="showAddToFilterModal = false; filterSearchQuery = ''; createFilter()" class="btn-ghost text-primary-500">
              <span class="material-symbols-rounded">add</span>
              Create new filter instead
            </button>
            <button @click="showAddToFilterModal = false; filterSearchQuery = ''" class="btn-ghost">Cancel</button>
          </div>
        </div>
      </div>
    </Transition>
  </Teleport>
  
  <!-- Delete Label Confirmation -->
  <ConfirmModal
    :show="showDeleteLabelConfirm"
    title="Delete Label"
    :message="`Are you sure you want to delete the label '${labelToDelete?.name}'? This will remove it from all messages.`"
    confirmText="Delete"
    type="danger"
    @confirm="executeDeleteLabel"
    @cancel="showDeleteLabelConfirm = false; labelToDelete = null"
  />
  
  <!-- Teleported confirmation modals (so they persist when context menu closes) -->
  <Teleport to="body">
    <!-- Permanent Delete Confirmation -->
    <ConfirmModal
      :show="showPermanentDeleteConfirm"
      title="Delete Permanently"
      :message="`Are you sure you want to permanently delete ${pendingDeleteCount} message(s)? This action cannot be undone.`"
      confirmText="Delete Forever"
      type="danger"
      @confirm="confirmPermanentDelete"
      @cancel="cancelPermanentDelete"
    />
    
    <!-- Delete Conversation Confirmation -->
    <ConfirmModal
      :show="showDeleteConversationConfirm"
      title="Delete Conversation"
      :message="`Are you sure you want to delete this entire conversation? This will delete all ${pendingConversationCount} messages in the conversation.`"
      confirmText="Delete Conversation"
      type="danger"
      @confirm="confirmDeleteConversation"
      @cancel="cancelDeleteConversation"
    />
    
    <!-- Report Spam Confirmation -->
    <ConfirmModal
      :show="showSpamConfirm"
      title="Report as Spam"
      :message="`Report ${pendingSpamUids.length} message(s) as spam?`"
      :confirmText="spamProcessing ? 'Reporting...' : 'Report Spam'"
      type="warning"
      :loading="spamProcessing"
      @confirm="confirmReportSpam"
      @cancel="cancelReportSpam"
    >
      <template #default>
        <div class="mt-3 space-y-3">
          <p class="text-sm text-surface-500 dark:text-surface-400">
            This will move the email(s) to spam and help train the spam filter.
          </p>
          <label v-if="pendingSpamMessage" class="flex items-center gap-2 cursor-pointer">
            <input 
              type="checkbox" 
              v-model="blockSenderToo"
              class="w-4 h-4 rounded border-surface-300 text-primary-600 focus:ring-primary-500"
            />
            <span class="text-sm text-surface-700 dark:text-surface-300">Also block this sender</span>
          </label>
        </div>
      </template>
    </ConfirmModal>
    
    <!-- Not Spam Confirmation -->
    <ConfirmModal
      :show="showNotSpamConfirm"
      title="Not Spam"
      :message="`Move ${pendingSpamUids.length} message(s) to inbox?`"
      :confirmText="spamProcessing ? 'Moving...' : 'Not Spam'"
      type="info"
      :loading="spamProcessing"
      @confirm="confirmNotSpam"
      @cancel="cancelNotSpam"
    >
      <template #default>
        <div class="mt-3 space-y-3">
          <p class="text-sm text-surface-500 dark:text-surface-400">
            This will move the email(s) to your inbox and help train the spam filter.
          </p>
          <label v-if="pendingSpamMessage" class="flex items-center gap-2 cursor-pointer">
            <input 
              type="checkbox" 
              v-model="addToSafeToo"
              class="w-4 h-4 rounded border-surface-300 text-primary-600 focus:ring-primary-500"
            />
            <span class="text-sm text-surface-700 dark:text-surface-300">Add sender to trusted list</span>
          </label>
        </div>
      </template>
    </ConfirmModal>
    
    <!-- Block Sender Confirmation -->
    <ConfirmModal
      :show="showBlockSenderConfirm"
      title="Block Sender"
      :message="pendingSpamMessage ? `Block all future emails from ${getSenderEmail(pendingSpamMessage)}?` : 'Block this sender?'"
      :confirmText="spamProcessing ? 'Blocking...' : 'Block Sender'"
      type="danger"
      :loading="spamProcessing"
      @confirm="confirmBlockSender"
      @cancel="cancelBlockSender"
    >
      <template #default>
        <p class="text-sm text-surface-500 dark:text-surface-400 mt-2">
          Future emails from this sender will be automatically moved to spam.
        </p>
      </template>
    </ConfirmModal>
    
    <!-- Split Conversation Confirmation -->
    <ConfirmModal
      :show="showSplitConfirm"
      title="Split conversation?"
      message="This message will start a new conversation. Future replies will stay in the new conversation."
      confirmText="Split"
      type="info"
      :loading="splitProcessing"
      @confirm="executeSplit"
      @cancel="cancelSplit"
    >
      <template #default>
        <label class="flex items-center gap-2 mt-4 cursor-pointer">
          <input 
            type="checkbox" 
            v-model="dontAskSplitAgain"
            class="w-4 h-4 rounded border-surface-300 text-primary-600 focus:ring-primary-500"
          />
          <span class="text-sm text-surface-600 dark:text-surface-300">Don't ask again</span>
        </label>
      </template>
    </ConfirmModal>
  </Teleport>
  
  <!-- Teleported mini context menu for label actions -->
  <Teleport to="body">
    <div
      v-if="labelContextMenu && labelContextMenuData"
      class="fixed z-[200] bg-white dark:bg-surface-800 rounded-lg shadow-xl border border-surface-200 dark:border-surface-700 py-1 min-w-[130px]"
      :style="{ left: labelContextMenuPos.x + 'px', top: labelContextMenuPos.y + 'px' }"
      @mouseenter="keepLabelSubmenuOpen"
      @click.stop
    >
      <button
        @click.stop="startEditLabel(labelContextMenuData)"
        class="w-full flex items-center gap-2 px-3 py-2 text-sm text-surface-700 dark:text-surface-200 hover:bg-surface-100 dark:hover:bg-surface-700"
      >
        <span class="material-symbols-rounded text-base">edit</span>
        Rename
      </button>
      <button
        @click.stop="confirmDeleteLabel(labelContextMenuData)"
        class="w-full flex items-center gap-2 px-3 py-1.5 text-sm text-red-500 hover:bg-red-50 dark:hover:bg-red-500/10"
      >
        <span class="material-symbols-rounded text-base">delete</span>
        Delete
      </button>
    </div>
    
    <!-- Backdrop for label context menu -->
    <div
      v-if="labelContextMenu"
      class="fixed inset-0 z-[199]"
      @click="closeLabelContextMenu"
    ></div>
  </Teleport>
</template>

<style scoped>
.context-menu-item {
  @apply w-full flex items-center gap-3 px-4 py-2 text-sm text-surface-700 dark:text-surface-200 hover:bg-surface-100 dark:hover:bg-surface-700 transition-colors disabled:opacity-50;
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
</style>

