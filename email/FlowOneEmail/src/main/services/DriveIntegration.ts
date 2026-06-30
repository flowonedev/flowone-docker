import axios, { AxiosInstance } from 'axios'
import { shell } from 'electron'
import { EventEmitter } from 'events'
import path from 'path'
import fs from 'fs'

/**
 * Drive Integration Service
 * 
 * Communicates with running FlowOneDrive app via local HTTP API
 * for file operations and sync folder access.
 * 
 * Port: 47891 (localhost only)
 */
export class DriveIntegration extends EventEmitter {
  private api: AxiosInstance
  private isAvailable = false
  private checkInterval: NodeJS.Timeout | null = null
  private syncFolder: string | null = null

  constructor() {
    super()
    
    this.api = axios.create({
      baseURL: 'http://127.0.0.1:47891',
      timeout: 2000,
    })
    
    // Start checking for FlowOneDrive availability
    this.startAvailabilityCheck()
  }

  /**
   * Check if FlowOneDrive is running
   */
  async checkAvailability(): Promise<boolean> {
    try {
      const response = await this.api.get('/status')
      this.isAvailable = response.data.running === true
      
      if (this.isAvailable) {
        this.syncFolder = response.data.syncFolder || null
        this.emit('available', response.data)
      }
      
      return this.isAvailable
    } catch {
      this.isAvailable = false
      this.syncFolder = null
      this.emit('unavailable')
      return false
    }
  }

  /**
   * Start periodic availability check
   */
  private startAvailabilityCheck(): void {
    // Check immediately
    this.checkAvailability()
    
    // Check every 30 seconds
    this.checkInterval = setInterval(() => {
      this.checkAvailability()
    }, 30000)
  }

  /**
   * Get sync status from FlowOneDrive
   */
  async getStatus(): Promise<DriveStatus | null> {
    try {
      const response = await this.api.get('/status')
      return {
        running: response.data.running,
        version: response.data.version,
        syncFolder: response.data.syncFolder,
        userEmail: response.data.userEmail,
        syncStatus: response.data.syncStatus,
      }
    } catch {
      return null
    }
  }

  /**
   * Get local path for a file by remote ID
   */
  async getFilePath(remoteId: number): Promise<FilePathResult | null> {
    if (!this.isAvailable) return null
    
    try {
      const response = await this.api.get(`/file/${remoteId}/path`)
      return {
        localPath: response.data.localPath,
        filename: response.data.filename,
        remoteId: response.data.remoteId,
        exists: fs.existsSync(response.data.localPath),
      }
    } catch {
      return null
    }
  }

  /**
   * Get local path for a folder by remote ID
   */
  async getFolderPath(remoteId: number): Promise<FolderPathResult | null> {
    if (!this.isAvailable) return null
    
    try {
      const response = await this.api.get(`/folder/${remoteId}/path`)
      return {
        localPath: response.data.localPath,
        name: response.data.name,
        remoteId: response.data.remoteId,
        exists: fs.existsSync(response.data.localPath),
      }
    } catch {
      return null
    }
  }

  /**
   * Get the sync folder path
   */
  async getSyncFolder(): Promise<string | null> {
    if (this.syncFolder) return this.syncFolder
    
    try {
      const response = await this.api.get('/sync-folder')
      this.syncFolder = response.data.syncFolder
      return this.syncFolder
    } catch {
      return null
    }
  }

  /**
   * Open a file from Drive in its default application
   */
  async openFile(remoteId: number): Promise<boolean> {
    const file = await this.getFilePath(remoteId)
    
    if (file && file.exists) {
      await shell.openPath(file.localPath)
      return true
    }
    
    return false
  }

  /**
   * Open a folder from Drive in file explorer
   */
  async openFolder(remoteId: number): Promise<boolean> {
    const folder = await this.getFolderPath(remoteId)
    
    if (folder && folder.exists) {
      await shell.openPath(folder.localPath)
      return true
    }
    
    return false
  }

  /**
   * Open the sync folder in file explorer
   */
  async openSyncFolder(): Promise<boolean> {
    const syncFolder = await this.getSyncFolder()
    
    if (syncFolder && fs.existsSync(syncFolder)) {
      await shell.openPath(syncFolder)
      return true
    }
    
    return false
  }

