/*
 * Post-build: copy the shared frontend's web fonts into the chat app's dist.
 *
 * Vite's `publicDir` is the chat app's own `src/public` (which only holds the
 * favicon), so the app-shell fonts — including the Material Symbols icon font
 * that every `material-symbols-rounded` glyph depends on — never make it into
 * the native bundle. Without this, `index.html`'s `./fonts/core.css` 404s and
 * ALL icons disappear in the Capacitor app.
 *
 * The fonts live in the shared frontend at `email/frontend/public/fonts`.
 */
import { cp, access } from 'node:fs/promises'
import { fileURLToPath } from 'node:url'
import { dirname, resolve } from 'node:path'

const __dirname = dirname(fileURLToPath(import.meta.url))
const src = resolve(__dirname, '../../frontend/public/fonts')
const dest = resolve(__dirname, '../dist/fonts')

try {
  await access(src)
} catch {
  console.error(`[copy-fonts] ERROR: shared fonts not found at ${src}`)
  process.exit(1)
}

await cp(src, dest, { recursive: true })
console.log(`[copy-fonts] Copied fonts -> ${dest}`)
