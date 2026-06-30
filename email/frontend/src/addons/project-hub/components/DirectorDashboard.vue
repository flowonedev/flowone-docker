<script setup>
import { ref, onMounted, computed, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useColleaguesStore } from '@/addons/team/stores/colleagues'
import api from '@/services/api'
import { fetchRoles } from '@/addons/project-hub/services/projectHubRoleApi'
import ProjectHubViewIntro from './ProjectHubViewIntro.vue'

const directorIntroSections = [
  {
    items: [
      { icon: 'dashboard', title: 'KPI cards at a glance', body: 'Total open tasks, total overdue, hours tracked this period, revenue at risk — all on one screen.' },
      { icon: 'group', title: 'Per-member rows', body: 'Workload, blocked count, last-active timestamp, and a difficulty-weighted total per person. Click any row to drill in.' },
      { icon: 'filter_alt', title: 'Role filter', body: 'Slice the org by graphic, account, reviewer, or any custom role. See only the slice that matters to your decision.' },
      { icon: 'compare_arrows', title: 'Period comparison', body: 'Green up / red down vs. previous period. Catch regressions early — more overdue this week than last is a flag.' },
      { icon: 'sort', title: 'Sortable everything', body: 'Click any column header to sort: total tasks, overdue, blocked, working, time tracked, difficulty.' },
    ],
  },
]

const directorIntroBenefits = [
  '<strong>One screen for weekly one-on-ones.</strong> Open it during reviews, point at numbers, decisions made in minutes.',
  '<strong>Defend pricing with data.</strong> When a client asks "why does this cost X?", show the hours tracked + people invested + capacity used.',
  '<strong>Hiring decisions become evidence-based.</strong> Three weeks of 110%+ load across roles = data-backed hire signal.',
  '<strong>Performance reviews stop being subjective.</strong> Every conversation has the numbers next to it.',
  '<strong>Pricing experiments are measurable.</strong> Bump retainer by 15%, watch tracked-hours-vs-revenue actually shift over the next quarter.',
]

const colleaguesStore = useColleaguesStore()
const route = useRoute()
const router = useRouter()

const loading = ref(false)
const members = ref([])
const sortBy = ref('total_tasks')
const sortDir = ref('desc')
const showExtended = ref(false)
const hubRoles = ref([])
const accessDenied = ref(false)

onMounted(async () => {
  await colleaguesStore.init()
  try {
    hubRoles.value = await fetchRoles()
  } catch {
    hubRoles.value = []
  }
  await loadSummary()
})

watch(
  () => route.query.role_slug,
  () => {
    loadSummary()
  },
)

async function loadSummary() {
  loading.value = true
  accessDenied.value = false
  try {
    const params = {}
    const slug = String(route.query.role_slug || '').trim()
    if (slug) params.role_slug = slug
    const { data } = await api.get('/project-hub/director-summary', { params })
    members.value = data.members || []
  } catch (e) {
    // Endpoint requires admin. Render a friendly empty-state instead of
    // letting the 403 bubble to the console as an unhandled axios error.
    if (e?.response?.status === 403) {
      accessDenied.value = true
      members.value = []
    } else {
      throw e
    }
  } finally {
    loading.value = false
  }
}

const selectedRoleSlug = computed({
  get() {
    return String(route.query.role_slug || '').trim()
  },
  set(v) {
    const slug = String(v || '').trim()
    const q = { ...route.query }
    if (slug) q.role_slug = slug
    else delete q.role_slug
    router.replace({ path: route.path, query: q })
  },
})

const sortedMembers = computed(() => {
  return [...members.value].sort((a, b) => {
    const aVal = Number(a[sortBy.value]) || 0
    const bVal = Number(b[sortBy.value]) || 0
    return sortDir.value === 'desc' ? bVal - aVal : aVal - bVal
  })
})

