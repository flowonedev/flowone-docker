<script setup>
import { computed, ref } from 'vue'
import { NODE_STYLES } from '@/stores/mindmap'

const props = defineProps({
  node: {
    type: Object,
    required: true
  },
  isSelected: {
    type: Boolean,
    default: false
  },
  isHovered: {
    type: Boolean,
    default: false
  },
  isExpanded: {
    type: Boolean,
    default: false
  },
  hasChildren: {
    type: Boolean,
    default: false
  },
  zoom: {
    type: Number,
    default: 1
  }
})

const emit = defineEmits(['click', 'dblclick', 'expand', 'hover', 'action', 'drag-start', 'drag', 'drag-end'])

const style = computed(() => NODE_STYLES[props.node.type] || NODE_STYLES.email)

// Drag state
const isDragging = ref(false)
const dragStart = ref({ x: 0, y: 0 })

const nodeStyle = computed(() => ({
  left: `${props.node.x || 0}px`,
  top: `${props.node.y || 0}px`,
  transform: `translate(-50%, -50%)`,
  cursor: isDragging.value ? 'grabbing' : 'grab',
}))

// Format date for display
function formatDate(timestamp) {
  if (!timestamp) return ''
  const date = new Date(typeof timestamp === 'number' ? timestamp * 1000 : timestamp)
  const now = new Date()
  const diffMs = now - date
  const diffHours = Math.floor(diffMs / 3600000)
  if (diffHours < 1) return `${Math.floor(diffMs / 60000)}m ago`
  if (diffHours < 24) return `${diffHours}h ago`
  if (diffHours < 48) return 'Yesterday'
  return date.toLocaleDateString(undefined, { month: 'short', day: 'numeric' })
}

// Truncate label for display
function truncateLabel(label, maxLength = 28) {
  if (!label || label.trim() === '') {
    const fallbacks = {
      'email': 'Email',
      'conversation': 'Thread',
      'board': 'Board',
      'task': 'Task',
      'milestone': 'Milestone',
      'list': 'List',
      'calendar': 'Event',
      'drive': 'Files',
      'client': 'Client',
    }
    return fallbacks[props.node.type] || 'Item'
  }
  if (label.length <= maxLength) return label
  return label.substring(0, maxLength) + '...'
}

// Get status indicator
const statusIndicator = computed(() => {
  if (props.node.type === 'email') {
    if (props.node.meta?.unread) return { color: 'bg-blue-500', pulse: true }
    if (props.node.meta?.flagged) return { color: 'bg-amber-500', pulse: false }
  }
  if (props.node.type === 'client') {
    const status = props.node.meta?.status
    if (status === 'attention') return { color: 'bg-red-500', pulse: true }
    if (status === 'waiting') return { color: 'bg-amber-500', pulse: false }
    if (status === 'active') return { color: 'bg-green-500', pulse: false }
  }
  return null
})

