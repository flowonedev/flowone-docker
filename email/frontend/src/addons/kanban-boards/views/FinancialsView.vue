<script setup>
/**
 * FinancialsView - Global Financial Overview across ALL boards.
 * 
 * This is a BASE kanban-boards feature — it works with just kanban_boards enabled.
 * When Board Pro is also enabled, it additionally shows card-level revenue estimates.
 * When CRM Pro is also enabled, it additionally shows invoice summaries.
 * Each layer degrades gracefully — the backend returns empty arrays for missing tables.
 */
import { ref, computed, onMounted, onUnmounted, onBeforeUnmount, watch } from 'vue'
import { useRouter } from 'vue-router'
import api from '@/services/api'
import { useToastStore } from '@/stores/toast'
import { useThemeStore } from '@/stores/theme'
import { useAddons } from '@/composables/useAddons'
import VueApexCharts from 'vue3-apexcharts'
import MobileBottomNav from '@/components/MobileBottomNav.vue'
import AppHeader from '@/components/shared/AppHeader.vue'
import WorkflowGuide from '@/addons/kanban-boards/components/WorkflowGuide.vue'
import FeatureGuide from '@/components/shared/FeatureGuide.vue'
import HowItWorksButton from '@/components/shared/HowItWorksButton.vue'
import { featureGuides } from '@/data/featureGuides'

const router = useRouter()
const toast = useToastStore()
const theme = useThemeStore()
const { crmProEnabled, boardProEnabled } = useAddons()

// Workflow guide — only when both Pro addons are active
const showWorkflowGuide = ref(false)
const canShowGuide = computed(() => boardProEnabled.value && crmProEnabled.value)

// Track if component is mounted (prevents ApexCharts "Element not found" errors)
const isMounted = ref(false)

// State
const loading = ref(true)
const financials = ref(null)
const dateFrom = ref('')
const dateTo = ref('')
const selectedCurrency = ref('all')
const groupBy = ref('month') // 'month', 'client', 'board'
const viewMode = ref('timeline') // 'timeline', 'list', 'charts'
const showEstimateBreakdown = ref(false)
const showTierGuide = ref(false)
const financialsGuideData = featureGuides.financials

// Mobile state
const isMobile = ref(false)

function checkMobile() {
  isMobile.value = window.innerWidth < 768
}

// Available currencies
const currencies = ['HUF', 'EUR', 'USD', 'RON']

// Fetch all financials
async function fetchFinancials() {
  loading.value = true
  try {
    const params = new URLSearchParams()
    if (dateFrom.value) params.append('date_from', dateFrom.value)
    if (dateTo.value) params.append('date_to', dateTo.value)
    
    const response = await api.get(`/financials?${params.toString()}`)
    if (response.data.success) {
      financials.value = response.data.data.financials
    }
  } catch (e) {
    console.error('Failed to fetch financials:', e)
    toast.error('Failed to load financial data')
  } finally {
    loading.value = false
  }
}

// Filter and group milestones
const filteredMilestones = computed(() => {
  if (!financials.value?.milestones) return []
  
  let milestones = financials.value.milestones
  
  // Filter by currency
  if (selectedCurrency.value !== 'all') {
    milestones = milestones.filter(m => m.currency === selectedCurrency.value)
  }
  
  return milestones
})

// Grouped data
const groupedData = computed(() => {
  const milestones = filteredMilestones.value
  
  function addToGroup(group, m) {
    group.milestones.push(m)
    group.totals[m.currency] = (group.totals[m.currency] || 0) + m.expected_amount
    if (m.payment_status === 'paid') {
      group.paidTotals[m.currency] = (group.paidTotals[m.currency] || 0) + m.expected_amount
    }
  }
  
  if (groupBy.value === 'month') {
    const groups = {}
    milestones.forEach(m => {
      const key = m.invoice_date ? m.invoice_date.substring(0, 7) : 'unscheduled'
      if (!groups[key]) {
        groups[key] = {
          key,
          label: key === 'unscheduled' ? 'Unscheduled' : formatMonth(key),
          milestones: [],
          totals: {},
          paidTotals: {}
        }
      }
      addToGroup(groups[key], m)
    })
    return Object.values(groups).sort((a, b) => {
      if (a.key === 'unscheduled') return 1
      if (b.key === 'unscheduled') return -1
      return a.key.localeCompare(b.key)
    })
  } else if (groupBy.value === 'client') {
    const groups = {}
    milestones.forEach(m => {
      const key = m.client_id || 'no-client'
      if (!groups[key]) {
        groups[key] = {
          key,
          label: m.client_name || 'No Client',
          milestones: [],
          totals: {},
          paidTotals: {}
        }
      }
      addToGroup(groups[key], m)
    })
    return Object.values(groups).sort((a, b) => a.label.localeCompare(b.label))
  } else {
    const groups = {}
    milestones.forEach(m => {
      const key = m.board_id
      if (!groups[key]) {
        groups[key] = {
          key,
          label: m.board_name,
          client: m.client_name,
          milestones: [],
          totals: {},
          paidTotals: {}
        }
      }
      addToGroup(groups[key], m)
    })
    return Object.values(groups).sort((a, b) => a.label.localeCompare(b.label))
  }
})

// Total amounts filtered by currency selection
const filteredTotals = computed(() => {
  if (!financials.value?.totals_by_currency) return {}
  
  if (selectedCurrency.value === 'all') {
    return financials.value.totals_by_currency
  }
  
  const total = financials.value.totals_by_currency[selectedCurrency.value] || 0
  return total > 0 ? { [selectedCurrency.value]: total } : {}
})

