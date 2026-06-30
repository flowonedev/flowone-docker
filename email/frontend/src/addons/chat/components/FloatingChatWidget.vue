<script setup>
import { ref, computed, watch, onMounted, onUnmounted, nextTick } from 'vue'
import { useRoute } from 'vue-router'
import { useChatStore } from '@/addons/chat/stores/chat'
import { useCallStore } from '@/stores/call'
import { useCallLauncher } from '@/composables/useCallLauncher'
import { useHuddleStore } from '@/stores/huddle'
import { useColleaguesStore } from '@/addons/team/stores/colleagues'
import { useAuthStore } from '@/stores/auth'
import { useToastStore } from '@/stores/toast'
import { useNotificationsStore } from '@/stores/notifications'
import { usePresence } from '@/composables/usePresence'
import { useChatPresence } from '@/composables/useChatPresence'
import ChatMessage from './ChatMessage.vue'
import ChatInput from './ChatInput.vue'
import ChatSettingsModal from './ChatSettingsModal.vue'
import ChatAttachmentsPanel from './ChatAttachmentsPanel.vue'
import ViewTogetherBanner from './ViewTogetherBanner.vue'
import GroupChatModal from './GroupChatModal.vue'
import ChatInvitations from './ChatInvitations.vue'
import NewConversationModal from './NewConversationModal.vue'
import ChannelCreateModal from './ChannelCreateModal.vue'
import ThreadPanel from './ThreadPanel.vue'
import UserAvatar from '@/components/shared/UserAvatar.vue'

const chatStore = useChatStore()
const callStore = useCallStore()
const callLauncher = useCallLauncher()
const huddleStore = useHuddleStore()
const toast = useToastStore()
const colleaguesStore = useColleaguesStore()
const auth = useAuthStore()
const notificationsStore = useNotificationsStore()
const route = useRoute()
const presence = usePresence()
const { getStatusColor, getStatusLabel } = useChatPresence()

// Combined badge count: chat unread + missed calls + pending invitations
const widgetBadgeCount = computed(() => {
  return (chatStore.totalUnread || 0) + (notificationsStore.missedCallUnreadCount || 0) + (chatStore.pendingInvitations?.length || 0)
})

// Status picker
const showStatusPicker = ref(false)

// Don't show on the full chat view or moodboard canvas (overlaps sidebar controls)
const isOnChatView = computed(() => route.path === '/chat')
const isOnMoodBoard = computed(() => route.name === 'mood-board' || route.name === 'shared-mood-board')

// Widget state
const isExpanded = ref(false)
const isMaximized = ref(false) // Zen mode - full height
const activeTab = ref('conversations') // 'conversations' | 'chat'
const selectedConversationId = ref(null)

// Dragging
const isDragging = ref(false)

// Position & Size (saved to localStorage)
// Position uses `right` (distance from right edge) + `bottom` (distance from bottom edge)
const STORAGE_KEY = 'floatingChatWidget'
const position = ref({ right: 24, bottom: 24 })
const size = ref({ width: 430, height: 520 })

// Mobile detection
const isMobile = ref(window.innerWidth < 640)

function updateMobileState() {
  isMobile.value = window.innerWidth < 640
  // Reset maximize state on mobile (already fullscreen)
  if (isMobile.value) {
    isMaximized.value = false
  }
}

// Load saved position/size
function loadSavedState() {
  try {
    const saved = localStorage.getItem(STORAGE_KEY)
    if (saved) {
      const data = JSON.parse(saved)
      if (data.position) {
        // Migrate old left-based position to right-based
        if (data.position.x !== undefined && data.position.right === undefined) {
          data.position.right = Math.max(24, window.innerWidth - data.position.x - size.value.width)
        }
        // Migrate old top-based (y) position to bottom-based
        if (data.position.y !== undefined && data.position.bottom === undefined) {
          data.position.bottom = Math.max(24, window.innerHeight - data.position.y - size.value.height)
        }
        position.value = { right: data.position.right || 24, bottom: data.position.bottom || 24 }
      }
      if (data.size) size.value = data.size
      if (data.isExpanded !== undefined) isExpanded.value = data.isExpanded
      if (data.isMaximized !== undefined) isMaximized.value = data.isMaximized
    }
  } catch (e) {
    console.warn('Failed to load floating chat state:', e)
  }
  
  if (!position.value.right) {
    position.value.right = 24
  }
  if (!position.value.bottom) {
    position.value.bottom = 24
  }
  
  constrainPosition()
}

function saveState() {
  try {
    localStorage.setItem(STORAGE_KEY, JSON.stringify({
      position: position.value,
      size: size.value,
      isExpanded: isExpanded.value,
      isMaximized: isMaximized.value
    }))
  } catch (e) {
    console.warn('Failed to save floating chat state:', e)
  }
}

function constrainPosition() {
  const maxRight = window.innerWidth - size.value.width - 16
  const maxBottom = window.innerHeight - size.value.height - 16
  position.value.right = Math.max(16, Math.min(position.value.right, maxRight))
  position.value.bottom = Math.max(16, Math.min(position.value.bottom, maxBottom))
}

// Dragging handlers — direct DOM manipulation for instant response
const widgetEl = ref(null)
let _dragOffX = 0
let _dragOffY = 0
let _dragRight = 0
let _dragBottom = 0
let _rafId = 0

function startDrag(e) {
  if (isMobile.value || isMaximized.value) return
  if (e.target.closest('button') || e.target.closest('input') || e.target.closest('textarea')) return

  isDragging.value = true
  const widgetLeft = window.innerWidth - position.value.right - size.value.width
  const widgetTop = window.innerHeight - position.value.bottom - size.value.height
  _dragOffX = e.clientX - widgetLeft
  _dragOffY = e.clientY - widgetTop
  _dragRight = position.value.right
  _dragBottom = position.value.bottom

  document.addEventListener('mousemove', handleDrag)
  document.addEventListener('mouseup', stopDrag)
}

function handleDrag(e) {
  const newLeft = e.clientX - _dragOffX
  const newTop = e.clientY - _dragOffY
  const w = size.value.width
  const h = size.value.height
  _dragRight = Math.max(16, Math.min(window.innerWidth - w - 16, window.innerWidth - newLeft - w))
  _dragBottom = Math.max(16, Math.min(window.innerHeight - h - 16, window.innerHeight - newTop - h))

  if (_rafId) return
  _rafId = requestAnimationFrame(() => {
    _rafId = 0
    const el = widgetEl.value
    if (el) {
      el.style.right = _dragRight + 'px'
      el.style.bottom = _dragBottom + 'px'
    }
  })
}

function stopDrag() {
  isDragging.value = false
  cancelAnimationFrame(_rafId)
  _rafId = 0
  position.value = { right: _dragRight, bottom: _dragBottom }
  document.removeEventListener('mousemove', handleDrag)
  document.removeEventListener('mouseup', stopDrag)
  saveState()
}

// Toggle expand/collapse
function toggleExpand() {
  isExpanded.value = !isExpanded.value
  isMaximized.value = false
  
  if (isExpanded.value) {
    position.value = { right: 24, bottom: 24 }
    nextTick(() => {
      constrainPosition()
      initChatIfNeeded()
    })
  }
  
  saveState()
}

// Toggle maximize/zen mode (full screen height)
const isAnimatingMaximize = ref(false)
let _animTimer = null

function toggleMaximize() {
  isAnimatingMaximize.value = true
  clearTimeout(_animTimer)
  isMaximized.value = !isMaximized.value
  saveState()
  _animTimer = setTimeout(() => { isAnimatingMaximize.value = false }, 220)
}

// Initialize chat store if not already done
async function initChatIfNeeded() {
  // Always ensure colleagues are initialized (for presence/status)
  if (!colleaguesStore.colleagues.length && !colleaguesStore.loading) {
    await colleaguesStore.init()
  } else if (!colleaguesStore.currentColleague) {
    colleaguesStore.init()
  }
  
  // Load conversations if not already loaded
  if (chatStore.conversations.length === 0 && !chatStore.loading) {
    await chatStore.init()
  }
  
  // Discover active calls and huddles across all conversations for sidebar indicators
  callStore.queryAllActiveCalls()
  huddleStore.fetchAllActiveHuddles()
}

// Select a conversation
function selectConversation(convId) {
  selectedConversationId.value = convId
  chatStore.setActiveConversation(convId)
  activeTab.value = 'chat'
}

