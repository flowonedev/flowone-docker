<script setup>
import { ref, computed, onMounted, onUnmounted, watch } from 'vue'
import { useRouter } from 'vue-router'
import { useNotificationsStore } from '@/stores/notifications'
import { useChatStore } from '@/addons/chat/stores/chat'
import { useToastStore } from '@/stores/toast'
import { useAddons } from '@/composables/useAddons'
import { useI18n } from 'vue-i18n'
import ConfirmModal from '@/components/ConfirmModal.vue'
import { useCalendarStore } from '@/addons/calendar/stores/calendar'
import MeetingActions from '@/addons/calendar/components/MeetingActions.vue'
import api from '@/services/api'
import { folderToUrlPath } from '@/services/mailRouteService'

const showClearConfirm = ref(false)
const clearingAll = ref(false)

const router = useRouter()
const chatStore = useChatStore()

const notifications = useNotificationsStore()
const toast = useToastStore()
const calendar = useCalendarStore()
const { chatEnabled, emailTrackingEnabled } = useAddons()
const { t } = useI18n()

// Calendar reminders (ephemeral, client-side; sourced from the calendar store)
function reminderCountdown(r) {
  return calendar.getEventCountdown({ start_time: r.startTime, end_time: r.endTime })
}
const visibleReminders = computed(() => calendar.activeReminders.filter(r => reminderCountdown(r).status !== 'ended'))
function reminderCountdownClass(status) {
  switch (status) {
    case 'now':
    case 'imminent':
      return 'bg-red-500 text-white'
    case 'ongoing':
      return 'bg-green-500 text-white'
    case 'upcoming':
      return 'bg-amber-100 text-amber-700 dark:bg-amber-500/20 dark:text-amber-400'
    default:
      return 'bg-surface-100 text-surface-600 dark:bg-surface-700 dark:text-surface-300'
  }
}

const showUnreadOnly = ref(false)
const pinnedOnly = ref(false) // Email tab: filter to only pinned notifications
const expandedNotifications = ref({}) // Track which notifications have expanded read events
const collapsedGroups = ref({}) // Track which date groups are collapsed

// Tab system: 'email' for read receipts, 'general' for everything else
// Default to 'email' when tracking is enabled, 'general' otherwise
const activeTab = ref(emailTrackingEnabled.value ? 'email' : 'general')

// Addons may load async -- once tracking becomes available, switch to email tab
// (only if still on general, i.e. user hasn't manually switched)
watch(emailTrackingEnabled, (enabled) => {
  if (enabled && activeTab.value === 'general') {
    activeTab.value = 'email'
  }
}, { once: true })

const allTabs = computed(() => [
  { id: 'email', label: t('notificationPanel.email'), icon: 'mark_email_read' },
  { id: 'general', label: t('notificationPanel.general'), icon: 'notifications' },
  { id: 'campaigns', label: 'Campaigns', icon: 'campaign' },
])

// Only show the Email / Campaigns tabs when email tracking addon is enabled
const tabs = computed(() =>
  emailTrackingEnabled.value
    ? allTabs.value
    : allTabs.value.filter(tab => tab.id !== 'email' && tab.id !== 'campaigns')
)

// Email notification types (read receipts)
const emailTypes = ['read_receipt', 'link_click']

// General notification types (board invites, system, chat, calls, drive, etc.)
const generalTypes = ['board_invite', 'card_created', 'card_assigned', 'card_comment', 'task_completed', 'reminder', 'system', 'thread_reply', 'missed_call', 'chat_message', 'drive_share', 'scope_creep', 'ph_assigned', 'ph_status_changed', 'ph_card_updated', 'ph_comment_added', 'ph_dependency_added', 'ph_dependency_removed', 'ph_watcher_added', 'ph_inactivity', 'watch_file_edited']

const isCampaignNotification = (n) => emailTypes.includes(n.type) && !!n.campaign_id
const isEmailNotification = (n) => emailTypes.includes(n.type) && !n.campaign_id

// Filter notifications by active tab
const filteredByTab = computed(() => {
  let result
  if (activeTab.value === 'email') {
    result = notifications.notifications.filter(n => isEmailNotification(n))
    // Apply pinned-only filter only on the email tab
    if (pinnedOnly.value) {
      result = result.filter(n => n.pinned)
    }
    return result
  }
  if (activeTab.value === 'campaigns') {
    return notifications.notifications.filter(n => isCampaignNotification(n))
  }
  return notifications.notifications.filter(n => !emailTypes.includes(n.type))
})

// Tab-specific unread counts
const emailUnreadCount = computed(() => {
  return notifications.notifications.filter(n => isEmailNotification(n) && !n.is_read).length
})

const campaignUnreadCount = computed(() => {
  return notifications.notifications.filter(n => isCampaignNotification(n) && !n.is_read).length
})

const generalUnreadCount = computed(() => {
  return notifications.notifications.filter(n => !emailTypes.includes(n.type) && !n.is_read).length
})

function getTabUnreadCount(tabId) {
  if (tabId === 'email') return emailUnreadCount.value
  if (tabId === 'campaigns') return campaignUnreadCount.value
  return generalUnreadCount.value
}

function toggleExpanded(id) {
  expandedNotifications.value[id] = !expandedNotifications.value[id]
}

function toggleGroup(groupKey) {
  collapsedGroups.value[groupKey] = !collapsedGroups.value[groupKey]
}

function isGroupCollapsed(groupKey) {
  return collapsedGroups.value[groupKey] ?? false
}

function getGroupIcon(groupKey) {
  if (groupKey === 'Pinned') return { icon: 'push_pin', color: 'text-amber-500' }
  // Today block headers contain the time-of-day in parens
  if (groupKey.startsWith('TODAY')) {
    if (/Morning|Afternoon|Early Morning/i.test(groupKey)) {
      return { icon: 'wb_sunny', color: 'text-amber-400' }
    }
    if (/Evening/i.test(groupKey)) {
      return { icon: 'wb_twilight', color: 'text-orange-400' }
    }
    return { icon: 'bedtime', color: 'text-indigo-400' }
  }
  // Yesterday / weekday / older = default to moon
  return { icon: 'bedtime', color: 'text-indigo-400' }
}

function getGroupUnreadCount(items) {
  if (!items) return 0
  return items.filter(n => !n.is_read).length
}

function formatTime(dateStr) {
  const date = new Date(dateStr)
  const now = new Date()
  const diffMs = now - date
  const diffMins = Math.floor(diffMs / 60000)
  const diffHours = Math.floor(diffMs / 3600000)
  
  if (diffMins < 1) return 'Just now'
  if (diffMins < 60) return `${diffMins}m ago`
  if (diffHours < 24) return `${diffHours}h ago`
  
  return date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })
}

function formatFullTime(dateStr) {
  const date = new Date(dateStr)
  return date.toLocaleDateString('en-US', { 
    weekday: 'short', 
    month: 'short', 
    day: 'numeric',
    hour: '2-digit',
    minute: '2-digit'
  })
}

function formatEventTime(dateStr) {
  const date = new Date(dateStr)
  return date.toLocaleString('en-US', {
    month: 'short',
    day: 'numeric',
    hour: '2-digit',
    minute: '2-digit'
  })
}

