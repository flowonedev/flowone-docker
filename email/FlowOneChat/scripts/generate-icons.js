/**
 * Generate PNG, ICO, and ICNS icon files from the SVG source for FlowOneChat.
 * Run: node scripts/generate-icons.js
 */
const fs = require('fs')
const path = require('path')
const { execSync } = require('child_process')

let sharp
try {
  sharp = require('sharp')
} catch (e) {
  console.log('Installing sharp for image processing...')
  execSync('npm install sharp --save-dev', { stdio: 'inherit' })
  sharp = require('sharp')
}

const ASSETS_DIR = path.join(__dirname, '..', 'assets')
const SVG_ICON = path.join(ASSETS_DIR, 'icon.svg')
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

async function generateIcns(pngBuffersBySize) {
  const iconsetDir = path.join(ASSETS_DIR, 'icon.iconset')
  if (!fs.existsSync(iconsetDir)) {
    fs.mkdirSync(iconsetDir, { recursive: true })
  }

  const icnsMapping = [
    { name: 'icon_16x16.png', size: 16 },
    { name: 'icon_16x16@2x.png', size: 32 },
    { name: 'icon_32x32.png', size: 32 },
    { name: 'icon_32x32@2x.png', size: 64 },
    { name: 'icon_128x128.png', size: 128 },
    { name: 'icon_128x128@2x.png', size: 256 },
    { name: 'icon_256x256.png', size: 256 },
    { name: 'icon_256x256@2x.png', size: 512 },
    { name: 'icon_512x512.png', size: 512 },
    { name: 'icon_512x512@2x.png', size: 1024 },
  ]

  const svgBuffer = fs.readFileSync(SVG_ICON)

  for (const entry of icnsMapping) {
    let buf = pngBuffersBySize[entry.size]
    if (!buf) {
      buf = await sharp(svgBuffer).resize(entry.size, entry.size).png().toBuffer()
    }
    fs.writeFileSync(path.join(iconsetDir, entry.name), buf)
  }

  try {
    execSync(`iconutil -c icns "${iconsetDir}" -o "${path.join(ASSETS_DIR, 'icon.icns')}"`)
    console.log('  icon.icns')
  } catch (e) {
    console.warn('  iconutil not available (not macOS?) - skipping .icns generation')
  }

  fs.rmSync(iconsetDir, { recursive: true, force: true })
}

async function main() {
  console.log('Generating FlowOneChat icons from SVG...')

  const svgBuffer = fs.readFileSync(SVG_ICON)
  const pngBuffersBySize = {}

  for (const size of SIZES) {
    const buf = await sharp(svgBuffer)
      .resize(size, size)
      .png()
      .toBuffer()
    pngBuffersBySize[size] = buf

    const outFile = path.join(ASSETS_DIR, `icon-${size}.png`)
    fs.writeFileSync(outFile, buf)
    console.log(`  icon-${size}.png`)
  }

  fs.writeFileSync(path.join(ASSETS_DIR, 'icon.png'), pngBuffersBySize[256])
  console.log('  icon.png (256x256)')

  const icoSizes = [16, 32, 48, 64, 128, 256]
  const icoPngs = icoSizes.map(size => pngBuffersBySize[size])
  const icoBuffer = createIco(icoPngs)
  fs.writeFileSync(path.join(ASSETS_DIR, 'icon.ico'), icoBuffer)
  console.log('  icon.ico (multi-size)')

  await generateIcns(pngBuffersBySize)

  console.log('\nDone! All icons generated in assets/')
}

main().catch(err => {
  console.error('Error generating icons:', err)
  process.exit(1)
})
