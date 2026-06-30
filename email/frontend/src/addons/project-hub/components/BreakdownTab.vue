<script setup>
import { ref, computed, watch, onMounted, defineAsyncComponent } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import { useColleaguesStore } from '@/addons/team/stores/colleagues'
import { useClientsStore } from '@/stores/clients'
import { fetchTimeBreakdown } from '@/addons/project-hub/services/timeBreakdownService'

const BreakdownTableView = defineAsyncComponent(() => import('./BreakdownTableView.vue'))

const props = defineProps({
  period: { type: String, default: 'month' },
  clientId: { type: [Number, String], default: null },
  boardId: { type: [Number, String], default: null },
})

const clientsStore = useClientsStore()

const clientName = computed(() => {
  if (!props.clientId) return null
  const c = clientsStore.clients.find(cl => cl.id === Number(props.clientId))
  return c?.display_name || c?.domain || null
})

const boardName = computed(() => {
  if (!props.boardId) return null
  const row = rows.value.find(r => r.board_id === Number(props.boardId))
  return row?.board_name || null
})

const router = useRouter()
const route = useRoute()
const colleaguesStore = useColleaguesStore()

const loading = ref(false)
const rows = ref([])
const isAdmin = ref(false)
const groupBy = ref('client')
const viewMode = ref('table')
const search = ref('')

const expanded = ref({})

onMounted(async () => {
  if (!colleaguesStore.loaded) await colleaguesStore.fetchColleagues()
  if (!clientsStore.clients.length) clientsStore.fetchClients()
  await load()
})

watch(() => [props.period, props.clientId, props.boardId, groupBy.value], () => load())

async function load() {
  loading.value = true
  try {
    const params = { period: props.period }
    if (props.clientId) params.client_id = props.clientId
    if (props.boardId) params.board_id = props.boardId
    const result = await fetchTimeBreakdown(params)
    rows.value = result?.data?.rows || []
    isAdmin.value = result?.data?.is_admin || false
    expanded.value = {}
  } catch (err) {
    console.error('[BreakdownTab] load error:', err)
    rows.value = []
  } finally {
    loading.value = false
  }
}

function refresh() { load() }

const filteredRows = computed(() => {
  if (!search.value.trim()) return rows.value
  const q = search.value.toLowerCase()
  return rows.value.filter(r =>
    r.client_name?.toLowerCase().includes(q) ||
    r.board_name?.toLowerCase().includes(q) ||
    r.user_name?.toLowerCase().includes(q) ||
    r.card_title?.toLowerCase().includes(q) ||
    r.entity_name?.toLowerCase().includes(q)
  )
})

const tree = computed(() => {
  if (groupBy.value === 'client') return buildClientTree(filteredRows.value)
  return buildPersonTree(filteredRows.value)
})

const grandTotal = computed(() =>
  filteredRows.value.reduce((sum, r) => sum + r.total_seconds, 0)
)

function addRowToCardMap(cardMap, r, keyPrefix) {
  if (!cardMap[r.card_id]) {
    cardMap[r.card_id] = {
      key: `t-${keyPrefix}-${r.card_id}`, label: r.card_title, total: 0,
      sessions: 0, lastActive: null, cardId: r.card_id, boardId: r.board_id, files: [],
    }
  }
  const task = cardMap[r.card_id]
  task.total += r.total_seconds
  task.sessions += r.session_count
  if (!task.lastActive || r.last_active > task.lastActive) task.lastActive = r.last_active

  const fileLabel = r.entity_name || sourceLabels[r.source] || r.source || 'General'
  task.files.push({
    key: `f-${keyPrefix}-${r.card_id}-${r.source}-${task.files.length}`,
    label: fileLabel,
    total: r.total_seconds,
    sessions: r.session_count,
    lastActive: r.last_active,
    source: r.source,
    entityType: r.entity_type,
    entityName: r.entity_name,
  })
}

function buildClientTree(data) {
  const map = {}
  for (const r of data) {
    const ck = r.client_id ?? 'none'
    if (!map[ck]) map[ck] = { key: `c-${ck}`, label: r.client_name || 'Unassigned (no client)', total: 0, children: {} }
    const bk = r.board_id ?? `act-${r.board_name}`
    if (!map[ck].children[bk]) map[ck].children[bk] = { key: `b-${ck}-${bk}`, label: r.board_name, boardId: r.board_id, total: 0, children: {}, isActivity: !r.board_id }
    const uk = r.user_email
    if (!map[ck].children[bk].children[uk]) map[ck].children[bk].children[uk] = { key: `u-${ck}-${bk}-${uk}`, label: r.user_name, total: 0, cardMap: {} }
    map[ck].total += r.total_seconds
    map[ck].children[bk].total += r.total_seconds
    map[ck].children[bk].children[uk].total += r.total_seconds
    if (r.card_id) {
      addRowToCardMap(map[ck].children[bk].children[uk].cardMap, r, `${ck}-${bk}-${uk}`)
    } else {
      const existing = map[ck].children[bk].children[uk]
      existing.activityTotal = (existing.activityTotal || 0) + r.total_seconds
      existing.activitySessions = (existing.activitySessions || 0) + r.session_count
    }
  }
  return finalizeTree(map)
}

