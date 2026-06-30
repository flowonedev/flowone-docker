import { app, BrowserWindow, ipcMain, nativeImage, dialog, nativeTheme } from 'electron'
import path from 'path'
import os from 'os'
import express from 'express'
import type { Express } from 'express'

// GPU stability - use ANGLE D3D11 backend for consistent rendering on Windows
// (disableHardwareAcceleration causes extreme lag; ANGLE avoids driver crashes while keeping GPU)
app.commandLine.appendSwitch('use-angle', 'd3d11')
app.commandLine.appendSwitch('ignore-gpu-blocklist')
app.commandLine.appendSwitch('disable-gpu-sandbox')

// Windows toast identity. Must match the appId in electron-builder.yml — the
// NSIS installer stamps that ID onto the Start Menu shortcut, and Windows uses
// it to resolve the app name + icon shown on notifications. Without this call
// toasts show as "electron.app.FlowOne Drive" with no icon.
app.setAppUserModelId('com.flowone.drive')

// Local HTTP server for FlowOneEmail integration
let localApiServer: ReturnType<Express['listen']> | null = null

// Enable crash reporting
process.on('uncaughtException', (error) => {
  console.error('Uncaught Exception:', error)
})

process.on('unhandledRejection', (reason) => {
  console.error('Unhandled Rejection:', reason)
})
import { TrayManager } from './tray'
import { SyncEngine } from './syncEngine'
import { FileWatcher } from './fileWatcher'
import { Database } from './database'
import { NotificationManager } from './notifications'
import { ConfigStore } from './config'
import { DocumentTimeTracker } from './documentTimeTracker'
import { EditingDetector } from './editingDetector'
import { BrowserMonitor } from './browserMonitor'
import { isQuitting, setQuitting } from './state'
import { AccessModeManager, createAccessModeManager, type AccessMode } from './nas'
import { registerDevice, startStatusPolling, stopStatusPolling, setMainWindow as setSecurityWindow } from './deviceSecurity'
import { getOrCreateDeviceId } from './deviceId'
import { initBiometricAuth, registerBiometricIpcHandlers, cleanupBiometricAuth, primeBiometricAvailability } from './biometricAuth'
import { getPrinterService } from './printerService'

// Import secure storage for token management
import { setAuthToken, getAuthToken, setSessionToken, getSessionToken, setDeviceToken, getDeviceToken, setNasCredentials as setSecureNasCreds, getNasCredentials as getSecureNasCreds } from './secureStorage'
import { readSharedAuth, writeSharedAuth, clearSharedAuth, SharedAuthWatcher, type SharedAuthData } from './sso/sharedAuth'
import { openOAuthWindow } from './sso/oauthWindow'
import { startTokenRefreshTimer, stopTokenRefreshTimer } from './sso/tokenRefresh'

// Wave C.5 / Metrics M.1–M.3: perf instrumentation
import { eventLoopMonitor } from './perf/eventLoopMonitor'
import { metrics } from './perf/metrics'
import { intervalRegistry } from './perf/IntervalRegistry'
import { logger } from './log/Logger'

// Keep a global reference to prevent garbage collection
let mainWindow: BrowserWindow | null = null
let trayManager: TrayManager | null = null
let syncEngine: SyncEngine | null = null
let fileWatcher: FileWatcher | null = null
let database: Database | null = null
let notificationManager: NotificationManager | null = null
let documentTimeTracker: DocumentTimeTracker | null = null
let editingDetector: EditingDetector | null = null
let browserMonitor: BrowserMonitor | null = null
let accessModeManager: AccessModeManager | null = null
let watchFolderService: import('./watchFolderService').WatchFolderService | null = null

// In production builds or when running 'npm start' after build, load from dist
// Only use dev server when explicitly running 'npm run dev'
const isDev = process.env.NODE_ENV === 'development' && process.env.VITE_DEV_SERVER === 'true'

// Disable renderer cache to avoid stale assets
app.commandLine.appendSwitch('disable-http-cache')

// Register as handler for flowone:// protocol
if (process.defaultApp) {
  if (process.argv.length >= 2) {
    app.setAsDefaultProtocolClient('flowone', process.execPath, [path.resolve(process.argv[1])])
  }
} else {
  app.setAsDefaultProtocolClient('flowone')
}

async function createWindow(): Promise<BrowserWindow> {
  // Load app icon
  const iconPath = path.join(__dirname, '..', '..', 'assets', 'icon.png')
  let appIcon = nativeImage.createEmpty()
  try {
    const loadedIcon = nativeImage.createFromPath(iconPath)
    if (!loadedIcon.isEmpty()) {
      appIcon = loadedIcon
    }
  } catch (e) {
    console.warn('[Main] Could not load app icon:', e)
  }

  const isMac = process.platform === 'darwin'

  mainWindow = new BrowserWindow({
    width: 1000,
    height: 900,
    minWidth: 400,
    minHeight: 500,
    show: true,
    frame: isMac,
    titleBarStyle: isMac ? 'hiddenInset' : undefined,
    trafficLightPosition: isMac ? { x: 12, y: 10 } : undefined,
    backgroundColor: '#0f172a',
    icon: appIcon,
    webPreferences: {
      preload: path.join(__dirname, '../preload/index.js'),
      nodeIntegration: false,
      contextIsolation: true,
    },
  })

  // Load the app
  const rendererPath = path.join(__dirname, '../renderer/index.html')
  
  if (isDev) {
    await mainWindow.loadURL('http://localhost:5173')
    mainWindow.webContents.openDevTools()
  } else {
    await mainWindow.loadFile(rendererPath)
  }
  
  // Force window visible and focused so the dev build is obvious
  mainWindow.setTitle('FlowOne - Drive')
  mainWindow.show()
  mainWindow.focus()
  
  // Allow F12 to toggle DevTools only in development mode
  if (isDev) {
    mainWindow.webContents.on('before-input-event', (event, input) => {
      if (input.key === 'F12') {
        mainWindow?.webContents.toggleDevTools()
      }
    })
  }

  // Hide instead of close (minimize to tray)
  mainWindow.on('close', (event) => {
    if (!isQuitting) {
      event.preventDefault()
      mainWindow?.hide()
    }
  })

  mainWindow.on('ready-to-show', () => {
    mainWindow?.show()
  })

  // When renderer signals it's ready, re-emit the editing status
  mainWindow.webContents.on('did-finish-load', () => {
    console.log('[WINDOW] Renderer finished loading, will emit self-editing status in 500ms')
    setTimeout(() => {
      if (syncEngine) {
        const editing = syncEngine.getSelfEditing()
        console.log('[WINDOW] Re-emitting self-editing:', editing)
        mainWindow?.webContents.send('self-editing-update', editing)
      }
    }, 500)
  })

  return mainWindow
}

