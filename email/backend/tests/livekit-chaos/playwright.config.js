// @ts-check
const { defineConfig, devices } = require('@playwright/test')
require('dotenv').config({ path: require('path').join(__dirname, '.env') })

const BASE_URL = process.env.FLOWONE_BASE_URL || 'https://flowone.pro'

module.exports = defineConfig({
  testDir: './scenarios',
  fullyParallel: false,
  forbidOnly: !!process.env.CI,
  retries: 0,
  workers: 1,
  reporter: [
    ['list'],
    ['json', { outputFile: 'report.json' }],
  ],
  timeout: 90_000,
  expect: { timeout: 15_000 },
  use: {
    baseURL: BASE_URL,
    headless: true,
    ignoreHTTPSErrors: true,
    viewport: { width: 1280, height: 800 },
    permissions: ['camera', 'microphone'],
    launchOptions: {
      args: [
        '--use-fake-ui-for-media-stream',
        '--use-fake-device-for-media-stream',
        '--autoplay-policy=no-user-gesture-required',
        '--disable-features=WebRtcHideLocalIpsWithMdns',
      ],
    },
    trace: 'retain-on-failure',
    video: 'retain-on-failure',
    screenshot: 'only-on-failure',
  },
  projects: [
    {
      name: 'desktop-chromium',
      use: { ...devices['Desktop Chrome'] },
    },
    {
      name: 'mobile-ios',
      grep: /@mobile/,
      use: { ...devices['iPhone 13'], hasTouch: true },
    },
  ],
})