const paidTotals = computed(() => {
  const totals = {}
  filteredMilestones.value.filter(m => m.payment_status === 'paid').forEach(m => {
    totals[m.currency] = (totals[m.currency] || 0) + m.expected_amount
  })
  return totals
})

const unpaidTotals = computed(() => {
  const totals = {}
  filteredMilestones.value.filter(m => m.payment_status !== 'paid').forEach(m => {
    totals[m.currency] = (totals[m.currency] || 0) + m.expected_amount
  })
  return totals
})

const paidMilestoneCount = computed(() => filteredMilestones.value.filter(m => m.payment_status === 'paid').length)
const unpaidMilestoneCount = computed(() => filteredMilestones.value.filter(m => m.payment_status !== 'paid').length)

async function toggleMilestonePaymentStatus(milestone) {
  const newStatus = milestone.payment_status === 'paid' ? 'unpaid' : 'paid'
  try {
    const response = await api.put(`/boards/lists/${milestone.id}`, { payment_status: newStatus })
    if (response.data.success) {
      milestone.payment_status = newStatus
      toast.success(newStatus === 'paid' ? 'Marked as paid' : 'Marked as unpaid')
    }
  } catch (e) {
    console.error('Failed to toggle payment status:', e)
    toast.error('Failed to update payment status')
  }
}

// Timeline visualization data
const timelineMonths = computed(() => {
  if (!financials.value?.by_month) return []
  
  return financials.value.by_month.map(month => {
    let filteredMilestones = month.milestones
    if (selectedCurrency.value !== 'all') {
      filteredMilestones = filteredMilestones.filter(m => m.currency === selectedCurrency.value)
    }
    
    const totals = {}
    filteredMilestones.forEach(m => {
      totals[m.currency] = (totals[m.currency] || 0) + m.expected_amount
    })
    
    return {
      ...month,
      milestones: filteredMilestones,
      totals
    }
  }).filter(month => month.milestones.length > 0)
})

// Chart data - Monthly Bar Chart
const barChartOptions = computed(() => ({
  chart: {
    type: 'bar',
    height: 350,
    stacked: true,
    toolbar: { show: false },
    background: 'transparent',
  },
  plotOptions: {
    bar: {
      horizontal: false,
      columnWidth: '60%',
      borderRadius: 4,
    },
  },
  dataLabels: { enabled: false },
  stroke: { show: true, width: 2, colors: ['transparent'] },
  xaxis: {
    categories: getMonthCategories(),
    labels: {
      style: {
        colors: theme.isDark ? '#9ca3af' : '#6b7280',
      }
    }
  },
  yaxis: {
    labels: {
      formatter: (val) => formatCompact(val),
      style: {
        colors: theme.isDark ? '#9ca3af' : '#6b7280',
      }
    }
  },
  fill: { opacity: 1 },
  tooltip: {
    theme: theme.isDark ? 'dark' : 'light',
    y: {
      formatter: (val, { seriesIndex }) => {
        const currencies = ['HUF', 'EUR', 'USD', 'RON'];
        const curr = selectedCurrency.value !== 'all' ? selectedCurrency.value : currencies[seriesIndex] || 'HUF';
        return formatCurrency(val, curr);
      }
    }
  },
  legend: {
    position: 'top',
    horizontalAlign: 'right',
    labels: {
      colors: theme.isDark ? '#d1d5db' : '#374151',
    }
  },
  colors: ['#10b981', '#3b82f6', '#f59e0b', '#ef4444'],
  grid: {
    borderColor: theme.isDark ? '#374151' : '#e5e7eb',
  }
}))

const barChartSeries = computed(() => {
  if (!financials.value?.by_month) return []
  
  const months = financials.value.by_month
  const currenciesToShow = selectedCurrency.value !== 'all' 
    ? [selectedCurrency.value] 
    : Object.keys(financials.value.totals_by_currency || {})
  
  return currenciesToShow.map(currency => ({
    name: currency,
    data: months.map(month => {
      const milestone = month.milestones?.find(m => m.currency === currency)
      const total = month.milestones
        ?.filter(m => m.currency === currency)
        .reduce((sum, m) => sum + (m.expected_amount || 0), 0) || 0
      return total
    })
  }))
})

// Chart data - Cumulative Line Chart
const lineChartOptions = computed(() => ({
  chart: {
    type: 'area',
    height: 300,
    toolbar: { show: false },
    background: 'transparent',
  },
  stroke: {
    curve: 'smooth',
    width: 3,
  },
  fill: {
    type: 'gradient',
    gradient: {
      shadeIntensity: 1,
      opacityFrom: 0.4,
      opacityTo: 0.1,
      stops: [0, 90, 100]
    }
  },
  dataLabels: { enabled: false },
  xaxis: {
    categories: getMonthCategories(),
    labels: {
      style: {
        colors: theme.isDark ? '#9ca3af' : '#6b7280',
      }
    }
  },
  yaxis: {
    labels: {
      formatter: (val) => formatCompact(val),
      style: {
        colors: theme.isDark ? '#9ca3af' : '#6b7280',
      }
    }
  },
  tooltip: {
    theme: theme.isDark ? 'dark' : 'light',
    y: {
      formatter: (val) => {
        const curr = selectedCurrency.value !== 'all' ? selectedCurrency.value : 'HUF';
        return formatCurrency(val, curr);
      }
    }
  },
  legend: {
    position: 'top',
    horizontalAlign: 'right',
    labels: {
      colors: theme.isDark ? '#d1d5db' : '#374151',
    }
  },
  colors: ['#10b981', '#3b82f6'],
  grid: {
    borderColor: theme.isDark ? '#374151' : '#e5e7eb',
  }
}))

