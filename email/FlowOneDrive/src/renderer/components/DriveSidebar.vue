<script setup lang="ts">
// Resizable Drive sidebar: collapsible Favorites, Folders tree, Watch Folders
// and the storage-card footer (web Drive UI parity).
import { ref, computed } from 'vue'
import { useFilesStore } from '../stores/files'
import { useWatchFoldersStore } from '../stores/watchFolders'
import { useConfigStore } from '../stores/config'
import FolderTreeNode from './FolderTreeNode.vue'
import { formatSize, formatTrackingDuration } from '../utils/format'
import { getFolderColor } from '../utils/fileVisuals'

const props = defineProps<{
  currentFolderId: number | null
  isTrash: boolean
  currentView: 'files' | 'activity' | 'settings'
  expandedFolders: Set<number>
  watchFolderSessions: Array<{ filename: string; processName: string; duration: number; watchFolderId: number; clientName: string; boardName: string | null }>
  buildLabel: string
}>()

const emit = defineEmits<{
  'navigate': [folderId: number | null, folderName?: string]
  'open-trash': []
  'toggle-expand': [folderId: number, event?: MouseEvent]
  'logout': []
  'manage-watch-folder': [wf: any]
}>()

const filesStore = useFilesStore()
const watchFoldersStore = useWatchFoldersStore()
const configStore = useConfigStore()

const favoritesOpen = ref(true)

const rootFolders = computed(() => filesStore.getChildFolders(null))

// Storage bar: decorative full bar when unlimited (web parity)
const quotaPercent = computed(() => {
  if (!filesStore.quota.total) return 0
  return Math.min(100, Math.round(((filesStore.quota.used || 0) / filesStore.quota.total) * 100))
})
const quotaBarWidth = computed(() => {
  if (!filesStore.quota.total) return '100%'
  return Math.max(2, quotaPercent.value) + '%'
})

function openWatchFolderInCloud(wf: { boardId: number | null; clientId: number | null }) {
  const apiUrl = configStore.config.apiUrl || ''
  const origin = apiUrl
    ? apiUrl.replace(/\/api\/?$/i, '').replace(/\/$/, '')
    : ''
  if (!origin) return
  const url = wf.boardId
    ? `${origin}/boards/${wf.boardId}`
    : `${origin}/boards`
  window.api.openExternalUrl(url).catch((err: unknown) => {
    console.error('Failed to open watch folder in cloud:', err)
  })
}

// ─── Resizable width ───
const sidebarWidth = ref(200)
const isResizing = ref(false)
const minSidebarWidth = 150
const maxSidebarWidth = 400

function startResize() {
  isResizing.value = true
  document.addEventListener('mousemove', handleResize)
  document.addEventListener('mouseup', stopResize)
  document.body.style.cursor = 'col-resize'
  document.body.style.userSelect = 'none'
}

function handleResize(e: MouseEvent) {
  if (!isResizing.value) return
  sidebarWidth.value = Math.min(maxSidebarWidth, Math.max(minSidebarWidth, e.clientX))
}

function stopResize() {
  isResizing.value = false
  document.removeEventListener('mousemove', handleResize)
  document.removeEventListener('mouseup', stopResize)
  document.body.style.cursor = ''
  document.body.style.userSelect = ''
}
</script>

