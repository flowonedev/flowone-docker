import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import api from '@/services/api'
import browserNotifications from '@/services/browserNotifications'
import { isDebugEnabled } from '@/utils/debug'
import { useAddons } from '@/composables/useAddons'
import { withOfflineFallback, getOfflineCalendars, getOfflineEvents } from '@/services/offlineData'

// Track events silently (non-blocking)
async function trackEvent(eventType, eventData = {}) {
  try {
    await api.post('/statistics/log-event', { event_type: eventType, event_data: eventData })
  } catch (e) {
    // Silent fail
  }
}

export const useCalendarStore = defineStore('calendar', () => {
  const calendars = ref([])
  const sharedCalendars = ref([]) // Calendars shared with me by others
  const events = ref([])
  const loading = ref(false)
  const currentDate = ref(new Date())
  const viewMode = ref('month') // 'month', 'week', 'day'
  
  // Cache tracking - prevent unnecessary reloads
  const eventsFetchedTime = ref(null)
  const eventsFetched = ref(false)
  const CACHE_DURATION = 300000 // 5 minutes
  let latestCalendarsRequestId = 0
  let latestEventsRequestId = 0
  
  // Calendar visibility state (stored in localStorage)
  let _hiddenCalendarIds = []
  try { _hiddenCalendarIds = JSON.parse(localStorage.getItem('calendar_hidden') || '[]') } catch {}
  const hiddenCalendars = ref(new Set(_hiddenCalendarIds))
  
  // Calendar preset colors
  const calendarColors = [
    { id: 'red', name: 'Red', hex: '#ef4444' },
    { id: 'orange', name: 'Orange', hex: '#f97316' },
    { id: 'yellow', name: 'Yellow', hex: '#eab308' },
    { id: 'green', name: 'Green', hex: '#22c55e' },
    { id: 'teal', name: 'Teal', hex: '#14b8a6' },
    { id: 'blue', name: 'Blue', hex: '#3b82f6' },
    { id: 'purple', name: 'Purple', hex: '#a855f7' },
    { id: 'pink', name: 'Pink', hex: '#ec4899' },
    { id: 'gray', name: 'Gray', hex: '#6b7280' },
    { id: 'brown', name: 'Brown', hex: '#92400e' },
  ]
  
  // Google Calendar sync state
  const googleCalendars = ref([])
  const syncConfigs = ref([])
  const syncing = ref(false)
  
  // Auto-sync settings
  const autoSyncEnabled = ref(localStorage.getItem('calendar_auto_sync_enabled') === 'true')
  const autoSyncInterval = ref(parseInt(localStorage.getItem('calendar_auto_sync_interval') || '60')) // minutes
  const autoSyncIntervalRef = ref(null)
  const lastAutoSync = ref(localStorage.getItem('calendar_last_auto_sync') || null)
  
  // Event reminder tracking
  const reminderInterval = ref(null)
  const notifiedEvents = ref(new Set()) // Track events we've already notified about

  // In-app reminder popups (Teams-style).
  // Each entry: { key, eventId, title, startTime, endTime, is_meeting, isHost, color }
  // Dismiss/snooze state is persisted so reminders don't re-appear on refresh.
  const REMINDER_DISMISSED_KEY = 'calendar_reminders_dismissed'
  const REMINDER_SNOOZED_KEY = 'calendar_reminders_snoozed'
  const REMINDER_DISMISS_TTL = 24 * 3600 * 1000 // prune dismissals after 24h

  function _loadReminderMap(storageKey) {
    const map = new Map()
    try {
      const raw = JSON.parse(localStorage.getItem(storageKey) || '[]')
      if (Array.isArray(raw)) {
        for (const entry of raw) {
          if (Array.isArray(entry) && entry.length === 2) map.set(String(entry[0]), Number(entry[1]) || 0)
        }
      }
    } catch { /* ignore corrupt state */ }
    return map
  }

  const activeReminders = ref([])
  const dismissedReminders = ref(_loadReminderMap(REMINDER_DISMISSED_KEY)) // key -> dismissedAt (ms)
  const snoozedReminders = ref(_loadReminderMap(REMINDER_SNOOZED_KEY))     // key -> until (ms)

  function _persistReminderState() {
    try {
      localStorage.setItem(REMINDER_DISMISSED_KEY, JSON.stringify([...dismissedReminders.value.entries()]))
      localStorage.setItem(REMINDER_SNOOZED_KEY, JSON.stringify([...snoozedReminders.value.entries()]))
    } catch { /* ignore quota errors */ }
  }

  // Prune stale persisted state on init (old dismissals, expired snoozes).
  ;(function pruneReminderState() {
    const nowMs = Date.now()
    let changed = false
    for (const [k, ts] of dismissedReminders.value) {
      if (nowMs - ts > REMINDER_DISMISS_TTL) { dismissedReminders.value.delete(k); changed = true }
    }
    for (const [k, until] of snoozedReminders.value) {
      if (nowMs >= until) { snoozedReminders.value.delete(k); changed = true }
    }
    if (changed) _persistReminderState()
  })()
  
  // Lightweight today-only events for reminders (does NOT pollute full events cache)
  const _reminderEvents = ref([])
  
  // Real-time "now" ref - ticks every 60s for countdown display
  const now = ref(new Date())
  const nowTickerRef = ref(null)
  
  // Pending event data for pre-filling the event modal from outside CalendarView
  const pendingEventData = ref(null)
  
  function setPendingEvent(data) {
    pendingEventData.value = data
  }
  
  function clearPendingEvent() {
    pendingEventData.value = null
  }

  const defaultCalendar = computed(() => {
    return calendars.value.find(c => c.is_default) || calendars.value[0] || null
  })

  // Get events for current view
  const viewEvents = computed(() => {
    return events.value
  })

  // Calendar navigation helpers
  const currentMonth = computed(() => currentDate.value.getMonth())
  const currentYear = computed(() => currentDate.value.getFullYear())
  
  const monthName = computed(() => {
    return currentDate.value.toLocaleDateString('en-US', { month: 'long', year: 'numeric' })
  })

  // Get days in month grid (6 weeks, Monday-start)
  const monthDays = computed(() => {
    const year = currentDate.value.getFullYear()
    const month = currentDate.value.getMonth()
    
    const firstDay = new Date(year, month, 1)
    const lastDay = new Date(year, month + 1, 0)
    
    // Monday-start offset: Mon=0, Tue=1, ..., Sun=6
    const rawDay = firstDay.getDay() // 0=Sun,1=Mon,...,6=Sat
    const startOffset = rawDay === 0 ? 6 : rawDay - 1
    const daysInMonth = lastDay.getDate()
    
    const days = []
    
    // Previous month days
    const prevMonth = new Date(year, month, 0)
    const prevMonthDays = prevMonth.getDate()
    for (let i = startOffset - 1; i >= 0; i--) {
      days.push({
        date: new Date(year, month - 1, prevMonthDays - i),
        isCurrentMonth: false,
        isToday: false,
      })
    }
    
    // Current month days
    const today = new Date()
    for (let i = 1; i <= daysInMonth; i++) {
      const date = new Date(year, month, i)
      days.push({
        date,
        isCurrentMonth: true,
        isToday: date.toDateString() === today.toDateString(),
      })
    }
    
    // Next month days (to fill 6 weeks = 42 days)
    const remaining = 42 - days.length
    for (let i = 1; i <= remaining; i++) {
      days.push({
        date: new Date(year, month + 1, i),
        isCurrentMonth: false,
        isToday: false,
      })
    }
    
    return days
  })

  // Filtered events based on visibility settings
  const filteredEvents = computed(() => {
    return events.value.filter(event => {
      // Always show events without a calendar_id (legacy)
      if (!event.calendar_id) return true
      // Hide if calendar is hidden
      return !hiddenCalendars.value.has(event.calendar_id)
    })
  })

  // Get events for a specific day (respects visibility)
  function getEventsForDay(date) {
    const dayStart = new Date(date)
    dayStart.setHours(0, 0, 0, 0)
    const dayEnd = new Date(date)
    dayEnd.setHours(23, 59, 59, 999)
    
    return filteredEvents.value.filter(event => {
      if (!event.start_time) return false
      const eventStart = new Date(event.start_time)
      const eventEnd = event.end_time ? new Date(event.end_time) : eventStart
      
      // Event overlaps with this day
      return eventStart <= dayEnd && eventEnd >= dayStart
    })
  }
  
  // Get the real parent event ID for a recurring instance
  function getParentEventId(event) {
    return event.recurrence_parent_id || event.id
  }
  
  // Check if an event is a virtual recurrence instance
  function isRecurrenceInstance(event) {
    return !!event.is_recurrence_instance
  }
  
  // Toggle calendar visibility
  function toggleCalendarVisibility(calendarId) {
    if (hiddenCalendars.value.has(calendarId)) {
      hiddenCalendars.value.delete(calendarId)
    } else {
      hiddenCalendars.value.add(calendarId)
    }
    // Persist to localStorage
    localStorage.setItem('calendar_hidden', JSON.stringify([...hiddenCalendars.value]))
  }
  
  // Check if calendar is visible
  function isCalendarVisible(calendarId) {
    return !hiddenCalendars.value.has(calendarId)
  }
  
  // Get color hex for a calendar
  function getCalendarColor(calendar) {
    if (calendar?.color) {
      // If it's a hex color, return it directly
      if (calendar.color.startsWith('#')) {
        return calendar.color
      }
      // If it's a color ID, find the hex
      const colorObj = calendarColors.find(c => c.id === calendar.color)
      if (colorObj) return colorObj.hex
    }
    return '#3b82f6' // Default blue
  }
  
  // Get the Work calendar (auto-created for tasks)
  const workCalendar = computed(() => {
    return calendars.value.find(c => c.name === 'Work') || calendars.value[0] || null
  })

  // Cache for calendars
  const calendarsFetchedTime = ref(null)
  const calendarsFetched = ref(false)

  async function fetchCalendars(options = {}) {
    const { calendarEnabled } = useAddons()
    if (!calendarEnabled.value) return

    const { force = false } = options
    
    if (!force && calendarsFetched.value && calendarsFetchedTime.value && 
        Date.now() - calendarsFetchedTime.value < CACHE_DURATION) {
      isDebugEnabled() && console.log("[Calendar] Cache hit - using cached calendars data")
      return
    }
    
    const requestId = ++latestCalendarsRequestId

    try {
      const result = await withOfflineFallback(
        async () => {
          const response = await api.get('/calendars')
          if (response.data.success) {
            return {
              calendars: response.data.data.calendars,
              sharedCalendars: response.data.data.shared_calendars || [],
            }
          }
          return null
        },
        async () => {
          const offlineCalendars = await getOfflineCalendars()
          if (offlineCalendars) return { calendars: offlineCalendars, sharedCalendars: [] }
          return null
        }
      )
      if (result && requestId === latestCalendarsRequestId) {
        calendars.value = result.calendars
        sharedCalendars.value = result.sharedCalendars
        calendarsFetchedTime.value = Date.now()
        calendarsFetched.value = true
      }
    } catch (e) {
      console.error('Failed to fetch calendars:', e)
    }
  }

  // Check if events cache is valid
  function isCacheValid() {
    if (!eventsFetched.value) return false
    if (!eventsFetchedTime.value) return false
    return Date.now() - eventsFetchedTime.value < CACHE_DURATION
  }

  // Options: { force: boolean, quiet: boolean }
  async function fetchEvents(startDate = null, endDate = null, options = {}) {
    const { calendarEnabled } = useAddons()
    if (!calendarEnabled.value) return

    const { force = false, quiet = false } = options
    
    const requestId = ++latestEventsRequestId

    // Detect if this is a custom/narrow range query vs full calendar load
    const isCustomRange = !!startDate
    
    // Cache check only applies to full-range fetches
    // Custom-range queries always execute but NEVER touch the main cache
    if (!force && !isCustomRange && isCacheValid()) {
      isDebugEnabled() && console.log("[Calendar] Cache hit - using cached events data")
      return
    }
    
    // Only show loading on first full-range load (no data at all)
    const hasData = eventsFetched.value && events.value.length > 0
    if (!isCustomRange && !hasData && !quiet) {
      loading.value = true
    }
    
    try {
      if (!isCustomRange) {
        const year = currentDate.value.getFullYear()
        const month = currentDate.value.getMonth()
        startDate = new Date(year, month - 1, 1).toISOString()
        endDate = new Date(year + 1, month + 1, 0).toISOString()
      }
      
      isDebugEnabled() && console.log("[Calendar] fetchEvents -", isCustomRange ? "custom range query" : "full range fetch", "hasData:", hasData)

      const fetchedEvents = await withOfflineFallback(
        async () => {
          const response = await api.get('/events', {
            params: { start: startDate, end: endDate }
          })
          if (response.data.success) return response.data.data.events
          return null
        },
        async () => {
          return await getOfflineEvents()
        }
      )

      if (fetchedEvents) {
        if (isCustomRange) {
          return fetchedEvents
        }
        if (requestId !== latestEventsRequestId) {
          return
        }
        events.value = fetchedEvents
        eventsFetchedTime.value = Date.now()
        eventsFetched.value = true
      }
    } catch (e) {
      console.error('Failed to fetch events:', e)
    } finally {
      if (!isCustomRange && requestId === latestEventsRequestId) {
        loading.value = false
      }
    }
  }

  /**
   * Lightweight fetch of today's events for the reminder system.
   * Stores in a separate ref - does NOT touch the main events cache.
   * Used by MailboxView to power reminders without polluting CalendarView data.
   */
  const REMINDER_FETCH_COOLDOWN = 60000 // 1 minute minimum between reminder fetches
  let lastReminderFetchAt = 0

  async function fetchTodayEventsForReminders() {
    const { calendarEnabled } = useAddons()
    if (!calendarEnabled.value) return

    const now = Date.now()
    if (now - lastReminderFetchAt < REMINDER_FETCH_COOLDOWN) return
    lastReminderFetchAt = now

    const today = new Date()
    today.setHours(0, 0, 0, 0)
    const tomorrow = new Date(today)
    tomorrow.setDate(tomorrow.getDate() + 1)
    
    try {
      const response = await api.get('/events', {
        params: { start: today.toISOString(), end: tomorrow.toISOString() }
      })
      if (response.data.success) {
        _reminderEvents.value = response.data.data.events
      }
    } catch (e) {
      console.error('Failed to fetch reminder events:', e)
    }
  }

  /**
   * Invalidate only the events cache (not calendars).
   * Forces the next fetchEvents() call to make a fresh API request.
   * Used by WS handlers when calendar changes happen while not on CalendarView.
   */
  function invalidateEventsCache() {
    eventsFetchedTime.value = null
    eventsFetched.value = false
  }

  function invalidateCalendarsCache() {
    calendarsFetchedTime.value = null
    calendarsFetched.value = false
  }

  /**
   * Reset all cache state. Used during account switching to ensure
   * fresh data is loaded for the new account.
   */
  function resetCache() {
    events.value = []
    _reminderEvents.value = []
    eventsFetchedTime.value = null
    eventsFetched.value = false
    calendarsFetchedTime.value = null
    calendarsFetched.value = false
    latestEventsRequestId++
    latestCalendarsRequestId++
  }

  async function createCalendar(name, color = '#3b82f6') {
    try {
      const response = await api.post('/calendars', { name, color })
      if (response.data.success) {
        calendars.value.push(response.data.data.calendar)
        return { success: true, calendar: response.data.data.calendar }
      }
      return { success: false, error: response.data.message }
    } catch (e) {
      return { success: false, error: e.response?.data?.message || 'Failed to create calendar' }
    }
  }

  async function updateCalendar(id, data) {
    try {
      const response = await api.put(`/calendars/${id}`, data)
      if (response.data.success) {
        const index = calendars.value.findIndex(c => c.id === id)
        if (index !== -1) {
          calendars.value[index] = response.data.data.calendar
        }
        return { success: true, calendar: response.data.data.calendar }
      }
      return { success: false, error: response.data.message }
    } catch (e) {
      return { success: false, error: e.response?.data?.message || 'Failed to update calendar' }
    }
  }

  async function deleteCalendar(id) {
    try {
      const response = await api.delete(`/calendars/${id}`)
      if (response.data.success) {
        calendars.value = calendars.value.filter(c => c.id !== id)
        return { success: true }
      }
      return { success: false, error: response.data.message }
    } catch (e) {
      return { success: false, error: e.response?.data?.message || 'Failed to delete calendar' }
    }
  }

  // ===== CALENDAR SHARING =====
  
  async function shareCalendar(calendarId, { targetEmail, groupId, permission = 'view', canSeeDetails = true }) {
    try {
      const body = { permission, can_see_details: canSeeDetails }
      if (targetEmail) body.target_email = targetEmail
      if (groupId) body.group_id = groupId
      
      const response = await api.post(`/calendars/${calendarId}/share`, body)
      if (response.data.success) {
        return { success: true }
      }
      return { success: false, error: response.data.message }
    } catch (e) {
      return { success: false, error: e.response?.data?.message || 'Failed to share calendar' }
    }
  }
  
  async function unshareCalendar(calendarId, { targetEmail, groupId }) {
    try {
      const body = {}
      if (targetEmail) body.target_email = targetEmail
      if (groupId) body.group_id = groupId
      
      const response = await api.delete(`/calendars/${calendarId}/share`, { data: body })
      if (response.data.success) {
        return { success: true }
      }
      return { success: false, error: response.data.message }
    } catch (e) {
      return { success: false, error: e.response?.data?.message || 'Failed to remove share' }
    }
  }
  
  async function getCalendarShares(calendarId) {
    try {
      const response = await api.get(`/calendars/${calendarId}/shares`)
      if (response.data.success) {
        return response.data.data.shares || []
      }
      return []
    } catch (e) {
      console.error('Failed to get calendar shares:', e)
      return []
    }
  }
  
  // ===== EVENT OPERATIONS =====
  
  async function createEvent(data) {
    try {
      const response = await api.post('/events', data)
      if (response.data.success) {
        const newEvent = response.data.data.event
        events.value.push(newEvent)
        trackEvent('calendar_event_created', { title: data.title })
        return { success: true, event: newEvent }
      }
      return { success: false, error: response.data.message }
    } catch (e) {
      return { success: false, error: e.response?.data?.message || 'Failed to create event' }
    }
  }

  async function quickAddEvent(text, calendarId = null) {
    try {
      const response = await api.post('/events/quick', { 
        text, 
        calendar_id: calendarId || defaultCalendar.value?.id 
      })
      if (response.data.success) {
        const newEvent = response.data.data.event
        events.value.push(newEvent)
        return { success: true, event: newEvent }
      }
      return { success: false, error: response.data.message }
    } catch (e) {
      return { success: false, error: e.response?.data?.message || 'Failed to create event' }
    }
  }

  async function updateEvent(id, data) {
    try {
      const response = await api.put(`/events/${id}`, data)
      if (response.data.success) {
        const updated = response.data.data.event
        const idx = events.value.findIndex(e => e.id === id)
        if (idx !== -1) events.value[idx] = updated
        return { success: true, event: updated }
      }
      return { success: false, error: response.data.message }
    } catch (e) {
      return { success: false, error: e.response?.data?.message || 'Failed to update event' }
    }
  }

  async function deleteEvent(id) {
    try {
      const response = await api.delete(`/events/${id}`)
      if (response.data.success) {
        events.value = events.value.filter(e =>
          e.id !== id && e.recurrence_parent_id !== id
        )
        return { success: true }
      }
      return { success: false, error: response.data.message }
    } catch (e) {
      return { success: false, error: e.response?.data?.message || 'Failed to delete event' }
    }
  }

  async function deleteAllEvents() {
    try {
      const response = await api.delete('/events/all')
      if (response.data.success) {
        events.value = []
        return { success: true, count: response.data.data?.count || 0 }
      }
      return { success: false, error: response.data.message }
    } catch (e) {
      console.error('Failed to delete all events:', e)
      return { success: false, error: e.response?.data?.message || 'Failed to delete all events' }
    }
  }

  function navigatePrevious() {
    const newDate = new Date(currentDate.value)
    if (viewMode.value === 'month') {
      newDate.setMonth(newDate.getMonth() - 1)
    } else if (viewMode.value === 'week') {
      newDate.setDate(newDate.getDate() - 7)
    } else {
      newDate.setDate(newDate.getDate() - 1)
    }
    currentDate.value = newDate
    fetchEvents()
  }

  function navigateNext() {
    const newDate = new Date(currentDate.value)
    if (viewMode.value === 'month') {
      newDate.setMonth(newDate.getMonth() + 1)
    } else if (viewMode.value === 'week') {
      newDate.setDate(newDate.getDate() + 7)
    } else {
      newDate.setDate(newDate.getDate() + 1)
    }
    currentDate.value = newDate
    fetchEvents()
  }

  function goToToday() {
    currentDate.value = new Date()
    fetchEvents()
  }

  function setViewMode(mode) {
    viewMode.value = mode
  }

  // Is the current user the host/organizer of this event?
  // Host = event lives on one of the user's own calendars (i.e. not an
  // invited participant event and not a calendar shared by someone else).
  function isHostOfEvent(event) {
    if (!event) return false
    if (event.is_participant_event) return false
    if (event.shared_by) return false
    // When the owned-calendar list is available, require the event to live on
    // one of the user's own calendars (guards against shared-calendar events).
    if (event.calendar_id && calendars.value.length) {
      return calendars.value.some(c => c.id === event.calendar_id)
    }
    return true
  }

  // Is this event an online (video) meeting? Robust against the backend
  // returning is_meeting as a boolean, an int, or a numeric string.
  function isMeetingEvent(event) {
    if (!event) return false
    if (Number(event.is_meeting)) return true
    return !!(event.meeting_token || event.meeting_room_name || event.meeting_link || event.meeting_conversation_id)
  }

  // Build a lightweight reminder entry from an event.
  function buildReminderEntry(event) {
    return {
      key: `${event.id}-${event.start_time}`,
      eventId: getParentEventId(event),
      title: event.title || '',
      startTime: event.start_time,
      endTime: event.end_time || event.start_time,
      is_meeting: isMeetingEvent(event),
      isHost: isHostOfEvent(event),
      color: event.color || event.calendar_color || null,
    }
  }

  // Reminder "minutes before" thresholds for a single event: the user's
  // configured global defaults merged with the event's own custom reminders
  // (deduped). Entries may be plain numbers or { minutes } objects.
  function reminderThresholdsFor(event, configuredTimes) {
    const customMinutes = Array.isArray(event.reminders)
      ? event.reminders.map(r => Number(r?.minutes ?? r)).filter(n => !Number.isNaN(n))
      : []
    return [...new Set([...configuredTimes, ...customMinutes])]
  }

  // Check for upcoming events and show notifications + in-app reminder popups
  function checkUpcomingEvents() {
    const currentTime = new Date()
    const calendarRemindersOn = localStorage.getItem('notification_calendar') !== 'false'
    let configuredTimes = [15, 5, 0]
    try {
      const parsed = JSON.parse(localStorage.getItem('notification_reminder_times') || '[15, 5, 0]')
      if (Array.isArray(parsed) && parsed.length) configuredTimes = parsed.map(Number).filter(n => !Number.isNaN(n))
    } catch { /* keep defaults */ }
    
    // Use filtered (visibility-respecting) events if loaded, otherwise fall back to today-only reminder events
    const allEvents = eventsFetched.value ? filteredEvents.value : _reminderEvents.value
    
    // Pre-filter to today-only to avoid looping through months of events every 30s
    const dayStart = new Date(currentTime)
    dayStart.setHours(0, 0, 0, 0)
    const dayEnd = new Date(currentTime)
    dayEnd.setHours(23, 59, 59, 999)
    
    const todayOnly = allEvents.filter(event => {
      if (!event.start_time) return false
      const s = new Date(event.start_time)
      return s >= dayStart && s <= dayEnd
    })
    
    todayOnly.forEach(event => {
      const eventStart = new Date(event.start_time)
      const eventEnd = event.end_time ? new Date(event.end_time) : eventStart
      const diffMinutes = Math.round((eventStart - currentTime) / 60000)

      // Reminder thresholds for THIS event: configured defaults merged with any
      // custom per-event reminder times. Single source of truth so the desktop
      // OS toast and the in-app popup always fire at the same minutes — the OS
      // path used to be hardcoded to 15/5/0 and ignored custom reminder times.
      const thresholds = reminderThresholdsFor(event, configuredTimes)

      // ----- OS / desktop notifications (fire once per threshold) -----
      thresholds.forEach(minutes => {
        const notificationKey = `${event.id}-${event.start_time}-${minutes}`

        if (diffMinutes >= minutes - 1 && diffMinutes <= minutes + 1 && !notifiedEvents.value.has(notificationKey)) {
          notifiedEvents.value.add(notificationKey)

          if (minutes <= 0) {
            browserNotifications.showEventStarting(event)
          } else {
            browserNotifications.showEventReminder(event, minutes)
          }
        }
      })

      // ----- In-app reminder popup -----
      if (!calendarRemindersOn) return
      const key = `${event.id}-${event.start_time}`
      const leadMinutes = thresholds.length ? Math.max(...thresholds, 0) : 15

      const snoozedUntil = snoozedReminders.value.get(key)
      if (snoozedUntil && currentTime.getTime() < snoozedUntil) return

      const notEnded = currentTime < eventEnd
      const withinLead = diffMinutes <= leadMinutes
      if (notEnded && withinLead && !dismissedReminders.value.has(key)) {
        if (!activeReminders.value.some(r => r.key === key)) {
          activeReminders.value.push(buildReminderEntry(event))
        }
      }
    })

    // Drop reminders for events that have ended.
    if (activeReminders.value.length) {
      activeReminders.value = activeReminders.value.filter(r => {
        const end = r.endTime ? new Date(r.endTime) : new Date(r.startTime)
        return currentTime < end
      })
    }
    
    // Clean up old notified keys (events that have passed). Dismissed/snoozed
    // entries are pruned by TTL on load, so we leave them in place here.
    const oneHourAgo = new Date(currentTime.getTime() - 3600000)
    todayOnly.forEach(event => {
      const eventStart = new Date(event.start_time)
      if (eventStart < oneHourAgo) {
        reminderThresholdsFor(event, configuredTimes).forEach(minutes => {
          notifiedEvents.value.delete(`${event.id}-${event.start_time}-${minutes}`)
        })
      }
    })
  }

  // Dismiss a single in-app reminder (persisted so it won't re-surface on refresh).
  function dismissReminder(key) {
    dismissedReminders.value.set(key, Date.now())
    activeReminders.value = activeReminders.value.filter(r => r.key !== key)
    _persistReminderState()
  }

  // Dismiss all currently shown reminders.
  function dismissAllReminders() {
    const nowMs = Date.now()
    activeReminders.value.forEach(r => dismissedReminders.value.set(r.key, nowMs))
    activeReminders.value = []
    _persistReminderState()
  }

  // Snooze a reminder for N minutes (hides it, then it can re-surface).
  function snoozeReminder(key, minutes = 5) {
    snoozedReminders.value.set(key, Date.now() + minutes * 60000)
    activeReminders.value = activeReminders.value.filter(r => r.key !== key)
    _persistReminderState()
  }

  // Start checking for upcoming events
  function startEventReminders(intervalMs = 30000) {
    stopEventReminders()
    // Check immediately
    checkUpcomingEvents()
    // Then check every 30 seconds
    reminderInterval.value = setInterval(checkUpcomingEvents, intervalMs)
  }

  // Stop checking for upcoming events
  function stopEventReminders() {
    if (reminderInterval.value) {
      clearInterval(reminderInterval.value)
      reminderInterval.value = null
    }
  }

  // Get upcoming events for today (reactive via `now` ref)
  // Uses full events if loaded, otherwise falls back to today-only reminder events
  const todayEvents = computed(() => {
    const _ = now.value // depend on reactive now for re-evaluation
    const today = new Date()
    today.setHours(0, 0, 0, 0)
    const tomorrow = new Date(today)
    tomorrow.setDate(tomorrow.getDate() + 1)
    
    // Use full events if calendar has been loaded, otherwise use reminder events
    const sourceEvents = eventsFetched.value ? filteredEvents.value : _reminderEvents.value
    
    return sourceEvents
      .filter(event => {
        if (!event.start_time) return false
        const start = new Date(event.start_time)
        const end = event.end_time ? new Date(event.end_time) : start
        // Respect calendar visibility
        if (event.calendar_id && hiddenCalendars.value.has(event.calendar_id)) return false
        // Event overlaps with today (handles multi-day events too)
        return start < tomorrow && end >= today
      })
      .sort((a, b) => new Date(a.start_time) - new Date(b.start_time))
  })
  
  // Get the next upcoming event (hasn't ended yet)
  const nextUpcomingEvent = computed(() => {
    const currentTime = now.value
    return todayEvents.value.find(event => {
      if (!event.end_time) return true
      return new Date(event.end_time) > currentTime
    }) || null
  })
  
  // Get countdown text for an event (e.g. "in 13 min", "in 2 hrs", "Now", "Ended")
  function getEventCountdown(event) {
    if (!event.start_time) return { text: '', status: 'unknown', minutes: -1 }
    const currentTime = now.value
    const start = new Date(event.start_time)
    const end = event.end_time ? new Date(event.end_time) : start
    
    if (currentTime >= end) {
      return { text: 'Ended', status: 'ended', minutes: -1 }
    }
    
    if (currentTime >= start) {
      const remainingMin = Math.ceil((end - currentTime) / 60000)
      if (remainingMin <= 60) {
        return { text: `${remainingMin} min left`, status: 'ongoing', minutes: 0 }
      }
      const hrs = Math.floor(remainingMin / 60)
      const mins = remainingMin % 60
      return { text: mins > 0 ? `${hrs} hr ${mins} min left` : `${hrs} hr left`, status: 'ongoing', minutes: 0 }
    }
    
    const diffMs = start - currentTime
    const diffMin = Math.ceil(diffMs / 60000)
    
    if (diffMin <= 0) {
      return { text: 'Now', status: 'now', minutes: 0 }
    }
    if (diffMin === 1) {
      return { text: 'in 1 min', status: 'imminent', minutes: 1 }
    }
    if (diffMin < 60) {
      return { text: `in ${diffMin} min`, status: 'upcoming', minutes: diffMin }
    }
    const hrs = Math.floor(diffMin / 60)
    const mins = diffMin % 60
    if (mins === 0) {
      return { text: `in ${hrs} hr`, status: 'upcoming', minutes: diffMin }
    }
    return { text: `in ${hrs} hr ${mins} min`, status: 'upcoming', minutes: diffMin }
  }
  
  // Start the now ticker (call on app init or calendar mount)
  function startNowTicker() {
    stopNowTicker()
    now.value = new Date()
    nowTickerRef.value = setInterval(() => {
      now.value = new Date()
    }, 60000) // Update every 60 seconds
  }
  
  // Stop the now ticker
  function stopNowTicker() {
    if (nowTickerRef.value) {
      clearInterval(nowTickerRef.value)
      nowTickerRef.value = null
    }
  }

  // ===== GOOGLE CALENDAR SYNC =====
  
  // Fetch Google calendars for an OAuth account
  async function fetchGoogleCalendars(oauthAccountId) {
    try {
      const response = await api.get('/calendar/google/calendars', {
        params: { account_id: oauthAccountId }
      })
      if (response.data.success) {
        googleCalendars.value = response.data.data.calendars
        return response.data.data.calendars
      }
    } catch (e) {
      console.error('Failed to fetch Google calendars:', e)
    }
    return []
  }
  
  // Get sync configurations for an account
  async function fetchSyncConfigs(oauthAccountId) {
    try {
      const response = await api.get('/calendar/google/sync', {
        params: { account_id: oauthAccountId }
      })
      if (response.data.success) {
        syncConfigs.value = response.data.data.configs
        return response.data.data.configs
      }
    } catch (e) {
      console.error('Failed to fetch sync configs:', e)
    }
    return []
  }
  
  // Setup sync between local and Google calendar
  async function setupGoogleSync(oauthAccountId, googleCalendarId, localCalendarId) {
    try {
      const response = await api.post('/calendar/google/sync', {
        account_id: oauthAccountId,
        google_calendar_id: googleCalendarId,
        local_calendar_id: localCalendarId,
      })
      if (response.data.success) {
        // Refresh sync configs
        await fetchSyncConfigs(oauthAccountId)
        return { success: true, sync: response.data.data.sync }
      }
      return { success: false, error: response.data.message }
    } catch (e) {
      return { success: false, error: e.response?.data?.message || 'Failed to setup sync' }
    }
  }
  
  // Sync events from Google Calendar
  // manageSyncFlag: false when called from syncAllLinkedCalendars (parent manages the flag)
  async function syncFromGoogle(oauthAccountId, googleCalendarId, { manageSyncFlag = true } = {}) {
    if (manageSyncFlag) syncing.value = true
    try {
      const response = await api.post('/calendar/google/sync/pull', {
        account_id: oauthAccountId,
        google_calendar_id: googleCalendarId,
      })
      if (response.data.success) {
        await fetchEvents()
        return { 
          success: true, 
          imported: response.data.data.imported,
          updated: response.data.data.updated,
          errors: response.data.data.errors,
        }
      }
      return { success: false, error: response.data.message }
    } catch (e) {
      return { success: false, error: e.response?.data?.message || 'Failed to sync from Google' }
    } finally {
      if (manageSyncFlag) syncing.value = false
    }
  }
  
  // Sync a single event to Google Calendar
  async function syncEventToGoogle(oauthAccountId, eventId) {
    try {
      const response = await api.post('/calendar/google/sync/push', {
        account_id: oauthAccountId,
        event_id: eventId,
      })
      if (response.data.success) {
        return { success: true, googleEventId: response.data.data.google_event_id }
      }
      return { success: false, error: response.data.message }
    } catch (e) {
      return { success: false, error: e.response?.data?.message || 'Failed to sync to Google' }
    }
  }
  
  // Disable sync for a calendar
  async function disableGoogleSync(oauthAccountId, googleCalendarId) {
    try {
      const response = await api.delete('/calendar/google/sync', {
        data: {
          account_id: oauthAccountId,
          google_calendar_id: googleCalendarId,
        }
      })
      if (response.data.success) {
        // Refresh sync configs
        await fetchSyncConfigs(oauthAccountId)
        return { success: true }
      }
      return { success: false, error: response.data.message }
    } catch (e) {
      return { success: false, error: e.response?.data?.message || 'Failed to disable sync' }
    }
  }
  
  // Check if a Google calendar is synced
  function isGoogleCalendarSynced(googleCalendarId) {
    return syncConfigs.value.some(c => c.google_calendar_id === googleCalendarId && c.sync_enabled)
  }

  // ===== MICROSOFT CALENDAR SYNC =====
  
  const microsoftCalendars = ref([])
  const microsoftSyncConfigs = ref([])
  
  // Fetch Microsoft calendars for an OAuth account
  async function fetchMicrosoftCalendars(oauthAccountId) {
    try {
      const response = await api.get('/calendar/microsoft/calendars', {
        params: { account_id: oauthAccountId }
      })
      if (response.data.success) {
        microsoftCalendars.value = response.data.data.calendars
        return response.data.data.calendars
      }
    } catch (e) {
      console.error('Failed to fetch Microsoft calendars:', e)
    }
    return []
  }
  
  // Get Microsoft sync configurations
  async function fetchMicrosoftSyncConfigs(oauthAccountId) {
    try {
      const response = await api.get('/calendar/microsoft/sync', {
        params: { account_id: oauthAccountId }
      })
      if (response.data.success) {
        microsoftSyncConfigs.value = response.data.data.configs
        return response.data.data.configs
      }
    } catch (e) {
      console.error('Failed to fetch Microsoft sync configs:', e)
    }
    return []
  }
  
  // Setup sync between local and Microsoft calendar
  async function setupMicrosoftSync(oauthAccountId, msCalendarId, localCalendarId) {
    try {
      const response = await api.post('/calendar/microsoft/sync', {
        account_id: oauthAccountId,
        ms_calendar_id: msCalendarId,
        local_calendar_id: localCalendarId,
      })
      if (response.data.success) {
        await fetchMicrosoftSyncConfigs(oauthAccountId)
        return { success: true, sync: response.data.data.sync }
      }
      return { success: false, error: response.data.message }
    } catch (e) {
      return { success: false, error: e.response?.data?.message || 'Failed to setup sync' }
    }
  }
  
  // Sync events from Microsoft Calendar
  // manageSyncFlag: false when called from syncAllLinkedCalendars (parent manages the flag)
  async function syncFromMicrosoft(oauthAccountId, msCalendarId, { manageSyncFlag = true } = {}) {
    if (manageSyncFlag) syncing.value = true
    try {
      const response = await api.post('/calendar/microsoft/sync/pull', {
        account_id: oauthAccountId,
        ms_calendar_id: msCalendarId,
      })
      if (response.data.success) {
        await fetchEvents()
        return { 
          success: true, 
          imported: response.data.data.imported,
          updated: response.data.data.updated,
          errors: response.data.data.errors,
        }
      }
      return { success: false, error: response.data.message }
    } catch (e) {
      return { success: false, error: e.response?.data?.message || 'Failed to sync from Microsoft' }
    } finally {
      if (manageSyncFlag) syncing.value = false
    }
  }
  
  // Check if a Microsoft calendar is synced
  function isMicrosoftCalendarSynced(msCalendarId) {
    return microsoftSyncConfigs.value.some(c => c.ms_calendar_id === msCalendarId && c.sync_enabled)
  }

  // ===== BATCH SYNC ACTIONS =====
  // One HTTP per bulk action instead of N. Settings-page "enable sync
  // for 8 calendars" used to fire 16 sequential requests; now it's 2.

  /**
   * Enable Google sync for many calendars mapped to one local calendar.
   * Single trailing fetchSyncConfigs (vs one-per-iteration in the old loop).
   */
  async function bulkSetupGoogleSync(oauthAccountId, googleCalendarIds, localCalendarId) {
    try {
      const response = await api.post('/calendar/google/sync-batch', {
        account_id: oauthAccountId,
        google_calendar_ids: googleCalendarIds,
        local_calendar_id: localCalendarId,
      })
      if (response.data.success) {
        await fetchSyncConfigs(oauthAccountId)
        const d = response.data.data || {}
        return {
          success: true,
          configured: d.success || 0,
          failed: d.failed || 0,
          alreadySynced: d.already_synced || 0,
          errors: d.errors || [],
        }
      }
      return { success: false, error: response.data.message }
    } catch (e) {
      return { success: false, error: e.response?.data?.message || 'Failed to setup sync (batch)' }
    }
  }

  /**
   * Pull events from many Google calendars in one request.
   * Single trailing fetchEvents.
   */
  async function bulkSyncFromGoogle(oauthAccountId, googleCalendarIds, { manageSyncFlag = true } = {}) {
    if (manageSyncFlag) syncing.value = true
    try {
      const response = await api.post('/calendar/google/sync-pull-batch', {
        account_id: oauthAccountId,
        google_calendar_ids: googleCalendarIds,
      })
      if (response.data.success) {
        await fetchEvents()
        const d = response.data.data || {}
        return {
          success: true,
          imported: d.imported || 0,
          updated: d.updated || 0,
          errors: d.errors || [],
          perCalendar: d.per_calendar || {},
        }
      }
      return { success: false, error: response.data.message }
    } catch (e) {
      return { success: false, error: e.response?.data?.message || 'Failed to pull from Google (batch)' }
    } finally {
      if (manageSyncFlag) syncing.value = false
    }
  }

  /**
   * Enable Microsoft sync for many calendars mapped to one local calendar.
   */
  async function bulkSetupMicrosoftSync(oauthAccountId, msCalendarIds, localCalendarId) {
    try {
      const response = await api.post('/calendar/microsoft/sync-batch', {
        account_id: oauthAccountId,
        ms_calendar_ids: msCalendarIds,
        local_calendar_id: localCalendarId,
      })
      if (response.data.success) {
        await fetchMicrosoftSyncConfigs(oauthAccountId)
        const d = response.data.data || {}
        return {
          success: true,
          configured: d.success || 0,
          failed: d.failed || 0,
          errors: d.errors || [],
        }
      }
      return { success: false, error: response.data.message }
    } catch (e) {
      return { success: false, error: e.response?.data?.message || 'Failed to setup Microsoft sync (batch)' }
    }
  }

  /**
   * Pull events from many Microsoft calendars in one request.
   */
  async function bulkSyncFromMicrosoft(oauthAccountId, msCalendarIds, { manageSyncFlag = true } = {}) {
    if (manageSyncFlag) syncing.value = true
    try {
      const response = await api.post('/calendar/microsoft/sync-pull-batch', {
        account_id: oauthAccountId,
        ms_calendar_ids: msCalendarIds,
      })
      if (response.data.success) {
        await fetchEvents()
        const d = response.data.data || {}
        return {
          success: true,
          imported: d.imported || 0,
          updated: d.updated || 0,
          errors: d.errors || [],
          perCalendar: d.per_calendar || {},
        }
      }
      return { success: false, error: response.data.message }
    } catch (e) {
      return { success: false, error: e.response?.data?.message || 'Failed to pull from Microsoft (batch)' }
    } finally {
      if (manageSyncFlag) syncing.value = false
    }
  }

  /**
   * Calendar-only connection: batched setup for non-email OAuth connections.
   */
  async function bulkSetupConnectionSync(connectionId, googleCalendarIds, localCalendarId) {
    try {
      const response = await api.post('/calendar/connections/sync-batch', {
        connection_id: connectionId,
        google_calendar_ids: googleCalendarIds,
        local_calendar_id: localCalendarId,
      })
      if (response.data.success) {
        const d = response.data.data || {}
        return {
          success: true,
          configured: d.success || 0,
          failed: d.failed || 0,
          errors: d.errors || [],
        }
      }
      return { success: false, error: response.data.message }
    } catch (e) {
      return { success: false, error: e.response?.data?.message || 'Failed to setup connection sync (batch)' }
    }
  }

  /**
   * Calendar-only connection: batched pull.
   */
  async function bulkSyncFromConnection(connectionId, googleCalendarIds, { manageSyncFlag = true } = {}) {
    if (manageSyncFlag) syncing.value = true
    try {
      const response = await api.post('/calendar/connections/sync-pull-batch', {
        connection_id: connectionId,
        google_calendar_ids: googleCalendarIds,
      })
      if (response.data.success) {
        await fetchEvents()
        const d = response.data.data || {}
        return {
          success: true,
          imported: d.imported || 0,
          updated: d.updated || 0,
          errors: d.errors || [],
          perCalendar: d.per_calendar || {},
        }
      }
      return { success: false, error: response.data.message }
    } catch (e) {
      return { success: false, error: e.response?.data?.message || 'Failed to pull from connection (batch)' }
    } finally {
      if (manageSyncFlag) syncing.value = false
    }
  }

  // ===== AUTO-SYNC FUNCTIONALITY =====
  
  // Set auto-sync enabled
  function setAutoSyncEnabled(enabled) {
    autoSyncEnabled.value = enabled
    localStorage.setItem('calendar_auto_sync_enabled', enabled.toString())
    
    if (enabled) {
      startAutoSync()
    } else {
      stopAutoSync()
    }
  }
  
  // Set auto-sync interval (in minutes)
  function setAutoSyncInterval(minutes) {
    autoSyncInterval.value = minutes
    localStorage.setItem('calendar_auto_sync_interval', minutes.toString())
    
    // Restart auto-sync with new interval if enabled
    if (autoSyncEnabled.value) {
      stopAutoSync()
      startAutoSync()
    }
  }
  
  // Start auto-sync.
  //
  // Phase 4: calendar deltas are pushed by the server-side sync cron
  // (refresh-oauth-tokens.php + calendar-sync cron) and, once the calendar
  // daemon worker ships, by direct push channels. The browser timer here
  // is now a safety net only — we floor the interval at 30 minutes so a
  // user with autoSyncInterval=5 in their settings does not keep
  // hammering syncFromGoogle/syncFromMicrosoft for every linked
  // calendar. Previously this used the raw user-configured value, which
  // produced an N-calendar fan-out every five minutes from each open
  // browser tab.
  function startAutoSync() {
    if (autoSyncIntervalRef.value) {
      clearInterval(autoSyncIntervalRef.value)
    }

    if (!autoSyncEnabled.value) return

    const MIN_INTERVAL_MS = 30 * 60 * 1000
    const requestedMs = (Number(autoSyncInterval.value) || 30) * 60 * 1000
    const intervalMs = Math.max(MIN_INTERVAL_MS, requestedMs)

    const lastSync = lastAutoSync.value ? new Date(lastAutoSync.value) : null
    const now = new Date()
    if (!lastSync || (now - lastSync) > intervalMs) {
      syncAllLinkedCalendars()
    }

    autoSyncIntervalRef.value = setInterval(() => {
      syncAllLinkedCalendars()
    }, intervalMs)

    isDebugEnabled() && console.log(`Auto-sync started (safety net): every ${intervalMs / 60000} minutes`)
  }
  
  // Stop auto-sync
  function stopAutoSync() {
    if (autoSyncIntervalRef.value) {
      clearInterval(autoSyncIntervalRef.value)
      autoSyncIntervalRef.value = null
    }
    isDebugEnabled() && console.log('Auto-sync stopped')
  }
  
  // Sync all linked calendars
  async function syncAllLinkedCalendars() {
    if (syncing.value) return // Already syncing
    
    syncing.value = true
    let totalImported = 0
    let totalUpdated = 0
    let errors = []
    
    try {
      // Sync Google calendars
      for (const config of syncConfigs.value) {
        if (!config.sync_enabled) continue
        try {
          const result = await syncFromGoogle(config.oauth_account_id, config.google_calendar_id, { manageSyncFlag: false })
          if (result.success) {
            totalImported += result.imported || 0
            totalUpdated += result.updated || 0
          } else {
            errors.push(`Google: ${result.error}`)
          }
        } catch (e) {
          errors.push(`Google sync error: ${e.message}`)
        }
      }
      
      // Sync Microsoft calendars
      for (const config of microsoftSyncConfigs.value) {
        if (!config.sync_enabled) continue
        try {
          const result = await syncFromMicrosoft(config.oauth_account_id, config.ms_calendar_id, { manageSyncFlag: false })
          if (result.success) {
            totalImported += result.imported || 0
            totalUpdated += result.updated || 0
          } else {
            errors.push(`Microsoft: ${result.error}`)
          }
        } catch (e) {
          errors.push(`Microsoft sync error: ${e.message}`)
        }
      }

      // Phase 3.4: also sync calendar-only OAuth connections (the ones that
      // are NOT linked to an email account). The server cron covers them too
      // but this in-tab loop keeps the open calendar view in sync without
      // waiting for the next cron pass.
      try {
        const connResp = await api.get('/calendar/connections')
        const connections = connResp.data?.data?.connections || []
        for (const conn of connections) {
          const synced = Array.isArray(conn.synced_calendars) ? conn.synced_calendars : []
          const ids = synced
            .filter(c => c.sync_enabled && c.google_calendar_id)
            .map(c => c.google_calendar_id)
          if (!ids.length) continue
          try {
            const result = await bulkSyncFromConnection(conn.id, ids, { manageSyncFlag: false })
            if (result.success) {
              totalImported += result.imported || 0
              totalUpdated += result.updated || 0
              if (Array.isArray(result.errors) && result.errors.length) {
                errors.push(...result.errors.map(e => `Connection ${conn.id}: ${e}`))
              }
            } else {
              errors.push(`Connection ${conn.id}: ${result.error}`)
            }
          } catch (e) {
            errors.push(`Connection ${conn.id} sync error: ${e.message}`)
          }
        }
      } catch (e) {
        // Calendar connections endpoint may 401/404 if user hasn't configured any.
        // We treat this as a soft failure - just record it and keep going.
        errors.push(`Calendar connections sync skipped: ${e.message}`)
      }

      // Update last sync time
      lastAutoSync.value = new Date().toISOString()
      localStorage.setItem('calendar_last_auto_sync', lastAutoSync.value)
      
      isDebugEnabled() && console.log(`Auto-sync complete: ${totalImported} imported, ${totalUpdated} updated`)
      
      // Refresh events
      await fetchEvents()
      
    } catch (e) {
      console.error('Auto-sync error:', e)
    } finally {
      syncing.value = false
    }
    
    return { imported: totalImported, updated: totalUpdated, errors }
  }

  // ===== EVENT INVITATION METHODS =====
  
  async function inviteParticipants(eventId, emails) {
    try {
      const response = await api.post(`/events/${eventId}/invite`, { emails })
      if (response.data.success) {
        return response.data.data
      }
      return { success: [], failed: [] }
    } catch (e) {
      console.error('Failed to invite participants:', e)
      return { error: e.response?.data?.message || 'Failed to send invitations' }
    }
  }

  // Upgrade an existing event to a FlowOne meeting (mints meeting
  // token + guest/admin links + chat conversation). Idempotent — safe
  // to call on an event that already has a meeting. Pass
  // options.force=true to revoke the old links and mint fresh ones.
  async function addMeetingToEvent(eventId, options = {}) {
    try {
      const response = await api.post(`/events/${eventId}/add-meeting`, {
        waiting_room: !!options.waiting_room,
        participants_hidden: !!options.participants_hidden,
        invite_participants: !!options.invite_participants,
        force: !!options.force,
      })
      if (response.data?.success) {
        return { success: true, data: response.data.data }
      }
      return { success: false, error: response.data?.error || 'Failed to add meeting' }
    } catch (e) {
      console.error('Failed to add meeting to event:', e)
      return {
        success: false,
        error: e.response?.data?.error || e.response?.data?.message || 'Failed to add meeting',
      }
    }
  }

  // Read the guest + host(admin) links and waiting-room state for an
  // event that is already a meeting. Read-only / idempotent.
  async function getEventMeeting(eventId) {
    try {
      const response = await api.get(`/events/${eventId}/meeting`)
      if (response.data?.success) {
        return { success: true, data: response.data.data }
      }
      return { success: false, error: response.data?.error || 'Failed to load meeting' }
    } catch (e) {
      console.error('Failed to load event meeting:', e)
      return {
        success: false,
        error: e.response?.data?.error || e.response?.data?.message || 'Failed to load meeting',
      }
    }
  }

  // Turn a meeting back into a plain event: revokes the guest/admin
  // links (kicking anyone currently in the room) and clears is_meeting.
  async function removeMeetingFromEvent(eventId) {
    return updateEvent(eventId, { is_meeting: false })
  }
  
  async function getParticipants(eventId) {
    try {
      const response = await api.get(`/events/${eventId}/participants`)
      if (response.data.success) {
        return response.data.data.participants
      }
      return []
    } catch (e) {
      console.error('Failed to get participants:', e)
      return []
    }
  }
  
  async function removeParticipant(eventId, email) {
    try {
      const response = await api.delete(`/events/${eventId}/participants/${encodeURIComponent(email)}`)
      return response.data.success
    } catch (e) {
      console.error('Failed to remove participant:', e)
      return false
    }
  }
  
  async function getMyInvitations() {
    try {
      const response = await api.get('/events/invitations')
      if (response.data.success) {
        return response.data.data.invitations
      }
      return []
    } catch (e) {
      console.error('Failed to get invitations:', e)
      return []
    }
  }
  
  async function respondToInvitation(token, response, message = null) {
    try {
      const result = await api.post(`/events/invitations/${token}/respond`, { response, message })
      return result.data.success ? { success: true } : { success: false, error: result.data.message }
    } catch (e) {
      console.error('Failed to respond to invitation:', e)
      return { success: false, error: e.response?.data?.message || 'Failed to respond' }
    }
  }

  return {
    calendars,
    sharedCalendars,
    events,
    loading,
    currentDate,
    viewMode,
    defaultCalendar,
    workCalendar,
    viewEvents,
    filteredEvents,
    currentMonth,
    currentYear,
    monthName,
    monthDays,
    todayEvents,
    nextUpcomingEvent,
    now,
    getEventCountdown,
    startNowTicker,
    stopNowTicker,
    // Calendar colors
    calendarColors,
    hiddenCalendars,
    // Google Calendar sync
    googleCalendars,
    syncConfigs,
    syncing,
    getEventsForDay,
    getParentEventId,
    isRecurrenceInstance,
    fetchCalendars,
    fetchEvents,
    fetchTodayEventsForReminders,
    invalidateEventsCache,
    invalidateCalendarsCache,
    resetCache,
    createCalendar,
    updateCalendar,
    deleteCalendar,
    createEvent,
    quickAddEvent,
    updateEvent,
    deleteEvent,
    deleteAllEvents,
    // Calendar sharing
    shareCalendar,
    unshareCalendar,
    getCalendarShares,
    navigatePrevious,
    navigateNext,
    goToToday,
    setViewMode,
    startEventReminders,
    stopEventReminders,
    checkUpcomingEvents,
    // In-app reminder popups
    activeReminders,
    isHostOfEvent,
    dismissReminder,
    dismissAllReminders,
    snoozeReminder,
    // Visibility helpers
    toggleCalendarVisibility,
    isCalendarVisible,
    getCalendarColor,
    // Google Calendar sync methods
    fetchGoogleCalendars,
    fetchSyncConfigs,
    setupGoogleSync,
    syncFromGoogle,
    syncEventToGoogle,
    disableGoogleSync,
    isGoogleCalendarSynced,
    // Microsoft Calendar sync
    microsoftCalendars,
    microsoftSyncConfigs,
    fetchMicrosoftCalendars,
    fetchMicrosoftSyncConfigs,
    setupMicrosoftSync,
    syncFromMicrosoft,
    isMicrosoftCalendarSynced,
    // Bulk sync (single-request alternatives to the per-id loops)
    bulkSetupGoogleSync,
    bulkSyncFromGoogle,
    bulkSetupMicrosoftSync,
    bulkSyncFromMicrosoft,
    bulkSetupConnectionSync,
    bulkSyncFromConnection,
    // Auto-sync
    autoSyncEnabled,
    autoSyncInterval,
    lastAutoSync,
    setAutoSyncEnabled,
    setAutoSyncInterval,
    startAutoSync,
    stopAutoSync,
    syncAllLinkedCalendars,
    // Event invitations
    inviteParticipants,
    addMeetingToEvent,
    getEventMeeting,
    removeMeetingFromEvent,
    getParticipants,
    removeParticipant,
    getMyInvitations,
    respondToInvitation,
    // Pending event (for opening modal from other views)
    pendingEventData,
    setPendingEvent,
    clearPendingEvent,
  }
})

