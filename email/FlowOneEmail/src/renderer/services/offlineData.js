/**
 * Offline Data Service -- Electron Implementation
 *
 * Provides offline-first fallback for boards, clients, todos, calendars,
 * and events. Reads from the local SQLite database exposed via window.api.db.
 *
 * The Vite alias '@/services/offlineData' resolves to this file in
 * desktop builds. The web build uses the no-op stub at
 * frontend/src/services/offlineData.js.
 */

import { withOfflineFallback } from '@/services/offlineMailbox'

const isElectron = () => typeof window !== 'undefined' && !!window.api

export async function getOfflineBoards() {
  if (!isElectron()) return null
  try {
    const boards = await window.api.db.getOfflineBoards()
    return boards && boards.length > 0 ? boards : null
  } catch (e) {
    console.error('[OfflineData] Failed to get offline boards:', e)
    return null
  }
}

export async function getOfflineClients() {
  if (!isElectron()) return null
  try {
    const clients = await window.api.db.getOfflineClients()
    return clients && clients.length > 0 ? clients : null
  } catch (e) {
    console.error('[OfflineData] Failed to get offline clients:', e)
    return null
  }
}

export async function getOfflineTodos() {
  if (!isElectron()) return null
  try {
    const todos = await window.api.db.getOfflineTodos()
    return todos && todos.length > 0 ? todos : null
  } catch (e) {
    console.error('[OfflineData] Failed to get offline todos:', e)
    return null
  }
}

export async function getOfflineCalendars() {
  if (!isElectron()) return null
  try {
    const calendars = await window.api.db.getOfflineCalendars()
    return calendars && calendars.length > 0 ? calendars : null
  } catch (e) {
    console.error('[OfflineData] Failed to get offline calendars:', e)
    return null
  }
}

export async function getOfflineEvents() {
  if (!isElectron()) return null
  try {
    const events = await window.api.db.getOfflineEvents()
    return events && events.length > 0 ? events : null
  } catch (e) {
    console.error('[OfflineData] Failed to get offline events:', e)
    return null
  }
}

export { withOfflineFallback }

export default {
  getOfflineBoards,
  getOfflineClients,
  getOfflineTodos,
  getOfflineCalendars,
  getOfflineEvents,
  withOfflineFallback,
}
