<script setup>
import { ref, computed, onMounted, onUnmounted, watch, nextTick } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import { useToastStore } from '@/stores/toast'
import { useMailboxStore } from '@/stores/mailbox'
import { useLayoutStore } from '@/stores/layout'
import { useAuthStore } from '@/stores/auth'
import { useAccountsStore } from '@/stores/accounts'
import AllMailDegradedBanner from '@/components/AllMailDegradedBanner.vue'
import EmailSearchBar from '@/components/EmailSearchBar.vue'
import { useAIStore } from '@/addons/ai-assistant/stores/ai'
import { useBoardsStore } from '@/addons/kanban-boards/stores/boards'
import { useConversationsStore } from '@/stores/conversations'
import { useCalendarStore } from '@/addons/calendar/stores/calendar'
import { useComposeStore } from '@/stores/compose'
import EmailContextMenu from './EmailContextMenu.vue'
import ReactionDisplay from '@/addons/reactions/components/ReactionDisplay.vue'
import ConfirmModal from './shared/ConfirmModal.vue'
import RefreshIndicator from './RefreshIndicator.vue'

const router = useRouter()
const route = useRoute()
const mailbox = useMailboxStore()
const layout = useLayoutStore()
const auth = useAuthStore()
const accountsStore = useAccountsStore()
const toast = useToastStore()
const aiStore = useAIStore()
const boardsStore = useBoardsStore()
const conversationsStore = useConversationsStore()
const calendarStore = useCalendarStore()
const compose = useComposeStore()

import { isDebugEnabled } from '@/utils/debug'
import { usePullToRefresh } from '@/composables/usePullToRefresh'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

// Check if a message has cached AI summary
function hasCachedSummary(item) {
  if (!aiStore.isConfigured) return false
  return aiStore.hasCachedSummary(mailbox.currentFolder, item.uid, item.message_id)
}

// Get all our email addresses (primary + linked accounts)
const ourEmailAddresses = computed(() => {
  const addresses = new Set()
  // Primary email
  if (auth.userEmail) {
    addresses.add(auth.userEmail.toLowerCase())
  }
  // Additional accounts
  accountsStore.accounts.forEach(acc => {
    if (acc.account_email) {
      addresses.add(acc.account_email.toLowerCase())
    }
  })
  return addresses
})

// Check if a message is sent by us
function isOurMessage(msg) {
  if (!msg?.from_email) return false
  return ourEmailAddresses.value.has(msg.from_email.toLowerCase())
}

// Defer reactions loading to avoid circular dependencies
const reactionsLoaded = ref(false)

// Scroll position tracking for instant folder switching
const listContainerRef = ref(null)
let scrollSaveTimeout = null

// Pull-to-refresh (mobile only)
const isMobileRef = computed(() => layout.isMobile)
const { pullDistance, refreshing: pullRefreshing, pulling } = usePullToRefresh(
  listContainerRef,
  () => forceRefresh(),
  { enabled: isMobileRef }
)

async function loadReactionsForMessages() {
  if (mailbox.messages.length === 0) return
  
  const messageIds = mailbox.messages.map(m => m.message_id).filter(id => id)
  if (messageIds.length === 0) return
  
  try {
    // Dynamic import to avoid circular dependency
    const { useReactionsStore } = await import('@/addons/reactions/stores/reactions')
    const reactionsStore = useReactionsStore()
    await reactionsStore.fetchReactionsBatch(messageIds)
    reactionsLoaded.value = true
  } catch (e) {
    console.error('Failed to fetch reactions batch:', e)
  }
}

// Load reactions after messages are fetched
watch(() => mailbox.messages, () => {
  nextTick(() => {
    loadReactionsForMessages()
    loadBoardLinksForMessages()
  })
}, { immediate: false })

// ========================================
// SCROLL POSITION PRESERVATION
// ========================================

// Save scroll position when scrolling (debounced)
function handleListScroll(e) {
  if (!mailbox.currentFolder) return
  
  // Debounce scroll saves
  if (scrollSaveTimeout) clearTimeout(scrollSaveTimeout)
  scrollSaveTimeout = setTimeout(() => {
    mailbox.setFolderScrollPosition(mailbox.currentFolder, e.target.scrollTop)
  }, 100)
}

// Restore scroll position when folder changes
let previousFolder = null
watch(() => mailbox.currentFolder, (newFolder, oldFolder) => {
  if (!newFolder) return
  
  // Save scroll position for old folder
  if (oldFolder && listContainerRef.value) {
    mailbox.setFolderScrollPosition(oldFolder, listContainerRef.value.scrollTop)
  }
  
  // Restore scroll position for new folder (with delay for DOM update)
  nextTick(() => {
    if (listContainerRef.value) {
      const savedPosition = mailbox.getFolderScrollPosition(newFolder)
      if (savedPosition > 0) {
        listContainerRef.value.scrollTop = savedPosition
      }
    }
  })
  
  previousFolder = newFolder
}, { immediate: false })

// Also restore scroll on initial mount if coming back to a cached folder
onMounted(() => {
  nextTick(() => {
    if (listContainerRef.value && mailbox.currentFolder) {
      const savedPosition = mailbox.getFolderScrollPosition(mailbox.currentFolder)
      if (savedPosition > 0) {
        listContainerRef.value.scrollTop = savedPosition
      }
    }
  })
})

// Fetch board links for visible messages
async function loadBoardLinksForMessages() {
  isDebugEnabled() && console.log('[EmailList] loadBoardLinksForMessages called, messages:', mailbox.messages.length, 'folder:', mailbox.currentFolder)
  if (mailbox.messages.length === 0 || !mailbox.currentFolder) return
  
  const emails = mailbox.messages
    .filter(m => m.uid)
    .map(m => ({ uid: m.uid, folder: mailbox.currentFolder }))
  
  isDebugEnabled() && console.log('[EmailList] Fetching board links for', emails.length, 'emails')
  if (emails.length > 0) {
    await boardsStore.fetchEmailBoardsBatch(emails, mailbox.currentFolder)
    isDebugEnabled() && console.log('[EmailList] Board links fetched, checking cache...')
    // Log what we got in cache
    emails.slice(0, 5).forEach(e => {
      const cached = boardsStore.getCachedEmailBoard(e.uid, mailbox.currentFolder)
      isDebugEnabled() && console.log('[EmailList] Email UID', e.uid, 'board:', cached)
    })
  }
}

// Get cached board link for an email (for display)
function getEmailBoard(item) {
  if (!item?.uid || !mailbox.currentFolder) return null
  return boardsStore.getCachedEmailBoard(item.uid, mailbox.currentFolder)
}

// Navigate to linked board
function goToBoard(e, item) {
  e.stopPropagation()
  const board = getEmailBoard(item)
  if (board?.board_id) {
    router.push(`/boards/${board.board_id}`)
  }
}

// Board link popup on hover
const boardPopup = ref({
  show: false,
  board: null,
  x: 0,
  y: 0
})
let boardPopupTimeout = null

function showBoardPopup(e, item) {
  const board = getEmailBoard(item)
  if (!board) return
  
  // Clear any pending hide timeout
  if (boardPopupTimeout) {
    clearTimeout(boardPopupTimeout)
    boardPopupTimeout = null
  }
  
  const rect = e.currentTarget.getBoundingClientRect()
  boardPopup.value = {
    show: true,
    board: board,
    x: rect.left + rect.width / 2,
    y: rect.top - 8
  }
}

function hideBoardPopup() {
  // Small delay to allow hovering over the popup itself
  boardPopupTimeout = setTimeout(() => {
    boardPopup.value.show = false
  }, 150)
}

function keepBoardPopupOpen() {
  if (boardPopupTimeout) {
    clearTimeout(boardPopupTimeout)
    boardPopupTimeout = null
  }
}

function closeBoardPopup() {
  boardPopup.value.show = false
}

function handleBoardPopupClick(e) {
  e.stopPropagation()
  if (boardPopup.value.board?.board_id) {
    router.push(`/boards/${boardPopup.value.board.board_id}`)
    closeBoardPopup()
  }
}

// Select all dropdown
const showSelectMenu = ref(false)
const selectMenuRef = ref(null)

// Context menu
const showContextMenu = ref(false)
const contextMenuX = ref(0)
const contextMenuY = ref(0)
const contextMenuMessage = ref(null)

function handleContextMenu(e, message, conversationInfo = null) {
  e.preventDefault()
  
  // For messages inside expanded conversations, they may not be in mailbox.messages
  // So we don't try to select them - we just pass them directly to the context menu
  const isFromExpandedConversation = !!conversationInfo
  
  if (!isFromExpandedConversation) {
    // Standard message from main list - handle selection
    const folder = message.folder || mailbox.currentFolder
    if (!mailbox.isMessageSelected(message.uid, folder)) {
      mailbox.selectNone()
      mailbox.selectMessage(message.uid, folder)
    }
  } else {
    // For expanded conversation messages, clear selection to avoid conflicts
    // The context menu will use props.message directly
    mailbox.selectNone()
  }
  
  // If this message is from an expanded conversation, add conversation info
  // so the context menu knows it can be split
  if (conversationInfo) {
    contextMenuMessage.value = {
      ...message,
      _parentConversation: conversationInfo
    }
  } else {
    contextMenuMessage.value = message
  }
  
  contextMenuX.value = e.clientX
  contextMenuY.value = e.clientY
  showContextMenu.value = true
}

function closeContextMenu() {
  showContextMenu.value = false
  contextMenuMessage.value = null
}

// Use shared unsubscribe composable for state sync across components
import { useUnsubscribe } from '@/composables/useUnsubscribe'
const {
  showUnsubscribeConfirm,
  showUnsubscribeUrlConfirm,
  unsubscribingMessage,
  unsubscribing,
  hasUnsubscribe,
  isUnsubscribed,
  getSenderDisplay,
  initiateUnsubscribe: baseInitiateUnsubscribe,
  cancelUnsubscribe,
  executeUnsubscribe,
  confirmUrlUnsubscribe,
  cancelUrlUnsubscribe,
} = useUnsubscribe()

// Wrapper to stop event propagation in list
function initiateUnsubscribe(e, msg) {
  e.stopPropagation()
  baseInitiateUnsubscribe(msg)
}

// Close dropdown when clicking outside
function handleClickOutside(e) {
  if (showSelectMenu.value && !e.target.closest('.select-menu-container')) {
    showSelectMenu.value = false
  }
}

onMounted(() => {
  document.addEventListener('click', handleClickOutside)
})

onUnmounted(() => {
  document.removeEventListener('click', handleClickOutside)
})

// Group messages by time period (Today, Yesterday, This Week, Last Week, This Month, by month)
const isSearchResults = computed(() => mailbox.currentFolder === 'SEARCH_RESULTS')
const isScheduledView = computed(() => mailbox.currentFolder === 'SCHEDULED')

// Check if viewing All Mail (already defined in the component, but adding computed for clarity)
// Note: isAllMailView is already defined elsewhere in this file

// Collapsed groups state
const collapsedGroups = ref(new Set())

function toggleGroupCollapse(groupKey) {
  if (collapsedGroups.value.has(groupKey)) {
    collapsedGroups.value.delete(groupKey)
  } else {
    collapsedGroups.value.add(groupKey)
  }
}

function isGroupCollapsed(groupKey) {
  return collapsedGroups.value.has(groupKey)
}

