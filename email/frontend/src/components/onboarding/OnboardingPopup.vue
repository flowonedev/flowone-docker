<script setup>
import { ref, computed, watch, defineAsyncComponent, onMounted, onUnmounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { useOnboardingStore } from '@/stores/onboarding'
import { getModel, getStepKey } from './model'
import { boldKeywords } from './model/shared'
import Modal from '@/components/shared/Modal.vue'
import OnboardingTierSelect from './OnboardingTierSelect.vue'
import OnboardingQuiz from './OnboardingQuiz.vue'

const BeginnerDiagram = defineAsyncComponent(() => import('./tiers/BeginnerDiagram.vue'))
const IntermediateDiagram = defineAsyncComponent(() => import('./tiers/IntermediateDiagram.vue'))
const AdvancedDiagram = defineAsyncComponent(() => import('./tiers/AdvancedDiagram.vue'))

const { t } = useI18n()
const store = useOnboardingStore()

const currentStep = ref(0)
const showQuiz = ref(false)
const isMobile = ref(false)

function checkMobile() {
  isMobile.value = window.innerWidth < 640
}
onMounted(() => { checkMobile(); window.addEventListener('resize', checkMobile) })
onUnmounted(() => window.removeEventListener('resize', checkMobile))

const activeTier = computed(() => store.selectedTier)
const model = computed(() => getModel(activeTier.value))
const TOTAL_STEPS = computed(() => model.value?.totalSteps ?? 12)

const diagramComponent = computed(() => {
  switch (activeTier.value) {
    case 'intermediate': return IntermediateDiagram
    case 'advanced': return AdvancedDiagram
    default: return BeginnerDiagram
  }
})

const currentStepKey = computed(() => {
  if (!model.value) return null
  return getStepKey(model.value, currentStep.value)
})

const stepTitle = computed(() => {
  const key = currentStepKey.value
  if (!key) return ''
  return t(`onboarding.${activeTier.value}.steps.${key}.title`)
})

const stepDescription = computed(() => {
  const key = currentStepKey.value
  if (!key) return ''
  return t(`onboarding.${activeTier.value}.steps.${key}.description`)
})

const isFirst = computed(() => currentStep.value <= 1)
const isLast = computed(() => currentStep.value >= TOTAL_STEPS.value)

function next() { if (currentStep.value < TOTAL_STEPS.value) currentStep.value++ }
function prev() { if (currentStep.value > 1) currentStep.value-- }

function close() { store.close() }

function onTierSelect(tier) {
  store.selectTier(tier)
  currentStep.value = 1
  showQuiz.value = false
}

function backToTiers() {
  store.backToTierSelect()
  currentStep.value = 0
  showQuiz.value = false
}

function startQuiz() { showQuiz.value = true }

function onQuizComplete(result) {
  store.completeTier(activeTier.value)
  store.saveQuizScore(result.score, result.total, result.percent)
}

function onQuizSkip() {
  store.completeTier(activeTier.value)
  showQuiz.value = false
  store.close()
}

watch(() => store.isOpen, (open) => {
  if (open) {
    currentStep.value = store.selectedTier ? 1 : 0
    showQuiz.value = false
  }
})

function onKeydown(e) {
  if (!store.isOpen || showQuiz.value || !activeTier.value) return
  if (e.key === 'ArrowRight' || e.key === 'ArrowDown') { e.preventDefault(); next() }
  else if (e.key === 'ArrowLeft' || e.key === 'ArrowUp') { e.preventDefault(); prev() }
}

const headerIcon = computed(() => {
  if (showQuiz.value) return 'quiz'
  if (!activeTier.value) return 'school'
  return model.value?.tierIcon ?? 'school'
})

const headerColor = computed(() => {
  return activeTier.value && model.value ? model.value.tierColor : 'text-primary-500'
})

// Touch swipe support
const touchStartX = ref(0)
const touchStartY = ref(0)
const isSwiping = ref(false)

function onTouchStart(e) {
  if (showQuiz.value || !activeTier.value) return
  touchStartX.value = e.touches[0].clientX
  touchStartY.value = e.touches[0].clientY
  isSwiping.value = true
}

function onTouchEnd(e) {
  if (!isSwiping.value) return
  isSwiping.value = false
  const dx = e.changedTouches[0].clientX - touchStartX.value
  const dy = e.changedTouches[0].clientY - touchStartY.value
  if (Math.abs(dx) < 50 || Math.abs(dy) > Math.abs(dx)) return
  if (dx < 0) next()
  else prev()
}

const progressPercent = computed(() => {
  if (TOTAL_STEPS.value <= 0) return 0
  return Math.round((currentStep.value / TOTAL_STEPS.value) * 100)
})
</script>

<template>
  <Modal :show="store.isOpen" size="fullscreen" :closable="true" @close="close">
    <template #header>
      <div class="flex items-center justify-between w-full px-1 sm:px-2">
        <div class="flex items-center gap-1.5 sm:gap-2 min-w-0">
          <button
            v-if="activeTier && !showQuiz"
            @click="backToTiers"
            class="w-8 h-8 flex items-center justify-center rounded-lg text-surface-400 hover:text-surface-600 dark:hover:text-surface-200 hover:bg-surface-100 dark:hover:bg-surface-700 transition-colors flex-shrink-0"
            :title="t('onboarding.buttons.backToTiers')"
          >
            <span class="material-symbols-rounded text-xl">arrow_back</span>
          </button>
          <span :class="['material-symbols-rounded text-lg sm:text-xl flex-shrink-0', headerColor]">
            {{ headerIcon }}
          </span>
          <h3 class="text-sm sm:text-base font-semibold text-surface-800 dark:text-surface-100 truncate">
            {{ !activeTier ? t('onboarding.tierSelect.title') : showQuiz ? t('onboarding.quiz.title') : t(`onboarding.tierSelect.${activeTier}.title`) }}
          </h3>
        </div>
        <div class="flex items-center gap-2 sm:gap-3 flex-shrink-0">
          <span v-if="activeTier && !showQuiz && currentStep >= 1" class="text-[10px] sm:text-xs text-surface-400 dark:text-surface-500 hidden sm:inline">
            {{ t('onboarding.stepOf', { current: currentStep, total: TOTAL_STEPS }) }}
          </span>
          <span v-if="activeTier && !showQuiz && currentStep >= 1 && isMobile" class="text-[10px] text-surface-400 dark:text-surface-500">
            {{ currentStep }}/{{ TOTAL_STEPS }}
          </span>
          <button @click="close" class="w-8 h-8 flex items-center justify-center rounded-lg text-surface-400 hover:text-surface-600 dark:hover:text-surface-200 hover:bg-surface-100 dark:hover:bg-surface-700 transition-colors">
            <span class="material-symbols-rounded text-xl">close</span>
          </button>
        </div>
      </div>
    </template>

    <OnboardingTierSelect
      v-if="!activeTier && !showQuiz"
      @select="onTierSelect"
    />

    <OnboardingQuiz
      v-else-if="showQuiz"
      :tier="activeTier"
      @complete="onQuizComplete"
      @skip="onQuizSkip"
    />

    <div v-else class="flex flex-col h-full" @keydown="onKeydown" @touchstart.passive="onTouchStart" @touchend.passive="onTouchEnd" tabindex="0">
      <div class="flex-1 relative min-h-0 overflow-y-auto sm:overflow-hidden">
        <!-- Welcome overlay (step 1) -->
        <Transition enter-active-class="transition-opacity duration-500" enter-from-class="opacity-0" leave-active-class="transition-opacity duration-300" leave-to-class="opacity-0">
          <div v-if="currentStep === 1" class="absolute inset-0 z-20 flex items-center justify-center overflow-y-auto">
            <div class="text-center max-w-lg px-4 sm:px-6 py-8 sm:py-0">
              <div :class="['w-16 h-16 sm:w-20 sm:h-20 rounded-2xl flex items-center justify-center mx-auto mb-4 sm:mb-6',
                activeTier === 'beginner' ? 'bg-blue-100 dark:bg-blue-900/40' :
                activeTier === 'intermediate' ? 'bg-violet-100 dark:bg-violet-900/40' :
                'bg-amber-100 dark:bg-amber-900/40']">
                <span :class="['material-symbols-rounded text-3xl sm:text-4xl',
                  activeTier === 'beginner' ? 'text-blue-500' :
                  activeTier === 'intermediate' ? 'text-violet-500' :
                  'text-amber-500']">{{ headerIcon }}</span>
              </div>
              <h2 class="text-xl sm:text-2xl font-bold text-surface-800 dark:text-surface-100 mb-2 sm:mb-3">{{ stepTitle }}</h2>
              <p class="text-sm sm:text-base text-surface-500 dark:text-surface-400 leading-relaxed" v-html="boldKeywords(stepDescription)"></p>
            </div>
          </div>
        </Transition>

        <!-- Summary overlay (last step) -->
        <Transition enter-active-class="transition-opacity duration-500" enter-from-class="opacity-0" leave-active-class="transition-opacity duration-300" leave-to-class="opacity-0">
          <div v-if="isLast" class="absolute inset-0 z-20 flex items-center justify-center pointer-events-none backdrop-blur-md bg-white/60 dark:bg-surface-900/60 overflow-y-auto">
            <div class="text-center max-w-lg px-4 sm:px-6 py-8 sm:py-0 pointer-events-auto">
              <div class="w-16 h-16 sm:w-20 sm:h-20 rounded-2xl bg-green-100 dark:bg-green-900/40 flex items-center justify-center mx-auto mb-4 sm:mb-6">
                <span class="material-symbols-rounded text-3xl sm:text-4xl text-green-500">check_circle</span>
              </div>
              <h2 class="text-xl sm:text-2xl font-bold text-surface-800 dark:text-surface-100 mb-2 sm:mb-3">{{ stepTitle }}</h2>
              <p class="text-sm sm:text-base text-surface-500 dark:text-surface-400 leading-relaxed mb-4 sm:mb-6">{{ stepDescription }}</p>
              <div class="flex flex-col sm:flex-row items-center justify-center gap-2 sm:gap-3">
                <button @click="startQuiz" class="w-full sm:w-auto inline-flex items-center justify-center gap-2 px-6 sm:px-8 py-2.5 sm:py-3 bg-primary-600 hover:bg-primary-700 text-white rounded-full font-medium text-sm transition-colors shadow-lg shadow-primary-500/25">
                  <span class="material-symbols-rounded text-lg">quiz</span>
                  {{ t('onboarding.buttons.startQuiz') }}
                </button>
                <button @click="backToTiers" class="w-full sm:w-auto inline-flex items-center justify-center gap-2 px-5 sm:px-6 py-2.5 sm:py-3 bg-surface-100 dark:bg-surface-800 hover:bg-surface-200 dark:hover:bg-surface-700 text-surface-600 dark:text-surface-300 rounded-full font-medium text-sm transition-colors">
                  <span class="material-symbols-rounded text-lg">school</span>
                  {{ t('onboarding.buttons.moreTiers') }}
                </button>
                <button @click="close" class="w-full sm:w-auto inline-flex items-center justify-center gap-2 px-5 sm:px-6 py-2.5 sm:py-3 text-surface-400 hover:text-surface-600 dark:hover:text-surface-300 rounded-full font-medium text-sm transition-colors">
                  {{ t('onboarding.buttons.skipQuiz') }}
                </button>
              </div>
            </div>
          </div>
        </Transition>

        <!-- Tier diagram -->
        <div :class="['absolute inset-0 transition-opacity duration-500',
          isMobile ? 'p-0 overflow-y-auto' : 'p-6',
          currentStep >= 2 && !isLast ? 'opacity-100' : isLast ? 'opacity-20' : 'opacity-0']">
          <component :is="diagramComponent" :currentStep="currentStep" />
        </div>
      </div>

      <!-- Bottom panel -->
      <div class="flex-shrink-0 border-t border-surface-200 dark:border-surface-700 bg-white/80 dark:bg-surface-900/80 backdrop-blur-sm">
        <!-- Step description (mobile: always show when in content steps, desktop: only mid-steps) -->
        <Transition enter-active-class="transition-all duration-300" enter-from-class="opacity-0 translate-y-2" leave-active-class="transition-all duration-200" leave-to-class="opacity-0 translate-y-2" mode="out-in">
          <div v-if="currentStep >= 2 && !isLast" :key="currentStep" class="px-4 sm:px-6 pt-3 sm:pt-4 pb-1 sm:pb-2">
            <h3 class="text-sm sm:text-base font-semibold text-surface-800 dark:text-surface-100 mb-0.5 sm:mb-1">{{ stepTitle }}</h3>
            <p class="text-xs sm:text-sm text-surface-500 dark:text-surface-400 leading-relaxed max-w-2xl" v-html="boldKeywords(stepDescription)"></p>
          </div>
        </Transition>

        <!-- Mobile: progress bar instead of dots -->
        <div v-if="isMobile" class="px-4 pt-2">
          <div class="h-1 bg-surface-200 dark:bg-surface-700 rounded-full overflow-hidden">
            <div class="h-full bg-primary-500 rounded-full transition-all duration-300" :style="{ width: progressPercent + '%' }"></div>
          </div>
        </div>

        <div class="flex items-center justify-between px-4 sm:px-6 py-2.5 sm:py-3">
          <button v-if="!isFirst" @click="prev" class="inline-flex items-center gap-1 sm:gap-1.5 px-3 sm:px-5 py-2 rounded-full text-xs sm:text-sm font-medium text-surface-600 dark:text-surface-300 bg-surface-100 dark:bg-surface-800 hover:bg-surface-200 dark:hover:bg-surface-700 transition-colors">
            <span class="material-symbols-rounded text-base">chevron_left</span>
            <span class="hidden sm:inline">{{ t('onboarding.buttons.previous') }}</span>
          </button>
          <div v-else></div>

          <!-- Desktop: step dots -->
          <div class="hidden sm:flex items-center gap-1.5">
            <button v-for="s in TOTAL_STEPS" :key="s" @click="currentStep = s"
              :class="['w-2 h-2 rounded-full transition-all duration-300',
                s === currentStep ? 'bg-primary-500 w-6' : s < currentStep ? 'bg-primary-300 dark:bg-primary-700' : 'bg-surface-300 dark:bg-surface-600']"
            />
          </div>

          <div class="flex items-center gap-1.5 sm:gap-2">
            <button v-if="!isLast && currentStep > 1" @click="close" class="px-3 sm:px-4 py-2 rounded-full text-[10px] sm:text-xs font-medium text-surface-400 hover:text-surface-600 dark:hover:text-surface-300 transition-colors">
              {{ t('onboarding.buttons.skipTour') }}
            </button>
            <button v-if="!isLast" @click="next" class="inline-flex items-center gap-1 sm:gap-1.5 px-4 sm:px-5 py-2 rounded-full text-xs sm:text-sm font-medium text-white bg-primary-600 hover:bg-primary-700 transition-colors shadow-md shadow-primary-500/20">
              {{ t('onboarding.buttons.next') }}
              <span class="material-symbols-rounded text-base">chevron_right</span>
            </button>
          </div>
        </div>
      </div>
    </div>
  </Modal>
</template>
