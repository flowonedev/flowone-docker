/**
 * WebSocket Client Manager
 * 
 * Tracks connected clients and provides methods for broadcasting
 * events to specific users or all clients.
 */

import { config } from '../config.js'
import { EventTypes } from '../events/eventTypes.js'
import { v4 as uuidv4 } from 'uuid'

export class ClientManager {
  constructor() {
    // Map of userEmail -> Set<WebSocket>
    this.userClients = new Map()
    
    // Map of WebSocket -> client info
    this.clientInfo = new Map()
    
    // Processed event IDs per client (for idempotency on client side)
    // The server tracks this to help clients that reconnect
    this.clientLastVersion = new Map()
    
    // Heartbeat tracking
    this.heartbeatTimers = new Map()
    
    // Presence tracking: Map of userEmail -> { status, lastActivity, organizationDomain }
    this.userPresence = new Map()
    
    // Organization domains: Map of domain -> Set<userEmail>
    this.organizationUsers = new Map()
    
    // Cross-domain presence subscriptions:
    // Map of watchedEmail -> Set<subscriberEmail>
    // When watchedEmail's presence changes, notify all subscriberEmails
    this.crossDomainPresenceSubs = new Map()
    
    // Away timeout (2 minutes of inactivity - reduced for better UX)
    this.AWAY_TIMEOUT = 2 * 60 * 1000
    
    // Presence broadcast batching to reduce O(n^2) message volume.
    // Instead of broadcasting each individual status change immediately,
    // queue them and flush a single PRESENCE_BULK_UPDATE every interval.
    // At 200 users, this reduces ~160K WS messages/hour to ~32K.
    this.presenceBatchQueue = new Map()  // domain -> Map<email, { status, eventType }>
    this.presenceCrossDomainBatchQueue = new Map()  // email -> { status, subscribers: Set }
    this.PRESENCE_BATCH_INTERVAL = 5000  // 5 seconds
    this.presenceBatchTimer = null
    
    // Presence check interval
    this.presenceCheckInterval = null
    this.startPresenceChecker()
    this.startPresenceBatcher()
  }
  
  /**
   * Start periodic presence checker (marks users as away after inactivity)
   */
  startPresenceChecker() {
    this.presenceCheckInterval = setInterval(() => {
      const now = Date.now()
      
      for (const [email, presence] of this.userPresence) {
        // Skip users who manually set their status
        if (presence.manualStatus) continue
        
        const timeSinceActivity = now - presence.lastActivity
        
        // If active and inactive for too long, mark as away
        if (presence.status === 'active' && timeSinceActivity > this.AWAY_TIMEOUT) {
          this.updateUserStatus(email, 'away', false)
        }
      }
    }, 60000) // Check every minute
  }

  /**
   * Add a new client connection
   * @param {WebSocket} ws - WebSocket connection
   * @param {object} userInfo - Decoded JWT payload
   */
  addClient(ws, userInfo) {
    // Normalize email to lowercase for consistent lookups across
    // userClients, userPresence, organizationUsers, and crossDomainPresenceSubs
    const userEmail = userInfo.email.toLowerCase()
    
    // Add to user's client set
    if (!this.userClients.has(userEmail)) {
      this.userClients.set(userEmail, new Set())
    }
    this.userClients.get(userEmail).add(ws)
    
    // Extract display name from JWT claims (name, display_name, displayName) or derive from email
    const displayName = userInfo.displayName || userInfo.name || userInfo.display_name || userEmail.split('@')[0]
    
    // Store client info
    this.clientInfo.set(ws, {
      userEmail,
      displayName,
      userInfo,
      isMoodGuest: !!userInfo.isMoodGuest,
      allowedBoardId: userInfo.allowedBoardId || null,
      connectedAt: Date.now(),
      lastActivity: Date.now(),
      subscribedFolders: new Set(userInfo.isMoodGuest ? [] : ['INBOX']),
      subscriptionMode: userInfo.isMoodGuest ? 'mood_guest' : 'folders',
      subscriptions: new Set(),
    })
    
    // Start heartbeat monitoring
    this.startHeartbeat(ws)
    
    // Update presence - mark user as online
    const domain = this.getDomainFromEmail(userEmail)
    this.setUserOnline(userEmail, domain)
    
    console.log(`[ClientManager] Client connected: ${userEmail} (total: ${this.getTotalClients()})`)
  }
  
