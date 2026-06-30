<script setup>
import { ref, computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useTodosStore } from '@/addons/tasks/stores/todos'
import { useToastStore } from '@/stores/toast'

const props = defineProps({
  parentTodo: { type: Object, required: true }
})

const { t } = useI18n()
const todosStore = useTodosStore()
const toast = useToastStore()

const adding = ref(false)
const newTitle = ref('')
const editingId = ref(null)
const editTitle = ref('')

const subtasks = computed(() => props.parentTodo.subtodos || [])

function startAdd() {
  adding.value = true
  newTitle.value = ''
}

async function saveNew() {
  const title = newTitle.value.trim()
  if (!title) {
    adding.value = false
    return
  }
  const created = await todosStore.createSubtodo(props.parentTodo.id, title)
  if (created) toast.success(t('tasksPanel.toast.subtaskAdded'))
  adding.value = false
  newTitle.value = ''
}

function cancelAdd() {
  adding.value = false
  newTitle.value = ''
}

function onAddKey(e) {
  if (e.key === 'Enter') saveNew()
  else if (e.key === 'Escape') cancelAdd()
}

async function toggleSubtask(sub) {
  await todosStore.toggleTodo(sub.id)
}

function startEdit(sub) {
  editingId.value = sub.id
  editTitle.value = sub.title
}

async function saveEdit(sub) {
  const title = editTitle.value.trim()
  if (!title) {
    editingId.value = null
    return
  }
  await todosStore.updateTodo(sub.id, { title })
  editingId.value = null
}

function cancelEdit() {
  editingId.value = null
}

function onEditKey(e, sub) {
  if (e.key === 'Enter') saveEdit(sub)
  else if (e.key === 'Escape') cancelEdit()
}

async function deleteSubtask(sub) {
  if (await todosStore.deleteTodo(sub.id)) {
    toast.success(t('tasksPanel.toast.subtaskDeleted'))
  }
}

defineExpose({ startAdd })
</script>

<template>
  <div class="space-y-1.5">
    <div
      v-for="sub in subtasks"
      :key="sub.id"
      class="flex items-center gap-2 p-2 rounded-lg bg-surface-50 dark:bg-surface-700/40 group/sub"
      :class="{ 'opacity-60': sub.completed }"
    >
      <button
        type="button"
        class="w-4 h-4 rounded border-2 flex items-center justify-center shrink-0 transition-colors"
        :class="sub.completed
          ? 'bg-primary-500 border-primary-500 text-white'
          : 'border-surface-400 dark:border-surface-500 hover:border-primary-500'"
        @click="toggleSubtask(sub)"
      >
        <span v-if="sub.completed" class="material-symbols-rounded text-[12px]">check</span>
      </button>

      <input
        v-if="editingId === sub.id"
        v-model="editTitle"
        type="text"
        class="flex-1 px-2 py-0.5 bg-white dark:bg-surface-600 border border-surface-300 dark:border-surface-500 rounded text-xs text-surface-900 dark:text-surface-100 outline-none"
        @keydown="(e) => onEditKey(e, sub)"
        @blur="saveEdit(sub)"
        autofocus
      />
      <p
        v-else
        class="flex-1 text-xs cursor-text"
        :class="sub.completed
          ? 'text-surface-400 dark:text-surface-500 line-through'
          : 'text-surface-700 dark:text-surface-200'"
        @dblclick="startEdit(sub)"
      >
        {{ sub.title }}
      </p>

      <div class="flex items-center gap-0.5 opacity-0 group-hover/sub:opacity-100 transition-opacity">
        <button
          v-if="editingId !== sub.id"
          type="button"
          class="p-1 rounded hover:bg-surface-200 dark:hover:bg-surface-600"
          @click="startEdit(sub)"
        >
          <span class="material-symbols-rounded text-[14px] text-surface-500">edit</span>
        </button>
        <button
          type="button"
          class="p-1 rounded hover:bg-red-500/20"
          @click="deleteSubtask(sub)"
        >
          <span class="material-symbols-rounded text-[14px] text-red-500">delete</span>
        </button>
      </div>
    </div>

    <div
      v-if="adding"
      class="flex items-center gap-2 p-2 rounded-lg bg-primary-500/10 border border-primary-500/30"
    >
      <span class="material-symbols-rounded text-sm text-primary-500">add</span>
      <input
        v-model="newTitle"
        type="text"
        :placeholder="t('tasksPanel.subtasks.placeholder')"
        class="flex-1 bg-transparent text-xs outline-none text-surface-800 dark:text-surface-200 placeholder:text-surface-500"
        @keydown="onAddKey"
        @blur="saveNew"
        autofocus
      />
      <button
        type="button"
        class="p-1 rounded hover:bg-surface-200 dark:hover:bg-surface-700"
        @click="cancelAdd"
      >
        <span class="material-symbols-rounded text-sm text-surface-500">close</span>
      </button>
    </div>

    <button
      v-else-if="!parentTodo.completed"
      type="button"
      class="flex items-center gap-2 w-full p-2 rounded-lg text-xs text-surface-500 hover:bg-surface-100 dark:hover:bg-surface-700 transition-colors"
      @click="startAdd"
    >
      <span class="material-symbols-rounded text-sm">add</span>
      {{ t('tasksPanel.subtasks.add') }}
    </button>
  </div>
</template>
