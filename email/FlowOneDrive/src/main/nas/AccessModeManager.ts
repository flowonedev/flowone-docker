/**
 * Access Mode Manager
 * 
 * Manages the current access mode (direct-nas, server-api, offline)
 * and provides methods for file operations that use the appropriate mode.
 */

import { EventEmitter } from 'events'
import axios, { AxiosInstance } from 'axios'
import { NasDiscovery, getNasDiscovery, AccessMode, NasConfig } from './NasDiscovery'
import { ConfigStore } from '../config'

export interface ConnectionConfig {
  nas: {
    enabled: boolean
    ip: string
    smb_share: string
    nfs_path: string
    user_folder: string
    direct_access_enabled: boolean
  }
  server: {
    api_url: string
    storage_type: string
    storage_source: string
  }
  sync: {
    interval_seconds: number
    conflict_strategy: string
  }
}

export class AccessModeManager extends EventEmitter {
  private nasDiscovery: NasDiscovery
  private config: ConfigStore
  private api: AxiosInstance | null = null
  private connectionConfig: ConnectionConfig | null = null
  private fallbackServerUrl: string | null = null  // Used when connectionConfig fails to load
  private initialized = false

  constructor(config: ConfigStore) {
    super()
    this.config = config
    this.nasDiscovery = getNasDiscovery()

    // Forward mode change events
    this.nasDiscovery.on('mode-changed', (data) => {
      console.log(`[AccessModeManager] Mode changed to: ${data.mode} (${data.reason})`)
      this.emit('mode-changed', data)
    })
  }

  /**
   * Set API credentials (called after login)
   */
  setCredentials(apiUrl: string, authToken: string): void {
    this.api = axios.create({
      baseURL: apiUrl,
      headers: {
        Authorization: `Bearer ${authToken}`,
        'Content-Type': 'application/json'
      },
      timeout: 10000
    })
  }

  /**
   * Initialize by fetching config from server
   */
  async initialize(): Promise<void> {
    this.emit('debug', '[AccessModeManager] initialize() called')
    
    if (!this.api) {
      console.log('[AccessModeManager] No API credentials, skipping initialization')
      this.emit('debug', '[AccessModeManager] No API - marking initialized without config')
      this.initialized = true  // Still mark as initialized (no config available)
      this.emit('initialized', null)
      return
    }

    try {
      const apiUrl = this.config.get('apiUrl')
      console.log('[AccessModeManager] Fetching connection config from server...')
      this.emit('debug', `[AccessModeManager] Fetching /drive/connection-config from ${apiUrl}`)
      
      const response = await this.api.get('/api/drive/connection-config')
      this.emit('debug', `[AccessModeManager] Got response: success=${response.data?.success}`)

      if (response.data?.success) {
        this.connectionConfig = response.data.data
        console.log('[AccessModeManager] Got connection config:', {
          nasEnabled: this.connectionConfig?.nas?.enabled,
          nasIp: this.connectionConfig?.nas?.ip,
          directAccessEnabled: this.connectionConfig?.nas?.direct_access_enabled
        })
        this.emit('debug', `[AccessModeManager] Config: nasIp=${this.connectionConfig?.nas?.ip}, enabled=${this.connectionConfig?.nas?.enabled}`)

        // Configure NAS discovery with the received config
        if (this.connectionConfig) {
          const nasConfig: NasConfig = {
            enabled: this.connectionConfig.nas.enabled,
            ip: this.connectionConfig.nas.ip,
            smbShare: this.connectionConfig.nas.smb_share,
            nfsPath: this.connectionConfig.nas.nfs_path,
            userFolder: this.connectionConfig.nas.user_folder,
            directAccessEnabled: this.connectionConfig.nas.direct_access_enabled
          }

          this.nasDiscovery.setConfig(nasConfig, this.connectionConfig.server.api_url)

          // Start monitoring network status
          const intervalMs = (this.connectionConfig.sync.interval_seconds || 30) * 1000
          this.nasDiscovery.startMonitoring(intervalMs)
        }

        this.initialized = true
        this.emit('debug', '[AccessModeManager] Initialization complete - success')
        this.emit('initialized', this.connectionConfig)
      } else {
        this.emit('debug', `[AccessModeManager] Response not successful: ${JSON.stringify(response.data)}`)
        throw new Error('Server returned success=false')
      }
    } catch (error: any) {
      console.error('[AccessModeManager] Failed to fetch connection config:', error.message)
      this.emit('debug', `[AccessModeManager] ERROR: ${error.message}`)
      
      // Even if NAS config fails, set up server URL for fallback to server-api mode
      const apiUrl = this.config.get('apiUrl')
      if (apiUrl) {
        // Store the fallback URL for getStatus()
        this.fallbackServerUrl = apiUrl
        
        // Set a minimal config so server-api mode can work
        const minimalNasConfig: NasConfig = {
          enabled: false,
          ip: '',
          smbShare: '',
          nfsPath: '',
          userFolder: '',
          directAccessEnabled: false
        }
        this.nasDiscovery.setConfig(minimalNasConfig, apiUrl)
        this.nasDiscovery.startMonitoring(30000)
        console.log('[AccessModeManager] Set up fallback server-api mode with URL:', apiUrl)
        this.emit('debug', `[AccessModeManager] Set up fallback mode with URL: ${apiUrl}`)
      }
      
      this.initialized = true
      this.emit('debug', '[AccessModeManager] Initialization complete - with error/fallback')
      this.emit('initialized', null)
    }
  }

