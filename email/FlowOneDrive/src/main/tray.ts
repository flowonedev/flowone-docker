import { Tray, Menu, nativeImage, app, BrowserWindow, NativeImage } from 'electron'
import { setQuitting } from './state'
import path from 'path'
import fs from 'fs'
import { SyncEngine, SyncStatus } from './syncEngine'
import { ConfigStore } from './config'

export class TrayManager {
  private tray: Tray | null = null
  private mainWindow: BrowserWindow
  private syncEngine: SyncEngine
  private updateInterval: NodeJS.Timeout | null = null

  // Status icons (will be created from base icon)
  private icons: Record<SyncStatus, NativeImage> | null = null
  private baseIconPath: string

  constructor(mainWindow: BrowserWindow, syncEngine: SyncEngine) {
    this.mainWindow = mainWindow
    this.syncEngine = syncEngine
    
    const isDev = !app.isPackaged
    this.baseIconPath = isDev
      ? path.join(__dirname, '..', '..', 'assets', 'tray-icon.png')
      : path.join(process.resourcesPath, 'assets', 'tray-icon.png')

    this.createTray()
    this.startStatusUpdates()
  }

  private createTray(): void {
    let icon: NativeImage

    const isDev = !app.isPackaged
    const assetsDir = isDev
      ? path.join(__dirname, '..', '..', 'assets')
      : path.join(process.resourcesPath, 'assets')

    if (process.platform === 'darwin') {
      // macOS: use template images (monochrome, auto-adapts to light/dark menu bar)
      const tplPath = path.join(assetsDir, 'tray-iconTemplate.png')
      const tpl2xPath = path.join(assetsDir, 'tray-iconTemplate@2x.png')
      try {
        icon = nativeImage.createFromPath(tplPath)
        if (fs.existsSync(tpl2xPath)) {
          const img2x = nativeImage.createFromPath(tpl2xPath)
          if (!img2x.isEmpty()) {
            icon.addRepresentation({ scaleFactor: 2.0, buffer: img2x.toPNG() })
          }
        }
        if (!icon.isEmpty()) {
          icon.setTemplateImage(true)
        } else {
          icon = this.createDefaultIcon()
        }
      } catch {
        icon = this.createDefaultIcon()
      }
    } else {
      // Windows/Linux: use color icons
      const colorIcoPath = path.join(assetsDir, 'tray-icon.ico')
      const colorPngPath = path.join(assetsDir, 'tray-icon-color.png')
      const tryPath = fs.existsSync(colorIcoPath) ? colorIcoPath : colorPngPath
      try {
        icon = nativeImage.createFromPath(tryPath)
        if (icon.isEmpty()) icon = this.createDefaultIcon()
        icon = icon.resize({ width: 16, height: 16 })
      } catch {
        icon = this.createDefaultIcon()
      }
    }

    this.tray = new Tray(icon)
    this.tray.setToolTip('FlowOne Drive')
    
    this.updateContextMenu()

    // Double-click to show window
    this.tray.on('double-click', () => {
      this.showWindow()
    })
  }

  private createDefaultIcon(): NativeImage {
    const size = 16
    const canvas = Buffer.alloc(size * size * 4)
    const r = 3

    for (let y = 0; y < size; y++) {
      for (let x = 0; x < size; x++) {
        const i = (y * size + x) * 4
        if (this.isInsideRoundedRect(x, y, 0, 0, size, size, r)) {
          canvas[i] = 34; canvas[i + 1] = 197; canvas[i + 2] = 94; canvas[i + 3] = 255
        }
      }
    }

    return nativeImage.createFromBuffer(canvas, { width: size, height: size })
  }

