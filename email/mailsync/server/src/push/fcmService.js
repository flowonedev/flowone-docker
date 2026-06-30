/**
 * FCM Service for MailSync Server
 *
 * Sends native push notifications to the iOS/Android Capacitor apps via
 * Firebase Cloud Messaging. Device tokens are read from the Redis cache
 * `fcm_tokens:{email}` which the PHP backend rebuilds from MySQL
 * (MySQL is the single source of truth).
 *
 * Token pruning ownership boundary:
 *   When FCM reports a token as unregistered/invalid, this service ONLY
 *   enqueues a prune request onto the durable Redis list `fcm_prune_queue`.
 *   It NEVER edits the token cache directly. A PHP cron drains the queue,
 *   deletes the MySQL row, and rebuilds `fcm_tokens:{email}`.
 *
 * Grouping/collapse:
 *   Derived from the shared notification shape produced by
 *   pushService.buildNotification() (type, tag, conversationId). Grouping
 *   stacks notifications (APNs thread-id / Android tag); collapsing replaces
 *   older with newer (APNs apns-collapse-id / Android collapseKey).
 */

import { readFileSync, existsSync } from 'fs'
import { config } from '../config.js'
import { getDbPool } from '../db/pool.js'

export class FcmService {
  constructor(redis) {
    this.redis = redis
    this.messaging = null
    this.enabled = false
    // Synchronous best-effort flag (credentials present on disk + not disabled).
    // Lets callers gate before the async init resolves, without dropping pushes.
    this.configured = !!(
      config.fcm?.enabled &&
      config.fcm.serviceAccountPath &&
      existsSync(config.fcm.serviceAccountPath)
    )
    // Lazy async init so a missing package/credential never crashes the server.
    this.initPromise = this._init()
  }

  async _init() {
    if (!config.fcm?.enabled) {
      console.log('[FcmService] Disabled via config')
      return
    }

    const saPath = config.fcm.serviceAccountPath
    if (!saPath || !existsSync(saPath)) {
      console.log(`[FcmService] Service account not found at ${saPath} - FCM disabled`)
      return
    }

    try {
      // Use the modular firebase-admin API (firebase-admin/app + /messaging).
      // The legacy default-export namespace (admin.apps / admin.app()) is not
      // reliably exposed under ESM in firebase-admin v13+, so we import the
      // stable sub-path entry points instead.
      const { initializeApp, getApps, cert } = await import('firebase-admin/app')
      const { getMessaging } = await import('firebase-admin/messaging')
      const serviceAccount = JSON.parse(readFileSync(saPath, 'utf8'))

      const app = getApps().length
        ? getApps()[0]
        : initializeApp({ credential: cert(serviceAccount) })

      this.messaging = getMessaging(app)
      this.enabled = true
      console.log('[FcmService] Initialized with Firebase Admin (project: ' +
        (serviceAccount.project_id || 'unknown') + ')')
    } catch (e) {
      console.error('[FcmService] Init failed:', e.message)
    }
  }

  isEnabled() {
    return this.enabled
  }

  /**
   * Normalize a cached/queried token entry to { token, app_id, platform }.
   * Accepts the current object shape and the legacy bare-string shape (which
   * predates per-app routing) so a stale Redis cache never breaks a send.
   */
  normalizeEntry(entry) {
    if (!entry) return null
    if (typeof entry === 'string') {
      return { token: entry, app_id: 'com.flowone.pro', platform: null }
    }
    if (entry.token) {
      return {
        token: entry.token,
        app_id: entry.app_id || 'com.flowone.pro',
        platform: entry.platform || null,
      }
    }
    return null
  }

  /**
   * Read FCM device tokens for a user from the Redis cache.
   * @returns {Promise<Array<{token: string, app_id: string}>>}
   */
  async getTokens(userEmail) {
    const email = userEmail.toLowerCase()
    const key = `${config.redis.prefix}fcm_tokens:${email}`

    // Fast path: the derived Redis cache (rebuilt by the PHP backend on every
    // token change).
    try {
      const data = await this.redis.get(key)
      if (data) {
        const parsed = JSON.parse(data)
        const tokens = Array.isArray(parsed)
          ? parsed.map((e) => this.normalizeEntry(e)).filter(Boolean)
          : []
        if (tokens.length) return tokens
      }
    } catch (e) {
      console.error(`[FcmService] getTokens(redis) failed for ${userEmail}:`, e.message)
    }

    // Cache miss/cold/evicted: fall back to MySQL (source of truth) and re-warm
    // the cache. Without this, an evicted cache silently drops every push.
    return this.getTokensFromDb(email, key)
  }

