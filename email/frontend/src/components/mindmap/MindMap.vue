<script setup>
import { ref, computed, watch, onMounted, onUnmounted } from 'vue'
import { useRouter } from 'vue-router'
import { useMindMapStore, MINDMAP_MODES } from '@/stores/mindmap'
import { useMailboxStore } from '@/stores/mailbox'

const router = useRouter()
const mailbox = useMailboxStore()
const mindmap = useMindMapStore()

// Mode dropdown
const availableModes = Object.values(MINDMAP_MODES)
const showModeMenu = ref(false)

// Container
const containerRef = ref(null)
const containerSize = ref({ width: 800, height: 600 })

// Pan / zoom
const isPanning = ref(false)
const panStart = ref({ x: 0, y: 0 })
const localPan = ref({ x: 0, y: 0 })
const localZoom = ref(1)

// Selection
const hoveredNode = ref(null)
const selectedNode = ref(null)

// Stacking - all groups collapsed by default
const expandedGroups = ref(new Set())
const GROUP_SIZE = 8 // bigger groups = more compact

// Layout constants
const NODE_W = 280
const NODE_H = 66
const CHILD_W = 250
const CHILD_H = 56
const GAP_Y = 18
const CHILD_GAP_Y = 10
const BRANCH_X = 360          // email branches go right at mainX + 360
const STACK_OFFSET = 4
const LINKED_COL_X = 720      // linked items column: past branches (360 + 250 + 110 gap)
const LINKED_ITEM_INDENT = 24  // indent items under their type header
const LINKED_GAP_Y = 14

// Colors
const accentColors = {
  client:           '#6366f1',
  conversation:     '#8b5cf6',
  email:            '#3b82f6',
  'email-out':      '#10b981',
  calendar:         '#22c55e',
  'calendar-group': '#22c55e',
  board:            '#a855f7',
  task:             '#f59e0b',
  drive:            '#06b6d4',
  topic:            '#ef4444',
  milestone:        '#f43f5e',
  list:             '#6366f1',
}

function getColor(node) {
  if (node.type === 'email') return node.meta?.isFromClient ? accentColors.email : accentColors['email-out']
  return accentColors[node.type] || '#6366f1'
}

function getIcon(node) {
  const icons = {
    calendar: 'event', 'calendar-group': 'calendar_month',
    board: 'dashboard', task: node.meta?.isComplete ? 'task_alt' : 'radio_button_unchecked',
    drive: 'folder', client: 'person',
    email: node.meta?.isFromClient ? 'call_received' : 'call_made',
    conversation: 'forum', topic: 'label', milestone: 'flag', list: 'view_list',
  }
  return node.icon || icons[node.type] || 'circle'
}

function truncate(text, max = 30) {
  if (!text) return 'Untitled'
  return text.length > max ? text.substring(0, max) + '...' : text
}

function getSublabel(node) {
  if (node.sublabel) return node.sublabel
  if (node.type === 'conversation') {
    const c = node.meta?.messageCount || node.children?.length || 0
    return `${c} email${c !== 1 ? 's' : ''}`
  }
  if (node.type === 'email') return node.meta?.from || ''
  if (node.type === 'client') return `${node.meta?.emailCount || 0} emails`
  if (node.type === 'board') {
    const m = node.meta
    return m?.totalCards !== undefined ? `${m.completedCards || 0}/${m.totalCards} cards` : ''
  }
  if (node.type === 'task') return node.meta?.listName || ''
  if (node.type === 'drive') {
    const m = node.meta
    return m?.totalFiles !== undefined ? `${m.totalFiles} files` : ''
  }
  if (node.type === 'calendar') {
    const d = node.meta?.eventDate
    return d ? new Date(d).toLocaleDateString('en-US', { month: 'short', day: 'numeric' }) : ''
  }
  if (node.type === 'calendar-group') return `${node.meta?.eventCount || 0} events`
  if (node.type === 'milestone' || node.type === 'list') {
    const m = node.meta
    if (m?.expectedAmount) {
      const cur = m.currency || 'HUF'
      if (cur === 'HUF') return new Intl.NumberFormat('en-US', { maximumFractionDigits: 0 }).format(m.expectedAmount) + ' Ft'
      return new Intl.NumberFormat('en-US', { style: 'currency', currency: cur }).format(m.expectedAmount)
    }
    if (m?.totalCards !== undefined) return `${m.completedCards || 0}/${m.totalCards} done`
    return ''
  }
  return ''
}

// ─── TREE PROCESSING ───────────────────────────────────────────
const treeData = ref({ root: null, conversations: [], linked: [] })

watch(
  [() => mindmap.flatNodes, () => mindmap.expandedNodes, () => mindmap.isOpen],
  () => { if (mindmap.isOpen && mindmap.flatNodes?.length) processTree() },
  { deep: true }
)

function processTree() {
  const nodes = mindmap.flatNodes
  if (!nodes?.length) return

  const root = nodes.find(n => !n.parentId && !n.isLinked)
  if (!root) return

  // Build children map
  const childrenMap = new Map()
  nodes.forEach(n => {
    if (n.parentId && !n.isLinked) {
      const ch = childrenMap.get(n.parentId) || []
      ch.push(n)
      childrenMap.set(n.parentId, ch)
    }
  })

  // Direct children of root = conversations
  const rootChildren = childrenMap.get(root.id) || []

  // Enrich each conversation with its emails
  const conversations = rootChildren.map(conv => {
    const emails = childrenMap.get(conv.id) || []
    const isExpanded = mindmap.expandedNodes?.has(conv.id) || false
    return {
      ...conv,
      emails: isExpanded ? emails : [],
      emailCount: emails.length,
      hasEmails: emails.length > 0,
      isExpanded,
    }
  })

  // Linked items = boards, calendar, drive
  const linked = nodes.filter(n => n.isLinked)

  // Enrich linked items with their children (milestones, tasks, events)
  const enrichedLinked = linked.map(l => {
    const ch = childrenMap.get(l.id) || []
    const isExp = mindmap.expandedNodes?.has(l.id) || false
    return {
      ...l,
      subItems: isExp ? ch : [],
      subItemCount: ch.length,
      hasSubItems: ch.length > 0,
      isExpanded: isExp,
    }
  })

  treeData.value = { root, conversations, linked: enrichedLinked }
}