// Sort read events by time (newest first) and group same-reader events within the same minute
function sortedReadEvents(events) {
  if (!events || !events.length) return []
  
  const sorted = [...events].sort((a, b) => {
    const dateA = new Date(a.read_at || 0)
    const dateB = new Date(b.read_at || 0)
    return dateB - dateA // Descending (newest first)
  })
  
  // Group by reader + minute (truncate seconds)
  const grouped = []
  const seen = new Map() // key: "reader|YYYY-MM-DD HH:mm"
  
  for (const event of sorted) {
    const reader = (event.reader_email || event.reader || 'Unknown').toLowerCase()
    const date = new Date(event.read_at || 0)
    const minuteKey = `${reader}|${date.getFullYear()}-${String(date.getMonth()).padStart(2,'0')}-${String(date.getDate()).padStart(2,'0')} ${String(date.getHours()).padStart(2,'0')}:${String(date.getMinutes()).padStart(2,'0')}`
    
    if (seen.has(minuteKey)) {
      // Increment count on the existing grouped entry
      seen.get(minuteKey).count++
    } else {
      const entry = { ...event, count: 1 }
      seen.set(minuteKey, entry)
      grouped.push(entry)
    }
  }
  
  return grouped
}

function sortedClickEvents(events) {
  if (!events || !events.length) return []
  
  const sorted = [...events].sort((a, b) => {
    const dateA = new Date(a.clicked_at || 0)
    const dateB = new Date(b.clicked_at || 0)
    return dateB - dateA
  })
  
  const grouped = []
  const seen = new Map()
  
  for (const event of sorted) {
    const clicker = (event.clicker_email || event.clicker || 'Unknown').toLowerCase()
    const date = new Date(event.clicked_at || 0)
    const minuteKey = `${clicker}|${date.getFullYear()}-${String(date.getMonth()).padStart(2,'0')}-${String(date.getDate()).padStart(2,'0')} ${String(date.getHours()).padStart(2,'0')}:${String(date.getMinutes()).padStart(2,'0')}`
    
    if (seen.has(minuteKey)) {
      seen.get(minuteKey).count++
    } else {
      const entry = { ...event, count: 1 }
      seen.set(minuteKey, entry)
      grouped.push(entry)
    }
  }
  
  return grouped
}

// Group notifications by date and 4-hour blocks
const groupedNotifications = computed(() => {
  const groups = {}
  const now = new Date()
  
  // First, separate pinned notifications (within the active tab)
  const tabNotifications = filteredByTab.value
  const pinned = tabNotifications.filter(n => n.pinned)
  const unpinned = tabNotifications.filter(n => !n.pinned)
  
  if (pinned.length > 0) {
    groups['Pinned'] = pinned
  }
  
  // Group unpinned by date and 4-hour blocks
  unpinned.forEach(notification => {
    const date = new Date(notification.last_read_at || notification.created_at)
    const today = new Date(now.getFullYear(), now.getMonth(), now.getDate())
    const notifDay = new Date(date.getFullYear(), date.getMonth(), date.getDate())
    const diffDays = Math.floor((today - notifDay) / (1000 * 60 * 60 * 24))
    
    let groupKey
    if (diffDays === 0) {
      // Today - group by 4-hour blocks
      const hour = date.getHours()
      const block = Math.floor(hour / 4)
      const blockNames = [
        'Night (12AM \u2013 4AM)',
        'Early Morning (4AM \u2013 8AM)',
        'Morning (8AM \u2013 12PM)',
        'Afternoon (12PM \u2013 4PM)',
        'Evening (4PM \u2013 8PM)',
        'Night (8PM \u2013 12AM)'
      ]
      groupKey = `TODAY \u00B7 ${blockNames[block]}`
    } else if (diffDays === 1) {
      groupKey = 'YESTERDAY'
    } else if (diffDays < 7) {
      groupKey = date.toLocaleDateString('en-US', { weekday: 'long' }).toUpperCase()
    } else {
      groupKey = date.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: diffDays > 365 ? 'numeric' : undefined }).toUpperCase()
    }
    
    if (!groups[groupKey]) {
      groups[groupKey] = []
    }
    groups[groupKey].push(notification)
  })
  
  return groups
})

function getNotificationIcon(type) {
  switch (type) {
    case 'read_receipt': return 'mark_email_read'
    case 'link_click': return 'ads_click'
    case 'reminder': return 'alarm'
    case 'system': return 'info'
    case 'board_invite': return 'group_add'
    case 'card_created': return 'add_card'
    case 'card_assigned': return 'assignment_ind'
    case 'card_comment': return 'chat_bubble'
    case 'task_completed': return 'task_alt'
    case 'thread_reply': return 'forum'
    case 'missed_call': return 'phone_missed'
    case 'chat_message': return 'chat'
    case 'drive_share': return 'folder_shared'
    case 'automation': return 'bolt'
    case 'scope_creep': return 'radar'
    case 'ph_assigned': return 'assignment_ind'
    case 'ph_status_changed': return 'swap_horiz'
    case 'ph_card_updated': return 'edit_note'
    case 'ph_comment_added': return 'comment'
    case 'ph_dependency_added': return 'account_tree'
    case 'ph_dependency_removed': return 'account_tree'
    case 'ph_watcher_added': return 'visibility'
    case 'ph_inactivity': return 'schedule'
    case 'watch_file_edited': return 'visibility'
    default: return 'notifications'
  }
}

function getNotificationColor(type) {
  switch (type) {
    case 'read_receipt': return 'text-green-500'
    case 'link_click': return 'text-blue-500'
    case 'reminder': return 'text-amber-500'
    case 'system': return 'text-blue-500'
    case 'board_invite': return 'text-purple-500'
    case 'card_created': return 'text-violet-500'
    case 'card_assigned': return 'text-teal-500'
    case 'card_comment': return 'text-sky-500'
    case 'task_completed': return 'text-emerald-500'
    case 'thread_reply': return 'text-indigo-500'
    case 'missed_call': return 'text-red-500'
    case 'chat_message': return 'text-cyan-500'
    case 'drive_share': return 'text-orange-500'
    case 'automation': return 'text-amber-500'
    case 'scope_creep': return 'text-red-500'
    case 'ph_assigned': return 'text-teal-500'
    case 'ph_status_changed': return 'text-blue-500'
    case 'ph_card_updated': return 'text-violet-500'
    case 'ph_comment_added': return 'text-sky-500'
    case 'ph_dependency_added': return 'text-amber-500'
    case 'ph_dependency_removed': return 'text-orange-500'
    case 'ph_watcher_added': return 'text-indigo-500'
    case 'ph_inactivity': return 'text-rose-500'
    case 'watch_file_edited': return 'text-amber-500'
    default: return 'text-primary-500'
  }
}

