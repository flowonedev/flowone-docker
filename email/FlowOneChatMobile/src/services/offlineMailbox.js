/**
 * Offline Mailbox stub for FlowOneChatMobile.
 * Chat mobile app does not need offline email capabilities.
 */

export const isOfflineMode = () => false
export const hasOfflineData = () => false
export const checkOfflineData = async () => false

export const getOfflineFolders = async () => null
export const getOfflineMessages = async () => null
export const getOfflineMessage = async () => null
export const getOfflineEmails = async () => ({ messages: [], total: 0 })
export const getOfflineEmail = async () => null
export const cacheEmails = async () => 0
export const cacheEmailBody = async () => false
export const isOnline = () => navigator.onLine

// No-op writers/readers consumed by the shared mailbox store + mail-sync
// integration. The chat app never persists email offline, so these short-circuit
// with the same return shapes as the real implementation in the frontend.
export const setActiveUserEmail = () => {}
export const setOfflineFolders = async () => false
export const setOfflineMessages = async () => false
export const patchMessage = async () => false
export const removeMessage = async () => false
export const addMessage = async () => false
export const getOfflineMessageBody = async () => null
export const setOfflineMessageBody = async () => false
export const recordFolderVisit = async () => false
export const getTopRecentFolders = async () => []
export const wipeFolderCache = async () => false

export async function shouldUseOffline() { return false }
export async function canUseOfflineFallback() { return false }

export async function withOfflineFallback(onlineCall) {
  return onlineCall()
}

export default {
  isOfflineMode, hasOfflineData, checkOfflineData,
  getOfflineFolders, getOfflineMessages, getOfflineMessage,
  getOfflineEmails, getOfflineEmail,
  cacheEmails, cacheEmailBody, isOnline,
  setActiveUserEmail, setOfflineFolders, setOfflineMessages,
  patchMessage, removeMessage, addMessage,
  getOfflineMessageBody, setOfflineMessageBody,
  recordFolderVisit, getTopRecentFolders, wipeFolderCache,
  shouldUseOffline, canUseOfflineFallback, withOfflineFallback,
}