// Get time period key for an email
function getTimePeriodKey(timestamp) {
  if (!timestamp) return 'unknown'
  
  const date = new Date(timestamp * 1000)
  const now = new Date()
  const today = new Date(now.getFullYear(), now.getMonth(), now.getDate())
  const yesterday = new Date(today.getTime() - 86400000)
  const thisWeekStart = new Date(today.getTime() - (today.getDay() * 86400000))
  const lastWeekStart = new Date(thisWeekStart.getTime() - 7 * 86400000)
  const thisMonthStart = new Date(now.getFullYear(), now.getMonth(), 1)
  
  if (date >= today) {
    return 'today'
  } else if (date >= yesterday) {
    return 'yesterday'
  } else if (date >= thisWeekStart) {
    return 'this-week'
  } else if (date >= lastWeekStart) {
    return 'last-week'
  } else if (date >= thisMonthStart) {
    return 'this-month'
  } else {
    // Group by month
    return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}`
  }
}

const groupedConversations = computed(() => {
  if (!mailbox.conversations.length) {
    return []
  }
  
  // First, separate pinned emails from the rest
  const pinnedItems = []
  const unpinnedItems = []
  
  for (const item of mailbox.conversations) {
    // Use item's actual folder for virtual views (ALL_MAIL, SEARCH_RESULTS)
    if (mailbox.isEmailPinned(item.uid, item.folder || mailbox.currentFolder)) {
      pinnedItems.push(item)
    } else {
      unpinnedItems.push(item)
    }
  }
  
  const groupMap = new Map()
  
  // Check if we're in All Mail with folder grouping mode
  const usesFolderGrouping = isAllMailView.value && mailbox.allMailGroupMode === 'folder'
  
  if (usesFolderGrouping) {
    // Group by folder
    for (const item of unpinnedItems) {
      const folderKey = item.folder || 'unknown-folder'
      if (!groupMap.has(folderKey)) {
        groupMap.set(folderKey, [])
      }
      groupMap.get(folderKey).push(item)
    }
    
    // Sort groups by folder name
    const groups = []
    
    // Add pinned group first if there are any pinned emails
    if (pinnedItems.length > 0) {
      groups.push({ key: 'pinned', items: pinnedItems })
    }
    
    // Sort folders: INBOX first, then alphabetically
    const folderKeys = Array.from(groupMap.keys()).sort((a, b) => {
      if (a === 'INBOX') return -1
      if (b === 'INBOX') return 1
      return a.localeCompare(b)
    })
    
    for (const key of folderKeys) {
      groups.push({ key: `folder:${key}`, items: groupMap.get(key) })
    }
    
    return groups
  }
  
  // Default: Group by time period
  const groupOrder = ['today', 'yesterday', 'this-week', 'last-week', 'this-month']
  
  for (const item of unpinnedItems) {
    const key = getTimePeriodKey(item.timestamp)
    if (!groupMap.has(key)) {
      groupMap.set(key, [])
    }
    groupMap.get(key).push(item)
  }
  
  // Sort groups: predefined order first, then months in descending order
  const groups = []
  
  // Add pinned group first if there are any pinned emails
  if (pinnedItems.length > 0) {
    groups.push({ key: 'pinned', items: pinnedItems })
  }
  
  // Add predefined groups in order
  for (const key of groupOrder) {
    if (groupMap.has(key)) {
      groups.push({ key, items: groupMap.get(key) })
      groupMap.delete(key)
    }
  }
  
  // Add remaining month groups sorted descending
  const monthKeys = Array.from(groupMap.keys())
    .filter(k => k !== 'unknown')
    .sort((a, b) => b.localeCompare(a))
  
  for (const key of monthKeys) {
    groups.push({ key, items: groupMap.get(key) })
  }
  
  // Add unknown at the end
  if (groupMap.has('unknown')) {
    groups.push({ key: 'unknown', items: groupMap.get('unknown') })
  }
  
  return groups
})

function formatGroupHeader(groupKey) {
  // Handle folder grouping keys (e.g., 'folder:INBOX.Sent')
  if (groupKey.startsWith('folder:')) {
    const folderName = groupKey.slice(7) // Remove 'folder:' prefix
    return getMessageFolderName({ folder: folderName })
  }
  
  switch (groupKey) {
    case 'pinned': return 'Pinned'
    case 'today': return 'Today'
    case 'yesterday': return 'Yesterday'
    case 'this-week': return 'This Week'
    case 'last-week': return 'Last Week'
    case 'this-month': return 'This Month'
    case 'unknown': return 'Unknown Date'
    default:
      // Month format: YYYY-MM
      const [year, month] = groupKey.split('-')
      const date = new Date(parseInt(year), parseInt(month) - 1, 1)
      const now = new Date()
      const monthName = date.toLocaleDateString('en-US', { month: 'long' })
      return date.getFullYear() === now.getFullYear() ? monthName : `${monthName} ${year}`
  }
}

const allSelected = computed(() => {
  return mailbox.messages.length > 0 && mailbox.selectedMessages.length === mailbox.messages.length
})

const someSelected = computed(() => {
  return mailbox.selectedMessages.length > 0 && mailbox.selectedMessages.length < mailbox.messages.length
})

function toggleSelectAll() {
  if (allSelected.value || someSelected.value) {
    mailbox.selectNone()
  } else {
    mailbox.selectAllMessages()
  }
}

function selectOption(option) {
  showSelectMenu.value = false
  switch (option) {
    case 'all':
      mailbox.selectAllMessages()
      break
    case 'none':
      mailbox.selectNone()
      break
    case 'read':
      mailbox.selectRead()
      break
    case 'unread':
      mailbox.selectUnread()
      break
    case 'starred':
      mailbox.selectStarred()
      break
    case 'unstarred':
      mailbox.selectUnstarred()
      break
  }
}

// Unified selection state - tracks by UID+folder for consistency across folders
const selectionState = ref({
  lastClickedUid: null,
  lastClickedFolder: null, // Track folder for proper composite key matching
  lastClickedConversationKey: null, // Which conversation the last click was in (null = main list)
})

// Get all visible items in display order (includes expanded thread messages)
function getVisibleItemsInOrder() {
  const items = []
  
  mailbox.conversations.forEach(conv => {
    const convFolder = conv.folder || mailbox.currentFolder
    // Add the conversation header/message
    items.push({
      uid: conv.uid,
      folder: convFolder,
      type: 'conversation',
      conversationKey: conv.conversationKey,
      isExpanded: mailbox.expandedConversations.has(conv.conversationKey),
    })
    
    // If conversation is expanded, add its thread messages
    if (conv.isConversation && mailbox.expandedConversations.has(conv.conversationKey)) {
      const threadMsgs = mailbox.getConversationMessages(conv.conversationKey)
      threadMsgs.forEach(msg => {
        items.push({
          uid: msg.uid,
          folder: msg.folder || mailbox.currentFolder,
          type: 'thread-message',
          conversationKey: conv.conversationKey,
          message: msg,
        })
      })
    }
  })
  
  return items
}

// Select a range of items between two UIDs in visible order
function selectRange(fromUid, fromFolder, toUid, toFolder, addToSelection = true) {
  const visibleItems = getVisibleItemsInOrder()
  
  // Find items by matching both uid and folder
  const fromIndex = visibleItems.findIndex(item => 
    item.uid === fromUid && item.folder === fromFolder
  )
  const toIndex = visibleItems.findIndex(item => 
    item.uid === toUid && item.folder === toFolder
  )
  
  if (fromIndex === -1 || toIndex === -1) {
    // Fallback: just select the target
    if (toUid && !mailbox.isMessageSelected(toUid, toFolder)) {
      mailbox.selectMessage(toUid, toFolder)
    }
    return
  }
  
  const start = Math.min(fromIndex, toIndex)
  const end = Math.max(fromIndex, toIndex)
  
  // Collect items in range (with folder info)
  const rangeItems = []
  for (let i = start; i <= end; i++) {
    const item = visibleItems[i]
    if (item.uid) {
      rangeItems.push({ uid: item.uid, folder: item.folder })
    }
  }
  
  if (!addToSelection) {
    mailbox.selectNone()
  }
  
  rangeItems.forEach(({ uid, folder }) => {
    if (!mailbox.isMessageSelected(uid, folder)) {
      mailbox.selectMessage(uid, folder)
    }
  })
}

function toggleSelect(e, message, isConversationItem = false) {
  e.stopPropagation()
  const msgFolder = message.folder || mailbox.currentFolder
  
  // Prevent text selection on shift-click
  if (e.shiftKey) {
    e.preventDefault()
  }
  
  if (e.shiftKey && selectionState.value.lastClickedUid) {
    // Shift+click: select range from last clicked to current
    selectRange(
      selectionState.value.lastClickedUid,
      selectionState.value.lastClickedFolder || mailbox.currentFolder,
      message.uid,
      msgFolder,
      true
    )
  } else if (isConversationItem && message.isConversation) {
    // Clicking on a conversation header: toggle all messages in the thread
    const threadMsgs = mailbox.getConversationMessages(message.conversationKey)
    const selectableItems = threadMsgs.filter(m => m.uid).map(m => ({
      uid: m.uid,
      folder: m.folder || mailbox.currentFolder
    }))
    
    const allSelected = selectableItems.length > 0 && selectableItems.every(item => 
      mailbox.isMessageSelected(item.uid, item.folder)
    )
    if (allSelected) {
      // Deselect all in conversation
      selectableItems.forEach(item => {
        mailbox.selectMessage(item.uid, item.folder) // toggle off
      })
    } else {
      // Select all in conversation
      selectableItems.forEach(item => {
        if (!mailbox.isMessageSelected(item.uid, item.folder)) {
          mailbox.selectMessage(item.uid, item.folder)
        }
      })
    }
  } else {
    // Normal click: toggle single message
    mailbox.selectMessage(message.uid, msgFolder)
  }
  
  // Update selection state
  selectionState.value.lastClickedUid = message.uid
  selectionState.value.lastClickedFolder = msgFolder
  selectionState.value.lastClickedConversationKey = isConversationItem ? null : message.conversationKey
}

// Handle selection within expanded thread (for shift-click)
function toggleThreadMsgSelect(e, msg, threadMessages, msgIndex, conversationKey) {
  e.stopPropagation()
  const msgFolder = msg.folder || mailbox.currentFolder
  
  // Prevent text selection on shift-click
  if (e.shiftKey) {
    e.preventDefault()
  }
  
  if (e.shiftKey && selectionState.value.lastClickedUid) {
    // Shift+click: select range using unified system
    selectRange(
      selectionState.value.lastClickedUid,
      selectionState.value.lastClickedFolder || mailbox.currentFolder,
      msg.uid,
      msgFolder,
      true
    )
  } else if (msg.uid) {
    // Normal or Ctrl+click: toggle single message
    mailbox.selectMessage(msg.uid, msgFolder)
  }
  
  // Update selection state
  selectionState.value.lastClickedUid = msg.uid
  selectionState.value.lastClickedFolder = msgFolder
  selectionState.value.lastClickedConversationKey = conversationKey
}

// Scheduled email click handler with single/double click detection
let scheduledClickTimer = null
let scheduledClickCount = 0

async function handleScheduledEmailClick(message) {
  scheduledClickCount++
  
  if (scheduledClickCount === 1) {
    // Wait to see if a second click follows (double-click)
    scheduledClickTimer = setTimeout(async () => {
      scheduledClickCount = 0
      // Single click: show read-only preview
      await mailbox.previewScheduledEmail(message.schedule_id, message)
    }, 250)
  } else if (scheduledClickCount >= 2) {
    // Double-click: open for editing in compose modal
    clearTimeout(scheduledClickTimer)
    scheduledClickCount = 0
    const result = await compose.openScheduledEmail(message.schedule_id)
    if (result.success) {
      toast.success(t('emailList.editingScheduledEmailSchedulePaused'))
      if (mailbox.currentFolder === 'SCHEDULED') {
        await mailbox.fetchScheduledEmails()
      }
    } else {
      toast.error(result.error || t('emailList.failedToMoveMessage'))
    }
  }
}

function getItemKey(item) {
  if (item.conversation_id) return item.conversation_id
  if (item.conversationKey) return item.conversationKey
  const folder = item.folder || ''
  if (item.uid) return folder ? `${folder}:${item.uid}` : String(item.uid)
  return item.message_id || `idx-${Math.random()}`
}

async function handleEmailClick(e, message, isThreadMessage = false) {
  // Ignore clicks that originated from action buttons (star, pin, checkbox)
  if (e.target.closest('button')) return
  
  // Scheduled emails: single click = preview, double click = edit in compose
  if (message.isScheduled && message.schedule_id) {
    handleScheduledEmailClick(message)
    return
  }
  
  const msgFolder = message.folder || mailbox.currentFolder
  
  // Mobile select mode: when a selection is active, a tap toggles this row in/out
  // of the selection instead of opening it. Prevents accidental opens while selecting.
  if (isMobileRef.value && !isThreadMessage && mailbox.selectedMessages.length > 0) {
    mailbox.selectMessage(message.uid, msgFolder)
    selectionState.value.lastClickedUid = message.uid
    selectionState.value.lastClickedFolder = msgFolder
    return
  }
  
  if (e.shiftKey && selectionState.value.lastClickedUid) {
    // Shift+click: select range using unified system
    e.preventDefault()
    selectRange(
      selectionState.value.lastClickedUid,
      selectionState.value.lastClickedFolder || mailbox.currentFolder,
      message.uid,
      msgFolder,
      false
    )
    selectionState.value.lastClickedUid = message.uid
    selectionState.value.lastClickedFolder = msgFolder
  } else if (e.ctrlKey || e.metaKey) {
    // Ctrl+click: toggle selection without opening
    e.preventDefault()
    mailbox.selectMessage(message.uid, msgFolder)
    selectionState.value.lastClickedUid = message.uid
    selectionState.value.lastClickedFolder = msgFolder
  } else {
    // Normal click: open email
    // For search results and All Mail, use the message's actual folder
    const isVirtualFolder = mailbox.currentFolder === 'SEARCH_RESULTS' || mailbox.currentFolder === 'ALL_MAIL'
    const messageFolder = message.folder || mailbox.currentFolder
    
    if (isThreadMessage && message.uid) {
      // Clicking on a thread message - scroll to it in the conversation view
      // Check if this message is already in the currently open conversation
      const currentConv = mailbox.currentMessage
      const isInCurrentConversation = currentConv?.isConversation && 
        currentConv?.messages?.some(m => m.uid === message.uid)
      
      if (isInCurrentConversation) {
        // Already showing this conversation - just scroll to the message
        mailbox.scrollToMessageUid = message.uid
      } else {
        // Need to open the parent conversation first
        mailbox.scrollToMessageUid = message.uid
        const parentConversation = mailbox.conversations.find(c => 
          c.isConversation && mailbox.getConversationMessages(c.conversationKey).some(m => m.uid === message.uid)
        )
        if (parentConversation) {
          if (isVirtualFolder && parentConversation.folder) {
            mailbox.fetchMessageFromFolder(parentConversation.uid, parentConversation.folder)
          } else {
            mailbox.fetchMessage(parentConversation.uid)
          }
        } else {
          // Fallback: just open the message directly
          if (isVirtualFolder && message.folder) {
            mailbox.fetchMessageFromFolder(message.uid, messageFolder)
          } else {
            mailbox.fetchMessage(message.uid)
          }
        }
      }
    } else {
      mailbox.scrollToMessageUid = null
      // Use fetchMessageFromFolder for virtual folders (search results, all mail)
      if (isVirtualFolder && message.folder) {
        mailbox.fetchMessageFromFolder(message.uid, messageFolder)
      } else {
        mailbox.fetchMessage(message.uid)
      }
    }
    // Update selection state for normal clicks too
    selectionState.value.lastClickedUid = message.uid
    selectionState.value.lastClickedFolder = msgFolder
  }
}

function isChecked(message) {
  const folder = message.folder || mailbox.currentFolder
  return mailbox.isMessageSelected(message.uid, folder)
}

// Email search + visual filter state now lives in useEmailSearchStore and is
// driven by EmailSearchBar (mounted in AppHeader's #center slot on desktop
// and inline on mobile). EmailList stays display-only.

// Full page-1 refresh (stale-while-revalidate — list stays visible)
async function forceRefresh() {
  await mailbox.refreshCurrentFolder()
}

function selectMessage(message) {
  mailbox.fetchMessage(message.uid)
}

function isSelected(message) {
  return mailbox.currentMessage?.uid === message.uid
}

function formatDate(timestamp) {
  // Handle missing or invalid timestamps
  if (!timestamp) return ''
  
  // If timestamp is a string (datetime format), parse it directly
  let date
  if (typeof timestamp === 'string') {
    date = new Date(timestamp)
  } else {
    // Unix timestamp (seconds) - convert to milliseconds
    date = new Date(timestamp * 1000)
  }
  
  // Check for invalid date
  if (isNaN(date.getTime())) return ''
  
  const now = new Date()
  const diff = now - date
  
  // Today: show time
  if (diff < 86400000 && date.getDate() === now.getDate()) {
    return date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })
  }
  
  // This week: show "Fri. Jan 22" format
  if (diff < 604800000) {
    const weekday = date.toLocaleDateString([], { weekday: 'short' })
    const month = date.toLocaleDateString([], { month: 'short' })
    const day = date.getDate()
    return `${weekday}. ${month} ${day}`
  }
  
  // This year: show month and day
  if (date.getFullYear() === now.getFullYear()) {
    return date.toLocaleDateString([], { month: 'short', day: 'numeric' })
  }
  
  // Older: show full date
  return date.toLocaleDateString([], { year: 'numeric', month: 'short', day: 'numeric' })
}

function formatSize(bytes) {
  if (bytes < 1024) return bytes + ' B'
  if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB'
  return (bytes / (1024 * 1024)).toFixed(1) + ' MB'
}

// Create calendar event from email date click - opens modal with pre-filled data
function createEventFromEmail(e, message) {
  e.stopPropagation()
  
  // Get the email's date
  const emailDate = new Date(message.timestamp * 1000)
  
  // Get sender info
  const sender = message.from_name || message.from_email || 'Unknown'
  
  // Format date for form: YYYY-MM-DD
  const formatDate = (d) => {
    const pad = (n) => n.toString().padStart(2, '0')
    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`
  }
  
  // Set pending event data in calendar store
  calendarStore.setPendingEvent({
    title: message.subject || 'Email follow-up',
    description: `Follow-up for email from ${sender}`,
    start_date: formatDate(emailDate),
    start_time: '09:00',
    end_date: formatDate(emailDate),
    end_time: '10:00',
    all_day: false,
    linked_message_id: message.message_id,
    linked_email_subject: message.subject,
    linked_email_sender: sender,
    linked_email_folder: mailbox.currentFolder,
  })
  
  // Navigate to calendar view - the modal will open automatically
  router.push({ name: 'calendar' })
}

function toggleStar(e, message) {
  e.stopPropagation()
  const folder = message.folder || mailbox.currentFolder
  mailbox.setFlag(message.uid, 'flagged', !message.flagged, folder)
}

