import { BaseSyncEngine, QueueItem, SyncEvent } from '../BaseSyncEngine'
import { LocalDatabase } from '../../database/Database'

/**
 * CRM Sync Engine
 * 
 * Handles synchronization of CRM Pro data:
 * - Deals (pipeline stages)
 * - Invoices + line items + payments
 * - Tags & custom fields
 * - Reminders, call log, meeting notes
 * - Automation rules & sequences
 * - Expenses
 * - Sharing config
 * - Billing settings
 * 
 * Sync Strategy:
 * - Full sync on startup, periodic refresh
 * - WebSocket events for instant updates (deal moved, invoice paid, etc.)
 * - Offline support: CRUD operations queue offline
 */
export class CrmSyncEngine extends BaseSyncEngine {
  entityType = 'crm'

  constructor(db: LocalDatabase) {
    super(db)
  }

  /**
   * Pull all CRM data from server
   */
  async pullChanges(): Promise<void> {
    console.log('[CrmSync] Pulling changes...')

    await Promise.all([
      this.syncDeals(),
      this.syncInvoices(),
      this.syncTags(),
      this.syncReminders(),
      this.syncCallLog(),
      this.syncMeetingNotes(),
      this.syncAutomationRules(),
      this.syncSequences(),
      this.syncExpenses(),
      this.syncBillingSettings(),
      this.syncShares(),
    ])
  }

  // ============================================
  // PULL METHODS
  // ============================================

  private async syncDeals(): Promise<void> {
    try {
      const response = await this.api.get('/crm/deals')
      if (response.data.success && response.data.data) {
        const deals = Array.isArray(response.data.data) ? response.data.data : response.data.data.deals || []
        for (const deal of deals) {
          this.upsertDeal(deal)
        }
        console.log(`[CrmSync] Synced ${deals.length} deals`)
      }
    } catch (error: any) {
      console.error('[CrmSync] Failed to sync deals:', error.message)
    }
  }

  private async syncInvoices(): Promise<void> {
    try {
      const response = await this.api.get('/crm/invoices')
      if (response.data.success && response.data.data) {
        const invoices = Array.isArray(response.data.data) ? response.data.data : response.data.data.invoices || []
        for (const invoice of invoices) {
          this.upsertInvoice(invoice)
          // Sync line items if present
          if (invoice.items && Array.isArray(invoice.items)) {
            for (const item of invoice.items) {
              this.upsertInvoiceItem(invoice.id, item)
            }
          }
        }
        console.log(`[CrmSync] Synced ${invoices.length} invoices`)
      }
    } catch (error: any) {
      console.error('[CrmSync] Failed to sync invoices:', error.message)
    }
  }

  private async syncTags(): Promise<void> {
    try {
      const response = await this.api.get('/crm/tags')
      if (response.data.success && response.data.data) {
        const tags = Array.isArray(response.data.data) ? response.data.data : response.data.data.tags || []
        for (const tag of tags) {
          this.upsertTag(tag)
        }
        console.log(`[CrmSync] Synced ${tags.length} tags`)
      }
    } catch (error: any) {
      console.error('[CrmSync] Failed to sync tags:', error.message)
    }
  }

  private async syncReminders(): Promise<void> {
    try {
      const response = await this.api.get('/crm/reminders')
      if (response.data.success && response.data.data) {
        const reminders = Array.isArray(response.data.data) ? response.data.data : response.data.data.reminders || []
        for (const reminder of reminders) {
          this.upsertReminder(reminder)
        }
        console.log(`[CrmSync] Synced ${reminders.length} reminders`)
      }
    } catch (error: any) {
      console.error('[CrmSync] Failed to sync reminders:', error.message)
    }
  }

  private async syncCallLog(): Promise<void> {
    try {
      const response = await this.api.get('/crm/call-log')
      if (response.data.success && response.data.data) {
        const calls = Array.isArray(response.data.data) ? response.data.data : response.data.data.calls || []
        for (const call of calls) {
          this.upsertCallLog(call)
        }
        console.log(`[CrmSync] Synced ${calls.length} call log entries`)
      }
    } catch (error: any) {
      console.error('[CrmSync] Failed to sync call log:', error.message)
    }
  }

