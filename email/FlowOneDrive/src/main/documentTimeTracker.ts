import axios, { AxiosInstance, AxiosError } from 'axios'
import fs from 'fs'
import path from 'path'
import { ConfigStore } from './config'
import { Database } from './database'
import { app, BrowserWindow } from 'electron'

/**
 * Document Time Tracker
 * 
 * Tracks time spent editing documents in the sync folder.
 * Associates time with clients via drive folder linking.
 * Sends accumulated time to backend periodically.
 * 
 * RELIABILITY FEATURES:
 * - Local file persistence for pending entries (survives crashes)
 * - Retry with exponential backoff on failures
 * - Sync on app quit with timeout guarantee
 */

interface TrackedDocument {
  filePath: string
  filename: string
  folderId: number | null
  clientId: number | null
  clientName: string | null
  startTime: number
  accumulatedSeconds: number
  lastActivity: number
}

interface TrackedWebsite {
  url: string
  domain: string
  boardId: number
  clientId: number
  clientName: string
  boardName: string
  displayName: string | null
  cardId: number | null
  startTime: number
  accumulatedSeconds: number
  lastActivity: number
}

interface FolderClientMapping {
  [folderId: string]: {
    client_id: number
    client_name: string
  }
}

interface PendingTimeEntry {
  id: string
  clientId: number
  activityType: 'document_open' | 'document_edit' | 'website_work'
  durationSeconds: number
  entityId: string
  entityName: string
  driveFileId?: number | null
  cardId?: number | null
  boardId?: number | null
  source?: 'cloud' | 'local_watch'
  retryCount: number
  createdAt: number
}

export class DocumentTimeTracker {
  private config: ConfigStore
  private db: Database
  private api: AxiosInstance | null = null
  private mainWindow: BrowserWindow | null = null
  
  // Currently tracked documents
  private activeDocuments: Map<string, TrackedDocument> = new Map()
  
  // Currently tracked websites
  private activeWebsites: Map<string, TrackedWebsite> = new Map()
  
  // Folder to client mapping (cached from backend)
  private folderClientMapping: FolderClientMapping = {}
  
  // Pending time entries to send (persisted to disk)
  private pendingEntries: PendingTimeEntry[] = []
  private pendingFilePath: string
  
  // Sync interval (send every 30 seconds)
  private syncInterval: NodeJS.Timeout | null = null
  private readonly SYNC_INTERVAL_MS = 30000
  
  // Inactivity threshold (5 minutes - consider document closed if no activity)
  private readonly INACTIVITY_THRESHOLD_MS = 5 * 60 * 1000
  
  // Minimum tracking time (5 seconds)
  private readonly MIN_TRACKING_SECONDS = 5
  
  // Retry settings
  private readonly MAX_RETRY_COUNT = 5
  private readonly BASE_RETRY_DELAY_MS = 5000  // 5s, 10s, 20s, 40s, 80s
  
  // Track if we've already emitted auth-failed to prevent duplicates
  private authFailedEmitted = false
  
  constructor(config: ConfigStore, db: Database) {
    this.config = config
    this.db = db
    
    // Set up persistent storage path
    const userDataPath = app.getPath('userData')
    this.pendingFilePath = path.join(userDataPath, 'pending-time-entries.json')
    
    // Load any pending entries from previous session
    this.loadPendingEntries()
    
    this.initializeApi()
  }
  
  /**
   * Set the main window for IPC communication
   */
  setMainWindow(window: BrowserWindow | null): void {
    this.mainWindow = window
  }
  
