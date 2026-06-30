<script setup>
import { useToastStore } from '@/stores/toast'

const toast = useToastStore()

const icons = {
  success: 'check_circle',
  error: 'error',
  warning: 'warning',
  info: 'info',
}

const colors = {
  success: 'text-green-500',
  error: 'text-red-500',
  warning: 'text-amber-500',
  info: 'text-blue-500',
}

function handleActionClick(t) {
  if (t.action?.onClick) {
    t.action.onClick()
  }
  toast.remove(t.id)
}
</script>

<template>
  <Teleport to="body">
    <div class="fixed bottom-4 right-4 z-[99999] flex flex-col gap-2 pointer-events-none">
      <!-- pointer-events-none on container, auto on children so toasts are clickable but don't block interaction -->
      <TransitionGroup
        enter-active-class="transition-all duration-300"
        leave-active-class="transition-all duration-200"
        enter-from-class="opacity-0 translate-x-8"
        leave-to-class="opacity-0 translate-x-8"
      >
        <div
          v-for="t in toast.toasts"
          :key="t.id"
          class="card no-ambient px-4 py-3 flex items-center gap-3 shadow-lg min-w-[300px] max-w-[400px] pointer-events-auto"
        >
          <span :class="['material-symbols-rounded text-xl', colors[t.type]]">
            {{ icons[t.type] }}
          </span>
          <p class="flex-1 text-sm">{{ t.message }}</p>
          <!-- Action button (optional) -->
          <button
            v-if="t.action"
            @click="handleActionClick(t)"
            class="px-3 py-1 text-sm font-medium text-primary-600 dark:text-primary-400 hover:bg-primary-50 dark:hover:bg-primary-500/10 rounded-lg transition-colors"
          >
            {{ t.action.label }}
          </button>
          <button
            @click="toast.remove(t.id)"
            class="text-surface-400 hover:text-surface-600 dark:hover:text-surface-200"
          >
            <span class="material-symbols-rounded text-lg">close</span>
          </button>
        </div>
      </TransitionGroup>
    </div>
  </Teleport>
</template>

