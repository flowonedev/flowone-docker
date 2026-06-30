<script setup>
import { computed, ref, onMounted, onUnmounted, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import OnboardingFlowNode from './OnboardingFlowNode.vue'
import OnboardingNodeTooltip from './OnboardingNodeTooltip.vue'

const { t } = useI18n()

const props = defineProps({
  currentStep: { type: Number, default: 0 },
})

const hoveredNode = ref(null)
const tooltipStyle = ref({})
let hoverTimeout = null

function onNodeEnter(nodeId, event) {
  clearTimeout(hoverTimeout)
  hoverTimeout = setTimeout(() => {
    hoveredNode.value = nodeId
    positionTooltip(nodeId, event)
  }, 300)
}

function onNodeLeave() {
  clearTimeout(hoverTimeout)
  hoverTimeout = setTimeout(() => {
    hoveredNode.value = null
  }, 150)
}

function positionTooltip(nodeId, event) {
  if (!container.value) return
  const p = nodePositions[nodeId]
  if (!p) return

  const rect = container.value.getBoundingClientRect()
  const nodeX = rect.left + p.x * rect.width
  const nodeY = rect.top + p.y * rect.height

  const tooltipW = 384
  const tooltipH = 440
  const gap = 16
  const vw = window.innerWidth
  const vh = window.innerHeight

  let left, top

  // Horizontal: prefer right of node, fall back to left
  if (nodeX + gap + tooltipW < vw - 20) {
    left = nodeX + gap
  } else if (nodeX - gap - tooltipW > 20) {
    left = nodeX - gap - tooltipW
  } else {
    left = Math.max(20, (vw - tooltipW) / 2)
  }

  // Vertical: center on node, clamp to viewport
  top = nodeY - tooltipH / 2
  top = Math.max(20, Math.min(top, vh - tooltipH - 20))

  tooltipStyle.value = {
    left: left + 'px',
    top: top + 'px',
  }
}

const container = ref(null)
const cW = ref(1100)
const cH = ref(620)

function measure() {
  if (!container.value) return
  cW.value = container.value.clientWidth
  cH.value = container.value.clientHeight
}

onMounted(() => {
  measure()
  window.addEventListener('resize', measure)
})
onUnmounted(() => window.removeEventListener('resize', measure))

// ── Node definitions ──
// Each node has: id, icon, labelKey (i18n path), step (when it appears)
const nodeDefs = [
  { id: 'email',           icon: 'mail',               labelKey: 'onboarding.nodes.email',           step: 2 },
  { id: 'conversations',   icon: 'forum',              labelKey: 'onboarding.nodes.conversations',   step: 3 },
  { id: 'crmClient',       icon: 'person_add',         labelKey: 'onboarding.nodes.crmClient',       step: 4 },
  { id: 'tasksBoards',     icon: 'view_kanban',        labelKey: 'onboarding.nodes.tasksBoards',     step: 5 },
  { id: 'timeTracking',    icon: 'timer',              labelKey: 'onboarding.nodes.timeTracking',    step: 6 },
  { id: 'clientReport',    icon: 'assessment',         labelKey: 'onboarding.nodes.clientReport',    step: 7 },
  { id: 'invoice',         icon: 'receipt_long',       labelKey: 'onboarding.nodes.invoice',         step: 8 },
  { id: 'pipelines',       icon: 'filter_alt',         labelKey: 'onboarding.nodes.pipelines',       step: 9 },
  { id: 'automations',     icon: 'bolt',               labelKey: 'onboarding.nodes.automations',     step: 10 },
  { id: 'boardAutomations',icon: 'smart_toy',          labelKey: 'onboarding.nodes.boardAutomations', step: 10 },
  { id: 'sequences',       icon: 'schedule_send',      labelKey: 'onboarding.nodes.sequences',       step: 10 },
  { id: 'teamLists',       icon: 'groups',             labelKey: 'onboarding.nodes.teamLists',       step: 11 },
  { id: 'emailingLists',   icon: 'contact_mail',       labelKey: 'onboarding.nodes.emailingLists',   step: 11 },
  { id: 'emailCampaigns',  icon: 'campaign',           labelKey: 'onboarding.nodes.emailCampaigns',  step: 11 },
  { id: 'drive',           icon: 'cloud',              labelKey: 'onboarding.nodes.drive',           step: 12 },
  { id: 'chat',            icon: 'chat',               labelKey: 'onboarding.nodes.chat',            step: 12 },
  { id: 'video',           icon: 'videocam',           labelKey: 'onboarding.nodes.video',           step: 12 },
  { id: 'moodboards',      icon: 'dashboard',          labelKey: 'onboarding.nodes.moodboards',      step: 12 },
]

// ── Node positions (percent of container) ──
// Laid out as 3 branches from a top-center email node
const nodePositions = {
  // Main vertical branch (center-left)
  email:            { x: 0.12, y: 0.08 },
  conversations:    { x: 0.12, y: 0.22 },
  crmClient:        { x: 0.12, y: 0.38 },
  tasksBoards:      { x: 0.12, y: 0.54 },
  timeTracking:     { x: 0.12, y: 0.70 },
  clientReport:     { x: 0.12, y: 0.84 },
  invoice:          { x: 0.24, y: 0.93 },

  // Branch 2: from crmClient going right (pipelines/automations)
  pipelines:        { x: 0.38, y: 0.38 },
  automations:      { x: 0.54, y: 0.38 },
  boardAutomations: { x: 0.54, y: 0.52 },
  sequences:        { x: 0.54, y: 0.66 },

  // Branch 3: from email going right (marketing)
  teamLists:        { x: 0.38, y: 0.08 },
  emailingLists:    { x: 0.54, y: 0.08 },
  emailCampaigns:   { x: 0.70, y: 0.08 },

  // Collaboration cluster (far right, middle)
  drive:            { x: 0.78, y: 0.32 },
  chat:             { x: 0.78, y: 0.46 },
  video:            { x: 0.78, y: 0.60 },
  moodboards:       { x: 0.78, y: 0.74 },
}

// Pixel positions for each node center
function nodeCenter(id) {
  const p = nodePositions[id]
  if (!p) return { x: 0, y: 0 }
  return { x: p.x * cW.value, y: p.y * cH.value }
}

// ── Connection definitions ──
// from → to, step when drawn, optional branch color
const connectionDefs = [
  // Main vertical branch
  { from: 'email',         to: 'conversations',    step: 3,  branch: 'main' },
  { from: 'conversations', to: 'crmClient',        step: 4,  branch: 'main' },
  { from: 'crmClient',     to: 'tasksBoards',      step: 5,  branch: 'main' },
  { from: 'tasksBoards',   to: 'timeTracking',     step: 6,  branch: 'main' },
  { from: 'timeTracking',  to: 'clientReport',     step: 7,  branch: 'main' },
  { from: 'clientReport',  to: 'invoice',          step: 8,  branch: 'main' },

  // Pipeline / automation branch
  { from: 'crmClient',      to: 'pipelines',       step: 9,  branch: 'pipeline' },
  { from: 'pipelines',      to: 'automations',     step: 10, branch: 'pipeline' },
  { from: 'automations',    to: 'boardAutomations', step: 10, branch: 'pipeline' },
  { from: 'boardAutomations', to: 'sequences',     step: 10, branch: 'pipeline' },
  { from: 'sequences',      to: 'invoice',         step: 10, branch: 'pipeline' },

  // Marketing branch
  { from: 'email',          to: 'teamLists',       step: 11, branch: 'marketing' },
  { from: 'teamLists',      to: 'emailingLists',   step: 11, branch: 'marketing' },
  { from: 'emailingLists',  to: 'emailCampaigns',  step: 11, branch: 'marketing' },

  // Collaboration links
  { from: 'tasksBoards',    to: 'drive',           step: 12, branch: 'collab' },
  { from: 'tasksBoards',    to: 'chat',            step: 12, branch: 'collab' },
  { from: 'conversations',  to: 'video',           step: 12, branch: 'collab' },
  { from: 'conversations',  to: 'moodboards',      step: 12, branch: 'collab' },
]

const branchColors = {
  main:      { start: '#6366f1', end: '#8b5cf6' },
  pipeline:  { start: '#f59e0b', end: '#ef4444' },
  marketing: { start: '#10b981', end: '#06b6d4' },
  collab:    { start: '#ec4899', end: '#8b5cf6' },
}

// Cubic bezier path between two nodes
function connPath(fromId, toId) {
  const a = nodeCenter(fromId)
  const b = nodeCenter(toId)
  const dx = b.x - a.x
  const dy = b.y - a.y

  // Prefer vertical curves for the main branch, horizontal for others
  let cp1x, cp1y, cp2x, cp2y
  if (Math.abs(dy) > Math.abs(dx)) {
    cp1x = a.x
    cp1y = a.y + dy * 0.45
    cp2x = b.x
    cp2y = b.y - dy * 0.45
  } else {
    cp1x = a.x + dx * 0.45
    cp1y = a.y
    cp2x = b.x - dx * 0.45
    cp2y = b.y
  }
  return `M ${a.x} ${a.y} C ${cp1x} ${cp1y}, ${cp2x} ${cp2y}, ${b.x} ${b.y}`
}

function estimatePathLength(fromId, toId) {
  const a = nodeCenter(fromId)
  const b = nodeCenter(toId)
  const dx = b.x - a.x
  const dy = b.y - a.y
  return Math.sqrt(dx * dx + dy * dy) * 1.3
}

// Which connections are visible (drawn on or before current step)
const visibleConnections = computed(() =>
  connectionDefs.filter(c => c.step <= props.currentStep)
)

// Which connections are being drawn right now
const activeConnections = computed(() =>
  connectionDefs.filter(c => c.step === props.currentStep)
)

// Track which steps have completed their draw-on
const drawnSteps = ref(new Set())
watch(() => props.currentStep, (newStep, oldStep) => {
  if (oldStep !== undefined && oldStep > 0) {
    drawnSteps.value.add(oldStep)
  }
})

function isDrawingOn(conn) {
  return conn.step === props.currentStep && !drawnSteps.value.has(conn.step)
}

// Build a set of node IDs directly connected to the hovered node
const connectedToHovered = computed(() => {
  if (!hoveredNode.value) return null
  const ids = new Set([hoveredNode.value])
  for (const c of connectionDefs) {
    if (c.step > props.currentStep) continue
    if (c.from === hoveredNode.value) ids.add(c.to)
    if (c.to === hoveredNode.value) ids.add(c.from)
  }
  return ids
})

function isConnInHoverFocus(conn) {
  if (!hoveredNode.value) return true
  return conn.from === hoveredNode.value || conn.to === hoveredNode.value
}

// Node state helpers
function nodeState(nodeDef) {
  if (props.currentStep <= 1 || props.currentStep >= 13) {
    return props.currentStep >= 13 ? 'visited' : 'dimmed'
  }
  if (nodeDef.step === props.currentStep) return 'active'
  if (nodeDef.step < props.currentStep) return 'visited'
  return 'dimmed'
}

function isNodeHoverDimmed(nodeId) {
  if (!connectedToHovered.value) return false
  return !connectedToHovered.value.has(nodeId)
}

function nodeVisible(nodeDef) {
  return props.currentStep >= nodeDef.step || props.currentStep >= 13
}
</script>

<template>
  <div ref="container" class="relative w-full h-full overflow-hidden">
    <!-- SVG layer for connections -->
    <svg
      class="absolute inset-0 w-full h-full pointer-events-none"
      :viewBox="`0 0 ${cW} ${cH}`"
      preserveAspectRatio="xMidYMid meet"
    >
      <defs>
        <!-- Gradient per branch -->
        <linearGradient
          v-for="conn in visibleConnections"
          :key="'grad-' + conn.from + '-' + conn.to"
          :id="'onb-grad-' + conn.from + '-' + conn.to"
          gradientUnits="userSpaceOnUse"
          :x1="nodeCenter(conn.from).x"
          :y1="nodeCenter(conn.from).y"
          :x2="nodeCenter(conn.to).x"
          :y2="nodeCenter(conn.to).y"
        >
          <stop offset="0%" :stop-color="branchColors[conn.branch]?.start || '#6366f1'" />
          <stop offset="100%" :stop-color="branchColors[conn.branch]?.end || '#8b5cf6'" />
        </linearGradient>

        <!-- Glow filter -->
        <filter id="onb-glow" x="-100%" y="-100%" width="300%" height="300%">
          <feGaussianBlur in="SourceGraphic" stdDeviation="6" />
        </filter>
      </defs>

      <!-- Rendered connections -->
      <g
        v-for="conn in visibleConnections"
        :key="'conn-' + conn.from + '-' + conn.to"
        class="onb-conn-group"
        :style="{ opacity: isConnInHoverFocus(conn) ? 1 : 0.08, transition: 'opacity 0.3s ease' }"
      >
        <!-- Glow behind active connections -->
        <path
          v-if="conn.step === currentStep"
          :d="connPath(conn.from, conn.to)"
          :stroke="`url(#onb-grad-${conn.from}-${conn.to})`"
          stroke-width="8"
          fill="none"
          stroke-opacity="0.3"
          stroke-linecap="round"
          filter="url(#onb-glow)"
        />

        <!-- Main path -->
        <path
          :d="connPath(conn.from, conn.to)"
          :stroke="`url(#onb-grad-${conn.from}-${conn.to})`"
          :stroke-width="isConnInHoverFocus(conn) && hoveredNode ? 3 : 2"
          fill="none"
          :stroke-opacity="conn.step === currentStep ? 1 : 0.4"
          stroke-linecap="round"
          :class="isDrawingOn(conn) ? 'onb-draw-on' : ''"
          :style="isDrawingOn(conn) ? {
            strokeDasharray: estimatePathLength(conn.from, conn.to),
            strokeDashoffset: estimatePathLength(conn.from, conn.to),
            '--draw-len': estimatePathLength(conn.from, conn.to),
            '--draw-dur': '0.8s',
          } : {}"
        />

        <!-- Flowing dots on active connections -->
        <template v-if="conn.step <= currentStep">
          <circle
            v-for="dotIdx in (conn.step === currentStep ? 3 : 1)"
            :key="'dot-' + conn.from + '-' + conn.to + '-' + dotIdx"
            :r="conn.step === currentStep ? 3.5 : 2"
            :fill="branchColors[conn.branch]?.end || '#8b5cf6'"
            :opacity="conn.step === currentStep ? 0.9 : 0.35"
          >
            <animateMotion
              :dur="(conn.step === currentStep ? 2.5 : 5) + 's'"
              repeatCount="indefinite"
              :path="connPath(conn.from, conn.to)"
              :begin="'-' + ((dotIdx - 1) / 3 * 2.5).toFixed(2) + 's'"
            />
          </circle>
        </template>
      </g>
    </svg>

    <!-- Node layer (HTML via absolute positioning) -->
    <template v-for="nodeDef in nodeDefs" :key="nodeDef.id">
      <Transition
        enter-active-class="transition-all duration-500 ease-out"
        enter-from-class="opacity-0 scale-90"
        leave-active-class="transition-all duration-300 ease-in"
        leave-to-class="opacity-0 scale-90"
      >
        <div
          v-if="nodeVisible(nodeDef)"
          class="absolute -translate-x-1/2 -translate-y-1/2 z-10 transition-all duration-300"
          :style="{
            left: (nodePositions[nodeDef.id].x * 100) + '%',
            top: (nodePositions[nodeDef.id].y * 100) + '%',
            opacity: isNodeHoverDimmed(nodeDef.id) ? 0.12 : 1,
            filter: isNodeHoverDimmed(nodeDef.id) ? 'blur(2px)' : 'none',
            transform: `translate(-50%, -50%) ${hoveredNode === nodeDef.id ? 'scale(1.08)' : 'scale(1)'}`,
          }"
          @mouseenter="onNodeEnter(nodeDef.id, $event)"
          @mouseleave="onNodeLeave"
        >
          <OnboardingFlowNode
            :icon="nodeDef.icon"
            :label="t(nodeDef.labelKey)"
            :active="nodeState(nodeDef) === 'active'"
            :visited="nodeState(nodeDef) === 'visited'"
            :dimmed="nodeState(nodeDef) === 'dimmed'"
            :size="['drive','chat','video','moodboards'].includes(nodeDef.id) ? 'sm' : 'md'"
          />
        </div>
      </Transition>
    </template>

    <!-- Hover tooltip (Teleported to body so it never clips) -->
    <Teleport to="body">
      <Transition
        enter-active-class="transition-opacity duration-200"
        enter-from-class="opacity-0"
        leave-active-class="transition-opacity duration-150"
        leave-to-class="opacity-0"
      >
        <OnboardingNodeTooltip
          v-if="hoveredNode"
          :nodeId="hoveredNode"
          :style="tooltipStyle"
          @mouseenter="onNodeEnter(hoveredNode, $event)"
          @mouseleave="onNodeLeave"
        />
      </Transition>
    </Teleport>

    <!-- Collaboration bracket label -->
    <Transition
      enter-active-class="transition-opacity duration-500"
      enter-from-class="opacity-0"
    >
      <div
        v-if="currentStep >= 12"
        class="absolute z-10 flex items-center gap-1 text-xs font-semibold text-pink-500 dark:text-pink-400"
        :style="{
          left: (0.78 * 100) + '%',
          top: (0.22 * 100) + '%',
          transform: 'translateX(-50%)',
        }"
      >
        <span class="material-symbols-rounded text-sm">hub</span>
        {{ t('onboarding.steps.collaboration.title') }}
      </div>
    </Transition>
  </div>
</template>

<style scoped>
.onb-draw-on {
  animation: onbDrawOn var(--draw-dur, 0.8s) ease-out forwards;
}

@keyframes onbDrawOn {
  to {
    stroke-dashoffset: 0;
  }
}
</style>
