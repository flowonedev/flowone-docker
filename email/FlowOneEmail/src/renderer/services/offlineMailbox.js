/**
 * Offline Mailbox Service
 * 
 * Provides offline-first email reading capability for the Electron desktop app.
 * When online, data is fetched from the API and synced to local DB.
 * When offline, data is read directly from the local SQLite database.
 */

// Check if we're running in Electron
const isElectron = () => {
  return typeof window !== 'undefined' && !!window.api
}

// Track online status
let _isOnline = navigator.onLine
let _hasOfflineData = false
let _offlineDataChecked = false

// Listen for online/offline events
if (typeof window !== 'undefined') {
  window.addEventListener('online', () => {
    _isOnline = true
    console.log('[OfflineMailbox] Network online')
  })
  window.addEventListener('offline', () => {
    _isOnline = false
    console.log('[OfflineMailbox] Network offline')
  })
  
  // Also listen for Electron's online status if available
  if (isElectron()) {
    window.api.on('online-status', (online) => {
      _isOnline = online
      console.log('[OfflineMailbox] Electron online status:', online)
    })
  }
}

/**
 * Check if we should use offline mode
 * Returns true if: we're in Electron AND (offline OR have offline data as fallback)
 */
export async function shouldUseOffline() {
  if (!isElectron()) return false
  
  // Check if we have offline data (only once)
  if (!_offlineDataChecked) {
    try {
      const result = await window.api.db.hasOfflineData()
      _hasOfflineData = result?.hasEmails || result?.hasFolders
      _offlineDataChecked = true
      console.log('[OfflineMailbox] Has offline data:', _hasOfflineData)
    } catch (e) {
      console.warn('[OfflineMailbox] Failed to check offline data:', e)
    }
  }
  
  // Use offline if explicitly offline
  if (!_isOnline) return true
  
  return false
}

/**
 * Check if we can read from local DB (for fallback)
 */
export async function canUseOfflineFallback() {
  if (!isElectron()) return false
  if (!_offlineDataChecked) {
    await shouldUseOffline()
  }
  return _hasOfflineData
}

/**
 * Get sync status
 */
export async function getSyncStatus() {
  if (!isElectron()) return { isOnline: navigator.onLine, pendingCount: 0 }
  
  try {
    return await window.api.db.getSyncStatus()
  } catch (e) {
    return { isOnline: _isOnline, pendingCount: 0 }
  }
}

/**
 * Trigger email sync to download emails to local DB
 */
export async function triggerSync() {
  if (!isElectron()) return false
  
  try {
    console.log('[OfflineMailbox] Triggering email sync...')
    console.log('[OfflineMailbox] window.api.db:', window.api?.db)
    console.log('[OfflineMailbox] syncEmails function:', typeof window.api?.db?.syncEmails)
    
    const result = await window.api.db.syncEmails()
    console.log('[OfflineMailbox] Sync result:', result)
    
    _offlineDataChecked = false // Re-check on next call
    return true
  } catch (e) {
    console.error('[OfflineMailbox] Sync failed:', e)
    console.error('[OfflineMailbox] Error details:', e.message, e.stack)
    return false
  }
}

/**
 * Sync email bodies for offline reading
 * @param {number} days - Number of days of emails to sync (default 7)
 * @param {number} maxCount - Maximum number of bodies to sync (default 200)
 */
export async function syncEmailBodies(days = 7, maxCount = 200) {
  if (!isElectron()) return { synced: 0, total: 0 }
  
  try {
    console.log(`[OfflineMailbox] Syncing email bodies (${days} days, max ${maxCount})...`)
    const result = await window.api.db.syncEmailBodies(days, maxCount)
    console.log(`[OfflineMailbox] Synced ${result.synced}/${result.total} bodies`)
    return result
  } catch (e) {
    console.error('[OfflineMailbox] Body sync failed:', e)
    return { synced: 0, total: 0 }
  }
}

/**
 * Get count of emails that need body sync
 */
export async function getEmailsNeedingBodies(days = 7) {
  if (!isElectron()) return 0
  
  try {
    return await window.api.db.getEmailsNeedingBodies(days)
  } catch (e) {
    console.error('[OfflineMailbox] Failed to get body count:', e)
    return 0
  }
}

/**
 * Prepare for offline mode - sync headers and bodies
 * @param {Function} onProgress - Progress callback (current, total, phase)
 */
