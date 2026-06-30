/**
 * Generate PNG, ICO, and ICNS icon files for FlowOneDrive.
 *
 * Source: assets/icon-source.png (the master app icon, ideally >= 1024x1024).
 *
 * Outputs (all into assets/):
 *   - icon-16..1024.png            raw size variants
 *   - icon.png                     256x256 (app window + notification icon)
 *   - icon.ico                     Windows multi-size app icon
 *   - icon.icns                    macOS app icon (pure-Node writer, runs on any OS)
 *   - tray-icon.png / tray-icon-16.png    color tray base (dev + fallback)
 *   - tray-icon-color.png / @2x    color tray (Windows/Linux)
 *   - tray-icon.ico                Windows tray icon
 *   - tray-iconTemplate.png / @2x  monochrome macOS menu-bar template (derived from the colored icon)
 *
 * Run: node scripts/generate-icons.js
 */
const sharp = require('sharp')
const fs = require('fs')
const path = require('path')

const ASSETS_DIR = path.join(__dirname, '..', 'assets')
const SOURCE_ICON = path.join(ASSETS_DIR, 'icon-source.png')
const TRANSPARENT = { r: 0, g: 0, b: 0, alpha: 0 }

const SIZES = [16, 24, 32, 48, 64, 128, 256, 512, 1024]

function createIco(pngBuffers) {
  const numImages = pngBuffers.length
  const headerSize = 6
  const dirEntrySize = 16
  const dirSize = dirEntrySize * numImages
  const dataOffset = headerSize + dirSize

  let totalDataSize = 0
  for (const buf of pngBuffers) totalDataSize += buf.length

  const ico = Buffer.alloc(headerSize + dirSize + totalDataSize)

  ico.writeUInt16LE(0, 0)
  ico.writeUInt16LE(1, 2)
  ico.writeUInt16LE(numImages, 4)

  let currentDataOffset = dataOffset
  for (let i = 0; i < numImages; i++) {
    const pngBuf = pngBuffers[i]
    const width = pngBuf.readUInt32BE(16)
    const height = pngBuf.readUInt32BE(20)
    const entryOffset = headerSize + (i * dirEntrySize)

    ico.writeUInt8(width >= 256 ? 0 : width, entryOffset)
    ico.writeUInt8(height >= 256 ? 0 : height, entryOffset + 1)
    ico.writeUInt8(0, entryOffset + 2)
    ico.writeUInt8(0, entryOffset + 3)
    ico.writeUInt16LE(1, entryOffset + 4)
    ico.writeUInt16LE(32, entryOffset + 6)
    ico.writeUInt32LE(pngBuf.length, entryOffset + 8)
    ico.writeUInt32LE(currentDataOffset, entryOffset + 12)

    pngBuf.copy(ico, currentDataOffset)
    currentDataOffset += pngBuf.length
  }

  return ico
}

// Pack PNG-encoded icons into a macOS .icns container. PNG entries are supported
// by macOS 10.7+ and electron-builder, so this needs no iconutil and runs on any OS.
function createIcns(pngBuffersBySize) {
  const typeForSize = [
    ['icp4', 16],
    ['icp5', 32],
    ['icp6', 64],
    ['ic07', 128],
    ['ic08', 256],
    ['ic09', 512],
    ['ic10', 1024],
    ['ic11', 32],
    ['ic12', 64],
    ['ic13', 256],
    ['ic14', 512],
  ]

  const chunks = []
  for (const [type, size] of typeForSize) {
    const png = pngBuffersBySize[size]
    if (!png) continue
    const header = Buffer.alloc(8)
    header.write(type, 0, 'ascii')
    header.writeUInt32BE(png.length + 8, 4)
    chunks.push(header, png)
  }

  const body = Buffer.concat(chunks)
  const fileHeader = Buffer.alloc(8)
  fileHeader.write('icns', 0, 'ascii')
  fileHeader.writeUInt32BE(body.length + 8, 4)
  return Buffer.concat([fileHeader, body])
}

