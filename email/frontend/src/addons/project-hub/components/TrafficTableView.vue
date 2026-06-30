<script setup>
import { ref, computed, onMounted, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useColleaguesStore } from '@/addons/team/stores/colleagues'
import api from '@/services/api'
import { fetchRoles } from '@/addons/project-hub/services/projectHubRoleApi'

const colleaguesStore = useColleaguesStore()
const route = useRoute()
const router = useRouter()

const loading = ref(false)
const members = ref([])
const granularity = ref('day')
const metric = ref('task_count')
const hubRoles = ref([])

const today = new Date()
const startDate = ref(formatDate(getMonday(today)))
const endDate = ref(formatDate(getSunday(today)))

function getMonday(d) {
  const dt = new Date(d)
  const day = dt.getDay()
  const diff = dt.getDate() - day + (day === 0 ? -6 : 1)
  dt.setDate(diff)
  return dt
}

function getSunday(d) {
  const mon = getMonday(d)
  mon.setDate(mon.getDate() + 6)
  return mon
}

function formatDate(d) {
  return d.toISOString().split('T')[0]
}

onMounted(async () => {
  await colleaguesStore.init()
  try {
    hubRoles.value = await fetchRoles()
  } catch {
    hubRoles.value = []
  }
  await loadTraffic()
})

watch(
  () => route.query.role_slug,
  () => {
    loadTraffic()
  },
)

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

async function loadTraffic() {
  loading.value = true
  try {
    const slug = String(route.query.role_slug || '').trim()
    const { data } = await api.get('/project-hub/workload/traffic', {
      params: {
        start_date: startDate.value,
        end_date: endDate.value,
        granularity: granularity.value,
        ...(slug ? { role_slug: slug } : {}),
      },
    })
    members.value = data.members || []
  } finally {
    loading.value = false
  }
}

const periodColumns = computed(() => {
  const start = new Date(startDate.value)
  const end = new Date(endDate.value)
  const cols = []

  if (granularity.value === 'week') {
    const cur = new Date(start)
    while (cur <= end) {
      const mon = getMonday(cur)
      cols.push({
        key: formatDate(mon),
        label: `W ${mon.toLocaleDateString(undefined, { month: 'short', day: 'numeric' })}`,
        isToday: false,
      })
      cur.setDate(cur.getDate() + 7)
    }
  } else {
    const cur = new Date(start)
    while (cur <= end) {
      cols.push({
        key: formatDate(cur),
        label: cur.toLocaleDateString(undefined, { weekday: 'short', day: 'numeric' }),
        isToday: formatDate(cur) === formatDate(new Date()),
        isWeekend: cur.getDay() === 0 || cur.getDay() === 6,
      })
      cur.setDate(cur.getDate() + 1)
    }
  }
  return cols
})

function getCellValue(member, periodKey) {
  const period = (member.periods || []).find(p => p.period === periodKey)
  if (!period) return 0
  if (metric.value === 'hours') return period.hours || 0
  if (metric.value === 'difficulty') return period.difficulty || 0
  return period.task_count || 0
}

function getCellColor(value) {
  if (value === 0) return ''
  if (metric.value === 'hours') {
    if (value >= 8) return 'bg-red-100 dark:bg-red-900/30 text-red-700 dark:text-red-300'
    if (value >= 4) return 'bg-amber-100 dark:bg-amber-900/30 text-amber-700 dark:text-amber-300'
    return 'bg-green-100 dark:bg-green-900/30 text-green-700 dark:text-green-300'
  }
  if (value >= 5) return 'bg-red-100 dark:bg-red-900/30 text-red-700 dark:text-red-300'
  if (value >= 3) return 'bg-amber-100 dark:bg-amber-900/30 text-amber-700 dark:text-amber-300'
  return 'bg-green-100 dark:bg-green-900/30 text-green-700 dark:text-green-300'
}

