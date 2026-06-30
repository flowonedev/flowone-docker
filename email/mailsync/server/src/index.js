/**
 * Mail Sync WebSocket Server
 * 
 * Real-time email synchronization server using:
 * - WebSocket for client connections
 * - IMAP IDLE for instant new mail detection
 * - Redis pub/sub for events from PHP backend
 * 
 * All events include unique eventId and are idempotent.
 */

import { WebSocketServer } from 'ws'
import { createServer } from 'http'
import { createServer as createHttpsServer } from 'https'
import { readFileSync } from 'fs'
import Redis from 'ioredis'

import { config } from './config.js'
import { validateToken, extractTokenFromRequest, isTokenExpiringSoon } from './auth/jwtValidator.js'
import { logAuthEvent, logConnectionEvent, flushAndStop as flushAudit } from './services/auditLogger.js'
import { EventStore } from './events/eventStore.js'
import { EventTypes, ClientMessageTypes } from './events/eventTypes.js'
import { ClientManager } from './clients/clientManager.js'
import { RedisPubSub } from './redis/pubsub.js'
import { ImapIdleManager } from './imap/idleManager.js'
import { PushService } from './push/pushService.js'
import {
  handleCallInitiate,
  handleCallAnswer,
  handleCallReject,
  handleCallHangup,
  handleCallMediaState,
  handleCallScreenShare,
  handleCallActiveQuery,
  cleanupAllCalls,
  scheduleUserCallCleanup,
  cancelUserCallCleanup
} from './calls/callSignaling.js'
import {
  handleHuddleJoin,
  handleHuddleLeave,
  handleHuddleSdpOffer,
  handleHuddleSdpAnswer,
  handleHuddleIceCandidate,
  handleHuddleMediaState,
  handleHuddleSpeaking,
  cleanupUserHuddles,
  cleanupAllHuddles
} from './calls/huddleSignaling.js'

// Global instances
let redis = null
let redisSubscriber = null
let eventStore = null
let clientManager = null
let redisPubSub = null
let imapIdleManager = null
let pushService = null
let wss = null
let server = null

/**
 * Initialize the server
 */
async function init() {
  console.log('[MailSync] Starting server...')
  console.log(`[MailSync] Config: WS port ${config.ws.port}, Redis ${config.redis.host}:${config.redis.port}`)

  // Initialize Redis connections
  const redisConfig = {
    host: config.redis.host,
    port: config.redis.port,
    password: config.redis.password || undefined,
    db: config.redis.database,
    retryDelayOnFailover: 100,
    maxRetriesPerRequest: 3,
  }

  redis = new Redis(redisConfig)
  redisSubscriber = new Redis(redisConfig)  // Separate connection for pub/sub

  redis.on('connect', () => console.log('[Redis] Connected'))
  redis.on('error', (err) => console.error('[Redis] Error:', err.message))

  // Initialize components
  eventStore = new EventStore(redis)
  await eventStore.init()

  clientManager = new ClientManager()

  // Initialize push notification service (for offline users)
  pushService = new PushService(redis, clientManager)

  redisPubSub = new RedisPubSub(redisSubscriber, eventStore, clientManager, pushService)
  await redisPubSub.init()

  imapIdleManager = new ImapIdleManager(
    eventStore,
    clientManager,
    (userEmail, type, payload) => redisPubSub.publishEvent(userEmail, type, payload),
    // Reuse the existing Redis client so expunge events can enqueue
    // tombstone payloads for the PHP sync cron (see idleManager.js
    // IDLE_TOMBSTONE_QUEUE).
    redis,
    // IMAP IDLE detects new mail directly and broadcasts to WS clients without
    // going through the Redis pub/sub path, so it must trigger pushes itself.
    pushService
  )

  // Phase 2 DB-as-truth: the imap_outbox queue is drained by the PHP cron
  // `backend/cron/drain-outbox.php`, NOT here. The drainer must run in PHP
  // because OAuth refresh-token decrypt and session-password decrypt keys
  // are only available to the PHP config (see drain-outbox.php header).

  // Create HTTP(S) server
  if (config.ssl.enabled) {
    const sslOptions = {
      key: readFileSync(config.ssl.keyPath),
      cert: readFileSync(config.ssl.certPath),
    }
    server = createHttpsServer(sslOptions)
    console.log('[MailSync] SSL enabled')
  } else {
    server = createServer()
  }

  // Create WebSocket server
  wss = new WebSocketServer({ server })

  // Handle WebSocket connections
  wss.on('connection', handleConnection)

  // Start listening
  server.listen(config.ws.port, config.ws.host, () => {
    console.log(`[MailSync] WebSocket server running on ${config.ws.host}:${config.ws.port}`)
  })

  // Graceful shutdown
  process.on('SIGINT', shutdown)
  process.on('SIGTERM', shutdown)
}

