<script setup>
import { ref, computed, onMounted, watch } from 'vue'

const props = defineProps({
  byClient: {
    type: Array,
    default: () => []
  },
  byActivity: {
    type: Array,
    default: () => []
  },
  dailyBreakdown: {
    type: Array,
    default: () => []
  },
  period: {
    type: String,
    default: 'week'
  }
})

const chartReady = ref(false)
const ApexCharts = ref(null)

// Load ApexCharts dynamically
onMounted(async () => {
  try {
    const module = await import('vue3-apexcharts')
    ApexCharts.value = module.default
    chartReady.value = true
  } catch (e) {
    console.error('Failed to load ApexCharts:', e)
  }
})

// Format time for display
function formatTime(seconds) {
  if (!seconds) return '0m'
  const hours = Math.floor(seconds / 3600)
  const mins = Math.floor((seconds % 3600) / 60)
  if (hours > 0) {
    return mins > 0 ? `${hours}h ${mins}m` : `${hours}h`
  }
  return `${mins}m`
}

// Bar chart options for clients
const clientChartOptions = computed(() => ({
  chart: {
    type: 'bar',
    height: 280,
    toolbar: { show: false },
    fontFamily: 'inherit'
  },
  plotOptions: {
    bar: {
      horizontal: true,
      borderRadius: 6,
      barHeight: '70%'
    }
  },
  colors: ['#6366F1'],
  dataLabels: {
    enabled: true,
    textAnchor: 'start',
    formatter: (val) => formatTime(val),
    offsetX: 5,
    style: {
      fontSize: '11px',
      colors: ['#6366F1']
    }
  },
  xaxis: {
    categories: props.byClient.slice(0, 8).map(c => c.display_name || c.domain || `Client #${c.client_id}`),
    labels: {
      formatter: (val) => formatTime(val),
      style: { colors: '#9CA3AF' }
    }
  },
  yaxis: {
    labels: {
      style: { colors: '#9CA3AF' }
    }
  },
  grid: {
    borderColor: '#E5E7EB',
    strokeDashArray: 4
  },
  tooltip: {
    y: {
      formatter: (val) => formatTime(val)
    }
  }
}))

const clientChartSeries = computed(() => [{
  name: 'Time',
  data: props.byClient.slice(0, 8).map(c => c.total_seconds ?? 0)
}])

// Donut chart options for activities
const activityChartOptions = computed(() => ({
  chart: {
    type: 'donut',
    height: 280,
    fontFamily: 'inherit'
  },
  labels: props.byActivity.slice(0, 6).map(a => a.label),
  colors: props.byActivity.slice(0, 6).map(a => a.color),
  legend: {
    position: 'bottom',
    fontSize: '12px',
    labels: { colors: '#6B7280' }
  },
  dataLabels: {
    enabled: true,
    formatter: (val) => `${Math.round(val)}%`
  },
  plotOptions: {
    pie: {
      donut: {
        size: '65%',
        labels: {
          show: true,
          name: {
            show: true,
            fontSize: '14px',
            color: '#374151'
          },
          value: {
            show: true,
            fontSize: '20px',
            fontWeight: 700,
            formatter: (val) => formatTime(parseInt(val))
          },
          total: {
            show: true,
            label: 'Total',
            color: '#6B7280',
            formatter: (w) => {
              const total = w.globals.seriesTotals.reduce((a, b) => a + b, 0)
              return formatTime(total)
            }
          }
        }
      }
    }
  },
  tooltip: {
    y: {
      formatter: (val) => formatTime(val)
    }
  }
}))

const activityChartSeries = computed(() => props.byActivity.slice(0, 6).map(a => a.seconds))

// Line/Area chart for daily breakdown
const dailyChartOptions = computed(() => ({
  chart: {
    type: 'area',
    height: 280,
    toolbar: { show: false },
    fontFamily: 'inherit',
    zoom: { enabled: false }
  },
  stroke: {
    curve: 'smooth',
    width: 3
  },
  colors: ['#6366F1'],
  fill: {
    type: 'gradient',
    gradient: {
      shadeIntensity: 1,
      opacityFrom: 0.4,
      opacityTo: 0.1
    }
  },
  dataLabels: { enabled: false },
  xaxis: {
    categories: props.dailyBreakdown.map(d => formatDateLabel(d.date)),
    labels: {
      style: { colors: '#9CA3AF' }
    }
  },
  yaxis: {
    labels: {
      formatter: (val) => formatTime(val),
      style: { colors: '#9CA3AF' }
    }
  },
  grid: {
    borderColor: '#E5E7EB',
    strokeDashArray: 4
  },
  tooltip: {
    y: {
      formatter: (val) => formatTime(val)
    }
  }
}))

