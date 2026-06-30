import { BaseSyncEngine, QueueItem, SyncEvent } from '../BaseSyncEngine'
import { LocalDatabase } from '../../database/Database'

/**
 * Portal Sync Engine
 * 
 * Handles synchronization of Client Portal data:
 * - Portal access (which contacts can log in)
 * - Updates (pushed to clients)
 * - Comments on updates
 * - Documents (contracts, invoices, etc.)
 * - Portal calls (video/audio)
 * 
 * Note: Portal data is mostly managed server-side (magic link auth,
 * signing, etc.) -- this engine caches it locally for offline viewing
 * and quick access. Write operations always go through the API.
 */
export class PortalSyncEngine extends BaseSyncEngine {
  entityType = 'portal'

  constructor(db: LocalDatabase) {
    super(db)
  }

  /**
   * Pull all portal data from server
   */
  async pullChanges(): Promise<void> {
    console.log('[PortalSync] Pulling changes...')

    await Promise.all([
      this.syncAccess(),
      this.syncUpdates(),
      this.syncDocuments(),
      this.syncCalls(),
    ])
  }

  // ============================================
  // PULL METHODS
  // ============================================

  private async syncAccess(): Promise<void> {
    try {
      const response = await this.api.get('/portal/access')
      if (response.data.success && response.data.data) {
        const entries = Array.isArray(response.data.data)
          ? response.data.data
          : response.data.data.access || []
        for (const entry of entries) {
          this.upsertAccess(entry)
        }
        console.log(`[PortalSync] Synced ${entries.length} portal access entries`)
      }
    } catch (error: any) {
      console.error('[PortalSync] Failed to sync portal access:', error.message)
    }
  }

  private async syncUpdates(): Promise<void> {
    try {
      const response = await this.api.get('/portal/updates')
      if (response.data.success && response.data.data) {
        const updates = Array.isArray(response.data.data)
          ? response.data.data
          : response.data.data.updates || []
        for (const update of updates) {
          this.upsertUpdate(update)

          // Sync comments if present
          if (update.comments && Array.isArray(update.comments)) {
            for (const comment of update.comments) {
              this.upsertComment(update.id, comment)
            }
          }

          // Sync files if present
          if (update.files && Array.isArray(update.files)) {
            for (const file of update.files) {
              this.upsertUpdateFile(update.id, file)
            }
          }
        }
        console.log(`[PortalSync] Synced ${updates.length} portal updates`)
      }
    } catch (error: any) {
      console.error('[PortalSync] Failed to sync portal updates:', error.message)
    }
  }

  private async syncDocuments(): Promise<void> {
    try {
      const response = await this.api.get('/portal/documents')
      if (response.data.success && response.data.data) {
        const documents = Array.isArray(response.data.data)
          ? response.data.data
          : response.data.data.documents || []
        for (const doc of documents) {
          this.upsertDocument(doc)

          // Sync signers if present
          if (doc.signers && Array.isArray(doc.signers)) {
            for (const signer of doc.signers) {
              this.upsertDocumentSigner(doc.id, signer)
            }
          }
        }
        console.log(`[PortalSync] Synced ${documents.length} portal documents`)
      }
    } catch (error: any) {
      console.error('[PortalSync] Failed to sync portal documents:', error.message)
    }
  }

  private async syncCalls(): Promise<void> {
    try {
      const response = await this.api.get('/portal/calls')
      if (response.data.success && response.data.data) {
        const calls = Array.isArray(response.data.data)
          ? response.data.data
          : response.data.data.calls || []
        for (const call of calls) {
          this.upsertCall(call)
        }
        console.log(`[PortalSync] Synced ${calls.length} portal calls`)
      }
    } catch (error: any) {
      console.error('[PortalSync] Failed to sync portal calls:', error.message)
    }
  }

  // ============================================
  // UPSERT METHODS
  // ============================================

  private upsertAccess(entry: any): void {
    this.db.prepare(`
      INSERT INTO portal_access (
        remote_id, client_id, contact_id, email, name, is_active,
        last_login_at, session_count, created_by, sync_status, created_at, updated_at
      ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'synced', ?, ?)
      ON CONFLICT(remote_id) DO UPDATE SET
        name = excluded.name, is_active = excluded.is_active,
        last_login_at = excluded.last_login_at, session_count = excluded.session_count,
        sync_status = 'synced', updated_at = excluded.updated_at
    `).run(
      entry.id, entry.client_id, entry.contact_id || null,
      entry.email, entry.name || null, entry.is_active ? 1 : 0,
      entry.last_login_at || null, entry.session_count || 0,
      entry.created_by, entry.created_at || null, entry.updated_at || null
    )
  }