const totals = computed(() => {
  return members.value.reduce((acc, m) => ({
    total_tasks: acc.total_tasks + Number(m.total_tasks || 0),
    done_tasks: acc.done_tasks + Number(m.done_tasks || 0),
    working_tasks: acc.working_tasks + Number(m.working_tasks || 0),
    blocked_tasks: acc.blocked_tasks + Number(m.blocked_tasks || 0),
    overdue_tasks: acc.overdue_tasks + Number(m.overdue_tasks || 0),
    total_time: acc.total_time + Number(m.total_time || 0),
    created_tasks: acc.created_tasks + Number(m.created_tasks || 0),
    total_difficulty: acc.total_difficulty + Number(m.total_difficulty || 0),
  }), { total_tasks: 0, done_tasks: 0, working_tasks: 0, blocked_tasks: 0, overdue_tasks: 0, total_time: 0, created_tasks: 0, total_difficulty: 0 })
})

function toggleSort(col) {
  if (sortBy.value === col) {
    sortDir.value = sortDir.value === 'desc' ? 'asc' : 'desc'
  } else {
    sortBy.value = col
    sortDir.value = 'desc'
  }
}

function formatTime(seconds) {
  if (!seconds) return '0h'
  const h = Math.floor(seconds / 3600)
  const m = Math.floor((seconds % 3600) / 60)
  return h > 0 ? `${h}h ${m}m` : `${m}m`
}

function getName(email) {
  const c = colleaguesStore.colleagueByEmail[email?.toLowerCase()]
  return c?.display_name || email?.split('@')[0] || email
}

function getInitials(email) {
  const c = colleaguesStore.colleagueByEmail[email?.toLowerCase()]
  if (c) return colleaguesStore.getInitials(c)
  return (email || '?').charAt(0).toUpperCase()
}

function getColor(email) {
  const c = colleaguesStore.colleagueByEmail[email?.toLowerCase()]
  if (c) return colleaguesStore.getColleagueColor(c)
  return 'bg-primary-500'
}

function completionPct(m) {
  const total = Number(m.total_tasks) || 0
  const done = Number(m.done_tasks) || 0
  return total > 0 ? Math.round((done / total) * 100) : 0
}