  private async syncMeetingNotes(): Promise<void> {
    try {
      const response = await this.api.get('/crm/meeting-notes')
      if (response.data.success && response.data.data) {
        const notes = Array.isArray(response.data.data) ? response.data.data : response.data.data.notes || []
        for (const note of notes) {
          this.upsertMeetingNote(note)
        }
        console.log(`[CrmSync] Synced ${notes.length} meeting notes`)
      }
    } catch (error: any) {
      console.error('[CrmSync] Failed to sync meeting notes:', error.message)
    }
  }

  private async syncAutomationRules(): Promise<void> {
    try {
      const response = await this.api.get('/crm/automation/rules')
      if (response.data.success && response.data.data) {
        const rules = Array.isArray(response.data.data) ? response.data.data : response.data.data.rules || []
        for (const rule of rules) {
          this.upsertAutomationRule(rule)
        }
        console.log(`[CrmSync] Synced ${rules.length} automation rules`)
      }
    } catch (error: any) {
      console.error('[CrmSync] Failed to sync automation rules:', error.message)
    }
  }

  private async syncSequences(): Promise<void> {
    try {
      const response = await this.api.get('/crm/sequences')
      if (response.data.success && response.data.data) {
        const sequences = Array.isArray(response.data.data) ? response.data.data : response.data.data.sequences || []
        for (const seq of sequences) {
          this.upsertSequence(seq)
        }
        console.log(`[CrmSync] Synced ${sequences.length} sequences`)
      }
    } catch (error: any) {
      console.error('[CrmSync] Failed to sync sequences:', error.message)
    }
  }

  private async syncExpenses(): Promise<void> {
    try {
      const response = await this.api.get('/crm/expenses')
      if (response.data.success && response.data.data) {
        const expenses = Array.isArray(response.data.data) ? response.data.data : response.data.data.expenses || []
        for (const expense of expenses) {
          this.upsertExpense(expense)
        }
        console.log(`[CrmSync] Synced ${expenses.length} expenses`)
      }
    } catch (error: any) {
      console.error('[CrmSync] Failed to sync expenses:', error.message)
    }
  }

  private async syncBillingSettings(): Promise<void> {
    try {
      const response = await this.api.get('/crm/billing/settings')
      if (response.data.success && response.data.data) {
        this.upsertBillingSettings(response.data.data)
        console.log('[CrmSync] Synced billing settings')
      }
    } catch (error: any) {
      // Not critical - may not have billing configured
      console.warn('[CrmSync] Failed to sync billing settings:', error.message)
    }
  }

  private async syncShares(): Promise<void> {
    try {
      const response = await this.api.get('/crm/sharing')
      if (response.data.success && response.data.data) {
        const shares = response.data.data.shares || []
        for (const share of shares) {
          this.upsertShare(share)
        }
        console.log(`[CrmSync] Synced ${shares.length} shares`)
      }
    } catch (error: any) {
      console.warn('[CrmSync] Failed to sync shares:', error.message)
    }
  }

  // ============================================
  // UPSERT METHODS
  // ============================================