  private initializeApi(): void {
    const { getAuthToken, getSessionToken } = require('./secureStorage')
    const apiUrl = this.config.get('apiUrl')
    const authToken = getAuthToken()
    const sessionToken = getSessionToken()
    
    if (apiUrl && authToken) {
      const headers: Record<string, string> = {
        Authorization: `Bearer ${authToken}`,
        'Content-Type': 'application/json',
      }
      
      // Add session token if available
      if (sessionToken) {
        headers['X-Session-Token'] = sessionToken
      }
      
      // Wave A.5: 30 s timeout. Time-tracking syncs are small JSON posts;
      // letting them hang indefinitely on a network glitch held the whole
      // tracker stalled because syncs are serial.
      this.api = axios.create({
        baseURL: apiUrl,
        headers,
        timeout: 30_000,
      })

      // Reset auth failed flag when setting up new API
      this.authFailedEmitted = false
      
      // Add 401 interceptor
      this.api.interceptors.response.use(
        response => response,
        (error: AxiosError) => {
          if (error.response?.status === 401 && !this.authFailedEmitted) {
            console.log('[TimeTracker] 401 Unauthorized - auth token expired')
            this.authFailedEmitted = true
            this.handleAuthFailure()
          }
          return Promise.reject(error)
        }
      )
    }
  }
  
  /**
   * Handle authentication failure - notify the renderer
   */
  private handleAuthFailure(): void {
    console.log('[TimeTracker] Handling auth failure - notifying renderer')
    
    // Stop tracking
    this.stop()
    
    // Notify renderer
    this.mainWindow?.webContents.send('auth-failed')
  }
  
  /**
   * Load pending entries from disk (for crash recovery)
   */
  private loadPendingEntries(): void {
    try {
      if (fs.existsSync(this.pendingFilePath)) {
        const data = fs.readFileSync(this.pendingFilePath, 'utf-8')
        const loaded = JSON.parse(data) as PendingTimeEntry[]
        
        // Filter out entries that are too old (> 7 days) or have too many retries
        const now = Date.now()
        const maxAge = 7 * 24 * 60 * 60 * 1000  // 7 days
        
        this.pendingEntries = loaded.filter(entry => {
          const age = now - entry.createdAt
          return age < maxAge && entry.retryCount < this.MAX_RETRY_COUNT
        })
        
        if (this.pendingEntries.length > 0) {
          console.log(`[TimeTracker] Loaded ${this.pendingEntries.length} pending entries from disk`)
        }
        
        // If we filtered some out, save the cleaned list
        if (this.pendingEntries.length !== loaded.length) {
          this.savePendingEntries()
        }
      }
    } catch (error) {
      console.error('[TimeTracker] Failed to load pending entries:', error)
      this.pendingEntries = []
    }
  }
  
  /**
   * Save pending entries to disk (for crash recovery)
   */
  private savePendingEntries(): void {
    try {
      fs.writeFileSync(
        this.pendingFilePath, 
        JSON.stringify(this.pendingEntries, null, 2),
        'utf-8'
      )
    } catch (error) {
      console.error('[TimeTracker] Failed to save pending entries:', error)
    }
  }
  
  /**
   * Generate a unique ID for a time entry
   */
  private generateEntryId(): string {
    return `${Date.now()}-${Math.random().toString(36).substr(2, 9)}`
  }
  
  /**
   * Start the time tracker
   */
  start(): void {
    console.log('[TimeTracker] Starting document time tracker')

    this.refreshFolderMapping()

    // Wave C.3: register through IntervalRegistry. The tick is a fast no-op
    // when there are no active documents and no pending entries — the
    // event-driven flush triggered from `documentClosed` is what actually
    // drives most syncs. The 30 s timer remains as a safety net for
    // documents that stay open for hours.
    const { intervalRegistry } = require('./perf/IntervalRegistry')
    intervalRegistry.set('time-tracker.sync', this.SYNC_INTERVAL_MS, async () => {
      if (this.activeDocuments.size === 0 && this.pendingEntries.length === 0) {
        return
      }
      this.checkInactiveDocuments()
      await this.syncPendingTime()
    })
  }
  
