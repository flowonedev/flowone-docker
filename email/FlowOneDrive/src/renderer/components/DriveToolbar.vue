<script setup lang="ts">
// File-manager toolbar matching the web Drive UI: Row 1 = back/forward/up +
// path bar + search; Row 2 = actions + view toggles. Spans the full window
// width ABOVE the sidebar, exactly like the web app.

defineProps<{
  breadcrumbs: Array<{ id: number | null; name: string }>
  isTrash: boolean
  searchQuery: string
  viewMode: 'grid' | 'list'
  canGoBack: boolean
  canGoForward: boolean
  canGoUp: boolean
  syncing: boolean
  syncingTrackings: boolean
}>()

const emit = defineEmits<{
  'update:searchQuery': [value: string]
  'update:viewMode': [value: 'grid' | 'list']
  'back': []
  'forward': []
  'up': []
  'navigate': [id: number | null, name: string]
  'open-local-folder': []
  'sync-now': []
  'sync-trackings': []
}>()

function onSearchInput(e: Event) {
  emit('update:searchQuery', (e.target as HTMLInputElement).value)
}
</script>

<template>
  <header class="drive-toolbar">
    <!-- ============ Row 1: explorer bar ============ -->
    <div class="toolbar-row">
      <!-- History navigation -->
      <div class="nav-group">
        <button type="button" class="nav-btn" :class="{ disabled: !canGoBack }" :disabled="!canGoBack" @click="emit('back')" title="Back">
          <span class="material-symbols-rounded">arrow_back</span>
        </button>
        <button type="button" class="nav-btn" :class="{ disabled: !canGoForward }" :disabled="!canGoForward" @click="emit('forward')" title="Forward">
          <span class="material-symbols-rounded">arrow_forward</span>
        </button>
        <button type="button" class="nav-btn" :class="{ disabled: !canGoUp }" :disabled="!canGoUp" @click="emit('up')" title="Up one level">
          <span class="material-symbols-rounded">arrow_upward</span>
        </button>
      </div>

      <!-- Path bar (leading location icon + clickable breadcrumb) -->
      <nav class="path-bar" aria-label="Breadcrumb">
        <span class="material-symbols-rounded path-icon">{{ isTrash ? 'delete' : 'hard_drive' }}</span>
        <template v-for="(crumb, index) in breadcrumbs" :key="crumb.id ?? 'root'">
          <span class="material-symbols-rounded path-chevron">chevron_right</span>
          <button
            v-if="index < breadcrumbs.length - 1"
            type="button"
            class="path-segment clickable"
            @click="emit('navigate', crumb.id, crumb.name)"
          >{{ crumb.name }}</button>
          <span v-else class="path-segment current">{{ crumb.name }}</span>
        </template>
      </nav>

      <!-- Search (magnifier on the right, like the web app) -->
      <div class="search-field">
        <input
          :value="searchQuery"
          type="text"
          placeholder="Search My Drive"
          @input="onSearchInput"
        />
        <span class="material-symbols-rounded search-icon">search</span>
      </div>
    </div>

    <!-- ============ Row 2: actions toolbar + view toggles ============ -->
    <div class="toolbar-row row-2">
      <button type="button" class="outline-btn" @click="emit('open-local-folder')">
        <span class="material-symbols-rounded">folder_open</span>
        Open Local Folder
      </button>
      <button type="button" class="outline-btn" :disabled="syncing" @click="emit('sync-now')">
        <span class="material-symbols-rounded" :class="{ 'animate-spin': syncing }">sync</span>
        {{ syncing ? 'Syncing...' : 'Sync Now' }}
      </button>

      <div class="v-divider"></div>

      <button type="button" class="flat-btn" :disabled="syncingTrackings" @click="emit('sync-trackings')">
        <span class="material-symbols-rounded" :class="{ 'animate-spin': syncingTrackings }">published_with_changes</span>
        {{ syncingTrackings ? 'Syncing...' : 'Sync Trackings' }}
      </button>

      <div class="spacer"></div>

      <!-- View mode toggles (active gets a tinted box, like the web) -->
      <div class="view-toggles">
        <button type="button" class="view-btn" :class="{ active: viewMode === 'list' }" @click="emit('update:viewMode', 'list')" title="List view">
          <span class="material-symbols-rounded">view_list</span>
        </button>
        <button type="button" class="view-btn" :class="{ active: viewMode === 'grid' }" @click="emit('update:viewMode', 'grid')" title="Grid view">
          <span class="material-symbols-rounded">grid_view</span>
        </button>
      </div>
    </div>
  </header>
</template>

