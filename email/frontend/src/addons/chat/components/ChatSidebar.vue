<script setup>
import { ref, computed, onMounted, nextTick } from 'vue'
import { useChatStore } from '@/addons/chat/stores/chat'
import { useCallStore } from '@/stores/call'
import { useHuddleStore } from '@/stores/huddle'
import { useToastStore } from '@/stores/toast'
import { useNotificationsStore } from '@/stores/notifications'
import { useChatPresence } from '@/composables/useChatPresence'
import ChatInvitations from './ChatInvitations.vue'
import ChannelBrowser from './ChannelBrowser.vue'
import UserAvatar from '@/components/shared/UserAvatar.vue'
import ChannelCreateModal from './ChannelCreateModal.vue'
import ChannelCategorySection from './ChannelCategorySection.vue'
import ConfirmModal from '@/components/shared/ConfirmModal.vue'

const emit = defineEmits(['new-chat', 'select-conversation'])

const chatStore = useChatStore()
const callStore = useCallStore()
const huddleStore = useHuddleStore()
const toast = useToastStore()
const notificationsStore = useNotificationsStore()
const { getStatusColor, getCustomStatusText } = useChatPresence()

const search = ref('')
const activeFilter = ref('all') // 'all' | 'unread' | 'groups' | 'channels'
const showChannelBrowser = ref(false)
const showChannelCreate = ref(false)
const isMobile = ref(window.innerWidth < 768)

// Search + filter controls are collapsed by default and revealed only when the
// search toggle is pressed, so the list header stays uncluttered. Closing
// search resets the query and filter back to the default "All" view.
const showSearch = ref(false)
const searchInputRef = ref(null)

function toggleSearch() {
  showSearch.value = !showSearch.value
  if (showSearch.value) {
    nextTick(() => searchInputRef.value?.focus())
  } else {
    search.value = ''
    activeFilter.value = 'all'
  }
}

// Mobile-only secondary actions. On desktop these live in the thin ChatRail
// (rendered by ChatView); both surfaces drive the same store-owned panels.
const quickActions = [
  { key: 'status', icon: 'sentiment_satisfied', label: 'Status' },
  { key: 'threads', icon: 'forum', label: 'Threads' },
  { key: 'saved', icon: 'bookmark', label: 'Saved' },
  { key: 'scheduled', icon: 'schedule_send', label: 'Scheduled' },
]

// Discover active calls and huddles on mount for sidebar indicators
onMounted(() => {
  callStore.queryAllActiveCalls()
  huddleStore.fetchAllActiveHuddles()
})

// Context menu
const showContextMenu = ref(false)
const contextMenuPos = ref({ x: 0, y: 0 })
const contextTarget = ref(null)

function openContextMenu(e, conv) {
  e.preventDefault()
  contextTarget.value = conv
  
  // Position menu, keep within viewport
  const menuWidth = 180
  const menuHeight = 120
  let x = e.clientX
  let y = e.clientY
  
  if (x + menuWidth > window.innerWidth) x = window.innerWidth - menuWidth - 8
  if (y + menuHeight > window.innerHeight) y = window.innerHeight - menuHeight - 8
  
  contextMenuPos.value = { x, y }
  showContextMenu.value = true
  
  // Close on next click anywhere
  setTimeout(() => {
    document.addEventListener('click', closeContextMenu, { once: true })
  }, 0)
}

function closeContextMenu() {
  showContextMenu.value = false
  contextTarget.value = null
}

async function archiveConversation() {
  if (!contextTarget.value) return
  const conv = contextTarget.value
  closeContextMenu()
  
  const result = await chatStore.archiveConversation(conv.id)
  if (result.success) {
    toast.success('Conversation archived')
  } else {
    toast.error(result.error || 'Failed to archive')
  }
}

async function togglePinFromMenu() {
  if (!contextTarget.value) return
  const conv = contextTarget.value
  closeContextMenu()
  
  const result = await chatStore.togglePin(conv.id)
  if (result.success) {
    toast.success(conv.is_pinned ? 'Unpinned' : 'Pinned')
  }
}

async function toggleMuteFromMenu() {
  if (!contextTarget.value) return
  const conv = contextTarget.value
  closeContextMenu()
  
  const result = await chatStore.toggleMute(conv.id)
  if (result.success) {
    toast.success(conv.is_muted ? 'Unmuted' : 'Muted')
  }
}

const showDeleteConfirm = ref(false)
const deleteTarget = ref(null)