function buildPersonTree(data) {
  const map = {}
  for (const r of data) {
    const uk = r.user_email
    if (!map[uk]) map[uk] = { key: `u-${uk}`, label: r.user_name, total: 0, children: {} }
    const ck = r.client_id ?? 'none'
    if (!map[uk].children[ck]) map[uk].children[ck] = { key: `c-${uk}-${ck}`, label: r.client_name || 'Unassigned (no client)', total: 0, children: {} }
    const bk = r.board_id ?? `act-${r.board_name}`
    if (!map[uk].children[ck].children[bk]) map[uk].children[ck].children[bk] = { key: `b-${uk}-${bk}`, label: r.board_name, boardId: r.board_id, total: 0, cardMap: {}, isActivity: !r.board_id }
    map[uk].total += r.total_seconds
    map[uk].children[ck].total += r.total_seconds
    map[uk].children[ck].children[bk].total += r.total_seconds
    if (r.card_id) {
      addRowToCardMap(map[uk].children[ck].children[bk].cardMap, r, `${uk}-${ck}-${bk}`)
    } else {
      const existing = map[uk].children[ck].children[bk]
      existing.activityTotal = (existing.activityTotal || 0) + r.total_seconds
      existing.activitySessions = (existing.activitySessions || 0) + r.session_count
    }
  }
  return finalizeTree(map)
}

const byTotalDesc = (a, b) => b.total - a.total

function finalizeTree(map) {
  return Object.values(map).sort(byTotalDesc).map(l0 => ({
    ...l0,
    children: Object.values(l0.children).sort(byTotalDesc).map(l1 => ({
      ...l1,
      children: Object.values(l1.children).sort(byTotalDesc).map(l2 => ({
        ...l2,
        children: Object.values(l2.cardMap || {}).sort(byTotalDesc).map(task => ({
          ...task,
          files: task.files.sort(byTotalDesc),
        })),
      })),
    })),
  }))
}

function toggle(key) { expanded.value[key] = !expanded.value[key] }

const activityIcons = {
  'Email': 'mail',
  'Calendar': 'event',
  'Board Viewing': 'dashboard',
  'Drive': 'folder_open',
  'Documents': 'description',
  'Website Work': 'language',
  'Mood Boards': 'palette',
  'Calls': 'call',
  'Manual Entry': 'edit_note',
}

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

