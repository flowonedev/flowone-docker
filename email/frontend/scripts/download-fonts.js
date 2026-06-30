/**
 * Downloads ALL required fonts from Google Fonts for self-hosting.
 * Eliminates ALL external CDN dependencies.
 *
 * Run: node scripts/download-fonts.js
 *
 * Downloads:
 * - Core app fonts: Inter, Outfit, JetBrains Mono
 * - Icon fonts: Material Symbols Rounded, Material Symbols Outlined
 * - Moodboard picker fonts: Roboto, Open Sans, Lato, Montserrat, Poppins,
 *   Raleway, Source Sans 3, Nunito, Work Sans, Playfair Display, Merriweather,
 *   Lora, PT Serif, Libre Baskerville, Oswald, Bebas Neue, Anton,
 *   Archivo Black, Roboto Mono, Source Code Pro, Fira Code
 */
import { mkdirSync, writeFileSync, existsSync } from 'fs'
import { resolve, dirname } from 'path'
import { fileURLToPath } from 'url'
import https from 'https'

const __dirname = dirname(fileURLToPath(import.meta.url))
const FONTS_DIR = resolve(__dirname, '../public/fonts')

const FONTS = [
  // Icon fonts
  {
    name: 'Material Symbols Rounded',
    cssUrl: 'https://fonts.googleapis.com/css2?family=Material+Symbols+Rounded:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200',
    dir: 'material-symbols-rounded',
  },
  {
    name: 'Material Symbols Outlined',
    cssUrl: 'https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200',
    dir: 'material-symbols-outlined',
  },
  // Core app fonts
  {
    name: 'Inter',
    cssUrl: 'https://fonts.googleapis.com/css2?family=Inter:wght@100;200;300;400;500;600;700;800;900&display=swap',
    dir: 'inter',
  },
  {
    name: 'Outfit',
    cssUrl: 'https://fonts.googleapis.com/css2?family=Outfit:wght@100;200;300;400;500;600;700;800;900&display=swap',
    dir: 'outfit',
  },
  {
    name: 'JetBrains Mono',
    cssUrl: 'https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@100;200;300;400;500;600;700;800&display=swap',
    dir: 'jetbrains-mono',
  },
  // Moodboard picker fonts
  {
    name: 'Roboto',
    cssUrl: 'https://fonts.googleapis.com/css2?family=Roboto:wght@100;300;400;500;700;900&display=swap',
    dir: 'roboto',
  },
  {
    name: 'Open Sans',
    cssUrl: 'https://fonts.googleapis.com/css2?family=Open+Sans:wght@300;400;500;600;700;800&display=swap',
    dir: 'open-sans',
  },
  {
    name: 'Lato',
    cssUrl: 'https://fonts.googleapis.com/css2?family=Lato:wght@100;300;400;700;900&display=swap',
    dir: 'lato',
  },
  {
    name: 'Montserrat',
    cssUrl: 'https://fonts.googleapis.com/css2?family=Montserrat:wght@100;200;300;400;500;600;700;800;900&display=swap',
    dir: 'montserrat',
  },
  {
    name: 'Poppins',
    cssUrl: 'https://fonts.googleapis.com/css2?family=Poppins:wght@100;200;300;400;500;600;700;800;900&display=swap',
    dir: 'poppins',
  },
  {
    name: 'Raleway',
    cssUrl: 'https://fonts.googleapis.com/css2?family=Raleway:wght@100;200;300;400;500;600;700;800;900&display=swap',
    dir: 'raleway',
  },
  {
    name: 'Source Sans 3',
    cssUrl: 'https://fonts.googleapis.com/css2?family=Source+Sans+3:wght@200;300;400;500;600;700;800;900&display=swap',
    dir: 'source-sans-3',
  },
  {
    name: 'Nunito',
    cssUrl: 'https://fonts.googleapis.com/css2?family=Nunito:wght@200;300;400;500;600;700;800;900&display=swap',
    dir: 'nunito',
  },
  {
    name: 'Work Sans',
    cssUrl: 'https://fonts.googleapis.com/css2?family=Work+Sans:wght@100;200;300;400;500;600;700;800;900&display=swap',
    dir: 'work-sans',
  },
  {
    name: 'Playfair Display',
    cssUrl: 'https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;500;600;700;800;900&display=swap',
    dir: 'playfair-display',
  },
  {
    name: 'Merriweather',
    cssUrl: 'https://fonts.googleapis.com/css2?family=Merriweather:wght@300;400;700;900&display=swap',
    dir: 'merriweather',
  },
  {
    name: 'Lora',
    cssUrl: 'https://fonts.googleapis.com/css2?family=Lora:wght@400;500;600;700&display=swap',
    dir: 'lora',
  },
  {
    name: 'PT Serif',
    cssUrl: 'https://fonts.googleapis.com/css2?family=PT+Serif:wght@400;700&display=swap',
    dir: 'pt-serif',
  },
  {
    name: 'Libre Baskerville',
    cssUrl: 'https://fonts.googleapis.com/css2?family=Libre+Baskerville:wght@400;700&display=swap',
    dir: 'libre-baskerville',
  },
  {
    name: 'Oswald',
    cssUrl: 'https://fonts.googleapis.com/css2?family=Oswald:wght@200;300;400;500;600;700&display=swap',
    dir: 'oswald',
  },
  {
    name: 'Bebas Neue',
    cssUrl: 'https://fonts.googleapis.com/css2?family=Bebas+Neue&display=swap',
    dir: 'bebas-neue',
  },
  {
    name: 'Anton',
    cssUrl: 'https://fonts.googleapis.com/css2?family=Anton&display=swap',
    dir: 'anton',
  },
  {
    name: 'Archivo Black',
    cssUrl: 'https://fonts.googleapis.com/css2?family=Archivo+Black&display=swap',
    dir: 'archivo-black',
  },
  {
    name: 'Roboto Mono',
    cssUrl: 'https://fonts.googleapis.com/css2?family=Roboto+Mono:wght@100;200;300;400;500;600;700&display=swap',
    dir: 'roboto-mono',
  },
  {
    name: 'Source Code Pro',
    cssUrl: 'https://fonts.googleapis.com/css2?family=Source+Code+Pro:wght@200;300;400;500;600;700;800;900&display=swap',
    dir: 'source-code-pro',
  },
  {
    name: 'Fira Code',
    cssUrl: 'https://fonts.googleapis.com/css2?family=Fira+Code:wght@300;400;500;600;700&display=swap',
    dir: 'fira-code',
  },
  // DM Sans (used in some landing pages)
  {
    name: 'DM Sans',
    cssUrl: 'https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&display=swap',
    dir: 'dm-sans',
  },
  // Instrument Serif/Sans (used in some landing pages)
  {
    name: 'Instrument Serif',
    cssUrl: 'https://fonts.googleapis.com/css2?family=Instrument+Serif:wght@400&display=swap',
    dir: 'instrument-serif',
  },
  {
    name: 'Instrument Sans',
    cssUrl: 'https://fonts.googleapis.com/css2?family=Instrument+Sans:wght@400;500;600;700&display=swap',
    dir: 'instrument-sans',
  },
]

