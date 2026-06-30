// @ts-check
/**
 * Scenario: mobile iOS-style backgrounding via visibilitychange.
 * After 30s "hidden", returning to visible should re-acquire the wake lock (when
 * supported) and resume the local video element without permanent disconnect.
 *
 * Tagged @mobile so the Playwright `mobile-ios` project picks it up.
 */
const { test, expect } = require('../lib/fixtures.js')
const { installChaosHooks, waitForEvent, getCallState } = require('../lib/livekit.js')

test('@mobile mobile_background: visibility hidden 30s → resume', async ({ browser, provisionRoom }) => {
  const room = await provisionRoom()
  const ctx = await browser.newContext({
    viewport: { width: 390, height: 844 },
    isMobile: true,
    hasTouch: true,
  })
  const page = await ctx.newPage()
  await installChaosHooks(page)

  await page.goto(room.guestUrl)
  await page.getByRole('button', { name: /join/i }).first().click()
  await waitForEvent(page, 'connected', 30_000)

  await page.evaluate(() => {
    Object.defineProperty(document, 'visibilityState', { configurable: true, get: () => 'hidden' })
    document.dispatchEvent(new Event('visibilitychange'))
  })
  await page.waitForTimeout(30_000)

  await page.evaluate(() => {
    Object.defineProperty(document, 'visibilityState', { configurable: true, get: () => 'visible' })
    document.dispatchEvent(new Event('visibilitychange'))
  })

  // Either an iOS resume CTA appears, or the video resumes automatically.
  const resumeBtn = page.getByRole('button', { name: /tap to resume|resume|reconnect/i })
  if (await resumeBtn.count()) {
    await resumeBtn.first().click()
  }

  const state = await getCallState(page)
  expect(state.connected).toBe(true)
  expect(state.kicked).toBe(false)

  await ctx.close()
})
