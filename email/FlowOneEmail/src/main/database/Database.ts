import initSqlJs, { Database as SqlJsDatabase, Statement } from 'sql.js'
import { app } from 'electron'
import path from 'path'
import fs from 'fs'

/**
 * SQLite Database wrapper for FlowOne Email Desktop
 * Uses sql.js (pure JavaScript SQLite) for cross-platform compatibility
 */
export class LocalDatabase {
  private db: SqlJsDatabase | null = null
  private dbPath: string
  private static instance: LocalDatabase | null = null
  private saveInterval: NodeJS.Timeout | null = null

  private constructor() {
    const userDataPath = app.getPath('userData')
    this.dbPath = path.join(userDataPath, 'mailflow.db')
    console.log('[Database] DB path:', this.dbPath)
  }

  static getInstance(): LocalDatabase {
    if (!LocalDatabase.instance) {
      LocalDatabase.instance = new LocalDatabase()
    }
    return LocalDatabase.instance
  }

  async initialize(): Promise<void> {
    console.log('[Database] Initializing...')
    
    // Initialize sql.js
    const SQL = await initSqlJs()
    
    // Load existing database or create new one
    if (fs.existsSync(this.dbPath)) {
      const buffer = fs.readFileSync(this.dbPath)
      this.db = new SQL.Database(buffer)
      console.log('[Database] Loaded existing database')
    } else {
      this.db = new SQL.Database()
      console.log('[Database] Created new database')
    }
    
    // Enable foreign keys
    this.db.run('PRAGMA foreign_keys = ON')
    
    // Initialize schema
    await this.initializeSchema()
    
    // Auto-save every 30 seconds
    this.saveInterval = setInterval(() => {
      this.save()
    }, 30000)
  }

  private async initializeSchema(): Promise<void> {
    const schemaPath = path.join(__dirname, 'schema.sql')
    
    // In development, schema might be in src folder
    let schema: string
    if (fs.existsSync(schemaPath)) {
      schema = fs.readFileSync(schemaPath, 'utf-8')
    } else {
      // Fallback to bundled schema
      const devSchemaPath = path.join(__dirname, '..', '..', '..', 'src', 'main', 'database', 'schema.sql')
      if (fs.existsSync(devSchemaPath)) {
        schema = fs.readFileSync(devSchemaPath, 'utf-8')
      } else {
        console.error('[Database] Schema file not found!')
        return
      }
    }

    // Execute schema (CREATE IF NOT EXISTS makes this safe to run multiple times)
    // Must use exec() not run() -- run() only executes the first statement
    this.db!.exec(schema)
    console.log('[Database] Schema initialized')
    
    // Run migrations
    await this.runMigrations()
    
    this.save()
  }
  
