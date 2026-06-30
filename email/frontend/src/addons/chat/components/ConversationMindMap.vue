<script setup>
import { ref, computed, onMounted, onUnmounted, nextTick, watch } from 'vue'
import { useChatStore } from '@/addons/chat/stores/chat'
import { useColleaguesStore } from '@/addons/team/stores/colleagues'
import api from '@/services/api'

const emit = defineEmits(['close', 'open-thread'])

const chatStore = useChatStore()
const colleaguesStore = useColleaguesStore()

const loading = ref(true)
const mapContainer = ref(null)
const isDragging = ref(false)
const dragStart = ref({ x: 0, y: 0 })
const panOffset = ref({ x: 0, y: 0 })
const zoom = ref(1)
const hoveredNode = ref(null)
const selectedNode = ref(null)
const isMobile = ref(false)

// Detect mobile
function checkMobile() {
  isMobile.value = window.innerWidth < 768 || ('ontouchstart' in window)
}

// Fetch all messages with their thread info
const treeData = ref([])

// Grouping: track which stacks are expanded
const expandedGroups = ref(new Set())
const GROUP_SIZE = 50 // Max messages per collapsed stack

// Node dimensions — responsive based on screen size
const NODE_W = computed(() => isMobile.value ? 220 : 260)
const NODE_H = computed(() => isMobile.value ? 60 : 68)
const THREAD_NODE_W = computed(() => isMobile.value ? 190 : 220)
const THREAD_NODE_H = computed(() => isMobile.value ? 50 : 56)
const GAP_Y = computed(() => isMobile.value ? 18 : 24)
const THREAD_GAP_Y = computed(() => isMobile.value ? 10 : 14)
const BRANCH_OFFSET_X = computed(() => isMobile.value ? 250 : 320)
const STACK_CARD_OFFSET = 4 // px offset for stacked card shadows

// Touch state for pinch-to-zoom and pan
let lastTouchDist = 0
let lastTouchCenter = { x: 0, y: 0 }

// Color palette for senders
const senderColors = {}
const palette = [
  '#6366f1', '#8b5cf6', '#a855f7', '#d946ef', '#ec4899',
  '#f43f5e', '#ef4444', '#f97316', '#eab308', '#22c55e',
  '#14b8a6', '#06b6d4', '#3b82f6', '#6366f1'
]
let colorIndex = 0

function getSenderColor(senderId) {
  if (!senderColors[senderId]) {
    senderColors[senderId] = palette[colorIndex % palette.length]
    colorIndex++
  }
  return senderColors[senderId]
}

function getColleague(msg) {
  return colleaguesStore.colleagueById?.[msg.sender_id] || {
    display_name: msg.sender_name,
    email: msg.sender_email
  }
}

function getInitials(name) {
  if (!name) return '?'
  return name.split(' ').map(w => w[0]).join('').substring(0, 2).toUpperCase()
}

function truncate(text, max = 50) {
  if (!text) return ''
  const clean = text
    .replace(/\[embed:\w+:\d+\]/g, 'Shared content')
    .replace(/\[voice:\d+\]/g, 'Voice message')
    .replace(/\[gif:[^\]]+\]/g, 'GIF')
    .replace(/\[call:[^\]]+\]/g, 'Call')
  return clean.length > max ? clean.substring(0, max) + '...' : clean
}

function formatTime(dateStr) {
  if (!dateStr) return ''
  const d = new Date(dateStr)
  const now = new Date()
  const diffMs = now - d
  const diffHours = Math.floor(diffMs / 3600000)
  if (diffHours < 1) return `${Math.floor(diffMs / 60000)}m ago`
  if (diffHours < 24) return `${diffHours}h ago`
  if (diffHours < 48) return 'Yesterday'
  return d.toLocaleDateString([], { month: 'short', day: 'numeric' })
}

// Group consecutive standalone messages (no thread) into stacks
const groupedItems = computed(() => {
  const items = []
  let standaloneBuffer = []
  let groupCounter = 0

  function flushBuffer() {
    if (standaloneBuffer.length === 0) return
    
    if (standaloneBuffer.length <= 3) {
      // Too few to group — show individually
      for (const msg of standaloneBuffer) {
        items.push({ type: 'single', msg, groupId: null })
      }
    } else {
      // Create groups of GROUP_SIZE
      for (let i = 0; i < standaloneBuffer.length; i += GROUP_SIZE) {
        const chunk = standaloneBuffer.slice(i, i + GROUP_SIZE)
        if (chunk.length <= 3) {
          // Remaining few — show individually
          for (const msg of chunk) {
            items.push({ type: 'single', msg, groupId: null })
          }
        } else {
          const gId = `group-${groupCounter++}`
          items.push({
            type: 'group',
            groupId: gId,
            messages: chunk,
            topMsg: chunk[0],
            count: chunk.length
          })
        }
      }
    }
    standaloneBuffer = []
  }

  for (const msg of treeData.value) {
    const hasThread = (msg.replies?.length || 0) > 0
    if (hasThread) {
      flushBuffer()
      items.push({ type: 'threaded', msg, groupId: null })
    } else {
      standaloneBuffer.push(msg)
    }
  }
  flushBuffer()

  return items
})

