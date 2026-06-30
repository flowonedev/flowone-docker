import { BaseSyncEngine, QueueItem, SyncEvent } from '../BaseSyncEngine'
import { LocalDatabase, Email } from '../../database/Database'

/**
 * Email Sync Engine
 * 
 * Handles synchronization of email data:
 * - Folders and folder counts
 * - Email headers (subject, from, date, snippet)
 * - Full message body (on demand)
 * - Attachments metadata
 * - Read/starred/deleted flags
 * 
 * Sync Strategy:
 * - Initial sync: Fetch last 30 days of headers per folder
 * - Incremental: Use WebSocket events + periodic delta poll
 * - Body/attachments: Fetch on-demand when user opens email
 */
export class EmailSyncEngine extends BaseSyncEngine {
  entityType = 'email'

  private syncingFolders = new Set<string>()

  constructor(db: LocalDatabase) {
    super(db)
  }

  /**
   * Pull changes from server
   */
  async pullChanges(): Promise<void> {
    console.log('[EmailSync] Pulling changes...')

    // 1. Sync email accounts
    await this.syncAccounts()

    // 2. Sync folder list
    await this.syncFolders()

    // 3. Sync each folder
    const folders = this.db.prepare(
      'SELECT * FROM email_folders ORDER BY name'
    ).all() as any[]

    console.log(`[EmailSync] Found ${folders.length} folders to sync`)

    for (const folder of folders) {
      await this.syncFolder(folder.id, folder.full_path)
    }

    // 4. Pre-fetch bodies for recent emails (offline reading)
    await this.prefetchRecentBodies()
  }

  /**
   * Sync email accounts from server
   */
  private async syncAccounts(): Promise<void> {
    try {
      const response = await this.api.get('/accounts')
      
      if (response.data.success && response.data.data) {
        const accounts = Array.isArray(response.data.data)
          ? response.data.data
          : response.data.data.accounts || []
        
        for (const account of accounts) {
          this.db.prepare(`
            INSERT INTO email_accounts (
              remote_id, email, display_name, is_primary, is_oauth, provider, sync_enabled
            ) VALUES (?, ?, ?, ?, ?, ?, ?)
            ON CONFLICT(remote_id) DO UPDATE SET
              email = excluded.email,
              display_name = excluded.display_name,
              is_primary = excluded.is_primary,
              is_oauth = excluded.is_oauth,
              provider = excluded.provider,
              sync_enabled = excluded.sync_enabled
          `).run(
            account.id,
            account.email || account.address || '',
            account.display_name || account.name || null,
            account.is_primary || account.is_default ? 1 : 0,
            account.is_oauth ? 1 : 0,
            account.provider || account.type || 'imap',
            account.sync_enabled !== undefined ? (account.sync_enabled ? 1 : 0) : 1
          )
        }
        
        console.log(`[EmailSync] Synced ${accounts.length} email accounts`)
      }
    } catch (error: any) {
      console.error('[EmailSync] Failed to sync accounts:', error.message)
    }
  }

  /**
   * Pre-fetch email bodies for recent emails (for offline reading)
   * Fetches bodies for emails in the last 7 days that don't have bodies yet
   */
  private async prefetchRecentBodies(): Promise<void> {
    try {
      // Get recent emails without bodies (last 7 days, max 100)
      const emailsNeedingBodies = this.db.prepare(`
        SELECT e.id, e.remote_id as uid, f.full_path as folder
        FROM emails e
        JOIN email_folders f ON f.id = e.folder_id
        WHERE e.body_html IS NULL AND e.body_text IS NULL
          AND e.date_received > datetime('now', '-7 days')
        ORDER BY e.date_received DESC
        LIMIT 100
      `).all() as { id: number; uid: number; folder: string }[]

      if (emailsNeedingBodies.length === 0) {
        console.log('[EmailSync] All recent emails have bodies cached')
        return
      }

      console.log(`[EmailSync] Pre-fetching ${emailsNeedingBodies.length} email bodies...`)

      // Group by folder for batch fetching
      const byFolder = new Map<string, { id: number; uid: number }[]>()
      for (const email of emailsNeedingBodies) {
        if (!byFolder.has(email.folder)) {
          byFolder.set(email.folder, [])
        }
        byFolder.get(email.folder)!.push({ id: email.id, uid: email.uid })
      }

      // Fetch bodies in batches per folder
      for (const [folder, emails] of byFolder) {
        await this.fetchBodiesForFolder(folder, emails)
      }

      console.log('[EmailSync] Pre-fetch complete')
    } catch (error: any) {
      console.error('[EmailSync] Failed to pre-fetch bodies:', error.message)
      // Don't throw - this is optional optimization
    }
  }

