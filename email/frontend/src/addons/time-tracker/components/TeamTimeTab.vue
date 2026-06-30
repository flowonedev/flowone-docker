<script setup>
/**
 * TeamTimeTab - Shows individual colleague drill-down.
 * Activated when CRM Pro is enabled alongside Time Tracker.
 * Displays per-member time, their client breakdown, and activity split.
 */
import { ref, computed, watch, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import api from '@/services/api'
import { useToastStore } from '@/stores/toast'
import TimeCharts from './TimeCharts.vue'

const router = useRouter()
const toast = useToastStore()

const props = defineProps({
  period: { type: String, default: 'week' },
  clientId: { type: [Number, null], default: null },
})

const loading = ref(true)
const data = ref(null)
const selectedMember = ref(null)

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
}

const members = computed(() => data.value?.by_member || [])
const byClient = computed(() => data.value?.by_client || [])
const byActivity = computed(() => {
  if (!data.value?.by_activity) return []
  return Object.entries(data.value.by_activity)
    .filter(([_, s]) => s > 0)
    .sort((a, b) => b[1] - a[1])
    .map(([type, seconds]) => ({
      type, seconds,
      ...activityMeta[type] || { label: type, icon: 'schedule', color: '#9CA3AF' },
    }))
})
const dailyBreakdown = computed(() => data.value?.daily_breakdown || [])
const totalSeconds = computed(() => data.value?.total_seconds || 0)

async function loadData() {
  loading.value = true
  try {
    const params = { period: props.period }
    if (props.clientId) params.client_id = props.clientId
    if (selectedMember.value) params.member = selectedMember.value
    const res = await api.get('/time/team-stats', { params })
    if (res.data?.success) data.value = res.data.data
  } catch {
    toast.error('Failed to load team time data')
  } finally {
    loading.value = false
  }
}

function selectMember(email) {
  selectedMember.value = selectedMember.value === email ? null : email
  loadData()
}

