#!/usr/bin/env node
/**
 * FlowOne Native Push (FCM) Delivery Test — mailsync / Node side.
 *
 * The PHP suite (backend/tests/native-push-test.php) covers token storage,
 * dedupe, Redis sync, prefs and pruning. THIS suite covers the other half:
 * the Node mailsync transport that actually talks to Firebase Cloud Messaging
 * (firebase-admin + the service-account credential + APNs routing). It drives
 * the real production FcmService — not a reimplementation — so a green run
 * means the live delivery path is wired correctly.
 *
 * Test sections (run all by default; restrict with --only=SECTION[,SECTION]):
 *   preflight   node version, firebase-admin present, service-account JSON
 *               valid, Redis reachable, fcm config sane
 *   init        FcmService initialises, authenticates, project id resolves
 *   tokens      getTokens() reads the Redis fcm_tokens:{email} cache
 *   message     buildGrouping()/buildMessage() emit valid FCM payloads
 *   routing     recipientsFor() routes by app_id (chat -> Chat app, etc.)
 *   send        live FCM validation (see safety note below)
 *
 * SAFETY (non-destructive by default):
 *   - No real notification is delivered unless you pass BOTH --token and --send.
 *   - The "auth reachability" check uses a DRY-RUN with a bogus token: it proves
 *     the credential authenticates with FCM without sending anything.
 *   - With --token (no --send) it does a DRY-RUN against your real device token:
 *     validates token + APNs config, still delivers nothing.
 *   - All Redis writes use the flowone_test_fcm@flowone.pro user + a
 *     flowone_test_fcm_ token prefix and are cleaned up even on failure/SIGINT.
 *
 * Run on the server (CLI only):
 *   node /var/www/vps-email/mailsync/server/tests/fcm-delivery-test.js --verbose
 *   node .../fcm-delivery-test.js --token=<device_fcm_token>            # dry-run, real token
 *   node .../fcm-delivery-test.js --token=<device_fcm_token> --send     # actually buzz the phone
 *
 * Exit code: 0 if all PASS/WARN, 1 on any FAIL.
 */

import { existsSync, readFileSync } from 'fs'
import Redis from 'ioredis'

import { NodeTestRunner } from './lib/testRunner.js'
import { config } from '../src/config.js'
import { FcmService } from '../src/push/fcmService.js'
import { PushService } from '../src/push/pushService.js'

const r = new NodeTestRunner('fcm-delivery', process.argv)

const TEST_EMAIL = (r.opts.email || 'flowone_test_fcm@flowone.pro').toLowerCase()
const TEST_TOKEN = 'flowone_test_fcm_token_' + Math.random().toString(36).slice(2, 14)
const DEVICE_TOKEN = r.opts.token || null
const DO_DELIVER = r.flags.has('send')
const REDIS_PREFIX = config.redis?.prefix || 'webmail:'

let redis = null
let fcm = null

// Recognisable sample notifications (same shape PushService.buildNotification emits).
const NOTIFS = {
  email: { title: 'Sender Name', body: '[FLOWONE-TEST] Subject line', type: 'email', tag: 'email-1', folder: 'INBOX', uid: '1', url: '/INBOX' },
  chat: { title: 'Sender Name', body: '[FLOWONE-TEST] Chat message', type: 'chat', tag: 'chat-7', conversationId: '7', url: '/chat?conversation=7' },
  call: { title: 'Caller', body: 'Incoming voice call', type: 'call', tag: 'call-9', conversationId: '7', callId: '9', callType: 'voice' },
}

r.addCleanup(async () => {
  if (!redis) return
  // Only touch Redis if the connection is live; disconnect() is synchronous and
  // forceful (quit() would queue forever on a closed connection -> hang).
  try { if (redis.status === 'ready') await redis.del(`${REDIS_PREFIX}fcm_tokens:${TEST_EMAIL}`) } catch { /* best-effort */ }
  try {
    if (redis.status === 'ready') {
      const dk = await redis.keys(`${REDIS_PREFIX}push_dedupe:${TEST_EMAIL}:*`)
      if (dk.length) await redis.del(...dk)
    }
  } catch { /* best-effort */ }
  try { redis.disconnect() } catch { /* best-effort */ }
})

// --- helpers ---------------------------------------------------------------