async function initializeApp() {
  console.log('[INIT] Starting app initialization...')
  
  // Initialize printer service with main window reference
  if (mainWindow) {
    getPrinterService().setWindow(mainWindow)
    console.log('[INIT] PrinterService initialized')
  }

  // Initialize configuration
  const config = ConfigStore.getInstance()
  console.log('[INIT] Config loaded, apiUrl:', config.get('apiUrl'))
  console.log('[INIT] Config loaded, authToken exists:', !!getAuthToken())

  // Initialize database
  database = new Database()
  await database.initialize()
  console.log('[INIT] Database initialized')

  // Initialize notification manager
  notificationManager = new NotificationManager()

  // Initialize sync engine
  syncEngine = new SyncEngine(database, config, notificationManager)
  console.log('[INIT] SyncEngine created')
  
  // Initialize document time tracker
  documentTimeTracker = new DocumentTimeTracker(config, database)
  
  // Set main window for IPC communication
  if (mainWindow) {
    syncEngine.setMainWindow(mainWindow)
    documentTimeTracker.setMainWindow(mainWindow)
  }
  
  // Listen for auth failure events from syncEngine
  syncEngine.on('auth-failed', () => {
    console.log('[MAIN] Auth failed event received - will show login screen')
    handleAuthFailure()
  })

  // Wave C.1: forward sync state changes to the renderer as push events
  // (replaces the renderer's 2 s polling loop). Throttle bursts so a
  // 1000-file sync cycle doesn't flood IPC.
  let syncStatusThrottle: NodeJS.Timeout | null = null
  let pendingSyncStatus: any = null
  syncEngine.on('state-changed', (status: any) => {
    pendingSyncStatus = status
    if (syncStatusThrottle) return
    syncStatusThrottle = setTimeout(() => {
      try {
        if (mainWindow && !mainWindow.isDestroyed() && pendingSyncStatus) {
          mainWindow.webContents.send('sync-status-change', pendingSyncStatus)
        }
      } catch {
        // Ignore: window may be torn down mid-emit during shutdown.
      }
      pendingSyncStatus = null
      syncStatusThrottle = null
    }, 100)
  })

  // Wave C.1: push individual activity items so ActivityLog can update
  // immediately instead of polling every 5 s. The ring/log-fetch IPC stays
  // as a 30 s heartbeat fallback.
  syncEngine.onActivity((activity: any) => {
    try {
      if (mainWindow && !mainWindow.isDestroyed()) {
        mainWindow.webContents.send('activity-update', activity)
      }
    } catch {
      // Ignore: window torn down during shutdown.
    }
  })

  // Initialize file watcher and editing detector
  const syncFolder = config.get('syncFolder')
  if (syncFolder) {
    // Get time tracking config
    const timeTrackingConfig = config.get('timeTracking')
    
    // Initialize editing detector (aggregates all detection sources)
    editingDetector = new EditingDetector(syncFolder, {
      handlePollInterval: timeTrackingConfig.handlePollInterval,
      windowPollInterval: timeTrackingConfig.windowPollInterval,
      inactivityTimeout: timeTrackingConfig.inactivityTimeout,
      enableHandleMonitor: timeTrackingConfig.handleMonitorEnabled,
      enableWindowMonitor: timeTrackingConfig.windowMonitorEnabled,
    })
    
    // Connect editing detector to time tracker and sync engine
    editingDetector.setTimeTracker(documentTimeTracker)
    editingDetector.setSyncEngine(syncEngine)
    
    // Initialize file watcher with editing detector
    fileWatcher = new FileWatcher(syncFolder, syncEngine, documentTimeTracker, editingDetector)
    fileWatcher.start()
    console.log('[INIT] FileWatcher started for:', syncFolder)
    
    // Start editing detector (handle monitor + window monitor)
    if (timeTrackingConfig.enabled) {
      editingDetector.start()
      console.log('[INIT] EditingDetector started (handle + window monitoring)')
    }
  }

  // Initialize Watch Folder Service
  if (documentTimeTracker && editingDetector) {
    const { WatchFolderService } = require('./watchFolderService')
    watchFolderService = new WatchFolderService(config, documentTimeTracker, editingDetector)
    editingDetector.onWatchSessionEnd = (session) => {
      watchFolderService?.reportFileActivity(session)
    }
  }

  // Initialize tray
  trayManager = new TrayManager(mainWindow!, syncEngine)

  // Start sync and time tracking
  const apiUrl = config.get('apiUrl')
  const authToken = getAuthToken()
  console.log('[INIT] Checking credentials:', { apiUrl: !!apiUrl, authToken: !!authToken })
  
  // Send debug info to renderer after window loads
  mainWindow!.webContents.on('did-finish-load', () => {
    mainWindow?.webContents.send('debug-log', `[MAIN] Startup - apiUrl: ${!!apiUrl}, authToken: ${!!authToken}, accessModeManager: ${!!accessModeManager}`)
  })
  
  if (apiUrl && authToken) {
    console.log('[INIT] Credentials found, starting sync...')
    mainWindow?.webContents.send('debug-log', `[MAIN-INIT] Credentials found: apiUrl=${apiUrl?.substring(0, 30)}...`)
    
    // Start device security polling on startup
    if (mainWindow) {
      setSecurityWindow(mainWindow)
      startStatusPolling(mainWindow)
      initBiometricAuth(mainWindow)
    }
    
    // Register device on startup (in case it was never registered)
    const sessionToken = getSessionToken()
    registerDevice(apiUrl, authToken, sessionToken).catch(() => {})
    
    // Initialize AccessModeManager for NAS direct access
    accessModeManager = createAccessModeManager(config)
    accessModeManager.setCredentials(apiUrl, authToken)
    mainWindow?.webContents.send('debug-log', `[MAIN-INIT] AccessModeManager created, credentials set`)
    
    // Listen for mode changes and update syncEngine
    accessModeManager.on('mode-changed', (data: { mode: AccessMode; reason: string }) => {
      console.log(`[INIT] Access mode changed: ${data.mode} (${data.reason})`)
      mainWindow?.webContents.send('debug-log', `[MAIN-INIT] Mode changed: ${data.mode} (${data.reason})`)
      
      // Update syncEngine with the new mode
      syncEngine?.setAccessMode(data.mode)
      
      // Notify renderer of mode change
      mainWindow?.webContents.send('access-mode-changed', data)
      
      // Log mode for tray (tooltip update not implemented)
      console.log(`[INIT] Tray mode: ${data.mode === 'direct-nas' ? 'Direct NAS' : data.mode === 'server-api' ? 'Server' : 'Offline'}`)
    })
    
    // Listen for initialized event
    accessModeManager.on('initialized', (cfg) => {
      mainWindow?.webContents.send('debug-log', `[MAIN-INIT] AccessModeManager 'initialized' event fired, hasConfig: ${!!cfg}`)
    })
    
    // Forward debug events to renderer
    accessModeManager.on('debug', (msg: string) => {
      mainWindow?.webContents.send('debug-log', msg)
    })
    
    // Initialize NAS discovery (async)
    mainWindow?.webContents.send('debug-log', `[MAIN-INIT] Calling accessModeManager.initialize()...`)
    accessModeManager.initialize().then(() => {
      const status = accessModeManager?.getStatus()
      console.log('[INIT] AccessModeManager initialized:', status)
      mainWindow?.webContents.send('debug-log', `[MAIN-INIT] initialize() resolved, status: ${JSON.stringify(status)}`)
      
      // Pass NAS config to syncEngine for direct access
      const nasConfig = accessModeManager?.getNasConfig()
      if (nasConfig && syncEngine) {
        syncEngine.setAccessMode(accessModeManager!.getCurrentMode())
        syncEngine.setNasConfig({
          host: nasConfig.ip,
          port: 445, // SMB default port
          basePath: nasConfig.smbShare || nasConfig.nfsPath,
          userFolder: nasConfig.userFolder
        })
        
        // Load and pass NAS credentials to SyncEngine from secure storage
        const nasCredsConfig = config.get('nasCredentials')
        if (nasCredsConfig?.useCredentials) {
          try {
            const secureCreds = getSecureNasCreds()
            if (secureCreds.username && secureCreds.password) {
              syncEngine.setNasCredentials({
                username: secureCreds.username,
                password: secureCreds.password
              })
              console.log('[INIT] NAS credentials loaded from secure storage for user:', secureCreds.username)
            }
          } catch (e) {
            console.error('[INIT] Failed to load NAS credentials from secure storage:', e)
          }
        }
      }
      
      // Signal that init is complete (for IPC handlers waiting)
      markAccessModeInitialized()
      mainWindow?.webContents.send('access-mode-ready', status)
    }).catch(err => {
      console.error('[INIT] AccessModeManager init error:', err.message)
      mainWindow?.webContents.send('debug-log', `[MAIN-INIT] initialize() REJECTED: ${err.message}`)
      // Still notify renderer that initialization attempted (even if failed)
      markAccessModeInitialized()
      const status = accessModeManager?.getStatus()
      mainWindow?.webContents.send('access-mode-ready', status)
    })
    
    syncEngine.startSync()
    // Also start editing status polling for shared folders
    syncEngine.startEditingStatusPolling()
    // Start document time tracking
    documentTimeTracker.start()

    // Start watch folder service
    if (watchFolderService) {
      watchFolderService.setCredentials(apiUrl, authToken)
      watchFolderService.start().catch(err => {
        console.error('[INIT] WatchFolderService start error:', err?.message)
      })
    }
    
    // Initialize and start browser monitoring for website time tracking
    browserMonitor = new BrowserMonitor()
    
    // Load URL mappings from backend and update browser monitor
    syncEngine.loadUrlMappings().then(mappings => {
      console.log('[INIT] URL mappings loaded:', mappings.length, 'domains')
      if (browserMonitor && mappings.length > 0) {
        browserMonitor.updateMappings(mappings)
        browserMonitor.start()
        console.log('[INIT] BrowserMonitor started with', mappings.length, 'URL mappings')
      } else if (mappings.length === 0) {
        console.log('[INIT] No URL mappings found - website tracking disabled')
      }
    }).catch(err => {
      console.error('[INIT] Failed to load URL mappings:', err.message)
    })
    
    // Connect browser monitor events to time tracker
    if (browserMonitor) {
      browserMonitor.on('urlFocus', (data: any) => {
        documentTimeTracker?.websiteFocused(data.url, data.domain, data.mapping)
      })
      
      browserMonitor.on('urlBlur', (data: any) => {
        documentTimeTracker?.websiteBlurred(data.domain)
      })
    }
    
    // Wave C.3: registered + skip-fire so a slow loadUrlMappings cannot
    // stack on a degraded network.
    const { intervalRegistry } = require('./perf/IntervalRegistry')
    intervalRegistry.set('main.url-mapping-refresh', 30_000, async () => {
      if (!syncEngine) return
      const mappings = await syncEngine.loadUrlMappings()
      if (browserMonitor) {
        browserMonitor.updateMappings(mappings)
        if (mappings.length > 0 && !browserMonitor.isRunning()) {
          browserMonitor.start()
        }
      }
    })
  } else {
    console.log('[INIT] No credentials, skipping auto-sync')
  }

  console.log('[INIT] App initialization complete!')

  // Wave C.3: snapshot every registered interval after boot so we have a
  // record of what's running on the main thread.
  try {
    const { intervalRegistry } = require('./perf/IntervalRegistry')
    intervalRegistry.printSnapshot()
  } catch {
    // Optional: ignore if registry not yet wired in this build.
  }
  
  // Notify renderer that app is ready - trigger file reload
  if (mainWindow) {
    console.log('[INIT] Sending app-ready signal to renderer')
    mainWindow.webContents.send('app-ready')
  }
  
  // Start local HTTP API server for FlowOneEmail integration
  startLocalApiServer()
}

