<template>
  <transition name="align-bar">
    <div
      v-if="store.selectedItemIds.size >= 2"
      class="fixed z-40 flex items-center gap-0.5 px-2 py-1.5 bg-white dark:bg-surface-800 rounded-2xl shadow-lg border border-surface-200 dark:border-surface-700"
      :style="barStyle"
    >
      <!-- Group / Ungroup -->
      <button
        v-if="!hasGroups"
        @click="store.groupSelectedItems()"
        title="Group (Ctrl+G)"
        class="p-1.5 rounded-lg hover:bg-surface-100 dark:hover:bg-surface-700 text-surface-500 dark:text-surface-400 hover:text-primary-500 transition-colors relative group"
      >
        <span class="material-symbols-rounded text-lg">group_work</span>
        <span class="absolute -top-7 left-1/2 -translate-x-1/2 text-[10px] bg-surface-800 dark:bg-surface-200 text-white dark:text-surface-800 px-1.5 py-0.5 rounded-full whitespace-nowrap opacity-0 group-hover:opacity-100 transition-opacity pointer-events-none">
          Group
        </span>
      </button>
      <button
        v-if="hasGroups"
        @click="store.ungroupSelectedItems()"
        title="Ungroup (Ctrl+Shift+G)"
        class="p-1.5 rounded-lg hover:bg-surface-100 dark:hover:bg-surface-700 text-primary-500 dark:text-primary-400 hover:text-primary-600 transition-colors relative group"
      >
        <span class="material-symbols-rounded text-lg">workspaces</span>
        <span class="absolute -top-7 left-1/2 -translate-x-1/2 text-[10px] bg-surface-800 dark:bg-surface-200 text-white dark:text-surface-800 px-1.5 py-0.5 rounded-full whitespace-nowrap opacity-0 group-hover:opacity-100 transition-opacity pointer-events-none">
          Ungroup
        </span>
      </button>

      <button
        @click="store.createRepeatGrid()"
        title="Repeat Grid"
        class="p-1.5 rounded-lg hover:bg-surface-100 dark:hover:bg-surface-700 text-surface-500 dark:text-surface-400 hover:text-emerald-500 transition-colors relative group"
      >
        <span class="material-symbols-rounded text-lg">grid_view</span>
        <span class="absolute -top-7 left-1/2 -translate-x-1/2 text-[10px] bg-surface-800 dark:bg-surface-200 text-white dark:text-surface-800 px-1.5 py-0.5 rounded-full whitespace-nowrap opacity-0 group-hover:opacity-100 transition-opacity pointer-events-none">
          Repeat Grid
        </span>
      </button>

      <!-- Divider -->
      <div class="w-px h-5 bg-surface-200 dark:bg-surface-700 mx-0.5"></div>

      <!-- Align group -->
      <button
        v-for="a in alignActions"
        :key="a.dir"
        @click="store.alignItems(a.dir)"
        :title="a.label"
        class="p-1.5 rounded-lg hover:bg-surface-100 dark:hover:bg-surface-700 text-surface-500 dark:text-surface-400 hover:text-primary-500 transition-colors relative group"
      >
        <span class="material-symbols-rounded text-lg">{{ a.icon }}</span>
        <span class="absolute -top-7 left-1/2 -translate-x-1/2 text-[10px] bg-surface-800 dark:bg-surface-200 text-white dark:text-surface-800 px-1.5 py-0.5 rounded-full whitespace-nowrap opacity-0 group-hover:opacity-100 transition-opacity pointer-events-none">
          {{ a.label }}
        </span>
      </button>

      <!-- Divider -->
      <div class="w-px h-5 bg-surface-200 dark:bg-surface-700 mx-0.5"></div>

      <!-- Distribute group (needs 3+) -->
      <button
        v-for="d in distributeActions"
        :key="d.dir"
        @click="store.alignItems(d.dir)"
        :title="d.label"
        :disabled="store.selectedItemIds.size < 3"
        class="p-1.5 rounded-lg hover:bg-surface-100 dark:hover:bg-surface-700 text-surface-500 dark:text-surface-400 hover:text-primary-500 transition-colors relative group disabled:opacity-30 disabled:cursor-not-allowed"
      >
        <span class="material-symbols-rounded text-lg">{{ d.icon }}</span>
        <span class="absolute -top-7 left-1/2 -translate-x-1/2 text-[10px] bg-surface-800 dark:bg-surface-200 text-white dark:text-surface-800 px-1.5 py-0.5 rounded-full whitespace-nowrap opacity-0 group-hover:opacity-100 transition-opacity pointer-events-none">
          {{ d.label }}
        </span>
      </button>

      <!-- Divider -->
      <div class="w-px h-5 bg-surface-200 dark:bg-surface-700 mx-0.5"></div>

      <!-- Clipping Mask -->
      <button
        v-if="canMask"
        @click="store.maskSelectedItems()"
        title="Create Clipping Mask"
        class="p-1.5 rounded-lg hover:bg-surface-100 dark:hover:bg-surface-700 text-surface-500 dark:text-surface-400 hover:text-cyan-500 transition-colors relative group"
      >
        <span class="material-symbols-rounded text-lg">content_cut</span>
        <span class="absolute -top-7 left-1/2 -translate-x-1/2 text-[10px] bg-surface-800 dark:bg-surface-200 text-white dark:text-surface-800 px-1.5 py-0.5 rounded-full whitespace-nowrap opacity-0 group-hover:opacity-100 transition-opacity pointer-events-none">
          Mask
        </span>
      </button>

      <!-- Divider -->
      <div class="w-px h-5 bg-surface-200 dark:bg-surface-700 mx-0.5"></div>

      <!-- Bulk actions -->
      <button
        @click="store.deleteSelectedItems()"
        title="Delete selected"
        class="p-1.5 rounded-lg hover:bg-red-50 dark:hover:bg-red-900/20 text-red-500 transition-colors relative group"
      >
        <span class="material-symbols-rounded text-lg">delete</span>
        <span class="absolute -top-7 left-1/2 -translate-x-1/2 text-[10px] bg-surface-800 dark:bg-surface-200 text-white dark:text-surface-800 px-1.5 py-0.5 rounded-full whitespace-nowrap opacity-0 group-hover:opacity-100 transition-opacity pointer-events-none">
          Delete
        </span>
      </button>
      <button
        @click="store.duplicateSelectedItems(30, 30)"
        title="Duplicate (Ctrl+D)"
        class="p-1.5 rounded-lg hover:bg-surface-100 dark:hover:bg-surface-700 text-surface-500 dark:text-surface-400 hover:text-primary-500 transition-colors relative group"
      >
        <span class="material-symbols-rounded text-lg">content_copy</span>
        <span class="absolute -top-7 left-1/2 -translate-x-1/2 text-[10px] bg-surface-800 dark:bg-surface-200 text-white dark:text-surface-800 px-1.5 py-0.5 rounded-full whitespace-nowrap opacity-0 group-hover:opacity-100 transition-opacity pointer-events-none">
          Duplicate
        </span>
      </button>

      <!-- Count badge -->
      <div class="ml-1 text-[10px] text-surface-400 dark:text-surface-500 font-medium tabular-nums">
        {{ store.selectedItemIds.size }} items
      </div>
    </div>
  </transition>