  /**
   * Stop the time tracker
   */
  stop(): void {
    console.log('[TimeTracker] Stopping document time tracker')
    
    // Close all active documents
    for (const [filePath] of this.activeDocuments) {
      this.documentClosed(filePath)
    }
    
    // Close all active websites
    this.closeAllWebsites()
    
    // Final sync
    this.syncPendingTime()

    // Wave C.3: drain the registered interval.
    const { intervalRegistry } = require('./perf/IntervalRegistry')
    intervalRegistry.clear('time-tracker.sync')

    if (this.syncInterval) {
      clearInterval(this.syncInterval)
      this.syncInterval = null
    }
  }
  
  /**
   * Update credentials (after login)
   */
  setCredentials(apiUrl: string, authToken: string): void {
    const { getSessionToken } = require('./secureStorage')
    const sessionToken = getSessionToken()
    
    const headers: Record<string, string> = {
      Authorization: `Bearer ${authToken}`,
      'Content-Type': 'application/json',
    }
    
    if (sessionToken) {
      headers['X-Session-Token'] = sessionToken
    }
    
    // Wave A.5: same 30 s timeout for the credential-refresh path.
    this.api = axios.create({
      baseURL: apiUrl,
      headers,
      timeout: 30_000,
    })

    // Reset auth failed flag when setting new credentials
    this.authFailedEmitted = false
    
    // Add 401 interceptor
    this.api.interceptors.response.use(
      response => response,
      (error: AxiosError) => {
        if (error.response?.status === 401 && !this.authFailedEmitted) {
          console.log('[TimeTracker] 401 Unauthorized - auth token expired')
          this.authFailedEmitted = true
          this.handleAuthFailure()
        }
        return Promise.reject(error)
      }
    )
    
    // Refresh mapping with new credentials
    this.refreshFolderMapping()
  }
  
  /**
   * Fetch folder-to-client mapping from backend
   */
  async refreshFolderMapping(): Promise<void> {
    if (!this.api) {
      console.log('[TimeTracker] refreshFolderMapping: No API configured, skipping')
      return
    }
    
    try {
      console.log('[TimeTracker] Fetching folder-to-client mapping...')
      const response = await this.api.get('/api/clients/folder-mapping')
      
      if (response.data.success) {
        this.folderClientMapping = response.data.data.mapping || {}
        // Wave C.4: sampled — refreshes on every URL/folder change.
        const { logger } = require('./log/Logger')
        logger.tagged('TimeTracker.FolderMapping').debug('Folder mapping updated:')
        console.log(`[TimeTracker]   Total folders mapped: ${Object.keys(this.folderClientMapping).length}`)
        
        // Log each mapping for debugging
        for (const [folderId, info] of Object.entries(this.folderClientMapping)) {
          console.log(`[TimeTracker]   Folder ${folderId} -> Client "${info.client_name}" (ID: ${info.client_id})`)
        }
        
        // UPDATE existing tracked documents with new client info
        this.updateTrackedDocumentsClientInfo()
      } else {
        console.log('[TimeTracker] Folder mapping response not successful:', response.data)
      }
    } catch (error: any) {
      console.error('[TimeTracker] Failed to fetch folder mapping:', error?.message || error)
    }
  }
  
  /**
   * Update client info for all currently tracked documents
   * Called after folder mapping is refreshed
   */
  private updateTrackedDocumentsClientInfo(): void {
    for (const [filePath, doc] of this.activeDocuments) {
      if (doc.folderId !== null && !doc.clientId) {
        const mapping = this.folderClientMapping[doc.folderId.toString()]
        if (mapping) {
          doc.clientId = mapping.client_id
          doc.clientName = mapping.client_name
          console.log(`[TimeTracker] Updated client info for ${doc.filename}: ${mapping.client_name} (ID: ${mapping.client_id})`)
        }
      }
    }
  }
  
