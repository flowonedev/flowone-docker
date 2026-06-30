import { ref, onUnmounted } from 'vue'
import api from '@/services/api'
import { isDebugEnabled } from '@/utils/debug'
import { getWsUrl } from '@/services/serverRegistry'

// Guest moodboard share links open in a browser; getWsUrl() derives the URL
// from the serving origin so each per-domain deployment connects to itself.
const WS_URL = import.meta.env.VITE_MAILSYNC_WS_URL || getWsUrl()

const RECONNECT_INITIAL_DELAY = 2000
const RECONNECT_MAX_DELAY = 30000
const TOKEN_REFRESH_MARGIN = 120_000 // refresh JWT 2 min before expiry

/**
 * Lightweight WebSocket composable for public/guest moodboard clients.
 * Obtains a short-lived mood_guest JWT, connects to the mailsync WS server,
 * subscribes to a single board, and exposes send/on/disconnect for cursor
 * and comment relay.
 */
export function useMoodGuestSocket() {
  const connected = ref(false)
  const collaborators = ref([])

  let ws = null
  let token = null
  let boardId = null
  let shareToken = null
  let guestId = null
  let guestName = null
  let reconnectTimer = null
  let reconnectDelay = RECONNECT_INITIAL_DELAY
  let tokenRefreshTimer = null
  let destroyed = false
  const eventHandlers = new Map()

  function getOrCreateGuestId() {
    let id = localStorage.getItem('mood_guest_id')
    if (!id) {
      id = 'g_' + Date.now().toString(36) + '_' + Math.random().toString(36).substring(2, 10)
      localStorage.setItem('mood_guest_id', id)
    }
    return id
  }

  async function fetchToken() {
    const name = guestName || localStorage.getItem('mood_comment_guest_name') || 'Guest'
    const res = await api.post(`/mood-boards/share/${shareToken}/ws-token`, {
      guest_id: guestId,
      guest_name: name,
    })
    if (res.data?.success) {
      token = res.data.data.token
      const expiresIn = (res.data.data.expires_in || 900) * 1000
      scheduleTokenRefresh(expiresIn)
      return token
    }
    throw new Error(res.data?.message || 'Failed to get WS token')
  }

  function scheduleTokenRefresh(expiresInMs) {
    if (tokenRefreshTimer) clearTimeout(tokenRefreshTimer)
    const refreshIn = Math.max(expiresInMs - TOKEN_REFRESH_MARGIN, 30_000)
    tokenRefreshTimer = setTimeout(async () => {
      try {
        await fetchToken()
        // Re-authenticate on the existing connection
        if (ws && ws.readyState === WebSocket.OPEN) {
          ws.send(JSON.stringify({ type: 'AUTHENTICATE', token }))
          isDebugEnabled() && console.log('[MoodGuestWS] Token refreshed & re-authenticated')
        }
      } catch (e) {
        console.error('[MoodGuestWS] Token refresh failed:', e.message)
      }
    }, refreshIn)
  }

  async function connect(_shareToken, _boardId, _guestName) {
    // Tear down any previous connection first: a reused composable instance
    // could otherwise stay subscribed to the old board with stale handlers.
    if (ws || reconnectTimer || tokenRefreshTimer) {
      disconnect()
    }
    shareToken = _shareToken
    boardId = parseInt(_boardId)
    guestName = _guestName || null
    guestId = getOrCreateGuestId()
    destroyed = false

    try {
      await fetchToken()
    } catch (e) {
      console.error('[MoodGuestWS] Initial token fetch failed:', e.message)
      scheduleReconnect()
      return
    }

    openSocket()
  }

  function openSocket() {
    if (destroyed) return
    if (ws && (ws.readyState === WebSocket.OPEN || ws.readyState === WebSocket.CONNECTING)) return

    isDebugEnabled() && console.log(`[MoodGuestWS] Connecting to ${WS_URL}...`)

    try {
      ws = new WebSocket(WS_URL)
    } catch (e) {
      console.error('[MoodGuestWS] WebSocket construction failed:', e.message)
      scheduleReconnect()
      return
    }

    ws.onopen = () => {
      isDebugEnabled() && console.log('[MoodGuestWS] Connected, authenticating...')
      reconnectDelay = RECONNECT_INITIAL_DELAY
      ws.send(JSON.stringify({ type: 'AUTHENTICATE', token }))
    }

    ws.onmessage = (event) => {
      try {
        const msg = JSON.parse(event.data)
        handleMessage(msg)
      } catch (e) {
        console.warn('[MoodGuestWS] Failed to parse message:', e)
      }
    }

    ws.onclose = (event) => {
      isDebugEnabled() && console.log(`[MoodGuestWS] Closed: code=${event.code}`)
      connected.value = false
      if (!destroyed) scheduleReconnect()
    }

    ws.onerror = (err) => {
      console.error('[MoodGuestWS] Error:', err.message || err)
    }
  }

  function handleMessage(msg) {
    switch (msg.type) {
      case 'CONNECTED':
        connected.value = true
        isDebugEnabled() && console.log('[MoodGuestWS] Authenticated, subscribing to board', boardId)
        send({
          type: 'SUBSCRIBE_MOOD_BOARD',
          boardId,
          userName: guestName || localStorage.getItem('mood_comment_guest_name') || 'Guest',
        })
        break

      case 'MOOD_BOARD_COLLABORATORS':
        if (parseInt(msg.payload?.board_id) === boardId) {
          collaborators.value = msg.payload.collaborators || []
        }
        break

      case 'PONG':
        break

      case 'ERROR':
        console.warn('[MoodGuestWS] Server error:', msg.payload)
        break

      default: {
        // Dispatch to registered event handlers
        const payload = msg.payload || msg
        const handlers = eventHandlers.get(msg.type)
        if (handlers) {
          for (const handler of handlers) {
            try { handler(payload) } catch (e) { /* non-critical */ }
          }
        }
        break
      }
    }
  }

  function send(message) {
    if (ws && ws.readyState === WebSocket.OPEN) {
      ws.send(JSON.stringify(message))
      return true
    }
    return false
  }

  function on(eventType, handler) {
    if (!eventHandlers.has(eventType)) {
      eventHandlers.set(eventType, new Set())
    }
    eventHandlers.get(eventType).add(handler)
    return () => {
      const set = eventHandlers.get(eventType)
      if (set) set.delete(handler)
    }
  }

  function scheduleReconnect() {
    if (destroyed || reconnectTimer) return
    isDebugEnabled() && console.log(`[MoodGuestWS] Reconnecting in ${reconnectDelay}ms...`)
    reconnectTimer = setTimeout(async () => {
      reconnectTimer = null
      try {
        await fetchToken()
      } catch (e) {
        console.error('[MoodGuestWS] Token re-fetch failed:', e.message)
      }
      openSocket()
    }, reconnectDelay)
    reconnectDelay = Math.min(reconnectDelay * 2, RECONNECT_MAX_DELAY)
  }

  function disconnect() {
    destroyed = true
    if (reconnectTimer) { clearTimeout(reconnectTimer); reconnectTimer = null }
    if (tokenRefreshTimer) { clearTimeout(tokenRefreshTimer); tokenRefreshTimer = null }
    if (ws) {
      if (ws.readyState === WebSocket.OPEN && boardId) {
        try { ws.send(JSON.stringify({ type: 'UNSUBSCRIBE_MOOD_BOARD', boardId })) } catch (e) { /* ignore */ }
      }
      try { ws.close(1000, 'Guest disconnecting') } catch (e) { /* ignore */ }
      ws = null
    }
    connected.value = false
    collaborators.value = []
    eventHandlers.clear()
  }

  onUnmounted(() => { disconnect() })

  return {
    connected,
    collaborators,
    connect,
    send,
    on,
    disconnect,
    getOrCreateGuestId,
  }
}
