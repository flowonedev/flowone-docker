import { ref, onBeforeUnmount } from 'vue'

/**
 * Hover-intent composable: opens after a short delay (kills accidental
 * brush-bys) and closes after a longer delay (lets the cursor traverse
 * rail -> popout without flicker). Matches Slack/Linear/Notion timings.
 *
 * Wire both rail and popout to `onEnter` / `onLeave` so moving the cursor
 * from rail into popout cancels the pending close timer.
 *
 * @param {{openDelay?: number, closeDelay?: number}} opts
 * @returns {{open: import('vue').Ref<boolean>, onEnter: () => void, onLeave: () => void, forceClose: () => void}}
 */
export function useHoverIntent({ openDelay = 80, closeDelay = 150 } = {}) {
  const open = ref(false)
  let openTimer = null
  let closeTimer = null

  function cancelTimers() {
    if (openTimer) { clearTimeout(openTimer); openTimer = null }
    if (closeTimer) { clearTimeout(closeTimer); closeTimer = null }
  }

  function onEnter() {
    if (closeTimer) { clearTimeout(closeTimer); closeTimer = null }
    if (open.value || openTimer) return
    openTimer = setTimeout(() => {
      open.value = true
      openTimer = null
    }, openDelay)
  }

  function onLeave() {
    if (openTimer) { clearTimeout(openTimer); openTimer = null }
    if (!open.value || closeTimer) return
    closeTimer = setTimeout(() => {
      open.value = false
      closeTimer = null
    }, closeDelay)
  }

  function forceClose() {
    cancelTimers()
    open.value = false
  }

  onBeforeUnmount(cancelTimers)

  return { open, onEnter, onLeave, forceClose }
}