  /**
   * Called when a document is opened (lock file detected)
   */
  documentOpened(filePath: string, filename: string, folderId: number | null): void {
    console.log(`[TimeTracker] documentOpened called:`)
    console.log(`[TimeTracker]   filePath: ${filePath}`)
    console.log(`[TimeTracker]   filename: ${filename}`)
    console.log(`[TimeTracker]   folderId: ${folderId}`)
    
    // Check if already tracking
    if (this.activeDocuments.has(filePath)) {
      // Update last activity
      const doc = this.activeDocuments.get(filePath)!
      doc.lastActivity = Date.now()
      console.log(`[TimeTracker]   Already tracking, updated lastActivity`)
      return
    }
    
    // Find client from folder mapping
    let clientId: number | null = null
    let clientName: string | null = null
    
    if (folderId !== null) {
      const mapping = this.folderClientMapping[folderId.toString()]
      console.log(`[TimeTracker]   Folder mapping lookup for ${folderId}: ${mapping ? JSON.stringify(mapping) : 'NOT FOUND'}`)
      if (mapping) {
        clientId = mapping.client_id
        clientName = mapping.client_name
      }
    } else {
      console.log(`[TimeTracker]   No folder ID - cannot map to client`)
    }
    
    // Start tracking
    const doc: TrackedDocument = {
      filePath,
      filename,
      folderId,
      clientId,
      clientName,
      startTime: Date.now(),
      accumulatedSeconds: 0,
      lastActivity: Date.now(),
    }
    
    this.activeDocuments.set(filePath, doc)
    
    console.log(`[TimeTracker] Started tracking: ${filename}`)
    console.log(`[TimeTracker]   Client ID: ${clientId || 'NONE'}`)
    console.log(`[TimeTracker]   Client Name: ${clientName || 'NONE'}`)
    console.log(`[TimeTracker]   Active documents: ${this.activeDocuments.size}`)
  }
  
  /**
   * Called when a watched folder document is opened -- client context is known upfront
   */
  documentOpenedWithClient(
    filePath: string,
    filename: string,
    clientId: number,
    clientName: string,
    boardId?: number | null,
    cardId?: number | null,
    source?: 'cloud' | 'local_watch'
  ): void {
    if (this.activeDocuments.has(filePath)) {
      const doc = this.activeDocuments.get(filePath)!
      doc.lastActivity = Date.now()
      return
    }

    const doc: TrackedDocument & { boardId?: number | null; cardId?: number | null; source?: 'cloud' | 'local_watch' } = {
      filePath,
      filename,
      folderId: null,
      clientId,
      clientName,
      startTime: Date.now(),
      accumulatedSeconds: 0,
      lastActivity: Date.now(),
      boardId: boardId || null,
      cardId: cardId || null,
      source: source || 'local_watch',
    }

    this.activeDocuments.set(filePath, doc as any)
    console.log(`[TimeTracker] Started tracking (watch folder): ${filename} -> ${clientName}`)
  }

