import { describe, it, expect, beforeEach, vi } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'
import { mockApi, resetAllMocks } from '../../helpers/setup.js'

import { useCalendarStore } from '@/addons/calendar/stores/calendar.js'

describe('calendar store', () => {
  let store

  beforeEach(() => {
    setActivePinia(createPinia())
    resetAllMocks()
    mockApi.get.mockResolvedValue({ data: { success: true, data: { calendars: [], events: [] } } })
    mockApi.post.mockResolvedValue({ data: { success: true } })
    store = useCalendarStore()
  })

  describe('initial state', () => {
    it('should have empty calendars', () => {
      expect(store.calendars).toEqual([])
    })

    it('should have empty events', () => {
      expect(store.events).toEqual([])
    })

    it('should not be loading', () => {
      expect(store.loading).toBe(false)
    })

    it('should default to month view', () => {
      expect(store.viewMode).toBe('month')
    })

    it('should have currentDate set to today', () => {
      const today = new Date()
      const storeDate = new Date(store.currentDate)
      expect(storeDate.getFullYear()).toBe(today.getFullYear())
      expect(storeDate.getMonth()).toBe(today.getMonth())
    })
  })

  describe('fetchCalendars', () => {
    it('should call API and populate calendars', async () => {
      const calendars = [
        { id: 1, name: 'Default', color: '#3b82f6', is_default: true },
        { id: 2, name: 'Work', color: '#ef4444', is_default: false },
      ]

      mockApi.get.mockResolvedValueOnce({
        data: { success: true, data: { calendars } },
      })

      await store.fetchCalendars({ force: true })

      expect(mockApi.get).toHaveBeenCalledWith('/calendars')
      expect(store.calendars).toEqual(calendars)
    })
  })

  describe('fetchEvents', () => {
    it('should call API with date range params', async () => {
      mockApi.get.mockResolvedValueOnce({
        data: {
          success: true,
          data: {
            events: [
              {
                id: 1,
                title: 'Meeting',
                start_date: '2026-04-01 09:00:00',
                end_date: '2026-04-01 10:00:00',
              },
            ],
          },
        },
      })

      await store.fetchEvents('2026-04-01', '2026-04-30', { force: true })

      expect(mockApi.get).toHaveBeenCalledWith(
        '/events',
        expect.objectContaining({
          params: expect.objectContaining({
            start: '2026-04-01',
            end: '2026-04-30',
          }),
        })
      )
    })
  })

  describe('createCalendar', () => {
    it('should POST new calendar', async () => {
      const newCal = { id: 5, name: 'Holidays', color: '#10b981' }

      mockApi.post.mockResolvedValueOnce({
        data: { success: true, data: { calendar: newCal } },
      })

      await store.createCalendar('Holidays', '#10b981')

      expect(mockApi.post).toHaveBeenCalledWith(
        '/calendars',
        expect.objectContaining({
          name: 'Holidays',
          color: '#10b981',
        })
      )
    })
  })

  describe('updateCalendar', () => {
    it('should PUT updated calendar data', async () => {
      mockApi.put.mockResolvedValueOnce({
        data: {
          success: true,
          data: { calendar: { id: 1, name: 'Renamed', color: '#000' } },
        },
      })

      await store.updateCalendar(1, { name: 'Renamed', color: '#000' })

      expect(mockApi.put).toHaveBeenCalledWith(
        '/calendars/1',
        expect.objectContaining({ name: 'Renamed', color: '#000' })
      )
    })
  })

  describe('deleteCalendar', () => {
    it('should DELETE calendar', async () => {
      store.calendars = [
        { id: 1, name: 'Default' },
        { id: 2, name: 'ToDelete' },
      ]

      mockApi.delete.mockResolvedValueOnce({ data: { success: true } })

      await store.deleteCalendar(2)

      expect(mockApi.delete).toHaveBeenCalledWith('/calendars/2')
    })
  })

  describe('createEvent', () => {
    it('should POST new event', async () => {
      const newEvent = {
        id: 10,
        title: 'Lunch',
        start_date: '2026-04-15 12:00:00',
        end_date: '2026-04-15 13:00:00',
        calendar_id: 1,
      }

      mockApi.post.mockResolvedValueOnce({
        data: { success: true, data: { event: newEvent } },
      })

      await store.createEvent({
        title: 'Lunch',
        start_date: '2026-04-15 12:00:00',
        end_date: '2026-04-15 13:00:00',
        calendar_id: 1,
      })

      expect(mockApi.post).toHaveBeenCalledWith(
        '/events',
        expect.objectContaining({ title: 'Lunch' })
      )
    })
  })

  describe('updateEvent', () => {
    it('should PUT event changes', async () => {
      mockApi.put.mockResolvedValueOnce({
        data: {
          success: true,
          data: { event: { id: 10, title: 'Updated Lunch' } },
        },
      })

      await store.updateEvent(10, { title: 'Updated Lunch' })

      expect(mockApi.put).toHaveBeenCalledWith(
        '/events/10',
        expect.objectContaining({ title: 'Updated Lunch' })
      )
    })
  })

  describe('deleteEvent', () => {
    it('should DELETE event', async () => {
      store.events = [
        { id: 10, title: 'Keep' },
        { id: 20, title: 'Remove' },
      ]

      mockApi.delete.mockResolvedValueOnce({ data: { success: true } })

      await store.deleteEvent(20)

      expect(mockApi.delete).toHaveBeenCalledWith('/events/20')
    })
  })

  describe('deleteAllEvents', () => {
    it('should DELETE all events', async () => {
      store.events = [{ id: 1 }, { id: 2 }]

      mockApi.delete.mockResolvedValueOnce({
        data: { success: true, data: { deleted: 2 } },
      })

      await store.deleteAllEvents()

      expect(mockApi.delete).toHaveBeenCalledWith('/events/all')
    })
  })

  describe('navigation', () => {
    it('should navigate to next month', () => {
      const originalMonth = new Date(store.currentDate).getMonth()
      store.setViewMode('month')
      store.navigateNext()

      const newMonth = new Date(store.currentDate).getMonth()
      expect(newMonth).not.toBe(originalMonth)
    })

    it('should navigate to previous month', () => {
      const originalMonth = new Date(store.currentDate).getMonth()
      store.setViewMode('month')
      store.navigatePrevious()

      const newMonth = new Date(store.currentDate).getMonth()
      expect(newMonth).not.toBe(originalMonth)
    })

    it('should go to today', () => {
      store.currentDate = new Date('2020-01-01')
      store.goToToday()

      const today = new Date()
      const storeDate = new Date(store.currentDate)
      expect(storeDate.getFullYear()).toBe(today.getFullYear())
      expect(storeDate.getMonth()).toBe(today.getMonth())
      expect(storeDate.getDate()).toBe(today.getDate())
    })
  })

  describe('view mode', () => {
    it('should switch between day/week/month views', () => {
      store.setViewMode('day')
      expect(store.viewMode).toBe('day')

      store.setViewMode('week')
      expect(store.viewMode).toBe('week')

      store.setViewMode('month')
      expect(store.viewMode).toBe('month')
    })
  })

  describe('calendar visibility', () => {
    it('should toggle calendar visibility', () => {
      expect(store.isCalendarVisible(1)).toBe(true)

      store.toggleCalendarVisibility(1)
      expect(store.isCalendarVisible(1)).toBe(false)

      store.toggleCalendarVisibility(1)
      expect(store.isCalendarVisible(1)).toBe(true)
    })
  })

  describe('defaultCalendar computed', () => {
    it('should return the default calendar', () => {
      store.calendars = [
        { id: 1, name: 'Work', is_default: false },
        { id: 2, name: 'Default', is_default: true },
      ]

      expect(store.defaultCalendar?.id).toBe(2)
    })

    it('should return first calendar when no default exists', () => {
      store.calendars = [
        { id: 1, name: 'Only One', is_default: false },
      ]

      expect(store.defaultCalendar?.id).toBe(1)
    })
  })

  describe('shareCalendar', () => {
    it('should POST share request with snake_case keys', async () => {
      mockApi.post.mockResolvedValueOnce({ data: { success: true } })

      await store.shareCalendar(1, {
        targetEmail: 'colleague@flowone.pro',
        permission: 'edit',
      })

      expect(mockApi.post).toHaveBeenCalledWith(
        '/calendars/1/share',
        expect.objectContaining({
          target_email: 'colleague@flowone.pro',
          permission: 'edit',
        })
      )
    })
  })

  describe('inviteParticipants', () => {
    it('should POST invite request', async () => {
      mockApi.post.mockResolvedValueOnce({
        data: { success: true, data: { invited: 2 } },
      })

      await store.inviteParticipants(10, ['a@test.com', 'b@test.com'])

      expect(mockApi.post).toHaveBeenCalledWith(
        '/events/10/invite',
        expect.objectContaining({
          emails: ['a@test.com', 'b@test.com'],
        })
      )
    })
  })

  describe('quickAddEvent', () => {
    it('should POST quick-add text', async () => {
      mockApi.post.mockResolvedValueOnce({
        data: {
          success: true,
          data: { event: { id: 99, title: 'Lunch tomorrow at noon' } },
        },
      })

      store.calendars = [{ id: 1, name: 'Default', is_default: true }]

      await store.quickAddEvent('Lunch tomorrow at noon')

      expect(mockApi.post).toHaveBeenCalledWith(
        '/events/quick',
        expect.objectContaining({
          text: 'Lunch tomorrow at noon',
        })
      )
    })
  })
})