  /**
   * Extract domain from email
   */
  getDomainFromEmail(email) {
    return email.split('@')[1]?.toLowerCase() || ''
  }
  
  /**
   * Set user as online and broadcast to organization
   */
  setUserOnline(userEmail, domain) {
    const emailLower = userEmail.toLowerCase()
    const wasOffline = !this.userPresence.has(emailLower) || 
                       this.userPresence.get(emailLower).status === 'offline'
    
    // Track user in organization
    if (!this.organizationUsers.has(domain)) {
      this.organizationUsers.set(domain, new Set())
    }
    this.organizationUsers.get(domain).add(emailLower)
    
    // Set presence (always use lowercase key for consistent lookups)
    const existingPresence = this.userPresence.get(emailLower)
    this.userPresence.set(emailLower, {
      status: existingPresence?.manualStatus ? existingPresence.status : 'active',
      lastActivity: Date.now(),
      organizationDomain: domain,
      manualStatus: existingPresence?.manualStatus || false
    })
    
    // Broadcast presence change to organization if they were offline
    if (wasOffline) {
      this.broadcastPresenceToOrganization(domain, emailLower, 'active', EventTypes.PRESENCE_ONLINE)
    }
  }
  
  /**
   * Update user's activity timestamp (called on any user action)
   */
  updateUserActivity(userEmail, currentView = null) {
    const emailLower = userEmail.toLowerCase()
    const presence = this.userPresence.get(emailLower)
    if (presence) {
      const wasAway = presence.status === 'away'
      presence.lastActivity = Date.now()
      if (currentView !== null) {
        presence.currentView = currentView
      }
      
      // If was away and not manually set, become active again
      if (wasAway && !presence.manualStatus) {
        presence.status = 'active'
        this.broadcastPresenceToOrganization(
          presence.organizationDomain, 
          emailLower, 
          'active',
          EventTypes.PRESENCE_STATUS_CHANGED
        )
      }
    }
  }
  
  /**
   * Update user's status (active, away, do_not_disturb)
   */
  updateUserStatus(userEmail, status, isManual = false) {
    const emailLower = userEmail.toLowerCase()
    const presence = this.userPresence.get(emailLower)
    if (presence && presence.status !== status) {
      presence.status = status
      presence.manualStatus = isManual
      presence.lastActivity = Date.now()
      
      this.broadcastPresenceToOrganization(
        presence.organizationDomain,
        emailLower,
        status,
        EventTypes.PRESENCE_STATUS_CHANGED
      )
    }
  }
  
  /**
   * Get current presence status for a user
   */
  getUserPresence(userEmail) {
    return this.userPresence.get(userEmail.toLowerCase()) || { status: 'offline', lastActivity: null }
  }
  
  /**
   * Get all online users in an organization
   */
  getOrganizationPresence(domain) {
    const users = this.organizationUsers.get(domain)
    if (!users) return {}

    const presence = {}
    for (const email of users) {
      const userPresence = this.userPresence.get(email)
      if (userPresence) {
        presence[email] = {
          status: userPresence.status,
          lastActivity: userPresence.lastActivity,
          currentView: userPresence.currentView || null
        }
      }
    }
    return presence
  }
  