  /**
   * Fetch bodies for emails in a folder (batch)
   */
  private async fetchBodiesForFolder(folder: string, emails: { id: number; uid: number }[]): Promise<void> {
    try {
      const uids = emails.map(e => e.uid)

      // Use batch endpoint
      const response = await this.api.post(
        `/mailbox/${encodeURIComponent(folder)}/messages/batch`,
        { uids }
      )

      if (response.data.success && response.data.data?.messages) {
        const fetchedMessages = response.data.data.messages

        for (const email of emails) {
          const msgData = fetchedMessages[email.uid]
          if (msgData && (msgData.body_html || msgData.body_text)) {
            // Cache the body
            this.db.prepare(`
              UPDATE emails SET body_html = ?, body_text = ? WHERE id = ?
            `).run(msgData.body_html || '', msgData.body_text || '', email.id)
          }
        }

        console.log(`[EmailSync] Cached ${emails.length} bodies for ${folder}`)
      }
    } catch (error: any) {
      console.error(`[EmailSync] Failed to fetch bodies for ${folder}:`, error.message)
    }
  }

  /**
   * Sync folder list
   */
  private async syncFolders(): Promise<void> {
    try {
      console.log('[EmailSync] Fetching folders from server...')
      const response = await this.api.get('/mailbox/folders')

      // API returns { success: true, data: { folders: [...] } }
      const remoteFolders = response.data?.data?.folders || response.data?.data || []

      if (!Array.isArray(remoteFolders) || remoteFolders.length === 0) {
        console.warn('[EmailSync] No folders received from server')
        return
      }

      console.log(`[EmailSync] Received ${remoteFolders.length} folders from server`)

      for (const folder of remoteFolders) {
        this.db.prepare(`
          INSERT INTO email_folders (remote_id, account_id, name, full_path, flags, unread_count, total_count)
          VALUES (?, ?, ?, ?, ?, ?, ?)
          ON CONFLICT(account_id, full_path) DO UPDATE SET
            name = excluded.name,
            flags = excluded.flags,
            unread_count = excluded.unread_count,
            total_count = excluded.total_count,
            last_sync_at = datetime('now')
        `).run(
          folder.id || null,
          folder.account_id || 1,
          folder.name,
          folder.path || folder.name,
          JSON.stringify(folder.flags || []),
          folder.unread || 0,
          folder.total || 0
        )
      }

      console.log(`[EmailSync] Synced ${remoteFolders.length} folders to local DB`)
    } catch (error: any) {
      console.error('[EmailSync] Failed to sync folders:', error.message)
      throw error
    }
  }

