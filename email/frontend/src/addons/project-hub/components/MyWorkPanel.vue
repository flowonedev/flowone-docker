<script setup>
import { ref, onMounted, computed, watch, reactive } from 'vue'
import api from '@/services/api'
import UserAvatar from '@/components/shared/UserAvatar.vue'
import ProjectHubViewIntro from './ProjectHubViewIntro.vue'
import { useAuthStore } from '@/stores/auth'

const emit = defineEmits(['open-card'])
const authStore = useAuthStore()
const currentEmail = computed(() => authStore.userEmail?.toLowerCase() || '')

const loading = ref(false)
const tasks = ref([])
const createdTasks = ref([])
const filter = ref('active')
const activeTab = ref('assigned')
const searchQuery = ref('')
const showFinancials = ref(localStorage.getItem('projecthub_my_work_show_financials') === 'true')
const expandedCards = reactive({})
const expandedSubtasks = reactive({})

onMounted(() => loadMyWork())

watch(showFinancials, (value) => {
  localStorage.setItem('projecthub_my_work_show_financials', value ? 'true' : 'false')
})

const loadError = ref('')

const introSections = [
  {
    items: [
      { icon: 'priority_high', title: 'See what\'s urgent first', body: 'Overdue and due-today cards bubble to the top automatically — no need to open every board to figure out what matters now.' },
      { icon: 'edit_note', title: '"Created by me" tab', body: 'Track every task you delegated. Chase status, nudge assignees, or reassign without leaving this screen.' },
      { icon: 'play_circle', title: 'One-click time tracking', body: 'Open any card and start the timer — minutes get attributed to the task, board, space, and client automatically.' },
      { icon: 'filter_alt', title: 'Triage in seconds', body: 'Active / Overdue / Done chips + a search box. Find the one task that needs attention right now without scrolling.' },
      { icon: 'payments', title: 'Financial view (optional)', body: 'Toggle to see estimated revenue per task when you have permission. Work the highest-paying tasks first.' },
      { icon: 'subdirectory_arrow_right', title: 'Subtasks inline', body: 'Expand any card to see its subtasks with their own assignees, due dates, and tracked time — no extra clicks.' },
    ],
  },
]

const introBenefits = [
  'Nothing falls through the cracks — <strong>overdue items literally surface at the top</strong> every morning.',
  'Replaces the daily standup: <strong>everyone knows their priority list before the meeting starts</strong>.',
  'Time logged here flows straight into <strong>Task Time → invoices</strong> — no double-entry, no end-of-month spreadsheets.',
  'One screen instead of <strong>six Kanban boards</strong> — saves 15–30 minutes per person per day.',
]

async function loadMyWork() {
  loading.value = true
  loadError.value = ''
  try {
    const { data } = await api.get('/project-hub/my-work', { params: { grouping: 'none' } })
    if (data.error) loadError.value = data.error
    tasks.value = data.tasks || []
  } catch (err) {
    loadError.value = err?.response?.data?.error || err.message || 'Request failed'
    tasks.value = []
  } finally {
    loading.value = false
  }
}

async function loadCreated() {
  loading.value = true
  try {
    const { data } = await api.get('/project-hub/my-created')
    createdTasks.value = data.tasks || []
  } finally {
    loading.value = false
  }
}

function switchTab(tab) {
  activeTab.value = tab
  if (tab === 'assigned') loadMyWork()
  else loadCreated()
}

const activeSource = computed(() => {
  if (activeTab.value === 'assigned') {
    if (!Array.isArray(tasks.value)) return []
    if (tasks.value.length > 0 && tasks.value[0]?.cards) return tasks.value.flatMap(g => g.cards || [])
    return tasks.value
  }
  return createdTasks.value
})