function isCredentialError(e) {
  const s = `${e?.code || ''} ${e?.message || ''}`.toLowerCase()
  return s.includes('invalid-credential') || s.includes('invalid_grant') ||
    s.includes('default credentials') || s.includes('unauthenticated') ||
    s.includes('permission_denied') || s.includes('failed to determine project id')
}

function explainSendError(code) {
  switch (code) {
    case 'messaging/registration-token-not-registered':
    case 'messaging/invalid-registration-token':
    case 'messaging/invalid-argument':
      return 'token is stale/invalid — re-open the app to register a fresh one'
    case 'messaging/third-party-auth-error':
    case 'messaging/authentication-error':
      return 'APNs not configured in Firebase — upload the APNs .p8 key (Cloud Messaging → Apple app)'
    case 'messaging/mismatched-credential':
      return 'service account belongs to a different Firebase project than the device token'
    default:
      return code || 'unknown FCM error'
  }
}

// ===========================================================================
// 1. PREFLIGHT
// ===========================================================================
if (r.shouldRunSection('preflight')) {
  r.section('1. PREFLIGHT')

  await r.test('node >= 18', () => {
    const major = parseInt(process.versions.node.split('.')[0], 10)
    r.assertTrue(major >= 18, `node ${process.versions.node} < 18`)
  })

  await r.test('firebase-admin installed', async () => {
    try { await import('firebase-admin') }
    catch { throw new Error('firebase-admin not installed — run `npm install` in the mailsync server dir') }
  })

  await r.test('fcm enabled in config', () => {
    r.assertTrue(config.fcm?.enabled !== false, 'config.fcm.enabled is false (FCM_ENABLED=false)')
  })

  await r.test('service account JSON present + valid', () => {
    const p = config.fcm?.serviceAccountPath
    r.assertTrue(!!p, 'config.fcm.serviceAccountPath is empty')
    r.assertTrue(existsSync(p), `service account not found at ${p}`)
    let sa
    try { sa = JSON.parse(readFileSync(p, 'utf8')) }
    catch (e) { throw new Error(`service account is not valid JSON: ${e.message}`) }
    for (const f of ['project_id', 'client_email', 'private_key']) {
      r.assertTrue(!!sa[f], `service account missing "${f}"`)
    }
    r.log(`          project_id=${sa.project_id} client_email=${sa.client_email}`)
  })

  await r.test('redis reachable', async () => {
    redis = new Redis({
      host: config.redis.host, port: config.redis.port,
      password: config.redis.password || undefined, db: config.redis.database,
      maxRetriesPerRequest: 2, lazyConnect: true,
      // Bounded retries so a down Redis fails fast instead of reconnecting forever.
      retryStrategy: (times) => (times > 2 ? null : 200),
      // Reject commands immediately when not connected instead of queueing them
      // (a queued command on a dead connection would hang the suite).
      enableOfflineQueue: false,
    })
    // Errors are surfaced via connect()/ping() below; swallow the stream events
    // so a down Redis doesn't spam "Unhandled error event".
    redis.on('error', () => {})
    try {
      await redis.connect()
      r.assertEquals('PONG', await redis.ping(), 'redis PING failed')
    } catch (e) {
      try { redis.disconnect() } catch { /* noop */ }
      throw new Error(`redis unreachable at ${config.redis.host}:${config.redis.port} — ${e.message}`)
    }
  })
}

if (r.smoke) process.exit(await r.finish())

// ===========================================================================
// 2. INIT
// ===========================================================================
if (r.shouldRunSection('init')) {
  r.section('2. INIT')

  await r.test('FcmService constructs + configured flag set', () => {
    fcm = new FcmService(redis)
    r.assertTrue(fcm.configured, 'configured=false — service account missing or FCM disabled')
  })

  await r.test('async init authenticates (isEnabled)', async () => {
    await fcm.initPromise
    r.assertTrue(fcm.isEnabled(), 'FcmService.isEnabled() is false — firebase-admin failed to init (see logs)')
    r.assertTrue(!!fcm.messaging, 'messaging client not available after init')
  })
}

