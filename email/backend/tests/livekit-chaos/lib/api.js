// @ts-check
/**
 * REST helpers for the FlowOne chaos suite.
 *
 * Authentication: FlowOne uses IMAP-backed login at `POST /api/auth/login`. The
 * QA admin therefore needs a REAL mail account on the FlowOne mail server with
 * 2FA disabled, NOT a database row. `loginAdmin` returns the JWT access_token;
 * callers wrap it into an authenticated APIRequestContext via the helper below.
 *
 * Wrapping pattern: Playwright's APIRequestContext is immutable per-instance,
 * so `withBearer` builds a fresh context that pins the Authorization header.
 *
 * IMPORTANT: All test fixtures use `[FLOWONE-TEST] livekit-chaos` markers so
 * production data can never be confused with chaos rows.
 */

const { request } = require('@playwright/test')
const crypto = require('crypto')

const TEST_MARK = '[FLOWONE-TEST] livekit-chaos'

/**
 * RFC 6238 TOTP code generator (HMAC-SHA1, 6 digits, 30s window).
 * Used when the operator passes FLOWONE_2FA_SECRET in .env so the chaos suite
 * can mint codes on demand instead of asking for fresh ones each run.
 *
 * @param {string} secretBase32 Base32-encoded shared secret as shown when 2FA was set up
 * @param {number} [stepSeconds=30]
 * @param {number} [digits=6]
 */
function generateTotp(secretBase32, stepSeconds = 30, digits = 6, offsetSteps = 0) {
  const norm = String(secretBase32 || '').toUpperCase().replace(/[^A-Z2-7]/g, '')
  if (!norm) throw new Error('FLOWONE_2FA_SECRET is empty or not valid base32')
  // Decode base32
  let bits = ''
  const alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'
  for (const c of norm) {
    const idx = alphabet.indexOf(c)
    if (idx < 0) continue
    bits += idx.toString(2).padStart(5, '0')
  }
  const keyBytes = []
  for (let i = 0; i + 8 <= bits.length; i += 8) {
    keyBytes.push(parseInt(bits.slice(i, i + 8), 2))
  }
  const key = Buffer.from(keyBytes)

  const counter = Math.floor(Date.now() / 1000 / stepSeconds) + offsetSteps
  const counterBuf = Buffer.alloc(8)
  counterBuf.writeBigUInt64BE(BigInt(counter), 0)

  const hmac = crypto.createHmac('sha1', key).update(counterBuf).digest()
  const off = hmac[hmac.length - 1] & 0xf
  const code = ((hmac[off] & 0x7f) << 24) |
               ((hmac[off + 1] & 0xff) << 16) |
               ((hmac[off + 2] & 0xff) << 8) |
               (hmac[off + 3] & 0xff)
  return String(code % Math.pow(10, digits)).padStart(digits, '0')
}

/**
 * @typedef {import('@playwright/test').APIRequestContext} APIRequestContext
 */

/**
 * POST /api/auth/login with email + IMAP password.
 *
 * Handles all three 2FA paths:
 *   1. No 2FA on the account → completes in one round-trip.
 *   2. 2FA enabled + FLOWONE_DEVICE_TOKEN set → passes device_token on the
 *      initial login; the backend skips 2FA if the token is still trusted.
 *   3. 2FA enabled + (FLOWONE_2FA_CODE or FLOWONE_2FA_SECRET) set → continues
 *      to POST /api/2fa/login with trust_device: true and returns the
 *      device_token in the result so the operator can save it for future runs.
 *
 * @param {APIRequestContext} ctx
 * @param {{ email: string, password: string }} creds
 * @returns {Promise<{ accessToken: string, refreshToken: string, displayName: string, deviceToken: string|null }>}
 */
