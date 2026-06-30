/**
 * Mail Sync WebSocket Client
 * 
 * Connects to the Mail Sync WebSocket server for real-time email
 * synchronization. In Electron, receives events from the main process
 * via IPC. In browser, connects directly via WebSocket.
 */

import { ref, computed, readonly } from 'vue'
import { isDebugEnabled } from '@/utils/debug'
import { isElectron } from '@/services/electronApi'

// Connection states
export const ConnectionState = {
  DISCONNECTED: 'disconnected',
  CONNECTING: 'connecting',
  CONNECTED: 'connected',
  RECONNECTING: 'reconnecting',
}

// Event types (must match server)
export const EventTypes = {
  MESSAGE_NEW: 'MESSAGE_NEW',
  MESSAGE_DELETED: 'MESSAGE_DELETED',
  MESSAGE_MOVED: 'MESSAGE_MOVED',
  FLAGS_CHANGED: 'FLAGS_CHANGED',
  FOLDER_COUNTS: 'FOLDER_COUNTS',
  CONVERSATION_UPDATED: 'CONVERSATION_UPDATED',
  FOLDER_CHANGED: 'FOLDER_CHANGED',
  SETTINGS_CHANGED: 'SETTINGS_CHANGED',
  PIN_CHANGED: 'PIN_CHANGED',
  LABELS_CHANGED: 'LABELS_CHANGED',
  CONNECTED: 'CONNECTED',
  RECONNECTED: 'RECONNECTED',
  ERROR: 'ERROR',
  SYNC_STATUS: 'SYNC_STATUS',
  // Board events
  BOARD_UPDATED: 'BOARD_UPDATED',
  LIST_UPDATED: 'LIST_UPDATED',
  CARD_UPDATED: 'CARD_UPDATED',
  CALENDAR_UPDATED: 'CALENDAR_UPDATED',
  CHECKLIST_UPDATED: 'CHECKLIST_UPDATED',
  TODO_UPDATED: 'TODO_UPDATED',
  // Colleague events
  COLLEAGUE_UPDATED: 'COLLEAGUE_UPDATED',
  COLLEAGUE_GROUP_UPDATED: 'COLLEAGUE_GROUP_UPDATED',
  // Email campaign events
  CAMPAIGN_PROGRESS: 'CAMPAIGN_PROGRESS',
  CAMPAIGN_UPDATE: 'CAMPAIGN_UPDATE',
  // Chat events (Direct Messaging)
  CHAT_MESSAGE_NEW: 'CHAT_MESSAGE_NEW',
  CHAT_MESSAGE_EDITED: 'CHAT_MESSAGE_EDITED',
  CHAT_MESSAGE_DELETED: 'CHAT_MESSAGE_DELETED',
  CHAT_MESSAGE_PINNED: 'CHAT_MESSAGE_PINNED',
  CHAT_REACTION_ADDED: 'CHAT_REACTION_ADDED',
  CHAT_REACTION_REMOVED: 'CHAT_REACTION_REMOVED',
  CHAT_TYPING_START: 'CHAT_TYPING_START',
  CHAT_TYPING_STOP: 'CHAT_TYPING_STOP',
  CHAT_READ_RECEIPT: 'CHAT_READ_RECEIPT',
  CHAT_CONVERSATION_CREATED: 'CHAT_CONVERSATION_CREATED',
  CHAT_SETTINGS_UPDATED: 'CHAT_SETTINGS_UPDATED',
  // View Together (collaborative viewing)
  CHAT_VIEW_SESSION_START: 'CHAT_VIEW_SESSION_START',
  CHAT_VIEW_SESSION_END: 'CHAT_VIEW_SESSION_END',
  CHAT_VIEW_SYNC: 'CHAT_VIEW_SYNC',
  // Presence events (online/away/offline status)
  PRESENCE_ONLINE: 'PRESENCE_ONLINE',
  PRESENCE_OFFLINE: 'PRESENCE_OFFLINE',
  PRESENCE_STATUS_CHANGED: 'PRESENCE_STATUS_CHANGED',
  PRESENCE_BULK_UPDATE: 'PRESENCE_BULK_UPDATE',
  // Call events (voice/video/screen share)
  CALL_INITIATE: 'CALL_INITIATE',
  CALL_RINGING: 'CALL_RINGING',
  CALL_ANSWER: 'CALL_ANSWER',
  CALL_REJECT: 'CALL_REJECT',
  CALL_HANGUP: 'CALL_HANGUP',
  CALL_ICE_CANDIDATE: 'CALL_ICE_CANDIDATE',
  CALL_MEDIA_STATE: 'CALL_MEDIA_STATE',
  CALL_PARTICIPANT_JOINED: 'CALL_PARTICIPANT_JOINED',
  CALL_PARTICIPANT_LEFT: 'CALL_PARTICIPANT_LEFT',
  CALL_SCREEN_SHARE_START: 'CALL_SCREEN_SHARE_START',
  CALL_SCREEN_SHARE_STOP: 'CALL_SCREEN_SHARE_STOP',
  CALL_MISSED: 'CALL_MISSED',
  CALL_DISMISSED: 'CALL_DISMISSED',
  CALL_SDP_OFFER: 'CALL_SDP_OFFER',
  CALL_SDP_ANSWER: 'CALL_SDP_ANSWER',
}