  /**
   * Called when a document is closed (lock file removed)
   */
  documentClosed(filePath: string): void {
    console.log(`[TimeTracker] documentClosed called: ${filePath}`)
    
    const doc = this.activeDocuments.get(filePath)
    if (!doc) {
      console.log(`[TimeTracker]   Document was not being tracked`)
      return
    }
    
    // Calculate final duration
    const sessionDuration = Math.round((Date.now() - doc.startTime) / 1000)
    const totalDuration = doc.accumulatedSeconds + sessionDuration
    
    console.log(`[TimeTracker]   Session duration: ${sessionDuration}s`)
    console.log(`[TimeTracker]   Total duration: ${totalDuration}s`)
    console.log(`[TimeTracker]   Client ID: ${doc.clientId || 'NONE'}`)
    console.log(`[TimeTracker]   Min tracking: ${this.MIN_TRACKING_SECONDS}s`)
    
    const docAnyPre = doc as any
    const isCardLinkedWatchFolder = docAnyPre.source === 'local_watch' && docAnyPre.cardId

    // Only track if above minimum and has a client
    // Skip client time for card-linked watch-folder files (backend work-session bridge handles it)
    if (totalDuration >= this.MIN_TRACKING_SECONDS && doc.clientId && !isCardLinkedWatchFolder) {
      let driveFileId: number | null = null
      try {
        const syncedFile = this.db.getFileByLocalPath(doc.filePath)
        if (syncedFile?.remoteId) {
          driveFileId = syncedFile.remoteId
          console.log(`[TimeTracker]   Resolved drive_file_id: ${driveFileId}`)
        }
      } catch (e) {
        console.log(`[TimeTracker]   Could not resolve drive_file_id: ${e}`)
      }

      const docAny = doc as any
      const entry: PendingTimeEntry = {
        id: this.generateEntryId(),
        clientId: doc.clientId,
        activityType: 'document_edit',
        durationSeconds: totalDuration,
        entityId: driveFileId ? String(driveFileId) : doc.filePath,
        entityName: doc.filename,
        driveFileId,
        boardId: docAny.boardId || null,
        source: docAny.source || 'cloud',
        retryCount: 0,
        createdAt: Date.now(),
      }
      
      this.pendingEntries.push(entry)
      
      // Persist to disk immediately for crash recovery
      this.savePendingEntries()
      
      console.log(`[TimeTracker] QUEUED: ${totalDuration}s for ${doc.filename}`)
      console.log(`[TimeTracker]   Client: ${doc.clientName} (ID: ${doc.clientId})`)
      console.log(`[TimeTracker]   Pending entries: ${this.pendingEntries.length}`)
    } else {
      if (isCardLinkedWatchFolder) {
        console.log(`[TimeTracker]   NOT QUEUED: Card-linked watch-folder file (backend work-session bridge handles client time)`)
      } else if (totalDuration < this.MIN_TRACKING_SECONDS) {
        console.log(`[TimeTracker]   NOT QUEUED: Duration below minimum (${totalDuration}s < ${this.MIN_TRACKING_SECONDS}s)`)
      } else if (!doc.clientId) {
        console.log(`[TimeTracker]   NOT QUEUED: No client linked to this folder`)
      }
    }
    
    this.activeDocuments.delete(filePath)
  }
  
  /**
   * Called when document is modified (file change detected)
   */
  documentActivity(filePath: string): void {
    const doc = this.activeDocuments.get(filePath)
    if (doc) {
      doc.lastActivity = Date.now()
    }
  }
  
  /**
   * Check for inactive documents and close them
   */
  private checkInactiveDocuments(): void {
    const now = Date.now()
    
    for (const [filePath, doc] of this.activeDocuments) {
      if (now - doc.lastActivity > this.INACTIVITY_THRESHOLD_MS) {
        console.log(`[TimeTracker] Closing inactive document: ${doc.filename}`)
        this.documentClosed(filePath)
      }
    }
  }
  
