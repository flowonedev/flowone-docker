<script setup>
import { useI18n } from 'vue-i18n'
import { useGreeting } from '@/addons/tasks/composables/useGreeting'
import TodoProgressRing from './TodoProgressRing.vue'

const props = defineProps({
  completedToday: { type: Number, default: 0 },
  totalToday: { type: Number, default: 0 },
  percent: { type: Number, default: 0 }
})

const { t } = useI18n()
const { firstName, periodKey } = useGreeting()
</script>

<template>
  <div class="px-4 pt-4 pb-3 flex items-start justify-between gap-3">
    <div class="min-w-0 flex-1">
      <h2 class="text-base font-semibold text-surface-900 dark:text-surface-100 flex items-center gap-1.5 truncate">
        <span class="truncate">
          {{ t(`tasksPanel.greeting.${periodKey}`) }}<template v-if="firstName">, {{ firstName }}</template>
        </span>
        <span aria-hidden="true" class="shrink-0">👋</span>
      </h2>
      <p class="mt-0.5 text-xs text-surface-500 dark:text-surface-400">
        {{ t('tasksPanel.completedToday', { done: props.completedToday, total: props.totalToday }) }}
      </p>
    </div>
    <TodoProgressRing :percent="percent" :size="56" :stroke="5" />
  </div>
</template>
