/**
 * Chat Sync WebSocket Client (FlowOneChatMobile)
 *
 * Connects directly via WebSocket (no Electron IPC).
 * Subscribes to chat, presence, and call events only.
 */

import { ref, computed, readonly } from 'vue'
import { getWsUrl } from '@/services/serverRegistry'

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

// Resolved at connect time so native targets wss://email.<domain>; a dev
// override (VITE_MAILSYNC_WS_URL) still wins for local development.
function resolveWsUrl() {
  return import.meta.env.VITE_MAILSYNC_WS_URL || getWsUrl()
}
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
  }

  async connect(token) {
    this.token = token
    await this.connectViaWebSocket(token)
  }

  async connectViaWebSocket(token) {
    if (this.ws && this.ws.readyState === WebSocket.OPEN) return
    const wsUrl = resolveWsUrl()
    if (!wsUrl) return // native pre-login: no deployment resolved yet
    this.connectionState.value = ConnectionState.CONNECTING
    try {
      this.ws = new WebSocket(wsUrl)
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
    this.stopHeartbeat()
    this.clearReconnectTimer()
    if (this.ws) { this.ws.close(1000); this.ws = null }
    this.connectionState.value = ConnectionState.DISCONNECTED
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
    this.clearReconnectTimer()
    const delay = Math.min(RECONNECT_INITIAL_DELAY * Math.pow(RECONNECT_MULTIPLIER, this.reconnectAttempts), RECONNECT_MAX_DELAY)
    this.reconnectTimer = setTimeout(() => { this.reconnectAttempts++; if (this.token) this.connect(this.token) }, delay)
  }

  clearReconnectTimer() { if (this.reconnectTimer) { clearTimeout(this.reconnectTimer); this.reconnectTimer = null } }

  startHeartbeat() {
    this.stopHeartbeat()
    this.heartbeatTimer = setInterval(() => {
      if (this.ws && this.ws.readyState === WebSocket.OPEN) this.send({ type: ClientMessageTypes.PING })
    }, HEARTBEAT_INTERVAL)
  }

  stopHeartbeat() { if (this.heartbeatTimer) { clearInterval(this.heartbeatTimer); this.heartbeatTimer = null } }

  send(message) {
    if (this.ws && this.ws.readyState === WebSocket.OPEN) { this.ws.send(JSON.stringify(message)); return true }
    return false
  }

  subscribeToPresence() { this.send({ type: ClientMessageTypes.SUBSCRIBE_PRESENCE }) }
  updatePresenceStatus(status) { this.send({ type: ClientMessageTypes.PRESENCE_UPDATE, status }) }
  sendPresenceHeartbeat() { this.send({ type: ClientMessageTypes.PRESENCE_HEARTBEAT }) }
  subscribeToPresenceUsers(emails) { if (emails?.length) this.send({ type: 'SUBSCRIBE_PRESENCE_USERS', emails }) }

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
