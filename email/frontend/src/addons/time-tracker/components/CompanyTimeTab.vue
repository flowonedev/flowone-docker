<script setup>
/**
 * CompanyTimeTab - Company-wide time overview with member x client matrix.
 * Shows who is spending time on which client across the entire organisation.
 */
import { ref, computed, watch, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import api from '@/services/api'
import { useToastStore } from '@/stores/toast'

const router = useRouter()
const toast = useToastStore()

const props = defineProps({
  period: { type: String, default: 'week' },
  clientId: { type: [Number, null], default: null },
})

const loading = ref(true)
const data = ref(null)

const members = computed(() => data.value?.by_member || [])
const clients = computed(() => data.value?.by_client || [])
const matrix = computed(() => data.value?.matrix || {})
const totalSeconds = computed(() => data.value?.total_seconds || 0)
const dailyBreakdown = computed(() => data.value?.daily_breakdown || [])

const topMembers = computed(() => members.value.slice(0, 10))
const topClients = computed(() => clients.value.slice(0, 10))

function maxMatrixVal() {
  let max = 0
  for (const row of Object.values(matrix.value)) {
    for (const v of Object.values(row)) {
      if (v > max) max = v
    }
  }
  return max || 1
}

async function loadData() {
  loading.value = true
  try {
    const params = { period: props.period }
    if (props.clientId) params.client_id = props.clientId
    const res = await api.get('/time/team-stats', { params })
    if (res.data?.success) data.value = res.data.data
  } catch {
    toast.error('Failed to load company time data')
  } finally {
    loading.value = false
  }
}

function formatTime(seconds) {
  if (!seconds || seconds <= 0) return '0s'
  const h = Math.floor(seconds / 3600)
  const m = Math.floor((seconds % 3600) / 60)
  if (h > 0) return `${h}h ${m}m`
  if (m > 0) return `${m}m`
  return `${seconds}s`
}

function formatHours(seconds) {
  if (!seconds) return '0'
  const h = (seconds / 3600).toFixed(1)
  return h.endsWith('.0') ? h.slice(0, -2) : h
}

function formatCurrency(amount) {
  if (amount === null || amount === undefined) return null
  const formatted = new Intl.NumberFormat('en-US', {
    minimumFractionDigits: 0,
    maximumFractionDigits: 0,
  }).format(amount)
  return `${formatted} Ft`
}

const totalValue = computed(() =>
  clients.value.reduce((sum, c) => sum + (c.value || 0), 0)
)

function formatDeadline(dateStr) {
  if (!dateStr) return null
  const d = new Date(dateStr.replace(' ', 'T'))
  const today = new Date()
  today.setHours(0, 0, 0, 0)
  const diff = Math.round((new Date(d.getFullYear(), d.getMonth(), d.getDate()) - today) / 86400000)
  if (diff < 0) return 'overdue'
  if (diff === 0) return 'today'
  if (diff === 1) return 'tomorrow'
  if (diff < 7) return d.toLocaleDateString([], { weekday: 'short' })
  return d.toLocaleDateString([], { month: 'short', day: 'numeric' })
}

function cellOpacity(seconds) {
  if (!seconds) return 0
  return Math.max(0.15, seconds / maxMatrixVal())
}

function memberInitials(member) {
  return (member.name || member.email).substring(0, 2).toUpperCase()
}

watch(() => [props.period, props.clientId], loadData)
onMounted(loadData)

defineExpose({ refresh: loadData })
</script>

<template>
  <div>
    <!-- KPI Row -->
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-6">
      <div class="bg-gradient-to-br from-violet-500 to-fuchsia-600 rounded-2xl p-5 text-white shadow-lg shadow-violet-500/20">
        <div class="flex items-center justify-between mb-3">
          <span class="text-violet-100 text-sm font-medium">Company Total</span>
          <div class="w-10 h-10 rounded-xl bg-white/20 flex items-center justify-center">
            <span class="material-symbols-rounded text-xl">corporate_fare</span>
          </div>
        </div>
        <p class="text-3xl font-bold">{{ formatTime(totalSeconds) }}</p>
        <p class="text-sm text-violet-200 mt-1">{{ formatHours(totalSeconds) }}h total</p>
      </div>

      <div class="bg-white dark:bg-surface-800 rounded-2xl p-5 border border-surface-200 dark:border-surface-700">
        <div class="flex items-center justify-between mb-3">
          <span class="text-surface-500 text-sm font-medium">Active Members</span>
          <div class="w-10 h-10 rounded-xl bg-blue-100 dark:bg-blue-500/20 flex items-center justify-center">
            <span class="material-symbols-rounded text-xl text-blue-600 dark:text-blue-400">groups</span>
          </div>
        </div>
        <p class="text-3xl font-bold text-surface-900 dark:text-surface-100">{{ data?.member_count || 0 }}</p>
        <p class="text-sm text-surface-500 mt-1">Tracked time this period</p>
      </div>

      <div class="bg-white dark:bg-surface-800 rounded-2xl p-5 border border-surface-200 dark:border-surface-700">
        <div class="flex items-center justify-between mb-3">
          <span class="text-surface-500 text-sm font-medium">Active Clients</span>
          <div class="w-10 h-10 rounded-xl bg-green-100 dark:bg-green-500/20 flex items-center justify-center">
            <span class="material-symbols-rounded text-xl text-green-600 dark:text-green-400">domain</span>
          </div>
        </div>
        <p class="text-3xl font-bold text-surface-900 dark:text-surface-100">{{ data?.client_count || 0 }}</p>
        <p class="text-sm text-surface-500 mt-1">With logged hours</p>
      </div>
    </div>

    <!-- Loading -->
    <div v-if="loading && !data" class="flex items-center justify-center py-16">
      <span class="material-symbols-rounded text-4xl text-primary-500 animate-spin">progress_activity</span>
    </div>

    <template v-else>
      <!-- Daily trend mini chart -->
      <div v-if="dailyBreakdown.length > 1" class="bg-white dark:bg-surface-800 rounded-2xl border border-surface-200 dark:border-surface-700 p-4 mb-6">
        <h3 class="text-sm font-semibold text-surface-700 dark:text-surface-300 mb-3 flex items-center gap-2">
          <span class="material-symbols-rounded text-lg text-violet-500">show_chart</span>
          Daily Trend (All Members)
        </h3>
        <div class="flex items-end gap-1 h-24">
          <div
            v-for="day in dailyBreakdown"
            :key="day.date"
            class="flex-1 group relative"
          >
            <div
              class="w-full rounded-t bg-violet-500 dark:bg-violet-400 transition-all hover:bg-violet-600"
              :style="{
                height: dailyBreakdown.reduce((m, d) => Math.max(m, d.total_seconds), 0) > 0
                  ? Math.max(2, (day.total_seconds / dailyBreakdown.reduce((m, d) => Math.max(m, d.total_seconds), 1)) * 96) + 'px'
                  : '2px'
              }"
            ></div>
            <div class="absolute -top-8 left-1/2 -translate-x-1/2 bg-surface-900 text-white text-[10px] px-1.5 py-0.5 rounded opacity-0 group-hover:opacity-100 whitespace-nowrap pointer-events-none z-10">
              {{ day.date.slice(5) }}: {{ formatTime(day.total_seconds) }}
            </div>
          </div>
        </div>
      </div>

      <!-- Heatmap Matrix: Member x Client -->
      <div v-if="topMembers.length > 0 && topClients.length > 0 && Object.keys(matrix).length > 0"
        class="bg-white dark:bg-surface-800 rounded-2xl border border-surface-200 dark:border-surface-700 overflow-hidden mb-6"
      >
        <div class="p-4 border-b border-surface-200 dark:border-surface-700">
          <h2 class="text-lg font-semibold text-surface-900 dark:text-surface-100 flex items-center gap-2">
            <span class="material-symbols-rounded text-violet-500">grid_on</span>
            Utilisation Matrix
          </h2>
          <p class="text-xs text-surface-400 mt-1">Hours per member per client (top 10)</p>
        </div>
        <div class="p-4 overflow-x-auto">
          <table class="w-full text-xs">
            <thead>
              <tr>
                <th class="text-left py-2 px-2 text-surface-500 font-medium sticky left-0 bg-white dark:bg-surface-800 z-10">Member</th>
                <th
                  v-for="client in topClients"
                  :key="client.client_id"
                  class="text-center py-2 px-2 text-surface-500 font-medium max-w-[80px]"
                >
                  <span class="block truncate" :title="client.display_name || client.domain">
                    {{ (client.display_name || client.domain || '').substring(0, 10) }}
                  </span>
                </th>
                <th class="text-right py-2 px-2 text-surface-500 font-medium">Total</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="member in topMembers" :key="member.email" class="border-t border-surface-100 dark:border-surface-700">
                <td class="py-2 px-2 sticky left-0 bg-white dark:bg-surface-800 z-10">
                  <div class="flex items-center gap-2">
                    <div class="w-6 h-6 rounded-full bg-indigo-100 dark:bg-indigo-500/20 flex items-center justify-center flex-shrink-0">
                      <span class="text-[10px] font-bold text-indigo-600 dark:text-indigo-400">{{ memberInitials(member) }}</span>
                    </div>
                    <span class="truncate text-surface-800 dark:text-surface-200 font-medium max-w-[100px]" :title="member.name">
                      {{ member.name }}
                    </span>
                  </div>
                </td>
                <td
                  v-for="client in topClients"
                  :key="client.client_id"
                  class="text-center py-2 px-1"
                >
                  <div
                    v-if="matrix[member.email]?.[client.client_id]"
                    class="mx-auto w-full max-w-[60px] py-1 rounded text-[10px] font-bold text-violet-800 dark:text-violet-200"
                    :style="{ backgroundColor: `rgba(139, 92, 246, ${cellOpacity(matrix[member.email][client.client_id])})` }"
                  >
                    {{ formatHours(matrix[member.email][client.client_id]) }}h
                  </div>
                  <span v-else class="text-surface-300 dark:text-surface-600">-</span>
                </td>
                <td class="text-right py-2 px-2 font-bold text-surface-900 dark:text-surface-100">
                  {{ formatHours(member.total_seconds) }}h
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <!-- Leaderboard + Activity side by side -->
      <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <!-- Member leaderboard -->
        <div class="bg-white dark:bg-surface-800 rounded-2xl border border-surface-200 dark:border-surface-700 overflow-hidden">
          <div class="p-4 border-b border-surface-200 dark:border-surface-700">
            <h2 class="text-lg font-semibold text-surface-900 dark:text-surface-100 flex items-center gap-2">
              <span class="material-symbols-rounded text-amber-500">leaderboard</span>
              Leaderboard
            </h2>
          </div>
          <div class="p-4 max-h-96 overflow-y-auto">
            <div v-if="members.length === 0" class="text-center py-8 text-surface-400">
              <span class="material-symbols-rounded text-4xl">group_off</span>
              <p class="mt-2">No tracked time this period</p>
            </div>
            <div v-else class="space-y-2">
              <div
                v-for="(member, idx) in members"
                :key="member.email"
                class="flex items-center gap-3 p-2.5 rounded-xl"
                :class="idx < 3 ? 'bg-amber-50 dark:bg-amber-500/5' : ''"
              >
                <div class="w-7 h-7 rounded-full flex items-center justify-center flex-shrink-0 text-xs font-bold"
                  :class="idx === 0 ? 'bg-amber-400 text-white' : idx === 1 ? 'bg-gray-300 text-gray-700' : idx === 2 ? 'bg-amber-700 text-amber-100' : 'bg-surface-200 dark:bg-surface-700 text-surface-500'"
                >
                  {{ idx + 1 }}
                </div>
                <div class="flex-1 min-w-0">
                  <p class="text-sm font-medium text-surface-900 dark:text-surface-100 truncate">{{ member.name }}</p>
                  <p class="text-xs text-surface-400 truncate">{{ member.email }}</p>
                </div>
                <p class="text-sm font-bold text-surface-900 dark:text-surface-100">{{ formatTime(member.total_seconds) }}</p>
              </div>
            </div>
          </div>
        </div>

        <!-- Client load: hours, value, open tasks, deadlines -->
        <div class="bg-white dark:bg-surface-800 rounded-2xl border border-surface-200 dark:border-surface-700 overflow-hidden">
          <div class="p-4 border-b border-surface-200 dark:border-surface-700 flex items-center justify-between">
            <h2 class="text-lg font-semibold text-surface-900 dark:text-surface-100 flex items-center gap-2">
              <span class="material-symbols-rounded text-green-500">domain</span>
              Client Load
            </h2>
            <span v-if="totalValue > 0" class="text-sm font-bold text-green-600 dark:text-green-400">
              ≈ {{ formatCurrency(totalValue) }}
            </span>
          </div>
          <div class="p-4 max-h-96 overflow-y-auto">
            <div v-if="clients.length === 0" class="text-center py-8 text-surface-400">
              <span class="material-symbols-rounded text-4xl">hourglass_empty</span>
              <p class="mt-2">No client hours</p>
            </div>
            <div v-else class="space-y-3">
              <div v-for="client in clients" :key="client.client_id">
                <div class="flex items-center justify-between mb-1">
                  <button
                    @click="router.push(`/clients/${client.client_id}`)"
                    class="text-sm font-medium text-surface-900 dark:text-surface-100 truncate hover:text-primary-500 transition-colors"
                  >
                    {{ client.display_name || client.domain || `Client #${client.client_id}` }}
                  </button>
                  <span class="text-sm font-bold text-green-600 dark:text-green-400 ml-2 whitespace-nowrap">
                    {{ formatHours(client.total_seconds) }}h
                    <template v-if="client.value"> · {{ formatCurrency(client.value) }}</template>
                  </span>
                </div>
                <div class="h-2 bg-surface-100 dark:bg-surface-700 rounded-full overflow-hidden">
                  <div
                    class="h-full bg-green-500 rounded-full transition-all"
                    :style="{ width: `${(client.total_seconds / (clients[0]?.total_seconds || 1)) * 100}%` }"
                  ></div>
                </div>
                <div class="flex items-center gap-3 mt-1 text-[11px] text-surface-400">
                  <span v-if="client.open_tasks">{{ client.open_tasks }} open task{{ client.open_tasks === 1 ? '' : 's' }}</span>
                  <span v-if="client.overdue_tasks" class="text-red-500 font-medium">{{ client.overdue_tasks }} overdue</span>
                  <span v-if="client.next_deadline">next deadline: {{ formatDeadline(client.next_deadline) }}</span>
                  <span v-if="client.hourly_rate" class="ml-auto">@ {{ formatCurrency(client.hourly_rate) }}/hr</span>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </template>
  </div>
</template>
