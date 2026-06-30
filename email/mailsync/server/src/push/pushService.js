/**
 * Push Notification Service for MailSync Server
 * 
 * Sends Web Push notifications to users who are offline (no WebSocket connection).
 * Reads push subscriptions from Redis (synced by PHP backend).
 * 
 * Uses the web-push npm package for VAPID authentication and payload encryption.
 */

import webpush from 'web-push'
import { config } from '../config.js'
import { FcmService } from './fcmService.js'
import { ApnsVoipService } from './apnsVoipService.js'
import { getDbPool } from '../db/pool.js'
import { isSystemNonInboxFolder } from '../imap/folderClassification.js'

const CHAT_APP_ID = 'com.flowone.chat'

export class PushService {
  constructor(redis, clientManager) {
    this.redis = redis
    this.clientManager = clientManager
    this.enabled = false

    // Native FCM transport (iOS/Android). Independent of web-push; both are
    // fanned out from sendPush() so web and mobile never diverge.
    this.fcmService = new FcmService(redis)

    // Native VoIP transport (iOS PushKit -> CallKit). Used ONLY for the Chat
    // app's incoming-call ring so it presents the system full-screen call UI
    // even when the app is killed. No-ops when the .p8 key isn't configured.
    this.apnsVoip = new ApnsVoipService()

    // Initialize web-push with VAPID credentials
    if (config.push?.vapidPublicKey && config.push?.vapidPrivateKey && config.push?.vapidSubject) {
      try {
        webpush.setVapidDetails(
          config.push.vapidSubject,
          config.push.vapidPublicKey,
          config.push.vapidPrivateKey
        )
        this.enabled = true
        console.log('[PushService] Initialized with VAPID credentials')
      } catch (e) {
        console.error('[PushService] Failed to initialize VAPID:', e.message)
      }
    } else {
      console.log('[PushService] VAPID credentials not configured - push notifications disabled')
    }
  }

  /**
   * Check if web-push (VAPID) is enabled
   */
  isEnabled() {
    return this.enabled
  }

  /**
   * Whether any push transport (web-push OR native FCM) is available.
   * Used to decide if it's worth building/sending a notification at all.
   */
  hasAnyTransport() {
    return this.enabled || !!this.fcmService?.configured
  }

  /**
   * Get push subscriptions for a user from Redis
   * PHP stores these at key: webmail:push_subs:{email}
   * 
   * @param {string} userEmail 
   * @returns {Array} Array of { endpoint, keys: { p256dh, auth } }
   */
  async getSubscriptions(userEmail) {
    const email = userEmail.toLowerCase()
    const key = `${config.redis.prefix}push_subs:${email}`

    // Fast path: the derived Redis cache (rebuilt by the PHP backend on every
    // subscribe/unsubscribe).
    try {
      const data = await this.redis.get(key)
      if (data) {
        const parsed = JSON.parse(data)
        const subs = Array.isArray(parsed) ? parsed.filter((s) => s && s.endpoint) : []
        if (subs.length) return subs
      }
    } catch (e) {
      console.error(`[PushService] getSubscriptions(redis) failed for ${userEmail}:`, e.message)
    }

    // Cache miss/cold/evicted: fall back to MySQL (source of truth) and re-warm
    // the cache. Without this, an evicted push_subs key silently kills web push
    // even though the browser is still subscribed — the macOS/Windows regression.
    return this.getSubscriptionsFromDb(email, key)
  }

