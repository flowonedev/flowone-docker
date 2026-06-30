<script setup>
import { useI18n } from 'vue-i18n'
import { useOnboardingStore } from '@/stores/onboarding'

const { t } = useI18n()
const store = useOnboardingStore()

const emit = defineEmits(['select'])

const tiers = [
  {
    id: 'beginner',
    icon: 'hub',
    color: 'from-teal-500 to-cyan-500',
    bgLight: 'bg-teal-50',
    bgDark: 'dark:bg-teal-500/10',
    borderActive: 'border-teal-500',
    textColor: 'text-teal-600 dark:text-teal-400',
    durationMinutes: 4,
    nodes: ['email', 'compose', 'conversations', 'labels', 'multiAccount', 'contacts', 'tasks', 'search', 'calendar', 'security'],
  },
  {
    id: 'intermediate',
    icon: 'engineering',
    color: 'from-violet-500 to-purple-500',
    bgLight: 'bg-violet-50',
    bgDark: 'dark:bg-violet-500/10',
    borderActive: 'border-violet-500',
    textColor: 'text-violet-600 dark:text-violet-400',
    durationMinutes: 6,
    nodes: ['boards', 'chat', 'drive', 'video', 'huddles', 'moodboards', 'colleagues', 'tracking', 'reactions', 'ai'],
  },
  {
    id: 'advanced',
    icon: 'payments',
    color: 'from-amber-500 to-orange-500',
    bgLight: 'bg-amber-50',
    bgDark: 'dark:bg-amber-500/10',
    borderActive: 'border-amber-500',
    textColor: 'text-amber-600 dark:text-amber-400',
    durationMinutes: 8,
    nodes: ['crmClient', 'portal', 'pipelines', 'automations', 'boardAuto', 'sequences', 'time', 'report', 'invoice', 'campaigns', 'mailingLists', 'dashboard'],
  },
]

function select(tierId) {
  emit('select', tierId)
}
</script>

<template>
  <div class="flex flex-col items-center justify-start sm:justify-center h-full p-4 sm:p-6 overflow-auto">
    <!-- Header -->
    <div class="text-center mb-6 sm:mb-10 max-w-xl">
      <div class="w-12 h-12 sm:w-16 sm:h-16 rounded-2xl bg-gradient-to-br from-primary-500 to-violet-500 flex items-center justify-center mx-auto mb-3 sm:mb-5 shadow-lg shadow-primary-500/20">
        <span class="material-symbols-rounded text-2xl sm:text-3xl text-white">school</span>
      </div>
      <h2 class="text-xl sm:text-2xl font-bold text-surface-800 dark:text-surface-100 mb-1.5 sm:mb-2">
        {{ t('onboarding.tierSelect.title') }}
      </h2>
      <p class="text-xs sm:text-sm text-surface-500 dark:text-surface-400 leading-relaxed">
        {{ t('onboarding.tierSelect.subtitle') }}
      </p>
    </div>

    <!-- Tier cards -->
    <div class="grid grid-cols-1 md:grid-cols-3 gap-3 sm:gap-5 max-w-4xl w-full pb-4 sm:pb-0">
      <button
        v-for="tier in tiers"
        :key="tier.id"
        @click="select(tier.id)"
        class="group relative text-left rounded-2xl border-2 p-4 sm:p-6 transition-all duration-300 hover:shadow-xl hover:-translate-y-1"
        :class="[
          store.isTierCompleted(tier.id)
            ? 'border-green-300 dark:border-green-500/30 bg-green-50/50 dark:bg-green-500/5'
            : 'border-surface-200 dark:border-surface-700 bg-white dark:bg-surface-800 hover:border-surface-300 dark:hover:border-surface-600',
        ]"
      >
        <!-- Completed badge -->
        <div
          v-if="store.isTierCompleted(tier.id)"
          class="absolute top-3 right-3 flex items-center gap-1 text-[10px] font-semibold text-green-600 dark:text-green-400 bg-green-100 dark:bg-green-500/20 px-2 py-0.5 rounded-full"
        >
          <span class="material-symbols-rounded" style="font-size: 12px">check_circle</span>
          {{ t('onboarding.tierSelect.completed') }}
        </div>

        <!-- Icon -->
        <div
          :class="['w-10 h-10 sm:w-12 sm:h-12 rounded-xl flex items-center justify-center mb-3 sm:mb-4 bg-gradient-to-br shadow-md', tier.color]"
        >
          <span class="material-symbols-rounded text-xl sm:text-2xl text-white">{{ tier.icon }}</span>
        </div>

        <!-- Title & description -->
        <h3 class="text-base sm:text-lg font-bold text-surface-800 dark:text-surface-100 mb-1">
          {{ t(`onboarding.tierSelect.${tier.id}.title`) }}
        </h3>
        <p class="text-[11px] sm:text-xs text-surface-500 dark:text-surface-400 leading-relaxed mb-3 sm:mb-4">
          {{ t(`onboarding.tierSelect.${tier.id}.description`) }}
        </p>

        <!-- Feature preview chips -->
        <div class="flex flex-wrap gap-1 sm:gap-1.5 mb-3 sm:mb-4">
          <span
            v-for="(node, i) in tier.nodes.slice(0, 6)"
            :key="node"
            :class="['text-[10px] font-medium px-2 py-0.5 rounded-full', tier.bgLight, tier.bgDark, tier.textColor]"
          >{{ t(`onboarding.tierSelect.${tier.id}.features.${i}`) }}</span>
          <span
            v-if="tier.nodes.length > 6"
            class="text-[10px] font-medium px-2 py-0.5 rounded-full bg-surface-100 dark:bg-surface-700 text-surface-400"
          >{{ t('onboarding.tierSelect.more', { count: tier.nodes.length - 6 }) }}</span>
        </div>

        <!-- Footer -->
        <div class="flex items-center justify-between">
          <span class="text-xs text-surface-400 flex items-center gap-1">
            <span class="material-symbols-rounded text-sm">schedule</span>
            {{ t('onboarding.tierSelect.duration', { minutes: tier.durationMinutes }) }}
          </span>
          <span
            :class="['inline-flex items-center gap-1 text-xs font-semibold transition-colors', tier.textColor]"
          >
            {{ store.isTierCompleted(tier.id) ? t('onboarding.tierSelect.retake') : t('onboarding.tierSelect.start') }}
            <span class="material-symbols-rounded text-sm group-hover:translate-x-0.5 transition-transform">arrow_forward</span>
          </span>
        </div>
      </button>
    </div>
  </div>
</template>
