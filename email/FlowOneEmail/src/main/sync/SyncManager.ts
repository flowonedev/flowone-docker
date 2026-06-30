import { EventEmitter } from 'events'
import { BrowserWindow, ipcMain } from 'electron'
import { LocalDatabase } from '../database/Database'
import { configStore } from '../config'
import { getAuthToken } from '../secureStorage'
import { getNetworkMonitor, shutdownNetworkMonitor, NetworkMonitor } from './NetworkMonitor'
import { getWebSocketClient, shutdownWebSocketClient, WebSocketClient } from './WebSocketClient'
import { BaseSyncEngine, SyncEvent } from './BaseSyncEngine'
import { getDesktopTaskHandler, shutdownDesktopTaskHandler } from '../services/DesktopTaskHandler'

// Import sync engines
import { EmailSyncEngine } from './engines/EmailSyncEngine'
import { CalendarSyncEngine } from './engines/CalendarSyncEngine'
import { BoardsSyncEngine } from './engines/BoardsSyncEngine'
import { ColleagueSyncEngine } from './engines/ColleagueSyncEngine'
import { ChatSyncEngine } from './engines/ChatSyncEngine'
import { MailingListSyncEngine } from './engines/MailingListSyncEngine'
import { CampaignSyncEngine } from './engines/CampaignSyncEngine'
import { CrmSyncEngine } from './engines/CrmSyncEngine'
import { MoodBoardSyncEngine } from './engines/MoodBoardSyncEngine'
import { PortalSyncEngine } from './engines/PortalSyncEngine'
import { ClientSyncEngine } from './engines/ClientSyncEngine'
import { TodoSyncEngine } from './engines/TodoSyncEngine'
import { DeviceSyncEngine } from './engines/DeviceSyncEngine'
import { TemplateSyncEngine } from './engines/TemplateSyncEngine'

/**
 * Sync Manager
 * 
 * Orchestrates all sync engines and manages the offline queue.
 * Handles WebSocket events and routes them to appropriate engines.
 */
export class SyncManager extends EventEmitter {
  private db: LocalDatabase
  private networkMonitor: NetworkMonitor
  private wsClient: WebSocketClient
  private engines: Map<string, BaseSyncEngine> = new Map()
  private mainWindow: BrowserWindow | null = null
  private _isInitialized = false
  private _isSyncing = false
  private _periodicSyncTimer: NodeJS.Timeout | null = null

  /**
   * Check if the sync manager has been initialized
   */
  get isInitialized(): boolean {
    return this._isInitialized
  }

  constructor() {
    super()
    this.db = LocalDatabase.getInstance()
    this.networkMonitor = getNetworkMonitor()
    this.wsClient = getWebSocketClient()
  }

  /**
   * Initialize the sync manager
   */
  async initialize(mainWindow: BrowserWindow): Promise<void> {
    if (this._isInitialized) return
    
    console.log('[SyncManager] Initializing...')
    this.mainWindow = mainWindow
    
    // Setup network monitoring
    this.setupNetworkMonitoring()
    
    // Setup WebSocket event handling
    this.setupWebSocketHandling()
    
    // Register IPC handlers
    this.registerIpcHandlers()
    
    // Initialize sync engines
    await this.initializeEngines()
    
    // Connect WebSocket if we have a token
    const token = getAuthToken()
    if (token) {
      this.wsClient.connect(token)
    }
    
    // Set initial online status for all engines based on current network state
    // (The NetworkMonitor may have already detected 'online' before listeners were set up)
    if (this.networkMonitor.isOnline) {
      console.log('[SyncManager] Network already online, setting engines online')
      this.updateEnginesOnlineStatus(true)
    }

    // Send initial online status to renderer (navigator.onLine is unreliable in Electron)
    this.notifyRenderer('online-status', this.networkMonitor.isOnline)
    
    // Start desktop task handler (polling fallback for printer/hardware tasks)
    const taskHandler = getDesktopTaskHandler()
    taskHandler.startPolling(10000)

    this.startPeriodicSync()

    this._isInitialized = true
    console.log('[SyncManager] Initialized')

    this.notifyRenderer('sync-status', {
      initialized: true,
      isOnline: this.networkMonitor.isOnline,
      wsConnected: this.wsClient.isConnected,
      pendingCount: this.db.getPendingCount()
    })
  }

