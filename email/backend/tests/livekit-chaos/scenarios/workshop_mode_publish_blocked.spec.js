// @ts-check
/**
 * Scenario: workshop mode guest tries to publish microphone — LiveKit must reject
 * because the server-issued token has `canPublish: false`. The browser API will
 * resolve with a permission/grant error from livekit-client.
 */
const { test, expect } = require('../lib/fixtures.js')
const { installChaosHooks, waitForEvent } = require('../lib/livekit.js')

test('workshop_mode_publish_blocked: guest mic enable rejected by LiveKit', async ({ browser, provisionRoom }) => {
  const room = await provisionRoom({ participants_hidden: true })

  const ctx = await browser.newContext()
  const page = await ctx.newPage()
  await installChaosHooks(page)
  await page.goto(room.guestUrl)
  await page.getByRole('button', { name: /join/i }).first().click()
  await waitForEvent(page, 'connected', 30_000)

  const errorMsg = await page.evaluate(async () => {
    const r = window.flowoneCallRoom || window.__flowoneRoomStash
    if (!r) return 'no-room-handle'
    try {
      await r.localParticipant.setMicrophoneEnabled(true)
      return 'unexpectedly-allowed'
    } catch (e) {
      return String(e && e.message || e)
    }
  })

  // The exact wording varies by livekit-client version, but it should reference
  // permission / publish / not allowed.
  expect(errorMsg).toMatch(/permission|publish|not allowed|denied|grants|forbidden/i)

  await ctx.close()
})
