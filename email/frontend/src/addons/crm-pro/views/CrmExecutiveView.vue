<script setup>
/**
 * CrmExecutiveView - Single-page executive financial summary
 * Aggregates pipeline, forecast, profitability, board financials,
 * time tracking, overdue invoices, and at-risk clients into one view.
 */
import { ref, computed, onMounted, onUnmounted } from 'vue'
import api from '@/services/api'
import { useToastStore } from '@/stores/toast'
import { useRouter } from 'vue-router'
import AppHeader from '@/components/shared/AppHeader.vue'
import ViewInfoButton from '@/components/shared/ViewInfoButton.vue'
import MobileBottomNav from '@/components/MobileBottomNav.vue'
import CrmSidebar from '../components/CrmSidebar.vue'
import CrmHealthBadge from '../components/CrmHealthBadge.vue'

const toast = useToastStore()
const router = useRouter()

const loading = ref(true)
const pipelineData = ref(null)
const forecastData = ref(null)
const profitabilityData = ref(null)
const boardFinancials = ref(null)
const timeData = ref(null)
const invoiceData = ref(null)
const healthData = ref(null)

async function loadExecutiveData() {
  loading.value = true
  try {
    const [pipeRes, fcRes, profRes, boardRes, timeRes, invRes, healthRes] = await Promise.all([
      api.get('/crm/deals/pipeline'),
      api.get('/crm/reports/forecast?months=3'),
      api.get('/crm/reports/profitability'),
      api.get('/board-pro/financials/global').catch(() => ({ data: { success: false } })),
      api.get('/time/my-stats?period=month').catch(() => ({ data: { success: false } })),
      api.get('/crm/invoices?overdue=1'),
      api.get('/crm/reports/health'),
    ])

    if (pipeRes.data?.success) pipelineData.value = pipeRes.data.data
    if (fcRes.data?.success) forecastData.value = fcRes.data.data
    if (profRes.data?.success) profitabilityData.value = profRes.data.data
    if (boardRes.data?.success) boardFinancials.value = boardRes.data.data
    if (timeRes.data?.success) timeData.value = timeRes.data.data
    if (invRes.data?.success) invoiceData.value = invRes.data.data
    if (healthRes.data?.success) healthData.value = healthRes.data.data
  } catch (e) {
    toast.error(e.response?.data?.message || 'Failed to load executive data')
  } finally {
    loading.value = false
  }
}

const isMobile = ref(false)
function checkMobile() {
  isMobile.value = window.innerWidth < 768
}

onMounted(() => {
  loadExecutiveData()
  checkMobile()
  window.addEventListener('resize', checkMobile)
})

onUnmounted(() => {
  window.removeEventListener('resize', checkMobile)
})