  private upsertUpdate(update: any): void {
    this.db.prepare(`
      INSERT INTO portal_updates (
        remote_id, client_id, created_by, title, content_html, content_text,
        update_type, mood_board_id, mood_board_share_token, drive_file_ids,
        board_id, board_card_id, is_pinned, sync_status, created_at, updated_at
      ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'synced', ?, ?)
      ON CONFLICT(remote_id) DO UPDATE SET
        title = excluded.title, content_html = excluded.content_html,
        content_text = excluded.content_text, is_pinned = excluded.is_pinned,
        sync_status = 'synced', updated_at = excluded.updated_at
    `).run(
      update.id, update.client_id, update.created_by, update.title,
      update.content_html || null, update.content_text || null,
      update.update_type || 'general', update.mood_board_id || null,
      update.mood_board_share_token || null,
      update.drive_file_ids ? JSON.stringify(update.drive_file_ids) : null,
      update.board_id || null, update.board_card_id || null,
      update.is_pinned ? 1 : 0, update.created_at || null, update.updated_at || null
    )
  }

  private upsertComment(updateRemoteId: number, comment: any): void {
    const localUpdate = this.db.prepare(
      'SELECT id FROM portal_updates WHERE remote_id = ?'
    ).get(updateRemoteId) as { id: number } | undefined
    if (!localUpdate) return

    this.db.prepare(`
      INSERT INTO portal_comments (
        remote_id, update_id, author_type, author_email, author_name,
        content_text, parent_comment_id, sync_status, created_at, updated_at
      ) VALUES (?, ?, ?, ?, ?, ?, ?, 'synced', ?, ?)
      ON CONFLICT(remote_id) DO UPDATE SET
        content_text = excluded.content_text, sync_status = 'synced',
        updated_at = excluded.updated_at
    `).run(
      comment.id, localUpdate.id, comment.author_type, comment.author_email,
      comment.author_name || null, comment.content_text,
      comment.parent_comment_id || null,
      comment.created_at || null, comment.updated_at || null
    )
  }

  private upsertUpdateFile(updateRemoteId: number, file: any): void {
    const localUpdate = this.db.prepare(
      'SELECT id FROM portal_updates WHERE remote_id = ?'
    ).get(updateRemoteId) as { id: number } | undefined
    if (!localUpdate) return

    this.db.prepare(`
      INSERT INTO portal_update_files (
        remote_id, update_id, filename, original_name, mime_type, file_size, drive_file_id
      ) VALUES (?, ?, ?, ?, ?, ?, ?)
      ON CONFLICT(remote_id) DO UPDATE SET
        filename = excluded.filename, original_name = excluded.original_name
    `).run(
      file.id, localUpdate.id, file.filename, file.original_name,
      file.mime_type || null, file.file_size || 0, file.drive_file_id || null
    )
  }

  private upsertDocument(doc: any): void {
    this.db.prepare(`
      INSERT INTO portal_documents (
        remote_id, client_id, created_by, title, description, document_type, status,
        filename, original_name, mime_type, file_size, file_path, drive_file_id,
        signing_method, requires_all_signers, signing_deadline,
        amount, currency, reference_number, version,
        viewed_at, completed_at, sync_status, created_at, updated_at
      ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'synced', ?, ?)
      ON CONFLICT(remote_id) DO UPDATE SET
        title = excluded.title, status = excluded.status,
        viewed_at = excluded.viewed_at, completed_at = excluded.completed_at,
        sync_status = 'synced', updated_at = excluded.updated_at
    `).run(
      doc.id, doc.client_id, doc.created_by, doc.title, doc.description || null,
      doc.document_type, doc.status || 'draft',
      doc.filename, doc.original_name, doc.mime_type || null,
      doc.file_size || 0, doc.file_path, doc.drive_file_id || null,
      doc.signing_method || 'both', doc.requires_all_signers ? 1 : 0,
      doc.signing_deadline || null, doc.amount || null, doc.currency || 'HUF',
      doc.reference_number || null, doc.version || 1,
      doc.viewed_at || null, doc.completed_at || null,
      doc.created_at || null, doc.updated_at || null
    )
  }

  private upsertDocumentSigner(docRemoteId: number, signer: any): void {
    const localDoc = this.db.prepare(
      'SELECT id FROM portal_documents WHERE remote_id = ?'
    ).get(docRemoteId) as { id: number } | undefined
    if (!localDoc) return

    this.db.prepare(`
      INSERT INTO portal_document_signers (
        remote_id, document_id, signer_email, signer_name, status,
        signed_at, signature_type, sign_order, created_at
      ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
      ON CONFLICT(document_id, signer_email) DO UPDATE SET
        status = excluded.status, signed_at = excluded.signed_at,
        signature_type = excluded.signature_type
    `).run(
      signer.id, localDoc.id, signer.signer_email, signer.signer_name || null,
      signer.status || 'pending', signer.signed_at || null,
      signer.signature_type || null, signer.sign_order || 0,
      signer.created_at || null
    )
  }

