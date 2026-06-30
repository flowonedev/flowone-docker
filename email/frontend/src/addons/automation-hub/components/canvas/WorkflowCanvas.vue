<template>
  <div
    ref="canvasContainer"
    class="relative w-full h-full overflow-hidden bg-surface-100 dark:bg-surface-950 select-none"
    @mousedown="onCanvasMouseDown"
    @wheel.prevent="onWheel"
    @keydown="onKeyDown"
    @contextmenu.prevent
    tabindex="0"
  >
    <!-- Dot grid background -->
    <div class="absolute inset-0 z-0 pointer-events-none" :style="dotGridStyle" />

    <!-- Canvas layer (transforms with pan/zoom) -->
    <div ref="canvasLayer" class="absolute top-0 left-0 z-10" :style="canvasTransform">
      <!-- SVG layer for edges -->
      <svg
        class="absolute top-0 left-0 pointer-events-none"
        style="width: 10000px; height: 10000px; overflow: visible;"
      >
        <defs>
          <marker
            id="wf-arrowhead"
            markerWidth="8"
            markerHeight="6"
            refX="8"
            refY="3"
            orient="auto"
          >
            <polygon points="0 0, 8 3, 0 6" fill="currentColor" class="text-surface-500" />
          </marker>
          <marker
            id="wf-arrowhead-selected"
            markerWidth="8"
            markerHeight="6"
            refX="8"
            refY="3"
            orient="auto"
          >
            <polygon points="0 0, 8 3, 0 6" fill="currentColor" class="text-primary-400" />
          </marker>
        </defs>

        <!-- Existing edges -->
        <g v-for="edge in store.edges" :key="edge.id">
          <!-- Hit area (invisible, wider) -->
          <path
            :d="getEdgePath(edge)"
            fill="none"
            stroke="transparent"
            stroke-width="16"
            style="pointer-events: stroke"
            class="cursor-pointer"
            @click.stop="store.selectEdge(edge.id)"
            @dblclick.stop="store.removeEdge(edge.id)"
          />
          <!-- Visible stroke -->
          <path
            :d="getEdgePath(edge)"
            fill="none"
            :stroke="edge.id === store.selectedEdgeId ? '#818cf8' : '#64748b'"
            :stroke-width="edge.id === store.selectedEdgeId ? 2.5 : 2"
            :stroke-dasharray="edge.style === 'dashed' ? '6 4' : 'none'"
            :marker-end="edge.id === store.selectedEdgeId ? 'url(#wf-arrowhead-selected)' : 'url(#wf-arrowhead)'"
            class="pointer-events-none transition-colors duration-150"
          />
          <!-- Flow animation dots -->
          <template v-if="flowAnimEnabled">
            <circle
              v-for="dotIdx in getEdgeDotCount(edge)"
              :key="'flow-' + edge.id + '-' + dotIdx"
              :r="3"
              :fill="edge.id === store.selectedEdgeId ? '#818cf8' : '#94a3b8'"
              :opacity="0.7 + 0.15 * Math.sin(dotIdx * 1.8)"
              class="pointer-events-none"
            >
              <animateMotion
                :dur="getEdgeAnimDuration(edge) + 's'"
                repeatCount="indefinite"
                :path="getEdgePath(edge)"
                :begin="'-' + ((dotIdx - 1) / getEdgeDotCount(edge) * getEdgeAnimDuration(edge)).toFixed(2) + 's'"
              />
            </circle>
          </template>
        </g>

        <!-- Temp edge while connecting -->
        <path
          v-if="store.isConnecting && store.connectingFrom && store.tempEdgeEnd"
          :d="getTempEdgePath()"
          fill="none"
          stroke="#818cf8"
          stroke-width="2"
          stroke-dasharray="6 4"
          marker-end="url(#wf-arrowhead-selected)"
          class="pointer-events-none"
        />
      </svg>

      <!-- Workflow nodes -->
      <WorkflowNode
        v-for="node in store.nodesArray"
        :key="node.uid"
        :node="node"
        :selected="store.selectedNodeUids.has(node.uid)"
        :connecting="store.isConnecting"
        :execution-status="store.executionState.get(node.uid)"
        @mousedown="onNodeMouseDown($event, node)"
        @port-drag-start="onPortDragStart"
        @port-drag-end="onPortDragEnd"
        @quick-add="onQuickAdd"
      />

      <!-- Selection rectangle -->
      <div
        v-if="selectionRect"
        class="absolute border border-primary-400/50 bg-primary-400/10 pointer-events-none rounded"
        :style="{
          left: selectionRect.x + 'px',
          top: selectionRect.y + 'px',
          width: selectionRect.w + 'px',
          height: selectionRect.h + 'px',
        }"
      />
    </div>

    <!-- Quick-add menu -->
    <div
      v-if="quickAddMenu"
      ref="quickAddEl"
      class="fixed z-50 bg-white dark:bg-surface-800 border border-surface-200 dark:border-surface-600 rounded-xl shadow-2xl p-2 min-w-56 max-h-80 overflow-y-auto"
      :style="{ left: quickAddMenu.screenX + 'px', top: quickAddMenu.screenY + 'px' }"
      @wheel.stop
      @mousedown.stop
    >
      <div
        v-for="(items, group) in groupedNodes"
        :key="group"
        class="mb-1"
      >
        <div class="text-[10px] font-semibold text-surface-400 uppercase tracking-wider px-2 py-1">{{ group }}</div>
        <button
          v-for="item in items"
          :key="item.type"
          class="w-full flex items-center gap-2 px-2 py-1.5 rounded-lg text-left hover:bg-surface-50 dark:hover:bg-surface-700 transition-colors"
          @click="onQuickAddSelect(item.type)"
        >
          <span
            class="w-6 h-6 rounded-md flex items-center justify-center text-xs"
            :class="getCategoryColors(item.category).bg"
          >
            <span class="material-symbols-rounded text-sm" :class="getCategoryColors(item.category).text">{{ item.icon }}</span>
          </span>
          <span class="text-xs text-surface-700 dark:text-surface-200">{{ item.label }}</span>
        </button>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, onBeforeUnmount, nextTick } from 'vue'
