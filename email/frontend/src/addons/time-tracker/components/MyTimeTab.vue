<script setup>
import { ref, computed, watch, onMounted, defineAsyncComponent } from 'vue'
import { useToastStore } from '@/stores/toast'
import api from '@/services/api'
import TimeCharts from '@/addons/time-tracker/components/TimeCharts.vue'
import TimeActivityList from '@/addons/time-tracker/components/TimeActivityList.vue'

const ClientTimeDrillDown = defineAsyncComponent(() => import('@/addons/time-tracker/components/ClientTimeDrillDown.vue'))

const props = defineProps({
  period: { type: String, default: 'week' },
  clientId: { type: [Number, String], default: null },
  clients: { type: Array, default: () => [] },
})

const emit = defineEmits(['select-client', 'clear-client'])

const toast = useToastStore()
const loading = ref(true)
const stats = ref(null)

const activityMeta = {
  email_read: { label: 'Reading Emails', icon: 'mail', color: '#3B82F6' },
  email_compose: { label: 'Writing Emails', icon: 'edit_note', color: '#6366F1' },
  calendar_event: { label: 'Calendar Events', icon: 'event', color: '#22C55E' },
  board_view: { label: 'Viewing Boards', icon: 'dashboard', color: '#A855F7' },
  board_task: { label: 'Working on Tasks', icon: 'task_alt', color: '#F59E0B' },
  drive_browse: { label: 'Browsing Files', icon: 'folder_open', color: '#14B8A6' },
  document_open: { label: 'Viewing Documents', icon: 'description', color: '#F97316' },
  document_edit: { label: 'Editing Documents', icon: 'edit_document', color: '#EF4444' },
  website_work: { label: 'Working on Websites', icon: 'language', color: '#06B6D4' },
  mood_board_view: { label: 'Viewing Mood Boards', icon: 'dashboard_customize', color: '#EC4899' },
  mood_board_edit: { label: 'Editing Mood Boards', icon: 'palette', color: '#DB2777' },
  client_call: { label: 'Client Calls', icon: 'videocam', color: '#10B981' },
  manual_entry: { label: 'Manual Entry', icon: 'edit_calendar', color: '#8B5CF6' },
}

const sectionMeta = {
  email: { label: 'Email', icon: 'mail', color: '#3B82F6' },
  calendar: { label: 'Calendar', icon: 'event', color: '#22C55E' },
  drive: { label: 'Drive', icon: 'cloud', color: '#14B8A6' },
  boards: { label: 'Projects / Boards', icon: 'dashboard', color: '#A855F7' },
  mood: { label: 'Mood Boards', icon: 'palette', color: '#EC4899' },
  todo: { label: 'Tasks', icon: 'task_alt', color: '#F59E0B' },
  time_tracker: { label: 'Time Tracker', icon: 'timer', color: '#6366F1' },
  clients: { label: 'Clients', icon: 'groups', color: '#10B981' },
  chat: { label: 'Chat', icon: 'chat', color: '#06B6D4' },
  team: { label: 'Team', icon: 'group', color: '#F97316' },
  crm: { label: 'CRM', icon: 'handshake', color: '#EF4444' },
  financials: { label: 'Financials', icon: 'payments', color: '#22D3EE' },
  automation: { label: 'Automation', icon: 'bolt', color: '#FBBF24' },
  settings: { label: 'Settings', icon: 'settings', color: '#9CA3AF' },
  other: { label: 'Other', icon: 'more_horiz', color: '#78716C' },
}

const totalTime = computed(() => stats.value?.total_seconds || 0)

const byClient = computed(() => {
  if (!stats.value?.by_client) return []
  return stats.value.by_client.sort((a, b) => b.total_seconds - a.total_seconds)
})

const byActivity = computed(() => {
  if (!stats.value?.by_activity) return []
  return Object.entries(stats.value.by_activity)
    .filter(([_, seconds]) => seconds > 0)
    .sort((a, b) => b[1] - a[1])
    .map(([type, seconds]) => ({
      type, seconds,
      ...activityMeta[type] || { label: type, icon: 'schedule', color: '#9CA3AF' }
    }))
})

const bySection = computed(() => {
  if (!stats.value?.section_time?.by_section) return []
  return stats.value.section_time.by_section
    .filter(s => s.total_seconds > 0)
    .sort((a, b) => b.total_seconds - a.total_seconds)
    .map(s => ({
      section: s.section, seconds: parseInt(s.total_seconds),
      ...sectionMeta[s.section] || { label: s.section, icon: 'schedule', color: '#9CA3AF' }
    }))
})

