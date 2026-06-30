/**
 * Pure helpers to resolve a Drive item's containing-folder breadcrumb from the
 * flat `allFolders` list (the parent_id chain). Used to show "where does this
 * live" under Drive-wide search results so the user can jump to the folder.
 */

/**
 * Walk the parent_id chain from `folderId` up to the root.
 *
 * @param {Array<{id:(number|string), name:string, parent_id:(number|string|null)}>} allFolders
 * @param {number|string|null} folderId  The containing folder id (file.folder_id / folder.parent_id)
 * @returns {Array<{id:(number|string), name:string}>} segments ordered root -> leaf (empty = root)
 */
export function buildFolderPath(allFolders, folderId) {
  if (folderId === null || folderId === undefined || folderId === '') return []

  const byId = new Map()
  for (const f of allFolders || []) {
    byId.set(String(f.id), f)
  }

  const chain = []
  const seen = new Set()
  let current = byId.get(String(folderId))

  // Guard against cycles / orphaned parents (e.g. a trashed ancestor).
  while (current && !seen.has(String(current.id))) {
    seen.add(String(current.id))
    chain.unshift({ id: current.id, name: current.name })
    current = current.parent_id != null ? byId.get(String(current.parent_id)) : null
  }

  return chain
}

/**
 * Render a path label like "My Drive / Clients / Acme" from breadcrumb segments.
 *
 * @param {Array<{name:string}>} segments
 * @param {string} rootLabel  Label for the Drive root (i18n-provided by caller)
 * @returns {string}
 */
export function formatFolderPathLabel(segments, rootLabel = 'My Drive') {
  if (!segments || segments.length === 0) return rootLabel
  return rootLabel + ' / ' + segments.map((s) => s.name).join(' / ')
}
