<script setup>
import { computed } from 'vue'
import { useRouter } from 'vue-router'

const props = defineProps({
  item: { type: Object, required: true }
})

const emit = defineEmits(['toggle', 'open-email', 'open-card'])
const router = useRouter()

const priorityConfig = {
  high: { label: 'High', bgClass: 'bg-red-100 dark:bg-red-900/30', textClass: 'text-red-600 dark:text-red-400' },
  normal: { label: 'Medium', bgClass: 'bg-amber-100 dark:bg-amber-900/30', textClass: 'text-amber-600 dark:text-amber-400' },
  low: { label: 'Low', bgClass: 'bg-blue-100 dark:bg-blue-900/30', textClass: 'text-blue-600 dark:text-blue-400' }
}

const pConfig = computed(() => priorityConfig[props.item.priority] || priorityConfig.normal)

const dueInfo = computed(() => {
  if (!props.item.dueDate) return null
  const now = new Date()
  const today = new Date(now.getFullYear(), now.getMonth(), now.getDate())
  const due = new Date(props.item.dueDate)
  const dueDay = new Date(due.getFullYear(), due.getMonth(), due.getDate())
  const diff = Math.round((dueDay - today) / (1000 * 60 * 60 * 24))

  if (diff < 0) return { text: 'Overdue', class: 'bg-red-500/20 text-red-600 dark:text-red-400' }
  if (diff === 0) return { text: 'Today', class: 'bg-amber-500/20 text-amber-600 dark:text-amber-400' }
  if (diff === 1) return { text: 'Tomorrow', class: 'bg-amber-500/20 text-amber-600 dark:text-amber-400' }
  if (diff < 7) return { text: due.toLocaleDateString([], { weekday: 'short' }), class: 'bg-blue-500/20 text-blue-600 dark:text-blue-400' }
  return { text: due.toLocaleDateString([], { month: 'short', day: 'numeric' }), class: 'bg-surface-500/20 text-surface-600 dark:text-surface-400' }
})

const checklistProgress = computed(() => {
  if (!props.item.checklists?.length) return null
  let done = 0, total = 0
  for (const cl of props.item.checklists) {
    if (cl.items) {
      done += cl.items.filter(i => i.completed).length
      total += cl.items.length
    }
  }
  if (total === 0) return null
  return { done, total, percent: Math.round((done / total) * 100) }
})

const subtodoProgress = computed(() => {
  if (!props.item.subtodos?.length) return null
  const done = props.item.subtodos.filter(s => s.completed).length
  return { done, total: props.item.subtodos.length }
})

function handleToggle() {
  emit('toggle', props.item)
}

function handleClick() {
  if (props.item.type === 'card') {
    emit('open-card', props.item)
  } else {
    emit('open-email', props.item)
  }
}

function goToBoard() {
  if (props.item.boardId) {
    router.push(`/boards/${props.item.boardId}`)
  }
}

function getSenderName(from) {
  if (!from) return null
  const match = from.match(/^([^<]+)\s*</)
  if (match) return match[1].trim()
  return from.split('@')[0]
}
</script>

