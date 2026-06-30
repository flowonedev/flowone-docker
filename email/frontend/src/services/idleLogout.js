/**
 * Idle Auto-Logout Service
 * 
 * Monitors user activity (mouse, keyboard, touch, scroll) and auto-logs out
 * after a configurable inactivity timeout. Shows a warning modal before logout.
 * 
 * Default: 30 min idle => warning => 60s countdown => logout
 * Configurable in Settings > Security
 */

import { ref, watch } from 'vue'

// Reactive state
const idleTimeoutMinutes = ref(parseInt(localStorage.getItem('idle_timeout_minutes') || '30', 10))
const isWarningVisible = ref(false)
const countdownSeconds = ref(60)
const isEnabled = ref(localStorage.getItem('idle_logout_enabled') !== 'false') // enabled by default

// Internal state
let lastActivity = Date.now()
let idleCheckInterval = null
let countdownInterval = null
let _router = null // Store router reference for navigation (used by Desktop/Electron)
const WARNING_COUNTDOWN = 60 // seconds before final logout after warning
const ACTIVITY_EVENTS = ['mousedown', 'mousemove', 'keydown', 'touchstart', 'scroll', 'click']

/**
 * Set the router instance for navigation
 * Must be called during app setup (from App.vue or main.js)
 * On Desktop, uses router.push instead of window.location.href for Electron compatibility
 */
function setRouter(router) {
  _router = router
}

/**
 * Record user activity (resets idle timer)
 */
function recordActivity() {
  lastActivity = Date.now()
  
  // If warning was showing, dismiss it
  if (isWarningVisible.value) {
    isWarningVisible.value = false
    clearCountdown()
  }
}

/**
 * Start the idle monitoring
 */
function startIdleMonitoring() {
  if (!isEnabled.value || idleTimeoutMinutes.value <= 0) return
  
  stopIdleMonitoring()
  
  // Record initial activity
  lastActivity = Date.now()
  
  // Listen for user activity
  ACTIVITY_EVENTS.forEach(event => {
    document.addEventListener(event, recordActivity, { passive: true })
  })
  
  // Check idle status every 15 seconds
  idleCheckInterval = setInterval(checkIdleStatus, 15000)
}

/**
 * Stop idle monitoring (cleanup)
 */
function stopIdleMonitoring() {
  ACTIVITY_EVENTS.forEach(event => {
    document.removeEventListener(event, recordActivity)
  })
  
  if (idleCheckInterval) {
    clearInterval(idleCheckInterval)
    idleCheckInterval = null
  }
  
  clearCountdown()
  isWarningVisible.value = false
}

/**
 * Check if user has been idle too long
 */
function checkIdleStatus() {
  if (!isEnabled.value || idleTimeoutMinutes.value <= 0) return
  
  const idleMs = Date.now() - lastActivity
  const timeoutMs = idleTimeoutMinutes.value * 60 * 1000
  
  if (idleMs >= timeoutMs && !isWarningVisible.value) {
    showWarning()
  }
}

/**
 * Show the warning modal with countdown
 */
function showWarning() {
  isWarningVisible.value = true
  countdownSeconds.value = WARNING_COUNTDOWN
  
  countdownInterval = setInterval(() => {
    countdownSeconds.value--
    
    if (countdownSeconds.value <= 0) {
      clearCountdown()
      performLogout()
    }
  }, 1000)
}

/**
 * Clear countdown timer
 */
function clearCountdown() {
  if (countdownInterval) {
    clearInterval(countdownInterval)
    countdownInterval = null
  }
}

/**
 * Perform the actual logout
 * Uses Vue Router when available (Desktop/Electron), otherwise window.location
 */
async function performLogout() {
  isWarningVisible.value = false
  stopIdleMonitoring()
  
  // Dynamic import to avoid circular dependencies
  const { useAuthStore } = await import('../stores/auth.js')
  const authStore = useAuthStore()
  authStore.logout()
  
  // Use router if available (Desktop sets this via setRouter()), otherwise fall back
  if (_router) {
    _router.push({ path: '/login', query: { reason: 'idle' } })
  } else {
    window.location.href = '/login?reason=idle'
  }
}

/**
 * Update the idle timeout setting
 */
function setIdleTimeout(minutes) {
  idleTimeoutMinutes.value = Math.max(0, Math.min(480, minutes)) // 0 = disabled, max 8 hours
  localStorage.setItem('idle_timeout_minutes', String(idleTimeoutMinutes.value))
  
  // Restart monitoring with new timeout
  if (isEnabled.value) {
    startIdleMonitoring()
  }
}

/**
 * Enable or disable idle auto-logout
 */
function setIdleLogoutEnabled(enabled) {
  isEnabled.value = enabled
  localStorage.setItem('idle_logout_enabled', String(enabled))
  
  if (enabled) {
    startIdleMonitoring()
  } else {
    stopIdleMonitoring()
  }
}

/**
 * Dismiss the warning and continue working
 */
function dismissWarning() {
  recordActivity()
}

// Watch for changes (e.g. from settings)
watch(idleTimeoutMinutes, () => {
  if (isEnabled.value) {
    startIdleMonitoring()
  }
})

export {
  // Reactive state
  idleTimeoutMinutes,
  isWarningVisible,
  countdownSeconds,
  isEnabled,
  
  // Methods
  startIdleMonitoring,
  stopIdleMonitoring,
  setIdleTimeout,
  setIdleLogoutEnabled,
  dismissWarning,
  setRouter,
}