  /**
   * Read web-push subscriptions straight from push_subscriptions (source of
   * truth) and re-warm the Redis cache. Returns the web-push-compatible shape
   * { endpoint, keys: { p256dh, auth } }. Returns [] when DB is
   * unconfigured/unreachable so the caller degrades to "no subscriptions".
   */
  async getSubscriptionsFromDb(email, redisKey) {
    const pool = getDbPool()
    if (!pool) return []
    try {
      const [rows] = await pool.query(
        'SELECT endpoint, p256dh, auth FROM push_subscriptions WHERE user_email = ?',
        [email]
      )
      const subs = rows
        .filter((r) => r.endpoint && r.p256dh && r.auth)
        .map((r) => ({ endpoint: r.endpoint, keys: { p256dh: r.p256dh, auth: r.auth } }))
      if (subs.length) {
        // Mirror the PHP backend's 30-day TTL; best-effort (never block the send).
        this.redis
          .set(redisKey, JSON.stringify(subs), 'EX', 86400 * 30)
          .catch(() => {})
        console.log(`[PushService] ${email}: push_subs cache cold — rebuilt from MySQL (${subs.length})`)
      }
      return subs
    } catch (e) {
      console.error(`[PushService] getSubscriptions(db) failed for ${email}:`, e.message)
      return []
    }
  }

  /**
   * Remove an invalid subscription from Redis
   * (Called when a push fails with 404/410 - subscription expired)
   */
  async removeSubscription(userEmail, endpoint) {
    try {
      const key = `${config.redis.prefix}push_subs:${userEmail.toLowerCase()}`
      const data = await this.redis.get(key)
      
      if (!data) return
      
      const subs = JSON.parse(data)
      const filtered = subs.filter(s => s.endpoint !== endpoint)
      
      if (filtered.length === 0) {
        await this.redis.del(key)
      } else {
        await this.redis.set(key, JSON.stringify(filtered), 'EX', 86400 * 30)
      }
      
      console.log(`[PushService] Removed expired subscription for ${userEmail}`)
    } catch (e) {
      console.error(`[PushService] Failed to remove subscription:`, e.message)
    }
  }

  /**
   * Stable identity for a notification, used to suppress duplicate device
   * pushes when the same logical message reaches the fan-out more than once
   * (the per-minute incremental tick and the 5-minute warmer both publishing a
   * fresh UID, the IMAP-IDLE path and the cron both firing, or a post-restart
   * event replay). Distinct messages MUST get distinct identities so genuine
   * notifications are never swallowed — so chat keys on the message id, not the
   * conversation, and email keys on folder+uid.
   */
  notificationIdentity(data, notification) {
    const p = data?.payload || {}
    switch (data?.type) {
      case 'MESSAGE_NEW':
        return `email:${p.folder || 'INBOX'}:${p.uid}`
      case 'CHAT_MESSAGE_NEW':
        return `chat:${p.message?.id ?? p.message?.message_id ?? notification.tag}`
      case 'CALL_INCOMING':
      case 'CALL_MISSED':
        return `${data.type}:${p.callId ?? notification.tag}`
      case 'CALENDAR_REMINDER':
        return `cal:${p.event_id ?? notification.tag}:${p.minutes ?? ''}`
      default:
        return notification.tag || `${data?.type}:${notification.title}:${notification.body}`
    }
  }

  /**
   * Claim the right to push this exact notification once. Returns true when we
   * own the send, false when an identical push went out moments ago (dup).
   * Fails OPEN (returns true) on any Redis error — a rare duplicate beats
   * silently dropping a notification.
   */
  async claimPushOnce(userEmail, identity, ttlSeconds = 120) {
    try {
      const key = `${config.redis.prefix}push_dedupe:${userEmail.toLowerCase()}:${identity}`
      // ioredis: SET key val EX ttl NX -> 'OK' when stored, null when key exists.
      const res = await this.redis.set(key, '1', 'EX', ttlSeconds, 'NX')
      return res === 'OK'
    } catch (e) {
      console.error(`[PushService] claimPushOnce failed for ${userEmail}:`, e.message)
      return true
    }
  }

