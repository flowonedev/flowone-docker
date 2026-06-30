<script setup>
import { ref, computed, onMounted } from 'vue'
import { useColleaguesStore } from '@/addons/team/stores/colleagues'
import api from '@/services/api'

const emit = defineEmits(['open-card'])
const colleaguesStore = useColleaguesStore()

const loading = ref(false)
const days = ref([])
const selectedMember = ref(null)
const selectedGroupId = ref(null)

const weekOffset = ref(0)

function getMonday(d) {
  const dt = new Date(d)
  const day = dt.getDay()
  const diff = dt.getDate() - day + (day === 0 ? -6 : 1)
  dt.setDate(diff)
  return dt
}

function formatDate(d) {
  return d.toISOString().split('T')[0]
}

const weekStart = computed(() => {
  const mon = getMonday(new Date())
  mon.setDate(mon.getDate() + weekOffset.value * 7)
  return mon
})

const weekEnd = computed(() => {
  const sun = new Date(weekStart.value)
  sun.setDate(sun.getDate() + 6)
  return sun
})

const weekLabel = computed(() => {
  const opts = { month: 'short', day: 'numeric' }
  const start = weekStart.value.toLocaleDateString(undefined, opts)
  const end = weekEnd.value.toLocaleDateString(undefined, opts)
  if (weekOffset.value === 0) return `This Week (${start} – ${end})`
  if (weekOffset.value === -1) return `Last Week (${start} – ${end})`
  return `${start} – ${end}`
})

const totalCompletions = computed(() => days.value.reduce((sum, d) => sum + (d.total || 0), 0))

const colleagues = computed(() => colleaguesStore.sortedColleagues || [])
const groups = computed(() => colleaguesStore.sortedGroups || [])

async function loadCompletions() {
  loading.value = true
  try {
    const params = {
      start_date: formatDate(weekStart.value),
      end_date: formatDate(weekEnd.value),
    }
    if (selectedMember.value) params.member_email = selectedMember.value
    if (selectedGroupId.value) params.group_id = selectedGroupId.value
    const { data } = await api.get('/project-hub/workload/completions', { params })
    days.value = data.days || []
  } catch (err) {
    console.error('[TeamCompletions] load error:', err)
    days.value = []
  } finally {
    loading.value = false
  }
}

function changeWeek(delta) {
  weekOffset.value += delta
  loadCompletions()
}

function dayHeading(dateStr) {
  const d = new Date(dateStr + 'T00:00:00')
  const today = formatDate(new Date())
  const weekday = d.toLocaleDateString(undefined, { weekday: 'long' })
  const date = d.toLocaleDateString(undefined, { month: 'short', day: 'numeric' })
  return dateStr === today ? `Today — ${weekday}, ${date}` : `${weekday}, ${date}`
}

function getName(email) {
  if (email === 'unassigned') return 'Unassigned'
  const c = colleaguesStore.colleagueByEmail[email?.toLowerCase()]
  return c?.display_name || email?.split('@')[0] || email
}

function getInitials(email) {
  if (email === 'unassigned') return '?'
  const c = colleaguesStore.colleagueByEmail[email?.toLowerCase()]
  if (c) return colleaguesStore.getInitials(c)
  return (email || '?').charAt(0).toUpperCase()
}

function getAvatarColor(email) {
  if (email === 'unassigned') return 'bg-surface-400'
  const c = colleaguesStore.colleagueByEmail[email?.toLowerCase()]
  if (c) return colleaguesStore.getColleagueColor(c)
  return 'bg-primary-500'
}

function completedTime(at) {
  if (!at) return ''
  return new Date(at.replace(' ', 'T')).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })
}

function openCard(completion) {
  emit('open-card', { card_id: completion.card_id, board_id: completion.board_id })
}

onMounted(async () => {
  await colleaguesStore.init()
  await loadCompletions()
})
</script>

