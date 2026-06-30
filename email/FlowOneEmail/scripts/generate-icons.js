/**
 * Icon Generator Script
 * Generates PNG, ICO, and ICNS icons from SVG for Electron app
 * 
 * Run: npm run generate-icons
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

let pngToIco
try {
  const pngToIcoModule = require('png-to-ico')
  pngToIco = pngToIcoModule.default || pngToIcoModule
} catch (e) {
  console.log('Installing png-to-ico for ICO generation...')
  execSync('npm install png-to-ico --save-dev', { stdio: 'inherit' })
  const pngToIcoModule = require('png-to-ico')
  pngToIco = pngToIcoModule.default || pngToIcoModule
}

const assetsDir = path.join(__dirname, '..', 'assets')
const svgPath = path.join(__dirname, '..', 'src', 'renderer', 'public', 'icon.svg')

if (!fs.existsSync(assetsDir)) {
  fs.mkdirSync(assetsDir, { recursive: true })
}

async function generateIcns(pngBuffers) {
  const iconsetDir = path.join(assetsDir, 'icon.iconset')
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

  const svgBuffer = fs.readFileSync(svgPath)

  for (const entry of icnsMapping) {
    let buf = pngBuffers[entry.size]
    if (!buf) {
      buf = await sharp(svgBuffer).resize(entry.size, entry.size).png().toBuffer()
    }
    fs.writeFileSync(path.join(iconsetDir, entry.name), buf)
  }

  try {
    execSync(`iconutil -c icns "${iconsetDir}" -o "${path.join(assetsDir, 'icon.icns')}"`)
    console.log('Created: icon.icns')
  } catch (e) {
    console.warn('iconutil not available (not macOS?) - skipping .icns generation')
  }

  fs.rmSync(iconsetDir, { recursive: true, force: true })
}

async function generateIcons() {
  console.log('Generating icons...')

  const svgBuffer = fs.readFileSync(svgPath)

  const sizes = [16, 24, 32, 48, 64, 128, 256, 512, 1024]
  const pngBuffers = {}

  for (const size of sizes) {
    const pngBuffer = await sharp(svgBuffer)
      .resize(size, size)
      .png()
      .toBuffer()

    pngBuffers[size] = pngBuffer

    const pngPath = path.join(assetsDir, `icon-${size}.png`)
    fs.writeFileSync(pngPath, pngBuffer)
    console.log(`Created: icon-${size}.png`)
  }

  fs.writeFileSync(path.join(assetsDir, 'tray-icon.png'), pngBuffers[32])
  console.log('Created: tray-icon.png')

  fs.writeFileSync(path.join(assetsDir, 'icon.png'), pngBuffers[256])
  console.log('Created: icon.png')

  const icoSizes = [16, 32, 48, 256]
  const icoPngs = icoSizes.map(size => pngBuffers[size])
  const icoBuffer = await pngToIco(icoPngs)
  fs.writeFileSync(path.join(assetsDir, 'icon.ico'), icoBuffer)
  console.log('Created: icon.ico')

  await generateIcns(pngBuffers)

  console.log('\nAll icons generated successfully!')
  console.log(`Output directory: ${assetsDir}`)
}

generateIcons().catch(err => {
  console.error('Error generating icons:', err)
  process.exit(1)
})

