import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import api from '@/services/api'

/**
 * Phase 8 — Storage signals store.
 *
 * Polls /api/storage/status periodically and exposes:
 *   - watermark (clear|warn|high|critical|null) — the headline gauge
 *   - drive usage %
 *   - reclaim daemon state (idle|warming|reclaiming|cooldown|paused|null)
 *   - backup last-success date + last-failure reason
 *
 * Also offers an on-demand per-file tier lookup
 * (`fetchTierState(fileId)`), cached in-memory for the session so the
 * drive grid can show TierBadge without N requests per render.
 *
 * Designed to fail safely:
 *   - Backend is missing the Phase 8 endpoint?     -> available=false
 *   - Backend returns { available: false }?         -> available=false
 *   - Network blip?                                 -> last good values
 *     remain visible; we never wipe the UI on a transient fetch
 *     failure.
 */

const POLL_MS = 60_000
const TIER_CACHE_TTL_MS = 60_000

export const useStorageStore = defineStore('storage', () => {
  const available = ref(false)
  const reason = ref(null)
  const budget = ref(null)
  const reclaim = ref(null)
  const backup = ref(null)
  const lastFetchedAt = ref(0)
  const isFetching = ref(false)

  // Per-file tier_state cache (Map<fileId, { tier_state, expires_at }>).
  // Kept simple — never grows huge because the drive list view only
  // ever displays a couple of hundred rows at a time.
  const tierCache = new Map()

  let pollTimer = null

  const watermark = computed(() => budget.value?.watermark || null)
  const isCritical = computed(() => watermark.value === 'critical')
  const isHigh = computed(() => watermark.value === 'high')
  const isWarn = computed(() => watermark.value === 'warn')
  const isPressured = computed(() => isHigh.value || isCritical.value)
  const driveUsedPct = computed(() => budget.value?.drive_used_pct ?? null)
  const vpsUsedPct = computed(() => budget.value?.vps_used_pct ?? null)
  const reclaimPaused = computed(() => reclaim.value?.paused === true)
  const reclaimActive = computed(() => reclaim.value?.state === 'reclaiming' || reclaim.value?.state === 'warming')
  const backupHealthy = computed(() => {
    const b = backup.value
    if (!b || !b.enabled) return null
    if (b.last_failure) return false
    if (!b.last_snapshot_at) return null
    // Stale if last snapshot started more than 36 hours ago.
    const ageSec = Math.floor(Date.now() / 1000) - Number(b.last_snapshot_at)
    return ageSec < 36 * 3600
  })

  async function refresh() {
    if (isFetching.value) return
    isFetching.value = true
    try {
      const { data } = await api.get('/storage/status')
      const payload = data?.data ?? data
      available.value = !!payload?.available
      reason.value = payload?.reason ?? null
      budget.value = payload?.budget ?? null
      reclaim.value = payload?.reclaim ?? null
      backup.value = payload?.backup ?? null
      lastFetchedAt.value = Date.now()
    } catch (e) {
      // Keep last good values; mark stale via lastFetchedAt staleness check.
      // Don't wipe `available` to false unless the endpoint *itself* says so.
      if (e?.response?.status === 404) {
        available.value = false
        reason.value = 'endpoint_not_available'
      }
    } finally {
      isFetching.value = false
    }
  }

  function startPolling() {
    if (pollTimer) return
    refresh()
    pollTimer = setInterval(refresh, POLL_MS)
  }

  function stopPolling() {
    if (pollTimer) {
      clearInterval(pollTimer)
      pollTimer = null
    }
  }

  async function fetchTierState(fileId) {
    const id = Number(fileId)
    if (!Number.isFinite(id) || id <= 0) return null
    const cached = tierCache.get(id)
    if (cached && cached.expires_at > Date.now()) return cached
    try {
      const { data } = await api.get(`/storage/files/${id}/tier`)
      const payload = data?.data ?? data
      const entry = {
        tier_state: payload?.tier_state || 'hot',
        storage_location: payload?.storage_location || 'local',
        tier_changed_at: payload?.tier_changed_at || null,
        last_read_at: payload?.last_read_at || null,
        expires_at: Date.now() + TIER_CACHE_TTL_MS,
      }
      tierCache.set(id, entry)
      return entry
    } catch (e) {
      return null
    }
  }

  /**
   * Update the in-memory tier cache from a row already in the drive
   * list payload (the list query returns tier_state inline, so we
   * can populate without an extra request).
   */
  function primeTierFromRow(fileId, tierState, extras = {}) {
    const id = Number(fileId)
    if (!Number.isFinite(id) || id <= 0 || !tierState) return
    tierCache.set(id, {
      tier_state: tierState,
      storage_location: extras.storage_location || 'local',
      tier_changed_at: extras.tier_changed_at || null,
      last_read_at: extras.last_read_at || null,
      expires_at: Date.now() + TIER_CACHE_TTL_MS,
    })
  }

  function getCachedTier(fileId) {
    const id = Number(fileId)
    if (!Number.isFinite(id) || id <= 0) return null
    const e = tierCache.get(id)
    return e && e.expires_at > Date.now() ? e : null
  }

  function clearTierCache() {
    tierCache.clear()
  }

  return {
    // state
    available,
    reason,
    budget,
    reclaim,
    backup,
    lastFetchedAt,
    isFetching,
    // computed
    watermark,
    isCritical,
    isHigh,
    isWarn,
    isPressured,
    driveUsedPct,
    vpsUsedPct,
    reclaimPaused,
    reclaimActive,
    backupHealthy,
    // actions
    refresh,
    startPolling,
    stopPolling,
    fetchTierState,
    primeTierFromRow,
    getCachedTier,
    clearTierCache,
  }
})
