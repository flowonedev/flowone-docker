/**
 * folderGroupingService - canonical home for folder groupings.
 *
 * Wave 3 services split. Owns the (system + user-defined) configuration
 * mapping folder_ids to display groups. Replaces ad-hoc string-prefix
 * grouping in the sidebar with a config-driven model that survives
 * folder renames because every group entry references folder_id, not path.
 *
 * Configuration shape (persisted via /folder-groups API in Wave 3):
 *   {
 *     id:        string   // group identifier, e.g. "system:archive"
 *     name:      string   // display label
 *     icon:      string   // material-symbols name
 *     folderIds: string[] // UUIDv7 list
 *     order:     number   // ascending sort order in the sidebar
 *     pinned:    boolean
 *   }
 *
 * The service is pure: it does NOT do API calls. Callers (folderGroupsStore
 * in Wave 3) pass the in-memory config and the folder list; the service
 * returns the materialized tree.
 */

const SYSTEM_GROUPS_DEFAULT = [
  { id: 'system:inbox', name: 'Inbox', icon: 'inbox', special: 'inbox', order: 0 },
  { id: 'system:starred', name: 'Starred', icon: 'star', special: 'starred', order: 10 },
  { id: 'system:sent', name: 'Sent', icon: 'send', special: 'sent', order: 20 },
  { id: 'system:drafts', name: 'Drafts', icon: 'edit_note', special: 'drafts', order: 30 },
  { id: 'system:archive', name: 'Archive', icon: 'archive', special: 'archive', order: 40 },
  { id: 'system:trash', name: 'Trash', icon: 'delete', special: 'trash', order: 80 },
  { id: 'system:spam', name: 'Spam', icon: 'report', special: 'spam', order: 90 },
]

/**
 * Materialize the sidebar groups.
 *
 * @param folders          array of folder rows from the backend
 * @param userGroupsConfig array of user-defined group configs (may be empty)
 * @returns                array of `{ id, name, icon, order, folders[] }`
 *                         sorted by `order`
 */
export function materializeGroups(folders, userGroupsConfig = []) {
  const safeFolders = Array.isArray(folders) ? folders : []

  const systemGroups = SYSTEM_GROUPS_DEFAULT.map((g) => ({
    ...g,
    folders: safeFolders.filter((f) => f.type === g.special),
  })).filter((g) => g.folders.length > 0)

  const userGroups = (userGroupsConfig || []).map((g) => ({
    id: g.id,
    name: g.name,
    icon: g.icon || 'folder',
    order: typeof g.order === 'number' ? g.order : 100,
    pinned: !!g.pinned,
    folders: safeFolders.filter((f) => Array.isArray(g.folderIds) && g.folderIds.includes(f.folder_id)),
  })).filter((g) => g.folders.length > 0)

  const usedFolderIds = new Set()
  for (const g of [...systemGroups, ...userGroups]) {
    for (const f of g.folders) {
      if (f.folder_id) usedFolderIds.add(f.folder_id)
    }
  }

  const otherFolders = safeFolders.filter((f) => {
    if (!f.folder_id) return true
    return !usedFolderIds.has(f.folder_id)
  })

  const otherGroup = {
    id: 'system:other',
    name: 'Other',
    icon: 'folder',
    order: 200,
    folders: otherFolders,
  }
  const groups = [...systemGroups, ...userGroups]
  if (otherFolders.length > 0) {
    groups.push(otherGroup)
  }
  groups.sort((a, b) => (a.order ?? 0) - (b.order ?? 0))
  return groups
}

/**
 * Return the user-group ids that contain `folderId`.
 */
export function groupsContainingFolder(userGroupsConfig, folderId) {
  if (!folderId) return []
  return (userGroupsConfig || [])
    .filter((g) => Array.isArray(g.folderIds) && g.folderIds.includes(folderId))
    .map((g) => g.id)
}

/**
 * Add `folderId` to the user-group with `groupId`. Returns a NEW config
 * array (immutable update so Pinia/Vue reactivity stays clean).
 */
export function addFolderToGroup(userGroupsConfig, groupId, folderId) {
  return (userGroupsConfig || []).map((g) => {
    if (g.id !== groupId) return g
    const set = new Set(Array.isArray(g.folderIds) ? g.folderIds : [])
    set.add(folderId)
    return { ...g, folderIds: Array.from(set) }
  })
}

/**
 * Remove `folderId` from the user-group with `groupId`. Returns a NEW
 * config array.
 */
export function removeFolderFromGroup(userGroupsConfig, groupId, folderId) {
  return (userGroupsConfig || []).map((g) => {
    if (g.id !== groupId) return g
    const filtered = (g.folderIds || []).filter((id) => id !== folderId)
    return { ...g, folderIds: filtered }
  })
}

/**
 * Insert a brand new user-defined group. Generates a stable id from the name.
 */
export function createUserGroup(userGroupsConfig, { name, icon, folderIds = [], order, pinned = false }) {
  const id = 'user:' + (name || 'group')
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, '_')
    .replace(/^_+|_+$/g, '')
    .slice(0, 32)
  const next = [...(userGroupsConfig || [])]
  if (next.some((g) => g.id === id)) {
    return next
  }
  next.push({
    id,
    name,
    icon: icon || 'folder',
    folderIds,
    order: order ?? 100 + next.length,
    pinned,
  })
  return next
}
