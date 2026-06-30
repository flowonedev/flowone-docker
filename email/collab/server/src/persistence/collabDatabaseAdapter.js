/**
 * Collab Database Adapter
 * 
 * Handles persistence of Y.js documents to MariaDB.
 * Stores CRDT state as binary blobs for efficient storage and retrieval.
 */

import mysql from 'mysql2/promise'
import { COLLAB_TABLES, COLLAB_PREFIX } from '../config.js'

export class CollabDatabaseAdapter {
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
      connectionLimit: 10,
      queueLimit: 0,
      enableKeepAlive: true,
      keepAliveInitialDelay: 0,
    })

    console.log(`[Database] Pool initialized for ${this.config.db.host}:${this.config.db.port}/${this.config.db.database}`)
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
   * Load document CRDT state from database
   * @param {string} documentId 
   * @returns {Promise<Uint8Array|null>}
   */
  async loadDocument(documentId) {
    const conn = await this.getConnection()
    
    try {
      const [rows] = await conn.execute(
        `SELECT crdt_state FROM ${COLLAB_TABLES.DOCUMENTS} WHERE uuid = ? AND deleted_at IS NULL`,
        [documentId]
      )

      if (rows.length === 0 || !rows[0].crdt_state) {
        return null
      }

      // Convert Buffer to Uint8Array
      const buffer = rows[0].crdt_state
      return new Uint8Array(buffer)
    } finally {
      conn.release()
    }
  }

  /**
   * Store document CRDT state to database
   * @param {string} documentId 
   * @param {Uint8Array} state 
   */
  async storeDocument(documentId, state) {
    const conn = await this.getConnection()
    
    try {
      // Convert Uint8Array to Buffer for MySQL
      const buffer = Buffer.from(state)

      // Update existing document
      const [result] = await conn.execute(
        `UPDATE ${COLLAB_TABLES.DOCUMENTS} 
         SET crdt_state = ?, updated_at = NOW() 
         WHERE uuid = ? AND deleted_at IS NULL`,
        [buffer, documentId]
      )

      if (result.affectedRows === 0) {
        console.warn(`[Database] Document not found for update: ${documentId}`)
      }
    } finally {
      conn.release()
    }
  }

  /**
   * Create a new document record
   * @param {string} uuid 
   * @param {string} ownerEmail 
   * @param {string} title 
   * @param {'document'|'presentation'} type 
   * @returns {Promise<number>}
   */
  async createDocument(uuid, ownerEmail, title, type) {
    const conn = await this.getConnection()
    
    try {
      const [result] = await conn.execute(
        `INSERT INTO ${COLLAB_TABLES.DOCUMENTS} (uuid, owner_email, title, type)
         VALUES (?, ?, ?, ?)`,
        [uuid, ownerEmail.toLowerCase(), title, type]
      )

      const documentId = result.insertId

      // Add owner permission
      await conn.execute(
        `INSERT INTO ${COLLAB_TABLES.PERMISSIONS} (document_id, user_email, role)
         VALUES (?, ?, 'owner')`,
        [documentId, ownerEmail.toLowerCase()]
      )

      return documentId
    } finally {
      conn.release()
    }
  }

  /**
   * Get document metadata
   * @param {string} documentId 
   * @returns {Promise<Object|null>}
   */
  async getDocument(documentId) {
    const conn = await this.getConnection()
    
    try {
      const [rows] = await conn.execute(
        `SELECT id, uuid, owner_email, title, type, created_at, updated_at
         FROM ${COLLAB_TABLES.DOCUMENTS}
         WHERE uuid = ? AND deleted_at IS NULL`,
        [documentId]
      )

      return rows.length > 0 ? rows[0] : null
    } finally {
      conn.release()
    }
  }

  /**
   * Check if user has permission for document
   * @param {string} documentId 
   * @param {string} userEmail 
   * @returns {Promise<string|null>}
   */
  async checkPermission(documentId, userEmail) {
    const conn = await this.getConnection()
    
    try {
      const [rows] = await conn.execute(
        `SELECT p.role
         FROM ${COLLAB_TABLES.PERMISSIONS} p
         JOIN ${COLLAB_TABLES.DOCUMENTS} d ON p.document_id = d.id
         WHERE d.uuid = ? AND p.user_email = ? AND d.deleted_at IS NULL`,
        [documentId, userEmail.toLowerCase()]
      )

      return rows.length > 0 ? rows[0].role : null
    } finally {
      conn.release()
    }
  }

  /**
   * Create a version snapshot
   * @param {string} documentId 
   * @param {Uint8Array} state 
   * @param {string} createdBy 
   * @param {string} [versionName] 
   * @returns {Promise<number>}
   */
  async createVersion(documentId, state, createdBy, versionName) {
    const conn = await this.getConnection()
    
    try {
      // Get document internal ID and next version number
      const [docRows] = await conn.execute(
        `SELECT id FROM ${COLLAB_TABLES.DOCUMENTS} WHERE uuid = ?`,
        [documentId]
      )

      if (docRows.length === 0) {
        throw new Error('Document not found')
      }

      const docInternalId = docRows[0].id

      // Get next version number
      const [versionRows] = await conn.execute(
        `SELECT COALESCE(MAX(version_number), 0) + 1 as next_version
         FROM ${COLLAB_TABLES.VERSIONS}
         WHERE document_id = ?`,
        [docInternalId]
      )

      const nextVersion = versionRows[0].next_version

      // Insert version
      const buffer = Buffer.from(state)
      const [result] = await conn.execute(
        `INSERT INTO ${COLLAB_TABLES.VERSIONS} 
         (document_id, version_number, version_name, crdt_state, created_by)
         VALUES (?, ?, ?, ?, ?)`,
        [docInternalId, nextVersion, versionName || null, buffer, createdBy.toLowerCase()]
      )

      return nextVersion
    } finally {
      conn.release()
    }
  }

  /**
   * Get version state
   * @param {string} documentId 
   * @param {number} versionNumber 
   * @returns {Promise<Uint8Array|null>}
   */
  async getVersion(documentId, versionNumber) {
    const conn = await this.getConnection()
    
    try {
      const [rows] = await conn.execute(
        `SELECT v.crdt_state
         FROM ${COLLAB_TABLES.VERSIONS} v
         JOIN ${COLLAB_TABLES.DOCUMENTS} d ON v.document_id = d.id
         WHERE d.uuid = ? AND v.version_number = ?`,
        [documentId, versionNumber]
      )

      if (rows.length === 0 || !rows[0].crdt_state) {
        return null
      }

      const buffer = rows[0].crdt_state
      return new Uint8Array(buffer)
    } finally {
      conn.release()
    }
  }

  /**
   * Soft delete a document
   * @param {string} documentId 
   * @returns {Promise<boolean>}
   */
  async deleteDocument(documentId) {
    const conn = await this.getConnection()
    
    try {
      const [result] = await conn.execute(
        `UPDATE ${COLLAB_TABLES.DOCUMENTS} 
         SET deleted_at = NOW() 
         WHERE uuid = ? AND deleted_at IS NULL`,
        [documentId]
      )

      return result.affectedRows > 0
    } finally {
      conn.release()
    }
  }

  /**
   * Update document title
   * @param {string} documentId 
   * @param {string} title 
   * @returns {Promise<boolean>}
   */
  async updateDocumentTitle(documentId, title) {
    const conn = await this.getConnection()
    
    try {
      const [result] = await conn.execute(
        `UPDATE ${COLLAB_TABLES.DOCUMENTS} 
         SET title = ?, updated_at = NOW() 
         WHERE uuid = ? AND deleted_at IS NULL`,
        [title, documentId]
      )

      return result.affectedRows > 0
    } finally {
      conn.release()
    }
  }

  /**
   * Log activity
   * @param {string} documentId 
   * @param {string} userEmail 
   * @param {string} action 
   * @param {Object} [details] 
   * @param {string} [ipAddress] 
   * @param {string} [userAgent] 
   */
  async logActivity(documentId, userEmail, action, details, ipAddress, userAgent) {
    const conn = await this.getConnection()
    
    try {
      // Get document internal ID
      const [docRows] = await conn.execute(
        `SELECT id FROM ${COLLAB_TABLES.DOCUMENTS} WHERE uuid = ?`,
        [documentId]
      )

      if (docRows.length === 0) return

      await conn.execute(
        `INSERT INTO ${COLLAB_PREFIX}activity_log 
         (document_id, user_email, action, details, ip_address, user_agent)
         VALUES (?, ?, ?, ?, ?, ?)`,
        [
          docRows[0].id,
          userEmail.toLowerCase(),
          action,
          details ? JSON.stringify(details) : null,
          ipAddress || null,
          userAgent || null,
        ]
      )
    } catch (error) {
      // Don't fail on logging errors
      console.error('[Database] Activity log error:', error)
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
      console.log('[Database] Pool closed')
    }
  }
}