<template>
  <div>
    <!-- Controls -->
    <div class="flex flex-wrap items-center gap-3 mb-4">
      <div class="flex items-center gap-1 bg-white dark:bg-surface-800 rounded-full border border-surface-200 dark:border-surface-700 px-1 py-0.5">
        <button class="p-1 rounded-full hover:bg-surface-100 dark:hover:bg-surface-700 text-surface-500" @click="changeWeek(-1)">
          <span class="material-symbols-rounded text-lg">chevron_left</span>
        </button>
        <span class="text-xs font-medium text-surface-700 dark:text-surface-300 px-2 min-w-[180px] text-center">{{ weekLabel }}</span>
        <button class="p-1 rounded-full hover:bg-surface-100 dark:hover:bg-surface-700 text-surface-500" :disabled="weekOffset >= 0" :class="{ 'opacity-30': weekOffset >= 0 }" @click="changeWeek(1)">
          <span class="material-symbols-rounded text-lg">chevron_right</span>
        </button>
      </div>

      <select
        v-model="selectedMember"
        class="text-xs px-3 py-1.5 rounded-full border border-surface-200 dark:border-surface-600 bg-white dark:bg-surface-700 text-surface-700 dark:text-surface-300"
        @change="loadCompletions"
      >
        <option :value="null">All Members</option>
        <option v-for="c in colleagues" :key="c.email" :value="c.email">{{ c.display_name || c.email }}</option>
      </select>

      <select
        v-model="selectedGroupId"
        class="text-xs px-3 py-1.5 rounded-full border border-surface-200 dark:border-surface-600 bg-white dark:bg-surface-700 text-surface-700 dark:text-surface-300"
        @change="loadCompletions"
      >
        <option :value="null">All Groups</option>
        <option v-for="g in groups" :key="g.id" :value="g.id">{{ g.name }}</option>
      </select>

      <span class="text-xs text-surface-400 ml-auto">
        {{ totalCompletions }} task{{ totalCompletions === 1 ? '' : 's' }} completed
      </span>
    </div>

    <!-- Loading -->
    <div v-if="loading" class="flex items-center justify-center py-16">
      <div class="animate-spin rounded-full h-8 w-8 border-2 border-primary-500 border-t-transparent"></div>
    </div>

    <!-- Empty -->
    <div v-else-if="days.length === 0" class="text-center py-16 text-surface-400">
      <span class="material-symbols-rounded text-5xl mb-3 block">task_alt</span>
      <p>No completed tasks in this period</p>
    </div>

    <!-- Day-by-day feed -->
    <div v-else class="space-y-5">
      <div v-for="day in days" :key="day.date">
        <div class="flex items-center gap-2 mb-2">
          <h3 class="text-sm font-semibold text-surface-700 dark:text-surface-300">{{ dayHeading(day.date) }}</h3>
          <span class="text-[10px] font-medium px-2 py-0.5 rounded-full bg-green-100 dark:bg-green-900/30 text-green-600 dark:text-green-400">
            {{ day.total }} done
          </span>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-3">
          <div
            v-for="member in day.members"
            :key="`${day.date}-${member.email}`"
            class="bg-white dark:bg-surface-800 rounded-2xl border border-surface-200 dark:border-surface-700 p-3"
          >
            <div class="flex items-center gap-2 mb-2">
              <div class="w-7 h-7 rounded-full flex items-center justify-center text-[10px] font-bold text-white shrink-0" :class="getAvatarColor(member.email)">
                {{ getInitials(member.email) }}
              </div>
              <span class="text-sm font-medium text-surface-700 dark:text-surface-300">{{ getName(member.email) }}</span>
              <span class="text-[10px] text-surface-400 ml-auto">{{ member.completions.length }} task{{ member.completions.length === 1 ? '' : 's' }}</span>
            </div>

            <div class="space-y-1">
              <button
                v-for="completion in member.completions"
                :key="`${completion.card_id}-${completion.kind}`"
                type="button"
                class="w-full text-left px-2.5 py-1.5 rounded-xl bg-surface-50 dark:bg-surface-700/40 hover:bg-surface-100 dark:hover:bg-surface-700 transition-colors"
                @click="openCard(completion)"
              >
                <div class="flex items-center gap-2 min-w-0">
                  <span class="material-symbols-rounded text-[16px] text-green-500 shrink-0">check_circle</span>
                  <span class="text-xs font-medium text-surface-700 dark:text-surface-300 truncate">{{ completion.card_title }}</span>
                  <span class="text-[10px] text-surface-400 shrink-0 ml-auto">{{ completedTime(completion.completed_at) }}</span>
                </div>
                <div class="flex items-center gap-1.5 mt-0.5 pl-6 text-[10px] text-surface-400 truncate">
                  <span>{{ completion.board_name }}</span>
                  <span v-if="completion.client_name">· {{ completion.client_name }}</span>
                  <span v-if="completion.kind === 'assignee_done'" class="text-blue-400">· marked done</span>
                </div>
              </button>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>
