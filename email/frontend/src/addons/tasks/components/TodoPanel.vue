<script setup>
import { ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useTodosStore } from '@/addons/tasks/stores/todos'
import { useAddons } from '@/composables/useAddons'
import TodosTab from './panel/TodosTab.vue'
import BoardsTab from './panel/BoardsTab.vue'
import CalendarTab from './panel/CalendarTab.vue'
import ConvertToBoardModal from './panel/ConvertToBoardModal.vue'

const { t } = useI18n()
const todosStore = useTodosStore()
const { kanbanBoardsEnabled, calendarEnabled, tasksEnabled } = useAddons()

const activeTab = ref('todos') // 'todos' | 'boards' | 'calendar'

// Convert-to-board modal lives at the panel level so the modal survives tab
// switches inside the same panel.
const convertOpen = ref(false)
const convertTodo = ref(null)

function openConvert(todo) {
  convertTodo.value = todo
  convertOpen.value = true
}

function closeConvert() {
  convertOpen.value = false
  convertTodo.value = null
}

// `openPanelWithBoard` from the store stages a board id; switch to the Boards
// tab so BoardsTab.vue can pick it up from its watcher.
watch(() => todosStore.pendingBoardId, (id) => {
  if (id) activeTab.value = 'boards'
}, { immediate: true })
</script>

<template>
  <Teleport to="body">
    <Transition name="fade">
      <div
        v-if="todosStore.panelOpen"
        class="fixed inset-0 bg-black/30 z-[9997] lg:hidden"
        @click="todosStore.closePanel"
      ></div>
    </Transition>

    <Transition name="slide">
      <div
        v-if="todosStore.panelOpen"
        class="fixed right-0 top-0 h-full w-full max-w-[420px] bg-white dark:bg-surface-900 shadow-2xl z-[9998] flex flex-col border-l border-surface-200 dark:border-surface-700"
      >
        <div class="px-4 py-3 border-b border-surface-200 dark:border-surface-700 flex items-center justify-between safe-area-top">
          <div class="flex items-center gap-2">
            <span class="material-symbols-rounded text-primary-500">
              {{ activeTab === 'todos' ? 'task_alt' : (activeTab === 'boards') ? 'dashboard' : 'calendar_month' }}
            </span>
            <h2 class="font-semibold text-surface-900 dark:text-surface-100">
              {{ activeTab === 'todos' ? t('tasksPanel.headers.myTasks')
                 : activeTab === 'boards' ? t('tasksPanel.headers.boards')
                 : t('tasksPanel.headers.calendar') }}
            </h2>
            <span
              v-if="activeTab === 'todos' && todosStore.incompleteCount > 0"
              class="px-2 py-0.5 text-xs font-medium bg-primary-500/20 text-primary-600 dark:text-primary-400 rounded-full"
            >
              {{ todosStore.incompleteCount }}
            </span>
          </div>
          <button class="p-2 rounded-lg hover:bg-surface-100 dark:hover:bg-surface-700" @click="todosStore.closePanel">
            <span class="material-symbols-rounded text-surface-500 dark:text-surface-400">close</span>
          </button>
        </div>

        <div class="flex border-b border-surface-200 dark:border-surface-700">
          <button
            v-if="tasksEnabled"
            class="flex-1 px-2 py-2.5 text-sm font-medium transition-colors relative"
            :class="activeTab === 'todos'
              ? 'text-primary-600 dark:text-primary-400'
              : 'text-surface-500 dark:text-surface-400 hover:text-surface-700 dark:hover:text-surface-300'"
            @click="activeTab = 'todos'"
          >
            <span class="flex items-center justify-center gap-1">
              <span class="material-symbols-rounded text-lg">task_alt</span>
              <span class="hidden sm:inline">{{ t('tasksPanel.tabs.myTasks') }}</span>
              <span
                v-if="todosStore.incompleteCount > 0"
                class="px-1.5 py-0.5 text-xs bg-primary-500/20 text-primary-600 dark:text-primary-400 rounded-full"
              >
                {{ todosStore.incompleteCount }}
              </span>
            </span>
            <span v-if="activeTab === 'todos'" class="absolute bottom-0 left-1 right-1 h-0.5 bg-primary-500 rounded-full"></span>
          </button>

          <button
            v-if="kanbanBoardsEnabled"
            class="flex-1 px-2 py-2.5 text-sm font-medium transition-colors relative"
            :class="activeTab === 'boards'
              ? 'text-primary-600 dark:text-primary-400'
              : 'text-surface-500 dark:text-surface-400 hover:text-surface-700 dark:hover:text-surface-300'"
            @click="activeTab = 'boards'"
          >
            <span class="flex items-center justify-center gap-1">
              <span class="material-symbols-rounded text-lg">dashboard</span>
              <span class="hidden sm:inline">{{ t('tasksPanel.tabs.boards') }}</span>
            </span>
            <span v-if="activeTab === 'boards'" class="absolute bottom-0 left-1 right-1 h-0.5 bg-primary-500 rounded-full"></span>
          </button>

          <button
            v-if="calendarEnabled"
            class="flex-1 px-2 py-2.5 text-sm font-medium transition-colors relative"
            :class="activeTab === 'calendar'
              ? 'text-primary-600 dark:text-primary-400'
              : 'text-surface-500 dark:text-surface-400 hover:text-surface-700 dark:hover:text-surface-300'"
            @click="activeTab = 'calendar'"
          >
            <span class="flex items-center justify-center gap-1">
              <span class="material-symbols-rounded text-lg">calendar_month</span>
              <span class="hidden sm:inline">{{ t('tasksPanel.tabs.calendar') }}</span>
            </span>
            <span v-if="activeTab === 'calendar'" class="absolute bottom-0 left-1 right-1 h-0.5 bg-primary-500 rounded-full"></span>
          </button>
        </div>

        <TodosTab
          v-if="activeTab === 'todos' && tasksEnabled"
          @convert="openConvert"
        />
        <BoardsTab
          v-else-if="activeTab === 'boards' && kanbanBoardsEnabled"
        />
        <CalendarTab
          v-else-if="activeTab === 'calendar' && calendarEnabled"
          @switch-to-todos="activeTab = 'todos'"
        />
      </div>
    </Transition>

    <ConvertToBoardModal
      :open="convertOpen"
      :todo="convertTodo"
      @close="closeConvert"
    />
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

@keyframes spin {
  from { transform: rotate(0deg); }
  to { transform: rotate(360deg); }
}
.animate-spin {
  animation: spin 1s linear infinite;
}
</style>
