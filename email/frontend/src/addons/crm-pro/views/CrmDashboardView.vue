<script setup>
/**
 * CrmDashboardView - CRM overview and reporting dashboard
 * Revenue metrics, pipeline overview, client health, upcoming reminders,
 * recent activity feed, and revenue chart.
 */
import { ref, computed, onMounted, onUnmounted } from 'vue'
import api from '@/services/api'
import { useToastStore } from '@/stores/toast'
import { useRouter, useRoute } from 'vue-router'
import AppHeader from '@/components/shared/AppHeader.vue'
import ViewInfoButton from '@/components/shared/ViewInfoButton.vue'
import MobileBottomNav from '@/components/MobileBottomNav.vue'
import CrmSidebar from '../components/CrmSidebar.vue'
import CrmHealthBadge from '../components/CrmHealthBadge.vue'
import CrmAgingChart from '../components/CrmAgingChart.vue'
import CrmClientRanking from '../components/CrmClientRanking.vue'
import CrmForecastChart from '../components/CrmForecastChart.vue'
import CrmConversionFunnel from '../components/CrmConversionFunnel.vue'
import ProfitabilityMini from '../components/CrmProfitabilityMini.vue'
import FeatureGuide from '@/components/shared/FeatureGuide.vue'
import HowItWorksButton from '@/components/shared/HowItWorksButton.vue'
import { featureGuides } from '@/data/featureGuides'
import StepGuide from '@/components/shared/StepGuide.vue'
import { crmDashboardGuide } from '@/data/stepGuides'

const toast = useToastStore()
const router = useRouter()
const route = useRoute()

// CRM Sharing / Switcher state
const viewingOwner = ref(route.query.owner || null) // null = own CRM
const sharedCrms = ref([])
const showCrmSwitcher = ref(false)

const showFeatureGuide = ref(false)
const showStepGuide = ref(false)
const guideData = featureGuides.crmDashboard

const dashboardTitle = computed(() => {
  if (!viewingOwner.value) return 'Forecast & Reports'
  const shared = sharedCrms.value.find(s => s.owner_email === viewingOwner.value)
  const name = shared?.owner_name || viewingOwner.value
  return `Forecast & Reports - ${name}`
})

// State
const loading = ref(true)
const dashboardData = ref(null)
const pipelineData = ref(null)
const revenueData = ref(null)
const healthData = ref(null)
const activeTab = ref('overview') // overview, pipeline, health, revenue, clients, forecast

// Build query params for API calls (adds owner= when viewing a shared CRM)
function ownerParam() {
  return viewingOwner.value ? `?owner=${encodeURIComponent(viewingOwner.value)}` : ''
}

// Fetch all data in parallel
async function loadDashboard() {
  loading.value = true
  try {
    const suffix = viewingOwner.value ? `&owner=${encodeURIComponent(viewingOwner.value)}` : ''
    const [dashRes, pipeRes, revRes, healthRes] = await Promise.all([
      api.get(`/crm/dashboard${ownerParam()}`),
      api.get(`/crm/reports/pipeline${ownerParam()}`),
      api.get(`/crm/reports/revenue?months=12${suffix}`),
      api.get(`/crm/reports/health${ownerParam()}`),
    ])

    if (dashRes.data?.success) dashboardData.value = dashRes.data.data
    if (pipeRes.data?.success) pipelineData.value = pipeRes.data.data
    if (revRes.data?.success) revenueData.value = revRes.data.data
    if (healthRes.data?.success) healthData.value = healthRes.data.data
  } catch (e) {
    toast.error(e.response?.data?.message || 'Failed to load dashboard data')
  } finally {
    loading.value = false
  }
}

async function loadSharedCrms() {
  try {
    const res = await api.get('/crm/sharing')
    if (res.data?.success) {
      const individual = res.data.data.shared_with_me?.individual || []
      const fromGroups = res.data.data.shared_with_me?.from_groups || []
      // Merge and deduplicate by owner_email
      const seen = new Set()
      sharedCrms.value = [...individual, ...fromGroups].filter(s => {
        if (seen.has(s.owner_email)) return false
        seen.add(s.owner_email)
        return true
      })
    }
  } catch (e) {
    // Silently fail - sharing endpoint might not be available
  }
}