  /**
   * Run database migrations for schema updates
   */
  private async runMigrations(): Promise<void> {
    // Migration 1: Fix emails UNIQUE constraint (account_id, remote_id) -> (account_id, folder_id, remote_id)
    // IMAP UIDs are only unique within a folder, not globally
    try {
      // Check if we need to migrate
      const emailsTable = this.db!.exec("SELECT sql FROM sqlite_master WHERE type='table' AND name='emails'")
      if (emailsTable.length > 0 && emailsTable[0].values.length > 0) {
        const tableSql = emailsTable[0].values[0][0] as string
        
        // Check if it has the old constraint (account_id, remote_id) without folder_id
        if (tableSql.includes('UNIQUE(account_id, remote_id)') && !tableSql.includes('UNIQUE(account_id, folder_id, remote_id)')) {
          console.log('[Database] Running migration: Fix emails UNIQUE constraint')
          
          // Disable foreign key checks temporarily
          this.db!.run('PRAGMA foreign_keys = OFF')
          
          // SQLite doesn't support ALTER TABLE to modify constraints
          // We need to recreate the table
          this.db!.run(`
            CREATE TABLE IF NOT EXISTS emails_new (
              id INTEGER PRIMARY KEY AUTOINCREMENT,
              remote_id INTEGER,
              account_id INTEGER,
              folder_id INTEGER,
              message_id TEXT,
              conversation_id TEXT,
              subject TEXT,
              from_address TEXT,
              from_name TEXT,
              to_addresses TEXT,
              cc_addresses TEXT,
              bcc_addresses TEXT,
              reply_to TEXT,
              date_sent TEXT,
              date_received TEXT,
              snippet TEXT,
              body_html TEXT,
              body_text TEXT,
              is_read INTEGER DEFAULT 0,
              is_starred INTEGER DEFAULT 0,
              is_answered INTEGER DEFAULT 0,
              is_forwarded INTEGER DEFAULT 0,
              is_draft INTEGER DEFAULT 0,
              is_queued INTEGER DEFAULT 0,
              has_attachments INTEGER DEFAULT 0,
              labels TEXT,
              headers TEXT,
              size INTEGER,
              sync_status TEXT DEFAULT 'synced',
              local_updated_at TEXT,
              remote_updated_at TEXT,
              created_at TEXT DEFAULT (datetime('now')),
              FOREIGN KEY (account_id) REFERENCES email_accounts(id),
              FOREIGN KEY (folder_id) REFERENCES email_folders(id),
              UNIQUE(account_id, folder_id, remote_id)
            )
          `)
          
          // Copy data (might have duplicates, so use INSERT OR IGNORE)
          this.db!.run(`INSERT OR IGNORE INTO emails_new SELECT * FROM emails`)
          
          // Drop attachments that reference the old emails table
          this.db!.run(`DELETE FROM email_attachments WHERE email_id NOT IN (SELECT id FROM emails_new)`)
          
          // Drop old table and rename new one
          this.db!.run(`DROP TABLE emails`)
          this.db!.run(`ALTER TABLE emails_new RENAME TO emails`)
          
          // Recreate indexes
          this.db!.run(`CREATE INDEX IF NOT EXISTS idx_emails_folder ON emails(folder_id)`)
          this.db!.run(`CREATE INDEX IF NOT EXISTS idx_emails_conversation ON emails(conversation_id)`)
          this.db!.run(`CREATE INDEX IF NOT EXISTS idx_emails_date ON emails(date_received DESC)`)
          
          // Re-enable foreign key checks
          this.db!.run('PRAGMA foreign_keys = ON')
          
          console.log('[Database] Migration complete: emails UNIQUE constraint fixed')
        }
      }
    } catch (error: any) {
      console.error('[Database] Migration failed:', error.message)
      // Try to re-enable foreign keys even on failure
      try { this.db!.run('PRAGMA foreign_keys = ON') } catch {}
    }

    // Migration 2: Add sync_enabled column to email_folders if missing
    try {
      const foldersInfo = this.db!.exec("PRAGMA table_info(email_folders)")
      if (foldersInfo.length > 0) {
        const columns = foldersInfo[0].values.map((row: any) => row[1])
        if (!columns.includes('sync_enabled')) {
          console.log('[Database] Running migration: Add sync_enabled to email_folders')
          this.db!.run('ALTER TABLE email_folders ADD COLUMN sync_enabled INTEGER DEFAULT 1')
          console.log('[Database] Migration complete: sync_enabled added')
        }
      }
    } catch (error: any) {
      console.error('[Database] Migration 2 failed:', error.message)
    }

    // Migration 3: Add sync_enabled column to email_accounts if missing
    try {
      const accountsInfo = this.db!.exec("PRAGMA table_info(email_accounts)")
      if (accountsInfo.length > 0) {
        const columns = accountsInfo[0].values.map((row: any) => row[1])
        if (!columns.includes('sync_enabled')) {
          console.log('[Database] Running migration: Add sync_enabled to email_accounts')
          this.db!.run('ALTER TABLE email_accounts ADD COLUMN sync_enabled INTEGER DEFAULT 1')
          console.log('[Database] Migration complete: sync_enabled added to accounts')
        }
      }
    } catch (error: any) {
      console.error('[Database] Migration 3 failed:', error.message)
    }

    // Migration 4: Add type and system columns to email_folders
    try {
      const foldersInfo4 = this.db!.exec("PRAGMA table_info(email_folders)")
      if (foldersInfo4.length > 0) {
        const columns4 = foldersInfo4[0].values.map((row: any) => row[1])
        if (!columns4.includes('type')) {
          console.log('[Database] Running migration 4: Add type/system to email_folders')
          this.db!.run("ALTER TABLE email_folders ADD COLUMN type TEXT DEFAULT 'user'")
          this.db!.run("ALTER TABLE email_folders ADD COLUMN system INTEGER DEFAULT 0")
          
          // Populate type and system based on folder name/path
          this.db!.run("UPDATE email_folders SET type = 'inbox', system = 1 WHERE full_path = 'INBOX'")
          this.db!.run("UPDATE email_folders SET type = 'sent', system = 1 WHERE full_path IN ('INBOX.Sent', 'Sent') OR name = 'Sent'")
          this.db!.run("UPDATE email_folders SET type = 'drafts', system = 1 WHERE full_path IN ('INBOX.Drafts', 'Drafts') OR name = 'Drafts'")
          this.db!.run("UPDATE email_folders SET type = 'trash', system = 1 WHERE full_path IN ('INBOX.Trash', 'Trash') OR name = 'Trash'")
          this.db!.run("UPDATE email_folders SET type = 'spam', system = 1 WHERE full_path IN ('INBOX.Spam', 'Spam', 'INBOX.Junk', 'Junk') OR name IN ('Spam', 'Junk')")
          this.db!.run("UPDATE email_folders SET type = 'archive', system = 1 WHERE full_path IN ('INBOX.Archive', 'Archive') OR name = 'Archive'")
          console.log('[Database] Migration 4 complete: type/system added to email_folders')
        }
      }
    } catch (error: any) {
      console.error('[Database] Migration 4 failed:', error.message)
    }

    // Migration 5: Clean corrupted from_address data in emails
    try {
      console.log('[Database] Running migration 5: Clean corrupted from_address data')
      this.db!.run("UPDATE emails SET from_address = '' WHERE typeof(from_address) != 'text' OR from_address IS NULL")
      this.db!.run("UPDATE emails SET from_address = '' WHERE from_address != '' AND from_address NOT LIKE '%@%'")
      console.log('[Database] Migration 5 complete: corrupted from_address cleaned')
    } catch (error: any) {
      console.error('[Database] Migration 5 failed:', error.message)
    }

    // Migration 6: Add highest_modseq to email_folders for CONDSTORE incremental flag sync
    try {
      const foldersInfo6 = this.db!.exec("PRAGMA table_info(email_folders)")
      if (foldersInfo6.length > 0) {
        const columns6 = foldersInfo6[0].values.map((row: any) => row[1])
        if (!columns6.includes('highest_modseq')) {
          console.log('[Database] Running migration 6: Add highest_modseq to email_folders')
          this.db!.run('ALTER TABLE email_folders ADD COLUMN highest_modseq INTEGER DEFAULT 0')
          console.log('[Database] Migration 6 complete: highest_modseq added')
        }
      }
    } catch (error: any) {
      console.error('[Database] Migration 6 failed:', error.message)
    }
  }