async function loginAdmin(ctx, creds) {
  const initialPayload = { email: creds.email, password: creds.password }
  const deviceToken = process.env.FLOWONE_DEVICE_TOKEN || ''
  if (deviceToken) initialPayload.device_token = deviceToken

  const res = await ctx.post('/api/auth/login', { data: initialPayload })
  const body = await res.json().catch(() => null)
  if (!res.ok()) {
    throw new Error(`loginAdmin failed: HTTP ${res.status()} ${JSON.stringify(body)}`)
  }
  const data = body?.data || body

  // 2FA branch
  if (data?.requires_2fa) {
    const tempToken = data?.temp_token
    if (!tempToken) {
      throw new Error('loginAdmin: requires_2fa but no temp_token in response')
    }

    let code = (process.env.FLOWONE_2FA_CODE || '').trim()
    if (!code && process.env.FLOWONE_2FA_SECRET) {
      try {
        code = generateTotp(process.env.FLOWONE_2FA_SECRET)
      } catch (e) {
        throw new Error('loginAdmin: failed to compute TOTP from FLOWONE_2FA_SECRET: ' + e.message)
      }
    }
    if (!code) {
      throw new Error(
        'loginAdmin: account has 2FA enabled. Set FLOWONE_2FA_CODE=<6-digit code from authenticator> ' +
        'or FLOWONE_2FA_SECRET=<base32 secret> in .env. The first successful run will return a ' +
        'device_token you can save as FLOWONE_DEVICE_TOKEN to skip 2FA on future runs.'
      )
    }

    // Try the current window first, then ±1 step (60s of skew tolerance) if
    // the server rejects. Helps when the chaos host or the operator's
    // authenticator are slightly out of sync.
    const candidates = [code]
    if (process.env.FLOWONE_2FA_SECRET) {
      try { candidates.push(generateTotp(process.env.FLOWONE_2FA_SECRET, 30, 6, -1)) } catch {}
      try { candidates.push(generateTotp(process.env.FLOWONE_2FA_SECRET, 30, 6, 1)) } catch {}
    }

    let vRes = null
    let vBody = null
    let lastCode = ''
    for (const c of candidates) {
      lastCode = c
      vRes = await ctx.post('/api/2fa/login', {
        data: {
          email: creds.email,
          code: c,
          temp_token: tempToken,
          trust_device: true,
        },
      })
      vBody = await vRes.json().catch(() => null)
      if (vRes.ok()) break
    }
    if (!vRes || !vRes.ok()) {
      throw new Error(`2FA verify failed: HTTP ${vRes ? vRes.status() : '?'} ${JSON.stringify(vBody)} (tried ${candidates.length} code(s))`)
    }
    const vData = vBody?.data || vBody
    const accessToken = vData?.access_token
    if (!accessToken) {
      throw new Error('2FA verify: response missing access_token: ' + JSON.stringify(vBody))
    }
    const newDeviceToken = vData?.device_token || ''
    if (newDeviceToken) {
      // eslint-disable-next-line no-console
      console.log(
        '\n[chaos] 2FA passed. To skip 2FA on future runs, append this to ' +
        'livekit-chaos/.env:\n' +
        '  FLOWONE_DEVICE_TOKEN=' + newDeviceToken + '\n'
      )
    }
    return {
      accessToken,
      refreshToken: vData?.refresh_token || '',
      displayName: vData?.user?.display_name || '',
      deviceToken: newDeviceToken || null,
    }
  }

  // No-2FA branch
  const accessToken = data?.access_token
  if (!accessToken) {
    throw new Error('loginAdmin: response missing access_token: ' + JSON.stringify(body))
  }
  return {
    accessToken,
    refreshToken: data?.refresh_token || '',
    displayName: data?.user?.display_name || '',
    deviceToken: null,
  }
}

/**
 * Build a fresh APIRequestContext that sends `Authorization: Bearer <token>` on
 * every request. Use this for all authenticated calls after `loginAdmin`.
 *
 * @param {string} baseURL
 * @param {string} accessToken
 * @returns {Promise<APIRequestContext>}
 */
async function withBearer(baseURL, accessToken) {
  return request.newContext({
    baseURL,
    ignoreHTTPSErrors: true,
    extraHTTPHeaders: {
      Accept: 'application/json',
      Authorization: `Bearer ${accessToken}`,
    },
  })
}

/**
 * Create a portal call + its guest token + its admin token. Returns the full
 * fixture handle the chaos scenarios consume.
 *
 * Flow: 3 round-trips against the authenticated context.
 *   1. POST /api/clients/{id}/portal/calls          → row with id, room_name
 *   2. POST /api/clients/{id}/portal/calls/{callId}/guest-link {role:'guest'}
 *   3. POST /api/clients/{id}/portal/calls/{callId}/guest-link {role:'admin'}
 *
 * @param {APIRequestContext} ctx
 * @param {number} clientId
 * @param {{ waiting_room?: boolean, participants_hidden?: boolean, call_type?: 'instant'|'scheduled', ttl_hours?: number }} opts
 */
