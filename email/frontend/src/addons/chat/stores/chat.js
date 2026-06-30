import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import api from '@/services/api'
import { useMailSync, EventTypes } from '@/services/mailSyncSocket'
import { useColleaguesStore } from '@/addons/team/stores/colleagues'
import { isDebugEnabled } from '@/utils/debug'
import { useToastStore } from '@/stores/toast'
import notificationSounds from '@/services/notificationSounds'
import browserNotifications from '@/services/browserNotifications'

// Resolve a human-friendly title for a conversation (matches the sidebar logic):
// channels show their slug/name, groups their name, DMs the other participant.
function conversationDisplayName(conv) {
  if (!conv) return 'New message'
  if (conv.type === 'channel') return conv.slug ? `#${conv.slug}` : (conv.name || 'Channel')
  if (conv.type === 'group') return conv.name || 'Group Chat'
  const participant = conv.participants?.[0]
  return participant?.display_name || participant?.email?.split('@')[0] || conv.name || 'New message'
}

// Helper to format call message content into a readable preview
function formatCallPreview(content) {
  if (!content) return 'Call'
  const inner = content.replace(/^\[call:/, '').replace(/\]$/, '')
  const parts = inner.split(':')
  if (parts.length < 3) return 'Call'
  const status = parts[0]
  const type = parts[1]
  // Extract emails and non-email info
  const emails = parts.slice(2).filter(p => p.includes('@'))
  const callerName = emails.length > 0 ? emails[emails.length - 1].split('@')[0] : ''
  const rejectorName = status === 'declined' && emails.length > 1 ? emails[0].split('@')[0] : ''
  const duration = parts.slice(2).filter(p => !p.includes('@')).join(':')
  if (status === 'missed') return `${callerName || 'Unknown'} - Missed ${type === 'video' ? 'video ' : ''}call`
  if (status === 'completed') return type === 'video' ? `Video call (${duration || '00:00'})` : `Call (${duration || '00:00'})`
  if (status === 'cancelled') return `${callerName || 'Unknown'} - Missed ${type === 'video' ? 'video ' : ''}call`
  if (status === 'declined') return `${rejectorName || 'Someone'} - Call rejected`
  return 'Call'
}

function formatEmbedPreview(content) {
  if (!content) return 'Shared content'
  const match = content.match(/^\[embed:(\w+):\d+\]$/)
  if (!match) return 'Shared content'
  const labels = {
    drive_file: 'Shared a file',
    drive_folder: 'Shared a folder',
    calendar_event: 'Shared an event',
    board: 'Shared a board',
    board_card: 'Shared a card',
  }
  return labels[match[1]] || 'Shared content'
}

