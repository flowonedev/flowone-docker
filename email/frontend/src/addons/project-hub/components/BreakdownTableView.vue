<script setup>
import { ref, computed } from 'vue'

const props = defineProps({
  tree: { type: Array, required: true },
  groupBy: { type: String, default: 'client' },
})

const emit = defineEmits(['open-card'])

const sortCol = ref('time')
const sortDir = ref('desc')

function toggleSort(col) {
  if (sortCol.value === col) {
    sortDir.value = sortDir.value === 'asc' ? 'desc' : 'asc'
  } else {
    sortCol.value = col
    sortDir.value = 'desc'
  }
}

function sortIcon(col) {
  if (sortCol.value !== col) return 'unfold_more'
  return sortDir.value === 'asc' ? 'expand_less' : 'expand_more'
}

function sortedTasks(tasks) {
  const dir = sortDir.value === 'asc' ? 1 : -1
  return [...tasks].sort((a, b) => {
    switch (sortCol.value) {
      case 'task': return dir * (a.label || '').localeCompare(b.label || '')
      case 'sessions': return dir * (a.sessions - b.sessions)
      case 'date': return dir * ((a.lastActive || '').localeCompare(b.lastActive || ''))
      case 'time': default: return dir * (a.total - b.total)
    }
  })
}

const flatSections = computed(() => {
  const sections = []
  for (const l0 of props.tree) {
    for (const l1 of l0.children) {
      for (const l2 of l1.children) {
        sections.push({
          key: l2.key,
          l0Label: l0.label,
          l1Label: l1.label,
          l1IsActivity: l1.isActivity,
          l2Label: l2.label,
          l2Total: l2.total,
          activityTotal: l2.activityTotal || 0,
          activitySessions: l2.activitySessions || 0,
          tasks: l2.children || [],
        })
      }
    }
  }
  return sections
})

const depthIcons = {
  0: { client: 'domain', person: 'person' },
  1: { client: 'folder_open', person: 'domain' },
  2: { client: 'person', person: 'folder_open' },
}

const sourceIconMap = {
  manual: 'edit',
  drive_edit: 'description',
  board_view: 'view_kanban',
  timer: 'timer',
  card_view: 'visibility',
  website_work: 'language',
  portal_call: 'video_call',
  calendar_event: 'event',
  local_watch: 'folder_open',
}

function fileIcon(file) {
  if (file.entityName) return 'description'
  return sourceIconMap[file.source] || 'schedule'
}

function fileIconColor(file) {
  if (file.entityName) return 'text-teal-500'
  const map = {
    timer: 'text-orange-400',
    card_view: 'text-emerald-500',
    board_view: 'text-indigo-400',
    manual: 'text-blue-400',
    drive_edit: 'text-teal-500',
    website_work: 'text-cyan-500',
    portal_call: 'text-green-500',
    calendar_event: 'text-blue-500',
    local_watch: 'text-amber-500',
  }
  return map[file.source] || 'text-surface-400'
}

function fmt(seconds) {
  if (!seconds || seconds <= 0) return '0m'
  const h = Math.floor(seconds / 3600)
  const m = Math.round((seconds % 3600) / 60)
  if (h === 0) return `${m}m`
  if (m === 0) return `${h}h`
  return `${h}h ${m}m`
}

function fmtDate(dateStr) {
  if (!dateStr) return ''
  return new Date(dateStr).toLocaleDateString(undefined, { month: 'short', day: 'numeric' })
}
</script>

