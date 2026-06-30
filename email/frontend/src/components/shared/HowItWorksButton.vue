<script setup>
import { useI18n } from 'vue-i18n'
const { t } = useI18n()

const props = defineProps({
  active: { type: Boolean, default: false },
  variant: { type: String, default: 'guide' },
})

defineEmits(['click'])
</script>

<template>
  <button
    v-if="variant === 'guide'"
    @click="$emit('click')"
    class="hiw-btn group relative hidden sm:flex items-center justify-center w-8 h-8 rounded-full transition-all duration-200"
    :class="active
      ? 'bg-amber-500 text-white shadow-md'
      : 'bg-amber-500/10 text-amber-500 hover:bg-amber-500 hover:text-white hover:shadow-md'"
  >
    <span class="material-symbols-rounded text-lg">help</span>
    <span class="hiw-tooltip">{{ t('featureGuide.howItWorks') }}</span>
  </button>

  <button
    v-else
    @click="$emit('click')"
    class="hiw-btn group relative hidden sm:flex items-center justify-center w-8 h-8 rounded-full transition-all duration-200"
    :class="active
      ? 'bg-primary-500 text-white shadow-md'
      : 'bg-surface-200 dark:bg-surface-700 text-surface-500 dark:text-surface-400 hover:bg-primary-500 hover:text-white hover:shadow-md'"
  >
    <span class="material-symbols-rounded text-lg">layers</span>
    <span class="hiw-tooltip">{{ t('featureGuide.features') }}</span>
  </button>
</template>

<style scoped>
.hiw-tooltip {
  @apply absolute -bottom-9 left-1/2 -translate-x-1/2 px-2.5 py-1 rounded-lg text-xs font-medium
         bg-surface-900 dark:bg-surface-100 text-white dark:text-surface-900
         opacity-0 pointer-events-none transition-opacity duration-150 whitespace-nowrap z-50;
}
.hiw-btn:hover .hiw-tooltip {
  @apply opacity-100;
}
</style>
