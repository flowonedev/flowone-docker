<template>
  <div
    class="flex-shrink-0 border-r border-surface-200 dark:border-surface-700 bg-surface-50 dark:bg-surface-900 flex h-full relative select-none"
    :style="{ width: collapsed ? `${RAIL_WIDTH}px` : `${panelWidth + RAIL_WIDTH}px`, transition: isResizing ? 'none' : 'width 200ms ease' }"
  >
    <!-- Left utility rail — Figma-style: icon + label stacked -->
    <div class="w-[56px] flex-shrink-0 h-full border-r border-surface-200 dark:border-surface-700/80 bg-surface-50 dark:bg-surface-900 flex flex-col items-center">
      <!-- Board color dot at the very top -->
      <div class="w-full flex items-center justify-center pt-2.5 pb-1 flex-shrink-0">
        <div
          class="w-5 h-5 rounded-full border-2 border-surface-200 dark:border-surface-600 flex-shrink-0"
          :style="{ backgroundColor: boardColor }"
          :title="boardName || 'Board'"
        />
      </div>

      <!-- Navigation tabs -->
      <div class="flex-1 w-full flex flex-col items-center gap-0.5 pt-1 pb-2 overflow-y-auto scrollbar-hide">
        <button
          v-for="tab in tabs"
          :key="tab.id"
          @click="onTabClick(tab.id)"
          class="w-full flex flex-col items-center gap-0.5 py-1.5 rounded-lg transition-colors flex-shrink-0"
          :class="[
            activeTab === tab.id && !collapsed
              ? 'text-primary-600 dark:text-primary-400'
              : 'text-surface-400 dark:text-surface-500 hover:text-surface-700 dark:hover:text-surface-300 hover:bg-surface-100 dark:hover:bg-surface-800'
          ]"
        >
          <span
            class="w-8 h-8 flex items-center justify-center rounded-lg transition-colors"
            :class="activeTab === tab.id && !collapsed ? 'bg-primary-500/10 dark:bg-primary-400/10' : ''"
          >
            <span class="material-symbols-rounded text-[20px] leading-none">{{ tab.icon }}</span>
          </span>
          <span class="text-[9px] font-medium leading-none tracking-wide">{{ tab.label }}</span>
        </button>
      </div>

      <!-- Bottom actions: Ready, Comments, Share, Settings, Collapse -->
      <div class="w-full px-1.5 pb-2 border-t border-surface-200 dark:border-surface-700/70 pt-2 flex flex-col items-center gap-1">
        <!-- Mark Ready -->
        <button
          @click="emit('toggle-ready')"
          class="w-full flex flex-col items-center gap-0.5 py-1.5 rounded-lg transition-colors flex-shrink-0"
          :class="isReady
            ? 'text-green-500'
            : 'text-surface-400 dark:text-surface-500 hover:text-surface-700 dark:hover:text-surface-300 hover:bg-surface-100 dark:hover:bg-surface-800'"
          :title="isReady ? 'Marked as ready' : 'Mark as ready'"
        >
          <span class="w-8 h-8 flex items-center justify-center rounded-lg" :class="isReady ? 'bg-green-500/10' : ''">
            <span class="material-symbols-rounded text-[20px] leading-none">{{ isReady ? 'check_circle' : 'radio_button_unchecked' }}</span>
          </span>
          <span class="text-[9px] font-medium leading-none tracking-wide">{{ isReady ? 'Ready' : 'Ready' }}</span>
        </button>

        <!-- Comments -->
        <button
          @click="emit('open-comments')"
          class="relative w-full flex flex-col items-center gap-0.5 py-1.5 rounded-lg transition-colors flex-shrink-0"
          :class="isCommentActive
            ? 'text-primary-600 dark:text-primary-400'
            : 'text-surface-400 dark:text-surface-500 hover:text-surface-700 dark:hover:text-surface-300 hover:bg-surface-100 dark:hover:bg-surface-800'"
          title="Comments"
        >
          <span class="w-8 h-8 flex items-center justify-center rounded-lg relative" :class="isCommentActive ? 'bg-primary-500/10 dark:bg-primary-400/10' : ''">
            <span class="material-symbols-rounded text-[20px] leading-none">comment</span>
            <span
              v-if="commentCount > 0"
              class="absolute -top-0.5 -right-0.5 min-w-[14px] h-3.5 px-0.5 rounded-full bg-red-500 text-white text-[7px] font-bold flex items-center justify-center"
            >{{ commentCount }}</span>
          </span>
          <span class="text-[9px] font-medium leading-none tracking-wide">Chat</span>
        </button>

        <!-- Share -->
        <button
          @click="emit('open-share')"
          class="w-full flex flex-col items-center gap-0.5 py-1.5 rounded-lg text-surface-400 dark:text-surface-500 hover:text-primary-500 hover:bg-surface-100 dark:hover:bg-surface-800 transition-colors flex-shrink-0"
          title="Share board"
        >
          <span class="w-8 h-8 flex items-center justify-center rounded-lg">
            <span class="material-symbols-rounded text-[20px] leading-none">share</span>
          </span>
          <span class="text-[9px] font-medium leading-none tracking-wide">Share</span>
        </button>

        <!-- Settings -->
        <button
          @click="emit('open-settings')"
          class="w-full flex flex-col items-center gap-0.5 py-1.5 rounded-lg text-surface-400 dark:text-surface-500 hover:text-surface-700 dark:hover:text-surface-300 hover:bg-surface-100 dark:hover:bg-surface-800 transition-colors flex-shrink-0"
          title="Board settings"
        >
          <span class="w-8 h-8 flex items-center justify-center rounded-lg">
            <span class="material-symbols-rounded text-[20px] leading-none">settings</span>
          </span>
          <span class="text-[9px] font-medium leading-none tracking-wide">Settings</span>
        </button>

        <!-- Collapse/Expand -->
        <button
          @click="collapsed = !collapsed"
          class="w-full flex flex-col items-center gap-0.5 py-1.5 rounded-lg text-surface-400 dark:text-surface-500 hover:text-surface-700 dark:hover:text-surface-300 hover:bg-surface-100 dark:hover:bg-surface-800 transition-colors"
          :title="collapsed ? 'Expand panel' : 'Collapse panel'"
        >
          <span class="material-symbols-rounded text-[20px]">{{ collapsed ? 'right_panel_open' : 'left_panel_close' }}</span>
          <span class="text-[9px] font-medium leading-none tracking-wide">{{ collapsed ? 'Open' : 'Close' }}</span>
        </button>
      </div>
    </div>

    <!-- Panel content -->
    <div v-if="!collapsed" class="flex-1 overflow-hidden flex flex-col min-h-0 bg-white dark:bg-surface-800 border-l border-surface-200 dark:border-surface-700/60">
      <!-- LAYERS TAB -->
      <template v-if="activeTab === 'layers'">
        <MoodLayerPanel
          ref="layerPanelRef"
          :embedded="true"
          @fly-to-item="$emit('fly-to-item', $event)"
        />
      </template>

      <!-- ASSETS TAB -->
      <template v-if="activeTab === 'assets'">
        <MoodAssetsPanel @fly-to-item="$emit('fly-to-item', $event)" />
      </template>

      <!-- CONTENT TAB -->
      <template v-if="activeTab === 'content'">
        <MoodContentPanel
          :items="contentItems"
          :editable="true"
          :board-id="moodStore.currentBoard?.id"
          @update-item="onContentUpdateItem"
          @replace-image="onContentReplaceImage"
        />
      </template>

      <!-- COMPONENTS TAB (Website Element templates) -->
      <template v-if="activeTab === 'components'">
        <MoodComponentLibrary
          :embedded="true"
          @place-component="$emit('place-component', $event)"
        />
      </template>

      <!-- GLOBALS TAB (unified: colors, gradients, text styles, components) -->
      <template v-if="activeTab === 'globals'">
        <MoodGlobalsPanel
          @place-component="$emit('place-component', $event)"
          @edit-component-items="$emit('edit-component-items', $event)"
        />
      </template>

      <!-- HISTORY TAB (authenticated users only) -->
      <template v-if="activeTab === 'history' && !moodStore.isPublicView">
        <MoodHistoryPanel />
      </template>

      <!-- ACTIVITY TAB -->
      <template v-if="activeTab === 'activity'">
        <MoodActivityPanel
          @fly-to-item="$emit('fly-to-item', $event)"
        />
      </template>
    </div>

    <!-- Resize handle (right edge) -->
    <div
      v-if="!collapsed"
      class="absolute top-0 right-0 w-1.5 h-full cursor-col-resize z-10 group hover:bg-primary-500/20 transition-colors"
      @mousedown.prevent="startResize"
    >
      <div class="absolute top-1/2 right-0 -translate-y-1/2 w-0.5 h-8 rounded-full bg-surface-300 dark:bg-surface-600 opacity-0 group-hover:opacity-100 transition-opacity" />
    </div>
  </div>