</template>

<script setup>
import { computed } from 'vue'
import { useMoodBoardsStore } from '@/addons/moodboards/stores/moodBoards'

const store = useMoodBoardsStore()

const hasGroups = computed(() => store.selectionHasGroups())
const canMask = computed(() => store.canMaskSelection())

const alignActions = [
  { dir: 'left',     icon: 'align_horizontal_left',   label: 'Align Left' },
  { dir: 'center-h', icon: 'align_horizontal_center', label: 'Align Center' },
  { dir: 'right',    icon: 'align_horizontal_right',  label: 'Align Right' },
  { dir: 'top',      icon: 'align_vertical_top',      label: 'Align Top' },
  { dir: 'center-v', icon: 'align_vertical_center',   label: 'Align Middle' },
  { dir: 'bottom',   icon: 'align_vertical_bottom',   label: 'Align Bottom' },
]

const distributeActions = [
  { dir: 'distribute-h', icon: 'horizontal_distribute', label: 'Distribute Horizontally' },
  { dir: 'distribute-v', icon: 'vertical_distribute',   label: 'Distribute Vertically' },
]

// Position bar at top-center of viewport
const barStyle = computed(() => ({
  top: '56px',
  left: '50%',
  transform: 'translateX(-50%)',
}))
</script>

<style scoped>
.align-bar-enter-active,
.align-bar-leave-active {
  transition: opacity 0.2s ease, transform 0.2s ease;
}
.align-bar-enter-from,
.align-bar-leave-to {
  opacity: 0;
  transform: translateX(-50%) translateY(-8px);
}
</style>

