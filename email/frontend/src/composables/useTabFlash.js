import { ref, watch, onUnmounted } from 'vue'

const FLASH_INTERVAL_MS = 1000
const FLASH_MESSAGE = 'New Activity!'

let flashTimer = null
let isFlashing = false
let currentTitle = ''

/**
 * Blinks the browser tab title between the normal title and a flash message
 * when the page is not focused and there are unread items.
 * Stops automatically when the user returns to the tab.
 */
export function useTabFlash() {
  const tabFocused = ref(document.hasFocus())

  function onFocus() {
    tabFocused.value = true
    stopFlash()
  }

  function onBlur() {
    tabFocused.value = false
  }

  window.addEventListener('focus', onFocus)
  window.addEventListener('blur', onBlur)

  function startFlash(normalTitle) {
    if (isFlashing) return
    isFlashing = true
    currentTitle = normalTitle
    let showFlash = true

    flashTimer = setInterval(() => {
      document.title = showFlash ? `** ${FLASH_MESSAGE} **` : currentTitle
      showFlash = !showFlash
    }, FLASH_INTERVAL_MS)
  }

  function stopFlash() {
    if (!isFlashing) return
    isFlashing = false
    if (flashTimer) {
      clearInterval(flashTimer)
      flashTimer = null
    }
    if (currentTitle) {
      document.title = currentTitle
    }
  }

  function updateTitle(normalTitle) {
    currentTitle = normalTitle
  }

  function cleanup() {
    stopFlash()
    window.removeEventListener('focus', onFocus)
    window.removeEventListener('blur', onBlur)
  }

  onUnmounted(cleanup)

  return { tabFocused, startFlash, stopFlash, updateTitle, cleanup }
}
