<script setup>
import { ref, onMounted, onUnmounted, computed, watch, defineAsyncComponent } from 'vue'
import { useRouter } from 'vue-router'
import { useProjectHubStore } from '@/addons/project-hub/stores/projectHub'
import { useBoardsStore } from '@/addons/kanban-boards/stores/boards'
import { useColleaguesStore } from '@/addons/team/stores/colleagues'
import { useSubtaskTrackedTime } from '@/addons/project-hub/composables/useSubtaskTrackedTime'
import { useSubtaskLinkedCards } from '@/addons/project-hub/composables/useSubtaskLinkedCards'
import { useAuthStore } from '@/stores/auth'
import { useToastStore } from '@/stores/toast'
import api from '@/services/api'
import UserAvatar from '@/components/shared/UserAvatar.vue'
import SubtaskAssignMenu from './SubtaskAssignMenu.vue'

const ManualTimeEntryDialog = defineAsyncComponent(() => import('./ManualTimeEntryDialog.vue'))
const SubtaskDeleteModal = defineAsyncComponent(() => import('./SubtaskDeleteModal.vue'))

const props = defineProps({
  cardId: { type: Number, required: true },
  fullTaskVisibility: { type: Boolean, default: false },
  isBoardOwner: { type: Boolean, default: false },
  // Pass an explicit Boolean from the parent card; null/undefined means
  // "unknown" — falls back to boardsStore.allCards lookup. We use `null` as
  // the type so Vue accepts both Boolean values and null without warnings.
  parentCompleted: { type: null, default: null },
})

const hubStore = useProjectHubStore()
const boardsStore = useBoardsStore()
const colleaguesStore = useColleaguesStore()
const authStore = useAuthStore()
const toast = useToastStore()
const router = useRouter()
const { resolveTrackedCardId, getTrackedSeconds, getPerUserTrackedTime, primeTrackedTime, formatTrackedTime } = useSubtaskTrackedTime(hubStore)
const {
  linkedCardMap, linkedCardStatusMap,
  getAssigneeRows, getRawCompletionMeta,
  getLinkedCard, hasLinkedCard, getLinkedCardStatus,
  getDisplayAssigneeRows, getCompletionMeta,
  getDisplayName, getAssigneeSummary,
  getLinkedProgressLabel, getSubtaskProgressPercent,
  loadSubtaskAssignees, refreshLinkedCardStatus,
  loadLinkedCardStatuses, loadLinks, resetStatus,
} = useSubtaskLinkedCards(hubStore, boardsStore, colleaguesStore)

const subtasks = ref([])
const loading = ref(false)
const showAddForm = ref(false)
const newTitle = ref('')
const expanded = ref(true)
const assignDropdown = ref(null)
const deleteConfirm = ref({ show: false, subtask: null })
const bulkCreating = ref(false)
const manualTimeSubtask = ref(null)
// Guards against infinite loops when WS CARD_UPDATED events come back to us
// (parent ref isn't always available via boardsStore.allCards, e.g. in Work Hub
// where no board is preloaded — so we track our own pushed state).
const lastSyncedDesired = ref(null)
const isUpdatingParent = ref(false)

const currentEmail = computed(() => authStore.userEmail?.toLowerCase() || '')
const canManageAllStatuses = computed(() => {
  const role = boardsStore.currentBoard?.user_role
  if (role === 'owner' || role === 'editor') return true

  const ownerEmail = (boardsStore.currentBoard?.owner_email || '').toLowerCase()
  return !!ownerEmail && ownerEmail === currentEmail.value
})
const completedCount = computed(() => subtasks.value.filter(s => getCompletionMeta(s).isComplete).length)
const totalCount = computed(() => subtasks.value.length)
const visibleCompletedCount = computed(() => visibleSubtasks.value.filter(s => getCompletionMeta(s).isComplete).length)
const visibleTotalCount = computed(() => visibleSubtasks.value.length)
const progressPercent = computed(() => visibleTotalCount.value > 0 ? Math.round((visibleCompletedCount.value / visibleTotalCount.value) * 100) : 0)

const isOwner = computed(() => {
  if (props.isBoardOwner) return true
  const role = boardsStore.currentBoard?.user_role
  if (role === 'owner') return true
  const ownerEmail = (boardsStore.currentBoard?.owner_email || '').toLowerCase()
  return !!ownerEmail && ownerEmail === currentEmail.value
})

