// Browser Notifications Service
// Provides Windows desktop notifications for email tracking and calendar events

import { useAddons } from '@/composables/useAddons'

class BrowserNotifications {
  constructor() {
    // Must check 'Notification' in window first - iOS Safari throws ReferenceError if Notification is accessed directly
    this.permission = ('Notification' in window) ? Notification.permission : 'default'
    this.enabled = localStorage.getItem('browser_notifications') !== 'false' // Enabled by default
    this.shownNotifications = new Set() // Track shown notifications to prevent duplicates
  }

  async requestPermission() {
    if (!('Notification' in window)) {
      console.warn('Browser does not support notifications')
      return false
    }

    if (Notification.permission === 'granted') {
      this.permission = 'granted'
      return true
    }

    if (Notification.permission !== 'denied') {
      const result = await Notification.requestPermission()
      this.permission = result
      return result === 'granted'
    }

    return false
  }

  isSupported() {
    return 'Notification' in window
  }

  isEnabled() {
    return this.enabled && this.isSupported() && Notification.permission === 'granted'
  }

  setEnabled(value) {
    this.enabled = value
    localStorage.setItem('browser_notifications', value.toString())
    
    // Request permission when enabling (only if supported)
    if (value && this.isSupported() && Notification.permission === 'default') {
      this.requestPermission()
    }
  }

  async show(title, options = {}) {
    // Check if notifications are supported and enabled
    if (!this.isSupported()) {
      console.warn('Browser notifications not supported')
      return null
    }

    // Auto-request permission if default
    if (Notification.permission === 'default') {
      await this.requestPermission()
    }

    if (Notification.permission !== 'granted') {
      return null
    }

    if (!this.enabled) {
      return null
    }

    // Prevent duplicate notifications
    const notificationKey = options.tag || `${title}-${Date.now()}`
    if (this.shownNotifications.has(notificationKey)) {
      return null
    }
    this.shownNotifications.add(notificationKey)
    
    // Clean up old notification keys after a while
    setTimeout(() => {
      this.shownNotifications.delete(notificationKey)
    }, 60000) // 1 minute

    try {
      const { onClick: onClickCb, autoClose: autoCloseOpt, ...notifOptions } = options
      const notification = new Notification(title, {
        icon: '/flowone-logo.png?v=2',
        badge: '/flowone-logo.png?v=2',
        tag: notifOptions.tag || 'webmail',
        renotify: notifOptions.renotify !== false, // Re-notify by default
        requireInteraction: notifOptions.requireInteraction || false,
        silent: notifOptions.silent || false,
        ...notifOptions,
      })

      // Auto-close after specified time or 15 seconds
      const autoCloseTime = autoCloseOpt !== undefined ? autoCloseOpt : 15000
      if (autoCloseTime > 0) {
        setTimeout(() => notification.close(), autoCloseTime)
      }

      notification.onclick = () => {
        window.focus()
        notification.close()
        if (onClickCb) onClickCb()
      }

      notification.onerror = (e) => {
        console.error('Notification error:', e)
      }

      return notification
    } catch (e) {
      console.error('Failed to show notification:', e)
      return null
    }
  }

  // Show read receipt notification (email was read)
  showReadReceipt(data) {
    // Skip if email tracking addon is disabled
    try {
      const { emailTrackingEnabled } = useAddons()
      if (!emailTrackingEnabled.value) return null
    } catch (_) { /* composable may not be ready */ }

    // Check if read receipt notifications are enabled
    if (localStorage.getItem('notification_read_receipts') === 'false') {
      return null
    }
    
    const recipient = data.recipient_display || data.recipient || 'Someone'
    const subject = data.subject || 'your email'
    const shortSubject = subject.length > 50 ? subject.substring(0, 50) + '...' : subject
    
    return this.show(`Email Read: ${recipient}`, {
      body: `"${shortSubject}" was opened`,
      tag: `read-receipt-${data.tracking_id || Date.now()}`,
      requireInteraction: false,
      autoClose: 10000,
    })
  }

  // Show calendar event reminder.
  // The calendar store decides which "minutes before" fire (configured defaults
  // merged with the event's own custom reminders), so this method does not
  // re-filter by reminder time — it only honors the master calendar toggle.
  showEventReminder(event, minutesBefore = 15) {
    // Check if calendar notifications are enabled
    if (localStorage.getItem('notification_calendar') === 'false') {
      return null
    }
    
    const startTime = new Date(event.start_time)
    const timeStr = startTime.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })
    
    let body = `Starting at ${timeStr}`
    if (minutesBefore > 0) {
      body = `Starting in ${minutesBefore} minute${minutesBefore > 1 ? 's' : ''} (${timeStr})`
    }
    if (event.location) {
      body += `\nLocation: ${event.location}`
    }
    
    return this.show(`Upcoming: ${event.title}`, {
      body,
      tag: `event-reminder-${event.id}`,
      requireInteraction: true, // Keep visible until user dismisses
      autoClose: 0, // Don't auto-close calendar reminders
    })
  }

  // Show calendar event starting now.
  // Only called by the calendar store when a 0-minute threshold is active for
  // the event, so the "at start time" filtering lives there, not here.
  showEventStarting(event) {
    // Check if calendar notifications are enabled
    if (localStorage.getItem('notification_calendar') === 'false') {
      return null
    }
    
    let body = 'Starting now'
    if (event.location) {
      body += ` at ${event.location}`
    }
    
    return this.show(`Now: ${event.title}`, {
      body,
      tag: `event-now-${event.id}`,
      requireInteraction: true,
      autoClose: 0,
    })
  }

  /**
   * Show new email desktop notification (MESSAGE_NEW).
   * @param {object} email - { uid, from_name, from_email, subject }
   * @param {{ onClick?: () => void }} [callbacks]
   */
  showNewEmail(email, callbacks = {}) {
    if (localStorage.getItem('notification_new_email') === 'false') {
      return null
    }

    const from = email.from_name || email.from_email || 'Unknown sender'
    const subject = email.subject || 'No subject'
    const shortSubject = subject.length > 50 ? subject.substring(0, 50) + '...' : subject

    return this.show(`New email from ${from}`, {
      body: shortSubject,
      tag: `new-email-${email.uid || Date.now()}`,
      autoClose: 10000,
      onClick: callbacks.onClick,
    })
  }

  /**
   * Show new chat message desktop notification (CHAT_MESSAGE_NEW).
   * The chat store decides WHEN to call this (it already skips your own
   * messages, the conversation you're actively viewing, and muted
   * conversations); this method only honors the master toggle + the per-type
   * chat preference.
   * @param {object} chat - { title, body, conversationId }
   * @param {{ onClick?: () => void }} [callbacks]
   */
  showNewChat(chat = {}, callbacks = {}) {
    if (localStorage.getItem('notification_chat') === 'false') {
      return null
    }

    return this.show(chat.title || 'New message', {
      body: chat.body || 'You have a new message',
      tag: `new-chat-${chat.conversationId || Date.now()}`,
      autoClose: 10000,
      onClick: callbacks.onClick,
    })
  }
}

export const browserNotifications = new BrowserNotifications()
export default browserNotifications

