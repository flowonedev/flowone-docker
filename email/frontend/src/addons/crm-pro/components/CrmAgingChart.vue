<script setup>
/**
 * CrmAgingChart - Invoice aging report visualization
 * Shows 4 buckets (0-30, 31-60, 61-90, 90+) with bar chart and invoice list.
 */
import { ref, computed, onMounted } from 'vue'
import api from '@/services/api'
import { useToastStore } from '@/stores/toast'

const toast = useToastStore()
const loading = ref(true)
const data = ref(null)
const expandedBucket = ref(null)

const bucketColors = {
  '0-30': { bar: 'bg-green-500', bg: 'bg-green-50 dark:bg-green-500/10', text: 'text-green-700 dark:text-green-400', border: 'border-green-200 dark:border-green-500/30' },
  '31-60': { bar: 'bg-yellow-500', bg: 'bg-yellow-50 dark:bg-yellow-500/10', text: 'text-yellow-700 dark:text-yellow-400', border: 'border-yellow-200 dark:border-yellow-500/30' },
  '61-90': { bar: 'bg-orange-500', bg: 'bg-orange-50 dark:bg-orange-500/10', text: 'text-orange-700 dark:text-orange-400', border: 'border-orange-200 dark:border-orange-500/30' },
  '90+': { bar: 'bg-red-500', bg: 'bg-red-50 dark:bg-red-500/10', text: 'text-red-700 dark:text-red-400', border: 'border-red-200 dark:border-red-500/30' },
}

const bucketLabels = { '0-30': '0-30 days', '31-60': '31-60 days', '61-90': '61-90 days', '90+': '90+ days' }

onMounted(async () => {
  await fetchData()
})

async function fetchData() {
  loading.value = true
  try {
    const res = await api.get('/crm/reports/aging')
    if (res.data?.success) data.value = res.data.data
  } catch (e) {
    toast.error('Failed to load aging report')
  } finally {
    loading.value = false
  }
}

const maxBucketValue = computed(() => {
  if (!data.value?.buckets?.length) return 1
  return Math.max(...data.value.buckets.map(b => b.outstanding), 1)
})

function formatCurrency(val) {
  if (val === null || val === undefined) return '--'
  const num = parseFloat(val)
  if (num >= 1_000_000) return `${(num / 1_000_000).toFixed(1)}M`
  if (num >= 1_000) return `${(num / 1_000).toFixed(0)}K`
  return `${num.toFixed(0)}`
}

function toggleBucket(key) {
  expandedBucket.value = expandedBucket.value === key ? null : key
}
</script>

<template>
  <div>
    <!-- Loading -->
    <div v-if="loading" class="flex items-center justify-center py-12">
      <div class="animate-spin w-6 h-6 border-2 border-primary-500 border-t-transparent rounded-full"></div>
    </div>

    <template v-else-if="data">
      <!-- Summary -->
      <div class="flex items-center justify-between mb-6">
        <div>
          <p class="text-2xl font-bold text-surface-900 dark:text-white">{{ formatCurrency(data.total_outstanding) }} HUF</p>
          <p class="text-sm text-surface-500">{{ data.total_count }} overdue invoice{{ data.total_count !== 1 ? 's' : '' }}</p>
        </div>
      </div>

      <!-- Empty state -->
      <div v-if="data.total_count === 0" class="text-center py-12 text-surface-400">
        <span class="material-symbols-rounded text-4xl">check_circle</span>
        <p class="text-sm mt-2">No overdue invoices</p>
      </div>

      <!-- Aging buckets -->
      <div v-else class="space-y-3">
        <div
          v-for="bucket in data.buckets" :key="bucket.bucket"
          :class="['rounded-xl border p-4 cursor-pointer transition-all', bucketColors[bucket.bucket]?.border, bucketColors[bucket.bucket]?.bg]"
          @click="toggleBucket(bucket.bucket)"
        >
          <div class="flex items-center justify-between mb-2">
            <div class="flex items-center gap-3">
              <span class="text-sm font-semibold" :class="bucketColors[bucket.bucket]?.text">
                {{ bucketLabels[bucket.bucket] }}
              </span>
              <span class="text-xs text-surface-500 bg-white dark:bg-[rgb(var(--color-surface))] px-2 py-0.5 rounded-full">
                {{ bucket.count }} invoice{{ bucket.count !== 1 ? 's' : '' }}
              </span>
            </div>
            <span class="text-sm font-bold" :class="bucketColors[bucket.bucket]?.text">
              {{ formatCurrency(bucket.outstanding) }} HUF
            </span>
          </div>

          <!-- Bar -->
          <div class="w-full bg-white/50 dark:bg-surface-700/50 rounded-full h-3">
            <div
              :class="['h-3 rounded-full transition-all', bucketColors[bucket.bucket]?.bar]"
              :style="{ width: `${Math.max((bucket.outstanding / maxBucketValue) * 100, bucket.count > 0 ? 4 : 0)}%` }"
            ></div>
          </div>

          <!-- Expand icon -->
          <div v-if="bucket.count > 0" class="flex justify-center mt-2">
            <span class="material-symbols-rounded text-sm text-surface-400 transition-transform" :class="{ 'rotate-180': expandedBucket === bucket.bucket }">
              expand_more
            </span>
          </div>

          <!-- Expanded invoice list -->
          <div v-if="expandedBucket === bucket.bucket && data.invoices_by_bucket[bucket.bucket]?.length" class="mt-3 space-y-2">
            <div
              v-for="inv in data.invoices_by_bucket[bucket.bucket]" :key="inv.id"
              class="flex items-center justify-between p-3 rounded-lg bg-white dark:bg-[rgb(var(--color-surface))] border border-surface-200 dark:border-[rgb(var(--color-border))]"
            >
              <div class="min-w-0">
                <p class="text-sm font-medium text-surface-900 dark:text-white truncate">{{ inv.invoice_number }}</p>
                <p class="text-xs text-surface-400 truncate">{{ inv.client_name || inv.client_domain }}</p>
              </div>
              <div class="text-right flex-shrink-0">
                <p class="text-sm font-bold text-surface-900 dark:text-white">{{ formatCurrency(inv.outstanding_amount) }} {{ inv.currency }}</p>
                <p class="text-xs text-red-500">{{ inv.days_overdue }}d overdue</p>
              </div>
            </div>
          </div>
        </div>
      </div>
    </template>
  </div>
</template>