  /**
   * Send pending time entries to backend with retry logic
   */
  private async syncPendingTime(): Promise<void> {
    if (!this.api) {
      console.log('[TimeTracker] syncPendingTime: No API configured')
      return
    }
    
    if (this.pendingEntries.length === 0) {
      return // Don't log when nothing to sync
    }
    
    console.log(`[TimeTracker] ========== SYNCING ${this.pendingEntries.length} ENTRIES ==========`)
    
    // Process entries that are ready for retry (based on exponential backoff)
    const now = Date.now()
    const readyEntries: PendingTimeEntry[] = []
    const waitingEntries: PendingTimeEntry[] = []
    
    for (const entry of this.pendingEntries) {
      // Check if entry is ready for retry based on backoff
      const backoffDelay = this.BASE_RETRY_DELAY_MS * Math.pow(2, entry.retryCount)
      const timeSinceCreated = now - entry.createdAt
      const minWaitTime = entry.retryCount > 0 ? backoffDelay : 0
      
      if (timeSinceCreated >= minWaitTime) {
        readyEntries.push(entry)
      } else {
        waitingEntries.push(entry)
      }
    }
    
    if (readyEntries.length === 0) {
      console.log(`[TimeTracker] All ${this.pendingEntries.length} entries waiting for retry backoff`)
      return
    }
    
    console.log(`[TimeTracker] Processing ${readyEntries.length} entries (${waitingEntries.length} waiting for backoff)`)
    
    const successfulIds: string[] = []
    const failedEntries: PendingTimeEntry[] = []
    
    for (const entry of readyEntries) {
      try {
        console.log(`[TimeTracker] Syncing: ${entry.entityName}`)
        console.log(`[TimeTracker]   Duration: ${entry.durationSeconds}s`)
        console.log(`[TimeTracker]   Client ID: ${entry.clientId}`)
        console.log(`[TimeTracker]   Retry #${entry.retryCount}`)
        
        const payload: Record<string, any> = {
          activity_type: entry.activityType,
          duration_seconds: entry.durationSeconds,
          entity_id: entry.entityId,
          entity_name: entry.entityName,
        }
        if (entry.driveFileId) payload.drive_file_id = entry.driveFileId
        if (entry.cardId) payload.card_id = entry.cardId
        if (entry.boardId) payload.board_id = entry.boardId
        if (entry.source && entry.source !== 'cloud') payload.source = entry.source

        await this.api.post(`/api/clients/${entry.clientId}/time`, payload)
        
        console.log(`[TimeTracker] SUCCESS: Synced ${entry.durationSeconds}s to client ${entry.clientId}`)
        successfulIds.push(entry.id)
        
      } catch (error: any) {
        console.error('[TimeTracker] FAILED to sync time entry:')
        console.error(`[TimeTracker]   Error: ${error?.message || error}`)
        console.error(`[TimeTracker]   Response: ${error?.response?.data?.message || 'N/A'}`)
        
        // Increment retry count
        entry.retryCount++
        
        if (entry.retryCount >= this.MAX_RETRY_COUNT) {
          console.error(`[TimeTracker]   MAX RETRIES REACHED - Dropping entry for ${entry.entityName}`)
          // Don't re-queue - will be discarded
        } else {
          const nextRetryDelay = this.BASE_RETRY_DELAY_MS * Math.pow(2, entry.retryCount)
          console.log(`[TimeTracker]   Will retry in ${nextRetryDelay / 1000}s (attempt ${entry.retryCount + 1}/${this.MAX_RETRY_COUNT})`)
          failedEntries.push(entry)
        }
      }
    }
    
    // Update pending entries: keep waiting + failed, remove successful
    this.pendingEntries = [...waitingEntries, ...failedEntries]
    
    // Persist updated list to disk
    this.savePendingEntries()
    
    console.log(`[TimeTracker] ========== SYNC COMPLETE ==========`)
    console.log(`[TimeTracker]   Synced: ${successfulIds.length}`)
    console.log(`[TimeTracker]   Failed: ${failedEntries.length}`)
    console.log(`[TimeTracker]   Remaining: ${this.pendingEntries.length}`)
  }
  
  /**
   * Get current tracking stats (for UI display)
   */
  getStats(): { activeCount: number; pendingCount: number; activeDocuments: Array<{ filename: string; clientName: string | null; duration: number }> } {
    const now = Date.now()
    
    return {
      activeCount: this.activeDocuments.size,
      pendingCount: this.pendingEntries.length,
      activeDocuments: Array.from(this.activeDocuments.values()).map(doc => ({
        filename: doc.filename,
        clientName: doc.clientName,
        duration: doc.accumulatedSeconds + Math.round((now - doc.startTime) / 1000),
      })),
    }
  }
  