  /**
   * Sync emails in a specific folder
   */
  private async syncFolder(folderId: number, folderPath: string): Promise<void> {
    if (this.syncingFolders.has(folderPath)) {
      console.log(`[EmailSync] Already syncing folder: ${folderPath}`)
      return
    }

    this.syncingFolders.add(folderPath)
    console.log(`[EmailSync] Syncing folder: ${folderPath}`)

    try {
      const cursor = this.db.prepare(
        'SELECT last_uid, uidvalidity, highest_modseq FROM email_folders WHERE id = ?'
      ).get(folderId) as { last_uid: number; uidvalidity: number; highest_modseq: number } | undefined

      const lastUid = cursor?.last_uid || 0
      const storedUidvalidity = cursor?.uidvalidity || 0
      const storedModseq = cursor?.highest_modseq || 0
      console.log(`[EmailSync] ${folderPath}: last_uid=${lastUid}, modseq=${storedModseq}`)

      let messages: any[] = []
      let serverModseq = 0

      if (lastUid > 0) {
        // Incremental sync via delta endpoint (includes CONDSTORE data)
        const response = await this.api.get(
          `/mailbox/${encodeURIComponent(folderPath)}/delta`,
          {
            params: {
              since_uid: lastUid,
              since_uidvalidity: storedUidvalidity,
              since_modseq: storedModseq,
            }
          }
        )
        
        const data = response.data?.data || {}
        
        // Check UIDVALIDITY
        if (data.uidvalidityChanged) {
          console.log(`[EmailSync] UIDVALIDITY changed for ${folderPath}, purging local cache`)
          this.db.prepare('DELETE FROM emails WHERE folder_id = ?').run(folderId)
          this.db.prepare(
            'UPDATE email_folders SET last_uid = 0, uidvalidity = ?, highest_modseq = 0 WHERE id = ?'
          ).run(data.uidvalidity || 0, folderId)
          // Full resync
          const fullResponse = await this.api.get(`/mailbox/${encodeURIComponent(folderPath)}/messages`, {
            params: { page: 1, limit: 100 }
          })
          messages = fullResponse.data?.data?.messages || []
        } else {
          messages = data.newMessages || []
          serverModseq = data.highest_modseq || 0
          
          // Apply flag changes from CONDSTORE (O(changes) instead of O(mailbox))
          const flagChanges = data.flagChanges || []
          if (flagChanges.length > 0) {
            console.log(`[EmailSync] Applying ${flagChanges.length} flag changes for ${folderPath}`)
            for (const change of flagChanges) {
              this.db.prepare(`
                UPDATE emails SET
                  is_read = ?, is_starred = ?, is_answered = ?,
                  local_updated_at = datetime('now')
                WHERE remote_id = ? AND folder_id = ?
              `).run(
                change.seen ? 1 : 0,
                change.flagged ? 1 : 0,
                change.answered ? 1 : 0,
                change.uid,
                folderId
              )
            }
          }
          
          // Update sync metadata
          if (data.uidvalidity) {
            this.db.prepare('UPDATE email_folders SET uidvalidity = ? WHERE id = ?')
              .run(data.uidvalidity, folderId)
          }
        }
      } else {
        // Initial sync: fetch latest page
        const response = await this.api.get(`/mailbox/${encodeURIComponent(folderPath)}/messages`, {
          params: { page: 1, limit: 100 }
        })
        messages = response.data?.data?.messages || []
      }

      console.log(`[EmailSync] Received ${messages.length} messages for ${folderPath}`)

      if (messages.length > 0) {
        let maxUid = lastUid
        let successCount = 0

        for (const msg of messages) {
          try {
            this.upsertEmail(folderId, msg)
            successCount++

            if (msg.uid > maxUid) {
              maxUid = msg.uid
            }
          } catch (insertError: any) {
            console.error(`[EmailSync] Failed to insert email ${msg.uid}:`, insertError?.message || insertError)
          }
        }

        if (maxUid > lastUid) {
          this.db.prepare(
            'UPDATE email_folders SET last_uid = ?, last_sync_at = datetime("now") WHERE id = ?'
          ).run(maxUid, folderId)
        }

        console.log(`[EmailSync] Synced ${successCount}/${messages.length} emails in ${folderPath}`)
      } else {
        console.log(`[EmailSync] No new messages in ${folderPath}`)
      }
      
      // Store highest modseq for next CONDSTORE sync
      if (serverModseq > storedModseq) {
        this.db.prepare('UPDATE email_folders SET highest_modseq = ? WHERE id = ?')
          .run(serverModseq, folderId)
      }
    } catch (error: any) {
      console.error(`[EmailSync] Failed to sync folder ${folderPath}:`, error?.message || error)
    } finally {
      this.syncingFolders.delete(folderPath)
    }
  }