function togglePin(e, message) {
  e.stopPropagation()
  // Use message's actual folder for virtual views (ALL_MAIL, SEARCH_RESULTS)
  const folder = message.folder || mailbox.currentFolder
  mailbox.togglePin(message.uid, folder, {
    message_id: message.message_id,
    subject: message.subject
  })
}

// Cancel a scheduled email
const cancellingScheduleId = ref(null)
async function cancelScheduledEmail(e, item) {
  e.stopPropagation()
  if (!item.schedule_id || cancellingScheduleId.value) return
  
  cancellingScheduleId.value = item.schedule_id
  try {
    const result = await compose.cancelScheduledEmail(item.schedule_id)
    if (result.success) {
      toast.success(t('emailList.scheduledEmailCancelled'))
      // Refresh the scheduled emails list
      await mailbox.fetchScheduledEmails()
    } else {
      toast.error(result.error || t('emailList.failedToCancelScheduledEmail'))
    }
  } catch (e) {
    toast.error(t('emailList.failedToCancelScheduledEmail'))
  } finally {
    cancellingScheduleId.value = null
  }
}

function formatScheduleTime(dateStr) {
  if (!dateStr) return ''
  const d = new Date(dateStr)
  const now = new Date()
  const isToday = d.toDateString() === now.toDateString()
  const tomorrow = new Date(now)
  tomorrow.setDate(tomorrow.getDate() + 1)
  const isTomorrow = d.toDateString() === tomorrow.toDateString()
  
  const timeStr = d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })
  
  if (isToday) return `Today at ${timeStr}`
  if (isTomorrow) return `Tomorrow at ${timeStr}`
  return d.toLocaleDateString([], { month: 'short', day: 'numeric' }) + ` at ${timeStr}`
}

// route.query.search (deep-link from Clients etc.) is handled in MailboxView
// now — it primes useEmailSearchStore so the header search reflects the query.

function prevPage() {
  if (mailbox.pagination.page > 1) {
    mailbox.fetchMessages(null, mailbox.pagination.page - 1)
  }
}

function nextPage() {
  if (mailbox.pagination.page < mailbox.pagination.pages) {
    mailbox.fetchMessages(null, mailbox.pagination.page + 1)
  }
}

const fromDisplay = (message) => {
  const folderName = String(message?.folder || mailbox.currentFolder || '').toLowerCase()
  const isSentFolder =
    folderName === 'sent' ||
    folderName.endsWith('.sent') ||
    folderName.endsWith('.sent items') ||
    folderName.endsWith('.sent mail')

  const isDraftsFolder =
    folderName === 'drafts' ||
    folderName.endsWith('.drafts')

  if ((isSentFolder || isDraftsFolder) && Array.isArray(message?.to) && message.to.length > 0) {
    const firstTo = message.to[0]
    const recipientLabel =
      (typeof firstTo === 'string' ? firstTo : (firstTo?.name || firstTo?.email || '')).trim() || 'Unknown'

    return message.to.length > 1
      ? `${recipientLabel} +${message.to.length - 1}`
      : recipientLabel
  }

  // Scheduled emails: show "To: recipient"
  if (message.isScheduled && Array.isArray(message.to) && message.to.length > 0) {
    const firstTo = message.to[0]
    const name = firstTo.name || firstTo.email?.split('@')[0] || 'Unknown'
    return `To: ${name}${message.to.length > 1 ? ` +${message.to.length - 1}` : ''}`
  }
  
  // Handle array format: [{name: '...', email: '...'}]
  if (Array.isArray(message.from) && message.from.length > 0) {
    const sender = message.from[0]
    return sender.name || sender.email?.split('@')[0] || 'Unknown'
  }
  
  // Handle string format
  if (typeof message.from === 'string' && message.from) {
    const name = message.from.split('<')[0]?.trim()
    return name || message.from_email?.split('@')[0] || 'Unknown'
  }
  
  // Fallback to from_email or from_name
  if (message.from_name) return message.from_name
  if (message.from_email) return message.from_email.split('@')[0]
  
  return 'Unknown'
}

// Drag and drop handlers
const isDragging = ref(false)
const dragSourceUid = ref(null)
const dragSourceFolder = ref(null)

function onDragStart(e, message) {
  isDragging.value = true
  const msgFolder = message.folder || mailbox.currentFolder
  dragSourceUid.value = message.uid
  dragSourceFolder.value = msgFolder
  
  // If the dragged message is selected, drag all selected messages
  // Otherwise, just drag this one message
  let dragItems = []
  if (mailbox.isMessageSelected(message.uid, msgFolder)) {
    // Get all selected messages with their folders
    dragItems = mailbox.getSelectedMessagesData()
  } else {
    dragItems = [{ uid: message.uid, folder: msgFolder }]
  }
  
  // Extract just UIDs for backward compatibility
  const uids = dragItems.map(item => item.uid)
  
  e.dataTransfer.effectAllowed = 'move'
  
  // Set folder move data (for moving to folders)
  // Include full item data for proper multi-folder support
  e.dataTransfer.setData('application/json', JSON.stringify({
    uids,
    items: dragItems, // New: includes folder info for each message
    sourceFolder: mailbox.currentFolder
  }))
  
  // Also set conversation move data (for merging/creating conversations)
  // Only for single message drags
  if (dragItems.length === 1) {
    e.dataTransfer.setData('application/x-conversation-move', JSON.stringify({
      messageId: message.message_id,
      uid: message.uid,
      sourceConversationKey: message.conversationKey || message.conversation_id || null,
      folder: msgFolder
    }))
  }
  
  // Create a custom drag image
  const dragEl = document.createElement('div')
  dragEl.className = 'bg-primary-500 text-white px-3 py-2 rounded-lg shadow-lg text-sm font-medium'
  dragEl.textContent = dragItems.length > 1 ? `${dragItems.length} emails` : '1 email'
  dragEl.style.position = 'absolute'
  dragEl.style.top = '-1000px'
  document.body.appendChild(dragEl)
  e.dataTransfer.setDragImage(dragEl, 0, 0)
  setTimeout(() => document.body.removeChild(dragEl), 0)
}

function onDragEnd() {
  isDragging.value = false
  isDraggingConversationMessage.value = false
  dragOverConversation.value = null
  isDraggingOverList.value = false
  dropBetweenIndex.value = null
  dragSourceUid.value = null
  dragSourceFolder.value = null
}

// =========================================
// CONVERSATION DRAG & DROP
// =========================================
const dragOverConversation = ref(null) // conversation_key being hovered over
const isDraggingOverList = ref(false) // For split drop zone visual feedback
const dropBetweenIndex = ref(null) // Index where drop-between indicator shows
const isDraggingConversationMessage = ref(false) // True when dragging a message from conversation

/**
 * Handle drag over the list area (for split to standalone)
 */
function onListDragOver(e) {
  // Only show feedback if dragging a conversation message
  if (!e.dataTransfer.types.includes('application/x-conversation-move')) return
  
  e.preventDefault()
  e.dataTransfer.dropEffect = 'move'
  isDraggingOverList.value = true
}

function onListDragLeave(e) {
  // Only reset if leaving the list entirely
  if (!e.currentTarget.contains(e.relatedTarget)) {
    isDraggingOverList.value = false
  }
}

/**
 * Start dragging a message from an expanded conversation
 */
function onMessageDragStart(e, msg, sourceConversationKey) {
  isDragging.value = true
  isDraggingConversationMessage.value = true
  
  e.dataTransfer.effectAllowed = 'move'
  e.dataTransfer.setData('application/x-conversation-move', JSON.stringify({
    messageId: msg.message_id,
    uid: msg.uid,
    sourceConversationKey,
    folder: mailbox.currentFolder
  }))
  
  // Create a custom drag image
  const dragEl = document.createElement('div')
  dragEl.className = 'bg-primary-500 text-white px-3 py-2 rounded-lg shadow-lg text-sm font-medium'
  dragEl.textContent = 'Drag to ungroup or merge'
  dragEl.style.position = 'absolute'
  dragEl.style.top = '-1000px'
  document.body.appendChild(dragEl)
  e.dataTransfer.setDragImage(dragEl, 0, 0)
  setTimeout(() => document.body.removeChild(dragEl), 0)
}

/**
 * Handle drag over an email item (conversation or standalone)
 */
function onConversationDragOver(e, item) {
  // Check if this is a conversation move operation
  const convData = e.dataTransfer.types.includes('application/x-conversation-move')
  if (!convData) return

  // Prevent self-drop: don't accept drops on the same item being dragged
  const itemFolder = item.folder || mailbox.currentFolder
  if (dragSourceUid.value === item.uid && dragSourceFolder.value === itemFolder) return
  
  e.preventDefault()
  e.stopPropagation() // Prevent triggering list drop zone
  e.dataTransfer.dropEffect = 'move'
  
  // Use conversationKey for conversations, message_id for standalone emails
  const targetKey = item.conversationKey || item.message_id
  dragOverConversation.value = targetKey
}

/**
 * Handle drag leaving an email item
 */
function onConversationDragLeave(e, item) {
  const targetKey = item.conversationKey || item.message_id
  // Only clear if actually leaving this item
  if (dragOverConversation.value === targetKey) {
    dragOverConversation.value = null
  }
}

/**
 * Normalize message ID for comparison (strips angle brackets, trims whitespace)
 */
function normalizeMessageId(id) {
  if (!id) return null
  return id.replace(/^<|>$/g, '').trim()
}

/**
 * Handle dropping a message onto a conversation or standalone email
 * - If both source and target are standalone (single message): CREATE new conversation (merge)
 * - If target is conversation or source is from conversation: MOVE message to target
 */
async function onConversationDrop(e, targetItem) {
  e.preventDefault()
  e.stopPropagation() // Prevent triggering list drop zone
  dragOverConversation.value = null
  
  const rawData = e.dataTransfer.getData('application/x-conversation-move')
  if (!rawData) return
  
  try {
    const { messageId, uid: sourceUid, sourceConversationKey, folder } = JSON.parse(rawData)
    
    // Get target conversation key (for conversations) or message_id (for standalone)
    const targetConversationId = targetItem.conversationKey || targetItem.conversation_id || targetItem.message_id
    const targetMessageId = targetItem.message_id

    // Definitive self-drop guard: UID + folder is unique and never changes during drag
    const targetFolder = targetItem.folder || mailbox.currentFolder
    if (sourceUid === targetItem.uid && folder === targetFolder) {
      return
    }
    
    isDebugEnabled() && console.log('[CONV-DEBUG] onConversationDrop', {
      sourceMessageId: messageId,
      sourceConversationKey,
      targetConversationId,
      targetMessageId,
      targetIsConversation: targetItem.isConversation,
      targetMessageCount: targetItem.messageCount
    })
    
    // Normalize message IDs for accurate comparison
    const normalizedSourceMsgId = normalizeMessageId(messageId)
    const normalizedTargetMsgId = normalizeMessageId(targetMessageId)
    
    // Check if source message is already in the target conversation using DB assignments
    const sourceCurrentConversation = conversationsStore.getConversationId(messageId)
    
    isDebugEnabled() && console.log('[CONV-DEBUG] onConversationDrop comparison', {
      normalizedSourceMsgId,
      normalizedTargetMsgId,
      sourceCurrentConversation
    })
    
    // Don't drop on same conversation/message
    // Check 1: Same conversation key (for conversation items)
    // Check 2: Same message (normalized comparison)
    // Check 3: Source message is already assigned to the target conversation in DB
    if (sourceConversationKey === targetConversationId || 
        (normalizedSourceMsgId && normalizedSourceMsgId === normalizedTargetMsgId) ||
        (sourceCurrentConversation && sourceCurrentConversation === targetConversationId)) {
      toast.info(t('emailList.messageIsAlreadyInThis'))
      return
    }
    
    // Check if target is a standalone email (not a conversation)
    // A standalone email has isConversation=false OR messageCount undefined/0/1
    const targetIsStandalone = !targetItem.isConversation || (targetItem.messageCount ?? 1) <= 1
    
    // If dropping on standalone email, CREATE a new conversation (merge)
    if (targetIsStandalone && targetMessageId) {
      // Get source conversation IDs before merge (to invalidate their caches if they exist)
      const sourceConvId = conversationsStore.getConversationId(messageId)
      const targetConvId = conversationsStore.getConversationId(targetMessageId)
      
      const result = await conversationsStore.mergeMessages(folder, messageId, targetMessageId)
      
      if (result && result.merged) {
        toast.success(t('emailList.createdNewConversation'))
        // Invalidate specific conversation key caches
        if (sourceConvId) mailbox.conversationKeys.delete(sourceConvId)
        if (targetConvId) mailbox.conversationKeys.delete(targetConvId)
        // NOTE: Don't call fetchConversations here - the merge response already
        // contains updated conversations and updateVersion triggers the watch
      } else {
        toast.error(t('emailList.failedToCreateConversation'))
      }
      return
    }
    
    // Otherwise, move message to existing conversation
    const success = await mailbox.moveMessageToConversation(
      messageId,
      targetConversationId,
      folder
    )
    
    if (success) {
      toast.success(t('emailList.messageMovedToConversation'))
    } else {
      toast.error(t('emailList.failedToMoveMessage'))
    }
  } catch (err) {
    console.error('[EmailList] onConversationDrop error:', err)
    toast.error(t('emailList.failedToMoveMessage'))
  }
}

/**
 * Handle dropping a message into the empty area (splits to standalone)
 */
async function onSplitDrop(e) {
  isDraggingOverList.value = false
  dropBetweenIndex.value = null
  
  const rawData = e.dataTransfer.getData('application/x-conversation-move')
  if (!rawData) return
  
  e.preventDefault()
  
  try {
    const { messageId, folder } = JSON.parse(rawData)
    
    // Split to new conversation (standalone)
    const newConvId = await mailbox.splitMessageToNewConversation(messageId, folder)
    
    if (newConvId) {
      toast.success(t('emailList.messageIsNowStandalone'))
    } else {
      toast.error(t('emailList.failedToUngroupMessage'))
    }
  } catch (err) {
    console.error('[EmailList] onSplitDrop error:', err)
    toast.error(t('emailList.failedToUngroupMessage'))
  }
}

/**
 * Handle drag over a drop-between zone
 */
function onDropBetweenDragOver(e, index) {
  // Only show feedback if dragging a conversation message
  if (!e.dataTransfer.types.includes('application/x-conversation-move')) return
  
  e.preventDefault()
  e.stopPropagation()
  e.dataTransfer.dropEffect = 'move'
  dropBetweenIndex.value = index
}

/**
 * Handle drag leaving a drop-between zone
 */
function onDropBetweenDragLeave() {
  dropBetweenIndex.value = null
}

/**
 * Handle dropping between emails (ungroup/split to standalone)
 */
