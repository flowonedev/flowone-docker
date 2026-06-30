/**
 * Folder classification shared across the new-mail detection paths.
 *
 * Folders whose new UIDs are the user's own outgoing copies or noise and must
 * never raise a "new email" device push (sending lands a copy in Sent, saving
 * lands one in Drafts). Mirrors MailboxSyncService::isSystemNonInboxFolder() on
 * the PHP side so every detection path (IDLE, Redis pub/sub push) agrees.
 * Only INBOX + custom folders notify.
 */

export const SYSTEM_NON_INBOX_NAMES = new Set([
  'sent', 'sent mail', 'sent items', 'sent messages', 'outbox',
  'drafts', 'draft',
  'junk', 'junk email', 'junk e-mail', 'spam', 'bulk', 'bulk mail',
  'trash', 'deleted', 'deleted items', 'deleted messages', 'bin', 'recycle bin',
])

export function isSystemNonInboxFolder(folderPath) {
  let leaf = String(folderPath || '')
  for (const delim of ['/', '.']) {
    const idx = leaf.lastIndexOf(delim)
    if (idx !== -1) leaf = leaf.slice(idx + 1)
  }
  leaf = leaf.trim().toLowerCase()
  if (leaf === '' || leaf === 'inbox') return false
  return SYSTEM_NON_INBOX_NAMES.has(leaf)
}
