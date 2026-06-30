import { BaseSyncEngine, QueueItem, SyncEvent } from '../BaseSyncEngine'
import { LocalDatabase, CalendarEvent } from '../../database/Database'

/**
 * Calendar Sync Engine
 * 
 * Handles synchronization of calendar data:
 * - Calendars (user's calendars list)
 * - Events (with recurrence support)
 * - Participants and RSVP status
 * 
 * Sync Strategy:
 * - Initial sync: Fetch all calendars + events for -30d to +365d
 * - Incremental: WebSocket events + periodic delta poll
 * - Offline support: Create/update/delete events locally, sync when online
 */
export class CalendarSyncEngine extends BaseSyncEngine {
  entityType = 'calendar'

  constructor(db: LocalDatabase) {
    super(db)
  }

  /**
   * Pull changes from server
   */
  async pullChanges(): Promise<void> {
    console.log('[CalendarSync] Pulling changes...')
    
    // 1. Sync calendars first
    await this.syncCalendars()
    
    // 2. Sync events
    await this.syncEvents()
  }

  /**
   * Sync calendar list
   */
  private async syncCalendars(): Promise<void> {
    try {
      const response = await this.api.get('/calendars')
      
      if (response.data.success && response.data.data) {
        // API returns { data: { calendars: [...] } } or { data: [...] }
        const calendars = Array.isArray(response.data.data) 
          ? response.data.data 
          : response.data.data.calendars || []
        
        for (const calendar of calendars) {
          this.db.prepare(`
            INSERT INTO calendars (remote_id, name, color, description, is_default, is_visible, can_edit, sync_source, external_id, sync_status, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'synced', datetime('now'))
            ON CONFLICT(remote_id) DO UPDATE SET
              name = excluded.name,
              color = excluded.color,
              description = excluded.description,
              is_visible = excluded.is_visible,
              can_edit = excluded.can_edit,
              sync_status = 'synced',
              updated_at = datetime('now')
          `).run(
            calendar.id,
            calendar.name,
            calendar.color || '#0ea5e9',
            calendar.description || null,
            calendar.is_default ? 1 : 0,
            calendar.is_visible !== false ? 1 : 0,
            calendar.can_edit !== false ? 1 : 0,
            calendar.sync_source || 'local',
            calendar.external_id || null
          )
        }
        
        console.log(`[CalendarSync] Synced ${calendars.length} calendars`)
      }
    } catch (error: any) {
      console.error('[CalendarSync] Failed to sync calendars:', error.message)
      throw error
    }
  }

  /**
   * Sync events for date range
   */
  private async syncEvents(): Promise<void> {
    try {
      // Sync events from 30 days ago to 365 days in future
      const startDate = new Date()
      startDate.setDate(startDate.getDate() - 30)
      
      const endDate = new Date()
      endDate.setFullYear(endDate.getFullYear() + 1)
      
      const response = await this.api.get('/events', {
        params: {
          start: startDate.toISOString(),
          end: endDate.toISOString(),
        }
      })
      
      if (response.data.success && response.data.data) {
        // API returns { data: { events: [...] } } or { data: [...] }
        const events = Array.isArray(response.data.data) 
          ? response.data.data 
          : response.data.data.events || []
        
        for (const event of events) {
          this.upsertEvent(event)
        }
        
        console.log(`[CalendarSync] Synced ${events.length} events`)
      }
    } catch (error: any) {
      console.error('[CalendarSync] Failed to sync events:', error.message)
      throw error
    }
  }