async function onDropBetweenDrop(e) {
  dropBetweenIndex.value = null
  
  const rawData = e.dataTransfer.getData('application/x-conversation-move')
  if (!rawData) return
  
  e.preventDefault()
  e.stopPropagation()
  
  try {
    const { messageId, folder, sourceConversationKey } = JSON.parse(rawData)
    
    // Only allow ungrouping if source is from a conversation
    if (!sourceConversationKey) {
      toast.info(t('emailList.thisMessageIsAlreadyStandalone'))
      return
    }
    
    // Split to new conversation (standalone)
    const newConvId = await mailbox.splitMessageToNewConversation(messageId, folder)
    
    if (newConvId) {
      toast.success(t('emailList.messageUngrouped'))
    } else {
      toast.error(t('emailList.failedToUngroupMessage'))
    }
  } catch (err) {
    console.error('[EmailList] onDropBetweenDrop error:', err)
    toast.error(t('emailList.failedToUngroupMessage'))
  }
}

// Clean folder display name
const folderDisplayName = computed(() => {
  const name = mailbox.currentFolder
  if (name === 'SEARCH_RESULTS') return 'Search Results'
  if (name === 'INBOX') return 'Inbox'
  if (name === 'ALL_MAIL') return 'All Mail'
  if (name.startsWith('INBOX.')) {
    const cleanName = name.slice(6)
    if (cleanName === 'Sent') return 'Sent'
    if (cleanName === 'Drafts') return 'Drafts'
    if (cleanName === 'Deleted Items' || cleanName === 'Trash') return 'Trash'
    if (cleanName === 'Junk E-mail' || cleanName === 'Spam' || cleanName === 'Junk') return 'Spam'
    if (cleanName === 'Archive') return 'Archive'
    // Replace dots with arrows for subfolder paths
    return cleanName.replace(/\./g, ' -> ')
  }
  return name?.replace(/\./g, ' -> ') || name
})

// Check if viewing All Mail
const isAllMailView = computed(() => mailbox.currentFolder === 'ALL_MAIL')

// Get folder display name for a message (used in All Mail view)
function getMessageFolderName(message, short = true) {
  if (message.folder_display && !short) return message.folder_display
  const folder = message.folder
  if (!folder) return ''
  if (folder === 'INBOX') return 'Inbox'
  if (folder.startsWith('INBOX.')) {
    const cleanName = folder.slice(6)
    if (cleanName === 'Sent') return 'Sent'
    if (cleanName === 'Drafts') return 'Drafts'
    if (cleanName === 'Deleted Items' || cleanName === 'Trash') return 'Trash'
    if (cleanName === 'Junk E-mail' || cleanName === 'Spam' || cleanName === 'Junk') return 'Spam'
    if (cleanName === 'Archive') return 'Archive'
    // For short display, only show the last folder in the path
    if (short && cleanName.includes('.')) {
      const parts = cleanName.split('.')
      return parts[parts.length - 1]
    }
    return cleanName
  }
  // For non-INBOX folders, return last part if short mode
  if (short && folder.includes('.')) {
    const parts = folder.split('.')
    return parts[parts.length - 1]
  }
  return folder
}

// Navigate directly to a message's folder (folder badge click in All Mail / search views)
function goToFolder(folder) {
  if (!folder || folder === mailbox.currentFolder) return
  mailbox.clearCurrentMessage()
  mailbox.fetchMessages(folder, 1)
}

// Check if we're in a trash folder
const isInTrash = computed(() => {
  const folder = mailbox.folders.find(f => f.name === mailbox.currentFolder)
  return folder?.type === 'trash'
})

// ========================================
// MOBILE SWIPE GESTURES (iOS-style)
// ========================================
const swipeState = ref({
  activeUid: null,
  startX: 0,
  startY: 0,
  currentX: 0,
  swiping: false,
  direction: null, // 'left' or 'right'
  committed: false, // threshold reached
  settling: false, // finger released — animate to the target offset
})

const SWIPE_THRESHOLD = 80 // px to trigger action
const SWIPE_MAX = 120 // max visual travel
const SWIPE_DEAD_ZONE = 10 // px before we decide direction
const SWIPE_SNAP_MS = 240 // snap-back / open animation duration

// Timer that tears the swipe state down after the cancel animation finishes.
let swipeResetTimer = null

function onSwipeTouchStart(e, item) {
  const touch = e.touches[0]
  if (swipeResetTimer) {
    clearTimeout(swipeResetTimer)
    swipeResetTimer = null
  }
  swipeState.value = {
    activeUid: item.uid,
    startX: touch.clientX,
    startY: touch.clientY,
    currentX: 0,
    swiping: false,
    direction: null,
    committed: false,
    settling: false,
  }
}

function onSwipeTouchMove(e, item) {
  if (swipeState.value.activeUid !== item.uid) return
  
  const touch = e.touches[0]
  const deltaX = touch.clientX - swipeState.value.startX
  const deltaY = touch.clientY - swipeState.value.startY
  
  // If we haven't decided direction yet
  if (!swipeState.value.swiping) {
    if (Math.abs(deltaX) < SWIPE_DEAD_ZONE && Math.abs(deltaY) < SWIPE_DEAD_ZONE) return
    
    // If vertical scroll dominates, abort swipe
    if (Math.abs(deltaY) > Math.abs(deltaX)) {
      swipeState.value.activeUid = null
      return
    }
    
    swipeState.value.swiping = true
    swipeState.value.direction = deltaX > 0 ? 'right' : 'left'
  }
  
  // Prevent page scroll while swiping horizontally
  e.preventDefault()
  
  // Clamp swipe distance
  let clampedX = deltaX
  if (swipeState.value.direction === 'left') {
    clampedX = Math.max(-SWIPE_MAX, Math.min(0, deltaX))
  } else {
    clampedX = Math.min(SWIPE_MAX, Math.max(0, deltaX))
  }
  
  swipeState.value.currentX = clampedX
  swipeState.value.committed = Math.abs(clampedX) >= SWIPE_THRESHOLD
}

function onSwipeTouchEnd(e, item) {
  if (swipeState.value.activeUid !== item.uid) return

  const { direction, committed, swiping } = swipeState.value

  // Plain tap (never crossed the dead zone) — nothing to animate.
  if (!swiping) {
    resetSwipeState()
    return
  }

  // Turn the snap transition on first, then move to the target on the next
  // tick so the browser animates the change instead of jumping to it. The
  // action buttons (fixed-width underlay) stay mounted for the whole
  // animation and are re-covered by the row sliding back, so they fade out
  // cleanly instead of collapsing.
  swipeState.value.settling = true

  if (committed && (direction === 'right' || direction === 'left')) {
    const target = direction === 'right' ? SWIPE_MAX : -SWIPE_MAX
    nextTick(() => { swipeState.value.currentX = target })
  } else {
    // Not committed: slide back to rest, then tear the state down once the
    // row has visually returned.
    nextTick(() => { swipeState.value.currentX = 0 })
    swipeResetTimer = setTimeout(() => { resetSwipeState() }, SWIPE_SNAP_MS + 20)
  }
}

function resetSwipeState() {
  if (swipeResetTimer) {
    clearTimeout(swipeResetTimer)
    swipeResetTimer = null
  }
  swipeState.value = {
    activeUid: null,
    startX: 0,
    startY: 0,
    currentX: 0,
    swiping: false,
    direction: null,
    committed: false,
    settling: false,
  }
}

function onSwipeDeleteTap(item) {
  const itemFolder = item.folder || mailbox.currentFolder
  mailbox.deleteMessage(item.uid, itemFolder, isInTrash.value)
  toast.success(isInTrash.value ? t('emailList.permanentlyDeletedMsg') : t('emailList.movedToTrash'))
  resetSwipeState()
}

function onSwipeReadTap(item) {
  const itemFolder = item.folder || mailbox.currentFolder
  const newSeen = !item.seen
  mailbox.setFlag(item.uid, 'seen', newSeen, itemFolder)
  toast.success(newSeen ? t('emailList.markedAsRead') : t('emailList.markedAsUnread'))
  resetSwipeState()
}

function onSwipePinTap(item) {
  const itemFolder = item.folder || mailbox.currentFolder
  const isPinned = mailbox.isEmailPinned(item.uid, itemFolder)
  mailbox.togglePin(item.uid, itemFolder, item)
  toast.success(isPinned ? t('emailList.unpin') : t('emailList.pin'))
  resetSwipeState()
}

function getSwipeTranslate(item) {
  if (swipeState.value.activeUid !== item.uid || !swipeState.value.swiping) return ''
  // While the finger is down we follow it instantly (no transition); once it
  // is released (settling) we animate to the target offset.
  const transition = swipeState.value.settling
    ? `transform ${SWIPE_SNAP_MS}ms cubic-bezier(0.25, 0.46, 0.45, 0.94)`
    : 'none'
  return `transform: translateX(${swipeState.value.currentX}px); transition: ${transition};`
}

function isSwipeActive(item, dir) {
  return swipeState.value.activeUid === item.uid && swipeState.value.swiping && swipeState.value.direction === dir
}

function isSwipeCommitted(item) {
  return swipeState.value.activeUid === item.uid && swipeState.value.committed
}

const restoringAll = ref(false)
const restoringSelected = ref(false)
const deletingSelectedPermanently = ref(false)
const showDeleteSelectedConfirm = ref(false)

async function restoreAllFromTrash() {
  if (!confirm('Restore all messages from Trash to Inbox?')) return
  
  restoringAll.value = true
  const result = await mailbox.restoreAllFromTrash('INBOX')
  restoringAll.value = false
  
  if (result) {
    toast.success(t('emailList.restoredMessages', { count: result.restored }))
    if (result.failed > 0) {
      toast.warning(t('emailList.messagesFailedToRestore', { count: result.failed }))
    }
  } else {
    toast.error(t('emailList.failedToRestoreMessages'))
  }
}

async function restoreSelectedFromTrash() {
  if (mailbox.selectedMessages.length === 0) return

  restoringSelected.value = true

  const selectedData = mailbox.getSelectedMessagesData()
  const result = await mailbox.bulkRestoreMessages(selectedData, 'INBOX')

  restoringSelected.value = false
  mailbox.clearSelection()

  if (result.success > 0) {
    toast.success(t('emailList.restoredMessages', { count: result.success }))
  }
  if (result.failed > 0) {
    toast.error(t('emailList.failedToRestoreCount', { count: result.failed }))
  }
}

function promptDeleteSelectedPermanently() {
  if (mailbox.selectedMessages.length === 0) return
  showDeleteSelectedConfirm.value = true
}

async function deleteSelectedPermanently() {
  showDeleteSelectedConfirm.value = false
  deletingSelectedPermanently.value = true
  
  // Get selected messages data and extract UIDs
  const selectedData = mailbox.getSelectedMessagesData()
  const uids = selectedData.map(item => item.uid)
  const result = await mailbox.bulkDeleteMessages(uids, true) // permanent = true
  
  deletingSelectedPermanently.value = false
  mailbox.clearSelection()
  
  if (result.success > 0) {
    toast.success(t('emailList.permanentlyDeletedCount', { count: result.success }))
  }
  if (result.failed > 0) {
    toast.error(t('emailList.failedToDeleteCount', { count: result.failed }))
  }
}
</script>

