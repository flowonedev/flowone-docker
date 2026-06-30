/**
 * mailRouteService - canonical home for mail route URL composition + parsing.
 *
 * Owns:
 *   - building the canonical `<slug>--<folder_id>` route segment
 *   - parsing the route segment back into `folder_id`
 *   - composing API URLs that target the canonical `/folders/{id}/...`
 *     endpoints
 *
 * Routing pattern:
 *   /m/<slug>--<folder_id>
 *   /m/<slug>--<folder_id>/<uid>
 *
 * The slug is purely cosmetic (URL-friendly display name); the folder_id
 * is the source of truth.
 *
 * NOTE (deferred deploy): this is the post-cutover form of the file. The
 * legacy fallbacks (path-shaped routes, ff_canonical_folder_routing
 * compare mode, /mailbox/{folder}/... API URLs) have been removed in
 * lockstep with the backend canonical-identity cutover. Do NOT deploy
 * before the backend cutover migration has been applied.
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
 * Returns null when no folder_id is available -- callers should treat
 * that as a hard error post-cutover.
 */
export function buildCanonicalSegment(folder) {
  if (!folder?.folder_id) return null
  const slug = slugify(folderDisplayName(folder))
  return `${slug}${SLUG_ID_SEPARATOR}${folder.folder_id}`
}

/**
 * Parse a canonical route segment (`slug--folder_id`).
 * Returns `{ slug, folderId }` or null when the segment is not in the
 * canonical form. Pre-cutover the parser also accepted bare path
 * segments; that branch has been removed.
 */
export function parseRouteSegment(segment) {
  if (typeof segment !== 'string' || segment === '') return null
  const idx = segment.lastIndexOf(SLUG_ID_SEPARATOR)
  if (idx <= 0) return null
  const slug = segment.slice(0, idx)
  const tail = segment.slice(idx + SLUG_ID_SEPARATOR.length)
  if (!looksLikeUuid(tail)) return null
  return { slug, folderId: tail }
}

function looksLikeUuid(s) {
  return /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i.test(s)
}

/**
 * Build the route path for a folder + optional uid.
 * Throws if the folder has no folder_id (hard failure post-cutover).
 */
export function buildMailRoute(folder, uid = null) {
  const segment = buildCanonicalSegment(folder)
  if (!segment) {
    throw new Error('mailRouteService: folder has no folder_id; cannot build canonical route')
  }
  return uid ? `/m/${segment}/${Number(uid)}` : `/m/${segment}`
}

/** Marker for the canonical separator; exported for tests. */
export const __CANONICAL_SEP = SLUG_ID_SEPARATOR

// Util: explicit re-export of normalizeFolderPath so route code doesn't
// need a second import path.
export { normalizeFolderPath, findFolder, resolveFolderId }

// ============================================================================
// API URL builders (canonical folder_id-shaped HTTP routes).
//
// These helpers compose the URLs that the API layer sends to the backend.
// The backend has dropped its legacy `/mailbox/{folder}/...` routes, so
// the resolver MUST find a folder_id; otherwise we fail fast rather than
// silently fall back.
// ============================================================================

function requireFolderId(folders, folder) {
  const id = resolveFolderId(folders, folder)
  if (!id) {
    throw new Error(
      `mailRouteService: cannot resolve folder_id for "${folder}" -- ` +
      'folder list may be stale; refresh /mailbox/folders first.'
    )
  }
  return id
}

/**
 * Build a URL for a folder-scoped collection endpoint.
 *
 *   /folders/{folder_id}/{subpath}
 *
 * @param folders   the account folder list (mailboxStore.folders.value)
 * @param folder    folder path string (e.g. "INBOX", "INBOX.Archive")
 * @param subpath   optional path tail (e.g. "messages", "messages/123/flag");
 *                  do NOT prefix with "/", do NOT URL-encode -- callers
 *                  pass already-encoded fragments where needed.
 */
export function folderCollectionUrl(folders, folder, subpath = '') {
  const id = requireFolderId(folders, folder)
  const tail = subpath ? `/${subpath}` : ''
  return `/folders/${id}${tail}`
}

/**
 * Build a URL for a folder-resource endpoint (rename, delete, empty).
 *
 *   /folders/{folder_id}/{subpath}
 */
export function folderResourceUrl(folders, folder, subpath = '') {
  const id = requireFolderId(folders, folder)
  const tail = subpath ? `/${subpath}` : ''
  return `/folders/${id}${tail}`
}