function getNotificationBgColor(type) {
  switch (type) {
    case 'read_receipt': return 'bg-green-100 dark:bg-green-500/20'
    case 'link_click': return 'bg-blue-100 dark:bg-blue-500/20'
    case 'board_invite': return 'bg-purple-100 dark:bg-purple-500/20'
    case 'card_created': return 'bg-violet-100 dark:bg-violet-500/20'
    case 'card_assigned': return 'bg-teal-100 dark:bg-teal-500/20'
    case 'card_comment': return 'bg-sky-100 dark:bg-sky-500/20'
    case 'task_completed': return 'bg-emerald-100 dark:bg-emerald-500/20'
    case 'thread_reply': return 'bg-indigo-100 dark:bg-indigo-500/20'
    case 'missed_call': return 'bg-red-100 dark:bg-red-500/20'
    case 'chat_message': return 'bg-cyan-100 dark:bg-cyan-500/20'
    case 'drive_share': return 'bg-orange-100 dark:bg-orange-500/20'
    case 'automation': return 'bg-amber-100 dark:bg-amber-500/20'
    case 'scope_creep': return 'bg-red-100 dark:bg-red-500/20'
    case 'ph_assigned': return 'bg-teal-100 dark:bg-teal-500/20'
    case 'ph_status_changed': return 'bg-blue-100 dark:bg-blue-500/20'
    case 'ph_card_updated': return 'bg-violet-100 dark:bg-violet-500/20'
    case 'ph_comment_added': return 'bg-sky-100 dark:bg-sky-500/20'
    case 'ph_dependency_added': return 'bg-amber-100 dark:bg-amber-500/20'
    case 'ph_dependency_removed': return 'bg-orange-100 dark:bg-orange-500/20'
    case 'ph_watcher_added': return 'bg-indigo-100 dark:bg-indigo-500/20'
    case 'ph_inactivity': return 'bg-rose-100 dark:bg-rose-500/20'
    case 'watch_file_edited': return 'bg-amber-100 dark:bg-amber-500/20'
    default: return 'bg-blue-100 dark:bg-blue-500/20'
  }
}

async function handleNotificationClick(notification) {
  if (!notification.is_read) {
    await notifications.markAsRead(notification.id)
  }
  
  // Handle Project Hub notifications
  if (notification.type?.startsWith('ph_')) {
    notifications.closePanel()
    const data = notification.data || {}
    if (data.card_id && data.board_id) {
      await router.push({ path: `/boards/${data.board_id}`, query: { card: data.card_id } })
    } else if (data.card_id) {
      await router.push({ path: '/boards', query: { card: data.card_id } })
    } else {
      await router.push('/boards')
    }
    return
  }

  // Handle watch folder file edit notifications
  if (notification.type === 'watch_file_edited') {
    notifications.closePanel()
    const data = notification.data || {}
    if (data.card_id && data.board_id) {
      await router.push({ path: `/boards/${data.board_id}`, query: { card: data.card_id } })
    } else if (data.board_id) {
      await router.push(`/boards/${data.board_id}`)
    }
    return
  }

  // Handle board-related notifications
  if (['board_invite', 'card_created', 'card_assigned', 'card_comment', 'task_completed', 'scope_creep'].includes(notification.type)) {
    notifications.closePanel()
    
    const data = notification.data || {}
    
    if (data.board_id) {
      await router.push(`/boards/${data.board_id}`)
    } else {
      await router.push('/boards')
    }
    return
  }
  
  // Handle chat-related notifications (thread replies, chat messages)
  if (['thread_reply', 'chat_message'].includes(notification.type)) {
    notifications.closePanel()
    if (chatEnabled.value) {
      const data = notification.data || {}
      if (data.conversation_id) {
        // Open the chat conversation
        chatStore.setActiveConversation(data.conversation_id)
        // If it's a thread reply, open the thread
        if (notification.type === 'thread_reply' && data.message_id) {
          chatStore.openThread(data.message_id)
        }
      }
    }
    return
  }
  
  // Handle missed call notifications
  if (notification.type === 'missed_call') {
    notifications.closePanel()
    if (chatEnabled.value) {
      const data = notification.data || {}
      if (data.conversation_id) {
        chatStore.setActiveConversation(data.conversation_id)
      }
    }
    return
  }
  
  // Handle drive share notifications
  if (notification.type === 'drive_share') {
    notifications.closePanel()
    await router.push('/drive')
    return
  }
  
  // Handle automation notifications - navigate to the relevant target
  if (notification.type === 'automation') {
    notifications.closePanel()
    const data = notification.data || {}
    
    if (data.board_id) {
      await router.push(`/boards/${data.board_id}`)
    } else if (data.moodboard_id) {
      await router.push(`/mood/${data.moodboard_id}`)
    } else if (data.task_id) {
      await router.push('/boards')
    } else if (data.client_id) {
      await router.push(`/clients/${data.client_id}`)
    } else if (data.deal_id) {
      await router.push('/crm/pipeline')
    } else if (data.target_type === 'moodboard') {
      await router.push('/mood')
    } else {
      await router.push('/crm/automation')
    }
    return
  }
  
  // Campaign notifications: navigate to the campaign detail page
  if (notification.campaign_id && emailTypes.includes(notification.type)) {
    notifications.closePanel()
    await router.push({ path: '/campaigns', query: { id: notification.campaign_id } })
    return
  }
  
  // Read-receipt / link-click: resolve the actual email server-side (by
  // Message-ID, falling back to subject across folders) and open it via the
  // canonical email route, which reliably switches folder + fetches the
  // message. Works regardless of how old the email is or which folder it's in.
  if ((notification.type === 'read_receipt' || notification.type === 'link_click')
      && notification.data?.tracking_id) {
    await openTrackedEmail(notification.data.tracking_id)
    return
  }
}

async function openTrackedEmail(trackingId) {
  notifications.closePanel()
  try {
    const res = await api.get(`/tracking/${trackingId}/locate`)
    const located = res.data?.data
    if (res.data?.success && located?.uid) {
      const folderPath = folderToUrlPath(located.folder)
      await router.push(`/email/${folderPath}/message/${located.uid}`)
    } else {
      toast.error(t('notificationPanel.emailNotFound'))
    }
  } catch (e) {
    const status = e?.response?.status
    toast.error(status === 404 ? t('notificationPanel.emailNotFound') : t('notificationPanel.openEmailFailed'))
  }
}

async function handleDelete(e, id) {
  e.stopPropagation()
  await notifications.deleteNotification(id)
}

async function handleTogglePin(e, notification) {
  e.stopPropagation()
  await notifications.togglePin(notification.id)
  toast.success(notification.pinned ? t('notificationPanel.notificationUnpinned') : t('notificationPanel.notificationPinned'))
}

async function handleMarkAllRead() {
  await notifications.markAllAsRead()
  toast.success(t('notificationPanel.allNotificationsMarkedAsRead'))
}

function handleClearAll() {
  if (filteredByTab.value.length === 0) return
  showClearConfirm.value = true
}

const clearConfirmTitle = computed(() => {
  if (activeTab.value === 'email') return t('notificationPanel.clearEmailNotifications')
  if (activeTab.value === 'campaigns') return t('notificationPanel.clearCampaignNotifications')
  return t('notificationPanel.clearGeneralNotifications')
})

const clearConfirmMessage = computed(() => {
  if (activeTab.value === 'email') return t('notificationPanel.areYouSureDeleteEmail')
  if (activeTab.value === 'campaigns') return t('notificationPanel.areYouSureDeleteCampaigns')
  return t('notificationPanel.areYouSureDeleteGeneral')
})

const clearSuccessMessage = computed(() => {
  if (activeTab.value === 'email') return t('notificationPanel.emailNotificationsCleared')
  if (activeTab.value === 'campaigns') return t('notificationPanel.campaignNotificationsCleared')
  return t('notificationPanel.generalNotificationsCleared')
})

async function confirmClearAll() {
  clearingAll.value = true
  try {
    await notifications.clearAllNotifications(activeTab.value)
    toast.success(clearSuccessMessage.value)
  } finally {
    clearingAll.value = false
    showClearConfirm.value = false
  }
}