  /**
   * Force sync all active documents and websites (call before app quit)
   * Returns a promise that resolves when sync is complete or times out
   */
  async flushAll(): Promise<void> {
    console.log('[TimeTracker] Flushing all active documents and websites')
    
    // Close all active documents to queue their time
    for (const [filePath] of this.activeDocuments) {
      this.documentClosed(filePath)
    }
    
    // Close all active websites to queue their time
    this.closeAllWebsites()
    
    // Force all entries to be ready for sync (bypass backoff for quit)
    for (const entry of this.pendingEntries) {
      entry.createdAt = 0  // Makes it immediately ready
    }
    
    // Save the state in case sync fails - at least entries are persisted
    this.savePendingEntries()
    
    // Try to sync with a timeout
    try {
      await Promise.race([
        this.syncPendingTime(),
        new Promise((_, reject) => 
          setTimeout(() => reject(new Error('Sync timeout')), 5000)
        )
      ])
      console.log('[TimeTracker] Flush completed successfully')
    } catch (error) {
      console.error('[TimeTracker] Flush timeout or error - entries saved to disk for next session')
    }
  }
  
  /**
   * Get the client ID for a folder
   */
  getClientForFolder(folderId: number | null): { clientId: number; clientName: string } | null {
    if (folderId === null) return null
    
    const mapping = this.folderClientMapping[folderId.toString()]
    return mapping ? { clientId: mapping.client_id, clientName: mapping.client_name } : null
  }
  
  /**
   * Manually stop tracking a document (user action)
   * Returns true if the document was being tracked and was stopped
   */
  stopTrackingDocument(filename: string): boolean {
    console.log(`[TimeTracker] stopTrackingDocument called for: ${filename}`)
    
    // Find the document by filename
    for (const [filePath, doc] of this.activeDocuments) {
      if (doc.filename === filename || filePath.endsWith(filename)) {
        console.log(`[TimeTracker] Found document to stop: ${filePath}`)
        this.documentClosed(filePath)
        return true
      }
    }
    
    console.log(`[TimeTracker] Document not found in active tracking: ${filename}`)
    return false
  }
  
  /**
   * Get list of currently tracked documents (for UI)
   */
  getActiveDocuments(): Array<{ filename: string; folderId: number | null; clientName: string | null; duration: number }> {
    const now = Date.now()
    
    return Array.from(this.activeDocuments.values()).map(doc => ({
      filename: doc.filename,
      folderId: doc.folderId,
      clientName: doc.clientName,
      duration: doc.accumulatedSeconds + Math.round((now - doc.startTime) / 1000),
    }))
  }
  
  // ========================
  // WEBSITE TRACKING
  // ========================
  
  /**
   * Called when a tracked website is focused
   */
  websiteFocused(url: string, domain: string, mapping: { boardId: number; clientId: number; boardName: string; clientName: string; displayName?: string; cardId?: number | null }): void {
    console.log(`[TimeTracker] websiteFocused:`)
    console.log(`[TimeTracker]   URL: ${url}`)
    console.log(`[TimeTracker]   Domain: ${domain}`)
    console.log(`[TimeTracker]   Client: ${mapping.clientName} (ID: ${mapping.clientId})`)
    console.log(`[TimeTracker]   Board: ${mapping.boardName} (ID: ${mapping.boardId})`)
    
    // Check if already tracking
    if (this.activeWebsites.has(domain)) {
      // Update last activity
      const site = this.activeWebsites.get(domain)!
      site.lastActivity = Date.now()
      console.log(`[TimeTracker]   Already tracking, updated lastActivity`)
      return
    }
    
    const site: TrackedWebsite = {
      url,
      domain,
      boardId: mapping.boardId,
      clientId: mapping.clientId,
      clientName: mapping.clientName,
      boardName: mapping.boardName,
      displayName: mapping.displayName || null,
      cardId: mapping.cardId || null,
      startTime: Date.now(),
      accumulatedSeconds: 0,
      lastActivity: Date.now(),
    }
    
    this.activeWebsites.set(domain, site)
    
    console.log(`[TimeTracker] Started tracking website: ${domain}`)
    console.log(`[TimeTracker]   Active websites: ${this.activeWebsites.size}`)
  }
  
