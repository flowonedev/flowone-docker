<script setup>
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { formatDueDate, formatRelativeAgo } from '@/addons/tasks/utils/todoDateFormat'
import TodoCardMenu from './TodoCardMenu.vue'

const props = defineProps({
  todo: { type: Object, required: true },
  expanded: { type: Boolean, default: false }
})

const emit = defineEmits([
  'toggle',
  'toggle-expand',
  'edit',
  'add-subtask',
  'convert',
  'delete',
  'open-email',
  'set-priority'
])

const { t } = useI18n()

// Map todo.priority -> Tailwind class fragments. Mirrors the legacy
// `priorityConfig` used elsewhere in the tasks addon so all surfaces stay in
// sync.
const PRIORITY = {
  high: {
    label: 'High',
    pill: 'bg-red-100 dark:bg-red-900/30 text-red-600 dark:text-red-400',
    box: 'border-red-400 hover:border-red-500'
  },
  normal: {
    label: 'Medium',
    pill: 'bg-amber-100 dark:bg-amber-900/30 text-amber-600 dark:text-amber-400',
    box: 'border-amber-400 hover:border-amber-500'
  },
  low: {
    label: 'Low',
    pill: 'bg-blue-100 dark:bg-blue-900/30 text-blue-600 dark:text-blue-400',
    box: 'border-blue-400 hover:border-blue-500'
  }
}

const priority = computed(() => PRIORITY[props.todo.priority] || PRIORITY.normal)

const due = computed(() => formatDueDate(props.todo.due_date))

const dueTone = computed(() => {
  if (!due.value) return ''
  switch (due.value.tone) {
    case 'overdue':
      return 'bg-red-50 dark:bg-red-900/20 text-red-600 dark:text-red-400'
    case 'today':
      return 'bg-amber-50 dark:bg-amber-900/20 text-amber-600 dark:text-amber-400'
    case 'soon':
      return 'bg-blue-50 dark:bg-blue-900/20 text-blue-600 dark:text-blue-400'
    default:
      return 'bg-surface-100 dark:bg-surface-700 text-surface-500 dark:text-surface-400'
  }
})

const completedAgo = computed(() => {
  if (!props.todo.completed_at) return ''
  return formatRelativeAgo(props.todo.completed_at)
})

const subtaskProgress = computed(() => {
  const subs = props.todo.subtodos || []
  if (subs.length === 0) return null
  const done = subs.filter(s => s.completed).length
  return { done, total: subs.length }
})

const hasEmailRef = computed(() => Boolean(props.todo.ref_message_id || props.todo.ref_uid))
</script>

<template>
  <article
    class="relative bg-white dark:bg-surface-800 rounded-xl border border-surface-200 dark:border-surface-700 shadow-sm transition-all"
    :class="{
      'ring-1 ring-primary-500/30 border-primary-300 dark:border-primary-500/40': expanded && !todo.completed,
      'opacity-80': todo.completed
    }"
  >
    <div class="p-3">
      <div class="flex items-start gap-3">
        <button
          type="button"
          class="mt-0.5 w-5 h-5 rounded-full border-2 flex items-center justify-center shrink-0 transition-all"
          :class="todo.completed
            ? 'bg-emerald-500 border-emerald-500 text-white'
            : priority.box"
          :aria-label="todo.completed ? t('tasksPanel.card.markIncomplete') : t('tasksPanel.card.markComplete')"
          @click.stop="emit('toggle')"
        >
          <span v-if="todo.completed" class="material-symbols-rounded text-[14px]">check</span>
        </button>

        <div class="flex-1 min-w-0">
          <h3
            class="text-sm font-medium leading-snug truncate cursor-pointer"
            :class="todo.completed
              ? 'text-surface-400 dark:text-surface-500 line-through'
              : 'text-surface-900 dark:text-surface-100'"
            @click="emit('toggle-expand')"
          >
            {{ todo.title }}
          </h3>

          <div v-if="!todo.completed" class="mt-1.5 flex items-center gap-1.5 flex-wrap">
            <span
              v-if="due"
              class="inline-flex items-center gap-1 px-1.5 py-0.5 text-[11px] font-medium rounded-md"
              :class="dueTone"
            >
              <span class="material-symbols-rounded text-[12px] leading-none">event</span>
              {{ due.text }}
            </span>
            <span
              class="px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide rounded-full"
              :class="priority.pill"
            >
              {{ t(`tasksPanel.priority.${todo.priority || 'normal'}`) }}
            </span>
            <span
              v-if="subtaskProgress"
              class="inline-flex items-center gap-1 px-1.5 py-0.5 text-[11px] font-medium rounded-md bg-surface-100 dark:bg-surface-700 text-surface-500 dark:text-surface-400"
            >
              <span class="material-symbols-rounded text-[12px] leading-none">checklist</span>
              {{ subtaskProgress.done }}/{{ subtaskProgress.total }}
            </span>
          </div>

          <p v-else class="mt-1 text-[11px] text-surface-500 dark:text-surface-500">
            {{ t('tasksPanel.card.completedAgo', { time: completedAgo }) }}
          </p>
        </div>

        <TodoCardMenu
          :completed="todo.completed"
          :has-email-ref="hasEmailRef"
          @edit="emit('edit')"
          @add-subtask="emit('add-subtask')"
          @convert="emit('convert')"
          @delete="emit('delete')"
          @open-email="emit('open-email')"
          @set-priority="(p) => emit('set-priority', p)"
        />
      </div>

      <div v-if="expanded" class="mt-3 pt-3 border-t border-surface-100 dark:border-surface-700">
        <slot name="expanded" />
      </div>
    </div>
  </article>
</template>
