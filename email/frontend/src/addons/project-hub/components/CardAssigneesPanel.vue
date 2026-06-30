<script setup>
import { ref, onMounted, computed, watch } from 'vue'
import { useProjectHubStore } from '@/addons/project-hub/stores/projectHub'
import { useBoardsStore } from '@/addons/kanban-boards/stores/boards'
import { useColleaguesStore } from '@/addons/team/stores/colleagues'
import { fetchRoleStatuses } from '@/addons/project-hub/services/projectHubRoleApi'
import AssigneeStatusBadge from './AssigneeStatusBadge.vue'
import UserAvatar from '@/components/shared/UserAvatar.vue'

const props = defineProps({
  cardId: { type: Number, required: true },
  boardId: { type: Number, default: null },
  checklistItems: { type: Array, default: () => [] },
})

const hubStore = useProjectHubStore()
const boardsStore = useBoardsStore()
const colleaguesStore = useColleaguesStore()

const assignees = ref([])
const subtaskAssignees = ref([])
const loading = ref(false)
const showPicker = ref(false)
const searchQuery = ref('')
const roleStatusCache = ref({})
const expandedAssignee = ref(null)

const allMembers = computed(() => {
  const all = (colleaguesStore.colleagues || []).map(c => ({
    user_email: c.email,
    email: c.email,
    display_name: c.display_name || c.email?.split('@')[0],
  }))
  if (all.length > 0) return all
  return boardsStore.currentMembers || []
})

function normalizeEmail(email) {
  return (email || '').toLowerCase().trim()
}

const assignedEmails = computed(() => new Set(assignees.value.map(a => normalizeEmail(a.user_email))))

const supplementalSubtaskAssignees = computed(() => {
  const directEmails = assignedEmails.value
  const merged = new Map()

  for (const assignee of subtaskAssignees.value) {
    const email = normalizeEmail(assignee?.user_email)
    if (!email || directEmails.has(email)) continue

    if (!merged.has(email)) {
      merged.set(email, {
        id: `subtask-${email}`,
        user_email: email,
        role: 'subtask_assignee',
        status: 'assigned',
        isSubtaskOnly: true,
        subtask_count: 0,
        subtask_done_count: 0,
      })
    }

    const entry = merged.get(email)
    entry.subtask_count += 1
    if ((assignee?.status || '') === 'done') {
      entry.subtask_done_count += 1
    }
  }

  return Array.from(merged.values()).map(entry => ({
    ...entry,
    status: entry.subtask_done_count >= entry.subtask_count
      ? 'done'
      : entry.subtask_done_count > 0
        ? 'working'
        : 'assigned',
  }))
})

const filteredMembers = computed(() => {
  return allMembers.value.filter(m => {
    const email = m.user_email || m.email
    if (assignedEmails.value.has(email)) return false
    if (searchQuery.value) {
      const q = searchQuery.value.toLowerCase()
      return email.toLowerCase().includes(q) || (m.display_name || '').toLowerCase().includes(q)
    }
    return true
  })
})

const availableGroups = computed(() => {
  const groups = colleaguesStore.groups || []
  if (!groups.length) return []
  return groups.filter(g => {
    if (searchQuery.value && !g.name.toLowerCase().includes(searchQuery.value.toLowerCase())) return false
    const gm = (colleaguesStore.colleagues || []).filter(c => c.group_ids?.includes(g.id))
    return gm.some(c => !assignedEmails.value.has(c.email))
  }).map(g => ({
    ...g,
    unassignedCount: (colleaguesStore.colleagues || []).filter(c => c.group_ids?.includes(g.id) && !assignedEmails.value.has(c.email)).length,
  }))
})

const statusColors = {
  working: 'bg-blue-500',
  assigned: 'bg-surface-400',
  review: 'bg-amber-500',
  blocked: 'bg-red-500',
  done: 'bg-green-500',
  admin: 'bg-primary-500',
}

const boardOwnerAssignee = computed(() => {
  const board = boardsStore.currentBoard
  if (!board || (props.boardId && Number(board.id) !== Number(props.boardId))) return null

  const ownerEmail = (board.owner_email || '').toLowerCase()
  if (!ownerEmail) return null
  if (assignees.value.some(a => (a.user_email || '').toLowerCase() === ownerEmail)) return null

  return {
    id: `admin-${ownerEmail}`,
    user_email: ownerEmail,
    role: 'admin',
    status: 'admin',
    isVirtualAdmin: true,
  }
})

const visibleAssignees = computed(() => {
  const merged = [...assignees.value, ...supplementalSubtaskAssignees.value]
  return boardOwnerAssignee.value
    ? [boardOwnerAssignee.value, ...merged]
    : merged
})

