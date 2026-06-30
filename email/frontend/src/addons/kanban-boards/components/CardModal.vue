<script setup>
import { ref, computed, onMounted, onBeforeUnmount, watch, nextTick, defineAsyncComponent } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import { useBoardsStore } from '@/addons/kanban-boards/stores/boards'
import { useColleaguesStore } from '@/addons/team/stores/colleagues'
import { useDriveStore } from '@/stores/drive'
import { useToastStore } from '@/stores/toast'
import { useMailSync, EventTypes } from '@/services/mailSyncSocket'
import { useAddons } from '@/composables/useAddons'
import { isDebugEnabled } from '@/utils/debug'
import ConfirmModal from '@/components/ConfirmModal.vue'
import DriveFilePicker from '@/components/DriveFilePicker.vue'
import UserAvatar from '@/components/shared/UserAvatar.vue'
import { useAuthStore } from '@/stores/auth'
import api from '@/services/api'
import { useBoardProStore } from '@/addons/board-pro/stores/boardPro'

// Board Pro components (lazy loaded)
const CardEmailsPanel = defineAsyncComponent(() => import('@/addons/board-pro/components/CardEmailsPanel.vue'))
const CardFinancialsPanel = defineAsyncComponent(() => import('@/addons/board-pro/components/CardFinancialsPanel.vue'))
const CardCommandCenter = defineAsyncComponent(() => import('@/addons/board-pro/components/CardCommandCenter.vue'))

const CardInvoicePanel = defineAsyncComponent(() => import('@/addons/board-pro/components/CardInvoicePanel.vue'))

// Project Hub components (lazy loaded)
const SubtasksList = defineAsyncComponent(() => import('@/addons/project-hub/components/SubtasksList.vue'))
const EnhancedComments = defineAsyncComponent(() => import('@/addons/project-hub/components/EnhancedComments.vue'))
const CardDependenciesPanel = defineAsyncComponent(() => import('@/addons/project-hub/components/CardDependenciesPanel.vue'))
const WorkSessionLog = defineAsyncComponent(() => import('@/addons/project-hub/components/WorkSessionLog.vue'))
const ManualTimeEntryDialog = defineAsyncComponent(() => import('@/addons/project-hub/components/ManualTimeEntryDialog.vue'))
const TaskCalendarSync = defineAsyncComponent(() => import('@/addons/project-hub/components/TaskCalendarSync.vue'))
const CardTrackedUrls = defineAsyncComponent(() => import('@/addons/project-hub/components/CardTrackedUrls.vue'))
const CardClientFiles = defineAsyncComponent(() => import('@/addons/project-hub/components/CardClientFiles.vue'))
const CardActivityTimeline = defineAsyncComponent(() => import('@/addons/project-hub/components/CardActivityTimeline.vue'))
const CardShareModal = defineAsyncComponent(() => import('@/addons/project-hub/components/CardShareModal.vue'))
const CardWatchFolders = defineAsyncComponent(() => import('@/addons/project-hub/components/CardWatchFolders.vue'))
const CardTimeBreakdown = defineAsyncComponent(() => import('@/addons/project-hub/components/CardTimeBreakdown.vue'))
const WatcherFollowerPanel = defineAsyncComponent(() => import('@/addons/project-hub/components/WatcherFollowerPanel.vue'))
const CardAssetManager = defineAsyncComponent(() => import('./CardAssetManager.vue'))

import { useProjectHubStore } from '@/addons/project-hub/stores/projectHub'
import { useCardTimer } from '@/addons/project-hub/composables/useCardTimer'
import { phUpdateCard, phAddComment } from '@/addons/project-hub/services/projectHubCardApi'

const router = useRouter()
const route = useRoute()
const { on } = useMailSync()
const { elapsed, isRunning: timerRunning, startTimer, stopTimer, stopTimerSync, formatElapsed } = useCardTimer()

const props = defineProps({
  card: {
    type: Object,
    required: true
  },
  inlineMode: {
    type: Boolean,
    default: false
  }
})

const emit = defineEmits(['close'])

const boardsStore = useBoardsStore()
const colleaguesStore = useColleaguesStore()
const driveStore = useDriveStore()
const toast = useToastStore()
const authStore = useAuthStore()
const { boardProEnabled, projectHubEnabled } = useAddons()
const boardProStore = useBoardProStore()

const linkedEmailCount = computed(() => {
  if (!boardProEnabled.value || !cardData.value?.id) return 0
  return (boardProStore.cardEmails[cardData.value.id] || []).length
})
const resolvedOriginalCardLink = computed(() => {
  const queryParentCardId = Number(route.query.originCard || 0)
  if (queryParentCardId) {
    return {
      parent_card_id: queryParentCardId,
      parent_board_id: Number(route.query.originBoard || 0),
      subtask_card_id: Number(route.query.originSubtask || 0),
    }
  }

  return originalCardLink.value
})

// PH-aware card mutation: routes through proxy when Project Hub is active
async function updateCard(cardId, payload) {
  if (projectHubEnabled.value) {
    const updated = await phUpdateCard(cardId, payload)
    if (updated) boardsStore.updateCardInState(cardId, updated)
    return updated
  }
  return await boardsStore.updateCard(cardId, payload)
}

async function addCardComment(cardId, content) {
  if (projectHubEnabled.value) {
    return await phAddComment(cardId, content)
  }
  return await boardsStore.addComment(cardId, content)
}

// Project Hub layout
const hubStore = projectHubEnabled.value ? useProjectHubStore() : null
const cardLayout = computed(() => {
  if (!projectHubEnabled.value || !hubStore) return 'modal'
  return hubStore.cardLayout
})

function cycleLayout() {
  if (!hubStore) return
  const order = ['modal', 'fullscreen', 'sidebar']
  const idx = order.indexOf(cardLayout.value)
  hubStore.setCardLayout(order[(idx + 1) % order.length])
}

const layoutIcon = computed(() => {
  const icons = { modal: 'pip', fullscreen: 'fullscreen', sidebar: 'side_navigation' }
  return icons[cardLayout.value] || 'pip'
})



const showDependencies = ref(false)
const showCalendarSync = ref(false)

const isBoardOwner = computed(() => {
  const cardRole = cardData.value?.board_user_role
  if (cardRole === 'owner') return true
  const role = boardsStore.currentBoard?.user_role
  if (role === 'owner') return true
  const ownerEmail = (cardData.value?.board_owner_email || boardsStore.currentBoard?.owner_email || '').toLowerCase()
  const userEmail = (authStore.userEmail || '').toLowerCase()
  return !!ownerEmail && ownerEmail === userEmail
})

async function toggleFullTaskVisibility() {
  if (!cardData.value?.id || !isBoardOwner.value) return
  const newVal = !cardData.value.full_task_visibility
  const updated = await updateCard(cardData.value.id, { full_task_visibility: newVal })
  if (updated) cardData.value = { ...cardData.value, full_task_visibility: newVal }
}

// Content tabs
const activeTab = ref('details')

// Resizable sidebar
const SIDEBAR_MIN = 280
const SIDEBAR_MAX = 600
const sidebarWidth = ref(parseInt(localStorage.getItem('flowone-card-sidebar-width')) || 380)
const isResizing = ref(false)
const sidebarOpen = ref(localStorage.getItem('flowone-card-sidebar-open') !== 'false')

function toggleSidebar() {
  sidebarOpen.value = !sidebarOpen.value
  localStorage.setItem('flowone-card-sidebar-open', String(sidebarOpen.value))
}

function startSidebarResize(e) {
  isResizing.value = true
  const startX = e.clientX
  const startW = sidebarWidth.value

  function onMove(ev) {
    const delta = startX - ev.clientX
    sidebarWidth.value = Math.min(SIDEBAR_MAX, Math.max(SIDEBAR_MIN, startW + delta))
  }
  function onUp() {
    isResizing.value = false
    localStorage.setItem('flowone-card-sidebar-width', String(sidebarWidth.value))
    document.removeEventListener('mousemove', onMove)
    document.removeEventListener('mouseup', onUp)
  }
  document.addEventListener('mousemove', onMove)
  document.addEventListener('mouseup', onUp)
}

// Confirm modal state
const showDeleteConfirm = ref(false)

// State
const cardData = ref(null)
const loading = ref(true)
const editingTitle = ref(false)
const titleInput = ref('')
const editingDescription = ref(false)
const descriptionInput = ref('')
const descriptionCollapsed = ref(localStorage.getItem('card_desc_collapsed') === '1')

function toggleDescriptionCollapsed() {
  descriptionCollapsed.value = !descriptionCollapsed.value
  localStorage.setItem('card_desc_collapsed', descriptionCollapsed.value ? '1' : '0')
}
const newChecklistTitle = ref('')
const addingChecklist = ref(false)
const newCommentContent = ref('')
const newChecklistItemInputs = ref({})
const showLabelPicker = ref(false)
const showDueDatePicker = ref(false)
const dueDateInput = ref('')
const showStartDatePicker = ref(false)
const startDateInput = ref('')
const showMemberPicker = ref(false)
const showColorPicker = ref(false)
const originalCardLink = ref(null)

const cardColors = [
  '#ef4444', '#f97316', '#f59e0b', '#eab308', '#84cc16',
  '#22c55e', '#10b981', '#14b8a6', '#06b6d4', '#0ea5e9',
  '#3b82f6', '#6366f1', '#8b5cf6', '#a855f7', '#d946ef',
  '#ec4899', '#f43f5e', '#78716c', '#64748b', '#1e293b',
  '#991b1b', '#9a3412', '#92400e', '#854d0e', '#3f6212',
  '#166534', '#065f46', '#115e59', '#155e75', '#075985',
  '#1e40af', '#3730a3', '#5b21b6', '#7e22ce', '#a21caf',
  '#9d174d', '#be123c', '#44403c', '#334155', '#0f172a'
]
const selectedMember = ref('')

const editingTimeEstimate = ref(false)
const timeEstimateHours = ref('')
const timeEstimateMins = ref('')

function openEstimateEditor() {
  const sec = cardData.value?.time_estimate_seconds || 0
  timeEstimateHours.value = sec > 0 ? String(Math.floor(sec / 3600)) : ''
  timeEstimateMins.value = sec > 0 ? String(Math.floor((sec % 3600) / 60)) : ''
  editingTimeEstimate.value = true
}

async function saveTimeEstimate() {
  const h = parseInt(timeEstimateHours.value) || 0
  const m = parseInt(timeEstimateMins.value) || 0
  const totalSec = h * 3600 + m * 60
  const updated = await updateCard(cardData.value.id, { time_estimate_seconds: totalSec || null })
  if (updated) cardData.value.time_estimate_seconds = updated.time_estimate_seconds ?? (totalSec || null)
  editingTimeEstimate.value = false
}

function cancelEstimateEdit() { editingTimeEstimate.value = false }

function onManualTimeSaved(targetCardId = null) {
  showManualTimeEntry.value = false
  if (cardData.value?.id && hubStore) {
    hubStore.fetchWorkSessions(cardData.value.id)
    hubStore.fetchCardAssignees(cardData.value.id)
    if (targetCardId && Number(targetCardId) !== Number(cardData.value.id)) {
      hubStore.fetchWorkSessions(Number(targetCardId))
      hubStore.fetchCardAssignees(Number(targetCardId))
    }
  }
}

function fmtTimePill(seconds) {
  if (!seconds || seconds <= 0) return '0m'
  const h = Math.floor(seconds / 3600)
  const m = Math.round((seconds % 3600) / 60)
  if (h === 0) return `${m}m`
  if (m === 0) return `${h}h`
  return `${h}h ${m}m`
}

const cardTrackedTime = computed(() => {
  if (!cardData.value?.id) return 0
  const cid = cardData.value.id
  const assignees = hubStore?.cardAssignees?.[cid] || []
  if (assignees.length) {
    const fromAssignees = assignees.reduce((s, a) => s + (a.time_spent_seconds || 0), 0)
    if (fromAssignees > 0) return fromAssignees
  }
  const sessions = hubStore?.cardWorkSessions?.[cid] || []
  if (sessions.length) return sessions.reduce((s, ws) => s + (Number(ws.duration_seconds) || 0), 0)
  return cardData.value.time_spent_seconds || 0
})

const timeBudgetPct = computed(() => {
  const estimate = cardData.value?.time_estimate_seconds || 0
  if (estimate <= 0 || cardTrackedTime.value <= 0) return 0
  return Math.round((cardTrackedTime.value / estimate) * 100)
})

const timeBudgetStatus = computed(() => {
  if (timeBudgetPct.value <= 0) return 'none'
  if (timeBudgetPct.value > 100) return 'exceeded'
  if (timeBudgetPct.value >= 90) return 'warning'
  return 'ok'
})

// File upload
const fileInput = ref(null)
const uploading = ref(false)
const showAttachmentMenu = ref(false)
const showDriveFilePicker = ref(false)
const assetManagerRef = ref(null)
const commentTextarea = ref(null)
const uploadingImage = ref(false)
const editingChecklistItem = ref(null) // { checklistId, itemId, title }
const editingItemInput = ref('')
const linkDriveTarget = ref(null) // { checklistId, itemId } for linking drive file to checklist item
const checklistItemAssignDropdown = ref(null) // item.id that has the dropdown open

// Attachment thumbnail blob URLs (for authenticated preview)
const thumbnailUrls = ref({})

// Mobile
const isMobile = ref(window.innerWidth < 768)
const showMobileSidebar = ref(false)
const showMoveList = ref(false)
const showActionsMenu = ref(false)
const showManualTimeEntry = ref(false)
const showCardShareModal = ref(false)
function updateMobileState() { isMobile.value = window.innerWidth < 768 }

// Computed
const lists = computed(() => boardsStore.currentLists)
const labels = computed(() => boardsStore.currentLabels)
const cardAssigneeEmails = computed(() => {
  if (!hubStore || !cardData.value?.id) return new Set()
  const assignees = hubStore.cardAssignees?.[cardData.value.id] || []
  return new Set(assignees.map(a => a.user_email))
})

const members = computed(() => {
  const all = (colleaguesStore.colleagues || []).map(c => ({
    email: c.email,
    user_email: c.email,
    display_name: c.display_name || c.email?.split('@')[0],
  }))
  const source = all.length > 0 ? all : (boardsStore.currentMembers || [])
  if (!projectHubEnabled.value || cardAssigneeEmails.value.size === 0) return source
  const assigned = source.filter(m => cardAssigneeEmails.value.has(m.email || m.user_email))
  const others = source.filter(m => !cardAssigneeEmails.value.has(m.email || m.user_email))
  return [...assigned, ...others]
})

const currentList = computed(() => {
  if (!cardData.value) return null
  return lists.value.find(l => l.id === cardData.value.list_id)
})

const cardLabels = computed(() => cardData.value?.labels || [])
const checklists = computed(() => cardData.value?.checklists || [])
const checklistItems = computed(() => checklists.value.flatMap(checklist => checklist?.items || []))
const attachments = computed(() => cardData.value?.attachments || [])
const enrichedAttachments = computed(() =>
  attachments.value.map(a => ({
    ...a,
    _thumbnailUrl: a.drive_file_id ? (thumbnailUrls.value[a.drive_file_id] || null) : null,
  }))
)
const comments = computed(() => cardData.value?.comments || [])
const activity = computed(() => cardData.value?.activity || [])
// Collect all unique team members across the card + all subtasks with their task names
const teamOverview = computed(() => {
  if (!projectHubEnabled.value || !hubStore || !cardData.value?.id) return []

  const memberMap = new Map()

  const addMember = (email, taskTitle, status) => {
    const key = email.toLowerCase().trim()
    if (!key) return
    if (!memberMap.has(key)) {
      memberMap.set(key, { email: key, tasks: [], doneCount: 0, totalCount: 0 })
    }
    const m = memberMap.get(key)
    if (taskTitle) {
      m.tasks.push({ title: taskTitle, status })
      m.totalCount++
      if (status === 'done') m.doneCount++
    }
  }

  // Card-level assignees
  for (const a of (hubStore.cardAssignees?.[cardData.value.id] || [])) {
    addMember(a.user_email || a.email, null, a.status)
  }
  // Subtask assignees
  for (const item of checklistItems.value) {
    for (const a of (hubStore.cardAssignees?.[item.id] || [])) {
      addMember(a.user_email || a.email, item.title, a.status)
    }
  }

  return Array.from(memberMap.values())
})
const toolbarTabs = computed(() => [
  { id: 'details', label: 'Details', icon: 'description' },
  { id: 'assets', label: 'Assets', icon: 'folder_open', badge: attachments.value.length || null },
  ...(projectHubEnabled.value ? [
    { id: 'activity', label: 'Activity', icon: 'timeline' },
  ] : []),
  ...(boardProEnabled.value && !projectHubEnabled.value ? [
    { id: 'activity', label: 'Activity', icon: 'timeline' },
    { id: 'emails', label: 'Emails', icon: 'email', badge: linkedEmailCount.value || null },
    { id: 'financials', label: 'Financials', icon: 'credit_score' },
    { id: 'invoice', label: 'Invoice', icon: 'receipt_long' },
  ] : []),
  ...(boardProEnabled.value && projectHubEnabled.value ? [
    { id: 'emails', label: 'Emails', icon: 'email', badge: linkedEmailCount.value || null },
    { id: 'financials', label: 'Financials', icon: 'credit_score' },
    { id: 'invoice', label: 'Invoice', icon: 'receipt_long' },
  ] : []),
  ...(projectHubEnabled.value ? [
    { id: 'time', label: 'Time Tracker', icon: 'timer' },
  ] : []),
])

