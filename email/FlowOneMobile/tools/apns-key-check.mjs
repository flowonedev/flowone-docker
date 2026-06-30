#!/usr/bin/env node
/**
 * APNs Auth Key (.p8) validator — talks to Apple directly, bypassing Firebase.
 *
 * Why this exists: when FCM returns `messaging/third-party-auth-error` the
 * failure is on Apple's side — Apple rejected the APNs provider credential
 * Firebase presented. This script reproduces that exact authentication using
 * the raw .p8 + Key ID + Team ID so we can tell a BAD KEY apart from a bad
 * device token / Firebase mis-config:
 *
 *   403 InvalidProviderToken / MissingProviderToken / ExpiredProviderToken
 *        -> the KEY / Key ID / Team ID combo is wrong (this is the FCM
 *           third-party-auth-error cause). Re-issue or re-upload the key.
 *   400 BadDeviceToken / 400 MissingTopic / 410 Unregistered
 *        -> the KEY AUTHENTICATED FINE. The credential is good; any remaining
 *           problem is the device token or the Firebase upload, not the key.
 *   403 TopicDisallowed
 *        -> the key is valid but not for this bundle id (--topic).
 *
 * It NEVER delivers a real push: by default it targets a random bogus device
 * token, so APNs fails at the token step *after* proving auth — exactly what we
 * want. Pass a real token with --token only if you explicitly want a live send.
 *
 * Usage (runs on the Mac where the .p8 lives — needs outbound 443 to Apple):
 *   node email/FlowOneMobile/tools/apns-key-check.mjs \
 *     --key=AuthKey_GNRP5363SP.p8 --key-id=GNRP5363SP --team-id=9CWY396X76 \
 *     --topic=com.flowone.pro
 *
 * Flags:
 *   --key=PATH        Path to the .p8 auth key (required)
 *   --key-id=ID       10-char APNs Key ID (required)
 *   --team-id=ID      10-char Apple Team ID (required)
 *   --topic=BUNDLE    App bundle id / apns-topic (required)
 *   --token=HEX       Device token (default: random bogus token — non-delivering)
 *   --env=both|sandbox|prod   Which APNs host(s) to probe (default: both)
 *   --verbose         Print the signed JWT header/payload
 *   --help            This banner
 *
 * Exit: 0 if the key authenticated on at least one environment, 1 otherwise.
 */

import { readFileSync } from 'fs'
import { connect } from 'http2'
import crypto from 'crypto'

const HOSTS = { sandbox: 'api.sandbox.push.apple.com', prod: 'api.push.apple.com' }

function parseArgs(argv) {
  const args = {}
  for (const a of argv.slice(2)) {
    if (a === '--help' || a === '-h') args.help = true
    else if (a === '--verbose') args.verbose = true
    else if (a.startsWith('--')) { const [k, ...v] = a.slice(2).split('='); args[k] = v.length ? v.join('=') : true }
  }
  return args
}

function b64url(buf) {
  return Buffer.from(buf).toString('base64').replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '')
}

function makeJwt(keyPem, keyId, teamId) {
  const header = { alg: 'ES256', kid: keyId }
  const payload = { iss: teamId, iat: Math.floor(Date.now() / 1000) }
  const signingInput = `${b64url(JSON.stringify(header))}.${b64url(JSON.stringify(payload))}`
  // APNs requires the ES256 signature in JOSE (r||s, 64-byte) form, not DER.
  const sig = crypto.sign('SHA256', Buffer.from(signingInput), { key: keyPem, dsaEncoding: 'ieee-p1363' })
  return { jwt: `${signingInput}.${b64url(sig)}`, header, payload }
}

function probe(host, jwt, topic, token) {
  return new Promise((resolve) => {
    const client = connect(`https://${host}`)
    client.on('error', (e) => resolve({ host, error: e.message }))
    const req = client.request({
      ':method': 'POST',
      ':path': `/3/device/${token}`,
      authorization: `bearer ${jwt}`,
      'apns-topic': topic,
      'apns-push-type': 'alert',
      'apns-priority': '10',
    })
    let status = 0
    let body = ''
    req.on('response', (h) => { status = h[':status'] })
    req.on('data', (c) => { body += c })
    req.on('end', () => { client.close(); resolve({ host, status, body }) })
    req.on('error', (e) => { client.close(); resolve({ host, error: e.message }) })
    req.write(JSON.stringify({ aps: { alert: { title: 'APNs key check', body: 'diagnostic — ignore' } } }))
    req.end()
  })
}

