import { BaseSyncEngine, QueueItem, SyncEvent } from '../BaseSyncEngine'
import { LocalDatabase } from '../../database/Database'

/**
 * Colleague Sync Engine
 * 
 * Handles synchronization of colleague/team data:
 * - Organization colleagues (synced from mail server via cloud)
 * - Colleague groups (teams, departments)
 * - Group memberships
 * 
 * Sync Strategy:
 * - Initial sync: Fetch all colleagues + groups for user's domain
 * - Incremental: WebSocket events + periodic delta poll
 * - Offline: Queue profile updates, group changes
 */
export class ColleagueSyncEngine extends BaseSyncEngine {
  entityType = 'colleague'

  constructor(db: LocalDatabase) {
    super(db)
  }

  /**
   * Pull changes from server
   */
  async pullChanges(): Promise<void> {
    console.log('[ColleagueSync] Pulling changes...')
    await this.syncColleagues()
    await this.syncGroups()
  }

  /**
   * Sync colleagues list
   */
  private async syncColleagues(): Promise<void> {
    try {
      const response = await this.api.get('/colleagues')
      
      if (response.data.success && response.data.data) {
        const colleagues = Array.isArray(response.data.data)
          ? response.data.data
          : response.data.data.colleagues || []
        
        for (const colleague of colleagues) {
          this.db.prepare(`
            INSERT INTO colleagues (
              remote_id, organization_domain, email, display_name, avatar_path,
              job_title, department, phone, is_admin, status, last_seen_at,
              profile_updated_at, synced_from_mailserver, sync_status, created_at, updated_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'synced', datetime('now'), datetime('now'))
            ON CONFLICT(remote_id) DO UPDATE SET
              display_name = excluded.display_name,
              avatar_path = excluded.avatar_path,
              job_title = excluded.job_title,
              department = excluded.department,
              phone = excluded.phone,
              is_admin = excluded.is_admin,
              status = excluded.status,
              last_seen_at = excluded.last_seen_at,
              profile_updated_at = excluded.profile_updated_at,
              sync_status = 'synced',
              updated_at = datetime('now')
          `).run(
            colleague.id,
            colleague.organization_domain || colleague.domain || '',
            colleague.email,
            colleague.display_name || colleague.name || null,
            colleague.avatar_path || colleague.avatar || null,
            colleague.job_title || null,
            colleague.department || null,
            colleague.phone || null,
            colleague.is_admin ? 1 : 0,
            colleague.status || 'active',
            colleague.last_seen_at || null,
            colleague.profile_updated_at || null,
            colleague.synced_from_mailserver ? 1 : 0
          )
        }
        
        console.log(`[ColleagueSync] Synced ${colleagues.length} colleagues`)
      }
    } catch (error: any) {
      console.error('[ColleagueSync] Failed to sync colleagues:', error.message)
      throw error
    }
  }

  /**
   * Sync colleague groups and memberships
   */
  private async syncGroups(): Promise<void> {
    try {
      const response = await this.api.get('/colleagues/groups')
      
      if (response.data.success && response.data.data) {
        const groups = Array.isArray(response.data.data)
          ? response.data.data
          : response.data.data.groups || []
        
        for (const group of groups) {
          this.db.prepare(`
            INSERT INTO colleague_groups (
              remote_id, organization_domain, name, description, color, icon,
              sort_order, created_by, sync_status, created_at, updated_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'synced', datetime('now'), datetime('now'))
            ON CONFLICT(remote_id) DO UPDATE SET
              name = excluded.name,
              description = excluded.description,
              color = excluded.color,
              icon = excluded.icon,
              sort_order = excluded.sort_order,
              sync_status = 'synced',
              updated_at = datetime('now')
          `).run(
            group.id,
            group.organization_domain || group.domain || '',
            group.name,
            group.description || null,
            group.color || '#6366f1',
            group.icon || 'group',
            group.sort_order || 0,
            group.created_by || ''
          )
          
          // Sync members if included inline
          if (group.members && Array.isArray(group.members)) {
            this.syncGroupMembersFromData(group.id, group.members)
          }
        }
        
        console.log(`[ColleagueSync] Synced ${groups.length} groups`)
        
        // Fetch members separately for each group (API list may not include them)
        for (const group of groups) {
          await this.fetchGroupMembers(group.id)
        }
      }
    } catch (error: any) {
      console.error('[ColleagueSync] Failed to sync groups:', error.message)
      // Don't throw - groups are optional
    }
  }

  /**
   * Sync group members from inline data array
   */
  private syncGroupMembersFromData(remoteGroupId: number, members: any[]): void {
    const localGroup = this.db.prepare(
      'SELECT id FROM colleague_groups WHERE remote_id = ?'
    ).get(remoteGroupId) as { id: number } | undefined
    
    if (!localGroup) return
    
    for (const member of members) {
      const localColleague = this.db.prepare(
        'SELECT id FROM colleagues WHERE remote_id = ?'
      ).get(member.colleague_id || member.id) as { id: number } | undefined
      
      if (localColleague) {
        this.db.prepare(`
          INSERT INTO colleague_group_members (group_id, colleague_id, added_by, added_at)
          VALUES (?, ?, ?, datetime('now'))
          ON CONFLICT(group_id, colleague_id) DO NOTHING
        `).run(localGroup.id, localColleague.id, member.added_by || '')
      }
    }
  }