const visibleSubtasks = computed(() => {
  if (isOwner.value || props.fullTaskVisibility) return subtasks.value
  return subtasks.value.filter(s => {
    const assignees = getAssignees(s).map(e => e.toLowerCase())
    return assignees.includes(currentEmail.value)
  })
})

const cardAssigneeEmails = computed(() => {
  const assignees = hubStore.cardAssignees?.[props.cardId] || []
  return new Set(assignees.map(a => a.user_email))
})

const membersList = computed(() => {
  const all = (colleaguesStore.colleagues || []).map(c => ({ user_email: c.email, email: c.email }))
  const source = all.length > 0 ? all : (boardsStore.currentMembers || [])
  // Card assignees first, then everyone else
  const assignees = source.filter(m => cardAssigneeEmails.value.has(m.user_email || m.email))
  const others = source.filter(m => !cardAssigneeEmails.value.has(m.user_email || m.email))
  return [...assignees, ...others]
})

function getLegacyAssignees(subtask) {
  if (!subtask.assigned_to) return []
  return subtask.assigned_to.split(',').map(e => e.trim()).filter(Boolean)
}

function getAssignees(subtask) {
  const rows = getAssigneeRows(subtask)
  if (rows.length) return rows.map(assignee => assignee.user_email)
  return getLegacyAssignees(subtask)
}

function isAssigned(subtask, email) {
  return getAssignees(subtask).includes(email)
}

function isCurrentUser(email) {
  return (email || '').toLowerCase() === currentEmail.value
}

function getTrackedTimeLabel(subtask) {
  const trackedSeconds = getTrackedSeconds(subtask, linkedCardMap.value)
  if (trackedSeconds <= 0) return ''

  return isCardSubtask(subtask)
    ? `Tracked on card: ${formatTrackedTime(trackedSeconds)}`
    : `Tracked: ${formatTrackedTime(trackedSeconds)}`
}

function getTrackedTimeCell(subtask) {
  const trackedSeconds = getTrackedSeconds(subtask, linkedCardMap.value)
  return trackedSeconds > 0 ? formatTrackedTime(trackedSeconds) : '--'
}

function getUserTimeBreakdown(subtask) {
  return getPerUserTrackedTime(subtask, linkedCardMap.value)
}

function shortName(email) {
  if (!email) return ''
  return email.split('@')[0]
}

function openManualTimeEntry(subtask) {
  manualTimeSubtask.value = subtask
}

async function handleManualTimeSaved() {
  manualTimeSubtask.value = null
  await primeTrackedTime(subtasks.value, linkedCardMap.value)
}

function getManualTimeCardId(subtask) {
  return resolveTrackedCardId(subtask, linkedCardMap.value)
}

function canToggleAssigneeStatus(assignee) {
  return canManageAllStatuses.value || isCurrentUser(assignee?.user_email)
}

function getAssigneeStatusLabel(assignee) {
  if (!assignee?.status) return 'Assigned'
  return assignee.status.charAt(0).toUpperCase() + assignee.status.slice(1)
}

async function syncSubtaskCompletion(subtask) {
  const meta = getCompletionMeta(subtask)
  const desiredCompleted = meta.isAssigneeDriven ? meta.isComplete : !!subtask.completed

  if (!!subtask.completed === desiredCompleted) return

  try {
    await boardsStore.updateCard(subtask.id, { completed: desiredCompleted })
    subtask.completed = desiredCompleted
  } catch (err) {
    console.error('Failed to sync subtask completion:', err)
  }
}

async function toggleAssignee(subtask, email) {
  const existing = getAssigneeRows(subtask).find(assignee => (assignee.user_email || '').toLowerCase() === email.toLowerCase())

  try {
    if (existing) {
      await hubStore.removeAssignee(existing.id, subtask.id)
    } else {
      await hubStore.addAssignee(subtask.id, email, 'assignee')
    }

    await hubStore.fetchCardAssignees(subtask.id)
    await syncSubtaskCompletion(subtask)
  } catch (err) {
    console.error('Failed to update subtask assignee:', err)
  }
}

