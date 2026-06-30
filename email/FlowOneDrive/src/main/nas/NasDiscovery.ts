/**
 * NAS Discovery Service
 * 
 * Detects if NAS is directly reachable on the local network.
 * Used to determine if we can bypass the server for file operations.
 */

import net from 'net'
import { EventEmitter } from 'events'

export interface NasConfig {
  enabled: boolean
  ip: string
  smbShare: string      // Windows SMB share name
  nfsPath: string       // Linux/Mac NFS path
  userFolder: string    // User's subfolder on NAS
  directAccessEnabled: boolean
}

export type AccessMode = 'direct-nas' | 'server-api' | 'offline'

export class NasDiscovery extends EventEmitter {
  private nasConfig: NasConfig | null = null
  private serverUrl: string = ''
  private currentMode: AccessMode = 'offline'
  private checkInterval: NodeJS.Timeout | null = null
  private lastCheckTime = 0
  private readonly CHECK_COOLDOWN_MS = 5000 // Don't check more than once per 5 seconds
  
  // NAS credentials for SMB authentication
  private nasUsername: string | null = null
  private nasPassword: string | null = null

  constructor() {
    super()
  }

  /**
   * Set NAS credentials for SMB authentication
   */
  setCredentials(username: string, password: string): void {
    this.nasUsername = username
    this.nasPassword = password
    console.log('[NasDiscovery] Credentials set for user:', username)
  }

  /**
   * Clear NAS credentials
   */
  clearCredentials(): void {
    this.nasUsername = null
    this.nasPassword = null
    console.log('[NasDiscovery] Credentials cleared')
  }

  /**
   * Get credentials info (password never exposed)
   */
  getCredentials(): { username: string | null; hasCredentials: boolean } {
    return {
      username: this.nasUsername,
      hasCredentials: !!(this.nasUsername && this.nasPassword)
    }
  }

  /**
   * Get full credentials for internal use (e.g., mounting SMB)
   * Only used by SyncEngine for actual file operations
   */
  getFullCredentials(): { username: string | null; password: string | null } | null {
    if (!this.nasUsername || !this.nasPassword) {
      return null
    }
    return {
      username: this.nasUsername,
      password: this.nasPassword
    }
  }

  /**
   * Initialize with config from server
   */
  setConfig(nasConfig: NasConfig, serverUrl: string): void {
    this.nasConfig = nasConfig
    this.serverUrl = serverUrl
    console.log('[NasDiscovery] Config set:', {
      nasIp: nasConfig.ip,
      enabled: nasConfig.enabled,
      directAccessEnabled: nasConfig.directAccessEnabled,
      serverUrl
    })
  }

  /**
   * Get current NAS config
   */
  getNasConfig(): NasConfig | null {
    return this.nasConfig
  }

  /**
   * Get current access mode
   */
  getCurrentMode(): AccessMode {
    return this.currentMode
  }

  /**
   * Check if NAS is directly reachable (via SMB port on Windows)
   */
  async checkNasReachable(): Promise<boolean> {
    if (!this.nasConfig?.enabled || !this.nasConfig?.directAccessEnabled) {
      return false
    }

    return new Promise((resolve) => {
      const socket = new net.Socket()
      const timeout = 2000 // 2 second timeout

      socket.setTimeout(timeout)

      socket.on('connect', () => {
        socket.destroy()
        console.log(`[NasDiscovery] NAS reachable at ${this.nasConfig!.ip}`)
        resolve(true)
      })

      socket.on('timeout', () => {
        socket.destroy()
        console.log(`[NasDiscovery] NAS timeout at ${this.nasConfig!.ip}`)
        resolve(false)
      })

      socket.on('error', (err) => {
        socket.destroy()
        console.log(`[NasDiscovery] NAS error at ${this.nasConfig!.ip}:`, err.message)
        resolve(false)
      })

      // SMB port (445) for Windows, NFS port (2049) for Unix
      const port = process.platform === 'win32' ? 445 : 2049
      console.log(`[NasDiscovery] Checking NAS at ${this.nasConfig!.ip}:${port}`)
      socket.connect(port, this.nasConfig!.ip)
    })
  }

  /**
   * Check if server API is reachable
   */
  async checkServerReachable(): Promise<boolean> {
    if (!this.serverUrl) {
      return false
    }

    try {
      // Simple fetch with short timeout
      const controller = new AbortController()
      const timeoutId = setTimeout(() => controller.abort(), 3000)

      const url = this.serverUrl + '/api/auth/google/enabled'
      const response = await fetch(url, {
        method: 'GET',
        signal: controller.signal
      })

      clearTimeout(timeoutId)
      // Any response (even 500 or 401) means server is reachable
      return true
    } catch (err: any) {
      console.log(`[NasDiscovery] Server unreachable:`, err.message)
      return false
    }
  }