  private _saving = false

  /**
   * Save database to disk asynchronously to avoid blocking the main process.
   * Falls back to synchronous write only during close().
   */
  save(): void {
    if (!this.db || this._saving) return

    try {
      const data = this.db.export()
      const buffer = Buffer.from(data)
      this._saving = true
      fs.writeFile(this.dbPath, buffer, (err) => {
        this._saving = false
        if (err) console.error('[Database] Save error:', err)
      })
    } catch (error) {
      this._saving = false
      console.error('[Database] Save error:', error)
    }
  }

  /**
   * Synchronous save -- only used during shutdown to guarantee data is flushed.
   */
  private saveSync(): void {
    if (!this.db) return
    try {
      const data = this.db.export()
      const buffer = Buffer.from(data)
      fs.writeFileSync(this.dbPath, buffer)
    } catch (error) {
      console.error('[Database] SaveSync error:', error)
    }
  }

  /**
   * Run a SQL statement
   */
  run(sql: string, params?: any[]): void {
    if (!this.db) throw new Error('Database not initialized')
    this.db.run(sql, params)
  }

  /**
   * Execute SQL and return all results
   */
  all(sql: string, params?: any[]): any[] {
    if (!this.db) throw new Error('Database not initialized')
    const stmt = this.db.prepare(sql)
    if (params) stmt.bind(params)
    
    const results: any[] = []
    while (stmt.step()) {
      results.push(stmt.getAsObject())
    }
    stmt.free()
    return results
  }

  /**
   * Execute SQL and return first result
   */
  get(sql: string, params?: any[]): any | undefined {
    const results = this.all(sql, params)
    return results[0]
  }

