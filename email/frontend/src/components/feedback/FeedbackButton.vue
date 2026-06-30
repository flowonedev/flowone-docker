<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue'
import { useRoute } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { useFeedbackStore } from '@/stores/feedback'

const { t } = useI18n()
const route = useRoute()
const feedbackStore = useFeedbackStore()

const isOnMoodBoard = computed(() => route.name === 'mood-board' || route.name === 'shared-mood-board')
const isEnabled = ref(localStorage.getItem('bug_report_enabled') !== 'false')

let pollInterval
onMounted(() => {
  pollInterval = setInterval(() => {
    isEnabled.value = localStorage.getItem('bug_report_enabled') !== 'false'
  }, 1000)
})
onUnmounted(() => clearInterval(pollInterval))

function openFeedback() {
  feedbackStore.open(route.name || '')
}
</script>

<template>
  <Transition
    enter-active-class="transition-all duration-300 ease-out"
    leave-active-class="transition-all duration-200 ease-in"
    enter-from-class="opacity-0 scale-75 translate-y-4"
    leave-to-class="opacity-0 scale-75 translate-y-4"
  >
    <button
      v-if="isEnabled && !feedbackStore.isOpen && !isOnMoodBoard"
      @click="openFeedback"
      class="feedback-trigger group hidden md:flex"
      :title="t('feedback.buttonTooltip')"
    >
      <span class="material-symbols-rounded text-xl">bug_report</span>
      <span class="absolute right-full mr-3 px-3 py-1.5 bg-surface-800 text-white text-sm rounded-lg opacity-0 group-hover:opacity-100 transition-opacity whitespace-nowrap pointer-events-none">
        {{ t('feedback.buttonTooltip') }}
      </span>
    </button>
  </Transition>
</template>

<style scoped>
.feedback-trigger {
  @apply fixed z-[9989]
         w-11 h-11 rounded-full
         bg-surface-100 dark:bg-surface-800
         text-surface-500 dark:text-surface-400
         border border-surface-200 dark:border-surface-700
         shadow-md hover:shadow-lg
         hover:bg-red-500 hover:text-white hover:border-red-500
         dark:hover:bg-red-500 dark:hover:text-white dark:hover:border-red-500
         transition-all duration-200
         flex items-center justify-center;
  right: 25px;
  bottom: 14rem;
}
</style>