// Client message types
const ClientMessageTypes = {
  SUBSCRIBE_FOLDER: 'SUBSCRIBE_FOLDER',
  UNSUBSCRIBE_FOLDER: 'UNSUBSCRIBE_FOLDER',
  REPLAY_EVENTS: 'REPLAY_EVENTS',
  ACK_EVENT: 'ACK_EVENT',
  PING: 'PING',
  // Subscription types for real-time sync
  SUBSCRIBE_ALL: 'SUBSCRIBE_ALL',
  SUBSCRIBE_BOARDS: 'SUBSCRIBE_BOARDS',
  SUBSCRIBE_CALENDARS: 'SUBSCRIBE_CALENDARS',
  SUBSCRIBE_TODOS: 'SUBSCRIBE_TODOS',
  SUBSCRIBE_CHAT: 'SUBSCRIBE_CHAT',
  // Presence
  SUBSCRIBE_PRESENCE: 'SUBSCRIBE_PRESENCE',
  PRESENCE_UPDATE: 'PRESENCE_UPDATE',
  PRESENCE_HEARTBEAT: 'PRESENCE_HEARTBEAT',
  // Call signaling
  CALL_INITIATE: 'CALL_INITIATE',
  CALL_ANSWER: 'CALL_ANSWER',
  CALL_REJECT: 'CALL_REJECT',
  CALL_HANGUP: 'CALL_HANGUP',
  CALL_ICE_CANDIDATE: 'CALL_ICE_CANDIDATE',
  CALL_MEDIA_STATE: 'CALL_MEDIA_STATE',
  CALL_SCREEN_SHARE_START: 'CALL_SCREEN_SHARE_START',
  CALL_SCREEN_SHARE_STOP: 'CALL_SCREEN_SHARE_STOP',
  CALL_SDP_OFFER: 'CALL_SDP_OFFER',
  CALL_SDP_ANSWER: 'CALL_SDP_ANSWER',
}

// WebSocket URL (configured for production)
const WS_URL = import.meta.env.VITE_MAILSYNC_WS_URL || 'wss://flowone.pro/mailsync_ws'

// Reconnection settings
const RECONNECT_INITIAL_DELAY = 1000
const RECONNECT_MAX_DELAY = 30000
const RECONNECT_MULTIPLIER = 2

// Heartbeat settings
const HEARTBEAT_INTERVAL = 25000

// Idempotency: max processed eventIds to track
const MAX_PROCESSED_EVENTS = 1000

class MailSyncSocket {
  constructor() {
    // WebSocket instance (browser mode only)
    this.ws = null
    
    // Reactive state
    this.connectionState = ref(ConnectionState.DISCONNECTED)
    this.lastError = ref(null)
    this.lastEventVersion = ref(0)
    this.serverTime = ref(null)
    
    // Event handlers
    this.eventHandlers = new Map()
    
    // Processed event IDs for idempotency
    this.processedEventIds = new Set()
    this.processedEventIdList = []
    
    // Reconnection state (browser mode)
    this.reconnectAttempts = 0
    this.reconnectTimer = null
    
    // Heartbeat timer (browser mode)
    this.heartbeatTimer = null
    
    // Auth token
    this.token = null
    
    // IPC unsubscribe functions (Electron mode)
    this.ipcUnsubscribers = []
    
    // IndexedDB for persistence (browser mode)
    this.dbPromise = null
    if (!isElectron()) {
      this.dbPromise = this.initIndexedDB()
    }
  }

