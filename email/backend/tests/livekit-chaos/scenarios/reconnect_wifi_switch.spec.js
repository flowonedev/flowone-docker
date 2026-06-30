// @ts-check
/**
 * Scenario: short network blip. Verifies the call survives a transient offline
 * period and ends up reconnected within a reasonable window.
 *
 * We don't assert exactly which LiveKit event fires while offline (some
 * versions emit `reconnecting`, others jump straight to `disconnected` if the
 * abort window is too short). We just require that:
 *   1. one of those events fires while offline (proving disruption was seen),
 *   2. the call returns to a connected state once the network is restored
 *      (either via LiveKit's auto-reconnect or via the user-facing Rejoin
 *      flow).
 */
const { test, expect } = require('../lib/fixtures.js')
const { installChaosHooks, waitForEvent, getCallState, getCallEvents } = require('../lib/livekit.js')

async function clickIfVisible(page, locator) {
  const count = await locator.count()
  if (count > 0) {
    try {
      await locator.first().click({ timeout: 2_000 })
      return true
    } catch (_) {
      return false
    }
  }
  return false
}

test('@smoke reconnect_wifi_switch: brief offline → call survives', async ({ browser, provisionRoom }) => {
  const room = await provisionRoom()
  const ctx = await browser.newContext()
  const page = await ctx.newPage()
  await installChaosHooks(page)

  await page.goto(room.guestUrl)
  await page.getByRole('button', { name: /join/i }).first().click()
  await waitForEvent(page, 'connected', 30_000)

  await ctx.setOffline(true)
  // Accept either `reconnecting` (auto-recovery path) or `disconnected`
  // (LiveKit gave up while offline). Either proves the blip was detected.
  // LiveKit's keepalive timeout can run up to ~30s depending on version.
  await waitForEvent(page, ['reconnecting', 'disconnected'], 30_000)

  // Hold offline a moment, then restore connectivity.
  await page.waitForTimeout(5_000)
  await ctx.setOffline(false)

  // Path A: LiveKit auto-reconnect.
  // Path B: UI shows the "Rejoin Call" button (status=ended) → prejoin → "Join Call".
  let recoveredVia = null
  try {
    await waitForEvent(page, ['reconnected', 'connected'], 20_000)
    recoveredVia = 'auto'
  } catch (_) {
    const clickedEnd = await clickIfVisible(page, page.getByRole('button', { name: /rejoin/i }))
    if (clickedEnd) {
      // Ended → prejoin: now there's a fresh Join button. Wait briefly for
      // the prejoin screen to render before clicking it.
      await page.waitForTimeout(500)
      await clickIfVisible(page, page.getByRole('button', { name: /join/i }).first())
      await waitForEvent(page, 'connected', 30_000)
      recoveredVia = 'rejoin-flow'
    } else {
      throw new Error('Did not recover and no Rejoin button was visible. Events: ' +
        JSON.stringify((await getCallEvents(page)).slice(-20)))
    }
  }

  const state = await getCallState(page)
  expect(state.connected, `recovered via ${recoveredVia} but state.connected was false`).toBe(true)
  expect(state.kicked).toBe(false)

  await ctx.close()
})