const filteredCards = computed(() => {
  let list = activeSource.value
  if (filter.value === 'active') list = list.filter(c => !c.completed)
  if (filter.value === 'done') list = list.filter(c => c.completed)
  if (filter.value === 'overdue') list = list.filter(c => !c.completed && c.due_date && new Date(c.due_date) < new Date())
  if (searchQuery.value.trim()) {
    const q = searchQuery.value.toLowerCase()
    list = list.filter(c =>
      c.title?.toLowerCase().includes(q) ||
      c.board_name?.toLowerCase().includes(q) ||
      c.client_name?.toLowerCase().includes(q) ||
      c.list_name?.toLowerCase().includes(q) ||
      (c.subtasks || []).some(s => s.title?.toLowerCase().includes(q))
    )
  }
  return list
})

const boardGroups = computed(() => {
  const map = new Map()
  for (const card of filteredCards.value) {
    const key = card.board_name || 'No board'
    if (!map.has(key)) map.set(key, [])
    map.get(key).push(card)
  }
  return Array.from(map.entries()).map(([label, cards]) => ({ label, cards }))
})

const stats = computed(() => {
  const all = activeSource.value
  let totalSubtasks = 0
  let doneSubtasks = 0
  for (const c of all) {
    totalSubtasks += (c.subtasks || []).length
    doneSubtasks += (c.subtasks || []).filter(s => s.completed).length
  }
  return {
    cards: all.length,
    subtasks: totalSubtasks,
    doneSubtasks,
    overdue: all.filter(c => !c.completed && c.due_date && new Date(c.due_date) < new Date()).length,
  }
})

function toggleCardExpand(cardId) {
  expandedCards[cardId] = !expandedCards[cardId]
}

function isCardExpanded(cardId) {
  return expandedCards[cardId] !== false
}

function toggleSubtaskExpand(subtaskId) {
  expandedSubtasks[subtaskId] = !expandedSubtasks[subtaskId]
}

function isSubtaskExpanded(subtaskId) {
  return !!expandedSubtasks[subtaskId]
}

function openCard(card) {
  const taskId = Number(card?.card_id || card?.id)
  if (taskId) {
    patchVisibleTasks(taskId, { has_updates: false, last_seen_at: new Date().toISOString() })
  }
  emit('open-card', card)
}

function openSubtaskInCard(card, subtask) {
  emit('open-card', {
    ...card,
    card_id: card.card_id,
    subtask_card_id: subtask.id,
  })
}

function patchVisibleTasks(cardId, changes) {
  tasks.value = tasks.value.map(item => Number(item.card_id) === Number(cardId) ? { ...item, ...changes } : item)
  createdTasks.value = createdTasks.value.map(item => Number(item.card_id) === Number(cardId) ? { ...item, ...changes } : item)
}

function isOverdue(card) {
  return !card.completed && card.due_date && new Date(card.due_date) < new Date()
}

function formatDueDate(date) {
  if (!date) return ''
  const d = new Date(date)
  const now = new Date()
  const diff = Math.floor((d - now) / 86400000)
  if (diff === 0) return 'Today'
  if (diff === 1) return 'Tomorrow'
  if (diff === -1) return 'Yesterday'
  if (diff < -1) return `${Math.abs(diff)}d ago`
  return d.toLocaleDateString(undefined, { month: 'short', day: 'numeric' })
}

function formatTrackedTime(seconds) {
  const totalSeconds = Number(seconds || 0)
  if (totalSeconds <= 0) return '--'
  const hours = Math.floor(totalSeconds / 3600)
  const minutes = Math.floor((totalSeconds % 3600) / 60)
  if (hours > 0) return `${hours}h ${minutes}m`
  if (minutes > 0) return `${minutes}m`
  return `${totalSeconds % 60}s`
}

function getSubtaskAssignees(subtask) {
  if (!subtask.assignee_emails) return []
  return subtask.assignee_emails.split(',').filter(Boolean)
}

function getSubtaskStatusColor(subtask) {
  if (subtask.completed) return 'text-green-500'
  const statuses = (subtask.assignee_statuses || '').split(',')
  if (statuses.includes('blocked')) return 'text-red-500'
  if (statuses.includes('in_progress')) return 'text-blue-500'
  return 'text-surface-400'
}

