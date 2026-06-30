<script setup>
import { ref, computed } from 'vue'
import { useAuthStore } from '@/stores/auth'
import UserAvatar from '@/components/shared/UserAvatar.vue'

const props = defineProps({
  tasks: { type: Array, default: () => [] },
  boards: { type: Array, default: () => [] },
})

const emit = defineEmits(['open-card'])
const authStore = useAuthStore()
const currentEmail = computed(() => authStore.userEmail?.toLowerCase() || '')

const searchQuery = ref('')
const sortKey = ref('due_date')
const sortDir = ref('asc')
const filterStatus = ref('all')
const filterBoard = ref('all')
const groupBy = ref('board')

const columns = [
  { key: 'title', label: 'Task', sortable: true },
  { key: 'board_name', label: 'Board', sortable: true },
  { key: 'list_name', label: 'List', sortable: true },
  { key: 'status', label: 'Status', sortable: true },
  { key: 'assigned_to', label: 'Assignee', sortable: true },
  { key: 'due_date', label: 'Due Date', sortable: true },
]

const statusOptions = computed(() => {
  const names = new Set()
  for (const t of props.tasks) {
    if (t.list_name) names.add(t.list_name)
  }
  return Array.from(names).sort()
})

const boardOptions = computed(() => {
  const map = new Map()
  for (const t of props.tasks) {
    if (t.board_id && t.board_name) map.set(t.board_id, t.board_name)
  }
  return Array.from(map.entries()).map(([id, name]) => ({ id, name }))
})

const filteredTasks = computed(() => {
  let list = props.tasks
  if (searchQuery.value.trim()) {
    const q = searchQuery.value.toLowerCase()
    list = list.filter(t =>
      (t.title || '').toLowerCase().includes(q) ||
      getTaskAssignees(t).some(e => e.includes(q)) ||
      (t.board_name || '').toLowerCase().includes(q)
    )
  }
  if (filterStatus.value !== 'all') {
    list = list.filter(t => t.list_name === filterStatus.value)
  }
  if (filterBoard.value !== 'all') {
    list = list.filter(t => String(t.board_id) === String(filterBoard.value))
  }
  return list
})

const sortedTasks = computed(() => {
  const dir = sortDir.value === 'asc' ? 1 : -1
  return [...filteredTasks.value].sort((a, b) => {
    let av, bv
    switch (sortKey.value) {
      case 'due_date':
        av = a.due_date ? new Date(a.due_date).getTime() : Infinity
        bv = b.due_date ? new Date(b.due_date).getTime() : Infinity
        return dir * (av - bv)
      case 'status':
        av = a.list_name || ''
        bv = b.list_name || ''
        return dir * av.localeCompare(bv)
      default:
        av = (a[sortKey.value] || '').toString().toLowerCase()
        bv = (b[sortKey.value] || '').toString().toLowerCase()
        return dir * av.localeCompare(bv)
    }
  })
})

const groupedTasks = computed(() => {
  if (groupBy.value === 'none') return [{ key: 'all', label: 'All Tasks', tasks: sortedTasks.value }]

  const map = new Map()
  for (const t of sortedTasks.value) {
    let key, label, owner = null
    switch (groupBy.value) {
      case 'board':
        key = t.board_id || 'none'
        label = t.board_name || 'Unknown Board'
        owner = t.board_owner || null
        break
      case 'status':
        key = t.list_name || 'none'
        label = t.list_name || 'Unknown'
        break
      case 'assignee': {
        const emails = getTaskAssignees(t)
        if (emails.length > 0) {
          for (const em of emails) {
            const k = em
            const lb = em.split('@')[0]
            if (!map.has(k)) map.set(k, { key: k, label: lb, owner: null, tasks: [] })
            map.get(k).tasks.push(t)
          }
          continue
        }
        key = '__unassigned__'
        label = 'Unassigned'
        break
      }
      default:
        key = 'all'
        label = 'All Tasks'
    }
    if (!map.has(key)) map.set(key, { key, label, owner, tasks: [] })
    map.get(key).tasks.push(t)
  }
  return Array.from(map.values())
})

function toggleSort(key) {
  if (sortKey.value === key) {
    sortDir.value = sortDir.value === 'asc' ? 'desc' : 'asc'
  } else {
    sortKey.value = key
    sortDir.value = 'asc'
  }
}

function isOverdue(task) {
  return !task.completed && task.due_date && new Date(task.due_date) < new Date()
}

function formatDate(dateStr) {
  if (!dateStr) return '--'
  const d = new Date(dateStr)
  const now = new Date()
  const diff = now - d
  const absDiff = Math.abs(diff)
  if (absDiff < 86400000) return 'Today'
  if (diff > 0 && diff < 86400000 * 2) return 'Yesterday'
  if (diff < 0 && absDiff < 86400000 * 2) return 'Tomorrow'
  const days = Math.round(diff / 86400000)
  if (days > 0 && days <= 30) return `${days}d ago`
  if (days < 0 && Math.abs(days) <= 30) return d.toLocaleDateString(undefined, { month: 'short', day: 'numeric' })
  return d.toLocaleDateString(undefined, { month: 'short', day: 'numeric' })
}

