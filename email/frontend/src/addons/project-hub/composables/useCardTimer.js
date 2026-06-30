import { ref } from 'vue'
import api from '@/services/api'
import { getToken } from '@/services/tokenStorage'
import { getApiOrigin } from '@/services/serverRegistry'

const MINIMUM_DURATION = 1

const activeCardId = ref(null)
const elapsed = ref(0)
const isRunning = ref(false)

let startedAt = null
let intervalId = null

function startTimer(cardId, userEmail) {
  if (isRunning.value) stopTimer()

  activeCardId.value = cardId
  startedAt = Date.now()
  elapsed.value = 0
  isRunning.value = true

  intervalId = setInterval(() => {
    elapsed.value = Math.floor((Date.now() - startedAt) / 1000)
  }, 1000)
}

async function stopTimer() {
  if (!isRunning.value || !activeCardId.value) return

  clearInterval(intervalId)
  intervalId = null
  isRunning.value = false

  const durationSeconds = Math.floor((Date.now() - startedAt) / 1000)
  const cardId = activeCardId.value
  const startedIso = new Date(startedAt).toISOString().slice(0, 19).replace('T', ' ')
  const endedIso = new Date().toISOString().slice(0, 19).replace('T', ' ')

  activeCardId.value = null
  startedAt = null
  elapsed.value = 0

  if (durationSeconds < MINIMUM_DURATION) return

  const payload = {
    card_id: cardId,
    source: 'card_view',
    started_at: startedIso,
    ended_at: endedIso,
    duration_seconds: durationSeconds,
  }

  try {
    const res = await api.post('/project-hub/work-sessions', payload)
    if (res.data?.logged === false) {
      console.error('[useCardTimer] Session not saved:', res.data.error)
    }
  } catch (err) {
    console.error('[useCardTimer] Failed to log work session:', err?.response?.status, err?.response?.data || err.message)
  }
}

/**
 * Fire-and-forget session log that survives page unload.
 * Uses fetch with keepalive, falling back to sendBeacon.
 */
function stopTimerSync() {
  if (!isRunning.value || !activeCardId.value) return

  clearInterval(intervalId)
  intervalId = null
  isRunning.value = false

  const durationSeconds = Math.floor((Date.now() - startedAt) / 1000)
  const cardId = activeCardId.value
  const startedIso = new Date(startedAt).toISOString().slice(0, 19).replace('T', ' ')
  const endedIso = new Date().toISOString().slice(0, 19).replace('T', ' ')

  activeCardId.value = null
  startedAt = null
  elapsed.value = 0

  if (durationSeconds < MINIMUM_DURATION) return

  const payload = JSON.stringify({
    card_id: cardId,
    source: 'card_view',
    started_at: startedIso,
    ended_at: endedIso,
    duration_seconds: durationSeconds,
  })

  const url = getApiOrigin() + '/api/project-hub/work-sessions'
  const token = getToken('webmail_token')

  try {
    fetch(url, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        ...(token ? { Authorization: `Bearer ${token}` } : {}),
      },
      body: payload,
      keepalive: true,
    })
  } catch {
    // keepalive fetch failed, try sendBeacon as last resort
    try {
      const blob = new Blob([payload], { type: 'application/json' })
      navigator.sendBeacon(url + '?_token=' + encodeURIComponent(token || ''), blob)
    } catch { /* best effort */ }
  }
}

function formatElapsed(seconds) {
  if (seconds < 60) return `${seconds}s`
  const m = Math.floor(seconds / 60)
  const s = seconds % 60
  if (m < 60) return `${m}m ${s}s`
  const h = Math.floor(m / 60)
  return `${h}h ${m % 60}m`
}

export function useCardTimer() {
  return {
    elapsed,
    isRunning,
    activeCardId,
    startTimer,
    stopTimer,
    stopTimerSync,
    formatElapsed,
  }
}