const lineChartSeries = computed(() => {
  if (!financials.value?.by_month) return []
  
  const months = financials.value.by_month
  const targetCurrency = selectedCurrency.value !== 'all' ? selectedCurrency.value : 'HUF'
  
  // Cumulative total
  let cumulative = 0
  const cumulativeData = months.map(month => {
    const monthTotal = month.milestones
      ?.filter(m => selectedCurrency.value === 'all' || m.currency === selectedCurrency.value)
      .reduce((sum, m) => sum + (m.expected_amount || 0), 0) || 0
    cumulative += monthTotal
    return cumulative
  })
  
  return [
    {
      name: 'Cumulative Revenue',
      data: cumulativeData
    }
  ]
})

// Year summary
const yearSummary = computed(() => {
  if (!financials.value?.milestones) return null
  
  const currentYear = new Date().getFullYear()
  const lastYear = currentYear - 1
  
  const byYear = {}
  financials.value.milestones.forEach(m => {
    const year = m.invoice_date ? new Date(m.invoice_date).getFullYear() : null
    if (year) {
      if (!byYear[year]) byYear[year] = {}
      if (!byYear[year][m.currency]) byYear[year][m.currency] = 0
      byYear[year][m.currency] += m.expected_amount || 0
    }
  })
  
  return {
    currentYear,
    currentYearTotals: byYear[currentYear] || {},
    lastYear,
    lastYearTotals: byYear[lastYear] || {},
    allYears: Object.keys(byYear).sort().reverse()
  }
})

// =====================================================================
// Optional addon data — shows when Board Pro / CRM Pro are enabled
// Backend returns empty arrays when tables don't exist, so this is safe.
// =====================================================================
const cardEstimates = computed(() => financials.value?.card_estimates || [])
const cardEstimateTotals = computed(() => {
  const totals = financials.value?.card_estimate_totals || {}
  if (selectedCurrency.value === 'all') return totals
  const val = totals[selectedCurrency.value]
  return val ? { [selectedCurrency.value]: val } : {}
})
const invoiceSummary = computed(() => {
  const summary = financials.value?.invoice_summary || {}
  if (selectedCurrency.value === 'all') return summary
  const val = summary[selectedCurrency.value]
  return val ? { [selectedCurrency.value]: val } : {}
})

const hasCardEstimates = computed(() => Object.keys(cardEstimateTotals.value).length > 0)
const hasInvoiceData = computed(() => Object.keys(invoiceSummary.value).length > 0)

// Helper functions for charts
function getMonthCategories() {
  if (!financials.value?.by_month) return []
  return financials.value.by_month.map(m => {
    const [year, month] = m.month.split('-')
    return new Date(year, parseInt(month) - 1).toLocaleDateString(undefined, { month: 'short', year: '2-digit' })
  })
}

function formatCompact(val) {
  if (val >= 1000000) return `${(val / 1000000).toFixed(1)}M`
  if (val >= 1000) return `${Math.round(val / 1000)}K`
  return val.toString()
}

// Format month string
function formatMonth(monthStr) {
  if (!monthStr || monthStr === 'unscheduled') return 'Unscheduled'
  const [year, month] = monthStr.split('-')
  const date = new Date(year, parseInt(month) - 1)
  return date.toLocaleDateString(undefined, { year: 'numeric', month: 'long' })
}

// Format currency with proper locale (HUF uses space as thousand separator)
function formatCurrency(amount, currency = 'HUF') {
  // Use commas as thousand separator for all currencies
  if (currency === 'HUF') {
    const formatted = new Intl.NumberFormat('en-US', {
      minimumFractionDigits: 0,
      maximumFractionDigits: 0
    }).format(amount)
    return `${formatted} Ft`
  }
  return new Intl.NumberFormat('en-US', {
    style: 'currency',
    currency: currency,
    minimumFractionDigits: 2,
    maximumFractionDigits: 2
  }).format(amount)
}

// Get progress bar color
function getProgressColor(percent) {
  if (percent >= 100) return 'bg-green-500'
  if (percent >= 75) return 'bg-blue-500'
  if (percent >= 50) return 'bg-amber-500'
  if (percent >= 25) return 'bg-orange-500'
  return 'bg-surface-300 dark:bg-surface-600'
}

// Navigate to board
function goToBoard(boardId) {
  router.push(`/boards/${boardId}`)
}

// Navigate to client
function goToClient(clientId) {
  if (clientId) {
    router.push(`/clients/${clientId}`)
  }
}

// Apply date filter
function applyDateFilter() {
  fetchFinancials()
}

// Clear date filter
function clearDateFilter() {
  dateFrom.value = ''
  dateTo.value = ''
  fetchFinancials()
}

// Quick date filters
function setQuickFilter(filter) {
  const today = new Date()
  const year = today.getFullYear()
  const month = today.getMonth()
  
  switch (filter) {
    case 'thisMonth':
      dateFrom.value = new Date(year, month, 1).toISOString().split('T')[0]
      dateTo.value = new Date(year, month + 1, 0).toISOString().split('T')[0]
      break
    case 'nextMonth':
      dateFrom.value = new Date(year, month + 1, 1).toISOString().split('T')[0]
      dateTo.value = new Date(year, month + 2, 0).toISOString().split('T')[0]
      break
    case 'thisQuarter':
      const quarterStart = Math.floor(month / 3) * 3
      dateFrom.value = new Date(year, quarterStart, 1).toISOString().split('T')[0]
      dateTo.value = new Date(year, quarterStart + 3, 0).toISOString().split('T')[0]
      break
    case 'thisYear':
      dateFrom.value = new Date(year, 0, 1).toISOString().split('T')[0]
      dateTo.value = new Date(year, 11, 31).toISOString().split('T')[0]
      break
    case 'all':
      dateFrom.value = ''
      dateTo.value = ''
      break
  }
  fetchFinancials()
}