  /**
   * Prepare a statement (for compatibility)
   */
  prepare(sql: string): PreparedStatement {
    return new PreparedStatement(this.db!, sql)
  }

  // ============================================
  // SYNC STATE METHODS
  // ============================================

  getSyncCursor(entityType: string): string | null {
    const row = this.get(
      'SELECT sync_cursor FROM sync_state WHERE entity_type = ?',
      [entityType]
    )
    return row?.sync_cursor || null
  }

  updateSyncCursor(entityType: string, cursor: string): void {
    this.run(
      'UPDATE sync_state SET sync_cursor = ?, last_sync_at = datetime("now") WHERE entity_type = ?',
      [cursor, entityType]
    )
    this.save()
  }

  getLastSyncAt(entityType: string): string | null {
    const row = this.get(
      'SELECT last_sync_at FROM sync_state WHERE entity_type = ?',
      [entityType]
    )
    return row?.last_sync_at || null
  }

  // ============================================
  // SYNC QUEUE METHODS
  // ============================================

  queueChange(entityType: string, entityId: number | null, action: string, payload: object): number {
    this.run(`
      INSERT INTO sync_queue (entity_type, entity_id, action, payload, created_at)
      VALUES (?, ?, ?, ?, datetime('now'))
    `, [entityType, entityId, action, JSON.stringify(payload)])
    
    this.save()
    return this.get('SELECT last_insert_rowid() as id')?.id || 0
  }

  getPendingChanges(): SyncQueueItem[] {
    return this.all('SELECT * FROM sync_queue ORDER BY created_at ASC')
  }

  getPendingCount(): number {
    const row = this.get('SELECT COUNT(*) as count FROM sync_queue')
    return row?.count || 0
  }

  removePendingChange(id: number): void {
    this.run('DELETE FROM sync_queue WHERE id = ?', [id])
    this.save()
  }

  markChangeAttempted(id: number, error?: string): void {
    this.run(`
      UPDATE sync_queue 
      SET attempts = attempts + 1, 
          last_error = ?, 
          last_attempt_at = datetime('now')
      WHERE id = ?
    `, [error || null, id])
    this.save()
  }

  // ============================================
  // EMAIL METHODS
  // ============================================

  getEmails(folderId: number, limit = 50, offset = 0): Email[] {
    return this.all(`
      SELECT * FROM emails 
      WHERE folder_id = ? 
      ORDER BY date_received DESC 
      LIMIT ? OFFSET ?
    `, [folderId, limit, offset])
  }

  getEmailByRemoteId(accountId: number, remoteId: number): Email | null {
    return this.get(
      'SELECT * FROM emails WHERE account_id = ? AND remote_id = ?',
      [accountId, remoteId]
    ) || null
  }

  // ============================================
  // CALENDAR METHODS
  // ============================================

  getCalendars(): Calendar[] {
    return this.all('SELECT * FROM calendars ORDER BY name')
  }

  getEvents(startDate: string, endDate: string, calendarId?: number): CalendarEvent[] {
    if (calendarId) {
      return this.all(`
        SELECT * FROM calendar_events 
        WHERE calendar_id = ? AND start_time >= ? AND start_time <= ?
        ORDER BY start_time
      `, [calendarId, startDate, endDate])
    }
    return this.all(`
      SELECT ce.*, c.name as calendar_name, c.color as calendar_color
      FROM calendar_events ce
      JOIN calendars c ON c.id = ce.calendar_id
      WHERE ce.start_time >= ? AND ce.start_time <= ?
      ORDER BY ce.start_time
    `, [startDate, endDate])
  }

  // ============================================
  // BOARDS METHODS
  // ============================================

  getBoards(): Board[] {
    return this.all('SELECT * FROM boards WHERE is_archived = 0 ORDER BY name')
  }

  getBoardWithLists(boardId: number): { board: Board; lists: BoardList[]; cards: BoardCard[] } | null {
    const board = this.get('SELECT * FROM boards WHERE id = ?', [boardId])
    if (!board) return null
    
    const lists = this.all(
      'SELECT * FROM board_lists WHERE board_id = ? ORDER BY position',
      [boardId]
    )
    
    const cards = this.all(`
      SELECT * FROM board_cards 
      WHERE list_id IN (SELECT id FROM board_lists WHERE board_id = ?)
      ORDER BY position
    `, [boardId])
    
    return { board, lists, cards }
  }