// Methods
function applyCardData(fullCard) {
  cardData.value = fullCard
  titleInput.value = fullCard.title
  descriptionInput.value = fullCard.description || ''
  dueDateInput.value = fullCard.due_date ? fullCard.due_date.substring(0, 10) : ''
  startDateInput.value = fullCard.start_date ? fullCard.start_date.substring(0, 10) : ''
  selectedMember.value = fullCard.assigned_to || ''
}

async function loadCard() {
  loading.value = true
  const fullCard = await boardsStore.getCard(props.card.id)
  if (fullCard) {
    applyCardData(fullCard)
    loadAllThumbnails()

    if (projectHubEnabled.value) {
      originalCardLink.value = await hubStore?.fetchCardOriginLink(fullCard.id)
      await preloadTaskAssignees(fullCard)
      hubStore?.fetchWorkSessions(fullCard.id)
      hubStore?.markCommentsRead(fullCard.id)
      startTimer(fullCard.id, authStore.userEmail)
    }

    if (boardProEnabled.value) {
      boardProStore.fetchCardEmails(fullCard.id)
    }
  }
  loading.value = false
}

// Refresh card fields in response to a WS CARD_UPDATED event WITHOUT toggling
// the `loading` state. Toggling `loading` would unmount/remount the inner
// content (incl. SubtasksList), which previously caused an infinite refresh
// loop when subtasks auto-synced the parent's `completed` flag.
async function silentRefreshCard() {
  if (!props.card?.id) return
  const fullCard = await boardsStore.getCard(props.card.id)
  if (!fullCard) return
  applyCardData(fullCard)
  if (projectHubEnabled.value) {
    await preloadTaskAssignees(fullCard)
  }
}

function openOriginalCard() {
  const parentCardId = Number(resolvedOriginalCardLink.value?.parent_card_id || 0)
  const parentBoardId = Number(resolvedOriginalCardLink.value?.parent_board_id || 0)
  if (!parentCardId || !parentBoardId) return

  router.replace({
    name: 'board',
    params: { id: parentBoardId },
    query: { card: parentCardId },
  })
}

watch(() => props.card?.id, async (newId, oldId) => {
  if (!newId || newId === oldId) return
  originalCardLink.value = null
  await stopTimer()
  await loadCard()
})

async function preloadTaskAssignees(card) {
  if (!projectHubEnabled.value || !hubStore || !card?.id) return

  const taskIds = [
    Number(card.id),
    ...(card.checklists || []).flatMap(checklist => (checklist?.items || []).map(item => Number(item?.id)))
  ].filter(Boolean)

  const uniqueTaskIds = [...new Set(taskIds)]
  if (!uniqueTaskIds.length) return

  // ONE HTTP call instead of N parallel ones (card + every checklist item).
  await hubStore.fetchCardAssigneesBatch(uniqueTaskIds)
}

async function close() {
  await stopTimer()
  emit('close')
}

// Title editing
function startEditTitle() {
  editingTitle.value = true
  titleInput.value = cardData.value.title
  nextTick(() => {
    document.getElementById('card-title-input')?.focus()
  })
}

async function saveTitle() {
  if (!titleInput.value.trim()) {
    titleInput.value = cardData.value.title
    editingTitle.value = false
    return
  }
  
  const updated = await updateCard(cardData.value.id, { title: titleInput.value.trim() })
  if (updated) {
    cardData.value.title = updated.title
  }
  editingTitle.value = false
}

// Description editing
function startEditDescription() {
  editingDescription.value = true
  descriptionInput.value = cardData.value.description || ''
  nextTick(() => {
    document.getElementById('card-description-input')?.focus()
  })
}

async function saveDescription() {
  const updated = await updateCard(cardData.value.id, { description: descriptionInput.value })
  if (updated) {
    cardData.value.description = updated.description
  }
  editingDescription.value = false
}

// Labels
function toggleLabel(label) {
  const hasLabel = cardLabels.value.some(l => l.id === label.id)
  
  if (hasLabel) {
    boardsStore.removeLabelFromCard(cardData.value.id, label.id)
    cardData.value.labels = cardData.value.labels.filter(l => l.id !== label.id)
  } else {
    boardsStore.addLabelToCard(cardData.value.id, label.id)
    cardData.value.labels.push(label)
  }
  
  // Close the picker after selection
  showLabelPicker.value = false
}

async function toggleLabelType(label) {
  const newIsType = label.is_type ? 0 : 1
  try {
    await api.put(`/boards/labels/${label.id}`, { is_type: newIsType })
    label.is_type = newIsType
  } catch (err) {
    console.error('[CardModal] toggleLabelType error:', err)
  }
}

// Due date
function openDueDatePicker() {
  // Sync the input with current card due date when opening picker
  // Handle both ISO format (2026-01-06T00:00:00) and MySQL format (2026-01-06 00:00:00)
  if (cardData.value?.due_date) {
    const dateStr = cardData.value.due_date
    // Extract just the date part (YYYY-MM-DD)
    dueDateInput.value = dateStr.substring(0, 10)
  } else {
    dueDateInput.value = ''
  }
  showDueDatePicker.value = true
}

async function saveDueDate() {
  const dueDate = dueDateInput.value || null
  const updated = await updateCard(cardData.value.id, { due_date: dueDate })
  if (updated) {
    cardData.value.due_date = updated.due_date
    toast.success(dueDate ? 'Due date set' : 'Due date removed')
  }
  showDueDatePicker.value = false
}

async function removeDueDate() {
  dueDateInput.value = ''
  await saveDueDate()
}

// Start date
function openStartDatePicker() {
  if (cardData.value?.start_date) {
    startDateInput.value = cardData.value.start_date.substring(0, 10)
  } else {
    startDateInput.value = ''
  }
  showStartDatePicker.value = true
}

async function saveStartDate() {
  const startDate = startDateInput.value || null
  const updated = await updateCard(cardData.value.id, { start_date: startDate })
  if (updated) {
    cardData.value.start_date = updated.start_date
    toast.success(startDate ? 'Start date set' : 'Start date removed')
  }
  showStartDatePicker.value = false
}

async function removeStartDate() {
  startDateInput.value = ''
  await saveStartDate()
}

// Member/Assignment
async function assignMember() {
  const updated = await updateCard(cardData.value.id, { assigned_to: selectedMember.value || null })
  if (updated) {
    cardData.value.assigned_to = updated.assigned_to
    toast.success(selectedMember.value ? 'Member assigned' : 'Assignment removed')
  }
  showMemberPicker.value = false
}

// Checklists
async function addChecklist() {
  if (!newChecklistTitle.value.trim()) {
    addingChecklist.value = false
    return
  }
  
  const checklist = await boardsStore.createChecklist(cardData.value.id, newChecklistTitle.value.trim())
  if (checklist) {
    cardData.value.checklists.push(checklist)
    newChecklistTitle.value = ''
    addingChecklist.value = false
  }
}

async function deleteChecklist(checklistId) {
  if (await boardsStore.deleteChecklist(checklistId)) {
    cardData.value.checklists = cardData.value.checklists.filter(c => c.id !== checklistId)
  }
}

async function addChecklistItem(checklistId) {
  const title = newChecklistItemInputs.value[checklistId]
  if (!title?.trim()) return
  
  const item = await boardsStore.addChecklistItem(checklistId, title.trim())
  if (item) {
    const checklist = cardData.value.checklists.find(c => c.id === checklistId)
    if (checklist) {
      checklist.items.push(item)
    }
    newChecklistItemInputs.value[checklistId] = ''
  }
}

async function handleChecklistPaste(e, checklistId) {
  const pastedText = e.clipboardData?.getData('text')
  if (!pastedText) return
  
  // Split by empty lines (double newlines) to create separate items
  // Single line breaks within a block are kept together as one item
  const blocks = pastedText.split(/\n\s*\n/).map(block => block.trim()).filter(block => block)
  
  // If multiple blocks (separated by empty lines), add each as separate item
  if (blocks.length > 1) {
    e.preventDefault()
    
    // Add all items
    for (const block of blocks) {
      // Keep line breaks within blocks but trim whitespace
      const title = block.replace(/\r?\n/g, ' ').trim()
      const item = await boardsStore.addChecklistItem(checklistId, title)
      if (item) {
        const checklist = cardData.value.checklists.find(c => c.id === checklistId)
        if (checklist) {
          checklist.items.push(item)
        }
      }
    }
    
    // Clear the input
    newChecklistItemInputs.value[checklistId] = ''
    toast.success(`Added ${blocks.length} items`)
  }
  // If no empty line separators, let default paste behavior handle it
}

async function toggleChecklistItem(item) {
  const updated = await boardsStore.toggleChecklistItem(item.id, !item.completed)
  if (updated) {
    item.completed = updated.completed
  }
}

async function deleteChecklistItem(checklistId, itemId) {
  if (await boardsStore.deleteChecklistItem(itemId)) {
    const checklist = cardData.value.checklists.find(c => c.id === checklistId)
    if (checklist) {
      checklist.items = checklist.items.filter(i => i.id !== itemId)
    }
  }
}

function getItemAssignees(item) {
  if (!item.assigned_to) return []
  return item.assigned_to.split(',').map(e => e.trim()).filter(Boolean)
}

function isItemAssignee(item, email) {
  return getItemAssignees(item).includes(email)
}

async function toggleChecklistItemAssignee(item, email) {
  const current = getItemAssignees(item)
  let next
  if (current.includes(email)) {
    next = current.filter(e => e !== email)
  } else {
    next = [...current, email]
  }
  const assignedStr = next.join(',')
  const updated = await boardsStore.updateChecklistItem(item.id, { assigned_to: assignedStr || '' })
  if (updated) {
    item.assigned_to = updated.assigned_to ?? assignedStr
  }
}

async function assignGroupToItem(item, groupId) {
  const groupMembers = (colleaguesStore.colleagues || [])
    .filter(c => c.group_ids && c.group_ids.includes(groupId))
    .map(c => c.email)
  if (!groupMembers.length) return
  const current = getItemAssignees(item)
  const merged = [...new Set([...current, ...groupMembers])]
  const next = merged.join(',')
  const updated = await boardsStore.updateChecklistItem(item.id, { assigned_to: next })
  if (updated) {
    item.assigned_to = next
  }
}

async function clearChecklistItemAssignees(item) {
  checklistItemAssignDropdown.value = null
  const updated = await boardsStore.updateChecklistItem(item.id, { assigned_to: '' })
  if (updated) {
    item.assigned_to = ''
  }
}

function startEditingItem(checklistId, item) {
  editingChecklistItem.value = { checklistId, itemId: item.id }
  editingItemInput.value = item.title
}

function cancelEditingItem() {
  editingChecklistItem.value = null
  editingItemInput.value = ''
}

async function saveEditingItem() {
  if (!editingChecklistItem.value || !editingItemInput.value.trim()) {
    cancelEditingItem()
    return
  }
  
  const { checklistId, itemId } = editingChecklistItem.value
  const newTitle = editingItemInput.value.trim()
  
  const updated = await boardsStore.updateChecklistItem(itemId, { title: newTitle })
  if (updated) {
    const checklist = cardData.value.checklists.find(c => c.id === checklistId)
    if (checklist) {
      const item = checklist.items.find(i => i.id === itemId)
      if (item) {
        item.title = newTitle
      }
    }
  }
  
  cancelEditingItem()
}

function checklistProgress(checklist) {
  if (!checklist.items?.length) return 0
  const done = checklist.items.filter(i => i.completed).length
  return Math.round((done / checklist.items.length) * 100)
}

// Attachments
function triggerFileUpload() {
  fileInput.value?.click()
}

async function handleFileUpload(e) {
  const file = e.target.files?.[0]
  if (!file) return
  
  uploading.value = true
  showAttachmentMenu.value = false
  const folderId = assetManagerRef.value?.currentFolderId ?? null
  const attachment = await boardsStore.uploadAttachment(cardData.value.id, file, folderId)
  if (attachment) {
    cardData.value.attachments.push(attachment)
    toast.success('File uploaded')
  } else {
    toast.error('Failed to upload file')
  }
  uploading.value = false
  e.target.value = ''
}

function openDrivePicker() {
  showAttachmentMenu.value = false
  showDriveFilePicker.value = true
}

async function attachFromDrive(file) {
  uploading.value = true
  showDriveFilePicker.value = false
  
  try {
    const folderId = assetManagerRef.value?.currentFolderId ?? null
    const attachment = await boardsStore.addDriveAttachment(cardData.value.id, file.id, file.original_name, folderId)
    if (attachment) {
      cardData.value.attachments.push(attachment)
      toast.success('File attached from Drive')
    } else {
      toast.error('Failed to attach file')
    }
  } catch (e) {
    console.error('Failed to attach from drive:', e)
    toast.error('Failed to attach file')
  } finally {
    uploading.value = false
  }
}

function closeDrivePicker() {
  showDriveFilePicker.value = false
  linkDriveTarget.value = null
}

function openDrivePickerForChecklistItem(target) {
  linkDriveTarget.value = target
  showDriveFilePicker.value = true
}

async function handleDriveFileSelected(file) {
  if (linkDriveTarget.value) {
    // Linking a drive file to a checklist item
    const { checklistId, itemId } = linkDriveTarget.value
    try {
      await api.put(`/boards/checklist-items/${itemId}`, { drive_file_id: file.id })
      const checklist = cardData.value.checklists?.find(c => c.id === checklistId)
      if (checklist) {
        const item = checklist.items?.find(i => i.id === itemId)
        if (item) item.drive_file_id = file.id
      }
      toast.success('Drive file linked to checklist item')
    } catch (err) {
      console.error('Failed to link drive file:', err)
      toast.error('Failed to link file')
    }
    linkDriveTarget.value = null
    showDriveFilePicker.value = false
  } else {
    await attachFromDrive(file)
  }
}

function formatFileSize(bytes) {
  if (!bytes) return ''
  if (bytes < 1024) return bytes + ' B'
  if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB'
  return (bytes / (1024 * 1024)).toFixed(1) + ' MB'
}

async function deleteAttachment(attachmentId) {
  if (await boardsStore.deleteAttachment(attachmentId)) {
    cardData.value.attachments = cardData.value.attachments.filter(a => a.id !== attachmentId)
    toast.success('Attachment deleted')
  }
}

async function setAsCover(attachmentId) {
  if (await boardsStore.setCardCover(cardData.value.id, attachmentId)) {
    toast.success('Cover set')
  }
}

const previewImageUrl = ref(null)
const previewImageName = ref('')
const previewLoading = ref(false)

async function openAttachment(attachment) {
  if (attachment.drive_file_id) {
    openInDrive(attachment)
    return
  }
  if (!attachment.url) return

  const isImage = /\.(jpg|jpeg|png|gif|webp)$/i.test(attachment.name || attachment.url)

  if (isImage && attachment.url.includes('/api/inline-image/')) {
    previewImageName.value = attachment.name || 'Image'
    previewLoading.value = true
    previewImageUrl.value = null
    try {
      const urlPath = new URL(attachment.url).pathname.replace(/^\/api/, '')
      const resp = await api.get(urlPath, { responseType: 'blob' })
      const blob = new Blob([resp.data], { type: resp.headers['content-type'] || 'image/png' })
      previewImageUrl.value = URL.createObjectURL(blob)
    } catch {
      previewImageUrl.value = null
      previewLoading.value = false
    }
    previewLoading.value = false
    return
  }
  window.open(attachment.url, '_blank')
}

function closeImagePreview() {
  if (previewImageUrl.value) {
    URL.revokeObjectURL(previewImageUrl.value)
  }
  previewImageUrl.value = null
  previewImageName.value = ''
}

function handleLightboxKeydown(e) {
  if (e.key === 'Escape' && (previewImageUrl.value || previewLoading.value)) {
    e.stopPropagation()
    closeImagePreview()
  }
}

watch(previewImageUrl, (val) => {
  if (val) {
    window.addEventListener('keydown', handleLightboxKeydown, true)
  } else {
    window.removeEventListener('keydown', handleLightboxKeydown, true)
  }
})