/**
 * Complete client setup after authentication
 * @param {WebSocket} ws 
 * @param {object} userInfo - Validated user info from JWT
 * @param {string} clientIp - Client IP address
 */
async function completeAuthentication(ws, userInfo, clientIp) {
  // Cancel any pending call cleanup grace timer (user reconnected)
  cancelUserCallCleanup(userInfo.email)
  
  // Add client to manager
  clientManager.addClient(ws, userInfo)

  // Send connected event with current version
  const currentVersion = await eventStore.getCurrentVersion(userInfo.email)
  const connectedEvent = await eventStore.createEvent(
    EventTypes.CONNECTED,
    userInfo.email,
    {
      serverTime: Date.now(),
      currentVersion,
    }
  )
  clientManager.sendToClient(ws, connectedEvent)

  console.log(`[MailSync] Client authenticated: ${userInfo.email}`)
  logAuthEvent('ws_connect', 'success', userInfo.email, { service: 'mailsync', ip: clientIp })
}

/**
 * Handle new WebSocket connection
 * Supports two auth modes:
 * 1. (Preferred) No URL token - client sends AUTHENTICATE message after open
 * 2. (Legacy/backward compat) Token in URL query param
 * 
 * @param {WebSocket} ws 
 * @param {IncomingMessage} request 
 */