function toggleGroup(groupId) {
  const next = new Set(expandedGroups.value)
  if (next.has(groupId)) {
    next.delete(groupId)
  } else {
    next.add(groupId)
  }
  expandedGroups.value = next
}

function isGroupExpanded(groupId) {
  return expandedGroups.value.has(groupId)
}

// Compute positions for the tree using grouped data
const positionedNodes = computed(() => {
  const nodes = []
  let mainY = 40
  const mainX = isMobile.value ? 16 : 40
  const nw = NODE_W.value
  const nh = NODE_H.value
  const tnw = THREAD_NODE_W.value
  const tnh = THREAD_NODE_H.value
  const gy = GAP_Y.value
  const tgy = THREAD_GAP_Y.value
  const branchX = BRANCH_OFFSET_X.value

  for (const item of groupedItems.value) {
    if (item.type === 'single') {
      // Single standalone message
      const msg = item.msg
      const color = getSenderColor(msg.sender_id)
      nodes.push({
        id: msg.id,
        x: mainX,
        y: mainY,
        w: nw,
        h: nh,
        type: 'main',
        msg,
        color,
        hasThread: false,
        replyCount: 0,
        groupId: null,
        isStack: false
      })
      mainY += nh + gy

    } else if (item.type === 'threaded') {
      // Message with thread — always shown, with branch replies
      const msg = item.msg
      const color = getSenderColor(msg.sender_id)

      nodes.push({
        id: msg.id,
        x: mainX,
        y: mainY,
        w: nw,
        h: nh,
        type: 'main',
        msg,
        color,
        hasThread: true,
        replyCount: msg.replies?.length || 0,
        groupId: null,
        isStack: false
      })

      // Thread replies branch to the right
      if (msg.replies?.length > 0) {
        let threadY = mainY - ((msg.replies.length - 1) * (tnh + tgy)) / 2

        for (let i = 0; i < msg.replies.length; i++) {
          const reply = msg.replies[i]
          const replyColor = getSenderColor(reply.sender_id)

          nodes.push({
            id: reply.id,
            parentId: msg.id,
            x: mainX + branchX,
            y: threadY,
            w: tnw,
            h: tnh,
            type: 'thread',
            msg: reply,
            color: replyColor,
            threadIndex: i,
            groupId: null,
            isStack: false
          })

          threadY += tnh + tgy
        }

        const threadClusterHeight = msg.replies.length * (tnh + tgy)
        mainY += Math.max(nh + gy, threadClusterHeight + gy)
      } else {
        mainY += nh + gy
      }

    } else if (item.type === 'group') {
      const expanded = isGroupExpanded(item.groupId)

      if (expanded) {
        // Show all messages in this group individually
        for (let i = 0; i < item.messages.length; i++) {
          const msg = item.messages[i]
          const color = getSenderColor(msg.sender_id)
          nodes.push({
            id: msg.id,
            x: mainX,
            y: mainY,
            w: nw,
            h: nh,
            type: 'main',
            msg,
            color,
            hasThread: false,
            replyCount: 0,
            groupId: item.groupId,
            isStack: false,
            isExpanded: true,
            isFirstInGroup: i === 0,
            isLastInGroup: i === item.messages.length - 1,
            groupCount: item.messages.length
          })
          mainY += nh + gy
        }
      } else {
        // Show collapsed stack — one visible card with shadow cards behind it
        const msg = item.topMsg
        const color = getSenderColor(msg.sender_id)
        const stackHeight = nh + Math.min(item.count - 1, 3) * STACK_CARD_OFFSET

        nodes.push({
          id: `stack-${item.groupId}`,
          x: mainX,
          y: mainY,
          w: nw,
          h: stackHeight,
          type: 'stack',
          msg,
          color,
          groupId: item.groupId,
          isStack: true,
          stackCount: item.count,
          stackMessages: item.messages,
          // Collect unique senders for stack preview
          stackSenders: [...new Set(item.messages.map(m => m.sender_id))]
        })
        mainY += stackHeight + gy + 8
      }
    }
  }

  return nodes
})

