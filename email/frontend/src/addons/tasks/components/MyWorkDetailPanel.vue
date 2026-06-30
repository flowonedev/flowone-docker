<script setup>
import { ref, computed, watch, nextTick, onBeforeUnmount } from 'vue'
import { useRouter } from 'vue-router'
import { useTodosStore } from '@/addons/tasks/stores/todos'
import { useBoardsStore } from '@/addons/kanban-boards/stores/boards'
import { useMyWorkStore } from '@/addons/tasks/stores/myWork'
import { useToastStore } from '@/stores/toast'
import { useAddons } from '@/composables/useAddons'
import clientTimeTracker from '@/addons/time-tracker/services/clientTimeTracker'
import { useCardTimer } from '@/addons/project-hub/composables/useCardTimer'
import api from '@/services/api'

const props = defineProps({
  item: { type: Object, default: null }
})

const emit = defineEmits(['close', 'delete'])
const router = useRouter()
const todosStore = useTodosStore()
const boardsStore = useBoardsStore()
const myWorkStore = useMyWorkStore()
const toast = useToastStore()
const { timeTrackerEnabled, projectHubEnabled } = useAddons()
const phCardTimer = useCardTimer()

const editingTitle = ref(false)
const editTitle = ref('')
const editingDescription = ref(false)
const editDescription = ref('')
const editingDueDate = ref(false)
const editDueDate = ref('')
const editingPriority = ref(false)
const addingSubtask = ref(false)
const newSubtaskTitle = ref('')
const addingChecklistItem = ref(null)
const newChecklistItemTitle = ref('')
const subtaskInput = ref(null)

// Time tracking
const trackedSeconds = ref(0)
const isTimerRunning = ref(false)
const timerInterval = ref(null)
const sessionStart = ref(null)
const loadingTime = ref(false)

async function fetchTrackedTime() {
  if (!props.item || !timeTrackerEnabled.value) return
  loadingTime.value = true
  try {
    const entityType = props.item.type === 'card' ? 'card' : 'todo'
    const response = await api.get(`/time/entity/${entityType}/${props.item.rawId}`)
    if (response.data.success) {
      trackedSeconds.value = response.data.data.total_seconds || 0
    }
  } catch {
    trackedSeconds.value = 0
  } finally {
    loadingTime.value = false
  }
}

function formatTrackedTime(seconds) {
  if (seconds < 60) return seconds + 's'
  const h = Math.floor(seconds / 3600)
  const m = Math.floor((seconds % 3600) / 60)
  if (h > 0) return h + 'h ' + (m > 0 ? m + 'm' : '')
  return m + 'm'
}

function startTimer() {
  if (isTimerRunning.value) return
  isTimerRunning.value = true
  sessionStart.value = Date.now()
  if (props.item.type === 'card' && props.item.boardId) {
    clientTimeTracker.trackBoardActivity(
      props.item.boardId,
      props.item.rawId,
      props.item.title,
      props.item.boardName
    )
    // Card-open time reaches projecthub_work_sessions only via the card_view
    // timer (the time-tracker bridge no longer forwards board_task entries)
    if (projectHubEnabled.value) {
      phCardTimer.startTimer(props.item.rawId)
    }
  }
  timerInterval.value = setInterval(() => {
    const elapsed = Math.floor((Date.now() - sessionStart.value) / 1000)
    trackedSeconds.value = (trackedSeconds.value || 0) + 1
    void elapsed
  }, 1000)
}

function stopTimer() {
  if (!isTimerRunning.value) return
  isTimerRunning.value = false
  if (timerInterval.value) {
    clearInterval(timerInterval.value)
    timerInterval.value = null
  }
  clientTimeTracker.stopTracking()
  if (phCardTimer.isRunning.value) {
    phCardTimer.stopTimer()
  }
  sessionStart.value = null
}

onBeforeUnmount(() => {
  if (timerInterval.value) {
    clearInterval(timerInterval.value)
  }
  if (phCardTimer.isRunning.value) {
    phCardTimer.stopTimerSync()
  }
})

const priorityConfig = {
  high: { label: 'High', bgClass: 'bg-red-100 dark:bg-red-900/30', textClass: 'text-red-600 dark:text-red-400', dotClass: 'bg-red-500' },
  normal: { label: 'Medium', bgClass: 'bg-amber-100 dark:bg-amber-900/30', textClass: 'text-amber-600 dark:text-amber-400', dotClass: 'bg-amber-500' },
  low: { label: 'Low', bgClass: 'bg-blue-100 dark:bg-blue-900/30', textClass: 'text-blue-600 dark:text-blue-400', dotClass: 'bg-blue-500' }
}