// ─── STACKING ──────────────────────────────────────────────────
// ALL conversations get stacked. Only individually visible when expanded from a stack.
const groupedConversations = computed(() => {
  const convs = treeData.value.conversations
  if (!convs?.length) return []

  const items = []
  let buffer = []
  let gIdx = 0

  function flush() {
    if (!buffer.length) return
    if (buffer.length <= 2) {
      buffer.forEach(c => items.push({ type: 'single', node: c }))
    } else {
      for (let i = 0; i < buffer.length; i += GROUP_SIZE) {
        const chunk = buffer.slice(i, i + GROUP_SIZE)
        if (chunk.length <= 2) {
          chunk.forEach(c => items.push({ type: 'single', node: c }))
        } else {
          const gId = `g-${gIdx++}`
          items.push({ type: 'group', groupId: gId, nodes: chunk, topNode: chunk[0], count: chunk.length })
        }
      }
    }
    buffer = []
  }

  for (const conv of convs) {
    // If conversation is explicitly expanded (user clicked expand) show it individually
    if (conv.isExpanded && conv.hasEmails) {
      flush()
      items.push({ type: 'expanded', node: conv })
    } else {
      buffer.push(conv)
    }
  }
  flush()

  return items
})

function toggleGroup(gId) {
  const s = new Set(expandedGroups.value)
  s.has(gId) ? s.delete(gId) : s.add(gId)
  expandedGroups.value = s
}

function isGroupExpanded(gId) { return expandedGroups.value.has(gId) }

// ─── POSITIONED NODES ──────────────────────────────────────────
const positionedNodes = computed(() => {
  const out = []
  const root = treeData.value.root
  if (!root) return out

  const mainX = 60
  let mainY = 50

  // Count totals for the root badge
  const totalConvs = treeData.value.conversations?.length || 0
  const totalLinked = treeData.value.linked?.length || 0

  // ── ROOT ──
  out.push({
    id: root.id, x: mainX, y: mainY, w: NODE_W, h: NODE_H,
    nodeType: 'root', data: root, color: getColor(root),
    hasChildren: totalConvs > 0, childCount: totalConvs, isStack: false,
    linkedCount: totalLinked
  })

  mainY += NODE_H + GAP_Y + 10

  // ── CONVERSATIONS (left spine) ──
  for (const item of groupedConversations.value) {
    if (item.type === 'single') {
      const c = item.node
      out.push({
        id: c.id, parentId: root.id, x: mainX, y: mainY, w: NODE_W, h: NODE_H,
        nodeType: 'child', data: c, color: getColor(c),
        hasChildren: c.hasEmails, childCount: c.emailCount,
        isStack: false, isExpanded: c.isExpanded
      })
      mainY += NODE_H + GAP_Y

    } else if (item.type === 'expanded') {
      // Conversation with emails branching right
      const c = item.node
      out.push({
        id: c.id, parentId: root.id, x: mainX, y: mainY, w: NODE_W, h: NODE_H,
        nodeType: 'child', data: c, color: getColor(c),
        hasChildren: true, childCount: c.emailCount,
        isStack: false, isExpanded: true
      })

      if (c.emails?.length) {
        let eY = mainY - ((c.emails.length - 1) * (CHILD_H + CHILD_GAP_Y)) / 2
        for (let i = 0; i < c.emails.length; i++) {
          const em = c.emails[i]
          out.push({
            id: em.id, parentId: c.id,
            x: mainX + BRANCH_X, y: eY, w: CHILD_W, h: CHILD_H,
            nodeType: 'subchild', data: em, color: getColor(em),
            hasChildren: false, childCount: 0, isStack: false, branchIndex: i
          })
          eY += CHILD_H + CHILD_GAP_Y
        }
        const clusterH = c.emails.length * (CHILD_H + CHILD_GAP_Y)
        mainY += Math.max(NODE_H + GAP_Y, clusterH + GAP_Y)
      } else {
        mainY += NODE_H + GAP_Y
      }

    } else if (item.type === 'group') {
      const exp = isGroupExpanded(item.groupId)

      if (exp) {
        // Show all conversations in group individually
        for (let i = 0; i < item.nodes.length; i++) {
          const c = item.nodes[i]
          out.push({
            id: c.id, parentId: root.id, x: mainX, y: mainY, w: NODE_W, h: NODE_H,
            nodeType: 'child', data: c, color: getColor(c),
            hasChildren: c.hasEmails, childCount: c.emailCount,
            isStack: false, isExpanded: c.isExpanded,
            groupId: item.groupId, isInExpandedGroup: true,
            isFirstInGroup: i === 0, isLastInGroup: i === item.nodes.length - 1,
            groupCount: item.nodes.length
          })
          mainY += NODE_H + GAP_Y
        }
      } else {
        // Collapsed stack card
        const stackH = NODE_H + Math.min(item.count - 1, 3) * STACK_OFFSET
        out.push({
          id: `stack-${item.groupId}`, parentId: root.id,
          x: mainX, y: mainY, w: NODE_W, h: stackH,
          nodeType: 'stack', data: item.topNode, color: getColor(item.topNode),
          isStack: true, groupId: item.groupId,
          stackCount: item.count, stackNodes: item.nodes,
          stackTypes: [...new Set(item.nodes.map(n => n.type))]
        })
        mainY += stackH + GAP_Y + 6
      }
    }
  }

  // ── LINKED ITEMS (right column, always clear of branches) ──
  const linked = treeData.value.linked
  if (linked?.length) {
    const linkX = mainX + LINKED_COL_X
    // Start linked column aligned with root
    let linkY = 50

    // Group by type
    const byType = {}
    linked.forEach(l => { const t = l.type; if (!byType[t]) byType[t] = []; byType[t].push(l) })

    const typeLabels = { board: 'Boards', calendar: 'Calendar', 'calendar-group': 'Calendar', drive: 'Drive', task: 'Tasks', milestone: 'Milestones', list: 'Lists' }

    for (const [type, items] of Object.entries(byType)) {
      // Type header
      out.push({
        id: `linked-header-${type}`, parentId: root.id,
        x: linkX, y: linkY, w: CHILD_W, h: 40,
        nodeType: 'linked-header',
        data: { type, label: typeLabels[type] || type, icon: getIcon({ type }) },
        color: accentColors[type] || '#6366f1', isStack: false,
        headerType: type, itemCount: items.length
      })
      linkY += 40 + 8

      // Items
      for (const item of items) {
        const itemX = linkX + LINKED_ITEM_INDENT
        const itemW = CHILD_W - LINKED_ITEM_INDENT

        out.push({
          id: item.id, parentId: `linked-header-${type}`,
          x: itemX, y: linkY, w: itemW, h: CHILD_H,
          nodeType: 'linked', data: item, color: getColor(item),
          isStack: false, linkedType: type,
          hasChildren: item.hasSubItems, childCount: item.subItemCount,
          isExpanded: item.isExpanded
        })

        // Expanded sub-items (tasks, milestones) go below their parent
        if (item.isExpanded && item.subItems?.length) {
          linkY += CHILD_H + 6
          for (const sub of item.subItems) {
            const subX = itemX + LINKED_ITEM_INDENT
            const subW = itemW - LINKED_ITEM_INDENT
            out.push({
              id: sub.id, parentId: item.id,
              x: subX, y: linkY, w: subW, h: CHILD_H - 6,
              nodeType: 'linked-sub', data: sub, color: getColor(sub),
              isStack: false, hasChildren: false, childCount: 0
            })
            linkY += (CHILD_H - 6) + CHILD_GAP_Y
          }
          linkY += 4
        } else {
          linkY += CHILD_H + LINKED_GAP_Y
        }
      }

      linkY += 16 // gap between type groups
    }
  }

  return out
})

