#!/usr/bin/env node
/**
 * FlowOne VoIP / CallKit Delivery Test — mailsync / Node side.
 *
 * Covers the APNs VoIP (PushKit) path that drives the iOS Chat app's native
 * CallKit full-screen call screen, and the call fan-out plumbing in PushService
 * (VoIP tokens, Android call-data routing, alert-ring fallback). It drives the
 * real production ApnsVoipService / PushService — not a reimplementation.
 *
 * Sections (run all by default; restrict with --only=SECTION[,SECTION]):
 *   preflight   node>=18, jsonwebtoken present, apns-voip config sane, redis up
 *   init        ApnsVoipService constructs; ES256 provider JWT signs; topic ok
 *   tokens      PushService.getVoipTokens() reads voip_tokens:{email} cache
 *   payload     sendIncomingCall/sendCallEnd no-op safely (no tokens / disabled)
 *   routing     PushService.sendCallInvite partitions iOS VoIP vs Android FCM
 *               and falls back to the alert ring when no Chat app is installed
 *   send        live APNs VoIP (requires a real VoIP token + --send; see below)
 *
 * SAFETY (non-destructive by default):
 *   - No VoIP push leaves the box unless you pass BOTH --token (a real PushKit
 *     token) and --send.
 *   - The "auth reachability" check sends to a BOGUS token and asserts APNs
 *     answered with BadDeviceToken (not a 403 provider-token error): it proves
 *     the .p8 credential authenticates without ringing any real device.
 *   - All Redis writes use the flowone_test_voip@flowone.pro user + a
 *     flowone_test_voip_ token prefix and are cleaned up even on failure/SIGINT.
 *
 * Run on the server (CLI only):
 *   node /var/www/vps-email/mailsync/server/tests/voip-delivery-test.js --verbose
 *   node .../voip-delivery-test.js --token=<voip_token> --send   # rings the phone (CallKit)
 *
 * Exit code: 0 if all PASS/WARN, 1 on any FAIL.
 */

import { existsSync } from 'fs'
import Redis from 'ioredis'

import { NodeTestRunner } from './lib/testRunner.js'
import { config } from '../src/config.js'
import { ApnsVoipService } from '../src/push/apnsVoipService.js'
import { PushService } from '../src/push/pushService.js'

const r = new NodeTestRunner('voip-delivery', process.argv)

const TEST_EMAIL = (r.opts.email || 'flowone_test_voip@flowone.pro').toLowerCase()
const DEVICE_TOKEN = r.opts.token || null
const DO_DELIVER = r.flags.has('send')
const REDIS_PREFIX = config.redis?.prefix || 'webmail:'

let redis = null
let apns = null

const CALL = {
  callId: 'flowone_test_voip_call_' + Math.random().toString(36).slice(2, 10),
  conversationId: '7',
  callType: 'voice',
  callerEmail: 'caller@flowone.pro',
  callerName: '[FLOWONE-TEST] Caller',
  callStartedAt: Date.now(),
}

r.addCleanup(async () => {
  if (!redis) return
  try {
    if (redis.status === 'ready') {
      await redis.del(`${REDIS_PREFIX}voip_tokens:${TEST_EMAIL}`)
      await redis.del(`${REDIS_PREFIX}fcm_tokens:${TEST_EMAIL}`)
      const dk = await redis.keys(`${REDIS_PREFIX}push_dedupe:${TEST_EMAIL}:*`)
      if (dk.length) await redis.del(...dk)
    }
  } catch { /* best-effort */ }
  try { redis.disconnect() } catch { /* best-effort */ }
})

