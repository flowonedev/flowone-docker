/**
 * Lazy MariaDB connection pool for the mailsync server.
 *
 * The Node worker is overwhelmingly Redis-driven, but a few lookups need the
 * source-of-truth tables directly. Today that's FcmService falling back to
 * `native_push_tokens` when the derived Redis cache (`fcm_tokens:{email}`) is
 * cold or has been evicted under memory pressure — without this, an evicted
 * cache silently drops every native push.
 *
 * One shared pool, created on first use. Returns null (and warns once) when DB
 * credentials aren't configured, so callers degrade gracefully to Redis-only.
 */

import { createPool } from 'mysql2/promise'
import { config } from '../config.js'

let pool = null
let attempted = false

export function getDbPool() {
  if (pool) return pool
  if (attempted) return null // already tried and failed/unconfigured; don't retry-spam
  attempted = true

  const { host, port, user, password, database, connectionLimit } = config.db || {}
  if (!host || !user || !database) {
    console.warn('[DB] MariaDB not configured (need DB_HOST/DB_USER/DB_NAME in .env) — DB fallbacks disabled')
    return null
  }

  try {
    pool = createPool({
      host,
      port: port || 3306,
      user,
      password: password || undefined,
      database,
      connectionLimit: connectionLimit || 5,
      waitForConnections: true,
      queueLimit: 0,
      enableKeepAlive: true,
    })
    console.log(`[DB] MariaDB pool ready (${user}@${host}:${port || 3306}/${database})`)
    return pool
  } catch (e) {
    console.error('[DB] Failed to create MariaDB pool:', e.message)
    pool = null
    return null
  }
}
