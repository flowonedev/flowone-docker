import { app, BrowserWindow, ipcMain, shell, Tray, Menu, nativeImage, NativeImage, Notification, protocol, net, screen, desktopCapturer, nativeTheme } from 'electron'
import path from 'path'
import http from 'http'
import https from 'https'
import fs from 'fs'
import { LocalDatabase } from './database/Database'
import { configStore } from './config'
import { getSyncManager, shutdownSyncManager } from './sync'
import { registerDevice, startStatusPolling, stopStatusPolling, setMainWindow as setSecurityWindow } from './deviceSecurity'
import { getOrCreateDeviceId } from './deviceId'
import { initBiometricAuth, registerBiometricIpcHandlers, cleanupBiometricAuth } from './biometricAuth'
import { getAuthToken, setAuthToken, getSessionToken, setSessionToken } from './secureStorage'
import { readSharedAuth, writeSharedAuth, clearSharedAuth, SharedAuthWatcher, type SharedAuthData } from './sso/sharedAuth'
import { openOAuthWindow } from './sso/oauthWindow'
import { startTokenRefreshTimer, stopTokenRefreshTimer } from './sso/tokenRefresh'

if (process.platform === 'win32') {
  app.setAppUserModelId('com.flowone.email')
}

// Register as handler for flowone:// protocol
if (process.defaultApp) {
  if (process.argv.length >= 2) {
    app.setAsDefaultProtocolClient('flowone', process.execPath, [path.resolve(process.argv[1])])
  }
} else {
  app.setAsDefaultProtocolClient('flowone')
}

app.commandLine.appendSwitch('use-angle', 'd3d11')
app.commandLine.appendSwitch('ignore-gpu-blocklist')
app.commandLine.appendSwitch('disable-gpu-sandbox')

// Use a simpler path without spaces for userData to avoid cache issues
const os = require('os')
const userDataPath = path.join(os.homedir(), '.mailflow-desktop')
app.setPath('userData', userDataPath)

// Clear GPU cache on startup to prevent access issues
const gpuCachePath = path.join(userDataPath, 'GPUCache')
try {
  if (fs.existsSync(gpuCachePath)) {
    fs.rmSync(gpuCachePath, { recursive: true, force: true })
    console.log('[Main] Cleared GPU cache')
  }
} catch (e) {
  console.warn('[Main] Could not clear GPU cache:', e)
}

console.log('[Main] User data path:', userDataPath)

// Register custom protocol for local files (fixes Windows path issues)
protocol.registerSchemesAsPrivileged([{
  scheme: 'app',
  privileges: {
    standard: true,
    secure: true,
    supportFetchAPI: true,
    bypassCSP: true,
  }
}])

// Global references
let mainWindow: BrowserWindow | null = null
let tray: Tray | null = null
let db: LocalDatabase | null = null

// Prevent multiple instances
const gotTheLock = app.requestSingleInstanceLock()
if (!gotTheLock) {
  app.quit()
}

// Store proxy server port globally so API requests can use it
let proxyServerPort = 0

// Register sync-get-status early so renderer can query it before SyncManager initializes
ipcMain.handle('sync-get-status', () => {
  try {
    const sm = getSyncManager()
    if (sm.isInitialized) {
      return { initialized: true, ...sm.getStatus() }
    }
  } catch (_) { /* SyncManager not ready */ }
  return { initialized: false, isOnline: false, isVerifiedOnline: false, wsConnected: false, pendingCount: 0, lastEventVersion: 0 }
})

/**
 * Create the main application window
 */
async function createWindow(): Promise<void> {
  const bounds = configStore.get('windowBounds')
  const maximized = configStore.get('windowMaximized')
  const rendererPath = path.join(__dirname, '..', 'renderer')

  // Start HTTP proxy server for API requests only
  const API_HOST = 'flowone.pro'

  const CORS_HEADERS: Record<string, string> = {
    'Access-Control-Allow-Origin': '*',
    'Access-Control-Allow-Methods': 'GET, POST, PUT, DELETE, OPTIONS, PATCH',
    'Access-Control-Allow-Headers': 'Content-Type, Authorization, X-Session-Token, X-Account-Id, X-Device-Id',
    'Access-Control-Max-Age': '86400',
  }

  const proxyServer = http.createServer((req: any, res: any) => {
    // Handle CORS preflight locally — never forward OPTIONS to backend
    if (req.method === 'OPTIONS') {
      res.writeHead(204, CORS_HEADERS)
      res.end()
      return
    }

    const proxyHeaders = { ...req.headers, host: API_HOST }

    // Remove hop-by-hop and problematic headers
    delete proxyHeaders['connection']
    delete proxyHeaders['keep-alive']
    delete proxyHeaders['origin']
    delete proxyHeaders['referer']
    // Don't request compressed responses — we can't decompress in the proxy pipe
    delete proxyHeaders['accept-encoding']

    const proxyOptions = {
      hostname: API_HOST,
      port: 443,
      path: req.url,
      method: req.method,
      headers: proxyHeaders,
    }

    // Only log non-GET requests to reduce console noise
    if (req.method !== 'GET') {
      console.log('[Proxy]', req.method, req.url)
    }

    const proxyReq = https.request(proxyOptions, (proxyRes: any) => {
      // Build clean response headers — take backend headers but override CORS
      const responseHeaders = { ...proxyRes.headers }
      // Remove backend CORS headers (we set our own)
      delete responseHeaders['access-control-allow-origin']
      delete responseHeaders['access-control-allow-methods']
      delete responseHeaders['access-control-allow-headers']
      delete responseHeaders['access-control-allow-credentials']
      delete responseHeaders['access-control-max-age']
      // Add our CORS headers
      Object.assign(responseHeaders, CORS_HEADERS)

      // Log non-200 responses for debugging
      if (proxyRes.statusCode !== 200) {
        console.log('[Proxy]', req.method, req.url, '->', proxyRes.statusCode)
      }
      // For 401 responses, log the body to see the reason (no_password, expired, etc.)
      if (proxyRes.statusCode === 401) {
        let body = ''
        proxyRes.on('data', (chunk: any) => { body += chunk.toString() })
        proxyRes.on('end', () => {
          console.log('[Proxy] 401 response body:', body.substring(0, 500))
          res.writeHead(proxyRes.statusCode, responseHeaders)
          res.end(body)
        })
        return
      }
      // For other auth error responses, also log
      if (req.url.includes('/auth/') && proxyRes.statusCode >= 400) {
        let body = ''
        proxyRes.on('data', (chunk: any) => { body += chunk.toString() })
        proxyRes.on('end', () => {
          console.log('[Proxy] Auth error response:', proxyRes.statusCode, body.substring(0, 500))
          res.writeHead(proxyRes.statusCode, responseHeaders)
          res.end(body)
        })
        return
      }

      res.writeHead(proxyRes.statusCode, responseHeaders)
      proxyRes.pipe(res)
    })

    proxyReq.on('error', (e: any) => {
      console.error('[Proxy] Error:', e.message)
      res.writeHead(502, CORS_HEADERS)
      res.end(JSON.stringify({ success: false, message: 'Proxy Error: ' + e.message }))
    })

    req.pipe(proxyReq)
  })

  // Start proxy server
  await new Promise<void>((resolve) => {
    proxyServer.listen(0, '127.0.0.1', () => {
      const addr = proxyServer.address()
      proxyServerPort = (addr as any).port
      console.log('[Proxy] API proxy listening on http://127.0.0.1:' + proxyServerPort)
      resolve()
    })
  })

  console.log('[Main] Creating BrowserWindow...')

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
    width: bounds?.width || 1200,
    height: bounds?.height || 800,
    minWidth: 400,
    minHeight: 300,
    x: bounds?.x,
    y: bounds?.y,
    show: false,
    frame: isMac,
    titleBarStyle: isMac ? 'hiddenInset' : undefined,
    trafficLightPosition: isMac ? { x: 12, y: 10 } : undefined,
    backgroundColor: '#0f172a',
    icon: appIcon,
    webPreferences: {
      preload: path.join(__dirname, '..', 'preload', 'index.js'),
      contextIsolation: true,
      nodeIntegration: false,
      sandbox: false,
    },
  })

  console.log('[Main] Window created, ID:', mainWindow.id)

  // Save window bounds on resize/move
  mainWindow.on('resize', saveWindowBounds)
  mainWindow.on('move', saveWindowBounds)

  // Frameless windows on Windows extend behind the taskbar when maximized.
  // The window overflows past the work area on all sides. We calculate the
  // overflow and tell the renderer to add CSS padding to compensate.
  function sendMaximizePadding(maximized: boolean) {
    if (!mainWindow) return
    if (process.platform === 'win32' && maximized) {
      const bounds = mainWindow.getBounds()
      const { workArea } = screen.getDisplayMatching(bounds)
      const overflowTop = Math.max(0, workArea.y - bounds.y)
      const overflowBottom = Math.max(0, (bounds.y + bounds.height) - (workArea.y + workArea.height))
      const overflowLeft = Math.max(0, workArea.x - bounds.x)
      const overflowRight = Math.max(0, (bounds.x + bounds.width) - (workArea.x + workArea.width))
      mainWindow.webContents.send('maximize-padding', { top: overflowTop, right: overflowRight, bottom: overflowBottom, left: overflowLeft })
    } else {
      mainWindow.webContents.send('maximize-padding', { top: 0, right: 0, bottom: 0, left: 0 })
    }
  }

  mainWindow.on('maximize', () => {
    configStore.set('windowMaximized', true)
    sendMaximizePadding(true)
  })
  mainWindow.on('unmaximize', () => {
    configStore.set('windowMaximized', false)
    sendMaximizePadding(false)
  })

  // Show window when ready — with timeout fallback
  let windowShown = false
  const showWindow = () => {
    if (windowShown || !mainWindow) return
    windowShown = true
    mainWindow.show()
    if (maximized) {
      mainWindow.maximize()
    }
    mainWindow.focus()
    console.log('[Main] Window shown')
  }

  mainWindow.once('ready-to-show', () => {
    console.log('[Main] Window ready to show')
    if (!configStore.get('startMinimized')) {
      showWindow()
    }
  })

  // Fallback: if ready-to-show doesn't fire within 5s, show anyway
  setTimeout(() => {
    if (!windowShown && !configStore.get('startMinimized')) {
      console.warn('[Main] ready-to-show timeout — forcing window show')
      showWindow()
    }
  }, 5000)

  // Debug: Log any errors during page load
  mainWindow.webContents.on('did-fail-load', (event, errorCode, errorDescription, validatedURL) => {
    console.error('[Main] Page failed to load:', { errorCode, errorDescription, validatedURL })
  })

  mainWindow.webContents.on('render-process-gone', (event, details) => {
    console.error('[Main] Render process gone:', details)
  })

  // Handle window close
  mainWindow.on('close', (event) => {
    if (configStore.get('minimizeToTray') && !isQuitting) {
      event.preventDefault()
      mainWindow?.hide()
    }
  })

  mainWindow.on('closed', () => {
    mainWindow = null
  })

  mainWindow.on('focus', () => {
    mainWindow?.flashFrame(false)
  })

  // Handle getDisplayMedia() requests from the renderer (LiveKit screen sharing).
  // Electron doesn't show the system picker by default; we enumerate sources
  // via desktopCapturer and provide the first full-screen source.
  mainWindow.webContents.session.setDisplayMediaRequestHandler(async (_request, callback) => {
    try {
      const sources = await desktopCapturer.getSources({ types: ['screen', 'window'] })
      if (sources.length > 0) {
        callback({ video: sources[0], audio: 'loopback' })
      } else {
        callback({})
      }
    } catch (err) {
      console.error('[Main] Screen share source enumeration failed:', err)
      callback({})
    }
  })

  // Set up CSP that allows our proxy
  // 'unsafe-eval' is required even in production because vue-i18n compiles
  // messages at runtime using new Function(). contextIsolation + no
  // nodeIntegration keeps the renderer sandbox intact.
  mainWindow.webContents.session.webRequest.onHeadersReceived((details, callback) => {
    callback({
      responseHeaders: {
        ...details.responseHeaders,
        'Content-Security-Policy': [`default-src 'self' 'unsafe-inline' 'unsafe-eval' data: blob: https: http://127.0.0.1:${proxyServerPort} ws: wss:`]
      }
    })
  })

  // Register custom protocol for API asset proxying (avatars, mood board images, etc.)
  // Intercepts file:// requests that contain /api/ paths (e.g. file:///D:/api/colleagues/avatar/...)
  // These occur when <img src="/api/..."> resolves against the file:// origin in Electron.
  // We use a broad filter to catch both file://*/api/* AND file://*/C:/api/* patterns.
  mainWindow.webContents.session.webRequest.onBeforeRequest(
    { urls: ['file://*'] },
    (details, callback) => {
      // Only process URLs that contain /api/ somewhere in the path
      if (!details.url.includes('/api/')) {
        callback({})
        return
      }
      try {
        const url = new URL(details.url)
        const pathname = decodeURIComponent(url.pathname)
        let apiPath: string | null = null

        // Direct /api/ path
        if (pathname.startsWith('/api/')) {
          apiPath = pathname
        } else {
          // Windows drive-letter prefix: /D:/api/... or /C:/api/...
          const driveMatch = pathname.match(/^\/[A-Za-z]:(.\/api\/.*)$/)
          if (driveMatch) {
            apiPath = driveMatch[1]
          }
          // Also handle encoded or nested paths containing /api/
          if (!apiPath) {
            const apiIdx = pathname.indexOf('/api/')
            if (apiIdx !== -1) {
              apiPath = pathname.substring(apiIdx)
            }
          }
        }
        if (apiPath) {
          const redirectUrl = `http://127.0.0.1:${proxyServerPort}${apiPath}${url.search || ''}`
          console.log('[WebRequest] Redirecting API asset:', apiPath)
          callback({ redirectURL: redirectUrl })
          return
        }
      } catch (e) {
        // ignore parse errors
      }
      callback({})
    }
  )

  // Inject auth headers for requests redirected to the local proxy (e.g. <img src="/api/...">)
  // These redirects lose their original headers, so we add auth from secure storage.
  mainWindow.webContents.session.webRequest.onBeforeSendHeaders(
    { urls: [`http://127.0.0.1:*/*`] },
    (details, callback) => {
      const headers = { ...details.requestHeaders }
      // Only inject if headers are missing (don't overwrite fetch() calls that already set them)
      if (!headers['Authorization'] && !headers['authorization']) {
        const token = getAuthToken()
        if (token) {
          headers['Authorization'] = `Bearer ${token}`
        }
      }
      if (!headers['X-Session-Token'] && !headers['x-session-token']) {
        const sessionToken = getSessionToken()
        if (sessionToken) {
          headers['X-Session-Token'] = sessionToken
        }
      }
      callback({ requestHeaders: headers })
    }
  )

  // Load the file directly
  const indexPath = path.join(rendererPath, 'index.html')
  console.log('[Main] Loading:', indexPath)

  try {
    await mainWindow.loadFile(indexPath)
    console.log('[Main] App loaded successfully!')
    // Pipe renderer console messages to main process stdout for debugging
    mainWindow.webContents.on('console-message', (_event: any, level: number, message: string) => {
      // Show all messages level >= 1 (INFO and above)
      if (level >= 1) {
        const levelStr = ['VERBOSE', 'INFO', 'WARN', 'ERROR'][level] || 'LOG'
        console.log(`[Renderer/${levelStr}] ${message}`)
      }
    })
    // DevTools can be opened manually with F12 / Ctrl+Shift+I

    // Initialize sync after loadFile resolves (did-finish-load already fired)
    const isLoggedIn = getAuthToken()
    if (isLoggedIn) {
      console.log('[Main] User already logged in, initializing sync...')
      initializeSyncAndAutoSync().catch(err => {
        console.error('[Main] Post-load sync init failed:', err)
      })
    } else {
      console.log('[Main] No auth token yet (user needs to log in), sync deferred')
    }
  } catch (err) {
    console.error('[Main] App loading failed:', err)
  }

  // Handle external links - prevent new windows, open in system browser
  mainWindow.webContents.setWindowOpenHandler(({ url }) => {
    shell.openExternal(url)
    return { action: 'deny' }
  })

  // Prevent the main window from navigating away (e.g. clicking links in email body)
  mainWindow.webContents.on('will-navigate', (event, url) => {
    // Allow file:// protocol for loading the app itself
    if (url.startsWith('file://')) return

    // Block all other navigation - open external URLs in system browser instead
    event.preventDefault()
    console.log('[Main] Blocked navigation to:', url)
    shell.openExternal(url)
  })
}

