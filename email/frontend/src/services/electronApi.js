/**
 * Electron API Adapter — Web Stub
 * 
 * On the web, isElectron() returns false and all Electron-specific
 * functions are safe no-ops. The Desktop build overrides this file
 * via Vite alias to the real Electron implementation.
 */

export const isElectron = () => false
export const appName = 'email'
export const nativeOAuthScheme = null

export const getNetworkStatus = async () => ({
  isOnline: navigator.onLine,
  isVerifiedOnline: navigator.onLine,
})

export const queueChange = async () => null
export const getPendingCount = async () => 0
export const triggerSync = () => {}
export const onSyncEvent = () => () => {}

export const getAuthToken = async () => localStorage.getItem('webmail_token')
export const setAuthToken = async (token) => {
  localStorage.setItem('webmail_token', token)
  return true
}
export const clearAuth = async () => {
  localStorage.removeItem('webmail_token')
  localStorage.removeItem('webmail_refresh_token')
  localStorage.removeItem('webmail_session_token')
  return true
}
export const isLoggedIn = async () => !!localStorage.getItem('webmail_token')

export const getConfig = async (key) => localStorage.getItem(`config_${key}`)
export const setConfig = async (key, value) => {
  localStorage.setItem(`config_${key}`, JSON.stringify(value))
  return true
}
export const getAllConfig = async () => ({})

export const showNotification = async (title, body) => {
  if ('Notification' in window && Notification.permission === 'granted') {
    new Notification(title, { body })
  }
  return true
}

export const openExternal = async (url) => {
  window.open(url, '_blank')
  return true
}

export const getAppVersion = async () => '1.0.0-web'

export const openOAuthBrowser = async (url) => {
  window.location.href = url
}

export default {
  isElectron,
  appName,
  nativeOAuthScheme,
  openOAuthBrowser,
  getNetworkStatus,
  queueChange,
  getPendingCount,
  triggerSync,
  onSyncEvent,
  getAuthToken,
  setAuthToken,
  clearAuth,
  isLoggedIn,
  getConfig,
  setConfig,
  getAllConfig,
  showNotification,
  openExternal,
  getAppVersion,
}

