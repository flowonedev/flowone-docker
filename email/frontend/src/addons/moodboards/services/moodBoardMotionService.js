import { ref, watch } from 'vue'
import api from '@/services/api'

/**
 * Ambient motion / animation settings for the moodboards store.
 *
 * Owns all motion refs, (de)serialization, and the debounced server save.
 * Extracted from stores/moodBoards.js per the modularity rules.
 *
 * @param ctx {
 *   currentBoard: Ref<board|null>,
 *   boardLoading: Ref<boolean>,
 *   presentationMode: Ref<boolean>,
 *   isPublicView: Ref<boolean>,
 *   isPrePresActive: () => boolean,  // true while presentation temporarily overrides motion
 * }
 */
export function createMotionSettings(ctx) {
  const { currentBoard, boardLoading, presentationMode, isPublicView, isPrePresActive } = ctx

  const motionEnabled = ref(false)
  const motionCards = ref(true)    // wobble/float on cards & notes
  const motionElements = ref(true) // subtle float on images, swatches, etc.
  const motionLines = ref(false)   // wave ripple on connection lines (off by default — circles animate regardless)
  const motionIntensity = ref(1.5)      // element wobble/float amplitude: 0=still, 5=lively
  const motionCardIntensity = ref(1)    // card-specific wobble amplitude: 0=still, 5=lively
  const motionSpeed = ref(1)            // animation speed multiplier: 0.3=slow, 2=fast
  const motionLineWave = ref(2)         // line displacement amount: 0=none, 5=heavy
  const motionLineSpeed = ref(0.5)      // line wave animation speed: 0.1=slow curtain, 2=jittery
  const motionLineDensity = ref(0.3)    // line wave density: 0.1=big flowing, 1=tight ripples
  const motionDrawOn = ref(true)        // draw-on reveal animation for drawings, text, lines
  const motionDrawOnTrigger = ref('viewport') // 'viewport' = animate when scrolled into view, 'always' = replay on load
  const motionDrawOnSpeed = ref(1)      // speed multiplier: 0.3=slow, 1=normal, 2=fast
  const slidesVisible = ref(false)      // show/hide slide outlines on canvas (off by default)

  let _motionSaveTimer = null
  const _motionSaving = ref(false)

  /** Collect all motion refs into a plain object for saving. */
  function getMotionSettingsPayload() {
    return {
      enabled: motionEnabled.value,
      cards: motionCards.value,
      elements: motionElements.value,
      lines: motionLines.value,
      intensity: motionIntensity.value,
      cardIntensity: motionCardIntensity.value,
      speed: motionSpeed.value,
      lineWave: motionLineWave.value,
      lineSpeed: motionLineSpeed.value,
      lineDensity: motionLineDensity.value,
      drawOn: motionDrawOn.value,
      drawOnTrigger: motionDrawOnTrigger.value,
      drawOnSpeed: motionDrawOnSpeed.value,
      slidesVisible: slidesVisible.value,
    }
  }

  /** Restore motion settings from the board data loaded from server. */
  function restoreMotionSettings(settings) {
    if (!settings) return
    // Parse if it's a string (e.g. straight from DB JSON column)
    let s = settings
    if (typeof s === 'string') {
      try { s = JSON.parse(s) } catch { return }
    }
    if (!s || typeof s !== 'object') return
    if (s.enabled !== undefined) motionEnabled.value = s.enabled
    if (s.cards !== undefined) motionCards.value = s.cards
    if (s.elements !== undefined) motionElements.value = s.elements
    if (s.lines !== undefined) motionLines.value = s.lines
    if (s.intensity !== undefined) motionIntensity.value = s.intensity
    if (s.cardIntensity !== undefined) motionCardIntensity.value = s.cardIntensity
    if (s.speed !== undefined) motionSpeed.value = s.speed
    if (s.lineWave !== undefined) motionLineWave.value = s.lineWave
    if (s.lineSpeed !== undefined) motionLineSpeed.value = s.lineSpeed
    if (s.lineDensity !== undefined) motionLineDensity.value = s.lineDensity
    if (s.drawOn !== undefined) motionDrawOn.value = s.drawOn
    if (s.drawOnTrigger !== undefined) motionDrawOnTrigger.value = s.drawOnTrigger
    if (s.drawOnSpeed !== undefined) motionDrawOnSpeed.value = s.drawOnSpeed
    if (s.slidesVisible !== undefined) slidesVisible.value = s.slidesVisible
    // backward compat: old boards may have framesVisible stored
    if (s.framesVisible !== undefined && s.slidesVisible === undefined) slidesVisible.value = s.framesVisible
  }

  /** Debounced save — waits 800ms after last change, then persists to server. */
  function saveMotionSettings() {
    if (!currentBoard.value) return
    // Capture board ID and payload NOW — if the user switches boards during the
    // delay, we must save to the ORIGINAL board, not the newly opened one.
    const boardId = currentBoard.value.id
    const payload = JSON.stringify(getMotionSettingsPayload())
    clearTimeout(_motionSaveTimer)
    _motionSaveTimer = setTimeout(async () => {
      _motionSaving.value = true
      try {
        await api.put(`/mood-boards/${boardId}`, {
          motion_settings: payload,
        })
      } catch (e) {
        console.error('Failed to save motion settings:', e)
      } finally {
        _motionSaving.value = false
      }
    }, 800)
  }

  /** Cancel any pending debounced save (store $reset). */
  function clearMotionSaveTimer() {
    clearTimeout(_motionSaveTimer)
  }

  // Watch ALL motion refs — trigger debounced save on any change
  watch(
    [
      motionEnabled, motionCards, motionElements, motionLines,
      motionIntensity, motionCardIntensity, motionSpeed,
      motionLineWave, motionLineSpeed, motionLineDensity,
      motionDrawOn, motionDrawOnTrigger, motionDrawOnSpeed,
      slidesVisible,
    ],
    () => {
      // Only save if a board is loaded (avoids saving during $reset or initial load)
      // Skip saving during presentation mode — startPresentation forces motionEnabled=true
      // temporarily; we must NOT persist that override to the database.
      // Skip saving for public/shared views — visitor has no auth token and the PUT
      // would 401, triggering a redirect to /login.
      if (currentBoard.value && !boardLoading.value && !presentationMode.value && !isPrePresActive() && !isPublicView.value) {
        saveMotionSettings()
      }
    }
  )

  return {
    motionEnabled, motionCards, motionElements, motionLines,
    motionIntensity, motionCardIntensity, motionSpeed,
    motionLineWave, motionLineSpeed, motionLineDensity,
    motionDrawOn, motionDrawOnTrigger, motionDrawOnSpeed,
    slidesVisible,
    getMotionSettingsPayload, restoreMotionSettings, saveMotionSettings,
    clearMotionSaveTimer,
  }
}