// ── KPI Cards ──────────────────────────────────────────────
const kpiCards = computed(() => {
  const pipeline = pipelineData.value?.summary
  const forecast = forecastData.value
  const invoice = invoiceData.value?.summary
  const profClients = profitabilityData.value?.clients || []
  const health = healthData.value?.clients || []
  const time = timeData.value

  const totalRevenue = profClients.reduce((s, c) => s + parseFloat(c.revenue || 0), 0)
  const totalProfit = profClients.reduce((s, c) => s + parseFloat(c.profit || 0), 0)
  const atRiskCount = health.filter(c => c.health_score < 50).length
  const totalHours = time ? (parseFloat(time.total_seconds || 0) / 3600) : 0

  return [
    {
      label: 'Pipeline Value',
      value: formatCurrency(pipeline?.pipeline_value ?? 0),
      icon: 'conversion_path',
      color: 'text-blue-500',
      bg: 'bg-blue-50 dark:bg-blue-500/10',
      border: 'border-blue-200 dark:border-blue-500/20',
    },
    {
      label: 'Forecasted Revenue',
      value: formatCurrency(forecast?.total_weighted ?? 0),
      sub: `Optimistic: ${formatCurrency(forecast?.total_optimistic ?? 0)}`,
      icon: 'trending_up',
      color: 'text-purple-500',
      bg: 'bg-purple-50 dark:bg-purple-500/10',
      border: 'border-purple-200 dark:border-purple-500/20',
    },
    {
      label: 'Total Revenue',
      value: formatCurrency(totalRevenue),
      icon: 'account_balance',
      color: 'text-green-500',
      bg: 'bg-green-50 dark:bg-green-500/10',
      border: 'border-green-200 dark:border-green-500/20',
    },
    {
      label: 'Total Profit',
      value: formatCurrency(totalProfit),
      sub: totalRevenue > 0 ? `${((totalProfit / totalRevenue) * 100).toFixed(0)}% margin` : '',
      icon: 'savings',
      color: 'text-emerald-500',
      bg: 'bg-emerald-50 dark:bg-emerald-500/10',
      border: 'border-emerald-200 dark:border-emerald-500/20',
    },
    {
      label: 'Time Tracked',
      value: `${totalHours.toFixed(1)}h`,
      sub: 'This month',
      icon: 'schedule',
      color: 'text-indigo-500',
      bg: 'bg-indigo-50 dark:bg-indigo-500/10',
      border: 'border-indigo-200 dark:border-indigo-500/20',
    },
    {
      label: 'Overdue Amount',
      value: formatCurrency(invoice?.overdue_amount ?? 0),
      sub: `${invoice?.overdue_count ?? 0} invoices`,
      icon: 'warning',
      color: 'text-red-500',
      bg: 'bg-red-50 dark:bg-red-500/10',
      border: 'border-red-200 dark:border-red-500/20',
    },
    {
      label: 'At-Risk Clients',
      value: atRiskCount,
      sub: `of ${health.length} total`,
      icon: 'heart_broken',
      color: 'text-orange-500',
      bg: 'bg-orange-50 dark:bg-orange-500/10',
      border: 'border-orange-200 dark:border-orange-500/20',
    },
  ]
})

// ── Revenue per Client ─────────────────────────────────────
const clientRevenue = computed(() => {
  const clients = profitabilityData.value?.clients || []
  return [...clients]
    .sort((a, b) => parseFloat(b.revenue || 0) - parseFloat(a.revenue || 0))
})

const maxClientRevenue = computed(() => {
  if (!clientRevenue.value.length) return 1
  return Math.max(...clientRevenue.value.map(c => parseFloat(c.revenue || 0)), 1)
})

// ── Profit per Board ───────────────────────────────────────
const boardProfits = computed(() => {
  if (!boardFinancials.value?.boards) return []
  return boardFinancials.value.boards.map(b => {
    const currencies = Object.values(b.currencies || {})
    const revenue = currencies.reduce((s, c) => s + parseFloat(c.revenue || 0), 0)
    const cost = currencies.reduce((s, c) => s + parseFloat(c.cost || 0), 0)
    const margin = revenue - cost
    return {
      name: b.board_name,
      id: b.board_id,
      revenue,
      cost,
      margin,
      marginPct: revenue > 0 ? ((margin / revenue) * 100).toFixed(0) : 0,
      cards: currencies.reduce((s, c) => s + parseInt(c.cards || 0), 0),
    }
  }).sort((a, b) => b.revenue - a.revenue)
})

// ── Time vs Billed ─────────────────────────────────────────
const timeVsBilled = computed(() => {
  const clients = profitabilityData.value?.clients || []
  return clients
    .filter(c => parseFloat(c.hours || 0) > 0)
    .map(c => {
      const hours = parseFloat(c.hours || 0)
      const revenue = parseFloat(c.revenue || 0)
      const effectiveRate = parseFloat(c.effective_rate || 0)
      const targetRate = parseFloat(c.target_rate || 0)
      return {
        ...c,
        hours,
        revenue,
        effectiveRate,
        targetRate,
        rateHealth: c.rate_health,
      }
    })
    .sort((a, b) => b.hours - a.hours)
})

// ── Overdue Invoices ───────────────────────────────────────
const overdueInvoices = computed(() => {
  if (!invoiceData.value?.invoices) return []
  return invoiceData.value.invoices
    .filter(inv => inv.status === 'overdue')
    .sort((a, b) => new Date(a.due_date) - new Date(b.due_date))
})