const selectedClient = computed(() => {
  if (!props.clientId) return null
  return props.clients.find(c => c.id === Number(props.clientId)) || null
})

const hasClientData = computed(() => byClient.value.length > 0 || byActivity.value.length > 0)
const hasSectionData = computed(() => bySection.value.length > 0)
const dailyBreakdown = computed(() => stats.value?.daily_breakdown || [])
const activityLog = computed(() => stats.value?.activity_log || [])

const periods = [
  { value: 'today', label: 'Today' },
  { value: 'week', label: 'This Week' },
  { value: 'month', label: 'This Month' },
  { value: 'year', label: 'This Year' },
  { value: 'all', label: 'All Time' },
]

async function loadStats() {
  loading.value = true
  try {
    const params = { period: props.period }
    if (props.clientId) params.client_id = props.clientId
    const response = await api.get('/time/my-stats', { params })
    if (response.data.success) stats.value = response.data.data
  } catch (error) {
    console.error('Failed to load time stats:', error)
    toast.error('Failed to load time statistics')
  } finally {
    loading.value = false
  }
}

function formatTime(seconds) {
  if (!seconds || seconds <= 0) return '0s'
  const h = Math.floor(seconds / 3600)
  const m = Math.floor((seconds % 3600) / 60)
  const s = seconds % 60
  if (h > 0) return `${h}h ${m}m ${s}s`
  if (m > 0) return `${m}m ${s}s`
  return `${s}s`
}

function formatTimeCompact(seconds) {
  if (!seconds || seconds <= 0) return '0'
  if (seconds < 60) return `${seconds}s`
  if (seconds < 3600) return `${Math.floor(seconds / 60)}m`
  const h = Math.floor(seconds / 3600)
  const m = Math.floor((seconds % 3600) / 60)
  return m > 0 ? `${h}h ${m}m` : `${h}h`
}

function refresh() { loadStats() }

watch(() => [props.period, props.clientId], () => loadStats())
onMounted(() => loadStats())

defineExpose({ refresh })
</script>

