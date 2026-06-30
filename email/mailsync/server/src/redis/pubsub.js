/**
 * Redis Pub/Sub Handler
 * 
 * Subscribes to Redis channels to receive events published by the PHP backend.
 * Routes events to connected WebSocket clients.
 */

import { config } from '../config.js'

export class RedisPubSub {
  constructor(subscriberRedis, eventStore, clientManager, pushService = null) {
    this.redis = subscriberRedis  // Dedicated Redis connection for pub/sub
    this.eventStore = eventStore
    this.clientManager = clientManager
    this.pushService = pushService  // For sending push notifications to offline users
    
    // Track subscribed channels
    this.subscribedChannels = new Set()
  }

  /**
   * Initialize pub/sub and start listening
   */
  async init() {
    // Subscribe to pattern for all user channels
    const userPattern = `${config.redis.prefix}mailbox:*`
    // Subscribe to board-level channels for guest relay
    const boardPattern = `${config.redis.prefix}mood_board:*`
    
    this.redis.on('pmessage', (pattern, channel, message) => {
      this.handleMessage(channel, message)
    })

    await this.redis.psubscribe(userPattern, boardPattern)
    console.log(`[RedisPubSub] Subscribed to patterns: ${userPattern}, ${boardPattern}`)

    // Also subscribe to global events channel
    const globalChannel = `${config.redis.prefix}mailbox:global`
    await this.redis.subscribe(globalChannel)
    console.log(`[RedisPubSub] Subscribed to global channel: ${globalChannel}`)
  }

  /**
   * Handle incoming Redis message
   * @param {string} channel - Redis channel name
   * @param {string} message - JSON message
   */
  async handleMessage(channel, message) {
    try {
      // Parse message
      const data = JSON.parse(message)
      
      if (!data.type) {
        console.warn('[RedisPubSub] Message missing type:', channel)
        return
      }

      // Board-level channel: {prefix}mood_board:{boardId}
      const boardMatch = channel.match(/mood_board:(\d+)$/)
      if (boardMatch) {
        await this.handleBoardRoomEvent(parseInt(boardMatch[1]), data)
        return
      }

      // Parse channel to extract user email
      // Channel format: webmail:mailbox:{userEmail} or webmail:mailbox:global
      const channelParts = channel.split(':')
      const userEmail = channelParts.length >= 3 ? channelParts.slice(2).join(':') : null

      // If this is a user-specific channel
      if (userEmail && userEmail !== 'global') {
        await this.handleUserEvent(userEmail, data)
      } else {
        // Global event (e.g., system maintenance)
        await this.handleGlobalEvent(data)
      }
    } catch (error) {
      console.error('[RedisPubSub] Error handling message:', error)
    }
  }

  /**
   * Handle user-specific event
   * @param {string} userEmail 
   * @param {object} data - Event data from PHP backend
   */
  async handleUserEvent(userEmail, data) {
    // Create proper event with eventId and version
    const event = await this.eventStore.createEvent(data.type, userEmail, data.payload || data)
    
    // Determine entity type from event type
    const entityType = this.getEntityTypeFromEvent(data.type)
    
    let delivered = 0
    if (entityType) {
      // Entity-specific event - only send to subscribed clients
      delivered = await this.clientManager.broadcastToSubscribed(userEmail, event, entityType)
    } else {
      // Email/folder event - send to all clients (web and desktop)
      delivered = await this.clientManager.broadcastToUser(userEmail, event)
    }
    
    const clientCount = this.clientManager.getClientCount(userEmail)
    console.log(`[RedisPubSub] ${event.type} -> ${userEmail} (v${event.version}) [${delivered}/${clientCount} clients${entityType ? ', entity:' + entityType : ''}]`)
    
    // Send push notification if user has no connected WebSocket clients
    // This ensures offline users still get notified (phone buzzes, etc.)
    if (this.pushService) {
      this.pushService.sendPushIfOffline(userEmail, data).catch(err => {
        console.error(`[RedisPubSub] Push notification error for ${userEmail}:`, err.message)
      })
    }
  }

  /**
   * Determine entity type from event type
   * @param {string} eventType 
   * @returns {string|null} Entity type or null for email events
   */
  getEntityTypeFromEvent(eventType) {
    // Calendar events
    if (eventType.startsWith('CALENDAR_')) return 'calendars'
    
    // Board events (including checklist items)
    if (eventType.startsWith('BOARD_') || 
        eventType.startsWith('LIST_') || 
        eventType.startsWith('CARD_') ||
        eventType.startsWith('CHECKLIST_')) return 'boards'
    
    // Client events
    if (eventType.startsWith('CLIENT_') || 
        eventType.startsWith('TIME_')) return 'clients'
    
    // Drive events
    if (eventType.startsWith('DRIVE_')) return 'drive'
    
    // Mood Board events
    if (eventType.startsWith('MOOD_BOARD_')) return null // Broadcast to all user clients; frontend filters by board_id
    
    // Todo events
    if (eventType.startsWith('TODO_')) return 'todos'
    
    // Chat events (direct messaging)
    if (eventType.startsWith('CHAT_')) return 'chat'
    
    // Call events (voice/video calls) - route through chat subscription
    if (eventType.startsWith('CALL_')) return 'chat'
    
    // Email/folder events - return null (broadcast to all)
    return null
  }

  /**
   * Handle a board-level event from Redis.
   * Relays the event to the mood_board:{boardId} subscription room so that
   * all connected clients (including unauthenticated guests) receive it.
   */
  async handleBoardRoomEvent(boardId, data) {
    const roomKey = `mood_board:${boardId}`
    const event = { type: data.type, payload: data.payload || data }
    const delivered = this.clientManager.broadcastToSubscription(roomKey, event)
    console.log(`[RedisPubSub] Board room ${boardId} ${data.type} -> ${delivered} clients`)
  }

  /**
   * Handle global event (broadcast to all connected clients)
   * @param {object} data 
   */
  async handleGlobalEvent(data) {
    console.log('[RedisPubSub] Global event:', data.type)
    
    // Create event for each connected user
    const connectedUsers = this.clientManager.getConnectedUsers()
    
    for (const userEmail of connectedUsers) {
      const event = await this.eventStore.createEvent(data.type, userEmail, data.payload || data)
      await this.clientManager.broadcastToUser(userEmail, event)
    }
  }

  /**
   * Publish an event to a user's channel (used by IMAP IDLE)
   * @param {string} userEmail 
   * @param {string} type 
   * @param {object} payload 
   */
  async publishEvent(userEmail, type, payload) {
    const channel = `${config.redis.prefix}mailbox:${userEmail}`
    const message = JSON.stringify({ type, payload })
    
    // Note: This publishes to Redis, which will be received by handleMessage
    // This allows multiple server instances to share events
    await this.redis.publish(channel, message)
  }

  /**
   * Shutdown pub/sub
   */
  async shutdown() {
    await this.redis.punsubscribe()
    await this.redis.unsubscribe()
    console.log('[RedisPubSub] Unsubscribed from all channels')
  }
}

