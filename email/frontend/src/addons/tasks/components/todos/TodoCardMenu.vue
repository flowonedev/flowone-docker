<script setup>
import { ref, onMounted, onBeforeUnmount } from 'vue'
import { useI18n } from 'vue-i18n'

const props = defineProps({
  // Hides menu items that don't apply to completed todos.
  completed: { type: Boolean, default: false },
  hasEmailRef: { type: Boolean, default: false }
})

const emit = defineEmits([
  'edit',
  'add-subtask',
  'convert',
  'delete',
  'open-email',
  'set-priority'
])

const { t } = useI18n()

const open = ref(false)
const root = ref(null)

function toggle(e) {
  e.stopPropagation()
  open.value = !open.value
}

function close() {
  open.value = false
}

function trigger(event, payload) {
  emit(event, payload)
  close()
}

function onDocumentClick(e) {
  if (!root.value) return
  if (root.value.contains(e.target)) return
  close()
}

function onEscape(e) {
  if (e.key === 'Escape') close()
}

onMounted(() => {
  document.addEventListener('mousedown', onDocumentClick)
  document.addEventListener('keydown', onEscape)
})

onBeforeUnmount(() => {
  document.removeEventListener('mousedown', onDocumentClick)
  document.removeEventListener('keydown', onEscape)
})
</script>

<template>
  <div ref="root" class="relative">
    <button
      type="button"
      class="p-1.5 -mr-1 rounded-lg text-surface-400 hover:text-surface-700 dark:text-surface-500 dark:hover:text-surface-200 hover:bg-surface-100 dark:hover:bg-surface-700 transition-colors"
      :aria-expanded="open"
      :aria-label="t('tasksPanel.menu.aria')"
      @click="toggle"
    >
      <span class="material-symbols-rounded text-lg">more_vert</span>
    </button>

    <Transition name="menu-fade">
      <div
        v-if="open"
        class="absolute right-0 top-full mt-1 z-30 min-w-[180px] bg-white dark:bg-surface-800 rounded-xl shadow-xl border border-surface-200 dark:border-surface-700 py-1"
      >
        <button
          v-if="!completed"
          type="button"
          class="w-full px-3 py-2 flex items-center gap-2 text-sm text-surface-700 dark:text-surface-200 hover:bg-surface-100 dark:hover:bg-surface-700 text-left"
          @click="trigger('edit')"
        >
          <span class="material-symbols-rounded text-base text-surface-400">edit</span>
          {{ t('tasksPanel.menu.edit') }}
        </button>
        <button
          v-if="!completed"
          type="button"
          class="w-full px-3 py-2 flex items-center gap-2 text-sm text-surface-700 dark:text-surface-200 hover:bg-surface-100 dark:hover:bg-surface-700 text-left"
          @click="trigger('add-subtask')"
        >
          <span class="material-symbols-rounded text-base text-surface-400">playlist_add</span>
          {{ t('tasksPanel.menu.addSubtask') }}
        </button>

        <div class="my-1 h-px bg-surface-200 dark:bg-surface-700"></div>

        <button
          v-if="!completed"
          type="button"
          class="w-full px-3 py-2 flex items-center gap-2 text-sm text-surface-700 dark:text-surface-200 hover:bg-surface-100 dark:hover:bg-surface-700 text-left"
          @click="trigger('set-priority', 'high')"
        >
          <span class="w-2 h-2 rounded-full bg-red-500"></span>
          {{ t('tasksPanel.menu.priorityHigh') }}
        </button>
        <button
          v-if="!completed"
          type="button"
          class="w-full px-3 py-2 flex items-center gap-2 text-sm text-surface-700 dark:text-surface-200 hover:bg-surface-100 dark:hover:bg-surface-700 text-left"
          @click="trigger('set-priority', 'normal')"
        >
          <span class="w-2 h-2 rounded-full bg-amber-500"></span>
          {{ t('tasksPanel.menu.priorityMedium') }}
        </button>
        <button
          v-if="!completed"
          type="button"
          class="w-full px-3 py-2 flex items-center gap-2 text-sm text-surface-700 dark:text-surface-200 hover:bg-surface-100 dark:hover:bg-surface-700 text-left"
          @click="trigger('set-priority', 'low')"
        >
          <span class="w-2 h-2 rounded-full bg-blue-500"></span>
          {{ t('tasksPanel.menu.priorityLow') }}
        </button>

        <div v-if="!completed" class="my-1 h-px bg-surface-200 dark:bg-surface-700"></div>

        <button
          v-if="hasEmailRef && !completed"
          type="button"
          class="w-full px-3 py-2 flex items-center gap-2 text-sm text-surface-700 dark:text-surface-200 hover:bg-surface-100 dark:hover:bg-surface-700 text-left"
          @click="trigger('open-email')"
        >
          <span class="material-symbols-rounded text-base text-surface-400">mail</span>
          {{ t('tasksPanel.menu.openEmail') }}
        </button>
        <button
          v-if="!completed"
          type="button"
          class="w-full px-3 py-2 flex items-center gap-2 text-sm text-surface-700 dark:text-surface-200 hover:bg-surface-100 dark:hover:bg-surface-700 text-left"
          @click="trigger('convert')"
        >
          <span class="material-symbols-rounded text-base text-surface-400">dashboard</span>
          {{ t('tasksPanel.menu.convertToBoard') }}
        </button>

        <div class="my-1 h-px bg-surface-200 dark:bg-surface-700"></div>

        <button
          type="button"
          class="w-full px-3 py-2 flex items-center gap-2 text-sm text-red-600 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-900/20 text-left"
          @click="trigger('delete')"
        >
          <span class="material-symbols-rounded text-base">delete</span>
          {{ t('tasksPanel.menu.delete') }}
        </button>
      </div>
    </Transition>
  </div>
</template>

<style scoped>
.menu-fade-enter-active,
.menu-fade-leave-active {
  transition: opacity 0.12s ease, transform 0.12s ease;
}
.menu-fade-enter-from,
.menu-fade-leave-to {
  opacity: 0;
  transform: translateY(-2px);
}
</style>
