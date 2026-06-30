/**
 * IMAP IDLE Manager
 * 
 * Manages persistent IMAP connections with IDLE support for real-time
 * new mail detection. One connection per active user.
 * 
 * Uses ImapFlow (modern, maintained, Promise-based) instead of node-imap.
 * ImapFlow handles IDLE automatically — no manual NOOP timer needed.
 */

import { ImapFlow } from 'imapflow'
import { config } from '../config.js'
import { EventTypes } from '../events/eventTypes.js'
import { isSystemNonInboxFolder } from './folderClassification.js'

// Redis list key used as a one-way channel from the IDLE worker to
// the PHP sync cron. When a watched folder fires `expunge` we push a
// tombstone payload here; cron/sync-mailbox.php RPOPs the queue at the
// top of every pass and writes durable rows into
// webmail_folder_tombstones so /delta surfaces deletedUids on the next
// poll. The fully-qualified key includes the PHP RedisCacheService
// prefix ('webmail:') because phpredis auto-prefixes and we want both
// sides to read/write the same physical key.
const IDLE_TOMBSTONE_QUEUE = `${config.redis.prefix || 'webmail:'}flowone:idle:tombstones`

export class ImapIdleManager {
  constructor(eventStore, clientManager, publishEventFn, redisClient = null, pushService = null) {
    this.eventStore = eventStore
    this.clientManager = clientManager
    this.publishEvent = publishEventFn
    // Optional Redis client - if provided, expunge events also enqueue
    // a tombstone payload for the PHP sync cron to drain. If null, the
    // worker still broadcasts to connected clients but the DB mirror
    // will catch up only on the next sync-mailbox.php polling pass.
    this.redis = redisClient
    // Optional push service. New mail is detected here (not via Redis
    // pub/sub), so without this no email push would ever be sent.
    this.pushService = pushService
    
    this.connections = new Map()
    
    this.reconnectAttempts = new Map()
  }

  /**
   * Start IDLE monitoring for a user
   * @param {string} userEmail 
   * @param {string} password - User's password or OAuth token
   * @param {string} folder - Folder to monitor (default: INBOX)
   * @param {object} options - Additional options (oauth, host override, etc.)
   */
  async startIdle(userEmail, password, folder = 'INBOX', options = {}) {
    // Check if already connected
    if (this.connections.has(userEmail)) {
      console.log(`[ImapIdle] Already monitoring ${userEmail}`)
      return
    }

    // Check connection limit
    if (this.connections.size >= config.performance.maxIdleConnections) {
      console.warn(`[ImapIdle] Max connections reached (${config.performance.maxIdleConnections})`)
      return
    }

    try {
      const connection = await this.createConnection(userEmail, password, folder, options)
      this.connections.set(userEmail, connection)
      this.reconnectAttempts.set(userEmail, 0)
      
      console.log(`[ImapIdle] Started monitoring ${folder} for ${userEmail}`)
    } catch (error) {
      console.error(`[ImapIdle] Failed to start IDLE for ${userEmail}:`, error.message)
      throw error
    }
  }