  // ============================================
  // CLIENTS METHODS
  // ============================================

  getClients(): Client[] {
    return this.all('SELECT * FROM clients WHERE is_active = 1 ORDER BY name')
  }

  // ============================================
  // SETTINGS METHODS
  // ============================================

  getSetting(key: string): string | null {
    const row = this.get('SELECT value FROM settings WHERE key = ?', [key])
    return row?.value || null
  }

  setSetting(key: string, value: string): void {
    this.run(`
      INSERT INTO settings (key, value, updated_at) VALUES (?, ?, datetime('now'))
      ON CONFLICT(key) DO UPDATE SET value = excluded.value, updated_at = excluded.updated_at
    `, [key, value])
    this.save()
  }

  // ============================================
  // DEBUG METHODS
  // ============================================

  /**
   * Get database statistics for debug view
   */
  getDebugStats(): {
    tables: { name: string; count: number }[]
    dbPath: string
    dbSize: number
    syncState: any[]
    pendingQueue: any[]
  } {
    const tables = [
      'emails', 'email_folders', 'email_accounts',
      'calendars', 'calendar_events',
      'boards', 'board_lists', 'board_cards',
      'clients', 'client_contacts', 'time_entries', 'todos',
      'colleagues', 'colleague_groups', 'colleague_group_members',
      'chat_conversations', 'chat_messages', 'chat_participants',
      'mailing_lists', 'mailing_list_contacts',
      'email_campaigns', 'email_queue',
      'call_history', 'devices', 'email_templates',
      'crm_invoices', 'crm_deals', 'crm_tags',
      'mood_boards', 'mood_board_items',
      'portal_access', 'portal_updates', 'portal_documents',
      'settings', 'sync_queue', 'sync_state'
    ]
    
    const tableCounts = tables.map(table => {
      try {
        const row = this.get(`SELECT COUNT(*) as count FROM ${table}`)
        return { name: table, count: row?.count || 0 }
      } catch (e) {
        return { name: table, count: -1 } // Table might not exist
      }
    }).filter(t => t.count >= 0)
    
    // Get database file size
    let dbSize = 0
    try {
      const stats = fs.statSync(this.dbPath)
      dbSize = stats.size
    } catch (e) {
      // File might not exist yet
    }
    
    // Get sync state
    const syncState = this.all('SELECT * FROM sync_state')
    
    // Get pending queue
    const pendingQueue = this.all('SELECT * FROM sync_queue ORDER BY created_at DESC LIMIT 50')
    
    return {
      tables: tableCounts,
      dbPath: this.dbPath,
      dbSize,
      syncState,
      pendingQueue
    }
  }

  /**
   * Get sample data from a table for debugging
   */
  getDebugTableData(tableName: string, limit = 20): { columns: string[]; rows: any[] } {
    // Whitelist of allowed tables for security
    const allowedTables = [
      'emails', 'email_folders', 'email_accounts', 'email_labels', 'email_attachments',
      'calendars', 'calendar_events', 'event_participants',
      'boards', 'board_lists', 'board_cards', 'board_labels',
      'clients', 'client_contacts', 'time_entries', 'todos',
      'colleagues', 'colleague_groups', 'colleague_group_members',
      'chat_conversations', 'chat_messages', 'chat_participants',
      'chat_message_reactions', 'chat_attachments',
      'mailing_lists', 'mailing_list_contacts',
      'email_campaigns', 'email_queue', 'email_campaign_log',
      'call_history', 'devices', 'email_templates',
      'crm_invoices', 'crm_invoice_items', 'crm_deals', 'crm_tags',
      'crm_reminders', 'crm_call_log', 'crm_meeting_notes',
      'mood_boards', 'mood_board_items', 'mood_board_connections',
      'portal_access', 'portal_updates', 'portal_documents', 'portal_comments',
      'settings', 'sync_queue', 'sync_state', 'sync_events'
    ]
    
    if (!allowedTables.includes(tableName)) {
      return { columns: [], rows: [] }
    }
    
    try {
      // Get column names
      const tableInfo = this.all(`PRAGMA table_info(${tableName})`)
      const columns = tableInfo.map((col: any) => col.name)
      
      // Get sample rows
      const rows = this.all(`SELECT * FROM ${tableName} LIMIT ?`, [limit])
      
      return { columns, rows }
    } catch (e) {
      console.error(`[Database] Debug query failed for ${tableName}:`, e)
      return { columns: [], rows: [] }
    }
  }