<template>
  <div class="space-y-4">
    <div v-for="section in flatSections" :key="section.key" class="rounded-xl border border-surface-200 dark:border-surface-700 bg-white dark:bg-surface-800 overflow-hidden">

      <!-- Section header breadcrumb -->
      <div class="px-4 py-2.5 bg-surface-50 dark:bg-surface-800/80 border-b border-surface-200 dark:border-surface-700 flex items-center gap-2 flex-wrap">
        <span class="material-symbols-rounded text-base text-primary-500">{{ depthIcons[0][groupBy] }}</span>
        <span class="text-xs font-semibold text-surface-700 dark:text-surface-200">{{ section.l0Label }}</span>
        <span class="material-symbols-rounded text-xs text-surface-300">chevron_right</span>
        <span class="material-symbols-rounded text-base" :class="section.l1IsActivity ? 'text-amber-500' : 'text-surface-400'">{{ depthIcons[1][groupBy] }}</span>
        <span class="text-xs font-medium text-surface-600 dark:text-surface-300">{{ section.l1Label }}</span>
        <span class="material-symbols-rounded text-xs text-surface-300">chevron_right</span>
        <span class="material-symbols-rounded text-base text-surface-400">{{ depthIcons[2][groupBy] }}</span>
        <span class="text-xs font-medium text-surface-600 dark:text-surface-300">{{ section.l2Label }}</span>
        <span class="ml-auto text-sm font-bold text-primary-600 dark:text-primary-400 tabular-nums">{{ fmt(section.l2Total) }}</span>
      </div>

      <!-- Table -->
      <table class="w-full text-left">
        <thead>
          <tr class="border-b border-surface-100 dark:border-surface-700/50 text-[11px] uppercase tracking-wider text-surface-400">
            <th class="pl-4 pr-2 py-2 font-semibold cursor-pointer select-none hover:text-surface-600 dark:hover:text-surface-300 transition-colors w-[220px] min-w-[180px]" @click="toggleSort('task')">
              <span class="inline-flex items-center gap-1">Task <span class="material-symbols-rounded text-xs">{{ sortIcon('task') }}</span></span>
            </th>
            <th class="px-2 py-2 font-semibold">Activity</th>
            <th class="px-2 py-2 font-semibold cursor-pointer select-none hover:text-surface-600 dark:hover:text-surface-300 transition-colors text-right w-20" @click="toggleSort('sessions')">
              <span class="inline-flex items-center gap-1 justify-end">Sessions <span class="material-symbols-rounded text-xs">{{ sortIcon('sessions') }}</span></span>
            </th>
            <th class="px-2 py-2 font-semibold cursor-pointer select-none hover:text-surface-600 dark:hover:text-surface-300 transition-colors text-right hidden md:table-cell w-24" @click="toggleSort('date')">
              <span class="inline-flex items-center gap-1 justify-end">Last Active <span class="material-symbols-rounded text-xs">{{ sortIcon('date') }}</span></span>
            </th>
            <th class="px-2 py-2 font-semibold cursor-pointer select-none hover:text-surface-600 dark:hover:text-surface-300 transition-colors text-right w-20" @click="toggleSort('time')">
              <span class="inline-flex items-center gap-1 justify-end">Time <span class="material-symbols-rounded text-xs">{{ sortIcon('time') }}</span></span>
            </th>
            <th class="w-8"></th>
          </tr>
        </thead>
        <tbody>
          <!-- Activity row (non-card time) -->
          <tr v-if="section.activityTotal" class="border-b border-surface-50 dark:border-surface-700/30 bg-amber-50/30 dark:bg-amber-900/5">
            <td class="pl-4 pr-2 py-2.5 text-sm text-surface-500 dark:text-surface-400 italic">Tracked activity</td>
            <td class="px-2 py-2.5">
              <div class="flex items-center gap-2">
                <span class="material-symbols-rounded text-sm text-amber-400">schedule</span>
                <span class="text-xs text-surface-500">General activity</span>
                <span class="text-xs font-medium text-surface-600 dark:text-surface-300 tabular-nums ml-auto">{{ fmt(section.activityTotal) }}</span>
              </div>
            </td>
            <td class="px-2 py-2.5 text-xs text-surface-400 tabular-nums text-right">{{ section.activitySessions }}</td>
            <td class="px-2 py-2.5 hidden md:table-cell"></td>
            <td class="px-2 py-2.5 text-sm font-semibold text-surface-700 dark:text-surface-200 tabular-nums text-right">{{ fmt(section.activityTotal) }}</td>
            <td></td>
          </tr>

          <!-- Task rows -->
          <tr
            v-for="task in sortedTasks(section.tasks)" :key="task.key"
            class="border-b border-surface-50 dark:border-surface-700/30 hover:bg-surface-50 dark:hover:bg-surface-750 transition-colors group align-top"
          >
            <!-- Task name -->
            <td class="pl-4 pr-2 py-2.5 w-[220px] min-w-[180px]">
              <div class="flex items-start gap-2 pt-0.5">
                <span class="material-symbols-rounded text-sm text-surface-300 group-hover:text-primary-500 shrink-0 mt-0.5">task_alt</span>
                <span class="text-sm text-surface-700 dark:text-surface-200 group-hover:text-primary-600 dark:group-hover:text-primary-300 line-clamp-2 leading-tight">{{ task.label }}</span>
              </div>
            </td>

            <!-- Activity: clean vertical list -->
            <td class="px-2 py-2">
              <div class="space-y-0.5">
                <div
                  v-for="file in task.files" :key="file.key"
                  class="flex items-center gap-2 py-0.5"
                >
                  <span class="material-symbols-rounded text-sm shrink-0" :class="fileIconColor(file)">{{ fileIcon(file) }}</span>
                  <span class="text-xs text-surface-600 dark:text-surface-300 truncate min-w-0 flex-1">{{ file.label }}</span>
                  <span class="text-[11px] text-surface-400 tabular-nums shrink-0 hidden sm:inline">{{ file.sessions }}x</span>
                  <span class="text-xs font-medium text-surface-600 dark:text-surface-300 tabular-nums shrink-0 w-14 text-right">{{ fmt(file.total) }}</span>
                </div>
              </div>
            </td>

            <!-- Sessions -->
            <td class="px-2 py-2.5 text-xs text-surface-400 tabular-nums text-right whitespace-nowrap">{{ task.sessions }}</td>
            <!-- Last active -->
            <td class="px-2 py-2.5 text-xs text-surface-400 tabular-nums text-right hidden md:table-cell whitespace-nowrap">{{ fmtDate(task.lastActive) }}</td>
            <!-- Total time -->
            <td class="px-2 py-2.5 text-sm font-semibold text-surface-700 dark:text-surface-200 tabular-nums text-right whitespace-nowrap">{{ fmt(task.total) }}</td>
            <!-- Open card -->
            <td class="pr-3 py-2.5">
              <button
                v-if="task.cardId"
                class="p-1 rounded hover:bg-primary-100 dark:hover:bg-primary-500/20 transition-colors opacity-0 group-hover:opacity-100"
                @click="emit('open-card', task.boardId, task.cardId)"
                title="Open card"
              >
                <span class="material-symbols-rounded text-sm text-surface-400 hover:text-primary-500">open_in_new</span>
              </button>
            </td>
          </tr>
        </tbody>
      </table>

      <div v-if="section.tasks.length === 0 && !section.activityTotal" class="px-4 py-6 text-center text-sm text-surface-400">
        No tasks tracked
      </div>
    </div>

    <div v-if="flatSections.length === 0" class="text-center py-16">
      <span class="material-symbols-rounded text-5xl text-surface-300 dark:text-surface-600 mb-3 block">hourglass_empty</span>
      <p class="text-surface-500">No time tracked for this period.</p>
    </div>
  </div>
</template>
