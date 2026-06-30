/**
 * Mail Sync WebSocket Client
 * 
 * Connects to the Mail Sync WebSocket server for real-time email
 * synchronization. Handles reconnection, event replay, and 
 * idempotent event processing.
 */

import { ref, computed, readonly } from 'vue'
import { isDebugEnabled } from '@/utils/debug'
import { getWsUrl } from '@/services/serverRegistry'

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
  // Drive events (real-time cross-device file/folder sync)
  DRIVE_FILE_CREATED: 'DRIVE_FILE_CREATED',
  DRIVE_FILE_UPDATED: 'DRIVE_FILE_UPDATED',
  DRIVE_FILE_DELETED: 'DRIVE_FILE_DELETED',
  DRIVE_FOLDER_CREATED: 'DRIVE_FOLDER_CREATED',
  DRIVE_FOLDER_DELETED: 'DRIVE_FOLDER_DELETED',
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
  // Mood Board collaboration events
  MOOD_BOARD_ITEM_CREATED: 'MOOD_BOARD_ITEM_CREATED',
  MOOD_BOARD_ITEM_UPDATED: 'MOOD_BOARD_ITEM_UPDATED',
  MOOD_BOARD_ITEM_DELETED: 'MOOD_BOARD_ITEM_DELETED',
  MOOD_BOARD_ITEMS_DELETED: 'MOOD_BOARD_ITEMS_DELETED',
  MOOD_BOARD_ITEMS_MOVED: 'MOOD_BOARD_ITEMS_MOVED',
  MOOD_BOARD_UPDATED: 'MOOD_BOARD_UPDATED',
  MOOD_BOARD_ITEMS_UPDATED: 'MOOD_BOARD_ITEMS_UPDATED',
  MOOD_BOARD_CONNECTION_CREATED: 'MOOD_BOARD_CONNECTION_CREATED',
  MOOD_BOARD_CONNECTIONS_BATCH_CREATED: 'MOOD_BOARD_CONNECTIONS_BATCH_CREATED',
  MOOD_BOARD_CONNECTION_DELETED: 'MOOD_BOARD_CONNECTION_DELETED',
  MOOD_BOARD_ACTIVITY: 'MOOD_BOARD_ACTIVITY',
  MOOD_BOARD_CURSOR: 'MOOD_BOARD_CURSOR',
  MOOD_BOARD_PRESENCE_JOIN: 'MOOD_BOARD_PRESENCE_JOIN',
  MOOD_BOARD_PRESENCE_LEAVE: 'MOOD_BOARD_PRESENCE_LEAVE',
  MOOD_BOARD_COMMENT_ADDED: 'MOOD_BOARD_COMMENT_ADDED',
  MOOD_BOARD_COMMENT_DELETED: 'MOOD_BOARD_COMMENT_DELETED',
  MOOD_BOARD_THREAD_DELETED: 'MOOD_BOARD_THREAD_DELETED',
  MOOD_BOARD_THREAD_RESOLVED: 'MOOD_BOARD_THREAD_RESOLVED',
  // Project Hub events
  CARD_COMMENT_UPDATED: 'CARD_COMMENT_UPDATED',
  CARD_COMMENT_REACTION: 'CARD_COMMENT_REACTION',
  CARD_ASSIGNEE_ADDED: 'CARD_ASSIGNEE_ADDED',
  CARD_ASSIGNEE_UPDATED: 'CARD_ASSIGNEE_UPDATED',
  CARD_ASSIGNEE_REMOVED: 'CARD_ASSIGNEE_REMOVED',
  CARD_SUBTASK_CREATED: 'CARD_SUBTASK_CREATED',
  CARD_SUBTASK_UPDATED: 'CARD_SUBTASK_UPDATED',
  CARD_WORK_SESSION: 'CARD_WORK_SESSION',
  SPACE_UPDATED: 'SPACE_UPDATED',
  FOLDER_UPDATED: 'FOLDER_UPDATED',
  CARD_DEPENDENCY_ADDED: 'CARD_DEPENDENCY_ADDED',
  CARD_DEPENDENCY_REMOVED: 'CARD_DEPENDENCY_REMOVED',
  // Presence events (online/away/offline status)
  PRESENCE_ONLINE: 'PRESENCE_ONLINE',
  PRESENCE_OFFLINE: 'PRESENCE_OFFLINE',
  PRESENCE_STATUS_CHANGED: 'PRESENCE_STATUS_CHANGED',
  PRESENCE_BULK_UPDATE: 'PRESENCE_BULK_UPDATE',
  // Notification events (server-side notification creation)
  NOTIFICATION_CREATED: 'NOTIFICATION_CREATED',
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
  CALL_ACTIVE_STATUS: 'CALL_ACTIVE_STATUS',
  // Huddle events (persistent audio rooms)
  HUDDLE_PARTICIPANT_JOINED: 'HUDDLE_PARTICIPANT_JOINED',
  HUDDLE_PARTICIPANT_LEFT: 'HUDDLE_PARTICIPANT_LEFT',
  HUDDLE_ENDED: 'HUDDLE_ENDED',
  HUDDLE_SDP_OFFER: 'HUDDLE_SDP_OFFER',
  HUDDLE_SDP_ANSWER: 'HUDDLE_SDP_ANSWER',
  HUDDLE_ICE_CANDIDATE: 'HUDDLE_ICE_CANDIDATE',
  HUDDLE_MEDIA_STATE: 'HUDDLE_MEDIA_STATE',
  HUDDLE_SPEAKING: 'HUDDLE_SPEAKING',
  // Internal client-side events
  SYNC_GAP_DETECTED: 'SYNC_GAP_DETECTED',
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
  CALL_ACTIVE_QUERY: 'CALL_ACTIVE_QUERY',
}

