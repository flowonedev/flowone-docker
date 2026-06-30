import { EventEmitter } from 'events'
import WebSocket from 'ws'
import { configStore } from '../config'
import { getAuthToken } from '../secureStorage'
import { getNetworkMonitor } from './NetworkMonitor'

/**
 * WebSocket Client with Auto-Reconnect
 * 
 * Features:
 * - Automatic reconnection with exponential backoff
 * - Heartbeat/ping-pong for connection health
 * - Event replay after reconnection
 * - Event version tracking
 */
export class WebSocketClient extends EventEmitter {
  private ws: WebSocket | null = null
  private reconnectAttempts = 0
  private maxReconnectAttempts = 10
  private lastEventVersion = 0
  private heartbeatInterval: NodeJS.Timeout | null = null
  private lastPong = Date.now()
  private isShuttingDown = false
  private token: string | null = null

  get isConnected(): boolean {
    return this.ws?.readyState === WebSocket.OPEN
  }

  get currentVersion(): number {
    return this.lastEventVersion
  }

  /**
   * Connect to WebSocket server
   * @param token JWT authentication token
   */
  connect(token?: string): void {
    if (this.isShuttingDown) return
    
    this.token = token || getAuthToken()
    if (!this.token) {
      console.error('[WS] No auth token available')
      this.emit('auth-failed')
      return
    }

    const wsUrl = configStore.get('wsUrl') || 'wss://flowone.pro/mailsync_ws'
    const url = `${wsUrl}?token=${this.token}`
    
    console.log('[WS] Connecting to:', wsUrl)
    
    try {
      this.ws = new WebSocket(url, {
        handshakeTimeout: 10000,
      })

      this.ws.on('open', () => {
        console.log('[WS] Connected')
        this.reconnectAttempts = 0
        this.lastPong = Date.now()
        
        // Notify network monitor
        getNetworkMonitor().setWebSocketConnected(true)
        
        this.emit('connected')
        this.startHeartbeat()
        
        // Subscribe to all events for desktop app
        this.send({ type: 'SUBSCRIBE_ALL' })
        
        // Request missed events since last known version
        if (this.lastEventVersion > 0) {
          this.replayEvents()
        }
      })

      this.ws.on('message', (data: WebSocket.RawData) => {
        this.handleMessage(data)
      })

      this.ws.on('close', (code: number, reason: Buffer) => {
        console.log(`[WS] Disconnected: ${code} - ${reason.toString()}`)
        this.stopHeartbeat()
        
        // Notify network monitor
        getNetworkMonitor().setWebSocketConnected(false)
        
        this.emit('disconnected', code)
        
        // Handle specific close codes
        if (code === 4001) {
          // Unauthorized - token invalid
          console.log('[WS] Auth failed - clearing token')
          this.emit('auth-failed')
          return
        }
        
        // Schedule reconnect
        if (!this.isShuttingDown) {
          this.scheduleReconnect()
        }
      })

      this.ws.on('error', (error: Error) => {
        console.error('[WS] Error:', error.message)
        this.emit('error', error)
      })

      this.ws.on('pong', () => {
        this.lastPong = Date.now()
      })

    } catch (error) {
      console.error('[WS] Failed to create connection:', error)
      this.scheduleReconnect()
    }
  }

  /**
   * Handle incoming WebSocket message
   */
  private handleMessage(data: WebSocket.RawData): void {
    try {
      const message = JSON.parse(data.toString())
      
      // Handle pong response
      if (message.type === 'PONG') {
        this.lastPong = Date.now()
        return
      }
      
      // Track event version
      if (message.version && message.version > this.lastEventVersion) {
        this.lastEventVersion = message.version
        // Persist version for recovery after restart
        configStore.set('lastEventVersion', this.lastEventVersion)
      }
      
      // Emit the event for handlers
      this.emit('event', message)
      
      // Also emit specific event types
      if (message.type) {
        this.emit(message.type, message)
      }
    } catch (error) {
      console.error('[WS] Failed to parse message:', error)
    }
  }

  /**
   * Send message to server
   */
  send(message: object): boolean {
    if (!this.isConnected || !this.ws) {
      console.warn('[WS] Cannot send - not connected')
      return false
    }
    
    try {
      this.ws.send(JSON.stringify(message))
      return true
    } catch (error) {
      console.error('[WS] Send failed:', error)
      return false
    }
  }

  /**
   * Request replay of missed events
   */
  replayEvents(): void {
    console.log(`[WS] Requesting event replay since version ${this.lastEventVersion}`)
    this.send({
      type: 'REPLAY_EVENTS',
      sinceVersion: this.lastEventVersion,
    })
  }

  /**
   * Start heartbeat monitoring
   */
  private startHeartbeat(): void {
    // Send PING every 30 seconds
    this.heartbeatInterval = setInterval(() => {
      if (this.isConnected) {
        this.send({ type: 'PING' })
        
        // Check if server responded within timeout
        setTimeout(() => {
          const timeSinceLastPong = Date.now() - this.lastPong
          if (timeSinceLastPong > 40000) {
            console.log('[WS] Heartbeat timeout - reconnecting')
            this.ws?.close()
          }
        }, 10000)
      }
    }, 30000)
  }

  /**
   * Stop heartbeat monitoring
   */
  private stopHeartbeat(): void {
    if (this.heartbeatInterval) {
      clearInterval(this.heartbeatInterval)
      this.heartbeatInterval = null
    }
  }

  /**
   * Schedule reconnection with exponential backoff
   */
  private scheduleReconnect(): void {
    if (this.reconnectAttempts >= this.maxReconnectAttempts) {
      console.log('[WS] Max reconnect attempts reached')
      this.emit('reconnect-failed')
      return
    }
    
    // Exponential backoff: 1s, 2s, 4s, 8s, max 30s
    const delay = Math.min(1000 * Math.pow(2, this.reconnectAttempts), 30000)
    this.reconnectAttempts++
    
    console.log(`[WS] Reconnecting in ${delay}ms (attempt ${this.reconnectAttempts})`)
    
    setTimeout(() => {
      if (!this.isShuttingDown && !this.isConnected) {
        this.connect(this.token || undefined)
      }
    }, delay)
  }

  /**
   * Manually trigger reconnection
   */
  reconnect(): void {
    if (this.ws) {
      this.ws.close()
    }
    this.reconnectAttempts = 0
    this.connect(this.token || undefined)
  }

  /**
   * Disconnect from server
   */
  disconnect(): void {
    this.isShuttingDown = true
    this.stopHeartbeat()
    
    if (this.ws) {
      this.ws.close(1000, 'Client disconnect')
      this.ws = null
    }
  }

  /**
   * Shutdown and cleanup
   */
  shutdown(): void {
    this.isShuttingDown = true
    this.disconnect()
    this.removeAllListeners()
  }

  /**
   * Restore last event version from storage
   */
  restoreVersion(): void {
    const savedVersion = configStore.get('lastEventVersion') || 0
    this.lastEventVersion = savedVersion
    console.log(`[WS] Restored event version: ${this.lastEventVersion}`)
  }
}

// Singleton instance
let wsClient: WebSocketClient | null = null

export function getWebSocketClient(): WebSocketClient {
  if (!wsClient) {
    wsClient = new WebSocketClient()
    wsClient.restoreVersion()
  }
  return wsClient
}

export function shutdownWebSocketClient(): void {
  if (wsClient) {
    wsClient.shutdown()
    wsClient = null
  }
}