  /**
   * Insert or update an email in local DB
   * Note: UID is only unique per folder, so we use (folder_id, remote_id) for conflict detection
   */
  private upsertEmail(folderId: number, email: any): void {
    // Helper to convert undefined to null (sql.js can't handle undefined)
    const n = (val: any) => val === undefined ? null : val

    // Extract from_address and from_name from various API formats:
    // API returns: from: [{name: '...', email: '...'}], from_email: '...', from_name: '...'
    let fromAddress = ''
    let fromName = ''
    if (Array.isArray(email.from) && email.from.length > 0) {
      fromAddress = email.from[0].email || ''
      fromName = email.from[0].name || ''
    } else if (typeof email.from === 'object' && email.from !== null) {
      fromAddress = email.from.email || email.from.address || ''
      fromName = email.from.name || ''
    } else if (typeof email.from === 'string') {
      fromAddress = email.from
    }
    if (!fromAddress && email.from_email) fromAddress = email.from_email
    if (!fromName && email.from_name) fromName = email.from_name

    // Compute date for storage
    let dateSent = email.date || ''
    if (!dateSent && email.timestamp) {
      dateSent = new Date(email.timestamp * 1000).toISOString()
    }
    const dateReceived = email.internal_date || dateSent

    // First try to find existing email by folder_id and remote_id (UID)
    const existing = this.db.prepare(
      'SELECT id FROM emails WHERE folder_id = ? AND remote_id = ?'
    ).get(folderId, email.uid) as { id: number } | undefined

    if (existing) {
      // Update existing (also fix from fields if they were previously cached wrong)
      this.db.prepare(`
        UPDATE emails SET
          subject = ?, snippet = ?, is_read = ?, is_starred = ?, is_answered = ?,
          is_forwarded = ?, has_attachments = ?, labels = ?, sync_status = 'synced',
          from_address = COALESCE(NULLIF(?, ''), from_address),
          from_name = COALESCE(NULLIF(?, ''), from_name),
          date_sent = COALESCE(NULLIF(?, ''), date_sent),
          remote_updated_at = ?
        WHERE id = ?
      `).run(
        n(email.subject) || '',
        n(email.snippet || email.preview) || '',
        email.is_read || email.seen ? 1 : 0,
        email.is_starred || email.flagged ? 1 : 0,
        email.is_answered || email.answered ? 1 : 0,
        email.is_forwarded ? 1 : 0,
        email.has_attachments ? 1 : 0,
        JSON.stringify(email.labels || []),
        fromAddress,
        fromName,
        dateSent,
        n(email.updated_at),
        existing.id
      )
    } else {
      // Insert new - convert all undefined to null
      this.db.prepare(`
        INSERT INTO emails (
          remote_id, account_id, folder_id, message_id, conversation_id,
          subject, from_address, from_name, to_addresses, cc_addresses,
          date_sent, date_received, snippet,
          is_read, is_starred, is_answered, is_forwarded, is_draft,
          has_attachments, labels, size, sync_status, remote_updated_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
      `).run(
        n(email.uid),
        n(email.account_id) || 1,
        folderId,
        n(email.message_id),
        n(email.conversation_id || email.thread_id),
        n(email.subject) || '',
        fromAddress || null,
        fromName || null,
        JSON.stringify(email.to || []),
        JSON.stringify(email.cc || []),
        dateSent || null,
        dateReceived || null,
        n(email.snippet || email.preview) || '',
        email.is_read || email.seen ? 1 : 0,
        email.is_starred || email.flagged ? 1 : 0,
        email.is_answered || email.answered ? 1 : 0,
        email.is_forwarded ? 1 : 0,
        email.is_draft || email.draft ? 1 : 0,
        email.has_attachments ? 1 : 0,
        JSON.stringify(email.labels || []),
        n(email.size) || 0,
        'synced',
        n(email.updated_at)
      )
    }
  }

