/**
 * Push Notifications for FlowOneChatMobile (Capacitor native)
 *
 * Native: uses @capacitor-firebase/messaging to obtain an FCM registration
 * token (works on iOS via APNs + Android) and registers it with the backend,
 * including a stable device_id/name for server-side dedupe. Re-registers on app
 * resume so last_seen_at stays fresh, and unregisters on logout.
 *
 * Web: falls back to the browser Notification API (status only).
 */

import { ref } from 'vue'
import api from '@/services/api'

const isNative = typeof window !== 'undefined' && !!window.Capacitor?.isNativePlatform?.()
const APP_ID = 'com.flowone.chat'

export const pushStatus = ref('unknown')

function getPlatform() {
  if (/iPad|iPhone|iPod/.test(navigator.userAgent)) return 'ios'
  if (/Android/.test(navigator.userAgent)) return 'android'
  return 'unknown'
}

async function getDeviceInfo() {
  try {
    const { Device } = await import('@capacitor/device')
    const id = await Device.getId()
    const info = await Device.getInfo()
    const deviceId = id?.identifier || id?.uuid || null
    const deviceName =
      [info?.manufacturer, info?.model].filter(Boolean).join(' ') ||
      info?.name ||
      info?.model ||
      null
    return { deviceId, deviceName }
  } catch (e) {
    return { deviceId: null, deviceName: null }
  }
}

class PushNotificationService {
  constructor() {
    this.initialized = false
    this.currentToken = null
    this.currentDeviceId = null
  }

  get isSupported() {
    if (isNative) return true
    return 'Notification' in window
  }

  async sendToken(token) {
    if (!token) return
    const { deviceId, deviceName } = await getDeviceInfo()
    this.currentToken = token
    this.currentDeviceId = deviceId
    try {
      await api.post('/push/native-register', {
        platform: getPlatform(),
        token,
        device_id: deviceId || undefined,
        device_name: deviceName || undefined,
        app_id: APP_ID,
      })
    } catch (e) {
      console.warn('[NativePush] Failed to send token:', e.message)
    }
  }

  async init(router) {
    if (this.initialized) return true
    if (!this.isSupported) { pushStatus.value = 'unsupported'; return false }

    if (isNative) {
      try {
        const { FirebaseMessaging } = await import('@capacitor-firebase/messaging')

        const permResult = await FirebaseMessaging.requestPermissions()
        if (permResult.receive !== 'granted') {
          pushStatus.value = 'denied'
          return false
        }

        await FirebaseMessaging.addListener('tokenReceived', (event) => {
          if (event?.token) this.sendToken(event.token)
        })

        await FirebaseMessaging.addListener('notificationActionPerformed', (action) => {
          const data = action?.notification?.data
          if (!data || !router) return
          if (data.type === 'chat' && data.conversationId) {
            router.push({ path: '/chat', query: { conversation: data.conversationId } })
          } else if (data.url) {
            router.push(data.url)
          }
        })

        try {
          const { token } = await FirebaseMessaging.getToken()
          await this.sendToken(token)
        } catch (e) {
          console.warn('[NativePush] getToken failed:', e.message)
        }

        // Re-register on resume (webview visible) to refresh last_seen_at.
        document.addEventListener('visibilitychange', async () => {
          if (document.visibilityState !== 'visible') return
          try {
            const { token } = await FirebaseMessaging.getToken()
            if (token) await this.sendToken(token)
          } catch (_e) { /* ignore */ }
        })

        this.initialized = true
        pushStatus.value = 'subscribed'
        return true
      } catch (e) {
        console.error('[NativePush] Init failed:', e)
        pushStatus.value = 'unsupported'
        return false
      }
    }

    pushStatus.value = Notification.permission === 'granted' ? 'subscribed'
                     : Notification.permission === 'denied' ? 'denied' : 'unsubscribed'
    this.initialized = true
    return true
  }

  async refreshStatus() {
    if (isNative) { pushStatus.value = 'subscribed'; return }
    if (!('Notification' in window)) { pushStatus.value = 'unsupported'; return }
    pushStatus.value = Notification.permission === 'granted' ? 'subscribed'
                     : Notification.permission === 'denied' ? 'denied' : 'unsubscribed'
  }

  async syncExisting() { return null }

  async subscribe() {
    if (isNative) { pushStatus.value = 'subscribed'; return true }
    if (!('Notification' in window)) { pushStatus.value = 'unsupported'; return null }
    const permission = await Notification.requestPermission()
    pushStatus.value = permission === 'granted' ? 'subscribed'
                     : permission === 'denied' ? 'denied' : 'unsubscribed'
    return permission === 'granted' ? true : null
  }

  async unsubscribe() { pushStatus.value = 'unsubscribed' }

  /**
   * Drive the native app-icon badge to the authoritative unread total.
   *
   * The Web Badging API (navigator.setAppBadge) is NOT available inside the
   * Capacitor WKWebView, so without this the iOS badge stays frozen at the last
   * push's aps.badge value and never clears when everything is read. Best-effort
   * and self-guarding: no-op off-native, swallows plugin errors.
   */
  async setBadge(count) {
    if (!isNative) return
    try {
      const { Badge } = await import('@capawesome/capacitor-badge')
      const n = Math.max(0, count | 0)
      if (n > 0) {
        await Badge.set({ count: n })
      } else {
        await Badge.clear()
      }
    } catch (e) {
      console.warn('[NativePush] setBadge failed:', e?.message)
    }
  }

  /**
   * Drop this device's token on logout. Best-effort; never blocks logout.
   */
  async unregister() {
    if (!isNative) return
    let deviceId = this.currentDeviceId
    if (!deviceId) deviceId = (await getDeviceInfo()).deviceId
    try {
      await api.post('/push/native-unregister', {
        token: this.currentToken || undefined,
        device_id: deviceId || undefined,
        app_id: APP_ID,
      })
    } catch (e) {
      // ignore
    }
    try {
      const { FirebaseMessaging } = await import('@capacitor-firebase/messaging')
      await FirebaseMessaging.deleteToken()
    } catch (_e) { /* ignore */ }
    this.currentToken = null
  }

  async getStatus() {
    if (isNative) return 'subscribed'
    if (!('Notification' in window)) return 'unsupported'
    return Notification.permission === 'granted' ? 'subscribed' : 'unsubscribed'
  }

  async show(title, body, options = {}) {
    if ('Notification' in window && Notification.permission === 'granted') {
      const notification = new Notification(title, { body, ...options })
      if (options.onClick) notification.onclick = options.onClick
      return notification
    }
    return null
  }

  listenForNotificationClicks(_router) {}
}

export const pushNotifications = new PushNotificationService()
export default pushNotifications
