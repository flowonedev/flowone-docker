/**
 * Email Content Processor
 * Processes email HTML to:
 * - Sanitize HTML (strip scripts, event handlers, etc.)
 * - Embed YouTube/Vimeo videos
 * - Block remote images for privacy/security
 * - Rewrite image URLs to backend proxy for privacy
 *
 * All post-sanitize processing operates on the DOM (not on serialized HTML
 * strings). Attribute values read from the DOM are entity-decoded, which is
 * what fixes the historic `&amp;` -> `%26amp%3B` corruption in proxied image
 * URLs (Instagram/Facebook CDN signatures failed and every image broke), and
 * prevents URL-matching regexes from ever splicing markup into attributes.
 */
import DOMPurify from 'dompurify'
import { getApiOrigin } from './serverRegistry'

const PURIFY_CONFIG = {
  ALLOWED_TAGS: [
    'html', 'head', 'body', 'div', 'span', 'p', 'br', 'hr', 'pre', 'blockquote',
    'b', 'i', 'u', 'strong', 'em', 'small', 'sub', 'sup', 's', 'strike', 'del', 'ins', 'mark',
    'font', 'center',
    'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
    'ul', 'ol', 'li', 'dl', 'dt', 'dd',
    'table', 'thead', 'tbody', 'tfoot', 'tr', 'th', 'td', 'caption', 'col', 'colgroup',
    'a', 'img', 'figure', 'figcaption', 'picture', 'source', 'video', 'audio',
    'iframe',
    'article', 'section', 'aside', 'header', 'footer', 'nav', 'main', 'details', 'summary',
    'style',
  ],
  ALLOWED_ATTR: [
    'href', 'src', 'alt', 'title', 'width', 'height', 'style', 'class', 'id', 'name',
    'target', 'rel', 'colspan', 'rowspan', 'cellpadding', 'cellspacing', 'border',
    'align', 'valign', 'bgcolor', 'color', 'face', 'size', 'dir', 'lang',
    'data-original-src', 'data-video-id',
    'frameborder', 'allowfullscreen', 'allow', 'loading',
    'type', 'media',
  ],
  ALLOW_DATA_ATTR: true,
  ADD_ATTR: ['target'],
}

// NOTE: deliberately NOT using DOMPurify.setConfig(). A persistent config
// makes DOMPurify IGNORE all per-call configs (so WHOLE_DOCUMENT/RETURN_DOM
// below would silently not apply) and leaks the email config into every
// other DOMPurify.sanitize() caller in the app (e.g. DriveView markdown).

// Resolved per call so native apps hit their own deployment (email.<domain>).
const imageProxyBase = () => `${getApiOrigin()}/api/mailbox/image-proxy?url=`