  /**
   * Push a queued change to server
   */
  async pushChange(queueItem: QueueItem): Promise<void> {
    const payload = JSON.parse(queueItem.payload)

    switch (queueItem.action) {
      case 'send':
        await this.sendQueuedEmail(payload)
        break

      case 'mark_read':
        await this.api.post(`/mailbox/${payload.folder}/messages/${payload.uid}/read`)
        break

      case 'mark_unread':
        await this.api.post(`/mailbox/${payload.folder}/messages/${payload.uid}/unread`)
        break

      case 'star':
        await this.api.post(`/mailbox/${payload.folder}/messages/${payload.uid}/star`)
        break

      case 'unstar':
        await this.api.post(`/mailbox/${payload.folder}/messages/${payload.uid}/unstar`)
        break

      case 'delete':
        await this.api.delete(`/mailbox/${payload.folder}/messages/${payload.uid}`)
        break

      case 'move':
        await this.api.post(`/mailbox/${payload.folder}/messages/${payload.uid}/move`, {
          target: payload.destination
        })
        break

      case 'save_draft':
        await this.api.post('/messages/draft', payload.email)
        break

      default:
        console.warn(`[EmailSync] Unknown action: ${queueItem.action}`)
    }
  }

  /**
   * Send a queued email
   */
  private async sendQueuedEmail(payload: any): Promise<void> {
    const response = await this.api.post('/messages/send', payload)

    if (response.data.success) {
      // Update local email to mark as sent
      if (payload.localId) {
        this.db.prepare(`
          UPDATE emails SET is_queued = 0, sync_status = 'synced'
          WHERE id = ?
        `).run(payload.localId)
      }
    }
  }

  /**
   * Handle incoming WebSocket event
   */
  async handleEvent(event: SyncEvent): Promise<void> {
    console.log(`[EmailSync] Handling event: ${event.type}`)

    switch (event.type) {
      case 'MESSAGE_NEW':
        await this.handleNewMessage(event.payload)
        break

      case 'MESSAGE_DELETED':
        await this.handleDeletedMessage(event.payload)
        break

      case 'MESSAGE_MOVED':
        await this.handleMovedMessage(event.payload)
        break

      case 'FLAGS_CHANGED':
        await this.handleFlagsChanged(event.payload)
        break

      case 'FOLDER_COUNTS':
        await this.handleFolderCounts(event.payload)
        break

      case 'FOLDER_CHANGED':
        await this.syncFolders()
        break
    }

    // Emit event for UI update
    this.emit('data-updated', { type: event.type, payload: event.payload })
  }

  /**
   * Handle new message event
   */
  private async handleNewMessage(payload: any): Promise<void> {
    // Fetch full message from API and insert
    try {
      const response = await this.api.get(`/mailbox/${payload.folder}/messages/${payload.uid}`)

      if (response.data.success && response.data.data) {
        const folder = this.db.prepare(
          'SELECT id FROM email_folders WHERE full_path = ?'
        ).get(payload.folder) as { id: number } | undefined

        if (folder) {
          this.upsertEmail(folder.id, response.data.data)
        }
      }
    } catch (error: any) {
      console.error('[EmailSync] Failed to fetch new message:', error.message)
    }
  }

  /**
   * Handle deleted message event
   */
  private async handleDeletedMessage(payload: any): Promise<void> {
    this.db.prepare(`
      DELETE FROM emails WHERE remote_id = ? AND folder_id IN (
        SELECT id FROM email_folders WHERE full_path = ?
      )
    `).run(payload.uid, payload.folder)
  }

  /**
   * Handle moved message event
   */
  private async handleMovedMessage(payload: any): Promise<void> {
    const targetFolder = payload.targetFolder || payload.destination
    const sourceFolder = payload.sourceFolder || payload.source
    const uid = payload.oldUid || payload.uid

    const destFolder = this.db.prepare(
      'SELECT id FROM email_folders WHERE full_path = ?'
    ).get(targetFolder) as { id: number } | undefined

    if (destFolder && uid) {
      this.db.prepare(`
        UPDATE emails SET folder_id = ? WHERE remote_id = ? AND folder_id IN (
          SELECT id FROM email_folders WHERE full_path = ?
        )
      `).run(destFolder.id, uid, sourceFolder)
    }
  }

