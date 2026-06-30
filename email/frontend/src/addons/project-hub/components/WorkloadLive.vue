<script setup>
import { ref, computed } from 'vue'
import { useColleaguesStore } from '@/addons/team/stores/colleagues'
import { useAuthStore } from '@/stores/auth'

const props = defineProps({
  members: { type: Array, default: () => [] },
  loading: { type: Boolean, default: false },
})

const colleaguesStore = useColleaguesStore()
const authStore = useAuthStore()
const currentUserEmail = computed(() => authStore.user?.email || authStore.userEmail)

const selectedMember = ref(null)
const selectedGroupId = ref(null)

const statusColors = {
  assigned: 'bg-surface-300 dark:bg-surface-600',
  working: 'bg-blue-500',
  review: 'bg-yellow-500',
  done: 'bg-green-500',
  blocked: 'bg-red-500',
}

const liveMembers = computed(() => {
  const allColleagues = colleaguesStore.colleagues || []
  const dbMap = new Map(props.members.map(m => [m.email?.toLowerCase(), m]))
  const myEmail = currentUserEmail.value?.toLowerCase()

  let list = allColleagues.map(colleague => {
    const email = (colleague.email || '').toLowerCase()
    const presenceStatus = colleaguesStore.getColleagueStatus(email)
    const currentView = colleaguesStore.getColleagueCurrentView(email)
    const isSelf = email === myEmail
    const isOnline = isSelf || presenceStatus === 'online' || presenceStatus === 'active'
    const isAway = !isSelf && presenceStatus === 'away'
    const dbData = dbMap.get(email) || {}

    return {
      email,
      name: colleague.display_name || colleague.name || email.split('@')[0],
      group_ids: colleague.group_ids || [],
      isOnline,
      isAway,
      isSelf,
      presenceStatus: isSelf ? 'active' : presenceStatus,
      currentView: isSelf ? 'Workload Planner' : currentView,
      current_card: dbData.current_card || null,
      time_spent_today: dbData.time_spent_today || 0,
      last_activity_at: dbData.last_activity_at || null,
      live_activity: dbData.live_activity || null,
      cards: dbData.cards || [],
    }
  })

  if (selectedMember.value) {
    list = list.filter(m => m.email === selectedMember.value)
  }
  if (selectedGroupId.value) {
    list = list.filter(m => m.group_ids?.includes(selectedGroupId.value))
  }

  return list.sort((a, b) => {
    if (a.isOnline && !b.isOnline) return -1
    if (!a.isOnline && b.isOnline) return 1
    if (a.isAway && !b.isAway) return -1
    if (!a.isAway && b.isAway) return 1
    return a.name.localeCompare(b.name)
  })
})

const colleagues = computed(() => colleaguesStore.sortedColleagues || [])
const groups = computed(() => colleaguesStore.sortedGroups || [])

function getInitials(email) {
  const colleague = colleaguesStore.colleagueByEmail[email?.toLowerCase()]
  if (colleague) return colleaguesStore.getInitials(colleague)
  return (email || '?').charAt(0).toUpperCase()
}

function getAvatarColor(email) {
  const colleague = colleaguesStore.colleagueByEmail[email?.toLowerCase()]
  if (colleague) return colleaguesStore.getColleagueColor(colleague)
  return 'bg-primary-500'
}

function formatTime(seconds) {
  if (!seconds) return '0m'
  const h = Math.floor(seconds / 3600)
  const m = Math.floor((seconds % 3600) / 60)
  return h > 0 ? `${h}h ${m}m` : `${m}m`
}

const activityIcons = {
  document_edit: 'edit_document',
  document_open: 'description',
  drive_edit: 'edit_document',
  local_watch: 'edit_document',
  website_work: 'language',
  board_task: 'task_alt',
  board_view: 'dashboard',
  card_view: 'task_alt',
  email_read: 'mail',
  email_compose: 'edit_note',
  calendar_event: 'event',
  drive_browse: 'folder_open',
  mood_board_view: 'dashboard_customize',
  mood_board_edit: 'dashboard_customize',
  client_call: 'call',
  manual_entry: 'more_time',
}

function activityIcon(kind) {
  return activityIcons[kind] || 'bolt'
}

function minutesAgo(at) {
  if (!at) return null
  const diff = Math.max(0, Math.round((Date.now() - new Date(at.replace(' ', 'T'))) / 60000))
  return diff === 0 ? 'now' : `${diff}m ago`
}
</script>