// ===========================================================================
// 3. TOKENS (Redis cache read)
// ===========================================================================
if (r.shouldRunSection('tokens')) {
  r.section('3. TOKENS')

  await r.test('getTokens reads the Redis fcm_tokens cache (object shape)', async () => {
    if (!fcm) fcm = new FcmService(redis)
    await redis.set(
      `${REDIS_PREFIX}fcm_tokens:${TEST_EMAIL}`,
      JSON.stringify([{ token: TEST_TOKEN, app_id: 'com.flowone.pro' }]),
      'EX', 120
    )
    const tokens = await fcm.getTokens(TEST_EMAIL)
    r.assertTrue(
      Array.isArray(tokens) && tokens.some((t) => t.token === TEST_TOKEN && t.app_id === 'com.flowone.pro'),
      'seeded {token, app_id} not returned by getTokens'
    )
  })

  await r.test('getTokens normalizes legacy bare-string cache entries', async () => {
    // Pre-routing caches stored a flat array of token strings. They must still
    // resolve (defaulting to the Pro app) so a deploy never drops pushes.
    await redis.set(`${REDIS_PREFIX}fcm_tokens:${TEST_EMAIL}`, JSON.stringify([TEST_TOKEN]), 'EX', 120)
    const tokens = await fcm.getTokens(TEST_EMAIL)
    r.assertTrue(
      tokens.some((t) => t.token === TEST_TOKEN && t.app_id === 'com.flowone.pro'),
      'legacy string not normalized to { token, app_id: com.flowone.pro }'
    )
  })

  await r.test('getTokens returns [] for unknown user', async () => {
    const tokens = await fcm.getTokens('flowone_test_fcm_nobody@flowone.pro')
    r.assertEquals(0, tokens.length, 'expected empty token list')
  })
}

