import { EventEmitter } from 'events'
import { LocalDatabase } from '../database/Database'
import { configStore } from '../config'
import { getAuthToken, getSessionToken } from '../secureStorage'
import { getOrCreateDeviceId } from '../deviceId'
import axios, { AxiosInstance } from 'axios'

/**
 * Base Sync Engine
 * 
 * Abstract class that provides common functionality for all sync engines.
 * Each module (Email, Calendar, Boards, Clients) extends this.
 * 
 * Sync Strategy:
 * 1. Local-first: User actions update local DB instantly
 * 2. If online: Push to server immediately
 * 3. If offline: Queue for later sync
 * 4. Pull periodically and on WebSocket events
 */
export abstract class BaseSyncEngine extends EventEmitter {
  protected db: LocalDatabase
  protected api: AxiosInstance
  protected isOnline = false
  protected syncInterval: NodeJS.Timeout | null = null
  protected isSyncing = false

  // To be defined by subclasses
  abstract entityType: string
  abstract pullChanges(): Promise<void>
  abstract pushChange(queueItem: QueueItem): Promise<void>
  abstract handleEvent(event: SyncEvent): Promise<void>

  constructor(db: LocalDatabase) {
    super()
    this.db = db
    
    // Setup API client
    const apiUrl = configStore.get('apiUrl') || 'https://flowone.pro'
    this.api = axios.create({
      baseURL: `${apiUrl}/api`,
      timeout: 30000,
    })
    
    // Add auth headers to all requests (JWT + session token + device ID)
    this.api.interceptors.request.use((config) => {
      const token = getAuthToken()
      if (token) {
        config.headers.Authorization = `Bearer ${token}`
      }
      // Session token is required by backend's requireImap() for IMAP password lookup
      const sessionToken = getSessionToken()
      if (sessionToken) {
        config.headers['X-Session-Token'] = sessionToken
      }
      // Device ID for device tracking
      const deviceId = getOrCreateDeviceId()
      if (deviceId) {
        config.headers['X-Device-Id'] = deviceId
      }
      return config
    })
    
    // Handle auth errors and rate limiting (429)
    this.api.interceptors.response.use(
      (response) => response,
      async (error) => {
        if (error.response?.status === 401) {
          this.emit('auth-failed')
        }

        if (error.response?.status === 429) {
          const retryAfter = error.response?.data?.retry_after || error.response?.headers?.['retry-after'] || 2
          const waitMs = (Number(retryAfter) + 1) * 1000
          console.warn(`[${this.entityType}Sync] Rate limited (429) - waiting ${waitMs}ms before retry`)
          await new Promise(resolve => setTimeout(resolve, waitMs))
          return this.api.request(error.config)
        }

        throw error
      }
    )
  }

  /**
   * Initialize the sync engine
   */
  async initialize(): Promise<void> {
    console.log(`[${this.entityType}Sync] Initializing...`)
    // Periodic sync is managed by SyncManager with staggered delays
    // to avoid hitting API rate limits (300 req/60s)
  }

  /**
   * Update online status
   */
  setOnline(online: boolean): void {
    const wasOffline = !this.isOnline
    this.isOnline = online
    
    if (online && wasOffline) {
      // Just came online - process queue
      this.processQueue()
    }
  }

  /**
   * Full sync - pull all changes from server
   */
  async sync(): Promise<void> {
    if (!this.isOnline || this.isSyncing) {
      console.log(`[${this.entityType}Sync] Skipping sync (online: ${this.isOnline}, syncing: ${this.isSyncing})`)
      return
    }

    try {
      this.isSyncing = true
      this.emit('sync-start')
      
      // Push local changes first
      await this.processQueue()
      
      // Then pull remote changes
      await this.pullChanges()
      
      // Update sync timestamp (INSERT OR REPLACE to self-heal missing rows)
      this.db.prepare(
        `INSERT INTO sync_state (entity_type, last_sync_at)
         VALUES (?, datetime("now"))
         ON CONFLICT(entity_type) DO UPDATE SET last_sync_at = datetime("now")`
      ).run(this.entityType)
      
      this.emit('sync-complete')
    } catch (error: any) {
      const errorMsg = error?.message || error?.response?.data?.message || String(error) || 'Unknown sync error'
      console.error(`[${this.entityType}Sync] Sync failed:`, errorMsg)
      this.emit('sync-error', errorMsg)
    } finally {
      this.isSyncing = false
    }
  }

