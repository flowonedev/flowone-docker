import { onMounted, onUnmounted, watch, ref } from 'vue'
import { useMailboxStore } from '@/stores/mailbox'
import { useSettingsStore } from '@/stores/settings'

const MAX_BACKOFF_MULT = 5

/**
 * Periodically revalidates the active folder using UIDNEXT/UIDVALIDITY (via mailbox.revalidateActiveFolder).
 * Driven by settings.refresh_interval. Pauses while tab is hidden or offline; backs off on errors.
 */
export function useFolderRevalidationInterval() {
  const mailbox = useMailboxStore()
  const settingsStore = useSettingsStore()

  let timerId = null
  const failureStreak = ref(0)
  let stopWatch = null

  function clearTimer() {
    if (timerId != null) {
      clearTimeout(timerId)
      timerId = null
    }
  }

  function baseIntervalMs() {
    const sec = Number(settingsStore.settings?.refresh_interval) || 0
    return sec > 0 ? sec * 1000 : 0
  }

  function scheduleNext() {
    clearTimer()
    const base = baseIntervalMs()
    if (base <= 0) return

    const mult = Math.min(MAX_BACKOFF_MULT, 2 ** failureStreak.value)
    const delay = base * mult
    timerId = window.setTimeout(() => {
      timerId = null
      void tick()
    }, delay)
  }

  async function tick() {
    const base = baseIntervalMs()
    if (base <= 0) return

    if (typeof document !== 'undefined' && document.hidden) {
      scheduleNext()
      return
    }
    if (typeof navigator !== 'undefined' && navigator.onLine === false) {
      scheduleNext()
      return
    }
    if (!mailbox.currentFolder) {
      scheduleNext()
      return
    }

    try {
      await mailbox.revalidateActiveFolder()
      failureStreak.value = 0
    } catch (e) {
      failureStreak.value = Math.min(failureStreak.value + 1, 8)
      console.warn('[FolderRevalidationInterval] tick failed:', e)
    }
    scheduleNext()
  }

  onMounted(() => {
    stopWatch = watch(
      () => settingsStore.settings?.refresh_interval,
      () => {
        failureStreak.value = 0
        clearTimer()
        if (baseIntervalMs() > 0) scheduleNext()
      },
      { immediate: true }
    )
  })

  onUnmounted(() => {
    if (stopWatch) stopWatch()
    clearTimer()
  })
}