const statusColors = {
  done: 'bg-green-100 dark:bg-green-900/30 text-green-700 dark:text-green-400',
  progress: 'bg-blue-100 dark:bg-blue-900/30 text-blue-700 dark:text-blue-400',
  review: 'bg-amber-100 dark:bg-amber-900/30 text-amber-700 dark:text-amber-400',
  fallback: 'bg-surface-100 dark:bg-surface-700 text-surface-600 dark:text-surface-300',
}

function getStatusClass(listName) {
  const lower = (listName || '').toLowerCase()
  if (lower.includes('done') || lower.includes('complete')) return statusColors.done
  if (lower.includes('progress') || lower.includes('doing')) return statusColors.progress
  if (lower.includes('review') || lower.includes('testing')) return statusColors.review
  return statusColors.fallback
}

const avatarPalette = [
  'bg-primary-500 text-white', 'bg-amber-500 text-white', 'bg-teal-500 text-white',
  'bg-pink-500 text-white', 'bg-indigo-500 text-white', 'bg-orange-500 text-white',
]

function getAvatarColor(email) {
  if (!email) return 'bg-surface-300 text-surface-600'
  let hash = 0
  for (let i = 0; i < email.length; i++) hash = email.charCodeAt(i) + ((hash << 5) - hash)
  return avatarPalette[Math.abs(hash) % avatarPalette.length]
}

function getInitial(email) {
  if (!email) return '?'
  return email.charAt(0).toUpperCase()
}

function getTaskAssignees(task) {
  const emails = new Set()
  if (task.assigned_to) emails.add(task.assigned_to.toLowerCase())
  if (task.card_assignees?.length) {
    for (const a of task.card_assignees) {
      if (a.user_email) emails.add(a.user_email.toLowerCase())
    }
  }
  return [...emails]
}
</script>

