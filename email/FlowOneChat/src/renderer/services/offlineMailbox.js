/**
 * Offline Mailbox stub for FlowOneChat
 * Chat app does not need offline email capabilities.
 * All functions are safe no-ops that match the interface expected by
 * shared stores (mailbox.js etc.).
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
  shouldUseOffline, canUseOfflineFallback, withOfflineFallback,
}
