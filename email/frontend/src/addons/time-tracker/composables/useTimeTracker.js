import { ref, onMounted, onUnmounted, watch } from 'vue'
import { useRoute } from 'vue-router'
import { useStatisticsStore } from '@/stores/statistics'
import { useMailboxStore } from '@/stores/mailbox'
import { useAddons } from '@/composables/useAddons'

/**
 * Time tracking composable for tracking time spent in different sections
 * 
 * Features:
 * - Sends accumulated time to backend every 3 minutes
 * - Persists pending entries to localStorage for crash recovery
 * - Pauses tracking when user is idle (no mouse/keyboard for 2 minutes)
 * - Uses sendBeacon for reliable sync on page close
 * - Fully disabled when time_tracker addon is off
 */
export function useTimeTracker() {
  const { timeTrackerEnabled } = useAddons()
  const route = useRoute()
  const statisticsStore = useStatisticsStore()
  const mailboxStore = useMailboxStore()
  
  // State
  const currentSection = ref(null)
  const currentFolder = ref(null)
  const sectionStartTime = ref(null)
  const accumulatedTime = ref({}) // { section: { folder: seconds } }
  const isPageVisible = ref(true)
  const isIdle = ref(false)
  
  // Constants
  const SYNC_INTERVAL = 3 * 60 * 1000 // 3 minutes (reduced from 5)
  const IDLE_THRESHOLD = 2 * 60 * 1000 // 2 minutes of inactivity
  const STORAGE_KEY = 'mailflow_time_tracker'
  
  // Timers
  let syncIntervalId = null
  let idleCheckIntervalId = null
  let lastActivityTime = Date.now()
  
  /**
   * Determine section from route
   */
  function getSectionFromRoute(routeName) {
    if (!routeName) return null
    
    const routeMap = {
      'mailbox': 'email',
      'mailbox-folder': 'email',
      'mailbox-email': 'email',
      'email': 'email',
      'inbox': 'email',
      'mailing-lists': 'email',
      'campaigns': 'email',
      'calendar': 'calendar',
      'drive': 'drive',
      'drive-folder': 'drive',
      'drive-document': 'drive',
      'drive-presentation': 'drive',
      'settings': 'settings',
      'todo': 'todo',
      'my-work': 'todo',
      'mood': 'mood',
      'mood-board': 'mood',
      'boards': 'boards',
      'board': 'boards',
      'board-folder': 'boards',
      'workload': 'boards',
      'ph-director': 'boards',
      'ph-settings': 'boards',
      'time': 'time_tracker',
      'clients': 'clients',
      'clients-overview': 'clients',
      'client': 'clients',
      'chat': 'chat',
      'chat-invite': 'chat',
      'team': 'team',
      'crm': 'crm',
      'crm-pipeline': 'crm',
      'crm-executive': 'crm',
      'crm-dashboard': 'crm',
      'financials': 'financials',
      'automation-hub': 'automation',
    }
    
    if (routeMap[routeName]) {
      return routeMap[routeName]
    }
    
    const lowerName = routeName.toLowerCase()
    for (const [key, section] of Object.entries(routeMap)) {
      if (lowerName.includes(key)) {
        return section
      }
    }
    
    return 'other'
  }
  
  // ===== localStorage Persistence =====
  
  /**
   * Load pending entries from localStorage
   */
  function loadFromStorage() {
    try {
      const stored = localStorage.getItem(STORAGE_KEY)
      if (stored) {
        const data = JSON.parse(stored)
        // Merge with current accumulated time
        if (data.accumulatedTime && typeof data.accumulatedTime === 'object') {
          for (const [section, folders] of Object.entries(data.accumulatedTime)) {
            if (!accumulatedTime.value[section]) {
              accumulatedTime.value[section] = {}
            }
            for (const [folder, seconds] of Object.entries(folders)) {
              if (!accumulatedTime.value[section][folder]) {
                accumulatedTime.value[section][folder] = 0
              }
              accumulatedTime.value[section][folder] += seconds
            }
          }
        }
        // Clear storage after loading
        localStorage.removeItem(STORAGE_KEY)
      }
    } catch (e) {
      console.error('[TimeTracker] Failed to load from localStorage:', e)
    }
  }
  
  /**
   * Save current state to localStorage
   */
  function saveToStorage() {
    try {
      // Only save if there's data to save
      const hasData = Object.keys(accumulatedTime.value).length > 0
      if (hasData) {
        localStorage.setItem(STORAGE_KEY, JSON.stringify({
          accumulatedTime: accumulatedTime.value,
          savedAt: Date.now()
        }))
      }
    } catch (e) {
      console.error('[TimeTracker] Failed to save to localStorage:', e)
    }
  }
  
  /**
   * Clear localStorage
   */
  function clearStorage() {
    try {
      localStorage.removeItem(STORAGE_KEY)
    } catch (e) {
      // Ignore errors
    }
  }
  
  // ===== Idle Detection =====
  
  /**
   * Handle user activity (mouse/keyboard)
   */
  function handleUserActivity() {
    lastActivityTime = Date.now()
    
    // If was idle, resume tracking
    if (isIdle.value) {
      isIdle.value = false
      // Resume tracking current section
      if (currentSection.value && !sectionStartTime.value) {
        sectionStartTime.value = Date.now()
      }
    }
  }
  
  /**
   * Check if user is idle
   */
  function checkIdle() {
    const now = Date.now()
    const timeSinceActivity = now - lastActivityTime
    
    if (timeSinceActivity >= IDLE_THRESHOLD && !isIdle.value) {
      // User became idle - stop tracking
      isIdle.value = true
      stopTracking()
      saveToStorage() // Persist before going idle
    }
  }
  
  // ===== Core Tracking =====
  
  /**
   * Start tracking a new section
   */
  function startTracking(section, folder = null) {
    // Stop tracking previous section
    stopTracking()
    
    if (!section) return
    
    // Don't start if idle
    if (isIdle.value) return
    
    currentSection.value = section
    currentFolder.value = folder
    sectionStartTime.value = Date.now()
  }
  
  /**
   * Stop tracking and accumulate time
   */
  function stopTracking() {
    if (!currentSection.value || !sectionStartTime.value) return
    
    const elapsed = Math.floor((Date.now() - sectionStartTime.value) / 1000)
    
    if (elapsed > 0) {
      const section = currentSection.value
      const folder = currentFolder.value || '_none'
      
      if (!accumulatedTime.value[section]) {
        accumulatedTime.value[section] = {}
      }
      
      if (!accumulatedTime.value[section][folder]) {
        accumulatedTime.value[section][folder] = 0
      }
      
      accumulatedTime.value[section][folder] += elapsed
      
      // Persist to localStorage after accumulating
      saveToStorage()
    }
    
    sectionStartTime.value = null
  }
  
  /**
   * Sync accumulated time to backend
   */
  async function syncToBackend() {
    // Skip sync if idle (nothing to sync)
    if (isIdle.value && Object.keys(accumulatedTime.value).length === 0) {
      return
    }
    
    // Pause current tracking to get accurate time
    const wasTracking = currentSection.value
    const wasFolder = currentFolder.value
    stopTracking()
    
    // Send accumulated time
    let syncSuccess = true
    for (const [section, folders] of Object.entries(accumulatedTime.value)) {
      for (const [folder, seconds] of Object.entries(folders)) {
        if (seconds >= 1) {
          const ok = await statisticsStore.trackTime(
            section, 
            seconds, 
            folder !== '_none' ? folder : null
          )
          if (!ok) {
            syncSuccess = false
          }
        }
      }
    }
    
    // Only clear if sync was successful
    if (syncSuccess) {
      accumulatedTime.value = {}
      clearStorage()
    }
    
    // Resume tracking if was tracking and not idle
    if (wasTracking && !isIdle.value) {
      startTracking(wasTracking, wasFolder)
    }
  }
  
  /**
   * Handle visibility change (tab switch)
   */
  function handleVisibilityChange() {
    isPageVisible.value = !document.hidden
    
    if (document.hidden) {
      // Tab became hidden - stop tracking
      stopTracking()
    } else {
      // Tab became visible - resume tracking if not idle
      if (currentSection.value && !isIdle.value) {
        sectionStartTime.value = Date.now()
      }
      // Reset activity time on tab focus
      lastActivityTime = Date.now()
    }
  }
  
  /**
   * Handle before unload (page close)
   */
  function handleBeforeUnload() {
    stopTracking()
    
    const entries = []
    for (const [section, folders] of Object.entries(accumulatedTime.value)) {
      for (const [folder, seconds] of Object.entries(folders)) {
        if (seconds >= 1) {
          entries.push({
            section,
            duration_seconds: seconds,
            folder: folder !== '_none' ? folder : null
          })
        }
      }
    }
    
    if (entries.length === 0) return

    const token = sessionStorage.getItem('webmail_token') || localStorage.getItem('webmail_token')
    const payload = JSON.stringify(entries)
    let sent = false

    try {
      sent = !!fetch('/api/statistics/track-time-batch', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          ...(token ? { 'Authorization': `Bearer ${token}` } : {}),
        },
        body: payload,
        keepalive: true,
      })
    } catch {
      // fetch keepalive failed, try sendBeacon (no auth, will be picked up from localStorage next session)
      if (navigator.sendBeacon) {
        const blob = new Blob([payload], { type: 'application/json' })
        sent = navigator.sendBeacon('/api/statistics/track-time-batch', blob)
      }
    }

    if (sent) {
      clearStorage()
    }
  }
  
  // Track whether infrastructure (intervals, listeners) is active
  let infrastructureActive = false

  function setupInfrastructure() {
    if (infrastructureActive) return
    infrastructureActive = true

    loadFromStorage()

    const section = getSectionFromRoute(route.name)
    const folder = mailboxStore.currentFolder
    startTracking(section, section === 'email' ? folder : null)

    syncIntervalId = setInterval(syncToBackend, SYNC_INTERVAL)
    idleCheckIntervalId = setInterval(checkIdle, 30000)

    document.addEventListener('visibilitychange', handleVisibilityChange)
    window.addEventListener('beforeunload', handleBeforeUnload)
    document.addEventListener('mousemove', handleUserActivity, { passive: true })
    document.addEventListener('mousedown', handleUserActivity, { passive: true })
    document.addEventListener('keydown', handleUserActivity, { passive: true })
    document.addEventListener('scroll', handleUserActivity, { passive: true })
    document.addEventListener('touchstart', handleUserActivity, { passive: true })
  }

  function teardownInfrastructure() {
    if (!infrastructureActive) return
    infrastructureActive = false

    syncToBackend()

    if (syncIntervalId) { clearInterval(syncIntervalId); syncIntervalId = null }
    if (idleCheckIntervalId) { clearInterval(idleCheckIntervalId); idleCheckIntervalId = null }

    document.removeEventListener('visibilitychange', handleVisibilityChange)
    window.removeEventListener('beforeunload', handleBeforeUnload)
    document.removeEventListener('mousemove', handleUserActivity)
    document.removeEventListener('mousedown', handleUserActivity)
    document.removeEventListener('keydown', handleUserActivity)
    document.removeEventListener('scroll', handleUserActivity)
    document.removeEventListener('touchstart', handleUserActivity)
  }

  // Watch route changes (only if addon is enabled)
  watch(() => route.name, (newRoute) => {
    if (!timeTrackerEnabled.value) return
    const section = getSectionFromRoute(newRoute)
    const folder = mailboxStore.currentFolder
    startTracking(section, section === 'email' ? folder : null)
  }, { immediate: true })
  
  // Watch folder changes (for email section)
  watch(() => mailboxStore.currentFolder, (newFolder) => {
    if (!timeTrackerEnabled.value) return
    if (currentSection.value === 'email') {
      stopTracking()
      startTracking('email', newFolder)
    }
  })

  // React to addon becoming enabled/disabled (handles bootstrap race condition)
  let isMounted = false

  watch(timeTrackerEnabled, (enabled) => {
    if (!isMounted) return
    if (enabled) {
      setupInfrastructure()
    } else {
      teardownInfrastructure()
    }
  })

  onMounted(() => {
    isMounted = true
    if (timeTrackerEnabled.value) {
      setupInfrastructure()
    }
  })
  
  onUnmounted(() => {
    isMounted = false
    teardownInfrastructure()
  })
  
  return {
    currentSection,
    currentFolder,
    isPageVisible,
    isIdle,
    accumulatedTime,
    startTracking,
    stopTracking,
    syncToBackend
  }
}
