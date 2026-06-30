<script setup>
import { ref, computed, onMounted } from 'vue'
import api from '@/services/api'

const props = defineProps({
  cardId: { type: Number, required: true },
  boardId: { type: Number, default: null },
  clientId: { type: Number, default: null },
})

const folders = ref([])
const activity = ref([])
const loading = ref(false)
const expanded = ref(false)
const showCreate = ref(false)
const creating = ref(false)
const createError = ref('')
const newFolder = ref({ name: '', folder_path: '' })
const activityGroupBy = ref('user')
const activitySortCol = ref('date')
const activitySortAsc = ref(false)
const activityExpanded = ref(true)

const boardFolders = computed(() =>
  props.boardId ? folders.value.filter(f => Number(f.board_id) === props.boardId) : folders.value
)

const linkedFolders = computed(() => boardFolders.value.filter(f => Number(f.card_id) === props.cardId))
const availableFolders = computed(() => boardFolders.value.filter(f => !f.card_id || Number(f.card_id) !== props.cardId))

const canCreate = computed(() =>
  newFolder.value.name.trim() && newFolder.value.folder_path.trim() && !creating.value
)

const sortedActivity = computed(() => {
  const arr = [...activity.value]
  const col = activitySortCol.value
  const asc = activitySortAsc.value
  arr.sort((a, b) => {
    let va, vb
    if (col === 'file') { va = (a.file_name || '').toLowerCase(); vb = (b.file_name || '').toLowerCase() }
    else if (col === 'user') { va = (a.user_display_name || a.user_email || '').toLowerCase(); vb = (b.user_display_name || b.user_email || '').toLowerCase() }
    else if (col === 'duration') { va = a.duration_seconds || 0; vb = b.duration_seconds || 0 }
    else { va = a.created_at || ''; vb = b.created_at || '' }
    if (va < vb) return asc ? -1 : 1
    if (va > vb) return asc ? 1 : -1
    return 0
  })
  return arr
})

const groupedActivity = computed(() => {
  const key = activityGroupBy.value
  const groups = {}
  for (const a of sortedActivity.value) {
    let gk, gl
    if (key === 'user') {
      gk = a.user_email || 'unknown'
      gl = a.user_display_name || a.user_email || 'Unknown'
    } else {
      gk = a.file_name || 'unknown'
      gl = a.file_name || 'Unknown file'
    }
    if (!groups[gk]) groups[gk] = { label: gl, totalSeconds: 0, items: [] }
    groups[gk].totalSeconds += (a.duration_seconds || 0)
    groups[gk].items.push(a)
  }
  return Object.values(groups).sort((a, b) => b.totalSeconds - a.totalSeconds)
})

function toggleSort(col) {
  if (activitySortCol.value === col) activitySortAsc.value = !activitySortAsc.value
  else { activitySortCol.value = col; activitySortAsc.value = true }
}

function sortIcon(col) {
  if (activitySortCol.value !== col) return 'unfold_more'
  return activitySortAsc.value ? 'arrow_upward' : 'arrow_downward'
}

onMounted(async () => {
  loading.value = true
  try {
    const [foldersRes, activityRes] = await Promise.all([
      api.get('/watch-folders'),
      api.get(`/watch-folders/file-activity/card/${props.cardId}`),
    ])
    folders.value = foldersRes.data.data || []
    activity.value = activityRes.data.data || []
  } catch (e) {
    console.error('[CardWatchFolders] load error:', e)
  } finally {
    loading.value = false
  }
})

async function createAndLink() {
  if (!canCreate.value) return
  creating.value = true
  createError.value = ''
  try {
    const res = await api.post('/watch-folders', {
      name: newFolder.value.name.trim(),
      folder_path: newFolder.value.folder_path.trim(),
      client_id: props.clientId,
      board_id: props.boardId,
      card_id: props.cardId,
    })
    if (res.data.success) {
      const refreshed = await api.get('/watch-folders')
      folders.value = refreshed.data.data || []
      newFolder.value = { name: '', folder_path: '' }
      showCreate.value = false
    } else {
      createError.value = res.data.error || 'Failed to create watch folder'
    }
  } catch (e) {
    createError.value = e.response?.data?.error || e.message || 'Server error. Check backend logs.'
    console.error('[CardWatchFolders] create error:', e)
  } finally {
    creating.value = false
  }
}

