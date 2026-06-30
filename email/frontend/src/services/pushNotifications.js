/**
 * Push Notifications Service
 * 
 * Manages Web Push subscription lifecycle:
 * - Requests notification permission (MUST be from user gesture on iOS!)
 * - Subscribes to push via the PushManager API
 * - Sends subscription to backend for storage
 * - Handles unsubscribe
 * 
 * Works on:
 * - Android (Chrome, Edge, Firefox) - full support
 * - iOS 16.4+ Safari - ONLY when added to Home Screen as PWA
 * - Desktop browsers (Chrome, Edge, Firefox)
 * 
 * IMPORTANT iOS notes:
 * - Notification.requestPermission() MUST be called from a user gesture (tap/click)
 * - Auto-calling on page load will silently fail on iOS
 * - The app MUST be installed to Home Screen (not Safari browser)
 */

import api from '@/services/api'
import { ref } from 'vue'
import { isDebugEnabled } from '@/utils/debug'
import notificationSounds from '@/services/notificationSounds'

// Reactive state for UI bindings
export const pushStatus = ref('unknown') // 'subscribed' | 'unsubscribed' | 'denied' | 'unsupported' | 'unknown'

class PushNotificationService {
  constructor() {
    this.vapidPublicKey = null
    this.subscription = null
    this.initialized = false
  }

  /**
   * Check if push notifications are supported in this browser
   */
  get isSupported() {
    return 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window
  }

  /**
   * Check if running as installed PWA (standalone mode)
   * On iOS, push ONLY works in standalone mode (added to Home Screen)
   */
  get isStandalone() {
    return window.matchMedia('(display-mode: standalone)').matches ||
           window.navigator.standalone === true // iOS Safari
  }

  /**
   * Detect if this is an iOS device
   */
  get isIOS() {
    return /iPad|iPhone|iPod/.test(navigator.userAgent) || 
           (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1)
  }

  /**
   * Set the VAPID key from bootstrap data (avoids a separate API call).
   */
  setVapidKey(key) {
    if (key) {
      this.vapidPublicKey = key
    }
  }

  /**
   * Initialize: use bootstrap VAPID key or fetch from backend as fallback
   */
  async init() {
    if (this.initialized) return true
    if (!this.isSupported) {
      isDebugEnabled() && console.log('[Push] Push notifications not supported in this browser')
      pushStatus.value = 'unsupported'
      return false
    }

    // On iOS, must be running as installed PWA
    if (this.isIOS && !this.isStandalone) {
      isDebugEnabled() && console.log('[Push] iOS detected but not running as installed PWA - push not available')
      pushStatus.value = 'unsupported'
      return false
    }

    // Use VAPID key already set from bootstrap if available
    if (this.vapidPublicKey) {
      this.initialized = true
      isDebugEnabled() && console.log('[Push] Initialized with bootstrap VAPID key')
      await this.refreshStatus()
      return true
    }

    // Fallback: fetch from backend
    try {
      const { data } = await api.get('/push/vapid-key')
      if (data.publicKey) {
        this.vapidPublicKey = data.publicKey
        this.initialized = true
        isDebugEnabled() && console.log('[Push] Initialized with VAPID key from API')
        await this.refreshStatus()
        return true
      } else {
        console.warn('[Push] VAPID key not configured on server')
        pushStatus.value = 'unsupported'
        return false
      }
    } catch (e) {
      console.error('[Push] Failed to fetch VAPID key:', e.message)
      pushStatus.value = 'unsupported'
      return false
    }
  }

  /**
   * Refresh the reactive push status
   */
  async refreshStatus() {
    if (!this.isSupported) {
      pushStatus.value = 'unsupported'
      return
    }
    if (Notification.permission === 'denied') {
      pushStatus.value = 'denied'
      return
    }
    try {
      const registration = await navigator.serviceWorker.ready
      const subscription = await registration.pushManager.getSubscription()
      pushStatus.value = subscription ? 'subscribed' : 'unsubscribed'
    } catch (e) {
      pushStatus.value = 'unsupported'
    }
  }