<style scoped>
.drive-toolbar {
  background: var(--bg-main);
  flex-shrink: 0;
}

.toolbar-row {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 6px 10px;
  border-bottom: 1px solid var(--border);
}

.toolbar-row.row-2 {
  gap: 4px;
  padding: 8px 10px;
}

/* --- Row 1 --- */
.nav-group {
  display: flex;
  align-items: center;
  gap: 2px;
  flex-shrink: 0;
}

.nav-btn {
  width: 32px;
  height: 32px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  border-radius: 6px;
  color: var(--text-muted);
  transition: background 0.15s ease, color 0.15s ease;
}

.nav-btn:not(.disabled):hover {
  background: var(--bg-elevated);
  color: var(--text-primary);
}

.nav-btn.disabled {
  color: var(--text-ghost);
  cursor: default;
}

.nav-btn .material-symbols-rounded {
  font-size: 20px;
}

.path-bar {
  flex: 1;
  min-width: 0;
  height: 36px;
  display: flex;
  align-items: center;
  gap: 2px;
  padding: 0 8px;
  border-radius: 8px;
  border: 1px solid var(--border);
  background: var(--bg-card);
  overflow: hidden;
}

.path-icon {
  font-size: 18px;
  color: var(--text-muted);
  flex-shrink: 0;
  padding-left: 2px;
}

.path-chevron {
  font-size: 16px;
  color: var(--text-faint);
  flex-shrink: 0;
}

.path-segment {
  padding: 2px 6px;
  border-radius: 6px;
  font-size: 13px;
  font-weight: 500;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  min-width: 0;
}

.path-segment.clickable {
  color: var(--text-muted);
  transition: background 0.15s ease, color 0.15s ease;
}

.path-segment.clickable:hover {
  background: var(--bg-elevated);
  color: var(--text-primary);
}

.path-segment.current {
  color: var(--text-primary);
}

.search-field {
  position: relative;
  width: 280px;
  flex-shrink: 0;
}

.search-field input {
  width: 100%;
  height: 36px;
  padding: 0 36px 0 12px;
  border-radius: 8px;
  background: var(--bg-card);
  border: 1px solid var(--border);
  color: var(--text-primary);
  font-size: 13px;
}

.search-field input::placeholder {
  color: var(--text-dim);
}

.search-field input:focus {
  border-color: #22c55e;
  outline: none;
}

.search-icon {
  position: absolute;
  right: 10px;
  top: 50%;
  transform: translateY(-50%);
  font-size: 18px;
  color: var(--text-dim);
  pointer-events: none;
}

/* --- Row 2 --- */
.outline-btn {
  height: 32px;
  padding: 0 12px;
  display: inline-flex;
  align-items: center;
  gap: 6px;
  border-radius: 6px;
  border: 1px solid var(--border);
  background: var(--bg-card);
  color: var(--text-secondary);
  font-size: 13px;
  font-weight: 500;
  flex-shrink: 0;
  transition: background 0.15s ease;
}

.outline-btn:hover:not(:disabled) {
  background: var(--bg-elevated);
}

.outline-btn:disabled {
  opacity: 0.5;
  cursor: default;
}

.outline-btn .material-symbols-rounded {
  font-size: 16px;
}

.flat-btn {
  height: 32px;
  padding: 0 10px;
  display: inline-flex;
  align-items: center;
  gap: 6px;
  border-radius: 6px;
  color: var(--text-muted);
  font-size: 13px;
  flex-shrink: 0;
  transition: background 0.15s ease, color 0.15s ease;
}

.flat-btn:hover:not(:disabled) {
  background: var(--bg-elevated);
  color: var(--text-primary);
}

.flat-btn:disabled {
  opacity: 0.5;
  cursor: default;
}

.flat-btn .material-symbols-rounded {
  font-size: 16px;
}

.v-divider {
  width: 1px;
  height: 16px;
  background: var(--border);
  margin: 0 6px;
  flex-shrink: 0;
}

.spacer {
  flex: 1;
}

.view-toggles {
  display: flex;
  align-items: center;
  gap: 2px;
  flex-shrink: 0;
}

.view-btn {
  width: 32px;
  height: 32px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  border-radius: 6px;
  color: var(--text-dim);
  transition: background 0.15s ease, color 0.15s ease;
}

.view-btn:hover {
  background: var(--bg-elevated);
  color: var(--text-primary);
}

.view-btn.active {
  background: rgba(34, 197, 94, 0.15);
  color: #22c55e;
  box-shadow: inset 0 0 0 1px rgba(34, 197, 94, 0.3);
}

.view-btn .material-symbols-rounded {
  font-size: 18px;
}
</style>