/**
 * Initialize the sync manager and run auto-sync for all engines.
 * Called after login or on app start if already logged in.
 */
async function initializeSyncAndAutoSync(): Promise<void> {
  if (!mainWindow) {
    console.error('[Main] Cannot init sync: no mainWindow')
    return
  }

  try {
    // Start device security polling
    setSecurityWindow(mainWindow)
    startStatusPolling(mainWindow)

    // Initialize biometric/PIN lock
    initBiometricAuth(mainWindow)

    // Register device on startup (in case it was never registered)
    const apiUrl = configStore.get('apiUrl') || 'https://flowone.pro'
    const authToken = getAuthToken()
    if (authToken) {
      registerDevice(apiUrl, authToken, getSessionToken()).catch(() => { })
    }

    const syncManager = getSyncManager()
    if (!syncManager.isInitialized) {
      await syncManager.initialize(mainWindow)
      console.log('[Main] SyncManager initialized successfully')
    } else {
      console.log('[Main] SyncManager already initialized, skipping')
    }

    // Auto-sync in background (don't block UI)
    // Lightweight engines first, heavy email engine last, with rate-limit-friendly delays
    console.log('[Main] Starting automatic background sync...')
    setTimeout(async () => {
      const delay = (ms: number) => new Promise(resolve => setTimeout(resolve, ms))
      const PAUSE = 3000

      try {
        // Lightweight engines first (each makes 1-3 API calls)
        const lightEngines: [string, any][] = [
          ['Colleagues', syncManager.getColleagueEngine()],
          ['Todos', syncManager.getTodoEngine()],
          ['Devices', syncManager.getDeviceEngine()],
          ['Templates', syncManager.getTemplateEngine()],
          ['Mailing Lists', syncManager.getMailingListEngine()],
          ['MoodBoards', syncManager.getMoodBoardEngine()],
          ['Clients', syncManager.getClientEngine()],
          ['Chats', syncManager.getChatEngine()],
          ['Campaigns', syncManager.getCampaignEngine()],
          ['Portal', syncManager.getPortalEngine()],
          ['Boards', syncManager.getBoardsEngine()],
          ['Calendars', syncManager.getCalendarEngine()],
        ]

        for (const [name, engine] of lightEngines) {
          if (engine) {
            try {
              engine.setOnline(true)
              await engine.sync()
              console.log(`[Main] Auto-sync: ${name} synced`)
            } catch (err: any) {
              console.error(`[Main] Auto-sync: ${name} failed:`, err.message)
            }
            await delay(PAUSE)
          }
        }

        // CRM has many sub-syncs (deals, invoices, tags, etc.) - give it extra room
        const crmEngine = syncManager.getCrmEngine()
        if (crmEngine) {
          try {
            crmEngine.setOnline(true)
            await crmEngine.sync()
            console.log('[Main] Auto-sync: CRM synced')
          } catch (err: any) {
            console.error('[Main] Auto-sync: CRM failed:', err.message)
          }
          await delay(PAUSE * 2)
        }

        // Settings (single API call)
        try {
          const settingsApiUrl = configStore.get('apiUrl') || 'https://flowone.pro'
          const authToken = getAuthToken()
          const sessionToken = getSessionToken()
          if (authToken) {
            const resp = await net.fetch(`${settingsApiUrl}/api/settings`, {
              headers: {
                'Authorization': `Bearer ${authToken}`,
                ...(sessionToken ? { 'X-Session-Token': sessionToken } : {})
              }
            })
            const json = await resp.json() as { success?: boolean; data?: Record<string, any> }
            if (json.success && json.data) {
              const db = LocalDatabase.getInstance()
              for (const [key, value] of Object.entries(json.data)) {
                const strValue = typeof value === 'string' ? value : JSON.stringify(value)
                db.prepare(`
                  INSERT INTO settings (key, value, updated_at)
                  VALUES (?, ?, datetime('now'))
                  ON CONFLICT(key) DO UPDATE SET value = excluded.value, updated_at = datetime('now')
                `).run(key, strValue)
              }
              console.log(`[Main] Auto-sync: Settings synced (${Object.keys(json.data).length} keys)`)
            }
          }
        } catch (settingsErr: any) {
          console.error('[Main] Auto-sync: Settings failed:', settingsErr.message)
        }

        await delay(PAUSE)

        // Email engine LAST (heaviest: 78+ folders, body prefetch)
        const emailEngine = syncManager.getEmailEngine()
        if (emailEngine) {
          try {
            emailEngine.setOnline(true)
            await emailEngine.sync()
            console.log('[Main] Auto-sync: Emails synced')
          } catch (err: any) {
            console.error('[Main] Auto-sync: Emails failed:', err.message)
          }
        }

        // Notify renderer that sync is complete
        mainWindow?.webContents.send('sync-complete', { type: 'auto' })
        console.log('[Main] Automatic background sync complete!')
      } catch (e: any) {
        console.error('[Main] Auto-sync failed:', e.message)
      }
    }, 8000)
  } catch (e: any) {
    console.error('[Main] initializeSyncAndAutoSync failed:', e.message, e.stack)
  }
}

// Expose proxy port to renderer via IPC
ipcMain.handle('get-proxy-port', () => proxyServerPort)

/**
 * Save window bounds to config (debounced to avoid disk thrashing during drag/resize)
 */
let saveWindowBoundsTimer: NodeJS.Timeout | null = null
function saveWindowBounds(): void {
  if (!mainWindow || mainWindow.isMaximized()) return
  if (saveWindowBoundsTimer) clearTimeout(saveWindowBoundsTimer)
  saveWindowBoundsTimer = setTimeout(() => {
    if (!mainWindow || mainWindow.isMaximized()) return
    const bounds = mainWindow.getBounds()
    configStore.set('windowBounds', bounds)
  }, 500)
}

