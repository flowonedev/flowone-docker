/**
 * Generate the PWA / Apple touch icons from the master FlowOne logo.
 *
 * Source: public/flowone-logo.png (transparent green-purple "F").
 * Output: public/pwa-192x192.png, public/pwa-512x512.png, public/apple-touch-icon.png
 *
 * Each output is a square, transparent canvas with the logo centered and a small
 * safe-zone padding (so maskable PWA icons aren't clipped).
 *
 * Run:  node scripts/generate-icons.cjs
 */
const path = require('path')
const fs = require('fs')

// sharp isn't a direct frontend dependency, but it's installed in the Electron
// apps. Resolve it from whichever location is available.
function resolveSharp() {
  const candidates = [
    'sharp',
    path.join(__dirname, '..', 'node_modules', 'sharp'),
    path.join(__dirname, '..', '..', 'FlowOneChat', 'node_modules', 'sharp'),
    path.join(__dirname, '..', '..', 'FlowOneEmail', 'node_modules', 'sharp'),
    path.join(__dirname, '..', '..', 'FlowOneDrive', 'node_modules', 'sharp'),
  ]
  for (const c of candidates) {
    try {
      return require(c)
    } catch (e) {
      /* try next */
    }
  }
  throw new Error('sharp not found. Install it (npm i -D sharp) or run from a checkout where an Electron app has it.')
}

const sharp = resolveSharp()
const publicDir = path.join(__dirname, '../public')
const MASTER = path.join(publicDir, 'flowone-logo.png')

async function generateIcons() {
  if (!fs.existsSync(MASTER)) throw new Error('Master logo not found: ' + MASTER)

  const transparent = { r: 0, g: 0, b: 0, alpha: 0 }
  const sizes = [
    { name: 'pwa-192x192.png', size: 192, pad: 0.12 },
    { name: 'pwa-512x512.png', size: 512, pad: 0.12 },
    { name: 'apple-touch-icon.png', size: 180, pad: 0.1 },
  ]

  for (const { name, size, pad } of sizes) {
    const inner = Math.round(size * (1 - pad * 2))
    const resized = await sharp(MASTER)
      .resize(inner, inner, { fit: 'contain', background: transparent })
      .toBuffer()
    await sharp({
      create: { width: size, height: size, channels: 4, background: transparent },
    })
      .composite([{ input: resized, gravity: 'center' }])
      .png()
      .toFile(path.join(publicDir, name))
    console.log(`Generated ${name} (${size}x${size})`)
  }

  console.log('All PWA icons generated successfully!')
}

generateIcons().catch((e) => {
  console.error(e)
  process.exit(1)
})