</template>

<script setup>
import { ref, computed, watch, onMounted, onUnmounted } from 'vue'
import MoodLayerPanel from './MoodLayerPanel.vue'
import MoodComponentLibrary from './MoodComponentLibrary.vue'
import MoodGlobalsPanel from './MoodGlobalsPanel.vue'
import MoodActivityPanel from './MoodActivityPanel.vue'
import MoodHistoryPanel from './MoodHistoryPanel.vue'
import MoodContentPanel from './MoodContentPanel.vue'
import MoodAssetsPanel from './MoodAssetsPanel.vue'
import { useMoodBoardsStore } from '../stores/moodBoards'

const props = defineProps({
  boardName: { type: String, default: '' },
  boardColor: { type: String, default: '#f5f5f5' },
  isReady: { type: Boolean, default: false },
  commentCount: { type: Number, default: 0 },
  isCommentActive: { type: Boolean, default: false },
})

const emit = defineEmits([
  'fly-to-item', 'place-component', 'edit-component-items',
  'toggle-ready', 'open-comments', 'open-share', 'open-settings',
])
const moodStore = useMoodBoardsStore()

const contentItems = computed(() => moodStore.currentBoard?.items || [])

const layerPanelRef = ref(null)
const collapsed = defineModel('collapsed', { type: Boolean, default: false })