  /**
   * Create an IMAP connection with IDLE support using ImapFlow
   */
  async createConnection(userEmail, password, folder, options) {
    const imapHost = options.host || config.imap.host
    const imapPort = options.port || config.imap.port

    // Build auth config
    const auth = options.oauth
      ? { user: userEmail, accessToken: options.oauth }
      : { user: userEmail, pass: password }

    const client = new ImapFlow({
      host: imapHost,
      port: imapPort,
      secure: config.imap.tls,
      auth,
      tls: {
        rejectUnauthorized: config.imap.tlsOptions?.rejectUnauthorized ?? false,
      },
      // Suppress ImapFlow's built-in logging (we do our own)
      logger: false,
      // IDLE is handled automatically by ImapFlow
      // It re-enters IDLE after every server notification
      //
      // QRESYNC enables VANISHED responses so expunge events arrive
      // with `uid` instead of `seq`. Without this, the frontend cannot
      // map an expunged-by-another-device deletion back to the message
      // it has cached -- and external deletions silently fail to
      // propagate. Servers that don't support QRESYNC (rare) gracefully
      // fall back to seq-based events.
      qresync: true,
    })

    // Track state
    const state = {
      userEmail,
      folder,
      password,
      options,
      uidnext: 0,
    }

    // Handle connection errors
    client.on('error', (err) => {
      console.error(`[ImapIdle] Error for ${userEmail}:`, err.message)
      this.handleDisconnect(userEmail, err)
    })

    // Handle connection close
    client.on('close', () => {
      console.log(`[ImapIdle] Connection closed for ${userEmail}`)
      this.handleDisconnect(userEmail)
    })

    // Handle new messages (exists event fires when message count changes)
    client.on('exists', async (data) => {
      // data = { path, count, prevCount }
      if (data.count > (data.prevCount ?? 0)) {
        const numNew = data.count - (data.prevCount ?? 0)
        console.log(`[ImapIdle] ${numNew} new message(s) for ${userEmail}`)
        await this.handleNewMail(client, state, numNew)
      }
    })

    // Handle message expunge (deletion).
    // With QRESYNC enabled above, data = { path, uid, vanished: true, ... }
    // for external deletions. Without QRESYNC (or for legacy EXPUNGE
    // notifications on the IDLE channel) data = { path, seq, vanished: false }.
    // We forward both shapes; the frontend handler treats `uid` as the
    // authoritative identifier and falls back to a sync probe when only
    // `seq` is available (because seqno values are mutable as other
    // messages get expunged from the same mailbox).
    client.on('expunge', async (data) => {
      const identifier = data.uid != null ? `uid ${data.uid}` : `seq ${data.seq}`
      console.log(`[ImapIdle] Message expunged (${identifier}, vanished=${!!data.vanished}) for ${userEmail}`)
      await this.handleExpunge(state, { seqno: data.seq, uid: data.uid, vanished: !!data.vanished })
    })

    // Handle flags changed. ImapFlow's `flags` event includes uid when
    // the server returned it (RFC 4551 servers always do), so we forward
    // it for the frontend's per-UID flag-state reconciliation. Falls
    // back to seq alone on the rare servers that don't include UID.
    client.on('flags', async (data) => {
      const identifier = data.uid != null ? `uid ${data.uid}` : `seq ${data.seq}`
      console.log(`[ImapIdle] Flags updated (${identifier}) for ${userEmail}`)
      await this.handleUpdate(state, { seqno: data.seq, uid: data.uid, flags: [...(data.flags || [])], modseq: data.modseq })
    })

    // Connect
    await client.connect()
    console.log(`[ImapIdle] Connected for ${userEmail}`)

    // Open mailbox and acquire lock (keeps the mailbox selected for IDLE)
    const lock = await client.getMailboxLock(folder)

    // Store initial uidnext from the selected mailbox
    state.uidnext = client.mailbox?.uidNext || 0

    return { client, lock, state }
  }

