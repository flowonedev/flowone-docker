import { ref, computed, watch } from 'vue'

/**
 * Manages connection visual effects: wave filter (feTurbulence displacement)
 * and draw-on reveal animation (stroke-dashoffset keyframes).
 */
export function useConnectionEffects(store, getConnectionCurveFn, buildItemMapFn) {
  const connDrawOnPlayed = ref(new Set())

  const showWaveFilter = computed(() => {
    if (!store.motionEnabled || !store.motionLines) return false
    if ((store.zoom || 1) < 0.3) return false
    const conns = store.currentBoard?.connections || []
    if (conns.length > 30) return false
    return !!(store.motionLineWave && store.motionLineWave > 0)
  })

  const lineWaveScale = computed(() => Math.round((store.motionLineWave || 0) * 4))

  const lineWaveDensity = computed(() => {
    const d = store.motionLineDensity || 0.3
    const bx = (0.003 + d * 0.025).toFixed(4)
    const by = (0.008 + d * 0.05).toFixed(4)
    return `${bx} ${by}`
  })

  const lineWaveDensityAnim = computed(() => {
    const d = store.motionLineDensity || 0.3
    const bx = 0.003 + d * 0.025
    const by = 0.008 + d * 0.05
    const v1 = `${bx.toFixed(4)} ${by.toFixed(4)}`
    const v2 = `${(bx * 1.3).toFixed(4)} ${(by * 1.4).toFixed(4)}`
    const v3 = `${(bx * 0.8).toFixed(4)} ${(by * 0.85).toFixed(4)}`
    return `${v1};${v2};${v3};${v1}`
  })

  const lineWaveAnimDur = computed(() => {
    return Math.max(3, Math.round(20 / Math.max(0.1, store.motionLineSpeed || 1)))
  })

  function isConnDrawOnActive(conn) {
    return store.motionEnabled && store.motionDrawOn && !connDrawOnPlayed.value.has(conn.id)
  }

  function getConnDrawOnDuration(conn) {
    const path = getConnectionSvgPath(conn)
    const len = estimateSvgPathLength(path)
    const baseDur = Math.max(0.4, Math.min(2.5, len / 400))
    return baseDur / (store.motionDrawOnSpeed || 1)
  }

  function onConnDrawOnEnd(conn) {
    const next = new Set(connDrawOnPlayed.value)
    next.add(conn.id)
    connDrawOnPlayed.value = next
  }

  function getConnectionSvgPath(conn) {
    const curve = getConnectionCurveFn(conn, buildItemMapFn(store.currentBoard?.items || []))
    if (!curve) return ''
    return `M${curve.x1},${curve.y1} C${curve.cx1},${curve.cy1} ${curve.cx2},${curve.cy2} ${curve.x2},${curve.y2}`
  }

  function estimateSvgPathLength(d) {
    if (!d) return 200
    const parts = d.replace(/[MCcSsQqTtAaZz]/g, '').trim().split(/[\s,]+/).map(Number).filter(n => !isNaN(n))
    if (parts.length < 4) return 200
    let len = 0
    for (let i = 2; i < parts.length; i += 2) {
      const dx = parts[i] - parts[i - 2]
      const dy = parts[i + 1] - parts[i - 1]
      len += Math.sqrt(dx * dx + dy * dy)
    }
    return Math.max(len, 20)
  }

  watch(() => store.motionDrawOn, (val) => {
    if (val) connDrawOnPlayed.value = new Set()
  })

  return {
    showWaveFilter,
    lineWaveScale,
    lineWaveDensity,
    lineWaveDensityAnim,
    lineWaveAnimDur,
    connDrawOnPlayed,
    isConnDrawOnActive,
    getConnDrawOnDuration,
    onConnDrawOnEnd,
    getConnectionSvgPath,
    estimateSvgPathLength,
  }
}