  /**
   * Silently sync an EXISTING subscription with the backend.
   * Does NOT request permission - safe to call on page load.
   * This is what gets called automatically on login.
   */
  async syncExisting() {
    if (!this.isSupported) return null

    // Initialize if needed
    if (!this.initialized) {
      const ok = await this.init()
      if (!ok) return null
    }

    try {
      const registration = await navigator.serviceWorker.ready
      const subscription = await registration.pushManager.getSubscription()

      if (subscription) {
        // Already subscribed - sync with backend
        await this.sendSubscriptionToBackend(subscription)
        this.subscription = subscription
        pushStatus.value = 'subscribed'
        isDebugEnabled() && console.log('[Push] Existing subscription synced with backend')
        return subscription
      }

      // No existing subscription - don't auto-request (needs user gesture on iOS)
      pushStatus.value = Notification.permission === 'denied' ? 'denied' : 'unsubscribed'
      return null
    } catch (e) {
      console.error('[Push] Failed to sync existing subscription:', e)
      return null
    }
  }

  /**
   * Subscribe to push notifications.
   * ⚠️ MUST be called from a user gesture (button click/tap) on iOS!
   * Requests permission, subscribes via PushManager, sends to backend.
   * Returns the PushSubscription or null.
   */
  async subscribe() {
    if (!this.isSupported) {
      pushStatus.value = 'unsupported'
      return null
    }

    // On iOS, warn if not standalone
    if (this.isIOS && !this.isStandalone) {
      console.warn('[Push] iOS: Must install PWA to Home Screen for push notifications')
      pushStatus.value = 'unsupported'
      return null
    }

    // Initialize if needed
    if (!this.initialized) {
      const ok = await this.init()
      if (!ok) return null
    }

    try {
      const registration = await navigator.serviceWorker.ready

      // Check if already subscribed
      let subscription = await registration.pushManager.getSubscription()

      if (subscription) {
        // Already subscribed - sync with backend (in case it was lost)
        await this.sendSubscriptionToBackend(subscription)
        this.subscription = subscription
        pushStatus.value = 'subscribed'
        isDebugEnabled() && console.log('[Push] Already subscribed, synced with backend')
        return subscription
      }

      // Request notification permission - THIS MUST BE FROM USER GESTURE ON iOS
      const permission = await Notification.requestPermission()
      if (permission !== 'granted') {
        isDebugEnabled() && console.log('[Push] Notification permission denied:', permission)
        pushStatus.value = permission === 'denied' ? 'denied' : 'unsubscribed'
        return null
      }

      // Subscribe to push
      subscription = await registration.pushManager.subscribe({
        userVisibleOnly: true,
        applicationServerKey: this.urlBase64ToUint8Array(this.vapidPublicKey)
      })

      // Save to backend
      await this.sendSubscriptionToBackend(subscription)
      this.subscription = subscription
      pushStatus.value = 'subscribed'

      isDebugEnabled() && console.log('[Push] Successfully subscribed to push notifications')
      return subscription
    } catch (e) {
      console.error('[Push] Failed to subscribe:', e)
      return null
    }
  }

  /**
   * Unsubscribe from push notifications
   */
  async unsubscribe() {
    if (!this.isSupported) return

    try {
      const registration = await navigator.serviceWorker.ready
      const subscription = await registration.pushManager.getSubscription()

      if (subscription) {
        // Remove from backend first
        try {
          await api.post('/push/unsubscribe', {
            endpoint: subscription.endpoint
          })
        } catch (e) {
          console.warn('[Push] Failed to remove subscription from backend:', e.message)
        }

        // Unsubscribe locally
        await subscription.unsubscribe()
        this.subscription = null
        pushStatus.value = 'unsubscribed'
        isDebugEnabled() && console.log('[Push] Unsubscribed from push notifications')
      }
    } catch (e) {
      console.error('[Push] Failed to unsubscribe:', e)
    }
  }