  /**
   * Subscribe a user to specific other users' presence (cross-domain support)
   * Used when users have chat conversations with people on different email domains.
   * @param {string} subscriberEmail - The user who wants updates
   * @param {string[]} watchedEmails - Emails to watch for presence changes
   */
  subscribeToCrossDomainPresence(subscriberEmail, watchedEmails) {
    const subscriberLower = subscriberEmail.toLowerCase()
    const subscriberDomain = this.getDomainFromEmail(subscriberLower)
    
    for (const watchedEmail of watchedEmails) {
      const watchedLower = watchedEmail.toLowerCase()
      const watchedDomain = this.getDomainFromEmail(watchedLower)
      
      // Only track cross-domain subscriptions (same-domain already handled by org broadcast)
      if (watchedDomain === subscriberDomain) continue
      
      if (!this.crossDomainPresenceSubs.has(watchedLower)) {
        this.crossDomainPresenceSubs.set(watchedLower, new Set())
      }
      this.crossDomainPresenceSubs.get(watchedLower).add(subscriberLower)
    }
    
    console.log(`[ClientManager] ${subscriberEmail} subscribed to cross-domain presence for ${watchedEmails.length} users`)
  }
  
  /**
   * Get cross-domain presence data for a user (presence of their cross-domain contacts)
   * @param {string} userEmail
   * @returns {object} Map of email -> { status, lastActivity }
   */
  getCrossDomainPresence(userEmail) {
    const emailLower = userEmail.toLowerCase()
    const presence = {}
    
    // Find all emails this user is subscribed to watch
    for (const [watchedEmail, subscribers] of this.crossDomainPresenceSubs) {
      if (subscribers.has(emailLower)) {
        // watchedEmail is already lowercase (normalized in subscribeToCrossDomainPresence)
        const userPresence = this.userPresence.get(watchedEmail)
        if (userPresence) {
          presence[watchedEmail] = {
            status: userPresence.status,
            lastActivity: userPresence.lastActivity
          }
        }
      }
    }
    
    return presence
  }
  
  /**
   * Queue a presence update for batched broadcasting.
   * Instead of O(n) messages per status change, changes are collected
   * and flushed as a single PRESENCE_BULK_UPDATE every PRESENCE_BATCH_INTERVAL.
   */
  broadcastPresenceToOrganization(domain, userEmail, status, eventType) {
    const emailLower = userEmail.toLowerCase()
    
    // Queue the change for the next batch flush
    if (!this.presenceBatchQueue.has(domain)) {
      this.presenceBatchQueue.set(domain, new Map())
    }
    // Only keep the latest status per user (if they flip multiple times in one interval)
    this.presenceBatchQueue.get(domain).set(emailLower, { status, eventType })
    
    // Queue cross-domain broadcasts
    const crossDomainSubs = this.crossDomainPresenceSubs.get(emailLower)
    if (crossDomainSubs && crossDomainSubs.size > 0) {
      if (!this.presenceCrossDomainBatchQueue.has(emailLower)) {
        this.presenceCrossDomainBatchQueue.set(emailLower, { status, subscribers: new Set() })
      }
      const entry = this.presenceCrossDomainBatchQueue.get(emailLower)
      entry.status = status // Update to latest status
      for (const sub of crossDomainSubs) {
        entry.subscribers.add(sub)
      }
    }
  }
  
  /**
   * Start the presence batch flusher
   */
  startPresenceBatcher() {
    this.presenceBatchTimer = setInterval(() => {
      this.flushPresenceBatch()
    }, this.PRESENCE_BATCH_INTERVAL)
  }
  