async function assignGroup(subtask, groupId) {
  const groupMembers = (colleaguesStore.colleagues || [])
    .filter(c => c.group_ids && c.group_ids.includes(groupId))
    .map(c => c.email)
  if (!groupMembers.length) return
  try {
    const existing = new Set(getAssigneeRows(subtask).map(assignee => (assignee.user_email || '').toLowerCase()))
    const toAdd = groupMembers.filter(email => !existing.has(email.toLowerCase()))
    if (toAdd.length === 0) return

    // One HTTP call instead of N parallel adds. addAssigneesBatch
    // also refreshes hubStore.cardAssignees[subtask.id] from the server
    // response, so the follow-up fetchCardAssignees can be skipped.
    await hubStore.addAssigneesBatch(subtask.id, toAdd, 'assignee')
    await syncSubtaskCompletion(subtask)
  } catch (err) {
    console.error('Failed to assign group to subtask:', err)
  }
}

async function clearAssignees(subtask) {
  assignDropdown.value = null
  try {
    const assignees = getAssigneeRows(subtask)
    const ids = assignees.map(a => a.id).filter(Boolean)
    if (!ids.length) return
    // ONE HTTP call instead of N parallel DELETEs. The batch call
    // also re-fetches the card's assignees on success.
    await hubStore.removeAssigneesBatch(ids, subtask.id)
  } catch (err) {
    console.error('Failed to clear subtask assignees:', err)
  }
}

function handleClickOutside(e) {
  if (assignDropdown.value && !e.target.closest('.assign-dropdown-area')) {
    assignDropdown.value = null
  }
}

onMounted(async () => {
  colleaguesStore.init()
  document.addEventListener('click', handleClickOutside)
})

onUnmounted(() => {
  document.removeEventListener('click', handleClickOutside)
})

async function loadSubtasks() {
  loading.value = true
  try {
    subtasks.value = await hubStore.fetchSubtasks(props.cardId)
    await loadSubtaskAssignees(subtasks.value)
  } finally {
    loading.value = false
  }
}

function resolveParentCompletedState() {
  // Prefer the explicit prop passed by the parent (always available, even in
  // Work Hub / TaskDetailView where boardsStore.currentBoard isn't loaded).
  if (props.parentCompleted !== null && props.parentCompleted !== undefined) {
    return !!props.parentCompleted
  }
  // Fall back to boardsStore.allCards (kanban board view).
  const parentCard = boardsStore.allCards?.find(card => Number(card.id) === Number(props.cardId))
  if (parentCard) return !!parentCard.completed
  return null
}

async function syncParentCardCompletion() {
  if (totalCount.value === 0) return
  if (isUpdatingParent.value) return

  const desiredCompleted = completedCount.value === totalCount.value

  // Skip if we've already pushed this exact state — prevents WS echo loops.
  if (lastSyncedDesired.value === desiredCompleted) return

  const currentCompleted = resolveParentCompletedState()
  if (currentCompleted !== null && currentCompleted === desiredCompleted) {
    lastSyncedDesired.value = desiredCompleted
    return
  }

  isUpdatingParent.value = true
  try {
    await boardsStore.updateCard(props.cardId, { completed: desiredCompleted })
    lastSyncedDesired.value = desiredCompleted
  } catch (err) {
    console.error('Failed to sync parent card completion:', err)
  } finally {
    isUpdatingParent.value = false
  }
}

async function reloadForCard() {
  resetStatus()
  await loadSubtasks()
  await loadLinks(props.cardId)
  await loadLinkedCardStatuses(subtasks.value)
  await primeTrackedTime(subtasks.value, linkedCardMap.value)
  await syncParentCardCompletion()
}

watch(() => props.cardId, () => {
  lastSyncedDesired.value = null
  reloadForCard()
}, { immediate: true })

watch([completedCount, totalCount], () => {
  syncParentCardCompletion()
})

async function addSubtask() {
  if (!newTitle.value.trim()) return
  try {
    const created = await hubStore.createSubtask(props.cardId, { title: newTitle.value.trim() })
    if (created?.id) {
      subtasks.value = [...subtasks.value, created]
      hubStore.cardAssignees[created.id] = []
    }
    newTitle.value = ''
    showAddForm.value = false
  } catch (err) {
    console.error('Failed to create subtask:', err)
  }
}

