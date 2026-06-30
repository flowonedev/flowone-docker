<script setup>
import { ref } from 'vue'
import { useRouter } from 'vue-router'

const props = defineProps({
  groups: { type: Array, required: true },
  collapsedGroups: { type: Set, default: () => new Set() }
})

const emit = defineEmits(['toggle', 'open', 'toggle-group'])
const router = useRouter()

const sortCol = ref(null)
const sortDir = ref('asc')

const priorityConfig = {
  high: { label: 'High', dotClass: 'bg-red-500', pillBg: 'bg-red-100 dark:bg-red-900/30', pillText: 'text-red-600 dark:text-red-400' },
  normal: { label: 'Medium', dotClass: 'bg-amber-500', pillBg: 'bg-amber-100 dark:bg-amber-900/30', pillText: 'text-amber-600 dark:text-amber-400' },
  low: { label: 'Low', dotClass: 'bg-blue-500', pillBg: 'bg-blue-100 dark:bg-blue-900/30', pillText: 'text-blue-600 dark:text-blue-400' }
}

function getDueInfo(dateStr) {
  if (!dateStr) return null
  const now = new Date()
  const today = new Date(now.getFullYear(), now.getMonth(), now.getDate())
  const due = new Date(dateStr)
  const dueDay = new Date(due.getFullYear(), due.getMonth(), due.getDate())
  const diff = Math.round((dueDay - today) / (1000 * 60 * 60 * 24))
  if (diff < 0) return { text: 'Overdue', class: 'text-red-500 font-medium' }
  if (diff === 0) return { text: 'Today', class: 'text-amber-500 font-medium' }
  if (diff === 1) return { text: 'Tomorrow', class: 'text-amber-400' }
  if (diff < 7) return { text: due.toLocaleDateString([], { weekday: 'short' }), class: 'text-blue-500' }
  return { text: due.toLocaleDateString([], { month: 'short', day: 'numeric' }), class: 'text-surface-500' }
}

function sortItems(items) {
  if (!sortCol.value) return items
  const sorted = [...items]
  const dir = sortDir.value === 'asc' ? 1 : -1
  sorted.sort((a, b) => {
    let va, vb
    switch (sortCol.value) {
      case 'title':
        return dir * a.title.localeCompare(b.title)
      case 'priority': {
        const order = { high: 0, normal: 1, low: 2 }
        va = order[a.priority] ?? 1
        vb = order[b.priority] ?? 1
        return dir * (va - vb)
      }
      case 'due': {
        va = a.dueDate ? new Date(a.dueDate).getTime() : Infinity
        vb = b.dueDate ? new Date(b.dueDate).getTime() : Infinity
        return dir * (va - vb)
      }
      case 'type':
        return dir * a.type.localeCompare(b.type)
      case 'board':
        va = a.boardName || ''
        vb = b.boardName || ''
        return dir * va.localeCompare(vb)
      case 'status':
        va = a.listName || ''
        vb = b.listName || ''
        return dir * va.localeCompare(vb)
      default:
        return 0
    }
  })
  return sorted
}

function toggleSort(col) {
  if (sortCol.value === col) {
    sortDir.value = sortDir.value === 'asc' ? 'desc' : 'asc'
  } else {
    sortCol.value = col
    sortDir.value = 'asc'
  }
}

function getProgress(item) {
  if (item.type === 'todo' && item.subtodos?.length) {
    const done = item.subtodos.filter(s => s.completed).length
    return { done, total: item.subtodos.length, percent: Math.round((done / item.subtodos.length) * 100) }
  }
  if (item.type === 'card' && item.checklists?.length) {
    let done = 0, total = 0
    for (const cl of item.checklists) {
      if (cl.items) {
        done += cl.items.filter(i => i.completed).length
        total += cl.items.length
      }
    }
    if (total > 0) return { done, total, percent: Math.round((done / total) * 100) }
  }
  return null
}

function goToBoard(item, e) {
  e.stopPropagation()
  if (item.boardId) router.push(`/boards/${item.boardId}`)
}

function goToDrive(item, e) {
  e.stopPropagation()
  if (item.boardDriveFolderId) router.push(`/drive/folder/${item.boardDriveFolderId}`)
}