export async function prepareForOffline(onProgress = null) {
  if (!isElectron()) return false
  
  try {
    // Phase 1: Sync email headers
    if (onProgress) onProgress(0, 2, 'headers')
    await triggerSync()
    
    // Phase 2: Sync email bodies
    if (onProgress) onProgress(1, 2, 'bodies')
    await syncEmailBodies(7, 500) // Last 7 days, max 500 emails
    
    if (onProgress) onProgress(2, 2, 'complete')
    return true
  } catch (e) {
    console.error('[OfflineMailbox] Failed to prepare for offline:', e)
    return false
  }
}

// ============================================
// OFFLINE DATA ACCESS METHODS
// ============================================

/**
 * Get folders from local database
 */
export async function getOfflineFolders() {
  if (!isElectron()) return null
  
  try {
    const folders = await window.api.db.getFolders()
    // Transform to match web app format
    return folders.map(f => {
      // Use stored type, fall back to inferring from folder path
      let folderType = f.type
      if (!folderType || folderType === 'user') {
        const path = (f.full_path || f.name || '').toUpperCase()
        const lastPart = (f.full_path || f.name || '').split('.').pop()?.toUpperCase() || path
        if (path === 'INBOX') folderType = 'inbox'
        else if (lastPart === 'SENT') folderType = 'sent'
        else if (lastPart === 'DRAFTS') folderType = 'drafts'
        else if (lastPart === 'TRASH') folderType = 'trash'
        else if (lastPart === 'SPAM' || lastPart === 'JUNK') folderType = 'spam'
        else if (lastPart === 'ARCHIVE') folderType = 'archive'
        else folderType = 'user'
      }
      
      const isSystem = folderType !== 'user'
      
      return {
        ...f,
        name: f.full_path || f.name,
        path: f.full_path,
        total: f.total || 0,
        unread: f.unread || 0,
        type: folderType,
        system: isSystem
      }
    })
  } catch (e) {
    console.error('[OfflineMailbox] Failed to get offline folders:', e)
    return null
  }
}

/**
 * Get messages from local database
 */
export async function getOfflineMessages(folderPath, page = 1, limit = 50) {
  if (!isElectron()) return null
  
  try {
    const offset = (page - 1) * limit
    const result = await window.api.db.getEmailsByFolder(folderPath, limit, offset)
    
    return {
      success: true,
      data: {
        messages: result.messages || [],
        page: result.page || page,
        pages: result.pages || 1,
        total: result.total || 0,
        limit: result.limit || limit,
        folderStatus: null, // Not available offline
        conversations: null, // Would need separate implementation
      }
    }
  } catch (e) {
    console.error('[OfflineMailbox] Failed to get offline messages:', e)
    return null
  }
}

/**
 * Get single message from local database
 */
export async function getOfflineMessage(folderPath, uid) {
  if (!isElectron()) return null
  
  try {
    const email = await window.api.db.getEmail(folderPath, uid)
    
    if (!email) return null
    
    return {
      success: true,
      data: email
    }
  } catch (e) {
    console.error('[OfflineMailbox] Failed to get offline message:', e)
    return null
  }
}

/**
 * Fetch email body (will try API first, then cache)
 */
export async function fetchEmailBody(emailId) {
  if (!isElectron()) return null
  
  try {
    return await window.api.db.fetchEmailBody(emailId)
  } catch (e) {
    console.error('[OfflineMailbox] Failed to fetch email body:', e)
    return null
  }
}

// ============================================
// OFFLINE-AWARE API WRAPPER
// ============================================

/**
 * Wrap an API call with offline fallback
 * @param {Function} onlineCall - Function that makes the API call
 * @param {Function} offlineCall - Function that reads from local DB
 * @param {Object} options - { forceOnline: boolean }
 */
export async function withOfflineFallback(onlineCall, offlineCall, options = {}) {
  const { forceOnline = false } = options
  
  // If not in Electron, always use online
  if (!isElectron()) {
    return onlineCall()
  }
  
  // Check if we should use offline mode
  const useOffline = !forceOnline && await shouldUseOffline()
  
  if (useOffline) {
    console.log('[OfflineMailbox] Using offline data')
    return offlineCall()
  }
  
  // Try online first
  try {
    const result = await onlineCall()
    return result
  } catch (e) {
    // If online fails and we have offline data, fall back
    if (await canUseOfflineFallback()) {
      console.log('[OfflineMailbox] Online failed, falling back to offline:', e.message)
      return offlineCall()
    }
    throw e
  }
}

export default {
  isElectron,
  shouldUseOffline,
  canUseOfflineFallback,
  getSyncStatus,
  triggerSync,
  syncEmailBodies,
  getEmailsNeedingBodies,
  prepareForOffline,
  getOfflineFolders,
  getOfflineMessages,
  getOfflineMessage,
  fetchEmailBody,
  withOfflineFallback,
}