function switchCrm(ownerEmail) {
  viewingOwner.value = ownerEmail
  showCrmSwitcher.value = false
  if (ownerEmail) {
    router.replace({ query: { ...route.query, owner: ownerEmail } })
  } else {
    const q = { ...route.query }
    delete q.owner
    router.replace({ query: q })
  }
  loadDashboard()
}

const isMobile = ref(false)
function checkMobile() {
  isMobile.value = window.innerWidth < 768
}

onMounted(async () => {
  await Promise.all([loadDashboard(), loadSharedCrms()])
  checkMobile()
  window.addEventListener('resize', checkMobile)
})

onUnmounted(() => {
  window.removeEventListener('resize', checkMobile)
})

// Computed helpers
const metricCards = computed(() => {
  if (!dashboardData.value) return []
  const d = dashboardData.value
  return [
    {
      label: 'Active Deals',
      value: d.deals?.total_deals ?? '—',
      icon: 'handshake',
      color: 'text-blue-500',
      bg: 'bg-blue-50 dark:bg-blue-500/10',
    },
    {
      label: 'Won Revenue',
      value: formatCurrency(d.deals?.total_won_value ?? 0),
      icon: 'emoji_events',
      color: 'text-green-500',
      bg: 'bg-green-50 dark:bg-green-500/10',
    },
    {
      label: 'Outstanding',
      value: formatCurrency(d.invoice_stats?.outstanding ?? 0),
      icon: 'receipt_long',
      color: 'text-amber-500',
      bg: 'bg-amber-50 dark:bg-amber-500/10',
    },
    {
      label: 'Paid This Month',
      value: formatCurrency(d.invoice_stats?.paid_this_month ?? 0),
      icon: 'payments',
      color: 'text-emerald-500',
      bg: 'bg-emerald-50 dark:bg-emerald-500/10',
    },
  ]
})

const pipelineStages = computed(() => {
  if (!pipelineData.value?.stage_stats) return []
  const stageOrder = ['lead', 'contacted', 'proposal', 'negotiation', 'won', 'lost']
  const stageNames = {
    lead: 'Lead', contacted: 'Contacted', proposal: 'Proposal',
    negotiation: 'Negotiation', won: 'Won', lost: 'Lost',
  }
  const stageColors = {
    lead: 'bg-blue-500', contacted: 'bg-indigo-500', proposal: 'bg-purple-500',
    negotiation: 'bg-yellow-500', won: 'bg-green-500', lost: 'bg-red-500',
  }

  return stageOrder.map(key => {
    const stat = pipelineData.value.stage_stats.find(s => s.pipeline_stage === key) || {}
    return {
      key,
      name: stageNames[key],
      color: stageColors[key],
      count: parseInt(stat.count || 0),
      value: parseFloat(stat.total_value || 0),
    }
  })
})

const totalPipelineValue = computed(() => {
  return pipelineStages.value.reduce((sum, s) => sum + s.value, 0)
})

const maxRevenueMonth = computed(() => {
  if (!revenueData.value?.revenue?.length) return 1
  return Math.max(...revenueData.value.revenue.map(r => parseFloat(r.total)), 1)
})

const healthClients = computed(() => {
  return healthData.value?.clients || []
})

const atRiskClients = computed(() => {
  return healthClients.value.filter(c => c.health_score < 50)
})

// Helpers
function formatCurrency(val) {
  if (val === null || val === undefined) return '—'
  const num = parseFloat(val)
  if (num >= 1_000_000) return `${(num / 1_000_000).toFixed(1)}M HUF`
  if (num >= 1_000) return `${(num / 1_000).toFixed(0)}K HUF`
  return `${num.toFixed(0)} HUF`
}

