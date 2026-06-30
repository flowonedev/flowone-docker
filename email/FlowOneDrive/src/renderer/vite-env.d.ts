/// <reference types="vite/client" />

interface Window {
  api: {
    // Auth
    login: (apiUrl: string, email: string, password: string) => Promise<{
      success: boolean
      requires2FA?: boolean
      tempToken?: string
      error?: string
    }>
    verify2FA: (apiUrl: string, email: string, code: string, tempToken: string, trustDevice: boolean) => Promise<{
      success: boolean
      error?: string
    }>
    logout: () => Promise<void>
    
    // Config
    getConfig: () => Promise<any>
    setConfig: (key: string, value: any) => Promise<boolean>
    selectSyncFolder: () => Promise<string | null>
    openSyncFolder: () => Promise<void>
    
    // Files
    getFiles: (folderId?: number) => Promise<{ files: any[]; folders: any[]; quota?: any }>
    getAllFolders: () => Promise<{ folders: any[] }>
    getTrash: () => Promise<{ files: any[]; folders: any[] }>
    getQuota: () => Promise<{ used: number; total: number | null; percentage: number }>
    
    // Activity
    getActivityLog: () => Promise<{ activities: any[] }>
    
    // Sync
    getSyncStatus: () => Promise<{
      status: 'idle' | 'syncing' | 'paused' | 'offline' | 'error'
      message: string
      progress?: number
      lastSync?: string
      pendingChanges: number
    }>
    triggerSync: () => Promise<void>
    pauseSync: () => Promise<void>
    resumeSync: () => Promise<void>
    
    // Editing sessions (watch folder + sync)
    getEditingSessions: () => Promise<Array<{
      filename: string
      processName: string
      source: string
      duration: number
      folderId: number | null
      watchFolder: { watchFolderId: number; clientId: number; clientName: string; boardId: number | null; boardName: string | null; cardId: number | null } | null
    }>>

    // Editing status
    getOtherEditors: () => Promise<any[]>
    getSelfEditing: () => Promise<Array<{ filename: string; folderId: number | null }>>
    onEditingUpdate: (callback: (editors: any[]) => void) => () => void
    onSelfEditingUpdate: (callback: (editing: Array<{ filename: string; folderId: number | null }>) => void) => () => void
    
    // Website tracking
    getActiveTrackedWebsites: () => Promise<Array<{ domain: string; clientName: string; boardName: string; duration: number }>>
    refreshUrlMappings: () => Promise<void>
    refreshFolderMapping: () => Promise<void>
    
    // Window controls
    minimize: () => Promise<void>
    maximize: () => Promise<void>
    close: () => Promise<void>
    setNativeTheme: (mode: 'light' | 'dark') => void
    
    // Events
    onNavigate: (callback: (route: string) => void) => () => void
    onAuthFailed: (callback: () => void) => () => void
    onAppReady: (callback: () => void) => () => void
    onDebugLog: (callback: (message: string) => void) => () => void
    onAccessModeChanged: (callback: (data: { mode: string; reason: string }) => void) => () => void
    // Wave C.1: push events from main process
    onSyncStatusChange: (callback: (status: any) => void) => () => void
    onActivityUpdate: (callback: (activity: any) => void) => () => void
    
    // Watch Folders
    getWatchFolders: () => Promise<any[]>
    refreshWatchFolders: () => Promise<any>
    changeWatchFolderPath: (id: number) => Promise<{ success: boolean; canceled?: boolean; error?: string; folders?: any[] }>
    removeWatchFolder: (id: number) => Promise<{ success: boolean; error?: string; folders?: any[] }>
    openWatchFolderLocally: (id: number) => Promise<{ success: boolean; error?: string }>

    // NAS Direct Access / Access Mode
    getAccessMode: () => Promise<'direct-nas' | 'server-api' | 'offline'>
    getAccessModeStatus: () => Promise<{
      mode: 'direct-nas' | 'server-api' | 'offline'
      nasIp: string | null
      nasReachable: boolean
      serverUrl: string | null
      initialized: boolean
      pendingOfflineCount: number
    }>
    forceAccessModeCheck: () => Promise<'direct-nas' | 'server-api' | 'offline'>
    getPendingOfflineCount: () => Promise<number>
  }
}