// ─── Tray icon pulsing (alert on unread) ───
let trayIconNormal: NativeImage | null = null
let trayIconAlert: NativeImage | null = null
let trayPulseTimer: NodeJS.Timeout | null = null
let trayPulseVisible = true

function loadTrayIcon(assetsDir: string, baseName: string, isTemplate: boolean): NativeImage {
  let icon = nativeImage.createEmpty()
  if (process.platform === 'darwin') {
    const p1x = path.join(assetsDir, `${baseName}.png`)
    const p2x = path.join(assetsDir, `${baseName}@2x.png`)
    try {
      const img = nativeImage.createFromPath(p1x)
      if (fs.existsSync(p2x)) {
        const img2x = nativeImage.createFromPath(p2x)
        if (!img2x.isEmpty()) img.addRepresentation({ scaleFactor: 2.0, buffer: img2x.toPNG() })
      }
      if (!img.isEmpty() && isTemplate) img.setTemplateImage(true)
      if (!img.isEmpty()) icon = img
    } catch (_) {}
  } else {
    const icoPath = path.join(assetsDir, `${baseName}.ico`)
    const pngPath = path.join(assetsDir, `${baseName}.png`)
    const tryPath = fs.existsSync(icoPath) ? icoPath : pngPath
    try {
      const loaded = nativeImage.createFromPath(tryPath)
      if (!loaded.isEmpty()) icon = loaded
    } catch (_) {}
  }
  return icon
}

function startTrayPulse(): void {
  if (trayPulseTimer) return
  trayPulseVisible = true
  trayPulseTimer = setInterval(() => {
    if (!tray || !trayIconNormal || !trayIconAlert) return
    trayPulseVisible = !trayPulseVisible
    tray.setImage(trayPulseVisible ? trayIconAlert : trayIconNormal)
  }, 800)
  if (tray && trayIconAlert) tray.setImage(trayIconAlert)
}

function stopTrayPulse(): void {
  if (trayPulseTimer) {
    clearInterval(trayPulseTimer)
    trayPulseTimer = null
  }
  if (tray && trayIconNormal) tray.setImage(trayIconNormal)
}

/**
 * Create system tray
 */
function createTray(): void {
  const assetsDir = path.join(__dirname, '..', '..', 'assets')
  const isMac = process.platform === 'darwin'

  trayIconNormal = loadTrayIcon(assetsDir, isMac ? 'tray-iconTemplate' : 'tray-icon', isMac)
  trayIconAlert = loadTrayIcon(assetsDir, isMac ? 'tray-iconAlert' : 'tray-icon-alert', false)

  tray = new Tray(trayIconNormal)
  tray.setToolTip('FlowOne Email')

  const contextMenu = Menu.buildFromTemplate([
    {
      label: 'Open FlowOne Email',
      click: () => {
        mainWindow?.show()
        mainWindow?.focus()
      },
    },
    { type: 'separator' },
    {
      label: 'Check for New Mail',
      click: () => {
        mainWindow?.webContents.send('trigger-sync', 'email')
      },
    },
    {
      label: 'Sync Now',
      click: () => {
        mainWindow?.webContents.send('trigger-sync', 'all')
      },
    },
    { type: 'separator' },
    {
      label: 'Settings',
      click: () => {
        mainWindow?.show()
        mainWindow?.webContents.send('navigate', '/settings')
      },
    },
    { type: 'separator' },
    {
      label: 'Quit',
      click: () => {
        isQuitting = true
        app.quit()
      },
    },
  ])

  tray.setContextMenu(contextMenu)

  tray.on('double-click', () => {
    mainWindow?.show()
    mainWindow?.focus()
  })
}

/**
 * Initialize the database
 */
async function initDatabase(): Promise<void> {
  db = LocalDatabase.getInstance()
  await db.initialize()
  console.log('[Main] Database initialized')
}

/**
 * Migrate config - fix any cached incorrect values
 */
function migrateConfig(): void {
  // Fix WebSocket URL if it's the old incorrect value
  const wsUrl = configStore.get('wsUrl')
  if (wsUrl === 'wss://email.devcon1.hu/ws') {
    configStore.set('wsUrl', 'wss://flowone.pro/mailsync_ws')
    console.log('[Main] Migrated WebSocket URL to correct endpoint')
  } else if (typeof wsUrl === 'string' && wsUrl.includes('email.devcon1.hu')) {
    configStore.set('wsUrl', wsUrl.replace('email.devcon1.hu', 'flowone.pro'))
    console.log('[Main] Migrated wsUrl domain to flowone.pro')
  }

  // Migrate old domain and strip trailing /api
  let apiUrl = configStore.get('apiUrl') as string | null
  if (apiUrl && apiUrl.includes('email.devcon1.hu')) {
    apiUrl = apiUrl.replace('email.devcon1.hu', 'flowone.pro')
    configStore.set('apiUrl', apiUrl)
    console.log('[Main] Migrated apiUrl domain to flowone.pro')
  }
  if (apiUrl && apiUrl.endsWith('/api')) {
    configStore.set('apiUrl', apiUrl.replace(/\/api$/, ''))
    console.log('[Main] Migrated apiUrl: removed trailing /api')
  }

  const serverUrl = configStore.get('serverUrl') as string | null
  if (serverUrl && serverUrl.includes('email.devcon1.hu')) {
    configStore.set('serverUrl', serverUrl.replace('email.devcon1.hu', 'flowone.pro'))
    console.log('[Main] Migrated serverUrl domain to flowone.pro')
  }
}

/**
 * Register IPC handlers
 */
