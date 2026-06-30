<script setup>
import { computed, onMounted, onUnmounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { useStorageStore } from '@/stores/storage'

/**
 * Compact header indicator for the drive storage budget watermark.
 *
 * Visibility rules:
 *   - hidden when watermark is null or 'clear' (don't pester users
 *     in the normal case)
 *   - subtle yellow chip at 'warn'
 *   - pulsing orange chip at 'high'
 *   - solid red chip at 'critical'
 *   - operator badges (reclaim active, backup unhealthy) show on hover
 *
 * Always shown as a small icon-chip in the header; clicking it
 * routes admins to the storage dashboard.
 */
const storage = useStorageStore()
const { t } = useI18n()

const visible = computed(() => {
  if (!storage.available) return false
  const w = storage.watermark
  return w === 'warn' || w === 'high' || w === 'critical'
})

const icon = computed(() => {
  if (storage.isCritical) return 'error'
  if (storage.isHigh)     return 'warning'
  if (storage.isWarn)     return 'cloud_alert'
  return 'cloud_done'
})

const colorClass = computed(() => {
  if (storage.isCritical) return 'text-red-100 bg-red-600 hover:bg-red-700'
  if (storage.isHigh)     return 'text-amber-100 bg-amber-600 hover:bg-amber-700 animate-pulse'
  if (storage.isWarn)     return 'text-amber-700 bg-amber-100 dark:text-amber-300 dark:bg-amber-900/40 hover:bg-amber-200'
  return 'text-surface-500 bg-transparent'
})

const tooltip = computed(() => {
  const w = storage.watermark || 'clear'
  const labelKey = `storage.watermark.${w}`
  let s = t(labelKey, w)
  if (typeof storage.driveUsedPct === 'number') {
    s += ` — ${storage.driveUsedPct.toFixed(0)}% ${t('storage.driveUsed', 'drive used')}`
  }
  if (storage.reclaimActive) {
    s += ` · ${t('storage.reclaimActive', 'reclaim running')}`
  }
  if (storage.reclaimPaused) {
    s += ` · ${t('storage.reclaimPaused', 'reclaim paused')}`
  }
  return s
})

onMounted(() => storage.startPolling())
onUnmounted(() => storage.stopPolling())
</script>

<template>
  <button
    v-if="visible"
    type="button"
    class="inline-flex items-center gap-1 px-2 py-1 rounded-full text-xs cursor-help transition-colors"
    :class="colorClass"
    :title="tooltip"
    :aria-label="tooltip"
    @click="$router.push('/admin/storage')"
  >
    <span class="material-symbols-rounded text-base">{{ icon }}</span>
    <span class="hidden sm:inline">{{ t(`storage.watermark.${storage.watermark}`, storage.watermark || '') }}</span>
  </button>
</template>