// ===========================================================================
// 4. MESSAGE (payload shaping)
// ===========================================================================
if (r.shouldRunSection('message')) {
  r.section('4. MESSAGE')

  await r.test('buildGrouping maps types to thread/collapse ids', () => {
    if (!fcm) fcm = new FcmService(redis)
    r.assertEquals('email', fcm.buildGrouping(NOTIFS.email).threadId, 'email threadId')
    r.assertEquals('chat-7', fcm.buildGrouping(NOTIFS.chat).threadId, 'chat threadId')
    const call = fcm.buildGrouping(NOTIFS.call)
    r.assertEquals('chat-7', call.threadId, 'call threadId')
    r.assertEquals('call-9', call.collapseId, 'call collapseId')
  })

  await r.test('buildMessage emits valid FCM payload (data all strings)', () => {
    const msg = fcm.buildMessage(NOTIFS.email, [TEST_TOKEN])
    r.assertEquals('Sender Name', msg.notification.title, 'title')
    r.assertEquals('email', msg.apns.payload.aps['thread-id'], 'apns thread-id')
    r.assertEquals('high', msg.android.priority, 'android priority')
    r.assertTrue(msg.tokens.includes(TEST_TOKEN), 'token not in message')
    for (const [k, v] of Object.entries(msg.data)) {
      r.assertTrue(typeof v === 'string', `data.${k} must be a string, got ${typeof v}`)
    }
  })

  await r.test('buildMessage sets apns-collapse-id for calls', () => {
    const msg = fcm.buildMessage(NOTIFS.call, [TEST_TOKEN])
    r.assertEquals('call-9', msg.apns.headers['apns-collapse-id'], 'apns-collapse-id')
    r.assertEquals('call-9', msg.android.collapseKey, 'android collapseKey')
  })

  await r.test('email uses custom sound + Android channel', () => {
    const msg = fcm.buildMessage(NOTIFS.email, [TEST_TOKEN])
    r.assertEquals('new-email.wav', msg.apns.payload.aps.sound, 'iOS aps.sound')
    r.assertEquals('flowone_email', msg.android.notification.channelId, 'android channelId')
    r.assertEquals('new_email', msg.android.notification.sound, 'android sound')
  })

  await r.test('chat uses custom sound + Android channel', () => {
    const msg = fcm.buildMessage(NOTIFS.chat, [TEST_TOKEN])
    r.assertEquals('new-chat.wav', msg.apns.payload.aps.sound, 'iOS aps.sound')
    r.assertEquals('flowone_chat', msg.android.notification.channelId, 'android channelId')
    r.assertEquals('new_chat', msg.android.notification.sound, 'android sound')
  })

  await r.test('unmapped type falls back to default sound', () => {
    const msg = fcm.buildMessage({ ...NOTIFS.call }, [TEST_TOKEN])
    r.assertEquals('default', msg.apns.payload.aps.sound, 'default iOS sound')
    r.assertTrue(msg.android.notification.channelId === undefined, 'no channel for default')
  })

  await r.test('badge forwards to aps.badge + android notificationCount', () => {
    const msg = fcm.buildMessage({ ...NOTIFS.email, badge: 3 }, [TEST_TOKEN])
    r.assertEquals(3, msg.apns.payload.aps.badge, 'aps.badge')
    r.assertEquals(3, msg.android.notification.notificationCount, 'android notificationCount')
  })

  await r.test('no badge field when notification.badge unset', () => {
    const msg = fcm.buildMessage(NOTIFS.email, [TEST_TOKEN])
    r.assertTrue(msg.apns.payload.aps.badge === undefined, 'aps.badge should be absent')
  })

  await r.test('MESSAGE_NEW coerces object/array `from` to a string title', () => {
    // Regression: a structured `from` ([{name,email}] or {name,email}) used to
    // render the device notification title as "[object Object]".
    const push = new PushService(redis, null)
    const cases = [
      ['plain string', 'Jane Doe', 'Jane Doe'],
      ['object', { name: 'Jane Doe', email: 'jane@x.io' }, 'Jane Doe'],
      ['object email-only', { email: 'jane@x.io' }, 'jane@x.io'],
      ['array', [{ name: 'Jane Doe', email: 'jane@x.io' }], 'Jane Doe'],
      ['empty', null, 'Unknown sender'],
    ]
    for (const [label, from, expected] of cases) {
      const n = push.buildNotification({ type: 'MESSAGE_NEW', payload: { from, subject: 'Hi', uid: 1, folder: 'INBOX' } })
      r.assertEquals('string', typeof n.title, `${label}: title must be a string`)
      r.assertTrue(!n.title.includes('[object'), `${label}: title leaked "[object Object]"`)
      r.assertEquals(expected, n.title, `${label}: title`)
    }
  })

  await r.test('duplicate pushes deduped by stable per-message identity', async () => {
    const push = new PushService(redis, null)
    const dataEmail = { type: 'MESSAGE_NEW', payload: { folder: 'INBOX', uid: 4242 } }
    const dataChatA = { type: 'CHAT_MESSAGE_NEW', payload: { message: { id: 'm-1' }, conversation_id: 7 } }
    const dataChatB = { type: 'CHAT_MESSAGE_NEW', payload: { message: { id: 'm-2' }, conversation_id: 7 } }

    // Identity must key on the message, not the conversation/type.
    r.assertEquals('email:INBOX:4242', push.notificationIdentity(dataEmail, push.buildNotification(dataEmail)), 'email identity')
    r.assertEquals('chat:m-1', push.notificationIdentity(dataChatA, push.buildNotification(dataChatA)), 'chat identity keys on message id')

    const idEmail = push.notificationIdentity(dataEmail, push.buildNotification(dataEmail))
    r.assertTrue(await push.claimPushOnce(TEST_EMAIL, idEmail, 30), 'first claim should win')
    r.assertTrue(!(await push.claimPushOnce(TEST_EMAIL, idEmail, 30)), 'duplicate claim must be blocked')

    // A different message in the SAME conversation must still get through.
    const idChatB = push.notificationIdentity(dataChatB, push.buildNotification(dataChatB))
    r.assertTrue(await push.claimPushOnce(TEST_EMAIL, idChatB, 30), 'distinct message must NOT be blocked')
  })
}

