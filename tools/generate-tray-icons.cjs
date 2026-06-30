/**
 * Regenerate every Electron system-tray icon variant from the master FlowOne
 * logo. The per-app `generate-icons` scripts only rebuild the main app icon and
 * a single tray-icon.png, leaving the .ico / color / alert / template tray
 * variants as the old (green) art. This rebuilds all of them.
 *
 *   - plain variants    -> the transparent green-purple "F"
 *   - *alert* variants  -> the "F" with a small red notification badge
 *   - *Template* (mac)  -> a flat black silhouette of the "F" (alpha preserved)
 *
 * Only files that already exist are overwritten (never create new orphans).
 *
 * Run from the repo root:  node tools/generate-tray-icons.cjs
 */
const fs = require('fs')
const path = require('path')

const ROOT = process.cwd()
const MASTER = path.join(ROOT, 'email', 'frontend', 'public', 'flowone-logo.png')

function resolveSharp() {
  for (const c of ['email/FlowOneChat', 'email/FlowOneEmail', 'email/FlowOneDrive']) {
    try {
      return require(path.join(ROOT, c, 'node_modules', 'sharp'))
    } catch (e) {
      /* next */
    }
  }
  throw new Error('sharp not found')
}
const sharp = resolveSharp()

const TRANSPARENT = { r: 0, g: 0, b: 0, alpha: 0 }

// Build a minimal ICO (PNG-embedded; supported by modern Windows) from PNG buffers.
function createIco(pngBuffers) {
  const headerSize = 6
  const dirEntrySize = 16
  const n = pngBuffers.length
  const dataOffset = headerSize + dirEntrySize * n
  let total = 0
  for (const b of pngBuffers) total += b.length
  const ico = Buffer.alloc(dataOffset + total)
  ico.writeUInt16LE(0, 0)
  ico.writeUInt16LE(1, 2)
  ico.writeUInt16LE(n, 4)
  let offset = dataOffset
  for (let i = 0; i < n; i++) {
    const buf = pngBuffers[i]
    const w = buf.readUInt32BE(16)
    const h = buf.readUInt32BE(20)
    const e = headerSize + i * dirEntrySize
    ico.writeUInt8(w >= 256 ? 0 : w, e)
    ico.writeUInt8(h >= 256 ? 0 : h, e + 1)
    ico.writeUInt8(0, e + 2)
    ico.writeUInt8(0, e + 3)
    ico.writeUInt16LE(1, e + 4)
    ico.writeUInt16LE(32, e + 6)
    ico.writeUInt32LE(buf.length, e + 8)
    ico.writeUInt32LE(offset, e + 12)
    buf.copy(ico, offset)
    offset += buf.length
  }
  return ico
}

async function plain(size) {
  return sharp(MASTER)
    .resize(size, size, { fit: 'contain', background: TRANSPARENT })
    .png()
    .toBuffer()
}

async function alert(size) {
  const base = await plain(size)
  const r = Math.max(2, Math.round(size * 0.26))
  const cx = size - r
  const cy = size - r
  const badge = Buffer.from(
    `<svg xmlns="http://www.w3.org/2000/svg" width="${size}" height="${size}">` +
      `<circle cx="${cx}" cy="${cy}" r="${r}" fill="#ef4444" stroke="#ffffff" stroke-width="${Math.max(1, Math.round(size * 0.04))}"/>` +
      `</svg>`
  )
  return sharp(base).composite([{ input: badge }]).png().toBuffer()
}

async function template(size) {
  const base = await plain(size)
  const { data, info } = await sharp(base).ensureAlpha().raw().toBuffer({ resolveWithObject: true })
  for (let i = 0; i < data.length; i += 4) {
    data[i] = 0
    data[i + 1] = 0
    data[i + 2] = 0
  }
  return sharp(data, { raw: { width: info.width, height: info.height, channels: 4 } }).png().toBuffer()
}

// name -> { kind, size } | { kind, ico: [sizes] }
const SPEC = {
  'tray-icon.png': { kind: 'plain', size: 32 },
  'tray-icon@2x.png': { kind: 'plain', size: 64 },
  'tray-icon-16.png': { kind: 'plain', size: 16 },
  'tray-icon.ico': { kind: 'plain', ico: [16, 32, 48] },
  'tray-icon-color.png': { kind: 'plain', size: 22 },
  'tray-icon-color@2x.png': { kind: 'plain', size: 44 },
  'tray-icon-alert.png': { kind: 'alert', size: 32 },
  'tray-icon-alert@2x.png': { kind: 'alert', size: 64 },
  'tray-icon-alert.ico': { kind: 'alert', ico: [16, 32, 48] },
  'tray-iconAlert.png': { kind: 'alert', size: 32 },
  'tray-iconAlert@2x.png': { kind: 'alert', size: 64 },
  'tray-iconTemplate.png': { kind: 'template', size: 22 },
  'tray-iconTemplate@2x.png': { kind: 'template', size: 44 },
}

const make = { plain, alert, template }

async function bufFor(kind, size) {
  return make[kind](size)
}

async function processDir(assetsRel) {
  const dir = path.join(ROOT, assetsRel)
  if (!fs.existsSync(dir)) return
  for (const [name, spec] of Object.entries(SPEC)) {
    const target = path.join(dir, name)
    if (!fs.existsSync(target)) continue // never create new files
    if (spec.ico) {
      const pngs = []
      for (const s of spec.ico) pngs.push(await bufFor(spec.kind, s))
      fs.writeFileSync(target, createIco(pngs))
    } else {
      fs.writeFileSync(target, await bufFor(spec.kind, spec.size))
    }
    console.log('  wrote', path.join(assetsRel, name))
  }
}

async function main() {
  if (!fs.existsSync(MASTER)) throw new Error('Master logo not found: ' + MASTER)
  for (const app of ['email/FlowOneChat/assets', 'email/FlowOneEmail/assets', 'email/FlowOneDrive/assets']) {
    console.log(app + ':')
    await processDir(app)
  }
  console.log('\nTray icons regenerated.')
}

main().catch((e) => {
  console.error(e)
  process.exit(1)
})
