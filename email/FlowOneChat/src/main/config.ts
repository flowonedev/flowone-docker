import Store from 'electron-store'

interface ConfigSchema {
  userEmail: string | null
  userName: string | null
  deviceToken: string | null
  refreshToken: string | null

  apiUrl: string
  wsUrl: string

  theme: 'light' | 'dark' | 'system'
  startMinimized: boolean
  minimizeToTray: boolean
  launchAtStartup: boolean
  notificationsEnabled: boolean

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
  deviceToken: null,
  refreshToken: null,

  apiUrl: 'https://flowone.pro',
  wsUrl: 'wss://flowone.pro/mailsync_ws',

  theme: 'system',
  startMinimized: false,
  minimizeToTray: true,
  launchAtStartup: false,
  notificationsEnabled: true,

  windowBounds: {
    width: 960,
    height: 700,
  },
  windowMaximized: false,
}

export const configStore = new Store<ConfigSchema>({
  name: 'config',
  defaults,
})

export type { ConfigSchema }