async function handlePaste(e) {
  const text = e.clipboardData?.getData('text/plain') || ''
  const lines = text.split(/\r?\n/).map(l => l.replace(/^[\s\-\*\u2022\d.]+/, '').trim()).filter(Boolean)
  if (lines.length < 2) return

  e.preventDefault()
  bulkCreating.value = true
  try {
    // ONE HTTP call instead of N. Server does one INSERT + one pubsub.
    const rows = lines.map(line => ({ title: line }))
    const { subtasks: created } = await hubStore.createSubtasksBatch(props.cardId, rows)
    if (created.length) {
      subtasks.value = [...subtasks.value, ...created]
      for (const c of created) {
        if (c?.id != null) hubStore.cardAssignees[c.id] = []
      }
    }
    newTitle.value = ''
  } catch (err) {
    console.error('Failed to bulk-create subtasks:', err)
  } finally {
    bulkCreating.value = false
  }
}

function openCardInBoard(boardId, cardId, extraQuery = {}) {
  if (!boardId || !cardId) return

  router.replace({
    name: 'board',
    params: { id: boardId },
    query: { card: cardId, ...extraQuery },
  })
}

function openLinkedCard(subtask) {
  const linked = getLinkedCard(subtask)
  if (!linked?.linked_board_id || !linked?.linked_card_id) return

  openCardInBoard(linked.linked_board_id, linked.linked_card_id, {
    originCard: props.cardId,
    originBoard: boardsStore.currentBoard?.id || subtask.board_id || null,
    originSubtask: subtask.id,
  })
}

function hasChildren(subtask) {
  return Number(subtask?.child_count || 0) > 0
}

function isCardSubtask(subtask) {
  return hasLinkedCard(subtask) || hasChildren(subtask)
}

function openSubtaskCard(subtask) {
  if (hasLinkedCard(subtask)) {
    openLinkedCard(subtask)
    return
  }

  openCardInBoard(subtask.board_id || boardsStore.currentBoard?.id, subtask.id, {
    originCard: props.cardId,
    originBoard: boardsStore.currentBoard?.id || subtask.board_id || null,
    originSubtask: subtask.id,
  })
}

async function toggleComplete(subtask) {
  if (isCardSubtask(subtask)) {
    openSubtaskCard(subtask)
    return
  }

  const ownAssignee = getAssigneeRows(subtask).find(assignee => (assignee.user_email || '').toLowerCase() === currentEmail.value)

  if (ownAssignee) {
    const nextStatus = ownAssignee.status === 'done'
      ? (ownAssignee.started_at ? 'working' : 'assigned')
      : 'done'

    try {
      await hubStore.changeAssigneeStatus(ownAssignee.id, nextStatus)
      await hubStore.fetchCardAssignees(subtask.id)
      await syncSubtaskCompletion(subtask)
    } catch (err) {
      console.error('Failed to toggle assignee completion:', err)
    }
    return
  }

  if (getAssigneeRows(subtask).length > 0) {
    if (canManageAllStatuses.value) {
      assignDropdown.value = assignDropdown.value === subtask.id ? null : subtask.id
    }
    return
  }

  try {
    await boardsStore.updateCard(subtask.id, { completed: !subtask.completed })
    subtask.completed = !subtask.completed
  } catch (err) {
    console.error('Failed to toggle subtask:', err)
  }
}

const promotingId = ref(null)

/**
 * Promote a plain subtask into a full card on the parent card's list.
 * Carries assignees and copies tracked work sessions, then removes the
 * original subtask row.
 */
async function promoteSubtask(subtask) {
  if (promotingId.value) return
  promotingId.value = subtask.id
  try {
    const parentCard = await boardsStore.getCard(props.cardId)
    if (!parentCard?.list_id) {
      toast.error('Could not resolve the parent task\'s list')
      return
    }

    const newCard = await boardsStore.createCard(parentCard.list_id, {
      title: subtask.title,
      description: subtask.description || '',
      due_date: subtask.due_date || null,
      priority: subtask.priority || null,
    })
    if (!newCard?.id) {
      toast.error('Failed to create the promoted task')
      return
    }

    await api.post(`/project-hub/cards/${newCard.id}/promote-from-subtask`, {
      source_card_id: subtask.id,
      assignee_emails: getAssignees(subtask),
    })

    await hubStore.deleteSubtask(subtask.id)
    subtasks.value = subtasks.value.filter(s => s.id !== subtask.id)

    toast.success(`Promoted "${subtask.title}" to a full task`)
  } catch (err) {
    console.error('Failed to promote subtask:', err)
    toast.error('Failed to promote subtask')
  } finally {
    promotingId.value = null
  }
}

