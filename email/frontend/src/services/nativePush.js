/**
 * Native Push Notifications (Capacitor + Firebase Cloud Messaging)
 *
 * Acquires an FCM registration token via @capacitor-firebase/messaging (works
 * on both iOS — riding on APNs — and Android) and registers it with the backend
 * so the mailsync server can deliver email/chat/call/calendar pushes.
 *
 * Device dedupe: each token is keyed server-side by (user, app, device_id). We
 * source a stable device_id + name from @capacitor/device and re-register on
 * every app resume so last_seen_at stays fresh and rotated tokens replace the
 * previous one for the same device. On logout we unregister immediately so a
 * re-used device never receives the previous user's notifications.
 *
 * Only active inside the Capacitor native shell.
 */

import { isNative } from './nativeConfig'
import { isDebugEnabled } from '@/utils/debug'
import api from './api'

const APP_ID = 'com.flowone.pro'

let initialized = false
let currentToken = null
let currentDeviceId = null

function getPlatform() {
  if (/iPad|iPhone|iPod/.test(navigator.userAgent)) return 'ios'
  if (/Android/.test(navigator.userAgent)) return 'android'
  return 'unknown'
}

/**
 * Stable per-install device id + a human-readable name (best-effort).
 */
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

/**
 * Send the current FCM token (+ device metadata) to the backend.
 */
async function sendToken(token) {
  if (!token) return
  const { deviceId, deviceName } = await getDeviceInfo()
  currentToken = token
  currentDeviceId = deviceId

  try {
    await api.post('/push/native-register', {
      platform: getPlatform(),
      token,
      device_id: deviceId || undefined,
      device_name: deviceName || undefined,
      app_id: APP_ID,
    })
    isDebugEnabled() && console.log('[NativePush] Token registered with backend')
  } catch (e) {
    console.warn('[NativePush] Failed to send token to backend:', e.message)
  }
}

/**
 * Android 8+ binds a notification's sound to its channel (the sound chosen at
 * channel creation is locked for that channel id forever). Create dedicated
 * channels wired to the bundled res/raw sounds so the FCM payload can target
 * them via android.notification.channelId (set server-side in fcmService). The
 * channel ids are versioned so future sound changes use a fresh id.
 *
 * No-op on iOS (sound comes from aps.sound + the bundled .wav).
 */
async function ensureAndroidChannels() {
  if (getPlatform() !== 'android') return
  try {
    const { PushNotifications } = await import('@capacitor/push-notifications')
    const channels = [
      { id: 'flowone_email', name: 'Email', description: 'New email notifications', sound: 'new_email' },
      { id: 'flowone_chat', name: 'Chat', description: 'New chat messages', sound: 'new_chat' },
    ]
    for (const ch of channels) {
      await PushNotifications.createChannel({
        id: ch.id,
        name: ch.name,
        description: ch.description,
        sound: ch.sound, // res/raw/<sound> (no extension)
        importance: 5, // IMPORTANCE_HIGH (heads-up banner + sound)
        visibility: 1, // VISIBILITY_PUBLIC
        vibration: true,
      })
    }
    isDebugEnabled() && console.log('[NativePush] Android channels ready')
  } catch (e) {
    isDebugEnabled() && console.warn('[NativePush] createChannel failed:', e?.message)
  }
}

function navigateFromData(router, data) {
  if (!data || !router) return
  if (data.type === 'chat' && data.conversationId) {
    router.push({ path: '/chat', query: { conversation: data.conversationId } })
  } else if (data.url) {
    router.push(data.url)
  }
}

/**
 * Initialize native push. Call after the user is authenticated.
 */
export async function initNativePush(router) {
  if (!isNative || initialized) return
  initialized = true

  try {
    const { FirebaseMessaging } = await import('@capacitor-firebase/messaging')

    const perm = await FirebaseMessaging.requestPermissions()
    isDebugEnabled() && console.log('[NativePush] Permission:', perm.receive)
    if (perm.receive !== 'granted') {
      console.warn('[NativePush] Notification permission not granted:', perm.receive)
      return
    }

    await ensureAndroidChannels()

    // FCM rotates tokens; keep the backend in sync.
    await FirebaseMessaging.addListener('tokenReceived', (event) => {
      if (event?.token) sendToken(event.token)
    })

    // Notification tapped -> route in-app.
    await FirebaseMessaging.addListener('notificationActionPerformed', (action) => {
      isDebugEnabled() && console.log('[NativePush] Notification tapped:', action)
      navigateFromData(router, action?.notification?.data)
    })

    // Initial token.
    try {
      const { token } = await FirebaseMessaging.getToken()
      await sendToken(token)
    } catch (e) {
      console.warn('[NativePush] getToken failed:', e.message)
    }

    // Re-register on app resume (webview becomes visible) so last_seen_at stays
    // fresh and any rotated token is captured. Dependency-free (no @capacitor/app).
    document.addEventListener('visibilitychange', async () => {
      if (document.visibilityState !== 'visible') return
      try {
        const { token } = await FirebaseMessaging.getToken()
        if (token) await sendToken(token)
      } catch (_e) {
        /* ignore */
      }
    })

    isDebugEnabled() && console.log('[NativePush] Initialized successfully')
  } catch (e) {
    console.error('[NativePush] Init failed:', e)
  }
}

/**
 * Set (or clear) the native app-icon badge to the authoritative unread total.
 *
 * The Web Badging API (navigator.setAppBadge), used for installed PWAs, is NOT
 * available inside the Capacitor WKWebView / Android shell — so without this the
 * app-icon badge stays frozen at whatever the last push's aps.badge value was
 * and never clears when the user reads everything (the reported bug). This
 * drives the native badge directly.
 *
 * Best-effort and self-guarding: no-op off-native, swallows plugin/permission
 * errors so a badge glitch never breaks the app.
 */
export async function setNativeBadge(count) {
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
    isDebugEnabled() && console.warn('[NativePush] setNativeBadge failed:', e?.message)
  }
}

/**
 * Unregister this device's token on logout. Best-effort; never blocks logout.
 */
export async function unregisterNativePush() {
  if (!isNative) return

  let deviceId = currentDeviceId
  if (!deviceId) {
    deviceId = (await getDeviceInfo()).deviceId
  }

  try {
    await api.post('/push/native-unregister', {
      token: currentToken || undefined,
      device_id: deviceId || undefined,
      app_id: APP_ID,
    })
    isDebugEnabled() && console.log('[NativePush] Token unregistered from backend')
  } catch (e) {
    // ignore - logout must proceed regardless
  }

  try {
    const { FirebaseMessaging } = await import('@capacitor-firebase/messaging')
    await FirebaseMessaging.deleteToken()
  } catch (_e) {
    /* ignore */
  }

  currentToken = null
}
