<script setup>
/**
 * CrmClientRanking - Client value leaderboard
 * Sortable table showing revenue, deal count, hours tracked, effective rate.
 */
import { ref, computed, onMounted } from 'vue'
import api from '@/services/api'
import { useToastStore } from '@/stores/toast'

const toast = useToastStore()
const loading = ref(true)
const data = ref(null)
const sortField = ref('revenue')
const sortAsc = ref(false)
const periodMonths = ref(0) // 0 = lifetime

onMounted(() => fetchData())

async function fetchData() {
  loading.value = true
  try {
    const params = periodMonths.value > 0 ? { period: periodMonths.value } : {}
    const res = await api.get('/crm/reports/client-ranking', { params })
    if (res.data?.success) data.value = res.data.data
  } catch (e) {
    toast.error('Failed to load client ranking')
  } finally {
    loading.value = false
  }
}

function setPeriod(months) {
  periodMonths.value = months
  fetchData()
}

const sortedClients = computed(() => {
  if (!data.value?.clients) return []
  const list = [...data.value.clients]
  list.sort((a, b) => {
    const aVal = a[sortField.value] ?? 0
    const bVal = b[sortField.value] ?? 0
    return sortAsc.value ? aVal - bVal : bVal - aVal
  })
  return list
})

function toggleSort(field) {
  if (sortField.value === field) {
    sortAsc.value = !sortAsc.value
  } else {
    sortField.value = field
    sortAsc.value = false
  }
}

function sortIcon(field) {
  if (sortField.value !== field) return 'unfold_more'
  return sortAsc.value ? 'arrow_upward' : 'arrow_downward'
}

function formatCurrency(val) {
  if (val === null || val === undefined) return '--'
  const num = parseFloat(val)
  if (num >= 1_000_000) return `${(num / 1_000_000).toFixed(1)}M`
  if (num >= 1_000) return `${(num / 1_000).toFixed(0)}K`
  return `${num.toFixed(0)}`
}

const periods = [
  { label: 'Lifetime', value: 0 },
  { label: '12M', value: 12 },
  { label: '6M', value: 6 },
  { label: '3M', value: 3 },
]
</script>

<template>
  <div>
    <!-- Period selector -->
    <div class="flex items-center justify-between mb-4">
      <p class="text-sm text-surface-500">
        Total: <span class="font-bold text-surface-900 dark:text-white">{{ formatCurrency(data?.total_revenue ?? 0) }} HUF</span>
      </p>
      <div class="flex gap-1">
        <button
          v-for="p in periods" :key="p.value"
          @click="setPeriod(p.value)"
          :class="[
            'px-3 py-1.5 rounded-full text-xs font-medium transition-colors',
            periodMonths === p.value
              ? 'bg-primary-100 dark:bg-primary-500/20 text-primary-700 dark:text-primary-300'
              : 'text-surface-500 hover:bg-surface-100 dark:hover:bg-surface-700'
          ]"
        >{{ p.label }}</button>
      </div>
    </div>

    <!-- Loading -->
    <div v-if="loading" class="flex items-center justify-center py-12">
      <div class="animate-spin w-6 h-6 border-2 border-primary-500 border-t-transparent rounded-full"></div>
    </div>

    <!-- Table -->
    <div v-else-if="sortedClients.length" class="overflow-x-auto">
      <table class="w-full text-sm">
        <thead>
          <tr class="border-b border-surface-200 dark:border-[rgb(var(--color-border))]">
            <th class="text-left py-3 px-2 text-xs font-medium text-surface-500 uppercase tracking-wider w-8">#</th>
            <th class="text-left py-3 px-2 text-xs font-medium text-surface-500 uppercase tracking-wider">Client</th>
            <th
              v-for="col in [
                { key: 'revenue', label: 'Revenue' },
                { key: 'deal_count', label: 'Deals' },
                { key: 'won_count', label: 'Won' },
                { key: 'hours_tracked', label: 'Hours' },
                { key: 'effective_hourly_rate', label: 'Eff. Rate' },
              ]" :key="col.key"
              class="text-right py-3 px-2 text-xs font-medium text-surface-500 uppercase tracking-wider cursor-pointer select-none hover:text-surface-700 dark:hover:text-surface-300"
              @click="toggleSort(col.key)"
            >
              <div class="flex items-center justify-end gap-1">
                {{ col.label }}
                <span class="material-symbols-rounded text-xs">{{ sortIcon(col.key) }}</span>
              </div>
            </th>
          </tr>
        </thead>
        <tbody>
          <tr
            v-for="(client, i) in sortedClients" :key="client.id"
            class="border-b border-surface-100 dark:border-surface-800 hover:bg-surface-50 dark:hover:bg-surface-800/50 transition-colors"
          >
            <td class="py-3 px-2 text-surface-400 font-medium">{{ i + 1 }}</td>
            <td class="py-3 px-2">
              <p class="font-medium text-surface-900 dark:text-white truncate max-w-[200px]">{{ client.name || client.domain || 'Unnamed' }}</p>
            </td>
            <td class="py-3 px-2 text-right font-bold text-surface-900 dark:text-white">{{ formatCurrency(client.revenue) }}</td>
            <td class="py-3 px-2 text-right text-surface-600 dark:text-surface-300">{{ client.deal_count }}</td>
            <td class="py-3 px-2 text-right text-green-600 dark:text-green-400">{{ client.won_count }}</td>
            <td class="py-3 px-2 text-right text-surface-600 dark:text-surface-300">{{ client.hours_tracked > 0 ? `${client.hours_tracked}h` : '--' }}</td>
            <td class="py-3 px-2 text-right">
              <span v-if="client.effective_hourly_rate !== null" class="font-medium" :class="client.effective_hourly_rate >= 10000 ? 'text-green-600 dark:text-green-400' : client.effective_hourly_rate >= 5000 ? 'text-surface-900 dark:text-white' : 'text-red-600 dark:text-red-400'">
                {{ formatCurrency(client.effective_hourly_rate) }}/h
              </span>
              <span v-else class="text-surface-400">--</span>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- Empty -->
    <div v-else class="text-center py-12 text-surface-400">
      <span class="material-symbols-rounded text-4xl">leaderboard</span>
      <p class="text-sm mt-2">No client data available yet</p>
    </div>
  </div>
</template>