  /**
   * Send push notification to a user.
   * 
   * Strategy:
   * - If user has NO WebSocket clients: send push immediately (they're fully offline)
   * - If user HAS WebSocket clients: send push after a short delay.
   *   A background/suspended tab counts as "connected" but won't actually show
   *   the event to the user. The delayed push ensures mobile devices still get notified.
   *   The service worker will suppress display if the app is in foreground focus.
   * 
   * @param {string} userEmail - Target user email
   * @param {object} data - Event data { type, payload }
   */
  async sendPushIfOffline(userEmail, data) {
    if (!this.hasAnyTransport()) return

    // Publisher explicitly opted this event out of device pushes (e.g. the
    // user's own Sent-folder copy from MessageController). Honored first so it
    // wins for every type/transport, independent of folder-name heuristics or
    // the sender check below. The downstream Sent/self-send guards remain as
    // additional backstops for publishers that don't set the flag.
    if (data?.payload?.no_push) return

    // Don't push chat messages back to the sender — they already know they sent it
    if (data.type === 'CHAT_MESSAGE_NEW') {
      const senderEmail = data?.payload?.message?.sender_email
      if (senderEmail && senderEmail.toLowerCase() === userEmail.toLowerCase()) return
      // Respect per-conversation mute. PHP tags each recipient's payload with
      // their own mute state, so muted users still get the realtime event
      // (message delivery + unread count) but no web/native push notification.
      if (data?.payload?.recipient_muted) return
    }

    // Don't push the user's own outgoing mail back to them, and never push for
    // Sent/Drafts/Junk/Trash. The IDLE + sync paths already do this; the
    // send-time MESSAGE_NEW publish from MessageController does not, so guard it
    // here so every path agrees. The self-send (from == account owner) check is
    // language-agnostic — it also catches localized Sent folders an English-only
    // folder-name list would miss.
    if (data.type === 'MESSAGE_NEW') {
      if (isSystemNonInboxFolder(data?.payload?.folder)) return
      const from = String(this.coerceSender(data?.payload?.from) || '').toLowerCase()
      const self = String(userEmail || '').toLowerCase()
      if (self && from.includes(self)) return
    }

    // Build notification payload based on event type
    const notification = this.buildNotification(data)
    if (!notification) return // Not a pushable event type

    // Suppress duplicate pushes for the same logical message (see
    // notificationIdentity). Claimed once here so BOTH the immediate and the
    // delayed reinforcement send below are covered by a single slot.
    const identity = this.notificationIdentity(data, notification)
    if (!(await this.claimPushOnce(userEmail, identity))) {
      return
    }

    if (!this.clientManager.hasConnectedClients(userEmail)) {
      // User is fully offline - send immediately
      await this.sendPush(userEmail, notification)
    } else {
      // User has WS connections but may be in a background/suspended tab.
      // Send push after a short delay as reinforcement.
      // The service worker checks if the app is focused and suppresses if so.
      setTimeout(async () => {
        try {
          await this.sendPush(userEmail, notification)
        } catch (e) {
          console.error(`[PushService] Delayed push error for ${userEmail}:`, e.message)
        }
      }, 4000)
    }
  }

  /**
   * Map a notification type to its preference key (matches the PHP/Redis map
   * notif_prefs:{email}). Unknown types fall under 'boards' (catch-all).
   */
  prefKeyForType(type) {
    switch (type) {
      case 'email': return 'email'
      case 'chat': return 'chat'
      case 'call':
      case 'missed_call': return 'calls'
      case 'calendar': return 'calendar'
      default: return 'boards'
    }
  }

  /**
   * Whether the user allows push for this notification type. Reads the Redis
   * map notif_prefs:{email} (mirrored from MySQL by the PHP backend). Defaults
   * to allow when unset or on error (fail-open — never silently drop on a glitch).
   */
  async isTypeAllowed(userEmail, type) {
    try {
      const key = `${config.redis.prefix}notif_prefs:${userEmail.toLowerCase()}`
      const data = await this.redis.get(key)
      if (!data) return true
      const prefs = JSON.parse(data)
      const prefKey = this.prefKeyForType(type)
      if (!(prefKey in prefs)) return true
      return prefs[prefKey] !== 0 && prefs[prefKey] !== false
    } catch (e) {
      return true
    }
  }

