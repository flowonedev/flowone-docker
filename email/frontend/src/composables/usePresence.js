/**
 * usePresence - Activity detection and presence management
 * 
 * Tracks user activity (mouse, keyboard, focus) and sends presence
 * heartbeats to keep status as "active". If user is idle for too long,
 * they'll be marked as "away" by the server.
 */

import { ref, onMounted, onUnmounted } from 'vue'
import { useMailSync } from '@/services/mailSyncSocket'
import { useColleaguesStore } from '@/addons/team/stores/colleagues'
import { useRouter } from 'vue-router'

// Activity timeout - how long until we consider user inactive (90 seconds)
// Server marks as "away" after 2 minutes, so we stop heartbeats before that
const ACTIVITY_TIMEOUT = 90 * 1000

// Heartbeat interval - how often to send heartbeat if active (every 30 seconds)
const HEARTBEAT_INTERVAL = 30 * 1000

// Debounce for activity events (don't spam on every mouse move)
const ACTIVITY_DEBOUNCE = 5000

export function usePresence() {
  const mailSync = useMailSync()
  const colleaguesStore = useColleaguesStore()
  
  const isActive = ref(true)
  const lastActivity = ref(Date.now())
  const manualStatus = ref(null) // If user manually sets status
  const currentView = ref(null)
  
  let heartbeatTimer = null
  let activityDebounceTimer = null
  let isInitialized = false
  let routerUnregister = null
  
  /**
   * Record user activity
   */
  function recordActivity() {
    // Debounce to avoid excessive calls
    if (activityDebounceTimer) return
    
    activityDebounceTimer = setTimeout(() => {
      activityDebounceTimer = null
    }, ACTIVITY_DEBOUNCE)
    
    lastActivity.value = Date.now()
    
    // If user was considered inactive, mark as active now
    if (!isActive.value && !manualStatus.value) {
      isActive.value = true
      mailSync.sendPresenceHeartbeat()
    }
  }
  
  /**
   * Check if user is still active
   */
  function checkActivity() {
    const now = Date.now()
    const timeSinceActivity = now - lastActivity.value
    
    if (timeSinceActivity > ACTIVITY_TIMEOUT) {
      isActive.value = false
    } else {
      isActive.value = true
      if (!manualStatus.value) {
        mailSync.sendPresenceHeartbeat(currentView.value)
      }
    }
  }

  /**
   * Compute a human-readable label for the current route
   */
  function computeCurrentView(route) {
    const name = route.name
    const path = route.path

    if (name === 'mailbox' || path.startsWith('/mailbox')) {
      const folder = route.params?.folder || 'INBOX'
      return `Email > ${folder}`
    }
    if (name === 'board' || name === 'boards') return 'Boards'
    if (name === 'workload') return 'Workload Planner'
    if (name === 'chat') return 'Chat'
    if (name === 'calendar') return 'Calendar'
    if (path.startsWith('/drive')) return 'Drive'
    if (path.startsWith('/contacts') || path.startsWith('/clients')) return 'Contacts'
    if (name === 'mood') return 'Mood Boards'
    if (name === 'time') return 'Time Tracker'
    if (name === 'financials') return 'Financials'
    if (name === 'team') return 'Team'
    if (path.startsWith('/settings')) return 'Settings'
    if (path.startsWith('/my-work')) return 'My Work'

    return name || path || 'Unknown'
  }
  
  /**
   * Set manual status (active, away, do_not_disturb)
   */
  function setStatus(status) {
    if (['active', 'away', 'do_not_disturb'].includes(status)) {
      manualStatus.value = status
      colleaguesStore.setMyStatus(status)
    } else if (status === null || status === 'auto') {
      // Clear manual status, go back to auto
      manualStatus.value = null
      if (isActive.value) {
        colleaguesStore.setMyStatus('active')
      }
    }
  }
  
  /**
   * Initialize presence tracking
   */
  function init() {
    if (isInitialized) return
    isInitialized = true
    
    // Subscribe to receive presence updates from other users
    colleaguesStore.subscribeToPresence()
    
    // Listen for activity events
    const events = ['mousedown', 'mousemove', 'keydown', 'scroll', 'touchstart', 'click']
    
    events.forEach(event => {
      window.addEventListener(event, recordActivity, { passive: true })
    })
    
    // Listen for visibility change
    document.addEventListener('visibilitychange', handleVisibilityChange)
    
    // Listen for focus/blur
    window.addEventListener('focus', handleFocus)
    window.addEventListener('blur', handleBlur)
    
    // Track route changes for currentView
    try {
      const router = useRouter()
      routerUnregister = router.afterEach((to) => {
        currentView.value = computeCurrentView(to)
      })
      // Set initial view
      if (router.currentRoute?.value) {
        currentView.value = computeCurrentView(router.currentRoute.value)
      }
    } catch {
      // Router may not be available in all contexts
    }
    
    // Start heartbeat timer
    heartbeatTimer = setInterval(checkActivity, HEARTBEAT_INTERVAL)
    
    // Initial heartbeat
    mailSync.sendPresenceHeartbeat(currentView.value)
  }
  
  /**
   * Handle page visibility change
   */
  function handleVisibilityChange() {
    if (document.visibilityState === 'visible') {
      recordActivity()
    }
  }
  
  /**
   * Handle window focus
   */
  function handleFocus() {
    recordActivity()
  }
  
  /**
   * Handle window blur - don't immediately mark as inactive,
   * just stop recording activity
   */
  function handleBlur() {
    // Activity will naturally time out if user doesn't come back
  }
  
  /**
   * Cleanup
   */
  function cleanup() {
    if (!isInitialized) return
    isInitialized = false
    
    const events = ['mousedown', 'mousemove', 'keydown', 'scroll', 'touchstart', 'click']
    
    events.forEach(event => {
      window.removeEventListener(event, recordActivity)
    })
    
    document.removeEventListener('visibilitychange', handleVisibilityChange)
    window.removeEventListener('focus', handleFocus)
    window.removeEventListener('blur', handleBlur)
    
    if (routerUnregister) {
      routerUnregister()
      routerUnregister = null
    }
    
    if (heartbeatTimer) {
      clearInterval(heartbeatTimer)
      heartbeatTimer = null
    }
    
    if (activityDebounceTimer) {
      clearTimeout(activityDebounceTimer)
      activityDebounceTimer = null
    }
  }
  
  return {
    isActive,
    lastActivity,
    manualStatus,
    currentView,
    setStatus,
    init,
    cleanup,
  }
}

/**
 * Auto-initialize presence tracking when composable is used in a component
 */
export function usePresenceAutoInit() {
  const presence = usePresence()
  
  onMounted(() => {
    presence.init()
  })
  
  onUnmounted(() => {
    presence.cleanup()
  })
  
  return presence
}

