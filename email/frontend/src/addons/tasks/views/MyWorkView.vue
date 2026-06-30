<script setup>
import { ref, watch, onMounted, onUnmounted, computed } from 'vue'
import { useMyWorkStore } from '@/addons/tasks/stores/myWork'
import { useTodosStore } from '@/addons/tasks/stores/todos'
import { useBoardsStore } from '@/addons/kanban-boards/stores/boards'
import { useToastStore } from '@/stores/toast'
import AppHeader from '@/components/shared/AppHeader.vue'
import MobileBottomNav from '@/components/MobileBottomNav.vue'
import MyWorkCard from '@/addons/tasks/components/MyWorkCard.vue'
import MyWorkTable from '@/addons/tasks/components/MyWorkTable.vue'
import MyWorkDetailPanel from '@/addons/tasks/components/MyWorkDetailPanel.vue'

const myWorkStore = useMyWorkStore()
const todosStore = useTodosStore()
const boardsStore = useBoardsStore()
const toast = useToastStore()

const isMobile = ref(false)
const showGroupDropdown = ref(false)
const showFilterDropdown = ref(false)
const collapsedGroups = ref(new Set())
const newTodoTitle = ref('')
const showAddTask = ref(false)
const selectedItemId = ref(null)
const selectedItem = computed(() => {
  if (!selectedItemId.value) return null
  return myWorkStore.findItem(selectedItemId.value) || null
})

function checkMobile() {
  isMobile.value = window.innerWidth < 768
}

const groupOptions = [
  { value: 'date', label: 'Date', icon: 'calendar_today' },
  { value: 'board', label: 'Board / Project', icon: 'dashboard' },
  { value: 'priority', label: 'Priority', icon: 'flag' },
  { value: 'status', label: 'Status', icon: 'view_kanban' }
]

const priorityOptions = [
  { value: 'all', label: 'All', activeClass: 'bg-surface-800 dark:bg-surface-100 text-white dark:text-surface-900', inactiveClass: 'bg-surface-50 dark:bg-surface-600 text-surface-600 dark:text-surface-400 hover:bg-surface-100 dark:hover:bg-surface-500' },
  { value: 'high', label: 'High', activeClass: 'bg-red-500 text-white shadow-sm', inactiveClass: 'bg-surface-50 dark:bg-surface-600 text-red-600 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-900/20' },
  { value: 'normal', label: 'Medium', activeClass: 'bg-amber-500 text-white shadow-sm', inactiveClass: 'bg-surface-50 dark:bg-surface-600 text-amber-600 dark:text-amber-400 hover:bg-amber-50 dark:hover:bg-amber-900/20' },
  { value: 'low', label: 'Low', activeClass: 'bg-blue-500 text-white shadow-sm', inactiveClass: 'bg-surface-50 dark:bg-surface-600 text-blue-600 dark:text-blue-400 hover:bg-blue-50 dark:hover:bg-blue-900/20' }
]

const sourceOptions = [
  { value: 'all', label: 'All', icon: null },
  { value: 'todos', label: 'Tasks', icon: 'task_alt' },
  { value: 'cards', label: 'Cards', icon: 'dashboard' }
]

const hasActiveFilters = computed(() => myWorkStore.filterPriority !== 'all' || myWorkStore.filterSource !== 'all')
const activeFilterCount = computed(() => (myWorkStore.filterPriority !== 'all' ? 1 : 0) + (myWorkStore.filterSource !== 'all' ? 1 : 0))

function clearAllFilters() {
  myWorkStore.setFilterPriority('all')
  myWorkStore.setFilterSource('all')
}

const currentGroupLabel = computed(() => {
  return groupOptions.find(o => o.value === myWorkStore.groupBy)?.label || 'Date'
})

function selectGroup(value) {
  myWorkStore.setGroupBy(value)
  showGroupDropdown.value = false
  collapsedGroups.value = new Set()
}