  /**
   * Called when a tracked website loses focus
   */
  websiteBlurred(domain: string): void {
    console.log(`[TimeTracker] websiteBlurred: ${domain}`)
    
    const site = this.activeWebsites.get(domain)
    if (!site) {
      console.log(`[TimeTracker]   Website was not being tracked`)
      return
    }
    
    // Calculate session duration
    const sessionDuration = Math.round((Date.now() - site.startTime) / 1000)
    const totalDuration = site.accumulatedSeconds + sessionDuration
    
    console.log(`[TimeTracker]   Session duration: ${sessionDuration}s`)
    console.log(`[TimeTracker]   Total duration: ${totalDuration}s`)
    console.log(`[TimeTracker]   Client ID: ${site.clientId}`)
    console.log(`[TimeTracker]   Min tracking: ${this.MIN_TRACKING_SECONDS}s`)
    
    if (totalDuration >= this.MIN_TRACKING_SECONDS) {
      const entry: PendingTimeEntry = {
        id: this.generateEntryId(),
        clientId: site.clientId,
        activityType: 'website_work',
        durationSeconds: totalDuration,
        entityId: site.domain,
        entityName: site.displayName || site.domain,
        cardId: site.cardId,
        retryCount: 0,
        createdAt: Date.now(),
      }
      
      this.pendingEntries.push(entry)
      
      // Persist to disk immediately for crash recovery
      this.savePendingEntries()
      
      console.log(`[TimeTracker] QUEUED: ${totalDuration}s for website ${site.domain}`)
      console.log(`[TimeTracker]   Client: ${site.clientName} (ID: ${site.clientId})`)
      console.log(`[TimeTracker]   Board: ${site.boardName} (ID: ${site.boardId})`)
      console.log(`[TimeTracker]   Pending entries: ${this.pendingEntries.length}`)
    } else {
      console.log(`[TimeTracker]   NOT QUEUED: Duration below minimum (${totalDuration}s < ${this.MIN_TRACKING_SECONDS}s)`)
    }
    
    this.activeWebsites.delete(domain)
  }
  
  /**
   * Get list of currently tracked websites (for UI)
   */
  getActiveWebsites(): Array<{ domain: string; clientName: string; boardName: string; duration: number }> {
    const now = Date.now()
    
    return Array.from(this.activeWebsites.values()).map(site => ({
      domain: site.displayName || site.domain,
      clientName: site.clientName,
      boardName: site.boardName,
      duration: site.accumulatedSeconds + Math.round((now - site.startTime) / 1000),
    }))
  }
  
  /**
   * Close all active websites (for app quit or monitoring stop)
   */
  closeAllWebsites(): void {
    console.log('[TimeTracker] Closing all active websites')
    
    for (const [domain] of this.activeWebsites) {
      this.websiteBlurred(domain)
    }
  }
  
  /**
   * Get debug data for troubleshooting time tracking issues
   */
  getDebugData(): {
    folderClientMapping: FolderClientMapping
    pendingEntries: PendingTimeEntry[]
    activeDocuments: Array<{
      filePath: string
      filename: string
      folderId: number | null
      clientId: number | null
      clientName: string | null
      duration: number
    }>
    activeWebsites: Array<{
      domain: string
      clientId: number
      clientName: string
      boardName: string
      duration: number
    }>
  } {
    const now = Date.now()
    
    return {
      folderClientMapping: this.folderClientMapping,
      pendingEntries: this.pendingEntries,
      activeDocuments: Array.from(this.activeDocuments.values()).map(doc => ({
        filePath: doc.filePath,
        filename: doc.filename,
        folderId: doc.folderId,
        clientId: doc.clientId,
        clientName: doc.clientName,
        duration: doc.accumulatedSeconds + Math.round((now - doc.startTime) / 1000),
      })),
      activeWebsites: Array.from(this.activeWebsites.values()).map(site => ({
        domain: site.displayName || site.domain,
        clientId: site.clientId,
        clientName: site.clientName,
        boardName: site.boardName,
        duration: site.accumulatedSeconds + Math.round((now - site.startTime) / 1000),
      })),
    }
  }
}

