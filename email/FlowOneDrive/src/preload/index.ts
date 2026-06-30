import { contextBridge, ipcRenderer } from 'electron'

// Expose protected methods to renderer via window.api
contextBridge.exposeInMainWorld('api', {
  // Platform
  platform: process.platform,

  // Config
  getConfig: () => ipcRenderer.invoke('get-config'),
  setConfig: (key: string, value: any) => ipcRenderer.invoke('set-config', key, value),

  // Sync
  getSyncStatus: () => ipcRenderer.invoke('get-sync-status'),
  getFiles: (folderId?: number) => ipcRenderer.invoke('get-files', folderId),
  getAllFolders: () => ipcRenderer.invoke('get-all-folders'),
  getTrash: () => ipcRenderer.invoke('get-trash'),
  getQuota: () => ipcRenderer.invoke('get-quota'),
  triggerSync: () => ipcRenderer.invoke('trigger-sync'),
  reconcileLocalFiles: () => ipcRenderer.invoke('reconcile-local-files'),
  pauseSync: () => ipcRenderer.invoke('pause-sync'),
  resumeSync: () => ipcRenderer.invoke('resume-sync'),

  // Activity & Collaborator changes
  getActivity: (limit?: number) => ipcRenderer.invoke('get-activity', limit),
  checkCollaboratorChanges: () => ipcRenderer.invoke('check-collaborator-changes'),

  // Time tracking
  getTimeTrackingStats: () => ipcRenderer.invoke('get-time-tracking-stats'),
  stopTrackingDocument: (filename: string) => ipcRenderer.invoke('stop-tracking-document', filename),
  getActiveTrackedDocuments: () => ipcRenderer.invoke('get-active-tracked-documents'),
  getActiveTrackedWebsites: () => ipcRenderer.invoke('get-active-tracked-websites'),
  
  // Debug APIs for time tracking
  getTimeTrackingDebug: () => ipcRenderer.invoke('get-time-tracking-debug'),
  getSyncedFoldersDebug: () => ipcRenderer.invoke('get-synced-folders-debug'),
  refreshFolderMapping: () => ipcRenderer.invoke('refresh-folder-mapping'),
  getUrlMappingsDebug: () => ipcRenderer.invoke('get-url-mappings-debug'),
  refreshUrlMappings: () => ipcRenderer.invoke('refresh-url-mappings'),

  // Editing sessions (watch folder tracking + sync folder editing)
  getEditingSessions: () => ipcRenderer.invoke('get-editing-sessions'),

  // Editing status (who is editing files)
  getEditingStatus: (folderId?: number | null) => ipcRenderer.invoke('get-editing-status', folderId),
  getOtherEditors: () => ipcRenderer.invoke('get-other-editors'),
  getSelfEditing: () => ipcRenderer.invoke('get-self-editing'),
  onSelfEditingUpdate: (callback: (editing: Array<{ filename: string; folderId: number | null }>) => void) => {
    const handler = (_: any, editing: Array<{ filename: string; folderId: number | null }>) => callback(editing)
    ipcRenderer.on('self-editing-update', handler)
    return () => ipcRenderer.removeListener('self-editing-update', handler)
  },

  // NAS Direct Access / Access Mode
  getAccessMode: () => ipcRenderer.invoke('get-access-mode'),
  getAccessModeStatus: () => ipcRenderer.invoke('get-access-mode-status'),
  forceAccessModeCheck: () => ipcRenderer.invoke('force-access-mode-check'),
  
  // Debug logging from main process
  onDebugLog: (callback: (message: string) => void) => {
    const handler = (_: any, message: string) => callback(message)
    ipcRenderer.on('debug-log', handler)
    return () => ipcRenderer.removeListener('debug-log', handler)
  },
  getNasConfig: () => ipcRenderer.invoke('get-nas-config'),
  getConnectionConfig: () => ipcRenderer.invoke('get-connection-config'),
  
  // NAS Credentials
  getNasCredentials: () => ipcRenderer.invoke('get-nas-credentials'),
  saveNasCredentials: (username: string, password: string, useCredentials: boolean) => 
    ipcRenderer.invoke('save-nas-credentials', username, password, useCredentials),
  clearNasCredentials: () => ipcRenderer.invoke('clear-nas-credentials'),
  testNasCredentials: (username: string, password: string) => 
    ipcRenderer.invoke('test-nas-credentials', username, password),
  
  onAccessModeChanged: (callback: (data: { mode: string; reason: string }) => void) => {
    const handler = (_: any, data: { mode: string; reason: string }) => callback(data)
    ipcRenderer.on('access-mode-changed', handler)
    return () => ipcRenderer.removeListener('access-mode-changed', handler)
  },
  onAccessModeReady: (callback: (status: any) => void) => {
    const handler = (_: any, status: any) => callback(status)
    ipcRenderer.on('access-mode-ready', handler)
    return () => ipcRenderer.removeListener('access-mode-ready', handler)
  },

  // File operations
  openSyncFolder: () => ipcRenderer.invoke('open-sync-folder'),
  selectSyncFolder: () => ipcRenderer.invoke('select-sync-folder'),
  openExternalUrl: (url: string) => ipcRenderer.invoke('open-external-url', url),

  // Auth
  login: (apiUrl: string, email: string, password: string) =>
    ipcRenderer.invoke('login', apiUrl, email, password),
  verify2FA: (apiUrl: string, email: string, code: string, tempToken: string, trustDevice: boolean = false) =>
    ipcRenderer.invoke('verify-2fa', apiUrl, email, code, tempToken, trustDevice),
  logout: () => ipcRenderer.invoke('logout'),

  // SSO + OAuth
  sso: {
    onAuthenticated: (cb: (data: any) => void) => {
      const handler = (_event: any, data: any) => cb(data)
      ipcRenderer.on('sso-authenticated', handler)
      return () => ipcRenderer.removeListener('sso-authenticated', handler)
    },
    logout: () => ipcRenderer.invoke('sso-logout'),
    // "Scan to sign in" device flow
    startDeviceLogin: (email?: string) => ipcRenderer.invoke('sso-device-start', email),
    cancelDeviceLogin: () => ipcRenderer.invoke('sso-device-cancel'),
    onDeviceStatus: (cb: (data: { status: string; error?: string }) => void) => {
      const handler = (_event: any, data: { status: string; error?: string }) => cb(data)
      ipcRenderer.on('sso-device-status', handler)
      return () => ipcRenderer.removeListener('sso-device-status', handler)
    },
  },
  oauth: {
    start: (provider: string) => ipcRenderer.invoke('oauth-start', provider),
  },

  // App Lock (Biometric / PIN)
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

  // Printer
  printer: {
    getPrinters: () => ipcRenderer.invoke('get-printers'),
    scanNetwork: () => ipcRenderer.invoke('scan-network-printers'),
    getAllPrinters: () => ipcRenderer.invoke('get-all-printers'),
    onScanProgress: (callback: (data: { scanned: number; total: number }) => void) => {
      const handler = (_: any, data: { scanned: number; total: number }) => callback(data)
      ipcRenderer.on('network-scan-progress', handler)
      return () => ipcRenderer.removeListener('network-scan-progress', handler)
    },
    printFile: (filePath: string, printerName: string, options?: any) =>
      ipcRenderer.invoke('print-file', filePath, printerName, options),
    printHtml: (htmlContent: string, printerName: string, options?: any) =>
      ipcRenderer.invoke('print-html', htmlContent, printerName, options),
    printToPdf: (htmlContent: string) => ipcRenderer.invoke('print-to-pdf', htmlContent),
  },

  // Watch Folders
  getWatchFolders: () => ipcRenderer.invoke('get-watch-folders'),
  refreshWatchFolders: () => ipcRenderer.invoke('refresh-watch-folders'),
  changeWatchFolderPath: (id: number) => ipcRenderer.invoke('change-watch-folder-path', id),
  removeWatchFolder: (id: number) => ipcRenderer.invoke('remove-watch-folder', id),
  openWatchFolderLocally: (id: number) => ipcRenderer.invoke('open-watch-folder-locally', id),

  // Wave C.5 — Perf HUD
  getPerfSnapshot: () => ipcRenderer.invoke('get-perf-snapshot'),
  setLogLevel: (level: string) => ipcRenderer.invoke('set-log-level', level),

  // Window controls
  minimize: () => ipcRenderer.invoke('window-minimize'),
  maximize: () => ipcRenderer.invoke('window-maximize'),
  close: () => ipcRenderer.invoke('window-close'),

  // Tell the main process to follow the renderer's light/dark theme.
  setNativeTheme: (mode: string) => ipcRenderer.send('set-native-theme', mode),

  // Events from main process
  onNavigate: (callback: (route: string) => void) => {
    ipcRenderer.on('navigate', (_event, route) => callback(route))
    return () => ipcRenderer.removeAllListeners('navigate')
  },

  onSyncStatusChange: (callback: (status: any) => void) => {
    ipcRenderer.on('sync-status-change', (_event, status) => callback(status))
    return () => ipcRenderer.removeAllListeners('sync-status-change')
  },

  // Wave C.1 — push activity events to ActivityLog
  onActivityUpdate: (callback: (activity: any) => void) => {
    const handler = (_: any, activity: any) => callback(activity)
    ipcRenderer.on('activity-update', handler)
    return () => ipcRenderer.removeListener('activity-update', handler)
  },

  onEditingUpdate: (callback: (editors: any[]) => void) => {
    ipcRenderer.on('editing-update', (_event, editors) => callback(editors))
    return () => ipcRenderer.removeAllListeners('editing-update')
  },

  onAppReady: (callback: () => void) => {
    ipcRenderer.on('app-ready', () => callback())
    return () => ipcRenderer.removeAllListeners('app-ready')
  },

  onFilesChanged: (callback: (data: { folderId: number | null }) => void) => {
    const handler = (_: any, data: { folderId: number | null }) => callback(data)
    ipcRenderer.on('files-changed', handler)
    return () => ipcRenderer.removeListener('files-changed', handler)
  },

  onAuthFailed: (callback: () => void) => {
    const handler = () => callback()
    ipcRenderer.on('auth-failed', handler)
    return () => ipcRenderer.removeListener('auth-failed', handler)
  },

  onAppLocked: (callback: () => void) => {
    const handler = () => callback()
    ipcRenderer.on('app-locked', handler)
    return () => ipcRenderer.removeListener('app-locked', handler)
  },

  onAppUnlocked: (callback: () => void) => {
    const handler = () => callback()
    ipcRenderer.on('app-unlocked', handler)
    return () => ipcRenderer.removeListener('app-unlocked', handler)
  },

  onForcedLogout: (callback: (data: { reason: string }) => void) => {
    const handler = (_: any, data: { reason: string }) => callback(data)
    ipcRenderer.on('forced-logout', handler)
    return () => ipcRenderer.removeListener('forced-logout', handler)
  },
})