  private upsertDeal(deal: any): void {
    this.db.prepare(`
      INSERT INTO crm_deals (
        remote_id, client_id, user_email, title, description, pipeline_stage,
        expected_value, currency, probability, expected_close_date, actual_close_date,
        lost_reason, contact_id, assigned_to, board_id, invoice_id, sync_status,
        created_at, updated_at
      ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'synced', ?, ?)
      ON CONFLICT(remote_id) DO UPDATE SET
        client_id = excluded.client_id,
        title = excluded.title,
        description = excluded.description,
        pipeline_stage = excluded.pipeline_stage,
        expected_value = excluded.expected_value,
        currency = excluded.currency,
        probability = excluded.probability,
        expected_close_date = excluded.expected_close_date,
        actual_close_date = excluded.actual_close_date,
        lost_reason = excluded.lost_reason,
        contact_id = excluded.contact_id,
        assigned_to = excluded.assigned_to,
        board_id = excluded.board_id,
        invoice_id = excluded.invoice_id,
        sync_status = 'synced',
        updated_at = excluded.updated_at
    `).run(
      deal.id, deal.client_id, deal.user_email, deal.title, deal.description || null,
      deal.pipeline_stage || 'lead', deal.expected_value || null, deal.currency || 'HUF',
      deal.probability ?? 50, deal.expected_close_date || null, deal.actual_close_date || null,
      deal.lost_reason || null, deal.contact_id || null, deal.assigned_to || null,
      deal.board_id || null, deal.invoice_id || null,
      deal.created_at || null, deal.updated_at || null
    )
  }

  private upsertInvoice(invoice: any): void {
    this.db.prepare(`
      INSERT INTO crm_invoices (
        remote_id, client_id, user_email, invoice_number, status, issue_date, due_date,
        subtotal, tax_rate, tax_amount, discount_amount, total, currency,
        paid_amount, paid_at, payment_method, payment_reference, notes, internal_notes,
        is_recurring, recurrence_interval, billing_provider, external_invoice_id,
        sent_at, viewed_at, sync_status, created_at, updated_at
      ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'synced', ?, ?)
      ON CONFLICT(remote_id) DO UPDATE SET
        client_id = excluded.client_id,
        invoice_number = excluded.invoice_number,
        status = excluded.status,
        subtotal = excluded.subtotal,
        total = excluded.total,
        paid_amount = excluded.paid_amount,
        paid_at = excluded.paid_at,
        sent_at = excluded.sent_at,
        viewed_at = excluded.viewed_at,
        billing_provider = excluded.billing_provider,
        sync_status = 'synced',
        updated_at = excluded.updated_at
    `).run(
      invoice.id, invoice.client_id, invoice.user_email, invoice.invoice_number,
      invoice.status || 'draft', invoice.issue_date, invoice.due_date,
      invoice.subtotal || 0, invoice.tax_rate || 0, invoice.tax_amount || 0,
      invoice.discount_amount || 0, invoice.total || 0, invoice.currency || 'HUF',
      invoice.paid_amount || 0, invoice.paid_at || null, invoice.payment_method || null,
      invoice.payment_reference || null, invoice.notes || null, invoice.internal_notes || null,
      invoice.is_recurring ? 1 : 0, invoice.recurrence_interval || null,
      invoice.billing_provider || 'manual', invoice.external_invoice_id || null,
      invoice.sent_at || null, invoice.viewed_at || null,
      invoice.created_at || null, invoice.updated_at || null
    )
  }

  private upsertInvoiceItem(invoiceRemoteId: number, item: any): void {
    const localInvoice = this.db.prepare(
      'SELECT id FROM crm_invoices WHERE remote_id = ?'
    ).get(invoiceRemoteId) as { id: number } | undefined
    if (!localInvoice) return

    this.db.prepare(`
      INSERT INTO crm_invoice_items (remote_id, invoice_id, description, quantity, unit, unit_price, tax_rate, total, sort_order)
      VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
      ON CONFLICT(remote_id) DO UPDATE SET
        description = excluded.description,
        quantity = excluded.quantity,
        unit_price = excluded.unit_price,
        total = excluded.total,
        sort_order = excluded.sort_order
    `).run(
      item.id, localInvoice.id, item.description, item.quantity || 1,
      item.unit || null, item.unit_price, item.tax_rate || null,
      item.total, item.sort_order || 0
    )
  }

  private upsertTag(tag: any): void {
    this.db.prepare(`
      INSERT INTO crm_tags (remote_id, user_email, name, color, tag_group, sync_status)
      VALUES (?, ?, ?, ?, ?, 'synced')
      ON CONFLICT(remote_id) DO UPDATE SET
        name = excluded.name, color = excluded.color,
        tag_group = excluded.tag_group, sync_status = 'synced'
    `).run(tag.id, tag.user_email, tag.name, tag.color || '#6366f1', tag.tag_group || null)
  }

