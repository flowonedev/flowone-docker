/**
 * folderIdentityService - canonical home for folder-identity transforms.
 *
 * Wave 3 services split. Owns:
 *   - composing the canonical message key (folder_id:uid; legacy fallback)
 *   - looking up folder display name / type from the account folder list
 *   - normalizing folder paths (case, whitespace, double slashes)
 *   - SPECIAL-USE flag mapping
 *
 * Stays under 400 lines per the modularity-architecture rule. Stores must
 * call THIS service for any folder-id work; ad-hoc string concatenation
 * (`${folder}:${uid}`) outside this file is a code smell.
 */

const SPECIAL_USE_TYPES = {
  '\\Sent': 'sent',
  '\\Drafts': 'drafts',
  '\\Trash': 'trash',
  '\\Junk': 'spam',
  '\\Archive': 'archive',
  '\\All': 'archive',
  '\\Important': 'important',
  '\\Flagged': 'starred',
}

/**
 * Compose the canonical message store key.
 *
 * When `folderId` is provided we use the rename-safe form `id:<uuid>:<uid>`.
 * Otherwise we fall back to `<folder>:<uid>` so dual-write reads keep
 * working during the Wave 2 cutover window.
 */
export function makeMessageKey(folderId, folder, uid) {
  const u = Number(uid)
  if (folderId) return `id:${folderId}:${u}`
  return `${folder}:${u}`
}

/**
 * Best-effort key parser. Returns `{ folderId, folder, uid }`. The store
 * accepts both forms; this is the single place that can split them apart.
 */
export function parseMessageKey(key) {
  if (typeof key !== 'string' || key === '') {
    return { folderId: null, folder: null, uid: null }
  }
  if (key.startsWith('id:')) {
    const rest = key.slice(3)
    const idx = rest.lastIndexOf(':')
    if (idx === -1) return { folderId: rest, folder: null, uid: null }
    return {
      folderId: rest.slice(0, idx),
      folder: null,
      uid: Number(rest.slice(idx + 1)),
    }
  }
  const idx = key.lastIndexOf(':')
  if (idx === -1) return { folderId: null, folder: key, uid: null }
  return {
    folderId: null,
    folder: key.slice(0, idx),
    uid: Number(key.slice(idx + 1)),
  }
}

/**
 * Normalize a folder path so equivalent paths share a key.
 *  - trim
 *  - collapse multiple slashes
 *  - lowercase
 */
export function normalizeFolderPath(path) {
  if (!path) return ''
  let p = String(path).trim()
  p = p.replace(/\/+/g, '/')
  return p.toLowerCase()
}

/**
 * Map an RFC 6154 SPECIAL-USE flag to our internal folder-type string.
 * Returns null for unrecognized flags.
 */
export function specialUseType(flag) {
  if (!flag) return null
  return SPECIAL_USE_TYPES[flag] ?? null
}

/**
 * Find a folder row in the account folder list by id, then by path.
 */
export function findFolder(folders, { folderId = null, folder = null } = {}) {
  if (!Array.isArray(folders)) return null
  if (folderId) {
    const byId = folders.find((f) => f.folder_id === folderId)
    if (byId) return byId
  }
  if (folder) {
    const norm = normalizeFolderPath(folder)
    return (
      folders.find((f) => f.name === folder) ||
      folders.find((f) => normalizeFolderPath(f.name) === norm) ||
      null
    )
  }
  return null
}

/**
 * Resolve the display name for a folder, honoring `display_name` from the
 * payload when present and falling back to the path basename.
 */
export function folderDisplayName(folder) {
  if (!folder) return ''
  if (folder.display_name) return folder.display_name
  const segments = String(folder.name || '').split(/[\\/.]/)
  return segments[segments.length - 1] || folder.name || ''
}

/**
 * Returns the folder_id for a (folder name, account folder list) pair, or
 * null when not found / not yet populated by the backend.
 */
export function resolveFolderId(folders, folder) {
  const row = findFolder(folders, { folder })
  return row?.folder_id ?? null
}