// ─── SVG CONNECTIONS ───────────────────────────────────────────
const connections = computed(() => {
  const lines = []
  const all = positionedNodes.value
  const map = new Map(all.map(n => [n.id, n]))
  const rootN = all.find(n => n.nodeType === 'root')
  if (!rootN) return lines

  // Spine: root → each child/stack on the left
  const spine = all.filter(n => n.parentId === rootN.id && (n.nodeType === 'child' || n.nodeType === 'stack'))
  spine.forEach(ch => {
    lines.push({
      type: 'spine',
      x1: rootN.x + rootN.w / 2, y1: rootN.y + rootN.h,
      x2: ch.x + ch.w / 2, y2: ch.y, fromId: rootN.id, toId: ch.id
    })
  })
  // Consecutive spine links
  for (let i = 0; i < spine.length - 1; i++) {
    const a = spine[i], b = spine[i + 1]
    lines.push({
      type: 'spine',
      x1: a.x + a.w / 2, y1: a.y + a.h,
      x2: b.x + b.w / 2, y2: b.y, fromId: a.id, toId: b.id
    })
  }

  // Branches: conversation → emails
  all.filter(n => n.nodeType === 'subchild').forEach(sub => {
    const p = map.get(sub.parentId)
    if (!p) return
    lines.push({
      type: 'branch',
      x1: p.x + p.w, y1: p.y + p.h / 2,
      x2: sub.x, y2: sub.y + sub.h / 2, fromId: p.id, toId: sub.id
    })
  })

  // Linked: root → linked headers (horizontal bezier)
  all.filter(n => n.nodeType === 'linked-header').forEach(h => {
    lines.push({
      type: 'linked',
      x1: rootN.x + rootN.w, y1: rootN.y + rootN.h / 2,
      x2: h.x, y2: h.y + 20, fromId: rootN.id, toId: h.id
    })
  })

  // Linked header → linked items (vertical step)
  all.filter(n => n.nodeType === 'linked').forEach(l => {
    const p = map.get(l.parentId)
    if (!p) return
    lines.push({
      type: 'linked-item',
      x1: p.x + 12, y1: p.y + p.h,
      x2: l.x + 4, y2: l.y, fromId: p.id, toId: l.id
    })
  })

  // Linked item → sub items (vertical step, indented)
  all.filter(n => n.nodeType === 'linked-sub').forEach(s => {
    const p = map.get(s.parentId)
    if (!p) return
    lines.push({
      type: 'linked-item',
      x1: p.x + 12, y1: p.y + p.h,
      x2: s.x + 4, y2: s.y, fromId: p.id, toId: s.id
    })
  })

  return lines
})

function bezierPath(c) {
  if (c.type === 'spine' || c.type === 'linked-item') {
    const cy = (c.y1 + c.y2) / 2
    return `M ${c.x1} ${c.y1} C ${c.x1} ${cy}, ${c.x2} ${cy}, ${c.x2} ${c.y2}`
  }
  const cx = (c.x1 + c.x2) / 2
  return `M ${c.x1} ${c.y1} C ${cx} ${c.y1}, ${cx} ${c.y2}, ${c.x2} ${c.y2}`
}