function fetch(url) {
  return new Promise((resolve, reject) => {
    const req = https.get(url, {
      headers: {
        'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
      },
    }, (res) => {
      if (res.statusCode >= 300 && res.statusCode < 400 && res.headers.location) {
        return fetch(res.headers.location).then(resolve, reject)
      }
      const chunks = []
      res.on('data', (c) => chunks.push(c))
      res.on('end', () => resolve(Buffer.concat(chunks)))
      res.on('error', reject)
    })
    req.on('error', reject)
  })
}

async function downloadFont(font) {
  const fontDir = resolve(FONTS_DIR, font.dir)
  if (!existsSync(fontDir)) mkdirSync(fontDir, { recursive: true })

  console.log(`\n[${font.name}] Fetching CSS...`)
  const css = (await fetch(font.cssUrl)).toString('utf8')

  const woff2Urls = [...css.matchAll(/url\((https:\/\/fonts\.gstatic\.com\/[^)]+\.woff2)\)/g)]
    .map(m => m[1])

  if (woff2Urls.length === 0) {
    console.error(`  No woff2 URLs found for ${font.name}`)
    return false
  }

  console.log(`  Found ${woff2Urls.length} woff2 file(s)`)

  let totalSize = 0
  let localCss = css
  for (let i = 0; i < woff2Urls.length; i++) {
    const filename = woff2Urls.length === 1 ? 'font.woff2' : `font-${i}.woff2`
    console.log(`  Downloading ${filename} (${i + 1}/${woff2Urls.length})...`)
    const data = await fetch(woff2Urls[i])
    writeFileSync(resolve(fontDir, filename), data)
    totalSize += data.length
    localCss = localCss.replace(woff2Urls[i], `/fonts/${font.dir}/${filename}`)
  }

  const isIconFont = font.name.startsWith('Material Symbols')
  if (isIconFont) {
    localCss = localCss.replace(/font-display:\s*swap/g, 'font-display: block')
  }

  writeFileSync(resolve(fontDir, 'font.css'), localCss)
  console.log(`  Total: ${(totalSize / 1024).toFixed(1)} KB -- saved to fonts/${font.dir}/`)
  return true
}

async function downloadTailwindCdn() {
  const jsDir = resolve(__dirname, '../public/js')
  if (!existsSync(jsDir)) mkdirSync(jsDir, { recursive: true })

  console.log('\n[Tailwind CDN] Downloading standalone script...')
  const data = await fetch('https://cdn.tailwindcss.com/3.4.17')
  const outPath = resolve(jsDir, 'tailwind.min.js')
  writeFileSync(outPath, data)
  console.log(`  Saved ${outPath} (${(data.length / 1024).toFixed(1)} KB)`)
}

async function main() {
  if (!existsSync(FONTS_DIR)) {
    mkdirSync(FONTS_DIR, { recursive: true })
    console.log(`Created ${FONTS_DIR}`)
  }

  let ok = 0
  let fail = 0
  for (const font of FONTS) {
    try {
      const success = await downloadFont(font)
      if (success) ok++; else fail++
    } catch (err) {
      console.error(`  FAILED: ${font.name} -- ${err.message}`)
      fail++
    }
  }

  try {
    await downloadTailwindCdn()
    ok++
  } catch (err) {
    console.error(`  FAILED: Tailwind CDN -- ${err.message}`)
    fail++
  }

  console.log(`\n========================================`)
  console.log(`Done! ${ok} assets downloaded, ${fail} failed.`)
  console.log(`Font files saved to: public/fonts/`)
  console.log(`Tailwind saved to: public/js/tailwind.min.js`)
  console.log(`\nRemember to rebuild (npm run build) and deploy.`)
}

main().catch((err) => {
  console.error('Font download failed:', err)
  process.exit(1)
})