  /**
   * Start a single periodic sync timer that runs fullSync() with staggered delays.
   * Individual engine timers are disabled to prevent rate-limit floods.
   */
  private startPeriodicSync(): void {
    const interval = (configStore.get('syncInterval') || 300) * 1000
    console.log(`[SyncManager] Periodic sync every ${interval / 1000}s`)
    this._periodicSyncTimer = setInterval(() => {
      if (this.networkMonitor.isOnline && !this._isSyncing) {
        this.fullSync().catch(err => {
          console.error('[SyncManager] Periodic sync failed:', err.message)
        })
      }
    }, interval)
  }

  private stopPeriodicSync(): void {
    if (this._periodicSyncTimer) {
      clearInterval(this._periodicSyncTimer)
      this._periodicSyncTimer = null
    }
  }

  /**
   * Setup network monitoring events
   */
  private setupNetworkMonitoring(): void {
    this.networkMonitor.on('online', () => {
      console.log('[SyncManager] Network online')
      this.updateEnginesOnlineStatus(true)
      this.notifyRenderer('online-status', true)
    })
    
    this.networkMonitor.on('offline', () => {
      console.log('[SyncManager] Network offline')
      this.updateEnginesOnlineStatus(false)
      this.notifyRenderer('online-status', false)
    })
    
    this.networkMonitor.on('came-online', async () => {
      console.log('[SyncManager] Came online - starting sync')
      await this.fullSync()
    })
  }

  /**
   * Setup WebSocket event handling
   */
  private setupWebSocketHandling(): void {
    // Connection events
    this.wsClient.on('connected', () => {
      console.log('[SyncManager] WebSocket connected')
      this.notifyRenderer('sync-status', { 
        isSyncing: false, 
        wsConnected: true,
        pendingCount: this.db.getPendingCount()
      })
    })
    
    this.wsClient.on('disconnected', () => {
      console.log('[SyncManager] WebSocket disconnected')
      this.notifyRenderer('sync-status', { 
        isSyncing: false, 
        wsConnected: false,
        pendingCount: this.db.getPendingCount()
      })
    })
    
    this.wsClient.on('auth-failed', () => {
      console.log('[SyncManager] Auth failed')
      this.notifyRenderer('auth-failed', null)
    })
    
    // Handle all incoming events
    this.wsClient.on('event', async (event: SyncEvent) => {
      await this.handleSyncEvent(event)
    })
  }

  /**
   * Register IPC handlers for renderer communication
   */
  private registerIpcHandlers(): void {
    // Manual sync trigger OR WebSocket message forwarding
    ipcMain.on('sync-request', async (_event, data?: any) => {
      // If data is a structured WebSocket message (has a 'type' property), forward it to the server
      if (data && typeof data === 'object' && data.type) {
        const sent = this.wsClient.send(data)
        if (!sent) {
          console.warn(`[SyncManager] Failed to forward WS message: ${data.type} (not connected)`)
        }
        return
      }
      
      // Otherwise treat as a sync trigger (string entity type)
      const entityType = data as string | undefined
      if (entityType === 'all' || !entityType) {
        await this.fullSync()
      } else if (entityType === 'process-queue') {
        await this.processOfflineQueue()
      } else {
        const engine = this.engines.get(entityType)
        if (engine) {
          await engine.sync()
        }
      }
    })
    
  }

  /**
   * Get current sync/connection status (used by IPC handler in main/index.ts)
   */
  getStatus() {
    return {
      isOnline: this.networkMonitor.isOnline,
      isVerifiedOnline: this.networkMonitor.isVerifiedOnline,
      wsConnected: this.wsClient.isConnected,
      pendingCount: this.db.getPendingCount(),
      lastEventVersion: this.wsClient.currentVersion,
    }
  }