  /**
   * Handle new mail notification
   */
  async handleNewMail(client, state, numNewMsgs) {
    try {
      // Get updated mailbox status
      const status = await client.status(state.folder, {
        messages: true,
        unseen: true,
        uidNext: true,
      })

      const newUidnext = status.uidNext || 0

      if (newUidnext > state.uidnext) {
        // Fetch new messages by UID range
        const range = `${state.uidnext}:*`
        const messages = await this.fetchMessages(client, range)

        // Create events for each new message
        for (const msg of messages) {
          const payload = {
            folder: state.folder,
            uid: msg.uid,
            from: msg.from,
            subject: msg.subject,
            date: msg.date,
            preview: msg.preview,
          }

          const event = await this.eventStore.createEvent(
            EventTypes.MESSAGE_NEW,
            state.userEmail,
            payload
          )

          // Broadcast to connected clients (low-latency in-app update)
          await this.clientManager.broadcastToUser(state.userEmail, event)

          // Fan out a device push (FCM + Web Push). IMAP IDLE bypasses the
          // Redis pub/sub path that normally calls sendPushIfOffline, so we
          // must trigger it here or new mail never produces a notification on
          // iOS/Android/desktop. Skip messages the user sent to themselves and
          // any system folder (Sent/Drafts/Junk/Trash) — only received mail
          // notifies.
          if (this.pushService && !isSystemNonInboxFolder(state.folder)) {
            const fromAddr = String(msg.from || '').toLowerCase()
            const selfEmail = String(state.userEmail || '').toLowerCase()
            const isFromSelf = !!(selfEmail && fromAddr.includes(selfEmail))
            if (!isFromSelf) {
              this.pushService
                .sendPushIfOffline(state.userEmail, { type: EventTypes.MESSAGE_NEW, payload })
                .catch((e) => console.error(`[ImapIdle] push error for ${state.userEmail}:`, e.message))
            }
          }
        }

        // Update uidnext
        state.uidnext = newUidnext
      }

      // Send folder count update
      const countEvent = await this.eventStore.createEvent(
        EventTypes.FOLDER_COUNTS,
        state.userEmail,
        {
          folder: state.folder,
          total: status.messages,
          unread: status.unseen,
          uidnext: status.uidNext,
        }
      )
      await this.clientManager.broadcastToUser(state.userEmail, countEvent)

    } catch (error) {
      console.error(`[ImapIdle] Error handling new mail:`, error)
    }
  }

  /**
   * Handle message expunge (deletion).
   *
   * Carries `uid` when QRESYNC is active (preferred), or falls back to
   * `seqno` when the server returned a plain EXPUNGE notification.
   * Frontend treats uid as authoritative; on seqno-only events it
   * triggers a folder sync probe rather than removing a specific row
   * (because seqnos shift as siblings get expunged).
   */
  async handleExpunge(state, { seqno = null, uid = null, vanished = false } = {}) {
    const event = await this.eventStore.createEvent(
      EventTypes.MESSAGE_DELETED,
      state.userEmail,
      {
        folder: state.folder,
        uid,
        seqno,
        vanished,
        permanent: true,
      }
    )

    await this.clientManager.broadcastToUser(state.userEmail, event)

    // Hand the PHP sync cron a tombstone so the DB mirror catches up
    // immediately instead of waiting for the next polling pass. Without
    // this, /delta returns deletedUids=[] for IDLE-detected deletions
    // and the client only learns about them via a periodic STATUS
    // reconcile - too slow for a Gmail-like feel.
    //
    // Skip when uid is null (legacy EXPUNGE without QRESYNC); the
    // polling pass will catch those.
    if (this.redis && uid != null) {
      try {
        const payload = JSON.stringify({
          user: state.userEmail,
          folder: state.folder,
          uid: Number(uid),
          ts: Date.now(),
          source: vanished ? 'qresync_vanished' : 'idle_expunge',
        })
        // LPUSH + cron RPOPs from the right, giving FIFO semantics.
        // LTRIM caps the queue at 10k entries so a misbehaving IDLE
        // connection can never blow up Redis memory.
        await this.redis.lpush(IDLE_TOMBSTONE_QUEUE, payload)
        await this.redis.ltrim(IDLE_TOMBSTONE_QUEUE, 0, 9999)
      } catch (e) {
        console.error(`[ImapIdle] tombstone enqueue failed for ${state.userEmail}:`, e.message)
      }
    }
  }

  /**
   * Handle message update (flags changed). Carries uid when available;
   * frontend matches on uid first and only falls back to seqno when the
   * server cannot supply it.
   */
  async handleUpdate(state, { seqno = null, uid = null, flags = [], modseq = null } = {}) {
    if (!flags) return
    const event = await this.eventStore.createEvent(
      EventTypes.FLAGS_CHANGED,
      state.userEmail,
      {
        folder: state.folder,
        uid,
        seqno,
        flags,
        modseq,
      }
    )

    await this.clientManager.broadcastToUser(state.userEmail, event)
  }

