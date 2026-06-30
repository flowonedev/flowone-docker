<script setup>
import { ref, computed, onMounted, watch } from 'vue'
import { useProjectHubStore } from '../stores/projectHub'
import { useCardTimer } from '../composables/useCardTimer'

const props = defineProps({
  cardId: { type: [Number, String], required: true },
})

const hubStore = useProjectHubStore()
const { isRunning: timerRunning } = useCardTimer()
const loading = ref(false)
const sessions = ref([])

const grandTotal = computed(() =>
  sessions.value.reduce((sum, s) => sum + (s.duration_seconds || 0), 0)
)

const userSections = computed(() => {
  const userMap = {}
  for (const s of sessions.value) {
    const uk = s.user_email
    if (!userMap[uk]) userMap[uk] = { email: uk, total: 0, sessions: 0, files: {} }
    const user = userMap[uk]
    user.total += s.duration_seconds || 0
    user.sessions++

    const fk = s.entity_name || sourceLabels[s.source] || s.source || 'General'
    const fileKey = `${fk}::${s.source}`
    if (!user.files[fileKey]) {
      user.files[fileKey] = {
        label: fk,
        total: 0,
        sessions: 0,
        source: s.source,
        entityName: s.entity_name,
      }
    }
    user.files[fileKey].total += s.duration_seconds || 0
    user.files[fileKey].sessions++
  }

  const byTotal = (a, b) => b.total - a.total
  return Object.values(userMap)
    .sort(byTotal)
    .map(u => ({
      ...u,
      files: Object.values(u.files).sort(byTotal),
    }))
})

const sourceLabels = {
  manual: 'Manual Entry',
  drive_edit: 'Drive Edit',
  board_view: 'Board Viewing',
  timer: 'Timer',
  card_view: 'Card View',
  website_work: 'Website Work',
  portal_call: 'Portal Call',
  calendar_event: 'Calendar Event',
  local_watch: 'Local Watch',
}

const sourceIconMap = {
  manual: 'edit',
  drive_edit: 'description',
  board_view: 'view_kanban',
  timer: 'timer',
  card_view: 'visibility',
  website_work: 'language',
  portal_call: 'video_call',
  calendar_event: 'event',
  local_watch: 'folder_open',
}

function fileIcon(file) {
  if (file.entityName) return 'description'
  return sourceIconMap[file.source] || 'schedule'
}

function fileIconColor(file) {
  if (file.entityName) return 'text-teal-500'
  const map = {
    timer: 'text-orange-400',
    card_view: 'text-emerald-500',
    board_view: 'text-indigo-400',
    manual: 'text-blue-400',
    drive_edit: 'text-teal-500',
    website_work: 'text-cyan-500',
    portal_call: 'text-green-500',
    calendar_event: 'text-blue-500',
    local_watch: 'text-amber-500',
  }
  return map[file.source] || 'text-surface-400'
}

function fmt(seconds) {
  if (!seconds || seconds <= 0) return '0m'
  const h = Math.floor(seconds / 3600)
  const m = Math.round((seconds % 3600) / 60)
  if (h === 0) return `${m}m`
  if (m === 0) return `${h}h`
  return `${h}h ${m}m`
}

async function loadSessions() {
  loading.value = true
  try {
    const result = await hubStore.fetchWorkSessions(props.cardId)
    sessions.value = result || []
  } finally {
    loading.value = false
  }
}

onMounted(loadSessions)
watch(() => props.cardId, loadSessions)
watch(timerRunning, (running) => {
  if (!running) setTimeout(loadSessions, 1500)
})
</script>

<template>
  <div class="space-y-4">
    <!-- Grand total -->
    <div class="flex items-center justify-between px-4 py-3 rounded-xl bg-white dark:bg-surface-800 border border-surface-200 dark:border-surface-700">
      <span class="text-sm font-semibold text-surface-700 dark:text-surface-200 flex items-center gap-2">
        <span class="material-symbols-rounded text-lg">timer</span>
        Total Tracked
      </span>
      <span class="text-lg font-bold text-primary-600 dark:text-primary-400">{{ fmt(grandTotal) }}</span>
    </div>

    <!-- Loading -->
    <div v-if="loading" class="flex items-center justify-center py-12">
      <span class="material-symbols-rounded text-3xl text-surface-400 animate-spin">progress_activity</span>
    </div>

    <!-- Empty -->
    <div v-else-if="userSections.length === 0" class="text-center py-12">
      <span class="material-symbols-rounded text-4xl text-surface-300 dark:text-surface-600 mb-2 block">hourglass_empty</span>
      <p class="text-sm text-surface-500">No work sessions recorded yet.</p>
    </div>

    <!-- Per-user tables -->
    <div v-else class="space-y-3">
      <div v-for="user in userSections" :key="user.email" class="rounded-xl border border-surface-200 dark:border-surface-700 bg-white dark:bg-surface-800 overflow-hidden">

        <!-- User header -->
        <div class="px-4 py-2.5 bg-surface-50 dark:bg-surface-800/80 border-b border-surface-200 dark:border-surface-700 flex items-center gap-2">
          <span class="material-symbols-rounded text-base text-primary-500">person</span>
          <span class="text-xs font-semibold text-surface-700 dark:text-surface-200 flex-1 truncate">{{ user.email }}</span>
          <span class="text-xs text-surface-400 tabular-nums">{{ user.sessions }} session{{ user.sessions !== 1 ? 's' : '' }}</span>
          <span class="text-sm font-bold text-primary-600 dark:text-primary-400 tabular-nums">{{ fmt(user.total) }}</span>
        </div>

        <!-- Activity table -->
        <table class="w-full text-left">
          <thead>
            <tr class="border-b border-surface-100 dark:border-surface-700/50 text-[11px] uppercase tracking-wider text-surface-400">
              <th class="pl-4 pr-2 py-1.5 font-semibold">Activity</th>
              <th class="px-2 py-1.5 font-semibold text-right w-16">Sessions</th>
              <th class="px-2 pr-4 py-1.5 font-semibold text-right w-20">Time</th>
            </tr>
          </thead>
          <tbody>
            <tr
              v-for="(file, fi) in user.files" :key="fi"
              class="border-b border-surface-50 dark:border-surface-700/30 hover:bg-surface-50 dark:hover:bg-surface-750 transition-colors"
            >
              <td class="pl-4 pr-2 py-2">
                <div class="flex items-center gap-2">
                  <span class="material-symbols-rounded text-sm shrink-0" :class="fileIconColor(file)">{{ fileIcon(file) }}</span>
                  <span class="text-sm text-surface-600 dark:text-surface-300 truncate">{{ file.label }}</span>
                </div>
              </td>
              <td class="px-2 py-2 text-xs text-surface-400 tabular-nums text-right">{{ file.sessions }}</td>
              <td class="px-2 pr-4 py-2 text-sm font-medium text-surface-700 dark:text-surface-200 tabular-nums text-right">{{ fmt(file.total) }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</template>