// ── At-Risk Clients ────────────────────────────────────────
const atRiskClients = computed(() => {
  const clients = healthData.value?.clients || []
  return clients.filter(c => c.health_score < 50)
})

// ── Helpers ────────────────────────────────────────────────
function formatCurrency(val) {
  if (val === null || val === undefined) return '\u2014'
  const num = parseFloat(val)
  if (num >= 1_000_000) return `${(num / 1_000_000).toFixed(1)}M HUF`
  if (num >= 1_000) return `${(num / 1_000).toFixed(0)}K HUF`
  return `${num.toFixed(0)} HUF`
}

function formatDate(dateStr) {
  if (!dateStr) return '\u2014'
  const d = new Date(dateStr)
  const now = new Date()
  const diff = now - d
  const days = Math.floor(diff / 86400000)
  if (days < 0) return `in ${Math.abs(days)}d`
  if (days === 0) return 'Today'
  if (days === 1) return 'Yesterday'
  if (days < 30) return `${days}d ago`
  return d.toLocaleDateString(undefined, { month: 'short', day: 'numeric' })
}

function formatHours(seconds) {
  const h = parseFloat(seconds || 0) / 3600
  return `${h.toFixed(1)}h`
}

function daysOverdue(dueDateStr) {
  if (!dueDateStr) return 0
  const due = new Date(dueDateStr)
  const now = new Date()
  return Math.max(0, Math.floor((now - due) / 86400000))
}

function rateHealthClass(health) {
  const map = {
    profitable: 'text-green-600 dark:text-green-400 bg-green-50 dark:bg-green-500/10',
    marginal: 'text-amber-600 dark:text-amber-400 bg-amber-50 dark:bg-amber-500/10',
    unprofitable: 'text-red-600 dark:text-red-400 bg-red-50 dark:bg-red-500/10',
  }
  return map[health] || 'text-surface-400 bg-surface-100 dark:bg-surface-700'
}

function rateHealthLabel(health) {
  const map = { profitable: 'Profitable', marginal: 'Marginal', unprofitable: 'Unprofitable' }
  return map[health] || 'Unknown'
}

function marginColor(pct) {
  const n = parseFloat(pct)
  if (n >= 50) return 'text-green-600 dark:text-green-400'
  if (n >= 20) return 'text-amber-600 dark:text-amber-400'
  return 'text-red-600 dark:text-red-400'
}
</script>

