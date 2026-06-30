<script setup>
/**
 * CrmProfitabilityMini - Compact profitability table for dashboard embedding
 * Shows per-client revenue vs hours with effective rate and health indicator.
 */
import { ref, onMounted } from 'vue'
import api from '@/services/api'
import { useToastStore } from '@/stores/toast'

const toast = useToastStore()
const loading = ref(true)
const data = ref(null)

onMounted(async () => {
  loading.value = true
  try {
    const res = await api.get('/crm/reports/profitability')
    if (res.data?.success) data.value = res.data.data
  } catch (e) {
    toast.error('Failed to load profitability data')
  } finally {
    loading.value = false
  }
})

function formatCurrency(val) {
  if (val === null || val === undefined) return '--'
  const num = parseFloat(val)
  if (num >= 1_000_000) return `${(num / 1_000_000).toFixed(1)}M`
  if (num >= 1_000) return `${(num / 1_000).toFixed(0)}K`
  return `${num.toFixed(0)}`
}

const healthBadge = {
  profitable: { text: 'Profitable', class: 'text-green-600 dark:text-green-400 bg-green-50 dark:bg-green-500/10' },
  marginal: { text: 'Marginal', class: 'text-amber-600 dark:text-amber-400 bg-amber-50 dark:bg-amber-500/10' },
  unprofitable: { text: 'Unprofitable', class: 'text-red-600 dark:text-red-400 bg-red-50 dark:bg-red-500/10' },
  unknown: { text: 'N/A', class: 'text-surface-400 bg-surface-100 dark:bg-surface-700' },
}
</script>

<template>
  <!-- Loading -->
  <div v-if="loading" class="flex items-center justify-center py-8">
    <div class="animate-spin w-6 h-6 border-2 border-primary-500 border-t-transparent rounded-full"></div>
  </div>

  <template v-else-if="data?.clients?.length">
    <!-- Activity breakdown summary -->
    <div v-if="data.activity_breakdown?.length" class="flex flex-wrap gap-2 mb-4">
      <span
        v-for="act in data.activity_breakdown.slice(0, 5)" :key="act.activity_type"
        class="text-xs px-2.5 py-1 rounded-full bg-surface-100 dark:bg-surface-700 text-surface-600 dark:text-surface-300"
      >
        {{ act.activity_type.replace(/_/g, ' ') }}: {{ act.total_hours }}h
      </span>
    </div>

    <!-- Client list -->
    <div class="space-y-2 max-h-[400px] overflow-y-auto">
      <div
        v-for="client in data.clients" :key="client.id"
        class="flex items-center gap-3 p-3 rounded-lg border border-surface-100 dark:border-[rgb(var(--color-border))] hover:bg-surface-50 dark:hover:bg-[rgb(var(--color-surface-hover))] transition-colors"
      >
        <div class="flex-1 min-w-0">
          <p class="text-sm font-medium text-surface-900 dark:text-white truncate">{{ client.name || client.domain }}</p>
          <div class="flex items-center gap-3 mt-1 text-xs text-surface-500">
            <span>Revenue: {{ formatCurrency(client.revenue) }}</span>
            <span>Hours: {{ client.hours > 0 ? `${client.hours}h` : '--' }}</span>
          </div>
        </div>
        <div class="flex flex-col items-end gap-1 flex-shrink-0">
          <span class="text-sm font-bold text-surface-900 dark:text-white">
            {{ client.effective_rate !== null ? `${formatCurrency(client.effective_rate)}/h` : '--' }}
          </span>
          <span :class="['text-[10px] font-medium px-2 py-0.5 rounded-full', healthBadge[client.rate_health]?.class]">
            {{ healthBadge[client.rate_health]?.text }}
          </span>
        </div>
      </div>
    </div>
  </template>

  <div v-else class="text-center py-8 text-surface-400">
    <span class="material-symbols-rounded text-3xl">savings</span>
    <p class="text-sm mt-2">No profitability data yet</p>
  </div>
</template>

