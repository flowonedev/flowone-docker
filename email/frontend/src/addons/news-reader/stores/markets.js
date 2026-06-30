import { defineStore } from 'pinia'
import { ref } from 'vue'
import { fetchMarketsOverview } from '@/addons/news-reader/services/marketsApi'

/**
 * Pinia store for the markets panel inside the News reader. Holds the
 * latest stocks + crypto rows and an auto-refresh timer that starts /
 * stops with the panel's lifecycle (`startPolling` on mount,
 * `stopPolling` on unmount). The backend handles caching, so polling
 * every 60s here is safe.
 */
export const useMarketsStore = defineStore('newsReaderMarkets', () => {
  const stocks = ref([])
  const crypto = ref([])
  const updatedAt = ref(null)
  const loading = ref(false)
  const error = ref(null)

  let timer = null
  let activeListeners = 0

  async function load() {
    if (loading.value) return
    loading.value = true
    error.value = null
    try {
      const data = await fetchMarketsOverview()
      stocks.value = Array.isArray(data.stocks) ? data.stocks : []
      crypto.value = Array.isArray(data.crypto) ? data.crypto : []
      updatedAt.value = data.updated_at || Date.now() / 1000
    } catch (e) {
      error.value = e?.message || 'Failed to load markets'
    } finally {
      loading.value = false
    }
  }

  /**
   * Start a 60-second refresh loop. Multiple components can call
   * startPolling() without each opening their own timer (we ref-count
   * the listeners and only stop when the last one unmounts).
   */
  function startPolling(intervalMs = 60_000) {
    activeListeners += 1
    if (timer === null) {
      load()
      timer = setInterval(load, intervalMs)
    }
  }

  function stopPolling() {
    activeListeners = Math.max(0, activeListeners - 1)
    if (activeListeners === 0 && timer !== null) {
      clearInterval(timer)
      timer = null
    }
  }

  return {
    stocks,
    crypto,
    updatedAt,
    loading,
    error,
    load,
    startPolling,
    stopPolling,
  }
})
