// @ts-check
/**
 * Playwright test fixtures for the LiveKit chaos suite.
 *
 * Each scenario gets:
 *   - `adminApi`: APIRequestContext authenticated as the admin fixture user
 *     (Authorization: Bearer <jwt> from POST /api/auth/login)
 *   - `provisionRoom({ waiting_room, participants_hidden })`: creates a portal
 *     call and its guest+admin tokens. Returns
 *     { adminUrl, guestUrl, adminToken, guestToken, roomName, cleanup }.
 *
 * Every fixture registers a teardown that revokes the room afterwards,
 * regardless of test outcome.
 */

const { test: base, request } = require('@playwright/test')
const api = require('./api.js')

const REQUIRED_ENV = ['FLOWONE_BASE_URL', 'FLOWONE_ADMIN_EMAIL', 'FLOWONE_ADMIN_PASSWORD']

function requireEnv() {
  const missing = REQUIRED_ENV.filter((k) => !process.env[k])
  if (missing.length) {
    throw new Error(
      'Missing required env: ' + missing.join(', ') +
      '. Copy .env.example to .env and fill in.'
    )
  }
}

const test = base.extend({
  adminApi: async ({}, use) => {
    requireEnv()
    const baseURL = process.env.FLOWONE_BASE_URL

    // Stage 1: plain context for login
    const loginCtx = await request.newContext({
      baseURL,
      ignoreHTTPSErrors: true,
      extraHTTPHeaders: { Accept: 'application/json' },
    })
    let bearerCtx = null
    try {
      const auth = await api.loginAdmin(loginCtx, {
        email: process.env.FLOWONE_ADMIN_EMAIL,
        password: process.env.FLOWONE_ADMIN_PASSWORD,
      })
      // Stage 2: authenticated context for all other calls
      bearerCtx = await api.withBearer(baseURL, auth.accessToken)
    } finally {
      await loginCtx.dispose()
    }

    await use(bearerCtx)
    await bearerCtx.dispose()
  },

  provisionRoom: async ({ adminApi }, use, testInfo) => {
    const tracked = []
    const baseURL = process.env.FLOWONE_BASE_URL

    async function provision({ waiting_room = false, participants_hidden = false } = {}) {
      const overrideGuest = process.env.FLOWONE_GUEST_TOKEN
      const overrideAdmin = process.env.FLOWONE_ADMIN_TOKEN
      if (overrideGuest && overrideAdmin) {
        return {
          adminUrl: `${baseURL}/guest/call/${overrideAdmin}`,
          guestUrl: `${baseURL}/guest/call/${overrideGuest}`,
          adminToken: overrideAdmin,
          guestToken: overrideGuest,
          roomName: 'preexisting',
          cleanup: async () => {},
        }
      }

      const clientId = parseInt(process.env.FLOWONE_CRM_CLIENT_ID || '0', 10)
      if (!clientId) {
        throw new Error('FLOWONE_CRM_CLIENT_ID is required to provision portal-call fixtures')
      }

      const call = await api.createPortalCall(adminApi, clientId, {
        waiting_room,
        participants_hidden,
        call_type: 'instant',
      })

      const handle = {
        adminUrl: call.admin_link,
        guestUrl: call.guest_link,
        adminToken: call.admin_token,
        guestToken: call.guest_token,
        roomName: call.room_name,
        callId: call.call_id,
        cleanup: async () => {
          try {
            await api.revokeRoomEntirely(adminApi, call.admin_token)
          } catch (e) {
            try {
              testInfo.attachments.push({
                name: 'cleanup-error.txt',
                contentType: 'text/plain',
                body: Buffer.from(String(e && e.message || e)),
              })
            } catch {}
          }
        },
      }
      tracked.push(handle)
      return handle
    }

    await use(provision)

    for (const h of tracked) {
      try { await h.cleanup() } catch {}
    }
  },
})

module.exports = { test, expect: base.expect }
