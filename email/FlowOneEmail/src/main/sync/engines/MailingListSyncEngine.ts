import { BaseSyncEngine, QueueItem, SyncEvent } from '../BaseSyncEngine'
import { LocalDatabase } from '../../database/Database'

/**
 * Mailing List Sync Engine
 * 
 * Handles synchronization of mailing list data:
 * - Mailing lists (contact groups)
 * - Contacts within lists
 * - Import history
 * 
 * Sync Strategy:
 * - Initial sync: Fetch all lists + contacts
 * - Incremental: WebSocket events + periodic delta
 * - Offline: Queue list/contact CRUD for later sync
 */
export class MailingListSyncEngine extends BaseSyncEngine {
  entityType = 'mailing_list'

  constructor(db: LocalDatabase) {
    super(db)
  }

  /**
   * Pull changes from server
   */
  async pullChanges(): Promise<void> {
    console.log('[MailingListSync] Pulling changes...')
    await this.syncLists()
  }

  /**
   * Sync all mailing lists and their contacts
   */
  private async syncLists(): Promise<void> {
    try {
      const response = await this.api.get('/mailing-lists')
      
      if (response.data.success && response.data.data) {
        const lists = Array.isArray(response.data.data)
          ? response.data.data
          : response.data.data.lists || []
        
        for (const list of lists) {
          this.db.prepare(`
            INSERT INTO mailing_lists (
              remote_id, user_email, name, description, color, icon,
              sort_order, sync_status, created_at, updated_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, 'synced', datetime('now'), datetime('now'))
            ON CONFLICT(remote_id) DO UPDATE SET
              name = excluded.name,
              description = excluded.description,
              color = excluded.color,
              icon = excluded.icon,
              sort_order = excluded.sort_order,
              sync_status = 'synced',
              updated_at = datetime('now')
          `).run(
            list.id,
            list.user_email || '',
            list.name,
            list.description || null,
            list.color || '#6366f1',
            list.icon || 'mail',
            list.sort_order || 0
          )
          
          // Sync contacts for this list
          await this.syncContacts(list.id)
        }
        
        console.log(`[MailingListSync] Synced ${lists.length} lists`)
      }
    } catch (error: any) {
      // 404 means the Email Marketing addon is disabled on the server - not a real error
      if (error.response?.status === 404) {
        console.log('[MailingListSync] Email Marketing addon not enabled on server, skipping')
        return
      }
      console.error('[MailingListSync] Failed to sync lists:', error.message)
      throw error
    }
  }

  /**
   * Sync contacts for a specific list
   */
  private async syncContacts(remoteListId: number): Promise<void> {
    try {
      const response = await this.api.get(`/mailing-lists/${remoteListId}/contacts`)
      
      if (response.data.success && response.data.data) {
        const contacts = Array.isArray(response.data.data)
          ? response.data.data
          : response.data.data.contacts || []
        
        const localList = this.db.prepare(
          'SELECT id FROM mailing_lists WHERE remote_id = ?'
        ).get(remoteListId) as { id: number } | undefined
        
        if (!localList) return
        
        for (const contact of contacts) {
          this.db.prepare(`
            INSERT INTO mailing_list_contacts (
              remote_id, list_id, email, name, phone, position, company, notes,
              sync_status, created_at, updated_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'synced', datetime('now'), datetime('now'))
            ON CONFLICT(remote_id) DO UPDATE SET
              email = excluded.email,
              name = excluded.name,
              phone = excluded.phone,
              position = excluded.position,
              company = excluded.company,
              notes = excluded.notes,
              sync_status = 'synced',
              updated_at = datetime('now')
          `).run(
            contact.id,
            localList.id,
            contact.email,
            contact.name || null,
            contact.phone || null,
            contact.position || null,
            contact.company || null,
            contact.notes || null
          )
        }
      }
    } catch (error: any) {
      console.error(`[MailingListSync] Failed to sync contacts for list ${remoteListId}:`, error.message)
    }
  }

  /**
   * Push a queued change to server
   */
  async pushChange(queueItem: QueueItem): Promise<void> {
    const payload = JSON.parse(queueItem.payload)
    
    switch (queueItem.action) {
      case 'create_list': {
        const res = await this.api.post('/mailing-lists', payload)
        if (res.data.success && res.data.data?.id) {
          this.db.prepare(`
            UPDATE mailing_lists SET remote_id = ?, sync_status = 'synced' WHERE id = ?
          `).run(res.data.data.id, queueItem.entity_id)
        }
        break
      }
        
      case 'update_list':
        await this.api.put(`/mailing-lists/${payload.remote_id}`, payload)
        break
        
      case 'delete_list':
        await this.api.delete(`/mailing-lists/${payload.remote_id}`)
        break
        
      case 'add_contact': {
        const res = await this.api.post(`/mailing-lists/${payload.list_remote_id}/contacts`, payload)
        if (res.data.success && res.data.data?.id) {
          this.db.prepare(`
            UPDATE mailing_list_contacts SET remote_id = ?, sync_status = 'synced' WHERE id = ?
          `).run(res.data.data.id, queueItem.entity_id)
        }
        break
      }
        
      case 'update_contact':
        await this.api.put(
          `/mailing-lists/${payload.list_remote_id}/contacts/${payload.contact_remote_id}`,
          payload
        )
        break
        
      case 'delete_contact':
        await this.api.delete(
          `/mailing-lists/${payload.list_remote_id}/contacts/${payload.contact_remote_id}`
        )
        break
        
      case 'bulk_delete_contacts':
        await this.api.post(`/mailing-lists/${payload.list_remote_id}/contacts/bulk-delete`, {
          ids: payload.contact_remote_ids
        })
        break
        
      default:
        console.warn(`[MailingListSync] Unknown action: ${queueItem.action}`)
    }
  }

  /**
   * Handle incoming WebSocket event
   */
  async handleEvent(event: SyncEvent): Promise<void> {
    console.log(`[MailingListSync] Handling event: ${event.type}`)
    
    switch (event.type) {
      case 'MAILING_LIST_CREATED':
      case 'MAILING_LIST_UPDATED':
        await this.syncLists()
        break
        
      case 'MAILING_LIST_DELETED':
        if (event.payload?.id) {
          this.db.prepare('DELETE FROM mailing_lists WHERE remote_id = ?').run(event.payload.id)
        }
        break
        
      case 'MAILING_LIST_CONTACT_ADDED':
      case 'MAILING_LIST_CONTACT_UPDATED':
        if (event.payload?.list_id) {
          await this.syncContacts(event.payload.list_id)
        }
        break
        
      case 'MAILING_LIST_CONTACTS_IMPORTED':
        if (event.payload?.list_id) {
          await this.syncContacts(event.payload.list_id)
        }
        break
    }
    
    this.emit('data-updated', { type: event.type, payload: event.payload })
  }

  // ============================================
  // PUBLIC METHODS FOR LOCAL QUERIES
  // ============================================

  getLists(): any[] {
    return this.db.all('SELECT * FROM mailing_lists ORDER BY sort_order, name')
  }

  getContacts(listId: number): any[] {
    return this.db.all(
      'SELECT * FROM mailing_list_contacts WHERE list_id = ? ORDER BY name, email',
      [listId]
    )
  }

  getListWithCount(): any[] {
    return this.db.all(`
      SELECT ml.*, COUNT(mlc.id) as contact_count
      FROM mailing_lists ml
      LEFT JOIN mailing_list_contacts mlc ON mlc.list_id = ml.id
      GROUP BY ml.id
      ORDER BY ml.sort_order, ml.name
    `)
  }
}

