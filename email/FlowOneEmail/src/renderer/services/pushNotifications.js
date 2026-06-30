/**
 * Push Notifications Service (Electron Desktop)
 * 
 * Uses Electron's native Notification API instead of browser PushManager/ServiceWorker.
 * Electron has full Chromium Notification support built-in.
 * 
 * For desktop: uses Electron's Notification (native OS notifications)
 * Falls back to browser Notification API as secondary.
 */

import { ref } from 'vue'
import { isElectron, showNotification } from '@/services/electronApi'

// Reactive state for UI bindings
export const pushStatus = ref('unknown') // 'subscribed' | 'unsubscribed' | 'denied' | 'unsupported' | 'unknown'

class PushNotificationService {
  constructor() {
    this.initialized = false
  }

  /**
   * Check if notifications are supported
   * In Electron, native notifications are always available
   */
  get isSupported() {
    if (isElectron()) return true
    return 'Notification' in window
  }

  /**
   * Initialize notification service
   */
  async init() {
    if (this.initialized) return true

    if (!this.isSupported) {
      console.log('[Push] Notifications not supported')
      pushStatus.value = 'unsupported'
      return false
    }

    if (isElectron()) {
      // Electron: native notifications are always available, no VAPID/service worker needed
      this.initialized = true
      pushStatus.value = 'subscribed'
      console.log('[Push] Initialized (Electron native notifications)')
      return true
    }

    // Browser fallback
    if (Notification.permission === 'granted') {
      pushStatus.value = 'subscribed'
    } else if (Notification.permission === 'denied') {
      pushStatus.value = 'denied'
    } else {
      pushStatus.value = 'unsubscribed'
    }

    this.initialized = true
    return true
  }

  /**
   * Refresh the reactive push status
   */
  async refreshStatus() {
    if (isElectron()) {
      pushStatus.value = 'subscribed'
      return
    }
    if (!('Notification' in window)) {
      pushStatus.value = 'unsupported'
      return
    }
    pushStatus.value = Notification.permission === 'granted' ? 'subscribed' 
                     : Notification.permission === 'denied' ? 'denied' 
                     : 'unsubscribed'
  }

  /**
   * Sync existing subscription (no-op for Electron, native notifs are always on)
   */
  async syncExisting() {
    if (isElectron()) {
      pushStatus.value = 'subscribed'
      return true
    }
    return null
  }

  /**
   * Subscribe to notifications
   * In Electron: always available. In browser: request permission.
   */
  async subscribe() {
    if (isElectron()) {
      pushStatus.value = 'subscribed'
      return true
    }

    if (!('Notification' in window)) {
      pushStatus.value = 'unsupported'
      return null
    }

    const permission = await Notification.requestPermission()
    if (permission === 'granted') {
      pushStatus.value = 'subscribed'
      return true
    }

    pushStatus.value = permission === 'denied' ? 'denied' : 'unsubscribed'
    return null
  }

  /**
   * Unsubscribe from notifications (no-op for Electron desktop)
   */
  async unsubscribe() {
    pushStatus.value = 'unsubscribed'
  }

  /**
   * Get current subscription status
   */
  async getStatus() {
    if (isElectron()) return 'subscribed'
    if (!('Notification' in window)) return 'unsupported'
    if (Notification.permission === 'denied') return 'denied'
    return Notification.permission === 'granted' ? 'subscribed' : 'unsubscribed'
  }

  /**
   * Show a notification (Electron native or browser fallback)
   * @param {string} title 
   * @param {string} body 
   * @param {object} options - Additional options like onClick handler
   */
  async show(title, body, options = {}) {
    if (isElectron()) {
      return showNotification(title, body)
    }

    // Browser fallback
    if ('Notification' in window && Notification.permission === 'granted') {
      const notification = new Notification(title, { body, ...options })
      if (options.onClick) {
        notification.onclick = options.onClick
      }
      return notification
    }
    return null
  }

  /**
   * Listen for notification click events
   * In Electron, the main process handles click routing via IPC
   */
  listenForNotificationClicks(router) {
    if (isElectron() && window.api) {
      // Listen for notification clicks from main process
      window.api.on('notification-click', (data) => {
        if (data?.type === 'chat' && data?.conversationId) {
          router.push({ path: '/chat', query: { conversation: data.conversationId } })
        } else if (data?.url) {
          router.push(data.url)
        }
      })
    }
  }
}

export const pushNotifications = new PushNotificationService()
export default pushNotifications