<template>
  <div class="h-full flex flex-col">
    <!--
      All Mail degraded-folders banner (Wave 1).
      Self-gated: only renders when current folder == ALL_MAIL and there
      are degraded entries. Component handles dismissal + retry internally.
    -->
    <AllMailDegradedBanner class="px-2 pt-2" />

    <!--
      Mobile-only search bar. On md+ viewports the search lives in AppHeader's
      centre slot (see MailboxView). On mobile the header is too cramped, so we
      keep an inline search row at the top of the list.
    -->
    <div class="md:hidden h-12 px-2 sm:px-3 flex items-center border-b border-surface-200 dark:border-[rgb(var(--color-border))]">
      <EmailSearchBar placement="inline" class="flex-1 min-w-0" />
    </div>

    <!-- Folder header with select all -->
    <div class="px-3 py-2 flex items-center gap-2 border-b border-surface-100 dark:border-[rgb(var(--color-border))]">
      <!-- Select all checkbox with dropdown -->
      <div class="relative flex items-center select-menu-container">
        <button
          @click="toggleSelectAll"
          class="p-1 text-surface-400 hover:text-surface-600 dark:hover:text-surface-200"
          :title="$t('emailList.selectAll')"
        >
          <span class="material-symbols-rounded text-xl">
            {{ allSelected ? 'check_box' : someSelected ? 'indeterminate_check_box' : 'check_box_outline_blank' }}
          </span>
        </button>
        <button
          @click="showSelectMenu = !showSelectMenu"
          class="p-0.5 text-surface-400 hover:text-surface-600 dark:hover:text-surface-200"
          :title="$t('emailList.selectionOptions')"
        >
          <span class="material-symbols-rounded text-sm">arrow_drop_down</span>
        </button>
        
        <!-- Dropdown menu -->
        <div 
          v-if="showSelectMenu"
          class="absolute left-0 top-full mt-1 w-36 bg-white dark:bg-surface-800 rounded-xl shadow-lg z-30 py-1 border border-surface-200 dark:border-surface-700"
        >
          <button
            @click="selectOption('all')"
            class="w-full px-4 py-2 text-left text-sm hover:bg-surface-100 dark:hover:bg-surface-700 text-surface-700 dark:text-surface-200"
          >
            {{ $t('emailList.selectAll') }}
          </button>
          <button
            @click="selectOption('none')"
            class="w-full px-4 py-2 text-left text-sm hover:bg-surface-100 dark:hover:bg-surface-700 text-surface-700 dark:text-surface-200"
          >
            {{ $t('emailList.selectNone') }}
          </button>
          <div class="border-t border-surface-200 dark:border-surface-700 my-1"></div>
          <button
            @click="selectOption('read')"
            class="w-full px-4 py-2 text-left text-sm hover:bg-surface-100 dark:hover:bg-surface-700 text-surface-700 dark:text-surface-200"
          >
            {{ $t('emailList.selectRead') }}
          </button>
          <button
            @click="selectOption('unread')"
            class="w-full px-4 py-2 text-left text-sm hover:bg-surface-100 dark:hover:bg-surface-700 text-surface-700 dark:text-surface-200"
          >
            {{ $t('emailList.selectUnread') }}
          </button>
          <button
            @click="selectOption('starred')"
            class="w-full px-4 py-2 text-left text-sm hover:bg-surface-100 dark:hover:bg-surface-700 text-surface-700 dark:text-surface-200"
          >
            {{ $t('emailList.selectStarred') }}
          </button>
          <button
            @click="selectOption('unstarred')"
            class="w-full px-4 py-2 text-left text-sm hover:bg-surface-100 dark:hover:bg-surface-700 text-surface-700 dark:text-surface-200"
          >
            {{ $t('emailList.selectNone') }}
          </button>
        </div>
      </div>
      
      <!-- Folder name and count -->
      <div class="flex-1 flex items-center gap-2 min-w-0">
        <h2 class="font-medium text-surface-900 dark:text-surface-100 truncate">
          {{ folderDisplayName }}
        </h2>
        <span class="text-xs text-surface-500 flex-shrink-0">
          <template v-if="mailbox.selectedMessages.length > 0">
            <span class="text-primary-500 font-medium">{{ mailbox.selectedMessages.length }} {{ $t('emailList.selected') }}</span>
            <span class="mx-1">/</span>
          </template>
          {{ $t('emailList.messagesOfTotal', { current: Math.max(0, mailbox.messages.length), total: Math.max(0, mailbox.pagination.total) }) }}
        </span>
      </div>
      
      <!-- All Mail grouping mode toggle -->
      <div v-if="isAllMailView" class="flex items-center gap-1 flex-shrink-0 bg-surface-100 dark:bg-surface-800 rounded-lg p-0.5">
        <button 
          @click="mailbox.setAllMailGroupMode('date')"
          :class="[
            'px-2 py-1 text-xs font-medium rounded-md transition-colors',
            mailbox.allMailGroupMode === 'date' 
              ? 'bg-white dark:bg-surface-700 text-surface-900 dark:text-surface-100 shadow-sm' 
              : 'text-surface-500 hover:text-surface-700 dark:hover:text-surface-300'
          ]"
          :title="$t('emailList.groupByDate')"
        >
          <span class="material-symbols-rounded text-sm align-middle mr-0.5">calendar_today</span>
          {{ $t('emailList.date') }}
        </button>
        <button 
          @click="mailbox.setAllMailGroupMode('folder')"
          :class="[
            'px-2 py-1 text-xs font-medium rounded-md transition-colors',
            mailbox.allMailGroupMode === 'folder' 
              ? 'bg-white dark:bg-surface-700 text-surface-900 dark:text-surface-100 shadow-sm' 
              : 'text-surface-500 hover:text-surface-700 dark:hover:text-surface-300'
          ]"
          :title="$t('emailList.groupByFolder')"
        >
          <span class="material-symbols-rounded text-sm align-middle mr-0.5">folder</span>
          {{ $t('emailList.folder') }}
        </button>
      </div>
      
      <!-- Conversation view toggle -->
      <button 
        @click="mailbox.toggleConversationView()"
        :class="[
          'btn-sm btn-icon flex-shrink-0 rounded-lg transition-colors',
          mailbox.conversationView 
            ? 'bg-primary-100 dark:bg-primary-900/50 text-primary-600 dark:text-primary-400' 
            : 'btn-ghost'
        ]"
        :title="mailbox.conversationView ? $t('emailList.switchToEmailView') : $t('emailList.switchToConversationView')"
      >
        <span class="material-symbols-rounded text-lg">
          {{ mailbox.conversationView ? 'forum' : 'chat_bubble_outline' }}
        </span>
      </button>
      
      <!-- Trash folder actions -->
      <template v-if="isInTrash && mailbox.pagination.total > 0">
        <!-- Restore Selected button (only when selection exists) -->
        <button 
          v-if="mailbox.selectedMessages.length > 0"
          @click="restoreSelectedFromTrash"
          class="btn-sm flex items-center gap-1 px-3 py-1.5 text-green-600 dark:text-green-400 hover:bg-green-50 dark:hover:bg-green-900/30 rounded-lg transition-colors flex-shrink-0"
          :disabled="restoringSelected"
          :title="$t('emailList.restoreSelectedMessagesToInbox')"
        >
          <span v-if="restoringSelected" class="spinner w-4 h-4"></span>
          <span v-else class="material-symbols-rounded text-lg">restore</span>
          <span class="text-sm font-medium">{{ $t('emailList.restoreAllConfirmTitle') }} ({{ mailbox.selectedMessages.length }})</span>
        </button>
        
        <!-- Delete Selected Permanently button (only when selection exists) -->
        <button 
          v-if="mailbox.selectedMessages.length > 0"
          @click="promptDeleteSelectedPermanently"
          class="btn-sm flex items-center gap-1 px-3 py-1.5 text-red-600 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-900/30 rounded-lg transition-colors flex-shrink-0"
          :disabled="deletingSelectedPermanently"
          :title="$t('emailList.permanentlyDeleteSelectedMessages')"
        >
          <span v-if="deletingSelectedPermanently" class="spinner w-4 h-4"></span>
          <span v-else class="material-symbols-rounded text-lg">delete_forever</span>
          <span class="text-sm font-medium">{{ $t('emailList.deletePermanently') }} ({{ mailbox.selectedMessages.length }})</span>
        </button>
        
        <!-- Restore All button -->
        <button 
          v-if="mailbox.selectedMessages.length === 0"
          @click="restoreAllFromTrash"
          class="btn-sm flex items-center gap-1 px-3 py-1.5 text-green-600 dark:text-green-400 hover:bg-green-50 dark:hover:bg-green-900/30 rounded-lg transition-colors flex-shrink-0"
          :disabled="restoringAll"
          :title="$t('emailList.restoreAllMessagesToInbox')"
        >
          <span v-if="restoringAll" class="spinner w-4 h-4"></span>
          <span v-else class="material-symbols-rounded text-lg">restore</span>
          <span class="text-sm font-medium">{{ $t('emailList.restoreAll') }}</span>
        </button>
      </template>
      
      <!-- Refresh + last synced -->
      <div class="flex items-center gap-1 flex-shrink-0">
        <RefreshIndicator :timestamp="mailbox.getLastRefreshed(mailbox.currentFolder)" />
        <button
          @click="forceRefresh"
          class="btn-ghost btn-sm btn-icon flex-shrink-0"
          :disabled="mailbox.loading.messages || mailbox.loading.refreshing"
          :title="$t('emailList.refreshEmailsFromServer')"
        >
          <span :class="['material-symbols-rounded text-lg', { 'animate-spin': mailbox.loading.messages || mailbox.loading.refreshing }]">
            refresh
          </span>
        </button>
      </div>
    </div>
    
    <!-- Slim top progress bar (Gmail-style). Shows during any list load/refresh
         without replacing the visible email rows. -->
    <div
      v-show="mailbox.loading.messages || mailbox.loading.refreshing"
      class="email-list-progress"
      aria-hidden="true"
    >
      <div class="email-list-progress__bar"></div>
    </div>

    <!-- Message list -->
    <div 
      ref="listContainerRef"
      class="flex-1 overflow-y-auto relative"
      @scroll="handleListScroll"
      @dragover="onListDragOver"
      @dragleave="onListDragLeave"
      @drop="onSplitDrop"
      :class="{ 'split-drop-zone-active': isDraggingOverList }"
    >
      <!-- Pull-to-refresh indicator (mobile only) -->
      <div
        v-if="layout.isMobile"
        v-show="pulling || pullRefreshing"
        class="ptr-indicator"
        :class="{ 'ptr-releasing': !pulling && pullRefreshing }"
        :style="{ transform: `translateY(${pullDistance - 48}px)` }"
      >
        <span
          class="material-symbols-rounded text-xl text-surface-400"
          :class="pullRefreshing ? 'ptr-spin' : ''"
          :style="!pullRefreshing ? { transform: `rotate(${Math.min(pullDistance / 64 * 180, 180)}deg)` } : {}"
        >refresh</span>
        <span class="text-xs font-medium text-surface-400">
          {{ pullRefreshing ? 'Refreshing...' : pullDistance >= 64 ? 'Release to refresh' : 'Pull to refresh' }}
        </span>
      </div>

      <!-- Wrapper shifts content down during pull (GPU-accelerated) -->
      <div
        class="ptr-content"
        :class="{ 'ptr-releasing': !pulling && !pullRefreshing && pullDistance === 0 }"
        :style="(pulling || pullRefreshing) ? { transform: `translateY(${pullDistance}px)` } : {}"
      >
      <!-- Cold-folder load state (only when there are no rows AT ALL).
           The slim top progress bar already signals activity, so this is just
           a quiet "preparing this folder" placeholder rather than a giant spinner. -->
      <div v-if="mailbox.loading.messages && mailbox.messages.length === 0" class="flex flex-col items-center justify-center h-full text-surface-400">
        <span class="material-symbols-rounded text-4xl mb-2 opacity-60">{{ isScheduledView ? 'schedule_send' : 'inbox' }}</span>
        <p class="text-sm">{{ $t('emailList.loadingFolder') }}</p>
      </div>

      <div v-else-if="mailbox.messages.length === 0" class="flex flex-col items-center justify-center h-full text-surface-500">
        <span class="material-symbols-rounded text-5xl mb-2">{{ isScheduledView ? 'schedule_send' : 'inbox' }}</span>
        <p>{{ isScheduledView ? $t('emailList.noEmails') : $t('emailList.noEmails') }}</p>
        <p v-if="isScheduledView" class="text-xs mt-1 text-surface-400">{{ $t('emailList.scheduleEmailsToSendThem') }}</p>
      </div>
      
      <div v-else>
        <template v-for="(group, groupIndex) in groupedConversations" :key="group.key || groupIndex">
          <!-- Time period group header (collapsible) -->
          <div 
            @click="toggleGroupCollapse(group.key)"
            :class="[
              'date-group-header sticky top-0 px-3 py-1.5 border-b flex items-center gap-2 cursor-pointer transition-colors select-none',
              group.key === 'pinned' 
                ? 'z-10 bg-amber-50 dark:bg-[rgb(40,28,8)] border-amber-200 dark:border-amber-800/50 hover:bg-amber-100 dark:hover:bg-[rgb(52,37,11)]' 
                : 'z-10 bg-[rgb(245,245,245)] dark:bg-[rgb(23,23,28)] border-surface-300 dark:border-surface-800 hover:bg-[rgb(235,235,235)] dark:hover:bg-[rgb(30,30,36)]'
            ]"
          >
            <span class="material-symbols-rounded text-lg transition-transform" :class="[
              { '-rotate-90': isGroupCollapsed(group.key) },
              group.key === 'pinned' ? 'text-amber-500' : 'text-surface-400'
            ]">
              {{ group.key === 'pinned' ? 'push_pin' : 'expand_more' }}
            </span>
            <span :class="[
              'font-medium text-sm',
              group.key === 'pinned' ? 'text-amber-700 dark:text-amber-400' : 'text-surface-600 dark:text-surface-400'
            ]">
              {{ formatGroupHeader(group.key) }}
            </span>
            <span :class="[
              'text-xs ml-auto',
              group.key === 'pinned' ? 'text-amber-500 dark:text-amber-500' : 'text-surface-400 dark:text-surface-500'
            ]">
              {{ group.items.length }}
            </span>
          </div>
          
          <!-- Group items (collapsible) -->
          <template v-if="!isGroupCollapsed(group.key)">
          <template v-for="(item, itemIndex) in group.items" :key="getItemKey(item)">
          
          <!-- Drop between zone (ungroup target) - only shows when dragging a conversation message -->
          <div 
            v-if="isDraggingConversationMessage"
            class="drop-between-zone"
            :class="{ 'drop-between-active': dropBetweenIndex === `${groupIndex}-${itemIndex}` }"
            @dragover="onDropBetweenDragOver($event, `${groupIndex}-${itemIndex}`)"
            @dragleave="onDropBetweenDragLeave"
            @drop="onDropBetweenDrop($event)"
          ></div>
          
          <!-- Main email/conversation row - 2-column (stacked) layout -->
          <!-- DESKTOP VERSION -->
          <div
            v-if="layout.isStackedLayout && !layout.isMobile"
            @click="handleEmailClick($event, item)"
            @contextmenu="handleContextMenu($event, item)"
            draggable="true"
            @dragstart="onDragStart($event, item)"
            @dragend="onDragEnd"
            @dragover="onConversationDragOver($event, item)"
            @dragleave="onConversationDragLeave($event, item)"
            @drop="onConversationDrop($event, item)"
            :class="[
              'email-item-wide cursor-grab active:cursor-grabbing',
              { 'selected': isSelected(item) || isChecked(item), 'unread': !item.seen || item.hasUnread },
              { 'conversation-drop-target': dragOverConversation && dragOverConversation === (item.conversationKey || item.message_id) },
              { 'pinned-item': mailbox.isEmailPinned(item.uid, item.folder || mailbox.currentFolder) }
            ]"
          >
            <!-- Conversation expand/collapse arrow (far left) -->
            <button
              v-if="item.isConversation && item.messageCount > 1"
              @click.stop="mailbox.toggleConversationExpanded(item.conversationKey)"
              class="w-6 flex-shrink-0 flex items-center justify-center text-surface-400 hover:text-surface-600 dark:hover:text-surface-200 transition-colors"
              :title="mailbox.isConversationExpanded(item.conversationKey) ? $t('emailList.collapse') : $t('emailList.expand')"
            >
              <span class="material-symbols-rounded text-lg transition-transform" :class="{ 'rotate-90': !mailbox.isConversationExpanded(item.conversationKey) }">
                expand_more
              </span>
            </button>
            <span v-else class="w-6 flex-shrink-0"></span>
            
            <!-- Unread indicator dot -->
            <span 
              v-if="!item.seen || item.hasUnread" 
              class="unread-dot"
              :title="$t('emailList.unread')"
            ></span>
            <span v-else class="unread-dot-spacer"></span>
            
            <!-- Action buttons group -->
            <div class="flex-shrink-0 flex items-center gap-1 pr-1" @click.stop>
              <!-- Checkbox -->
              <button 
                type="button"
                @click.stop="toggleSelect($event, item, item.isConversation)"
                class="flex-shrink-0 w-7 h-7 flex items-center justify-center rounded transition-colors text-surface-400 hover:text-surface-600 dark:hover:text-surface-200 hover:bg-surface-100 dark:hover:bg-surface-700"
                :title="item.isConversation ? $t('emailList.ctrlClickToSelectAll') : ''"
              >
                <span class="material-symbols-rounded text-xl leading-none">
                  {{ isChecked(item) ? 'check_box' : 'check_box_outline_blank' }}
                </span>
              </button>
              
              <!-- Pin -->
              <button 
                type="button"
                @click.stop="togglePin($event, item)"
                :class="[
                  'flex-shrink-0 w-7 h-7 flex items-center justify-center rounded transition-colors hover:bg-surface-100 dark:hover:bg-surface-700',
                  mailbox.isEmailPinned(item.uid, item.folder || mailbox.currentFolder)
                    ? 'text-primary-500 hover:text-primary-600' 
                    : 'text-surface-300 hover:text-primary-300/60 dark:text-surface-600 dark:hover:text-primary-400/40'
                ]"
                :title="mailbox.isEmailPinned(item.uid, item.folder || mailbox.currentFolder) ? $t('emailList.unpin') : $t('emailList.pin')"
              >
                <span class="material-symbols-rounded text-xl leading-none" :style="mailbox.isEmailPinned(item.uid, item.folder || mailbox.currentFolder) ? 'font-variation-settings: \'FILL\' 1' : ''">
                  push_pin
                </span>
              </button>
              
              <!-- Star -->
              <button 
                type="button"
                @click.stop="toggleStar($event, item)"
                :class="[
                  'flex-shrink-0 w-7 h-7 flex items-center justify-center rounded transition-colors hover:bg-surface-100 dark:hover:bg-surface-700',
                  item.flagged || item.hasStarred
                    ? 'text-amber-400 hover:text-amber-500' 
                    : 'text-surface-300 hover:text-amber-300/60 dark:text-surface-600 dark:hover:text-amber-400/40'
                ]"
              >
                <span class="material-symbols-rounded text-xl leading-none" :style="item.flagged || item.hasStarred ? 'font-variation-settings: \'FILL\' 1' : ''">
                  star
                </span>
              </button>
            </div>
            
            <!-- Sender (fixed width) -->
            <div class="flex items-center gap-1.5 min-w-0 w-48 flex-shrink-0">
              <!-- Reply indicator (before sender, like Gmail) -->
              <span
                v-if="item.answered"
                class="material-symbols-rounded text-base text-primary-500 dark:text-primary-400 flex-shrink-0 -mr-0.5"
                style="font-variation-settings: 'wght' 500"
                :title="$t('emailList.replied')"
              >reply</span>
              <span :class="['truncate text-sm', (!item.seen || item.hasUnread) ? 'text-surface-900 dark:text-surface-100 font-medium' : 'text-surface-600 dark:text-surface-300']">
                {{ fromDisplay(item) }}
              </span>
              <!-- Folder badge (All Mail view) -->
              <span 
                v-if="isAllMailView && item.folder"
                class="flex-shrink-0 max-w-[80px] truncate px-1.5 py-0.5 text-[0.625rem] font-medium bg-surface-100 dark:bg-surface-700 text-surface-500 dark:text-surface-400 rounded cursor-pointer hover:bg-surface-200 dark:hover:bg-surface-600 hover:text-surface-700 dark:hover:text-surface-200 transition-colors"
                :title="$t('emailList.goToFolder', { folder: item.folder })"
                @click.stop="goToFolder(item.folder)"
              >{{ getMessageFolderName(item) }}</span>
              <!-- Linked account indicator -->
              <span 
                v-if="item.linked_account"
                class="flex-shrink-0 material-symbols-rounded text-sm text-primary-500"
                :title="$t('emailList.syncedFrom', { account: item.linked_account })"
              >link</span>
            </div>
            
            <!-- Subject + Preview (flexible, takes all remaining space) -->
            <div class="flex-1 min-w-0 flex items-center gap-1.5">
              <!-- Reaction indicator -->
              <span 
                v-if="item.is_reaction_email && item.reaction_emoji" 
                class="flex-shrink-0 text-lg" 
                :title="$t('emailList.reaction', { emoji: item.reaction_emoji })"
              >
                {{ item.reaction_emoji }}
              </span>
              <!-- Subject and preview in single truncating container -->
              <div class="flex-1 min-w-0 truncate text-sm">
                <span
                  v-if="item.important"
                  class="material-symbols-rounded text-red-500 text-base align-middle"
                  style="font-variation-settings: 'FILL' 1"
                  :title="$t('emailList.important')"
                >priority_high</span>
                <span :class="[(!item.seen || item.hasUnread) ? 'text-surface-900 dark:text-surface-100 font-medium' : 'text-surface-600 dark:text-surface-400']">{{ item.subject || '(No subject)' }}</span>
                <span v-if="item.body_preview || item.snippet" class="text-surface-400 dark:text-surface-500"> - {{ item.body_preview || item.snippet }}</span>
              </div>
            </div>
            
            <!-- Metadata cell: labels + badges + icons (fixed, aligned right) -->
            <div class="flex-shrink-0 flex items-center gap-1 overflow-hidden">
              <!-- Labels -->
              <template v-if="item.labels?.length">
                <span
                  v-for="label in item.labels.slice(0, 2)"
                  :key="label.id"
                  class="inline-flex items-center h-5 px-2 rounded-full text-[0.625rem] font-medium text-white"
                  :style="{ backgroundColor: label.color }"
                >
                  {{ label.name }}
                </span>
                <span v-if="item.labels.length > 2" class="text-[0.625rem] text-surface-500">+{{ item.labels.length - 2 }}</span>
              </template>
              <!-- Conversation count badge -->
              <span 
                v-if="item.isConversation && item.messageCount > 1"
                class="h-5 px-2 inline-flex items-center text-[0.625rem] font-medium border border-primary-500/30 bg-primary-500/10 text-primary-600 dark:text-primary-400 rounded-full"
                :title="$t('emailList.messagesInConversation', { count: item.messageCount })"
              >
                {{ item.messageCount }}
              </span>
              <!-- Split conversation indicator -->
              <span 
                v-if="item.isSplit"
                class="h-5 w-5 inline-flex items-center justify-center rounded-full bg-surface-200 dark:bg-surface-700 text-surface-500"
                :title="$t('emailList.splitConversation')"
              >
                <span class="material-symbols-rounded text-xs">call_split</span>
              </span>
              <!-- Reactions inline -->
              <ReactionDisplay 
                v-if="item.message_id"
                :message-id="item.message_id"
                compact
                class="flex-shrink-0"
              />
              <!-- Scheduled cancel or action icons -->
              <template v-if="item.isScheduled">
                <button
                  @click="cancelScheduledEmail($event, item)"
                  :disabled="cancellingScheduleId === item.schedule_id"
                  class="inline-flex items-center gap-0.5 px-2 py-0.5 rounded-full text-xs font-medium bg-red-50 dark:bg-red-900/20 text-red-600 dark:text-red-400 hover:bg-red-100 dark:hover:bg-red-900/40 transition-colors"
                  :title="$t('emailList.cancelScheduledEmail')"
                >
                  <span v-if="cancellingScheduleId === item.schedule_id" class="spinner-xs"></span>
                  <span v-else class="material-symbols-rounded text-sm">close</span>
                </button>
              </template>
              <template v-else>
                <span v-if="hasCachedSummary(item)" class="material-symbols-rounded text-green-500 text-lg" :title="$t('emailList.aiSummaryAvailable')">
                  auto_awesome
                </span>
                <!-- Board link indicator with hover popup -->
                <button
                  v-if="getEmailBoard(item)"
                  @click="goToBoard($event, item)"
                  @mouseenter="showBoardPopup($event, item)"
                  @mouseleave="hideBoardPopup"
                  class="p-0.5 rounded-full transition-colors"
                  :style="{ color: getEmailBoard(item).background_color || '#22c55e' }"
                >
                  <span class="material-symbols-rounded text-lg">dashboard</span>
                </button>
                <!-- Unsubscribe button -->
                <button
                  v-if="hasUnsubscribe(item)"
                  @click="initiateUnsubscribe($event, item)"
                  :class="[
                    'p-0.5 rounded-full transition-colors',
                    isUnsubscribed(item) 
                      ? 'text-green-500 cursor-default' 
                      : 'text-amber-500 hover:bg-amber-50 dark:hover:bg-amber-900/30'
                  ]"
                  :title="isUnsubscribed(item) ? $t('emailList.unsubscribed') : $t('emailList.unsubscribe')"
                >
                  <span class="material-symbols-rounded text-lg">{{ isUnsubscribed(item) ? 'check_circle' : 'unsubscribe' }}</span>
                </button>
                <span v-if="item.has_attachment" class="material-symbols-rounded text-surface-400 text-lg" :title="$t('emailList.hasAttachment')">
                  attach_file
                </span>
              </template>
            </div>
            
            <!-- Date (compact column) - clickable to create calendar event -->
            <div class="flex-shrink-0 text-right ml-2 max-w-[9rem] overflow-hidden">
              <!-- Scheduled email: show schedule time with icon -->
              <template v-if="item.isScheduled">
                <span class="flex items-center gap-1 text-xs text-amber-600 dark:text-amber-400 font-medium">
                  <span class="material-symbols-rounded text-sm">schedule_send</span>
                  {{ formatScheduleTime(item.scheduled_at) }}
                </span>
              </template>
              <template v-else>
              <!-- Folder indicator (search results only) -->
              <span 
                v-if="isSearchResults && item.folder_display"
                class="block text-[8px] leading-tight text-surface-400 truncate"
                :title="item.folder_display"
              >
                {{ item.folder_display }}
              </span>
              <span 
                class="block text-xs leading-tight text-surface-500 hover:text-primary-500 hover:underline cursor-pointer transition-colors"
                @click="createEventFromEmail($event, item)"
                :title="$t('emailList.clickToCreateCalendarEvent')"
              >
                {{ formatDate(item.timestamp) }}
              </span>
              </template>
            </div>
          </div>
          
          <!-- MOBILE VERSION - Apple Mail inspired layout with swipe gestures -->
          <div
            v-else-if="layout.isStackedLayout && layout.isMobile"
            class="mobile-swipe-container border-b border-surface-200/50 dark:border-surface-700/50 relative overflow-hidden"
          >
            <!-- Swipe action buttons - Read/Unread + Pin (RIGHT swipe).
                 Fixed-width underlay revealed by the row sliding over it, so the
                 buttons keep full size instead of squashing as you drag. -->
            <div
              v-if="isSwipeActive(item, 'right')"
              class="absolute inset-y-0 left-0 flex items-center"
              :style="`width: ${SWIPE_MAX}px`"
            >
              <button
                @click.stop="onSwipeReadTap(item)"
                class="h-full flex-1 bg-blue-500 flex flex-col items-center justify-center text-white gap-0.5 active:bg-blue-700"
              >
                <span class="material-symbols-rounded text-2xl" :style="isSwipeCommitted(item) ? 'font-variation-settings: \'FILL\' 1' : ''">
                  {{ item.seen ? 'mark_email_unread' : 'drafts' }}
                </span>
                <span class="text-xs font-medium">{{ item.seen ? 'Unread' : 'Read' }}</span>
              </button>
              <button
                @click.stop="onSwipePinTap(item)"
                class="h-full flex-1 bg-amber-500 flex flex-col items-center justify-center text-white gap-0.5 active:bg-amber-700"
              >
                <span class="material-symbols-rounded text-2xl" :style="isSwipeCommitted(item) ? 'font-variation-settings: \'FILL\' 1' : ''">
                  {{ mailbox.isEmailPinned(item.uid, item.folder || mailbox.currentFolder) ? 'keep_off' : 'keep' }}
                </span>
                <span class="text-xs font-medium">{{ mailbox.isEmailPinned(item.uid, item.folder || mailbox.currentFolder) ? 'Unpin' : 'Pin' }}</span>
              </button>
            </div>
            
            <!-- Swipe action background - Delete button (LEFT swipe). Fixed
                 width, revealed by the row sliding over it. -->
            <div
              v-if="isSwipeActive(item, 'left')"
              class="absolute inset-y-0 right-0 flex items-center"
              :style="`width: ${SWIPE_MAX}px`"
            >
              <button
                @click.stop="onSwipeDeleteTap(item)"
                class="h-full w-full bg-red-500 flex flex-col items-center justify-center text-white gap-0.5 active:bg-red-700"
              >
                <span class="material-symbols-rounded text-2xl" :style="isSwipeCommitted(item) ? 'font-variation-settings: \'FILL\' 1' : ''">delete</span>
                <span class="text-xs font-medium">{{ isInTrash ? 'Delete' : 'Trash' }}</span>
              </button>
            </div>
            
            <!-- Slideable email content -->
            <div
              @click="handleEmailClick($event, item)"
              @contextmenu="handleContextMenu($event, item)"
              @touchstart.passive="onSwipeTouchStart($event, item)"
              @touchmove="onSwipeTouchMove($event, item)"
              @touchend="onSwipeTouchEnd($event, item)"
              :class="[
                'mobile-email-item relative',
                (isSelected(item) || isChecked(item))
                  ? 'bg-primary-50 dark:bg-primary-500/10'
                  : 'bg-white dark:bg-[rgb(var(--color-surface))]',
                { 'pinned-item': mailbox.isEmailPinned(item.uid, item.folder || mailbox.currentFolder) },
                { 'swipe-transitioning': swipeState.activeUid !== item.uid }
              ]"
              :style="getSwipeTranslate(item)"
            >
              <div class="flex items-start gap-3 px-4 py-3.5">
                <!-- Leading indicator: selection checkmark (bulk select) or unread dot -->
                <div class="flex-shrink-0" :class="isChecked(item) ? 'w-6 pt-1.5' : 'w-2.5 pt-2'">
                  <span
                    v-if="isChecked(item)"
                    class="material-symbols-rounded text-primary-500 text-2xl leading-none"
                    style="font-variation-settings: 'FILL' 1"
                  >check_circle</span>
                  <span 
                    v-else-if="!item.seen || item.hasUnread" 
                    class="block w-2.5 h-2.5 rounded-full bg-primary-500"
                    :title="$t('emailList.unread')"
                  ></span>
                </div>
                
                <!-- Content -->
                <div class="flex-1 min-w-0">
                  <!-- Row 1: Sender + Date -->
                  <div class="flex items-center justify-between gap-3 mb-1">
                    <div class="flex items-center gap-1.5 min-w-0 flex-1">
                      <!-- Reply indicator (before sender, like Gmail) -->
                      <span
                        v-if="item.answered"
                        class="material-symbols-rounded text-base text-primary-500 dark:text-primary-400 flex-shrink-0 -mr-0.5"
                        style="font-variation-settings: 'wght' 500"
                        :title="$t('emailList.replied')"
                      >reply</span>
                      <span :class="['truncate text-[1.0625rem] leading-tight', (!item.seen || item.hasUnread) ? 'text-surface-900 dark:text-white font-semibold' : 'text-surface-700 dark:text-surface-200 font-medium']">
                        {{ fromDisplay(item) }}
                      </span>
                      <!-- Folder badge (All Mail view) -->
                      <span 
                        v-if="isAllMailView && item.folder"
                        class="flex-shrink-0 max-w-[80px] truncate px-1.5 py-0.5 text-[0.625rem] font-medium bg-surface-100 dark:bg-surface-700 text-surface-500 dark:text-surface-400 rounded cursor-pointer hover:bg-surface-200 dark:hover:bg-surface-600 hover:text-surface-700 dark:hover:text-surface-200 transition-colors"
                        :title="$t('emailList.goToFolder', { folder: item.folder })"
                        @click.stop="goToFolder(item.folder)"
                      >{{ getMessageFolderName(item) }}</span>
                      <!-- Conversation count badge -->
                      <span 
                        v-if="item.isConversation && item.messageCount > 1"
                        class="flex-shrink-0 h-6 px-2.5 inline-flex items-center text-[0.6875rem] font-medium border border-primary-500/30 bg-primary-500/10 text-primary-600 dark:text-primary-400 rounded-full"
                        :title="item.threadLoaded ? $t('emailList.messages', { count: item.messageCount }) : $t('emailList.loadingFullThread')"
                      >
                        {{ item.messageCount }}{{ !item.threadLoaded && item.threadReferences?.length > 0 ? '+' : '' }}
                      </span>
                      <!-- Split conversation indicator -->
                      <span 
                        v-if="item.isSplit"
                        class="flex-shrink-0 h-6 w-6 inline-flex items-center justify-center rounded-full bg-surface-200 dark:bg-surface-700 text-surface-500"
                        :title="$t('emailList.splitConversation')"
                      >
                        <span class="material-symbols-rounded text-sm">call_split</span>
                      </span>
                    </div>
                    <div class="flex items-center gap-1 flex-shrink-0">
                      <template v-if="item.isScheduled">
                        <span class="flex items-center gap-1 text-xs text-amber-600 dark:text-amber-400 font-medium">
                          <span class="material-symbols-rounded text-sm">schedule_send</span>
                          {{ formatScheduleTime(item.scheduled_at) }}
                        </span>
                      </template>
                      <template v-else>
                      <span 
                        class="text-[0.9375rem] text-surface-500 dark:text-surface-400 hover:text-primary-500 hover:underline cursor-pointer transition-colors"
                        @click="createEventFromEmail($event, item)"
                        :title="$t('emailList.clickToCreateCalendarEvent')"
                      >
                        {{ formatDate(item.timestamp) }}
                      </span>
                      <span class="material-symbols-rounded text-lg text-surface-400">chevron_right</span>
                      </template>
                    </div>
                  </div>
                  
                  <!-- Row 2: Subject (+ attachment icon aligned right, under the date) -->
                  <div class="flex items-center gap-2 mb-0.5">
                    <!-- Important indicator -->
                    <span
                      v-if="item.important"
                      class="material-symbols-rounded text-red-500 text-base flex-shrink-0"
                      style="font-variation-settings: 'FILL' 1"
                      :title="$t('emailList.important')"
                    >priority_high</span>
                    <!-- Star indicator -->
                    <span 
                      v-if="item.flagged || item.hasStarred" 
                      class="material-symbols-rounded text-amber-400 text-base flex-shrink-0"
                      style="font-variation-settings: 'FILL' 1"
                    >star</span>
                    <span :class="['truncate text-[0.9375rem] leading-snug flex-1 min-w-0', (!item.seen || item.hasUnread) ? 'text-surface-800 dark:text-surface-100 font-medium' : 'text-surface-600 dark:text-surface-400']">
                      {{ item.subject || '(No subject)' }}
                    </span>
                    <!-- Attachment icon: sits under the date so an attachment never costs a third line -->
                    <span v-if="item.has_attachment" class="material-symbols-rounded text-surface-400 text-base flex-shrink-0" :title="$t('emailList.hasAttachment')">
                      attach_file
                    </span>
                  </div>
                  
                  <!-- Row 3: Preview text / Actions for scheduled (collapses when empty) -->
                  <div class="flex items-center gap-2">
                    <template v-if="item.isScheduled">
                      <span class="text-xs text-surface-400">{{ $t('emailList.tapToEdit') }}</span>
                      <button
                        @click="cancelScheduledEmail($event, item)"
                        :disabled="cancellingScheduleId === item.schedule_id"
                        class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-medium bg-red-50 dark:bg-red-900/20 text-red-600 dark:text-red-400 hover:bg-red-100 dark:hover:bg-red-900/40 transition-colors"
                      >
                        <span v-if="cancellingScheduleId === item.schedule_id" class="spinner-xs"></span>
                        <span v-else class="material-symbols-rounded text-sm">close</span>
                        Cancel
                      </button>
                    </template>
                    <template v-else>
                    <span class="text-[0.9375rem] text-surface-500 dark:text-surface-500 truncate leading-snug">
                      {{ item.body_preview || item.snippet || '' }}
                    </span>
                    </template>
                  </div>
                </div>
              </div>
            </div>
          </div>
          
          <!-- Main email/conversation row - 3-column layout (single row aligned) -->
          <div
            v-else
            @click="handleEmailClick($event, item)"
            @contextmenu="handleContextMenu($event, item)"
            draggable="true"
            @dragstart="onDragStart($event, item)"
            @dragend="onDragEnd"
            @dragover="onConversationDragOver($event, item)"
            @dragleave="onConversationDragLeave($event, item)"
            @drop="onConversationDrop($event, item)"
            :class="[
              'email-item-compact cursor-grab active:cursor-grabbing',
              { 'selected': isSelected(item) || isChecked(item), 'unread': !item.seen || item.hasUnread },
              { 'conversation-drop-target': dragOverConversation && dragOverConversation === (item.conversationKey || item.message_id) },
              { 'pinned-item': mailbox.isEmailPinned(item.uid, item.folder || mailbox.currentFolder) }
            ]"
          >
            <!-- Conversation expand/collapse arrow (far left) -->
            <button
              v-if="item.isConversation && item.messageCount > 1"
              @click.stop="mailbox.toggleConversationExpanded(item.conversationKey)"
              class="w-6 flex-shrink-0 flex items-center justify-center text-surface-400 hover:text-surface-600 dark:hover:text-surface-200 transition-colors"
              :title="mailbox.isConversationExpanded(item.conversationKey) ? $t('emailList.collapse') : $t('emailList.expand')"
            >
              <span class="material-symbols-rounded text-lg transition-transform" :class="{ 'rotate-90': !mailbox.isConversationExpanded(item.conversationKey) }">
                expand_more
              </span>
            </button>
            <span v-else class="w-6 flex-shrink-0"></span>
            
            <!-- Unread indicator dot -->
            <span 
              v-if="!item.seen || item.hasUnread" 
              class="unread-dot"
              :title="$t('emailList.unread')"
            ></span>
            <span v-else class="unread-dot-spacer"></span>
            
            <!-- Action buttons group -->
            <div class="flex-shrink-0 flex items-center gap-0.5 pr-1" @click.stop>
              <!-- Checkbox -->
              <button 
                type="button"
                @click.stop="toggleSelect($event, item, item.isConversation)"
                class="flex-shrink-0 w-7 h-7 flex items-center justify-center rounded transition-colors text-surface-400 hover:text-surface-600 dark:hover:text-surface-200 hover:bg-surface-100 dark:hover:bg-surface-700"
                :title="item.isConversation ? $t('emailList.ctrlClickToSelectAll') : ''"
              >
                <span class="material-symbols-rounded text-xl leading-none">
                  {{ isChecked(item) ? 'check_box' : 'check_box_outline_blank' }}
                </span>
              </button>
              
              <!-- Pin -->
              <button 
                type="button"
                @click.stop="togglePin($event, item)"
                :class="[
                  'flex-shrink-0 w-7 h-7 flex items-center justify-center rounded transition-colors hover:bg-surface-100 dark:hover:bg-surface-700',
                  mailbox.isEmailPinned(item.uid, item.folder || mailbox.currentFolder)
                    ? 'text-primary-500 hover:text-primary-600' 
                    : 'text-surface-300 hover:text-primary-300/60 dark:text-surface-600 dark:hover:text-primary-400/40'
                ]"
                :title="mailbox.isEmailPinned(item.uid, item.folder || mailbox.currentFolder) ? $t('emailList.unpin') : $t('emailList.pin')"
              >
                <span class="material-symbols-rounded text-xl leading-none" :style="mailbox.isEmailPinned(item.uid, item.folder || mailbox.currentFolder) ? 'font-variation-settings: \'FILL\' 1' : ''">
                  push_pin
                </span>
              </button>
              
              <!-- Star -->
              <button 
                type="button"
                @click.stop="toggleStar($event, item)"
                :class="[
                  'flex-shrink-0 w-7 h-7 flex items-center justify-center rounded transition-colors hover:bg-surface-100 dark:hover:bg-surface-700',
                  item.flagged || item.hasStarred
                    ? 'text-amber-400 hover:text-amber-500' 
                    : 'text-surface-300 hover:text-amber-300/60 dark:text-surface-600 dark:hover:text-amber-400/40'
                ]"
              >
                <span class="material-symbols-rounded text-xl leading-none" :style="item.flagged || item.hasStarred ? 'font-variation-settings: \'FILL\' 1' : ''">
                  star
                </span>
              </button>
            </div>
            
            <!-- Sender (fixed width column) -->
            <div class="w-64 flex-shrink-0 flex items-center gap-1.5 min-w-0">
              <!-- Reply indicator (before sender, like Gmail) -->
              <span
                v-if="item.answered"
                class="material-symbols-rounded text-base text-primary-500 dark:text-primary-400 flex-shrink-0 -mr-0.5"
                style="font-variation-settings: 'wght' 500"
                :title="$t('emailList.replied')"
              >reply</span>
              <span :class="['truncate text-sm', (!item.seen || item.hasUnread) ? 'text-surface-900 dark:text-surface-100 font-medium' : 'text-surface-600 dark:text-surface-300']">
                {{ fromDisplay(item) }}
              </span>
              <!-- Folder badge (All Mail view) -->
              <span 
                v-if="isAllMailView && item.folder"
                class="flex-shrink-0 max-w-[6rem] truncate px-1.5 py-0.5 text-[0.625rem] font-medium bg-surface-100 dark:bg-surface-700 text-surface-500 dark:text-surface-400 rounded cursor-pointer hover:bg-surface-200 dark:hover:bg-surface-600 hover:text-surface-700 dark:hover:text-surface-200 transition-colors"
                :title="$t('emailList.goToFolder', { folder: item.folder })"
                @click.stop="goToFolder(item.folder)"
              >{{ getMessageFolderName(item) }}</span>
              <!-- Linked account indicator -->
              <span 
                v-if="item.linked_account"
                class="flex-shrink-0 material-symbols-rounded text-sm text-primary-500"
                :title="$t('emailList.syncedFrom', { account: item.linked_account })"
              >link</span>
            </div>
            
            <!-- Subject + Preview (flexible, takes all remaining space) -->
            <div class="flex-1 min-w-0 flex items-center gap-1.5">
              <!-- Reaction indicator -->
              <span 
                v-if="item.is_reaction_email && item.reaction_emoji" 
                class="flex-shrink-0 text-base" 
                :title="$t('emailList.reaction', { emoji: item.reaction_emoji })"
              >
                {{ item.reaction_emoji }}
              </span>
              <!-- Subject and preview in single truncating container -->
              <div class="flex-1 min-w-0 truncate text-sm">
                <span
                  v-if="item.important"
                  class="material-symbols-rounded text-red-500 text-base align-middle"
                  style="font-variation-settings: 'FILL' 1"
                  :title="$t('emailList.important')"
                >priority_high</span>
                <span :class="[(!item.seen || item.hasUnread) ? 'text-surface-900 dark:text-surface-100 font-medium' : 'text-surface-600 dark:text-surface-400']">{{ item.subject || '(No subject)' }}</span>
                <span v-if="item.body_preview || item.snippet" class="text-surface-400 dark:text-surface-500"> - {{ item.body_preview || item.snippet }}</span>
              </div>
            </div>
            
            <!-- Metadata cell: labels + badges + icons (fixed, aligned right) -->
            <div class="flex-shrink-0 flex items-center gap-1 overflow-hidden">
              <!-- Labels -->
              <template v-if="item.labels?.length">
                <span
                  v-for="label in item.labels.slice(0, 2)"
                  :key="label.id"
                  class="inline-flex items-center h-5 px-2 rounded-full text-[0.625rem] font-medium text-white"
                  :style="{ backgroundColor: label.color }"
                >
                  {{ label.name }}
                </span>
                <span v-if="item.labels.length > 2" class="text-[0.625rem] text-surface-500">+{{ item.labels.length - 2 }}</span>
              </template>
              <!-- Conversation count badge -->
              <span 
                v-if="item.isConversation && item.messageCount > 1"
                class="h-5 px-2 inline-flex items-center text-[0.625rem] font-medium border border-primary-500/30 bg-primary-500/10 text-primary-600 dark:text-primary-400 rounded-full"
                :title="$t('emailList.messagesInConversation', { count: item.messageCount })"
              >
                {{ item.messageCount }}
              </span>
              <!-- Split conversation indicator -->
              <span 
                v-if="item.isSplit"
                class="h-5 w-5 inline-flex items-center justify-center rounded-full bg-surface-200 dark:bg-surface-700 text-surface-500"
                :title="$t('emailList.splitConversation')"
              >
                <span class="material-symbols-rounded text-xs">call_split</span>
              </span>
              <!-- Reactions inline -->
              <ReactionDisplay 
                v-if="item.message_id"
                :message-id="item.message_id"
                compact
                class="flex-shrink-0"
              />
              <!-- Scheduled cancel or action icons -->
              <template v-if="item.isScheduled">
                <button
                  @click="cancelScheduledEmail($event, item)"
                  :disabled="cancellingScheduleId === item.schedule_id"
                  class="inline-flex items-center gap-0.5 px-2 py-0.5 rounded-full text-xs font-medium bg-red-50 dark:bg-red-900/20 text-red-600 dark:text-red-400 hover:bg-red-100 dark:hover:bg-red-900/40 transition-colors"
                  :title="$t('emailList.cancelScheduledEmail')"
                >
                  <span v-if="cancellingScheduleId === item.schedule_id" class="spinner-xs"></span>
                  <span v-else class="material-symbols-rounded text-sm">close</span>
                </button>
              </template>
              <template v-else>
                <!-- AI Summary indicator -->
                <span v-if="hasCachedSummary(item)" class="material-symbols-rounded text-green-500 text-base" :title="$t('emailList.aiSummaryAvailable')">
                  auto_awesome
                </span>
                <!-- Board link indicator -->
                <button
                  v-if="getEmailBoard(item)"
                  @click="goToBoard($event, item)"
                  @mouseenter="showBoardPopup($event, item)"
                  @mouseleave="hideBoardPopup"
                  class="p-0.5 rounded-full transition-colors"
                  :style="{ color: getEmailBoard(item).background_color || '#22c55e' }"
                >
                  <span class="material-symbols-rounded text-base">dashboard</span>
                </button>
                <!-- Unsubscribe button -->
                <button
                  v-if="hasUnsubscribe(item)"
                  @click="initiateUnsubscribe($event, item)"
                  :class="[
                    'p-0.5 rounded-full transition-colors',
                    isUnsubscribed(item) 
                      ? 'text-green-500 cursor-default' 
                      : 'text-amber-500 hover:bg-amber-50 dark:hover:bg-amber-900/30'
                  ]"
                  :title="isUnsubscribed(item) ? $t('emailList.unsubscribed') : $t('emailList.unsubscribe')"
                >
                  <span class="material-symbols-rounded text-base">{{ isUnsubscribed(item) ? 'check_circle' : 'unsubscribe' }}</span>
                </button>
                <!-- Attachment icon -->
                <span v-if="item.has_attachment" class="material-symbols-rounded text-surface-400 text-base" :title="$t('emailList.hasAttachment')">
                  attach_file
                </span>
              </template>
            </div>
            
            <!-- Date (compact column) - clickable to create calendar event -->
            <div class="flex-shrink-0 text-right ml-2 max-w-[9rem] overflow-hidden">
              <!-- Scheduled email: show schedule time -->
              <template v-if="item.isScheduled">
                <span class="flex items-center gap-1 text-xs text-amber-600 dark:text-amber-400 font-medium">
                  <span class="material-symbols-rounded text-sm">schedule_send</span>
                  {{ formatScheduleTime(item.scheduled_at) }}
                </span>
              </template>
              <template v-else>
              <!-- Folder indicator (search results only) -->
              <span 
                v-if="isSearchResults && item.folder_display"
                class="block text-[8px] leading-tight text-surface-400 truncate"
                :title="item.folder_display"
              >
                {{ item.folder_display }}
              </span>
              <span 
                class="block text-xs leading-tight text-surface-500 hover:text-primary-500 hover:underline cursor-pointer transition-colors"
                @click="createEventFromEmail($event, item)"
                :title="$t('emailList.clickToCreateCalendarEvent')"
              >
                {{ formatDate(item.timestamp) }}
              </span>
              </template>
            </div>
          </div>
          
          <!-- Expanded conversation messages -->
          <div 
            v-if="item.isConversation && mailbox.isConversationExpanded(item.conversationKey)"
            class="ml-6 bg-surface-50 dark:bg-surface-900/50 border-l-2 border-primary-200 dark:border-primary-800"
          >
            <div
              v-for="(msg, idx) in mailbox.getConversationMessages(item.conversationKey)"
              :key="getItemKey(msg)"
              @click="handleEmailClick($event, msg, true)"
              @contextmenu="handleContextMenu($event, msg, { conversationKey: item.conversationKey, messageCount: item.messageCount })"
              draggable="true"
              @dragstart="onMessageDragStart($event, msg, item.conversationKey)"
              @dragend="onDragEnd"
              :class="[
                'email-item !pl-3 cursor-pointer',
                { 'selected': isSelected(msg) || isChecked(msg), 'unread': !msg.seen && msg.folder && msg.folder.toLowerCase() === mailbox.currentFolder.toLowerCase() },
                isOurMessage(msg) ? 'thread-msg-sent' : 'thread-msg-received',
                { 'pinned-item': mailbox.isEmailPinned(msg.uid, msg.folder || mailbox.currentFolder) }
              ]"
            >
              <!-- Thread line indicator -->
              <div class="flex-shrink-0 w-4 flex items-center justify-center">
                <div :class="['w-0.5 h-full', idx === mailbox.getConversationMessages(item.conversationKey).length - 1 ? 'h-1/2 self-start' : '']" style="background: rgb(var(--color-primary-300))"></div>
              </div>
              
              <!-- Sent/Received indicator -->
              <span 
                v-if="isOurMessage(msg)"
                class="thread-direction-indicator sent"
                :title="$t('emailList.sentByYou')"
              >
                <span class="material-symbols-rounded text-xs">arrow_upward</span>
              </span>
              <span 
                v-else
                class="thread-direction-indicator received"
                :title="$t('emailList.received')"
              >
                <span class="material-symbols-rounded text-xs">arrow_downward</span>
              </span>
              
              <!-- Unread indicator dot (smaller for nested) -->
              <span 
                v-if="!msg.seen && msg.folder && msg.folder.toLowerCase() === mailbox.currentFolder.toLowerCase()" 
                class="unread-dot-nested"
                :title="$t('emailList.unread')"
              ></span>
              <span v-else class="unread-dot-spacer-nested"></span>
              
              <!-- Checkbox -->
              <button 
                type="button"
                @click.stop="toggleThreadMsgSelect($event, msg, mailbox.getConversationMessages(item.conversationKey), idx, item.conversationKey)"
                class="flex-shrink-0 w-5 h-5 flex items-center justify-center p-0 text-surface-400 hover:text-surface-600 dark:hover:text-surface-200"
                :title="$t('emailList.shiftclickToSelectRange')"
              >
                <span class="material-symbols-rounded text-lg leading-none">
                  {{ isChecked(msg) ? 'check_box' : 'check_box_outline_blank' }}
                </span>
              </button>
              
              <!-- Content -->
              <div class="flex-1 min-w-0 py-1">
                <div class="flex items-center justify-between gap-2">
                  <div class="flex items-center gap-2 min-w-0">
                    <span :class="[
                      'truncate text-xs',
                      isOurMessage(msg) ? 'text-primary-600 dark:text-primary-400' : '',
                      !isOurMessage(msg) && msg.seen ? 'text-surface-500 dark:text-surface-400' : '',
                      !isOurMessage(msg) && !msg.seen ? 'text-surface-800 dark:text-surface-200 font-medium' : ''
                    ]">
                      {{ fromDisplay(msg) }}
                    </span>
                    <span
                      v-if="msg.folder && msg.folder !== mailbox.currentFolder"
                      class="inline-flex items-center px-1.5 py-0.5 rounded text-[0.5625rem] font-medium bg-surface-200 dark:bg-surface-700 text-surface-500 dark:text-surface-400 cursor-pointer hover:bg-surface-300 dark:hover:bg-surface-600 hover:text-surface-700 dark:hover:text-surface-200 transition-colors"
                      :title="$t('emailList.goToFolder', { folder: msg.folder })"
                      @click.stop="goToFolder(msg.folder)"
                    >{{ getMessageFolderName(msg) }}</span>
                    <!-- Labels for expanded message -->
                    <div v-if="msg.labels?.length" class="flex flex-wrap gap-1">
                      <span
                        v-for="label in msg.labels"
                        :key="label.id"
                        class="inline-flex items-center px-1.5 py-0.5 rounded text-[0.5625rem] font-medium text-white"
                        :style="{ backgroundColor: label.color }"
                      >
                        {{ label.name }}
                      </span>
                    </div>
                  </div>
                  <div class="flex items-center gap-1.5 flex-shrink-0">
                    <span v-if="msg.has_attachment" class="material-symbols-rounded text-surface-400 text-sm">
                      attach_file
                    </span>
                    <span 
                      class="text-[0.625rem] text-surface-400 hover:text-primary-500 hover:underline cursor-pointer transition-colors"
                      @click="createEventFromEmail($event, msg)"
                      :title="$t('emailList.clickToCreateCalendarEvent')"
                    >
                      {{ formatDate(msg.timestamp) }}
                    </span>
                  </div>
                </div>
              </div>
            </div>
          </div>
          </template>
          </template>
        </template>
      </div>
      </div><!-- /ptr-content -->
    </div>
    
    <!-- Pagination - same height as settings bar -->
    <div class="h-11 flex items-center justify-between px-4 border-t border-surface-200 dark:border-[rgb(var(--color-border))]">
      <span class="text-xs text-surface-500">
        Page {{ mailbox.pagination.page }} of {{ mailbox.pagination.pages }}
      </span>
      <div class="flex items-center gap-1">
        <button 
          @click="prevPage" 
          :disabled="mailbox.pagination.page <= 1"
          class="btn-ghost btn-sm btn-icon"
        >
          <span class="material-symbols-rounded">chevron_left</span>
        </button>
        <button 
          @click="nextPage" 
          :disabled="mailbox.pagination.page >= mailbox.pagination.pages"
          class="btn-ghost btn-sm btn-icon"
        >
          <span class="material-symbols-rounded">chevron_right</span>
        </button>
      </div>
    </div>
    
    <!-- Context Menu -->
    <EmailContextMenu
      :show="showContextMenu"
      :x="contextMenuX"
      :y="contextMenuY"
      :message="contextMenuMessage"
      @close="closeContextMenu"
    />
    
    <!-- Permanent Delete Confirmation -->
    <ConfirmModal
      :show="showDeleteSelectedConfirm"
      :title="$t('emailList.deleteConfirmTitle')"
      :message="$t('emailList.deleteConfirmMessage')"
      :confirmText="$t('emailList.deletePermanently')"
      type="danger"
      @confirm="deleteSelectedPermanently"
      @cancel="showDeleteSelectedConfirm = false"
    />
    
    <!-- Unsubscribe Confirmation -->
    <ConfirmModal
      :show="showUnsubscribeConfirm"
      :title="$t('emailList.unsubscribe')"
      :message="unsubscribingMessage ? `${$t('emailList.unsubscribe')} ${getSenderDisplay(unsubscribingMessage)}?` : $t('emailList.unsubscribe')"
      :confirm-text="unsubscribing ? '...' : $t('emailList.unsubscribe')"
      type="warning"
      :loading="unsubscribing"
      @confirm="executeUnsubscribe"
      @cancel="cancelUnsubscribe"
    />
    
    <!-- Second confirmation after URL opened -->
    <ConfirmModal
      :show="showUnsubscribeUrlConfirm"
      :title="$t('emailList.didYouCompleteTheUnsubscribe')"
      :message="$t('emailList.didYouCompleteTheUnsubscribe')"
      :confirm-text="$t('emailList.unsubscribed')"
      cancel-text=""
      type="info"
      @confirm="confirmUrlUnsubscribe"
      @cancel="cancelUrlUnsubscribe"
    />
    
    <!-- Board Link Popup -->
    <Teleport to="body">
      <Transition name="popup">
        <div 
          v-if="boardPopup.show && boardPopup.board"
          class="fixed z-[200] transform -translate-x-1/2 -translate-y-full pointer-events-auto"
          :style="{ left: boardPopup.x + 'px', top: boardPopup.y + 'px' }"
          @mouseenter="keepBoardPopupOpen"
          @mouseleave="closeBoardPopup"
        >
          <div 
            class="bg-white dark:bg-surface-800 rounded-lg shadow-xl border border-surface-200 dark:border-surface-700 overflow-hidden cursor-pointer hover:shadow-2xl transition-shadow"
            @click="handleBoardPopupClick"
          >
            <!-- Board color bar -->
            <div 
              class="h-1.5"
              :style="{ backgroundColor: boardPopup.board.background_color || '#22c55e' }"
            ></div>
            
            <div class="px-3 py-2.5 flex items-center gap-2.5">
              <span 
                class="material-symbols-rounded text-xl"
                :style="{ color: boardPopup.board.background_color || '#22c55e' }"
              >dashboard</span>
              <div class="min-w-0">
                <div class="text-xs text-surface-500 dark:text-surface-400">Linked to Board</div>
                <div class="font-medium text-sm text-surface-900 dark:text-surface-100 truncate max-w-48">
                  {{ boardPopup.board.board_name }}
                </div>
              </div>
              <span class="material-symbols-rounded text-surface-400 text-sm ml-1">open_in_new</span>
            </div>
          </div>
          <!-- Arrow pointer -->
          <div class="absolute left-1/2 -translate-x-1/2 -bottom-1.5 w-3 h-3 bg-white dark:bg-surface-800 border-r border-b border-surface-200 dark:border-surface-700 rotate-45"></div>
        </div>
      </Transition>
    </Teleport>
  </div>
