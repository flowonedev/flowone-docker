/**
 * Collab Server - Main Entry Point
 * 
 * Hocuspocus WebSocket server for real-time collaborative editing.
 * Handles authentication, persistence, and presence for documents and presentations.
 */

import { Server } from '@hocuspocus/server'
import { Logger } from '@hocuspocus/extension-logger'
import { createServer as createHttpsServer } from 'https'
import { readFileSync } from 'fs'
import { WebSocketServer } from 'ws'
import { config, COLLAB_DEBOUNCE } from './config.js'
import { CollabAuthHandler } from './auth/collabAuthHandler.js'
import { CollabDatabaseAdapter } from './persistence/collabDatabaseAdapter.js'
import { CollabAwarenessHandler } from './presence/collabAwarenessHandler.js'
import { flushAndStop as flushAudit } from './services/auditLogger.js'

// Determine protocol based on SSL config
const protocol = config.ssl.enabled ? 'wss' : 'ws'

console.log('╔═══════════════════════════════════════════════════════════════╗')
console.log('║            Collab Server - Real-Time Collaboration            ║')
console.log('╠═══════════════════════════════════════════════════════════════╣')
console.log(`║  WebSocket: ${protocol}://${config.ws.host}:${config.ws.port}`)
console.log(`║  SSL:       ${config.ssl.enabled ? 'Enabled' : 'Disabled'}`)
console.log(`║  Database:  ${config.db.host}:${config.db.port}/${config.db.database}`)
console.log(`║  Log Level: ${config.logLevel}`)
console.log('╚═══════════════════════════════════════════════════════════════╝')

// Initialize handlers
const authHandler = new CollabAuthHandler(config)
const databaseAdapter = new CollabDatabaseAdapter(config)
const awarenessHandler = new CollabAwarenessHandler(config)

/**
 * Ephemeral presence-only rooms (live cursors for the OnlyOffice editor).
 * Document content lives in the Document Server, not in Yjs - these rooms
 * only carry awareness states, so nothing is loaded from or persisted to
 * the collab_documents table.
 */
const isEphemeralRoom = (documentName) => documentName.startsWith('office-file-')