  /**
   * Initialize IndexedDB for persisting last version (browser mode only)
   */
  async initIndexedDB() {
    if (isElectron()) return null
    
    return new Promise((resolve, reject) => {
      const request = indexedDB.open('mailsync', 1)
      
      request.onerror = () => reject(request.error)
      request.onsuccess = () => resolve(request.result)
      
      request.onupgradeneeded = (event) => {
        const db = event.target.result
        
        if (!db.objectStoreNames.contains('syncState')) {
          db.createObjectStore('syncState', { keyPath: 'key' })
        }
      }
    })
  }

  /**
   * Save last processed version
   */
  async saveLastVersion(version) {
    if (isElectron()) {
      try {
        await window.api.db.setSetting('lastEventVersion', String(version))
      } catch (e) {
        console.warn('[MailSync] Failed to save version:', e)
      }
    } else {
      try {
        const db = await this.dbPromise
        const tx = db.transaction('syncState', 'readwrite')
        tx.objectStore('syncState').put({ key: 'lastVersion', value: version })
      } catch (e) {
        console.warn('[MailSync] Failed to save version:', e)
      }
    }
  }

  /**
   * Load last processed version
   */
  async loadLastVersion() {
    if (isElectron()) {
      try {
        const value = await window.api.db.getSetting('lastEventVersion')
        return value ? parseInt(value, 10) : 0
      } catch (e) {
        console.warn('[MailSync] Failed to load version:', e)
        return 0
      }
    } else {
      try {
        const db = await this.dbPromise
        const tx = db.transaction('syncState', 'readonly')
        const result = await new Promise((resolve, reject) => {
          const request = tx.objectStore('syncState').get('lastVersion')
          request.onsuccess = () => resolve(request.result)
          request.onerror = () => reject(request.error)
        })
        return result?.value || 0
      } catch (e) {
        console.warn('[MailSync] Failed to load version:', e)
        return 0
      }
    }
  }

  /**
   * Connect to the WebSocket server (or IPC in Electron)
   * @param {string} token - JWT auth token
   */
  async connect(token) {
    this.token = token
    
    if (isElectron()) {
      // In Electron, subscribe to IPC events from main process
      this.connectViaIPC()
    } else {
      // In browser, connect directly via WebSocket
      await this.connectViaWebSocket(token)
    }
  }

