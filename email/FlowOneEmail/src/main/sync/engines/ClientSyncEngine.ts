import { BaseSyncEngine, QueueItem, SyncEvent } from '../BaseSyncEngine'
import { LocalDatabase } from '../../database/Database'

/**
 * Client Sync Engine
 * 
 * Handles synchronization of client/contact data:
 * - Client records (name, email, company, phone, etc.)
 * - Time entries associated with clients
 * 
 * Sync Strategy:
 * - Initial sync: Fetch all clients from /clients
 * - Incremental: WebSocket events for client changes
 * - Offline: Queue create/update/delete for later sync
 */
export class ClientSyncEngine extends BaseSyncEngine {
  entityType = 'client'

  constructor(db: LocalDatabase) {
    super(db)
  }

  /**
   * Pull changes from server
   */
  async pullChanges(): Promise<void> {
    console.log('[ClientSync] Pulling changes...')
    await this.syncClients()
    await this.syncAllContacts()
  }

  /**
   * Sync all clients from server
   */
  private async syncClients(): Promise<void> {
    try {
      const response = await this.api.get('/clients', {
        params: { sort: 'name' }
      })
      
      if (response.data.success && response.data.data) {
        const clients = Array.isArray(response.data.data)
          ? response.data.data
          : response.data.data.clients || []
        
        for (const client of clients) {
          this.upsertClient(client)
        }
        
        console.log(`[ClientSync] Synced ${clients.length} clients`)
      }
    } catch (error: any) {
      console.error('[ClientSync] Failed to sync clients:', error.message)
      throw error
    }
  }

  /**
   * Sync all client contacts from server
   */
  private async syncAllContacts(): Promise<void> {
    try {
      const response = await this.api.get('/clients/all-contacts')
      
      if (response.data.success && response.data.data) {
        const contacts = Array.isArray(response.data.data)
          ? response.data.data
          : response.data.data.contacts || []
        
        for (const contact of contacts) {
          this.upsertContact(contact)
        }
        
        console.log(`[ClientSync] Synced ${contacts.length} contacts`)
      }
    } catch (error: any) {
      console.error('[ClientSync] Failed to sync contacts:', error.message)
    }
  }

  /**
   * Insert or update a client contact
   */
  private upsertContact(contact: any): void {
    const localClient = contact.client_id
      ? this.db.prepare('SELECT id FROM clients WHERE remote_id = ?').get(contact.client_id) as { id: number } | undefined
      : undefined

    this.db.prepare(`
      INSERT INTO client_contacts (
        remote_id, client_id, name, email, phone, role, is_primary
      ) VALUES (?, ?, ?, ?, ?, ?, ?)
      ON CONFLICT(remote_id) DO UPDATE SET
        name = excluded.name,
        email = excluded.email,
        phone = excluded.phone,
        role = excluded.role,
        is_primary = excluded.is_primary
    `).run(
      contact.id,
      localClient?.id || null,
      contact.name || '',
      contact.email || null,
      contact.phone || null,
      contact.role || contact.position || null,
      contact.is_primary ? 1 : 0
    )
  }

  /**
   * Insert or update a client
   */
  private upsertClient(client: any): void {
    this.db.prepare(`
      INSERT INTO clients (
        remote_id, name, email, company, phone, address, notes,
        avatar_url, is_active, hourly_rate, currency, drive_folder_id,
        sync_status, remote_updated_at, created_at
      ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'synced', ?, ?)
      ON CONFLICT(remote_id) DO UPDATE SET
        name = excluded.name,
        email = excluded.email,
        company = excluded.company,
        phone = excluded.phone,
        address = excluded.address,
        notes = excluded.notes,
        avatar_url = excluded.avatar_url,
        is_active = excluded.is_active,
        hourly_rate = excluded.hourly_rate,
        currency = excluded.currency,
        drive_folder_id = excluded.drive_folder_id,
        remote_updated_at = excluded.remote_updated_at,
        sync_status = 'synced'
    `).run(
      client.id,
      client.name || '',
      client.email || null,
      client.company || null,
      client.phone || null,
      client.address || null,
      client.notes || null,
      client.avatar_url || null,
      client.is_active !== undefined ? (client.is_active ? 1 : 0) : 1,
      client.hourly_rate || null,
      client.currency || 'EUR',
      client.drive_folder_id || null,
      client.updated_at || null,
      client.created_at || new Date().toISOString()
    )
  }