  private upsertReminder(reminder: any): void {
    this.db.prepare(`
      INSERT INTO crm_reminders (
        remote_id, client_id, user_email, title, description, remind_at,
        is_completed, completed_at, is_recurring, recurrence_interval,
        contact_id, deal_id, notification_sent, sync_status, created_at
      ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'synced', ?)
      ON CONFLICT(remote_id) DO UPDATE SET
        title = excluded.title, description = excluded.description,
        remind_at = excluded.remind_at, is_completed = excluded.is_completed,
        completed_at = excluded.completed_at, sync_status = 'synced'
    `).run(
      reminder.id, reminder.client_id, reminder.user_email, reminder.title,
      reminder.description || null, reminder.remind_at,
      reminder.is_completed ? 1 : 0, reminder.completed_at || null,
      reminder.is_recurring ? 1 : 0, reminder.recurrence_interval || null,
      reminder.contact_id || null, reminder.deal_id || null,
      reminder.notification_sent ? 1 : 0, reminder.created_at || null
    )
  }

  private upsertCallLog(call: any): void {
    this.db.prepare(`
      INSERT INTO crm_call_log (
        remote_id, client_id, user_email, contact_id, direction,
        duration_minutes, outcome, notes, call_date, sync_status, created_at
      ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'synced', ?)
      ON CONFLICT(remote_id) DO UPDATE SET
        notes = excluded.notes, outcome = excluded.outcome, sync_status = 'synced'
    `).run(
      call.id, call.client_id, call.user_email, call.contact_id || null,
      call.direction, call.duration_minutes || null, call.outcome || 'connected',
      call.notes || null, call.call_date, call.created_at || null
    )
  }

  private upsertMeetingNote(note: any): void {
    this.db.prepare(`
      INSERT INTO crm_meeting_notes (
        remote_id, client_id, user_email, title, content, meeting_date,
        attendees, action_items, calendar_event_id, portal_call_id, deal_id,
        sync_status, created_at, updated_at
      ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'synced', ?, ?)
      ON CONFLICT(remote_id) DO UPDATE SET
        title = excluded.title, content = excluded.content,
        attendees = excluded.attendees, action_items = excluded.action_items,
        sync_status = 'synced', updated_at = excluded.updated_at
    `).run(
      note.id, note.client_id, note.user_email, note.title, note.content || null,
      note.meeting_date, JSON.stringify(note.attendees || []),
      JSON.stringify(note.action_items || []), note.calendar_event_id || null,
      note.portal_call_id || null, note.deal_id || null,
      note.created_at || null, note.updated_at || null
    )
  }

  private upsertAutomationRule(rule: any): void {
    this.db.prepare(`
      INSERT INTO crm_automation_rules (
        remote_id, user_email, name, description, is_active,
        trigger_type, trigger_config, action_type, action_config,
        last_run_at, run_count, sync_status, created_at, updated_at
      ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'synced', ?, ?)
      ON CONFLICT(remote_id) DO UPDATE SET
        name = excluded.name, is_active = excluded.is_active,
        trigger_config = excluded.trigger_config, action_config = excluded.action_config,
        last_run_at = excluded.last_run_at, run_count = excluded.run_count,
        sync_status = 'synced', updated_at = excluded.updated_at
    `).run(
      rule.id, rule.user_email, rule.name, rule.description || null,
      rule.is_active ? 1 : 0, rule.trigger_type,
      JSON.stringify(rule.trigger_config), rule.action_type,
      JSON.stringify(rule.action_config), rule.last_run_at || null,
      rule.run_count || 0, rule.created_at || null, rule.updated_at || null
    )
  }

