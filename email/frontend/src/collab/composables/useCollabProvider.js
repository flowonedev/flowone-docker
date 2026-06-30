/**
 * useCollabProvider Composable
 * 
 * Manages the Hocuspocus WebSocket connection and Y.js document.
 * This is the core composable that other collaboration features build upon.
 */

import { ref, shallowRef, onUnmounted, watch, computed } from 'vue'
import { HocuspocusProvider } from '@hocuspocus/provider'
import * as Y from 'yjs'
import { isDebugEnabled } from '@/utils/debug'
import { IndexeddbPersistence } from 'y-indexeddb'
import { useCollabStore } from '../stores/collabStore.js'

// Configuration - auto-detect WebSocket URL based on environment
function getCollabWsUrl() {
  // Check for explicit env var first
  if (import.meta.env.VITE_COLLAB_WS_URL) {
    return import.meta.env.VITE_COLLAB_WS_URL
  }
  
  // In production, use direct WSS connection on port 1234
  // This bypasses the reverse proxy which has WebSocket header issues
  if (typeof window !== 'undefined' && window.location.hostname !== 'localhost') {
    return `wss://${window.location.hostname}:1234`
  }
  
  // Development fallback
  return 'ws://localhost:1234'
}

const COLLAB_WS_URL = getCollabWsUrl()

/**
 * Create and manage a collaborative document connection
 * 
 * @param {Object} options
 * @param {string} options.documentUuid - Document UUID to connect to
 * @param {string} options.token - Collaboration JWT token
 * @param {Object} options.user - Current user info { email, name }
 * @param {Function} options.onSynced - Callback when initial sync completes
 * @param {Function} options.onDisconnect - Callback on disconnect
 */