// WebSocket URL is resolved at connect time (not module load) so the native
// apps can target the correct per-deployment server (wss://email.<domain>)
// once the user's email domain is known at login. A dev override via
// VITE_MAILSYNC_WS_URL (e.g. ws://localhost:3001/mailsync_ws from
// .env.development.local) still takes priority for local development.
function resolveWsUrl() {
  const override = import.meta.env.VITE_MAILSYNC_WS_URL
  if (override) {
    // SAFETY GUARD: a production build must never connect to a developer
    // machine. If a localhost override leaks into a production bundle, drop it
    // and fall back to the deployment-derived URL, logging loudly.
    if (import.meta.env.PROD && /(^|\/\/)localhost|127\.0\.0\.1|0\.0\.0\.0/i.test(override)) {
      console.error(
        `[MailSync] Production build is misconfigured: VITE_MAILSYNC_WS_URL was baked in as "${override}". ` +
        `Falling back to the deployment-derived WebSocket URL. ` +
        `Fix: ensure email/frontend/.env.local does not exist before running 'npm run build'. ` +
        `Use .env.development.local for local-only overrides — Vite ignores that file for builds.`
      )
      return getWsUrl()
    }
    return override
  }
  return getWsUrl()
}

// Reconnection settings
const RECONNECT_INITIAL_DELAY = 1000
const RECONNECT_MAX_DELAY = 30000
const RECONNECT_MULTIPLIER = 2

// Heartbeat settings
const HEARTBEAT_INTERVAL = 25000

// Grace period after tab comes back to foreground before stale-checking
// (background tabs throttle timers, so lastPongReceived will be stale)
const VISIBILITY_GRACE_MS = 10000

// Idempotency: max processed eventIds to track
const MAX_PROCESSED_EVENTS = 1000

class MailSyncSocket {
  constructor() {
    // WebSocket instance
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
    this.processedEventIdList = []  // For LRU eviction
    
    // Reconnection state
    this.reconnectAttempts = 0
    this.reconnectTimer = null
    
    // Heartbeat timer
    this.heartbeatTimer = null
    
    // Track last pong received for stale connection detection
    this.lastPongReceived = Date.now()
    
    // Track page visibility — background tabs throttle timers,
    // so we must not kill the connection based on stale pong timestamps
    // when the page was hidden. The native WebSocket pong keeps the
    // server-side connection alive even when JS timers are frozen.
    this.pageHidden = document.hidden ?? false
    this.visibilityRestoredAt = 0   // timestamp when tab last became visible
    this._onVisibilityChange = this._handleVisibilityChange.bind(this)
    document.addEventListener('visibilitychange', this._onVisibilityChange)
    
    // Auth token
    this.token = null
    
    // Guard flag for 4001 token refresh (prevent infinite loops)
    this._refreshingAfter4001 = false
    
    // IndexedDB for persistence
    this.dbPromise = this.initIndexedDB()
  }