  /**
   * Get current access mode
   */
  getCurrentMode(): AccessMode {
    return this.nasDiscovery.getCurrentMode()
  }

  /**
   * Get NAS configuration
   */
  getNasConfig(): NasConfig | null {
    return this.nasDiscovery.getNasConfig()
  }

  /**
   * Get connection configuration
   */
  getConnectionConfig(): ConnectionConfig | null {
    return this.connectionConfig
  }

  /**
   * Check if direct NAS access is currently available
   */
  isNasDirectAccessAvailable(): boolean {
    return this.nasDiscovery.getCurrentMode() === 'direct-nas'
  }

  /**
   * Get the base path for direct NAS access
   * Returns null if not available
   */
  getDirectNasBasePath(): string | null {
    return this.nasDiscovery.getDirectNasPath()
  }

  /**
   * Build full NAS path for a file
   */
  buildNasFilePath(relativePath: string): string | null {
    return this.nasDiscovery.buildNasFilePath(relativePath)
  }

  /**
   * Force a network status check
   */
  async forceCheck(): Promise<AccessMode> {
    return this.nasDiscovery.forceCheck()
  }

  /**
   * Stop monitoring and cleanup
   */
  cleanup(): void {
    this.nasDiscovery.stopMonitoring()
    this.initialized = false
    this.connectionConfig = null
  }

  /**
   * Check if initialized
   */
  isInitialized(): boolean {
    return this.initialized
  }

  /**
   * Set NAS credentials for SMB authentication
   */
  setNasCredentials(username: string, password: string): void {
    this.nasDiscovery.setCredentials(username, password)
    console.log('[AccessModeManager] NAS credentials set for user:', username)
  }

  /**
   * Clear stored NAS credentials
   */
  clearNasCredentials(): void {
    this.nasDiscovery.clearCredentials()
    console.log('[AccessModeManager] NAS credentials cleared')
  }

  /**
   * Get current NAS credentials (username only, password never exposed)
   */
  getNasCredentials(): { username: string | null; hasCredentials: boolean } {
    return this.nasDiscovery.getCredentials()
  }

  /**
   * Get status summary for UI display
   */
  getStatus(): {
    mode: AccessMode
    nasIp: string | null
    nasReachable: boolean
    serverUrl: string | null
    initialized: boolean
  } {
    const mode = this.getCurrentMode()
    const nasConfig = this.getNasConfig()

    return {
      mode,
      nasIp: nasConfig?.ip || null,
      nasReachable: mode === 'direct-nas',
      serverUrl: this.connectionConfig?.server?.api_url || this.fallbackServerUrl || null,
      initialized: this.initialized
    }
  }
}

// Factory function
export function createAccessModeManager(config: ConfigStore): AccessModeManager {
  return new AccessModeManager(config)
}