const expandedData = computed(() => visibleAssignees.value.find(a => a.id === expandedAssignee.value))

onMounted(async () => {
  await ensureBoardContext()
  await loadAssignees()
  colleaguesStore.init()
  if (!colleaguesStore.groups?.length) colleaguesStore.fetchGroups()
})

watch(() => [props.cardId, props.boardId, (props.checklistItems || []).map(item => item?.id).join(',')], async () => {
  expandedAssignee.value = null
  showPicker.value = false
  await ensureBoardContext()
  await loadAssignees()
})

async function ensureBoardContext() {
  if (!props.boardId) return
  if (Number(boardsStore.currentBoard?.id) === Number(props.boardId)) return
  try {
    await boardsStore.fetchBoard(props.boardId, { silent: true })
  } catch (err) {
    console.error('Failed to load board context for assignees:', err)
  }
}

async function loadAssignees() {
  loading.value = true
  try {
    assignees.value = await hubStore.fetchCardAssignees(props.cardId)
    const subtaskIds = [...new Set((props.checklistItems || []).map(item => Number(item?.id)).filter(Boolean))]
    if (subtaskIds.length > 0) {
      // ONE HTTP call for ALL subtask assignees instead of N parallel ones.
      const { flat } = await hubStore.fetchCardAssigneesBatch(subtaskIds)
      subtaskAssignees.value = flat.filter(Boolean)
    } else {
      subtaskAssignees.value = []
    }
  } finally {
    loading.value = false
  }
}

async function addAssignee(email) {
  try {
    await hubStore.addAssignee(props.cardId, email)
    assignees.value = hubStore.cardAssignees[props.cardId] || []
    searchQuery.value = ''
  } catch (err) {
    console.error('Failed to add assignee:', err)
  }
}

async function assignGroup(groupId) {
  const groupMembers = (colleaguesStore.colleagues || []).filter(c => c.group_ids?.includes(groupId))
  const emailsToAdd = groupMembers
    .map(m => m.email)
    .filter(email => email && !assignedEmails.value.has(email))
  if (emailsToAdd.length === 0) {
    searchQuery.value = ''
    return
  }
  try {
    await hubStore.addAssigneesBatch(props.cardId, emailsToAdd)
  } catch (err) {
    console.error('Failed to assign group:', err)
  }
  assignees.value = hubStore.cardAssignees[props.cardId] || []
  searchQuery.value = ''
}

async function removeAssignee(assigneeId) {
  if (String(assigneeId).startsWith('admin-') || String(assigneeId).startsWith('subtask-')) return
  try {
    await hubStore.removeAssignee(assigneeId, props.cardId)
    assignees.value = hubStore.cardAssignees[props.cardId] || []
    expandedAssignee.value = null
  } catch (err) {
    console.error('Failed to remove assignee:', err)
  }
}

async function getRoleStatuses(roleSlug) {
  if (roleStatusCache.value[roleSlug]) return roleStatusCache.value[roleSlug]
  try {
    const roles = (await import('@/addons/project-hub/services/projectHubRoleApi')).fetchRoles
    const allRoles = await roles()
    const role = allRoles.find(r => r.slug === roleSlug)
    if (role) {
      const statuses = await fetchRoleStatuses(role.id)
      roleStatusCache.value[roleSlug] = statuses
      return statuses
    }
  } catch { /* fallback */ }
  return []
}

function getStatusesForAssignee(assignee) {
  return roleStatusCache.value[assignee?.role || 'assignee'] || []
}

async function setDifficultyWeight(assignee, weight) {
  if (!assignee || assignee.isVirtualAdmin || assignee.isSubtaskOnly) return
  const w = Math.min(5, Math.max(1, Number(weight) || 1))
  try {
    const updated = await hubStore.updateAssignee(assignee.id, { difficulty_weight: w })
    if (updated) {
      const idx = assignees.value.findIndex(a => a.id === assignee.id)
      if (idx >= 0) assignees.value[idx] = updated
      if (expandedAssignee.value === assignee.id) {
        const ex = assignees.value.find(a => a.id === assignee.id)
        if (ex) Object.assign(assignee, ex)
      }
    }
  } catch (err) {
    console.error('Failed to set difficulty weight:', err)
  }
}

function difficultyWeight(assignee) {
  const v = Number(assignee?.difficulty_weight)
  return v >= 1 && v <= 5 ? v : 1
}