// Back to conversation list
function goBack() {
  activeTab.value = 'conversations'
  selectedConversationId.value = null
}

// Get participant info
const currentParticipant = computed(() => {
  const conv = chatStore.conversations.find(c => c.id === selectedConversationId.value)
  return conv?.participants?.[0] || null
})

// Messages for selected conversation
const currentMessages = computed(() => {
  return chatStore.messages[selectedConversationId.value] || []
})

// Group messages by date (same as ChatConversation)
const groupedMessages = computed(() => {
  const msgs = currentMessages.value
  if (!msgs.length) return []
  
  const groups = []
  let currentGroup = null
  let currentDate = null
  
  for (const msg of msgs) {
    const msgDate = new Date(msg.created_at).toDateString()
    
    if (msgDate !== currentDate) {
      currentDate = msgDate
      currentGroup = {
        date: formatDateHeader(msg.created_at),
        messages: []
      }
      groups.push(currentGroup)
    }
    
    currentGroup.messages.push(msg)
  }
  
  return groups
})

function formatDateHeader(dateString) {
  const date = new Date(dateString)
  const now = new Date()
  const diffDays = Math.floor((now - date) / (1000 * 60 * 60 * 24))
  
  if (diffDays === 0) return 'Today'
  if (diffDays === 1) return 'Yesterday'
  if (diffDays < 7) return date.toLocaleDateString([], { weekday: 'long' })
  return date.toLocaleDateString([], { month: 'short', day: 'numeric' })
}

// Check if a message should show its timestamp
// Only show timestamp for the last message in a group of consecutive messages from the same sender within the same minute
function shouldShowTimestamp(messages, index) {
  const msg = messages[index]
  const nextMsg = messages[index + 1]
  
  // Always show timestamp if this is the last message
  if (!nextMsg) return true
  
  // Show timestamp if next message is from a different sender
  if (msg.sender_id !== nextMsg.sender_id) return true
  
  // Show timestamp if next message is in a different minute
  const msgTime = new Date(msg.created_at)
  const nextTime = new Date(nextMsg.created_at)
  const sameMinute = msgTime.getHours() === nextTime.getHours() && 
                     msgTime.getMinutes() === nextTime.getMinutes()
  
  return !sameMinute
}

function formatTime(dateString) {
  if (!dateString) return ''
  
  const date = new Date(dateString)
  const now = new Date()
  const diffDays = Math.floor((now - date) / (1000 * 60 * 60 * 24))
  
  if (diffDays === 0) {
    return date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })
  } else if (diffDays === 1) {
    return 'Yesterday'
  } else if (diffDays < 7) {
    return date.toLocaleDateString([], { weekday: 'short' })
  } else {
    return date.toLocaleDateString([], { month: 'short', day: 'numeric' })
  }
}

// getStatusColor is provided by useChatPresence composable

