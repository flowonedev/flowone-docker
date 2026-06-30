<script setup>
import { ref, computed, onMounted, nextTick } from 'vue'
import { useI18n } from 'vue-i18n'
import { useTodosStore } from '@/addons/tasks/stores/todos'
import { useMailboxStore } from '@/stores/mailbox'
import { useToastStore } from '@/stores/toast'
import { useTodoGroups } from '@/addons/tasks/composables/useTodoGroups'
import TodoGreetingHeader from '../todos/TodoGreetingHeader.vue'
import TodoSearchBar from '../todos/TodoSearchBar.vue'
import TodoSection from '../todos/TodoSection.vue'
import TodoCard from '../todos/TodoCard.vue'
import TodoQuickAdd from '../todos/TodoQuickAdd.vue'
import TodoSubtaskList from '../todos/TodoSubtaskList.vue'
import ClearCompletedConfirm from './ClearCompletedConfirm.vue'

const emit = defineEmits(['convert'])

const { t } = useI18n()
const todosStore = useTodosStore()
const mailbox = useMailboxStore()
const toast = useToastStore()

const search = ref('')
const expandedIds = ref(new Set())
const editingId = ref(null)
const editTitle = ref('')
const clearingCompleted = ref(false)
const showClearConfirm = ref(false)

// Subtask list refs keyed by todo id, so the menu's "Add subtask" can trigger
// the input inside the expanded card.
const subtaskListRefs = new Map()
function registerSubtaskList(id, el) {
  if (el) subtaskListRefs.set(id, el)
  else subtaskListRefs.delete(id)
}

const EXPANDED_KEY = 'todo_expanded_ids'

function loadExpanded() {
  try {
    const raw = localStorage.getItem(EXPANDED_KEY)
    if (raw) expandedIds.value = new Set(JSON.parse(raw))
  } catch {}
}
function saveExpanded() {
  try {
    localStorage.setItem(EXPANDED_KEY, JSON.stringify([...expandedIds.value]))
  } catch {}
}
loadExpanded()

const groups = useTodoGroups(computed(() => todosStore.todos))

function applySearch(items) {
  const q = search.value.trim().toLowerCase()
  if (!q) return items
  return items.filter(t => (t.title || '').toLowerCase().includes(q))
}

const todayList = computed(() => applySearch(groups.today.value))
const overdueList = computed(() => applySearch(groups.overdue.value))
const upcomingList = computed(() => applySearch(groups.upcoming.value))
// The COMPLETED section is now controlled by its own collapse state (the
// chevron in the section header), so we always show its cards when expanded.
const completedList = computed(() => applySearch(groups.completed.value))

const totalVisible = computed(
  () => todayList.value.length + overdueList.value.length + upcomingList.value.length + completedList.value.length
)

function isExpanded(id) {
  return expandedIds.value.has(id)
}
function toggleExpand(id) {
  if (expandedIds.value.has(id)) expandedIds.value.delete(id)
  else expandedIds.value.add(id)
  expandedIds.value = new Set(expandedIds.value)
  saveExpanded()
}

async function onCreate({ title }) {
  const created = await todosStore.createTodo({ title })
  if (created) toast.success(t('tasksPanel.toast.taskAdded'))
}

async function toggleTodo(todo) {
  await todosStore.toggleTodo(todo.id)
}

function startEdit(todo) {
  editingId.value = todo.id
  editTitle.value = todo.title
  if (!expandedIds.value.has(todo.id)) toggleExpand(todo.id)
}

async function saveEdit(todo) {
  const value = editTitle.value.trim()
  if (!value) {
    editingId.value = null
    return
  }
  await todosStore.updateTodo(todo.id, { title: value })
  editingId.value = null
}

function cancelEdit() {
  editingId.value = null
}

function onEditKey(e, todo) {
  if (e.key === 'Enter') saveEdit(todo)
  else if (e.key === 'Escape') cancelEdit()
}

async function setPriority(todo, priority) {
  await todosStore.updateTodo(todo.id, { priority })
}

async function deleteTodo(todo) {
  if (await todosStore.deleteTodo(todo.id)) {
    if (expandedIds.value.has(todo.id)) {
      expandedIds.value.delete(todo.id)
      expandedIds.value = new Set(expandedIds.value)
      saveExpanded()
    }
    toast.success(t('tasksPanel.toast.taskDeleted'))
  }
}

const clearTargetCount = computed(() => groups.completed.value.length)

function openClearConfirm() {
  if (clearTargetCount.value === 0) {
    toast.info(t('tasksPanel.clearCompleted.empty'))
    return
  }
  showClearConfirm.value = true
}

function closeClearConfirm() {
  if (clearingCompleted.value) return
  showClearConfirm.value = false
}