// ─── CANVAS ────────────────────────────────────────────────────
const canvasSize = computed(() => {
  let mx = 800, my = 600
  positionedNodes.value.forEach(n => {
    mx = Math.max(mx, n.x + n.w + 120)
    my = Math.max(my, n.y + n.h + 100)
  })
  return { width: mx, height: my }
})

const nodeCount = computed(() => positionedNodes.value.filter(n => !n.isStack && n.nodeType !== 'linked-header').length)
const stackCount = computed(() => positionedNodes.value.filter(n => n.isStack).length)
const conversationCount = computed(() => treeData.value.conversations?.length || 0)

// ─── PAN / ZOOM ────────────────────────────────────────────────
function onWheel(e) {
  e.preventDefault()
  if (e.ctrlKey || e.metaKey) {
    const d = e.deltaY > 0 ? -0.08 : 0.08
    localZoom.value = Math.max(0.15, Math.min(2.5, localZoom.value + d))
  } else {
    localPan.value = { x: localPan.value.x - e.deltaX, y: localPan.value.y - e.deltaY }
  }
}
function onMouseDown(e) {
  if (e.target.closest('.mm-node')) return
  isPanning.value = true
  panStart.value = { x: e.clientX - localPan.value.x, y: e.clientY - localPan.value.y }
}
function onMouseMove(e) {
  if (!isPanning.value) return
  localPan.value = { x: e.clientX - panStart.value.x, y: e.clientY - panStart.value.y }
}
function onMouseUp() { isPanning.value = false }

function resetView() { localPan.value = { x: 0, y: 0 }; localZoom.value = 1 }
function zoomIn() { localZoom.value = Math.min(2.5, localZoom.value + 0.15) }
function zoomOut() { localZoom.value = Math.max(0.15, localZoom.value - 0.15) }

function fitToView() {
  if (!positionedNodes.value.length || !containerRef.value) return
  let minX = Infinity, maxX = -Infinity, minY = Infinity, maxY = -Infinity
  positionedNodes.value.forEach(n => {
    minX = Math.min(minX, n.x); maxX = Math.max(maxX, n.x + n.w)
    minY = Math.min(minY, n.y); maxY = Math.max(maxY, n.y + n.h)
  })
  const cw = containerSize.value.width, ch = containerSize.value.height
  const z = Math.min(cw / (maxX - minX + 80), ch / (maxY - minY + 80), 1.2)
  localZoom.value = z
  localPan.value = {
    x: (cw / 2) - ((minX + maxX) / 2) * z,
    y: (ch / 2) - ((minY + maxY) / 2) * z + 20
  }
}

function expandAll() {
  mindmap.expandAll()
  const all = new Set()
  groupedConversations.value.forEach(i => { if (i.type === 'group') all.add(i.groupId) })
  expandedGroups.value = all
}

function collapseAll() {
  mindmap.collapseAll()
  expandedGroups.value = new Set()
}

// ─── INTERACTIONS ──────────────────────────────────────────────
function handleNodeClick(node) {
  selectedNode.value = node.id === selectedNode.value ? null : node.id
}

function handleNodeDblClick(node) {
  const d = node.data
  if (!d) return
  if (d.type === 'email' && d.meta?.folder && d.meta?.uid) {
    handleClose(); mailbox.currentFolder = d.meta.folder
    router.push({ name: 'mailbox', query: { folder: d.meta.folder, uid: d.meta.uid } })
  } else if (d.type === 'calendar' && d.meta?.eventId) {
    handleClose(); router.push({ name: 'calendar', query: { event: d.meta.eventId } })
  } else if ((d.type === 'board' || d.type === 'milestone' || d.type === 'list') && d.meta?.boardId) {
    handleClose(); router.push({ name: 'boards', query: { board: d.meta.boardId } })
  } else if (d.type === 'task' && d.meta?.cardId) {
    handleClose(); router.push({ name: 'boards', query: { card: d.meta.cardId } })
  } else if (d.type === 'drive' && d.meta?.folderId) {
    handleClose(); router.push({ name: 'drive', query: { folder: d.meta.folderId } })
  }
}

function handleExpandToggle(node) {
  if (node.data?.id) mindmap.toggleNodeExpanded(node.data.id)
}

function handleKeyDown(e) {
  if (!mindmap.isOpen) return
  if (e.key === 'Escape') handleClose()
  if (e.key === '+' || e.key === '=') zoomIn()
  if (e.key === '-') zoomOut()
  if (e.key === '0') fitToView()
}

const emit = defineEmits(['close', 'open-email', 'open-item'])
function handleClose() { mindmap.closeMindMap(); emit('close') }
function handleModeChange(m) { mindmap.setMode(m) }

// Resize observer
let resizeObs = null
onMounted(() => {
  window.addEventListener('keydown', handleKeyDown)
  window.addEventListener('mouseup', onMouseUp)
  window.addEventListener('mousemove', onMouseMove)
  if (containerRef.value) {
    resizeObs = new ResizeObserver(entries => {
      for (const e of entries) containerSize.value = { width: e.contentRect.width, height: e.contentRect.height }
    })
    resizeObs.observe(containerRef.value)
  }
})
onUnmounted(() => {
  window.removeEventListener('keydown', handleKeyDown)
  window.removeEventListener('mouseup', onMouseUp)
  window.removeEventListener('mousemove', onMouseMove)
  if (resizeObs) resizeObs.disconnect()
})
</script>

