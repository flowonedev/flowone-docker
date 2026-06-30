/**
 * Event Store
 * 
 * Buffers recent events in Redis for replay on reconnection.
 * Enables clients to catch up on missed events by requesting
 * events since their last processed version.
 */

import { v4 as uuidv4 } from 'uuid'
import { config } from '../config.js'
import { EventTypes } from './eventTypes.js'

export class EventStore {
  constructor(redis) {
    this.redis = redis
    this.bufferSize = config.redis.eventBufferSize
    this.bufferTtl = config.redis.eventBufferTtl
    
    // In-memory version counters (persisted to Redis periodically)
    this.userVersions = new Map()
    
    // Flush interval for version persistence
    this.flushInterval = null
  }

  /**
   * Initialize the event store
   */
  async init() {
    // Start periodic flush of version counters
    this.flushInterval = setInterval(() => this.flushVersions(), 10000)
    console.log('[EventStore] Initialized with buffer size:', this.bufferSize)
  }

  /**
   * Shutdown the event store
   */
  async shutdown() {
    if (this.flushInterval) {
      clearInterval(this.flushInterval)
    }
    await this.flushVersions()
  }

  /**
   * Get the next version number for a user
   * @param {string} userEmail 
   * @returns {Promise<number>}
   */
  async getNextVersion(userEmail) {
    // Try memory first
    let version = this.userVersions.get(userEmail)
    
    if (version === undefined) {
      // Load from Redis
      const key = `${config.redis.prefix}sync:version:${userEmail}`
      const stored = await this.redis.get(key)
      version = stored ? parseInt(stored, 10) : 0
    }
    
    version++
    this.userVersions.set(userEmail, version)
    
    return version
  }

  /**
   * Flush version counters to Redis
   */
  async flushVersions() {
    if (this.userVersions.size === 0) return
    
    const pipeline = this.redis.pipeline()
    
    for (const [userEmail, version] of this.userVersions) {
      const key = `${config.redis.prefix}sync:version:${userEmail}`
      pipeline.set(key, version.toString())
      // Expire after 24 hours of inactivity
      pipeline.expire(key, 86400)
    }
    
    await pipeline.exec()
  }

  /**
   * Create and store a new event
   * @param {string} type - Event type from EventTypes
   * @param {string} userEmail - Target user
   * @param {object} payload - Event payload
   * @returns {Promise<object>} The created event
   */
  async createEvent(type, userEmail, payload) {
    const version = await this.getNextVersion(userEmail)
    
    const event = {
      eventId: uuidv4(),
      type,
      timestamp: Date.now(),
      version,
      userEmail,
      payload,
    }
    
    // Store in Redis buffer
    await this.storeEvent(userEmail, event)
    
    return event
  }

  /**
   * Store an event in the user's event buffer
   * @param {string} userEmail 
   * @param {object} event 
   */
  async storeEvent(userEmail, event) {
    const key = `${config.redis.prefix}sync:events:${userEmail}`
    
    // Use a sorted set with version as score for ordering
    await this.redis.zadd(key, event.version, JSON.stringify(event))
    
    // Trim to buffer size (remove oldest)
    await this.redis.zremrangebyrank(key, 0, -this.bufferSize - 1)
    
    // Set TTL
    await this.redis.expire(key, this.bufferTtl)
  }

  /**
   * Get events since a specific version for replay
   * @param {string} userEmail 
   * @param {number} sinceVersion - Get events after this version
   * @returns {Promise<object[]>} Array of events
   */
  async getEventsSince(userEmail, sinceVersion) {
    const key = `${config.redis.prefix}sync:events:${userEmail}`
    
    // Get events with version > sinceVersion
    const results = await this.redis.zrangebyscore(
      key,
      `(${sinceVersion}`,  // Exclusive lower bound
      '+inf',
      'WITHSCORES'
    )
    
    // Parse results (alternating value, score)
    const events = []
    for (let i = 0; i < results.length; i += 2) {
      try {
        events.push(JSON.parse(results[i]))
      } catch (e) {
        console.error('[EventStore] Failed to parse event:', e)
      }
    }
    
    return events
  }

  /**
   * Get the current version for a user
   * @param {string} userEmail 
   * @returns {Promise<number>}
   */
  async getCurrentVersion(userEmail) {
    // Check memory first
    if (this.userVersions.has(userEmail)) {
      return this.userVersions.get(userEmail)
    }
    
    // Load from Redis
    const key = `${config.redis.prefix}sync:version:${userEmail}`
    const stored = await this.redis.get(key)
    return stored ? parseInt(stored, 10) : 0
  }

  /**
   * Clear all events for a user (used on logout or cache invalidation)
   * @param {string} userEmail 
   */
  async clearUserEvents(userEmail) {
    const eventsKey = `${config.redis.prefix}sync:events:${userEmail}`
    const versionKey = `${config.redis.prefix}sync:version:${userEmail}`
    
    await this.redis.del(eventsKey, versionKey)
    this.userVersions.delete(userEmail)
  }

  /**
   * Get event store statistics
   * @returns {Promise<object>}
   */
  async getStats() {
    return {
      activeUsers: this.userVersions.size,
      bufferSize: this.bufferSize,
      bufferTtl: this.bufferTtl,
    }
  }
}

