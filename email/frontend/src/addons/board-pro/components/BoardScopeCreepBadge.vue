<script setup>
/**
 * BoardScopeCreepBadge - Shows scope creep severity indicator on boards/clients.
 * Fetches scope radar summary and displays a compact badge.
 */
import { ref, onMounted, watch } from 'vue'
import api from '@/services/api'

const props = defineProps({
  boardId: { type: [Number, String], required: true },
  size: { type: String, default: 'sm' },
})

const severity = ref(null)
const flaggedCount = ref(0)
const loading = ref(true)

async function fetchStatus() {
  if (!props.boardId) return
  loading.value = true
  try {
    const res = await api.get(`/board-pro/boards/${props.boardId}/scope-radar`)
    if (res.data?.success) {
      const s = res.data.data?.summary
      severity.value = s?.board_severity || 'normal'
      flaggedCount.value = s?.flagged_cards || 0
    }
  } catch {
    severity.value = null
  } finally {
    loading.value = false
  }
}

onMounted(fetchStatus)
watch(() => props.boardId, fetchStatus)

const severityConfig = {
  critical: {
    label: 'Critical',
    icon: 'error',
    classes: 'text-red-600 dark:text-red-400 bg-red-50 dark:bg-red-500/10',
  },
  high: {
    label: 'High Risk',
    icon: 'warning',
    classes: 'text-orange-600 dark:text-orange-400 bg-orange-50 dark:bg-orange-500/10',
  },
  warning: {
    label: 'Warning',
    icon: 'flag',
    classes: 'text-amber-600 dark:text-amber-400 bg-amber-50 dark:bg-amber-500/10',
  },
}

const sizeClasses = {
  sm: 'text-[10px] px-1.5 py-0.5 gap-0.5',
  md: 'text-xs px-2 py-1 gap-1',
}
</script>

<template>
  <span
    v-if="severity && severity !== 'normal' && severityConfig[severity]"
    :class="[
      'inline-flex items-center font-medium rounded-full',
      severityConfig[severity].classes,
      sizeClasses[size] || sizeClasses.sm,
    ]"
    :title="`${flaggedCount} flagged cards`"
  >
    <span class="material-symbols-rounded" :class="size === 'sm' ? 'text-xs' : 'text-sm'">
      {{ severityConfig[severity].icon }}
    </span>
    {{ severityConfig[severity].label }}
  </span>
</template>