async function confirmClearCompleted() {
  clearingCompleted.value = true
  try {
    const { deleted, error } = await todosStore.deleteAllCompleted()
    if (deleted > 0) {
      toast.success(t('tasksPanel.clearCompleted.done', { count: deleted }))
      showClearConfirm.value = false
    } else if (error) {
      toast.error(`${t('tasksPanel.clearCompleted.failed')} (${error})`)
    } else {
      toast.error(t('tasksPanel.clearCompleted.failed'))
    }
  } finally {
    clearingCompleted.value = false
  }
}

async function openEmail(todo) {
  if (!todo.ref_folder || !todo.ref_uid) {
    toast.warning(t('tasksPanel.toast.emailRefMissing'))
    return
  }
  try {
    if (mailbox.currentFolder !== todo.ref_folder) {
      await mailbox.fetchMessages(todo.ref_folder)
    }
    await mailbox.fetchMessage(todo.ref_uid)
    todosStore.closePanel()
  } catch (e) {
    console.error('Failed to open email:', e)
    toast.error(t('tasksPanel.toast.emailNotFound'))
  }
}

async function addSubtask(todo) {
  if (!expandedIds.value.has(todo.id)) {
    toggleExpand(todo.id)
  }
  await nextTick()
  subtaskListRefs.get(todo.id)?.startAdd()
}

function getSenderName(from) {
  if (!from) return null
  const match = from.match(/^([^<]+)\s*</)
  if (match) return match[1].trim()
  return from.split('@')[0]
}

onMounted(() => {
  todosStore.fetchTodos()
})
</script>