<template>
  <div class="flex-1 flex flex-col overflow-hidden">
    <!-- Toolbar -->
    <div class="flex items-center gap-2 px-5 py-2.5 border-b border-surface-200 dark:border-surface-700 bg-surface-50/50 dark:bg-surface-900/50 shrink-0 flex-wrap">
      <!-- Search -->
      <div class="relative flex-1 min-w-[180px] max-w-xs">
        <span class="material-symbols-rounded absolute left-2.5 top-1/2 -translate-y-1/2 text-[16px] text-surface-400">search</span>
        <input
          v-model="searchQuery"
          type="text"
          placeholder="Search tasks..."
          class="w-full pl-8 pr-3 py-1.5 text-xs bg-white dark:bg-surface-800 border border-surface-200 dark:border-surface-700 rounded-lg text-surface-800 dark:text-surface-200 outline-none focus:border-primary-500 placeholder:text-surface-400"
        />
      </div>

      <!-- Filter: Status -->
      <select
        v-model="filterStatus"
        class="px-2.5 py-1.5 text-xs bg-white dark:bg-surface-800 border border-surface-200 dark:border-surface-700 rounded-lg text-surface-700 dark:text-surface-300 outline-none"
      >
        <option value="all">All statuses</option>
        <option v-for="s in statusOptions" :key="s" :value="s">{{ s }}</option>
      </select>

      <!-- Filter: Board -->
      <select
        v-if="boardOptions.length > 1"
        v-model="filterBoard"
        class="px-2.5 py-1.5 text-xs bg-white dark:bg-surface-800 border border-surface-200 dark:border-surface-700 rounded-lg text-surface-700 dark:text-surface-300 outline-none"
      >
        <option value="all">All boards</option>
        <option v-for="b in boardOptions" :key="b.id" :value="b.id">{{ b.name }}</option>
      </select>

      <!-- Group by -->
      <div class="flex items-center gap-1 ml-auto">
        <span class="text-[10px] text-surface-400 uppercase font-bold tracking-wide">Group:</span>
        <select
          v-model="groupBy"
          class="px-2 py-1.5 text-xs bg-white dark:bg-surface-800 border border-surface-200 dark:border-surface-700 rounded-lg text-surface-700 dark:text-surface-300 outline-none"
        >
          <option value="board">Board</option>
          <option value="status">Status</option>
          <option value="assignee">Assignee</option>
          <option value="none">None</option>
        </select>
      </div>

      <!-- Count -->
      <span class="text-[10px] text-surface-400 font-medium">{{ filteredTasks.length }} tasks</span>
    </div>

    <!-- Table -->
    <div class="flex-1 overflow-auto">
      <div v-if="filteredTasks.length === 0" class="text-center py-12 text-surface-400">
        <span class="material-symbols-rounded text-4xl mb-2 block">table_rows</span>
        <p class="text-sm">No tasks match your filters</p>
      </div>

      <div v-for="group in groupedTasks" :key="group.key" class="mb-1">
        <!-- Group header -->
        <div v-if="groupBy !== 'none'" class="flex items-center gap-2 px-5 py-1.5 bg-surface-100/80 dark:bg-surface-800/60 border-b border-surface-200/60 dark:border-surface-700/60 sticky top-0 z-[5]">
          <span class="material-symbols-rounded text-[14px] text-surface-400">
            {{ groupBy === 'board' ? 'view_kanban' : groupBy === 'status' ? 'circle' : 'person' }}
          </span>
          <span class="text-[11px] font-bold uppercase tracking-wider text-surface-500 dark:text-surface-400">{{ group.label }}</span>
          <span
            v-if="group.owner && group.owner.toLowerCase() !== currentEmail"
            class="inline-flex items-center gap-0.5 text-[9px] text-amber-500 dark:text-amber-400"
            :title="'Shared by ' + group.owner"
          ><span class="material-symbols-rounded text-[11px]">group</span>{{ group.owner.split('@')[0] }}</span>
          <span class="text-[10px] text-surface-400 bg-surface-200/60 dark:bg-surface-700 px-1.5 py-0.5 rounded-full font-semibold">{{ group.tasks.length }}</span>
        </div>

        <!-- Column headers -->
        <div class="grid grid-cols-[1fr_120px_100px_100px_100px_100px] gap-1 px-5 py-1 text-[10px] font-bold uppercase tracking-wider text-surface-400 border-b border-surface-200 dark:border-surface-700">
          <button
            v-for="col in columns"
            :key="col.key"
            class="flex items-center gap-0.5 hover:text-surface-600 dark:hover:text-surface-300 transition-colors"
            :class="col.key === 'title' ? '' : 'justify-center'"
            @click="col.sortable && toggleSort(col.key)"
          >
            {{ col.label }}
            <span v-if="sortKey === col.key" class="material-symbols-rounded text-[11px]">
              {{ sortDir === 'asc' ? 'arrow_upward' : 'arrow_downward' }}
            </span>
          </button>
        </div>

        <!-- Rows -->
        <div
          v-for="task in group.tasks"
          :key="task.id"
          class="grid grid-cols-[1fr_120px_100px_100px_100px_100px] gap-1 items-center px-5 py-2 border-b border-surface-100 dark:border-surface-700/40 hover:bg-primary-50/30 dark:hover:bg-primary-900/10 cursor-pointer transition-colors group/row"
          @click="emit('open-card', task)"
        >
          <!-- Task name -->
          <div class="flex items-center gap-2 min-w-0">
            <div class="w-4 h-4 rounded border-2 shrink-0 flex items-center justify-center"
              :class="task.completed ? 'bg-green-500 border-green-500' : 'border-surface-300 dark:border-surface-600'">
              <span v-if="task.completed" class="material-symbols-rounded text-white text-[10px]">check</span>
            </div>
            <span class="text-[13px] truncate" :class="task.completed ? 'line-through text-surface-400' : 'text-surface-800 dark:text-surface-200'">{{ task.title }}</span>
          </div>

          <!-- Board -->
          <div class="text-center">
            <span class="text-[11px] text-surface-500 dark:text-surface-400 truncate block">{{ task.board_name }}</span>
            <span
              v-if="task.board_owner && task.board_owner.toLowerCase() !== currentEmail"
              class="text-[9px] text-amber-500 dark:text-amber-400 truncate block leading-tight"
              :title="'Shared by ' + task.board_owner"
            >{{ task.board_owner.split('@')[0] }}</span>
          </div>

          <!-- List -->
          <div class="flex justify-center">
            <span class="text-[10px] font-medium px-2 py-0.5 rounded-full" :class="getStatusClass(task.list_name)">{{ task.list_name }}</span>
          </div>

          <!-- Status (completed badge) -->
          <div class="flex justify-center">
            <span class="text-[10px] font-medium px-2 py-0.5 rounded-full"
              :class="task.completed
                ? 'bg-green-100 dark:bg-green-900/30 text-green-600 dark:text-green-400'
                : 'bg-surface-100 dark:bg-surface-700 text-surface-500'">
              {{ task.completed ? 'Done' : 'Open' }}
            </span>
          </div>

          <!-- Assignee -->
          <div class="flex justify-center">
            <div v-if="getTaskAssignees(task).length" class="flex -space-x-1.5">
              <UserAvatar v-for="email in getTaskAssignees(task).slice(0, 3)" :key="email"
                :email="email" size="xs" :show-presence="true"
                class="border-2 border-white dark:border-surface-800 rounded-full" />
              <div v-if="getTaskAssignees(task).length > 3" class="w-5 h-5 rounded-full border-2 border-white dark:border-surface-800 bg-surface-200 dark:bg-surface-700 flex items-center justify-center text-[8px] font-bold text-surface-500">+{{ getTaskAssignees(task).length - 3 }}</div>
            </div>
            <span v-else class="text-surface-300 text-[10px]">--</span>
          </div>

          <!-- Due date -->
          <div class="text-center">
            <span v-if="task.due_date" class="text-[11px]"
              :class="isOverdue(task) ? 'text-red-500 dark:text-red-400 font-semibold' : 'text-surface-500'">
              {{ formatDate(task.due_date) }}
            </span>
            <span v-else class="text-surface-300 text-[10px]">--</span>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>