// SVG connections
const connections = computed(() => {
  const lines = []
  const mainAndStackNodes = positionedNodes.value.filter(n => n.type === 'main' || n.type === 'stack')
  const threadNodes = positionedNodes.value.filter(n => n.type === 'thread')

  // Vertical spine between main/stack messages
  for (let i = 0; i < mainAndStackNodes.length - 1; i++) {
    const from = mainAndStackNodes[i]
    const to = mainAndStackNodes[i + 1]
    lines.push({
      type: 'spine',
      x1: from.x + from.w / 2,
      y1: from.y + from.h,
      x2: to.x + to.w / 2,
      y2: to.y
    })
  }

  // Branch connections from main to thread replies
  for (const tn of threadNodes) {
    const parent = positionedNodes.value.find(n => n.id === tn.parentId)
    if (!parent) continue

    lines.push({
      type: 'branch',
      parentId: tn.parentId,
      x1: parent.x + parent.w,
      y1: parent.y + parent.h / 2,
      x2: tn.x,
      y2: tn.y + tn.h / 2
    })
  }

  return lines
})

// Total canvas size
const canvasSize = computed(() => {
  let maxX = 800, maxY = 600
  for (const n of positionedNodes.value) {
    maxX = Math.max(maxX, n.x + n.w + 80)
    maxY = Math.max(maxY, n.y + n.h + 80)
  }
  return { width: maxX, height: maxY }
})

// Conversation name
const conversationName = computed(() => {
  const conv = chatStore.activeConversation
  if (!conv) return 'Conversation'
  return conv.name || conv.display_name || 'Conversation'
})

// Stats
const totalMessages = computed(() => {
  let count = 0
  for (const msg of treeData.value) {
    count++
    count += msg.replies?.length || 0
  }
  return count
})

const threadCount = computed(() => {
  return treeData.value.filter(m => (m.replies?.length || 0) > 0).length
})

const groupCount = computed(() => {
  return groupedItems.value.filter(i => i.type === 'group').length
})

// Data loading
async function loadMindMapData() {
  loading.value = true
  const conversationId = chatStore.activeConversationId
  if (!conversationId) return

  try {
    // Get the main messages (already loaded in store, or fetch)
    let mainMessages = chatStore.messages[conversationId] || []

    if (!mainMessages.length) {
      const res = await api.get(`/chat/conversations/${conversationId}/messages`, { params: { limit: 500 } })
      if (res.data.success) {
        mainMessages = res.data.data.messages || []
      }
    }

    // Only include top-level messages (no reply_to_id)
    const topLevel = mainMessages.filter(m => !m.reply_to_id && m.content_type !== 'system')

    // For messages with reply_count > 0, fetch their threads
    const messagesWithThreads = []
    for (const msg of topLevel) {
      const entry = { ...msg, replies: [] }

      if ((msg.reply_count || 0) > 0) {
        try {
          const threadRes = await api.get(`/chat/messages/${msg.id}/thread`)
          if (threadRes.data.success) {
            const threadMsgs = threadRes.data.data?.messages || []
            // First message is the parent, rest are replies
            entry.replies = threadMsgs.slice(1)
          }
        } catch (e) {
          console.error('Failed to fetch thread for message', msg.id, e)
        }
      }

      messagesWithThreads.push(entry)
    }

    treeData.value = messagesWithThreads
  } catch (e) {
    console.error('Failed to load mind map data:', e)
  } finally {
    loading.value = false
  }
}

// Pan & zoom
function onWheel(e) {
  e.preventDefault()
  if (e.ctrlKey || e.metaKey) {
    // Zoom
    const delta = e.deltaY > 0 ? -0.08 : 0.08
    zoom.value = Math.max(0.3, Math.min(2, zoom.value + delta))
  } else {
    // Pan
    panOffset.value = {
      x: panOffset.value.x - e.deltaX,
      y: panOffset.value.y - e.deltaY
    }
  }
}

function onMouseDown(e) {
  if (e.target.closest('.mind-map-node')) return
  isDragging.value = true
  dragStart.value = { x: e.clientX - panOffset.value.x, y: e.clientY - panOffset.value.y }
}

function onMouseMove(e) {
  if (!isDragging.value) return
  panOffset.value = {
    x: e.clientX - dragStart.value.x,
    y: e.clientY - dragStart.value.y
  }
}

