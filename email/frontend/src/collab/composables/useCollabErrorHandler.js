/**
 * useCollabErrorHandler Composable
 * 
 * Centralized error handling and recovery for collaboration.
 * Manages reconnection logic, error notifications, and recovery strategies.
 */

import { ref, computed, watch } from 'vue'
import { isDebugEnabled } from '@/utils/debug'

// Error types
export const COLLAB_ERROR_TYPES = {
  CONNECTION_LOST: 'connection_lost',
  AUTH_FAILED: 'auth_failed',
  AUTH_EXPIRED: 'auth_expired',
  DOCUMENT_NOT_FOUND: 'document_not_found',
  PERMISSION_DENIED: 'permission_denied',
  SERVER_ERROR: 'server_error',
  SYNC_FAILED: 'sync_failed',
  NETWORK_ERROR: 'network_error',
  OFFLINE: 'offline',
}

// Recovery strategies
export const COLLAB_RECOVERY_STRATEGIES = {
  [COLLAB_ERROR_TYPES.CONNECTION_LOST]: {
    canAutoRecover: true,
    maxRetries: 10,
    action: 'reconnect',
    message: 'Connection lost. Attempting to reconnect...',
  },
  [COLLAB_ERROR_TYPES.AUTH_FAILED]: {
    canAutoRecover: false,
    action: 'reauth',
    message: 'Authentication failed. Please log in again.',
  },
  [COLLAB_ERROR_TYPES.AUTH_EXPIRED]: {
    canAutoRecover: true,
    maxRetries: 1,
    action: 'refresh_token',
    message: 'Session expired. Refreshing...',
  },
  [COLLAB_ERROR_TYPES.DOCUMENT_NOT_FOUND]: {
    canAutoRecover: false,
    action: 'redirect',
    message: 'Document not found. It may have been deleted.',
  },
  [COLLAB_ERROR_TYPES.PERMISSION_DENIED]: {
    canAutoRecover: false,
    action: 'redirect',
    message: 'You no longer have access to this document.',
  },
  [COLLAB_ERROR_TYPES.SERVER_ERROR]: {
    canAutoRecover: true,
    maxRetries: 3,
    action: 'reconnect',
    message: 'Server error. Retrying...',
  },
  [COLLAB_ERROR_TYPES.SYNC_FAILED]: {
    canAutoRecover: true,
    maxRetries: 5,
    action: 'resync',
    message: 'Sync failed. Changes saved locally.',
  },
  [COLLAB_ERROR_TYPES.NETWORK_ERROR]: {
    canAutoRecover: true,
    maxRetries: Infinity,
    action: 'wait_online',
    message: 'Network error. Waiting for connection...',
  },
  [COLLAB_ERROR_TYPES.OFFLINE]: {
    canAutoRecover: true,
    action: 'wait_online',
    message: 'You are offline. Changes will sync when reconnected.',
  },
}

/**
 * Error handling and recovery
 */
