<script setup>
import { useToastStore } from '../stores/toast'

const toast = useToastStore()

const getIcon = (type) => {
  switch (type) {
    case 'success': return 'check_circle'
    case 'error': return 'error'
    case 'warning': return 'warning'
    default: return 'info'
  }
}

const getClass = (type) => {
  switch (type) {
    case 'success': return 'bg-green-600 border-green-500'
    case 'error': return 'bg-red-600 border-red-500'
    case 'warning': return 'bg-amber-600 border-amber-500'
    default: return 'bg-blue-600 border-blue-500'
  }
}
</script>

<template>
  <div class="fixed bottom-4 right-4 z-[9999] flex flex-col gap-2">
    <TransitionGroup name="toast">
      <div
        v-for="t in toast.toasts"
        :key="t.id"
        :class="['flex items-center gap-3 px-4 py-3 rounded-lg border shadow-lg text-white min-w-[300px]', getClass(t.type)]"
      >
        <span class="material-symbols-rounded text-xl">{{ getIcon(t.type) }}</span>
        <span class="flex-1">{{ t.message }}</span>
        <button @click="toast.remove(t.id)" class="hover:opacity-75">
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

