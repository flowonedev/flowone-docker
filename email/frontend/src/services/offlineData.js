/**
 * Offline Data Service -- Web Stub
 *
 * All functions are safe no-ops. The Desktop build overrides this file
 * via Vite alias to the real Electron implementation.
 */

export async function getOfflineBoards() { return null }
export async function getOfflineClients() { return null }
export async function getOfflineTodos() { return null }
export async function getOfflineCalendars() { return null }
export async function getOfflineEvents() { return null }

export async function withOfflineFallback(onlineCall) {
  return onlineCall()
}

export default {
  getOfflineBoards,
  getOfflineClients,
  getOfflineTodos,
  getOfflineCalendars,
  getOfflineEvents,
  withOfflineFallback,
}