async function cycleStatus(assignee) {
  if (!assignee) return
  if (assignee.isVirtualAdmin || assignee.isSubtaskOnly) return
  const roleSlug = assignee.role || 'assignee'
  let statuses = await getRoleStatuses(roleSlug)
  const slugs = statuses.length > 0
    ? statuses.map(s => s.slug)
    : ['assigned', 'working', 'review', 'done', 'blocked']
  const currentIdx = slugs.indexOf(assignee.status)
  const nextStatus = slugs[(currentIdx + 1) % slugs.length]
  try {
    const updated = await hubStore.changeAssigneeStatus(assignee.id, nextStatus)
    if (updated) {
      const idx = assignees.value.findIndex(a => a.id === assignee.id)
      if (idx >= 0) assignees.value[idx] = updated
    }
  } catch (err) {
    console.error('Failed to change status:', err)
  }
}

function formatTime(seconds) {
  if (!seconds) return '0m'
  const h = Math.floor(seconds / 3600)
  const m = Math.floor((seconds % 3600) / 60)
  return h > 0 ? `${h}h ${m}m` : `${m}m`
}

function toggleExpand(id) {
  expandedAssignee.value = expandedAssignee.value === id ? null : id
}

function getDisplayName(email) {
  if (!email) return ''
  const c = (colleaguesStore.colleagues || []).find(col => col.email === email)
  return c?.display_name || email.split('@')[0]
}

function getRoleLabel(assignee) {
  if (assignee?.isVirtualAdmin) return 'board admin'
  if (assignee?.isSubtaskOnly) return 'subtask assignee'
  return assignee?.role || 'assignee'
}

watch(showPicker, (v) => { if (!v) searchQuery.value = '' })
</script>