// Type definitions for the exposed API
export interface LoginResult {
  success: boolean
  error?: string
  requires2FA?: boolean
  tempToken?: string
}

export interface EditingStatus {
  filename: string
  folder_id: number | null
  folder_name?: string
  editor_email: string
  started_at: string
  editing_duration: number
}

export interface ElectronAPI {
  getConfig: () => Promise<any>
  setConfig: (key: string, value: any) => Promise<boolean>
  getSyncStatus: () => Promise<any>
  getFiles: (folderId?: number) => Promise<{ files: any[], folders: any[], quota?: { used: number, total: number | null, percentage: number } }>
  getAllFolders: () => Promise<{ folders: any[] }>
  getTrash: () => Promise<{ files: any[], folders: any[] }>
  getQuota: () => Promise<{ used: number, total: number | null, percentage: number } | null>
  triggerSync: () => Promise<void>
  reconcileLocalFiles: () => Promise<{ folders: number; files: number }>
  getActivity: (limit?: number) => Promise<any[]>
  checkCollaboratorChanges: () => Promise<any[]>
  getEditingStatus: (folderId?: number | null) => Promise<EditingStatus[]>
  getOtherEditors: () => Promise<EditingStatus[]>
  getSelfEditing: () => Promise<Array<{ filename: string; folderId: number | null }>>
  onSelfEditingUpdate: (callback: (editing: Array<{ filename: string; folderId: number | null }>) => void) => () => void
  stopTrackingDocument: (filename: string) => Promise<boolean>
  getActiveTrackedDocuments: () => Promise<Array<{ filename: string; folderId: number | null; clientName: string | null; duration: number }>>
  getActiveTrackedWebsites: () => Promise<Array<{ domain: string; clientName: string; boardName: string; duration: number }>>
  getTimeTrackingDebug: () => Promise<{
    folderClientMapping: Record<string, { client_id: number; client_name: string }>
    pendingEntries: Array<{
      id: string
      clientId: number
      activityType: string
      durationSeconds: number
      entityId: string
      entityName: string
      retryCount: number
      createdAt: number
    }>
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
  }>
  getSyncedFoldersDebug: () => Promise<Array<{
    remoteId: number
    name: string
    localPath: string
    syncStatus: string
  }>>
  refreshFolderMapping: () => Promise<Record<string, { client_id: number; client_name: string }>>
  getUrlMappingsDebug: () => Promise<Array<{
    domain: string
    clientName?: string
    boardName?: string
    clientId?: number
    boardId?: number
  }>>
  refreshUrlMappings: () => Promise<Array<{
    domain: string
    clientName?: string
    boardName?: string
    clientId?: number
    boardId?: number
  }>>
  // Watch Folders
  getWatchFolders: () => Promise<any[]>
  refreshWatchFolders: () => Promise<any>
  // Wave C.5 — Perf HUD
  getPerfSnapshot: () => Promise<any>
  setLogLevel: (level: string) => Promise<boolean>
  pauseSync: () => Promise<boolean>
  resumeSync: () => Promise<boolean>
  openSyncFolder: () => Promise<void>
  selectSyncFolder: () => Promise<string | null>
  openExternalUrl: (url: string) => Promise<boolean>
  login: (apiUrl: string, email: string, password: string) => Promise<LoginResult>
  verify2FA: (apiUrl: string, email: string, code: string, tempToken: string) => Promise<{ success: boolean, error?: string }>
  logout: () => Promise<boolean>
  minimize: () => Promise<void>
  maximize: () => Promise<void>
  close: () => Promise<void>
  onNavigate: (callback: (route: string) => void) => () => void
  onSyncStatusChange: (callback: (status: any) => void) => () => void
  onActivityUpdate: (callback: (activity: any) => void) => () => void
  onEditingUpdate: (callback: (editors: EditingStatus[]) => void) => () => void
  onAppReady: (callback: () => void) => () => void
  onFilesChanged: (callback: (data: { folderId: number | null }) => void) => () => void
  onAuthFailed: (callback: () => void) => () => void
  onAppLocked: (callback: () => void) => () => void
  onAppUnlocked: (callback: () => void) => () => void
  onForcedLogout: (callback: (data: { reason: string }) => void) => () => void
  // NAS Direct Access
  getAccessMode: () => Promise<'direct-nas' | 'server-api' | 'offline'>
  getAccessModeStatus: () => Promise<AccessModeStatus>
  forceAccessModeCheck: () => Promise<'direct-nas' | 'server-api' | 'offline'>
  getNasConfig: () => Promise<NasConfig | null>
  getConnectionConfig: () => Promise<any | null>
  onAccessModeChanged: (callback: (data: { mode: string; reason: string }) => void) => () => void
  onAccessModeReady: (callback: (status: AccessModeStatus) => void) => () => void
  // Printer
  printer: {
    getPrinters: () => Promise<Array<{ name: string; displayName: string; status: number; isDefault: boolean; options: Record<string, string>; source: string }>>
    scanNetwork: () => Promise<Array<{ name: string; displayName: string; status: number; isDefault: boolean; source: string; ip?: string; port?: number; protocol?: string; model?: string; location?: string; mac?: string }>>
    getAllPrinters: () => Promise<{ local: Array<any>; network: Array<any> }>
    onScanProgress: (callback: (data: { scanned: number; total: number }) => void) => () => void
    printFile: (filePath: string, printerName: string, options?: { copies?: number; silent?: boolean; duplex?: string }) => Promise<{ success: boolean; printer: string; error?: string }>
    printHtml: (htmlContent: string, printerName: string, options?: { copies?: number; silent?: boolean; duplex?: string }) => Promise<{ success: boolean; printer: string; error?: string }>
    printToPdf: (htmlContent: string) => Promise<{ success: boolean; data?: Buffer; error?: string }>
  }
  // App Lock
  lock: {
    isBiometricAvailable: () => Promise<boolean>
    authenticateBiometric: () => Promise<boolean>
    hasPin: () => Promise<boolean>
    setPin: (pin: string) => Promise<{ success: boolean; message?: string }>
    verifyPin: (pin: string) => Promise<boolean>
    removePin: () => Promise<boolean>
    getSettings: () => Promise<{
      lockEnabled: boolean
      lockTimeout: number
      lockOnMinimize: boolean
      hasPin: boolean
      biometricAvailable: boolean
      isLocked: boolean
    }>
    setSettings: (settings: { lockEnabled?: boolean; lockTimeout?: number; lockOnMinimize?: boolean }) => Promise<{ success: boolean; message?: string }>
    lockNow: () => Promise<boolean>
    isLocked: () => Promise<boolean>
  }
}

export interface NasConfig {
  enabled: boolean
  ip: string
  smbShare: string
  nfsPath: string
  userFolder: string
  directAccessEnabled: boolean
}

export interface AccessModeStatus {
  mode: 'direct-nas' | 'server-api' | 'offline'
  nasIp: string | null
  nasReachable: boolean
  serverUrl: string | null
  initialized: boolean
}

declare global {
  interface Window {
    api: ElectronAPI
  }
}