  /**
   * Read tokens straight from native_push_tokens (source of truth) and re-warm
   * the Redis cache. Returns [] when DB is unconfigured/unreachable so the
   * caller degrades to "no tokens" rather than throwing.
   * @returns {Promise<Array<{token: string, app_id: string}>>}
   */
  async getTokensFromDb(email, redisKey) {
    const pool = getDbPool()
    if (!pool) return []
    try {
      // FCM tokens only — VoIP/PushKit tokens (token_kind='voip') are APNs-only
      // and would be rejected by FCM (and then wrongly pruned). NULL kind is
      // legacy data that predates the column, so treat it as 'fcm'.
      const [rows] = await pool.query(
        "SELECT token, app_id, platform FROM native_push_tokens " +
          "WHERE user_email = ? AND (token_kind = 'fcm' OR token_kind IS NULL)",
        [email]
      )
      const tokens = rows
        .map((r) => this.normalizeEntry({ token: r.token, app_id: r.app_id, platform: r.platform }))
        .filter(Boolean)
      if (tokens.length) {
        // Mirror the PHP backend's 90-day TTL + object shape; best-effort
        // (never block the send).
        this.redis
          .set(redisKey, JSON.stringify(tokens), 'EX', 86400 * 90)
          .catch(() => {})
        console.log(`[FcmService] ${email}: Redis token cache cold — rebuilt from MySQL (${tokens.length})`)
      }
      return tokens
    } catch (e) {
      console.error(`[FcmService] getTokens(db) failed for ${email}:`, e.message)
      return []
    }
  }

  /**
   * Map the notification type to grouping (stack) and collapse (replace) ids.
   * - threadId  -> stacks notifications together (keeps each one)
   * - collapseId -> replaces an older notification with the newer one
   */
  buildGrouping(notification) {
    const { type, tag, conversationId } = notification
    const grouping = {}

    switch (type) {
      case 'email':
        // All new mail stacks under one thread instead of flooding.
        grouping.threadId = 'email'
        break
      case 'chat':
        grouping.threadId = conversationId ? `chat-${conversationId}` : 'chat'
        break
      case 'call':
      case 'missed_call':
        // Ring + missed for the same call should replace, not multiply.
        grouping.threadId = conversationId ? `chat-${conversationId}` : 'call'
        grouping.collapseId = tag || (notification.callId ? `call-${notification.callId}` : undefined)
        break
      case 'calendar':
      case 'calendar_reminder':
        grouping.threadId = 'calendar'
        break
      default:
        grouping.threadId = tag || type || 'general'
    }

    return grouping
  }

  /**
   * Resolve the per-type custom notification sound + Android channel.
   *
   * iOS push sounds must be a short (<30s) PCM file (wav/aiff/caf) bundled in
   * the app target; mp3 is NOT supported by APNs, hence the .wav names.
   * Android 8+ binds the sound to a notification channel (created client-side
   * in nativePush.js), so we set channelId + the res/raw resource name.
   */
  soundFor(type) {
    switch (type) {
      case 'email':
        return { ios: 'new-email.wav', channel: 'flowone_email', android: 'new_email' }
      case 'chat':
        return { ios: 'new-chat.wav', channel: 'flowone_chat', android: 'new_chat' }
      default:
        return { ios: 'default', channel: null, android: null }
    }
  }

  /**
   * Build an FCM MulticastMessage from the shared notification shape.
   * FCM data values must all be strings.
   */
  buildMessage(notification, tokens) {
    const grouping = this.buildGrouping(notification)
    const sound = this.soundFor(notification.type)

    const data = {}
    for (const [k, v] of Object.entries(notification)) {
      if (v === undefined || v === null) continue
      data[k] = typeof v === 'string' ? v : String(v)
    }

    const aps = { sound: sound.ios }
    if (grouping.threadId) aps['thread-id'] = grouping.threadId
    if (typeof notification.badge === 'number') aps.badge = notification.badge

    const apnsHeaders = { 'apns-priority': '10' }
    if (grouping.collapseId) apnsHeaders['apns-collapse-id'] = grouping.collapseId

    const androidNotification = {}
    if (grouping.threadId) androidNotification.tag = grouping.threadId
    if (sound.channel) androidNotification.channelId = sound.channel
    if (sound.android) androidNotification.sound = sound.android
    if (typeof notification.badge === 'number') androidNotification.notificationCount = notification.badge

    return {
      tokens,
      notification: {
        title: notification.title || 'FlowOne',
        body: notification.body || '',
      },
      data,
      android: {
        priority: 'high',
        ...(grouping.collapseId ? { collapseKey: grouping.collapseId } : {}),
        notification: androidNotification,
      },
      apns: {
        headers: apnsHeaders,
        payload: { aps },
      },
    }
  }

  /**
   * Pick which app's device tokens should receive a notification of this type.
   *
   * When a phone has BOTH apps installed it holds two tokens (one per app_id).
   * Without this filter every notification rings both apps. Policy:
   *   - chat / call / missed_call -> the Chat app only, IF the user has it
   *     installed; otherwise fall back to the Pro app so chat-in-Pro users are
   *     still notified.
   *   - everything else (email, calendar, boards, ...) -> never the Chat app.
   *
   * @param {Array<{token: string, app_id: string}>} all
   * @param {string} type notification.type
   * @returns {Array<{token: string, app_id: string}>}
   */
  recipientsFor(all, type) {
    const CHAT_APP = 'com.flowone.chat'
    const isChat = type === 'chat' || type === 'call' || type === 'missed_call'
    if (isChat) {
      const chat = all.filter((t) => t.app_id === CHAT_APP)
      return chat.length ? chat : all.filter((t) => t.app_id !== CHAT_APP)
    }
    return all.filter((t) => t.app_id !== CHAT_APP)
  }