  /**
   * Determine the best access mode
   */
  async determineAccessMode(): Promise<AccessMode> {
    // Cooldown check - don't spam network checks
    const now = Date.now()
    if (now - this.lastCheckTime < this.CHECK_COOLDOWN_MS) {
      return this.currentMode
    }
    this.lastCheckTime = now

    // Step 1: Try direct NAS access (fastest)
    if (this.nasConfig?.enabled && this.nasConfig?.directAccessEnabled) {
      const nasReachable = await this.checkNasReachable()
      if (nasReachable) {
        if (this.currentMode !== 'direct-nas') {
          this.currentMode = 'direct-nas'
          this.emit('mode-changed', { mode: 'direct-nas', reason: 'NAS directly reachable' })
        }
        return 'direct-nas'
      }
    }

    // Step 2: Try server API
    const serverReachable = await this.checkServerReachable()
    if (serverReachable) {
      if (this.currentMode !== 'server-api') {
        this.currentMode = 'server-api'
        this.emit('mode-changed', { mode: 'server-api', reason: 'Using server relay' })
      }
      return 'server-api'
    }

    // Step 3: Offline
    if (this.currentMode !== 'offline') {
      this.currentMode = 'offline'
      this.emit('mode-changed', { mode: 'offline', reason: 'No network connectivity' })
    }
    return 'offline'
  }

  /**
   * Start periodic monitoring of network status
   */
  startMonitoring(intervalMs = 30000): void {
    this.stopMonitoring()

    // Initial check
    this.determineAccessMode()

    // Periodic checks
    this.checkInterval = setInterval(() => {
      this.determineAccessMode()
    }, intervalMs)

    console.log(`[NasDiscovery] Started monitoring (interval: ${intervalMs}ms)`)
  }

  /**
   * Stop monitoring
   */
  stopMonitoring(): void {
    if (this.checkInterval) {
      clearInterval(this.checkInterval)
      this.checkInterval = null
      console.log('[NasDiscovery] Stopped monitoring')
    }
  }

  /**
   * Force an immediate mode check
   */
  async forceCheck(): Promise<AccessMode> {
    this.lastCheckTime = 0 // Reset cooldown
    return this.determineAccessMode()
  }

  /**
   * Get the direct NAS path for file operations
   * Returns null if direct NAS access is not available
   */
  getDirectNasPath(): string | null {
    if (this.currentMode !== 'direct-nas' || !this.nasConfig) {
      return null
    }

    if (process.platform === 'win32') {
      // Windows: UNC path to SMB share
      // e.g., \\192.168.1.106\mailflow-drive
      return `\\\\${this.nasConfig.ip}\\${this.nasConfig.smbShare}`
    } else {
      // macOS/Linux: Assume NFS is mounted at a fixed location
      // User needs to mount NFS manually or via system config
      // e.g., /mnt/mailflow-nas or /Volumes/mailflow-drive
      return process.platform === 'darwin' 
        ? `/Volumes/${this.nasConfig.smbShare}`
        : `/mnt/mailflow-nas`
    }
  }

  /**
   * Build full path to a file on NAS
   */
  buildNasFilePath(relativePath: string): string | null {
    const basePath = this.getDirectNasPath()
    if (!basePath || !this.nasConfig) {
      return null
    }

    // Build: {basePath}/{userFolder}/{relativePath}
    const userFolder = this.nasConfig.userFolder
    
    if (process.platform === 'win32') {
      // Windows paths use backslashes
      const cleanRelative = relativePath.replace(/\//g, '\\')
      return `${basePath}\\${userFolder}\\${cleanRelative}`
    } else {
      // Unix paths use forward slashes
      const cleanRelative = relativePath.replace(/\\/g, '/')
      return `${basePath}/${userFolder}/${cleanRelative}`
    }
  }

  /**
   * Check if a specific NAS path exists and is accessible
   */
  async checkNasPathAccessible(nasPath: string): Promise<boolean> {
    const fs = await import('fs')
    try {
      await fs.promises.access(nasPath, fs.constants.R_OK)
      return true
    } catch {
      return false
    }
  }
}

// Singleton instance
let instance: NasDiscovery | null = null

export function getNasDiscovery(): NasDiscovery {
  if (!instance) {
    instance = new NasDiscovery()
  }
  return instance
}