<template>
  <div>
    <!-- Live filters -->
    <div class="flex items-center gap-3 mb-4">
      <select
        v-model="selectedMember"
        class="text-xs px-3 py-1.5 rounded-full border border-surface-200 dark:border-surface-600 bg-white dark:bg-surface-700 text-surface-700 dark:text-surface-300"
      >
        <option :value="null">All Members</option>
        <option v-for="c in colleagues" :key="c.email" :value="c.email">
          {{ c.display_name || c.email }}
        </option>
      </select>
      <select
        v-model="selectedGroupId"
        class="text-xs px-3 py-1.5 rounded-full border border-surface-200 dark:border-surface-600 bg-white dark:bg-surface-700 text-surface-700 dark:text-surface-300"
      >
        <option :value="null">All Groups</option>
        <option v-for="g in groups" :key="g.id" :value="g.id">
          {{ g.name }}
        </option>
      </select>
      <button
        v-if="selectedMember || selectedGroupId"
        class="px-3 py-1.5 rounded-full text-xs text-surface-400 hover:text-surface-600 transition-colors"
        @click="selectedMember = null; selectedGroupId = null"
      >
        Clear
      </button>
    </div>

    <!-- Loading -->
    <div v-if="loading" class="flex items-center justify-center py-20">
      <div class="animate-spin rounded-full h-8 w-8 border-2 border-primary-500 border-t-transparent"></div>
    </div>

    <div v-else-if="liveMembers.length === 0" class="text-center py-16 text-surface-400 dark:text-surface-500">
      <span class="material-symbols-rounded text-5xl mb-3 block">person_off</span>
      <p>No team members found</p>
    </div>

    <div v-else class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
      <div
        v-for="member in liveMembers"
        :key="member.email"
        class="bg-white dark:bg-surface-800 rounded-2xl border transition-all"
        :class="member.isOnline
          ? 'border-green-200 dark:border-green-800 shadow-sm'
          : member.isAway
            ? 'border-amber-200 dark:border-amber-800'
            : 'border-surface-200 dark:border-surface-700 opacity-60'"
      >
        <div class="p-4">
          <div class="flex items-center gap-3 mb-3">
            <div class="relative">
              <div class="w-10 h-10 rounded-full flex items-center justify-center text-sm font-semibold text-white" :class="getAvatarColor(member.email)">
                {{ getInitials(member.email) }}
              </div>
              <div
                class="absolute -bottom-0.5 -right-0.5 w-3.5 h-3.5 rounded-full border-2 border-white dark:border-surface-800"
                :class="member.isOnline ? 'bg-green-500' : member.isAway ? 'bg-amber-500' : 'bg-surface-400'"
              ></div>
            </div>
            <div class="flex-1 min-w-0">
              <div class="font-medium text-surface-800 dark:text-surface-200">{{ member.name }}</div>
              <div class="text-xs" :class="member.isOnline ? 'text-green-500' : member.isAway ? 'text-amber-500' : 'text-surface-400'">
                {{ member.isOnline ? 'Online' : member.isAway ? 'Away' : 'Offline' }}
              </div>
            </div>
          </div>

          <div v-if="member.currentView && member.isOnline" class="mb-3 px-3 py-2 rounded-xl bg-surface-50 dark:bg-surface-700/50 flex items-center gap-2">
            <span class="material-symbols-rounded text-[14px] text-surface-400">visibility</span>
            <span class="text-xs text-surface-500 dark:text-surface-400 truncate">{{ member.currentView }}</span>
          </div>

          <!-- Real tracked activity (file / website / card) within the last 10 min -->
          <div v-if="member.live_activity" class="mb-3 px-3 py-2 rounded-xl bg-emerald-50 dark:bg-emerald-900/20 border border-emerald-200 dark:border-emerald-800">
            <div class="flex items-center gap-1.5">
              <span class="material-symbols-rounded text-[15px] text-emerald-500">{{ activityIcon(member.live_activity.kind) }}</span>
              <span class="text-sm font-medium text-emerald-700 dark:text-emerald-300 truncate">
                {{ member.live_activity.detail || member.live_activity.card_title || member.live_activity.kind }}
              </span>
            </div>
            <div class="flex items-center justify-between text-xs text-emerald-500 dark:text-emerald-400 mt-0.5">
              <span class="truncate">{{ member.live_activity.client_name || member.live_activity.card_title || '' }}</span>
              <span class="shrink-0 ml-2">{{ minutesAgo(member.live_activity.at) }}</span>
            </div>
          </div>

          <div v-if="member.current_card" class="mb-3 px-3 py-2 rounded-xl bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800">
            <div class="text-sm font-medium text-blue-700 dark:text-blue-300">{{ member.current_card.title }}</div>
            <div class="text-xs text-blue-500 dark:text-blue-400 mt-0.5">{{ member.current_card.board_name }}</div>
          </div>

          <div v-if="member.cards?.length > 0 && !member.current_card" class="space-y-1 mb-3">
            <div
              v-for="card in member.cards.slice(0, 3)"
              :key="card.card_id"
              class="flex items-center gap-2 px-2 py-1.5 rounded-lg bg-surface-50 dark:bg-surface-700/50"
            >
              <span class="w-2 h-2 rounded-full shrink-0" :class="statusColors[card.status] || 'bg-surface-300'"></span>
              <span class="text-xs text-surface-600 dark:text-surface-400 truncate">{{ card.title }}</span>
            </div>
            <div v-if="member.cards.length > 3" class="text-[10px] text-surface-400 px-2">
              +{{ member.cards.length - 3 }} more
            </div>
          </div>

          <div class="flex items-center justify-between text-xs text-surface-400">
            <span>Today: {{ formatTime(member.time_spent_today || 0) }}</span>
            <span v-if="member.last_activity_at">{{ new Date(member.last_activity_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) }}</span>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>