function onMouseUp() {
  isDragging.value = false
}

// ============================================
// TOUCH EVENTS (mobile pan + pinch-to-zoom)
// ============================================

function getTouchDist(touches) {
  const dx = touches[0].clientX - touches[1].clientX
  const dy = touches[0].clientY - touches[1].clientY
  return Math.sqrt(dx * dx + dy * dy)
}

function getTouchCenter(touches) {
  return {
    x: (touches[0].clientX + touches[1].clientX) / 2,
    y: (touches[0].clientY + touches[1].clientY) / 2
  }
}

function onTouchStart(e) {
  if (e.touches.length === 1) {
    // Single finger drag to pan
    if (e.target.closest('.mind-map-node')) return
    isDragging.value = true
    dragStart.value = {
      x: e.touches[0].clientX - panOffset.value.x,
      y: e.touches[0].clientY - panOffset.value.y
    }
  } else if (e.touches.length === 2) {
    // Pinch to zoom
    e.preventDefault()
    isDragging.value = false
    lastTouchDist = getTouchDist(e.touches)
    lastTouchCenter = getTouchCenter(e.touches)
  }
}

function onTouchMove(e) {
  if (e.touches.length === 1 && isDragging.value) {
    e.preventDefault()
    panOffset.value = {
      x: e.touches[0].clientX - dragStart.value.x,
      y: e.touches[0].clientY - dragStart.value.y
    }
  } else if (e.touches.length === 2) {
    e.preventDefault()
    const dist = getTouchDist(e.touches)
    const center = getTouchCenter(e.touches)

    // Pinch zoom
    if (lastTouchDist > 0) {
      const scale = dist / lastTouchDist
      zoom.value = Math.max(0.3, Math.min(2, zoom.value * scale))
    }

    // Two-finger pan
    panOffset.value = {
      x: panOffset.value.x + (center.x - lastTouchCenter.x),
      y: panOffset.value.y + (center.y - lastTouchCenter.y)
    }

    lastTouchDist = dist
    lastTouchCenter = center
  }
}

function onTouchEnd(e) {
  if (e.touches.length < 2) {
    lastTouchDist = 0
  }
  if (e.touches.length === 0) {
    isDragging.value = false
  }
}

function resetView() {
  panOffset.value = { x: 0, y: 0 }
  zoom.value = isMobile.value ? 0.7 : 1
}

function zoomIn() {
  zoom.value = Math.min(2, zoom.value + 0.15)
}

function zoomOut() {
  zoom.value = Math.max(0.3, zoom.value - 0.15)
}

function expandAll() {
  const all = new Set()
  for (const item of groupedItems.value) {
    if (item.type === 'group') all.add(item.groupId)
  }
  expandedGroups.value = all
}

function collapseAll() {
  expandedGroups.value = new Set()
}

function handleNodeClick(node) {
  if (node.type === 'stack') {
    toggleGroup(node.groupId)
    return
  }
  if (node.isExpanded && node.isFirstInGroup && node.groupId) {
    // Click first card header to collapse back
    // (handled by the collapse button instead)
  }
  if (node.type === 'main' && node.hasThread) {
    emit('open-thread', node.id)
    emit('close')
  } else if (node.type === 'thread') {
    emit('open-thread', node.parentId)
    emit('close')
  }
  selectedNode.value = node.id
}

// Generate bezier curve path for branch
function branchPath(conn) {
  const cx = (conn.x1 + conn.x2) / 2
  return `M ${conn.x1} ${conn.y1} C ${cx} ${conn.y1}, ${cx} ${conn.y2}, ${conn.x2} ${conn.y2}`
}

onMounted(() => {
  checkMobile()
  window.addEventListener('resize', checkMobile)
  // Start zoomed out on mobile so the map is visible
  if (isMobile.value) {
    zoom.value = 0.65
  }
  loadMindMapData()
})

onUnmounted(() => {
  window.removeEventListener('resize', checkMobile)
})
</script>