/**
 * Start local HTTP server for FlowOneEmail IPC
 * Provides endpoints for desktop app to query sync status and file paths
 */
// Local API bearer token (regenerated each app launch for security)
import crypto from 'crypto'
const localApiToken = crypto.randomBytes(32).toString('hex')

function startLocalApiServer(): void {
  const localApp = express()
  localApp.use(express.json())
  
  // Strict CORS: allow localhost + this deployment's web app origin for
  // printer/config panel access. The web origin is resolved from the logged-in
  // server (email.<domain>) instead of a hardcoded flowone.pro.
  const allowedLocalOrigins = new Set([
    'http://localhost',
    'http://127.0.0.1',
  ])
  localApp.use((req, res, next) => {
    const origin = req.headers.origin
    if (origin) {
      const originBase = origin.replace(/:\d+$/, '')
      if (allowedLocalOrigins.has(originBase) || originBase === resolveApiUrl()) {
        res.header('Access-Control-Allow-Origin', origin)
        res.header('Access-Control-Allow-Methods', 'GET, POST, OPTIONS')
        res.header('Access-Control-Allow-Headers', 'Content-Type, Authorization')
      }
    }
    if (req.method === 'OPTIONS') {
      return res.sendStatus(204)
    }
    next()
  })
  
  const publicEndpoints = new Set(['/printers', '/printers/scan', '/status'])

  // Authentication middleware for local API
  localApp.use((req, res, next) => {
    // Allow CORS preflight
    if (req.method === 'OPTIONS') return next()

    // Public endpoints skip token auth (still localhost-only via bind)
    if (publicEndpoints.has(req.path)) return next()
    
    const authHeader = req.headers.authorization
    if (!authHeader || authHeader !== `Bearer ${localApiToken}`) {
      res.status(401).json({ error: 'Unauthorized — invalid or missing local API token' })
      return
    }
    next()
  })
  
  // Status endpoint
  localApp.get('/status', (req, res) => {
    const config = ConfigStore.getInstance()
    const status = syncEngine?.getStatus() || { status: 'offline', message: 'Not initialized' }
    const accessStatus = accessModeManager?.getStatus() || { mode: 'offline', initialized: false }
    
    res.json({
      running: true,
      version: app.getVersion(),
      syncFolder: config.get('syncFolder'),
      userEmail: config.get('userEmail'),
      syncStatus: status,
      accessMode: accessStatus,
    })
  })
  
  // Get file path by remote ID
  localApp.get('/file/:id/path', async (req, res) => {
    const remoteId = parseInt(req.params.id)
    
    if (!database || isNaN(remoteId)) {
      res.status(404).json({ error: 'File not found' })
      return
    }
    
    try {
      const file = database.getFileByRemoteId(remoteId)
      if (file && file.localPath) {
        res.json({ 
          localPath: file.localPath,
          filename: file.filename,
          remoteId: file.remoteId,
          checksum: file.checksum,
        })
      } else {
        res.status(404).json({ error: 'File not found or not synced locally' })
      }
    } catch (error: any) {
      res.status(500).json({ error: error.message })
    }
  })
  
  // Get folder path by remote ID
  localApp.get('/folder/:id/path', async (req, res) => {
    const remoteId = parseInt(req.params.id)
    
    if (!database || isNaN(remoteId)) {
      res.status(404).json({ error: 'Folder not found' })
      return
    }
    
    try {
      const folder = database.getFolderByRemoteId(remoteId)
      if (folder && folder.localPath) {
        res.json({ 
          localPath: folder.localPath,
          name: folder.name,
          remoteId: folder.remoteId,
        })
      } else {
        res.status(404).json({ error: 'Folder not found or not synced locally' })
      }
    } catch (error: any) {
      res.status(500).json({ error: error.message })
    }
  })
  
  // Get sync folder path
  localApp.get('/sync-folder', (req, res) => {
    const config = ConfigStore.getInstance()
    const syncFolder = config.get('syncFolder')
    
    if (syncFolder) {
      res.json({ syncFolder })
    } else {
      res.status(404).json({ error: 'Sync folder not configured' })
    }
  })
  
  // Trigger sync
  localApp.post('/sync', async (req, res) => {
    if (syncEngine) {
      syncEngine.syncNow('local-api:/sync')
      res.json({ success: true, message: 'Sync triggered' })
    } else {
      res.status(503).json({ error: 'Sync engine not initialized' })
    }
  })
  
  // Get recent activity
  localApp.get('/activity', async (req, res) => {
    const limit = parseInt(req.query.limit as string) || 20
    const activity = syncEngine?.getRecentActivity(limit) || []
    res.json({ activity })
  })

  // Printer endpoints (no auth required -- localhost-only, read/print operations)
  localApp.get('/printers', async (_req, res) => {
    try {
      const printers = await getPrinterService().getPrinters()
      res.json({ printers })
    } catch (error: any) {
      res.status(500).json({ error: error.message })
    }
  })

  localApp.get('/printers/scan', async (_req, res) => {
    try {
      const result = await getPrinterService().getAllPrinters()
      res.json(result)
    } catch (error: any) {
      res.status(500).json({ error: error.message })
    }
  })

  localApp.post('/print', async (req, res) => {
    const { filePath, printerName, copies, silent, duplex, htmlContent } = req.body || {}

    if (!printerName) {
      res.status(400).json({ error: 'printerName is required' })
      return
    }

    try {
      const options = { copies: copies || 1, silent: silent !== false, duplex: duplex || 'default' }
      let result

      if (htmlContent) {
        result = await getPrinterService().printHtml(htmlContent, printerName, options)
      } else if (filePath) {
        result = await getPrinterService().printFile(filePath, printerName, options)
      } else {
        res.status(400).json({ error: 'Either filePath or htmlContent is required' })
        return
      }

      res.json(result)
    } catch (error: any) {
      res.status(500).json({ success: false, error: error.message })
    }
  })

  // Start server on localhost only (port 47891)
  try {
    localApiServer = localApp.listen(47891, '127.0.0.1', () => {
      console.log('[LocalAPI] HTTP server started on http://127.0.0.1:47891')
    })
    
    localApiServer.on('error', (error: NodeJS.ErrnoException) => {
      if (error.code === 'EADDRINUSE') {
        console.warn('[LocalAPI] Port 47891 already in use - another instance may be running')
      } else {
        console.error('[LocalAPI] Server error:', error.message)
      }
    })
  } catch (error: any) {
    console.error('[LocalAPI] Failed to start HTTP server:', error.message)
  }
}

// ─── SSO helpers ───
let pendingDeepLink: string | null = null
const sharedAuthWatcher = new SharedAuthWatcher()
let tokenRefreshHandle: { stop: () => void } | null = null
let ignoredSeedId: string | null = null

