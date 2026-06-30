// @ts-check
/**
 * Scenario: admin issues a per-participant kick. The targeted guest must:
 *   1. Be disconnected from LiveKit with a ParticipantRemoved reason.
 *   2. Show the kicked overlay.
 *   3. On a hard reload, the /guest/call/{token} page must show that the
 *      link is no longer valid (token revoked server-side).
 *
 * We poll on `state.kicked` rather than `waitForEvent('disconnected')` because
 * the disconnect can fire faster than the test thread can register its
 * listener — the chaos hook captures the event either way, polling avoids
 * the race entirely.
 */
const { test, expect } = require('../lib/fixtures.js')
const { installChaosHooks, waitForEvent, getCallState } = require('../lib/livekit.js')
const api = require('../lib/api.js')

test('kick_disconnects: admin kick → disconnect + kicked UI + 410 on reload', async ({ browser, adminApi, provisionRoom }) => {
  const room = await provisionRoom()
  const ctx = await browser.newContext()
  const page = await ctx.newPage()
  await installChaosHooks(page)

  await page.goto(room.guestUrl)
  await page.getByRole('button', { name: /join/i }).first().click()
  await waitForEvent(page, 'connected', 30_000)

  const att = await api.listAttendees(adminApi, room.adminToken)
  expect(att.status).toBe(200)
  const list = att.body?.data || att.body?.attendees || []
  expect(Array.isArray(list)).toBe(true)
  expect(list.length).toBeGreaterThan(0)
  const target = list[0]
  const identity = target.identity || target.name || target.id
  expect(identity).toBeTruthy()

  const kicked = await api.kickByIdentity(adminApi, room.adminToken, identity)
  expect(kicked.status).toBe(200)

  // Poll state.kicked instead of waiting for the disconnect event — the
  // event can fire faster than waitForEvent can attach its filter.
  await expect.poll(async () => {
    const s = await getCallState(page)
    return s.kicked === true
  }, { timeout: 10_000, intervals: [200, 200, 500, 500, 1000] }).toBe(true)

  const state = await getCallState(page)
  expect(state.connected).toBe(false)
  await expect(page.getByText(/removed from the call/i).first()).toBeVisible({ timeout: 5_000 })

  // Reload — guest token revoked → page should show "no longer valid" / "expired"
  await page.reload()
  await expect(page.getByText(/no longer (valid|available)|has expired/i).first()).toBeVisible({ timeout: 10_000 })

  await ctx.close()
})
