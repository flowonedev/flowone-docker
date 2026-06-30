import Store from 'electron-store'

interface ConfigSchema {
  // Authentication (access/session tokens are in secureStorage, NOT here)
  userEmail: string | null
  userName: string | null
  sessionToken: string | null  // Session token for IMAP password lookup
  deviceToken: string | null   // Trusted device token for 2FA bypass (persists across logout)
  refreshToken: string | null  // Refresh token for proactive JWT renewal
  
  // Server settings
  apiUrl: string
  wsUrl: string
  
  // Sync settings
  syncEnabled: boolean
  syncInterval: number  // seconds
  lastEventVersion: number
  
  // UI settings
  theme: 'light' | 'dark' | 'system'
  sidebarCollapsed: boolean
  startMinimized: boolean
  minimizeToTray: boolean
  launchAtStartup: boolean
  dbSyncDebugEnabled: boolean
  
  // Notifications
  notificationsEnabled: boolean
  notifyNewEmail: boolean
  notifyCalendarReminder: boolean
  notifyBoardUpdates: boolean
  
  // Drive integration
  driveEnabled: boolean
  driveSyncFolder: string | null
  
  // App lock settings
  lockEnabled: boolean
  lockTimeout: number  // minutes of inactivity before auto-lock (0 = manual only)
  lockOnMinimize: boolean
  
  // Window state
  windowBounds: {
    x?: number
    y?: number
    width: number
    height: number
  }
  windowMaximized: boolean
}

const defaults: ConfigSchema = {
  userEmail: null,
  userName: null,
  sessionToken: null,
  deviceToken: null,
  refreshToken: null,
  
  apiUrl: 'https://flowone.pro',
  wsUrl: 'wss://flowone.pro/mailsync_ws',
  
  syncEnabled: true,
  syncInterval: 300,
  lastEventVersion: 0,
  
  theme: 'system',
  sidebarCollapsed: false,
  startMinimized: false,
  minimizeToTray: true,
  launchAtStartup: false,
  dbSyncDebugEnabled: false,
  
  notificationsEnabled: true,
  notifyNewEmail: true,
  notifyCalendarReminder: true,
  notifyBoardUpdates: true,
  
  driveEnabled: true,
  driveSyncFolder: null,
  
  lockEnabled: false,
  lockTimeout: 5,  // 5 minutes default
  lockOnMinimize: false,
  
  windowBounds: {
    width: 1200,
    height: 800,
  },
  windowMaximized: false,
}

export const configStore = new Store<ConfigSchema>({
  name: 'config',
  defaults,
})

export type { ConfigSchema }

