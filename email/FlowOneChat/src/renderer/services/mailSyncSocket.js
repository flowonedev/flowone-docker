/**
 * Chat Sync WebSocket Client (FlowOneChat)
 * 
 * In Electron: receives events from the main process via IPC.
 * Subscribes to chat, presence, and call events only.
 */

import { ref, computed, readonly } from 'vue'
import { isElectron } from '@/services/electronApi'

export const ConnectionState = {
  DISCONNECTED: 'disconnected',
  CONNECTING: 'connecting',
  CONNECTED: 'connected',
  RECONNECTING: 'reconnecting',
}

export const EventTypes = {
  CONNECTED: 'CONNECTED',
  RECONNECTED: 'RECONNECTED',
  ERROR: 'ERROR',
  SYNC_STATUS: 'SYNC_STATUS',
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
  CHAT_VIEW_SESSION_START: 'CHAT_VIEW_SESSION_START',
  CHAT_VIEW_SESSION_END: 'CHAT_VIEW_SESSION_END',
  CHAT_VIEW_SYNC: 'CHAT_VIEW_SYNC',
  PRESENCE_ONLINE: 'PRESENCE_ONLINE',
  PRESENCE_OFFLINE: 'PRESENCE_OFFLINE',
  PRESENCE_STATUS_CHANGED: 'PRESENCE_STATUS_CHANGED',
  PRESENCE_BULK_UPDATE: 'PRESENCE_BULK_UPDATE',
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
  COLLEAGUE_UPDATED: 'COLLEAGUE_UPDATED',
  COLLEAGUE_GROUP_UPDATED: 'COLLEAGUE_GROUP_UPDATED',
}