<template>
  <Teleport to="body">
    <div class="fixed inset-0 z-[10000] flex flex-col bg-surface-950/95 backdrop-blur-sm">
      <!-- Top bar -->
      <div :class="[
        'flex items-center justify-between px-3 sm:px-5 py-2.5 sm:py-3 bg-surface-900/80 border-b border-surface-700/50 flex-shrink-0 gap-2',
        isMobile ? 'pt-16' : ''
      ]">
        <div class="flex items-center gap-2 sm:gap-3 min-w-0">
          <div class="w-8 h-8 sm:w-9 sm:h-9 rounded-xl bg-primary-500/20 flex items-center justify-center flex-shrink-0">
            <span class="material-symbols-rounded text-lg sm:text-xl text-primary-400">account_tree</span>
          </div>
          <div class="min-w-0">
            <h2 class="text-sm sm:text-base font-semibold text-white truncate">Conversation Map</h2>
            <p class="text-[10px] sm:text-xs text-surface-400 truncate">
              {{ conversationName }} &middot; {{ totalMessages }} msgs
              <template v-if="threadCount > 0"> &middot; {{ threadCount }} threads</template>
              <template v-if="groupCount > 0"> &middot; {{ groupCount }} stacks</template>
            </p>
          </div>
        </div>

        <div class="flex items-center gap-1 sm:gap-2 flex-shrink-0">
          <!-- Expand / Collapse All (icons only on mobile) -->
          <div v-if="groupCount > 0" class="flex items-center gap-1 mr-0.5 sm:mr-1">
            <button
              @click="expandAll"
              class="h-7 px-1.5 sm:px-2.5 flex items-center gap-0.5 sm:gap-1 text-xs text-surface-400 hover:text-surface-200 bg-surface-800 hover:bg-surface-700 rounded-full border border-surface-700/50 transition-colors"
              title="Expand all stacks"
            >
              <span class="material-symbols-rounded text-sm">unfold_more</span>
              <span class="hidden sm:inline">Expand</span>
            </button>
            <button
              @click="collapseAll"
              class="h-7 px-1.5 sm:px-2.5 flex items-center gap-0.5 sm:gap-1 text-xs text-surface-400 hover:text-surface-200 bg-surface-800 hover:bg-surface-700 rounded-full border border-surface-700/50 transition-colors"
              title="Collapse all stacks"
            >
              <span class="material-symbols-rounded text-sm">unfold_less</span>
              <span class="hidden sm:inline">Collapse</span>
            </button>
          </div>

          <!-- Zoom controls (compact on mobile) -->
          <div class="flex items-center gap-0.5 sm:gap-1 bg-surface-800 rounded-full px-1.5 sm:px-2 py-1 border border-surface-700/50">
            <button @click="zoomOut" class="w-7 h-7 flex items-center justify-center hover:bg-surface-700 rounded-full transition-colors">
              <span class="material-symbols-rounded text-surface-400 text-lg">remove</span>
            </button>
            <span class="text-[10px] sm:text-xs text-surface-400 font-mono w-8 sm:w-10 text-center">{{ Math.round(zoom * 100) }}%</span>
            <button @click="zoomIn" class="w-7 h-7 flex items-center justify-center hover:bg-surface-700 rounded-full transition-colors">
              <span class="material-symbols-rounded text-surface-400 text-lg">add</span>
            </button>
          </div>
          <button
            @click="resetView"
            class="w-8 h-8 sm:w-9 sm:h-9 flex items-center justify-center hover:bg-surface-800 rounded-full transition-colors"
            title="Reset view"
          >
            <span class="material-symbols-rounded text-surface-400 text-lg sm:text-xl">fit_screen</span>
          </button>
          <button
            @click="emit('close')"
            class="w-8 h-8 sm:w-9 sm:h-9 flex items-center justify-center hover:bg-surface-800 rounded-full transition-colors"
          >
            <span class="material-symbols-rounded text-surface-400 text-lg sm:text-xl">close</span>
          </button>
        </div>
      </div>

      <!-- Loading -->
      <div v-if="loading" class="flex-1 flex items-center justify-center">
        <div class="text-center">
          <span class="material-symbols-rounded text-4xl text-primary-400 animate-spin block mb-3">progress_activity</span>
          <p class="text-surface-400 text-sm">Building conversation map...</p>
        </div>
      </div>

      <!-- Mind Map Canvas -->
      <div
        v-else
        ref="mapContainer"
        class="flex-1 overflow-hidden select-none touch-none"
        :class="isDragging ? 'cursor-grabbing' : 'cursor-grab'"
        @wheel.prevent="onWheel"
        @mousedown="onMouseDown"
        @mousemove="onMouseMove"
        @mouseup="onMouseUp"
        @mouseleave="onMouseUp"
        @touchstart.passive="onTouchStart"
        @touchmove="onTouchMove"
        @touchend="onTouchEnd"
        @touchcancel="onTouchEnd"
      >
        <div
          :style="{
            transform: `translate(${panOffset.x}px, ${panOffset.y}px) scale(${zoom})`,
            transformOrigin: '0 0',
            width: canvasSize.width + 'px',
            height: canvasSize.height + 'px',
            position: 'relative'
          }"
        >
          <!-- SVG layer for connections -->
          <svg
            :width="canvasSize.width"
            :height="canvasSize.height"
            class="absolute inset-0 pointer-events-none"
            style="overflow: visible"
          >
            <defs>
              <!-- Glow filter -->
              <filter id="glow">
                <feGaussianBlur stdDeviation="3" result="blur" />
                <feMerge>
                  <feMergeNode in="blur" />
                  <feMergeNode in="SourceGraphic" />
                </feMerge>
              </filter>
            </defs>

            <!-- Spine connections (vertical) -->
            <line
              v-for="(conn, i) in connections.filter(c => c.type === 'spine')"
              :key="'spine-' + i"
              :x1="conn.x1" :y1="conn.y1"
              :x2="conn.x2" :y2="conn.y2"
              stroke="rgba(99, 102, 241, 0.25)"
              stroke-width="2"
              stroke-dasharray="6 4"
            />

            <!-- Branch connections (curves) -->
            <path
              v-for="(conn, i) in connections.filter(c => c.type === 'branch')"
              :key="'branch-' + i"
              :d="branchPath(conn)"
              fill="none"
              stroke="rgba(168, 85, 247, 0.35)"
              stroke-width="2"
              :class="{ 'mind-map-branch-glow': hoveredNode === conn.parentId }"
            />

            <!-- Animated dots on branches -->
            <circle
              v-for="(conn, i) in connections.filter(c => c.type === 'branch')"
              :key="'dot-' + i"
              r="3"
              fill="#a855f7"
              opacity="0.6"
            >
              <animateMotion
                :dur="(2 + i * 0.3) + 's'"
                repeatCount="indefinite"
                :path="branchPath(conn)"
              />
            </circle>
          </svg>

          <!-- Message Nodes -->
          <template v-for="node in positionedNodes" :key="node.id">

            <!-- ============ COLLAPSED STACK ============ -->
            <div
              v-if="node.type === 'stack'"
              class="mind-map-node absolute transition-all duration-300"
              :style="{
                left: node.x + 'px',
                top: node.y + 'px',
                width: node.w + 'px',
              }"
              @mouseenter="hoveredNode = node.id"
              @mouseleave="hoveredNode = null"
              @click="handleNodeClick(node)"
            >
              <!-- Shadow cards behind (stacked look) -->
              <div class="relative" :style="{ height: node.h + 'px' }">
                <!-- Card shadows (up to 3 behind) -->
                <div
                  v-for="s in Math.min(node.stackCount - 1, 3)"
                  :key="'shadow-' + s"
                  class="absolute rounded-2xl border border-surface-700/30 bg-surface-800/50"
                  :style="{
                    left: (s * 3) + 'px',
                    top: (s * STACK_CARD_OFFSET) + 'px',
                    right: -(s * 3) + 'px',
                    height: NODE_H + 'px',
                    zIndex: 10 - s
                  }"
                ></div>

                <!-- Top visible card -->
                <div
                  class="absolute inset-x-0 top-0 rounded-2xl border transition-all duration-200 cursor-pointer overflow-hidden bg-surface-800/90 border-surface-600/60 hover:border-indigo-400/50 hover:shadow-lg hover:shadow-indigo-500/15"
                  :class="hoveredNode === node.id ? 'ring-1 ring-indigo-400/40' : ''"
                  :style="{ height: NODE_H + 'px', zIndex: 20 }"
                >
                  <div class="flex items-start gap-2 sm:gap-2.5 p-2.5 sm:p-3">
                    <!-- Stacked avatars -->
                    <div class="flex-shrink-0 flex items-center -space-x-2">
                      <div
                        v-for="sid in node.stackSenders.slice(0, isMobile ? 2 : 4)"
                        :key="sid"
                        class="w-6 h-6 sm:w-7 sm:h-7 rounded-full flex items-center justify-center text-white text-[8px] sm:text-[9px] font-bold border-2 border-surface-800"
                        :style="{ backgroundColor: getSenderColor(sid) }"
                      >
                        {{ getInitials(getColleague({ sender_id: sid }).display_name || getColleague({ sender_id: sid }).email) }}
                      </div>
                      <div
                        v-if="node.stackSenders.length > (isMobile ? 2 : 4)"
                        class="w-6 h-6 sm:w-7 sm:h-7 rounded-full flex items-center justify-center text-surface-300 text-[8px] sm:text-[9px] font-bold border-2 border-surface-800 bg-surface-700"
                      >
                        +{{ node.stackSenders.length - (isMobile ? 2 : 4) }}
                      </div>
                    </div>

                    <!-- Stack info -->
                    <div class="flex-1 min-w-0">
                      <div class="flex items-center gap-1.5">
                        <span class="text-[11px] sm:text-xs font-semibold text-surface-200">
                          {{ node.stackCount }} messages
                        </span>
                        <span class="text-[10px] text-surface-500 flex-shrink-0">
                          {{ formatTime(node.msg.created_at) }}
                        </span>
                      </div>
                      <p class="text-[10px] sm:text-xs text-surface-500 mt-0.5 leading-relaxed truncate">
                        {{ truncate(node.msg.content, isMobile ? 30 : 50) }}
                      </p>
                    </div>

                    <!-- Expand icon -->
                    <div class="flex-shrink-0 flex items-center gap-0.5 sm:gap-1 px-1.5 sm:px-2 py-1 rounded-full bg-indigo-500/15 text-indigo-400 hover:bg-indigo-500/25 transition-colors">
                      <span class="material-symbols-rounded text-sm">unfold_more</span>
                      <span class="text-[10px] font-semibold">{{ node.stackCount }}</span>
                    </div>
                  </div>
                </div>
              </div>
            </div>

            <!-- ============ REGULAR / EXPANDED MESSAGE NODES ============ -->
            <div
              v-else
              class="mind-map-node absolute transition-all duration-200"
              :style="{
                left: node.x + 'px',
                top: node.y + 'px',
                width: node.w + 'px',
              }"
              @mouseenter="hoveredNode = node.type === 'thread' ? node.parentId : node.id"
              @mouseleave="hoveredNode = null"
              @click="handleNodeClick(node)"
            >
              <div
                :class="[
                  'rounded-2xl border transition-all duration-200 cursor-pointer overflow-hidden',
                  node.type === 'main'
                    ? 'bg-surface-800/90 border-surface-700/60 hover:border-primary-500/50 hover:shadow-lg hover:shadow-primary-500/10'
                    : 'bg-surface-850/80 border-surface-700/40 hover:border-purple-500/50 hover:shadow-lg hover:shadow-purple-500/10',
                  hoveredNode === (node.type === 'thread' ? node.parentId : node.id) ? 'ring-1 ring-primary-500/30' : '',
                  selectedNode === node.id ? 'ring-2 ring-primary-500' : '',
                  node.isExpanded ? 'border-l-2 border-l-indigo-500/60' : ''
                ]"
              >
                <div class="flex items-start gap-2 sm:gap-2.5 p-2.5 sm:p-3">
                  <!-- Avatar -->
                  <div
                    class="flex-shrink-0 rounded-full flex items-center justify-center text-white text-[9px] sm:text-[10px] font-bold"
                    :style="{
                      backgroundColor: node.color,
                      width: node.type === 'main' ? (isMobile ? '26px' : '32px') : (isMobile ? '22px' : '26px'),
                      height: node.type === 'main' ? (isMobile ? '26px' : '32px') : (isMobile ? '22px' : '26px')
                    }"
                  >
                    {{ getInitials(getColleague(node.msg).display_name || getColleague(node.msg).email) }}
                  </div>

                  <!-- Content -->
                  <div class="flex-1 min-w-0">
                    <div class="flex items-center gap-1 sm:gap-1.5">
                      <span class="text-[11px] sm:text-xs font-semibold text-surface-200 truncate">
                        {{ getColleague(node.msg).display_name || getColleague(node.msg).email?.split('@')[0] }}
                      </span>
                      <span class="text-[9px] sm:text-[10px] text-surface-500 flex-shrink-0">{{ formatTime(node.msg.created_at) }}</span>
                    </div>
                    <p class="text-[10px] sm:text-xs text-surface-400 mt-0.5 leading-relaxed truncate">
                      {{ truncate(node.msg.content, node.type === 'main' ? (isMobile ? 35 : 60) : (isMobile ? 25 : 45)) }}
                    </p>
                  </div>

                  <!-- Thread indicator -->
                  <div v-if="node.hasThread" class="flex-shrink-0 flex items-center gap-0.5 px-1.5 py-0.5 rounded-full bg-purple-500/20 text-purple-400">
                    <span class="material-symbols-rounded text-xs">forum</span>
                    <span class="text-[10px] font-semibold">{{ node.replyCount }}</span>
                  </div>

                  <!-- Collapse button for first card in expanded group -->
                  <button
                    v-if="node.isExpanded && node.isFirstInGroup && node.groupId"
                    @click.stop="toggleGroup(node.groupId)"
                    class="flex-shrink-0 flex items-center gap-0.5 px-1.5 py-0.5 rounded-full bg-indigo-500/15 text-indigo-400 hover:bg-indigo-500/25 transition-colors"
                    title="Collapse stack"
                  >
                    <span class="material-symbols-rounded text-xs">unfold_less</span>
                    <span class="text-[10px] font-semibold">{{ node.groupCount }}</span>
                  </button>
                </div>

                <!-- Thread branch indicator line -->
                <div
                  v-if="node.hasThread"
                  class="h-0.5 bg-gradient-to-r from-transparent via-purple-500/30 to-purple-500/60"
                ></div>

                <!-- Expanded group indicator (left accent is handled via class) -->
                <div
                  v-if="node.isExpanded && node.isLastInGroup"
                  class="h-0.5 bg-gradient-to-r from-indigo-500/40 via-indigo-500/20 to-transparent"
                ></div>
              </div>
            </div>
          </template>
        </div>
      </div>

      <!-- Legend -->
      <div class="flex items-center justify-center flex-wrap gap-3 sm:gap-6 px-3 sm:px-5 py-2 sm:py-2.5 bg-surface-900/80 border-t border-surface-700/50 flex-shrink-0">
        <div class="flex items-center gap-1.5 text-[10px] sm:text-xs text-surface-500">
          <div class="w-2.5 h-2.5 sm:w-3 sm:h-3 rounded-sm bg-surface-700 border border-surface-600"></div>
          <span>Message</span>
        </div>
        <div class="flex items-center gap-1.5 text-[10px] sm:text-xs text-surface-500">
          <div class="w-2.5 h-2.5 sm:w-3 sm:h-3 rounded-sm bg-purple-500/30 border border-purple-500/50"></div>
          <span>Thread reply</span>
        </div>
        <div class="flex items-center gap-1.5 text-[10px] sm:text-xs text-surface-500">
          <span class="material-symbols-rounded text-xs sm:text-sm text-purple-400">forum</span>
          <span>Has thread</span>
        </div>
        <div class="flex items-center gap-1.5 text-[10px] sm:text-xs text-surface-500">
          <div class="w-2.5 h-2.5 sm:w-3 sm:h-3 rounded-sm bg-indigo-500/20 border border-indigo-500/40"></div>
          <span>Stacked group</span>
        </div>
        <div class="flex items-center gap-1.5 text-[10px] sm:text-xs text-surface-500">
          <span class="material-symbols-rounded text-xs sm:text-sm text-surface-500">{{ isMobile ? 'touch_app' : 'mouse' }}</span>
          <span>{{ isMobile ? 'Drag to pan, pinch to zoom' : 'Drag to pan, scroll to move, Ctrl+scroll to zoom' }}</span>
        </div>
      </div>
    </div>
  </Teleport>
