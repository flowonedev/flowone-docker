<template>
  <div class="flex flex-col h-full">
    <!-- Header -->
    <div class="flex items-center justify-between px-3 py-2 border-b border-surface-100 dark:border-surface-700">
      <span class="text-[11px] font-semibold text-surface-600 dark:text-surface-300 uppercase tracking-wider">Activity</span>
      <button
        @click="refresh"
        class="p-1 rounded-md hover:bg-surface-100 dark:hover:bg-surface-700 text-surface-400 hover:text-surface-600 transition-colors"
        :class="{ 'animate-spin': store.activitiesLoading }"
        title="Refresh"
      >
        <span class="material-symbols-rounded text-sm">refresh</span>
      </button>
    </div>

    <!-- Activity list -->
    <div class="flex-1 overflow-y-auto" ref="listRef">
      <!-- Loading state -->
      <div v-if="store.activitiesLoading && !store.activities.length" class="flex items-center justify-center py-8 text-surface-400">
        <span class="material-symbols-rounded text-lg animate-spin mr-2">progress_activity</span>
        <span class="text-xs">Loading...</span>
      </div>

      <!-- Empty state -->
      <div v-else-if="!store.activities.length" class="flex flex-col items-center justify-center py-8 px-4 text-surface-400">
        <span class="material-symbols-rounded text-2xl mb-1">history</span>
        <span class="text-xs text-center">No activity yet. Start adding items to see the log.</span>
      </div>

      <!-- Activity entries -->
      <div v-else class="py-1">
        <div
          v-for="(group, gIdx) in groupedActivities"
          :key="gIdx"
          class="mb-2"
        >
          <!-- Date separator -->
          <div class="px-3 py-1">
            <span class="text-[9px] font-semibold text-surface-400 uppercase tracking-wider">{{ group.label }}</span>
          </div>

          <!-- Entries -->
          <button
            v-for="entry in group.entries"
            :key="entry.id"
            @click="onEntryClick(entry)"
            class="w-full flex items-start gap-2 px-3 py-1.5 text-left transition-colors group"
            :class="entry.item_id ? 'hover:bg-surface-50 dark:hover:bg-surface-700/50 cursor-pointer' : 'cursor-default'"
          >
            <!-- Action icon (colored) -->
            <div
              class="flex-shrink-0 w-5 h-5 rounded-md flex items-center justify-center mt-0.5"
              :style="{ backgroundColor: getActionColor(entry.action) + '20', color: getActionColor(entry.action) }"
            >
              <span class="material-symbols-rounded" style="font-size: 13px;">{{ getActionIcon(entry.action) }}</span>
            </div>

            <!-- Content -->
            <div class="flex-1 min-w-0">
              <div class="text-[11px] text-surface-700 dark:text-surface-300 leading-snug">
                <span class="font-medium">{{ entry.user_name || entry.user_email?.split('@')[0] }}</span>
                <span class="text-surface-500 dark:text-surface-400">&nbsp;{{ getActionText(entry) }}</span>
                <span v-if="entry.item_label" class="font-medium" :style="{ color: getActionColor(entry.action) }"> "{{ truncate(entry.item_label, 40) }}"</span>
                <template v-if="entry.target_label">
                  <span class="text-surface-500 dark:text-surface-400"> → </span>
                  <span class="font-medium text-surface-600 dark:text-surface-300">"{{ truncate(entry.target_label, 40) }}"</span>
                </template>
              </div>
              <div class="text-[9px] text-surface-400 mt-0.5">
                {{ formatTime(entry.created_at) }}
              </div>
            </div>

            <!-- Jump indicator -->
            <span
              v-if="entry.item_id && itemExists(entry.item_id)"
              class="flex-shrink-0 mt-1 material-symbols-rounded text-[12px] text-surface-300 opacity-0 group-hover:opacity-100 transition-opacity"
            >north_east</span>
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, watch } from 'vue'
import { useMoodBoardsStore } from '@/addons/moodboards/stores/moodBoards'

const emit = defineEmits(['fly-to-item'])

const store = useMoodBoardsStore()
const listRef = ref(null)

// Fetch activity when the board changes
watch(() => store.currentBoard?.id, (boardId) => {
  if (boardId) {
    store.fetchActivities(boardId)
  }
}, { immediate: true })

function refresh() {
  if (store.currentBoard?.id) {
    store.fetchActivities(store.currentBoard.id)
  }
}