async function handleConnection(ws, request) {
  const clientIp = request.headers['x-forwarded-for'] || request.socket.remoteAddress
  console.log(`[MailSync] New connection from ${clientIp}`)

  // Try legacy auth via URL/headers first (backward compat)
  const token = extractTokenFromRequest(request)
  let userInfo = validateToken(token)
  let isAuthenticated = false

  if (userInfo) {
    // Legacy path: token was in URL, authenticate immediately
    if (isTokenExpiringSoon(userInfo)) {
      console.log(`[MailSync] Warning: token expiring soon for ${userInfo.email}`)
    }
    await completeAuthentication(ws, userInfo, clientIp)
    isAuthenticated = true
  } else if (token) {
    console.log(`[MailSync] Legacy token found in URL/headers but validation failed`)
  }

  // Set an auth timeout: if no AUTHENTICATE message in 10s, disconnect
  let authTimeout = null
  if (!isAuthenticated) {
    console.log(`[MailSync] Waiting for AUTHENTICATE message from ${clientIp}...`)
    authTimeout = setTimeout(() => {
      if (!clientManager.getClientInfo(ws)) {
        console.log(`[MailSync] Connection rejected: auth timeout (no AUTHENTICATE in 10s) from ${clientIp}`)
        logAuthEvent('ws_auth_timeout', 'failed', null, { ip: clientIp, service: 'mailsync' })
        ws.close(4001, 'Authentication timeout')
      }
    }, 10000)
  }

  // Handle incoming messages
  ws.on('message', async (data) => {
    try {
      const message = JSON.parse(data.toString())

      // Handle AUTHENTICATE message (new secure path)
      if (message.type === 'AUTHENTICATE' && !isAuthenticated) {
        console.log(`[MailSync] Got AUTHENTICATE message from ${clientIp}, token present: ${!!message.token}`)
        const authInfo = validateToken(message.token)
        if (!authInfo) {
          console.error(`[MailSync] Connection rejected: invalid auth token from ${clientIp}`)
          logAuthEvent('ws_auth_failed', 'failed', null, { ip: clientIp, service: 'mailsync' })
          ws.close(4001, 'Unauthorized')
          return
        }
        if (isTokenExpiringSoon(authInfo)) {
          console.log(`[MailSync] Warning: token expiring soon for ${authInfo.email}`)
        }
        isAuthenticated = true
        if (authTimeout) {
          clearTimeout(authTimeout)
          authTimeout = null
        }
        await completeAuthentication(ws, authInfo, clientIp)
        return
      }

      // All other messages require authentication
      if (!isAuthenticated) {
        console.warn(`[MailSync] Message type=${message.type} from unauthenticated client ${clientIp}`)
        clientManager.sendToClient(ws, { type: 'ERROR', payload: { code: 'NOT_AUTHENTICATED', message: 'Send AUTHENTICATE message first' } })
        return
      }

      // Forward to existing message handler
      await handleClientMessage(ws, data)
    } catch (e) {
      // If the message handler below re-parses, that's fine - it's idempotent
      if (isAuthenticated) {
        await handleClientMessage(ws, data)
      }
    }
  })

  // Handle pong (heartbeat response)
  ws.on('pong', () => {
    clientManager.updateActivity(ws)
  })

  // Handle close
  ws.on('close', async (code, reason) => {
    if (authTimeout) {
      clearTimeout(authTimeout)
    }
    const info = clientManager.removeClient(ws)
    if (info) {
      // Skip IMAP/call/huddle cleanup for mood guests (they don't use those features)
      if (!info.isMoodGuest && !clientManager.hasConnectedClients(info.userEmail)) {
        imapIdleManager.stopIdle(info.userEmail)
        await cleanupUserHuddles(info.userEmail, clientManager, eventStore)
        scheduleUserCallCleanup(info.userEmail, clientManager, eventStore, pushService)
      }
    }
  })

  // Handle errors
  ws.on('error', (error) => {
    const info = clientManager.getClientInfo(ws)
    const email = info ? info.userEmail : 'unknown'
    console.error(`[MailSync] WebSocket error for ${email}:`, error.message)
  })
}

/**
 * Handle incoming message from client
 * @param {WebSocket} ws 
 * @param {Buffer} data 
 */