<template>
  <div>
    <!-- Compact header -->
    <div class="flex items-center gap-2 mb-2">
      <span class="material-symbols-rounded text-[16px] text-surface-400">group</span>
      <span class="text-xs font-semibold text-surface-500 dark:text-surface-400 uppercase tracking-wide">Assignees</span>
      <span v-if="visibleAssignees.length" class="text-[10px] text-surface-400 bg-surface-100 dark:bg-surface-700 px-1.5 py-0.5 rounded-full">{{ visibleAssignees.length }}</span>
    </div>

    <!-- Loading -->
    <div v-if="loading" class="flex items-center gap-2 py-2">
      <div class="animate-spin rounded-full h-4 w-4 border-2 border-primary-500 border-t-transparent"></div>
      <span class="text-xs text-surface-400">Loading...</span>
    </div>

    <template v-else>
      <!-- Compact avatar row -->
      <div class="flex flex-wrap items-center gap-1.5 mb-2">
        <div
          v-for="assignee in visibleAssignees"
          :key="assignee.id"
          class="relative cursor-pointer transition-transform hover:scale-110"
          :class="expandedAssignee === assignee.id ? 'ring-2 ring-primary-400 rounded-full' : ''"
          @click="toggleExpand(assignee.id)"
          :title="getDisplayName(assignee.user_email) + ' (' + getRoleLabel(assignee) + ')'"
        >
          <UserAvatar :email="assignee.user_email" size="sm" :show-presence="true" />
          <span
            class="absolute -bottom-0.5 -left-0.5 w-2.5 h-2.5 rounded-full border-2 border-white dark:border-surface-900"
            :class="statusColors[assignee.status] || 'bg-surface-400'"
          ></span>
        </div>

        <!-- Add button -->
        <button
          class="w-8 h-8 rounded-full border-2 border-dashed border-surface-300 dark:border-surface-600 flex items-center justify-center hover:border-primary-400 hover:text-primary-500 transition-colors text-surface-400"
          @click.stop="showPicker = !showPicker; expandedAssignee = null"
          title="Add assignee"
        >
          <span class="material-symbols-rounded text-[16px]">add</span>
        </button>
      </div>

      <!-- Expanded assignee detail card -->
      <div
        v-if="expandedData"
        class="flex items-center gap-2 px-2.5 py-2 rounded-xl bg-surface-50 dark:bg-surface-800/50 mb-2 animate-[fadeIn_0.15s_ease]"
      >
        <UserAvatar :email="expandedData.user_email" size="sm" :show-presence="true" />
        <div class="flex-1 min-w-0">
          <div class="text-sm font-medium text-surface-700 dark:text-surface-300 truncate">
            {{ getDisplayName(expandedData.user_email) }}
          </div>
          <div class="text-[10px] text-surface-400 capitalize">{{ getRoleLabel(expandedData) }}</div>
        </div>

        <template v-if="!expandedData.isVirtualAdmin && !expandedData.isSubtaskOnly">
          <AssigneeStatusBadge
            :status="expandedData.status"
            :custom-statuses="getStatusesForAssignee(expandedData)"
            :clickable="true"
            size="xs"
            @click="cycleStatus(expandedData)"
          />
        </template>
        <span
          v-else
          class="px-2 py-1 rounded-full text-[10px] font-medium bg-primary-50 dark:bg-primary-900/20 text-primary-600 dark:text-primary-400 shrink-0"
        >
          Admin
        </span>

        <span
          v-if="!expandedData.isVirtualAdmin && !expandedData.isSubtaskOnly && expandedData.time_spent_seconds > 0"
          class="text-[10px] text-surface-400 shrink-0 flex items-center gap-0.5"
        >
          <span class="material-symbols-rounded text-[12px]">timer</span>
          {{ formatTime(expandedData.time_spent_seconds) }}
        </span>

        <div
          v-if="!expandedData.isVirtualAdmin && !expandedData.isSubtaskOnly"
          class="flex items-center gap-0.5 shrink-0"
          title="Difficulty (1–5)"
        >
          <button
            v-for="n in 5"
            :key="'dw-' + n"
            type="button"
            class="w-2 h-2 rounded-full transition-transform hover:scale-125"
            :class="n <= difficultyWeight(expandedData) ? 'bg-amber-500' : 'bg-surface-300 dark:bg-surface-600'"
            @click.stop="setDifficultyWeight(expandedData, n)"
          />
        </div>

        <span
          v-if="expandedData.isSubtaskOnly"
          class="text-[10px] text-surface-400 shrink-0"
        >
          {{ expandedData.subtask_done_count }}/{{ expandedData.subtask_count }} subtasks done
        </span>

        <button
          v-if="!expandedData.isVirtualAdmin && !expandedData.isSubtaskOnly"
          class="material-symbols-rounded text-[16px] text-surface-400 hover:text-red-500 transition-colors shrink-0"
          @click.stop="removeAssignee(expandedData.id)"
          title="Remove"
        >close</button>
      </div>

      <!-- Member picker -->
      <div v-if="showPicker" class="border border-surface-200 dark:border-surface-700 rounded-xl bg-white dark:bg-surface-800 shadow-lg overflow-hidden mb-1">
        <div class="p-2">
          <input
            v-model="searchQuery"
            class="w-full text-sm px-3 py-1.5 rounded-lg border border-surface-200 dark:border-surface-600 bg-surface-50 dark:bg-surface-700 text-surface-800 dark:text-surface-200 outline-none focus:ring-1 focus:ring-primary-500"
            placeholder="Search members or groups..."
            autofocus
            @keydown.escape="showPicker = false"
          />
        </div>
        <div class="max-h-48 overflow-y-auto">
          <!-- Groups -->
          <template v-if="availableGroups.length > 0">
            <div class="px-3 py-1 text-[10px] font-semibold text-surface-400 uppercase tracking-wide">Groups</div>
            <button
              v-for="group in availableGroups"
              :key="'g-' + group.id"
              class="w-full flex items-center gap-2 px-3 py-1.5 text-sm hover:bg-primary-50 dark:hover:bg-primary-900/20 text-surface-700 dark:text-surface-300"
              @click="assignGroup(group.id)"
            >
              <span class="material-symbols-rounded text-[16px] text-primary-500">groups</span>
              <span class="truncate flex-1 text-left">{{ group.name }}</span>
              <span class="text-[10px] text-surface-400">+{{ group.unassignedCount }}</span>
            </button>
          </template>

          <div v-if="filteredMembers.length > 0 && availableGroups.length > 0" class="px-3 py-1 text-[10px] font-semibold text-surface-400 uppercase tracking-wide">Members</div>
          <div v-if="filteredMembers.length === 0 && availableGroups.length === 0" class="px-3 py-3 text-sm text-surface-400 text-center">
            No available members
          </div>
          <button
            v-for="member in filteredMembers"
            :key="member.user_email || member.email"
            class="w-full flex items-center gap-2 px-3 py-1.5 text-sm hover:bg-surface-100 dark:hover:bg-surface-700 text-surface-700 dark:text-surface-300"
            @click="addAssignee(member.user_email || member.email)"
          >
            <UserAvatar :email="member.user_email || member.email" size="xs" />
            <span class="truncate">{{ member.display_name || (member.user_email || member.email).split('@')[0] }}</span>
          </button>
        </div>
        <div class="p-1.5 border-t border-surface-200 dark:border-surface-700">
          <button class="w-full text-xs text-surface-400 hover:text-surface-600 dark:hover:text-surface-300 text-center py-1" @click="showPicker = false">
            Close
          </button>
        </div>
      </div>
    </template>
  </div>
</template>
