<script setup>
import { useI18n } from 'vue-i18n'

const props = defineProps({
  open: { type: Boolean, default: false },
  count: { type: Number, default: 0 },
  loading: { type: Boolean, default: false },
})

const emit = defineEmits(['close', 'confirm'])

const { t } = useI18n()

function onCancel() {
  if (props.loading) return
  emit('close')
}

function onConfirm() {
  if (props.loading) return
  emit('confirm')
}
</script>

<template>
  <Teleport to="body">
    <Transition name="fade">
      <div
        v-if="open"
        class="fixed inset-0 bg-black/50 z-[9999] flex items-center justify-center p-4"
        @click.self="onCancel"
        @keydown.esc="onCancel"
      >
        <div
          class="bg-white dark:bg-surface-800 rounded-2xl shadow-xl w-full max-w-sm overflow-hidden"
          role="alertdialog"
          aria-modal="true"
          :aria-labelledby="'clear-completed-title'"
        >
          <div class="p-6">
            <div class="flex items-start gap-4">
              <div class="shrink-0 w-11 h-11 rounded-full bg-red-500/10 text-red-500 flex items-center justify-center">
                <span class="material-symbols-rounded text-[22px]">delete_sweep</span>
              </div>
              <div class="flex-1 min-w-0">
                <h2
                  id="clear-completed-title"
                  class="text-base font-semibold text-surface-900 dark:text-surface-100"
                >
                  {{ t('tasksPanel.clearCompleted.title') }}
                </h2>
                <p class="mt-1.5 text-sm text-surface-600 dark:text-surface-400 leading-relaxed">
                  {{ t('tasksPanel.clearCompleted.confirm', { count }) }}
                </p>
              </div>
            </div>
          </div>

          <div class="px-6 py-4 bg-surface-50 dark:bg-surface-900 flex justify-end gap-2">
            <button
              type="button"
              class="px-4 py-2 text-sm font-medium text-surface-700 dark:text-surface-300 hover:text-surface-900 dark:hover:text-surface-100 rounded-full transition-colors disabled:opacity-50"
              :disabled="loading"
              @click="onCancel"
            >
              {{ t('tasksPanel.clearCompleted.cancel') }}
            </button>
            <button
              type="button"
              class="px-5 py-2 text-sm font-medium bg-red-500 hover:bg-red-600 disabled:bg-red-500/50 text-white rounded-full transition-colors flex items-center gap-2"
              :disabled="loading"
              @click="onConfirm"
            >
              <span
                v-if="loading"
                class="material-symbols-rounded text-base animate-spin"
              >progress_activity</span>
              <span v-else class="material-symbols-rounded text-base">delete_sweep</span>
              {{ loading
                ? t('tasksPanel.clearCompleted.clearing')
                : t('tasksPanel.clearCompleted.confirmButton') }}
            </button>
          </div>
        </div>
      </div>
    </Transition>
  </Teleport>
</template>

<style scoped>
.fade-enter-active,
.fade-leave-active {
  transition: opacity 0.18s ease;
}
.fade-enter-from,
.fade-leave-to {
  opacity: 0;
}
</style>
