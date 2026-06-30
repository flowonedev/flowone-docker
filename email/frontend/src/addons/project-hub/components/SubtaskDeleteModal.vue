<script setup>
defineProps({
  subtask: { type: Object, required: true },
})
const emit = defineEmits(['confirm', 'cancel'])
</script>

<template>
  <Teleport to="body">
    <Transition name="fade">
      <div
        class="fixed inset-0 z-[9999] flex items-center justify-center bg-black/40"
        @click.self="emit('cancel')"
      >
        <div class="bg-white dark:bg-surface-800 rounded-2xl shadow-xl w-full max-w-sm p-6 mx-4">
          <div class="flex items-center gap-3 mb-4">
            <div class="w-10 h-10 rounded-full bg-red-100 dark:bg-red-900/30 flex items-center justify-center shrink-0">
              <span class="material-symbols-rounded text-xl text-red-500">delete</span>
            </div>
            <div>
              <h3 class="text-sm font-bold text-surface-800 dark:text-surface-100">Delete Subtask</h3>
              <p class="text-xs text-surface-400 mt-0.5">This action cannot be undone.</p>
            </div>
          </div>

          <p class="text-sm text-surface-600 dark:text-surface-300 mb-5 pl-[52px]">
            Are you sure you want to delete
            <span class="font-medium text-surface-800 dark:text-surface-100">"{{ subtask?.title }}"</span>?
          </p>

          <div class="flex items-center justify-end gap-2">
            <button
              type="button"
              class="px-4 py-2 rounded-full text-sm font-medium text-surface-600 dark:text-surface-400 hover:bg-surface-100 dark:hover:bg-surface-700 transition-colors"
              @click="emit('cancel')"
            >
              Cancel
            </button>
            <button
              type="button"
              class="px-4 py-2 rounded-full text-sm font-medium bg-red-500 text-white hover:bg-red-600 transition-colors"
              @click="emit('confirm')"
            >
              Delete
            </button>
          </div>
        </div>
      </div>
    </Transition>
  </Teleport>
</template>

<style scoped>
.fade-enter-active { transition: opacity 0.15s ease; }
.fade-leave-active { transition: opacity 0.1s ease; }
.fade-enter-from, .fade-leave-to { opacity: 0; }
</style>
