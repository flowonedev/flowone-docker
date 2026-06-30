/**
 * Date / time formatting helpers for the Tasks panel.
 *
 * All helpers are pure functions and locale-aware via `toLocaleDateString`.
 * The "tone" string returned from `formatDueDate` is a coarse classification
 * (`overdue`, `today`, `soon`, `future`) that the UI maps to colors so that
 * components stay decoupled from Tailwind classnames.
 */

const MS_PER_DAY = 1000 * 60 * 60 * 24

/**
 * Strip time-of-day from a Date so day-only comparisons are stable across
 * timezones / DST boundaries.
 */
function startOfDay(date) {
  const d = new Date(date)
  d.setHours(0, 0, 0, 0)
  return d
}

/**
 * Parse a value into a Date. MariaDB returns timestamps as
 * "YYYY-MM-DD HH:MM:SS" without a timezone marker — Chrome, Firefox and
 * older Safari versions parse that inconsistently, which can flip "today"
 * checks across browsers. We normalize the MySQL format to ISO so every
 * browser interprets it the same way (as local time).
 */
function toDate(input) {
  if (!input) return null
  if (input instanceof Date) return Number.isNaN(input.getTime()) ? null : input
  if (typeof input === 'string') {
    const mysql = input.match(/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2}):(\d{2})/)
    if (mysql) {
      const [, y, m, d, hh, mm, ss] = mysql
      return new Date(Number(y), Number(m) - 1, Number(d), Number(hh), Number(mm), Number(ss))
    }
    const dateOnly = input.match(/^(\d{4})-(\d{2})-(\d{2})$/)
    if (dateOnly) {
      const [, y, m, d] = dateOnly
      return new Date(Number(y), Number(m) - 1, Number(d))
    }
  }
  const d = new Date(input)
  return Number.isNaN(d.getTime()) ? null : d
}

/**
 * Bucket-tagged formatted due date.
 * @param {string|Date|null} dateInput
 * @returns {{ text: string, tone: 'overdue'|'today'|'soon'|'future' }|null}
 */
export function formatDueDate(dateInput) {
  const date = toDate(dateInput)
  if (!date) return null

  const today = startOfDay(new Date())
  const dueDay = startOfDay(date)
  const diffDays = Math.round((dueDay - today) / MS_PER_DAY)

  if (diffDays < 0) return { text: 'Overdue', tone: 'overdue' }
  if (diffDays === 0) return { text: 'Today', tone: 'today' }
  if (diffDays === 1) return { text: 'Tomorrow', tone: 'soon' }
  if (diffDays < 7) {
    return { text: date.toLocaleDateString([], { weekday: 'long' }), tone: 'soon' }
  }
  return {
    text: date.toLocaleDateString([], { month: 'short', day: 'numeric' }),
    tone: 'future'
  }
}

/**
 * "5m ago", "2h ago", "3d ago" or absolute date for older.
 */
export function formatRelativeAgo(dateInput) {
  const date = toDate(dateInput)
  if (!date) return ''

  const diffMs = Date.now() - date.getTime()
  if (diffMs < 0) return date.toLocaleDateString([], { month: 'short', day: 'numeric' })

  const minutes = Math.floor(diffMs / 60000)
  if (minutes < 1) return 'just now'
  if (minutes < 60) return `${minutes}m ago`

  const hours = Math.floor(minutes / 60)
  if (hours < 24) return `${hours}h ago`

  const days = Math.floor(hours / 24)
  if (days < 7) return `${days}d ago`

  return date.toLocaleDateString([], { month: 'short', day: 'numeric' })
}

/**
 * True if the supplied date string represents today's local date.
 */
export function isToday(dateInput) {
  const date = toDate(dateInput)
  if (!date) return false
  return startOfDay(date).getTime() === startOfDay(new Date()).getTime()
}

/**
 * True if the supplied date is strictly before today (i.e. overdue).
 */
export function isOverdue(dateInput) {
  const date = toDate(dateInput)
  if (!date) return false
  return startOfDay(date).getTime() < startOfDay(new Date()).getTime()
}

/**
 * True if the supplied date is strictly after today.
 */
export function isUpcoming(dateInput) {
  const date = toDate(dateInput)
  if (!date) return false
  return startOfDay(date).getTime() > startOfDay(new Date()).getTime()
}