  /**
   * Send a notification to a user's native devices, routed by app (see
   * recipientsFor).
   */
  async send(userEmail, notification) {
    await this.initPromise
    if (!this.enabled) return

    const all = await this.getTokens(userEmail)
    if (all.length === 0) return

    const recipients = this.recipientsFor(all, notification.type)
    if (recipients.length === 0) return

    const tokens = recipients.map((r) => r.token)
    const message = this.buildMessage(notification, tokens)

    try {
      const resp = await this.messaging.sendEachForMulticast(message)

      if (resp.failureCount > 0) {
        resp.responses.forEach((r, i) => {
          if (r.success) return
          const code = r.error?.code || ''
          if (
            code === 'messaging/registration-token-not-registered' ||
            code === 'messaging/invalid-registration-token' ||
            code === 'messaging/invalid-argument'
          ) {
            this.enqueuePrune(userEmail, recipients[i].token)
          } else {
            console.error(`[FcmService] Send error for ${userEmail}:`, code || r.error?.message)
          }
        })
      }

      console.log(`[FcmService] Sent to ${userEmail} (${notification.type}): ${resp.successCount}/${tokens.length}`)
    } catch (e) {
      console.error(`[FcmService] sendEachForMulticast failed for ${userEmail}:`, e.message)
    }
  }

  /**
   * Send a DATA-ONLY, high-priority FCM message to Android Chat-app devices so
   * the native CallMessagingService can post a full-screen-intent IncomingCall
   * activity (the Android equivalent of iOS CallKit). Deliberately omits the
   * `notification` block: a notification-message would be handled by the system
   * tray (no onMessageReceived when backgrounded), but a data-only high-priority
   * message reaches the service even when the app is killed.
   *
   * @param {string} userEmail
   * @param {Array<{token:string, app_id:string, platform:string}>} androidTokens
   * @param {object} callData  call fields ({ callId, callType, callerEmail, ... })
   * @param {{ end?: boolean }} opts  when end=true, signals the device to cancel the FSI
   */
  async sendCallDataAndroid(userEmail, androidTokens, callData, opts = {}) {
    await this.initPromise
    if (!this.enabled) return { sent: 0 }
    const tokens = (androidTokens || []).map((t) => t.token).filter(Boolean)
    if (tokens.length === 0) return { sent: 0 }

    const data = { callEvent: opts.end ? 'end_call' : 'incoming_call' }
    for (const [k, v] of Object.entries(callData || {})) {
      if (v === undefined || v === null) continue
      data[k] = typeof v === 'string' ? v : String(v)
    }

    const message = {
      tokens,
      data,
      android: {
        priority: 'high',
        // Replace an outstanding ring/end for the same call rather than stacking.
        collapseKey: callData?.callId ? `call-${callData.callId}` : undefined,
        // High TTL window is pointless for a ring; keep it short so a stale ring
        // never fires after the call is long over.
        ttl: 30 * 1000,
      },
    }

    try {
      const resp = await this.messaging.sendEachForMulticast(message)
      if (resp.failureCount > 0) {
        resp.responses.forEach((r, i) => {
          if (r.success) return
          const code = r.error?.code || ''
          if (
            code === 'messaging/registration-token-not-registered' ||
            code === 'messaging/invalid-registration-token' ||
            code === 'messaging/invalid-argument'
          ) {
            this.enqueuePrune(userEmail, tokens[i])
          } else {
            console.error(`[FcmService] Android call-data error for ${userEmail}:`, code || r.error?.message)
          }
        })
      }
      console.log(`[FcmService] Android call-data (${data.callEvent}) -> ${userEmail}: ${resp.successCount}/${tokens.length}`)
      return { sent: resp.successCount }
    } catch (e) {
      console.error(`[FcmService] sendCallDataAndroid failed for ${userEmail}:`, e.message)
      return { sent: 0 }
    }
  }

  /**
   * Enqueue a dead token for the PHP cron to prune. Node never deletes tokens
   * from MySQL or the Redis cache itself.
   */
  async enqueuePrune(userEmail, token) {
    try {
      const key = `${config.redis.prefix}fcm_prune_queue`
      await this.redis.rpush(key, JSON.stringify({ email: userEmail, token, ts: Date.now() }))
      console.log(`[FcmService] Enqueued prune for ${userEmail} (token …${String(token).slice(-8)})`)
    } catch (e) {
      console.error('[FcmService] enqueuePrune failed:', e.message)
    }
  }
}