<template>
  <div
    @click="handleClick"
    :class="[
      'group flex items-start gap-3 px-4 py-3 rounded-xl transition-all cursor-pointer',
      'bg-white dark:bg-surface-800 border border-surface-200 dark:border-transparent',
      'hover:shadow-md hover:border-primary-200 dark:hover:border-surface-600',
      'active:bg-surface-50 dark:active:bg-surface-700',
      item.completed && 'opacity-60'
    ]"
  >
    <!-- Checkbox -->
    <button
      @click.stop="handleToggle"
      :class="[
        'mt-0.5 w-5 h-5 rounded-lg border-2 flex items-center justify-center shrink-0 transition-all',
        item.completed
          ? 'bg-primary-500 border-primary-500 text-white'
          : item.priority === 'high' ? 'border-red-400 hover:border-red-500'
          : item.priority === 'low' ? 'border-blue-400 hover:border-blue-500'
          : 'border-amber-400 hover:border-amber-500'
      ]"
    >
      <span v-if="item.completed" class="material-symbols-rounded text-xs">check</span>
    </button>

    <!-- Content -->
    <div class="flex-1 min-w-0">
      <!-- Title row -->
      <div class="flex items-start gap-2 flex-wrap">
        <span
          :class="[
            'text-sm font-medium cursor-pointer',
            item.completed ? 'text-surface-400 dark:text-surface-500 line-through' : 'text-surface-900 dark:text-surface-100'
          ]"
          @click="handleClick"
        >
          {{ item.title }}
        </span>

        <!-- Priority badge -->
        <span
          v-if="!item.completed"
          :class="['px-2 py-0.5 text-xs font-medium rounded-full', pConfig.bgClass, pConfig.textClass]"
        >
          {{ pConfig.label }}
        </span>

        <!-- Due date badge -->
        <span
          v-if="dueInfo && !item.completed"
          :class="['px-2 py-0.5 text-xs font-medium rounded-full', dueInfo.class]"
        >
          {{ dueInfo.text }}
        </span>
      </div>

      <!-- Meta row -->
      <div class="mt-1.5 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs">
        <!-- Source type -->
        <span class="flex items-center gap-1 text-surface-400 dark:text-surface-500">
          <span class="material-symbols-rounded text-sm">{{ item.type === 'card' ? 'dashboard' : 'task_alt' }}</span>
          {{ item.type === 'card' ? 'Board Card' : 'Task' }}
        </span>

        <!-- Board / List info (cards only) -->
        <button
          v-if="item.boardName"
          @click.stop="goToBoard"
          class="flex items-center gap-1 text-primary-500 hover:text-primary-600 dark:text-primary-400 dark:hover:text-primary-300 transition-colors"
        >
          <span class="material-symbols-rounded text-sm">dashboard</span>
          <span class="truncate max-w-[140px]">{{ item.boardName }}</span>
        </button>

        <span
          v-if="item.listName"
          class="px-1.5 py-0.5 bg-surface-100 dark:bg-surface-700 text-surface-500 rounded"
        >
          {{ item.listName }}
        </span>

        <!-- Email reference (todos only) -->
        <button
          v-if="item.refSubject"
          @click.stop="$emit('open-email', item)"
          class="flex items-center gap-1 text-surface-500 hover:text-primary-500 transition-colors truncate max-w-[200px]"
        >
          <span class="material-symbols-rounded text-sm">mail</span>
          {{ item.refSubject }}
        </button>

        <!-- Labels (cards only) -->
        <div v-if="item.labels?.length" class="flex items-center gap-1">
          <span
            v-for="label in item.labels.slice(0, 3)"
            :key="label.id"
            class="w-4 h-2 rounded-full"
            :style="{ backgroundColor: label.color }"
            :title="label.name"
          ></span>
          <span v-if="item.labels.length > 3" class="text-surface-400 text-xs">+{{ item.labels.length - 3 }}</span>
        </div>

        <!-- Checklist progress (cards) -->
        <span v-if="checklistProgress" class="flex items-center gap-1 text-surface-500">
          <span class="material-symbols-rounded text-sm">checklist</span>
          {{ checklistProgress.done }}/{{ checklistProgress.total }}
        </span>

        <!-- Subtodo progress (todos) -->
        <span v-if="subtodoProgress" class="flex items-center gap-1 text-surface-500">
          <span class="material-symbols-rounded text-sm">checklist</span>
          {{ subtodoProgress.done }}/{{ subtodoProgress.total }}
        </span>

        <!-- From email (todos) -->
        <span v-if="item.refFrom && !item.refSubject" class="flex items-center gap-1 text-surface-500">
          <span class="material-symbols-rounded text-sm">person</span>
          {{ getSenderName(item.refFrom) }}
        </span>
      </div>

      <!-- Quoted text preview (todos from email) -->
      <div
        v-if="item.refSelectedText && !item.completed"
        class="mt-1.5 pl-3 border-l-2 border-primary-500/40 text-xs text-surface-500 dark:text-surface-400 italic truncate"
      >
        "{{ item.refSelectedText.length > 100 ? item.refSelectedText.substring(0, 100) + '...' : item.refSelectedText }}"
      </div>
    </div>

    <!-- Right side actions -->
    <div class="flex items-center gap-1 opacity-0 group-hover:opacity-100 transition-opacity shrink-0">
      <button
        v-if="item.type === 'card' && item.boardId"
        @click.stop="goToBoard"
        class="p-1.5 rounded-lg hover:bg-surface-100 dark:hover:bg-surface-700 text-surface-400 hover:text-primary-500 transition-colors"
        title="Open board"
      >
        <span class="material-symbols-rounded text-lg">open_in_new</span>
      </button>
      <button
        v-if="item.type === 'todo' && item.refFolder"
        @click.stop="$emit('open-email', item)"
        class="p-1.5 rounded-lg hover:bg-surface-100 dark:hover:bg-surface-700 text-surface-400 hover:text-primary-500 transition-colors"
        title="Open email"
      >
        <span class="material-symbols-rounded text-lg">mail</span>
      </button>
    </div>
  </div>
</template>
