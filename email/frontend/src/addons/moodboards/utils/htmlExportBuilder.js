/**
 * htmlExportBuilder.js
 *
 * Generates a self-contained HTML file from a moodboard group, frame,
 * or selected items. Uses existing CSS generation + semantic HTML tag
 * inference from item names.
 */
import {
  generateGroupCss,
  generateFullCss,
  generateCssClassBlocks,
  parseComponentName,
  toCssName,
  getItemCssName,
  isBackgroundItem,
} from './cssInspectUtils'

// ── Semantic tag resolution ──

const CONTAINER_RULES = [
  [/\bnav(igation)?\b/, 'nav'],
  [/\bheader\b(?!.*\bnav)/, 'header'],
  [/\bfooter\b/, 'footer'],
  [/\bsection\b/, 'section'],
  [/\bmain\b/, 'main'],
  [/\b(aside|sidebar)\b/, 'aside'],
  [/\barticle\b/, 'article'],
  [/\bform\b/, 'form'],
  [/\bul\b|\blist\b|\bmenu\b/, 'ul'],
  [/\bfigure\b/, 'figure'],
]

const TEXT_RULES = [
  [/\bh1\b|hero[-_]?title|main[-_]?title/, 'h1'],
  [/\bh2\b|section[-_]?title/, 'h2'],
  [/\bh3\b|sub[-_]?title|subtitle/, 'h3'],
  [/\bh4\b/, 'h4'],
  [/\bh5\b/, 'h5'],
  [/\bh6\b/, 'h6'],
  [/\blabel\b/, 'label'],
  [/\b(span|badge|tag|chip)\b/, 'span'],
  [/\b(caption|figcaption)\b/, 'figcaption'],
  [/\b(blockquote|quote)\b/, 'blockquote'],
]

const BUTTON_RE = /\b(btn|button|cta)\b/

function resolveHtmlTag(item) {
  const names = (item.title || '').toLowerCase()
  const type = item.type

  if (type === 'group' || type === 'frame' || type === 'repeat_grid' || type === 'slide') {
    for (const [re, tag] of CONTAINER_RULES) {
      if (re.test(names)) return tag
    }
    return 'div'
  }

  if (type === 'text') {
    if (BUTTON_RE.test(names)) return 'a'
    for (const [re, tag] of TEXT_RULES) {
      if (re.test(names)) return tag
    }
    return inferHeadingFromSize(item)
  }

  if (type === 'shape' || type === 'pen_shape') {
    if (BUTTON_RE.test(names)) return 'a'
    if (/\b(divider|separator|hr)\b/.test(names)) return 'hr'
    return 'div'
  }

  if (type === 'image') return 'img'
  if (type === 'video') return 'video'
  if (type === 'line') return 'hr'

  return 'div'
}

function inferHeadingFromSize(item) {
  const sd = item.style_data || {}
  const size = sd.font_size || sd.shape_font_size || 14
  if (size >= 48) return 'h1'
  if (size >= 32) return 'h2'
  if (size >= 24) return 'h3'
  return 'p'
}

// ── Class string from item title ──

function fallbackClass(item) {
  const isBg = item.style_data?.is_background
  const prefix = isBg ? '_bg' : `_${item.type || 'el'}`
  return `${prefix}-${String(item.id || '').slice(-6)}`
}

function buildClassAttr(item) {
  const parsed = parseComponentName(item.title)
  if (!parsed.classes.length) return fallbackClass(item)
  return parsed.classes.map(c => toCssName(c)).join(' ')
}

// ── Sanitize text content ──

function sanitizeContent(html) {
  if (!html) return ''
  return html
    .replace(/<script\b[^>]*>[\s\S]*?<\/script>/gi, '')
    .replace(/\son\w+\s*=\s*["'][^"']*["']/gi, '')
    .replace(/javascript\s*:/gi, '')
}

function plainText(content) {
  if (!content) return ''
  if (/<[a-z][\s\S]*>/i.test(content)) return sanitizeContent(content)
  return content
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/\n/g, '<br>')
}

// ── Collect local font links ──

