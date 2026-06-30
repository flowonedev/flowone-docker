/**
 * useDocViewTogether - Composable for document "View Together" sessions.
 * Reuses the existing WebSocket/Redis pub/sub infrastructure to sync
 * cursor position and page navigation between internal CRM users.
 */
import { ref, computed, onUnmounted } from 'vue'
import api from '@/services/api'

let syncThrottle = null
let cursorThrottle = null

export function useDocViewTogether(clientId, docId) {
  const isActive = ref(false)
  const otherCursor = ref(null)
  const otherPosition = ref(null)
  const followMode = ref(true)
  const sessionStartedBy = ref(null)

  function startSession() {
    api.post(`/clients/${clientId}/portal/documents/${docId}/view-session`)
      .then(res => {
        if (res.data?.success) {
          isActive.value = true
          sessionStartedBy.value = res.data.data?.started_by
        }
      })
      .catch(() => {})
  }

  function endSession() {
    api.delete(`/clients/${clientId}/portal/documents/${docId}/view-session`)
      .catch(() => {})
    isActive.value = false
    otherCursor.value = null
    otherPosition.value = null
  }

  function syncPosition(position) {
    if (!isActive.value) return
    if (syncThrottle) return
    syncThrottle = setTimeout(() => { syncThrottle = null }, 100)
    api.put(`/clients/${clientId}/portal/documents/${docId}/view-session/sync`, { position })
      .catch(() => {})
  }

  function syncCursor(x, y, containerWidth, containerHeight, position) {
    if (!isActive.value) return
    if (cursorThrottle) return
    cursorThrottle = setTimeout(() => { cursorThrottle = null }, 50)
    const cursor = {
      x: x / containerWidth,
      y: y / containerHeight,
      containerWidth,
      containerHeight,
    }
    api.put(`/clients/${clientId}/portal/documents/${docId}/view-session/sync`, { cursor, position })
      .catch(() => {})
  }

  function handleSyncEvent(data) {
    if (!isActive.value) return
    if (data.document_id !== docId) return
    if (data.cursor) otherCursor.value = data.cursor
    if (data.position) otherPosition.value = data.position
  }

  function handleSessionEnd(data) {
    if (data.document_id !== docId) return
    isActive.value = false
    otherCursor.value = null
    otherPosition.value = null
  }

  onUnmounted(() => {
    if (isActive.value) endSession()
  })

  return {
    isActive,
    otherCursor,
    otherPosition,
    followMode,
    startSession,
    endSession,
    syncPosition,
    syncCursor,
    handleSyncEvent,
    handleSessionEnd,
  }
}