async function handleClientMessage(ws, data) {
  const info = clientManager.getClientInfo(ws)
  if (!info) return

  clientManager.updateActivity(ws)

  try {
    const message = JSON.parse(data.toString())
    
    // Mood guest restriction: only allow moodboard operations for the allowed board
    if (info.isMoodGuest) {
      const guestAllowed = [
        ClientMessageTypes.PING,
        ClientMessageTypes.SUBSCRIBE_MOOD_BOARD,
        ClientMessageTypes.UNSUBSCRIBE_MOOD_BOARD,
        ClientMessageTypes.MOOD_BOARD_CURSOR_MOVE,
        ClientMessageTypes.MOOD_BOARD_COMMENT_BROADCAST,
        ClientMessageTypes.MOOD_BOARD_COMMENT_DELETE_BROADCAST,
        ClientMessageTypes.MOOD_BOARD_THREAD_RESOLVE_BROADCAST,
      ]
      if (!guestAllowed.includes(message.type)) {
        clientManager.sendToClient(ws, { type: 'ERROR', payload: { code: 'GUEST_RESTRICTED', message: 'Operation not allowed for guests' } })
        return
      }
      if (message.boardId && parseInt(message.boardId) !== info.allowedBoardId) {
        clientManager.sendToClient(ws, { type: 'ERROR', payload: { code: 'BOARD_NOT_ALLOWED', message: 'Access denied for this board' } })
        return
      }
    }

    switch (message.type) {
      case ClientMessageTypes.PING:
        // Respond with pong
        clientManager.sendToClient(ws, { type: 'PONG', timestamp: Date.now() })
        break

      case ClientMessageTypes.SUBSCRIBE_FOLDER:
        // Subscribe to folder IDLE
        if (message.folder) {
          clientManager.subscribeToFolder(ws, message.folder)
          
          // If IMAP credentials provided, start IDLE
          if (message.password) {
            try {
              await imapIdleManager.startIdle(
                info.userEmail,
                message.password,
                message.folder,
                message.options || {}
              )
            } catch (e) {
              console.error(`[MailSync] Failed to start IDLE:`, e.message)
              clientManager.sendToClient(ws, {
                type: EventTypes.ERROR,
                payload: { code: 'IMAP_CONNECT_FAILED', message: e.message }
              })
            }
          }
        }
        break

      case ClientMessageTypes.UNSUBSCRIBE_FOLDER:
        if (message.folder) {
          clientManager.unsubscribeFromFolder(ws, message.folder)
        }
        break

      case ClientMessageTypes.REPLAY_EVENTS:
        // Client requesting missed events
        const sinceVersion = message.sinceVersion || 0
        const events = await eventStore.getEventsSince(info.userEmail, sinceVersion)
        
        // Send events in order
        for (const event of events) {
          clientManager.sendToClient(ws, event)
        }
        
        // Detect gap: if the client requested events since version N,
        // but the oldest event in our buffer is version N+X (X > 1),
        // then events were lost because the buffer expired or overflowed.
        const currentVersion = await eventStore.getCurrentVersion(info.userEmail)
        let gapDetected = false
        if (sinceVersion > 0 && events.length > 0) {
          const oldestReplayedVersion = events[0].version
          if (oldestReplayedVersion > sinceVersion + 1) {
            gapDetected = true
            console.warn(`[MailSync] Event gap detected for ${info.userEmail}: requested since v${sinceVersion}, oldest available v${oldestReplayedVersion}`)
          }
        } else if (sinceVersion > 0 && events.length === 0 && currentVersion > sinceVersion) {
          // Client had events but buffer is empty - all events expired
          gapDetected = true
          console.warn(`[MailSync] Event gap detected for ${info.userEmail}: requested since v${sinceVersion}, buffer empty, current v${currentVersion}`)
        }
        
        // Send sync complete notification
        clientManager.sendToClient(ws, {
          type: EventTypes.SYNC_STATUS,
          payload: {
            status: 'replay_complete',
            eventCount: events.length,
            currentVersion,
            gapDetected,
          }
        })
        break

      case ClientMessageTypes.ACK_EVENT:
        // Client acknowledging event receipt
        if (message.version) {
          clientManager.setLastVersion(ws, message.version)
        }
        break

      // ============================================
      // DESKTOP APP SUBSCRIPTION HANDLERS
      // ============================================
      
      case ClientMessageTypes.SUBSCRIBE_ALL:
        // Desktop app subscribing to all events
        clientManager.setSubscriptionMode(ws, 'all')
        console.log(`[MailSync] Client ${info.userEmail} subscribed to ALL events (desktop mode)`)
        
        // Send sync status
        clientManager.sendToClient(ws, {
          type: EventTypes.SYNC_STATUS,
          payload: {
            status: 'subscribed',
            mode: 'all',
            currentVersion: await eventStore.getCurrentVersion(info.userEmail),
          }
        })
        
        // Send current presence state (since SUBSCRIBE_ALL includes presence)
        clientManager.sendBulkPresenceUpdate(ws, info.userEmail)
        break

      case ClientMessageTypes.SUBSCRIBE_CALENDARS:
        clientManager.addSubscription(ws, 'calendars')
        console.log(`[MailSync] Client ${info.userEmail} subscribed to calendar events`)
        break

      case ClientMessageTypes.SUBSCRIBE_BOARDS:
        clientManager.addSubscription(ws, 'boards')
        console.log(`[MailSync] Client ${info.userEmail} subscribed to board events`)
        break

      case ClientMessageTypes.SUBSCRIBE_CLIENTS:
        clientManager.addSubscription(ws, 'clients')
        console.log(`[MailSync] Client ${info.userEmail} subscribed to client events`)
        break

      case ClientMessageTypes.SUBSCRIBE_DRIVE:
        clientManager.addSubscription(ws, 'drive')
        console.log(`[MailSync] Client ${info.userEmail} subscribed to drive events`)
        break

      case ClientMessageTypes.SUBSCRIBE_CHAT:
        clientManager.addSubscription(ws, 'chat')
        console.log(`[MailSync] Client ${info.userEmail} subscribed to chat events`)
        break

      case ClientMessageTypes.UNSUBSCRIBE_CALENDARS:
        clientManager.removeSubscription(ws, 'calendars')
        break

      case ClientMessageTypes.UNSUBSCRIBE_BOARDS:
        clientManager.removeSubscription(ws, 'boards')
        break

      case ClientMessageTypes.UNSUBSCRIBE_CLIENTS:
        clientManager.removeSubscription(ws, 'clients')
        break

      case ClientMessageTypes.UNSUBSCRIBE_DRIVE:
        clientManager.removeSubscription(ws, 'drive')
        break

      case ClientMessageTypes.UNSUBSCRIBE_CHAT:
        clientManager.removeSubscription(ws, 'chat')
        break

      // ============================================
      // CALL SIGNALING HANDLERS
      // ============================================
      
      case ClientMessageTypes.CALL_INITIATE:
        await handleCallInitiate(ws, info, message, clientManager, eventStore, redisPubSub)
        break

      case ClientMessageTypes.CALL_ANSWER:
        await handleCallAnswer(ws, info, message, clientManager, eventStore, pushService)
        break

      case ClientMessageTypes.CALL_REJECT:
        await handleCallReject(ws, info, message, clientManager, eventStore, pushService)
        break

      case ClientMessageTypes.CALL_HANGUP:
        await handleCallHangup(ws, info, message, clientManager, eventStore, pushService)
        break

      case ClientMessageTypes.CALL_MEDIA_STATE:
        await handleCallMediaState(ws, info, message, clientManager, eventStore)
        break

      case ClientMessageTypes.CALL_SCREEN_SHARE_START:
        await handleCallScreenShare(ws, info, message, clientManager, eventStore, true)
        break

      case ClientMessageTypes.CALL_SCREEN_SHARE_STOP:
        await handleCallScreenShare(ws, info, message, clientManager, eventStore, false)
        break

      case ClientMessageTypes.CALL_ACTIVE_QUERY:
        handleCallActiveQuery(ws, info, message, clientManager)
        break

      // ============================================
      // HUDDLE SIGNALING HANDLERS
      // ============================================
      
      case ClientMessageTypes.HUDDLE_JOIN:
        await handleHuddleJoin(ws, info, message, clientManager, eventStore)
        break

      case ClientMessageTypes.HUDDLE_LEAVE:
        await handleHuddleLeave(ws, info, message, clientManager, eventStore)
        break

      case ClientMessageTypes.HUDDLE_SDP_OFFER:
        await handleHuddleSdpOffer(ws, info, message, clientManager, eventStore)
        break

      case ClientMessageTypes.HUDDLE_SDP_ANSWER:
        await handleHuddleSdpAnswer(ws, info, message, clientManager, eventStore)
        break

      case ClientMessageTypes.HUDDLE_ICE_CANDIDATE:
        await handleHuddleIceCandidate(ws, info, message, clientManager, eventStore)
        break

      case ClientMessageTypes.HUDDLE_MEDIA_STATE:
        await handleHuddleMediaState(ws, info, message, clientManager, eventStore)
        break

      case ClientMessageTypes.HUDDLE_SPEAKING:
        await handleHuddleSpeaking(ws, info, message, clientManager, eventStore)
        break

      // ============================================
      // PRESENCE HANDLERS
      // ============================================
      
      case ClientMessageTypes.SUBSCRIBE_PRESENCE:
        // Subscribe to presence updates for organization
        clientManager.addSubscription(ws, 'presence')
        console.log(`[MailSync] Client ${info.userEmail} subscribed to presence events`)
        
        // Send current presence state for organization
        clientManager.sendBulkPresenceUpdate(ws, info.userEmail)
        break
      
      case ClientMessageTypes.PRESENCE_UPDATE:
        // User manually updating their status (active, away, do_not_disturb)
        if (message.status && ['active', 'away', 'do_not_disturb'].includes(message.status)) {
          clientManager.updateUserStatus(info.userEmail, message.status, true) // true = manual
          console.log(`[MailSync] ${info.userEmail} set status to: ${message.status}`)
        }
        break
      
      case ClientMessageTypes.PRESENCE_HEARTBEAT:
        // User activity heartbeat (keeps status as active, prevents auto-away)
        clientManager.updateUserActivity(info.userEmail, message.currentView || null)
        break
      
      case ClientMessageTypes.SUBSCRIBE_PRESENCE_USERS:
        // Subscribe to specific users' presence (cross-domain chat partners)
        if (Array.isArray(message.emails) && message.emails.length > 0) {
          clientManager.subscribeToCrossDomainPresence(info.userEmail, message.emails)
          // Send current presence for those users immediately
          clientManager.sendBulkPresenceUpdate(ws, info.userEmail)
        }
        break

      // ============================================
      // MOOD BOARD COLLABORATION HANDLERS
      // ============================================
      
      case ClientMessageTypes.SUBSCRIBE_MOOD_BOARD:
        if (message.boardId) {
          // Update display name if the client sends one (JWT may not have it)
          if (message.userName && !info.displayName?.includes('@')) {
            info.displayName = message.userName
          }
          
          clientManager.addSubscription(ws, `mood_board:${message.boardId}`)
          console.log(`[MailSync] ${info.userEmail} (${info.displayName}) subscribed to mood board ${message.boardId}`)
          
          // Broadcast join to other collaborators
          const joinEvent = {
            type: EventTypes.MOOD_BOARD_PRESENCE_JOIN,
            payload: {
              board_id: message.boardId,
              user_email: info.userEmail,
              user_name: info.displayName || info.userEmail,
              timestamp: Date.now()
            }
          }
          clientManager.broadcastToSubscription(`mood_board:${message.boardId}`, joinEvent, ws)
          
          // Send current collaborators list
          const collaborators = clientManager.getSubscriptionMembers(`mood_board:${message.boardId}`)
          clientManager.sendToClient(ws, {
            type: 'MOOD_BOARD_COLLABORATORS',
            payload: { board_id: message.boardId, collaborators }
          })
        }
        break
      
      case ClientMessageTypes.UNSUBSCRIBE_MOOD_BOARD:
        if (message.boardId) {
          clientManager.removeSubscription(ws, `mood_board:${message.boardId}`)
          
          // Broadcast leave
          const leaveEvent = {
            type: EventTypes.MOOD_BOARD_PRESENCE_LEAVE,
            payload: {
              board_id: message.boardId,
              user_email: info.userEmail,
              timestamp: Date.now()
            }
          }
          clientManager.broadcastToSubscription(`mood_board:${message.boardId}`, leaveEvent, ws)
        }
        break
      
      case ClientMessageTypes.MOOD_BOARD_CURSOR_MOVE:
        // Relay cursor position to other collaborators on the same board
        if (message.boardId && message.x !== undefined && message.y !== undefined) {
          // Use client-provided userName (from auth store) or fall back to server-side displayName
          const cursorUserName = message.userName || info.displayName || info.userEmail
          const cursorEvent = {
            type: EventTypes.MOOD_BOARD_CURSOR,
            payload: {
              board_id: message.boardId,
              user_email: info.userEmail,
              user_name: cursorUserName,
              x: message.x,
              y: message.y,
              // Viewport data for follow-viewport mode
              panX: message.panX,
              panY: message.panY,
              zoom: message.zoom,
              timestamp: Date.now()
            }
          }
          clientManager.broadcastToSubscription(`mood_board:${message.boardId}`, cursorEvent, ws)
        }
        break

      case ClientMessageTypes.MOOD_BOARD_COMMENT_BROADCAST:
        if (message.boardId && message.comment) {
          clientManager.broadcastToSubscription(`mood_board:${message.boardId}`, {
            type: EventTypes.MOOD_BOARD_COMMENT_ADDED,
            payload: { board_id: message.boardId, comment: message.comment }
          }, ws)
        }
        break

      case ClientMessageTypes.MOOD_BOARD_COMMENT_DELETE_BROADCAST:
        if (message.boardId && message.commentId) {
          clientManager.broadcastToSubscription(`mood_board:${message.boardId}`, {
            type: EventTypes.MOOD_BOARD_COMMENT_DELETED,
            payload: { board_id: message.boardId, comment_id: message.commentId }
          }, ws)
        }
        break

      case ClientMessageTypes.MOOD_BOARD_THREAD_DELETE_BROADCAST:
        if (message.boardId && message.threadId) {
          clientManager.broadcastToSubscription(`mood_board:${message.boardId}`, {
            type: EventTypes.MOOD_BOARD_THREAD_DELETED,
            payload: { board_id: message.boardId, thread_id: message.threadId }
          }, ws)
        }
        break

      case ClientMessageTypes.MOOD_BOARD_THREAD_RESOLVE_BROADCAST:
        if (message.boardId && message.threadId) {
          clientManager.broadcastToSubscription(`mood_board:${message.boardId}`, {
            type: EventTypes.MOOD_BOARD_THREAD_RESOLVED,
            payload: { board_id: message.boardId, thread_id: message.threadId, resolved: !!message.resolved }
          }, ws)
        }
        break

      default:
        console.warn(`[MailSync] Unknown message type: ${message.type}`)
    }
  } catch (error) {
    console.error('[MailSync] Error parsing message:', error)
  }
}