function registerIpcHandlers(): void {
  // Window controls
  ipcMain.on('window-minimize', () => mainWindow?.minimize())
  ipcMain.on('window-maximize', () => {
    if (mainWindow?.isMaximized()) {
      mainWindow.unmaximize()
    } else {
      mainWindow?.maximize()
    }
  })
  ipcMain.on('window-close', () => mainWindow?.close())
  ipcMain.handle('window-is-maximized', () => mainWindow?.isMaximized())

  // Keep the native window chrome (and pre-paint background) in sync with the
  // renderer's theme. Accepts 'light' | 'dark' | 'system' so OS-following still
  // works; the background is derived from the effective resolved color.
  ipcMain.on('set-native-theme', (_event, mode: string) => {
    if (mode === 'light' || mode === 'dark' || mode === 'system') {
      nativeTheme.themeSource = mode
    }
    mainWindow?.setBackgroundColor(nativeTheme.shouldUseDarkColors ? '#0f172a' : '#ffffff')
  })

  // Badge count + Windows taskbar flash & overlay
  ipcMain.handle('set-badge-count', (_event, count: number) => {
    if (!mainWindow) return
    if (count > 0) {
      mainWindow.setTitle(`(${count}) FlowOne - Email`)
      if (!mainWindow.isFocused()) {
        mainWindow.flashFrame(true)
      }
      if (process.platform === 'win32') {
        mainWindow.setOverlayIcon(
          trayIconAlert || nativeImage.createEmpty(),
          `${count} unread`
        )
      }
    } else {
      mainWindow.setTitle('FlowOne - Email')
      mainWindow.flashFrame(false)
      if (process.platform === 'win32') {
        mainWindow.setOverlayIcon(null, '')
      }
    }
    app.setBadgeCount(count)
    return true
  })

  // Tray icon pulse on unread
  ipcMain.handle('set-tray-unread', (_event, hasUnread: boolean) => {
    if (hasUnread) startTrayPulse()
    else stopTrayPulse()
    return true
  })

  // Config
  ipcMain.handle('config-get', (_event, key: string) => {
    if (key === 'sessionToken') return getSessionToken()
    return configStore.get(key as any)
  })
  ipcMain.handle('config-set', (_event, key: string, value: any) => {
    if (key === 'sessionToken') {
      setSessionToken(value)
      console.log('[Main] Session token', value ? `stored in secure storage (${value.length} chars)` : 'cleared')
      return true
    }
    configStore.set(key as any, value)
    if (key === 'launchAtStartup') {
      app.setLoginItemSettings({ openAtLogin: !!value })
      console.log(`[Main] Auto-launch set to: ${!!value}`)
    }
    return true
  })
  ipcMain.handle('config-get-all', () => {
    const config = { ...configStore.store }
    config.sessionToken = getSessionToken()
    return config
  })

  // Auth — tokens stored in secureStorage (OS-encrypted), NOT in plaintext config
  ipcMain.handle('auth-set-token', async (_event, token: string, email: string, name: string) => {
    setAuthToken(token)
    configStore.set('userEmail', email)
    configStore.set('userName', name)

    // Register device with server after login
    const apiUrl = configStore.get('apiUrl') || 'https://flowone.pro'
    const sessionToken = getSessionToken()
    registerDevice(apiUrl, token, sessionToken).catch(err => {
      console.error('[Main] Device registration failed:', err)
    })

    if (mainWindow) startStatusPolling(mainWindow)

    console.log('[Main] Auth token set, initializing sync system...')
    initializeSyncAndAutoSync().catch(err => {
      console.error('[Main] Post-login sync init failed:', err)
    })

    // Create seed for sibling desktop apps
    try {
      const resp = await fetch(`${apiUrl}/api/sso/create-seed`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'Authorization': `Bearer ${token}`,
          'X-Session-Token': sessionToken || '',
        },
      })
      const seedResult: any = await resp.json()
      if (seedResult.success && seedResult.data) {
        sharedAuthWatcher.markWrite()
        await writeSharedAuth({
          userEmail: email,
          displayName: name,
          baseUrl: apiUrl,
          seedId: seedResult.data.seed_id,
          seedSecret: seedResult.data.seed_secret,
          seedCreatedAt: seedResult.data.seed_created_at,
          seedExpiresAt: seedResult.data.expires_at,
          updatedAt: Date.now(),
        })
        console.log('[SSO] Seed created for sibling apps after manual login')
      }
    } catch (e) {
      console.error('[SSO] Failed to create seed after login:', e)
    }

    // Start token refresh
    const rt = configStore.get('refreshToken') as string
    if (rt) {
      tokenRefreshHandle?.stop()
      tokenRefreshHandle = startTokenRefreshTimer({
        getTokens: () => {
          const at = getAuthToken()
          const rtok = configStore.get('refreshToken') as string
          const st = getSessionToken()
          if (!at || !rtok || !st) return null
          return { accessToken: at, refreshToken: rtok, sessionToken: st }
        },
        onRefreshed: (newTokens) => {
          setAuthToken(newTokens.access_token)
          if (newTokens.refresh_token) configStore.set('refreshToken', newTokens.refresh_token)
          if (newTokens.session_token) setSessionToken(newTokens.session_token)
        },
        onFailed: () => { mainWindow?.webContents.send('auth-failed') },
        apiBaseUrl: apiUrl,
      })
    }

    // Re-start SSO watcher (may have been stopped by explicit logout)
    startSharedAuthWatcher()

    return true
  })
  ipcMain.handle('auth-clear', () => {
    sharedAuthWatcher.stop()
    const currentSeed = readSharedAuth()
    ignoredSeedId = currentSeed?.seedId || null
    tokenRefreshHandle?.stop()
    tokenRefreshHandle = null
    stopTokenRefreshTimer()
    setAuthToken(null)
    setSessionToken(null)
    configStore.set('userEmail', null)
    configStore.set('userName', null)
    configStore.set('refreshToken', null)
    configStore.delete('sessionToken' as any)
    stopStatusPolling()
    startSharedAuthWatcher()
    return true
  })
  ipcMain.handle('auth-get-token', () => getAuthToken())
  ipcMain.handle('auth-is-logged-in', () => !!getAuthToken())

  // Database operations
  ipcMain.handle('db-get-emails', (_event, folderId: number, limit?: number, offset?: number) => {
    return db?.getEmails(folderId, limit, offset) || []
  })
  ipcMain.handle('db-get-calendars', () => {
    return db?.getCalendars() || []
  })
  ipcMain.handle('db-get-events', (_event, startDate: string, endDate: string, calendarId?: number) => {
    return db?.getEvents(startDate, endDate, calendarId) || []
  })
  ipcMain.handle('db-get-boards', () => {
    return db?.getBoards() || []
  })
  ipcMain.handle('db-get-board', (_event, boardId: number) => {
    return db?.getBoardWithLists(boardId)
  })
  ipcMain.handle('db-get-clients', () => {
    return db?.getClients() || []
  })
  ipcMain.handle('db-get-pending-count', () => {
    return db?.getPendingCount() || 0
  })

  // ============================================
  // HELPER: Infer folder type from path/name
  // ============================================
  function inferFolderType(folderPath: string): string {
    const path = folderPath.toUpperCase()
    const lastPart = folderPath.split('.').pop()?.toUpperCase() || path

    if (path === 'INBOX') return 'inbox'
    if (lastPart === 'SENT' || path === 'INBOX.SENT') return 'sent'
    if (lastPart === 'DRAFTS' || path === 'INBOX.DRAFTS') return 'drafts'
    if (lastPart === 'TRASH' || path === 'INBOX.TRASH') return 'trash'
    if (lastPart === 'SPAM' || lastPart === 'JUNK' || path === 'INBOX.SPAM' || path === 'INBOX.JUNK') return 'spam'
    if (lastPart === 'ARCHIVE' || path === 'INBOX.ARCHIVE') return 'archive'
    return 'user'
  }

  // ============================================
  // OFFLINE EMAIL OPERATIONS
  // ============================================

  // Get all email folders from local DB
  ipcMain.handle('db-get-folders', () => {
    if (!db) return []
    return db.all(`
      SELECT 
        id, remote_id, account_id, name, full_path, 
        type, system,
        unread_count as unread, total_count as total,
        flags, last_sync_at, sync_enabled
      FROM email_folders 
      ORDER BY 
        CASE 
          WHEN full_path = 'INBOX' THEN 1
          WHEN full_path LIKE 'INBOX.%' THEN 2
          ELSE 3
        END, 
        full_path
    `)
  })

  // Get folder by path
  ipcMain.handle('db-get-folder-by-path', (_event, folderPath: string) => {
    if (!db) return null
    return db.get(`
      SELECT * FROM email_folders WHERE full_path = ? OR name = ?
    `, [folderPath, folderPath])
  })

  // Get emails by folder path (with pagination)
  ipcMain.handle('db-get-emails-by-folder', (_event, folderPath: string, limit: number = 50, offset: number = 0) => {
    if (!db) return { messages: [], total: 0 }

    // First get folder ID
    const folder = db.get(`SELECT id, total_count FROM email_folders WHERE full_path = ? OR name = ?`, [folderPath, folderPath])
    if (!folder) return { messages: [], total: 0 }

    const emails = db.all(`
      SELECT 
        e.id, e.remote_id as uid, e.message_id, e.conversation_id,
        e.subject, e.from_address, e.from_name, e.to_addresses, e.cc_addresses,
        e.date_sent as date, e.date_received, e.snippet,
        e.is_read as seen, e.is_starred as flagged, e.is_answered as answered,
        e.is_forwarded as forwarded, e.is_draft as draft,
        e.has_attachments as has_attachment, e.labels, e.size,
        e.body_html, e.body_text
      FROM emails e
      WHERE e.folder_id = ?
      ORDER BY e.date_received DESC
      LIMIT ? OFFSET ?
    `, [folder.id, limit, offset])

    // Parse JSON fields and transform to web app format (must match API response shape)
    const messages = emails.map((e: any) => {
      // Sanitize from_address: it MUST be a valid email string.
      // Old data may have been stored as Buffer/object due to a previous bug.
      let fromAddr = ''
      if (e.from_address) {
        if (typeof e.from_address === 'string') {
          // Only accept if it looks like an email (contains @)
          if (e.from_address.includes('@')) {
            fromAddr = e.from_address.trim()
          }
        } else if (Buffer.isBuffer(e.from_address)) {
          const decoded = e.from_address.toString('utf8').trim()
          if (decoded.includes('@')) {
            fromAddr = decoded
          }
        }
        // Any other type (object, number, etc.) → discard
      }

      let fromName = ''
      if (e.from_name && typeof e.from_name === 'string') {
        fromName = e.from_name.trim()
      }

      // Build the from array in the same format as the API: [{name, email}]
      const fromArray: any[] = []
      if (fromAddr) {
        fromArray.push({ name: fromName, email: fromAddr })
      }

      // Compute Unix timestamp from date_sent or date_received
      let timestamp = 0
      const dateStr = e.date || e.date_received
      if (dateStr) {
        const parsed = new Date(dateStr).getTime()
        if (!isNaN(parsed)) {
          timestamp = Math.floor(parsed / 1000)
        }
      }

      return {
        ...e,
        to: e.to_addresses ? JSON.parse(e.to_addresses) : [],
        cc: e.cc_addresses ? JSON.parse(e.cc_addresses) : [],
        labels: e.labels ? JSON.parse(e.labels) : [],
        // Match API format: from is an array, plus top-level from_email / from_name
        from: fromArray,
        from_email: fromAddr || null,
        from_name: fromName || null,
        timestamp,
        seen: !!e.seen,
        flagged: !!e.flagged,
        answered: !!e.answered,
        forwarded: !!e.forwarded,
        draft: !!e.draft,
        has_attachment: !!e.has_attachment,
        has_body: !!(e.body_html || e.body_text),
      }
    })

    return {
      messages,
      total: folder.total_count || emails.length,
      page: Math.floor(offset / limit) + 1,
      pages: Math.ceil((folder.total_count || emails.length) / limit),
      limit
    }
  })

  // Get single email with body
  ipcMain.handle('db-get-email', (_event, folderPath: string, uid: number) => {
    if (!db) return null

    // Get folder first
    const folder = db.get(`SELECT id FROM email_folders WHERE full_path = ? OR name = ?`, [folderPath, folderPath])
    if (!folder) return null

    const email = db.get(`
      SELECT 
        e.*,
        f.full_path as folder
      FROM emails e
      JOIN email_folders f ON f.id = e.folder_id
      WHERE e.folder_id = ? AND e.remote_id = ?
    `, [folder.id, uid])

    if (!email) return null

    // Sanitize from_address: it MUST be a valid email string.
    let fromAddr = ''
    if (email.from_address) {
      if (typeof email.from_address === 'string' && email.from_address.includes('@')) {
        fromAddr = email.from_address.trim()
      } else if (Buffer.isBuffer(email.from_address)) {
        const decoded = email.from_address.toString('utf8').trim()
        if (decoded.includes('@')) fromAddr = decoded
      }
    }
    let fromName = ''
    if (email.from_name && typeof email.from_name === 'string') {
      fromName = email.from_name.trim()
    }

    // Build from array in API format: [{name, email}]
    const fromArray: any[] = []
    if (fromAddr) {
      fromArray.push({ name: fromName, email: fromAddr })
    }

    // Compute Unix timestamp
    let timestamp = 0
    const dateStr = email.date_sent || email.date_received
    if (dateStr) {
      const parsed = new Date(dateStr).getTime()
      if (!isNaN(parsed)) {
        timestamp = Math.floor(parsed / 1000)
      }
    }

    // Transform to web app format (must match API response shape)
    return {
      ...email,
      uid: email.remote_id,
      to: email.to_addresses ? JSON.parse(email.to_addresses) : [],
      cc: email.cc_addresses ? JSON.parse(email.cc_addresses) : [],
      bcc: email.bcc_addresses ? JSON.parse(email.bcc_addresses) : [],
      labels: email.labels ? JSON.parse(email.labels) : [],
      from: fromArray,
      from_email: fromAddr || null,
      from_name: fromName || null,
      date: email.date_sent,
      timestamp,
      seen: !!email.is_read,
      flagged: !!email.is_starred,
      answered: !!email.is_answered,
      forwarded: !!email.is_forwarded,
      draft: !!email.is_draft,
      has_attachment: !!email.has_attachments,
    }
  })

  // Fetch and cache email body (uses sync engine)
  ipcMain.handle('db-fetch-email-body', async (_event, emailId: number) => {
    const syncManager = getSyncManager()
    const emailEngine = syncManager.getEmailEngine()
    if (!emailEngine) return null
    return emailEngine.fetchEmailBody(emailId)
  })

  // Trigger email sync
  ipcMain.handle('db-sync-emails', async () => {
    console.log('[Main] db-sync-emails called')
    const syncManager = getSyncManager()
    const emailEngine = syncManager.getEmailEngine()
    if (!emailEngine) {
      console.log('[Main] No email engine!')
      return false
    }
    console.log('[Main] Starting email sync, isOnline:', syncManager.isOnline)

    // Force online status and sync
    emailEngine.setOnline(true)
    await emailEngine.sync()
    console.log('[Main] Email sync complete')
    return true
  })

  // Trigger calendar sync
  ipcMain.handle('db-sync-calendars', async () => {
    console.log('[Main] db-sync-calendars called')
    const syncManager = getSyncManager()
    const calendarEngine = syncManager.getCalendarEngine()
    if (!calendarEngine) {
      console.log('[Main] No calendar engine!')
      return false
    }
    calendarEngine.setOnline(true)
    await calendarEngine.sync()
    console.log('[Main] Calendar sync complete')
    return true
  })

  // Trigger boards sync
  ipcMain.handle('db-sync-boards', async () => {
    console.log('[Main] db-sync-boards called')
    const syncManager = getSyncManager()
    const boardsEngine = syncManager.getBoardsEngine()
    if (!boardsEngine) {
      console.log('[Main] No boards engine!')
      return false
    }
    boardsEngine.setOnline(true)
    await boardsEngine.sync()
    console.log('[Main] Boards sync complete')
    return true
  })

  // Comprehensive offline sync - syncs ALL data (emails, calendars, boards)
  ipcMain.handle('db-sync-all-for-offline', async () => {
    console.log('[Main] db-sync-all-for-offline called - starting comprehensive sync')
    const syncManager = getSyncManager()

    // Ensure SyncManager is initialized (this may be called before window fully loaded)
    if (mainWindow && !syncManager.isInitialized) {
      console.log('[Main] SyncManager not initialized, initializing now...')
      await syncManager.initialize(mainWindow)
    }

    const results = {
      emails: false,
      emailBodies: { synced: 0, total: 0 },
      calendars: false,
      boards: false,
      errors: [] as string[]
    }

    // 0. Sync email accounts first
    try {
      console.log('[Main] Syncing email accounts...')
      const axios = require('axios')
      const token = getAuthToken() || db?.getSetting('auth_token')
      const serverUrl = configStore.get('serverUrl') || 'https://flowone.pro'

      if (token) {
        const accountsResponse = await axios.get(`${serverUrl}/api/accounts`, {
          headers: { Authorization: `Bearer ${token}` }
        })

        if (accountsResponse.data.success && accountsResponse.data.data) {
          const accounts = accountsResponse.data.data.accounts || accountsResponse.data.data || []
          let cachedAccounts = 0

          for (const account of (Array.isArray(accounts) ? accounts : [])) {
            try {
              db?.prepare(`
                INSERT INTO email_accounts (remote_id, email, display_name, is_primary, is_oauth, provider, sync_enabled)
                VALUES (?, ?, ?, ?, ?, ?, ?)
                ON CONFLICT(remote_id) DO UPDATE SET
                  email = excluded.email, display_name = excluded.display_name, 
                  is_primary = excluded.is_primary, provider = excluded.provider
              `).run(
                account.id,
                account.email,
                account.display_name || account.name || account.email,
                account.is_primary || account.is_default ? 1 : 0,
                account.is_oauth ? 1 : 0,
                account.provider || 'imap',
                1
              )
              cachedAccounts++
            } catch (e) { }
          }

          console.log(`[Main] Synced ${cachedAccounts} email accounts`)
            ; (results as any).accounts = cachedAccounts
        }
      }
    } catch (e: any) {
      console.log('[Main] Email accounts sync skipped:', e.message)
    }

    // 1. Sync email headers
    try {
      const emailEngine = syncManager.getEmailEngine()
      console.log('[Main] Email engine exists:', !!emailEngine)
      if (emailEngine) {
        emailEngine.setOnline(true)
        console.log('[Main] Starting email sync...')
        await emailEngine.sync()
        results.emails = true
        console.log('[Main] Email headers synced')

        // 2. Sync email bodies (last 14 days, max 500)
        console.log('[Main] Starting email bodies sync...')
        results.emailBodies = await emailEngine.syncBodies(14, 500)
        console.log(`[Main] Email bodies synced: ${results.emailBodies.synced}/${results.emailBodies.total}`)
      } else {
        console.log('[Main] No email engine available!')
        results.errors.push('Email engine not initialized')
      }
    } catch (e: any) {
      console.error('[Main] Email sync failed:', e.message, e.stack)
      results.errors.push(`Email: ${e.message}`)
    }

    // 3. Sync calendars
    try {
      const calendarEngine = syncManager.getCalendarEngine()
      console.log('[Main] Calendar engine exists:', !!calendarEngine)
      if (calendarEngine) {
        calendarEngine.setOnline(true)
        console.log('[Main] Starting calendar sync...')
        await calendarEngine.sync()
        results.calendars = true
        console.log('[Main] Calendars synced')
      } else {
        console.log('[Main] No calendar engine available!')
        results.errors.push('Calendar engine not initialized')
      }
    } catch (e: any) {
      console.error('[Main] Calendar sync failed:', e.message, e.stack)
      results.errors.push(`Calendar: ${e.message}`)
    }

    // 4. Sync boards
    try {
      const boardsEngine = syncManager.getBoardsEngine()
      console.log('[Main] Boards engine exists:', !!boardsEngine)
      if (boardsEngine) {
        boardsEngine.setOnline(true)
        console.log('[Main] Starting boards sync...')
        await boardsEngine.sync()
        results.boards = true
        console.log('[Main] Boards synced')
      } else {
        console.log('[Main] No boards engine available!')
        results.errors.push('Boards engine not initialized')
      }
    } catch (e: any) {
      console.error('[Main] Boards sync failed:', e.message, e.stack)
      results.errors.push(`Boards: ${e.message}`)
    }

    // 5. Sync clients (direct API call - no dedicated engine)
    try {
      console.log('[Main] Syncing clients...')
      const axios = require('axios')
      const token = getAuthToken() || db?.getSetting('auth_token')
      const serverUrl = configStore.get('serverUrl') || 'https://flowone.pro'

      if (token) {
        const clientsResponse = await axios.get(`${serverUrl}/api/clients`, {
          headers: { Authorization: `Bearer ${token}` },
          params: { status: null } // Get all statuses
        })

        if (clientsResponse.data.success && clientsResponse.data.data?.clients) {
          const clients = clientsResponse.data.data.clients
          let cachedClients = 0

          for (const client of clients) {
            try {
              db?.prepare(`
                INSERT INTO clients (remote_id, name, email, phone, company, is_active, hourly_rate, notes, sync_status)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'synced')
                ON CONFLICT(remote_id) DO UPDATE SET
                  name = excluded.name, email = excluded.email, phone = excluded.phone,
                  company = excluded.company, is_active = excluded.is_active, hourly_rate = excluded.hourly_rate
              `).run(
                client.id,
                client.name || client.company || 'Unknown',
                client.email || null,
                client.phone || null,
                client.company || null,
                client.is_active !== false && client.status !== 'inactive' ? 1 : 0,
                client.hourly_rate || null,
                client.notes || null
              )
              cachedClients++
            } catch (e) { }
          }

          console.log(`[Main] Synced ${cachedClients}/${clients.length} clients`)
            ; (results as any).clients = cachedClients

          // Update sync state
          db?.prepare(`
            INSERT INTO sync_state (entity_type, last_sync_at) 
            VALUES ('client', datetime('now'))
            ON CONFLICT(entity_type) DO UPDATE SET last_sync_at = datetime('now')
          `).run()
        }
      } else {
        console.log('[Main] No auth token for client sync')
      }
    } catch (e: any) {
      console.error('[Main] Client sync failed:', e.message)
      results.errors.push(`Clients: ${e.message}`)
    }

    // 6. Sync todos (direct API call)
    try {
      console.log('[Main] Syncing todos...')
      const axios = require('axios')
      const token = getAuthToken() || db?.getSetting('auth_token')
      const serverUrl = configStore.get('serverUrl') || 'https://flowone.pro'

      if (token) {
        const todosResponse = await axios.get(`${serverUrl}/api/todos`, {
          headers: { Authorization: `Bearer ${token}` },
          params: { include_completed: true }
        })

        if (todosResponse.data.success && todosResponse.data.data?.todos) {
          const todos = todosResponse.data.data.todos

          let cachedTodos = 0
          for (const todo of todos) {
            try {
              db?.prepare(`
                INSERT INTO todos (remote_id, title, description, is_completed, due_date, priority, sync_status)
                VALUES (?, ?, ?, ?, ?, ?, 'synced')
                ON CONFLICT(remote_id) DO UPDATE SET
                  title = excluded.title, description = excluded.description,
                  is_completed = excluded.is_completed, due_date = excluded.due_date, priority = excluded.priority
              `).run(
                todo.id,
                todo.title || todo.text || '',
                todo.description || todo.notes || null,
                todo.completed || todo.is_completed || todo.done ? 1 : 0,
                todo.due_date || todo.due || null,
                todo.priority || 0
              )
              cachedTodos++
            } catch (e) { }
          }

          console.log(`[Main] Synced ${cachedTodos}/${todos.length} todos`)
            ; (results as any).todos = cachedTodos

          // Update sync state
          db?.prepare(`
            INSERT INTO sync_state (entity_type, last_sync_at) 
            VALUES ('todo', datetime('now'))
            ON CONFLICT(entity_type) DO UPDATE SET last_sync_at = datetime('now')
          `).run()
        }
      } else {
        console.log('[Main] No auth token for todos sync')
      }
    } catch (e: any) {
      console.error('[Main] Todos sync failed:', e.message)
      results.errors.push(`Todos: ${e.message}`)
    }

    // 7. Sync time tracking data (uses /time/my-stats endpoint)
    try {
      console.log('[Main] Syncing time tracking...')
      const axios = require('axios')
      const token = getAuthToken() || db?.getSetting('auth_token')
      const serverUrl = configStore.get('serverUrl') || 'https://flowone.pro'

      if (token) {
        const timeResponse = await axios.get(`${serverUrl}/api/time/my-stats`, {
          headers: { Authorization: `Bearer ${token}` },
          params: { period: 'month' }
        })

        if (timeResponse.data.success && timeResponse.data.data) {
          const timeData = timeResponse.data.data

          // Extract activities from the response
          const activities = timeData.activities || timeData.recent_activities || []
          let cachedEntries = 0

          for (const activity of activities) {
            try {
              db?.prepare(`
                INSERT INTO time_entries (remote_id, client_id, description, started_at, duration_seconds, source, is_billable, sync_status)
                VALUES (?, ?, ?, ?, ?, ?, ?, 'synced')
                ON CONFLICT(remote_id) DO UPDATE SET
                  description = excluded.description, started_at = excluded.started_at, 
                  duration_seconds = excluded.duration_seconds, is_billable = excluded.is_billable
              `).run(
                activity.id,
                activity.client_id || null,
                activity.activity_type || activity.description || 'Time tracked',
                activity.created_at || activity.started_at,
                activity.duration_seconds || activity.duration || 0,
                activity.source || 'desktop',
                activity.is_billable !== false ? 1 : 0
              )
              cachedEntries++
            } catch (e) { }
          }

          console.log(`[Main] Synced ${cachedEntries} time tracking activities`)
            ; (results as any).timeEntries = cachedEntries
        }
      }
    } catch (e: any) {
      // Time tracking might not have activities, log and continue
      console.log('[Main] Time tracking sync skipped:', e.message)
    }

    // 8. Sync colleagues/team
    try {
      const colleagueEngine = syncManager.getColleagueEngine()
      if (colleagueEngine) {
        colleagueEngine.setOnline(true)
        await colleagueEngine.sync()
          ; (results as any).colleagues = true
        console.log('[Main] Colleagues synced')
      }
    } catch (e: any) {
      console.error('[Main] Colleagues sync failed:', e.message)
      results.errors.push(`Colleagues: ${e.message}`)
    }

    // 9. Sync chats
    try {
      const chatEngine = syncManager.getChatEngine()
      if (chatEngine) {
        chatEngine.setOnline(true)
        await chatEngine.sync()
          ; (results as any).chats = true
        console.log('[Main] Chats synced')
      }
    } catch (e: any) {
      console.error('[Main] Chat sync failed:', e.message)
      results.errors.push(`Chats: ${e.message}`)
    }

    // 10. Sync mailing lists
    try {
      const mailingListEngine = syncManager.getMailingListEngine()
      if (mailingListEngine) {
        mailingListEngine.setOnline(true)
        await mailingListEngine.sync()
          ; (results as any).mailingLists = true
        console.log('[Main] Mailing Lists synced')
      }
    } catch (e: any) {
      console.error('[Main] Mailing List sync failed:', e.message)
      results.errors.push(`MailingLists: ${e.message}`)
    }

    // 11. Sync campaigns
    try {
      const campaignEngine = syncManager.getCampaignEngine()
      if (campaignEngine) {
        campaignEngine.setOnline(true)
        await campaignEngine.sync()
          ; (results as any).campaigns = true
        console.log('[Main] Campaigns synced')
      }
    } catch (e: any) {
      console.error('[Main] Campaign sync failed:', e.message)
      results.errors.push(`Campaigns: ${e.message}`)
    }

    // 12. Sync CRM data (via CRM sync engine)
    try {
      const crmEngine = syncManager.getCrmEngine()
      console.log('[Main] CRM engine exists:', !!crmEngine)
      if (crmEngine) {
        crmEngine.setOnline(true)
        console.log('[Main] Starting CRM sync...')
        await crmEngine.sync()
          ; (results as any).crm = true
        console.log('[Main] CRM synced')
      }
    } catch (e: any) {
      console.error('[Main] CRM sync failed:', e.message)
      results.errors.push(`CRM: ${e.message}`)
    }

    // 13. Sync Mood Boards (via MoodBoard sync engine)
    try {
      const moodBoardEngine = syncManager.getMoodBoardEngine()
      console.log('[Main] MoodBoard engine exists:', !!moodBoardEngine)
      if (moodBoardEngine) {
        moodBoardEngine.setOnline(true)
        console.log('[Main] Starting MoodBoard sync...')
        await moodBoardEngine.sync()
          ; (results as any).moodBoards = true
        console.log('[Main] MoodBoards synced')
      }
    } catch (e: any) {
      console.error('[Main] MoodBoard sync failed:', e.message)
      results.errors.push(`MoodBoards: ${e.message}`)
    }

    // 14. Sync Client Portal (via Portal sync engine)
    try {
      const portalEngine = syncManager.getPortalEngine()
      console.log('[Main] Portal engine exists:', !!portalEngine)
      if (portalEngine) {
        portalEngine.setOnline(true)
        console.log('[Main] Starting Portal sync...')
        await portalEngine.sync()
          ; (results as any).portal = true
        console.log('[Main] Portal synced')
      }
    } catch (e: any) {
      console.error('[Main] Portal sync failed:', e.message)
      results.errors.push(`Portal: ${e.message}`)
    }

    console.log('[Main] Comprehensive sync complete:', results)
    return results
  })

  // Get network/sync status
  ipcMain.handle('db-get-sync-status', () => {
    const syncManager = getSyncManager()
    return {
      isOnline: syncManager.isOnline,
      pendingCount: db?.getPendingCount() || 0,
      lastEmailSync: db?.getLastSyncAt('email'),
    }
  })

  // Check if we have local email data
  ipcMain.handle('db-has-offline-data', () => {
    if (!db) return false
    const emailCount = db.get('SELECT COUNT(*) as count FROM emails')
    const folderCount = db.get('SELECT COUNT(*) as count FROM email_folders')
    return {
      hasEmails: (emailCount?.count || 0) > 0,
      hasFolders: (folderCount?.count || 0) > 0,
      emailCount: emailCount?.count || 0,
      folderCount: folderCount?.count || 0,
    }
  })

  // Sync email bodies (for offline reading preparation)
  ipcMain.handle('db-sync-email-bodies', async (_event, days?: number, maxCount?: number) => {
    const syncManager = getSyncManager()
    const emailEngine = syncManager.getEmailEngine()
    if (!emailEngine) return { synced: 0, total: 0 }
    return emailEngine.syncBodies(days || 7, maxCount || 200)
  })

  // Get count of emails needing body sync
  ipcMain.handle('db-get-emails-needing-bodies', (_event, days?: number) => {
    const syncManager = getSyncManager()
    const emailEngine = syncManager.getEmailEngine()
    if (!emailEngine) return 0
    return emailEngine.getEmailsNeedingBodySync(days || 7)
  })

  // Queue offline changes
  ipcMain.handle('db-queue-change', (_event, entityType: string, entityId: number | null, action: string, payload: object) => {
    return db?.queueChange(entityType, entityId, action, payload)
  })

  // ============================================
  // CACHE API DATA FOR OFFLINE USE
  // These are called by renderer when it receives data from API
  // ============================================

  // Cache emails when loaded from API
  ipcMain.handle('db-cache-emails', (_event, folderPath: string, emails: any[]) => {
    if (!db || !emails?.length) return 0

    try {
      // Get or create folder
      let folder = db.get('SELECT id FROM email_folders WHERE full_path = ?', [folderPath]) as { id: number } | undefined
      if (!folder) {
        const folderType = inferFolderType(folderPath)
        const isSystem = folderType !== 'user' ? 1 : 0
        db.prepare(`
          INSERT INTO email_folders (account_id, name, full_path, type, system) VALUES (1, ?, ?, ?, ?)
        `).run(folderPath.split('.').pop() || folderPath, folderPath, folderType, isSystem)
        folder = db.get('SELECT id FROM email_folders WHERE full_path = ?', [folderPath]) as { id: number }
      }

      if (!folder) return 0

      let cached = 0
      for (const email of emails) {
        try {
          // Extract from_address and from_name from various API formats:
          // API returns: from: [{name: '...', email: '...'}], from_email: '...', from_name: '...'
          let fromAddress = ''
          let fromName = ''
          if (Array.isArray(email.from) && email.from.length > 0) {
            // Standard API format: [{name, email}]
            fromAddress = email.from[0].email || ''
            fromName = email.from[0].name || ''
          } else if (typeof email.from === 'object' && email.from !== null) {
            // Object format: {address, name} or {email, name}
            fromAddress = email.from.email || email.from.address || ''
            fromName = email.from.name || ''
          } else if (typeof email.from === 'string') {
            fromAddress = email.from
          }
          // Fallback to top-level fields if available
          if (!fromAddress && email.from_email) fromAddress = email.from_email
          if (!fromName && email.from_name) fromName = email.from_name

          // Compute date for storage — API sends timestamp (Unix) or date (ISO string)
          let dateSent = email.date || ''
          if (!dateSent && email.timestamp) {
            dateSent = new Date(email.timestamp * 1000).toISOString()
          }
          const dateReceived = email.internal_date || dateSent

          // Check if exists
          const existing = db.get('SELECT id FROM emails WHERE folder_id = ? AND remote_id = ?', [folder.id, email.uid])

          if (existing) {
            // Update flags + sender info (sender may have been cached incorrectly before)
            db.prepare(`
              UPDATE emails SET subject = ?, snippet = ?, is_read = ?, is_starred = ?, has_attachments = ?,
              from_address = COALESCE(NULLIF(?, ''), from_address),
              from_name = COALESCE(NULLIF(?, ''), from_name),
              date_sent = COALESCE(NULLIF(?, ''), date_sent)
              WHERE folder_id = ? AND remote_id = ?
            `).run(
              email.subject,
              email.snippet || email.preview,
              email.is_read || email.seen ? 1 : 0,
              email.is_starred || email.flagged ? 1 : 0,
              email.has_attachments ? 1 : 0,
              fromAddress,
              fromName,
              dateSent,
              folder.id,
              email.uid
            )
          } else {
            // Insert
            db.prepare(`
              INSERT INTO emails (
                remote_id, account_id, folder_id, message_id, subject, from_address, from_name,
                to_addresses, date_sent, date_received, snippet, is_read, is_starred, has_attachments, sync_status
              ) VALUES (?, 1, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'synced')
            `).run(
              email.uid,
              folder.id,
              email.message_id,
              email.subject,
              fromAddress,
              fromName,
              JSON.stringify(email.to || []),
              dateSent,
              dateReceived,
              email.snippet || email.preview,
              email.is_read || email.seen ? 1 : 0,
              email.is_starred || email.flagged ? 1 : 0,
              email.has_attachments ? 1 : 0
            )
          }
          cached++
        } catch (e) {
          // Skip individual errors
        }
      }

      console.log(`[Main] Cached ${cached}/${emails.length} emails for ${folderPath}`)
      return cached
    } catch (e: any) {
      console.error('[Main] Failed to cache emails:', e.message)
      return 0
    }
  })

  // Cache email body when viewed
  ipcMain.handle('db-cache-email-body', (_event, folderPath: string, uid: number, bodyHtml: string, bodyText: string) => {
    if (!db) return false

    try {
      db.prepare(`
        UPDATE emails SET body_html = ?, body_text = ?
        WHERE remote_id = ? AND folder_id IN (SELECT id FROM email_folders WHERE full_path = ?)
      `).run(bodyHtml || '', bodyText || '', uid, folderPath)
      return true
    } catch (e) {
      return false
    }
  })

  // Cache calendar events when loaded
  ipcMain.handle('db-cache-events', (_event, events: any[]) => {
    if (!db || !events?.length) return 0

    try {
      let cached = 0
      for (const event of events) {
        try {
          // Get or create calendar
          let calendar = db.get('SELECT id FROM calendars WHERE remote_id = ?', [event.calendar_id]) as { id: number } | undefined
          if (!calendar) {
            db.prepare('INSERT OR IGNORE INTO calendars (remote_id, name, color) VALUES (?, ?, ?)').run(
              event.calendar_id, 'Calendar', '#0ea5e9'
            )
            calendar = db.get('SELECT id FROM calendars WHERE remote_id = ?', [event.calendar_id]) as { id: number }
          }

          if (!calendar) continue

          // Upsert event
          db.prepare(`
            INSERT INTO calendar_events (remote_id, calendar_id, title, description, location, start_time, end_time, all_day, sync_status)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'synced')
            ON CONFLICT(remote_id) DO UPDATE SET
              title = excluded.title, description = excluded.description, location = excluded.location,
              start_time = excluded.start_time, end_time = excluded.end_time, all_day = excluded.all_day
          `).run(
            event.id,
            calendar.id,
            event.title,
            event.description || null,
            event.location || null,
            event.start_time || event.start,
            event.end_time || event.end,
            event.all_day ? 1 : 0
          )
          cached++
        } catch (e) {
          // Skip individual errors
        }
      }
      console.log(`[Main] Cached ${cached}/${events.length} calendar events`)
      return cached
    } catch (e: any) {
      console.error('[Main] Failed to cache events:', e.message)
      return 0
    }
  })

  // Cache boards when loaded
  ipcMain.handle('db-cache-boards', (_event, boards: any[]) => {
    if (!db || !boards?.length) return 0

    try {
      let cached = 0
      for (const board of boards) {
        try {
          db.prepare(`
            INSERT INTO boards (remote_id, name, description, color, sync_status)
            VALUES (?, ?, ?, ?, 'synced')
            ON CONFLICT(remote_id) DO UPDATE SET
              name = excluded.name, description = excluded.description, color = excluded.color
          `).run(board.id, board.name, board.description || null, board.color || null)
          cached++
        } catch (e) {
          // Skip
        }
      }
      console.log(`[Main] Cached ${cached}/${boards.length} boards`)
      return cached
    } catch (e: any) {
      console.error('[Main] Failed to cache boards:', e.message)
      return 0
    }
  })

  // Cache full board with lists and cards
  ipcMain.handle('db-cache-board-full', (_event, boardData: any) => {
    if (!db || !boardData) return false

    try {
      // Cache board
      db.prepare(`
        INSERT INTO boards (remote_id, name, description, color, sync_status)
        VALUES (?, ?, ?, ?, 'synced')
        ON CONFLICT(remote_id) DO UPDATE SET
          name = excluded.name, description = excluded.description, color = excluded.color
      `).run(boardData.id, boardData.name, boardData.description || null, boardData.color || null)

      const localBoard = db.get('SELECT id FROM boards WHERE remote_id = ?', [boardData.id]) as { id: number } | undefined
      if (!localBoard) return false

      // Cache lists
      let listsCount = 0
      if (boardData.lists?.length) {
        for (const list of boardData.lists) {
          try {
            db.prepare(`
              INSERT INTO board_lists (remote_id, board_id, name, position, sync_status)
              VALUES (?, ?, ?, ?, 'synced')
              ON CONFLICT(remote_id) DO UPDATE SET
                name = excluded.name, position = excluded.position
            `).run(list.id, localBoard.id, list.name, list.position || 0)
            listsCount++
          } catch (e) { }
        }
      }

      // Cache cards
      let cardsCount = 0
      if (boardData.cards?.length) {
        for (const card of boardData.cards) {
          try {
            const localList = db.get('SELECT id FROM board_lists WHERE remote_id = ?', [card.list_id]) as { id: number } | undefined
            if (!localList) continue

            db.prepare(`
              INSERT INTO board_cards (remote_id, list_id, title, description, position, due_date, sync_status)
              VALUES (?, ?, ?, ?, ?, ?, 'synced')
              ON CONFLICT(remote_id) DO UPDATE SET
                list_id = excluded.list_id, title = excluded.title, description = excluded.description,
                position = excluded.position, due_date = excluded.due_date
            `).run(card.id, localList.id, card.title, card.description || null, card.position || 0, card.due_date || null)
            cardsCount++
          } catch (e) { }
        }
      }

      console.log(`[Main] Cached board "${boardData.name}" with ${listsCount} lists, ${cardsCount} cards`)
      return true
    } catch (e: any) {
      console.error('[Main] Failed to cache board:', e.message)
      return false
    }
  })

  // Cache clients when loaded
  ipcMain.handle('db-cache-clients', (_event, clients: any[]) => {
    if (!db || !clients?.length) return 0

    try {
      let cached = 0
      for (const client of clients) {
        try {
          db.prepare(`
            INSERT INTO clients (remote_id, name, email, phone, company, is_active, hourly_rate, notes, sync_status)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'synced')
            ON CONFLICT(remote_id) DO UPDATE SET
              name = excluded.name, email = excluded.email, phone = excluded.phone,
              company = excluded.company, is_active = excluded.is_active, hourly_rate = excluded.hourly_rate
          `).run(
            client.id,
            client.name || client.company || 'Unknown',
            client.email || null,
            client.phone || null,
            client.company || null,
            client.is_active !== false && client.status !== 'inactive' ? 1 : 0,
            client.hourly_rate || null,
            client.notes || null
          )
          cached++
        } catch (e) { }
      }
      console.log(`[Main] Cached ${cached}/${clients.length} clients`)
      return cached
    } catch (e: any) {
      console.error('[Main] Failed to cache clients:', e.message)
      return 0
    }
  })

  // Cache time entries when loaded
  ipcMain.handle('db-cache-time-entries', (_event, entries: any[]) => {
    if (!db || !entries?.length) return 0

    try {
      let cached = 0
      for (const entry of entries) {
        try {
          db.prepare(`
            INSERT INTO time_entries (remote_id, client_id, description, started_at, ended_at, duration_seconds, is_billable, sync_status)
            VALUES (?, ?, ?, ?, ?, ?, ?, 'synced')
            ON CONFLICT(remote_id) DO UPDATE SET
              description = excluded.description, started_at = excluded.started_at, 
              ended_at = excluded.ended_at, duration_seconds = excluded.duration_seconds,
              is_billable = excluded.is_billable
          `).run(
            entry.id,
            entry.client_id || null,
            entry.description || null,
            entry.started_at || entry.start_time,
            entry.ended_at || entry.end_time,
            entry.duration_seconds || entry.duration || 0,
            entry.is_billable !== false ? 1 : 0
          )
          cached++
        } catch (e) { }
      }
      console.log(`[Main] Cached ${cached}/${entries.length} time entries`)
      return cached
    } catch (e: any) {
      console.error('[Main] Failed to cache time entries:', e.message)
      return 0
    }
  })

  // Cache todos when loaded
  ipcMain.handle('db-cache-todos', (_event, todos: any[]) => {
    if (!db || !todos?.length) return 0

    try {
      let cached = 0
      for (const todo of todos) {
        try {
          db.prepare(`
            INSERT INTO todos (remote_id, title, description, is_completed, due_date, priority, sync_status)
            VALUES (?, ?, ?, ?, ?, ?, 'synced')
            ON CONFLICT(remote_id) DO UPDATE SET
              title = excluded.title, description = excluded.description,
              is_completed = excluded.is_completed, due_date = excluded.due_date, priority = excluded.priority
          `).run(
            todo.id,
            todo.title || todo.text || '',
            todo.description || todo.notes || null,
            todo.completed || todo.is_completed || todo.done ? 1 : 0,
            todo.due_date || todo.due || null,
            todo.priority || 0
          )
          cached++
        } catch (e) { }
      }
      console.log(`[Main] Cached ${cached}/${todos.length} todos`)
      return cached
    } catch (e: any) {
      console.error('[Main] Failed to cache todos:', e.message)
      return 0
    }
  })

  // ============================================
  // OFFLINE DATA RETRIEVAL
  // ============================================

  // Get offline boards
  ipcMain.handle('db-get-offline-boards', () => {
    if (!db) return []
    try {
      const boards = db.all(`
        SELECT id, remote_id as id, name, description, color, is_archived, is_starred, 
               sync_status, created_at
        FROM boards WHERE is_archived = 0 ORDER BY name
      `)
      console.log(`[Main] Retrieved ${boards?.length || 0} offline boards`)
      return boards || []
    } catch (e: any) {
      console.error('[Main] Failed to get offline boards:', e.message)
      return []
    }
  })

  // Get offline clients
  ipcMain.handle('db-get-offline-clients', () => {
    if (!db) return []
    try {
      const clients = db.all(`
        SELECT id, remote_id as id, name, email, company, phone, is_active, 
               hourly_rate, notes, sync_status
        FROM clients ORDER BY name
      `)
      console.log(`[Main] Retrieved ${clients?.length || 0} offline clients`)
      return clients || []
    } catch (e: any) {
      console.error('[Main] Failed to get offline clients:', e.message)
      return []
    }
  })

  // Get offline todos
  ipcMain.handle('db-get-offline-todos', () => {
    if (!db) return []
    try {
      const todos = db.all(`
        SELECT id, remote_id as id, title, description, is_completed as completed, 
               due_date, priority, sync_status
        FROM todos ORDER BY priority DESC, due_date
      `)
      console.log(`[Main] Retrieved ${todos?.length || 0} offline todos`)
      return todos || []
    } catch (e: any) {
      console.error('[Main] Failed to get offline todos:', e.message)
      return []
    }
  })

  // Get offline calendars
  ipcMain.handle('db-get-offline-calendars', () => {
    if (!db) return []
    try {
      const calendars = db.all(`
        SELECT id, remote_id as id, name, color, description, is_default, is_visible
        FROM calendars ORDER BY is_default DESC, name
      `)
      console.log(`[Main] Retrieved ${calendars?.length || 0} offline calendars`)
      return calendars || []
    } catch (e: any) {
      console.error('[Main] Failed to get offline calendars:', e.message)
      return []
    }
  })

  // Get offline calendar events
  ipcMain.handle('db-get-offline-events', () => {
    if (!db) return []
    try {
      const events = db.all(`
        SELECT e.id, e.remote_id as id, e.title, e.description, e.location,
               e.start_time, e.end_time, e.all_day, e.calendar_id,
               c.name as calendar_name, c.color as calendar_color
        FROM calendar_events e
        LEFT JOIN calendars c ON e.calendar_id = c.id
        ORDER BY e.start_time
      `)
      console.log(`[Main] Retrieved ${events?.length || 0} offline events`)
      return events || []
    } catch (e: any) {
      console.error('[Main] Failed to get offline events:', e.message)
      return []
    }
  })

  // Settings
  ipcMain.handle('db-get-setting', (_event, key: string) => {
    return db?.getSetting(key)
  })
  ipcMain.handle('db-set-setting', (_event, key: string, value: string) => {
    db?.setSetting(key, value)
    return true
  })

  // Notifications
  ipcMain.handle('show-notification', (_event, title: string, body: string) => {
    if (configStore.get('notificationsEnabled')) {
      const iconPath = path.join(__dirname, '..', '..', 'assets', 'icon.png')
      const opts: Electron.NotificationConstructorOptions = { title, body }
      if (process.platform === 'win32' && fs.existsSync(iconPath)) {
        opts.icon = iconPath
      }
      const n = new Notification(opts)
      n.on('click', () => {
        if (mainWindow) {
          if (mainWindow.isMinimized()) mainWindow.restore()
          mainWindow.show()
          mainWindow.focus()
        }
      })
      n.show()
      if (mainWindow && !mainWindow.isFocused()) {
        mainWindow.flashFrame(true)
      }
    }
    return true
  })

  // Open external link
  ipcMain.handle('open-external', (_event, url: string) => {
    shell.openExternal(url)
    return true
  })

  // Get app info
  ipcMain.handle('get-app-version', () => app.getVersion())
  ipcMain.handle('get-app-path', (_event, name: string) => {
    return app.getPath(name as any)
  })

  // Sync request from renderer
  // NOTE: SyncManager.registerIpcHandlers() also listens on 'sync-request'
  //       and handles structured WS messages (e.g. { type: 'SUBSCRIBE_CHAT' }).
  //       This handler only processes simple string-based sync triggers.
  ipcMain.on('sync-request', async (_event, type?: any) => {
    // Skip structured WebSocket messages - SyncManager handles those
    if (type && typeof type === 'object' && type.type) {
      return
    }

    console.log('[Main] Sync request:', type || 'all')
    try {
      const syncManager = getSyncManager()
      if (type === 'email') {
        // Sync emails only
        const emailEngine = syncManager['engines']?.get('email')
        if (emailEngine) {
          await emailEngine.sync()
        }
      } else {
        // Full sync
        await syncManager.fullSync()
      }
    } catch (error) {
      console.error('[Main] Sync error:', error)
      mainWindow?.webContents.send('sync-error', { error: String(error) })
    }
  })

  // Install update (for auto-updater)
  ipcMain.on('install-update', () => {
    console.log('[Main] Install update requested')
    // This would integrate with electron-updater
    // For now, just log it
  })

  // Logging from renderer
  ipcMain.on('log', (_event, level: string, ...args: any[]) => {
    switch (level) {
      case 'error':
        console.error('[Renderer]', ...args)
        break
      case 'warn':
        console.warn('[Renderer]', ...args)
        break
      default:
        console.log('[Renderer]', ...args)
    }
  })

  // ============================================
  // DEBUG MODE - Database inspection
  // ============================================

  // Get database stats
  ipcMain.handle('debug-get-db-stats', () => {
    return db?.getDebugStats() || null
  })

  // Get table data
  ipcMain.handle('debug-get-table-data', (_event, tableName: string, limit?: number) => {
    return db?.getDebugTableData(tableName, limit || 20) || { columns: [], rows: [] }
  })

  // Run debug query (SELECT only)
  ipcMain.handle('debug-run-query', (_event, sql: string) => {
    return db?.debugQuery(sql) || { columns: [], rows: [], error: 'Database not initialized' }
  })

  // Open database folder
  ipcMain.handle('debug-open-db-folder', () => {
    const dbPath = app.getPath('userData')
    shell.openPath(dbPath)
    return dbPath
  })
}