  /**
   * Initialize all sync engines
   */
  private async initializeEngines(): Promise<void> {
    // Create sync engines
    const emailEngine = new EmailSyncEngine(this.db)
    this.engines.set('email', emailEngine)
    
    // Calendar sync engine
    const calendarEngine = new CalendarSyncEngine(this.db)
    this.engines.set('calendar', calendarEngine)
    
    // Boards sync engine
    const boardsEngine = new BoardsSyncEngine(this.db)
    this.engines.set('board', boardsEngine)
    
    // Colleague sync engine
    const colleagueEngine = new ColleagueSyncEngine(this.db)
    this.engines.set('colleague', colleagueEngine)
    
    // Chat sync engine
    const chatEngine = new ChatSyncEngine(this.db)
    this.engines.set('chat', chatEngine)
    
    // Mailing list sync engine
    const mailingListEngine = new MailingListSyncEngine(this.db)
    this.engines.set('mailing_list', mailingListEngine)
    
    // Campaign sync engine
    const campaignEngine = new CampaignSyncEngine(this.db)
    this.engines.set('campaign', campaignEngine)
    
    // CRM sync engine
    const crmEngine = new CrmSyncEngine(this.db)
    this.engines.set('crm', crmEngine)
    
    // MoodBoard sync engine
    const moodBoardEngine = new MoodBoardSyncEngine(this.db)
    this.engines.set('mood_board', moodBoardEngine)
    
    // Portal sync engine
    const portalEngine = new PortalSyncEngine(this.db)
    this.engines.set('portal', portalEngine)
    
    // Client sync engine
    const clientEngine = new ClientSyncEngine(this.db)
    this.engines.set('client', clientEngine)
    
    // Todo sync engine
    const todoEngine = new TodoSyncEngine(this.db)
    this.engines.set('todo', todoEngine)
    
    // Device sync engine
    const deviceEngine = new DeviceSyncEngine(this.db)
    this.engines.set('device', deviceEngine)
    
    // Template sync engine
    const templateEngine = new TemplateSyncEngine(this.db)
    this.engines.set('template', templateEngine)
    
    // Initialize each engine
    for (const [name, engine] of this.engines) {
      await engine.initialize()
      
      // Forward engine events to renderer
      engine.on('sync-start', () => {
        this.notifyRenderer('sync-status', { isSyncing: true, engine: name })
      })
      
      engine.on('sync-complete', () => {
        this.notifyRenderer('sync-complete', { engine: name })
        this.notifyRenderer('sync-status', { 
          isSyncing: false, 
          pendingCount: this.db.getPendingCount()
        })
      })
      
      engine.on('sync-error', (error: string) => {
        this.notifyRenderer('sync-error', { engine: name, error })
      })
    }
    
    console.log(`[SyncManager] Initialized ${this.engines.size} sync engines`)
  }

  /**
   * Update online status for all engines
   */
  private updateEnginesOnlineStatus(online: boolean): void {
    for (const engine of this.engines.values()) {
      engine.setOnline(online)
    }
  }

  /**
   * Handle incoming sync event from WebSocket
   */
  private async handleSyncEvent(event: SyncEvent): Promise<void> {
    console.log(`[SyncManager] Received event: ${event.type}`)

    // Handle desktop task events (printer, etc.)
    if (event.type === 'DESKTOP_TASK') {
      const handler = getDesktopTaskHandler()
      handler.handleEvent(event as any).catch(err => {
        console.error('[SyncManager] Desktop task handler error:', err.message)
      })
      return
    }
    
    // Route event to appropriate engine based on type
    const entityType = this.getEntityTypeFromEvent(event.type)
    
    if (entityType) {
      const engine = this.engines.get(entityType)
      if (engine) {
        await engine.handleEvent(event)
      }
    }
    
    // Also notify renderer for UI updates
    this.notifyRenderer(event.type.toLowerCase().replace(/_/g, '-'), event.payload)
  }

