import { BaseSyncEngine, QueueItem, SyncEvent } from '../BaseSyncEngine'
import { LocalDatabase } from '../../database/Database'

/**
 * Template Sync Engine
 * 
 * Handles synchronization of email template (content block) data:
 * - Template records (name, description, category, HTML content)
 * - Template ordering and sharing
 * 
 * Sync Strategy:
 * - Initial sync: Fetch all templates from /email-templates
 * - Incremental: WebSocket events for template changes
 * - Offline: Queue create/update/delete for later sync
 */
export class TemplateSyncEngine extends BaseSyncEngine {
  entityType = 'template'

  constructor(db: LocalDatabase) {
    super(db)
  }

  /**
   * Pull changes from server
   */
  async pullChanges(): Promise<void> {
    console.log('[TemplateSync] Pulling changes...')
    await this.syncTemplates()
  }

  /**
   * Sync all templates from server
   */
  private async syncTemplates(): Promise<void> {
    try {
      const response = await this.api.get('/email-templates')
      
      if (response.data.success && response.data.data) {
        const templates = Array.isArray(response.data.data)
          ? response.data.data
          : response.data.data.templates || []
        
        for (const template of templates) {
          this.upsertTemplate(template)
        }
        
        console.log(`[TemplateSync] Synced ${templates.length} templates`)
      }
    } catch (error: any) {
      console.error('[TemplateSync] Failed to sync templates:', error.message)
      throw error
    }
  }

  /**
   * Insert or update a template
   */
  private upsertTemplate(template: any): void {
    this.db.prepare(`
      INSERT INTO email_templates (
        remote_id, created_by, organization_domain, name, description,
        category, icon, html_content, thumbnail, is_shared, sort_order,
        sync_status, created_at, updated_at
      ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'synced', ?, ?)
      ON CONFLICT(remote_id) DO UPDATE SET
        name = excluded.name,
        description = excluded.description,
        category = excluded.category,
        icon = excluded.icon,
        html_content = excluded.html_content,
        thumbnail = excluded.thumbnail,
        is_shared = excluded.is_shared,
        sort_order = excluded.sort_order,
        updated_at = excluded.updated_at,
        sync_status = 'synced'
    `).run(
      template.id,
      template.created_by || '',
      template.organization_domain || '',
      template.name || '',
      template.description || null,
      template.category || 'custom',
      template.icon || 'dashboard_customize',
      template.html_content || '',
      template.thumbnail || null,
      template.is_shared ? 1 : 0,
      template.sort_order || 0,
      template.created_at || new Date().toISOString(),
      template.updated_at || null
    )
  }

  /**
   * Push a queued change to server
   */
  async pushChange(queueItem: QueueItem): Promise<void> {
    const payload = JSON.parse(queueItem.payload)
    
    switch (queueItem.action) {
      case 'create': {
        const res = await this.api.post('/email-templates', payload)
        if (res.data.success && res.data.data?.id) {
          this.db.prepare(`
            UPDATE email_templates SET remote_id = ?, sync_status = 'synced' WHERE id = ?
          `).run(res.data.data.id, queueItem.entity_id)
        }
        break
      }
        
      case 'update':
        if (payload.remote_id) {
          await this.api.put(`/email-templates/${payload.remote_id}`, payload)
        }
        break
        
      case 'delete':
        if (payload.remote_id) {
          await this.api.delete(`/email-templates/${payload.remote_id}`)
        }
        break
        
      case 'reorder':
        await this.api.post('/email-templates/reorder', payload)
        break
        
      default:
        console.warn(`[TemplateSync] Unknown action: ${queueItem.action}`)
    }
  }

  /**
   * Handle incoming WebSocket event
   */
  async handleEvent(event: SyncEvent): Promise<void> {
    console.log(`[TemplateSync] Handling event: ${event.type}`)
    
    switch (event.type) {
      case 'TEMPLATE_CREATED':
      case 'TEMPLATE_UPDATED':
        if (event.payload) {
          this.upsertTemplate(event.payload)
        }
        break
        
      case 'TEMPLATE_DELETED':
        if (event.payload?.id) {
          this.db.prepare('DELETE FROM email_templates WHERE remote_id = ?').run(event.payload.id)
        }
        break
        
      case 'TEMPLATE_REORDERED':
        if (event.payload?.order && Array.isArray(event.payload.order)) {
          for (const item of event.payload.order) {
            this.db.prepare(
              'UPDATE email_templates SET sort_order = ? WHERE remote_id = ?'
            ).run(item.sort_order, item.id)
          }
        }
        break
    }
    
    this.emit('data-updated', { type: event.type, payload: event.payload })
  }

  // ============================================
  // PUBLIC METHODS FOR LOCAL QUERIES
  // ============================================

  getTemplates(): any[] {
    return this.db.all('SELECT * FROM email_templates ORDER BY sort_order ASC, name ASC')
  }

  getTemplatesByCategory(category: string): any[] {
    return this.db.all(
      'SELECT * FROM email_templates WHERE category = ? ORDER BY sort_order ASC',
      [category]
    )
  }

  getTemplateById(remoteId: number): any | null {
    return this.db.get('SELECT * FROM email_templates WHERE remote_id = ?', [remoteId]) || null
  }

  getSharedTemplates(): any[] {
    return this.db.all(
      'SELECT * FROM email_templates WHERE is_shared = 1 ORDER BY sort_order ASC'
    )
  }
}

