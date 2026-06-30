<script setup>
import { useI18n } from 'vue-i18n'

const props = defineProps({
  modelValue: { type: String, default: '' }
})
const emit = defineEmits(['update:modelValue'])

const { t } = useI18n()

function onInput(e) {
  emit('update:modelValue', e.target.value)
}

// Mac vs non-Mac shortcut hint. Purely cosmetic – the keybinding belongs to
// the global SuperSearch, this input is a local filter only.
const isMac = typeof navigator !== 'undefined' && /Mac|iPhone|iPad/.test(navigator.platform || navigator.userAgent || '')
const shortcutHint = isMac ? '⌘K' : 'Ctrl K'
</script>

<template>
  <div class="px-4 pb-3">
    <div class="relative">
      <span class="material-symbols-rounded text-lg absolute left-3 top-1/2 -translate-y-1/2 text-surface-400 dark:text-surface-500 pointer-events-none">
        search
      </span>
      <input
        :value="modelValue"
        type="text"
        :placeholder="t('tasksPanel.searchPlaceholder')"
        class="w-full pl-9 pr-14 py-2.5 text-sm bg-surface-50 dark:bg-surface-800 border border-surface-200 dark:border-surface-700 rounded-xl text-surface-900 dark:text-surface-100 placeholder:text-surface-400 dark:placeholder:text-surface-500 outline-none focus:border-primary-500 focus:ring-1 focus:ring-primary-500 transition-colors"
        @input="onInput"
      />
      <span
        aria-hidden="true"
        class="absolute right-2 top-1/2 -translate-y-1/2 px-1.5 py-0.5 text-[10px] font-medium tracking-wide bg-surface-100 dark:bg-surface-700 text-surface-500 dark:text-surface-400 rounded-md border border-surface-200 dark:border-surface-600"
      >
        {{ shortcutHint }}
      </span>
    </div>
  </div>
</template>
