const CLIPBOARD_MARKER = '_flowone_moodboard_clipboard'
const CLIPBOARD_VERSION = 2
const HTML_DATA_ATTR = 'data-flowone-clip'

export function serializeForClipboard(items, connections = []) {
  return JSON.stringify({
    [CLIPBOARD_MARKER]: true,
    version: CLIPBOARD_VERSION,
    items,
    connections,
  })
}

/**
 * Parse FlowOne clipboard data from either HTML (preferred) or plain text.
 * Accepts the raw string from either `text/html` or `text/plain`.
 */
export function parseFromClipboard(text) {
  if (!text || typeof text !== 'string') return null

  const htmlResult = extractFromHtml(text)
  if (htmlResult) return htmlResult

  return extractFromJson(text)
}

function extractFromHtml(html) {
  const m = html.match(/data-flowone-clip="([^"]*)"/)
  if (!m) return null
  try {
    const json = decodeURIComponent(m[1])
    return extractFromJson(json)
  } catch { return null }
}

function extractFromJson(text) {
  try {
    const data = JSON.parse(text)
    if (!data?.[CLIPBOARD_MARKER] || !Array.isArray(data.items) || !data.items.length) return null
    return { items: data.items, connections: data.connections || [] }
  } catch { return null }
}

/**
 * Write items to the system clipboard using ClipboardItem API.
 * JSON is hidden inside text/html (data attribute) so it never shows
 * when pasting into text fields. text/plain gets a human-readable summary.
 */
export async function writeToSystemClipboard(items, connections = []) {
  const json = serializeForClipboard(items, connections)
  const encoded = encodeURIComponent(json)
  const label = items.length === 1
    ? (items[0].title || items[0].type || 'item')
    : `${items.length} items`
  const html = `<span ${HTML_DATA_ATTR}="${encoded}">${label} (FlowOne)</span>`
  const plain = `${label} (FlowOne)`

  try {
    const item = new ClipboardItem({
      'text/html': new Blob([html], { type: 'text/html' }),
      'text/plain': new Blob([plain], { type: 'text/plain' }),
    })
    await navigator.clipboard.write([item])
  } catch {
    try { await navigator.clipboard.writeText(json) } catch {}
  }
}