  /**
   * Flush all queued presence changes as batched PRESENCE_BULK_UPDATE events.
   * One message per recipient instead of one message per status change.
   */
  flushPresenceBatch() {
    if (this.presenceBatchQueue.size === 0 && this.presenceCrossDomainBatchQueue.size === 0) return
    
    let totalSent = 0
    
    // Process each domain's accumulated changes
    for (const [domain, changes] of this.presenceBatchQueue) {
      if (changes.size === 0) continue
      
      const orgUsers = this.organizationUsers.get(domain)
      if (!orgUsers) continue
      
      // Build batch payload: { email: { status, lastActivity, currentView }, ... }
      const batchPayload = {}
      for (const [email, { status }] of changes) {
        const userPres = this.userPresence.get(email)
        batchPayload[email] = {
          status,
          lastActivity: Date.now(),
          currentView: userPres?.currentView || null
        }
      }
      
      // Send one batch event to each org user
      for (const recipientEmail of orgUsers) {
        if (!this.hasConnectedClients(recipientEmail)) continue
        
        // Exclude the recipient's own status (they already know their own status)
        const payloadForRecipient = {}
        for (const [email, data] of Object.entries(batchPayload)) {
          if (email !== recipientEmail) {
            payloadForRecipient[email] = data
          }
        }
        
        if (Object.keys(payloadForRecipient).length === 0) continue
        
        const event = {
          eventId: uuidv4(),
          type: EventTypes.PRESENCE_BULK_UPDATE,
          timestamp: Date.now(),
          payload: { presence: payloadForRecipient }
        }
        this.broadcastToSubscribed(recipientEmail, event, 'presence')
        totalSent++
      }
    }
    
    // Process cross-domain presence broadcasts
    for (const [email, { status, subscribers }] of this.presenceCrossDomainBatchQueue) {
      const event = {
        eventId: uuidv4(),
        type: EventTypes.PRESENCE_STATUS_CHANGED,
        timestamp: Date.now(),
        payload: { userEmail: email, status, lastActivity: Date.now() }
      }
      
      for (const subscriberEmail of subscribers) {
        if (subscriberEmail !== email && this.hasConnectedClients(subscriberEmail)) {
          this.broadcastToSubscribed(subscriberEmail, event, 'presence')
          totalSent++
        }
      }
    }
    
    if (totalSent > 0) {
      console.log(`[ClientManager] Presence batch flushed: ${this.presenceBatchQueue.size} domains, ${totalSent} messages sent`)
    }
    
    // Clear queues
    this.presenceBatchQueue.clear()
    this.presenceCrossDomainBatchQueue.clear()
  }
  
  /**
   * Send bulk presence update to a newly connected user
   * Includes both same-domain organization presence AND cross-domain contacts
   */
  sendBulkPresenceUpdate(ws, userEmail) {
    const info = this.clientInfo.get(ws)
    if (!info) return
    
    const domain = this.getDomainFromEmail(userEmail)
    const orgPresence = this.getOrganizationPresence(domain)
    
    // Merge with cross-domain presence
    const crossDomainPresence = this.getCrossDomainPresence(userEmail)
    const presence = { ...orgPresence, ...crossDomainPresence }
    
    const event = {
      eventId: uuidv4(),
      type: EventTypes.PRESENCE_BULK_UPDATE,
      timestamp: Date.now(),
      payload: {
        presence
      }
    }
    
    this.sendToClient(ws, event)
  }

  /**
   * Remove a client connection
   * @param {WebSocket} ws 
   */
  removeClient(ws) {
    const info = this.clientInfo.get(ws)
    if (!info) return

    const userEmail = info.userEmail
    
    // Broadcast mood board leave events for any mood board rooms this client was in
    for (const sub of info.subscriptions) {
      if (sub.startsWith('mood_board:')) {
        const boardId = parseInt(sub.replace('mood_board:', ''))
        if (!isNaN(boardId)) {
          const leaveEvent = {
            type: EventTypes.MOOD_BOARD_PRESENCE_LEAVE,
            payload: {
              board_id: boardId,
              user_email: userEmail,
              timestamp: Date.now()
            }
          }
          this.broadcastToSubscription(sub, leaveEvent, ws)
        }
      }
    }
    
    // Remove from user's client set
    const clients = this.userClients.get(userEmail)
    if (clients) {
      clients.delete(ws)
      if (clients.size === 0) {
        this.userClients.delete(userEmail)
        
        // Only broadcast offline presence for real users, not mood guests
        if (!info.isMoodGuest) {
          this.setUserOffline(userEmail)
        }
      }
    }
    
    // Clean up
    this.clientInfo.delete(ws)
    this.clientLastVersion.delete(ws)
    this.stopHeartbeat(ws)
    
    console.log(`[ClientManager] Client disconnected: ${userEmail} (total: ${this.getTotalClients()})`)
    
    return info
  }
  