</template>

<style scoped>
/* Pull-to-refresh */
.ptr-indicator {
  position: absolute;
  top: 0;
  left: 0;
  right: 0;
  height: 48px;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 0.5rem;
  z-index: 5;
  will-change: transform;
  pointer-events: none;
}
.ptr-content {
  min-height: 100%;
  will-change: transform;
}
.ptr-releasing {
  transition: transform 0.3s cubic-bezier(0.2, 0, 0, 1);
}
@keyframes ptr-rotate {
  to { transform: rotate(360deg); }
}
.ptr-spin {
  animation: ptr-rotate 0.7s linear infinite;
}

/* Conversation drag & drop */
.conversation-drop-target {
  background-color: rgb(var(--color-primary-100)) !important;
  outline: 2px dashed rgb(var(--color-primary-500));
  outline-offset: -2px;
}

:deep(.dark) .conversation-drop-target {
  background-color: rgba(var(--color-primary-900), 0.5) !important;
  outline-color: rgb(var(--color-primary-400));
}

/* Pinned email item styling */
.pinned-item {
  background-color: rgba(251, 191, 36, 0.08) !important; /* amber-400 with low opacity */
  border-left: 3px solid rgb(245, 158, 11) !important; /* amber-500 */
}

.pinned-item:hover {
  background-color: rgba(251, 191, 36, 0.12) !important;
}

