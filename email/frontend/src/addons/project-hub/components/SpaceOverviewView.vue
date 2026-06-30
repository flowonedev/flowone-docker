<script setup>
import { ref, computed, watch, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { useProjectHubStore } from '@/addons/project-hub/stores/projectHub'
import SpaceClientCard from './SpaceClientCard.vue'
import ProjectHubViewIntro from './ProjectHubViewIntro.vue'
import api from '@/services/api'

const spaceIntroSections = [
  {
    heading: 'The hierarchy at a glance',
    items: [
      { icon: 'folder_special', title: 'Space (you are here)', body: 'Container for one client or one big project. Everything related — folders, boards, tasks, files, time, comments, shares — lives inside.' },
      { icon: 'folder', title: 'Folders', body: 'Workstreams inside the Space. Campaigns, deliverables, retainer streams, production phases — group however your team works.' },
      { icon: 'view_kanban', title: 'Boards', body: 'Kanban columns + cards live inside Folders. The same Board can be linked into more than one Folder if a workflow is shared.' },
      { icon: 'task_alt', title: 'Tasks (cards)', body: 'Where the actual work happens: assignees, due dates, subtasks, comments, attachments, time logs, per-role statuses.' },
    ],
  },
  {
    heading: 'What you can do from this Space',
    items: [
      { icon: 'business', title: 'Linked client context', body: 'When the Space is linked to a CRM client, every task inside is automatically attributed to that client for invoicing.' },
      { icon: 'schedule', title: 'Roll-up time tracking', body: 'Minutes logged on tasks roll up: Task → Board → Folder → Space → Client. The Time view slices it however you need.' },
      { icon: 'share', title: 'Client share links', body: 'Open a task, pick Drive files, set password + expiry + max-download cap, send a public link. Revoke any time.' },
      { icon: 'event_repeat', title: 'Calendar 2-way sync', body: 'Tasks with due dates appear as calendar events. Drag to reschedule — task dates move with them (titles stay protected).' },
      { icon: 'alternate_email', title: '@mention teammates', body: 'Pull anyone into the conversation in any task comment — they get notified even when not assigned.' },
      { icon: 'groups', title: 'Per-role statuses', body: 'Designer can be "Designing" while account is "Negotiating" on the same card — separate progress tracks per role.' },
    ],
  },
]

const spaceIntroBenefits = [
  '<strong>One place for everything per client</strong> — no more hunting through 6 tools to answer "what\'s happening with Acme?".',
  '<strong>Billing becomes audit-proof</strong>: every minute traces to a task, board, folder, Space, and client.',
  '<strong>Client-facing shares without account creation.</strong> Send a link — they download, you get a notification, the timeline logs it.',
  '<strong>Calendar and Project Hub never disagree</strong> — drag an event, the task updates; move a task, the calendar updates.',
  '<strong>Multi-role workflows finally work right.</strong> Designer\'s "Done" doesn\'t mean account\'s "Done" — each track is independent on the same task.',
]

const props = defineProps({
  spaceId: { type: Number, required: true },
})

const emit = defineEmits(['select-folder', 'open-card'])

const router = useRouter()
const hubStore = useProjectHubStore()

const loading = ref(false)
const data = ref(null)
const error = ref(false)

const space = computed(() => data.value?.space || {})
const folders = computed(() => data.value?.folders || [])
const recentCards = computed(() => data.value?.recent_cards || [])
const timeSummary = computed(() => data.value?.time_summary || { total_seconds: 0, by_user: [] })

const clientContext = computed(() => data.value?.client_context || null)
const totalCards = computed(() => folders.value.reduce((s, f) => s + (f.card_count || 0), 0))

async function load() {
  loading.value = true
  error.value = false
  try {
    const { data: res } = await api.get(`/project-hub/spaces/${props.spaceId}/overview`)
    data.value = res
  } catch (err) {
    console.error('[SpaceOverview] load error:', err)
    error.value = true
  } finally {
    loading.value = false
  }
}

function fmt(seconds) {
  if (!seconds || seconds <= 0) return '0m'
  const h = Math.floor(seconds / 3600)
  const m = Math.round((seconds % 3600) / 60)
  if (h === 0) return `${m}m`
  if (m === 0) return `${h}h`
  return `${h}h ${m}m`
}

function fmtDate(dateStr) {
  if (!dateStr) return ''
  return new Date(dateStr).toLocaleDateString(undefined, { month: 'short', day: 'numeric' })
}

function selectFolder(folder) {
  emit('select-folder', folder)
}

function openCard(card) {
  emit('open-card', card)
}

function goToTime() {
  const clientId = space.value?.client_id
  if (clientId) router.push({ path: '/workload', query: { mode: 'task-time', client_id: clientId } })
  else router.push({ path: '/workload', query: { mode: 'task-time' } })
}

watch(() => props.spaceId, () => load())
onMounted(() => load())
</script>

<template>
  <div class="flex-1 overflow-auto px-6 py-5">
    <div v-if="loading && !data" class="flex items-center justify-center py-16">
      <span class="material-symbols-rounded text-3xl text-surface-400 animate-spin">progress_activity</span>
    </div>

    <div v-else-if="error && !data" class="flex flex-col items-center justify-center py-16 text-surface-400">
      <span class="material-symbols-rounded text-4xl mb-2">cloud_off</span>
      <p class="text-sm mb-3">Failed to load space overview</p>
      <button @click="load" class="px-4 py-1.5 rounded-full text-xs font-medium bg-primary-500 text-white hover:bg-primary-600 transition-colors">
        Retry
      </button>
    </div>

    <template v-else-if="data">
      <!-- Space header with inline stats -->
      <div class="flex items-center gap-4 mb-6">
        <div
          class="w-11 h-11 rounded-xl flex items-center justify-center text-white"
          :style="{ backgroundColor: space.color || '#6366f1' }"
        >
          <span class="material-symbols-rounded text-xl">{{ space.icon || 'folder_special' }}</span>
        </div>
        <div class="flex-1 min-w-0">
          <h1 class="text-lg font-bold text-surface-900 dark:text-surface-100">{{ space.name }}</h1>
          <div class="flex items-center gap-3 text-xs text-surface-400 mt-0.5">
            <span v-if="space.client_name" class="text-surface-500">{{ space.client_name }}</span>
            <span v-if="space.client_name" class="text-surface-300">|</span>
            <span>{{ totalCards }} tasks</span>
            <span class="text-surface-300">|</span>
            <span>{{ timeSummary.by_user.length }} members</span>
            <span v-if="timeSummary.total_seconds > 0" class="text-surface-300">|</span>
            <span v-if="timeSummary.total_seconds > 0" class="text-indigo-500 font-medium">{{ fmt(timeSummary.total_seconds) }} tracked</span>
          </div>
        </div>
        <button
          @click="goToTime"
          class="flex items-center gap-1.5 px-4 py-2 rounded-full bg-surface-100 dark:bg-surface-800 text-surface-600 dark:text-surface-300 text-sm font-medium hover:bg-surface-200 dark:hover:bg-surface-700 transition-colors border border-surface-200 dark:border-surface-700"
        >
          <span class="material-symbols-rounded text-lg">schedule</span>
          Time
        </button>
      </div>

      <ProjectHubViewIntro
        storage-key="ph.intro.space.v1"
        icon="folder_special"
        title="Working in this Space"
        summary="A Space groups every folder, board, task, file, and conversation for one client or project. Everything you log here rolls up to the same place — for time, billing, and history."
        :sections="spaceIntroSections"
        :benefits="spaceIntroBenefits"
      />

      <!-- Client context card (only when space is linked to a client) -->
      <SpaceClientCard v-if="clientContext" :client="clientContext" />

      <!-- Folders (full width, primary content) -->
      <div class="bg-white dark:bg-surface-800 rounded-xl border border-surface-200 dark:border-surface-700 overflow-hidden mb-6">
        <div class="px-4 py-3 border-b border-surface-200 dark:border-surface-700 flex items-center gap-2">
          <span class="material-symbols-rounded text-primary-500 text-lg">folder</span>
          <h3 class="text-sm font-semibold text-surface-900 dark:text-surface-100">Folders</h3>
          <span class="text-xs text-surface-400 ml-auto">{{ folders.length }} folders</span>
        </div>
        <div class="divide-y divide-surface-100 dark:divide-surface-700/50">
          <div v-if="folders.length === 0" class="text-center py-10 text-surface-400 text-sm">
            <span class="material-symbols-rounded text-3xl block mb-2">create_new_folder</span>
            No folders yet
          </div>
          <button
            v-for="folder in folders" :key="folder.id"
            class="w-full text-left flex items-center gap-3 px-4 py-3 hover:bg-surface-50 dark:hover:bg-surface-700/50 transition-colors"
            @click="selectFolder(folder)"
          >
            <div
              class="w-9 h-9 rounded-lg flex items-center justify-center flex-shrink-0"
              :style="{ backgroundColor: (folder.color || space.color || '#6366f1') + '18' }"
            >
              <span class="material-symbols-rounded text-lg" :style="{ color: folder.color || space.color || '#6366f1' }">
                {{ folder.icon || 'folder' }}
              </span>
            </div>
            <div class="flex-1 min-w-0">
              <div class="text-sm font-medium text-surface-800 dark:text-surface-100 truncate">{{ folder.name }}</div>
              <div class="text-xs text-surface-400">{{ folder.board_count }} board{{ folder.board_count !== 1 ? 's' : '' }} / {{ folder.card_count }} tasks</div>
            </div>
            <span class="material-symbols-rounded text-surface-300 text-sm">chevron_right</span>
          </button>
        </div>
      </div>

      <!-- Recent Activity (compact) -->
      <div v-if="recentCards.length > 0" class="bg-white dark:bg-surface-800 rounded-xl border border-surface-200 dark:border-surface-700 overflow-hidden">
        <div class="px-4 py-3 border-b border-surface-200 dark:border-surface-700 flex items-center gap-2">
          <span class="material-symbols-rounded text-surface-400 text-lg">history</span>
          <h3 class="text-sm font-semibold text-surface-900 dark:text-surface-100">Recent Activity</h3>
        </div>
        <div class="divide-y divide-surface-100 dark:divide-surface-700/50 max-h-64 overflow-y-auto">
          <button
            v-for="card in recentCards" :key="card.id"
            class="w-full text-left flex items-center gap-3 px-4 py-2.5 hover:bg-surface-50 dark:hover:bg-surface-700/50 transition-colors"
            @click="openCard({ id: card.id, board_id: card.board_id })"
          >
            <span class="material-symbols-rounded text-base" :class="card.completed ? 'text-green-500' : 'text-surface-300'">
              {{ card.completed ? 'check_circle' : 'radio_button_unchecked' }}
            </span>
            <div class="flex-1 min-w-0">
              <div class="text-sm text-surface-800 dark:text-surface-100 truncate">{{ card.title }}</div>
              <div class="text-xs text-surface-400">{{ card.board_name }}</div>
            </div>
            <span v-if="card.updated_at" class="text-xs text-surface-400 tabular-nums">{{ fmtDate(card.updated_at) }}</span>
          </button>
        </div>
      </div>
    </template>
  </div>
</template>
