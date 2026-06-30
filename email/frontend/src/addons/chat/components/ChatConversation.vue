<script setup>
import { ref, computed, watch, nextTick, onMounted, onUnmounted } from 'vue'
import { useChatStore } from '@/addons/chat/stores/chat'
import { useAuthStore } from '@/stores/auth'
import { useToastStore } from '@/stores/toast'
import { useCallStore } from '@/stores/call'
import { useCallLauncher } from '@/composables/useCallLauncher'
import { useChatPresence } from '@/composables/useChatPresence'
import { isDebugEnabled } from '@/utils/debug'
import ChatMessage from './ChatMessage.vue'
import ChatInput from './ChatInput.vue'
import ChatSettingsModal from './ChatSettingsModal.vue'
import ChatAttachmentsPanel from './ChatAttachmentsPanel.vue'
import ViewTogetherBanner from './ViewTogetherBanner.vue'
import GroupChatModal from './GroupChatModal.vue'
import HuddleBar from './HuddleBar.vue'
import ConversationMindMap from './ConversationMindMap.vue'
import UserAvatar from '@/components/shared/UserAvatar.vue'
import MeetingActions from '@/addons/calendar/components/MeetingActions.vue'
import api from '@/services/api'

const props = defineProps({
  showBackButton: {
    type: Boolean,
    default: false
  },
  useSafeArea: {
    type: Boolean,
    default: false
  },
  hasFooterNav: {
    type: Boolean,
    default: false
  }
})

const emit = defineEmits(['back'])

const chatStore = useChatStore()
const auth = useAuthStore()
const toast = useToastStore()
const callStore = useCallStore()
const callLauncher = useCallLauncher()
const { getStatusColor, getStatusText, getCustomStatusText } = useChatPresence()

const messagesContainer = ref(null)
const huddleBarRef = ref(null)
const isAtBottom = ref(true)
const showScrollToBottom = ref(false)
const isMobile = ref(window.innerWidth < 768)

// Background settings (synced from server)
const backgroundImage = ref('')
const backgroundOpacity = ref(0.1)
const backgroundSize = ref('') // Empty = cover/fill, '20px 20px' = tiled pattern

async function loadBackgroundSettings() {
  if (!chatStore.activeConversationId) {
    backgroundImage.value = ''
    backgroundOpacity.value = 0.1
    backgroundSize.value = ''
    return
  }
  
  const result = await chatStore.getConversationSettings(chatStore.activeConversationId)
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
  // Update local state immediately for responsiveness
  backgroundImage.value = settings.backgroundImage || ''
  backgroundOpacity.value = settings.backgroundOpacity ?? 0.1
  backgroundSize.value = settings.backgroundSize || ''
  
  // Save to server (will broadcast to all participants)
  if (chatStore.activeConversationId) {
    await chatStore.updateConversationSettings(chatStore.activeConversationId, {
      backgroundImage: settings.backgroundImage,
      backgroundOpacity: settings.backgroundOpacity,
      backgroundSize: settings.backgroundSize
    })
  }
}

// Computed getter for active conversation settings (helps Vue reactivity)
const activeSettings = computed(() => {
  if (!chatStore.activeConversationId) return null
  return chatStore.conversationSettings[chatStore.activeConversationId] || null
})

// Watch for settings updates from other participants (real-time sync)
watch(
  activeSettings,
  (newSettings) => {
    if (newSettings) {
      isDebugEnabled() && console.log('[ChatConversation] Settings updated from sync:', newSettings)
      backgroundImage.value = newSettings.backgroundImage || ''
      backgroundOpacity.value = newSettings.backgroundOpacity ?? 0.1
      backgroundSize.value = newSettings.backgroundSize || ''
    }
  },
  { deep: true, immediate: true }
)

// Get the other participant
const participant = computed(() => {
  return chatStore.activeConversation?.participants?.[0] || null
})

// Is this a group conversation?
const isGroupChat = computed(() => {
  return chatStore.activeConversation?.type === 'group' || chatStore.activeConversation?.type === 'channel'
})

// Is this a channel?
const isChannel = computed(() => {
  return chatStore.activeConversation?.type === 'channel'
})

// Channel topic
const channelTopic = computed(() => {
  return chatStore.activeConversation?.topic || ''
})

// Is this a meeting conversation? (name starts with "Meeting:")
const isMeetingConversation = computed(() => {
  const name = chatStore.activeConversation?.name || ''
  return isGroupChat.value && name.startsWith('Meeting:')
})

// Meeting details (time + host/participant links) resolved from the linked
// calendar event. Loaded lazily when a meeting conversation is opened.
const meetingInfo = ref(null)
const meetingInfoLoading = ref(false)

async function loadMeetingInfo() {
  meetingInfo.value = null
  const convId = chatStore.activeConversationId
  if (!convId || !isMeetingConversation.value) return
  meetingInfoLoading.value = true
  try {
    const res = await api.get(`/chat/conversations/${convId}/meeting`)
    // Guard against a stale response after the user switched conversations.
    if (chatStore.activeConversationId !== convId) return
    if (res.data?.success && res.data.data?.is_meeting) {
      meetingInfo.value = res.data.data
    }
  } catch (e) {
    // Non-fatal: header just won't show the link buttons.
  } finally {
    meetingInfoLoading.value = false
  }
}

watch(
  () => [chatStore.activeConversationId, isMeetingConversation.value],
  () => { loadMeetingInfo() },
  { immediate: true }
)

