import { ref, onMounted, onUnmounted } from 'vue'
import { useAuthStore } from '@/stores/auth'
import { useRouter } from 'vue-router'

const IDLE_TIMEOUT = 15 * 60 * 1000    // 15 minutes
const WARNING_BEFORE = 60 * 1000        // Show warning 60s before logout

const TRACKED_EVENTS = [
  'mousedown', 'mousemove', 'keydown',
  'scroll', 'touchstart', 'click', 'wheel'
]

/**
 * Composable that logs the user out after a period of inactivity.
 * Shows a warning modal 60 seconds before the session ends.
 */
export function useIdleTimer() {
  const auth = useAuthStore()
  const router = useRouter()

  const showWarning = ref(false)
  const secondsLeft = ref(60)

  let idleTimer = null
  let warningTimer = null
  let countdownInterval = null

  const resetTimers = () => {
    // If warning is already showing, dismiss it on activity
    if (showWarning.value) {
      showWarning.value = false
      secondsLeft.value = 60
      if (countdownInterval) clearInterval(countdownInterval)
    }

    clearTimeout(idleTimer)
    clearTimeout(warningTimer)

    // Start warning timer (fires WARNING_BEFORE ms before logout)
    warningTimer = setTimeout(() => {
      showWarning.value = true
      secondsLeft.value = Math.ceil(WARNING_BEFORE / 1000)

      countdownInterval = setInterval(() => {
        secondsLeft.value--
        if (secondsLeft.value <= 0) {
          clearInterval(countdownInterval)
        }
      }, 1000)
    }, IDLE_TIMEOUT - WARNING_BEFORE)

    // Start full idle timer
    idleTimer = setTimeout(() => {
      doLogout()
    }, IDLE_TIMEOUT)
  }

  const doLogout = async () => {
    cleanup()
    await auth.logout()
    router.push('/login')
  }

  const stayActive = () => {
    resetTimers()
  }

  const cleanup = () => {
    TRACKED_EVENTS.forEach(evt => document.removeEventListener(evt, resetTimers))
    clearTimeout(idleTimer)
    clearTimeout(warningTimer)
    if (countdownInterval) clearInterval(countdownInterval)
    showWarning.value = false
  }

  onMounted(() => {
    TRACKED_EVENTS.forEach(evt => document.addEventListener(evt, resetTimers, { passive: true }))
    resetTimers()
  })

  onUnmounted(() => {
    cleanup()
  })

  return {
    showWarning,
    secondsLeft,
    stayActive,
    doLogout,
  }
}

