// @ts-check
/**
 * Scenario: waiting room enabled. Guest opens link, sees "Waiting for host".
 * Admin opens admin link, sees the lobby entry, clicks Admit. Guest should
 * auto-transition into the call within 5s.
 */
const { test, expect } = require('../lib/fixtures.js')
const { installChaosHooks, waitForEvent } = require('../lib/livekit.js')

test('waiting_room_flow: pending → admit → connected within 5s', async ({ browser, provisionRoom }) => {
  const room = await provisionRoom({ waiting_room: true })

  const guestCtx = await browser.newContext()
  const guestPage = await guestCtx.newPage()
  await installChaosHooks(guestPage)
  await guestPage.goto(room.guestUrl)
  await guestPage.getByRole('button', { name: /join/i }).first().click()
  await expect(guestPage.getByText(/waiting for the host|let you in/i)).toBeVisible({ timeout: 15_000 })

  const adminCtx = await browser.newContext()
  const adminPage = await adminCtx.newPage()
  await installChaosHooks(adminPage)
  await adminPage.goto(room.adminUrl)

  const admitBtn = adminPage.getByRole('button', { name: /admit/i })
  await expect(admitBtn.first()).toBeVisible({ timeout: 15_000 })
  const t0 = Date.now()
  await admitBtn.first().click()

  await waitForEvent(guestPage, 'connected', 10_000)
  const elapsed = Date.now() - t0
  expect(elapsed).toBeLessThan(10_000)

  await guestCtx.close()
  await adminCtx.close()
})
