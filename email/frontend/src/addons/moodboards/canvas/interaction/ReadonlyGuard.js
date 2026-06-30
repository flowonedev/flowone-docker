/**
 * Gates write interactions when canvas is in readonly mode.
 * Allows: pan, zoom, presentation. Blocks: drag, resize, rotate, edit, delete.
 */
export function isWriteAction(action) {
  const writeActions = new Set([
    'drag', 'resize', 'rotate', 'edit', 'delete', 'drop',
    'paste', 'duplicate', 'group', 'ungroup', 'nudge',
    'layer-order', 'boolean-op', 'add-item', 'connect',
    'align', 'flip', 'lock', 'unlock',
  ])
  return writeActions.has(action)
}

export function guardAction(action, readonly) {
  if (!readonly) return true
  if (isWriteAction(action)) return false
  return true
}