function promptDeleteConversation() {
  if (!contextTarget.value) return
  deleteTarget.value = contextTarget.value
  closeContextMenu()
  showDeleteConfirm.value = true
}

async function confirmDeleteConversation() {
  if (!deleteTarget.value) return
  const conv = deleteTarget.value
  showDeleteConfirm.value = false
  deleteTarget.value = null
  
  const result = await chatStore.deleteConversation(conv.id)
  if (result.success) {
    toast.success('Chat deleted')
  } else {
    toast.error(result.error || 'Failed to delete')
  }
}

const filteredConversations = computed(() => {
  let list = chatStore.sortedConversations
  
  // Apply tab filter
  if (activeFilter.value === 'unread') {
    list = list.filter(conv => conv.unread_count > 0)
  } else if (activeFilter.value === 'groups') {
    list = list.filter(conv => conv.type === 'group')
  } else if (activeFilter.value === 'channels') {
    list = list.filter(conv => conv.type === 'channel')
  }
  
  // Apply search filter
  if (search.value) {
    const q = search.value.toLowerCase()
    list = list.filter(conv => {
      // Search in group/channel name
      if ((conv.type === 'group' || conv.type === 'channel') && conv.name?.toLowerCase().includes(q)) {
        return true
      }
      // Search in channel slug/topic
      if (conv.type === 'channel') {
        if (conv.slug?.toLowerCase().includes(q)) return true
        if (conv.topic?.toLowerCase().includes(q)) return true
      }
      // Search in participant names
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

const createChannelForCategory = ref(null)

function handleChannelCreated(channel) {
  showChannelCreate.value = false
  showChannelBrowser.value = false
  createChannelForCategory.value = null
  if (channel?.id) {
    chatStore.setActiveConversation(channel.id)
    chatStore.fetchConversations()
    emit('select-conversation', channel.id)
  }
}

function openCreateChannelInCategory(categoryId) {
  createChannelForCategory.value = categoryId || null
  showChannelCreate.value = true
}

async function handleRenameCategory(categoryId, newName) {
  await chatStore.updateCategory(categoryId, { name: newName })
}

async function handleDeleteCategory(categoryId) {
  await chatStore.deleteCategory(categoryId)
}

async function promptCreateCategory() {
  const name = prompt('Category name:')
  if (name?.trim()) {
    const result = await chatStore.createCategory(name.trim())
    if (!result.success) {
      toast.error(result.error || 'Failed to create category')
    }
  }
}

const showChannelCategories = computed(() => {
  return activeFilter.value === 'channels' || activeFilter.value === 'all'
})

const nonChannelConversations = computed(() => {
  let list = chatStore.sortedConversations.filter(c => c.type !== 'channel')

  if (activeFilter.value === 'unread') {
    list = chatStore.sortedConversations.filter(conv => conv.unread_count > 0)
  } else if (activeFilter.value === 'groups') {
    list = list.filter(conv => conv.type === 'group')
  } else if (activeFilter.value === 'channels') {
    list = []
  }

  if (search.value) {
    const q = search.value.toLowerCase()
    list = list.filter(conv => {
      if ((conv.type === 'group') && conv.name?.toLowerCase().includes(q)) return true
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

function handleChannelSelected(channelId) {
  showChannelBrowser.value = false
  emit('select-conversation', channelId)
}

function selectConversation(conversationId) {
  emit('select-conversation', conversationId)
}

function formatTime(dateString) {
  if (!dateString) return ''
  
  const date = new Date(dateString)
  const now = new Date()
  const diffDays = Math.floor((now - date) / (1000 * 60 * 60 * 24))
  
  if (diffDays === 0) {
    // Today - show time
    return date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })
  } else if (diffDays === 1) {
    return 'Yesterday'
  } else if (diffDays < 7) {
    // This week - show day name
    return date.toLocaleDateString([], { weekday: 'short' })
  } else {
    // Older - show date
    return date.toLocaleDateString([], { month: 'short', day: 'numeric' })
  }
}

function getConversationName(conv) {
  // Channel - show with # prefix
  if (conv.type === 'channel') {
    return conv.slug ? `#${conv.slug}` : (conv.name || 'Channel')
  }
  // Group chat - use name
  if (conv.type === 'group') {
    return conv.name || 'Group Chat'
  }
  // DM - use participant name
  const participant = conv.participants?.[0]
  if (!participant) return 'Unknown'
  return participant.display_name || participant.email.split('@')[0]
}

// getStatusColor and getCustomStatusText are provided by useChatPresence composable

function getDmStatusText(conv) {
  if (conv.type !== 'direct') return null
  const participant = conv.participants?.[0]
  return participant ? getCustomStatusText(participant) : null
}

function getDmStatusIcon(conv) {
  const text = getDmStatusText(conv)?.toLowerCase() || ''
  if (text.includes('meeting')) return 'meeting_room'
  if (text.includes('commut')) return 'directions_car'
  if (text.includes('sick')) return 'sick'
  if (text.includes('vacation')) return 'beach_access'
  if (text.includes('remote')) return 'home'
  if (text.includes('lunch')) return 'lunch_dining'
  if (text.includes('focus')) return 'headphones'
  return 'chat_bubble'
}

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
  // Replace GIF format with "Sent a GIF"
  if (/^\[gif:(.+?):(\d+):(\d+)\]$/.test(text)) {
    return 'Sent a GIF'
  }
  // Replace embed format with friendly label
  const embedMatch = text.match(/^\[embed:(\w+):\d+\]$/)
  if (embedMatch) {
    const labels = { drive_file: 'Shared a file', drive_folder: 'Shared a folder', calendar_event: 'Shared an event', board: 'Shared a board', board_card: 'Shared a card', collab_doc: 'Shared a document', mood_board: 'Shared a mood board' }
    return labels[embedMatch[1]] || 'Shared content'
  }
  // Replace voice message format
  if (/^\[voice:\d/.test(text)) {
    return 'Voice message'
  }
  // Replace call message formats - parse by splitting (duration has colons like 05:30)
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
  // Replace emoji codes with a simpler format
  return text.replace(/:([a-z_]+):/g, '[$1]')
}
</script>

<template>
  <aside class="border-r border-surface-200 dark:border-[rgb(var(--color-border))] bg-white dark:bg-[rgb(var(--color-surface))] flex flex-col overflow-hidden">
    <!-- Header -->
    <div class="p-4 border-b border-surface-200 dark:border-[rgb(var(--color-border))]">
      <!-- Title + primary action. The compose button is a labeled pill so it
           reads clearly as THE primary action, not one of a row of tiny icons. -->
      <div class="flex items-center justify-between gap-2 mb-3">
        <h2 class="font-semibold text-lg text-surface-900 dark:text-surface-100">Messages</h2>
        <div class="flex items-center gap-2 flex-shrink-0">
          <button
            @click="toggleSearch"
            :class="[
              'w-10 h-10 flex items-center justify-center rounded-full transition-colors active:scale-95',
              showSearch
                ? 'bg-primary-500 text-white'
                : 'text-surface-500 dark:text-surface-400 hover:bg-surface-100 dark:hover:bg-surface-700'
            ]"
            :title="showSearch ? 'Close search' : 'Search & filters'"
          >
            <span class="material-symbols-rounded text-xl">{{ showSearch ? 'close' : 'search' }}</span>
          </button>
          <button
            @click="emit('new-chat')"
            class="flex items-center gap-1.5 h-10 pl-3 pr-4 bg-primary-500 text-white rounded-full hover:bg-primary-600 active:scale-95 transition-all shadow-sm font-medium text-sm"
            title="New Message"
          >
            <span class="material-symbols-rounded text-xl">edit_square</span>
            <span>New</span>
          </button>
        </div>
      </div>

      <!-- Secondary actions (mobile only): on desktop these live in the thin
           ChatRail. Evenly-spaced, labeled, comfortable tap targets. -->
      <div v-if="isMobile" class="grid grid-cols-4 gap-1 mb-2">
        <button
          v-for="action in quickActions"
          :key="action.key"
          @click="chatStore.openPanel(action.key)"
          class="flex flex-col items-center justify-center gap-0.5 py-1 rounded-xl text-surface-500 dark:text-surface-400 hover:bg-surface-100 dark:hover:bg-surface-700 hover:text-primary-500 dark:hover:text-primary-400 active:scale-95 transition-all"
          :title="action.label"
        >
          <span class="material-symbols-rounded text-[30px]">{{ action.icon }}</span>
          <span class="text-xs font-medium leading-none">{{ action.label }}</span>
        </button>
      </div>
      
      <!-- Search, filters & channel browsing: revealed only when the search
           toggle is pressed, keeping the header compact the rest of the time. -->
      <div v-show="showSearch" class="mt-3 space-y-3">
        <!-- Search -->
        <div class="relative">
          <span class="material-symbols-rounded absolute left-3 top-1/2 -translate-y-1/2 text-surface-400">search</span>
          <input
            ref="searchInputRef"
            v-model="search"
            type="text"
            placeholder="Search conversations..."
            class="w-full pl-10 pr-4 py-2 bg-surface-50 dark:bg-surface-900 border border-surface-200 dark:border-surface-700 rounded-full text-sm focus:ring-2 focus:ring-primary-500 focus:border-primary-500 outline-none"
          />
        </div>

        <!-- Filter tabs -->
        <div class="flex items-center gap-1.5">
          <button
            v-for="tab in [
              { key: 'all', label: 'All' },
              { key: 'unread', label: 'Unread' },
              { key: 'channels', label: 'Channels' },
              { key: 'groups', label: 'Groups' }
            ]"
            :key="tab.key"
            @click="activeFilter = tab.key"
            :class="[
              'px-3 py-1 rounded-full text-xs font-medium transition-colors chat-filter-pill',
              activeFilter === tab.key
                ? 'bg-primary-500 text-white'
                : 'bg-surface-100 dark:bg-surface-800 text-surface-600 dark:text-surface-400 hover:bg-surface-200 dark:hover:bg-surface-700'
            ]"
          >
            {{ tab.label }}
          </button>
        </div>

        <!-- Browse Channels / Create Category -->
        <div class="flex items-center gap-3">
          <button
            @click="showChannelBrowser = true"
            class="flex items-center gap-1.5 text-xs text-primary-500 hover:text-primary-600 font-medium transition-colors"
          >
            <span class="material-symbols-rounded text-sm">explore</span>
            Browse Channels
          </button>
          <button
            @click="promptCreateCategory"
            class="flex items-center gap-1.5 text-xs text-surface-400 hover:text-surface-600 dark:hover:text-surface-300 font-medium transition-colors"
            title="Create channel category"
          >
            <span class="material-symbols-rounded text-sm">create_new_folder</span>
            Category
          </button>
        </div>
      </div>
    </div>
    
    <!-- Conversation list -->
    <div class="flex-1 overflow-y-auto">
      <!-- Missed Calls Banner -->
      <div 
        v-if="notificationsStore.missedCallUnreadCount > 0"
        @click="notificationsStore.openPanel()"
        class="mx-3 mt-2 mb-1 px-3 py-2.5 bg-red-50 dark:bg-red-500/10 border border-red-200 dark:border-red-500/20 rounded-xl cursor-pointer hover:bg-red-100 dark:hover:bg-red-500/20 transition-colors"
      >
        <div class="flex items-center gap-2.5">
          <div class="w-9 h-9 rounded-full bg-red-100 dark:bg-red-500/20 flex items-center justify-center flex-shrink-0">
            <span class="material-symbols-rounded text-red-500 text-xl">phone_missed</span>
          </div>
          <div class="flex-1 min-w-0">
            <p class="text-sm font-semibold text-red-700 dark:text-red-400">
              {{ notificationsStore.missedCallUnreadCount }} missed call{{ notificationsStore.missedCallUnreadCount > 1 ? 's' : '' }}
            </p>
            <p class="text-xs text-red-500 dark:text-red-400/70">Tap to view in notifications</p>
          </div>
          <span class="material-symbols-rounded text-red-400 text-lg">chevron_right</span>
        </div>
      </div>
      
      <!-- Pending Invitations -->
      <ChatInvitations />
      
      <!-- Loading -->
      <div v-if="chatStore.loading" class="flex items-center justify-center py-8">
        <span class="material-symbols-rounded text-2xl text-surface-400 animate-spin">progress_activity</span>
      </div>
      
      <!-- Empty -->
      <div 
        v-else-if="filteredConversations.length === 0" 
        class="flex flex-col items-center justify-center py-12 px-4 text-center"
      >
        <span class="material-symbols-rounded text-5xl text-surface-300 dark:text-surface-600 mb-3">chat_bubble_outline</span>
        <p class="text-surface-500">
          {{ search ? 'No conversations match your search' : activeFilter === 'unread' ? 'No unread conversations' : activeFilter === 'groups' ? 'No group conversations' : 'No conversations yet' }}
        </p>
        <button
          v-if="!search && activeFilter === 'all'"
          @click="emit('new-chat')"
          class="mt-4 text-primary-500 text-sm font-medium hover:underline"
        >
          Start a new conversation
        </button>
      </div>
      
      <!-- Channel Categories (when viewing channels or all) -->
      <div v-else-if="showChannelCategories && (activeFilter === 'channels' || chatStore.categories.length > 0)" class="py-1">
        <!-- Category sections -->
        <ChannelCategorySection
          v-for="cat in chatStore.categories"
          :key="'cat-' + cat.id"
          :category="cat"
          :channels="chatStore.channelsByCategory[cat.id] || []"
          @select-conversation="selectConversation"
          @create-channel="openCreateChannelInCategory"
          @rename-category="handleRenameCategory"
          @delete-category="handleDeleteCategory"
          @context-menu="openContextMenu"
        />

        <!-- Uncategorized channels -->
        <ChannelCategorySection
          v-if="chatStore.uncategorizedChannels.length > 0"
          :channels="chatStore.uncategorizedChannels"
          :title="chatStore.categories.length > 0 ? 'Other Channels' : 'Channels'"
          :is-uncategorized="true"
          @select-conversation="selectConversation"
          @create-channel="openCreateChannelInCategory"
          @context-menu="openContextMenu"
        />

        <!-- Non-channel conversations (DMs, Groups) when filter is 'all' -->
        <template v-if="activeFilter === 'all'">
          <div
            v-for="conv in nonChannelConversations"
            :key="conv.id"
            @click="selectConversation(conv.id)"
            @contextmenu="openContextMenu($event, conv)"
            :class="[
              'flex items-center gap-3 px-4 py-3 cursor-pointer transition-colors',
              chatStore.activeConversationId === conv.id 
                ? 'bg-primary-50 dark:bg-primary-500/10' 
                : 'hover:bg-surface-50 dark:hover:bg-surface-800'
            ]"
          >
            <!-- Avatar -->
            <div class="relative flex-shrink-0">
              <div 
                v-if="conv.type === 'group'"
                class="w-12 h-12 rounded-full bg-primary-100 dark:bg-primary-500/20 flex items-center justify-center"
              >
                <span class="material-symbols-rounded text-primary-500 text-2xl">group</span>
              </div>
              <template v-else>
                <UserAvatar
                  :colleague="conv.participants?.[0]"
                  :email="conv.participants?.[0]?.email"
                  :name="conv.participants?.[0]?.display_name"
                  :avatar-path="conv.participants?.[0]?.avatar_path || ''"
                  size="xl"
                  :show-presence="true"
                />
              </template>
            </div>
            
            <!-- Content -->
            <div class="flex-1 min-w-0">
              <div v-if="getDmStatusText(conv)" class="mb-0.5">
                <span 
                  class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-medium leading-tight bg-primary-50 dark:bg-primary-500/10 text-primary-600 dark:text-primary-400 border border-primary-200/50 dark:border-primary-500/20"
                  :title="getDmStatusText(conv)"
                >
                  <span class="material-symbols-rounded text-[11px]">{{ getDmStatusIcon(conv) }}</span>
                  {{ getDmStatusText(conv) }}
                </span>
              </div>
              <div class="flex items-center justify-between gap-2 mb-0.5">
                <span 
                  :class="[
                    'font-medium truncate chat-conv-name',
                    conv.unread_count > 0 
                      ? 'text-surface-900 dark:text-surface-100' 
                      : 'text-surface-700 dark:text-surface-300'
                  ]"
                >
                  {{ getConversationName(conv) }}
                </span>
                <span class="text-xs text-surface-400 flex-shrink-0">
                  {{ formatTime(conv.last_message_at) }}
                </span>
              </div>
              <div class="flex items-center gap-2">
                <p 
                  :class="[
                    'text-sm truncate flex-1 flex items-center gap-1',
                    isMissedCallPreview(conv.last_message_preview) 
                      ? 'text-red-500 dark:text-red-400 font-semibold' 
                      : conv.unread_count > 0 
                        ? 'text-surface-700 dark:text-surface-300 font-medium' 
                        : 'text-surface-500'
                  ]"
                >
                  <span 
                    v-if="isMissedCallPreview(conv.last_message_preview)" 
                    class="material-symbols-rounded text-sm flex-shrink-0"
                  >phone_missed</span>
                  <template v-if="conv.type === 'group' && !conv.last_message_preview">
                    {{ conv.participant_count || conv.participants?.length || 0 }} members
                  </template>
                  <template v-else>
                    {{ formatPreview(conv.last_message_preview) }}
                  </template>
                </p>
                <div class="flex items-center gap-1.5 flex-shrink-0">
                  <span v-if="conv.is_muted" class="material-symbols-rounded text-sm text-surface-400" title="Muted">notifications_off</span>
                  <span v-if="conv.is_pinned" class="material-symbols-rounded text-sm text-primary-500" title="Pinned">push_pin</span>
                  <span 
                    v-if="conv.unread_count > 0"
                    class="min-w-[20px] h-5 px-1.5 bg-primary-500 text-white text-xs font-medium rounded-full flex items-center justify-center"
                  >
                    {{ conv.unread_count > 99 ? '99+' : conv.unread_count }}
                  </span>
                </div>
              </div>
              <div 
                v-if="callStore.conversationActiveCalls[conv.id]"
                class="flex items-center gap-1.5 mt-1"
              >
                <span class="material-symbols-rounded text-xs text-green-500 animate-pulse">
                  {{ callStore.conversationActiveCalls[conv.id].callType === 'video' ? 'videocam' : 'call' }}
                </span>
                <span class="text-xs font-medium text-green-600 dark:text-green-400">
                  {{ callStore.conversationActiveCalls[conv.id].callType === 'video' ? 'Video call' : 'Voice call' }} in progress
                </span>
              </div>
              <div
                v-if="huddleStore.conversationActiveHuddles[conv.id] && !callStore.conversationActiveCalls[conv.id]"
                class="flex items-center gap-1.5 mt-1"
              >
                <span class="material-symbols-rounded text-xs text-green-500 animate-pulse">headset_mic</span>
                <div class="flex -space-x-1.5">
                  <div
                    v-for="p in (huddleStore.conversationActiveHuddles[conv.id].participants || []).slice(0, 4)"
                    :key="p.id || p.email"
                    :class="[
                      'rounded-full transition-all',
                      huddleStore.speakingParticipants?.has?.(p.email?.toLowerCase()) ? 'ring-2 ring-green-400 ring-offset-1 dark:ring-offset-[rgb(var(--color-surface))]' : ''
                    ]"
                  >
                    <UserAvatar
                      :colleague="p"
                      :email="p.email"
                      :name="p.display_name"
                      :avatar-path="p.avatar_path || ''"
                      size="xs"
                      class="border border-white dark:border-[rgb(var(--color-surface))]"
                    />
                  </div>
                </div>
                <span v-if="(huddleStore.conversationActiveHuddles[conv.id].participantCount || 0) > 4" class="text-[10px] text-green-500">
                  +{{ huddleStore.conversationActiveHuddles[conv.id].participantCount - 4 }}
                </span>
              </div>
            </div>
          </div>
        </template>
      </div>

      <!-- Original flat list (for unread, groups, or when no categories) -->
      <div v-else class="py-1">
        <div
          v-for="conv in filteredConversations"
          :key="conv.id"
          @click="selectConversation(conv.id)"
          @contextmenu="openContextMenu($event, conv)"
          :class="[
            'flex items-center gap-3 px-4 py-3 cursor-pointer transition-colors',
            chatStore.activeConversationId === conv.id 
              ? 'bg-primary-50 dark:bg-primary-500/10' 
              : 'hover:bg-surface-50 dark:hover:bg-surface-800'
          ]"
        >
          <!-- Avatar -->
          <div class="relative flex-shrink-0">
            <div 
              v-if="conv.type === 'channel'"
              :class="[
                'w-12 h-12 rounded-full flex items-center justify-center',
                conv.is_public ? 'bg-primary-100 dark:bg-primary-500/20' : 'bg-amber-100 dark:bg-amber-500/20'
              ]"
            >
              <span class="material-symbols-rounded text-2xl" :class="conv.is_public ? 'text-primary-500' : 'text-amber-500'">{{ conv.is_public ? 'tag' : 'lock' }}</span>
            </div>
            <div 
              v-else-if="conv.type === 'group'"
              class="w-12 h-12 rounded-full bg-primary-100 dark:bg-primary-500/20 flex items-center justify-center"
            >
              <span class="material-symbols-rounded text-primary-500 text-2xl">group</span>
            </div>
            <template v-else>
              <UserAvatar
                :colleague="conv.participants?.[0]"
                :email="conv.participants?.[0]?.email"
                :name="conv.participants?.[0]?.display_name"
                :avatar-path="conv.participants?.[0]?.avatar_path || ''"
                size="xl"
                :show-presence="true"
              />
            </template>
          </div>
          
          <!-- Content -->
          <div class="flex-1 min-w-0">
            <div v-if="getDmStatusText(conv)" class="mb-0.5">
              <span 
                class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-medium leading-tight bg-primary-50 dark:bg-primary-500/10 text-primary-600 dark:text-primary-400 border border-primary-200/50 dark:border-primary-500/20"
                :title="getDmStatusText(conv)"
              >
                <span class="material-symbols-rounded text-[11px]">{{ getDmStatusIcon(conv) }}</span>
                {{ getDmStatusText(conv) }}
              </span>
            </div>
            <div class="flex items-center justify-between gap-2 mb-0.5">
              <span 
                :class="[
                  'font-medium truncate chat-conv-name',
                  conv.unread_count > 0 
                    ? 'text-surface-900 dark:text-surface-100' 
                    : 'text-surface-700 dark:text-surface-300'
                ]"
              >
                {{ getConversationName(conv) }}
              </span>
              <span class="text-xs text-surface-400 flex-shrink-0">
                {{ formatTime(conv.last_message_at) }}
              </span>
            </div>
            <div class="flex items-center gap-2">
              <p 
                :class="[
                  'text-sm truncate flex-1 flex items-center gap-1',
                  isMissedCallPreview(conv.last_message_preview) 
                    ? 'text-red-500 dark:text-red-400 font-semibold' 
                    : conv.unread_count > 0 
                      ? 'text-surface-700 dark:text-surface-300 font-medium' 
                      : 'text-surface-500'
                ]"
              >
                <span 
                  v-if="isMissedCallPreview(conv.last_message_preview)" 
                  class="material-symbols-rounded text-sm flex-shrink-0"
                >phone_missed</span>
                <template v-if="conv.type === 'group' && !conv.last_message_preview">
                  {{ conv.participant_count || conv.participants?.length || 0 }} members
                </template>
                <template v-else>
                  {{ formatPreview(conv.last_message_preview) }}
                </template>
              </p>
              <div class="flex items-center gap-1.5 flex-shrink-0">
                <span v-if="conv.is_muted" class="material-symbols-rounded text-sm text-surface-400" title="Muted">notifications_off</span>
                <span v-if="conv.is_pinned" class="material-symbols-rounded text-sm text-primary-500" title="Pinned">push_pin</span>
                <span 
                  v-if="conv.unread_count > 0"
                  class="min-w-[20px] h-5 px-1.5 bg-primary-500 text-white text-xs font-medium rounded-full flex items-center justify-center"
                >
                  {{ conv.unread_count > 99 ? '99+' : conv.unread_count }}
                </span>
              </div>
            </div>
            <div 
              v-if="callStore.conversationActiveCalls[conv.id]"
              class="flex items-center gap-1.5 mt-1"
            >
              <span class="material-symbols-rounded text-xs text-green-500 animate-pulse">
                {{ callStore.conversationActiveCalls[conv.id].callType === 'video' ? 'videocam' : 'call' }}
              </span>
              <span class="text-xs font-medium text-green-600 dark:text-green-400">
                {{ callStore.conversationActiveCalls[conv.id].callType === 'video' ? 'Video call' : 'Voice call' }} in progress
              </span>
            </div>
            <div 
              v-if="huddleStore.conversationActiveHuddles[conv.id] && !callStore.conversationActiveCalls[conv.id]"
              class="flex items-center gap-1.5 mt-1"
            >
              <span class="material-symbols-rounded text-xs text-green-500 animate-pulse">headset_mic</span>
              <div class="flex -space-x-1.5">
                <div
                  v-for="p in (huddleStore.conversationActiveHuddles[conv.id].participants || []).slice(0, 4)"
                  :key="p.id || p.email"
                  :class="[
                    'rounded-full transition-all',
                    huddleStore.speakingParticipants?.has?.(p.email?.toLowerCase()) ? 'ring-2 ring-green-400 ring-offset-1 dark:ring-offset-[rgb(var(--color-surface))]' : ''
                  ]"
                >
                  <UserAvatar
                    :colleague="p"
                    :email="p.email"
                    :name="p.display_name"
                    :avatar-path="p.avatar_path || ''"
                    size="xs"
                    class="border border-white dark:border-[rgb(var(--color-surface))]"
                  />
                </div>
              </div>
              <span v-if="(huddleStore.conversationActiveHuddles[conv.id].participantCount || 0) > 4" class="text-[10px] text-green-500">
                +{{ huddleStore.conversationActiveHuddles[conv.id].participantCount - 4 }}
              </span>
            </div>
          </div>
        </div>
      </div>
    </div>
    
    <!-- Channel Browser Modal -->
    <Teleport to="body">
      <div v-if="showChannelBrowser" class="fixed inset-0 z-[10000] flex items-center justify-center">
        <div class="absolute inset-0 bg-black/40" @click="showChannelBrowser = false"></div>
        <div class="relative w-full max-w-lg mx-4 max-h-[80vh] bg-white dark:bg-[rgb(var(--color-surface))] rounded-2xl shadow-2xl overflow-hidden">
          <ChannelBrowser 
            @close="showChannelBrowser = false" 
            @create-channel="showChannelBrowser = false; showChannelCreate = true"
            @select-channel="handleChannelSelected"
          />
        </div>
      </div>
    </Teleport>

    <!-- Channel Create Modal -->
    <ChannelCreateModal 
      v-if="showChannelCreate" 
      :default-category-id="createChannelForCategory"
      @close="showChannelCreate = false; createChannelForCategory = null"
      @back="showChannelCreate = false; createChannelForCategory = null"
      @created="handleChannelCreated"
    />

    <!-- Status / Threads / Saved / Scheduled panels render once in ChatView,
         driven by chatStore.activePanel (opened from here on mobile, from the
         ChatRail on desktop). -->

    <!-- Context Menu -->
    <Teleport to="body">
      <div 
        v-if="showContextMenu"
        class="fixed inset-0 z-[9998]"
        @click="closeContextMenu"
        @contextmenu.prevent="closeContextMenu"
      ></div>
      <div
        v-if="showContextMenu"
        class="fixed z-[9999] bg-white dark:bg-surface-800 rounded-xl shadow-xl border border-surface-200 dark:border-surface-700 py-1.5 min-w-[180px]"
        :style="{ left: contextMenuPos.x + 'px', top: contextMenuPos.y + 'px' }"
      >
        <button
          @click="togglePinFromMenu"
          class="w-full flex items-center gap-2.5 px-4 py-2 text-sm text-surface-700 dark:text-surface-300 hover:bg-surface-100 dark:hover:bg-surface-700 transition-colors"
        >
          <span class="material-symbols-rounded text-lg">{{ contextTarget?.is_pinned ? 'push_pin' : 'push_pin' }}</span>
          {{ contextTarget?.is_pinned ? 'Unpin' : 'Pin' }}
        </button>
        <button
          @click="toggleMuteFromMenu"
          class="w-full flex items-center gap-2.5 px-4 py-2 text-sm text-surface-700 dark:text-surface-300 hover:bg-surface-100 dark:hover:bg-surface-700 transition-colors"
        >
          <span class="material-symbols-rounded text-lg">{{ contextTarget?.is_muted ? 'notifications' : 'notifications_off' }}</span>
          {{ contextTarget?.is_muted ? 'Unmute' : 'Mute' }}
        </button>
        <div class="h-px bg-surface-200 dark:bg-surface-700 my-1"></div>
        <button
          @click="archiveConversation"
          class="w-full flex items-center gap-2.5 px-4 py-2 text-sm text-amber-600 dark:text-amber-400 hover:bg-surface-100 dark:hover:bg-surface-700 transition-colors"
        >
          <span class="material-symbols-rounded text-lg">archive</span>
          Archive
        </button>
        <button
          @click="promptDeleteConversation"
          class="w-full flex items-center gap-2.5 px-4 py-2 text-sm text-red-500 dark:text-red-400 hover:bg-surface-100 dark:hover:bg-surface-700 transition-colors"
        >
          <span class="material-symbols-rounded text-lg">delete</span>
          Delete
        </button>
      </div>
    </Teleport>
    
    <!-- Delete confirmation modal -->
    <ConfirmModal
      :show="showDeleteConfirm"
      title="Delete Chat"
      message="This will remove this chat from your list. The other person will still see the conversation. You can start a new chat with them anytime."
      confirmText="Delete"
      confirmColor="red"
      @confirm="confirmDeleteConversation"
      @cancel="showDeleteConfirm = false; deleteTarget = null"
    />

  </aside>
</template>

<style scoped>
</style>

