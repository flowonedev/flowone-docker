/**
 * Debug logging utility
 * 
 * Controlled by the debug_logs setting in Settings.
 * Uses localStorage for instant access without store imports (avoids circular deps).
 */

// Check if debug is enabled
export function isDebugEnabled() {
  try {
    return localStorage.getItem('webmail_debug_logs') === 'true'
  } catch {
    return false
  }
}

// Enable/disable debug (called from settings)
export function setDebugEnabled(enabled) {
  try {
    localStorage.setItem('webmail_debug_logs', enabled ? 'true' : 'false')
  } catch {
    // localStorage not available
  }
}

// Conditional debug log - only logs if debug is enabled
export function debugLog(prefix, ...args) {
  if (isDebugEnabled()) {
    console.log(prefix, ...args)
  }
}