function extractDeepLink(argv: string[]): string | null {
  for (const arg of argv) {
    const cleaned = arg.replace(/^["']|["']$/g, '')
    if (cleaned.includes('flowone://')) {
      const idx = cleaned.indexOf('flowone://')
      return cleaned.substring(idx)
    }
  }
  return null
}

function startDriveTokenRefresh(): void {
  const config = ConfigStore.getInstance()
  const apiUrl = resolveApiUrl()
  tokenRefreshHandle?.stop()
  tokenRefreshHandle = startTokenRefreshTimer({
    getTokens: () => {
      const at = getAuthToken()
      const rt = config.get('refreshToken') as string
      const st = getSessionToken()
      if (!at || !rt || !st) return null
      return { accessToken: at, refreshToken: rt, sessionToken: st }
    },
    onRefreshed: (newTokens) => {
      setAuthToken(newTokens.access_token)
      if (newTokens.refresh_token) config.set('refreshToken', newTokens.refresh_token)
      if (newTokens.session_token) setSessionToken(newTokens.session_token)
      syncEngine?.setCredentials(apiUrl, newTokens.access_token, newTokens.session_token)
    },
    onFailed: () => {
      mainWindow?.webContents.send('auth-failed')
    },
    apiBaseUrl: apiUrl,
  })
}

async function performDriveLogin(tokenData: any): Promise<void> {
  if (!tokenData) return
  const config = ConfigStore.getInstance()
  const apiUrl = resolveApiUrl()

  setAuthToken(tokenData.access_token)
  if (tokenData.session_token) setSessionToken(tokenData.session_token)
  if (tokenData.device_token) setDeviceToken(tokenData.device_token)
  if (tokenData.refresh_token) config.set('refreshToken', tokenData.refresh_token)
  config.set('userEmail', tokenData.user?.email || '')
  config.set('apiUrl', apiUrl)

  const sessionToken = getSessionToken()
  syncEngine?.setCredentials(apiUrl, tokenData.access_token, sessionToken)
  syncEngine?.startSync()

  documentTimeTracker?.setCredentials(apiUrl, tokenData.access_token)
  documentTimeTracker?.start()

  registerDevice(apiUrl, tokenData.access_token, sessionToken).catch(() => {})
  if (mainWindow) {
    setSecurityWindow(mainWindow)
    startStatusPolling(mainWindow)
  }

  if (!accessModeManager) {
    accessModeManager = createAccessModeManager(config)
  }
  accessModeManager.setCredentials(apiUrl, tokenData.access_token)
  accessModeManager.initialize().catch(() => {})

  startDriveTokenRefresh()

  mainWindow?.webContents.send('sso-authenticated', {
    email: tokenData.user?.email,
    displayName: tokenData.user?.display_name,
  })
  console.log('[SSO] performDriveLogin complete for', tokenData.user?.email)
}

async function afterDriveLogin(tokenData: any): Promise<void> {
  const config = ConfigStore.getInstance()
  if (tokenData.refresh_token) config.set('refreshToken', tokenData.refresh_token)
  const apiUrl = resolveApiUrl()

  try {
    const resp = await fetch(`${apiUrl}/api/sso/create-seed`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'Authorization': `Bearer ${tokenData.access_token}`,
        'X-Session-Token': tokenData.session_token || '',
      },
    })
    const seedResult: any = await resp.json()
    if (seedResult.success && seedResult.data) {
      sharedAuthWatcher.markWrite()
      await writeSharedAuth({
        userEmail: tokenData.user?.email || '',
        displayName: tokenData.user?.display_name || '',
        baseUrl: apiUrl,
        seedId: seedResult.data.seed_id,
        seedSecret: seedResult.data.seed_secret,
        seedCreatedAt: seedResult.data.seed_created_at,
        seedExpiresAt: seedResult.data.expires_at,
        updatedAt: Date.now(),
      })
    }
  } catch (e) {
    console.error('[SSO] Failed to create seed:', e)
  }

  await performDriveLogin(tokenData)
}

async function handleDeepLink(url: string): Promise<void> {
  try {
    const parsed = new URL(url)
    if (parsed.protocol !== 'flowone:' || parsed.hostname !== 'auth') return

    const code = parsed.searchParams.get('code')
    const nonce = parsed.searchParams.get('nonce')
    if (!code || !/^[A-Za-z0-9]{12}$/.test(code)) return
    if (!nonce || !/^[A-Za-z0-9]{12}$/.test(nonce)) return

    console.log('[SSO] Deep link received, exchanging code')
    const apiUrl = resolveApiUrl()

    const resp = await fetch(`${apiUrl}/api/sso/exchange`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ code, nonce }),
    })
    const result: any = await resp.json()
    if (result.success && result.data) {
      sharedAuthWatcher.markWrite()
      await writeSharedAuth({
        userEmail: result.data.user?.email || '',
        displayName: result.data.user?.display_name || '',
        baseUrl: apiUrl,
        seedId: result.data.seed_id,
        seedSecret: result.data.seed_secret,
        seedCreatedAt: result.data.seed_created_at,
        seedExpiresAt: result.data.seed_expires_at,
        updatedAt: Date.now(),
      })
      await performDriveLogin(result.data)
    }
  } catch (e) {
    console.error('[SSO] Deep link handling failed:', e)
  }
}

async function handleSSOClone(seedData: SharedAuthData): Promise<void> {
  try {
    const resp = await fetch(`${seedData.baseUrl}/api/sso/clone-session`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ seed_id: seedData.seedId, seed_secret: seedData.seedSecret }),
    })
    const result: any = await resp.json()
    if (result.success && result.data) {
      const config = ConfigStore.getInstance()
      config.set('apiUrl', seedData.baseUrl)

      sharedAuthWatcher.markWrite()
      await writeSharedAuth({
        userEmail: result.data.user?.email || '',
        displayName: result.data.user?.display_name || '',
        baseUrl: seedData.baseUrl,
        seedId: result.data.seed_id,
        seedSecret: result.data.seed_secret,
        seedCreatedAt: result.data.seed_created_at,
        seedExpiresAt: result.data.seed_expires_at,
        updatedAt: Date.now(),
      })
      await performDriveLogin(result.data)
      console.log('[SSO] Clone success')
    } else {
      throw { error: result.error || 'SSO_CLONE_FAILED' }
    }
  } catch (e: any) {
    if (e?.error === 'SSO_SEED_REVOKED') {
      console.log('[SSO] Seed rotated by sibling, retrying shortly')
      const delay = 300 + Math.random() * 500
      setTimeout(async () => {
        const freshData = readSharedAuth()
        if (freshData && freshData.seedId !== seedData.seedId) {
          try { await handleSSOClone(freshData) } catch { /* next poll */ }
        }
      }, delay)
    } else {
      console.error('[SSO] Clone from seed failed:', e?.error || e)
    }
  }
}

function startSharedAuthWatcher(): void {
  sharedAuthWatcher.start(async (data) => {
    if (!data) {
      if (!getAuthToken()) return
      console.log('[SSO] Shared store cleared, logging out')
      tokenRefreshHandle?.stop()
      tokenRefreshHandle = null
      setAuthToken(null)
      setSessionToken(null)
      ConfigStore.getInstance().set('userEmail', '')
      stopStatusPolling()
      syncEngine?.stopSync()
      mainWindow?.webContents.send('auth-failed')
      return
    }

    if (ignoredSeedId && data.seedId === ignoredSeedId) {
      console.log('[SSO] Skipping pre-logout seed')
      return
    }
    ignoredSeedId = null

    const currentEmail = ConfigStore.getInstance().get('userEmail')
    if (currentEmail === data.userEmail && getAuthToken()) return

    if (currentEmail && currentEmail !== data.userEmail) {
      const { dialog } = require('electron')
      if (mainWindow) {
        const { response } = await dialog.showMessageBox(mainWindow, {
          type: 'question',
          buttons: ['Switch Account', 'Stay'],
          message: `Another FlowOne app logged in as ${data.userEmail}. Switch account?`,
        })
        if (response !== 0) return
      }
      setAuthToken(null)
      setSessionToken(null)
      stopStatusPolling()
      syncEngine?.stopSync()
    }

    await handleSSOClone(data)
  })
}

// Prevent multiple instances
const gotLock = app.requestSingleInstanceLock()
if (!gotLock) {
  app.quit()
} else {
  app.on('second-instance', (_event: any, commandLine: string[]) => {
    try {
      const deepLink = extractDeepLink(commandLine)
      if (deepLink) handleDeepLink(deepLink)
    } catch (e) {
      console.error('[DeepLink] Failed to handle:', e)
    }
    if (mainWindow) {
      if (mainWindow.isMinimized()) mainWindow.restore()
      mainWindow.show()
      mainWindow.focus()
    }
  })
}

app.on('open-url', (_event: any, url: string) => {
  try { handleDeepLink(url) } catch (e) { console.error('[DeepLink]', e) }
})

/**
 * Resolve the backend base URL for this install.
 *
 * Source of truth is the stored apiUrl (set at login from the user's email
 * domain). If it is missing, fall back to deriving it from the stored email
 * using the deploy convention `email.<domain>`. No flowone.pro literal default:
 * this app ships to many per-domain deployments.
 */
function resolveApiUrl(): string {
  const config = ConfigStore.getInstance()
  const stored = config.get('apiUrl') as string | null
  if (stored) return String(stored).replace(/\/+$/, '')
  const email = config.get('userEmail') as string | null
  if (email && email.includes('@')) {
    const domain = email.slice(email.lastIndexOf('@') + 1).trim().toLowerCase()
    if (domain) return `https://email.${domain}`
  }
  return ''
}

function migrateConfig(): void {
  const config = ConfigStore.getInstance()
  const apiUrl = config.get('apiUrl') as string | null
  if (apiUrl && apiUrl.endsWith('/api')) {
    config.set('apiUrl', apiUrl.replace(/\/api$/, ''))
    console.log('[Main] Migrated apiUrl: removed trailing /api')
  }
}