function formatDate(dateStr) {
  if (!dateStr) return '—'
  const d = new Date(dateStr)
  const now = new Date()
  const diff = now - d
  const hours = Math.floor(diff / 3600000)
  const days = Math.floor(diff / 86400000)

  if (hours < 1) return 'Just now'
  if (hours < 24) return `${hours}h ago`
  if (days < 7) return `${days}d ago`
  return d.toLocaleDateString(undefined, { month: 'short', day: 'numeric' })
}

function formatMonthLabel(monthStr) {
  // monthStr is 'YYYY-MM'
  const [y, m] = monthStr.split('-')
  const months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec']
  return months[parseInt(m) - 1] || m
}

function getActivityIcon(type) {
  const map = { deal: 'handshake', invoice: 'receipt_long', update: 'campaign', document: 'description' }
  return map[type] || 'event'
}

function getActivityColor(type) {
  const map = {
    deal: 'bg-indigo-100 dark:bg-indigo-500/20 text-indigo-600 dark:text-indigo-400',
    invoice: 'bg-amber-100 dark:bg-amber-500/20 text-amber-600 dark:text-amber-400',
  }
  return map[type] || 'bg-surface-100 dark:bg-surface-700 text-surface-500'
}

function goToPipeline() { router.push({ name: 'crm-pipeline' }) }
function goToInvoices() { router.push({ name: 'crm-invoices' }) }
</script>