  /**
   * Handle disconnection with automatic reconnect
   */
  async handleDisconnect(userEmail, error = null) {
    const connection = this.connections.get(userEmail)
    if (!connection) return

    // Release mailbox lock if held
    try {
      connection.lock?.release()
    } catch (e) {
      // Ignore — lock may already be released
    }

    // Clean up
    this.connections.delete(userEmail)

    // Attempt reconnection if we have clients still connected
    if (this.clientManager.hasConnectedClients(userEmail)) {
      const attempts = this.reconnectAttempts.get(userEmail) || 0

      if (attempts < config.imap.maxReconnectAttempts) {
        this.reconnectAttempts.set(userEmail, attempts + 1)

        const delay = config.imap.reconnectDelay * Math.pow(2, attempts) // Exponential backoff
        console.log(`[ImapIdle] Reconnecting ${userEmail} in ${delay}ms (attempt ${attempts + 1})`)

        setTimeout(async () => {
          try {
            await this.startIdle(
              userEmail,
              connection.state.password,
              connection.state.folder,
              connection.state.options
            )
          } catch (e) {
            console.error(`[ImapIdle] Reconnection failed for ${userEmail}:`, e.message)
          }
        }, delay)
      } else {
        console.error(`[ImapIdle] Max reconnection attempts reached for ${userEmail}`)
        // Notify clients
        const event = await this.eventStore.createEvent(
          EventTypes.ERROR,
          userEmail,
          { code: 'IMAP_DISCONNECTED', message: 'IMAP connection lost' }
        )
        await this.clientManager.broadcastToUser(userEmail, event)
      }
    }
  }

  /**
   * Stop IDLE monitoring for a user
   */
  async stopIdle(userEmail) {
    const connection = this.connections.get(userEmail)
    if (!connection) return

    // Release mailbox lock
    try {
      connection.lock?.release()
    } catch (e) {
      // Ignore
    }

    // Logout and close connection
    try {
      await connection.client.logout()
    } catch (e) {
      // Ignore — connection may already be closed
    }

    this.connections.delete(userEmail)
    this.reconnectAttempts.delete(userEmail)

    console.log(`[ImapIdle] Stopped monitoring for ${userEmail}`)
  }

  /**
   * Helper: Fetch messages by UID range
   * Uses ImapFlow's async iterable fetch API
   */
  async fetchMessages(client, range) {
    const messages = []

    try {
      for await (const msg of client.fetch(range, {
        uid: true,
        envelope: true,
        flags: true,
      })) {
        messages.push({
          uid: msg.uid,
          flags: msg.flags ? [...msg.flags] : [],
          from: msg.envelope?.from?.[0]
            ? `${msg.envelope.from[0].name || ''} <${msg.envelope.from[0].address || ''}>`
            : '',
          subject: msg.envelope?.subject || '',
          date: msg.envelope?.date?.toISOString() || '',
          seqno: msg.seq,
        })
      }
    } catch (error) {
      console.error(`[ImapIdle] Error fetching messages:`, error.message)
    }

    return messages
  }

  /**
   * Get statistics
   */
  getStats() {
    return {
      activeConnections: this.connections.size,
      maxConnections: config.performance.maxIdleConnections,
      users: Array.from(this.connections.keys()),
    }
  }

  /**
   * Shutdown all connections
   */
  async shutdown() {
    for (const [userEmail, connection] of this.connections) {
      try {
        connection.lock?.release()
        await connection.client.logout()
      } catch (e) {
        // Ignore
      }
    }
    this.connections.clear()
    this.reconnectAttempts.clear()
    console.log('[ImapIdle] All connections closed')
  }
}
