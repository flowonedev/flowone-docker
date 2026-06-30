<script setup lang="ts">
import { ref, computed, watch } from 'vue'
import { useConfigStore } from '../stores/config'
import { useWatchFoldersStore } from '../stores/watchFolders'

/**
 * Wave D.7 — watch folder management from the desktop app.
 *
 * Shows what project/board a watch folder is tracking time for, which local
 * directory it watches, and lets the user re-point it at a different folder
 * or remove it entirely (same server-side delete as the cloud app).
 */

interface WatchFolder {
  id: number
  name: string
  folderPath: string
  resolvedPath: string
  clientId: number
  clientName: string
  boardId: number | null
  boardName: string | null
  cardId: number | null
  status: 'watching' | 'not_found' | 'pending'
}

const props = defineProps<{ folder: WatchFolder | null }>()
const emit = defineEmits<{ (e: 'close'): void }>()

const configStore = useConfigStore()
const watchFoldersStore = useWatchFoldersStore()

const busy = ref(false)
const error = ref('')
const confirmingRemove = ref(false)

// Reset transient state whenever a different folder is opened
watch(() => props.folder?.id, () => {
  busy.value = false
  error.value = ''
  confirmingRemove.value = false
})

const statusLabel = computed(() => {
  switch (props.folder?.status) {
    case 'watching': return 'Watching'
    case 'not_found': return 'Folder not found'
    default: return 'Pending'
  }
})
const statusColor = computed(() =>
  props.folder?.status === 'watching' ? '#22c55e'
  : props.folder?.status === 'not_found' ? '#ef4444'
  : '#F59E0B'
)

function cloudOrigin(): string {
  const apiUrl = configStore.config.apiUrl || ''
  return apiUrl ? apiUrl.replace(/\/api\/?$/i, '').replace(/\/$/, '') : ''
}

function openInCloud() {
  if (!props.folder) return
  const origin = cloudOrigin()
  if (!origin) return
  const url = props.folder.boardId
    ? `${origin}/boards/${props.folder.boardId}`
    : `${origin}/boards`
  window.api.openExternalUrl(url).catch(() => {})
}

async function openLocally() {
  if (!props.folder) return
  const res = await window.api.openWatchFolderLocally(props.folder.id)
  if (!res.success) error.value = res.error || 'Could not open folder'
}

async function changeFolder() {
  if (!props.folder || busy.value) return
  busy.value = true
  error.value = ''
  try {
    const res = await window.api.changeWatchFolderPath(props.folder.id)
    if (res.canceled) return
    if (!res.success) {
      error.value = res.error || 'Failed to change folder'
      return
    }
    watchFoldersStore.setFolders(res.folders as any)
    emit('close')
  } finally {
    busy.value = false
  }
}