function fileColor(file) {
  if (file.entityName) return 'text-teal-500'
  const map = {
    timer: 'text-orange-400',
    card_view: 'text-emerald-400',
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

function openCard(boardId, cardId) {
  if (!boardId || !cardId) return
  router.push({ name: 'board', params: { id: boardId }, query: { card: cardId } })
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

const depthIcons = {
  0: { client: 'domain', person: 'person' },
  1: { client: 'folder_open', person: 'domain' },
  2: { client: 'person', person: 'folder_open' },
}

defineExpose({ refresh })
</script>

<template>
  <div>
    <!-- Toolbar -->
    <div class="flex flex-wrap items-center gap-3 mb-4">
      <div class="inline-flex rounded-full bg-surface-200 dark:bg-surface-800 p-0.5">
        <button
          v-for="opt in [{ v: 'client', l: 'By Client' }, { v: 'person', l: 'By Person' }]"
          :key="opt.v"
          class="px-4 py-1.5 rounded-full text-sm font-medium transition-colors"
          :class="groupBy === opt.v
            ? 'bg-primary-500 text-white shadow-sm'
            : 'text-surface-600 dark:text-surface-300 hover:bg-surface-300 dark:hover:bg-surface-700'"
          @click="groupBy = opt.v"
        >{{ opt.l }}</button>
      </div>

      <div class="inline-flex rounded-full bg-surface-200 dark:bg-surface-800 p-0.5">
        <button
          v-for="opt in [{ v: 'tree', icon: 'account_tree', l: 'Tree' }, { v: 'table', icon: 'table_rows', l: 'Table' }]"
          :key="opt.v"
          class="px-3 py-1.5 rounded-full text-sm font-medium transition-colors inline-flex items-center gap-1.5"
          :class="viewMode === opt.v
            ? 'bg-primary-500 text-white shadow-sm'
            : 'text-surface-600 dark:text-surface-300 hover:bg-surface-300 dark:hover:bg-surface-700'"
          @click="viewMode = opt.v"
        ><span class="material-symbols-rounded text-base">{{ opt.icon }}</span>{{ opt.l }}</button>
      </div>

      <div class="relative ml-auto">
        <span class="material-symbols-rounded absolute left-2.5 top-1/2 -translate-y-1/2 text-surface-400 text-lg">search</span>
        <input
          v-model="search"
          placeholder="Search client, project, person..."
          class="pl-9 pr-3 py-1.5 rounded-full border border-surface-300 dark:border-surface-600 bg-white dark:bg-surface-800 text-sm text-surface-700 dark:text-surface-200 w-64 focus:outline-none focus:ring-2 focus:ring-primary-400"
        />
      </div>

      <button @click="load()" class="p-2 rounded-lg hover:bg-surface-200 dark:hover:bg-surface-700 transition-colors" title="Refresh">
        <span class="material-symbols-rounded text-surface-500" :class="{ 'animate-spin': loading }">refresh</span>
      </button>
    </div>

    <!-- Active filters -->
    <div v-if="props.clientId || props.boardId" class="flex items-center gap-2 mb-4">
      <span class="text-xs text-surface-400">Filtered:</span>
      <span v-if="props.clientId" class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full bg-indigo-100 dark:bg-indigo-500/20 text-indigo-700 dark:text-indigo-300 text-xs font-medium">
        <span class="material-symbols-rounded text-sm">domain</span> {{ clientName || ('Client #' + props.clientId) }}
      </span>
      <span v-if="props.boardId" class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full bg-emerald-100 dark:bg-emerald-500/20 text-emerald-700 dark:text-emerald-300 text-xs font-medium">
        <span class="material-symbols-rounded text-sm">folder_open</span> {{ boardName || ('Board #' + props.boardId) }}
      </span>
    </div>

    <!-- Grand total -->
    <div class="flex items-center justify-between mb-4 px-4 py-3 rounded-xl bg-white dark:bg-surface-800 border border-surface-200 dark:border-surface-700">
      <span class="text-sm font-semibold text-surface-700 dark:text-surface-200">Total Tracked</span>
      <span class="text-lg font-bold text-primary-600 dark:text-primary-400">{{ fmt(grandTotal) }}</span>
    </div>

    <!-- Loading -->
    <div v-if="loading" class="flex items-center justify-center py-16">
      <span class="material-symbols-rounded text-3xl text-surface-400 animate-spin">progress_activity</span>
    </div>

    <!-- Empty -->
    <div v-else-if="tree.length === 0" class="text-center py-16">
      <span class="material-symbols-rounded text-5xl text-surface-300 dark:text-surface-600 mb-3 block">hourglass_empty</span>
      <p class="text-surface-500">No time tracked for this period.</p>
    </div>

    <!-- TABLE VIEW -->
    <BreakdownTableView
      v-else-if="viewMode === 'table'"
      :tree="tree"
      :group-by="groupBy"
      @open-card="openCard"
    />

    <!-- TREE VIEW -->
    <div v-else class="space-y-1">
      <div v-for="l0 in tree" :key="l0.key" class="rounded-xl overflow-hidden border border-surface-200 dark:border-surface-700 bg-white dark:bg-surface-800">
        <button class="w-full flex items-center gap-3 px-4 py-3 hover:bg-surface-50 dark:hover:bg-surface-750 transition-colors text-left" @click="toggle(l0.key)">
          <span class="material-symbols-rounded text-lg transition-transform" :class="expanded[l0.key] ? 'rotate-90' : ''">chevron_right</span>
          <span class="material-symbols-rounded text-xl text-primary-500">{{ depthIcons[0][groupBy] }}</span>
          <span class="flex-1 font-semibold text-sm text-surface-800 dark:text-surface-100 truncate">{{ l0.label }}</span>
          <span class="text-sm font-bold text-primary-600 dark:text-primary-400 tabular-nums">{{ fmt(l0.total) }}</span>
        </button>
        <div v-if="expanded[l0.key]" class="border-t border-surface-100 dark:border-surface-700">
          <div v-for="l1 in l0.children" :key="l1.key">
            <button class="w-full flex items-center gap-3 pl-10 pr-4 py-2.5 hover:bg-surface-50 dark:hover:bg-surface-750 transition-colors text-left" @click="toggle(l1.key)">
              <span class="material-symbols-rounded text-base transition-transform" :class="expanded[l1.key] ? 'rotate-90' : ''">chevron_right</span>
              <span class="material-symbols-rounded text-lg" :class="l1.isActivity ? 'text-amber-500' : 'text-surface-400'">{{ l1.isActivity ? (activityIcons[l1.label] || 'schedule') : depthIcons[1][groupBy] }}</span>
              <span class="flex-1 text-sm text-surface-700 dark:text-surface-200 truncate">{{ l1.label }}</span>
              <span class="text-sm font-semibold text-surface-600 dark:text-surface-300 tabular-nums">{{ fmt(l1.total) }}</span>
            </button>
            <div v-if="expanded[l1.key]" class="border-t border-surface-50 dark:border-surface-700/50">
              <div v-for="l2 in l1.children" :key="l2.key">
                <button class="w-full flex items-center gap-3 pl-16 pr-4 py-2 hover:bg-surface-50 dark:hover:bg-surface-750 transition-colors text-left" @click="toggle(l2.key)">
                  <span class="material-symbols-rounded text-base transition-transform" :class="expanded[l2.key] ? 'rotate-90' : ''">chevron_right</span>
                  <span class="material-symbols-rounded text-lg text-surface-400">{{ depthIcons[2][groupBy] }}</span>
                  <span class="flex-1 text-sm text-surface-600 dark:text-surface-200 truncate">{{ l2.label }}</span>
                  <span class="text-sm font-medium text-surface-500 dark:text-surface-400 tabular-nums">{{ fmt(l2.total) }}</span>
                </button>
                <div v-if="expanded[l2.key]" class="border-t border-surface-50 dark:border-surface-700/30">
                  <!-- Activity summary (non-card tracked time) -->
                  <div
                    v-if="l2.activityTotal"
                    class="flex items-center gap-3 pl-24 pr-4 py-2 text-surface-500"
                  >
                    <span class="material-symbols-rounded text-base text-amber-400">schedule</span>
                    <span class="flex-1 text-sm text-surface-500 dark:text-surface-400 truncate">Tracked activity</span>
                    <span class="text-xs text-surface-400 tabular-nums hidden sm:inline">{{ l2.activitySessions }} entries</span>
                    <span class="text-sm font-medium text-surface-500 dark:text-surface-400 tabular-nums">{{ fmt(l2.activityTotal) }}</span>
                  </div>
                  <!-- Card tasks (expandable to show files) -->
                  <div v-for="task in l2.children" :key="task.key">
                    <div class="flex items-center gap-2 pl-[88px] pr-4 py-2 hover:bg-surface-50 dark:hover:bg-surface-750 transition-colors">
                      <button v-if="task.files.length > 0" class="shrink-0 p-0.5" @click="toggle(task.key)">
                        <span class="material-symbols-rounded text-sm text-surface-400 transition-transform" :class="expanded[task.key] ? 'rotate-90' : ''">chevron_right</span>
                      </button>
                      <span v-else class="w-5"></span>
                      <span class="material-symbols-rounded text-base text-surface-300">task_alt</span>
                      <span class="flex-1 text-sm text-surface-600 dark:text-surface-300 truncate">{{ task.label }}</span>
                      <span class="text-xs text-surface-400 tabular-nums hidden sm:inline">{{ task.sessions }} session{{ task.sessions !== 1 ? 's' : '' }}</span>
                      <span v-if="task.lastActive" class="text-xs text-surface-400 tabular-nums hidden md:inline">{{ fmtDate(task.lastActive) }}</span>
                      <span class="text-sm font-medium text-surface-600 dark:text-surface-300 tabular-nums">{{ fmt(task.total) }}</span>
                      <button v-if="task.cardId" class="shrink-0 p-0.5 rounded hover:bg-primary-100 dark:hover:bg-primary-500/20 transition-colors" @click="openCard(task.boardId, task.cardId)" title="Open card">
                        <span class="material-symbols-rounded text-sm text-surface-300 hover:text-primary-500">open_in_new</span>
                      </button>
                    </div>
                    <!-- File-level detail under each task -->
                    <div v-if="expanded[task.key] && task.files.length > 0">
                      <div
                        v-for="file in task.files" :key="file.key"
                        class="flex items-center gap-2 pl-[120px] pr-4 py-1.5 hover:bg-surface-50 dark:hover:bg-surface-750 transition-colors"
                      >
                        <span class="material-symbols-rounded text-sm" :class="fileColor(file)">{{ fileIcon(file) }}</span>
                        <span class="flex-1 text-xs text-surface-500 dark:text-surface-400 truncate">{{ file.label }}</span>
                        <span class="text-[11px] text-surface-400 tabular-nums hidden sm:inline">{{ file.sessions }} session{{ file.sessions !== 1 ? 's' : '' }}</span>
                        <span class="text-xs font-medium text-surface-500 dark:text-surface-400 tabular-nums">{{ fmt(file.total) }}</span>
                      </div>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>
