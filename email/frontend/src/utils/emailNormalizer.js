/**
 * Frontend mirror of Webmail\Utils\EmailNormalizer (PHP).
 *
 * Used by:
 *   - MentionAutocomplete (so the address the suggestion popup writes into
 *     the body matches the canonical form the backend stores).
 *   - Compose recipient auto-add (de-duping a mention with an existing
 *     To/Cc entry before pushing it onto the list).
 *
 * Intentionally NOT a 1:1 byte-for-byte port: JS doesn't have native
 * Punycode in every runtime. We use the URL constructor for IDN → ASCII
 * conversion, which is implemented natively in every browser we support.
 * The result is the same canonical form modulo IDN-edge cases.
 */

const EMAIL_REGEX = /^[^\s@]+@[^\s@]+\.[^\s@]+$/

/**
 * @param {string|null|undefined} raw
 * @returns {string|null} canonical lowercase address, or null if invalid
 */
export function normalizeEmail(raw) {
  if (raw == null) return null
  let s = String(raw).trim()
  if (!s) return null

  // Strip display name: "Alice <a@x.y>" → "a@x.y"
  const angle = s.match(/<([^>]+)>/)
  if (angle) s = angle[1]
  s = s.replace(/^[\s"'<]+|[\s"'>]+$/g, '')

  const at = s.lastIndexOf('@')
  if (at <= 0 || at === s.length - 1) return null

  let local = s.slice(0, at).toLowerCase()
  let domain = s.slice(at + 1).toLowerCase()

  // IDN → Punycode via URL parsing. We construct a throwaway URL with the
  // domain as host; the URL constructor punycodes it for us. Safe on every
  // modern browser; fall back to the raw lowercase domain on failure.
  try {
    const u = new URL(`http://${domain}`)
    if (u.hostname) domain = u.hostname
  } catch {
    /* keep lowercase domain as-is */
  }

  const out = `${local}@${domain}`
  if (!EMAIL_REGEX.test(out)) return null
  if (out.length > 255) return null
  return out
}

/** @param {string} raw */
export function isValidEmail(raw) {
  return normalizeEmail(raw) !== null
}

/** @param {string} raw */
export function domainOf(raw) {
  const norm = normalizeEmail(raw)
  if (!norm) return null
  const at = norm.lastIndexOf('@')
  return at < 0 ? null : norm.slice(at + 1)
}

export function isSameMailbox(a, b) {
  const na = normalizeEmail(a)
  const nb = normalizeEmail(b)
  return na !== null && nb !== null && na === nb
}

/**
 * Pull every email-looking token from a free-form string (e.g. a header
 * value). Returns canonical normalised, dedup-preserved-order.
 *
 * @param {string} text
 * @returns {string[]}
 */
export function extractEmails(text) {
  if (!text) return []
  const m = String(text).match(/[\w.+\-]+@[\w.\-]+\.[a-zA-Z]{2,}/g)
  if (!m) return []
  const out = []
  const seen = new Set()
  for (const addr of m) {
    const n = normalizeEmail(addr)
    if (!n) continue
    if (seen.has(n)) continue
    seen.add(n)
    out.push(n)
  }
  return out
}