  /**
   * Push a queued change to server
   */
  async pushChange(queueItem: QueueItem): Promise<void> {
    const payload = JSON.parse(queueItem.payload)
    
    switch (queueItem.action) {
      case 'create': {
        const res = await this.api.post('/clients/manual', payload)
        if (res.data.success && res.data.data?.id) {
          this.db.prepare(`
            UPDATE clients SET remote_id = ?, sync_status = 'synced' WHERE id = ?
          `).run(res.data.data.id, queueItem.entity_id)
        }
        break
      }
        
      case 'update':
        if (payload.remote_id) {
          await this.api.put(`/clients/${payload.remote_id}`, payload)
        }
        break
        
      case 'delete':
        if (payload.remote_id) {
          await this.api.delete(`/clients/${payload.remote_id}`)
        }
        break
        
      default:
        console.warn(`[ClientSync] Unknown action: ${queueItem.action}`)
    }
  }

  /**
   * Handle incoming WebSocket event
   */
  async handleEvent(event: SyncEvent): Promise<void> {
    console.log(`[ClientSync] Handling event: ${event.type}`)
    
    switch (event.type) {
      case 'CLIENT_CREATED':
      case 'CLIENT_UPDATED':
        if (event.payload) {
          this.upsertClient(event.payload)
        }
        break
        
      case 'CLIENT_DELETED':
        if (event.payload?.id) {
          this.db.prepare('DELETE FROM clients WHERE remote_id = ?').run(event.payload.id)
        }
        break
        
      case 'TIME_ENTRY_CREATED':
      case 'TIME_ENTRY_UPDATED':
        if (event.payload) {
          this.upsertTimeEntry(event.payload)
        }
        break
        
      case 'TIME_ENTRY_DELETED':
        if (event.payload?.id) {
          this.db.prepare('DELETE FROM time_entries WHERE remote_id = ?').run(event.payload.id)
        }
        break
    }
    
    this.emit('data-updated', { type: event.type, payload: event.payload })
  }

  /**
   * Insert or update a time entry
   */
  private upsertTimeEntry(entry: any): void {
    this.db.prepare(`
      INSERT INTO time_entries (
        remote_id, client_id, board_id, card_id, description,
        duration_seconds, started_at, ended_at, is_billable, is_running,
        source, sync_status, created_at
      ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'synced', ?)
      ON CONFLICT(remote_id) DO UPDATE SET
        description = excluded.description,
        duration_seconds = excluded.duration_seconds,
        started_at = excluded.started_at,
        ended_at = excluded.ended_at,
        is_billable = excluded.is_billable,
        is_running = excluded.is_running,
        sync_status = 'synced'
    `).run(
      entry.id,
      entry.client_id || null,
      entry.board_id || null,
      entry.card_id || null,
      entry.description || '',
      entry.duration_seconds || 0,
      entry.started_at || null,
      entry.ended_at || null,
      entry.is_billable ? 1 : 0,
      entry.is_running ? 1 : 0,
      entry.source || 'desktop',
      entry.created_at || new Date().toISOString()
    )
  }

  // ============================================
  // PUBLIC METHODS FOR LOCAL QUERIES
  // ============================================

  getClients(): any[] {
    return this.db.all('SELECT * FROM clients ORDER BY name ASC')
  }

  getActiveClients(): any[] {
    return this.db.all('SELECT * FROM clients WHERE is_active = 1 ORDER BY name ASC')
  }

  getClientById(remoteId: number): any | null {
    return this.db.get('SELECT * FROM clients WHERE remote_id = ?', [remoteId]) || null
  }

  getTimeEntries(clientId?: number): any[] {
    if (clientId) {
      return this.db.all(
        'SELECT * FROM time_entries WHERE client_id = ? ORDER BY started_at DESC',
        [clientId]
      )
    }
    return this.db.all('SELECT * FROM time_entries ORDER BY started_at DESC')
  }
}