/**
 * SSO + OAuth IPC handlers
 */
function registerSSOIpcHandlers(): void {
  // OAuth via BrowserWindow
  ipcMain.handle('oauth-start', async (_event, provider: string) => {
    const apiUrl = configStore.get('apiUrl') || 'https://flowone.pro'
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

      // After OAuth success, create seed for siblings and perform full login
      await afterLogin(result.tokens)
      return { success: true }
    } catch (e: any) {
      console.error('[OAuth] Failed:', e.message)
      return { success: false, error: e.message }
    }
  })

  // Exchange SSO code from web
  ipcMain.handle('sso-exchange-code', async (_event, code: string) => {
    try {
      const apiUrl = configStore.get('apiUrl') || 'https://flowone.pro'
      console.log('[SSO] Exchanging code via', `${apiUrl}/api/sso/exchange`)
      const resp = await fetch(`${apiUrl}/api/sso/exchange`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ code, nonce: '' }),
      })
      const result: any = await resp.json()
      console.log('[SSO] Exchange response:', resp.status, JSON.stringify(result).substring(0, 300))
      if (result.success && result.data) {
        await afterLogin(result.data)
        return { success: true }
      }
      const errorMsg = result.error || result.message || `Exchange failed (HTTP ${resp.status})`
      console.error('[SSO] Exchange failed:', errorMsg)
      return { success: false, error: errorMsg }
    } catch (e: any) {
      console.error('[SSO] Exchange error:', e.message)
      return { success: false, error: e.message }
    }
  })

  // Logout: clear local auth state only.
  // Do NOT revoke the seed on the server -- sibling apps (Drive, Chat) that
  // already cloned their own independent sessions should keep working.
  // The "Logout Everywhere" button uses a separate /sessions/revoke-all flow.
  ipcMain.handle('sso-logout', async () => {
    // Stop SSO watcher FIRST to prevent re-cloning before cleanup finishes
    sharedAuthWatcher.stop()

    clearSharedAuth()
    tokenRefreshHandle?.stop()
    tokenRefreshHandle = null
    stopTokenRefreshTimer()
    setAuthToken(null)
    setSessionToken(null)
    configStore.set('userEmail', null)
    configStore.set('userName', null)
    configStore.set('refreshToken', null)
    stopStatusPolling()
    mainWindow?.webContents.send('auth-failed')
    ignoredSeedId = null
    startSharedAuthWatcher()
    return true
  })
}

