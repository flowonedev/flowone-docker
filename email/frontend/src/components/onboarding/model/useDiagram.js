import { ref, computed, onMounted, onUnmounted, watch } from 'vue'
import { computeConnPath, computePathLength } from './shared'

export function useDiagram(model, currentStepRef) {
  const container = ref(null)
  const cW = ref(1100)
  const cH = ref(620)
  const isMobile = ref(false)

  function measure() {
    if (container.value) {
      cW.value = container.value.clientWidth
      cH.value = container.value.clientHeight
    }
    isMobile.value = window.innerWidth < 640
  }

  onMounted(() => { measure(); window.addEventListener('resize', measure) })
  onUnmounted(() => window.removeEventListener('resize', measure))

  const hoveredNode = ref(null)
  const tooltipStyle = ref({})
  let hoverTimeout = null

  function onNodeEnter(nodeId) {
    if (isMobile.value) return
    clearTimeout(hoverTimeout)
    hoverTimeout = setTimeout(() => {
      hoveredNode.value = nodeId
      positionTooltip(nodeId)
    }, 300)
  }

  function onNodeLeave() {
    if (isMobile.value) return
    clearTimeout(hoverTimeout)
    hoverTimeout = setTimeout(() => { hoveredNode.value = null }, 150)
  }

  function positionTooltip(nodeId) {
    if (!container.value || isMobile.value) return
    const p = model.positions[nodeId]
    if (!p) return
    const rect = container.value.getBoundingClientRect()
    const nodeX = rect.left + p.x * rect.width
    const nodeY = rect.top + p.y * rect.height
    const tooltipW = 384, tooltipH = 440, gap = 16
    const vw = window.innerWidth, vh = window.innerHeight
    let left = nodeX + gap + tooltipW < vw - 20
      ? nodeX + gap
      : nodeX - gap - tooltipW > 20
        ? nodeX - gap - tooltipW
        : Math.max(20, (vw - tooltipW) / 2)
    let top = Math.max(20, Math.min(nodeY - tooltipH / 2, vh - tooltipH - 20))
    tooltipStyle.value = { left: left + 'px', top: top + 'px' }
  }

  function nodeCenter(id) {
    const p = model.positions[id]
    return p ? { x: p.x * cW.value, y: p.y * cH.value } : { x: 0, y: 0 }
  }

  function connPath(fromId, toId) {
    return computeConnPath(model.positions[fromId], model.positions[toId], cW.value, cH.value)
  }

  function estimatePathLength(fromId, toId) {
    return computePathLength(model.positions[fromId], model.positions[toId], cW.value, cH.value)
  }

  const visibleConnections = computed(() =>
    model.edges.filter(c => c.step <= currentStepRef.value)
  )

  const drawnSteps = ref(new Set())
  watch(currentStepRef, (n, o) => {
    if (o !== undefined && o > 0) drawnSteps.value.add(o)
  })

  function isDrawingOn(conn) {
    return conn.step === currentStepRef.value && !drawnSteps.value.has(conn.step)
  }

  const connectedToHovered = computed(() => {
    if (!hoveredNode.value) return null
    const ids = new Set([hoveredNode.value])
    for (const c of model.edges) {
      if (c.step > currentStepRef.value) continue
      if (c.from === hoveredNode.value) ids.add(c.to)
      if (c.to === hoveredNode.value) ids.add(c.from)
    }
    return ids
  })

  function isConnInHoverFocus(conn) {
    if (!hoveredNode.value) return true
    return conn.from === hoveredNode.value || conn.to === hoveredNode.value
  }

  function isNodeHoverDimmed(nodeId) {
    if (!connectedToHovered.value) return false
    return !connectedToHovered.value.has(nodeId)
  }

  function nodeState(nodeDef) {
    const step = currentStepRef.value
    if (step <= 1 || step >= model.totalSteps) {
      return step >= model.totalSteps ? 'visited' : 'dimmed'
    }
    if (nodeDef.step === step) return 'active'
    if (nodeDef.step < step) return 'visited'
    return 'dimmed'
  }

  function nodeVisible(nodeDef) {
    return currentStepRef.value >= nodeDef.step || currentStepRef.value >= model.totalSteps
  }

  const scenePosition = computed(() => {
    const nodeId = model.sceneNodeMap[currentStepRef.value]
    if (!nodeId) return { left: '35%', top: '50%' }
    const p = model.positions[nodeId]
    if (!p) return { left: '35%', top: '50%' }
    const isRightSide = p.x > 0.55
    const rawLeft = isRightSide ? p.x * 100 - 30 : p.x * 100 + 6
    const left = Math.max(4, Math.min(rawLeft, 58)) + '%'
    const top = Math.max(22, Math.min(p.y * 100, 68)) + '%'
    return { left, top }
  })

  return {
    container, cW, cH, isMobile,
    hoveredNode, tooltipStyle, onNodeEnter, onNodeLeave,
    nodeCenter, connPath, estimatePathLength,
    visibleConnections, drawnSteps, isDrawingOn,
    connectedToHovered, isConnInHoverFocus, isNodeHoverDimmed,
    nodeState, nodeVisible, scenePosition,
  }
}
