/**
 * Article display helpers.
 *
 * Some RSS feeds (microblogs, podcast feeds, video feeds, malformed RSS)
 * ship items with empty `<title>` elements — the body lives in the
 * description instead. We never want to show a blank row, so derive a
 * display title from whatever fields are available.
 */

export function stripHtml(html) {
  if (!html) return ''
  return String(html)
    .replace(/<style[\s\S]*?<\/style>/gi, '')
    .replace(/<script[\s\S]*?<\/script>/gi, '')
    .replace(/<[^>]*>/g, ' ')
    .replace(/&nbsp;/g, ' ')
    .replace(/&amp;/g, '&')
    .replace(/&lt;/g, '<')
    .replace(/&gt;/g, '>')
    .replace(/&quot;/g, '"')
    .replace(/&#39;/g, "'")
    .replace(/\s+/g, ' ')
    .trim()
}

/**
 * Best-effort display title for an article. Falls back through:
 *   1. Real `title`
 *   2. First sentence of summary/content (HTML stripped)
 *   3. First sentence of `content_text`
 *
 * Returns null when nothing usable exists — caller can render a localized
 * "(untitled)" placeholder.
 */
export function articleDisplayTitle(article) {
  if (!article) return null
  const t = String(article.title || '').trim()
  if (t) return t

  const summary = stripHtml(article.summary || article.content_html || '')
  const fromSummary = pickShortSentence(summary)
  if (fromSummary) return fromSummary

  const text = String(article.content_text || '').trim()
  const fromText = pickShortSentence(text)
  if (fromText) return fromText

  return null
}

function pickShortSentence(text) {
  if (!text) return ''
  // Take the first sentence if it ends with punctuation in a sensible
  // length window; otherwise truncate at a word boundary.
  const stop = text.search(/[.!?](?=\s|$)/)
  if (stop > 20 && stop < 140) {
    return text.slice(0, stop + 1)
  }
  if (text.length <= 110) return text
  const cut = text.slice(0, 110)
  const lastSpace = cut.lastIndexOf(' ')
  return (lastSpace > 60 ? cut.slice(0, lastSpace) : cut) + '…'
}