  /**
   * Map event type to entity type
   */
  private getEntityTypeFromEvent(eventType: string): string | null {
    // Email events
    if (eventType.startsWith('MESSAGE_') || 
        eventType.startsWith('FLAGS_') || 
        eventType.startsWith('FOLDER_') ||
        eventType.startsWith('CONVERSATION_')) {
      return 'email'
    }
    
    // Calendar events
    if (eventType.startsWith('CALENDAR_')) return 'calendar'
    
    // Board events
    if (eventType.startsWith('BOARD_') || 
        eventType.startsWith('LIST_') || 
        eventType.startsWith('CARD_')) {
      return 'board'
    }
    
    // Chat events
    if (eventType.startsWith('CHAT_')) return 'chat'
    
    // Colleague events
    if (eventType.startsWith('COLLEAGUE_')) return 'colleague'
    
    // Mailing list events
    if (eventType.startsWith('MAILING_LIST_')) return 'mailing_list'
    
    // Campaign events
    if (eventType.startsWith('CAMPAIGN_')) return 'campaign'
    
    // CRM events
    if (eventType.startsWith('CRM_')) return 'crm'
    
    // MoodBoard events
    if (eventType.startsWith('MOOD_BOARD_')) {
      return 'mood_board'
    }
    
    // Portal events
    if (eventType.startsWith('PORTAL_')) return 'portal'
    
    // Client events
    if (eventType.startsWith('CLIENT_') || 
        eventType.startsWith('TIME_')) {
      return 'client'
    }
    
    // Todo events
    if (eventType.startsWith('TODO_')) return 'todo'
    
    // Device events
    if (eventType.startsWith('DEVICE_')) return 'device'
    
    // Template events
    if (eventType.startsWith('TEMPLATE_')) return 'template'
    
    // Drive events
    if (eventType.startsWith('DRIVE_')) return 'drive'
    
    return null
  }

  /**
   * Full sync - sync all engines
   */
  async fullSync(): Promise<void> {
    if (!this.networkMonitor.isOnline) {
      console.log('[SyncManager] Cannot sync - offline')
      return
    }

    if (this._isSyncing) {
      console.log('[SyncManager] Sync already in progress, skipping')
      return
    }
    
    this._isSyncing = true
    console.log('[SyncManager] Starting full sync')
    this.notifyRenderer('sync-status', { isSyncing: true, type: 'full' })
    
    try {
      // Process offline queue first
      await this.processOfflineQueue()
      
      const delay = (ms: number) => new Promise(resolve => setTimeout(resolve, ms))
      
      // Sync each engine with pauses to avoid hitting API rate limits
      for (const [name, engine] of this.engines) {
        try {
          await engine.sync()
        } catch (error: any) {
          console.error(`[SyncManager] Failed to sync ${name}:`, error.message)
        }
        await delay(2000)
      }
      
      console.log('[SyncManager] Full sync complete')
      this.notifyRenderer('sync-complete', { type: 'full' })
    } catch (error: any) {
      console.error('[SyncManager] Full sync failed:', error.message)
      this.notifyRenderer('sync-error', { error: error.message })
    } finally {
      this._isSyncing = false
      this.notifyRenderer('sync-status', { 
        isSyncing: false, 
        pendingCount: this.db.getPendingCount()
      })
    }
  }

  /**
   * Process the offline queue
   */
  async processOfflineQueue(): Promise<void> {
    const pending = this.db.getPendingChanges()
    
    if (pending.length === 0) {
      console.log('[SyncManager] No pending changes')
      return
    }
    
    console.log(`[SyncManager] Processing ${pending.length} pending changes`)
    
    // Group by entity type
    const byType = new Map<string, typeof pending>()
    for (const item of pending) {
      if (!byType.has(item.entity_type)) {
        byType.set(item.entity_type, [])
      }
      byType.get(item.entity_type)!.push(item)
    }
    
    // Process each type
    for (const [entityType, items] of byType) {
      const engine = this.engines.get(entityType)
      if (engine) {
        await engine.processQueue()
      } else {
        console.warn(`[SyncManager] No engine for entity type: ${entityType}`)
      }
    }
    
    this.notifyRenderer('sync-status', { 
      pendingCount: this.db.getPendingCount()
    })
  }

  /**
   * Send notification to renderer
   */
  private notifyRenderer(channel: string, data: any): void {
    if (this.mainWindow && !this.mainWindow.isDestroyed()) {
      this.mainWindow.webContents.send(channel, data)
    }
  }

  /**
   * Add a sync engine
   */
  addEngine(name: string, engine: BaseSyncEngine): void {
    this.engines.set(name, engine)
    engine.setOnline(this.networkMonitor.isOnline)
  }

