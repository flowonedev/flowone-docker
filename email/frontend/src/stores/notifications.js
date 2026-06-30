import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import api from '@/services/api'
import browserNotifications from '@/services/browserNotifications'
import { useMailSync, EventTypes } from '@/services/mailSyncSocket'
import { isDebugEnabled } from '@/utils/debug'
import { pollerShouldRun, pollerRecordResult } from '@/services/pollerBreaker'

const POLLER_ID_NOTIFICATIONS = 'notifications.safety-poll'

/**
 * Raise an OS desktop banner (Windows/macOS notification center) for a newly
 * created server-side notification. read_receipt has its own dedicated handler,
 * so this covers drive_share and other general notifications that previously
 * only landed in the in-app panel with no OS banner. Gating (permission,
 * enabled, dedupe) is handled inside browserNotifications.show().
 */
function showOsBanner(n) {
  try {
    const title = n.title || 'Notification'
    const body = n.message || ''
    let url = '/'
    if (n.type === 'drive_share') {
      url = '/drive'
    } else if (n.data && n.data.board_id && n.data.card_id) {
      url = `/boards/${n.data.board_id}?card=${n.data.card_id}`
    }
    browserNotifications.show(title, {
      body,
      tag: `notif-${n.id || Date.now()}`,
      autoClose: 10000,
      onClick: () => {
        import('@/router').then((m) => m.default && m.default.push(url)).catch(() => {})
      },
    })
  } catch (_) { /* never let a banner failure break event handling */ }
}

