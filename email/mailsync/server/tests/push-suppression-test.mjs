#!/usr/bin/env node
/**
 * push-suppression-test.mjs
 *
 * Verifies the MESSAGE_NEW push guard in pushService.sendPushIfOffline():
 *   - the user's OWN sent mail never raises a push (self-send),
 *   - Sent/Drafts/Junk/Trash folders never raise a push, and
 *   - an explicit no_push flag on the payload always suppresses the push
 *     (locale/sender-agnostic — covers localized Sent folders + secondary
 *     accounts the folder-name/self-send heuristics would miss),
 * while genuine INBOX mail from another sender still does.
 *
 * Also verifies call-notification de-duplication (one missed call must yield a
 * single device banner, not four):
 *   - call system chat messages ([call:...]) raise no push (WS-only),
 *   - PHP-originated missed_call NOTIFICATION_CREATED raises no push (WS-only),
 *   - normal chat / other notification types still push, and
 *   - CALL_MISSED reuses the CALL_INCOMING ring's collapse tag so the missed
 *     banner replaces the ring instead of stacking.
 *
 * Pure in-process test: PushService is built with stub redis + clientManager
 * and its sendPush() is replaced with a spy, so nothing touches the network,
 * Firebase, the database, or any production data. Idempotent and safe to run
 * repeatedly.
 *
 * Run:
 *   node email/mailsync/server/tests/push-suppression-test.mjs [--verbose]
 *
 * Exit code: 0 on all pass, 1 on any failure.
 */

import { PushService } from '../src/push/pushService.js'

const args = process.argv.slice(2)
const VERBOSE = args.includes('--verbose')

if (args.includes('-h') || args.includes('--help')) {
  console.log(`push-suppression-test.mjs

Asserts the MESSAGE_NEW push guard: own sent mail and Sent/Drafts/Junk/Trash
are suppressed; INBOX mail from another sender still pushes.

Options:
  --verbose   Print every notification the spy captured
  -h, --help  Show this help

Exit 0 = all pass, 1 = any failure.`)
  process.exit(0)
}

const USER = 'me@flowone.pro'

/** Build a PushService wired to harmless stubs, with sendPush() spied. */
function makeService() {
  const sends = []
  const redis = {
    // claimPushOnce: ioredis SET ... NX returns 'OK' when newly stored.
    set: async () => 'OK',
    get: async () => null,
  }
  const clientManager = {
    // Fully offline -> sendPushIfOffline takes the immediate (awaited) path.
    hasConnectedClients: () => false,
  }
  const svc = new PushService(redis, clientManager)
  // Force a transport so hasAnyTransport() passes without VAPID/FCM config.
  svc.enabled = true
  // Spy: capture instead of fanning out to web-push / FCM.
  svc.sendPush = async (userEmail, notification) => {
    sends.push({ userEmail, notification })
  }
  return { svc, sends }
}

const results = []
function record(name, pass, detail) {
  results.push({ name, pass, detail })
  const tag = pass ? '\x1b[32mPASS\x1b[0m' : '\x1b[31mFAIL\x1b[0m'
  console.log(`[${tag}] ${name}${detail ? ` - ${detail}` : ''}`)
}

async function expectSends(name, data, expected) {
  const { svc, sends } = makeService()
  await svc.sendPushIfOffline(USER, data)
  if (VERBOSE) {
    console.log(`    ${name}: captured ${sends.length} send(s)`, sends.map((s) => s.notification?.title))
  }
  record(name, sends.length === expected, `expected ${expected}, got ${sends.length}`)
}

function messageNew(payload) {
  return { type: 'MESSAGE_NEW', payload }
}

function chatMessageNew(message, conversationId) {
  return { type: 'CHAT_MESSAGE_NEW', payload: { message, conversation_id: conversationId } }
}

/** Run sendPushIfOffline once and return the spy's captured sends. */
async function captureOne(data) {
  const { svc, sends } = makeService()
  await svc.sendPushIfOffline(USER, data)
  return sends
}