<template>
  <div
    :style="{ width: sidebarWidth + 'px', minWidth: minSidebarWidth + 'px', maxWidth: maxSidebarWidth + 'px' }"
    style="background: var(--bg-main); position: relative; border-right: 1px solid var(--border);"
    class="flex flex-col"
  >
    <!-- Nav -->
    <nav style="flex: 1; overflow-y: auto; padding: 10px 8px 0 8px;">
      <!-- Favorites (collapsible, like web) -->
      <button type="button" class="side-section-header" @click="favoritesOpen = !favoritesOpen">
        <span class="material-symbols-rounded" style="font-size: 16px;">{{ favoritesOpen ? 'keyboard_arrow_down' : 'chevron_right' }}</span>
        Favorites
      </button>
      <template v-if="favoritesOpen">
        <button
          type="button"
          class="side-nav-item"
          :class="{ active: !isTrash && currentFolderId === null && currentView === 'files' }"
          @click="emit('navigate', null)"
        >
          <span class="material-symbols-rounded">folder</span>
          <span>My Drive</span>
        </button>
        <button
          type="button"
          class="side-nav-item"
          :class="{ active: isTrash }"
          @click="emit('open-trash')"
        >
          <span class="material-symbols-rounded">delete</span>
          <span>Trash</span>
        </button>
      </template>

      <!-- Folders Tree - Windows Explorer style -->
      <template v-if="rootFolders.length > 0">
        <div class="side-section-label">Folders</div>
        <template v-for="folder in rootFolders" :key="folder.remoteId">
          <FolderTreeNode
            :folder="folder"
            :depth="0"
            :current-folder-id="currentFolderId"
            :expanded-folders="expandedFolders"
            :is-trash="isTrash"
            :get-folder-color="getFolderColor"
            @toggle-expand="(id, e) => emit('toggle-expand', id, e)"
            @navigate="(id, name) => emit('navigate', id, name)"
          />
        </template>
      </template>

      <!-- Watch Folders Section (only show folders that are actively watching) -->
      <div v-if="watchFoldersStore.activeWatchFolders.length > 0" style="margin-top: 4px;">
        <div style="height: 1px; background: var(--bg-elevated); margin: 8px 4px;"></div>
        <div style="display: flex; align-items: center; gap: 6px; padding: 4px 10px; color: #F59E0B; font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px;">
          <span class="material-symbols-rounded" style="font-size: 16px;">visibility</span>
          Watch Folders
        </div>
        <div v-for="wf in watchFoldersStore.activeWatchFolders" :key="wf.id"
          @click="emit('manage-watch-folder', wf)"
          :title="`${wf.name}${wf.clientName ? ' — ' + wf.clientName : ''}${wf.boardName ? ' / ' + wf.boardName : ''}\n${wf.resolvedPath}\nClick to manage (change folder, remove)`"
          style="display: flex; align-items: center; gap: 6px; padding: 6px 10px; font-size: 12px; color: var(--text-muted); border-radius: 6px; cursor: pointer;"
          class="hover:bg-[--bg-elevated] transition-colors">
          <span class="material-symbols-rounded" style="font-size: 16px; color: #F59E0B;">visibility</span>
          <div style="flex: 1; min-width: 0; overflow: hidden;">
            <div style="overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">{{ wf.name }}</div>
            <div v-if="wf.boardName" style="color: var(--text-ghost); font-size: 10px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">{{ wf.boardName }}</div>
          </div>
          <span v-if="wf.clientName" style="font-size: 10px; padding: 1px 5px; border-radius: 8px; background: rgba(245,158,11,0.15); color: #F59E0B; white-space: nowrap;">{{ wf.clientName }}</span>
          <span @click.stop="openWatchFolderInCloud(wf)" title="Open board in the web app"
            class="material-symbols-rounded hover:opacity-100" style="font-size: 14px; color: var(--text-ghost); opacity: 0.6;">open_in_new</span>
        </div>

        <!-- Active watch folder tracking sessions -->
        <div v-for="session in watchFolderSessions" :key="'wfs-' + session.filename"
          style="display: flex; align-items: center; gap: 6px; padding: 5px 10px 5px 16px; font-size: 11px; border-radius: 6px; background: rgba(34, 197, 94, 0.08); margin: 2px 4px;">
          <span class="material-symbols-rounded tracking-pulse" style="font-size: 14px; color: #22c55e;">radio_button_checked</span>
          <div style="flex: 1; min-width: 0; overflow: hidden;">
            <div style="color: #22c55e; font-weight: 600; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">{{ session.filename }}</div>
            <div style="color: var(--text-dim); font-size: 10px;">{{ session.processName }} {{ session.boardName ? '/ ' + session.boardName : '' }}</div>
          </div>
          <span style="font-size: 11px; font-weight: 700; color: #22c55e; font-variant-numeric: tabular-nums; white-space: nowrap;">{{ formatTrackingDuration(session.duration) }}</span>
        </div>
      </div>
    </nav>

    <!-- Footer: storage card (web parity) + sign out + build -->
    <div style="border-top: 1px solid var(--border);">
      <div class="quota-row">
        <div class="quota-icon">
          <span class="material-symbols-rounded">cloud_upload</span>
        </div>
        <div style="flex: 1; min-width: 0;">
          <div class="quota-line">
            <span class="quota-used">{{ filesStore.quota.used ? formatSize(filesStore.quota.used) : '0 B' }}</span>
            <span class="quota-of">used of {{ filesStore.quota.total ? formatSize(filesStore.quota.total) : 'Unlimited' }}</span>
          </div>
          <div v-if="!filesStore.quota.total" class="quota-sub">
            <span class="material-symbols-rounded" style="font-size: 12px;">all_inclusive</span>
            Unlimited storage
          </div>
          <div v-else class="quota-sub">{{ quotaPercent }}% used</div>
        </div>
      </div>
      <div style="padding: 0 10px 8px;">
        <button @click="emit('logout')" style="width: 100%; display: flex; align-items: center; justify-content: center; gap: 5px; padding: 7px; border-radius: 5px; color: #ef4444; font-size: 12px; margin-bottom: 6px;" class="hover:bg-[#3f1d1d] transition-colors">
          <span class="material-symbols-rounded" style="font-size: 16px;">logout</span>
          Sign Out
        </button>
        <div style="font-size: 10px; color: var(--text-dim); text-align: center;">
          {{ buildLabel }}
        </div>
      </div>
      <!-- Usage bar pinned to the very bottom edge -->
      <div class="quota-bar">
        <div class="quota-bar-fill" :style="{ width: quotaBarWidth }"></div>
      </div>
    </div>

    <!-- Resize handle -->
    <div
      @mousedown="startResize"
      :style="{
        position: 'absolute',
        top: 0,
        right: 0,
        width: '4px',
        height: '100%',
        cursor: 'col-resize',
        background: isResizing ? '#22c55e' : 'transparent',
        transition: 'background 0.15s ease'
      }"
      class="hover:bg-[#22c55e]/50"
    ></div>
  </div>