const pConfig = computed(() => priorityConfig[props.item?.priority] || priorityConfig.normal)

const dueInfo = computed(() => {
  if (!props.item?.dueDate) return null
  const now = new Date()
  const today = new Date(now.getFullYear(), now.getMonth(), now.getDate())
  const due = new Date(props.item.dueDate)
  const dueDay = new Date(due.getFullYear(), due.getMonth(), due.getDate())
  const diff = Math.round((dueDay - today) / (1000 * 60 * 60 * 24))
  if (diff < 0) return { text: 'Overdue', class: 'text-red-500', bgClass: 'bg-red-500/10' }
  if (diff === 0) return { text: 'Today', class: 'text-amber-500', bgClass: 'bg-amber-500/10' }
  if (diff === 1) return { text: 'Tomorrow', class: 'text-amber-400', bgClass: 'bg-amber-500/10' }
  if (diff < 7) return { text: due.toLocaleDateString([], { weekday: 'long' }), class: 'text-blue-500', bgClass: 'bg-blue-500/10' }
  return { text: due.toLocaleDateString([], { weekday: 'short', month: 'short', day: 'numeric' }), class: 'text-surface-500', bgClass: 'bg-surface-500/10' }
})

const formattedDueDate = computed(() => {
  if (!props.item?.dueDate) return null
  return new Date(props.item.dueDate).toLocaleDateString([], { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' })
})

const formattedCreatedAt = computed(() => {
  if (!props.item?.createdAt) return null
  return new Date(props.item.createdAt).toLocaleDateString([], { month: 'short', day: 'numeric', year: 'numeric', hour: '2-digit', minute: '2-digit' })
})

const compactDescription = computed(() => {
  if (!props.item?.description) return ''
  return props.item.description
    .replace(/\r\n/g, '\n')
    .replace(/\n{3,}/g, '\n\n')
    .trim()
})

function getChecklistProgress(checklist) {
  if (!checklist.items?.length) return { done: 0, total: 0, percent: 0 }
  const done = checklist.items.filter(i => i.completed).length
  const total = checklist.items.length
  return { done, total, percent: Math.round((done / total) * 100) }
}

function getSenderName(from) {
  if (!from) return null
  const match = from.match(/^([^<]+)\s*</)
  if (match) return match[1].trim()
  return from.split('@')[0]
}

// Title editing
function startEditTitle() {
  editTitle.value = props.item.title
  editingTitle.value = true
}

async function saveTitle() {
  if (!editTitle.value.trim()) {
    editingTitle.value = false
    return
  }
  const newTitle = editTitle.value.trim()
  const { id, type, rawId } = props.item
  editingTitle.value = false
  myWorkStore.updateItemLocally(id, { title: newTitle })
  const save = type === 'todo'
    ? todosStore.updateTodo(rawId, { title: newTitle })
    : boardsStore.updateCard(rawId, { title: newTitle })
  myWorkStore.trackSave(save)
}

// Priority
function setPriority(priority) {
  const { id, type, rawId } = props.item
  editingPriority.value = false
  myWorkStore.updateItemLocally(id, { priority })
  const save = type === 'todo'
    ? todosStore.updateTodo(rawId, { priority })
    : boardsStore.updateCard(rawId, { priority })
  myWorkStore.trackSave(save)
}

// Due date
function startEditDueDate() {
  editDueDate.value = props.item.dueDate ? new Date(props.item.dueDate).toISOString().split('T')[0] : ''
  editingDueDate.value = true
}

function saveDueDate() {
  const val = editDueDate.value || null
  const { id, type, rawId } = props.item
  editingDueDate.value = false
  myWorkStore.updateItemLocally(id, { dueDate: val })
  const save = type === 'todo'
    ? todosStore.updateTodo(rawId, { due_date: val })
    : boardsStore.updateCard(rawId, { due_date: val })
  myWorkStore.trackSave(save)
}

function clearDueDate() {
  const { id, type, rawId } = props.item
  editingDueDate.value = false
  myWorkStore.updateItemLocally(id, { dueDate: null })
  const save = type === 'todo'
    ? todosStore.updateTodo(rawId, { due_date: null })
    : boardsStore.updateCard(rawId, { due_date: null })
  myWorkStore.trackSave(save)
}

// Toggle complete
function handleToggle() {
  const { id, type, rawId } = props.item
  const newCompleted = !props.item.completed
  myWorkStore.updateItemLocally(id, {
    completed: newCompleted,
    completedAt: newCompleted ? new Date().toISOString() : null
  })
  const save = type === 'todo'
    ? todosStore.toggleTodo(rawId)
    : boardsStore.updateCard(rawId, { completed: newCompleted })
  myWorkStore.trackSave(save)
}

// Delete
function handleDelete() {
  emit('delete', props.item)
}

// Subtasks (todos)
async function addSubtask() {
  if (!newSubtaskTitle.value.trim()) {
    addingSubtask.value = false
    return
  }
  const title = newSubtaskTitle.value.trim()
  newSubtaskTitle.value = ''
  const result = await todosStore.createSubtodo(props.item.rawId, title)
  if (result) {
    myWorkStore.addSubtodoLocally(props.item.id, result)
  }
}

function toggleSubtask(subtask) {
  myWorkStore.toggleSubtodoLocally(props.item.id, subtask.id)
  myWorkStore.trackSave(todosStore.toggleTodo(subtask.id))
}

function deleteSubtask(subtask) {
  myWorkStore.removeSubtodoLocally(props.item.id, subtask.id)
  myWorkStore.trackSave(todosStore.deleteTodo(subtask.id))
}

// Checklist items (cards)
function toggleChecklistItem(clItem) {
  myWorkStore.toggleChecklistItemLocally(props.item.id, clItem.id)
  myWorkStore.trackSave(boardsStore.toggleChecklistItem(clItem.id, !clItem.completed))
}

async function addChecklistItem(checklistId) {
  if (!newChecklistItemTitle.value.trim()) {
    addingChecklistItem.value = null
    return
  }
  const title = newChecklistItemTitle.value.trim()
  newChecklistItemTitle.value = ''
  addingChecklistItem.value = null
  const newItem = await boardsStore.addChecklistItem(checklistId, title)
  if (newItem) {
    myWorkStore.addChecklistItemLocally(props.item.id, checklistId, newItem)
  }
}

function deleteChecklistItem(itemId) {
  myWorkStore.removeChecklistItemLocally(props.item.id, itemId)
  myWorkStore.trackSave(boardsStore.deleteChecklistItem(itemId))
}

function goToBoard() {
  if (props.item?.boardId) {
    router.push(`/boards/${props.item.boardId}`)
    closePanel()
  }
}

function closePanel() {
  stopTimer()
  emit('close')
  myWorkStore.backgroundRefresh()
}

watch(() => props.item, (newItem) => {
  editingTitle.value = false
  editingDescription.value = false
  editingDueDate.value = false
  editingPriority.value = false
  addingSubtask.value = false
  addingChecklistItem.value = null
  stopTimer()
  trackedSeconds.value = 0
  if (newItem) fetchTrackedTime()
})

watch(addingSubtask, (val) => {
  if (val) {
    nextTick(() => {
      subtaskInput.value?.focus()
    })
  }
})
</script>

<template>
  <Teleport to="body">
    <!-- Backdrop -->
    <Transition name="fade">
      <div
        v-if="item"
        class="fixed inset-0 bg-black/30 z-40"
        @click="closePanel"
      ></div>
    </Transition>

    <!-- Panel -->
    <Transition name="slide">
      <div
        v-if="item"
        class="fixed right-0 top-0 h-full w-full max-w-[520px] bg-white dark:bg-surface-900 shadow-2xl z-50 flex flex-col border-l border-surface-200 dark:border-surface-700 mobile-detail-panel"
      >
        <!-- Header -->
        <div class="px-5 py-4 border-b border-surface-200 dark:border-surface-700 flex items-center justify-between gap-3 safe-area-top">
          <div class="flex items-center gap-2 min-w-0">
            <span class="material-symbols-rounded text-primary-500">
              {{ item.type === 'card' ? 'dashboard' : 'task_alt' }}
            </span>
            <span class="text-xs font-medium text-surface-500 uppercase tracking-wide">
              {{ item.type === 'card' ? 'Board Card' : 'Task' }}
            </span>
          </div>
          <div class="flex items-center gap-1">
            <button
              v-if="item.type === 'card' && item.boardId"
              @click="goToBoard"
              class="p-2 rounded-lg hover:bg-surface-100 dark:hover:bg-surface-700 text-surface-500 hover:text-primary-500 transition-colors"
              title="Open in board"
            >
              <span class="material-symbols-rounded text-xl">open_in_new</span>
            </button>
            <button
              @click="handleDelete"
              class="p-2 rounded-lg hover:bg-red-500/10 text-surface-500 hover:text-red-500 transition-colors"
              title="Delete"
            >
              <span class="material-symbols-rounded text-xl">delete</span>
            </button>
            <button
              @click="closePanel"
              class="p-2 rounded-lg hover:bg-surface-100 dark:hover:bg-surface-700 text-surface-500 transition-colors"
            >
              <span class="material-symbols-rounded text-xl">close</span>
            </button>
          </div>
        </div>

        <!-- Content -->
        <div class="flex-1 overflow-y-auto">
          <div class="p-5 space-y-5">

            <!-- Title + Checkbox -->
            <div class="flex items-start gap-3">
              <button
                @click="handleToggle"
                :class="[
                  'mt-1 w-6 h-6 rounded-lg border-2 flex items-center justify-center shrink-0 transition-all',
                  item.completed
                    ? 'bg-primary-500 border-primary-500 text-white'
                    : item.priority === 'high' ? 'border-red-400 hover:border-red-500'
                    : item.priority === 'low' ? 'border-blue-400 hover:border-blue-500'
                    : 'border-amber-400 hover:border-amber-500'
                ]"
              >
                <span v-if="item.completed" class="material-symbols-rounded text-sm">check</span>
              </button>
              <div class="flex-1 min-w-0">
                <input
                  v-if="editingTitle"
                  v-model="editTitle"
                  class="w-full text-lg font-semibold bg-transparent border-b-2 border-primary-500 text-surface-900 dark:text-surface-100 outline-none pb-1"
                  @keydown.enter="saveTitle"
                  @keydown.escape="editingTitle = false"
                  @blur="saveTitle"
                  autofocus
                />
                <h2
                  v-else
                  :class="[
                    'text-lg font-semibold cursor-pointer hover:text-primary-500 transition-colors',
                    item.completed ? 'text-surface-400 line-through' : 'text-surface-900 dark:text-surface-100'
                  ]"
                  @click="startEditTitle"
                >
                  {{ item.title }}
                </h2>
              </div>
            </div>

            <!-- Properties Grid -->
            <div class="grid grid-cols-[auto_1fr] gap-x-4 gap-y-3 text-sm">

              <!-- Priority -->
              <span class="flex items-center gap-2 text-surface-500">
                <span class="material-symbols-rounded text-lg">flag</span>
                Priority
              </span>
              <div class="relative">
                <button
                  @click="editingPriority = !editingPriority"
                  :class="['px-3 py-1 rounded-full text-xs font-medium inline-flex items-center gap-1.5 transition-colors', pConfig.bgClass, pConfig.textClass]"
                >
                  <span :class="['w-2 h-2 rounded-full', pConfig.dotClass]"></span>
                  {{ pConfig.label }}
                  <span class="material-symbols-rounded text-sm">expand_more</span>
                </button>
                <div
                  v-if="editingPriority"
                  class="absolute top-full left-0 mt-1 bg-white dark:bg-surface-700 rounded-xl shadow-xl border border-surface-200 dark:border-surface-600 py-1 z-50 min-w-[120px]"
                >
                  <button
                    v-for="(conf, key) in priorityConfig"
                    :key="key"
                    @click="setPriority(key)"
                    class="w-full px-3 py-2 text-left text-xs hover:bg-surface-50 dark:hover:bg-surface-600 flex items-center gap-2"
                    :class="conf.textClass"
                  >
                    <span :class="['w-2 h-2 rounded-full', conf.dotClass]"></span>
                    {{ conf.label }}
                  </button>
                </div>
              </div>

              <!-- Due Date -->
              <span class="flex items-center gap-2 text-surface-500">
                <span class="material-symbols-rounded text-lg">event</span>
                Due Date
              </span>
              <div>
                <template v-if="editingDueDate">
                  <div class="flex items-center gap-2">
                    <input
                      v-model="editDueDate"
                      type="date"
                      class="px-3 py-1.5 text-sm bg-surface-50 dark:bg-surface-700 border border-surface-300 dark:border-surface-600 rounded-lg text-surface-900 dark:text-surface-100 outline-none focus:border-primary-500"
                      @keydown.enter="saveDueDate"
                      @keydown.escape="editingDueDate = false"
                    />
                    <button @click="saveDueDate" class="p-1 text-primary-500 hover:text-primary-600">
                      <span class="material-symbols-rounded text-lg">check</span>
                    </button>
                    <button v-if="item.dueDate" @click="clearDueDate" class="p-1 text-red-400 hover:text-red-500">
                      <span class="material-symbols-rounded text-lg">close</span>
                    </button>
                  </div>
                </template>
                <template v-else>
                  <button
                    @click="startEditDueDate"
                    :class="[
                      'px-3 py-1 rounded-full text-xs font-medium inline-flex items-center gap-1.5 transition-colors',
                      dueInfo ? [dueInfo.bgClass, dueInfo.class] : 'bg-surface-100 dark:bg-surface-700 text-surface-500 hover:text-surface-700 dark:hover:text-surface-300'
                    ]"
                  >
                    <span class="material-symbols-rounded text-sm">calendar_today</span>
                    {{ dueInfo ? dueInfo.text : 'Set due date' }}
                  </button>
                  <p v-if="formattedDueDate" class="text-xs text-surface-400 mt-0.5">{{ formattedDueDate }}</p>
                </template>
              </div>

              <!-- Status -->
              <span class="flex items-center gap-2 text-surface-500">
                <span class="material-symbols-rounded text-lg">radio_button_checked</span>
                Status
              </span>
              <div>
                <span v-if="item.listName" class="px-3 py-1 rounded-full text-xs font-medium bg-surface-100 dark:bg-surface-700 text-surface-600 dark:text-surface-400 inline-flex items-center gap-1.5">
                  <span class="material-symbols-rounded text-sm">arrow_forward</span>
                  {{ item.listName }}
                </span>
                <span v-else-if="item.completed" class="px-3 py-1 rounded-full text-xs font-medium bg-green-100 dark:bg-green-900/30 text-green-600 dark:text-green-400 inline-flex items-center gap-1.5">
                  <span class="material-symbols-rounded text-sm">check</span>
                  Completed
                </span>
                <span v-else class="px-3 py-1 rounded-full text-xs font-medium bg-blue-100 dark:bg-blue-900/30 text-blue-600 dark:text-blue-400 inline-flex items-center gap-1.5">
                  <span class="material-symbols-rounded text-sm">pending</span>
                  To Do
                </span>
              </div>

              <!-- Assigned (cards only) -->
              <template v-if="item.assignedTo">
                <span class="flex items-center gap-2 text-surface-500">
                  <span class="material-symbols-rounded text-lg">person</span>
                  Assignee
                </span>
                <span class="text-surface-700 dark:text-surface-300 inline-flex items-center gap-2">
                  <span class="w-6 h-6 rounded-full bg-primary-500 text-white text-xs flex items-center justify-center font-medium shrink-0">
                    {{ (item.assignedTo || '?')[0].toUpperCase() }}
                  </span>
                  {{ item.assignedTo }}
                </span>
              </template>

              <!-- Board (cards only) -->
              <template v-if="item.type === 'card' && item.boardName">
                <span class="flex items-center gap-2 text-surface-500">
                  <span class="material-symbols-rounded text-lg">dashboard</span>
                  Board
                </span>
                <button
                  @click="goToBoard"
                  class="text-primary-500 hover:text-primary-600 dark:text-primary-400 dark:hover:text-primary-300 font-medium text-left transition-colors inline-flex items-center gap-1"
                >
                  {{ item.boardName }}
                  <span class="material-symbols-rounded text-sm">open_in_new</span>
                </button>
              </template>

              <!-- Drive Folder -->
              <template v-if="item.boardDriveFolderId">
                <span class="flex items-center gap-2 text-surface-500">
                  <span class="material-symbols-rounded text-lg">folder</span>
                  Drive
                </span>
                <button
                  @click="router.push(`/drive/folder/${item.boardDriveFolderId}`); closePanel()"
                  class="text-amber-500 hover:text-amber-600 dark:text-amber-400 dark:hover:text-amber-300 font-medium text-left transition-colors inline-flex items-center gap-1"
                >
                  Open files
                  <span class="material-symbols-rounded text-sm">folder_open</span>
                </button>
              </template>

              <!-- Track Time -->
              <template v-if="timeTrackerEnabled">
                <span class="flex items-center gap-2 text-surface-500">
                  <span class="material-symbols-rounded text-lg">timer</span>
                  Tracked Time
                </span>
                <div class="flex items-center gap-2">
                  <span v-if="loadingTime" class="text-xs text-surface-400">Loading...</span>
                  <template v-else>
                    <span :class="['text-sm font-medium tabular-nums', trackedSeconds > 0 ? 'text-surface-900 dark:text-surface-100' : 'text-surface-400']">
                      {{ trackedSeconds > 0 ? formatTrackedTime(trackedSeconds) : '0h' }}
                    </span>
                    <button
                      v-if="item.type === 'card'"
                      @click="isTimerRunning ? stopTimer() : startTimer()"
                      :class="[
                        'px-2.5 py-1 rounded-full text-xs font-medium inline-flex items-center gap-1 transition-colors',
                        isTimerRunning
                          ? 'bg-red-100 dark:bg-red-900/30 text-red-600 dark:text-red-400 hover:bg-red-200 dark:hover:bg-red-900/50'
                          : 'bg-primary-100 dark:bg-primary-900/30 text-primary-600 dark:text-primary-400 hover:bg-primary-200 dark:hover:bg-primary-900/50'
                      ]"
                    >
                      <span class="material-symbols-rounded text-sm">{{ isTimerRunning ? 'stop' : 'play_arrow' }}</span>
                      {{ isTimerRunning ? 'Stop' : 'Start' }}
                    </button>
                  </template>
                </div>
              </template>

              <!-- Created -->
              <span class="flex items-center gap-2 text-surface-500">
                <span class="material-symbols-rounded text-lg">schedule</span>
                Created
              </span>
              <span class="text-surface-500">{{ formattedCreatedAt || 'Unknown' }}</span>
            </div>

            <!-- Labels (cards only) -->
            <div v-if="item.labels?.length" class="space-y-2">
              <h3 class="text-xs font-semibold text-surface-500 uppercase tracking-wide flex items-center gap-2">
                <span class="material-symbols-rounded text-sm">label</span>
                Labels
              </h3>
              <div class="flex flex-wrap gap-2">
                <span
                  v-for="label in item.labels"
                  :key="label.id"
                  class="px-3 py-1 rounded-full text-xs font-medium text-white"
                  :style="{ backgroundColor: label.color }"
                >
                  {{ label.name }}
                </span>
              </div>
            </div>

            <!-- Email Reference (todos only) -->
            <div v-if="item.refSubject" class="space-y-2">
              <h3 class="text-xs font-semibold text-surface-500 uppercase tracking-wide flex items-center gap-2">
                <span class="material-symbols-rounded text-sm">mail</span>
                From Email
              </h3>
              <div class="p-3 bg-surface-50 dark:bg-surface-800 rounded-xl border border-surface-200 dark:border-surface-700 space-y-1">
                <p class="text-sm font-medium text-surface-900 dark:text-surface-100">{{ item.refSubject }}</p>
                <p v-if="item.refFrom" class="text-xs text-surface-500">
                  From: {{ getSenderName(item.refFrom) }}
                </p>
                <div
                  v-if="item.refSelectedText"
                  class="mt-2 pl-3 border-l-2 border-primary-500/40 text-xs text-surface-500 dark:text-surface-400 italic"
                >
                  "{{ item.refSelectedText }}"
                </div>
              </div>
            </div>

            <!-- Description (cards) -->
            <div v-if="item.type === 'card' && item.description" class="space-y-2">
              <h3 class="text-xs font-semibold text-surface-500 uppercase tracking-wide flex items-center gap-2">
                <span class="material-symbols-rounded text-sm">description</span>
                Description
              </h3>
              <p class="text-sm text-surface-700 dark:text-surface-300 whitespace-pre-wrap leading-relaxed">{{ compactDescription }}</p>
            </div>

            <!-- Subtasks (todos) -->
            <div v-if="item.type === 'todo'" class="space-y-2">
              <div class="flex items-center justify-between">
                <h3 class="text-xs font-semibold text-surface-500 uppercase tracking-wide flex items-center gap-2">
                  <span class="material-symbols-rounded text-sm">checklist</span>
                  Subtasks
                  <span v-if="item.subtodos?.length" class="text-surface-400">({{ item.subtodos.filter(s => s.completed).length }}/{{ item.subtodos.length }})</span>
                </h3>
                <button
                  v-if="!addingSubtask"
                  @click="addingSubtask = true"
                  class="text-xs text-primary-500 hover:text-primary-600 font-medium flex items-center gap-1"
                >
                  <span class="material-symbols-rounded text-sm">add</span>
                  Add
                </button>
              </div>

              <!-- Progress bar -->
              <div v-if="item.subtodos?.length" class="h-1.5 bg-surface-200 dark:bg-surface-700 rounded-full overflow-hidden">
                <div
                  class="h-full bg-primary-500 transition-all duration-300 rounded-full"
                  :style="{ width: (item.subtodos.filter(s => s.completed).length / item.subtodos.length * 100) + '%' }"
                ></div>
              </div>

              <div class="space-y-1">
                <div
                  v-for="subtask in item.subtodos"
                  :key="subtask.id"
                  class="flex items-center gap-2 py-1.5 px-2 rounded-lg hover:bg-surface-50 dark:hover:bg-surface-800 group/sub"
                >
                  <button
                    @click="toggleSubtask(subtask)"
                    :class="[
                      'w-4 h-4 rounded border-2 flex items-center justify-center shrink-0 transition-colors',
                      subtask.completed
                        ? 'bg-primary-500 border-primary-500 text-white'
                        : 'border-surface-400 dark:border-surface-500 hover:border-primary-500'
                    ]"
                  >
                    <span v-if="subtask.completed" class="material-symbols-rounded text-xs">check</span>
                  </button>
                  <span
                    :class="['flex-1 text-sm', subtask.completed ? 'text-surface-400 line-through' : 'text-surface-700 dark:text-surface-300']"
                  >{{ subtask.title }}</span>
                  <button
                    @click="deleteSubtask(subtask)"
                    class="p-0.5 rounded opacity-0 group-hover/sub:opacity-100 hover:bg-red-500/10 text-surface-400 hover:text-red-500 transition-all"
                  >
                    <span class="material-symbols-rounded text-sm">close</span>
                  </button>
                </div>
              </div>

              <!-- Add subtask input -->
              <form v-if="addingSubtask" @submit.prevent="addSubtask" class="flex items-center gap-2 py-1 px-2">
                <span class="material-symbols-rounded text-sm text-primary-500">add</span>
                <input
                  ref="subtaskInput"
                  v-model="newSubtaskTitle"
                  type="text"
                  placeholder="Add subtask..."
                  class="flex-1 text-sm bg-transparent outline-none text-surface-800 dark:text-surface-200 placeholder:text-surface-400"
                  @keydown.escape="addingSubtask = false; newSubtaskTitle = ''"
                />
                <button
                  type="submit"
                  :disabled="!newSubtaskTitle.trim()"
                  class="p-1 text-primary-500 hover:text-primary-600 disabled:text-surface-400 transition-colors"
                >
                  <span class="material-symbols-rounded text-lg">check</span>
                </button>
                <button
                  type="button"
                  @click="addingSubtask = false; newSubtaskTitle = ''"
                  class="p-1 text-surface-400 hover:text-surface-600 transition-colors"
                >
                  <span class="material-symbols-rounded text-lg">close</span>
                </button>
              </form>

              <p v-if="!item.subtodos?.length && !addingSubtask" class="text-xs text-surface-400 italic py-2">No subtasks yet</p>
            </div>

            <!-- Checklists (cards) -->
            <div v-if="item.type === 'card' && item.checklists?.length" class="space-y-4">
              <div v-for="checklist in item.checklists" :key="checklist.id" class="space-y-2">
                <div class="flex items-center gap-2">
                  <span class="material-symbols-rounded text-sm text-surface-400">checklist</span>
                  <h3 class="text-xs font-semibold text-surface-500 uppercase tracking-wide">{{ checklist.name || checklist.title }}</h3>
                  <span class="text-xs text-surface-400">{{ getChecklistProgress(checklist).done }}/{{ getChecklistProgress(checklist).total }}</span>
                  <div class="flex-1 h-1 bg-surface-200 dark:bg-surface-700 rounded-full overflow-hidden">
                    <div
                      class="h-full bg-primary-500 transition-all duration-300"
                      :style="{ width: getChecklistProgress(checklist).percent + '%' }"
                    ></div>
                  </div>
                </div>

                <div class="space-y-1 pl-5">
                  <div
                    v-for="clItem in checklist.items"
                    :key="clItem.id"
                    class="flex items-center gap-2 py-1.5 px-2 rounded-lg hover:bg-surface-50 dark:hover:bg-surface-800 group/cli"
                  >
                    <button
                      @click="toggleChecklistItem(clItem)"
                      :class="[
                        'w-4 h-4 rounded border-2 flex items-center justify-center shrink-0 transition-colors',
                        clItem.completed
                          ? 'bg-primary-500 border-primary-500 text-white'
                          : 'border-surface-400 dark:border-surface-500 hover:border-primary-500'
                      ]"
                    >
                      <span v-if="clItem.completed" class="material-symbols-rounded text-xs">check</span>
                    </button>
                    <span
                      :class="['flex-1 text-sm', clItem.completed ? 'text-surface-400 line-through' : 'text-surface-700 dark:text-surface-300']"
                    >{{ clItem.title }}</span>
                    <button
                      @click="deleteChecklistItem(clItem.id)"
                      class="p-0.5 rounded opacity-0 group-hover/cli:opacity-100 hover:bg-red-500/10 text-surface-400 hover:text-red-500 transition-all"
                    >
                      <span class="material-symbols-rounded text-sm">close</span>
                    </button>
                  </div>

                  <!-- Add checklist item -->
                  <form v-if="addingChecklistItem === checklist.id" @submit.prevent="addChecklistItem(checklist.id)" class="flex items-center gap-2 py-1 px-2">
                    <span class="material-symbols-rounded text-sm text-primary-500">add</span>
                    <input
                      v-model="newChecklistItemTitle"
                      type="text"
                      placeholder="Add item..."
                      class="flex-1 text-sm bg-transparent outline-none text-surface-800 dark:text-surface-200 placeholder:text-surface-400"
                      @keydown.escape="addingChecklistItem = null; newChecklistItemTitle = ''"
                    />
                    <button
                      type="submit"
                      :disabled="!newChecklistItemTitle.trim()"
                      class="p-1 text-primary-500 hover:text-primary-600 disabled:text-surface-400 transition-colors"
                    >
                      <span class="material-symbols-rounded text-lg">check</span>
                    </button>
                    <button
                      type="button"
                      @click="addingChecklistItem = null; newChecklistItemTitle = ''"
                      class="p-1 text-surface-400 hover:text-surface-600 transition-colors"
                    >
                      <span class="material-symbols-rounded text-lg">close</span>
                    </button>
                  </form>
                  <button
                    v-else
                    @click="addingChecklistItem = checklist.id"
                    class="text-xs text-primary-500 hover:text-primary-600 font-medium flex items-center gap-1 py-1 px-2"
                  >
                    <span class="material-symbols-rounded text-sm">add</span>
                    Add item
                  </button>
                </div>
              </div>
            </div>

            <!-- No checklists message for cards -->
            <div v-if="item.type === 'card' && (!item.checklists || item.checklists.length === 0)" class="space-y-2">
              <h3 class="text-xs font-semibold text-surface-500 uppercase tracking-wide flex items-center gap-2">
                <span class="material-symbols-rounded text-sm">checklist</span>
                Checklists
              </h3>
              <p class="text-xs text-surface-400 italic py-2">No checklists on this card</p>
            </div>

          </div>
        </div>

        <!-- Footer -->
        <div class="px-5 py-3 border-t border-surface-200 dark:border-surface-700 flex items-center justify-between bg-white dark:bg-transparent">
          <button
            @click="handleToggle"
            :class="[
              'px-4 py-2 text-sm font-medium rounded-full transition-colors flex items-center gap-2',
              item.completed
                ? 'bg-surface-100 dark:bg-surface-700 text-surface-700 dark:text-surface-300 hover:bg-surface-200 dark:hover:bg-surface-600'
                : 'bg-primary-500 hover:bg-primary-600 text-white'
            ]"
          >
            <span class="material-symbols-rounded text-lg">{{ item.completed ? 'undo' : 'check' }}</span>
            {{ item.completed ? 'Mark Incomplete' : 'Mark Complete' }}
          </button>
          <button
            v-if="item.type === 'card' && item.boardId"
            @click="goToBoard"
            class="px-4 py-2 text-sm text-surface-500 hover:text-primary-500 font-medium flex items-center gap-1 transition-colors"
          >
            Open in Board
            <span class="material-symbols-rounded text-lg">open_in_new</span>
          </button>
        </div>
      </div>
    </Transition>
  </Teleport>
</template>

<style scoped>
.slide-enter-active,
.slide-leave-active {
  transition: transform 0.3s ease;
}
.slide-enter-from,
.slide-leave-to {
  transform: translateX(100%);
}
.fade-enter-active,
.fade-leave-active {
  transition: opacity 0.3s ease;
}
.fade-enter-from,
.fade-leave-to {
  opacity: 0;
}

@media (max-width: 640px) {
  .mobile-detail-panel {
    top: 4rem;
    border-radius: 1rem 0 0 0;
  }
}
</style>