// ── Group activities by date ──
const groupedActivities = computed(() => {
  const groups = []
  let currentLabel = null
  let currentGroup = null
  const now = new Date()
  const today = new Date(now.getFullYear(), now.getMonth(), now.getDate())
  const yesterday = new Date(today.getTime() - 86400000)

  for (const entry of store.activities) {
    const d = new Date(entry.created_at)
    const entryDate = new Date(d.getFullYear(), d.getMonth(), d.getDate())
    
    let label
    if (entryDate.getTime() === today.getTime()) label = 'Today'
    else if (entryDate.getTime() === yesterday.getTime()) label = 'Yesterday'
    else label = d.toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: d.getFullYear() !== now.getFullYear() ? 'numeric' : undefined })

    if (label !== currentLabel) {
      currentLabel = label
      currentGroup = { label, entries: [] }
      groups.push(currentGroup)
    }
    currentGroup.entries.push(entry)
  }

  return groups
})

// ── Action display text ──
function getActionText(entry) {
  const map = {
    'item_added': `added ${getTypeLabel(entry.item_type)}`,
    'item_deleted': `removed ${getTypeLabel(entry.item_type)}`,
    'item_edited': `edited ${getTypeLabel(entry.item_type)}`,
    'item_locked': `locked ${getTypeLabel(entry.item_type)}`,
    'item_unlocked': `unlocked ${getTypeLabel(entry.item_type)}`,
    'connection_added': `connected`,
    'connection_deleted': 'removed a connection',
  }
  return map[entry.action] || entry.action
}

function getTypeLabel(type) {
  const labels = {
    text: 'text', note: 'a note', image: 'an image', shape: 'a shape',
    pen_shape: 'a drawing', drawing: 'a drawing', frame: 'an artboard',
    link: 'a link', todo_list: 'a todo list', file: 'a file',
    color_swatch: 'a color', board_link: 'a board link', image_set: 'an image set',
    calendar_event: 'an event', table: 'a table', column: 'a column',
    folder: 'a folder', video: 'a video', youtube: 'a video', line: 'a line',
    artboard: 'an artboard',
  }
  return labels[type] || 'an item'
}

function getActionIcon(action) {
  const map = {
    'item_added': 'add_circle',
    'item_deleted': 'delete',
    'item_edited': 'edit_note',
    'item_locked': 'lock',
    'item_unlocked': 'lock_open',
    'connection_added': 'cable',
    'connection_deleted': 'link_off',
  }
  return map[action] || 'history'
}

function getActionColor(action) {
  const map = {
    'item_added': '#10B981',      // green
    'item_deleted': '#EF4444',    // red
    'item_edited': '#3B82F6',     // blue
    'item_locked': '#F59E0B',     // amber
    'item_unlocked': '#F59E0B',   // amber
    'connection_added': '#8B5CF6', // purple
    'connection_deleted': '#EF4444', // red
  }
  return map[action] || '#64748B'  // slate fallback
}

// ── User color (deterministic from email) ──
const COLORS = ['#3B82F6', '#10B981', '#F59E0B', '#EF4444', '#8B5CF6', '#EC4899', '#14B8A6', '#F97316', '#6366F1', '#84CC16']
function getUserColor(email) {
  if (!email) return COLORS[0]
  let hash = 0
  for (let i = 0; i < email.length; i++) hash = ((hash << 5) - hash) + email.charCodeAt(i)
  return COLORS[Math.abs(hash) % COLORS.length]
}

function getInitial(name) {
  if (!name) return '?'
  return name.charAt(0).toUpperCase()
}

// ── Time formatting ──
function formatTime(isoStr) {
  if (!isoStr) return ''
  const d = new Date(isoStr)
  const now = new Date()
  const diff = (now - d) / 1000

  if (diff < 60) return 'just now'
  if (diff < 3600) return `${Math.floor(diff / 60)}m ago`
  if (diff < 86400) return `${Math.floor(diff / 3600)}h ago`
  return d.toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit' })
}

function truncate(str, max) {
  if (!str) return ''
  return str.length > max ? str.substring(0, max) + '...' : str
}

// ── Click to fly to item ──
function itemExists(itemId) {
  return store.currentBoard?.items?.some(i => i.id === itemId)
}

function onEntryClick(entry) {
  if (!entry.item_id) return
  const item = store.currentBoard?.items?.find(i => i.id === entry.item_id)
  if (item) {
    emit('fly-to-item', item)
  }
}
</script>