  /**
   * Handle flags changed event
   */
  private async handleFlagsChanged(payload: any): Promise<void> {
    this.db.prepare(`
      UPDATE emails SET
        is_read = ?,
        is_starred = ?,
        is_answered = ?,
        local_updated_at = datetime('now')
      WHERE remote_id = ? AND folder_id IN (
        SELECT id FROM email_folders WHERE full_path = ?
      )
    `).run(
      payload.seen ? 1 : 0,
      payload.flagged ? 1 : 0,
      payload.answered ? 1 : 0,
      payload.uid,
      payload.folder
    )
  }

  /**
   * Handle folder counts update
   */
  private async handleFolderCounts(payload: any): Promise<void> {
    if (payload.folder && payload.counts) {
      this.db.prepare(`
        UPDATE email_folders SET
          unread_count = ?,
          total_count = ?
        WHERE full_path = ?
      `).run(
        payload.counts.unread || 0,
        payload.counts.total || 0,
        payload.folder
      )
    }
  }

  // ============================================
  // PUBLIC METHODS FOR LOCAL ACTIONS
  // ============================================

  /**
   * Mark email as read (local-first)
   */
  async markAsRead(emailId: number, uid: number, folder: string): Promise<void> {
    await this.performAction(
      // Local action
      () => {
        this.db.prepare('UPDATE emails SET is_read = 1 WHERE id = ?').run(emailId)
      },
      // Remote action
      async () => {
        await this.api.post(`/mailbox/${folder}/messages/${uid}/read`)
      },
      // Queue data
      { action: 'mark_read', entityId: emailId, payload: { uid, folder } }
    )
  }

  /**
   * Mark email as unread (local-first)
   */
  async markAsUnread(emailId: number, uid: number, folder: string): Promise<void> {
    await this.performAction(
      () => {
        this.db.prepare('UPDATE emails SET is_read = 0 WHERE id = ?').run(emailId)
      },
      async () => {
        await this.api.post(`/mailbox/${folder}/messages/${uid}/unread`)
      },
      { action: 'mark_unread', entityId: emailId, payload: { uid, folder } }
    )
  }

  /**
   * Toggle star on email (local-first)
   */
  async toggleStar(emailId: number, uid: number, folder: string, starred: boolean): Promise<void> {
    await this.performAction(
      () => {
        this.db.prepare('UPDATE emails SET is_starred = ? WHERE id = ?').run(starred ? 1 : 0, emailId)
      },
      async () => {
        await this.api.post(`/mailbox/${folder}/messages/${uid}/${starred ? 'star' : 'unstar'}`)
      },
      { action: starred ? 'star' : 'unstar', entityId: emailId, payload: { uid, folder } }
    )
  }

  /**
   * Delete email (local-first, move to trash)
   */
  async deleteEmail(emailId: number, uid: number, folder: string): Promise<void> {
    await this.performAction(
      () => {
        // Mark as deleted locally (soft delete)
        this.db.prepare('DELETE FROM emails WHERE id = ?').run(emailId)
      },
      async () => {
        await this.api.delete(`/mailbox/${folder}/messages/${uid}`)
      },
      { action: 'delete', entityId: emailId, payload: { uid, folder } }
    )
  }

  /**
   * Queue email for sending (offline-capable)
   */
  async sendEmail(email: ComposeEmail): Promise<number> {
    // Save to local drafts with queued flag
    const result = this.db.prepare(`
      INSERT INTO emails (
        account_id, folder_id, subject, from_address, to_addresses, cc_addresses, bcc_addresses,
        body_html, body_text, is_draft, is_queued, created_at
      ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 1, datetime('now'))
    `).run(
      1, // account_id
      null, // Will be set to Sent folder after sending
      email.subject,
      email.from,
      JSON.stringify(email.to),
      JSON.stringify(email.cc || []),
      JSON.stringify(email.bcc || []),
      email.body_html,
      email.body_text
    )

    const localId = result.lastInsertRowid as number

    // Queue for sending
    this.queueChange('send', localId, { ...email, localId })

    // If online, process queue immediately
    if (this.isOnline) {
      this.processQueue()
    }

    return localId
  }