app.whenReady().then(async () => {
  migrateConfig()
  registerBiometricIpcHandlers()

  // Detect Windows Hello / Touch ID availability in the background. Without
  // this, the very first lock-get-settings IPC (fired by Settings.onMounted)
  // would block the main thread for 1-3 s while PowerShell cold-starts to
  // query Windows.Security WinRT. Now we eat that cost during app boot, where
  // there's no UI to freeze, and Settings opens are instant.
  void primeBiometricAvailability()

  // Metrics M.1: start event-loop monitoring immediately so we capture
  // startup spikes too. p99 alert fires every 10 s above 100 ms.
  eventLoopMonitor.start()
  eventLoopMonitor.startAlerting({ cadenceMs: 10_000, thresholdMs: 100 })

  const launchAtStartup = ConfigStore.getInstance().get('launchAtStartup')
  app.setLoginItemSettings({ openAtLogin: !!launchAtStartup })

  // Check for deep link on cold start
  const deepLinkArg = extractDeepLink(process.argv)
  if (deepLinkArg) {
    pendingDeepLink = deepLinkArg
    console.log('[SSO] Cold start deep link detected')
  }

  // Quick-validate existing token; only clear on a confirmed 401 from the server.
  // Network errors / timeouts must NOT wipe the token -- the user may simply be offline.
  if (getAuthToken() && !pendingDeepLink) {
    const apiUrl = resolveApiUrl()
    try {
      const resp = await fetch(`${apiUrl}/api/auth/me`, {
        headers: { 'Authorization': `Bearer ${getAuthToken()}` },
        signal: AbortSignal.timeout(5000),
      })
      if (resp.status === 401) {
        console.log('[SSO] Token confirmed invalid (401), clearing for SSO clone')
        setAuthToken(null)
        setSessionToken(null)
        ConfigStore.getInstance().set('userEmail', '')
      }
    } catch (e: any) {
      console.log('[SSO] Token validation skipped (network/timeout):', e.message)
    }
  }

  // Try SSO clone if not logged in
  if (!getAuthToken() && !pendingDeepLink) {
    const seedData = readSharedAuth()
    if (seedData && new Date(seedData.seedExpiresAt) > new Date()) {
      console.log('[SSO] Found shared seed, attempting clone')
      ConfigStore.getInstance().set('apiUrl', seedData.baseUrl)
      try { await handleSSOClone(seedData) } catch {}
    }
  }

  try {
    await createWindow()
    await initializeApp()
  } catch (error) {
    console.error('Failed to initialize app:', error)
  }

  if (pendingDeepLink) {
    const link = pendingDeepLink
    pendingDeepLink = null
    setTimeout(() => handleDeepLink(link), 500)
  }

  if (getAuthToken()) {
    startDriveTokenRefresh()
  }

  startSharedAuthWatcher()

  app.on('activate', () => {
    if (BrowserWindow.getAllWindows().length === 0) {
      void createWindow()
    }
  })
})

app.on('window-all-closed', () => {
  if (process.platform !== 'darwin') {
    // Keep running in tray
  }
})

app.on('before-quit', async (event) => {
  if (!isQuitting) {
    event.preventDefault()
    setQuitting(true)
    
    console.log('[APP] Preparing to quit - flushing time tracking data...')
    
    sharedAuthWatcher.stop()
    tokenRefreshHandle?.stop()
    stopStatusPolling()
    cleanupBiometricAuth()
    
    editingDetector?.stop()
    browserMonitor?.stop()
    
    if (documentTimeTracker) {
      await documentTimeTracker.flushAll()
    }
    
    fileWatcher?.stop()
    syncEngine?.stopSync()
    
    if (localApiServer) {
      localApiServer.close()
      localApiServer = null
    }

    // Wave C.3 + C.4: drain every registered interval and flush the logger
    // write stream so log lines aren't lost on quit.
    try {
      const { intervalRegistry } = require('./perf/IntervalRegistry')
      intervalRegistry.clearAll()
    } catch { /* ignore */ }
    try {
      const { logger } = require('./log/Logger')
      logger.flush()
    } catch { /* ignore */ }

    console.log('[APP] Cleanup complete, quitting...')

    app.quit()
  }
})

// IPC Handlers
ipcMain.handle('get-config', () => {
  const config = ConfigStore.getInstance().getAll()
  return { ...config, authToken: getAuthToken() || null }
})

ipcMain.handle('set-config', (_event, key: string, value: any) => {
  ConfigStore.getInstance().set(key as any, value)
  if (key === 'launchAtStartup' || key === 'startOnBoot') {
    app.setLoginItemSettings({ openAtLogin: !!value })
    console.log(`[Main] Auto-launch set to: ${!!value}`)
  }
  return true
})

ipcMain.handle('get-sync-status', () => {
  return syncEngine?.getStatus() || { status: 'offline', message: 'Not connected' }
})

/**
 * Wave C.5 — Perf HUD snapshot. Single IPC that returns everything the
 * floating panel needs: event-loop p99, scheduler/upload counters,
 * histograms, gauges, rates, registered intervals, hash-skip metrics,
 * recent log ring entries.
 */
ipcMain.handle('get-perf-snapshot', () => {
  return {
    timestamp: Date.now(),
    eventLoop: eventLoopMonitor.getStats(),
    scheduler: syncEngine?.getSchedulerCounters() ?? null,
    uploadQueue: syncEngine?.getUploadQueueCounters() ?? null,
    hashSkip: syncEngine?.getHashSkipMetrics?.() ?? null,
    db: database?.getQueueMetrics?.() ?? null,
    intervals: intervalRegistry.snapshot(),
    metrics: metrics.snapshot(),
    logLevel: logger.getLevel(),
    recentLogs: logger.ring(50),
  }
})

ipcMain.handle('set-log-level', (_evt, level: string) => {
  try {
    logger.setLevel(level as any)
    return true
  } catch {
    return false
  }
})

ipcMain.handle('get-files', async (_event, folderId?: number) => {
  console.log('[IPC] get-files called, folderId:', folderId)
  
  if (!syncEngine) {
    return { files: [], folders: [], quota: null }
  }
  
  try {
    // getFiles already fetches from /api/drive which includes quota data
    // Fetch files and quota in parallel instead of sequentially
    const [result, quota] = await Promise.all([
      syncEngine.getFiles(folderId),
      syncEngine.getQuota(),
    ])
    return { ...result, quota }
  } catch (error) {
    console.error('[IPC] get-files error:', error)
    return { files: [], folders: [], quota: null }
  }
})

// Get ALL folders for the sidebar tree (Windows Explorer style)
ipcMain.handle('get-all-folders', async () => {
  if (!syncEngine) return { folders: [] }
  try {
    const folders = await syncEngine.getAllFolders()
    return { folders }
  } catch (error) {
    console.error('[IPC] get-all-folders error:', error)
    return { folders: [] }
  }
})

ipcMain.handle('get-trash', async () => {
  return syncEngine?.getTrash() || { files: [], folders: [] }
})

ipcMain.handle('get-quota', async () => {
  return syncEngine?.getQuota() || null
})

ipcMain.handle('get-editing-status', async (_, folderId?: number | null) => {
  return syncEngine?.getEditingStatus(folderId) || []
})

ipcMain.handle('get-other-editors', async () => {
  return syncEngine?.getOtherEditors() || []
})

ipcMain.handle('get-self-editing', async () => {
  const result = syncEngine?.getSelfEditing() || []
  console.log('[IPC] get-self-editing called, returning:', JSON.stringify(result))
  return result
})

ipcMain.handle('trigger-sync', async () => {
  return syncEngine?.syncNow('ipc:trigger-sync')
})

ipcMain.handle('reconcile-local-files', async () => {
  console.log('[IPC] reconcile-local-files called')
  return syncEngine?.forceReconcileLocalFiles() || { folders: 0, files: 0 }
})

ipcMain.handle('pause-sync', () => {
  syncEngine?.pauseSync()
  return true
})

ipcMain.handle('resume-sync', () => {
  syncEngine?.resumeSync()
  return true
})

// Activity log
ipcMain.handle('get-activity', async (_event, limit?: number) => {
  return syncEngine?.getRecentActivity(limit || 50) || []
})

// NAS Direct Access / Access Mode
ipcMain.handle('get-access-mode', async () => {
  return accessModeManager?.getCurrentMode() || 'offline'
})

// Promise to wait for AccessModeManager initialization
let accessModeInitPromise: Promise<void> | null = null
let accessModeInitResolver: (() => void) | null = null

// Called when AccessModeManager initializes (success or failure)
function markAccessModeInitialized() {
  if (accessModeInitResolver) {
    accessModeInitResolver()
    accessModeInitResolver = null
  }
}

// Create/get the init wait promise
function getAccessModeInitPromise(): Promise<void> {
  if (!accessModeInitPromise) {
    accessModeInitPromise = new Promise((resolve) => {
      accessModeInitResolver = resolve
      // Auto-resolve after 10s timeout to prevent infinite waiting
      setTimeout(() => {
        if (accessModeInitResolver) {
          console.log('[IPC] AccessModeManager init timeout - resolving anyway')
          accessModeInitResolver()
          accessModeInitResolver = null
        }
      }, 10000)
    })
  }
  return accessModeInitPromise
}

ipcMain.handle('get-access-mode-status', () => {
  // This handler used to await `getAccessModeInitPromise()` for up to 3s if
  // AccessModeManager hadn't finished initializing yet. That meant every
  // SettingsPanel open at startup was a 3s freeze. Now we always return
  // whatever cached state exists immediately; if the manager isn't ready
  // yet, the renderer will get an `access-mode-ready` event later via the
  // existing onAccessModeReady subscription and update the UI then.
  if (accessModeManager && !accessModeManager.isInitialized()) {
    void getAccessModeInitPromise()
  }

  const status = accessModeManager?.getStatus() || {
    mode: 'offline',
    nasIp: null,
    nasReachable: false,
    serverUrl: null,
    initialized: false,
  }

  return {
    ...status,
    pendingOfflineCount: syncEngine?.getPendingOfflineCount() || 0,
  }
})