  /**
   * Get current subscription status
   * @returns {'subscribed'|'unsubscribed'|'denied'|'unsupported'}
   */
  async getStatus() {
    if (!this.isSupported) return 'unsupported'

    if (Notification.permission === 'denied') return 'denied'

    try {
      const registration = await navigator.serviceWorker.ready
      const subscription = await registration.pushManager.getSubscription()
      return subscription ? 'subscribed' : 'unsubscribed'
    } catch (e) {
      return 'unsupported'
    }
  }

  /**
   * Send the PushSubscription to the backend for storage
   */
  async sendSubscriptionToBackend(subscription) {
    try {
      const subJSON = subscription.toJSON()
      await api.post('/push/subscribe', {
        endpoint: subJSON.endpoint,
        keys: {
          p256dh: subJSON.keys.p256dh,
          auth: subJSON.keys.auth
        }
      })
      isDebugEnabled() && console.log('[Push] Subscription sent to backend')
    } catch (e) {
      console.error('[Push] Failed to send subscription to backend:', e.message)
    }
  }

  /**
   * Convert a URL-safe base64 string to a Uint8Array (for applicationServerKey)
   */
  urlBase64ToUint8Array(base64String) {
    const padding = '='.repeat((4 - base64String.length % 4) % 4)
    const base64 = (base64String + padding)
      .replace(/-/g, '+')
      .replace(/_/g, '/')

    const rawData = window.atob(base64)
    const outputArray = new Uint8Array(rawData.length)

    for (let i = 0; i < rawData.length; ++i) {
      outputArray[i] = rawData.charCodeAt(i)
    }
    return outputArray
  }

  /**
   * Listen for notification click messages from the service worker
   */
  listenForNotificationClicks(router) {
    if (!('serviceWorker' in navigator)) return

    navigator.serviceWorker.addEventListener('message', async (event) => {
      // New email push delivered while the app is focused (on any route / in
      // the PWA). The SW forwards it instead of showing an OS alert, so play
      // the email chime here too. The per-type throttle dedupes against the
      // WebSocket path, so this is a safe fallback that guarantees the email
      // sound everywhere in the app. (Chat is handled in the chat store, which
      // has its own "active conversation" guard, so it is not duplicated here.)
      if (event.data?.type === 'PUSH_RECEIVED') {
        if (event.data.payload?.type === 'email' && localStorage.getItem('notification_new_email') !== 'false') {
          notificationSounds.playEmailSound()
        }
      }

      if (event.data?.type === 'NOTIFICATION_CLICK') {
        const { url, notificationType, conversationId } = event.data

        if (notificationType === 'chat' && conversationId) {
          // Navigate to chat
          router.push({ path: '/chat', query: { conversation: conversationId } })
        } else if (url) {
          router.push(url)
        }
      }

      // Handle Answer/Decline actions from call push notifications
      if (event.data?.type === 'CALL_ACTION_FROM_NOTIFICATION') {
        const { action, callId, conversationId, url } = event.data
        const { useCallStore } = await import('@/stores/call')
        const { useCallLauncher } = await import('@/composables/useCallLauncher')
        const callStore = useCallStore()
        const callLauncher = useCallLauncher()

        if (action === 'decline') {
          // User tapped "Decline" on the notification
          if (callStore.callId === callId || callStore.callStatus === 'ringing') {
            callStore.rejectCall(callId || callStore.callId, 'declined')
          }
        } else if (action === 'answer') {
          // User tapped "Answer" — navigate to chat and answer
          if (url) {
            router.push(url)
          }
          // Wait a moment for navigation and call state to settle, then answer
          const tryAnswer = () => {
            if (callStore.callStatus === 'ringing') {
              callLauncher.acceptIncomingCall()
              return true
            }
            return false
          }
          // Try immediately, then retry for up to 3 seconds (call event may still be in transit)
          if (!tryAnswer()) {
            let attempts = 0
            const interval = setInterval(() => {
              if (tryAnswer() || ++attempts > 6) {
                clearInterval(interval)
              }
            }, 500)
          }
        }
      }
    })
  }
}

export const pushNotifications = new PushNotificationService()
export default pushNotifications
