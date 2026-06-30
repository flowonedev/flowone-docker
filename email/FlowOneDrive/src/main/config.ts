import Store from 'electron-store'
import path from 'path'
import os from 'os'

interface Config {
  apiUrl: string | null
  // NOTE: authToken, sessionToken, deviceToken are in secureStorage (OS-encrypted), NOT here
  userEmail: string | null
  syncFolder: string
  syncEnabled: boolean
  syncInterval: number // in seconds
  notificationsEnabled: boolean
  startMinimized: boolean
  startOnBoot: boolean
  launchAtStartup: boolean
  lastSyncCursor: string | null
  lastCollaboratorCheck: string | null
  conflictResolution: 'newer' | 'ask' | 'keep-both'
  // Time tracking configuration
  timeTracking: {
    enabled: boolean
    handleMonitorEnabled: boolean    // Windows file handle monitoring
    windowMonitorEnabled: boolean    // Active window title monitoring
    handlePollInterval: number       // ms - how often to poll file handles
    windowPollInterval: number       // ms - how often to poll active window
    inactivityTimeout: number        // ms - close session after inactivity
  }
  // NAS credentials for direct access (encrypted)
  nasCredentials: {
    username: string | null
    password: string | null  // Encrypted with CryptoJS
    useCredentials: boolean  // Whether to use stored credentials vs Windows Credential Manager
  }
  refreshToken: string | null
  // Watch Folders
  watchFolders: {
    cachedFolders: Array<{ id: number; name: string; folderPath: string; resolvedPath: string; clientId: number; clientName: string; boardId?: number | null; boardName?: string | null; cardId?: number | null; resolved?: boolean; status?: string }>
  }
  // App lock settings
  lockEnabled: boolean
  lockTimeout: number  // minutes of inactivity before auto-lock (0 = manual only)
  lockOnMinimize: boolean
}

const defaultSyncFolder = path.join(os.homedir(), 'FlowOneDrive')

const defaults: Config = {
  apiUrl: null,
  userEmail: null,
  syncFolder: defaultSyncFolder,
  syncEnabled: true,
  syncInterval: 30, // 30 seconds
  notificationsEnabled: true,
  startMinimized: false,
  startOnBoot: false,
  launchAtStartup: false,
  lastSyncCursor: null,
  lastCollaboratorCheck: null,
  conflictResolution: 'newer',
  timeTracking: {
    enabled: true,
    handleMonitorEnabled: true,     // Enable Windows handle monitoring
    windowMonitorEnabled: true,     // Enable active window monitoring
    handlePollInterval: 3000,       // Poll handles every 3 seconds
    windowPollInterval: 2000,       // Poll active window every 2 seconds
    inactivityTimeout: 300000,      // 5 minutes inactivity = session closed
  },
  nasCredentials: {
    username: null,
    password: null,
    useCredentials: false,
  },
  refreshToken: null,
  watchFolders: {
    cachedFolders: [],
  },
  // App lock settings
  lockEnabled: false,
  lockTimeout: 5,
  lockOnMinimize: false,
}

export class ConfigStore {
  private static instance: ConfigStore
  private store: Store<Config>

  private constructor() {
    this.store = new Store<Config>({
      name: 'mailflow-drive-config',
      defaults,
    })

    // Ensure sync folder exists
    const fs = require('fs')
    const syncFolder = this.store.get('syncFolder')
    if (!fs.existsSync(syncFolder)) {
      fs.mkdirSync(syncFolder, { recursive: true })
    }
  }

  static getInstance(): ConfigStore {
    if (!ConfigStore.instance) {
      ConfigStore.instance = new ConfigStore()
    }
    return ConfigStore.instance
  }

  get<K extends keyof Config>(key: K): Config[K] {
    return this.store.get(key)
  }

  set<K extends keyof Config>(key: K, value: Config[K]): void {
    this.store.set(key, value)
  }

  getAll(): Config {
    return this.store.store
  }

  reset(): void {
    this.store.clear()
  }
}