  /**
   * Get a sync engine by name
   */
  getEngine(name: string): BaseSyncEngine | undefined {
    return this.engines.get(name)
  }

  /**
   * Get the email sync engine (typed)
   */
  getEmailEngine(): EmailSyncEngine | undefined {
    return this.engines.get('email') as EmailSyncEngine | undefined
  }

  /**
   * Get the calendar sync engine (typed)
   */
  getCalendarEngine(): CalendarSyncEngine | undefined {
    return this.engines.get('calendar') as CalendarSyncEngine | undefined
  }

  /**
   * Get the boards sync engine (typed)
   */
  getBoardsEngine(): BoardsSyncEngine | undefined {
    return this.engines.get('board') as BoardsSyncEngine | undefined
  }

  /**
   * Get the colleague sync engine (typed)
   */
  getColleagueEngine(): ColleagueSyncEngine | undefined {
    return this.engines.get('colleague') as ColleagueSyncEngine | undefined
  }

  /**
   * Get the chat sync engine (typed)
   */
  getChatEngine(): ChatSyncEngine | undefined {
    return this.engines.get('chat') as ChatSyncEngine | undefined
  }

  /**
   * Get the mailing list sync engine (typed)
   */
  getMailingListEngine(): MailingListSyncEngine | undefined {
    return this.engines.get('mailing_list') as MailingListSyncEngine | undefined
  }

  /**
   * Get the campaign sync engine (typed)
   */
  getCampaignEngine(): CampaignSyncEngine | undefined {
    return this.engines.get('campaign') as CampaignSyncEngine | undefined
  }

  /**
   * Get the CRM sync engine (typed)
   */
  getCrmEngine(): CrmSyncEngine | undefined {
    return this.engines.get('crm') as CrmSyncEngine | undefined
  }

  /**
   * Get the MoodBoard sync engine (typed)
   */
  getMoodBoardEngine(): MoodBoardSyncEngine | undefined {
    return this.engines.get('mood_board') as MoodBoardSyncEngine | undefined
  }

  /**
   * Get the Portal sync engine (typed)
   */
  getPortalEngine(): PortalSyncEngine | undefined {
    return this.engines.get('portal') as PortalSyncEngine | undefined
  }

  /**
   * Get the Client sync engine (typed)
   */
  getClientEngine(): ClientSyncEngine | undefined {
    return this.engines.get('client') as ClientSyncEngine | undefined
  }

  /**
   * Get the Todo sync engine (typed)
   */
  getTodoEngine(): TodoSyncEngine | undefined {
    return this.engines.get('todo') as TodoSyncEngine | undefined
  }

  /**
   * Get the Device sync engine (typed)
   */
  getDeviceEngine(): DeviceSyncEngine | undefined {
    return this.engines.get('device') as DeviceSyncEngine | undefined
  }

  /**
   * Get the Template sync engine (typed)
   */
  getTemplateEngine(): TemplateSyncEngine | undefined {
    return this.engines.get('template') as TemplateSyncEngine | undefined
  }

  /**
   * Get the pending change count
   */
  getPendingCount(): number {
    return this.db.getPendingCount()
  }

  /**
   * Check if online
   */
  get isOnline(): boolean {
    return this.networkMonitor.isOnline
  }

  /**
   * Shutdown
   */
  async shutdown(): Promise<void> {
    console.log('[SyncManager] Shutting down...')
    
    this.stopPeriodicSync()
    
    // Shutdown all engines
    for (const engine of this.engines.values()) {
      engine.shutdown()
    }
    
    // Shutdown desktop task handler
    shutdownDesktopTaskHandler()

    // Shutdown WebSocket and network monitor
    shutdownWebSocketClient()
    shutdownNetworkMonitor()
    
    this.removeAllListeners()
    console.log('[SyncManager] Shutdown complete')
  }
}

// Singleton instance
let syncManager: SyncManager | null = null

export function getSyncManager(): SyncManager {
  if (!syncManager) {
    syncManager = new SyncManager()
  }
  return syncManager
}

export async function shutdownSyncManager(): Promise<void> {
  if (syncManager) {
    await syncManager.shutdown()
    syncManager = null
  }
}