function toggleGroup(key) {
  if (collapsedGroups.value.has(key)) {
    collapsedGroups.value.delete(key)
  } else {
    collapsedGroups.value.add(key)
  }
  collapsedGroups.value = new Set(collapsedGroups.value)
}

function isGroupCollapsed(key) {
  return collapsedGroups.value.has(key)
}

function handleToggle(item) {
  const newCompleted = !item.completed
  myWorkStore.updateItemLocally(item.id, {
    completed: newCompleted,
    completedAt: newCompleted ? new Date().toISOString() : null
  })
  const save = item.type === 'todo'
    ? todosStore.toggleTodo(item.rawId)
    : boardsStore.updateCard(item.rawId, { completed: newCompleted })
  myWorkStore.trackSave(save)
}

function handleOpenItem(item) {
  selectedItemId.value = item.id
}

function handleClosePanel() {
  selectedItemId.value = null
}

function handleDeleteItem(item) {
  myWorkStore.removeItemLocally(item.id)
  selectedItemId.value = null
  toast.success('Deleted')
  const save = item.type === 'todo'
    ? todosStore.deleteTodo(item.rawId)
    : boardsStore.deleteCard(item.rawId)
  myWorkStore.trackSave(save)
}

async function createQuickTask() {
  if (!newTodoTitle.value.trim()) return
  await todosStore.createTodo({ title: newTodoTitle.value.trim() })
  newTodoTitle.value = ''
  toast.success('Task added')
  await myWorkStore.fetchMyWork()
}

function closeDropdowns(e) {
  if (!e.target.closest('.group-dropdown')) showGroupDropdown.value = false
  if (!e.target.closest('.filter-dropdown')) showFilterDropdown.value = false
}

onMounted(async () => {
  checkMobile()
  window.addEventListener('resize', checkMobile)
  document.addEventListener('click', closeDropdowns)
  await myWorkStore.fetchMyWork()
})

onUnmounted(() => {
  window.removeEventListener('resize', checkMobile)
  document.removeEventListener('click', closeDropdowns)
})

watch(() => myWorkStore.includeCompleted, () => {
  myWorkStore.fetchMyWork()
})

</script>