ipcMain.handle('force-access-mode-check', async () => {
  console.log('[IPC] force-access-mode-check called, accessModeManager exists:', !!accessModeManager)
  if (!accessModeManager) {
    return { mode: 'offline' as const, nasMs: null, serverMs: null, nasIp: null, serverUrl: null }
  }

  // Probe NAS and server independently and record per-probe latency so the
  // Check button can show a concrete result instead of a silent yes/no. The
  // mode is decided by AccessModeManager.forceCheck() (which uses the same
  // probes internally) — we time them again here just for the UI display.
  const nas = accessModeManager.getNasConfig()
  const status = accessModeManager.getStatus()
  const discovery = (accessModeManager as any).nasDiscovery
  let nasMs: number | null = null
  let serverMs: number | null = null

  const probeNas = async () => {
    if (!nas?.enabled || !nas?.directAccessEnabled) return
    const t0 = Date.now()
    try {
      const reachable = discovery && typeof discovery.checkNasReachable === 'function'
        ? await discovery.checkNasReachable()
        : false
      nasMs = reachable ? Date.now() - t0 : null
    } catch {
      nasMs = null
    }
  }

  const probeServer = async () => {
    const t0 = Date.now()
    try {
      const reachable = discovery && typeof discovery.checkServerReachable === 'function'
        ? await discovery.checkServerReachable()
        : false
      serverMs = reachable ? Date.now() - t0 : null
    } catch {
      serverMs = null
    }
  }

  // Run probes in parallel and decide mode in parallel; the timed probes
  // above are observation-only, the canonical decision is forceCheck().
  const [, , mode] = await Promise.all([
    probeNas(),
    probeServer(),
    accessModeManager.forceCheck().catch(() => 'offline' as const),
  ])

  return {
    mode,
    nasMs,
    serverMs,
    nasIp: status.nasIp,
    serverUrl: status.serverUrl,
  }
})

ipcMain.handle('get-nas-config', async () => {
  return accessModeManager?.getNasConfig() || null
})

ipcMain.handle('get-connection-config', async () => {
  return accessModeManager?.getConnectionConfig() || null
})

// NAS Credentials Management
ipcMain.handle('get-nas-credentials', async () => {
  const config = ConfigStore.getInstance()
  const creds = config.get('nasCredentials')
  
  // Return username and whether credentials are set (never return the actual password)
  return {
    username: creds?.username || null,
    hasPassword: !!(creds?.password),
    useCredentials: creds?.useCredentials || false
  }
})

ipcMain.handle('save-nas-credentials', async (_event, username: string, password: string, useCredentials: boolean) => {
  const config = ConfigStore.getInstance()
  
  try {
    // Store credentials in OS-encrypted secure storage (DPAPI/Keychain)
    setSecureNasCreds(username || null, password || null)
    
    config.set('nasCredentials', {
      username: username || null,
      password: null, // No longer stored in plaintext config
      useCredentials
    })
    
    // Update AccessModeManager with new credentials
    if (accessModeManager && useCredentials && username && password) {
      // Pass credentials to NAS discovery for SMB auth
      accessModeManager.setNasCredentials(username, password)
    }
    
    // Update SyncEngine with new credentials
    if (syncEngine && useCredentials && username && password) {
      syncEngine.setNasCredentials({ username, password })
    } else if (syncEngine && !useCredentials) {
      syncEngine.setNasCredentials(null)
    }
    
    console.log('[NAS-CREDS] Credentials saved successfully')
    return { success: true }
  } catch (error: any) {
    console.error('[NAS-CREDS] Failed to save credentials:', error.message)
    return { success: false, error: error.message }
  }
})

ipcMain.handle('clear-nas-credentials', async () => {
  const config = ConfigStore.getInstance()
  
  // Clear from secure storage
  setSecureNasCreds(null, null)
  
  config.set('nasCredentials', {
    username: null,
    password: null,
    useCredentials: false
  })
  
  if (accessModeManager) {
    accessModeManager.clearNasCredentials()
  }
  
  if (syncEngine) {
    syncEngine.setNasCredentials(null)
  }
  
  console.log('[NAS-CREDS] Credentials cleared')
  return { success: true }
})

ipcMain.handle('test-nas-credentials', async (_event, username: string, password: string) => {
  // Test if the credentials work by trying to access the NAS
  const nasConfig = accessModeManager?.getNasConfig()
  if (!nasConfig?.ip) {
    return { success: false, error: 'NAS IP not configured' }
  }
  
  try {
    const fs = await import('fs')
    const nasPath = process.platform === 'win32' 
      ? `\\\\${nasConfig.ip}\\${nasConfig.smbShare}`
      : `/mnt/nas-test`
    
    // On Windows, we need to use net use to test with credentials
    if (process.platform === 'win32') {
      const { execFile } = await import('child_process')
      const util = await import('util')
      const execFilePromise = util.promisify(execFile)
      
      const nasTarget = `\\\\${nasConfig.ip}\\IPC$`
      
      // First, try to disconnect any existing connection
      try {
        await execFilePromise('net', ['use', nasTarget, '/delete', '/y'], { timeout: 5000 })
      } catch (e) {
        // Ignore - might not be connected
      }
      
      // Try to connect with credentials using execFile (safe from injection)
      await execFilePromise('net', ['use', nasTarget, `/user:${username}`, password], { timeout: 10000 })
      
      // Disconnect after test
      await execFilePromise('net', ['use', nasTarget, '/delete', '/y'], { timeout: 5000 })
      
      return { success: true, message: 'Credentials verified successfully' }
    } else {
      // For non-Windows, just return not implemented
      return { success: false, error: 'Credential test not implemented for this platform' }
    }
  } catch (error: any) {
    console.error('[NAS-CREDS] Test failed:', error.message)
    return { success: false, error: 'Invalid credentials or NAS unreachable' }
  }
})

// Time tracking stats
ipcMain.handle('get-time-tracking-stats', async () => {
  return documentTimeTracker?.getStats() || { activeCount: 0, pendingCount: 0, activeDocuments: [] }
})

// Stop tracking a specific document
ipcMain.handle('stop-tracking-document', async (_event, filename: string) => {
  console.log('[IPC] stop-tracking-document called for:', filename)
  const result = documentTimeTracker?.stopTrackingDocument(filename) || false
  
  // Also notify sync engine to clear editing status
  if (result && syncEngine) {
    syncEngine.clearSelfEditing(filename)
  }
  
  return result
})

// Get active tracked documents
ipcMain.handle('get-active-tracked-documents', async () => {
  return documentTimeTracker?.getActiveDocuments() || []
})

ipcMain.handle('get-active-tracked-websites', async () => {
  return documentTimeTracker?.getActiveWebsites() || []
})

// Get active editing sessions (from all detection sources)
ipcMain.handle('get-editing-sessions', async () => {
  return editingDetector?.getActiveSessions() || []
})

// DEBUG: Get time tracking debug data
ipcMain.handle('get-time-tracking-debug', async () => {
  return documentTimeTracker?.getDebugData() || {
    folderClientMapping: {},
    pendingEntries: [],
    activeDocuments: [],
    activeWebsites: [],
  }
})

// =========================================================================
// WATCH FOLDERS IPC
// =========================================================================
ipcMain.handle('get-watch-folders', async () => {
  return watchFolderService?.getAll() || []
})

ipcMain.handle('refresh-watch-folders', async () => {
  await watchFolderService?.refresh()
  return watchFolderService?.getAll() || []
})

// Pick a new local directory for a watch folder and save it to the server.
ipcMain.handle('change-watch-folder-path', async (_event, id: number) => {
  if (!watchFolderService) return { success: false, error: 'Service not ready' }
  const folder = watchFolderService.getById(id)

  const { dialog } = require('electron')
  const result = await dialog.showOpenDialog(mainWindow!, {
    properties: ['openDirectory'],
    title: folder ? `Select folder to watch for "${folder.name}"` : 'Select folder to watch',
    defaultPath: folder?.resolvedPath || undefined,
  })
  if (result.canceled || result.filePaths.length === 0) {
    return { success: false, canceled: true, folders: watchFolderService.getAll() }
  }

  const outcome = await watchFolderService.updateFolderPath(id, result.filePaths[0])
  return { ...outcome, folders: watchFolderService.getAll() }
})

ipcMain.handle('remove-watch-folder', async (_event, id: number) => {
  if (!watchFolderService) return { success: false, error: 'Service not ready' }
  const outcome = await watchFolderService.deleteFolder(id)
  return { ...outcome, folders: watchFolderService.getAll() }
})

