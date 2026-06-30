/**
 * useCollabOffline Composable
 * 
 * Provides IndexedDB-based offline persistence for Y.js documents.
 * Automatically syncs changes when connection is restored.
 */

import { ref, watch, onUnmounted } from 'vue'
import { IndexeddbPersistence } from 'y-indexeddb'
import { isDebugEnabled } from '@/utils/debug'

/**
 * Setup offline persistence for a Y.js document
 * 
 * @param {Object} options
 * @param {string} options.documentId - Document UUID
 * @param {Object} options.ydoc - Y.js document
 */
export function useCollabOffline(options = {}) {
  // State
  const isOfflineSynced = ref(false)
  const offlineError = ref(null)
  const pendingChanges = ref(0)
  
  // IndexedDB provider
  let indexeddbProvider = null
  let changeObserver = null
  
  /**
   * Initialize offline persistence
   */
  function initOffline(documentId, ydoc) {
    if (indexeddbProvider) {
      console.warn('[CollabOffline] Already initialized')
      return
    }
    
    if (!documentId || !ydoc) {
      offlineError.value = 'Document ID and Y.doc required'
      return
    }
    
    try {
      // Create IndexedDB provider with collab prefix
      indexeddbProvider = new IndexeddbPersistence(
        `collab_${documentId}`,
        ydoc
      )
      
      // Track sync status
      indexeddbProvider.on('synced', () => {
        isDebugEnabled() && console.log('[CollabOffline] IndexedDB synced')
        isOfflineSynced.value = true
      })
      
      // Track changes for offline indicator
      changeObserver = () => {
        pendingChanges.value++
      }
      ydoc.on('update', changeObserver)
      
      isDebugEnabled() && console.log(`[CollabOffline] Initialized for document: ${documentId}`)
    } catch (error) {
      console.error('[CollabOffline] Init error:', error)
      offlineError.value = error.message
    }
  }
  
  /**
   * Destroy offline persistence
   */
  function destroyOffline() {
    if (changeObserver && options.ydoc) {
      options.ydoc.off('update', changeObserver)
      changeObserver = null
    }
    
    if (indexeddbProvider) {
      indexeddbProvider.destroy()
      indexeddbProvider = null
    }
    
    isOfflineSynced.value = false
    pendingChanges.value = 0
  }
  
  /**
   * Clear offline data for a document
   */
  async function clearOfflineData(documentId) {
    try {
      const dbName = `collab_${documentId}`
      await new Promise((resolve, reject) => {
        const request = indexedDB.deleteDatabase(dbName)
        request.onsuccess = resolve
        request.onerror = reject
      })
      isDebugEnabled() && console.log(`[CollabOffline] Cleared data for: ${documentId}`)
    } catch (error) {
      console.error('[CollabOffline] Clear error:', error)
    }
  }
  
  /**
   * Get list of all offline documents
   */
  async function listOfflineDocuments() {
    try {
      const databases = await indexedDB.databases()
      return databases
        .filter(db => db.name?.startsWith('collab_'))
        .map(db => db.name.replace('collab_', ''))
    } catch (error) {
      console.error('[CollabOffline] List error:', error)
      return []
    }
  }
  
  /**
   * Check if document has offline data
   */
  async function hasOfflineData(documentId) {
    try {
      const databases = await indexedDB.databases()
      return databases.some(db => db.name === `collab_${documentId}`)
    } catch (error) {
      return false
    }
  }
  
  /**
   * Get approximate offline storage size
   */
  async function getOfflineStorageSize() {
    try {
      if (navigator.storage && navigator.storage.estimate) {
        const estimate = await navigator.storage.estimate()
        return {
          used: estimate.usage || 0,
          quota: estimate.quota || 0,
          usedFormatted: formatBytes(estimate.usage || 0),
          quotaFormatted: formatBytes(estimate.quota || 0),
        }
      }
      return null
    } catch (error) {
      return null
    }
  }
  
  /**
   * Format bytes to human readable
   */
  function formatBytes(bytes) {
    if (bytes === 0) return '0 B'
    const k = 1024
    const sizes = ['B', 'KB', 'MB', 'GB']
    const i = Math.floor(Math.log(bytes) / Math.log(k))
    return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i]
  }
  
  // Auto-initialize if options provided
  if (options.documentId && options.ydoc) {
    initOffline(options.documentId, options.ydoc)
  }
  
  // Cleanup
  onUnmounted(() => {
    destroyOffline()
  })
  
  return {
    // State
    isOfflineSynced,
    offlineError,
    pendingChanges,
    
    // Actions
    initOffline,
    destroyOffline,
    clearOfflineData,
    listOfflineDocuments,
    hasOfflineData,
    getOfflineStorageSize,
  }
}

/**
 * Standalone offline utilities
 */
export const offlineUtils = {
  /**
   * Check if browser supports IndexedDB
   */
  isSupported() {
    return typeof indexedDB !== 'undefined'
  },
  
  /**
   * Request persistent storage
   */
  async requestPersistence() {
    if (navigator.storage && navigator.storage.persist) {
      return await navigator.storage.persist()
    }
    return false
  },
  
  /**
   * Check if storage is persisted
   */
  async isPersisted() {
    if (navigator.storage && navigator.storage.persisted) {
      return await navigator.storage.persisted()
    }
    return false
  },
}