/**
 * Graceful shutdown with timeout
 */
async function shutdown() {
  console.log('\n[MailSync] Shutting down...')

  // Force exit after 3 seconds if graceful shutdown hangs
  const forceExitTimer = setTimeout(() => {
    console.log('[MailSync] Force exit (timeout)')
    process.exit(0)
  }, 3000)

  try {
    // Flush audit events before shutdown
    await flushAudit()

    // Clean up active calls and huddles (notify participants before clearing)
    await cleanupAllCalls(clientManager, eventStore)
    cleanupAllHuddles()

    // Close WebSocket server
    if (wss) {
      wss.close()
    }

    // Shutdown components
    if (clientManager) {
      await clientManager.shutdown()
    }

    if (imapIdleManager) {
      await imapIdleManager.shutdown()
    }

    if (redisPubSub) {
      await redisPubSub.shutdown()
    }

    if (eventStore) {
      await eventStore.shutdown()
    }

    // Close Redis connections (use disconnect instead of quit for faster shutdown)
    if (redis) {
      redis.disconnect()
    }
    if (redisSubscriber) {
      redisSubscriber.disconnect()
    }

    // Close HTTP server
    if (server) {
      server.close()
    }

    clearTimeout(forceExitTimer)
    console.log('[MailSync] Shutdown complete')
    process.exit(0)
  } catch (error) {
    clearTimeout(forceExitTimer)
    console.error('[MailSync] Error during shutdown:', error)
    process.exit(1)
  }
}

// Start the server
init().catch((error) => {
  console.error('[MailSync] Failed to start:', error)
  process.exit(1)
})