function formatCellValue(value) {
  if (metric.value === 'hours') return value > 0 ? `${value.toFixed(1)}h` : ''
  return value > 0 ? value : ''
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

function getMemberTotal(member) {
  return (member.periods || []).reduce((sum, p) => {
    if (metric.value === 'hours') return sum + (p.hours || 0)
    if (metric.value === 'difficulty') return sum + (p.difficulty || 0)
    return sum + (p.task_count || 0)
  }, 0)
}

function changeGranularity(g) {
  granularity.value = g
  loadTraffic()
}

const presets = [
  { key: 'week', label: 'This Week' },
  { key: 'next-week', label: 'Next Week' },
  { key: 'month', label: 'This Month' },
]

function applyPreset(p) {
  const now = new Date()
  if (p === 'week') {
    startDate.value = formatDate(getMonday(now))
    endDate.value = formatDate(getSunday(now))
  } else if (p === 'next-week') {
    const next = new Date(now.getTime() + 7 * 86400000)
    startDate.value = formatDate(getMonday(next))
    endDate.value = formatDate(getSunday(next))
  } else if (p === 'month') {
    startDate.value = formatDate(new Date(now.getFullYear(), now.getMonth(), 1))
    endDate.value = formatDate(new Date(now.getFullYear(), now.getMonth() + 1, 0))
  }
  loadTraffic()
}
</script>

<template>
  <div>
    <!-- Controls -->
    <div class="flex flex-wrap items-center gap-3 mb-4">
      <div class="flex bg-surface-200 dark:bg-surface-700 rounded-full p-0.5">
        <button
          v-for="p in presets"
          :key="p.key"
          class="px-3 py-1 rounded-full text-xs font-medium transition-colors"
          :class="'text-surface-500 dark:text-surface-400 hover:text-surface-700'"
          @click="applyPreset(p.key)"
        >
          {{ p.label }}
        </button>
      </div>

      <div class="flex items-center gap-2">
        <input type="date" v-model="startDate" class="text-xs px-3 py-1.5 rounded-full border border-surface-300 dark:border-surface-600 bg-white dark:bg-surface-700 text-surface-800 dark:text-surface-200" @change="loadTraffic" />
        <span class="text-surface-400 text-xs">to</span>
        <input type="date" v-model="endDate" class="text-xs px-3 py-1.5 rounded-full border border-surface-300 dark:border-surface-600 bg-white dark:bg-surface-700 text-surface-800 dark:text-surface-200" @change="loadTraffic" />
      </div>

      <div class="flex items-center gap-2">
        <span class="text-xs text-surface-500">Role:</span>
        <select
          v-model="selectedRoleSlug"
          class="text-xs px-2 py-1.5 rounded-lg border border-surface-300 dark:border-surface-600 bg-white dark:bg-surface-700 text-surface-800 dark:text-surface-200 max-w-[10rem]"
        >
          <option value="">All roles</option>
          <option v-for="r in hubRoles" :key="r.id" :value="r.slug">{{ r.name }}</option>
        </select>
      </div>

      <div class="w-px h-6 bg-surface-200 dark:bg-surface-700"></div>

      <div class="flex items-center gap-2">
        <span class="text-xs text-surface-500">View:</span>
        <div class="flex bg-surface-200 dark:bg-surface-700 rounded-full p-0.5">
          <button
            v-for="g in [{ key: 'day', label: 'Daily' }, { key: 'week', label: 'Weekly' }]"
            :key="g.key"
            class="px-3 py-1 rounded-full text-xs font-medium transition-colors"
            :class="granularity === g.key
              ? 'bg-white dark:bg-surface-600 text-surface-800 dark:text-surface-100 shadow-sm'
              : 'text-surface-500 dark:text-surface-400'"
            @click="changeGranularity(g.key)"
          >
            {{ g.label }}
          </button>
        </div>
      </div>

      <div class="flex items-center gap-2">
        <span class="text-xs text-surface-500">Metric:</span>
        <div class="flex bg-surface-200 dark:bg-surface-700 rounded-full p-0.5">
          <button
            v-for="m in [
              { key: 'task_count', label: 'Tasks', icon: 'task' },
              { key: 'hours', label: 'Hours', icon: 'timer' },
              { key: 'difficulty', label: 'Weight', icon: 'fitness_center' },
            ]"
            :key="m.key"
            class="px-3 py-1 rounded-full text-xs font-medium transition-colors flex items-center gap-1"
            :class="metric === m.key
              ? 'bg-white dark:bg-surface-600 text-surface-800 dark:text-surface-100 shadow-sm'
              : 'text-surface-500 dark:text-surface-400'"
            @click="metric = m.key"
          >
            <span class="material-symbols-rounded text-[14px]">{{ m.icon }}</span>
            {{ m.label }}
          </button>
        </div>
      </div>
    </div>

    <!-- Loading -->
    <div v-if="loading" class="flex items-center justify-center py-16">
      <div class="animate-spin rounded-full h-8 w-8 border-2 border-primary-500 border-t-transparent"></div>
    </div>

    <!-- Empty -->
    <div v-else-if="members.length === 0" class="text-center py-16 text-surface-400">
      <span class="material-symbols-rounded text-5xl mb-3 block">grid_on</span>
      <p>No traffic data for this period</p>
    </div>

    <!-- Traffic grid -->
    <div v-else class="bg-white dark:bg-surface-800 rounded-2xl border border-surface-200 dark:border-surface-700 overflow-hidden">
      <div class="overflow-x-auto">
        <table class="w-full text-sm">
          <thead>
            <tr class="border-b border-surface-200 dark:border-surface-700">
              <th class="text-left px-4 py-2 text-xs font-semibold text-surface-500 uppercase tracking-wide w-44 sticky left-0 bg-white dark:bg-surface-800 z-10">Member</th>
              <th
                v-for="col in periodColumns"
                :key="col.key"
                class="text-center px-2 py-2 text-[10px] font-semibold uppercase tracking-wide whitespace-nowrap"
                :class="{
                  'text-primary-600 dark:text-primary-400 bg-primary-50 dark:bg-primary-900/20': col.isToday,
                  'text-surface-400 bg-surface-50/50 dark:bg-surface-700/20': col.isWeekend && !col.isToday,
                  'text-surface-500': !col.isToday && !col.isWeekend,
                }"
              >
                {{ col.label }}
              </th>
              <th class="text-center px-3 py-2 text-xs font-semibold text-surface-500 uppercase tracking-wide">Total</th>
            </tr>
          </thead>
          <tbody>
            <tr
              v-for="member in members"
              :key="member.email"
              class="border-b border-surface-100 dark:border-surface-700/50 last:border-0"
            >
              <td class="px-4 py-2 sticky left-0 bg-white dark:bg-surface-800 z-10">
                <div class="flex items-center gap-2">
                  <div class="w-6 h-6 rounded-full flex items-center justify-center text-[10px] font-bold text-white shrink-0" :class="getColor(member.email)">
                    {{ getInitials(member.email) }}
                  </div>
                  <div class="min-w-0">
                    <span class="text-xs font-medium text-surface-700 dark:text-surface-300 truncate block">{{ getName(member.email) }}</span>
                    <div v-if="(member.roles || []).length" class="flex flex-wrap gap-0.5 mt-0.5">
                      <span
                        v-for="slug in member.roles"
                        :key="slug"
                        class="text-[8px] px-1 py-0 rounded bg-surface-100 dark:bg-surface-700 text-surface-500"
                      >{{ slug }}</span>
                    </div>
                  </div>
                </div>
              </td>
              <td
                v-for="col in periodColumns"
                :key="col.key"
                class="text-center px-2 py-2"
                :class="[
                  getCellColor(getCellValue(member, col.key)),
                  col.isWeekend && !getCellValue(member, col.key) ? 'bg-surface-50/50 dark:bg-surface-700/20' : '',
                ]"
              >
                <span class="text-xs font-medium">{{ formatCellValue(getCellValue(member, col.key)) }}</span>
              </td>
              <td class="text-center px-3 py-2 font-bold text-surface-700 dark:text-surface-300">
                {{ metric === 'hours' ? getMemberTotal(member).toFixed(1) + 'h' : getMemberTotal(member) }}
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Legend -->
    <div class="flex items-center gap-4 mt-3 px-2">
      <span class="text-[10px] text-surface-400 uppercase font-semibold">Load:</span>
      <div class="flex items-center gap-1.5">
        <span class="w-3 h-2 rounded-sm bg-green-100 dark:bg-green-900/30"></span>
        <span class="text-[10px] text-surface-500">Low</span>
      </div>
      <div class="flex items-center gap-1.5">
        <span class="w-3 h-2 rounded-sm bg-amber-100 dark:bg-amber-900/30"></span>
        <span class="text-[10px] text-surface-500">Medium</span>
      </div>
      <div class="flex items-center gap-1.5">
        <span class="w-3 h-2 rounded-sm bg-red-100 dark:bg-red-900/30"></span>
        <span class="text-[10px] text-surface-500">High</span>
      </div>
    </div>
  </div>
</template>
