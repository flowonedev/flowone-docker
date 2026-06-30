import { computed, unref } from 'vue'
import { isToday, isOverdue, isUpcoming } from '@/addons/tasks/utils/todoDateFormat'

/**
 * Splits a flat array of todos into the four buckets the redesigned panel
 * renders: today, overdue, upcoming, completed.
 *
 * Rules:
 *   - completed = todo.completed === true
 *   - overdue   = !completed && due_date strictly before today
 *   - today     = !completed && (due_date is today OR no due_date set)
 *   - upcoming  = !completed && due_date strictly after today
 *
 * Treating "no due date" as "do today" keeps freshly captured tasks visible
 * immediately instead of orphaning them into an unscheduled limbo.
 *
 * @param {import('vue').Ref<Array>|Array} todosSource
 * @returns {{
 *   today: import('vue').ComputedRef,
 *   overdue: import('vue').ComputedRef,
 *   upcoming: import('vue').ComputedRef,
 *   completed: import('vue').ComputedRef,
 *   completedTodayCount: import('vue').ComputedRef<number>,
 *   totalTodayCount: import('vue').ComputedRef<number>,
 *   progressPercent: import('vue').ComputedRef<number>,
 * }}
 */
export function useTodoGroups(todosSource) {
  const list = computed(() => unref(todosSource) || [])

  const overdue = computed(() =>
    list.value.filter(t => !t.completed && isOverdue(t.due_date))
  )

  const today = computed(() =>
    list.value.filter(t => !t.completed && (!t.due_date || isToday(t.due_date)))
  )

  const upcoming = computed(() =>
    list.value.filter(t => !t.completed && isUpcoming(t.due_date))
  )

  const completed = computed(() => list.value.filter(t => t.completed))

  // "Today" progress ring covers items that are due today (or unscheduled) plus
  // anything completed_at today, so finishing a task immediately bumps the ring.
  const completedTodayCount = computed(() =>
    list.value.filter(t => t.completed && isToday(t.completed_at)).length
  )

  const totalTodayCount = computed(() => today.value.length + completedTodayCount.value)

  const progressPercent = computed(() => {
    const total = totalTodayCount.value
    if (!total) return 0
    return Math.round((completedTodayCount.value / total) * 100)
  })

  return {
    today,
    overdue,
    upcoming,
    completed,
    completedTodayCount,
    totalTodayCount,
    progressPercent
  }
}
