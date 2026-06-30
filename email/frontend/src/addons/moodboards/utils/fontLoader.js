/**
 * Local font loader & shared font definitions for Moodboards.
 *
 * All fonts are self-hosted -- no external CDN calls.
 * System fonts don't need loading - they're on the OS.
 * Google Fonts have been downloaded locally and are loaded by injecting
 * a <link> to their local CSS file.
 */

const loadedFonts = new Set()
const loadingFonts = new Map()

const SYSTEM_FONTS = new Set([
  'Arial', 'Calibri', 'Times New Roman', 'Georgia', 'Verdana',
  'Trebuchet MS', 'Tahoma', 'Garamond', 'Palatino Linotype',
  'Comic Sans MS', 'Impact', 'Lucida Console', 'Courier New',
  'Segoe UI', 'Consolas', 'Cambria',
])

const FONT_DIR_MAP = {
  'Inter': 'inter',
  'Roboto': 'roboto',
  'Open Sans': 'open-sans',
  'Lato': 'lato',
  'Montserrat': 'montserrat',
  'Poppins': 'poppins',
  'Raleway': 'raleway',
  'Source Sans 3': 'source-sans-3',
  'Nunito': 'nunito',
  'Work Sans': 'work-sans',
  'Outfit': 'outfit',
  'Playfair Display': 'playfair-display',
  'Merriweather': 'merriweather',
  'Lora': 'lora',
  'PT Serif': 'pt-serif',
  'Libre Baskerville': 'libre-baskerville',
  'Oswald': 'oswald',
  'Bebas Neue': 'bebas-neue',
  'Anton': 'anton',
  'Archivo Black': 'archivo-black',
  'Roboto Mono': 'roboto-mono',
  'Source Code Pro': 'source-code-pro',
  'Fira Code': 'fira-code',
  'JetBrains Mono': 'jetbrains-mono',
}

const LOCAL_FONTS = new Set(Object.keys(FONT_DIR_MAP))

/**
 * Load a font by injecting a local stylesheet <link>.
 * Safe to call multiple times - each font is loaded only once.
 */
export function loadGoogleFont(fontName) {
  if (!fontName || SYSTEM_FONTS.has(fontName) || loadedFonts.has(fontName)) return
  if (!LOCAL_FONTS.has(fontName)) return
  if (loadingFonts.has(fontName)) return

  const dir = FONT_DIR_MAP[fontName]
  if (!dir) return

  const url = `/fonts/${dir}/font.css`

  const link = document.createElement('link')
  link.rel = 'stylesheet'
  link.href = url
  link.dataset.moodFont = fontName

  const promise = new Promise((resolve) => {
    link.onload = () => {
      loadedFonts.add(fontName)
      loadingFonts.delete(fontName)
      resolve(true)
    }
    link.onerror = () => {
      loadingFonts.delete(fontName)
      resolve(false)
    }
  })

  loadingFonts.set(fontName, promise)
  document.head.appendChild(link)
  return promise
}

/**
 * Preload all fonts used in a board's items.
 */
export function preloadBoardFonts(items) {
  if (!items?.length) return

  const fonts = new Set()
  for (const item of items) {
    const sd = item.style_data || {}
    if (sd.font_family) fonts.add(sd.font_family)
    if (sd.shape_font_family) fonts.add(sd.shape_font_family)
  }
  for (const font of fonts) {
    loadGoogleFont(font)
  }
}

/**
 * Preload a batch of fonts (e.g. the whole picker list on first open).
 */
export function preloadFontList(fontNames) {
  for (const name of fontNames) {
    loadGoogleFont(name)
  }
}

export function isSystemFont(name) {
  return SYSTEM_FONTS.has(name)
}

/**
 * Shared font groups used across MoodTextToolbar, MoodRightSidebar, MoodInlineFormatBar.
 */
export const FONT_GROUPS = [
  {
    label: 'System Fonts',
    fonts: [
      { label: 'Arial', value: 'Arial' },
      { label: 'Calibri', value: 'Calibri' },
      { label: 'Times New Roman', value: 'Times New Roman' },
      { label: 'Georgia', value: 'Georgia' },
      { label: 'Verdana', value: 'Verdana' },
      { label: 'Trebuchet MS', value: 'Trebuchet MS' },
      { label: 'Tahoma', value: 'Tahoma' },
      { label: 'Garamond', value: 'Garamond' },
      { label: 'Palatino Linotype', value: 'Palatino Linotype' },
      { label: 'Courier New', value: 'Courier New' },
      { label: 'Segoe UI', value: 'Segoe UI' },
      { label: 'Cambria', value: 'Cambria' },
    ]
  },
  {
    label: 'Sans-Serif',
    fonts: [
      { label: 'Inter', value: 'Inter' },
      { label: 'Roboto', value: 'Roboto' },
      { label: 'Open Sans', value: 'Open Sans' },
      { label: 'Lato', value: 'Lato' },
      { label: 'Montserrat', value: 'Montserrat' },
      { label: 'Poppins', value: 'Poppins' },
      { label: 'Raleway', value: 'Raleway' },
      { label: 'Source Sans Pro', value: 'Source Sans 3' },
      { label: 'Nunito', value: 'Nunito' },
      { label: 'Work Sans', value: 'Work Sans' },
      { label: 'Outfit', value: 'Outfit' },
    ]
  },
  {
    label: 'Serif',
    fonts: [
      { label: 'Playfair Display', value: 'Playfair Display' },
      { label: 'Merriweather', value: 'Merriweather' },
      { label: 'Lora', value: 'Lora' },
      { label: 'PT Serif', value: 'PT Serif' },
      { label: 'Libre Baskerville', value: 'Libre Baskerville' },
    ]
  },
  {
    label: 'Display',
    fonts: [
      { label: 'Oswald', value: 'Oswald' },
      { label: 'Bebas Neue', value: 'Bebas Neue' },
      { label: 'Anton', value: 'Anton' },
      { label: 'Archivo Black', value: 'Archivo Black' },
    ]
  },
  {
    label: 'Monospace',
    fonts: [
      { label: 'Roboto Mono', value: 'Roboto Mono' },
      { label: 'Source Code Pro', value: 'Source Code Pro' },
      { label: 'Fira Code', value: 'Fira Code' },
      { label: 'JetBrains Mono', value: 'JetBrains Mono' },
    ]
  },
]

/**
 * Flat list of all font values for quick-pick UIs.
 */
export const ALL_FONT_VALUES = FONT_GROUPS.flatMap(g => g.fonts.map(f => f.value))

/**
 * Quick fonts list for inline format bar (subset of popular ones).
 */
export const QUICK_FONTS = [
  'Arial', 'Calibri', 'Times New Roman', 'Georgia', 'Verdana',
  'Inter', 'Roboto', 'Montserrat', 'Poppins', 'Playfair Display',
  'Merriweather', 'Oswald', 'Bebas Neue', 'Fira Code', 'Lora',
]

/**
 * Find font label from its value.
 */
export function getFontLabel(fontValue) {
  for (const g of FONT_GROUPS) {
    const found = g.fonts.find(f => f.value === fontValue)
    if (found) return found.label
  }
  return fontValue
}

/**
 * Filter font groups by search query.
 */
export function filterFontGroups(groups, query) {
  const q = (query || '').toLowerCase().trim()
  if (!q) return groups
  return groups
    .map(g => ({ ...g, fonts: g.fonts.filter(f => f.label.toLowerCase().includes(q)) }))
    .filter(g => g.fonts.length > 0)
}