  /**
   * Set user as offline and broadcast to organization
   */
  setUserOffline(userEmail) {
    const emailLower = userEmail.toLowerCase()
    const presence = this.userPresence.get(emailLower)
    if (!presence) return
    
    const domain = presence.organizationDomain
    
    // Broadcast offline status before removing
    this.broadcastPresenceToOrganization(domain, emailLower, 'offline', EventTypes.PRESENCE_OFFLINE)
    
    // Remove from presence tracking
    this.userPresence.delete(emailLower)
    
    // Remove from organization users
    const orgUsers = this.organizationUsers.get(domain)
    if (orgUsers) {
      orgUsers.delete(emailLower)
      if (orgUsers.size === 0) {
        this.organizationUsers.delete(domain)
      }
    }
  }

  /**
   * Get client info
   * @param {WebSocket} ws 
   * @returns {object|undefined}
   */
  getClientInfo(ws) {
    return this.clientInfo.get(ws)
  }

  /**
   * Update client's last activity time
   * @param {WebSocket} ws 
   */
  updateActivity(ws) {
    const info = this.clientInfo.get(ws)
    if (info) {
      info.lastActivity = Date.now()
    }
  }

  /**
   * Set client's last processed version (for reconnect replay)
   * @param {WebSocket} ws 
   * @param {number} version 
   */
  setLastVersion(ws, version) {
    this.clientLastVersion.set(ws, version)
  }

  /**
   * Get client's last processed version
   * @param {WebSocket} ws 
   * @returns {number}
   */
  getLastVersion(ws) {
    return this.clientLastVersion.get(ws) || 0
  }

  /**
   * Subscribe client to folder updates
   * @param {WebSocket} ws 
   * @param {string} folder 
   */
  subscribeToFolder(ws, folder) {
    const info = this.clientInfo.get(ws)
    if (info) {
      info.subscribedFolders.add(folder)
      console.log(`[ClientManager] ${info.userEmail} subscribed to ${folder}`)
    }
  }

  /**
   * Unsubscribe client from folder updates
   * @param {WebSocket} ws 
   * @param {string} folder 
   */
  unsubscribeFromFolder(ws, folder) {
    const info = this.clientInfo.get(ws)
    if (info) {
      info.subscribedFolders.delete(folder)
      console.log(`[ClientManager] ${info.userEmail} unsubscribed from ${folder}`)
    }
  }

  /**
   * Set subscription mode for desktop app
   * @param {WebSocket} ws 
   * @param {string} mode - 'folders' or 'all'
   */
  setSubscriptionMode(ws, mode) {
    const info = this.clientInfo.get(ws)
    if (info) {
      info.subscriptionMode = mode
      if (mode === 'all') {
        // Subscribe to all entity types
        info.subscriptions.add('calendars')
        info.subscriptions.add('boards')
        info.subscriptions.add('clients')
        info.subscriptions.add('drive')
        info.subscriptions.add('todos')
        info.subscriptions.add('chat')
        info.subscriptions.add('presence')
      }
    }
  }

  /**
   * Add entity subscription (calendars, boards, clients, drive)
   * @param {WebSocket} ws 
   * @param {string} entityType 
   */
  addSubscription(ws, entityType) {
    const info = this.clientInfo.get(ws)
    if (info) {
      info.subscriptions.add(entityType)
    }
  }

