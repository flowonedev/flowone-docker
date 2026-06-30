<script setup>
/**
 * CrmForecastChart - Deal forecast visualization
 * Bar chart with optimistic vs weighted values by month, plus unscheduled deals warning.
 */
import { ref, computed, onMounted } from 'vue'
import api from '@/services/api'
import { useToastStore } from '@/stores/toast'

const toast = useToastStore()
const loading = ref(true)
const data = ref(null)
const forecastMonths = ref(6)

onMounted(() => fetchData())

async function fetchData() {
  loading.value = true
  try {
    const res = await api.get('/crm/reports/forecast', { params: { months: forecastMonths.value } })
    if (res.data?.success) data.value = res.data.data
  } catch (e) {
    toast.error('Failed to load forecast')
  } finally {
    loading.value = false
  }
}

function setMonths(m) {
  forecastMonths.value = m
  fetchData()
}

const maxMonthValue = computed(() => {
  if (!data.value?.forecast?.length) return 1
  return Math.max(...data.value.forecast.map(f => f.optimistic_value), 1)
})

function formatCurrency(val) {
  if (val === null || val === undefined) return '--'
  const num = parseFloat(val)
  if (num >= 1_000_000) return `${(num / 1_000_000).toFixed(1)}M`
  if (num >= 1_000) return `${(num / 1_000).toFixed(0)}K`
  return `${num.toFixed(0)}`
}

function formatMonthLabel(monthStr) {
  const [y, m] = monthStr.split('-')
  const months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec']
  return months[parseInt(m) - 1] || m
}

const monthOptions = [3, 6, 12]
</script>

<template>
  <div>
    <!-- Header -->
    <div class="flex items-center justify-between mb-4">
      <div>
        <div class="flex items-center gap-4">
          <div>
            <p class="text-xs text-surface-500 uppercase tracking-wider">Weighted Forecast</p>
            <p class="text-2xl font-bold text-surface-900 dark:text-white">{{ formatCurrency(data?.total_weighted ?? 0) }} HUF</p>
          </div>
          <div class="pl-4 border-l border-surface-200 dark:border-[rgb(var(--color-border))]">
            <p class="text-xs text-surface-500 uppercase tracking-wider">Optimistic</p>
            <p class="text-lg font-semibold text-surface-600 dark:text-surface-300">{{ formatCurrency(data?.total_optimistic ?? 0) }} HUF</p>
          </div>
        </div>
      </div>
      <div class="flex gap-1">
        <button
          v-for="m in monthOptions" :key="m"
          @click="setMonths(m)"
          :class="[
            'px-3 py-1.5 rounded-full text-xs font-medium transition-colors',
            forecastMonths === m
              ? 'bg-primary-100 dark:bg-primary-500/20 text-primary-700 dark:text-primary-300'
              : 'text-surface-500 hover:bg-surface-100 dark:hover:bg-surface-700'
          ]"
        >{{ m }}M</button>
      </div>
    </div>

    <!-- Loading -->
    <div v-if="loading" class="flex items-center justify-center py-12">
      <div class="animate-spin w-6 h-6 border-2 border-primary-500 border-t-transparent rounded-full"></div>
    </div>

    <template v-else-if="data">
      <!-- Chart -->
      <div v-if="data.forecast?.length" class="space-y-2">
        <!-- Legend -->
        <div class="flex items-center gap-4 mb-3 text-xs">
          <span class="flex items-center gap-1.5">
            <span class="w-3 h-3 rounded-sm bg-primary-500/30"></span>
            Optimistic
          </span>
          <span class="flex items-center gap-1.5">
            <span class="w-3 h-3 rounded-sm bg-primary-500"></span>
            Weighted (probability-adjusted)
          </span>
        </div>

        <!-- Bar chart -->
        <div class="flex items-end gap-2 h-48">
          <div
            v-for="month in data.forecast" :key="month.month"
            class="flex-1 flex flex-col items-center gap-1"
          >
            <!-- Values label -->
            <div class="text-center">
              <span v-if="month.weighted_value > 0" class="text-[10px] text-primary-600 dark:text-primary-400 font-medium block">{{ formatCurrency(month.weighted_value) }}</span>
              <span v-if="month.optimistic_value > 0 && month.optimistic_value !== month.weighted_value" class="text-[10px] text-surface-400 block">{{ formatCurrency(month.optimistic_value) }}</span>
            </div>

            <!-- Stacked bar -->
            <div class="w-full relative" :style="{ height: `${Math.max((month.optimistic_value / maxMonthValue) * 100, month.deal_count > 0 ? 8 : 0)}%` }">
              <!-- Optimistic (background) -->
              <div class="absolute inset-0 bg-primary-500/20 dark:bg-primary-500/15 rounded-t-md"></div>
              <!-- Weighted (foreground) -->
              <div
                class="absolute bottom-0 left-0 right-0 bg-primary-500 dark:bg-primary-400 rounded-t-md transition-all"
                :style="{ height: month.optimistic_value > 0 ? `${(month.weighted_value / month.optimistic_value) * 100}%` : '0%' }"
              ></div>
            </div>

            <!-- Month label -->
            <span class="text-[10px] text-surface-400">{{ formatMonthLabel(month.month) }}</span>
            <!-- Deal count -->
            <span v-if="month.deal_count > 0" class="text-[9px] text-surface-400">{{ month.deal_count }}d</span>
          </div>
        </div>
      </div>

      <div v-else class="text-center py-12 text-surface-400">
        <span class="material-symbols-rounded text-4xl">trending_up</span>
        <p class="text-sm mt-2">No scheduled deals to forecast</p>
      </div>

      <!-- Unscheduled deals warning -->
      <div v-if="data.unscheduled_deals?.count > 0" class="mt-4 p-3 rounded-lg bg-amber-50 dark:bg-amber-500/10 border border-amber-200 dark:border-amber-500/30">
        <div class="flex items-center gap-2">
          <span class="material-symbols-rounded text-lg text-amber-600 dark:text-amber-400">warning</span>
          <p class="text-sm text-amber-700 dark:text-amber-300">
            <span class="font-semibold">{{ data.unscheduled_deals.count }} deal{{ data.unscheduled_deals.count !== 1 ? 's' : '' }}</span>
            worth {{ formatCurrency(data.unscheduled_deals.value) }} HUF have no expected close date
          </p>
        </div>
      </div>
    </template>
  </div>
</template>