  private upsertSequence(seq: any): void {
    this.db.prepare(`
      INSERT INTO crm_sequences (
        remote_id, user_email, name, description, trigger_stage, is_active,
        steps, sync_status, created_at, updated_at
      ) VALUES (?, ?, ?, ?, ?, ?, ?, 'synced', ?, ?)
      ON CONFLICT(remote_id) DO UPDATE SET
        name = excluded.name, description = excluded.description,
        trigger_stage = excluded.trigger_stage, is_active = excluded.is_active,
        steps = excluded.steps, sync_status = 'synced', updated_at = excluded.updated_at
    `).run(
      seq.id, seq.user_email, seq.name, seq.description || null,
      seq.trigger_stage || null, seq.is_active ? 1 : 0,
      JSON.stringify(seq.steps), seq.created_at || null, seq.updated_at || null
    )
  }

  private upsertExpense(expense: any): void {
    this.db.prepare(`
      INSERT INTO crm_expenses (
        remote_id, client_id, user_email, description, amount, currency,
        expense_date, category, notes, sync_status, created_at
      ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'synced', ?)
      ON CONFLICT(remote_id) DO UPDATE SET
        description = excluded.description, amount = excluded.amount,
        category = excluded.category, sync_status = 'synced'
    `).run(
      expense.id, expense.client_id, expense.user_email, expense.description,
      expense.amount, expense.currency || 'HUF', expense.expense_date,
      expense.category || null, expense.notes || null, expense.created_at || null
    )
  }

  private upsertBillingSettings(settings: any): void {
    this.db.prepare(`
      INSERT INTO crm_billing_settings (
        remote_id, user_email, provider, company_name, company_address,
        company_tax_number, company_eu_tax_number, company_bank_account,
        company_bank_name, company_email, company_phone,
        default_currency, default_tax_rate, default_payment_terms_days,
        default_payment_method, default_language, sync_status
      ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'synced')
      ON CONFLICT(remote_id) DO UPDATE SET
        provider = excluded.provider,
        company_name = excluded.company_name,
        default_currency = excluded.default_currency,
        sync_status = 'synced'
    `).run(
      settings.id || 1, settings.user_email, settings.provider || 'none',
      settings.company_name || null, settings.company_address || null,
      settings.company_tax_number || null, settings.company_eu_tax_number || null,
      settings.company_bank_account || null, settings.company_bank_name || null,
      settings.company_email || null, settings.company_phone || null,
      settings.default_currency || 'HUF', settings.default_tax_rate ?? 27,
      settings.default_payment_terms_days ?? 8,
      settings.default_payment_method || 'bank_transfer',
      settings.default_language || 'hu'
    )
  }

  private upsertShare(share: any): void {
    this.db.prepare(`
      INSERT INTO crm_shares (
        remote_id, owner_email, shared_with_email, permission, is_active,
        sync_status, created_at, updated_at
      ) VALUES (?, ?, ?, ?, ?, 'synced', ?, ?)
      ON CONFLICT(remote_id) DO UPDATE SET
        permission = excluded.permission, is_active = excluded.is_active,
        sync_status = 'synced', updated_at = excluded.updated_at
    `).run(
      share.id, share.owner_email, share.shared_with_email,
      share.permission || 'viewer', share.is_active ? 1 : 0,
      share.created_at || null, share.updated_at || null
    )
  }

  // ============================================
  // PUSH CHANGES
  // ============================================