  /**
   * Handle page visibility changes.
   * When the tab comes back to the foreground:
   * - If the WebSocket is still open, send an immediate PING and reset
   *   the pong timestamp so the stale-detection doesn't false-positive.
   * - If the WebSocket was closed (proxy/server dropped it while hidden),
   *   trigger an immediate reconnect instead of waiting for the throttled timer.
   */
  _handleVisibilityChange() {
    this.pageHidden = document.hidden

    if (!document.hidden) {
      // Tab just became visible
      this.visibilityRestoredAt = Date.now()

      if (this.ws && this.ws.readyState === WebSocket.OPEN) {
        // Connection still open — reset pong timer and send a health-check PING
        this.lastPongReceived = Date.now()
        this.send({ type: 'PING' })
        isDebugEnabled() && console.log('[MailSync] Tab visible — sent health-check PING')
      } else if (this.token && this.connectionState.value !== ConnectionState.CONNECTING) {
        // Connection died while hidden — reconnect immediately
        console.warn('[MailSync] Tab visible — WebSocket was closed while hidden, reconnecting now')
        this.connectionState.value = ConnectionState.RECONNECTING
        this.clearReconnectTimer()
        this.reconnectAttempts = 0
        this.connect(this.token)
      }
    }
  }

  /**
   * Initialize IndexedDB for persisting last version and processed events
   */
  async initIndexedDB() {
    return new Promise((resolve, reject) => {
      const request = indexedDB.open('mailsync', 1)
      
      request.onerror = () => reject(request.error)
      request.onsuccess = () => resolve(request.result)
      
      request.onupgradeneeded = (event) => {
        const db = event.target.result
        
        // Store for sync state
        if (!db.objectStoreNames.contains('syncState')) {
          db.createObjectStore('syncState', { keyPath: 'key' })
        }
      }
    })
  }

  /**
   * Save last processed version to IndexedDB
   */
  async saveLastVersion(version) {
    try {
      const db = await this.dbPromise
      const tx = db.transaction('syncState', 'readwrite')
      tx.objectStore('syncState').put({ key: 'lastVersion', value: version })
    } catch (e) {
      console.warn('[MailSync] Failed to save version:', e)
    }
  }