function formatTime(seconds) {
  if (!seconds || seconds <= 0) return '0s'
  const h = Math.floor(seconds / 3600)
  const m = Math.floor((seconds % 3600) / 60)
  const s = seconds % 60
  if (h > 0) return `${h}h ${m}m`
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

watch(() => [props.period, props.clientId], loadData)
onMounted(loadData)

defineExpose({ refresh: loadData })
</script>

<template>
  <div>
    <!-- KPI Row -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
      <div class="bg-gradient-to-br from-indigo-500 to-purple-600 rounded-2xl p-5 text-white shadow-lg shadow-indigo-500/20">
        <div class="flex items-center justify-between mb-3">
          <span class="text-indigo-100 text-sm font-medium">Team Total</span>
          <div class="w-10 h-10 rounded-xl bg-white/20 flex items-center justify-center">
            <span class="material-symbols-rounded text-xl">groups</span>
          </div>
        </div>
        <p class="text-3xl font-bold">{{ formatTime(totalSeconds) }}</p>
        <p class="text-sm text-indigo-200 mt-1">{{ selectedMember ? 'Filtered member' : 'All members' }}</p>
      </div>

      <div class="bg-white dark:bg-surface-800 rounded-2xl p-5 border border-surface-200 dark:border-surface-700">
        <div class="flex items-center justify-between mb-3">
          <span class="text-surface-500 text-sm font-medium">Members</span>
          <div class="w-10 h-10 rounded-xl bg-blue-100 dark:bg-blue-500/20 flex items-center justify-center">
            <span class="material-symbols-rounded text-xl text-blue-600 dark:text-blue-400">person</span>
          </div>
        </div>
        <p class="text-3xl font-bold text-surface-900 dark:text-surface-100">{{ data?.member_count || 0 }}</p>
        <p class="text-sm text-surface-500 mt-1">Active this period</p>
      </div>

      <div class="bg-white dark:bg-surface-800 rounded-2xl p-5 border border-surface-200 dark:border-surface-700">
        <div class="flex items-center justify-between mb-3">
          <span class="text-surface-500 text-sm font-medium">Clients</span>
          <div class="w-10 h-10 rounded-xl bg-green-100 dark:bg-green-500/20 flex items-center justify-center">
            <span class="material-symbols-rounded text-xl text-green-600 dark:text-green-400">domain</span>
          </div>
        </div>
        <p class="text-3xl font-bold text-surface-900 dark:text-surface-100">{{ data?.client_count || 0 }}</p>
        <p class="text-sm text-surface-500 mt-1">With tracked time</p>
      </div>

      <div class="bg-white dark:bg-surface-800 rounded-2xl p-5 border border-surface-200 dark:border-surface-700">
        <div class="flex items-center justify-between mb-3">
          <span class="text-surface-500 text-sm font-medium">Avg / Member</span>
          <div class="w-10 h-10 rounded-xl bg-amber-100 dark:bg-amber-500/20 flex items-center justify-center">
            <span class="material-symbols-rounded text-xl text-amber-600 dark:text-amber-400">avg_pace</span>
          </div>
        </div>
        <p class="text-3xl font-bold text-surface-900 dark:text-surface-100">
          {{ members.length > 0 ? formatTimeCompact(Math.round(totalSeconds / members.length)) : '0' }}
        </p>
        <p class="text-sm text-surface-500 mt-1">Per member average</p>
      </div>
    </div>

    <!-- Loading -->
    <div v-if="loading && !data" class="flex items-center justify-center py-16">
      <span class="material-symbols-rounded text-4xl text-primary-500 animate-spin">progress_activity</span>
    </div>

    <template v-else>
      <!-- Selected member chip -->
      <div v-if="selectedMember" class="flex items-center gap-2 mb-4">
        <span class="text-sm text-surface-500">Showing:</span>
        <button
          @click="selectMember(null)"
          class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full bg-indigo-100 dark:bg-indigo-500/20 text-indigo-700 dark:text-indigo-300 text-sm font-medium"
        >
          <span class="material-symbols-rounded text-sm">person</span>
          {{ selectedMember }}
          <span class="material-symbols-rounded text-sm">close</span>
        </button>
      </div>

      <!-- Charts -->
      <TimeCharts
        :by-client="byClient"
        :by-activity="byActivity"
        :daily-breakdown="dailyBreakdown"
        :period="period"
        class="mb-6"
      />

      <!-- Members + Clients side by side -->
      <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <!-- Members list -->
        <div class="bg-white dark:bg-surface-800 rounded-2xl border border-surface-200 dark:border-surface-700 overflow-hidden">
          <div class="p-4 border-b border-surface-200 dark:border-surface-700">
            <h2 class="text-lg font-semibold text-surface-900 dark:text-surface-100 flex items-center gap-2">
              <span class="material-symbols-rounded text-indigo-500">group</span>
              Team Members
            </h2>
          </div>
          <div class="p-4 max-h-96 overflow-y-auto">
            <div v-if="members.length === 0" class="text-center py-8 text-surface-400">
              <span class="material-symbols-rounded text-4xl">group_off</span>
              <p class="mt-2">No team activity this period</p>
            </div>
            <div v-else class="space-y-2">
              <button
                v-for="member in members"
                :key="member.email"
                @click="selectMember(member.email)"
                class="w-full flex items-center gap-3 p-3 rounded-xl transition-all text-left"
                :class="selectedMember === member.email
                  ? 'bg-indigo-50 dark:bg-indigo-500/10 ring-1 ring-indigo-300 dark:ring-indigo-500/30'
                  : 'hover:bg-surface-50 dark:hover:bg-surface-700/50'"
              >
                <div class="w-9 h-9 rounded-full bg-indigo-100 dark:bg-indigo-500/20 flex items-center justify-center flex-shrink-0">
                  <span class="text-sm font-bold text-indigo-600 dark:text-indigo-400">
                    {{ (member.name || member.email).substring(0, 2).toUpperCase() }}
                  </span>
                </div>
                <div class="flex-1 min-w-0">
                  <p class="text-sm font-medium text-surface-900 dark:text-surface-100 truncate">{{ member.name }}</p>
                  <p class="text-xs text-surface-400 truncate">{{ member.email }}</p>
                </div>
                <div class="text-right flex-shrink-0">
                  <p class="text-sm font-bold text-indigo-600 dark:text-indigo-400">{{ formatTime(member.total_seconds) }}</p>
                  <p class="text-xs text-surface-400">
                    {{ totalSeconds > 0 ? Math.round((member.total_seconds / totalSeconds) * 100) : 0 }}%
                  </p>
                </div>
              </button>
            </div>
          </div>
        </div>

        <!-- Clients breakdown -->
        <div class="bg-white dark:bg-surface-800 rounded-2xl border border-surface-200 dark:border-surface-700 overflow-hidden">
          <div class="p-4 border-b border-surface-200 dark:border-surface-700">
            <h2 class="text-lg font-semibold text-surface-900 dark:text-surface-100 flex items-center gap-2">
              <span class="material-symbols-rounded text-green-500">domain</span>
              Time by Client
            </h2>
          </div>
          <div class="p-4 max-h-96 overflow-y-auto">
            <div v-if="byClient.length === 0" class="text-center py-8 text-surface-400">
              <span class="material-symbols-rounded text-4xl">hourglass_empty</span>
              <p class="mt-2">No client time this period</p>
            </div>
            <div v-else class="space-y-3">
              <div v-for="client in byClient" :key="client.client_id" class="group">
                <div class="flex items-center justify-between mb-1">
                  <div class="flex items-center gap-2 min-w-0">
                    <div class="w-8 h-8 rounded-lg bg-green-100 dark:bg-green-500/20 flex items-center justify-center flex-shrink-0">
                      <span class="material-symbols-rounded text-green-600 dark:text-green-400 text-sm">domain</span>
                    </div>
                    <button
                      @click="router.push(`/clients/${client.client_id}`)"
                      class="text-sm font-medium text-surface-900 dark:text-surface-100 truncate hover:text-primary-500 transition-colors"
                    >
                      {{ client.display_name || client.domain || `Client #${client.client_id}` }}
                    </button>
                  </div>
                  <span class="text-sm font-bold text-green-600 dark:text-green-400 ml-2">
                    {{ formatTime(client.total_seconds) }}
                  </span>
                </div>
                <div class="h-2 bg-surface-100 dark:bg-surface-700 rounded-full overflow-hidden">
                  <div
                    class="h-full bg-green-500 rounded-full transition-all"
                    :style="{ width: `${(client.total_seconds / (byClient[0]?.total_seconds || 1)) * 100}%` }"
                  ></div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Activity breakdown -->
      <div class="mt-6 bg-white dark:bg-surface-800 rounded-2xl border border-surface-200 dark:border-surface-700 overflow-hidden">
        <div class="p-4 border-b border-surface-200 dark:border-surface-700">
          <h2 class="text-lg font-semibold text-surface-900 dark:text-surface-100 flex items-center gap-2">
            <span class="material-symbols-rounded text-purple-500">category</span>
            Activity Breakdown
          </h2>
        </div>
        <div class="p-4 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
          <div
            v-for="activity in byActivity"
            :key="activity.type"
            class="flex items-center gap-3 p-3 rounded-xl bg-surface-50 dark:bg-surface-700/30"
          >
            <div
              class="w-10 h-10 rounded-xl flex items-center justify-center flex-shrink-0"
              :style="{ backgroundColor: activity.color + '20' }"
            >
              <span class="material-symbols-rounded" :style="{ color: activity.color }">{{ activity.icon }}</span>
            </div>
            <div class="min-w-0">
              <p class="text-sm font-medium text-surface-900 dark:text-surface-100 truncate">{{ activity.label }}</p>
              <p class="text-sm font-bold" :style="{ color: activity.color }">{{ formatTime(activity.seconds) }}</p>
            </div>
          </div>
        </div>
      </div>
    </template>
  </div>
</template>
