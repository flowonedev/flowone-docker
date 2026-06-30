/// <reference types="vite/client" />

declare module '*.vue' {
  import type { DefineComponent } from 'vue'
  const component: DefineComponent<{}, {}, any>
  export default component
}

// Electron API exposed via preload
interface ElectronAPI {
  window: {
    minimize: () => void
    maximize: () => void
    close: () => void
    isMaximized: () => Promise<boolean>
  }
  config: {
    get: (key: string) => Promise<any>
    set: (key: string, value: any) => Promise<boolean>
    getAll: () => Promise<any>
  }
  auth: {
    setToken: (token: string, email: string, name: string) => Promise<boolean>
    clearToken: () => Promise<boolean>
    getToken: () => Promise<string | null>
    isLoggedIn: () => Promise<boolean>
  }
  db: {
    getEmails: (folderId: number, limit?: number, offset?: number) => Promise<any[]>
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
  notification: {
    show: (title: string, body: string) => Promise<boolean>
  }
  openExternal: (url: string) => Promise<boolean>
  getVersion: () => Promise<string>
  getAppPath: (name: string) => Promise<string>
  on: (channel: string, callback: (...args: any[]) => void) => () => void
  off: (channel: string, callback?: Function) => void
  send: (channel: string, ...args: any[]) => void
  network: {
    onStatusChange: (callback: (isOnline: boolean) => void) => () => void
  }
}

declare global {
  interface Window {
    api: ElectronAPI
  }
}

export {}