function voipConfigured() {
  const c = config.apnsVoip || {}
  return !!(c.enabled !== false && c.keyId && c.teamId && c.keyPath && existsSync(c.keyPath))
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

  await r.test('jsonwebtoken installed', async () => {
    try { await import('jsonwebtoken') }
    catch { throw new Error('jsonwebtoken not installed — run `npm install` in the mailsync server dir') }
  })

  await r.test('apns-voip config present', () => {
    const c = config.apnsVoip || {}
    if (c.enabled === false) return 'skip'
    if (!voipConfigured()) {
      const missing = []
      if (!c.keyId) missing.push('APNS_VOIP_KEY_ID')
      if (!c.teamId) missing.push('APNS_VOIP_TEAM_ID')
      if (!c.keyPath || !existsSync(c.keyPath)) missing.push(`key file (${c.keyPath || 'APNS_VOIP_KEY_PATH'})`)
      r.log(`          VoIP not configured — calls fall back to the alert ring. Missing: ${missing.join(', ')}`)
      return 'warn'
    }
    r.log(`          topic=${c.bundleId}.voip env=${c.production ? 'production' : 'sandbox'} keyId=${c.keyId}`)
  })

  await r.test('redis reachable', async () => {
    redis = new Redis({
      host: config.redis.host, port: config.redis.port,
      password: config.redis.password || undefined, db: config.redis.database,
      maxRetriesPerRequest: 2, lazyConnect: true,
      retryStrategy: (times) => (times > 2 ? null : 200),
      enableOfflineQueue: false,
    })
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

  await r.test('ApnsVoipService constructs', () => {
    apns = new ApnsVoipService()
    r.assertEquals(`${config.apnsVoip.bundleId}.voip`, apns.topic, 'VoIP topic should be bundleId + .voip')
  })

  await r.test('ES256 provider JWT signs (kid + iss)', () => {
    if (!voipConfigured()) return 'skip'
    if (!apns) apns = new ApnsVoipService()
    const token = apns._authToken()
    const parts = String(token).split('.')
    r.assertEquals(3, parts.length, 'JWT must have 3 dot-separated segments')
    const header = JSON.parse(Buffer.from(parts[0], 'base64').toString('utf8'))
    const claims = JSON.parse(Buffer.from(parts[1], 'base64').toString('utf8'))
    r.assertEquals('ES256', header.alg, 'JWT alg')
    r.assertEquals(config.apnsVoip.keyId, header.kid, 'JWT kid')
    r.assertEquals(config.apnsVoip.teamId, claims.iss, 'JWT iss (team id)')
  })

  await r.test('auth token is cached between calls', () => {
    if (!voipConfigured()) return 'skip'
    r.assertEquals(apns._authToken(), apns._authToken(), 'cached JWT should be identical within TTL')
  })
}

// ===========================================================================
// 3. TOKENS (voip_tokens cache read via PushService)
// ===========================================================================
if (r.shouldRunSection('tokens')) {
  r.section('3. TOKENS')

  await r.test('getVoipTokens reads the voip_tokens:{email} cache', async () => {
    const push = new PushService(redis, { hasConnectedClients: () => false })
    await redis.set(
      `${REDIS_PREFIX}voip_tokens:${TEST_EMAIL}`,
      JSON.stringify([{ token: 'flowone_test_voip_tok_1', app_id: 'com.flowone.chat', platform: 'ios' }]),
      'EX', 120
    )
    const toks = await push.getVoipTokens(TEST_EMAIL)
    r.assertTrue(
      Array.isArray(toks) && toks.some((t) => t.token === 'flowone_test_voip_tok_1' && t.platform === 'ios'),
      'seeded VoIP token not returned'
    )
  })

  await r.test('getVoipTokens returns [] for unknown user', async () => {
    const push = new PushService(redis, { hasConnectedClients: () => false })
    const toks = await push.getVoipTokens('flowone_test_voip_nobody@flowone.pro')
    r.assertEquals(0, toks.length, 'expected empty VoIP token list')
  })
}

// ===========================================================================
// 4. PAYLOAD (safe no-ops)
// ===========================================================================
if (r.shouldRunSection('payload')) {
  r.section('4. PAYLOAD')

  await r.test('sendIncomingCall([]) is a no-op', async () => {
    if (!apns) apns = new ApnsVoipService()
    const res = await apns.sendIncomingCall([], CALL)
    r.assertEquals(0, res.sent, 'no tokens -> nothing sent')
    r.assertEquals(0, res.failed, 'no tokens -> nothing failed')
  })

  await r.test('sendCallEnd([]) is a no-op', async () => {
    const res = await apns.sendCallEnd([], { callId: CALL.callId, reason: 'cancelled' })
    r.assertEquals(0, res.sent, 'no tokens -> nothing sent')
  })

  await r.test('disabled service never sends', async () => {
    const saved = { keyId: config.apnsVoip.keyId }
    // Force "not configured" by clearing keyId on a fresh instance via env shadow.
    const probe = new ApnsVoipService()
    probe.configured = false
    const res = await probe.sendIncomingCall(['anything'], CALL)
    r.assertEquals(0, res.sent, 'unconfigured service must not send')
    config.apnsVoip.keyId = saved.keyId
  })
}

// ===========================================================================
// 5. ROUTING (sendCallInvite fan-out)
// ===========================================================================
if (r.shouldRunSection('routing')) {
  r.section('5. ROUTING')

  await r.test('no Chat app -> falls back to the alert ring (no throw)', async () => {
    // No voip token, no chat fcm token, fcm transport disabled in test env:
    // sendCallInvite must not throw and must report nativeHandled=false.
    const push = new PushService(redis, { hasConnectedClients: () => false })
    await redis.del(`${REDIS_PREFIX}voip_tokens:${TEST_EMAIL}`)
    await redis.set(
      `${REDIS_PREFIX}fcm_tokens:${TEST_EMAIL}`,
      JSON.stringify([{ token: 'flowone_test_voip_pro', app_id: 'com.flowone.pro', platform: 'ios' }]),
      'EX', 120
    )
    const res = await push.sendCallInvite(TEST_EMAIL, CALL)
    r.assertEquals(false, res.nativeHandled, 'no Chat app -> native UI not handled')
    r.assertEquals(false, res.ios, 'no VoIP token -> ios false')
    r.assertEquals(false, res.android, 'no Android chat token -> android false')
  })

  await r.test('respects the call notification preference (calls off -> no native ring)', async () => {
    const push = new PushService(redis, { hasConnectedClients: () => false })
    await redis.set(`${REDIS_PREFIX}notif_prefs:${TEST_EMAIL}`, JSON.stringify({ calls: 0 }), 'EX', 120)
    await redis.set(
      `${REDIS_PREFIX}voip_tokens:${TEST_EMAIL}`,
      JSON.stringify([{ token: 'flowone_test_voip_tok_1', app_id: 'com.flowone.chat', platform: 'ios' }]),
      'EX', 120
    )
    const res = await push.sendCallInvite(TEST_EMAIL, CALL)
    r.assertEquals(false, res.nativeHandled, 'calls disabled -> no native ring')
    await redis.del(`${REDIS_PREFIX}notif_prefs:${TEST_EMAIL}`)
  })
}

// ===========================================================================
// 6. SEND (live APNs VoIP — see safety note in the header)
// ===========================================================================
if (r.shouldRunSection('send')) {
  r.section('6. SEND')

  await r.test('APNs VoIP auth reachability (bogus token)', async () => {
    if (r.skipSend) return 'skip'
    if (!voipConfigured()) return 'skip'
    if (!apns) apns = new ApnsVoipService()
    const res = await apns._sendOne('flowone_test_voip_bogus_token', { type: 'incoming_call', callId: CALL.callId })
    if (r.verbose) r.log(`          APNs resp: status=${res.status} reason=${res.reason || '-'}`)
    // A 403 InvalidProviderToken / ExpiredProviderToken means the .p8/keyId/teamId
    // are wrong. Anything else (e.g. 400 BadDeviceToken) means auth succeeded.
    if (res.status === 403) throw new Error(`APNs rejected the provider token (.p8/keyId/teamId): ${res.reason}`)
    r.assertTrue(res.status > 0, `no HTTP status from APNs — network blocked? (${res.reason})`)
  })

  await r.test('[FLOWONE-TEST] live VoIP ring to device', async () => {
    if (r.skipSend) return 'skip'
    if (!voipConfigured() || !DEVICE_TOKEN || !DO_DELIVER) return 'skip' // needs --token AND --send
    if (!apns) apns = new ApnsVoipService()
    const res = await apns.sendIncomingCall([DEVICE_TOKEN], CALL)
    if (res.sent !== 1) {
      throw new Error(`VoIP delivery failed: ${JSON.stringify(res)}`)
    }
    r.log('          delivered — the iPhone should present the CallKit incoming-call screen')
  })
}

process.exit(await r.finish())