<template>
  <div class="h-[100dvh] flex flex-col bg-surface-50 dark:bg-surface-900 ambient-tint" :class="isMobile ? 'overflow-y-auto pb-20' : 'overflow-hidden'">
    <!-- Header -->
    <AppHeader
      current-view="my-work"
      icon="assignment_ind"
      title="My Work"
    />

    <!-- Summary Cards: horizontal scroll on mobile, grid on desktop -->
    <div v-if="isMobile" class="flex-shrink-0 px-4 py-3 overflow-x-auto -webkit-overflow-scrolling-touch">
      <div class="flex gap-2.5" style="min-width: max-content;">
        <div class="bg-white dark:bg-[rgb(var(--color-surface))] rounded-xl border border-surface-200 dark:border-surface-700 px-4 py-2.5 text-center min-w-[7rem]">
          <p class="text-xl font-bold text-surface-900 dark:text-surface-100">{{ myWorkStore.totalCount }}</p>
          <p class="text-[11px] text-surface-500">Active</p>
        </div>
        <div class="bg-white dark:bg-[rgb(var(--color-surface))] rounded-xl border-2 border-green-300 dark:border-green-700 px-4 py-2.5 text-center min-w-[7rem]">
          <p class="text-xl font-bold text-green-600 dark:text-green-400">{{ myWorkStore.completedThisWeek }}</p>
          <p class="text-[11px] text-surface-500">This Week</p>
        </div>
        <div class="bg-white dark:bg-[rgb(var(--color-surface))] rounded-xl border border-surface-200 dark:border-surface-700 px-4 py-2.5 text-center min-w-[7rem]">
          <p class="text-xl font-bold text-amber-500">{{ myWorkStore.dueTodayCount }}</p>
          <p class="text-[11px] text-surface-500">Due Today</p>
        </div>
        <div class="bg-white dark:bg-[rgb(var(--color-surface))] rounded-xl border border-surface-200 dark:border-surface-700 px-4 py-2.5 text-center min-w-[7rem]">
          <p class="text-xl font-bold text-red-500">{{ myWorkStore.overdueCount }}</p>
          <p class="text-[11px] text-surface-500">Overdue</p>
        </div>
        <div class="bg-white dark:bg-[rgb(var(--color-surface))] rounded-xl border border-surface-200 dark:border-surface-700 px-4 py-2.5 text-center min-w-[7rem]">
          <p class="text-xl font-bold text-orange-500">{{ myWorkStore.highPriorityCount }}</p>
          <p class="text-[11px] text-surface-500">High Priority</p>
        </div>
        <div class="bg-white dark:bg-[rgb(var(--color-surface))] rounded-xl border border-surface-200 dark:border-surface-700 px-4 py-2.5 text-center min-w-[7rem]">
          <p class="text-xl font-bold text-primary-500">{{ myWorkStore.completedCount }}</p>
          <p class="text-[11px] text-surface-500">Completed</p>
        </div>
      </div>
    </div>
    <div v-else class="flex-shrink-0 px-4 py-3 grid grid-cols-3 lg:grid-cols-6 gap-3">
      <div class="bg-white dark:bg-[rgb(var(--color-surface))] rounded-xl border border-surface-200 dark:border-surface-700 p-3 text-center">
        <p class="text-2xl font-bold text-surface-900 dark:text-surface-100">{{ myWorkStore.totalCount }}</p>
        <p class="text-xs text-surface-500">Active Tasks</p>
      </div>
      <div class="bg-white dark:bg-[rgb(var(--color-surface))] rounded-xl border-2 border-green-300 dark:border-green-700 p-3 text-center">
        <p class="text-2xl font-bold text-green-600 dark:text-green-400">{{ myWorkStore.completedThisWeek }}</p>
        <p class="text-xs text-surface-500">Done This Week</p>
      </div>
      <div class="bg-white dark:bg-[rgb(var(--color-surface))] rounded-xl border border-surface-200 dark:border-surface-700 p-3 text-center">
        <p class="text-2xl font-bold text-amber-500">{{ myWorkStore.dueTodayCount }}</p>
        <p class="text-xs text-surface-500">Due Today</p>
      </div>
      <div class="bg-white dark:bg-[rgb(var(--color-surface))] rounded-xl border border-surface-200 dark:border-surface-700 p-3 text-center">
        <p class="text-2xl font-bold text-red-500">{{ myWorkStore.overdueCount }}</p>
        <p class="text-xs text-surface-500">Overdue</p>
      </div>
      <div class="bg-white dark:bg-[rgb(var(--color-surface))] rounded-xl border border-surface-200 dark:border-surface-700 p-3 text-center">
        <p class="text-2xl font-bold text-orange-500">{{ myWorkStore.highPriorityCount }}</p>
        <p class="text-xs text-surface-500">High Priority</p>
      </div>
      <div class="bg-white dark:bg-[rgb(var(--color-surface))] rounded-xl border border-surface-200 dark:border-surface-700 p-3 text-center">
        <p class="text-2xl font-bold text-primary-500">{{ myWorkStore.completedCount }}</p>
        <p class="text-xs text-surface-500">Completed</p>
      </div>
    </div>

    <!-- Toolbar -->
    <div class="flex-shrink-0 px-4 pb-3">
      <div class="flex items-center gap-2 flex-wrap">
        <!-- Search -->
        <div class="relative flex-1 min-w-[200px] max-w-md">
          <span class="material-symbols-rounded text-lg text-surface-400 absolute left-3 top-1/2 -translate-y-1/2">search</span>
          <input
            v-model="myWorkStore.searchQuery"
            type="text"
            placeholder="Search tasks..."
            class="w-full h-9 pl-10 pr-4 text-sm bg-white dark:bg-surface-700 border border-surface-200 dark:border-surface-600 rounded-lg text-surface-900 dark:text-surface-100 placeholder:text-surface-400 dark:placeholder:text-surface-500 focus:border-primary-500 focus:ring-1 focus:ring-primary-500 outline-none"
          />
          <button
            v-if="myWorkStore.searchQuery"
            @click="myWorkStore.searchQuery = ''"
            class="absolute right-3 top-1/2 -translate-y-1/2 text-surface-400 hover:text-surface-600"
          >
            <span class="material-symbols-rounded text-lg">close</span>
          </button>
        </div>

        <!-- Group By -->
        <div class="relative group-dropdown">
          <button
            @click.stop="showGroupDropdown = !showGroupDropdown"
            class="h-9 flex items-center gap-1.5 px-3 text-sm bg-white dark:bg-surface-700 border border-surface-200 dark:border-surface-600 rounded-lg text-surface-700 dark:text-surface-300 hover:border-primary-500 transition-colors"
          >
            <span class="material-symbols-rounded text-lg">view_agenda</span>
            <span class="hidden sm:inline text-surface-400">Group:</span>
            <span class="font-medium">{{ currentGroupLabel }}</span>
            <span class="material-symbols-rounded text-base text-surface-400">expand_more</span>
          </button>
          <Transition name="dropdown">
            <div
              v-if="showGroupDropdown"
              class="absolute top-full left-0 mt-1 bg-white dark:bg-surface-700 rounded-xl shadow-xl border border-surface-200 dark:border-surface-600 py-1 z-50 min-w-[180px]"
            >
              <button
                v-for="opt in groupOptions"
                :key="opt.value"
                @click="selectGroup(opt.value)"
                :class="[
                  'w-full px-4 py-2 text-left text-sm flex items-center gap-3 transition-colors',
                  myWorkStore.groupBy === opt.value
                    ? 'bg-primary-50 dark:bg-primary-500/10 text-primary-600 dark:text-primary-400 font-medium'
                    : 'text-surface-700 dark:text-surface-300 hover:bg-surface-50 dark:hover:bg-surface-600'
                ]"
              >
                <span class="material-symbols-rounded text-lg">{{ opt.icon }}</span>
                {{ opt.label }}
                <span
                  v-if="myWorkStore.groupBy === opt.value"
                  class="material-symbols-rounded text-lg ml-auto"
                >check</span>
              </button>
            </div>
          </Transition>
        </div>

        <!-- Filter -->
        <div class="relative filter-dropdown">
          <button
            @click.stop="showFilterDropdown = !showFilterDropdown"
            :class="[
              'h-9 flex items-center gap-1.5 px-3 text-sm rounded-lg border transition-colors',
              hasActiveFilters
                ? 'bg-primary-50 dark:bg-primary-500/10 border-primary-300 dark:border-primary-500/30 text-primary-600 dark:text-primary-400'
                : 'bg-white dark:bg-surface-700 border-surface-200 dark:border-surface-600 text-surface-700 dark:text-surface-300 hover:border-primary-500'
            ]"
          >
            <span class="material-symbols-rounded text-lg">filter_list</span>
            <span class="hidden sm:inline">Filter</span>
            <span v-if="activeFilterCount > 0" class="w-4.5 h-4.5 flex items-center justify-center text-[10px] font-bold rounded-full bg-primary-500 text-white leading-none">{{ activeFilterCount }}</span>
          </button>
          <Transition name="dropdown">
            <div
              v-if="showFilterDropdown"
              class="absolute top-full right-0 mt-1 bg-white dark:bg-surface-700 rounded-xl shadow-xl border border-surface-200 dark:border-surface-600 z-50 w-[280px] overflow-hidden"
            >
              <!-- Filter header -->
              <div class="flex items-center justify-between px-4 py-2.5 border-b border-surface-100 dark:border-surface-600">
                <span class="text-xs font-semibold text-surface-500 uppercase tracking-wide">Filters</span>
                <button
                  v-if="hasActiveFilters"
                  @click="clearAllFilters"
                  class="text-xs text-primary-500 hover:text-primary-600 font-medium"
                >Reset all</button>
              </div>

              <div class="p-3 space-y-3">
                <!-- Priority -->
                <div>
                  <p class="text-[11px] font-semibold text-surface-400 uppercase tracking-wider mb-1.5">Priority</p>
                  <div class="grid grid-cols-4 gap-1.5">
                    <button
                      v-for="p in priorityOptions"
                      :key="p.value"
                      @click="myWorkStore.setFilterPriority(p.value)"
                      :class="[
                        'h-8 text-xs font-medium rounded-lg transition-all text-center',
                        myWorkStore.filterPriority === p.value ? p.activeClass : p.inactiveClass
                      ]"
                    >{{ p.label }}</button>
                  </div>
                </div>

                <!-- Source -->
                <div>
                  <p class="text-[11px] font-semibold text-surface-400 uppercase tracking-wider mb-1.5">Source</p>
                  <div class="grid grid-cols-3 gap-1.5">
                    <button
                      v-for="s in sourceOptions"
                      :key="s.value"
                      @click="myWorkStore.setFilterSource(s.value)"
                      :class="[
                        'h-8 text-xs font-medium rounded-lg transition-all flex items-center justify-center gap-1',
                        myWorkStore.filterSource === s.value
                          ? 'bg-primary-500 text-white shadow-sm'
                          : 'bg-surface-50 dark:bg-surface-600 text-surface-600 dark:text-surface-400 hover:bg-surface-100 dark:hover:bg-surface-500'
                      ]"
                    >
                      <span v-if="s.icon" class="material-symbols-rounded text-sm">{{ s.icon }}</span>
                      {{ s.label }}
                    </button>
                  </div>
                </div>

                <!-- Show completed toggle -->
                <div class="flex items-center justify-between pt-1 border-t border-surface-100 dark:border-surface-600">
                  <span class="text-sm text-surface-700 dark:text-surface-300">Show completed</span>
                  <button
                    @click="myWorkStore.setIncludeCompleted(!myWorkStore.includeCompleted)"
                    :class="['w-10 h-[22px] rounded-full relative transition-colors', myWorkStore.includeCompleted ? 'bg-primary-500' : 'bg-surface-300 dark:bg-surface-500']"
                  >
                    <div :class="['absolute top-[3px] w-4 h-4 rounded-full bg-white transition-transform shadow-sm', myWorkStore.includeCompleted ? 'translate-x-[22px]' : 'translate-x-[3px]']"></div>
                  </button>
                </div>
              </div>
            </div>
          </Transition>
        </div>

        <!-- View Toggle -->
        <div class="h-9 flex items-center border border-surface-200 dark:border-surface-600 rounded-lg overflow-hidden">
          <button
            @click="myWorkStore.setViewMode('list')"
            :class="[
              'h-full px-2.5 flex items-center transition-colors',
              myWorkStore.viewMode === 'list'
                ? 'bg-primary-500 text-white'
                : 'bg-white dark:bg-surface-700 text-surface-500 hover:text-surface-700 dark:hover:text-surface-300 hover:bg-surface-50 dark:hover:bg-surface-600'
            ]"
            title="List view"
          >
            <span class="material-symbols-rounded text-lg">view_agenda</span>
          </button>
          <div class="w-px h-full bg-surface-200 dark:bg-surface-600"></div>
          <button
            @click="myWorkStore.setViewMode('table')"
            :class="[
              'h-full px-2.5 flex items-center transition-colors',
              myWorkStore.viewMode === 'table'
                ? 'bg-primary-500 text-white'
                : 'bg-white dark:bg-surface-700 text-surface-500 hover:text-surface-700 dark:hover:text-surface-300 hover:bg-surface-50 dark:hover:bg-surface-600'
            ]"
            title="Table view"
          >
            <span class="material-symbols-rounded text-lg">table_rows</span>
          </button>
        </div>

        <!-- Add Task -->
        <button
          @click="showAddTask = !showAddTask"
          class="h-9 flex items-center gap-1.5 px-4 text-sm bg-primary-500 hover:bg-primary-600 text-white rounded-lg font-medium transition-colors"
        >
          <span class="material-symbols-rounded text-lg">add</span>
          <span class="hidden sm:inline">Add Task</span>
        </button>
      </div>

      <!-- Quick add task input -->
      <Transition name="slide-down">
        <div v-if="showAddTask" class="mt-3">
          <form @submit.prevent="createQuickTask" class="flex gap-2">
            <input
              v-model="newTodoTitle"
              type="text"
              placeholder="What needs to be done?"
              class="flex-1 h-9 px-4 text-sm bg-white dark:bg-surface-700 border border-surface-200 dark:border-surface-600 rounded-lg text-surface-900 dark:text-surface-100 placeholder:text-surface-400 dark:placeholder:text-surface-500 focus:border-primary-500 focus:ring-1 focus:ring-primary-500 outline-none"
              autofocus
              @keydown.escape="showAddTask = false"
            />
            <button
              type="submit"
              :disabled="!newTodoTitle.trim()"
              class="h-9 px-5 text-sm bg-primary-500 hover:bg-primary-600 disabled:bg-primary-500/50 text-white rounded-lg font-medium transition-colors"
            >Add</button>
            <button
              type="button"
              @click="showAddTask = false; newTodoTitle = ''"
              class="h-9 w-9 flex items-center justify-center text-surface-400 hover:text-surface-600 dark:hover:text-surface-300 transition-colors"
            >
              <span class="material-symbols-rounded text-lg">close</span>
            </button>
          </form>
        </div>
      </Transition>
    </div>

    <!-- Main Content -->
    <div :class="isMobile ? 'px-4 pb-24' : 'flex-1 overflow-auto px-4 pb-4'">
      <!-- Loading -->
      <div v-if="myWorkStore.loading" class="flex items-center justify-center py-20">
        <span class="material-symbols-rounded text-4xl text-surface-400 animate-spin">sync</span>
        <p class="ml-3 text-surface-500">Loading your work...</p>
      </div>

      <!-- Error -->
      <div v-else-if="myWorkStore.error" class="flex flex-col items-center justify-center py-20 px-4">
        <span class="material-symbols-rounded text-5xl text-red-400 mb-3">error</span>
        <p class="text-surface-600 dark:text-surface-400">{{ myWorkStore.error }}</p>
        <button
          @click="myWorkStore.fetchMyWork()"
          class="mt-4 px-4 py-2 text-sm bg-primary-500 hover:bg-primary-600 text-white rounded-full font-medium transition-colors"
        >Retry</button>
      </div>

      <!-- Empty state -->
      <div v-else-if="myWorkStore.filteredItems.length === 0 && !myWorkStore.loading" class="flex flex-col items-center justify-center py-20 px-4">
        <span class="material-symbols-rounded text-6xl text-surface-300 dark:text-surface-600 mb-4">assignment_turned_in</span>
        <p class="text-lg font-medium text-surface-600 dark:text-surface-400 mb-2">
          {{ myWorkStore.searchQuery || myWorkStore.filterPriority !== 'all' || myWorkStore.filterSource !== 'all' ? 'No matching tasks' : 'All clear!' }}
        </p>
        <p class="text-sm text-surface-500 text-center max-w-sm">
          {{ myWorkStore.searchQuery || myWorkStore.filterPriority !== 'all' || myWorkStore.filterSource !== 'all'
            ? 'Try adjusting your filters or search query.'
            : 'You have no tasks or assigned board cards. Create a new task to get started.' }}
        </p>
        <div class="flex gap-3 mt-6">
          <button
            v-if="myWorkStore.searchQuery || myWorkStore.filterPriority !== 'all' || myWorkStore.filterSource !== 'all'"
            @click="myWorkStore.searchQuery = ''; clearAllFilters()"
            class="px-4 py-2 text-sm bg-surface-100 dark:bg-surface-700 text-surface-700 dark:text-surface-300 rounded-full font-medium hover:bg-surface-200 dark:hover:bg-surface-600 transition-colors"
          >Clear filters</button>
          <button
            @click="showAddTask = true"
            class="px-4 py-2 text-sm bg-primary-500 hover:bg-primary-600 text-white rounded-full font-medium transition-colors flex items-center gap-2"
          >
            <span class="material-symbols-rounded text-lg">add</span>
            Add Task
          </button>
        </div>
      </div>

      <!-- Table View -->
      <MyWorkTable
        v-else-if="myWorkStore.viewMode === 'table'"
        :groups="myWorkStore.currentGroups"
        :collapsed-groups="collapsedGroups"
        @toggle="handleToggle"
        @open="handleOpenItem"
        @toggle-group="toggleGroup"
      />

      <!-- Card List View -->
      <div v-else class="space-y-4 pb-20">
        <div
          v-for="group in myWorkStore.currentGroups"
          :key="group.key"
          class="space-y-2"
        >
          <button
            @click="toggleGroup(group.key)"
            class="w-full flex items-center gap-2 py-2 group/header"
          >
            <span
              class="material-symbols-rounded text-lg transition-transform"
              :class="[isGroupCollapsed(group.key) ? '-rotate-90' : '', group.colorClass || 'text-surface-500']"
            >expand_more</span>
            <span :class="['material-symbols-rounded text-lg', group.colorClass || 'text-surface-500']">{{ group.icon }}</span>
            <span class="text-sm font-semibold text-surface-900 dark:text-surface-100">{{ group.label }}</span>
            <span class="px-2 py-0.5 text-xs font-medium bg-surface-200 dark:bg-surface-700 text-surface-600 dark:text-surface-400 rounded-full">{{ group.items.length }}</span>
            <div class="flex-1 h-px bg-surface-200 dark:bg-surface-700 ml-2 group-hover/header:bg-surface-300 dark:group-hover/header:bg-surface-600 transition-colors"></div>
          </button>
          <Transition name="collapse">
            <div v-if="!isGroupCollapsed(group.key)" class="space-y-2 pl-1">
              <MyWorkCard
                v-for="item in group.items"
                :key="item.id"
                :item="item"
                @toggle="handleToggle"
                @open-email="handleOpenItem"
                @open-card="handleOpenItem"
              />
            </div>
          </Transition>
        </div>
      </div>
    </div>

    <!-- Mobile Bottom Navigation -->
    <MobileBottomNav v-if="isMobile" />

    <!-- Detail Panel -->
    <MyWorkDetailPanel
      :item="selectedItem"
      @close="handleClosePanel"
      @delete="handleDeleteItem"
    />
  </div>
</template>

<style scoped>
.dropdown-enter-active,
.dropdown-leave-active {
  transition: opacity 0.15s ease, transform 0.15s ease;
}
.dropdown-enter-from,
.dropdown-leave-to {
  opacity: 0;
  transform: translateY(-4px);
}

.slide-down-enter-active,
.slide-down-leave-active {
  transition: all 0.2s ease;
}
.slide-down-enter-from,
.slide-down-leave-to {
  opacity: 0;
  max-height: 0;
  margin-top: 0;
  overflow: hidden;
}
.slide-down-enter-to,
.slide-down-leave-from {
  max-height: 80px;
}

.collapse-enter-active,
.collapse-leave-active {
  transition: all 0.2s ease;
}
.collapse-enter-from,
.collapse-leave-to {
  opacity: 0;
  transform: translateY(-8px);
}

@keyframes spin {
  from { transform: rotate(0deg); }
  to { transform: rotate(360deg); }
}
.animate-spin {
  animation: spin 1s linear infinite;
}

@media (max-width: 768px) {
  .-webkit-overflow-scrolling-touch {
    -webkit-overflow-scrolling: touch;
  }
}
</style>