<template>
  <div class="flex-1 flex flex-col overflow-hidden">
    <TodoGreetingHeader
      :completed-today="groups.completedTodayCount.value"
      :total-today="groups.totalTodayCount.value"
      :percent="groups.progressPercent.value"
    />

    <TodoSearchBar v-model="search" />

    <div class="flex-1 overflow-y-auto pb-24">
      <div v-if="todosStore.loading" class="flex justify-center py-12">
        <span class="material-symbols-rounded text-3xl text-primary-500 animate-spin">progress_activity</span>
      </div>

      <div
        v-else-if="todosStore.todos.length === 0"
        class="px-6 py-12 text-center"
      >
        <span class="material-symbols-rounded text-5xl text-surface-300 dark:text-surface-600 block mb-3">task_alt</span>
        <p class="text-sm text-surface-600 dark:text-surface-400">{{ t('tasksPanel.empty.noTasks') }}</p>
        <p class="text-xs text-surface-500 mt-1">{{ t('tasksPanel.empty.hint') }}</p>
      </div>

      <div
        v-else-if="search && totalVisible === 0"
        class="px-6 py-12 text-center"
      >
        <span class="material-symbols-rounded text-5xl text-surface-300 dark:text-surface-600 block mb-3">search_off</span>
        <p class="text-sm text-surface-600 dark:text-surface-400">
          {{ t('tasksPanel.empty.noMatches', { q: search }) }}
        </p>
      </div>

      <template v-else>
        <TodoSection
          id="today"
          :label="t('tasksPanel.sections.today')"
          icon="today"
          tone="info"
          :count="todayList.length"
          :default-open="true"
          hide-when-empty
        >
          <div class="space-y-2 px-2">
            <TodoCard
              v-for="todo in todayList"
              :key="todo.id"
              :todo="todo"
              :expanded="isExpanded(todo.id)"
              @toggle="toggleTodo(todo)"
              @toggle-expand="toggleExpand(todo.id)"
              @edit="startEdit(todo)"
              @add-subtask="addSubtask(todo)"
              @convert="emit('convert', todo)"
              @delete="deleteTodo(todo)"
              @open-email="openEmail(todo)"
              @set-priority="(p) => setPriority(todo, p)"
            >
              <template #expanded>
                <div class="space-y-3">
                  <div v-if="editingId === todo.id">
                    <input
                      v-model="editTitle"
                      type="text"
                      class="w-full px-2 py-1.5 bg-surface-100 dark:bg-surface-700 border border-surface-300 dark:border-surface-600 rounded-lg text-sm outline-none focus:border-primary-500"
                      @keydown="(e) => onEditKey(e, todo)"
                      @blur="saveEdit(todo)"
                      autofocus
                    />
                  </div>

                  <div
                    v-if="todo.ref_selected_text"
                    class="pl-3 border-l-2 border-primary-500 text-xs text-surface-500 dark:text-surface-400 italic"
                  >
                    "{{ todo.ref_selected_text.length > 160 ? todo.ref_selected_text.substring(0, 160) + '...' : todo.ref_selected_text }}"
                  </div>

                  <div
                    v-if="todo.ref_from"
                    class="flex items-center gap-1.5 text-xs text-surface-600 dark:text-surface-400"
                  >
                    <span class="material-symbols-rounded text-sm">mail</span>
                    {{ t('tasksPanel.card.from', { name: getSenderName(todo.ref_from) }) }}
                  </div>

                  <button
                    v-if="todo.ref_message_id || todo.ref_uid"
                    type="button"
                    class="inline-flex items-center gap-1.5 px-2.5 py-1 text-xs bg-surface-100 dark:bg-surface-700 hover:bg-surface-200 dark:hover:bg-surface-600 rounded-lg text-surface-600 dark:text-surface-300 transition-colors"
                    @click="openEmail(todo)"
                  >
                    <span class="material-symbols-rounded text-sm text-primary-500">open_in_new</span>
                    <span class="truncate max-w-[220px]">
                      {{ todo.ref_subject || t('tasksPanel.card.viewSourceEmail') }}
                    </span>
                  </button>

                  <TodoSubtaskList
                    :ref="(el) => registerSubtaskList(todo.id, el)"
                    :parent-todo="todo"
                  />
                </div>
              </template>
            </TodoCard>
          </div>
        </TodoSection>

        <TodoSection
          id="overdue"
          :label="t('tasksPanel.sections.overdue')"
          icon="schedule"
          tone="danger"
          :count="overdueList.length"
          hide-when-empty
        >
          <div class="space-y-2 px-2">
            <TodoCard
              v-for="todo in overdueList"
              :key="todo.id"
              :todo="todo"
              :expanded="isExpanded(todo.id)"
              @toggle="toggleTodo(todo)"
              @toggle-expand="toggleExpand(todo.id)"
              @edit="startEdit(todo)"
              @add-subtask="addSubtask(todo)"
              @convert="emit('convert', todo)"
              @delete="deleteTodo(todo)"
              @open-email="openEmail(todo)"
              @set-priority="(p) => setPriority(todo, p)"
            >
              <template #expanded>
                <TodoSubtaskList
                  :ref="(el) => registerSubtaskList(todo.id, el)"
                  :parent-todo="todo"
                />
              </template>
            </TodoCard>
          </div>
        </TodoSection>

        <TodoSection
          id="upcoming"
          :label="t('tasksPanel.sections.upcoming')"
          icon="calendar_month"
          tone="neutral"
          :count="upcomingList.length"
          hide-when-empty
        >
          <div class="space-y-2 px-2">
            <TodoCard
              v-for="todo in upcomingList"
              :key="todo.id"
              :todo="todo"
              :expanded="isExpanded(todo.id)"
              @toggle="toggleTodo(todo)"
              @toggle-expand="toggleExpand(todo.id)"
              @edit="startEdit(todo)"
              @add-subtask="addSubtask(todo)"
              @convert="emit('convert', todo)"
              @delete="deleteTodo(todo)"
              @open-email="openEmail(todo)"
              @set-priority="(p) => setPriority(todo, p)"
            >
              <template #expanded>
                <TodoSubtaskList
                  :ref="(el) => registerSubtaskList(todo.id, el)"
                  :parent-todo="todo"
                />
              </template>
            </TodoCard>
          </div>
        </TodoSection>

        <TodoSection
          id="completed"
          :label="t('tasksPanel.sections.completed')"
          icon="check_circle"
          tone="success"
          :count="groups.completed.value.length"
          hide-when-empty
        >
          <template #action>
            <button
              type="button"
              class="px-2 py-1 text-[11px] font-medium rounded-md text-surface-500 hover:text-red-500 hover:bg-red-500/10 transition-colors flex items-center gap-1"
              :disabled="clearingCompleted"
              @click="openClearConfirm"
            >
              <span
                class="material-symbols-rounded text-[14px]"
                :class="{ 'animate-spin': clearingCompleted }"
              >
                {{ clearingCompleted ? 'progress_activity' : 'delete_sweep' }}
              </span>
              {{ t('tasksPanel.clearCompleted.button') }}
            </button>
          </template>
          <div class="space-y-2 px-2">
            <TodoCard
              v-for="todo in completedList"
              :key="todo.id"
              :todo="todo"
              :expanded="isExpanded(todo.id)"
              @toggle="toggleTodo(todo)"
              @toggle-expand="toggleExpand(todo.id)"
              @edit="startEdit(todo)"
              @add-subtask="addSubtask(todo)"
              @convert="emit('convert', todo)"
              @delete="deleteTodo(todo)"
              @open-email="openEmail(todo)"
              @set-priority="(p) => setPriority(todo, p)"
            />
          </div>
        </TodoSection>
      </template>
    </div>

    <div class="border-t border-surface-200 dark:border-surface-700 bg-white dark:bg-surface-900">
      <TodoQuickAdd @create="onCreate" />
    </div>

    <ClearCompletedConfirm
      :open="showClearConfirm"
      :count="clearTargetCount"
      :loading="clearingCompleted"
      @close="closeClearConfirm"
      @confirm="confirmClearCompleted"
    />
  </div>
</template>
