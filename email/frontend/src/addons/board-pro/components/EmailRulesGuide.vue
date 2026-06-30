<script setup>
/**
 * EmailRulesGuide — Visual step-by-step explanation of
 * Board Pro email auto-link rules: how emails get routed to cards.
 *
 * Shown inside the Email Rules panel. Dismissable via localStorage.
 */
import { ref, computed } from 'vue'
import { useI18n } from 'vue-i18n'

const emit = defineEmits(['close'])
const { t } = useI18n()

const currentStep = ref(0)

const steps = computed(() => [
  {
    icon: 'mark_email_read',
    title: t('stepGuide.emailRulesGuide.s1title'),
    color: 'blue',
    description: t('stepGuide.emailRulesGuide.s1desc'),
    example: t('stepGuide.emailRulesGuide.s1example'),
    visual: [
      { label: t('stepGuide.emailRulesGuide.s1v1'), icon: 'mail', accent: 'blue' },
      { label: t('stepGuide.emailRulesGuide.s1v2'), icon: 'rule', accent: 'amber', arrow: true },
      { label: t('stepGuide.emailRulesGuide.s1v3'), icon: 'assignment', accent: 'green', arrow: true },
    ]
  },
  {
    icon: 'filter_alt',
    title: t('stepGuide.emailRulesGuide.s2title'),
    color: 'purple',
    description: t('stepGuide.emailRulesGuide.s2desc'),
    example: t('stepGuide.emailRulesGuide.s2example'),
    visual: [
      { label: t('stepGuide.emailRulesGuide.s2v1'), icon: 'subject', accent: 'purple', sub: t('stepGuide.emailRulesGuide.s2v1sub') },
      { label: t('stepGuide.emailRulesGuide.s2v2'), icon: 'language', accent: 'blue', sub: t('stepGuide.emailRulesGuide.s2v2sub'), arrow: true },
      { label: t('stepGuide.emailRulesGuide.s2v3'), icon: 'person', accent: 'green', sub: t('stepGuide.emailRulesGuide.s2v3sub'), arrow: true },
    ]
  },
  {
    icon: 'add_card',
    title: t('stepGuide.emailRulesGuide.s3title'),
    color: 'green',
    description: t('stepGuide.emailRulesGuide.s3desc'),
    example: t('stepGuide.emailRulesGuide.s3example'),
    visual: [
      { label: t('stepGuide.emailRulesGuide.s3v1'), icon: 'mail', accent: 'blue' },
      { label: t('stepGuide.emailRulesGuide.s3v2'), icon: 'bolt', accent: 'amber', arrow: true },
      { label: t('stepGuide.emailRulesGuide.s3v3'), icon: 'add_card', accent: 'green', sub: t('stepGuide.emailRulesGuide.s3v3sub'), arrow: true },
    ]
  },
  {
    icon: 'link',
    title: t('stepGuide.emailRulesGuide.s4title'),
    color: 'primary',
    description: t('stepGuide.emailRulesGuide.s4desc'),
    example: t('stepGuide.emailRulesGuide.s4example'),
    visual: [
      { label: t('stepGuide.emailRulesGuide.s4v1'), icon: 'reply', accent: 'blue' },
      { label: t('stepGuide.emailRulesGuide.s4v2'), icon: 'search', accent: 'purple', arrow: true },
      { label: t('stepGuide.emailRulesGuide.s4v3'), icon: 'link', accent: 'primary', sub: t('stepGuide.emailRulesGuide.s4v3sub'), arrow: true },
    ]
  },
  {
    icon: 'person_add',
    title: t('stepGuide.emailRulesGuide.s5title'),
    color: 'amber',
    description: t('stepGuide.emailRulesGuide.s5desc'),
    example: t('stepGuide.emailRulesGuide.s5example'),
    visual: [
      { label: t('stepGuide.emailRulesGuide.s5v1'), icon: 'filter_alt', accent: 'purple' },
      { label: t('stepGuide.emailRulesGuide.s5v2'), icon: 'view_kanban', accent: 'blue', arrow: true },
      { label: t('stepGuide.emailRulesGuide.s5v3'), icon: 'person_add', accent: 'amber', arrow: true },
    ]
  },
  {
    icon: 'stacked_email',
    title: t('stepGuide.emailRulesGuide.s6title'),
    color: 'green',
    description: t('stepGuide.emailRulesGuide.s6desc'),
    example: t('stepGuide.emailRulesGuide.s6example'),
    visual: [
      { label: t('stepGuide.emailRulesGuide.s6v1'), icon: 'domain', accent: 'blue', sub: t('stepGuide.emailRulesGuide.s6v1sub') },
      { label: t('stepGuide.emailRulesGuide.s6v2'), icon: 'domain', accent: 'purple', sub: t('stepGuide.emailRulesGuide.s6v2sub'), arrow: true },
      { label: t('stepGuide.emailRulesGuide.s6v3'), icon: 'inventory_2', accent: 'green', sub: t('stepGuide.emailRulesGuide.s6v3sub'), arrow: true },
    ]
  },
  {
    icon: 'toggle_on',
    title: t('stepGuide.emailRulesGuide.s7title'),
    color: 'primary',
    description: t('stepGuide.emailRulesGuide.s7desc'),
    example: t('stepGuide.emailRulesGuide.s7example'),
    visual: [
      { label: t('stepGuide.emailRulesGuide.s7v1'), icon: 'toggle_on', accent: 'green', sub: t('stepGuide.emailRulesGuide.s7v1sub') },
      { label: t('stepGuide.emailRulesGuide.s7v2'), icon: 'toggle_off', accent: 'surface', sub: t('stepGuide.emailRulesGuide.s7v2sub'), arrow: true },
      { label: t('stepGuide.emailRulesGuide.s7v3'), icon: 'delete', accent: 'red', sub: t('stepGuide.emailRulesGuide.s7v3sub'), arrow: true },
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
  localStorage.setItem('email_rules_guide_dismissed', '1')
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
              <div class="w-9 h-9 rounded-xl bg-blue-100 dark:bg-blue-900/30 flex items-center justify-center">
                <span class="material-symbols-rounded text-blue-500">mark_email_read</span>
              </div>
              <div>
                <h2 class="text-base font-semibold text-surface-900 dark:text-surface-100">{{ t('stepGuide.emailRulesGuide.title') }}</h2>
                <p class="text-xs text-surface-500">{{ t('stepGuide.emailRulesGuide.subtitle', { count: steps.length }) }}</p>
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
          <div class="flex items-center justify-center gap-2 px-6 py-3 bg-surface-50 dark:bg-surface-900/50">
            <button
              v-for="(s, idx) in steps"
              :key="idx"
              @click="goToStep(idx)"
              class="group flex items-center gap-1.5"
            >
              <div 
                :class="[
                  'w-7 h-7 rounded-full flex items-center justify-center text-xs font-semibold transition-all',
                  idx === currentStep
                    ? 'bg-primary-500 text-white scale-110 shadow-md'
                    : idx < currentStep
                      ? 'bg-green-100 dark:bg-green-900/30 text-green-600 dark:text-green-400'
                      : 'bg-surface-200 dark:bg-surface-700 text-surface-500'
                ]"
              >
                <span v-if="idx < currentStep" class="material-symbols-rounded text-sm">check</span>
                <span v-else>{{ idx + 1 }}</span>
              </div>
              <div 
                v-if="idx < steps.length - 1"
                :class="[
                  'w-4 h-0.5 rounded-full transition-colors',
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
                <div class="flex items-center justify-center gap-1 py-4">
                  <template v-for="(node, idx) in step.visual" :key="idx">
                    <div v-if="node.arrow" class="flex items-center px-1">
                      <span class="material-symbols-rounded text-surface-400 text-lg">arrow_forward</span>
                    </div>
                    <div :class="['flex flex-col items-center gap-1.5 px-3 py-3 rounded-xl border min-w-[90px]', accentClasses(node.accent)]">
                      <span class="material-symbols-rounded text-xl">{{ node.icon }}</span>
                      <span class="text-xs font-semibold">{{ node.label }}</span>
                      <span v-if="node.sub" class="text-[10px] opacity-75">{{ node.sub }}</span>
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
