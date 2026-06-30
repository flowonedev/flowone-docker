// @ts-check
/**
 * Scenario: 3 guests join the same room (same context, three tabs). Admin
 * revokes the entire room via /guest/call/{adminToken}/revoke-room. All 3
 * participants must see the kicked overlay.
 *
 * Note: because the unified flow gives one guest token per provisioning, all 3
 * tabs share the same token. The backend enumerates `meeting_sessions` for
 * that room and calls `RemoveParticipant` for every active session, then
 * marks all tokens in the room as 'revoked'.
 *
 * We poll on `state.kicked` rather than `waitForEvent('disconnected')` because
 * the disconnect can fire faster than the test thread can register its
 * listener.
 */
const { test, expect } = require('../lib/fixtures.js')
const { installChaosHooks, waitForEvent, getCallState } = require('../lib/livekit.js')
const api = require('../lib/api.js')

test('revoke_disconnects_all: revoke-room → all 3 participants kicked', async ({ browser, adminApi, provisionRoom }) => {
  const room = await provisionRoom()
  const ctx = await browser.newContext()
  const pages = []
  for (let i = 0; i < 3; i++) {
    const page = await ctx.newPage()
    await installChaosHooks(page)
    await page.goto(room.guestUrl)
    await page.getByRole('button', { name: /join/i }).first().click()
    await waitForEvent(page, 'connected', 30_000)
    pages.push(page)
  }

  const revoke = await api.revokeRoomEntirely(adminApi, room.adminToken)
  expect(revoke.status).toBe(200)

  for (const page of pages) {
    await expect.poll(async () => {
      const s = await getCallState(page)
      return s.kicked === true || s.connected === false
    }, { timeout: 10_000, intervals: [200, 200, 500, 500, 1000] }).toBe(true)
    await expect(page.getByText(/removed from the call/i).first()).toBeVisible({ timeout: 5_000 })
  }

  await ctx.close()
})
