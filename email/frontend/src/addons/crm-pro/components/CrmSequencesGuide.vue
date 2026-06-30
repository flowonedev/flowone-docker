<script setup>
/**
 * CrmSequencesGuide — Visual step-by-step explanation of
 * CRM Pro email sequences (drip campaigns).
 *
 * Shown inside the CRM Sequences view. Dismissable via localStorage.
 */
import { ref, computed } from 'vue'
import { useI18n } from 'vue-i18n'

const emit = defineEmits(['close'])
const { t } = useI18n()

const currentStep = ref(0)

const steps = computed(() => [
  {
    icon: 'route',
    title: t('stepGuide.crmSequencesGuide.s1title'),
    color: 'purple',
    description: t('stepGuide.crmSequencesGuide.s1desc'),
    example: t('stepGuide.crmSequencesGuide.s1example'),
    visual: [
      { label: t('stepGuide.crmSequencesGuide.s1v1'), icon: 'mail', accent: 'blue' },
      { label: t('stepGuide.crmSequencesGuide.s1v2'), icon: 'schedule', accent: 'surface', arrow: true },
      { label: t('stepGuide.crmSequencesGuide.s1v3'), icon: 'mail', accent: 'blue', arrow: true },
      { label: t('stepGuide.crmSequencesGuide.s1v4'), icon: 'schedule', accent: 'surface', arrow: true },
      { label: t('stepGuide.crmSequencesGuide.s1v5'), icon: 'mail', accent: 'blue', arrow: true },
    ]
  },
  {
    icon: 'edit_note',
    title: t('stepGuide.crmSequencesGuide.s2title'),
    color: 'blue',
    description: t('stepGuide.crmSequencesGuide.s2desc'),
    example: t('stepGuide.crmSequencesGuide.s2example'),
    visual: [
      { label: t('stepGuide.crmSequencesGuide.s2v1'), icon: 'edit', accent: 'blue' },
      { label: t('stepGuide.crmSequencesGuide.s2v2'), icon: 'playlist_add', accent: 'purple', arrow: true },
      { label: t('stepGuide.crmSequencesGuide.s2v3'), icon: 'timer', accent: 'amber', arrow: true },
    ]
  },
  {
    icon: 'person_add',
    title: t('stepGuide.crmSequencesGuide.s3title'),
    color: 'green',
    description: t('stepGuide.crmSequencesGuide.s3desc'),
    example: t('stepGuide.crmSequencesGuide.s3example'),
    visual: [
      { label: t('stepGuide.crmSequencesGuide.s3v1'), icon: 'person_add', accent: 'green' },
      { label: t('stepGuide.crmSequencesGuide.s3v2'), icon: 'compare_arrows', accent: 'surface', arrow: true },
      { label: t('stepGuide.crmSequencesGuide.s3v3'), icon: 'smart_toy', accent: 'purple', arrow: true },
    ]
  },
  {
    icon: 'monitoring',
    title: t('stepGuide.crmSequencesGuide.s4title'),
    color: 'amber',
    description: t('stepGuide.crmSequencesGuide.s4desc'),
    example: t('stepGuide.crmSequencesGuide.s4example'),
    visual: [
      { label: t('stepGuide.crmSequencesGuide.s4v1'), icon: 'check_circle', accent: 'green' },
      { label: t('stepGuide.crmSequencesGuide.s4v2'), icon: 'bolt', accent: 'amber', arrow: true },
      { label: t('stepGuide.crmSequencesGuide.s4v3'), icon: 'hourglass_top', accent: 'surface', arrow: true },
    ]
  },
  {
    icon: 'handshake',
    title: t('stepGuide.crmSequencesGuide.s5title'),
    color: 'blue',
    description: t('stepGuide.crmSequencesGuide.s5desc'),
    example: t('stepGuide.crmSequencesGuide.s5example'),
    visual: [
      { label: t('stepGuide.crmSequencesGuide.s5v1'), icon: 'description', accent: 'blue' },
      { label: t('stepGuide.crmSequencesGuide.s5v2'), icon: 'bolt', accent: 'amber', arrow: true },
      { label: t('stepGuide.crmSequencesGuide.s5v3'), icon: 'forward_to_inbox', accent: 'purple', arrow: true },
    ]
  },
  {
    icon: 'campaign',
    title: t('stepGuide.crmSequencesGuide.s6title'),
    color: 'green',
    description: t('stepGuide.crmSequencesGuide.s6desc'),
    example: t('stepGuide.crmSequencesGuide.s6example'),
    visual: [
      { label: t('stepGuide.crmSequencesGuide.s6v1'), icon: 'emoji_events', accent: 'green' },
      { label: t('stepGuide.crmSequencesGuide.s6v2'), icon: 'bolt', accent: 'amber', arrow: true },
      { label: t('stepGuide.crmSequencesGuide.s6v3'), icon: 'route', accent: 'purple', arrow: true },
    ]
  },
  {
    icon: 'tips_and_updates',
    title: t('stepGuide.crmSequencesGuide.s7title'),
    color: 'primary',
    description: t('stepGuide.crmSequencesGuide.s7desc'),
    example: t('stepGuide.crmSequencesGuide.s7example'),
    visual: [
      { label: t('stepGuide.crmSequencesGuide.s7v1'), icon: 'bolt', accent: 'amber' },
      { label: t('stepGuide.crmSequencesGuide.s7v2'), icon: 'star', accent: 'green', arrow: true },
      { label: t('stepGuide.crmSequencesGuide.s7v3'), icon: 'smart_toy', accent: 'purple', arrow: true },
    ]
  },
])

