import { createApp } from 'vue'
import { createPinia } from 'pinia'
import App from './App.vue'
import router from './router'
import i18n from '@/i18n'
import './styles/main.css'

// Type declaration for Electron API
declare global {
  interface Window {
    api: {
      window: {
        minimize: () => void
        maximize: () => void
        close: () => void
        isMaximized: () => Promise<boolean>
      }
      config: {
        get: (key: string) => Promise<any>
        set: (key: string, value: any) => Promise<boolean>
        getAll: () => Promise<Record<string, any>>
      }
      auth: {
        setToken: (token: string, email: string, name: string) => Promise<boolean>
        clearToken: () => Promise<boolean>
        getToken: () => Promise<string | null>
        isLoggedIn: () => Promise<boolean>
      }
      db: {
        getEmails: (folderId: number, limit?: number, offset?: number) => Promise<any[]>
        getFolders: () => Promise<any[]>
        getFolderByPath: (folderPath: string) => Promise<any>
        getEmailsByFolder: (folderPath: string, limit?: number, offset?: number) => Promise<any>
        getEmail: (folderPath: string, uid: number) => Promise<any>
        fetchEmailBody: (emailId: number) => Promise<any>
        syncEmails: () => Promise<boolean>
        syncCalendars: () => Promise<boolean>
        syncBoards: () => Promise<boolean>
        syncAllForOffline: () => Promise<any>
        syncEmailBodies: (days?: number, maxCount?: number) => Promise<any>
        getEmailsNeedingBodies: (days?: number) => Promise<number>
        getSyncStatus: () => Promise<any>
        hasOfflineData: () => Promise<any>
        cacheEmails: (folderPath: string, emails: any[]) => Promise<number>
        cacheEmailBody: (folderPath: string, uid: number, bodyHtml: string, bodyText: string) => Promise<boolean>
        cacheEvents: (events: any[]) => Promise<number>
        cacheBoards: (boards: any[]) => Promise<number>
        cacheBoardFull: (boardData: any) => Promise<boolean>
        cacheClients: (clients: any[]) => Promise<number>
        cacheTimeEntries: (entries: any[]) => Promise<number>
        cacheTodos: (todos: any[]) => Promise<number>
        getOfflineBoards: () => Promise<any[]>
        getOfflineClients: () => Promise<any[]>
        getOfflineTodos: () => Promise<any[]>
        getOfflineCalendars: () => Promise<any[]>
        getOfflineEvents: () => Promise<any[]>
        getCalendars: () => Promise<any[]>
        getEvents: (startDate: string, endDate: string, calendarId?: number) => Promise<any[]>
        getBoards: () => Promise<any[]>
        getBoard: (boardId: number) => Promise<any>
        getClients: () => Promise<any[]>
        getPendingCount: () => Promise<number>
        queueChange: (entityType: string, entityId: number | null, action: string, payload: object) => Promise<number>
        getSetting: (key: string) => Promise<string | null>
        setSetting: (key: string, value: string) => Promise<boolean>
      }
      lock: {
        isBiometricAvailable: () => Promise<boolean>
        authenticateBiometric: () => Promise<boolean>
        hasPin: () => Promise<boolean>
        setPin: (pin: string) => Promise<{ success: boolean; message?: string }>
        verifyPin: (pin: string) => Promise<boolean>
        removePin: () => Promise<boolean>
        getSettings: () => Promise<any>
        setSettings: (settings: { lockEnabled?: boolean; lockTimeout?: number; lockOnMinimize?: boolean }) => Promise<any>
        lockNow: () => Promise<boolean>
        isLocked: () => Promise<boolean>
      }
      notification: {
        show: (title: string, body: string) => Promise<boolean>
      }
      network: {
        onStatusChange: (callback: (isOnline: boolean) => void) => () => void
      }
      debug: {
        getDbStats: () => Promise<any>
        getTableData: (tableName: string, limit?: number) => Promise<any>
        runQuery: (sql: string) => Promise<any>
        openDbFolder: () => Promise<string>
      }
      sso: {
        onAuthenticated: (cb: (data: any) => void) => () => void
        exchangeCode: (code: string) => Promise<any>
        logout: () => Promise<boolean>
      }
      oauth: {
        start: (provider: string) => Promise<any>
      }
      tray: {
        setUnread: (hasUnread: boolean) => Promise<boolean>
      }
      platform: string
      getProxyPort: () => Promise<number>
      setBadgeCount: (count: number) => Promise<boolean>
      openExternal: (url: string) => Promise<boolean>
      getVersion: () => Promise<string>
      getAppPath: (name: string) => Promise<string>
      on: (channel: string, callback: (...args: any[]) => void) => () => void
      off: (channel: string, callback?: Function) => void
      send: (channel: string, ...args: any[]) => void
    }
  }
}

// Global handler for chunk loading failures
const handleChunkLoadError = (error: any) => {
  const chunkFailedMessage = /Loading chunk|Failed to fetch dynamically imported module|Loading module/i
  
  if (chunkFailedMessage.test(error?.message || error?.reason?.message || '')) {
    console.warn('Chunk loading failed. Reloading app...')
    
    // Prevent multiple reload attempts
    if (sessionStorage.getItem('chunk_reload_attempted')) {
      console.error('Chunk reload already attempted')
      return
    }
    sessionStorage.setItem('chunk_reload_attempted', 'true')
    
    // Reload
    window.location.reload()
  }
}

// Listen for unhandled errors and rejections
window.addEventListener('error', (event) => {
  handleChunkLoadError(event.error || event)
})

window.addEventListener('unhandledrejection', (event) => {
  handleChunkLoadError(event)
})

// Clear the reload flag on successful page load
window.addEventListener('load', () => {
  setTimeout(() => {
    sessionStorage.removeItem('chunk_reload_attempted')
  }, 3000)
})

// Create Vue app
const app = createApp(App)

// Setup Pinia store
const pinia = createPinia()
app.use(pinia)

// Setup i18n (shared with web frontend)
app.use(i18n)

// Setup Router (imported from router/index.js)
app.use(router)

// Mount app
app.mount('#app')