  /**
   * Remove entity subscription
   * @param {WebSocket} ws 
   * @param {string} entityType 
   */
  removeSubscription(ws, entityType) {
    const info = this.clientInfo.get(ws)
    if (info) {
      info.subscriptions.delete(entityType)
    }
  }

  /**
   * Check if client is subscribed to entity type
   * @param {WebSocket} ws 
   * @param {string} entityType 
   * @returns {boolean}
   */
  isSubscribedTo(ws, entityType) {
    const info = this.clientInfo.get(ws)
    if (!info) return false
    
    // 'all' mode subscribes to everything
    if (info.subscriptionMode === 'all') return true
    
    return info.subscriptions.has(entityType)
  }

  /**
   * Broadcast event to subscribed clients only
   * @param {string} userEmail 
   * @param {object} event 
   * @param {string} entityType - Type of entity (calendars, boards, clients, drive)
   */
  async broadcastToSubscribed(userEmail, event, entityType) {
    const clients = this.userClients.get(userEmail.toLowerCase())
    if (!clients || clients.size === 0) {
      return 0
    }

    const message = JSON.stringify(event)
    let delivered = 0

    for (const ws of clients) {
      try {
        if (ws.readyState === 1 && this.isSubscribedTo(ws, entityType)) {
          ws.send(message)
          delivered++
        }
      } catch (error) {
        console.error(`[ClientManager] Failed to send to client:`, error)
      }
    }

    return delivered
  }

  /**
   * Get all connected users
   * @returns {string[]}
   */
  getConnectedUsers() {
    return Array.from(this.userClients.keys())
  }

  /**
   * Check if user has any connected clients
   * @param {string} userEmail 
   * @returns {boolean}
   */
  hasConnectedClients(userEmail) {
    const clients = this.userClients.get(userEmail.toLowerCase())
    return clients && clients.size > 0
  }

  /**
   * Get number of connected clients for a user
   * @param {string} userEmail 
   * @returns {number}
   */
  getClientCount(userEmail) {
    const clients = this.userClients.get(userEmail.toLowerCase())
    return clients ? clients.size : 0
  }

  /**
   * Get total number of connected clients
   * @returns {number}
   */
  getTotalClients() {
    let total = 0
    for (const clients of this.userClients.values()) {
      total += clients.size
    }
    return total
  }

  /**
   * Broadcast event to all clients of a user
   * @param {string} userEmail 
   * @param {object} event 
   */
  async broadcastToUser(userEmail, event) {
    const clients = this.userClients.get(userEmail.toLowerCase())
    if (!clients || clients.size === 0) {
      return 0
    }

    const message = JSON.stringify(event)
    let delivered = 0

    for (const ws of clients) {
      try {
        if (ws.readyState === 1) {  // WebSocket.OPEN
          ws.send(message)
          delivered++
        }
      } catch (error) {
        console.error(`[ClientManager] Failed to send to client:`, error)
      }
    }

    return delivered
  }

  /**
   * Broadcast event to all connected clients
   * @param {object} event 
   */
  async broadcastToAll(event) {
    const message = JSON.stringify(event)
    let delivered = 0

    for (const [userEmail, clients] of this.userClients) {
      for (const ws of clients) {
        try {
          if (ws.readyState === 1) {
            ws.send(message)
            delivered++
          }
        } catch (error) {
          console.error(`[ClientManager] Failed to send to client:`, error)
        }
      }
    }

    return delivered
  }

  /**
   * Broadcast to all clients subscribed to a specific subscription key
   * Used for mood board collaboration — sends to everyone in a "room"
   * @param {string} subscriptionKey - e.g. "mood_board:123"
   * @param {object} event - Event to broadcast
   * @param {WebSocket} excludeWs - Optional WebSocket to exclude (the sender)
   */
  broadcastToSubscription(subscriptionKey, event, excludeWs = null) {
    const message = JSON.stringify(event)
    let delivered = 0

    for (const [ws, info] of this.clientInfo) {
      try {
        if (ws.readyState === 1 && ws !== excludeWs && info.subscriptions.has(subscriptionKey)) {
          ws.send(message)
          delivered++
        }
      } catch (error) {
        console.error(`[ClientManager] Failed to broadcast to subscription:`, error)
      }
    }

    return delivered
  }

