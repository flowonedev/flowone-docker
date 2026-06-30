import { Browser } from '@capacitor/browser'

export const isElectron = () => false
export const appName = 'chat-mobile'
export const nativeOAuthScheme = 'flowone-chat'

export const getNetworkStatus = async () => {
  return { isOnline: navigator.onLine, isVerifiedOnline: navigator.onLine }
}

export const getAuthToken = async () => localStorage.getItem('webmail_token')

export const setAuthToken = async (token, email, name) => {
  if (token) localStorage.setItem('webmail_token', token)
  return true
}

export const clearAuth = async () => {
  localStorage.removeItem('webmail_token')
  localStorage.removeItem('webmail_refresh_token')
  localStorage.removeItem('webmail_session_token')
  return true
}

export const isLoggedIn = async () => !!localStorage.getItem('webmail_token')

export const getConfig = async (key) => {
  try {
    const val = localStorage.getItem(`config_${key}`)
    return val ? JSON.parse(val) : null
  } catch { return localStorage.getItem(`config_${key}`) }
}

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

export const getAppVersion = async () => '1.0.0-mobile'

export const openOAuthBrowser = async (url) => {
  await Browser.open({ url, presentationStyle: 'popover' })
}

export const onSyncEvent = (_event, _callback) => () => {}

export const queueChange = async () => null
export const getPendingCount = async () => 0
export const triggerSync = () => {}

export default {
  isElectron, appName, nativeOAuthScheme, openOAuthBrowser,
  getNetworkStatus, getAuthToken, setAuthToken, clearAuth,
  isLoggedIn, getConfig, setConfig, getAllConfig,
  showNotification, openExternal, getAppVersion, onSyncEvent,
  queueChange, getPendingCount, triggerSync,
}