  /**
   * Connect via IPC (Electron mode)
   */
  connectViaIPC() {
    isDebugEnabled() && console.log('[MailSync] Connecting via IPC (Electron mode)')
    
    // Clean up any existing subscriptions
    this.disconnectIPC()
    
    // Subscribe to sync events from main process
    const eventMappings = {
      'sync-status': this.handleSyncStatus.bind(this),
      'sync-complete': this.handleSyncComplete.bind(this),
      'sync-error': this.handleSyncError.bind(this),
      'new-email': (payload) => this.processEvent({ type: EventTypes.MESSAGE_NEW, payload }),
      'message-new': (payload) => this.processEvent({ type: EventTypes.MESSAGE_NEW, payload }),
      'message-deleted': (payload) => this.processEvent({ type: EventTypes.MESSAGE_DELETED, payload }),
      'message-moved': (payload) => this.processEvent({ type: EventTypes.MESSAGE_MOVED, payload }),
      'flags-changed': (payload) => this.processEvent({ type: EventTypes.FLAGS_CHANGED, payload }),
      'folder-counts': (payload) => this.processEvent({ type: EventTypes.FOLDER_COUNTS, payload }),
      'conversation-updated': (payload) => this.processEvent({ type: EventTypes.CONVERSATION_UPDATED, payload }),
      'folder-changed': (payload) => this.processEvent({ type: EventTypes.FOLDER_CHANGED, payload }),
      'settings-changed': (payload) => {
        console.log('[MailSync IPC] Received settings-changed:', payload)
        this.processEvent({ type: EventTypes.SETTINGS_CHANGED, payload })
      },
      'pin-changed': (payload) => {
        console.log('[MailSync IPC] Received pin-changed:', payload)
        this.processEvent({ type: EventTypes.PIN_CHANGED, payload })
      },
      'labels-changed': (payload) => {
        console.log('[MailSync IPC] Received labels-changed:', payload)
        this.processEvent({ type: EventTypes.LABELS_CHANGED, payload })
      },
      'calendar-update': (payload) => this.processEvent({ type: 'CALENDAR_UPDATE', payload }),
      'calendar-updated': (payload) => {
        console.log('[MailSync IPC] Received calendar-updated:', payload)
        this.processEvent({ type: EventTypes.CALENDAR_UPDATED, payload })
      },
      'board-update': (payload) => this.processEvent({ type: 'BOARD_UPDATE', payload }),
      'board-updated': (payload) => {
        console.log('[MailSync IPC] Received board-updated:', payload)
        this.processEvent({ type: EventTypes.BOARD_UPDATED, payload })
      },
      'list-updated': (payload) => {
        console.log('[MailSync IPC] Received list-updated:', payload)
        this.processEvent({ type: EventTypes.LIST_UPDATED, payload })
      },
      'card-updated': (payload) => {
        console.log('[MailSync IPC] Received card-updated:', payload)
        this.processEvent({ type: EventTypes.CARD_UPDATED, payload })
      },
      'checklist-updated': (payload) => {
        console.log('[MailSync IPC] Received checklist-updated:', payload)
        this.processEvent({ type: EventTypes.CHECKLIST_UPDATED, payload })
      },
      'todo-updated': (payload) => {
        console.log('[MailSync IPC] Received todo-updated:', payload)
        this.processEvent({ type: EventTypes.TODO_UPDATED, payload })
      },
      // Colleague events
      'colleague-updated': (payload) => this.processEvent({ type: EventTypes.COLLEAGUE_UPDATED, payload }),
      'colleague-group-updated': (payload) => this.processEvent({ type: EventTypes.COLLEAGUE_GROUP_UPDATED, payload }),
      // Campaign events
      'campaign-progress': (payload) => this.processEvent({ type: EventTypes.CAMPAIGN_PROGRESS, payload }),
      'campaign-update': (payload) => this.processEvent({ type: EventTypes.CAMPAIGN_UPDATE, payload }),
      // Chat events
      'chat-message-new': (payload) => this.processEvent({ type: EventTypes.CHAT_MESSAGE_NEW, payload }),
      'chat-message-edited': (payload) => this.processEvent({ type: EventTypes.CHAT_MESSAGE_EDITED, payload }),
      'chat-message-deleted': (payload) => this.processEvent({ type: EventTypes.CHAT_MESSAGE_DELETED, payload }),
      'chat-message-pinned': (payload) => this.processEvent({ type: EventTypes.CHAT_MESSAGE_PINNED, payload }),
      'chat-reaction-added': (payload) => this.processEvent({ type: EventTypes.CHAT_REACTION_ADDED, payload }),
      'chat-reaction-removed': (payload) => this.processEvent({ type: EventTypes.CHAT_REACTION_REMOVED, payload }),
      'chat-typing-start': (payload) => this.processEvent({ type: EventTypes.CHAT_TYPING_START, payload }),
      'chat-typing-stop': (payload) => this.processEvent({ type: EventTypes.CHAT_TYPING_STOP, payload }),
      'chat-read-receipt': (payload) => this.processEvent({ type: EventTypes.CHAT_READ_RECEIPT, payload }),
      'chat-conversation-created': (payload) => this.processEvent({ type: EventTypes.CHAT_CONVERSATION_CREATED, payload }),
      'chat-settings-updated': (payload) => this.processEvent({ type: EventTypes.CHAT_SETTINGS_UPDATED, payload }),
      'chat-view-session-start': (payload) => this.processEvent({ type: EventTypes.CHAT_VIEW_SESSION_START, payload }),
      'chat-view-session-end': (payload) => this.processEvent({ type: EventTypes.CHAT_VIEW_SESSION_END, payload }),
      'chat-view-sync': (payload) => this.processEvent({ type: EventTypes.CHAT_VIEW_SYNC, payload }),
      // Presence events
      'presence-online': (payload) => this.processEvent({ type: EventTypes.PRESENCE_ONLINE, payload }),
      'presence-offline': (payload) => this.processEvent({ type: EventTypes.PRESENCE_OFFLINE, payload }),
      'presence-status-changed': (payload) => this.processEvent({ type: EventTypes.PRESENCE_STATUS_CHANGED, payload }),
      'presence-bulk-update': (payload) => this.processEvent({ type: EventTypes.PRESENCE_BULK_UPDATE, payload }),
      // Call events
      'call-initiate': (payload) => this.processEvent({ type: EventTypes.CALL_INITIATE, payload }),
      'call-ringing': (payload) => this.processEvent({ type: EventTypes.CALL_RINGING, payload }),
      'call-answer': (payload) => this.processEvent({ type: EventTypes.CALL_ANSWER, payload }),
      'call-reject': (payload) => this.processEvent({ type: EventTypes.CALL_REJECT, payload }),
      'call-hangup': (payload) => this.processEvent({ type: EventTypes.CALL_HANGUP, payload }),
      'call-ice-candidate': (payload) => this.processEvent({ type: EventTypes.CALL_ICE_CANDIDATE, payload }),
      'call-media-state': (payload) => this.processEvent({ type: EventTypes.CALL_MEDIA_STATE, payload }),
      'call-participant-joined': (payload) => this.processEvent({ type: EventTypes.CALL_PARTICIPANT_JOINED, payload }),
      'call-participant-left': (payload) => this.processEvent({ type: EventTypes.CALL_PARTICIPANT_LEFT, payload }),
      'call-screen-share-start': (payload) => this.processEvent({ type: EventTypes.CALL_SCREEN_SHARE_START, payload }),
      'call-screen-share-stop': (payload) => this.processEvent({ type: EventTypes.CALL_SCREEN_SHARE_STOP, payload }),
      'call-missed': (payload) => this.processEvent({ type: EventTypes.CALL_MISSED, payload }),
      'call-dismissed': (payload) => this.processEvent({ type: EventTypes.CALL_DISMISSED, payload }),
      'call-sdp-offer': (payload) => this.processEvent({ type: EventTypes.CALL_SDP_OFFER, payload }),
      'call-sdp-answer': (payload) => this.processEvent({ type: EventTypes.CALL_SDP_ANSWER, payload }),
      'online-status': this.handleOnlineStatus.bind(this),
    }
    
    for (const [channel, handler] of Object.entries(eventMappings)) {
      const unsub = window.api.on(channel, handler)
      this.ipcUnsubscribers.push(unsub)
    }
    
    this._pollSyncStatus()
    this._startHealthCheck()
  }

