#!/usr/bin/env node
/*
 * Generates App Store / iOS launch assets from the source artwork in
 * the repo-root "FOR APP STORE" folder:
 *   - App icon       -> ios/App/App/Assets.xcassets/AppIcon.appiconset (1024x1024, opaque)
 *   - Launch splash  -> ios/App/App/Assets.xcassets/Splash.imageset (2732x2732, opaque)
 *   - Store shots    -> "FOR APP STORE/appstore-ready" (1290x2796, iPhone 6.9")
 *
 * Run: node scripts/generate-store-assets.cjs
 * Sharp is resolved from the frontend's node_modules so this script needs
 * no dependencies of its own.
 */
const path = require('path')
const fs = require('fs')

const MOBILE_DIR = path.resolve(__dirname, '..')
const REPO_ROOT = path.resolve(MOBILE_DIR, '..', '..')
const SRC_DIR = path.join(REPO_ROOT, 'FOR APP STORE')
const IOS_ASSETS = path.join(MOBILE_DIR, 'ios', 'App', 'App', 'Assets.xcassets')
const FRONTEND_MODULES = path.join(REPO_ROOT, 'email', 'frontend', 'node_modules')

const sharp = require(require.resolve('sharp', { paths: [FRONTEND_MODULES] }))

const WHITE = { r: 255, g: 255, b: 255, alpha: 1 }

// App Store screenshot sizes.
//  - iPhone 6.9" display: 1290x2796 (also covers smaller iPhones)
//  - iPad 13" display:    2048x2732 (accepted alongside 2064x2752)
const SHOT_SIZES = [
  { w: 1290, h: 2796, dir: 'appstore-ready', label: 'iPhone 6.9"' },
  { w: 2048, h: 2732, dir: 'appstore-ready-ipad', label: 'iPad 13"' },
]

const SCREENSHOTS = [
  'email.png',
  'calendar.png',
  'drive.png',
  'clients overview.png',
  'projects.png',
  'time.png',
  'financials.png',
  'team.png',
  'email capmpaigns.png',
]

async function buildIcon() {
  const out = path.join(IOS_ASSETS, 'AppIcon.appiconset', 'AppIcon-512@2x.png')
  const inner = Math.round(1024 * 0.74) // padded logo
  const logo = await sharp(path.join(SRC_DIR, 'logo.png'))
    .trim()
    .resize(inner, inner, { fit: 'inside', withoutEnlargement: false })
    .toBuffer()
  await sharp({
    create: { width: 1024, height: 1024, channels: 3, background: WHITE },
  })
    .composite([{ input: logo, gravity: 'center' }])
    .removeAlpha()
    .png()
    .toFile(out)
  console.log('icon  ->', path.relative(REPO_ROOT, out))
}

async function buildSplash() {
  const dir = path.join(IOS_ASSETS, 'Splash.imageset')
  const base = await sharp(path.join(SRC_DIR, 'splash.png'))
    .resize(2732, 2732, { fit: 'cover', position: 'centre' })
    .flatten({ background: WHITE })
    .removeAlpha()
    .png()
    .toBuffer()
  for (const name of [
    'splash-2732x2732.png',
    'splash-2732x2732-1.png',
    'splash-2732x2732-2.png',
  ]) {
    fs.writeFileSync(path.join(dir, name), base)
  }
  console.log('splash ->', path.relative(REPO_ROOT, dir), '(2732x2732 x3)')
}

async function buildScreenshotSet({ w, h, dir, label }) {
  const outDir = path.join(SRC_DIR, dir)
  fs.mkdirSync(outDir, { recursive: true })
  let n = 0
  for (const file of SCREENSHOTS) {
    const src = path.join(SRC_DIR, file)
    if (!fs.existsSync(src)) {
      console.warn('  skip (missing):', file)
      continue
    }
    n += 1
    const scaled = await sharp(src)
      .resize(w, h, { fit: 'inside', withoutEnlargement: false })
      .toBuffer()
    const slug = file.replace(/\.png$/i, '').replace(/[^a-z0-9]+/gi, '-').toLowerCase()
    const out = path.join(outDir, `${String(n).padStart(2, '0')}-${slug}.png`)
    await sharp({
      create: { width: w, height: h, channels: 3, background: WHITE },
    })
      .composite([{ input: scaled, gravity: 'center' }])
      .removeAlpha()
      .png()
      .toFile(out)
  }
  console.log(`screenshots (${label}, ${w}x${h}): ${n} -> ${path.relative(REPO_ROOT, outDir)}`)
}

async function buildScreenshots() {
  for (const size of SHOT_SIZES) {
    await buildScreenshotSet(size)
  }
}

;(async () => {
  if (!fs.existsSync(SRC_DIR)) {
    console.error('Source folder not found:', SRC_DIR)
    process.exit(1)
  }
  await buildIcon()
  await buildSplash()
  await buildScreenshots()
  console.log('\nDone. Re-run "npx cap sync ios" to embed icon + splash.')
})().catch((e) => {
  console.error(e)
  process.exit(1)
})
