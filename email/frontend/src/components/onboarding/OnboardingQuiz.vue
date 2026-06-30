<script setup>
import { ref, computed, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { getModel } from './model'

const { t, tm, rt } = useI18n()

const props = defineProps({
  tier: { type: String, default: '' },
})

const emit = defineEmits(['complete', 'skip'])

const tierQuestionCounts = { beginner: 12, intermediate: 12, advanced: 12 }
const QUESTION_COUNT = 8

const model = computed(() => getModel(props.tier))

const quizPrefix = computed(() => {
  if (props.tier && ['beginner', 'intermediate', 'advanced'].includes(props.tier)) {
    return `onboarding.${props.tier}.quiz.questions`
  }
  return 'onboarding.quiz.questions'
})

const topicPrefix = computed(() => {
  if (props.tier && ['beginner', 'intermediate', 'advanced'].includes(props.tier)) {
    return `onboarding.${props.tier}.topics`
  }
  return null
})

const totalQuestionsInPool = computed(() => tierQuestionCounts[props.tier] || 18)

const allQuestionKeys = computed(() =>
  Array.from({ length: totalQuestionsInPool.value }, (_, i) => `q${i + 1}`)
)

function shuffle(arr) {
  const a = [...arr]
  for (let i = a.length - 1; i > 0; i--) {
    const j = Math.floor(Math.random() * (i + 1));
    [a[i], a[j]] = [a[j], a[i]]
  }
  return a
}

const selectedKeys = ref([])
const currentIndex = ref(0)
const selectedOption = ref(null)
const hasAnswered = ref(false)
const answers = ref([])
const phase = ref('quiz')

onMounted(() => {
  selectedKeys.value = shuffle(allQuestionKeys.value).slice(0, QUESTION_COUNT)
})

const currentKey = computed(() => selectedKeys.value[currentIndex.value])
const currentQuestion = computed(() => {
  if (!currentKey.value) return null
  const raw = tm(`${quizPrefix.value}.${currentKey.value}`)
  if (!raw || !raw.question) return null
  return {
    question: rt(raw.question),
    options: Array.isArray(raw.options) ? raw.options.map(o => rt(o)) : [],
    correct: Number(raw.correct),
  }
})

const isCorrect = computed(() => {
  if (!hasAnswered.value || selectedOption.value === null) return false
  return selectedOption.value === currentQuestion.value?.correct
})

const score = computed(() => answers.value.filter(a => a.correct).length)
const scorePercent = computed(() => Math.round((score.value / QUESTION_COUNT) * 100))

const scoreMessage = computed(() => {
  const pct = scorePercent.value
  if (pct === 100) return t('onboarding.quiz.scorePerfect')
  if (pct >= 70) return t('onboarding.quiz.scoreGreat')
  if (pct >= 40) return t('onboarding.quiz.scoreGood')
  return t('onboarding.quiz.scoreNeedsWork')
})

const scoreColor = computed(() => {
  const pct = scorePercent.value
  if (pct >= 80) return 'text-green-500'
  if (pct >= 50) return 'text-amber-500'
  return 'text-red-500'
})

const scoreRingColor = computed(() => {
  const pct = scorePercent.value
  if (pct >= 80) return '#22c55e'
  if (pct >= 50) return '#f59e0b'
  return '#ef4444'
})

function submitAnswer() {
  if (selectedOption.value === null) return
  hasAnswered.value = true
  answers.value.push({
    key: currentKey.value,
    selected: selectedOption.value,
    correct: selectedOption.value === currentQuestion.value.correct,
  })
}

function nextQuestion() {
  if (currentIndex.value < QUESTION_COUNT - 1) {
    currentIndex.value++
    selectedOption.value = null
    hasAnswered.value = false
  } else {
    phase.value = 'results'
    emit('complete', {
      score: score.value,
      total: QUESTION_COUNT,
      percent: scorePercent.value,
    })
  }
}

function retake() {
  selectedKeys.value = shuffle(allQuestionKeys.value).slice(0, QUESTION_COUNT)
  currentIndex.value = 0
  selectedOption.value = null
  hasAnswered.value = false
  answers.value = []
  phase.value = 'quiz'
}

const ringCircumference = 2 * Math.PI * 54
const ringOffset = computed(() => ringCircumference - (scorePercent.value / 100) * ringCircumference)

function questionText(answerKey) {
  const raw = tm(`${quizPrefix.value}.${answerKey}`)
  return raw?.question ? rt(raw.question) : answerKey
}

const topicBreakdown = computed(() => {
  if (!model.value?.quizTopicMap || !topicPrefix.value) return null
  const topicMap = model.value.quizTopicMap
  const grouped = {}

  for (const answer of answers.value) {
    const topic = topicMap[answer.key]
    if (!topic) continue
    if (!grouped[topic]) grouped[topic] = { correct: 0, total: 0 }
    grouped[topic].total++
    if (answer.correct) grouped[topic].correct++
  }

  return Object.entries(grouped).map(([key, data]) => ({
    key,
    label: t(`${topicPrefix.value}.${key}`),
    correct: data.correct,
    total: data.total,
    passed: data.correct === data.total,
  }))
})
</script>

<template>
  <div class="flex flex-col h-full">
    <!-- Quiz question phase -->
    <template v-if="phase === 'quiz' && currentQuestion">
      <div class="flex-1 flex items-center justify-center p-4 sm:p-6 overflow-y-auto">
        <div class="max-w-xl w-full">
          <!-- Progress bar -->
          <div class="h-1.5 bg-surface-200 dark:bg-surface-700 rounded-full mb-4 sm:mb-8 overflow-hidden">
            <div
              class="h-full bg-primary-500 rounded-full transition-all duration-500"
              :style="{ width: ((currentIndex + (hasAnswered ? 1 : 0)) / QUESTION_COUNT * 100) + '%' }"
            ></div>
          </div>

          <!-- Question counter -->
          <p class="text-xs text-surface-400 mb-2">
            {{ t('onboarding.quiz.questionOf', { current: currentIndex + 1, total: QUESTION_COUNT }) }}
          </p>

          <!-- Question text -->
          <h2 class="text-base sm:text-lg font-semibold text-surface-800 dark:text-surface-100 mb-4 sm:mb-6">
            {{ currentQuestion.question }}
          </h2>

          <!-- Options -->
          <div class="space-y-2 sm:space-y-3">
            <button
              v-for="(option, idx) in currentQuestion.options"
              :key="idx"
              @click="!hasAnswered && (selectedOption = idx)"
              :disabled="hasAnswered"
              :class="[
                'w-full text-left px-3 sm:px-4 py-2.5 sm:py-3 rounded-xl border-2 transition-all duration-200 flex items-center gap-2 sm:gap-3',
                hasAnswered && idx === currentQuestion.correct
                  ? 'border-green-500 bg-green-50 dark:bg-green-900/20'
                  : hasAnswered && idx === selectedOption && idx !== currentQuestion.correct
                    ? 'border-red-500 bg-red-50 dark:bg-red-900/20'
                    : selectedOption === idx && !hasAnswered
                      ? 'border-primary-500 bg-primary-50 dark:bg-primary-900/20'
                      : 'border-surface-200 dark:border-surface-600 hover:border-surface-300 dark:hover:border-surface-500',
              ]"
            >
              <span
                :class="[
                  'w-6 h-6 sm:w-7 sm:h-7 rounded-full flex items-center justify-center text-[10px] sm:text-xs font-bold flex-shrink-0 transition-colors',
                  hasAnswered && idx === currentQuestion.correct
                    ? 'bg-green-500 text-white'
                    : hasAnswered && idx === selectedOption && idx !== currentQuestion.correct
                      ? 'bg-red-500 text-white'
                      : selectedOption === idx && !hasAnswered
                        ? 'bg-primary-500 text-white'
                        : 'bg-surface-200 dark:bg-surface-700 text-surface-500',
                ]"
              >
                {{ ['A', 'B', 'C', 'D'][idx] }}
              </span>

              <span :class="[
                'text-sm',
                hasAnswered && idx === currentQuestion.correct
                  ? 'text-green-700 dark:text-green-300 font-medium'
                  : hasAnswered && idx === selectedOption && idx !== currentQuestion.correct
                    ? 'text-red-700 dark:text-red-300'
                    : 'text-surface-700 dark:text-surface-300',
              ]">
                {{ option }}
              </span>

              <span v-if="hasAnswered && idx === currentQuestion.correct" class="material-symbols-rounded text-green-500 ml-auto text-lg">check_circle</span>
              <span v-else-if="hasAnswered && idx === selectedOption && idx !== currentQuestion.correct" class="material-symbols-rounded text-red-500 ml-auto text-lg">cancel</span>
            </button>
          </div>

          <!-- Feedback -->
          <Transition enter-active-class="transition-all duration-300" enter-from-class="opacity-0 translate-y-2">
            <div v-if="hasAnswered" class="mt-4 flex items-center gap-2">
              <span :class="['material-symbols-rounded text-lg', isCorrect ? 'text-green-500' : 'text-red-500']">{{ isCorrect ? 'check_circle' : 'info' }}</span>
              <span :class="['text-sm font-medium', isCorrect ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400']">
                {{ isCorrect ? t('onboarding.quiz.correct') : t('onboarding.quiz.incorrect') }}
              </span>
            </div>
          </Transition>
        </div>
      </div>

      <!-- Bottom actions -->
      <div class="flex items-center justify-between px-4 sm:px-6 py-2.5 sm:py-3 border-t border-surface-200 dark:border-surface-700 bg-white/80 dark:bg-surface-900/80 backdrop-blur-sm">
        <button @click="emit('skip')" class="px-3 sm:px-4 py-2 rounded-full text-[10px] sm:text-xs font-medium text-surface-400 hover:text-surface-600 dark:hover:text-surface-300 transition-colors">
          {{ t('onboarding.buttons.skipQuiz') }}
        </button>

        <button
          v-if="!hasAnswered"
          @click="submitAnswer"
          :disabled="selectedOption === null"
          class="inline-flex items-center gap-1.5 px-4 sm:px-5 py-2 rounded-full text-xs sm:text-sm font-medium text-white bg-primary-600 hover:bg-primary-700 transition-colors shadow-md shadow-primary-500/20 disabled:opacity-40 disabled:cursor-not-allowed"
        >
          {{ t('onboarding.buttons.submitAnswer') }}
          <span class="material-symbols-rounded text-base">check</span>
        </button>

        <button
          v-else
          @click="nextQuestion"
          class="inline-flex items-center gap-1.5 px-4 sm:px-5 py-2 rounded-full text-xs sm:text-sm font-medium text-white bg-primary-600 hover:bg-primary-700 transition-colors shadow-md shadow-primary-500/20"
        >
          {{ currentIndex < QUESTION_COUNT - 1 ? t('onboarding.buttons.nextQuestion') : t('onboarding.buttons.finishQuiz') }}
          <span class="material-symbols-rounded text-base">{{ currentIndex < QUESTION_COUNT - 1 ? 'chevron_right' : 'flag' }}</span>
        </button>
      </div>
    </template>

    <!-- Results phase -->
    <template v-if="phase === 'results'">
      <div class="flex-1 flex items-center justify-center p-4 sm:p-6 overflow-y-auto">
        <div class="max-w-md w-full text-center">
          <div class="relative w-28 h-28 sm:w-36 sm:h-36 mx-auto mb-4 sm:mb-6">
            <svg class="w-full h-full -rotate-90" viewBox="0 0 120 120">
              <circle cx="60" cy="60" r="54" fill="none" stroke="currentColor" stroke-width="6" class="text-surface-200 dark:text-surface-700" />
              <circle cx="60" cy="60" r="54" fill="none" :stroke="scoreRingColor" stroke-width="6" stroke-linecap="round" :stroke-dasharray="ringCircumference" :stroke-dashoffset="ringOffset" class="transition-all duration-1000 ease-out" />
            </svg>
            <div class="absolute inset-0 flex flex-col items-center justify-center">
              <span :class="['text-3xl font-bold', scoreColor]">{{ scorePercent }}%</span>
              <span class="text-xs text-surface-400">{{ t('onboarding.quiz.scoreText', { score: score, total: QUESTION_COUNT }) }}</span>
            </div>
          </div>

          <h2 class="text-lg sm:text-xl font-bold text-surface-800 dark:text-surface-100 mb-2">{{ t('onboarding.quiz.resultsTitle') }}</h2>
          <p class="text-xs sm:text-sm text-surface-500 dark:text-surface-400 mb-4 sm:mb-8 leading-relaxed">{{ scoreMessage }}</p>

          <!-- Topic breakdown -->
          <div v-if="topicBreakdown && topicBreakdown.length" class="bg-surface-50 dark:bg-surface-800/50 rounded-xl p-3 sm:p-4 mb-3 sm:mb-4 text-left">
            <h4 class="text-[10px] sm:text-xs font-semibold text-surface-500 uppercase tracking-wider mb-2 sm:mb-3">{{ t('onboarding.quiz.topicBreakdown') }}</h4>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-1.5 sm:gap-2">
              <div v-for="topic in topicBreakdown" :key="topic.key" class="flex items-center gap-2 py-1">
                <span :class="['material-symbols-rounded text-sm', topic.passed ? 'text-green-500' : 'text-amber-500']">{{ topic.passed ? 'check_circle' : 'radio_button_unchecked' }}</span>
                <span class="text-xs text-surface-600 dark:text-surface-300 flex-1 truncate">{{ topic.label }}</span>
                <span :class="['text-[10px] font-medium', topic.passed ? 'text-green-500' : 'text-amber-500']">{{ topic.correct }}/{{ topic.total }}</span>
              </div>
            </div>
          </div>

          <!-- Answer review -->
          <div class="bg-surface-50 dark:bg-surface-800/50 rounded-xl p-3 sm:p-4 mb-4 sm:mb-6 text-left max-h-36 sm:max-h-48 overflow-y-auto">
            <div v-for="(answer, idx) in answers" :key="idx" class="flex items-center gap-2 py-1.5" :class="idx < answers.length - 1 ? 'border-b border-surface-200 dark:border-surface-700' : ''">
              <span :class="['material-symbols-rounded text-sm', answer.correct ? 'text-green-500' : 'text-red-500']">{{ answer.correct ? 'check_circle' : 'cancel' }}</span>
              <span class="text-xs text-surface-600 dark:text-surface-300 flex-1 truncate">{{ questionText(answer.key) }}</span>
            </div>
          </div>

          <div class="flex flex-col sm:flex-row items-center justify-center gap-2 sm:gap-3">
            <button @click="retake" class="w-full sm:w-auto inline-flex items-center justify-center gap-1.5 px-5 py-2 rounded-full text-xs sm:text-sm font-medium text-surface-600 dark:text-surface-300 bg-surface-100 dark:bg-surface-800 hover:bg-surface-200 dark:hover:bg-surface-700 transition-colors">
              <span class="material-symbols-rounded text-base">refresh</span>
              {{ t('onboarding.buttons.retakeQuiz') }}
            </button>
            <button @click="emit('skip')" class="w-full sm:w-auto inline-flex items-center justify-center gap-2 px-6 py-2 bg-primary-600 hover:bg-primary-700 text-white rounded-full font-medium text-xs sm:text-sm transition-colors shadow-md shadow-primary-500/20">
              <span class="material-symbols-rounded text-base">check</span>
              {{ t('onboarding.buttons.closeQuiz') }}
            </button>
          </div>
        </div>
      </div>
    </template>
  </div>
</template>