const loadLevelMeta = {
  light: { label: 'Light', color: 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400' },
  moderate: { label: 'Moderate', color: 'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-400' },
  heavy: { label: 'Heavy', color: 'bg-orange-100 text-orange-700 dark:bg-orange-900/30 dark:text-orange-400' },
  overloaded: { label: 'Overloaded', color: 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400' },
}

const coreColumns = [
  { key: 'load_score', label: 'Load', icon: 'speed' },
  { key: 'total_tasks', label: 'Total', icon: 'task' },
  { key: 'done_tasks', label: 'Done', icon: 'check_circle' },
  { key: 'overdue_tasks', label: 'Overdue', icon: 'warning' },
]

const extendedColumns = [
  { key: 'working_tasks', label: 'Working', icon: 'engineering' },
  { key: 'blocked_tasks', label: 'Blocked', icon: 'block' },
  { key: 'created_tasks', label: 'Created', icon: 'edit_note' },
  { key: 'total_time', label: 'Time', icon: 'timer' },
  { key: 'total_difficulty', label: 'Weight', icon: 'fitness_center' },
]

const visibleColumns = computed(() =>
  showExtended.value ? [...coreColumns, ...extendedColumns] : coreColumns
)
</script>

<template>
  <div class="flex-1 overflow-auto">
    <div class="px-6 py-4">
      <ProjectHubViewIntro
        storage-key="ph.intro.director.v1"
        icon="leaderboard"
        title="Director Dashboard — the agency owner's morning coffee view"
        summary="Every member, every space, every overdue task — one screen. Filter by role, sort by anything, drill into any row. The view that turns gut-feeling decisions into data-backed ones."
        :sections="directorIntroSections"
        :benefits="directorIntroBenefits"
      />
      <div class="flex items-center justify-between mb-5">
        <h2 class="text-lg font-bold text-surface-800 dark:text-surface-200 flex items-center gap-2">
          <span class="material-symbols-rounded text-xl">supervisor_account</span>
          Director Dashboard
        </h2>
        <div class="flex items-center gap-2 flex-wrap">
          <div class="flex items-center gap-1.5">
            <span class="text-[10px] text-surface-500 uppercase font-semibold">Role</span>
            <select
              v-model="selectedRoleSlug"
              class="text-xs px-2 py-1.5 rounded-lg border border-surface-300 dark:border-surface-600 bg-white dark:bg-surface-700 text-surface-800 dark:text-surface-200 max-w-[10rem]"
            >
              <option value="">All roles</option>
              <option v-for="r in hubRoles" :key="r.id" :value="r.slug">{{ r.name }}</option>
            </select>
          </div>
          <button
            class="px-3 py-1.5 rounded-full text-xs font-medium transition-colors flex items-center gap-1"
            :class="showExtended
              ? 'bg-primary-100 dark:bg-primary-900/30 text-primary-600'
              : 'bg-surface-100 dark:bg-surface-700 text-surface-500 hover:bg-surface-200 dark:hover:bg-surface-600'"
            @click="showExtended = !showExtended"
          >
            <span class="material-symbols-rounded text-[14px]">{{ showExtended ? 'view_compact' : 'view_week' }}</span>
            {{ showExtended ? 'Compact' : 'All Columns' }}
          </button>
          <button
            class="px-3 py-1.5 rounded-full text-xs font-medium bg-surface-100 dark:bg-surface-700 text-surface-500 hover:bg-surface-200 dark:hover:bg-surface-600 transition-colors flex items-center gap-1"
            @click="loadSummary"
          >
            <span class="material-symbols-rounded text-[14px]">refresh</span>
            Refresh
          </button>
        </div>
      </div>

      <div v-if="loading" class="flex items-center justify-center py-16">
        <div class="animate-spin rounded-full h-8 w-8 border-2 border-primary-500 border-t-transparent"></div>
      </div>

      <div v-else-if="accessDenied" class="text-center py-16 text-surface-500">
        <span class="material-symbols-rounded text-5xl mb-3 block text-amber-500">lock</span>
        <p class="font-medium text-surface-700 dark:text-surface-300">Admin access required</p>
        <p class="text-xs mt-1 text-surface-400">Only team admins can view the Director Dashboard. Ask an admin to grant you access in Team settings.</p>
      </div>

      <div v-else-if="members.length === 0" class="text-center py-16 text-surface-400">
        <span class="material-symbols-rounded text-5xl mb-3 block">groups</span>
        <p>No member data yet</p>
      </div>

      <div v-else class="bg-white dark:bg-surface-800 rounded-2xl border border-surface-200 dark:border-surface-700 overflow-hidden">
        <div class="overflow-x-auto">
          <table class="w-full text-sm">
            <thead>
              <tr class="border-b border-surface-200 dark:border-surface-700">
                <th class="text-left px-4 py-3 text-xs font-semibold text-surface-500 uppercase tracking-wide">Member</th>
                <th
                  v-for="col in visibleColumns"
                  :key="col.key"
                  class="text-center px-3 py-3 text-xs font-semibold text-surface-500 uppercase tracking-wide cursor-pointer hover:text-primary-500 transition-colors whitespace-nowrap"
                  @click="toggleSort(col.key)"
                >
                  <span class="material-symbols-rounded text-[14px] align-middle mr-0.5">{{ col.icon }}</span>
                  {{ col.label }}
                  <span v-if="sortBy === col.key" class="material-symbols-rounded text-[12px] align-middle">
                    {{ sortDir === 'desc' ? 'arrow_downward' : 'arrow_upward' }}
                  </span>
                </th>
                <th class="text-center px-3 py-3 text-xs font-semibold text-surface-500 uppercase tracking-wide">Done %</th>
              </tr>
            </thead>
            <tbody>
              <tr
                v-for="m in sortedMembers"
                :key="m.user_email"
                class="border-b border-surface-100 dark:border-surface-700/50 last:border-0 hover:bg-surface-50 dark:hover:bg-surface-700/30 transition-colors"
              >
                <td class="px-4 py-3">
                  <div class="flex items-center gap-2.5">
                    <div class="w-7 h-7 rounded-full flex items-center justify-center text-xs font-bold text-white shrink-0" :class="getColor(m.user_email)">
                      {{ getInitials(m.user_email) }}
                    </div>
                    <div class="min-w-0">
                      <div class="font-medium text-surface-800 dark:text-surface-200 truncate">{{ getName(m.user_email) }}</div>
                      <div class="text-[10px] text-surface-400 truncate">{{ m.user_email }}</div>
                      <div v-if="(m.roles || []).length" class="flex flex-wrap gap-0.5 mt-1">
                        <span
                          v-for="slug in m.roles"
                          :key="slug"
                          class="text-[9px] px-1 py-0 rounded bg-surface-100 dark:bg-surface-700 text-surface-500"
                        >{{ slug }}</span>
                      </div>
                    </div>
                  </div>
                </td>
                <td class="text-center px-3 py-3">
                  <span
                    class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-semibold"
                    :class="loadLevelMeta[m.load_level]?.color || 'text-surface-400'"
                  >{{ loadLevelMeta[m.load_level]?.label || '--' }}</span>
                </td>
                <td class="text-center px-3 py-3 font-medium text-surface-700 dark:text-surface-300">{{ m.total_tasks }}</td>
                <td class="text-center px-3 py-3 text-green-600 dark:text-green-400">{{ m.done_tasks }}</td>
                <td class="text-center px-3 py-3" :class="Number(m.overdue_tasks) > 0 ? 'text-red-500 font-medium' : 'text-surface-400'">{{ m.overdue_tasks }}</td>
                <template v-if="showExtended">
                  <td class="text-center px-3 py-3 text-blue-600 dark:text-blue-400">{{ m.working_tasks }}</td>
                  <td class="text-center px-3 py-3" :class="Number(m.blocked_tasks) > 0 ? 'text-red-500 font-medium' : 'text-surface-400'">{{ m.blocked_tasks }}</td>
                  <td class="text-center px-3 py-3 text-surface-500">{{ m.created_tasks }}</td>
                  <td class="text-center px-3 py-3 text-surface-500">{{ formatTime(m.total_time) }}</td>
                  <td class="text-center px-3 py-3 text-surface-500">{{ m.total_difficulty }}</td>
                </template>
                <td class="text-center px-3 py-3">
                  <div class="flex items-center gap-2 justify-center">
                    <div class="w-16 h-1.5 rounded-full bg-surface-200 dark:bg-surface-600 overflow-hidden">
                      <div class="h-full rounded-full bg-green-500 transition-all" :style="{ width: completionPct(m) + '%' }"></div>
                    </div>
                    <span class="text-[10px] text-surface-500 w-8">{{ completionPct(m) }}%</span>
                  </div>
                </td>
              </tr>
            </tbody>
            <tfoot>
              <tr class="bg-surface-50 dark:bg-surface-700/30 border-t border-surface-200 dark:border-surface-700">
                <td class="px-4 py-3 font-semibold text-surface-600 dark:text-surface-300">Totals</td>
                <td></td>
                <td class="text-center px-3 py-3 font-bold text-surface-800 dark:text-surface-100">{{ totals.total_tasks }}</td>
                <td class="text-center px-3 py-3 font-bold text-green-600">{{ totals.done_tasks }}</td>
                <td class="text-center px-3 py-3 font-bold text-red-500">{{ totals.overdue_tasks }}</td>
                <template v-if="showExtended">
                  <td class="text-center px-3 py-3 font-bold text-blue-600">{{ totals.working_tasks }}</td>
                  <td class="text-center px-3 py-3 font-bold text-red-500">{{ totals.blocked_tasks }}</td>
                  <td class="text-center px-3 py-3 font-bold text-surface-600">{{ totals.created_tasks }}</td>
                  <td class="text-center px-3 py-3 font-bold text-surface-600">{{ formatTime(totals.total_time) }}</td>
                  <td class="text-center px-3 py-3 font-bold text-surface-600">{{ totals.total_difficulty }}</td>
                </template>
                <td></td>
              </tr>
            </tfoot>
          </table>
        </div>
      </div>
    </div>
  </div>
</template>
