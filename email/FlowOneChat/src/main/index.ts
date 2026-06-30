import { app, BrowserWindow, ipcMain, shell, Tray, Menu, nativeImage, NativeImage, Notification, screen, desktopCapturer, nativeTheme } from 'electron'
import path from 'path'
import http from 'http'
import https from 'https'
import fs from 'fs'
import { configStore } from './config'
import { getAuthToken, setAuthToken, getSessionToken, setSessionToken } from './secureStorage'
import { readSharedAuth, writeSharedAuth, clearSharedAuth, SharedAuthWatcher, type SharedAuthData } from './sso/sharedAuth'
import { openOAuthWindow } from './sso/oauthWindow'
import { startTokenRefreshTimer, stopTokenRefreshTimer } from './sso/tokenRefresh'

if (process.platform === 'win32') {
  app.setAppUserModelId('com.flowone.chat')
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

const os = require('os')
const userDataPath = path.join(os.homedir(), '.flowone-chat')
app.setPath('userData', userDataPath)

const gpuCachePath = path.join(userDataPath, 'GPUCache')
try {
  if (fs.existsSync(gpuCachePath)) {
    fs.rmSync(gpuCachePath, { recursive: true, force: true })
  }
} catch (e) {
  console.warn('[Main] Could not clear GPU cache:', e)
}

let mainWindow: BrowserWindow | null = null
let tray: Tray | null = null
let proxyServerPort = 0
let isQuitting = false

const gotTheLock = app.requestSingleInstanceLock()
if (!gotTheLock) {
  app.quit()
}

// ─── WebSocket client for chat events ───
const WebSocket = require('ws')
let wsClient: any = null
let wsReconnectTimer: NodeJS.Timeout | null = null
let wsReconnectAttempts = 0

function connectWebSocket(): void {
  const token = getAuthToken()
  if (!token) {
    console.log('[WS] No auth token, skipping WebSocket connection')
    return
  }

  const wsUrl = configStore.get('wsUrl')
  console.log('[WS] Connecting to', wsUrl)

  try {
    wsClient = new WebSocket(wsUrl)
  } catch (err) {
    console.error('[WS] Failed to create WebSocket:', err)
    scheduleReconnect()
    return
  }

  wsClient.on('open', () => {
    console.log('[WS] Connected')
    wsReconnectAttempts = 0

    wsClient.send(JSON.stringify({ type: 'AUTHENTICATE', token }))
    wsClient.send(JSON.stringify({ type: 'SUBSCRIBE_CHAT' }))
    wsClient.send(JSON.stringify({ type: 'SUBSCRIBE_PRESENCE' }))

    notifyRenderer('sync-status', { initialized: true, isOnline: true, wsConnected: true })
  })

  wsClient.on('message', (data: any) => {
    try {
      const msg = JSON.parse(data.toString())
      if (msg.type === 'PONG') return

      const channelMap: Record<string, string> = {
        CHAT_MESSAGE_NEW: 'chat-message-new',
        CHAT_MESSAGE_EDITED: 'chat-message-edited',
        CHAT_MESSAGE_DELETED: 'chat-message-deleted',
        CHAT_MESSAGE_PINNED: 'chat-message-pinned',
        CHAT_REACTION_ADDED: 'chat-reaction-added',
        CHAT_REACTION_REMOVED: 'chat-reaction-removed',
        CHAT_TYPING_START: 'chat-typing-start',
        CHAT_TYPING_STOP: 'chat-typing-stop',
        CHAT_READ_RECEIPT: 'chat-read-receipt',
        CHAT_CONVERSATION_CREATED: 'chat-conversation-created',
        CHAT_SETTINGS_UPDATED: 'chat-settings-updated',
        CHAT_VIEW_SESSION_START: 'chat-view-session-start',
        CHAT_VIEW_SESSION_END: 'chat-view-session-end',
        CHAT_VIEW_SYNC: 'chat-view-sync',
        PRESENCE_ONLINE: 'presence-online',
        PRESENCE_OFFLINE: 'presence-offline',
        PRESENCE_STATUS_CHANGED: 'presence-status-changed',
        PRESENCE_BULK_UPDATE: 'presence-bulk-update',
        CALL_INITIATE: 'call-initiate',
        CALL_RINGING: 'call-ringing',
        CALL_ANSWER: 'call-answer',
        CALL_REJECT: 'call-reject',
        CALL_HANGUP: 'call-hangup',
        CALL_ICE_CANDIDATE: 'call-ice-candidate',
        CALL_MEDIA_STATE: 'call-media-state',
        CALL_PARTICIPANT_JOINED: 'call-participant-joined',
        CALL_PARTICIPANT_LEFT: 'call-participant-left',
        CALL_SCREEN_SHARE_START: 'call-screen-share-start',
        CALL_SCREEN_SHARE_STOP: 'call-screen-share-stop',
        CALL_MISSED: 'call-missed',
        CALL_DISMISSED: 'call-dismissed',
        CALL_SDP_OFFER: 'call-sdp-offer',
        CALL_SDP_ANSWER: 'call-sdp-answer',
        COLLEAGUE_UPDATED: 'colleague-updated',
        COLLEAGUE_GROUP_UPDATED: 'colleague-group-updated',
      }

      const channel = channelMap[msg.type]
      if (channel) {
        notifyRenderer(channel, msg.payload || msg)
      }
    } catch (err) {
      console.error('[WS] Message parse error:', err)
    }
  })

  wsClient.on('close', (code: number) => {
    console.log('[WS] Disconnected, code:', code)
    notifyRenderer('sync-status', { initialized: true, isOnline: false, wsConnected: false })
    if (code !== 1000 && !isQuitting) {
      scheduleReconnect()
    }
  })

  wsClient.on('error', (err: any) => {
    console.error('[WS] Error:', err.message)
  })

  // Heartbeat
  const heartbeat = setInterval(() => {
    if (wsClient?.readyState === WebSocket.OPEN) {
      wsClient.send(JSON.stringify({ type: 'PING' }))
    } else {
      clearInterval(heartbeat)
    }
  }, 25000)
}

function scheduleReconnect(): void {
  if (wsReconnectTimer) clearTimeout(wsReconnectTimer)
  const delay = Math.min(1000 * Math.pow(2, wsReconnectAttempts), 30000)
  wsReconnectAttempts++
  console.log(`[WS] Reconnecting in ${delay}ms (attempt ${wsReconnectAttempts})`)
  wsReconnectTimer = setTimeout(connectWebSocket, delay)
}

function disconnectWebSocket(): void {
  if (wsReconnectTimer) {
    clearTimeout(wsReconnectTimer)
    wsReconnectTimer = null
  }
  if (wsClient) {
    try { wsClient.close(1000) } catch (_) {}
    wsClient = null
  }
}

function notifyRenderer(channel: string, data: any): void {
  mainWindow?.webContents.send(channel, data)
}

// ─── Sync status IPC (renderer polls this) ───
ipcMain.handle('sync-get-status', () => {
  const connected = wsClient?.readyState === WebSocket.OPEN
  return {
    initialized: true,
    isOnline: connected,
    isVerifiedOnline: connected,
    wsConnected: connected,
    pendingCount: 0,
    lastEventVersion: 0,
  }
})

// ─── Create window ───
async function createWindow(): Promise<void> {
  const bounds = configStore.get('windowBounds')
  const maximized = configStore.get('windowMaximized')
  const rendererPath = path.join(__dirname, '..', 'renderer')

  const API_HOST = 'flowone.pro'

  const CORS_HEADERS: Record<string, string> = {
    'Access-Control-Allow-Origin': '*',
    'Access-Control-Allow-Methods': 'GET, POST, PUT, DELETE, OPTIONS, PATCH',
    'Access-Control-Allow-Headers': 'Content-Type, Authorization, X-Session-Token, X-Account-Id, X-Device-Id',
    'Access-Control-Max-Age': '86400',
  }

  const proxyServer = http.createServer((req: any, res: any) => {
    if (req.method === 'OPTIONS') {
      res.writeHead(204, CORS_HEADERS)
      res.end()
      return
    }

    const proxyHeaders = { ...req.headers, host: API_HOST }
    delete proxyHeaders['connection']
    delete proxyHeaders['keep-alive']
    delete proxyHeaders['origin']
    delete proxyHeaders['referer']
    delete proxyHeaders['accept-encoding']

    const proxyOptions = {
      hostname: API_HOST,
      port: 443,
      path: req.url,
      method: req.method,
      headers: proxyHeaders,
    }

    if (req.method !== 'GET') {
      console.log('[Proxy]', req.method, req.url, 'content-type:', req.headers['content-type']?.substring(0, 60))
    }

    const proxyReq = https.request(proxyOptions, (proxyRes: any) => {
      const responseHeaders = { ...proxyRes.headers }
      delete responseHeaders['access-control-allow-origin']
      delete responseHeaders['access-control-allow-methods']
      delete responseHeaders['access-control-allow-headers']
      delete responseHeaders['access-control-allow-credentials']
      delete responseHeaders['access-control-max-age']
      Object.assign(responseHeaders, CORS_HEADERS)

      if (proxyRes.statusCode !== 200) {
        console.log('[Proxy]', req.method, req.url, '->', proxyRes.statusCode)
      }

      if (proxyRes.statusCode === 401 || (proxyRes.statusCode >= 400 && req.method !== 'GET')) {
        let body = ''
        proxyRes.on('data', (chunk: any) => { body += chunk.toString() })
        proxyRes.on('end', () => {
          if (proxyRes.statusCode >= 400) {
            console.log('[Proxy] Error response:', proxyRes.statusCode, body.substring(0, 500))
          }
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

  await new Promise<void>((resolve) => {
    proxyServer.listen(0, '127.0.0.1', () => {
      const addr = proxyServer.address()
      proxyServerPort = (addr as any).port
      console.log('[Proxy] API proxy on http://127.0.0.1:' + proxyServerPort)
      resolve()
    })
  })

  const iconPath = path.join(__dirname, '..', '..', 'assets', 'icon.png')
  let appIcon = nativeImage.createEmpty()
  try {
    const loadedIcon = nativeImage.createFromPath(iconPath)
    if (!loadedIcon.isEmpty()) appIcon = loadedIcon
  } catch (_) {}

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

  mainWindow.on('resize', saveWindowBounds)
  mainWindow.on('move', saveWindowBounds)

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

  let windowShown = false
  const showWindow = () => {
    if (windowShown || !mainWindow) return
    windowShown = true
    mainWindow.show()
    if (maximized) mainWindow.maximize()
    mainWindow.focus()
  }

  mainWindow.once('ready-to-show', () => {
    if (!configStore.get('startMinimized')) showWindow()
  })

  setTimeout(() => {
    if (!windowShown && !configStore.get('startMinimized')) showWindow()
  }, 5000)

  mainWindow.on('close', (event) => {
    if (configStore.get('minimizeToTray') && !isQuitting) {
      event.preventDefault()
      mainWindow?.hide()
    }
  })

  mainWindow.on('closed', () => { mainWindow = null })

  mainWindow.on('focus', () => {
    mainWindow?.flashFrame(false)
  })

  // Screen sharing support for calls
  mainWindow.webContents.session.setDisplayMediaRequestHandler(async (_request, callback) => {
    try {
      const sources = await desktopCapturer.getSources({ types: ['screen', 'window'] })
      if (sources.length > 0) {
        callback({ video: sources[0], audio: 'loopback' })
      } else {
        callback({})
      }
    } catch (err) {
      console.error('[Main] Screen share failed:', err)
      callback({})
    }
  })

  mainWindow.webContents.session.webRequest.onHeadersReceived((details, callback) => {
    callback({
      responseHeaders: {
        ...details.responseHeaders,
        'Content-Security-Policy': [
          `default-src 'self' 'unsafe-inline' 'unsafe-eval' data: blob: https: http://127.0.0.1:${proxyServerPort} ws: wss:; ` +
          `style-src 'self' 'unsafe-inline' https:; ` +
          `font-src 'self' data: https:; ` +
          `img-src 'self' data: blob: https: http://127.0.0.1:${proxyServerPort}`
        ]
      }
    })
  })

  // Redirect file:// API asset requests to the local proxy
  mainWindow.webContents.session.webRequest.onBeforeRequest(
    { urls: ['file://*'] },
    (details, callback) => {
      if (!details.url.includes('/api/')) {
        callback({})
        return
      }
      try {
        const url = new URL(details.url)
        const pathname = decodeURIComponent(url.pathname)
        let apiPath: string | null = null
        if (pathname.startsWith('/api/')) {
          apiPath = pathname
        } else {
          const apiIdx = pathname.indexOf('/api/')
          if (apiIdx !== -1) apiPath = pathname.substring(apiIdx)
        }
        if (apiPath) {
          callback({ redirectURL: `http://127.0.0.1:${proxyServerPort}${apiPath}${url.search || ''}` })
          return
        }
      } catch (_) {}
      callback({})
    }
  )

  // Inject auth headers for proxy requests (avatar images etc.)
  mainWindow.webContents.session.webRequest.onBeforeSendHeaders(
    { urls: [`http://127.0.0.1:*/*`] },
    (details, callback) => {
      const headers = { ...details.requestHeaders }
      if (!headers['Authorization'] && !headers['authorization']) {
        const token = getAuthToken()
        if (token) headers['Authorization'] = `Bearer ${token}`
      }
      if (!headers['X-Session-Token'] && !headers['x-session-token']) {
        const sessionToken = getSessionToken()
        if (sessionToken) headers['X-Session-Token'] = sessionToken
      }
      callback({ requestHeaders: headers })
    }
  )

  const indexPath = path.join(rendererPath, 'index.html')
  console.log('[Main] Loading:', indexPath)

  try {
    await mainWindow.loadFile(indexPath)
    console.log('[Main] App loaded')

    mainWindow.webContents.on('console-message', (_event: any, level: number, message: string) => {
      if (level >= 1) {
        const levelStr = ['VERBOSE', 'INFO', 'WARN', 'ERROR'][level] || 'LOG'
        console.log(`[Renderer/${levelStr}] ${message}`)
      }
    })

    mainWindow.webContents.on('before-input-event', (_event: any, input: any) => {
      if (input.type === 'keyDown') {
        if (input.key === 'F12' || (input.control && input.shift && input.key.toLowerCase() === 'i')) {
          mainWindow?.webContents.toggleDevTools()
        }
      }
    })

    if (getAuthToken()) {
      console.log('[Main] Auth token present, connecting WebSocket...')
      connectWebSocket()
    }
  } catch (err) {
    console.error('[Main] Failed to load app:', err)
  }

  mainWindow.webContents.setWindowOpenHandler(({ url }) => {
    shell.openExternal(url)
    return { action: 'deny' }
  })

  mainWindow.webContents.on('will-navigate', (event, url) => {
    if (url.startsWith('file://')) return
    event.preventDefault()
    shell.openExternal(url)
  })
}

// ─── Window bounds ───
let saveWindowBoundsTimer: NodeJS.Timeout | null = null
function saveWindowBounds(): void {
  if (!mainWindow || mainWindow.isMaximized()) return
  if (saveWindowBoundsTimer) clearTimeout(saveWindowBoundsTimer)
  saveWindowBoundsTimer = setTimeout(() => {
    if (!mainWindow || mainWindow.isMaximized()) return
    configStore.set('windowBounds', mainWindow.getBounds())
  }, 500)
}

// ─── System tray ───
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

function createTray(): void {
  const assetsDir = path.join(__dirname, '..', '..', 'assets')
  const isMac = process.platform === 'darwin'

  trayIconNormal = loadTrayIcon(assetsDir, isMac ? 'tray-iconTemplate' : 'tray-icon', isMac)
  trayIconAlert = loadTrayIcon(assetsDir, isMac ? 'tray-iconAlert' : 'tray-icon-alert', false)

  tray = new Tray(trayIconNormal)
  tray.setToolTip('FlowOne Chat')

  const contextMenu = Menu.buildFromTemplate([
    {
      label: 'Open FlowOne Chat',
      click: () => { mainWindow?.show(); mainWindow?.focus() },
    },
    { type: 'separator' },
    {
      label: 'Quit',
      click: () => { isQuitting = true; app.quit() },
    },
  ])

  tray.setContextMenu(contextMenu)
  tray.on('double-click', () => { mainWindow?.show(); mainWindow?.focus() })
}

// ─── IPC handlers ───
function registerIpcHandlers(): void {
  ipcMain.on('window-minimize', () => mainWindow?.minimize())
  ipcMain.on('window-maximize', () => {
    if (mainWindow?.isMaximized()) mainWindow.unmaximize()
    else mainWindow?.maximize()
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

  ipcMain.handle('set-badge-count', (_event, count: number) => {
    if (!mainWindow) return
    mainWindow.setTitle(count > 0 ? `(${count}) FlowOne - Chat` : 'FlowOne - Chat')
    if (count > 0) {
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
      mainWindow.flashFrame(false)
      if (process.platform === 'win32') {
        mainWindow.setOverlayIcon(null, '')
      }
    }
    app.setBadgeCount(count)
    return true
  })

  ipcMain.handle('config-get', (_event, key: string) => {
    if (key === 'sessionToken') return getSessionToken()
    return configStore.get(key as any)
  })
  ipcMain.handle('config-set', (_event, key: string, value: any) => {
    if (key === 'sessionToken') {
      setSessionToken(value)
      return true
    }
    configStore.set(key as any, value)
    if (key === 'launchAtStartup') {
      app.setLoginItemSettings({ openAtLogin: !!value })
    }
    return true
  })
  ipcMain.handle('config-get-all', () => {
    const config = { ...configStore.store }
    ;(config as any).sessionToken = getSessionToken()
    return config
  })

  ipcMain.handle('auth-set-token', async (_event, token: string, email: string, name: string) => {
    setAuthToken(token)
    configStore.set('userEmail', email)
    configStore.set('userName', name)
    connectWebSocket()

    // Create seed for sibling desktop apps
    const apiUrl = configStore.get('apiUrl') || 'https://flowone.pro'
    const sessionToken = getSessionToken()
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
        console.log('[SSO] Seed created for sibling apps')
      }
    } catch (e) {
      console.error('[SSO] Failed to create seed:', e)
    }

    // Start token refresh
    startChatTokenRefresh()

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
    setAuthToken(null)
    setSessionToken(null)
    configStore.set('userEmail', null)
    configStore.set('userName', null)
    configStore.set('refreshToken', null)
    disconnectWebSocket()
    startSharedAuthWatcher()
    return true
  })
  ipcMain.handle('auth-get-token', () => getAuthToken())
  ipcMain.handle('auth-is-logged-in', () => !!getAuthToken())

  // SSO + OAuth IPC
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
      await afterChatLogin(result.tokens)
      return { success: true }
    } catch (e: any) {
      return { success: false, error: e.message }
    }
  })

  ipcMain.handle('sso-exchange-code', async (_event, code: string) => {
    const apiUrl = configStore.get('apiUrl') || 'https://flowone.pro'
    try {
      console.log('[SSO] Exchanging code via', `${apiUrl}/api/sso/exchange`)
      const resp = await fetch(`${apiUrl}/api/sso/exchange`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ code, nonce: '' }),
      })
      const result: any = await resp.json()
      console.log('[SSO] Exchange response:', resp.status, JSON.stringify(result).substring(0, 300))
      if (result.success && result.data) {
        await afterChatLogin(result.data)
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

  ipcMain.handle('sso-logout', async () => {
    // Stop SSO watcher FIRST to prevent it from re-cloning before we finish cleanup
    sharedAuthWatcher.stop()
    clearSharedAuth()
    tokenRefreshHandle?.stop()
    tokenRefreshHandle = null
    setAuthToken(null)
    setSessionToken(null)
    configStore.set('userEmail', null)
    configStore.set('userName', null)
    configStore.set('refreshToken', null)
    disconnectWebSocket()
    mainWindow?.webContents.send('auth-failed')
    ignoredSeedId = null
    startSharedAuthWatcher()
    return true
  })

  ipcMain.handle('get-proxy-port', () => proxyServerPort)

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

  ipcMain.handle('set-tray-unread', (_event, hasUnread: boolean) => {
    if (hasUnread) startTrayPulse()
    else stopTrayPulse()
    return true
  })

  ipcMain.handle('open-external', (_event, url: string) => {
    shell.openExternal(url)
    return true
  })

  ipcMain.handle('get-app-version', () => app.getVersion())

  // Forward WebSocket messages from renderer (typing, presence, call signaling)
  ipcMain.on('sync-request', (_event, msg: any) => {
    if (wsClient?.readyState === WebSocket.OPEN && msg && typeof msg === 'object') {
      wsClient.send(JSON.stringify(msg))
    }
  })
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

function startChatTokenRefresh(): void {
  const apiUrl = configStore.get('apiUrl') || 'https://flowone.pro'
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
      mainWindow?.webContents.send('auth-failed')
    },
    apiBaseUrl: apiUrl,
  })
}

async function performChatLogin(tokenData: any): Promise<void> {
  if (!tokenData) return
  setAuthToken(tokenData.access_token)
  if (tokenData.session_token) setSessionToken(tokenData.session_token)
  if (tokenData.refresh_token) configStore.set('refreshToken', tokenData.refresh_token)
  configStore.set('userEmail', tokenData.user?.email || '')
  configStore.set('userName', tokenData.user?.display_name || '')

  connectWebSocket()
  startChatTokenRefresh()

  mainWindow?.webContents.send('sso-authenticated', {
    email: tokenData.user?.email,
    displayName: tokenData.user?.display_name,
  })
  console.log('[SSO] performChatLogin complete for', tokenData.user?.email)
}

async function afterChatLogin(tokenData: any): Promise<void> {
  if (tokenData.refresh_token) configStore.set('refreshToken', tokenData.refresh_token)
  const apiUrl = configStore.get('apiUrl') || 'https://flowone.pro'

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

  await performChatLogin(tokenData)
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
      await performChatLogin(result.data)
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
      await performChatLogin(result.data)
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
      configStore.set('userEmail', null)
      configStore.set('userName', null)
      disconnectWebSocket()
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
      disconnectWebSocket()
    }

    await handleSSOClone(data)
  })
}

// ─── App lifecycle ───
function migrateConfig(): void {
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

  const wsUrl = configStore.get('wsUrl') as string | null
  if (wsUrl && typeof wsUrl === 'string' && wsUrl.includes('email.devcon1.hu')) {
    configStore.set('wsUrl', wsUrl.replace('email.devcon1.hu', 'flowone.pro'))
    console.log('[Main] Migrated wsUrl domain to flowone.pro')
  }
}

app.whenReady().then(async () => {
  console.log('[Main] App ready')

  migrateConfig()

  const deepLinkArg = extractDeepLink(process.argv)
  if (deepLinkArg) {
    pendingDeepLink = deepLinkArg
    console.log('[SSO] Cold start deep link detected')
  }

  // Try SSO clone if not logged in and no pending deep link
  if (!getAuthToken() && !pendingDeepLink) {
    const seedData = readSharedAuth()
    if (seedData && new Date(seedData.seedExpiresAt) > new Date()) {
      console.log('[SSO] Found shared seed, attempting clone')
      try { await handleSSOClone(seedData) } catch {}
    }
  }

  registerIpcHandlers()
  createWindow()
  createTray()

  if (pendingDeepLink) {
    const link = pendingDeepLink
    pendingDeepLink = null
    setTimeout(() => handleDeepLink(link), 500)
  }

  if (getAuthToken()) {
    startChatTokenRefresh()
  }

  startSharedAuthWatcher()

  app.on('activate', () => {
    if (BrowserWindow.getAllWindows().length === 0) createWindow()
    else mainWindow?.show()
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

app.on('open-url', (_event: any, url: string) => {
  try { handleDeepLink(url) } catch (e) { console.error('[DeepLink]', e) }
})

app.on('window-all-closed', () => {})

app.on('before-quit', () => {
  isQuitting = true
  sharedAuthWatcher.stop()
  tokenRefreshHandle?.stop()
  disconnectWebSocket()
})

process.on('uncaughtException', (error) => {
  console.error('[Main] Uncaught exception:', error)
})

process.on('unhandledRejection', (reason, promise) => {
  console.error('[Main] Unhandled rejection at:', promise, 'reason:', reason)
})
