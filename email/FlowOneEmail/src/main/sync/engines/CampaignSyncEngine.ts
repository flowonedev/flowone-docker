import { BaseSyncEngine, QueueItem, SyncEvent } from '../BaseSyncEngine'
import { LocalDatabase } from '../../database/Database'

/**
 * Campaign Sync Engine
 * 
 * Handles synchronization of email campaign data:
 * - Campaigns (bulk email sends)
 * - Queue items (per-recipient status)
 * - Campaign activity log
 * 
 * Sync Strategy:
 * - Initial sync: Fetch all campaigns (recent + active)
 * - Incremental: WebSocket events for status changes
 * - Campaigns are primarily server-driven (sending happens on server)
 *   but we cache data locally for offline viewing
 */
export class CampaignSyncEngine extends BaseSyncEngine {
  entityType = 'campaign'

  constructor(db: LocalDatabase) {
    super(db)
  }

  /**
   * Pull changes from server
   */
  async pullChanges(): Promise<void> {
    console.log('[CampaignSync] Pulling changes...')
    await this.syncCampaigns()
  }

  /**
   * Sync all campaigns
   */
  private async syncCampaigns(): Promise<void> {
    try {
      const response = await this.api.get('/email-queue/campaigns')
      
      if (response.data.success && response.data.data) {
        const campaigns = Array.isArray(response.data.data)
          ? response.data.data
          : response.data.data.campaigns || []
        
        for (const campaign of campaigns) {
          this.upsertCampaign(campaign)
        }
        
        console.log(`[CampaignSync] Synced ${campaigns.length} campaigns`)
      }
    } catch (error: any) {
      // 404 means the Email Marketing addon is disabled on the server - not a real error
      if (error.response?.status === 404) {
        console.log('[CampaignSync] Email Marketing addon not enabled on server, skipping')
        return
      }
      console.error('[CampaignSync] Failed to sync campaigns:', error.message)
      throw error
    }
  }

  /**
   * Insert or update a campaign
   */
  private upsertCampaign(campaign: any): void {
    this.db.prepare(`
      INSERT INTO email_campaigns (
        remote_id, campaign_id, user_email, subject, body_html, body_text,
        from_name, attachments, in_reply_to, reference_ids, track_read,
        total_recipients, sent_count, failed_count, status,
        sync_status, created_at, started_at, completed_at
      ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'synced', ?, ?, ?)
      ON CONFLICT(campaign_id) DO UPDATE SET
        remote_id = COALESCE(excluded.remote_id, remote_id),
        total_recipients = excluded.total_recipients,
        sent_count = excluded.sent_count,
        failed_count = excluded.failed_count,
        status = excluded.status,
        started_at = excluded.started_at,
        completed_at = excluded.completed_at,
        sync_status = 'synced'
    `).run(
      campaign.id ?? campaign.remote_id ?? null,
      campaign.campaign_id ?? campaign.uuid ?? null,
      campaign.user_email ?? '',
      campaign.subject ?? '',
      campaign.body_html ?? null,
      campaign.body_text ?? null,
      campaign.from_name ?? null,
      campaign.attachments ? JSON.stringify(campaign.attachments) : null,
      campaign.in_reply_to ?? null,
      campaign.references ?? null,
      campaign.track_read ? 1 : 0,
      campaign.total_recipients ?? 0,
      campaign.sent_count ?? 0,
      campaign.failed_count ?? 0,
      campaign.status ?? 'pending',
      campaign.created_at ?? new Date().toISOString(),
      campaign.started_at ?? null,
      campaign.completed_at ?? null
    )
  }

  /**
   * Push a queued change to server
   */
  async pushChange(queueItem: QueueItem): Promise<void> {
    const payload = JSON.parse(queueItem.payload)
    
    switch (queueItem.action) {
      case 'create':
        const createRes = await this.api.post('/email-queue/campaigns', payload)
        if (createRes.data.success && createRes.data.data?.id) {
          this.db.prepare(`
            UPDATE email_campaigns SET remote_id = ?, sync_status = 'synced' WHERE id = ?
          `).run(createRes.data.data.id, queueItem.entity_id)
        }
        break
        
      case 'pause':
        await this.api.post(`/email-queue/campaigns/${payload.campaign_id}/pause`)
        break
        
      case 'resume':
        await this.api.post(`/email-queue/campaigns/${payload.campaign_id}/resume`)
        break
        
      case 'cancel':
        await this.api.delete(`/email-queue/campaigns/${payload.campaign_id}`)
        break
        
      default:
        console.warn(`[CampaignSync] Unknown action: ${queueItem.action}`)
    }
  }

  /**
   * Handle incoming WebSocket event
   */
  async handleEvent(event: SyncEvent): Promise<void> {
    console.log(`[CampaignSync] Handling event: ${event.type}`)
    
    switch (event.type) {
      case 'CAMPAIGN_CREATED':
        if (event.payload) {
          this.upsertCampaign(event.payload)
        }
        break
        
      case 'CAMPAIGN_PROGRESS':
      case 'CAMPAIGN_UPDATED':
        if (event.payload) {
          this.db.prepare(`
            UPDATE email_campaigns 
            SET sent_count = ?, failed_count = ?, status = ?
            WHERE campaign_id = ?
          `).run(
            event.payload.sent_count || 0,
            event.payload.failed_count || 0,
            event.payload.status || 'processing',
            event.payload.campaign_id
          )
        }
        break
        
      case 'CAMPAIGN_COMPLETED':
        if (event.payload?.campaign_id) {
          this.db.prepare(`
            UPDATE email_campaigns 
            SET status = 'completed', completed_at = datetime('now'),
                sent_count = COALESCE(?, sent_count), failed_count = COALESCE(?, failed_count)
            WHERE campaign_id = ?
          `).run(
            event.payload.sent_count || null,
            event.payload.failed_count || null,
            event.payload.campaign_id
          )
        }
        break
        
      case 'CAMPAIGN_PAUSED':
        if (event.payload?.campaign_id) {
          this.db.prepare(`
            UPDATE email_campaigns SET status = 'paused' WHERE campaign_id = ?
          `).run(event.payload.campaign_id)
        }
        break
        
      case 'CAMPAIGN_CANCELLED':
        if (event.payload?.campaign_id) {
          this.db.prepare(`
            UPDATE email_campaigns SET status = 'cancelled' WHERE campaign_id = ?
          `).run(event.payload.campaign_id)
        }
        break
    }
    
    this.emit('data-updated', { type: event.type, payload: event.payload })
  }

  // ============================================
  // PUBLIC METHODS FOR LOCAL QUERIES
  // ============================================

  getCampaigns(): any[] {
    return this.db.all('SELECT * FROM email_campaigns ORDER BY created_at DESC')
  }

  getActiveCampaigns(): any[] {
    return this.db.all(
      "SELECT * FROM email_campaigns WHERE status IN ('pending', 'processing') ORDER BY created_at DESC"
    )
  }

  getCampaignById(campaignId: string): any | null {
    return this.db.get('SELECT * FROM email_campaigns WHERE campaign_id = ?', [campaignId]) || null
  }
}