// Restore persisted states from localStorage
const savedCollapsed = localStorage.getItem('mood_left_sidebar_collapsed')
if (savedCollapsed !== null) collapsed.value = savedCollapsed === 'true'

const savedTab = localStorage.getItem('mood_left_sidebar_tab')
const activeTab = ref(savedTab || 'layers')

const RAIL_WIDTH = 56
const MIN_WIDTH = 200
const MAX_WIDTH = 500
const DEFAULT_WIDTH = 280

// Restore width from localStorage
const panelWidth = ref(parseInt(localStorage.getItem('mood_left_panel_width')) || DEFAULT_WIDTH)

// Persist collapsed state and active tab
watch(collapsed, (v) => localStorage.setItem('mood_left_sidebar_collapsed', String(v)))
watch(activeTab, (v) => localStorage.setItem('mood_left_sidebar_tab', v))
const isResizing = ref(false)

const allTabs = [
  { id: 'layers', label: 'Layers', icon: 'layers' },
  { id: 'globals', label: 'Globals', icon: 'token' },
  { id: 'assets', label: 'Assets', icon: 'image' },
  { id: 'content', label: 'Content', icon: 'text_fields' },
  { id: 'components', label: 'Library', icon: 'web' },
  { id: 'history', label: 'History', icon: 'hourglass_empty', authOnly: true },
  { id: 'activity', label: 'Activity', icon: 'update' },
]

const tabs = computed(() =>
  moodStore.isPublicView ? allTabs.filter(t => !t.authOnly) : allTabs
)

function onTabClick(tabId) {
  if (collapsed.value) {
    collapsed.value = false
    activeTab.value = tabId
  } else if (activeTab.value === tabId) {
    // Clicking the active tab collapses the sidebar
    collapsed.value = true
  } else {
    activeTab.value = tabId
  }
}

// ── Content panel handlers ──
async function onContentUpdateItem({ itemId, data }) {
  try {
    await moodStore.updateItem(itemId, data)
  } catch (e) {
    console.error('[MoodLeftSidebar] Content update failed:', e)
  }
}

async function onContentReplaceImage({ itemId, file }) {
  if (!moodStore.currentBoard?.id) return
  try {
    const uploads = await moodStore.uploadFiles([file])
    if (uploads?.length > 0) {
      await moodStore.updateItem(itemId, {
        image_url: uploads[0].url,
        thumbnail_url: null,
      })
    }
  } catch (e) {
    console.error('[MoodLeftSidebar] Image replace failed:', e)
  }
}

// ── Resize drag ──
let _resizeStartX = 0
let _resizeStartW = 0

function startResize(e) {
  isResizing.value = true
  _resizeStartX = e.clientX
  _resizeStartW = panelWidth.value
  document.addEventListener('mousemove', onResize)
  document.addEventListener('mouseup', endResize)
  document.body.style.cursor = 'col-resize'
  document.body.style.userSelect = 'none'
}

function onResize(e) {
  const delta = e.clientX - _resizeStartX
  panelWidth.value = Math.max(MIN_WIDTH, Math.min(MAX_WIDTH, _resizeStartW + delta))
}

function endResize() {
  isResizing.value = false
  document.removeEventListener('mousemove', onResize)
  document.removeEventListener('mouseup', endResize)
  document.body.style.cursor = ''
  document.body.style.userSelect = ''
  localStorage.setItem('mood_left_panel_width', String(panelWidth.value))
}

onUnmounted(() => {
  document.removeEventListener('mousemove', onResize)
  document.removeEventListener('mouseup', endResize)
})
</script>

