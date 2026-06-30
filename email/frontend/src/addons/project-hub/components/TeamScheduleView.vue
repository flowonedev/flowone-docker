<script setup>
import { ref, computed, onMounted } from 'vue'
import { useColleaguesStore } from '@/addons/team/stores/colleagues'
import api from '@/services/api'

const emit = defineEmits(['open-card'])
const colleaguesStore = useColleaguesStore()

const loading = ref(false)
const members = ref([])

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
  await loadSchedule()
})

async function loadSchedule() {
  loading.value = true
  try {
    const { data } = await api.get('/project-hub/workload/team-schedule', {
      params: { start_date: startDate.value, end_date: endDate.value },
    })
    members.value = data.members || []
  } finally {
    loading.value = false
  }
}

const dayColumns = computed(() => {
  const start = new Date(startDate.value)
  const end = new Date(endDate.value)
  const cols = []
  const cur = new Date(start)
  while (cur <= end) {
    const key = formatDate(cur)
    cols.push({
      key,
      dayName: cur.toLocaleDateString(undefined, { weekday: 'short' }),
      dayNum: cur.getDate(),
      monthName: cur.toLocaleDateString(undefined, { month: 'short' }),
      isToday: key === formatDate(new Date()),
      isWeekend: cur.getDay() === 0 || cur.getDay() === 6,
    })
    cur.setDate(cur.getDate() + 1)
  }
  return cols
})

function getMemberTasks(member, dayKey) {
  return (member.days || {})[dayKey] || []
}

function getUnscheduledTasks(member) {
  return (member.days || {}).__unscheduled || []
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

function getAvatarColor(email) {
  const c = colleaguesStore.colleagueByEmail[email?.toLowerCase()]
  if (c) return colleaguesStore.getColleagueColor(c)
  return 'bg-primary-500'
}

const statusColors = {
  working: 'bg-green-500',
  assigned: 'bg-amber-400',
  review: 'bg-blue-500',
  blocked: 'bg-red-500',
  done: 'bg-surface-400',
}

const statusBg = {
  working: 'bg-green-50 dark:bg-green-900/20 border-green-200 dark:border-green-800',
  assigned: 'bg-amber-50 dark:bg-amber-900/20 border-amber-200 dark:border-amber-800',
  review: 'bg-blue-50 dark:bg-blue-900/20 border-blue-200 dark:border-blue-800',
  blocked: 'bg-red-50 dark:bg-red-900/20 border-red-200 dark:border-red-800',
  done: 'bg-surface-50 dark:bg-surface-800/40 border-surface-200 dark:border-surface-700',
}

function getStatusColor(status) {
  return statusColors[status] || 'bg-surface-400'
}

function getStatusBg(status) {
  return statusBg[status] || 'bg-surface-50 dark:bg-surface-800 border-surface-200 dark:border-surface-700'
}

function getMemberDayCount(member) {
  let total = 0
  for (const col of dayColumns.value) {
    total += getMemberTasks(member, col.key).length
  }
  return total
}

const presets = [
  { key: 'week', label: 'This Week' },
  { key: 'next-week', label: 'Next Week' },
  { key: 'month', label: 'This Month' },
  { key: '2-weeks', label: '2 Weeks' },
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
  } else if (p === '2-weeks') {
    startDate.value = formatDate(getMonday(now))
    const end = new Date(getMonday(now))
    end.setDate(end.getDate() + 13)
    endDate.value = formatDate(end)
  }
  loadSchedule()
}

