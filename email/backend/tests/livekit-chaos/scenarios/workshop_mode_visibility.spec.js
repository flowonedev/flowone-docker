// @ts-check
/**
 * Scenario: workshop mode (participants_hidden=true).
 *
 * Contract being verified:
 *   1. Two guests in the same workshop-mode room must NOT see each other in
 *      their LiveKit `room.remoteParticipants` (server-enforced via the
 *      `hidden: true` grant).
 *   2. Each guest still sees the admin (who is not hidden).
 *   3. The admin can enumerate all participants via the server-side
 *      `/guest/call/{token}/attendees` admin endpoint — this is the
 *      *contract*, not whether the admin's LiveKit client happens to see
 *      hidden participants (which depends on LiveKit server version).
 */
const { test, expect } = require('../lib/fixtures.js')
const { installChaosHooks, waitForEvent, getCallState } = require('../lib/livekit.js')
const api = require('../lib/api.js')

test('workshop_mode_visibility: guests hidden from each other, admin enumerates via API', async ({ browser, adminApi, provisionRoom }) => {
  const room = await provisionRoom({ participants_hidden: true })

  const ctx = await browser.newContext()

  const guestAPage = await ctx.newPage()
  await installChaosHooks(guestAPage)
  await guestAPage.goto(room.guestUrl)
  await guestAPage.getByRole('button', { name: /join/i }).first().click()
  await waitForEvent(guestAPage, 'connected', 30_000)

  const guestBPage = await ctx.newPage()
  await installChaosHooks(guestBPage)
  await guestBPage.goto(room.guestUrl)
  await guestBPage.getByRole('button', { name: /join/i }).first().click()
  await waitForEvent(guestBPage, 'connected', 30_000)

  const adminPage = await ctx.newPage()
  await installChaosHooks(adminPage)
  await adminPage.goto(room.adminUrl)
  await adminPage.getByRole('button', { name: /join/i }).first().click()
  await waitForEvent(adminPage, 'connected', 30_000)

  // Give participantConnected events 2s to propagate to A and B for the admin
  // (admin is NOT hidden, so guests must see the admin).
  await guestAPage.waitForTimeout(2_000)

  const guestAState = await getCallState(guestAPage)
  const guestBState = await getCallState(guestBPage)

  // Guest A must NOT see Guest B (both are hidden). Guest A must see admin.
  const guestANonAdmin = guestAState.participants.filter((p) => !/^admin_/.test(String(p.identity || '')))
  const guestBNonAdmin = guestBState.participants.filter((p) => !/^admin_/.test(String(p.identity || '')))
  expect(guestANonAdmin.length, 'Guest A should not see other hidden guests').toBe(0)
  expect(guestBNonAdmin.length, 'Guest B should not see other hidden guests').toBe(0)

  const guestASeesAdmin = guestAState.participants.some((p) => /^admin_/.test(String(p.identity || '')))
  const guestBSeesAdmin = guestBState.participants.some((p) => /^admin_/.test(String(p.identity || '')))
  expect(guestASeesAdmin, 'Guest A should see the admin').toBe(true)
  expect(guestBSeesAdmin, 'Guest B should see the admin').toBe(true)

  // Server-side: admin must be able to enumerate all participants regardless
  // of LiveKit visibility — this is the production admin contract.
  const att = await api.listAttendees(adminApi, room.adminToken)
  expect(att.status).toBe(200)
  const list = att.body?.data || att.body?.attendees || []
  expect(Array.isArray(list)).toBe(true)
  const guests = list.filter((p) => /^guest_/.test(String(p.identity || p.name || '')))
  expect(guests.length, 'Admin attendees API should list both workshop guests').toBeGreaterThanOrEqual(2)

  await ctx.close()
})
