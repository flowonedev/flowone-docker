/**
 * collabColors - deterministic per-user presence colors.
 *
 * Every client (and the collab server) derives the same color for the same
 * email, so cursors/avatars look consistent across all participants without
 * any coordination.
 */

export const COLLAB_PRESENCE_COLORS = [
  '#E53935', // Red
  '#D81B60', // Pink
  '#8E24AA', // Purple
  '#5E35B1', // Deep Purple
  '#3949AB', // Indigo
  '#1E88E5', // Blue
  '#039BE5', // Light Blue
  '#00ACC1', // Cyan
  '#00897B', // Teal
  '#43A047', // Green
  '#C0CA33', // Lime
  '#FDD835', // Yellow
  '#FFB300', // Amber
  '#FB8C00', // Orange
  '#F4511E', // Deep Orange
  '#6D4C41', // Brown
  '#546E7A', // Blue Grey
  '#EC407A', // Pink Accent
  '#AB47BC', // Purple Accent
  '#42A5F5', // Blue Accent
  '#26A69A', // Teal Accent
  '#66BB6A', // Green Accent
  '#FF7043', // Deep Orange Accent
  '#8D6E63', // Brown Accent
]

/**
 * FNV-1a 32-bit hash of the email -> stable color pick.
 */
export function getCollabUserColor(email) {
  const key = String(email || '').toLowerCase()
  let hash = 2166136261
  for (let i = 0; i < key.length; i++) {
    hash ^= key.charCodeAt(i)
    hash = Math.imul(hash, 16777619)
  }
  return COLLAB_PRESENCE_COLORS[(hash >>> 0) % COLLAB_PRESENCE_COLORS.length]
}

/**
 * Initials for avatar bubbles ("john.doe@x.com" -> "JD", "Anna Kis" -> "AK").
 */
export function getInitials(nameOrEmail) {
  const base = String(nameOrEmail || '?').split('@')[0]
  const parts = base.split(/[\s._-]+/).filter(Boolean)
  if (parts.length >= 2) {
    return (parts[0][0] + parts[1][0]).toUpperCase()
  }
  return base.slice(0, 2).toUpperCase()
}