const columns = [
  { key: 'title', label: 'Task' },
  { key: 'type', label: 'Source' },
  { key: 'priority', label: 'Priority' },
  { key: 'progress', label: 'Progress', sortable: false },
  { key: 'due', label: 'Due Date' },
  { key: 'board', label: 'Board / Project' },
  { key: 'status', label: 'Status' },
  { key: 'links', label: 'Links', sortable: false }
]
</script>

<template>
  <div class="bg-white dark:bg-[rgb(var(--color-surface))] rounded-xl border border-surface-200 dark:border-surface-700 overflow-hidden">
    <div class="overflow-x-auto">
      <table class="w-full text-sm">
        <thead>
          <tr class="border-b border-surface-200 dark:border-surface-700 bg-surface-50 dark:bg-surface-800/50">
            <th class="w-10 px-3 py-3"></th>
            <th
              v-for="col in columns"
              :key="col.key"
              @click="col.sortable !== false && toggleSort(col.key)"
              :class="[
                'px-4 py-3 text-left text-xs font-semibold text-surface-600 dark:text-surface-400 uppercase tracking-wide whitespace-nowrap select-none',
                col.sortable !== false ? 'cursor-pointer hover:bg-surface-100 dark:hover:bg-surface-700 transition-colors' : ''
              ]"
            >
              <span class="flex items-center gap-1">
                {{ col.label }}
                <span v-if="sortCol === col.key" class="material-symbols-rounded text-xs text-primary-500">
                  {{ sortDir === 'asc' ? 'arrow_upward' : 'arrow_downward' }}
                </span>
              </span>
            </th>
            <th class="w-10 px-3 py-3"></th>
          </tr>
        </thead>
        <tbody>
          <template v-for="group in groups" :key="group.key">
            <!-- Group header row -->
            <tr
              @click="$emit('toggle-group', group.key)"
              class="bg-surface-50/70 dark:bg-surface-800/30 cursor-pointer hover:bg-surface-100 dark:hover:bg-surface-800/50 transition-colors"
            >
              <td :colspan="columns.length + 2" class="px-4 py-2.5">
                <div class="flex items-center gap-2">
                  <span
                    class="material-symbols-rounded text-base transition-transform"
                    :class="[collapsedGroups.has(group.key) ? '-rotate-90' : '', group.colorClass || 'text-surface-500']"
                  >expand_more</span>
                  <span :class="['material-symbols-rounded text-base', group.colorClass || 'text-surface-500']">{{ group.icon }}</span>
                  <span class="text-xs font-semibold text-surface-900 dark:text-surface-100">{{ group.label }}</span>
                  <span class="px-1.5 py-0.5 text-xs font-medium bg-surface-200 dark:bg-surface-700 text-surface-600 dark:text-surface-400 rounded-full">{{ group.items.length }}</span>
                  <div class="flex-1 h-px bg-surface-200 dark:bg-surface-700 ml-2"></div>
                </div>
              </td>
            </tr>

            <!-- Item rows -->
            <template v-if="!collapsedGroups.has(group.key)">
              <tr
                v-for="item in sortItems(group.items)"
                :key="item.id"
                @click="$emit('open', item)"
                :class="[
                  'border-b border-surface-100 dark:border-surface-800 hover:bg-surface-50 dark:hover:bg-surface-800/30 cursor-pointer transition-colors',
                  item.completed ? 'opacity-50' : ''
                ]"
              >
                <!-- Checkbox -->
                <td class="px-3 py-3">
                  <button
                    @click.stop="$emit('toggle', item)"
                    :class="[
                      'w-5 h-5 rounded-md border-2 flex items-center justify-center shrink-0 transition-all',
                      item.completed
                        ? 'bg-primary-500 border-primary-500 text-white'
                        : item.priority === 'high' ? 'border-red-400 hover:border-red-500'
                        : item.priority === 'low' ? 'border-blue-400 hover:border-blue-500'
                        : 'border-amber-400 hover:border-amber-500'
                    ]"
                  >
                    <span v-if="item.completed" class="material-symbols-rounded text-xs">check</span>
                  </button>
                </td>

                <!-- Task Title -->
                <td class="px-4 py-3">
                  <div class="flex items-center gap-2 min-w-0">
                    <div class="min-w-0">
                      <p :class="['font-medium truncate max-w-[300px]', item.completed ? 'text-surface-400 line-through' : 'text-surface-900 dark:text-surface-100']">{{ item.title }}</p>
                      <p v-if="item.refSubject" class="text-xs text-surface-400 truncate max-w-[250px] mt-0.5">
                        <span class="material-symbols-rounded text-xs align-middle">mail</span>
                        {{ item.refSubject }}
                      </p>
                    </div>
                  </div>
                </td>

                <!-- Source Type -->
                <td class="px-4 py-3">
                  <span class="flex items-center gap-1.5 text-surface-500 whitespace-nowrap">
                    <span class="material-symbols-rounded text-sm">{{ item.type === 'card' ? 'dashboard' : 'task_alt' }}</span>
                    {{ item.type === 'card' ? 'Card' : 'Task' }}
                  </span>
                </td>

                <!-- Priority -->
                <td class="px-4 py-3">
                  <span
                    :class="[
                      'px-2 py-0.5 rounded-full text-xs font-medium whitespace-nowrap',
                      (priorityConfig[item.priority] || priorityConfig.normal).pillBg,
                      (priorityConfig[item.priority] || priorityConfig.normal).pillText
                    ]"
                  >
                    {{ (priorityConfig[item.priority] || priorityConfig.normal).label }}
                  </span>
                </td>

                <!-- Progress -->
                <td class="px-4 py-3">
                  <div v-if="getProgress(item)" class="flex items-center gap-2">
                    <span class="text-surface-900 dark:text-surface-100 font-medium text-xs">{{ getProgress(item).done }}/{{ getProgress(item).total }}</span>
                    <div class="flex-1 max-w-[60px] h-1.5 bg-surface-200 dark:bg-surface-700 rounded-full overflow-hidden">
                      <div class="h-full bg-primary-500 rounded-full transition-all" :style="{ width: getProgress(item).percent + '%' }"></div>
                    </div>
                    <span class="text-xs text-surface-400">{{ getProgress(item).percent }}%</span>
                  </div>
                  <span v-else class="text-xs text-surface-300 dark:text-surface-600">&mdash;</span>
                </td>

                <!-- Due Date -->
                <td class="px-4 py-3">
                  <span v-if="getDueInfo(item.dueDate)" :class="['text-xs whitespace-nowrap', getDueInfo(item.dueDate).class]">
                    {{ getDueInfo(item.dueDate).text }}
                  </span>
                  <span v-else class="text-xs text-surface-300 dark:text-surface-600">&mdash;</span>
                </td>

                <!-- Board / Project -->
                <td class="px-4 py-3">
                  <button
                    v-if="item.boardName"
                    @click="goToBoard(item, $event)"
                    class="text-xs text-primary-500 hover:text-primary-600 font-medium truncate max-w-[120px] block transition-colors"
                  >{{ item.boardName }}</button>
                  <span v-else class="text-xs text-surface-400">{{ item.type === 'todo' ? 'Personal' : '' }}</span>
                </td>

                <!-- Status / List -->
                <td class="px-4 py-3">
                  <span v-if="item.listName" class="px-2 py-0.5 rounded-full text-xs font-medium bg-surface-100 dark:bg-surface-700 text-surface-600 dark:text-surface-400 whitespace-nowrap">
                    {{ item.listName }}
                  </span>
                  <span v-else class="text-xs text-surface-300 dark:text-surface-600">&mdash;</span>
                </td>

                <!-- Links -->
                <td class="px-4 py-3">
                  <div class="flex items-center gap-1">
                    <button
                      v-if="item.boardId"
                      @click="goToBoard(item, $event)"
                      class="p-1 text-surface-400 hover:text-primary-500 transition-colors rounded"
                      title="Open board"
                    >
                      <span class="material-symbols-rounded text-base">dashboard</span>
                    </button>
                    <button
                      v-if="item.boardDriveFolderId"
                      @click="goToDrive(item, $event)"
                      class="p-1 text-surface-400 hover:text-amber-500 transition-colors rounded"
                      title="Open drive folder"
                    >
                      <span class="material-symbols-rounded text-base">folder_open</span>
                    </button>
                    <span v-if="!item.boardId && !item.boardDriveFolderId" class="text-xs text-surface-300 dark:text-surface-600">&mdash;</span>
                  </div>
                </td>

                <!-- Open action -->
                <td class="px-3 py-3">
                  <span class="material-symbols-rounded text-sm text-surface-400 hover:text-primary-500 transition-colors">open_in_new</span>
                </td>
              </tr>
            </template>
          </template>
        </tbody>
      </table>
    </div>
  </div>
</template>