// Initialize
onMounted(() => {
  isMounted.value = true
  checkMobile()
  window.addEventListener('resize', checkMobile)
  fetchFinancials()
})

onBeforeUnmount(() => {
  isMounted.value = false
})

onUnmounted(() => {
  window.removeEventListener('resize', checkMobile)
})
</script>

<template>
  <div class="h-[100dvh] flex flex-col bg-surface-100 dark:bg-surface-900 ambient-tint" :class="isMobile ? 'overflow-y-auto pb-20' : 'overflow-hidden'">
    <!-- Header -->
    <AppHeader
      current-view="financials"
      icon="account_balance"
      title="Financials"
    >
      <template #title-badge>
        <HowItWorksButton variant="features" :active="showTierGuide" @click="showTierGuide = !showTierGuide" />
        <HowItWorksButton v-if="canShowGuide" @click="showWorkflowGuide = true" />
      </template>
    </AppHeader>
    
    <!-- Filters Bar -->
    <div class="flex-shrink-0 bg-white dark:bg-[rgb(var(--color-surface))] border-b border-surface-200 dark:border-[rgb(var(--color-border))] px-4 py-3 overflow-x-auto">
      <div class="flex items-center gap-4 min-w-max md:min-w-0 md:flex-wrap">
        <!-- Quick date filters -->
        <div class="flex items-center gap-1 p-1 bg-surface-100 dark:bg-surface-700 rounded-lg">
          <button 
            @click="setQuickFilter('all')"
            :class="[
              'px-3 py-1 text-sm font-medium rounded-md transition-colors',
              !dateFrom && !dateTo
                ? 'bg-white dark:bg-surface-600 text-surface-900 dark:text-surface-100 shadow-sm' 
                : 'text-surface-600 dark:text-surface-400'
            ]"
          >
            All
          </button>
          <button 
            @click="setQuickFilter('thisMonth')"
            class="px-3 py-1 text-sm font-medium rounded-md transition-colors text-surface-600 dark:text-surface-400 hover:bg-surface-200 dark:hover:bg-surface-600"
          >
            This Month
          </button>
          <button 
            @click="setQuickFilter('nextMonth')"
            class="px-3 py-1 text-sm font-medium rounded-md transition-colors text-surface-600 dark:text-surface-400 hover:bg-surface-200 dark:hover:bg-surface-600"
          >
            Next Month
          </button>
          <button 
            @click="setQuickFilter('thisQuarter')"
            class="px-3 py-1 text-sm font-medium rounded-md transition-colors text-surface-600 dark:text-surface-400 hover:bg-surface-200 dark:hover:bg-surface-600"
          >
            This Quarter
          </button>
          <button 
            @click="setQuickFilter('thisYear')"
            class="px-3 py-1 text-sm font-medium rounded-md transition-colors text-surface-600 dark:text-surface-400 hover:bg-surface-200 dark:hover:bg-surface-600"
          >
            This Year
          </button>
        </div>
        
        <!-- Custom date range (hidden on mobile) -->
        <div class="hidden md:flex items-center gap-2">
          <input 
            v-model="dateFrom"
            type="date"
            class="px-3 py-1.5 text-sm border border-surface-300 dark:border-surface-600 rounded-lg bg-white dark:bg-surface-900 text-surface-900 dark:text-surface-100"
            @change="applyDateFilter"
          />
          <span class="text-surface-400">to</span>
          <input 
            v-model="dateTo"
            type="date"
            class="px-3 py-1.5 text-sm border border-surface-300 dark:border-surface-600 rounded-lg bg-white dark:bg-surface-900 text-surface-900 dark:text-surface-100"
            @change="applyDateFilter"
          />
        </div>
        
        <div class="flex-1"></div>
        
        <!-- Currency filter -->
        <select 
          v-model="selectedCurrency"
          class="px-3 py-1.5 text-sm border border-surface-300 dark:border-surface-600 rounded-lg bg-white dark:bg-surface-900 text-surface-900 dark:text-surface-100"
        >
          <option value="all">All Currencies</option>
          <option v-for="curr in currencies" :key="curr" :value="curr">{{ curr }}</option>
        </select>
        
        <!-- Group by -->
        <select 
          v-model="groupBy"
          class="px-3 py-1.5 text-sm border border-surface-300 dark:border-surface-600 rounded-lg bg-white dark:bg-surface-900 text-surface-900 dark:text-surface-100"
        >
          <option value="month">Group by Month</option>
          <option value="client">Group by Client</option>
          <option value="board">Group by Project</option>
        </select>
        
        <!-- View toggle -->
        <div class="flex items-center gap-1 p-1 bg-surface-100 dark:bg-surface-700 rounded-lg">
          <button 
            @click="viewMode = 'charts'"
            :class="[
              'p-1.5 rounded-md transition-colors',
              viewMode === 'charts'
                ? 'bg-white dark:bg-surface-600 shadow-sm' 
                : 'text-surface-500 hover:text-surface-700'
            ]"
            title="Charts View"
          >
            <span class="material-symbols-rounded text-lg">bar_chart</span>
          </button>
          <button 
            @click="viewMode = 'timeline'"
            :class="[
              'p-1.5 rounded-md transition-colors',
              viewMode === 'timeline'
                ? 'bg-white dark:bg-surface-600 shadow-sm' 
                : 'text-surface-500 hover:text-surface-700'
            ]"
            title="Timeline View"
          >
            <span class="material-symbols-rounded text-lg">view_timeline</span>
          </button>
          <button 
            @click="viewMode = 'list'"
            :class="[
              'p-1.5 rounded-md transition-colors',
              viewMode === 'list'
                ? 'bg-white dark:bg-surface-600 shadow-sm' 
                : 'text-surface-500 hover:text-surface-700'
            ]"
            title="List View"
          >
            <span class="material-symbols-rounded text-lg">view_list</span>
          </button>
        </div>
      </div>
    </div>
    
    <!-- Tier Guide Panel -->
    <div class="flex-shrink-0 px-4 md:px-6 pt-4">
      <FeatureGuide v-model="showTierGuide" :tiers="financialsGuideData.tiers" :integrations="financialsGuideData.integrations" :title-key="financialsGuideData.titleKey" :footer-key="financialsGuideData.footerKey" :layer-key="financialsGuideData.layerKey" :layer-icon="financialsGuideData.layerIcon" />
    </div>

    <!-- Content -->
    <div class="flex-1 p-4 md:p-6" :class="isMobile ? 'overflow-y-auto pb-20' : 'overflow-hidden'">
      <!-- Loading -->
      <div v-if="loading" class="flex items-center justify-center py-20">
        <div class="animate-spin rounded-full h-12 w-12 border-4 border-primary-500 border-t-transparent"></div>
      </div>
      
      <template v-else>
        <!-- Financial Summary Cards -->
        <div class="mb-6" :class="[hasCardEstimates || hasInvoiceData ? 'grid grid-cols-1 md:grid-cols-3 gap-4' : 'flex flex-wrap gap-4']">
          <!-- Milestone Totals (always shown -- base kanban data) -->
          <div class="px-5 py-4 bg-white dark:bg-surface-800 rounded-xl shadow-sm border border-surface-200 dark:border-surface-700">
            <div class="flex items-center gap-2 mb-3">
              <span class="material-symbols-rounded text-lg text-amber-500">flag</span>
              <span class="text-sm font-semibold text-surface-700 dark:text-surface-300">Milestones</span>
            </div>
            <template v-if="Object.keys(filteredTotals).length > 0">
              <div v-for="(amount, currency) in filteredTotals" :key="currency" class="mb-2">
                <div class="text-2xl font-bold text-surface-900 dark:text-surface-100">{{ formatCurrency(amount, currency) }}</div>
                <div class="flex items-center gap-3 mt-1">
                  <span v-if="paidTotals[currency]" class="text-xs text-emerald-600 dark:text-emerald-400 flex items-center gap-0.5">
                    <span class="material-symbols-rounded text-xs">paid</span>
                    {{ formatCurrency(paidTotals[currency], currency) }}
                  </span>
                  <span v-if="unpaidTotals[currency]" class="text-xs text-amber-600 dark:text-amber-400 flex items-center gap-0.5">
                    <span class="material-symbols-rounded text-xs">pending</span>
                    {{ formatCurrency(unpaidTotals[currency], currency) }}
                  </span>
                </div>
              </div>
              <div class="flex items-center gap-3 text-xs text-surface-400 mt-1">
                <span>{{ filteredMilestones.length }} milestones</span>
                <span v-if="paidMilestoneCount > 0" class="text-emerald-500">{{ paidMilestoneCount }} paid</span>
                <span v-if="unpaidMilestoneCount > 0" class="text-amber-500">{{ unpaidMilestoneCount }} unpaid</span>
              </div>
            </template>
            <p v-else class="text-sm text-surface-400">No milestones set</p>
          </div>

          <!-- Card-Level Revenue (only when Board Pro data exists) -->
          <div v-if="hasCardEstimates" class="px-5 py-4 bg-white dark:bg-surface-800 rounded-xl shadow-sm border border-surface-200 dark:border-surface-700">
            <div class="flex items-center gap-2 mb-3">
              <span class="material-symbols-rounded text-lg text-primary-500">credit_score</span>
              <span class="text-sm font-semibold text-surface-700 dark:text-surface-300">Card Revenue</span>
            </div>
            <div v-for="(amount, currency) in cardEstimateTotals" :key="currency" class="mb-1">
              <div class="text-2xl font-bold text-primary-600 dark:text-primary-400">{{ formatCurrency(amount, currency) }}</div>
            </div>
            <p class="text-xs text-surface-400 mt-1">Per-task revenue from {{ cardEstimates.length }} projects</p>
          </div>

          <!-- CRM Invoices (only when CRM Pro invoice data exists) -->
          <div v-if="hasInvoiceData" class="px-5 py-4 bg-white dark:bg-surface-800 rounded-xl shadow-sm border border-surface-200 dark:border-surface-700">
            <div class="flex items-center gap-2 mb-3">
              <span class="material-symbols-rounded text-lg text-green-500">receipt_long</span>
              <span class="text-sm font-semibold text-surface-700 dark:text-surface-300">Invoiced</span>
            </div>
            <div v-for="(inv, currency) in invoiceSummary" :key="currency" class="mb-2 last:mb-0">
              <div class="text-2xl font-bold text-green-600 dark:text-green-400">{{ formatCurrency(inv.total_invoiced, currency) }}</div>
              <div class="flex items-center gap-3 text-xs mt-1">
                <span class="text-green-500">{{ inv.paid_count }} paid</span>
                <span class="text-amber-500">{{ inv.sent_count }} sent</span>
                <span v-if="inv.overdue_count > 0" class="text-red-500">{{ inv.overdue_count }} overdue</span>
                <span class="text-surface-400">{{ inv.draft_count }} draft</span>
              </div>
              <div v-if="inv.overdue_total > 0" class="text-xs text-red-500 mt-1">
                {{ formatCurrency(inv.overdue_total, currency) }} overdue
              </div>
            </div>
          </div>
        </div>

        <!-- Card Estimates Breakdown (collapsible, only when data exists) -->
        <div v-if="hasCardEstimates" class="mb-6 bg-white dark:bg-surface-800 rounded-xl shadow-sm border border-surface-200 dark:border-surface-700 overflow-hidden">
          <button
            class="w-full px-5 py-3 flex items-center justify-between hover:bg-surface-50 dark:hover:bg-surface-700/30 transition-colors"
            @click="showEstimateBreakdown = !showEstimateBreakdown"
          >
            <span class="text-sm font-semibold text-surface-700 dark:text-surface-300 flex items-center gap-2">
              <span class="material-symbols-rounded text-base text-primary-500">credit_score</span>
              Card Revenue Estimates by Board
            </span>
            <span class="material-symbols-rounded text-sm text-surface-400 transition-transform" :class="{ 'rotate-180': showEstimateBreakdown }">expand_more</span>
          </button>
          <div v-if="showEstimateBreakdown" class="divide-y divide-surface-100 dark:divide-surface-700">
            <div
              v-for="est in cardEstimates"
              :key="est.board_id"
              class="px-5 py-3 flex items-center justify-between hover:bg-surface-50 dark:hover:bg-surface-700/30 cursor-pointer"
              @click="goToBoard(est.board_id)"
            >
              <div>
                <p class="text-sm font-medium text-surface-800 dark:text-surface-200">{{ est.board_name }}</p>
                <p class="text-xs text-surface-400">{{ est.client_name }}</p>
              </div>
              <div class="text-right">
                <div v-for="(data, cur) in est.currencies" :key="cur" class="text-sm">
                  <span class="font-semibold text-primary-600 dark:text-primary-400">{{ formatCurrency(data.revenue, cur) }}</span>
                  <span v-if="data.cost > 0" class="text-xs text-surface-400 ml-2">cost {{ formatCurrency(data.cost, cur) }}</span>
                  <span v-if="data.paid_revenue > 0" class="text-xs text-green-500 ml-2">{{ formatCurrency(data.paid_revenue, cur) }} paid</span>
                </div>
              </div>
            </div>
          </div>
        </div>
        
        <!-- Charts View -->
        <div v-if="viewMode === 'charts'" class="space-y-6">
          <!-- Year Summary Cards -->
          <div v-if="yearSummary" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
            <div 
              v-for="year in yearSummary.allYears.slice(0, 4)" 
              :key="year"
              class="bg-white dark:bg-surface-800 rounded-xl p-4 border border-surface-200 dark:border-surface-700"
            >
              <div class="text-sm text-surface-500 dark:text-surface-400 mb-1">{{ year }}</div>
              <div v-if="selectedCurrency === 'all'">
                <div 
                  v-for="(amount, currency) in (year == yearSummary.currentYear ? yearSummary.currentYearTotals : (year == yearSummary.lastYear ? yearSummary.lastYearTotals : {}))" 
                  :key="currency"
                  class="text-lg font-bold text-surface-900 dark:text-surface-100"
                >
                  {{ formatCurrency(amount, currency) }}
                </div>
              </div>
              <div v-else class="text-xl font-bold text-surface-900 dark:text-surface-100">
                {{ formatCurrency((year == yearSummary.currentYear ? yearSummary.currentYearTotals : yearSummary.lastYearTotals)[selectedCurrency] || 0, selectedCurrency) }}
              </div>
            </div>
          </div>
          
          <!-- Monthly Revenue Bar Chart -->
          <div class="bg-white dark:bg-surface-800 rounded-xl p-6 border border-surface-200 dark:border-surface-700">
            <h3 class="text-lg font-semibold text-surface-900 dark:text-surface-100 mb-4 flex items-center gap-2">
              <span class="material-symbols-rounded text-primary-500">bar_chart</span>
              Monthly Revenue
            </h3>
            <VueApexCharts 
              v-if="isMounted && !loading && barChartSeries.length > 0"
              :key="'bar-' + selectedCurrency + '-' + (financials?.by_month?.length || 0)"
              type="bar" 
              height="350" 
              :options="barChartOptions" 
              :series="barChartSeries"
            />
            <div v-else-if="!loading" class="text-center py-12 text-surface-500">
              No data available for chart
            </div>
            <div v-else class="flex justify-center py-12">
              <span class="spinner text-primary-500 w-8 h-8"></span>
            </div>
          </div>
          
          <!-- Cumulative Cash Flow Line Chart -->
          <div class="bg-white dark:bg-surface-800 rounded-xl p-6 border border-surface-200 dark:border-surface-700">
            <h3 class="text-lg font-semibold text-surface-900 dark:text-surface-100 mb-4 flex items-center gap-2">
              <span class="material-symbols-rounded text-primary-500">show_chart</span>
              Cumulative Cash Flow
            </h3>
            <VueApexCharts 
              v-if="isMounted && !loading && lineChartSeries.length > 0 && lineChartSeries[0]?.data?.length > 0"
              :key="'line-' + selectedCurrency + '-' + (financials?.by_month?.length || 0)"
              type="area" 
              height="300" 
              :options="lineChartOptions" 
              :series="lineChartSeries"
            />
            <div v-else-if="!loading" class="text-center py-12 text-surface-500">
              No data available for chart
            </div>
            <div v-else class="flex justify-center py-12">
              <span class="spinner text-primary-500 w-8 h-8"></span>
            </div>
          </div>
        </div>
        
        <!-- Timeline View -->
        <div v-if="viewMode === 'timeline'" class="space-y-6">
          <div 
            v-for="group in groupedData" 
            :key="group.key"
            class="bg-white dark:bg-surface-800 rounded-xl shadow-sm border border-surface-200 dark:border-surface-700 overflow-hidden"
          >
            <!-- Group header -->
            <div class="px-4 py-3 bg-surface-50 dark:bg-surface-700/50 border-b border-surface-200 dark:border-surface-700 flex items-center justify-between">
              <div>
                <h3 class="font-semibold text-surface-900 dark:text-surface-100">{{ group.label }}</h3>
                <div v-if="group.client" class="text-sm text-surface-500">{{ group.client }}</div>
              </div>
              <div class="flex items-center gap-2 flex-wrap justify-end">
                <span 
                  v-for="(amount, currency) in group.totals" 
                  :key="currency"
                  class="px-3 py-1 bg-green-100 dark:bg-green-900/30 text-green-700 dark:text-green-400 rounded-full text-sm font-medium"
                >
                  {{ formatCurrency(amount, currency) }}
                </span>
                <template v-for="(amount, currency) in group.paidTotals" :key="'paid-' + currency">
                  <span class="px-2 py-0.5 bg-emerald-100 dark:bg-emerald-900/30 text-emerald-700 dark:text-emerald-400 rounded-full text-xs font-medium flex items-center gap-0.5">
                    <span class="material-symbols-rounded text-xs">paid</span>
                    {{ formatCurrency(amount, currency) }}
                  </span>
                </template>
              </div>
            </div>
            
            <!-- Milestones -->
            <div class="divide-y divide-surface-100 dark:divide-surface-700">
              <div 
                v-for="milestone in group.milestones" 
                :key="milestone.id"
                class="p-4 hover:bg-surface-50 dark:hover:bg-surface-700/30 transition-colors cursor-pointer"
                @click="goToBoard(milestone.board_id)"
              >
                <div class="flex items-start justify-between gap-4">
                  <div class="flex-1 min-w-0">
                    <div class="flex items-center gap-2 mb-1">
                      <span 
                        v-if="milestone.is_milestone" 
                        class="material-symbols-rounded text-sm text-amber-500"
                      >
                        flag
                      </span>
                      <span class="font-medium text-surface-900 dark:text-surface-100">
                        {{ milestone.name }}
                      </span>
                    </div>
                    
                    <div class="flex items-center gap-3 text-sm text-surface-500 dark:text-surface-400">
                      <span 
                        class="hover:text-primary-500 cursor-pointer"
                        @click.stop="goToClient(milestone.client_id)"
                      >
                        {{ milestone.client_name }}
                      </span>
                      <span class="text-surface-300">|</span>
                      <span>{{ milestone.board_name }}</span>
                    </div>
                    
                    <!-- Progress bar -->
                    <div class="mt-2">
                      <div class="flex items-center justify-between text-xs text-surface-500 mb-1">
                        <span>Progress</span>
                        <span>
                          {{ milestone.total_todos > 0 
                            ? `${milestone.completed_todos}/${milestone.total_todos} todos` 
                            : `${milestone.completed_cards}/${milestone.total_cards} cards` 
                          }} 
                          ({{ milestone.progress_percent }}%)
                        </span>
                      </div>
                      <div class="h-2 bg-surface-200 dark:bg-surface-700 rounded-full overflow-hidden">
                        <div 
                          :class="['h-full rounded-full transition-all', getProgressColor(milestone.progress_percent)]"
                          :style="{ width: `${milestone.progress_percent}%` }"
                        ></div>
                      </div>
                    </div>
                  </div>
                  
                  <div class="text-right shrink-0">
                    <div class="text-lg font-semibold text-green-600 dark:text-green-400">
                      {{ formatCurrency(milestone.expected_amount, milestone.currency) }}
                    </div>
                    <div v-if="milestone.invoice_date" class="text-xs text-surface-500 mt-1 flex items-center justify-end gap-1">
                      <span class="material-symbols-rounded text-sm">receipt</span>
                      {{ milestone.invoice_date }}
                    </div>
                    <div v-if="milestone.payment_date" class="text-xs text-surface-400 flex items-center justify-end gap-1">
                      <span class="material-symbols-rounded text-sm">payments</span>
                      {{ milestone.payment_date }}
                    </div>
                    
                    <!-- Ready to invoice badge -->
                    <div 
                      v-if="milestone.progress_percent >= 100 && milestone.payment_status !== 'paid'"
                      class="mt-2 inline-flex items-center gap-1 px-2 py-0.5 bg-green-100 dark:bg-green-900/30 text-green-700 dark:text-green-400 rounded-full text-xs font-medium"
                    >
                      <span class="material-symbols-rounded text-sm">check_circle</span>
                      Ready to invoice
                    </div>
                    
                    <!-- Payment status badge (clickable toggle) -->
                    <button
                      @click.stop="toggleMilestonePaymentStatus(milestone)"
                      :class="[
                        'mt-1 inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium cursor-pointer transition-colors',
                        milestone.payment_status === 'paid'
                          ? 'bg-emerald-100 dark:bg-emerald-900/30 text-emerald-700 dark:text-emerald-400 hover:bg-emerald-200 dark:hover:bg-emerald-900/50'
                          : 'bg-amber-100 dark:bg-amber-900/30 text-amber-700 dark:text-amber-400 hover:bg-amber-200 dark:hover:bg-amber-900/50'
                      ]"
                      :title="milestone.payment_status === 'paid' ? 'Click to mark unpaid' : 'Click to mark paid'"
                    >
                      <span class="material-symbols-rounded text-sm">{{ milestone.payment_status === 'paid' ? 'paid' : 'pending' }}</span>
                      {{ milestone.payment_status === 'paid' ? 'Paid' : 'Unpaid' }}
                    </button>
                  </div>
                </div>
              </div>
            </div>
          </div>
          
          <!-- Empty state -->
          <div v-if="groupedData.length === 0" class="text-center py-20">
            <span class="material-symbols-rounded text-6xl text-surface-300 dark:text-surface-600">account_balance_wallet</span>
            <p class="text-surface-500 dark:text-surface-400 mt-4 text-lg">No financial milestones found</p>
            <p class="text-surface-400 text-sm mt-1">Add expected amounts to your board lists to start tracking</p>
          </div>
        </div>
        
        <!-- List View -->
        <div v-else class="bg-white dark:bg-surface-800 rounded-xl shadow-sm border border-surface-200 dark:border-surface-700 overflow-hidden">
          <div class="overflow-x-auto">
          <table class="w-full">
            <thead class="bg-surface-50 dark:bg-surface-700/50">
              <tr>
                <th class="px-3 md:px-4 py-3 text-left text-xs font-semibold text-surface-500 uppercase">Milestone</th>
                <th class="px-3 md:px-4 py-3 text-left text-xs font-semibold text-surface-500 uppercase hide-on-mobile">Client</th>
                <th class="px-4 py-3 text-left text-xs font-semibold text-surface-500 uppercase hide-on-mobile">Project</th>
                <th class="px-3 md:px-4 py-3 text-left text-xs font-semibold text-surface-500 uppercase">Progress</th>
                <th class="px-4 py-3 text-left text-xs font-semibold text-surface-500 uppercase hide-on-mobile">Invoice Date</th>
                <th class="px-3 md:px-4 py-3 text-center text-xs font-semibold text-surface-500 uppercase">Status</th>
                <th class="px-3 md:px-4 py-3 text-right text-xs font-semibold text-surface-500 uppercase">Amount</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-surface-100 dark:divide-surface-700">
              <tr 
                v-for="milestone in filteredMilestones" 
                :key="milestone.id"
                class="hover:bg-surface-50 dark:hover:bg-surface-700/30 cursor-pointer"
                @click="goToBoard(milestone.board_id)"
              >
                <td class="px-3 md:px-4 py-3">
                  <div class="flex items-center gap-2">
                    <span v-if="milestone.is_milestone" class="material-symbols-rounded text-sm text-amber-500">flag</span>
                    <span class="font-medium text-surface-900 dark:text-surface-100">{{ milestone.name }}</span>
                  </div>
                </td>
                <td class="px-4 py-3 hide-on-mobile">
                  <span 
                    class="text-surface-600 dark:text-surface-400 hover:text-primary-500 cursor-pointer"
                    @click.stop="goToClient(milestone.client_id)"
                  >
                    {{ milestone.client_name }}
                  </span>
                </td>
                <td class="px-4 py-3 text-surface-600 dark:text-surface-400 hide-on-mobile">
                  {{ milestone.board_name }}
                </td>
                <td class="px-3 md:px-4 py-3">
                  <div class="flex items-center gap-2">
                    <div class="w-16 md:w-20 h-2 bg-surface-200 dark:bg-surface-700 rounded-full overflow-hidden">
                      <div 
                        :class="['h-full rounded-full', getProgressColor(milestone.progress_percent)]"
                        :style="{ width: `${milestone.progress_percent}%` }"
                      ></div>
                    </div>
                    <span class="text-xs text-surface-500">{{ milestone.progress_percent }}%</span>
                  </div>
                </td>
                <td class="px-4 py-3 text-surface-600 dark:text-surface-400 hide-on-mobile">
                  {{ milestone.invoice_date || '-' }}
                </td>
                <td class="px-3 md:px-4 py-3 text-center">
                  <button
                    @click.stop="toggleMilestonePaymentStatus(milestone)"
                    :class="[
                      'inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium cursor-pointer transition-colors',
                      milestone.payment_status === 'paid'
                        ? 'bg-emerald-100 dark:bg-emerald-900/30 text-emerald-700 dark:text-emerald-400 hover:bg-emerald-200 dark:hover:bg-emerald-900/50'
                        : 'bg-amber-100 dark:bg-amber-900/30 text-amber-700 dark:text-amber-400 hover:bg-amber-200 dark:hover:bg-amber-900/50'
                    ]"
                    :title="milestone.payment_status === 'paid' ? 'Click to mark unpaid' : 'Click to mark paid'"
                  >
                    <span class="material-symbols-rounded text-sm">{{ milestone.payment_status === 'paid' ? 'paid' : 'pending' }}</span>
                    {{ milestone.payment_status === 'paid' ? 'Paid' : 'Unpaid' }}
                  </button>
                </td>
                <td class="px-3 md:px-4 py-3 text-right font-semibold text-green-600 dark:text-green-400">
                  {{ formatCurrency(milestone.expected_amount, milestone.currency) }}
                </td>
              </tr>
            </tbody>
          </table>
          </div>
          
          <!-- Empty state -->
          <div v-if="filteredMilestones.length === 0" class="text-center py-20">
            <span class="material-symbols-rounded text-6xl text-surface-300 dark:text-surface-600">account_balance_wallet</span>
            <p class="text-surface-500 dark:text-surface-400 mt-4">No financial milestones found</p>
          </div>
        </div>
      </template>
    </div>
    
    <!-- Mobile Bottom Navigation -->
    <MobileBottomNav v-if="isMobile" />

    <!-- Workflow guide modal -->
    <WorkflowGuide v-if="showWorkflowGuide" @close="showWorkflowGuide = false" />
  </div>
</template>