function verdict(status, reason) {
  if (status === 400 && /BadDeviceToken|DeviceTokenNotForTopic|MissingDeviceToken/.test(reason)) {
    return { ok: true, msg: 'KEY AUTHENTICATED OK (rejected only the bogus device token) ✅' }
  }
  if (status === 410) return { ok: true, msg: 'KEY AUTHENTICATED OK (token Unregistered) ✅' }
  if (status === 200) return { ok: true, msg: 'Delivered (you passed a real --token) ✅' }
  if (status === 403 && /TopicDisallowed/.test(reason)) {
    return { ok: false, msg: 'Key valid but NOT for this bundle id — check --topic ❌' }
  }
  if (status === 403) return { ok: false, msg: `KEY REJECTED BY APPLE (${reason}) — this is the third-party-auth-error cause ❌` }
  if (status === 400 && /MissingTopic/.test(reason)) return { ok: true, msg: 'KEY AUTHENTICATED OK (topic missing) ✅' }
  return { ok: false, msg: `Unexpected APNs response (${status} ${reason}) ❌` }
}

async function main() {
  const args = parseArgs(process.argv)
  if (args.help || !args.key || !args['key-id'] || !args['team-id'] || !args.topic) {
    process.stdout.write(`apns-key-check — validate an APNs .p8 against Apple directly\n\n` +
      `  --key=PATH --key-id=ID --team-id=ID --topic=BUNDLE [--token=HEX] [--env=both|sandbox|prod] [--verbose]\n\n` +
      `Example:\n  node email/FlowOneMobile/tools/apns-key-check.mjs --key=AuthKey_GNRP5363SP.p8 \\\n` +
      `    --key-id=GNRP5363SP --team-id=9CWY396X76 --topic=com.flowone.pro\n`)
    process.exit(args.help ? 0 : 1)
  }

  let keyPem
  try {
    keyPem = readFileSync(args.key, 'utf8')
    if (!keyPem.includes('BEGIN PRIVATE KEY')) {
      throw new Error('not a PEM private key (corrupt/compressed?) — expected "-----BEGIN PRIVATE KEY-----"')
    }
  } catch (e) {
    console.error(`Cannot read key at ${args.key}: ${e.message}`)
    process.exit(1)
  }

  let jwt, header, payload
  try { ({ jwt, header, payload } = makeJwt(keyPem, args['key-id'], args['team-id'])) }
  catch (e) { console.error(`Failed to sign APNs JWT (bad key?): ${e.message}`); process.exit(1) }

  const token = args.token || crypto.randomBytes(32).toString('hex')
  const isReal = !!args.token
  const envs = args.env === 'sandbox' ? ['sandbox'] : args.env === 'prod' ? ['prod'] : ['sandbox', 'prod']

  console.log(`APNs key check — key-id=${args['key-id']} team-id=${args['team-id']} topic=${args.topic}`)
  console.log(`token: ${isReal ? token.slice(0, 12) + '… (REAL — may deliver)' : 'random bogus (non-delivering)'}`)
  if (args.verbose) console.log(`jwt header=${JSON.stringify(header)} payload=${JSON.stringify(payload)}`)
  console.log('')

  let anyOk = false
  for (const env of envs) {
    const res = await probe(HOSTS[env], jwt, args.topic, token)
    if (res.error) { console.log(`[${env}]  connection error: ${res.error}`); continue }
    let reason = ''
    try { reason = JSON.parse(res.body || '{}').reason || '' } catch { reason = res.body }
    const v = verdict(res.status, reason)
    anyOk = anyOk || v.ok
    console.log(`[${env}]  HTTP ${res.status} ${reason ? `(${reason})` : ''}\n         ${v.msg}`)
  }

  console.log('')
  console.log(anyOk
    ? '=> The .p8 key authenticates with Apple. If FCM still fails, re-upload THIS exact key to Firebase.'
    : '=> The .p8 key is being rejected by Apple. Re-issue the key (ensure APNs is enabled) or fix Key ID / Team ID.')
  process.exit(anyOk ? 0 : 1)
}

main()
