/**
 * Collab Server Configuration
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

// Re-export shared constants
export {
  COLLAB_PREFIX,
  COLLAB_TABLES,
  COLLAB_DOC_TYPES,
  COLLAB_ROLES,
  COLLAB_ROLE_CAPABILITIES,
  COLLAB_COLORS,
  COLLAB_DEBOUNCE,
  COLLAB_YDOC_FIELDS,
  getCollabUserColor,
} from '../../shared/collabConstants.js'

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

// Server-specific configuration
export const config = {
  // WebSocket server
  ws: {
    host: process.env.COLLAB_WS_HOST || '0.0.0.0',
    port: parseInt(process.env.COLLAB_WS_PORT || '1234', 10),
  },

  // SSL for direct WebSocket (bypassing reverse proxy)
  ssl: {
    enabled: process.env.COLLAB_SSL_ENABLED === 'true',
    keyPath: process.env.COLLAB_SSL_KEY || '/etc/letsencrypt/live/flowone.pro/privkey.pem',
    certPath: process.env.COLLAB_SSL_CERT || '/etc/letsencrypt/live/flowone.pro/fullchain.pem',
  },

  // Database
  db: {
    host: process.env.DB_HOST || '127.0.0.1',
    port: parseInt(process.env.DB_PORT || '3306', 10),
    database: process.env.DB_NAME || 'devc_vps_dash',
    user: process.env.DB_USER || 'vpsadmin',
    password: process.env.DB_PASS || '',
  },

  // JWT — RS256 (public key verify) preferred, HS256 fallback during migration
  jwt: {
    secret: process.env.JWT_SECRET || 'change-me-in-production', // HS256 fallback (remove after migration)
    publicKey: jwtPublicKey,                                       // RS256 verification key
    algorithm: jwtAlgorithm,
  },

  // PHP Backend
  phpBackendUrl: process.env.PHP_BACKEND_URL || 'http://localhost/api',

  // Redis (optional, for scaling)
  redis: process.env.REDIS_HOST ? {
    host: process.env.REDIS_HOST,
    port: parseInt(process.env.REDIS_PORT || '6379', 10),
    password: process.env.REDIS_PASSWORD || undefined,
  } : null,

  // Logging
  logLevel: process.env.LOG_LEVEL || 'info',
}

