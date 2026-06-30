<template>
  <div class="space-y-3">
    <div class="flex items-center justify-between">
      <h4 class="text-sm font-semibold text-surface-700 dark:text-surface-200 flex items-center gap-2">
        <span class="material-symbols-rounded text-lg">timer</span>
        Work Sessions
      </h4>
      <span class="text-xs text-surface-400">{{ totalFormatted }} total</span>
    </div>

    <div v-if="loading" class="flex items-center justify-center py-4">
      <div class="w-5 h-5 border-2 border-primary-400 border-t-transparent rounded-full animate-spin"></div>
    </div>

    <div v-else-if="sessions.length === 0" class="text-sm text-surface-400 text-center py-4">
      No work sessions recorded yet
    </div>

    <div v-else class="space-y-1 max-h-64 overflow-y-auto">
      <div
        v-for="session in sessions"
        :key="session.id"
        class="flex items-center gap-3 p-2 rounded-lg hover:bg-surface-100 dark:hover:bg-surface-700/50 group"
      >
        <div
          class="w-7 h-7 rounded-full flex items-center justify-center text-xs font-bold shrink-0"
          :class="sourceColor(session.source)"
        >
          <span class="material-symbols-rounded text-sm">{{ sourceIcon(session.source) }}</span>
        </div>
        <div class="flex-1 min-w-0">
          <div class="flex items-center gap-2">
            <span class="text-xs font-medium text-surface-700 dark:text-surface-200 truncate">
              {{ session.user_email }}
            </span>
            <span class="text-[10px] px-1.5 py-0.5 rounded-full bg-surface-100 dark:bg-surface-700 text-surface-500">
              {{ session.source }}
            </span>
          </div>
          <div class="flex items-center gap-2 mt-0.5">
            <span v-if="session.entity_name" class="text-[11px] text-surface-400 truncate">
              {{ session.entity_name }}
            </span>
            <span class="text-[11px] text-surface-400">
              {{ formatDate(session.started_at) }}
            </span>
          </div>
        </div>
        <span class="text-xs font-mono font-semibold text-surface-600 dark:text-surface-300 shrink-0">
          {{ formatDuration(session.duration_seconds) }}
        </span>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, watch } from 'vue'
import { useProjectHubStore } from '../stores/projectHub'
import { useCardTimer } from '../composables/useCardTimer'

const props = defineProps({
  cardId: { type: [Number, String], required: true },
  userEmail: { type: String, default: null },
})

const hubStore = useProjectHubStore()
const { isRunning: timerRunning } = useCardTimer()
const loading = ref(false)
const sessions = ref([])

const totalSeconds = computed(() =>
  sessions.value.reduce((sum, s) => sum + (s.duration_seconds || 0), 0)
)
const totalFormatted = computed(() => formatDuration(totalSeconds.value))

function sourceIcon(source) {
  const map = {
    manual: 'edit',
    auto: 'bolt',
    board_task: 'dashboard',
    board_view: 'view_kanban',
    document_edit: 'description',
    drive_edit: 'folder_open',
    website_work: 'language',
    timer: 'timer',
    card_view: 'visibility',
    portal_call: 'video_call',
    calendar_event: 'event',
    local_watch: 'visibility',
  }
  return map[source] || 'schedule'
}

function sourceColor(source) {
  const map = {
    manual: 'bg-blue-100 text-blue-600 dark:bg-blue-900/30 dark:text-blue-400',
    auto: 'bg-amber-100 text-amber-600 dark:bg-amber-900/30 dark:text-amber-400',
    board_task: 'bg-purple-100 text-purple-600 dark:bg-purple-900/30 dark:text-purple-400',
    board_view: 'bg-indigo-100 text-indigo-600 dark:bg-indigo-900/30 dark:text-indigo-400',
    document_edit: 'bg-green-100 text-green-600 dark:bg-green-900/30 dark:text-green-400',
    drive_edit: 'bg-teal-100 text-teal-600 dark:bg-teal-900/30 dark:text-teal-400',
    website_work: 'bg-cyan-100 text-cyan-600 dark:bg-cyan-900/30 dark:text-cyan-400',
    timer: 'bg-orange-100 text-orange-600 dark:bg-orange-900/30 dark:text-orange-400',
    card_view: 'bg-emerald-100 text-emerald-600 dark:bg-emerald-900/30 dark:text-emerald-400',
    portal_call: 'bg-green-100 text-green-600 dark:bg-green-900/30 dark:text-green-400',
    calendar_event: 'bg-blue-100 text-blue-600 dark:bg-blue-900/30 dark:text-blue-400',
    local_watch: 'bg-amber-100 text-amber-600 dark:bg-amber-900/30 dark:text-amber-400',
  }
  return map[source] || 'bg-surface-100 text-surface-500'
}

function formatDuration(secs) {
  if (!secs || secs <= 0) return '0s'
  const h = Math.floor(secs / 3600)
  const m = Math.floor((secs % 3600) / 60)
  const s = secs % 60
  if (h > 0) return s > 0 ? `${h}h ${m}m ${s}s` : `${h}h ${m}m`
  if (m > 0) return s > 0 ? `${m}m ${s}s` : `${m}m`
  return `${s}s`
}

function formatDate(dt) {
  if (!dt) return ''
  const d = new Date(dt)
  const now = new Date()
  const diff = now - d
  if (diff < 60000) return 'just now'
  if (diff < 3600000) return `${Math.floor(diff / 60000)}m ago`
  if (diff < 86400000) return `${Math.floor(diff / 3600000)}h ago`
  return d.toLocaleDateString(undefined, { month: 'short', day: 'numeric' })
}

async function loadSessions() {
  loading.value = true
  try {
    const result = await hubStore.fetchWorkSessions(props.cardId, props.userEmail)
    sessions.value = result || []
  } finally {
    loading.value = false
  }
}

onMounted(loadSessions)

watch(() => props.cardId, loadSessions)
watch(() => props.userEmail, loadSessions)
watch(timerRunning, (running) => {
  if (!running) {
    setTimeout(loadSessions, 1500)
  }
})
</script>