// Ongoing call banner: show when there's an active call in this conversation
// but the user is NOT already in that call
const showOngoingCallBanner = computed(() => {
  const active = callStore.activeCallInConversation
  if (!active || active.status === 'idle') return false
  // Don't show banner if user is already in this call
  if (callStore.isInCall && callStore.conversationId === chatStore.activeConversationId) return false
  return active.conversationId === chatStore.activeConversationId
})

const ongoingCallParticipantNames = computed(() => {
  const active = callStore.activeCallInConversation
  if (!active || !active.participants) return ''
  return active.participants
    .map(email => {
      const name = email.split('@')[0]
      return name.charAt(0).toUpperCase() + name.slice(1)
    })
    .join(', ')
})

function joinOngoingCall() {
  const active = callStore.activeCallInConversation
  if (!active) return
  callLauncher.joinExistingCall(chatStore.activeConversationId, active)
}

// Display name for the header (channel, group or participant name)
const headerName = computed(() => {
  if (isChannel.value) {
    const slug = chatStore.activeConversation?.slug
    return slug ? `#${slug}` : (chatStore.activeConversation?.name || 'Channel')
  }
  if (isGroupChat.value) {
    return chatStore.activeConversation?.name || 'Group Chat'
  }
  return participant.value?.display_name || participant.value?.email?.split('@')[0] || 'Unknown'
})