<template>
  <div class="h-[100dvh] flex flex-col bg-surface-50 dark:bg-surface-900 ambient-tint" :class="isMobile ? 'overflow-y-auto pb-20' : 'overflow-hidden'">
    <!-- App Header -->
    <AppHeader
      current-view="crm-dashboard"
      icon="bar_chart_4_bars"
      :title="dashboardTitle"
    >
      <template #title-badge>
        <ViewInfoButton view-key="crmDashboard" />
        <HowItWorksButton @click="showStepGuide = true" />
        <HowItWorksButton variant="features" :active="showFeatureGuide" @click="showFeatureGuide = !showFeatureGuide" />
      </template>
    </AppHeader>

    <div :class="isMobile ? 'flex-1 flex flex-col' : 'flex-1 flex overflow-hidden'">
      <CrmSidebar />

      <div :class="isMobile ? 'flex-1 min-w-0' : 'flex-1 flex flex-col overflow-hidden'">
    <!-- Sub-header with tabs + CRM switcher -->
    <div class="px-4 sm:px-6 py-3 border-b border-surface-200 dark:border-[rgb(var(--color-border))] bg-white dark:bg-[rgb(var(--color-surface))] flex items-center justify-between">
      <div class="flex items-center gap-3">
        <p class="text-sm text-surface-500 hidden sm:block">Overview of your business metrics and activity</p>
        <!-- CRM Switcher -->
        <div v-if="sharedCrms.length > 0" class="relative">
          <button
            @click="showCrmSwitcher = !showCrmSwitcher"
            class="flex items-center gap-1.5 px-3 py-1.5 rounded-full text-sm bg-surface-100 dark:bg-surface-800 text-surface-600 dark:text-surface-400 hover:bg-surface-200 dark:hover:bg-surface-700 transition-colors"
          >
            <span class="material-symbols-rounded text-[16px]">swap_horiz</span>
            {{ viewingOwner ? 'Viewing shared' : 'My CRM' }}
            <span class="material-symbols-rounded text-[14px]">expand_more</span>
          </button>
          <!-- Dropdown -->
          <div
            v-if="showCrmSwitcher"
            class="absolute top-full left-0 mt-1 w-64 bg-white dark:bg-surface-800 rounded-xl shadow-lg border border-surface-200 dark:border-surface-700 z-50 overflow-hidden"
          >
            <button
              @click="switchCrm(null)"
              :class="[
                'w-full px-4 py-2.5 text-left text-sm flex items-center gap-2 transition-colors',
                !viewingOwner ? 'bg-blue-50 dark:bg-blue-500/10 text-blue-600 dark:text-blue-400' : 'hover:bg-surface-50 dark:hover:bg-surface-700'
              ]"
            >
              <span class="material-symbols-rounded text-[16px]">person</span>
              My CRM
            </button>
            <div class="border-t border-surface-100 dark:border-surface-700"></div>
            <div class="max-h-48 overflow-y-auto">
              <button
                v-for="crm in sharedCrms"
                :key="crm.owner_email"
                @click="switchCrm(crm.owner_email)"
                :class="[
                  'w-full px-4 py-2.5 text-left text-sm flex items-center gap-2 transition-colors',
                  viewingOwner === crm.owner_email ? 'bg-blue-50 dark:bg-blue-500/10 text-blue-600 dark:text-blue-400' : 'hover:bg-surface-50 dark:hover:bg-surface-700'
                ]"
              >
                <span class="material-symbols-rounded text-[16px]">folder_shared</span>
                <div class="truncate">
                  <div class="font-medium">{{ crm.owner_name || crm.owner_email }}</div>
                  <div v-if="crm.owner_name" class="text-xs text-surface-500">{{ crm.owner_email }}</div>
                </div>
              </button>
            </div>
          </div>
        </div>
      </div>
      <div class="flex gap-1 items-center">
        <button
          v-for="tab in [
            { key: 'overview', label: 'Overview', icon: 'monitoring' },
            { key: 'pipeline', label: 'Deals & Pipeline', icon: 'view_kanban' },
            { key: 'health', label: 'Client Health', icon: 'favorite' },
            { key: 'revenue', label: 'Revenue', icon: 'account_balance' },
            { key: 'clients', label: 'Clients', icon: 'leaderboard' },
            { key: 'forecast', label: 'Forecast', icon: 'trending_up' },
          ]" :key="tab.key"
          @click="activeTab = tab.key"
          :class="[
            'px-3 py-2 rounded-full text-sm font-medium flex items-center gap-1.5 transition-colors',
            activeTab === tab.key
              ? 'bg-primary-100 dark:bg-primary-500/20 text-primary-700 dark:text-primary-300'
              : 'text-surface-500 hover:bg-surface-100 dark:hover:bg-surface-700'
          ]"
        >
          <span class="material-symbols-rounded text-lg">{{ tab.icon }}</span>
          {{ tab.label }}
        </button>
        <div class="w-px h-5 bg-surface-200 dark:bg-surface-700 mx-1"></div>
        <button
          @click="router.push('/crm/automation')"
          class="px-3 py-2 rounded-full text-sm font-medium flex items-center gap-1.5 transition-colors text-surface-500 hover:bg-surface-100 dark:hover:bg-surface-700"
        >
          <span class="material-symbols-rounded text-lg">smart_toy</span>
          Workflows
        </button>
        <button
          @click="router.push('/crm/sharing')"
          class="px-3 py-2 rounded-full text-sm font-medium flex items-center gap-1.5 transition-colors text-surface-500 hover:bg-surface-100 dark:hover:bg-surface-700"
        >
          <span class="material-symbols-rounded text-lg">share</span>
          Sharing
        </button>
      </div>
    </div>

    <!-- Feature Guide -->
    <FeatureGuide v-model="showFeatureGuide" :tiers="guideData.tiers" :integrations="guideData.integrations" :title-key="guideData.titleKey" :footer-key="guideData.footerKey" :layer-key="guideData.layerKey" :layer-icon="guideData.layerIcon" />

    <!-- Loading -->
    <div v-if="loading" class="flex-1 flex items-center justify-center">
      <div class="animate-spin w-8 h-8 border-3 border-primary-500 border-t-transparent rounded-full"></div>
    </div>

    <div v-else class="flex-1 overflow-auto p-6 space-y-6">
      <!-- ============================================================ -->
      <!-- Overview Tab -->
      <!-- ============================================================ -->
      <template v-if="activeTab === 'overview'">
        <!-- Metric Cards -->
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
          <div
            v-for="card in metricCards" :key="card.label"
            :class="['rounded-xl border border-surface-200 dark:border-[rgb(var(--color-border))] p-5', card.bg]"
          >
            <div class="flex items-center justify-between mb-3">
              <span class="text-xs font-medium text-surface-500 uppercase tracking-wider">{{ card.label }}</span>
              <span class="material-symbols-rounded text-xl" :class="card.color">{{ card.icon }}</span>
            </div>
            <p class="text-2xl font-bold text-surface-900 dark:text-white">{{ card.value }}</p>
          </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
          <!-- Revenue Chart -->
          <div class="lg:col-span-2 bg-white dark:bg-[rgb(var(--color-surface))] rounded-xl border border-surface-200 dark:border-[rgb(var(--color-border))] p-6">
            <div class="flex items-center justify-between mb-4">
              <h2 class="text-lg font-semibold text-surface-900 dark:text-white">Revenue (Last 12 Months)</h2>
              <button @click="goToInvoices" class="text-sm text-primary-600 hover:text-primary-700 font-medium">View Invoices</button>
            </div>

            <div v-if="revenueData?.revenue?.length" class="space-y-2">
              <!-- Simple bar chart -->
              <div class="flex items-end gap-1 h-40">
                <div
                  v-for="month in revenueData.revenue" :key="month.month"
                  class="flex-1 flex flex-col items-center gap-1"
                >
                  <span class="text-[10px] text-surface-400 font-medium">{{ formatCurrency(month.total) }}</span>
                  <div
                    class="w-full bg-primary-500 dark:bg-primary-400 rounded-t-md transition-all"
                    :style="{ height: `${Math.max((parseFloat(month.total) / maxRevenueMonth) * 100, 4)}%` }"
                  ></div>
                  <span class="text-[10px] text-surface-400">{{ formatMonthLabel(month.month) }}</span>
                </div>
              </div>
            </div>
            <div v-else class="h-40 flex items-center justify-center text-surface-400">
              <p class="text-sm">No revenue data available yet.</p>
            </div>
          </div>

          <!-- Upcoming Reminders -->
          <div class="bg-white dark:bg-[rgb(var(--color-surface))] rounded-xl border border-surface-200 dark:border-[rgb(var(--color-border))] p-6">
            <h2 class="text-lg font-semibold text-surface-900 dark:text-white mb-4 flex items-center gap-2">
              <span class="material-symbols-rounded text-xl text-orange-500">notification_important</span>
              Upcoming Reminders
            </h2>
            <div v-if="dashboardData?.upcoming_reminders?.length" class="space-y-3">
              <div
                v-for="rem in dashboardData.upcoming_reminders" :key="rem.id"
                class="flex items-start gap-3 p-3 rounded-lg bg-surface-50 dark:bg-[rgb(var(--color-surface-elevated))]"
              >
                <div class="w-8 h-8 rounded-full bg-orange-100 dark:bg-orange-500/20 flex items-center justify-center flex-shrink-0">
                  <span class="material-symbols-rounded text-base text-orange-600 dark:text-orange-400">alarm</span>
                </div>
                <div class="min-w-0">
                  <p class="text-sm font-medium text-surface-900 dark:text-white truncate">{{ rem.title }}</p>
                  <p class="text-xs text-surface-400 mt-0.5">Due {{ formatDate(rem.remind_at) }}</p>
                </div>
              </div>
            </div>
            <div v-else class="text-center py-6 text-surface-400">
              <span class="material-symbols-rounded text-3xl">check_circle</span>
              <p class="text-sm mt-1">No upcoming reminders</p>
            </div>
          </div>
        </div>

        <!-- Recent Activity -->
        <div class="bg-white dark:bg-[rgb(var(--color-surface))] rounded-xl border border-surface-200 dark:border-[rgb(var(--color-border))] p-6">
          <h2 class="text-lg font-semibold text-surface-900 dark:text-white mb-4">Recent Activity</h2>
          <div v-if="dashboardData?.recent_activity?.length" class="space-y-3">
            <div
              v-for="(activity, i) in dashboardData.recent_activity" :key="i"
              class="flex items-center gap-3 p-3 rounded-lg hover:bg-surface-50 dark:hover:bg-[rgb(var(--color-surface-hover))] transition-colors"
            >
              <div :class="['w-9 h-9 rounded-full flex items-center justify-center flex-shrink-0', getActivityColor(activity.type)]">
                <span class="material-symbols-rounded text-lg">{{ getActivityIcon(activity.type) }}</span>
              </div>
              <div class="flex-1 min-w-0">
                <p class="text-sm font-medium text-surface-900 dark:text-white truncate">{{ activity.title }}</p>
                <p class="text-xs text-surface-400 truncate">{{ activity.detail }}</p>
              </div>
              <span class="text-xs text-surface-400 flex-shrink-0">{{ formatDate(activity.date) }}</span>
            </div>
          </div>
          <div v-else class="text-center py-8 text-surface-400">
            <span class="material-symbols-rounded text-4xl">history</span>
            <p class="text-sm mt-2">No recent activity</p>
          </div>
        </div>

        <!-- At-Risk Clients Banner -->
        <div v-if="atRiskClients.length > 0" class="bg-red-50 dark:bg-red-500/10 rounded-xl border border-red-200 dark:border-red-500/30 p-5">
          <div class="flex items-center gap-3 mb-3">
            <span class="material-symbols-rounded text-2xl text-red-500">warning</span>
            <h2 class="text-lg font-semibold text-red-700 dark:text-red-400">{{ atRiskClients.length }} At-Risk Clients</h2>
          </div>
          <div class="flex flex-wrap gap-2">
            <div
              v-for="client in atRiskClients.slice(0, 6)" :key="client.id"
              class="flex items-center gap-2 px-3 py-1.5 rounded-lg bg-white dark:bg-[rgb(var(--color-surface))] border border-red-200 dark:border-red-500/30"
            >
              <CrmHealthBadge :score="client.health_score" :showLabel="false" size="sm" />
              <span class="text-sm font-medium text-surface-900 dark:text-white">{{ client.name || client.domain }}</span>
            </div>
            <button v-if="atRiskClients.length > 6" @click="activeTab = 'health'" class="text-sm text-red-600 hover:text-red-700 font-medium px-3 py-1.5">
              +{{ atRiskClients.length - 6 }} more →
            </button>
          </div>
        </div>
      </template>

      <!-- ============================================================ -->
      <!-- Pipeline Tab -->
      <!-- ============================================================ -->
      <template v-if="activeTab === 'pipeline'">
        <!-- Pipeline Summary -->
        <div class="bg-white dark:bg-[rgb(var(--color-surface))] rounded-xl border border-surface-200 dark:border-[rgb(var(--color-border))] p-6">
          <div class="flex items-center justify-between mb-6">
            <div>
              <h2 class="text-lg font-semibold text-surface-900 dark:text-white">Deals & Pipeline Overview</h2>
              <p class="text-sm text-surface-500 hidden sm:block">Total pipeline value: {{ formatCurrency(totalPipelineValue) }}</p>
            </div>
            <div class="flex items-center gap-4">
              <div class="text-center">
                <p class="text-2xl font-bold text-primary-600">{{ pipelineData?.conversion_rate ?? 0 }}%</p>
                <p class="text-xs text-surface-500">Win Rate</p>
              </div>
              <button @click="goToPipeline" class="px-4 py-2 rounded-lg bg-primary-600 hover:bg-primary-700 text-white text-sm font-medium">
                Open Deals & Pipeline
              </button>
            </div>
          </div>

          <!-- Stage bars -->
          <div class="space-y-4">
            <div v-for="stage in pipelineStages" :key="stage.key" class="space-y-1.5">
              <div class="flex items-center justify-between text-sm">
                <span class="font-medium text-surface-700 dark:text-surface-200">{{ stage.name }}</span>
                <span class="text-surface-500">{{ stage.count }} deals · {{ formatCurrency(stage.value) }}</span>
              </div>
              <div class="w-full bg-surface-100 dark:bg-surface-700 rounded-full h-3">
                <div
                  :class="['h-3 rounded-full transition-all', stage.color]"
                  :style="{ width: `${totalPipelineValue > 0 ? (stage.value / totalPipelineValue) * 100 : 0}%`, minWidth: stage.count > 0 ? '1rem' : '0' }"
                ></div>
              </div>
            </div>
          </div>
        </div>

        <!-- Recent Wins -->
        <div v-if="pipelineData?.recent_wins?.length" class="bg-white dark:bg-[rgb(var(--color-surface))] rounded-xl border border-surface-200 dark:border-[rgb(var(--color-border))] p-6">
          <h2 class="text-lg font-semibold text-surface-900 dark:text-white mb-4 flex items-center gap-2">
            <span class="material-symbols-rounded text-xl text-green-500">emoji_events</span>
            Recent Wins
          </h2>
          <div class="space-y-3">
            <div
              v-for="win in pipelineData.recent_wins" :key="win.id"
              class="flex items-center gap-3 p-3 rounded-lg bg-green-50 dark:bg-green-500/10 border border-green-200 dark:border-green-500/20"
            >
              <div class="w-9 h-9 rounded-full bg-green-100 dark:bg-green-500/20 flex items-center justify-center flex-shrink-0">
                <span class="material-symbols-rounded text-lg text-green-600 dark:text-green-400">check_circle</span>
              </div>
              <div class="flex-1 min-w-0">
                <p class="text-sm font-medium text-surface-900 dark:text-white truncate">{{ win.title }}</p>
                <p class="text-xs text-surface-500 truncate">{{ win.client_name || win.client_domain }}</p>
              </div>
              <span class="text-sm font-bold text-green-700 dark:text-green-400 flex-shrink-0">{{ formatCurrency(win.actual_value || win.expected_value) }}</span>
            </div>
          </div>
        </div>
      </template>

      <!-- ============================================================ -->
      <!-- Health Tab -->
      <!-- ============================================================ -->
      <template v-if="activeTab === 'health'">
        <div class="bg-white dark:bg-[rgb(var(--color-surface))] rounded-xl border border-surface-200 dark:border-[rgb(var(--color-border))] p-6">
          <div class="flex items-center justify-between mb-6">
            <div>
              <h2 class="text-lg font-semibold text-surface-900 dark:text-white">Client Health Report</h2>
              <p class="text-sm text-surface-500 hidden sm:block">Based on recency of interaction across all CRM touchpoints</p>
            </div>
            <div class="flex items-center gap-3 text-xs">
              <span class="flex items-center gap-1"><span class="w-2.5 h-2.5 rounded-full bg-green-500"></span> Healthy (80+)</span>
              <span class="flex items-center gap-1"><span class="w-2.5 h-2.5 rounded-full bg-yellow-500"></span> Moderate (50-79)</span>
              <span class="flex items-center gap-1"><span class="w-2.5 h-2.5 rounded-full bg-orange-500"></span> At Risk (20-49)</span>
              <span class="flex items-center gap-1"><span class="w-2.5 h-2.5 rounded-full bg-red-500"></span> Critical (&lt;20)</span>
            </div>
          </div>

          <div v-if="healthClients.length" class="space-y-2">
            <div
              v-for="client in healthClients" :key="client.id"
              class="flex items-center gap-4 p-3 rounded-lg hover:bg-surface-50 dark:hover:bg-[rgb(var(--color-surface-hover))] transition-colors border border-surface-100 dark:border-[rgb(var(--color-border))]"
            >
              <CrmHealthBadge :score="client.health_score" size="md" />
              <div class="flex-1 min-w-0">
                <p class="text-sm font-medium text-surface-900 dark:text-white truncate">{{ client.name || client.domain }}</p>
                <p class="text-xs text-surface-400">
                  Last activity: {{ client.last_activity ? formatDate(client.last_activity) : 'Never' }}
                </p>
              </div>
              <div class="text-right flex-shrink-0">
                <p class="text-sm font-medium text-surface-700 dark:text-surface-200">{{ formatCurrency(client.total_revenue) }}</p>
                <p class="text-xs text-surface-400">Total revenue</p>
              </div>
            </div>
          </div>
          <div v-else class="text-center py-12 text-surface-400">
            <span class="material-symbols-rounded text-4xl">groups</span>
            <p class="text-sm mt-2">No client health data available yet.</p>
          </div>
        </div>
      </template>

      <!-- ============================================================ -->
      <!-- Revenue Tab (Aging + Profitability) -->
      <!-- ============================================================ -->
      <template v-if="activeTab === 'revenue'">
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
          <!-- Aging Invoices -->
          <div class="bg-white dark:bg-[rgb(var(--color-surface))] rounded-xl border border-surface-200 dark:border-[rgb(var(--color-border))] p-6">
            <h2 class="text-lg font-semibold text-surface-900 dark:text-white mb-4 flex items-center gap-2">
              <span class="material-symbols-rounded text-xl text-red-500">schedule</span>
              Invoice Aging
            </h2>
            <CrmAgingChart />
          </div>

          <!-- Profitability Overview -->
          <div class="bg-white dark:bg-[rgb(var(--color-surface))] rounded-xl border border-surface-200 dark:border-[rgb(var(--color-border))] p-6">
            <h2 class="text-lg font-semibold text-surface-900 dark:text-white mb-4 flex items-center gap-2">
              <span class="material-symbols-rounded text-xl text-emerald-500">savings</span>
              Time Profitability
            </h2>
            <p class="text-sm text-surface-500 mb-4 hidden sm:block">Revenue vs hours tracked per client</p>
            <!-- Inline profitability mini-view -->
            <ProfitabilityMini />
          </div>
        </div>
      </template>

      <!-- ============================================================ -->
      <!-- Clients Tab (Ranking) -->
      <!-- ============================================================ -->
      <template v-if="activeTab === 'clients'">
        <div class="bg-white dark:bg-[rgb(var(--color-surface))] rounded-xl border border-surface-200 dark:border-[rgb(var(--color-border))] p-6">
          <h2 class="text-lg font-semibold text-surface-900 dark:text-white mb-4 flex items-center gap-2">
            <span class="material-symbols-rounded text-xl text-amber-500">leaderboard</span>
            Client Value Ranking
          </h2>
          <CrmClientRanking />
        </div>
      </template>

      <!-- ============================================================ -->
      <!-- Forecast Tab (Forecast + Funnel) -->
      <!-- ============================================================ -->
      <template v-if="activeTab === 'forecast'">
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
          <!-- Deal Forecast -->
          <div class="bg-white dark:bg-[rgb(var(--color-surface))] rounded-xl border border-surface-200 dark:border-[rgb(var(--color-border))] p-6">
            <h2 class="text-lg font-semibold text-surface-900 dark:text-white mb-4 flex items-center gap-2">
              <span class="material-symbols-rounded text-xl text-blue-500">trending_up</span>
              Deal Forecast
            </h2>
            <CrmForecastChart />
          </div>

          <!-- Conversion Funnel -->
          <div class="bg-white dark:bg-[rgb(var(--color-surface))] rounded-xl border border-surface-200 dark:border-[rgb(var(--color-border))] p-6">
            <h2 class="text-lg font-semibold text-surface-900 dark:text-white mb-4 flex items-center gap-2">
              <span class="material-symbols-rounded text-xl text-purple-500">filter_alt</span>
              Conversion Funnel
            </h2>
            <CrmConversionFunnel />
          </div>
        </div>
      </template>
    </div>

      </div><!-- end flex-1 content -->
    </div><!-- end flex sidebar+content -->

    <StepGuide
      v-if="showStepGuide"
      :title-key="crmDashboardGuide.titleKey"
      :subtitle-key="crmDashboardGuide.subtitleKey"
      :header-icon="crmDashboardGuide.headerIcon"
      :header-color="crmDashboardGuide.headerColor"
      :storage-key="crmDashboardGuide.storageKey"
      :steps="crmDashboardGuide.steps"
      @close="showStepGuide = false"
    />

    <MobileBottomNav v-if="isMobile" />
  </div>
</template>