<template>
  <div>
    <ClientTimeDrillDown
      v-if="props.clientId && selectedClient"
      :client-id="Number(props.clientId)"
      :client-name="selectedClient.display_name || ''"
      :client-domain="selectedClient.domain || ''"
      :period="props.period"
      :clients="props.clients"
      @back="emit('clear-client')"
    />

    <template v-else>
      <div v-if="loading && !stats" class="flex items-center justify-center py-20">
        <div class="text-center">
          <span class="material-symbols-rounded text-4xl text-primary-500 animate-spin">progress_activity</span>
          <p class="mt-3 text-surface-500">Loading time data...</p>
        </div>
      </div>

      <template v-else>
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
          <div class="bg-gradient-to-br from-primary-500 to-primary-600 rounded-2xl p-5 text-white shadow-lg shadow-primary-500/20">
            <div class="flex items-center justify-between mb-3">
              <span class="text-primary-100 text-sm font-medium">Total Time</span>
              <div class="w-10 h-10 rounded-xl bg-white/20 flex items-center justify-center">
                <span class="material-symbols-rounded text-xl">schedule</span>
              </div>
            </div>
            <p class="text-3xl font-bold">{{ formatTime(totalTime) }}</p>
            <p class="text-sm text-primary-200 mt-1">{{ periods.find(p => p.value === props.period)?.label }}</p>
          </div>
          <div class="bg-white dark:bg-surface-800 rounded-2xl p-5 border border-surface-200 dark:border-surface-700">
            <div class="flex items-center justify-between mb-3">
              <span class="text-surface-500 text-sm font-medium">Clients</span>
              <div class="w-10 h-10 rounded-xl bg-green-100 dark:bg-green-500/20 flex items-center justify-center">
                <span class="material-symbols-rounded text-xl text-green-600 dark:text-green-400">groups</span>
              </div>
            </div>
            <p class="text-3xl font-bold text-surface-900 dark:text-surface-100">{{ byClient.length }}</p>
            <p class="text-sm text-surface-500 mt-1">Active this period</p>
          </div>
          <div class="bg-white dark:bg-surface-800 rounded-2xl p-5 border border-surface-200 dark:border-surface-700">
            <div class="flex items-center justify-between mb-3">
              <span class="text-surface-500 text-sm font-medium">{{ hasClientData ? 'Activities' : 'Sections' }}</span>
              <div class="w-10 h-10 rounded-xl bg-purple-100 dark:bg-purple-500/20 flex items-center justify-center">
                <span class="material-symbols-rounded text-xl text-purple-600 dark:text-purple-400">category</span>
              </div>
            </div>
            <p class="text-3xl font-bold text-surface-900 dark:text-surface-100">{{ hasClientData ? byActivity.length : bySection.length }}</p>
            <p class="text-sm text-surface-500 mt-1">{{ hasClientData ? 'Types tracked' : 'Areas used' }}</p>
          </div>
          <div class="bg-white dark:bg-surface-800 rounded-2xl p-5 border border-surface-200 dark:border-surface-700">
            <div class="flex items-center justify-between mb-3">
              <span class="text-surface-500 text-sm font-medium">{{ hasClientData ? 'Avg per Client' : 'Most Active' }}</span>
              <div class="w-10 h-10 rounded-xl bg-amber-100 dark:bg-amber-500/20 flex items-center justify-center">
                <span class="material-symbols-rounded text-xl text-amber-600 dark:text-amber-400">{{ hasClientData ? 'avg_pace' : 'local_fire_department' }}</span>
              </div>
            </div>
            <p class="text-3xl font-bold text-surface-900 dark:text-surface-100">
              <template v-if="hasClientData">{{ byClient.length > 0 ? formatTimeCompact(Math.round(totalTime / byClient.length)) : '0' }}</template>
              <template v-else>{{ bySection.length > 0 ? bySection[0].label : '-' }}</template>
            </p>
            <p class="text-sm text-surface-500 mt-1">{{ hasClientData ? 'Per client average' : (bySection.length > 0 ? formatTime(bySection[0].seconds) : 'No data yet') }}</p>
          </div>
        </div>

        <TimeCharts :by-client="byClient" :by-activity="byActivity" :daily-breakdown="dailyBreakdown" :period="props.period" class="mb-6" />

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
          <div v-if="hasSectionData" class="bg-white dark:bg-surface-800 rounded-2xl border border-surface-200 dark:border-surface-700 overflow-hidden">
            <div class="p-4 border-b border-surface-200 dark:border-surface-700">
              <h2 class="text-lg font-semibold text-surface-900 dark:text-surface-100 flex items-center gap-2">
                <span class="material-symbols-rounded text-primary-500">apps</span> Time by Section
              </h2>
            </div>
            <div class="p-4 max-h-96 overflow-y-auto space-y-3">
              <div v-for="sec in bySection" :key="sec.section">
                <div class="flex items-center justify-between mb-1">
                  <div class="flex items-center gap-2">
                    <div class="w-8 h-8 rounded-lg flex items-center justify-center flex-shrink-0" :style="{ backgroundColor: sec.color + '20' }">
                      <span class="material-symbols-rounded text-sm" :style="{ color: sec.color }">{{ sec.icon }}</span>
                    </div>
                    <span class="text-sm font-medium text-surface-900 dark:text-surface-100">{{ sec.label }}</span>
                  </div>
                  <span class="text-sm font-bold" :style="{ color: sec.color }">{{ formatTime(sec.seconds) }}</span>
                </div>
                <div class="h-2 bg-surface-100 dark:bg-surface-700 rounded-full overflow-hidden">
                  <div class="h-full rounded-full transition-all" :style="{ width: `${(sec.seconds / (bySection[0]?.seconds || 1)) * 100}%`, backgroundColor: sec.color }"></div>
                </div>
              </div>
            </div>
          </div>

          <div v-if="hasClientData" class="bg-white dark:bg-surface-800 rounded-2xl border border-surface-200 dark:border-surface-700 overflow-hidden">
            <div class="p-4 border-b border-surface-200 dark:border-surface-700">
              <h2 class="text-lg font-semibold text-surface-900 dark:text-surface-100 flex items-center gap-2">
                <span class="material-symbols-rounded text-primary-500">groups</span> Time by Client
              </h2>
            </div>
            <div class="p-4 max-h-96 overflow-y-auto">
              <div v-if="byClient.length === 0" class="text-center py-8">
                <span class="material-symbols-rounded text-4xl text-surface-300 dark:text-surface-600">hourglass_empty</span>
                <p class="mt-2 text-surface-500">No client time tracked</p>
              </div>
              <div v-else class="space-y-3">
                <div v-for="client in byClient" :key="client.client_id">
                  <div class="flex items-center justify-between mb-1">
                    <div class="flex items-center gap-2 min-w-0">
                      <div class="w-8 h-8 rounded-lg bg-primary-100 dark:bg-primary-500/20 flex items-center justify-center flex-shrink-0">
                        <span class="material-symbols-rounded text-primary-600 dark:text-primary-400 text-sm">domain</span>
                      </div>
                      <button @click="emit('select-client', client.client_id)" class="text-sm font-medium text-surface-900 dark:text-surface-100 truncate hover:text-primary-500 transition-colors">
                        {{ client.display_name || client.domain || `Client #${client.client_id}` }}
                      </button>
                    </div>
                    <span class="text-sm font-bold text-primary-600 dark:text-primary-400 ml-2">{{ formatTime(client.total_seconds) }}</span>
                  </div>
                  <div class="h-2 bg-surface-100 dark:bg-surface-700 rounded-full overflow-hidden">
                    <div class="h-full bg-primary-500 rounded-full transition-all" :style="{ width: `${(client.total_seconds / (byClient[0]?.total_seconds || 1)) * 100}%` }"></div>
                  </div>
                </div>
              </div>
            </div>
          </div>

          <div v-if="hasClientData" class="bg-white dark:bg-surface-800 rounded-2xl border border-surface-200 dark:border-surface-700 overflow-hidden">
            <div class="p-4 border-b border-surface-200 dark:border-surface-700">
              <h2 class="text-lg font-semibold text-surface-900 dark:text-surface-100 flex items-center gap-2">
                <span class="material-symbols-rounded text-purple-500">category</span> Time by Activity
              </h2>
            </div>
            <div class="p-4 max-h-96 overflow-y-auto">
              <div v-if="byActivity.length === 0" class="text-center py-8">
                <span class="material-symbols-rounded text-4xl text-surface-300 dark:text-surface-600">hourglass_empty</span>
                <p class="mt-2 text-surface-500">No activities tracked</p>
              </div>
              <div v-else class="space-y-3">
                <div v-for="activity in byActivity" :key="activity.type">
                  <div class="flex items-center justify-between mb-1">
                    <div class="flex items-center gap-2">
                      <div class="w-8 h-8 rounded-lg flex items-center justify-center flex-shrink-0" :style="{ backgroundColor: activity.color + '20' }">
                        <span class="material-symbols-rounded text-sm" :style="{ color: activity.color }">{{ activity.icon }}</span>
                      </div>
                      <span class="text-sm font-medium text-surface-900 dark:text-surface-100">{{ activity.label }}</span>
                    </div>
                    <span class="text-sm font-bold" :style="{ color: activity.color }">{{ formatTime(activity.seconds) }}</span>
                  </div>
                  <div class="h-2 bg-surface-100 dark:bg-surface-700 rounded-full overflow-hidden">
                    <div class="h-full rounded-full transition-all" :style="{ width: `${(activity.seconds / (byActivity[0]?.seconds || 1)) * 100}%`, backgroundColor: activity.color }"></div>
                  </div>
                </div>
              </div>
            </div>
          </div>

          <div v-if="!hasSectionData && !hasClientData" class="lg:col-span-2 bg-white dark:bg-surface-800 rounded-2xl border border-surface-200 dark:border-surface-700 p-12 text-center">
            <span class="material-symbols-rounded text-5xl text-surface-300 dark:text-surface-600">hourglass_empty</span>
            <p class="mt-3 text-lg font-medium text-surface-700 dark:text-surface-300">No time tracked this period</p>
            <p class="mt-1 text-sm text-surface-500">Time is tracked automatically as you use the app.</p>
          </div>
        </div>

        <div class="mt-6 bg-white dark:bg-surface-800 rounded-2xl border border-surface-200 dark:border-surface-700 overflow-hidden">
          <div class="p-4 border-b border-surface-200 dark:border-surface-700">
            <h2 class="text-lg font-semibold text-surface-900 dark:text-surface-100 flex items-center gap-2">
              <span class="material-symbols-rounded text-teal-500">history</span> Recent Activity
            </h2>
          </div>
          <div class="p-4">
            <TimeActivityList :activities="activityLog" :show-user="false" :max-visible="10" />
          </div>
        </div>
      </template>
    </template>
  </div>
</template>