// Open a watch folder's local directory in Explorer/Finder. Only paths the
// service actually knows about can be opened (no arbitrary path from renderer).
ipcMain.handle('open-watch-folder-locally', async (_event, id: number) => {
  const folder = watchFolderService?.getById(id)
  if (!folder?.resolvedPath) return { success: false, error: 'Folder not found' }
  const { shell } = require('electron')
  const err = await shell.openPath(folder.resolvedPath)
  return err ? { success: false, error: err } : { success: true }
})


// DEBUG: Get all synced folders with client info
ipcMain.handle('get-synced-folders-debug', async () => {
  const folders = database?.getAllFolders() || []
  return folders.map(f => ({
    remoteId: f.remoteId,
    name: f.name,
    localPath: f.localPath,
    syncStatus: f.syncStatus,
  }))
})

// DEBUG: Refresh folder-client mapping manually
ipcMain.handle('refresh-folder-mapping', async () => {
  await documentTimeTracker?.refreshFolderMapping()
  return documentTimeTracker?.getDebugData()?.folderClientMapping || {}
})

// DEBUG: Get URL mappings for website tracking
ipcMain.handle('get-url-mappings-debug', async () => {
  return browserMonitor?.getUrlMappings() || []
})

// DEBUG: Force refresh URL mappings from server
ipcMain.handle('refresh-url-mappings', async () => {
  if (syncEngine) {
    const mappings = await syncEngine.loadUrlMappings()
    if (browserMonitor) {
      browserMonitor.updateMappings(mappings)
    }
    return browserMonitor?.getUrlMappings() || []
  }
  return []
})

// Printer IPC handlers
ipcMain.handle('get-printers', async () => {
  return getPrinterService().getPrinters()
})

ipcMain.handle('scan-network-printers', async (event) => {
  return getPrinterService().scanNetwork((scanned, total) => {
    event.sender.send('network-scan-progress', { scanned, total })
  })
})

ipcMain.handle('get-all-printers', async (event) => {
  return getPrinterService().getAllPrinters((scanned, total) => {
    event.sender.send('network-scan-progress', { scanned, total })
  })
})

ipcMain.handle('print-file', async (_event, filePath: string, printerName: string, options?: any) => {
  return getPrinterService().printFile(filePath, printerName, options || {})
})

ipcMain.handle('print-html', async (_event, htmlContent: string, printerName: string, options?: any) => {
  return getPrinterService().printHtml(htmlContent, printerName, options || {})
})

ipcMain.handle('print-to-pdf', async (_event, htmlContent: string) => {
  return getPrinterService().printToPdf(htmlContent)
})

// Check for collaborator changes
ipcMain.handle('check-collaborator-changes', async () => {
  return syncEngine?.checkCollaboratorChanges() || []
})

ipcMain.handle('open-sync-folder', () => {
  const syncFolder = ConfigStore.getInstance().get('syncFolder')
  if (syncFolder) {
    require('electron').shell.openPath(syncFolder)
  }
})