async function main() {
  console.log('--- 1. EMAIL PUSH SUPPRESSION ---')

  // Own sent mail (the reported bug): from == account owner, in Sent.
  await expectSends(
    'own sent mail in Sent -> no push',
    messageNew({ folder: 'Sent', uid: 101, from: USER, subject: 'Re: project' }),
    0
  )

  // Sent folder, even if from somehow differs, is a non-inbox system folder.
  await expectSends(
    'Sent folder from other -> no push',
    messageNew({ folder: 'Sent', uid: 102, from: 'someone@else.com', subject: 'x' }),
    0
  )

  // Drafts / Junk / Trash are likewise suppressed.
  await expectSends(
    'Drafts folder -> no push',
    messageNew({ folder: 'Drafts', uid: 103, from: 'someone@else.com', subject: 'x' }),
    0
  )

  // Genuine received mail still notifies.
  await expectSends(
    'INBOX from another sender -> push',
    messageNew({ folder: 'INBOX', uid: 104, from: 'someone@else.com', subject: 'Hello' }),
    1
  )

  // Self-send is language-agnostic: even an INBOX-classified or localized folder
  // is suppressed when the sender is the account owner.
  await expectSends(
    'self-send in INBOX -> no push',
    messageNew({ folder: 'INBOX', uid: 105, from: USER, subject: 'note to self' }),
    0
  )

  await expectSends(
    'localized Sent (Elkuldott) from self -> no push',
    messageNew({ folder: 'Elkuldott', uid: 106, from: USER, subject: 'x' }),
    0
  )

  // "Name <email>" form still matches the self substring check.
  await expectSends(
    'self-send as "Name <email>" -> no push',
    messageNew({ folder: 'INBOX', uid: 107, from: `Me <${USER}>`, subject: 'x' }),
    0
  )

  // Explicit publisher opt-out (MessageController tags its own Sent-folder
  // copy with no_push). This must win regardless of folder name or sender, so
  // even an INBOX-classified folder from another sender is suppressed.
  await expectSends(
    'no_push flag in INBOX from other -> no push',
    messageNew({ folder: 'INBOX', uid: 108, from: 'someone@else.com', subject: 'x', no_push: true }),
    0
  )

  // The real-world send case: a localized Sent folder + a linked secondary
  // account whose address differs from the primary user (so neither the
  // folder-name heuristic nor the self-send check would fire) — the explicit
  // flag still suppresses it.
  await expectSends(
    'no_push: localized Sent + secondary sender -> no push',
    messageNew({ folder: 'Postausgang', uid: 109, from: 'work@gmail.com', subject: 'x', no_push: true }),
    0
  )

  console.log('')
  console.log('--- 2. CALL NOTIFICATION DE-DUPLICATION ---')

  // A single missed call previously produced FOUR device banners (ring +
  // CALL_MISSED + a [call:missed] chat system message + a PHP missed_call
  // NOTIFICATION_CREATED). The call subsystem (CALL_INCOMING/CALL_MISSED) now
  // owns every call device push; the other two are in-app (WebSocket) only.

  // Call system chat messages are chat-log artifacts -> no push (still WS).
  await expectSends(
    'call system message ([call:missed]) -> no push',
    chatMessageNew(
      { id: 1, sender_email: 'caller@flowone.pro', content: '[call:missed:voice:caller@flowone.pro]', content_type: 'call' },
      7
    ),
    0
  )
  await expectSends(
    'call system message by content pattern -> no push',
    chatMessageNew(
      { id: 2, sender_email: 'caller@flowone.pro', content: '[call:declined:video:r@x.com:caller@flowone.pro]' },
      7
    ),
    0
  )

  // A normal chat message must still push (regression guard for the above).
  await expectSends(
    'normal chat message -> push',
    chatMessageNew({ id: 3, sender_email: 'caller@flowone.pro', content: 'hey there' }, 7),
    1
  )

  // PHP-originated missed-call record: in-app bell only, no duplicate banner.
  await expectSends(
    'NOTIFICATION_CREATED missed_call -> no push',
    { type: 'NOTIFICATION_CREATED', payload: { notification_id: 9, type: 'missed_call', title: 'Missed Call', message: 'You missed a call from games' } },
    0
  )

  // Other NOTIFICATION_CREATED types still push (regression guard).
  await expectSends(
    'NOTIFICATION_CREATED board invite -> push',
    { type: 'NOTIFICATION_CREATED', payload: { notification_id: 10, type: 'board_invite', title: 'Board', message: 'You were invited' } },
    1
  )

  // The missed banner must reuse the ring's collapse id (tag) so it REPLACES
  // the ringing notification on the device instead of stacking a second one.
  {
    const callId = 'call_abc'
    const incoming = await captureOne({ type: 'CALL_INCOMING', payload: { callId, conversationId: 7, callType: 'voice', callerName: 'games' } })
    const missed = await captureOne({ type: 'CALL_MISSED', payload: { callId, conversationId: 7, callType: 'voice', callerName: 'games' } })
    const incTag = incoming[0]?.notification?.tag
    const missTag = missed[0]?.notification?.tag
    if (VERBOSE) console.log(`    collapse tags: ring=${incTag} missed=${missTag}`)
    record(
      'missed call reuses ring collapse tag',
      !!incTag && incTag === missTag,
      `ring=${incTag} missed=${missTag}`
    )
  }

  const failed = results.filter((r) => !r.pass)
  console.log('')
  console.log(`Summary: ${results.length - failed.length}/${results.length} passed`)
  if (failed.length) {
    console.log('Failed:')
    for (const f of failed) console.log(`  - ${f.name} (${f.detail})`)
    process.exit(1)
  }
  process.exit(0)
}

main().catch((e) => {
  console.error('Test harness error:', e)
  process.exit(1)
})
