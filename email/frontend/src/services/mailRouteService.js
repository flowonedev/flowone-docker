/**
 * mailRouteService - canonical home for mail route URL composition + parsing.
 *
 * Wave 3 services split. Owns:
 *   - building the canonical `<slug>--<folder_id>` route segment
 *   - parsing legacy `<folder>` segments and resolving them to a folder_id
 *   - 301-redirect targets for legacy URLs
 *
 * Routing pattern:
 *   /m/<slug>--<folder_id>
 *   /m/<slug>--<folder_id>/<uid>
 *
 * The slug is purely cosmetic (URL-friendly display name); the folder_id
 * is the source of truth. If only the slug matches an old folder the
 * service will look up the path-history table on the backend (via the
 * resolveLegacyPath() helper) and 301 to the canonical URL.
 *
 * Feature flag: routes are only emitted in canonical form when
 * `canonicalFolderRoutingEnabled` returns true (off / compare / on);
 * see ff_canonical_folder_routing in the email-life.md runbook.
 */

import {
  normalizeFolderPath,
  resolveFolderId,
  findFolder,
  folderDisplayName,
} from '@/services/folderIdentityService'

/** Canonical separator between slug and folder_id. Two dashes; reserved. */
const SLUG_ID_SEPARATOR = '--'

/** Lowercase, ASCII-fold, non-alphanum -> dash, collapse repeats. */
export function slugify(text) {
  if (!text) return 'folder'
  return String(text)
    .normalize('NFKD')
    .replace(/[\u0300-\u036f]/g, '')
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-+|-+$/g, '')
    .slice(0, 64) || 'folder'
}

/**
 * Build the canonical URL segment for a folder.
 * Returns null when no folder_id is available; callers should fall back
 * to the legacy form when this happens.
 */
export function buildCanonicalSegment(folder) {
  if (!folder?.folder_id) return null
  const slug = slugify(folderDisplayName(folder))
  return `${slug}${SLUG_ID_SEPARATOR}${folder.folder_id}`
}

/**
 * Parse a route segment that might be canonical (`slug--folder_id`) or
 * legacy (just the folder path). Returns
 * `{ form: 'canonical'|'legacy', slug, folderId, folder, uid }`.
 */
export function parseRouteSegment(segment) {
  if (typeof segment !== 'string' || segment === '') {
    return { form: 'legacy', slug: null, folderId: null, folder: '', uid: null }
  }

  const idx = segment.lastIndexOf(SLUG_ID_SEPARATOR)
  if (idx > 0) {
    const slug = segment.slice(0, idx)
    const tail = segment.slice(idx + SLUG_ID_SEPARATOR.length)
    if (looksLikeUuid(tail)) {
      return { form: 'canonical', slug, folderId: tail, folder: null, uid: null }
    }
  }
  return { form: 'legacy', slug: null, folderId: null, folder: decodeURIComponent(segment), uid: null }
}

function looksLikeUuid(s) {
  return /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i.test(s)
}

/**
 * Build the route path for a folder + optional uid.
 *
 * @param folder    folder row (must have folder_id for canonical form)
 * @param uid       optional message uid
 * @param options   { canonical: boolean }
 */
export function buildMailRoute(folder, uid = null, { canonical = true } = {}) {
  let segment
  if (canonical && folder?.folder_id) {
    segment = buildCanonicalSegment(folder)
  } else {
    segment = encodeURIComponent(folder?.name ?? '')
  }
  return uid ? `/m/${segment}/${Number(uid)}` : `/m/${segment}`
}

/**
 * Given a parsed legacy segment, look up its folder_id from the local
 * folder list (or null when not found). Used by the router to compute the
 * 301-redirect target.
 */
export function resolveLegacyToCanonical(folders, parsed) {
  if (parsed.form !== 'legacy' || !parsed.folder) return null
  const folder = findFolder(folders, { folder: parsed.folder })
  if (!folder?.folder_id) return null
  return buildCanonicalSegment(folder)
}

/**
 * Single source of truth for "should we emit canonical URLs?"
 *
 * Reads the feature-flag value from localStorage so the router and the
 * services agree without a Pinia round-trip.
 *   - "off"     : never emit canonical
 *   - "compare" : emit canonical AND log mismatches
 *   - "on"      : always emit canonical
 */
export function canonicalFolderRoutingEnabled() {
  if (typeof localStorage === 'undefined') return false
  const v = localStorage.getItem('ff_canonical_folder_routing') || 'off'
  return v === 'on' || v === 'compare'
}

export function canonicalFolderRoutingMode() {
  if (typeof localStorage === 'undefined') return 'off'
  const v = localStorage.getItem('ff_canonical_folder_routing') || 'off'
  return ['off', 'compare', 'on'].includes(v) ? v : 'off'
}

export function setCanonicalFolderRoutingMode(mode) {
  if (typeof localStorage === 'undefined') return
  if (!['off', 'compare', 'on'].includes(mode)) return
  localStorage.setItem('ff_canonical_folder_routing', mode)
}