// Check if preview text is a missed/declined call (for red styling)
function isMissedCallPreview(text) {
  if (!text || !/^\[call:/.test(text)) return false
  const inner = text.replace(/^\[call:/, '').replace(/\]$/, '')
  const status = inner.split(':')[0]
  return status === 'missed' || status === 'declined'
}

// Format preview text (hide GIF/emoji raw formats)
function formatPreview(text) {
  if (!text) return 'No messages yet'
  if (/^\[gif:(.+?):(\d+):(\d+)\]$/.test(text)) {
    return 'Sent a GIF'
  }
  const embedMatch = text.match(/^\[embed:(\w+):\d+\]$/)
  if (embedMatch) {
    const labels = { drive_file: 'Shared a file', drive_folder: 'Shared a folder', calendar_event: 'Shared an event', board: 'Shared a board', board_card: 'Shared a card', collab_doc: 'Shared a document', mood_board: 'Shared a mood board' }
    return labels[embedMatch[1]] || 'Shared content'
  }
  if (/^\[voice:\d/.test(text)) {
    return 'Voice message'
  }
  if (/^\[call:/.test(text)) {
    const inner = text.replace(/^\[call:/, '').replace(/\]$/, '')
    const parts = inner.split(':')
    const status = parts[0]
    const type = parts[1]
    const duration = parts.slice(2, -1).join(':')
    if (status === 'missed') return type === 'video' ? 'Missed video call' : 'Missed voice call'
    if (status === 'completed') return type === 'video' ? `Video call ended (${duration || '00:00'})` : `Call ended (${duration || '00:00'})`
    if (status === 'declined') return type === 'video' ? 'Declined video call' : 'Declined voice call'
    if (status === 'cancelled') return 'Cancelled call'
    return 'Call'
  }
  return text.replace(/:([a-z_]+):/g, '[$1]')
}

// Get my current status
const myStatus = computed(() => {
  if (presence.manualStatus.value) return presence.manualStatus.value
  return presence.isActive.value ? 'active' : 'away'
})

function getMyStatusColor() {
  return getStatusColor(myStatus.value)
}

// getStatusLabel is provided by useChatPresence composable

function setMyStatus(status) {
  presence.setStatus(status)
  showStatusPicker.value = false
}

// Messages container ref for scrolling
const messagesContainer = ref(null)

function scrollToBottom(smooth = false) {
  nextTick(() => {
    if (messagesContainer.value) {
      // First immediate scroll
      messagesContainer.value.scrollTo({
        top: messagesContainer.value.scrollHeight,
        behavior: smooth ? 'smooth' : 'auto'
      })
      // Second scroll after a brief delay to catch late-rendering content (images, GIFs)
      setTimeout(() => {
        if (messagesContainer.value) {
          messagesContainer.value.scrollTo({
            top: messagesContainer.value.scrollHeight,
            behavior: 'auto'
          })
        }
      }, 100)
    }
  })
}

// Scroll to bottom when conversation changes or messages update
watch(selectedConversationId, () => {
  scrollToBottom()
})

watch(
  () => currentMessages.value.length,
  (newLen, oldLen) => {
    if (newLen > oldLen) {
      scrollToBottom(true)
    }
  }
)

// Load messages when selecting conversation
watch(selectedConversationId, async (id) => {
  if (id && chatStore.messages[id]?.length === 0) {
    await chatStore.loadMessages(id)
    scrollToBottom()
  }
})

// External DM trigger (from TeamPresencePanel, etc.)
watch(() => chatStore.pendingOpenDM, async (pending) => {
  if (!pending) return
  await initChatIfNeeded()
  isExpanded.value = true
  position.value = { right: 24, bottom: 24 }
  // Guard: only switch to the chat tab when we actually have a
  // conversation to render. Without this, a missing activeConversationId
  // leaves the widget stuck on an empty chat view (no header, no input).
  const convId = chatStore.activeConversationId
  if (convId) {
    selectedConversationId.value = convId
    activeTab.value = 'chat'
  } else {
    activeTab.value = 'conversations'
  }
  saveState()
  chatStore.pendingOpenDM = false
  nextTick(() => scrollToBottom())
})

// New chat
const showColleaguePicker = ref(false)
const showNewConversation = ref(false)
const showInviteModal = ref(false)
const showGroupModal = ref(false)
const showChannelModal = ref(false)
const inviteEmail = ref('')
const inviteLoading = ref(false)
const colleagueSearch = ref('')

function openNewChat() {
  showNewConversation.value = true
}

function openOldColleaguePicker() {
  showColleaguePicker.value = true
  colleagueSearch.value = ''
}

function openNewGroup() {
  showColleaguePicker.value = false
  showNewConversation.value = false
  showGroupModal.value = true
}

function handleOpenChannel() {
  showNewConversation.value = false
  showChannelModal.value = true
}

function handleChannelCreated() {
  showChannelModal.value = false
  chatStore.fetchConversations()
}

// Track if we're editing an existing group (for add people)
const editGroupId = ref(null)

function handleGroupCreated(conversation) {
  showGroupModal.value = false
  editGroupId.value = null
  selectedConversationId.value = conversation.id
  chatStore.setActiveConversation(conversation.id)
  activeTab.value = 'chat'
  // Refresh conversations to include the new group
  chatStore.fetchConversations()
}

function handleGroupUpdated() {
  showGroupModal.value = false
  editGroupId.value = null
  // Refresh to get updated participants
  chatStore.fetchConversations()
}

function handleAddPeople() {
  closeMenu()
  editGroupId.value = selectedConversationId.value
  showGroupModal.value = true
}

function handleGroupModalClose() {
  showGroupModal.value = false
  editGroupId.value = null
}

async function startChatWith(colleague) {
  showColleaguePicker.value = false
  await chatStore.openDMWith(colleague.id)
  selectedConversationId.value = chatStore.activeConversationId
  activeTab.value = 'chat'
}

function openInviteModal() {
  showColleaguePicker.value = false
  showInviteModal.value = true
  inviteEmail.value = ''
}

async function sendInvite() {
  if (!inviteEmail.value || !inviteEmail.value.includes('@')) {
    toast.error('Please enter a valid email address')
    return
  }
  
  inviteLoading.value = true
  
  try {
    const result = await chatStore.inviteToChat(inviteEmail.value)
    if (result.success) {
      toast.success(`Invitation sent to ${inviteEmail.value}`)
      showInviteModal.value = false
      inviteEmail.value = ''
      
      // If conversation was created, open it
      if (result.conversationId) {
        selectedConversationId.value = result.conversationId
        chatStore.setActiveConversation(result.conversationId)
        activeTab.value = 'chat'
      }
    } else {
      toast.error(result.error || 'Failed to send invitation')
    }
  } catch (e) {
    toast.error('Failed to send invitation')
  } finally {
    inviteLoading.value = false
  }
}

// Filter colleagues in picker
const filteredPickerColleagues = computed(() => {
  if (!colleagueSearch.value) return colleaguesStore.sortedColleagues
  
  const q = colleagueSearch.value.toLowerCase()
  return colleaguesStore.sortedColleagues.filter(c => 
    c.display_name?.toLowerCase().includes(q) ||
    c.email.toLowerCase().includes(q)
  )
})

// Search
const searchQuery = ref('')
const activeFilter = ref('all') // 'all' | 'direct' | 'groups' | 'channels' | 'public'

// Public call links
const publicLinks = ref([])
const publicLinksLoading = ref(false)

async function fetchPublicLinks() {
  publicLinksLoading.value = true
  try {
    const api = (await import('@/services/api')).default
    const { data } = await api.get('/chat/guest-call-links')
    if (data.success) publicLinks.value = data.data || []
  } catch { /* silent */ } finally {
    publicLinksLoading.value = false
  }
}

async function revokePublicLink(token) {
  try {
    const api = (await import('@/services/api')).default
    await api.delete(`/chat/guest-call-links/${token}`)
    publicLinks.value = publicLinks.value.filter(l => l.token !== token)
    toast.success('Call link revoked')
  } catch {
    toast.error('Failed to revoke link')
  }
}

async function copyPublicLink(link) {
  try {
    await navigator.clipboard.writeText(link)
    toast.success('Link copied to clipboard')
  } catch {
    toast.error('Failed to copy')
  }
}

function openPublicLink(url) {
  window.open(url, '_blank')
}

watch(activeFilter, (val) => {
  if (val === 'public' && publicLinks.value.length === 0) fetchPublicLinks()
})

// Context menu (3-dot menu)
const showMenu = ref(false)
const menuButtonRef = ref(null)
const menuPosition = ref({ top: '0px', right: '0px' })

function toggleMenu() {
  if (!showMenu.value && menuButtonRef.value) {
    // Calculate position before opening
    const rect = menuButtonRef.value.getBoundingClientRect()
    menuPosition.value = {
      top: rect.bottom + 4 + 'px',
      right: (window.innerWidth - rect.right) + 'px'
    }
  }
  showMenu.value = !showMenu.value
}

function closeMenu() {
  showMenu.value = false
}

// Settings modal
const showSettingsModal = ref(false)

function handleOpenSettings() {
  closeMenu()
  showSettingsModal.value = true
}

// Attachments panel
const showAttachmentsPanel = ref(false)

function handleViewAttachments() {
  closeMenu()
  showAttachmentsPanel.value = true
}

// Call actions
function getCallParticipantEmails() {
  const conv = currentConversation.value
  if (!conv) return []
  const myEmail = auth.userEmail?.toLowerCase()
  if (conv.type === 'group') {
    return (conv.participants || [])
      .filter(p => p?.email && p.email.toLowerCase() !== myEmail)
      .map(p => ({
        email: p.email,
        name: p.display_name || p.name || null,
        avatar: p.avatar || p.avatar_url || null
      }))
  }
  const other = conv.participants?.[0]
  return other?.email
    ? [{
      email: other.email,
      name: other.display_name || other.name || null,
      avatar: other.avatar || other.avatar_url || null
    }]
    : []
}

function startVoiceCall() {
  const emails = getCallParticipantEmails()
  if (!emails.length) {
    toast.error('No participants to call')
    return
  }
  callLauncher.startCall(selectedConversationId.value, 'voice', emails)
}

function startVideoCall() {
  const emails = getCallParticipantEmails()
  if (!emails.length) {
    toast.error('No participants to call')
    return
  }
  callLauncher.startCall(selectedConversationId.value, 'video', emails)
}


// Pinned messages
const showPinnedPanel = ref(false)

async function handleViewPinned() {
  closeMenu()
  showPinnedPanel.value = true
  await chatStore.fetchPinnedMessages(selectedConversationId.value)
}

// Pin/Mute/Archive actions
async function handlePin() {
  closeMenu()
  await chatStore.togglePin(selectedConversationId.value)
}

async function handleMute() {
  closeMenu()
  await chatStore.toggleMute(selectedConversationId.value)
}

async function handleArchive() {
  closeMenu()
  await chatStore.archiveConversation(selectedConversationId.value)
  goBack() // Return to conversation list after archiving
}

// Save to Drive
const savingToDrive = ref(false)

async function handleSaveAllToDrive() {
  closeMenu()
  savingToDrive.value = true
  
  const result = await chatStore.saveAttachmentsToDrive(selectedConversationId.value)
  
  if (result.success) {
    toast.success(`Saved ${result.savedCount} files to Drive`)
  } else {
    toast.error(result.error || 'Failed to save to Drive')
  }
  
  savingToDrive.value = false
}

// View Together
async function handleToggleViewTogether() {
  closeMenu()
  
  if (chatStore.viewSession) {
    await chatStore.endViewSession()
    toast.info('View Together session ended')
  } else {
    await chatStore.startViewSession('pending', 'waiting_for_content')
    toast.success('View Together mode enabled. Open any media to share the view.')
  }
}

// Get current conversation for menu states
const currentConversation = computed(() => {
  return chatStore.conversations.find(c => c.id === selectedConversationId.value)
})

// Background settings (synced from server)
const backgroundImage = ref('')
const backgroundOpacity = ref(0.1)
const backgroundSize = ref('')

async function loadBackgroundSettings() {
  if (!selectedConversationId.value) {
    backgroundImage.value = ''
    backgroundOpacity.value = 0.1
    backgroundSize.value = ''
    return
  }
  
  const result = await chatStore.getConversationSettings(selectedConversationId.value)
  if (result.success && result.settings) {
    backgroundImage.value = result.settings.backgroundImage || ''
    backgroundOpacity.value = result.settings.backgroundOpacity ?? 0.1
    backgroundSize.value = result.settings.backgroundSize || ''
  } else {
    backgroundImage.value = ''
    backgroundOpacity.value = 0.1
    backgroundSize.value = ''
  }
}

async function handleBackgroundUpdate(settings) {
  backgroundImage.value = settings.backgroundImage || ''
  backgroundOpacity.value = settings.backgroundOpacity ?? 0.1
  backgroundSize.value = settings.backgroundSize || ''
  
  if (selectedConversationId.value) {
    await chatStore.updateConversationSettings(selectedConversationId.value, {
      backgroundImage: settings.backgroundImage,
      backgroundOpacity: settings.backgroundOpacity,
      backgroundSize: settings.backgroundSize
    })
  }
}

// Watch for settings updates from other participants
const activeSettings = computed(() => {
  if (!selectedConversationId.value) return null
  return chatStore.conversationSettings[selectedConversationId.value] || null
})

watch(
  activeSettings,
  (newSettings) => {
    if (newSettings) {
      backgroundImage.value = newSettings.backgroundImage || ''
      backgroundOpacity.value = newSettings.backgroundOpacity ?? 0.1
      backgroundSize.value = newSettings.backgroundSize || ''
    }
  },
  { deep: true }
)

// Load background when conversation changes
watch(selectedConversationId, () => {
  loadBackgroundSettings()
})

const filteredConversations = computed(() => {
  let list = chatStore.sortedConversations

  if (activeFilter.value === 'direct') {
    list = list.filter(conv => !conv.type || conv.type === 'direct')
  } else if (activeFilter.value === 'groups') {
    list = list.filter(conv => conv.type === 'group')
  } else if (activeFilter.value === 'channels') {
    list = list.filter(conv => conv.type === 'channel')
  }

  if (searchQuery.value) {
    const q = searchQuery.value.toLowerCase()
    list = list.filter(conv => {
      if ((conv.type === 'group' || conv.type === 'channel') && conv.name?.toLowerCase().includes(q)) return true
      if (conv.type === 'channel' && conv.slug?.toLowerCase().includes(q)) return true
      const participant = conv.participants?.[0]
      if (!participant) return false
      return (
        participant.display_name?.toLowerCase().includes(q) ||
        participant.email.toLowerCase().includes(q)
      )
    })
  }

  return list
})

const filterCounts = computed(() => {
  const all = chatStore.sortedConversations
  return {
    all: all.length,
    direct: all.filter(c => !c.type || c.type === 'direct').length,
    groups: all.filter(c => c.type === 'group').length,
    channels: all.filter(c => c.type === 'channel').length,
    public: publicLinks.value.filter(l => !l.expired).length,
  }
})

// Window resize handler
function handleResize() {
  updateMobileState()
  constrainPosition()
}

onMounted(async () => {
  loadSavedState()
  window.addEventListener('resize', handleResize)
  updateMobileState()
  
  // Subscribe to chat events early so unread badge updates live
  // This is lightweight: subscribes to WS events + fetches unread counts (no conversations load)
  chatStore.ensureSubscribed()
  
  // If widget was already expanded (from localStorage), initialize chat data
  if (isExpanded.value) {
    await initChatIfNeeded()
  }
})

onUnmounted(() => {
  window.removeEventListener('resize', handleResize)
  document.removeEventListener('mousemove', handleDrag)
  document.removeEventListener('mouseup', stopDrag)
  cancelAnimationFrame(_rafId)
  _rafId = 0
  clearTimeout(_animTimer)
  _animTimer = null
})
</script>

<template>
  <!-- Don't render on /chat view, mobile (use footer nav), or if not authenticated -->
  <div v-if="auth.isAuthenticated && !isOnChatView && !isOnMoodBoard && !isMobile">
    <!-- Collapsed: Floating bubble -->
    <Transition name="bubble">
      <button
        v-if="!isExpanded"
        @click="toggleExpand"
        class="fixed z-[9990] w-12 h-12 rounded-full bg-primary-500 text-white shadow-lg hover:bg-primary-600 hover:scale-105 transition-all flex items-center justify-center group"
        :style="{ right: '24px', bottom: '24px' }"
      >
        <span class="material-symbols-rounded text-2xl">
          {{ notificationsStore.missedCallUnreadCount > 0 ? 'phone_missed' : 'chat' }}
        </span>
        
        <!-- Unread badge (includes chat unread + missed calls + pending invitations) -->
        <span 
          v-if="widgetBadgeCount > 0"
          :class="[
            'absolute -top-1 -right-1 min-w-[22px] h-[22px] px-1.5 text-white text-xs font-bold rounded-full flex items-center justify-center ring-2 ring-white dark:ring-surface-900',
            notificationsStore.missedCallUnreadCount > 0 ? 'bg-red-500' : (chatStore.pendingInvitations.length > 0 && chatStore.totalUnread === 0 ? 'bg-amber-500' : 'bg-red-500')
          ]"
        >
          {{ widgetBadgeCount > 99 ? '99+' : widgetBadgeCount }}
        </span>
        
        <!-- Tooltip -->
        <span class="absolute right-full mr-3 px-3 py-1.5 bg-surface-800 text-white text-sm rounded-lg opacity-0 group-hover:opacity-100 transition-opacity whitespace-nowrap pointer-events-none">
          Open Chat
        </span>
      </button>
    </Transition>
    
    <!-- Expanded: Chat window -->
    <Transition name="window">
      <div
        ref="widgetEl"
        v-if="isExpanded"
        :class="[
          'fixed z-[9990] bg-white dark:bg-surface-800 flex flex-col overflow-hidden',
          isMobile 
            ? 'inset-0 rounded-none' 
            : isDragging
              ? 'rounded-2xl shadow-lg border border-surface-200 dark:border-surface-700'
              : 'rounded-2xl shadow-2xl border border-surface-200 dark:border-surface-700',
          isAnimatingMaximize && !isMobile && 'transition-all duration-200'
        ]"
        :style="isMobile ? {} : {
          right: isMaximized ? '16px' : position.right + 'px',
          bottom: isMaximized ? '16px' : position.bottom + 'px',
          width: isMaximized ? '470px' : size.width + 'px',
          height: isMaximized ? 'calc(100vh - 76px)' : size.height + 'px',
          willChange: isDragging ? 'right, bottom' : 'auto',
        }"
      >
        <!-- Header (draggable on desktop only, not when maximized) -->
        <div 
          @mousedown="startDrag"
          :class="[
            'flex items-center justify-between px-4 border-b border-surface-200 dark:border-surface-700 flex-shrink-0',
            isMobile ? 'widget-header-safe-area' : 'h-14',
            !isMobile && !isMaximized && (isDragging ? 'cursor-grabbing' : 'cursor-grab')
          ]"
        >
          <div class="flex items-center gap-3 min-w-0 flex-1">
            <!-- Back button when in chat -->
            <button
              v-if="activeTab === 'chat'"
              @click="goBack"
              class="p-1.5 -ml-1 hover:bg-surface-100 dark:hover:bg-surface-700 rounded-lg transition-colors flex-shrink-0"
            >
              <span class="material-symbols-rounded text-surface-600 dark:text-surface-400">arrow_back</span>
            </button>
            
            <!-- Title / participant info -->
            <template v-if="activeTab === 'conversations'">
              <div class="w-8 h-8 bg-primary-500 rounded-lg flex items-center justify-center">
                <span class="material-symbols-rounded text-white text-lg">chat</span>
              </div>
              <span class="font-semibold text-surface-900 dark:text-surface-100">Messages</span>
              <!-- Missed calls badge (red, separate from unread messages) -->
              <span 
                v-if="notificationsStore.missedCallUnreadCount > 0"
                class="px-2 py-0.5 text-xs font-medium bg-red-500 text-white rounded-full flex items-center gap-1"
              >
                <span class="material-symbols-rounded text-xs">phone_missed</span>
                {{ notificationsStore.missedCallUnreadCount }}
              </span>
              <!-- Chat unread badge -->
              <span 
                v-if="chatStore.totalUnread > 0"
                class="px-2 py-0.5 text-xs font-medium bg-primary-500 text-white rounded-full"
              >
                {{ chatStore.totalUnread }}
              </span>
            </template>
            
            <!-- Group conversation header -->
            <template v-else-if="currentConversation?.type === 'group'">
              <button
                ref="menuButtonRef"
                @click.stop="toggleMenu"
                class="flex items-center gap-3 min-w-0 hover:bg-surface-100 dark:hover:bg-surface-700 rounded-lg px-2 py-1 -mx-2 transition-colors cursor-pointer"
              >
                <div class="w-9 h-9 rounded-full bg-primary-100 dark:bg-primary-500/20 flex items-center justify-center flex-shrink-0">
                  <span class="material-symbols-rounded text-primary-500">group</span>
                </div>
                <div class="min-w-0 text-left">
                  <p class="font-medium text-surface-900 dark:text-surface-100 truncate text-sm">
                    {{ currentConversation?.name || 'Group Chat' }}
                  </p>
                  <p v-if="chatStore.typingStatus[selectedConversationId]" class="text-xs text-primary-500">
                    Typing...
                  </p>
                  <p v-else class="text-xs text-surface-500">
                    {{ currentConversation?.participant_count || currentConversation?.participants?.length || 0 }} members
                  </p>
                </div>
              </button>
            </template>
            
            <!-- DM conversation header -->
            <template v-else-if="currentParticipant">
              <button
                ref="menuButtonRef"
                @click.stop="toggleMenu"
                class="flex items-center gap-3 min-w-0 hover:bg-surface-100 dark:hover:bg-surface-700 rounded-lg px-2 py-1 -mx-2 transition-colors cursor-pointer"
              >
                <UserAvatar
                  :colleague="currentParticipant"
                  size="lg"
                  show-presence
                />
                <div class="min-w-0 text-left">
                  <p class="font-medium text-surface-900 dark:text-surface-100 truncate text-sm">
                    {{ currentParticipant?.display_name || currentParticipant?.email?.split('@')[0] }}
                  </p>
                  <p v-if="chatStore.typingStatus[selectedConversationId]" class="text-xs text-primary-500">
                    Typing...
                  </p>
                </div>
              </button>
            </template>
          </div>
          
          <!-- Actions -->
          <div class="flex items-center gap-1 flex-shrink-0">
            
            <button
              v-if="activeTab === 'conversations'"
              @click="openNewChat"
              class="p-2 hover:bg-surface-100 dark:hover:bg-surface-700 rounded-lg transition-colors"
              title="New Message"
            >
              <span class="material-symbols-rounded text-surface-500">edit_square</span>
            </button>
            
            <!-- Call buttons (only in chat view) -->
            <button
              v-if="activeTab === 'chat'"
              @click="startVoiceCall"
              :disabled="callStore.isInCall"
              :class="[
                'p-2 rounded-lg transition-colors',
                callStore.isInCall
                  ? 'text-surface-300 dark:text-surface-600 cursor-not-allowed'
                  : 'hover:bg-surface-100 dark:hover:bg-surface-700 text-surface-500'
              ]"
              title="Voice call"
            >
              <span class="material-symbols-rounded text-[20px]">call</span>
            </button>
            <button
              v-if="activeTab === 'chat'"
              @click="startVideoCall"
              :disabled="callStore.isInCall"
              :class="[
                'p-2 rounded-lg transition-colors',
                callStore.isInCall
                  ? 'text-surface-300 dark:text-surface-600 cursor-not-allowed'
                  : 'hover:bg-surface-100 dark:hover:bg-surface-700 text-surface-500'
              ]"
              title="Video call"
            >
              <span class="material-symbols-rounded text-[20px]">videocam</span>
            </button>
            
            <!-- Menu dropdown (triggered by clicking avatar/name) -->
            <Teleport to="body">
              <template v-if="activeTab === 'chat'">
                <div v-if="showMenu" class="fixed inset-0 z-[99998]" @click.stop="closeMenu"></div>
                <div 
                  v-if="showMenu"
                  class="fixed w-48 bg-white dark:bg-surface-800 rounded-xl shadow-xl border border-surface-200 dark:border-surface-700 py-1 z-[99999]"
                  :style="menuPosition"
                  @click.stop
                >
                  <button
                    @click="handleViewPinned"
                    class="w-full flex items-center gap-3 px-4 py-2 hover:bg-surface-100 dark:hover:bg-surface-700 transition-colors text-left"
                  >
                    <span class="material-symbols-rounded text-lg text-primary-500">push_pin</span>
                    <span class="text-sm">Pinned messages</span>
                  </button>
                  <button
                    @click="handlePin"
                    class="w-full flex items-center gap-3 px-4 py-2 hover:bg-surface-100 dark:hover:bg-surface-700 transition-colors text-left"
                  >
                    <span class="material-symbols-rounded text-lg">push_pin</span>
                    <span class="text-sm">
                      {{ currentConversation?.is_pinned ? 'Unpin' : 'Pin' }}
                    </span>
                  </button>
                  <button
                    @click="handleMute"
                    class="w-full flex items-center gap-3 px-4 py-2 hover:bg-surface-100 dark:hover:bg-surface-700 transition-colors text-left"
                  >
                    <span class="material-symbols-rounded text-lg">
                      {{ currentConversation?.is_muted ? 'notifications' : 'notifications_off' }}
                    </span>
                    <span class="text-sm">
                      {{ currentConversation?.is_muted ? 'Unmute' : 'Mute' }}
                    </span>
                  </button>
                  
                  <button
                    @click="handleAddPeople"
                    class="w-full flex items-center gap-3 px-4 py-2 hover:bg-surface-100 dark:hover:bg-surface-700 transition-colors text-left"
                  >
                    <span class="material-symbols-rounded text-lg">person_add</span>
                    <span class="text-sm">Add people</span>
                  </button>
                  
                  <div class="border-t border-surface-200 dark:border-surface-700 my-1"></div>
                  
                  <button
                    @click="handleViewAttachments"
                    class="w-full flex items-center gap-3 px-4 py-2 hover:bg-surface-100 dark:hover:bg-surface-700 transition-colors text-left"
                  >
                    <span class="material-symbols-rounded text-lg">photo_library</span>
                    <span class="text-sm">Media & Docs</span>
                  </button>
                  
                  <button
                    @click="handleOpenSettings"
                    class="w-full flex items-center gap-3 px-4 py-2 hover:bg-surface-100 dark:hover:bg-surface-700 transition-colors text-left"
                  >
                    <span class="material-symbols-rounded text-lg">wallpaper</span>
                    <span class="text-sm">Appearance</span>
                  </button>
                  
                  <button
                    @click="handleToggleViewTogether"
                    class="w-full flex items-center gap-3 px-4 py-2 hover:bg-surface-100 dark:hover:bg-surface-700 transition-colors text-left"
                  >
                    <span class="material-symbols-rounded text-lg">
                      {{ chatStore.viewSession ? 'stop_screen_share' : 'screen_share' }}
                    </span>
                    <span class="text-sm">
                      {{ chatStore.viewSession ? 'End View Together' : 'View Together' }}
                    </span>
                  </button>
                  
                  <button
                    @click="handleSaveAllToDrive"
                    :disabled="savingToDrive"
                    class="w-full flex items-center gap-3 px-4 py-2 hover:bg-surface-100 dark:hover:bg-surface-700 transition-colors text-left"
                  >
                    <span :class="['material-symbols-rounded text-lg', savingToDrive && 'animate-spin']">
                      {{ savingToDrive ? 'progress_activity' : 'cloud_upload' }}
                    </span>
                    <span class="text-sm">Save to Drive</span>
                  </button>
                  
                  <div class="border-t border-surface-200 dark:border-surface-700 my-1"></div>
                  
                  <button
                    @click="handleArchive"
                    class="w-full flex items-center gap-3 px-4 py-2 hover:bg-surface-100 dark:hover:bg-surface-700 transition-colors text-left text-red-500"
                  >
                    <span class="material-symbols-rounded text-lg">archive</span>
                    <span class="text-sm">Archive</span>
                  </button>
                </div>
              </template>
            </Teleport>
            
            <!-- Maximize/Zen mode button (hidden on mobile) -->
            <button
              v-if="!isMobile"
              @click="toggleMaximize"
              class="p-2 hover:bg-surface-100 dark:hover:bg-surface-700 rounded-lg transition-colors"
              :title="isMaximized ? 'Exit Zen Mode' : 'Zen Mode'"
            >
              <span class="material-symbols-rounded text-surface-500">
                {{ isMaximized ? 'close_fullscreen' : 'open_in_full' }}
              </span>
            </button>
            
            <button
              @click="toggleExpand"
              class="p-2 hover:bg-surface-100 dark:hover:bg-surface-700 rounded-lg transition-colors"
              title="Close"
            >
              <span class="material-symbols-rounded text-surface-500">close</span>
            </button>
          </div>
        </div>
        
        <!-- Content -->
        <div class="flex-1 overflow-hidden flex flex-col">
          <!-- Conversations List -->
          <template v-if="activeTab === 'conversations'">
            <!-- Search + Filters -->
            <div class="px-3 pt-2 pb-1.5 border-b border-surface-200 dark:border-surface-700">
              <div class="relative">
                <span class="material-symbols-rounded absolute left-3 top-1/2 -translate-y-1/2 text-surface-400 text-lg">search</span>
                <input
                  v-model="searchQuery"
                  type="text"
                  placeholder="Search..."
                  class="w-full pl-9 pr-3 py-2 bg-surface-50 dark:bg-surface-900 border border-surface-200 dark:border-surface-700 rounded-full text-sm focus:ring-2 focus:ring-primary-500 focus:border-primary-500 outline-none"
                />
              </div>
              <div class="flex items-center gap-1 mt-2 overflow-x-auto no-scrollbar">
                <button
                  v-for="tab in [
                    { key: 'all', label: 'All', icon: 'forum' },
                    { key: 'direct', icon: 'chat' },
                    { key: 'groups', icon: 'group' },
                    { key: 'channels', icon: 'tag' },
                    { key: 'public', icon: 'add_link' },
                  ]"
                  :key="tab.key"
                  @click="activeFilter = tab.key"
                  :class="[
                    'flex items-center gap-1 px-2 py-1 rounded-full text-xs font-medium transition-all whitespace-nowrap flex-shrink-0',
                    activeFilter === tab.key
                      ? (tab.key === 'public' ? 'bg-cyan-500 text-white shadow-sm' : 'bg-primary-500 text-white shadow-sm')
                      : 'bg-surface-100 dark:bg-surface-800 text-surface-500 dark:text-surface-400 hover:bg-surface-200 dark:hover:bg-surface-700'
                  ]"
                  :title="tab.key.charAt(0).toUpperCase() + tab.key.slice(1)"
                >
                  <span class="material-symbols-rounded text-sm">{{ tab.icon }}</span>
                  <template v-if="tab.label">{{ tab.label }}</template>
                  <span
                    v-if="filterCounts[tab.key] > 0"
                    :class="[
                      'min-w-[14px] h-4 px-0.5 rounded-full text-[10px] leading-4 text-center',
                      activeFilter === tab.key
                        ? 'bg-white/25'
                        : 'bg-surface-200 dark:bg-surface-700 text-surface-500 dark:text-surface-400'
                    ]"
                  >{{ filterCounts[tab.key] }}</span>
                </button>
              </div>
            </div>
            
            <!-- Conversation list -->
            <div class="flex-1 overflow-y-auto">
              <!-- Missed Calls Banner -->
              <div 
                v-if="notificationsStore.missedCallUnreadCount > 0"
                @click="notificationsStore.openPanel(); notificationsStore.panelOpen && (isExpanded = false)"
                class="mx-3 mt-2 mb-1 px-3 py-2.5 bg-red-50 dark:bg-red-500/10 border border-red-200 dark:border-red-500/20 rounded-xl cursor-pointer hover:bg-red-100 dark:hover:bg-red-500/20 transition-colors"
              >
                <div class="flex items-center gap-2.5">
                  <div class="w-8 h-8 rounded-full bg-red-100 dark:bg-red-500/20 flex items-center justify-center flex-shrink-0">
                    <span class="material-symbols-rounded text-red-500 text-lg">phone_missed</span>
                  </div>
                  <div class="flex-1 min-w-0">
                    <p class="text-sm font-medium text-red-700 dark:text-red-400">
                      {{ notificationsStore.missedCallUnreadCount }} missed call{{ notificationsStore.missedCallUnreadCount > 1 ? 's' : '' }}
                    </p>
                    <p class="text-xs text-red-500 dark:text-red-400/70">Tap to view in notifications</p>
                  </div>
                  <span class="material-symbols-rounded text-red-400 text-lg">chevron_right</span>
                </div>
              </div>
              
              <!-- Public call links tab -->
              <template v-if="activeFilter === 'public'">
                <div v-if="publicLinksLoading" class="flex items-center justify-center py-8">
                  <span class="material-symbols-rounded text-2xl text-surface-400 animate-spin">progress_activity</span>
                </div>
                <div v-else-if="publicLinks.length === 0" class="text-center py-8 px-4">
                  <span class="material-symbols-rounded text-4xl text-surface-300 dark:text-surface-600 mb-2 block">add_link</span>
                  <p class="text-sm text-surface-500">No public call links yet</p>
                  <p class="text-xs text-surface-400 mt-1">Create one from the New Conversation menu</p>
                </div>
                <div v-else class="px-2 py-1 space-y-1">
                  <div
                    v-for="link in publicLinks"
                    :key="link.token"
                    class="flex items-center gap-3 px-3 py-2.5 rounded-xl transition-colors"
                    :class="link.expired ? 'opacity-50' : 'hover:bg-surface-50 dark:hover:bg-surface-700/50'"
                  >
                    <div class="w-9 h-9 rounded-full flex items-center justify-center flex-shrink-0"
                      :class="link.expired ? 'bg-surface-200 dark:bg-surface-700' : 'bg-cyan-100 dark:bg-cyan-500/20'"
                    >
                      <span class="material-symbols-rounded text-lg"
                        :class="link.expired ? 'text-surface-400' : 'text-cyan-600 dark:text-cyan-400'"
                      >{{ link.expired ? 'link_off' : 'add_link' }}</span>
                    </div>
                    <div class="flex-1 min-w-0">
                      <p class="text-sm font-medium text-surface-900 dark:text-surface-100 truncate">
                        {{ link.room_name }}
                      </p>
                      <p class="text-[11px] text-surface-500 truncate">
                        {{ link.expired ? 'Expired' : `Expires ${new Date(link.expires_at).toLocaleDateString()}` }}
                        <span v-if="link.use_count > 0" class="ml-1">
                          &middot; {{ link.use_count }} join{{ link.use_count !== 1 ? 's' : '' }}
                        </span>
                      </p>
                    </div>
                    <div class="flex items-center gap-1 flex-shrink-0">
                      <button
                        v-if="!link.expired"
                        @click.stop="copyPublicLink(link.link)"
                        class="p-1.5 rounded-lg hover:bg-surface-200 dark:hover:bg-surface-600 text-surface-500 transition-colors"
                        title="Copy link"
                      >
                        <span class="material-symbols-rounded text-base">content_copy</span>
                      </button>
                      <button
                        v-if="!link.expired"
                        @click.stop="openPublicLink(link.link)"
                        class="p-1.5 rounded-lg hover:bg-surface-200 dark:hover:bg-surface-600 text-surface-500 transition-colors"
                        title="Open link"
                      >
                        <span class="material-symbols-rounded text-base">open_in_new</span>
                      </button>
                      <button
                        @click.stop="revokePublicLink(link.token)"
                        class="p-1.5 rounded-lg hover:bg-red-100 dark:hover:bg-red-500/20 text-surface-400 hover:text-red-500 transition-colors"
                        :title="link.expired ? 'Remove' : 'Revoke'"
                      >
                        <span class="material-symbols-rounded text-base">{{ link.expired ? 'delete' : 'block' }}</span>
                      </button>
                    </div>
                  </div>
                </div>
              </template>

              <!-- Normal conversation tabs -->
              <template v-else>
              <!-- Pending Invitations -->
              <ChatInvitations :compact="true" />
              
              <div v-if="chatStore.loading" class="flex items-center justify-center py-8">
                <span class="material-symbols-rounded text-2xl text-surface-400 animate-spin">progress_activity</span>
              </div>
              
              <div v-else-if="filteredConversations.length === 0 && chatStore.pendingInvitations.length === 0" class="text-center py-8 px-4">
                <span class="material-symbols-rounded text-4xl text-surface-300 dark:text-surface-600 mb-2 block">
                  {{ activeFilter === 'channels' ? 'tag' : activeFilter === 'groups' ? 'group' : 'chat_bubble_outline' }}
                </span>
                <p class="text-sm text-surface-500">
                  {{ searchQuery ? 'No matches found' : activeFilter === 'channels' ? 'No channels yet' : activeFilter === 'groups' ? 'No group chats yet' : activeFilter === 'direct' ? 'No direct messages yet' : 'No conversations yet' }}
                </p>
              </div>
              
              <div v-else>
                <div
                  v-for="conv in filteredConversations"
                  :key="conv.id"
                  @click="selectConversation(conv.id)"
                  class="flex items-center gap-3 px-3 py-2.5 cursor-pointer hover:bg-surface-50 dark:hover:bg-surface-700/50 transition-colors"
                >
                  <!-- Avatar -->
                  <div class="relative flex-shrink-0">
                    <!-- Channel Avatar -->
                    <div 
                      v-if="conv.type === 'channel'"
                      :class="[
                        'w-11 h-11 rounded-full flex items-center justify-center',
                        conv.is_public === 0 || conv.is_public === '0' ? 'bg-amber-100 dark:bg-amber-500/20' : 'bg-primary-100 dark:bg-primary-500/20'
                      ]"
                    >
                      <span :class="['material-symbols-rounded text-xl', conv.is_public === 0 || conv.is_public === '0' ? 'text-amber-500' : 'text-primary-500']">
                        {{ conv.is_public === 0 || conv.is_public === '0' ? 'lock' : 'tag' }}
                      </span>
                    </div>
                    <!-- Group Avatar -->
                    <div 
                      v-else-if="conv.type === 'group'"
                      class="w-11 h-11 rounded-full bg-primary-100 dark:bg-primary-500/20 flex items-center justify-center"
                    >
                      <span class="material-symbols-rounded text-primary-500 text-xl">group</span>
                    </div>
                    <!-- DM Avatar -->
                    <UserAvatar
                      v-else
                      :colleague="conv.participants?.[0]"
                      size="xl"
                      show-presence
                    />
                  </div>
                  
                  <!-- Content -->
                  <div class="flex-1 min-w-0">
                    <div class="flex items-center justify-between gap-2 mb-0.5">
                      <span 
                        :class="[
                          'font-medium truncate text-sm',
                          conv.unread_count > 0 
                            ? 'text-surface-900 dark:text-surface-100' 
                            : 'text-surface-700 dark:text-surface-300'
                        ]"
                      >
                        {{ conv.type === 'channel' ? (conv.slug ? `#${conv.slug}` : (conv.name || 'Channel')) : conv.type === 'group' ? (conv.name || 'Group Chat') : (conv.participants?.[0]?.display_name || conv.participants?.[0]?.email?.split('@')[0] || 'Unknown') }}
                      </span>
                      <span class="text-xs text-surface-400 flex-shrink-0">
                        {{ formatTime(conv.last_message_at) }}
                      </span>
                    </div>
                    <div class="flex items-center gap-2">
                      <p 
                        :class="[
                          'text-xs truncate flex-1 flex items-center gap-1',
                          isMissedCallPreview(conv.last_message_preview) 
                            ? 'text-red-500 dark:text-red-400 font-semibold' 
                            : conv.unread_count > 0 
                              ? 'text-surface-700 dark:text-surface-300 font-medium' 
                              : 'text-surface-500'
                        ]"
                      >
                        <span 
                          v-if="isMissedCallPreview(conv.last_message_preview)" 
                          class="material-symbols-rounded text-xs flex-shrink-0"
                        >phone_missed</span>
                        <!-- Show member count for groups with no messages -->
                        <template v-if="conv.type === 'group' && !conv.last_message_preview">
                          {{ conv.participant_count || conv.participants?.length || 0 }} members
                        </template>
                        <template v-else>
                          {{ formatPreview(conv.last_message_preview) }}
                        </template>
                      </p>
                      
                      <!-- Unread badge -->
                      <span 
                        v-if="conv.unread_count > 0"
                        class="min-w-[18px] h-[18px] px-1 bg-primary-500 text-white text-xs font-medium rounded-full flex items-center justify-center"
                      >
                        {{ conv.unread_count > 99 ? '99+' : conv.unread_count }}
                      </span>
                    </div>
                    
                    <!-- Active call indicator -->
                    <div 
                      v-if="callStore.conversationActiveCalls[conv.id]"
                      class="flex items-center gap-1 mt-0.5"
                    >
                      <span class="material-symbols-rounded text-xs text-green-500 animate-pulse">
                        {{ callStore.conversationActiveCalls[conv.id].callType === 'video' ? 'videocam' : 'call' }}
                      </span>
                      <span class="text-[11px] font-medium text-green-600 dark:text-green-400">
                        {{ callStore.conversationActiveCalls[conv.id].callType === 'video' ? 'Video call' : 'Voice call' }} in progress
                      </span>
                    </div>
                    
                    <!-- Active huddle indicator -->
                    <div 
                      v-if="huddleStore.conversationActiveHuddles[conv.id] && !callStore.conversationActiveCalls[conv.id]"
                      class="flex items-center gap-1 mt-0.5"
                    >
                      <span class="material-symbols-rounded text-xs text-green-500 animate-pulse">headset_mic</span>
                      <span class="text-[11px] font-medium text-green-600 dark:text-green-400">
                        Huddle ({{ huddleStore.conversationActiveHuddles[conv.id].participantCount || 0 }})
                      </span>
                    </div>
                  </div>
                </div>
              </div>
              </template>
            </div>
          </template>
          
          <!-- Chat View -->
          <div v-else-if="activeTab === 'chat' && selectedConversationId" class="flex-1 overflow-hidden flex flex-col relative">
            <!-- View Together Banner -->
            <ViewTogetherBanner @end-session="chatStore.endViewSession()" />
            
            <!-- Pinned Messages Panel -->
            <div 
              v-if="showPinnedPanel"
              class="border-b border-surface-200 dark:border-surface-700 bg-white dark:bg-surface-800 flex-shrink-0 max-h-[40%] flex flex-col"
            >
              <div class="flex items-center justify-between px-3 py-2 border-b border-surface-100 dark:border-surface-700">
                <div class="flex items-center gap-1.5">
                  <span class="material-symbols-rounded text-base text-primary-500">push_pin</span>
                  <span class="text-xs font-semibold text-surface-900 dark:text-surface-100">Pinned</span>
                  <span v-if="chatStore.pinnedMessages[selectedConversationId]?.length" class="text-xs text-surface-400">
                    ({{ chatStore.pinnedMessages[selectedConversationId].length }})
                  </span>
                </div>
                <button @click="showPinnedPanel = false" class="p-1 hover:bg-surface-100 dark:hover:bg-surface-700 rounded transition-colors">
                  <span class="material-symbols-rounded text-sm text-surface-400">close</span>
                </button>
              </div>
              <div class="overflow-y-auto flex-1">
                <div v-if="chatStore.loadingPinned" class="flex justify-center py-4">
                  <span class="material-symbols-rounded text-lg text-surface-400 animate-spin">progress_activity</span>
                </div>
                <div v-else-if="!chatStore.pinnedMessages[selectedConversationId]?.length" class="py-6 text-center">
                  <span class="material-symbols-rounded text-2xl text-surface-300 dark:text-surface-600 mb-1 block">push_pin</span>
                  <p class="text-xs text-surface-500">No pinned messages</p>
                </div>
                <div v-else class="divide-y divide-surface-100 dark:divide-surface-700">
                  <div
                    v-for="msg in chatStore.pinnedMessages[selectedConversationId]"
                    :key="msg.id"
                    class="px-3 py-2 hover:bg-surface-50 dark:hover:bg-surface-800/50 cursor-pointer group"
                    @click="showPinnedPanel = false"
                  >
                    <div class="flex items-start gap-2">
                      <UserAvatar
                        :colleague="{ display_name: msg.sender_name, email: msg.sender_email }"
                        size="xs"
                      />
                      <div class="flex-1 min-w-0">
                        <span class="text-xs font-medium text-surface-900 dark:text-surface-100">{{ msg.sender_name || msg.sender_email }}</span>
                        <p class="text-xs text-surface-500 mt-0.5 line-clamp-2">{{ msg.content }}</p>
                      </div>
                    </div>
                  </div>
                </div>
              </div>
            </div>
            
            <!-- Messages area with background -->
            <div class="flex-1 overflow-hidden relative">
              <!-- Background Layer -->
              <div 
                v-if="backgroundImage"
                class="absolute inset-0 pointer-events-none z-0"
                :style="{
                  background: backgroundImage.startsWith('linear') || backgroundImage.startsWith('radial') 
                    ? backgroundImage 
                    : `url(${backgroundImage})`,
                  backgroundSize: backgroundSize || 'cover',
                  backgroundPosition: 'center',
                  opacity: backgroundOpacity
                }"
              ></div>
              
              <!-- Messages -->
              <div 
                ref="messagesContainer"
                class="absolute inset-0 overflow-y-auto px-3 py-3 z-10"
              >
              <template v-for="(group, groupIndex) in groupedMessages" :key="groupIndex">
                <!-- Date divider -->
                <div class="flex items-center gap-3 my-4">
                  <div class="flex-1 h-px bg-surface-200 dark:bg-surface-700"></div>
                  <span class="text-xs font-medium text-surface-400 px-2">{{ group.date }}</span>
                  <div class="flex-1 h-px bg-surface-200 dark:bg-surface-700"></div>
                </div>
                
                <!-- Messages -->
                <ChatMessage
                  v-for="(message, msgIndex) in group.messages"
                  :key="message.id"
                  :message="message"
                  :participant="currentParticipant"
                  :show-timestamp="shouldShowTimestamp(group.messages, msgIndex)"
                  :is-group-chat="currentConversation?.type === 'group'"
                />
              </template>
              
              <!-- Typing indicator -->
              <div 
                v-if="chatStore.typingStatus[selectedConversationId]"
                class="flex items-end gap-2 mb-3"
              >
                <UserAvatar
                  :colleague="currentParticipant"
                  size="sm"
                />
                <div class="bg-surface-200 dark:bg-surface-700 rounded-2xl rounded-bl-md px-3 py-2">
                  <div class="flex gap-1">
                    <span class="w-1.5 h-1.5 bg-surface-400 rounded-full animate-bounce" style="animation-delay: 0ms"></span>
                    <span class="w-1.5 h-1.5 bg-surface-400 rounded-full animate-bounce" style="animation-delay: 150ms"></span>
                    <span class="w-1.5 h-1.5 bg-surface-400 rounded-full animate-bounce" style="animation-delay: 300ms"></span>
                  </div>
                </div>
              </div>
              
              <!-- Empty state -->
              <div 
                v-if="groupedMessages.length === 0 && !chatStore.loadingMessages"
                class="flex flex-col items-center justify-center py-8 text-center"
              >
                <span class="material-symbols-rounded text-4xl text-primary-300 mb-2">waving_hand</span>
                <p class="text-sm text-surface-500">
                  Start the conversation!
                </p>
              </div>
              </div>
            </div>
            
            <!-- Reply preview -->
            <div 
              v-if="chatStore.replyingTo"
              class="px-3 py-2 bg-surface-100 dark:bg-surface-800 border-t border-surface-200 dark:border-surface-700 flex items-center gap-2 flex-shrink-0"
            >
              <div class="w-1 h-8 bg-primary-500 rounded-full flex-shrink-0"></div>
              <div class="flex-1 min-w-0">
                <p class="text-xs font-medium text-primary-500 mb-0.5">
                  Replying to {{ chatStore.replyingTo.sender_name }}
                </p>
                <p class="text-xs text-surface-500 truncate">
                  {{ formatPreview(chatStore.replyingTo.content) }}
                </p>
              </div>
              <button
                @click="chatStore.clearReplyingTo()"
                class="p-1 hover:bg-surface-200 dark:hover:bg-surface-700 rounded transition-colors flex-shrink-0"
              >
                <span class="material-symbols-rounded text-surface-400 text-sm">close</span>
              </button>
            </div>
            
            <!-- Input -->
            <div class="border-t border-surface-200 dark:border-surface-700 flex-shrink-0">
              <ChatInput compact />
            </div>
            
            <!-- Thread Panel Overlay (slides in over the chat view) -->
            <Transition
              enter-active-class="transition-transform duration-200 ease-out"
              leave-active-class="transition-transform duration-150 ease-in"
              enter-from-class="translate-x-full"
              enter-to-class="translate-x-0"
              leave-from-class="translate-x-0"
              leave-to-class="translate-x-full"
            >
              <div
                v-if="chatStore.activeThreadId !== null"
                class="absolute inset-0 z-20 bg-white dark:bg-surface-800 flex flex-col widget-thread-panel"
              >
                <ThreadPanel />
              </div>
            </Transition>
          </div>
        </div>
        
        <!-- Settings Modal -->
        <ChatSettingsModal
          :show="showSettingsModal"
          :conversation-id="selectedConversationId"
          @close="showSettingsModal = false"
          @update="handleBackgroundUpdate"
        />
        
        <!-- Attachments Panel -->
        <ChatAttachmentsPanel
          :show="showAttachmentsPanel"
          :conversation-id="selectedConversationId"
          @close="showAttachmentsPanel = false"
        />
      </div>
    </Transition>
    
    <!-- New Conversation Modal -->
    <NewConversationModal
      :show="showNewConversation"
      @close="showNewConversation = false"
      @open-group="(ids) => { showNewConversation = false; showGroupModal = true }"
      @open-channel="handleOpenChannel"
      @started="showNewConversation = false"
    />

    <!-- Channel Create Modal -->
    <ChannelCreateModal
      v-if="showChannelModal"
      @close="showChannelModal = false"
      @back="showChannelModal = false; showNewConversation = true"
      @created="handleChannelCreated"
    />
    
    <!-- Group Chat Modal -->
    <GroupChatModal
      :show="showGroupModal"
      :edit-mode="!!editGroupId"
      :conversation-id="editGroupId"
      @close="handleGroupModalClose"
      @back="showGroupModal = false; editGroupId = null; showNewConversation = true"
      @created="handleGroupCreated"
      @updated="handleGroupUpdated"
    />
    
    <!-- Invite External User Modal -->
    <Teleport to="body">
      <div 
        v-if="showInviteModal"
        class="fixed inset-0 bg-black/50 flex items-center justify-center z-[9999]"
        @click.self="showInviteModal = false"
      >
        <div class="bg-white dark:bg-surface-800 rounded-2xl shadow-2xl w-full max-w-sm mx-4">
          <div class="p-4 border-b border-surface-200 dark:border-surface-700">
            <div class="flex items-center justify-between">
              <h2 class="font-semibold text-surface-900 dark:text-surface-100">Invite to Chat</h2>
              <button
                @click="showInviteModal = false"
                class="p-1 hover:bg-surface-100 dark:hover:bg-surface-700 rounded-lg transition-colors"
              >
                <span class="material-symbols-rounded text-surface-500">close</span>
              </button>
            </div>
          </div>
          
          <div class="p-4">
            <p class="text-sm text-surface-500 mb-4">
              Enter the email address of the person you want to invite. They'll receive an email with a link to join the chat.
            </p>
            
            <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-1.5">
              Email Address
            </label>
            <input
              v-model="inviteEmail"
              type="email"
              placeholder="name@example.com"
              class="w-full px-3 py-2.5 bg-surface-50 dark:bg-surface-900 border border-surface-200 dark:border-surface-700 rounded-lg text-sm focus:ring-2 focus:ring-primary-500 focus:border-primary-500 outline-none"
              @keyup.enter="sendInvite"
            />
          </div>
          
          <div class="p-4 pt-0 flex gap-2">
            <button
              @click="showInviteModal = false"
              class="flex-1 px-4 py-2.5 text-sm font-medium text-surface-700 dark:text-surface-300 hover:bg-surface-100 dark:hover:bg-surface-700 rounded-lg transition-colors"
            >
              Cancel
            </button>
            <button
              @click="sendInvite"
              :disabled="inviteLoading || !inviteEmail"
              class="flex-1 px-4 py-2.5 text-sm font-medium text-white bg-primary-500 hover:bg-primary-600 rounded-lg transition-colors disabled:opacity-50 disabled:cursor-not-allowed flex items-center justify-center gap-2"
            >
              <span v-if="inviteLoading" class="material-symbols-rounded animate-spin text-lg">progress_activity</span>
              {{ inviteLoading ? 'Sending...' : 'Send Invite' }}
            </button>
          </div>
        </div>
      </div>
    </Teleport>
  </div>