</template>

<style scoped>
.tracking-pulse {
  animation: trackPulse 1.5s ease-in-out infinite;
}
@keyframes trackPulse {
  0%, 100% { opacity: 1; }
  50% { opacity: 0.4; }
}

.side-section-header {
  width: 100%;
  display: flex;
  align-items: center;
  gap: 4px;
  padding: 4px 6px;
  margin-bottom: 2px;
  border-radius: 6px;
  font-size: 11px;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  color: var(--text-dim);
  text-align: left;
  transition: color 0.15s ease;
}

.side-section-header:hover {
  color: var(--text-muted);
}

.side-section-label {
  display: flex;
  align-items: center;
  padding: 4px 6px;
  margin: 14px 0 2px;
  font-size: 11px;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  color: var(--text-dim);
}

.side-nav-item {
  width: 100%;
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 6px 10px;
  border-radius: 6px;
  text-align: left;
  font-size: 13px;
  color: var(--text-muted);
  transition: background 0.15s ease, color 0.15s ease;
}

.side-nav-item:hover {
  background: var(--bg-elevated);
}

.side-nav-item.active {
  background: rgba(22, 163, 74, 0.15);
  color: #22c55e;
}

.side-nav-item .material-symbols-rounded {
  font-size: 18px;
}

/* ─── Footer: storage card ─── */
.quota-row {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 10px 12px 8px;
}

.quota-icon {
  width: 32px;
  height: 32px;
  border-radius: 8px;
  background: rgba(34, 197, 94, 0.12);
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
}

.quota-icon .material-symbols-rounded {
  font-size: 18px;
  color: #22c55e;
}

.quota-line {
  display: flex;
  align-items: baseline;
  gap: 4px;
  white-space: nowrap;
  overflow: hidden;
}

.quota-used {
  font-size: 13px;
  font-weight: 600;
  color: var(--text-primary);
}

.quota-of {
  font-size: 11px;
  color: var(--text-dim);
}

.quota-sub {
  display: flex;
  align-items: center;
  gap: 3px;
  font-size: 11px;
  color: var(--text-dim);
}

.quota-bar {
  height: 4px;
  width: 100%;
  background: var(--bg-elevated);
  overflow: hidden;
}

.quota-bar-fill {
  height: 100%;
  background: linear-gradient(to right, #4ade80, #22c55e);
  transition: width 0.3s ease;
}
</style>