const step = computed(() => steps.value[currentStep.value])
const isFirst = computed(() => currentStep.value === 0)
const isLast = computed(() => currentStep.value === steps.value.length - 1)

function next() {
  if (!isLast.value) currentStep.value++
}

function prev() {
  if (!isFirst.value) currentStep.value--
}

function goToStep(idx) {
  currentStep.value = idx
}

function dismiss() {
  localStorage.setItem('crm_sequences_guide_dismissed', '1')
  emit('close')
}

function close() {
  emit('close')
}

function accentClasses(accent) {
  const map = {
    primary: 'bg-primary-100 dark:bg-primary-900/30 text-primary-600 dark:text-primary-400 border-primary-200 dark:border-primary-800',
    amber: 'bg-amber-100 dark:bg-amber-900/30 text-amber-600 dark:text-amber-400 border-amber-200 dark:border-amber-800',
    blue: 'bg-blue-100 dark:bg-blue-900/30 text-blue-600 dark:text-blue-400 border-blue-200 dark:border-blue-800',
    green: 'bg-green-100 dark:bg-green-900/30 text-green-600 dark:text-green-400 border-green-200 dark:border-green-800',
    red: 'bg-red-100 dark:bg-red-900/30 text-red-600 dark:text-red-400 border-red-200 dark:border-red-800',
    purple: 'bg-purple-100 dark:bg-purple-900/30 text-purple-600 dark:text-purple-400 border-purple-200 dark:border-purple-800',
    surface: 'bg-surface-100 dark:bg-surface-700 text-surface-600 dark:text-surface-400 border-surface-200 dark:border-surface-600',
  }
  return map[accent] || map.surface
}

function stepAccentColor(color) {
  const map = {
    primary: 'text-primary-500', amber: 'text-amber-500', blue: 'text-blue-500',
    green: 'text-green-500', purple: 'text-purple-500', red: 'text-red-500',
  }
  return map[color] || 'text-primary-500'
}

function stepBgColor(color) {
  const map = {
    primary: 'bg-primary-50 dark:bg-primary-900/20', amber: 'bg-amber-50 dark:bg-amber-900/20',
    blue: 'bg-blue-50 dark:bg-blue-900/20', green: 'bg-green-50 dark:bg-green-900/20',
    purple: 'bg-purple-50 dark:bg-purple-900/20', red: 'bg-red-50 dark:bg-red-900/20',
  }
  return map[color] || 'bg-primary-50 dark:bg-primary-900/20'
}
</script>

