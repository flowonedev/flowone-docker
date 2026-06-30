<script setup>
import { computed, onMounted } from 'vue'
import { useMailboxStore } from '@/stores/mailbox'

const mailbox = useMailboxStore()

// Fetch lazily on mount; the store guards against failures and the card stays
// hidden unless the server reports an enforced mailbox limit.
onMounted(() => { mailbox.fetchMailboxQuota() })

const quota = computed(() => mailbox.formattedMailboxQuota)

const barColor = computed(() => {
  const pct = quota.value.percentUsed
  if (pct > 90) return 'bg-red-500'
  if (pct > 70) return 'bg-amber-500'
  return 'mail-quota-fill bg-gradient-to-r from-primary-400 to-violet-500'
})

const barWidth = computed(() => `${Math.min(100, quota.value.percentUsed || 0)}%`)
</script>

<template>
  <div
    v-if="quota.enabled"
    class="mail-quota-card border-t border-surface-200 dark:border-surface-700"
  >
    <div class="flex items-center gap-2.5 px-3 pt-2.5 pb-2">
      <div class="w-8 h-8 rounded-full bg-primary-100 dark:bg-primary-500/20 flex items-center justify-center flex-shrink-0">
        <span class="material-symbols-rounded text-base text-primary-500">mail</span>
      </div>

      <div class="flex-1 min-w-0">
        <div class="flex items-baseline gap-1 flex-wrap leading-tight">
          <span class="text-[13px] font-semibold text-surface-900 dark:text-surface-100 truncate">
            {{ quota.used }}
          </span>
          <span class="text-xs text-surface-500 truncate">
            {{ $t('folderTree.usedOfQuota', { quota: quota.quota }) }}
          </span>
        </div>
        <div class="text-[11px] text-surface-500 dark:text-surface-400 mt-0.5">
          {{ Math.min(100, quota.percentUsed || 0) }}% &middot; {{ $t('folderTree.emailStorage') }}
        </div>
      </div>
    </div>

    <div class="h-1 w-full bg-surface-100 dark:bg-surface-800">
      <div
        :class="['h-1 transition-all', barColor]"
        :style="{ width: barWidth }"
      ></div>
    </div>
  </div>
</template>