import { useAutomationHubStore } from '../../stores/automationHub'
import { useNodeRegistry } from '../../composables/useNodeRegistry'
import WorkflowNode from './WorkflowNode.vue'

const store = useAutomationHubStore()
const { groupedNodes, getNodeDef, getCategoryColors } = useNodeRegistry()

const canvasContainer = ref(null)
const canvasLayer = ref(null)

// ── Drag state ────────────────────────────────────────────────────────
const dragStart = ref(null)
const dragNodeUid = ref(null)
const dragNodeOffset = ref({ x: 0, y: 0 })
const selectionStart = ref(null)
const selectionRect = ref(null)

// ── Quick-add menu ──────────────────────────────────────────────────
const quickAddMenu = ref(null)

// ── Canvas transform ────────────────────────────────────────────────
const canvasTransform = computed(() => ({
  transform: `translate(${store.panX}px, ${store.panY}px) scale(${store.zoom})`,
  transformOrigin: '0 0',
}))

// ── Dot grid ────────────────────────────────────────────────────────
const isDarkMode = computed(() => document.documentElement.classList.contains('dark'))
const dotGridStyle = computed(() => {
  const size = 20 * store.zoom
  const ox = store.panX % size
  const oy = store.panY % size
  const dotColor = isDarkMode.value
    ? 'rgba(148,163,184,0.15)'
    : 'rgba(100,116,139,0.2)'
  return {
    backgroundImage: `radial-gradient(circle, ${dotColor} 1px, transparent 1px)`,
    backgroundSize: `${size}px ${size}px`,
    backgroundPosition: `${ox}px ${oy}px`,
  }
})

// ── Coordinate conversion ───────────────────────────────────────────
function screenToCanvas(clientX, clientY) {
  const rect = canvasContainer.value.getBoundingClientRect()
  return {
    x: (clientX - rect.left - store.panX) / store.zoom,
    y: (clientY - rect.top - store.panY) / store.zoom,
  }
}

// ── Edge path calculation (n8n-style horizontal bezier) ─────────────
function getPortPosition(nodeUid, portId, isOutput) {
  const node = store.nodes.get(nodeUid)
  if (!node) return { x: 0, y: 0 }

  const def = getNodeDef(node.type)
  const nodeW = 220
  const nodeH = 64

  if (isOutput) {
    const outputs = def?.outputs || [{ id: 'output' }]
    const idx = outputs.findIndex(p => p.id === portId)
    const count = outputs.length
    const spacing = nodeH / (count + 1)
    return {
      x: node.x + nodeW,
      y: node.y + spacing * (idx + 1),
    }
  } else {
    return {
      x: node.x,
      y: node.y + nodeH / 2,
    }
  }
}

function getEdgePath(edge) {
  const from = getPortPosition(edge.sourceUid, edge.sourcePort, true)
  const to = getPortPosition(edge.targetUid, edge.targetPort, false)
  return buildBezierPath(from.x, from.y, to.x, to.y)
}

function getTempEdgePath() {
  if (!store.connectingFrom || !store.tempEdgeEnd) return ''
  const from = getPortPosition(store.connectingFrom.nodeUid, store.connectingFrom.portId, true)
  return buildBezierPath(from.x, from.y, store.tempEdgeEnd.x, store.tempEdgeEnd.y)
}