  /**
   * Poll sync-get-status with retries.
   * SyncManager may not be initialized yet when the renderer first loads,
   * or WebSocket may still be connecting. We distinguish between "not
   * initialized yet" (keep waiting) vs "initialized but offline" (give up).
   */
  _pollSyncStatus(attempt = 0) {
    const MAX_INIT_WAIT = 20
    const RETRY_DELAY_MS = 2000

    if (!window.api.sync?.getStatus) {
      this.connectionState.value = ConnectionState.CONNECTED
      return
    }

    if (attempt === 0) {
      this.connectionState.value = ConnectionState.CONNECTING
    }

    window.api.sync.getStatus().then(status => {
      isDebugEnabled() && console.log(`[MailSync] Status poll #${attempt + 1}:`, status)

      if (status?.wsConnected) {
        this.connectionState.value = ConnectionState.CONNECTED
        return
      }

      if (status?.isOnline) {
        this.connectionState.value = ConnectionState.CONNECTED
        return
      }

      if (!status?.initialized && attempt < MAX_INIT_WAIT) {
        this._statusRetryTimer = setTimeout(() => this._pollSyncStatus(attempt + 1), RETRY_DELAY_MS)
        return
      }

      if (status?.initialized && !status?.wsConnected && !status?.isOnline) {
        this.connectionState.value = ConnectionState.DISCONNECTED
        return
      }

      if (attempt < MAX_INIT_WAIT) {
        this._statusRetryTimer = setTimeout(() => this._pollSyncStatus(attempt + 1), RETRY_DELAY_MS)
      }
    }).catch(() => {
      if (attempt < MAX_INIT_WAIT) {
        this._statusRetryTimer = setTimeout(() => this._pollSyncStatus(attempt + 1), RETRY_DELAY_MS)
      } else {
        this.connectionState.value = ConnectionState.CONNECTED
      }
    })
  }

  /**
   * Periodic health check - re-evaluates connection status.
   * First check runs quickly (3s), then every 15s after that.
   */
  _startHealthCheck() {
    this._stopHealthCheck()
    const doCheck = () => {
      if (!window.api?.sync?.getStatus) return
      window.api.sync.getStatus().then(status => {
        if (status?.wsConnected || status?.isOnline) {
          if (this.connectionState.value !== ConnectionState.CONNECTED) {
            isDebugEnabled() && console.log('[MailSync] Health check: restoring CONNECTED state')
            this.connectionState.value = ConnectionState.CONNECTED
          }
        }
      }).catch(() => {})
    }
    setTimeout(doCheck, 3000)
    this._healthCheckTimer = setInterval(doCheck, 15000)
  }

  _stopHealthCheck() {
    if (this._healthCheckTimer) {
      clearInterval(this._healthCheckTimer)
      this._healthCheckTimer = null
    }
  }