<template>
  <Teleport to="body">
    <Transition name="fade">
      <div
        class="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4"
        @click.self="close"
      >
        <div class="bg-white dark:bg-surface-800 rounded-2xl shadow-2xl w-full max-w-2xl max-h-[90vh] flex flex-col overflow-hidden">
          <!-- Header -->
          <div class="flex items-center justify-between px-6 py-4 border-b border-surface-200 dark:border-surface-700">
            <div class="flex items-center gap-3">
              <div class="w-9 h-9 rounded-xl bg-purple-100 dark:bg-purple-900/30 flex items-center justify-center">
                <span class="material-symbols-rounded text-purple-500">route</span>
              </div>
              <div>
                <h2 class="text-base font-semibold text-surface-900 dark:text-surface-100">{{ t('stepGuide.crmSequencesGuide.title') }}</h2>
                <p class="text-xs text-surface-500">{{ t('stepGuide.crmSequencesGuide.subtitle', { count: steps.length }) }}</p>
              </div>
            </div>
            <button
              @click="close"
              class="p-2 rounded-lg hover:bg-surface-100 dark:hover:bg-surface-700 text-surface-400 hover:text-surface-600 dark:hover:text-surface-300 transition-colors"
            >
              <span class="material-symbols-rounded text-xl">close</span>
            </button>
          </div>

          <!-- Step progress dots -->
          <div class="flex items-center justify-center gap-1.5 px-6 py-3 bg-surface-50 dark:bg-surface-900/50 overflow-x-auto">
            <button
              v-for="(s, idx) in steps"
              :key="idx"
              @click="goToStep(idx)"
              class="group flex items-center gap-1"
            >
              <div
                :class="[
                  'w-6 h-6 rounded-full flex items-center justify-center text-[10px] font-semibold transition-all flex-shrink-0',
                  idx === currentStep
                    ? 'bg-primary-500 text-white scale-110 shadow-md'
                    : idx < currentStep
                      ? 'bg-green-100 dark:bg-green-900/30 text-green-600 dark:text-green-400'
                      : 'bg-surface-200 dark:bg-surface-700 text-surface-500'
                ]"
              >
                <span v-if="idx < currentStep" class="material-symbols-rounded text-xs">check</span>
                <span v-else>{{ idx + 1 }}</span>
              </div>
              <div
                v-if="idx < steps.length - 1"
                :class="[
                  'w-3 h-0.5 rounded-full transition-colors flex-shrink-0',
                  idx < currentStep ? 'bg-green-400' : 'bg-surface-300 dark:bg-surface-600'
                ]"
              ></div>
            </button>
          </div>

          <!-- Step content -->
          <div class="flex-1 overflow-y-auto px-6 py-5">
            <Transition name="slide" mode="out-in">
              <div :key="currentStep" class="space-y-5">
                <!-- Step icon + title -->
                <div class="flex items-start gap-4">
                  <div :class="['w-12 h-12 rounded-2xl flex items-center justify-center flex-shrink-0', stepBgColor(step.color)]">
                    <span :class="['material-symbols-rounded text-2xl', stepAccentColor(step.color)]">{{ step.icon }}</span>
                  </div>
                  <div class="flex-1 min-w-0">
                    <div class="flex items-center gap-2 flex-wrap">
                      <h3 class="text-lg font-semibold text-surface-900 dark:text-surface-100">{{ step.title }}</h3>
                      <span
                        v-if="step.badge"
                        class="text-[10px] font-semibold px-2 py-0.5 rounded-full bg-purple-100 dark:bg-purple-900/30 text-purple-600 dark:text-purple-400"
                      >{{ step.badge }}</span>
                    </div>
                    <p class="text-sm text-surface-600 dark:text-surface-400 mt-1 leading-relaxed">{{ step.description }}</p>
                  </div>
                </div>

                <!-- Visual flow -->
                <div class="flex items-center justify-center gap-1 py-4 flex-wrap">
                  <template v-for="(node, idx) in step.visual" :key="idx">
                    <div v-if="node.arrow" class="flex items-center px-0.5">
                      <span class="material-symbols-rounded text-surface-400 text-base">arrow_forward</span>
                    </div>
                    <div :class="['flex flex-col items-center gap-1.5 px-2.5 py-2.5 rounded-xl border min-w-[72px]', accentClasses(node.accent)]">
                      <span class="material-symbols-rounded text-lg">{{ node.icon }}</span>
                      <span class="text-[10px] font-semibold">{{ node.label }}</span>
                    </div>
                  </template>
                </div>

                <!-- Example callout -->
                <div class="flex items-start gap-3 p-4 rounded-xl bg-surface-50 dark:bg-surface-900/50 border border-surface-200 dark:border-surface-700">
                  <span class="material-symbols-rounded text-base text-surface-400 mt-0.5 flex-shrink-0">lightbulb</span>
                  <p class="text-sm text-surface-600 dark:text-surface-400 leading-relaxed">{{ step.example }}</p>
                </div>
              </div>
            </Transition>
          </div>

          <!-- Footer navigation -->
          <div class="flex items-center justify-between px-6 py-4 border-t border-surface-200 dark:border-surface-700 bg-surface-50 dark:bg-surface-900/50">
            <button @click="dismiss" class="text-xs text-surface-400 hover:text-surface-600 dark:hover:text-surface-300 transition-colors">
              {{ t('stepGuide.common.dontShowAgain') }}
            </button>
            <div class="flex items-center gap-2">
              <button
                v-if="!isFirst"
                @click="prev"
                class="flex items-center gap-1 px-4 py-2 text-sm font-medium text-surface-600 dark:text-surface-400 hover:bg-surface-100 dark:hover:bg-surface-700 rounded-full transition-colors"
              >
                <span class="material-symbols-rounded text-base">arrow_back</span>
                {{ t('stepGuide.common.back') }}
              </button>
              <button
                v-if="!isLast"
                @click="next"
                class="flex items-center gap-1 px-4 py-2 text-sm font-medium bg-primary-500 text-white rounded-full hover:bg-primary-600 transition-colors"
              >
                {{ t('stepGuide.common.next') }}
                <span class="material-symbols-rounded text-base">arrow_forward</span>
              </button>
              <button
                v-else
                @click="close"
                class="flex items-center gap-1 px-4 py-2 text-sm font-medium bg-green-500 text-white rounded-full hover:bg-green-600 transition-colors"
              >
                <span class="material-symbols-rounded text-base">check</span>
                {{ t('stepGuide.common.gotIt') }}
              </button>
            </div>
          </div>
        </div>
      </div>
    </Transition>
  </Teleport>
</template>

<style scoped>
.fade-enter-active, .fade-leave-active { transition: opacity 0.2s ease; }
.fade-enter-from, .fade-leave-to { opacity: 0; }
.slide-enter-active, .slide-leave-active { transition: all 0.25s ease; }
.slide-enter-from { opacity: 0; transform: translateX(20px); }
.slide-leave-to { opacity: 0; transform: translateX(-20px); }
</style>

