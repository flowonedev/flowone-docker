<script setup>
import { useToastStore } from '@/stores/toast'

const toast = useToastStore()

const icons = {
  success: 'check_circle',
  error: 'error',
  warning: 'warning',
  info: 'info'
}

const colors = {
  success: 'text-green-500',
  error: 'text-red-500',
  warning: 'text-amber-500',
  info: 'text-blue-500'
}
</script>

<template>
  <div class="fixed bottom-4 right-4 z-[9999] space-y-2">
    <TransitionGroup name="toast">
      <div
        v-for="t in toast.toasts"
        :key="t.id"
        class="card px-4 py-3 flex items-center gap-3 shadow-lg min-w-[300px] max-w-md animate-slide-up"
      >
        <span :class="['material-symbols-rounded', colors[t.type]]">
          {{ icons[t.type] }}
        </span>
        <p class="flex-1 text-sm">{{ t.message }}</p>
        <button
          @click="toast.remove(t.id)"
          class="text-surface-400 hover:text-surface-600 dark:hover:text-surface-300"
        >
          <span class="material-symbols-rounded text-lg">close</span>
        </button>
      </div>
    </TransitionGroup>
  </div>
</template>

<style scoped>
.toast-enter-active,
.toast-leave-active {
  transition: all 0.3s ease;
}

.toast-enter-from {
  opacity: 0;
  transform: translateX(100%);
}

.toast-leave-to {
  opacity: 0;
  transform: translateX(100%);
}
</style>