// Group messages by date
const groupedMessages = computed(() => {
  const messages = chatStore.activeMessages
  if (!messages.length) return []
  
  const groups = []
  let currentGroup = null
  let currentDate = null
  
  for (const msg of messages) {
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
  return date.toLocaleDateString([], { month: 'long', day: 'numeric', year: 'numeric' })
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

// getStatusColor and getStatusText are provided by useChatPresence composable

// Format preview content (hide GIF/emoji raw formats)
function formatPreviewContent(text) {
  if (!text) return ''
  if (/^\[gif:(.+?):(\d+):(\d+)\]$/.test(text)) {
    return 'GIF'
  }
  const embedMatch = text.match(/^\[embed:(\w+):\d+\]$/)
  if (embedMatch) {
    const labels = { drive_file: 'Shared a file', drive_folder: 'Shared a folder', calendar_event: 'Shared an event', board: 'Shared a board', board_card: 'Shared a card', collab_doc: 'Shared a document', mood_board: 'Shared a mood board' }
    return labels[embedMatch[1]] || 'Shared content'
  }
  return text.replace(/:([a-z_]+):/g, '[$1]')
}

// Scroll management
function scrollToBottom(smooth = true) {
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

function handleScroll() {
  if (!messagesContainer.value) return
  
  const { scrollTop, scrollHeight, clientHeight } = messagesContainer.value
  const distanceFromBottom = scrollHeight - scrollTop - clientHeight
  
  isAtBottom.value = distanceFromBottom < 100
  showScrollToBottom.value = distanceFromBottom > 300
}

async function handleLoadMore() {
  if (!messagesContainer.value || chatStore.loadingMessages) return
  
  // Save scroll position so we can restore it after prepending messages
  const container = messagesContainer.value
  const prevScrollHeight = container.scrollHeight
  
  await chatStore.loadOlderMessages()
  
  // Restore scroll position: new content was added at the top,
  // so shift scrollTop by the difference in container height
  await nextTick()
  if (container) {
    const newScrollHeight = container.scrollHeight
    container.scrollTop += newScrollHeight - prevScrollHeight
  }
}

// Watch for new messages - scroll to bottom if we're already at bottom
watch(
  () => chatStore.activeMessages.length,
  (newLen, oldLen) => {
    if (newLen > oldLen && isAtBottom.value) {
      scrollToBottom()
    }
  }
)

// Watch for conversation change - scroll to bottom
watch(
  () => chatStore.activeConversationId,
  () => {
    nextTick(() => {
      scrollToBottom(false)
    })
  }
)

// Conversation actions
const showMenu = ref(false)
const menuButtonRef = ref(null)
const menuPosition = ref({ top: '0px', right: '0px' })

function toggleMenu() {
  if (!showMenu.value && menuButtonRef.value) {
    const rect = menuButtonRef.value.getBoundingClientRect()
    menuPosition.value = {
      top: rect.bottom + 4 + 'px',
      right: (window.innerWidth - rect.right) + 'px'
    }
  }
  showMenu.value = !showMenu.value
}

// Call actions
function getCallParticipantEmails() {
  const conv = chatStore.activeConversation
  if (!conv) return []
  const myEmail = auth.userEmail?.toLowerCase()
  if (conv.type === 'group') {
    // All participants except self
    return (conv.participants || [])
      .filter(p => p?.email && p.email.toLowerCase() !== myEmail)
      .map(p => ({
        email: p.email,
        name: p.display_name || p.name || null,
        avatar: p.avatar || p.avatar_url || null
      }))
  }
  // 1:1 DM - get the other participant
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
  callLauncher.startCall(chatStore.activeConversationId, 'voice', emails)
}

function startVideoCall() {
  const emails = getCallParticipantEmails()
  if (!emails.length) {
    toast.error('No participants to call')
    return
  }
  callLauncher.startCall(chatStore.activeConversationId, 'video', emails)
}

async function handlePin() {
  showMenu.value = false
  await chatStore.togglePin(chatStore.activeConversationId)
}

async function handleMute() {
  showMenu.value = false
  await chatStore.toggleMute(chatStore.activeConversationId)
}

async function handleArchive() {
  showMenu.value = false
  await chatStore.archiveConversation(chatStore.activeConversationId)
}

// Pinned messages panel
const showPinnedPanel = ref(false)

async function handleViewPinned() {
  showMenu.value = false
  showPinnedPanel.value = true
  await chatStore.fetchPinnedMessages(chatStore.activeConversationId)
}

function closePinnedPanel() {
  showPinnedPanel.value = false
}

function formatPinnedTime(dateString) {
  if (!dateString) return ''
  const date = new Date(dateString)
  const now = new Date()
  const diffDays = Math.floor((now - date) / (1000 * 60 * 60 * 24))
  if (diffDays === 0) return date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })
  if (diffDays === 1) return 'Yesterday'
  if (diffDays < 7) return date.toLocaleDateString([], { weekday: 'short' })
  return date.toLocaleDateString([], { month: 'short', day: 'numeric' })
}

function scrollToMessage(messageId) {
  closePinnedPanel()
  nextTick(() => {
    const el = messagesContainer.value?.querySelector(`[data-message-id="${messageId}"]`)
    if (el) {
      el.scrollIntoView({ behavior: 'smooth', block: 'center' })
      // Flash highlight effect
      el.classList.add('pinned-highlight')
      setTimeout(() => el.classList.remove('pinned-highlight'), 2000)
    }
  })
}

async function handleUnpinFromPanel(messageId) {
  await chatStore.togglePinMessage(messageId)
}

// Attachments
const showAttachmentsPanel = ref(false)
const savingToDrive = ref(false)

// Settings modal
const showSettingsModal = ref(false)

// Group modal for adding people
const showGroupModal = ref(false)
const showMindMap = ref(false)

function handleViewAttachments() {
  showMenu.value = false
  showAttachmentsPanel.value = true
}

function handleOpenSettings() {
  showMenu.value = false
  showSettingsModal.value = true
}

function handleAddPeople() {
  showMenu.value = false
  showGroupModal.value = true
}

function handleGroupUpdated() {
  showGroupModal.value = false
  // Refresh conversation to get updated participants
  chatStore.fetchConversations()
}

async function handleToggleViewTogether() {
  showMenu.value = false
  
  if (chatStore.viewSession) {
    // End existing session
    await chatStore.endViewSession()
    toast.info('View Together session ended')
  } else {
    // Start new session - will be activated when user opens media
    await chatStore.startViewSession('pending', 'waiting_for_content')
    toast.success('View Together mode enabled. Open any media to share the view.')
  }
}

async function handleSaveAllToDrive() {
  showMenu.value = false
  savingToDrive.value = true
  
  const result = await chatStore.saveAttachmentsToDrive(chatStore.activeConversationId)
  
  if (result.success) {
    toast.success(`Saved ${result.savedCount} files to Drive in "${result.folderPath}"`)
  } else {
    toast.error(result.error || 'Failed to save to Drive')
  }
  
  savingToDrive.value = false
}

// Load background on mount and conversation change
watch(
  () => chatStore.activeConversationId,
  (newId) => {
    loadBackgroundSettings()
    // Query for any ongoing call in this conversation
    if (newId) {
      callStore.queryActiveCall(newId)
    }
  }
)

onMounted(() => {
  scrollToBottom(false)
  loadBackgroundSettings()
  // Query active call on initial mount too
  if (chatStore.activeConversationId) {
    callStore.queryActiveCall(chatStore.activeConversationId)
  }
  
  // Handle deep-link scroll from search results
  if (chatStore.pendingScrollToMessage) {
    const targetId = chatStore.pendingScrollToMessage
    chatStore.pendingScrollToMessage = null
    // Wait for messages to render, then scroll
    nextTick(() => {
      setTimeout(() => scrollToMessage(targetId), 300)
    })
  }
})

// Watch for pending scroll requests (e.g. from search deep links)
watch(() => chatStore.pendingScrollToMessage, (messageId) => {
  if (messageId) {
    chatStore.pendingScrollToMessage = null
    nextTick(() => {
      setTimeout(() => scrollToMessage(messageId), 300)
    })
  }
})

onUnmounted(() => {
  // cleanup if needed
})
</script>

<template>
  <div class="chat-conversation-root flex-1 flex flex-col overflow-hidden bg-surface-50 dark:bg-surface-900 relative">
    <!-- Header -->
    <header 
      :class="[
        'flex items-center justify-between px-4 border-b border-surface-200 dark:border-[rgb(var(--color-border))] bg-white dark:bg-[rgb(var(--color-surface))] flex-shrink-0 relative z-10',
        props.useSafeArea ? 'header-safe-area' : 'h-16'
      ]"
    >
      <div class="flex items-center gap-3 min-w-0 flex-1">
        <!-- Mobile back button -->
        <button
          v-if="props.showBackButton"
          @click="emit('back')"
          class="p-2 -ml-2 hover:bg-surface-100 dark:hover:bg-surface-700 rounded-lg transition-colors flex-shrink-0"
        >
          <span class="material-symbols-rounded text-surface-600 dark:text-surface-400">arrow_back</span>
        </button>
        
        <!-- Avatar -->
        <div class="relative flex-shrink-0">
          <div 
            v-if="isChannel"
            :class="[
              'w-10 h-10 rounded-full flex items-center justify-center',
              chatStore.activeConversation?.is_public !== false ? 'bg-primary-100 dark:bg-primary-500/20' : 'bg-amber-100 dark:bg-amber-500/20'
            ]"
          >
            <span class="material-symbols-rounded text-xl" :class="chatStore.activeConversation?.is_public !== false ? 'text-primary-500' : 'text-amber-500'">{{ chatStore.activeConversation?.is_public !== false ? 'tag' : 'lock' }}</span>
          </div>
          <div 
            v-else-if="isGroupChat"
            class="w-10 h-10 rounded-full bg-primary-500 flex items-center justify-center text-white"
          >
            <span class="material-symbols-rounded text-xl">groups</span>
          </div>
          <UserAvatar
            v-else
            :colleague="participant"
            size="lg"
            show-presence
          />
        </div>
        
        <!-- Info -->
        <div class="min-w-0">
          <h2 class="font-semibold text-surface-900 dark:text-surface-100 flex items-center gap-2 leading-tight">
            <span class="truncate">{{ headerName }}</span>
            <!-- Redundant on mobile (the meeting details bar below already marks
                 this as a meeting); hidden there to give the title room. -->
            <span 
              v-if="isMeetingConversation"
              class="hidden sm:inline-flex items-center gap-0.5 px-2 py-0.5 rounded-full text-[11px] bg-primary-100 dark:bg-primary-500/20 text-primary-700 dark:text-primary-400 font-medium whitespace-nowrap flex-shrink-0"
            >
              <span class="material-symbols-rounded text-xs leading-none">videocam</span>
              Meeting
            </span>
          </h2>
          <p class="text-sm text-surface-500 flex items-center gap-1 truncate">
            <span v-if="chatStore.activeTyping" class="text-primary-500">
              Typing...
            </span>
            <span
              v-else-if="isGroupChat"
              @click.stop="chatStore.toggleMembersPanel()"
              class="cursor-pointer hover:text-primary-500 transition-colors"
              title="View members"
            >
              <span class="material-symbols-rounded text-xs align-middle mr-0.5">group</span>
              {{ chatStore.activeConversation?.participants?.length || 0 }} members
            </span>
            <template v-else>
              <span>{{ getStatusText(participant) }}</span>
              <span 
                v-if="getCustomStatusText(participant)" 
                class="text-surface-400 truncate"
              >
                &middot; {{ getCustomStatusText(participant) }}
              </span>
            </template>
          </p>
        </div>
      </div>
      
      <!-- Actions -->
      <div class="flex items-center gap-1 flex-shrink-0">
        <!-- Start Meeting button (for meeting conversations) -->
        <button
          v-if="isMeetingConversation"
          @click="startVideoCall"
          :disabled="callStore.isInCall"
          :class="[
            'flex items-center gap-1.5 px-3 h-10 rounded-full text-sm font-medium transition-colors',
            callStore.isInCall
              ? 'bg-surface-100 dark:bg-surface-700 text-surface-400 cursor-not-allowed'
              : 'bg-primary-500 hover:bg-primary-600 text-white shadow-sm'
          ]"
          title="Start meeting"
        >
          <span class="material-symbols-rounded text-xl leading-none">videocam</span>
          <span class="hidden sm:inline">Start Meeting</span>
        </button>
        
        <!-- Start Huddle button (for channels and groups) -->
        <button
          v-if="isGroupChat && !isMeetingConversation"
          @click="huddleBarRef?.startHuddle()"
          class="w-10 h-10 flex items-center justify-center rounded-full hover:bg-surface-100 dark:hover:bg-surface-700 text-surface-500 transition-colors"
          title="Start Huddle"
        >
          <span class="material-symbols-rounded text-2xl leading-none">headset_mic</span>
        </button>
        
        <!-- Voice call button (hidden for meeting conversations - they use video) -->
        <button
          v-if="!isMeetingConversation"
          @click="startVoiceCall"
          :disabled="callStore.isInCall"
          :class="[
            'w-10 h-10 flex items-center justify-center rounded-full transition-colors',
            callStore.isInCall
              ? 'text-surface-300 dark:text-surface-600 cursor-not-allowed'
              : 'hover:bg-surface-100 dark:hover:bg-surface-700 text-surface-500'
          ]"
          title="Voice call"
        >
          <span class="material-symbols-rounded text-2xl leading-none">call</span>
        </button>
        
        <!-- Video call button (hidden for meeting conversations - replaced by Start Meeting) -->
        <button
          v-if="!isMeetingConversation"
          @click="startVideoCall"
          :disabled="callStore.isInCall"
          :class="[
            'w-10 h-10 flex items-center justify-center rounded-full transition-colors',
            callStore.isInCall
              ? 'text-surface-300 dark:text-surface-600 cursor-not-allowed'
              : 'hover:bg-surface-100 dark:hover:bg-surface-700 text-surface-500'
          ]"
          title="Video call"
        >
          <span class="material-symbols-rounded text-2xl leading-none">videocam</span>
        </button>
        
        <!-- Mind Map button -->
        <button
          @click="showMindMap = true"
          class="w-10 h-10 flex items-center justify-center rounded-full hover:bg-surface-100 dark:hover:bg-surface-700 text-surface-500 transition-colors"
          title="Conversation Map"
        >
          <span class="material-symbols-rounded text-2xl leading-none">account_tree</span>
        </button>
        
        <div class="relative flex items-center">
          <button
            ref="menuButtonRef"
            @click="toggleMenu"
            class="w-10 h-10 flex items-center justify-center hover:bg-surface-100 dark:hover:bg-surface-700 rounded-full transition-colors"
          >
            <span class="material-symbols-rounded text-2xl leading-none text-surface-500">more_vert</span>
          </button>
          
          <!-- Desktop: dropdown menu -->
          <Teleport to="body">
            <template v-if="!isMobile">
              <div v-if="showMenu" class="fixed inset-0 z-[99998]" @click="showMenu = false"></div>
              <div 
                v-if="showMenu"
                class="fixed w-56 bg-white dark:bg-surface-800 rounded-xl shadow-xl border border-surface-200 dark:border-surface-700 py-1 z-[99999]"
                :style="menuPosition"
              >
                <button class="w-full flex items-center gap-3 px-4 py-2.5 hover:bg-surface-100 dark:hover:bg-surface-700 transition-colors text-left">
                  <span class="material-symbols-rounded text-xl text-surface-500">search</span>
                  <span class="text-sm">Search</span>
                </button>
                <button @click="handleViewPinned" class="w-full flex items-center gap-3 px-4 py-2.5 hover:bg-surface-100 dark:hover:bg-surface-700 transition-colors text-left">
                  <span class="material-symbols-rounded text-xl text-primary-500">push_pin</span>
                  <span class="text-sm">Pinned messages</span>
                </button>
                <button @click="handleViewAttachments" class="w-full flex items-center gap-3 px-4 py-2.5 hover:bg-surface-100 dark:hover:bg-surface-700 transition-colors text-left">
                  <span class="material-symbols-rounded text-xl">photo_library</span>
                  <span class="text-sm">Media & Files</span>
                </button>
                <div class="border-t border-surface-200 dark:border-surface-700 my-1"></div>
                <button @click="handlePin" class="w-full flex items-center gap-3 px-4 py-2.5 hover:bg-surface-100 dark:hover:bg-surface-700 transition-colors text-left">
                  <span class="material-symbols-rounded text-xl">push_pin</span>
                  <span class="text-sm">{{ chatStore.activeConversation?.is_pinned ? 'Unpin' : 'Pin' }} conversation</span>
                </button>
                <button @click="handleMute" class="w-full flex items-center gap-3 px-4 py-2.5 hover:bg-surface-100 dark:hover:bg-surface-700 transition-colors text-left">
                  <span class="material-symbols-rounded text-xl">{{ chatStore.activeConversation?.is_muted ? 'notifications' : 'notifications_off' }}</span>
                  <span class="text-sm">{{ chatStore.activeConversation?.is_muted ? 'Unmute' : 'Mute' }}</span>
                </button>
                <button @click="handleSaveAllToDrive" :disabled="savingToDrive" class="w-full flex items-center gap-3 px-4 py-2.5 hover:bg-surface-100 dark:hover:bg-surface-700 transition-colors text-left">
                  <span :class="['material-symbols-rounded text-xl', savingToDrive && 'animate-spin']">{{ savingToDrive ? 'progress_activity' : 'cloud_upload' }}</span>
                  <span class="text-sm">Save to Drive</span>
                </button>
                <div class="border-t border-surface-200 dark:border-surface-700 my-1"></div>
                <button @click="handleAddPeople" class="w-full flex items-center gap-3 px-4 py-2.5 hover:bg-surface-100 dark:hover:bg-surface-700 transition-colors text-left">
                  <span class="material-symbols-rounded text-xl">settings</span>
                  <span class="text-sm">Settings</span>
                </button>
                <button @click="handleArchive" class="w-full flex items-center gap-3 px-4 py-2.5 hover:bg-surface-100 dark:hover:bg-surface-700 transition-colors text-left text-red-500">
                  <span class="material-symbols-rounded text-xl">archive</span>
                  <span class="text-sm">Archive</span>
                </button>
              </div>
            </template>

            <!-- Mobile: bottom sheet menu -->
            <Transition name="conv-sheet">
              <div
                v-if="isMobile && showMenu"
                class="fixed inset-0 z-[60] bg-black/40"
                @click.self="showMenu = false"
              >
                <div class="absolute bottom-0 left-0 right-0 bg-white dark:bg-surface-800 rounded-t-2xl max-h-[80vh] overflow-y-auto" style="-webkit-overflow-scrolling: touch;">
                  <div class="flex justify-center pt-3 pb-1 sticky top-0 bg-white dark:bg-surface-800 z-10">
                    <div class="w-10 h-1 bg-surface-300 dark:bg-surface-600 rounded-full"></div>
                  </div>
                  <div class="px-4 pb-6 space-y-2">
                    <button @click="showMenu = false" class="w-full px-3 py-3 bg-surface-100 dark:bg-surface-700 hover:bg-surface-200 dark:hover:bg-surface-600 rounded-xl text-sm text-surface-700 dark:text-surface-300 text-left flex items-center gap-3">
                      <span class="material-symbols-rounded text-lg text-surface-500">search</span>
                      Search
                    </button>
                    <button @click="handleViewPinned" class="w-full px-3 py-3 bg-surface-100 dark:bg-surface-700 hover:bg-surface-200 dark:hover:bg-surface-600 rounded-xl text-sm text-surface-700 dark:text-surface-300 text-left flex items-center gap-3">
                      <span class="material-symbols-rounded text-lg text-primary-500">push_pin</span>
                      Pinned messages
                    </button>
                    <button @click="handleViewAttachments" class="w-full px-3 py-3 bg-surface-100 dark:bg-surface-700 hover:bg-surface-200 dark:hover:bg-surface-600 rounded-xl text-sm text-surface-700 dark:text-surface-300 text-left flex items-center gap-3">
                      <span class="material-symbols-rounded text-lg">photo_library</span>
                      Media & Files
                    </button>

                    <hr class="border-surface-200 dark:border-surface-600 my-3">

                    <button @click="handlePin" class="w-full px-3 py-3 bg-surface-100 dark:bg-surface-700 hover:bg-surface-200 dark:hover:bg-surface-600 rounded-xl text-sm text-surface-700 dark:text-surface-300 text-left flex items-center gap-3">
                      <span class="material-symbols-rounded text-lg">push_pin</span>
                      {{ chatStore.activeConversation?.is_pinned ? 'Unpin' : 'Pin' }} conversation
                    </button>
                    <button @click="handleMute" class="w-full px-3 py-3 bg-surface-100 dark:bg-surface-700 hover:bg-surface-200 dark:hover:bg-surface-600 rounded-xl text-sm text-surface-700 dark:text-surface-300 text-left flex items-center gap-3">
                      <span class="material-symbols-rounded text-lg">{{ chatStore.activeConversation?.is_muted ? 'notifications' : 'notifications_off' }}</span>
                      {{ chatStore.activeConversation?.is_muted ? 'Unmute' : 'Mute' }}
                    </button>
                    <button @click="handleSaveAllToDrive" :disabled="savingToDrive" class="w-full px-3 py-3 bg-surface-100 dark:bg-surface-700 hover:bg-surface-200 dark:hover:bg-surface-600 rounded-xl text-sm text-surface-700 dark:text-surface-300 text-left flex items-center gap-3">
                      <span :class="['material-symbols-rounded text-lg', savingToDrive && 'animate-spin']">{{ savingToDrive ? 'progress_activity' : 'cloud_upload' }}</span>
                      Save to Drive
                    </button>

                    <hr class="border-surface-200 dark:border-surface-600 my-3">

                    <button @click="handleAddPeople" class="w-full px-3 py-3 bg-surface-100 dark:bg-surface-700 hover:bg-surface-200 dark:hover:bg-surface-600 rounded-xl text-sm text-surface-700 dark:text-surface-300 text-left flex items-center gap-3">
                      <span class="material-symbols-rounded text-lg">settings</span>
                      Settings
                    </button>
                    <button @click="handleArchive" class="w-full px-3 py-3 hover:bg-red-500/10 rounded-xl text-sm text-red-600 dark:text-red-400 text-left flex items-center gap-3">
                      <span class="material-symbols-rounded text-lg">archive</span>
                      Archive
                    </button>
                  </div>
                </div>
              </div>
            </Transition>
          </Teleport>
        </div>
      </div>
    </header>

    <!-- Meeting details bar (time + host/participant links + participants) -->
    <div
      v-if="isMeetingConversation && meetingInfo"
      class="flex items-center justify-between gap-3 px-4 py-2.5 border-b border-surface-200 dark:border-surface-700 bg-primary-50/60 dark:bg-primary-500/10 flex-shrink-0"
    >
      <MeetingActions
        :event-id="meetingInfo.event_id"
        :start-time="meetingInfo.start_time"
        :end-time="meetingInfo.end_time"
        :is-host="!!meetingInfo.is_host"
        layout="row"
      />
    </div>

    <!-- Ongoing Call Banner -->
    <Transition name="slide-down">
      <div 
        v-if="showOngoingCallBanner"
        class="flex items-center justify-between px-4 py-2.5 border-b border-green-200 dark:border-green-800 bg-green-50 dark:bg-green-900/30 flex-shrink-0"
      >
        <div class="flex items-center gap-3 min-w-0">
          <div class="w-8 h-8 rounded-full bg-green-500 flex items-center justify-center flex-shrink-0 animate-pulse">
            <span class="material-symbols-rounded text-white text-lg">call</span>
          </div>
          <div class="min-w-0">
            <p class="text-sm font-medium text-green-800 dark:text-green-200">
              {{ callStore.activeCallInConversation?.callType === 'video' ? 'Video' : 'Voice' }} call in progress
            </p>
            <p class="text-xs text-green-600 dark:text-green-400 truncate">
              {{ ongoingCallParticipantNames }}
            </p>
          </div>
        </div>
        <button
          @click="joinOngoingCall"
          :disabled="callStore.isInCall"
          :class="[
            'flex items-center gap-1.5 px-4 py-1.5 rounded-full text-sm font-medium transition-colors flex-shrink-0',
            callStore.isInCall
              ? 'bg-surface-200 dark:bg-surface-700 text-surface-400 cursor-not-allowed'
              : 'bg-green-500 hover:bg-green-600 text-white shadow-sm'
          ]"
        >
          <span class="material-symbols-rounded text-lg">call</span>
          Join
        </button>
      </div>
    </Transition>

    <!-- Channel Topic Bar -->
    <div 
      v-if="isChannel && channelTopic"
      class="px-4 py-2 border-b border-surface-200 dark:border-[rgb(var(--color-border))] bg-surface-50 dark:bg-surface-800/50 flex-shrink-0"
    >
      <p class="text-xs text-surface-500 dark:text-surface-400 truncate">
        <span class="material-symbols-rounded text-xs align-middle mr-1">topic</span>
        {{ channelTopic }}
      </p>
    </div>
    
    <!-- Pinned Messages Panel -->
    <Transition name="slide-down">
      <div 
        v-if="showPinnedPanel"
        class="border-b border-surface-200 dark:border-surface-700 bg-white dark:bg-surface-800 flex-shrink-0 relative z-20 max-h-[50vh] flex flex-col"
      >
        <!-- Panel header -->
        <div class="flex items-center justify-between px-4 py-2.5 border-b border-surface-100 dark:border-surface-700">
          <div class="flex items-center gap-2">
            <span class="material-symbols-rounded text-lg text-primary-500">push_pin</span>
            <h3 class="text-sm font-semibold text-surface-900 dark:text-surface-100">Pinned Messages</h3>
            <span 
              v-if="chatStore.pinnedMessages[chatStore.activeConversationId]?.length"
              class="text-xs text-surface-400"
            >
              ({{ chatStore.pinnedMessages[chatStore.activeConversationId].length }})
            </span>
          </div>
          <button
            @click="closePinnedPanel"
            class="p-1.5 hover:bg-surface-100 dark:hover:bg-surface-700 rounded-lg transition-colors"
          >
            <span class="material-symbols-rounded text-lg text-surface-400">close</span>
          </button>
        </div>
        
        <!-- Panel content -->
        <div class="overflow-y-auto flex-1">
          <!-- Loading -->
          <div v-if="chatStore.loadingPinned" class="flex justify-center py-6">
            <span class="material-symbols-rounded text-xl text-surface-400 animate-spin">progress_activity</span>
          </div>
          
          <!-- Empty -->
          <div 
            v-else-if="!chatStore.pinnedMessages[chatStore.activeConversationId]?.length"
            class="flex flex-col items-center justify-center py-8 px-4 text-center"
          >
            <span class="material-symbols-rounded text-3xl text-surface-300 dark:text-surface-600 mb-2">push_pin</span>
            <p class="text-sm text-surface-500">No pinned messages yet</p>
            <p class="text-xs text-surface-400 mt-1">Pin important messages to find them quickly</p>
          </div>
          
          <!-- Pinned messages list -->
          <div v-else class="divide-y divide-surface-100 dark:divide-surface-700">
            <div
              v-for="msg in chatStore.pinnedMessages[chatStore.activeConversationId]"
              :key="msg.id"
              class="px-4 py-3 hover:bg-surface-50 dark:hover:bg-surface-800/50 transition-colors cursor-pointer group"
              @click="scrollToMessage(msg.id)"
            >
              <div class="flex items-start gap-3">
                <!-- Sender avatar -->
                <UserAvatar
                  :colleague="{ display_name: msg.sender_name, email: msg.sender_email, avatar_path: msg.sender_avatar_path }"
                  size="md"
                />
                
                <!-- Content -->
                <div class="flex-1 min-w-0">
                  <div class="flex items-center justify-between gap-2">
                    <span class="text-sm font-medium text-surface-900 dark:text-surface-100">
                      {{ msg.sender_name || msg.sender_email }}
                    </span>
                    <div class="flex items-center gap-1.5">
                      <span class="text-xs text-surface-400">
                        {{ formatPinnedTime(msg.created_at) }}
                      </span>
                      <!-- Unpin button -->
                      <button
                        @click.stop="handleUnpinFromPanel(msg.id)"
                        class="opacity-0 group-hover:opacity-100 p-1 hover:bg-surface-200 dark:hover:bg-surface-600 rounded transition-all"
                        title="Unpin"
                      >
                        <span class="material-symbols-rounded text-sm text-surface-400">close</span>
                      </button>
                    </div>
                  </div>
                  <p class="text-sm text-surface-600 dark:text-surface-400 mt-0.5 line-clamp-2">
                    {{ msg.content }}
                  </p>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </Transition>
    
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
    
    <!-- Huddle Bar (active voice huddle) -->
    <HuddleBar
      ref="huddleBarRef"
      v-if="chatStore.activeConversationId"
      :conversation-id="chatStore.activeConversationId"
    />
    
    <!-- Messages -->
    <div 
      ref="messagesContainer"
      class="flex-1 overflow-y-auto px-4 py-4 relative z-10"
      @scroll="handleScroll"
    >
      <!-- Load older messages -->
      <div 
        v-if="chatStore.hasMoreMessages[chatStore.activeConversationId]"
        class="flex justify-center py-3"
      >
        <button
          v-if="!chatStore.loadingMessages"
          @click="handleLoadMore"
          class="inline-flex items-center gap-1.5 px-4 py-1.5 text-xs font-medium rounded-full
                 bg-surface-100 dark:bg-surface-700 text-surface-500 dark:text-surface-400
                 hover:bg-surface-200 dark:hover:bg-surface-600 transition-colors"
        >
          <span class="material-symbols-rounded text-sm">expand_less</span>
          Load older messages
        </button>
        <span
          v-else
          class="inline-flex items-center gap-1.5 px-4 py-1.5 text-xs text-surface-400"
        >
          <span class="material-symbols-rounded text-sm animate-spin">progress_activity</span>
          Loading...
        </span>
      </div>
      
      <!-- Message groups -->
      <template v-for="(group, groupIndex) in groupedMessages" :key="groupIndex">
        <!-- Date divider -->
        <div class="flex items-center gap-4 my-6">
          <div class="flex-1 h-px bg-surface-200 dark:bg-surface-700"></div>
          <span class="text-xs font-medium text-surface-400 px-2">{{ group.date }}</span>
          <div class="flex-1 h-px bg-surface-200 dark:bg-surface-700"></div>
        </div>
        
        <!-- Messages -->
        <ChatMessage
          v-for="(message, msgIndex) in group.messages"
          :key="message.id"
          :message="message"
          :participant="participant"
          :show-timestamp="shouldShowTimestamp(group.messages, msgIndex)"
          :is-group-chat="chatStore.activeConversation?.type === 'group'"
        />
      </template>
      
      <!-- Typing indicator -->
      <div 
        v-if="chatStore.activeTyping"
        class="flex items-end gap-2 mb-4"
      >
        <UserAvatar
          :colleague="participant"
          size="md"
        />
        <div class="bg-surface-200 dark:bg-surface-700 rounded-2xl rounded-bl-md px-4 py-3">
          <div class="flex gap-1">
            <span class="w-2 h-2 bg-surface-400 rounded-full animate-bounce" style="animation-delay: 0ms"></span>
            <span class="w-2 h-2 bg-surface-400 rounded-full animate-bounce" style="animation-delay: 150ms"></span>
            <span class="w-2 h-2 bg-surface-400 rounded-full animate-bounce" style="animation-delay: 300ms"></span>
          </div>
        </div>
      </div>
      
      <!-- Empty state -->
      <div 
        v-if="groupedMessages.length === 0 && !chatStore.loadingMessages"
        class="flex flex-col items-center justify-center py-12 text-center"
      >
        <div class="w-16 h-16 rounded-full bg-primary-100 dark:bg-primary-500/20 flex items-center justify-center mb-4">
          <span class="material-symbols-rounded text-3xl text-primary-500">waving_hand</span>
        </div>
        <p class="text-surface-500 max-w-sm">
          This is the beginning of your conversation with 
          <span class="font-medium text-surface-700 dark:text-surface-300">
            {{ participant?.display_name || participant?.email?.split('@')[0] }}
          </span>. 
          Say hi!
        </p>
      </div>
    </div>
    
    <!-- Scroll to bottom button -->
    <Transition name="fade">
      <button
        v-if="showScrollToBottom"
        @click="scrollToBottom()"
        class="absolute bottom-24 right-6 w-10 h-10 bg-white dark:bg-surface-800 rounded-full shadow-lg flex items-center justify-center hover:bg-surface-50 dark:hover:bg-surface-700 transition-colors border border-surface-200 dark:border-surface-700 z-20"
      >
        <span class="material-symbols-rounded text-surface-600 dark:text-surface-400">keyboard_arrow_down</span>
      </button>
    </Transition>
    
    <!-- Reply preview -->
    <div 
      v-if="chatStore.replyingTo"
      class="px-4 py-2 bg-surface-100 dark:bg-surface-800 border-t border-surface-200 dark:border-[rgb(var(--color-border))] flex items-center gap-3 relative z-10"
    >
      <div class="w-1 h-10 bg-primary-500 rounded-full"></div>
      <div class="flex-1 min-w-0">
        <p class="text-xs font-medium text-primary-500 mb-0.5">
          Replying to {{ chatStore.replyingTo.sender_name }}
        </p>
        <p class="text-sm text-surface-500 truncate">
          {{ formatPreviewContent(chatStore.replyingTo.content) }}
        </p>
      </div>
      <button
        @click="chatStore.clearReplyingTo()"
        class="p-1 hover:bg-surface-200 dark:hover:bg-surface-700 rounded transition-colors"
      >
        <span class="material-symbols-rounded text-surface-400">close</span>
      </button>
    </div>
    
    <!-- Input -->
    <ChatInput :use-safe-area="true" :has-footer-nav="props.hasFooterNav" />
    
    <!-- Settings Modal -->
    <ChatSettingsModal
      :show="showSettingsModal"
      :conversation-id="chatStore.activeConversationId"
      @close="showSettingsModal = false"
      @update="handleBackgroundUpdate"
    />
    
    <!-- Attachments Panel -->
    <ChatAttachmentsPanel
      :show="showAttachmentsPanel"
      :conversation-id="chatStore.activeConversationId"
      @close="showAttachmentsPanel = false"
    />
    
    <!-- View Together Banner -->
    <ViewTogetherBanner @end-session="chatStore.endViewSession()" />
    
    <!-- Group Chat Modal (for adding people) -->
    <GroupChatModal
      :show="showGroupModal"
      :edit-mode="true"
      :conversation-id="chatStore.activeConversationId"
      @close="showGroupModal = false"
      @updated="handleGroupUpdated"
    />
    
    <!-- Conversation Mind Map -->
    <ConversationMindMap
      v-if="showMindMap"
      @close="showMindMap = false"
      @open-thread="(id) => { showMindMap = false; chatStore.openThread(id) }"
    />
  </div>