function getSubtaskStatusIcon(subtask) {
  if (subtask.completed) return 'check_circle'
  const statuses = (subtask.assignee_statuses || '').split(',')
  if (statuses.includes('blocked')) return 'block'
  if (statuses.includes('in_progress')) return 'play_circle'
  return 'radio_button_unchecked'
}

function getTaskAssignees(card) {
  return (card.assignees || []).filter(a => (a.role || 'assignee') === 'assignee')
}

const showFinancialToggle = computed(() => activeSource.value.some(card => card.can_view_financials))

function formatFinancial(card) {
  if (card?.estimated_revenue == null || card?.estimated_revenue === '') return ''
  const amount = Number(card.estimated_revenue)
  const currency = card.financial_currency || 'HUF'
  try {
    return new Intl.NumberFormat(undefined, { style: 'currency', currency, maximumFractionDigits: 0 }).format(amount)
  } catch { return `${amount.toLocaleString()} ${currency}` }
}
</script>

<template>
  <div class="flex-1 overflow-auto">
    <div class="px-6 py-4">
      <ProjectHubViewIntro
        storage-key="ph.intro.my-work.v1"
        icon="task_alt"
        title="My Work — your personal command center"
        summary="Every task assigned to you or created by you, across every Space, sorted urgency-first. Triage your day in one screen."
        :sections="introSections"
        :benefits="introBenefits"
      />

      <!-- Tab switcher -->
      <div class="flex items-center gap-2 mb-4">
        <div class="flex bg-surface-200 dark:bg-surface-700 rounded-full p-0.5">
          <button
            class="px-3 py-1 rounded-full text-xs font-medium transition-colors"
            :class="activeTab === 'assigned'
              ? 'bg-white dark:bg-surface-600 text-surface-800 dark:text-surface-100 shadow-sm'
              : 'text-surface-500 dark:text-surface-400'"
            @click="switchTab('assigned')"
          >
            <span class="material-symbols-rounded text-[14px] align-middle mr-0.5">assignment_ind</span>
            My Tasks
          </button>
          <button
            class="px-3 py-1 rounded-full text-xs font-medium transition-colors"
            :class="activeTab === 'created'
              ? 'bg-white dark:bg-surface-600 text-surface-800 dark:text-surface-100 shadow-sm'
              : 'text-surface-500 dark:text-surface-400'"
            @click="switchTab('created')"
          >
            <span class="material-symbols-rounded text-[14px] align-middle mr-0.5">edit_note</span>
            Created by Me
          </button>
        </div>
      </div>

      <!-- Stats row -->
      <div class="grid grid-cols-4 gap-3 mb-5">
        <div class="bg-white dark:bg-surface-800 rounded-xl border border-surface-200 dark:border-surface-700 p-3 text-center">
          <div class="text-2xl font-bold text-surface-800 dark:text-surface-100">{{ stats.cards }}</div>
          <div class="text-xs text-surface-400">Cards</div>
        </div>
        <div class="bg-white dark:bg-surface-800 rounded-xl border border-surface-200 dark:border-surface-700 p-3 text-center">
          <div class="text-2xl font-bold text-blue-500">{{ stats.subtasks }}</div>
          <div class="text-xs text-surface-400">Total Tasks</div>
        </div>
        <div class="bg-white dark:bg-surface-800 rounded-xl border border-surface-200 dark:border-surface-700 p-3 text-center">
          <div class="text-2xl font-bold text-green-500">{{ stats.doneSubtasks }}</div>
          <div class="text-xs text-surface-400">Completed</div>
        </div>
        <div class="bg-white dark:bg-surface-800 rounded-xl border border-surface-200 dark:border-surface-700 p-3 text-center">
          <div class="text-2xl font-bold" :class="stats.overdue > 0 ? 'text-red-500' : 'text-surface-300'">{{ stats.overdue }}</div>
          <div class="text-xs text-surface-400">Overdue</div>
        </div>
      </div>

      <!-- Toolbar -->
      <div class="flex flex-wrap items-center gap-2 mb-4">
        <button
          v-for="f in [
            { key: 'all', label: 'All', icon: 'list' },
            { key: 'active', label: 'Active', icon: 'radio_button_unchecked' },
            { key: 'overdue', label: 'Overdue', icon: 'warning' },
            { key: 'done', label: 'Done', icon: 'check_circle' },
          ]"
          :key="f.key"
          class="px-3 py-1.5 rounded-full text-xs font-medium transition-colors inline-flex items-center gap-1"
          :class="filter === f.key
            ? 'bg-primary-500 text-white'
            : 'bg-surface-100 dark:bg-surface-700 text-surface-500 dark:text-surface-400 hover:bg-surface-200 dark:hover:bg-surface-600'"
          @click="filter = f.key"
        >
          <span class="material-symbols-rounded text-[14px]">{{ f.icon }}</span>
          {{ f.label }}
        </button>

        <div class="w-px h-5 bg-surface-200 dark:bg-surface-700 mx-1"></div>

        <button
          v-if="showFinancialToggle"
          type="button"
          class="inline-flex items-center gap-1.5 rounded-full px-3 py-1.5 text-xs font-medium transition-colors"
          :class="showFinancials
            ? 'bg-emerald-500 text-white'
            : 'bg-surface-100 dark:bg-surface-700 text-surface-500 dark:text-surface-400 hover:bg-surface-200 dark:hover:bg-surface-600'"
          @click="showFinancials = !showFinancials"
        >
          <span class="material-symbols-rounded text-[14px]">payments</span>
          Financial
        </button>

        <div class="flex-1"></div>

        <div class="relative">
          <span class="material-symbols-rounded text-[16px] text-surface-400 absolute left-2.5 top-1/2 -translate-y-1/2">search</span>
          <input
            v-model="searchQuery"
            type="text"
            placeholder="Search tasks..."
            class="text-xs bg-surface-100 dark:bg-surface-700 border-0 rounded-full pl-8 pr-3 py-1.5 w-48 text-surface-700 dark:text-surface-300 placeholder-surface-400 focus:ring-1 focus:ring-primary-500 focus:w-64 transition-all"
          />
        </div>
      </div>

      <!-- Loading -->
      <div v-if="loading" class="flex items-center justify-center py-12">
        <div class="animate-spin rounded-full h-8 w-8 border-2 border-primary-500 border-t-transparent"></div>
      </div>

      <!-- Error -->
      <div v-else-if="loadError" class="mb-4 px-4 py-3 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-xl text-sm text-red-600 dark:text-red-400">
        <span class="material-symbols-rounded text-[16px] align-middle mr-1">error</span>
        {{ loadError }}
      </div>

      <!-- Empty -->
      <div v-else-if="filteredCards.length === 0" class="text-center py-12 text-surface-400">
        <span class="material-symbols-rounded text-5xl mb-3 block">inbox</span>
        <p class="text-surface-600 dark:text-surface-300 mb-1">No tasks found</p>
      </div>

      <!-- Board > Card > Subtask hierarchy -->
      <div v-else class="space-y-6">
        <div v-for="group in boardGroups" :key="group.label">
          <!-- Board header -->
          <div class="flex items-center gap-2 mb-2 px-1">
            <span class="material-symbols-rounded text-[14px] text-surface-400">dashboard</span>
            <span class="text-xs font-bold uppercase tracking-wider text-surface-500">{{ group.label }}</span>
            <span class="text-[10px] text-surface-400 font-medium bg-surface-100 dark:bg-surface-700 px-1.5 py-0.5 rounded-full">{{ group.cards.length }}</span>
            <div class="flex-1 h-px bg-surface-200 dark:bg-surface-700 ml-2"></div>
          </div>

          <!-- Cards within this board -->
          <div class="space-y-2">
            <div
              v-for="card in group.cards"
              :key="card.card_id"
              class="bg-white dark:bg-surface-800 rounded-xl border border-surface-200 dark:border-surface-700 overflow-hidden"
            >
              <!-- Card header row -->
              <div
                class="flex items-center gap-2 px-4 py-2.5 cursor-pointer hover:bg-surface-50 dark:hover:bg-surface-700/30 transition-colors"
                @click="toggleCardExpand(card.card_id)"
              >
                <button
                  class="shrink-0 w-5 h-5 flex items-center justify-center rounded transition-transform"
                  :class="isCardExpanded(card.card_id) ? 'rotate-90' : ''"
                >
                  <span class="material-symbols-rounded text-[16px] text-surface-400">chevron_right</span>
                </button>

                <span
                  v-if="card.has_updates"
                  class="w-2 h-2 rounded-full bg-amber-400 shrink-0"
                  title="Unseen updates"
                ></span>

                <span
                  class="text-sm font-medium truncate flex-1 min-w-0"
                  :class="card.completed ? 'line-through text-surface-400' : 'text-surface-800 dark:text-surface-100'"
                >{{ card.title }}</span>

                <!-- Card meta pills -->
                <span class="text-[10px] text-surface-400 bg-surface-100 dark:bg-surface-700 px-1.5 py-0.5 rounded-full shrink-0">
                  {{ card.list_name }}
                </span>

                <div v-if="getTaskAssignees(card).length" class="flex -space-x-1.5 shrink-0">
                  <UserAvatar
                    v-for="a in getTaskAssignees(card).slice(0, 3)"
                    :key="a.user_email"
                    :email="a.user_email"
                    size="xs"
                    class="ring-2 ring-white dark:ring-surface-800"
                  />
                </div>

                <span v-if="card.subtask_count" class="text-[10px] text-surface-400 shrink-0">
                  {{ card.subtask_done_count }}/{{ card.subtask_count }} done
                </span>

                <span v-if="card.time_estimate_seconds" class="text-[10px] text-blue-500 shrink-0">
                  {{ formatTrackedTime(card.time_estimate_seconds) }}
                </span>

                <span
                  v-if="card.total_tracked_seconds"
                  class="text-[10px] font-medium shrink-0"
                  :class="card.time_estimate_seconds && card.total_tracked_seconds > card.time_estimate_seconds
                    ? 'text-red-500' : 'text-emerald-500'"
                >{{ formatTrackedTime(card.total_tracked_seconds) }}</span>

                <span
                  v-if="card.due_date"
                  class="text-[10px] px-1.5 py-0.5 rounded-full shrink-0"
                  :class="isOverdue(card) ? 'bg-red-100 dark:bg-red-900/30 text-red-600 dark:text-red-400 font-medium' : 'text-surface-400'"
                >{{ formatDueDate(card.due_date) }}</span>

                <span
                  v-if="showFinancials && card.can_view_financials && formatFinancial(card)"
                  class="text-[10px] font-medium text-emerald-600 dark:text-emerald-400 shrink-0"
                >{{ formatFinancial(card) }}</span>

                <button
                  class="shrink-0 w-6 h-6 flex items-center justify-center rounded-full hover:bg-primary-100 dark:hover:bg-primary-900/30 transition-colors"
                  title="Open card"
                  @click.stop="openCard(card)"
                >
                  <span class="material-symbols-rounded text-[16px] text-primary-500">open_in_new</span>
                </button>
              </div>

              <!-- Subtask rows -->
              <div v-if="isCardExpanded(card.card_id) && (card.subtasks || []).length > 0">
                <div class="border-t border-surface-100 dark:border-surface-700/50">
                  <template v-for="st in card.subtasks" :key="st.id">
                    <!-- Subtask row -->
                    <div
                      class="flex items-center gap-2 pl-10 pr-4 py-1.5 border-b border-surface-50 dark:border-surface-700/30 hover:bg-surface-50 dark:hover:bg-surface-700/20 cursor-pointer transition-colors"
                      @click="st.has_children ? toggleSubtaskExpand(st.id) : openSubtaskInCard(card, st)"
                    >
                      <!-- Expand toggle for subtasks with children -->
                      <button
                        v-if="st.has_children"
                        class="shrink-0 w-4 h-4 flex items-center justify-center transition-transform"
                        :class="isSubtaskExpanded(st.id) ? 'rotate-90' : ''"
                        @click.stop="toggleSubtaskExpand(st.id)"
                      >
                        <span class="material-symbols-rounded text-[14px] text-purple-400">chevron_right</span>
                      </button>

                      <!-- Status icon -->
                      <span
                        class="material-symbols-rounded text-[16px] shrink-0"
                        :class="getSubtaskStatusColor(st)"
                      >{{ getSubtaskStatusIcon(st) }}</span>

                      <!-- Title -->
                      <span
                        class="text-[13px] truncate flex-1 min-w-0"
                        :class="st.completed ? 'line-through text-surface-400' : 'text-surface-700 dark:text-surface-300'"
                      >{{ st.title }}</span>

                      <!-- Children count badge -->
                      <span
                        v-if="st.has_children"
                        class="text-[10px] text-purple-400 bg-purple-50 dark:bg-purple-900/20 px-1.5 py-0.5 rounded-full shrink-0"
                      >{{ (st.children || []).length }} sub</span>

                      <!-- Assignee avatars -->
                      <div v-if="getSubtaskAssignees(st).length" class="flex -space-x-1 shrink-0">
                        <UserAvatar
                          v-for="email in getSubtaskAssignees(st).slice(0, 2)"
                          :key="email"
                          :email="email"
                          size="xs"
                          class="ring-1 ring-white dark:ring-surface-800"
                        />
                      </div>

                      <!-- Due date -->
                      <span
                        v-if="st.due_date"
                        class="text-[10px] shrink-0"
                        :class="!st.completed && st.due_date && new Date(st.due_date) < new Date() ? 'text-red-500 font-medium' : 'text-surface-400'"
                      >{{ formatDueDate(st.due_date) }}</span>

                      <!-- Open button for subtasks with children -->
                      <button
                        v-if="st.has_children"
                        class="shrink-0 w-5 h-5 flex items-center justify-center rounded-full hover:bg-purple-100 dark:hover:bg-purple-900/30 transition-colors"
                        title="Open task"
                        @click.stop="openSubtaskInCard(card, st)"
                      >
                        <span class="material-symbols-rounded text-[14px] text-purple-400">open_in_new</span>
                      </button>
                    </div>

                    <!-- Sub-subtask rows (level 2) -->
                    <template v-if="st.has_children && isSubtaskExpanded(st.id)">
                      <div
                        v-for="child in (st.children || [])"
                        :key="child.id"
                        class="flex items-center gap-2 pl-16 pr-4 py-1 border-b border-surface-50 dark:border-surface-700/20 last:border-0 hover:bg-purple-50/30 dark:hover:bg-purple-900/10 cursor-pointer transition-colors"
                        @click="openSubtaskInCard(card, st)"
                      >
                        <span
                          class="material-symbols-rounded text-[14px] shrink-0"
                          :class="child.completed ? 'text-green-500' : 'text-surface-400'"
                        >{{ child.completed ? 'check_circle' : 'radio_button_unchecked' }}</span>

                        <span
                          class="text-[12px] truncate flex-1 min-w-0"
                          :class="child.completed ? 'line-through text-surface-400' : 'text-surface-600 dark:text-surface-400'"
                        >{{ child.title }}</span>

                        <div v-if="getSubtaskAssignees(child).length" class="flex -space-x-1 shrink-0">
                          <UserAvatar
                            v-for="email in getSubtaskAssignees(child).slice(0, 2)"
                            :key="email"
                            :email="email"
                            size="xs"
                            class="ring-1 ring-white dark:ring-surface-800"
                          />
                        </div>

                        <span
                          v-if="child.due_date"
                          class="text-[10px] shrink-0"
                          :class="!child.completed && new Date(child.due_date) < new Date() ? 'text-red-500 font-medium' : 'text-surface-400'"
                        >{{ formatDueDate(child.due_date) }}</span>
                      </div>
                    </template>
                  </template>
                </div>
              </div>

              <!-- No subtasks message -->
              <div
                v-else-if="isCardExpanded(card.card_id) && !(card.subtasks || []).length"
                class="border-t border-surface-100 dark:border-surface-700/50 px-10 py-3"
              >
                <span class="text-xs text-surface-400">No tasks in this card</span>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>
