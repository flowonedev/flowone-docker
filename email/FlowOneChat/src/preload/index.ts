import { contextBridge, ipcRenderer } from 'electron'

const eventListeners = new Map<string, Set<Function>>()

const api = {
  platform: process.platform,

  // Tell the main process to follow the renderer's light/dark theme.
  setNativeTheme: (mode: string) => ipcRenderer.send('set-native-theme', mode),

  window: {
    minimize: () => ipcRenderer.send('window-minimize'),
    maximize: () => ipcRenderer.send('window-maximize'),
    close: () => ipcRenderer.send('window-close'),
    isMaximized: () => ipcRenderer.invoke('window-is-maximized'),
  },

  config: {
    get: (key: string) => ipcRenderer.invoke('config-get', key),
    set: (key: string, value: any) => ipcRenderer.invoke('config-set', key, value),
    getAll: () => ipcRenderer.invoke('config-get-all'),
  },

  auth: {
    setToken: (token: string, email: string, name: string) =>
      ipcRenderer.invoke('auth-set-token', token, email, name),
    clearToken: () => ipcRenderer.invoke('auth-clear'),
    getToken: () => ipcRenderer.invoke('auth-get-token'),
    isLoggedIn: () => ipcRenderer.invoke('auth-is-logged-in'),
  },

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

  sync: {
    getStatus: () => ipcRenderer.invoke('sync-get-status'),
  },

  notification: {
    show: (title: string, body: string) =>
      ipcRenderer.invoke('show-notification', title, body),
  },

  tray: {
    setUnread: (hasUnread: boolean) =>
      ipcRenderer.invoke('set-tray-unread', hasUnread),
  },

  openExternal: (url: string) => ipcRenderer.invoke('open-external', url),
  getVersion: () => ipcRenderer.invoke('get-app-version'),
  getProxyPort: () => ipcRenderer.invoke('get-proxy-port'),
  setBadgeCount: (count: number) => ipcRenderer.invoke('set-badge-count', count),

  on: (channel: string, callback: (...args: any[]) => void) => {
    const validChannels = [
      'sync-status',
      'sync-complete',
      'sync-error',
      'online-status',
      'auth-failed',
      'sso-authenticated',
      'forced-logout',
      'maximize-padding',
      'update-available',
      'update-downloaded',
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
      // Colleague events
      'colleague-updated',
      'colleague-group-updated',
    ]

    if (!validChannels.includes(channel)) {
      console.warn(`[Preload] Invalid channel: ${channel}`)
      return () => {}
    }

    const subscription = (_event: any, ...args: any[]) => callback(...args)
    ipcRenderer.on(channel, subscription)

    if (!eventListeners.has(channel)) {
      eventListeners.set(channel, new Set())
    }
    eventListeners.get(channel)!.add(subscription)

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

  send: (channel: string, ...args: any[]) => {
    const validChannels = ['sync-request', 'log']
    if (validChannels.includes(channel)) {
      ipcRenderer.send(channel, ...args)
    }
  },

  network: {
    onStatusChange: (callback: (isOnline: boolean) => void) => {
      return api.on('online-status', callback)
    },
  },
}

contextBridge.exposeInMainWorld('api', api)

export type ElectronAPI = typeof api