export function useCollabErrorHandler() {
  // State
  const currentError = ref(null)
  const errorHistory = ref([])
  const isRecovering = ref(false)
  const recoveryAttempts = ref(0)
  const isOnline = ref(navigator.onLine)
  
  // Callbacks
  const onRecoveryComplete = ref(null)
  const onRecoveryFailed = ref(null)
  const onAuthRequired = ref(null)
  
  // Computed
  const hasError = computed(() => currentError.value !== null)
  
  const recoveryStrategy = computed(() => {
    if (!currentError.value) return null
    return COLLAB_RECOVERY_STRATEGIES[currentError.value.type] || null
  })
  
  const canRecover = computed(() => {
    if (!recoveryStrategy.value) return false
    if (!recoveryStrategy.value.canAutoRecover) return false
    if (recoveryStrategy.value.maxRetries !== Infinity && 
        recoveryAttempts.value >= recoveryStrategy.value.maxRetries) {
      return false
    }
    return true
  })
  
  const errorMessage = computed(() => {
    if (!currentError.value) return null
    return recoveryStrategy.value?.message || currentError.value.message || 'An error occurred'
  })
  
  /**
   * Handle an error
   */
  function handleError(type, details = {}) {
    const error = {
      type,
      message: details.message || COLLAB_RECOVERY_STRATEGIES[type]?.message,
      details,
      timestamp: Date.now(),
    }
    
    currentError.value = error
    errorHistory.value.push(error)
    
    // Limit history
    if (errorHistory.value.length > 50) {
      errorHistory.value = errorHistory.value.slice(-50)
    }
    
    isDebugEnabled() && console.log(`[CollabError] ${type}:`, details)
    
    // Auto-recover if possible
    if (canRecover.value) {
      attemptRecovery()
    }
    
    return error
  }
  
  /**
   * Attempt recovery
   */
  async function attemptRecovery() {
    if (isRecovering.value) return
    if (!canRecover.value) return
    
    isRecovering.value = true
    recoveryAttempts.value++
    
    const strategy = recoveryStrategy.value
    
    try {
      switch (strategy.action) {
        case 'reconnect':
          // Exponential backoff
          const delay = Math.min(1000 * Math.pow(2, recoveryAttempts.value - 1), 30000)
          await sleep(delay)
          
          // Emit reconnect event for provider to handle
          window.dispatchEvent(new CustomEvent('collab:reconnect'))
          break
          
        case 'refresh_token':
          // Emit token refresh event
          window.dispatchEvent(new CustomEvent('collab:refresh-token'))
          break
          
        case 'resync':
          // Emit resync event
          window.dispatchEvent(new CustomEvent('collab:resync'))
          break
          
        case 'wait_online':
          // Just wait - online listener will trigger recovery
          break
          
        case 'reauth':
          // Cannot auto-recover, need user action
          if (onAuthRequired.value) {
            onAuthRequired.value()
          }
          break
      }
    } catch (e) {
      console.error('[CollabError] Recovery attempt failed:', e)
    } finally {
      isRecovering.value = false
    }
  }
  
  /**
   * Mark error as resolved
   */
  function clearError() {
    currentError.value = null
    recoveryAttempts.value = 0
    isRecovering.value = false
    
    if (onRecoveryComplete.value) {
      onRecoveryComplete.value()
    }
  }
  
  /**
   * Force recovery attempt
   */
  function retry() {
    recoveryAttempts.value = 0
    attemptRecovery()
  }
  
  /**
   * Reset all error state
   */
  function reset() {
    currentError.value = null
    recoveryAttempts.value = 0
    isRecovering.value = false
  }
  
  // Online/offline detection
  function handleOnline() {
    isOnline.value = true
    
    // If we have an offline/network error, attempt recovery
    if (currentError.value?.type === COLLAB_ERROR_TYPES.OFFLINE ||
        currentError.value?.type === COLLAB_ERROR_TYPES.NETWORK_ERROR) {
      clearError()
      window.dispatchEvent(new CustomEvent('collab:reconnect'))
    }
  }
  
  function handleOffline() {
    isOnline.value = false
    handleError(COLLAB_ERROR_TYPES.OFFLINE)
  }
  
  // Setup listeners
  window.addEventListener('online', handleOnline)
  window.addEventListener('offline', handleOffline)
  
  // Cleanup
  function destroy() {
    window.removeEventListener('online', handleOnline)
    window.removeEventListener('offline', handleOffline)
  }
  
  return {
    // State
    currentError,
    errorHistory,
    isRecovering,
    recoveryAttempts,
    isOnline,
    
    // Computed
    hasError,
    canRecover,
    errorMessage,
    recoveryStrategy,
    
    // Actions
    handleError,
    attemptRecovery,
    clearError,
    retry,
    reset,
    destroy,
    
    // Callbacks
    onRecoveryComplete,
    onRecoveryFailed,
    onAuthRequired,
    
    // Error types
    ERROR_TYPES: COLLAB_ERROR_TYPES,
  }
}

/**
 * Sleep helper
 */
function sleep(ms) {
  return new Promise(resolve => setTimeout(resolve, ms))
}

/**
 * Error notification component data
 */
export function getErrorNotification(error) {
  if (!error) return null
  
  const strategy = COLLAB_RECOVERY_STRATEGIES[error.type]
  
  return {
    type: strategy?.canAutoRecover ? 'warning' : 'error',
    message: strategy?.message || error.message || 'An error occurred',
    canDismiss: !strategy?.canAutoRecover,
    action: strategy?.canAutoRecover ? null : {
      label: getActionLabel(strategy?.action),
      handler: strategy?.action,
    },
  }
}

function getActionLabel(action) {
  switch (action) {
    case 'reauth': return 'Log in'
    case 'redirect': return 'Go back'
    default: return 'Retry'
  }
}

