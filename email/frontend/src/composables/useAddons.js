/**
 * useAddons - Composable to check addon feature flags
 * 
 * Fetches addon statuses from the backend (which proxies from the Panel)
 * and caches them. Used throughout the app to conditionally show/hide
 * addon-gated features (CRM Pro, Mood Boards, Kanban Boards, etc.).
 *
 * Auto-refreshes when the browser tab regains focus so Panel toggles
 * take effect immediately without a full page reload.
 */

import { ref, computed } from 'vue'
import api from '@/services/api'

// Module-level singleton state so all consumers share the same data
const addons = ref({})
const loaded = ref(false)
const loading = ref(false)
const error = ref(null)
let fetchPromise = null
let listenersRegistered = false
let lastRefreshTime = 0
const REFRESH_THROTTLE_MS = 90000 // Don't refresh more than once every 90s

/**
 * Fetch addon statuses from backend.
 * 
 * Uses POST /addons/refresh (which clears the backend Redis cache and
 * re-fetches live from the Panel) to ensure the frontend always shows
 * the real-time toggle state.  Falls back to GET /addons (Redis-cached)
 * if the refresh call fails (e.g. token not yet available on first load).
 *
 * Deduplicates concurrent calls — only one HTTP request in flight at a time.
 */
async function fetchAddons(force = false) {
  if (loaded.value && !force) return addons.value
  if (loading.value && fetchPromise) return fetchPromise

  loading.value = true
  error.value = null

  fetchPromise = (async () => {
    try {
      // Prefer the refresh endpoint (clears Redis cache, fetches fresh from Panel)
      let response
      try {
        response = await api.post('/addons/refresh')
      } catch (_) {
        // Fallback: GET /addons (uses Redis cache — still better than nothing)
        response = await api.get('/addons')
      }
      if (response.data?.success && response.data?.data) {
        addons.value = response.data.data
      } else {
        // Fallback: treat as all disabled
        addons.value = {}
      }
      loaded.value = true
      return addons.value
    } catch (err) {
      console.warn('[useAddons] Failed to fetch addon statuses:', err)
      error.value = err
      // On error, default to empty (all disabled) - fail closed
      if (!loaded.value) {
        addons.value = {}
      }
      return addons.value
    } finally {
      loading.value = false
      fetchPromise = null
    }
  })()

  return fetchPromise
}

/**
 * Force refresh addon statuses (clears server cache too)
 */
async function refreshAddons() {
  loading.value = true
  error.value = null
  try {
    const response = await api.post('/addons/refresh')
    if (response.data?.success && response.data?.data) {
      addons.value = response.data.data
    }
    loaded.value = true
    return addons.value
  } catch (err) {
    console.warn('[useAddons] Failed to refresh addon statuses:', err)
    error.value = err
    return addons.value
  } finally {
    loading.value = false
  }
}

function hydrateAddons(data) {
  addons.value = data || {}
  loaded.value = true
}

export function useAddons() {
  const crmProEnabled = computed(() => !!addons.value?.crm_pro)
  const moodboardsEnabled = computed(() => !!addons.value?.moodboards)
  const kanbanBoardsEnabled = computed(() => !!addons.value?.kanban_boards)
  const chatEnabled = computed(() => !!addons.value?.chat)
  const emailMarketingEnabled = computed(() => !!addons.value?.email_marketing)
  const teamEnabled = computed(() => !!addons.value?.team)
  const calendarEnabled = computed(() => !!addons.value?.calendar)
  const tasksEnabled = computed(() => !!addons.value?.tasks)
  const emailTrackingEnabled = computed(() => !!addons.value?.email_tracking)
  const timeTrackerEnabled = computed(() => !!addons.value?.time_tracker)
  const reactionsEnabled = computed(() => !!addons.value?.reactions)
  const aiAssistantEnabled = computed(() => !!addons.value?.ai_assistant)
  const boardProEnabled = computed(() => !!addons.value?.board_pro)
  const projectHubEnabled = computed(() => !!addons.value?.project_hub)
  const automationHubEnabled = computed(() => !!addons.value?.automation_hub)
  const universalSearchEnabled = computed(() => !!addons.value?.universal_search)
  const newsReaderEnabled = computed(() => !!addons.value?.news_reader)

  // Auto-refresh when the user switches back to this tab/window
  // (e.g. after toggling addons in the Panel in another tab)
  // Uses refreshAddons() (POST /addons/refresh) to clear backend Redis cache
  // and re-fetch live from the Panel.
  // Both visibilitychange AND window focus are used because:
  //   - visibilitychange fires on tab switch but not always on window focus
  //   - focus fires on window activation (important for Electron desktop app)
  // Throttled to avoid duplicate refreshes when both events fire together.
  if (!listenersRegistered && typeof document !== 'undefined') {
    listenersRegistered = true

    const throttledRefresh = () => {
      if (!loaded.value) return
      const now = Date.now()
      if (now - lastRefreshTime < REFRESH_THROTTLE_MS) return
      lastRefreshTime = now
      refreshAddons()
    }

    document.addEventListener('visibilitychange', () => {
      if (document.visibilityState === 'visible') throttledRefresh()
    })
    window.addEventListener('focus', throttledRefresh)
  }

  return {
    // State
    addons,
    loaded,
    loading,
    error,

    // Computed flags
    crmProEnabled,
    moodboardsEnabled,
    kanbanBoardsEnabled,
    chatEnabled,
    emailMarketingEnabled,
    teamEnabled,
    calendarEnabled,
    tasksEnabled,
    emailTrackingEnabled,
    timeTrackerEnabled,
    reactionsEnabled,
    aiAssistantEnabled,
    boardProEnabled,
    projectHubEnabled,
    automationHubEnabled,
    universalSearchEnabled,
    newsReaderEnabled,

    // Methods
    fetchAddons,
    refreshAddons,
    hydrateAddons,
  }
}

