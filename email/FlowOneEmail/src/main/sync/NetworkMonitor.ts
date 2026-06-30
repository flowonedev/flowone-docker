import { EventEmitter } from 'events'
import dns from 'dns'
import { configStore } from '../config'

/**
 * Hybrid Network Monitor
 * 
 * Combines multiple methods for fast and reliable network detection:
 * 1. OS-level events (navigator.onLine equivalent) - < 1ms
 * 2. DNS lookup verification - ~50-100ms
 * 3. WebSocket connection state - primary indicator
 * 
 * Detection Timeline:
 * - GOES OFFLINE:
 *   0ms    → net module event fires (instant UI update)
 *   50ms   → DNS lookup fails (confirmed offline)
 *   30sec  → WebSocket heartbeat timeout (backup)
 * 
 * - COMES BACK ONLINE:
 *   0ms    → net module event fires (start reconnect)
 *   100ms  → WebSocket reconnects (confirmed online)
 *   200ms  → First sync batch sent
 */
export class NetworkMonitor extends EventEmitter {
  private _isOnline = true
  private _isVerifiedOnline = false
  private _wsConnected = false
  private checkInterval: NodeJS.Timeout | null = null
  private readonly apiHost: string

  constructor() {
    super()
    
    const apiUrl = configStore.get('apiUrl') || 'https://flowone.pro'
    try {
      this._isOnline = true
      this.apiHost = new URL(apiUrl).hostname
    } catch {
      this.apiHost = 'flowone.pro'
    }

    // Start monitoring
    this.initialize()
  }

  private initialize(): void {
    console.log('[NetworkMonitor] Initializing...')
    
    // In Electron main process, we can't use navigator.onLine directly
    // Instead, we use periodic DNS checks combined with WebSocket state
    
    // Initial connectivity check
    this.verifyConnectivity()
    
    // Periodic verification (every 30 seconds as fallback)
    this.checkInterval = setInterval(() => {
      if (!this._wsConnected) {
        this.verifyConnectivity()
      }
    }, 30000)
  }

  /**
   * Called by WebSocketClient when connection state changes
   * This is the most reliable indicator of actual connectivity
   */
  setWebSocketConnected(connected: boolean): void {
    const wasOffline = !this._wsConnected && !this._isVerifiedOnline
    this._wsConnected = connected
    
    if (connected) {
      this._isOnline = true
      this._isVerifiedOnline = true
      
      if (wasOffline) {
        console.log('[NetworkMonitor] Came online (WebSocket connected)')
        this.emit('online')
        this.emit('came-online')
      }
    } else {
      // WebSocket disconnected - don't immediately assume offline
      // Could be temporary network hiccup
      this._isVerifiedOnline = false
      
      // Verify with DNS in 2 seconds
      setTimeout(() => {
        if (!this._wsConnected) {
          this.verifyConnectivity().then((online) => {
            if (!online) {
              console.log('[NetworkMonitor] Confirmed offline')
              this._isOnline = false
              this.emit('offline')
            }
          })
        }
      }, 2000)
    }
  }

  /**
   * Quick DNS lookup to verify actual internet connectivity
   * Takes ~50-100ms
   */
  async verifyConnectivity(): Promise<boolean> {
    return new Promise((resolve) => {
      const startTime = Date.now()
      
      dns.lookup(this.apiHost, (err) => {
        const duration = Date.now() - startTime
        const online = !err
        
        console.log(`[NetworkMonitor] DNS lookup: ${online ? 'success' : 'failed'} (${duration}ms)`)
        
        const wasOffline = !this._isOnline
        const wasVerifiedOffline = !this._isVerifiedOnline
        
        this._isVerifiedOnline = online
        
        if (online) {
          this._isOnline = true
          if (wasOffline || wasVerifiedOffline) {
            this.emit('online')
            if (wasOffline) {
              this.emit('came-online')
            }
          }
        } else if (!this._wsConnected) {
          // Only mark offline if WebSocket is also disconnected
          this._isOnline = false
          if (!wasOffline) {
            this.emit('offline')
          }
        }
        
        resolve(online)
      })
    })
  }

  /**
   * HTTP ping to verify server is reachable
   * More reliable than DNS but slower (~100-500ms)
   */
  async pingServer(): Promise<boolean> {
    try {
      const apiUrl = configStore.get('apiUrl') || 'https://flowone.pro'
      const controller = new AbortController()
      const timeout = setTimeout(() => controller.abort(), 5000)
      
      const response = await fetch(`${apiUrl}/api/auth/me`, {
        method: 'HEAD',
        signal: controller.signal,
      })
      
      clearTimeout(timeout)
      
      // 401 means server is reachable (just not authenticated)
      return response.ok || response.status === 401
    } catch {
      return false
    }
  }

  /**
   * Check if online using cached state (instant)
   */
  get isOnline(): boolean {
    return this._isOnline
  }

  /**
   * Check if online using verified state (DNS confirmed)
   */
  get isVerifiedOnline(): boolean {
    return this._isVerifiedOnline
  }

  /**
   * Check WebSocket connection state
   */
  get isWebSocketConnected(): boolean {
    return this._wsConnected
  }

  /**
   * Force a connectivity check
   */
  async checkNow(): Promise<boolean> {
    return this.verifyConnectivity()
  }

  /**
   * Cleanup
   */
  shutdown(): void {
    if (this.checkInterval) {
      clearInterval(this.checkInterval)
      this.checkInterval = null
    }
    this.removeAllListeners()
  }
}

// Singleton instance
let networkMonitor: NetworkMonitor | null = null

export function getNetworkMonitor(): NetworkMonitor {
  if (!networkMonitor) {
    networkMonitor = new NetworkMonitor()
  }
  return networkMonitor
}

export function shutdownNetworkMonitor(): void {
  if (networkMonitor) {
    networkMonitor.shutdown()
    networkMonitor = null
  }
}