const FONT_DIR_MAP = {
  'Inter': 'inter', 'Roboto': 'roboto', 'Open Sans': 'open-sans',
  'Lato': 'lato', 'Montserrat': 'montserrat', 'Poppins': 'poppins',
  'Raleway': 'raleway', 'Source Sans 3': 'source-sans-3', 'Nunito': 'nunito',
  'Work Sans': 'work-sans', 'Outfit': 'outfit',
  'Playfair Display': 'playfair-display', 'Merriweather': 'merriweather',
  'Lora': 'lora', 'PT Serif': 'pt-serif', 'Libre Baskerville': 'libre-baskerville',
  'Oswald': 'oswald', 'Bebas Neue': 'bebas-neue', 'Anton': 'anton',
  'Archivo Black': 'archivo-black', 'Roboto Mono': 'roboto-mono',
  'Source Code Pro': 'source-code-pro', 'Fira Code': 'fira-code',
  'JetBrains Mono': 'jetbrains-mono',
}

const SKIP_FONTS = new Set([
  'Arial', 'Helvetica', 'sans-serif', 'serif', 'monospace', 'system-ui',
])

function collectFonts(items) {
  const needed = new Set()
  for (const item of items) {
    const sd = item.style_data || {}
    for (const f of [sd.font_family, sd.shape_font_family].filter(Boolean)) {
      if (!SKIP_FONTS.has(f) && FONT_DIR_MAP[f]) needed.add(f)
    }
  }
  if (!needed.size) return ''
  const links = [...needed].map(f =>
    `<link rel="stylesheet" href="/fonts/${FONT_DIR_MAP[f]}/font.css">`
  )
  return links.join('\n  ')
}

// ── Resolve image URLs to absolute ──

function resolveUrl(url) {
  if (!url) return ''
  if (url.startsWith('http://') || url.startsWith('https://') || url.startsWith('data:')) return url
  if (url.startsWith('/')) return `${window.location.origin}${url}`
  return url
}

function resolveAllUrls(css) {
  return css.replace(/url\(['"]?([^'")]+)['"]?\)/g, (match, url) => {
    return `url('${resolveUrl(url)}')`
  })
}

// ── Build HTML tree recursively ──

function buildHtmlTree(item, allItems, depth = 0) {
  if (isBackgroundItem(item, { _isExport: true })) return ''

  const pad = '  '.repeat(depth)
  const tag = resolveHtmlTag(item)
  const classes = buildClassAttr(item)
  const classStr = classes ? ` class="${classes}"` : ''

  if (tag === 'hr') return `${pad}<hr${classStr} />`

  if (item.type === 'image') {
    const alt = item.title || 'image'
    const src = resolveUrl(item.image_url)
    return `${pad}<img${classStr} src="${esc(src)}" alt="${esc(alt)}" loading="lazy" />`
  }

  if (tag === 'video') {
    const src = resolveUrl(item.url || item.image_url)
    return `${pad}<video${classStr} src="${esc(src)}" autoplay muted loop playsinline></video>`
  }

  const isContainer = ['group', 'frame', 'repeat_grid', 'slide'].includes(item.type)
  if (isContainer) {
    const children = allItems
      .filter(c => c.parent_id === item.id)
      .sort((a, b) => (a.z_index || 0) - (b.z_index || 0))

    const attrs = buildContainerAttrs(tag, item)
    const inner = children
      .map(c => {
        const childHtml = buildHtmlTree(c, allItems, depth + (tag === 'ul' ? 2 : 1))
        if (tag === 'ul') return `${pad}  <li>\n${childHtml}\n${pad}  </li>`
        return childHtml
      })
      .filter(Boolean)
      .join('\n')

    if (!inner) return `${pad}<${tag}${classStr}${attrs}></${tag}>`
    return `${pad}<${tag}${classStr}${attrs}>\n${inner}\n${pad}</${tag}>`
  }

  if (tag === 'a') {
    const content = getButtonContent(item, allItems)
    return `${pad}<a${classStr} href="#">${content}</a>`
  }

  if (item.type === 'text') {
    const content = plainText(item.content)
    return `${pad}<${tag}${classStr}>${content}</${tag}>`
  }

  if ((item.type === 'shape' || item.type === 'pen_shape') && item.style_data?.shape_text) {
    return `${pad}<${tag}${classStr}>${esc(item.style_data.shape_text)}</${tag}>`
  }

  return `${pad}<${tag}${classStr}></${tag}>`
}