  async pushChange(queueItem: QueueItem): Promise<void> {
    const payload = JSON.parse(queueItem.payload)

    switch (queueItem.action) {
      // Deal actions
      case 'create_deal': {
        const res = await this.api.post('/crm/deals', payload)
        if (res.data.success && res.data.data?.id) {
          this.db.prepare('UPDATE crm_deals SET remote_id = ?, sync_status = "synced" WHERE id = ?')
            .run(res.data.data.id, queueItem.entity_id)
        }
        break
      }
      case 'update_deal':
        await this.api.put(`/crm/deals/${payload.remote_id}`, payload)
        break
      case 'delete_deal':
        await this.api.delete(`/crm/deals/${payload.remote_id}`)
        break

      // Invoice actions
      case 'create_invoice': {
        const res = await this.api.post('/crm/invoices', payload)
        if (res.data.success && res.data.data?.id) {
          this.db.prepare('UPDATE crm_invoices SET remote_id = ?, sync_status = "synced" WHERE id = ?')
            .run(res.data.data.id, queueItem.entity_id)
        }
        break
      }
      case 'update_invoice':
        await this.api.put(`/crm/invoices/${payload.remote_id}`, payload)
        break

      // Reminder actions
      case 'create_reminder': {
        const res = await this.api.post('/crm/reminders', payload)
        if (res.data.success && res.data.data?.id) {
          this.db.prepare('UPDATE crm_reminders SET remote_id = ?, sync_status = "synced" WHERE id = ?')
            .run(res.data.data.id, queueItem.entity_id)
        }
        break
      }
      case 'complete_reminder':
        await this.api.put(`/crm/reminders/${payload.remote_id}/complete`)
        break

      // Call log actions
      case 'create_call_log': {
        const res = await this.api.post('/crm/call-log', payload)
        if (res.data.success && res.data.data?.id) {
          this.db.prepare('UPDATE crm_call_log SET remote_id = ?, sync_status = "synced" WHERE id = ?')
            .run(res.data.data.id, queueItem.entity_id)
        }
        break
      }

      // Meeting note actions
      case 'create_meeting_note': {
        const res = await this.api.post('/crm/meeting-notes', payload)
        if (res.data.success && res.data.data?.id) {
          this.db.prepare('UPDATE crm_meeting_notes SET remote_id = ?, sync_status = "synced" WHERE id = ?')
            .run(res.data.data.id, queueItem.entity_id)
        }
        break
      }
      case 'update_meeting_note':
        await this.api.put(`/crm/meeting-notes/${payload.remote_id}`, payload)
        break

      // Expense actions
      case 'create_expense': {
        const res = await this.api.post('/crm/expenses', payload)
        if (res.data.success && res.data.data?.id) {
          this.db.prepare('UPDATE crm_expenses SET remote_id = ?, sync_status = "synced" WHERE id = ?')
            .run(res.data.data.id, queueItem.entity_id)
        }
        break
      }

      default:
        console.warn(`[CrmSync] Unknown action: ${queueItem.action}`)
    }
  }

  // ============================================
  // WEBSOCKET EVENT HANDLER
  // ============================================

  async handleEvent(event: SyncEvent): Promise<void> {
    console.log(`[CrmSync] Handling event: ${event.type}`)

    switch (event.type) {
      case 'CRM_DEAL_CREATED':
      case 'CRM_DEAL_UPDATED':
        this.upsertDeal(event.payload)
        break
      case 'CRM_DEAL_DELETED':
        this.db.prepare('DELETE FROM crm_deals WHERE remote_id = ?').run(event.payload.id)
        break
      case 'CRM_DEAL_STAGE_CHANGED':
        this.db.prepare('UPDATE crm_deals SET pipeline_stage = ? WHERE remote_id = ?')
          .run(event.payload.stage, event.payload.deal_id)
        break
      case 'CRM_INVOICE_CREATED':
      case 'CRM_INVOICE_UPDATED':
        this.upsertInvoice(event.payload)
        break
      case 'CRM_INVOICE_PAID':
        this.db.prepare('UPDATE crm_invoices SET status = "paid", paid_at = ? WHERE remote_id = ?')
          .run(event.payload.paid_at || new Date().toISOString(), event.payload.id)
        break
      case 'CRM_REMINDER_CREATED':
      case 'CRM_REMINDER_UPDATED':
        this.upsertReminder(event.payload)
        break
      case 'CRM_REMINDER_COMPLETED':
        this.db.prepare('UPDATE crm_reminders SET is_completed = 1, completed_at = ? WHERE remote_id = ?')
          .run(new Date().toISOString(), event.payload.id)
        break
      case 'CRM_TAG_CREATED':
      case 'CRM_TAG_UPDATED':
        this.upsertTag(event.payload)
        break
    }

    this.emit('data-updated', { type: event.type, payload: event.payload })
  }
}

