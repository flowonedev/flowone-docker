export function useSubtaskTrackedTime(hubStore) {
  function resolveTrackedCardId(subtask, linkedCardMap = {}) {
    return Number(linkedCardMap?.[subtask?.id]?.linked_card_id || subtask?.id || 0)
  }

  function sumWorkSessionSeconds(sessions = []) {
    return sessions.reduce((sum, session) => sum + Number(session?.duration_seconds || 0), 0)
  }

  function getTrackedSeconds(subtask, linkedCardMap = {}) {
    const trackedCardId = resolveTrackedCardId(subtask, linkedCardMap)
    if (!trackedCardId) return 0
    return sumWorkSessionSeconds(hubStore.cardWorkSessions?.[trackedCardId] || [])
  }

  function getPerUserTrackedTime(subtask, linkedCardMap = {}) {
    const trackedCardId = resolveTrackedCardId(subtask, linkedCardMap)
    if (!trackedCardId) return []
    const sessions = hubStore.cardWorkSessions?.[trackedCardId] || []
    if (!sessions.length) return []

    const byUser = {}
    for (const s of sessions) {
      const email = (s.user_email || '').toLowerCase()
      if (!email) continue
      byUser[email] = (byUser[email] || 0) + Number(s.duration_seconds || 0)
    }

    return Object.entries(byUser)
      .map(([email, seconds]) => ({ email, seconds, label: formatTrackedTime(seconds) }))
      .sort((a, b) => b.seconds - a.seconds)
  }

  async function primeTrackedTime(subtasks = [], linkedCardMap = {}) {
    const trackedCardIds = [...new Set(
      subtasks
        .map(subtask => resolveTrackedCardId(subtask, linkedCardMap))
        .filter(Boolean)
    )]

    if (!trackedCardIds.length) return

    await Promise.allSettled(
      trackedCardIds.map(cardId => hubStore.fetchWorkSessions(cardId))
    )
  }

  function formatTrackedTime(seconds) {
    const totalSeconds = Number(seconds || 0)
    if (totalSeconds <= 0) return '0s'

    const hours = Math.floor(totalSeconds / 3600)
    const minutes = Math.floor((totalSeconds % 3600) / 60)
    const secs = totalSeconds % 60

    if (hours > 0) return secs > 0 ? `${hours}h ${minutes}m ${secs}s` : `${hours}h ${minutes}m`
    if (minutes > 0) return secs > 0 ? `${minutes}m ${secs}s` : `${minutes}m`
    return `${secs}s`
  }

  return {
    resolveTrackedCardId,
    getTrackedSeconds,
    getPerUserTrackedTime,
    primeTrackedTime,
    formatTrackedTime,
  }
}