function buildContainerAttrs(tag, item) {
  if (tag === 'nav') return ' aria-label="Navigation"'
  if (tag === 'form') return ' action="#" method="post"'
  return ''
}

function getButtonContent(item, allItems) {
  if (item.content) return plainText(item.content)
  if (item.style_data?.shape_text) return esc(item.style_data.shape_text)
  const textChild = allItems.find(c =>
    c.parent_id === item.id && c.type === 'text' && c.content
  )
  if (textChild) return plainText(textChild.content)
  return item.title || 'Button'
}

function esc(str) {
  if (!str) return ''
  return str.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;')
}

// ── CSS generation (reuses existing utilities) ──

function isContainerType(type) {
  return type === 'group' || type === 'repeat_grid' || type === 'frame' || type === 'slide'
}

function generateExportCss(rootItem, allItems, options) {
  const exportOpts = { ...options, _isExport: true }
  const parts = []

  function generateRecursive(item, isRoot) {
    const children = allItems.filter(c => c.parent_id === item.id)
    if (!isContainerType(item.type)) {
      if (!isRoot) return
      parts.push(generateFullCss(item, exportOpts))
      return
    }

    const opts = isRoot
      ? { ...exportOpts, includeGlobals: true, _isRootExport: true }
      : { ...exportOpts, includeGlobals: false }
    const css = generateGroupCss(item, children, opts)
    if (css) parts.push(css)

    for (const child of children) {
      if (isContainerType(child.type)) {
        generateRecursive(child, false)
      }
    }
  }

  generateRecursive(rootItem, true)

  const allDescendants = getDescendants(rootItem.id, allItems)
  const classBlocks = [rootItem, ...allDescendants]
    .map(c => generateCssClassBlocks(c, options.globalCssClasses))
    .filter(Boolean)

  return [...parts, ...classBlocks].join('\n\n')
}

const REM_BASE = 16
function remToPx(css) {
  return css.replace(/(\d+\.?\d*)rem\b/g, (_, num) => {
    return `${Math.round(parseFloat(num) * REM_BASE)}px`
  })
}

// ── Base CSS reset ──

const BASE_CSS = `*, *::before, *::after {
  margin: 0;
  padding: 0;
  box-sizing: border-box;
}
body {
  min-height: 100vh;
  -webkit-font-smoothing: antialiased;
  -moz-osx-font-smoothing: grayscale;
}
img, video {
  max-width: 100%;
  display: block;
}
a {
  text-decoration: none;
  color: inherit;
  display: inline-flex;
  align-items: center;
  justify-content: center;
}`

// ── Main export builder ──

export function buildHtmlExport(rootItem, allItems, options = {}) {
  const {
    globalColors = [],
    globalGradients = [],
    globalTextStyles = [],
    globalCssClasses = [],
    boardName = 'Export',
  } = options

  const cssOpts = { globalColors, globalGradients, globalTextStyles, globalCssClasses }

  const rawCss = generateExportCss(rootItem, allItems, cssOpts)
  const css = resolveAllUrls(remToPx(rawCss))

  const descendants = getDescendants(rootItem.id, allItems)
  const allRelevant = [rootItem, ...descendants]
  const fontLink = collectFonts(allRelevant)

  const html = buildHtmlTree(rootItem, allItems, 1)

  const title = rootItem.title || boardName

  return `<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>${esc(title)}</title>
  ${fontLink}
  <style>
${indentBlock(BASE_CSS, 4)}

${indentBlock(css, 4)}
  </style>
</head>
<body>
${html}
</body>
</html>`
}

function getDescendants(parentId, allItems) {
  const children = allItems.filter(i => i.parent_id === parentId)
  const result = [...children]
  for (const child of children) {
    result.push(...getDescendants(child.id, allItems))
  }
  return result
}

function indentBlock(css, spaces) {
  if (!css) return ''
  const pad = ' '.repeat(spaces)
  return css.split('\n').map(l => `${pad}${l}`).join('\n')
}

export function downloadHtmlFile(content, filename) {
  const blob = new Blob([content], { type: 'text/html;charset=utf-8' })
  const url = URL.createObjectURL(blob)
  const a = document.createElement('a')
  a.href = url
  a.download = filename
  document.body.appendChild(a)
  a.click()
  document.body.removeChild(a)
  URL.revokeObjectURL(url)
}
