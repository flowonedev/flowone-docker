<script setup>
import { ref, computed, watch, onMounted, defineAsyncComponent } from 'vue'
import { useAddons } from '@/composables/useAddons'
import api from '@/services/api'

const ClientTrackedWebsites = defineAsyncComponent(() => import('@/components/clients/ClientTrackedWebsites.vue'))
const ManualClientTimeDialog = defineAsyncComponent(() => import('./ManualClientTimeDialog.vue'))

const props = defineProps({
  clientId: { type: Number, required: true },
  clientName: { type: String, default: '' },
  clientDomain: { type: String, default: '' },
  period: { type: String, default: 'week' },
  clients: { type: Array, default: () => [] },
})

const emit = defineEmits(['back'])

const { kanbanBoardsEnabled } = useAddons()

const loading = ref(false)
const timeStats = ref(null)
const activityLog = ref([])
const linkedBoards = ref([])
const expandedActivities = ref({})
const showManualDialog = ref(false)

const activityMeta = {
  document_edit: { label: 'Editing Files', icon: 'edit_document', color: '#EF4444', priority: 1 },
  calendar_event: { label: 'Calendar Events', icon: 'event', color: '#22C55E', priority: 2 },
  email_compose: { label: 'Writing Emails', icon: 'edit_note', color: '#6366F1', priority: 3 },
  email_read: { label: 'Reading Emails', icon: 'mail', color: '#3B82F6', priority: 4 },
  document_open: { label: 'Viewing Files', icon: 'description', color: '#F97316', priority: 5 },
  board_view: { label: 'Boards', icon: 'dashboard', color: '#A855F7', priority: 6 },
  board_task: { label: 'Board Tasks', icon: 'task_alt', color: '#F59E0B', priority: 7 },
  drive_browse: { label: 'Browsing Folders', icon: 'folder_open', color: '#14B8A6', priority: 8 },
  website_work: { label: 'Website Work', icon: 'language', color: '#06B6D4', priority: 3 },
  mood_board_view: { label: 'Mood Boards', icon: 'dashboard_customize', color: '#EC4899', priority: 5 },
  mood_board_edit: { label: 'Editing Mood Boards', icon: 'palette', color: '#DB2777', priority: 2 },
  client_call: { label: 'Client Calls', icon: 'videocam', color: '#10B981', priority: 1 },
  manual_entry: { label: 'Manual Entries', icon: 'edit_calendar', color: '#8B5CF6', priority: 0 },
}

const excludedFolderNames = ['Boards', 'boards', 'Projects', 'projects', 'Clients', 'clients']

const teamTime = computed(() => timeStats.value?.team_time || { total_seconds: 0, by_activity: {} })
const totalSeconds = computed(() => timeStats.value?.cumulative?.team_total || teamTime.value.total_seconds || 0)

const activitiesByType = computed(() => {
  const grouped = {}
  for (const entry of activityLog.value) {
    const type = entry.activity_type
    if (type === 'drive_browse' && excludedFolderNames.includes(entry.entity_name)) continue
    if (!grouped[type]) grouped[type] = []
    grouped[type].push(entry)
  }
  return grouped
})

const sortedActivities = computed(() => {
  const activities = teamTime.value.by_activity || {}
  return Object.entries(activities)
    .filter(([_, seconds]) => seconds > 0)
    .sort((a, b) => {
      const pa = activityMeta[a[0]]?.priority ?? 99
      const pb = activityMeta[b[0]]?.priority ?? 99
      if (pa !== pb) return pa - pb
      return b[1] - a[1]
    })
    .map(([type, seconds]) => ({
      type,
      seconds,
      ...(activityMeta[type] || { label: type, icon: 'schedule', color: '#9CA3AF', priority: 99 }),
      entries: activitiesByType.value[type] || [],
    }))
})

const topSeconds = computed(() => sortedActivities.value[0]?.seconds || 1)

function toggleActivity(type) {
  expandedActivities.value[type] = !expandedActivities.value[type]
}

async function loadStats() {
  loading.value = true
  try {
    const statsRes = await api.get(`/clients/${props.clientId}/time-stats`, { params: { period: props.period } })
    if (statsRes.data.success) {
      timeStats.value = statsRes.data.data
      activityLog.value = statsRes.data.data?.activity_log || []
    }
  } catch (err) {
    console.error('[ClientTimeDrillDown] load failed:', err)
  } finally {
    loading.value = false
  }
}