// ============================================
// SSO / DEEP LINK HELPERS
// ============================================

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

async function performFullLogin(tokenData: any): Promise<void> {
  if (!tokenData) return
  setAuthToken(tokenData.access_token)
  if (tokenData.session_token) setSessionToken(tokenData.session_token)
  configStore.set('userEmail', tokenData.user?.email || '')
  configStore.set('userName', tokenData.user?.display_name || '')

  const apiUrl = configStore.get('apiUrl') || 'https://flowone.pro'
  registerDevice(apiUrl, tokenData.access_token, tokenData.session_token).catch(() => {})
  if (mainWindow) startStatusPolling(mainWindow)

  initializeSyncAndAutoSync().catch(err => {
    console.error('[Main] Post-SSO sync init failed:', err)
  })

  // Start proactive token refresh
  tokenRefreshHandle?.stop()
  tokenRefreshHandle = startTokenRefreshTimer({
    getTokens: () => {
      const at = getAuthToken()
      const rt = configStore.get('refreshToken') as string
      const st = getSessionToken()
      if (!at || !rt || !st) return null
      return { accessToken: at, refreshToken: rt, sessionToken: st }
    },
    onRefreshed: (newTokens) => {
      setAuthToken(newTokens.access_token)
      if (newTokens.refresh_token) configStore.set('refreshToken', newTokens.refresh_token)
      if (newTokens.session_token) setSessionToken(newTokens.session_token)
    },
    onFailed: () => {
      console.log('[TokenRefresh] Auth failed, notifying renderer')
      mainWindow?.webContents.send('auth-failed')
    },
    apiBaseUrl: apiUrl,
  })

  mainWindow?.webContents.send('sso-authenticated', {
    email: tokenData.user?.email,
    displayName: tokenData.user?.display_name,
  })
  console.log('[SSO] performFullLogin complete for', tokenData.user?.email)
}