// Create Hocuspocus server
const server = Server.configure({
  name: 'collab-server',
  port: config.ws.port,
  address: config.ws.host,

  // Timeout settings
  timeout: 30000,
  debounce: COLLAB_DEBOUNCE.PERSIST,
  maxDebounce: COLLAB_DEBOUNCE.PERSIST * 5,

  // Quiet mode (Logger extension handles logging)
  quiet: true,

  // Extensions
  extensions: [
    new Logger({
      log: (message) => {
        console.log(`[Hocuspocus] ${message}`)
      },
    }),
  ],

  // ============================================================
  // AUTHENTICATION
  // ============================================================

  async onAuthenticate(data) {
    const { token, documentName } = data
    console.log(`[Auth] Attempting auth for document: ${documentName}, token present: ${!!token}`)
    try {
      const result = await authHandler.authenticate({ token, documentName })
      console.log(`[Auth] Success for ${documentName}:`, result.user?.email)
      return result
    } catch (error) {
      console.error(`[Auth] Failed for ${documentName}:`, error.message)
      throw error
    }
  },

  // ============================================================
  // DOCUMENT LIFECYCLE
  // ============================================================

  async onLoadDocument(data) {
    const { documentName, document } = data

    if (isEphemeralRoom(documentName)) {
      console.log(`[LoadDocument] Ephemeral presence room (no persistence): ${documentName}`)
      return document
    }

    console.log(`[LoadDocument] Loading: ${documentName}`)

    try {
      // Load CRDT state from database
      const state = await databaseAdapter.loadDocument(documentName)

      if (state) {
        // Apply stored state to Y.js document
        const Y = await import('yjs')
        Y.applyUpdate(document, state)
        console.log(`[LoadDocument] Restored state for: ${documentName}`)
      } else {
        console.log(`[LoadDocument] New document: ${documentName}`)
      }

      return document
    } catch (error) {
      console.error(`[LoadDocument] Error loading ${documentName}:`, error)
      throw error
    }
  },

  async onStoreDocument(data) {
    const { documentName, document } = data

    if (isEphemeralRoom(documentName)) {
      return
    }

    try {
      // Encode Y.js document state
      const Y = await import('yjs')
      const state = Y.encodeStateAsUpdate(document)

      // Store to database
      await databaseAdapter.storeDocument(documentName, state)

      console.log(`[StoreDocument] Saved: ${documentName} (${state.length} bytes)`)
    } catch (error) {
      console.error(`[StoreDocument] Error saving ${documentName}:`, error)
      // Don't throw - document is still in memory and will retry
    }
  },

  // ============================================================
  // CONNECTION LIFECYCLE
  // ============================================================

  async onConnect(data) {
    const { documentName, context } = data

    console.log(`[Connect] User connected to: ${documentName}`)

    // Ephemeral rooms have no collab_documents row to attach sessions to;
    // presence travels purely via the awareness protocol.
    if (isEphemeralRoom(documentName)) {
      return
    }

    // Track session in database for presence
    if (context?.user) {
      const user = context.user
      await awarenessHandler.createSession({
        documentName,
        connectionId: `conn_${Date.now()}`,
        userEmail: user.email,
        userName: user.name,
        color: user.color,
      })
    }
  },

  async onDisconnect(data) {
    const { documentName, context } = data

    console.log(`[Disconnect] User disconnected from: ${documentName}`)

    // Remove session
    if (context?.connectionId) {
      await awarenessHandler.removeSession(context.connectionId)
    }
  },

  // ============================================================
  // STATELESS MESSAGES
  // ============================================================

  async onStateless(data) {
    const { documentName, payload, connection } = data

    try {
      const message = JSON.parse(payload)
      console.log(`[Stateless] ${documentName}:`, message.type)

      // Handle custom messages (e.g., comments, notifications)
      switch (message.type) {
        case 'ping':
          connection.sendStateless(JSON.stringify({ type: 'pong' }))
          break

        default:
          // Unknown message type, ignore
          break
      }
    } catch (error) {
      console.error(`[Stateless] Error parsing message:`, error)
    }
  },

  // ============================================================
  // ERROR HANDLING
  // ============================================================

  async onDestroy() {
    console.log('[Destroy] Server shutting down...')
    await databaseAdapter.close()
  },
})

// Graceful shutdown
process.on('SIGINT', async () => {
  console.log('\n[Shutdown] Received SIGINT, shutting down gracefully...')
  await server.destroy()
  process.exit(0)
})

process.on('SIGTERM', async () => {
  console.log('\n[Shutdown] Received SIGTERM, shutting down gracefully...')
  await flushAudit()
  await server.destroy()
  process.exit(0)
})

// Start server with optional SSL
if (config.ssl.enabled) {
  // Create HTTPS server for direct SSL WebSocket connections
  try {
    const httpsServer = createHttpsServer({
      key: readFileSync(config.ssl.keyPath),
      cert: readFileSync(config.ssl.certPath),
    })

    // Create WebSocket server without HTTP server (will handle upgrade manually)
    const wss = new WebSocketServer({ noServer: true })

    // Handle HTTP upgrade requests for WebSocket
    httpsServer.on('upgrade', (request, socket, head) => {
      wss.handleUpgrade(request, socket, head, (ws) => {
        // Pass the WebSocket connection to Hocuspocus
        server.handleConnection(ws, request)
      })
    })

    // Handle regular HTTP requests (health check)
    httpsServer.on('request', (request, response) => {
      response.writeHead(200, { 'Content-Type': 'text/plain' })
      response.end('OK')
    })

    // Start listening
    httpsServer.listen(config.ws.port, config.ws.host, () => {
      console.log(`[Server] HTTPS/WSS server listening on ${config.ws.host}:${config.ws.port}`)
    })
  } catch (err) {
    console.error('[Server] Failed to start HTTPS server:', err)
    console.error('[Server] Check SSL certificate paths in config')
    process.exit(1)
  }
} else {
  // Standard HTTP WebSocket (for use behind reverse proxy)
  server.listen()
}

