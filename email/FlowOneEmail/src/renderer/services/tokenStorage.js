/**
 * Secure Token Storage Utility (Electron Desktop)
 * 
 * For Electron: uses the main process secure store via window.api.auth
 * Falls back to localStorage for non-Electron environments.
 * 
 * In Electron, tokens are stored via the main process which uses
 * electron-store with encryption, not browser sessionStorage.
 */

import { isElectron } from '@/services/electronApi'

const TOKEN_KEYS = [
  'webmail_token',
  'webmail_refresh_token', 
  'webmail_session_token',
  'webmail_active_account',
];

/**
 * Get a token value
 * In Electron: checks localStorage (renderer-side cache), main process handles secure storage
 * In browser: uses localStorage
 */
export function getToken(key) {
  // Both Electron and browser use localStorage in the renderer
  // In Electron, auth tokens are also stored via window.api.auth for main process access
  return localStorage.getItem(key) || null
}

/**
 * Set a token value
 */
export function setToken(key, value) {
  if (value) {
    localStorage.setItem(key, value)
  } else {
    localStorage.removeItem(key)
  }
  
  // Also sync to Electron main process secure store
  if (isElectron() && window.api?.auth) {
    if (key === 'webmail_token' && value) {
      window.api.auth.setToken(value).catch(() => {})
    }
  }
}

/**
 * Clear all auth tokens
 */
export function clearAllTokens() {
  for (const key of TOKEN_KEYS) {
    localStorage.removeItem(key)
  }
  
  // Also clear from Electron main process
  if (isElectron() && window.api?.auth) {
    window.api.auth.clearToken().catch(() => {})
  }
}

/**
 * Check if a token exists
 */
export function hasToken(key) {
  return !!localStorage.getItem(key)
}