// Allow the renderer to ask the OS to open a URL in the user's default browser.
// We only honour http(s) URLs to avoid being abused as a generic launcher.
ipcMain.handle('open-external-url', async (_event, url: string) => {
  if (typeof url !== 'string') return false
  if (!/^https?:\/\//i.test(url)) return false
  try {
    await require('electron').shell.openExternal(url)
    return true
  } catch (err: any) {
    console.error('[IPC] open-external-url failed:', err?.message)
    return false
  }
})

ipcMain.handle('select-sync-folder', async () => {
  const { dialog } = require('electron')
  const result = await dialog.showOpenDialog(mainWindow!, {
    properties: ['openDirectory'],
    title: 'Select Sync Folder',
  })
  
  if (!result.canceled && result.filePaths.length > 0) {
    const folder = result.filePaths[0]
    const config = ConfigStore.getInstance()
    config.set('syncFolder', folder)
    
    // Stop existing monitors
    editingDetector?.stop()
    fileWatcher?.stop()
    
    // Get time tracking config
    const timeTrackingConfig = config.get('timeTracking')
    
    // Reinitialize editing detector with new folder
    editingDetector = new EditingDetector(folder, {
      handlePollInterval: timeTrackingConfig.handlePollInterval,
      windowPollInterval: timeTrackingConfig.windowPollInterval,
      inactivityTimeout: timeTrackingConfig.inactivityTimeout,
      enableHandleMonitor: timeTrackingConfig.handleMonitorEnabled,
      enableWindowMonitor: timeTrackingConfig.windowMonitorEnabled,
    })
    
    if (documentTimeTracker) {
      editingDetector.setTimeTracker(documentTimeTracker)
    }
    if (syncEngine) {
      editingDetector.setSyncEngine(syncEngine)
    }
    
    // Restart file watcher with new folder
    fileWatcher = new FileWatcher(folder, syncEngine!, documentTimeTracker, editingDetector)
    fileWatcher.start()
    
    // Start editing detector
    if (timeTrackingConfig.enabled) {
      editingDetector.start()
    }
    
    return folder
  }
  return null
})

ipcMain.handle('login', async (_event, apiUrl: string, email: string, password: string) => {
  const config = ConfigStore.getInstance()
  
  try {
    const axios = require('axios')
    
    // Include device token if we have one (for trusted device recognition)
    const deviceToken = getDeviceToken()
    const headers: Record<string, string> = {}
    if (deviceToken) {
      headers['X-Device-Token'] = deviceToken
    }
    
    const response = await axios.post(`${apiUrl}/api/auth/login`, {
      email,
      password,
      device_token: deviceToken || undefined,
    }, { headers })
    
    // Check if 2FA is required
    if (response.data.success && response.data.data?.requires_2fa) {
      return { 
        success: false, 
        requires2FA: true, 
        tempToken: response.data.data.temp_token 
      }
    }
    
    // Normal login with token (2FA not enabled or skipped due to trusted device)
    if (response.data.success && response.data.data?.access_token) {
      config.set('apiUrl', apiUrl)
      config.set('userEmail', email)
      
      // Store sensitive tokens in OS-encrypted secure storage (not plaintext config)
      setAuthToken(response.data.data.access_token)
      
      // Store session token for session tracking
      if (response.data.data.session_token) {
        setSessionToken(response.data.data.session_token)
      }
      
      // Store device token if returned (trusted device)
      if (response.data.data.device_token) {
        setDeviceToken(response.data.data.device_token)
      }
      
      // Initialize sync engine with new credentials
      const sessionToken = getSessionToken()
      syncEngine?.setCredentials(apiUrl, response.data.data.access_token, sessionToken)
      syncEngine?.startSync()
      
      // Start time tracking
      documentTimeTracker?.setCredentials(apiUrl, response.data.data.access_token)
      documentTimeTracker?.start()
      
      // Register device and start security polling
      registerDevice(apiUrl, response.data.data.access_token, sessionToken).catch(() => {})
      if (mainWindow) {
        setSecurityWindow(mainWindow)
        startStatusPolling(mainWindow)
      }
      
      // Initialize AccessModeManager for NAS direct access (if not already done)
      if (!accessModeManager) {
        console.log('[LOGIN] Creating AccessModeManager after login')
        accessModeManager = createAccessModeManager(config)
      }
      accessModeManager.setCredentials(apiUrl, response.data.data.access_token)
      accessModeManager.initialize().then(() => {
        console.log('[LOGIN] AccessModeManager initialized:', accessModeManager?.getStatus())
        const nasConfig = accessModeManager?.getNasConfig()
        if (nasConfig && syncEngine) {
          syncEngine.setAccessMode(accessModeManager!.getCurrentMode())
          syncEngine.setNasConfig({
            host: nasConfig.ip,
            port: 445,
            basePath: nasConfig.smbShare || nasConfig.nfsPath,
            userFolder: nasConfig.userFolder
          })
        }
        markAccessModeInitialized()
        mainWindow?.webContents.send('access-mode-ready', accessModeManager?.getStatus())
      }).catch(err => {
        console.error('[LOGIN] AccessModeManager init error:', err.message)
        markAccessModeInitialized()
        mainWindow?.webContents.send('access-mode-ready', accessModeManager?.getStatus())
      })
      
      startSharedAuthWatcher()
      afterDriveLogin(response.data.data).catch(() => {})
      return { success: true }
    }
    
    // Legacy token format
    if (response.data.success && response.data.data?.token) {
      config.set('apiUrl', apiUrl)
      config.set('userEmail', email)
      setAuthToken(response.data.data.token)
      
      syncEngine?.setCredentials(apiUrl, response.data.data.token)
      syncEngine?.startSync()
      startSharedAuthWatcher()
      
      return { success: true }
    }
    
    return { success: false, error: response.data.message || 'Login failed' }
  } catch (error: any) {
    return { success: false, error: error.response?.data?.message || error.message }
  }
})

ipcMain.handle('verify-2fa', async (_event, apiUrl: string, email: string, code: string, tempToken: string, trustDevice: boolean = false) => {
  const config = ConfigStore.getInstance()
  
  try {
    const axios = require('axios')
    const response = await axios.post(`${apiUrl}/api/2fa/login`, {
      email,
      code,
      temp_token: tempToken,
      trust_device: trustDevice,  // Request to trust this device for 7 days
    })
    
    if (response.data.success && response.data.data?.access_token) {
      config.set('apiUrl', apiUrl)
      config.set('userEmail', email)
      
      // Store sensitive tokens in OS-encrypted secure storage
      setAuthToken(response.data.data.access_token)
      
      // Store session token for session tracking
      if (response.data.data.session_token) {
        setSessionToken(response.data.data.session_token)
      }
      
      // Store device token if returned (trusted device)
      if (response.data.data.device_token) {
        setDeviceToken(response.data.data.device_token)
      }
      
      // Initialize sync engine with new credentials
      const sessionToken = getSessionToken()
      syncEngine?.setCredentials(apiUrl, response.data.data.access_token, sessionToken)
      syncEngine?.startSync()
      
      // Start time tracking
      documentTimeTracker?.setCredentials(apiUrl, response.data.data.access_token)
      documentTimeTracker?.start()
      
      // Register device and start security polling
      registerDevice(apiUrl, response.data.data.access_token, sessionToken).catch(() => {})
      if (mainWindow) {
        setSecurityWindow(mainWindow)
        startStatusPolling(mainWindow)
      }
      
      // Initialize AccessModeManager for NAS direct access (if not already done)
      if (!accessModeManager) {
        console.log('[2FA] Creating AccessModeManager after 2FA login')
        accessModeManager = createAccessModeManager(config)
      }
      accessModeManager.setCredentials(apiUrl, response.data.data.access_token)
      accessModeManager.initialize().catch(err => {
        console.error('[2FA] AccessModeManager init error:', err.message)
      })
      
      return { success: true }
    }
    
    return { success: false, error: response.data.message || 'Verification failed' }
  } catch (error: any) {
    return { success: false, error: error.response?.data?.message || error.message }
  }
})

ipcMain.handle('logout', () => {
  sharedAuthWatcher.stop()
  const currentSeed = readSharedAuth()
  ignoredSeedId = currentSeed?.seedId || null
  const config = ConfigStore.getInstance()
  setAuthToken(null)
  setSessionToken(null)
  config.set('userEmail', null)
  stopStatusPolling()
  syncEngine?.stopSync()
  documentTimeTracker?.stop()
  watchFolderService?.stop()
  startSharedAuthWatcher()
  return true
})

/**
 * Handle auth failure - clear credentials and notify renderer to show login
 */
function handleAuthFailure(): void {
  console.log('[MAIN] Handling auth failure - clearing credentials and showing login')
  
  const config = ConfigStore.getInstance()
  // Clear sensitive tokens from secure storage
  setAuthToken(null)
  setSessionToken(null)
  // Keep userEmail so user knows which account they were logged into
  // Keep deviceToken for trusted device feature
  
  // Stop all background operations
  syncEngine?.stopSync()
  documentTimeTracker?.stop()
  editingDetector?.stop()
  browserMonitor?.stop()
  watchFolderService?.stop()
  
  // Show notification
  notificationManager?.show('Session Expired', 'Your login session has expired. Please log in again.')
  
  // Bring window to front and send auth-failed to renderer
  if (mainWindow) {
    mainWindow.show()
    mainWindow.focus()
    mainWindow.webContents.send('auth-failed')
  }
}

ipcMain.handle('window-minimize', () => {
  mainWindow?.minimize()
})

// Keep the native window chrome (and pre-paint background) in sync with the
// renderer's theme. Accepts 'light' | 'dark' | 'system' so OS-following still
// works; the background is derived from the effective resolved color.
ipcMain.on('set-native-theme', (_event, mode: string) => {
  if (mode === 'light' || mode === 'dark' || mode === 'system') {
    nativeTheme.themeSource = mode
  }
  mainWindow?.setBackgroundColor(nativeTheme.shouldUseDarkColors ? '#0f172a' : '#ffffff')
})

ipcMain.handle('window-maximize', () => {
  if (mainWindow?.isMaximized()) {
    mainWindow.unmaximize()
  } else {
    mainWindow?.maximize()
  }
})

ipcMain.handle('window-close', () => {
  mainWindow?.hide()
})

// ─── SSO + OAuth IPC ───
ipcMain.handle('oauth-start', async (_event, provider: string) => {
  const apiUrl = resolveApiUrl()
  const authUrlEndpoint = provider === 'microsoft'
    ? `${apiUrl}/api/auth/microsoft/login`
    : `${apiUrl}/api/auth/google/login`
  try {
    const resp = await fetch(authUrlEndpoint)
    const data: any = await resp.json()
    const authUrl = data.data?.url || data.url
    if (!authUrl) throw new Error('No auth URL returned')

    const callbackHost = new URL(apiUrl).host
    const result = await openOAuthWindow(authUrl, callbackHost, provider)
    await afterDriveLogin(result.tokens)
    return { success: true }
  } catch (e: any) {
    return { success: false, error: e.message }
  }
})

// ===== Device authorization ("scan to sign in") =====
// The renderer shows a QR + a 2-digit match number; an already-signed-in web
// session approves it. We keep the poll_secret in the main process only and
// poll the backend until the request is approved, then redeem the one-time
// code through the existing exchange path (afterDriveLogin emits
// 'sso-authenticated', which the login view already listens for).
let deviceLoginAbort = false

async function deviceLoginPollLoop(
  requestId: string,
  pollSecret: string,
  apiUrl: string,
  expiresIn: number
): Promise<void> {
  const intervalMs = 2000
  const deadline = Date.now() + (Math.max(60, expiresIn || 120) + 10) * 1000

  while (!deviceLoginAbort) {
    await new Promise((r) => setTimeout(r, intervalMs))
    if (deviceLoginAbort) return
    if (Date.now() > deadline) {
      mainWindow?.webContents.send('sso-device-status', { status: 'expired' })
      return
    }

    try {
      const resp = await fetch(`${apiUrl}/api/sso/device/poll`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ request_id: requestId, poll_secret: pollSecret }),
      })
      const result: any = await resp.json()
      const data = result?.data || {}
      const status = data.status

      if (status === 'approved' && data.code) {
        const ex = await fetch(`${apiUrl}/api/sso/exchange`, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ code: data.code, nonce: '' }),
        })
        const exResult: any = await ex.json()
        if (exResult.success && exResult.data) {
          await afterDriveLogin(exResult.data)
          mainWindow?.webContents.send('sso-device-status', { status: 'approved' })
        } else {
          mainWindow?.webContents.send('sso-device-status', {
            status: 'error',
            error: exResult.error || exResult.message || 'Exchange failed',
          })
        }
        return
      }

      if (status === 'denied') {
        mainWindow?.webContents.send('sso-device-status', { status: 'denied' })
        return
      }
      if (status === 'expired') {
        mainWindow?.webContents.send('sso-device-status', { status: 'expired' })
        return
      }
      // pending / consumed -> keep polling
    } catch (e) {
      // transient network error -> keep polling until the deadline
    }
  }
}

ipcMain.handle('sso-device-start', async (_event, email?: string) => {
  const apiUrl = resolveApiUrl()
  if (!apiUrl) return { success: false, error: 'NO_SERVER' }
  try {
    deviceLoginAbort = false
    const label = `FlowOne Drive — ${os.hostname()} (${process.platform})`
    // Pass the target email (like the iOS app) so the account's already-signed-in
    // browser sessions DISCOVER this request via /sso/device/pending and pop the
    // approval modal automatically — no QR scan needed. Falls back to the
    // anonymous QR flow when no email is supplied.
    const target = String(email || '').trim().toLowerCase()
    const body: Record<string, string> = { device_label: label }
    if (target) body.email = target
    const resp = await fetch(`${apiUrl}/api/sso/device/start`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(body),
    })
    const result: any = await resp.json()
    if (!result.success || !result.data) {
      return { success: false, error: result.error || result.message || 'START_FAILED' }
    }
    const { request_id, poll_secret, match_number, verify_url, expires_in } = result.data
    void deviceLoginPollLoop(request_id, poll_secret, apiUrl, expires_in)
    return {
      success: true,
      matchNumber: match_number,
      verifyUrl: verify_url,
      expiresIn: expires_in,
    }
  } catch (e: any) {
    return { success: false, error: e.message }
  }
})

ipcMain.handle('sso-device-cancel', async () => {
  deviceLoginAbort = true
  return true
})

ipcMain.handle('sso-logout', async () => {
  deviceLoginAbort = true
  sharedAuthWatcher.stop()
  clearSharedAuth()
  tokenRefreshHandle?.stop()
  tokenRefreshHandle = null
  setAuthToken(null)
  setSessionToken(null)
  ConfigStore.getInstance().set('userEmail', '')
  ConfigStore.getInstance().set('refreshToken', null)
  stopStatusPolling()
  syncEngine?.stopSync()
  mainWindow?.webContents.send('auth-failed')
  ignoredSeedId = null
  startSharedAuthWatcher()
  return true
})