<template>
  <div class="h-[100dvh] flex flex-col bg-surface-50 dark:bg-surface-900 ambient-tint" :class="isMobile ? 'overflow-y-auto pb-20' : 'overflow-hidden'">
    <AppHeader
      current-view="crm-executive"
      icon="query_stats"
      title="Overview"
    >
      <template #title-badge>
        <ViewInfoButton view-key="crmExecutive" />
      </template>
    </AppHeader>

    <div :class="isMobile ? 'flex-1 flex flex-col' : 'flex-1 flex overflow-hidden'">
      <CrmSidebar />

      <div :class="isMobile ? 'flex-1 min-w-0' : 'flex-1 flex flex-col overflow-hidden'">
        <!-- Sub-header -->
        <div class="px-4 sm:px-6 py-3 border-b border-surface-200 dark:border-[rgb(var(--color-border))] bg-white dark:bg-[rgb(var(--color-surface))] flex items-center justify-between">
          <p class="text-sm text-surface-500 hidden sm:block">Financial overview across pipeline, clients, projects, and invoices</p>
          <button
            @click="loadExecutiveData"
            class="px-3 py-1.5 rounded-full text-sm font-medium flex items-center gap-1.5 transition-colors text-surface-500 hover:bg-surface-100 dark:hover:bg-surface-700"
          >
            <span class="material-symbols-rounded text-lg">refresh</span>
            Refresh
          </button>
        </div>

        <!-- Loading -->
        <div v-if="loading" class="flex-1 flex items-center justify-center">
          <div class="animate-spin w-8 h-8 border-3 border-primary-500 border-t-transparent rounded-full"></div>
        </div>

        <!-- Content -->
        <div v-else :class="isMobile ? 'p-4 space-y-4' : 'flex-1 overflow-auto p-6 space-y-6'">

          <!-- ═══ KPI Cards ═══ -->
          <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-7 gap-3">
            <div
              v-for="card in kpiCards" :key="card.label"
              :class="['rounded-xl border p-4', card.bg, card.border]"
            >
              <div class="flex items-center justify-between mb-2">
                <span class="text-[10px] font-semibold text-surface-500 uppercase tracking-wider leading-tight">{{ card.label }}</span>
                <span class="material-symbols-rounded text-lg" :class="card.color">{{ card.icon }}</span>
              </div>
              <p class="text-xl font-bold text-surface-900 dark:text-white">{{ card.value }}</p>
              <p v-if="card.sub" class="text-[11px] text-surface-400 mt-0.5">{{ card.sub }}</p>
            </div>
          </div>

          <div class="grid grid-cols-1 xl:grid-cols-2 gap-4 sm:gap-6">

            <!-- ═══ Revenue per Client ═══ -->
            <div class="bg-white dark:bg-[rgb(var(--color-surface))] rounded-xl border border-surface-200 dark:border-[rgb(var(--color-border))] p-6">
              <div class="flex items-center justify-between mb-4">
                <h2 class="text-lg font-semibold text-surface-900 dark:text-white flex items-center gap-2">
                  <span class="material-symbols-rounded text-xl text-green-500">person</span>
                  Revenue per Client
                </h2>
                <button @click="router.push('/crm/dashboard')" class="text-sm text-primary-600 hover:text-primary-700 font-medium">
                  View Details
                </button>
              </div>

              <div v-if="clientRevenue.length" class="space-y-2 max-h-[340px] overflow-y-auto">
                <div
                  v-for="client in clientRevenue" :key="client.id"
                  class="flex items-center gap-3 p-3 rounded-lg border border-surface-100 dark:border-[rgb(var(--color-border))] hover:bg-surface-50 dark:hover:bg-[rgb(var(--color-surface-hover))] transition-colors"
                >
                  <div class="flex-1 min-w-0">
                    <p class="text-sm font-medium text-surface-900 dark:text-white truncate">{{ client.name || client.domain }}</p>
                    <div class="flex items-center gap-2 mt-1">
                      <div class="flex-1 bg-surface-100 dark:bg-surface-700 rounded-full h-1.5">
                        <div
                          class="h-1.5 rounded-full bg-green-500 transition-all"
                          :style="{ width: `${(parseFloat(client.revenue || 0) / maxClientRevenue) * 100}%` }"
                        ></div>
                      </div>
                    </div>
                  </div>
                  <div class="text-right flex-shrink-0">
                    <p class="text-sm font-bold text-surface-900 dark:text-white">{{ formatCurrency(client.revenue) }}</p>
                    <p class="text-[11px] text-surface-400">{{ parseFloat(client.hours || 0).toFixed(1) }}h tracked</p>
                  </div>
                </div>
              </div>
              <div v-else class="text-center py-8 text-surface-400">
                <span class="material-symbols-rounded text-3xl">groups</span>
                <p class="text-sm mt-1">No client revenue data yet</p>
              </div>
            </div>

            <!-- ═══ Profit per Board/Project ═══ -->
            <div class="bg-white dark:bg-[rgb(var(--color-surface))] rounded-xl border border-surface-200 dark:border-[rgb(var(--color-border))] p-6">
              <div class="flex items-center justify-between mb-4">
                <h2 class="text-lg font-semibold text-surface-900 dark:text-white flex items-center gap-2">
                  <span class="material-symbols-rounded text-xl text-emerald-500">view_kanban</span>
                  Profit per Project
                </h2>
              </div>

              <div v-if="boardProfits.length" class="space-y-2 max-h-[340px] overflow-y-auto">
                <div
                  v-for="board in boardProfits" :key="board.id"
                  class="p-3 rounded-lg border border-surface-100 dark:border-[rgb(var(--color-border))] hover:bg-surface-50 dark:hover:bg-[rgb(var(--color-surface-hover))] transition-colors"
                >
                  <div class="flex items-center justify-between mb-1.5">
                    <p class="text-sm font-medium text-surface-900 dark:text-white truncate">{{ board.name }}</p>
                    <span :class="['text-sm font-bold', marginColor(board.marginPct)]">
                      {{ board.marginPct }}% margin
                    </span>
                  </div>
                  <div class="flex items-center gap-4 text-[11px] text-surface-500">
                    <span>Revenue: <strong class="text-surface-700 dark:text-surface-200">{{ formatCurrency(board.revenue) }}</strong></span>
                    <span>Cost: <strong class="text-surface-700 dark:text-surface-200">{{ formatCurrency(board.cost) }}</strong></span>
                    <span>Profit: <strong :class="marginColor(board.marginPct)">{{ formatCurrency(board.margin) }}</strong></span>
                    <span class="ml-auto">{{ board.cards }} cards</span>
                  </div>
                </div>
              </div>
              <div v-else class="text-center py-8 text-surface-400">
                <span class="material-symbols-rounded text-3xl">view_kanban</span>
                <p class="text-sm mt-1">No board financial data available</p>
              </div>
            </div>

            <!-- ═══ Time Tracked vs Billed ═══ -->
            <div class="bg-white dark:bg-[rgb(var(--color-surface))] rounded-xl border border-surface-200 dark:border-[rgb(var(--color-border))] p-6">
              <div class="flex items-center justify-between mb-4">
                <h2 class="text-lg font-semibold text-surface-900 dark:text-white flex items-center gap-2">
                  <span class="material-symbols-rounded text-xl text-indigo-500">timer</span>
                  Time Tracked vs Billed
                </h2>
              </div>

              <div v-if="timeVsBilled.length" class="space-y-2 max-h-[340px] overflow-y-auto">
                <div
                  v-for="entry in timeVsBilled" :key="entry.id"
                  class="flex items-center gap-3 p-3 rounded-lg border border-surface-100 dark:border-[rgb(var(--color-border))] hover:bg-surface-50 dark:hover:bg-[rgb(var(--color-surface-hover))] transition-colors"
                >
                  <div class="flex-1 min-w-0">
                    <p class="text-sm font-medium text-surface-900 dark:text-white truncate">{{ entry.name || entry.domain }}</p>
                    <div class="flex items-center gap-3 mt-1 text-[11px] text-surface-500">
                      <span class="flex items-center gap-1">
                        <span class="material-symbols-rounded text-xs text-indigo-400">schedule</span>
                        {{ entry.hours.toFixed(1) }}h tracked
                      </span>
                      <span class="flex items-center gap-1">
                        <span class="material-symbols-rounded text-xs text-green-400">payments</span>
                        {{ formatCurrency(entry.revenue) }} billed
                      </span>
                    </div>
                  </div>
                  <div class="flex items-center gap-2 flex-shrink-0">
                    <div class="text-right">
                      <p class="text-sm font-bold text-surface-900 dark:text-white">{{ formatCurrency(entry.effectiveRate) }}/h</p>
                      <p v-if="entry.targetRate" class="text-[10px] text-surface-400">Target: {{ formatCurrency(entry.targetRate) }}/h</p>
                    </div>
                    <span :class="['text-[10px] font-medium px-2 py-0.5 rounded-full', rateHealthClass(entry.rateHealth)]">
                      {{ rateHealthLabel(entry.rateHealth) }}
                    </span>
                  </div>
                </div>
              </div>
              <div v-else class="text-center py-8 text-surface-400">
                <span class="material-symbols-rounded text-3xl">timer_off</span>
                <p class="text-sm mt-1">No time tracking data with revenue</p>
              </div>
            </div>

            <!-- ═══ Overdue Invoices ═══ -->
            <div class="bg-white dark:bg-[rgb(var(--color-surface))] rounded-xl border border-surface-200 dark:border-[rgb(var(--color-border))] p-6">
              <div class="flex items-center justify-between mb-4">
                <h2 class="text-lg font-semibold text-surface-900 dark:text-white flex items-center gap-2">
                  <span class="material-symbols-rounded text-xl text-red-500">receipt_long</span>
                  Overdue Invoices
                </h2>
                <button @click="router.push('/crm/invoices')" class="text-sm text-primary-600 hover:text-primary-700 font-medium">
                  All Invoices
                </button>
              </div>

              <div v-if="overdueInvoices.length" class="space-y-2 max-h-[340px] overflow-y-auto">
                <div
                  v-for="inv in overdueInvoices" :key="inv.id"
                  class="flex items-center gap-3 p-3 rounded-lg bg-red-50 dark:bg-red-500/5 border border-red-200 dark:border-red-500/20 hover:bg-red-100 dark:hover:bg-red-500/10 transition-colors cursor-pointer"
                  @click="router.push(`/crm/invoices/${inv.id}`)"
                >
                  <div class="w-9 h-9 rounded-full bg-red-100 dark:bg-red-500/20 flex items-center justify-center flex-shrink-0">
                    <span class="material-symbols-rounded text-lg text-red-600 dark:text-red-400">error</span>
                  </div>
                  <div class="flex-1 min-w-0">
                    <p class="text-sm font-medium text-surface-900 dark:text-white truncate">{{ inv.invoice_number }}</p>
                    <p class="text-[11px] text-red-600 dark:text-red-400">{{ daysOverdue(inv.due_date) }} days overdue</p>
                  </div>
                  <div class="text-right flex-shrink-0">
                    <p class="text-sm font-bold text-red-700 dark:text-red-400">{{ formatCurrency(inv.total) }}</p>
                    <p v-if="inv.paid_amount > 0" class="text-[10px] text-surface-400">Paid: {{ formatCurrency(inv.paid_amount) }}</p>
                  </div>
                </div>
              </div>
              <div v-else class="text-center py-8">
                <div class="w-12 h-12 mx-auto rounded-full bg-green-50 dark:bg-green-500/10 flex items-center justify-center mb-2">
                  <span class="material-symbols-rounded text-2xl text-green-500">check_circle</span>
                </div>
                <p class="text-sm text-surface-500">No overdue invoices</p>
              </div>
            </div>
          </div>

          <!-- ═══ At-Risk Clients (full width) ═══ -->
          <div v-if="atRiskClients.length > 0" class="bg-white dark:bg-[rgb(var(--color-surface))] rounded-xl border border-surface-200 dark:border-[rgb(var(--color-border))] p-6">
            <div class="flex items-center justify-between mb-4">
              <h2 class="text-lg font-semibold text-surface-900 dark:text-white flex items-center gap-2">
                <span class="material-symbols-rounded text-xl text-orange-500">warning</span>
                At-Risk Clients
                <span class="text-sm font-normal text-surface-400 ml-1">({{ atRiskClients.length }})</span>
              </h2>
              <div class="flex items-center gap-3 text-[11px] text-surface-400">
                <span class="flex items-center gap-1"><span class="w-2 h-2 rounded-full bg-orange-500"></span> At Risk (20-49)</span>
                <span class="flex items-center gap-1"><span class="w-2 h-2 rounded-full bg-red-500"></span> Critical (&lt;20)</span>
              </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-3">
              <div
                v-for="client in atRiskClients" :key="client.id"
                class="flex items-center gap-3 p-3 rounded-lg border border-surface-100 dark:border-[rgb(var(--color-border))] hover:bg-surface-50 dark:hover:bg-[rgb(var(--color-surface-hover))] transition-colors"
              >
                <CrmHealthBadge :score="client.health_score" size="md" />
                <div class="flex-1 min-w-0">
                  <p class="text-sm font-medium text-surface-900 dark:text-white truncate">{{ client.name || client.domain }}</p>
                  <p class="text-[11px] text-surface-400">
                    Last activity: {{ client.last_activity ? formatDate(client.last_activity) : 'Never' }}
                  </p>
                </div>
                <div class="text-right flex-shrink-0">
                  <p class="text-sm font-medium text-surface-700 dark:text-surface-200">{{ formatCurrency(client.total_revenue) }}</p>
                </div>
              </div>
            </div>
          </div>

        </div><!-- end content scroll -->
      </div><!-- end flex-1 content -->
    </div><!-- end flex sidebar+content -->

    <MobileBottomNav v-if="isMobile" />
  </div>
</template>