  private isInsideRoundedRect(px: number, py: number, rx: number, ry: number, rw: number, rh: number, rad: number): boolean {
    if (px < rx || px >= rx + rw || py < ry || py >= ry + rh) return false
    const corners = [
      { cx: rx + rad, cy: ry + rad },
      { cx: rx + rw - rad - 1, cy: ry + rad },
      { cx: rx + rad, cy: ry + rh - rad - 1 },
      { cx: rx + rw - rad - 1, cy: ry + rh - rad - 1 },
    ]
    for (const c of corners) {
      const inCornerX = (px < rx + rad && c.cx === rx + rad) || (px >= rx + rw - rad - 1 && c.cx === rx + rw - rad - 1)
      const inCornerY = (py < ry + rad && c.cy === ry + rad) || (py >= ry + rh - rad - 1 && c.cy === ry + rh - rad - 1)
      if (inCornerX && inCornerY) {
        const dist = Math.sqrt((px - c.cx) ** 2 + (py - c.cy) ** 2)
        if (dist > rad) return false
      }
    }
    return true
  }

  private updateContextMenu(): void {
    const status = this.syncEngine.getStatus()
    const config = ConfigStore.getInstance()
    
    const contextMenu = Menu.buildFromTemplate([
      {
        label: `FlowOne Drive`,
        enabled: false,
        icon: this.createStatusIcon(status.status),
      },
      {
        label: status.message,
        enabled: false,
      },
      { type: 'separator' },
      {
        label: 'Open FlowOne Drive',
        click: () => this.showWindow(),
      },
      {
        label: 'Open Sync Folder',
        click: () => {
          const syncFolder = config.get('syncFolder')
          require('electron').shell.openPath(syncFolder)
        },
      },
      { type: 'separator' },
      {
        label: 'Sync Now',
        enabled: status.status !== 'syncing' && status.status !== 'offline',
        click: () => this.syncEngine.syncNow('tray:sync-now'),
      },
      {
        label: status.status === 'paused' ? 'Resume Sync' : 'Pause Sync',
        enabled: status.status !== 'offline',
        click: () => {
          if (status.status === 'paused') {
            this.syncEngine.resumeSync()
          } else {
            this.syncEngine.pauseSync()
          }
          this.updateContextMenu()
        },
      },
      { type: 'separator' },
      {
        label: 'Settings',
        click: () => {
          this.showWindow()
          this.mainWindow.webContents.send('navigate', '/settings')
        },
      },
      { type: 'separator' },
      {
        label: 'Quit FlowOne Drive',
        click: () => {
          setQuitting(true)
          app.quit()
        },
      },
    ])

    this.tray?.setContextMenu(contextMenu)
    this.tray?.setToolTip(`FlowOne Drive - ${status.message}`)
  }

  private createStatusIcon(status: SyncStatus): NativeImage {
    const size = 16
    const canvas = Buffer.alloc(size * size * 4)
    const r = 3

    let color: { r: number; g: number; b: number }
    switch (status) {
      case 'syncing':
        color = { r: 59, g: 130, b: 246 }
        break
      case 'idle':
        color = { r: 34, g: 197, b: 94 }
        break
      case 'paused':
        color = { r: 251, g: 191, b: 36 }
        break
      case 'error':
        color = { r: 239, g: 68, b: 68 }
        break
      case 'offline':
      default:
        color = { r: 107, g: 114, b: 128 }
    }

    for (let y = 0; y < size; y++) {
      for (let x = 0; x < size; x++) {
        const i = (y * size + x) * 4
        if (this.isInsideRoundedRect(x, y, 1, 1, 14, 14, r)) {
          canvas[i] = color.r; canvas[i + 1] = color.g; canvas[i + 2] = color.b; canvas[i + 3] = 255
        }
      }
    }

    return nativeImage.createFromBuffer(canvas, { width: size, height: size })
  }

  private showWindow(): void {
    if (this.mainWindow.isMinimized()) {
      this.mainWindow.restore()
    }
    this.mainWindow.show()
    this.mainWindow.focus()
  }

  private startStatusUpdates(): void {
    // Update tray menu every 5 seconds
    this.updateInterval = setInterval(() => {
      this.updateContextMenu()
    }, 5000)
  }

  destroy(): void {
    if (this.updateInterval) {
      clearInterval(this.updateInterval)
    }
    this.tray?.destroy()
  }
}

