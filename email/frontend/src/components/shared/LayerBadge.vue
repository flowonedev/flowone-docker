<script setup>
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

defineProps({
  layerKey: { type: String, required: true },
  icon: { type: String, required: true },
})
</script>

<template>
  <div class="flex items-center gap-1.5 px-3 py-1.5 mb-2 rounded-lg bg-surface-50 dark:bg-surface-700/30">
    <span class="material-symbols-rounded text-sm text-surface-400">{{ icon }}</span>
    <span class="text-[10px] font-semibold uppercase tracking-wider text-surface-400 dark:text-surface-500">{{ t(layerKey) }}</span>
  </div>
</template>