async function afterLogin(tokenData: any): Promise<void> {
  // Store refresh token
  if (tokenData.refresh_token) configStore.set('refreshToken', tokenData.refresh_token)

  const apiUrl = configStore.get('apiUrl') || 'https://flowone.pro'

  // Create a seed for sibling apps
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
      console.log('[SSO] Seed created and shared for sibling apps')
    }
  } catch (e) {
    console.error('[SSO] Failed to create seed after login:', e)
  }

  await performFullLogin(tokenData)
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
    const apiUrl = configStore.get('apiUrl') || 'https://flowone.pro'

    const resp = await fetch(`${apiUrl}/api/sso/exchange`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ code, nonce }),
    })
    const result: any = await resp.json()

    if (result.success && result.data) {
      if (result.data.refresh_token) configStore.set('refreshToken', result.data.refresh_token)

      // Write seed to shared store
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

      await performFullLogin(result.data)
    } else {
      console.error('[SSO] Deep link exchange failed:', result.error || result.message)
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
      if (result.data.refresh_token) configStore.set('refreshToken', result.data.refresh_token)

      // Write rotated seed back
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

      await performFullLogin(result.data)
      console.log('[SSO] Clone success, new seed written')
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
      stopTokenRefreshTimer()
      tokenRefreshHandle = null
      setAuthToken(null)
      setSessionToken(null)
      configStore.set('userEmail', null)
      configStore.set('userName', null)
      stopStatusPolling()
      mainWindow?.webContents.send('auth-failed')
      return
    }

    if (ignoredSeedId && data.seedId === ignoredSeedId) {
      console.log('[SSO] Skipping pre-logout seed')
      return
    }
    ignoredSeedId = null

    const currentEmail = configStore.get('userEmail')
    if (currentEmail === data.userEmail && getAuthToken()) return

    if (currentEmail && currentEmail !== data.userEmail) {
      const { dialog } = require('electron')
      const { response } = await dialog.showMessageBox(mainWindow!, {
        type: 'question',
        buttons: ['Switch Account', 'Stay'],
        message: `Another FlowOne app logged in as ${data.userEmail}. Switch account?`,
      })
      if (response !== 0) return
      setAuthToken(null)
      setSessionToken(null)
      stopStatusPolling()
    }

    await handleSSOClone(data)
  })
}