function buildBezierPath(x1, y1, x2, y2) {
  const dx = Math.abs(x2 - x1)
  const offset = Math.max(80, dx * 0.4)
  return `M ${x1} ${y1} C ${x1 + offset} ${y1}, ${x2 - offset} ${y2}, ${x2} ${y2}`
}

// ── Flow animation ──────────────────────────────────────────────────
const flowAnimEnabled = computed(() => {
  if (!store.showFlowAnimation) return false
  if (store.isDragging || store.isPanning) return false
  if (store.zoom < 0.3) return false
  if (store.edges.length > 30 && store.zoom < 0.5) return false
  return true
})

function getEdgeDotCount(edge) {
  const from = getPortPosition(edge.sourceUid, edge.sourcePort, true)
  const to = getPortPosition(edge.targetUid, edge.targetPort, false)
  const dist = Math.sqrt((to.x - from.x) ** 2 + (to.y - from.y) ** 2)
  if (dist < 300) return 2
  return 3
}

function getEdgeAnimDuration(edge) {
  const from = getPortPosition(edge.sourceUid, edge.sourcePort, true)
  const to = getPortPosition(edge.targetUid, edge.targetPort, false)
  const dist = Math.sqrt((to.x - from.x) ** 2 + (to.y - from.y) ** 2)
  return Math.max(1.5, Math.min(5, dist / 180))
}

// ── Global mouse tracking ────────────────────────────────────────────
// Attach move/up to window so drags never get stuck when the cursor
// leaves the canvas area (e.g. over sidebar, toolbar, config panel).
const isInteracting = ref(false)

function startGlobalTracking() {
  if (isInteracting.value) return
  isInteracting.value = true
  window.addEventListener('mousemove', onGlobalMouseMove)
  window.addEventListener('mouseup', onGlobalMouseUp)
}

function stopGlobalTracking() {
  isInteracting.value = false
  window.removeEventListener('mousemove', onGlobalMouseMove)
  window.removeEventListener('mouseup', onGlobalMouseUp)
}

// ── Mouse events ────────────────────────────────────────────────────
function onCanvasMouseDown(e) {
  quickAddMenu.value = null

  // Middle button or Alt+click = pan
  if (e.button === 1 || (e.button === 0 && e.altKey)) {
    store.isPanning = true
    dragStart.value = { x: e.clientX - store.panX, y: e.clientY - store.panY }
    startGlobalTracking()
    e.preventDefault()
    return
  }

  // Left click on empty canvas
  if (e.button === 0 && e.target === canvasContainer.value) {
    if (!e.shiftKey) {
      store.deselectAll()
    }
    const pos = screenToCanvas(e.clientX, e.clientY)
    selectionStart.value = pos
    startGlobalTracking()
  }
}

function onGlobalMouseMove(e) {
  // Panning
  if (store.isPanning && dragStart.value) {
    store.panX = e.clientX - dragStart.value.x
    store.panY = e.clientY - dragStart.value.y
    return
  }

  // Connection drawing
  if (store.isConnecting) {
    store.tempEdgeEnd = screenToCanvas(e.clientX, e.clientY)
    return
  }

  // Node dragging
  if (dragNodeUid.value) {
    const pos = screenToCanvas(e.clientX, e.clientY)
    const newX = pos.x - dragNodeOffset.value.x
    const newY = pos.y - dragNodeOffset.value.y
    store.updateNodePosition(dragNodeUid.value, newX, newY)
    return
  }

  // Selection rectangle
  if (selectionStart.value) {
    const pos = screenToCanvas(e.clientX, e.clientY)
    const x = Math.min(selectionStart.value.x, pos.x)
    const y = Math.min(selectionStart.value.y, pos.y)
    const w = Math.abs(pos.x - selectionStart.value.x)
    const h = Math.abs(pos.y - selectionStart.value.y)
    selectionRect.value = { x, y, w, h }
  }
}

function onGlobalMouseUp() {
  // Finish panning
  if (store.isPanning) {
    store.isPanning = false
    dragStart.value = null
  }

  // Finish connection
  if (store.isConnecting) {
    store.cancelConnecting()
  }

  // Finish node drag
  if (dragNodeUid.value) {
    dragNodeUid.value = null
    store.isDragging = false
  }

  // Finish selection rectangle
  if (selectionRect.value && selectionStart.value) {
    const r = selectionRect.value
    for (const node of store.nodesArray) {
      if (node.x + 220 > r.x && node.x < r.x + r.w &&
          node.y + 64 > r.y && node.y < r.y + r.h) {
        store.selectNode(node.uid, true)
      }
    }
  }
  selectionStart.value = null
  selectionRect.value = null

  stopGlobalTracking()
}