  /**
   * Insert or update an event in local DB
   */
  private upsertEvent(event: any): void {
    // Get local calendar ID
    const calendar = this.db.prepare(
      'SELECT id FROM calendars WHERE remote_id = ?'
    ).get(event.calendar_id) as { id: number } | undefined
    
    if (!calendar) {
      console.warn(`[CalendarSync] Calendar not found for event: ${event.id}`)
      return
    }
    
    this.db.prepare(`
      INSERT INTO calendar_events (
        remote_id, calendar_id, title, description, location,
        start_time, end_time, all_day, timezone, recurrence_rule, recurrence_id,
        color, status, visibility, busy_status, reminders, attendees, organizer,
        linked_email_uid, linked_email_folder, external_id,
        sync_status, remote_updated_at, created_at
      ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'synced', ?, datetime('now'))
      ON CONFLICT(remote_id) DO UPDATE SET
        calendar_id = excluded.calendar_id,
        title = excluded.title,
        description = excluded.description,
        location = excluded.location,
        start_time = excluded.start_time,
        end_time = excluded.end_time,
        all_day = excluded.all_day,
        recurrence_rule = excluded.recurrence_rule,
        color = excluded.color,
        status = excluded.status,
        reminders = excluded.reminders,
        attendees = excluded.attendees,
        sync_status = 'synced',
        remote_updated_at = excluded.remote_updated_at
    `).run(
      event.id,
      calendar.id,
      event.title,
      event.description || null,
      event.location || null,
      event.start_time || event.start,
      event.end_time || event.end,
      event.all_day ? 1 : 0,
      event.timezone || null,
      event.recurrence_rule || event.rrule || null,
      event.recurrence_id || null,
      event.color || null,
      event.status || 'confirmed',
      event.visibility || 'default',
      event.busy_status || 'busy',
      JSON.stringify(event.reminders || []),
      JSON.stringify(event.attendees || []),
      event.organizer || null,
      event.linked_email_uid || null,
      event.linked_email_folder || null,
      event.external_id || null,
      event.updated_at || null
    )
    
    // Sync participants if present
    if (event.participants && Array.isArray(event.participants)) {
      const localEvent = this.db.prepare(
        'SELECT id FROM calendar_events WHERE remote_id = ?'
      ).get(event.id) as { id: number } | undefined
      
      if (localEvent) {
        for (const participant of event.participants) {
          this.db.prepare(`
            INSERT INTO event_participants (event_id, email, name, status, role)
            VALUES (?, ?, ?, ?, ?)
            ON CONFLICT(event_id, email) DO UPDATE SET
              name = excluded.name,
              status = excluded.status,
              role = excluded.role
          `).run(
            localEvent.id,
            participant.email,
            participant.name || null,
            participant.status || 'pending',
            participant.role || 'attendee'
          )
        }
      }
    }
  }

  /**
   * Push a queued change to server
   */
  async pushChange(queueItem: QueueItem): Promise<void> {
    const payload = JSON.parse(queueItem.payload)
    
    switch (queueItem.action) {
      case 'create':
        const createResponse = await this.api.post('/events', payload)
        if (createResponse.data.success && createResponse.data.data?.id) {
          // Update local event with remote ID
          this.db.prepare(`
            UPDATE calendar_events SET remote_id = ?, sync_status = 'synced'
            WHERE id = ?
          `).run(createResponse.data.data.id, queueItem.entity_id)
        }
        break
        
      case 'update':
        await this.api.put(`/events/${payload.remote_id}`, payload)
        break
        
      case 'delete':
        await this.api.delete(`/events/${payload.remote_id}`)
        break
        
      case 'rsvp':
        await this.api.post(`/events/${payload.event_id}/rsvp`, {
          status: payload.status
        })
        break
        
      default:
        console.warn(`[CalendarSync] Unknown action: ${queueItem.action}`)
    }
  }

  /**
   * Handle incoming WebSocket event
   */
  async handleEvent(event: SyncEvent): Promise<void> {
    console.log(`[CalendarSync] Handling event: ${event.type}`)
    
    switch (event.type) {
      case 'CALENDAR_EVENT_CREATED':
        this.upsertEvent(event.payload)
        break
        
      case 'CALENDAR_EVENT_UPDATED':
        this.upsertEvent(event.payload)
        break
        
      case 'CALENDAR_EVENT_DELETED':
        this.db.prepare('DELETE FROM calendar_events WHERE remote_id = ?').run(event.payload.id)
        break
        
      case 'CALENDAR_CHANGED':
        await this.syncCalendars()
        break
        
      case 'CALENDAR_RSVP_CHANGED':
        if (event.payload.event_id && event.payload.email) {
          const localEvent = this.db.prepare(
            'SELECT id FROM calendar_events WHERE remote_id = ?'
          ).get(event.payload.event_id) as { id: number } | undefined
          
          if (localEvent) {
            this.db.prepare(`
              UPDATE event_participants SET status = ? WHERE event_id = ? AND email = ?
            `).run(event.payload.status, localEvent.id, event.payload.email)
          }
        }
        break

      case 'CALENDAR_UPDATED':
        // Generic update from backend — pull fresh data from server
        await this.syncEvents()
        await this.syncCalendars()
        break
    }
    
    // Emit event for UI update
    this.emit('data-updated', { type: event.type, payload: event.payload })
  }

  // ============================================
  // PUBLIC METHODS FOR LOCAL ACTIONS
  // ============================================