// Open file in Drive
function openInDrive(attachment) {
  if (!attachment.drive_file_id) return
  
  // Navigate to drive with folder and file selection
  const query = { file: attachment.drive_file_id }
  if (attachment.folder_id) {
    query.folder = attachment.folder_id
  }
  
  // Close modal and navigate
  emit('close')
  router.push({ name: 'drive', query })
}

// Comments
async function handleCommentPaste(e) {
  const items = e.clipboardData?.items
  if (!items || items.length === 0) {
    isDebugEnabled() && console.log('[Comment Paste] No clipboard items found')
    return
  }
  
  isDebugEnabled() && console.log('[Comment Paste] Clipboard items:', Array.from(items).map(item => item.type))
  
  // Check if clipboard contains an image
  for (let i = 0; i < items.length; i++) {
    const item = items[i]
    if (item.type && item.type.indexOf('image') !== -1) {
      isDebugEnabled() && console.log('[Comment Paste] Image detected:', item.type)
      e.preventDefault()
      e.stopPropagation()
      
      const blob = item.getAsFile()
      if (!blob) {
        console.error('[Comment Paste] Failed to get file from clipboard item')
        return
      }
      
      isDebugEnabled() && console.log('[Comment Paste] Blob created:', blob.type, blob.size)
      
      // Convert blob to File object
      const file = new File([blob], `screenshot-${Date.now()}.png`, { type: blob.type || 'image/png' })
      
      // Upload to drive
      await uploadImageToComment(file)
      return
    }
  }
  
  isDebugEnabled() && console.log('[Comment Paste] No image found in clipboard, allowing default paste behavior')
}

async function uploadImageToComment(file) {
  isDebugEnabled() && console.log('[Upload Image] Starting upload:', file.name, file.type, file.size)
  uploadingImage.value = true
  
  try {
    // Get board folder for this card (Boards / [Board Name])
    let boardFolderId = null
    if (cardData.value?.board_id) {
      try {
        // Get board info to get board name
        const board = await boardsStore.getBoard(cardData.value.board_id)
        if (board?.name) {
          // Get or create board folder
          const folderResponse = await api.post('/drive/board-folder', {
            board_name: board.name
          })
          
          if (folderResponse.data.success && folderResponse.data.data?.folder?.id) {
            boardFolderId = folderResponse.data.data.folder.id
            isDebugEnabled() && console.log('[Upload Image] Using board folder:', boardFolderId)
          }
        }
      } catch (e) {
        console.warn('[Upload Image] Failed to get board folder, uploading to root:', e)
      }
    }
    
    // Upload to drive (in board folder if available)
    isDebugEnabled() && console.log('[Upload Image] Calling driveStore.uploadFile with folder:', boardFolderId)
    const uploadResult = await driveStore.uploadFile(file, boardFolderId)
    
    isDebugEnabled() && console.log('[Upload Image] Upload result:', uploadResult)
    
    if (!uploadResult || !uploadResult.success || !uploadResult.file) {
      const errorMsg = uploadResult?.error || 'Unknown error'
      console.error('[Upload Image] Upload failed:', errorMsg)
      toast.error(`Failed to upload image: ${errorMsg}`)
      return
    }
    
    // Create a share link for the image so it can be accessed without auth
    // Share for 30 days (720 hours) - long enough for comments
    isDebugEnabled() && console.log('[Upload Image] Creating share link for file ID:', uploadResult.file.id)
    const shareResult = await driveStore.createShareLink(uploadResult.file.id, 720, false)
    
    isDebugEnabled() && console.log('[Upload Image] Share link result:', shareResult)
    
    let imageUrl
    if (shareResult && shareResult.success && shareResult.url) {
      // Use the share URL which doesn't require authentication
      imageUrl = shareResult.url
      isDebugEnabled() && console.log('[Upload Image] Using share URL:', imageUrl)
    } else {
      // Share link creation failed - log error and show warning
      console.error('[Upload Image] Share link creation failed:', shareResult)
      toast.error('Failed to create share link - image may not display')
      
      // Still try to use preview URL as fallback (won't work without auth, but at least we tried)
      const baseUrl = import.meta.env.VITE_API_URL || '/api'
      imageUrl = `${baseUrl}/drive/files/${uploadResult.file.id}/preview`
      isDebugEnabled() && console.log('[Upload Image] Fallback to preview URL:', imageUrl)
      console.warn('[Upload Image] Preview URL requires auth - image will not display')
    }
    
    // Insert image HTML into comment content at cursor position
    const textarea = commentTextarea.value
    if (textarea) {
      const cursorPos = textarea.selectionStart || 0
      const textBefore = newCommentContent.value.substring(0, cursorPos)
      const textAfter = newCommentContent.value.substring(cursorPos)
      
      // Add image HTML
      const imageHtml = `<img src="${imageUrl}" alt="Screenshot" style="max-width: 100%; border-radius: 8px; margin: 8px 0;" />`
      
      // Add newline before image if there's text before
      const separator = textBefore.trim() ? '\n\n' : ''
      newCommentContent.value = textBefore + separator + imageHtml + (textAfter ? '\n\n' + textAfter : '')
      
      isDebugEnabled() && console.log('[Upload Image] Image HTML inserted into comment content')
      
      // Set cursor position after the image
      await nextTick()
      const newPos = cursorPos + separator.length + imageHtml.length + (textAfter ? 2 : 0)
      textarea.setSelectionRange(newPos, newPos)
      textarea.focus()
      
      toast.success('Screenshot uploaded and inserted')
    } else {
      console.error('[Upload Image] Textarea not found')
      toast.error('Failed to insert image')
    }
  } catch (e) {
    console.error('[Upload Image] Exception:', e)
    toast.error(`Failed to upload image: ${e.message}`)
  } finally {
    uploadingImage.value = false
  }
}

async function addComment() {
  if (!newCommentContent.value.trim()) return
  
  const comment = await addCardComment(cardData.value.id, newCommentContent.value.trim())
  if (comment) {
    cardData.value.comments.unshift(comment)
    newCommentContent.value = ''
  }
}

async function deleteComment(commentId) {
  if (await boardsStore.deleteComment(commentId)) {
    cardData.value.comments = cardData.value.comments.filter(c => c.id !== commentId)
  }
}

async function setCardColor(color) {
  const updated = await updateCard(cardData.value.id, { card_color: color })
  if (updated) {
    cardData.value.card_color = updated.card_color
  }
  showColorPicker.value = false
}

// Card actions
async function toggleComplete() {
  const updated = await updateCard(cardData.value.id, { completed: !cardData.value.completed })
  if (updated) {
    cardData.value.completed = updated.completed
    cardData.value.completed_at = updated.completed_at
  }
}

async function archiveCard() {
  const updated = await updateCard(cardData.value.id, { archived: true })
  if (updated) {
    toast.success('Card archived')
    close()
  }
}

function confirmDeleteCard() {
  showDeleteConfirm.value = true
}

async function deleteCard() {
  showDeleteConfirm.value = false
  
  if (await boardsStore.deleteCard(cardData.value.id)) {
    toast.success('Card deleted')
    close()
  }
}

async function moveToList(listId) {
  if (listId === cardData.value.list_id) return
  
  const updated = await boardsStore.moveCard(cardData.value.id, listId)
  if (updated) {
    cardData.value.list_id = listId
    toast.success('Card moved')
  }
}

// Attachment helpers
function isImageAttachment(attachment) {
  if (attachment.mime_type?.startsWith('image/')) return true
  const ext = (attachment.name || attachment.original_name || '').split('.').pop()?.toLowerCase()
  return ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'bmp'].includes(ext)
}

function getAttachmentThumbnailUrl(attachment) {
  if (!attachment.drive_file_id) return null
  const cached = thumbnailUrls.value[attachment.drive_file_id]
  if (cached === 'error' || cached === 'loading') return null
  if (cached) return cached
  loadThumbnail(attachment.drive_file_id)
  return null
}

// Load thumbnail with authentication
async function loadThumbnail(driveFileId) {
  if (thumbnailUrls.value[driveFileId]) return // Already loaded or loading
  
  // Mark as loading to prevent duplicate requests
  thumbnailUrls.value[driveFileId] = 'loading'
  
  try {
    const response = await api.get(`/drive/files/${driveFileId}/preview`, {
      responseType: 'blob'
    })
    
    if (response.data) {
      const blobUrl = URL.createObjectURL(response.data)
      thumbnailUrls.value[driveFileId] = blobUrl
    }
  } catch (e) {
    console.error('Failed to load thumbnail:', e)
    thumbnailUrls.value[driveFileId] = 'error'
  }
}

// Load all thumbnails when attachments are available
function loadAllThumbnails() {
  if (!cardData.value?.attachments) return
  
  for (const attachment of cardData.value.attachments) {
    if (attachment.drive_file_id && isImageAttachment(attachment)) {
      loadThumbnail(attachment.drive_file_id)
    }
  }
}

async function openAttachmentPreview(attachment) {
  if (attachment.type === 'url' && attachment.url) {
    window.open(attachment.url, '_blank')
    return
  }
  if (!attachment.drive_file_id) return

  const forceDownload = attachment._forceDownload
  const isImage = !forceDownload && isImageAttachment(attachment)

  if (isImage) {
    const existingBlob = thumbnailUrls.value[attachment.drive_file_id]
    if (existingBlob && existingBlob !== 'loading') {
      window.open(existingBlob, '_blank')
      return
    }
  }

  try {
    const endpoint = isImage ? 'preview' : 'download'
    const response = await api.get(`/drive/files/${attachment.drive_file_id}/${endpoint}`, {
      responseType: 'blob'
    })
    if (response.data) {
      const blobUrl = URL.createObjectURL(response.data)
      if (isImage) {
        thumbnailUrls.value[attachment.drive_file_id] = blobUrl
        window.open(blobUrl, '_blank')
      } else {
        const link = document.createElement('a')
        link.href = blobUrl
        link.download = attachment.name || attachment.original_name || 'file'
        document.body.appendChild(link)
        link.click()
        document.body.removeChild(link)
        URL.revokeObjectURL(blobUrl)
      }
    }
  } catch (e) {
    console.error('Failed to open attachment:', e)
    const status = e?.response?.status
    if (status === 404) {
      toast.error('File no longer exists on the server')
    } else {
      toast.error('Failed to open file')
    }
  }
}

// Format helpers
function formatDate(dateStr) {
  if (!dateStr) return ''
  const date = new Date(dateStr)
  return date.toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' })
}

function formatDateTime(dateStr) {
  if (!dateStr) return ''
  const date = new Date(dateStr)
  return date.toLocaleString(undefined, { 
    month: 'short', day: 'numeric', 
    hour: '2-digit', minute: '2-digit' 
  })
}

// Track WebSocket unsubscribe functions
const wsUnsubscribers = []

// Lifecycle
function handleCardBodyClick(e) {
  if (checklistItemAssignDropdown.value && !e.target.closest('.checklist-assign-area')) {
    checklistItemAssignDropdown.value = null
  }
  if (showAttachmentMenu.value && !e.target.closest('.attachment-menu-area')) {
    showAttachmentMenu.value = false
  }
}

function handleBeforeUnload() {
  stopTimerSync()
}

onMounted(() => {
  loadCard()
  colleaguesStore.init()
  window.addEventListener('resize', updateMobileState)
  document.addEventListener('click', handleCardBodyClick)
  window.addEventListener('beforeunload', handleBeforeUnload)
  window.addEventListener('pagehide', handleBeforeUnload)

  // Subscribe to real-time checklist updates for this card
  // Update locally instead of reloading to prevent flicker
  const unsubChecklist = on(EventTypes.CHECKLIST_UPDATED, (payload) => {
    if (payload.card_id === props.card.id && payload.item_id && payload.completed !== undefined) {
      // Find and update the specific item locally - no reload needed
      for (const checklist of cardData.value?.checklists || []) {
        const item = checklist.items?.find(i => i.id === payload.item_id)
        if (item) {
          item.completed = payload.completed
          isDebugEnabled() && console.log('[CardModal] Updated checklist item locally:', payload.item_id, payload.completed)
          return
        }
      }
    }
  })
  wsUnsubscribers.push(unsubChecklist)
  
  // Also subscribe to card updates (title, description, etc - not checklists)
  const unsubCard = on(EventTypes.CARD_UPDATED, (payload) => {
    if (payload.card_id === props.card.id) {
      const action = payload.action || ''
      if (action === 'subtask_created' || action === 'subtask_deleted') return
      isDebugEnabled() && console.log('[CardModal] Card updated, silent refresh...')
      // Silent refresh — do NOT call loadCard() here. loadCard() toggles
      // `loading` which unmounts inner content (SubtasksList) and triggers
      // its onMounted/watch chain, creating an infinite reload loop when
      // SubtasksList auto-syncs the parent's `completed` flag.
      silentRefreshCard()
    }
  })
  wsUnsubscribers.push(unsubCard)
})

onBeforeUnmount(() => {
  stopTimerSync()
  window.removeEventListener('resize', updateMobileState)
  window.removeEventListener('beforeunload', handleBeforeUnload)
  window.removeEventListener('pagehide', handleBeforeUnload)
  document.removeEventListener('click', handleCardBodyClick)

  // Cleanup blob URLs
  for (const url of Object.values(thumbnailUrls.value)) {
    if (url && url !== 'loading' && url.startsWith('blob:')) {
      URL.revokeObjectURL(url)
    }
  }
  thumbnailUrls.value = {}
  
  // Cleanup WebSocket subscriptions
  for (const unsub of wsUnsubscribers) {
    if (typeof unsub === 'function') {
      unsub()
    }
  }
})
</script>