export const useNotificationsStore = defineStore('notifications', () => {
  const notifications = ref([])
  const loading = ref(false)
  const unreadCount = ref(0)
  const pollInterval = ref(null)
  const lastNotificationId = ref(null)
  const consecutiveErrors = ref(0)
  const maxConsecutiveErrors = 3 // Stop polling after 3 consecutive failures
  const hasConsolidated = ref(false) // Track if we've consolidated this session

  // Missed call unread count (for badges on chat widget, favicon, etc.)
  const missedCallUnreadCount = computed(() => {
    return notifications.value.filter(n => n.type === 'missed_call' && !n.is_read).length
  })

  // Grouped notifications by date
  const groupedNotifications = computed(() => {
    const groups = {}
    const today = new Date().toDateString()
    const yesterday = new Date(Date.now() - 86400000).toDateString()
    
    notifications.value.forEach(n => {
      const date = new Date(n.created_at)
      let key
      
      if (date.toDateString() === today) {
        key = 'Today'
      } else if (date.toDateString() === yesterday) {
        key = 'Yesterday'
      } else {
        key = date.toLocaleDateString('en-US', { weekday: 'long', month: 'short', day: 'numeric' })
      }
      
      if (!groups[key]) {
        groups[key] = []
      }
      groups[key].push(n)
    })
    
    return groups
  })

  async function fetchNotifications(unreadOnly = false, showBrowserAlert = false, forceConsolidate = false) {
    // Only show loading on initial fetch (when no notifications yet)
    const isInitialFetch = notifications.value.length === 0
    if (isInitialFetch) {
      loading.value = true
    }
    
    try {
      // Consolidate duplicates on first fetch of the session or if forced
      const params = { unread_only: unreadOnly }
      if (!hasConsolidated.value || forceConsolidate) {
        params.consolidate = true
        hasConsolidated.value = true
      }
      
      const response = await api.get('/notifications', { params })
      if (response.data.success) {
        consecutiveErrors.value = 0 // Reset on success
        const newNotifications = response.data.data.notifications
        
        // Check for new notifications and show browser alert
        if (showBrowserAlert && lastNotificationId.value && newNotifications.length > 0) {
          const newestId = newNotifications[0]?.id
          if (newestId && newestId > lastNotificationId.value) {
            // Find all new notifications
            const newOnes = newNotifications.filter(n => n.id > lastNotificationId.value && !n.is_read)
            for (const n of newOnes) {
              if (n.type === 'read_receipt') {
                browserNotifications.showReadReceipt(n.data || n)
              }
            }
          }
        }
        
        // Update last notification ID
        if (newNotifications.length > 0) {
          lastNotificationId.value = newNotifications[0].id
        }
        
        // Preserve optimistic local notifications (e.g. missed calls) that haven't
        // been synced to the server yet. Check if a matching server notification exists
        // (same type + similar data) before discarding the local one.
        const localOptimistic = notifications.value.filter(n => n._local)
        const survivingLocal = localOptimistic.filter(localN => {
          // Check if a server notification matches this local one (by type + key data)
          return !newNotifications.some(serverN => {
            if (serverN.type !== localN.type) return false
            // For missed calls, match by call_id or caller_email + close timestamp
            if (localN.type === 'missed_call') {
              const localData = localN.data || {}
              const serverData = serverN.data || {}
              if (localData.call_id && serverData.call_id) {
                return localData.call_id === serverData.call_id
              }
              // Fallback: same caller within 60 seconds
              if (localData.caller_email === serverData.caller_email) {
                const localTime = new Date(localN.created_at).getTime()
                const serverTime = new Date(serverN.created_at).getTime()
                return Math.abs(localTime - serverTime) < 60000
              }
            }
            return false
          })
        })
        
        // Merge: server notifications + any surviving local optimistic ones at the top
        const mergedNotifications = survivingLocal.length > 0
          ? [...survivingLocal, ...newNotifications]
          : newNotifications
        
        // Smart update: only replace array if data actually changed
        // This prevents flickering from unnecessary re-renders during polling
        const currentIds = notifications.value.map(n => `${n.id}-${n.is_read}-${n.pinned}`).join(',')
        const newIds = mergedNotifications.map(n => `${n.id}-${n.is_read}-${n.pinned}`).join(',')
        
        if (currentIds !== newIds) {
          notifications.value = mergedNotifications
        }
        
        unreadCount.value = response.data.data.unread_count
      }
    } catch (e) {
      consecutiveErrors.value++
      if (consecutiveErrors.value >= maxConsecutiveErrors) {
        console.warn('Notifications: Too many errors, stopping polling')
        stopPolling()
      }
    } finally {
      if (isInitialFetch) {
        loading.value = false
      }
    }
  }

  async function fetchUnreadCount() {
    try {
      const response = await api.get('/notifications/count')
      if (response.data.success) {
        consecutiveErrors.value = 0 // Reset on success
        unreadCount.value = response.data.data.unread_count
      }
    } catch (e) {
      // Silently fail - don't spam console
    }
  }

  async function markAsRead(id) {
    try {
      const response = await api.post(`/notifications/${id}/read`)
      if (response.data.success) {
        const notification = notifications.value.find(n => n.id === id)
        if (notification && !notification.is_read) {
          notification.is_read = true
          unreadCount.value = Math.max(0, unreadCount.value - 1)
        }
        return true
      }
    } catch (e) {
      console.error('Failed to mark as read:', e)
    }
    return false
  }

  async function markAllAsRead() {
    try {
      const response = await api.post('/notifications/read-all')
      if (response.data.success) {
        notifications.value.forEach(n => n.is_read = true)
        unreadCount.value = 0
        return true
      }
    } catch (e) {
      console.error('Failed to mark all as read:', e)
    }
    return false
  }

  async function togglePin(id) {
    try {
      const response = await api.post(`/notifications/${id}/pin`)
      if (response.data.success) {
        const notification = notifications.value.find(n => n.id === id)
        if (notification) {
          notification.pinned = !notification.pinned
        }
        return true
      }
    } catch (e) {
      console.error('Failed to toggle pin:', e)
      return false
    }
  }
  
  async function deleteNotification(id) {
    try {
      const response = await api.delete(`/notifications/${id}`)
      if (response.data.success) {
        const notification = notifications.value.find(n => n.id === id)
        if (notification && !notification.is_read) {
          unreadCount.value = Math.max(0, unreadCount.value - 1)
        }
        notifications.value = notifications.value.filter(n => n.id !== id)
        return true
      }
    } catch (e) {
      console.error('Failed to delete notification:', e)
    }
    return false
  }

  /**
   * Clear notifications, optionally scoped to a tab.
   * @param {('email'|'campaigns'|'general'|'all'|null)} scope - which tab to clear, null = all
   */
  async function clearAllNotifications(scope = null) {
    const emailTypes = ['read_receipt', 'link_click']
    // Predicate: returns true if the notification belongs to the scope being cleared
    const matchesScope = (n) => {
      switch (scope) {
        case 'email':
          return emailTypes.includes(n.type) && !n.campaign_id
        case 'campaigns':
          return emailTypes.includes(n.type) && !!n.campaign_id
        case 'general':
          return !emailTypes.includes(n.type)
        case null:
        case undefined:
        case '':
        case 'all':
        default:
          return true
      }
    }
    const removeMatching = () => {
      if (scope && scope !== 'all') {
        const removedUnread = notifications.value.filter(n => matchesScope(n) && !n.is_read).length
        notifications.value = notifications.value.filter(n => !matchesScope(n))
        unreadCount.value = Math.max(0, unreadCount.value - removedUnread)
      } else {
        notifications.value = []
        unreadCount.value = 0
      }
    }

    try {
      const params = (scope && scope !== 'all') ? { scope } : {}
      const response = await api.delete('/notifications', { params })
      if (response.data.success) {
        removeMatching()
        return true
      }
    } catch (e) {
      // Clear locally even if API fails to keep UI consistent
      removeMatching()
    }
    return false
  }
  
  async function consolidateNotifications() {
    // Only show loading on initial load (when no notifications yet)
    const isInitialLoad = notifications.value.length === 0
    if (isInitialLoad) {
      loading.value = true
    }
    
    try {
      const response = await api.post('/notifications/consolidate')
      if (response.data.success) {
        isDebugEnabled() && console.log('Consolidation result:', response.data.data)
        // Refresh to show consolidated results
        await fetchNotifications(false, false, false) // Don't re-consolidate
        return response.data.data
      }
    } catch (e) {
      console.error('Failed to consolidate notifications:', e)
      // Still try to fetch notifications even if consolidation fails
      await fetchNotifications(false, false, false)
    } finally {
      if (isInitialLoad) {
        loading.value = false
      }
    }
    return null
  }

  /**
   * Add a missed call notification optimistically (instant local update)
   * The server-side notification will be synced on next poll/fetch
   */
  function addMissedCallNotification(payload) {
    const localId = `local_missed_${Date.now()}`
    const callerName = payload.callerName || payload.callerEmail?.split('@')[0] || 'Unknown'
    const callTypeLabel = payload.callType === 'video' ? 'video call' : 'call'
    
    const localNotification = {
      id: localId,
      type: 'missed_call',
      title: 'Missed Call',
      message: `You missed a ${callTypeLabel} from ${callerName}`,
      data: {
        call_id: payload.callId,
        conversation_id: payload.conversationId,
        call_type: payload.callType,
        caller_email: payload.callerEmail,
        caller_name: callerName
      },
      is_read: false,
      pinned: false,
      created_at: new Date().toISOString(),
      _local: true // Flag to identify optimistic entries
    }
    
    // Add to the beginning of notifications array
    notifications.value = [localNotification, ...notifications.value]
    unreadCount.value += 1
  }

  // Prevent double WebSocket subscription
  let wsSubscribed = false
  
  /**
   * Subscribe to real-time notification events via WebSocket.
   * This replaces the need for aggressive polling - the server pushes
   * NOTIFICATION_CREATED events whenever a notification is persisted.
   */
  function subscribeToEvents() {
    if (wsSubscribed) return
    wsSubscribed = true
    const mailSync = useMailSync()
    mailSync.on(EventTypes.NOTIFICATION_CREATED, handleNotificationCreated)
  }
  
  /**
   * Handle a NOTIFICATION_CREATED event from the server.
   * Adds the notification directly to the local store without polling.
   */
  function handleNotificationCreated(payload) {
    if (!payload) return

    const createdAt = payload.created_at || new Date().toISOString()
    const newNotif = {
      id: payload.id,
      type: payload.type,
      title: payload.title,
      message: payload.message,
      data: payload.data || {},
      is_read: payload.is_read || false,
      pinned: false,
      created_at: createdAt,
      last_read_at: payload.last_read_at || createdAt,
    }

    const existingIdx = notifications.value.findIndex(n => n.id === newNotif.id)
    if (existingIdx >= 0) {
      const prev = notifications.value[existingIdx]
      if (prev.is_read) {
        unreadCount.value += 1
      }
      notifications.value[existingIdx] = {
        ...prev,
        title: newNotif.title,
        message: newNotif.message,
        data: newNotif.data,
        created_at: newNotif.created_at,
        last_read_at: payload.last_read_at ?? payload.created_at ?? prev.last_read_at,
        is_read: false
      }
      if (newNotif.id > (lastNotificationId.value || 0)) {
        lastNotificationId.value = newNotif.id
      }
      if (newNotif.type === 'read_receipt') {
        browserNotifications.showReadReceipt(newNotif.data || newNotif)
      }
      isDebugEnabled() && console.log(`[Notifications] Refreshed notification id=${newNotif.id} (is_update=${payload.is_update === true})`)
      return
    }

    // Check if a matching local optimistic notification exists and replace it
    const localIndex = notifications.value.findIndex(n => {
      if (!n._local || n.type !== newNotif.type) return false
      if (n.type === 'missed_call') {
        const localData = n.data || {}
        const serverData = newNotif.data || {}
        if (localData.call_id && serverData.call_id) {
          return localData.call_id === serverData.call_id
        }
      }
      return false
    })
    
    let isNewlyAdded = false
    if (localIndex >= 0) {
      // Replace optimistic notification with server version
      notifications.value[localIndex] = newNotif
    } else {
      // Add to the beginning
      notifications.value = [newNotif, ...notifications.value]
      unreadCount.value += 1
      isNewlyAdded = true
    }
    
    // Update lastNotificationId
    if (newNotif.id > (lastNotificationId.value || 0)) {
      lastNotificationId.value = newNotif.id
    }
    
    // Show an OS desktop banner for the new notification. read_receipt keeps its
    // dedicated handler; everything else (drive_share, board notifications, ...)
    // gets a generic banner so it actually surfaces in the notification center.
    if (newNotif.type === 'read_receipt') {
      browserNotifications.showReadReceipt(newNotif.data || newNotif)
    } else if (isNewlyAdded) {
      showOsBanner(newNotif)
    }
    
    isDebugEnabled() && console.log(`[Notifications] Received real-time notification: ${newNotif.type} (id: ${newNotif.id})`)
  }

  // Phase 4: notifications are pushed via the mail-sync WebSocket (the
  // notification.new event was already in the protocol vocabulary). The
  // periodic HTTP fetch is now a 30-minute safety net only, used to
  // recover state after a long WS outage. Previously this ran at the
  // configured interval floored to 5 minutes.
  function startPolling(intervalMs = 10000) {
    stopPolling()
    subscribeToEvents()
    if (notifications.value.length === 0 && !lastNotificationId.value) {
      fetchNotifications(false, false)
    }
    const SAFETY_NET_MS = 30 * 60 * 1000
    const safetyInterval = Math.max(Number(intervalMs) || 0, SAFETY_NET_MS)
    pollInterval.value = setInterval(() => {
      if (!pollerShouldRun(POLLER_ID_NOTIFICATIONS)) return
      Promise.resolve()
        .then(() => fetchUnreadCount())
        .then(() => fetchNotifications(false, true))
        .then(() => pollerRecordResult(POLLER_ID_NOTIFICATIONS, null))
        .catch((e) => pollerRecordResult(POLLER_ID_NOTIFICATIONS, e))
    }, safetyInterval)
  }

  function stopPolling() {
    if (pollInterval.value) {
      clearInterval(pollInterval.value)
      pollInterval.value = null
    }
  }

  // Panel visibility (global, works across all views)
  const panelOpen = ref(false)

  function openPanel() {
    panelOpen.value = true
  }

  function closePanel() {
    panelOpen.value = false
  }

  function togglePanel() {
    panelOpen.value = !panelOpen.value
  }

  function hydrateFromBootstrap(data) {
    if (data.notifications) {
      notifications.value = data.notifications
      if (data.notifications.length > 0) {
        lastNotificationId.value = data.notifications[0].id
      }
    }
    if (data.unread_count !== undefined) {
      unreadCount.value = data.unread_count
    }
    hasConsolidated.value = true
  }

  return {
    notifications,
    loading,
    unreadCount,
    missedCallUnreadCount,
    panelOpen,
    groupedNotifications,
    fetchNotifications,
    fetchUnreadCount,
    markAsRead,
    markAllAsRead,
    togglePin,
    deleteNotification,
    clearAllNotifications,
    consolidateNotifications,
    addMissedCallNotification,
    subscribeToEvents,
    startPolling,
    stopPolling,
    openPanel,
    closePanel,
    togglePanel,
    hydrateFromBootstrap,
  }
})

