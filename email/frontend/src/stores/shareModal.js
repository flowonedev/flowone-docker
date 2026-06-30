import { defineStore } from 'pinia'
import { ref } from 'vue'

/**
 * shareModal - global state for the single, app-wide UnifiedShareModal.
 *
 * Any view (Drive, Office editor, attachment preview, ...) opens the same
 * sharing UI by calling `open(item, type, opts)`. The modal is mounted once
 * in App.vue and reads its state from here, so the experience is identical
 * everywhere.
 *
 * opts:
 *   - defaultTab: 'link' | 'collaborate'   (which top-level tab opens first)
 *   - onUpdated: () => void                (called after every mutation so the
 *                                           caller can refresh its own view)
 */
export const useShareModalStore = defineStore('shareModal', () => {
  const show = ref(false)
  const item = ref(null)
  const type = ref('file') // 'file' | 'folder'
  const defaultTab = ref('link') // 'link' | 'collaborate'

  // Callbacks are not reactive state; keep them out of refs.
  let onUpdatedCb = null

  function open(targetItem, targetType = 'file', opts = {}) {
    if (!targetItem?.id) {
      console.warn('[shareModal] open() called without a valid item')
      return
    }
    item.value = targetItem
    type.value = targetType
    defaultTab.value = opts.defaultTab === 'collaborate' ? 'collaborate' : 'link'
    onUpdatedCb = typeof opts.onUpdated === 'function' ? opts.onUpdated : null
    show.value = true
  }

  function close() {
    show.value = false
    item.value = null
    onUpdatedCb = null
  }

  /** Invoked by the modal after a link/collaborator/group/guest-link change. */
  function notifyUpdated() {
    if (onUpdatedCb) {
      try {
        onUpdatedCb()
      } catch (e) {
        console.error('[shareModal] onUpdated callback failed:', e)
      }
    }
  }

  return { show, item, type, defaultTab, open, close, notifyUpdated }
})