<template>
  <Teleport to="body" :disabled="inlineMode">
    <div 
      :class="[
        inlineMode ? 'w-full h-full' : 'fixed inset-0 z-50',
        !inlineMode && isMobile ? 'bg-black/60' :
        !inlineMode && cardLayout === 'sidebar' ? 'pointer-events-none' :
        !inlineMode && cardLayout === 'fullscreen' ? 'bg-black/60' :
        !inlineMode ? 'bg-black/60 flex items-start justify-center overflow-y-auto py-8 px-4' : ''
      ]"
      @mousedown.self="!inlineMode && close()"
    >
      <div :class="[
        'bg-white dark:bg-surface-800 flex flex-col overflow-hidden pointer-events-auto',
        inlineMode ? 'w-full h-full' :
        isMobile ? 'w-full h-full' :
        cardLayout === 'fullscreen' ? 'w-full h-full' :
        cardLayout === 'sidebar' ? 'fixed top-0 right-0 h-full w-full max-w-xl shadow-2xl border-l border-surface-200 dark:border-surface-700' :
        'rounded-2xl shadow-2xl w-[95vw] max-h-[calc(100vh-2rem)]'
      ]"
      >
        <!-- Loading -->
        <div v-if="loading" class="p-12 flex justify-center">
          <span class="material-symbols-rounded text-4xl text-primary-500 animate-spin">progress_activity</span>
        </div>
        
        <template v-else-if="cardData">
          <!-- Header -->
          <div :class="isMobile ? 'px-4 pt-16 pb-3' : 'px-6 pt-5 pb-4'" class="border-b border-surface-200 dark:border-surface-700 shrink-0">
            <div class="flex items-start gap-3 md:gap-4">
              <!-- Completion checkbox -->
              <button 
                @click="toggleComplete"
                :class="[
                  'mt-0.5 md:mt-1 w-5 md:w-6 h-5 md:h-6 rounded-lg border-2 flex items-center justify-center shrink-0 transition-all',
                  cardData.completed 
                    ? 'bg-primary-500 border-primary-500 text-white' 
                    : 'border-surface-300 dark:border-surface-600 hover:border-primary-500'
                ]"
              >
                <span v-if="cardData.completed" class="material-symbols-rounded text-xs md:text-sm">check</span>
              </button>
              
              <!-- Title -->
              <div class="flex-1 min-w-0">
                <div v-if="editingTitle">
                  <input
                    id="card-title-input"
                    v-model="titleInput"
                    type="text"
                    class="w-full px-2 py-1 text-base md:text-xl font-semibold bg-surface-100 dark:bg-surface-700 border border-surface-300 dark:border-surface-600 rounded-lg text-surface-900 dark:text-surface-100 outline-none focus:border-primary-500"
                    @keydown.enter="saveTitle"
                    @keydown.escape="editingTitle = false"
                    @blur="saveTitle"
                  />
                </div>
                <h2 
                  v-else
                  @click="startEditTitle"
                  class="text-base md:text-xl font-semibold text-surface-900 dark:text-surface-100 cursor-pointer hover:bg-surface-100 dark:hover:bg-surface-700 px-2 py-1 -mx-2 rounded-lg break-words"
                  :class="{ 'line-through text-surface-500': cardData.completed }"
                >
                  {{ cardData.title }}
                </h2>
                
                <p class="text-xs md:text-sm text-surface-500 mt-1 px-2">
                  in list <span class="font-medium">{{ currentList?.name }}</span>
                </p>
                <button
                  v-if="projectHubEnabled && resolvedOriginalCardLink?.parent_card_id"
                  type="button"
                  class="mt-2 ml-2 inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-medium bg-primary-50 dark:bg-primary-900/20 text-primary-600 dark:text-primary-300 hover:bg-primary-100 dark:hover:bg-primary-900/30 transition-colors"
                  @click="openOriginalCard"
                >
                  <span class="material-symbols-rounded text-[14px]">arrow_back</span>
                  Back to original card
                </button>
              </div>

              <!-- Timer display -->
              <div 
                v-if="timerRunning && projectHubEnabled"
                class="flex items-center gap-1.5 px-2.5 py-1 bg-emerald-50 dark:bg-emerald-900/30 border border-emerald-200 dark:border-emerald-700 rounded-full shrink-0"
              >
                <span class="material-symbols-rounded text-[14px] text-emerald-500 animate-pulse">timer</span>
                <span class="text-xs font-mono font-semibold text-emerald-700 dark:text-emerald-300">{{ formatElapsed(elapsed) }}</span>
              </div>

              <!-- Add Time button (Project Hub) -->
              <button
                v-if="projectHubEnabled && cardData?.id"
                @click="showManualTimeEntry = true"
                class="flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-medium bg-violet-50 dark:bg-violet-900/20 text-violet-600 dark:text-violet-300 hover:bg-violet-100 dark:hover:bg-violet-900/40 border border-violet-200 dark:border-violet-700 transition-colors shrink-0"
                title="Manually add time"
              >
                <span class="material-symbols-rounded text-[14px]">more_time</span>
                Add Time
              </button>

              <!-- Mobile actions button -->
              <button 
                v-if="isMobile"
                @click="showMobileSidebar = true"
                class="p-1.5 hover:bg-surface-100 dark:hover:bg-surface-700 rounded-lg transition-colors"
              >
                <span class="material-symbols-rounded text-surface-500">more_vert</span>
              </button>
              
              <!-- Actions dropdown (desktop) -->
              <div v-if="!isMobile" class="relative">
                <button 
                  @click="showActionsMenu = !showActionsMenu"
                  class="p-1.5 md:p-2 hover:bg-surface-100 dark:hover:bg-surface-700 rounded-lg transition-colors"
                  title="Card actions"
                >
                  <span class="material-symbols-rounded text-surface-500 text-[20px]">more_horiz</span>
                </button>
                <!-- Backdrop -->
                <div v-if="showActionsMenu" class="fixed inset-0 z-[59]" @click="showActionsMenu = false"></div>
                <!-- Dropdown -->
                <div 
                  v-if="showActionsMenu"
                  class="absolute right-0 top-full mt-1 w-52 bg-white dark:bg-surface-800 rounded-xl shadow-xl border border-surface-200 dark:border-surface-700 py-1.5 z-[60]"
                >
                  <p class="px-3 py-1 text-[10px] font-semibold text-surface-400 uppercase tracking-wider">Add to card</p>
                  <button @click="showLabelPicker = !showLabelPicker; showActionsMenu = false" class="w-full px-3 py-2 text-left text-sm text-surface-700 dark:text-surface-300 hover:bg-surface-100 dark:hover:bg-surface-700 flex items-center gap-2.5">
                    <span class="material-symbols-rounded text-[18px] text-surface-400">label</span> Labels
                  </button>
                  <button @click="addingChecklist = !addingChecklist; showActionsMenu = false" class="w-full px-3 py-2 text-left text-sm text-surface-700 dark:text-surface-300 hover:bg-surface-100 dark:hover:bg-surface-700 flex items-center gap-2.5">
                    <span class="material-symbols-rounded text-[18px] text-surface-400">checklist</span> Checklist
                  </button>
                  <button @click="openStartDatePicker(); showActionsMenu = false" class="w-full px-3 py-2 text-left text-sm text-surface-700 dark:text-surface-300 hover:bg-surface-100 dark:hover:bg-surface-700 flex items-center gap-2.5">
                    <span class="material-symbols-rounded text-[18px] text-surface-400">event</span> Start date
                  </button>
                  <button @click="openDueDatePicker(); showActionsMenu = false" class="w-full px-3 py-2 text-left text-sm text-surface-700 dark:text-surface-300 hover:bg-surface-100 dark:hover:bg-surface-700 flex items-center gap-2.5">
                    <span class="material-symbols-rounded text-[18px] text-surface-400">schedule</span> Due date
                  </button>
                  <button @click="showAttachmentMenu = !showAttachmentMenu; showActionsMenu = false" class="w-full px-3 py-2 text-left text-sm text-surface-700 dark:text-surface-300 hover:bg-surface-100 dark:hover:bg-surface-700 flex items-center gap-2.5">
                    <span class="material-symbols-rounded text-[18px] text-surface-400">attach_file</span> Attachment
                  </button>
                  <button v-if="!projectHubEnabled" @click="showMemberPicker = !showMemberPicker; showActionsMenu = false" class="w-full px-3 py-2 text-left text-sm text-surface-700 dark:text-surface-300 hover:bg-surface-100 dark:hover:bg-surface-700 flex items-center gap-2.5">
                    <span class="material-symbols-rounded text-[18px] text-surface-400">person_add</span> Members
                  </button>
                  <button @click="showColorPicker = !showColorPicker; showActionsMenu = false" class="w-full px-3 py-2 text-left text-sm text-surface-700 dark:text-surface-300 hover:bg-surface-100 dark:hover:bg-surface-700 flex items-center gap-2.5">
                    <span class="material-symbols-rounded text-[18px] text-surface-400">palette</span> Card color
                    <span v-if="cardData.card_color" class="w-3.5 h-3.5 rounded-full border border-black/10 ml-auto" :style="{ backgroundColor: cardData.card_color }"></span>
                  </button>
                  <div class="border-t border-surface-200 dark:border-surface-700 my-1.5"></div>
                  <p class="px-3 py-1 text-[10px] font-semibold text-surface-400 uppercase tracking-wider">Actions</p>
                  <button class="w-full px-3 py-2 text-left text-sm text-surface-700 dark:text-surface-300 hover:bg-surface-100 dark:hover:bg-surface-700 flex items-center gap-2.5 group/move relative">
                    <span class="material-symbols-rounded text-[18px] text-surface-400">arrow_forward</span> Move
                    <span class="material-symbols-rounded text-sm text-surface-400 ml-auto">chevron_right</span>
                    <div class="absolute left-full top-0 ml-1 w-48 bg-white dark:bg-surface-800 rounded-xl shadow-xl border border-surface-200 dark:border-surface-700 py-1.5 opacity-0 invisible group-hover/move:opacity-100 group-hover/move:visible transition-all z-[61]">
                      <button
                        v-for="list in lists"
                        :key="'move-' + list.id"
                        @click="moveToList(list.id); showActionsMenu = false"
                        :class="['w-full px-3 py-2 text-left text-sm hover:bg-surface-100 dark:hover:bg-surface-700 flex items-center gap-2', list.id === cardData.list_id ? 'text-primary-500' : 'text-surface-700 dark:text-surface-300']"
                      >
                        {{ list.name }}
                        <span v-if="list.id === cardData.list_id" class="material-symbols-rounded text-sm ml-auto">check</span>
                      </button>
                    </div>
                  </button>
                  <button @click="archiveCard(); showActionsMenu = false" class="w-full px-3 py-2 text-left text-sm text-surface-700 dark:text-surface-300 hover:bg-surface-100 dark:hover:bg-surface-700 flex items-center gap-2.5">
                    <span class="material-symbols-rounded text-[18px] text-surface-400">archive</span> Archive
                  </button>
                  <button @click="confirmDeleteCard(); showActionsMenu = false" class="w-full px-3 py-2 text-left text-sm text-red-500 hover:bg-red-50 dark:hover:bg-red-900/20 flex items-center gap-2.5">
                    <span class="material-symbols-rounded text-[18px]">delete</span> Delete
                  </button>
                </div>
              </div>

              <!-- Close button (not in inline mode - parent provides back nav) -->
              <button 
                v-if="!inlineMode"
                @click="close"
                class="p-1.5 md:p-2 hover:bg-surface-100 dark:hover:bg-surface-700 rounded-lg transition-colors"
              >
                <span class="material-symbols-rounded text-surface-500">close</span>
              </button>
            </div>

            <!-- Metadata pills -->
            <div v-if="!isMobile" class="flex flex-wrap items-center gap-2 mt-3 ml-9">
              <!-- Status pill -->
              <div
                class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-medium"
                :class="cardData.completed
                  ? 'bg-green-100 dark:bg-green-900/30 text-green-700 dark:text-green-400'
                  : 'bg-primary-100 dark:bg-primary-900/30 text-primary-700 dark:text-primary-400'"
              >
                <span class="material-symbols-rounded text-[13px]">{{ cardData.completed ? 'check_circle' : 'radio_button_unchecked' }}</span>
                {{ cardData.completed ? 'Complete' : (currentList?.name || 'Open') }}
              </div>
              <!-- Date pill -->
              <div
                v-if="cardData.start_date || cardData.due_date"
                class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-medium"
                :class="!cardData.completed && cardData.due_date && new Date(cardData.due_date) < new Date()
                  ? 'bg-red-100 dark:bg-red-900/30 text-red-600 dark:text-red-400'
                  : 'bg-surface-100 dark:bg-surface-700 text-surface-600 dark:text-surface-300'"
              >
                <span class="material-symbols-rounded text-[13px]">calendar_today</span>
                <span v-if="cardData.start_date">{{ formatDate(cardData.start_date) }}</span>
                <span v-if="cardData.start_date && cardData.due_date" class="text-surface-400">-</span>
                <span v-if="cardData.due_date">{{ formatDate(cardData.due_date) }}</span>
              </div>
              <!-- Assignee pill (only in basic Kanban mode, PH has its own panel) -->
              <div
                v-if="cardData.assigned_to && !projectHubEnabled"
                class="inline-flex items-center gap-1.5 px-1 pr-2.5 py-0.5 rounded-full bg-surface-100 dark:bg-surface-700 text-xs font-medium text-surface-600 dark:text-surface-300"
              >
                <UserAvatar :email="cardData.assigned_to" size="xs" :show-presence="true" />
                {{ cardData.assigned_to.split('@')[0] }}
              </div>
              <!-- Label pills -->
              <span
                v-for="label in cardLabels.slice(0, 5)"
                :key="'field-' + label.id"
                class="px-2.5 py-1 rounded-full text-[11px] font-semibold text-white"
                :style="{ backgroundColor: label.color }"
              >
                {{ label.name || 'Label' }}
              </span>
              <span v-if="cardLabels.length > 5" class="text-[11px] text-surface-400">+{{ cardLabels.length - 5 }}</span>
              <!-- Card color dot -->
              <span v-if="cardData.card_color" class="w-4 h-4 rounded-full border border-black/10 shrink-0" :style="{ backgroundColor: cardData.card_color }"></span>
              <!-- Time Budget display -->
              <template v-if="projectHubEnabled">
                <!-- Has estimate + tracked time: combined pill -->
                <div
                  v-if="cardData.time_estimate_seconds && cardTrackedTime > 0"
                  class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-[11px] font-semibold leading-none"
                  :class="timeBudgetStatus === 'exceeded'
                    ? 'bg-red-100 dark:bg-red-900/30 text-red-600 dark:text-red-400'
                    : timeBudgetStatus === 'warning'
                      ? 'bg-amber-100 dark:bg-amber-900/30 text-amber-700 dark:text-amber-400'
                      : 'bg-emerald-50 dark:bg-emerald-900/20 text-emerald-700 dark:text-emerald-400'"
                >
                  <span class="material-symbols-rounded text-[12px]">{{ timeBudgetStatus === 'exceeded' ? 'warning' : 'timer' }}</span>
                  {{ fmtTimePill(cardTrackedTime) }}
                  <span class="opacity-50">/</span>
                  {{ fmtTimePill(cardData.time_estimate_seconds) }}
                  <span v-if="timeBudgetStatus === 'exceeded'" class="text-[10px] font-bold opacity-70">(+{{ timeBudgetPct - 100 }}%)</span>
                  <button
                    @click.stop="openEstimateEditor"
                    class="ml-0.5 -my-0.5 p-0 rounded hover:bg-black/10 dark:hover:bg-white/10 transition-colors"
                    title="Edit estimate"
                  >
                    <span class="material-symbols-rounded text-[11px]">edit</span>
                  </button>
                </div>
                <!-- Has estimate, no tracked time yet -->
                <button
                  v-else-if="cardData.time_estimate_seconds"
                  class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-medium bg-blue-100 dark:bg-blue-900/30 text-blue-700 dark:text-blue-400 transition-colors"
                  @click="openEstimateEditor"
                  title="Edit time estimate"
                >
                  <span class="material-symbols-rounded text-[13px]">hourglass_top</span>
                  {{ fmtTimePill(cardData.time_estimate_seconds) }} est
                </button>
                <!-- No estimate, but has tracked time -->
                <div
                  v-else-if="cardTrackedTime > 0"
                  class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-medium bg-emerald-100 dark:bg-emerald-900/30 text-emerald-700 dark:text-emerald-400"
                >
                  <span class="material-symbols-rounded text-[13px]">timer</span>
                  {{ fmtTimePill(cardTrackedTime) }} tracked
                </div>
                <!-- No estimate, no tracked time: button to add -->
                <button
                  v-else
                  class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-medium bg-surface-100 dark:bg-surface-700 text-surface-400 hover:text-surface-600 dark:hover:text-surface-300 transition-colors"
                  @click="openEstimateEditor"
                  title="Set time estimate"
                >
                  <span class="material-symbols-rounded text-[13px]">hourglass_top</span>
                  Estimate
                </button>
              </template>
            </div>
            <!-- Time budget progress bar -->
            <div
              v-if="projectHubEnabled && cardData?.time_estimate_seconds > 0 && cardTrackedTime > 0 && !isMobile"
              class="ml-9 mt-2"
            >
              <div class="h-1.5 rounded-full bg-surface-200 dark:bg-surface-700 overflow-hidden">
                <div
                  class="h-full rounded-full transition-all duration-300"
                  :class="timeBudgetStatus === 'exceeded'
                    ? 'bg-red-500'
                    : timeBudgetStatus === 'warning'
                      ? 'bg-amber-500'
                      : 'bg-emerald-500'"
                  :style="{ width: Math.min(timeBudgetPct, 100) + '%' }"
                />
              </div>
              <div v-if="timeBudgetStatus === 'exceeded'" class="flex items-center gap-1 mt-1">
                <span class="material-symbols-rounded text-[12px] text-red-500">error</span>
                <span class="text-[10px] font-medium text-red-500">Over budget by {{ fmtTimePill(cardTrackedTime - cardData.time_estimate_seconds) }}</span>
              </div>
            </div>
            <!-- Inline time estimate editor -->
            <div v-if="editingTimeEstimate && !isMobile" class="ml-9 mt-2 flex items-center gap-2">
              <input
                v-model="timeEstimateHours" type="number" min="0" placeholder="h"
                class="w-16 px-2 py-1.5 text-sm rounded-lg border border-surface-300 dark:border-surface-600 bg-white dark:bg-surface-700 text-surface-900 dark:text-surface-100 focus:ring-2 focus:ring-primary-400 focus:border-primary-400"
                @keydown.enter="saveTimeEstimate" @keydown.escape="cancelEstimateEdit"
              />
              <span class="text-xs text-surface-400">h</span>
              <input
                v-model="timeEstimateMins" type="number" min="0" max="59" placeholder="m"
                class="w-16 px-2 py-1.5 text-sm rounded-lg border border-surface-300 dark:border-surface-600 bg-white dark:bg-surface-700 text-surface-900 dark:text-surface-100 focus:ring-2 focus:ring-primary-400 focus:border-primary-400"
                @keydown.enter="saveTimeEstimate" @keydown.escape="cancelEstimateEdit"
              />
              <span class="text-xs text-surface-400">m</span>
              <button @click="saveTimeEstimate" class="px-3 py-1.5 rounded-full bg-primary-500 text-white text-xs font-medium hover:bg-primary-600 transition-colors">Save</button>
              <button @click="cancelEstimateEdit" class="px-3 py-1.5 rounded-full bg-surface-200 dark:bg-surface-600 text-surface-600 dark:text-surface-300 text-xs font-medium hover:bg-surface-300 dark:hover:bg-surface-500 transition-colors">Cancel</button>
            </div>
          </div>

          <!-- Tab bar (outside header, clear visual separation) -->
          <div v-if="!isMobile" class="flex items-center gap-1 px-6 bg-surface-50/50 dark:bg-surface-800/50 border-b border-surface-200 dark:border-surface-700 shrink-0">
            <button
              v-for="tab in toolbarTabs"
              :key="tab.id"
              @click="activeTab = tab.id"
              class="px-4 py-2.5 text-xs font-semibold transition-colors border-b-2 -mb-px flex items-center gap-1.5"
              :class="activeTab === tab.id
                ? 'border-primary-500 text-primary-600 dark:text-primary-400'
                : 'border-transparent text-surface-400 hover:text-surface-600 dark:hover:text-surface-300'"
            >
              <span class="material-symbols-rounded text-[15px]">{{ tab.icon }}</span>
              {{ tab.label }}
              <span v-if="tab.badge" class="ml-0.5 min-w-[18px] h-[18px] rounded-full bg-surface-200 dark:bg-surface-600 text-[10px] font-bold text-surface-500 dark:text-surface-400 flex items-center justify-center">{{ tab.badge }}</span>
            </button>
          </div>
          
          <div class="flex flex-col lg:flex-row flex-1 min-h-0" :class="{ 'select-none': isResizing }">
            <!-- Main content -->
            <div class="flex-1 p-4 lg:p-5 space-y-3 lg:space-y-4 overflow-y-auto bg-surface-100 dark:bg-surface-900">
              
              <!-- === DETAILS TAB === -->
              <template v-if="activeTab === 'details' || isMobile">

              <!-- Description -->
              <div class="bg-white dark:bg-surface-800 rounded-xl p-4 shadow-sm border border-surface-200/60 dark:border-surface-700/60">
                <button
                  type="button"
                  class="w-full flex items-center justify-between group"
                  @click="toggleDescriptionCollapsed"
                >
                  <h4 class="text-xs font-semibold text-surface-500 uppercase tracking-wide flex items-center gap-2">
                    <span class="material-symbols-rounded text-lg">subject</span>
                    Description
                  </h4>
                  <span
                    class="material-symbols-rounded text-lg text-surface-400 group-hover:text-surface-600 dark:group-hover:text-surface-300 transition-transform duration-200"
                    :class="{ '-rotate-90': descriptionCollapsed }"
                  >expand_more</span>
                </button>
                
                <div v-if="!descriptionCollapsed" class="mt-2">
                  <div v-if="editingDescription">
                    <textarea
                      id="card-description-input"
                      v-model="descriptionInput"
                      rows="4"
                      placeholder="Add a description..."
                      class="w-full px-3 py-2 bg-surface-50 dark:bg-surface-700 border border-surface-200 dark:border-surface-600 rounded-xl text-surface-900 dark:text-surface-100 placeholder:text-surface-400 outline-none focus:border-primary-500 resize-none"
                    ></textarea>
                    <div class="flex gap-2 mt-2">
                      <button 
                        @click="saveDescription"
                        class="px-4 py-1.5 bg-primary-500 hover:bg-primary-600 text-white rounded-lg text-sm font-medium"
                      >
                        Save
                      </button>
                      <button 
                        @click="editingDescription = false"
                        class="px-4 py-1.5 text-surface-600 dark:text-surface-400 hover:bg-surface-100 dark:hover:bg-surface-700 rounded-lg text-sm"
                      >
                        Cancel
                      </button>
                    </div>
                  </div>
                  <div 
                    v-else
                    @click="startEditDescription"
                    class="px-3 py-2 bg-surface-50 dark:bg-surface-700 rounded-xl min-h-[80px] cursor-pointer hover:bg-surface-100 dark:hover:bg-surface-600 transition-colors"
                  >
                    <p v-if="cardData.description" class="text-sm text-surface-700 dark:text-surface-300 whitespace-pre-wrap leading-relaxed break-words" style="overflow-wrap: anywhere;">
                      {{ cardData.description.replace(/\r\n/g, '\n').replace(/\n{3,}/g, '\n\n').trim() }}
                    </p>
                    <p v-else class="text-sm text-surface-400">
                      Add a more detailed description...
                    </p>
                  </div>
                </div>
              </div>
              
              <!-- Checklists -->
              <div v-for="checklist in checklists" :key="checklist.id" class="bg-white dark:bg-surface-800 rounded-xl p-4 shadow-sm border border-surface-200/60 dark:border-surface-700/60">
                <div class="flex items-center justify-between mb-2">
                  <h4 class="text-xs font-semibold text-surface-500 uppercase tracking-wide flex items-center gap-2">
                    <span class="material-symbols-rounded text-lg">checklist</span>
                    {{ checklist.title }}
                  </h4>
                  <button 
                    @click="deleteChecklist(checklist.id)"
                    class="text-xs text-surface-500 hover:text-red-500 transition-colors"
                  >
                    Delete
                  </button>
                </div>
                
                <!-- Progress bar -->
                <div class="flex items-center gap-2 mb-3">
                  <span class="text-xs text-surface-500">{{ checklistProgress(checklist) }}%</span>
                  <div class="flex-1 h-2 bg-surface-200 dark:bg-surface-600 rounded-full overflow-hidden">
                    <div 
                      class="h-full bg-primary-500 transition-all"
                      :style="{ width: checklistProgress(checklist) + '%' }"
                    ></div>
                  </div>
                </div>
                
                <!-- Items -->
                <div class="space-y-1.5">
                  <div 
                    v-for="item in checklist.items"
                    :key="item.id"
                    class="flex items-center gap-2.5 group py-0.5"
                  >
                    <button 
                      @click="toggleChecklistItem(item)"
                      :class="[
                        'w-[18px] h-[18px] rounded border-2 flex items-center justify-center shrink-0 transition-colors',
                        item.completed 
                          ? 'bg-primary-500 border-primary-500 text-white' 
                          : 'border-surface-300 dark:border-surface-500 hover:border-primary-500'
                      ]"
                    >
                      <span v-if="item.completed" class="material-symbols-rounded text-xs">check</span>
                    </button>
                    
                    <!-- Editing mode -->
                    <template v-if="editingChecklistItem?.checklistId === checklist.id && editingChecklistItem?.itemId === item.id">
                      <input 
                        v-model="editingItemInput"
                        type="text"
                        class="flex-1 px-2 py-0.5 text-sm bg-white dark:bg-surface-800 border border-primary-500 rounded text-surface-900 dark:text-surface-100 outline-none"
                        @keydown.enter="saveEditingItem"
                        @keydown.escape="cancelEditingItem"
                        @blur="saveEditingItem"
                        autofocus
                      />
                      <button 
                        @click="saveEditingItem"
                        class="p-1 hover:text-primary-500 transition-all"
                      >
                        <span class="material-symbols-rounded text-sm">check</span>
                      </button>
                      <button 
                        @click="cancelEditingItem"
                        class="p-1 hover:text-red-500 transition-all"
                      >
                        <span class="material-symbols-rounded text-sm">close</span>
                      </button>
                    </template>
                    
                    <!-- Display mode -->
                    <template v-else>
                      <div class="flex-1 min-w-0">
                        <span 
                          class="text-sm cursor-pointer hover:bg-surface-100 dark:hover:bg-surface-700 px-1 rounded"
                          :class="item.completed ? 'text-surface-400 line-through' : 'text-surface-700 dark:text-surface-300'"
                          @click="startEditingItem(checklist.id, item)"
                        >
                          {{ item.title }}
                        </span>
                        <span
                          v-if="item.drive_file_id && projectHubEnabled"
                          class="inline-flex items-center gap-0.5 ml-1 px-1.5 py-0.5 rounded bg-blue-50 dark:bg-blue-900/20 text-blue-600 dark:text-blue-400 text-[10px]"
                        >
                          <span class="material-symbols-rounded text-[12px]">cloud</span>
                          Drive file linked
                        </span>
                      </div>

                      <!-- Checklist item assignees (Project Hub, multi-select) -->
                      <div v-if="projectHubEnabled" class="relative shrink-0 checklist-assign-area flex items-center">
                        <div v-if="getItemAssignees(item).length" class="flex -space-x-1.5 mr-1">
                          <UserAvatar
                            v-for="assigneeEmail in getItemAssignees(item)"
                            :key="'cia-' + assigneeEmail"
                            :email="assigneeEmail"
                            size="xs"
                            class="ring-2 ring-white dark:ring-surface-900 cursor-pointer"
                            :title="assigneeEmail"
                            @click.stop="checklistItemAssignDropdown = checklistItemAssignDropdown === item.id ? null : item.id"
                          />
                        </div>
                        <button
                          class="w-5 h-5 rounded-full border border-dashed border-surface-300 dark:border-surface-600 flex items-center justify-center transition-all hover:border-primary-400"
                          :class="getItemAssignees(item).length ? 'opacity-0 group-hover:opacity-100' : 'opacity-0 group-hover:opacity-100'"
                          title="Assign members"
                          @click.stop="checklistItemAssignDropdown = checklistItemAssignDropdown === item.id ? null : item.id"
                        >
                          <span class="material-symbols-rounded text-[11px] text-surface-400">person_add</span>
                        </button>
                        <div
                          v-if="checklistItemAssignDropdown === item.id"
                          class="absolute right-0 top-full mt-1 w-52 bg-white dark:bg-surface-800 border border-surface-200 dark:border-surface-700 rounded-xl shadow-xl z-[60] py-1 max-h-56 overflow-y-auto"
                        >
                          <div class="px-3 py-1.5 text-[10px] font-semibold text-surface-400 uppercase tracking-wide flex items-center justify-between">
                            <span>Assign members</span>
                            <button
                              @click.stop="checklistItemAssignDropdown = null"
                              class="p-0.5 hover:bg-surface-100 dark:hover:bg-surface-700 rounded"
                            ><span class="material-symbols-rounded text-[12px]">close</span></button>
                          </div>
                          <template v-if="projectHubEnabled && cardAssigneeEmails.size > 0">
                            <div class="px-3 py-1 text-[10px] font-semibold text-surface-400 uppercase tracking-wide">Card Assignees</div>
                            <button
                              v-for="member in members.filter(m => cardAssigneeEmails.has(m.email || m.user_email))"
                              :key="'clca-' + member.email"
                              class="w-full px-3 py-2 text-left text-xs hover:bg-primary-50 dark:hover:bg-primary-900/20 flex items-center gap-2 text-surface-700 dark:text-surface-300"
                              @click.stop="toggleChecklistItemAssignee(item, member.email)"
                            >
                              <UserAvatar :email="member.email" size="xs" class="shrink-0" />
                              <span class="truncate flex-1">{{ member.email.split('@')[0] }}</span>
                              <span
                                v-if="isItemAssignee(item, member.email)"
                                class="material-symbols-rounded text-[14px] text-primary-500 shrink-0"
                              >check_circle</span>
                            </button>
                            <div class="px-3 py-1 text-[10px] font-semibold text-surface-400 uppercase tracking-wide border-t border-surface-200 dark:border-surface-700 mt-0.5">Others</div>
                          </template>
                          <button
                            v-for="member in (projectHubEnabled && cardAssigneeEmails.size > 0) ? members.filter(m => !cardAssigneeEmails.has(m.email || m.user_email)) : members"
                            :key="'cli-' + member.email"
                            class="w-full px-3 py-2 text-left text-xs hover:bg-surface-100 dark:hover:bg-surface-700 flex items-center gap-2 text-surface-700 dark:text-surface-300"
                            @click.stop="toggleChecklistItemAssignee(item, member.email)"
                          >
                            <UserAvatar :email="member.email" size="xs" class="shrink-0" />
                            <span class="truncate flex-1">{{ member.email.split('@')[0] }}</span>
                            <span
                              v-if="isItemAssignee(item, member.email)"
                              class="material-symbols-rounded text-[14px] text-primary-500 shrink-0"
                            >check_circle</span>
                          </button>
                          <template v-if="colleaguesStore.groups?.length">
                            <div class="px-3 py-1.5 text-[10px] font-semibold text-surface-400 uppercase tracking-wide border-t border-surface-200 dark:border-surface-700 mt-0.5">
                              Groups
                            </div>
                            <button
                              v-for="group in colleaguesStore.groups"
                              :key="'clg-' + group.id"
                              class="w-full px-3 py-2 text-left text-xs hover:bg-surface-100 dark:hover:bg-surface-700 flex items-center gap-2 text-surface-700 dark:text-surface-300"
                              @click.stop="assignGroupToItem(item, group.id)"
                            >
                              <span class="material-symbols-rounded text-[14px] text-surface-400 shrink-0">group</span>
                              <span class="truncate flex-1">{{ group.name }}</span>
                              <span class="text-[10px] text-surface-400 shrink-0">+ all</span>
                            </button>
                          </template>
                          <button
                            v-if="getItemAssignees(item).length"
                            class="w-full px-3 py-2 text-left text-xs text-red-500 hover:bg-red-50 dark:hover:bg-red-900/20 flex items-center gap-2 border-t border-surface-200 dark:border-surface-700 mt-0.5"
                            @click.stop="clearChecklistItemAssignees(item)"
                          >
                            <span class="material-symbols-rounded text-[14px]">person_remove</span>
                            Remove all
                          </button>
                        </div>
                      </div>

                      <button
                        v-if="projectHubEnabled && !item.drive_file_id"
                        @click="openDrivePickerForChecklistItem({ checklistId: checklist.id, itemId: item.id })"
                        class="p-1 opacity-0 group-hover:opacity-100 hover:text-blue-500 transition-all"
                        title="Link Drive file"
                      >
                        <span class="material-symbols-rounded text-sm">cloud</span>
                      </button>
                      <button 
                        @click="deleteChecklistItem(checklist.id, item.id)"
                        class="p-1 opacity-0 group-hover:opacity-100 hover:text-red-500 transition-all"
                      >
                        <span class="material-symbols-rounded text-sm">close</span>
                      </button>
                    </template>
                  </div>
                  
                  <!-- Add item -->
                  <div class="flex items-center gap-2">
                    <input
                      v-model="newChecklistItemInputs[checklist.id]"
                      type="text"
                      placeholder="Add item... (Paste multiline text to create multiple items)"
                      class="flex-1 px-2 py-1 text-sm bg-transparent border-b border-surface-200 dark:border-surface-600 text-surface-900 dark:text-surface-100 placeholder:text-surface-400 outline-none focus:border-primary-500"
                      @keydown.enter="addChecklistItem(checklist.id)"
                      @paste="handleChecklistPaste($event, checklist.id)"
                    />
                    <button 
                      @click="addChecklistItem(checklist.id)"
                      class="p-1 text-primary-500 hover:bg-primary-500/10 rounded transition-colors"
                    >
                      <span class="material-symbols-rounded text-lg">add</span>
                    </button>
                  </div>
                </div>
              </div>
              
              <!-- Project Hub: Subtasks -->
              <div v-if="projectHubEnabled && cardData?.id" class="bg-white dark:bg-surface-800 rounded-xl p-4 shadow-sm border border-surface-200/60 dark:border-surface-700/60">
                <div v-if="isBoardOwner" class="flex items-center justify-between mb-2 px-1">
                  <span class="text-[11px] text-surface-400 flex items-center gap-1">
                    <span class="material-symbols-rounded text-[13px]">visibility</span>
                    {{ cardData.full_task_visibility ? 'All members see all tasks' : 'Members see only assigned tasks' }}
                  </span>
                  <button
                    type="button"
                    class="relative inline-flex h-5 w-9 items-center rounded-full transition-colors"
                    :class="cardData.full_task_visibility ? 'bg-primary-500' : 'bg-surface-300 dark:bg-surface-600'"
                    @click.stop="toggleFullTaskVisibility"
                    title="Toggle task visibility for all members"
                  >
                    <span
                      class="inline-block h-3.5 w-3.5 rounded-full bg-white shadow-sm transition-transform"
                      :class="cardData.full_task_visibility ? 'translate-x-4' : 'translate-x-0.5'"
                    ></span>
                  </button>
                </div>
                <SubtasksList
                  :card-id="cardData.id"
                  :parent-completed="!!cardData.completed"
                  :full-task-visibility="!!cardData.full_task_visibility"
                  :is-board-owner="isBoardOwner"
                />
              </div>


              <!-- Project Hub sidebar sections shown inline on mobile only -->
              <template v-if="projectHubEnabled && cardData?.id && isMobile">
                <div class="bg-white dark:bg-surface-800 rounded-xl p-4 shadow-sm border border-surface-200/60 dark:border-surface-700/60">
                  <EnhancedComments
                    :card-id="cardData.id"
                    :board-id="cardData?.board_id"
                  />
                </div>
                <div class="bg-white dark:bg-surface-800 rounded-xl p-4 shadow-sm border border-surface-200/60 dark:border-surface-700/60 space-y-3">
                  <CardDependenciesPanel
                    :card-id="cardData.id"
                    :board-id="cardData?.board_id"
                  />
                  <WorkSessionLog
                    :card-id="cardData.id"
                  />
                  <div class="flex justify-end">
                    <router-link
                      :to="`/workload?mode=task-time&board_id=${cardData?.board_id}`"
                      class="inline-flex items-center gap-1 text-xs text-primary-600 dark:text-primary-400 hover:text-primary-700 dark:hover:text-primary-300 font-medium"
                    >
                      <span class="material-symbols-rounded text-sm">schedule</span>
                      View Time
                    </router-link>
                  </div>
                  <TaskCalendarSync
                    :card-id="cardData.id"
                  />
                </div>
              </template>

              <!-- Basic Comments + Activity shown inline on mobile only (desktop shows in right panel) -->
              <template v-if="isMobile && !projectHubEnabled">
                <div class="bg-white dark:bg-surface-800 rounded-xl p-4 shadow-sm border border-surface-200/60 dark:border-surface-700/60">
                  <h4 class="text-xs font-semibold text-surface-500 uppercase tracking-wide mb-3 flex items-center gap-2">
                    <span class="material-symbols-rounded text-lg">chat_bubble</span>
                    Comments
                  </h4>
                  <div class="flex gap-3 mb-4">
                    <UserAvatar :email="authStore.userEmail" size="md" />
                    <div class="flex-1">
                      <textarea
                        ref="commentTextarea"
                        v-model="newCommentContent"
                        rows="2"
                        placeholder="Write a comment... (Paste screenshot with Ctrl+V)"
                        class="w-full px-3 py-2 bg-surface-50 dark:bg-surface-700 border border-surface-200 dark:border-surface-600 rounded-xl text-sm text-surface-900 dark:text-surface-100 placeholder:text-surface-400 outline-none focus:border-primary-500 resize-none"
                        @keydown.meta.enter="addComment"
                        @keydown.ctrl.enter="addComment"
                        @paste="handleCommentPaste"
                      ></textarea>
                      <div class="flex items-center gap-2 mt-2">
                        <button 
                          v-if="newCommentContent.trim()"
                          @click="addComment"
                          :disabled="uploadingImage"
                          class="px-4 py-1.5 bg-primary-500 hover:bg-primary-600 disabled:opacity-50 disabled:cursor-not-allowed text-white rounded-lg text-sm font-medium"
                        >
                          {{ uploadingImage ? 'Uploading...' : 'Save' }}
                        </button>
                      </div>
                    </div>
                  </div>
                  <div class="space-y-4">
                    <div v-for="comment in comments" :key="comment.id" class="flex gap-3 group">
                      <UserAvatar :email="comment.user_email || ''" size="md" />
                      <div class="flex-1">
                        <div class="flex items-center gap-2 mb-1">
                          <span class="text-sm font-medium text-surface-900 dark:text-surface-100">{{ comment.user_email }}</span>
                          <span class="text-xs text-surface-500">{{ formatDateTime(comment.created_at) }}</span>
                          <button @click="deleteComment(comment.id)" class="ml-auto p-1 opacity-0 group-hover:opacity-100 hover:text-red-500 transition-all">
                            <span class="material-symbols-rounded text-sm">delete</span>
                          </button>
                        </div>
                        <div class="text-sm text-surface-700 dark:text-surface-300 whitespace-pre-wrap comment-content" v-html="comment.content"></div>
                      </div>
                    </div>
                  </div>
                </div>
              </template>
              
              <!-- Project Hub extras (grouped, collapsible) -->
              <!-- Team Overview (all members across card + tasks) -->
              <div v-if="projectHubEnabled && teamOverview.length > 0" class="bg-white dark:bg-surface-800 rounded-xl px-4 py-3 shadow-sm border border-surface-200/60 dark:border-surface-700/60">
                <h4 class="text-[10px] font-bold text-surface-400 dark:text-surface-500 uppercase tracking-widest mb-2 flex items-center gap-1.5">
                  <span class="material-symbols-rounded text-sm">group</span>
                  Team
                  <span class="text-[10px] font-normal text-surface-400">({{ teamOverview.length }})</span>
                </h4>
                <div class="flex flex-wrap gap-2">
                  <div
                    v-for="member in teamOverview"
                    :key="member.email"
                    class="group/m relative flex items-center gap-1.5 pl-0.5 pr-2 py-0.5 rounded-full bg-surface-50 dark:bg-surface-700/50 text-xs"
                  >
                    <UserAvatar :email="member.email" size="xs" :show-presence="true" />
                    <span class="text-surface-700 dark:text-surface-300 font-medium">{{ member.email.split('@')[0] }}</span>
                    <span
                      v-if="member.totalCount > 0"
                      class="text-[10px] font-semibold px-1 rounded-full"
                      :class="member.doneCount === member.totalCount
                        ? 'bg-green-100 dark:bg-green-900/30 text-green-600 dark:text-green-400'
                        : 'bg-surface-200 dark:bg-surface-600 text-surface-500 dark:text-surface-300'"
                    >{{ member.doneCount }}/{{ member.totalCount }}</span>
                    <!-- Hover tooltip showing task list -->
                    <div
                      v-if="member.tasks.length"
                      class="absolute bottom-full left-0 mb-1 hidden group-hover/m:block z-50 w-52 bg-white dark:bg-surface-800 border border-surface-200 dark:border-surface-700 rounded-lg shadow-lg p-2 text-[11px]"
                    >
                      <div v-for="(t, ti) in member.tasks" :key="ti" class="flex items-center gap-1.5 py-0.5">
                        <span class="material-symbols-rounded text-[12px]" :class="t.status === 'done' ? 'text-green-500' : 'text-surface-400'">{{ t.status === 'done' ? 'check_circle' : 'radio_button_unchecked' }}</span>
                        <span class="truncate" :class="t.status === 'done' ? 'line-through text-surface-400' : 'text-surface-600 dark:text-surface-300'">{{ t.title }}</span>
                      </div>
                    </div>
                  </div>
                </div>
              </div>

              <div v-if="projectHubEnabled && cardData?.id" class="bg-white dark:bg-surface-800 rounded-xl p-4 shadow-sm border border-surface-200/60 dark:border-surface-700/60 space-y-2">
                <h4 class="text-[10px] font-bold text-surface-400 dark:text-surface-500 uppercase tracking-widest mb-1">Project Hub</h4>

                <div class="grid grid-cols-2 gap-1.5">
                  <button
                    @click="showDependencies = !showDependencies"
                    :class="['flex items-center gap-1.5 px-2.5 py-1.5 rounded-lg text-xs font-medium transition-colors', showDependencies ? 'bg-primary-50 dark:bg-primary-500/10 text-primary-600 dark:text-primary-400' : 'bg-surface-50 dark:bg-surface-800 text-surface-500 hover:bg-surface-100 dark:hover:bg-surface-700']"
                  >
                    <span class="material-symbols-rounded text-sm">account_tree</span>
                    Dependencies
                  </button>
                  <button
                    @click="showCalendarSync = !showCalendarSync"
                    :class="['flex items-center gap-1.5 px-2.5 py-1.5 rounded-lg text-xs font-medium transition-colors', showCalendarSync ? 'bg-primary-50 dark:bg-primary-500/10 text-primary-600 dark:text-primary-400' : 'bg-surface-50 dark:bg-surface-800 text-surface-500 hover:bg-surface-100 dark:hover:bg-surface-700']"
                  >
                    <span class="material-symbols-rounded text-sm">calendar_month</span>
                    Calendar Sync
                  </button>
                </div>

                <CardDependenciesPanel
                  v-if="showDependencies"
                  :card-id="cardData.id"
                  :board-id="cardData?.board_id"
                  class="pt-2"
                />
                <TaskCalendarSync
                  v-if="showCalendarSync"
                  :card-id="cardData.id"
                  class="pt-2"
                />
              </div>


              </template><!-- end Details tab -->

              <!-- === ASSETS TAB === -->
              <template v-if="activeTab === 'assets' && cardData">
                <!-- Card Watch Folders (Project Hub) - top of assets for time tracking visibility -->
                <CardWatchFolders v-if="projectHubEnabled && cardData?.id" :card-id="cardData.id" :board-id="cardData.board_id" :client-id="cardData.client_id ? Number(cardData.client_id) : (boardsStore.currentBoard?.client_id ? Number(boardsStore.currentBoard.client_id) : null)" />

                <!-- Client Drive Files (Project Hub) -->
                <CardClientFiles v-if="projectHubEnabled && cardData?.id" :card-id="cardData.id" @attached="loadCard" />

                <CardAssetManager
                  ref="assetManagerRef"
                  :card-id="cardData.id"
                  :attachments="enrichedAttachments"
                  @refresh="loadCard"
                  @preview="openAttachmentPreview"
                  @delete-attachment="deleteAttachment"
                  @set-cover="(att) => boardsStore.setCardCover(cardData.id, att.drive_file_id)"
                >
                  <template #add-button>
                    <div class="relative attachment-menu-area">
                      <button
                        @click.stop="showAttachmentMenu = !showAttachmentMenu"
                        class="text-xs px-3 py-1.5 rounded-full bg-primary-50 dark:bg-primary-500/10 text-primary-600 dark:text-primary-400 hover:bg-primary-100 dark:hover:bg-primary-500/20 transition-colors flex items-center gap-1"
                      >
                        <span class="material-symbols-rounded text-sm">add</span>
                        Add file
                      </button>
                      <div
                        v-if="showAttachmentMenu && !isMobile"
                        class="absolute right-0 top-full mt-2 w-56 bg-white dark:bg-surface-800 rounded-xl shadow-2xl border border-surface-200 dark:border-surface-700 py-2 z-[80]"
                      >
                        <span class="text-xs font-semibold text-surface-500 uppercase tracking-wide px-3 mb-1 block">Attach</span>
                        <button @click="triggerFileUpload" class="w-full px-3 py-2 text-left text-sm text-surface-700 dark:text-surface-300 hover:bg-surface-100 dark:hover:bg-surface-700 flex items-center gap-2">
                          <span class="material-symbols-rounded text-lg">upload</span> Upload from computer
                        </button>
                        <button @click="openDrivePicker" class="w-full px-3 py-2 text-left text-sm text-surface-700 dark:text-surface-300 hover:bg-surface-100 dark:hover:bg-surface-700 flex items-center gap-2">
                          <span class="material-symbols-rounded text-lg">cloud</span> Choose from Drive
                        </button>
                      </div>
                    </div>
                  </template>
                </CardAssetManager>

                <!-- Card Tracked URLs (Project Hub) -->
                <CardTrackedUrls v-if="projectHubEnabled && cardData?.id" :card-id="cardData.id" />
              </template>

              <!-- Assignees tab removed -- team overview is now inline in Details tab -->

              <!-- === ACTIVITY TAB === -->
              <template v-if="activeTab === 'activity' && cardData">
                <div class="bg-white dark:bg-surface-800 rounded-xl p-4 shadow-sm border border-surface-200/60 dark:border-surface-700/60">
                  <CardActivityTimeline
                    v-if="projectHubEnabled"
                    :card-id="cardData.id"
                  />
                  <CardCommandCenter
                    v-else-if="boardProEnabled"
                    :card-id="cardData.id"
                  />
                </div>
              </template>

              <!-- === EMAILS TAB === -->
              <template v-if="activeTab === 'emails' && boardProEnabled && cardData">
                <div class="bg-white dark:bg-surface-800 rounded-xl p-4 shadow-sm border border-surface-200/60 dark:border-surface-700/60">
                  <CardEmailsPanel :card-id="cardData.id" />
                </div>
              </template>

              <!-- === FINANCIALS TAB === -->
              <template v-if="activeTab === 'financials' && boardProEnabled && cardData">
                <div class="bg-white dark:bg-surface-800 rounded-xl p-4 shadow-sm border border-surface-200/60 dark:border-surface-700/60">
                  <CardFinancialsPanel :card-id="cardData.id" />
                </div>
              </template>

              <!-- === INVOICE TAB === -->
              <template v-if="activeTab === 'invoice' && boardProEnabled && cardData">
                <div class="bg-white dark:bg-surface-800 rounded-xl p-4 shadow-sm border border-surface-200/60 dark:border-surface-700/60">
                  <CardInvoicePanel :card-id="cardData.id" :card-title="cardData.title" />
                </div>
              </template>

              <!-- === TIME TRACKER TAB === -->
              <template v-if="activeTab === 'time' && projectHubEnabled && cardData">
                <CardTimeBreakdown :card-id="cardData.id" />
              </template>
            </div>
            
            <!-- Resize handle -->
            <div
              v-if="!isMobile && sidebarOpen"
              class="w-1 cursor-col-resize bg-surface-200 dark:bg-surface-700 hover:bg-primary-400 dark:hover:bg-primary-600 transition-colors shrink-0"
              :class="{ 'bg-primary-500 dark:bg-primary-500': isResizing }"
              @mousedown.prevent="startSidebarResize"
            ></div>

            <!-- Sidebar open button (when collapsed) -->
            <button
              v-if="!isMobile && !sidebarOpen"
              @click="toggleSidebar"
              class="shrink-0 flex items-center justify-center w-8 border-l border-surface-200 dark:border-surface-700 bg-surface-50 dark:bg-surface-900 hover:bg-surface-100 dark:hover:bg-surface-800 transition-colors"
              title="Show activity panel"
            >
              <span class="material-symbols-rounded text-lg text-surface-400">left_panel_open</span>
            </button>

            <!-- Right panel: Activity & Comments -->
            <div
              v-if="!isMobile && sidebarOpen"
              :style="{ width: sidebarWidth + 'px' }"
              class="border-l border-surface-200 dark:border-surface-700 bg-surface-50 dark:bg-surface-900 shrink-0 flex flex-col overflow-hidden"
            >
              <!-- Activity header -->
              <div class="flex items-center gap-2 px-4 py-3 border-b border-surface-200 dark:border-surface-700 shrink-0">
                <span class="material-symbols-rounded text-lg text-surface-400">forum</span>
                <span class="text-sm font-semibold text-surface-700 dark:text-surface-200 flex-1">Activity</span>
                <button
                  v-if="projectHubEnabled && cardData?.id"
                  type="button"
                  class="text-xs font-medium px-2 py-1 rounded-full bg-primary-100 dark:bg-primary-900/30 text-primary-700 dark:text-primary-300 hover:bg-primary-200 dark:hover:bg-primary-900/50 transition-colors"
                  @click="showCardShareModal = true"
                >
                  Client share
                </button>
                <button
                  @click="toggleSidebar"
                  class="p-1 hover:bg-surface-200 dark:hover:bg-surface-700 rounded-lg transition-colors"
                  title="Hide activity panel"
                >
                  <span class="material-symbols-rounded text-sm text-surface-400">right_panel_close</span>
                </button>
              </div>

              <div class="flex-1 overflow-y-auto p-4 space-y-4">
                <!-- Project Hub: Watchers + EnhancedComments + WorkSessionLog -->
                <template v-if="projectHubEnabled && cardData?.id">
                  <WatcherFollowerPanel :card-id="cardData.id" />

                  <hr class="border-surface-200 dark:border-surface-700 my-3">

                  <EnhancedComments
                    :card-id="cardData.id"
                    :board-id="cardData?.board_id"
                  />

                  <hr class="border-surface-200 dark:border-surface-700 my-3">

                  <WorkSessionLog
                    :card-id="cardData.id"
                  />
                  <div class="flex justify-end mt-1">
                    <router-link
                      :to="`/workload?mode=task-time&board_id=${cardData?.board_id}`"
                      class="inline-flex items-center gap-1 text-xs text-primary-600 dark:text-primary-400 hover:text-primary-700 dark:hover:text-primary-300 font-medium"
                    >
                      <span class="material-symbols-rounded text-sm">schedule</span>
                      View Time
                    </router-link>
                  </div>
                </template>

                <!-- Non-hub: Basic comments + activity -->
                <template v-else>
                  <!-- Comment input -->
                  <div class="flex gap-3">
                    <UserAvatar :email="authStore.userEmail" size="sm" />
                    <div class="flex-1">
                      <textarea
                        ref="commentTextarea"
                        v-model="newCommentContent"
                        rows="2"
                        placeholder="Write a comment... Use @email to mention"
                        class="w-full px-3 py-2 bg-white dark:bg-surface-800 border border-surface-200 dark:border-surface-600 rounded-xl text-sm text-surface-900 dark:text-surface-100 placeholder:text-surface-400 outline-none focus:border-primary-500 resize-none"
                        @keydown.meta.enter="addComment"
                        @keydown.ctrl.enter="addComment"
                        @paste="handleCommentPaste"
                      ></textarea>
                      <div class="flex items-center gap-2 mt-1.5">
                        <button 
                          v-if="newCommentContent.trim()"
                          @click="addComment"
                          :disabled="uploadingImage"
                          class="px-3 py-1 bg-primary-500 hover:bg-primary-600 disabled:opacity-50 disabled:cursor-not-allowed text-white rounded-lg text-xs font-medium"
                        >
                          {{ uploadingImage ? 'Uploading...' : 'Comment' }}
                        </button>
                        <span class="text-[10px] text-surface-400">Cmd+Enter to send</span>
                      </div>
                    </div>
                  </div>

                  <!-- Comments list -->
                  <div class="space-y-3">
                    <div 
                      v-for="comment in comments"
                      :key="comment.id"
                      class="flex gap-2.5 group"
                    >
                      <UserAvatar :email="comment.user_email || ''" size="sm" />
                      <div class="flex-1 min-w-0">
                        <div class="flex items-center gap-2 mb-0.5">
                          <span class="text-xs font-semibold text-surface-900 dark:text-surface-100">
                            {{ (comment.user_email || '').split('@')[0] }}
                          </span>
                          <span class="text-[10px] text-surface-400">
                            {{ formatDateTime(comment.created_at) }}
                          </span>
                          <button 
                            @click="deleteComment(comment.id)"
                            class="ml-auto p-0.5 opacity-0 group-hover:opacity-100 hover:text-red-500 transition-all"
                          >
                            <span class="material-symbols-rounded text-[14px]">delete</span>
                          </button>
                        </div>
                        <div 
                          class="text-sm text-surface-700 dark:text-surface-300 whitespace-pre-wrap break-words comment-content"
                          v-html="comment.content"
                        ></div>
                      </div>
                    </div>
                  </div>

                  <!-- Activity log -->
                  <div v-if="activity.length > 0">
                    <div class="flex items-center gap-2 mb-2 mt-2">
                      <div class="flex-1 border-t border-surface-200 dark:border-surface-700"></div>
                      <span class="text-[10px] font-semibold text-surface-400 uppercase tracking-wider">Activity</span>
                      <div class="flex-1 border-t border-surface-200 dark:border-surface-700"></div>
                    </div>
                    <div class="space-y-2">
                      <div 
                        v-for="act in activity.slice(0, 20)"
                        :key="act.id"
                        class="flex items-start gap-2 text-xs"
                      >
                        <UserAvatar :email="act.user_email || ''" size="xs" />
                        <div class="flex-1 min-w-0">
                          <span class="text-surface-600 dark:text-surface-400">
                            <span class="font-semibold text-surface-700 dark:text-surface-300">{{ (act.user_email || '').split('@')[0] }}</span>
                            {{ act.action }}
                          </span>
                          <span class="text-[10px] text-surface-400 ml-1">
                            {{ formatDateTime(act.created_at) }}
                          </span>
                        </div>
                      </div>
                    </div>
                  </div>
                </template>
              </div>
            </div>

            <!-- Picker overlays (triggered from actions dropdown) -->
            <!-- File input (shared) -->
            <input ref="fileInput" type="file" class="hidden" @change="handleFileUpload" />

            <!-- Label picker overlay -->
            <Teleport to="body">
              <div v-if="showLabelPicker && !isMobile" class="fixed inset-0 z-[70] flex items-start justify-center pt-[15vh]" @click.self="showLabelPicker = false">
                <div class="w-72 bg-white dark:bg-surface-800 rounded-xl shadow-2xl border border-surface-200 dark:border-surface-700 p-3 max-h-[60vh] overflow-y-auto">
                  <div class="flex items-center justify-between mb-2">
                    <span class="text-xs font-semibold text-surface-500 uppercase tracking-wide">Labels</span>
                    <button @click="showLabelPicker = false" class="p-0.5 hover:bg-surface-100 dark:hover:bg-surface-700 rounded"><span class="material-symbols-rounded text-sm text-surface-400">close</span></button>
                  </div>
                  <div class="space-y-1">
                    <div v-for="label in labels" :key="'lp-' + label.id" class="flex items-center gap-2 p-2 rounded-lg hover:bg-surface-100 dark:hover:bg-surface-700">
                      <button class="flex items-center gap-2 flex-1 min-w-0" @click="toggleLabel(label)">
                        <span class="w-8 h-6 rounded shrink-0" :style="{ backgroundColor: label.color }"></span>
                        <span class="text-sm text-surface-700 dark:text-surface-300 flex-1 text-left truncate">{{ label.name || 'Label' }}</span>
                        <span v-if="cardLabels.some(l => l.id === label.id)" class="material-symbols-rounded text-primary-500 shrink-0">check</span>
                      </button>
                      <button v-if="projectHubEnabled" @click.stop="toggleLabelType(label)" class="shrink-0 px-1.5 py-0.5 rounded text-[10px] font-semibold transition-colors" :class="label.is_type ? 'bg-primary-100 dark:bg-primary-900/30 text-primary-600 dark:text-primary-400' : 'bg-surface-100 dark:bg-surface-700 text-surface-400 hover:text-surface-600'">
                        {{ label.is_type ? 'TYPE' : 'type' }}
                      </button>
                    </div>
                  </div>
                </div>
              </div>
            </Teleport>

            <!-- Checklist creator overlay -->
            <Teleport to="body">
              <div v-if="addingChecklist && !isMobile" class="fixed inset-0 z-[70] flex items-start justify-center pt-[15vh]" @click.self="addingChecklist = false">
                <div class="w-64 bg-white dark:bg-surface-800 rounded-xl shadow-2xl border border-surface-200 dark:border-surface-700 p-4">
                  <span class="text-xs font-semibold text-surface-500 uppercase tracking-wide mb-2 block">New Checklist</span>
                  <input v-model="newChecklistTitle" type="text" placeholder="Checklist title..." class="w-full px-3 py-2 bg-surface-50 dark:bg-surface-700 border border-surface-200 dark:border-surface-600 rounded-lg text-sm text-surface-900 dark:text-surface-100 outline-none focus:border-primary-500 mb-2" @keydown.enter="addChecklist" autofocus />
                  <button @click="addChecklist" class="w-full px-3 py-2 bg-primary-500 hover:bg-primary-600 text-white rounded-lg text-sm font-medium">Add</button>
                </div>
              </div>
            </Teleport>

            <!-- Start date picker overlay -->
            <Teleport to="body">
              <div v-if="showStartDatePicker && !isMobile" class="fixed inset-0 z-[70] flex items-start justify-center pt-[15vh]" @click.self="showStartDatePicker = false">
                <div class="w-64 bg-white dark:bg-surface-800 rounded-xl shadow-2xl border border-surface-200 dark:border-surface-700 p-4">
                  <span class="text-xs font-semibold text-surface-500 uppercase tracking-wide mb-2 block">Start Date</span>
                  <input v-model="startDateInput" type="date" class="w-full px-3 py-2 bg-surface-50 dark:bg-surface-700 border border-surface-200 dark:border-surface-600 rounded-lg text-sm text-surface-900 dark:text-surface-100 outline-none focus:border-primary-500 mb-2" />
                  <div class="flex gap-2">
                    <button @click="saveStartDate" class="flex-1 px-3 py-2 bg-primary-500 hover:bg-primary-600 text-white rounded-lg text-sm font-medium">Save</button>
                    <button v-if="cardData?.start_date" @click="removeStartDate" class="px-3 py-2 text-red-500 hover:bg-red-500/10 rounded-lg text-sm">Remove</button>
                  </div>
                </div>
              </div>
            </Teleport>

            <!-- Due date picker overlay -->
            <Teleport to="body">
              <div v-if="showDueDatePicker && !isMobile" class="fixed inset-0 z-[70] flex items-start justify-center pt-[15vh]" @click.self="showDueDatePicker = false">
                <div class="w-64 bg-white dark:bg-surface-800 rounded-xl shadow-2xl border border-surface-200 dark:border-surface-700 p-4">
                  <span class="text-xs font-semibold text-surface-500 uppercase tracking-wide mb-2 block">Due Date</span>
                  <input v-model="dueDateInput" type="date" class="w-full px-3 py-2 bg-surface-50 dark:bg-surface-700 border border-surface-200 dark:border-surface-600 rounded-lg text-sm text-surface-900 dark:text-surface-100 outline-none focus:border-primary-500 mb-2" />
                  <div class="flex gap-2">
                    <button @click="saveDueDate" class="flex-1 px-3 py-2 bg-primary-500 hover:bg-primary-600 text-white rounded-lg text-sm font-medium">Save</button>
                    <button v-if="cardData?.due_date" @click="removeDueDate" class="px-3 py-2 text-red-500 hover:bg-red-500/10 rounded-lg text-sm">Remove</button>
                  </div>
                </div>
              </div>
            </Teleport>

            <!-- Member picker overlay -->
            <Teleport to="body">
              <div v-if="showMemberPicker && !isMobile" class="fixed inset-0 z-[70] flex items-start justify-center pt-[15vh]" @click.self="showMemberPicker = false">
                <div class="w-64 bg-white dark:bg-surface-800 rounded-xl shadow-2xl border border-surface-200 dark:border-surface-700 p-3 max-h-[60vh] overflow-y-auto">
                  <div class="flex items-center justify-between mb-2">
                    <span class="text-xs font-semibold text-surface-500 uppercase tracking-wide">Assign member</span>
                    <button @click="showMemberPicker = false" class="p-0.5 hover:bg-surface-100 dark:hover:bg-surface-700 rounded"><span class="material-symbols-rounded text-sm text-surface-400">close</span></button>
                  </div>
                  <div class="space-y-1">
                    <button v-for="member in members" :key="'mp-' + member.email" @click="selectedMember = member.email; assignMember()" class="w-full p-2 rounded-lg flex items-center gap-2 hover:bg-surface-100 dark:hover:bg-surface-700">
                      <UserAvatar :email="member.email" size="xs" />
                      <span class="text-sm text-surface-700 dark:text-surface-300 flex-1 text-left truncate">{{ member.email }}</span>
                      <span v-if="cardData?.assigned_to === member.email" class="material-symbols-rounded text-primary-500">check</span>
                    </button>
                  </div>
                  <button v-if="cardData?.assigned_to" @click="selectedMember = ''; assignMember()" class="w-full mt-2 px-3 py-2 text-red-500 hover:bg-red-500/10 rounded-lg text-sm">Remove assignment</button>
                </div>
              </div>
            </Teleport>

            <!-- Color picker overlay -->
            <Teleport to="body">
              <div v-if="showColorPicker && !isMobile" class="fixed inset-0 z-[70] flex items-start justify-center pt-[15vh]" @click.self="showColorPicker = false">
                <div class="w-[240px] bg-white dark:bg-surface-800 rounded-xl shadow-2xl border border-surface-200 dark:border-surface-700 p-3">
                  <div class="flex items-center justify-between mb-2">
                    <span class="text-xs font-semibold text-surface-500 uppercase tracking-wide">Card color</span>
                    <button @click="showColorPicker = false" class="p-0.5 hover:bg-surface-100 dark:hover:bg-surface-700 rounded"><span class="material-symbols-rounded text-sm text-surface-400">close</span></button>
                  </div>
                  <div class="grid grid-cols-10 gap-1">
                    <button v-for="color in cardColors" :key="'cp-' + color" class="w-[18px] h-[18px] rounded-sm cursor-pointer hover:scale-125 transition-transform border border-black/10 relative" :style="{ backgroundColor: color }" @click="setCardColor(color)">
                      <span v-if="cardData?.card_color === color" class="material-symbols-rounded text-white text-xs absolute inset-0 flex items-center justify-center drop-shadow-sm">check</span>
                    </button>
                  </div>
                  <button v-if="cardData?.card_color" @click="setCardColor(null)" class="mt-2 w-full px-2 py-1.5 text-left text-xs text-surface-500 hover:bg-surface-100 dark:hover:bg-surface-700 rounded flex items-center gap-2">
                    <span class="material-symbols-rounded text-sm">format_color_reset</span> Remove color
                  </button>
                </div>
              </div>
            </Teleport>
          </div>
        </template>
      </div>
    </div>
    
    <!-- Mobile sidebar bottom sheet -->
    <Transition name="card-sidebar-sheet">
      <div
        v-if="isMobile && showMobileSidebar"
        class="fixed inset-0 z-[60] bg-black/40"
        @click.self="showMobileSidebar = false"
      >
        <div class="absolute bottom-0 left-0 right-0 bg-white dark:bg-surface-800 rounded-t-2xl max-h-[80vh] overflow-y-auto" style="-webkit-overflow-scrolling: touch;">
          <!-- Handle bar -->
          <div class="flex justify-center pt-3 pb-1 sticky top-0 bg-white dark:bg-surface-800 z-10">
            <div class="w-10 h-1 bg-surface-300 dark:bg-surface-600 rounded-full"></div>
          </div>

          <div class="px-4 pb-6 space-y-2">
            <p class="text-xs font-semibold text-surface-500 uppercase tracking-wide mb-3">Add to card</p>

            <button 
              @click="showLabelPicker = !showLabelPicker; showMobileSidebar = false"
              class="w-full px-3 py-3 bg-surface-100 dark:bg-surface-700 hover:bg-surface-200 dark:hover:bg-surface-600 rounded-xl text-sm text-surface-700 dark:text-surface-300 text-left flex items-center gap-3"
            >
              <span class="material-symbols-rounded text-lg">label</span>
              Labels
            </button>

            <button 
              @click="addingChecklist = !addingChecklist; showMobileSidebar = false"
              class="w-full px-3 py-3 bg-surface-100 dark:bg-surface-700 hover:bg-surface-200 dark:hover:bg-surface-600 rounded-xl text-sm text-surface-700 dark:text-surface-300 text-left flex items-center gap-3"
            >
              <span class="material-symbols-rounded text-lg">checklist</span>
              Checklist
            </button>

            <button 
              @click="openStartDatePicker(); showMobileSidebar = false"
              class="w-full px-3 py-3 bg-surface-100 dark:bg-surface-700 hover:bg-surface-200 dark:hover:bg-surface-600 rounded-xl text-sm text-surface-700 dark:text-surface-300 text-left flex items-center gap-3"
            >
              <span class="material-symbols-rounded text-lg">event</span>
              Start date
            </button>

            <button 
              @click="openDueDatePicker(); showMobileSidebar = false"
              class="w-full px-3 py-3 bg-surface-100 dark:bg-surface-700 hover:bg-surface-200 dark:hover:bg-surface-600 rounded-xl text-sm text-surface-700 dark:text-surface-300 text-left flex items-center gap-3"
            >
              <span class="material-symbols-rounded text-lg">schedule</span>
              Due date
            </button>

            <button 
              @click="showAttachmentMenu = !showAttachmentMenu; showMobileSidebar = false"
              class="w-full px-3 py-3 bg-surface-100 dark:bg-surface-700 hover:bg-surface-200 dark:hover:bg-surface-600 rounded-xl text-sm text-surface-700 dark:text-surface-300 text-left flex items-center gap-3"
            >
              <span class="material-symbols-rounded text-lg">attach_file</span>
              Attachment
            </button>

            <button 
              v-if="!projectHubEnabled"
              @click="showMemberPicker = !showMemberPicker; showMobileSidebar = false"
              class="w-full px-3 py-3 bg-surface-100 dark:bg-surface-700 hover:bg-surface-200 dark:hover:bg-surface-600 rounded-xl text-sm text-surface-700 dark:text-surface-300 text-left flex items-center gap-3"
            >
              <span class="material-symbols-rounded text-lg">person_add</span>
              Members
            </button>

            <button 
              @click="showColorPicker = !showColorPicker; showMobileSidebar = false"
              class="w-full px-3 py-3 bg-surface-100 dark:bg-surface-700 hover:bg-surface-200 dark:hover:bg-surface-600 rounded-xl text-sm text-surface-700 dark:text-surface-300 text-left flex items-center gap-3"
            >
              <span class="material-symbols-rounded text-lg">palette</span>
              Card color
              <span
                v-if="cardData?.card_color"
                class="w-4 h-4 rounded-full border border-black/10 ml-auto"
                :style="{ backgroundColor: cardData.card_color }"
              ></span>
            </button>

            <hr class="border-surface-200 dark:border-surface-600 my-3">
            <p class="text-xs font-semibold text-surface-500 uppercase tracking-wide mb-3">Actions</p>

            <button 
              @click="showMoveList = true; showMobileSidebar = false"
              class="w-full px-3 py-3 bg-surface-100 dark:bg-surface-700 hover:bg-surface-200 dark:hover:bg-surface-600 rounded-xl text-sm text-surface-700 dark:text-surface-300 text-left flex items-center gap-3"
            >
              <span class="material-symbols-rounded text-lg">arrow_forward</span>
              Move
            </button>

            <button 
              @click="archiveCard(); showMobileSidebar = false"
              class="w-full px-3 py-3 bg-surface-100 dark:bg-surface-700 hover:bg-surface-200 dark:hover:bg-surface-600 rounded-xl text-sm text-surface-700 dark:text-surface-300 text-left flex items-center gap-3"
            >
              <span class="material-symbols-rounded text-lg">archive</span>
              Archive
            </button>

            <button 
              @click="confirmDeleteCard(); showMobileSidebar = false"
              class="w-full px-3 py-3 hover:bg-red-500/10 rounded-xl text-sm text-red-600 dark:text-red-400 text-left flex items-center gap-3"
            >
              <span class="material-symbols-rounded text-lg">delete</span>
              Delete
            </button>
          </div>
        </div>
      </div>
    </Transition>

    <!-- Mobile picker overlays (labels, members, dates, etc.) -->
    <template v-if="isMobile">
      <!-- Labels picker -->
      <Transition name="card-sidebar-sheet">
        <div v-if="showLabelPicker" class="fixed inset-0 z-[70] bg-black/40" @click.self="showLabelPicker = false">
          <div class="absolute bottom-0 left-0 right-0 bg-white dark:bg-surface-800 rounded-t-2xl max-h-[60vh] overflow-y-auto p-4">
            <div class="flex justify-between items-center mb-3">
              <h4 class="text-sm font-semibold text-surface-900 dark:text-surface-100">Labels</h4>
              <button @click="showLabelPicker = false" class="p-1 rounded-full hover:bg-surface-100 dark:hover:bg-surface-700"><span class="material-symbols-rounded text-lg text-surface-500">close</span></button>
            </div>
            <div class="space-y-2">
              <button
                v-for="label in labels"
                :key="'ml-' + label.id"
                @click="toggleLabel(label)"
                class="w-full p-3 rounded-xl flex items-center gap-3 hover:bg-surface-50 dark:hover:bg-surface-700 active:bg-surface-100"
              >
                <span class="w-10 h-7 rounded-lg" :style="{ backgroundColor: label.color }"></span>
                <span class="text-sm text-surface-700 dark:text-surface-300 flex-1 text-left">{{ label.name || 'Label' }}</span>
                <span v-if="cardLabels.some(l => l.id === label.id)" class="material-symbols-rounded text-primary-500">check</span>
              </button>
            </div>
          </div>
        </div>
      </Transition>

      <!-- Members picker -->
      <Transition name="card-sidebar-sheet">
        <div v-if="showMemberPicker" class="fixed inset-0 z-[70] bg-black/40" @click.self="showMemberPicker = false">
          <div class="absolute bottom-0 left-0 right-0 bg-white dark:bg-surface-800 rounded-t-2xl max-h-[60vh] overflow-y-auto p-4">
            <div class="flex justify-between items-center mb-3">
              <h4 class="text-sm font-semibold text-surface-900 dark:text-surface-100">Assign member</h4>
              <button @click="showMemberPicker = false" class="p-1 rounded-full hover:bg-surface-100 dark:hover:bg-surface-700"><span class="material-symbols-rounded text-lg text-surface-500">close</span></button>
            </div>
            <div class="space-y-2 mb-3">
              <button
                v-for="member in members"
                :key="'mm-' + member.email"
                @click="selectedMember = member.email; assignMember(); showMemberPicker = false"
                class="w-full p-3 rounded-xl flex items-center gap-3 hover:bg-surface-50 dark:hover:bg-surface-700 active:bg-surface-100"
              >
                <UserAvatar :email="member.email" size="sm" />
                <span class="text-sm text-surface-700 dark:text-surface-300 flex-1 text-left truncate">{{ member.email }}</span>
                <span v-if="cardData?.assigned_to === member.email" class="material-symbols-rounded text-primary-500">check</span>
              </button>
            </div>
            <button 
              v-if="cardData?.assigned_to"
              @click="selectedMember = ''; assignMember(); showMemberPicker = false"
              class="w-full px-3 py-3 text-red-500 hover:bg-red-500/10 rounded-xl text-sm"
            >
              Remove assignment
            </button>
          </div>
        </div>
      </Transition>

      <!-- Start date picker -->
      <Transition name="card-sidebar-sheet">
        <div v-if="showStartDatePicker" class="fixed inset-0 z-[70] bg-black/40" @click.self="showStartDatePicker = false">
          <div class="absolute bottom-0 left-0 right-0 bg-white dark:bg-surface-800 rounded-t-2xl p-4">
            <div class="flex justify-between items-center mb-3">
              <h4 class="text-sm font-semibold text-surface-900 dark:text-surface-100">Start date</h4>
              <button @click="showStartDatePicker = false" class="p-1 rounded-full hover:bg-surface-100 dark:hover:bg-surface-700"><span class="material-symbols-rounded text-lg text-surface-500">close</span></button>
            </div>
            <input v-model="startDateInput" type="date" class="w-full px-3 py-3 bg-surface-50 dark:bg-surface-700 border border-surface-200 dark:border-surface-600 rounded-xl text-sm text-surface-900 dark:text-surface-100 outline-none focus:border-primary-500 mb-3" />
            <div class="flex gap-2">
              <button @click="saveStartDate(); showStartDatePicker = false" class="flex-1 px-3 py-3 bg-primary-500 hover:bg-primary-600 text-white rounded-xl text-sm font-medium">Save</button>
              <button v-if="cardData?.start_date" @click="removeStartDate(); showStartDatePicker = false" class="px-3 py-3 text-red-500 hover:bg-red-500/10 rounded-xl text-sm">Remove</button>
            </div>
          </div>
        </div>
      </Transition>

      <!-- Due date picker -->
      <Transition name="card-sidebar-sheet">
        <div v-if="showDueDatePicker" class="fixed inset-0 z-[70] bg-black/40" @click.self="showDueDatePicker = false">
          <div class="absolute bottom-0 left-0 right-0 bg-white dark:bg-surface-800 rounded-t-2xl p-4">
            <div class="flex justify-between items-center mb-3">
              <h4 class="text-sm font-semibold text-surface-900 dark:text-surface-100">Due date</h4>
              <button @click="showDueDatePicker = false" class="p-1 rounded-full hover:bg-surface-100 dark:hover:bg-surface-700"><span class="material-symbols-rounded text-lg text-surface-500">close</span></button>
            </div>
            <input v-model="dueDateInput" type="date" class="w-full px-3 py-3 bg-surface-50 dark:bg-surface-700 border border-surface-200 dark:border-surface-600 rounded-xl text-sm text-surface-900 dark:text-surface-100 outline-none focus:border-primary-500 mb-3" />
            <div class="flex gap-2">
              <button @click="saveDueDate(); showDueDatePicker = false" class="flex-1 px-3 py-3 bg-primary-500 hover:bg-primary-600 text-white rounded-xl text-sm font-medium">Save</button>
              <button v-if="cardData?.due_date" @click="removeDueDate(); showDueDatePicker = false" class="px-3 py-3 text-red-500 hover:bg-red-500/10 rounded-xl text-sm">Remove</button>
            </div>
          </div>
        </div>
      </Transition>

      <!-- Color picker -->
      <Transition name="card-sidebar-sheet">
        <div v-if="showColorPicker" class="fixed inset-0 z-[70] bg-black/40" @click.self="showColorPicker = false">
          <div class="absolute bottom-0 left-0 right-0 bg-white dark:bg-surface-800 rounded-t-2xl p-4">
            <div class="flex justify-between items-center mb-3">
              <h4 class="text-sm font-semibold text-surface-900 dark:text-surface-100">Card color</h4>
              <button @click="showColorPicker = false" class="p-1 rounded-full hover:bg-surface-100 dark:hover:bg-surface-700"><span class="material-symbols-rounded text-lg text-surface-500">close</span></button>
            </div>
            <div class="grid grid-cols-10 gap-2">
              <button
                v-for="color in cardColors"
                :key="'mc-' + color"
                class="w-full aspect-square rounded-lg cursor-pointer hover:scale-110 transition-transform border border-black/10 relative"
                :style="{ backgroundColor: color }"
                @click="setCardColor(color); showColorPicker = false"
              >
                <span v-if="cardData?.card_color === color" class="material-symbols-rounded text-white text-sm absolute inset-0 flex items-center justify-center drop-shadow-sm">check</span>
              </button>
            </div>
            <button
              v-if="cardData?.card_color"
              @click="setCardColor(null); showColorPicker = false"
              class="mt-3 w-full px-3 py-2.5 text-left text-sm text-surface-500 hover:bg-surface-100 dark:hover:bg-surface-700 rounded-xl flex items-center gap-2"
            >
              <span class="material-symbols-rounded text-lg">format_color_reset</span>
              Remove color
            </button>
          </div>
        </div>
      </Transition>

      <!-- Checklist add -->
      <Transition name="card-sidebar-sheet">
        <div v-if="addingChecklist" class="fixed inset-0 z-[70] bg-black/40" @click.self="addingChecklist = false">
          <div class="absolute bottom-0 left-0 right-0 bg-white dark:bg-surface-800 rounded-t-2xl p-4">
            <div class="flex justify-between items-center mb-3">
              <h4 class="text-sm font-semibold text-surface-900 dark:text-surface-100">Add checklist</h4>
              <button @click="addingChecklist = false" class="p-1 rounded-full hover:bg-surface-100 dark:hover:bg-surface-700"><span class="material-symbols-rounded text-lg text-surface-500">close</span></button>
            </div>
            <input
              v-model="newChecklistTitle"
              type="text"
              placeholder="Checklist title..."
              class="w-full px-3 py-3 bg-surface-50 dark:bg-surface-700 border border-surface-200 dark:border-surface-600 rounded-xl text-sm text-surface-900 dark:text-surface-100 outline-none focus:border-primary-500 mb-3"
              @keydown.enter="addChecklist"
              autofocus
            />
            <button 
              @click="addChecklist"
              class="w-full px-3 py-3 bg-primary-500 hover:bg-primary-600 text-white rounded-xl text-sm font-medium"
            >
              Add
            </button>
          </div>
        </div>
      </Transition>

      <!-- Move to list -->
      <Transition name="card-sidebar-sheet">
        <div v-if="showMoveList" class="fixed inset-0 z-[70] bg-black/40" @click.self="showMoveList = false">
          <div class="absolute bottom-0 left-0 right-0 bg-white dark:bg-surface-800 rounded-t-2xl max-h-[60vh] overflow-y-auto p-4">
            <div class="flex justify-between items-center mb-3">
              <h4 class="text-sm font-semibold text-surface-900 dark:text-surface-100">Move to list</h4>
              <button @click="showMoveList = false" class="p-1 rounded-full hover:bg-surface-100 dark:hover:bg-surface-700"><span class="material-symbols-rounded text-lg text-surface-500">close</span></button>
            </div>
            <div class="space-y-2">
              <button
                v-for="list in lists"
                :key="'mvl-' + list.id"
                @click="moveToList(list.id); showMoveList = false"
                :class="[
                  'w-full px-3 py-3 rounded-xl text-left text-sm flex items-center gap-3 active:bg-surface-100',
                  list.id === cardData?.list_id 
                    ? 'bg-primary-50 dark:bg-primary-500/10 text-primary-600 dark:text-primary-400' 
                    : 'bg-surface-100 dark:bg-surface-700 text-surface-700 dark:text-surface-300'
                ]"
              >
                {{ list.name }}
                <span v-if="list.id === cardData?.list_id" class="material-symbols-rounded text-sm ml-auto">check</span>
              </button>
            </div>
          </div>
        </div>
      </Transition>

      <!-- Attachment picker -->
      <Transition name="card-sidebar-sheet">
        <div v-if="showAttachmentMenu" class="fixed inset-0 z-[70] bg-black/40" @click.self="showAttachmentMenu = false">
          <div class="absolute bottom-0 left-0 right-0 bg-white dark:bg-surface-800 rounded-t-2xl p-4">
            <div class="flex justify-between items-center mb-3">
              <h4 class="text-sm font-semibold text-surface-900 dark:text-surface-100">Add attachment</h4>
              <button @click="showAttachmentMenu = false" class="p-1 rounded-full hover:bg-surface-100 dark:hover:bg-surface-700"><span class="material-symbols-rounded text-lg text-surface-500">close</span></button>
            </div>
            <button
              @click="triggerFileUpload(); showAttachmentMenu = false"
              class="w-full px-3 py-3 bg-surface-100 dark:bg-surface-700 hover:bg-surface-200 dark:hover:bg-surface-600 rounded-xl text-sm text-surface-700 dark:text-surface-300 text-left flex items-center gap-3 mb-2"
            >
              <span class="material-symbols-rounded text-lg">upload</span>
              Upload from device
            </button>
            <button
              @click="openDrivePicker(); showAttachmentMenu = false"
              class="w-full px-3 py-3 bg-surface-100 dark:bg-surface-700 hover:bg-surface-200 dark:hover:bg-surface-600 rounded-xl text-sm text-surface-700 dark:text-surface-300 text-left flex items-center gap-3"
            >
              <span class="material-symbols-rounded text-lg">cloud</span>
              Choose from Drive
            </button>
          </div>
        </div>
      </Transition>
    </template>

    <!-- Drive File Picker -->
    <DriveFilePicker
      :show="showDriveFilePicker"
      :title="linkDriveTarget ? 'Link Drive file to checklist item' : 'Choose file from Drive'"
      @select="handleDriveFileSelected"
      @cancel="closeDrivePicker"
    />
    
    <!-- Delete Confirm Modal -->
    <ConfirmModal
      :show="showDeleteConfirm"
      title="Delete Card"
      message="Delete this card permanently? This action cannot be undone."
      confirm-text="Delete"
      :danger="true"
      @confirm="deleteCard"
      @cancel="showDeleteConfirm = false"
    />

    <CardShareModal
      v-if="projectHubEnabled && cardData?.id"
      :show="showCardShareModal"
      :card-id="cardData.id"
      @close="showCardShareModal = false"
    />

    <!-- Image Preview Lightbox -->
    <Transition name="fade">
      <div
        v-if="previewImageUrl || previewLoading"
        class="fixed inset-0 z-[9999] flex items-center justify-center bg-black/80 backdrop-blur-sm"
        @click.self="closeImagePreview"
      >
        <div class="relative max-w-[90vw] max-h-[90vh] flex flex-col items-center">
          <!-- Header -->
          <div class="flex items-center justify-between w-full mb-3 px-1">
            <span class="text-white/80 text-sm truncate max-w-[60vw]">{{ previewImageName }}</span>
            <button
              @click="closeImagePreview"
              class="p-2 rounded-full bg-white/10 hover:bg-white/20 text-white transition-colors"
            >
              <span class="material-symbols-rounded text-xl">close</span>
            </button>
          </div>

          <!-- Loading spinner -->
          <div v-if="previewLoading && !previewImageUrl" class="flex items-center justify-center h-64">
            <span class="material-symbols-rounded text-4xl text-white/60 animate-spin">progress_activity</span>
          </div>

          <!-- Image -->
          <img
            v-if="previewImageUrl"
            :src="previewImageUrl"
            :alt="previewImageName"
            class="max-w-[85vw] max-h-[80vh] object-contain rounded-lg shadow-2xl"
          />

          <!-- Download button -->
          <div v-if="previewImageUrl" class="mt-3">
            <a
              :href="previewImageUrl"
              :download="previewImageName"
              class="inline-flex items-center gap-2 px-4 py-2 rounded-full bg-white/10 hover:bg-white/20 text-white text-sm transition-colors"
            >
              <span class="material-symbols-rounded text-base">download</span>
              Download
            </a>
          </div>
        </div>
      </div>
    </Transition>

    <!-- Manual Time Entry Dialog -->
    <ManualTimeEntryDialog
      v-if="showManualTimeEntry && cardData?.id"
      :card-id="cardData.id"
      :card-title="cardData.title || ''"
      :can-select-member="isBoardOwner"
      :member-options="members"
      :allow-task-selection="isBoardOwner"
      @close="showManualTimeEntry = false"
      @saved="onManualTimeSaved"
    />
  </Teleport>