const dailyChartSeries = computed(() => [{
  name: 'Time',
  data: props.dailyBreakdown.map(d => d.total_seconds ?? 0)
}])

// Format date label based on period
function formatDateLabel(dateStr) {
  const date = new Date(dateStr)
  if (props.period === 'today') {
    return date.toLocaleTimeString('en-US', { hour: '2-digit' })
  }
  if (props.period === 'week') {
    return date.toLocaleDateString('en-US', { weekday: 'short' })
  }
  if (props.period === 'month') {
    return date.toLocaleDateString('en-US', { day: 'numeric' })
  }
  return date.toLocaleDateString('en-US', { month: 'short' })
}

// Check if we have enough data
const hasClientData = computed(() => props.byClient.length > 0)
const hasActivityData = computed(() => props.byActivity.length > 0)
const hasDailyData = computed(() => props.dailyBreakdown.length > 0)
</script>

<template>
  <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
    <!-- Time by Client Chart -->
    <div class="bg-white dark:bg-surface-800 rounded-2xl border border-surface-200 dark:border-surface-700 overflow-hidden">
      <div class="p-4 border-b border-surface-200 dark:border-surface-700">
        <h3 class="font-semibold text-surface-900 dark:text-surface-100 flex items-center gap-2">
          <span class="material-symbols-rounded text-primary-500">bar_chart</span>
          Top Clients
        </h3>
      </div>
      <div class="p-4">
        <div v-if="!hasClientData" class="flex items-center justify-center h-64">
          <div class="text-center">
            <span class="material-symbols-rounded text-4xl text-surface-300 dark:text-surface-600">assessment</span>
            <p class="mt-2 text-surface-500 text-sm">No client data yet</p>
          </div>
        </div>
        <component 
          v-else-if="chartReady && ApexCharts"
          :is="ApexCharts"
          type="bar"
          :options="clientChartOptions"
          :series="clientChartSeries"
          height="280"
        />
        <div v-else class="flex items-center justify-center h-64">
          <span class="material-symbols-rounded text-2xl text-surface-400 animate-spin">progress_activity</span>
        </div>
      </div>
    </div>
    
    <!-- Activity Distribution Chart -->
    <div class="bg-white dark:bg-surface-800 rounded-2xl border border-surface-200 dark:border-surface-700 overflow-hidden">
      <div class="p-4 border-b border-surface-200 dark:border-surface-700">
        <h3 class="font-semibold text-surface-900 dark:text-surface-100 flex items-center gap-2">
          <span class="material-symbols-rounded text-purple-500">pie_chart</span>
          Activity Mix
        </h3>
      </div>
      <div class="p-4">
        <div v-if="!hasActivityData" class="flex items-center justify-center h-64">
          <div class="text-center">
            <span class="material-symbols-rounded text-4xl text-surface-300 dark:text-surface-600">donut_large</span>
            <p class="mt-2 text-surface-500 text-sm">No activity data yet</p>
          </div>
        </div>
        <component 
          v-else-if="chartReady && ApexCharts"
          :is="ApexCharts"
          type="donut"
          :options="activityChartOptions"
          :series="activityChartSeries"
          height="280"
        />
        <div v-else class="flex items-center justify-center h-64">
          <span class="material-symbols-rounded text-2xl text-surface-400 animate-spin">progress_activity</span>
        </div>
      </div>
    </div>
    
    <!-- Daily Trend Chart -->
    <div class="bg-white dark:bg-surface-800 rounded-2xl border border-surface-200 dark:border-surface-700 overflow-hidden">
      <div class="p-4 border-b border-surface-200 dark:border-surface-700">
        <h3 class="font-semibold text-surface-900 dark:text-surface-100 flex items-center gap-2">
          <span class="material-symbols-rounded text-teal-500">trending_up</span>
          Daily Trend
        </h3>
      </div>
      <div class="p-4">
        <div v-if="!hasDailyData" class="flex items-center justify-center h-64">
          <div class="text-center">
            <span class="material-symbols-rounded text-4xl text-surface-300 dark:text-surface-600">show_chart</span>
            <p class="mt-2 text-surface-500 text-sm">No trend data yet</p>
          </div>
        </div>
        <component 
          v-else-if="chartReady && ApexCharts"
          :is="ApexCharts"
          type="area"
          :options="dailyChartOptions"
          :series="dailyChartSeries"
          height="280"
        />
        <div v-else class="flex items-center justify-center h-64">
          <span class="material-symbols-rounded text-2xl text-surface-400 animate-spin">progress_activity</span>
        </div>
      </div>
    </div>
  </div>
</template>