  /**
   * Disconnect IPC subscriptions
   */
  disconnectIPC() {
    if (this._statusRetryTimer) {
      clearTimeout(this._statusRetryTimer)
      this._statusRetryTimer = null
    }
    this._stopHealthCheck()
    if (this._wsDisconnectTimer) {
      clearTimeout(this._wsDisconnectTimer)
      this._wsDisconnectTimer = null
    }
    for (const unsub of this.ipcUnsubscribers) {
      if (typeof unsub === 'function') {
        unsub()
      }
    }
    this.ipcUnsubscribers = []
  }

  /**
   * Handle sync status from main process
   */
  handleSyncStatus(status) {
    isDebugEnabled() && console.log('[MailSync] Sync status:', status)
    
    if (status?.wsConnected || status?.isOnline) {
      if (this._wsDisconnectTimer) {
        clearTimeout(this._wsDisconnectTimer)
        this._wsDisconnectTimer = null
      }
      this.connectionState.value = ConnectionState.CONNECTED
    } else if ('wsConnected' in status && !status.wsConnected) {
      if (this._wsDisconnectTimer) clearTimeout(this._wsDisconnectTimer)
      this._wsDisconnectTimer = setTimeout(() => {
        if (this.connectionState.value !== ConnectionState.CONNECTED) return
        this.connectionState.value = ConnectionState.RECONNECTING
      }, 5000)
    }
    
    this.processEvent({ type: EventTypes.SYNC_STATUS, payload: status })
  }

  /**
   * Handle sync complete from main process
   */
  handleSyncComplete(data) {
    isDebugEnabled() && console.log('[MailSync] Sync complete:', data)
    this.processEvent({ type: 'SYNC_COMPLETE', payload: data })
  }

  /**
   * Handle sync error from main process
   */
  handleSyncError(data) {
    console.error('[MailSync] Sync error:', data)
    this.lastError.value = data.error
    this.processEvent({ type: EventTypes.ERROR, payload: data })
  }

  /**
   * Handle online status change from main process
   */
  handleOnlineStatus(isOnline) {
    isDebugEnabled() && console.log('[MailSync] Online status:', isOnline)
    
    if (isOnline) {
      this.connectionState.value = ConnectionState.CONNECTED
    } else {
      this.connectionState.value = ConnectionState.DISCONNECTED
    }
  }

  /**
   * Connect via WebSocket (browser mode)
   */
  async connectViaWebSocket(token) {
    if (this.ws && this.ws.readyState === WebSocket.OPEN) {
      isDebugEnabled() && console.log('[MailSync] Already connected')
      return
    }

    this.connectionState.value = ConnectionState.CONNECTING
    
    // Load last version for event replay
    this.lastEventVersion.value = await this.loadLastVersion()

    try {
      // Connect WITHOUT token in URL (security: tokens in URLs leak through logs/referrer)
      // Token is sent as the first message after connection opens
      this.ws = new WebSocket(WS_URL)
      
      this.ws.onopen = () => this.handleOpen()
      this.ws.onmessage = (event) => this.handleMessage(event)
      this.ws.onclose = (event) => this.handleClose(event)
      this.ws.onerror = (error) => this.handleError(error)
      
    } catch (error) {
      console.error('[MailSync] Connection failed:', error)
      this.lastError.value = error.message
      this.scheduleReconnect()
    }
  }

  /**
   * Disconnect from the server
   */
  disconnect() {
    if (isElectron()) {
      this.disconnectIPC()
      this.connectionState.value = ConnectionState.DISCONNECTED
      isDebugEnabled() && console.log('[MailSync] Disconnected (IPC)')
    } else {
      this.stopHeartbeat()
      this.clearReconnectTimer()
      
      if (this.ws) {
        this.ws.close(1000, 'Client disconnecting')
        this.ws = null
      }
      
      this.connectionState.value = ConnectionState.DISCONNECTED
      isDebugEnabled() && console.log('[MailSync] Disconnected')
    }
  }

  /**
   * Handle WebSocket open (browser mode)
   */
  handleOpen() {
    isDebugEnabled() && console.log('[MailSync] Connected, authenticating...')
    this.connectionState.value = ConnectionState.CONNECTED
    this.reconnectAttempts = 0
    this.lastError.value = null
    
    // SECURITY: Send auth token as the first message instead of URL query param
    this.send({ type: 'AUTHENTICATE', token: this.token })
    
    this.startHeartbeat()
    
    if (this.lastEventVersion.value > 0) {
      this.requestEventReplay(this.lastEventVersion.value)
    }
  }