async function removeFolder() {
  if (!props.folder || busy.value) return
  if (!confirmingRemove.value) {
    confirmingRemove.value = true
    return
  }
  busy.value = true
  error.value = ''
  try {
    const res = await window.api.removeWatchFolder(props.folder.id)
    if (!res.success) {
      error.value = res.error || 'Failed to remove watch folder'
      confirmingRemove.value = false
      return
    }
    watchFoldersStore.setFolders(res.folders as any)
    emit('close')
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <Teleport to="body">
    <div v-if="folder" style="position: fixed; inset: 0; z-index: 50; display: flex; align-items: center; justify-content: center; background: rgba(0,0,0,0.6);" @click.self="emit('close')">
      <div style="width: 460px; background: var(--bg-card); border: 1px solid var(--border); border-radius: 14px; box-shadow: 0 25px 50px rgba(0,0,0,0.5); overflow: hidden;">

        <!-- Header -->
        <div style="display: flex; align-items: center; justify-content: space-between; padding: 14px 18px; border-bottom: 1px solid var(--border);">
          <div style="display: flex; align-items: center; gap: 10px; min-width: 0;">
            <span class="material-symbols-rounded" style="font-size: 20px; color: #F59E0B;">visibility</span>
            <h3 style="color: var(--text-primary); font-weight: 600; font-size: 15px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">{{ folder.name }}</h3>
            <span :style="{ fontSize: '10px', padding: '2px 8px', borderRadius: '9999px', background: statusColor + '26', color: statusColor, whiteSpace: 'nowrap' }">{{ statusLabel }}</span>
          </div>
          <button @click="emit('close')" style="padding: 4px; border-radius: 5px; color: var(--text-muted);" class="hover:bg-[--bg-elevated] hover:text-white">
            <span class="material-symbols-rounded" style="font-size: 20px;">close</span>
          </button>
        </div>

        <!-- Details -->
        <div style="padding: 18px; display: flex; flex-direction: column; gap: 12px; font-size: 13px;">
          <div style="display: flex; justify-content: space-between; gap: 12px;">
            <span style="color: var(--text-muted); white-space: nowrap;">Project / Client</span>
            <span style="color: var(--text-primary); font-weight: 500; text-align: right;">{{ folder.clientName || '—' }}</span>
          </div>
          <div style="display: flex; justify-content: space-between; gap: 12px; align-items: center;">
            <span style="color: var(--text-muted); white-space: nowrap;">Board</span>
            <span style="display: flex; align-items: center; gap: 6px; min-width: 0;">
              <span style="color: var(--text-primary); font-weight: 500; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">{{ folder.boardName || '—' }}</span>
              <button v-if="folder.boardId" @click="openInCloud" title="Open board in FlowOne" style="display: flex; color: var(--text-muted);" class="hover:text-white">
                <span class="material-symbols-rounded" style="font-size: 15px;">open_in_new</span>
              </button>
            </span>
          </div>
          <div>
            <div style="color: var(--text-muted); margin-bottom: 5px;">Watched local folder</div>
            <button @click="openLocally" title="Open in file explorer"
              style="width: 100%; display: flex; align-items: center; gap: 8px; padding: 9px 11px; border-radius: 7px; background: var(--bg-main); border: 1px solid var(--border); color: var(--text-primary); font-size: 12px; text-align: left;"
              class="hover:border-[#F59E0B]">
              <span class="material-symbols-rounded" style="font-size: 16px; color: #F59E0B; flex-shrink: 0;">folder_open</span>
              <span style="overflow: hidden; text-overflow: ellipsis; white-space: nowrap; direction: rtl;">{{ folder.resolvedPath }}</span>
            </button>
          </div>
          <p style="color: var(--text-ghost); font-size: 11px; line-height: 1.5;">
            Time spent editing files inside this folder is tracked for the project above.
            Removing the watch folder stops the tracking only — your local files are not touched.
          </p>
          <p v-if="error" style="color: #ef4444; font-size: 12px;">{{ error }}</p>
        </div>

        <!-- Actions -->
        <div style="display: flex; align-items: center; gap: 10px; padding: 14px 18px; border-top: 1px solid var(--border); background: var(--bg-main);">
          <button @click="removeFolder" :disabled="busy"
            :style="{ padding: '8px 14px', borderRadius: '9999px', fontSize: '13px', background: confirmingRemove ? '#ef4444' : 'transparent', color: confirmingRemove ? 'white' : '#ef4444', border: '1px solid #ef4444' }"
            class="disabled:opacity-50">
            {{ confirmingRemove ? 'Confirm remove?' : 'Remove' }}
          </button>
          <div style="flex: 1;"></div>
          <button @click="changeFolder" :disabled="busy" style="padding: 8px 16px; border-radius: 9999px; background: var(--bg-elevated); color: var(--text-primary); font-size: 13px;" class="hover:bg-[--bg-elevated-hover] disabled:opacity-50">
            Change Folder…
          </button>
          <button @click="openInCloud" style="padding: 8px 16px; border-radius: 9999px; background: #F59E0B; color: black; font-size: 13px; font-weight: 600;" class="hover:bg-[#d97706]">
            Open in FlowOne
          </button>
        </div>
      </div>
    </div>
  </Teleport>
</template>
