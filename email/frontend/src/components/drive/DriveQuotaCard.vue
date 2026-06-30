<script setup>
import { computed } from 'vue'
import { useDriveStore } from '@/stores/drive'

const drive = useDriveStore()

const barColor = computed(() => {
  const pct = drive.formattedQuota.percentUsed
  if (pct > 90) return 'bg-red-500'
  if (pct > 70) return 'bg-amber-500'
  return 'bg-gradient-to-r from-primary-400 to-violet-500'
})

// Unlimited plans show a decorative full gradient strip (per mock); limited
// plans show actual usage percent.
const barWidth = computed(() => {
  if (drive.quota.unlimited) return '100%'
  return `${Math.min(100, drive.formattedQuota.percentUsed || 0)}%`
})
</script>

<template>
  <div class="drive-quota-card border-t border-surface-200 dark:border-surface-700">
    <div class="flex items-center gap-2.5 px-3 pt-2.5 pb-2">
      <div class="w-8 h-8 rounded-full bg-primary-100 dark:bg-primary-500/20 flex items-center justify-center flex-shrink-0">
        <span class="material-symbols-rounded text-base text-primary-500">cloud_upload</span>
      </div>

      <div class="flex-1 min-w-0">
        <div class="flex items-baseline gap-1 flex-wrap leading-tight">
          <span class="text-[13px] font-semibold text-surface-900 dark:text-surface-100 truncate">
            {{ drive.formattedQuota.used }}
          </span>
          <span class="text-xs text-surface-500 truncate">
            {{ $t('driveView.usedOfQuota', { quota: drive.formattedQuota.quota }) }}
          </span>
        </div>

        <div
          v-if="drive.quota.unlimited"
          class="text-[11px] text-primary-600 dark:text-primary-400 flex items-center gap-1 mt-0.5"
        >
          <span class="material-symbols-rounded text-xs">all_inclusive</span>
          {{ $t('driveView.unlimitedStorage') }}
        </div>
        <div
          v-else
          class="text-[11px] text-surface-500 dark:text-surface-400 mt-0.5"
        >
          {{ Math.min(100, drive.formattedQuota.percentUsed || 0) }}%
        </div>
      </div>
    </div>

    <!-- Usage bar pinned to the very bottom edge of the sidebar (per mock) -->
    <div class="h-1 w-full bg-surface-100 dark:bg-surface-800">
      <div
        :class="['h-1 transition-all', barColor]"
        :style="{ width: barWidth }"
      ></div>
    </div>
  </div>
</template>
