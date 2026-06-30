/**
 * APNs VoIP push sender (PushKit) for the iOS Chat app.
 *
 * firebase-admin / FCM cannot send `apns-push-type: voip` notifications, which
 * are the ONLY way to wake a killed/backgrounded iOS app and present the native
 * CallKit full-screen call screen. This service talks to APNs directly over
 * HTTP/2 using token-based auth (a .p8 key signed as ES256). The same key that
 * signs alert pushes also signs VoIP pushes; only the topic differs
 * (`${bundleId}.voip`).
 *
 * Self-contained and fail-safe: when the key is missing/misconfigured the
 * service reports `configured = false` and every send is a no-op, so calls
 * gracefully fall back to the existing alert push.
 */

import http2 from 'node:http2'
import { readFileSync, existsSync } from 'fs'
import jwt from 'jsonwebtoken'
import { config } from '../config.js'

const APNS_HOST_PROD = 'https://api.push.apple.com'
const APNS_HOST_SANDBOX = 'https://api.sandbox.push.apple.com'
// Apple requires the auth JWT be refreshed between 20 and 60 minutes.
const TOKEN_TTL_MS = 50 * 60 * 1000

export class ApnsVoipService {
  constructor() {
    const c = config.apnsVoip || {}
    this.enabled = c.enabled !== false
    this.keyPath = c.keyPath
    this.keyId = c.keyId
    this.teamId = c.teamId
    this.bundleId = c.bundleId || 'com.flowone.chat'
    this.topic = `${this.bundleId}.voip`
    this.host = c.production ? APNS_HOST_PROD : APNS_HOST_SANDBOX

    this._privateKey = null
    this._jwt = null
    this._jwtIssuedAt = 0
    this._client = null

    this.configured = !!(
      this.enabled &&
      this.keyId &&
      this.teamId &&
      this.keyPath &&
      existsSync(this.keyPath)
    )

    if (this.configured) {
      try {
        this._privateKey = readFileSync(this.keyPath, 'utf8')
        console.log(`[ApnsVoip] Initialized (topic: ${this.topic}, ${c.production ? 'prod' : 'sandbox'})`)
      } catch (e) {
        this.configured = false
        console.error('[ApnsVoip] Failed to read .p8 key:', e.message)
      }
    } else {
      console.log('[ApnsVoip] Not configured — VoIP pushes disabled (calls use alert push fallback)')
    }
  }

  /** Cached ES256 provider JWT, regenerated every <60 min as APNs requires. */
  _authToken() {
    const now = Date.now()
    if (this._jwt && now - this._jwtIssuedAt < TOKEN_TTL_MS) return this._jwt
    this._jwt = jwt.sign(
      { iss: this.teamId, iat: Math.floor(now / 1000) },
      this._privateKey,
      { algorithm: 'ES256', header: { alg: 'ES256', kid: this.keyId } }
    )
    this._jwtIssuedAt = now
    return this._jwt
  }

  /** Lazily open (and memoize) the HTTP/2 session to APNs, reconnecting on close. */
  _connect() {
    if (this._client && !this._client.closed && !this._client.destroyed) return this._client
    this._client = http2.connect(this.host)
    this._client.on('error', (e) => {
      console.error('[ApnsVoip] HTTP/2 session error:', e.message)
    })
    this._client.on('close', () => { this._client = null })
    return this._client
  }

  /**
   * Send a VoIP push to a single device token. Resolves to
   * { ok, status, reason, token }. Never throws (callers fan out best-effort).
   */
  _sendOne(token, payloadObj, { collapseId, expiration } = {}) {
    return new Promise((resolve) => {
      if (!this.configured) return resolve({ ok: false, status: 0, reason: 'not_configured', token })

      let client
      try {
        client = this._connect()
      } catch (e) {
        return resolve({ ok: false, status: 0, reason: e.message, token })
      }

      const body = Buffer.from(JSON.stringify(payloadObj))
      const headers = {
        ':method': 'POST',
        ':path': `/3/device/${token}`,
        'authorization': `bearer ${this._authToken()}`,
        'apns-topic': this.topic,
        'apns-push-type': 'voip',
        'apns-priority': '10',
        'apns-expiration': String(expiration ?? Math.floor(Date.now() / 1000) + 30),
        'content-type': 'application/json',
        'content-length': body.length,
      }
      if (collapseId) headers['apns-collapse-id'] = String(collapseId).slice(0, 64)

      let req
      try {
        req = client.request(headers)
      } catch (e) {
        return resolve({ ok: false, status: 0, reason: e.message, token })
      }

      let status = 0
      let data = ''
      req.setEncoding('utf8')
      req.setTimeout(10000, () => { try { req.close(http2.constants.NGHTTP2_CANCEL) } catch (_) {} })
      req.on('response', (h) => { status = h[':status'] })
      req.on('data', (chunk) => { data += chunk })
      req.on('error', (e) => resolve({ ok: false, status, reason: e.message, token }))
      req.on('end', () => {
        let reason = null
        if (status !== 200 && data) {
          try { reason = JSON.parse(data).reason } catch (_) { reason = data }
        }
        resolve({ ok: status === 200, status, reason, token })
      })
      req.end(body)
    })
  }

  /**
   * Notify the iOS device(s) of an incoming call so the plugin can present
   * CallKit. `tokens` is an array of VoIP (PushKit) device tokens.
   * Returns { sent, failed, dead: [tokens] } — dead tokens (BadDeviceToken /
   * Unregistered) should be pruned by the caller.
   */
  async sendIncomingCall(tokens, call) {
    return this._fanOut(tokens, {
      type: 'incoming_call',
      callId: call.callId,
      conversationId: call.conversationId,
      callType: call.callType,
      callerEmail: call.callerEmail,
      callerName: call.callerName,
      callStartedAt: call.callStartedAt || Date.now(),
    }, { collapseId: `call-${call.callId}` })
  }

  /**
   * Tell the iOS device(s) to tear down the CallKit screen for a call that was
   * cancelled / missed / answered elsewhere before this device picked up.
   */
  async sendCallEnd(tokens, call) {
    return this._fanOut(tokens, {
      type: 'end_call',
      callId: call.callId,
      conversationId: call.conversationId,
      reason: call.reason || 'ended',
    }, { collapseId: `call-${call.callId}` })
  }

  async _fanOut(tokens, payloadObj, opts) {
    const result = { sent: 0, failed: 0, dead: [] }
    if (!this.configured || !Array.isArray(tokens) || tokens.length === 0) return result
    await Promise.all(
      tokens.map(async (token) => {
        const r = await this._sendOne(token, payloadObj, opts)
        if (r.ok) {
          result.sent++
        } else {
          result.failed++
          if (r.reason === 'BadDeviceToken' || r.reason === 'Unregistered') result.dead.push(token)
          else console.error(`[ApnsVoip] send failed (${r.status} ${r.reason}) token …${String(token).slice(-8)}`)
        }
      })
    )
    return result
  }
}