  /**
   * Run arbitrary SELECT query for debugging (read-only)
   */
  debugQuery(sql: string): { columns: string[]; rows: any[]; error?: string } {
    // Only allow SELECT queries
    const trimmed = sql.trim().toLowerCase()
    if (!trimmed.startsWith('select')) {
      return { columns: [], rows: [], error: 'Only SELECT queries are allowed' }
    }
    
    try {
      const stmt = this.db!.prepare(sql)
      const columns = stmt.getColumnNames()
      const rows: any[] = []
      
      while (stmt.step()) {
        rows.push(stmt.getAsObject())
      }
      stmt.free()
      
      return { columns, rows }
    } catch (e: any) {
      return { columns: [], rows: [], error: e.message }
    }
  }

  // ============================================
  // CLEANUP
  // ============================================

  close(): void {
    if (this.saveInterval) {
      clearInterval(this.saveInterval)
      this.saveInterval = null
    }
    
    if (this.db) {
      this.saveSync()
      this.db.close()
      this.db = null
    }
    
    LocalDatabase.instance = null
  }
}

/**
 * Prepared statement wrapper for compatibility with better-sqlite3 API
 */
class PreparedStatement {
  private db: SqlJsDatabase
  private sql: string

  constructor(db: SqlJsDatabase, sql: string) {
    this.db = db
    this.sql = sql
  }

  run(...params: any[]): { lastInsertRowid: number; changes: number } {
    this.db.run(this.sql, params)
    const lastId = this.db.exec('SELECT last_insert_rowid() as id')[0]?.values[0]?.[0] as number || 0
    const changes = this.db.getRowsModified()
    return { lastInsertRowid: lastId, changes }
  }

  get(...params: any[]): any | undefined {
    const stmt = this.db.prepare(this.sql)
    if (params.length) stmt.bind(params)
    
    let result: any
    if (stmt.step()) {
      result = stmt.getAsObject()
    }
    stmt.free()
    return result
  }

  all(...params: any[]): any[] {
    const stmt = this.db.prepare(this.sql)
    if (params.length) stmt.bind(params)
    
    const results: any[] = []
    while (stmt.step()) {
      results.push(stmt.getAsObject())
    }
    stmt.free()
    return results
  }
}

// Type definitions
export interface SyncQueueItem {
  id: number
  entity_type: string
  entity_id: number | null
  action: string
  payload: string
  created_at: string
  attempts: number
  last_error: string | null
  last_attempt_at: string | null
}

export interface Email {
  id: number
  remote_id: number
  account_id: number
  folder_id: number
  message_id: string
  conversation_id: string
  subject: string
  from_address: string
  from_name: string
  to_addresses: string
  cc_addresses: string
  date_sent: string
  date_received: string
  snippet: string
  body_html: string
  body_text: string
  is_read: number
  is_starred: number
  is_draft: number
  is_queued: number
  has_attachments: number
  labels: string
  sync_status: string
}

export interface Calendar {
  id: number
  remote_id: number
  name: string
  color: string
  is_default: number
  is_visible: number
}

export interface CalendarEvent {
  id: number
  remote_id: number
  calendar_id: number
  title: string
  description: string
  location: string
  start_time: string
  end_time: string
  all_day: number
  recurrence_rule: string
  color: string
  attendees: string
  sync_status: string
}

export interface Board {
  id: number
  remote_id: number
  name: string
  description: string
  color: string
  is_archived: number
  owner_email: string
}

export interface BoardList {
  id: number
  remote_id: number
  board_id: number
  name: string
  position: number
}

export interface BoardCard {
  id: number
  remote_id: number
  list_id: number
  title: string
  description: string
  position: number
  due_date: string
  labels: string
  assignees: string
}

export interface Client {
  id: number
  remote_id: number
  name: string
  email: string
  company: string
  hourly_rate: number
}

export interface TimeEntry {
  id: number
  remote_id: number
  client_id: number
  description: string
  duration_seconds: number
  started_at: string
  ended_at: string
  is_billable: number
  sync_status: string
}
