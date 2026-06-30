import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import api from '@/services/api'

export const useOnboardingStore = defineStore('onboarding', () => {
  const isOpen = ref(false)
  const selectedTier = ref(null) // 'beginner' | 'intermediate' | 'advanced' | null
  const completedTiers = ref(JSON.parse(localStorage.getItem('onboarding_completed') || '[]'))
  const quizScores = ref(JSON.parse(localStorage.getItem('onboarding_quizScores') || '{}'))
  const quizSaving = ref(false)
  const notifyEmail = import.meta.env.VITE_ONBOARDING_NOTIFY_EMAIL

  // Platform tour header button visibility. Hidden by default; opt-in via Settings.
  const tourButtonEnabled = ref(localStorage.getItem('platform_tour_enabled') === 'true')

  const quizScore = computed(() => {
    if (!selectedTier.value) return null
    return quizScores.value[selectedTier.value] || null
  })

  function open(tier = null) {
    selectedTier.value = tier
    isOpen.value = true
  }

  function setTourButtonEnabled(val) {
    tourButtonEnabled.value = !!val
    localStorage.setItem('platform_tour_enabled', tourButtonEnabled.value ? 'true' : 'false')
  }

  function close() {
    isOpen.value = false
  }

  function selectTier(tier) {
    selectedTier.value = tier
  }

  function backToTierSelect() {
    selectedTier.value = null
  }

  function completeTier(tier) {
    if (!completedTiers.value.includes(tier)) {
      completedTiers.value.push(tier)
      localStorage.setItem('onboarding_completed', JSON.stringify(completedTiers.value))
    }
  }

  function isTierCompleted(tier) {
    return completedTiers.value.includes(tier)
  }

  async function saveQuizScore(score, total, percent) {
    const tier = selectedTier.value || 'general'
    quizScores.value[tier] = { score, total, percent }
    localStorage.setItem('onboarding_quizScores', JSON.stringify(quizScores.value))
    quizSaving.value = true
    try {
      const payload = {
        score,
        total,
        percent,
        tier,
      }
      if (notifyEmail) payload.notify_email = notifyEmail
      await api.post('/onboarding/quiz-score', payload)
    } catch (e) {
      console.error('[Onboarding] Failed to save quiz score:', e)
    } finally {
      quizSaving.value = false
    }
  }

  return {
    isOpen, selectedTier, completedTiers, quizScores, quizScore, quizSaving, tourButtonEnabled,
    open, close, selectTier, backToTierSelect, completeTier, isTierCompleted, saveQuizScore,
    setTourButtonEnabled,
  }
})