  /**
   * Fetch members for a specific group from the dedicated endpoint
   */
  private async fetchGroupMembers(remoteGroupId: number): Promise<void> {
    try {
      const response = await this.api.get(`/colleagues/groups/${remoteGroupId}/members`)
      
      if (response.data.success && response.data.data) {
        const members = Array.isArray(response.data.data)
          ? response.data.data
          : response.data.data.members || []
        
        this.syncGroupMembersFromData(remoteGroupId, members)
        console.log(`[ColleagueSync] Synced ${members.length} members for group ${remoteGroupId}`)
      }
    } catch (error: any) {
      console.error(`[ColleagueSync] Failed to fetch members for group ${remoteGroupId}:`, error.message)
    }
  }

  /**
   * Push a queued change to server
   */
  async pushChange(queueItem: QueueItem): Promise<void> {
    const payload = JSON.parse(queueItem.payload)
    
    switch (queueItem.action) {
      case 'update_profile':
        await this.api.put(`/colleagues/${payload.remote_id}`, payload)
        break
        
      case 'create_group':
        const createRes = await this.api.post('/colleagues/groups', payload)
        if (createRes.data.success && createRes.data.data?.id) {
          this.db.prepare(`
            UPDATE colleague_groups SET remote_id = ?, sync_status = 'synced'
            WHERE id = ?
          `).run(createRes.data.data.id, queueItem.entity_id)
        }
        break
        
      case 'update_group':
        await this.api.put(`/colleagues/groups/${payload.remote_id}`, payload)
        break
        
      case 'delete_group':
        await this.api.delete(`/colleagues/groups/${payload.remote_id}`)
        break
        
      case 'add_member':
        await this.api.post(`/colleagues/groups/${payload.group_remote_id}/members`, {
          colleague_id: payload.colleague_remote_id
        })
        break
        
      case 'remove_member':
        await this.api.delete(
          `/colleagues/groups/${payload.group_remote_id}/members/${payload.colleague_remote_id}`
        )
        break
        
      default:
        console.warn(`[ColleagueSync] Unknown action: ${queueItem.action}`)
    }
  }

  /**
   * Handle incoming WebSocket event
   */
  async handleEvent(event: SyncEvent): Promise<void> {
    console.log(`[ColleagueSync] Handling event: ${event.type}`)
    
    switch (event.type) {
      case 'COLLEAGUE_UPDATED':
      case 'COLLEAGUE_STATUS_CHANGED':
        if (event.payload) {
          this.upsertColleague(event.payload)
        }
        break
        
      case 'COLLEAGUE_GROUP_CREATED':
      case 'COLLEAGUE_GROUP_UPDATED':
        if (event.payload) {
          await this.syncGroups()
        }
        break
        
      case 'COLLEAGUE_GROUP_DELETED':
        if (event.payload?.id) {
          this.db.prepare('DELETE FROM colleague_groups WHERE remote_id = ?').run(event.payload.id)
        }
        break
        
      case 'COLLEAGUE_SYNCED':
        // Full re-sync triggered by server
        await this.syncColleagues()
        break
    }
    
    this.emit('data-updated', { type: event.type, payload: event.payload })
  }

  /**
   * Insert or update a single colleague
   */
  private upsertColleague(colleague: any): void {
    this.db.prepare(`
      INSERT INTO colleagues (
        remote_id, organization_domain, email, display_name, avatar_path,
        job_title, department, phone, is_admin, status, last_seen_at,
        sync_status, updated_at
      ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'synced', datetime('now'))
      ON CONFLICT(remote_id) DO UPDATE SET
        display_name = excluded.display_name,
        avatar_path = excluded.avatar_path,
        status = excluded.status,
        last_seen_at = excluded.last_seen_at,
        sync_status = 'synced',
        updated_at = datetime('now')
    `).run(
      colleague.id,
      colleague.organization_domain || colleague.domain || '',
      colleague.email,
      colleague.display_name || colleague.name || null,
      colleague.avatar_path || colleague.avatar || null,
      colleague.job_title || null,
      colleague.department || null,
      colleague.phone || null,
      colleague.is_admin ? 1 : 0,
      colleague.status || 'active',
      colleague.last_seen_at || null
    )
  }

  // ============================================
  // PUBLIC METHODS FOR LOCAL QUERIES
  // ============================================

  getColleagues(): any[] {
    return this.db.all('SELECT * FROM colleagues ORDER BY display_name, email')
  }

  getGroups(): any[] {
    return this.db.all('SELECT * FROM colleague_groups ORDER BY sort_order, name')
  }

  getGroupMembers(groupId: number): any[] {
    return this.db.all(`
      SELECT c.* FROM colleagues c
      JOIN colleague_group_members gm ON gm.colleague_id = c.id
      WHERE gm.group_id = ?
      ORDER BY c.display_name, c.email
    `, [groupId])
  }
}

