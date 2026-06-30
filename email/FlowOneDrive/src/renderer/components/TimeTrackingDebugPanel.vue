<script setup lang="ts">
import { ref, onMounted, onUnmounted } from 'vue'

interface TrackingSession {
  filename: string
  folderId: number | null
  folderName?: string
  startedAt: string
  duration: number
  source: 'handle' | 'window' | 'both'
}

const currentSessions = ref<TrackingSession[]>([])
const isLoading = ref(true)
let refreshInterval: NodeJS.Timeout | null = null
let pushUnsubscribe: (() => void) | null = null
let isVisible = ref(typeof document !== 'undefined' ? document.visibilityState === 'visible' : true)

onMounted(async () => {
  // Wave C.1: visibility-gated push subscription instead of a 1 s poll.
  await loadSessions()

  pushUnsubscribe = window.api.onSelfEditingUpdate((editing) => {
    currentSessions.value = editing.map(e => ({
      filename: e.filename,
      folderId: e.folderId,
      startedAt: new Date().toISOString(),
      duration: 0,
      source: 'window' as const,
    }))
  })

  document.addEventListener('visibilitychange', onVisibilityChange)

  if (isVisible.value) {
    refreshInterval = setInterval(loadSessions, 30_000)
  }
})

onUnmounted(() => {
  if (refreshInterval) {
    clearInterval(refreshInterval)
    refreshInterval = null
  }
  if (pushUnsubscribe) {
    pushUnsubscribe()
    pushUnsubscribe = null
  }
  document.removeEventListener('visibilitychange', onVisibilityChange)
})

function onVisibilityChange() {
  isVisible.value = document.visibilityState === 'visible'
  if (isVisible.value) {
    if (!refreshInterval) {
      refreshInterval = setInterval(loadSessions, 30_000)
      loadSessions()
    }
  } else {
    if (refreshInterval) {
      clearInterval(refreshInterval)
      refreshInterval = null
    }
  }
}

async function loadSessions() {
  try {
    const editing = await window.api.getSelfEditing()
    currentSessions.value = editing.map(e => ({
      filename: e.filename,
      folderId: e.folderId,
      startedAt: new Date().toISOString(),
      duration: 0,
      source: 'window' as const
    }))
  } catch (e) {
    console.error('Failed to load tracking sessions:', e)
  } finally {
    isLoading.value = false
  }
}

function formatDuration(seconds: number): string {
  const mins = Math.floor(seconds / 60)
  const secs = seconds % 60
  if (mins === 0) return `${secs}s`
  return `${mins}m ${secs}s`
}
</script>

<template>
  <div style="padding: 16px; background: #1a1a20; border-radius: 12px; border: 1px solid #2a2a32;">
    <h3 style="color: white; font-size: 14px; font-weight: 600; margin-bottom: 16px; display: flex; align-items: center; gap: 8px;">
      <span class="material-symbols-rounded" style="font-size: 18px; color: #22c55e;">bug_report</span>
      Time Tracking Debug
    </h3>
    
    <div v-if="isLoading" style="display: flex; align-items: center; justify-content: center; padding: 20px;">
      <span class="material-symbols-rounded animate-spin" style="font-size: 24px; color: #22c55e;">sync</span>
    </div>
    
    <div v-else-if="currentSessions.length === 0" style="text-align: center; padding: 20px;">
      <span class="material-symbols-rounded" style="font-size: 32px; color: #3a3a42; margin-bottom: 8px;">schedule</span>
      <p style="color: #6b7280; font-size: 13px;">No files currently being tracked</p>
    </div>
    
    <div v-else style="display: flex; flex-direction: column; gap: 8px;">
      <div
        v-for="(session, index) in currentSessions"
        :key="index"
        style="display: flex; align-items: center; gap: 12px; padding: 12px; background: rgba(34, 197, 94, 0.1); border-radius: 8px; border: 1px solid rgba(34, 197, 94, 0.2);"
      >
        <span class="material-symbols-rounded self-editing-dot-pulse" style="font-size: 20px; color: #22c55e;">edit_document</span>
        <div style="flex: 1;">
          <p style="color: white; font-size: 13px;">{{ session.filename }}</p>
          <p style="color: #6b7280; font-size: 11px;">Source: {{ session.source }}</p>
        </div>
        <span style="color: #22c55e; font-size: 12px; font-weight: 500;">
          {{ formatDuration(session.duration) }}
        </span>
      </div>
    </div>
  </div>
</template>