:deep(.dark) .pinned-item {
  background-color: rgba(251, 191, 36, 0.06) !important;
  border-left: 3px solid rgb(245, 158, 11) !important;
}

:deep(.dark) .pinned-item:hover {
  background-color: rgba(251, 191, 36, 0.1) !important;
}

/* Split drop zone (when dragging to make standalone) */
.split-drop-zone-active {
  background-color: rgba(var(--color-primary-100), 0.3);
  position: relative;
}

.split-drop-zone-active::before {
  content: 'Drop to create standalone email';
  position: fixed;
  bottom: 80px;
  left: 50%;
  transform: translateX(-50%);
  background: rgb(var(--color-primary-500));
  color: white;
  padding: 8px 16px;
  border-radius: 8px;
  font-size: 14px;
  font-weight: 500;
  z-index: 100;
  box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2);
}

:deep(.dark) .split-drop-zone-active {
  background-color: rgba(var(--color-primary-900), 0.2);
}

/* Drop between zone (for ungrouping) */
.drop-between-zone {
  height: 8px;
  margin: 0;
  position: relative;
  transition: height 0.15s ease, background-color 0.15s ease;
  cursor: pointer;
}

.drop-between-zone::before {
  content: '';
  position: absolute;
  left: 40px;
  right: 40px;
  top: 50%;
  height: 2px;
  background: rgba(var(--color-primary-500), 0.3);
  border-radius: 1px;
  transform: translateY(-50%);
  transition: all 0.15s ease;
}