  /**
   * Get list of users subscribed to a specific subscription key
   * @param {string} subscriptionKey
   * @returns {Array} List of { email, name } objects
   */
  getSubscriptionMembers(subscriptionKey) {
    const members = []
    const seen = new Set()

    for (const [ws, info] of this.clientInfo) {
      if (ws.readyState === 1 && info.subscriptions.has(subscriptionKey) && !seen.has(info.userEmail)) {
        seen.add(info.userEmail)
        members.push({
          email: info.userEmail,
          name: info.displayName || info.userEmail
        })
      }
    }

    return members
  }

  /**
   * Send event to specific client
   * @param {WebSocket} ws 
   * @param {object} event 
   */
  sendToClient(ws, event) {
    try {
      if (ws.readyState === 1) {
        ws.send(JSON.stringify(event))
        return true
      }
    } catch (error) {
      console.error(`[ClientManager] Failed to send to client:`, error)
    }
    return false
  }

  /**
   * Start heartbeat monitoring for a client
   * @param {WebSocket} ws 
   */
  startHeartbeat(ws) {
    const timer = setInterval(() => {
      const info = this.clientInfo.get(ws)
      if (!info) {
        this.stopHeartbeat(ws)
        return
      }

      const timeSinceActivity = Date.now() - info.lastActivity
      
      // If client hasn't responded in timeout period, disconnect
      if (timeSinceActivity > config.performance.clientTimeout) {
        console.log(`[ClientManager] Client timeout: ${info.userEmail}`)
        ws.terminate()
        this.removeClient(ws)
        return
      }

      // Send ping
      if (ws.readyState === 1) {
        ws.ping()
      }
    }, config.performance.heartbeatInterval)

    this.heartbeatTimers.set(ws, timer)
  }

  /**
   * Stop heartbeat monitoring for a client
   * @param {WebSocket} ws 
   */
  stopHeartbeat(ws) {
    const timer = this.heartbeatTimers.get(ws)
    if (timer) {
      clearInterval(timer)
      this.heartbeatTimers.delete(ws)
    }
  }

  /**
   * Get statistics
   * @returns {object}
   */
  getStats() {
    const stats = {
      totalClients: this.getTotalClients(),
      uniqueUsers: this.userClients.size,
      userStats: {},
    }

    for (const [userEmail, clients] of this.userClients) {
      stats.userStats[userEmail] = {
        clients: clients.size,
      }
    }

    return stats
  }

  /**
   * Shutdown - close all connections
   */
  async shutdown() {
    // Stop presence checker and batcher
    if (this.presenceCheckInterval) {
      clearInterval(this.presenceCheckInterval)
      this.presenceCheckInterval = null
    }
    if (this.presenceBatchTimer) {
      clearInterval(this.presenceBatchTimer)
      this.presenceBatchTimer = null
    }
    // Flush any remaining presence changes before shutdown
    this.flushPresenceBatch()
    
    for (const [ws, info] of this.clientInfo) {
      try {
        this.stopHeartbeat(ws)
        ws.close(1001, 'Server shutting down')
      } catch (e) {
        // Ignore
      }
    }
    
    this.userClients.clear()
    this.clientInfo.clear()
    this.clientLastVersion.clear()
    this.userPresence.clear()
    this.organizationUsers.clear()
    this.crossDomainPresenceSubs.clear()
    this.presenceBatchQueue.clear()
    this.presenceCrossDomainBatchQueue.clear()
    
    console.log('[ClientManager] All clients disconnected')
  }
}


