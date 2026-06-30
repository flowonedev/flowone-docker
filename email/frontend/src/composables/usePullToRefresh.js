import { ref, onMounted, onUnmounted, watch } from 'vue'

/**
 * iOS-style pull-to-refresh for a scrollable element.
 *
 * @param {import('vue').Ref<HTMLElement|null>} scrollElRef - ref to the scroll container
 * @param {() => Promise<void>} onRefresh - async callback fired on release past threshold
 * @param {Object} [opts]
 * @param {import('vue').Ref<boolean>} [opts.enabled] - reactive gate (e.g. layout.isMobile)
 * @param {number} [opts.threshold=64] - pull distance in px before release triggers refresh
 * @param {number} [opts.maxPull=120] - maximum visual displacement (rubber-band cap)
 * @param {number} [opts.resistance=0.45] - drag resistance factor (0–1)
 */
export function usePullToRefresh(scrollElRef, onRefresh, opts = {}) {
  const threshold = opts.threshold ?? 64
  const maxPull = opts.maxPull ?? 120
  const resistance = opts.resistance ?? 0.45

  const pullDistance = ref(0)
  const refreshing = ref(false)
  const pulling = ref(false)

  let startY = 0
  let tracking = false

  function onTouchStart(e) {
    if (refreshing.value) return
    const el = scrollElRef.value
    if (!el || el.scrollTop > 0) return
    startY = e.touches[0].clientY
    tracking = true
    pulling.value = false
  }

  function onTouchMove(e) {
    if (!tracking || refreshing.value) return
    const el = scrollElRef.value
    if (!el) return

    if (el.scrollTop > 0) {
      tracking = false
      pullDistance.value = 0
      pulling.value = false
      return
    }

    const deltaY = (e.touches[0].clientY - startY) * resistance
    if (deltaY <= 0) {
      pullDistance.value = 0
      pulling.value = false
      return
    }

    e.preventDefault()
    pulling.value = true
    pullDistance.value = Math.min(deltaY, maxPull)
  }

  async function onTouchEnd() {
    if (!tracking) return
    tracking = false

    if (pullDistance.value >= threshold && !refreshing.value) {
      refreshing.value = true
      pullDistance.value = threshold * 0.6
      try {
        await onRefresh()
      } finally {
        refreshing.value = false
        pullDistance.value = 0
        pulling.value = false
      }
    } else {
      pullDistance.value = 0
      pulling.value = false
    }
  }

  function bind() {
    const el = scrollElRef.value
    if (!el) return
    el.addEventListener('touchstart', onTouchStart, { passive: true })
    el.addEventListener('touchmove', onTouchMove, { passive: false })
    el.addEventListener('touchend', onTouchEnd, { passive: true })
  }

  function unbind() {
    const el = scrollElRef.value
    if (!el) return
    el.removeEventListener('touchstart', onTouchStart)
    el.removeEventListener('touchmove', onTouchMove)
    el.removeEventListener('touchend', onTouchEnd)
  }

  if (opts.enabled) {
    watch(opts.enabled, (val) => { val ? bind() : unbind() })
  }

  watch(scrollElRef, (newEl, oldEl) => {
    if (oldEl) unbind()
    if (newEl && (!opts.enabled || opts.enabled.value)) bind()
  })

  onMounted(() => {
    if (!opts.enabled || opts.enabled.value) bind()
  })

  onUnmounted(unbind)

  return { pullDistance, refreshing, pulling }
}