/**
 * Compare-mode helper: log when canonical and legacy URLs disagree about
 * which folder a route resolves to. Mismatches above 0.01% over 7 days
 * gate the cutover from `compare` to `on`.
 */
export function logRouteMismatch(folders, legacyParsed, canonicalParsed) {
  if (canonicalFolderRoutingMode() !== 'compare') return
  if (legacyParsed.form !== 'legacy' || canonicalParsed.form !== 'canonical') return
  const legacyId = resolveFolderId(folders, legacyParsed.folder)
  if (legacyId !== canonicalParsed.folderId) {
    /* eslint-disable-next-line no-console */
    console.warn('[ff_canonical_folder_routing] mismatch', {
      legacy: { folder: legacyParsed.folder, resolvedId: legacyId },
      canonical: { folderId: canonicalParsed.folderId },
    })
  }
}

/** Marker for the canonical separator; exported for tests. */
export const __CANONICAL_SEP = SLUG_ID_SEPARATOR

// Util: explicit re-export of normalizeFolderPath so route code doesn't
// need a second import path.
export { normalizeFolderPath }

// ============================================================================
// Address-bar path helpers (path-shaped Vue Router URLs, e.g. /folder/foo/bar).
//
// These convert between an IMAP folder name ("INBOX.work.greyskull") and the
// lowercase, slash-separated path used in the browser address bar
// ("inbox/work/greyskull"). Shared by MailboxView and NotificationPanel so the
// two stay in lockstep -- duplicating the regex previously risked routing drift.
// ============================================================================

/** IMAP folder name -> address-bar path segment. */
export function folderToUrlPath(folderName) {
  if (!folderName || folderName === 'INBOX') return 'inbox'
  return folderName
    .replace(/\./g, '/')
    .replace(/ /g, '_')
    .toLowerCase()
}

/** Address-bar path segment -> IMAP folder name. */
export function urlPathToFolder(urlPath) {
  if (!urlPath || urlPath === 'inbox') return 'INBOX'
  const folderPath = Array.isArray(urlPath) ? urlPath.join('/') : urlPath
  let folderName = decodeURIComponent(folderPath)
    .replace(/\//g, '.')
    .replace(/_/g, ' ')

  // Handle INBOX prefix - if the path starts with 'inbox.', convert to 'INBOX.'
  // so subfolders like 'inbox.work.greyskull' become 'INBOX.work.greyskull'.
  if (folderName.toLowerCase().startsWith('inbox.')) {
    folderName = 'INBOX.' + folderName.substring(6)
  }

  return folderName
}

// ============================================================================
// API URL builders (canonical folder_id-shaped HTTP routes).
//
// These helpers compose the URLs that the API layer sends to the backend
// (NOT the Vue Router URLs in the address bar -- those stay path-shaped
// for Gmail-style UX).
//
// Post-cutover contract (Wave 2 P2 final):
//   - The backend ONLY exposes /folders/{folder_id}/... -- the legacy
//     /mailbox/{path}/... routes were removed. Returning a legacy URL
//     here would guarantee a 404 from the network layer.
//   - When the local folder list has a folder_id for the named folder,
//     return `/folders/{id}/...`.
//   - When the folder_id can NOT be resolved (folder list still hydrating
//     during a page-load race, or the folder genuinely does not exist),
//     return `null`. Callers MUST guard on a null return and either skip
//     the request or queue a retry once the folder list is populated. A
//     watcher in the mailbox store re-fires the active fetch when folders
//     transition from empty to populated, which recovers the early-fire
//     race transparently.
// ============================================================================

/**
 * Build a URL for a folder-scoped collection endpoint.
 *
 * Returns `/folders/{folder_id}/{subpath}` when folder_id is resolvable,
 * or `null` when it isn't. Never emits a legacy URL post-cutover.
 *
 * @param folders   the account folder list (mailboxStore.folders.value)
 * @param folder    folder path string (e.g. "INBOX", "INBOX.Archive")
 * @param subpath   optional path tail (e.g. "messages", "messages/123/flag");
 *                  do NOT prefix with "/", do NOT URL-encode -- callers
 *                  pass already-encoded fragments where needed.
 * @returns {string|null}
 */
export function folderCollectionUrl(folders, folder, subpath = '') {
  const id = resolveFolderId(folders, folder)
  if (!id) return null
  const tail = subpath ? `/${subpath}` : ''
  return `/folders/${id}${tail}`
}

/**
 * Build a URL for a folder-resource endpoint (rename, delete, empty).
 *
 * Returns `/folders/{folder_id}/{subpath}` when folder_id is resolvable,
 * or `null` when it isn't. Never emits a legacy URL post-cutover.
 *
 * @returns {string|null}
 */
export function folderResourceUrl(folders, folder, subpath = '') {
  const id = resolveFolderId(folders, folder)
  if (!id) return null
  const tail = subpath ? `/${subpath}` : ''
  return `/folders/${id}${tail}`
}