  /**
   * Save a file to the sync folder (will be auto-uploaded by FlowOneDrive)
   */
  async saveToSyncFolder(
    data: Buffer,
    filename: string,
    subPath?: string
  ): Promise<string | null> {
    const syncFolder = await this.getSyncFolder()
    
    if (!syncFolder) {
      console.error('[DriveIntegration] Sync folder not available')
      return null
    }
    
    try {
      const targetDir = subPath 
        ? path.join(syncFolder, subPath)
        : syncFolder
      
      // Create directory if needed
      if (!fs.existsSync(targetDir)) {
        fs.mkdirSync(targetDir, { recursive: true })
      }
      
      const filePath = path.join(targetDir, filename)
      fs.writeFileSync(filePath, data)
      
      console.log(`[DriveIntegration] Saved file to sync folder: ${filePath}`)
      return filePath
    } catch (error: any) {
      console.error('[DriveIntegration] Failed to save file:', error.message)
      return null
    }
  }

  /**
   * Trigger a sync in FlowOneDrive
   */
  async triggerSync(): Promise<boolean> {
    if (!this.isAvailable) return false
    
    try {
      await this.api.post('/sync')
      return true
    } catch {
      return false
    }
  }

  /**
   * Get recent activity from FlowOneDrive
   */
  async getActivity(limit = 20): Promise<any[]> {
    if (!this.isAvailable) return []
    
    try {
      const response = await this.api.get(`/activity?limit=${limit}`)
      return response.data.activity || []
    } catch {
      return []
    }
  }

  /**
   * Get available printers from FlowOneDrive
   */
  async getPrinters(): Promise<PrinterInfo[] | null> {
    if (!this.isAvailable) return null

    try {
      const response = await this.api.get('/printers')
      return response.data?.printers || []
    } catch {
      return null
    }
  }

  /**
   * Print a file via FlowOneDrive
   */
  async printDocument(options: PrintRequest): Promise<PrintResult> {
    if (!this.isAvailable) {
      return { success: false, printer: options.printerName, error: 'FlowOneDrive not available' }
    }

    try {
      const response = await this.api.post('/print', {
        filePath: options.filePath,
        htmlContent: options.htmlContent,
        printerName: options.printerName,
        copies: options.copies || 1,
        silent: options.silent !== false,
        duplex: options.duplex || 'default',
      })
      return response.data
    } catch (err: any) {
      return { success: false, printer: options.printerName, error: err.message }
    }
  }

  /**
   * Check if FlowOneDrive is available
   */
  get isDriveAvailable(): boolean {
    return this.isAvailable
  }

  /**
   * Shutdown
   */
  shutdown(): void {
    if (this.checkInterval) {
      clearInterval(this.checkInterval)
      this.checkInterval = null
    }
    this.removeAllListeners()
  }
}

/**
 * Drive status info
 */
interface DriveStatus {
  running: boolean
  version: string
  syncFolder: string | null
  userEmail: string | null
  syncStatus: {
    status: string
    message: string
  }
}

/**
 * File path result
 */
interface FilePathResult {
  localPath: string
  filename: string
  remoteId: number
  exists: boolean
}

/**
 * Folder path result
 */
interface FolderPathResult {
  localPath: string
  name: string
  remoteId: number
  exists: boolean
}

/**
 * Printer info from FlowOneDrive
 */
export interface PrinterInfo {
  name: string
  displayName: string
  status: number
  isDefault: boolean
  options: Record<string, string>
}

/**
 * Print request options
 */
export interface PrintRequest {
  printerName: string
  filePath?: string
  htmlContent?: string
  copies?: number
  silent?: boolean
  duplex?: string
}

/**
 * Print result
 */
export interface PrintResult {
  success: boolean
  printer: string
  error?: string
}

// Singleton instance
let driveIntegration: DriveIntegration | null = null

export function getDriveIntegration(): DriveIntegration {
  if (!driveIntegration) {
    driveIntegration = new DriveIntegration()
  }
  return driveIntegration
}

export function shutdownDriveIntegration(): void {
  if (driveIntegration) {
    driveIntegration.shutdown()
    driveIntegration = null
  }
}