// ============================================
// APP LIFECYCLE
// ============================================

// Track quitting state
let isQuitting = false

app.whenReady().then(async () => {
  console.log('[Main] App ready')

  migrateConfig()

  // Apply saved auto-launch setting
  const launchAtStartup = configStore.get('launchAtStartup')
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
    const apiUrl = configStore.get('apiUrl') || 'https://flowone.pro'
    try {
      const resp = await fetch(`${apiUrl}/api/auth/me`, {
        headers: { 'Authorization': `Bearer ${getAuthToken()}` },
        signal: AbortSignal.timeout(5000),
      })
      if (resp.status === 401) {
        console.log('[SSO] Token confirmed invalid (401), clearing for SSO clone')
        setAuthToken(null)
        setSessionToken(null)
        configStore.set('userEmail', null)
      }
    } catch (e: any) {
      console.log('[SSO] Token validation skipped (network/timeout):', e.message)
    }
  }

  // Try SSO clone if not logged in and no pending deep link
  if (!getAuthToken() && !pendingDeepLink) {
    const seedData = readSharedAuth()
    if (seedData && new Date(seedData.seedExpiresAt) > new Date()) {
      console.log('[SSO] Found shared seed, attempting clone')
      try {
        await handleSSOClone(seedData)
      } catch (e) {
        console.log('[SSO] Startup clone failed, showing login screen')
      }
    }
  }

  await initDatabase()
  registerIpcHandlers()
  registerBiometricIpcHandlers()
  registerSSOIpcHandlers()
  createWindow()
  createTray()

  // Handle pending deep link after window is ready
  if (pendingDeepLink) {
    const link = pendingDeepLink
    pendingDeepLink = null
    setTimeout(() => handleDeepLink(link), 500)
  }

  // If already logged in, start token refresh and watcher
  if (getAuthToken()) {
    const apiUrl = configStore.get('apiUrl') || 'https://flowone.pro'
    tokenRefreshHandle = startTokenRefreshTimer({
      getTokens: () => {
        const at = getAuthToken()
        const rt = configStore.get('refreshToken') as string
        const st = getSessionToken()
        if (!at || !rt || !st) return null
        return { accessToken: at, refreshToken: rt, sessionToken: st }
      },
      onRefreshed: (newTokens) => {
        setAuthToken(newTokens.access_token)
        if (newTokens.refresh_token) configStore.set('refreshToken', newTokens.refresh_token)
        if (newTokens.session_token) setSessionToken(newTokens.session_token)
      },
      onFailed: () => {
        mainWindow?.webContents.send('auth-failed')
      },
      apiBaseUrl: apiUrl,
    })
  }

  // Start watching for sibling app logins
  startSharedAuthWatcher()

  app.on('activate', () => {
    if (BrowserWindow.getAllWindows().length === 0) {
      createWindow()
    } else {
      mainWindow?.show()
    }
  })
})

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

// macOS: handle protocol URL
app.on('open-url', (_event: any, url: string) => {
  try { handleDeepLink(url) } catch (e) { console.error('[DeepLink]', e) }
})

app.on('window-all-closed', () => {
  console.log('[Main] All windows closed')
})

app.on('before-quit', async () => {
  isQuitting = true
  sharedAuthWatcher.stop()
  tokenRefreshHandle?.stop()
  stopStatusPolling()
  cleanupBiometricAuth()
  await shutdownSyncManager()
  db?.close()
})

// Handle uncaught exceptions
process.on('uncaughtException', (error) => {
  console.error('[Main] Uncaught exception:', error)
})

process.on('unhandledRejection', (reason, promise) => {
  console.error('[Main] Unhandled rejection at:', promise, 'reason:', reason)
})