  /**
   * Handle incoming WebSocket message (browser mode)
   */
  handleMessage(event) {
    try {
      const data = JSON.parse(event.data)
      
      if (data.type === 'PONG') {
        return
      }
      
      if (data.eventId && this.processedEventIds.has(data.eventId)) {
        isDebugEnabled() && console.log('[MailSync] Skipping duplicate event:', data.eventId)
        return
      }
      
      this.processEvent(data)
      
      if (data.eventId) {
        this.markEventProcessed(data.eventId)
      }
      
      if (data.version && data.version > this.lastEventVersion.value) {
        this.lastEventVersion.value = data.version
        this.saveLastVersion(data.version)
      }
      
    } catch (error) {
      console.error('[MailSync] Error handling message:', error)
    }
  }

  /**
   * Process an event from the server
   */
  processEvent(event) {
    // Always log board-related events for debugging
    if (event.type?.includes('BOARD') || event.type?.includes('CARD') || 
        event.type?.includes('LIST') || event.type?.includes('CHECKLIST') ||
        event.type?.includes('TODO')) {
      console.log('[MailSync] Board/Todo Event:', event.type, event.payload)
    } else {
      isDebugEnabled() && console.log('[MailSync] Event:', event.type, event.payload)
    }
    
    switch (event.type) {
      case EventTypes.CONNECTED:
        this.serverTime.value = event.payload?.serverTime
        if (event.payload?.currentVersion > this.lastEventVersion.value) {
          this.requestEventReplay(this.lastEventVersion.value)
        }
        break
        
      case EventTypes.SYNC_STATUS:
        isDebugEnabled() && console.log('[MailSync] Sync status:', event.payload)
        break
        
      case EventTypes.ERROR:
        console.error('[MailSync] Server error:', event.payload)
        this.lastError.value = event.payload?.message
        break
    }
    
    const handlers = this.eventHandlers.get(event.type)
    if (handlers) {
      for (const handler of handlers) {
        try {
          handler(event.payload, event)
        } catch (error) {
          console.error(`[MailSync] Handler error for ${event.type}:`, error)
        }
      }
    }
    
    const wildcardHandlers = this.eventHandlers.get('*')
    if (wildcardHandlers) {
      for (const handler of wildcardHandlers) {
        try {
          handler(event.payload, event)
        } catch (error) {
          console.error('[MailSync] Wildcard handler error:', error)
        }
      }
    }
  }

  /**
   * Mark an event as processed (idempotency tracking)
   */
  markEventProcessed(eventId) {
    this.processedEventIds.add(eventId)
    this.processedEventIdList.push(eventId)
    
    if (this.processedEventIdList.length > MAX_PROCESSED_EVENTS) {
      const oldEventId = this.processedEventIdList.shift()
      this.processedEventIds.delete(oldEventId)
    }
  }

  /**
   * Handle WebSocket close (browser mode)
   */
  handleClose(event) {
    isDebugEnabled() && console.log('[MailSync] Connection closed:', event.code, event.reason)
    this.stopHeartbeat()
    
    if (event.code === 4001) {
      this.connectionState.value = ConnectionState.DISCONNECTED
      this.lastError.value = 'Unauthorized'
    } else if (event.code !== 1000) {
      this.connectionState.value = ConnectionState.RECONNECTING
      this.scheduleReconnect()
    } else {
      this.connectionState.value = ConnectionState.DISCONNECTED
    }
  }

  /**
   * Handle WebSocket error (browser mode)
   */
  handleError(error) {
    console.error('[MailSync] WebSocket error:', error)
    this.lastError.value = 'Connection error'
  }

  /**
   * Schedule a reconnection attempt (browser mode)
   */
  scheduleReconnect() {
    if (isElectron()) return // Main process handles reconnection
    
    this.clearReconnectTimer()
    
    const delay = Math.min(
      RECONNECT_INITIAL_DELAY * Math.pow(RECONNECT_MULTIPLIER, this.reconnectAttempts),
      RECONNECT_MAX_DELAY
    )
    
    console.log(`[MailSync] Reconnecting in ${delay}ms (attempt ${this.reconnectAttempts + 1})`)
    
    this.reconnectTimer = setTimeout(() => {
      this.reconnectAttempts++
      if (this.token) {
        this.connect(this.token)
      }
    }, delay)
  }

  clearReconnectTimer() {
    if (this.reconnectTimer) {
      clearTimeout(this.reconnectTimer)
      this.reconnectTimer = null
    }
  }