function pngAt(size) {
  return sharp(SOURCE_ICON)
    .resize(size, size, { fit: 'contain', background: TRANSPARENT })
    .png()
    .toBuffer()
}

// Black silhouette + original alpha, for macOS menu-bar template icons.
async function templateAt(size) {
  const alpha = await sharp(SOURCE_ICON)
    .resize(size, size, { fit: 'contain', background: TRANSPARENT })
    .ensureAlpha()
    .extractChannel('alpha')
    .raw()
    .toBuffer()

  return sharp({ create: { width: size, height: size, channels: 3, background: { r: 0, g: 0, b: 0 } } })
    .joinChannel(alpha, { raw: { width: size, height: size, channels: 1 } })
    .png()
    .toBuffer()
}

async function main() {
  if (!fs.existsSync(SOURCE_ICON)) {
    console.error(`Source icon not found: ${SOURCE_ICON}`)
    console.error('Place the master app icon there (PNG, ideally 1024x1024) and re-run.')
    process.exit(1)
  }

  const meta = await sharp(SOURCE_ICON).metadata()
  console.log(`Generating FlowOneDrive icons from icon-source.png (${meta.width}x${meta.height})...`)
  if ((meta.width || 0) < 1024 || (meta.height || 0) < 1024) {
    console.warn('  WARNING: source is smaller than 1024x1024 - large icons will be upscaled and may look soft.')
  }

  const pngBuffersBySize = {}
  for (const size of SIZES) {
    const buf = await pngAt(size)
    pngBuffersBySize[size] = buf
    fs.writeFileSync(path.join(ASSETS_DIR, `icon-${size}.png`), buf)
    console.log(`  icon-${size}.png`)
  }

  fs.writeFileSync(path.join(ASSETS_DIR, 'icon.png'), pngBuffersBySize[256])
  console.log('  icon.png (256x256)')

  const icoSizes = [16, 32, 48, 64, 128, 256]
  fs.writeFileSync(path.join(ASSETS_DIR, 'icon.ico'), createIco(icoSizes.map(s => pngBuffersBySize[s])))
  console.log('  icon.ico (multi-size)')

  fs.writeFileSync(path.join(ASSETS_DIR, 'icon.icns'), createIcns(pngBuffersBySize))
  console.log('  icon.icns (cross-platform PNG container)')

  // --- Color tray icons (Windows/Linux + dev base) ---
  fs.writeFileSync(path.join(ASSETS_DIR, 'tray-icon.png'), pngBuffersBySize[32])
  console.log('  tray-icon.png (32x32)')
  fs.writeFileSync(path.join(ASSETS_DIR, 'tray-icon-16.png'), pngBuffersBySize[16])
  console.log('  tray-icon-16.png (16x16)')
  fs.writeFileSync(path.join(ASSETS_DIR, 'tray-icon-color.png'), pngBuffersBySize[32])
  fs.writeFileSync(path.join(ASSETS_DIR, 'tray-icon-color@2x.png'), pngBuffersBySize[64])
  console.log('  tray-icon-color.png / @2x')
  fs.writeFileSync(
    path.join(ASSETS_DIR, 'tray-icon.ico'),
    createIco([pngBuffersBySize[16], pngBuffersBySize[32], pngBuffersBySize[48]])
  )
  console.log('  tray-icon.ico')

  // --- Monochrome template tray icon (macOS menu bar) ---
  fs.writeFileSync(path.join(ASSETS_DIR, 'tray-iconTemplate.png'), await templateAt(16))
  fs.writeFileSync(path.join(ASSETS_DIR, 'tray-iconTemplate@2x.png'), await templateAt(32))
  console.log('  tray-iconTemplate.png / @2x (monochrome)')

  console.log('\nDone! All icons generated in assets/')
}

main().catch(err => {
  console.error('Error generating icons:', err)
  process.exit(1)
})