<template>
  <Teleport to="body">
    <Transition name="mindmap-modal">
      <div v-if="mindmap.isOpen" class="fixed inset-0 z-[10000] flex flex-col bg-surface-950/95 backdrop-blur-sm">

        <!-- ═══ TOP BAR ═══ -->
        <div class="flex items-center justify-between px-5 py-3 bg-surface-900/80 border-b border-surface-700/50 flex-shrink-0">
          <div class="flex items-center gap-3">
            <div class="w-9 h-9 rounded-xl bg-primary-500/20 flex items-center justify-center">
              <span class="material-symbols-rounded text-xl text-primary-400">hub</span>
            </div>
            <div>
              <h2 class="text-base font-semibold text-white">Email Mind Map</h2>
              <p class="text-xs text-surface-400">
                {{ treeData.root?.label || 'Visualizing' }}
                <span v-if="conversationCount"> &middot; {{ conversationCount }} conversations</span>
                <span v-if="stackCount"> &middot; {{ stackCount }} stacks</span>
              </p>
            </div>
          </div>

          <div class="flex items-center gap-2">
            <!-- Expand / Collapse -->
            <div class="flex items-center gap-1 mr-1">
              <button @click="expandAll" class="h-7 px-2.5 flex items-center gap-1 text-xs text-surface-400 hover:text-surface-200 bg-surface-800 hover:bg-surface-700 rounded-full border border-surface-700/50 transition-colors" title="Expand all">
                <span class="material-symbols-rounded text-sm">unfold_more</span><span>Expand</span>
              </button>
              <button @click="collapseAll" class="h-7 px-2.5 flex items-center gap-1 text-xs text-surface-400 hover:text-surface-200 bg-surface-800 hover:bg-surface-700 rounded-full border border-surface-700/50 transition-colors" title="Collapse all">
                <span class="material-symbols-rounded text-sm">unfold_less</span><span>Collapse</span>
              </button>
            </div>

            <!-- Zoom -->
            <div class="flex items-center gap-1 bg-surface-800 rounded-full px-2 py-1 border border-surface-700/50">
              <button @click="zoomOut" class="w-7 h-7 flex items-center justify-center hover:bg-surface-700 rounded-full transition-colors">
                <span class="material-symbols-rounded text-surface-400 text-lg">remove</span>
              </button>
              <span class="text-xs text-surface-400 font-mono w-10 text-center">{{ Math.round(localZoom * 100) }}%</span>
              <button @click="zoomIn" class="w-7 h-7 flex items-center justify-center hover:bg-surface-700 rounded-full transition-colors">
                <span class="material-symbols-rounded text-surface-400 text-lg">add</span>
              </button>
            </div>
            <button @click="fitToView" class="w-9 h-9 flex items-center justify-center hover:bg-surface-800 rounded-full transition-colors" title="Fit to view">
              <span class="material-symbols-rounded text-surface-400">fit_screen</span>
            </button>
            <button @click="resetView" class="w-9 h-9 flex items-center justify-center hover:bg-surface-800 rounded-full transition-colors" title="Reset view">
              <span class="material-symbols-rounded text-surface-400">restart_alt</span>
            </button>

            <!-- Mode -->
            <div class="relative">
              <button @click="showModeMenu = !showModeMenu" class="flex items-center gap-2 px-3 py-1.5 rounded-full bg-surface-800 hover:bg-surface-700 border border-surface-700/50 transition-colors">
                <span class="material-symbols-rounded text-base text-primary-400">{{ mindmap.mode?.icon || 'hub' }}</span>
                <span class="text-xs font-medium text-surface-200">{{ mindmap.mode?.label || 'Mode' }}</span>
                <span class="material-symbols-rounded text-sm text-surface-500">expand_more</span>
              </button>
              <Transition name="dropdown">
                <div v-if="showModeMenu" class="absolute right-0 top-full mt-2 w-60 bg-surface-800 rounded-xl shadow-xl border border-surface-700 p-2 z-50">
                  <div class="text-[10px] font-medium text-surface-500 px-3 py-1 uppercase tracking-wider">Visualization Mode</div>
                  <button v-for="m in availableModes" :key="m.id" @click="handleModeChange(m); showModeMenu = false"
                    class="w-full flex items-center gap-3 px-3 py-2 rounded-lg text-left transition-colors"
                    :class="mindmap.mode?.id === m.id ? 'bg-primary-500/15 text-primary-300' : 'hover:bg-surface-700 text-surface-300'">
                    <span class="material-symbols-rounded text-lg" :class="mindmap.mode?.id === m.id ? 'text-primary-400' : 'text-surface-500'">{{ m.icon }}</span>
                    <div class="flex-1">
                      <div class="text-xs font-medium">{{ m.label }}</div>
                      <div class="text-[10px] text-surface-500 capitalize">{{ m.layout }} layout</div>
                    </div>
                    <span v-if="mindmap.mode?.id === m.id" class="material-symbols-rounded text-sm text-primary-400">check</span>
                  </button>
                </div>
              </Transition>
              <div v-if="showModeMenu" class="fixed inset-0 z-40" @click="showModeMenu = false"></div>
            </div>

            <button @click="handleClose" class="w-9 h-9 flex items-center justify-center hover:bg-surface-800 rounded-full transition-colors">
              <span class="material-symbols-rounded text-surface-400 text-xl">close</span>
            </button>
          </div>
        </div>

        <!-- ═══ LOADING ═══ -->
        <div v-if="mindmap.loading" class="flex-1 flex items-center justify-center">
          <div class="text-center">
            <span class="material-symbols-rounded text-4xl text-primary-400 animate-spin block mb-3">progress_activity</span>
            <p class="text-surface-400 text-sm">Building mind map...</p>
          </div>
        </div>

        <!-- ═══ CANVAS ═══ -->
        <div v-else ref="containerRef"
          class="flex-1 overflow-hidden select-none mindmap-canvas-dark"
          :class="isPanning ? 'cursor-grabbing' : 'cursor-grab'"
          @wheel.prevent="onWheel" @mousedown="onMouseDown" @mouseleave="onMouseUp">

          <div :style="{
            transform: `translate(${localPan.x}px, ${localPan.y}px) scale(${localZoom})`,
            transformOrigin: '0 0',
            width: canvasSize.width + 'px', height: canvasSize.height + 'px',
            position: 'relative'
          }">

            <!-- SVG -->
            <svg :width="canvasSize.width" :height="canvasSize.height" class="absolute inset-0 pointer-events-none" style="overflow: visible">
              <defs>
                <filter id="mm-glow"><feGaussianBlur stdDeviation="3" result="blur"/><feMerge><feMergeNode in="blur"/><feMergeNode in="SourceGraphic"/></feMerge></filter>
              </defs>

              <!-- Spine -->
              <path v-for="(c, i) in connections.filter(x => x.type === 'spine')" :key="'sp-'+i"
                :d="bezierPath(c)" fill="none" stroke="rgba(99,102,241,0.2)" stroke-width="2" stroke-dasharray="6 4"/>

              <!-- Branches (email threads) -->
              <template v-for="(c, i) in connections.filter(x => x.type === 'branch')" :key="'br-'+i">
                <path :d="bezierPath(c)" fill="none"
                  :stroke="hoveredNode === c.fromId ? 'rgba(168,85,247,0.6)' : 'rgba(168,85,247,0.3)'"
                  stroke-width="2" :filter="hoveredNode === c.fromId ? 'url(#mm-glow)' : ''"/>
                <circle r="3" fill="#a855f7" opacity="0.5">
                  <animateMotion :dur="(2+i*0.3)+'s'" repeatCount="indefinite" :path="bezierPath(c)"/>
                </circle>
              </template>

              <!-- Linked connections (root → headers) -->
              <template v-for="(c, i) in connections.filter(x => x.type === 'linked')" :key="'lk-'+i">
                <path :d="bezierPath(c)" fill="none" stroke="rgba(6,182,212,0.3)" stroke-width="2" stroke-dasharray="8 4"/>
                <circle r="2.5" fill="#06b6d4" opacity="0.4">
                  <animateMotion :dur="(3+i*0.4)+'s'" repeatCount="indefinite" :path="bezierPath(c)"/>
                </circle>
              </template>

              <!-- Linked item connections (header → items) -->
              <path v-for="(c, i) in connections.filter(x => x.type === 'linked-item')" :key="'li-'+i"
                :d="bezierPath(c)" fill="none" stroke="rgba(6,182,212,0.15)" stroke-width="1.5"/>
            </svg>

            <!-- ═══ NODES ═══ -->
            <template v-for="node in positionedNodes" :key="node.id">

              <!-- ━━━ STACK NODE ━━━ -->
              <div v-if="node.isStack" class="mm-node absolute transition-all duration-300"
                :style="{ left: node.x+'px', top: node.y+'px', width: node.w+'px' }"
                @mouseenter="hoveredNode = node.id" @mouseleave="hoveredNode = null"
                @click="toggleGroup(node.groupId)">
                <div class="relative" :style="{ height: node.h+'px' }">
                  <!-- Shadow layers -->
                  <div v-for="s in Math.min(node.stackCount - 1, 3)" :key="'sh-'+s"
                    class="absolute rounded-2xl border border-surface-700/30 bg-surface-800/40"
                    :style="{ left: (s*3)+'px', top: (s*STACK_OFFSET)+'px', right: -(s*3)+'px', height: NODE_H+'px', zIndex: 10-s }"/>
                  <!-- Top card -->
                  <div class="absolute inset-x-0 top-0 rounded-2xl border transition-all duration-200 cursor-pointer overflow-hidden bg-surface-800/90 border-surface-600/60 hover:border-indigo-400/50 hover:shadow-lg hover:shadow-indigo-500/15"
                    :class="hoveredNode === node.id ? 'ring-1 ring-indigo-400/40' : ''"
                    :style="{ height: NODE_H+'px', zIndex: 20 }">
                    <div class="flex items-center gap-2.5 p-3">
                      <div class="flex-shrink-0 w-8 h-8 rounded-full flex items-center justify-center bg-indigo-500/15">
                        <span class="material-symbols-rounded text-sm text-indigo-400">stacks</span>
                      </div>
                      <div class="flex-1 min-w-0">
                        <span class="text-xs font-semibold text-surface-200">{{ node.stackCount }} conversations</span>
                        <p class="text-[10px] text-surface-500 mt-0.5 truncate">{{ truncate(node.data.label, 35) }} ...</p>
                      </div>
                      <div class="flex-shrink-0 flex items-center gap-1 px-2 py-1 rounded-full bg-indigo-500/15 text-indigo-400 hover:bg-indigo-500/25 transition-colors">
                        <span class="material-symbols-rounded text-sm">unfold_more</span>
                        <span class="text-[10px] font-semibold">{{ node.stackCount }}</span>
                      </div>
                    </div>
                  </div>
                </div>
              </div>

              <!-- ━━━ LINKED HEADER ━━━ -->
              <div v-else-if="node.nodeType === 'linked-header'" class="mm-node absolute transition-all duration-200"
                :style="{ left: node.x+'px', top: node.y+'px', width: node.w+'px' }">
                <div class="rounded-xl px-3 py-2 flex items-center gap-2 border"
                  :style="{ borderColor: node.color + '40', backgroundColor: node.color + '10' }">
                  <span class="material-symbols-rounded text-base" :style="{ color: node.color }">{{ node.data.icon || getIcon(node.data) }}</span>
                  <span class="text-xs font-semibold" :style="{ color: node.color }">{{ node.data.label }}</span>
                  <span class="text-[10px] text-surface-500 ml-auto">{{ node.itemCount }}</span>
                </div>
              </div>

              <!-- ━━━ REGULAR NODE ━━━ -->
              <div v-else class="mm-node absolute transition-all duration-200"
                :style="{ left: node.x+'px', top: node.y+'px', width: node.w+'px' }"
                @mouseenter="hoveredNode = node.nodeType === 'subchild' || node.nodeType === 'linked-sub' ? node.parentId : node.id"
                @mouseleave="hoveredNode = null"
                @click="handleNodeClick(node)" @dblclick="handleNodeDblClick(node)">
                <div :class="[
                  'rounded-2xl border transition-all duration-200 cursor-pointer overflow-hidden',
                  node.nodeType === 'root'
                    ? 'bg-surface-800 border-surface-600/80 hover:border-primary-500/50 hover:shadow-lg hover:shadow-primary-500/15'
                    : node.nodeType === 'subchild' || node.nodeType === 'linked-sub'
                      ? 'bg-surface-850/80 border-surface-700/40 hover:border-purple-500/50 hover:shadow-lg hover:shadow-purple-500/10'
                      : node.nodeType === 'linked'
                        ? 'bg-surface-850/80 border-surface-700/40 hover:border-cyan-500/50 hover:shadow-lg hover:shadow-cyan-500/10'
                        : 'bg-surface-800/90 border-surface-700/60 hover:border-primary-500/50 hover:shadow-lg hover:shadow-primary-500/10',
                  hoveredNode === (node.nodeType === 'subchild' || node.nodeType === 'linked-sub' ? node.parentId : node.id) ? 'ring-1 ring-primary-500/30' : '',
                  selectedNode === node.id ? 'ring-2 ring-primary-500' : '',
                  node.isInExpandedGroup ? 'border-l-2 border-l-indigo-500/60' : ''
                ]">
                  <div class="flex items-center gap-2.5 p-3">
                    <!-- Icon -->
                    <div class="flex-shrink-0 rounded-full flex items-center justify-center"
                      :style="{ backgroundColor: node.color+'25', width: node.nodeType==='root'?'34px':'28px', height: node.nodeType==='root'?'34px':'28px' }">
                      <span class="material-symbols-rounded" :class="node.nodeType==='root'?'text-base':'text-sm'" :style="{ color: node.color }">
                        {{ getIcon(node.data) }}
                      </span>
                    </div>

                    <!-- Content -->
                    <div class="flex-1 min-w-0">
                      <div class="flex items-center gap-1.5">
                        <span class="text-xs font-semibold text-surface-200 truncate flex-1" :title="node.data.label">
                          {{ truncate(node.data.label, node.nodeType==='root' ? 35 : 28) }}
                        </span>
                        <!-- Email direction badge for sub-children -->
                        <span v-if="node.nodeType === 'subchild' && node.data.type === 'email'"
                          class="flex-shrink-0 text-[9px] px-1.5 py-0.5 rounded-full font-medium"
                          :class="node.data.meta?.isFromClient ? 'bg-blue-500/15 text-blue-400' : 'bg-emerald-500/15 text-emerald-400'">
                          {{ node.data.meta?.isFromClient ? 'IN' : 'OUT' }}
                        </span>
                      </div>
                      <p v-if="getSublabel(node.data)" class="text-[10px] text-surface-500 mt-0.5 truncate">
                        {{ getSublabel(node.data) }}
                      </p>
                    </div>

                    <!-- Expand button (conversations & linked items with children) -->
                    <button v-if="node.hasChildren && node.nodeType !== 'root'"
                      @click.stop="handleExpandToggle(node)"
                      class="flex-shrink-0 flex items-center gap-0.5 px-1.5 py-0.5 rounded-full transition-colors"
                      :class="node.isExpanded ? 'bg-purple-500/20 text-purple-400' : 'bg-surface-700/50 text-surface-400 hover:bg-surface-600/50'">
                      <span class="material-symbols-rounded text-xs">{{ node.isExpanded ? 'unfold_less' : 'unfold_more' }}</span>
                      <span class="text-[10px] font-semibold">{{ node.childCount }}</span>
                    </button>

                    <!-- Linked count badge on root -->
                    <div v-if="node.nodeType === 'root' && node.linkedCount"
                      class="flex-shrink-0 flex items-center gap-1 px-2 py-1 rounded-full bg-cyan-500/15 text-cyan-400 text-[10px] font-semibold">
                      <span class="material-symbols-rounded text-xs">link</span>
                      {{ node.linkedCount }}
                    </div>

                    <!-- Collapse group button -->
                    <button v-if="node.isInExpandedGroup && node.isFirstInGroup && node.groupId"
                      @click.stop="toggleGroup(node.groupId)"
                      class="flex-shrink-0 flex items-center gap-0.5 px-1.5 py-0.5 rounded-full bg-indigo-500/15 text-indigo-400 hover:bg-indigo-500/25 transition-colors"
                      title="Collapse stack">
                      <span class="material-symbols-rounded text-xs">unfold_less</span>
                      <span class="text-[10px] font-semibold">{{ node.groupCount }}</span>
                    </button>
                  </div>

                  <!-- Accent bar when expanded -->
                  <div v-if="node.hasChildren && node.isExpanded" class="h-0.5"
                    :style="{ background: `linear-gradient(to right, transparent, ${node.color}30, ${node.color}60)` }"/>
                  <div v-if="node.isInExpandedGroup && node.isLastInGroup"
                    class="h-0.5 bg-gradient-to-r from-indigo-500/40 via-indigo-500/20 to-transparent"/>
                </div>
              </div>
            </template>
          </div>
        </div>

        <!-- ═══ DETAIL PANEL ═══ -->
        <Transition name="slide-up">
          <div v-if="selectedNode && positionedNodes.find(n => n.id === selectedNode)"
            class="border-t border-surface-700/50 bg-surface-900/80 p-4 flex-shrink-0">
            <div class="max-w-4xl mx-auto flex items-center justify-between">
              <div class="flex items-center gap-3">
                <div class="w-9 h-9 rounded-full flex items-center justify-center"
                  :style="{ backgroundColor: positionedNodes.find(n => n.id === selectedNode)?.color + '25' }">
                  <span class="material-symbols-rounded text-lg"
                    :style="{ color: positionedNodes.find(n => n.id === selectedNode)?.color }">
                    {{ getIcon(positionedNodes.find(n => n.id === selectedNode)?.data) }}
                  </span>
                </div>
                <div>
                  <h4 class="font-semibold text-surface-100 text-sm">
                    {{ positionedNodes.find(n => n.id === selectedNode)?.data.label }}
                  </h4>
                  <p class="text-xs text-surface-400">
                    {{ getSublabel(positionedNodes.find(n => n.id === selectedNode)?.data) || positionedNodes.find(n => n.id === selectedNode)?.data.type }}
                  </p>
                </div>
              </div>
              <div class="flex items-center gap-2">
                <button @click="handleNodeDblClick(positionedNodes.find(n => n.id === selectedNode))"
                  class="px-3 py-1.5 bg-primary-500 hover:bg-primary-600 text-white rounded-full text-xs font-medium flex items-center gap-1.5 transition-colors">
                  <span class="material-symbols-rounded text-base">open_in_new</span> Open
                </button>
                <button @click="selectedNode = null" class="w-8 h-8 flex items-center justify-center hover:bg-surface-700 rounded-full transition-colors">
                  <span class="material-symbols-rounded text-surface-400 text-sm">close</span>
                </button>
              </div>
            </div>
          </div>
        </Transition>

        <!-- ═══ LEGEND ═══ -->
        <div class="flex items-center justify-center gap-5 px-5 py-2 bg-surface-900/80 border-t border-surface-700/50 flex-shrink-0">
          <div class="flex items-center gap-1.5 text-xs text-surface-500"><div class="w-2 h-2 rounded-full" style="background:#6366f1"></div><span>Client</span></div>
          <div class="flex items-center gap-1.5 text-xs text-surface-500"><div class="w-2 h-2 rounded-full" style="background:#8b5cf6"></div><span>Thread</span></div>
          <div class="flex items-center gap-1.5 text-xs text-surface-500"><div class="w-2 h-2 rounded-full" style="background:#3b82f6"></div><span>Incoming</span></div>
          <div class="flex items-center gap-1.5 text-xs text-surface-500"><div class="w-2 h-2 rounded-full" style="background:#10b981"></div><span>Outgoing</span></div>
          <div class="flex items-center gap-1.5 text-xs text-surface-500"><div class="w-2 h-2 rounded-full" style="background:#a855f7"></div><span>Board</span></div>
          <div class="flex items-center gap-1.5 text-xs text-surface-500"><div class="w-2 h-2 rounded-full" style="background:#22c55e"></div><span>Calendar</span></div>
          <div class="flex items-center gap-1.5 text-xs text-surface-500"><div class="w-2 h-2 rounded-full" style="background:#06b6d4"></div><span>Drive</span></div>
          <div class="flex items-center gap-1.5 text-xs text-surface-500"><div class="w-2 h-2 rounded-full bg-indigo-500/40 border border-indigo-500/60"></div><span>Stack</span></div>
          <div class="flex items-center gap-1.5 text-xs text-surface-500">
            <span class="material-symbols-rounded text-xs">mouse</span>
            <span>Drag to pan &middot; Scroll to move &middot; Ctrl+scroll zoom</span>
          </div>
        </div>
      </div>
    </Transition>
  </Teleport>
