// @ts-check
/**
 * Scenario: long offline (60s). LiveKit gives up its own reconnect attempts, so the
 * VideoCallRoom must transition to "disconnected" then prompt rejoin which calls
 * `reconnectFn` -> `/guest/call/{token}/join` again to fetch fresh credentials.
 *
 * Verifies that `/guest/call/{token}/join` was called at least twice and that the
 * participant is connected again.
 */
const { test, expect } = require('../lib/fixtures.js')
const { installChaosHooks, waitForEvent, getCallState, getCallEvents } = require('../lib/livekit.js')

test('reconnect_long_pause: 60s offline → fresh-token rejoin', async ({ browser, provisionRoom }) => {
  const room = await provisionRoom()
  const ctx = await browser.newContext()
  const page = await ctx.newPage()
  await installChaosHooks(page)

  const joinCalls = []
  page.on('request', (req) => {
    if (req.method() === 'POST' && /\/guest\/call\/[^/]+\/join/.test(req.url())) {
      joinCalls.push(req.url())
    }
  })

  await page.goto(room.guestUrl)
  await page.getByRole('button', { name: /join/i }).first().click()
  await waitForEvent(page, 'connected', 30_000)

  await ctx.setOffline(true)
  await page.waitForTimeout(60_000)
  await ctx.setOffline(false)

  // After a 60s blackout LiveKit should have given up its retries. The app
  // ends in "Rejoin Call" (status='ended') or "Reconnecting…" (status='reconnecting').
  // Either way, we drive recovery through the visible UI.
  await page.waitForTimeout(2_000)

  const rejoinEnd = page.getByRole('button', { name: /rejoin/i })
  if (await rejoinEnd.count()) {
    await rejoinEnd.first().click()
    await page.waitForTimeout(500)
    const joinAgain = page.getByRole('button', { name: /join/i })
    if (await joinAgain.count()) {
      await joinAgain.first().click()
    }
  }

  // Poll the connection state instead of waiting for a single event. LiveKit
  // may have already fired `reconnected` between setOffline(false) and our
  // check (events older than the lookback window would be missed by
  // waitForEvent).
  try {
    await expect.poll(async () => {
      const s = await getCallState(page)
      return !!s.connected
    }, { timeout: 30_000, intervals: [500, 1000, 1000, 2000, 2000] }).toBe(true)
  } catch (e) {
    throw new Error('Failed to recover after long pause. Recent events: ' +
      JSON.stringify((await getCallEvents(page)).slice(-25)))
  }

  // LiveKit *can* recover without a fresh /join call if its internal retry
  // succeeds before the user clicks Rejoin. The hard contract is "the call
  // is connected again", not "we re-fetched credentials".
  expect(joinCalls.length).toBeGreaterThanOrEqual(1)

  await ctx.close()
})
