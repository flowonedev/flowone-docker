import { defineStore } from 'pinia'
import { ref } from 'vue'

interface SyncStatus {
  status: 'idle' | 'syncing' | 'paused' | 'offline' | 'error'
  message: string
  progress?: number
  lastSync?: string
  pendingChanges: number
}

/**
 * Wave C.1 — push-driven sync status.
 *
 * Replaces the old `setInterval(fetchStatus, 2000)` poll with subscription
 * to `sync-status-change` from main. A 30 s heartbeat fallback re-pulls the
 * status to recover from missed events (e.g. ipc dropped during a
 * webContents reload).
 */
export const useSyncStore = defineStore('sync', () => {
  const status = ref<SyncStatus>({
    status: 'idle',
    message: 'Ready',
    pendingChanges: 0,
  })

  let pushUnsubscribe: (() => void) | null = null
  let heartbeatInterval: NodeJS.Timeout | null = null

  function startStatusPolling() {
    if (pushUnsubscribe) return // already subscribed; idempotent

    fetchStatus()

    pushUnsubscribe = window.api.onSyncStatusChange((next) => {
      if (!next) return
      status.value = {
        status: next.status || 'idle',
        message: next.message || 'Ready',
        progress: next.progress,
        lastSync: next.lastSync,
        pendingChanges: next.pendingChanges || 0,
      }
    })

    heartbeatInterval = setInterval(fetchStatus, 30_000)
  }

  function stopStatusPolling() {
    if (pushUnsubscribe) {
      pushUnsubscribe()
      pushUnsubscribe = null
    }
    if (heartbeatInterval) {
      clearInterval(heartbeatInterval)
      heartbeatInterval = null
    }
  }
  
  async function fetchStatus() {
    try {
      const result = await window.api.getSyncStatus()
      status.value = {
        status: result.status || 'idle',
        message: result.message || 'Ready',
        progress: result.progress,
        lastSync: result.lastSync,
        pendingChanges: result.pendingChanges || 0,
      }
    } catch (e) {
      console.error('Failed to fetch sync status:', e)
    }
  }
  
  async function triggerSync() {
    try {
      await window.api.triggerSync()
    } catch (e) {
      console.error('Failed to trigger sync:', e)
    }
  }
  
  async function pauseSync() {
    try {
      await window.api.pauseSync()
    } catch (e) {
      console.error('Failed to pause sync:', e)
    }
  }
  
  async function resumeSync() {
    try {
      await window.api.resumeSync()
    } catch (e) {
      console.error('Failed to resume sync:', e)
    }
  }
  
  return {
    status,
    startStatusPolling,
    stopStatusPolling,
    fetchStatus,
    triggerSync,
    pauseSync,
    resumeSync,
  }
})