export function useCollabProvider(options = {}) {
  const collabStore = useCollabStore()
  
  // ============================================================
  // STATE
  // ============================================================

  // Y.js document
  const ydoc = shallowRef(null)
  
  // Hocuspocus provider
  const provider = shallowRef(null)
  
  // IndexedDB persistence (offline support)
  const indexeddbProvider = shallowRef(null)
  
  // Connection state
  const isConnected = ref(false)
  const isSynced = ref(false)
  const status = ref('disconnected') // 'disconnected', 'connecting', 'connected', 'reconnecting'
  
  // Error state
  const error = ref(null)
  const reconnectAttempts = ref(0)
  const maxReconnectAttempts = 10

  // ============================================================
  // INITIALIZATION
  // ============================================================

  /**
   * Connect to the collaboration server
   */
  async function connect(documentUuid, token, user) {
    if (provider.value) {
      console.warn('[CollabProvider] Already connected, disconnecting first')
      disconnect()
    }

    if (!documentUuid || !token) {
      error.value = 'Document UUID and token are required'
      return false
    }

    try {
      status.value = 'connecting'
      collabStore.setConnectionStatus('connecting')
      error.value = null

      // Create Y.js document
      ydoc.value = new Y.Doc()

      // Set up IndexedDB persistence for offline support
      indexeddbProvider.value = new IndexeddbPersistence(
        `collab-${documentUuid}`,
        ydoc.value
      )

      indexeddbProvider.value.on('synced', () => {
        isDebugEnabled() && console.log('[CollabProvider] IndexedDB synced')
      })

      // Create Hocuspocus provider
      provider.value = new HocuspocusProvider({
        url: COLLAB_WS_URL,
        name: documentUuid,
        document: ydoc.value,
        token: token,
        
        // Connection options
        connect: true,
        preserveConnection: true,
        
        // Reconnection settings
        delay: 1000,
        maxDelay: 30000,
        factor: 2,
        
        // Event handlers
        onOpen: () => {
          isDebugEnabled() && console.log('[CollabProvider] WebSocket opened')
        },
        
        onConnect: () => {
          isDebugEnabled() && console.log('[CollabProvider] Connected to server')
          isConnected.value = true
          status.value = 'connected'
          reconnectAttempts.value = 0
          error.value = null
          collabStore.setConnectionStatus('connected')
        },
        
        onDisconnect: ({ event }) => {
          isDebugEnabled() && console.log('[CollabProvider] Disconnected', event)
          isConnected.value = false
          status.value = 'disconnected'
          collabStore.setConnectionStatus('disconnected')
          
          if (options.onDisconnect) {
            options.onDisconnect(event)
          }
        },
        
        onClose: ({ event }) => {
          isDebugEnabled() && console.log('[CollabProvider] Connection closed', event)
          if (event.code !== 1000) {
            // Abnormal closure
            status.value = 'reconnecting'
            collabStore.setConnectionStatus('reconnecting')
            reconnectAttempts.value++
            
            if (reconnectAttempts.value >= maxReconnectAttempts) {
              error.value = 'Connection lost. Please refresh the page.'
            }
          }
        },
        
        onSynced: ({ state }) => {
          isDebugEnabled() && console.log('[CollabProvider] Document synced', state)
          isSynced.value = true
          
          if (options.onSynced) {
            options.onSynced(ydoc.value)
          }
        },
        
        onAuthenticationFailed: ({ reason }) => {
          console.error('[CollabProvider] Authentication failed:', reason)
          error.value = `Authentication failed: ${reason}`
          status.value = 'disconnected'
          collabStore.setConnectionStatus('disconnected')
        },
        
        onStateless: ({ payload }) => {
          isDebugEnabled() && console.log('[CollabProvider] Stateless message:', payload)
        },
      })

      // Set awareness info
      if (user) {
        setAwarenessUser(user)
      }

      return true
    } catch (e) {
      console.error('[CollabProvider] Connection error:', e)
      error.value = e.message
      status.value = 'disconnected'
      collabStore.setConnectionStatus('disconnected')
      return false
    }
  }

  /**
   * Disconnect from the collaboration server
   */
  function disconnect() {
    if (provider.value) {
      provider.value.destroy()
      provider.value = null
    }

    if (indexeddbProvider.value) {
      indexeddbProvider.value.destroy()
      indexeddbProvider.value = null
    }

    if (ydoc.value) {
      ydoc.value.destroy()
      ydoc.value = null
    }

    isConnected.value = false
    isSynced.value = false
    status.value = 'disconnected'
    error.value = null
    reconnectAttempts.value = 0
    collabStore.setConnectionStatus('disconnected')
  }

  /**
   * Force reconnection
   */
  function reconnect() {
    if (provider.value) {
      provider.value.connect()
    }
  }

  // ============================================================
  // AWARENESS (Presence)
  // ============================================================

  /**
   * Get the awareness instance
   */
  const awareness = computed(() => {
    return provider.value?.awareness || null
  })

  /**
   * Set local user awareness info
   */
  function setAwarenessUser(user) {
    if (!provider.value?.awareness) return

    const color = getCollabUserColor(user.email)
    
    provider.value.awareness.setLocalStateField('user', {
      email: user.email,
      name: user.name || user.email.split('@')[0],
      color: color,
    })
  }

  /**
   * Update cursor position in awareness
   */
  function updateCursor(cursorData) {
    if (!provider.value?.awareness) return

    provider.value.awareness.setLocalStateField('cursor', cursorData)
  }

  /**
   * Clear cursor from awareness (e.g., when leaving editor)
   */
  function clearCursor() {
    if (!provider.value?.awareness) return

    provider.value.awareness.setLocalStateField('cursor', null)
  }

  // ============================================================
  // HELPERS
  // ============================================================

  /**
   * Get deterministic color for user based on email.
   * Uses FNV-1a hash (better distribution than DJB2) and 24 highly
   * distinct colors to minimise collisions among concurrent editors.
   */
  function getCollabUserColor(email) {
    const colors = [
      '#E53935', // Red
      '#D81B60', // Pink
      '#8E24AA', // Purple
      '#5E35B1', // Deep Purple
      '#3949AB', // Indigo
      '#1E88E5', // Blue
      '#039BE5', // Light Blue
      '#00ACC1', // Cyan
      '#00897B', // Teal
      '#43A047', // Green
      '#C0CA33', // Lime
      '#FDD835', // Yellow
      '#FFB300', // Amber
      '#FB8C00', // Orange
      '#F4511E', // Deep Orange
      '#6D4C41', // Brown
      '#546E7A', // Blue Grey
      '#EC407A', // Pink Accent
      '#AB47BC', // Purple Accent
      '#42A5F5', // Blue Accent
      '#26A69A', // Teal Accent
      '#66BB6A', // Green Accent
      '#FF7043', // Deep Orange Accent
      '#8D6E63', // Brown Accent
    ]
    // FNV-1a 32-bit hash — much better distribution than DJB2
    let hash = 2166136261
    for (let i = 0; i < email.length; i++) {
      hash ^= email.charCodeAt(i)
      hash = Math.imul(hash, 16777619)
    }
    return colors[(hash >>> 0) % colors.length]
  }

  /**
   * Send a custom message to all connected clients
   */
  function broadcast(type, payload) {
    if (!provider.value) return

    provider.value.sendStateless(JSON.stringify({ type, payload }))
  }

  // ============================================================
  // CLEANUP
  // ============================================================

  onUnmounted(() => {
    disconnect()
  })

  // ============================================================
  // RETURN
  // ============================================================

  return {
    // Y.js document
    ydoc,
    
    // Provider
    provider,
    awareness,
    
    // State
    isConnected,
    isSynced,
    status,
    error,
    reconnectAttempts,
    
    // Actions
    connect,
    disconnect,
    reconnect,
    
    // Awareness
    setAwarenessUser,
    updateCursor,
    clearCursor,
    
    // Helpers
    getCollabUserColor,
    broadcast,
  }
}