  /**
   * Load last processed version from IndexedDB
   */
  async loadLastVersion() {
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

  /**
   * Connect to the WebSocket server
   * @param {string} token - JWT auth token
   */
  async connect(token) {
    // Guard: if already OPEN, do nothing
    if (this.ws && this.ws.readyState === WebSocket.OPEN) {
      isDebugEnabled() && console.log('[MailSync] Already connected')
      return
    }

    // Guard: if a previous WS is still CONNECTING, close it first to avoid
    // orphaned connections. Orphan WebSockets whose handlers reference this
    // singleton can corrupt state (e.g. an orphan's 4001 close sets the
    // singleton to DISCONNECTED even though the real connection is fine).
    if (this.ws && this.ws.readyState === WebSocket.CONNECTING) {
      isDebugEnabled() && console.log('[MailSync] Closing stale CONNECTING socket before new attempt')
      try {
        // Null out handlers FIRST so the close event doesn't trigger handleClose
        this.ws.onopen = null
        this.ws.onclose = null
        this.ws.onerror = null
        this.ws.onmessage = null
        this.ws.close()
      } catch (e) { /* ignore */ }
      this.ws = null
    }

    this.token = token
    this.connectionState.value = ConnectionState.CONNECTING
    
    // Load last version for event replay
    this.lastEventVersion.value = await this.loadLastVersion()

    // After the async gap, another connect() may have already created a new WS.
    // Check again to avoid overwriting it.
    if (this.ws && (this.ws.readyState === WebSocket.OPEN || this.ws.readyState === WebSocket.CONNECTING)) {
      isDebugEnabled() && console.log('[MailSync] Another connection was created during await, aborting this one')
      return
    }

    const wsUrl = resolveWsUrl()
    if (!wsUrl) {
      // No deployment resolved yet (native, pre-login). Skip; a later connect()
      // after login will have the server base set.
      isDebugEnabled() && console.log('[MailSync] No server resolved yet — skipping connect')
      return
    }

    isDebugEnabled() && console.log(`[MailSync] Connecting to ${wsUrl}...`)

    try {
      // Connect WITHOUT token in URL (security: tokens in URLs leak through logs/referrer)
      // Token is sent as the first message after connection opens
      const ws = new WebSocket(wsUrl)
      this.ws = ws
      
      // Pin all event handlers to THIS specific WebSocket instance.
      // If this ws gets superseded by a newer connect() call, the orphan's
      // events are silently ignored — they can't corrupt the singleton state.
      ws.onopen = () => {
        if (this.ws !== ws) {
          isDebugEnabled() && console.log('[MailSync] Orphan WS opened — closing it')
          try { ws.close(1000, 'Superseded') } catch (e) { /* ignore */ }
          return
        }
        this.handleOpen()
      }
      ws.onmessage = (event) => {
        if (this.ws !== ws) return // orphan — ignore
        this.handleMessage(event)
      }
      ws.onclose = (event) => {
        if (this.ws !== ws) {
          isDebugEnabled() && console.log('[MailSync] Orphan WS closed — ignoring (not current)')
          return
        }
        this.handleClose(event)
      }
      ws.onerror = (error) => {
        if (this.ws !== ws) return // orphan — ignore
        this.handleError(error)
      }
      
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
    this.stopHeartbeat()
    this.clearReconnectTimer()
    
    if (this.ws) {
      // Null out handlers first to prevent close event from triggering reconnect
      const ws = this.ws
      this.ws = null
      try {
        ws.onopen = null
        ws.onclose = null
        ws.onerror = null
        ws.onmessage = null
        ws.close(1000, 'Client disconnecting')
      } catch (e) { /* ignore */ }
    }
    
    this.connectionState.value = ConnectionState.DISCONNECTED
    isDebugEnabled() && console.log('[MailSync] Disconnected')
  }

  /**
   * Handle WebSocket open
   */
  handleOpen() {
    isDebugEnabled() && console.log('[MailSync] WebSocket connected, authenticating...')
    this.connectionState.value = ConnectionState.CONNECTED
    this.reconnectAttempts = 0
    this.lastError.value = null
    
    // SECURITY: Send auth token as the first message instead of URL query param
    this.send({ type: 'AUTHENTICATE', token: this.token })
    
    // Start heartbeat
    this.startHeartbeat()
    
    // Subscribe to ALL events (boards, calendars, todos, etc.) for real-time sync
    // This is essential - without this, the server only sends email folder events
    this.send({ type: ClientMessageTypes.SUBSCRIBE_ALL })
    isDebugEnabled() && console.log('[MailSync] Subscribed to ALL events')
    
    // Explicitly subscribe to presence (belt-and-suspenders with SUBSCRIBE_ALL)
    // This ensures we get the PRESENCE_BULK_UPDATE regardless of event handler timing
    this.send({ type: ClientMessageTypes.SUBSCRIBE_PRESENCE })
    
    // Request missed events if we have a previous version
    if (this.lastEventVersion.value > 0) {
      this.requestEventReplay(this.lastEventVersion.value)
    }
  }

  /**
   * Handle incoming WebSocket message
   */
  handleMessage(event) {
    try {
      const data = JSON.parse(event.data)
      
      // Handle pong (heartbeat response) - track for stale connection detection
      if (data.type === 'PONG') {
        this.lastPongReceived = Date.now()
        return
      }
      
      // Check for idempotency - skip if we've already processed this event
      if (data.eventId && this.processedEventIds.has(data.eventId)) {
        isDebugEnabled() && console.log('[MailSync] Skipping duplicate event:', data.eventId)
        return
      }
      
      // Process the event
      this.processEvent(data)
      
      // Mark as processed (for idempotency)
      if (data.eventId) {
        this.markEventProcessed(data.eventId)
      }
      
      // Update last version
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
    // Always log call-related and board-related events for debugging
    // Skip high-frequency cursor events to avoid console spam
    if (event.type?.includes('CURSOR') || event.type?.includes('PRESENCE')) {
      // High-frequency events — only log in debug mode
      isDebugEnabled() && console.log('[MailSync] Event:', event.type)
    } else if (event.type?.includes('CALL_')) {
      isDebugEnabled() && console.log('[MailSync] CALL Event:', event.type, event.payload)
    } else if (event.type?.includes('BOARD') || event.type?.includes('CARD') || 
        event.type?.includes('LIST') || event.type?.includes('CHECKLIST') ||
        event.type?.includes('TODO')) {
      isDebugEnabled() && console.log('[MailSync] Board/Todo Event:', event.type, event.payload)
    } else {
      isDebugEnabled() && console.log('[MailSync] Event:', event.type, event.payload)
    }
    
    // Handle special events
    switch (event.type) {
      case EventTypes.CONNECTED:
        this.serverTime.value = event.payload?.serverTime
        // Update version from server if higher
        if (event.payload?.currentVersion > this.lastEventVersion.value) {
          this.requestEventReplay(this.lastEventVersion.value)
        }
        break
        
      case EventTypes.SYNC_STATUS:
        isDebugEnabled() && console.log('[MailSync] Sync status:', event.payload)
        // Detect event buffer gap: if replay completed but the first replayed event
        // doesn't continue from our last known version, events were lost (buffer expired).
        // Dispatch a gap event so stores can trigger a full refresh.
        if (event.payload?.status === 'replay_complete' && event.payload?.gapDetected) {
          console.warn('[MailSync] Event gap detected — buffer could not cover disconnection period. Stores should refresh.')
          this.dispatchGapDetected()
        }
        break
        
      case EventTypes.ERROR:
        console.error('[MailSync] Server error:', event.payload)
        this.lastError.value = event.payload?.message
        break
    }
    
    // Dispatch to registered handlers
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
    
    // Also dispatch to wildcard handlers
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
    
    // LRU eviction
    if (this.processedEventIdList.length > MAX_PROCESSED_EVENTS) {
      const oldEventId = this.processedEventIdList.shift()
      this.processedEventIds.delete(oldEventId)
    }
  }

  /**
   * Handle WebSocket close
   */
  handleClose(event) {
    console.warn(`[MailSync] Connection closed: code=${event.code} reason="${event.reason || 'none'}"`)
    this.stopHeartbeat()
    
    if (event.code === 4001) {
      // Auth failed — try to get a fresh token and reconnect once before giving up.
      // This handles: expired JWT, token rotated by another tab, race conditions.
      console.warn('[MailSync] Auth failed (4001) — attempting token refresh before giving up...')
      this.connectionState.value = ConnectionState.RECONNECTING
      this.lastError.value = 'Auth failed - refreshing token'
      this._attemptTokenRefreshReconnect()
    } else if (event.code !== 1000) {
      // Abnormal close - try to reconnect
      this.connectionState.value = ConnectionState.RECONNECTING
      this.scheduleReconnect()
    } else {
      this.connectionState.value = ConnectionState.DISCONNECTED
    }
  }

  /**
   * Attempt to refresh token and reconnect after a 4001 close.
   * Tries once: get fresh token from auth store, then reconnect.
   * If that also fails, give up (user must re-login).
   */
  async _attemptTokenRefreshReconnect() {
    // Prevent infinite loops — only try once per disconnect
    if (this._refreshingAfter4001) {
      console.error('[MailSync] Token refresh already in progress — giving up.')
      this.connectionState.value = ConnectionState.DISCONNECTED
      this.lastError.value = 'Unauthorized'
      this._refreshingAfter4001 = false
      return
    }
    this._refreshingAfter4001 = true

    try {
      // Dynamic import to avoid circular dependency
      const { useAuthStore } = await import('@/stores/auth')
      const { getToken } = await import('@/services/tokenStorage')
      const auth = useAuthStore()
      
      // Check if the auth store or storage already has a newer token
      // (e.g. another tab refreshed and synced via BroadcastChannel)
      const storedToken = getToken('webmail_token')
      if (storedToken && storedToken !== this.token) {
        console.log('[MailSync] Found newer token in storage — reconnecting with it')
        this.token = storedToken
        this._refreshingAfter4001 = false
        setTimeout(() => this.connect(this.token), 500)
        return
      }
      
      // Try refreshing via auth store
      if (auth.checkAuth) {
        const ok = await auth.checkAuth()
        if (ok && auth.token) {
          console.log('[MailSync] Token refreshed via auth store — reconnecting')
          this.token = auth.token
          this._refreshingAfter4001 = false
          setTimeout(() => this.connect(this.token), 500)
          return
        }
      }
    } catch (e) {
      console.warn('[MailSync] Token refresh attempt failed:', e)
    }

    // Give up — user must re-login
    console.error('[MailSync] Could not refresh token — user must re-login.')
    this.connectionState.value = ConnectionState.DISCONNECTED
    this.lastError.value = 'Unauthorized'
    this._refreshingAfter4001 = false
  }

  /**
   * Handle WebSocket error
   */
  handleError(error) {
    console.error('[MailSync] WebSocket error — server may be down or proxy broken:', error)
    this.lastError.value = 'Connection error'
  }

  /**
   * Schedule a reconnection attempt
   */
  scheduleReconnect() {
    this.clearReconnectTimer()
    
    const delay = Math.min(
      RECONNECT_INITIAL_DELAY * Math.pow(RECONNECT_MULTIPLIER, this.reconnectAttempts),
      RECONNECT_MAX_DELAY
    )
    
    console.warn(`[MailSync] Reconnecting in ${Math.round(delay/1000)}s (attempt ${this.reconnectAttempts + 1})`)
    
    this.reconnectTimer = setTimeout(() => {
      this.reconnectAttempts++
      if (this.token) {
        this.connect(this.token)
      }
    }, delay)
  }

  /**
   * Clear reconnection timer
   */
  clearReconnectTimer() {
    if (this.reconnectTimer) {
      clearTimeout(this.reconnectTimer)
      this.reconnectTimer = null
    }
  }

  /**
   * Start heartbeat pings with stale connection detection.
   * If no PONG is received within 2x the heartbeat interval,
   * the connection is considered dead and force-closed to trigger reconnect.
   * 
   * IMPORTANT: When the page is hidden (background tab), browsers heavily
   * throttle setInterval (Chrome: ~1 min; after 5 min may suspend entirely).
   * The native WebSocket ping/pong (handled by the browser, NOT by JS) keeps
   * the server-side connection alive. So we MUST NOT kill the connection
   * based on a stale lastPongReceived while the page is hidden or has
   * only just become visible.
   */
  startHeartbeat() {
    this.stopHeartbeat()
    this.lastPongReceived = Date.now()
    
    this.heartbeatTimer = setInterval(() => {
      if (this.ws && this.ws.readyState === WebSocket.OPEN) {
        // Skip stale-detection while page is hidden — timers are throttled
        // and lastPongReceived won't be accurate. The native WS pong keeps
        // the server connection alive independently.
        if (this.pageHidden) {
          return
        }

        // Also skip stale-detection for a grace period after the tab just
        // became visible, because the first timer tick after un-throttling
        // will see a very old lastPongReceived even though the connection is fine.
        const timeSinceVisible = Date.now() - this.visibilityRestoredAt
        if (timeSinceVisible < VISIBILITY_GRACE_MS) {
          // Send a PING to get a fresh PONG, but don't kill the connection yet
          this.send({ type: ClientMessageTypes.PING })
          return
        }

        // Check if the server has responded to our pings recently
        const timeSinceLastPong = Date.now() - this.lastPongReceived
        if (timeSinceLastPong > HEARTBEAT_INTERVAL * 2) {
          console.warn(`[MailSync] No PONG received in ${Math.round(timeSinceLastPong / 1000)}s — connection likely dead, forcing reconnect`)
          this.ws.close(4000, 'Heartbeat timeout')
          return
        }
        
        this.send({ type: ClientMessageTypes.PING })
      }
    }, HEARTBEAT_INTERVAL)
  }

  /**
   * Stop heartbeat pings
   */
  stopHeartbeat() {
    if (this.heartbeatTimer) {
      clearInterval(this.heartbeatTimer)
      this.heartbeatTimer = null
    }
  }

  /**
   * Send a message to the server
   */
  send(message) {
    if (this.ws && this.ws.readyState === WebSocket.OPEN) {
      this.ws.send(JSON.stringify(message))
      return true
    }
    return false
  }

  /**
   * Dispatch a gap detected event to all registered handlers
   * so stores can trigger full data refreshes
   */
  dispatchGapDetected() {
    const handlers = this.eventHandlers.get('SYNC_GAP_DETECTED')
    if (handlers) {
      for (const handler of handlers) {
        try {
          handler({}, { type: 'SYNC_GAP_DETECTED', payload: {} })
        } catch (error) {
          console.error('[MailSync] Gap handler error:', error)
        }
      }
    }
  }

  /**
   * Request event replay from a specific version
   */
  requestEventReplay(sinceVersion) {
    isDebugEnabled() && console.log('[MailSync] Requesting events since version:', sinceVersion)
    this.send({
      type: ClientMessageTypes.REPLAY_EVENTS,
      sinceVersion,
    })
  }

  /**
   * Subscribe to folder updates (triggers IMAP IDLE on server)
   */
  subscribeToFolder(folder, password = null, options = {}) {
    this.send({
      type: ClientMessageTypes.SUBSCRIBE_FOLDER,
      folder,
      password,
      options,
    })
  }

  /**
   * Unsubscribe from folder updates
   */
  unsubscribeFromFolder(folder) {
    this.send({
      type: ClientMessageTypes.UNSUBSCRIBE_FOLDER,
      folder,
    })
  }

  /**
   * Acknowledge an event (for reliable delivery tracking)
   */
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
  sendPresenceHeartbeat(currentView = null) {
    const msg = { type: ClientMessageTypes.PRESENCE_HEARTBEAT }
    if (currentView) msg.currentView = currentView
    this.send(msg)
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

  /**
   * Register an event handler
   * @param {string} eventType - Event type or '*' for all events
   * @param {function} handler - Handler function
   * @returns {function} Unsubscribe function
   */
  on(eventType, handler) {
    if (!this.eventHandlers.has(eventType)) {
      this.eventHandlers.set(eventType, new Set())
    }
    this.eventHandlers.get(eventType).add(handler)
    
    // Return unsubscribe function
    return () => {
      const handlers = this.eventHandlers.get(eventType)
      if (handlers) {
        handlers.delete(handler)
      }
    }
  }

  /**
   * Remove an event handler
   */
  off(eventType, handler) {
    const handlers = this.eventHandlers.get(eventType)
    if (handlers) {
      handlers.delete(handler)
    }
  }

  /**
   * Get current connection state (reactive)
   */
  getState() {
    return readonly(this.connectionState)
  }

  /**
   * Check if connected
   */
  isConnected() {
    return this.connectionState.value === ConnectionState.CONNECTED
  }

  /**
   * Get last error (reactive)
   */
  getLastError() {
    return readonly(this.lastError)
  }
}

// Singleton instance
let instance = null

/**
 * Get the MailSyncSocket singleton instance
 * @returns {MailSyncSocket}
 */
export function useMailSyncSocket() {
  if (!instance) {
    instance = new MailSyncSocket()
  }
  return instance
}

/**
 * Vue composable for MailSync socket
 */
export function useMailSync() {
  const socket = useMailSyncSocket()
  
  return {
    // State
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
    sendPresenceHeartbeat: (currentView) => socket.sendPresenceHeartbeat(currentView),
    requestPresenceRefresh: () => socket.subscribeToPresence(), // Re-sends SUBSCRIBE_PRESENCE to get fresh bulk update
  }
}