// Sublabel content
const sublabel = computed(() => {
  if (props.node.sublabel) return props.node.sublabel

  if (props.node.type === 'conversation') {
    const count = props.node.meta?.messageCount || 0
    const unread = props.node.meta?.unreadCount || 0
    if (unread > 0) return `${count} emails (${unread} new)`
    return `${count} emails`
  }

  if (props.node.type === 'email') {
    return props.node.meta?.from || formatDate(props.node.meta?.timestamp)
  }

  if (props.node.type === 'client') {
    return `${props.node.meta?.emailCount || 0} emails`
  }

  if (props.node.type === 'calendar') {
    const date = props.node.meta?.eventDate
    if (date) {
      return new Date(date).toLocaleDateString('en-US', { month: 'short', day: 'numeric' })
    }
    return ''
  }

  if (props.node.type === 'task') {
    const dueDate = props.node.meta?.dueDate
    if (dueDate) {
      return 'Due: ' + new Date(dueDate).toLocaleDateString('en-US', { month: 'short', day: 'numeric' })
    }
    return props.node.meta?.listName || ''
  }

  if (props.node.type === 'board') {
    const meta = props.node.meta
    if (meta?.totalCards !== undefined) {
      return `${meta.completedCards || 0}/${meta.totalCards} (${meta.progress || 0}%)`
    }
    return meta?.cardCount ? `${meta.cardCount} cards` : ''
  }

  if (props.node.type === 'drive') {
    const meta = props.node.meta
    if (meta?.totalFiles !== undefined) {
      return `${meta.totalFiles} files, ${meta.totalSizeFormatted || ''}`
    }
    return ''
  }

  if (props.node.type === 'milestone' || props.node.type === 'list') {
    const meta = props.node.meta
    if (meta?.expectedAmount) {
      const currency = meta.currency || 'HUF'
      if (currency === 'HUF') {
        const formatted = new Intl.NumberFormat('en-US', {
          minimumFractionDigits: 0,
          maximumFractionDigits: 0
        }).format(meta.expectedAmount)
        return `${formatted} Ft`
      }
      return new Intl.NumberFormat('en-US', {
        style: 'currency',
        currency: currency,
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
      }).format(meta.expectedAmount)
    }
    if (meta?.totalCards !== undefined) {
      return `${meta.completedCards || 0}/${meta.totalCards} done`
    }
    return ''
  }

  if (props.node.type === 'calendar-group') {
    return `${props.node.meta?.eventCount || 0} events`
  }

  return ''
})

// Type icon mapping
const typeIcon = computed(() => {
  const icons = {
    'calendar': 'event',
    'calendar-group': 'calendar_month',
    'board': 'dashboard',
    'task': props.node.meta?.isComplete ? 'task_alt' : 'radio_button_unchecked',
    'drive': 'folder',
    'client': 'person',
    'email': props.node.meta?.isFromClient ? 'call_received' : 'call_made',
    'conversation': 'forum',
    'topic': 'label',
    'milestone': 'flag',
    'list': 'view_list',
  }
  return props.node.icon || icons[props.node.type] || 'circle'
})

// Accent color for card border/icon based on type
const accentColor = computed(() => {
  if (props.node.type === 'email') {
    return props.node.meta?.isFromClient ? '#3b82f6' : '#10b981' // blue for incoming, green for outgoing
  }
  const colors = {
    client: '#6366f1',     // indigo
    conversation: '#8b5cf6', // purple
    calendar: '#22c55e',   // green
    'calendar-group': '#22c55e',
    board: '#a855f7',      // purple
    task: '#f59e0b',       // amber
    drive: '#06b6d4',      // cyan
    topic: '#ef4444',      // red
    milestone: '#f43f5e',  // rose
    list: '#6366f1',       // indigo
  }
  return colors[props.node.type] || '#6366f1'
})

// Card width based on node type
const cardWidth = computed(() => {
  if (props.node.type === 'client') return 240
  if (props.node.type === 'conversation') return 240
  return 220
})

function handleClick(e) {
  if (isDragging.value) return
  e.stopPropagation()
  emit('click', props.node)
}

function handleDoubleClick(e) {
  e.stopPropagation()
  emit('dblclick', props.node)
}

function handleExpand(e) {
  e.stopPropagation()
  emit('expand', props.node)
}

function handleMouseEnter() {
  if (!isDragging.value) {
    emit('hover', props.node)
  }
}

function handleMouseLeave() {
  if (!isDragging.value) {
    emit('hover', null)
  }
}

// Drag handlers
function handleMouseDown(e) {
  if (e.button !== 0) return

  isDragging.value = true
  dragStart.value = {
    x: e.clientX,
    y: e.clientY
  }

  emit('drag-start', props.node)

  window.addEventListener('mousemove', handleGlobalMouseMove)
  window.addEventListener('mouseup', handleGlobalMouseUp)

  e.stopPropagation()
  e.preventDefault()
}