</template>

<style scoped>
.mind-map-branch-glow {
  stroke: rgba(168, 85, 247, 0.6);
  filter: url(#glow);
}

/* Subtle entrance animation for nodes */
.mind-map-node {
  animation: nodeAppear 0.3s ease-out both;
}

@keyframes nodeAppear {
  from {
    opacity: 0;
    transform: scale(0.9) translateY(8px);
  }
  to {
    opacity: 1;
    transform: scale(1) translateY(0);
  }
}

/* Stagger animations */
.mind-map-node:nth-child(1) { animation-delay: 0.05s; }
.mind-map-node:nth-child(2) { animation-delay: 0.1s; }
.mind-map-node:nth-child(3) { animation-delay: 0.15s; }
.mind-map-node:nth-child(4) { animation-delay: 0.2s; }
.mind-map-node:nth-child(5) { animation-delay: 0.25s; }
.mind-map-node:nth-child(6) { animation-delay: 0.3s; }
.mind-map-node:nth-child(7) { animation-delay: 0.35s; }
.mind-map-node:nth-child(8) { animation-delay: 0.4s; }
.mind-map-node:nth-child(9) { animation-delay: 0.45s; }
.mind-map-node:nth-child(10) { animation-delay: 0.5s; }
.mind-map-node:nth-child(11) { animation-delay: 0.55s; }
.mind-map-node:nth-child(12) { animation-delay: 0.6s; }

.bg-surface-850\/80 {
  background-color: rgba(30, 30, 35, 0.8);
}

.bg-surface-950\/95 {
  background-color: rgba(10, 10, 15, 0.95);
}
</style>