  /**
   * Process offline queue for this entity type
   */
  async processQueue(): Promise<void> {
    const pending = this.db.prepare(
      'SELECT * FROM sync_queue WHERE entity_type = ? ORDER BY created_at ASC'
    ).all(this.entityType) as QueueItem[]

    if (pending.length === 0) return

    console.log(`[${this.entityType}Sync] Processing ${pending.length} queued changes`)

    for (const item of pending) {
      try {
        await this.pushChange(item)
        
        // Remove from queue on success
        this.db.removePendingChange(item.id)
      } catch (error: any) {
        console.error(`[${this.entityType}Sync] Failed to push change ${item.id}:`, error.message)
        
        // Mark attempt and store error
        this.db.markChangeAttempted(item.id, error.message)
        
        // If too many attempts, move to dead letter queue or skip
        if (item.attempts >= 5) {
          console.error(`[${this.entityType}Sync] Giving up on change ${item.id} after ${item.attempts} attempts`)
          this.db.prepare(
            'UPDATE sync_queue SET action = ? WHERE id = ?'
          ).run('failed_' + item.action, item.id)
        }
      }
    }
    
    this.emit('queue-processed')
  }

  /**
   * Queue a change for offline sync
   */
  queueChange(action: string, entityId: number | null, payload: object): void {
    const id = this.db.queueChange(this.entityType, entityId, action, payload)
    console.log(`[${this.entityType}Sync] Queued change: ${action} (id: ${id})`)
    this.emit('change-queued', { id, action, entityId })
  }

  /**
   * Perform an action with offline support
   * 
   * @param localAction Function that updates local DB (returns result)
   * @param remoteAction Function that syncs to server
   * @param queueData Data to queue if offline
   */
  async performAction<T>(
    localAction: () => T,
    remoteAction: () => Promise<void>,
    queueData: { action: string; entityId: number | null; payload: object }
  ): Promise<T> {
    // 1. Always execute locally first (instant feedback)
    const result = localAction()

    // 2. If online, sync immediately
    if (this.isOnline) {
      try {
        await remoteAction()
      } catch (error: any) {
        console.error(`[${this.entityType}Sync] Remote action failed, queueing:`, error.message)
        this.queueChange(queueData.action, queueData.entityId, queueData.payload)
      }
    } else {
      // 3. Offline - queue for later
      this.queueChange(queueData.action, queueData.entityId, queueData.payload)
    }

    return result
  }

  /**
   * Start periodic sync
   */
  protected startPeriodicSync(): void {
    const interval = configStore.get('syncInterval') * 1000 || 60000
    
    this.syncInterval = setInterval(() => {
      if (this.isOnline && !this.isSyncing) {
        this.sync()
      }
    }, interval)
  }

  /**
   * Stop periodic sync
   */
  protected stopPeriodicSync(): void {
    if (this.syncInterval) {
      clearInterval(this.syncInterval)
      this.syncInterval = null
    }
  }

  /**
   * Get sync cursor for delta sync
   */
  protected getSyncCursor(): string | null {
    return this.db.getSyncCursor(this.entityType)
  }

  /**
   * Update sync cursor
   */
  protected updateSyncCursor(cursor: string): void {
    this.db.updateSyncCursor(this.entityType, cursor)
  }

  /**
   * Shutdown the sync engine
   */
  shutdown(): void {
    this.stopPeriodicSync()
    this.removeAllListeners()
    console.log(`[${this.entityType}Sync] Shutdown complete`)
  }
}

/**
 * Queue item from sync_queue table
 */
export interface QueueItem {
  id: number
  entity_type: string
  entity_id: number | null
  action: string
  payload: string // JSON string
  created_at: string
  attempts: number
  last_error: string | null
  last_attempt_at: string | null
}

/**
 * Sync event from WebSocket
 */
export interface SyncEvent {
  eventId: string
  type: string
  timestamp: number
  version: number
  userEmail: string
  payload: any
}

