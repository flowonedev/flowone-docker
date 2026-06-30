import { contextBridge, ipcRenderer } from 'electron'

/**
 * Preload script - Exposes safe APIs to the renderer process
 * This is the bridge between the main process and Vue frontend
 */

// Event listeners storage for cleanup
const eventListeners = new Map<string, Set<Function>>()

const api = {
  // ============================================
  // PLATFORM
  // ============================================
  platform: process.platform,

  // Tell the main process to follow the renderer's light/dark theme.
  setNativeTheme: (mode: string) => ipcRenderer.send('set-native-theme', mode),

  // ============================================
  // WINDOW CONTROLS
  // ============================================
  window: {
    minimize: () => ipcRenderer.send('window-minimize'),
    maximize: () => ipcRenderer.send('window-maximize'),
    close: () => ipcRenderer.send('window-close'),
    isMaximized: () => ipcRenderer.invoke('window-is-maximized'),
  },

  // ============================================
  // CONFIGURATION
  // ============================================
  config: {
    get: (key: string) => ipcRenderer.invoke('config-get', key),
    set: (key: string, value: any) => ipcRenderer.invoke('config-set', key, value),
    getAll: () => ipcRenderer.invoke('config-get-all'),
  },

  // ============================================
  // AUTHENTICATION
  // ============================================
  auth: {
    setToken: (token: string, email: string, name: string) =>
      ipcRenderer.invoke('auth-set-token', token, email, name),
    clearToken: () => ipcRenderer.invoke('auth-clear'),
    getToken: () => ipcRenderer.invoke('auth-get-token'),
    isLoggedIn: () => ipcRenderer.invoke('auth-is-logged-in'),
  },

  // ============================================
  // SSO + OAUTH
  // ============================================
  sso: {
    onAuthenticated: (cb: (data: any) => void) => {
      const handler = (_event: any, data: any) => cb(data)
      ipcRenderer.on('sso-authenticated', handler)
      return () => ipcRenderer.removeListener('sso-authenticated', handler)
    },
    exchangeCode: (code: string) => ipcRenderer.invoke('sso-exchange-code', code),
    logout: () => ipcRenderer.invoke('sso-logout'),
  },
  oauth: {
    start: (provider: string) => ipcRenderer.invoke('oauth-start', provider),
  },

  // ============================================
  // DATABASE OPERATIONS
  // ============================================
  db: {
    // Email (legacy - by folder ID)
    getEmails: (folderId: number, limit?: number, offset?: number) =>
      ipcRenderer.invoke('db-get-emails', folderId, limit, offset),

    // Email (new - for offline support)
    getFolders: () => ipcRenderer.invoke('db-get-folders'),
    getFolderByPath: (folderPath: string) => ipcRenderer.invoke('db-get-folder-by-path', folderPath),
    getEmailsByFolder: (folderPath: string, limit?: number, offset?: number) =>
      ipcRenderer.invoke('db-get-emails-by-folder', folderPath, limit || 50, offset || 0),
    getEmail: (folderPath: string, uid: number) =>
      ipcRenderer.invoke('db-get-email', folderPath, uid),
    fetchEmailBody: (emailId: number) => ipcRenderer.invoke('db-fetch-email-body', emailId),
    syncEmails: () => ipcRenderer.invoke('db-sync-emails'),
    syncCalendars: () => ipcRenderer.invoke('db-sync-calendars'),
    syncBoards: () => ipcRenderer.invoke('db-sync-boards'),
    syncAllForOffline: () => ipcRenderer.invoke('db-sync-all-for-offline'),
    syncEmailBodies: (days?: number, maxCount?: number) =>
      ipcRenderer.invoke('db-sync-email-bodies', days, maxCount),
    getEmailsNeedingBodies: (days?: number) =>
      ipcRenderer.invoke('db-get-emails-needing-bodies', days),
    getSyncStatus: () => ipcRenderer.invoke('db-get-sync-status'),
    hasOfflineData: () => ipcRenderer.invoke('db-has-offline-data'),

    // Cache data for offline use (called when API returns data)
    cacheEmails: (folderPath: string, emails: any[]) =>
      ipcRenderer.invoke('db-cache-emails', folderPath, emails),
    cacheEmailBody: (folderPath: string, uid: number, bodyHtml: string, bodyText: string) =>
      ipcRenderer.invoke('db-cache-email-body', folderPath, uid, bodyHtml, bodyText),
    cacheEvents: (events: any[]) => ipcRenderer.invoke('db-cache-events', events),
    cacheBoards: (boards: any[]) => ipcRenderer.invoke('db-cache-boards', boards),
    cacheBoardFull: (boardData: any) => ipcRenderer.invoke('db-cache-board-full', boardData),
    cacheClients: (clients: any[]) => ipcRenderer.invoke('db-cache-clients', clients),
    cacheTimeEntries: (entries: any[]) => ipcRenderer.invoke('db-cache-time-entries', entries),
    cacheTodos: (todos: any[]) => ipcRenderer.invoke('db-cache-todos', todos),
    
    // Offline data retrieval
    getOfflineBoards: () => ipcRenderer.invoke('db-get-offline-boards'),
    getOfflineClients: () => ipcRenderer.invoke('db-get-offline-clients'),
    getOfflineTodos: () => ipcRenderer.invoke('db-get-offline-todos'),
    getOfflineCalendars: () => ipcRenderer.invoke('db-get-offline-calendars'),
    getOfflineEvents: () => ipcRenderer.invoke('db-get-offline-events'),

    // Calendar
    getCalendars: () => ipcRenderer.invoke('db-get-calendars'),
    getEvents: (startDate: string, endDate: string, calendarId?: number) =>
      ipcRenderer.invoke('db-get-events', startDate, endDate, calendarId),

    // Boards
    getBoards: () => ipcRenderer.invoke('db-get-boards'),
    getBoard: (boardId: number) => ipcRenderer.invoke('db-get-board', boardId),

    // Clients
    getClients: () => ipcRenderer.invoke('db-get-clients'),

    // Sync queue
    getPendingCount: () => ipcRenderer.invoke('db-get-pending-count'),
    queueChange: (entityType: string, entityId: number | null, action: string, payload: object) =>
      ipcRenderer.invoke('db-queue-change', entityType, entityId, action, payload),

    // Settings
    getSetting: (key: string) => ipcRenderer.invoke('db-get-setting', key),
    setSetting: (key: string, value: string) => ipcRenderer.invoke('db-set-setting', key, value),
  },

  // ============================================
  // NOTIFICATIONS
  // ============================================
  notification: {
    show: (title: string, body: string) =>
      ipcRenderer.invoke('show-notification', title, body),
  },

  // ============================================
  // TRAY ICON (unread pulse)
  // ============================================
  tray: {
    setUnread: (hasUnread: boolean) =>
      ipcRenderer.invoke('set-tray-unread', hasUnread),
  },

  // ============================================
  // APP LOCK (Biometric / PIN)
  // ============================================
  lock: {
    isBiometricAvailable: () => ipcRenderer.invoke('lock-biometric-available'),
    authenticateBiometric: () => ipcRenderer.invoke('lock-biometric-auth'),
    hasPin: () => ipcRenderer.invoke('lock-has-pin'),
    setPin: (pin: string) => ipcRenderer.invoke('lock-set-pin', pin),
    verifyPin: (pin: string) => ipcRenderer.invoke('lock-verify-pin', pin),
    removePin: () => ipcRenderer.invoke('lock-remove-pin'),
    getSettings: () => ipcRenderer.invoke('lock-get-settings'),
    setSettings: (settings: { lockEnabled?: boolean; lockTimeout?: number; lockOnMinimize?: boolean }) =>
      ipcRenderer.invoke('lock-set-settings', settings),
    lockNow: () => ipcRenderer.invoke('lock-now'),
    isLocked: () => ipcRenderer.invoke('lock-is-locked'),
  },

  // ============================================
  // SYNC STATUS (from SyncManager)
  // ============================================
  sync: {
    getStatus: () => ipcRenderer.invoke('sync-get-status'),
  },

  // ============================================
  // UTILITIES
  // ============================================
  openExternal: (url: string) => ipcRenderer.invoke('open-external', url),
  getVersion: () => ipcRenderer.invoke('get-app-version'),
  getAppPath: (name: string) => ipcRenderer.invoke('get-app-path', name),

  // Proxy port (needed by renderer API service to build base URL)
  getProxyPort: () => ipcRenderer.invoke('get-proxy-port'),

  // Badge count (Windows taskbar overlay)
  setBadgeCount: (count: number) => ipcRenderer.invoke('set-badge-count', count),

  // ============================================
  // EVENT SYSTEM
  // ============================================
  on: (channel: string, callback: (...args: any[]) => void) => {
    const validChannels = [
      'trigger-sync',
      'navigate',
      'online-status',
      'sync-status',
      'sync-progress',
      'sync-complete',
      'sync-error',
      'new-email',
      'calendar-update',
      'board-update',
      'auth-failed',
      'sso-authenticated',
      'app-locked',
      'app-unlocked',
      'forced-logout',
      'remote-wipe-executed',
      'update-available',
      'update-downloaded',
      'maximize-padding',
      'message-new',
      'message-deleted',
      'message-moved',
      'flags-changed',
      'folder-counts',
      // Email real-time sync events (from WebSocket via main process)
      'conversation-updated',
      'folder-changed',
      'settings-changed',
      'pin-changed',
      'labels-changed',
      // Board/checklist real-time sync events
      'checklist-updated',
      'card-updated',
      'list-updated',
      'board-updated',
      'todo-updated',
      'calendar-updated',
      // Colleague events
      'colleague-updated',
      'colleague-group-updated',
      // Campaign events
      'campaign-progress',
      'campaign-update',
      // Chat events
      'chat-message-new',
      'chat-message-edited',
      'chat-message-deleted',
      'chat-message-pinned',
      'chat-reaction-added',
      'chat-reaction-removed',
      'chat-typing-start',
      'chat-typing-stop',
      'chat-read-receipt',
      'chat-conversation-created',
      'chat-settings-updated',
      // View Together
      'chat-view-session-start',
      'chat-view-session-end',
      'chat-view-sync',
      // Presence events
      'presence-online',
      'presence-offline',
      'presence-status-changed',
      'presence-bulk-update',
      // Call events
      'call-initiate',
      'call-ringing',
      'call-answer',
      'call-reject',
      'call-hangup',
      'call-ice-candidate',
      'call-media-state',
      'call-participant-joined',
      'call-participant-left',
      'call-screen-share-start',
      'call-screen-share-stop',
      'call-missed',
      'call-dismissed',
      'call-sdp-offer',
      'call-sdp-answer',
    ]

    if (!validChannels.includes(channel)) {
      console.warn(`[Preload] Invalid channel: ${channel}`)
      return () => { }
    }

    const subscription = (_event: any, ...args: any[]) => callback(...args)
    ipcRenderer.on(channel, subscription)

    // Track listener for cleanup
    if (!eventListeners.has(channel)) {
      eventListeners.set(channel, new Set())
    }
    eventListeners.get(channel)!.add(subscription)

    // Return unsubscribe function
    return () => {
      ipcRenderer.removeListener(channel, subscription)
      eventListeners.get(channel)?.delete(subscription)
    }
  },

  off: (channel: string, callback?: Function) => {
    if (callback) {
      ipcRenderer.removeListener(channel, callback as any)
      eventListeners.get(channel)?.delete(callback)
    } else {
      ipcRenderer.removeAllListeners(channel)
      eventListeners.delete(channel)
    }
  },

  // Send event to main process
  send: (channel: string, ...args: any[]) => {
    const validChannels = [
      'sync-request',
      'install-update',
      'log',
    ]

    if (validChannels.includes(channel)) {
      ipcRenderer.send(channel, ...args)
    }
  },

  // ============================================
  // NETWORK STATUS (from main process)
  // ============================================
  network: {
    // These will be called by NetworkMonitor in renderer
    onStatusChange: (callback: (isOnline: boolean) => void) => {
      return api.on('online-status', callback)
    },
  },

  // ============================================
  // DEBUG MODE - Database inspection
  // ============================================
  debug: {
    getDbStats: () => ipcRenderer.invoke('debug-get-db-stats'),
    getTableData: (tableName: string, limit?: number) =>
      ipcRenderer.invoke('debug-get-table-data', tableName, limit),
    runQuery: (sql: string) => ipcRenderer.invoke('debug-run-query', sql),
    openDbFolder: () => ipcRenderer.invoke('debug-open-db-folder'),
  },
}

// Expose API to renderer
contextBridge.exposeInMainWorld('api', api)

// Type declaration for TypeScript
export type ElectronAPI = typeof api

