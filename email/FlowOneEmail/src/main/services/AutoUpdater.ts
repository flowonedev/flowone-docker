import { autoUpdater, UpdateInfo } from 'electron-updater'
import { BrowserWindow, dialog, Notification } from 'electron'
import { EventEmitter } from 'events'
import { configStore } from '../config'

/**
 * Auto-Update Service
 * 
 * Handles checking for updates, downloading, and installing.
 * Uses electron-updater with your update server.
 */
export class AutoUpdater extends EventEmitter {
  private mainWindow: BrowserWindow | null = null
  private isCheckingForUpdate = false
  private updateAvailable = false
  private downloadProgress = 0

  constructor() {
    super()
    this.setupAutoUpdater()
  }

  /**
   * Initialize with main window reference
   */
  setMainWindow(window: BrowserWindow): void {
    this.mainWindow = window
  }

  /**
   * Setup auto-updater event handlers
   */
  private setupAutoUpdater(): void {
    // Configure updater
    autoUpdater.autoDownload = false
    autoUpdater.autoInstallOnAppQuit = true
    
    // Checking for updates
    autoUpdater.on('checking-for-update', () => {
      console.log('[AutoUpdater] Checking for update...')
      this.isCheckingForUpdate = true
      this.emit('checking')
    })

    // Update available
    autoUpdater.on('update-available', (info: UpdateInfo) => {
      console.log('[AutoUpdater] Update available:', info.version)
      this.isCheckingForUpdate = false
      this.updateAvailable = true
      this.emit('update-available', info)
      
      // Show notification
      if (configStore.get('notificationsEnabled')) {
        new Notification({
          title: 'Update Available',
          body: `Version ${info.version} is available. Click to download.`,
        }).show()
      }
      
      // Notify renderer
      this.mainWindow?.webContents.send('update-available', info)
    })

    // No update available
    autoUpdater.on('update-not-available', (info: UpdateInfo) => {
      console.log('[AutoUpdater] No update available. Current version:', info.version)
      this.isCheckingForUpdate = false
      this.emit('update-not-available', info)
    })

    // Download progress
    autoUpdater.on('download-progress', (progress: { percent: number; bytesPerSecond: number; total: number; transferred: number }) => {
      this.downloadProgress = progress.percent
      console.log(`[AutoUpdater] Download progress: ${progress.percent.toFixed(1)}%`)
      
      // Update taskbar progress on Windows
      this.mainWindow?.setProgressBar(progress.percent / 100)
      
      this.emit('download-progress', progress)
      this.mainWindow?.webContents.send('update-progress', progress)
    })

    // Update downloaded
    autoUpdater.on('update-downloaded', (info: UpdateInfo) => {
      console.log('[AutoUpdater] Update downloaded:', info.version)
      this.downloadProgress = 100
      
      // Clear taskbar progress
      this.mainWindow?.setProgressBar(-1)
      
      this.emit('update-downloaded', info)
      this.mainWindow?.webContents.send('update-downloaded', info)
      
      // Show notification
      if (configStore.get('notificationsEnabled')) {
        new Notification({
          title: 'Update Ready',
          body: `Version ${info.version} has been downloaded. Restart to install.`,
        }).show()
      }
      
      // Ask user to restart
      this.promptRestart(info.version)
    })

    // Error
    autoUpdater.on('error', (error: Error) => {
      console.error('[AutoUpdater] Error:', error.message)
      this.isCheckingForUpdate = false
      
      // Clear taskbar progress
      this.mainWindow?.setProgressBar(-1)
      
      this.emit('error', error)
    })
  }

  /**
   * Check for updates
   */
  async checkForUpdates(): Promise<void> {
    if (this.isCheckingForUpdate) {
      console.log('[AutoUpdater] Already checking for updates')
      return
    }
    
    try {
      await autoUpdater.checkForUpdates()
    } catch (error: any) {
      console.error('[AutoUpdater] Check failed:', error.message)
    }
  }

  /**
   * Download the update
   */
  async downloadUpdate(): Promise<void> {
    if (!this.updateAvailable) {
      console.log('[AutoUpdater] No update available to download')
      return
    }
    
    try {
      await autoUpdater.downloadUpdate()
    } catch (error: any) {
      console.error('[AutoUpdater] Download failed:', error.message)
    }
  }

  /**
   * Install and restart
   */
  quitAndInstall(): void {
    autoUpdater.quitAndInstall(false, true)
  }

  /**
   * Prompt user to restart
   */
  private async promptRestart(version: string): Promise<void> {
    const result = await dialog.showMessageBox(this.mainWindow!, {
      type: 'info',
      title: 'Update Ready',
      message: `Version ${version} has been downloaded.`,
      detail: 'Would you like to restart now to install the update?',
      buttons: ['Restart Now', 'Later'],
      defaultId: 0,
      cancelId: 1,
    })
    
    if (result.response === 0) {
      this.quitAndInstall()
    }
  }

  /**
   * Get current status
   */
  getStatus(): UpdateStatus {
    return {
      isChecking: this.isCheckingForUpdate,
      updateAvailable: this.updateAvailable,
      downloadProgress: this.downloadProgress,
    }
  }
}

interface UpdateStatus {
  isChecking: boolean
  updateAvailable: boolean
  downloadProgress: number
}

// Singleton
let autoUpdaterInstance: AutoUpdater | null = null

export function getAutoUpdater(): AutoUpdater {
  if (!autoUpdaterInstance) {
    autoUpdaterInstance = new AutoUpdater()
  }
  return autoUpdaterInstance
}

