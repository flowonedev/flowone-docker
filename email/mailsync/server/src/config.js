/**
 * Mail Sync Server Configuration
 * 
 * Loads configuration from environment variables with sensible defaults.
 */

import dotenv from 'dotenv'
import path from 'path'
import fs from 'fs'
import { fileURLToPath } from 'url'

const __dirname = path.dirname(fileURLToPath(import.meta.url))

// Load .env file
dotenv.config({ path: path.join(__dirname, '..', '.env') })

/**
 * Load JWT public key for RS256 verification.
 * Only the public key is needed — this service never signs tokens.
 */
function loadJwtPublicKey() {
  const keyPath = process.env.JWT_PUBLIC_KEY_PATH || path.join(__dirname, '..', 'jwt-public.pem')
  try {
    if (fs.existsSync(keyPath)) {
      return fs.readFileSync(keyPath, 'utf8')
    }
  } catch (err) {
    console.error(`[Config] Failed to load JWT public key from ${keyPath}:`, err.message)
  }
  return null
}

const jwtPublicKey = loadJwtPublicKey()
const jwtAlgorithm = process.env.JWT_ALGORITHM || (jwtPublicKey ? 'RS256' : 'HS256')

if (jwtAlgorithm === 'RS256' && !jwtPublicKey) {
  console.error('[Config] FATAL: JWT_ALGORITHM=RS256 but no public key found. Set JWT_PUBLIC_KEY_PATH or place jwt-public.pem in the server directory.')
}

export const config = {
  // WebSocket server
  ws: {
    host: process.env.MAILSYNC_WS_HOST || '0.0.0.0',
    port: parseInt(process.env.MAILSYNC_WS_PORT || '1235', 10),
  },

  // SSL for direct WebSocket (bypassing reverse proxy)
  ssl: {
    enabled: process.env.MAILSYNC_SSL_ENABLED === 'true',
    keyPath: process.env.MAILSYNC_SSL_KEY || '/etc/letsencrypt/live/flowone.pro/privkey.pem',
    certPath: process.env.MAILSYNC_SSL_CERT || '/etc/letsencrypt/live/flowone.pro/fullchain.pem',
  },

  // JWT — RS256 (public key verify) preferred, HS256 fallback during migration
  jwt: {
    secret: process.env.JWT_SECRET || '',      // HS256 fallback (remove after migration)
    publicKey: jwtPublicKey,                     // RS256 verification key
    algorithm: jwtAlgorithm,
  },

  // IMAP settings
  imap: {
    host: process.env.IMAP_HOST || 'localhost',
    port: parseInt(process.env.IMAP_PORT || '993', 10),
    tls: process.env.IMAP_TLS !== 'false',
    tlsOptions: {
      rejectUnauthorized: process.env.IMAP_VERIFY_CERT === 'true',
    },
    // IDLE timeout (RFC 2177 recommends 29 minutes max)
    idleTimeout: parseInt(process.env.IMAP_IDLE_TIMEOUT || '1680000', 10), // 28 minutes
    // Reconnection settings
    reconnectDelay: parseInt(process.env.IMAP_RECONNECT_DELAY || '5000', 10),
    maxReconnectAttempts: parseInt(process.env.IMAP_MAX_RECONNECT_ATTEMPTS || '10', 10),
  },

  // MariaDB (for the imap_outbox drain and CONDSTORE puller credentials lookup)
  db: {
    host: process.env.DB_HOST || 'localhost',
    port: parseInt(process.env.DB_PORT || '3306', 10),
    user: process.env.DB_USER || process.env.DB_USERNAME || '',
    password: process.env.DB_PASSWORD || '',
    database: process.env.DB_NAME || process.env.DB_DATABASE || '',
    // Conservative pool: the pump is single-threaded per worker, the
    // puller does periodic queries. 5 is plenty and keeps connection
    // memory in check on dense VPSes.
    connectionLimit: parseInt(process.env.DB_POOL_SIZE || '5', 10),
  },

  // NOTE: the imap_outbox queue is drained by the PHP cron
  // backend/cron/drain-outbox.php (it needs the PHP-only credential decrypt
  // keys), so the Node worker has no outbox tunables.

  // Redis
  redis: {
    host: process.env.REDIS_HOST || '127.0.0.1',
    port: parseInt(process.env.REDIS_PORT || '6379', 10),
    password: process.env.REDIS_PASSWORD || null,
    database: parseInt(process.env.REDIS_DATABASE || '0', 10),
    prefix: 'webmail:',
    // Event store settings
    eventBufferSize: parseInt(process.env.EVENT_BUFFER_SIZE || '500', 10),
    eventBufferTtl: parseInt(process.env.EVENT_BUFFER_TTL || '86400', 10), // 24 hours
  },

  // Web Push (VAPID credentials)
  // Generate keys with: node ../../scripts/generate-vapid-keys.js
  push: {
    vapidPublicKey: process.env.VAPID_PUBLIC_KEY || '',
    vapidPrivateKey: process.env.VAPID_PRIVATE_KEY || '',
    vapidSubject: process.env.VAPID_SUBJECT || 'mailto:admin@devcon1.hu',
  },

  // Firebase Cloud Messaging (native iOS/Android push for the Capacitor apps).
  // Point FCM_SERVICE_ACCOUNT_PATH at the Firebase Admin service account JSON.
  // When unset/missing, FCM is disabled and only Web Push is used.
  fcm: {
    serviceAccountPath: process.env.FCM_SERVICE_ACCOUNT_PATH
      || path.join(__dirname, '..', 'fcm-service-account.json'),
    enabled: process.env.FCM_ENABLED !== 'false',
  },

  // APNs VoIP pushes (PushKit) for the iOS Chat app's native CallKit screen.
  // Uses token-based auth (.p8 key) — the SAME key can sign both alert and VoIP
  // pushes; the VoIP topic is the bundle id suffixed with `.voip`. When the key
  // is missing/disabled, VoIP is skipped and calls fall back to the alert push.
  apnsVoip: {
    enabled: process.env.APNS_VOIP_ENABLED !== 'false',
    keyPath: process.env.APNS_VOIP_KEY_PATH
      || path.join(__dirname, '..', 'apns-voip-key.p8'),
    keyId: process.env.APNS_VOIP_KEY_ID || '',
    teamId: process.env.APNS_VOIP_TEAM_ID || '',
    // The Chat app bundle id; VoIP topic is `${bundleId}.voip`.
    bundleId: process.env.APNS_VOIP_BUNDLE_ID || 'com.flowone.chat',
    // 'production' uses api.push.apple.com; otherwise api.sandbox.push.apple.com.
    production: process.env.APNS_VOIP_PRODUCTION !== 'false',
  },

  // Panel integration (audit logging)
  panel: {
    apiUrl: process.env.PANEL_API_URL || 'https://panel.devcon1.hu/api',
    apiKey: process.env.PANEL_API_KEY || '',
  },

  // Service identification
  service: {
    name: 'mailsync',
  },

  // Logging
  logLevel: process.env.LOG_LEVEL || 'info',

  // Performance tuning
  performance: {
    // Max IDLE connections per server instance
    maxIdleConnections: parseInt(process.env.MAX_IDLE_CONNECTIONS || '500', 10),
    // Heartbeat interval for WebSocket clients
    heartbeatInterval: parseInt(process.env.WS_HEARTBEAT_INTERVAL || '30000', 10),
    // Client timeout (no heartbeat)
    clientTimeout: parseInt(process.env.WS_CLIENT_TIMEOUT || '60000', 10),
  },
}