  /**
   * Coerce an email `from` value of unknown shape into a display string.
   * Accepts a plain string, a {name,email} object, or an array of those.
   * Returns '' when nothing usable is present.
   */
  coerceSender(from) {
    if (!from) return ''
    if (typeof from === 'string') return from
    if (Array.isArray(from)) return this.coerceSender(from[0])
    if (typeof from === 'object') return from.name || from.email || from.address || ''
    return String(from)
  }

  /**
   * Build notification payload from event data
   * Returns null if the event type shouldn't trigger a push
   */
  buildNotification(data) {
    const { type, payload } = data

    switch (type) {
      case 'CHAT_MESSAGE_NEW': {
        const msg = payload?.message
        const convId = payload?.conversation_id
        if (!msg) return null
        
        const senderName = msg.sender_display_name || msg.sender_name || msg.sender_email || 'Someone'
        const content = msg.content || ''
        
        // Call system messages ([call:missed|completed|declined|cancelled:...]) are
        // chat-log artifacts, NOT chat notifications. The call subsystem already
        // owns every call device push (the CALL_INCOMING ring and the CALL_MISSED
        // banner that replaces it), so pushing the system message as well produced
        // duplicate "Missed call" banners. Deliver it over the WebSocket so the
        // conversation updates, but never as its own device push.
        if (msg.content_type === 'call' || /^\[call:/.test(content)) {
          return null
        }
        
        // Build clean preview - don't show raw encoded content
        // Check content_type first, then fallback to content pattern matching
        let preview
        if (msg.content_type === 'voice' || /^\[voice:\d/.test(content)) {
          preview = 'Voice message'
        } else if (msg.content_type === 'image') {
          preview = 'Sent an image'
        } else if (/^\[gif:/.test(content)) {
          preview = 'Sent a GIF'
        } else {
          preview = content ? content.substring(0, 100) : 'New message'
        }
        
        return {
          title: `${senderName}`,
          body: preview,
          type: 'chat',
          tag: `chat-${convId}`,
          conversationId: convId,
          url: `/chat?conversation=${convId}`
        }
      }

      case 'MESSAGE_NEW': {
        // `from` may arrive as a plain string (IDLE path) or, from older PHP
        // publishers, as a [{name,email}] array / {name,email} object. Coerce
        // to a display string so the title never renders "[object Object]".
        const from = this.coerceSender(payload?.from) || 'Unknown sender'
        const subject = payload?.subject || 'No subject'
        const folder = payload?.folder || 'INBOX'
        
        return {
          title: `${from}`,
          body: subject.length > 100 ? subject.substring(0, 100) + '...' : subject,
          type: 'email',
          tag: `email-${payload?.uid || Date.now()}`,
          folder: folder,
          uid: payload?.uid,
          url: `/${folder}`
        }
      }

      case 'CALL_INCOMING': {
        const callerName = payload?.callerName || payload?.callerEmail?.split('@')[0] || 'Someone'
        const callTypeLabel = payload?.callType === 'video' ? 'video' : 'voice'
        
        return {
          title: `${callerName}`,
          body: `Incoming ${callTypeLabel} call`,
          type: 'call',
          tag: `call-${payload?.callId || Date.now()}`,
          conversationId: payload?.conversationId,
          callId: payload?.callId,
          callType: payload?.callType,
          callerEmail: payload?.callerEmail,
          callStartedAt: payload?.callStartedAt || Date.now(),
          url: `/chat?conversation=${payload?.conversationId}`
        }
      }

      case 'CALL_MISSED': {
        const callerName = payload?.callerName || payload?.callerEmail?.split('@')[0] || 'Someone'
        const callTypeLabel = payload?.callType === 'video' ? 'video' : 'voice'
        
        return {
          title: `Missed ${callTypeLabel} call`,
          body: `from ${callerName}`,
          type: 'missed_call',
          // Reuse the CALL_INCOMING ring's tag/collapse id (buildGrouping derives
          // apns-collapse-id / Android collapseKey from the tag). This makes the
          // missed banner REPLACE the ring on the device instead of leaving two
          // notifications stacked for the same call.
          tag: `call-${payload?.callId || Date.now()}`,
          callId: payload?.callId,
          conversationId: payload?.conversationId,
          url: `/chat?conversation=${payload?.conversationId}`
        }
      }

      case 'CALENDAR_REMINDER': {
        const title = payload?.title || 'Event reminder'
        const minutes = payload?.minutes
        let body
        if (minutes === 0 || minutes === '0') {
          body = payload?.all_day ? 'Today' : 'Starting now'
        } else if (minutes !== undefined && minutes !== null) {
          body = `Starts in ${minutes} min`
        } else {
          body = 'Upcoming event'
        }

        return {
          title,
          body,
          type: 'calendar',
          tag: 'calendar',
          url: payload?.event_id ? `/calendar?event=${payload.event_id}` : '/calendar'
        }
      }

      case 'NOTIFICATION_CREATED': {
        const title = payload?.title || 'Notification'
        const message = payload?.message || ''
        const phType = payload?.ph_type || payload?.type || 'notification'

        // Missed-call records are device-pushed by the call subsystem (CALL_MISSED,
        // which also replaces the ring). This PHP-originated NOTIFICATION_CREATED
        // still flows over the WebSocket to populate the in-app notification bell,
        // but must not fire a duplicate device banner.
        if (phType === 'missed_call') return null
        const cardId = payload?.data?.card_id
        const boardId = payload?.data?.board_id

        let url = '/boards'
        if (phType === 'drive_share') {
          // "Someone shared a file/folder with you" → open Drive, not boards.
          url = '/drive'
        } else if (cardId && boardId) {
          url = `/boards/${boardId}?card=${cardId}`
        } else if (cardId) {
          url = `/boards?card=${cardId}`
        }

        return {
          title,
          body: message.length > 120 ? message.substring(0, 120) + '...' : message,
          type: phType,
          tag: `notif-${payload?.notification_id || Date.now()}`,
          url
        }
      }

      default:
        return null // Don't push for other event types
    }
  }

  /**
   * Attach the app-icon badge count to a notification so iOS (aps.badge) and
   * Android (notificationCount) show "N unread" on the icon.
   *
   * The count is seeded from the client's authoritative unread total, which the
   * PWA POSTs to the PHP `/push/badge` endpoint into Redis `badge:{email}`.
   * Each outgoing push atomically increments it (a new email/message/notif = +1
   * unread); when the user reads items the client re-POSTs the lower total,
   * keeping the badge correct. A missing key increments to 1 (sane default).
   *
   * Incoming ringing calls are transient (not a persistent unread item) so they
   * never bump the badge.
   */
  async attachBadge(userEmail, notification) {
    if (notification.type === 'call') return
    try {
      const key = `${config.redis.prefix}badge:${userEmail.toLowerCase()}`
      const count = await this.redis.incr(key)
      // Keep the counter from living forever if the client never resets it.
      await this.redis.expire(key, 86400 * 30)
      if (Number.isFinite(count) && count > 0) notification.badge = count
    } catch (e) {
      // Non-fatal: send without a badge rather than dropping the push.
      console.error(`[PushService] attachBadge failed for ${userEmail}:`, e.message)
    }
  }

  /**
   * Send push notification directly (bypasses offline check)
   * Used by call signaling when we already know the user is offline
   */
  async sendPushDirectly(userEmail, data) {
    if (!this.hasAnyTransport()) return
    
    const notification = this.buildNotification(data)
    if (!notification) return
    
    await this.sendPush(userEmail, notification)
  }

  /**
   * Read a user's VoIP (PushKit) device tokens from the Redis cache
   * `voip_tokens:{email}` (rebuilt by PHP), falling back to MySQL. These are
   * APNs-only iOS tokens for the Chat app, stored as token_kind='voip'.
   * @returns {Promise<Array<{token:string, app_id:string, platform:string}>>}
   */
  async getVoipTokens(userEmail) {
    const email = userEmail.toLowerCase()
    const key = `${config.redis.prefix}voip_tokens:${email}`
    try {
      const data = await this.redis.get(key)
      if (data) {
        const parsed = JSON.parse(data)
        if (Array.isArray(parsed)) {
          const toks = parsed
            .map((e) => (e && e.token ? { token: e.token, app_id: e.app_id || CHAT_APP_ID, platform: e.platform || 'ios' } : null))
            .filter(Boolean)
          if (toks.length) return toks
        }
      }
    } catch (e) {
      console.error(`[PushService] getVoipTokens(redis) failed for ${email}:`, e.message)
    }
    const pool = getDbPool()
    if (!pool) return []
    try {
      const [rows] = await pool.query(
        "SELECT token, app_id, platform FROM native_push_tokens WHERE user_email = ? AND token_kind = 'voip'",
        [email]
      )
      const toks = rows
        .filter((r) => r.token)
        .map((r) => ({ token: r.token, app_id: r.app_id || CHAT_APP_ID, platform: r.platform || 'ios' }))
      if (toks.length) {
        this.redis.set(key, JSON.stringify(toks), 'EX', 86400 * 90).catch(() => {})
      }
      return toks
    } catch (e) {
      console.error(`[PushService] getVoipTokens(db) failed for ${email}:`, e.message)
      return []
    }
  }

  /**
   * Immediately drop a dead VoIP token from the `voip_tokens:{email}` Redis
   * cache after APNs reported it unregistered. We've already enqueued it for the
   * PHP cron, which deletes it from MySQL and authoritatively rebuilds this
   * cache — but that runs on a ~1-minute cadence. This fast path keeps the very
   * next ring/cancel in this session (and any other mailsync instance reading
   * the shared cache) from re-selecting the dead token in the meantime. Safe and
   * idempotent: PHP remains the source of truth.
   */
  async dropVoipTokenFromCache(userEmail, deadToken) {
    const email = userEmail.toLowerCase()
    const key = `${config.redis.prefix}voip_tokens:${email}`
    try {
      const data = await this.redis.get(key)
      if (!data) return
      const parsed = JSON.parse(data)
      if (!Array.isArray(parsed)) return
      const next = parsed.filter((e) => e && e.token && e.token !== deadToken)
      if (next.length === parsed.length) return // token wasn't in the cache
      // Re-set (even when empty) rather than DEL so we preserve the cache's
      // 90-day TTL window; getVoipTokens treats an empty array as a MySQL miss.
      await this.redis.set(key, JSON.stringify(next), 'EX', 86400 * 90)
    } catch (e) {
      console.error(`[PushService] dropVoipTokenFromCache failed for ${email}:`, e.message)
    }
  }

  /**
   * Ring a callee's devices for the native full-screen call UI:
   *   - iOS Chat devices  -> APNs VoIP push  -> CallKit
   *   - Android Chat devices -> data-only FCM -> full-screen-intent Activity
   * Falls back to the legacy alert ring ONLY when no Chat app is installed
   * (chat-in-Pro users), so those users are still notified.
   *
   * Gated by the user's call notification preference. Returns the set of
   * platforms that received a native ring (so callSignaling can suppress the
   * duplicate alert ring on those platforms).
   *
   * @param {string} userEmail
   * @param {object} call { callId, conversationId, callType, callerEmail, callerName, callStartedAt }
   * @returns {Promise<{ ios:boolean, android:boolean, nativeHandled:boolean }>}
   */
  async sendCallInvite(userEmail, call) {
    const result = { ios: false, android: false, nativeHandled: false }
    if (!(await this.isTypeAllowed(userEmail, 'call'))) return result

    // iOS: PushKit VoIP -> CallKit.
    let voipTokens = []
    if (this.apnsVoip?.configured) {
      voipTokens = await this.getVoipTokens(userEmail)
      if (voipTokens.length) {
        const r = await this.apnsVoip.sendIncomingCall(voipTokens.map((t) => t.token), call)
        if (r.sent > 0) result.ios = true
        for (const dead of r.dead) {
          // Durable delete: PHP owns MySQL + rebuilds the caches on the next
          // drain. Fast path: also drop it from the voip cache right now so this
          // session's later cancel/retry — and any other mailsync instance —
          // can't keep ringing a dead token before the cron runs.
          this.fcmService?.enqueuePrune(userEmail, dead)
          this.dropVoipTokenFromCache(userEmail, dead).catch(() => {})
        }
      }
    }

    // Android: data-only high-priority FCM -> full-screen-intent.
    const fcmTokens = await this.fcmService.getTokens(userEmail)
    const androidChat = fcmTokens.filter((t) => t.app_id === CHAT_APP_ID && t.platform === 'android')
    if (androidChat.length) {
      const r = await this.fcmService.sendCallDataAndroid(userEmail, androidChat, call)
      if (r.sent > 0) result.android = true
    }

    result.nativeHandled = result.ios || result.android

    // No Chat app anywhere -> keep the legacy alert ring so chat-in-Pro users
    // (and any web/desktop) are still alerted.
    if (!result.nativeHandled) {
      await this.sendPushDirectly(userEmail, { type: 'CALL_INCOMING', payload: call })
    }
    return result
  }

  /**
   * Tear down the native call UI for a ring that ended before this device
   * answered (caller hung up / timed out / answered elsewhere). The persistent
   * "Missed call" banner is handled separately via the CALL_MISSED event.
   *
   * @param {string} userEmail
   * @param {object} call { callId, conversationId, reason }
   */
  async sendCallCancel(userEmail, call) {
    if (this.apnsVoip?.configured) {
      const voipTokens = await this.getVoipTokens(userEmail)
      if (voipTokens.length) {
        await this.apnsVoip.sendCallEnd(voipTokens.map((t) => t.token), call)
      }
    }
    const fcmTokens = await this.fcmService.getTokens(userEmail)
    const androidChat = fcmTokens.filter((t) => t.app_id === CHAT_APP_ID && t.platform === 'android')
    if (androidChat.length) {
      await this.fcmService.sendCallDataAndroid(userEmail, androidChat, call, { end: true })
    }
  }

  /**
   * Send push notification to all of a user's devices, across both transports:
   *   - Native FCM (iOS/Android Capacitor apps)
   *   - Web Push (browser / installed PWA)
   * The two are independent: a native-only user has no web-push subscription
   * and vice-versa, so FCM runs regardless of web-push availability.
   */
  async sendPush(userEmail, notification) {
    // Honor user push preferences (gates BOTH transports). Fail-open on errors.
    if (!(await this.isTypeAllowed(userEmail, notification.type))) {
      return
    }

    // Attach the iOS/Android app-icon badge count (best-effort).
    await this.attachBadge(userEmail, notification)

    // Native FCM transport (fire-and-forget; handles its own enabled check).
    if (this.fcmService) {
      this.fcmService.send(userEmail, notification).catch(e =>
        console.error(`[PushService] FCM send error for ${userEmail}:`, e.message))
    }

    // Web Push transport (only when VAPID is configured).
    if (!this.enabled) return

    const subscriptions = await this.getSubscriptions(userEmail)
    
    if (subscriptions.length === 0) return

    const payload = JSON.stringify(notification)

    for (const sub of subscriptions) {
      try {
        await webpush.sendNotification(sub, payload)
        console.log(`[PushService] Push sent to ${userEmail} (${sub.endpoint.substring(0, 50)}...)`)
      } catch (e) {
        if (e.statusCode === 404 || e.statusCode === 410) {
          // Subscription expired or invalid - remove it
          console.log(`[PushService] Subscription expired for ${userEmail} (${e.statusCode})`)
          await this.removeSubscription(userEmail, sub.endpoint)
        } else {
          console.error(`[PushService] Push failed for ${userEmail}:`, e.statusCode || e.message)
        }
      }
    }
  }
}

