<script setup>
import { usePerspectiveStore, PERSPECTIVES } from '@/stores/perspective'
import { useI18n } from 'vue-i18n'

const perspective = usePerspectiveStore()
const { t } = useI18n()

const options = [
  { id: PERSPECTIVES.EXECUTIVE, icon: 'query_stats', labelKey: 'perspective.executive' },
  { id: PERSPECTIVES.DELIVERY, icon: 'engineering', labelKey: 'perspective.delivery' },
  { id: PERSPECTIVES.OPERATIONS, icon: 'hub', labelKey: 'perspective.operations' },
]

function select(id) {
  perspective.setPerspective(id)
}
</script>

<template>
  <div class="flex items-center w-full bg-surface-100 dark:bg-surface-700/60 rounded-full p-0.5 gap-0.5">
    <button
      v-for="opt in options"
      :key="opt.id"
      @click="select(opt.id)"
      class="flex-1 flex items-center justify-center gap-1.5 px-3 py-1.5 rounded-full text-xs font-medium transition-all duration-200 whitespace-nowrap"
      :class="perspective.active === opt.id
        ? 'bg-white dark:bg-surface-600 text-surface-900 dark:text-surface-100 shadow-sm'
        : 'text-surface-500 dark:text-surface-400 hover:text-surface-700 dark:hover:text-surface-200'"
      :title="t(opt.labelKey)"
    >
      <span class="material-symbols-rounded text-sm">{{ opt.icon }}</span>
      {{ t(opt.labelKey) }}
    </button>
  </div>
</template>