function onWheel(e) {
  const rect = canvasContainer.value.getBoundingClientRect()
  const mouseX = e.clientX - rect.left
  const mouseY = e.clientY - rect.top

  const oldZoom = store.zoom
  const delta = e.deltaY > 0 ? 0.92 : 1.08
  const newZoom = Math.max(0.1, Math.min(3, oldZoom * delta))

  store.panX = mouseX - (mouseX - store.panX) * (newZoom / oldZoom)
  store.panY = mouseY - (mouseY - store.panY) * (newZoom / oldZoom)
  store.zoom = newZoom
}

// ── Node events ─────────────────────────────────────────────────────
function onNodeMouseDown(e, node) {
  if (store.isConnecting) return

  e.stopPropagation()

  if (!store.selectedNodeUids.has(node.uid)) {
    store.selectNode(node.uid, e.shiftKey)
  }

  // Start dragging
  const pos = screenToCanvas(e.clientX, e.clientY)
  dragNodeUid.value = node.uid
  dragNodeOffset.value = { x: pos.x - node.x, y: pos.y - node.y }
  store.isDragging = true
  startGlobalTracking()
}

function onPortDragStart({ nodeUid, portId }) {
  store.startConnecting(nodeUid, portId)
  startGlobalTracking()
}

function onPortDragEnd({ nodeUid, portId }) {
  store.finishConnecting(nodeUid, portId)
}

// ── Quick-add ───────────────────────────────────────────────────────
function onQuickAdd({ nodeUid, portId, screenX, screenY }) {
  quickAddMenu.value = { nodeUid, portId, screenX, screenY }
}

function onQuickAddSelect(nodeType) {
  if (!quickAddMenu.value) return
  const def = getNodeDef(nodeType)
  if (!def) return

  const sourceNode = store.nodes.get(quickAddMenu.value.nodeUid)
  if (!sourceNode) return

  const newX = sourceNode.x + 300
  const newY = sourceNode.y
  const newUid = store.addNode(nodeType, def.category, newX, newY, def.label)

  const targetPort = def.inputs?.[0]?.id || 'input'
  store.addEdge(quickAddMenu.value.nodeUid, quickAddMenu.value.portId, newUid, targetPort)

  store.selectNode(newUid)
  quickAddMenu.value = null
}

// ── Keyboard ────────────────────────────────────────────────────────
function onKeyDown(e) {
  if (e.key === 'Delete' || e.key === 'Backspace') {
    if (document.activeElement?.tagName === 'INPUT' || document.activeElement?.tagName === 'TEXTAREA') return
    store.deleteSelected()
    e.preventDefault()
  }
  if (e.key === 'Escape') {
    // Force-release any stuck drag/connect state
    if (dragNodeUid.value) {
      dragNodeUid.value = null
      store.isDragging = false
      stopGlobalTracking()
    }
    if (store.isPanning) {
      store.isPanning = false
      dragStart.value = null
      stopGlobalTracking()
    }
    if (store.isConnecting) {
      store.cancelConnecting()
      stopGlobalTracking()
    } else if (quickAddMenu.value) {
      quickAddMenu.value = null
    } else {
      store.deselectAll()
    }
    selectionStart.value = null
    selectionRect.value = null
  }
  if (e.key === 's' && (e.ctrlKey || e.metaKey)) {
    e.preventDefault()
    store.saveWorkflow()
  }
}

// ── Drop from palette ───────────────────────────────────────────────
function onDrop(e) {
  const nodeType = e.dataTransfer.getData('automation-hub/node-type')
  if (!nodeType) return

  const def = getNodeDef(nodeType)
  if (!def) return

  const pos = screenToCanvas(e.clientX, e.clientY)
  const uid = store.addNode(nodeType, def.category, pos.x - 110, pos.y - 32, def.label)
  store.selectNode(uid)
}

function onDragOver(e) {
  if (e.dataTransfer.types.includes('automation-hub/node-type')) {
    e.preventDefault()
    e.dataTransfer.dropEffect = 'copy'
  }
}

onMounted(() => {
  if (canvasContainer.value) {
    canvasContainer.value.addEventListener('drop', onDrop)
    canvasContainer.value.addEventListener('dragover', onDragOver)
  }
})

onBeforeUnmount(() => {
  stopGlobalTracking()
  if (canvasContainer.value) {
    canvasContainer.value.removeEventListener('drop', onDrop)
    canvasContainer.value.removeEventListener('dragover', onDragOver)
  }
})
</script>
