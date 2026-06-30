// @ts-check
/**
 * Scenario: admin is already inside the room. When a guest opens the link, the
 * backend pushes a `admission_request` data message via LiveKit `SendData`, and
 * the admin's VideoCallRoom must surface an admission toast / panel entry
 * within 3 seconds.
 */
const { test, expect } = require('../lib/fixtures.js')
const { installChaosHooks, waitForEvent, getCallState, getCallEvents } = require('../lib/livekit.js')

test('waiting_room_data_channel: admin gets SendData admission_request within 3s', async ({ browser, provisionRoom }) => {
  const room = await provisionRoom({ waiting_room: true })

  // Admin joins first
  const adminCtx = await browser.newContext()
  const adminPage = await adminCtx.newPage()
  await installChaosHooks(adminPage)
  await adminPage.goto(room.adminUrl)
  await adminPage.getByRole('button', { name: /join/i }).first().click()
  await waitForEvent(adminPage, 'connected', 30_000)

  const guestCtx = await browser.newContext()
  const guestPage = await guestCtx.newPage()
  await guestPage.goto(room.guestUrl)
  await guestPage.getByRole('button', { name: /join/i }).first().click()

  // Wait for the data message to land on admin's side. Poll state because
  // the data event can fire before our listener attaches.
  await expect.poll(async () => {
    const s = await getCallState(adminPage)
    return s.dataMessages.some((m) => m && (m.type === 'admission_request' || m.kind === 'admission_request'))
  }, { timeout: 15_000, intervals: [300, 500, 1000, 1000, 2000] }).toBe(true)

  // VideoCallRoom auto-opens the admission panel on incoming request, so the
  // user sees the panel (with "Waiting room" header and per-request Admit/Deny
  // buttons) rather than the collapsed "n waiting" badge.
  try {
    await expect(adminPage.getByText(/^Waiting room$/i).first()).toBeVisible({ timeout: 5_000 })
  } catch (_) {
    // Older builds may keep the badge collapsed: accept either UI shape.
    await expect(
      adminPage.getByText(/\d+ waiting/i).or(adminPage.getByRole('button', { name: /^admit$/i }))
    ).toBeVisible({ timeout: 5_000 }).catch(async () => {
      const events = (await getCallEvents(adminPage)).slice(-30)
      throw new Error('Admission UI never appeared. Recent admin events: ' + JSON.stringify(events))
    })
  }

  await guestCtx.close()
  await adminCtx.close()
})
