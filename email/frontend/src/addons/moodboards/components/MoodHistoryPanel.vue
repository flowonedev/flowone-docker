<template>
  <div class="flex flex-col h-full overflow-hidden text-surface-700 dark:text-surface-300">
    <!-- Header -->
    <div class="flex items-center justify-between px-3 py-2 border-b border-surface-200 dark:border-surface-700 flex-shrink-0">
      <div class="flex items-center gap-2">
        <span class="material-symbols-rounded text-base">history</span>
        <span class="text-sm font-semibold">History</span>
      </div>
      <button
        v-if="!store.isPublicView"
        class="px-2 py-1 text-xs rounded-full bg-primary-50 dark:bg-primary-900/30 text-primary-600 dark:text-primary-400 hover:bg-primary-100 dark:hover:bg-primary-900/50 transition-colors flex items-center gap-1"
        @click="saveSnapshot"
        :disabled="snapshotSaving"
      >
        <span class="material-symbols-rounded text-sm">save</span>
        Save
      </button>
    </div>

    <!-- Undo stack entries -->
    <div class="flex-1 overflow-y-auto" ref="listEl">
      <!-- Current state marker -->
      <div class="px-3 py-1.5 flex items-center gap-2 bg-primary-50 dark:bg-primary-900/20 border-b border-primary-200 dark:border-primary-800/30">
        <span class="material-symbols-rounded text-sm text-primary-500">radio_button_checked</span>
        <span class="text-xs font-medium text-primary-600 dark:text-primary-400">Current State</span>
        <span class="ml-auto text-[10px] text-surface-400">{{ redoStack.length ? `${redoStack.length} undone` : '' }}</span>
      </div>

      <!-- Redo entries (above current position) -->
      <div
        v-for="(action, idx) in reversedRedo"
        :key="'redo-' + idx"
        class="px-3 py-1.5 flex items-center gap-2 border-b border-surface-100 dark:border-surface-700/50 opacity-50 hover:opacity-80 cursor-pointer hover:bg-surface-50 dark:hover:bg-surface-800 transition-all"
        @click="redoToIndex(idx)"
      >
        <span class="material-symbols-rounded text-sm text-surface-400">{{ action.icon || 'history' }}</span>
        <span class="text-xs truncate flex-1">{{ action.label || action.type }}</span>
        <span class="text-[10px] text-surface-400 flex-shrink-0">{{ formatTime(action.timestamp) }}</span>
      </div>

      <!-- Undo entries (below current position) -->
      <div
        v-for="(action, idx) in reversedUndo"
        :key="'undo-' + idx"
        class="px-3 py-1.5 flex items-center gap-2 border-b border-surface-100 dark:border-surface-700/50 cursor-pointer hover:bg-surface-50 dark:hover:bg-surface-800 transition-all"
        @click="undoToIndex(idx)"
      >
        <span class="material-symbols-rounded text-sm text-surface-500">{{ action.icon || 'history' }}</span>
        <span class="text-xs truncate flex-1">{{ action.label || action.type }}</span>
        <span class="text-[10px] text-surface-400 flex-shrink-0">{{ formatTime(action.timestamp) }}</span>
      </div>

      <!-- Empty state -->
      <div v-if="!undoStack.length && !redoStack.length" class="px-4 py-8 text-center text-xs text-surface-400">
        <span class="material-symbols-rounded text-2xl block mb-2">history_toggle_off</span>
        No history yet. Start making changes to see them here.
      </div>

      <!-- Snapshots section (authenticated users only) -->
      <div v-if="snapshots.length && !store.isPublicView" class="border-t border-surface-200 dark:border-surface-700 mt-2">
        <div class="px-3 py-2 text-[11px] font-semibold uppercase tracking-wide text-surface-400 bg-surface-50 dark:bg-surface-800/50">
          Saved Snapshots
        </div>
        <div
          v-for="snap in snapshots"
          :key="snap.id"
          class="px-3 py-2 flex items-center gap-2 border-b border-surface-100 dark:border-surface-700/50 hover:bg-surface-50 dark:hover:bg-surface-800 transition-colors"
        >
          <span class="material-symbols-rounded text-sm text-amber-500">photo_camera</span>
          <div class="flex-1 min-w-0">
            <div class="text-xs truncate">{{ snap.label || snap.trigger_type }}</div>
            <div class="text-[10px] text-surface-400">{{ snap.item_count }} items</div>
          </div>
          <div class="text-[10px] text-surface-400 flex-shrink-0">{{ formatDate(snap.created_at) }}</div>
          <button
            class="px-1.5 py-0.5 text-[10px] rounded-full bg-amber-50 dark:bg-amber-900/20 text-amber-600 dark:text-amber-400 hover:bg-amber-100 dark:hover:bg-amber-900/30 transition-colors"
            @click="restoreSnap(snap.id)"
          >
            Restore
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, watch } from 'vue'
import { useMoodBoardsStore } from '@/addons/moodboards/stores/moodBoards'

const store = useMoodBoardsStore()
const listEl = ref(null)
const snapshotSaving = ref(false)

const undoStack = computed(() => store.undoStack)
const redoStack = computed(() => store.redoStack)
const snapshots = computed(() => store.snapshots)

const reversedUndo = computed(() => [...undoStack.value].reverse())
const reversedRedo = computed(() => [...redoStack.value].reverse())

function formatTime(ts) {
  if (!ts) return ''
  const diff = Math.floor((Date.now() - ts) / 1000)
  if (diff < 60) return 'just now'
  if (diff < 3600) return `${Math.floor(diff / 60)}m ago`
  if (diff < 86400) return `${Math.floor(diff / 3600)}h ago`
  return new Date(ts).toLocaleDateString()
}

function formatDate(dateStr) {
  if (!dateStr) return ''
  const d = new Date(dateStr)
  const now = new Date()
  const diff = Math.floor((now - d) / 1000)
  if (diff < 3600) return `${Math.floor(diff / 60)}m ago`
  if (diff < 86400) return `${Math.floor(diff / 3600)}h ago`
  return d.toLocaleDateString()
}

async function undoToIndex(idx) {
  const count = idx + 1
  for (let i = 0; i < count; i++) {
    try {
      await store.undo()
    } catch (e) {
      console.error('Undo stopped at step', i, e)
      break
    }
  }
}

async function redoToIndex(idx) {
  const count = reversedRedo.value.length - idx
  for (let i = 0; i < count; i++) {
    try {
      await store.redo()
    } catch (e) {
      console.error('Redo stopped at step', i, e)
      break
    }
  }
}

async function saveSnapshot() {
  snapshotSaving.value = true
  try {
    await store.createManualSnapshot('Manual save')
  } finally {
    snapshotSaving.value = false
  }
}

async function restoreSnap(snapshotId) {
  if (!confirm('Restore this snapshot?\n\nThis will replace all current items on the board. A backup snapshot of the current state will be created automatically before restoring.')) return
  await store.restoreSnapshot(snapshotId)
}

onMounted(() => {
  if (store.currentBoard?.id) {
    store.fetchSnapshots()
  }
})

watch(() => store.currentBoard?.id, (newId) => {
  if (newId) store.fetchSnapshots()
})
</script>
