import { ref, computed, watch, nextTick, onBeforeUnmount } from 'vue'

/**
 * Computes background styles (dot grid, image, gradient/vignette/blur, grain noise)
 * for the moodboard canvas.
 */
export function useCanvasBackground(store, { boardProps, containerRef }) {
  const grainCanvas = ref(null)
  let grainResizeObserver = null

  const isDarkBg = computed(() => {
    const bg = boardProps.value?.background_color || '#f5f5f5'
    const hex = bg.replace('#', '')
    if (hex.length >= 6) {
      const r = parseInt(hex.substr(0, 2), 16)
      const g = parseInt(hex.substr(2, 2), 16)
      const b = parseInt(hex.substr(4, 2), 16)
      return (r * 0.299 + g * 0.587 + b * 0.114) < 128
    }
    return false
  })

  const dotGridStyle = computed(() => {
    const z = store.zoom || 1
    const spacing = Math.max(6, 20 * z)
    const dotSize = Math.max(0.5, Math.min(2.5, 1 * z))
    const ox = (store.panX || 0) % spacing
    const oy = (store.panY || 0) % spacing
    const color = isDarkBg.value
      ? `rgba(255,255,255,${0.08 + 0.05 * Math.min(z, 2)})`
      : `rgba(160,170,180,${0.3 + 0.15 * Math.min(z, 2)})`
    return {
      backgroundImage: `radial-gradient(circle, ${color} ${dotSize}px, transparent ${dotSize}px)`,
      backgroundSize: `${spacing}px ${spacing}px`,
      backgroundPosition: `${ox}px ${oy}px`,
    }
  })

  const bgImageStyle = computed(() => {
    const url = boardProps.value?.background_image
    if (!url) return {}
    const size = boardProps.value?.background_image_size || 'cover'
    const style = { backgroundImage: `url(${url})`, backgroundPosition: 'center' }
    if (size === 'repeat') { style.backgroundSize = 'auto'; style.backgroundRepeat = 'repeat' }
    else { style.backgroundSize = size; style.backgroundRepeat = 'no-repeat' }
    return style
  })

  const bgEffect = computed(() => store.getBackgroundEffect())

  const bgEffectStyles = computed(() => {
    const fx = bgEffect.value
    if (!fx) return null
    const layers = []
    if (fx.gradient?.enabled) {
      const angle = fx.gradient.angle ?? 135
      const from = fx.gradient.from || '#000000'
      const to = fx.gradient.to || '#ffffff'
      const op = (fx.gradient.opacity ?? 30) / 100
      layers.push(`linear-gradient(${angle}deg, ${hexToRgba(from, op)}, ${hexToRgba(to, op)})`)
    }
    if (fx.vignette?.enabled) {
      const intensity = (fx.vignette.intensity ?? 40) / 100
      const spread = (fx.vignette.spread ?? 60)
      layers.push(`radial-gradient(ellipse at center, transparent ${spread}%, rgba(0,0,0,${intensity}) 100%)`)
    }
    if (!layers.length && !fx.blur?.enabled) return null
    const style = {}
    if (layers.length) style.background = layers.join(', ')
    if (fx.blur?.enabled) style.backdropFilter = `blur(${fx.blur.amount ?? 4}px)`
    return style
  })

  const grainEnabled = computed(() => bgEffect.value?.grain?.enabled)
  const grainCanvasStyle = computed(() => ({
    opacity: ((bgEffect.value?.grain?.intensity ?? 20) / 100),
    mixBlendMode: 'overlay',
  }))

  function hexToRgba(hex, alpha) {
    const r = parseInt(hex.slice(1, 3), 16)
    const g = parseInt(hex.slice(3, 5), 16)
    const b = parseInt(hex.slice(5, 7), 16)
    return `rgba(${r},${g},${b},${alpha})`
  }

  function renderGrainNoise() {
    const canvas = grainCanvas.value
    if (!canvas || !containerRef.value) return
    const w = containerRef.value.clientWidth
    const h = containerRef.value.clientHeight
    canvas.width = w
    canvas.height = h
    const ctx = canvas.getContext('2d')
    if (!ctx) return
    const imageData = ctx.createImageData(w, h)
    const data = imageData.data
    const isColor = bgEffect.value?.grain?.mode === 'color'
    for (let i = 0; i < data.length; i += 4) {
      if (isColor) {
        data[i] = Math.random() * 255
        data[i + 1] = Math.random() * 255
        data[i + 2] = Math.random() * 255
      } else {
        const v = Math.random() * 255
        data[i] = v; data[i + 1] = v; data[i + 2] = v
      }
      data[i + 3] = 255
    }
    ctx.putImageData(imageData, 0, 0)
  }

  function setupGrainResizeObserver() {
    if (grainResizeObserver) { grainResizeObserver.disconnect(); grainResizeObserver = null }
    if (grainEnabled.value && containerRef.value) {
      grainResizeObserver = new ResizeObserver(() => {
        if (grainEnabled.value) renderGrainNoise()
      })
      grainResizeObserver.observe(containerRef.value)
    }
  }

  watch(grainEnabled, (v) => {
    if (v) nextTick(renderGrainNoise)
    nextTick(setupGrainResizeObserver)
  })
  watch(() => bgEffect.value?.grain?.mode, () => {
    if (grainEnabled.value) nextTick(renderGrainNoise)
  })

  function cleanup() {
    if (grainResizeObserver) grainResizeObserver.disconnect()
  }

  return {
    isDarkBg,
    dotGridStyle,
    bgImageStyle,
    bgEffectStyles,
    grainEnabled,
    grainCanvasStyle,
    grainCanvas,
    renderGrainNoise,
    setupGrainResizeObserver,
    cleanup,
  }
}