function requestDeleteSubtask(subtask) {
  deleteConfirm.value = { show: true, subtask }
}

function cancelDelete() {
  deleteConfirm.value = { show: false, subtask: null }
}

async function confirmDeleteSubtask() {
  const subtask = deleteConfirm.value.subtask
  if (!subtask) return
  deleteConfirm.value = { show: false, subtask: null }
  try {
    await hubStore.deleteSubtask(subtask.id)
    subtasks.value = subtasks.value.filter(s => s.id !== subtask.id)
  } catch (err) {
    console.error('Failed to delete subtask:', err)
  }
}

async function toggleAssigneeDone(subtask, assignee) {
  if (isCardSubtask(subtask)) {
    openSubtaskCard(subtask)
    return
  }

  if (!canToggleAssigneeStatus(assignee)) {
    return
  }

  const nextStatus = assignee.status === 'done'
    ? (assignee.started_at ? 'working' : 'assigned')
    : 'done'

  try {
    await hubStore.changeAssigneeStatus(assignee.id, nextStatus)
    await hubStore.fetchCardAssignees(subtask.id)
    await syncSubtaskCompletion(subtask)
  } catch (err) {
    console.error('Failed to update assignee status:', err)
  }
}
</script>

<template>
  <div>
    <button
      type="button"
      class="w-full flex items-center gap-2 py-1 text-sm font-medium text-surface-700 dark:text-surface-300 hover:text-surface-900 dark:hover:text-surface-100 transition-colors"
      @click="expanded = !expanded"
    >
      <span class="material-symbols-rounded text-[18px] transition-transform" :class="expanded ? 'rotate-90' : ''">chevron_right</span>
      <span class="material-symbols-rounded text-[18px]">account_tree</span>
      Subtasks
      <span v-if="visibleTotalCount > 0" class="text-xs text-surface-400 ml-1">{{ visibleCompletedCount }}/{{ visibleTotalCount }}</span>
      <div v-if="visibleTotalCount > 0" class="flex-1 mx-2">
        <div class="h-1.5 bg-surface-200 dark:bg-surface-700 rounded-full overflow-hidden">
          <div
            class="h-full bg-green-500 rounded-full transition-all duration-300"
            :style="{ width: progressPercent + '%' }"
          ></div>
        </div>
      </div>
    </button>

    <div v-if="expanded" class="pt-2">
      <!-- Filtered indicator for non-owners -->
      <div v-if="!isOwner && !fullTaskVisibility && subtasks.length > visibleSubtasks.length" class="flex items-center gap-1.5 mb-2 px-1">
        <span class="material-symbols-rounded text-[14px] text-surface-400">filter_alt</span>
        <span class="text-[11px] text-surface-400">Showing your assigned tasks ({{ visibleSubtasks.length }} of {{ subtasks.length }})</span>
      </div>

      <div v-if="loading" class="flex items-center justify-center py-4">
        <div class="animate-spin rounded-full h-5 w-5 border-2 border-primary-500 border-t-transparent"></div>
      </div>

      <template v-else>
        <div class="mb-2 overflow-visible rounded-xl border border-surface-200/70 dark:border-surface-700/70">
          <div class="grid grid-cols-[2rem_minmax(0,1fr)_7rem_8rem_9rem] items-center gap-2 px-3 py-2 bg-surface-50 dark:bg-surface-800/80 border-b border-surface-200 dark:border-surface-700 text-[10px] font-semibold uppercase tracking-wide text-surface-400">
            <span class="text-center">Done</span>
            <span>Task</span>
            <span>Tracked</span>
            <span>Assigned</span>
            <span>Actions</span>
          </div>

          <div
            v-for="subtask in visibleSubtasks"
            :key="subtask.id"
            class="relative grid grid-cols-[2rem_minmax(0,1fr)_7rem_8rem_9rem] items-center gap-2 px-3 py-2 group transition-colors border-b border-surface-100 dark:border-surface-700/50 last:border-b-0"
            :class="isCardSubtask(subtask)
              ? 'bg-primary-50/70 dark:bg-primary-900/10 hover:bg-primary-100/70 dark:hover:bg-primary-900/20'
              : 'bg-white dark:bg-surface-800 hover:bg-surface-50 dark:hover:bg-surface-700/40'"
          >
            <div class="flex justify-center">
              <button
                type="button"
                class="relative w-5 h-5 rounded-md border-2 transition-all flex items-center justify-center shrink-0"
                :class="getCompletionMeta(subtask).isComplete
                  ? 'bg-green-500 border-green-500'
                  : 'border-surface-300 dark:border-surface-600 hover:border-primary-400'"
                @click="toggleComplete(subtask)"
              >
                <span v-if="getCompletionMeta(subtask).isComplete" class="material-symbols-rounded text-white text-[14px]">check</span>
              </button>
            </div>

            <div class="min-w-0">
              <button
                type="button"
                class="w-full text-left min-w-0"
                @click="openSubtaskCard(subtask)"
              >
                <span
                  class="block text-sm truncate"
                  :class="getCompletionMeta(subtask).isComplete
                    ? 'line-through text-surface-400 dark:text-surface-500'
                    : 'text-surface-700 dark:text-surface-300'"
                >
                  {{ subtask.title }}
                </span>
              </button>

              <div class="flex flex-wrap items-center gap-1 mt-1 min-w-0">
                <span
                  v-if="isCardSubtask(subtask)"
                  class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-medium bg-primary-100 dark:bg-primary-900/30 text-primary-600 dark:text-primary-300"
                >
                  <span class="material-symbols-rounded text-[12px]">{{ hasLinkedCard(subtask) ? 'linked_services' : 'subtitles' }}</span>
                  {{ hasLinkedCard(subtask) ? 'Linked card created' : `${subtask.child_count} subtask${subtask.child_count > 1 ? 's' : ''}` }}
                </span>
                <span
                  v-if="getLinkedProgressLabel(subtask)"
                  class="text-[10px] font-medium text-primary-600 dark:text-primary-300 truncate"
                >
                  {{ getLinkedProgressLabel(subtask) }}
                </span>
              </div>
            </div>

            <div class="min-w-0">
              <span
                class="inline-flex items-center gap-1 text-[10px] font-medium px-2 py-1 rounded-full"
                :class="getTrackedTimeCell(subtask) !== '--'
                  ? 'text-emerald-600 dark:text-emerald-400 bg-emerald-50 dark:bg-emerald-900/20'
                  : 'text-surface-400 bg-surface-100 dark:bg-surface-700'"
              >
                <span class="material-symbols-rounded text-[11px]">timer</span>
                {{ getTrackedTimeCell(subtask) }}
              </span>
            </div>

            <div class="relative assign-dropdown-area flex items-center gap-1 min-w-0 group/assigned">
              <div v-if="getDisplayAssigneeRows(subtask).length" class="flex items-center gap-1 min-w-0">
                <div class="flex -space-x-1.5 shrink-0">
                  <button
                    v-for="assignee in getDisplayAssigneeRows(subtask)"
                    :key="'sa-' + assignee.id"
                    type="button"
                    class="relative rounded-full ring-2 ring-white dark:ring-surface-900 transition-transform"
                    :class="!isCardSubtask(subtask) && canToggleAssigneeStatus(assignee) ? 'cursor-pointer hover:scale-105' : 'cursor-default'"
                    :title="`${getDisplayName(assignee.user_email)} (${assignee.status || 'assigned'})`"
                    @click.stop="toggleAssigneeDone(subtask, assignee)"
                  >
                    <UserAvatar
                      :email="assignee.user_email"
                      size="xs"
                      :class="[
                        isCurrentUser(assignee.user_email) ? 'ring-2 ring-primary-300 dark:ring-primary-700' : '',
                        assignee.status === 'done' ? 'opacity-100' : 'opacity-75'
                      ]"
                    />
                    <span
                      v-if="assignee.status === 'done'"
                      class="absolute -right-1 -bottom-1 w-3.5 h-3.5 rounded-full bg-green-500 text-white flex items-center justify-center shadow-sm"
                    >
                      <span class="material-symbols-rounded text-[10px]">check</span>
                    </span>
                  </button>
                </div>
                <span class="text-[10px] text-surface-400 tabular-nums shrink-0">
                  {{ getCompletionMeta(subtask).done }}/{{ getCompletionMeta(subtask).total }}
                </span>
              </div>

              <div
                v-if="getDisplayAssigneeRows(subtask).length"
                class="absolute right-0 top-full pt-2 w-64 z-50 opacity-0 pointer-events-none translate-y-1 transition-all duration-150 group-hover/assigned:opacity-100 group-hover/assigned:pointer-events-auto group-hover/assigned:translate-y-0"
              >
                <div class="rounded-xl border border-surface-200 dark:border-surface-700 bg-white dark:bg-surface-800 shadow-xl p-2">
                  <div class="px-2 py-1 text-[10px] font-semibold uppercase tracking-wide text-surface-400 flex items-center justify-between">
                    <span>{{ isCardSubtask(subtask) ? 'Card Team' : 'Team Progress' }}</span>
                    <span class="tabular-nums">{{ getCompletionMeta(subtask).done }}/{{ getCompletionMeta(subtask).total }}</span>
                  </div>
                  <div class="space-y-1">
                    <button
                      v-for="assignee in getDisplayAssigneeRows(subtask)"
                      :key="'hover-' + assignee.id"
                      type="button"
                      class="w-full flex items-center gap-2 rounded-lg px-2 py-1.5 text-left transition-colors"
                      :class="!isCardSubtask(subtask) && canToggleAssigneeStatus(assignee)
                        ? 'hover:bg-surface-100 dark:hover:bg-surface-700 cursor-pointer'
                        : 'cursor-default'"
                      @click.stop="toggleAssigneeDone(subtask, assignee)"
                    >
                      <div class="relative shrink-0">
                        <UserAvatar
                          :email="assignee.user_email"
                          size="xs"
                          :class="assignee.status === 'done' ? 'opacity-100' : 'opacity-75'"
                        />
                        <span
                          v-if="assignee.status === 'done'"
                          class="absolute -right-1 -bottom-1 w-3.5 h-3.5 rounded-full bg-green-500 text-white flex items-center justify-center shadow-sm"
                        >
                          <span class="material-symbols-rounded text-[10px]">check</span>
                        </span>
                      </div>
                      <div class="min-w-0 flex-1">
                        <span class="block text-xs font-medium text-surface-700 dark:text-surface-200 truncate">
                          {{ getDisplayName(assignee.user_email) }}
                          <span v-if="isCurrentUser(assignee.user_email)" class="text-primary-500">(You)</span>
                        </span>
                        <span class="block text-[10px] text-surface-400 truncate">{{ assignee.user_email }}</span>
                      </div>
                      <div class="flex items-center gap-1 shrink-0">
                        <span
                          class="inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-medium"
                          :class="assignee.status === 'done'
                            ? 'bg-green-100 dark:bg-green-900/30 text-green-600 dark:text-green-400'
                            : 'bg-surface-100 dark:bg-surface-700 text-surface-500 dark:text-surface-300'"
                        >
                          {{ getAssigneeStatusLabel(assignee) }}
                        </span>
                        <span
                          v-if="canToggleAssigneeStatus(assignee)"
                          class="material-symbols-rounded text-[16px]"
                          :class="assignee.status === 'done'
                            ? 'text-green-500'
                            : 'text-surface-300 dark:text-surface-500'"
                        >check_circle</span>
                      </div>
                    </button>
                  </div>
                  <div class="px-2 pt-1 text-[10px] text-surface-400">
                    {{ isCardSubtask(subtask)
                      ? 'Progress and owners come from the card.'
                      : (canManageAllStatuses ? 'You can update any assignee here.' : 'You can only update your own status here.') }}
                  </div>
                </div>
              </div>
            </div>

            <div class="relative assign-dropdown-area flex items-center justify-start gap-1">
              <button
                v-if="!isCardSubtask(subtask)"
                type="button"
                class="w-7 h-7 rounded-full border border-dashed border-surface-300 dark:border-surface-600 flex items-center justify-center transition-all hover:border-primary-400"
                title="Assign members"
                @click.stop="assignDropdown = assignDropdown === subtask.id ? null : subtask.id"
              >
                <span class="material-symbols-rounded text-[14px] text-surface-400">person_add</span>
              </button>
              <button
                v-if="isCardSubtask(subtask)"
                type="button"
                class="px-2.5 py-1 rounded-full text-[11px] font-medium transition-all bg-primary-500 text-white hover:bg-primary-600"
                title="Open card"
                @click.stop="openSubtaskCard(subtask)"
              >
                <span class="flex items-center gap-1">
                  <span class="material-symbols-rounded text-[13px]">arrow_outward</span>
                  Open
                </span>
              </button>
              <button
                type="button"
                class="w-7 h-7 rounded-full flex items-center justify-center transition-all hover:bg-violet-100 dark:hover:bg-violet-900/30"
                title="Add time manually"
                @click.stop="openManualTimeEntry(subtask)"
              >
                <span class="material-symbols-rounded text-[14px] text-surface-400 hover:text-violet-500">more_time</span>
              </button>
              <button
                v-if="!isCardSubtask(subtask) && canManageAllStatuses"
                type="button"
                class="w-7 h-7 rounded-full flex items-center justify-center transition-all hover:bg-primary-100 dark:hover:bg-primary-900/30"
                title="Promote to full task (carries assignees + tracked time)"
                :disabled="promotingId === subtask.id"
                @click.stop="promoteSubtask(subtask)"
              >
                <span
                  class="material-symbols-rounded text-[14px] text-surface-400 hover:text-primary-500"
                  :class="{ 'animate-spin': promotingId === subtask.id }"
                >{{ promotingId === subtask.id ? 'progress_activity' : 'move_up' }}</span>
              </button>
              <button
                type="button"
                class="w-7 h-7 rounded-full flex items-center justify-center transition-all hover:bg-red-100 dark:hover:bg-red-900/30"
                title="Delete subtask"
                @click.stop="requestDeleteSubtask(subtask)"
              >
                <span class="material-symbols-rounded text-[14px] text-surface-400 hover:text-red-500">close</span>
              </button>

              <SubtaskAssignMenu
                v-if="assignDropdown === subtask.id"
                :card-assignee-emails="cardAssigneeEmails"
                :members-list="membersList"
                :groups="colleaguesStore.groups || []"
                :subtask="subtask"
                :is-assigned-fn="isAssigned"
                :get-assignees-fn="getAssignees"
                @close="assignDropdown = null"
                @toggle-assignee="toggleAssignee(subtask, $event)"
                @assign-group="assignGroup(subtask, $event)"
                @clear-assignees="clearAssignees(subtask)"
              />
            </div>
          </div>
        </div>

        <div v-if="showAddForm" class="space-y-1.5">
          <div class="flex items-center gap-2">
            <input
              v-model="newTitle"
              class="flex-1 text-sm px-3 py-1.5 rounded-lg border border-surface-300 dark:border-surface-600 bg-white dark:bg-surface-700 text-surface-800 dark:text-surface-200 outline-none focus:ring-1 focus:ring-primary-500"
              :placeholder="bulkCreating ? 'Creating subtasks...' : 'Subtask title (paste multiple lines to bulk-add)'"
              :disabled="bulkCreating"
              @keydown.enter.prevent="addSubtask"
              @keydown.escape="showAddForm = false"
              @paste="handlePaste"
              autofocus
            />
            <span v-if="bulkCreating" class="material-symbols-rounded text-[20px] text-primary-500 animate-spin">progress_activity</span>
            <template v-else>
              <button type="button" class="material-symbols-rounded text-[20px] text-green-500 hover:text-green-600" @click="addSubtask">check</button>
              <button type="button" class="material-symbols-rounded text-[20px] text-surface-400 hover:text-surface-600" @click="showAddForm = false">close</button>
            </template>
          </div>
        </div>

        <button
          v-else
          type="button"
          class="flex items-center gap-1 text-sm text-surface-400 hover:text-primary-500 transition-colors mt-1"
          @click="showAddForm = true"
        >
          <span class="material-symbols-rounded text-[16px]">add</span>
          Add subtask
        </button>
      </template>
    </div>

    <SubtaskDeleteModal
      v-if="deleteConfirm.show"
      :subtask="deleteConfirm.subtask"
      @confirm="confirmDeleteSubtask"
      @cancel="cancelDelete"
    />

    <!-- Manual Time Entry Dialog -->
    <ManualTimeEntryDialog
      v-if="manualTimeSubtask"
      :card-id="getManualTimeCardId(manualTimeSubtask)"
      :card-title="manualTimeSubtask.title || ''"
      @close="manualTimeSubtask = null"
      @saved="handleManualTimeSaved"
    />
  </div>
</template>