async function loadLinkedBoards() {
  try {
    const res = await api.get('/boards/url-mappings')
    if (res.data.success) {
      const allMappings = res.data.data?.mappings || []
      const clientMappings = allMappings.filter(m => m.client_id === props.clientId)
      const boardIds = [...new Set(clientMappings.map(m => m.board_id))]
      const boards = boardIds.map(id => {
        const mapping = clientMappings.find(m => m.board_id === id)
        return { board_id: id, board_name: mapping?.board_name || null }
      })
      const unresolved = boards.filter(b => !b.board_name)
      if (unresolved.length) {
        try {
          const boardsRes = await api.get('/boards')
          const allBoards = boardsRes.data?.data || boardsRes.data || []
          for (const b of unresolved) {
            const found = allBoards.find(ab => ab.id === b.board_id)
            if (found) b.board_name = found.name
          }
        } catch { /* ignore */ }
      }
      linkedBoards.value = boards.map(b => ({
        board_id: b.board_id,
        board_name: b.board_name || `Board #${b.board_id}`,
      }))
    }
  } catch { /* ignore */ }
}

function handleManualSaved() {
  loadStats()
}

function formatTime(seconds) {
  if (!seconds || seconds <= 0) return '0s'
  const h = Math.floor(seconds / 3600)
  const m = Math.floor((seconds % 3600) / 60)
  const s = seconds % 60
  if (h > 0) return s > 0 ? `${h}h ${m}m ${s}s` : `${h}h ${m}m`
  if (m > 0) return s > 0 ? `${m}m ${s}s` : `${m}m`
  return `${s}s`
}

function formatDate(dateStr) {
  if (!dateStr) return ''
  return new Date(dateStr).toLocaleDateString(undefined, { month: 'short', day: 'numeric' })
}

watch(() => props.clientId, () => {
  loadStats()
  if (kanbanBoardsEnabled.value) loadLinkedBoards()
}, { immediate: true })

watch(() => props.period, () => {
  loadStats()
})
</script>