watch(() => notifications.panelOpen, async (isOpen) => {
  if (isOpen) {
    // Always consolidate duplicates when panel opens, then fetch
    await notifications.consolidateNotifications()
  }
})

watch(showUnreadOnly, (unreadOnly) => {
  notifications.fetchNotifications(unreadOnly)
})

onMounted(() => {
  // App.vue already starts polling at 30s interval.
  // When the panel is open, speed up to 10s for snappier updates.
  notifications.startPolling(10000)
})

onUnmounted(() => {
  // Slow back down to 30s safety-net polling (don't stop entirely - App.vue needs it)
  notifications.startPolling(30000)
})
</script>

<template>
  <Teleport to="body">
    <!-- Backdrop -->
    <Transition name="fade">
      <div 
        v-if="notifications.panelOpen" 
        class="fixed inset-0 bg-black/20 dark:bg-black/40 z-40"
        @click="notifications.closePanel()"
      ></div>
    </Transition>
    
    <!-- Panel -->
    <Transition name="slide-right">
      <div 
        v-if="notifications.panelOpen"
        class="fixed top-0 sm:top-0 right-0 h-full w-full max-w-md bg-white dark:bg-surface-800 shadow-2xl z-50 flex flex-col mobile-panel-top"
      >
        <!-- Header -->
        <div class="flex items-center justify-between px-5 py-4 border-b border-surface-200 dark:border-surface-700">
          <div class="flex items-center gap-3">
            <div class="w-9 h-9 rounded-full bg-primary-100 dark:bg-primary-500/15 flex items-center justify-center flex-shrink-0">
              <span class="material-symbols-rounded text-primary-500 text-xl">notifications</span>
            </div>
            <div>
              <h2 class="font-semibold text-surface-900 dark:text-surface-100">{{ $t('notificationPanel.notifications') }}</h2>
              <p v-if="notifications.unreadCount > 0" class="text-xs text-surface-500">
                {{ notifications.unreadCount }} {{ $t('notificationPanel.unread') }}
              </p>
            </div>
          </div>
          <button @click="notifications.closePanel()" class="btn-ghost btn-icon">
            <span class="material-symbols-rounded">close</span>
          </button>
        </div>
        
        <!-- Tab bar -->
        <div class="flex border-b border-surface-200 dark:border-surface-700 bg-white dark:bg-surface-800">
          <button
            v-for="tab in tabs"
            :key="tab.id"
            @click="activeTab = tab.id"
            :class="[
              'flex-1 flex items-center justify-center gap-2 px-4 py-3 text-sm font-medium transition-all relative',
              activeTab === tab.id
                ? 'text-primary-600 dark:text-primary-400'
                : 'text-surface-500 hover:text-surface-700 dark:hover:text-surface-300'
            ]"
          >
            <span class="material-symbols-rounded text-lg">{{ tab.icon }}</span>
            <span>{{ tab.label }}</span>
            <!-- Unread badge -->
            <span 
              v-if="getTabUnreadCount(tab.id) > 0"
              class="min-w-[18px] h-[18px] px-1 flex items-center justify-center rounded-full text-[10px] font-bold bg-red-500 text-white"
            >
              {{ getTabUnreadCount(tab.id) }}
            </span>
            <!-- Active indicator -->
            <div 
              v-if="activeTab === tab.id"
              class="absolute bottom-0 left-2 right-2 h-0.5 bg-primary-500 rounded-full"
            ></div>
          </button>
        </div>
        
        <!-- Actions bar -->
        <div class="flex items-center justify-between px-5 py-3 border-b border-surface-200 dark:border-surface-700 bg-surface-50 dark:bg-surface-850">
          <label class="flex items-center gap-2 cursor-pointer">
            <button
              @click="showUnreadOnly = !showUnreadOnly"
              :class="['w-9 h-5 rounded-full transition-colors relative shrink-0', showUnreadOnly ? 'bg-primary-500' : 'bg-surface-300 dark:bg-surface-600']"
            >
              <span :class="['absolute top-0.5 left-0.5 w-4 h-4 rounded-full bg-white shadow transition-transform duration-200', showUnreadOnly ? 'translate-x-4' : 'translate-x-0']"></span>
            </button>
            <span class="text-sm text-surface-600 dark:text-surface-400">{{ $t('notificationPanel.unreadOnly') }}</span>
          </label>
          
          <div class="flex items-center gap-3">
            <button 
              v-if="notifications.unreadCount > 0"
              @click="handleMarkAllRead"
              class="text-sm text-primary-500 hover:text-primary-600 font-medium"
            >
              {{ $t('notificationPanel.markAllRead') }}
            </button>
            <button 
              v-if="filteredByTab.length > 0"
              @click="handleClearAll"
              class="text-sm text-red-500 hover:text-red-600 font-medium"
            >
              {{ $t('notificationPanel.clearAll') }}
            </button>
          </div>
        </div>
        
        <!-- Calendar reminders (client-side, ephemeral) -->
        <div v-if="visibleReminders.length" class="border-b border-surface-200 dark:border-surface-700">
          <div class="flex items-center justify-between px-5 py-2.5 bg-amber-50 dark:bg-amber-500/10">
            <div class="flex items-center gap-2">
              <span class="material-symbols-rounded text-amber-500 text-lg">notifications_active</span>
              <span class="text-sm font-semibold text-surface-800 dark:text-surface-200">{{ $t('calendarReminders.title') }}</span>
            </div>
            <button
              @click="calendar.dismissAllReminders()"
              class="text-xs font-medium text-surface-500 hover:text-surface-700 dark:hover:text-surface-300"
            >
              {{ $t('calendarReminders.dismissAll') }}
            </button>
          </div>
          <div class="divide-y divide-surface-100 dark:divide-surface-700">
            <div v-for="r in visibleReminders" :key="r.key" class="px-5 py-3">
              <div class="flex items-start gap-2.5">
                <span class="w-1 self-stretch rounded-full flex-shrink-0" :style="{ backgroundColor: r.color || '#a855f7' }"></span>
                <div class="flex-1 min-w-0">
                  <div class="flex items-start justify-between gap-2">
                    <p class="text-sm font-medium text-surface-900 dark:text-surface-100 truncate">
                      {{ r.title || $t('calendarReminders.untitled') }}
                    </p>
                    <button
                      @click="calendar.dismissReminder(r.key)"
                      class="flex-shrink-0 w-6 h-6 flex items-center justify-center rounded-full text-surface-400 hover:bg-surface-100 dark:hover:bg-surface-700"
                      :title="$t('calendarReminders.dismiss')"
                    >
                      <span class="material-symbols-rounded text-base leading-none">close</span>
                    </button>
                  </div>
                  <div class="mt-1">
                    <span
                      :class="[
                        'inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[11px] font-semibold',
                        reminderCountdownClass(reminderCountdown(r).status),
                      ]"
                    >
                      <span class="material-symbols-rounded text-xs leading-none">
                        {{ reminderCountdown(r).status === 'ongoing' ? 'play_circle' : 'schedule' }}
                      </span>
                      {{ reminderCountdown(r).text }}
                    </span>
                  </div>
                  <div v-if="r.is_meeting" class="mt-2.5">
                    <MeetingActions
                      :event-id="r.eventId"
                      :start-time="r.startTime"
                      :end-time="r.endTime"
                      :is-host="r.isHost"
                      layout="stack"
                    />
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Content -->
        <div class="flex-1 overflow-y-auto">
          <!-- Loading -->
          <div v-if="notifications.loading" class="flex items-center justify-center py-12">
            <span class="spinner text-primary-500"></span>
          </div>
          
          <!-- Empty state -->
          <div v-else-if="filteredByTab.length === 0" class="flex flex-col items-center justify-center py-12 px-6 text-center">
            <span class="material-symbols-rounded text-5xl text-surface-300 dark:text-surface-600 mb-3">
              {{ activeTab === 'email' && pinnedOnly ? 'push_pin' : activeTab === 'email' ? 'mark_email_read' : activeTab === 'campaigns' ? 'campaign' : 'notifications_off' }}
            </span>
            <p class="text-surface-500">
              {{ activeTab === 'email' && pinnedOnly
                ? $t('notificationPanel.noPinnedNotifications')
                : showUnreadOnly 
                  ? $t('notificationPanel.noUnreadNotifications') 
                  : activeTab === 'email' 
                    ? $t('notificationPanel.noEmailNotificationsYet') 
                    : activeTab === 'campaigns'
                      ? 'No campaign notifications yet'
                      : $t('notificationPanel.noGeneralNotificationsYet') }}
            </p>
            <p class="text-sm text-surface-400 mt-1">
              {{ activeTab === 'email' && pinnedOnly
                ? $t('notificationPanel.pinForQuickAccess')
                : activeTab === 'email' 
                  ? $t('notificationPanel.readReceiptsAppearHere') 
                  : activeTab === 'campaigns'
                    ? 'Opens and clicks from your email campaigns will appear here'
                    : $t('notificationPanel.generalAppearHere') }}
            </p>
          </div>
          
          <!-- Grouped notifications -->
          <div v-else>
            <div v-for="(items, group) in groupedNotifications" :key="group">
              <!-- Collapsible group header -->
              <button
                @click="toggleGroup(group)"
                :class="[
                  'w-full px-5 py-2.5 text-xs font-medium uppercase tracking-wider sticky top-0 flex items-center justify-between cursor-pointer hover:brightness-95 transition-all z-10',
                  group === 'Pinned' 
                    ? 'text-amber-600 bg-amber-50 dark:bg-amber-500/10 dark:text-amber-400' 
                    : 'text-surface-500 bg-surface-50 dark:bg-surface-850'
                ]"
              >
                <span class="flex items-center gap-2">
                  <span 
                    v-if="activeTab === 'email'"
                    :class="['material-symbols-rounded text-base', getGroupIcon(group).color]"
                  >
                    {{ getGroupIcon(group).icon }}
                  </span>
                  <span v-else-if="group === 'Pinned'" class="material-symbols-rounded text-sm">push_pin</span>
                  <span class="normal-case tracking-normal">{{ group }}</span>
                  <!-- "X new" indicator for email tab, or count for other tabs -->
                  <template v-if="activeTab === 'email'">
                    <span 
                      v-if="getGroupUnreadCount(items) > 0"
                      class="inline-flex items-center gap-1 text-[11px] font-medium normal-case tracking-normal text-green-600 dark:text-green-400 ml-1"
                    >
                      <span class="w-1.5 h-1.5 rounded-full bg-green-500"></span>
                      {{ getGroupUnreadCount(items) }} {{ $t('notificationPanel.new') }}
                    </span>
                  </template>
                  <span v-else class="text-surface-400 font-normal normal-case ml-1">({{ items.length }})</span>
                </span>
                <span class="material-symbols-rounded text-base transition-transform" :class="{ 'rotate-180': !isGroupCollapsed(group) }">
                  expand_more
                </span>
              </button>
              
              <!-- Collapsible content with smooth transition -->
              <Transition name="collapse">
                <div 
                  v-show="!isGroupCollapsed(group)" 
                  :class="activeTab === 'email' 
                    ? 'px-3 py-2 space-y-2' 
                    : 'divide-y divide-surface-100 dark:divide-surface-700'"
                >
                <div 
                  v-for="notification in items" 
                  :key="notification.id"
                  @click="handleNotificationClick(notification)"
                  :class="[
                    'cursor-pointer transition-all group',
                    activeTab === 'email'
                      ? [
                          'rounded-xl border bg-white dark:bg-surface-800 p-3 hover:shadow-sm',
                          notification.pinned
                            ? 'border-amber-300 dark:border-amber-500/40 border-l-4 border-l-amber-400'
                            : !notification.is_read
                              ? 'border-surface-200 dark:border-surface-700 border-l-4 border-l-green-500'
                              : 'border-surface-200 dark:border-surface-700'
                        ]
                      : [
                          'px-5 py-4 hover:bg-surface-50 dark:hover:bg-surface-750',
                          !notification.is_read ? 'bg-primary-50/50 dark:bg-primary-500/5' : '',
                          notification.pinned ? 'border-l-2 border-amber-400' : ''
                        ]
                  ]"
                >
                  <div class="flex gap-3">
                    <!-- Icon -->
                    <div :class="['w-10 h-10 rounded-full flex items-center justify-center flex-shrink-0', 
                      !notification.is_read 
                        ? getNotificationBgColor(notification.type)
                        : 'bg-surface-100 dark:bg-surface-700'
                    ]">
                      <span :class="['material-symbols-rounded', getNotificationColor(notification.type)]">
                        {{ getNotificationIcon(notification.type) }}
                      </span>
                    </div>
                    
                    <!-- Content -->
                    <div class="flex-1 min-w-0">
                      <div class="flex items-start justify-between gap-2">
                        <div class="flex-1 min-w-0">
                          <p :class="['text-sm', !notification.is_read ? 'font-semibold text-surface-900 dark:text-surface-100' : 'text-surface-700 dark:text-surface-300']">
                            {{ notification.title }}
                          </p>
                          <!-- Campaign badge -->
                          <span v-if="notification.campaign_id && activeTab === 'campaigns'"
                            class="inline-flex items-center gap-1 px-2 py-0.5 mt-1 mr-1 text-[10px] font-medium bg-purple-100 dark:bg-purple-500/15 text-purple-600 dark:text-purple-400 rounded-full">
                            <span class="material-symbols-rounded text-xs">campaign</span>
                            Campaign
                          </span>
                          <!-- Read count badge -->
                          <span v-if="notification.data?.total_reads > 1" 
                            class="inline-flex items-center gap-1 px-2 py-0.5 mt-1 text-xs bg-green-100 dark:bg-green-500/20 text-green-700 dark:text-green-400 rounded-full">
                            <span class="material-symbols-rounded text-xs">visibility</span>
                            {{ notification.data.total_reads }} {{ $t('notificationPanel.opens') }}
                            <span v-if="notification.data.unique_readers > 1" class="text-green-600 dark:text-green-500">
                              ({{ notification.data.unique_readers }} {{ $t('notificationPanel.people') }})
                            </span>
                          </span>
                        </div>
                        <div class="flex items-center gap-1 flex-shrink-0">
                          <span class="text-xs text-surface-400 whitespace-nowrap">
                            {{ formatTime(notification.last_read_at || notification.created_at) }}
                          </span>
                          <!-- Pin button -->
                          <button 
                            @click="handleTogglePin($event, notification)"
                            :class="[
                              'btn-ghost btn-icon btn-sm',
                              notification.pinned 
                                ? 'text-amber-500 opacity-100' 
                                : 'opacity-0 group-hover:opacity-100 text-surface-400 hover:text-amber-500'
                            ]"
                            :title="notification.pinned ? $t('notificationPanel.unpin') : $t('notificationPanel.pin')"
                          >
                            <span class="material-symbols-rounded text-lg">push_pin</span>
                          </button>
                          <!-- Delete button -->
                          <button 
                            @click="handleDelete($event, notification.id)"
                            class="opacity-0 group-hover:opacity-100 btn-ghost btn-icon btn-sm text-surface-400 hover:text-red-500"
                          >
                            <span class="material-symbols-rounded text-lg">close</span>
                          </button>
                        </div>
                      </div>
                      
                      <!-- Read receipt details -->
                      <template v-if="notification.type === 'read_receipt' && notification.data">
                        <div class="mt-2 p-3 bg-surface-100 dark:bg-surface-700/50 rounded-lg space-y-2">
                          <!-- Email subject -->
                          <div class="flex items-center gap-2">
                            <span class="material-symbols-rounded text-base text-surface-400 flex-shrink-0">mail</span>
                            <p class="text-sm font-medium text-surface-700 dark:text-surface-300 truncate" :title="notification.data.subject">
                              {{ notification.data.subject || $t('notificationPanel.noSubject') }}
                            </p>
                          </div>
                          
                          <!-- Read events list (expandable) -->
                          <div class="flex items-start gap-2">
                            <span class="material-symbols-rounded text-base text-surface-400 mt-0.5 flex-shrink-0">schedule</span>
                            <div class="flex-1 min-w-0">
                              <button 
                                v-if="sortedReadEvents(notification.read_events).length > 1"
                                @click.stop="toggleExpanded(notification.id)"
                                class="flex items-center gap-1 text-xs text-green-600 dark:text-green-400 hover:text-green-700 dark:hover:text-green-300 font-medium"
                              >
                                <span class="material-symbols-rounded text-sm">
                                  {{ expandedNotifications[notification.id] ? 'expand_less' : 'expand_more' }}
                                </span>
                                {{ sortedReadEvents(notification.read_events).length }} {{ $t('notificationPanel.readEvents') }}
                              </button>
                              
                              <!-- Read events (sorted newest first, grouped by reader+minute) -->
                              <div :class="[
                                'space-y-2 mt-1',
                                sortedReadEvents(notification.read_events).length > 1 && !expandedNotifications[notification.id] ? 'max-h-20 overflow-hidden' : ''
                              ]">
                                <div 
                                  v-for="(event, idx) in sortedReadEvents(notification.read_events)" 
                                  :key="idx"
                                  class="flex items-center justify-between text-sm py-1"
                                  :class="idx > 0 ? 'border-t border-surface-200 dark:border-surface-600' : ''"
                                >
                                  <div class="flex items-center gap-2 min-w-0">
                                    <span class="material-symbols-rounded text-sm text-green-500">check_circle</span>
                                    <span class="text-surface-700 dark:text-surface-300 truncate">
                                      {{ event.reader || event.reader_email || 'Unknown' }}
                                    </span>
                                    <span v-if="event.count > 1" class="text-xs text-surface-400 bg-surface-200 dark:bg-surface-600 px-1.5 py-0.5 rounded-full flex-shrink-0">
                                      x{{ event.count }}
                                    </span>
                                  </div>
                                  <span class="text-xs text-surface-400 flex-shrink-0 ml-2">
                                    {{ formatEventTime(event.read_at) }}
                                  </span>
                                </div>
                              </div>
                              
                              <!-- Show first event if no read_events array (legacy) -->
                              <div v-if="!notification.read_events?.length" class="text-sm text-surface-700 dark:text-surface-300">
                                {{ notification.data.recipient_display || notification.data.recipient || 'Unknown' }}
                                <span class="text-xs text-surface-400 ml-2">
                                  {{ formatEventTime(notification.created_at) }}
                                </span>
                              </div>
                            </div>
                          </div>
                          
                          <!-- All recipients (if multiple) -->
                          <div v-if="notification.data.all_recipients?.length > 1" class="flex items-start gap-2 pt-2 border-t border-surface-200 dark:border-surface-600">
                            <span class="material-symbols-rounded text-base text-surface-400 mt-0.5 flex-shrink-0">group</span>
                            <div class="flex-1 min-w-0">
                              <p class="text-xs text-surface-500">
                                {{ $t('notificationPanel.sentTo') }} {{ notification.data.all_recipients.length }} {{ $t('notificationPanel.recipients') }}
                              </p>
                              <p class="text-xs text-surface-400 truncate mt-0.5" :title="notification.data.all_recipients.join(', ')">
                                {{ notification.data.all_recipients.join(', ') }}
                              </p>
                            </div>
                          </div>
                          <!-- Campaign link -->
                          <div v-if="notification.campaign_id" class="pt-2 mt-2 border-t border-surface-200 dark:border-surface-600">
                            <span class="text-xs text-primary-500 hover:text-primary-600 font-medium flex items-center gap-1">
                              <span class="material-symbols-rounded text-sm">campaign</span>
                              View campaign details
                              <span class="material-symbols-rounded text-sm">arrow_forward</span>
                            </span>
                          </div>
                        </div>
                      </template>
                      
                      <!-- Link click details -->
                      <template v-else-if="notification.type === 'link_click' && notification.data">
                        <div class="mt-2 p-3 bg-surface-100 dark:bg-surface-700/50 rounded-lg space-y-2">
                          <div class="flex items-start gap-2">
                            <span class="material-symbols-rounded text-sm text-surface-400 mt-0.5">link</span>
                            <div class="flex-1 min-w-0">
                              <p class="text-xs text-surface-500">Clicked link</p>
                              <p class="text-sm text-primary-500 truncate" :title="notification.data.last_url || notification.data.url">
                                {{ notification.data.last_url || notification.data.url }}
                              </p>
                            </div>
                          </div>
                          <div class="flex items-start gap-2">
                            <span class="material-symbols-rounded text-sm text-surface-400 mt-0.5">person</span>
                            <div class="flex-1 min-w-0">
                              <p class="text-xs text-surface-500">By</p>
                              <p class="text-sm text-surface-700 dark:text-surface-300 truncate">
                                {{ notification.data.last_clicker_email || notification.data.recipient_email || 'Unknown' }}
                              </p>
                            </div>
                          </div>
                          
                          <div class="flex items-start gap-2">
                            <span class="material-symbols-rounded text-sm text-surface-400 mt-0.5">schedule</span>
                            <div class="flex-1 min-w-0">
                              <button 
                                v-if="sortedClickEvents(notification.read_events).length > 1"
                                @click.stop="toggleExpanded(notification.id)"
                                class="flex items-center gap-1 text-xs text-blue-500 hover:text-blue-600 font-medium"
                              >
                                <span class="material-symbols-rounded text-sm">
                                  {{ expandedNotifications[notification.id] ? 'expand_less' : 'expand_more' }}
                                </span>
                                {{ notification.data.total_clicks || sortedClickEvents(notification.read_events).length }} clicks
                              </button>
                              
                              <div :class="[
                                'space-y-2 mt-1',
                                sortedClickEvents(notification.read_events).length > 1 && !expandedNotifications[notification.id] ? 'max-h-20 overflow-hidden' : ''
                              ]">
                                <div 
                                  v-for="(event, idx) in sortedClickEvents(notification.read_events)" 
                                  :key="idx"
                                  class="flex items-center justify-between text-sm py-1"
                                  :class="idx > 0 ? 'border-t border-surface-200 dark:border-surface-600' : ''"
                                >
                                  <div class="flex items-center gap-2 min-w-0">
                                    <span class="material-symbols-rounded text-xs text-blue-500">ads_click</span>
                                    <span class="text-surface-700 dark:text-surface-300 truncate">
                                      {{ event.clicker_email || event.clicker || 'Unknown' }}
                                    </span>
                                    <span v-if="event.count > 1" class="text-xs text-surface-400 bg-surface-200 dark:bg-surface-600 px-1.5 py-0.5 rounded-full flex-shrink-0">
                                      x{{ event.count }}
                                    </span>
                                  </div>
                                  <span class="text-xs text-surface-400 flex-shrink-0 ml-2">
                                    {{ formatEventTime(event.clicked_at) }}
                                  </span>
                                </div>
                              </div>
                              
                              <div v-if="!notification.read_events?.length" class="text-sm text-surface-700 dark:text-surface-300">
                                {{ notification.data.last_clicker_email || notification.data.recipient_email || 'Unknown' }}
                                <span class="text-xs text-surface-400 ml-2">
                                  {{ formatEventTime(notification.created_at) }}
                                </span>
                              </div>
                            </div>
                          </div>
                          <!-- Campaign link -->
                          <div v-if="notification.campaign_id" class="pt-2 mt-2 border-t border-surface-200 dark:border-surface-600">
                            <span class="text-xs text-primary-500 hover:text-primary-600 font-medium flex items-center gap-1">
                              <span class="material-symbols-rounded text-sm">campaign</span>
                              View campaign details
                              <span class="material-symbols-rounded text-sm">arrow_forward</span>
                            </span>
                          </div>
                        </div>
                      </template>
                      
                      <!-- Board invite details -->
                      <template v-else-if="notification.type === 'board_invite' && notification.data">
                        <div class="mt-2 p-3 bg-surface-100 dark:bg-surface-700/50 rounded-lg space-y-2">
                          <div class="flex items-center gap-2">
                            <span class="material-symbols-rounded text-sm text-purple-500">dashboard</span>
                            <span class="text-sm font-medium text-surface-700 dark:text-surface-300">
                              {{ notification.data.board_name || 'Board' }}
                            </span>
                            <span v-if="notification.data.role" class="text-xs px-2 py-0.5 rounded-full bg-purple-100 dark:bg-purple-500/20 text-purple-600 dark:text-purple-400">
                              {{ notification.data.role }}
                            </span>
                          </div>
                          <p v-if="notification.data.invited_by" class="text-xs text-surface-500">
                            {{ $t('notificationPanel.invitedBy') }} {{ notification.data.invited_by }}
                          </p>
                          <button 
                            @click.stop="handleNotificationClick(notification)"
                            class="text-xs text-primary-500 hover:text-primary-600 font-medium flex items-center gap-1"
                          >
                            <span class="material-symbols-rounded text-sm">open_in_new</span>
                            {{ $t('notificationPanel.openBoard') }}
                          </button>
                        </div>
                      </template>
                      
                      <!-- Card assignment / comment details -->
                      <template v-else-if="['card_created', 'card_assigned', 'card_comment'].includes(notification.type) && notification.data">
                        <div class="mt-2 p-3 bg-surface-100 dark:bg-surface-700/50 rounded-lg">
                          <div class="flex items-center gap-2">
                            <span class="material-symbols-rounded text-sm" :class="notification.type === 'card_created' ? 'text-violet-500' : notification.type === 'card_assigned' ? 'text-teal-500' : 'text-sky-500'">
                              {{ notification.type === 'card_created' ? 'add_card' : notification.type === 'card_assigned' ? 'assignment' : 'chat_bubble' }}
                            </span>
                            <span class="text-sm text-surface-700 dark:text-surface-300">
                              {{ notification.message || notification.data.card_title || 'Task' }}
                            </span>
                          </div>
                          <button 
                            @click.stop="handleNotificationClick(notification)"
                            class="mt-2 text-xs text-primary-500 hover:text-primary-600 font-medium flex items-center gap-1"
                          >
                            <span class="material-symbols-rounded text-sm">open_in_new</span>
                            {{ $t('notificationPanel.viewBoard') }}
                          </button>
                        </div>
                      </template>
                      
                      <!-- Task completed details -->
                      <template v-else-if="notification.type === 'task_completed' && notification.data">
                        <div class="mt-2 p-3 bg-surface-100 dark:bg-surface-700/50 rounded-lg">
                          <div class="flex items-center gap-2">
                            <span class="material-symbols-rounded text-sm text-emerald-500">task_alt</span>
                            <span class="text-sm font-medium text-surface-700 dark:text-surface-300">
                              {{ notification.data.card_title || 'Task' }}
                            </span>
                          </div>
                          <p v-if="notification.data.board_name" class="text-xs text-surface-500 mt-1">
                            {{ $t('notificationPanel.board') }}: {{ notification.data.board_name }}
                          </p>
                          <button 
                            @click.stop="handleNotificationClick(notification)"
                            class="mt-2 text-xs text-primary-500 hover:text-primary-600 font-medium flex items-center gap-1"
                          >
                            <span class="material-symbols-rounded text-sm">open_in_new</span>
                            {{ $t('notificationPanel.viewBoard') }}
                          </button>
                        </div>
                      </template>
                      
                      <!-- Thread reply details -->
                      <template v-else-if="notification.type === 'thread_reply' && notification.data">
                        <div class="mt-2 p-3 bg-surface-100 dark:bg-surface-700/50 rounded-lg">
                          <div class="flex items-center gap-2">
                            <span class="material-symbols-rounded text-sm text-indigo-500">forum</span>
                            <span class="text-sm text-surface-700 dark:text-surface-300">
                              {{ notification.data.sender_name || 'Someone' }}
                            </span>
                          </div>
                          <p v-if="notification.data.reply_preview" class="text-xs text-surface-500 mt-1 truncate">
                            "{{ notification.data.reply_preview }}"
                          </p>
                          <button 
                            @click.stop="handleNotificationClick(notification)"
                            class="mt-2 text-xs text-primary-500 hover:text-primary-600 font-medium flex items-center gap-1"
                          >
                            <span class="material-symbols-rounded text-sm">open_in_new</span>
                            {{ $t('notificationPanel.openThread') }}
                          </button>
                        </div>
                      </template>
                      
                      <!-- Missed call details -->
                      <template v-else-if="notification.type === 'missed_call' && notification.data">
                        <div class="mt-2 p-3 bg-surface-100 dark:bg-surface-700/50 rounded-lg">
                          <div class="flex items-center gap-2">
                            <span class="material-symbols-rounded text-sm text-red-500">phone_missed</span>
                            <span class="text-sm text-surface-700 dark:text-surface-300">
                              {{ notification.data.caller_name || notification.data.caller_email || 'Unknown' }}
                            </span>
                            <span v-if="notification.data.call_type" class="text-xs px-2 py-0.5 rounded-full bg-red-100 dark:bg-red-500/20 text-red-600 dark:text-red-400">
                              {{ notification.data.call_type === 'video' ? $t('notificationPanel.video') : $t('notificationPanel.voice') }}
                            </span>
                          </div>
                          <button 
                            v-if="chatEnabled"
                            @click.stop="handleNotificationClick(notification)"
                            class="mt-2 text-xs text-primary-500 hover:text-primary-600 font-medium flex items-center gap-1"
                          >
                            <span class="material-symbols-rounded text-sm">open_in_new</span>
                            {{ $t('notificationPanel.openChat') }}
                          </button>
                        </div>
                      </template>
                      
                      <!-- Automation notification details -->
                      <template v-else-if="notification.type === 'automation' && notification.data">
                        <div class="mt-2 p-3 bg-surface-100 dark:bg-surface-700/50 rounded-lg">
                          <div class="flex items-center gap-2">
                            <span class="material-symbols-rounded text-sm text-amber-500">bolt</span>
                            <span class="text-sm text-surface-700 dark:text-surface-300">
                              {{ notification.message }}
                            </span>
                          </div>
                          <div v-if="notification.data.target_name" class="flex items-center gap-2 mt-1.5">
                            <span class="material-symbols-rounded text-xs text-surface-400">label</span>
                            <span class="text-xs text-surface-500">
                              {{ notification.data.target_type }}: {{ notification.data.target_name }}
                            </span>
                          </div>
                          <button 
                            @click.stop="handleNotificationClick(notification)"
                            class="mt-2 text-xs text-primary-500 hover:text-primary-600 font-medium flex items-center gap-1"
                          >
                            <span class="material-symbols-rounded text-sm">open_in_new</span>
                            {{ $t('notificationPanel.view') }}
                          </button>
                        </div>
                      </template>
                      
                      <!-- Drive share details -->
                      <template v-else-if="notification.type === 'drive_share' && notification.data">
                        <div class="mt-2 p-3 bg-surface-100 dark:bg-surface-700/50 rounded-lg">
                          <div class="flex items-center gap-2">
                            <span class="material-symbols-rounded text-sm text-orange-500">
                              {{ notification.data.embed_type === 'drive_folder' ? 'folder' : 'description' }}
                            </span>
                            <span class="text-sm font-medium text-surface-700 dark:text-surface-300 truncate">
                              {{ notification.data.item_name || 'File' }}
                            </span>
                          </div>
                          <p v-if="notification.data.sender_name" class="text-xs text-surface-500 mt-1">
                            {{ $t('notificationPanel.sharedBy') }} {{ notification.data.sender_name }}
                          </p>
                          <button 
                            @click.stop="handleNotificationClick(notification)"
                            class="mt-2 text-xs text-primary-500 hover:text-primary-600 font-medium flex items-center gap-1"
                          >
                            <span class="material-symbols-rounded text-sm">open_in_new</span>
                            {{ $t('notificationPanel.openDrive') }}
                          </button>
                        </div>
                      </template>
                      
                      <!-- Project Hub notification details -->
                      <template v-else-if="notification.type?.startsWith('ph_') && notification.data">
                        <div class="mt-2 p-3 bg-surface-100 dark:bg-surface-700/50 rounded-lg">
                          <div class="flex items-center gap-2">
                            <span :class="['material-symbols-rounded text-sm', getNotificationColor(notification.type)]">
                              {{ getNotificationIcon(notification.type) }}
                            </span>
                            <span class="text-sm text-surface-700 dark:text-surface-300">
                              {{ notification.message }}
                            </span>
                          </div>
                          <button 
                            @click.stop="handleNotificationClick(notification)"
                            class="mt-2 text-xs text-primary-500 hover:text-primary-600 font-medium flex items-center gap-1"
                          >
                            <span class="material-symbols-rounded text-sm">open_in_new</span>
                            Open in Project Hub
                          </button>
                        </div>
                      </template>

                      <!-- Other notification types -->
                      <p v-else class="text-sm text-surface-500 mt-0.5">
                        {{ notification.message }}
                      </p>
                    </div>
                  </div>
                </div>
              </div>
              </Transition>
            </div>
          </div>
        </div>
        
        <!-- Footer info -->
        <div class="px-4 py-3 border-t border-surface-200 dark:border-surface-700 bg-surface-50 dark:bg-surface-850">
          <!-- Email tab: tinted lightbulb + 2-line text + View pinned toggle -->
          <div v-if="activeTab === 'email'" class="flex items-center gap-3">
            <div class="w-8 h-8 rounded-full bg-surface-200 dark:bg-surface-700 flex items-center justify-center flex-shrink-0">
              <span class="material-symbols-rounded text-base text-surface-500 dark:text-surface-400">lightbulb</span>
            </div>
            <div class="flex-1 min-w-0">
              <p class="text-xs text-surface-600 dark:text-surface-300 leading-snug">
                {{ $t('notificationPanel.readReceiptsPersist') }}
              </p>
              <p class="text-xs text-surface-500 dark:text-surface-400 leading-snug">
                {{ $t('notificationPanel.pinForQuickAccess') }}
              </p>
            </div>
            <button
              @click="pinnedOnly = !pinnedOnly"
              :class="[
                'inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full text-xs font-medium transition-colors border whitespace-nowrap flex-shrink-0',
                pinnedOnly
                  ? 'bg-green-500 text-white border-green-500 hover:bg-green-600'
                  : 'bg-white dark:bg-surface-800 text-green-600 dark:text-green-400 border-green-500/50 hover:bg-green-50 dark:hover:bg-green-500/10'
              ]"
              :title="pinnedOnly ? $t('notificationPanel.showingPinned') : $t('notificationPanel.viewPinned')"
            >
              <span class="material-symbols-rounded text-sm">push_pin</span>
              {{ pinnedOnly ? $t('notificationPanel.showingPinned') : $t('notificationPanel.viewPinned') }}
            </button>
          </div>
          <!-- Other tabs: original centered info line -->
          <p v-else class="text-xs text-surface-400 text-center">
            <span class="material-symbols-rounded text-sm align-text-bottom mr-1">info</span>
            {{ activeTab === 'campaigns'
              ? 'Campaign tracking notifications are shown separately from regular email tracking'
              : $t('notificationPanel.generalInfo') }}
          </p>
        </div>
      </div>
    </Transition>
  </Teleport>

  <!-- Clear All Confirmation Modal -->
  <ConfirmModal
    :show="showClearConfirm"
    :title="clearConfirmTitle"
    :message="clearConfirmMessage"
    :confirm-text="$t('notificationPanel.clearAllConfirm')"
    :cancel-text="$t('notificationPanel.keep')"
    :danger="true"
    :loading="clearingAll"
    @confirm="confirmClearAll"
    @cancel="showClearConfirm = false"
  />
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

.slide-right-enter-active,
.slide-right-leave-active {
  transition: transform 0.3s ease;
}
.slide-right-enter-from,
.slide-right-leave-to {
  transform: translateX(100%);
}

/* Make buttons visible on hover */
.divide-y > div:hover button {
  opacity: 1;
}

/* Collapse animation */
.collapse-enter-active,
.collapse-leave-active {
  transition: all 0.25s ease;
  overflow: hidden;
}

.collapse-enter-from,
.collapse-leave-to {
  opacity: 0;
  max-height: 0;
}

.collapse-enter-to,
.collapse-leave-from {
  opacity: 1;
  max-height: 2000px; /* Large enough for content */
}

@media (max-width: 640px) {
  .mobile-panel-top {
    top: 0;
    padding-top: 4rem;
  }
}
</style>
