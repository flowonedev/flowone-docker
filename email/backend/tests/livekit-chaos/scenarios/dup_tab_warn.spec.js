// @ts-check
/**
 * Scenario: same browser, second tab opens the /guest/call/{token} URL while
 * the first is still in-call. VideoCallRoom uses a BroadcastChannel keyed on
 * the room name, so the second tab must surface the "Another tab is open"
 * warning with a "Continue anyway" button.
 *
 * IMPORTANT: BroadcastChannel only works *within the same browser context*
 * (same origin + same process). Two separate `browser.newContext()` calls are
 * fully isolated, so we use a single context and open a second page. That's
 * also the real-world scenario this code is defending against.
 */
const { test, expect } = require('../lib/fixtures.js')
const { installChaosHooks, waitForEvent } = require('../lib/livekit.js')

test('dup_tab_warn: second tab in same context shows duplicate warning', async ({ browser, provisionRoom }) => {
  const room = await provisionRoom()

  const ctx = await browser.newContext()

  const pageA = await ctx.newPage()
  await installChaosHooks(pageA)
  await pageA.goto(room.guestUrl)
  await pageA.getByRole('button', { name: /join/i }).first().click()
  await waitForEvent(pageA, 'connected', 30_000)

  const pageB = await ctx.newPage()
  await installChaosHooks(pageB)
  await pageB.goto(room.guestUrl)
  await pageB.getByRole('button', { name: /join/i }).first().click()
  await waitForEvent(pageB, 'connected', 30_000)

  // Once Tab B has VideoCallRoom mounted its BroadcastChannel picks up
  // Tab A's heartbeat (1 s interval) and flips dupTabConflict.
  await expect(pageB.getByText(/another tab is open/i)).toBeVisible({ timeout: 8_000 })

  const continueBtn = pageB.getByRole('button', { name: /continue anyway/i })
  if (await continueBtn.count()) {
    await continueBtn.first().click()
  }

  await ctx.close()
})