</template>

<style scoped>
@keyframes spin {
  from { transform: rotate(0deg); }
  to { transform: rotate(360deg); }
}

.animate-spin {
  animation: spin 1s linear infinite;
}

.comment-content :deep(img) {
  max-width: 100%;
  height: auto;
  border-radius: 8px;
  margin: 8px 0;
  display: block;
  box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
}

.comment-content :deep(p) {
  margin: 0;
  line-height: 1.5;
}

.fade-enter-active,
.fade-leave-active {
  transition: opacity 0.2s ease;
}
.fade-enter-from,
.fade-leave-to {
  opacity: 0;
}

/* Bottom sheet slide-up transition */
.card-sidebar-sheet-enter-active {
  transition: opacity 0.2s ease;
}
.card-sidebar-sheet-enter-active > div:last-child {
  transition: transform 0.3s cubic-bezier(0.22, 1, 0.36, 1);
}
.card-sidebar-sheet-leave-active {
  transition: opacity 0.15s ease;
}
.card-sidebar-sheet-leave-active > div:last-child {
  transition: transform 0.2s ease-in;
}
.card-sidebar-sheet-enter-from {
  opacity: 0;
}
.card-sidebar-sheet-enter-from > div:last-child {
  transform: translateY(100%);
}
.card-sidebar-sheet-leave-to {
  opacity: 0;
}
.card-sidebar-sheet-leave-to > div:last-child {
  transform: translateY(100%);
}
</style>