const ClientMessageTypes = {
  PING: 'PING',
  SUBSCRIBE_CHAT: 'SUBSCRIBE_CHAT',
  SUBSCRIBE_PRESENCE: 'SUBSCRIBE_PRESENCE',
  PRESENCE_UPDATE: 'PRESENCE_UPDATE',
  PRESENCE_HEARTBEAT: 'PRESENCE_HEARTBEAT',
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

const WS_URL = import.meta.env.VITE_MAILSYNC_WS_URL || 'wss://flowone.pro/mailsync_ws'
const RECONNECT_INITIAL_DELAY = 1000
const RECONNECT_MAX_DELAY = 30000
const RECONNECT_MULTIPLIER = 2
const HEARTBEAT_INTERVAL = 25000

class ChatSyncSocket {
  constructor() {
    this.ws = null
    this.connectionState = ref(ConnectionState.DISCONNECTED)
    this.lastError = ref(null)
    this.lastEventVersion = ref(0)
    this.serverTime = ref(null)
    this.eventHandlers = new Map()
    this.processedEventIds = new Set()
    this.processedEventIdList = []
    this.reconnectAttempts = 0
    this.reconnectTimer = null
    this.heartbeatTimer = null
    this.token = null
    this.ipcUnsubscribers = []
  }

  async connect(token) {
    this.token = token
    if (isElectron()) {
      this.connectViaIPC()
    } else {
      await this.connectViaWebSocket(token)
    }
  }

  connectViaIPC() {
    this.disconnectIPC()

    const eventMappings = {
      'sync-status': this.handleSyncStatus.bind(this),
      'sync-error': (data) => { this.lastError.value = data.error; this.processEvent({ type: EventTypes.ERROR, payload: data }) },
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
      'presence-online': (payload) => this.processEvent({ type: EventTypes.PRESENCE_ONLINE, payload }),
      'presence-offline': (payload) => this.processEvent({ type: EventTypes.PRESENCE_OFFLINE, payload }),
      'presence-status-changed': (payload) => this.processEvent({ type: EventTypes.PRESENCE_STATUS_CHANGED, payload }),
      'presence-bulk-update': (payload) => this.processEvent({ type: EventTypes.PRESENCE_BULK_UPDATE, payload }),
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
      'colleague-updated': (payload) => this.processEvent({ type: EventTypes.COLLEAGUE_UPDATED, payload }),
      'colleague-group-updated': (payload) => this.processEvent({ type: EventTypes.COLLEAGUE_GROUP_UPDATED, payload }),
      'online-status': this.handleOnlineStatus.bind(this),
    }

    for (const [channel, handler] of Object.entries(eventMappings)) {
      const unsub = window.api.on(channel, handler)
      this.ipcUnsubscribers.push(unsub)
    }

    this._pollSyncStatus()
    this._startHealthCheck()
  }

  _pollSyncStatus(attempt = 0) {
    const MAX_INIT_WAIT = 20
    const RETRY_DELAY_MS = 2000
    if (!window.api.sync?.getStatus) { this.connectionState.value = ConnectionState.CONNECTED; return }
    if (attempt === 0) this.connectionState.value = ConnectionState.CONNECTING

    window.api.sync.getStatus().then(status => {
      if (status?.wsConnected || status?.isOnline) { this.connectionState.value = ConnectionState.CONNECTED; return }
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

  _startHealthCheck() {
    this._stopHealthCheck()
    const doCheck = () => {
      if (!window.api?.sync?.getStatus) return
      window.api.sync.getStatus().then(status => {
        if ((status?.wsConnected || status?.isOnline) && this.connectionState.value !== ConnectionState.CONNECTED) {
          this.connectionState.value = ConnectionState.CONNECTED
        }
      }).catch(() => {})
    }
    setTimeout(doCheck, 3000)
    this._healthCheckTimer = setInterval(doCheck, 15000)
  }

  _stopHealthCheck() {
    if (this._healthCheckTimer) { clearInterval(this._healthCheckTimer); this._healthCheckTimer = null }
  }

  disconnectIPC() {
    if (this._statusRetryTimer) { clearTimeout(this._statusRetryTimer); this._statusRetryTimer = null }
    this._stopHealthCheck()
    if (this._wsDisconnectTimer) { clearTimeout(this._wsDisconnectTimer); this._wsDisconnectTimer = null }
    for (const unsub of this.ipcUnsubscribers) { if (typeof unsub === 'function') unsub() }
    this.ipcUnsubscribers = []
  }

  handleSyncStatus(status) {
    if (status?.wsConnected || status?.isOnline) {
      if (this._wsDisconnectTimer) { clearTimeout(this._wsDisconnectTimer); this._wsDisconnectTimer = null }
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

  handleOnlineStatus(isOnline) {
    this.connectionState.value = isOnline ? ConnectionState.CONNECTED : ConnectionState.DISCONNECTED
  }

  async connectViaWebSocket(token) {
    if (this.ws && this.ws.readyState === WebSocket.OPEN) return
    this.connectionState.value = ConnectionState.CONNECTING
    try {
      this.ws = new WebSocket(WS_URL)
      this.ws.onopen = () => this.handleOpen()
      this.ws.onmessage = (event) => this.handleMessage(event)
      this.ws.onclose = (event) => this.handleClose(event)
      this.ws.onerror = () => { this.lastError.value = 'Connection error' }
    } catch (error) {
      this.lastError.value = error.message
      this.scheduleReconnect()
    }
  }

  disconnect() {
    if (isElectron()) {
      this.disconnectIPC()
      this.connectionState.value = ConnectionState.DISCONNECTED
    } else {
      this.stopHeartbeat()
      this.clearReconnectTimer()
      if (this.ws) { this.ws.close(1000); this.ws = null }
      this.connectionState.value = ConnectionState.DISCONNECTED
    }
  }

  handleOpen() {
    this.connectionState.value = ConnectionState.CONNECTED
    this.reconnectAttempts = 0
    this.lastError.value = null
    this.send({ type: 'AUTHENTICATE', token: this.token })
    this.send({ type: 'SUBSCRIBE_CHAT' })
    this.send({ type: 'SUBSCRIBE_PRESENCE' })
    this.startHeartbeat()
  }

  handleMessage(event) {
    try {
      const data = JSON.parse(event.data)
      if (data.type === 'PONG') return
      if (data.eventId && this.processedEventIds.has(data.eventId)) return
      this.processEvent(data)
      if (data.eventId) this.markEventProcessed(data.eventId)
    } catch (_) {}
  }

  processEvent(event) {
    const handlers = this.eventHandlers.get(event.type)
    if (handlers) {
      for (const handler of handlers) {
        try { handler(event.payload, event) } catch (_) {}
      }
    }
    const wildcardHandlers = this.eventHandlers.get('*')
    if (wildcardHandlers) {
      for (const handler of wildcardHandlers) {
        try { handler(event.payload, event) } catch (_) {}
      }
    }
  }

  markEventProcessed(eventId) {
    this.processedEventIds.add(eventId)
    this.processedEventIdList.push(eventId)
    if (this.processedEventIdList.length > 1000) {
      this.processedEventIds.delete(this.processedEventIdList.shift())
    }
  }

  handleClose(event) {
    this.stopHeartbeat()
    if (event.code === 4001) { this.connectionState.value = ConnectionState.DISCONNECTED; this.lastError.value = 'Unauthorized' }
    else if (event.code !== 1000) { this.connectionState.value = ConnectionState.RECONNECTING; this.scheduleReconnect() }
    else { this.connectionState.value = ConnectionState.DISCONNECTED }
  }

  scheduleReconnect() {
    if (isElectron()) return
    this.clearReconnectTimer()
    const delay = Math.min(RECONNECT_INITIAL_DELAY * Math.pow(RECONNECT_MULTIPLIER, this.reconnectAttempts), RECONNECT_MAX_DELAY)
    this.reconnectTimer = setTimeout(() => { this.reconnectAttempts++; if (this.token) this.connect(this.token) }, delay)
  }

  clearReconnectTimer() { if (this.reconnectTimer) { clearTimeout(this.reconnectTimer); this.reconnectTimer = null } }

  startHeartbeat() {
    if (isElectron()) return
    this.stopHeartbeat()
    this.heartbeatTimer = setInterval(() => {
      if (this.ws && this.ws.readyState === WebSocket.OPEN) this.send({ type: ClientMessageTypes.PING })
    }, HEARTBEAT_INTERVAL)
  }

  stopHeartbeat() { if (this.heartbeatTimer) { clearInterval(this.heartbeatTimer); this.heartbeatTimer = null } }

  send(message) {
    if (isElectron()) { window.api.send('sync-request', message); return true }
    if (this.ws && this.ws.readyState === WebSocket.OPEN) { this.ws.send(JSON.stringify(message)); return true }
    return false
  }

  subscribeToPresence() { this.send({ type: ClientMessageTypes.SUBSCRIBE_PRESENCE }) }
  updatePresenceStatus(status) { this.send({ type: ClientMessageTypes.PRESENCE_UPDATE, status }) }
  sendPresenceHeartbeat() { this.send({ type: ClientMessageTypes.PRESENCE_HEARTBEAT }) }
  subscribeToPresenceUsers(emails) { if (emails?.length) this.send({ type: 'SUBSCRIBE_PRESENCE_USERS', emails }) }

  // Stubs for shared code compatibility
  subscribeToFolder() {}
  unsubscribeFromFolder() {}
  acknowledgeEvent() {}
  requestEventReplay() {}
  async saveLastVersion() {}
  async loadLastVersion() { return 0 }

  on(eventType, handler) {
    if (!this.eventHandlers.has(eventType)) this.eventHandlers.set(eventType, new Set())
    this.eventHandlers.get(eventType).add(handler)
    return () => { this.eventHandlers.get(eventType)?.delete(handler) }
  }

  off(eventType, handler) { this.eventHandlers.get(eventType)?.delete(handler) }

  getState() { return readonly(this.connectionState) }
  isConnected() { return this.connectionState.value === ConnectionState.CONNECTED }
  getLastError() { return readonly(this.lastError) }
}

let instance = null

export function useMailSyncSocket() {
  if (!instance) instance = new ChatSyncSocket()
  return instance
}

export function useMailSync() {
  const socket = useMailSyncSocket()
  return {
    connectionState: socket.getState(),
    lastError: socket.getLastError(),
    isConnected: computed(() => socket.isConnected()),
    connect: (token) => socket.connect(token),
    disconnect: () => socket.disconnect(),
    send: (message) => socket.send(message),
    subscribeToFolder: () => {},
    unsubscribeFromFolder: () => {},
    on: (eventType, handler) => socket.on(eventType, handler),
    off: (eventType, handler) => socket.off(eventType, handler),
    subscribeToPresence: () => socket.subscribeToPresence(),
    subscribeToPresenceUsers: (emails) => socket.subscribeToPresenceUsers(emails),
    updatePresenceStatus: (status) => socket.updatePresenceStatus(status),
    sendPresenceHeartbeat: () => socket.sendPresenceHeartbeat(),
    requestPresenceRefresh: () => socket.subscribeToPresence(),
  }
}