// ===========================================================================
// 5. ROUTING (per-app token selection)
// ===========================================================================
if (r.shouldRunSection('routing')) {
  r.section('5. ROUTING')

  const PRO = 'com.flowone.pro'
  const CHAT = 'com.flowone.chat'
  const both = [
    { token: 'flowone_test_fcm_pro', app_id: PRO },
    { token: 'flowone_test_fcm_chat', app_id: CHAT },
  ]
  const tokensOf = (list) => list.map((t) => t.token)

  await r.test('chat/call/missed_call route to the Chat app when installed', () => {
    if (!fcm) fcm = new FcmService(redis)
    for (const type of ['chat', 'call', 'missed_call']) {
      const got = tokensOf(fcm.recipientsFor(both, type))
      r.assertEquals(JSON.stringify(['flowone_test_fcm_chat']), JSON.stringify(got), `${type} -> Chat app only`)
    }
  })

  await r.test('chat falls back to Pro when no Chat app is installed', () => {
    const proOnly = [{ token: 'flowone_test_fcm_pro', app_id: PRO }]
    const got = tokensOf(fcm.recipientsFor(proOnly, 'chat'))
    r.assertEquals(JSON.stringify(['flowone_test_fcm_pro']), JSON.stringify(got), 'chat should fall back to the Pro token')
  })

  await r.test('email/calendar/default never reach the Chat app', () => {
    for (const type of ['email', 'calendar', 'board', undefined]) {
      const got = tokensOf(fcm.recipientsFor(both, type))
      r.assertEquals(JSON.stringify(['flowone_test_fcm_pro']), JSON.stringify(got), `${type} -> Pro app only`)
    }
  })

  await r.test('chat-only device: gets chat, gets no email', () => {
    const chatOnly = [{ token: 'flowone_test_fcm_chat', app_id: CHAT }]
    r.assertEquals(JSON.stringify(['flowone_test_fcm_chat']), JSON.stringify(tokensOf(fcm.recipientsFor(chatOnly, 'chat'))), 'chat-only -> chat delivered')
    r.assertEquals(JSON.stringify([]), JSON.stringify(tokensOf(fcm.recipientsFor(chatOnly, 'email'))), 'chat-only -> email suppressed')
  })
}

// ===========================================================================
// 6. SEND (live FCM — see safety note in the header)
// ===========================================================================
if (r.shouldRunSection('send')) {
  r.section('6. SEND')

  await r.test('FCM auth reachability (dry-run, bogus token)', async () => {
    if (r.skipSend) return 'skip'
    if (!fcm?.isEnabled()) throw new Error('FcmService not enabled; cannot reach FCM')
    const msg = fcm.buildMessage(NOTIFS.email, ['flowone_test_fcm_bogus_token'])
    let resp
    try {
      resp = await fcm.messaging.sendEachForMulticast(msg, /* dryRun */ true)
    } catch (e) {
      if (isCredentialError(e)) throw new Error(`credential rejected by FCM: ${e.message}`)
      throw e
    }
    // A bogus token SHOULD fail per-token; the point is the call itself
    // authenticated and reached FCM. successCount may be 0 — that's fine.
    const code = resp.responses?.[0]?.error?.code
    if (r.verbose) r.log(`          dry-run resp: success=${resp.successCount} failure=${resp.failureCount} code=${code || '-'}`)
    r.assertTrue(resp.failureCount + resp.successCount === 1, 'FCM did not return a per-token result')
  })

  await r.test('device token validation (dry-run)', async () => {
    if (r.skipSend) return 'skip'
    if (!DEVICE_TOKEN) return 'skip'
    const msg = fcm.buildMessage({ ...NOTIFS.email, body: '[FLOWONE-TEST] dry-run validation' }, [DEVICE_TOKEN])
    const resp = await fcm.messaging.sendEachForMulticast(msg, /* dryRun */ true)
    if (resp.successCount !== 1) {
      const code = resp.responses?.[0]?.error?.code
      throw new Error(`dry-run rejected the token: ${explainSendError(code)}`)
    }
  })

  await r.test('[FLOWONE-TEST] live delivery to device', async () => {
    if (r.skipSend) return 'skip'
    if (!DEVICE_TOKEN || !DO_DELIVER) return 'skip' // requires --token AND --send
    // Exercise the exact production path: seed Redis, call FcmService.send().
    await redis.set(`${REDIS_PREFIX}fcm_tokens:${TEST_EMAIL}`, JSON.stringify([DEVICE_TOKEN]), 'EX', 120)
    // badge:3 exercises the icon badge; chat type exercises the new-chat custom sound.
    const notif = { ...NOTIFS.chat, title: '[FLOWONE-TEST]', body: 'FlowOne push is working ✓', badge: 3 }
    // Also do a direct (non-dry) send so we can assert the delivery result.
    const msg = fcm.buildMessage(notif, [DEVICE_TOKEN])
    const resp = await fcm.messaging.sendEachForMulticast(msg, /* dryRun */ false)
    if (resp.successCount !== 1) {
      const code = resp.responses?.[0]?.error?.code
      throw new Error(`delivery failed: ${explainSendError(code)}`)
    }
    await fcm.send(TEST_EMAIL, notif) // production path (reads token from Redis); must not throw
    r.log('          delivered — check the device for the [FLOWONE-TEST] notification')
  })
}

process.exit(await r.finish())
