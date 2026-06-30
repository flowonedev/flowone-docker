<script setup>
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

/**
 * Reusable confirm modal for storage admin actions.
 *
 * Usage:
 *   <StorageConfirmModal
 *     v-model="show"
 *     :title="..."
 *     :description="..."
 *     :confirm-label="..."
 *     :variant="'danger' | 'warning' | 'primary'"
 *     :busy="busy"
 *     :require-typed-confirm="'DELETE'"   optional
 *     @confirm="onConfirm({ reason })"
 *   />
 */
const props = defineProps({
  modelValue: { type: Boolean, default: false },
  title: { type: String, default: '' },
  description: { type: String, default: '' },
  confirmLabel: { type: String, default: '' },
  variant: { type: String, default: 'primary' },  // primary | warning | danger
  busy: { type: Boolean, default: false },
  requireTypedConfirm: { type: String, default: '' },
  showReason: { type: Boolean, default: true },
})

const emit = defineEmits(['update:modelValue', 'confirm'])

const { t } = useI18n()
const reason = ref('')
const typed = ref('')

watch(() => props.modelValue, (on) => {
  if (on) {
    reason.value = ''
    typed.value = ''
  }
})

const canConfirm = computed(() => {
  if (props.busy) return false
  if (props.requireTypedConfirm && typed.value !== props.requireTypedConfirm) return false
  return true
})

const buttonClass = computed(() => {
  if (props.variant === 'danger') {
    return 'bg-red-600 hover:bg-red-700 text-white disabled:bg-red-300'
  }
  if (props.variant === 'warning') {
    return 'bg-amber-500 hover:bg-amber-600 text-white disabled:bg-amber-300'
  }
  return 'bg-primary-600 hover:bg-primary-700 text-white disabled:bg-primary-300'
})

function close() {
  if (!props.busy) emit('update:modelValue', false)
}

function confirm() {
  if (!canConfirm.value) return
  emit('confirm', { reason: reason.value.trim() })
}
</script>

<template>
  <Teleport to="body">
    <div
      v-if="modelValue"
      class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 backdrop-blur-sm p-4"
      @click.self="close"
    >
      <div class="w-full max-w-md rounded-xl bg-white dark:bg-surface-800 shadow-2xl overflow-hidden">
        <header class="px-5 py-4 border-b border-surface-200 dark:border-surface-700">
          <h3 class="font-semibold text-surface-900 dark:text-surface-100">
            {{ title || t('storage.confirm.title', 'Confirm action') }}
          </h3>
        </header>

        <div class="px-5 py-4 space-y-3 text-sm text-surface-700 dark:text-surface-300">
          <p>{{ description }}</p>

          <div v-if="showReason">
            <label class="block text-xs uppercase tracking-wide text-surface-500 mb-1">
              {{ t('storage.confirm.reason', 'Reason (optional)') }}
            </label>
            <input
              v-model="reason"
              type="text"
              maxlength="200"
              :disabled="busy"
              class="w-full px-3 py-2 rounded-lg bg-surface-50 dark:bg-surface-900 border border-surface-300 dark:border-surface-600 text-sm focus:outline-none focus:ring-2 focus:ring-primary-500"
              :placeholder="t('storage.confirm.reasonPlaceholder', 'e.g. maintenance window')"
            />
          </div>

          <div v-if="requireTypedConfirm">
            <label class="block text-xs uppercase tracking-wide text-red-600 dark:text-red-400 mb-1">
              {{ t('storage.confirm.typeToConfirm', 'Type {word} to confirm', { word: requireTypedConfirm }) }}
            </label>
            <input
              v-model="typed"
              type="text"
              :disabled="busy"
              class="w-full px-3 py-2 rounded-lg bg-surface-50 dark:bg-surface-900 border border-red-300 dark:border-red-700 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-red-500"
              :placeholder="requireTypedConfirm"
              autocomplete="off"
            />
          </div>
        </div>

        <footer class="px-5 py-3 bg-surface-50 dark:bg-surface-900/50 flex items-center justify-end gap-2">
          <button
            type="button"
            class="px-3 py-2 text-sm rounded-lg bg-surface-200 hover:bg-surface-300 dark:bg-surface-700 dark:hover:bg-surface-600 disabled:opacity-50"
            :disabled="busy"
            @click="close"
          >
            {{ t('storage.confirm.cancel', 'Cancel') }}
          </button>
          <button
            type="button"
            class="px-3 py-2 text-sm rounded-lg disabled:cursor-not-allowed"
            :class="buttonClass"
            :disabled="!canConfirm"
            @click="confirm"
          >
            <span v-if="busy" class="inline-flex items-center gap-2">
              <span class="material-symbols-rounded text-base animate-spin">progress_activity</span>
              {{ t('storage.confirm.working', 'Working…') }}
            </span>
            <span v-else>{{ confirmLabel || t('storage.confirm.ok', 'Confirm') }}</span>
          </button>
        </footer>
      </div>
    </div>
  </Teleport>
</template>
