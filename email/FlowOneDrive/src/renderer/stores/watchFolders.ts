import { defineStore } from 'pinia'
import { ref, computed } from 'vue'

interface WatchFolder {
  id: number
  name: string
  folderPath: string
  resolvedPath: string
  clientId: number
  clientName: string
  boardId: number | null
  boardName: string | null
  cardId: number | null
  resolved: boolean
  status: 'watching' | 'not_found' | 'pending'
}

export const useWatchFoldersStore = defineStore('watchFolders', () => {
  const watchFolders = ref<WatchFolder[]>([])
  const loading = ref(false)

  const watchingCount = computed(() => watchFolders.value.filter(f => f.status === 'watching').length)
  const unresolvedCount = computed(() => watchFolders.value.filter(f => f.status === 'not_found').length)

  // Only folders that are actively watching (path exists on disk)
  const activeWatchFolders = computed(() =>
    watchFolders.value.filter(f => f.status !== 'not_found')
  )

  // Loads from main-process in-memory cache (no network call)
  async function loadWatchFolders() {
    try {
      const result = await window.api.getWatchFolders()
      watchFolders.value = result || []
    } catch (e) {
      console.error('Failed to load watch folders:', e)
    }
  }

  // Triggers a full re-fetch from server + re-validates local paths
  async function refresh() {
    loading.value = true
    try {
      const result = await window.api.refreshWatchFolders()
      watchFolders.value = result || []
    } finally {
      loading.value = false
    }
  }

  // Apply a folder list returned by a manage action (change path / remove)
  // so the sidebar reflects the change without an extra IPC round trip.
  function setFolders(folders: WatchFolder[] | undefined) {
    if (folders) watchFolders.value = folders
  }

  return {
    watchFolders,
    loading,
    watchingCount,
    unresolvedCount,
    activeWatchFolders,
    loadWatchFolders,
    refresh,
    setFolders,
  }
})