async function createPortalCall(ctx, clientId, opts = {}) {
  const callRes = await ctx.post(`/api/clients/${clientId}/portal/calls`, {
    data: {
      call_type: opts.call_type || 'instant',
      waiting_room: !!opts.waiting_room,
      participants_hidden: !!opts.participants_hidden,
      note: TEST_MARK,
    },
  })
  const callBody = await callRes.json().catch(() => null)
  if (!callRes.ok()) {
    throw new Error(`createPortalCall failed: HTTP ${callRes.status()} ${JSON.stringify(callBody)}`)
  }
  const call = callBody?.data || callBody
  if (!call?.id || !call?.room_name) {
    throw new Error('createPortalCall: response missing id/room_name: ' + JSON.stringify(callBody))
  }

  const callId = call.id
  const ttlHours = opts.ttl_hours || 6

  const mkLink = async (role) => {
    const r = await ctx.post(`/api/clients/${clientId}/portal/calls/${callId}/guest-link`, {
      data: { role, ttl_hours: ttlHours },
    })
    const body = await r.json().catch(() => null)
    if (!r.ok()) {
      throw new Error(`createGuestLink(${role}) HTTP ${r.status()} ${JSON.stringify(body)}`)
    }
    const data = body?.data || body
    if (!data?.token || !data?.link) {
      throw new Error(`createGuestLink(${role}) missing token/link: ` + JSON.stringify(body))
    }
    return { token: data.token, link: data.link, expires_at: data.expires_at }
  }

  const guest = await mkLink('guest')
  const admin = await mkLink('admin')

  return {
    call_id: callId,
    room_name: call.room_name,
    guest_link: guest.link,
    guest_token: guest.token,
    admin_link: admin.link,
    admin_token: admin.token,
    expires_at: guest.expires_at,
  }
}

/**
 * Extract the raw token from a /guest/call/{token} URL.
 * @param {string} url
 */
function tokenFromUrl(url) {
  const m = String(url || '').match(/\/guest\/call\/([0-9a-f]{32,})/i)
  if (!m) throw new Error('cannot parse token from URL: ' + url)
  return m[1]
}

// --- Public endpoints (no auth required, but admin token can be passed) ---

async function publicGetInfo(ctx, token) {
  const res = await ctx.get(`/api/guest/call/${token}`)
  return { status: res.status(), body: await res.json().catch(() => null) }
}

async function publicJoin(ctx, token, name = TEST_MARK) {
  const res = await ctx.post(`/api/guest/call/${token}/join`, { data: { name } })
  return { status: res.status(), body: await res.json().catch(() => null) }
}

async function revokeRoomEntirely(ctx, adminToken) {
  const res = await ctx.post(`/api/guest/call/${adminToken}/revoke-room`)
  return { status: res.status(), body: await res.json().catch(() => null) }
}

async function kickByIdentity(ctx, adminToken, identity) {
  const res = await ctx.post(`/api/guest/call/${adminToken}/kick`, { data: { identity } })
  return { status: res.status(), body: await res.json().catch(() => null) }
}

async function listLobby(ctx, adminToken) {
  const res = await ctx.get(`/api/guest/call/lobby?admin_token=${encodeURIComponent(adminToken)}`)
  return { status: res.status(), body: await res.json().catch(() => null) }
}

async function approveAdmission(ctx, adminToken, requestId) {
  const res = await ctx.post(`/api/guest/call/admission/${requestId}/approve`, {
    data: { admin_token: adminToken },
  })
  return { status: res.status(), body: await res.json().catch(() => null) }
}

async function denyAdmission(ctx, adminToken, requestId) {
  const res = await ctx.post(`/api/guest/call/admission/${requestId}/deny`, {
    data: { admin_token: adminToken },
  })
  return { status: res.status(), body: await res.json().catch(() => null) }
}

async function listAttendees(ctx, adminToken) {
  const res = await ctx.get(`/api/guest/call/${adminToken}/attendees`)
  return { status: res.status(), body: await res.json().catch(() => null) }
}

module.exports = {
  TEST_MARK,
  loginAdmin,
  withBearer,
  createPortalCall,
  tokenFromUrl,
  publicGetInfo,
  publicJoin,
  revokeRoomEntirely,
  kickByIdentity,
  listLobby,
  approveAdmission,
  denyAdmission,
  listAttendees,
}