function openCardById(task) {
  emit('open-card', { card_id: task.card_id, board_id: task.board_id })
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
          class="px-3 py-1 rounded-full text-xs font-medium transition-colors text-surface-500 dark:text-surface-400 hover:text-surface-700 dark:hover:text-surface-300"
          @click="applyPreset(p.key)"
        >
          {{ p.label }}
        </button>
      </div>

      <div class="flex items-center gap-2">
        <input
          type="date"
          v-model="startDate"
          class="text-xs px-3 py-1.5 rounded-full border border-surface-300 dark:border-surface-600 bg-white dark:bg-surface-700 text-surface-800 dark:text-surface-200"
          @change="loadSchedule"
        />
        <span class="text-surface-400 text-xs">to</span>
        <input
          type="date"
          v-model="endDate"
          class="text-xs px-3 py-1.5 rounded-full border border-surface-300 dark:border-surface-600 bg-white dark:bg-surface-700 text-surface-800 dark:text-surface-200"
          @change="loadSchedule"
        />
      </div>
    </div>

    <!-- Loading -->
    <div v-if="loading" class="flex items-center justify-center py-16">
      <div class="animate-spin rounded-full h-8 w-8 border-2 border-primary-500 border-t-transparent"></div>
    </div>

    <!-- Empty -->
    <div v-else-if="members.length === 0" class="text-center py-16 text-surface-400">
      <span class="material-symbols-rounded text-5xl mb-3 block">event_busy</span>
      <p>No scheduled tasks for this period</p>
    </div>

    <!-- Schedule grid -->
    <div v-else class="bg-white dark:bg-surface-800 rounded-2xl border border-surface-200 dark:border-surface-700 overflow-hidden">
      <div class="overflow-x-auto">
        <table class="w-full border-collapse" style="min-width: 800px;">
          <thead>
            <tr class="border-b border-surface-200 dark:border-surface-700">
              <th class="text-left px-3 py-2.5 text-xs font-semibold text-surface-500 uppercase tracking-wide w-40 sticky left-0 bg-white dark:bg-surface-800 z-10 border-r border-surface-200 dark:border-surface-700">
                Member
              </th>
              <th
                v-for="col in dayColumns"
                :key="col.key"
                class="text-center px-1.5 py-2.5 text-[10px] font-semibold uppercase tracking-wide whitespace-nowrap border-r border-surface-100 dark:border-surface-700/50 last:border-r-0"
                :class="{
                  'bg-primary-50 dark:bg-primary-900/20 text-primary-600 dark:text-primary-400': col.isToday,
                  'bg-surface-50/50 dark:bg-surface-700/20 text-surface-400': col.isWeekend && !col.isToday,
                  'text-surface-500': !col.isToday && !col.isWeekend,
                }"
                :style="{ minWidth: '120px' }"
              >
                <div>{{ col.dayName }}</div>
                <div class="text-[11px] font-bold">{{ col.monthName }} {{ col.dayNum }}</div>
              </th>
            </tr>
          </thead>
          <tbody>
            <tr
              v-for="member in members"
              :key="member.email"
              class="border-b border-surface-100 dark:border-surface-700/50 last:border-0 align-top"
            >
              <!-- Member name -->
              <td class="px-3 py-2 sticky left-0 bg-white dark:bg-surface-800 z-10 border-r border-surface-200 dark:border-surface-700">
                <div class="flex items-center gap-2">
                  <div
                    class="w-7 h-7 rounded-full flex items-center justify-center text-[10px] font-bold text-white shrink-0"
                    :class="getAvatarColor(member.email)"
                  >
                    {{ getInitials(member.email) }}
                  </div>
                  <div class="min-w-0">
                    <span class="text-xs font-medium text-surface-700 dark:text-surface-300 truncate block">
                      {{ getName(member.email) }}
                    </span>
                    <span class="text-[10px] text-surface-400">
                      {{ getMemberDayCount(member) }} tasks
                    </span>
                  </div>
                </div>
              </td>

              <!-- Day cells -->
              <td
                v-for="col in dayColumns"
                :key="col.key"
                class="px-1 py-1 border-r border-surface-100 dark:border-surface-700/50 last:border-r-0"
                :class="{
                  'bg-primary-50/30 dark:bg-primary-900/10': col.isToday,
                  'bg-surface-50/30 dark:bg-surface-700/10': col.isWeekend && !col.isToday,
                }"
                :style="{ minWidth: '120px' }"
              >
                <div class="space-y-0.5 min-h-[28px]">
                  <button
                    v-for="task in getMemberTasks(member, col.key)"
                    :key="`${col.key}-${task.card_id}`"
                    type="button"
                    class="w-full text-left px-1.5 py-1 rounded-lg border text-[11px] leading-tight truncate transition-colors hover:shadow-sm cursor-pointer"
                    :class="[getStatusBg(task.status), task.completed ? 'opacity-50 line-through' : '']"
                    :title="`${task.title}\n${task.board_name} / ${task.list_name}\nStatus: ${task.status || 'assigned'}`"
                    @click="openCardById(task)"
                  >
                    <div class="flex items-center gap-1 min-w-0">
                      <span
                        class="w-1.5 h-1.5 rounded-full shrink-0"
                        :class="getStatusColor(task.status || 'assigned')"
                      ></span>
                      <span class="truncate">{{ task.title }}</span>
                    </div>
                  </button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Legend -->
    <div class="flex items-center gap-4 mt-3 px-2">
      <span class="text-[10px] text-surface-400 uppercase font-semibold">Status:</span>
      <div v-for="(color, status) in statusColors" :key="status" class="flex items-center gap-1.5">
        <span class="w-2.5 h-2.5 rounded-full" :class="color"></span>
        <span class="text-[10px] text-surface-500 capitalize">{{ status }}</span>
      </div>
    </div>
  </div>
</template>
