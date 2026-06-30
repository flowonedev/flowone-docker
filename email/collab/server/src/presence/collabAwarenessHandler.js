/**
 * Collab Awareness Handler
 * 
 * Manages presence and cursor tracking for collaborative editing.
 * Stores session information in the database for cross-server awareness.
 */

import mysql from 'mysql2/promise'
import { COLLAB_TABLES, getCollabUserColor } from '../config.js'

export class CollabAwarenessHandler {
  constructor(config) {
    this.config = config
    this.pool = null
    this.initPool()
  }

  /**
   * Initialize database connection pool
   */
  initPool() {
    this.pool = mysql.createPool({
      host: this.config.db.host,
      port: this.config.db.port,
      database: this.config.db.database,
      user: this.config.db.user,
      password: this.config.db.password,
      waitForConnections: true,
      connectionLimit: 5,
      queueLimit: 0,
    })
  }

  /**
   * Get a connection from the pool
   * @returns {Promise<mysql.PoolConnection>}
   */
  async getConnection() {
    if (!this.pool) {
      throw new Error('Database pool not initialized')
    }
    return this.pool.getConnection()
  }

  /**
   * Create a new session when a user connects
   * @param {Object} data - { documentName, connectionId, userEmail, userName, color }
   */
  async createSession(data) {
    const conn = await this.getConnection()
    
    try {
      // Get document internal ID
      const [docRows] = await conn.execute(
        `SELECT id FROM ${COLLAB_TABLES.DOCUMENTS} WHERE uuid = ?`,
        [data.documentName]
      )

      if (docRows.length === 0) {
        console.warn(`[Awareness] Document not found: ${data.documentName}`)
        return
      }

      const documentId = docRows[0].id
      const color = data.color || getCollabUserColor(data.userEmail)

      // Insert or update session
      await conn.execute(
        `INSERT INTO ${COLLAB_TABLES.SESSIONS} 
         (document_id, user_email, connection_id, color, user_name, connected_at, last_seen)
         VALUES (?, ?, ?, ?, ?, NOW(), NOW())
         ON DUPLICATE KEY UPDATE 
           user_email = VALUES(user_email),
           color = VALUES(color),
           user_name = VALUES(user_name),
           connected_at = NOW(),
           last_seen = NOW()`,
        [documentId, data.userEmail.toLowerCase(), data.connectionId, color, data.userName || null]
      )

      console.log(`[Awareness] Session created: ${data.userEmail} -> ${data.documentName}`)
    } catch (error) {
      console.error('[Awareness] Error creating session:', error)
    } finally {
      conn.release()
    }
  }

  /**
   * Remove a session when a user disconnects
   * @param {string} connectionId 
   */
  async removeSession(connectionId) {
    const conn = await this.getConnection()
    
    try {
      await conn.execute(
        `DELETE FROM ${COLLAB_TABLES.SESSIONS} WHERE connection_id = ?`,
        [connectionId]
      )

      console.log(`[Awareness] Session removed: ${connectionId}`)
    } catch (error) {
      console.error('[Awareness] Error removing session:', error)
    } finally {
      conn.release()
    }
  }

  /**
   * Update cursor position for a session
   * @param {string} connectionId 
   * @param {Object} cursor - Cursor position data
   */
  async updateCursor(connectionId, cursor) {
    const conn = await this.getConnection()
    
    try {
      await conn.execute(
        `UPDATE ${COLLAB_TABLES.SESSIONS} 
         SET cursor_position = ?, last_seen = NOW()
         WHERE connection_id = ?`,
        [JSON.stringify(cursor), connectionId]
      )
    } catch (error) {
      // Ignore cursor update errors - this is best-effort
    } finally {
      conn.release()
    }
  }

  /**
   * Get all active sessions for a document
   * @param {string} documentName 
   * @returns {Promise<Array>}
   */
  async getDocumentSessions(documentName) {
    const conn = await this.getConnection()
    
    try {
      const [rows] = await conn.execute(
        `SELECT s.user_email, s.connection_id, s.cursor_position, s.color, s.user_name, s.connected_at, s.last_seen
         FROM ${COLLAB_TABLES.SESSIONS} s
         JOIN ${COLLAB_TABLES.DOCUMENTS} d ON s.document_id = d.id
         WHERE d.uuid = ? AND s.last_seen > DATE_SUB(NOW(), INTERVAL 5 MINUTE)
         ORDER BY s.connected_at`,
        [documentName]
      )

      return rows.map(row => ({
        email: row.user_email,
        connectionId: row.connection_id,
        cursor: row.cursor_position ? JSON.parse(row.cursor_position) : null,
        color: row.color,
        name: row.user_name || row.user_email.split('@')[0],
        connectedAt: row.connected_at,
        lastSeen: row.last_seen,
      }))
    } finally {
      conn.release()
    }
  }

  /**
   * Heartbeat to keep session alive
   * @param {string} connectionId 
   */
  async heartbeat(connectionId) {
    const conn = await this.getConnection()
    
    try {
      await conn.execute(
        `UPDATE ${COLLAB_TABLES.SESSIONS} SET last_seen = NOW() WHERE connection_id = ?`,
        [connectionId]
      )
    } catch (error) {
      // Ignore heartbeat errors
    } finally {
      conn.release()
    }
  }

  /**
   * Cleanup expired sessions (older than 5 minutes)
   * @returns {Promise<number>}
   */
  async cleanupExpiredSessions() {
    const conn = await this.getConnection()
    
    try {
      const [result] = await conn.execute(
        `DELETE FROM ${COLLAB_TABLES.SESSIONS} WHERE last_seen < DATE_SUB(NOW(), INTERVAL 5 MINUTE)`
      )

      if (result.affectedRows > 0) {
        console.log(`[Awareness] Cleaned up ${result.affectedRows} expired sessions`)
      }

      return result.affectedRows
    } finally {
      conn.release()
    }
  }

  /**
   * Get count of active users for a document
   * @param {string} documentName 
   * @returns {Promise<number>}
   */
  async getActiveUserCount(documentName) {
    const conn = await this.getConnection()
    
    try {
      const [rows] = await conn.execute(
        `SELECT COUNT(DISTINCT s.user_email) as count
         FROM ${COLLAB_TABLES.SESSIONS} s
         JOIN ${COLLAB_TABLES.DOCUMENTS} d ON s.document_id = d.id
         WHERE d.uuid = ? AND s.last_seen > DATE_SUB(NOW(), INTERVAL 5 MINUTE)`,
        [documentName]
      )

      return rows[0]?.count || 0
    } finally {
      conn.release()
    }
  }

  /**
   * Close database pool
   */
  async close() {
    if (this.pool) {
      await this.pool.end()
      this.pool = null
    }
  }
}