  /**
   * Create event (local-first)
   */
  async createEvent(eventData: CreateEventData): Promise<number> {
    return await this.performAction(
      // Local action
      () => {
        const calendar = this.db.prepare(
          'SELECT id FROM calendars WHERE remote_id = ?'
        ).get(eventData.calendar_id) as { id: number } | undefined
        
        if (!calendar) {
          throw new Error('Calendar not found')
        }
        
        const result = this.db.prepare(`
          INSERT INTO calendar_events (
            calendar_id, title, description, location,
            start_time, end_time, all_day, timezone, recurrence_rule,
            color, status, reminders, sync_status, created_at
          ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', datetime('now'))
        `).run(
          calendar.id,
          eventData.title,
          eventData.description || null,
          eventData.location || null,
          eventData.start_time,
          eventData.end_time,
          eventData.all_day ? 1 : 0,
          eventData.timezone || null,
          eventData.recurrence_rule || null,
          eventData.color || null,
          'confirmed',
          JSON.stringify(eventData.reminders || [])
        )
        
        return result.lastInsertRowid as number
      },
      // Remote action
      async () => {
        const response = await this.api.post('/events', eventData)
        if (response.data.success && response.data.data?.id) {
          // This will be handled by queue processing
        }
      },
      // Queue data
      { action: 'create', entityId: null, payload: eventData }
    )
  }

  /**
   * Update event (local-first)
   */
  async updateEvent(eventId: number, remoteId: number, updates: Partial<CreateEventData>): Promise<void> {
    await this.performAction(
      // Local action
      () => {
        const setClauses: string[] = []
        const values: any[] = []
        
        Object.entries(updates).forEach(([key, value]) => {
          if (key === 'reminders') {
            setClauses.push('reminders = ?')
            values.push(JSON.stringify(value))
          } else if (key === 'all_day') {
            setClauses.push('all_day = ?')
            values.push(value ? 1 : 0)
          } else {
            setClauses.push(`${key} = ?`)
            values.push(value)
          }
        })
        
        setClauses.push('local_updated_at = datetime("now")')
        setClauses.push('sync_status = "pending"')
        
        values.push(eventId)
        
        this.db.prepare(`
          UPDATE calendar_events SET ${setClauses.join(', ')} WHERE id = ?
        `).run(...values)
      },
      // Remote action
      async () => {
        await this.api.put(`/events/${remoteId}`, updates)
      },
      // Queue data
      { action: 'update', entityId: eventId, payload: { remote_id: remoteId, ...updates } }
    )
  }

  /**
   * Delete event (local-first)
   */
  async deleteEvent(eventId: number, remoteId: number): Promise<void> {
    await this.performAction(
      // Local action
      () => {
        this.db.prepare('DELETE FROM calendar_events WHERE id = ?').run(eventId)
        this.db.prepare('DELETE FROM event_participants WHERE event_id = ?').run(eventId)
      },
      // Remote action
      async () => {
        await this.api.delete(`/events/${remoteId}`)
      },
      // Queue data
      { action: 'delete', entityId: eventId, payload: { remote_id: remoteId } }
    )
  }

  /**
   * Respond to event invite (local-first)
   */
  async respondToInvite(eventId: number, remoteId: number, status: 'accepted' | 'declined' | 'tentative'): Promise<void> {
    await this.performAction(
      // Local action - update local participant status
      () => {
        // We'd need the user's email to update the right participant
        // For now, just mark the event
        this.db.prepare(`
          UPDATE calendar_events SET status = ?, local_updated_at = datetime('now') WHERE id = ?
        `).run(status === 'accepted' ? 'confirmed' : status, eventId)
      },
      // Remote action
      async () => {
        await this.api.post(`/events/${remoteId}/rsvp`, { status })
      },
      // Queue data
      { action: 'rsvp', entityId: eventId, payload: { event_id: remoteId, status } }
    )
  }

  /**
   * Get events for a date range
   */
  getEvents(startDate: string, endDate: string, calendarId?: number): CalendarEvent[] {
    return this.db.getEvents(startDate, endDate, calendarId)
  }

  /**
   * Get all calendars
   */
  getCalendars(): any[] {
    return this.db.getCalendars()
  }
}

/**
 * Event creation data
 */
interface CreateEventData {
  calendar_id: number
  title: string
  description?: string
  location?: string
  start_time: string
  end_time: string
  all_day?: boolean
  timezone?: string
  recurrence_rule?: string
  color?: string
  reminders?: Array<{ minutes: number; method: string }>
  attendees?: Array<{ email: string; name?: string }>
}