</template>

<style scoped>
/* Bubble animation */
.bubble-enter-active,
.bubble-leave-active {
  transition: all 0.2s ease;
}

.bubble-enter-from,
.bubble-leave-to {
  opacity: 0;
  transform: scale(0.8);
}

/* Window animation */
.window-enter-active,
.window-leave-active {
  transition: all 0.25s ease;
}

.window-enter-from,
.window-leave-to {
  opacity: 0;
  transform: scale(0.95) translateY(10px);
}

/* Custom scrollbar for conversation list */
.overflow-y-auto::-webkit-scrollbar {
  width: 6px;
}

.overflow-y-auto::-webkit-scrollbar-track {
  background: transparent;
}

.overflow-y-auto::-webkit-scrollbar-thumb {
  background: rgb(var(--color-surface-300));
  border-radius: 3px;
}

.dark .overflow-y-auto::-webkit-scrollbar-thumb {
  background: rgb(var(--color-surface-600));
}

/* Safe area handling for PWA on iOS/Android */
.widget-header-safe-area {
  padding-top: calc(0.5rem + env(safe-area-inset-top, 0px));
  padding-bottom: 0.5rem;
  min-height: calc(3.5rem + env(safe-area-inset-top, 0px));
  /* Push content to bottom of header, away from the notch */
  align-items: flex-end !important;
}

/* Override ThreadPanel width to fill the floating widget */
.widget-thread-panel > div {
  width: 100% !important;
  max-width: 100% !important;
  border-left: none !important;
}
</style>