async function linkToCard(folderId) {
  try {
    await api.put(`/watch-folders/${folderId}`, { card_id: props.cardId })
    const res = await api.get('/watch-folders')
    folders.value = res.data.data || []
  } catch (e) {
    console.error('[CardWatchFolders] link error:', e)
  }
}

async function unlinkFromCard(folderId) {
  try {
    await api.put(`/watch-folders/${folderId}`, { card_id: null })
    const res = await api.get('/watch-folders')
    folders.value = res.data.data || []
  } catch (e) {
    console.error('[CardWatchFolders] unlink error:', e)
  }
}

function formatDuration(seconds) {
  if (!seconds) return '0s'
  if (seconds >= 3600) return `${(seconds / 3600).toFixed(1)}h`
  if (seconds >= 60) return `${Math.round(seconds / 60)}m`
  return `${seconds}s`
}

function formatDate(dateStr) {
  if (!dateStr) return ''
  const d = new Date(dateStr)
  return d.toLocaleDateString(undefined, { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' })
}
</script>

<template>
  <div class="bg-white dark:bg-surface-800 rounded-xl p-4 shadow-sm border border-surface-200/60 dark:border-surface-700/60 space-y-3">
    <div class="flex items-center justify-between">
      <h3 class="text-sm font-semibold text-surface-700 dark:text-surface-200 flex items-center gap-2">
        <span class="material-symbols-rounded text-lg text-amber-500">folder_eye</span>
        Watch Folders
        <span v-if="linkedFolders.length" class="text-xs px-1.5 py-0.5 rounded-full bg-amber-500/10 text-amber-600 dark:text-amber-400">{{ linkedFolders.length }}</span>
      </h3>
      <div class="flex items-center gap-1">
        <button
          v-if="availableFolders.length > 0"
          @click="expanded = !expanded; showCreate = false"
          class="text-xs px-2 py-1 rounded-full transition-colors flex items-center gap-1"
          :class="expanded
            ? 'bg-primary-100 dark:bg-primary-900/30 text-primary-600 dark:text-primary-400'
            : 'text-surface-400 hover:text-surface-600 dark:hover:text-surface-300 hover:bg-surface-100 dark:hover:bg-surface-700'"
        >
          <span class="material-symbols-rounded text-sm">{{ expanded ? 'expand_less' : 'add_link' }}</span>
          {{ expanded ? 'Close' : 'Link' }}
        </button>
        <button
          @click="showCreate = !showCreate; expanded = false"
          class="text-xs px-2 py-1 rounded-full transition-colors flex items-center gap-1"
          :class="showCreate
            ? 'bg-amber-100 dark:bg-amber-900/30 text-amber-600 dark:text-amber-400'
            : 'text-surface-400 hover:text-surface-600 dark:hover:text-surface-300 hover:bg-surface-100 dark:hover:bg-surface-700'"
        >
          <span class="material-symbols-rounded text-sm">{{ showCreate ? 'close' : 'create_new_folder' }}</span>
          {{ showCreate ? 'Cancel' : 'New' }}
        </button>
      </div>
    </div>

    <div v-if="loading" class="flex items-center justify-center py-4">
      <div class="animate-spin rounded-full h-5 w-5 border-2 border-primary-500 border-t-transparent"></div>
    </div>

    <template v-else>
      <!-- Create new watch folder inline -->
      <div v-if="showCreate" class="space-y-2 p-3 rounded-lg bg-amber-50 dark:bg-amber-500/10 border border-amber-200 dark:border-amber-500/20">
        <p class="text-[11px] font-medium text-amber-700 dark:text-amber-300">Create a new watch folder linked to this card</p>
        <input
          v-model="newFolder.name"
          type="text"
          placeholder="Folder name (e.g. Design Files)"
          class="w-full px-3 py-1.5 text-xs rounded-lg border border-surface-200 dark:border-surface-600 bg-white dark:bg-surface-800 text-surface-700 dark:text-surface-200 focus:outline-none focus:ring-1 focus:ring-amber-400"
        />
        <input
          v-model="newFolder.folder_path"
          type="text"
          placeholder="Full path (e.g. Z:\Clients\BV Boros\Design)"
          class="w-full px-3 py-1.5 text-xs rounded-lg border border-surface-200 dark:border-surface-600 bg-white dark:bg-surface-800 text-surface-700 dark:text-surface-200 focus:outline-none focus:ring-1 focus:ring-amber-400 font-mono"
        />
        <p class="text-[10px] text-surface-400 flex items-start gap-1">
          <span class="material-symbols-rounded text-[11px] mt-px shrink-0">info</span>
          Paste the full path as it appears on office machines. Remote workers use path overrides. You can also browse & create from the Drive app.
        </p>

        <div v-if="createError" class="text-[11px] text-red-500 dark:text-red-400 flex items-center gap-1">
          <span class="material-symbols-rounded text-xs">error</span>
          {{ createError }}
        </div>

        <button
          @click="createAndLink"
          :disabled="!canCreate"
          class="px-3 py-1.5 text-xs font-medium rounded-full transition-colors flex items-center gap-1"
          :class="canCreate
            ? 'bg-amber-500 text-white hover:bg-amber-600'
            : 'bg-surface-200 dark:bg-surface-700 text-surface-400 cursor-not-allowed'"
        >
          <span v-if="creating" class="animate-spin material-symbols-rounded text-sm">progress_activity</span>
          <span v-else class="material-symbols-rounded text-sm">add</span>
          Create & Link to Card
        </button>
      </div>

      <!-- Linked folders -->
      <div v-if="linkedFolders.length > 0" class="space-y-1.5">
        <div
          v-for="f in linkedFolders"
          :key="f.id"
          class="flex items-center gap-2 p-2.5 rounded-lg bg-amber-50 dark:bg-amber-500/10 border border-amber-200 dark:border-amber-500/20"
        >
          <span class="material-symbols-rounded text-base text-amber-500">folder_special</span>
          <div class="flex-1 min-w-0">
            <div class="text-xs font-medium text-surface-700 dark:text-surface-200">{{ f.name }}</div>
            <div class="text-[11px] text-surface-400 truncate font-mono">{{ f.folder_path }}</div>
          </div>
          <button @click="unlinkFromCard(f.id)" class="p-1 rounded text-surface-400 hover:text-red-500 transition-colors" title="Unlink from card">
            <span class="material-symbols-rounded text-sm">link_off</span>
          </button>
        </div>
      </div>

      <div v-else-if="!expanded && !showCreate" class="text-xs text-surface-400 py-1">No watch folders linked to this card.</div>

      <!-- Available folders to link -->
      <div v-if="expanded && availableFolders.length > 0" class="space-y-1.5 border-t border-surface-200 dark:border-surface-700 pt-2">
        <p class="text-[11px] text-surface-400 mb-1">Available board watch folders:</p>
        <div
          v-for="f in availableFolders"
          :key="f.id"
          class="flex items-center gap-2 p-2.5 rounded-lg bg-surface-50 dark:bg-surface-700/50 hover:bg-surface-100 dark:hover:bg-surface-700 transition-colors cursor-pointer"
          @click="linkToCard(f.id)"
        >
          <span class="material-symbols-rounded text-base text-surface-400">folder</span>
          <div class="flex-1 min-w-0">
            <div class="text-xs font-medium text-surface-700 dark:text-surface-200">{{ f.name }}</div>
            <div class="text-[11px] text-surface-400 truncate font-mono">{{ f.folder_path }}</div>
          </div>
          <span class="material-symbols-rounded text-sm text-primary-500">add_link</span>
        </div>
      </div>

      <!-- File activity table -->
      <div v-if="activity.length > 0" class="border-t border-surface-200 dark:border-surface-700 pt-2">
        <div class="flex items-center justify-between mb-2">
          <button
            class="text-[11px] text-surface-500 font-medium flex items-center gap-1 hover:text-surface-700 dark:hover:text-surface-300 transition-colors"
            @click="activityExpanded = !activityExpanded"
          >
            <span class="material-symbols-rounded text-sm transition-transform" :class="activityExpanded ? 'rotate-90' : ''">chevron_right</span>
            Recent file activity
            <span class="text-[10px] px-1.5 py-0.5 rounded-full bg-surface-100 dark:bg-surface-700 text-surface-400 ml-1">{{ activity.length }}</span>
          </button>
          <div v-if="activityExpanded" class="inline-flex rounded-full bg-surface-100 dark:bg-surface-700 p-0.5">
            <button
              v-for="opt in [{ v: 'user', icon: 'person' }, { v: 'file', icon: 'description' }]"
              :key="opt.v"
              class="px-2 py-0.5 rounded-full text-[10px] font-medium transition-colors flex items-center gap-0.5"
              :class="activityGroupBy === opt.v
                ? 'bg-white dark:bg-surface-600 text-surface-800 dark:text-surface-100 shadow-sm'
                : 'text-surface-400 hover:text-surface-600 dark:hover:text-surface-300'"
              @click="activityGroupBy = opt.v"
            >
              <span class="material-symbols-rounded text-[11px]">{{ opt.icon }}</span>
              {{ opt.v === 'user' ? 'By User' : 'By File' }}
            </button>
          </div>
        </div>

        <div v-if="activityExpanded" class="rounded-lg border border-surface-200 dark:border-surface-700 overflow-hidden">
          <!-- Table header -->
          <div class="grid grid-cols-[1fr_1fr_auto_auto] gap-x-2 px-2.5 py-1.5 bg-surface-50 dark:bg-surface-700/50 border-b border-surface-200 dark:border-surface-700">
            <button class="text-[10px] font-semibold text-surface-500 uppercase tracking-wide text-left flex items-center gap-0.5" @click="toggleSort('file')">
              File <span class="material-symbols-rounded text-[10px]">{{ sortIcon('file') }}</span>
            </button>
            <button class="text-[10px] font-semibold text-surface-500 uppercase tracking-wide text-left flex items-center gap-0.5" @click="toggleSort('user')">
              User <span class="material-symbols-rounded text-[10px]">{{ sortIcon('user') }}</span>
            </button>
            <button class="text-[10px] font-semibold text-surface-500 uppercase tracking-wide text-right flex items-center gap-0.5 justify-end w-12" @click="toggleSort('duration')">
              Time <span class="material-symbols-rounded text-[10px]">{{ sortIcon('duration') }}</span>
            </button>
            <button class="text-[10px] font-semibold text-surface-500 uppercase tracking-wide text-right flex items-center gap-0.5 justify-end w-28" @click="toggleSort('date')">
              Date <span class="material-symbols-rounded text-[10px]">{{ sortIcon('date') }}</span>
            </button>
          </div>

          <!-- Grouped rows -->
          <div class="max-h-72 overflow-y-auto divide-y divide-surface-100 dark:divide-surface-700/50">
            <div v-for="(group, gi) in groupedActivity" :key="gi">
              <!-- Group header -->
              <div class="flex items-center gap-2 px-2.5 py-1.5 bg-surface-50/50 dark:bg-surface-750/30 sticky top-0">
                <span class="material-symbols-rounded text-xs" :class="activityGroupBy === 'user' ? 'text-primary-500' : 'text-amber-500'">
                  {{ activityGroupBy === 'user' ? 'person' : 'description' }}
                </span>
                <span class="text-[11px] font-semibold text-surface-700 dark:text-surface-200 truncate flex-1">{{ group.label }}</span>
                <span class="text-[10px] font-medium text-amber-600 dark:text-amber-400 shrink-0">{{ formatDuration(group.totalSeconds) }}</span>
                <span class="text-[10px] text-surface-400 shrink-0">{{ group.items.length }} edit{{ group.items.length !== 1 ? 's' : '' }}</span>
              </div>
              <!-- Group items -->
              <div
                v-for="a in group.items"
                :key="a.id"
                class="grid grid-cols-[1fr_1fr_auto_auto] gap-x-2 px-2.5 py-1.5 hover:bg-surface-50 dark:hover:bg-surface-700/30 transition-colors"
              >
                <span class="text-xs text-surface-700 dark:text-surface-200 truncate">{{ a.file_name }}</span>
                <span class="text-xs text-surface-500 truncate">{{ a.user_display_name || a.user_email || '?' }}</span>
                <span class="text-xs font-medium text-amber-600 dark:text-amber-400 text-right tabular-nums w-12">{{ formatDuration(a.duration_seconds) }}</span>
                <span class="text-[10px] text-surface-400 text-right tabular-nums w-28">{{ formatDate(a.created_at) }}</span>
              </div>
            </div>
          </div>
        </div>
      </div>
    </template>
  </div>
</template>