.drop-between-zone.drop-between-active {
  height: 32px;
  background: rgba(var(--color-primary-500), 0.1);
}

.drop-between-zone.drop-between-active::before {
  height: 4px;
  background: rgb(var(--color-primary-500));
  box-shadow: 0 0 12px rgba(var(--color-primary-500), 0.6);
}

.drop-between-zone.drop-between-active::after {
  content: 'Drop to ungroup';
  position: absolute;
  left: 50%;
  top: 50%;
  transform: translate(-50%, -50%);
  background: rgb(var(--color-primary-500));
  color: white;
  padding: 4px 12px;
  border-radius: 6px;
  font-size: 12px;
  font-weight: 500;
  white-space: nowrap;
  z-index: 10;
  box-shadow: 0 2px 8px rgba(0, 0, 0, 0.2);
}

:deep(.dark) .drop-between-zone::before {
  background: rgba(var(--color-primary-400), 0.2);
}

:deep(.dark) .drop-between-zone.drop-between-active {
  background: rgba(var(--color-primary-500), 0.15);
}

:deep(.dark) .drop-between-zone.drop-between-active::before {
  background: rgb(var(--color-primary-400));
}

/* Board popup transition */
.popup-enter-active,
.popup-leave-active {
  transition: all 0.15s ease;
}
.popup-enter-from,
.popup-leave-to {
  opacity: 0;
  transform: translate(-50%, calc(-100% + 8px));
}

/* Mobile email item styles - Apple Mail inspired */
@media (max-width: 767px) {
  .mobile-email-item {
    cursor: pointer;
    transition: background-color 0.15s ease;
  }
  
  .mobile-email-item:active {
    background-color: rgba(0, 0, 0, 0.05);
  }
  
  :deep(.dark) .mobile-email-item:active {
    background-color: rgba(255, 255, 255, 0.05);
  }
}

/* Mobile swipe gesture styles (iOS-style) */
.mobile-swipe-container {
  touch-action: pan-y;
  -webkit-user-select: none;
  user-select: none;
}

.mobile-email-item.swipe-transitioning {
  transition: transform 0.3s cubic-bezier(0.25, 0.46, 0.45, 0.94);
}

/* Slim top progress bar (Gmail / Material indeterminate style).
   Sits between the toolbar and the list, signalling refreshes without
   removing the email rows from view. */
.email-list-progress {
  position: relative;
  height: 2px;
  width: 100%;
  overflow: hidden;
  background: transparent;
  flex-shrink: 0;
}

.email-list-progress__bar {
  position: absolute;
  top: 0;
  left: 0;
  height: 100%;
  width: 40%;
  background: linear-gradient(90deg, transparent, var(--p-primary-500, #8b5cf6), transparent);
  border-radius: 1px;
  animation: email-list-progress-slide 1.1s cubic-bezier(0.4, 0, 0.2, 1) infinite;
}

@keyframes email-list-progress-slide {
  0%   { transform: translateX(-100%); }
  100% { transform: translateX(350%); }
}

@media (prefers-reduced-motion: reduce) {
  .email-list-progress__bar { animation-duration: 2.2s; }
}
</style>