  startHeartbeat() {
    if (isElectron()) return
    
    this.stopHeartbeat()
    
    this.heartbeatTimer = setInterval(() => {
      if (this.ws && this.ws.readyState === WebSocket.OPEN) {
        this.send({ type: ClientMessageTypes.PING })
      }
    }, HEARTBEAT_INTERVAL)
  }

  stopHeartbeat() {
    if (this.heartbeatTimer) {
      clearInterval(this.heartbeatTimer)
      this.heartbeatTimer = null
    }
  }

  send(message) {
    if (isElectron()) {
      // In Electron, send via IPC to main process
      window.api.send('sync-request', message)
      return true
    }
    
    if (this.ws && this.ws.readyState === WebSocket.OPEN) {
      this.ws.send(JSON.stringify(message))
      return true
    }
    return false
  }

  requestEventReplay(sinceVersion) {
    isDebugEnabled() && console.log('[MailSync] Requesting events since version:', sinceVersion)
    this.send({
      type: ClientMessageTypes.REPLAY_EVENTS,
      sinceVersion,
    })
  }

  subscribeToFolder(folder, password = null, options = {}) {
    this.send({
      type: ClientMessageTypes.SUBSCRIBE_FOLDER,
      folder,
      password,
      options,
    })
  }

  unsubscribeFromFolder(folder) {
    this.send({
      type: ClientMessageTypes.UNSUBSCRIBE_FOLDER,
      folder,
    })
  }

  acknowledgeEvent(version) {
    this.send({
      type: ClientMessageTypes.ACK_EVENT,
      version,
    })
  }

  /**
   * Subscribe to presence updates for organization
   */
  subscribeToPresence() {
    this.send({
      type: ClientMessageTypes.SUBSCRIBE_PRESENCE,
    })
  }

  /**
   * Update own presence status
   * @param {string} status - 'active', 'away', or 'do_not_disturb'
   */
  updatePresenceStatus(status) {
    this.send({
      type: ClientMessageTypes.PRESENCE_UPDATE,
      status,
    })
  }

  /**
   * Send presence heartbeat (keeps status as active)
   */
  sendPresenceHeartbeat() {
    this.send({
      type: ClientMessageTypes.PRESENCE_HEARTBEAT,
    })
  }

  /**
   * Subscribe to specific users' presence (cross-domain chat partners)
   * @param {string[]} emails - Array of email addresses to watch
   */
  subscribeToPresenceUsers(emails) {
    if (!emails || emails.length === 0) return
    this.send({
      type: 'SUBSCRIBE_PRESENCE_USERS',
      emails,
    })
  }

  on(eventType, handler) {
    if (!this.eventHandlers.has(eventType)) {
      this.eventHandlers.set(eventType, new Set())
    }
    this.eventHandlers.get(eventType).add(handler)
    
    return () => {
      const handlers = this.eventHandlers.get(eventType)
      if (handlers) {
        handlers.delete(handler)
      }
    }
  }

  off(eventType, handler) {
    const handlers = this.eventHandlers.get(eventType)
    if (handlers) {
      handlers.delete(handler)
    }
  }

  getState() {
    return readonly(this.connectionState)
  }

  isConnected() {
    return this.connectionState.value === ConnectionState.CONNECTED
  }

  getLastError() {
    return readonly(this.lastError)
  }
}

// Singleton instance
let instance = null

export function useMailSyncSocket() {
  if (!instance) {
    instance = new MailSyncSocket()
  }
  return instance
}

export function useMailSync() {
  const socket = useMailSyncSocket()
  
  return {
    connectionState: socket.getState(),
    lastError: socket.getLastError(),
    isConnected: computed(() => socket.isConnected()),
    
    // Methods
    connect: (token) => socket.connect(token),
    disconnect: () => socket.disconnect(),
    send: (message) => socket.send(message),
    subscribeToFolder: (folder, password, options) => socket.subscribeToFolder(folder, password, options),
    unsubscribeFromFolder: (folder) => socket.unsubscribeFromFolder(folder),
    on: (eventType, handler) => socket.on(eventType, handler),
    off: (eventType, handler) => socket.off(eventType, handler),
    // Presence
    subscribeToPresence: () => socket.subscribeToPresence(),
    subscribeToPresenceUsers: (emails) => socket.subscribeToPresenceUsers(emails),
    updatePresenceStatus: (status) => socket.updatePresenceStatus(status),
    sendPresenceHeartbeat: () => socket.sendPresenceHeartbeat(),
    requestPresenceRefresh: () => socket.subscribeToPresence(),
  }
}