// Anchored variants for matching an <a href> value (href must BE the video link).
const YOUTUBE_HREF_REGEX = /^(?:https?:\/\/)?(?:www\.)?(?:youtube\.com\/(?:watch\?v=|embed\/|v\/)|youtu\.be\/)([a-zA-Z0-9_-]{11})/
const VIMEO_HREF_REGEX = /^(?:https?:\/\/)?(?:www\.)?vimeo\.com\/(\d+)(?:[?#]|$)/

// Global variants for scanning plain text nodes.
const YOUTUBE_TEXT_REGEX = /(?:https?:\/\/)?(?:www\.)?(?:youtube\.com\/(?:watch\?v=|embed\/|v\/)|youtu\.be\/)([a-zA-Z0-9_-]{11})(?:[&?][^\s<"']*)?/g
const VIMEO_TEXT_REGEX = /(?:https?:\/\/)?(?:www\.)?vimeo\.com\/(\d+)(?:[?&][^\s<"']*)?/g

// Our own open-tracking pixels (stripped so reading your own sent mail
// doesn't fire the tracker).
const TRACKING_PIXEL_REGEX = /\/api\/track\/[a-f0-9]+\.gif$/i

const REMOTE_SRC_REGEX = /^https?:\/\//i

// Tags that imply the body already carries its own line/paragraph structure.
const STRUCTURAL_TAG_REGEX = /<\s*(br|p|div|table|tr|td|th|li|ul|ol|blockquote|h[1-6]|article|section|pre|dl|dt|dd)\b/gi

/**
 * Some emails ship a `text/html` part that is really just plain text: real
 * newlines with no `<br>`/block tags. Rendered with `white-space: normal`
 * (the iframe default) every newline collapses into a single space, turning a
 * quoted reply chain into one unreadable wall of text.
 *
 * When the HTML looks text-like (newlines present, structural tags absent or
 * negligible) convert its newlines to `<br>`. Conversion only touches text
 * segments between tags so it never corrupts markup, and genuine structured
 * HTML is returned untouched.
 */
function normalizeTextLikeHtml(html) {
  if (!html) return html

  const newlineCount = (html.match(/\r\n|\r|\n/g) || []).length
  if (newlineCount < 2) return html

  // Stylesheets/scripts only appear in real structured HTML documents.
  if (/<\s*(style|script)\b/i.test(html)) return html

  const structuralCount = (html.match(STRUCTURAL_TAG_REGEX) || []).length
  // If the body already has enough structure, leave it alone. Treat it as
  // text-like only when newlines vastly outnumber structural tags.
  if (structuralCount >= newlineCount / 4) return html

  const parts = html.split(/(<[^>]+>)/g)
  return parts.map(part => {
    if (part.startsWith('<')) return part
    return part.replace(/\r\n|\r|\n/g, '<br>\n')
  }).join('')
}

function escapeAttribute(value) {
  return String(value)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/"/g, '&quot;')
}

/**
 * Parse an HTML snippet into a DocumentFragment owned by `doc`.
 * Only used for our OWN static embed markup, never for email content.
 */
function htmlToFragment(doc, html) {
  const template = doc.createElement('template')
  template.innerHTML = html
  return template.content
}

/**
 * Strip our own tracking pixels.
 */
function stripTrackingPixels(root) {
  for (const img of root.querySelectorAll('img[src]')) {
    if (TRACKING_PIXEL_REGEX.test(img.getAttribute('src') || '')) {
      img.remove()
    }
  }
}

/**
 * Block remote images by replacing them with placeholders.
 * Keeps inline/embedded images (data: and cid: URLs).
 */
function blockRemoteImages(root) {
  for (const img of Array.from(root.querySelectorAll('img[src]'))) {
    const src = img.getAttribute('src') || ''
    if (!REMOTE_SRC_REGEX.test(src)) continue
    const span = img.ownerDocument.createElement('span')
    span.className = 'blocked-image'
    span.setAttribute('data-original-src', encodeURIComponent(src))
    span.setAttribute('title', 'Image blocked for privacy')
    span.innerHTML = createImagePlaceholder()
    img.replaceWith(span)
  }
}

function createImagePlaceholder() {
  return `<svg viewBox="0 0 100 80" style="width: 80px; height: 64px; display: inline-block; vertical-align: middle; background: #f3f4f6; border-radius: 4px; margin: 2px;">
    <rect width="100" height="80" fill="#e5e7eb"/>
    <path d="M35 50 L50 35 L65 50 L55 50 L55 55 L45 55 L45 50 Z" fill="#9ca3af"/>
    <circle cx="65" cy="30" r="8" fill="#9ca3af"/>
  </svg>`
}

/**
 * Rewrite remote image src URLs to go through the backend proxy.
 * This prevents the sender from seeing the user's IP/referrer.
 */
function proxyRemoteImages(root) {
  for (const img of root.querySelectorAll('img[src]')) {
    const src = img.getAttribute('src') || ''
    if (!REMOTE_SRC_REGEX.test(src)) continue
    img.setAttribute('src', imageProxyBase() + encodeURIComponent(src))
  }
}

function createYouTubeEmbed(videoId, title = 'YouTube Video') {
  const safeTitle = escapeAttribute(title)
  return `
    <div class="youtube-embed" style="margin: 1rem 0; max-width: 100%;">
      <div style="position: relative; width: 100%; padding-bottom: 56.25%;">
        <iframe 
          style="position: absolute; top: 0; left: 0; width: 100%; height: 100%; border-radius: 0.5rem;"
          src="https://www.youtube.com/embed/${videoId}?rel=0"
          title="${safeTitle}"
          frameborder="0"
          allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share"
          allowfullscreen
        ></iframe>
      </div>
      <p style="font-size: 0.75rem; color: #6b7280; margin-top: 0.25rem;">
        <a href="https://www.youtube.com/watch?v=${videoId}" target="_blank" rel="noopener noreferrer" style="text-decoration: none;">
          Open in YouTube
        </a>
      </p>
    </div>
  `
}

function createVimeoEmbed(videoId) {
  return `
    <div class="vimeo-embed" style="margin: 1rem 0; max-width: 100%;">
      <div style="position: relative; width: 100%; padding-bottom: 56.25%;">
        <iframe 
          style="position: absolute; top: 0; left: 0; width: 100%; height: 100%; border-radius: 0.5rem;"
          src="https://player.vimeo.com/video/${videoId}"
          frameborder="0"
          allow="autoplay; fullscreen; picture-in-picture"
          allowfullscreen
        ></iframe>
      </div>
    </div>
  `
}

/**
 * Replace <a> elements whose href IS a YouTube/Vimeo video link with an
 * inline player. Only text-only anchors are converted; anchors wrapping
 * images (common in marketing mail) are left as links.
 */
function embedVideoAnchors(root) {
  for (const anchor of Array.from(root.querySelectorAll('a[href]'))) {
    if (anchor.children.length > 0) continue
    const href = anchor.getAttribute('href') || ''

    const youtube = href.match(YOUTUBE_HREF_REGEX)
    if (youtube) {
      const title = (anchor.textContent || '').trim() || 'YouTube Video'
      anchor.replaceWith(htmlToFragment(anchor.ownerDocument, createYouTubeEmbed(youtube[1], title)))
      continue
    }

    const vimeo = href.match(VIMEO_HREF_REGEX)
    if (vimeo) {
      anchor.replaceWith(htmlToFragment(anchor.ownerDocument, createVimeoEmbed(vimeo[1])))
    }
  }
}

/**
 * Find bare YouTube/Vimeo URLs in text nodes and replace them with embeds.
 * Working on text nodes (instead of the serialized HTML) guarantees URLs
 * inside attributes are never touched.
 */
function embedPlainTextVideoUrls(root) {
  const doc = root.ownerDocument
  const walker = doc.createTreeWalker(root, NodeFilter.SHOW_TEXT)
  const textNodes = []
  while (walker.nextNode()) textNodes.push(walker.currentNode)

  for (const node of textNodes) {
    if (node.parentElement && node.parentElement.closest('a, style')) continue
    const fragment = buildVideoFragment(doc, node.nodeValue || '')
    if (fragment) node.replaceWith(fragment)
  }
}

/**
 * Build a fragment mixing the original text with video embeds, or null when
 * the text contains no video URLs.
 */
function buildVideoFragment(doc, text) {
  const matches = []
  for (const m of text.matchAll(YOUTUBE_TEXT_REGEX)) {
    matches.push({ index: m.index, length: m[0].length, html: createYouTubeEmbed(m[1]) })
  }
  for (const m of text.matchAll(VIMEO_TEXT_REGEX)) {
    matches.push({ index: m.index, length: m[0].length, html: createVimeoEmbed(m[1]) })
  }
  if (matches.length === 0) return null

  matches.sort((a, b) => a.index - b.index)

  const fragment = doc.createDocumentFragment()
  let pos = 0
  for (const match of matches) {
    if (match.index < pos) continue
    if (match.index > pos) {
      fragment.appendChild(doc.createTextNode(text.slice(pos, match.index)))
    }
    fragment.appendChild(htmlToFragment(doc, match.html))
    pos = match.index + match.length
  }
  if (pos < text.length) {
    fragment.appendChild(doc.createTextNode(text.slice(pos)))
  }
  return fragment
}

/**
 * Sanitizing with WHOLE_DOCUMENT keeps the email's `<head><style>` (preheader
 * hiding, responsive/dark-mode rules) which a body-only sanitize discards.
 * This flattens that sanitized document back into a fragment the iframe can
 * inject into its own `<body>`, hoisting the `<style>` blocks to the front so
 * they still apply (`<style>` is valid and active inside `<body>`).
 */
function flattenSanitizedDom(dom) {
  const styles = Array.from(dom.querySelectorAll('style'))
  const styleHtml = styles.map(style => style.outerHTML).join('\n')
  styles.forEach(style => style.remove())

  const body = dom.tagName === 'BODY' ? dom : dom.querySelector('body')
  const bodyHtml = body ? body.innerHTML : dom.innerHTML

  return styleHtml ? `${styleHtml}\n${bodyHtml}` : bodyHtml
}

/**
 * Process email HTML content for display inside an iframe.
 * Pipeline: DOMPurify sanitize (to DOM) -> strip tracking pixels ->
 * block/proxy images -> embed videos -> flatten/serialize once.
 *
 * @param {string} html - Raw email HTML
 * @param {Object} options
 * @param {boolean} options.blockRemoteImages - Block remote images with placeholder
 */
export function processEmailContent(html, options = {}) {
  if (!html) return html

  const normalized = normalizeTextLikeHtml(html)
  // Sanitize as a whole document so the email's <head><style> survives.
  // RETURN_DOM lets every later step read entity-DECODED attribute values;
  // string-level regex post-processing saw `&amp;` entities and corrupted
  // proxied URLs.
  const dom = DOMPurify.sanitize(normalized, {
    ...PURIFY_CONFIG,
    WHOLE_DOCUMENT: true,
    RETURN_DOM: true,
  })

  stripTrackingPixels(dom)

  if (options.blockRemoteImages) {
    blockRemoteImages(dom)
  } else {
    proxyRemoteImages(dom)
  }

  embedVideoAnchors(dom)
  embedPlainTextVideoUrls(dom)

  return flattenSanitizedDom(dom)
}

export function isVideoFormatSupported(filename) {
  const supportedFormats = ['.mp4', '.webm', '.ogg', '.ogv', '.m4v']
  const ext = filename?.toLowerCase().slice(filename.lastIndexOf('.'))
  return supportedFormats.includes(ext)
}

export function getVideoMimeType(filename) {
  const ext = filename?.toLowerCase().slice(filename.lastIndexOf('.'))
  const mimeTypes = {
    '.mp4': 'video/mp4',
    '.webm': 'video/webm',
    '.ogg': 'video/ogg',
    '.ogv': 'video/ogg',
    '.m4v': 'video/mp4',
    '.mov': 'video/quicktime',
    '.avi': 'video/x-msvideo',
    '.wmv': 'video/x-ms-wmv',
    '.mkv': 'video/x-matroska',
  }
  return mimeTypes[ext] || 'video/mp4'
}

export function getUnsupportedFormatMessage(filename) {
  const ext = filename?.toLowerCase().slice(filename.lastIndexOf('.')) || ''
  return `${ext.toUpperCase().replace('.', '')} format cannot be played in browser. Download to view with a video player.`
}

export default {
  processEmailContent,
  isVideoFormatSupported,
  getVideoMimeType,
  getUnsupportedFormatMessage,
}