  private upsertCall(call: any): void {
    this.db.prepare(`
      INSERT INTO portal_calls (
        remote_id, client_id, created_by, room_name, call_type, status,
        scheduled_at, started_at, ended_at, duration_seconds, had_screen_share,
        notes, sync_status, created_at
      ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'synced', ?)
      ON CONFLICT(remote_id) DO UPDATE SET
        status = excluded.status,
        started_at = excluded.started_at,
        ended_at = excluded.ended_at,
        duration_seconds = excluded.duration_seconds,
        notes = excluded.notes,
        sync_status = 'synced'
    `).run(
      call.id, call.client_id, call.created_by, call.room_name,
      call.call_type || 'instant', call.status || 'waiting',
      call.scheduled_at || null, call.started_at || null,
      call.ended_at || null, call.duration_seconds || 0,
      call.had_screen_share ? 1 : 0, call.notes || null,
      call.created_at || null
    )
  }

  // ============================================
  // PUSH CHANGES
  // ============================================

  async pushChange(queueItem: QueueItem): Promise<void> {
    const payload = JSON.parse(queueItem.payload)

    switch (queueItem.action) {
      // Portal access
      case 'grant_access': {
        const res = await this.api.post('/portal/access', payload)
        if (res.data.success && res.data.data?.id) {
          this.db.prepare('UPDATE portal_access SET remote_id = ?, sync_status = "synced" WHERE id = ?')
            .run(res.data.data.id, queueItem.entity_id)
        }
        break
      }
      case 'revoke_access':
        await this.api.delete(`/portal/access/${payload.remote_id}`)
        break

      // Updates
      case 'create_update': {
        const res = await this.api.post('/portal/updates', payload)
        if (res.data.success && res.data.data?.id) {
          this.db.prepare('UPDATE portal_updates SET remote_id = ?, sync_status = "synced" WHERE id = ?')
            .run(res.data.data.id, queueItem.entity_id)
        }
        break
      }
      case 'update_update':
        await this.api.put(`/portal/updates/${payload.remote_id}`, payload)
        break
      case 'delete_update':
        await this.api.delete(`/portal/updates/${payload.remote_id}`)
        break

      // Comments
      case 'create_comment': {
        const res = await this.api.post(`/portal/updates/${payload.update_id}/comments`, payload)
        if (res.data.success && res.data.data?.id) {
          this.db.prepare('UPDATE portal_comments SET remote_id = ?, sync_status = "synced" WHERE id = ?')
            .run(res.data.data.id, queueItem.entity_id)
        }
        break
      }

      // Documents
      case 'create_document': {
        const res = await this.api.post('/portal/documents', payload)
        if (res.data.success && res.data.data?.id) {
          this.db.prepare('UPDATE portal_documents SET remote_id = ?, sync_status = "synced" WHERE id = ?')
            .run(res.data.data.id, queueItem.entity_id)
        }
        break
      }
      case 'send_document':
        await this.api.post(`/portal/documents/${payload.remote_id}/send`)
        break

      // Calls
      case 'create_call': {
        const res = await this.api.post('/portal/calls', payload)
        if (res.data.success && res.data.data?.id) {
          this.db.prepare('UPDATE portal_calls SET remote_id = ?, sync_status = "synced" WHERE id = ?')
            .run(res.data.data.id, queueItem.entity_id)
        }
        break
      }

      default:
        console.warn(`[PortalSync] Unknown action: ${queueItem.action}`)
    }
  }

  // ============================================
  // WEBSOCKET EVENT HANDLER
  // ============================================

  async handleEvent(event: SyncEvent): Promise<void> {
    console.log(`[PortalSync] Handling event: ${event.type}`)

    switch (event.type) {
      case 'PORTAL_ACCESS_GRANTED':
      case 'PORTAL_ACCESS_UPDATED':
        this.upsertAccess(event.payload)
        break
      case 'PORTAL_ACCESS_REVOKED':
        this.db.prepare('DELETE FROM portal_access WHERE remote_id = ?').run(event.payload.id)
        break

      case 'PORTAL_UPDATE_CREATED':
      case 'PORTAL_UPDATE_UPDATED':
        this.upsertUpdate(event.payload)
        break
      case 'PORTAL_UPDATE_DELETED':
        this.db.prepare('DELETE FROM portal_updates WHERE remote_id = ?').run(event.payload.id)
        break

      case 'PORTAL_COMMENT_CREATED':
        if (event.payload.update_id) {
          this.upsertComment(event.payload.update_id, event.payload)
        }
        break

      case 'PORTAL_DOCUMENT_CREATED':
      case 'PORTAL_DOCUMENT_UPDATED':
        this.upsertDocument(event.payload)
        break
      case 'PORTAL_DOCUMENT_SIGNED':
        this.db.prepare('UPDATE portal_documents SET status = "signed", completed_at = ? WHERE remote_id = ?')
          .run(new Date().toISOString(), event.payload.id)
        break

      case 'PORTAL_CALL_STARTED':
      case 'PORTAL_CALL_UPDATED':
        this.upsertCall(event.payload)
        break
      case 'PORTAL_CALL_ENDED':
        this.db.prepare('UPDATE portal_calls SET status = "ended", ended_at = ? WHERE remote_id = ?')
          .run(new Date().toISOString(), event.payload.id)
        break
    }

    this.emit('data-updated', { type: event.type, payload: event.payload })
  }
}