</template>

<style scoped>
.mindmap-canvas-dark {
  background-color: rgba(10, 10, 15, 0.95);
  background-image: radial-gradient(circle, rgba(50, 50, 60, 0.4) 1px, transparent 1px);
  background-size: 24px 24px;
}
.mm-node { animation: mmAppear 0.3s ease-out both; }
@keyframes mmAppear {
  from { opacity: 0; transform: scale(0.9) translateY(8px); }
  to { opacity: 1; transform: scale(1) translateY(0); }
}
.mm-node:nth-child(1) { animation-delay: 0.04s; }
.mm-node:nth-child(2) { animation-delay: 0.08s; }
.mm-node:nth-child(3) { animation-delay: 0.12s; }
.mm-node:nth-child(4) { animation-delay: 0.16s; }
.mm-node:nth-child(5) { animation-delay: 0.2s; }
.mm-node:nth-child(6) { animation-delay: 0.24s; }
.mm-node:nth-child(7) { animation-delay: 0.28s; }
.mm-node:nth-child(8) { animation-delay: 0.32s; }
.mm-node:nth-child(9) { animation-delay: 0.36s; }
.mm-node:nth-child(10) { animation-delay: 0.4s; }
.bg-surface-850\/80 { background-color: rgba(30, 30, 35, 0.8); }
.bg-surface-950\/95 { background-color: rgba(10, 10, 15, 0.95); }

.dropdown-enter-active, .dropdown-leave-active { transition: opacity 0.15s ease, transform 0.15s ease; }
.dropdown-enter-from, .dropdown-leave-to { opacity: 0; transform: translateY(-8px); }
.mindmap-modal-enter-active, .mindmap-modal-leave-active { transition: opacity 0.3s ease; }
.mindmap-modal-enter-from, .mindmap-modal-leave-to { opacity: 0; }
.slide-up-enter-active, .slide-up-leave-active { transition: transform 0.2s ease, opacity 0.2s ease; }
.slide-up-enter-from, .slide-up-leave-to { transform: translateY(100%); opacity: 0; }
</style>