</template>

<style scoped>
.fade-enter-active,
.fade-leave-active {
  transition: opacity 0.2s ease;
}

.fade-enter-from,
.fade-leave-to {
  opacity: 0;
}

/* Slide-down transition for pinned panel */
.slide-down-enter-active,
.slide-down-leave-active {
  transition: all 0.25s ease;
  max-height: 50vh;
  overflow: hidden;
}

.slide-down-enter-from,
.slide-down-leave-to {
  max-height: 0;
  opacity: 0;
}

/* Safe area handling for PWA on iOS/Android - matches AppHeader spacing exactly */
.header-safe-area {
  padding-top: calc(0.5rem + env(safe-area-inset-top, 0px));
  padding-bottom: 0.5rem;
  min-height: calc(3.5rem + env(safe-area-inset-top, 0px));
  /* Push content to bottom of header, away from the notch - same as AppHeader's min-h-safe-top */
  align-items: flex-end !important;
}

/* Mobile: bigger icons in chat header */
@media (max-width: 768px) {
  header .material-symbols-rounded {
    font-size: 30px !important;
    line-height: 1 !important;
  }
}

/* Bottom sheet transition */
.conv-sheet-enter-active { transition: opacity 0.2s ease; }
.conv-sheet-enter-active > div:last-child { transition: transform 0.3s cubic-bezier(0.22, 1, 0.36, 1); }
.conv-sheet-leave-active { transition: opacity 0.15s ease; }
.conv-sheet-leave-active > div:last-child { transition: transform 0.2s ease-in; }
.conv-sheet-enter-from { opacity: 0; }
.conv-sheet-enter-from > div:last-child { transform: translateY(100%); }
.conv-sheet-leave-to { opacity: 0; }
.conv-sheet-leave-to > div:last-child { transform: translateY(100%); }
</style>

<style>
/* Pinned message highlight animation (global to reach ChatMessage child) */
.pinned-highlight {
  animation: pinHighlight 2s ease-out;
}

@keyframes pinHighlight {
  0%, 10% {
    background-color: rgba(var(--color-primary-500), 0.15);
    border-radius: 0.5rem;
  }
  100% {
    background-color: transparent;
  }
}
</style>

