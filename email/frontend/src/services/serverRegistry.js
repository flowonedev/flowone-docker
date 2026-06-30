/**
 * Per-deployment server resolution for the native (Capacitor) apps.
 *
 * One app binary ships to the store, but the same email address can live on
 * two very different backends:
 *
 *   1. The SHARED host (flowone.pro): a single server holds mailboxes for many
 *      domains (pixelranger.hu, kiskonyvecske, ...). Those users log in against
 *      flowone.pro even though their domain is not flowone.pro.
 *   2. A DEDICATED deployment following the convention `email.<domain>`
 *      (robert@magyarszinhaz.hu -> https://email.magyarszinhaz.hu).
 *
 * The email alone cannot tell these apart, so before login we ask a discovery
 * host (`resolveServerBase`) which backend owns the domain, cache the answer,
 * and persist it so every later API + WebSocket call targets the right server.
 * The convention `email.<domain>` is only an offline/last-resort fallback.
 *
 * The discovery host is build-configurable via VITE_DISCOVERY_HOST and defaults
 * to https://flowone.pro for the public build. White-label builds point it at
 * their own server so they never contact flowone.pro.
 *
 * On the browser/web build this module is a no-op: the SPA is served from
 * its own origin, so relative API URLs ('') are already correct and the
 * WebSocket is derived from window.location.
 */

const isNative =
  typeof window !== 'undefined' &&
  !!(window.Capacitor?.isNativePlatform?.())

// localStorage key holding the resolved backend base (e.g. https://email.acme.hu).
const STORAGE_KEY = 'flowone_server_base'

// localStorage key holding a { domain: base } map of previously resolved
// backends, so relaunches and repeat logins resolve instantly and offline.
const CACHE_KEY = 'flowone_server_map'

// Discovery host the native app queries to learn which backend owns a domain.
// Public build -> flowone.pro; white-label builds override at build time.
const DISCOVERY_HOST = String(
  import.meta.env?.VITE_DISCOVERY_HOST || 'https://flowone.pro'
).replace(/\/+$/, '')

const DISCOVERY_TIMEOUT_MS = 6000

/** Lowercased domain part of an email address ('' if not an email). */
function domainPart(email) {
  if (typeof email !== 'string') return ''
  const at = email.lastIndexOf('@')
  if (at === -1) return ''
  return email.slice(at + 1).trim().toLowerCase()
}

/** Build the backend base for an email using the email.<domain> convention. */
function deriveBaseFromEmail(email) {
  const domain = domainPart(email)
  if (!domain) return ''
  return `https://email.${domain}`
}

/** The persisted backend base on native, or '' on web (relative URLs). */
function getServerBase() {
  if (!isNative) return ''
  try {
    return localStorage.getItem(STORAGE_KEY) || ''
  } catch {
    return ''
  }
}

/** Persist the backend base (native only). No-op on web. */
function setServerBase(url) {
  if (!isNative || !url) return
  try {
    localStorage.setItem(STORAGE_KEY, String(url).replace(/\/+$/, ''))
  } catch {
    /* storage may be unavailable */
  }
}

function clearServerBase() {
  try {
    localStorage.removeItem(STORAGE_KEY)
  } catch {
    /* ignore */
  }
}

/** Read the cached backend base for a domain ('' if none). */
function getCachedBase(domain) {
  if (!domain) return ''
  try {
    const map = JSON.parse(localStorage.getItem(CACHE_KEY) || '{}')
    return typeof map[domain] === 'string' ? map[domain] : ''
  } catch {
    return ''
  }
}

/** Remember the resolved backend base for a domain. */
function setCachedBase(domain, base) {
  if (!domain || !base) return
  try {
    const map = JSON.parse(localStorage.getItem(CACHE_KEY) || '{}')
    map[domain] = String(base).replace(/\/+$/, '')
    localStorage.setItem(CACHE_KEY, JSON.stringify(map))
  } catch {
    /* storage may be unavailable */
  }
}

/**
 * Ask the discovery host which backend owns a domain. Returns the backend
 * origin (e.g. https://flowone.pro or https://email.acme.hu) or '' on any
 * failure. Times out so a dead/slow host never blocks the login screen.
 */
async function discover(domain) {
  if (!domain) return ''
  const url = `${DISCOVERY_HOST}/api/server-discovery?domain=${encodeURIComponent(domain)}`
  const controller =
    typeof AbortController !== 'undefined' ? new AbortController() : null
  const timer = controller
    ? setTimeout(() => controller.abort(), DISCOVERY_TIMEOUT_MS)
    : null
  try {
    const res = await fetch(url, {
      method: 'GET',
      headers: { Accept: 'application/json' },
      signal: controller?.signal,
    })
    if (!res.ok) return ''
    const data = await res.json()
    const apiUrl = data?.api_url ?? data?.data?.api_url
    return typeof apiUrl === 'string' ? apiUrl.replace(/\/+$/, '') : ''
  } catch {
    return ''
  } finally {
    if (timer) clearTimeout(timer)
  }
}

/**
 * Resolve and persist the backend base for an email (native only).
 * Order: live discovery -> cached answer -> email.<domain> convention.
 * No-op on web (returns '' so relative URLs keep hitting the serving origin).
 */
async function resolveServerBase(email) {
  if (!isNative) return ''
  const domain = domainPart(email)
  if (!domain) return getServerBase()

  let base = await discover(domain)
  if (!base) base = getCachedBase(domain) || deriveBaseFromEmail(email)

  if (base) {
    setServerBase(base)
    setCachedBase(domain, base)
  }
  return base
}

/**
 * Origin prefix to prepend to "/api/..." calls.
 * - Web: '' (relative -> hits the serving origin).
 * - Native: the persisted per-deployment base (empty until first login).
 */
function getApiOrigin() {
  return isNative ? getServerBase() : ''
}

/**
 * Origin to build PUBLIC/share URLs a user copies and opens in a browser
 * (guest office links, /share/<token>, public download links, etc.).
 * - Web: window.location.origin (the serving origin).
 * - Native: the persisted per-deployment base (https://flowone.pro or
 *   https://email.<domain>). NEVER the WebView's capacitor://localhost origin —
 *   that produces unopenable links. Falls back to the window origin only if the
 *   base isn't resolved yet (shouldn't happen once signed in).
 */
function getPublicOrigin() {
  if (isNative) {
    const base = getServerBase()
    if (base) return base.replace(/\/+$/, '')
  }
  if (typeof window !== 'undefined' && window.location?.origin) {
    return window.location.origin
  }
  return ''
}

/**
 * Full mailsync WebSocket URL for the current deployment.
 * - Native: derived from the persisted base ('' until first login).
 * - Web: derived from window.location so each deployment connects to itself.
 */
function getWsUrl() {
  if (isNative) {
    const base = getServerBase()
    return base ? base.replace(/^http/i, 'ws') + '/mailsync_ws' : ''
  }
  if (typeof window !== 'undefined' && window.location?.host) {
    const proto = window.location.protocol === 'https:' ? 'wss' : 'ws'
    return `${proto}://${window.location.host}/mailsync_ws`
  }
  return ''
}

export {
  isNative,
  domainPart,
  deriveBaseFromEmail,
  getServerBase,
  setServerBase,
  clearServerBase,
  getCachedBase,
  setCachedBase,
  resolveServerBase,
  getApiOrigin,
  getPublicOrigin,
  getWsUrl,
}