function handleGlobalMouseMove(e) {
  if (!isDragging.value) return

  const dx = (e.clientX - dragStart.value.x) / props.zoom
  const dy = (e.clientY - dragStart.value.y) / props.zoom

  emit('drag', {
    node: props.node,
    dx,
    dy
  })

  dragStart.value = {
    x: e.clientX,
    y: e.clientY
  }
}

function handleGlobalMouseUp() {
  if (isDragging.value) {
    isDragging.value = false
    emit('drag-end', props.node)
  }

  window.removeEventListener('mousemove', handleGlobalMouseMove)
  window.removeEventListener('mouseup', handleGlobalMouseUp)
}
</script>

<template>
  <div
    class="mind-map-node absolute select-none"
    :style="nodeStyle"
    :class="[
      isSelected ? 'z-20' : isHovered ? 'z-10' : 'z-0',
      isDragging ? 'opacity-80' : ''
    ]"
    @click="handleClick"
    @dblclick="handleDoubleClick"
    @mouseenter="handleMouseEnter"
    @mouseleave="handleMouseLeave"
    @mousedown="handleMouseDown"
  >
    <!-- Card Node -->
    <div
      :class="[
        'rounded-2xl border overflow-hidden',
        isDragging ? '' : 'transition-all duration-200',
        isSelected
          ? 'ring-2 ring-primary-500 shadow-xl shadow-primary-500/20 bg-surface-800 border-primary-500'
          : isHovered
            ? 'ring-1 ring-primary-500/30 shadow-lg bg-surface-800/95 border-surface-600/80'
            : 'bg-surface-800/90 border-surface-700/60 hover:border-surface-500/60 hover:shadow-lg',
      ]"
      :style="{ width: cardWidth + 'px' }"
    >
      <div class="flex items-start gap-2.5 p-3">
        <!-- Icon Circle -->
        <div
          class="flex-shrink-0 w-8 h-8 rounded-full flex items-center justify-center"
          :style="{ backgroundColor: accentColor + '25' }"
        >
          <span
            class="material-symbols-rounded text-base"
            :style="{ color: accentColor }"
          >
            {{ typeIcon }}
          </span>
        </div>

        <!-- Content -->
        <div class="flex-1 min-w-0">
          <div class="flex items-center gap-1.5">
            <span class="text-xs font-semibold text-surface-200 truncate flex-1" :title="node.label">
              {{ truncateLabel(node.label) }}
            </span>

            <!-- Status indicator -->
            <div
              v-if="statusIndicator"
              class="flex-shrink-0 w-2 h-2 rounded-full"
              :class="[statusIndicator.color, statusIndicator.pulse ? 'animate-pulse' : '']"
            ></div>
          </div>
          <p v-if="sublabel" class="text-[10px] text-surface-500 mt-0.5 leading-relaxed truncate">
            {{ sublabel }}
          </p>
        </div>

        <!-- Expand/collapse indicator for nodes with children -->
        <button
          v-if="hasChildren"
          @click.stop="handleExpand"
          class="flex-shrink-0 w-6 h-6 rounded-full flex items-center justify-center transition-colors"
          :class="isExpanded
            ? 'bg-primary-500/20 text-primary-400'
            : 'bg-surface-700/50 text-surface-400 hover:bg-surface-600/50 hover:text-surface-300'"
        >
          <span class="material-symbols-rounded text-sm transition-transform duration-200" :class="isExpanded ? 'rotate-180' : ''">
            expand_more
          </span>
        </button>
      </div>

      <!-- Accent line at bottom -->
      <div
        class="h-0.5 transition-opacity duration-200"
        :style="{ background: `linear-gradient(to right, transparent, ${accentColor}40, ${accentColor}60)` }"
        :class="isSelected || isHovered ? 'opacity-100' : 'opacity-40'"
      ></div>
    </div>

    <!-- Hover Preview Card (for emails and conversations) -->
    <Transition name="fade">
      <div
        v-if="isHovered && !isDragging && (node.type === 'email' || node.type === 'conversation')"
        class="absolute top-full left-1/2 -translate-x-1/2 mt-4 w-72 bg-surface-800 rounded-xl shadow-2xl border border-surface-600 p-4 z-50 pointer-events-none"
      >
        <!-- Email Preview -->
        <template v-if="node.type === 'email'">
          <div class="flex items-start gap-3 mb-2">
            <div
              class="w-10 h-10 rounded-full flex items-center justify-center flex-shrink-0"
              :style="{ backgroundColor: accentColor + '25' }"
            >
              <span class="material-symbols-rounded text-lg" :style="{ color: accentColor }">mail</span>
            </div>
            <div class="flex-1 min-w-0">
              <p class="text-sm font-semibold text-surface-100 line-clamp-2">
                {{ node.label }}
              </p>
              <p class="text-xs text-surface-400 truncate mt-0.5">
                {{ node.meta?.from || 'Unknown sender' }}
              </p>
            </div>
          </div>
          <p v-if="node.meta?.preview" class="text-xs text-surface-400 line-clamp-3 mt-2">
            {{ node.meta.preview }}
          </p>
          <div class="flex items-center gap-3 mt-3 pt-2 border-t border-surface-700 text-[11px] text-surface-500">
            <span>{{ formatDate(node.meta?.timestamp) }}</span>
            <span v-if="node.meta?.hasAttachment" class="flex items-center gap-1">
              <span class="material-symbols-rounded text-xs">attach_file</span>
              Attachment
            </span>
            <span v-if="node.meta?.unread" class="text-blue-400 font-medium">Unread</span>
          </div>
        </template>

        <!-- Conversation Preview -->
        <template v-else-if="node.type === 'conversation'">
          <div class="flex items-start gap-3 mb-2">
            <div class="w-10 h-10 rounded-full bg-purple-500/20 flex items-center justify-center flex-shrink-0">
              <span class="material-symbols-rounded text-lg text-purple-400">forum</span>
            </div>
            <div class="flex-1 min-w-0">
              <p class="text-sm font-semibold text-surface-100 line-clamp-2">
                {{ node.label }}
              </p>
              <p class="text-xs text-surface-400 mt-0.5">
                {{ node.meta?.messageCount || 0 }} messages in thread
              </p>
            </div>
          </div>
          <div v-if="node.meta?.participants?.length" class="flex flex-wrap gap-1.5 mt-3">
            <span
              v-for="p in node.meta.participants.slice(0, 4)"
              :key="p"
              class="px-2 py-1 bg-surface-700 rounded-full text-[10px] text-surface-300"
            >
              {{ p.split('@')[0] }}
            </span>
            <span
              v-if="node.meta.participants.length > 4"
              class="px-2 py-1 bg-surface-700 rounded-full text-[10px] text-surface-400"
            >
              +{{ node.meta.participants.length - 4 }} more
            </span>
          </div>
        </template>
      </div>
    </Transition>
  </div>
</template>

<style scoped>
.fade-enter-active,
.fade-leave-active {
  transition: opacity 0.2s ease, transform 0.2s ease;
}

.fade-enter-from,
.fade-leave-to {
  opacity: 0;
  transform: translate(-50%, -8px) scale(0.95);
}

.line-clamp-2 {
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
}

.line-clamp-3 {
  display: -webkit-box;
  -webkit-line-clamp: 3;
  -webkit-box-orient: vertical;
  overflow: hidden;
}

.mind-map-node {
  animation: nodeAppear 0.3s ease-out both;
}

@keyframes nodeAppear {
  from {
    opacity: 0;
    transform: translate(-50%, -50%) scale(0.9);
  }
  to {
    opacity: 1;
    transform: translate(-50%, -50%) scale(1);
  }
}
</style>