<template>
  <div>
    <!-- Header -->
    <div class="flex items-center gap-4 mb-6">
      <button
        @click="emit('back')"
        class="p-2 rounded-xl hover:bg-surface-100 dark:hover:bg-surface-700 transition-colors"
      >
        <span class="material-symbols-rounded text-xl text-surface-500">arrow_back</span>
      </button>
      <div class="flex-1 min-w-0">
        <h2 class="text-xl font-bold text-surface-900 dark:text-surface-100 truncate">
          {{ clientName || clientDomain || `Client #${clientId}` }}
        </h2>
        <p v-if="clientDomain && clientName" class="text-sm text-surface-500 truncate">{{ clientDomain }}</p>
      </div>
      <div class="text-right mr-2">
        <p class="text-2xl font-bold text-primary-600 dark:text-primary-400">{{ formatTime(totalSeconds) }}</p>
        <p class="text-xs text-surface-500">Total tracked</p>
      </div>
      <button
        @click="showManualDialog = true"
        class="flex items-center gap-1.5 px-4 py-2 rounded-full bg-primary-500 text-white text-sm font-medium hover:bg-primary-600 transition-colors shadow-sm shrink-0"
      >
        <span class="material-symbols-rounded text-lg">more_time</span>
        Log Time
      </button>
    </div>

    <!-- Loading -->
    <div v-if="loading && !timeStats" class="flex items-center justify-center py-16">
      <div class="text-center">
        <span class="material-symbols-rounded text-4xl text-primary-500 animate-spin">progress_activity</span>
        <p class="mt-3 text-surface-500">Loading time data...</p>
      </div>
    </div>

    <template v-else>
      <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- Activity breakdown (2/3 width) -->
        <div class="lg:col-span-2 space-y-4">
          <div class="bg-white dark:bg-surface-800 rounded-2xl border border-surface-200 dark:border-surface-700 overflow-hidden">
            <div class="p-4 border-b border-surface-200 dark:border-surface-700">
              <h3 class="text-base font-semibold text-surface-900 dark:text-surface-100 flex items-center gap-2">
                <span class="material-symbols-rounded text-primary-500">category</span>
                Activity Breakdown
              </h3>
            </div>

            <div v-if="sortedActivities.length === 0" class="p-8 text-center">
              <span class="material-symbols-rounded text-4xl text-surface-300">hourglass_empty</span>
              <p class="mt-2 text-surface-500">No activity tracked this period</p>
            </div>

            <div v-else class="p-4 space-y-3">
              <div v-for="activity in sortedActivities" :key="activity.type">
                <button
                  class="w-full text-left"
                  @click="toggleActivity(activity.type)"
                >
                  <div class="flex items-center justify-between mb-1">
                    <div class="flex items-center gap-2">
                      <div
                        class="w-8 h-8 rounded-lg flex items-center justify-center shrink-0"
                        :style="{ backgroundColor: activity.color + '20' }"
                      >
                        <span class="material-symbols-rounded text-sm" :style="{ color: activity.color }">{{ activity.icon }}</span>
                      </div>
                      <span class="text-sm font-medium text-surface-900 dark:text-surface-100">{{ activity.label }}</span>
                      <span v-if="activity.entries.length" class="text-xs text-surface-400">({{ activity.entries.length }})</span>
                    </div>
                    <div class="flex items-center gap-2">
                      <span class="text-sm font-bold" :style="{ color: activity.color }">{{ formatTime(activity.seconds) }}</span>
                      <span
                        v-if="activity.entries.length"
                        class="material-symbols-rounded text-sm text-surface-400 transition-transform"
                        :class="expandedActivities[activity.type] ? 'rotate-180' : ''"
                      >expand_more</span>
                    </div>
                  </div>
                  <div class="h-2 bg-surface-100 dark:bg-surface-700 rounded-full overflow-hidden">
                    <div
                      class="h-full rounded-full transition-all"
                      :style="{ width: `${(activity.seconds / topSeconds) * 100}%`, backgroundColor: activity.color }"
                    ></div>
                  </div>
                </button>

                <!-- Expanded entries -->
                <div
                  v-if="expandedActivities[activity.type] && activity.entries.length"
                  class="mt-2 ml-10 space-y-1 max-h-48 overflow-y-auto"
                >
                  <div
                    v-for="(entry, idx) in activity.entries"
                    :key="idx"
                    class="flex items-center justify-between px-3 py-1.5 rounded-lg bg-surface-50 dark:bg-surface-700/50 text-xs"
                  >
                    <span class="text-surface-700 dark:text-surface-300 truncate flex-1 mr-2">
                      {{ entry.entity_name || entry.entity_id || 'Unknown' }}
                    </span>
                    <div class="flex items-center gap-3 shrink-0">
                      <span v-if="entry.source === 'local_watch'" class="px-1.5 py-0.5 rounded bg-amber-500/15 text-amber-500 text-[10px]">local</span>
                      <span class="font-semibold" :style="{ color: activity.color }">{{ formatTime(entry.duration_seconds) }}</span>
                      <span class="text-surface-400">{{ formatDate(entry.tracked_date) }}</span>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Sidebar (1/3 width) -->
        <div class="space-y-4">
          <!-- Tracked Websites -->
          <ClientTrackedWebsites
            v-if="kanbanBoardsEnabled"
            :client-id="clientId"
            :linked-boards="linkedBoards"
          />

          <!-- Quick stats -->
          <div class="bg-white dark:bg-surface-800 rounded-2xl border border-surface-200 dark:border-surface-700 p-4">
            <h4 class="text-sm font-semibold text-surface-900 dark:text-surface-100 flex items-center gap-2 mb-3">
              <span class="material-symbols-rounded text-lg text-amber-500">insights</span>
              Summary
            </h4>
            <div class="space-y-2">
              <div class="flex items-center justify-between text-sm">
                <span class="text-surface-500">Activities</span>
                <span class="font-medium text-surface-900 dark:text-surface-100">{{ sortedActivities.length }}</span>
              </div>
              <div class="flex items-center justify-between text-sm">
                <span class="text-surface-500">Log entries</span>
                <span class="font-medium text-surface-900 dark:text-surface-100">{{ activityLog.length }}</span>
              </div>
              <div v-if="sortedActivities.length" class="flex items-center justify-between text-sm">
                <span class="text-surface-500">Most time</span>
                <span class="font-medium text-surface-900 dark:text-surface-100">{{ sortedActivities[0]?.label }}</span>
              </div>
            </div>
          </div>
        </div>
      </div>
    </template>

    <!-- Manual time dialog -->
    <ManualClientTimeDialog
      v-if="showManualDialog"
      :client-id="clientId"
      :clients="clients"
      @close="showManualDialog = false"
      @saved="handleManualSaved"
    />
  </div>
</template>