export const useChatStore = defineStore('chat', () => {
  // Get mail sync socket and colleagues store
  const mailSync = useMailSync()
  const colleaguesStore = useColleaguesStore()
  
  // State
  const conversations = ref([])
  const activeConversationId = ref(null)
  const messages = ref({}) // Map of conversationId -> message array
  const unreadCounts = ref({}) // Map of conversationId -> count
  const totalUnread = ref(0)
  const typingStatus = ref({}) // Map of conversationId -> {colleagueId, colleagueName, startedAt}
  const pendingOpenDM = ref(false)
  const loading = ref(false)
  const loadingMessages = ref(false)
  const error = ref(null)
  const hasMoreMessages = ref({}) // Map of conversationId -> boolean
  const replyingTo = ref(null) // Message being replied to
  const pendingScrollToMessage = ref(null) // Message ID to scroll to (from search deep link)
  const conversationSettings = ref({}) // Map of conversationId -> settings object
  const pinnedMessages = ref({}) // Map of conversationId -> pinned message array
  const loadingPinned = ref(false)
  const readReceipts = ref({}) // Map of conversationId -> { colleagueId -> { message_id, read_at } }
  
  // View Together state
  const viewSession = ref(null) // { conversationId, contentType, contentId, participants: [{id, name, position}], startedAt, isPresenter }
  const otherParticipantPosition = ref(null) // Position of the other participant in view session
  const otherParticipantCursor = ref(null) // Cursor position { x, y, user }
  const followMode = ref(false) // When true, auto-follow other participant's navigation
  const syncScrollMode = ref(false) // Presenter mode - when true, others follow scroll automatically
  const isPresenter = ref(false) // Whether current user started the view session
  
  // Typing debounce
  let typingTimeout = null
  let lastTypingSent = 0
  
  // Computed
  const activeConversation = computed(() => {
    return conversationById.value[activeConversationId.value] || null
  })
  
  const activeMessages = computed(() => {
    return messages.value[activeConversationId.value] || []
  })
  
  const activeTyping = computed(() => {
    return typingStatus.value[activeConversationId.value] || null
  })
  
  const sortedConversations = computed(() => {
    return [...conversations.value].sort((a, b) => {
      // Pinned first
      if (a.is_pinned !== b.is_pinned) {
        return a.is_pinned ? -1 : 1
      }
      // Then by last message time
      const aTime = a.last_message_at ? new Date(a.last_message_at).getTime() : 0
      const bTime = b.last_message_at ? new Date(b.last_message_at).getTime() : 0
      return bTime - aTime
    })
  })
  
  const conversationById = computed(() => {
    const map = {}
    for (const c of conversations.value) {
      map[c.id] = c
    }
    return map
  })
  
  // Track if events are already subscribed (prevent double-subscription)
  const eventsSubscribed = ref(false)
  
  // Stable reference for CONNECTED/RECONNECTED handler so it can be unsubscribed
  function sendSubscription() {
    const sent = mailSync.send({ type: 'SUBSCRIBE_CHAT' })
    isDebugEnabled() && console.log('[ChatStore] SUBSCRIBE_CHAT sent:', sent)
  }
  
  // Actions
  async function init() {
    // Subscribe to chat events
    subscribeToEvents()
    
    // Load initial data
    await fetchConversations()
    await fetchUnreadCounts()

    // Load channel categories (non-blocking)
    fetchCategories()

    // Load pending invitations (non-blocking)
    fetchPendingInvitations()
  }
  
  // Lightweight: just subscribe to WS events + fetch unread counts (no conversation load)
  // Called early by FloatingChatWidget so unread badge and live updates work even when collapsed
  function ensureSubscribed() {
    subscribeToEvents()
    fetchUnreadCounts()
    // Also fetch pending invitations so the badge shows on the collapsed widget
    fetchPendingInvitations()
  }
  
  function cleanup() {
    unsubscribeFromEvents()
    eventsSubscribed.value = false
  }
  
  function subscribeToEvents() {
    // Prevent double-subscription
    if (eventsSubscribed.value) return
    eventsSubscribed.value = true
    
    // Send immediately if connected
    sendSubscription()
    
    // Also subscribe on reconnect
    mailSync.on(EventTypes.CONNECTED, sendSubscription)
    mailSync.on(EventTypes.RECONNECTED, sendSubscription)
    
    // Register event handlers
    mailSync.on(EventTypes.CHAT_MESSAGE_NEW, handleNewMessage)
    mailSync.on(EventTypes.CHAT_MESSAGE_EDITED, handleMessageEdited)
    mailSync.on(EventTypes.CHAT_MESSAGE_DELETED, handleMessageDeleted)
    mailSync.on(EventTypes.CHAT_MESSAGE_PINNED, handleMessagePinned)
    mailSync.on(EventTypes.CHAT_REACTION_ADDED, handleReactionAdded)
    mailSync.on(EventTypes.CHAT_REACTION_REMOVED, handleReactionRemoved)
    mailSync.on(EventTypes.CHAT_TYPING_START, handleTypingStart)
    mailSync.on(EventTypes.CHAT_TYPING_STOP, handleTypingStop)
    mailSync.on(EventTypes.CHAT_READ_RECEIPT, handleReadReceipt)
    mailSync.on(EventTypes.CHAT_CONVERSATION_CREATED, handleConversationCreated)
    mailSync.on(EventTypes.CHAT_SETTINGS_UPDATED, handleSettingsUpdated)
    mailSync.on(EventTypes.CHAT_VIEW_SESSION_START, handleViewSessionStart)
    mailSync.on(EventTypes.CHAT_VIEW_SESSION_END, handleViewSessionEnd)
    mailSync.on(EventTypes.CHAT_VIEW_SYNC, handleViewSync)
  }
  
  function unsubscribeFromEvents() {
    mailSync.off(EventTypes.CONNECTED, sendSubscription)
    mailSync.off(EventTypes.RECONNECTED, sendSubscription)
    mailSync.off(EventTypes.CHAT_MESSAGE_NEW, handleNewMessage)
    mailSync.off(EventTypes.CHAT_MESSAGE_EDITED, handleMessageEdited)
    mailSync.off(EventTypes.CHAT_MESSAGE_DELETED, handleMessageDeleted)
    mailSync.off(EventTypes.CHAT_MESSAGE_PINNED, handleMessagePinned)
    mailSync.off(EventTypes.CHAT_REACTION_ADDED, handleReactionAdded)
    mailSync.off(EventTypes.CHAT_REACTION_REMOVED, handleReactionRemoved)
    mailSync.off(EventTypes.CHAT_TYPING_START, handleTypingStart)
    mailSync.off(EventTypes.CHAT_TYPING_STOP, handleTypingStop)
    mailSync.off(EventTypes.CHAT_READ_RECEIPT, handleReadReceipt)
    mailSync.off(EventTypes.CHAT_CONVERSATION_CREATED, handleConversationCreated)
    mailSync.off(EventTypes.CHAT_SETTINGS_UPDATED, handleSettingsUpdated)
    mailSync.off(EventTypes.CHAT_VIEW_SESSION_START, handleViewSessionStart)
    mailSync.off(EventTypes.CHAT_VIEW_SESSION_END, handleViewSessionEnd)
    mailSync.off(EventTypes.CHAT_VIEW_SYNC, handleViewSync)
  }
  
  // Event handlers - payload is the first argument (not event.payload)
  function handleNewMessage(payload) {
    isDebugEnabled() && console.log('[ChatStore] CHAT_MESSAGE_NEW received:', payload)
    
    if (!payload) return
    const { conversation_id, message } = payload
    if (!conversation_id || !message) {
      console.warn('[ChatStore] Invalid message payload:', payload)
      return
    }
    
    // Thread reply → don't add to main conversation, update parent reply_count instead
    if (message.reply_to_id) {
      if (messages.value[conversation_id]) {
        const parent = messages.value[conversation_id].find(m => m.id === message.reply_to_id)
        if (parent) {
          parent.reply_count = (parent.reply_count || 0) + 1
        }
      }
      // If the thread panel is open for this parent, refresh it
      if (activeThreadId.value === message.reply_to_id) {
        openThread(message.reply_to_id)
      }
    } else {
      // Top-level message → add to main conversation view
      if (messages.value[conversation_id]) {
        const exists = messages.value[conversation_id].some(m => m.id === message.id)
        if (!exists) {
          messages.value[conversation_id].push(message)
        }
      } else {
        messages.value[conversation_id] = [message]
      }
    }
    
    // Update conversation preview
    const conv = conversationById.value[conversation_id]
    if (conv) {
      conv.last_message_at = message.created_at
      conv.last_message_preview = (message.content_type === 'voice' || /^\[voice:\d/.test(message.content))
        ? 'Voice message'
        : /^\[gif:/.test(message.content)
          ? 'Sent a GIF'
          : message.content_type === 'call' || /^\[call:/.test(message.content)
            ? formatCallPreview(message.content)
            : message.content_type === 'embed' || /^\[embed:/.test(message.content)
              ? formatEmbedPreview(message.content)
              : message.content?.substring(0, 100)
      conv.last_message_sender_id = message.sender_id
      conv.message_count = (conv.message_count || 0) + 1
      
      // Increment unread if not active
      if (activeConversationId.value !== conversation_id) {
        conv.unread_count = (conv.unread_count || 0) + 1
        unreadCounts.value[conversation_id] = conv.unread_count
        totalUnread.value = Object.values(unreadCounts.value).reduce((a, b) => a + b, 0)
      }
    } else {
      isDebugEnabled() && console.log('[ChatStore] Conversation not found, fetching:', conversation_id)
      if (activeConversationId.value !== conversation_id) {
        unreadCounts.value[conversation_id] = (unreadCounts.value[conversation_id] || 0) + 1
        totalUnread.value = Object.values(unreadCounts.value).reduce((a, b) => a + b, 0)
      }
      fetchConversations()
    }
    
    // Clear typing status for sender
    if (typingStatus.value[conversation_id]?.colleague_id === message.sender_id) {
      delete typingStatus.value[conversation_id]
    }

    // Teams-style new-message pop. Skip our own messages, the conversation we
    // are actively reading (focused), and any conversation the user has muted,
    // so we only chime for things we'd miss and want to hear about.
    const currentColleagueId = colleaguesStore.currentColleague?.id
    const isOwnMessage = currentColleagueId && message.sender_id === currentColleagueId
    const viewingThisConversation = activeConversationId.value === conversation_id && document.hasFocus()
    const isMuted = !!conv?.is_muted
    if (!isOwnMessage && !viewingThisConversation && !isMuted) {
      notificationSounds.playChatSound()
      // Pop a desktop/OS toast too (parity with new-email alerts). Fires while
      // the app is open; suppressed automatically when you're focused on this
      // conversation. Honors the master + per-type notification toggles.
      browserNotifications.showNewChat({
        title: conversationDisplayName(conv),
        body: conv?.last_message_preview || message.content || 'You have a new message',
        conversationId: conversation_id,
      })
    }
  }
  
  function handleMessageEdited(payload) {
    if (!payload) return
    const { conversation_id, message_id, content, edited_at } = payload
    if (!conversation_id || !message_id) return
    
    if (messages.value[conversation_id]) {
      const msg = messages.value[conversation_id].find(m => m.id === message_id)
      if (msg) {
        msg.content = content
        msg.is_edited = true
        msg.edited_at = edited_at
      }
    }
  }
  
  function handleMessageDeleted(payload) {
    if (!payload) return
    const { conversation_id, message_id } = payload
    if (!conversation_id || !message_id) return
    
    if (messages.value[conversation_id]) {
      const index = messages.value[conversation_id].findIndex(m => m.id === message_id)
      if (index > -1) {
        messages.value[conversation_id].splice(index, 1)
      }
    }
  }
  
  function handleMessagePinned(payload) {
    if (!payload) return
    const { conversation_id, message_id, is_pinned } = payload
    if (!conversation_id || !message_id) return
    
    if (messages.value[conversation_id]) {
      const msg = messages.value[conversation_id].find(m => m.id === message_id)
      if (msg) {
        msg.is_pinned = is_pinned
        msg.pinned_at = is_pinned ? new Date().toISOString() : null
      }
    }
    
    // Also update pinned messages cache if we have one
    if (pinnedMessages.value[conversation_id]) {
      if (is_pinned) {
        // Refresh pinned messages
        fetchPinnedMessages(conversation_id)
      } else {
        // Remove from pinned cache
        pinnedMessages.value[conversation_id] = pinnedMessages.value[conversation_id].filter(m => m.id !== message_id)
      }
    }
  }
  
  function handleReactionAdded(payload) {
    if (!payload) return
    const { conversation_id, message_id, colleague_id, colleague_name, emoji } = payload
    if (!conversation_id || !message_id) return
    
    if (messages.value[conversation_id]) {
      const msg = messages.value[conversation_id].find(m => m.id === message_id)
      if (msg) {
        if (!msg.reactions) msg.reactions = []
        // Check if already exists
        const exists = msg.reactions.some(r => r.colleague_id === colleague_id && r.emoji === emoji)
        if (!exists) {
          msg.reactions.push({ colleague_id, colleague_name, emoji, created_at: new Date().toISOString() })
        }
      }
    }
  }
  
  function handleReactionRemoved(payload) {
    if (!payload) return
    const { conversation_id, message_id, colleague_id, emoji } = payload
    if (!conversation_id || !message_id) return
    
    if (messages.value[conversation_id]) {
      const msg = messages.value[conversation_id].find(m => m.id === message_id)
      if (msg && msg.reactions) {
        const index = msg.reactions.findIndex(r => r.colleague_id === colleague_id && r.emoji === emoji)
        if (index > -1) {
          msg.reactions.splice(index, 1)
        }
      }
    }
  }
  
  function handleTypingStart(payload) {
    if (!payload) return
    const { conversation_id, colleague_id, colleague_name } = payload
    if (!conversation_id) return
    
    // Ignore our own typing events
    const currentColleague = colleaguesStore.currentColleague
    if (currentColleague && colleague_id === currentColleague.id) {
      return
    }
    
    typingStatus.value[conversation_id] = {
      colleague_id,
      colleague_name,
      startedAt: Date.now()
    }
    
    // Auto-clear after 5 seconds (in case stop event is missed)
    setTimeout(() => {
      if (typingStatus.value[conversation_id]?.colleague_id === colleague_id) {
        delete typingStatus.value[conversation_id]
      }
    }, 5000)
  }
  
  function handleTypingStop(payload) {
    if (!payload) return
    const { conversation_id, colleague_id } = payload
    if (!conversation_id) return
    
    // Ignore our own typing events
    const currentColleague = colleaguesStore.currentColleague
    if (currentColleague && colleague_id === currentColleague.id) {
      return
    }
    
    if (typingStatus.value[conversation_id]?.colleague_id === colleague_id) {
      delete typingStatus.value[conversation_id]
    }
  }
  
  function handleReadReceipt(payload) {
    if (!payload) return
    const { conversation_id, colleague_id, message_id, read_at } = payload
    
    // Cross-device read sync: if this read receipt is from the current user
    // (on another device), update local unread count to 0 for that conversation.
    const currentColleague = colleaguesStore.currentColleague
    if (currentColleague && colleague_id === currentColleague.id) {
      // This is our own read receipt from another device - sync read state
      const conv = conversationById.value[conversation_id]
      if (conv && conv.unread_count > 0) {
        conv.unread_count = 0
        unreadCounts.value[conversation_id] = 0
        totalUnread.value = Object.values(unreadCounts.value).reduce((a, b) => a + b, 0)
        isDebugEnabled() && console.log(`[Chat] Cross-device read sync: conversation ${conversation_id} marked as read`)
      }
      return
    }
    
    // For other participants' read receipts: update "last read" indicators
    // Store the last read message per colleague for "seen" checkmarks
    if (!readReceipts.value[conversation_id]) readReceipts.value[conversation_id] = {}
    readReceipts.value[conversation_id][colleague_id] = {
      message_id,
      read_at
    }
  }
  
  function handleConversationCreated(payload) {
    if (!payload) return
    const { conversation } = payload
    if (!conversation) return
    
    // Add to conversations if not already present
    const exists = conversations.value.some(c => c.id === conversation.id)
    if (!exists) {
      conversations.value.unshift(conversation)
    }
  }
  
  function handleSettingsUpdated(payload) {
    if (!payload) return
    const { conversation_id, settings } = payload
    if (!conversation_id || !settings) return
    
    isDebugEnabled() && console.log('[ChatStore] Settings update received via WebSocket:', { conversation_id, settings })
    
    // Update local settings cache - spread to ensure new object reference for Vue reactivity
    conversationSettings.value = {
      ...conversationSettings.value,
      [conversation_id]: { ...settings }
    }
  }
  
  // View Together event handlers
  function handleViewSessionStart(payload) {
    if (!payload) return
    const { conversation_id, started_by, content_type, content_id, started_at } = payload
    if (!conversation_id) return
    
    // Only track if it's for the active conversation
    if (conversation_id === activeConversationId.value) {
      // Check if we're the one who started it
      const currentColleague = colleaguesStore.currentColleague
      const weStartedIt = currentColleague && started_by?.id === currentColleague.id
      
      viewSession.value = {
        conversationId: conversation_id,
        startedBy: started_by,
        contentType: content_type,
        contentId: content_id,
        startedAt: started_at,
        participants: [started_by]
      }
      isPresenter.value = weStartedIt
      isDebugEnabled() && console.log('[ChatStore] View Together session started:', viewSession.value, 'isPresenter:', weStartedIt)
    }
  }
  
  function handleViewSessionEnd(payload) {
    if (!payload) return
    const { conversation_id } = payload
    
    if (viewSession.value?.conversationId === conversation_id) {
      isDebugEnabled() && console.log('[ChatStore] View Together session ended')
      viewSession.value = null
      otherParticipantPosition.value = null
      otherParticipantCursor.value = null
      followMode.value = false
      syncScrollMode.value = false
      isPresenter.value = false
    }
  }
  
  function handleViewSync(payload) {
    if (!payload) return
    const { conversation_id, user, position, cursor, syncScroll, timestamp } = payload
    if (!conversation_id) return
    
    // Update other participant's position/cursor if it's not from us
    if (viewSession.value?.conversationId === conversation_id) {
      // Get current colleague to check if this is from the other user
      const currentColleague = colleaguesStore.currentColleague
      if (currentColleague && user?.id !== currentColleague.id) {
        // Update cursor position if provided
        if (cursor) {
          otherParticipantCursor.value = {
            user,
            ...cursor,
            position, // Include position data with cursor for "same view" check
            timestamp
          }
        }
        
        // Update navigation position if provided
        if (position) {
          otherParticipantPosition.value = {
            user,
            position,
            timestamp
          }
        }
        
        // Update sync scroll mode if specified by presenter
        if (syncScroll !== undefined) {
          syncScrollMode.value = syncScroll
        }
      }
    }
  }
  
  // API calls
  async function fetchConversations() {
    loading.value = true
    error.value = null
    
    try {
      const response = await api.get('/chat/conversations')
      if (response.data.success) {
        conversations.value = response.data.data.conversations || []
        
        // Subscribe to cross-domain presence for chat partners on different email domains
        _subscribeCrossDomainPresence()
      }
    } catch (e) {
      error.value = e.message
      console.error('Failed to fetch conversations:', e)
    } finally {
      loading.value = false
    }
  }
  
  /**
   * Extract all participant emails from conversations and subscribe to
   * cross-domain presence updates (for chat partners on different email domains).
   */
  function _subscribeCrossDomainPresence() {
    try {
      const colleaguesStore = useColleaguesStore()
      const allParticipantEmails = new Set()
      
      for (const conv of conversations.value) {
        if (conv.participants && Array.isArray(conv.participants)) {
          for (const p of conv.participants) {
            if (p.email) {
              allParticipantEmails.add(p.email.toLowerCase())
            }
          }
        }
      }
      
      if (allParticipantEmails.size > 0) {
        colleaguesStore.subscribeToCrossDomainUsers(Array.from(allParticipantEmails))
      }
    } catch (e) {
      console.error('[Chat] Failed to subscribe cross-domain presence:', e)
    }
  }
  
  let _initHydratedAt = 0
  const INIT_HYDRATE_COOLDOWN = 15000

  function markInitPending() {
    _initHydratedAt = Date.now()
  }

  function hydrateFromInit(data) {
    if (!data) return
    if (data.unread_by_conversation !== undefined) unreadCounts.value = data.unread_by_conversation || {}
    if (data.unread_total !== undefined) totalUnread.value = data.unread_total || 0
    if (data.invitations !== undefined) pendingInvitations.value = data.invitations || []
    _initHydratedAt = Date.now()
  }

  async function initChat() {
    _initHydratedAt = Date.now()
    try {
      const response = await api.get('/chat/init')
      if (response.data.success) {
        return response.data.data
      }
    } catch (e) {
      console.error('[Chat] initChat failed:', e)
      _initHydratedAt = 0
    }
    return null
  }

  async function fetchUnreadCounts() {
    if (_initHydratedAt && (Date.now() - _initHydratedAt < INIT_HYDRATE_COOLDOWN)) return
    try {
      const response = await api.get('/chat/unread')
      if (response.data.success) {
        unreadCounts.value = response.data.data.by_conversation || {}
        totalUnread.value = response.data.data.total || 0
      }
    } catch (e) {
      console.error('Failed to fetch unread counts:', e)
    }
  }
  
  async function openDMWith(colleagueId) {
    loading.value = true
    error.value = null
    
    try {
      const response = await api.post(`/chat/dm/${colleagueId}`)
      if (response.data.success) {
        const conversation = response.data.data.conversation
        
        // Add to conversations if not present
        const exists = conversations.value.some(c => c.id === conversation.id)
        if (!exists) {
          conversations.value.unshift(conversation)
        } else {
          // Update existing
          const index = conversations.value.findIndex(c => c.id === conversation.id)
          if (index > -1) {
            conversations.value[index] = { ...conversations.value[index], ...conversation }
          }
        }
        
        // Set as active
        activeConversationId.value = conversation.id
        
        // Load messages
        await loadMessages(conversation.id)
        
        return { success: true, conversation }
      }
      return { success: false, error: response.data.error }
    } catch (e) {
      error.value = e.message
      return { success: false, error: e.message }
    } finally {
      loading.value = false
    }
  }
  
  async function openDMAndExpand(colleagueId) {
    // Open the DM FIRST so activeConversationId is populated, then flip
    // the pendingOpenDM trigger. The FloatingChatWidget watcher reads
    // chatStore.activeConversationId synchronously when the flag flips,
    // so flipping it before openDMWith resolves leaves the widget on the
    // chat tab with selectedConversationId === null -- which renders an
    // empty popout (no header, no input, nothing).
    const result = await openDMWith(colleagueId)
    if (result.success) {
      pendingOpenDM.value = true
    }
    return result
  }

  async function setActiveConversation(conversationId) {
    activeConversationId.value = conversationId
    replyingTo.value = null
    
    if (conversationId && !messages.value[conversationId]) {
      await loadMessages(conversationId)
    }
    
    // Mark as read
    if (conversationId) {
      await markAsRead(conversationId)
    }
  }
  
  async function loadMessages(conversationId, beforeId = null) {
    if (!conversationId) return
    
    loadingMessages.value = true
    
    try {
      const params = { limit: 50 }
      if (beforeId) params.before_id = beforeId
      
      const response = await api.get(`/chat/conversations/${conversationId}/messages`, { params })
      if (response.data.success) {
        const newMessages = response.data.data.messages || []
        
        if (beforeId && messages.value[conversationId]) {
          // Prepend older messages
          messages.value[conversationId] = [...newMessages, ...messages.value[conversationId]]
        } else {
          // Initial load
          messages.value[conversationId] = newMessages
        }
        
        hasMoreMessages.value[conversationId] = response.data.data.has_more
      }
    } catch (e) {
      console.error('Failed to load messages:', e)
    } finally {
      loadingMessages.value = false
    }
  }
  
  async function loadOlderMessages() {
    if (!activeConversationId.value) return
    if (!hasMoreMessages.value[activeConversationId.value]) return
    if (loadingMessages.value) return // Guard against concurrent loads
    
    const currentMessages = messages.value[activeConversationId.value] || []
    if (currentMessages.length === 0) return
    
    const oldestId = currentMessages[0]?.id
    await loadMessages(activeConversationId.value, oldestId)
  }
  
  async function sendMessage(content, attachments = null, voiceDuration = null) {
    if (!activeConversationId.value) return { success: false, error: 'No active conversation' }
    
    const conversationId = activeConversationId.value
    
    try {
      const payload = { content }
      if (replyingTo.value) {
        payload.reply_to_id = replyingTo.value.id
      }
      if (attachments && attachments.length > 0) {
        payload.attachments = attachments
      }
      if (voiceDuration !== null) {
        payload.voice_duration = voiceDuration
      }
      
      const response = await api.post(`/chat/conversations/${conversationId}/messages`, payload)
      
      if (response.data.success) {
        const newMessage = response.data.data.message
        
        // Clear reply
        replyingTo.value = null
        
        // Thread reply → update parent's reply_count, don't add to main view
        if (newMessage.reply_to_id && messages.value[conversationId]) {
          const parent = messages.value[conversationId].find(m => m.id === newMessage.reply_to_id)
          if (parent) {
            parent.reply_count = (parent.reply_count || 0) + 1
          }
        } else {
          // Top-level message → add to main conversation for instant feedback
          if (messages.value[conversationId]) {
            const exists = messages.value[conversationId].some(m => m.id === newMessage.id)
            if (!exists) {
              messages.value[conversationId].push(newMessage)
            }
          } else {
            messages.value[conversationId] = [newMessage]
          }
        }
        
        // Update conversation preview
        const conv = conversationById.value[conversationId]
        if (conv) {
          conv.last_message_at = newMessage.created_at
          conv.last_message_preview = (newMessage.content_type === 'voice' || /^\[voice:\d/.test(newMessage.content))
            ? 'Voice message'
            : /^\[gif:/.test(newMessage.content)
              ? 'Sent a GIF'
              : newMessage.content_type === 'call' || /^\[call:/.test(newMessage.content)
                ? formatCallPreview(newMessage.content)
                : newMessage.content_type === 'embed' || /^\[embed:/.test(newMessage.content)
                  ? formatEmbedPreview(newMessage.content)
                  : newMessage.content?.substring(0, 100)
          conv.last_message_sender_id = newMessage.sender_id
        }
        
        return { success: true, message: newMessage }
      }
      return { success: false, error: response.data.error }
    } catch (e) {
      return { success: false, error: e.message }
    }
  }
  
  async function editMessage(messageId, content) {
    try {
      const response = await api.patch(`/chat/messages/${messageId}`, { content })
      return { success: response.data.success, error: response.data.error }
    } catch (e) {
      return { success: false, error: e.message }
    }
  }
  
  async function deleteMessage(messageId) {
    try {
      const response = await api.delete(`/chat/messages/${messageId}`)
      return { success: response.data.success, error: response.data.error }
    } catch (e) {
      return { success: false, error: e.message }
    }
  }
  
  async function deleteThread(parentMessageId) {
    try {
      const response = await api.delete(`/chat/messages/${parentMessageId}/thread`)
      if (response.data.success) {
        // Remove thread replies from local messages
        const convId = activeConversationId.value
        if (convId && messages.value[convId]) {
          // The thread is closed by the store when replies are cleared
          // The WebSocket broadcast will handle removing the replies for other clients
        }
        // Close the thread panel
        closeThread()
      }
      return response.data
    } catch (e) {
      return { success: false, error: e.message }
    }
  }

  async function addReaction(messageId, emoji) {
    try {
      const response = await api.post(`/chat/messages/${messageId}/reactions`, { emoji })
      return { success: response.data.success, error: response.data.error }
    } catch (e) {
      return { success: false, error: e.message }
    }
  }
  
  async function removeReaction(messageId, emoji) {
    try {
      const response = await api.delete(`/chat/messages/${messageId}/reactions/${encodeURIComponent(emoji)}`)
      return { success: response.data.success, error: response.data.error }
    } catch (e) {
      return { success: false, error: e.message }
    }
  }
  
  async function toggleReaction(messageId, emoji) {
    // Check if reaction already exists
    const conversationId = activeConversationId.value
    if (!conversationId || !messages.value[conversationId]) return
    
    const msg = messages.value[conversationId].find(m => m.id === messageId)
    if (!msg) return
    
    // Get current user's colleague ID from colleagues store
    const currentColleague = colleaguesStore.currentColleague
    if (!currentColleague) return
    
    const hasReaction = msg.reactions?.some(r => 
      r.colleague_id === currentColleague.id && r.emoji === emoji
    )
    
    if (hasReaction) {
      return removeReaction(messageId, emoji)
    } else {
      return addReaction(messageId, emoji)
    }
  }
  
  async function togglePinMessage(messageId) {
    try {
      const response = await api.post(`/chat/messages/${messageId}/pin`)
      if (response.data.success) {
        const isPinned = response.data.data.is_pinned
        // Update local message state immediately
        const conversationId = activeConversationId.value
        if (conversationId && messages.value[conversationId]) {
          const msg = messages.value[conversationId].find(m => m.id === messageId)
          if (msg) {
            msg.is_pinned = isPinned
            msg.pinned_at = isPinned ? new Date().toISOString() : null
          }
        }
        return { success: true, is_pinned: isPinned }
      }
      return { success: false, error: response.data.error }
    } catch (e) {
      return { success: false, error: e.message }
    }
  }
  
  async function fetchPinnedMessages(conversationId) {
    if (!conversationId) return
    loadingPinned.value = true
    try {
      const response = await api.get(`/chat/conversations/${conversationId}/pinned`)
      if (response.data.success) {
        pinnedMessages.value[conversationId] = response.data.data.messages
      }
    } catch (e) {
      console.error('Failed to fetch pinned messages:', e)
    } finally {
      loadingPinned.value = false
    }
  }
  
  async function markAsRead(conversationId) {
    if (!conversationId) return
    
    try {
      await api.post(`/chat/conversations/${conversationId}/read`)
      
      // Update local state
      const conv = conversationById.value[conversationId]
      if (conv) {
        conv.unread_count = 0
      }
      unreadCounts.value[conversationId] = 0
      totalUnread.value = Object.values(unreadCounts.value).reduce((a, b) => a + b, 0)
    } catch (e) {
      console.error('Failed to mark as read:', e)
    }
  }
  
  async function sendTypingStatus(isTyping) {
    if (!activeConversationId.value) return
    
    // Debounce - don't send more than once per second
    const now = Date.now()
    if (isTyping && now - lastTypingSent < 1000) return
    lastTypingSent = now
    
    try {
      await api.post(`/chat/conversations/${activeConversationId.value}/typing`, { is_typing: isTyping })
    } catch (e) {
      // Ignore typing errors
    }
    
    // Auto-stop typing after 3 seconds of inactivity
    if (isTyping) {
      if (typingTimeout) clearTimeout(typingTimeout)
      typingTimeout = setTimeout(() => {
        sendTypingStatus(false)
      }, 3000)
    }
  }
  
  async function togglePin(conversationId) {
    try {
      const response = await api.post(`/chat/conversations/${conversationId}/pin`)
      if (response.data.success) {
        const conv = conversationById.value[conversationId]
        if (conv) {
          conv.is_pinned = response.data.data.is_pinned
        }
      }
      return response.data
    } catch (e) {
      return { success: false, error: e.message }
    }
  }
  
  async function toggleMute(conversationId) {
    try {
      const response = await api.post(`/chat/conversations/${conversationId}/mute`)
      if (response.data.success) {
        const conv = conversationById.value[conversationId]
        if (conv) {
          conv.is_muted = response.data.data.is_muted
        }
      }
      return response.data
    } catch (e) {
      return { success: false, error: e.message }
    }
  }
  
  async function archiveConversation(conversationId) {
    try {
      const response = await api.post(`/chat/conversations/${conversationId}/archive`)
      if (response.data.success) {
        // Remove from list
        const index = conversations.value.findIndex(c => c.id === conversationId)
        if (index > -1) {
          conversations.value.splice(index, 1)
        }
        // Clear active if this was active
        if (activeConversationId.value === conversationId) {
          activeConversationId.value = null
        }
      }
      return response.data
    } catch (e) {
      return { success: false, error: e.message }
    }
  }
  
  async function deleteConversation(conversationId) {
    try {
      const response = await api.delete(`/chat/conversations/${conversationId}`)
      if (response.data.success) {
        // Remove from list
        const index = conversations.value.findIndex(c => c.id === conversationId)
        if (index > -1) {
          conversations.value.splice(index, 1)
        }
        // Clear messages
        delete messages.value[conversationId]
        // Clear active if this was active
        if (activeConversationId.value === conversationId) {
          activeConversationId.value = null
        }
      }
      return response.data
    } catch (e) {
      return { success: false, error: e.message }
    }
  }

  async function searchMessages(query, conversationId = null) {
    try {
      const params = { q: query }
      if (conversationId) params.conversation_id = conversationId
      
      const response = await api.get('/chat/search', { params })
      if (response.data.success) {
        return { success: true, messages: response.data.data.messages }
      }
      return { success: false, error: response.data.error }
    } catch (e) {
      return { success: false, error: e.message }
    }
  }
  
  // Attachment methods
  async function getConversationAttachments(conversationId, category = null) {
    try {
      const params = {}
      if (category) params.category = category
      
      const response = await api.get(`/chat/conversations/${conversationId}/attachments`, { params })
      if (response.data.success) {
        return { 
          success: true, 
          attachments: response.data.data.attachments,
          grouped: response.data.data.grouped,
          counts: response.data.data.counts
        }
      }
      return { success: false, error: response.data.error }
    } catch (e) {
      return { success: false, error: e.message }
    }
  }
  
  async function saveAttachmentsToDrive(conversationId, attachmentIds = null) {
    try {
      const payload = {}
      if (attachmentIds) payload.attachment_ids = attachmentIds
      
      const response = await api.post(`/chat/conversations/${conversationId}/attachments/save-to-drive`, payload)
      if (response.data.success) {
        return { 
          success: true, 
          savedCount: response.data.data.saved_count,
          total: response.data.data.total,
          folderPath: response.data.data.folder_path,
          folderId: response.data.data.folder_id,
          errors: response.data.data.errors
        }
      }
      return { success: false, error: response.data.error }
    } catch (e) {
      return { success: false, error: e.message }
    }
  }
  
  async function saveMessageAttachmentsToDrive(conversationId, messageId) {
    try {
      const response = await api.post(`/chat/conversations/${conversationId}/attachments/save-to-drive`, {
        message_id: messageId
      })
      if (response.data.success) {
        const toast = useToastStore()
        toast.success(`Saved ${response.data.data.saved_count} files to Drive`)
        return { 
          success: true, 
          savedCount: response.data.data.saved_count,
          folderPath: response.data.data.folder_path
        }
      }
      return { success: false, error: response.data.error }
    } catch (e) {
      const toast = useToastStore()
      toast.error(e.message || 'Failed to save to Drive')
      return { success: false, error: e.message }
    }
  }
  
  function setReplyingTo(message) {
    replyingTo.value = message
  }
  
  function clearReplyingTo() {
    replyingTo.value = null
  }
  
  // Settings
  async function getConversationSettings(conversationId, forceRefresh = false) {
    try {
      // Check cache first (skip on force refresh)
      if (!forceRefresh && conversationSettings.value[conversationId]) {
        return { success: true, settings: conversationSettings.value[conversationId] }
      }
      
      const response = await api.get(`/chat/conversations/${conversationId}/settings`)
      isDebugEnabled() && console.log('[ChatStore] Loaded settings from server:', response.data)
      if (response.data.success) {
        conversationSettings.value[conversationId] = response.data.data || {}
        return { success: true, settings: conversationSettings.value[conversationId] }
      }
      return { success: false, error: response.data.error }
    } catch (e) {
      console.error('[ChatStore] Failed to load settings:', e)
      return { success: false, error: e.message }
    }
  }
  
  async function updateConversationSettings(conversationId, settings) {
    try {
      isDebugEnabled() && console.log('[ChatStore] Saving settings to server:', { conversationId, settings })
      const response = await api.put(`/chat/conversations/${conversationId}/settings`, settings)
      isDebugEnabled() && console.log('[ChatStore] Save response:', response.data)
      if (response.data.success) {
        // Update local cache with spread to ensure reactivity
        conversationSettings.value = {
          ...conversationSettings.value,
          [conversationId]: response.data.data || {}
        }
        return { success: true, settings: conversationSettings.value[conversationId] }
      }
      console.error('[ChatStore] Save failed:', response.data.error)
      return { success: false, error: response.data.error }
    } catch (e) {
      console.error('[ChatStore] Save exception:', e)
      return { success: false, error: e.message }
    }
  }
  
  // View Together API methods
  let viewSyncThrottle = null
  
  async function startViewSession(contentType, contentId) {
    if (!activeConversationId.value) {
      return { success: false, error: 'No active conversation' }
    }
    
    try {
      const response = await api.post(`/chat/conversations/${activeConversationId.value}/view-session`, {
        content_type: contentType,
        content_id: contentId
      })
      
      if (response.data.success) {
        viewSession.value = {
          conversationId: activeConversationId.value,
          contentType,
          contentId,
          startedBy: response.data.data.started_by,
          startedAt: response.data.data.started_at,
          participants: [response.data.data.started_by]
        }
        return { success: true, session: viewSession.value }
      }
      return { success: false, error: response.data.error }
    } catch (e) {
      console.error('[ChatStore] Failed to start view session:', e)
      return { success: false, error: e.message }
    }
  }
  
  async function endViewSession() {
    if (!viewSession.value?.conversationId) {
      viewSession.value = null
      otherParticipantPosition.value = null
      otherParticipantCursor.value = null
      followMode.value = false
      syncScrollMode.value = false
      isPresenter.value = false
      return { success: true }
    }
    
    try {
      const response = await api.delete(`/chat/conversations/${viewSession.value.conversationId}/view-session`)
      
      viewSession.value = null
      otherParticipantPosition.value = null
      otherParticipantCursor.value = null
      followMode.value = false
      syncScrollMode.value = false
      isPresenter.value = false
      
      if (response.data.success) {
        return { success: true }
      }
      return { success: false, error: response.data.error }
    } catch (e) {
      console.error('[ChatStore] Failed to end view session:', e)
      viewSession.value = null
      otherParticipantPosition.value = null
      otherParticipantCursor.value = null
      followMode.value = false
      syncScrollMode.value = false
      isPresenter.value = false
      return { success: false, error: e.message }
    }
  }
  
  function syncViewPosition(position) {
    if (!viewSession.value?.conversationId) return
    
    // Throttle to max 10 updates per second (100ms)
    if (viewSyncThrottle) return
    
    viewSyncThrottle = setTimeout(() => {
      viewSyncThrottle = null
    }, 100)
    
    // Fire and forget - don't wait for response
    api.put(`/chat/conversations/${viewSession.value.conversationId}/view-session/sync`, { position })
      .catch(e => console.warn('[ChatStore] View sync failed:', e))
  }
  
  let cursorSyncThrottle = null
  
  function syncCursorPosition(x, y, containerWidth, containerHeight, currentPosition = null) {
    if (!viewSession.value?.conversationId) return
    
    // Throttle to max 20 updates per second (50ms) for smoother cursor
    if (cursorSyncThrottle) return
    
    cursorSyncThrottle = setTimeout(() => {
      cursorSyncThrottle = null
    }, 50)
    
    // Send relative position (0-1) so it works across different screen sizes
    const cursor = {
      x: x / containerWidth,
      y: y / containerHeight,
      containerWidth,
      containerHeight
    }
    
    // Include position data so other user knows we're on the same content
    const payload = { cursor }
    if (currentPosition) {
      payload.position = currentPosition
    }
    
    // Fire and forget
    api.put(`/chat/conversations/${viewSession.value.conversationId}/view-session/sync`, payload)
      .catch(e => console.warn('[ChatStore] Cursor sync failed:', e))
  }
  
  function toggleFollowMode() {
    followMode.value = !followMode.value
    return followMode.value
  }
  
  function setFollowMode(enabled) {
    followMode.value = enabled
  }
  
  function toggleSyncScrollMode() {
    if (!isPresenter.value) return false // Only presenter can toggle
    
    syncScrollMode.value = !syncScrollMode.value
    
    // Broadcast sync scroll state change to other participants
    if (viewSession.value?.conversationId) {
      api.put(`/chat/conversations/${viewSession.value.conversationId}/view-session/sync`, { 
        syncScroll: syncScrollMode.value 
      }).catch(e => console.warn('[ChatStore] Sync scroll toggle failed:', e))
    }
    
    return syncScrollMode.value
  }
  
  // Helpers
  function getColleagueAvatar(colleague) {
    if (colleague?.avatar_path) {
      return colleague.avatar_path
    }
    return null
  }
  
  function getColleagueInitials(colleague) {
    const name = colleague?.display_name || colleague?.email || '?'
    return name.substring(0, 2).toUpperCase()
  }
  
  function getColleagueColor(colleague) {
    // Extended color palette - 16 distinct colors for better variety
    const colors = [
      'bg-rose-500',      // 0 - red/pink
      'bg-orange-500',    // 1 - orange
      'bg-amber-500',     // 2 - amber/yellow
      'bg-lime-500',      // 3 - lime green
      'bg-emerald-500',   // 4 - green
      'bg-teal-500',      // 5 - teal
      'bg-cyan-500',      // 6 - cyan
      'bg-sky-500',       // 7 - light blue
      'bg-blue-500',      // 8 - blue
      'bg-indigo-500',    // 9 - indigo
      'bg-violet-500',    // 10 - violet
      'bg-purple-500',    // 11 - purple
      'bg-fuchsia-500',   // 12 - fuchsia
      'bg-pink-500',      // 13 - pink
      'bg-red-500',       // 14 - red
      'bg-stone-500'      // 15 - neutral
    ]
    
    // Use colleague ID for consistent, unique colors in small groups
    // Falls back to email hash if ID not available
    if (colleague?.id) {
      return colors[colleague.id % colors.length]
    }
    
    // Fallback: better hash using email
    const email = colleague?.email || ''
    let hash = 0
    for (let i = 0; i < email.length; i++) {
      hash = ((hash << 5) - hash) + email.charCodeAt(i)
      hash = hash & hash // Convert to 32bit integer
    }
    return colors[Math.abs(hash) % colors.length]
  }
  
  // ========================================
  // INVITATION MANAGEMENT
  // ========================================
  
  const pendingInvitations = ref([])
  const loadingInvitations = ref(false)
  
  // Invite external user to chat
  async function inviteToChat(email) {
    try {
      const response = await api.post('/chat/invite', { email })
      if (response.data.success) {
        // Refresh conversations to include the new one
        await fetchConversations()
        return { 
          success: true, 
          conversationId: response.data.data?.conversation_id,
          inviteId: response.data.data?.invite_id 
        }
      }
      return { success: false, error: response.data.message || 'Failed to send invitation' }
    } catch (e) {
      console.error('Failed to invite to chat:', e)
      return { success: false, error: e.response?.data?.message || e.message }
    }
  }
  
  async function fetchPendingInvitations() {
    if (_initHydratedAt && (Date.now() - _initHydratedAt < INIT_HYDRATE_COOLDOWN)) return
    loadingInvitations.value = true
    try {
      const response = await api.get('/chat/invitations')
      if (response.data.success) {
        pendingInvitations.value = response.data.data?.invitations || []
      }
    } catch (e) {
      console.error('Failed to fetch pending invitations:', e)
    } finally {
      loadingInvitations.value = false
    }
  }
  
  // Accept a pending invitation
  async function acceptInvitation(invitationId) {
    try {
      const response = await api.post(`/chat/invitations/${invitationId}/accept`)
      if (response.data.success) {
        // Remove from pending list
        pendingInvitations.value = pendingInvitations.value.filter(i => i.id !== invitationId)
        // Refresh conversations to show the new DM
        await fetchConversations()
        const conversationId = response.data.data?.conversation_id
        if (conversationId) {
          setActiveConversation(conversationId)
        }
        return { success: true, conversationId }
      }
      return { success: false, error: response.data.error || 'Failed to accept invitation' }
    } catch (e) {
      console.error('Failed to accept invitation:', e)
      return { success: false, error: e.response?.data?.error || e.message }
    }
  }
  
  // Decline a pending invitation
  async function declineInvitation(invitationId) {
    try {
      const response = await api.post(`/chat/invitations/${invitationId}/decline`)
      if (response.data.success) {
        // Remove from pending list
        pendingInvitations.value = pendingInvitations.value.filter(i => i.id !== invitationId)
        return { success: true }
      }
      return { success: false, error: response.data.error || 'Failed to decline invitation' }
    } catch (e) {
      console.error('Failed to decline invitation:', e)
      return { success: false, error: e.response?.data?.error || e.message }
    }
  }
  
  // Process invite token from email link - look up and auto-accept
  async function processInviteToken(token) {
    try {
      // First look up the invitation
      const lookupRes = await api.get(`/chat/invitations/token/${token}`)
      if (!lookupRes.data.success) {
        return { success: false, error: lookupRes.data.error || 'Invalid invitation link' }
      }
      
      const invitation = lookupRes.data.data?.invitation
      if (!invitation) {
        return { success: false, error: 'Invitation not found' }
      }
      
      // If already accepted, navigate to existing conversation
      if (invitation.status === 'accepted' && invitation.conversation_id) {
        await fetchConversations()
        setActiveConversation(invitation.conversation_id)
        return { success: true, conversationId: invitation.conversation_id, alreadyAccepted: true }
      }
      
      // If expired or declined
      if (invitation.status !== 'pending') {
        return { success: false, error: `This invitation has been ${invitation.status}` }
      }
      
      // Accept it
      return await acceptInvitation(invitation.id)
    } catch (e) {
      console.error('Failed to process invite token:', e)
      return { success: false, error: e.response?.data?.error || e.message }
    }
  }
  
  // ========================================
  // CHANNELS
  // ========================================

  async function browseChannels(search = null) {
    try {
      const params = search ? { search } : {}
      const response = await api.get('/chat/channels', { params })
      if (response.data.success) {
        return { success: true, channels: response.data.data?.channels || [] }
      }
      return { success: false, error: response.data.error }
    } catch (e) {
      return { success: false, error: e.response?.data?.error || e.message }
    }
  }

  async function createChannel(data) {
    try {
      const response = await api.post('/chat/channels', data)
      if (response.data.success) {
        await fetchConversations()
        const channel = response.data.data?.channel
        if (channel) {
          setActiveConversation(channel.id)
        }
        return { success: true, channel }
      }
      return { success: false, error: response.data.error }
    } catch (e) {
      return { success: false, error: e.response?.data?.error || e.message }
    }
  }

  async function joinChannel(channelId) {
    try {
      const response = await api.post(`/chat/channels/${channelId}/join`)
      if (response.data.success) {
        await fetchConversations()
        return { success: true }
      }
      return { success: false, error: response.data.error }
    } catch (e) {
      return { success: false, error: e.response?.data?.error || e.message }
    }
  }

  async function leaveChannel(channelId) {
    try {
      const response = await api.post(`/chat/channels/${channelId}/leave`)
      if (response.data.success) {
        if (activeConversationId.value === channelId) {
          activeConversationId.value = null
        }
        await fetchConversations()
        return { success: true }
      }
      return { success: false, error: response.data.error }
    } catch (e) {
      return { success: false, error: e.response?.data?.error || e.message }
    }
  }

  async function setChannelTopic(channelId, topic) {
    try {
      const response = await api.patch(`/chat/channels/${channelId}/topic`, { topic })
      if (response.data.success) {
        const conv = conversationById.value[channelId]
        if (conv) conv.topic = response.data.data?.topic || topic
        return { success: true }
      }
      return { success: false, error: response.data.error }
    } catch (e) {
      return { success: false, error: e.response?.data?.error || e.message }
    }
  }

  // ========================================
  // CHANNEL CATEGORIES
  // ========================================

  const categories = ref([])
  const showMembersPanel = ref(false)

  function toggleMembersPanel() {
    showMembersPanel.value = !showMembersPanel.value
  }

  // Secondary chat panels (Status / Threads / Saved / Scheduled). Held here so
  // the desktop icon rail and the mobile actions row can both drive them while
  // the panels themselves render once in ChatView.
  const activePanel = ref(null) // null | 'status' | 'threads' | 'saved' | 'scheduled'

  function openPanel(name) {
    activePanel.value = name
  }

  function closePanel() {
    activePanel.value = null
  }

  const channelsByCategory = computed(() => {
    const channelConvs = conversations.value.filter(c => c.type === 'channel')
    const map = {}
    for (const cat of categories.value) {
      map[cat.id] = channelConvs
        .filter(c => c.category_id === cat.id)
        .sort((a, b) => (a.position || 0) - (b.position || 0))
    }
    return map
  })

  const uncategorizedChannels = computed(() => {
    return conversations.value
      .filter(c => c.type === 'channel' && !c.category_id)
      .sort((a, b) => (a.position || 0) - (b.position || 0))
  })

  async function fetchCategories() {
    try {
      const response = await api.get('/chat/categories')
      if (response.data.success) {
        categories.value = response.data.data?.categories || []
      }
    } catch (e) {
      console.error('Failed to fetch categories:', e)
    }
  }

  async function createCategory(name) {
    try {
      const response = await api.post('/chat/categories', { name })
      if (response.data.success) {
        const cat = response.data.data?.category
        if (cat) categories.value.push(cat)
        return { success: true, category: cat }
      }
      return { success: false, error: response.data.error }
    } catch (e) {
      return { success: false, error: e.response?.data?.error || e.message }
    }
  }

  async function updateCategory(categoryId, data) {
    try {
      const response = await api.patch(`/chat/categories/${categoryId}`, data)
      if (response.data.success) {
        const updated = response.data.data?.category
        const idx = categories.value.findIndex(c => c.id === categoryId)
        if (idx !== -1 && updated) categories.value[idx] = { ...categories.value[idx], ...updated }
        return { success: true, category: updated }
      }
      return { success: false, error: response.data.error }
    } catch (e) {
      return { success: false, error: e.response?.data?.error || e.message }
    }
  }

  async function deleteCategory(categoryId) {
    try {
      const response = await api.delete(`/chat/categories/${categoryId}`)
      if (response.data.success) {
        categories.value = categories.value.filter(c => c.id !== categoryId)
        conversations.value.forEach(c => {
          if (c.category_id === categoryId) c.category_id = null
        })
        return { success: true }
      }
      return { success: false, error: response.data.error }
    } catch (e) {
      return { success: false, error: e.response?.data?.error || e.message }
    }
  }

  async function reorderCategories(categoriesData) {
    try {
      const response = await api.post('/chat/categories/reorder', { categories: categoriesData })
      if (response.data.success) {
        await fetchCategories()
        return { success: true }
      }
      return { success: false, error: response.data.error }
    } catch (e) {
      return { success: false, error: e.response?.data?.error || e.message }
    }
  }

  async function assignChannelCategory(channelId, categoryId) {
    try {
      const response = await api.post(`/chat/channels/${channelId}/category`, { category_id: categoryId })
      if (response.data.success) {
        const conv = conversationById.value[channelId]
        if (conv) conv.category_id = categoryId || null
        return { success: true }
      }
      return { success: false, error: response.data.error }
    } catch (e) {
      return { success: false, error: e.response?.data?.error || e.message }
    }
  }

  // ========================================
  // THREADS
  // ========================================

  const activeThreadId = ref(null)
  const threadMessages = ref([])
  const loadingThread = ref(false)

  async function openThread(messageId) {
    activeThreadId.value = messageId
    loadingThread.value = true
    try {
      const response = await api.get(`/chat/messages/${messageId}/thread`)
      if (response.data.success) {
        threadMessages.value = response.data.data?.messages || []
      }
    } catch (e) {
      console.error('Failed to fetch thread:', e)
    } finally {
      loadingThread.value = false
    }
  }

  function closeThread() {
    activeThreadId.value = null
    threadMessages.value = []
  }

  const allThreads = ref([])
  const loadingAllThreads = ref(false)

  async function fetchActiveThreads() {
    loadingAllThreads.value = true
    try {
      const response = await api.get('/chat/threads')
      if (response.data.success) {
        allThreads.value = response.data.data?.threads || []
      }
    } catch (e) {
      console.error('Failed to fetch threads:', e)
    } finally {
      loadingAllThreads.value = false
    }
  }

  async function sendThreadReply(parentMessageId, content, conversationId, alsoSendToConversation = false) {
    try {
      const payload = {
        content,
        reply_to_id: parentMessageId
      }
      if (alsoSendToConversation) {
        payload.also_send_to_channel = true
      }
      const response = await api.post(`/chat/conversations/${conversationId}/messages`, payload)
      if (response.data.success) {
        // Refresh thread
        await openThread(parentMessageId)
        // If also sent to channel, refresh messages to show in main view
        if (alsoSendToConversation) {
          await loadMessages(conversationId)
        }
        return { success: true }
      }
      return { success: false, error: response.data.error }
    } catch (e) {
      return { success: false, error: e.response?.data?.error || e.message }
    }
  }

  // ========================================
  // BOOKMARKS (SAVED MESSAGES)
  // ========================================

  const bookmarks = ref([])
  const loadingBookmarks = ref(false)

  async function toggleBookmark(messageId) {
    try {
      const response = await api.post(`/chat/messages/${messageId}/bookmark`)
      if (response.data.success) {
        return { success: true, bookmarked: response.data.data?.bookmarked }
      }
      return { success: false, error: response.data.error }
    } catch (e) {
      return { success: false, error: e.response?.data?.error || e.message }
    }
  }

  async function fetchBookmarks() {
    loadingBookmarks.value = true
    try {
      const response = await api.get('/chat/bookmarks')
      if (response.data.success) {
        bookmarks.value = response.data.data?.bookmarks || []
      }
    } catch (e) {
      console.error('Failed to fetch bookmarks:', e)
    } finally {
      loadingBookmarks.value = false
    }
  }

  // ========================================
  // MENTIONS
  // ========================================

  const mentions = ref([])
  const unreadMentions = ref(0)

  async function fetchMentions() {
    try {
      const response = await api.get('/chat/mentions')
      if (response.data.success) {
        mentions.value = response.data.data?.mentions || []
      }
    } catch (e) {
      console.error('Failed to fetch mentions:', e)
    }
  }

  async function fetchUnreadMentionCount() {
    try {
      const response = await api.get('/chat/mentions/unread')
      if (response.data.success) {
        unreadMentions.value = response.data.data?.count || 0
      }
    } catch (e) {
      // silent
    }
  }

  // ========================================
  // SCHEDULED MESSAGES
  // ========================================

  const scheduledMessages = ref([])

  async function scheduleMessage(conversationId, content, scheduledAt) {
    try {
      const response = await api.post(`/chat/conversations/${conversationId}/schedule`, {
        content,
        scheduled_at: scheduledAt
      })
      if (response.data.success) {
        return { success: true }
      }
      return { success: false, error: response.data.error }
    } catch (e) {
      return { success: false, error: e.response?.data?.error || e.message }
    }
  }

  async function fetchScheduledMessages() {
    try {
      const response = await api.get('/chat/scheduled')
      if (response.data.success) {
        scheduledMessages.value = response.data.data?.messages || []
      }
    } catch (e) {
      console.error('Failed to fetch scheduled messages:', e)
    }
  }

  async function cancelScheduledMessage(id) {
    try {
      const response = await api.delete(`/chat/scheduled/${id}`)
      if (response.data.success) {
        scheduledMessages.value = scheduledMessages.value.filter(m => m.id !== id)
        return { success: true }
      }
      return { success: false, error: response.data.error }
    } catch (e) {
      return { success: false, error: e.response?.data?.error || e.message }
    }
  }

  // ========================================
  // LINK PREVIEWS
  // ========================================

  const linkPreviews = ref({}) // Map of URL -> preview data

  async function fetchLinkPreview(url) {
    if (linkPreviews.value[url]) return linkPreviews.value[url]
    try {
      const response = await api.get('/chat/link-preview', { params: { url } })
      if (response.data.success && response.data.data?.preview) {
        linkPreviews.value[url] = response.data.data.preview
        return response.data.data.preview
      }
    } catch (e) {
      // Silent fail
    }
    return null
  }

  return {
    // State
    conversations,
    activeConversationId,
    messages,
    unreadCounts,
    totalUnread,
    typingStatus,
    loading,
    loadingMessages,
    error,
    hasMoreMessages,
    replyingTo,
    pendingScrollToMessage,
    
    // Computed
    activeConversation,
    activeMessages,
    activeTyping,
    sortedConversations,
    conversationById,
    
    // Actions
    init,
    ensureSubscribed,
    cleanup,
    fetchConversations,
    fetchUnreadCounts,
    hydrateFromInit,
    markInitPending,
    initChat,
    pendingOpenDM,
    openDMWith,
    openDMAndExpand,
    setActiveConversation,
    loadMessages,
    loadOlderMessages,
    sendMessage,
    editMessage,
    deleteMessage,
    addReaction,
    removeReaction,
    toggleReaction,
    markAsRead,
    sendTypingStatus,
    togglePin,
    toggleMute,
    archiveConversation,
    deleteConversation,
    deleteThread,
    searchMessages,
    setReplyingTo,
    clearReplyingTo,
    getConversationAttachments,
    
    // Pinned messages
    pinnedMessages,
    loadingPinned,
    togglePinMessage,
    
    // Read receipts (colleague -> last read message)
    readReceipts,
    fetchPinnedMessages,
    saveAttachmentsToDrive,
    saveMessageAttachmentsToDrive,
    
    // Settings
    conversationSettings,
    getConversationSettings,
    updateConversationSettings,
    
    // View Together
    viewSession,
    otherParticipantPosition,
    otherParticipantCursor,
    followMode,
    syncScrollMode,
    isPresenter,
    startViewSession,
    endViewSession,
    syncViewPosition,
    syncCursorPosition,
    toggleFollowMode,
    setFollowMode,
    toggleSyncScrollMode,
    
    // Helpers
    getColleagueAvatar,
    getColleagueInitials,
    getColleagueColor,
    
    // Invitations
    pendingInvitations,
    loadingInvitations,
    inviteToChat,
    fetchPendingInvitations,
    acceptInvitation,
    declineInvitation,
    processInviteToken,

    // Channels
    browseChannels,
    createChannel,
    joinChannel,
    leaveChannel,
    setChannelTopic,

    // Channel Categories
    categories,
    channelsByCategory,
    uncategorizedChannels,
    fetchCategories,
    createCategory,
    updateCategory,
    deleteCategory,
    reorderCategories,
    assignChannelCategory,
    showMembersPanel,
    toggleMembersPanel,
    activePanel,
    openPanel,
    closePanel,

    // Threads
    activeThreadId,
    threadMessages,
    loadingThread,
    openThread,
    closeThread,
    sendThreadReply,
    allThreads,
    loadingAllThreads,
    fetchActiveThreads,

    // Bookmarks
    bookmarks,
    loadingBookmarks,
    toggleBookmark,
    fetchBookmarks,

    // Mentions
    mentions,
    unreadMentions,
    fetchMentions,
    fetchUnreadMentionCount,

    // Scheduled Messages
    scheduledMessages,
    scheduleMessage,
    fetchScheduledMessages,
    cancelScheduledMessage,

    // Link Previews
    linkPreviews,
    fetchLinkPreview,
  }
})