  /**
   * Fetch full email body (on-demand)
   */
  async fetchEmailBody(emailId: number): Promise<{ html: string; text: string } | null> {
    const email = this.db.prepare('SELECT * FROM emails WHERE id = ?').get(emailId) as Email | undefined

    if (!email) return null

    // If body already cached, return it
    if (email.body_html || email.body_text) {
      return { html: email.body_html, text: email.body_text }
    }

    // Fetch from server
    if (this.isOnline) {
      try {
        const folder = this.db.prepare('SELECT full_path FROM email_folders WHERE id = ?').get(email.folder_id) as { full_path: string } | undefined

        if (folder) {
          const response = await this.api.get(`/mailbox/${folder.full_path}/messages/${email.remote_id}/body`)

          if (response.data.success && response.data.data) {
            // Cache the body
            this.db.prepare(`
              UPDATE emails SET body_html = ?, body_text = ? WHERE id = ?
            `).run(response.data.data.html, response.data.data.text, emailId)

            return { html: response.data.data.html, text: response.data.data.text }
          }
        }
      } catch (error: any) {
        console.error('[EmailSync] Failed to fetch email body:', error.message)
      }
    }

    return null
  }

  /**
   * Public method to trigger body pre-fetching
   * Called when user wants to prepare for offline mode
   */
  async syncBodies(days: number = 7, maxCount: number = 200): Promise<{ synced: number; total: number }> {
    if (!this.isOnline) {
      return { synced: 0, total: 0 }
    }

    try {
      // Get emails without bodies
      const emailsNeedingBodies = this.db.prepare(`
        SELECT e.id, e.remote_id as uid, f.full_path as folder
        FROM emails e
        JOIN email_folders f ON f.id = e.folder_id
        WHERE e.body_html IS NULL AND e.body_text IS NULL
          AND e.date_received > datetime('now', '-' || ? || ' days')
        ORDER BY e.date_received DESC
        LIMIT ?
      `).all(days, maxCount) as { id: number; uid: number; folder: string }[]

      const total = emailsNeedingBodies.length
      if (total === 0) {
        return { synced: 0, total: 0 }
      }

      console.log(`[EmailSync] Syncing ${total} email bodies...`)
      this.emit('sync-progress', { type: 'bodies', current: 0, total })

      // Group by folder
      const byFolder = new Map<string, { id: number; uid: number }[]>()
      for (const email of emailsNeedingBodies) {
        if (!byFolder.has(email.folder)) {
          byFolder.set(email.folder, [])
        }
        byFolder.get(email.folder)!.push({ id: email.id, uid: email.uid })
      }

      let synced = 0
      for (const [folder, emails] of byFolder) {
        await this.fetchBodiesForFolder(folder, emails)
        synced += emails.length
        this.emit('sync-progress', { type: 'bodies', current: synced, total })
      }

      console.log(`[EmailSync] Synced ${synced} bodies`)
      return { synced, total }
    } catch (error: any) {
      console.error('[EmailSync] Body sync failed:', error.message)
      throw error
    }
  }

  /**
   * Get count of emails needing body sync
   */
  getEmailsNeedingBodySync(days: number = 7): number {
    const result = this.db.prepare(`
      SELECT COUNT(*) as count
      FROM emails e
      WHERE e.body_html IS NULL AND e.body_text IS NULL
        AND e.date_received > datetime('now', '-' || ? || ' days')
    `).get(days) as { count: number } | undefined

    return result?.count || 0
  }
}

/**
 * Email composition data
 */
interface ComposeEmail {
  from: string
  to: string[]
  cc?: string[]
  bcc?: string[]
  subject: string
  body_html: string
  body_text: string
  reply_to?: number
  forward_of?: number
  attachments?: any[]
}

