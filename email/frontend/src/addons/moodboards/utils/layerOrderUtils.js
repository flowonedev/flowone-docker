/**
 * Scoped layer ordering utilities.
 *
 * z_index is meaningful only among siblings that share the same scope:
 *   scope = (parent_id, lane)
 *
 * "lane" distinguishes slides from content at the root level (parent_id null).
 * Nested items (inside frames/columns) always belong to the "content" lane.
 */

export function layerScope(item) {
  const parentId = item.parent_id || null
  const lane = (!parentId && item.type === 'slide') ? 'slide' : 'content'
  return { parentId, lane }
}

function scopeMatch(a, b) {
  return a.parentId === b.parentId && a.lane === b.lane
}

export function itemsInScope(allItems, scope) {
  return allItems.filter(i => {
    const s = layerScope(i)
    return scopeMatch(s, scope)
  })
}

export function sortByZIndexAsc(items) {
  return [...items].sort((a, b) => (a.z_index || 0) - (b.z_index || 0) || a.id - b.id)
}

export function sortByZIndexDesc(items) {
  return [...items].sort((a, b) => (b.z_index || 0) - (a.z_index || 0) || b.id - a.id)
}

/**
 * Given a desired order (array of items), return update objects for items
 * whose z_index needs to change to match contiguous 1..n numbering.
 */
export function renumberScope(orderedItems) {
  const updates = []
  for (let i = 0; i < orderedItems.length; i++) {
    const newZ = i + 1
    if ((orderedItems[i].z_index || 0) !== newZ) {
      updates.push({ id: orderedItems[i].id, z_index: newZ })
    }
  }
  return updates
}

/**
 * Next available z_index in a scope (one above the current maximum).
 */
export function nextZIndexInScope(allItems, scope) {
  const siblings = itemsInScope(allItems, scope)
  if (!siblings.length) return 1
  return Math.max(...siblings.map(i => i.z_index || 0)) + 1
}

/**
 * Build a scoped sorted list, move `itemId` to the end (front), and return
 * the update array. Returns [] if item not found or already at front.
 */
export function buildBringToFront(allItems, itemId) {
  const item = allItems.find(i => i.id === itemId)
  if (!item) return []
  const scope = layerScope(item)
  const sorted = sortByZIndexAsc(itemsInScope(allItems, scope))
  const idx = sorted.findIndex(i => i.id === itemId)
  if (idx === -1 || idx === sorted.length - 1) return []
  sorted.splice(idx, 1)
  sorted.push(item)
  return renumberScope(sorted)
}

export function buildSendToBack(allItems, itemId) {
  const item = allItems.find(i => i.id === itemId)
  if (!item) return []
  const scope = layerScope(item)
  const sorted = sortByZIndexAsc(itemsInScope(allItems, scope))
  const idx = sorted.findIndex(i => i.id === itemId)
  if (idx === -1 || idx === 0) return []
  sorted.splice(idx, 1)
  sorted.unshift(item)
  return renumberScope(sorted)
}

export function buildMoveForward(allItems, itemId) {
  const item = allItems.find(i => i.id === itemId)
  if (!item) return []
  const scope = layerScope(item)
  const sorted = sortByZIndexAsc(itemsInScope(allItems, scope))
  const idx = sorted.findIndex(i => i.id === itemId)
  if (idx === -1 || idx === sorted.length - 1) return []
  ;[sorted[idx], sorted[idx + 1]] = [sorted[idx + 1], sorted[idx]]
  return renumberScope(sorted)
}

export function buildMoveBackward(allItems, itemId) {
  const item = allItems.find(i => i.id === itemId)
  if (!item) return []
  const scope = layerScope(item)
  const sorted = sortByZIndexAsc(itemsInScope(allItems, scope))
  const idx = sorted.findIndex(i => i.id === itemId)
  if (idx === -1 || idx === 0) return []
  ;[sorted[idx], sorted[idx - 1]] = [sorted[idx - 1], sorted[idx]]
  return renumberScope(sorted)
}

/**
 * Reorder: move `movedId` relative to `targetId` ('before' or 'after').
 * Both items must be in the same scope. Returns update array.
 */
export function buildReorder(allItems, movedId, targetId, position = 'before') {
  const moved = allItems.find(i => i.id === movedId)
  const target = allItems.find(i => i.id === targetId)
  if (!moved || !target) return []
  const scope = layerScope(target)

  // Use descending sort (matches layer panel: top of panel = highest z)
  const sorted = sortByZIndexDesc(itemsInScope(allItems, scope))
  const fromIdx = sorted.findIndex(i => i.id === movedId)
  const toIdx = sorted.findIndex(i => i.id === targetId)
  if (fromIdx === -1 || toIdx === -1 || fromIdx === toIdx) return []

  sorted.splice(fromIdx, 1)
  const adjustedToIdx = sorted.findIndex(i => i.id === targetId)
  const insertIdx = position === 'before' ? adjustedToIdx : adjustedToIdx + 1
  sorted.splice(insertIdx, 0, moved)

  // Reverse to ascending for renumber (1 = back, n = front)
  sorted.reverse()
  return renumberScope(sorted)
}

/**
 * Reorder an entire group (by groupId) relative to a target item.
 * Group members must share the same scope as the target.
 */
export function buildReorderGroup(allItems, groupId, targetId, position = 'before') {
  const target = allItems.find(i => i.id === targetId)
  if (!target) return []
  const scope = layerScope(target)

  const sorted = sortByZIndexDesc(itemsInScope(allItems, scope))
  const groupItems = sorted.filter(i => i.style_data?.group_id === groupId)
  const nonGroupItems = sorted.filter(i => i.style_data?.group_id !== groupId)
  if (!groupItems.length) return []

  const toIdx = nonGroupItems.findIndex(i => i.id === targetId)
  if (toIdx === -1) return []

  const insertIdx = position === 'before' ? toIdx : toIdx + 1
  const reordered = [
    ...nonGroupItems.slice(0, insertIdx),
    ...groupItems,
    ...nonGroupItems.slice(insertIdx),
  ]

  reordered.reverse()
  return renumberScope(reordered)
}
