/**
 * Regenerate every brand SVG source from the master FlowOne logo.
 *
 * Master: email/frontend/public/flowone-logo.png (transparent green-purple "F").
 *
 * This writes:
 *   - Electron app icon sources (square, padded, transparent) used by each
 *     app's own `npm run generate-icons` to rasterize PNG/ICO/ICNS.
 *   - Browser favicon.svg files (small embedded PNG, transparent).
 *   - OnlyOffice editor branding logos (F + "FlowOne" wordmark, dark/light).
 *
 * The logo PNG is embedded as a base64 data URI so the SVGs are self-contained
 * and render identically in browsers, resvg (sharp), and OnlyOffice (Chromium).
 *
 * Run from the repo root:  node tools/generate-brand-assets.cjs
 */
const fs = require('fs')
const path = require('path')

const ROOT = process.cwd()
const MASTER = path.join(ROOT, 'email', 'frontend', 'public', 'flowone-logo.png')

function resolveSharp() {
  const candidates = ['email/FlowOneChat', 'email/FlowOneEmail', 'email/FlowOneDrive']
  for (const c of candidates) {
    try {
      return require(path.join(ROOT, c, 'node_modules', 'sharp'))
    } catch (e) {
      /* try next */
    }
  }
  throw new Error('sharp not found in any FlowOne* app node_modules')
}

const sharp = resolveSharp()

// Square icon source (app icons). Transparent, padded, tall "F" centered.
const squareSvg = (b64) =>
  `<svg xmlns="http://www.w3.org/2000/svg" width="512" height="512" viewBox="0 0 512 512">\n` +
  `  <image href="data:image/png;base64,${b64}" x="56" y="40" width="400" height="432" preserveAspectRatio="xMidYMid meet"/>\n` +
  `</svg>\n`

// Browser favicon (served to browsers, keep small).
const faviconSvg = (b64) =>
  `<svg xmlns="http://www.w3.org/2000/svg" width="64" height="64" viewBox="0 0 64 64">\n` +
  `  <image href="data:image/png;base64,${b64}" x="4" y="2" width="56" height="60" preserveAspectRatio="xMidYMid meet"/>\n` +
  `</svg>\n`

// OnlyOffice header logo: "F" mark + FlowOne wordmark. Dark/light text variants.
const officeSvg = (b64, textColor) =>
  `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 432 86" width="432" height="86">\n` +
  `  <image href="data:image/png;base64,${b64}" x="6" y="3" width="80" height="80" preserveAspectRatio="xMidYMid meet"/>\n` +
  `  <text x="98" y="58" font-family="Arial, Helvetica, sans-serif" font-size="44" font-weight="700" fill="${textColor}" letter-spacing="0.5">FlowOne</text>\n` +
  `</svg>\n`

function write(rel, content) {
  const p = path.join(ROOT, rel)
  if (!fs.existsSync(path.dirname(p))) {
    console.warn('  SKIP (missing dir):', rel)
    return
  }
  fs.writeFileSync(p, content)
  console.log('  wrote', rel)
}

async function main() {
  if (!fs.existsSync(MASTER)) throw new Error('Master logo not found: ' + MASTER)
  const masterBuf = fs.readFileSync(MASTER)
  const meta = await sharp(masterBuf).metadata()
  console.log(`Master logo: ${meta.width}x${meta.height} (alpha=${meta.hasAlpha})`)

  // Full resolution for build-time icon sources (crisp up to 1024px).
  const fullB64 = masterBuf.toString('base64')
  // Downscaled for files that ship to the browser / editor.
  const smallBuf = await sharp(masterBuf).resize({ height: 256, fit: 'inside' }).png().toBuffer()
  const smallB64 = smallBuf.toString('base64')

  console.log('\nElectron app icon sources:')
  write('email/FlowOneChat/assets/icon.svg', squareSvg(fullB64))
  write('email/FlowOneEmail/src/renderer/public/icon.svg', squareSvg(fullB64))
  write('email/FlowOneDrive/assets/icon.svg', squareSvg(fullB64))
  write('email/FlowOneDrive/assets/tray-icon.svg', squareSvg(fullB64))

  console.log('\nFavicons:')
  write('email/landing/favicon.svg', faviconSvg(smallB64))
  write('email/FlowOneChat/src/renderer/public/favicon.svg', faviconSvg(smallB64))
  write('email/FlowOneChatMobile/src/public/favicon.svg', faviconSvg(smallB64))
  write('email/FlowOneEmail/src/renderer/public/favicon.svg', faviconSvg(smallB64))

  console.log('\nOnlyOffice branding:')
  write('email/office/branding/flowone-logo-dark.svg', officeSvg(smallB64, '#1F2937'))
  write('email/office/branding/flowone-logo-light.svg', officeSvg(smallB64, '#FFFFFF'))

  console.log('\nDone. Now run `npm run generate-icons` in each Electron app to rebuild rasters.')
}

main().catch((err) => {
  console.error('Error generating brand assets:', err)
  process.exit(1)
})
